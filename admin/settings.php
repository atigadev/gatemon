<?php
// Pengaturan waktu: interval heartbeat agent, batas offline, refresh dashboard, masa berlaku kode instalasi.
// Disimpan di tabel settings dan langsung dipakai (agent mengikuti interval baru pada heartbeat berikutnya).
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$spec = settings_spec();
$msg = '';
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    if (($_POST['act'] ?? '') === 'reset') {
        // hanya pengaturan di halaman ini; penerapan wallpaper (wallpaper_*) diatur di menu Wallpaper
        $in = implode(',', array_fill(0, count($spec), '?'));
        $pdo->prepare("DELETE FROM settings WHERE k IN ($in)")->execute(array_keys($spec));
        $msg = 'Pengaturan dikembalikan ke bawaan.';
    } else {
        $new = [];
        foreach ($spec as $k => $s) {
            if (($s['type'] ?? '') === 'bool') { $new[$k] = empty($_POST[$k]) ? 0 : 1; continue; }   // checkbox: tidak dikirim = mati
            if (($s['type'] ?? '') === 'text') {   // pola URL: dipisah koma, karakter aman saja
                $v = implode(', ', array_filter(array_map('trim', explode(',', (string)($_POST[$k] ?? '')))));
                if ($v === '' || strlen($v) > 200 || !preg_match('#^[A-Za-z0-9_\-./, ]+$#', $v)) { $err = "{$s['label']} wajib diisi (huruf, angka, - _ . / dipisah koma)."; break; }
                $new[$k] = $v;
                continue;
            }
            $v = filter_var($_POST[$k] ?? '', FILTER_VALIDATE_INT);
            if ($v === false || $v < $s['min'] || $v > $s['max']) { $err = "{$s['label']} harus {$s['min']}–{$s['max']} {$s['unit']}."; break; }
            $new[$k] = $v;
        }
        if (!$err && $new['offline_after'] < 3 * $new['heartbeat_interval']) {
            $err = 'Batas offline minimal 3x interval heartbeat (' . (3 * $new['heartbeat_interval']) . ' detik), agar laptop tidak salah dianggap offline.';
        }
        if (!$err) {
            $st = $pdo->prepare("REPLACE INTO settings (k, v, updated_at) VALUES (?, ?, NOW())");
            foreach ($new as $k => $v) $st->execute([$k, (string)$v]);
            log_event(null, 'info', current_user()['username'] . " mengubah pengaturan: heartbeat {$new['heartbeat_interval']} dtk, offline {$new['offline_after']} dtk, refresh {$new['dashboard_refresh']} dtk, buka gate otomatis " . ($new['auto_open_gate'] ? 'ON' : 'OFF') . ", buka ulang " . ($new['auto_reopen_gate'] ? "ON ({$new['reopen_after']} dtk)" : 'OFF'));
            $msg = 'Pengaturan disimpan. Agent memakai interval baru pada heartbeat berikutnya; dashboard yang terbuka ikut menyesuaikan.';
        }
    }
    if (!$err) { $_SESSION['flash'] = $msg; header('Location: settings.php'); exit; }
}
if (!$err && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }

$cur = [];
foreach ($spec as $k => $s) {
    $t = $s['type'] ?? '';
    // saat gagal simpan, tampilkan lagi isian yang dikirim
    $cur[$k] = !$err ? setting($k) : ($t === 'bool' ? (empty($_POST[$k]) ? 0 : 1) : ($t === 'text' ? (string)($_POST[$k] ?? '') : (int)($_POST[$k] ?? setting($k))));
}
$n = (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn();
$ng = (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE mode='gate'")->fetchColumn();
$csrf = csrf_token();
$ro = is_supervisor() ? '' : 'disabled';

page_head('Pengaturan');
topbar('settings', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Pengaturan</h1><p class="muted">Waktu heartbeat agent dan refresh dashboard. Berlaku langsung tanpa memasang ulang agent.</p></div></div>
<?= $err ? '<p class="notice error">' . h($err) . '</p>' : flash($msg) ?>
<?php if (!is_supervisor()): ?><p class="notice">Hanya supervisor yang bisa mengubah pengaturan.</p><?php endif; ?>

<div class="settings-grid">
<section class="card">
  <form method="post" id="set-form" class="set-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <?php foreach ($spec as $k => $s): $bool = ($s['type'] ?? '') === 'bool'; $text = ($s['type'] ?? '') === 'text'; ?>
    <?php if ($k === 'heartbeat_interval'): ?><h2><?= icon('gear') ?> Waktu</h2><?php endif; ?>
    <?php if ($k === 'auto_open_gate'): ?><h2 class="set-sec"><?= icon('monitor') ?> Halaman gate otomatis <span class="badge">agent 1.3+</span></h2><?php endif; ?>
    <?php if ($k === 'gate_check_interval'): ?><h2 class="set-sec"><?= icon('pulse') ?> Hemat trafik <span class="badge">agent 1.5+</span></h2><?php endif; ?>
    <?php if ($k === 'scan_capture'): ?><h2 class="set-sec"><?= icon('pulse') ?> Hasil scan halaman gate <span class="badge">agent 1.6+</span></h2><?php endif; ?>
    <div class="set-row">
      <div class="set-txt"><label for="s-<?= $k ?>"><?= h($s['label']) ?></label><p class="muted"><?= h($s['help']) ?></p>
        <small class="muted">Bawaan <?= $bool ? ($s['def'] ? 'aktif' : 'mati') : ($text ? '<code>' . h($s['def']) . '</code>' : (int)$s['def'] . ' ' . h($s['unit']) . ' · rentang ' . $s['min'] . '–' . $s['max']) ?></small></div>
      <?php if ($bool): ?>
      <label class="switch"><input type="checkbox" id="s-<?= $k ?>" name="<?= $k ?>" value="1" <?= $cur[$k] ? 'checked' : '' ?> <?= $ro ?>><span></span></label>
      <?php elseif ($text): ?>
      <div class="set-input set-text"><input type="text" id="s-<?= $k ?>" name="<?= $k ?>" value="<?= h($cur[$k]) ?>" maxlength="200" required pattern="[A-Za-z0-9_\-./, ]+" spellcheck="false" <?= $ro ?>></div>
      <?php else: ?>
      <div class="set-input"><input type="number" id="s-<?= $k ?>" name="<?= $k ?>" value="<?= $cur[$k] ?>" min="<?= $s['min'] ?>" max="<?= $s['max'] ?>" required <?= $ro ?>><span><?= h($s['unit']) ?></span></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (is_supervisor()): ?>
    <div class="actions set-actions">
      <button class="btn" name="act" value="reset" formnovalidate onclick="return confirm('Kembalikan semua pengaturan ke bawaan?')">Kembalikan bawaan</button>
      <button class="btn btn-primary" name="act" value="save"><?= icon('save') ?> Simpan</button>
    </div>
    <?php endif; ?>
  </form>
</section>

<section class="card">
  <h2><?= icon('pulse') ?> Perkiraan beban</h2>
  <p class="muted">Dihitung dari <?= $n ?> laptop terdaftar (<?= $ng ?> mode gate). Berguna bila server di hosting yang membatasi jumlah request.</p>
  <div class="load" id="load" data-n="<?= $n ?>" data-ng="<?= $ng ?>">
    <div><span>Heartbeat ke server</span><b id="l-hb">–</b></div>
    <div><span>Cek halaman gate ke internet (HEAD)</span><b id="l-gate">–</b></div>
    <div><span>Trafik internet cek halaman gate</span><b id="l-gatebw">–</b></div>
    <div><span>Per layar dashboard/monitor yang terbuka</span><b id="l-dash">–</b></div>
    <div><span>Laptop mati terdeteksi paling lambat</span><b id="l-off">–</b></div>
  </div>
  <p class="muted small">Rekomendasi: LAN 5 detik / offline 30 detik. Hosting bersama 10–15 detik / offline 60 detik.</p>
</section>
<?php if (is_supervisor()): ?>
<section class="card">
  <h2><?= icon('download') ?> Pindah / cadangkan server</h2>
  <p class="muted">Satu ZIP berisi seluruh GateMon seperti server ini: kode, <code>config.php</code>, agent + installer Python &amp; wheels,
    dan salinan database (laptop, kategori, pengaturan, akun admin, token). Di server tujuan cukup ekstrak lalu jalankan
    <code>INSTALL-SERVER.bat</code>.</p>
  <form method="post" action="export_server.php" class="export-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label class="check"><input type="checkbox" name="history" value="1" checked> Sertakan riwayat (log, perintah, screenshot, hasil scan)</label>
    <button class="btn btn-primary"><?= icon('download') ?> Unduh paket server</button>
  </form>
  <p class="notice warn-notice small">Paket berisi hash password &amp; token laptop. Simpan di tempat aman dan hapus setelah dipakai.
    Agar agent tidak perlu dipasang ulang, beri server baru <b>IP yang sama</b> dengan server ini.</p>
</section>
<?php endif; ?>
</div>
</main>
<script>
// perkiraan beban dihitung ulang saat angka diubah
(function(){
  const L = document.getElementById('load'), n = +L.dataset.n, ng = +L.dataset.ng, v = id => +document.getElementById('s-' + id).value || 1;
  const fmt = x => (x >= 100 ? Math.round(x) : Math.round(x * 10) / 10) + ' request/menit';
  function calc(){
    document.getElementById('l-hb').textContent = fmt(n * 60 / v('heartbeat_interval'));
    document.getElementById('l-gate').textContent = fmt(ng * 60 / v('gate_check_interval'));
    // HEAD lewat koneksi keep-alive: ~1 KB per cek (dulu ~21 KB: halaman penuh + koneksi TLS baru tiap heartbeat)
    const kbps = ng * 1 * 8 / v('gate_check_interval');
    document.getElementById('l-gatebw').textContent = (kbps < 1 ? kbps.toFixed(2) : kbps.toFixed(1)) + ' kbps';
    document.getElementById('l-dash').textContent = fmt(60 / v('dashboard_refresh'));
    document.getElementById('l-off').textContent = v('offline_after') + ' detik';
  }
  document.getElementById('set-form').addEventListener('input', calc); calc();
})();
</script>
<?php page_foot();
