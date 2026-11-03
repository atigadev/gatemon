<?php
// Aplikasi (PWA) & push notification: pasang GateMon di HP/PC, terima notifikasi DARURAT & chat dari laptop
// walau aplikasi tertutup. Butuh HTTPS: sertifikat dibuat dengan admin-tools/sertifikat-https.bat (CA lokal).
require __DIR__ . '/auth.php';
require_login();
require __DIR__ . '/../push.php';
[$ready, $why] = push_ready();
$host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
$https_url = 'https://' . preg_replace('/:\d+$/', '', $host) . ($_SERVER['REQUEST_URI'] ?? '/');
$ca = is_file(__DIR__ . '/../sertifikat/gatemon-ca.crt');
page_head('Notifikasi');
topbar('notifikasi', 'Monitoring Laptop');
?>
<main class="page" id="notif">
  <div class="page-head"><div><h1>Aplikasi &amp; notifikasi</h1>
    <p class="muted">Pasang GateMon sebagai aplikasi di HP / PC dan terima <b>push notification</b> DARURAT &amp; chat dari laptop gate, juga saat aplikasi atau browser ditutup.</p></div></div>

  <div class="nt-grid">
  <section class="card">
    <h2><?= icon('bell') ?> Push notification</h2>
    <div class="nt-rows">
      <div><span>HTTPS</span><b id="nt-https" class="nt-state warn">memeriksa…</b></div>
      <div><span>Aplikasi</span><b id="nt-app" class="nt-state warn">memeriksa…</b></div>
      <div><span>Push di browser ini</span><b id="nt-push" class="nt-state warn">memeriksa…</b></div>
    </div>
    <fieldset class="nt-kinds">
      <legend class="small muted">Kirim notifikasi untuk</legend>
      <label class="nt-check"><input type="checkbox" id="nt-emg" checked> <span><b>🚨 DARURAT</b> <small class="muted">tombol DARURAT ditekan di laptop (tetap tampil sampai disentuh, getar panjang)</small></span></label>
      <label class="nt-check" id="nt-chat-row"><input type="checkbox" id="nt-chat" checked> <span><b>💬 Chat support</b> <small class="muted">pesan baru dari petugas di laptop (petugas &amp; supervisor)</small></span></label>
    </fieldset>
    <div class="actions nt-actions">
      <button type="button" class="btn" data-pwa-install hidden><?= icon('download') ?> Pasang aplikasi</button>
      <button type="button" class="btn" id="nt-test" hidden><?= icon('pulse') ?> Kirim notifikasi uji</button>
      <button type="button" class="btn btn-ghost" id="nt-off" hidden>Matikan di browser ini</button>
      <button type="button" class="btn btn-primary" id="nt-on"><?= icon('bell') ?> Aktifkan notifikasi</button>
    </div>
    <p id="nt-msg" class="small nt-msg" role="status"></p>
    <h3 class="nt-sub">Browser akun ini yang menerima notifikasi</h3>
    <ul class="nt-list" id="nt-list"></ul>
    <p class="muted small" id="nt-none">Belum ada.</p>
    <?php if (!$ready): ?><p class="notice warn-notice">Server belum bisa mengirim push: <?= h($why) ?>.</p><?php endif; ?>
  </section>

  <section class="card">
    <h2><?= icon('key') ?> HTTPS &amp; sertifikat</h2>
    <?php if (!is_https()): ?>
    <p class="notice warn-notice">Halaman ini dibuka lewat <b>http</b>. Aplikasi &amp; push notification hanya bisa lewat HTTPS:
      <a href="<?= h($https_url) ?>"><?= h($https_url) ?></a></p>
    <?php endif; ?>
    <?php if ($ca): ?>
    <p>Server memakai sertifikat dari <b>CA GateMon</b> (jaringan lokal). Pasang file CA <b>sekali</b> di setiap HP / PC agar HTTPS dipercaya:</p>
    <p><a class="btn" href="../sertifikat/gatemon-ca.crt" download><?= icon('download') ?> Unduh sertifikat CA GateMon</a></p>
    <ul class="nt-steps small">
      <li><b>Android</b>: buka file yang diunduh, atau Setelan → Keamanan → Enkripsi &amp; kredensial → Instal sertifikat → <i>Sertifikat CA</i>. Lalu buka alamat https di Chrome.</li>
      <li><b>iPhone / iPad</b>: buka file di Safari → Izinkan → Setelan → Profil telah diunduh → Instal. Lalu Setelan → Umum → Mengenai → <i>Pengaturan Kepercayaan Sertifikat</i> → aktifkan GateMon CA.</li>
      <li><b>Windows</b>: klik dua kali file → Install Certificate → Local Machine → <i>Trusted Root Certification Authorities</i>. Tutup &amp; buka lagi Chrome / Edge.</li>
    </ul>
    <?php else: ?>
    <p class="muted">Sertifikat HTTPS belum dibuat di server ini. Di PC server jalankan <code>admin-tools\sertifikat-https.bat</code> (lihat README bagian <b>HTTPS &amp; aplikasi</b>), lalu restart Apache.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2><?= icon('chat') ?> Suara &amp; tips</h2>
    <ul class="nt-steps small">
      <li><b>Aplikasi tertutup</b>: notifikasi berbunyi dengan <b>suara notifikasi HP / PC</b> dan getar (browser tidak mengizinkan suara sendiri). DARURAT tetap di layar sampai disentuh.</li>
      <li><b>Aplikasi terbuka</b>: alarm GateMon langsung berbunyi (sirene DARURAT berulang + kalimat dibacakan, nada pesan chat). Klik sekali di halaman setelah dibuka agar browser mengizinkan suara.</li>
      <li><b>Android</b>: agar suara lebih keras / berbeda, tekan lama notifikasi GateMon → Setelan → atur suara &amp; jadikan <i>Prioritas</i>. Matikan <i>optimasi baterai</i> untuk Chrome agar notifikasi tidak tertunda.</li>
      <li><b>iPhone / iPad</b>: butuh iOS 16.4+, GateMon harus dipasang dari Safari (Bagikan → Tambah ke Layar Utama) lalu notifikasi diaktifkan dari aplikasi itu.</li>
      <li>HP dan server perlu <b>internet</b>: push dikirim lewat layanan browser (Google / Apple / Mozilla / Microsoft). Isi notifikasi dienkripsi, hanya browser ini yang bisa membacanya.</li>
    </ul>
  </section>
  </div>
</main>
<?php page_foot();
