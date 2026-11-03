<?php
// Sertifikat HTTPS untuk server GateMon di jaringan lokal (dibutuhkan aplikasi PWA & push notification).
// Membuat CA GateMon sendiri (sekali) + sertifikat server untuk IP / nama server, lalu memasangnya ke Apache XAMPP.
//   sertifikat-https.bat C:\GateMon-kunci                          -> IP & nama komputer ini terdeteksi otomatis
//   sertifikat-https.bat C:\GateMon-kunci 10.5.5.100 gatemon.lan    -> tambah IP / nama lain
//   ... --tanpa-apache                                             -> hanya buat file, Apache tidak diubah
// Kunci RAHASIA CA (gatemon-ca.key) tetap di folder kunci, JANGAN di htdocs / git: siapa pun yang memegangnya bisa
// membuat sertifikat palsu yang dipercaya semua HP yang memasang CA GateMon. Yang dibagikan hanya gatemon-ca.crt.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Asia/Jakarta');
function fail($m) { fwrite(STDERR, "GAGAL: $m\n"); exit(1); }
if (!function_exists('openssl_csr_new')) fail('ekstensi PHP openssl tidak aktif (php.ini: extension=openssl).');

$root = dirname(__DIR__);
$noApache = in_array('--tanpa-apache', $argv, true);
$argv = array_values(array_diff($argv, ['--tanpa-apache']));
$dir = rtrim((string)($argv[1] ?? ''), '\\/');
if ($dir === '') exit("Pakai: sertifikat-https.bat <folder-kunci> [IP / nama server tambahan ...]\n  contoh: sertifikat-https.bat C:\\GateMon-kunci 10.5.5.100 gatemon.lan\n");
// dicek sebelum folder dibuat: folder yang belum ada dinilai dari folder induk terdekat yang sudah ada
$inWeb = function ($p) use ($root) {
    $p = strtr($p, '\\', '/');
    if (!preg_match('#^([a-z]:)?/#i', $p)) $p = strtr(getcwd(), '\\', '/') . "/$p";
    for ($q = $p, $tail = ''; !is_dir($q) && dirname($q) !== $q; $q = dirname($q)) $tail = '/' . basename($q) . $tail;
    $p = strtr((string)realpath($q), '\\', '/') . $tail;
    $web = [strtr(realpath($root), '\\', '/'), strtr((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: 'C:/xampp/htdocs', '\\', '/'), 'C:/xampp/htdocs'];
    foreach ($web as $w) if ($w !== '' && stripos(rtrim($p, '/') . '/', rtrim($w, '/') . '/') === 0) return $p;
    return null;
};
if ($p = $inWeb($dir)) fail("folder kunci tidak boleh di dalam htdocs ($p): folder itu disajikan web.");
if (!is_dir($dir) && !@mkdir($dir, 0700, true)) fail("tidak bisa membuat folder $dir");

// openssl.cnf berisi bagian ekstensi sertifikat (SAN dll)
$names = ['localhost'];
$ips = ['127.0.0.1'];
$host = strtolower(gethostname());
if ($host) $names[] = $host;
foreach (@gethostbynamel(gethostname()) ?: [] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) $ips[] = $ip;
foreach (array_slice($argv, 2) as $a) {
    $a = strtolower(trim($a));
    if (filter_var($a, FILTER_VALIDATE_IP)) $ips[] = $a;
    elseif (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/', $a)) $names[] = $a;
    else fail("bukan IP / nama yang valid: $a");
}
$ips = array_values(array_unique($ips)); $names = array_values(array_unique($names));
$alt = [];
foreach ($names as $i => $n) $alt[] = 'DNS.' . ($i + 1) . " = $n";
foreach ($ips as $i => $n) $alt[] = 'IP.' . ($i + 1) . " = $n";
$cnf = tempnam(sys_get_temp_dir(), 'gmssl');
file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n"
    . "[v3_ca]\nbasicConstraints = critical, CA:TRUE, pathlen:0\nkeyUsage = critical, keyCertSign, cRLSign\nsubjectKeyIdentifier = hash\n"
    . "[v3_srv]\nbasicConstraints = critical, CA:FALSE\nkeyUsage = critical, digitalSignature, keyEncipherment\nextendedKeyUsage = serverAuth\n"
    . "subjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid\nsubjectAltName = @alt\n[alt]\n" . implode("\n", $alt) . "\n");
$o = ['config' => $cnf, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
$serial = function () { return random_int(1, PHP_INT_MAX); };

// ---------- CA (sekali; dipakai ulang agar HP yang sudah memasang CA tidak perlu memasang lagi) ----------
$caKeyF = "$dir/gatemon-ca.key"; $caCrtF = "$dir/gatemon-ca.crt";
if (is_file($caKeyF) && is_file($caCrtF)) {
    $caKey = openssl_pkey_get_private(file_get_contents($caKeyF));
    $caCrt = file_get_contents($caCrtF);
    if (!$caKey || !openssl_x509_check_private_key($caCrt, $caKey)) fail("$caKeyF / $caCrtF rusak atau tidak cocok.");
    echo "CA lama dipakai: $caCrtF\n";
} else {
    $caKey = openssl_pkey_new($o) ?: fail('gagal membuat kunci CA: ' . openssl_error_string());
    $csr = openssl_csr_new(['commonName' => 'GateMon CA ' . ($host ?: 'server'), 'organizationName' => 'GateMon'], $caKey, $o);
    $x = openssl_csr_sign($csr, null, $caKey, 3650, $o + ['x509_extensions' => 'v3_ca'], $serial()) ?: fail('gagal membuat CA: ' . openssl_error_string());
    openssl_x509_export($x, $caCrt);
    openssl_pkey_export($caKey, $pem, null, $o);
    file_put_contents($caKeyF, $pem);
    file_put_contents($caCrtF, $caCrt);
    echo "CA baru dibuat (berlaku 10 tahun): $caCrtF\n";
}

// ---------- sertifikat server (825 hari: batas yang diterima iPhone / Chrome) ----------
$key = openssl_pkey_new($o) ?: fail('gagal membuat kunci server: ' . openssl_error_string());
$csr = openssl_csr_new(['commonName' => $ips[count($ips) > 1 ? 1 : 0] ?? 'gatemon', 'organizationName' => 'GateMon'], $key, $o);
$x = openssl_csr_sign($csr, $caCrt, $caKey, 825, $o + ['x509_extensions' => 'v3_srv'], $serial()) ?: fail('gagal membuat sertifikat server: ' . openssl_error_string());
openssl_x509_export($x, $crt);
openssl_pkey_export($key, $keyPem, null, $o);
@unlink($cnf);
file_put_contents("$dir/gatemon-server.crt", $crt);
file_put_contents("$dir/gatemon-server.key", $keyPem);
echo "Sertifikat server: " . implode(', ', array_merge($ips, $names)) . " (berlaku sampai " . date('d/m/Y', openssl_x509_parse($crt)['validTo_time_t']) . ")\n";

// ---------- pasang ke Apache XAMPP (file lama dicadangkan) ----------
$apache = null;
foreach ([dirname(PHP_BINARY) . '/../apache', 'C:/xampp/apache'] as $a) if (is_file("$a/conf/extra/httpd-ssl.conf")) { $apache = realpath($a); break; }
if ($apache && !$noApache) {
    $stamp = date('Ymd-His');
    foreach (["$apache/conf/ssl.crt/server.crt" => $crt, "$apache/conf/ssl.key/server.key" => $keyPem] as $f => $data) {
        if (!is_dir(dirname($f))) mkdir(dirname($f), 0700, true);
        if (is_file($f)) copy($f, "$f.bak-$stamp");
        if (file_put_contents($f, $data) === false) fail("tidak bisa menulis $f (jalankan sebagai Administrator?)");
    }
    echo "Dipasang ke Apache: $apache\\conf\\ssl.crt\\server.crt + ssl.key\\server.key (cadangan .bak-$stamp)\n";
} else {
    echo ($noApache ? 'Apache tidak diubah (--tanpa-apache)' : 'Apache XAMPP tidak ditemukan') . ": pasang manual gatemon-server.crt / .key di httpd-ssl.conf.\n";
}

// CA publik untuk diunduh HP / PC dari halaman Notifikasi
$pub = "$root/sertifikat";
if (!is_dir($pub)) mkdir($pub, 0755, true);
file_put_contents("$pub/gatemon-ca.crt", $caCrt);
echo "CA publik untuk diunduh: $pub\\gatemon-ca.crt\n\n"
   . "Langkah berikut:\n"
   . "  1. Restart Apache di XAMPP Control Panel (Stop lalu Start).\n"
   . "  2. Buka https://" . ($ips[1] ?? $ips[0]) . "/gatemon/admin/notifikasi.php, unduh & pasang sertifikat CA di tiap HP / PC.\n"
   . "  3. Agent di laptop tetap memakai http:// (tidak perlu diubah).\n"
   . "Simpan cadangan folder $dir (terutama gatemon-ca.key) di tempat aman.\n";
