<?php
// Export / import daftar laptop (CSV, bisa dibuka & diisi di Excel) untuk menambah banyak laptop sekaligus.
//   GET  ?export=1    -> semua laptop dalam format import (tanpa token / data rahasia)
//   GET  ?template=1  -> file kosong berisi judul kolom
//   POST act=preview  -> unggah CSV, tampilkan pratinjau tiap baris (belum ada yang disimpan)
//   POST act=apply    -> simpan hasil pratinjau. Laptop baru mendapat token acak; token laptop lama tidak pernah diubah.
// Hanya supervisor.
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); exit('Hanya supervisor.'); }
$pdo = db();

const CSV_MAX_BYTES = 1048576;
const CSV_MAX_ROWS = 1000;
// kolom file => keterangan (urutan = urutan di file export)
const CSV_COLS = [
    'id_laptop'        => 'Wajib. Huruf/angka/-/_, unik, mis. G003. Tidak bisa diubah setelah dibuat.',
    'nama'             => 'Wajib. Nama laptop.',
    'kategori'         => 'Nama kategori persis seperti di menu Kategori. Kosong = tanpa kategori.',
    'gate_pos'         => 'Gate / pos, mis. Gate Utara.',
    'mode'             => 'gate atau monitor. Kosong = gate.',
    'url_halaman'      => 'URL halaman gate, maks ' . GATE_URL_MAX . ', dipisah " | ". Kosong = URL default + ID laptop.',
    'tampilan_browser' => 'ikut (menu Pengaturan), kiosk, atau biasa.',
    'cetak_langsung'   => 'ikut (menu Pengaturan), ya, atau tidak.',
    'kamera'           => 'Kosong = tidak ada, auto = Agent DVR di laptop itu, atau URL http(s) (boleh {ip}).',
    'wallpaper'        => 'ikut (kategori / bawaan), jangan ubah, atau nama wallpaper di menu Wallpaper.',
];

$cats = $pdo->query("SELECT id, name FROM categories ORDER BY sort, name")->fetchAll();
$wps = $pdo->query("SELECT id, name FROM wallpapers ORDER BY id")->fetchAll();
$cat_name = array_column($cats, 'name', 'id');
$wp_name = array_column($wps, 'name', 'id');
$lc = function ($s) { return function_exists('mb_strtolower') ? mb_strtolower(trim($s), 'UTF-8') : strtolower(trim($s)); };

// csv_cell / csv_send (db.php): sel yang diawali = + - @ diberi awalan ' (dibuang lagi saat import)

if (isset($_GET['template'])) csv_send('gatemon-template-laptop.csv', [array_keys(CSV_COLS)]);
if (isset($_GET['export'])) {
    $rows = [array_keys(CSV_COLS)];
    foreach ($pdo->query("SELECT * FROM devices ORDER BY device_key") as $d) {
        $cam = (string)$d['camera_url'];
        $rows[] = [
            $d['device_key'], $d['name'], $cat_name[$d['category_id']] ?? '', $d['gate'], $d['mode'],
            implode(' | ', array_filter(explode("\n", (string)$d['gate_url']))),
            $d['browser_view'] === 'kiosk' ? 'kiosk' : ($d['browser_view'] === 'window' ? 'biasa' : 'ikut'),
            $d['silent_print'] === null ? 'ikut' : ($d['silent_print'] ? 'ya' : 'tidak'),
            $cam,
            $d['wallpaper_id'] === null ? 'ikut' : ((int)$d['wallpaper_id'] === 0 ? 'jangan ubah' : ($wp_name[$d['wallpaper_id']] ?? 'ikut')),
        ];
    }
    log_event(null, 'info', current_user()['username'] . ' mengekspor daftar laptop (' . (count($rows) - 1) . ' laptop)');
    csv_send('gatemon-laptop-' . date('Ymd-His') . '.csv', $rows);
}

