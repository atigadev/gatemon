<?php
// Update agent dari server (agent >= 1.9): kirim perintah UPDATE_AGENT ke laptop yang versinya lebih lama dari rilis.
// Rilis harus ditandatangani dengan kunci rahasia admin (admin-tools/rilis-agent.bat); agent menolak rilis tanpa tanda tangan sah.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$rel = agent_release();
$err = null;

$devs = $pdo->query("SELECT d.id, d.name, d.device_key, d.agent_version, d.last_seen, d.mode, d.camera_url, d.dvr_state, c.name AS cat, c.color FROM devices d
    LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();
// perintah UPDATE_AGENT terakhir per laptop
$last = [];
foreach ($pdo->query("SELECT c.device_id, c.status, c.result, c.created_at FROM commands c
    JOIN (SELECT device_id, MAX(id) id FROM commands WHERE type='UPDATE_AGENT' GROUP BY device_id) x ON x.id=c.id") as $r) $last[$r['device_id']] = $r;

// perintah INSTALL_DVR terakhir per laptop (Agent DVR / live camera, agent >= 1.23)
$dlast = [];
foreach ($pdo->query("SELECT c.device_id, c.status, c.result, c.created_at FROM commands c
    JOIN (SELECT device_id, MAX(id) id FROM commands WHERE type='INSTALL_DVR' GROUP BY device_id) x ON x.id=c.id") as $r) $dlast[$r['device_id']] = $r;
$dvr_ok = $rel['ready'] && !empty($rel['dvr']['ok']);
$offline_after = setting('offline_after');
foreach ($devs as &$d) {
    $v = $d['agent_version'];
    $l = $last[$d['id']] ?? null;
    $d['online'] = $d['last_seen'] && time() - strtotime($d['last_seen']) <= $offline_after;
    $d['can'] = false;
    if (!$v) [$d['st'], $d['label']] = ['none', 'Belum pernah lapor'];
    elseif (version_compare($v, AGENT_UPDATE_MIN, '<')) [$d['st'], $d['label']] = ['warn', 'Pasang ulang manual (/install/' . $d['device_key'] . ')'];
    elseif (!$rel['version'] || version_compare($v, $rel['version'], '>=')) [$d['st'], $d['label']] = ['ok', 'Terbaru'];
    elseif ($l && in_array($l['status'], ['pending', 'sent'], true)) [$d['st'], $d['label']] = ['pending', $l['status'] === 'pending' ? 'Menunggu laptop mengambil perintah' : 'Sedang update'];
    elseif ($l && $l['status'] === 'done' && strpos((string)$l['result'], 'restart') !== false && strtotime($l['created_at']) > time() - 600)
        [$d['st'], $d['label']] = ['pending', 'Restart ke versi baru…'];
    else {
        $d['can'] = true;
        [$d['st'], $d['label']] = $l && $l['status'] === 'failed' ? ['offline', 'Gagal: ' . $l['result']] : ['warn', 'Perlu update ke ' . $rel['version']];
    }
    // Agent DVR
    $dl = $dlast[$d['id']] ?? null;
    [$d['dst'], $d['dlabel']] = dvr_label($d['dvr_state']);
    $d['dres'] = $dl ? $dl : null;
    $d['dcan'] = false;
    if (!$v || version_compare($v, DVR_MIN, '<')) $d['dlabel'] = 'Perlu agent ' . DVR_MIN . '+ (update dulu)';
    elseif ($dl && in_array($dl['status'], ['pending', 'sent'], true)) [$d['dst'], $d['dlabel']] = ['pending', $dl['status'] === 'pending' ? 'Menunggu laptop mengambil perintah' : 'Sedang dipasang…'];
    elseif ($d['dst'] !== 'ok' && $d['dvr_state'] !== 'installing') $d['dcan'] = true;
}
unset($d);

// Pasang Agent DVR: laptop yang dipilih, atau semua laptop yang live camera-nya "Agent DVR di laptop ini"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor() && in_array($_POST['act'] ?? '', ['dvr_selected', 'dvr_all'], true)) {
    if (!csrf_ok($_POST['csrf'] ?? '')) die('csrf');
    $all = $_POST['act'] === 'dvr_all';
    $want = array_map('intval', (array)($_POST['dvr_ids'] ?? []));
    $pick = array_filter($devs, function ($d) use ($all, $want) { return $d['dcan'] && ($all ? $d['camera_url'] === 'auto' : in_array((int)$d['id'], $want, true)); });
    if (!$dvr_ok) $err = 'Installer Agent DVR belum siap: ' . ($rel['ready'] ? 'tanda tangani ulang rilis agar AgentDVRSetup.exe ikut (admin-tools\rilis-agent.bat).' : implode(' ', $rel['problems']));
    elseif (!$pick) $err = 'Tidak ada laptop yang perlu / bisa dipasang Agent DVR dari pilihan itu.';
    else {
        $user = current_user()['username'];
        $ins = $pdo->prepare("INSERT INTO commands (device_id, type, payload, status, created_by, created_at) VALUES (?, 'INSTALL_DVR', ?, 'pending', ?, NOW())");
        foreach ($pick as $d) $ins->execute([$d['id'], json_encode(['port' => dvr_port()]), $user]);
        log_event(null, 'warn', "$user mengirim instalasi Agent DVR ke " . count($pick) . ' laptop: ' . implode(', ', array_column($pick, 'name')));
        $_SESSION['flash'] = 'Instalasi Agent DVR dikirim ke ' . count($pick) . ' laptop. Tiap laptop mengunduh Agent DVR dari internet (beberapa menit).';
        header('Location: agent_update.php#dvr');
        exit;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor() && !$err) {
    if (!csrf_ok($_POST['csrf'] ?? '')) die('csrf');
    $want = ($_POST['act'] ?? '') === 'all' ? null : array_map('intval', (array)($_POST['ids'] ?? []));
    $pick = array_filter($devs, function ($d) use ($want) { return $d['can'] && ($want === null || in_array((int)$d['id'], $want, true)); });
    if (!$rel['ready']) $err = 'Rilis belum siap: ' . implode(' ', $rel['problems']);
    elseif (!$pick) $err = 'Tidak ada laptop yang perlu / bisa diupdate dari pilihan itu.';
    else {
        $user = current_user()['username'];
        $ins = $pdo->prepare("INSERT INTO commands (device_id, type, payload, status, created_by, created_at) VALUES (?, 'UPDATE_AGENT', ?, 'pending', ?, NOW())");
        foreach ($pick as $d) $ins->execute([$d['id'], $rel['version'], $user]);
        log_event(null, 'warn', "$user mengirim update agent {$rel['version']} ke " . count($pick) . ' laptop: ' . implode(', ', array_column($pick, 'name')));
        $_SESSION['flash'] = 'Perintah update dikirim ke ' . count($pick) . ' laptop. Laptop offline mengambilnya saat online.';
        header('Location: agent_update.php');
        exit;
    }
}
$msg = '';
if (!$err && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }
$n_can = count(array_filter($devs, function ($d) { return $d['can']; }));
$n_dvr_all = count(array_filter($devs, function ($d) { return $d['dcan'] && $d['camera_url'] === 'auto'; }));
$n_dvr_run = count(array_filter($devs, function ($d) { return $d['dst'] === 'ok'; }));
$csrf = csrf_token();

page_head('Update agent');
topbar('agent_update', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Update agent</h1><p class="muted">Perbarui GateMon Agent di laptop langsung dari server, tanpa memasang ulang. Hanya rilis yang ditandatangani kunci rahasia admin yang mau dipasang agent.</p></div></div>
<?= $err ? '<p class="notice error">' . h($err) . '</p>' : flash($msg) ?>

<div class="settings-grid">
<section class="card">
  <h2><?= icon('download') ?> Rilis di server</h2>
  <div class="load">
    <div><span>Versi agent di server (agent.py)</span><b><?= h(agent_version()) ?></b></div>
    <div><span>Versi rilis bertanda tangan</span><b><?= h($rel['version'] ?? '–') ?></b></div>
    <div><span>Ditandatangani</span><b><?= $rel['created'] ? h(date('d M Y H:i', strtotime($rel['created']))) : '–' ?></b></div>
    <div><span>Tanda tangan</span><b><?= $rel['sig_ok'] === null ? ($rel['version'] ? 'diperiksa oleh agent' : '–') : ($rel['sig_ok'] ? 'sah' : 'TIDAK SAH') ?></b></div>
    <div><span>Status</span><b class="<?= $rel['ready'] ? 'wol-ready' : 'wol-warn' ?>"><?= $rel['ready'] ? 'Siap dikirim' : 'Belum siap' ?></b></div>
  </div>
  <?php foreach ($rel['problems'] as $p): ?><p class="notice warn-notice small"><?= h($p) ?></p><?php endforeach; ?>
</section>
<section class="card">
  <h2><?= icon('key') ?> Cara membuat rilis</h2>
  <ol class="muted small steps">
    <li>Sekali saja: <code>admin-tools\rilis-agent.bat kunci-baru E:\gatemon-update.key</code> (simpan kunci di flashdisk + cadangan, jangan di server).</li>
    <li>Setiap versi baru: naikkan <code>VERSION</code> di <code>agent/agent.py</code>, colok flashdisk, jalankan
      <code>admin-tools\rilis-agent.bat tanda-tangan E:\gatemon-update.key</code>.</li>
    <li>Commit <code>agent/release.json</code> + <code>release.sig</code>, lalu kirim update di bawah. Coba 1 laptop dulu.</li>
  </ol>
  <p class="muted small">Laptop dengan agent di bawah <?= AGENT_UPDATE_MIN ?> belum punya fitur ini: pasang ulang sekali lewat <code>/install/ID-LAPTOP</code>.
    Bila versi baru tidak tersambung ke server dalam 150 detik, laptop otomatis kembali ke versi lama.</p>
</section>
</div>

<section class="card">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <div class="page-head" style="margin:0 0 8px"><h2 style="margin:0"><?= icon('devices') ?> Laptop <span class="badge"><?= $n_can ?> perlu update</span></h2>
    <?php if (is_supervisor()): ?><div class="actions">
      <button class="btn" name="act" value="selected" <?= $rel['ready'] ? '' : 'disabled' ?>><?= icon('download') ?> Update yang dipilih</button>
      <button class="btn btn-primary" name="act" value="all" <?= $rel['ready'] && $n_can ? '' : 'disabled' ?> data-confirm="Kirim update <?= h($rel['version']) ?> ke <?= $n_can ?> laptop?"><?= icon('download') ?> Update semua (<?= $n_can ?>)</button>
    </div><?php endif; ?></div>
    <div class="table-wrap"><table class="table small">
      <tr><?php if (is_supervisor()): ?><th></th><?php endif; ?><th>Laptop</th><th>Kategori</th><th>Versi</th><th>Koneksi</th><th>Status update</th></tr>
      <?php foreach ($devs as $d): ?>
      <tr>
        <?php if (is_supervisor()): ?><td><?php if ($d['can']): ?><input type="checkbox" name="ids[]" value="<?= $d['id'] ?>" aria-label="Pilih <?= h($d['name']) ?>"><?php endif; ?></td><?php endif; ?>
        <td><b><?= h($d['name']) ?></b> <code class="muted"><?= h($d['device_key']) ?></code></td>
        <td><span class="cat-badge" style="--cat:<?= h($d['color'] ?? '#64748b') ?>"><i></i><?= h($d['cat'] ?? 'Tanpa kategori') ?></span></td>
        <td><code><?= h($d['agent_version'] ?: '–') ?></code></td>
        <td><span class="state <?= $d['online'] ? 'ok' : 'none' ?>"><i></i><?= $d['online'] ? 'Online' : 'Offline' ?></span></td>
        <td><span class="state <?= $d['st'] ?>"><i></i><?= h($d['label']) ?></span></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </form>
</section>

<section class="card" id="dvr">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <div class="page-head" style="margin:0 0 8px"><h2 style="margin:0"><?= icon('camera') ?> Agent DVR (live camera) <span class="badge"><?= $n_dvr_run ?> berjalan</span> <span class="badge">agent <?= DVR_MIN ?>+</span></h2>
    <?php if (is_supervisor()): ?><div class="actions">
      <button class="btn" name="act" value="dvr_selected" <?= $dvr_ok ? '' : 'disabled' ?>><?= icon('download') ?> Pasang di yang dipilih</button>
      <button class="btn btn-primary" name="act" value="dvr_all" <?= $dvr_ok && $n_dvr_all ? '' : 'disabled' ?> data-confirm="Pasang Agent DVR di <?= $n_dvr_all ?> laptop yang live camera-nya Agent DVR?" title="Laptop yang di menu Devices memakai live camera &quot;Agent DVR di laptop ini&quot;"><?= icon('download') ?> Pasang di semua laptop kamera (<?= $n_dvr_all ?>)</button>
    </div><?php endif; ?></div>
    <p class="muted small">Laptop mengambil installer resmi <code><?= h($rel['dvr']['file'] ?? 'AgentDVRSetup.exe') ?></code> dari server, mencocokkannya dengan rilis bertanda tangan
      dan tanda tangan resmi pembuat Agent DVR, lalu memasang tanpa jendela. Installer mengunduh Agent DVR dari <b>internet</b>, jadi laptop perlu internet (beberapa menit).
      Port <?= dvr_port() ?> dibuka di firewall laptop hanya untuk jaringan lokal. Setelah terpasang, <b>tambahkan kamera sekali</b> lewat <code>http://IP-laptop:<?= dvr_port() ?></code>,
      lalu atur live camera laptop di menu Devices ke <b>Agent DVR di laptop ini</b>.</p>
    <?php if (!$dvr_ok): ?><p class="notice warn-notice small"><?= dvr_installer()
        ? 'AgentDVRSetup.exe ada di agent/installer tetapi belum ikut rilis bertanda tangan. Jalankan <code>admin-tools\rilis-agent.bat tanda-tangan &lt;file-kunci&gt;</code> lalu commit release.json + release.sig.'
        : 'Letakkan installer resmi <code>AgentDVRSetup.exe</code> (unduh dari ispyconnect.com) di folder <code>agent/installer</code>, lalu tanda tangani rilis.' ?></p><?php endif; ?>
    <div class="table-wrap"><table class="table small">
      <tr><?php if (is_supervisor()): ?><th></th><?php endif; ?><th>Laptop</th><th>Live camera</th><th>Agent DVR</th><th>Hasil terakhir</th></tr>
      <?php foreach ($devs as $d): $r = $d['dres']; ?>
      <tr>
        <?php if (is_supervisor()): ?><td><?php if ($d['dcan']): ?><input type="checkbox" name="dvr_ids[]" value="<?= $d['id'] ?>" aria-label="Pilih <?= h($d['name']) ?>"><?php endif; ?></td><?php endif; ?>
        <td><b><?= h($d['name']) ?></b> <code class="muted"><?= h($d['device_key']) ?></code></td>
        <td class="muted"><?= $d['camera_url'] === 'auto' ? 'Agent DVR di laptop ini' : ($d['camera_url'] !== '' ? 'URL custom' : '–') ?></td>
        <td><span class="state <?= $d['dst'] ?>"><i></i><?= h($d['dlabel']) ?></span></td>
        <td class="muted"><?= $r ? '<span class="badge' . ($r['status'] === 'done' ? ' sup' : '') . '">' . h(['pending' => 'menunggu', 'sent' => 'diproses', 'done' => 'selesai', 'failed' => 'gagal'][$r['status']] ?? $r['status']) . '</span> ' . h(mb_substr((string)$r['result'], 0, 220)) . ' <small>' . h(date('d/m H:i', strtotime($r['created_at']))) . '</small>' : '–' ?></td>
      </tr>
      <?php endforeach; ?>
    </table></div>
  </form>
</section>
</main>
<?php page_foot();
