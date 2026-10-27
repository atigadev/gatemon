<?php
// Kelola akun admin dashboard (supervisor saja), ganti password sendiri (semua).
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    $act = $_POST['act'] ?? '';
    if ($act === 'password') {
        $p = $_POST['password'] ?? '';
        if (strlen($p) < 6) $msg = 'Password minimal 6 karakter.';
        else { $pdo->prepare("UPDATE admins SET password_hash=? WHERE id=?")->execute([password_hash($p, PASSWORD_DEFAULT), current_user()['id']]); $msg = 'Password diganti.'; }
    } elseif (is_supervisor() && $act === 'add') {
        $u = trim($_POST['username']); $p = $_POST['password'] ?? '';
        $role = $_POST['role'] === 'supervisor' ? 'supervisor' : 'viewer';
        if ($u === '' || strlen($p) < 6) $msg = 'Username wajib, password minimal 6 karakter.';
        else { try { $pdo->prepare("INSERT INTO admins (username,password_hash,role,created_at) VALUES (?,?,?,NOW())")->execute([$u, password_hash($p, PASSWORD_DEFAULT), $role]); $msg = "Akun $u dibuat."; } catch (Exception $e) { $msg = 'Username sudah ada.'; } }
    } elseif (is_supervisor() && $act === 'delete') {
        $id = (int)$_POST['id'];
        if ($id === (int)current_user()['id']) $msg = 'Tidak bisa menghapus akun sendiri.';
        else { $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$id]); $msg = 'Akun dihapus.'; }
    }
}
$admins = $pdo->query("SELECT id, username, role, created_at FROM admins ORDER BY username")->fetchAll();
$csrf = csrf_token();
page_head('Admins');
topbar('admins', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Admins</h1><p class="muted">Akun yang bisa masuk ke dashboard. Viewer hanya melihat, supervisor boleh mengirim perintah.</p></div></div>
<?= flash($msg) ?>
<section class="card">
  <h2><?= icon("users") ?> Ganti password saya</h2>
  <form method="post" class="row-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="password">
    <label>Password baru <input type="password" name="password" required minlength="6"></label>
    <button class="btn btn-primary">Simpan</button>
  </form>
</section>
<?php if (is_supervisor()): ?>
<section class="card">
  <h2><?= icon("plus") ?> Tambah akun</h2>
  <form method="post" class="row-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="add">
    <label>Username <input name="username" required></label>
    <label>Password <input type="password" name="password" required minlength="6"></label>
    <label>Peran <select name="role"><option value="viewer">Viewer (hanya melihat)</option><option value="supervisor">Supervisor (boleh kirim perintah)</option></select></label>
    <button class="btn btn-primary">Tambah</button>
  </form>
</section>
<?php endif; ?>
<section class="card">
  <h2><?= icon("users") ?> Daftar akun</h2>
  <div class="table-wrap"><table class="table">
    <tr><th>Username</th><th>Peran</th><th>Dibuat</th><?php if (is_supervisor()): ?><th></th><?php endif; ?></tr>
    <?php foreach ($admins as $a): ?>
    <tr><td><?= h($a['username']) ?></td><td><span class="badge<?= $a['role'] === 'supervisor' ? ' sup' : '' ?>"><?= h($a['role']) ?></span></td><td class="muted"><?= h($a['created_at']) ?></td>
    <?php if (is_supervisor()): ?><td class="actions"><form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><button class="btn btn-danger" onclick="return confirm('Hapus akun <?= h($a['username']) ?>?')">Hapus</button></form></td><?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
</section>
</main>
<?php page_foot();
