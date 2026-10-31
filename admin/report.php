<?php
// Laporan scan: QR unik yang berhasil (QR yang di-scan berhasil berkali-kali dihitung 1), scan ganda, scan gagal.
// Rincian per kategori / gate / jam memakai scan berhasil PERTAMA tiap QR, sehingga jumlahnya sama dengan total unik.
// ?csv=1 mengunduh daftar QR unik (filter sama). Data scan disimpan sesuai Pengaturan "Simpan data scan selama".
require __DIR__ . '/auth.php';
require_login();
$pdo = db();
const STATUS_LABEL = ['success' => 'Berhasil', 'belum' => 'Belum waktunya', 'notfound' => 'Tidak ditemukan', 'error' => 'Error'];

require __DIR__ . '/report_groups.php';   // group kategori (grp_of, grp_sort) dan tgl()
// simpan pemetaan (modal "Atur group", supervisor). Group kosong = kategori dilepas dari group.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'groups' && is_supervisor()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) die('csrf');
    $cats_in = (array)($_POST['cat'] ?? []); $grps_in = (array)($_POST['grp'] ?? []);
    $set = $pdo->prepare("REPLACE INTO scan_groups (category, grp, updated_at) VALUES (?, ?, NOW())");
    $del = $pdo->prepare("DELETE FROM scan_groups WHERE category = ?");
    $bad = []; $n = 0;
    foreach ($cats_in as $i => $c) {
        $c = strtoupper(trim(mb_substr((string)$c, 0, 100)));
        $g = strtoupper(trim((string)($grps_in[$i] ?? '')));
        if ($c === '') continue;
        if ($g === '') { $del->execute([$c]); continue; }
        if (!preg_match('/^[A-Z0-9 _&\/.\-]{1,50}$/', $g)) { $bad[] = $g; continue; }
        $set->execute([$c, $g]); $n++;
    }
    log_event(null, 'info', current_user()['username'] . " mengubah group kategori scan ($n kategori)");
    $_SESSION['flash'] = $bad ? 'Sebagian tidak disimpan, nama group tidak valid (huruf, angka, spasi, - _ / & .): ' . implode(', ', $bad) : "Group kategori disimpan ($n kategori).";
    header('Location: report.php?' . preg_replace('/[^\w=&%.\-+]/', '', (string)($_POST['back'] ?? '')));
    exit;
}
$flash_msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);

// ---------- filter ----------
$today = date('Y-m-d');
$valid = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) && strtotime($d) ? $d : null; };
$from = $valid($_GET['from'] ?? '') ?: $today;
$to = $valid($_GET['to'] ?? '') ?: $from;
if ($to < $from) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 92) $to = date('Y-m-d', strtotime($from) + 92 * 86400);   // maks ±3 bulan
$dev = (int)($_GET['device'] ?? 0);
$cat = trim((string)($_GET['category'] ?? ''));
$gate = trim((string)($_GET['gate'] ?? ''));
$grp = trim((string)($_GET['grp'] ?? ''));
$search = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);      // cari ID QR (sebagian), menyaring daftar
$code = mb_substr(trim((string)($_GET['code'] ?? '')), 0, 100);     // riwayat satu QR (semua tanggal)

$w = "scanned_at >= ? AND scanned_at < ?";
$p = [$from . ' 00:00:00', date('Y-m-d', strtotime($to) + 86400) . ' 00:00:00'];
$base = [$w, $p];                                   // tanpa filter kategori/gate: untuk isi pilihan filter
if ($dev) { $w .= " AND device_id = ?"; $p[] = $dev; }
if ($cat !== '') { $w .= " AND category = ?"; $p[] = $cat; }
if ($gate !== '') { $w .= " AND gate = ?"; $p[] = $gate; }
if ($grp !== '') {   // group = daftar kategorinya; "Tanpa group" = kategori yang belum dipetakan (termasuk scan gagal tanpa kategori)
    $in_grp = array_keys(array_filter($gmap, function ($g) use ($grp) { return $g === $grp; }));
    $list = $grp === NO_GROUP ? array_keys($gmap) : $in_grp;
    if ($list) { $w .= " AND category " . ($grp === NO_GROUP ? 'NOT ' : '') . "IN (" . implode(',', array_fill(0, count($list), '?')) . ")"; $p = array_merge($p, $list); }
    elseif ($grp !== NO_GROUP) $w .= " AND 1 = 0";
}
function q($sql, $params) { global $pdo; $st = $pdo->prepare($sql); $st->execute($params); return $st; }
$S = "\x1f";   // pemisah GROUP_CONCAT (tidak mungkin ada di data)
$names = array_column($pdo->query("SELECT id, name FROM devices")->fetchAll(), 'name', 'id');
$qs = http_build_query(array_filter(['from' => $from, 'to' => $to, 'device' => $dev ?: null, 'grp' => $grp, 'category' => $cat, 'gate' => $gate, 'q' => $search], function ($v) { return $v !== null && $v !== ''; }));
function code_link($c) { global $qs; return '<a class="rep-code" data-code="' . h($c) . '" href="report.php?' . h($qs . '&code=' . rawurlencode($c)) . '" title="Lihat riwayat scan"><code>' . h($c) . '</code></a>'; }

