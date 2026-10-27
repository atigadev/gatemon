<?php
// Jalankan SEKALI lewat browser: http://IP-SERVER/gatemon/setup.php  lalu HAPUS file ini.
// Pengaman kedua (selain .htaccess): hanya dari laptop server sendiri
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('setup.php hanya bisa dijalankan dari laptop server: buka http://localhost/gatemon/setup.php');
}
$CFG = require __DIR__ . '/config.php';

// ---------- pemeriksaan sistem (wajib = GateMon tidak jalan tanpanya; opsional = ada cadangan bawaan) ----------
$ini = php_ini_loaded_file() ?: '(php.ini tidak ditemukan)';
$checks = [
    ['PHP versi 7.4 atau lebih baru', version_compare(PHP_VERSION, '7.4.0', '>='), true, 'Sekarang ' . PHP_VERSION],
    ['Ekstensi pdo_mysql (database)', extension_loaded('pdo_mysql'), true, "Aktifkan <code>extension=pdo_mysql</code> di $ini lalu restart Apache"],
    ['Ekstensi json', function_exists('json_encode'), true, 'Bawaan PHP; pasang paket php-json'],
    ['Ekstensi zip (paket instalasi agent)', class_exists('ZipArchive'), false, "Opsional: tanpa ini GateMon memakai pembuat ZIP bawaan (tanpa kompresi, file lebih besar). Aktifkan: hapus titik koma di <code>;extension=zip</code> pada $ini lalu restart Apache"],
    ['Ekstensi mbstring', extension_loaded('mbstring'), false, 'Opsional: ada cadangan bawaan'],
    ['Ekstensi zlib (kompres data dashboard)', function_exists('gzencode'), false, 'Opsional: tanpa ini data dashboard dikirim tanpa kompresi'],
    ['Apache mod_rewrite (link /install/ID-LAPTOP)', function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null, false,
        'Opsional: tanpa ini gunakan <code>install/index.php?id=ID-LAPTOP</code>. Aktifkan <code>LoadModule rewrite_module</code> di httpd.conf'],
];
echo '<h3>Pemeriksaan sistem GateMon</h3><table border="1" cellpadding="6" style="border-collapse:collapse;font-family:sans-serif;font-size:14px">';
$fatal = false;
foreach ($checks as [$name, $ok, $required, $how]) {
    $st = $ok === null ? 'tidak dapat dicek' : ($ok ? 'OK' : ($required ? 'WAJIB, BELUM ADA' : 'belum aktif (opsional)'));
    $color = $ok ? '#0a7d4f' : ($required ? '#c0392b' : '#b7791f');
    if (!$ok && $required && $ok !== null) $fatal = true;
    echo "<tr><td>$name</td><td style='color:$color;font-weight:bold'>$st</td><td>" . ($ok ? '' : $how) . '</td></tr>';
}
echo '</table>';
if ($fatal) exit('<p style="color:#c0392b"><b>Perbaiki yang WAJIB di atas, restart Apache, lalu buka setup.php lagi.</b></p>');

