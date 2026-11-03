<?php
// Push notification (PWA) untuk akun yang sedang login, dipanggil pwa.js / halaman Notifikasi.
// GET  act=info                                  -> kunci publik VAPID, kesiapan server, daftar browser akun ini
// POST act=subscribe {sub (JSON PushSubscription), emg, chat}  -> simpan / perbarui langganan browser ini (CSRF)
// POST act=prefs {endpoint, emg, chat}           -> ubah jenis notifikasi browser ini
// POST act=unsubscribe {endpoint | id}           -> hapus langganan (browser ini, atau browser lain milik akun ini)
// POST act=test {endpoint}                       -> kirim notifikasi uji ke browser ini, hasilnya langsung dilaporkan
require __DIR__ . '/auth.php';
require __DIR__ . '/../push.php';
if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
$pdo = db();
$uid = (int)current_user()['id'];
$act = $_REQUEST['act'] ?? '';
$can_chat = is_operator();   // chat hanya untuk petugas & supervisor (yang bisa membalas)

function my_subs() {
    global $pdo, $uid;
    $st = $pdo->prepare("SELECT id, endpoint, want_emg, want_chat, ua, created_at, last_ok, last_err FROM push_subs WHERE admin_id=? ORDER BY id DESC");
    $st->execute([$uid]);
    return array_map(function ($r) {
        return ['id' => (int)$r['id'], 'ep' => hash('sha256', $r['endpoint']), 'emg' => (int)$r['want_emg'], 'chat' => (int)$r['want_chat'],
            'ua' => $r['ua'], 'created_at' => $r['created_at'], 'last_ok' => $r['last_ok'], 'last_err' => $r['last_err']];
    }, $st->fetchAll());
}
function sub_by_endpoint($ep) {
    global $pdo, $uid;
    $st = $pdo->prepare("SELECT * FROM push_subs WHERE ep_hash=? AND admin_id=?");
    $st->execute([hash('sha256', (string)$ep), $uid]);
    return $st->fetch();
}
$bit = function ($k) { return !empty($_POST[$k]) && $_POST[$k] !== '0' ? 1 : 0; };

if ($act === 'info') {
    [$ready, $why] = push_ready();
    json_out(['ok' => true, 'ready' => $ready, 'why' => $why, 'https' => is_https(), 'key' => $ready ? vapid_public() : '',
        'can_chat' => $can_chat, 'subs' => my_subs()]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
check_csrf();

if ($act === 'subscribe') {
    [$ready, $why] = push_ready();
    if (!$ready) json_out(['ok' => false, 'error' => $why], 500);
    $s = json_decode((string)($_POST['sub'] ?? ''), true);
    $ep = (string)($s['endpoint'] ?? '');
    $p256 = (string)($s['keys']['p256dh'] ?? ''); $auth = (string)($s['keys']['auth'] ?? '');
    if (!push_endpoint_ok($ep)) json_out(['ok' => false, 'error' => 'alamat layanan push browser ini tidak dikenal'], 400);
    if (strlen(b64u_dec($p256)) !== 65 || strlen(b64u_dec($auth)) !== 16) json_out(['ok' => false, 'error' => 'kunci langganan tidak valid'], 400);
    $n = $pdo->prepare("SELECT COUNT(*) FROM push_subs WHERE admin_id=?"); $n->execute([$uid]);
    if ($n->fetchColumn() >= 20 && !sub_by_endpoint($ep)) json_out(['ok' => false, 'error' => 'maksimal 20 browser per akun: hapus yang tidak dipakai'], 400);
    $ua = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 150);
    // endpoint yang sama dari akun lain (HP dipakai bergantian): pindah ke akun yang login sekarang
    $pdo->prepare("INSERT INTO push_subs (admin_id, ep_hash, endpoint, p256dh, auth, want_emg, want_chat, ua, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE admin_id=VALUES(admin_id), endpoint=VALUES(endpoint), p256dh=VALUES(p256dh), auth=VALUES(auth),
        want_emg=VALUES(want_emg), want_chat=VALUES(want_chat), ua=VALUES(ua), fails=0, last_err=NULL")
        ->execute([$uid, hash('sha256', $ep), $ep, $p256, $auth, $bit('emg'), $can_chat ? $bit('chat') : 0, $ua]);
    log_event(null, 'info', current_user()['username'] . ' mengaktifkan push notification di browser: ' . mb_substr($ua, 0, 80));
    json_out(['ok' => true, 'subs' => my_subs()]);
}
if ($act === 'prefs') {
    $s = sub_by_endpoint($_POST['endpoint'] ?? '');
    if (!$s) json_out(['ok' => false, 'error' => 'browser ini belum berlangganan'], 404);
    $pdo->prepare("UPDATE push_subs SET want_emg=?, want_chat=? WHERE id=?")->execute([$bit('emg'), $can_chat ? $bit('chat') : 0, $s['id']]);
    json_out(['ok' => true, 'subs' => my_subs()]);
}
if ($act === 'unsubscribe') {
    if (!empty($_POST['id'])) $pdo->prepare("DELETE FROM push_subs WHERE id=? AND admin_id=?")->execute([(int)$_POST['id'], $uid]);
    else $pdo->prepare("DELETE FROM push_subs WHERE ep_hash=? AND admin_id=?")->execute([hash('sha256', (string)($_POST['endpoint'] ?? '')), $uid]);
    json_out(['ok' => true, 'subs' => my_subs()]);
}
if ($act === 'test') {
    $s = sub_by_endpoint($_POST['endpoint'] ?? '');
    if (!$s) json_out(['ok' => false, 'error' => 'browser ini belum berlangganan'], 404);
    set_time_limit(30);
    [$ok, $err] = push_send_sub($s, ['kind' => 'test', 'title' => 'GateMon: notifikasi uji', 'body' => 'Push notification di browser ini sudah berfungsi.',
        'tag' => 'test', 'url' => 'notifikasi.php', 'id' => 0, 'ts' => time() * 1000]);
    json_out(['ok' => $ok, 'error' => $ok ? null : 'Server gagal mengirim ke layanan push browser: ' . $err], $ok ? 200 : 502);
}
json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
