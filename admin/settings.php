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
    if (!csrf_ok($_POST['csrf'] ?? '')) die('csrf');
    if (($_POST['act'] ?? '') === 'reset') {
        // hanya pengaturan di halaman ini; penerapan wallpaper (wallpaper_*) diatur di menu Wallpaper
        $in = implode(',', array_fill(0, count($spec), '?'));
        $pdo->prepare("DELETE FROM settings WHERE k IN ($in)")->execute(array_keys($spec));
        $msg = 'Pengaturan dikembalikan ke bawaan.';
    } else {
        $new = [];
        foreach ($spec as $k => $s) {
            if (($s['type'] ?? '') === 'bool') { $new[$k] = empty($_POST[$k]) ? 0 : 1; continue; }   // checkbox: tidak dikirim = mati
            if (!empty($s['url'])) {   // link https berisi {id} (sama dengan pemeriksaan di purpose_fill.php)
                $v = trim((string)($_POST[$k] ?? ''));
                if (strlen($v) > 300 || !preg_match('#^https://[^\s"\'<>]+$#i', $v) || strpos($v, '{id}') === false) { $err = "{$s['label']} harus link https:// yang berisi {id}."; break; }
                $new[$k] = $v;
                continue;
            }
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
// hasil perintah pindah server terakhir per laptop (menu "Pindahkan agent ke server lain")
$moves = $pdo->query("SELECT d.name, d.agent_version, c.status, c.result, c.payload, c.created_at FROM devices d
    LEFT JOIN (SELECT device_id, MAX(id) id FROM commands WHERE type='SET_SERVER' GROUP BY device_id) x ON x.device_id=d.id
    LEFT JOIN commands c ON c.id=x.id ORDER BY d.name")->fetchAll();
$any_move = (bool)array_filter($moves, function ($m) { return $m['status'] !== null; });

// bagian halaman: kunci pertama tiap bagian => judul; pengaturan sesudahnya ikut bagian itu sampai bagian berikut
$secs = [
    'heartbeat_interval'  => ['id' => 'waktu',   'icon' => 'gear',    'title' => 'Waktu & koneksi',            'badge' => '',            'desc' => 'Seberapa cepat laptop melapor dan dashboard diperbarui.'],
    'auto_open_gate'      => ['id' => 'gate',    'icon' => 'monitor', 'title' => 'Browser halaman gate',       'badge' => 'agent 1.3+',  'desc' => 'Cara browser gate dibuka di laptop mode gate. Kiosk & cetak langsung juga bisa diatur per laptop di menu Devices.'],
    'gate_check_interval' => ['id' => 'trafik',  'icon' => 'pulse',   'title' => 'Hemat trafik & penyimpanan', 'badge' => 'agent 1.5+',  'desc' => 'Beban internet venue dan ukuran database.'],
    'scan_capture'        => ['id' => 'scan',    'icon' => 'chart',   'title' => 'Hasil scan halaman gate',    'badge' => 'agent 1.6+',  'desc' => 'Data scan untuk menu Laporan dan peringatan scan gagal.'],
    'support_overlay'     => ['id' => 'support', 'icon' => 'chat',    'title' => 'Chat support & darurat',     'badge' => 'agent 1.11+', 'desc' => 'Tombol bantuan di layar laptop.'],
];
// pengaturan yang hanya berpengaruh bila sakelar lain aktif (tampil redup bila sakelarnya mati, tetap bisa diisi)
$deps = ['reopen_after' => 'auto_reopen_gate', 'scan_page_match' => 'scan_capture', 'scan_api_match' => 'scan_capture',
         'scan_purpose_key' => 'scan_capture', 'scan_fail_alert' => 'scan_capture'];
$groups = []; $g = null;
foreach ($spec as $k => $s) { if (isset($secs[$k])) $g = $k; $groups[$g][] = $k; }
$def_txt = function ($s) {
    $t = $s['type'] ?? '';
    if ($t === 'bool') return $s['def'] ? 'aktif' : 'mati';
    if ($t === 'text') return '<code>' . h($s['def']) . '</code>';
    return (int)$s['def'] . ' ' . h($s['unit']) . ' <span class="st-range">· rentang ' . $s['min'] . '–' . $s['max'] . '</span>';
};

page_head('Pengaturan');
topbar('settings', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Pengaturan</h1><p class="muted">Berlaku langsung tanpa memasang ulang agent: laptop mengikuti pada heartbeat berikutnya.</p></div>
    <?php if (is_supervisor()): ?><a class="btn" href="keamanan.php"><?= icon('shield') ?> Pemeriksaan keamanan</a><?php endif; ?></div>
<?= $err ? '<p class="notice error">' . h($err) . '</p>' : flash($msg) ?>
<?php if (!is_supervisor()): ?><p class="notice">Anda hanya bisa melihat. Hanya supervisor yang bisa mengubah pengaturan.</p><?php endif; ?>

<div class="st-layout">
  <nav class="st-nav" id="st-nav" aria-label="Bagian pengaturan">
    <div class="st-search"><input type="search" id="st-q" placeholder="Cari pengaturan…" aria-label="Cari pengaturan" autocomplete="off"></div>
    <?php foreach ($secs as $s): ?><a href="#<?= $s['id'] ?>" data-sec="<?= $s['id'] ?>"><?= icon($s['icon']) ?><span><?= h($s['title']) ?></span><i class="st-cnt" hidden></i></a><?php endforeach; ?>
    <?php if (is_supervisor()): ?>
    <a href="#cadangan" data-sec="cadangan"><?= icon('download') ?><span>Pindah / cadangkan server</span></a>
    <a href="#pindah-agent" data-sec="pindah-agent"><?= icon('enroll') ?><span>Pindahkan agent</span></a>
    <?php endif; ?>
  </nav>

  <div class="st-main">
  <form method="post" id="set-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <?php foreach ($groups as $first => $keys): $sec = $secs[$first]; ?>
    <section class="card st-sec" id="<?= $sec['id'] ?>">
      <div class="st-head">
        <h2><?= icon($sec['icon']) ?> <?= h($sec['title']) ?><?php if ($sec['badge']): ?> <span class="badge"><?= h($sec['badge']) ?></span><?php endif; ?></h2>
        <p class="muted"><?= h($sec['desc']) ?></p>
      </div>
      <?php foreach ($keys as $k): $s = $spec[$k]; $t = $s['type'] ?? 'int'; ?>
      <div class="st-row<?= $t === 'text' ? ' st-wide' : '' ?>" data-key="<?= $k ?>" data-type="<?= $t ?>" data-def="<?= h((string)$s['def']) ?>"<?= isset($deps[$k]) ? ' data-dep="' . $deps[$k] . '"' : '' ?>>
        <div class="st-txt">
          <label for="s-<?= $k ?>"><?= h($s['label']) ?><span class="st-dot" title="Diubah, belum disimpan"></span></label>
          <p class="muted"><?= h($s['help']) ?></p>
          <div class="st-meta"><span>Bawaan: <?= $def_txt($s) ?></span>
            <?php if (!$ro): ?><button type="button" class="st-undo" hidden>Pakai bawaan</button><?php endif; ?>
            <?php if (isset($deps[$k])): ?><span class="st-depnote">Tidak dipakai selama <b><?= h($spec[$deps[$k]]['label']) ?></b> mati</span><?php endif; ?></div>
          <p class="st-err" hidden></p>
        </div>
        <div class="st-ctl">
          <?php if ($t === 'bool'): ?>
          <label class="switch"><input type="checkbox" id="s-<?= $k ?>" name="<?= $k ?>" value="1" <?= $cur[$k] ? 'checked' : '' ?> <?= $ro ?>><span></span></label>
          <span class="st-state"><?= $cur[$k] ? 'Aktif' : 'Mati' ?></span>
          <?php elseif ($t === 'text'): ?>
          <?php if (!empty($s['url'])): ?>
          <input type="text" id="s-<?= $k ?>" name="<?= $k ?>" value="<?= h($cur[$k]) ?>" maxlength="300" required pattern="https://[^\s&quot;'<>]*\{id\}[^\s&quot;'<>]*" title="Link https:// yang berisi {id}" spellcheck="false" inputmode="url" <?= $ro ?>>
          <?php else: ?>
          <input type="text" id="s-<?= $k ?>" name="<?= $k ?>" value="<?= h($cur[$k]) ?>" maxlength="200" required pattern="[A-Za-z0-9_\-./, ]+" title="Huruf, angka, - _ . / dipisah koma" spellcheck="false" <?= $ro ?>>
          <?php endif; ?>
          <?php else: ?>
          <div class="st-num"><input type="number" id="s-<?= $k ?>" name="<?= $k ?>" value="<?= $cur[$k] ?>" min="<?= $s['min'] ?>" max="<?= $s['max'] ?>" required inputmode="numeric" <?= $ro ?>><span><?= h($s['unit']) ?></span></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if ($first === 'heartbeat_interval'): ?>
      <div class="st-load">
        <h3><?= icon('pulse') ?> Perkiraan beban <small class="muted">dari <?= $n ?> laptop terdaftar (<?= $ng ?> mode gate), ikut berubah saat angka diisi</small></h3>
        <div class="st-tiles" id="load" data-n="<?= $n ?>" data-ng="<?= $ng ?>">
          <div><span>Heartbeat ke server</span><b id="l-hb">–</b></div>
          <div><span>Cek halaman gate (HEAD)</span><b id="l-gate">–</b></div>
          <div><span>Trafik internet cek gate</span><b id="l-gatebw">–</b></div>
          <div><span>Per layar dashboard / monitor</span><b id="l-dash">–</b></div>
          <div><span>Laptop mati terdeteksi paling lambat</span><b id="l-off">–</b></div>
        </div>
        <p class="muted small">Rekomendasi: LAN 5 detik / offline 30 detik. Hosting bersama 10–15 detik / offline 60 detik.</p>
      </div>
      <?php endif; ?>
    </section>
    <?php endforeach; ?>
    <?php if (is_supervisor()): ?>
    <div class="st-bar" id="st-bar">
      <span class="st-bar-msg" id="st-msg">Semua pengaturan tersimpan</span>
      <button class="btn st-bar-reset" name="act" value="reset" formnovalidate data-confirm="Kembalikan SEMUA pengaturan di halaman ini ke bawaan?">Semua ke bawaan</button>
      <button type="button" class="btn" id="st-revert" disabled>Batalkan</button>
      <button class="btn btn-primary" name="act" value="save" id="st-save"><?= icon('save') ?> Simpan</button>
    </div>
    <?php endif; ?>
  </form>
  <p class="st-empty muted" id="st-empty" hidden>Tidak ada pengaturan yang cocok.</p>

<?php if (is_supervisor()): ?>
<div class="st-grid2">
<section class="card st-sec" id="cadangan">
  <h2><?= icon('download') ?> Pindah / cadangkan server</h2>
  <p class="muted">Satu ZIP berisi seluruh GateMon seperti server ini: kode, <code>config.php</code>, agent + installer Python &amp; wheels,
    dan salinan database (laptop, kategori, pengaturan, akun admin, token). Di server tujuan cukup ekstrak lalu jalankan
    <code>INSTALL-SERVER.bat</code> (XAMPP), atau unggah ke hosting lalu impor <code>database.sql</code> lewat phpMyAdmin (cPanel).</p>
  <form method="post" action="export_server.php" class="st-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Tujuan <select name="format"><option value="xampp">Server Windows + XAMPP (INSTALL-SERVER.bat)</option><option value="cpanel">Hosting cPanel (unggah + phpMyAdmin)</option></select></label>
    <label class="check"><input type="checkbox" name="history" value="1"> Sertakan riwayat (log, perintah, screenshot, hasil scan)</label>
    <label>Password Anda <input type="password" name="password" required autocomplete="current-password" maxlength="200"></label>
    <button class="btn btn-primary"><?= icon('download') ?> Unduh paket server</button>
  </form>
  <p class="notice warn-notice small">Paket berisi hash password &amp; token laptop. Simpan di tempat aman dan hapus setelah dipakai.
    Agar agent tidak perlu dipasang ulang, beri server baru <b>IP yang sama</b>, atau pakai <b>Pindahkan agent ke server lain</b>.</p>
</section>
<section class="card st-sec" id="pindah-agent">
  <h2><?= icon('enroll') ?> Pindahkan agent ke server lain <span class="badge">agent 1.13+</span></h2>
  <p class="muted">Pindah ke server baru (mis. hosting cPanel) <b>tanpa memasang ulang agent</b>.</p>
  <ol class="st-steps muted">
    <li>Server baru sudah berjalan dengan database dari paket <b>Pindah / cadangkan server</b>.</li>
    <li>Kirim perintah ini dari server <b>lama</b> (server ini).</li>
    <li>Tiap laptop menguji server baru dengan ID + tokennya. Bila gagal (alamat salah, sertifikat HTTPS tidak valid, database belum dipindah),
      laptop tetap di server ini dan alasannya tampil di tabel. Bila server baru tidak bisa dihubungi dalam 10 menit pertama, laptop kembali ke sini.</li>
  </ol>
  <form method="post" action="server_move.php" class="st-form" data-confirm="Pindahkan SEMUA laptop (agent 1.13+) ke server baru?">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <label>Alamat server baru <input type="url" name="url" required maxlength="300" placeholder="https://monitor.domain-anda.com/gatemon" pattern="https?://.+"></label>
    <label>Password Anda <input type="password" name="password" required autocomplete="current-password" maxlength="200"></label>
    <button class="btn btn-primary"><?= icon('enroll') ?> Kirim ke semua laptop</button>
  </form>
  <?php if ($any_move): ?>
  <div class="table-wrap"><table class="table small">
    <tr><th>Laptop</th><th>Agent</th><th>Status</th><th>Hasil</th></tr>
    <?php foreach ($moves as $m): ?>
    <tr><td><?= h($m['name']) ?></td><td class="muted"><?= h($m['agent_version'] ?: '–') ?></td>
      <td><?= $m['status'] === null ? '<span class="muted">–</span>' : '<span class="badge' . ($m['status'] === 'done' ? ' sup' : '') . '">' . h(['pending' => 'menunggu', 'sent' => 'diproses', 'done' => 'pindah', 'failed' => 'gagal'][$m['status']] ?? $m['status']) . '</span> <small class="muted">' . h($m['created_at']) . '</small>' ?></td>
      <td class="muted"><?= h((string)$m['result']) ?></td></tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</section>
</div>
<?php endif; ?>
  </div>
</div>
</main>
<?php page_foot(['settings.js?v=1']);
