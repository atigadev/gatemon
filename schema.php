<?php
// Struktur database GateMon. Idempoten: aman dijalankan berulang (tabel/kolom yang sudah ada dilewati).
// Dijalankan otomatis oleh db() bila settings.schema_version < SCHEMA_VERSION (setelah kode diperbarui),
// juga oleh setup.php (instalasi baru) dan migrasi/import.php (setelah impor database server lama).
// NAIKKAN SCHEMA_VERSION setiap kali menambah tabel/kolom di bawah.
const SCHEMA_VERSION = 18;   // 2 = wallpaper (agent 1.9); 3 = wallpaper per laptop + kebijakan daya/Windows Update (agent 1.10); 4 = group kategori scan; 5 = chat support + darurat (agent 1.11); 6 = tombol support per laptop (agent 1.12); 7 = keamanan server publik; 8 = layar live (agent 1.14); 9 = peran petugas (operator); 10 = permintaan shutdown dari laptop (agent 1.19); 11 = peruntukan hasil scan (agent 1.20); 12-13 = lengkapi peruntukan dari halaman cek undangan; 14 = tampilan browser gate per laptop (agent 1.21); 15 = push notification (PWA); 16 = cetak langsung tanpa dialog per laptop (agent 1.22); 17 = status Agent DVR di laptop (agent 1.23); 18 = log kejadian per laptop (offline tercatat)

function schema_version(PDO $pdo) {
    try {
        return (int)$pdo->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn();
    } catch (PDOException $e) {
        return 0;   // tabel settings belum ada
    }
}

// Jalankan migrasi sekali; request lain yang datang bersamaan menunggu (GET_LOCK) lalu melewati.
// Mengembalikan daftar perubahan yang dilakukan.
// $only_if_old: lewati bila request lain sudah menyelesaikan migrasi selama menunggu.
function db_migrate(PDO $pdo, $only_if_old = false) {
    $pdo->query("SELECT GET_LOCK('gatemon_schema', 60)")->fetchColumn();
    try {
        if ($only_if_old && schema_version($pdo) >= SCHEMA_VERSION) return [];
        return schema_apply($pdo);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('gatemon_schema')")->fetchColumn();
    }
}

