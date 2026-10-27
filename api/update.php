<?php
// Agent (>= 1.9) mengunduh rilis update: ?f=manifest | sig | <file di manifest>, ?w=<wheel di manifest>.
// Server hanya menyajikan; keaslian diperiksa agent lewat tanda tangan Ed25519 (kunci rahasia tidak ada di server).
require __DIR__ . '/../db.php';
auth_device();
$dir = __DIR__ . '/../agent';
$m = json_decode((string)@file_get_contents("$dir/release.json"), true);
if (!$m || !is_file("$dir/release.sig")) json_out(['ok' => false, 'error' => 'belum ada rilis bertanda tangan'], 404);

$f = (string)($_GET['f'] ?? '');
$w = (string)($_GET['w'] ?? '');
if ($f === 'manifest') $path = "$dir/release.json";
elseif ($f === 'sig') $path = "$dir/release.sig";
elseif ($f !== '' && in_array($f, array_column($m['files'] ?? [], 'path'), true)) $path = "$dir/$f";
elseif ($w !== '' && in_array($w, array_column($m['wheels'] ?? [], 'file'), true)) $path = "$dir/installer/wheels/$w";
else json_out(['ok' => false, 'error' => 'file tidak ada di rilis'], 404);
if (!is_file($path)) json_out(['ok' => false, 'error' => 'file rilis hilang di server'], 404);

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
