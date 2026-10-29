<?php
// Chat support & tombol darurat dari laptop (agent >= 1.11).
// GET  ?since=ID[&read=1]          -> pesan baru percakapan laptop ini (read=1: jendela chat terbuka, pesan support ditandai dibaca)
// POST {kind, text, cid}           -> kirim pesan (kind chat) atau DARURAT (kind emergency); cid = id kiriman agent (kirim ulang aman)
require __DIR__ . '/../db.php';
$d = auth_device();
$pdo = db();
if (!setting('support_overlay')) json_out(['ok' => false, 'error' => 'chat support dinonaktifkan di menu Pengaturan'], 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $since = max(0, (int)($_GET['since'] ?? 0));
    $msgs = support_msgs($d['id'], $since);
    if (!empty($_GET['read'])) {
        $pdo->prepare("UPDATE support_msgs SET read_at=NOW() WHERE device_id=? AND sender<>'agent' AND read_at IS NULL")->execute([$d['id']]);
    }
    if (mt_rand(1, 500) === 1) $pdo->exec("DELETE FROM support_msgs WHERE created_at < NOW() - INTERVAL " . SUPPORT_KEEP_DAYS . " DAY");
    json_out(['ok' => true, 'msgs' => $msgs] + support_hb($d));
}

$b = json_in();
$kind = ($b['kind'] ?? '') === 'emergency' ? 'emergency' : 'chat';
$cid = (string)($b['cid'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{8,40}$/', $cid)) json_out(['ok' => false, 'error' => 'cid tidak valid'], 400);
$text = support_text($b['text'] ?? '');
$author = mb_substr((string)($b['user'] ?? ''), 0, 50);

// kiriman ulang (koneksi putus sebelum balasan diterima): kembalikan pesan yang sudah tersimpan
$st = $pdo->prepare("SELECT id FROM support_msgs WHERE device_id=? AND cid=?");
$st->execute([$d['id'], $cid]);
if ($id = $st->fetchColumn()) json_out(['ok' => true, 'id' => (int)$id, 'dup' => true]);

if ($kind === 'chat') {
    if ($text === '') json_out(['ok' => false, 'error' => 'pesan kosong'], 400);
    $st = $pdo->prepare("SELECT COUNT(*) FROM support_msgs WHERE device_id=? AND sender='agent' AND created_at > NOW() - INTERVAL 60 SECOND");
    $st->execute([$d['id']]);
    if ($st->fetchColumn() >= 15) json_out(['ok' => false, 'error' => 'terlalu banyak pesan, tunggu sebentar'], 429);
} else {
    // darurat yang masih menunggu dan baru saja dikirim: tidak dobel (tombol ditekan berulang)
    $st = $pdo->prepare("SELECT id FROM support_msgs WHERE device_id=? AND kind='emergency' AND ack_at IS NULL AND created_at > NOW() - INTERVAL 120 SECOND ORDER BY id DESC LIMIT 1");
    $st->execute([$d['id']]);
    if ($id = $st->fetchColumn()) json_out(['ok' => true, 'id' => (int)$id, 'dup' => true]);
    if ($text === '') $text = SUPPORT_EMERGENCY_TEXT;
}

$pdo->prepare("INSERT IGNORE INTO support_msgs (device_id, sender, author, kind, body, cid, created_at) VALUES (?, 'agent', ?, ?, ?, ?, NOW())")
    ->execute([$d['id'], $author, $kind, $text, $cid]);
$id = (int)$pdo->lastInsertId();
if (!$id) json_out(['ok' => true, 'dup' => true]);   // kiriman ulang yang datang bersamaan
if ($kind === 'emergency') {
    $where = trim(implode(' · ', array_filter([(string)$d['gate'], (string)$d['ip']])));
    log_event($d['id'], 'error', "{$d['name']}: tombol DARURAT ditekan" . ($where !== '' ? " ($where)" : ''));
}
json_out(['ok' => true, 'id' => $id]);
