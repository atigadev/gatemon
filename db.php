<?php
$CFG = require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Jakarta');
require_once __DIR__ . '/security.php';
security_bootstrap();

function db() {
    static $pdo = null;
    global $CFG;
    if ($pdo === null) {
        $c = $CFG['db'];
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4", $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        // NOW() di MySQL harus sama dengan jam PHP (Asia/Jakarta). Tanpa ini, server yang zona waktu Windows-nya lain
        // (mis. UTC+8) mencatat last_seen 1 jam "di masa depan": laptop yang sudah mati tetap tampil online ±1 jam.
        $pdo->exec("SET time_zone = '" . date('P') . "'");
        // Struktur database mengikuti versi kode: setelah kode diperbarui (git pull / salin file), migrasi berjalan
        // otomatis sekali pada request pertama, tanpa membuka setup.php. Gagal (mis. user MySQL tanpa hak ALTER)
        // dicatat di error log PHP; setup.php menampilkan pesan lengkapnya.
        require_once __DIR__ . '/schema.php';
        if (schema_version($pdo) < SCHEMA_VERSION) {
            try {
                if ($done = db_migrate($pdo, true)) log_event(null, 'info', mb_substr('Struktur database diperbarui ke versi ' . SCHEMA_VERSION . ': ' . implode(', ', $done), 0, 500));
            } catch (Exception $e) {
                error_log('GateMon: migrasi database gagal: ' . $e->getMessage());
            }
        }
    }
    return $pdo;
}

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $out = json_encode($data, JSON_UNESCAPED_UNICODE);
    // gzip untuk balasan besar (data dashboard): hemat 70-85% trafik; balasan kecil (heartbeat) tidak perlu
    if (strlen($out) > 1024 && function_exists('gzencode') && stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false
        && !ini_get('zlib.output_compression')) {
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        $out = gzencode($out, 6);
    }
    header('Content-Length: ' . strlen($out));
    echo $out;
    exit;
}

