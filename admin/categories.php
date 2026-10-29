<?php
// Kategori lokasi laptop (Gate, Tiket Box, Convention, ...). Jadi tab dan warna card di dashboard.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$msg = '';
function post_color() { $c = $_POST['color'] ?? ''; return preg_match('/^#[0-9a-f]{6}$/i', $c) ? strtolower($c) : '#2563eb'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_supervisor()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    $act = $_POST['act'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if (($act === 'add' || $act === 'edit') && ($name === '' || mb_strlen($name) > 50)) { $msg = 'Nama kategori wajib (maks. 50 karakter).'; }
    elseif ($act === 'add') {
        try {
            $pdo->prepare("INSERT INTO categories (name,color,sort,created_at) VALUES (?,?,?,NOW())")->execute([$name, post_color(), (int)($_POST['sort'] ?? 0)]);
            $msg = "Kategori $name ditambahkan.";
        } catch (Exception $e) { $msg = 'Nama kategori sudah ada.'; }
    } elseif ($act === 'edit') {
        try {
            $pdo->prepare("UPDATE categories SET name=?, color=?, sort=? WHERE id=?")->execute([$name, post_color(), (int)($_POST['sort'] ?? 0), (int)$_POST['id']]);
            $msg = 'Disimpan.';
        } catch (Exception $e) { $msg = 'Nama kategori sudah ada.'; }
    } elseif ($act === 'delete') {
        $pdo->prepare("UPDATE devices SET category_id=NULL WHERE category_id=?")->execute([(int)$_POST['id']]);
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([(int)$_POST['id']]);
        $msg = 'Kategori dihapus; laptop di dalamnya menjadi "Tanpa kategori".';
    }
}
$cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM devices d WHERE d.category_id=c.id) AS n FROM categories c ORDER BY c.sort, c.name")->fetchAll();
$csrf = csrf_token();
$ro = is_supervisor() ? '' : 'disabled';

// Pemilih warna: kotak warna (membuka picker browser) + kode hex + palet siap pakai.
// Nilai yang dikirim ke server ada di kolom hex (name="color"); input color bawaan hanya pemicu picker.
const PALETTE = ['#2563eb', '#0ea5e9', '#0d9488', '#16a34a', '#84cc16', '#eab308', '#f97316', '#dc2626', '#db2777', '#9333ea', '#6366f1', '#64748b'];
function color_field($value, $form = '', $presets = false, $disabled = false) {
    $v = h($value); $f = $form ? ' form="' . h($form) . '"' : ''; $dis = $disabled ? ' disabled' : '';
    $o = '<div class="cfield" data-cfield><span class="cswatch" style="--c:' . $v . '" title="Klik untuk pilih warna">'
       . '<input type="color" value="' . $v . '" tabindex="-1" aria-label="Pilih warna"' . $dis . '></span>'
       . '<input class="chex" name="color"' . $f . ' value="' . $v . '" maxlength="7" pattern="#[0-9A-Fa-f]{6}" spellcheck="false" autocomplete="off" aria-label="Kode warna"' . $dis . ' required></div>';
    if ($presets && !$disabled) {
        $o .= '<div class="cpresets" role="group" aria-label="Palet warna">';
        foreach (PALETTE as $c) $o .= '<button type="button" class="cdot" style="--c:' . $c . '" data-c="' . $c . '" title="' . $c . '" aria-label="Warna ' . $c . '"></button>';
        $o .= '</div>';
    }
    return $o;
}
page_head('Kategori lokasi');
topbar('categories', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head"><div><h1>Kategori lokasi</h1><p class="muted">Kategori = lokasi laptop. Di dashboard tiap kategori punya tab sendiri, dan card laptop diberi warna kategorinya.</p></div></div>
<?= flash($msg) ?>
<?php if (is_supervisor()): ?>
<section class="card">
  <h2><?= icon("plus") ?> Tambah kategori</h2>
  <form method="post" class="row-form cat-form">
    <input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="act" value="add">
    <label>Nama <input name="name" placeholder="Registrasi" required maxlength="50"></label>
    <div class="fld wide"><span class="fld-lbl">Warna</span><?= color_field('#0ea5e9', '', true) ?></div>
    <label>Urutan tab <input type="number" name="sort" value="<?= (count($cats) + 1) * 10 ?>" class="num"></label>
    <button class="btn btn-primary">Tambah</button>
  </form>
</section>
<?php endif; ?>
<section class="card">
  <h2><?= icon("tag") ?> Daftar kategori</h2>
  <div class="table-wrap"><table class="table">
    <tr><th>Warna</th><th>Nama</th><th>Urutan tab</th><th>Jumlah laptop</th><?php if (is_supervisor()): ?><th></th><?php endif; ?></tr>
    <?php foreach ($cats as $c): ?>
    <tr>
      <td><?= color_field($c['color'], 'c' . $c['id'], false, !is_supervisor()) ?></td>
      <td><input form="c<?= $c['id'] ?>" name="name" value="<?= h($c['name']) ?>" maxlength="50" <?= is_supervisor() ? '' : 'readonly' ?>></td>
      <td><input type="number" form="c<?= $c['id'] ?>" name="sort" value="<?= (int)$c['sort'] ?>" class="num" <?= is_supervisor() ? '' : 'readonly' ?>></td>
      <td><?= (int)$c['n'] ?></td>
      <?php if (is_supervisor()): ?>
      <td class="actions">
        <button form="c<?= $c['id'] ?>" class="btn" name="act" value="edit">Simpan</button>
        <button form="c<?= $c['id'] ?>" class="btn btn-danger" name="act" value="delete" data-confirm="Hapus kategori <?= h($c['name']) ?>? Laptop di dalamnya tidak ikut terhapus.">Hapus</button>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php foreach ($cats as $c): ?>
  <form method="post" id="c<?= $c['id'] ?>"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= $c['id'] ?>"></form>
  <?php endforeach; ?>
</section>
</main>
<?php page_foot(['categories.js?v=1']);
