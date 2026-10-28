<?php
// Laporan scan: QR unik yang berhasil (QR yang di-scan berhasil berkali-kali dihitung 1), scan ganda, scan gagal.
// Rincian per kategori / gate / jam memakai scan berhasil PERTAMA tiap QR, sehingga jumlahnya sama dengan total unik.
// ?csv=1 mengunduh daftar QR unik (filter sama). Data scan disimpan sesuai Pengaturan "Simpan data scan selama".
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
const STATUS_LABEL = ['success' => 'Berhasil', 'belum' => 'Belum waktunya', 'notfound' => 'Tidak ditemukan', 'error' => 'Error'];

// ---------- filter ----------
$today = date('Y-m-d');
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) ? $d : null; };
$from = $valid($_GET['from'] ?? '') ?: $today;
$to = $valid($_GET['to'] ?? '') ?: $from;
if ($to < $from) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 92) $to = date('Y-m-d', strtotime($from) + 92 * 86400);   // maks ±3 bulan
$dev = (int)($_GET['device'] ?? 0);
$cat = trim((string)($_GET['category'] ?? ''));
$gate = trim((string)($_GET['gate'] ?? ''));
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);      // cari ID QR (sebagian), menyaring daftar
$code = mb_substr(trim((string)($_GET['code'] ?? '')), 0, 100);     // riwayat satu QR (semua tanggal)

$w = "scanned_at >= ? AND scanned_at < ?";
$p = [$from . ' 00:00:00', date('Y-m-d', strtotime($to) + 86400) . ' 00:00:00'];
$base = [$w, $p];                                   // tanpa filter kategori/gate: untuk isi pilihan filter
if ($dev) { $w .= " AND device_id = ?"; $p[] = $dev; }
if ($cat !== '') { $w .= " AND category = ?"; $p[] = $cat; }
if ($gate !== '') { $w .= " AND gate = ?"; $p[] = $gate; }
function q($sql, $params) { global $pdo; $st = $pdo->prepare($sql); $st->execute($params); return $st; }

