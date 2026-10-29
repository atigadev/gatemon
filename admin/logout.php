<?php
// Keluar: perlu token sesi (logout.php?t=CSRF) agar link/gambar dari situs lain tidak bisa mengeluarkan pengguna.
require __DIR__ . '/auth.php';
$t = $_GET['t'] ?? ($_POST['csrf'] ?? '');
if (current_user() && is_string($t) && hash_equals($_SESSION['csrf'] ?? '', $t)) {
    session_end();
    session_destroy();
    header('Location: login.php?keluar=1');
    exit;
}
header('Location: ' . (current_user() ? 'index.php' : 'login.php'));
