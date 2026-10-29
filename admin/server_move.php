<?php
// Pindahkan agent ke server lain (mis. server LAN -> hosting cPanel) tanpa memasang ulang: perintah SET_SERVER.
// Agent >= 1.13 menguji dulu server baru dengan ID + token-nya; gagal = tetap di server ini dan alasannya dilaporkan.
// Aksi berbahaya: supervisor + CSRF + konfirmasi password, tercatat di log.
require __DIR__ . '/auth.php';
require_login();
const MOVE_MIN = '1.13.0';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_supervisor()) { header('Location: settings.php'); exit; }
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) die('csrf');
$back = function ($msg) { $_SESSION['flash'] = $msg; header('Location: settings.php#pindah-agent'); exit; };

$url = rtrim(trim((string)($_POST['url'] ?? '')), '/');
$p = parse_url($url);
$host = $p['host'] ?? '';
$ok = $p && in_array($p['scheme'] ?? '', ['http', 'https'], true) && $host !== '' && !isset($p['user']) && !isset($p['pass']) && !isset($p['query']) && !isset($p['fragment']);
if ($ok && $p['scheme'] === 'http') {
    // tanpa enkripsi hanya untuk server di jaringan lokal (token laptop terkirim apa adanya)
    $ok = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && !filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}
if (!$ok || strlen($url) > 300) $back('Alamat server tidak valid: wajib https://domain/folder (http:// hanya untuk IP LAN).');
if (!confirm_password((string)($_POST['password'] ?? ''))) $back('Password tidak valid. Perintah pindah server tidak dikirim.');

$pdo = db();
$ins = $pdo->prepare("INSERT INTO commands (device_id, type, payload, status, created_by, created_at) VALUES (?, 'SET_SERVER', ?, 'pending', ?, NOW())");
$who = current_user()['username'];
$n = 0; $old = [];
foreach ($pdo->query("SELECT id, name, agent_version FROM devices ORDER BY name") as $d) {
    if (!$d['agent_version'] || version_compare($d['agent_version'], MOVE_MIN, '<')) { $old[] = $d['name']; continue; }
    $ins->execute([$d['id'], json_encode(['url' => $url]), $who]);
    $n++;
}
log_event(null, 'warn', "$who mengirim perintah PINDAH SERVER ke $n laptop: $url");
$back("Perintah pindah ke $url dikirim ke $n laptop. Hasil per laptop terlihat di tabel di bawah."
    . ($old ? ' Belum didukung (agent < ' . MOVE_MIN . ', update dulu di menu Agent): ' . implode(', ', $old) . '.' : ''));
