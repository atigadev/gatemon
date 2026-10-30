<?php
// Pemeriksaan keamanan server (supervisor): jalankan setelah unggah ke hosting / setelah mengubah konfigurasi.
// Termasuk uji dari luar: server meminta URL file sensitif miliknya sendiri, yang harus ditolak (403/404).
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); exit('Hanya supervisor.'); }
session_write_close();
@set_time_limit(120);
$pdo = db();
$root = realpath(__DIR__ . '/..');
$rows = [];   // [grup, nama, status ok|warn|bad|info, keterangan]
$add = function ($g, $name, $st, $note = '') use (&$rows) { $rows[] = [$g, $name, $st, $note]; };

// ---------- PHP & server ----------
$v = PHP_VERSION;
$add('PHP', 'Versi PHP', version_compare($v, '8.1.0', '>=') ? 'ok' : (version_compare($v, '7.4.0', '>=') ? 'warn' : 'bad'),
    "$v" . (version_compare($v, '8.1.0', '<') ? ' - sudah tidak mendapat perbaikan keamanan dari PHP; pakai 8.1+ di hosting' : ''));
foreach (['pdo_mysql' => true, 'mbstring' => true, 'json' => true, 'zip' => false, 'zlib' => false] as $ext => $req) {
    $add('PHP', "Ekstensi $ext", extension_loaded($ext) ? 'ok' : ($req ? 'bad' : 'warn'), extension_loaded($ext) ? '' : ($req ? 'wajib' : 'disarankan'));
}
$de = strtolower((string)ini_get('display_errors'));
$add('PHP', 'Pesan error tidak tampil ke pengunjung', in_array($de, ['', '0', 'off', 'false', 'stderr'], true) ? 'ok' : 'bad', "display_errors = " . ($de === '' ? 'off' : $de));
$add('PHP', 'Mode debug', empty($CFG['debug']) ? 'ok' : 'bad', empty($CFG['debug']) ? 'mati' : "matikan 'debug' di config.local.php");
$add('PHP', 'Versi PHP disembunyikan (expose_php)', ini_get('expose_php') ? 'warn' : 'ok', ini_get('expose_php') ? 'header X-Powered-By sudah dihapus oleh GateMon; expose_php hanya bisa diubah penyedia hosting' : '');

// ---------- HTTPS & sesi ----------
$https = is_https();
$add('HTTPS', 'Halaman ini dibuka lewat HTTPS', $https ? 'ok' : 'bad', $https ? '' : 'aktifkan SSL di hosting dan buka https://...');
$add('HTTPS', "Wajib HTTPS ('force_https')", !empty($CFG['force_https']) ? 'ok' : 'bad', !empty($CFG['force_https']) ? 'HTTP dialihkan/ditolak, HSTS aktif' : "isi 'force_https' => true di config.local.php");
$cp = session_get_cookie_params();
$add('HTTPS', 'Cookie sesi: Secure, HttpOnly, SameSite', $cp['secure'] && $cp['httponly'] && !empty($cp['samesite']) ? 'ok' : 'bad',
    'secure=' . ($cp['secure'] ? 'ya' : 'tidak') . ', httponly=' . ($cp['httponly'] ? 'ya' : 'tidak') . ', samesite=' . ($cp['samesite'] ?: '-'));
$add('HTTPS', 'Batas sesi', 'info', 'keluar otomatis bila ' . (int)$CFG['session_idle_minutes'] . ' menit tanpa aktivitas, wajib login ulang tiap ' . (int)$CFG['session_max_hours'] . ' jam');