// ---------- baca & periksa file ----------
// hasil: [baris file => ['no','key','set' => kolom DB yang diisi, 'status' => new|update|skip|error, 'msg']]
function parse_csv($path, $update, $pdo, $cats, $wps, $lc) {
    $raw = file_get_contents($path);
    if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) $raw = substr($raw, 3);
    if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');   // CSV lama Excel (ANSI)
    $first = strtok($raw, "\r\n");
    if ($first === false) return [null, 'File kosong.'];
    if (preg_match('/^sep=(.)$/i', trim($first), $m)) { $raw = substr($raw, strlen($first)); $raw = ltrim($raw, "\r\n"); $first = strtok($raw, "\r\n"); $sep = $m[1]; }
    else { $cnt = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")]; arsort($cnt); $sep = key($cnt); }
    $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
    $head = fgetcsv($fh, 0, $sep);
    $map = [];
    foreach ($head ?: [] as $i => $hname) {
        $hname = str_replace([' ', '/'], '_', $lc($hname));
        if (array_key_exists($hname, CSV_COLS)) $map[$hname] = $i;
    }
    if (!isset($map['id_laptop'], $map['nama'])) return [null, 'Kolom id_laptop dan nama tidak ditemukan di baris pertama. Pakai file dari tombol Template / Export.'];

    $catBy = []; foreach ($cats as $c) $catBy[$lc($c['name'])] = (int)$c['id'];
    $wpBy = []; foreach ($wps as $w) $wpBy[$lc($w['name'])] = (int)$w['id'];
    $exist = [];
    foreach ($pdo->query("SELECT device_key FROM devices") as $r) $exist[strtoupper($r['device_key'])] = true;

    $out = []; $seen = []; $line = 1;
    while (($r = fgetcsv($fh, 0, $sep)) !== false) {
        $line++;
        if (count(array_filter($r, function ($v) { return trim((string)$v) !== ''; })) === 0) continue;   // baris kosong
        if (count($out) >= CSV_MAX_ROWS) return [null, 'Maksimal ' . CSV_MAX_ROWS . ' laptop per file.'];
        $g = function ($c) use ($r, $map) {
            if (!isset($map[$c])) return null;   // kolom tidak ada di file: laptop lama tidak diubah
            $v = trim((string)($r[$map[$c]] ?? ''));
            if ($v !== '' && $v[0] === "'" && strlen($v) > 1 && strpos("=+-@", $v[1]) !== false) $v = substr($v, 1);
            return $v;
        };
        $key = strtoupper($g('id_laptop'));
        $row = ['no' => $line, 'key' => $key, 'name' => $g('nama'), 'set' => [], 'status' => 'new', 'msg' => ''];
        $err = function ($m) use (&$row) { if ($row['status'] !== 'error') { $row['status'] = 'error'; $row['msg'] = $m; } };   // pesan error pertama
        if (!preg_match('/^[A-Z0-9_-]{1,50}$/', $key)) $err('ID laptop kosong / tidak valid (huruf, angka, - _, maks 50).');
        elseif (isset($seen[$key])) $err("ID $key sudah ada di baris {$seen[$key]} file ini.");
        elseif ($row['name'] === '' || mb_strlen($row['name']) > 100) $err('Nama wajib diisi (maks 100 karakter).');
        if ($row['status'] !== 'error') {
            $seen[$key] = $line;
            $s = ['name' => $row['name']];
            if (($v = $g('gate_pos')) !== null) { if (mb_strlen($v) > 100) $err('Gate / pos maks 100 karakter.'); $s['gate'] = $v; }
            if (($v = $g('kategori')) !== null) {
                if ($v === '' || in_array($lc($v), ['-', 'tanpa kategori'], true)) $s['category_id'] = null;
                elseif (isset($catBy[$lc($v)])) $s['category_id'] = $catBy[$lc($v)];
                else $err("Kategori \"$v\" tidak ada. Tambahkan dulu di menu Kategori, atau kosongkan.");
            }
            if (($v = $g('mode')) !== null) {
                $m = $lc($v);
                if ($m === '' || strpos($m, 'gate') === 0) $s['mode'] = 'gate';
                elseif (strpos($m, 'monitor') === 0) $s['mode'] = 'monitor';
                else $err("Mode \"$v\" tidak dikenal (gate / monitor).");
            }
            if (($v = $g('url_halaman')) !== null) {
                $l = array_values(array_unique(array_filter(array_map('trim', preg_split('/\s+\|\s+|\R/', $v)))));
                if (count($l) > GATE_URL_MAX) $err('Maksimal ' . GATE_URL_MAX . ' URL halaman per laptop.');
                foreach ($l as $u) if (!preg_match('#^https?://[^\s"\'<>]+$#i', $u) || strlen($u) > 480) { $err("URL halaman tidak valid: $u"); break; }
                $s['gate_url'] = implode("\n", $l);
            }
            if (($v = $g('tampilan_browser')) !== null) {
                $m = $lc($v);
                if (in_array($m, ['', 'ikut', 'ikut pengaturan'], true)) $s['browser_view'] = null;
                elseif ($m === 'kiosk') $s['browser_view'] = 'kiosk';
                elseif (in_array($m, ['biasa', 'browser biasa', 'window'], true)) $s['browser_view'] = 'window';
                else $err("Tampilan browser \"$v\" tidak dikenal (ikut / kiosk / biasa).");
            }
            if (($v = $g('cetak_langsung')) !== null) {
                $m = $lc($v);
                if (in_array($m, ['', 'ikut', 'ikut pengaturan'], true)) $s['silent_print'] = null;
                elseif (in_array($m, ['ya', 'y', '1', 'langsung'], true)) $s['silent_print'] = 1;
                elseif (in_array($m, ['tidak', 't', '0', 'dialog'], true)) $s['silent_print'] = 0;
                else $err("Cetak langsung \"$v\" tidak dikenal (ikut / ya / tidak).");
            }
            if (($v = $g('kamera')) !== null) {
                $m = $lc($v);
                if (in_array($m, ['', '-', 'tidak', 'tidak ada'], true)) $s['camera_url'] = '';
                elseif ($m === 'auto') $s['camera_url'] = 'auto';
                elseif (preg_match('#^https?://[^\s"\'<>]+$#i', $v) && strlen($v) <= 500) $s['camera_url'] = $v;
                else $err("Kamera tidak valid: $v (kosong / auto / URL http(s)).");
            }
            if (($v = $g('wallpaper')) !== null) {
                $m = $lc($v);
                if (in_array($m, ['', 'ikut', 'ikut kategori', 'ikut kategori / bawaan'], true)) $s['wallpaper_id'] = null;
                elseif (in_array($m, ['jangan ubah', '0'], true)) $s['wallpaper_id'] = 0;
                elseif (isset($wpBy[$m])) $s['wallpaper_id'] = $wpBy[$m];
                else $err("Wallpaper \"$v\" tidak ada di menu Wallpaper.");
            }
            $row['set'] = $s;
        }
        if ($row['status'] !== 'error' && isset($exist[$key])) {
            $row['status'] = $update ? 'update' : 'skip';
            $row['msg'] = $update ? 'Sudah ada: data diperbarui (token & agent tidak berubah).' : 'Sudah ada: dilewati.';
        }
        $out[] = $row;
    }
    fclose($fh);
    if (!$out) return [null, 'Tidak ada baris laptop di file (baris pertama = judul kolom).'];
    return [$out, ''];
}

