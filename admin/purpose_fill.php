<?php
// Melengkapi peruntukan scan lama (yang belum terdeteksi) dari halaman cek undangan per ID QR (Pengaturan: purpose_lookup_url).
// Dipanggil tombol "Lengkapi peruntukan" di Laporan, bertahap per beberapa ID agar tidak timeout. Hanya supervisor.
// GET  act=status[&all=1]       -> jumlah ID yang belum dicek
// POST act=run[&all=1][&first=1] -> cek beberapa ID berikutnya (CSRF); hasil disimpan di purpose_lookup lalu scans.peruntukan diisi
// all=1: semua kategori; bawaan hanya kategori yang masuk group ASIC / EXH.
// ID yang sudah dicek tidak diminta lagi; yang gagal (error) dicoba lagi setelah 10 menit.
require __DIR__ . '/auth.php';
require_supervisor_json();
require __DIR__ . '/report_groups.php';
session_write_close();   // proses bisa lama: jangan menahan halaman lain di sesi yang sama
// error ditampilkan ke supervisor (bukan "Terjadi kesalahan" saja) agar masalah di server bisa langsung terlihat
set_exception_handler(function ($e) {
    error_log('GateMon purpose_fill: ' . $e);
    json_out(['ok' => false, 'error' => 'Kesalahan server: ' . mb_substr($e->getMessage(), 0, 300)], 500);
});
$pdo = db();
// struktur belum diperbarui (migrasi otomatis gagal / belum jalan): coba sekali lagi, tampilkan alasannya bila tetap gagal
if (!$pdo->query("SHOW TABLES LIKE 'purpose_lookup'")->fetchColumn()) {
    require_once __DIR__ . '/../schema.php';
    try { db_migrate($pdo); }
    catch (Exception $e) { json_out(['ok' => false, 'error' => 'Struktur database belum diperbarui: ' . mb_substr($e->getMessage(), 0, 300)], 500); }
}
$act = $_REQUEST['act'] ?? '';
$all = !empty($_REQUEST['all']);
const FILL_BATCH = 8;        // ID per request
const FILL_SECONDS = 20;     // batas waktu per request

// kategori yang masuk group ASIC / EXH
$cats = array_keys(array_filter($gmap, function ($g) { return in_array($g, PURPOSE_GROUPS, true); }));
$cat_sql = '';
$cat_p = [];
if (!$all) {
    if (!$cats) $cat_sql = ' AND 1 = 0';
    else { $cat_sql = ' AND s.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')'; $cat_p = $cats; }
}
$pending_sql = "FROM scans s LEFT JOIN purpose_lookup l ON l.code = s.code
    WHERE s.status = 'success' AND s.code <> '' AND s.peruntukan = ''$cat_sql
      AND (l.code IS NULL OR (l.status = 'error' AND l.checked_at < NOW() - INTERVAL 10 MINUTE))";

