<?php
// Status satu perintah (untuk layar progress screenshot di dashboard). Hanya status, bukan isi gambar.
require __DIR__ . '/auth.php';
require_operator_json();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT c.id, c.type, c.status, c.created_at, c.sent_at, c.done_at, d.name AS device_name,
    CASE WHEN c.type='SCREENSHOT' AND c.status<>'failed' THEN NULL ELSE LEFT(c.result,200) END AS result,
    (c.type='SCREENSHOT' AND c.result IS NOT NULL AND c.status='done') AS has_shot
    FROM commands c JOIN devices d ON d.id=c.device_id WHERE c.id=?");
$st->execute([$id]);
$c = $st->fetch();
if (!$c) json_out(['ok' => false, 'error' => 'perintah tidak ditemukan'], 404);
$c['has_shot'] = (bool)$c['has_shot'];
json_out(['ok' => true, 'cmd' => $c]);
