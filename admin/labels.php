<?php
// Cetak label komputer (stiker nama laptop) ke printer thermal / label lewat dialog print browser.
// Satu label = satu halaman dengan ukuran kertas = ukuran label (@page), jadi printer label memotong / maju per label.
// Isi label: nama laptop (huruf otomatis sebesar mungkin), ID, kategori, gate / pos, barcode Code 128 ID laptop (opsional).
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
$devs = $pdo->query("SELECT d.id, d.name, d.device_key, d.gate, d.category_id, c.name AS cat, c.color FROM devices d
    LEFT JOIN categories c ON c.id=d.category_id ORDER BY c.sort IS NULL, c.sort, c.name, d.name")->fetchAll();
$cats = [];
foreach ($devs as $d) $cats[$d['cat'] !== null ? (int)$d['category_id'] : -1] = $d['cat'] ?? 'Tanpa kategori';
$data = array_map(function ($d) {
    return ['id' => (int)$d['id'], 'name' => $d['name'], 'key' => $d['device_key'], 'gate' => (string)$d['gate'],
        'cat' => $d['cat'] ?? '', 'cid' => $d['cat'] !== null ? (int)$d['category_id'] : -1];
}, $devs);
// ukuran siap pakai: nilai = LEBARxTINGGI kertas (mm) seperti keluar dari printer.
// Awalan b: = rol Brother QL (QL-800 dll): LEBAR ROL x PANJANG label (kertas keluar memanjang, halaman Portrait).
const LABEL_SIZES = [
    'Brother QL-800 / QL-series (rol DK)' => [
        'b:62x100' => 'DK-11202 · 62 × 100 mm (pengiriman)', 'b:62x40' => 'DK-22205 · kontinu 62 mm, panjang 40 mm',
        'b:29x90' => 'DK-11201 · 29 × 90 mm (label alamat)', 'b:29x62' => 'DK-11209 · 29 × 62 mm (alamat kecil)',
        'b:38x90' => 'DK-11208 · 38 × 90 mm (alamat besar)', 'b:17x54' => 'DK-11204 · 17 × 54 mm (serbaguna)',
        'b:29x60' => 'DK-22210 · kontinu 29 mm, panjang 60 mm', 'b:38x50' => 'DK-22225 · kontinu 38 mm, panjang 50 mm',
    ],
    'Printer label / struk lain' => [
        '50x30' => 'Stiker 50 × 30 mm', '40x30' => 'Stiker 40 × 30 mm', '60x40' => 'Stiker 60 × 40 mm', '70x30' => 'Stiker 70 × 30 mm',
        '100x50' => 'Stiker 100 × 50 mm', '58x40' => 'Kertas struk 58 mm (label 40 mm)', '80x50' => 'Kertas struk 80 mm (label 50 mm)',
    ],
    'Lainnya' => ['custom' => 'Ukuran lain… (mis. pita kontinu dengan panjang sendiri)'],
];

page_head('Cetak label');
topbar('devices', 'Monitoring Laptop');
?>
<main class="page labels-page">
  <div class="page-head no-print">
    <div><h1>Cetak label komputer</h1><p class="muted">Stiker nama laptop untuk ditempel di komputer, dicetak ke printer thermal / label.</p></div>
    <div class="head-btns"><a class="btn" href="devices.php">Kembali ke Devices</a>
      <button class="btn btn-primary" type="button" id="lb-print"><?= icon('download') ?> Cetak <span id="lb-count">0</span> label</button></div>
  </div>

  <div class="lb-layout no-print">
    <section class="card lb-opts">
      <h2><?= icon('gear') ?> Ukuran &amp; isi label</h2>
      <label class="lb-f">Ukuran label <select id="lb-size">
        <?php foreach (LABEL_SIZES as $g => $opts): ?><optgroup label="<?= h($g) ?>">
          <?php foreach ($opts as $k => $n): ?><option value="<?= $k ?>"><?= h($n) ?></option><?php endforeach; ?>
        </optgroup><?php endforeach; ?>
      </select></label>
      <div class="lb-row" id="lb-custom" hidden>
        <label class="lb-f">Lebar (mm) <input type="number" id="lb-w" min="15" max="200" value="50"></label>
        <label class="lb-f">Tinggi (mm) <input type="number" id="lb-h" min="12" max="200" value="30"></label>
      </div>
      <label class="lb-f">Arah cetak <select id="lb-rot">
        <option value="auto">Otomatis (rol lebar: mendatar searah keluar kertas; rol sempit: diputar)</option>
        <option value="flow">Mendatar searah keluar kertas (tanpa diputar)</option>
        <option value="rot">Diputar 90° (tulisan sepanjang kertas)</option>
      </select></label>
      <label class="lb-f" id="lb-lh-f" title="Tinggi kotak / garis tepi = panjang kertas yang dipakai per label. Kosong = otomatis (setengah lebar kertas)">Tinggi label / kotak (mm)
        <input type="number" id="lb-lh" min="12" max="200" step="1" placeholder="otomatis"></label>
      <div class="lb-row">
        <label class="lb-f" title="Tinggi huruf nama laptop. Kosong = otomatis sebesar mungkin">Huruf nama (mm)
          <input type="number" id="lb-fn" min="2" max="60" step="0.5" placeholder="otomatis"></label>
        <label class="lb-f" title="Tinggi huruf baris ID · kategori · gate. Kosong = otomatis">Huruf info (mm)
          <input type="number" id="lb-fm" min="1.5" max="15" step="0.5" placeholder="otomatis"></label>
      </div>
      <label class="lb-f">Teks atas (opsional) <input id="lb-top" maxlength="40" placeholder="mis. nama event / milik panitia"></label>
      <fieldset class="lb-checks">
        <label><input type="checkbox" id="lb-key" checked> ID laptop</label>
        <label><input type="checkbox" id="lb-cat" checked> Kategori</label>
        <label><input type="checkbox" id="lb-gate" checked> Gate / pos</label>
        <label><input type="checkbox" id="lb-bar"> Barcode ID laptop</label>
        <label><input type="checkbox" id="lb-border" checked> Garis tepi</label>
      </fieldset>
      <div class="lb-row">
        <label class="lb-f">Rangkap per laptop <input type="number" id="lb-copies" min="1" max="10" value="1"></label>
        <label class="lb-f" title="Geser isi bila hasil cetak tidak di tengah">Margin (mm) <input type="number" id="lb-pad" min="0" max="8" step="0.5" value="1.5"></label>
      </div>
      <div class="notice small lb-hint" id="lb-hint-brother" hidden>
        <b>Brother QL-800</b>
        <ol>
          <li>Pasang <b>driver printer Brother QL-800</b> dari support.brother.com (bukan hanya P-touch Editor). Lampu <b>Editor Lite</b>
            (hijau) harus <b>mati</b>: tekan tahan tombolnya sampai lampu padam, kalau menyala printer terbaca sebagai flashdisk.</li>
          <li>Pasang rol DK yang sama dengan pilihan di atas. Di <i>Printing Preferences</i> driver: <b>Paper Size</b> = rol itu
            (mis. <i>29mm x 90mm</i>, atau <i>62mm</i> + panjang untuk rol kontinu), <b>Cut Option: cut every 1 label</b>.</li>
          <li>Di dialog print browser: printer <b>Brother QL-800</b> → <i>More settings</i>: <b>Paper size</b> sama dengan rol,
            <b>Layout / Orientasi: Portrait</b>, <b>Margins: None</b>, <b>Scale 100</b> (bukan <i>Fit to page</i>), <b>Headers and footers</b> mati.
            Halaman dikirim persis selebar rol × panjang label (seperti kertas keluar dari printer), jadi tidak diperkecil driver.</li>
          <li>Halaman dibuat setinggi label (tanpa ruang kosong). Agar kertas juga dipotong pas setinggi label, pakai <b>rol kontinu 62 mm</b>
            (DK-22205) dan di driver pilih Paper Size <b>62mm</b> (kontinu). Rol potong-jadi (mis. DK-11202 62 × 100) tetap maju satu label penuh.</li>
          <li>Tepi label Brother tidak tercetak ±1-1,5 mm: biarkan <b>Margin</b> di atas ≥ 1,5 mm.</li>
          <li>Bila pratinjau di dialog print sudah benar tetapi hasil cetak tetap miring / mengecil: driver Brother yang memutar.
            Buka <i>Printers &amp; scanners → Brother QL-800 → Printing preferences</i>: <b>Orientation: Portrait</b>, Paper Size = rol,
            matikan pilihan putar / <i>Rotate</i> dan <i>Fit to page</i>, lalu klik Apply.</li>
        </ol>
      </div>
      <p class="muted small" id="lb-hint-other">Di dialog print: pilih printer thermal, <b>ukuran kertas sama dengan ukuran label</b>
        (atur sekali di Properties / Preferences printer), margin <b>None</b>, skala <b>100%</b>, dan matikan <b>Headers and footers</b>.
        Satu label = satu halaman.</p>
    </section>

    <section class="card lb-pick">
      <div class="lb-pick-head">
        <h2><?= icon('devices') ?> Laptop</h2>
        <select id="lb-catf" aria-label="Kategori">
          <option value="">Semua kategori</option>
          <?php foreach ($cats as $id => $n): ?><option value="<?= $id ?>"><?= h($n) ?></option><?php endforeach; ?>
        </select>
        <input type="search" id="lb-q" placeholder="Cari nama / ID…" aria-label="Cari laptop">
        <button class="btn" type="button" id="lb-all">Pilih semua</button><button class="btn" type="button" id="lb-none">Kosongkan</button>
      </div>
      <?php if (!$devs): ?><p class="muted">Belum ada laptop. Tambahkan dulu di menu Devices.</p><?php endif; ?>
      <div class="lb-list" id="lb-list"></div>
    </section>
  </div>

  <section class="card no-print">
    <h2><?= icon('image') ?> Pratinjau <small class="muted" id="lb-dim"></small></h2>
    <div class="lb-preview" id="lb-preview"></div>
  </section>
  <div id="lb-print-area" data-devs="<?= h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>"></div>
</main>
<?php page_foot(['labels.js?v=12']);
