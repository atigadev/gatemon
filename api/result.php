<?php
// Agent melaporkan hasil eksekusi perintah.
require __DIR__ . '/../db.php';
$d = auth_device();
$b = json_in();
$id = (int)($b['command_id'] ?? 0);
$status = ($b['status'] ?? 'done') === 'failed' ? 'failed' : 'done';
$result = (string)($b['result'] ?? '');
if ($id <= 0) json_out(['ok' => false, 'error' => 'command_id required'], 400);

$st = db()->prepare("SELECT type, payload FROM commands WHERE id=? AND device_id=?");
$st->execute([$id, $d['id']]);
$c = $st->fetch();
if (!$c) json_out(['ok' => false, 'error' => 'command not found'], 404);

db()->prepare("UPDATE commands SET status=?, result=?, done_at=NOW() WHERE id=?")->execute([$status, $result, $id]);
// screenshot lama dihapus (menu Pengaturan: simpan screenshot N hari) agar database tidak terus membesar
if ($c['type'] === 'SCREENSHOT') {
    db()->prepare("UPDATE commands SET result=NULL WHERE type='SCREENSHOT' AND result IS NOT NULL AND created_at < NOW() - INTERVAL ? DAY")
        ->execute([setting('screenshot_keep_days')]);
}
$short = $c['type'] === 'SCREENSHOT' ? '(screenshot diterima)' : mb_substr($result, 0, 120);
log_event($d['id'], $status === 'done' ? 'info' : 'error', "{$d['name']}: {$c['type']} {$status} $short");
// uninstall dimulai di laptop dan supervisor memilih "hapus dari daftar": laptop dihapus seperti tombol Hapus di menu Devices
if ($c['type'] === 'UNINSTALL' && $status === 'done' && $c['payload'] === 'remove') {
    db()->prepare("DELETE FROM commands WHERE device_id=?")->execute([$d['id']]);
    db()->prepare("DELETE FROM devices WHERE id=?")->execute([$d['id']]);
    @unlink(live_path($d['id']));
    log_event(null, 'warn', "{$d['name']} ({$d['device_key']}) dihapus dari daftar setelah uninstall agent");
}
json_out(['ok' => true]);
