<?php
// Paket "Pindah / cadangkan server": seluruh aplikasi + config + database + installer server tujuan.
// Berisi data sensitif (hash password, token laptop) -> hanya supervisor, POST + CSRF, tercatat di log.
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); exit('Hanya supervisor.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(400); exit('Permintaan tidak valid.'); }
@set_time_limit(600);
@ignore_user_abort(false);

$history = !empty($_POST['history']);
$root = realpath(__DIR__ . '/..');
$stamp = date('Ymd-His');
$top = "GateMon-Server-$stamp/";

// 1) salin database
$sql = tempnam(sys_get_temp_dir(), 'gmsql');
$counts = db_dump_sql(db(), $sql, $history);

// 2) ZIP: installer + seluruh folder aplikasi (tanpa file sementara/log/cadangan)
$tmp = tempnam(sys_get_temp_dir(), 'gmsrv');
$zip = zip_create($tmp);
$zip->addFile("$root/migrasi/INSTALL-SERVER.bat", $top . 'INSTALL-SERVER.bat');
$zip->addFile("$root/migrasi/BACA-SAYA.txt", $top . 'BACA-SAYA.txt');
$skip = '#^(\.git/|agent/browser-profile/|migrasi/(backup-.*|database)\.sql$)|\.log$#i';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$nfiles = 0;
foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $rel = strtr(substr($f->getPathname(), strlen($root) + 1), DIRECTORY_SEPARATOR, '/');
    if (preg_match($skip, $rel)) continue;
    $zip->addFile($f->getPathname(), $top . 'gatemon/' . $rel);
    $nfiles++;
}
$zip->addFile($sql, $top . 'gatemon/migrasi/database.sql');
$zip->close();
@unlink($sql);

log_event(null, 'warn', current_user()['username'] . " membuat paket pindah server ($nfiles file, {$counts['devices']} laptop" . ($history ? ', dengan riwayat' : ', tanpa riwayat') . ')');

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="GateMon-Server-' . $stamp . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
