<?php
// Log kejadian per laptop (agent): offline / online, browser & halaman gate, darurat, perintah, instalasi, dll.
// Filter laptop, tingkat, jenis, periode, cari teks; rekap per laptop; cetak / PDF dan unduh CSV.
//   ?d=ID laptop (0 = semua, -1 = sistem / admin tanpa laptop) &lv=info|warn|error|issue &k=jenis &from &to &q &p=halaman &csv=1
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
log_offline_devices();
const LOG_PAGE = 100;

$today = date('Y-m-d');
$valid = function ($s) { return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$s, $m) && checkdate($m[2], $m[3], $m[1]) ? $s : ''; };
$from = $valid($_GET['from'] ?? '') ?: date('Y-m-d', strtotime('-6 days'));
$to = $valid($_GET['to'] ?? '') ?: $today;
if ($to < $from) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 92) $from = date('Y-m-d', strtotime($to) - 92 * 86400);   // maks ±3 bulan
$dev = (int)($_GET['d'] ?? 0);
$lv = in_array($_GET['lv'] ?? '', ['info', 'warn', 'error', 'issue'], true) ? $_GET['lv'] : '';
$kind = isset(LOG_KINDS[$_GET['k'] ?? '']) ? $_GET['k'] : '';
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int)($_GET['p'] ?? 1));

$devices = $pdo->query("SELECT d.id, d.name, d.device_key, d.mode, d.last_seen, d.browser_running, d.gate_url_ok, d.hostname, d.ip, d.agent_version,
    c.name AS cat, c.color FROM devices d LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();
$by_id = array_column($devices, null, 'id');
if ($dev > 0 && !isset($by_id[$dev])) $dev = 0;
$off = setting('offline_after');

// ---------- query kejadian sesuai filter ----------
$rng = [$from . ' 00:00:00', date('Y-m-d', strtotime($to) + 86400) . ' 00:00:00'];
$where = ['l.created_at >= ?', 'l.created_at < ?'];
$params = $rng;
if ($dev > 0) { $where[] = 'l.device_id = ?'; $params[] = $dev; }
elseif ($dev === -1) $where[] = 'l.device_id IS NULL';
if ($lv === 'issue') $where[] = "l.level <> 'info'";
elseif ($lv !== '') { $where[] = 'l.level = ?'; $params[] = $lv; }
if ($kind !== '') $where[] = log_kind_sql($kind, $params);
if ($q !== '') { $where[] = 'l.message LIKE ?'; $params[] = '%' . addcslashes($q, '%_\\') . '%'; }
$w = implode(' AND ', $where);
$run = function ($sql, $p) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($p); return $st; };

$lv_name = ['info' => 'Info', 'warn' => 'Peringatan', 'error' => 'Error'];
if (isset($_GET['csv'])) {
    $rows = [['waktu', 'laptop', 'id_laptop', 'tingkat', 'jenis', 'kejadian']];
    foreach ($run("SELECT l.* FROM logs l WHERE $w ORDER BY l.id LIMIT 50000", $params) as $r) {
        $dv = $by_id[$r['device_id']] ?? null;
        $k = log_kind_of($r['message']);
        $rows[] = [$r['created_at'], $dv ? $dv['name'] : ($r['device_id'] ? '(laptop dihapus)' : 'Sistem / admin'), $dv['device_key'] ?? '',
            $lv_name[$r['level']] ?? $r['level'], $k ? LOG_KINDS[$k][0] : 'Lain-lain', $r['message']];
    }
    csv_send('gatemon-log-' . ($dev > 0 ? $by_id[$dev]['device_key'] . '-' : '') . $from . '_' . $to . '.csv', $rows);
}

$total = (int)$run("SELECT COUNT(*) FROM logs l WHERE $w", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / LOG_PAGE));
$page = min($page, $pages);
$logs = $run("SELECT l.* FROM logs l WHERE $w ORDER BY l.id DESC LIMIT " . LOG_PAGE . ' OFFSET ' . (($page - 1) * LOG_PAGE), $params)->fetchAll();

