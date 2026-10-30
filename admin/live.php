<?php
// Layar live satu laptop (supervisor): gambar layar laptop diperbarui 1-2 kali per detik, tanpa Radmin/aplikasi lain.
// Selama dilihat, laptop menampilkan tanda "Layar sedang dilihat Tim IT". Data & alur: live.js + live_api.php.
require __DIR__ . '/auth.php';
require_login();
if (!is_supervisor()) { http_response_code(403); exit('Layar live hanya untuk supervisor.'); }
$st = db()->prepare("SELECT d.id, d.name, d.device_key, d.gate, d.agent_version, c.name AS category_name FROM devices d LEFT JOIN categories c ON c.id=d.category_id WHERE d.id=?");
$st->execute([(int)($_GET['dev'] ?? 0)]);
$d = $st->fetch();
if (!$d) { http_response_code(404); exit('Laptop tidak ditemukan.'); }
$ok = $d['agent_version'] && version_compare($d['agent_version'], LIVE_MIN, '>=');
page_head('Layar ' . $d['name'], 'live-page', '<meta name="csrf" content="' . csrf_token() . '">');
topbar('index', 'Monitoring Laptop');
?>
<main class="page live-wrap" id="live" data-dev="<?= (int)$d['id'] ?>" data-ok="<?= $ok ? 1 : 0 ?>">
  <div class="live-bar">
    <div class="live-title"><span class="live-dot" id="live-dot"></span><b><?= h($d['name']) ?></b>
      <small class="muted"><?= h(implode(' · ', array_filter([$d['device_key'], $d['category_name'], $d['gate'], $d['agent_version'] ? 'agent ' . $d['agent_version'] : '']))) ?></small></div>
    <div class="live-ctl">
      <select id="live-mode" title="Kualitas gambar (makin tajam makin besar trafiknya)">
        <?php foreach (LIVE_MODES as $k => $m): ?><option value="<?= $k ?>"<?= $k === 'normal' ? ' selected' : '' ?>><?= h($m[0]) ?></option><?php endforeach; ?>
      </select>
      <button type="button" class="btn" id="live-save" disabled title="Simpan gambar yang sedang tampil">Simpan gambar</button>
      <button type="button" class="btn" id="live-full" title="Layar penuh (Esc untuk keluar)"><?= icon('expand') ?></button>
      <button type="button" class="btn btn-primary" id="live-toggle">Mulai</button>
    </div>
  </div>
  <p class="live-status" id="live-status" role="status"><?= $ok ? 'Memulai…' : 'Laptop ini memakai agent ' . h($d['agent_version'] ?: '?') . '. Layar live perlu agent ' . LIVE_MIN . '+: update lewat menu Agent.' ?></p>
  <div class="live-screen" id="live-screen"><img id="live-img" alt="Layar <?= h($d['name']) ?>" hidden><div class="live-empty muted" id="live-empty">Belum ada gambar.</div></div>
  <p class="muted small">Selama dilihat, di layar laptop tampil tanda <b>"Layar sedang dilihat Tim IT"</b> (tidak menghalangi petugas dan tidak ikut terekam).
    Sesi berhenti saat halaman ini ditutup atau tab disembunyikan, dan paling lama <?= LIVE_MAX_MINUTES ?> menit. Setiap sesi tercatat di log. Layar kunci Windows tidak bisa diambil.</p>
</main>
<?php page_foot(['live.js?v=2']);