// ---------- riwayat scan satu QR (isi modal): semua tanggal, tidak terbatas filter ----------
function history_html($code) {
    global $pdo;
    $names = array_column($pdo->query("SELECT id, name FROM devices")->fetchAll(), 'name', 'id');
    $hist = q("SELECT scanned_at, status, category, gate, device_id FROM scans WHERE code = ? ORDER BY scanned_at, id", [$code])->fetchAll();
    $place = function ($r) use ($names) { return ($r['gate'] ?: '–') . ' · ' . ($names[$r['device_id']] ?? 'laptop terhapus'); };
    ob_start(); ?>
    <div class="modal-head"><h3><?= icon('pulse') ?> Riwayat scan <code><?= h($code) ?></code></h3><button type="button" class="icon-btn sm" data-hist-close aria-label="Tutup">✕</button></div>
    <div class="hist-body">
    <?php if (!$hist): ?>
      <p class="muted">Tidak ada scan dengan ID QR ini (di semua tanggal yang masih tersimpan).</p>
    <?php else:
        $ok_rows = array_values(array_filter($hist, function ($r) { return $r['status'] === 'success'; }));
        $first = $ok_rows[0] ?? null; ?>
      <div class="load rep-hist-sum">
        <div><span>Status</span><b class="<?= $first ? 'wol-ready' : 'wol-warn' ?>"><?= $first ? 'Berhasil (dihitung 1)' : 'Tidak pernah berhasil' ?></b></div>
        <div><span>Pertama berhasil</span><b><?= $first ? h($first['scanned_at'] . ' · ' . $place($first)) : '–' ?></b></div>
        <div><span>Jumlah scan</span><b><?= count($hist) ?> (<?= count($ok_rows) ?> berhasil, <?= count($hist) - count($ok_rows) ?> gagal)</b></div>
        <div><span>Di-scan di</span><b><?= h(implode(', ', array_unique(array_map($place, $hist)))) ?></b></div>
      </div>
      <div class="table-wrap"><table class="table small">
        <tr><th>No</th><th>Waktu</th><th>Status</th><th>Kategori</th><th>Gate</th><th>Laptop</th><th>Keterangan</th></tr>
        <?php $seen = false; foreach ($hist as $i => $r):
            $ok = $r['status'] === 'success';
            $note = $ok ? (!$seen ? '<span class="badge ok-badge">Dihitung</span>' : '<span class="badge warn-badge">Ganda</span>') : '<span class="muted">tidak dihitung</span>';
            if ($ok) $seen = true; ?>
        <tr>
          <td class="muted"><?= $i + 1 ?></td>
          <td class="mono"><?= h($r['scanned_at']) ?></td>
          <td><span class="state <?= $ok ? 'ok' : 'offline' ?>"><i></i><?= h(STATUS_LABEL[$r['status']] ?? $r['status']) ?></span></td>
          <td><?= h($r['category'] ?: '–') ?><?= $r['category'] !== '' ? ' <small class="muted">· ' . h(grp_of($r['category'])) . '</small>' : '' ?></td>
          <td><?= h($r['gate'] ?: '–') ?></td>
          <td><?= h($names[$r['device_id']] ?? 'laptop terhapus') ?></td>
          <td><?= $note ?></td>
        </tr>
        <?php endforeach; ?>
      </table></div>
      <p class="muted small">Riwayat dari semua tanggal yang masih tersimpan, tidak terbatas filter tanggal laporan.</p>
    <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}
// dipanggil modal (fetch): hanya isi modal
if (isset($_GET['history'])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-GateMon-History: 1');   // penanda bagi JS: bukan halaman login (sesi habis)
    header('Cache-Control: no-store');
    echo history_html(mb_substr(trim((string)$_GET['history']), 0, 100));
    exit;
}

