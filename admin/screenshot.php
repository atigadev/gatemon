<?php
// Menampilkan screenshot hasil perintah SCREENSHOT (JPEG dari agent >= 1.5, PNG dari agent lama).
require __DIR__ . '/auth.php';
require_login();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT result FROM commands WHERE id=? AND type='SCREENSHOT' AND status='done'");
$st->execute([$id]);
$r = $st->fetchColumn();
if (!$r) { http_response_code(404); exit('Screenshot tidak ada atau sudah dihapus otomatis.'); }
$img = base64_decode($r);
header('Content-Type: ' . (strncmp($img, "\xFF\xD8", 2) === 0 ? 'image/jpeg' : 'image/png'));
header('Cache-Control: private, max-age=86400');
echo $img;
