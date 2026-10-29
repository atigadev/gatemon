<?php
// Salin file ini menjadi config.local.php (tidak ikut git), lalu isi sesuai server.
// Hanya tulis nilai yang berbeda dari config.php.
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_gatemon',      // cPanel: nama database & user diawali nama akun cPanel
        'user' => 'cpaneluser_gatemon',
        'pass' => 'GANTI-dengan-password-database-yang-kuat',
    ],
    // Server publik: wajib HTTPS (aktifkan SSL/AutoSSL di cPanel dulu)
    'force_https' => true,
    // URL yang ditulis ke agent saat instalasi, contoh: https://monitor.domain-anda.com/gatemon
    'agent_server_url' => '',
    // Hanya untuk instalasi baru tanpa akses lokal: isi kunci acak >= 20 karakter, buka setup.php?key=KUNCI,
    // lalu KOSONGKAN lagi setelah selesai.
    'setup_key' => '',
];
