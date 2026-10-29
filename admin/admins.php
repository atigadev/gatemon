<?php
// Kelola akun admin dashboard (supervisor saja), ganti password sendiri (semua).
// Password: min. 10 karakter, tidak memuat username / kata umum (password_problem()). Ganti password mengeluarkan
// sesi lain akun itu. Supervisor bisa mereset password akun lain: akun itu wajib menggantinya saat login.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$msg = '';
$me = current_user();
$must = !empty($_SESSION['must_change']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) die('csrf');
    $act = $_POST['act'] ?? '';
    $sup_count = (int)$pdo->query("SELECT COUNT(*) FROM admins WHERE role='supervisor'")->fetchColumn();
    if ($act === 'password') {
        $old = (string)($_POST['old'] ?? '');
        $p = (string)($_POST['password'] ?? '');
        if (!confirm_password($old)) $msg = 'Password lama tidak valid.';
        elseif ($p !== (string)($_POST['password2'] ?? '')) $msg = 'Password baru tidak valid: ulangan password tidak sama.';
        elseif (hash_equals($old, $p)) $msg = 'Password baru tidak valid: harus berbeda dari password lama.';
        elseif (($pp = password_problem($p, $me['username'])) !== '') $msg = 'Password baru tidak valid: ' . $pp;
        else {
            $hash = password_hash($p, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE admins SET password_hash=?, must_change=0, pw_changed_at=NOW() WHERE id=?")->execute([$hash, $me['id']]);
            $st = $pdo->prepare("SELECT * FROM admins WHERE id=?");
            $st->execute([$me['id']]);
            session_login($st->fetch(), false);                  // sesi ini tetap masuk; sesi lain akun ini keluar
            log_event(null, 'info', "{$me['username']} mengganti password");
            $_SESSION['flash'] = 'Password diganti. Sesi lain akun ini sudah dikeluarkan.';
            header('Location: ' . ($must ? 'index.php' : 'admins.php'));
            exit;
        }
    } elseif (is_supervisor() && !$must && $act === 'add') {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        $role = ($_POST['role'] ?? '') === 'supervisor' ? 'supervisor' : 'viewer';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $u)) $msg = 'Username tidak valid: 3-50 karakter huruf, angka, titik, minus, garis bawah.';
        elseif (($pp = password_problem($p, $u)) !== '') $msg = 'Password tidak valid: ' . $pp;
        else {
            try {
                $pdo->prepare("INSERT INTO admins (username,password_hash,role,created_at,pw_changed_at) VALUES (?,?,?,NOW(),NOW())")->execute([$u, password_hash($p, PASSWORD_DEFAULT), $role]);
                log_event(null, 'info', "{$me['username']} membuat akun $u ($role)");
                $msg = "Akun $u dibuat.";
            } catch (Exception $e) { $msg = 'Username sudah ada.'; }
        }
    } elseif (is_supervisor() && !$must && $act === 'reset') {
        // password sementara dari supervisor; pemilik akun wajib menggantinya saat login, semua sesinya keluar
        $id = (int)($_POST['id'] ?? 0);
        $p = (string)($_POST['password'] ?? '');
        $st = $pdo->prepare("SELECT username FROM admins WHERE id=?");
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false || $id === (int)$me['id']) $msg = 'Akun tidak valid (untuk akun sendiri pakai "Ganti password saya").';
        elseif (($pp = password_problem($p, $name)) !== '') $msg = 'Password sementara tidak valid: ' . $pp;
        else {
            $pdo->prepare("UPDATE admins SET password_hash=?, must_change=1, pw_changed_at=NOW() WHERE id=?")->execute([password_hash($p, PASSWORD_DEFAULT), $id]);
            auth_fail_clear('login', client_ip(), strtolower($name));
            log_event(null, 'warn', "{$me['username']} mereset password akun $name");
            $msg = "Password $name direset. Berikan password sementara secara langsung; $name wajib menggantinya saat login.";
        }
    } elseif (is_supervisor() && !$must && $act === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT username, role FROM admins WHERE id=?");
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) $msg = 'Akun tidak valid.';
        elseif ($id === (int)$me['id']) $msg = 'Tidak bisa menghapus akun sendiri.';
        elseif ($t['role'] === 'supervisor' && $sup_count <= 1) $msg = 'Tidak bisa menghapus supervisor terakhir.';
        else {
            $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$id]);
            log_event(null, 'warn', "{$me['username']} menghapus akun {$t['username']}");
            $msg = 'Akun dihapus.';
        }
    }
}
if ($msg === '' && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }
$admins = $pdo->query("SELECT id, username, role, created_at, must_change, pw_changed_at, last_login_at, last_login_ip FROM admins ORDER BY username")->fetchAll();
$csrf = csrf_token();
page_head('Admins');
topbar('admins', 'Monitoring Laptop');
$rule = 'Minimal 10 karakter, tidak memuat username atau kata umum (password, admin123, qwerty, …).';
?>
<main class="page">
  <div class="page-head"><div><h1>Admins</h1><p class="muted">Akun yang bisa masuk ke dashboard. Viewer hanya melihat, supervisor boleh mengirim perintah.</p></div></div>
