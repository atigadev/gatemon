<?php
// Daftar laptop + token untuk agent. Tambah/edit/hapus hanya oleh supervisor; petugas hanya membuat/membatalkan kode instalasi.
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$msg = '';
$form_err = null; // isi form tambah yang gagal disimpan (modal dibuka lagi dengan isian ini)
// gate = pantau browser + halaman gate; monitor = hanya pantau koneksi laptop
const MODES = ['gate' => 'Gate (browser + halaman gate)', 'monitor' => 'Monitor koneksi saja'];
function post_mode() { return ($_POST['mode'] ?? '') === 'monitor' ? 'monitor' : 'gate'; }
$cats = $pdo->query("SELECT id, name, color FROM categories ORDER BY sort, name")->fetchAll();
$wallpapers = $pdo->query("SELECT id, name, width, height FROM wallpapers ORDER BY id DESC")->fetchAll();
$wp_ids = array_column($wallpapers, 'id');
// wallpaper laptop: '' = ikut kategori/bawaan (NULL), '0' = jangan ubah, id = wallpaper tertentu
function post_wallpaper() {
    global $wp_ids;
    $v = $_POST['wallpaper_id'] ?? '';
    if ($v === '' || $v === null) return null;
    $v = (int)$v;
    return ($v === 0 || in_array($v, array_map('intval', $wp_ids), true)) ? $v : null;
}
function wp_select($form, $cur) {
    global $wallpapers;
    $cur = ($cur === null || $cur === '') ? '' : (string)(int)$cur;
    $o = '<option value=""' . ($cur === '' ? ' selected' : '') . '>Ikut kategori / bawaan</option>'
       . '<option value="0"' . ($cur === '0' ? ' selected' : '') . '>— Jangan ubah —</option>';
    foreach ($wallpapers as $w) $o .= '<option value="' . $w['id'] . '"' . ($cur === (string)$w['id'] ? ' selected' : '') . '>' . h($w['name']) . " ({$w['width']}x{$w['height']})</option>";
    return '<select name="wallpaper_id"' . ($form ? ' form="' . h($form) . '"' : '') . '>' . $o . '</select>';
}
// Live camera: cam_mode none -> '', auto -> 'auto' (Agent DVR di laptop itu, lihat camera_link()), custom -> URL (boleh {ip})
// URL halaman gate: 1 per baris (maks GATE_URL_MAX). >1 URL = dibuka berdampingan kiri-kanan. Kosong = URL default.
function post_gate_urls() {
    $l = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R+/', (string)($_POST['gate_url'] ?? ''))))));
    return implode("\n", $l);
}
function gate_urls_invalid() {
    $l = post_gate_urls() === '' ? [] : explode("\n", post_gate_urls());
    if (count($l) > GATE_URL_MAX) return 'Maksimal ' . GATE_URL_MAX . ' URL halaman per laptop.';
    foreach ($l as $u) if (!preg_match('#^https?://[^\s"\'<>]+$#i', $u) || strlen($u) > 480) return 'URL halaman tidak valid: ' . $u;
    return '';
}
function post_cam() {
    $m = $_POST['cam_mode'] ?? 'custom';
    if ($m === 'none') return '';
    if ($m === 'auto') return 'auto';
    return trim($_POST['camera_url'] ?? '');
}
function cam_invalid() { $u = post_cam(); return $u !== '' && $u !== 'auto' && !preg_match('#^https?://[^\s"\'<>]+$#i', $u); }
function post_cat() { $c = (int)($_POST['category_id'] ?? 0); return $c > 0 ? $c : null; }
function cat_select($form, $cur, $ro) {
    global $cats;
    $o = '<option value="0">— tanpa kategori —</option>';
    foreach ($cats as $c) {
        $sel = (int)$c['id'] === (int)$cur;
        $o .= '<option value="' . $c['id'] . '"' . ($sel ? ' selected' : '') . ($ro && !$sel ? ' disabled' : '') . '>' . h($c['name']) . '</option>';
    }
    return '<select name="category_id"' . ($form ? ' form="' . $form . '"' : '') . '>' . $o . '</select>';
}
$act = $_POST['act'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (is_supervisor() || (is_operator() && in_array($act, ['enroll', 'enroll_cancel'], true)))) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    if ($act === 'add') {
        $key = strtoupper(trim($_POST['device_key']));
        if ($key === '' || !preg_match('/^[A-Z0-9_-]{1,50}$/', $key)) { $msg = 'ID laptop hanya huruf/angka, contoh G003.'; $form_err = $_POST; }
        elseif (trim($_POST['name'] ?? '') === '') { $msg = 'Nama laptop wajib diisi.'; $form_err = $_POST; }
        elseif (cam_invalid()) { $msg = 'URL kamera tidak valid, harus diawali http:// atau https://'; $form_err = $_POST; }
        elseif (gate_urls_invalid()) { $msg = gate_urls_invalid(); $form_err = $_POST; }
        else {
            $token = bin2hex(random_bytes(16));
            try {
                $pdo->prepare("INSERT INTO devices (device_key,name,gate,category_id,gate_url,mode,camera_url,wallpaper_id,token,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")
                    ->execute([$key, trim($_POST['name']), trim($_POST['gate']), post_cat(), post_gate_urls(), post_mode(), post_cam(), post_wallpaper(), $token]);
                $msg = "Laptop $key ditambahkan.";
            } catch (Exception $e) { $msg = "ID laptop $key sudah ada."; $form_err = $_POST; }
        }
    } elseif ($act === 'edit' && trim($_POST['name'] ?? '') === '') {
        $msg = 'Nama laptop wajib diisi.';
    } elseif ($act === 'edit' && gate_urls_invalid()) {
        $msg = gate_urls_invalid();
    } elseif ($act === 'edit' && cam_invalid()) {
        $msg = 'URL kamera tidak valid, harus diawali http:// atau https://';
    } elseif ($act === 'edit') {
        $pdo->prepare("UPDATE devices SET name=?, gate=?, category_id=?, gate_url=?, mode=?, camera_url=?, wallpaper_id=? WHERE id=?")
            ->execute([trim($_POST['name']), trim($_POST['gate']), post_cat(), post_gate_urls(), post_mode(), post_cam(), post_wallpaper(), (int)$_POST['id']]);
        $msg = 'Disimpan.';
    } elseif ($act === 'enroll') {
        // Kode instalasi sekali pakai: username = ID laptop, password acak (tanpa huruf/angka yang mirip)
        $st = $pdo->prepare("SELECT device_key, name FROM devices WHERE id=?");
        $st->execute([(int)$_POST['id']]);
        if ($dv = $st->fetch()) {
            $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            $pw = '';
            for ($i = 0; $i < 8; $i++) $pw .= $abc[random_int(0, strlen($abc) - 1)];
            $hours = setting('enroll_valid_hours');
            $exp = date('Y-m-d H:i:s', time() + $hours * 3600);
            $pdo->prepare("UPDATE devices SET enroll_hash=?, enroll_expires=?, enroll_fail=0 WHERE id=?")
                ->execute([password_hash($pw, PASSWORD_DEFAULT), $exp, (int)$_POST['id']]);
            log_event((int)$_POST['id'], 'info', "{$dv['name']}: kode instalasi agent dibuat oleh " . current_user()['username']);
            // password hanya ditampilkan sekali (tersimpan di server dalam bentuk hash)
            $_SESSION['enroll_show'] = ['key' => $dv['device_key'], 'name' => $dv['name'], 'pass' => substr($pw, 0, 4) . '-' . substr($pw, 4), 'exp' => $exp];
            $msg = "Kode instalasi untuk {$dv['device_key']} dibuat.";
        }
    } elseif ($act === 'enroll_cancel') {
        $pdo->prepare("UPDATE devices SET enroll_hash=NULL, enroll_expires=NULL, enroll_fail=0 WHERE id=?")->execute([(int)$_POST['id']]);
        $msg = 'Kode instalasi dibatalkan.';
    } elseif ($act === 'regen') {
        $pdo->prepare("UPDATE devices SET token=? WHERE id=?")->execute([bin2hex(random_bytes(16)), (int)$_POST['id']]);
        $msg = 'Token baru dibuat. Perbarui config.json di laptop tersebut.';
    } elseif ($act === 'delete') {
        $pdo->prepare("DELETE FROM commands WHERE device_id=?")->execute([(int)$_POST['id']]);
        $pdo->prepare("DELETE FROM devices WHERE id=?")->execute([(int)$_POST['id']]);
        $msg = 'Laptop dihapus.';
    }
}
// Sukses: redirect (PRG) agar refresh browser tidak mengirim ulang form
if ($msg !== '' && $form_err === null) { $_SESSION['flash'] = $msg; header('Location: devices.php'); exit; }
if ($msg === '' && isset($_SESSION['flash'])) { $msg = $_SESSION['flash']; unset($_SESSION['flash']); }