// ---------- rekap per laptop di periode ini (tanpa filter tingkat / jenis / cari) ----------
$sum = [];
foreach ($run("SELECT device_id, COUNT(*) n, SUM(level='error') e, SUM(level='warn') w, SUM(message LIKE '% offline (%') off,
        SUM(message LIKE '%tombol DARURAT%') emg, SUM(level<>'info' AND (message LIKE '%browser gate%' OR message LIKE '%halaman gate%')) gate, MAX(created_at) last
        FROM logs WHERE created_at >= ? AND created_at < ? AND device_id IS NOT NULL GROUP BY device_id", $rng) as $r) $sum[$r['device_id']] = $r;
$blank = ['n' => 0, 'e' => 0, 'w' => 0, 'off' => 0, 'emg' => 0, 'gate' => 0, 'last' => null];
$rekap = [];
foreach ($devices as $d) $rekap[] = $d + ['s' => ($sum[$d['id']] ?? []) + $blank, 'state' => device_state($d, $off)];
usort($rekap, function ($a, $b) { return [$b['s']['e'] + $b['s']['w'], $b['s']['n']] <=> [$a['s']['e'] + $a['s']['w'], $a['s']['n']]; });
$S = $dev > 0 ? (($sum[$dev] ?? []) + $blank) : null;

$qs = function ($over = []) use ($dev, $lv, $kind, $from, $to, $q) {
    return h(http_build_query(array_filter(array_merge(['d' => $dev ?: null, 'lv' => $lv, 'k' => $kind, 'from' => $from, 'to' => $to, 'q' => $q], $over),
        function ($v) { return $v !== null && $v !== '' && $v !== 0; })));
};
$tgl = function ($s) { return date('d/m/Y', strtotime($s)); };
$period = $from === $to ? $tgl($from) : $tgl($from) . ' – ' . $tgl($to);
$state_txt = ['ok' => 'Sehat', 'warn' => 'Ada masalah', 'offline' => 'Offline'];
$title = $dev > 0 ? 'Log ' . $by_id[$dev]['name'] : ($dev === -1 ? 'Log sistem / admin' : 'Log kejadian');

page_head($title);
topbar('logs', 'Monitoring Laptop');
?>
<main class="page logs">
  <div class="page-head">
    <div><h1><?= h($title) ?><?php if ($dev > 0): $D = $by_id[$dev]; ?> <code class="muted"><?= h($D['device_key']) ?></code><?php endif; ?></h1>
      <p class="muted"><?= h($period) ?> · <?= $total ?> kejadian<?php if ($dev > 0): ?> · <?= h($D['cat'] ?? 'Tanpa kategori') ?> · sekarang
        <?php $st_now = device_state($D, $off); ?><span class="state <?= $st_now ?>"><i></i><?= $state_txt[$st_now] ?></span><?php endif; ?></p></div>
    <div class="rep-head-actions no-print">
      <?php if ($dev !== 0): ?><a class="btn" href="logs.php?<?= $qs(['d' => null]) ?>">Semua laptop</a><?php endif; ?>
      <button type="button" class="btn" data-print><?= icon('download') ?> Cetak / PDF</button>
      <a class="btn btn-primary" href="logs.php?<?= $qs(['csv' => 1]) ?>"><?= icon('download') ?> Unduh CSV</a>
    </div>
  </div>

  <section class="card no-print">
    <form method="get" class="filters rep-filters">
      <label>Laptop <select name="d">
        <option value="0">Semua laptop</option>
        <?php foreach ($devices as $d): ?><option value="<?= $d['id'] ?>" <?= $dev === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name'] . ' (' . $d['device_key'] . ')') ?></option><?php endforeach; ?>
        <option value="-1" <?= $dev === -1 ? 'selected' : '' ?>>Sistem / admin (tanpa laptop)</option>
      </select></label>
      <label>Tingkat <select name="lv">
        <option value="">Semua</option><option value="issue" <?= $lv === 'issue' ? 'selected' : '' ?>>Peringatan + error</option>
        <?php foreach ($lv_name as $k => $n): ?><option value="<?= $k ?>" <?= $lv === $k ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?>
      </select></label>
      <label>Jenis <select name="k">
        <option value="">Semua</option>
        <?php foreach (LOG_KINDS as $k => [$n]): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?>
      </select></label>
      <label>Dari <input type="date" name="from" value="<?= h($from) ?>" max="<?= $today ?>"></label>
      <label>Sampai <input type="date" name="to" value="<?= h($to) ?>" max="<?= $today ?>"></label>
      <label>Cari <input type="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="mis. browser, G003"></label>
      <button class="btn btn-primary">Tampilkan</button>
      <?php if ($lv || $kind || $q): ?><a class="btn" href="logs.php?<?= $qs(['lv' => null, 'k' => null, 'q' => null]) ?>">Hapus filter</a><?php endif; ?>
    </form>
  </section>

  <?php if ($S): ?>
  <section class="summary">
    <div class="stat"><span class="label">Kejadian</span><span class="value"><?= (int)$S['n'] ?></span><span class="sic"><?= icon('log') ?></span></div>
    <div class="stat offline"><span class="label">Offline</span><span class="value"><?= (int)$S['off'] ?></span><span class="sic"><?= icon('power') ?></span></div>
    <div class="stat warn"><span class="label">Gangguan gate (browser / halaman)</span><span class="value"><?= (int)$S['gate'] ?></span><span class="sic"><?= icon('monitor') ?></span></div>
    <div class="stat offline"><span class="label">Error · darurat</span><span class="value"><?= (int)$S['e'] ?> · <?= (int)$S['emg'] ?></span><span class="sic"><?= icon('x') ?></span></div>
  </section>
  <?php elseif ($dev === 0): ?>
  <section class="card">
    <h2><?= icon('devices') ?> Rekap per laptop <span class="badge"><?= h($period) ?></span></h2>
    <div class="table-wrap"><table class="table small log-rekap">
      <thead><tr><th>Laptop</th><th>Kategori</th><th>Sekarang</th><th class="right">Kejadian</th><th class="right">Offline</th>
        <th class="right" title="Browser gate tertutup / halaman gate tidak bisa diakses">Gangguan gate</th><th class="right">Peringatan</th><th class="right">Error</th>
        <th class="right">Darurat</th><th>Kejadian terakhir</th></tr></thead>
      <tbody>
      <?php foreach ($rekap as $d): $s = $d['s']; ?>
      <tr class="<?= $s['n'] ? '' : 'daily-empty' ?>">
        <td><a href="logs.php?<?= $qs(['d' => $d['id']]) ?>"><b><?= h($d['name']) ?></b></a> <code class="muted"><?= h($d['device_key']) ?></code></td>
        <td class="muted"><?= h($d['cat'] ?? '–') ?></td>
        <td><span class="state <?= $d['state'] ?>"><i></i><?= $state_txt[$d['state']] ?></span></td>
        <td class="right mono"><?= (int)$s['n'] ?></td>
        <td class="right mono<?= $s['off'] ? ' bad' : '' ?>"><?= (int)$s['off'] ?></td>
        <td class="right mono<?= $s['gate'] ? ' bad' : '' ?>"><?= (int)$s['gate'] ?></td>
        <td class="right mono"><?= (int)$s['w'] ?></td>
        <td class="right mono<?= $s['e'] ? ' bad' : '' ?>"><?= (int)$s['e'] ?></td>
        <td class="right mono<?= $s['emg'] ? ' bad' : '' ?>"><?= (int)$s['emg'] ?></td>
        <td class="muted"><?= $s['last'] ? h(date('d/m H:i', strtotime($s['last']))) : '–' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2><?= icon('log') ?> Kejadian <span class="badge"><?= $total ?></span><?= $pages > 1 ? ' <small class="muted">halaman ' . $page . ' / ' . $pages . '</small>' : '' ?></h2>
    <?php if (!$logs): ?><p class="muted">Tidak ada kejadian untuk filter ini.</p><?php else: ?>
    <div class="table-wrap"><table class="table small log-table">
      <thead><tr><th>Waktu</th><?php if ($dev === 0): ?><th>Laptop</th><?php endif; ?><th>Tingkat</th><th>Jenis</th><th>Kejadian</th></tr></thead>
      <tbody>
      <?php foreach ($logs as $r): $dv = $by_id[$r['device_id']] ?? null; $k = log_kind_of($r['message']);
        $msg = $dev > 0 && strpos($r['message'], $dv['name'] . ': ') === 0 ? mb_substr($r['message'], mb_strlen($dv['name']) + 2) : $r['message']; ?>
      <tr class="lv-<?= h($r['level']) ?>">
        <td class="mono nowrap"><?= h(date('d/m H:i:s', strtotime($r['created_at']))) ?></td>
        <?php if ($dev === 0): ?><td class="nowrap"><?= $dv ? '<a href="logs.php?' . $qs(['d' => $dv['id'], 'p' => null]) . '">' . h($dv['name']) . '</a>' : '<span class="muted">' . ($r['device_id'] ? 'laptop dihapus' : 'sistem / admin') . '</span>' ?></td><?php endif; ?>
        <td><span class="lv-badge <?= h($r['level']) ?>"><?= $lv_name[$r['level']] ?? h($r['level']) ?></span></td>
        <td class="muted nowrap"><?= $k ? '<a href="logs.php?' . $qs(['k' => $k, 'p' => null]) . '">' . h(LOG_KINDS[$k][0]) . '</a>' : 'Lain-lain' ?></td>
        <td><?= h($msg) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($pages > 1): ?><nav class="pager no-print">
      <?php if ($page > 1): ?><a class="btn" href="logs.php?<?= $qs(['p' => $page - 1]) ?>">‹ Lebih baru</a><?php endif; ?>
      <span class="muted small"><?= ($page - 1) * LOG_PAGE + 1 ?>–<?= min($total, $page * LOG_PAGE) ?> dari <?= $total ?></span>
      <?php if ($page < $pages): ?><a class="btn" href="logs.php?<?= $qs(['p' => $page + 1]) ?>">Lebih lama ›</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php endif; ?>
    <p class="muted small">Offline dicatat sekali saat laptop berhenti melapor lebih dari <?= (int)$off ?> dtk; saat online kembali tercatat berapa lama laptop offline.
      Cetak hanya mencetak halaman yang tampil, unduh CSV berisi semua kejadian sesuai filter.</p>
  </section>
</main>
<?php page_foot();