<?php if ($must): ?><p class="notice error"><b>Wajib ganti password.</b> Password akun ini terlalu lemah atau baru direset supervisor. Menu lain terbuka setelah password diganti.</p><?php endif; ?>
<?= flash($msg) ?>
<section class="card">
  <h2><?= icon("key") ?> Ganti password saya</h2>
  <form method="post" class="row-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="password">
    <input type="text" name="username" value="<?= h($me['username']) ?>" autocomplete="username" hidden>
    <label>Password lama <input type="password" name="old" required autocomplete="current-password" maxlength="200"></label>
    <label>Password baru <input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
    <label>Ulangi password baru <input type="password" name="password2" required minlength="10" maxlength="200" autocomplete="new-password"></label>
    <button class="btn btn-primary">Simpan</button>
  </form>
  <p class="muted small"><?= h($rule) ?> Setelah diganti, sesi akun ini di perangkat lain otomatis keluar.</p>
</section>
<?php if (is_supervisor() && !$must): ?>
<section class="card">
  <h2><?= icon("plus") ?> Tambah akun</h2>
  <form method="post" class="row-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="add">
    <label>Username <input name="username" required pattern="[A-Za-z0-9._\-]{3,50}" maxlength="50" autocomplete="off"></label>
    <label>Password <input type="password" name="password" required minlength="10" maxlength="200" autocomplete="new-password"></label>
    <label>Peran <select name="role"><option value="viewer">Viewer (hanya melihat)</option><option value="supervisor">Supervisor (boleh kirim perintah)</option></select></label>
    <button class="btn btn-primary">Tambah</button>
  </form>
  <p class="muted small"><?= h($rule) ?></p>
</section>
<?php endif; ?>
<?php if (!$must): ?>
<section class="card">
  <h2><?= icon("users") ?> Daftar akun</h2>
  <div class="table-wrap"><table class="table">
    <tr><th>Username</th><th>Peran</th><th>Login terakhir</th><th>Password diganti</th><?php if (is_supervisor()): ?><th></th><?php endif; ?></tr>
    <?php foreach ($admins as $a): ?>
    <tr><td><?= h($a['username']) ?><?= (int)$a['must_change'] === 1 ? ' <span class="badge">wajib ganti password</span>' : '' ?></td>
      <td><span class="badge<?= $a['role'] === 'supervisor' ? ' sup' : '' ?>"><?= h($a['role']) ?></span></td>
      <td class="muted"><?= $a['last_login_at'] ? h($a['last_login_at']) . ' · ' . h($a['last_login_ip']) : 'belum pernah' ?></td>
      <td class="muted"><?= $a['pw_changed_at'] ? h($a['pw_changed_at']) : '–' ?></td>
    <?php if (is_supervisor()): ?><td class="actions">
      <?php if ((int)$a['id'] !== (int)$me['id']): ?>
      <form method="post" class="inline-form" data-confirm="Reset password <?= h($a['username']) ?>? Semua sesinya akan keluar."><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="reset"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
        <input type="password" name="password" required minlength="10" maxlength="200" placeholder="Password sementara" autocomplete="new-password" aria-label="Password sementara untuk <?= h($a['username']) ?>"><button class="btn">Reset</button></form>
      <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-danger" data-confirm="Hapus akun <?= h($a['username']) ?>?">Hapus</button></form>
      <?php endif; ?>
    </td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
<?php endif; ?>
</main>
<?php page_foot();
