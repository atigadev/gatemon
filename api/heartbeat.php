<?php
// Dipanggil agent tiap beberapa detik. Menerima status laptop, mengembalikan perintah yang menunggu.
require __DIR__ . '/../db.php';
$d = auth_device();
$b = json_in();
$pdo = db();

$age = $d['last_seen'] ? (time() - strtotime($d['last_seen'])) : PHP_INT_MAX;
$was_online = $age <= setting('offline_after');

$browser = !empty($b['browser_running']) ? 1 : 0;
$gate_ok = !empty($b['gate_url_ok']) ? 1 : 0;
$radmin = !empty($b['radmin_ok']) ? 1 : 0;

$is_gate = $d['mode'] !== 'monitor';

if (!$was_online) log_event($d['id'], 'info', "{$d['name']} online");
// Satu ID laptop dipakai lebih dari satu komputer (agent hasil salin / config.json sama): heartbeat datang bergantian
// dari hostname berbeda, sehingga laptop yang sudah mati tetap tampil online. Dicatat paling sering 10 menit sekali.
$host_in = substr($b['hostname'] ?? '', 0, 100);
if ($was_online && $host_in !== '' && (string)$d['hostname'] !== '' && $host_in !== $d['hostname']) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE device_id=? AND message LIKE '%dipakai lebih dari satu komputer%' AND created_at > NOW() - INTERVAL 10 MINUTE");
    $st->execute([$d['id']]);
    if (!$st->fetchColumn()) log_event($d['id'], 'warn', "{$d['name']}: ID {$d['device_key']} dipakai lebih dari satu komputer ({$d['hostname']} dan $host_in, IP " . ($_SERVER['REMOTE_ADDR'] ?? '?') . "). Laptop yang mati bisa tetap tampil online.");
}
if ($is_gate && $was_online) {
    if ($d['browser_running'] && !$browser) log_event($d['id'], 'warn', "{$d['name']}: browser gate tertutup");
    if (!$d['browser_running'] && $browser) log_event($d['id'], 'info', "{$d['name']}: browser gate aktif");
    if ($d['gate_url_ok'] && !$gate_ok) log_event($d['id'], 'warn', "{$d['name']}: halaman gate tidak bisa diakses");
    if (!$d['gate_url_ok'] && $gate_ok) log_event($d['id'], 'info', "{$d['name']}: halaman gate bisa diakses kembali");
}

// IP LAN dilaporkan agent (agent >= 1.2); server di hosting hanya melihat IP publik router venue.
// Agent lama tidak mengirim lan_ip -> pakai alamat pengirim seperti sebelumnya.
$public_ip = $_SERVER['REMOTE_ADDR'] ?? null;
$lan_ip = filter_var($b['lan_ip'] ?? '', FILTER_VALIDATE_IP) ?: $public_ip;

