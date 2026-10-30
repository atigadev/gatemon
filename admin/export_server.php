<?php
// Paket "Pindah / cadangkan server": seluruh aplikasi + database + petunjuk server tujuan.
// format=xampp : INSTALL-SERVER.bat + gatemon\ (config ikut) + gatemon\migrasi\database.sql
// format=cpanel: gatemon\ untuk diunggah ke public_html (tanpa config.local.php) + database.sql TERPISAH di luar folder
//                aplikasi (diimpor lewat phpMyAdmin, jangan diunggah ke folder web) + BACA-SAYA-CPANEL.txt
// Berisi data sensitif (hash password, token laptop) -> hanya supervisor, POST + CSRF + konfirmasi password, tercatat di log.
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); exit('Hanya supervisor.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Permintaan tidak valid.'); }
if (!confirm_password((string)($_POST['password'] ?? ''))) { $_SESSION['flash'] = 'Password tidak valid. Paket server tidak dibuat.'; header('Location: settings.php'); exit; }
@set_time_limit(600);
@ignore_user_abort(false);

$history = !empty($_POST['history']);
$cpanel = ($_POST['format'] ?? '') === 'cpanel';
$root = realpath(__DIR__ . '/..');
$stamp = date('Ymd-His');
$top = 'GateMon-' . ($cpanel ? 'cPanel' : 'Server') . "-$stamp/";

// 1) salin database
$sql = tempnam(sys_get_temp_dir(), 'gmsql');
$counts = db_dump_sql(db(), $sql, $history);

// 2) ZIP: petunjuk + seluruh folder aplikasi (tanpa file sementara/log/cadangan)
$tmp = tempnam(sys_get_temp_dir(), 'gmsrv');
$zip = zip_create($tmp);
if ($cpanel) {
    $zip->addFile("$root/migrasi/BACA-SAYA-CPANEL.txt", $top . 'BACA-SAYA-CPANEL.txt');
    $zip->addFile($sql, $top . 'database.sql');
} else {
    $zip->addFile("$root/migrasi/INSTALL-SERVER.bat", $top . 'INSTALL-SERVER.bat');
    $zip->addFile("$root/migrasi/BACA-SAYA.txt", $top . 'BACA-SAYA.txt');
}
// paket cPanel: tanpa config.local.php server ini (isinya untuk server lama) dan tanpa alat Windows server
$skip = '#^(\.git/|agent/browser-profile/|live/.+\.(jpg|tmp)$|migrasi/(backup-.*|database)\.sql$' . ($cpanel ? '|config\.local\.php$|migrasi/INSTALL-SERVER\.bat$|admin-tools/' : '') . ')|\.log$#i';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$nfiles = 0;
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = strtr(substr($f->getPathname(), strlen($root) + 1), DIRECTORY_SEPARATOR, '/');
    if (preg_match($skip, $rel)) continue;
    $zip->addFile($f->getPathname(), $top . 'gatemon/' . $rel);
    $nfiles++;
}
if (!$cpanel) $zip->addFile($sql, $top . 'gatemon/migrasi/database.sql');
$zip->close();
@unlink($sql);

log_event(null, 'warn', current_user()['username'] . ' membuat paket pindah server ' . ($cpanel ? 'cPanel' : 'XAMPP') . " dari " . client_ip()
    . " ($nfiles file, {$counts['devices']} laptop" . ($history ? ', dengan riwayat' : ', tanpa riwayat') . ')');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rtrim($top, '/') . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
@unlink($tmp);
