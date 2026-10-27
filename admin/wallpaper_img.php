<?php
// Pratinjau wallpaper untuk admin (folder wallpaper/ sendiri ditutup dari web). ?thumb=1 = gambar kecil bila ada.
require __DIR__ . '/auth.php';
require_login();
$st = db()->prepare("SELECT sha256, ext FROM wallpapers WHERE id=?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$w = $st->fetch();
if (!$w) { http_response_code(404); exit; }
$thumb = WALLPAPER_DIR . "/thumb-{$w['sha256']}.jpg";
$path = !empty($_GET['thumb']) && is_file($thumb) ? $thumb : wallpaper_path($w);
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . (substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=86400');   // isi per id tidak pernah berubah
readfile($path);
