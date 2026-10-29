<?php
// Menu Support: chat dengan petugas di laptop (tombol "Chat Support" di pojok kanan bawah layar laptop, agent >= 1.11)
// dan penanganan tombol DARURAT. Isi percakapan dimuat & diperbarui oleh support.js lewat support_api.php.
require __DIR__ . '/auth.php';
require_login();
$csrf = csrf_token();
page_head('Support', 'sup-page', '<meta name="csrf" content="' . $csrf . '"><meta name="role" content="' . h(current_user()['role']) . '">');
topbar('support', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Support</h1>
    <p class="muted">Pesan dari petugas lewat tombol <b>Chat Support</b> di pojok kanan bawah layar laptop, dan tombol <b>DARURAT</b>.
      Alarm darurat berbunyi di semua halaman admin yang terbuka sampai ditangani.</p></div></div>
  <?php if (!setting('support_overlay')): ?><p class="notice warn-notice">Tombol support di laptop sedang <b>dinonaktifkan</b> di menu Pengaturan (kecuali laptop yang diatur "Selalu tampil" di sini).</p><?php endif; ?>
  <?php if (!is_supervisor()): ?><p class="notice">Akun viewer hanya bisa membaca. Membalas dan menangani darurat hanya untuk supervisor.</p><?php endif; ?>

  <div class="sup-wrap" id="sup" data-dev="<?= (int)($_GET['dev'] ?? 0) ?>">
    <section class="card sup-side">
      <label class="sup-new">Mulai chat dengan laptop <select id="sup-pick"><option value="">Pilih laptop…</option></select></label>
      <?php if (is_supervisor()): ?><button type="button" class="btn" id="sup-show-all" title="Bila tombol di layar laptop hilang/tertutup">Tampilkan tombol di semua laptop</button><?php endif; ?>
      <div class="sup-convs" id="sup-convs"><p class="muted">Memuat…</p></div>
    </section>
    <section class="card sup-main" id="sup-main">
      <div class="sup-empty muted">Pilih percakapan di kiri.</div>
    </section>
  </div>
</main>
<?php page_foot(['support.js?v=2']);
