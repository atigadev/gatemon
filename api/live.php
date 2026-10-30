<?php
// Layar live (agent >= 1.14): agent mengirim gambar layar (JPEG mentah di body) selama ada penonton.
// Body kosong = gambar sama seperti sebelumnya (hanya tanda masih berjalan). Header X-Live-Err = layar tidak bisa diambil.
// Jawaban: {on: 0|1, w, q, fps, by} -> on 0 = berhenti (penonton sudah pergi).
require __DIR__ . '/../db.php';
$d = auth_device();
$pdo = db();
$on = live_hb($d);
$path = live_path($d['id']);
if (!$on['on']) {
    if (is_file($path)) @unlink($path);
    json_out($on);
}
$err = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)($_SERVER['HTTP_X_LIVE_ERR'] ?? '')), 0, 200);
$body = file_get_contents('php://input', false, null, 0, LIVE_MAX_BYTES + 1);
if ($err !== '') {
    $pdo->prepare("UPDATE devices SET live_err=?, live_at=NOW() WHERE id=?")->execute([$err, $d['id']]);
} elseif ($body !== '' && $body !== false) {
    if (strlen($body) > LIVE_MAX_BYTES) json_out(['ok' => false, 'error' => 'gambar terlalu besar'] + $on, 413);
    $info = @getimagesizefromstring($body);
    if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] > 4000 || $info[1] > 4000) json_out(['ok' => false, 'error' => 'bukan gambar JPEG'] + $on, 400);
    // tulis ke file sementara lalu ganti nama: penonton tidak pernah membaca gambar setengah jadi
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, $body) === false) json_out(['ok' => false, 'error' => 'gagal menyimpan'] + $on, 500);
    if (!@rename($tmp, $path)) { @file_put_contents($path, $body); @unlink($tmp); }
    $pdo->prepare("UPDATE devices SET live_seq=live_seq+1, live_at=NOW(), live_err=NULL, live_w=?, live_h=? WHERE id=?")->execute([$info[0], $info[1], $d['id']]);
} else {
    $pdo->prepare("UPDATE devices SET live_at=NOW(), live_err=NULL WHERE id=?")->execute([$d['id']]);
}
json_out(['ok' => true] + $on);
