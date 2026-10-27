<?php
// Agent (>= 1.9) mengunduh wallpaper yang ditetapkan untuk laptopnya (menu Wallpaper).
// Hanya file milik laptop itu yang bisa diambil; agent mencocokkan sha256 sebelum memasang.
require __DIR__ . '/../db.php';
$d = auth_device();
$w = wallpaper_for($d);
$path = $w ? wallpaper_path($w) : '';
if (!$w || !is_file($path)) json_out(['ok' => false, 'error' => 'no wallpaper'], 404);

header('Content-Type: ' . ($w['ext'] === 'png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('X-Wallpaper-Sha256: ' . $w['sha256']);
header('Cache-Control: no-store');
readfile($path);