$err = '';
$preview = null;
$act = $_POST['act'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok($_POST['csrf'] ?? '')) die('csrf');
    if ($act === 'preview') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) $err = 'Pilih file CSV dulu (gagal mengunggah).';
        elseif ($f['size'] > CSV_MAX_BYTES) $err = 'File terlalu besar (maks 1 MB).';
        elseif (!preg_match('/\.(csv|txt)$/i', $f['name'])) $err = 'File harus .csv. Di Excel: File > Save As > CSV UTF-8.';
        else {
            [$preview, $err] = parse_csv($f['tmp_name'], !empty($_POST['update']), $pdo, $cats, $wps, $lc);
            if ($preview) $_SESSION['dev_import'] = ['id' => bin2hex(random_bytes(8)), 'rows' => $preview, 'file' => basename($f['name']), 'update' => !empty($_POST['update'])];
        }
    } elseif ($act === 'apply') {
        $imp = $_SESSION['dev_import'] ?? null;
        unset($_SESSION['dev_import']);
        if (!$imp || !hash_equals($imp['id'], (string)($_POST['import_id'] ?? ''))) $err = 'Pratinjau sudah kedaluwarsa. Unggah file lagi.';
        else {
            $add = $upd = $fail = 0; $notes = [];
            $pdo->beginTransaction();
            foreach ($imp['rows'] as $r) {
                if ($r['status'] !== 'new' && $r['status'] !== 'update') continue;
                $s = $r['set'];
                try {
                    if ($r['status'] === 'new') {
                        $s += ['gate' => '', 'category_id' => null, 'gate_url' => '', 'mode' => 'gate', 'browser_view' => null, 'silent_print' => null, 'camera_url' => '', 'wallpaper_id' => null];
                        $s['device_key'] = $r['key']; $s['token'] = bin2hex(random_bytes(16));
                        $cols = array_keys($s);
                        $pdo->prepare('INSERT INTO devices (' . implode(',', $cols) . ',created_at) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',NOW())')->execute(array_values($s));
                        $add++;
                    } else {
                        $st = $pdo->prepare('UPDATE devices SET ' . implode(', ', array_map(function ($c) { return "$c=?"; }, array_keys($s))) . ' WHERE device_key=?');
                        $st->execute(array_merge(array_values($s), [$r['key']]));
                        $upd++;
                    }
                } catch (PDOException $e) { $fail++; $notes[] = $r['key']; }   // mis. ID baru saja ditambahkan orang lain
            }
            $pdo->commit();
            log_event(null, 'info', current_user()['username'] . " mengimpor laptop dari {$imp['file']}: $add baru, $upd diperbarui" . ($fail ? ", $fail gagal" : ''));
            $_SESSION['flash'] = "Import selesai: $add laptop baru, $upd diperbarui" . ($fail ? ", $fail gagal (" . implode(', ', $notes) . ')' : '')
                . ($add ? '. Buat Kode instalasi untuk memasang agent di laptop baru.' : '.');
            header('Location: devices.php'); exit;
        }
    }
}
$csrf = csrf_token();
$n_dev = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
const IMP_STATUS = ['new' => ['Baru', 'ok'], 'update' => ['Diperbarui', 'info'], 'skip' => ['Dilewati', 'muted'], 'error' => ['Error', 'bad']];