// CA bundle untuk https keluar (cek peruntukan, push notification): php.ini tanpa openssl.cafile / curl.cainfo
// memakai CA bundle bawaan XAMPP bila ada
function ca_bundle() {
    foreach ([ini_get('openssl.cafile'), ini_get('curl.cainfo'), dirname(PHP_BINARY) . '/../apache/bin/curl-ca-bundle.crt',
              'C:/xampp/apache/bin/curl-ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $f) {
        if ($f && is_file($f)) return $f;
    }
    return null;
}

// Balas JSON sekarang lalu lanjutkan proses (mis. kirim push) tanpa membuat agent menunggu
function json_out_early($data) {
    ignore_user_abort(true);
    $out = json_encode($data, JSON_UNESCAPED_UNICODE);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($out));
    header('Connection: close');
    echo $out;
    while (ob_get_level() > 0) ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
}
function json_in() {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function log_event($device_id, $level, $message) {
    db()->prepare("INSERT INTO logs (device_id, level, message, created_at) VALUES (?,?,?,NOW())")
        ->execute([$device_id, $level, $message]);
}

// Lama waktu yang mudah dibaca: 45 dtk, 12 menit, 3 jam 5 menit, 2 hari 4 jam
function dur_txt($sec) {
    $sec = max(0, (int)$sec);
    if ($sec < 60) return $sec . ' dtk';
    if ($sec < 3600) return intdiv($sec, 60) . ' menit';
    if ($sec < 86400) return intdiv($sec, 3600) . ' jam' . ($sec % 3600 >= 60 ? ' ' . intdiv($sec % 3600, 60) . ' menit' : '');
    return intdiv($sec, 86400) . ' hari' . ($sec % 86400 >= 3600 ? ' ' . intdiv($sec % 86400, 3600) . ' jam' : '');
}

// Catat "offline" sekali per putus untuk laptop yang berhenti melapor (dipanggil heartbeat laptop lain & dashboard).
// offline_since diisi lebih dulu dengan syarat masih kosong, jadi dua pemanggil bersamaan tidak mencatat dobel.
function log_offline_devices() {
    $pdo = db();
    $off = (int)setting('offline_after');
    $st = $pdo->prepare("SELECT id, name, last_seen FROM devices WHERE last_seen IS NOT NULL AND offline_since IS NULL AND last_seen < NOW() - INTERVAL ? SECOND");
    $st->execute([$off]);
    $up = $pdo->prepare("UPDATE devices SET offline_since=last_seen WHERE id=? AND offline_since IS NULL AND last_seen < NOW() - INTERVAL ? SECOND");
    foreach ($st->fetchAll() as $d) {
        $up->execute([$d['id'], $off]);
        if ($up->rowCount()) log_event($d['id'], 'warn', "{$d['name']} offline (tidak melapor sejak " . date('d/m H:i:s', strtotime($d['last_seen'])) . ')');
    }
}

// Jenis kejadian di log (menu Log): kunci => [label, pola LIKE pesan]
const LOG_KINDS = [
    'offline'  => ['Offline / online', ['% offline (%', '% online', '% online (%']],
    'browser'  => ['Browser gate', ['%browser gate%']],
    'gatepage' => ['Halaman gate', ['%halaman gate%']],
    'darurat'  => ['Darurat', ['%tombol DARURAT%', '%darurat ditangani%']],
    'shutdown' => ['Minta shutdown', ['%izin shutdown%', '%permintaan shutdown%']],
    'command'  => ['Perintah', ['% mengirim %', '%: % done%', '%: % failed%']],
    'agent'    => ['Agent & instalasi', ['%instalasi%', '%uninstall%', '%Paket agent%', '%paket instalasi%', '%dipakai lebih dari satu komputer%', '%UPDATE_AGENT%', '%INSTALL_DVR%']],
    'device'   => ['Wallpaper, daya, support, DVR', ['%wallpaper%', '%kebijakan daya%', '%tombol support%', '%Agent DVR%', '%layar live%', '%Layar live%']],
];
function log_kind_sql($kind, &$params) {
    $pats = LOG_KINDS[$kind][1] ?? [];
    if (!$pats) return '1=1';
    foreach ($pats as $p) $params[] = $p;
    // catatan migrasi database bisa memuat kata kunci jenis lain (mis. "permintaan shutdown"): bukan kejadian
    return "(l.message NOT LIKE 'Struktur database%' AND (" . implode(' OR ', array_fill(0, count($pats), 'l.message LIKE ?')) . '))';
}
function log_kind_of($msg) {
    if (strpos($msg, 'Struktur database') === 0) return '';
    foreach (LOG_KINDS as $k => [, $pats]) {
        foreach ($pats as $p) {   // pola LIKE -> regex (% = apa saja), tanpa beda huruf besar/kecil seperti MySQL
            $re = '/^' . implode('.*', array_map(function ($s) { return preg_quote($s, '/'); }, explode('%', $p))) . '$/isu';
            if (preg_match($re, $msg)) return $k;
        }
    }
    return '';
}

// CSV untuk Excel (BOM, titik koma); sel yang diawali = + - @ diberi awalan ' (CSV injection)
function csv_cell($v) {
    $v = (string)$v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v;
    return '"' . str_replace('"', '""', $v) . '"';
}
function csv_send($name, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    // BOM agar Excel membaca UTF-8; titik koma = pemisah daftar Excel berbahasa Indonesia
    echo "\xEF\xBB\xBF";
    foreach ($rows as $r) echo implode(';', array_map('csv_cell', $r)), "\r\n";
    exit;
}

// Autentikasi agent via header X-Device-Key + X-Device-Token
function auth_device() {
    $key = $_SERVER['HTTP_X_DEVICE_KEY'] ?? '';
    $tok = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? '';
    if ($key === '' || $tok === '') json_out(['ok' => false, 'error' => 'missing credentials'], 401);
    // Token 128-bit acak tidak bisa ditebak; batas ini menahan banjir request. Longgar karena di hosting semua laptop
    // satu venue keluar lewat satu IP publik: satu laptop bertoken lama (heartbeat tiap 5 dtk) tidak boleh memblokir semuanya.
    $ip = client_ip();
    if (auth_fail_count('device', $ip, null, 10) >= 600) json_out(['ok' => false, 'error' => 'terlalu banyak percobaan, coba lagi nanti'], 429);
    $st = db()->prepare("SELECT * FROM devices WHERE device_key = ?");
    $st->execute([(string)$key]);
    $d = $st->fetch();
    if (!$d || !hash_equals((string)$d['token'], (string)$tok)) {
        auth_fail_add('device', $ip, $key);
        json_out(['ok' => false, 'error' => 'unauthorized'], 401);
    }
    return $d;
}

function device_state($d, $offline_after) {
    if (!$d['last_seen']) return 'offline';
    $age = time() - strtotime($d['last_seen']);
    if ($age > $offline_after) return 'offline';
    if (($d['mode'] ?? 'gate') === 'monitor') return 'ok'; // hanya pantau koneksi
    if (!$d['browser_running'] || !$d['gate_url_ok']) return 'warn';
    return 'ok';
}

// Link live camera yang dipakai dashboard. camera_url: '' = tidak ada, 'auto' = Agent DVR di laptop itu
// (template camera_auto_url), selain itu URL custom (boleh memakai {ip}). '' bila IP laptop belum diketahui.
function camera_link($d) {
    global $CFG;
    $u = (string)($d['camera_url'] ?? '');
    if ($u === '') return '';
    if ($u === 'auto') $u = $CFG['camera_auto_url'] ?? 'http://{ip}:8090/?viewIndex=0#Live';
    if (strpos($u, '{ip}') !== false) {
        if (empty($d['ip'])) return '';
        $u = str_replace('{ip}', $d['ip'], $u);
    }
    return $u;
}

// Verifikasi kode instalasi sekali pakai (username = ID laptop). Berhasil: kode hangus + token baru.
// Dipakai halaman /install/ID dan api/enroll.php. Hasil: ['ok'=>true,'device'=>..,'token'=>..] atau ['ok'=>false,'error'=>..,'code'=>HTTP]
function enroll_verify($user, $pass, $from) {
    $pdo = db();
    $user = strtoupper(trim((string)$user));
    $pass = strtoupper(preg_replace('/[\s-]/', '', (string)$pass));
    $no = function ($msg, $code = 401) { usleep(800000); return ['ok' => false, 'error' => $msg, 'code' => $code]; };
    if ($user === '' || $pass === '') return $no('username dan password wajib diisi', 400);
    // selain kunci 5x salah per laptop: satu IP yang mencoba banyak laptop ditahan 15 menit
    $ip = client_ip();
    if (auth_fail_count('enroll', $ip) >= 20) return $no('terlalu banyak percobaan dari alamat ini, coba lagi 15 menit lagi', 429);
    $st = $pdo->prepare("SELECT * FROM devices WHERE device_key=?");
    $st->execute([$user]);
    $d = $st->fetch();
    if (!$d || !$d['enroll_hash']) { auth_fail_add('enroll', $ip, $user); return $no('username atau password salah, atau kode instalasi belum dibuat'); }
    if ($d['enroll_fail'] >= 5) return $no('kode instalasi terkunci karena terlalu banyak percobaan salah; minta supervisor membuat kode baru', 423);
    if ($d['enroll_expires'] && strtotime($d['enroll_expires']) < time()) return $no('kode instalasi sudah kedaluwarsa; minta supervisor membuat kode baru', 410);
    if (!password_verify($pass, $d['enroll_hash'])) {
        $pdo->prepare("UPDATE devices SET enroll_fail=enroll_fail+1 WHERE id=?")->execute([$d['id']]);
        auth_fail_add('enroll', $ip, $user);
        log_event($d['id'], 'warn', "{$d['name']}: percobaan instalasi agent dengan password salah" . ($from ? " dari $from" : ''));
        return $no('username atau password salah');
    }
    // kondisi enroll_hash=? mencegah kode yang sama dipakai dua kali bersamaan
    $token = bin2hex(random_bytes(16));
    $upd = $pdo->prepare("UPDATE devices SET token=?, enroll_hash=NULL, enroll_expires=NULL, enroll_fail=0, enrolled_at=NOW(), enrolled_host=? WHERE id=? AND enroll_hash=?");
    $upd->execute([$token, substr($from, 0, 100), $d['id'], $d['enroll_hash']]);
    if ($upd->rowCount() !== 1) return $no('kode instalasi sudah dipakai', 409);
    log_event($d['id'], 'info', "{$d['name']}: kode instalasi dipakai" . ($from ? " dari $from" : ''));
    $d['token'] = $token;
    return ['ok' => true, 'device' => $d, 'token' => $token];
}

// config.json agent untuk laptop ini
function agent_config($d, $server) {
    $example = json_decode(@file_get_contents(__DIR__ . '/agent/config.example.json'), true) ?: [];
    // install_dvr: saran untuk installer agent (pasang Agent DVR bila live camera laptop ini "Agent DVR di laptop ini")
    return array_merge($example, ['server_url' => $server, 'device_key' => $d['device_key'], 'token' => $d['token'], 'gate_url' => '',
        'install_dvr' => ($d['camera_url'] ?? '') === 'auto', 'dvr_port' => dvr_port()]);
}

// Isi paket agent ke ZIP: file agent + isi agent/installer (python-*.exe, wheels\). Mengembalikan daftar file tambahan.
function agent_zip_files($zip, $folder) {
    $dir = __DIR__ . '/agent';
    foreach (['agent.py', 'requirements.txt', 'install_agent.bat', 'install_agent.ps1', 'uninstall_agent.bat', 'uninstall_agent.ps1'] as $f) $zip->addFile("$dir/$f", $folder . $f);
    $extra = [];
    if (is_dir("$dir/installer")) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$dir/installer", FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = strtr(substr($file->getPathname(), strlen("$dir/installer") + 1), DIRECTORY_SEPARATOR, '/');
            if ($rel === 'BACA-SAYA.txt' || $rel[0] === '.') continue;
            $zip->addFile($file->getPathname(), $folder . $rel);
            $extra[] = $rel;
        }
    }
    return $extra;
}

