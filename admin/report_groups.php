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
// "26 Okt 2026"; $day = true menambah nama hari ("Senin, 26 Okt 2026")
function tgl($d, $day = false) {
    $bln = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $t = strtotime($d);
    return ($day ? $hari[(int)date('w', $t)] . ', ' : '') . date('j', $t) . ' ' . $bln[(int)date('n', $t)] . ' ' . date('Y', $t);
}