// ---------- QR unik berhasil: satu baris per QR, atribut dari scan berhasil pertama ----------
$S = "\x1f";   // pemisah GROUP_CONCAT (tidak mungkin ada di data)
$uniq = q("SELECT code, COUNT(*) AS n, MIN(scanned_at) AS first_at, MAX(scanned_at) AS last_at,
    SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS category,
    SUBSTRING_INDEX(GROUP_CONCAT(gate ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS gate,
    SUBSTRING_INDEX(GROUP_CONCAT(device_id ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS device_id
    FROM scans WHERE $w AND status = 'success' AND code <> '' GROUP BY code ORDER BY first_at, code", $p)->fetchAll();
$names = array_column($pdo->query("SELECT id, name FROM devices")->fetchAll(), 'name', 'id');

// ---------- unduh CSV (Excel, pemisah ;) ----------
if (!empty($_GET['csv'])) {
    $fn = 'laporan-scan-' . $from . ($to !== $from ? '_sd_' . $to : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No', 'ID QR', 'Kategori', 'Gate', 'Laptop', 'Pertama berhasil', 'Terakhir berhasil', 'Jumlah scan berhasil'], ';');
    foreach ($uniq as $i => $r) {
        fputcsv($out, [$i + 1, $r['code'], $r['category'], $r['gate'], $names[$r['device_id']] ?? '', $r['first_at'], $r['last_at'], $r['n']], ';');
    }
    fclose($out);
    exit;
}

// ---------- ringkasan ----------
$by_status = array_fill_keys(array_keys(STATUS_LABEL), 0);
foreach (q("SELECT status, COUNT(*) AS n FROM scans WHERE $w GROUP BY status", $p) as $r) $by_status[$r['status']] = (int)$r['n'];
$n_uniq = count($uniq);
$n_success = $by_status['success'];
$no_code = (int)q("SELECT COUNT(*) FROM scans WHERE $w AND status = 'success' AND code = ''", $p)->fetchColumn();
$n_double = $n_success - $n_uniq - $no_code;
$n_fail = $by_status['notfound'] + $by_status['error'] + $by_status['belum'];
// QR yang gagal dan tidak pernah berhasil di periode ini (per status terakhirnya)
$fail_only = q("SELECT code, COUNT(*) AS n, MAX(scanned_at) AS last_at,
    SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY scanned_at DESC, id DESC SEPARATOR '$S'), '$S', 1) AS last_status
    FROM scans WHERE $w AND code <> '' GROUP BY code HAVING SUM(status = 'success') = 0 ORDER BY last_at DESC", $p)->fetchAll();

// rincian (dari scan berhasil pertama tiap QR)
$per_cat = $per_gate = $per_hour = $per_day = [];
foreach ($uniq as $r) {
    $k = $r['category'] !== '' ? $r['category'] : 'Tanpa kategori';
    $per_cat[$k] = ($per_cat[$k] ?? 0) + 1;
    $g = ($r['gate'] !== '' ? $r['gate'] : '–') . ' · ' . ($names[$r['device_id']] ?? 'laptop terhapus');
    $per_gate[$g] = ($per_gate[$g] ?? 0) + 1;
    $h = substr($r['first_at'], 11, 2) . ':00';
    $per_hour[$h] = ($per_hour[$h] ?? 0) + 1;
    $d = substr($r['first_at'], 0, 10);
    $per_day[$d] = ($per_day[$d] ?? 0) + 1;
}
arsort($per_cat); arsort($per_gate); ksort($per_hour); ksort($per_day);

// ---------- cari ID QR & riwayat scan ----------
$match = function ($r) use ($search) { return $search === '' || stripos($r['code'], $search) !== false; };
$uniq_show = array_values(array_filter($uniq, $match));
$fail_show = array_values(array_filter($fail_only, $match));
if ($code === '' && $search !== '') {
    // ID persis ada di database (tanggal berapa pun), atau pencarian hanya menemukan satu QR -> langsung tampilkan riwayatnya
    $code = (string)q("SELECT code FROM scans WHERE code = ? LIMIT 1", [$search])->fetchColumn();
    if ($code === '' && count($uniq_show) + count($fail_show) === 1) $code = ($uniq_show ?: $fail_show)[0]['code'];
}
$hist = $code !== '' ? q("SELECT scanned_at, status, category, gate, device_id FROM scans WHERE code = ? ORDER BY scanned_at, id", [$code])->fetchAll() : [];

// pilihan filter (dari data periode ini)
$opt_dev = $pdo->query("SELECT DISTINCT d.id, d.name FROM devices d JOIN scans s ON s.device_id = d.id ORDER BY d.name")->fetchAll();
$opt_cat = q("SELECT DISTINCT category FROM scans WHERE {$base[0]} AND category <> '' ORDER BY category", $base[1])->fetchAll(PDO::FETCH_COLUMN);
$opt_gate = q("SELECT DISTINCT gate FROM scans WHERE {$base[0]} AND gate <> '' ORDER BY gate", $base[1])->fetchAll(PDO::FETCH_COLUMN);

function bars($rows, $total) {
    if (!$rows) return '<p class="muted">Belum ada QR berhasil.</p>';
    $max = max($rows);
    $o = '<table class="table small rep-bars">';
    foreach ($rows as $k => $n) {
        $o .= '<tr><td>' . h($k) . '</td><td class="rep-bar"><i style="width:' . round($n / $max * 100, 1) . '%"></i></td>'
            . '<td class="right mono"><b>' . $n . '</b> <small class="muted">' . round($n / max(1, $total) * 100) . '%</small></td></tr>';
    }
    return $o . '</table>';
}
$qs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'device' => $dev ?: null, 'category' => $cat, 'gate' => $gate, 'q' => $search], function ($v) { return $v !== null && $v !== ''; }));
function code_link($c) { global $qs; return '<a class="rep-code" href="report.php?' . h($qs . '&code=' . rawurlencode($c)) . '#riwayat" title="Lihat riwayat scan"><code>' . h($c) . '</code></a>'; }
function tgl($d) {
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $t = strtotime($d);
    return date('j', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t);
}
$period = $from === $to ? tgl($from) : tgl($from) . ' – ' . tgl($to);
const SHOW_MAX = 500;

page_head('Laporan scan');
topbar('report', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head">
    <div><h1>Laporan scan</h1><p class="muted"><?= h($period) ?>. Satu QR yang berhasil dihitung <b>1</b>, walaupun di-scan berkali-kali.</p></div>
    <a class="btn btn-primary" href="report.php?<?= h($qs) ?>&amp;csv=1"><?= icon('download') ?> Unduh CSV (<?= $n_uniq ?> QR)</a>
  </div>

  <section class="card">
    <form method="get" class="filters rep-filters">
      <label class="rep-search">Cari ID QR <input type="search" name="q" value="<?= h($search) ?>" placeholder="mis. D15Z0H3MC" spellcheck="false" autocomplete="off"></label>
      <label>Dari <input type="date" name="from" value="<?= h($from) ?>" max="<?= $today ?>"></label>
      <label>Sampai <input type="date" name="to" value="<?= h($to) ?>" max="<?= $today ?>"></label>
      <label>Laptop <select name="device"><option value="0">Semua laptop</option><?php foreach ($opt_dev as $o): ?><option value="<?= $o['id'] ?>" <?= $dev === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
      <label>Kategori <select name="category"><option value="">Semua kategori</option><?php foreach ($opt_cat as $o): ?><option <?= $cat === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <label>Gate <select name="gate"><option value="">Semua gate</option><?php foreach ($opt_gate as $o): ?><option <?= $gate === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-primary">Tampilkan</button>
      <a class="btn" href="report.php">Hari ini</a>
    </form>
  </section>

  <?php if ($code !== '' || $search !== ''): ?>
  <section class="card" id="riwayat">
    <?php if (!$hist): ?>
    <h2><?= icon('x') ?> QR "<?= h($search ?: $code) ?>"</h2>
    <p class="muted"><?= ($uniq_show || $fail_show) ? 'Ditemukan ' . (count($uniq_show) + count($fail_show)) . ' QR yang mengandung teks ini. Klik ID QR di daftar bawah untuk melihat riwayatnya.' : 'Tidak ada scan dengan ID QR ini (di semua tanggal yang masih tersimpan).' ?></p>
    <?php else:
        $ok_rows = array_filter($hist, function ($r) { return $r['status'] === 'success'; });
        $first = $ok_rows ? reset($ok_rows) : null;
        $places = array_unique(array_map(function ($r) use ($names) { return ($r['gate'] ?: '–') . ' · ' . ($names[$r['device_id']] ?? 'laptop terhapus'); }, $hist));
    ?>
    <div class="table-tools">
      <h2><?= icon('pulse') ?> Riwayat scan <code><?= h($code) ?></code></h2>
      <a class="btn" href="report.php?<?= h(http_build_query(array_filter(['from' => $from, 'to' => $to, 'device' => $dev ?: null, 'category' => $cat, 'gate' => $gate]))) ?>"><?= icon('x') ?> Tutup</a>
    </div>
    <div class="load rep-hist-sum">
      <div><span>Status</span><b class="<?= $first ? 'wol-ready' : 'wol-warn' ?>"><?= $first ? 'Berhasil (dihitung 1)' : 'Tidak pernah berhasil' ?></b></div>
      <div><span>Pertama berhasil</span><b><?= $first ? h($first['scanned_at']) . ' · ' . h(($first['gate'] ?: '–') . ' · ' . ($names[$first['device_id']] ?? 'laptop terhapus')) : '–' ?></b></div>
      <div><span>Jumlah scan</span><b><?= count($hist) ?> (<?= count($ok_rows) ?> berhasil, <?= count($hist) - count($ok_rows) ?> gagal)</b></div>
      <div><span>Di-scan di</span><b><?= h(implode(', ', $places)) ?></b></div>
    </div>
    <div class="table-wrap"><table class="table small">
      <tr><th>No</th><th>Waktu</th><th>Status</th><th>Kategori</th><th>Gate</th><th>Laptop</th><th>Keterangan</th></tr>
      <?php $seen = false; foreach ($hist as $i => $r):
          $ok = $r['status'] === 'success';
          $note = $ok ? (!$seen ? '<span class="badge ok-badge">Dihitung</span>' : '<span class="badge warn-badge">Ganda</span>') : '<span class="muted">tidak dihitung</span>';
          if ($ok) $seen = true; ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td class="mono"><?= h($r['scanned_at']) ?></td>
        <td><span class="state <?= $ok ? 'ok' : 'offline' ?>"><i></i><?= h(STATUS_LABEL[$r['status']] ?? $r['status']) ?></span></td>
        <td><?= h($r['category'] ?: '–') ?></td>
        <td><?= h($r['gate'] ?: '–') ?></td>
        <td><?= h($names[$r['device_id']] ?? 'laptop terhapus') ?></td>
        <td><?= $note ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <p class="muted small">Riwayat dari semua tanggal yang masih tersimpan, tidak terbatas filter tanggal di atas.</p>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <section class="summary">
    <div class="stat ok"><span class="label">QR unik berhasil</span><span class="value"><?= $n_uniq ?></span><span class="sic"><?= icon('save') ?></span></div>
    <div class="stat"><span class="label">Scan berhasil (total)</span><span class="value"><?= $n_success ?></span><span class="sic"><?= icon('pulse') ?></span></div>
    <div class="stat warn"><span class="label">Scan ganda (tidak dihitung)</span><span class="value"><?= max(0, $n_double) ?></span><span class="sic"><?= icon('copy') ?></span></div>
    <div class="stat offline"><span class="label">Scan gagal</span><span class="value"><?= $n_fail ?></span><span class="sic"><?= icon('x') ?></span></div>
  </section>

  <div class="settings-grid">
    <section class="card">
      <h2><?= icon('pulse') ?> Rincian status scan</h2>
      <div class="load">
        <?php foreach (STATUS_LABEL as $k => $l): ?><div><span><?= h($l) ?></span><b><?= $by_status[$k] ?></b></div><?php endforeach; ?>
        <div><span>QR gagal yang tidak pernah berhasil</span><b><?= count($fail_only) ?></b></div>
        <?php if ($no_code): ?><div><span>Berhasil tanpa ID QR (tidak dihitung unik)</span><b><?= $no_code ?></b></div><?php endif; ?>
      </div>
      <p class="muted small">Scan gagal tidak mengurangi hitungan: QR yang awalnya gagal lalu berhasil tetap dihitung 1 berhasil.</p>
    </section>
    <section class="card">
      <h2><?= icon('tag') ?> QR unik per kategori</h2>
      <?= bars($per_cat, $n_uniq) ?>
    </section>
  </div>

  <div class="settings-grid">
    <section class="card">
      <h2><?= icon('devices') ?> QR unik per gate / laptop</h2>
      <?= bars($per_gate, $n_uniq) ?>
      <p class="muted small">QR dihitung di gate tempat pertama kali berhasil.</p>
    </section>
    <section class="card">
      <h2><?= icon('monitor') ?> QR unik per <?= count($per_day) > 1 ? 'hari' : 'jam' ?></h2>
      <?= bars(count($per_day) > 1 ? $per_day : $per_hour, $n_uniq) ?>
    </section>
  </div>

  <section class="card">
    <h2><?= icon('save') ?> Daftar QR unik berhasil <span class="badge"><?= $search !== '' ? count($uniq_show) . ' dari ' . $n_uniq : $n_uniq ?></span></h2>
    <?php if (!$uniq_show): ?><p class="muted"><?= $search !== '' ? 'Tidak ada QR berhasil yang mengandung "' . h($search) . '" di periode / filter ini.' : 'Belum ada QR yang berhasil di periode / filter ini.' ?></p><?php else: ?>
    <div class="table-wrap"><table class="table small">
      <tr><th>No</th><th>ID QR</th><th>Kategori</th><th>Gate</th><th>Laptop</th><th>Pertama berhasil</th><th class="right">Scan berhasil</th></tr>
      <?php foreach (array_slice($uniq_show, 0, SHOW_MAX) as $i => $r): ?>
      <tr<?= $r['code'] === $code ? ' class="rep-sel"' : '' ?>>
        <td class="muted"><?= $i + 1 ?></td>
        <td><?= code_link($r['code']) ?></td>
        <td><?= h($r['category'] ?: '–') ?></td>
        <td><?= h($r['gate'] ?: '–') ?></td>
        <td><?= h($names[$r['device_id']] ?? 'laptop terhapus') ?></td>
        <td class="mono"><?= h(substr($r['first_at'], $from === $to ? 11 : 0)) ?></td>
        <td class="right"><?= (int)$r['n'] > 1 ? '<span class="badge warn-badge" title="Di-scan berhasil ' . (int)$r['n'] . ' kali, dihitung 1">' . (int)$r['n'] . 'x ganda</span>' : '1' ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if (count($uniq_show) > SHOW_MAX): ?><p class="muted small">Menampilkan <?= SHOW_MAX ?> pertama. Pakai kotak <b>Cari ID QR</b>, atau unduh CSV untuk daftar lengkap.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($fail_show): ?>
  <section class="card">
    <h2><?= icon('x') ?> QR gagal yang tidak pernah berhasil <span class="badge"><?= count($fail_show) ?></span></h2>
    <div class="table-wrap"><table class="table small">
      <tr><th>ID QR</th><th>Status terakhir</th><th class="right">Jumlah scan</th><th>Terakhir di-scan</th></tr>
      <?php foreach (array_slice($fail_show, 0, SHOW_MAX) as $r): ?>
      <tr<?= $r['code'] === $code ? ' class="rep-sel"' : '' ?>><td><?= code_link($r['code']) ?></td><td><?= h(STATUS_LABEL[$r['last_status']] ?? $r['last_status']) ?></td><td class="right"><?= (int)$r['n'] ?></td><td class="mono"><?= h($r['last_at']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </section>
  <?php endif; ?>
</main>
<?php page_foot();
