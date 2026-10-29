<?php
// Instalasi baru / pemeriksaan sistem: pemeriksaan PHP, struktur database (schema.php), dan akun supervisor pertama.
// Update kode berikutnya tidak perlu setup.php: db() memperbarui struktur database otomatis.
// Akses: dari komputer server sendiri (localhost), ATAU dari luar dengan kunci setup_key di config.local.php
// (minimal 20 karakter): https://domain/gatemon/setup.php?key=KUNCI. Kosongkan setup_key lagi setelah selesai.
$CFG = require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Jakarta');
require __DIR__ . '/security.php';
security_bootstrap();
send_csp();
header('Content-Type: text/html; charset=utf-8');
$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };

$local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
$key = (string)($CFG['setup_key'] ?? '');
$given = (string)($_POST['key'] ?? $_GET['key'] ?? '');
$by_key = strlen($key) >= 20 && $given !== '' && hash_equals($key, $given);
if (!$local && !$by_key) {
    usleep(500000);
    http_response_code(403);
    exit('setup.php hanya bisa dibuka dari komputer server (http://localhost/...), atau dengan setup_key di config.local.php.');
}

echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">'
   . '<title>Setup GateMon</title></head><body style="font-family:sans-serif;font-size:14px;max-width:900px;margin:24px auto;padding:0 16px">';
// ---------- pemeriksaan sistem (wajib = GateMon tidak jalan tanpanya; opsional = ada cadangan bawaan) ----------
$ini = php_ini_loaded_file() ?: '(php.ini tidak ditemukan)';
$https = is_https();
$checks = [
    ['PHP versi 7.4 - 8.5', version_compare(PHP_VERSION, '7.4.0', '>='), true, 'Sekarang ' . PHP_VERSION],
    ['Ekstensi pdo_mysql (database)', extension_loaded('pdo_mysql'), true, "Aktifkan pdo_mysql (cPanel: Select PHP Version / MultiPHP INI Editor; XAMPP: $ini)"],
    ['Ekstensi json', function_exists('json_encode'), true, 'Bawaan PHP'],
    ['Ekstensi mbstring', extension_loaded('mbstring'), true, 'Aktifkan mbstring (cPanel: Select PHP Version → Extensions)'],
    ['Ekstensi zip (paket instalasi agent)', class_exists('ZipArchive'), false, 'Opsional: tanpa ini GateMon memakai pembuat ZIP bawaan (tanpa kompresi)'],
    ['Ekstensi zlib (kompres data dashboard)', function_exists('gzencode'), false, 'Opsional: tanpa ini data dashboard dikirim tanpa kompresi'],
    ['Koneksi HTTPS', $https || $local, !$local, 'Akses dari luar wajib HTTPS: aktifkan SSL/AutoSSL di cPanel lalu buka https://...'],
    ["config.local.php ('force_https' => true untuk server publik)", $local || !empty($CFG['force_https']), false, 'Tulis di config.local.php: \'force_https\' => true'],
];
echo '<h3>Pemeriksaan sistem GateMon</h3><table border="1" cellpadding="6" style="border-collapse:collapse">';
$fatal = false;
foreach ($checks as [$name, $ok, $required, $how]) {
    $st = $ok ? 'OK' : ($required ? 'WAJIB, BELUM ADA' : 'belum (disarankan)');
    $color = $ok ? '#0a7d4f' : ($required ? '#c0392b' : '#b7791f');
    if (!$ok && $required) $fatal = true;
    echo '<tr><td>' . $e($name) . "</td><td style='color:$color;font-weight:bold'>$st</td><td>" . ($ok ? '' : $e($how)) . '</td></tr>';
}
echo '</table>';
if ($fatal) exit('<p style="color:#c0392b"><b>Perbaiki yang WAJIB di atas, lalu buka setup.php lagi.</b></p></body></html>');

// ---------- database: buat bila belum ada (cPanel: database dibuat dulu di menu MySQL Databases) ----------
$c = $CFG['db'];
if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$c['name'])) exit('<p>Nama database di config tidak valid.</p>');
try {
    $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $ex) {
    try {
        $pdo = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$c['name']}`");
    } catch (PDOException $ex2) {
        error_log('GateMon setup: ' . $ex2->getMessage());
        exit('<p style="color:#c0392b"><b>Tidak bisa terhubung ke database.</b> Periksa host, nama database, user, dan password di config.local.php'
            . ' (cPanel: buat database + user di MySQL Databases, beri user hak ALL PRIVILEGES pada database itu).</p></body></html>');
    }
}
$pdo->exec("SET time_zone = '" . date('P') . "'");
require __DIR__ . '/schema.php';
$done = db_migrate($pdo);
echo '<p>Struktur database versi ' . SCHEMA_VERSION . ': ' . ($done ? $e(implode(', ', $done)) : 'sudah terbaru, tidak ada perubahan') . '.</p>';

// ---------- akun supervisor pertama (hanya bila belum ada akun sama sekali; tanpa password bawaan) ----------
$n = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
$keyField = $by_key ? '<input type="hidden" name="key" value="' . $e($given) . '">' : '';
if ($n === 0) {
    $err = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u)) $err = 'Username 3-50 karakter: huruf, angka, titik, minus, garis bawah.';
        elseif ($p !== (string)($_POST['password2'] ?? '')) $err = 'Ulangan password tidak sama.';
        elseif (($pp = password_problem($p, $u)) !== '') $err = $pp;
        else {
            $pdo->prepare("INSERT INTO admins (username,password_hash,role,created_at,pw_changed_at) VALUES (?,?,'supervisor',NOW(),NOW())")
                ->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
            $n = 1;
            echo '<p style="color:#0a7d4f"><b>Akun supervisor ' . $e($u) . ' dibuat.</b> Akun lain dibuat dari menu Admins.</p>';
        }
    }
    if ($n === 0) {
        echo '<h3>Buat akun supervisor pertama</h3>' . ($err ? '<p style="color:#c0392b">' . $e($err) . '</p>' : '')
           . '<form method="post">' . $keyField
           . '<p><label>Username<br><input name="username" required maxlength="50" autocomplete="username"></label></p>'
           . '<p><label>Password (min. 10 karakter, tidak memuat username / kata umum)<br><input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label></p>'
           . '<p><label>Ulangi password<br><input type="password" name="password2" required minlength="10" maxlength="200" autocomplete="new-password"></label></p>'
           . '<button>Buat akun</button></form></body></html>';
        exit;
    }
}
echo '<p>Setup selesai. ' . ($by_key ? '<b style="color:#c0392b">Kosongkan setup_key di config.local.php sekarang.</b>' : '<b>Hapus file setup.php</b> bila server ini bisa diakses dari luar.')
   . ' Lanjut ke <a href="admin/login.php">halaman login</a>.</p></body></html>';
