<?php
// Pendaftaran agent dengan kode instalasi sekali pakai, untuk installer umum (install_agent.ps1 tanpa config.json).
// Cara utama di laptop: buka http://SERVER/gatemon/install/ID-LAPTOP di browser (install/index.php).
// Masuk: {username, password, hostname}. Keluar: config agent (device_key + token baru, tanpa server_url).
require __DIR__ . '/../db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok' => false, 'error' => 'POST only'], 405);

$b = json_in();
$host = trim((string)($b['hostname'] ?? ''));
$r = enroll_verify($b['username'] ?? '', $b['password'] ?? '', $host !== '' ? $host : ($_SERVER['REMOTE_ADDR'] ?? ''));
if (!$r['ok']) json_out(['ok' => false, 'error' => $r['error']], $r['code']);

$d = $r['device'];
$cfg = agent_config($d, '');
unset($cfg['server_url']); // installer mengisi server_url sesuai alamat yang dipakainya
json_out(['ok' => true, 'device' => ['key' => $d['device_key'], 'name' => $d['name'], 'mode' => $d['mode']], 'config' => $cfg]);