// ---------- pengaturan (tabel settings, diubah dari menu Pengaturan; bawaan dari config.php) ----------
function settings_spec() {
    global $CFG;
    return [
        'heartbeat_interval' => ['label' => 'Interval heartbeat agent', 'unit' => 'detik', 'min' => 2, 'max' => 300, 'def' => $CFG['heartbeat_interval'] ?? 5,
            'help' => 'Seberapa sering setiap laptop melapor ke server dan mengambil perintah. Makin kecil makin cepat, tapi beban server dan jaringan naik.'],
        'offline_after' => ['label' => 'Dianggap offline setelah', 'unit' => 'detik', 'min' => 6, 'max' => 3600, 'def' => $CFG['offline_after'] ?? 30,
            'help' => 'Laptop dianggap OFFLINE bila tidak melapor selama ini. Minimal 3x interval heartbeat agar tidak salah alarm.'],
        'dashboard_refresh' => ['label' => 'Refresh dashboard & monitor', 'unit' => 'detik', 'min' => 1, 'max' => 60, 'def' => $CFG['dashboard_refresh'] ?? 3,
            'help' => 'Seberapa sering Dashboard dan layar Monitor mengambil data terbaru dari server.'],
        'enroll_valid_hours' => ['label' => 'Masa berlaku kode instalasi', 'unit' => 'jam', 'min' => 1, 'max' => 720, 'def' => $CFG['enroll_valid_hours'] ?? 24,
            'help' => 'Berapa lama password sekali pakai untuk instalasi agent berlaku setelah dibuat.'],
        // halaman gate otomatis (agent >= 1.3, laptop mode gate)
        'auto_open_gate' => ['label' => 'Buka halaman gate otomatis', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 1,
            'help' => 'Saat laptop mode gate terhubung ke server (setelah login Windows / agent start), browser gate langsung dibuka.'],
        'auto_reopen_gate' => ['label' => 'Buka ulang bila browser gate tertutup', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 1,
            'help' => 'Bila jendela gate tertutup (tidak sengaja atau crash), agent membukanya lagi setelah jeda di bawah. Matikan saat perawatan laptop.'],
        'reopen_after' => ['label' => 'Jeda sebelum dibuka ulang', 'unit' => 'detik', 'min' => 5, 'max' => 600, 'def' => 15,
            'help' => 'Berapa lama browser gate boleh tertutup sebelum agent membukanya lagi.'],
        'gate_kiosk' => ['label' => 'Browser gate layar penuh (kiosk)', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 1,
            'help' => 'Aktif = kiosk: layar penuh tanpa address bar / tombol back & refresh (paling aman, petugas tidak bisa membuka situs lain). Mati = browser biasa (jendela penuh dengan address bar, back, refresh, tab). Bisa diatur per laptop di menu Devices. Berubah = browser gate dibuka ulang. Agent 1.21+.'],
        'gate_silent_print' => ['label' => 'Cetak langsung tanpa dialog print', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 0,
            'help' => 'Print dari halaman gate langsung keluar di printer default Windows laptop itu, tanpa jendela pilihan printer (opsi --kiosk-printing Chrome / Edge). Atur printer default di Windows laptop. Bisa diatur per laptop di menu Devices. Berubah = browser gate dibuka ulang. Agent 1.22+.'],
        // hemat internet & beban server halaman gate (agent >= 1.5)
        'gate_check_interval' => ['label' => 'Cek halaman gate setiap', 'unit' => 'detik', 'min' => 10, 'max' => 600, 'def' => 30,
            'help' => 'Agent mengecek apakah halaman gate (internet) bisa diakses. Cek ringan (HEAD, hanya status), tidak tiap heartbeat, agar tidak membebani internet venue dan server halaman gate.'],
        'screenshot_keep_days' => ['label' => 'Simpan screenshot selama', 'unit' => 'hari', 'min' => 1, 'max' => 90, 'def' => 7,
            'help' => 'Screenshot yang lebih lama dihapus otomatis dari database agar tidak terus membesar.'],
        // hasil scan halaman gate (agent >= 1.6)
        'scan_capture' => ['label' => 'Tangkap hasil scan', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 1,
            'help' => 'Agent membaca hasil scan dari browser gate (berhasil / belum waktunya / tidak ditemukan). Disimpan: status, kategori, peruntukan, gate, ID QR/barcode, waktu. Nama & perusahaan tidak disimpan.'],
        'scan_page_match' => ['label' => 'Pola URL halaman scan', 'type' => 'text', 'def' => 'form-scanning-gate',
            'help' => 'Hanya laptop mode gate yang URL halaman gate-nya mengandung salah satu pola ini (pisahkan dengan koma) yang dianggap laptop scan: datanya ditangkap & ditampilkan. Laptop dengan link lain tidak.'],
        'scan_api_match' => ['label' => 'Pola URL proses scan', 'type' => 'text', 'def' => 'proses_scan_gate',
            'help' => 'Request di halaman scan yang dibaca hasilnya (balasan JSON berisi status). Pisahkan dengan koma.'],
        'scan_purpose_key' => ['label' => 'Field peruntukan', 'type' => 'text', 'def' => 'peruntukan',
            'help' => 'Nama field di balasan scan yang berisi peruntukan (mis. untuk kategori ASIC / EXH), untuk laporan per peruntukan. Pisahkan dengan koma bila namanya bisa berbeda. Agent 1.20+.'],
        'purpose_lookup_url' => ['label' => 'Link cek peruntukan', 'type' => 'text', 'url' => true, 'def' => 'https://toems.saturegis.com/index.php?id={id}',
            'help' => 'Halaman cek undangan per ID QR ({id} diganti ID QR). Dipakai tombol "Lengkapi peruntukan" di menu Laporan untuk scan yang peruntukannya belum terdeteksi. Harus https.'],
        'scan_keep_days' => ['label' => 'Simpan data scan selama', 'unit' => 'hari', 'min' => 1, 'max' => 365, 'def' => 30,
            'help' => 'Data scan yang lebih lama dihapus otomatis.'],
        'scan_fail_alert' => ['label' => 'Peringatan scan tidak ditemukan', 'unit' => 'per menit', 'min' => 0, 'max' => 100, 'def' => 5,
            'help' => 'Bunyikan peringatan bila satu laptop mendapat sebanyak ini scan "tidak ditemukan" dalam 1 menit. 0 = mati.'],
        // chat support & tombol darurat di laptop (agent >= 1.11)
        'support_overlay' => ['label' => 'Tombol Chat Support & DARURAT di laptop', 'type' => 'bool', 'min' => 0, 'max' => 1, 'def' => 1,
            'help' => 'Tombol kecil di pojok kanan bawah layar laptop: petugas bisa chat dengan tim support (menu Support) dan menekan DARURAT yang langsung membunyikan alarm di halaman admin.'],
    ];
}
function settings_all() {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try { foreach (db()->query("SELECT k, v FROM settings") as $r) $cache[$r['k']] = $r['v']; } catch (Exception $e) {} // tabel belum ada: pakai bawaan
    }
    return $cache;
}
function setting($k) {
    $cache = settings_all();
    $s = settings_spec()[$k];
    if (($s['type'] ?? '') === 'text') {
        $v = trim((string)($cache[$k] ?? ''));
        return $v !== '' ? $v : $s['def'];
    }
    $v = isset($cache[$k]) ? (int)$cache[$k] : (int)$s['def'];
    return max($s['min'], min($s['max'], $v));
}

