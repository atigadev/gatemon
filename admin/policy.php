<?php
// Kebijakan daya & Windows Update laptop (agent >= 1.10). Global untuk semua laptop; "Jangan ubah" = agent tidak menyentuh.
// Agent menerapkan lewat powercfg (daya) dan service wuauserv + policy registry (Windows Update), butuh hak Administrator.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    $power = isset(POWER_MODES[$_POST['power'] ?? '']) ? $_POST['power'] : '';
    $wu = isset(WINUPDATE_MODES[$_POST['winupdate'] ?? '']) ? $_POST['winupdate'] : '';
    $st = $pdo->prepare("REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, NOW())");
    $st->execute(['power_mode', $power]);
    $st->execute(['winupdate', $wu]);
    log_event(null, 'warn', current_user()['username'] . ' mengubah kebijakan laptop: daya=' . ($power ?: 'jangan ubah') . ', Windows Update=' . ($wu ?: 'jangan ubah'));
    $_SESSION['flash'] = 'Kebijakan disimpan. Laptop online (agent 1.10+) menerapkannya pada heartbeat berikutnya.';
    header('Location: policy.php');
    exit;
}
$msg = '';
if (!$err && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }

$cfg = policy_cfg();
$offline_after = setting('offline_after');
$devs = $pdo->query("SELECT d.id, d.name, d.device_key, d.agent_version, d.last_seen, d.power_state, d.winupdate_state, d.policy_err, c.name AS cat, c.color
    FROM devices d LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();

// status satu aspek (daya/WU) untuk satu laptop: target vs yang dilaporkan agent
function aspect_state($d, $target, $applied, $old_agent, $online) {
    if ($target === '') return ['none', 'Tidak diatur'];
    if ($old_agent) return ['warn', 'Perlu agent ' . POLICY_MIN . '+'];
    if ((string)$applied === $target) return ['ok', 'Diterapkan'];
    if ((string)$d['policy_err'] !== '') return ['offline', 'Gagal'];
    return $online ? ['pending', 'Sedang diterapkan'] : ['none', 'Menunggu laptop online'];
}
$sum = ['target' => 0, 'ok' => 0];
foreach ($devs as &$d) {
    $d['online'] = $d['last_seen'] && time() - strtotime($d['last_seen']) <= $offline_after;
    $old = !$d['agent_version'] || version_compare($d['agent_version'], POLICY_MIN, '<');
    $d['pw'] = aspect_state($d, $cfg['power'], $d['power_state'], $old, $d['online']);
    $d['wu'] = aspect_state($d, $cfg['winupdate'], $d['winupdate_state'], $old, $d['online']);
    foreach (['power' => 'pw', 'winupdate' => 'wu'] as $k => $a) {
        if ($cfg[$k] === '') continue;
        $sum['target']++;
        if ($d[$a][0] === 'ok') $sum['ok']++;
    }
}
unset($d);
$any = $cfg['power'] !== '' || $cfg['winupdate'] !== '';
$csrf = csrf_token();
$ro = is_supervisor() ? '' : 'disabled';

page_head('Daya & Windows Update');
topbar('policy', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Daya &amp; Windows Update</h1><p class="muted">Atur rencana daya (tidur/layar mati) dan aktif/nonaktif Windows Update di semua laptop dari server. Butuh agent <?= POLICY_MIN ?>+ dan agent berjalan sebagai Administrator.</p></div></div>
<?= $err ? '<p class="notice error">' . h($err) . '</p>' : flash($msg) ?>
<?php if (!is_supervisor()): ?><p class="notice">Hanya supervisor yang bisa mengubah kebijakan.</p><?php endif; ?>

<section class="card">
  <form method="post" class="set-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <h2><?= icon('power') ?> Rencana daya</h2>
    <div class="set-row">
      <div class="set-txt"><label for="p-power">Rencana daya laptop</label>
        <p class="muted">Untuk laptop gate biasanya <b>Selalu aktif</b> agar layar tidak mati dan laptop tidak tidur saat ditinggal. Diterapkan ke rencana daya yang sedang aktif (AC &amp; baterai).</p></div>
      <select id="p-power" name="power" <?= $ro ?>><?php foreach (POWER_MODES as $k => $v): ?><option value="<?= $k ?>" <?= $cfg['power'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
    </div>
    <h2 class="set-sec"><?= icon('shield') ?> Windows Update</h2>
    <div class="set-row">
      <div class="set-txt"><label for="p-wu">Windows Update</label>
        <p class="muted"><b>Nonaktifkan</b> mematikan layanan Windows Update (<code>wuauserv</code>) dan update otomatis, agar laptop tidak mengunduh/restart saat acara. <b>Aktifkan</b> mengembalikannya. Nyalakan lagi berkala untuk menambal keamanan.</p></div>
      <select id="p-wu" name="winupdate" <?= $ro ?>><?php foreach (WINUPDATE_MODES as $k => $v): ?><option value="<?= $k ?>" <?= $cfg['winupdate'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select>
    </div>
    <?php if (is_supervisor()): ?>
    <div class="actions set-actions"><button class="btn btn-primary"><?= icon('save') ?> Simpan kebijakan</button></div>
    <?php endif; ?>
  </form>
</section>

<section class="card">
  <h2><?= icon('devices') ?> Status laptop <?php if ($sum['target']): ?><span class="badge <?= $sum['ok'] === $sum['target'] ? 'sup' : '' ?>"><?= $sum['ok'] ?>/<?= $sum['target'] ?> diterapkan</span><?php endif; ?></h2>
  <?php if (!$any): ?><p class="muted">Kedua kebijakan "Jangan ubah": GateMon tidak menyentuh daya / Windows Update laptop.</p><?php endif; ?>
  <div class="table-wrap"><table class="table small">
    <tr><th>Laptop</th><th>Kategori</th><th>Koneksi</th><th>Daya</th><th>Windows Update</th></tr>
    <?php foreach ($devs as $d): ?>
    <tr>
      <td><b><?= h($d['name']) ?></b> <code class="muted"><?= h($d['device_key']) ?></code><?= (string)$d['policy_err'] !== '' ? '<br><small class="muted" title="' . h($d['policy_err']) . '">' . h(mb_strimwidth($d['policy_err'], 0, 60, '…')) . '</small>' : '' ?></td>
      <td><span class="cat-badge" style="--cat:<?= h($d['color'] ?? '#64748b') ?>"><i></i><?= h($d['cat'] ?? 'Tanpa kategori') ?></span></td>
      <td><span class="state <?= $d['online'] ? 'ok' : 'none' ?>"><i></i><?= $d['online'] ? 'Online' : 'Offline' ?></span></td>
      <td><span class="state <?= $d['pw'][0] ?>"><i></i><?= h($d['pw'][1]) ?></span></td>
      <td><span class="state <?= $d['wu'][0] ?>"><i></i><?= h($d['wu'][1]) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
</main>
<?php page_foot();
