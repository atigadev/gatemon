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
$qs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'device' => $dev ?: null, 'category' => $cat, 'gate' => $gate], function ($v) { return $v !== null && $v !== ''; }));
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
      <label>Dari <input type="date" name="from" value="<?= h($from) ?>" max="<?= $today ?>"></label>
      <label>Sampai <input type="date" name="to" value="<?= h($to) ?>" max="<?= $today ?>"></label>
      <label>Laptop <select name="device"><option value="0">Semua laptop</option><?php foreach ($opt_dev as $o): ?><option value="<?= $o['id'] ?>" <?= $dev === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
      <label>Kategori <select name="category"><option value="">Semua kategori</option><?php foreach ($opt_cat as $o): ?><option <?= $cat === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <label>Gate <select name="gate"><option value="">Semua gate</option><?php foreach ($opt_gate as $o): ?><option <?= $gate === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-primary">Tampilkan</button>
      <a class="btn" href="report.php">Hari ini</a>
    </form>
  </section>

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
    <h2><?= icon('save') ?> Daftar QR unik berhasil <span class="badge"><?= $n_uniq ?></span></h2>
    <?php if (!$uniq): ?><p class="muted">Belum ada QR yang berhasil di periode / filter ini.</p><?php else: ?>
    <div class="table-wrap"><table class="table small">
      <tr><th>No</th><th>ID QR</th><th>Kategori</th><th>Gate</th><th>Laptop</th><th>Pertama berhasil</th><th class="right">Scan berhasil</th></tr>
      <?php foreach (array_slice($uniq, 0, SHOW_MAX) as $i => $r): ?>
      <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><code><?= h($r['code']) ?></code></td>
        <td><?= h($r['category'] ?: '–') ?></td>
        <td><?= h($r['gate'] ?: '–') ?></td>
        <td><?= h($names[$r['device_id']] ?? 'laptop terhapus') ?></td>
        <td class="mono"><?= h(substr($r['first_at'], $from === $to ? 11 : 0)) ?></td>
        <td class="right"><?= (int)$r['n'] > 1 ? '<span class="badge warn-badge" title="Di-scan berhasil ' . (int)$r['n'] . ' kali, dihitung 1">' . (int)$r['n'] . 'x ganda</span>' : '1' ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php if ($n_uniq > SHOW_MAX): ?><p class="muted small">Menampilkan <?= SHOW_MAX ?> pertama. Daftar lengkap ada di CSV.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <?php if ($fail_only): ?>
  <section class="card">
    <h2><?= icon('x') ?> QR gagal yang tidak pernah berhasil <span class="badge"><?= count($fail_only) ?></span></h2>
    <div class="table-wrap"><table class="table small">
      <tr><th>ID QR</th><th>Status terakhir</th><th class="right">Jumlah scan</th><th>Terakhir di-scan</th></tr>
      <?php foreach (array_slice($fail_only, 0, SHOW_MAX) as $r): ?>
      <tr><td><code><?= h($r['code']) ?></code></td><td><?= h(STATUS_LABEL[$r['last_status']] ?? $r['last_status']) ?></td><td class="right"><?= (int)$r['n'] ?></td><td class="mono"><?= h($r['last_at']) ?></td></tr>
      <?php endforeach; ?>
    </table></div>
  </section>
  <?php endif; ?>
</main>
<?php page_foot();