// Daftar pola dari pengaturan teks (dipisah koma), huruf kecil
function setting_patterns($k) {
    return array_values(array_filter(array_map(function ($p) { return strtolower(trim($p)); }, explode(',', setting($k)))));
}

// URL halaman gate yang dipakai laptop (URL di menu Devices, atau URL default + ID laptop)
function effective_gate_url($d) {
    $u = gate_urls_of($d);
    return $u ? $u[0] : '';
}
// Semua URL halaman yang dibuka otomatis (1 per baris di menu Devices, maks 4). >1 URL = jendela berdampingan.
const GATE_URL_MAX = 4;
function gate_urls_of($d) {
    global $CFG;
    if (($d['mode'] ?? 'gate') === 'monitor') return [];
    $list = [];
    foreach (preg_split('/\R+/', (string)($d['gate_url'] ?? '')) as $u) {
        $u = trim($u);
        if ($u !== '' && preg_match('#^https?://[^\s"\'<>]+$#i', $u) && !in_array($u, $list, true)) $list[] = $u;
    }
    return $list ? array_slice($list, 0, GATE_URL_MAX) : [$CFG['default_gate_url'] . rawurlencode($d['device_key'])];
}

// "Laptop scan": mode gate, fitur tangkap scan aktif, dan URL halaman gate cocok dengan pola halaman scan.
// Hanya laptop ini yang diminta menangkap scan, yang datanya diterima server, dan yang menampilkan data scan.
function scan_enabled($d) {
    if (!setting('scan_capture')) return false;
    foreach (gate_urls_of($d) as $url) {
        foreach (setting_patterns('scan_page_match') as $p) if (strpos(strtolower($url), $p) !== false) return true;
    }
    return false;
}

// Versi agent di server (dibaca dari agent/agent.py: VERSION = "x.y.z"), dipakai untuk nama file instalasi.
function agent_version() {
    static $v = null;
    if ($v === null) {
        $v = preg_match('/^VERSION\s*=\s*"([0-9][0-9A-Za-z.\-]*)"/m', (string)@file_get_contents(__DIR__ . '/agent/agent.py'), $m) ? $m[1] : '0';
    }
    return $v;
}

