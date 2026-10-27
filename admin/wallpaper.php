<?php
// Wallpaper laptop (agent >= 1.9): pustaka gambar, wallpaper bawaan + per kategori, gaya & kunci, status per laptop.
// Agent mengetahui wallpaper tujuannya lewat heartbeat (hanya sha), lalu mengunduh file sekali lewat api/wallpaper.php.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
const WP_MAX = 10 * 1024 * 1024;
$err = null;

// batas unggah efektif: 10 MB, atau lebih kecil bila php.ini membatasi
function ini_bytes($v) {
    $v = trim((string)$v); $n = (float)$v;
    switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; }
    return (int)$n ?: PHP_INT_MAX;
}
$limit = min(WP_MAX, ini_bytes(ini_get('upload_max_filesize')), ini_bytes(ini_get('post_max_size')));
function mb_fmt($b) { return $b < 1048576 ? max(1, round($b / 1024)) . ' KB' : round($b / 1048576, 1) . ' MB'; }

// gambar kecil untuk pratinjau (butuh ekstensi gd; tanpa gd pratinjau memakai file asli)
function wp_thumb($src, $ext, $dst) {
    if (!function_exists('imagecreatetruecolor')) return;
    $im = $ext === 'png' ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
    if (!$im) return;
    $w = imagesx($im); $h = imagesy($im); $tw = 480; $th = max(1, (int)round($h * $tw / $w));
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagejpeg($t, $dst, 80);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor()) {
    // file melebihi post_max_size: PHP mengosongkan $_POST (termasuk csrf)
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $err = 'File terlalu besar (maks. ' . mb_fmt($limit) . ').';
    } elseif (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        die('csrf');
    } else {
        $act = $_POST['act'] ?? '';
        $user = current_user()['username'];
        if ($act === 'upload') {
            $f = $_FILES['file'] ?? null;
            $code = $f['error'] ?? UPLOAD_ERR_NO_FILE;
            $info = $code === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) ? @getimagesize($f['tmp_name']) : false;
            $ext = $info ? ([IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'][$info[2]] ?? null) : null;
            $name = trim((string)($_POST['name'] ?? '')) ?: pathinfo((string)($f['name'] ?? ''), PATHINFO_FILENAME);
            $name = mb_substr(preg_replace('/[\x00-\x1f]/', '', $name), 0, 80);
            if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($code === UPLOAD_ERR_OK && $f['size'] > $limit)) $err = 'File terlalu besar (maks. ' . mb_fmt($limit) . ').';
            elseif ($code === UPLOAD_ERR_NO_FILE) $err = 'Pilih file gambar dulu.';
            elseif (!$ext) $err = 'File harus gambar JPG atau PNG.';
            elseif ($info[0] < 640 || $info[1] < 480 || $info[0] > 10000 || $info[1] > 10000) $err = "Ukuran gambar {$info[0]}x{$info[1]} tidak valid (minimal 640x480, maksimal 10000).";
            elseif ($name === '') $err = 'Nama wallpaper wajib diisi.';
            else {
                $sha = hash_file('sha256', $f['tmp_name']);
                $dup = $pdo->prepare("SELECT name FROM wallpapers WHERE sha256=?");
                $dup->execute([$sha]);
                if ($old = $dup->fetchColumn()) $err = "Gambar yang sama sudah ada di pustaka: $old.";
                elseif (!is_dir(WALLPAPER_DIR) && !@mkdir(WALLPAPER_DIR, 0775)) $err = 'Gagal membuat folder wallpaper/ di server.';
                elseif (!move_uploaded_file($f['tmp_name'], WALLPAPER_DIR . "/$sha.$ext")) $err = 'Gagal menyimpan file ke folder wallpaper/.';
                else {
                    wp_thumb(WALLPAPER_DIR . "/$sha.$ext", $ext, WALLPAPER_DIR . "/thumb-$sha.jpg");
                    $pdo->prepare("INSERT INTO wallpapers (name, sha256, ext, bytes, width, height, created_by, created_at) VALUES (?,?,?,?,?,?,?,NOW())")
                        ->execute([$name, $sha, $ext, $f['size'], $info[0], $info[1], $user]);
                    log_event(null, 'info', "$user mengunggah wallpaper $name ({$info[0]}x{$info[1]}, " . mb_fmt($f['size']) . ')');
                    $_SESSION['flash'] = "Wallpaper $name ditambahkan ke pustaka. Pilih di bagian Penerapan agar dipasang di laptop.";
                }
            }
        } elseif ($act === 'apply') {
            $ids = array_map('intval', $pdo->query("SELECT id FROM wallpapers")->fetchAll(PDO::FETCH_COLUMN));
            $def = (int)($_POST['default'] ?? 0);
            if ($def && !in_array($def, $ids, true)) $def = 0;
            $style = isset(WALLPAPER_STYLES[$_POST['style'] ?? '']) ? $_POST['style'] : 'fill';
            $lock = empty($_POST['lock']) ? 0 : 1;
            $st = $pdo->prepare("REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, NOW())");
            foreach (['wallpaper_default' => $def, 'wallpaper_style' => $style, 'wallpaper_lock' => $lock] as $k => $v) $st->execute([$k, (string)$v]);
            // per kategori: "" = ikut bawaan (NULL), 0 = jangan ubah, id = wallpaper tertentu
            $uc = $pdo->prepare("UPDATE categories SET wallpaper_id=? WHERE id=?");
            foreach ((array)($_POST['cat'] ?? []) as $cid => $v) {
                $v = (string)$v === '' ? null : (int)$v;
                if ($v && !in_array($v, $ids, true)) $v = null;
                $uc->execute([$v, (int)$cid]);
            }
            log_event(null, 'info', "$user mengubah penerapan wallpaper (bawaan #$def, gaya $style, kunci " . ($lock ? 'ON' : 'OFF') . ')');
            $_SESSION['flash'] = 'Penerapan wallpaper disimpan. Laptop online (agent 1.9+) memasangnya pada heartbeat berikutnya.';
        } elseif ($act === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $cfg = wallpaper_cfg();
            $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM categories WHERE wallpaper_id=?) + (SELECT COUNT(*) FROM devices WHERE wallpaper_id=?)");
            $st->execute([$id, $id]);
            $w = $pdo->prepare("SELECT * FROM wallpapers WHERE id=?");
            $w->execute([$id]);
            $w = $w->fetch();
            if (!$w) $err = 'Wallpaper tidak ditemukan.';
            elseif ($cfg['default'] === $id || $st->fetchColumn()) $err = "Wallpaper {$w['name']} masih dipakai (kategori atau laptop). Ganti dulu, lalu hapus.";
            else {
                $pdo->prepare("DELETE FROM wallpapers WHERE id=?")->execute([$id]);
                @unlink(wallpaper_path($w));
                @unlink(WALLPAPER_DIR . "/thumb-{$w['sha256']}.jpg");
                log_event(null, 'info', "$user menghapus wallpaper {$w['name']}");
                $_SESSION['flash'] = "Wallpaper {$w['name']} dihapus.";
            }
        }
    }
    if (!$err) { header('Location: wallpaper.php'); exit; }
}
$msg = '';
if (!$err && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }

