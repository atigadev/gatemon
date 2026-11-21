<?php
// Traffic laptop ke host URL halaman gate (agent >= 1.25): download / upload, kecepatan sekarang & puncak, porsi dari
// seluruh traffic laptop. Data per menit dari agent (api/heartbeat.php, tabel traffic), disimpan TRAFFIC_KEEP_DAYS hari.
//   ?r=1h|6h|24h|7d  &d=ID laptop (0 = semua laptop)
require __DIR__ . '/auth.php';
require_login();
$pdo = db();

const TRF_RANGES = ['1h' => ['1 jam', 3600, 60], '6h' => ['6 jam', 21600, 300], '24h' => ['24 jam', 86400, 900], '7d' => ['7 hari', 604800, 7200]];
$r = isset(TRF_RANGES[$_GET['r'] ?? '']) ? $_GET['r'] : '6h';
[$rname, $span, $bk] = TRF_RANGES[$r];
$dev = (int)($_GET['d'] ?? 0);

$devices = $pdo->query("SELECT d.id, d.name, d.device_key, d.mode, d.gate_url, d.agent_version, d.traffic_err, d.last_seen, d.category_id,
    c.name AS cat, c.color FROM devices d LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();
$by_id = array_column($devices, null, 'id');
if ($dev && !isset($by_id[$dev])) $dev = 0;

$now = time();
$b0 = intdiv($now - $span, $bk) * $bk + $bk;                       // awal bucket pertama (bucket terakhir = yang sedang berjalan)
$nb = intdiv($now - $b0, $bk) + 1;
$since = date('Y-m-d H:i:s', $b0);
$devw = $dev ? ' AND device_id=' . $dev : '';                       // id angka yang sudah dicek

// ---------- ringkasan per laptop & host ----------
$sum = [];
$st = $pdo->prepare("SELECT device_id, host, SUM(bytes_in) bi, SUM(bytes_out) bo, MAX(bytes_in + bytes_out) peak, MAX(conns) mc, MAX(minute) last
    FROM traffic WHERE minute >= ?$devw GROUP BY device_id, host");
$st->execute([$since]);
foreach ($st as $x) $sum[$x['device_id']][$x['host']] = $x;
// kecepatan sekarang: rata-rata 3 menit lengkap terakhir
$cur = [];
$st = $pdo->prepare("SELECT device_id, host, SUM(bytes_in) bi, SUM(bytes_out) bo FROM traffic WHERE minute >= ? AND minute < ?$devw GROUP BY device_id, host");
$st->execute([date('Y-m-d H:i:00', $now - 180), date('Y-m-d H:i:00', $now)]);
foreach ($st as $x) $cur[$x['device_id']][$x['host']] = [$x['bi'] * 8 / 180, $x['bo'] * 8 / 180];

// ---------- grafik: semua host URL gate (laptop terpilih / semua laptop), rata-rata bit/detik per bucket ----------
$series = array_fill(0, $nb, null);
$st = $pdo->prepare("SELECT minute, SUM(bytes_in) bi, SUM(bytes_out) bo FROM traffic WHERE host <> '*' AND minute >= ? AND minute < ?$devw GROUP BY minute");
$st->execute([$since, date('Y-m-d H:i:00', $now)]);   // tanpa menit yang sedang berjalan
foreach ($st as $x) {
    $i = intdiv(strtotime($x['minute']) - $b0, $bk);
    if ($i < 0 || $i >= $nb) continue;
    $series[$i] = $series[$i] ?? [0, 0, 0];
    $series[$i][0] += $x['bi'];
    $series[$i][1] += $x['bo'];
    $series[$i][2]++;                                                  // jumlah menit yang ada datanya
}
$pts = [];
$peak = 0;
foreach ($series as $i => $v) {
    $t = $b0 + $i * $bk;
    $len = $v ? $v[2] * 60 : $bk;                                     // rata-rata atas menit yang terukur (bucket berjalan / ada jeda offline)
    $pts[] = [$t * 1000, $v ? round($v[0] * 8 / $len) : null, $v ? round($v[1] * 8 / $len) : null, $v ? (int)$v[0] : null, $v ? (int)$v[1] : null];
    if ($v) $peak = max($peak, ($v[0] + $v[1]) * 8 / $len);
}

// ---------- baris tabel: laptop x host URL gate ----------
$rows = [];
$T = ['bi' => 0, 'bo' => 0];
foreach ($devices as $d) {
    if ($dev && (int)$d['id'] !== $dev) continue;
    $s = $sum[$d['id']] ?? [];
    $hosts = traffic_hosts_of($d);
    foreach (array_keys($s) as $h) if ($h !== '*' && !in_array($h, $hosts, true)) $hosts[] = (string)$h;   // host lama yang pernah diukur
    $old = !$d['agent_version'] || version_compare($d['agent_version'], TRAFFIC_MIN, '<');
    $all = $s['*'] ?? null;
    foreach ($hosts as $h) {
        $x = $s[$h] ?? null;
        $bi = $x ? (int)$x['bi'] : 0; $bo = $x ? (int)$x['bo'] : 0;
        $T['bi'] += $bi; $T['bo'] += $bo;
        $lap = $all ? (int)$all['bi'] + (int)$all['bo'] : 0;
        $rows[] = ['d' => $d, 'host' => $h, 'x' => $x, 'bi' => $bi, 'bo' => $bo, 'share' => $lap > 0 ? min(100, ($bi + $bo) * 100 / $lap) : null,
            'cur' => $cur[$d['id']][$h] ?? null, 'old' => $old];
    }
}
$hasData = array_filter($pts, function ($p) { return $p[1] !== null; });

$qs = function ($over = []) use ($dev, $r) {
    return h(http_build_query(array_filter(array_merge(['d' => $dev ?: null, 'r' => $r], $over), function ($v) { return $v !== null; })));
};
$title = $dev ? 'Traffic ' . $by_id[$dev]['name'] : 'Traffic URL gate';
$ago = function ($s) use ($now) { return $s ? dur_txt($now - strtotime($s)) . ' lalu' : '–'; };

page_head($title);
topbar('traffic', 'Monitoring Laptop');
?>
<main class="page traffic-page">
  <div class="page-head">
    <div><h1><?= h($title) ?><?php if ($dev): ?> <code class="muted"><?= h($by_id[$dev]['device_key']) ?></code><?php endif; ?></h1>
      <p class="muted">Download &amp; upload laptop ke host halaman gate · <?= h($rname) ?> terakhir · data per menit dari agent <?= h(TRAFFIC_MIN) ?>+</p></div>
    <?php if ($dev): ?><div class="head-btns"><a class="btn" href="traffic.php?<?= $qs(['d' => null]) ?>">Semua laptop</a></div><?php endif; ?>
  </div>

  <section class="card">
    <form method="get" class="filters rep-filters">
      <label>Laptop <select name="d">
        <option value="0">Semua laptop</option>
        <?php foreach ($devices as $d): ?><option value="<?= $d['id'] ?>" <?= $dev === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name'] . ' (' . $d['device_key'] . ')') ?></option><?php endforeach; ?>
      </select></label>
      <label>Periode <select name="r">
        <?php foreach (TRF_RANGES as $k => [$n]): ?><option value="<?= $k ?>" <?= $r === $k ? 'selected' : '' ?>><?= $n ?> terakhir</option><?php endforeach; ?>
      </select></label>
      <button class="btn btn-primary">Tampilkan</button>
    </form>
  </section>

  <section class="summary">
    <div class="stat"><span class="label">Download (masuk)</span><span class="value"><?= bytes_txt($T['bi']) ?></span><span class="sic"><?= icon('download') ?></span></div>
    <div class="stat"><span class="label">Upload (keluar)</span><span class="value"><?= bytes_txt($T['bo']) ?></span><span class="sic"><?= icon('traffic') ?></span></div>
    <div class="stat"><span class="label">Rata-rata <?= h($rname) ?></span><span class="value"><?= bps_txt(($T['bi'] + $T['bo']) * 8 / $span) ?></span><span class="sic"><?= icon('pulse') ?></span></div>
    <div class="stat"><span class="label">Puncak (per <?= $bk >= 3600 ? ($bk / 3600) . ' jam' : ($bk / 60) . ' menit' ?>)</span><span class="value"><?= bps_txt($peak) ?></span><span class="sic"><?= icon('chart') ?></span></div>
  </section>

  <section class="card">
    <h2><?= icon('chart') ?> Kecepatan ke host URL gate <small class="muted"><?= $dev ? h($by_id[$dev]['name']) : 'semua laptop' ?>, rata-rata per <?= $bk >= 3600 ? ($bk / 3600) . ' jam' : ($bk / 60) . ' menit' ?></small></h2>
    <?php if ($hasData): ?>
    <div class="trf-legend"><span><i class="trf-key in"></i>Download</span><span><i class="trf-key out"></i>Upload</span></div>
    <div class="trf-chart" id="trf-chart" data-pts="<?= h(json_encode($pts)) ?>" data-bk="<?= $bk ?>" role="img"
      aria-label="Grafik kecepatan download dan upload ke host URL gate, <?= h($rname) ?> terakhir"></div>
    <details class="trf-table"><summary class="muted small">Lihat sebagai tabel</summary>
      <div class="table-wrap"><table class="table small">
        <tr><th>Waktu</th><th class="right">Download</th><th class="right">Upload</th><th class="right">Data masuk</th><th class="right">Data keluar</th></tr>
        <?php foreach (array_reverse($pts) as $p): if ($p[1] === null) continue; ?>
        <tr><td><?= date($bk >= 3600 ? 'd/m H:i' : 'H:i', intdiv($p[0], 1000)) ?></td><td class="right"><?= bps_txt($p[1]) ?></td><td class="right"><?= bps_txt($p[2]) ?></td>
          <td class="right"><?= bytes_txt($p[3]) ?></td><td class="right"><?= bytes_txt($p[4]) ?></td></tr>
        <?php endforeach; ?>
      </table></div>
    </details>
    <?php else: ?>
    <p class="muted">Belum ada data traffic pada periode ini. Agent mulai mengukur setelah diupdate ke versi <?= h(TRAFFIC_MIN) ?> (menu Agent).</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2><?= icon('devices') ?> Per laptop &amp; host <span class="badge"><?= h($rname) ?></span></h2>
    <div class="table-wrap"><table class="table small trf-rows">
      <tr><th>Laptop</th><th>Host URL gate</th><th class="right">Download</th><th class="right">Upload</th><th class="right">Total</th>
        <th class="right" title="Porsi host ini dari seluruh traffic laptop">Porsi laptop</th><th class="right">Sekarang ↓ / ↑</th>
        <th class="right">Puncak / menit</th><th>Data terakhir</th><th>Status</th></tr>
      <?php foreach ($rows as $w): $d = $w['d']; $x = $w['x']; ?>
      <tr>
        <td><a href="traffic.php?<?= $qs(['d' => $d['id']]) ?>"><b><?= h($d['name']) ?></b></a><br><small class="muted"><?= h($d['cat'] ?? 'Tanpa kategori') ?></small></td>
        <td><code><?= h($w['host']) ?></code></td>
        <td class="right"><?= $x ? bytes_txt($w['bi']) : '–' ?></td>
        <td class="right"><?= $x ? bytes_txt($w['bo']) : '–' ?></td>
        <td class="right"><b><?= $x ? bytes_txt($w['bi'] + $w['bo']) : '–' ?></b></td>
        <td class="right"><?= $w['share'] === null ? '–' : number_format($w['share'], $w['share'] < 10 ? 1 : 0, ',', '.') . '%' ?></td>
        <td class="right"><?= $w['cur'] ? bps_txt($w['cur'][0]) . ' / ' . bps_txt($w['cur'][1]) : '–' ?></td>
        <td class="right"><?= $x ? bytes_txt((int)$x['peak']) : '–' ?></td>
        <td class="muted"><?= $x ? h($ago($x['last'])) : '–' ?></td>
        <td><?php if ($w['old']): ?><span class="lv-badge warn" title="Update agent di menu Agent">agent <?= h($d['agent_version'] ?: '?') ?>: perlu <?= h(TRAFFIC_MIN) ?></span>
          <?php elseif ($d['traffic_err']): ?><span class="lv-badge error" title="<?= h($d['traffic_err']) ?>">tidak terukur</span> <small class="muted"><?= h($d['traffic_err']) ?></small>
          <?php elseif (!$x): ?><span class="lv-badge">belum ada data</span>
          <?php else: ?><span class="badge op">terukur</span><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="muted">Belum ada laptop.</td></tr><?php endif; ?>
    </table></div>
    <p class="muted small">Diukur di laptop: byte koneksi TCP ke IP host URL gate (beserta IP yang dipakai host itu 2 jam terakhir), tanpa membaca isi data.
      Browser gate dibuka tanpa QUIC agar terukur; berlaku setelah browser gate dibuka ulang. Host di CDN bersama (mis. Cloudflare) bisa ikut menghitung situs lain di IP yang sama.
      Data disimpan <?= TRAFFIC_KEEP_DAYS ?> hari.</p>
  </section>
</main>
<?php page_foot(['traffic.js?v=2']);