// ---------- update agent dari server (agent >= 1.9, menu Agent) ----------
const AGENT_UPDATE_MIN = '1.9.0';   // agent mulai versi ini bisa diupdate otomatis; yang lebih lama dipasang ulang manual
// Rilis bertanda tangan di agent/release.json + release.sig (dibuat admin-tools/rilis-agent.bat).
// ready = boleh dikirim ke laptop: tanda tangan ada & file di server sama dengan yang ditandatangani.
function agent_release() {
    $dir = __DIR__ . '/agent';
    $r = ['version' => null, 'created' => null, 'ready' => false, 'sig_ok' => null, 'problems' => []];
    $raw = @file_get_contents("$dir/release.json");
    $sig = trim((string)@file_get_contents("$dir/release.sig"));
    $m = $raw ? json_decode($raw, true) : null;
    if (!$m || !preg_match('/^[0-9a-f]{128}$/', $sig)) { $r['problems'][] = 'Belum ada rilis bertanda tangan.'; return $r; }
    $r['version'] = $m['version'] ?? null;
    $r['created'] = $m['created'] ?? null;
    $pk = preg_match('/^UPDATE_PUBKEY = "([0-9a-f]{64})"/m', (string)@file_get_contents("$dir/agent.py"), $mm) ? $mm[1] : '';
    if ($pk === '') $r['problems'][] = 'Kunci publik update belum diisi di agent.py.';
    foreach ($m['files'] ?? [] as $f) {
        if (@hash_file('sha256', "$dir/{$f['path']}") !== $f['sha256']) $r['problems'][] = "{$f['path']} di server berbeda dengan yang ditandatangani (tanda tangani ulang).";
    }
    foreach ($m['wheels'] ?? [] as $w) {
        if (@filesize("$dir/installer/wheels/{$w['file']}") !== $w['size']) $r['problems'][] = "Wheel {$w['file']} hilang / berubah.";
    }
    $r['dvr'] = null;   // installer Agent DVR di rilis: siap bila file di server sama dengan yang ditandatangani
    if (!empty($m['dvr']['file'])) {
        $ok = @hash_file('sha256', "$dir/installer/" . basename($m['dvr']['file'])) === ($m['dvr']['sha256'] ?? '');
        $r['dvr'] = ['file' => basename($m['dvr']['file']), 'ok' => $ok];
    }
    if ($pk !== '' && function_exists('sodium_crypto_sign_verify_detached')) {   // tanpa ekstensi sodium: diperiksa agent saja
        $r['sig_ok'] = sodium_crypto_sign_verify_detached(hex2bin($sig), $raw, hex2bin($pk));
        if (!$r['sig_ok']) $r['problems'][] = 'Tanda tangan tidak cocok dengan kunci publik di agent.py.';
    }
    $r['ready'] = !$r['problems'];
    return $r;
}

// ---------- Agent DVR (live camera) di laptop (agent >= 1.23, menu Agent) ----------
// Installer resmi (agent/installer/AgentDVRSetup*.exe, web installer NSIS: mengunduh Agent DVR dari internet) ikut
// dalam rilis bertanda tangan (release.json "dvr"): agent hanya menjalankan file yang sha256-nya cocok dengan rilis
// dan bertanda tangan Authenticode resmi pembuat Agent DVR.
const DVR_MIN = '1.23.0';
function dvr_installer() {
    $f = glob(__DIR__ . '/agent/installer/AgentDVRSetup*.exe') ?: [];
    return $f ? $f[0] : null;
}
// Port web Agent DVR dari template camera_auto_url (bawaan 8090): dibuka di firewall laptop untuk jaringan lokal
function dvr_port() {
    global $CFG;
    $p = (int)parse_url(str_replace('{ip}', 'x', (string)($CFG['camera_auto_url'] ?? '')), PHP_URL_PORT);
    return $p > 0 && $p < 65536 ? $p : 8090;
}
// [kelas status, teks] dari devices.dvr_state
function dvr_label($state) {
    $s = (string)$state;
    if ($s === '') return ['none', 'Belum dilaporkan'];
    if ($s === 'none') return ['none', 'Tidak terpasang'];
    if ($s === 'installing') return ['pending', 'Sedang dipasang…'];
    [$k, $v] = array_pad(explode(':', $s, 2), 2, '');
    if ($k === 'running') return ['ok', 'Berjalan' . ($v !== '' ? " ($v)" : '')];
    if ($k === 'stopped') return ['warn', 'Terpasang, tidak berjalan' . ($v !== '' ? " ($v)" : '')];
    return ['offline', $s];
}

