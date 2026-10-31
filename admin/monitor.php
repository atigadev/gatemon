<?php
// Tampilan layar penuh untuk layar monitor ruang kontrol: hanya card laptop, otomatis muat 1 layar.
require __DIR__ . '/auth.php';
require_login();
$csrf = csrf_token();
page_head('Monitor', 'wall', '<meta name="csrf" content="' . $csrf . '"><meta name="role" content="' . h(current_user()['role']) . '">');
?>
<header class="wallbar">
  <a class="brand" href="index.php"><?= logo() ?><span><?= h($CFG['app_name']) ?></span></a>
  <nav class="tabs" id="tabs"></nav>
  <span class="spacer"></span>
  <div class="chips">
    <span class="chip">Total <b id="s-total">–</b></span>
    <span class="chip ok">Sehat <b id="s-ok">–</b></span>
    <span class="chip warn">Perhatian <b id="s-warn">–</b></span>
    <span class="chip offline">Offline <b id="s-offline">–</b></span>
    <span class="chip scan" title="Scan hari ini: berhasil / belum waktunya / tidak ditemukan">Scan <b class="ok" id="sc-ok">–</b><b class="be" id="sc-belum">–</b><b class="nf" id="sc-nf">–</b></span>
  </div>
  <span class="live" id="clock">menghubungkan…</span>
  <button class="btn sound-btn" type="button" data-sound title="Pengaturan suara peringatan">Suara</button>
  <?= theme_button() ?>
  <button class="btn" data-fullscreen title="Layar penuh (F11)"><?= icon('expand') ?> Layar penuh</button>
  <a class="btn" href="index.php"><?= icon('dashboard') ?> Dashboard</a>
</header>
<main class="grid" id="grid"><p class="muted">Memuat…</p></main>

<div class="modal" id="modal" hidden><div class="modal-box"><h3 id="m-title"></h3><div id="m-body"></div><div class="actions"><button class="btn" id="m-cancel">Tutup</button><button class="btn btn-primary" id="m-ok">Kirim</button></div></div></div>
<?php page_foot(['alerts.js?v=3', 'app.js?v=19', 'shot.js?v=1']);