// ---------- konfigurasi ----------
$add('Konfigurasi', 'config.local.php (rahasia di luar git)', is_file("$root/config.local.php") ? 'ok' : 'warn', is_file("$root/config.local.php") ? '' : 'buat dari config.local.example.php');
$add('Konfigurasi', 'User database bukan root', strtolower((string)$CFG['db']['user']) !== 'root' ? 'ok' : 'warn', 'user: ' . $CFG['db']['user']);
$add('Konfigurasi', 'Password database terisi', (string)$CFG['db']['pass'] !== '' ? 'ok' : 'bad', (string)$CFG['db']['pass'] === '' ? 'database tanpa password' : '');
$add('Konfigurasi', "setup_key kosong", (string)($CFG['setup_key'] ?? '') === '' ? 'ok' : 'warn', (string)($CFG['setup_key'] ?? '') === '' ? 'setup.php hanya dari localhost' : 'kosongkan setelah setup selesai');
$add('Konfigurasi', 'URL server untuk agent', !empty($CFG['agent_server_url']) ? (stripos($CFG['agent_server_url'], 'https://') === 0 ? 'ok' : 'warn') : 'warn',
    !empty($CFG['agent_server_url']) ? $CFG['agent_server_url'] : "isi 'agent_server_url' (https://...) agar link instalasi tidak bergantung header Host");
$found = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = strtr(substr($f->getPathname(), strlen($root) + 1), DIRECTORY_SEPARATOR, '/');
    if (strpos($rel, '.git/') === 0) continue;
    if (preg_match('#\.(key|sql|zip|bak)$|(^|/)config\.json$#i', $rel) && strpos($rel, 'agent/installer/') !== 0) $found[] = $rel;
}
$add('Konfigurasi', 'Tidak ada kunci rilis / salinan database / ZIP di folder web', $found ? 'bad' : 'ok', $found ? 'hapus: ' . implode(', ', array_slice($found, 0, 8)) : '');
$add('Konfigurasi', 'Folder wallpaper bisa ditulis', is_writable("$root/wallpaper") ? 'ok' : 'warn', is_writable("$root/wallpaper") ? '' : 'unggah wallpaper akan gagal (permission 0755)');