// ---------- wallpaper laptop (agent >= 1.9, menu Wallpaper) ----------
// File di folder wallpaper/ (ditutup dari web), nama = sha256 isi file. Agent mengunduh lewat api/wallpaper.php.
const WALLPAPER_DIR = __DIR__ . '/wallpaper';
const WALLPAPER_STYLES = ['fill' => 'Isi layar (Fill)', 'fit' => 'Pas (Fit)', 'stretch' => 'Regangkan (Stretch)', 'center' => 'Tengah (Center)', 'tile' => 'Ubin (Tile)', 'span' => 'Bentang semua layar (Span)'];
// Penerapan disimpan di tabel settings (wallpaper_default, wallpaper_style, wallpaper_lock), di luar settings_spec
function wallpaper_cfg() {
    $s = settings_all();
    $style = $s['wallpaper_style'] ?? 'fill';
    return ['default' => (int)($s['wallpaper_default'] ?? 0), 'style' => isset(WALLPAPER_STYLES[$style]) ? $style : 'fill',
        'lock' => (int)($s['wallpaper_lock'] ?? 0)];
}
function wallpaper_path($w) { return WALLPAPER_DIR . '/' . $w['sha256'] . '.' . $w['ext']; }
// id wallpaper tujuan untuk satu laptop, berlapis: laptop -> kategori -> bawaan.
// Di tiap lapis: NULL = ikut lapis berikutnya, 0 = jangan ubah (berhenti), >0 = wallpaper tertentu.
// Kembali: >0 id wallpaper, atau 0 = GateMon tidak mengatur wallpaper laptop ini.
function wallpaper_target_id($d) {
    $dev = $d['wallpaper_id'] ?? null;
    if ($dev !== null && $dev !== '') return (int)$dev;                 // khusus laptop (0 atau id)
    if (!empty($d['category_id'])) {
        $st = db()->prepare("SELECT wallpaper_id FROM categories WHERE id=?");
        $st->execute([$d['category_id']]);
        $cat = $st->fetchColumn();
        if ($cat !== false && $cat !== null) return (int)$cat;          // ikut kategori (0 atau id)
    }
    return wallpaper_cfg()['default'];                                   // bawaan (0 atau id)
}
// Baris wallpaper untuk laptop, atau null (tidak diatur / tabel belum ada / wallpaper terhapus).
function wallpaper_for($d) {
    try {
        $id = wallpaper_target_id($d);
        if ($id <= 0) return null;
        $st = db()->prepare("SELECT id, name, sha256, ext, bytes FROM wallpapers WHERE id=?");
        $st->execute([$id]);
        $w = $st->fetch();
    } catch (Exception $e) { return null; }   // tabel belum ada (setup.php belum dijalankan)
    return $w ?: null;
}

// ---------- kebijakan daya & Windows Update (agent >= 1.10, menu Daya) ----------
// Global, disimpan di tabel settings (power_mode, winupdate), di luar settings_spec. "" = jangan ubah.
const POWER_MODES = ['' => 'Jangan ubah (biarkan apa adanya)', 'always_on' => 'Selalu aktif - tidak tidur, layar tetap menyala',
    'balanced' => 'Seimbang - layar mati 10 mnt, tidur 30 mnt', 'saver' => 'Hemat daya - layar mati 5 mnt, tidur 15 mnt'];
const WINUPDATE_MODES = ['' => 'Jangan ubah (biarkan apa adanya)', 'on' => 'Aktifkan Windows Update', 'off' => 'Nonaktifkan Windows Update'];
function policy_cfg() {
    $s = settings_all();
    $p = (string)($s['power_mode'] ?? '');
    $w = (string)($s['winupdate'] ?? '');
    return ['power' => isset(POWER_MODES[$p]) ? $p : '', 'winupdate' => isset(WINUPDATE_MODES[$w]) ? $w : ''];
}
const POLICY_MIN = '1.10.0';   // agent mulai versi ini menerapkan kebijakan daya & Windows Update

// ---------- uninstall agent dari server (agent >= 1.17) ----------
const UNINSTALL_MIN = '1.17.0';

// ---------- layar live (agent >= 1.14) ----------
const LIVE_MIN = '1.14.0';
const LIVE_MAX_MINUTES = 30;          // satu sesi paling lama ini, lalu penonton harus klik "Lanjutkan"
const LIVE_MAX_BYTES = 3000000;       // batas satu gambar dari agent
// mode => [label, lebar maks (px), kualitas JPEG, gambar per detik]
const LIVE_MODES = ['hemat' => ['Hemat - 960 px, 1 gambar/dtk', 960, 50, 1], 'normal' => ['Normal - 1280 px, 2 gambar/dtk', 1280, 60, 2],
    'tajam' => ['Tajam - 1600 px, 2 gambar/dtk', 1600, 75, 2]];
function live_path($device_id) { return __DIR__ . '/live/' . (int)$device_id . '.jpg'; }
// Untuk agent (heartbeat & api/live.php): kirim gambar layar selama ada penonton
function live_hb($d) {
    if (empty($d['live_until']) || strtotime($d['live_until']) <= time()) return ['on' => 0];
    $m = LIVE_MODES[$d['live_mode'] ?? ''] ?? LIVE_MODES['normal'];
    return ['on' => 1, 'w' => $m[1], 'q' => $m[2], 'fps' => $m[3], 'by' => (string)$d['live_by']];
}

