<?php
// Web Push (PWA): kirim notifikasi DARURAT & chat dari laptop ke HP/PC admin, juga saat aplikasi tertutup.
// Tanpa library tambahan (PHP 7.3+ dengan ekstensi openssl): VAPID (RFC 8292, JWT ES256) + enkripsi isi aes128gcm (RFC 8291).
// Isi notifikasi terenkripsi end-to-end: layanan push browser (Google / Mozilla / Microsoft / Apple) tidak bisa membacanya.
// Kunci VAPID dibuat otomatis sekali dan disimpan di tabel settings (vapid_d = kunci rahasia, vapid_pub = kunci publik).
if (!function_exists('db')) { http_response_code(404); exit; }   // hanya untuk di-include

const PUSH_TIMEOUT = 6;   // detik per kiriman
// Endpoint hanya boleh ke layanan push browser resmi: server tidak bisa disuruh mengirim request ke alamat lain (SSRF)
const PUSH_HOSTS = '/(^|\.)(fcm\.googleapis\.com|android\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com|push\.apple\.com)$/i';

function b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec($s) { return (string)base64_decode(strtr((string)$s, '-_', '+/') . str_repeat('=', (4 - strlen((string)$s) % 4) % 4), true); }

// Fitur push bisa dipakai di server ini? [true, ''] atau [false, alasan]
function push_ready() {
    if (!function_exists('openssl_pkey_derive') || !function_exists('openssl_pkey_new')) return [false, 'ekstensi PHP openssl belum aktif (php.ini: extension=openssl), butuh PHP 7.3+'];
    if (!function_exists('curl_init') && !in_array('https', stream_get_wrappers(), true)) return [false, 'PHP tidak bisa membuka https (aktifkan extension=openssl)'];
    return [true, ''];
}
function push_endpoint_ok($url) {
    $p = parse_url((string)$url);
    return is_string($url) && strlen($url) <= 600 && ($p['scheme'] ?? '') === 'https' && !isset($p['user']) && !isset($p['port'])
        && preg_match(PUSH_HOSTS, (string)($p['host'] ?? ''));
}

// openssl di Windows butuh file openssl.cnf untuk membuat kunci
function ssl_cnf() {
    foreach ([getenv('OPENSSL_CONF'), dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf', 'C:/xampp/php/extras/ssl/openssl.cnf',
              'C:/xampp/apache/conf/openssl.cnf', dirname(PHP_BINARY) . '/../apache/conf/openssl.cnf'] as $f) {
        if ($f && is_file($f)) return ['config' => $f];
    }
    return [];
}
function ec_new_key() {
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'] + ssl_cnf());
    if (!$k) throw new RuntimeException('gagal membuat kunci EC: ' . openssl_error_string());
    $e = openssl_pkey_get_details($k)['ec'];
    $pad = function ($v) { return str_pad($v, 32, "\0", STR_PAD_LEFT); };
    return [$k, "\x04" . $pad($e['x']) . $pad($e['y']), $pad($e['d'])];
}
function ec_pub_pem($raw) {   // kunci publik P-256 mentah (65 byte, 04||x||y) -> PEM
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function ec_priv_pem($d, $pub) {   // kunci rahasia mentah (32 byte) + publik -> PEM (SEC1)
    $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $pub;
    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

// Kunci VAPID server: dibuat sekali (kunci publiknya dipakai browser saat berlangganan)
function vapid_keys() {
    static $v = null;
    if ($v) return $v;
    $s = settings_all();
    $d = b64u_dec($s['vapid_d'] ?? ''); $pub = b64u_dec($s['vapid_pub'] ?? '');
    if (strlen($d) !== 32 || strlen($pub) !== 65) {
        [, $pub, $d] = ec_new_key();
        $st = db()->prepare("INSERT IGNORE INTO settings (k, v, updated_at) VALUES (?, ?, NOW())");
        $st->execute(['vapid_d', b64u($d)]);
        $st->execute(['vapid_pub', b64u($pub)]);
        // dua request bersamaan: pakai kunci yang benar-benar tersimpan
        $r = db()->query("SELECT k, v FROM settings WHERE k IN ('vapid_d', 'vapid_pub')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $d = b64u_dec($r['vapid_d']); $pub = b64u_dec($r['vapid_pub']);
    }
    return $v = ['d' => $d, 'pub' => $pub, 'pem' => ec_priv_pem($d, $pub)];
}
function vapid_public() { return b64u(vapid_keys()['pub']); }

// Header Authorization VAPID untuk endpoint ini (JWT ES256, berlaku 12 jam)
function vapid_auth($endpoint) {
    global $CFG;
    $p = parse_url($endpoint);
    $claims = ['aud' => $p['scheme'] . '://' . $p['host'], 'exp' => time() + 12 * 3600, 'sub' => (string)($CFG['push_contact'] ?? 'mailto:admin@gatemon.invalid')];
    $in = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($in, $der, vapid_keys()['pem'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('gagal menandatangani VAPID');
    // tanda tangan DER (SEQUENCE r, s) -> r||s masing-masing 32 byte
    $o = 3 + (ord($der[1]) & 0x80 ? 1 : 0);
    $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl);
    $sl = ord($der[$o + $rl + 2]); $s = substr($der, $o + $rl + 3, $sl);
    $fix = function ($x) { return str_pad(ltrim($x, "\0"), 32, "\0", STR_PAD_LEFT); };
    return 'vapid t=' . $in . '.' . b64u($fix($r) . $fix($s)) . ', k=' . vapid_public();
}

// Enkripsi isi untuk satu browser (RFC 8291, aes128gcm, satu record). $fixed = [kunci, publik, salt] hanya untuk uji vektor RFC.
function push_encrypt($plain, $ua_pub, $auth, $fixed = null) {
    [$k, $as_pub] = $fixed ?: ec_new_key();
    $ecdh = openssl_pkey_derive(ec_pub_pem($ua_pub), $k, 32);
    if ($ecdh === false) throw new RuntimeException('kunci browser tidak valid');
    $prk_key = hash_hmac('sha256', $ecdh, $auth, true);
    $ikm = hash_hmac('sha256', "WebPush: info\0" . $ua_pub . $as_pub . "\x01", $prk_key, true);
    $salt = $fixed ? $fixed[2] : random_bytes(16);
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
    $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);
    $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as_pub . $ct . $tag;
}

// POST ke layanan push: [kode HTTP, error]
function push_post($url, array $headers, $body) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $ca = ca_bundle();
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => PUSH_TIMEOUT] + ($ca ? [CURLOPT_CAINFO => $ca] : []));
        $ok = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = $ok === false ? curl_error($ch) : ($http >= 300 ? mb_substr(trim(strip_tags((string)$ok)), 0, 150) : '');
        curl_close($ch);
        return [$ok === false ? 0 : $http, $err];
    }
    $ca = ca_bundle();
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => PUSH_TIMEOUT, 'follow_location' => 0, 'ignore_errors' => true],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true] + ($ca ? ['cafile' => $ca] : [])]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) return [0, preg_replace('/^fopen\([^)]*\):\s*/', '', (string)(error_get_last()['message'] ?? 'gagal terhubung'))];
    $resp = (string)stream_get_contents($fp, 2000);
    $head = stream_get_meta_data($fp)['wrapper_data'] ?? [];
    fclose($fp);
    $http = preg_match('#^HTTP/\S+\s+(\d{3})#', (string)($head[0] ?? ''), $m) ? (int)$m[1] : 0;
    return [$http, $http >= 300 || !$http ? mb_substr(trim(strip_tags($resp)), 0, 150) : ''];
}

