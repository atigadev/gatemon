<?php
// Pengamanan dasar untuk setiap request web (di-include db.php): HTTPS, header keamanan, error tidak bocor ke browser,
// alamat IP klien, dan pembatasan percobaan (login / token agent / kode instalasi) lewat tabel auth_fail.

function is_https() {
    global $CFG;
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') return true;
    // di belakang proxy/CDN (mis. Cloudflare) hanya bila diizinkan di config: header ini bisa dipalsukan klien
    return !empty($CFG['trust_proxy']) && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function client_ip() {
    global $CFG;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!empty($CFG['trust_proxy'])) {
        $f = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0]);
        if (filter_var($f, FILTER_VALIDATE_IP)) $ip = $f;
    }
    return substr($ip, 0, 45);
}

// Nonce acak per request untuk <script> inline (Content-Security-Policy)
function csp_nonce() {
    static $n = null;
    if ($n === null) $n = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    return $n;
}
// Halaman HTML admin/instalasi: hanya skrip dari server ini + skrip inline bernonce. Gambar/iframe http(s) diizinkan
// untuk live camera (Agent DVR di IP LAN laptop).
function send_csp() {
    if (headers_sent()) return;
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob: http: https:; media-src 'self' blob: http: https:; frame-src 'self' http: https:; connect-src 'self'; "
        . "font-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
}

function security_bootstrap() {
    global $CFG;
    if (PHP_SAPI === 'cli') return;
    if (empty($CFG['debug'])) {
        // pesan error (path file, query SQL) tidak boleh terlihat pengunjung; tetap dicatat di error log server
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        set_exception_handler(function ($e) {
            error_log('GateMon: ' . $e);
            if (!headers_sent()) {
                http_response_code(500);
                $json = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'json') !== false || preg_match('#/(api/|admin/[a-z_]+_api\.php|admin/(status|command|scans)\.php)#', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
                header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
                header('Cache-Control: no-store');
                echo $json ? '{"ok":false,"error":"kesalahan server"}' : 'Terjadi kesalahan di server. Detail tercatat di error log.';
            }
        });
    }
    if (headers_sent()) return;
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');                       // data dashboard/token tidak disimpan cache browser/proxy
    $https = is_https();
    if ($https && !empty($CFG['force_https'])) header('Strict-Transport-Security: max-age=31536000');
    if (!empty($CFG['force_https']) && !$https) {
        // GET: alihkan ke HTTPS. Lainnya (POST agent/form): tolak, karena isinya (password/token) sudah terkirim tanpa enkripsi
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && $host !== '') {
            header('Location: https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
            exit;
        }
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        exit('{"ok":false,"error":"wajib memakai https"}');
    }
}

// ---------- pembatasan percobaan ----------
// scope: login | device | enroll. Dihitung dalam jendela $minutes menit; data lama dibersihkan sesekali.
function auth_fail_count($scope, $ip, $name = null, $minutes = 15) {
    $sql = "SELECT COUNT(*) FROM auth_fail WHERE scope=? AND created_at > NOW() - INTERVAL " . (int)$minutes . " MINUTE AND ";
    $st = db()->prepare($sql . ($name === null ? 'ip=?' : 'name=?'));
    $st->execute([$scope, $name === null ? $ip : $name]);
    return (int)$st->fetchColumn();
}
function auth_fail_add($scope, $ip, $name = '') {
    db()->prepare("INSERT INTO auth_fail (scope, ip, name, created_at) VALUES (?, ?, ?, NOW())")->execute([$scope, $ip, mb_substr((string)$name, 0, 100)]);
    if (mt_rand(1, 50) === 1) db()->exec("DELETE FROM auth_fail WHERE created_at < NOW() - INTERVAL 2 DAY");
}
function auth_fail_clear($scope, $ip, $name = null) {
    if ($name !== null) db()->prepare("DELETE FROM auth_fail WHERE scope=? AND name=?")->execute([$scope, $name]);
    db()->prepare("DELETE FROM auth_fail WHERE scope=? AND ip=?")->execute([$scope, $ip]);
}

// Aturan password akun admin. Mengembalikan pesan kesalahan, atau '' bila memenuhi.
function password_problem($pass, $user = '') {
    $p = (string)$pass;
    if (strlen($p) < 10) return 'Password minimal 10 karakter.';
    if (strlen($p) > 200) return 'Password maksimal 200 karakter.';
    $low = strtolower($p);
    if ($user !== '' && strpos($low, strtolower($user)) !== false) return 'Password tidak boleh memuat username.';
    if (count(array_unique(str_split($p))) < 5) return 'Password terlalu mudah ditebak (karakter terlalu sedikit variasinya).';
    foreach (['password', 'admin123', 'viewer123', 'qwerty', '12345678', 'gatemon', 'abcdef', '11111111'] as $w) {
        if (strpos($low, $w) !== false) return 'Password terlalu umum (memuat "' . $w . '").';
    }
    return '';
}