$pdo->prepare("UPDATE devices SET ip=?, public_ip=?, hostname=?, os_user=?, cpu=?, ram=?, disk=?, battery=?, battery_plugged=?, uptime_sec=?,
    browser_running=?, gate_url_ok=?, gate_url_ms=?, net_ms=?, radmin_ok=?, agent_version=?, last_seen=NOW() WHERE id=?")
    ->execute([
        $lan_ip,
        $public_ip,
        substr($b['hostname'] ?? '', 0, 100),
        substr($b['os_user'] ?? '', 0, 100),
        isset($b['cpu']) ? (float)$b['cpu'] : null,
        isset($b['ram']) ? (float)$b['ram'] : null,
        isset($b['disk']) ? (float)$b['disk'] : null,
        isset($b['battery']) ? (int)$b['battery'] : null,
        isset($b['battery_plugged']) ? (int)(bool)$b['battery_plugged'] : null,
        isset($b['uptime_sec']) ? (int)$b['uptime_sec'] : null,
        $browser, $gate_ok,
        isset($b['gate_url_ms']) ? (int)$b['gate_url_ms'] : null,
        isset($b['net_ms']) ? (int)$b['net_ms'] : null,
        $radmin,
        substr($b['agent_version'] ?? '', 0, 20),
        $d['id'],
    ]);

// Kartu jaringan & kesiapan Wake-on-LAN (agent >= 1.7, dikirim sesekali). Divalidasi ketat: MAC yang
// tersimpan di sini adalah satu-satunya sumber tujuan WoL (bukan isian pengguna).
if (isset($b['nics']) && is_array($b['nics'])) {
    $nics = [];
    foreach (array_slice($b['nics'], 0, 8) as $n) {
        if (!is_array($n) || !($mac = norm_mac($n['mac'] ?? ''))) continue;
        $nics[] = ['name' => mb_substr(preg_replace('/[^\w .()#\-*]/u', '', (string)($n['name'] ?? '')), 0, 60),
            'type' => in_array($n['type'] ?? '', ['ethernet', 'wifi'], true) ? $n['type'] : 'other',
            'mac' => $mac, 'up' => !empty($n['up'])];
    }
    $wol = null;
    if (isset($b['wol']) && is_array($b['wol'])) {
        $magic = [];
        foreach (array_slice((array)($b['wol']['magic'] ?? []), 0, 8, true) as $k => $v) {
            $magic[mb_substr(preg_replace('/[^\w .()#\-*]/u', '', (string)$k), 0, 60)] = in_array($v, ['Enabled', 'Disabled', 'Unsupported'], true) ? $v : 'Unsupported';
        }
        $wol = json_encode(['fast_startup' => !empty($b['wol']['fast_startup']), 'magic' => $magic]);
        if (strlen($wol) > 500) $wol = null;
    }
    $pdo->prepare("UPDATE devices SET nics=?, wol=COALESCE(?, wol) WHERE id=?")->execute([json_encode($nics), $wol, $d['id']]);
}

// Wallpaper yang sedang terpasang (agent >= 1.9): sha256 dari server, "" bila belum/tidak ada; ditulis hanya bila berubah
if (isset($b['wallpaper'])) {
    $wsha = preg_match('/^[0-9a-f]{64}$/', (string)$b['wallpaper']) ? $b['wallpaper'] : '';
    $werr = mb_substr((string)($b['wallpaper_err'] ?? ''), 0, 200);
    if ($d['wallpaper_sha'] === null || $wsha !== $d['wallpaper_sha'] || $werr !== (string)$d['wallpaper_err']) {
        $pdo->prepare("UPDATE devices SET wallpaper_sha=?, wallpaper_err=? WHERE id=?")->execute([$wsha, $werr, $d['id']]);
        if ($werr !== '' && $werr !== (string)$d['wallpaper_err']) log_event($d['id'], 'warn', "{$d['name']}: wallpaper gagal dipasang ($werr)");
    }
}

// Kebijakan daya & Windows Update yang sedang berlaku di laptop (agent >= 1.10); ditulis hanya bila berubah
if (isset($b['power_state']) || isset($b['winupdate_state']) || isset($b['policy_err'])) {
    $ps = substr((string)($b['power_state'] ?? ''), 0, 20);
    $ws = substr((string)($b['winupdate_state'] ?? ''), 0, 10);
    $pe = mb_substr((string)($b['policy_err'] ?? ''), 0, 200);
    if ($ps !== (string)$d['power_state'] || $ws !== (string)$d['winupdate_state'] || $pe !== (string)$d['policy_err']) {
        $pdo->prepare("UPDATE devices SET power_state=?, winupdate_state=?, policy_err=? WHERE id=?")->execute([$ps, $ws, $pe, $d['id']]);
        if ($pe !== '' && $pe !== (string)$d['policy_err']) log_event($d['id'], 'warn', "{$d['name']}: kebijakan daya/Windows Update gagal ($pe)");
    }
}

// Hasil update agent (>= 1.9) setelah restart: "ok:LAMA>BARU" atau "fail:BARU:alasan" (penjaga mengembalikan versi lama).
// Dicatat di log dan pada perintah UPDATE_AGENT terakhir laptop ini (status di menu Agent).
if (isset($b['update_result']) && preg_match('/^(ok|fail):(.{0,240})$/s', (string)$b['update_result'], $um)) {
    $ok = $um[1] === 'ok';
    $txt = $ok ? 'update berhasil: ' . str_replace('>', ' -> ', $um[2]) : 'update gagal, kembali ke versi lama: ' . preg_replace('/^[^:]*:/', '', $um[2]);
    log_event($d['id'], $ok ? 'info' : 'error', "{$d['name']}: $txt");
    $pdo->prepare("UPDATE commands SET status=?, result=?, done_at=NOW() WHERE device_id=? AND type='UPDATE_AGENT' ORDER BY id DESC LIMIT 1")
        ->execute([$ok ? 'done' : 'failed', mb_substr($txt, 0, 300), $d['id']]);
}

// Hasil scan dari halaman gate (agent >= 1.6), dikirim berkelompok bersama heartbeat.
// Semua sid yang diterima dikembalikan (scans_ack) agar agent menghapusnya dari antrean; INSERT IGNORE
// mencegah data dobel bila kiriman diulang setelah koneksi putus.
// Hanya "laptop scan" (mode gate + URL halaman gate cocok pola di menu Pengaturan) yang datanya disimpan;
// scan dari laptop lain tetap di-ack (dibuang) agar tidak dikirim ulang terus.
$scan_on = scan_enabled($d);
$ack = [];
if (!empty($b['scans']) && is_array($b['scans'])) {
    $ins = $pdo->prepare("INSERT IGNORE INTO scans (device_id, sid, gate, status, category, code, scanned_at) VALUES (?,?,?,?,?,?,?)");
    foreach (array_slice($b['scans'], 0, 500) as $s) {
        if (!is_array($s) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)($s['sid'] ?? ''))) continue;
        $ack[] = $s['sid'];
        if (!$scan_on) continue;
        $status = in_array($s['status'] ?? '', ['success', 'belum', 'notfound', 'error'], true) ? $s['status'] : 'error';
        $t = (int)($s['t'] ?? 0);
        if ($t < time() - 86400 * 30 || $t > time() + 300) $t = time();   // jam laptop ngawur: pakai waktu server
        $ins->execute([$d['id'], $s['sid'], mb_substr((string)($s['gate'] ?? ''), 0, 50), $status,
            mb_substr((string)($s['category'] ?? ''), 0, 100), mb_substr((string)($s['code'] ?? ''), 0, 100), date('Y-m-d H:i:s', $t)]);
    }
    if (mt_rand(1, 300) === 1) {   // sesekali: hapus data scan lama (menu Pengaturan)
        $pdo->prepare("DELETE FROM scans WHERE scanned_at < NOW() - INTERVAL ? DAY")->execute([setting('scan_keep_days')]);
    }
}

