<?php
require __DIR__ . '/auth.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $st = db()->prepare("SELECT * FROM admins WHERE username=?");
    $st->execute([trim($_POST['username'] ?? '')]);
    $u = $st->fetch();
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        $_SESSION['user'] = ['id' => $u['id'], 'username' => $u['username'], 'role' => $u['role']];
        header('Location: index.php'); exit;
    }
    $err = 'Username atau password salah.';
}
page_head('Login', 'login-body');
?>
<form method="post" class="login-box">
  <?= theme_button() ?>
  <?= logo() ?>
  <h1><?= h($CFG['app_name']) ?></h1>
  <p class="muted">Monitoring &amp; kendali laptop event</p>
  <?php if ($err): ?><p class="notice error"><?= h($err) ?></p><?php endif; ?>
  <label>Username <input name="username" required autofocus autocomplete="username"></label>
  <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
  <button class="btn btn-primary" type="submit">Masuk</button>
</form>
<?php page_foot();
