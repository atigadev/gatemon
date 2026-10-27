<?php
// Paket agent siap pasang (ZIP) untuk satu laptop (?id=N) atau semua laptop (?id=all).
// Berisi file agent + config.json yang sudah terisi server_url, device_key dan token.
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); die('Hanya supervisor yang boleh mengunduh paket agent (berisi token).'); }
// ZIP dibuat lewat zip_create() (db.php): tetap jalan walau ekstensi PHP zip belum aktif

$server = rtrim(trim($_GET['server'] ?? ''), '/');
if (!preg_match('#^https?://[^\s/]+(/\S*)?$#i', $server)) die('URL server tidak valid, contoh: http://192.168.1.10/gatemon');

$pdo = db();
$id = $_GET['id'] ?? '';
$agent_dir = realpath(__DIR__ . '/../agent');
$files = ['agent.py', 'requirements.txt', 'install_agent.bat', 'install_agent.ps1', 'uninstall_agent.bat'];

// Installer umum (sama untuk semua laptop, tanpa token): login dengan kode instalasi sekali pakai.
// Ikut disertakan isi agent/installer/ (installer python-*.exe dan folder wheels\ bila disiapkan).
if ($id === 'installer') {
    @set_time_limit(300);
    $folder = 'GateMon-Agent-Installer-v' . agent_version() . '/';
    $tmp = tempnam(sys_get_temp_dir(), 'gm');
    $zip = zip_create($tmp);
    foreach ($files as $f) $zip->addFile("$agent_dir/$f", $folder . $f);
    $zip->addFromString($folder . 'server.txt', $server . "\r\n");
    $extra = [];
    $idir = "$agent_dir/installer";
    if (is_dir($idir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($idir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $rel = strtr(substr($file->getPathname(), strlen($idir) + 1), DIRECTORY_SEPARATOR, '/');
            if ($rel === 'BACA-SAYA.txt' || $rel[0] === '.') continue;
            $zip->addFile($file->getPathname(), $folder . $rel);
            $extra[] = $rel;
        }
    }
    $py = array_values(array_filter($extra, function ($r) { return preg_match('#^python-.*\.exe$#i', $r); }));
    $lines = [
        'GATEMON AGENT - INSTALLER UMUM (pakai kode instalasi sekali pakai)',
        "Server : $server",
        'Python : ' . ($py ? $py[0] . ' (dipasang otomatis bila belum ada)' : 'TIDAK disertakan - laptop harus sudah punya Python 3'),
        'Library: ' . (in_array(true, array_map(function ($r) { return strpos($r, 'wheels/') === 0; }, $extra), true) ? 'offline dari folder wheels' : 'diunduh dari internet saat instalasi'),
        '',
        'LANGKAH DI LAPTOP',
        '1. Di dashboard menu Devices, klik "Kode instalasi" pada laptop yang akan dipasang.',
        '   Catat username (ID laptop) dan password. Password hanya berlaku sekali dan punya batas waktu.',
        '2. Ekstrak ZIP ini di laptop (misal Desktop), lalu klik dua kali install_agent.bat dan setujui izin Administrator.',
        '3. Masukkan username dan password. Installer otomatis: mengambil konfigurasi dari server,',
        '   memasang Python (bila perlu), library, menyalin ke C:\GateMon-Agent, dan menjalankan agent.',
        '4. Laptop muncul ONLINE di dashboard dalam beberapa detik.',
        '',
        'ZIP ini tidak berisi token, jadi aman dipakai ulang untuk semua laptop.',
        'Log agent: C:\GateMon-Agent\agent.log. Untuk melepas: jalankan uninstall_agent.bat.',
    ];
    $zip->addFromString($folder . 'BACA-SAYA.txt', implode("\r\n", $lines) . "\r\n");
    $zip->close();
    log_event(null, 'info', 'Installer agent umum diunduh oleh ' . current_user()['username']);
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="GateMon-Agent-Installer-v' . agent_version() . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}


if ($id === 'all') {
    $devices = $pdo->query("SELECT * FROM devices ORDER BY device_key")->fetchAll();
    if (!$devices) die('Belum ada laptop di menu Devices.');
} else {
    $st = $pdo->prepare("SELECT * FROM devices WHERE id=?");
    $st->execute([(int)$id]);
    $devices = $st->fetchAll();
    if (!$devices) die('Laptop tidak ditemukan.');
}

$example = json_decode(file_get_contents("$agent_dir/config.example.json"), true) ?: [];

function readme_text($d, $server) {
    $monitor = $d['mode'] === 'monitor';
    $gate_url = $d['gate_url'] !== '' ? $d['gate_url'] : $GLOBALS['CFG']['default_gate_url'] . rawurlencode($d['device_key']);
    $lines = [
        "GATEMON AGENT - {$d['device_key']} ({$d['name']})",
        "Gate / pos : " . ($d['gate'] ?: '-'),
        "Server     : $server",
        $monitor ? "Mode       : monitor koneksi saja (tanpa browser / halaman gate)" : "Halaman gate: $gate_url",
        "",
        "LANGKAH PEMASANGAN DI LAPTOP GATE",
        "1. Pastikan Python 3 terpasang (python.org, centang \"Add python.exe to PATH\" saat install).",
        "2. Ekstrak ZIP ini di mana saja (misal Desktop).",
        "3. Klik dua kali install_agent.bat dan setujui izin Administrator (config.json di paket ini langsung dipakai, tanpa login).",
        "   Installer menyalin semua file ke C:\\GateMon-Agent, memasang library Python,",
        "   dan membuat tugas otomatis \"GateMonAgent\" yang jalan setiap laptop login.",
        "4. Cek dashboard: laptop {$d['device_key']} harus muncul ONLINE dalam beberapa detik.",
        "5. (Opsional) Pasang Radmin Server agar bisa diremote dari dashboard.",
        "",
        "config.json sudah terisi token laptop ini - JANGAN dipakai di laptop lain.",
        "Bila token dibuat ulang di menu Devices, unduh ulang paket ini lalu jalankan install_agent.bat lagi.",
        "Log agent: C:\\GateMon-Agent\\agent.log. Untuk melepas: jalankan uninstall_agent.bat.",
    ];
    return implode("\r\n", $lines) . "\r\n";
}

$tmp = tempnam(sys_get_temp_dir(), 'gm');
$zip = zip_create($tmp);
$single = count($devices) === 1 && $id !== 'all';
$ver = agent_version();
foreach ($devices as $d) {
    $folder = $single ? "GateMon-Agent-{$d['device_key']}-v$ver/" : "GateMon-Agent-Semua-v$ver/{$d['device_key']}/";
    foreach ($files as $f) $zip->addFile("$agent_dir/$f", $folder . $f);
    $cfg = array_merge($example, [
        'server_url' => $server,
        'device_key' => $d['device_key'],
        'token' => $d['token'],
        'gate_url' => '', // kosong = server yang memberi tahu URL gate
    ]);
    $zip->addFromString($folder . 'config.json', json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFromString($folder . 'BACA-SAYA.txt', readme_text($d, $server));
}
$zip->close();

log_event($single ? $devices[0]['id'] : null, 'info', 'Paket agent diunduh oleh ' . current_user()['username'] . ($single ? '' : ' (semua laptop)'));

$name = $single ? "GateMon-Agent-{$devices[0]['device_key']}-v$ver.zip" : "GateMon-Agent-Semua-v$ver.zip";
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
