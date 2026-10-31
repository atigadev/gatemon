<?php
// Uninstall agent dari server (agent >= 1.17): perintah UNINSTALL ke SATU laptop.
// Agent menjalankan uninstaller di laptop (hapus tugas otomatis, kembalikan wallpaper & Windows Update, hapus folder agent).
// Tidak bisa dibatalkan setelah diambil laptop -> supervisor + CSRF + konfirmasi password, tercatat di log.
// POST act=send (dev, password, remove=1: sekalian hapus laptop dari daftar setelah uninstall dimulai) / act=cancel (dev)
require __DIR__ . '/auth.php';
require_supervisor_json();
check_csrf();
$pdo = db();
$act = $_POST['act'] ?? '';
$st = $pdo->prepare("SELECT id, name, device_key, agent_version FROM devices WHERE id=?");
$st->execute([(int)($_POST['dev'] ?? 0)]);
$d = $st->fetch();
if (!$d) json_out(['ok' => false, 'error' => 'laptop tidak ditemukan'], 404);
$who = current_user()['username'];
$pending = $pdo->prepare("SELECT id FROM commands WHERE device_id=? AND type='UNINSTALL' AND status='pending'");
$pending->execute([$d['id']]);
$pid = $pending->fetchColumn();

if ($act === 'cancel') {
    if (!$pid) json_out(['ok' => false, 'error' => 'tidak ada uninstall yang menunggu (mungkin sudah diambil laptop)'], 400);
    // hanya yang belum diambil laptop: status masih pending
    $up = $pdo->prepare("UPDATE commands SET status='failed', result=?, done_at=NOW() WHERE id=? AND status='pending'");
    $up->execute(["dibatalkan oleh $who", $pid]);
    if (!$up->rowCount()) json_out(['ok' => false, 'error' => 'terlambat: perintah sudah diambil laptop'], 409);
    log_event($d['id'], 'warn', "{$d['name']}: uninstall agent dibatalkan oleh $who");
    json_out(['ok' => true]);
}
if ($act !== 'send') json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
if (!$d['agent_version'] || version_compare($d['agent_version'], UNINSTALL_MIN, '<')) {
    json_out(['ok' => false, 'error' => 'perlu agent ' . UNINSTALL_MIN . '+ (laptop ini ' . ($d['agent_version'] ?: '?') . '). Update dulu lewat menu Agent, atau jalankan uninstall_agent.bat di laptop.'], 400);
}
if ($pid) json_out(['ok' => false, 'error' => 'uninstall untuk laptop ini sudah menunggu dikirim'], 409);
if (!confirm_password((string)($_POST['password'] ?? ''))) json_out(['ok' => false, 'error' => 'password salah, perintah tidak dikirim'], 403);
$remove = !empty($_POST['remove']);
$pdo->prepare("INSERT INTO commands (device_id, type, payload, status, created_by, created_at) VALUES (?, 'UNINSTALL', ?, 'pending', ?, NOW())")
    ->execute([$d['id'], $remove ? 'remove' : '', $who]);
log_event($d['id'], 'warn', "{$d['name']}: uninstall agent dikirim oleh $who" . ($remove ? ' (laptop dihapus dari daftar setelah uninstall dimulai)' : ''));
json_out(['ok' => true]);