// ---------- daftar bertingkat halaman (server-side): u = QR unik berhasil, f = QR gagal yang tidak pernah berhasil ----------
// Parameter per daftar di URL (ikut dipakai muat ulang otomatis): {t}p halaman, {t}n baris, {t}o kolom urut, {t}d arah, {t}q cari.
const LIST_COLS = ['u' => ['first_at', 'code', 'category', 'gate', 'n'], 'f' => ['last_at', 'code', 'last_status', 'n']];
const LIST_SIZES = [10, 25, 50, 100];
function list_state($t) {
    global $search;
    $o = $_GET[$t . 'o'] ?? '';
    $d = $_GET[$t . 'd'] ?? '';
    $n = (int)($_GET[$t . 'n'] ?? 25);
    return ['t' => $t, 'o' => in_array($o, LIST_COLS[$t], true) ? $o : LIST_COLS[$t][0],
        'd' => in_array($d, ['asc', 'desc'], true) ? $d : ($t === 'u' ? 'asc' : 'desc'),
        'n' => in_array($n, LIST_SIZES, true) ? $n : 25, 'p' => max(1, (int)($_GET[$t . 'p'] ?? 1)),
        'q' => mb_substr(trim((string)($_GET[$t . 'q'] ?? $search)), 0, 100)];
}
// satu halaman data + jumlah baris (difilter cari & tanpa cari)
function list_fetch(array &$s) {
    global $w, $p, $S;
    $like = $s['q'] !== '' ? " AND code LIKE ?" : '';
    $lp = $s['q'] !== '' ? array_merge($p, ['%' . addcslashes($s['q'], '%_\\') . '%']) : $p;
    if ($s['t'] === 'u') {
        $cnt = "SELECT COUNT(DISTINCT code) FROM scans WHERE $w AND status = 'success' AND code <> ''";
        $s['all'] = (int)q($cnt, $p)->fetchColumn();
        $s['found'] = $like ? (int)q($cnt . $like, $lp)->fetchColumn() : $s['all'];
        $sql = "SELECT code, COUNT(*) AS n, MIN(scanned_at) AS first_at, MAX(scanned_at) AS last_at,
            SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS category,
            SUBSTRING_INDEX(GROUP_CONCAT(gate ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS gate,
            SUBSTRING_INDEX(GROUP_CONCAT(device_id ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS device_id
            FROM scans WHERE $w AND status = 'success' AND code <> ''$like GROUP BY code";
    } else {
        $grp_sql = "SELECT code FROM scans WHERE $w AND code <> ''%s GROUP BY code HAVING SUM(status = 'success') = 0";
        $s['all'] = (int)q("SELECT COUNT(*) FROM (" . sprintf($grp_sql, '') . ") x", $p)->fetchColumn();
        $s['found'] = $like ? (int)q("SELECT COUNT(*) FROM (" . sprintf($grp_sql, $like) . ") x", $lp)->fetchColumn() : $s['all'];
        $sql = "SELECT code, COUNT(*) AS n, MAX(scanned_at) AS last_at,
            SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY scanned_at DESC, id DESC SEPARATOR '$S'), '$S', 1) AS last_status
            FROM scans WHERE $w AND code <> ''$like GROUP BY code HAVING SUM(status = 'success') = 0";
    }
    $s['pages'] = max(1, (int)ceil($s['found'] / $s['n']));
    $s['p'] = min($s['p'], $s['pages']);
    $off = ($s['p'] - 1) * $s['n'];
    // kolom urut dari daftar putih LIST_COLS; code sebagai pengurut kedua agar urutan halaman stabil
    return q("$sql ORDER BY {$s['o']} " . strtoupper($s['d']) . ", code LIMIT $off, {$s['n']}", $lp)->fetchAll();
}
function list_section($t) {
    global $names, $code, $from, $to;
    $s = list_state($t);
    $rows = list_fetch($s);
    $off = ($s['p'] - 1) * $s['n'];
    $th = function ($col, $label, $cls = '') use ($s) {
        if ($col === '') return "<th class=\"$cls\">$label</th>";
        $on = $s['o'] === $col;
        return '<th class="sortable ' . $cls . ($on ? ' on ' . $s['d'] : '') . '" data-sort="' . $col . '" aria-sort="' . ($on ? ($s['d'] === 'asc' ? 'ascending' : 'descending') : 'none') . '" tabindex="0">'
            . $label . '<i class="sort-ic" aria-hidden="true"></i></th>';
    };
    ob_start(); ?>
  <section class="card rep-list" id="list-<?= $t ?>" data-list="<?= $t ?>" data-o="<?= $s['o'] ?>" data-d="<?= $s['d'] ?>" data-p="<?= $s['p'] ?>" data-n="<?= $s['n'] ?>">
    <div class="table-tools">
      <h2><?= $t === 'u' ? icon('save') . ' Daftar QR unik berhasil' : icon('x') . ' QR gagal yang tidak pernah berhasil' ?>
        <span class="badge"><?= $s['q'] !== '' ? $s['found'] . ' dari ' . $s['all'] : $s['all'] ?></span></h2>
      <div class="filters rep-list-tools">
        <input type="search" class="rep-list-q" value="<?= h($s['q']) ?>" placeholder="Cari ID QR di daftar ini…" aria-label="Cari ID QR" spellcheck="false" autocomplete="off">
        <select class="rep-list-n" aria-label="Baris per halaman"><?php foreach (LIST_SIZES as $n): ?><option value="<?= $n ?>" <?= $s['n'] === $n ? 'selected' : '' ?>><?= $n ?> baris</option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="table-wrap"><table class="table small rep-ltable">
      <?php if ($t === 'u'): ?>
      <thead><tr><?= $th('', 'No') . $th('code', 'ID QR') . $th('', 'Group') . $th('category', 'Kategori') . $th('gate', 'Gate') . $th('', 'Laptop') . $th('first_at', 'Pertama berhasil') . $th('n', 'Scan berhasil', 'right') ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
      <tr<?= $r['code'] === $code ? ' class="rep-sel"' : '' ?>>
        <td class="muted"><?= $off + $i + 1 ?></td>
        <td><?= code_link($r['code']) ?></td>
        <td><?= h(grp_of($r['category'])) ?></td>
        <td><?= h($r['category'] ?: '–') ?></td>
        <td><?= h($r['gate'] ?: '–') ?></td>
        <td><?= h($names[$r['device_id']] ?? 'laptop terhapus') ?></td>
        <td class="mono"><?= h(substr($r['first_at'], $from === $to ? 11 : 0)) ?></td>
        <td class="right"><?= (int)$r['n'] > 1 ? '<span class="badge warn-badge" title="Di-scan berhasil ' . (int)$r['n'] . ' kali, dihitung 1">' . (int)$r['n'] . 'x ganda</span>' : '1' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php else: ?>
      <thead><tr><?= $th('', 'No') . $th('code', 'ID QR') . $th('last_status', 'Status terakhir') . $th('n', 'Jumlah scan', 'right') . $th('last_at', 'Terakhir di-scan') ?></tr></thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
      <tr<?= $r['code'] === $code ? ' class="rep-sel"' : '' ?>>
        <td class="muted"><?= $off + $i + 1 ?></td><td><?= code_link($r['code']) ?></td><td><?= h(STATUS_LABEL[$r['last_status']] ?? $r['last_status']) ?></td>
        <td class="right"><?= (int)$r['n'] ?></td><td class="mono"><?= h($r['last_at']) ?></td></tr>
      <?php endforeach; ?>
      <?php endif; ?>
      <?php if (!$rows): ?><tr><td colspan="8" class="muted rep-empty"><?= $s['q'] !== '' ? 'Tidak ada ID QR yang mengandung "' . h($s['q']) . '".' : ($t === 'u' ? 'Belum ada QR yang berhasil di periode / filter ini.' : 'Tidak ada QR gagal di periode / filter ini.') ?></td></tr><?php endif; ?>
      </tbody>
    </table></div>
    <div class="rep-pager">
      <span class="muted small"><?= $s['found'] ? 'Menampilkan ' . ($off + 1) . '–' . ($off + count($rows)) . ' dari ' . $s['found'] . ($s['q'] !== '' ? ' (difilter dari ' . $s['all'] . ')' : '') : '0 data' ?></span>
      <?php if ($s['pages'] > 1): ?>
      <nav class="pager" aria-label="Halaman">
        <?php
        $btn = function ($pg, $label, $dis = false, $cur = false) {
            return '<button type="button" class="pg' . ($cur ? ' cur' : '') . '" data-page="' . $pg . '"' . ($dis || $cur ? ' disabled' : '') . ($cur ? ' aria-current="page"' : '') . '>' . $label . '</button>';
        };
        echo $btn(1, '«', $s['p'] === 1) . $btn($s['p'] - 1, '‹', $s['p'] === 1);
        $lo = max(1, $s['p'] - 2); $hi = min($s['pages'], $s['p'] + 2);
        if ($lo > 1) echo '<span class="pg-gap">…</span>';
        for ($i = $lo; $i <= $hi; $i++) echo $btn($i, (string)$i, false, $i === $s['p']);
        if ($hi < $s['pages']) echo '<span class="pg-gap">…</span>';
        echo $btn($s['p'] + 1, '›', $s['p'] === $s['pages']) . $btn($s['pages'], '»', $s['p'] === $s['pages']);
        ?>
      </nav>
      <?php endif; ?>
    </div>
  </section>
    <?php return ob_get_clean();
}
// dipanggil JS saat ganti halaman / urutan / cari: hanya kartu daftar itu
if (isset($_GET['list']) && isset(LIST_COLS[$_GET['list']])) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-GateMon-List: 1');
    header('Cache-Control: no-store');
    echo list_section($_GET['list']);
    exit;
}

// ---------- QR unik berhasil: satu baris per QR, atribut dari scan berhasil pertama ----------
$uniq = q("SELECT code, COUNT(*) AS n, MIN(scanned_at) AS first_at, MAX(scanned_at) AS last_at,
    SUBSTRING_INDEX(GROUP_CONCAT(category ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS category,
    SUBSTRING_INDEX(GROUP_CONCAT(gate ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS gate,
    SUBSTRING_INDEX(GROUP_CONCAT(device_id ORDER BY scanned_at, id SEPARATOR '$S'), '$S', 1) AS device_id
    FROM scans WHERE $w AND status = 'success' AND code <> '' GROUP BY code ORDER BY first_at, code", $p)->fetchAll();

// ---------- unduh CSV (Excel, pemisah ;) ----------
if (!empty($_GET['csv'])) {
    $fn = 'laporan-scan-' . $from . ($to !== $from ? '_sd_' . $to : '') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No', 'ID QR', 'Group', 'Kategori', 'Gate', 'Laptop', 'Pertama berhasil', 'Terakhir berhasil', 'Jumlah scan berhasil'], ';');
    foreach ($uniq as $i => $r) {
        fputcsv($out, [$i + 1, $r['code'], grp_of($r['category']), $r['category'], $r['gate'], $names[$r['device_id']] ?? '', $r['first_at'], $r['last_at'], $r['n']], ';');
    }
    fclose($out);
    exit;
}

// ---------- ringkasan ----------
$by_status = array_fill_keys(array_keys(STATUS_LABEL), 0);
foreach (q("SELECT status, COUNT(*) AS n FROM scans WHERE $w GROUP BY status", $p) as $r) $by_status[$r['status']] = (int)$r['n'];
$n_uniq = count($uniq);
$n_success = $by_status['success'];
$no_code = (int)q("SELECT COUNT(*) FROM scans WHERE $w AND status = 'success' AND code = ''", $p)->fetchColumn();
$n_double = $n_success - $n_uniq - $no_code;
$n_fail = $by_status['notfound'] + $by_status['error'] + $by_status['belum'];
// QR yang gagal dan tidak pernah berhasil di periode ini (per status terakhirnya)
$fail_only = q("SELECT code, COUNT(*) AS n, MAX(scanned_at) AS last_at,
    SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY scanned_at DESC, id DESC SEPARATOR '$S'), '$S', 1) AS last_status
    FROM scans WHERE $w AND code <> '' GROUP BY code HAVING SUM(status = 'success') = 0 ORDER BY last_at DESC", $p)->fetchAll();

// rincian (dari scan berhasil pertama tiap QR)
$per_cat = $per_gate = $per_hour = $per_day = [];
foreach ($uniq as $r) {
    $k = $r['category'] !== '' ? $r['category'] : 'Tanpa kategori';
    $per_cat[$k] = ($per_cat[$k] ?? 0) + 1;
    $g = ($r['gate'] !== '' ? $r['gate'] : '–') . ' · ' . ($names[$r['device_id']] ?? 'laptop terhapus');
    $per_gate[$g] = ($per_gate[$g] ?? 0) + 1;
    $h = substr($r['first_at'], 11, 2) . ':00';
    $per_hour[$h] = ($per_hour[$h] ?? 0) + 1;
    $d = substr($r['first_at'], 0, 10);
    $per_day[$d] = ($per_day[$d] ?? 0) + 1;
}
arsort($per_cat); arsort($per_gate); ksort($per_hour); ksort($per_day);

// per group: QR unik (dari scan berhasil pertama), total scan berhasil, ganda, dan kategori anggotanya
$G = [];
foreach ($uniq as $r) {
    $g = grp_of($r['category']);
    $G[$g] = $G[$g] ?? ['uniq' => 0, 'ok' => 0, 'cats' => []];
    $G[$g]['uniq']++;
    $G[$g]['cats'][$r['category'] !== '' ? $r['category'] : 'Tanpa kategori'] = true;
}
foreach (q("SELECT category, COUNT(*) AS n FROM scans WHERE $w AND status = 'success' AND code <> '' GROUP BY category", $p) as $r) {
    $g = grp_of($r['category']);
    $G[$g] = $G[$g] ?? ['uniq' => 0, 'ok' => 0, 'cats' => []];
    $G[$g]['ok'] += (int)$r['n'];
}
$G_order = grp_sort(array_merge(GROUP_FIRST, array_keys($G)));   // ID-CARD, ASIC, EXH selalu tampil (walau 0)
$unmapped = array_keys($G[NO_GROUP]['cats'] ?? []);
// kategori yang pernah muncul di data scan + yang sudah dipetakan (isi modal "Atur group")
$all_cats = array_values(array_unique(array_merge(
    array_map('strtoupper', $pdo->query("SELECT DISTINCT category FROM scans WHERE category <> ''")->fetchAll(PDO::FETCH_COLUMN)), array_keys($gmap))));
sort($all_cats);
$grp_names = grp_sort(array_merge(GROUP_FIRST, array_values($gmap)));

// ---------- cari ID QR & riwayat scan ----------
$match = function ($r) use ($search) { return $search === '' || stripos($r['code'], $search) !== false; };
$uniq_show = array_values(array_filter($uniq, $match));
$fail_show = array_values(array_filter($fail_only, $match));
if ($code === '' && $search !== '') {
    // ID persis ada di database (tanggal berapa pun), atau pencarian hanya menemukan satu QR -> langsung tampilkan riwayatnya
    $code = (string)q("SELECT code FROM scans WHERE code = ? LIMIT 1", [$search])->fetchColumn();
    if ($code === '' && count($uniq_show) + count($fail_show) === 1) $code = ($uniq_show ?: $fail_show)[0]['code'];
}
// $code terisi: modal riwayat langsung terbuka saat halaman dimuat (link ?code=, atau hasil pencarian tunggal)

// pilihan filter (dari data periode ini)
$opt_dev = $pdo->query("SELECT DISTINCT d.id, d.name FROM devices d JOIN scans s ON s.device_id = d.id ORDER BY d.name")->fetchAll();
$opt_cat = q("SELECT DISTINCT category FROM scans WHERE {$base[0]} AND category <> '' ORDER BY category", $base[1])->fetchAll(PDO::FETCH_COLUMN);
$opt_gate = q("SELECT DISTINCT gate FROM scans WHERE {$base[0]} AND gate <> '' ORDER BY gate", $base[1])->fetchAll(PDO::FETCH_COLUMN);

function bars($rows, $total) {
    if (!$rows) return '<p class="muted">Belum ada QR berhasil.</p>';
    $max = max($rows);
    $o = '<table class="table small rep-bars">';
    foreach ($rows as $k => $n) {
        $o .= '<tr><td>' . h($k) . '</td><td class="rep-bar"><i style="width:' . round($n / $max * 100, 1) . '%"></i></td>'
            . '<td class="right mono"><b>' . $n . '</b> <small class="muted">' . round($n / max(1, $total) * 100) . '%</small></td></tr>';
    }
    return $o . '</table>';
}
$period = $from === $to ? tgl($from) : tgl($from) . ' – ' . tgl($to);

page_head('Laporan scan');
topbar('report', 'Monitoring Laptop');
?>
<main class="page">
  <div class="page-head">
    <div><h1>Laporan scan</h1><p class="muted"><?= h($period) ?>. Satu QR yang berhasil dihitung <b>1</b>, walaupun di-scan berkali-kali.</p></div>
    <div class="rep-head-actions">
      <label class="rep-auto" title="Muat ulang data laporan otomatis (tanpa reload halaman)"><?= icon('pulse') ?> Otomatis
        <select id="auto-refresh" data-url="<?= isset($_GET['auto']) ? (int)$_GET['auto'] : '' ?>" data-today="<?= $to >= $today ? 1 : 0 ?>">
          <?php foreach ([0 => 'Mati', 10 => '10 dtk', 30 => '30 dtk', 60 => '1 mnt', 120 => '2 mnt', 300 => '5 mnt'] as $s => $l): ?><option value="<?= $s ?>"><?= $l ?></option><?php endforeach; ?>
        </select></label>
      <span class="rep-auto-st" id="auto-status" aria-live="polite"></span>
      <a class="btn" href="report_daily.php"><?= icon('chart') ?> Laporan per hari</a>
      <a class="btn btn-primary" href="report.php?<?= h($qs) ?>&amp;csv=1"><?= icon('download') ?> Unduh CSV (<?= $n_uniq ?> QR)</a>
    </div>
  </div>

  <section class="card">
    <form method="get" class="filters rep-filters">
      <label class="rep-search">Cari ID QR <input type="search" name="q" value="<?= h($search) ?>" placeholder="mis. D15Z0H3MC" spellcheck="false" autocomplete="off"></label>
      <label>Dari <input type="date" name="from" value="<?= h($from) ?>" max="<?= $today ?>"></label>
      <label>Sampai <input type="date" name="to" value="<?= h($to) ?>" max="<?= $today ?>"></label>
      <label>Laptop <select name="device"><option value="0">Semua laptop</option><?php foreach ($opt_dev as $o): ?><option value="<?= $o['id'] ?>" <?= $dev === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></label>
      <label>Group <select name="grp"><option value="">Semua group</option><?php foreach (array_merge($grp_names, [NO_GROUP]) as $o): ?><option <?= $grp === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <label>Kategori <select name="category"><option value="">Semua kategori</option><?php foreach ($opt_cat as $o): ?><option <?= $cat === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <label>Gate <select name="gate"><option value="">Semua gate</option><?php foreach ($opt_gate as $o): ?><option <?= $gate === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?></select></label>
      <button class="btn btn-primary">Tampilkan</button>
      <a class="btn" href="report.php">Hari ini</a>
    </form>
  </section>

  <?= flash($flash_msg) ?>
  <?php if ($search !== '' && $code === ''): ?>
  <p class="notice <?= ($uniq_show || $fail_show) ? '' : 'warn-notice' ?>"><?= ($uniq_show || $fail_show)
      ? 'Ditemukan ' . (count($uniq_show) + count($fail_show)) . ' QR yang mengandung "' . h($search) . '". Klik ID QR di daftar untuk melihat riwayatnya.'
      : 'Tidak ada scan dengan ID QR "' . h($search) . '" di periode ini maupun tanggal lain yang masih tersimpan.' ?></p>
  <?php endif; ?>

  <section class="summary">
    <div class="stat ok"><span class="label">QR unik berhasil</span><span class="value"><?= $n_uniq ?></span><span class="sic"><?= icon('save') ?></span></div>
    <div class="stat"><span class="label">Scan berhasil (total)</span><span class="value"><?= $n_success ?></span><span class="sic"><?= icon('pulse') ?></span></div>
    <div class="stat warn"><span class="label">Scan ganda (tidak dihitung)</span><span class="value"><?= max(0, $n_double) ?></span><span class="sic"><?= icon('copy') ?></span></div>
    <div class="stat offline"><span class="label">Scan gagal</span><span class="value"><?= $n_fail ?></span><span class="sic"><?= icon('x') ?></span></div>
  </section>

  <section class="card">
    <div class="table-tools">
      <h2><?= icon('tag') ?> QR unik per group kategori</h2>
      <?php if (is_supervisor()): ?><button type="button" class="btn" data-open-modal="grp-modal"><?= icon('edit') ?> Atur group</button><?php endif; ?>
    </div>
    <div class="rep-groups">
      <?php foreach ($G_order as $g): $x = $G[$g] ?? ['uniq' => 0, 'ok' => 0, 'cats' => []]; if ($g === NO_GROUP && !$x['uniq']) continue; ?>
      <div class="stat <?= $g === NO_GROUP ? 'warn' : 'ok' ?>"><span class="label"><?= h($g) ?></span><span class="value"><?= $x['uniq'] ?></span>
        <small class="muted rep-gsub"><?= $n_uniq ? round($x['uniq'] / $n_uniq * 100) : 0 ?>% · <?= $x['ok'] ?> scan berhasil, <?= max(0, $x['ok'] - $x['uniq']) ?> ganda</small></div>
      <?php endforeach; ?>
    </div>
    <div class="table-wrap"><table class="table small">
      <tr><th>Group</th><th>Kategori (di periode ini)</th><th class="right">QR unik</th><th class="right">Scan berhasil</th><th class="right">Ganda</th></tr>
      <?php foreach ($G_order as $g): $x = $G[$g] ?? ['uniq' => 0, 'ok' => 0, 'cats' => []]; if ($g === NO_GROUP && !$x['uniq']) continue; ?>
      <tr><td><b><?= h($g) ?></b></td><td><?= $x['cats'] ? h(implode(', ', array_keys($x['cats']))) : '<span class="muted">–</span>' ?></td>
        <td class="right mono"><b><?= $x['uniq'] ?></b></td><td class="right mono"><?= $x['ok'] ?></td><td class="right mono"><?= max(0, $x['ok'] - $x['uniq']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="rep-total"><td><b>Total</b></td><td></td><td class="right mono"><b><?= $n_uniq ?></b></td><td class="right mono"><?= $n_success - $no_code ?></td><td class="right mono"><?= max(0, $n_double) ?></td></tr>
    </table></div>
    <?php if ($unmapped): ?><p class="notice warn-notice small">Kategori belum punya group: <b><?= h(implode(', ', $unmapped)) ?></b>.<?= is_supervisor() ? ' Klik <b>Atur group</b> untuk memetakannya.' : '' ?></p><?php endif; ?>
    <p class="muted small">Scan gagal (tidak ditemukan) tidak membawa kategori, jadi tidak bisa dipecah per group.</p>
  </section>

  <div class="settings-grid">
    <section class="card">
      <h2><?= icon('pulse') ?> Rincian status scan</h2>
      <div class="load">
        <?php foreach (STATUS_LABEL as $k => $l): ?><div><span><?= h($l) ?></span><b><?= $by_status[$k] ?></b></div><?php endforeach; ?>
        <div><span>QR gagal yang tidak pernah berhasil</span><b><?= count($fail_only) ?></b></div>
        <?php if ($no_code): ?><div><span>Berhasil tanpa ID QR (tidak dihitung unik)</span><b><?= $no_code ?></b></div><?php endif; ?>
      </div>
      <p class="muted small">Scan gagal tidak mengurangi hitungan: QR yang awalnya gagal lalu berhasil tetap dihitung 1 berhasil.</p>
    </section>
    <section class="card">
      <h2><?= icon('tag') ?> QR unik per kategori</h2>
      <?= bars($per_cat, $n_uniq) ?>
    </section>
  </div>

  <div class="settings-grid">
    <section class="card">
      <h2><?= icon('devices') ?> QR unik per gate / laptop</h2>
      <?= bars($per_gate, $n_uniq) ?>
      <p class="muted small">QR dihitung di gate tempat pertama kali berhasil.</p>
    </section>
    <section class="card">
      <h2><?= icon('monitor') ?> QR unik per <?= count($per_day) > 1 ? 'hari' : 'jam' ?></h2>
      <?= bars(count($per_day) > 1 ? $per_day : $per_hour, $n_uniq) ?>
    </section>
  </div>

  <?= list_section('u') ?>
  <?php if ($fail_only): ?><?= list_section('f') ?><?php endif; ?>
</main>

<div class="modal" id="hist-modal" <?= $code === '' ? 'hidden' : '' ?> role="dialog" aria-modal="true" aria-label="Riwayat scan">
  <div class="modal-box form-modal hist-box" id="hist-body"><?= $code !== '' ? history_html($code) : '' ?></div>
</div>
<?php if (is_supervisor()): ?>
<div class="modal" id="grp-modal" hidden role="dialog" aria-modal="true" aria-label="Atur group kategori">
  <form method="post" class="modal-box form-modal grp-box">
    <div class="modal-head"><h3><?= icon('tag') ?> Atur group kategori</h3><button type="button" class="icon-btn sm" data-close-modal aria-label="Tutup">✕</button></div>
    <div class="hist-body">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>"><input type="hidden" name="act" value="groups"><input type="hidden" name="back" value="<?= h($qs) ?>">
      <p class="muted small">Kategori dari halaman scan dipetakan ke group untuk laporan. Ketik nama group (mis. <code>ID-CARD</code>, <code>ASIC</code>, <code>EXH</code>), kosongkan untuk melepas. Kategori baru: isi di baris paling bawah.</p>
      <datalist id="grp-list"><?php foreach ($grp_names as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>
      <div class="table-wrap"><table class="table small grp-table">
        <tr><th>Kategori</th><th>Group</th></tr>
        <?php foreach ($all_cats as $c): ?>
        <tr><td><input type="hidden" name="cat[]" value="<?= h($c) ?>"><b><?= h($c) ?></b><?= isset($gmap[$c]) ? '' : ' <span class="badge warn-badge">belum ada group</span>' ?></td>
          <td><input name="grp[]" value="<?= h($gmap[$c] ?? '') ?>" list="grp-list" maxlength="50" placeholder="tanpa group" autocomplete="off" spellcheck="false"></td></tr>
        <?php endforeach; ?>
        <tr><td><input name="cat[]" maxlength="100" placeholder="kategori baru (opsional)" autocomplete="off"></td><td><input name="grp[]" list="grp-list" maxlength="50" placeholder="group" autocomplete="off"></td></tr>
      </table></div>
    </div>
    <div class="actions grp-actions"><button type="button" class="btn" data-close-modal>Batal</button><button class="btn btn-primary"><?= icon('save') ?> Simpan group</button></div>
  </form>
</div>
<?php endif; ?>
<?php page_foot(['report.js?v=5']);
