<?php
// Halaman instalasi agent di laptop gate: http://SERVER/gatemon/install/ID-LAPTOP
//  GET  /install/ID              -> form username + password sekali pakai
//  POST /install/ID              -> verifikasi kode (hangus + token baru), buat tiket unduhan 30 menit
//  GET  /install/ID/paket.zip?t= -> paket lengkap (agent + config.json + python + wheels): Extract All lalu install_agent.bat
require __DIR__ . '/../db.php';

$id = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['id'] ?? ''));
$file = $_GET['f'] ?? '';
$base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');   // contoh: /gatemon
$origin = (is_https() ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));   // server publik: isi agent_server_url di config
$server = !empty($CFG['agent_server_url']) ? rtrim($CFG['agent_server_url'], '/') : $origin . $base;
$self = $origin . $base . '/install/' . rawurlencode($id);
const TICKET_MIN = 30;

$d = null;
if ($id !== '') {
    $st = db()->prepare("SELECT d.*, c.name AS category_name FROM devices d LEFT JOIN categories c ON c.id=d.category_id WHERE device_key=?");
    $st->execute([$id]);
    $d = $st->fetch() ?: null;
}

// ---------- unduhan (perlu tiket dari login yang berhasil) ----------
if ($file === 'run.bat' || $file === 'paket.zip') {
    $t = (string)($_GET['t'] ?? '');
    $ok = $d && $d['install_ticket'] && preg_match('/^[a-f0-9]{48}$/', $t)
        && hash_equals($d['install_ticket'], hash('sha256', $t)) && strtotime($d['install_ticket_expires']) > time();
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Link unduhan tidak berlaku atau sudah kedaluwarsa ($id).\r\nBuka $self lalu login ulang dengan kode instalasi baru.\r\n");
    }
    // Installer .bat pengunduh (bat -> PowerShell unduh ZIP -> jalankan skrip) dihapus: pola itu diblokir antivirus
    // ("Failed - Virus detected" di Windows Defender). Sekarang paket ZIP diunduh langsung, diekstrak, lalu
    // install_agent.bat di dalamnya dijalankan (tidak mengunduh apa pun). Link .bat lama: arahkan ke cara baru.
    if ($file === 'run.bat') {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Installer .bat sudah diganti paket ZIP. Buka $self lalu login ulang dengan kode instalasi.\r\n");
    }
    // paket.zip
    // ZIP dibuat lewat zip_create(): tetap jalan walau ekstensi PHP zip belum aktif
    @set_time_limit(300);
    $tmp = tempnam(sys_get_temp_dir(), 'gm');
    $zip = zip_create($tmp);
    $folder = "GateMon-Agent-$id-v" . agent_version() . '/';
    $extra = agent_zip_files($zip, $folder);
    $zip->addFromString($folder . 'config.json', json_encode(agent_config($d, $server), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $zip->addFromString($folder . 'BACA-SAYA.txt', implode("\r\n", [
        "GATEMON AGENT - $id ({$d['name']})", "Server: $server", '',
        'Klik dua kali install_agent.bat dan setujui izin Administrator.',
        'config.json sudah berisi token laptop ini - JANGAN dipakai di laptop lain.',
        'Python ' . (preg_grep('#^python-.*\.exe$#i', $extra) ? 'ikut di paket ini dan dipasang otomatis bila belum ada.' : 'TIDAK ikut di paket ini; laptop harus sudah punya Python 3.'),
    ]) . "\r\n");
    $zip->close();
    log_event($d['id'], 'info', "{$d['name']}: paket instalasi diunduh dari " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="GateMon-Agent-' . $id . '-v' . agent_version() . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ---------- halaman ----------
$err = '';
$done = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $d) {
    $user = strtoupper(trim($_POST['username'] ?? ''));
    if ($user !== $id) { usleep(800000); $err = "Username harus $id (sesuai alamat halaman ini)."; }
    else {
        $r = enroll_verify($user, $_POST['password'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '');
        if (!$r['ok']) $err = ucfirst($r['error']) . '.';
        else {
            $t = bin2hex(random_bytes(24));
            db()->prepare("UPDATE devices SET install_ticket=?, install_ticket_expires=? WHERE id=?")
                ->execute([hash('sha256', $t), date('Y-m-d H:i:s', time() + TICKET_MIN * 60), $d['id']]);
            $done = ['zip' => "$self/paket.zip?t=$t"];
        }
    }
    if ($err) { $st = db()->prepare("SELECT * FROM devices WHERE id=?"); $st->execute([$d['id']]); $d = array_merge($d, $st->fetch()); }
}
$active = $d && $d['enroll_hash'] && $d['enroll_fail'] < 5 && strtotime($d['enroll_expires']) > time();
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
header('X-Robots-Tag: noindex');
send_csp();
?><!doctype html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><meta name="color-scheme" content="dark light">
<title>Instalasi agent <?= e($id) ?> - <?= e($CFG['app_name']) ?></title>
<link rel="stylesheet" href="<?= e($base) ?>/admin/style.css?v=18">
<script nonce="<?= csp_nonce() ?>">(function(){try{var t=localStorage.getItem("gm_theme");}catch(e){} if(!t)t=matchMedia("(prefers-color-scheme: light)").matches?"light":"dark"; document.documentElement.setAttribute("data-theme",t);})();</script>
<?php if ($done): ?><meta http-equiv="refresh" content="1;url=<?= e($done['zip']) ?>"><?php endif; ?>
</head>
<body class="login-body">
<div class="login-box install-box">
  <span class="logo"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg></span>
  <h1>Instalasi GateMon Agent</h1>
  <p class="muted small inst-ver">Versi agent <b>v<?= e(agent_version()) ?></b></p>
<?php if (!$d): ?>
  <p class="notice error">Laptop <b><?= e($id ?: '(kosong)') ?></b> tidak terdaftar. Periksa alamat: <code><?= e($origin . $base) ?>/install/ID-LAPTOP</code></p>
<?php elseif ($done): ?>
  <div class="inst-dev"><b><?= e($d['name']) ?></b><code><?= e($id) ?></code></div>
  <p class="notice">Login berhasil. Unduhan <b>GateMon-Agent-<?= e($id) ?>-v<?= e(agent_version()) ?>.zip</b> dimulai otomatis.</p>
  <ol class="steps">
    <li>Buka folder Downloads, klik kanan file ZIP tadi → <b>Extract All…</b> → <b>Extract</b>.<br><span class="muted">Jangan dijalankan langsung dari dalam ZIP.</span></li>
    <li>Di folder hasil ekstrak, klik dua kali <b>install_agent.bat</b>.<br><span class="muted">Bila muncul "Windows protected your PC", klik <i>More info</i> lalu <i>Run anyway</i>.</span></li>
    <li>Setujui izin Administrator, tunggu sampai selesai: Python dan library dipasang dari paket (tanpa internet), agent langsung berjalan.</li>
  </ol>
  <a class="btn btn-primary" href="<?= e($done['zip']) ?>">Unduh ulang paket ZIP</a>
  <p class="muted small">Link unduhan berlaku <?= TICKET_MIN ?> menit dan hanya untuk laptop ini. Kode instalasi sudah hangus.</p>
<?php else: ?>
  <div class="inst-dev"><b><?= e($d['name']) ?></b><code><?= e($id) ?></code><?= $d['category_name'] ? '<span class="badge">' . e($d['category_name']) . '</span>' : '' ?></div>
  <?php if ($err): ?><p class="notice error"><?= e($err) ?></p><?php endif; ?>
  <?php if (!$active && !$err): ?>
  <p class="notice error">Belum ada kode instalasi aktif untuk laptop ini. Minta supervisor membuat <b>Kode instalasi</b> di menu Devices.</p>
  <?php endif; ?>
  <form method="post" class="inst-form" autocomplete="off">
    <label>Username <input name="username" value="<?= e($_POST['username'] ?? $id) ?>" required autocapitalize="characters"></label>
    <label>Password sekali pakai <input name="password" type="password" required autofocus placeholder="XXXX-XXXX" autocomplete="one-time-code"></label>
    <button class="btn btn-primary" type="submit">Masuk &amp; unduh installer</button>
  </form>
  <p class="muted small">Installer akan memasang Python (bila belum ada), library, dan agent di <code>C:\GateMon-Agent</code>, lalu menjalankannya otomatis setiap laptop login.</p>
<?php endif; ?>
</div>
</body></html>
