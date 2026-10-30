<?php
// Layar live untuk halaman admin/live.php (hanya supervisor).
// POST act=start (dev, mode) | act=keep (dev, mode) | act=stop (dev)   -> CSRF; sesi berlaku 30 dtk, diperpanjang halaman penonton
// GET  act=frame&dev=ID&since=SEQ  -> gambar terbaru (image/jpeg, header X-Live-Seq) atau 204 bila belum ada yang baru
// Setiap sesi tercatat di log: siapa, laptop mana, berapa lama. Gambar tidak disimpan permanen.
require __DIR__ . '/auth.php';
if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
if (!is_supervisor()) json_out(['ok' => false, 'error' => 'layar live hanya untuk supervisor'], 403);
$pdo = db();
$act = $_REQUEST['act'] ?? '';
$dev = (int)($_REQUEST['dev'] ?? 0);

function live_dev($id) {
    $st = db()->prepare("SELECT id, name, device_key, agent_version, last_seen, live_until, live_started, live_by, live_mode, live_seq, live_at, live_err, live_w, live_h FROM devices WHERE id=?");
    $st->execute([$id]);
    $d = $st->fetch();
    if (!$d) json_out(['ok' => false, 'error' => 'laptop tidak ditemukan'], 404);
    return $d;
}
function live_status($d) {
    $now = time();
    return ['ok' => true, 'online' => $d['last_seen'] && $now - strtotime($d['last_seen']) <= setting('offline_after'),
        'active' => $d['live_until'] && strtotime($d['live_until']) > $now, 'seq' => (int)$d['live_seq'],
        'frame_age' => $d['live_at'] ? $now - strtotime($d['live_at']) : null, 'err' => (string)$d['live_err'],
        'w' => (int)$d['live_w'], 'h' => (int)$d['live_h'], 'by' => (string)$d['live_by'], 'mode' => (string)$d['live_mode'],
        'left' => $d['live_started'] ? max(0, LIVE_MAX_MINUTES * 60 - ($now - strtotime($d['live_started']))) : null,
        'heartbeat' => setting('heartbeat_interval')];
}

if ($act === 'frame') {
    session_write_close();
    $st = $pdo->prepare("SELECT live_seq, live_until FROM devices WHERE id=?");
    $st->execute([$dev]);
    $r = $st->fetch();
    $path = live_path($dev);
    header('Cache-Control: no-store');
    if (!$r || !$r['live_until'] || strtotime($r['live_until']) <= time() || (int)$r['live_seq'] <= (int)($_GET['since'] ?? 0) || !is_file($path)) {
        header('X-Live-Seq: ' . (int)($r['live_seq'] ?? 0));
        http_response_code(204);
        exit;
    }
    header('Content-Type: image/jpeg');
    header('X-Live-Seq: ' . (int)$r['live_seq']);
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
check_csrf();
$d = live_dev($dev);
$who = current_user()['username'];
$mode = isset(LIVE_MODES[$_POST['mode'] ?? '']) ? $_POST['mode'] : 'normal';

if ($act === 'start' || $act === 'keep') {
    if (!$d['agent_version'] || version_compare($d['agent_version'], LIVE_MIN, '<')) {
        json_out(['ok' => false, 'error' => 'perlu agent ' . LIVE_MIN . '+ (laptop ini ' . ($d['agent_version'] ?: '?') . '); update lewat menu Agent'], 400);
    }
    $active = $d['live_until'] && strtotime($d['live_until']) > time();
    if ($act === 'keep' && $active && $d['live_started'] && time() - strtotime($d['live_started']) > LIVE_MAX_MINUTES * 60) {
        $pdo->prepare("UPDATE devices SET live_until=NULL WHERE id=?")->execute([$d['id']]);
        log_event($d['id'], 'info', "Layar live {$d['name']} berhenti otomatis setelah " . LIVE_MAX_MINUTES . " menit");
        json_out(['ok' => false, 'expired' => true, 'error' => 'sesi layar live ' . LIVE_MAX_MINUTES . ' menit selesai']);
    }
    // sesi baru: urutan gambar dan gambar lama direset, sehingga penonton tidak melihat layar dari sesi sebelumnya
    if (!$active) {
        @unlink(live_path($d['id']));
        $pdo->prepare("UPDATE devices SET live_started=NOW(), live_seq=0, live_at=NULL, live_err=NULL, live_w=NULL, live_h=NULL WHERE id=?")->execute([$d['id']]);
        log_event($d['id'], 'warn', "$who mulai melihat layar live {$d['name']}");
    }
    $pdo->prepare("UPDATE devices SET live_until=NOW() + INTERVAL 30 SECOND, live_by=?, live_mode=? WHERE id=?")->execute([$who, $mode, $d['id']]);
    json_out(live_status(live_dev($d['id'])));
}
if ($act === 'stop') {
    if ($d['live_until'] && strtotime($d['live_until']) > time()) {
        $pdo->prepare("UPDATE devices SET live_until=NULL WHERE id=?")->execute([$d['id']]);
        $min = $d['live_started'] ? max(1, (int)round((time() - strtotime($d['live_started'])) / 60)) : 0;
        log_event($d['id'], 'info', "$who selesai melihat layar live {$d['name']} (±$min menit)");
    }
    @unlink(live_path($d['id']));
    json_out(['ok' => true]);
}
json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