page_head('Import laptop');
topbar('devices', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head">
    <div><h1>Export / import laptop</h1><p class="muted">Tambah banyak laptop sekaligus dari file CSV (Excel).</p></div>
    <a class="btn" href="devices.php">← Kembali ke Devices</a>
  </div>
<?php if ($err): ?><p class="notice error"><?= h($err) ?></p><?php endif; ?>

<?php if ($preview):
    $cnt = array_count_values(array_column($preview, 'status')) + ['new' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
    $go = $cnt['new'] + $cnt['update'];
?>
<section class="card">
  <h2><?= icon('devices') ?> Pratinjau: <?= h($_SESSION['dev_import']['file']) ?></h2>
  <p class="muted">Belum ada yang disimpan. Periksa hasilnya, lalu klik <b>Simpan</b>.</p>
  <div class="imp-sum">
    <span class="imp-chip ok"><b><?= $cnt['new'] ?></b> baru</span>
    <span class="imp-chip info"><b><?= $cnt['update'] ?></b> diperbarui</span>
    <span class="imp-chip muted"><b><?= $cnt['skip'] ?></b> dilewati</span>
    <span class="imp-chip bad"><b><?= $cnt['error'] ?></b> error</span>
  </div>
  <?php if ($cnt['error']): ?><p class="notice warn-notice small">Baris <b>Error</b> tidak disimpan. Perbaiki di Excel lalu unggah lagi, atau simpan dulu baris yang benar.</p><?php endif; ?>
  <div class="table-wrap"><table class="table small imp-table">
    <thead><tr><th>Baris</th><th>Status</th><th>ID</th><th>Nama</th><th>Kategori</th><th>Gate / pos</th><th>Mode</th><th>Keterangan</th></tr></thead>
    <tbody>
    <?php foreach ($preview as $r): [$lbl, $cls] = IMP_STATUS[$r['status']]; $s = $r['set'];
        // kolom tidak ada di file: laptop lama tetap; baris error: tidak ditampilkan
        $cell = function ($k, $txt) use ($r, $s) { return $r['status'] === 'error' ? '' : (array_key_exists($k, $s) ? h($txt) : '<span class="muted">tetap</span>'); }; ?>
    <tr class="imp-<?= $r['status'] ?>">
      <td class="muted"><?= $r['no'] ?></td>
      <td><span class="imp-chip <?= $cls ?>"><?= $lbl ?></span></td>
      <td class="mono"><?= h($r['key']) ?></td>
      <td><?= h((string)$r['name']) ?></td>
      <td><?= $cell('category_id', !empty($s['category_id']) ? $cat_name[$s['category_id']] : '–') ?></td>
      <td><?= $cell('gate', ($s['gate'] ?? '') ?: '–') ?></td>
      <td><?= $cell('mode', $s['mode'] ?? '') ?></td>
      <td class="<?= $r['status'] === 'error' ? 'imp-err' : 'muted' ?>"><?= h($r['msg']) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <form method="post" class="actions imp-actions">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="import_id" value="<?= h($_SESSION['dev_import']['id']) ?>">
    <a class="btn" href="devices_csv.php">Batal</a>
    <button class="btn btn-primary" name="act" value="apply" <?= $go ? '' : 'disabled' ?>><?= icon('save') ?> Simpan <?= $go ?> laptop</button>
  </form>
</section>
<?php else: ?>
<div class="imp-grid">
<section class="card">
  <h2><?= icon('download') ?> 1. Unduh file</h2>
  <p class="muted"><b>Export</b> berisi semua laptop (<?= $n_dev ?>) dalam format yang sama dengan import: cocok untuk cadangan atau menyalin
    pengaturan laptop yang mirip. <b>Template</b> hanya berisi judul kolom. Token & data agent tidak ikut diekspor.</p>
  <div class="imp-btns">
    <a class="btn btn-primary" href="devices_csv.php?export=1"><?= icon('download') ?> Export laptop (CSV)</a>
    <a class="btn" href="devices_csv.php?template=1"><?= icon('download') ?> Template kosong</a>
  </div>
  <h3 class="imp-h3">2. Isi di Excel</h3>
  <p class="muted small">Satu baris = satu laptop. Simpan sebagai <b>CSV UTF-8</b> (File › Save As › CSV UTF-8). Pemisah titik koma, koma, atau tab terbaca otomatis.</p>
  <div class="table-wrap"><table class="table small imp-cols">
    <?php foreach (CSV_COLS as $c => $t): ?><tr><td class="mono"><?= $c ?></td><td class="muted"><?= h($t) ?></td></tr><?php endforeach; ?>
  </table></div>
  <?php if ($cats): ?><p class="muted small">Kategori: <?= implode(', ', array_map(function ($c) { return '<code>' . h($c['name']) . '</code>'; }, $cats)) ?></p><?php endif; ?>
  <?php if ($wps): ?><p class="muted small">Wallpaper: <?= implode(', ', array_map(function ($w) { return '<code>' . h($w['name']) . '</code>'; }, $wps)) ?></p><?php endif; ?>
</section>
<section class="card">
  <h2><?= icon('plus') ?> 3. Import</h2>
  <form method="post" enctype="multipart/form-data" class="st-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>File CSV <input type="file" name="file" accept=".csv,text/csv" required></label>
    <label class="check"><input type="checkbox" name="update" value="1"> Perbarui laptop yang ID-nya sudah ada <span class="muted">(bila tidak dicentang: dilewati)</span></label>
    <button class="btn btn-primary" name="act" value="preview"><?= icon('pulse') ?> Periksa file</button>
  </form>
  <p class="muted small">Isi file diperiksa dulu dan ditampilkan per baris; tidak ada yang disimpan sebelum Anda klik <b>Simpan</b>.
    Laptop baru mendapat token baru; setelah itu buat <b>Kode instalasi</b> di menu Devices untuk memasang agent.
    Laptop yang sudah ada hanya diubah datanya: token &amp; agent di laptop tidak terpengaruh.</p>
</section>
</div>
<?php endif; ?>
</main>
<?php page_foot();
