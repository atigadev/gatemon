<?php
// GateMon - konfigurasi server. Sesuaikan dengan XAMPP/Laragon Anda.
return [
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
];