// ---------- chat support & tombol darurat (agent >= 1.11, tabel support_msgs) ----------
const SUPPORT_MIN = '1.11.0';    // agent mulai versi ini punya tombol Chat Support & DARURAT
const SUPPORT_ACK_SHOW = 120;    // detik: laptop menampilkan "DITANGANI" selama ini setelah darurat ditangani
const SUPPORT_KEEP_DAYS = 90;    // pesan yang lebih lama dihapus otomatis
const SUPPORT_EMERGENCY_TEXT = 'DARURAT - petugas membutuhkan bantuan segera';
const SUPPORT_SHUTDOWN_TEXT = 'Minta izin mematikan (shutdown) laptop ini';
const SHUTDOWN_REQ_MIN = '1.19.0';   // agent mulai versi ini punya tombol "Minta shutdown" (disetujui petugas/supervisor)
const SUPPORT_SHOW_MIN = '1.12.0';   // agent mulai versi ini menerima perintah "tampilkan tombol / buka chat" dan melaporkan status tombol
const SUPPORT_MODES = ['' => 'Ikut menu Pengaturan', '1' => 'Selalu tampil', '0' => 'Sembunyikan'];
// Tombol tampil di laptop ini? Pilihan per laptop (menu Support) mengalahkan menu Pengaturan
function support_on($d) {
    return isset($d['support_overlay']) && $d['support_overlay'] !== null ? (bool)$d['support_overlay'] : (bool)setting('support_overlay');
}
// Tampilan browser gate (agent >= 1.21): kiosk = layar penuh tanpa navigasi, window = browser biasa.
// Pilihan per laptop (menu Devices) mengalahkan menu Pengaturan.
const BROWSER_VIEWS = ['' => 'Ikut menu Pengaturan', 'kiosk' => 'Kiosk (layar penuh)', 'window' => 'Browser biasa'];
const BROWSER_VIEW_MIN = '1.21.0';
const SILENT_PRINTS = ['' => 'Ikut menu Pengaturan', '1' => 'Langsung ke printer default (tanpa dialog)', '0' => 'Tampilkan dialog print'];
const SILENT_PRINT_MIN = '1.22.0';
function silent_print_of($d) {
    return isset($d['silent_print']) && $d['silent_print'] !== null ? (bool)$d['silent_print'] : (bool)setting('gate_silent_print');
}
function browser_view_of($d) {
    $v = (string)($d['browser_view'] ?? '');
    return $v === 'kiosk' || $v === 'window' ? $v : (setting('gate_kiosk') ? 'kiosk' : 'window');
}
// Isi pesan: UTF-8 valid, tanpa karakter kontrol (kecuali baris baru), maks 1000 karakter
function support_text($s) {
    $s = mb_convert_encoding((string)$s, 'UTF-8', 'UTF-8');
    $s = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', str_replace(["\r\n", "\r"], "\n", $s)) ?? '';
    return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", $s)), 0, 1000);
}
// Untuk balasan heartbeat: id pesan terakhir dari support (agent mengambil pesan bila lebih baru) + status darurat
function support_hb($d) {
    if (!support_on($d)) return ['on' => 0];
    $st = db()->prepare("SELECT MAX(CASE WHEN sender<>'agent' THEN id END) last,
        MAX(kind='emergency' AND ack_at IS NULL) open, MAX(kind='emergency' AND ack_at >= NOW() - INTERVAL " . SUPPORT_ACK_SHOW . " SECOND) ack
        FROM support_msgs WHERE device_id=?");
    $st->execute([$d['id']]);
    $r = $st->fetch();
    return ['on' => 1, 'last' => (int)$r['last'], 'emg' => $r['open'] ? 'open' : ($r['ack'] ? 'ack' : ''), 'shut' => shutdown_state($d['id'])];
}
// Permintaan shutdown terakhir laptop ini: pending (menunggu), approved / rejected (diputuskan dalam SUPPORT_ACK_SHOW detik terakhir), atau kosong
function shutdown_state($device_id) {
    $st = db()->prepare("SELECT ack_at, decision, ack_at >= NOW() - INTERVAL " . SUPPORT_ACK_SHOW . " SECOND AS recent FROM support_msgs WHERE device_id=? AND kind='shutdown' ORDER BY id DESC LIMIT 1");
    $st->execute([$device_id]);
    $r = $st->fetch();
    if (!$r) return '';
    if ($r['ack_at'] === null) return 'pending';
    return $r['recent'] && in_array($r['decision'], ['approved', 'rejected'], true) ? $r['decision'] : '';
}
// Pesan percakapan satu laptop: id > $since (urut lama -> baru); $since 0 = 50 pesan terakhir
function support_msgs($device_id, $since) {
    $cols = "id, sender, author, kind, body, created_at, read_at IS NOT NULL AS seen, ack_at, ack_by, decision";
    if ($since > 0) {
        $st = db()->prepare("SELECT $cols FROM support_msgs WHERE device_id=? AND id>? ORDER BY id LIMIT 200");
        $st->execute([$device_id, $since]);
        $rows = $st->fetchAll();
    } else {
        $st = db()->prepare("SELECT $cols FROM support_msgs WHERE device_id=? ORDER BY id DESC LIMIT 50");
        $st->execute([$device_id]);
        $rows = array_reverse($st->fetchAll());
    }
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['seen'] = (bool)$r['seen']; }
    return $rows;
}

// ---------- Wake-on-LAN (agent >= 1.7) ----------
// MAC valid -> "AA:BB:CC:DD:EE:FF", selain itu null (MAC kosong/broadcast/multicast ditolak)
function norm_mac($m) {
    $h = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string)$m));
    if (strlen($h) !== 12 || $h === '000000000000' || $h === 'FFFFFFFFFFFF' || (hexdec($h[1]) & 1)) return null;
    return implode(':', str_split($h, 2));
}
// MAC tujuan WoL laptop ini (kabel dulu, lalu Wi-Fi), hanya dari data yang dilaporkan agent sendiri
function wol_macs($d) {
    $nics = json_decode((string)($d['nics'] ?? ''), true) ?: [];
    usort($nics, function ($a, $b) { return ($a['type'] === 'ethernet' ? 0 : 1) - ($b['type'] === 'ethernet' ? 0 : 1); });
    $out = [];
    foreach ($nics as $n) if (($m = norm_mac($n['mac'] ?? '')) && !in_array($m, $out, true)) $out[] = $m;
    return array_slice($out, 0, 8);
}
// Kesiapan WoL untuk dashboard: level ready | sleep | warn | unknown + keterangan
function wol_summary($d) {
    $nics = json_decode((string)($d['nics'] ?? ''), true) ?: [];
    $w = json_decode((string)($d['wol'] ?? ''), true) ?: [];
    if (!$nics) return ['level' => 'unknown', 'text' => 'belum ada data (perlu agent 1.7+)'];
    $eth = array_values(array_filter($nics, function ($n) { return $n['type'] === 'ethernet'; }));
    $fast = !empty($w['fast_startup']);
    $magic = $w['magic'] ?? [];
    $eth_ok = false;
    foreach ($eth as $n) if (($magic[$n['name']] ?? '') === 'Enabled') $eth_ok = true;
    if ($eth && $eth_ok && !$fast) return ['level' => 'ready', 'text' => 'siap (kabel LAN), pastikan WoL aktif di BIOS'];
    if ($eth && $fast) return ['level' => 'warn', 'text' => 'Fast Startup masih aktif (pasang ulang agent untuk mematikannya)'];
    if ($eth && !$magic) return ['level' => 'unknown', 'text' => 'status kartu LAN belum terbaca (agent perlu hak Administrator)'];
    if ($eth && !$eth_ok) return ['level' => 'warn', 'text' => 'Wake on Magic Packet kartu LAN belum aktif'];
    return ['level' => 'sleep', 'text' => 'Wi-Fi saja: hanya bisa dibangunkan dari Sleep'];
}
// Kirim magic packet langsung dari server. Hanya sampai bila server satu jaringan (LAN) dengan laptop.
// Tujuan hanya broadcast: 255.255.255.255 dan broadcast /24 dari IP LAN terakhir laptop (port 9 & 7).
// Memakai stream UDP bawaan PHP (so_broadcast), jadi tidak butuh ekstensi sockets.
function wol_server_send(array $macs, $ip) {
    if (!$macs) return 0;
    $dst = ['255.255.255.255'];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_RES_RANGE) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)) {
        $dst[] = preg_replace('/\.\d+$/', '.255', $ip);         // hanya IP privat (LAN)
    }
    $ctx = stream_context_create(['socket' => ['so_broadcast' => true]]);
    $n = 0;
    foreach ($dst as $b) foreach ([9, 7] as $p) {
        $s = @stream_socket_client("udp://$b:$p", $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) continue;
        foreach ($macs as $m) {
            if (!($m = norm_mac($m))) continue;
            $pkt = str_repeat("\xFF", 6) . str_repeat(hex2bin(str_replace(':', '', $m)), 16);
            for ($i = 0; $i < 3; $i++) if (@fwrite($s, $pkt) === strlen($pkt)) $n++;   // diulang: UDP bisa hilang
        }
        fclose($s);
    }
    return $n;
}

