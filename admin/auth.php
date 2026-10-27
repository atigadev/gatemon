<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/layout.php';
session_name('gatemon');
session_start();

function current_user() { return $_SESSION['user'] ?? null; }
function is_supervisor() { return (current_user()['role'] ?? '') === 'supervisor'; }
function require_login() {
    if (!current_user()) { header('Location: login.php'); exit; }
}
function require_supervisor_json() {
    if (!current_user()) json_out(['ok' => false, 'error' => 'login required'], 401);
    if (!is_supervisor()) json_out(['ok' => false, 'error' => 'hanya supervisor yang boleh mengirim perintah'], 403);
}
function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf() {
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) json_out(['ok' => false, 'error' => 'csrf'], 403);
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
