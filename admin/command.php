<?php
// Supervisor / petugas mengirim perintah ke satu laptop atau semua laptop (petugas: tanpa reboot/shutdown).
require __DIR__ . '/auth.php';
require_operator_json();
check_csrf();
$b = json_in();
$allowed = ['PING', 'MESSAGE', 'OPEN_GATE', 'RESTART_BROWSER', 'SCREENSHOT', 'LOCK', 'SLEEP', 'REBOOT', 'SHUTDOWN', 'WAKE'];
$type = strtoupper(trim($b['type'] ?? ''));
if (!in_array($type, $allowed, true)) json_out(['ok' => false, 'error' => 'perintah tidak dikenal'], 400);
if (!can_command($type)) json_out(['ok' => false, 'error' => 'reboot / shutdown hanya boleh dilakukan supervisor'], 403);
$payload = isset($b['payload']) ? mb_substr((string)$b['payload'], 0, 500) : null;
$target = $b['device_id'] ?? '';

$pdo = db();
// Perintah browser/halaman gate tidak berlaku untuk laptop mode "monitor koneksi"
$gate_only = in_array($type, ['OPEN_GATE', 'RESTART_BROWSER'], true);
// category: kosong = semua laptop, 0 = tanpa kategori, N = kategori tertentu (tab yang sedang dibuka)
$cat = isset($b['category']) && $b['category'] !== '' && $b['category'] !== null ? (int)$b['category'] : null;
$cat_name = '';
if ($target === 'all') {
    $where = [];
    $args = [];
    if ($gate_only) $where[] = "mode='gate'";
    if ($cat === 0) { $where[] = 'category_id IS NULL'; $cat_name = 'tanpa kategori'; }
    elseif ($cat !== null) {
        $where[] = 'category_id=?';
        $args[] = $cat;
        $st = $pdo->prepare("SELECT name FROM categories WHERE id=?");
        $st->execute([$cat]);
        $cat_name = (string)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT id FROM devices" . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
    $st->execute($args);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
} else {
    $st = $pdo->prepare("SELECT mode FROM devices WHERE id=?");
    $st->execute([(int)$target]);
    $mode = $st->fetchColumn();
    if ($mode === false) json_out(['ok' => false, 'error' => 'laptop tidak ditemukan'], 404);
    if ($gate_only && $mode !== 'gate') json_out(['ok' => false, 'error' => 'laptop ini mode monitor koneksi (tanpa halaman gate)'], 400);
    $ids = [(int)$target];
}
if (!$ids) json_out(['ok' => false, 'error' => $gate_only ? 'tidak ada laptop mode gate' : 'tidak ada laptop'], 400);

$ins = $pdo->prepare("INSERT INTO commands (device_id, type, payload, status, created_by, created_at) VALUES (?,?,?,'pending',?,NOW())");
$user = current_user()['username'];

// ---------- Wake-on-LAN: nyalakan laptop yang OFFLINE ----------
// Keamanan: MAC tujuan diambil dari database (dilaporkan agent sendiri), bukan dari isian pengguna;
// relay = laptop online (agent >= 1.7) di jaringan /24 yang sama, yang hanya mengirim magic packet broadcast;
// tiap laptop dibatasi 1 permintaan per 30 detik; semua tercatat di log.
if ($type === 'WAKE') {
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT * FROM devices WHERE id IN ($in)");
    $st->execute($ids);
    $targets = $st->fetchAll();
    $all = $pdo->query("SELECT * FROM devices")->fetchAll();
    $off = setting('offline_after');
    $relays = array_values(array_filter($all, function ($r) use ($off) {
        return device_state($r, $off) !== 'offline' && version_compare((string)$r['agent_version'], '1.7.0', '>=') && $r['ip'];
    }));
    $net24 = function ($ip) { return preg_match('/^(\d+\.\d+\.\d+)\.\d+$/', (string)$ip, $m) ? $m[1] : null; };
    $recent = $pdo->prepare("SELECT COUNT(*) FROM logs WHERE device_id=? AND message LIKE '%Wake-on-LAN%' AND created_at >= NOW() - INTERVAL 30 SECOND");
    $sent = [];
    $skip = [];
    foreach ($targets as $t) {
        if (device_state($t, $off) !== 'offline') { $skip[] = "{$t['name']} (sudah online)"; continue; }
        $macs = wol_macs($t);
        if (!$macs) { $skip[] = "{$t['name']} (MAC belum tercatat, perlu agent 1.7+)"; continue; }
        $recent->execute([$t['id']]);
        if ($recent->fetchColumn() > 0) { $skip[] = "{$t['name']} (baru saja dikirim, tunggu 30 detik)"; continue; }
        // relay di jaringan yang sama dulu; bila IP terakhir tidak diketahui, relay mana saja
        $same = array_values(array_filter($relays, function ($r) use ($t, $net24) { return $r['id'] != $t['id'] && $net24($r['ip']) !== null && $net24($r['ip']) === $net24($t['ip']); }));
        $use = array_slice($same ?: ($t['ip'] ? [] : $relays), 0, 2);
        $pl = json_encode(['target' => $t['device_key'], 'name' => $t['name'], 'macs' => $macs]);
        foreach ($use as $r) $ins->execute([$r['id'], 'WAKE', $pl, $user]);
        $direct = wol_server_send($macs, $t['ip']);
        if (!$use && !$direct) {
            // jelaskan kenapa: laptop online di jaringan yang sama tapi agent-nya masih lama
            $old = array_filter($all, function ($r) use ($t, $off, $net24) {
                return $r['id'] != $t['id'] && device_state($r, $off) !== 'offline' && $net24($r['ip']) !== null && $net24($r['ip']) === $net24($t['ip']);
            });
            $why = $old ? 'laptop online di jaringan yang sama masih agent lama: ' . implode(', ', array_map(function ($r) { return "{$r['name']} (" . ($r['agent_version'] ?: '?') . ')'; }, $old)) . ' - perbarui ke 1.7+'
                        : 'tidak ada laptop online di jaringan yang sama, dan server tidak satu jaringan';
            $skip[] = "{$t['name']} ($why)";
            continue;
        }
        $via = array_map(function ($r) { return $r['name']; }, $use);
        if ($direct) $via[] = 'server';
        log_event($t['id'], 'info', "$user mengirim Wake-on-LAN ke {$t['name']} (" . implode(', ', $macs) . ") lewat " . implode(', ', $via));
        $sent[] = $t['name'];
    }
    if (!$sent) json_out(['ok' => false, 'error' => 'Tidak ada yang dikirim: ' . ($skip ? implode('; ', $skip) : 'tidak ada laptop offline')], 400);
    json_out(['ok' => true, 'count' => count($sent), 'skipped' => $skip]);
}
foreach ($ids as $id) {
    $ins->execute([$id, $type, $payload, $user]);
}
$names = $target === 'all' ? ($gate_only ? 'semua laptop gate' : 'SEMUA laptop') . ($cat_name !== '' ? " ($cat_name)" : '') : $pdo->query("SELECT name FROM devices WHERE id=" . (int)$target)->fetchColumn();
log_event($target === 'all' ? null : (int)$target, 'info', "$user mengirim $type ke $names" . ($payload ? ": $payload" : ''));
json_out(['ok' => true, 'count' => count($ids)]);
