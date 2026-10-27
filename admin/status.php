<?php
// Dipolling dashboard tiap 3 detik. Mengembalikan status semua laptop, log, dan perintah terakhir.
require __DIR__ . '/auth.php';
if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
$pdo = db();
$off = setting('offline_after');

$cats = $pdo->query("SELECT id, name, color FROM categories ORDER BY sort, name")->fetchAll();
$devices = $pdo->query("SELECT d.*, c.name AS category_name, c.color AS category_color FROM devices d
    LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.id IS NULL, c.sort, c.name, d.gate, d.name")->fetchAll();
$summary = ['total' => 0, 'ok' => 0, 'warn' => 0, 'offline' => 0];
// rekap scan hari ini per laptop (+ "tidak ditemukan" 1 menit terakhir untuk peringatan suara)
$scan = [];
foreach ($pdo->query("SELECT device_id, SUM(status='success') ok, SUM(status='belum') belum, SUM(status='notfound') nf,
        SUM(status='error') err, MAX(scanned_at) last, SUM(status='notfound' AND scanned_at >= NOW() - INTERVAL 60 SECOND) nf_1m
        FROM scans WHERE scanned_at >= CURDATE() GROUP BY device_id") as $r) {
    $scan[$r['device_id']] = ['ok' => (int)$r['ok'], 'belum' => (int)$r['belum'], 'nf' => (int)$r['nf'], 'err' => (int)$r['err'],
        'last' => $r['last'], 'nf_1m' => (int)$r['nf_1m']];
}
$scan_total = ['ok' => 0, 'belum' => 0, 'nf' => 0, 'err' => 0];
foreach ($devices as &$d) {
    // data scan hanya untuk "laptop scan" (URL halaman gate cocok pola di menu Pengaturan)
    $d['scan_enabled'] = scan_enabled($d);
    $d['scan'] = $d['scan_enabled'] ? ($scan[$d['id']] ?? null) : null;
    if ($d['scan']) foreach ($scan_total as $k => $v) $scan_total[$k] += $d['scan'][$k];
    $d['state'] = device_state($d, $off);
    $d['age'] = $d['last_seen'] ? time() - strtotime($d['last_seen']) : null;
    $d['camera_link'] = camera_link($d);
    $d['gate_urls'] = gate_urls_of($d);
    $d['wol_info'] = wol_summary($d);                         // kesiapan Wake-on-LAN
    $d['nics'] = json_decode((string)$d['nics'], true) ?: [];
    unset($d['wol']);
    unset($d['token'], $d['enroll_hash']);
    $summary['total']++;
    $summary[$d['state']]++;
}
unset($d);

$logs = $pdo->query("SELECT l.*, d.name AS device_name FROM logs l LEFT JOIN devices d ON d.id=l.device_id ORDER BY l.id DESC LIMIT 40")->fetchAll();
$cmds = $pdo->query("SELECT c.id, c.device_id, c.type, c.status, c.created_by, c.created_at, c.done_at, d.name AS device_name,
    CASE WHEN c.type='SCREENSHOT' THEN NULL ELSE LEFT(c.result,200) END AS result, (c.type='SCREENSHOT' AND c.result IS NOT NULL) AS has_shot
    FROM commands c JOIN devices d ON d.id=c.device_id ORDER BY c.id DESC LIMIT 25")->fetchAll();

json_out(['ok' => true, 'now' => date('H:i:s'), 'offline_after' => $off, 'refresh' => setting('dashboard_refresh'), 'role' => current_user()['role'],
    'summary' => $summary, 'scan_total' => $scan_total, 'scan_fail_alert' => setting('scan_fail_alert'), 'categories' => $cats, 'devices' => $devices, 'logs' => $logs, 'commands' => $cmds]);
