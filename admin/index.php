<?php
require __DIR__ . '/auth.php';
require_login();
$csrf = csrf_token();
page_head('Dashboard', '', '<meta name="csrf" content="' . $csrf . '"><meta name="role" content="' . h(current_user()['role']) . '">');
topbar('index', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head">
    <div><h1>Dashboard</h1><p class="muted">Status laptop di semua lokasi, diperbarui otomatis tiap <?= setting('dashboard_refresh') ?> detik.</p></div>
    <div class="head-tools"><span class="live" id="clock">menghubungkan…</span><button class="btn sound-btn" type="button" data-sound title="Pengaturan suara peringatan">Suara</button></div>
  </div>

  <nav class="tabs" id="tabs"></nav>

  <section class="summary">
    <div class="stat"><span class="label">Total laptop</span><span class="value" id="s-total">–</span><span class="sic"><?= icon('devices') ?></span></div>
    <div class="stat ok"><span class="label">Sehat</span><span class="value" id="s-ok">–</span><span class="sic"><?= icon('pulse') ?></span></div>
    <div class="stat warn"><span class="label">Perlu perhatian</span><span class="value" id="s-warn">–</span><span class="sic"><?= icon('bell') ?></span></div>
    <div class="stat offline"><span class="label">Offline</span><span class="value" id="s-offline">–</span><span class="sic"><?= icon('monitor') ?></span></div>
  </section>

  <section class="scanbar" title="Hasil scan halaman gate hari ini (agent 1.6+)">
    <span class="lbl">Scan hari ini</span>
    <span class="sb ok">✓ Berhasil <b id="sc-ok">–</b></span>
    <span class="sb be">⏳ Belum waktunya <b id="sc-belum">–</b></span>
    <span class="sb nf">✗ Tidak ditemukan <b id="sc-nf">–</b></span>
    <span class="sb er">⚠ Gagal kirim <b id="sc-err">–</b></span>
  </section>

  <section class="toolbar" id="toolbar" hidden>
    <span class="lbl">Kirim ke <b id="scope">semua laptop</b>:</span>
    <button class="btn" data-all="PING">Ping</button>
    <button class="btn" data-all="MESSAGE">Pesan</button>
    <button class="btn" data-all="OPEN_GATE">Buka halaman gate</button>
    <button class="btn" data-all="RESTART_BROWSER">Restart browser</button>
    <button class="btn" data-all="SCREENSHOT">Screenshot</button>
    <button class="btn" data-all="WAKE" title="Wake-on-LAN untuk laptop yang offline di tab ini">Nyalakan yang offline</button>
    <button class="btn btn-danger" data-all="REBOOT">Reboot</button>
    <button class="btn btn-danger" data-all="SHUTDOWN">Shutdown</button>
  </section>

  <section class="grid" id="grid"><p class="muted">Memuat…</p></section>

  <section class="panels">
    <div class="card"><h2><?= icon('bell') ?> Log kejadian</h2><ul class="log" id="log"></ul></div>
    <div class="card"><h2><?= icon('pulse') ?> Perintah terakhir</h2><div class="table-wrap"><table class="table small" id="cmds"></table></div></div>
  </section>
</main>

<div class="modal" id="modal" hidden><div class="modal-box"><h3 id="m-title"></h3><div id="m-body"></div><div class="actions"><button class="btn" id="m-cancel">Tutup</button><button class="btn btn-primary" id="m-ok">Kirim</button></div></div></div>
<?php page_foot(['alerts.js?v=3', 'app.js?v=19', 'shot.js?v=1']);
