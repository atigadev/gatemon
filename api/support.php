<?php
// Chat support & tombol darurat dari laptop (agent >= 1.11).
// GET  ?since=ID[&read=1]          -> pesan baru percakapan laptop ini (read=1: jendela chat terbuka, pesan support ditandai dibaca)
// POST {kind, text, cid}           -> kirim pesan (kind chat), DARURAT (emergency), minta izin shutdown (shutdown, agent >= 1.19)
//                                     atau batalkan permintaan itu (shutdown_cancel); cid = id kiriman agent (kirim ulang aman)
require __DIR__ . '/../db.php';
$d = auth_device();
$pdo = db();
if (!support_on($d)) json_out(['ok' => false, 'error' => 'chat support dinonaktifkan untuk laptop ini'], 403);

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
$kind = in_array($b['kind'] ?? '', ['emergency', 'shutdown', 'shutdown_cancel'], true) ? $b['kind'] : 'chat';
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
} elseif ($kind === 'shutdown_cancel') {
    // petugas di laptop membatalkan permintaan shutdown yang belum diputuskan
    $st = $pdo->prepare("UPDATE support_msgs SET ack_at=NOW(), ack_by=?, decision='cancelled' WHERE device_id=? AND kind='shutdown' AND ack_at IS NULL");
    $st->execute([$author !== '' ? $author : 'laptop', $d['id']]);
    if ($st->rowCount()) {
        $pdo->prepare("INSERT INTO support_msgs (device_id, sender, author, kind, body, created_at) VALUES (?, 'system', '', 'chat', ?, NOW())")
            ->execute([$d['id'], 'Permintaan shutdown dibatalkan dari laptop.']);
        log_event($d['id'], 'info', "{$d['name']}: permintaan shutdown dibatalkan dari laptop");
    }
    json_out(['ok' => true, 'n' => $st->rowCount()]);
} elseif ($kind === 'shutdown') {
    // permintaan shutdown yang masih menunggu: tidak dobel
    $st = $pdo->prepare("SELECT id FROM support_msgs WHERE device_id=? AND kind='shutdown' AND ack_at IS NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$d['id']]);
    if ($id = $st->fetchColumn()) json_out(['ok' => true, 'id' => (int)$id, 'dup' => true]);
    $text = SUPPORT_SHUTDOWN_TEXT . ($text !== '' ? ": $text" : '');
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
} elseif ($kind === 'shutdown') {
    log_event($d['id'], 'warn', "{$d['name']}: minta izin shutdown" . ($author !== '' ? " (user $author)" : '') . ", menunggu persetujuan petugas");
}
json_out(['ok' => true, 'id' => $id]);