// hasil cek yang sudah ada langsung dipakai untuk scan yang belum terisi (mis. QR yang di-scan lagi setelah dicek)
function apply_cached() {
    return db()->exec("UPDATE scans s JOIN purpose_lookup l ON l.code = s.code SET s.peruntukan = l.peruntukan
        WHERE s.peruntukan = '' AND l.status = 'found' AND l.peruntukan <> ''");
}
function counts() {
    global $pdo, $pending_sql, $cat_p;
    $st = $pdo->prepare("SELECT COUNT(DISTINCT s.code) $pending_sql");
    $st->execute($cat_p);
    $c = ['pending' => (int)$st->fetchColumn()];
    foreach ($pdo->query("SELECT status, COUNT(*) AS n FROM purpose_lookup GROUP BY status") as $r) $c[$r['status']] = (int)$r['n'];
    return $c + ['found' => 0, 'notfound' => 0, 'error' => 0];
}

function lookup_url() {
    $u = trim((string)setting('purpose_lookup_url'));
    if (!preg_match('#^https://[^\s"\'<>]+$#i', $u) || strpos($u, '{id}') === false) return null;
    return $u;
}
// Ambil halaman https (maks 1 MB, tanpa redirect, sertifikat diverifikasi): [kode HTTP, isi, error].
// Pakai ekstensi curl bila aktif; bila tidak, stream https bawaan PHP (ekstensi openssl).
const FETCH_MAX = 1048576;   // halaman normal ~8 KB
function can_fetch() { return function_exists('curl_init') || in_array('https', stream_get_wrappers(), true); }
function ca_bundle() {
    // php.ini tanpa openssl.cafile / curl.cainfo: pakai CA bundle bawaan XAMPP bila ada
    foreach ([ini_get('openssl.cafile'), ini_get('curl.cainfo'), dirname(PHP_BINARY) . '/../apache/bin/curl-ca-bundle.crt',
              'C:/xampp/apache/bin/curl-ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt'] as $f) {
        if ($f && is_file($f)) return $f;
    }
    return null;
}
function http_fetch($url) {
    $ca = ca_bundle();
    if (function_exists('curl_init')) {
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_USERAGENT => 'GateMon',
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$body) { $body .= $chunk; return strlen($body) > FETCH_MAX ? 0 : strlen($chunk); },
        ] + ($ca ? [CURLOPT_CAINFO => $ca] : []));
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$ok === false ? 0 : $http, $body, $err];
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => 10, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Accept: text/html\r\nUser-Agent: GateMon\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true] + ($ca ? ['cafile' => $ca] : []),
    ]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) return [0, '', preg_replace('/^fopen\([^)]*\):\s*/', '', (string)(error_get_last()['message'] ?? 'gagal terhubung'))];
    $body = (string)stream_get_contents($fp, FETCH_MAX);
    $head = stream_get_meta_data($fp)['wrapper_data'] ?? [];
    fclose($fp);
    $http = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($head[0] ?? ''), $m) ? (int)$m[1] : 0;
    return [$http, $body, $http ? '' : 'balasan tidak dikenal'];
}
// ambil halaman cek satu ID: [status, peruntukan, kategori, error]
function lookup_one($url, $code) {
    [$http, $body, $err] = http_fetch(str_replace('{id}', rawurlencode(trim($code)), $url));
    if ($http !== 200) return ['error', '', '', mb_substr($err ?: "HTTP $http", 0, 200)];
    $txt = function ($s) { return mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 100); };
    $peru = '';
    if (preg_match('#class="[^"]*\bscan-peruntukan\b[^"]*"[^>]*>(.*?)</span>#is', $body, $m)) $peru = $txt($m[1]);
    if ($peru === '' && preg_match('#>\s*Peruntukan\s*</span>\s*<span[^>]*>(.*?)</span>#is', $body, $m)) $peru = $txt($m[1]);
    $cat = preg_match('#fa-id-badge[^>]*>\s*</i>(.*?)</span>#is', $body, $m) ? $txt($m[1]) : '';
    if ($peru !== '') return ['found', $peru, $cat, null];
    if (stripos($body, 'Tidak Ditemukan') !== false || stripos($body, 'tidak terdaftar') !== false) return ['notfound', '', '', null];
    return ['found', '', $cat, null];   // terdaftar tapi tanpa peruntukan
}

if ($act === 'status') {
    apply_cached();
    json_out(['ok' => true, 'url_ok' => lookup_url() !== null] + counts());
}
if ($act === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (!can_fetch()) json_out(['ok' => false, 'error' => 'PHP di server tidak bisa membuka https: buka C:\\xampp\\php\\php.ini, hapus ; di depan extension=openssl (atau extension=curl), lalu restart Apache.'], 500);
    $url = lookup_url();
    if (!$url) json_out(['ok' => false, 'error' => 'Link cek peruntukan di menu Pengaturan tidak valid (harus https dan berisi {id}).'], 400);
    // satu proses sekaligus (dua tab / dua supervisor tidak mengecek ID yang sama)
    if (!(int)$pdo->query("SELECT GET_LOCK('gatemon_purpose_fill', 0)")->fetchColumn()) json_out(['ok' => false, 'error' => 'Proses melengkapi peruntukan sedang berjalan di tab / akun lain.'], 409);
    set_time_limit(FILL_SECONDS + 30);
    if (!empty($_POST['first'])) log_event(null, 'info', current_user()['username'] . ' melengkapi peruntukan scan lama dari link cek' . ($all ? ' (semua kategori)' : ' (ASIC / EXH)'));
    $st = $pdo->prepare("SELECT DISTINCT s.code $pending_sql LIMIT " . FILL_BATCH);
    $st->execute($cat_p);
    $codes = $st->fetchAll(PDO::FETCH_COLUMN);
    $save = $pdo->prepare("REPLACE INTO purpose_lookup (code, status, peruntukan, category, err, checked_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $fill = $pdo->prepare("UPDATE scans SET peruntukan = ? WHERE code = ? AND peruntukan = ''");
    $res = ['checked' => 0, 'found' => 0, 'notfound' => 0, 'error' => 0, 'scans' => 0, 'last_error' => null];
    $t0 = time();
    foreach ($codes as $code) {
        if (time() - $t0 > FILL_SECONDS) break;
        [$status, $peru, $cat, $err] = lookup_one($url, $code);
        $save->execute([$code, $status, $peru, $cat, $err]);
        $res['checked']++;
        $res[$status]++;
        if ($err) $res['last_error'] = $err;
        if ($peru !== '') { $fill->execute([$peru, $code]); $res['scans'] += $fill->rowCount(); }
    }
    $pdo->query("SELECT RELEASE_LOCK('gatemon_purpose_fill')")->fetchColumn();
    json_out(['ok' => true, 'batch' => $res] + counts());
}
json_out(['ok' => false, 'error' => 'aksi tidak dikenal'], 400);