function schema_apply(PDO $pdo) {
    $done = [];
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $fresh = !in_array('devices', $tables, true);   // database baru: isi kategori bawaan
    $cols = function ($t) use ($pdo) {   // nama kolom => tipe
        $r = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `$t`")->fetchAll(PDO::FETCH_NUM) as $c) $r[$c[0]] = $c[1];
        return $r;
    };
    $table = function ($name, $sql) use ($pdo, $tables, &$done) {
        if (in_array($name, $tables, true)) return;
        $pdo->exec("CREATE TABLE `$name` ($sql)");
        $done[] = "tabel $name dibuat";
    };
    $add = function ($t, $have, $col, $sql) use ($pdo, &$done) {
        if (isset($have[$col])) return;
        $pdo->exec("ALTER TABLE `$t` $sql");
        $done[] = "kolom $t.$col ditambahkan";
    };

    $table('admins', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('viewer','operator','supervisor') NOT NULL DEFAULT 'viewer',
  created_at DATETIME NOT NULL");
    // keamanan akun: must_change = wajib ganti password saat login berikutnya (password lemah / direset supervisor)
    $add('admins', $cols('admins'), 'must_change', "ADD must_change TINYINT NOT NULL DEFAULT 0, ADD pw_changed_at DATETIME NULL, ADD last_login_at DATETIME NULL, ADD last_login_ip VARCHAR(45) NULL");
    // peran Petugas (operator): perintah ke agent kecuali reboot/shutdown, balas chat, kode instalasi
    if (strpos($cols('admins')['role'] ?? '', 'operator') === false) {
        $pdo->exec("ALTER TABLE admins MODIFY role ENUM('viewer','operator','supervisor') NOT NULL DEFAULT 'viewer'");
        $done[] = 'peran petugas (operator) ditambahkan';
    }
    // percobaan gagal (login admin, token agent, kode instalasi) untuk pembatasan; dibersihkan otomatis setelah 2 hari
    $table('auth_fail', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(10) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  name VARCHAR(100) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX scope_ip (scope, ip, created_at),
  INDEX scope_name (scope, name, created_at)");
    $table('devices', "
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
  wallpaper_id INT NULL,
  wallpaper_sha VARCHAR(64) NULL,
  wallpaper_err VARCHAR(200) NULL,
  power_state VARCHAR(20) NULL,
  winupdate_state VARCHAR(10) NULL,
  policy_err VARCHAR(200) NULL,
  last_seen DATETIME NULL,
  created_at DATETIME NOT NULL");
    // Upgrade database lama: tambahkan kolom yang belum ada
    $d = $cols('devices');
    $add('devices', $d, 'mode', "ADD mode ENUM('gate','monitor') NOT NULL DEFAULT 'gate' AFTER gate_url");
    $add('devices', $d, 'net_ms', "ADD net_ms INT NULL AFTER gate_url_ms");
    $add('devices', $d, 'camera_url', "ADD camera_url VARCHAR(500) NOT NULL DEFAULT '' AFTER mode");
    // Kode instalasi agent sekali pakai (username = ID laptop, password di-hash)
    $add('devices', $d, 'enroll_hash', "ADD enroll_hash VARCHAR(255) NULL, ADD enroll_expires DATETIME NULL, ADD enroll_fail INT NOT NULL DEFAULT 0, ADD enrolled_at DATETIME NULL, ADD enrolled_host VARCHAR(100) NULL");
    $add('devices', $d, 'install_ticket', "ADD install_ticket CHAR(64) NULL, ADD install_ticket_expires DATETIME NULL");
    // ip = IP LAN yang dilaporkan agent (dipakai Radmin & kamera), public_ip = alamat yang terlihat server (beda bila server di hosting)
    $add('devices', $d, 'public_ip', "ADD public_ip VARCHAR(45) NULL AFTER ip");
    // Wake-on-LAN (agent >= 1.7): kartu jaringan fisik (JSON: nama, jenis kabel/wifi, MAC) dan kesiapan WoL di Windows
    $add('devices', $d, 'nics', "ADD nics TEXT NULL AFTER public_ip, ADD wol VARCHAR(500) NULL AFTER nics");
    $add('devices', $d, 'category_id', "ADD category_id INT NULL AFTER gate");
    // Wallpaper yang terpasang di laptop (agent >= 1.9)
    $add('devices', $d, 'wallpaper_sha', "ADD wallpaper_sha VARCHAR(64) NULL, ADD wallpaper_err VARCHAR(200) NULL");
    // Wallpaper khusus laptop ini (NULL = ikut kategori/bawaan, 0 = jangan ubah, id = wallpaper tertentu) (agent >= 1.9)
    $add('devices', $d, 'wallpaper_id', "ADD wallpaper_id INT NULL AFTER agent_version");
    // Kebijakan daya & Windows Update yang sedang berlaku di laptop (agent >= 1.10)
    $add('devices', $d, 'power_state', "ADD power_state VARCHAR(20) NULL, ADD winupdate_state VARCHAR(10) NULL, ADD policy_err VARCHAR(200) NULL");
    // tombol Chat Support & DARURAT per laptop: NULL = ikut menu Pengaturan, 1 = tampil, 0 = sembunyi; support_state dilaporkan agent
    $add('devices', $d, 'support_overlay', "ADD support_overlay TINYINT NULL, ADD support_state VARCHAR(220) NULL");
    // tampilan browser gate per laptop (agent >= 1.21): NULL = ikut menu Pengaturan, 'kiosk' = layar penuh, 'window' = browser biasa
    $add('devices', $d, 'browser_view', "ADD browser_view VARCHAR(10) NULL AFTER mode");
    // cetak langsung ke printer default tanpa dialog (agent >= 1.22): NULL = ikut menu Pengaturan, 1 = ya, 0 = tidak
    $add('devices', $d, 'silent_print', "ADD silent_print TINYINT NULL AFTER browser_view");
    // Agent DVR (live camera) di laptop, dilaporkan agent >= 1.23: none / stopped:VERSI / running:VERSI / installing
    $add('devices', $d, 'dvr_state', "ADD dvr_state VARCHAR(60) NULL");
    // log kejadian per laptop: offline_since = last_seen saat "offline" dicatat (sekali per putus), dikosongkan heartbeat
    if (!isset($d['offline_since'])) {
        $pdo->exec("ALTER TABLE devices ADD offline_since DATETIME NULL AFTER last_seen");
        // laptop yang sudah offline sebelum fitur ini: jangan dicatat offline serentak saat pertama jalan
        $pdo->prepare("UPDATE devices SET offline_since=last_seen WHERE last_seen < NOW() - INTERVAL ? SECOND")->execute([in_array('settings', $tables, true) ? (int)setting('offline_after') : 60]);
        $done[] = 'kolom devices.offline_since ditambahkan';
    }
    // layar live (agent >= 1.14): sesi aktif selama live_until di masa depan (diperpanjang halaman penonton);
    // gambar terakhir disimpan sebagai file live/ID.jpg (ditimpa terus, dihapus saat sesi selesai)
    $add('devices', $d, 'live_until', "ADD live_until DATETIME NULL, ADD live_started DATETIME NULL, ADD live_by VARCHAR(50) NULL, ADD live_mode VARCHAR(10) NULL, ADD live_seq INT NOT NULL DEFAULT 0, ADD live_at DATETIME NULL, ADD live_err VARCHAR(200) NULL, ADD live_w INT NULL, ADD live_h INT NULL");
    // gate_url boleh berisi beberapa URL (1 per baris): dibuka berdampingan kiri-kanan oleh agent >= 1.8
    if (stripos($d['gate_url'] ?? '', 'varchar(2000)') === false) {
        $pdo->exec("ALTER TABLE devices MODIFY gate_url VARCHAR(2000) NOT NULL DEFAULT ''");
        $done[] = 'kolom devices.gate_url diperbesar';
    }

    // Pengaturan yang bisa diubah dari menu Pengaturan (menimpa nilai bawaan di config.php)
    $table('settings', "k VARCHAR(50) PRIMARY KEY, v VARCHAR(255) NOT NULL, updated_at DATETIME NOT NULL");
    // Hasil scan dari halaman gate (agent >= 1.6). Tanpa data pribadi: hanya status, kategori, gate, ID QR/barcode, waktu.
    // sid = ID unik buatan agent per scan, agar kiriman ulang (saat koneksi putus) tidak tercatat dobel.
    $table('scans', "
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
  INDEX scan_time (scanned_at)");
    // peruntukan dari balasan scan (agent >= 1.20; mis. kategori ASIC / EXH), untuk laporan per peruntukan
    $add('scans', $cols('scans'), 'peruntukan', "ADD peruntukan VARCHAR(100) NOT NULL DEFAULT '' AFTER category");
    // melengkapi peruntukan scan lama dari halaman cek undangan (menu Laporan): index per ID QR + hasil cek per ID
    if (!$pdo->query("SHOW INDEX FROM scans WHERE Key_name = 'code'")->fetch()) {
        $pdo->exec("ALTER TABLE scans ADD INDEX code (code)");
        $done[] = 'index scans.code ditambahkan';
    }
    // charset/collation mengikuti tabel scans (dipakai JOIN scans.code = purpose_lookup.code): database hasil impor
    // bisa punya collation tabel lama yang berbeda dari bawaan database -> "Illegal mix of collations"
    $sc = $pdo->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'scans' AND COLUMN_NAME = 'code'")->fetch(PDO::FETCH_NUM);
    $coll = $sc && preg_match('/^\w+$/', $sc[0] . $sc[1]) ? " DEFAULT CHARSET={$sc[0]} COLLATE={$sc[1]}" : '';
    if (!in_array('purpose_lookup', $tables, true)) {
        $pdo->exec("CREATE TABLE purpose_lookup (
  code VARCHAR(100) PRIMARY KEY,
  status ENUM('found','notfound','error') NOT NULL,
  peruntukan VARCHAR(100) NOT NULL DEFAULT '',
  category VARCHAR(100) NOT NULL DEFAULT '',
  err VARCHAR(200) NULL,
  checked_at DATETIME NOT NULL)$coll");
        $done[] = 'tabel purpose_lookup dibuat';
    } elseif ($sc) {
        $pc = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purpose_lookup' AND COLUMN_NAME = 'code'")->fetchColumn();
        if ($pc && $pc !== $sc[1] && $coll !== '') {
            $pdo->exec("ALTER TABLE purpose_lookup CONVERT TO CHARACTER SET {$sc[0]} COLLATE {$sc[1]}");
            $done[] = 'collation purpose_lookup disamakan dengan scans';
        }
    }
    $table('commands', "
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
  INDEX (device_id, status)");
    $table('logs', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NULL,
  level ENUM('info','warn','error') NOT NULL DEFAULT 'info',
  message VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX (created_at)");
    // log kejadian per laptop (menu Log)
    if (!$pdo->query("SHOW INDEX FROM logs WHERE Key_name = 'dev_time'")->fetch()) {
        $pdo->exec("ALTER TABLE logs ADD INDEX dev_time (device_id, created_at)");
        $done[] = 'index logs.dev_time ditambahkan';
    }
    // Kategori lokasi laptop (tab + warna card di dashboard); wallpaper_id: NULL = ikut bawaan, 0 = jangan ubah
    $table('categories', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) UNIQUE NOT NULL,
  color CHAR(7) NOT NULL DEFAULT '#2563eb',
  sort INT NOT NULL DEFAULT 0,
  wallpaper_id INT NULL,
  created_at DATETIME NOT NULL");
    $add('categories', $cols('categories'), 'wallpaper_id', "ADD wallpaper_id INT NULL");
    // Pustaka wallpaper laptop (agent >= 1.9), file di folder wallpaper/
    $table('wallpapers', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  sha256 CHAR(64) UNIQUE NOT NULL,
  ext VARCHAR(4) NOT NULL,
  bytes INT NOT NULL,
  width INT NOT NULL,
  height INT NOT NULL,
  created_by VARCHAR(50) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL");
    if (!is_dir(__DIR__ . '/wallpaper')) @mkdir(__DIR__ . '/wallpaper', 0775);
    if (!is_dir(__DIR__ . '/live')) @mkdir(__DIR__ . '/live', 0775);
    // Group kategori hasil scan untuk Laporan (kategori dari halaman gate -> group, mis. VIP -> ID-CARD).
    // Diisi pemetaan awal saat tabel dibuat; selanjutnya diatur dari menu Laporan.
    $new_groups = !in_array('scan_groups', $tables, true);
    $table('scan_groups', "
  category VARCHAR(100) PRIMARY KEY,
  grp VARCHAR(50) NOT NULL,
  updated_at DATETIME NOT NULL");
    if ($new_groups) {
        $st = $pdo->prepare("INSERT INTO scan_groups (category, grp, updated_at) VALUES (?, ?, NOW())");
        foreach (['EXHIBITOR' => 'ID-CARD', 'ASIC' => 'ASIC', 'EXH PASS' => 'EXH', 'EXH PASS PLUS' => 'EXH', 'MEDIA' => 'ID-CARD',
            'PARTNERS' => 'ID-CARD', 'ORGANIZER' => 'ID-CARD', 'CREW' => 'ID-CARD', 'ASIC PLUS' => 'ASIC', 'FOOD AND BEVERAGE' => 'ID-CARD',
            'SPECIAL GUEST' => 'ID-CARD', 'TEMPORARY' => 'ID-CARD', 'COMMITTEE' => 'ID-CARD', 'VIP' => 'ID-CARD'] as $c => $g) $st->execute([$c, $g]);
        $done[] = 'group kategori scan awal dibuat';
    }
    // Chat support & tombol darurat dari laptop (agent >= 1.11). sender: agent = petugas di laptop, admin = tim support,
    // system = pesan otomatis (mis. "darurat ditangani"). read_at: dibaca pihak seberang. cid: id kiriman agent (anti dobel).
    $table('support_msgs', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  device_id INT NOT NULL,
  sender ENUM('agent','admin','system') NOT NULL,
  author VARCHAR(50) NOT NULL DEFAULT '',
  kind ENUM('chat','emergency','shutdown') NOT NULL DEFAULT 'chat',
  body VARCHAR(1000) NOT NULL,
  cid VARCHAR(40) NULL,
  created_at DATETIME NOT NULL,
  read_at DATETIME NULL,
  ack_at DATETIME NULL,
  ack_by VARCHAR(50) NULL,
  decision ENUM('approved','rejected','cancelled') NULL,
  UNIQUE KEY dev_cid (device_id, cid),
  INDEX dev_id (device_id, id),
  INDEX kind_ack (kind, ack_at)");
    // Permintaan shutdown dari laptop (agent >= 1.19): kind shutdown, diputuskan petugas/supervisor (ack_at/ack_by + decision)
    $sm = $cols('support_msgs');
    if (strpos($sm['kind'] ?? '', 'shutdown') === false) {
        $pdo->exec("ALTER TABLE support_msgs MODIFY kind ENUM('chat','emergency','shutdown') NOT NULL DEFAULT 'chat'");
        $done[] = 'permintaan shutdown dari laptop ditambahkan';
    }
    $add('support_msgs', $sm, 'decision', "ADD decision ENUM('approved','rejected','cancelled') NULL AFTER ack_by");

    // Push notification ke HP/PC (PWA, Web Push): satu baris per browser yang mengaktifkan notifikasi (menu Notifikasi).
    // ep_hash = sha256(endpoint) untuk UNIQUE (endpoint terlalu panjang untuk index). Kunci VAPID server di tabel settings.
    $table('push_subs', "
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  ep_hash CHAR(64) NOT NULL,
  endpoint VARCHAR(600) NOT NULL,
  p256dh VARCHAR(120) NOT NULL,
  auth VARCHAR(40) NOT NULL,
  want_emg TINYINT NOT NULL DEFAULT 1,
  want_chat TINYINT NOT NULL DEFAULT 1,
  ua VARCHAR(150) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  last_ok DATETIME NULL,
  last_err VARCHAR(200) NULL,
  fails INT NOT NULL DEFAULT 0,
  UNIQUE KEY ep_hash (ep_hash),
  INDEX admin_id (admin_id)");

    // Kategori bawaan hanya untuk database baru (kategori yang sengaja dihapus tidak muncul lagi)
    if ($fresh) {
        $st = $pdo->prepare("INSERT INTO categories (name,color,sort,created_at) VALUES (?,?,?,NOW())");
        foreach ([['Gate', '#2563eb'], ['Tiket Box', '#9333ea'], ['Convention', '#0d9488'], ['Opening', '#db2777'], ['Lain-lain', '#64748b']] as $i => $c) {
            $st->execute([$c[0], $c[1], ($i + 1) * 10]);
        }
        $done[] = 'kategori bawaan dibuat';
    }
    $pdo->prepare("REPLACE INTO settings (k, v, updated_at) VALUES ('schema_version', ?, NOW())")->execute([(string)SCHEMA_VERSION]);
    return $done;
}
