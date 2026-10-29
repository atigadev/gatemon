<?php
// Chat support & darurat untuk halaman admin (menu Support + alarm di semua halaman).
// GET  act=alert                 -> darurat yang belum ditangani + jumlah pesan belum dibaca (dipolling semua halaman admin)
// GET  act=convs                 -> daftar percakapan per laptop
// GET  act=thread&dev=ID&since=  -> pesan baru satu laptop (pesan dari laptop ditandai dibaca)
// POST act=send (dev, text) / act=ack (dev)   -> supervisor + CSRF
require __DIR__ . '/auth.php';
if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
$pdo = db();
$act = $_REQUEST['act'] ?? '';

function sup_device($id) {
    $st = db()->prepare("SELECT d.id, d.name, d.device_key, d.gate, d.ip, d.mode, d.last_seen, d.agent_version, c.name AS category_name, c.color AS category_color
        FROM devices d LEFT JOIN categories c ON c.id=d.category_id WHERE d.id=?");
    $st->execute([(int)$id]);
    $d = $st->fetch();
    if (!$d) json_out(['ok' => false, 'error' => 'laptop tidak ditemukan'], 404);
    $d['id'] = (int)$d['id'];
    $d['state'] = $d['last_seen'] && time() - strtotime($d['last_seen']) <= setting('offline_after') ? 'online' : 'offline';
    $d['supported'] = $d['agent_version'] !== null && version_compare($d['agent_version'], SUPPORT_MIN, '>=');
    return $d;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_supervisor_json();
    check_csrf();
    $d = sup_device($_POST['dev'] ?? 0);
    $who = current_user()['username'];
    if ($act === 'send') {
        $text = support_text($_POST['text'] ?? '');
        if ($text === '') json_out(['ok' => false, 'error' => 'pesan kosong'], 400);
        $pdo->prepare("INSERT INTO support_msgs (device_id, sender, author, kind, body, created_at) VALUES (?, 'admin', ?, 'chat', ?, NOW())")
            ->execute([$d['id'], $who, $text]);
        json_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
    if ($act === 'ack') {
        $st = $pdo->prepare("UPDATE support_msgs SET ack_at=NOW(), ack_by=? WHERE device_id=? AND kind='emergency' AND ack_at IS NULL");
        $st->execute([$who, $d['id']]);
        if ($st->rowCount()) {
            $pdo->prepare("INSERT INTO support_msgs (device_id, sender, author, kind, body, created_at) VALUES (?, 'system', ?, 'chat', ?, NOW())")
                ->execute([$d['id'], $who, "Darurat diterima oleh $who. Tim support segera menuju lokasi."]);
            log_event($d['id'], 'info', "{$d['name']}: darurat ditangani oleh $who");
        }
        json_out(['ok' => true, 'n' => $st->rowCount()]);
    }
    json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
}

session_write_close();   // polling: jangan menahan sesi (halaman lain tetap cepat)

if ($act === 'alert') {
    $emg = $pdo->query("SELECT m.id, m.device_id, m.body, m.created_at, d.name, d.gate, c.name AS category_name
        FROM support_msgs m JOIN devices d ON d.id=m.device_id LEFT JOIN categories c ON c.id=d.category_id
        WHERE m.kind='emergency' AND m.ack_at IS NULL ORDER BY m.id")->fetchAll();
    $unread = (int)$pdo->query("SELECT COUNT(*) FROM support_msgs WHERE sender='agent' AND read_at IS NULL")->fetchColumn();
    $last = $pdo->query("SELECT m.id, m.device_id, m.body, d.name FROM support_msgs m JOIN devices d ON d.id=m.device_id
        WHERE m.sender='agent' ORDER BY m.id DESC LIMIT 1")->fetch() ?: null;
    json_out(['ok' => true, 'role' => current_user()['role'], 'unread' => $unread, 'emergencies' => $emg, 'last' => $last]);
}

if ($act === 'convs') {
    // laptop dengan pesan 7 hari terakhir atau darurat yang belum ditangani
    $convs = $pdo->query("SELECT m.device_id, d.name, d.gate, d.last_seen, c.name AS category_name, c.color AS category_color,
            MAX(m.id) last_id, MAX(m.created_at) last_at, SUM(m.sender='agent' AND m.read_at IS NULL) unread,
            SUM(m.kind='emergency' AND m.ack_at IS NULL) emg
        FROM support_msgs m JOIN devices d ON d.id=m.device_id LEFT JOIN categories c ON c.id=d.category_id
        WHERE m.created_at >= NOW() - INTERVAL 7 DAY OR (m.kind='emergency' AND m.ack_at IS NULL)
        GROUP BY m.device_id ORDER BY SUM(m.kind='emergency' AND m.ack_at IS NULL) > 0 DESC, MAX(m.id) DESC")->fetchAll();
    $off = setting('offline_after');
    $lastmsg = [];
    if ($convs) {
        $ids = array_column($convs, 'last_id');
        $st = $pdo->prepare("SELECT id, sender, body FROM support_msgs WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
        $st->execute($ids);
        foreach ($st as $r) $lastmsg[$r['id']] = $r;
    }
    foreach ($convs as &$c) {
        $m = $lastmsg[$c['last_id']];
        $c['last_body'] = mb_substr($m['body'], 0, 80);
        $c['last_sender'] = $m['sender'];
        $c['state'] = $c['last_seen'] && time() - strtotime($c['last_seen']) <= $off ? 'online' : 'offline';
        foreach (['device_id', 'last_id', 'unread', 'emg'] as $k) $c[$k] = (int)$c[$k];
        unset($c['last_seen']);
    }
    unset($c);
    $devs = $pdo->query("SELECT d.id, d.name, d.gate, c.name AS category_name FROM devices d LEFT JOIN categories c ON c.id=d.category_id
        ORDER BY c.id IS NULL, c.sort, c.name, d.gate, d.name")->fetchAll();
    json_out(['ok' => true, 'convs' => $convs, 'devices' => $devs]);
}

if ($act === 'thread') {
    $d = sup_device($_GET['dev'] ?? 0);
    $msgs = support_msgs($d['id'], max(0, (int)($_GET['since'] ?? 0)));
    $pdo->prepare("UPDATE support_msgs SET read_at=NOW() WHERE device_id=? AND sender='agent' AND read_at IS NULL")->execute([$d['id']]);
    // tanda "dibaca" pesan support yang sudah terkirim (berubah setelah dikirim, jadi dikirim ulang terpisah)
    $st = $pdo->prepare("SELECT id FROM support_msgs WHERE device_id=? AND sender<>'agent' AND read_at IS NOT NULL ORDER BY id DESC LIMIT 1");
    $st->execute([$d['id']]);
    $seen = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT MIN(created_at) FROM support_msgs WHERE device_id=? AND kind='emergency' AND ack_at IS NULL");
    $st->execute([$d['id']]);
    unset($d['last_seen']);
    json_out(['ok' => true, 'device' => $d, 'msgs' => $msgs, 'seen_upto' => $seen, 'emg_open' => $st->fetchColumn() ?: null]);
}
json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
