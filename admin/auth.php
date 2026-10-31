<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';

// Cookie sesi: tidak terbaca JavaScript, tidak ikut request dari situs lain, hanya lewat HTTPS bila server memakai HTTPS
ini_set('session.use_strict_mode', '1');      // ID sesi buatan penyerang (session fixation) ditolak
ini_set('session.use_only_cookies', '1');
session_name('gatemon');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
session_start();

// Sesi berakhir bila: tidak aktif terlalu lama, umur sesi habis, akun dihapus, role berubah, atau password diganti
// (sid_pw = sidik jari hash password saat login, jadi ganti password mengeluarkan semua sesi lain).
function session_end() {
    $_SESSION = [];
    session_regenerate_id(true);
}
function session_check() {
    global $CFG;
    if (empty($_SESSION['user'])) return;
    $now = time();
    $idle = max(5, (int)($CFG['session_idle_minutes'] ?? 240)) * 60;
    $max = max(1, (int)($CFG['session_max_hours'] ?? 24)) * 3600;
    if ($now - (int)($_SESSION['seen_at'] ?? 0) > $idle || $now - (int)($_SESSION['login_at'] ?? 0) > $max) { session_end(); return; }
    $st = db()->prepare("SELECT id, username, role, password_hash, must_change FROM admins WHERE id=?");
    $st->execute([(int)$_SESSION['user']['id']]);
    $a = $st->fetch();
    if (!$a || !hash_equals((string)($_SESSION['sid_pw'] ?? ''), pw_fingerprint($a['password_hash']))) { session_end(); return; }
    $_SESSION['user'] = ['id' => (int)$a['id'], 'username' => $a['username'], 'role' => $a['role']];
    $_SESSION['must_change'] = !empty($_SESSION['must_change']) || (int)$a['must_change'] === 1;
    if ($now - (int)($_SESSION['seen_at'] ?? 0) > 30) $_SESSION['seen_at'] = $now;   // tidak menulis sesi tiap polling
}
function pw_fingerprint($hash) { return substr(hash('sha256', 'gatemon-session|' . $hash), 0, 32); }
// Dipanggil setelah password benar (login.php)
function session_login(array $a, $must_change) {
    session_regenerate_id(true);
    $_SESSION = ['user' => ['id' => (int)$a['id'], 'username' => $a['username'], 'role' => $a['role']],
        'sid_pw' => pw_fingerprint($a['password_hash']), 'login_at' => time(), 'seen_at' => time(), 'must_change' => (bool)$must_change];
}
session_check();

// Peran akun dashboard:
//   viewer     = hanya melihat
//   operator   = "Petugas": kirim perintah ke agent (kecuali reboot/shutdown), balas chat & tangani darurat,
//                buat kode instalasi agent; TIDAK bisa menambah/mengubah/menghapus laptop atau pengaturan
//   supervisor = semua
const ROLES = ['viewer' => 'Viewer (hanya melihat)', 'operator' => 'Petugas (perintah, chat, kode instalasi)', 'supervisor' => 'Supervisor (semua akses)'];
const ROLE_NAMES = ['viewer' => 'viewer', 'operator' => 'petugas', 'supervisor' => 'supervisor'];
const OPERATOR_DENY_CMDS = ['REBOOT', 'SHUTDOWN'];
function current_user() { return $_SESSION['user'] ?? null; }
function is_supervisor() { return (current_user()['role'] ?? '') === 'supervisor'; }
function is_operator() { return in_array(current_user()['role'] ?? '', ['operator', 'supervisor'], true); }   // petugas atau supervisor
function can_command($type) { return is_supervisor() || (is_operator() && !in_array($type, OPERATOR_DENY_CMDS, true)); }
function require_login() {
    if (!current_user()) { header('Location: login.php'); exit; }
}
function require_supervisor_json() {
    if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
    if (!is_supervisor()) json_out(['ok' => false, 'error' => 'hanya supervisor yang boleh melakukan ini'], 403);
}
function require_operator_json() {
    if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
    if (!is_operator()) json_out(['ok' => false, 'error' => 'akun viewer hanya bisa melihat'], 403);
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf() {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) json_out(['ok' => false, 'error' => 'csrf'], 403);
}
// Konfirmasi password untuk aksi berbahaya (paket server berisi token, pindah server agent)
function confirm_password($pass) {
    $st = db()->prepare("SELECT password_hash FROM admins WHERE id=?");
    $st->execute([(int)(current_user()['id'] ?? 0)]);
    $h = $st->fetchColumn();
    if ($h && is_string($pass) && password_verify($pass, $h)) return true;
    usleep(500000);
    return false;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Password lemah / direset supervisor: semua halaman dialihkan ke form ganti password sampai diganti
if (current_user() && !empty($_SESSION['must_change']) && !in_array(basename((string)$_SERVER['SCRIPT_NAME']), ['admins.php', 'logout.php', 'login.php'], true)) {
    if (stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        header('Location: admins.php?wajib=1');
        exit;
    }
    json_out(['ok' => false, 'error' => 'wajib ganti password dulu (menu Admins)'], 403);
}
