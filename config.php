<?php
// GateMon - konfigurasi server (nilai bawaan, ikut repo git: JANGAN isi password di sini).
// Nilai khusus server (password database, HTTPS, dll) ditulis di config.local.php, yang tidak ikut git
// dan menimpa nilai di bawah. Contoh: config.local.example.php.
$cfg = [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'gatemon',
        'user' => 'root',
        'pass' => '',
    ],
    // Nilai BAWAAN waktu (detik). Bisa diubah dari dashboard menu Pengaturan tanpa mengedit file ini.
    'heartbeat_interval' => 5,     // agent melapor tiap N detik
    'dashboard_refresh' => 3,      // dashboard & monitor mengambil data tiap N detik
    // Laptop dianggap OFFLINE bila tidak mengirim heartbeat selama N detik
    'offline_after' => 30,
    // URL default halaman validasi gate (ID gate ditambahkan per laptop di menu Devices)
    'default_gate_url' => 'https://sevenems.com/ems_giias_bdg/scan/form-scanning-gate.php?id=',
    // URL server yang ditulis ke config.json paket agent. Kosong = dideteksi otomatis (IP LAN server)
    'agent_server_url' => '',
    // Live camera mode "otomatis": Agent DVR di laptop itu sendiri. {ip} = IP laptop yang dilaporkan agent
    'camera_auto_url' => 'http://{ip}:8090/?viewIndex=0#Live',
    // Masa berlaku kode instalasi agent sekali pakai (jam)
    'enroll_valid_hours' => 24,
    'app_name' => 'GateMon',
    // ---------- keamanan (server publik / cPanel: atur di config.local.php) ----------
    'force_https' => false,          // true: semua akses HTTP dialihkan / ditolak, cookie hanya lewat HTTPS, HSTS aktif
    'trust_proxy' => false,          // true hanya bila di belakang proxy/CDN yang mengirim X-Forwarded-Proto/-For (mis. Cloudflare)
    'debug' => false,                // true: tampilkan pesan error PHP di browser (jangan di server publik)
    'session_idle_minutes' => 240,   // keluar otomatis bila tidak ada aktivitas (dashboard yang terbuka tetap aktif)
    'session_max_hours' => 24,       // wajib login ulang setelah ini
    'setup_key' => '',               // isi (min. 20 karakter) agar setup.php bisa dibuka dari luar server: setup.php?key=...
];
$local = __DIR__ . '/config.local.php';
if (is_file($local)) $cfg = array_replace_recursive($cfg, (array)require $local);
return $cfg;
