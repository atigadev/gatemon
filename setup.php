<?php
// Instalasi baru: jalankan lewat browser http://localhost/gatemon/setup.php lalu HAPUS file ini.
// Pemeriksaan sistem + struktur database (schema.php) + akun awal. Update kode berikutnya tidak perlu setup.php:
// db() memperbarui struktur database otomatis.
// Pengaman kedua (selain .htaccess): hanya dari laptop server sendiri
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('setup.php hanya bisa dijalankan dari laptop server: buka http://localhost/gatemon/setup.php');
}
$CFG = require __DIR__ . '/config.php';

// ---------- pemeriksaan sistem (wajib = GateMon tidak jalan tanpanya; opsional = ada cadangan bawaan) ----------
$ini = php_ini_loaded_file() ?: '(php.ini tidak ditemukan)';
$checks = [
    ['PHP versi 7.4 atau lebih baru', version_compare(PHP_VERSION, '7.4.0', '>='), true, 'Sekarang ' . PHP_VERSION],
    ['Ekstensi pdo_mysql (database)', extension_loaded('pdo_mysql'), true, "Aktifkan <code>extension=pdo_mysql</code> di $ini lalu restart Apache"],
    ['Ekstensi json', function_exists('json_encode'), true, 'Bawaan PHP; pasang paket php-json'],
    ['Ekstensi zip (paket instalasi agent)', class_exists('ZipArchive'), false, "Opsional: tanpa ini GateMon memakai pembuat ZIP bawaan (tanpa kompresi, file lebih besar). Aktifkan: hapus titik koma di <code>;extension=zip</code> pada $ini lalu restart Apache"],
    ['Ekstensi mbstring', extension_loaded('mbstring'), false, 'Opsional: ada cadangan bawaan'],
    ['Ekstensi zlib (kompres data dashboard)', function_exists('gzencode'), false, 'Opsional: tanpa ini data dashboard dikirim tanpa kompresi'],
    ['Apache mod_rewrite (link /install/ID-LAPTOP)', function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null, false,
        'Opsional: tanpa ini gunakan <code>install/index.php?id=ID-LAPTOP</code>. Aktifkan <code>LoadModule rewrite_module</code> di httpd.conf'],
];
echo '<h3>Pemeriksaan sistem GateMon</h3><table border="1" cellpadding="6" style="border-collapse:collapse;font-family:sans-serif;font-size:14px">';
$fatal = false;
foreach ($checks as [$name, $ok, $required, $how]) {
    $st = $ok === null ? 'tidak dapat dicek' : ($ok ? 'OK' : ($required ? 'WAJIB, BELUM ADA' : 'belum aktif (opsional)'));
    $color = $ok ? '#0a7d4f' : ($required ? '#c0392b' : '#b7791f');
    if (!$ok && $required && $ok !== null) $fatal = true;
    echo "<tr><td>$name</td><td style='color:$color;font-weight:bold'>$st</td><td>" . ($ok ? '' : $how) . '</td></tr>';
}
echo '</table>';
if ($fatal) exit('<p style="color:#c0392b"><b>Perbaiki yang WAJIB di atas, restart Apache, lalu buka setup.php lagi.</b></p>');

$c = $CFG['db'];
$pdo = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$c['name']}`");
// Tabel & kolom (idempoten, lihat schema.php). Data yang sudah ada tidak diubah.
require __DIR__ . "/schema.php";
$done = db_migrate($pdo);
echo "<p>Struktur database versi " . SCHEMA_VERSION . ": " . ($done ? htmlspecialchars(implode(", ", $done)) : "sudah terbaru, tidak ada perubahan") . ".</p>";
// Akun awal hanya bila belum ada akun sama sekali (tanpa akun tidak ada yang bisa login)
$n = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
if ($n == 0) {
    $st = $pdo->prepare("INSERT INTO admins (username,password_hash,role,created_at) VALUES (?,?,?,NOW())");
    $st->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'supervisor']);
    $st->execute(['viewer', password_hash('viewer123', PASSWORD_DEFAULT), 'viewer']);
    echo "<p>Admin dibuat: <b>admin / admin123</b> (supervisor), <b>viewer / viewer123</b> (viewer). Segera ganti password di menu Admins.</p>";
}
echo "<p>Setup selesai. <b>HAPUS file setup.php sekarang.</b> Lanjut ke <a href='admin/login.php'>halaman login</a>.</p>";
