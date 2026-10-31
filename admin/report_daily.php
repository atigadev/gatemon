<?php
// Laporan per hari selama event: satu baris per tanggal (QR unik, per group, scan ganda/gagal, jam tersibuk,
// laptop yang dipakai, gangguan, darurat & chat), matriks per gate dan per jam. ?csv=1 mengunduh rekap harian.
// Periode event bisa disimpan supervisor (tabel settings: event_from / event_to) agar langsung terbuka saat halaman dibuka.
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/report_groups.php';
$pdo = db();
$today = date('Y-m-d');
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) ? $d : null; };
$saved = [];
foreach ($pdo->query("SELECT k, v FROM settings WHERE k IN ('event_from', 'event_to', 'event_name')") as $r) $saved[$r['k']] = $r['v'];

// simpan / hapus periode event (supervisor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) die('csrf');
    $who = current_user()['username'];
    if (($_POST['act'] ?? '') === 'save_event' && ($f = $valid($_POST['from'] ?? '')) && ($t = $valid($_POST['to'] ?? ''))) {
        if ($t < $f) [$f, $t] = [$t, $f];
        $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
        $set = $pdo->prepare("REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, NOW())");
        $set->execute(['event_from', $f]); $set->execute(['event_to', $t]); $set->execute(['event_name', $name]);
        log_event(null, 'info', "$who menyimpan periode event" . ($name !== '' ? " \"$name\"" : '') . ": $f s/d $t");
        $_SESSION['flash'] = 'Periode event disimpan.';
    } elseif (($_POST['act'] ?? '') === 'clear_event') {
        $pdo->exec("DELETE FROM settings WHERE k IN ('event_from', 'event_to', 'event_name')");
        log_event(null, 'info', "$who menghapus periode event tersimpan");
        $_SESSION['flash'] = 'Periode event dihapus. Halaman memakai 7 hari terakhir yang ada data scan.';
    }
    header('Location: report_daily.php');
    exit;
}
$flash_msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// ---------- periode: dari URL, atau periode event tersimpan, atau 7 hari terakhir yang ada data scan ----------
$from = $valid($_GET['from'] ?? '');
$to = $valid($_GET['to'] ?? '');
$event_name = '';
if (!$from && !$to && !empty($saved['event_from']) && !empty($saved['event_to'])) {
    [$from, $to, $event_name] = [$saved['event_from'], $saved['event_to'], (string)($saved['event_name'] ?? '')];
}
if (!$from && !$to) {
    $last = (string)$pdo->query("SELECT DATE(MAX(scanned_at)) FROM scans")->fetchColumn() ?: $today;
    $st = $pdo->prepare("SELECT DATE(MIN(scanned_at)) FROM scans WHERE scanned_at >= ?");
    $st->execute([date('Y-m-d', strtotime($last) - 6 * 86400)]);
    [$from, $to] = [(string)$st->fetchColumn() ?: $last, $last];
}
$from = $from ?: $to;
$to = $to ?: $from;
if ($to < $from) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 92) $to = date('Y-m-d', strtotime($from) + 92 * 86400);   // maks ±3 bulan
$is_saved = !empty($saved['event_from']) && $from === $saved['event_from'] && $to === ($saved['event_to'] ?? '');
if ($is_saved) $event_name = (string)($saved['event_name'] ?? '');
$rng = [$from . ' 00:00:00', date('Y-m-d', strtotime($to) + 86400) . ' 00:00:00'];
function q($sql, $params) { global $pdo; $st = $pdo->prepare($sql); $st->execute($params); return $st; }
$names = array_column($pdo->query("SELECT id, name FROM devices")->fetchAll(), 'name', 'id');

