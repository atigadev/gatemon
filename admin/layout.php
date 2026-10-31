<?php
// Kerangka halaman bersama: <head>, topbar + navigasi, ikon, dan tema (gelap/terang).
// Dipakai semua halaman admin agar tampilan seragam.

function icon($name, $cls = 'ic') {
    static $p = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'monitor'   => '<rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M6 11l3-3 3 3 3-4 3 3"/>',
        'devices'   => '<rect x="4" y="4" width="16" height="11" rx="1.5"/><path d="M2 19h20"/>',
        'tag'       => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'users'     => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8M22 21a7 7 0 0 0-4-6.3"/>',
        'logout'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l-5-5 5-5M5 12h12"/>',
        'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon'      => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'menu'      => '<path d="M3 6h18M3 12h18M3 18h18"/>',
        'expand'    => '<path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/>',
        'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5M12 15V3"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'pulse'     => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'key'       => '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M18 5l2 2"/>',
        'trash'     => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
        'save'      => '<path d="M20 6L9 17l-5-5"/>',
        'enroll'    => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5M15 12H3"/>',
        'gear'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'x'         => '<path d="M18 6L6 18M6 6l12 12"/>',
        'copy'      => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
        'chart'     => '<path d="M3 3v18h18"/><rect x="7" y="12" width="3" height="6" rx="1"/><rect x="12" y="8" width="3" height="10" rx="1"/><rect x="17" y="5" width="3" height="13" rx="1"/>',
        'power'     => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'chat'      => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20.5l1.4-4.9A8 8 0 1 1 21 12z"/><path d="M8.5 11h.01M12 11h.01M15.5 11h.01"/>',
        'camera'    => '<path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2"/>',
    ];
    return '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

function logo() {
    return '<span class="logo">' . icon('pulse', 'ic') . '</span>';
}

// <head> lengkap + skrip tema (dijalankan sebelum render agar tidak berkedip)
function page_head($title, $body_class = '', $extra = '') {
    global $CFG;
    send_csp();
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
       . '<meta name="color-scheme" content="dark light">'
       . '<title>' . h($title) . ' - ' . h($CFG['app_name']) . '</title>'
       . '<link rel="stylesheet" href="style.css?v=37">'
       . '<script nonce="' . csp_nonce() . '">(function(){try{var t=localStorage.getItem("gm_theme");}catch(e){}'
       . 'if(!t)t=matchMedia("(prefers-color-scheme: light)").matches?"light":"dark";'
       . 'document.documentElement.setAttribute("data-theme",t);})();</script>'
       . $extra . '</head><body' . ($body_class ? ' class="' . h($body_class) . '"' : '') . '>';
}

function nav_items() {
    return [
        'index'      => ['Dashboard', 'dashboard'],
        'monitor'    => ['Monitor', 'monitor'],
        'report'     => ['Laporan', 'chart'],
        'support'    => ['Support', 'chat'],
        'devices'    => ['Devices', 'devices'],
        'categories' => ['Kategori', 'tag'],
        'wallpaper'  => ['Wallpaper', 'image'],
        'policy'     => ['Daya', 'power'],
        'agent_update' => ['Agent', 'download'],
        'admins'     => ['Admins', 'users'],
        'settings'   => ['Pengaturan', 'gear'],
    ];
}

function theme_button() {
    return '<button class="icon-btn" type="button" data-theme-toggle title="Ganti tema gelap / terang" aria-label="Ganti tema">'
         . icon('sun', 'ic only-dark') . icon('moon', 'ic only-light') . '</button>';
}

// Topbar dengan navigasi; di layar kecil menjadi menu hamburger
function topbar($active, $subtitle = '', $extra_right = '') {
    global $CFG;
    $u = current_user();
    echo '<header class="topbar"><div class="topbar-in">'
       . '<a class="brand" href="index.php">' . logo() . '<span class="brand-txt">' . h($CFG['app_name'])
       . ($subtitle ? '<small>' . h($subtitle) . '</small>' : '') . '</span></a>'
       . '<button class="icon-btn nav-toggle" type="button" data-nav-toggle aria-label="Menu" aria-expanded="false">' . icon('menu') . '</button>'
       . '<nav class="nav" id="nav">';
    foreach (nav_items() as $k => [$label, $ic]) {
        echo '<a href="' . $k . '.php" title="' . $label . '"' . ($k === $active ? ' class="active" aria-current="page"' : '') . '>' . icon($ic) . '<span>' . $label . '</span>'
           . ($k === 'support' ? '<b class="nav-badge" data-sup-badge hidden></b>' : '') . '</a>';
    }
    echo '</nav><div class="top-right">' . $extra_right . theme_button()
       . '<div class="user"><span class="avatar">' . h(strtoupper(substr($u['username'], 0, 1))) . '</span>'
       . '<span class="user-txt"><b>' . h($u['username']) . '</b><small>' . h(ROLE_NAMES[$u['role']] ?? $u['role']) . '</small></span></div>'
       . '<a class="icon-btn" href="logout.php?t=' . csrf_token() . '" title="Keluar" aria-label="Keluar">' . icon('logout') . '</a>'
       . '</div></div></header>';
}

function page_foot($scripts = []) {
    echo '<script src="ui.js?v=7"></script>';
    // alarm darurat & pesan baru dari laptop (menu Support) di semua halaman setelah login
    if (function_exists('current_user') && ($u = current_user())) {
        echo '<script src="support_alert.js?v=5" data-csrf="' . csrf_token() . '" data-role="' . h($u['role']) . '"></script>';
    }
    foreach ($scripts as $s) echo '<script src="' . h($s) . '"></script>';
    echo '</body></html>';
}

// Pesan hasil aksi form; pesan gagal (validasi/duplikat) ditampilkan merah
function flash($msg) {
    if ($msg === '' || $msg === null) return '';
    $err = preg_match('/(sudah ada|wajib|hanya |tidak bisa|minimal|tidak valid|gagal)/i', $msg);
    return '<p class="notice' . ($err ? ' error' : '') . '" role="status">' . h($msg) . '</p>';
}
