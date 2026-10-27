<?php
// GateMon - impor server hasil "Pindah / cadangkan server". Dijalankan oleh INSTALL-SERVER.bat:
//   php migrasi\import.php [folder-xampp] [--yes] [--no-tune] [--keep-sql]
// --yes: timpa database yang sudah berisi data tanpa bertanya (tetap dicadangkan dulu)
// --no-tune: jangan tawarkan perubahan php.ini / Apache;  --keep-sql: jangan hapus database.sql setelah impor
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Hanya untuk command line.\n"); }

$args = array_slice($argv, 1);
$opt = function ($f) use ($args) { return in_array($f, $args, true); };
$xampp = rtrim((string)(array_values(array_filter($args, function ($a) { return $a[0] !== '-'; }))[0] ?? 'C:\xampp'), '\\/');
$app = dirname(__DIR__);
$sql = __DIR__ . DIRECTORY_SEPARATOR . 'database.sql';

function say($t = '') { echo $t, PHP_EOL; }
function ask($q, $def = 'n') {
    echo $q, ' [', $def === 'y' ? 'Y/n' : 'y/N', '] ';
    $a = strtolower(trim((string)fgets(STDIN)));
    return ($a === '' ? $def : $a[0]) === 'y';
}
function prompt($q, $def) {
    echo "$q [$def]: ";
    $a = trim((string)fgets(STDIN));
    return $a === '' ? $def : $a;
}

say('=== GateMon - impor server ===');
if (!extension_loaded('pdo_mysql')) { say('GAGAL: ekstensi PHP pdo_mysql belum aktif (php.ini: extension=pdo_mysql).'); exit(1); }
if (!is_file($sql)) { say("GAGAL: $sql tidak ada. Paket tidak lengkap."); exit(1); }

// ---------- 1. koneksi MySQL (pakai config.php; bila gagal, tanya lalu perbarui config.php) ----------
$cfgFile = $app . DIRECTORY_SEPARATOR . 'config.php';
$CFG = require $cfgFile;
for ($try = 0; ; $try++) {
    $c = $CFG['db'];
    try {
        $pdo = new PDO("mysql:host={$c['host']};charset=utf8mb4", $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (Exception $e) {
        say("Koneksi MySQL gagal ({$c['user']}@{$c['host']}): " . $e->getMessage());
        if ($try >= 3) exit(1);
        say('Pastikan MySQL di XAMPP Control Panel sudah Start, lalu isi data koneksi:');
        $CFG['db']['host'] = prompt('  Host MySQL', $c['host']);
        $CFG['db']['user'] = prompt('  User MySQL', $c['user']);
        $CFG['db']['pass'] = prompt('  Password MySQL', $c['pass']);
        $CFG['db']['name'] = prompt('  Nama database', $c['name']);
    }
}
if ($CFG['db'] !== (require $cfgFile)['db']) {          // simpan data koneksi baru ke config.php
    $txt = file_get_contents($cfgFile);
    foreach (['host', 'name', 'user', 'pass'] as $k) {
        $txt = preg_replace("/('$k'\s*=>\s*)'(?:[^'\\\\]|\\\\.)*'/", '${1}' . var_export($CFG['db'][$k], true), $txt, 1);
    }
    file_put_contents($cfgFile, $txt);
    say('config.php diperbarui dengan data koneksi MySQL.');
}
$db = $CFG['db']['name'];
if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) { say("Nama database tidak valid: $db"); exit(1); }

// ---------- 2. database tujuan: bila sudah berisi data, tanya & cadangkan dulu ----------
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$db`");
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
if ($tables) {
    $nd = in_array('devices', $tables, true) ? (int)$pdo->query("SELECT COUNT(*) FROM devices")->fetchColumn() : 0;
    say("Database '$db' sudah berisi " . count($tables) . " tabel ($nd laptop).");
    if (!$opt('--yes') && !ask('Timpa dengan data dari paket? Data lama dicadangkan dulu.')) { say('Dibatalkan, tidak ada yang diubah.'); exit(1); }
    $GLOBALS['CFG'] = $CFG;
    require_once $app . DIRECTORY_SEPARATOR . 'db.php';
    $bak = __DIR__ . DIRECTORY_SEPARATOR . 'backup-' . $db . '-' . date('Ymd-His') . '.sql';
    db_dump_sql($pdo, $bak, true);
    say("Cadangan database lama: $bak");
} else {
    $GLOBALS['CFG'] = $CFG;
    require_once $app . DIRECTORY_SEPARATOR . 'db.php';
}

// ---------- 3. impor ----------
$n = db_import_sql($pdo, $sql);
say("Impor selesai: $n perintah SQL.");
foreach (['devices' => 'laptop', 'categories' => 'kategori', 'admins' => 'akun admin', 'settings' => 'pengaturan', 'scans' => 'scan', 'logs' => 'log'] as $t => $l) {
    try { say(sprintf('  %-12s %d', $l, $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn())); } catch (Exception $e) {}
}

// ---------- 4. penyesuaian server (opsional, file asli dicadangkan) ----------
if (!$opt('--no-tune')) {
    $ini = php_ini_loaded_file();
    if ($ini && preg_match('/^\s*;\s*extension\s*=\s*(php_)?zip(\.dll)?\s*$/mi', file_get_contents($ini))
        && ask("Aktifkan ekstensi PHP zip di $ini (paket agent lebih kecil)?", 'y')) {
        copy($ini, $ini . '.bak-' . date('Ymd-His'));
        file_put_contents($ini, preg_replace('/^\s*;\s*(extension\s*=\s*(php_)?zip(\.dll)?)\s*$/mi', '$1', file_get_contents($ini), 1));
        say('  extension=zip diaktifkan.');
    }
    $hd = "$xampp\\apache\\conf\\extra\\httpd-default.conf";
    if (is_file($hd) && preg_match('/^KeepAliveTimeout\s+([0-9]+)/mi', file_get_contents($hd), $m) && (int)$m[1] < 15
        && ask("Ubah KeepAliveTimeout Apache {$m[1]} -> 15 detik (koneksi agent lebih hemat)?", 'y')) {
        copy($hd, $hd . '.bak-' . date('Ymd-His'));
        file_put_contents($hd, preg_replace('/^KeepAliveTimeout\s+[0-9]+/mi', 'KeepAliveTimeout 15', file_get_contents($hd), 1));
        say('  KeepAliveTimeout = 15.');
    }
}

// ---------- 5. hapus salinan database (berisi hash password & token) ----------
if (!$opt('--keep-sql')) { @unlink($sql); say('database.sql dihapus dari folder migrasi (berisi data sensitif).'); }

say();
say('SELESAI. Langkah berikutnya:');
say('  1. Restart Apache (dan MySQL bila diminta) di XAMPP Control Panel.');
say('  2. Buka http://localhost/gatemon/setup.php untuk pemeriksaan sistem, lalu hapus setup.php.');
say('  3. PENTING: agent di laptop gate menyimpan alamat server lama. Beri server baru IP yang SAMA');
say('     dengan server lama, atau pasang ulang agent lewat /install/ID-LAPTOP dari server baru.');
exit(0);