// ---------- data per hari ----------
$days = [];
for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
    $days[date('Y-m-d', $t)] = ['uniq' => 0, 'new' => 0, 'ok' => 0, 'fail' => 0, 'notfound' => 0, 'belum' => 0, 'error' => 0, 'grp' => [],
        'hours' => [], 'first' => null, 'last' => null, 'devs' => [], 'gates' => [], 'online' => 0, 'issues' => 0, 'emg' => 0, 'chat' => 0, 'cmds' => 0];
}
// QR unik per hari: satu baris per (tanggal, QR); atribut dari scan berhasil pertama hari itu.
// QR yang datang di beberapa hari dihitung di tiap hari; "baru" = pertama kali berhasil di periode ini.
$S = "\x1f";
$rows = q("SELECT DATE(scanned_at) AS d, code, COUNT(*) AS n, MIN(scanned_at) AS first_at,
    SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS category,
    SUBSTRING_INDEX(GROUP_CONCAT(gate ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS gate,
    SUBSTRING_INDEX(GROUP_CONCAT(device_id ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS device_id
    FROM scans WHERE scanned_at >= ? AND scanned_at < ? AND status = 'success' AND code <> ''
    GROUP BY DATE(scanned_at), code ORDER BY first_at", $rng);
$seen = [];
$grp_all = [];
$gate_mx = [];   // gate -> tanggal -> QR unik
$hour_mx = [];   // tanggal -> jam -> QR unik
foreach ($rows as $r) {
    $d = $r['d'];
    if (!isset($days[$d])) continue;
    $x = &$days[$d];
    $x['uniq']++;
    $x['ok'] += (int)$r['n'];
    if (!isset($seen[$r['code']])) { $seen[$r['code']] = true; $x['new']++; }
    $g = grp_of($r['category']);
    $x['grp'][$g] = ($x['grp'][$g] ?? 0) + 1;
    $grp_all[$g] = true;
    $h = (int)substr($r['first_at'], 11, 2);
    $x['hours'][$h] = ($x['hours'][$h] ?? 0) + 1;
    $hour_mx[$d][$h] = ($hour_mx[$d][$h] ?? 0) + 1;
    $x['first'] = $x['first'] ?? $r['first_at'];
    $x['last'] = $r['first_at'];
    $x['devs'][$r['device_id']] = true;
    $gk = ($r['gate'] !== '' ? $r['gate'] : '–') . ' · ' . ($names[$r['device_id']] ?? 'laptop terhapus');
    $gate_mx[$gk][$d] = ($gate_mx[$gk][$d] ?? 0) + 1;
    unset($x);
}
// scan gagal per hari (dan jam scan terakhir apa pun statusnya)
foreach (q("SELECT DATE(scanned_at) AS d, status, COUNT(*) AS n FROM scans WHERE scanned_at >= ? AND scanned_at < ? GROUP BY DATE(scanned_at), status", $rng) as $r) {
    if (!isset($days[$r['d']]) || $r['status'] === 'success') continue;
    $days[$r['d']][$r['status']] = (int)$r['n'];
    $days[$r['d']]['fail'] += (int)$r['n'];
}
// operasional: laptop online kembali (setelah mati / terputus), gangguan (log peringatan/error laptop), darurat, chat, perintah
foreach (q("SELECT DATE(created_at) AS d, SUM(level = 'info' AND message LIKE '% online') AS online, SUM(level <> 'info') AS issues
    FROM logs WHERE created_at >= ? AND created_at < ? AND device_id IS NOT NULL GROUP BY DATE(created_at)", $rng) as $r) {
    if (!isset($days[$r['d']])) continue;
    $days[$r['d']]['online'] = (int)$r['online'];
    $days[$r['d']]['issues'] = (int)$r['issues'];
}
foreach (q("SELECT DATE(created_at) AS d, SUM(kind = 'emergency') AS emg, SUM(kind = 'chat' AND sender = 'agent') AS chat
    FROM support_msgs WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $rng) as $r) {
    if (!isset($days[$r['d']])) continue;
    $days[$r['d']]['emg'] = (int)$r['emg'];
    $days[$r['d']]['chat'] = (int)$r['chat'];
}
foreach (q("SELECT DATE(created_at) AS d, COUNT(*) AS n FROM commands WHERE created_at >= ? AND created_at < ? GROUP BY DATE(created_at)", $rng) as $r) {
    if (isset($days[$r['d']])) $days[$r['d']]['cmds'] = (int)$r['n'];
}

// ---------- total periode ----------
$groups = grp_sort(array_merge(GROUP_FIRST, array_keys($grp_all)));
if (empty($grp_all[NO_GROUP])) $groups = array_values(array_diff($groups, [NO_GROUP]));
$T = ['uniq_sum' => 0, 'ok' => 0, 'fail' => 0, 'online' => 0, 'issues' => 0, 'emg' => 0, 'chat' => 0, 'cmds' => 0, 'grp' => [], 'active' => 0];
$busy_day = null;
foreach ($days as $d => $x) {
    foreach (['ok', 'fail', 'online', 'issues', 'emg', 'chat', 'cmds'] as $k) $T[$k] += $x[$k];
    $T['uniq_sum'] += $x['uniq'];
    foreach ($x['grp'] as $g => $n) $T['grp'][$g] = ($T['grp'][$g] ?? 0) + $n;
    if ($x['uniq'] || $x['fail']) $T['active']++;
    if ($x['uniq'] && (!$busy_day || $x['uniq'] > $days[$busy_day]['uniq'])) $busy_day = $d;
}
$T['uniq'] = count($seen);                       // QR unik selama event (dihitung sekali)
$T['double'] = max(0, $T['ok'] - $T['uniq_sum']);
$peak = function ($hours) { if (!$hours) return null; $h = array_search(max($hours), $hours, true); return [$h, $hours[$h]]; };
$hm = function ($dt) { return $dt ? substr($dt, 11, 5) : '–'; };

// ---------- unduh CSV (Excel, pemisah ;) ----------
if (!empty($_GET['csv'])) {
    $fn = 'laporan-harian-' . $from . ($to !== $from ? '_sd_' . $to : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Tanggal', 'Hari', 'QR unik', 'QR baru'], $groups,
        ['Scan berhasil', 'Scan ganda', 'Scan gagal', 'Tidak ditemukan', 'Belum waktunya', 'Error', 'Scan pertama', 'Scan terakhir', 'Jam tersibuk', 'QR di jam tersibuk',
         'Laptop dipakai scan', 'Laptop online kembali', 'Gangguan laptop', 'Darurat', 'Chat dari laptop', 'Perintah dikirim']), ';');
    foreach ($days as $d => $x) {
        $pk = $peak($x['hours']);
        fputcsv($out, array_merge([$d, explode(',', tgl($d, true))[0], $x['uniq'], $x['new']], array_map(function ($g) use ($x) { return $x['grp'][$g] ?? 0; }, $groups),
            [$x['ok'], max(0, $x['ok'] - $x['uniq']), $x['fail'], $x['notfound'], $x['belum'], $x['error'], $hm($x['first']), $hm($x['last']),
             $pk ? sprintf('%02d:00', $pk[0]) : '', $pk ? $pk[1] : '', count($x['devs']), $x['online'], $x['issues'], $x['emg'], $x['chat'], $x['cmds']]), ';');
    }
    fputcsv($out, array_merge(['TOTAL', '', $T['uniq_sum'], $T['uniq']], array_map(function ($g) use ($T) { return $T['grp'][$g] ?? 0; }, $groups),
        [$T['ok'], $T['double'], $T['fail'], '', '', '', '', '', '', '', '', $T['online'], $T['issues'], $T['emg'], $T['chat'], $T['cmds']]), ';');
    fclose($out);
    exit;
}

$period = $from === $to ? tgl($from) : tgl($from) . ' – ' . tgl($to);
$qs = http_build_query(['from' => $from, 'to' => $to]);
page_head('Laporan harian');
topbar('report', 'Monitoring Laptop');
?>
<main class="page daily">
  <div class="page-head">
    <div><h1>Laporan per hari<?= $event_name !== '' ? ' · ' . h($event_name) : '' ?></h1>
      <p class="muted"><?= h($period) ?> · <?= count($days) ?> hari<?= $is_saved ? ' · <span class="badge ok-badge">periode event tersimpan</span>' : '' ?></p></div>
    <div class="rep-head-actions no-print">
      <a class="btn" href="report.php?<?= h($qs) ?>"><?= icon('chart') ?> Laporan scan</a>
      <button type="button" class="btn" data-print><?= icon('download') ?> Cetak / PDF</button>
      <a class="btn btn-primary" href="report_daily.php?<?= h($qs) ?>&amp;csv=1"><?= icon('download') ?> Unduh CSV</a>
    </div>
  </div>

  <section class="card no-print">
    <form method="get" class="filters rep-filters">
      <label>Dari <input type="date" name="from" value="<?= h($from) ?>"></label>
      <label>Sampai <input type="date" name="to" value="<?= h($to) ?>"></label>
      <button class="btn btn-primary">Tampilkan</button>
      <?php if (!empty($saved['event_from'])): ?><a class="btn" href="report_daily.php">Periode event</a><?php endif; ?>
    </form>
    <?php if (is_supervisor()): ?>
    <form method="post" class="filters rep-filters daily-save">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="from" value="<?= h($from) ?>"><input type="hidden" name="to" value="<?= h($to) ?>">
      <label>Nama event <input name="name" value="<?= h($event_name ?: ($saved['event_name'] ?? '')) ?>" maxlength="100" placeholder="mis. Pameran 2026" autocomplete="off"></label>
      <button class="btn" name="act" value="save_event"><?= icon('save') ?> Simpan sebagai periode event</button>
      <?php if (!empty($saved['event_from'])): ?><button class="btn" name="act" value="clear_event" data-confirm="Hapus periode event tersimpan?">Hapus periode tersimpan</button><?php endif; ?>
    </form>
    <p class="muted small">Periode event yang disimpan langsung terbuka saat halaman ini dibuka (semua akun).</p>
    <?php endif; ?>
  </section>
  <?= flash($flash_msg) ?>

  <section class="summary">
    <div class="stat ok"><span class="label">QR unik selama event</span><span class="value"><?= $T['uniq'] ?></span><span class="sic"><?= icon('save') ?></span></div>
    <div class="stat"><span class="label">Jumlah kehadiran (QR unik per hari)</span><span class="value"><?= $T['uniq_sum'] ?></span><span class="sic"><?= icon('users') ?></span></div>
    <div class="stat"><span class="label">Hari tersibuk</span><span class="value daily-small"><?= $busy_day ? h(tgl($busy_day)) : '–' ?></span>
      <small class="muted"><?= $busy_day ? $days[$busy_day]['uniq'] . ' QR unik' : 'belum ada scan' ?></small><span class="sic"><?= icon('chart') ?></span></div>
    <div class="stat offline"><span class="label">Scan gagal</span><span class="value"><?= $T['fail'] ?></span><span class="sic"><?= icon('x') ?></span></div>
  </section>

  <section class="card">
    <h2><?= icon('monitor') ?> Rekap per hari</h2>
    <div class="table-wrap"><table class="table small daily-table">
      <thead><tr><th>Tanggal</th><th class="right">QR unik</th><th class="right" title="Pertama kali datang di periode ini">Baru</th>
        <?php foreach ($groups as $g): ?><th class="right"><?= h($g) ?></th><?php endforeach; ?>
        <th class="right">Scan berhasil</th><th class="right">Ganda</th><th class="right">Gagal</th><th>Jam scan</th><th>Jam tersibuk</th>
        <th class="right" title="Laptop yang mencatat scan berhasil">Laptop</th><th class="right" title="Laptop online kembali setelah mati / terputus">Online kembali</th>
        <th class="right" title="Peringatan / error dari laptop (browser tertutup, halaman gate tidak bisa diakses, perintah gagal, dll)">Gangguan</th>
        <th class="right">Darurat</th><th class="right">Chat</th></tr></thead>
      <tbody>
      <?php foreach ($days as $d => $x): $pk = $peak($x['hours']); $empty = !$x['uniq'] && !$x['fail']; ?>
      <tr class="<?= $empty ? 'daily-empty' : '' ?><?= $d === $busy_day ? ' daily-busy' : '' ?>">
        <td><a href="report.php?from=<?= $d ?>&amp;to=<?= $d ?>" title="Laporan scan hari ini"><?= h(tgl($d, true)) ?></a></td>
        <td class="right mono"><b><?= $x['uniq'] ?></b></td><td class="right mono"><?= $x['new'] ?></td>
        <?php foreach ($groups as $g): ?><td class="right mono"><?= $x['grp'][$g] ?? 0 ?></td><?php endforeach; ?>
        <td class="right mono"><?= $x['ok'] ?></td><td class="right mono"><?= max(0, $x['ok'] - $x['uniq']) ?></td>
        <td class="right mono<?= $x['fail'] ? ' bad' : '' ?>" title="Tidak ditemukan <?= $x['notfound'] ?> · belum waktunya <?= $x['belum'] ?> · error <?= $x['error'] ?>"><?= $x['fail'] ?></td>
        <td class="mono"><?= $x['first'] ? $hm($x['first']) . '–' . $hm($x['last']) : '–' ?></td>
        <td class="mono"><?= $pk ? sprintf('%02d:00', $pk[0]) . ' <small class="muted">(' . $pk[1] . ')</small>' : '–' ?></td>
        <td class="right mono"><?= count($x['devs']) ?></td><td class="right mono"><?= $x['online'] ?></td>
        <td class="right mono<?= $x['issues'] ? ' warn' : '' ?>"><?= $x['issues'] ?></td>
        <td class="right mono<?= $x['emg'] ? ' bad' : '' ?>"><?= $x['emg'] ?></td><td class="right mono"><?= $x['chat'] ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="rep-total"><td><b>Total</b> <small class="muted">(<?= $T['active'] ?> hari ada scan)</small></td>
        <td class="right mono"><b><?= $T['uniq_sum'] ?></b></td><td class="right mono"><b><?= $T['uniq'] ?></b></td>
        <?php foreach ($groups as $g): ?><td class="right mono"><?= $T['grp'][$g] ?? 0 ?></td><?php endforeach; ?>
        <td class="right mono"><?= $T['ok'] ?></td><td class="right mono"><?= $T['double'] ?></td><td class="right mono"><?= $T['fail'] ?></td><td></td><td></td><td></td>
        <td class="right mono"><?= $T['online'] ?></td><td class="right mono"><?= $T['issues'] ?></td><td class="right mono"><?= $T['emg'] ?></td><td class="right mono"><?= $T['chat'] ?></td></tr>
      </tbody>
    </table></div>
    <p class="muted small">QR unik per hari: QR yang berhasil di hari itu dihitung 1 (yang datang lagi di hari lain dihitung lagi di hari itu).
      <b>Baru</b> = pertama kali datang di periode ini; total kolom Baru = QR unik selama event. Klik tanggal untuk laporan scan hari itu.</p>
  </section>

  <section class="card">
    <h2><?= icon('chart') ?> QR unik per hari</h2>
    <?php $max = max(1, max(array_column($days, 'uniq'))); ?>
    <table class="table small rep-bars">
      <?php foreach ($days as $d => $x): ?>
      <tr><td><?= h(tgl($d, true)) ?></td><td class="rep-bar"><i style="width:<?= round($x['uniq'] / $max * 100, 1) ?>%"></i></td>
        <td class="right mono"><b><?= $x['uniq'] ?></b> <small class="muted"><?= $x['new'] ?> baru</small></td></tr>
      <?php endforeach; ?>
    </table>
  </section>

  <?php if ($gate_mx): ksort($gate_mx); ?>
  <section class="card">
    <h2><?= icon('devices') ?> QR unik per gate / laptop per hari</h2>
    <div class="table-wrap"><table class="table small daily-table">
      <thead><tr><th>Gate · laptop</th><?php foreach ($days as $d => $x): ?><th class="right"><?= h(substr(tgl($d), 0, -5)) ?></th><?php endforeach; ?><th class="right">Total</th></tr></thead>
      <tbody>
      <?php foreach ($gate_mx as $gk => $row): ?>
      <tr><td><?= h($gk) ?></td><?php foreach ($days as $d => $x): ?><td class="right mono"><?= $row[$d] ?? '<span class="muted">0</span>' ?></td><?php endforeach; ?>
        <td class="right mono"><b><?= array_sum($row) ?></b></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>

  <?php if ($hour_mx):
      $hrs = []; foreach ($hour_mx as $hh) $hrs = array_merge($hrs, array_keys($hh));
      $hrs = range(min($hrs), max($hrs));
      $hmax = 1; foreach ($hour_mx as $hh) $hmax = max($hmax, max($hh)); ?>
  <section class="card">
    <h2><?= icon('pulse') ?> QR unik per jam per hari</h2>
    <div class="table-wrap"><table class="table small daily-heat">
      <thead><tr><th>Tanggal</th><?php foreach ($hrs as $h): ?><th class="right"><?= sprintf('%02d', $h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach ($days as $d => $x): if (empty($hour_mx[$d])) continue; ?>
      <tr><td><?= h(substr(tgl($d), 0, -5)) ?></td>
        <?php foreach ($hrs as $h): $n = $hour_mx[$d][$h] ?? 0; ?><td class="right mono" style="--a:<?= round($n / $hmax, 2) ?>"><?= $n ?: '' ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="muted small">Angka = QR unik yang pertama kali berhasil di jam itu (hari tersebut). Warna makin pekat = makin ramai.</p>
  </section>
  <?php endif; ?>
</main>
<?php page_foot([]);