$cfg = wallpaper_cfg();
$wps = $pdo->query("SELECT * FROM wallpapers ORDER BY id DESC")->fetchAll();
$byId = array_column($wps, null, 'id');
$cats = $pdo->query("SELECT c.id, c.name, c.color, c.wallpaper_id, (SELECT COUNT(*) FROM devices d WHERE d.category_id=c.id) AS n FROM categories c ORDER BY c.sort, c.name")->fetchAll();
$catById = array_column($cats, null, 'id');
$devs = $pdo->query("SELECT d.id, d.name, d.device_key, d.category_id, d.wallpaper_id, d.agent_version, d.wallpaper_sha, d.wallpaper_err, d.last_seen FROM devices d
    LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();

// pemakaian tiap wallpaper (label di pustaka)
$usage = [];
if ($cfg['default']) $usage[$cfg['default']][] = 'Bawaan semua laptop';
foreach ($cats as $c) if ($c['wallpaper_id']) $usage[(int)$c['wallpaper_id']][] = $c['name'];
foreach ($devs as $dv) if (!empty($dv['wallpaper_id'])) $usage[(int)$dv['wallpaper_id']][] = $dv['name'];

// status per laptop, dihitung dengan aturan yang sama seperti wallpaper_for()
$offline_after = setting('offline_after');
$sum = ['target' => 0, 'ok' => 0];
foreach ($devs as &$d) {
    $tid = wallpaper_target_id($d);   // laptop -> kategori -> bawaan
    $d['target'] = $tid > 0 ? ($byId[$tid] ?? null) : null;
    $online = $d['last_seen'] && time() - strtotime($d['last_seen']) <= $offline_after;
    if (!$d['target']) {
        [$d['st'], $d['label']] = (string)$d['wallpaper_sha'] !== '' ? ['pending', 'Mengembalikan wallpaper asli'] : ['none', 'Tidak diatur'];
        continue;
    }
    $sum['target']++;
    if ($d['wallpaper_sha'] === null || version_compare($d['agent_version'] ?: '0', '1.9', '<')) [$d['st'], $d['label']] = ['warn', 'Perlu agent 1.9+ (pasang ulang agent)'];
    elseif ($d['wallpaper_sha'] === $d['target']['sha256']) { [$d['st'], $d['label']] = ['ok', 'Terpasang']; $sum['ok']++; }
    elseif ((string)$d['wallpaper_err'] !== '') [$d['st'], $d['label']] = ['offline', 'Gagal: ' . $d['wallpaper_err']];
    else [$d['st'], $d['label']] = $online ? ['pending', 'Sedang dipasang'] : ['none', 'Menunggu laptop online'];
}
unset($d);

function wp_options($wps, $sel, $first) {
    $o = '';
    foreach ($first as $v => $label) $o .= '<option value="' . h($v) . '"' . ((string)$sel === (string)$v ? ' selected' : '') . '>' . h($label) . '</option>';
    foreach ($wps as $w) $o .= '<option value="' . $w['id'] . '"' . ((string)$sel === (string)$w['id'] ? ' selected' : '') . '>' . h($w['name']) . " ({$w['width']}x{$w['height']})</option>";
    return $o;
}
$csrf = csrf_token();
$ro = is_supervisor() ? '' : 'disabled';

page_head('Wallpaper');
topbar('wallpaper', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Wallpaper laptop</h1><p class="muted">Atur wallpaper desktop semua laptop dari server: satu wallpaper bawaan, bisa dibedakan per kategori lokasi. Butuh agent 1.9+.</p></div></div>
<?= $err ? '<p class="notice error">' . h($err) . '</p>' : flash($msg) ?>
<?php if (!is_supervisor()): ?><p class="notice">Hanya supervisor yang bisa mengubah wallpaper.</p><?php endif; ?>

<div class="settings-grid">
<section class="card">
  <h2><?= icon('monitor') ?> Penerapan</h2>
  <form method="post" class="set-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="apply">
    <div class="set-row">
      <div class="set-txt"><label for="wp-def">Wallpaper bawaan</label><p class="muted">Dipasang di semua laptop, kecuali kategori yang diatur sendiri di bawah. "Jangan ubah" = agent mengembalikan wallpaper asli laptop.</p></div>
      <select id="wp-def" name="default" <?= $ro ?>><?= wp_options($wps, $cfg['default'], ['0' => '— Jangan ubah —']) ?></select>
    </div>
    <div class="set-row wp-cats">
      <div class="set-txt"><label>Per kategori</label>
        <table class="table small">
          <?php foreach ($cats as $c): ?>
          <tr><td><span class="cat-badge" style="--cat:<?= h($c['color']) ?>"><i></i><?= h($c['name']) ?></span> <small class="muted"><?= (int)$c['n'] ?> laptop</small></td>
            <td><select name="cat[<?= $c['id'] ?>]" aria-label="Wallpaper kategori <?= h($c['name']) ?>" <?= $ro ?>><?= wp_options($wps, $c['wallpaper_id'] ?? '', ['' => 'Ikut bawaan', '0' => '— Jangan ubah —']) ?></select></td></tr>
          <?php endforeach; ?>
        </table>
        <p class="muted small">Laptop tanpa kategori memakai wallpaper bawaan.</p>
      </div>
    </div>
    <div class="set-row">
      <div class="set-txt"><label for="wp-style">Posisi gambar</label><p class="muted">Cara gambar ditampilkan bila ukurannya berbeda dengan layar laptop.</p></div>
      <select id="wp-style" name="style" <?= $ro ?>><?php foreach (WALLPAPER_STYLES as $k => $l): ?><option value="<?= $k ?>" <?= $cfg['style'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
    </div>
    <div class="set-row">
      <div class="set-txt"><label for="wp-lock">Kunci wallpaper</label><p class="muted">Bila petugas mengganti wallpaper, agent memasang kembali wallpaper dari server.</p></div>
      <label class="switch"><input type="checkbox" id="wp-lock" name="lock" value="1" <?= $cfg['lock'] ? 'checked' : '' ?> <?= $ro ?>><span></span></label>
    </div>
    <?php if (is_supervisor()): ?>
    <div class="actions set-actions"><button class="btn btn-primary"><?= icon('save') ?> Simpan penerapan</button></div>
    <?php endif; ?>
  </form>
</section>

<?php if (is_supervisor()): ?>
<section class="card">
  <h2><?= icon('plus') ?> Unggah wallpaper</h2>
  <form method="post" enctype="multipart/form-data" class="export-form wp-upload">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="upload">
    <input type="hidden" name="MAX_FILE_SIZE" value="<?= $limit ?>">
    <label class="fld">Nama <input name="name" maxlength="80" placeholder="Wallpaper event (opsional)"></label>
    <input type="file" name="file" accept=".jpg,.jpeg,.png,image/jpeg,image/png" required>
    <button class="btn btn-primary"><?= icon('download') ?> Unggah</button>
  </form>
  <p class="muted small">JPG atau PNG, maks. <?= mb_fmt($limit) ?>, minimal 640x480. Saran: 1920x1080 JPG ±1 MB.
    Tiap laptop mengunduh sekali saat wallpaper berubah (<?= count($devs) ?> laptop × ukuran file).</p>
</section>
<?php endif; ?>
</div>

<section class="card">
  <h2><?= icon('tag') ?> Pustaka <span class="badge"><?= count($wps) ?></span></h2>
  <?php if (!$wps): ?><p class="muted">Belum ada wallpaper. Unggah gambar dulu.</p><?php endif; ?>
  <div class="wp-grid">
    <?php foreach ($wps as $w): ?>
    <figure class="wp-item">
      <a href="wallpaper_img.php?id=<?= $w['id'] ?>" target="_blank" rel="noopener"><img src="wallpaper_img.php?id=<?= $w['id'] ?>&amp;thumb=1" alt="<?= h($w['name']) ?>" loading="lazy"></a>
      <figcaption class="wp-meta">
        <b><?= h($w['name']) ?></b>
        <span class="muted"><?= $w['width'] ?>x<?= $w['height'] ?> · <?= strtoupper($w['ext']) ?> · <?= mb_fmt($w['bytes']) ?></span>
        <span class="wp-tags"><?php foreach ($usage[$w['id']] ?? [] as $u): ?><span class="badge sup"><?= h($u) ?></span><?php endforeach; ?><?= empty($usage[$w['id']]) ? '<span class="badge">Tidak dipakai</span>' : '' ?></span>
      </figcaption>
      <?php if (is_supervisor() && empty($usage[$w['id']])): ?>
      <form method="post" onsubmit="return confirm('Hapus wallpaper <?= h($w['name']) ?>?')">
        <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $w['id'] ?>">
        <button class="btn btn-danger"><?= icon('trash') ?> Hapus</button>
      </form>
      <?php endif; ?>
    </figure>
    <?php endforeach; ?>
  </div>
</section>

<section class="card">
  <h2><?= icon('devices') ?> Status laptop <?php if ($sum['target']): ?><span class="badge <?= $sum['ok'] === $sum['target'] ? 'sup' : '' ?>"><?= $sum['ok'] ?>/<?= $sum['target'] ?> terpasang</span><?php endif; ?></h2>
  <div class="table-wrap"><table class="table small">
    <tr><th>Laptop</th><th>Kategori</th><th>Wallpaper</th><th>Status</th></tr>
    <?php foreach ($devs as $d): $c = $catById[$d['category_id']] ?? null; ?>
    <tr>
      <td><b><?= h($d['name']) ?></b> <code class="muted"><?= h($d['device_key']) ?></code></td>
      <td><span class="cat-badge" style="--cat:<?= h($c['color'] ?? '#64748b') ?>"><i></i><?= h($c['name'] ?? 'Tanpa kategori') ?></span></td>
      <td><?= $d['target'] ? h($d['target']['name']) : '<span class="muted">–</span>' ?></td>
      <td><span class="state <?= $d['st'] ?>"><i></i><?= h($d['label']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
</main>
<?php page_foot();