// Kirim ke satu langganan; status disimpan (langganan yang sudah tidak berlaku dihapus). [ok, pesan]
function push_send_sub(array $s, array $msg) {
    $pdo = db();
    try {
        $body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), b64u_dec($s['p256dh']), b64u_dec($s['auth']));
        [$http, $err] = push_post($s['endpoint'], ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm',
            'TTL: ' . ($msg['kind'] === 'emergency' ? 3600 : 86400), 'Urgency: high', 'Authorization: ' . vapid_auth($s['endpoint'])], $body);
    } catch (Exception $e) {
        [$http, $err] = [0, $e->getMessage()];
    }
    if ($http >= 200 && $http < 300) {
        $pdo->prepare("UPDATE push_subs SET last_ok=NOW(), last_err=NULL, fails=0 WHERE id=?")->execute([$s['id']]);
        return [true, ''];
    }
    if ($http === 404 || $http === 410) {   // browser berhenti berlangganan / data situs dihapus
        $pdo->prepare("DELETE FROM push_subs WHERE id=?")->execute([$s['id']]);
        return [false, 'langganan sudah tidak berlaku (dihapus)'];
    }
    $err = mb_substr(($http ? "HTTP $http" : 'gagal terhubung') . ($err !== '' ? ": $err" : ''), 0, 200);
    $pdo->prepare("UPDATE push_subs SET last_err=?, fails=fails+1 WHERE id=?")->execute([$err, $s['id']]);
    return [false, $err];
}

// Notifikasi dari laptop: kind emergency (semua akun yang berlangganan) atau chat (petugas & supervisor)
function push_notify($kind, array $d, $text, $msg_id) {
    if (!push_ready()[0]) return;
    if (!isset($d['category_name'])) {
        $st = db()->prepare("SELECT name FROM categories WHERE id=?");
        $st->execute([(int)$d['category_id']]);
        $d['category_name'] = (string)$st->fetchColumn();
    }
    $where = trim(implode(' · ', array_filter([(string)($d['category_name'] ?? ''), (string)$d['gate']])));
    $msg = $kind === 'emergency'
        ? ['kind' => 'emergency', 'title' => '🚨 DARURAT: ' . $d['name'], 'body' => ($where !== '' ? "$where · " : '') . mb_substr((string)$text, 0, 200)]
        : ['kind' => 'chat', 'title' => '💬 Pesan dari ' . $d['name'], 'body' => mb_substr((string)$text, 0, 300)];
    $msg += ['tag' => $kind . '-' . $d['id'], 'url' => 'support.php?dev=' . (int)$d['id'], 'id' => (int)$msg_id, 'ts' => time() * 1000];
    $sql = "SELECT s.* FROM push_subs s JOIN admins a ON a.id = s.admin_id WHERE "
         . ($kind === 'emergency' ? "s.want_emg = 1" : "s.want_chat = 1 AND a.role IN ('operator', 'supervisor')");
    $t0 = time();
    foreach (db()->query($sql)->fetchAll() as $s) {
        if (time() - $t0 > 40) break;
        push_send_sub($s, $msg);
    }
}
