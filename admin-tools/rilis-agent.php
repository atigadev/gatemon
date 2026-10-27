<?php
// Rilis update agent bertanda tangan (Ed25519). Jalankan lewat rilis-agent.bat di PC yang memegang kunci rahasia.
//   rilis-agent.bat kunci-baru  E:\gatemon-update.key   -> buat pasangan kunci (sekali), kunci publik ditulis ke agent.py
//   rilis-agent.bat tanda-tangan E:\gatemon-update.key  -> tulis agent/release.json + release.sig dari agent.py saat ini
// Kunci RAHASIA jangan disimpan di server / htdocs / git: siapa pun yang memegangnya bisa memasang program ke semua laptop.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Jakarta');
if (!function_exists('sodium_crypto_sign_detached')) exit("GAGAL: ekstensi sodium tidak aktif. Jalankan lewat rilis-agent.bat.\n");

$root = dirname(__DIR__);
$agentPy = "$root/agent/agent.py";
[$cmd, $keyFile] = [$argv[1] ?? '', $argv[2] ?? ''];
function fail($m) { fwrite(STDERR, "GAGAL: $m\n"); exit(1); }
function pubkey_in_agent($src) { return preg_match('/^UPDATE_PUBKEY = "([0-9a-f]*)"/m', $src, $m) ? $m[1] : null; }
if ($keyFile === '' || !in_array($cmd, ['kunci-baru', 'tanda-tangan'], true)) {
    exit("Pakai:\n  rilis-agent.bat kunci-baru   <file-kunci>   (sekali saja)\n  rilis-agent.bat tanda-tangan <file-kunci>   (setiap versi agent baru)\n");
}
$real = realpath(dirname($keyFile)) ?: '';
if ($real !== '' && stripos(strtr($real, '\\', '/') . '/', strtr(realpath($root), '\\', '/') . '/') === 0) {
    fail("file kunci tidak boleh di dalam folder GateMon ($real): folder itu disajikan web dan masuk git.");
}

if ($cmd === 'kunci-baru') {
    if (file_exists($keyFile)) fail("$keyFile sudah ada. Tidak ditimpa (kunci lama tetap dipakai agent yang sudah terpasang).");
    $kp = sodium_crypto_sign_keypair();
    $pk = bin2hex(sodium_crypto_sign_publickey($kp));
    $txt = "# Kunci RAHASIA update GateMon Agent. JANGAN dibagikan / disimpan di server. Simpan juga cadangannya.\n"
         . "# Kunci publik: $pk\n" . bin2hex(sodium_crypto_sign_secretkey($kp)) . "\n";
    if (@file_put_contents($keyFile, $txt) === false) fail("tidak bisa menulis $keyFile");
    $src = file_get_contents($agentPy);
    if (pubkey_in_agent($src) === null) fail('baris UPDATE_PUBKEY tidak ditemukan di agent.py');
    file_put_contents($agentPy, preg_replace('/^UPDATE_PUBKEY = "[0-9a-f]*"/m', 'UPDATE_PUBKEY = "' . $pk . '"', $src, 1));
    echo "Kunci rahasia : $keyFile\nKunci publik  : $pk (ditulis ke agent/agent.py)\n\n"
       . "PENTING: buat cadangan file kunci (flashdisk kedua). Bila hilang, update otomatis tidak bisa lagi dan\n"
       . "semua laptop harus dipasang ulang manual dengan kunci baru.\n";
    exit(0);
}

// ---------- tanda-tangan ----------
$lines = preg_grep('/^[0-9a-f]{128}$/', array_map('trim', @file($keyFile) ?: []));
if (!$lines) fail("kunci rahasia tidak terbaca dari $keyFile");
$sk = hex2bin(reset($lines));
$pk = bin2hex(sodium_crypto_sign_publickey_from_secretkey($sk));
$src = file_get_contents($agentPy);
if (pubkey_in_agent($src) !== $pk) fail("kunci ini tidak cocok dengan UPDATE_PUBKEY di agent.py. Agent akan menolak rilis ini.");
if (!preg_match('/^VERSION = "([0-9]+\.[0-9]+\.[0-9]+)"/m', $src, $m)) fail('VERSION di agent.py tidak terbaca');
$version = $m[1];
$files = [];
foreach (['agent.py', 'requirements.txt'] as $f) {
    $p = "$root/agent/$f";
    $files[] = ['path' => $f, 'sha256' => hash_file('sha256', $p), 'size' => filesize($p)];
}
// wheels harus sama dengan SHA256SUMS.txt (daftar yang juga dipakai installer)
$wdir = "$root/agent/installer/wheels";
$sums = [];
foreach (@file("$wdir/SHA256SUMS.txt") ?: [] as $l) if (preg_match('/^([0-9a-f]{64})\s+\*?(\S+\.whl)\s*$/i', trim($l), $mm)) $sums[$mm[2]] = strtolower($mm[1]);
$wheels = [];
foreach (glob("$wdir/*.whl") as $w) {
    $n = basename($w);
    $h = hash_file('sha256', $w);
    if (($sums[$n] ?? '') !== $h) fail("wheel $n tidak cocok / tidak terdaftar di SHA256SUMS.txt");
    $wheels[] = ['file' => $n, 'sha256' => $h, 'size' => filesize($w)];
}
$relFile = "$root/agent/release.json";
if (is_file($relFile) && ($old = json_decode(file_get_contents($relFile), true)) && ($old['version'] ?? '') === $version
    && ($old['files'][0]['sha256'] ?? '') !== $files[0]['sha256']) {
    fail("agent.py berubah tetapi VERSION masih $version. Naikkan VERSION di agent.py, agent hanya memasang versi yang lebih baru.");
}
$manifest = json_encode(['app' => 'gatemon-agent', 'version' => $version, 'created' => date('c'), 'files' => $files, 'wheels' => $wheels],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$sig = sodium_crypto_sign_detached($manifest, $sk);
if (!sodium_crypto_sign_verify_detached($sig, $manifest, hex2bin($pk))) fail('verifikasi tanda tangan gagal');
file_put_contents($relFile, $manifest);
file_put_contents("$root/agent/release.sig", bin2hex($sig) . "\n");
sodium_memzero($sk);
echo "Rilis agent $version ditandatangani.\n  agent/release.json  (agent.py " . substr($files[0]['sha256'], 0, 12) . "..., " . count($wheels) . " wheels)\n  agent/release.sig\n"
   . "Commit kedua file itu, lalu di menu Agent klik Update.\n";