// ---------- akun ----------
$adm = $pdo->query("SELECT username, role, must_change, pw_changed_at, last_login_at FROM admins")->fetchAll();
$sup = array_filter($adm, function ($a) { return $a['role'] === 'supervisor'; });
$add('Akun', 'Jumlah supervisor', count($sup) >= 1 && count($sup) <= 3 ? 'ok' : 'warn', count($sup) . ' supervisor, ' . (count($adm) - count($sup)) . ' viewer');
foreach ($adm as $a) {
    if ((int)$a['must_change'] === 1) $add('Akun', "Akun {$a['username']}", 'warn', 'wajib ganti password saat login berikutnya');
    elseif (!$a['pw_changed_at']) $add('Akun', "Akun {$a['username']}", 'warn', 'password belum pernah diganti sejak aturan password baru; bila lemah, akan dipaksa ganti saat login');
}
$f24 = (int)$pdo->query("SELECT COUNT(*) FROM auth_fail WHERE scope='login' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
$ip24 = (int)$pdo->query("SELECT COUNT(DISTINCT ip) FROM auth_fail WHERE scope='login' AND created_at > NOW() - INTERVAL 1 DAY")->fetchColumn();
$add('Akun', 'Login gagal 24 jam terakhir', $f24 > 50 ? 'warn' : 'info', "$f24 kali dari $ip24 alamat IP (dibatasi: 10 gagal/IP dan 8 gagal/username per 15 menit)");

// ---------- uji dari luar: file sensitif harus ditolak ----------
$base = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST'])
      . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$probe = ['config.php', 'config.local.php', 'db.php', 'security.php', 'schema.php', '.git/HEAD', '.user.ini', '.htaccess', 'README.md', 'agent/agent.py',
    'agent/release.json', 'agent/install_agent.ps1', 'migrasi/import.php', 'migrasi/BACA-SAYA.txt', 'admin-tools/rilis-agent.php', 'admin/auth.php', 'admin/layout.php', 'wallpaper/.htaccess', 'live/.htaccess'];
$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: GateMon-security-check\r\n"],
    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
foreach ($probe as $p) {
    $code = null;
    $h = @get_headers("$base/$p", false, $ctx);
    if ($h && preg_match('#^HTTP/\S+\s+(\d{3})#', $h[0], $m)) $code = (int)$m[1];
    if ($code === null) $add('Uji dari luar', "/$p", 'info', 'tidak dapat dicek (server tidak bisa memanggil alamatnya sendiri)');
    else $add('Uji dari luar', "/$p", in_array($code, [401, 403, 404], true) ? 'ok' : ($code >= 300 && $code < 400 ? 'warn' : 'bad'), "HTTP $code" . ($code === 200 ? ' - TERBUKA: .htaccess tidak berlaku, file ini bisa dibuka siapa saja' : ''));
}
$h = @get_headers("$base/admin/login.php", true, $ctx);
if ($h) {
    $hh = array_change_key_case($h, CASE_LOWER);
    foreach (['content-security-policy' => 'Content-Security-Policy', 'x-frame-options' => 'X-Frame-Options', 'x-content-type-options' => 'X-Content-Type-Options', 'strict-transport-security' => 'Strict-Transport-Security (HSTS)'] as $k => $l) {
        $add('Uji dari luar', "Header $l", isset($hh[$k]) ? 'ok' : 'warn', isset($hh[$k]) ? '' : 'tidak ada');
    }
    $add('Uji dari luar', 'Header X-Powered-By (versi PHP)', isset($hh['x-powered-by']) ? 'warn' : 'ok', isset($hh['x-powered-by']) ? 'terlihat: ' . (is_array($hh['x-powered-by']) ? end($hh['x-powered-by']) : $hh['x-powered-by']) : 'tersembunyi');
}
if ($https) {
    $hp = @get_headers('http://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)$_SERVER['HTTP_HOST']) . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/admin/login.php', false, $ctx);
    if ($hp && preg_match('#^HTTP/\S+\s+(\d{3})#', $hp[0], $m)) $add('Uji dari luar', 'http:// dialihkan ke https://', in_array((int)$m[1], [301, 302, 307, 308], true) ? 'ok' : 'bad', "HTTP {$m[1]}");
}

$score = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'info' => 0];
foreach ($rows as $r) $score[$r[2]]++;
page_head('Pemeriksaan keamanan');
topbar('settings', 'Monitoring Laptop');
$label = ['ok' => 'OK', 'warn' => 'Perhatian', 'bad' => 'BAHAYA', 'info' => 'Info'];
?>
<main class="page">
  <div class="page-head"><div><h1>Pemeriksaan keamanan</h1>
    <p class="muted">Jalankan setelah GateMon diunggah ke hosting atau konfigurasi diubah. Server <?= h(PHP_OS_FAMILY) ?> · PHP <?= h(PHP_VERSION) ?> · <?= h($_SERVER['SERVER_SOFTWARE'] ?? '') ?></p></div>
    <a class="btn" href="settings.php">Kembali ke Pengaturan</a></div>
  <p class="notice<?= $score['bad'] ? ' error' : '' ?>"><b><?= $score['ok'] ?> OK</b> · <?= $score['warn'] ?> perlu perhatian · <b><?= $score['bad'] ?> bahaya</b><?= $score['bad'] ? ' - perbaiki sebelum dibuka ke publik.' : '' ?></p>
  <section class="card"><div class="table-wrap"><table class="table sec-table">
    <tr><th>Bagian</th><th>Pemeriksaan</th><th>Hasil</th><th>Keterangan</th></tr>
    <?php foreach ($rows as [$g, $n, $s, $note]): ?>
    <tr class="sec-<?= $s ?>"><td class="muted"><?= h($g) ?></td><td><?= h($n) ?></td><td><b><?= $label[$s] ?></b></td><td class="muted"><?= h($note) ?></td></tr>
    <?php endforeach; ?>
  </table></div></section>
</main>
<?php page_foot();