// Tombol Chat Support / DARURAT gagal tampil di laptop (agent >= 1.11); dicatat paling sering 1 jam sekali
if (!empty($b['support_err'])) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE device_id=? AND message LIKE '%tombol support gagal%' AND created_at > NOW() - INTERVAL 1 HOUR");
    $st->execute([$d['id']]);
    if (!$st->fetchColumn()) log_event($d['id'], 'warn', "{$d['name']}: tombol support gagal tampil (" . mb_substr((string)$b['support_err'], 0, 200) . ")");
}

// Status tombol support di layar laptop (agent >= 1.12): shown / off / starting / error:...; ditulis hanya bila berubah
if (isset($b['support_ui'])) {
    $ss = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)$b['support_ui']), 0, 220);
    if ($ss !== (string)$d['support_state']) $pdo->prepare("UPDATE devices SET support_state=? WHERE id=?")->execute([$ss, $d['id']]);
}

// Ambil perintah yang menunggu dan tandai sebagai terkirim
$st = $pdo->prepare("SELECT id, type, payload FROM commands WHERE device_id=? AND status='pending' ORDER BY id");
$st->execute([$d['id']]);
$cmds = $st->fetchAll();
if ($cmds) {
    $ids = array_column($cmds, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE commands SET status='sent', sent_at=NOW() WHERE id IN ($in)")->execute($ids);
}

$gate_urls = gate_urls_of($d);                      // >1 URL = dibuka berdampingan (agent >= 1.8)
$gate_url = $gate_urls ? $gate_urls[0] : '';
// Wallpaper (agent >= 1.9): hanya sha + gaya; file diunduh agent lewat api/wallpaper.php saat sha berubah
$w = wallpaper_for($d);
$wc = wallpaper_cfg();
$wallpaper = $w ? ['sha' => $w['sha256'], 'ext' => $w['ext'], 'bytes' => (int)$w['bytes'], 'style' => $wc['style'], 'lock' => $wc['lock']] : null;
json_out(['ok' => true, 'wallpaper' => $wallpaper, 'policy' => policy_cfg(), 'support' => support_hb($d), 'server_time' => date('c'), 'interval' => setting('heartbeat_interval'), 'mode' => $d['mode'],
    'auto_open' => setting('auto_open_gate'), 'auto_reopen' => setting('auto_reopen_gate'), 'reopen_after' => setting('reopen_after'),
    'gate_check_every' => setting('gate_check_interval'), 'scan_capture' => $scan_on ? 1 : 0, 'scans_ack' => $ack,
    'scan_page_match' => setting_patterns('scan_page_match'), 'scan_api_match' => setting_patterns('scan_api_match'),
    'gate_url' => $gate_url, 'gate_urls' => $gate_urls, 'commands' => $cmds]);
