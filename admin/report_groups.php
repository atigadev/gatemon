<?php
// Group kategori scan (tabel scan_groups: kategori dari halaman gate -> group, mis. VIP -> ID-CARD).
if (!function_exists('db')) { http_response_code(404); exit; }   // hanya untuk di-include
// Dipakai report.php (laporan scan) dan report_daily.php (laporan per hari).
const NO_GROUP = 'Tanpa group';
const GROUP_FIRST = ['ID-CARD', 'ASIC', 'EXH'];   // urutan tampil; group lain menyusul urut abjad
$gmap = [];
foreach (db()->query("SELECT category, grp FROM scan_groups") as $r) $gmap[strtoupper(trim($r['category']))] = $r['grp'];
function grp_of($c) { global $gmap; $c = strtoupper(trim((string)$c)); return $c === '' ? NO_GROUP : ($gmap[$c] ?? NO_GROUP); }
function grp_sort(array $names) {
    $names = array_values(array_unique($names));
    usort($names, function ($a, $b) {
        $ia = array_search($a, GROUP_FIRST, true); $ib = array_search($b, GROUP_FIRST, true);
        if ($a === NO_GROUP || $b === NO_GROUP) return ($a === NO_GROUP) - ($b === NO_GROUP);
        if ($ia !== false || $ib !== false) return ($ia === false ? 99 : $ia) - ($ib === false ? 99 : $ib);
        return strcmp($a, $b);
    });
    return $names;
}
// Peruntukan (field di balasan scan, agent >= 1.20). Laporan per peruntukan selalu menampilkan group ini,
// group lain ikut tampil bila datanya punya peruntukan.
const NO_PURPOSE = 'Tanpa peruntukan';
const PURPOSE_GROUPS = ['ASIC', 'EXH'];
function purpose_of($p) { $p = trim((string)$p); return $p === '' ? NO_PURPOSE : $p; }
// $rows: baris QR unik (category, peruntukan) -> [group => [peruntukan => [kategori => jumlah]]]
function purpose_matrix($rows) {
    $m = [];
    foreach ($rows as $r) {
        $g = grp_of($r['category']);
        $c = $r['category'] !== '' ? $r['category'] : 'Tanpa kategori';
        $m[$g][purpose_of($r['peruntukan'])][$c] = ($m[$g][purpose_of($r['peruntukan'])][$c] ?? 0) + 1;
    }
    return purpose_sort($m);
}
// [group => [peruntukan => [kunci => jumlah]]]: pilih group yang ditampilkan, urutkan peruntukan (terbanyak dulu, "Tanpa peruntukan" terakhir)
function purpose_sort(array $m) {
    $out = [];
    foreach (grp_sort(array_merge(PURPOSE_GROUPS, array_keys($m))) as $g) {
        $x = $m[$g] ?? [];
        // group lain hanya bila ada peruntukan terisi
        if (!in_array($g, PURPOSE_GROUPS, true) && !array_diff(array_keys($x), [NO_PURPOSE])) continue;
        uksort($x, function ($a, $b) use ($x) {
            if ($a === NO_PURPOSE || $b === NO_PURPOSE) return ($a === NO_PURPOSE) - ($b === NO_PURPOSE);
            return array_sum($x[$b]) - array_sum($x[$a]) ?: strcmp($a, $b);
        });
        $out[$g] = $x;
    }
    return $out;
}

// Teks dari halaman gate untuk CSV: awalan = + - @ diberi ' agar tidak dijalankan Excel sebagai rumus
function csv_txt($v) { $v = (string)$v; return $v !== '' && strpos('=+-@', $v[0]) !== false ? "'" . $v : $v; }

// "26 Okt 2026"; $day = true menambah nama hari ("Senin, 26 Okt 2026")
function tgl($d, $day = false) {
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $t = strtotime($d);
    return ($day ? $hari[(int)date('w', $t)] . ', ' : '') . date('j', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t);
}