$c = $CFG['db'];
$pdo = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$c['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$c['name']}`");
$pdo->exec("CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('viewer','supervisor') NOT NULL DEFAULT 'viewer',
  created_at DATETIME NOT NULL
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS devices (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_key VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(100) NOT NULL,
  gate VARCHAR(100) NOT NULL DEFAULT '',
  category_id INT NULL,
  gate_url VARCHAR(2000) NOT NULL DEFAULT '',
  mode ENUM('gate','monitor') NOT NULL DEFAULT 'gate',
  camera_url VARCHAR(500) NOT NULL DEFAULT '',
  enroll_hash VARCHAR(255) NULL,
  enroll_expires DATETIME NULL,
  enroll_fail INT NOT NULL DEFAULT 0,
  enrolled_at DATETIME NULL,
  enrolled_host VARCHAR(100) NULL,
  install_ticket CHAR(64) NULL,
  install_ticket_expires DATETIME NULL,
  token VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NULL,
  public_ip VARCHAR(45) NULL,
  nics TEXT NULL,
  wol VARCHAR(500) NULL,
  hostname VARCHAR(100) NULL,
  os_user VARCHAR(100) NULL,
  cpu DECIMAL(5,1) NULL,
  ram DECIMAL(5,1) NULL,
  disk DECIMAL(5,1) NULL,
  battery INT NULL,
  battery_plugged TINYINT NULL,
  uptime_sec INT NULL,
  browser_running TINYINT NOT NULL DEFAULT 0,
  gate_url_ok TINYINT NOT NULL DEFAULT 0,
  gate_url_ms INT NULL,
  net_ms INT NULL,
  radmin_ok TINYINT NOT NULL DEFAULT 0,
  agent_version VARCHAR(20) NULL,
  last_seen DATETIME NULL,
  created_at DATETIME NOT NULL
)");
// Upgrade database lama: tambahkan kolom yang belum ada
$have = $pdo->query("SHOW COLUMNS FROM devices")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('mode', $have)) $pdo->exec("ALTER TABLE devices ADD mode ENUM('gate','monitor') NOT NULL DEFAULT 'gate' AFTER gate_url");
if (!in_array('net_ms', $have)) $pdo->exec("ALTER TABLE devices ADD net_ms INT NULL AFTER gate_url_ms");
if (!in_array('camera_url', $have)) $pdo->exec("ALTER TABLE devices ADD camera_url VARCHAR(500) NOT NULL DEFAULT '' AFTER mode");
// Kode instalasi agent sekali pakai (username = ID laptop, password di-hash)
if (!in_array('enroll_hash', $have)) $pdo->exec("ALTER TABLE devices ADD enroll_hash VARCHAR(255) NULL, ADD enroll_expires DATETIME NULL, ADD enroll_fail INT NOT NULL DEFAULT 0, ADD enrolled_at DATETIME NULL, ADD enrolled_host VARCHAR(100) NULL");
if (!in_array('install_ticket', $have)) $pdo->exec("ALTER TABLE devices ADD install_ticket CHAR(64) NULL, ADD install_ticket_expires DATETIME NULL");
// ip = IP LAN yang dilaporkan agent (dipakai Radmin & kamera), public_ip = alamat yang terlihat server (beda bila server di hosting)
if (!in_array('public_ip', $have)) $pdo->exec("ALTER TABLE devices ADD public_ip VARCHAR(45) NULL AFTER ip");
// Wake-on-LAN (agent >= 1.7): daftar kartu jaringan fisik (JSON: nama, jenis kabel/wifi, MAC) dan kesiapan WoL di Windows
if (!in_array('nics', $have)) $pdo->exec("ALTER TABLE devices ADD nics TEXT NULL AFTER public_ip, ADD wol VARCHAR(500) NULL AFTER nics");
// gate_url boleh berisi beberapa URL (1 per baris): dibuka berdampingan kiri-kanan oleh agent >= 1.8
$pdo->exec("ALTER TABLE devices MODIFY gate_url VARCHAR(2000) NOT NULL DEFAULT ''");
// Pengaturan yang bisa diubah dari menu Pengaturan (menimpa nilai bawaan di config.php)
$pdo->exec("CREATE TABLE IF NOT EXISTS settings (k VARCHAR(50) PRIMARY KEY, v VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL)");
// Hasil scan dari halaman gate (ditangkap agent >= 1.6). Tanpa data pribadi: hanya status, kategori, gate, ID QR/barcode, waktu.
// sid = ID unik buatan agent per scan, agar kiriman ulang (saat koneksi putus) tidak tercatat dobel.
$pdo->exec("CREATE TABLE IF NOT EXISTS scans (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  sid VARCHAR(40) NOT NULL,
  gate VARCHAR(50) NOT NULL DEFAULT '',
  status ENUM('success','belum','notfound','error') NOT NULL,
  category VARCHAR(100) NOT NULL DEFAULT '',
  code VARCHAR(100) NOT NULL DEFAULT '',
  scanned_at DATETIME NOT NULL,
  UNIQUE KEY dev_sid (device_id, sid),
  INDEX dev_time (device_id, scanned_at),
  INDEX scan_time (scanned_at)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS commands (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  type VARCHAR(30) NOT NULL,
  payload TEXT NULL,
  status ENUM('pending','sent','done','failed') NOT NULL DEFAULT 'pending',
  result MEDIUMTEXT NULL,
  created_by VARCHAR(50) NOT NULL,
  created_at DATETIME NOT NULL,
  sent_at DATETIME NULL,
  done_at DATETIME NULL,
  INDEX (device_id, status)
)");
$pdo->exec("CREATE TABLE IF NOT EXISTS logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  message VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (created_at)
)");
// Kategori lokasi laptop (tab + warna card di dashboard)
$pdo->exec("CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#2563eb',
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL
)");
if (!in_array('category_id', $have)) $pdo->exec("ALTER TABLE devices ADD category_id INT NULL AFTER gate");
// Wallpaper laptop (agent >= 1.9): pustaka gambar, wallpaper per kategori, status terpasang per laptop
$pdo->exec("CREATE TABLE IF NOT EXISTS wallpapers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  sha256 CHAR(64) UNIQUE NOT NULL,
  ext VARCHAR(4) NOT NULL,
  bytes INT NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  created_by VARCHAR(50) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL
)");
if (!in_array('wallpaper_id', $pdo->query("SHOW COLUMNS FROM categories")->fetchAll(PDO::FETCH_COLUMN))) $pdo->exec("ALTER TABLE categories ADD wallpaper_id INT NULL");
if (!in_array('wallpaper_sha', $have)) $pdo->exec("ALTER TABLE devices ADD wallpaper_sha VARCHAR(64) NULL, ADD wallpaper_err VARCHAR(200) NULL");
if (!is_dir(__DIR__ . '/wallpaper')) @mkdir(__DIR__ . '/wallpaper', 0775);
if ($pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn() == 0) {
    $st = $pdo->prepare("INSERT INTO categories (name,color,sort,created_at) VALUES (?,?,?,NOW())");
    foreach ([['Gate', '#2563eb'], ['Tiket Box', '#9333ea'], ['Convention', '#0d9488'], ['Opening', '#db2777'], ['Lain-lain', '#64748b']] as $i => $c) {
        $st->execute([$c[0], $c[1], ($i + 1) * 10]);
    }
}
$n = $pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
if ($n == 0) {
    $st = $pdo->prepare("INSERT INTO admins (username,password_hash,role,created_at) VALUES (?,?,?,NOW())");
    $st->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 'supervisor']);
    $st->execute(['viewer', password_hash('viewer123', PASSWORD_DEFAULT), 'viewer']);
    echo "<p>Admin dibuat: <b>admin / admin123</b> (supervisor), <b>viewer / viewer123</b> (viewer). Segera ganti password di menu Admins.</p>";
}
echo "<p>Setup selesai. <b>HAPUS file setup.php sekarang.</b> Lanjut ke <a href='admin/login.php'>halaman login</a>.</p>";
