<?php
// Riwayat scan satu laptop (panel detail): status, kategori, gate, ID QR/barcode, waktu. Tanpa data pribadi.
require __DIR__ . '/auth.php';
if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
$id = (int)($_GET['device'] ?? 0);
$limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
$st = db()->prepare("SELECT scanned_at, status, category, gate, code FROM scans WHERE device_id=? ORDER BY scanned_at DESC, id DESC LIMIT $limit");
$st->execute([$id]);
json_out(['ok' => true, 'scans' => $st->fetchAll()]);
