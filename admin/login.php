<?php
require __DIR__ . '/auth.php';
// Pembatasan tebak password: per IP (10 gagal / 15 menit) dan per username (8 gagal / 15 menit, dari IP mana pun).
// Pesan gagal selalu sama agar tidak membocorkan username mana yang ada.
const LOGIN_MAX_IP = 10, LOGIN_MAX_USER = 8, LOGIN_WINDOW = 15;
$err = '';
$ip = client_ip();
if (current_user()) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 50);
    $pass = (string)($_POST['password'] ?? '');
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        $err = 'Sesi halaman login kedaluwarsa, silakan coba lagi.';
    } elseif (auth_fail_count('login', $ip, null, LOGIN_WINDOW) >= LOGIN_MAX_IP || ($user !== '' && auth_fail_count('login', $ip, strtolower($user), LOGIN_WINDOW) >= LOGIN_MAX_USER)) {
        $err = 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . LOGIN_WINDOW . ' menit.';
        usleep(500000);
    } else {
        $st = db()->prepare("SELECT * FROM admins WHERE username=?");
        $st->execute([$user]);
        $u = $st->fetch();
        // hash pengganti saat username tidak ada: waktu proses sama, username tidak bisa ditebak dari lamanya respon
        $ok = password_verify($pass, $u ? $u['password_hash'] : '$2y$10$ZS0f7/13aDo4xWn66Ha4C..m.reY.Lk1fAeLhIKVB/rqny078PU/q');
        if ($u && $ok) {
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                $u['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
                db()->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([$u['password_hash'], $u['id']]);
            }
            $weak = password_problem($pass, $u['username']) !== '';
            db()->prepare("UPDATE admins SET last_login_at=NOW(), last_login_ip=? WHERE id=?")->execute([$ip, $u['id']]);
            auth_fail_clear('login', $ip, strtolower($user));
            session_login($u, $weak || (int)$u['must_change'] === 1);
            log_event(null, 'info', "{$u['username']} login dari $ip" . ($weak ? ' (password lemah: wajib diganti)' : ''));
            header('Location: ' . ($weak || (int)$u['must_change'] === 1 ? 'admins.php?wajib=1' : 'index.php'));
            exit;
        }
        auth_fail_add('login', $ip, strtolower($user));
        $n = auth_fail_count('login', $ip, strtolower($user), LOGIN_WINDOW);
        if ($n === LOGIN_MAX_USER || auth_fail_count('login', $ip, null, LOGIN_WINDOW) === LOGIN_MAX_IP) {
            log_event(null, 'warn', "Login diblokir sementara: terlalu banyak password salah untuk \"" . mb_substr($user, 0, 30) . "\" dari $ip");
        }
        usleep(random_int(300000, 700000));
        $err = 'Username atau password salah.';
    }
}
page_head('Login', 'login-body');
?>
<form method="post" class="login-box" autocomplete="on">
  <?= theme_button() ?>
  <?= logo() ?>
  <h1><?= h($CFG['app_name']) ?></h1>
  <p class="muted">Monitoring &amp; kendali laptop event</p>
  <?php if ($err): ?><p class="notice error"><?= h($err) ?></p><?php endif; ?>
  <?php if (!empty($_GET['keluar'])): ?><p class="notice">Anda sudah keluar.</p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <label>Username <input name="username" required autofocus autocomplete="username" maxlength="50"></label>
  <label>Password <input name="password" type="password" required autocomplete="current-password" maxlength="200"></label>
  <button class="btn btn-primary" type="submit">Masuk</button>
</form>
<?php page_foot();