// ---------- ZIP tanpa ekstensi php-zip ----------
// zip_create(): pakai ZipArchive bila ekstensi zip aktif; bila tidak, GmZip (PHP murni, format ZIP standar
// tanpa kompresi, ditulis bertahap ke file agar file besar seperti installer Python tidak memenuhi memori).
function zip_create($path) {
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) return $z;
    }
    return new GmZip($path);
}

class GmZip {
    private $fh;
    private $cd = '';
    private $n = 0;

    public function __construct($path) {
        $this->fh = fopen($path, 'wb');
        if (!$this->fh) throw new RuntimeException('tidak bisa menulis file ZIP sementara');
    }
    private static function dos_time($ts) {
        $d = getdate($ts);
        return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
                (max(0, $d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday']];
    }
    private function entry($name, $crc, $size, $ts, $write) {
        $name = strtr($name, DIRECTORY_SEPARATOR, '/');
        [$t, $dt] = self::dos_time($ts);
        $off = ftell($this->fh);
        // local file header: versi 2.0, flag UTF-8 (bit 11), tanpa kompresi (store)
        fwrite($this->fh, pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $t, $dt, $crc, $size, $size, strlen($name), 0) . $name);
        $write();
        $this->cd .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $t, $dt, $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0x20, $off) . $name;
        $this->n++;
        return true;
    }
    public function addFile($src, $name) {
        $size = filesize($src);
        if ($size === false || $size > 0xFFFFFFFF) return false;
        $crc = hexdec(hash_file('crc32b', $src));
        $fh = $this->fh;
        return $this->entry($name, $crc, $size, filemtime($src) ?: time(), function () use ($src, $fh) {
            $in = fopen($src, 'rb'); stream_copy_to_stream($in, $fh); fclose($in);
        });
    }
    public function addFromString($name, $data) {
        $fh = $this->fh;
        return $this->entry($name, crc32($data) & 0xFFFFFFFF, strlen($data), time(), function () use ($data, $fh) { fwrite($fh, $data); });
    }
    public function close() {
        if (!$this->fh) return true;
        $off = ftell($this->fh);
        fwrite($this->fh, $this->cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $this->n, $this->n, strlen($this->cd), $off, 0));
        fclose($this->fh);
        $this->fh = null;
        return true;
    }
}

// ---------- cadangan bila ekstensi mbstring belum aktif (hanya fungsi yang dipakai GateMon, UTF-8) ----------
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len = null, $enc = null) {
        preg_match_all('/./us', (string)$s, $m);
        return implode('', array_slice($m[0], $start, $len));
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return preg_match_all('/./us', (string)$s); }
}

// ---------- salin database (pindah server), tanpa mysqldump ----------
// Nilai di-escape seperti mysqli_real_escape_string sehingga tiap INSERT tepat satu baris (aman diimpor per baris).
function sql_q($v) {
    if ($v === null) return 'NULL';
    return "'" . strtr((string)$v, ["\\" => "\\\\", "'" => "\'", "\n" => "\n", "\r" => "\r", "\0" => "\0", "\x1a" => "\Z"]) . "'";
}
// Tulis seluruh database ke file .sql. $history=false: tabel riwayat (logs, commands, scans) hanya strukturnya.
function db_dump_sql(PDO $pdo, $path, $history = true) {
    $fh = fopen($path, 'wb');
    if (!$fh) throw new RuntimeException("tidak bisa menulis $path");
    fwrite($fh, "-- GateMon database, dibuat " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $n = [];
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "\nDROP TABLE IF EXISTS `$t`;\n$create;\n");
        $n[$t] = 0;
        if (!$history && in_array($t, ['logs', 'commands', 'scans'], true)) continue;
        $st = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
        foreach ($st as $row) {
            fwrite($fh, "INSERT INTO `$t` (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', array_map('sql_q', $row)) . ");\n");
            $n[$t]++;
        }
    }
    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return $n;
}
// Impor file .sql buatan db_dump_sql (pernyataan diakhiri ';' di akhir baris)
function db_import_sql(PDO $pdo, $path) {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException("tidak bisa membaca $path");
    $buf = '';
    $n = 0;
    while (($line = fgets($fh)) !== false) {
        if ($buf === '' && (trim($line) === '' || strpos(ltrim($line), '--') === 0)) continue;
        $buf .= $line;
        if (substr(rtrim($line), -1) === ';') { $pdo->exec($buf); $buf = ''; $n++; }
    }
    fclose($fh);
    if (trim($buf) !== '') { $pdo->exec($buf); $n++; }
    return $n;
}