$devices = $pdo->query("SELECT d.*, c.name AS category_name, c.color AS category_color FROM devices d
    LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.id IS NULL, c.sort, c.name, d.gate, d.name")->fetchAll();
$csrf = csrf_token();
$sup = is_supervisor();
$op = is_operator();   // petugas: kolom Instalasi + tombol kode instalasi saja
$host = $_SERVER['HTTP_HOST'];
// Dibuka dari localhost: ganti dengan IP LAN agar bisa dipakai laptop gate
if (preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/i', $host, $m)) {
    $lan = gethostbyname(gethostname());
    if ($lan && strpos($lan, '127.') !== 0) $host = $lan . ($m[2] ?? '');
}
$server_url = !empty($CFG['agent_server_url']) ? rtrim($CFG['agent_server_url'], '/')
    : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $host . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

function ago($ts) {
    if (!$ts) return 'belum pernah';
    $s = time() - strtotime($ts);
    if ($s < 60) return $s . ' dtk lalu';
    if ($s < 3600) return floor($s / 60) . ' mnt lalu';
    if ($s < 86400) return floor($s / 3600) . ' jam lalu';
    return floor($s / 86400) . ' hari lalu';
}
const STATE_LABEL = ['ok' => 'Online', 'warn' => 'Perhatian', 'offline' => 'Offline'];

page_head('Devices');
topbar('devices', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head">
    <div><h1>Devices</h1><p class="muted">Daftar laptop yang dipantau, token agent, dan paket instalasi agent.</p></div>
    <?php if ($sup): ?><button class="btn btn-primary" type="button" data-dev-add><?= icon('plus') ?> Tambah laptop</button><?php endif; ?>
  </div>
<?= flash($form_err === null ? $msg : '') ?>

<?php if ($sup): ?>
<section class="card agent-pkg">
  <div class="agent-pkg-txt">
    <h2><?= icon('download') ?> Paket instalasi agent</h2>
    <p class="muted"><b>Cara utama:</b> klik tombol <b>Kode instalasi</b> (ikon masuk) pada laptop, lalu di laptop tersebut buka <code><?= h($server_url) ?>/install/ID-LAPTOP</code> dan masukkan username &amp; password sekali pakai. Installer memasang Python, library, dan agent otomatis. File installer Python/wheels disiapkan di folder <code>agent/installer</code> di server.</p>
  </div>
  <form method="get" action="download_agent.php" id="dl" class="agent-pkg-form">
    <label>URL server untuk agent (pakai IP LAN, bukan localhost)<input name="server" value="<?= h($server_url) ?>" required></label>
    <button class="btn btn-primary" name="id" value="installer"><?= icon('download') ?> Installer umum (ZIP)</button>
    <button class="btn" name="id" value="all" <?= $devices ? '' : 'disabled' ?> title="Satu folder per laptop, config.json berisi token"><?= icon('download') ?> ZIP per laptop</button>
  </form>
</section>
<?php elseif ($op): ?>
<p class="notice">Pasang / pasang ulang agent: klik tombol <b>Kode instalasi</b> (ikon masuk) pada laptop, lalu di laptop tersebut buka <code><?= h($server_url) ?>/install/ID-LAPTOP</code> dan masukkan username &amp; password sekali pakai. Menambah / mengubah / menghapus laptop hanya oleh supervisor.</p>
<?php else: ?>
<p class="muted">URL server untuk <code>config.json</code> agent: <code><?= h($server_url) ?></code></p>
<?php endif; ?>

<section class="card">
  <div class="table-tools">
    <h2><?= icon('devices') ?> Daftar laptop <span class="badge" id="dev-count"><?= count($devices) ?></span></h2>
    <div class="filters">
      <input type="search" id="dev-q" placeholder="Cari ID, nama, pos, IP…" aria-label="Cari laptop">
      <select id="dev-cat" aria-label="Filter kategori">
        <option value="">Semua kategori</option>
        <?php foreach ($cats as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        <option value="none">Tanpa kategori</option>
      </select>
    </div>
  </div>
  <?php if (!$devices): ?>
  <div class="empty"><?= icon('devices', 'ic big') ?><p>Belum ada laptop.<?= $sup ? ' Klik <b>Tambah laptop</b> untuk mulai.' : '' ?></p></div>
  <?php else: ?>
  <div class="table-wrap"><table class="table dev-table" id="dev-table">
    <thead><tr><th>Laptop</th><th>Kategori</th><th>Gate / pos</th><th>Mode</th><th>Status</th><th>IP</th><th>Terakhir lapor</th><?php if ($op): ?><th>Instalasi</th><?php endif; ?><?php if ($sup): ?><th>Token agent</th><?php endif; ?><?php if ($op): ?><th class="right">Aksi</th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($devices as $d):
        $st = device_state($d, setting('offline_after'));
        $cat = $d['category_color'] ?: '#64748b';
        $data = ['id' => (int)$d['id'], 'device_key' => $d['device_key'], 'name' => $d['name'], 'gate' => $d['gate'],
                 'category_id' => (int)$d['category_id'], 'mode' => $d['mode'], 'gate_url' => $d['gate_url'], 'camera_url' => $d['camera_url'],
                 'wallpaper_id' => $d['wallpaper_id'] === null ? '' : (string)(int)$d['wallpaper_id']];
        $search = strtolower($d['device_key'] . ' ' . $d['name'] . ' ' . $d['gate'] . ' ' . $d['ip'] . ' ' . $d['hostname'] . ' ' . $d['category_name']);
    ?>
    <tr data-cat="<?= $d['category_id'] ? (int)$d['category_id'] : 'none' ?>" data-q="<?= h($search) ?>">
      <td data-label="Laptop"><div class="dev-name"><b><?= h($d['name']) ?><?= $d['camera_url'] !== '' ? ' <span class="cam-flag" title="' . ($d['camera_url'] === 'auto' ? 'Live camera: Agent DVR di laptop ini' : 'Live camera: URL custom') . '">' . icon('camera') . '</span>' : '' ?></b><code><?= h($d['device_key']) ?></code></div></td>
      <td data-label="Kategori"><span class="cat-badge" style="--cat:<?= h($cat) ?>"><i></i><?= h($d['category_name'] ?: 'Tanpa kategori') ?></span></td>
      <td data-label="Gate / pos"><?= $d['gate'] !== '' ? h($d['gate']) : '<span class="muted">–</span>' ?></td>
      <td data-label="Mode"><span class="badge"><?= $d['mode'] === 'monitor' ? 'Monitor koneksi' : 'Gate' ?></span><?= scan_enabled($d) ? ' <span class="badge ok-badge" title="URL halaman gate cocok pola halaman scan: hasil scan ditangkap">Scan</span>' : '' ?><?php $gu = gate_urls_of($d); echo count($gu) > 1 ? ' <span class="badge" title="' . h(implode(' | ', $gu)) . '">' . count($gu) . ' halaman</span>' : ''; ?></td>
      <td data-label="Status"><span class="state <?= $st ?>"><i></i><?= STATE_LABEL[$st] ?></span></td>
      <td data-label="IP"><?= $d['ip'] ? '<code>' . h($d['ip']) . '</code>' : '<span class="muted">–</span>' ?></td>
      <td data-label="Terakhir lapor" class="muted" title="<?= h($d['last_seen'] ?? '') ?>"><?= ago($d['last_seen']) ?></td>
      <?php if ($op):
        $code_active = $d['enroll_hash'] && $d['enroll_fail'] < 5 && strtotime($d['enroll_expires']) > time();
      ?>
      <td data-label="Instalasi"><?php
        if ($d['enroll_hash'] && $d['enroll_fail'] >= 5) echo '<span class="badge bad-badge" title="5 kali password salah">Kode terkunci</span>';
        elseif ($d['enroll_hash'] && !$code_active) echo '<span class="badge" title="' . h($d['enroll_expires']) . '">Kode kedaluwarsa</span>';
        elseif ($code_active) echo '<span class="badge warn-badge" title="Berlaku sampai ' . h($d['enroll_expires']) . '">Kode aktif s/d ' . date('d/m H:i', strtotime($d['enroll_expires'])) . '</span>';
        elseif ($d['enrolled_at']) echo '<span class="badge ok-badge" title="Dipasang ' . h($d['enrolled_at']) . ($d['enrolled_host'] ? ' di ' . h($d['enrolled_host']) : '') . '">Terpasang ' . date('d/m H:i', strtotime($d['enrolled_at'])) . '</span>';
        else echo '<span class="muted">–</span>';
      ?></td>
      <?php if ($sup): ?><td data-label="Token"><button type="button" class="token-btn" data-token="<?= h($d['token']) ?>" title="Klik untuk tampilkan dan salin">••••••••</button></td><?php endif; ?>
      <td data-label="Aksi" class="actions right">
        <?php if ($sup): ?><button type="button" class="icon-btn sm" title="Edit" aria-label="Edit <?= h($d['name']) ?>" data-dev-edit="<?= h(json_encode($data)) ?>"><?= icon('edit') ?></button><?php endif; ?>
        <button form="f<?= $d['id'] ?>" class="icon-btn sm" name="act" value="enroll" title="Buat kode instalasi (username + password sekali pakai)" aria-label="Kode instalasi"<?= $code_active ? ' data-confirm="Kode lama untuk ' . h($d['device_key']) . ' masih aktif dan akan diganti. Lanjutkan?"' : '' ?>><?= icon('enroll') ?></button>
        <?php if ($code_active): ?><button form="f<?= $d['id'] ?>" class="icon-btn sm" name="act" value="enroll_cancel" title="Batalkan kode instalasi" aria-label="Batalkan kode"><?= icon('x') ?></button><?php endif; ?>
        <?php if ($sup): ?><button form="dl" class="icon-btn sm" name="id" value="<?= $d['id'] ?>" title="Unduh paket agent (config.json berisi token, tanpa login)" aria-label="Unduh agent"><?= icon('download') ?></button>
        <button form="f<?= $d['id'] ?>" class="icon-btn sm" name="act" value="regen" title="Buat token baru" aria-label="Token baru" data-confirm="Buat token baru untuk <?= h($d['device_key']) ?>? Agent di laptop ini harus dipasang ulang."><?= icon('key') ?></button>
        <button form="f<?= $d['id'] ?>" class="icon-btn sm danger" name="act" value="delete" title="Hapus" aria-label="Hapus" data-confirm="Hapus laptop <?= h($d['device_key']) ?>?"><?= icon('trash') ?></button><?php endif; ?>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="empty-filter muted" id="dev-none" hidden>Tidak ada laptop yang cocok dengan pencarian.</p>
  <?php foreach ($devices as $d): ?>
  <form method="post" id="f<?= $d['id'] ?>" hidden><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= $d['id'] ?>"></form>
  <?php endforeach; ?>
  <?php endif; ?>
</section>
</main>

<?php if ($sup):
    $fe = $form_err ?? [];
?>
<div class="modal" id="dev-modal" hidden data-open="<?= $form_err !== null ? 'add' : '' ?>">
  <form method="post" class="modal-box form-modal" id="dev-form" autocomplete="off">
    <div class="modal-head"><h3 id="dev-title">Tambah laptop</h3><button type="button" class="icon-btn sm" data-close aria-label="Tutup">✕</button></div>
    <?php if ($form_err !== null): ?><p class="notice error"><?= h($msg) ?></p><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="act" value="add" id="f-act">
    <input type="hidden" name="id" value="" id="f-id">
    <div class="form-grid">
      <label>ID laptop <input name="device_key" id="f-key" placeholder="G003" required pattern="[A-Za-z0-9_\-]{1,50}" value="<?= h($fe['device_key'] ?? '') ?>">
        <small class="hint">Huruf/angka, unik. Tidak bisa diubah setelah dibuat.</small></label>
      <label>Nama <input name="name" id="f-name" placeholder="Laptop Gate 3" required value="<?= h($fe['name'] ?? '') ?>"></label>
      <label>Kategori <?= cat_select('', (int)($fe['category_id'] ?? 0), false) ?></label>
      <label>Gate / pos <input name="gate" id="f-gate" placeholder="Gate Utara" value="<?= h($fe['gate'] ?? '') ?>"></label>
      <label class="full">Wallpaper <?= wp_select('', $fe['wallpaper_id'] ?? '') ?>
        <small class="hint">Khusus laptop ini. <b>Ikut kategori / bawaan</b> = sesuai pengaturan di menu Wallpaper.
          Unggah gambar di menu <b>Wallpaper</b> dulu. Butuh agent 1.9+.</small></label>
      <div class="full field">
        <span class="field-lbl">Mode</span>
        <div class="seg">
          <?php foreach (MODES as $k => $v): ?>
          <label class="seg-opt"><input type="radio" name="mode" value="<?= $k ?>" <?= ($fe['mode'] ?? 'gate') === $k ? 'checked' : '' ?>><span><?= h($v) ?></span></label>
          <?php endforeach; ?>
        </div>
      </div>
      <label class="full" id="f-url-row">URL halaman yang dibuka otomatis <span class="muted">(1 URL per baris, maks <?= GATE_URL_MAX ?>)</span>
        <textarea name="gate_url" id="f-url" rows="3" spellcheck="false" placeholder="kosongkan = URL default + ID laptop&#10;https://.../form-scanning-gate.php?id=G001&#10;https://.../halaman-kedua.php"><?= h($fe['gate_url'] ?? '') ?></textarea>
        <small class="hint">1 URL = layar penuh (kiosk). Lebih dari 1 URL = jendela <b>berdampingan kiri-kanan</b> (agent 1.8+).
          Hasil scan hanya ditangkap bila salah satu URL mengandung: <code><?= h(setting('scan_page_match')) ?></code>.</small></label>
      <?php $cm = $fe['cam_mode'] ?? 'none'; $auto = str_replace('{ip}', '{IP laptop}', $CFG['camera_auto_url']); ?>
      <div class="full field">
        <span class="field-lbl">Live camera</span>
        <div class="seg seg3">
          <?php foreach (['none' => 'Tidak ada', 'auto' => 'Agent DVR di laptop ini', 'custom' => 'URL custom'] as $k => $v): ?>
          <label class="seg-opt"><input type="radio" name="cam_mode" value="<?= $k ?>" <?= $cm === $k ? 'checked' : '' ?>><span><?= $v ?></span></label>
          <?php endforeach; ?>
        </div>
        <small class="hint" id="f-cam-auto">Otomatis memakai IP laptop yang dilaporkan agent: <code><?= h($auto) ?></code></small>
      </div>
      <label class="full" id="f-cam-row">URL live camera <input name="camera_url" id="f-cam" placeholder="http://{ip}:8090/?viewIndex=0#Live" value="<?= h($fe['camera_url'] ?? '') ?>">
        <small class="hint">Boleh memakai <code>{ip}</code> (IP laptop). Contoh MJPEG <code>…/video.mjpg?oids=1</code>, snapshot <code>…/grab.jpg?oid=1</code>, atau halaman web Agent DVR.</small></label>
    </div>
    <div class="actions"><button type="button" class="btn" data-close>Batal</button><button class="btn btn-primary" id="dev-submit"><?= icon('plus') ?> <span>Tambah</span></button></div>
  </form>
</div>
<?php endif; ?>
<?php
// Kode instalasi yang baru dibuat: ditampilkan sekali saja
$show = $_SESSION['enroll_show'] ?? null;
unset($_SESSION['enroll_show']);
if ($show && $op):
    $inst_url = $server_url . '/install/' . rawurlencode($show['key']);
    $copy = "Instalasi GateMon Agent - {$show['key']} ({$show['name']})\nBuka di laptop : $inst_url\nUsername       : {$show['key']}\nPassword       : {$show['pass']}\nBerlaku        : sampai " . date('d/m/Y H:i', strtotime($show['exp'])) . " (sekali pakai)";
?>
<div class="modal" id="enroll-modal">
  <div class="modal-box form-modal enroll-box">
    <div class="modal-head"><h3><?= icon('enroll') ?> Kode instalasi <?= h($show['key']) ?></h3><button type="button" class="icon-btn sm" data-close-enroll aria-label="Tutup">✕</button></div>
    <div class="enroll-body">
      <p class="muted">Untuk <b><?= h($show['name']) ?></b>. Di laptop tersebut buka alamat ini di browser, lalu masukkan username &amp; password:</p>
      <a class="inst-link mono" href="<?= h($inst_url) ?>" target="_blank" rel="noopener"><?= h($inst_url) ?></a>
      <div class="cred">
        <div><span>Username</span><b class="mono"><?= h($show['key']) ?></b></div>
        <div><span>Password</span><b class="mono big"><?= h($show['pass']) ?></b></div>
      </div>
      <p class="notice warn-notice">Password <b>hanya ditampilkan sekali</b>, sekali pakai, dan berlaku sampai <b><?= date('d/m/Y H:i', strtotime($show['exp'])) ?></b>. Setelah dipakai, kode hangus dan token lama laptop ini tidak berlaku lagi.</p>
    </div>
    <div class="actions"><button type="button" class="btn" data-copy-text="<?= h($copy) ?>"><?= icon('copy') ?> <span>Salin semua</span></button><button type="button" class="btn btn-primary" data-close-enroll>Selesai</button></div>
  </div>
</div>
<?php endif; ?>
<?php page_foot(['devices.js?v=6']);
