<?php
// Unduh "Setup-Radmin-GateMon.bat" untuk laptop ADMIN: mendaftarkan link radmin:// agar tombol
// "Remote via Radmin" membuka Radmin Viewer. Hanya untuk user Windows yang menjalankannya (HKCU, tanpa hak
// Administrator). Peluncur (radmin-launch.ps1) memvalidasi alamat sebelum Radmin Viewer dijalankan.
require __DIR__ . '/auth.php';
require_login();

$ps1 = @file_get_contents(__DIR__ . '/../admin-tools/radmin-launch.ps1');
if ($ps1 === false) { http_response_code(500); exit('admin-tools/radmin-launch.ps1 tidak ditemukan.'); }
$b64 = base64_encode($ps1);

$bat = [
    '@echo off',
    'REM GateMon - pasang link radmin:// untuk tombol "Remote via Radmin" di laptop admin ini.',
    'REM Hanya untuk user Windows ini (tidak perlu hak Administrator). Hapus: reg delete HKCU\Software\Classes\radmin /f',
    'title GateMon - setup link Radmin',
    'set "GMDIR=%LOCALAPPDATA%\GateMon"',
    'if not exist "%GMDIR%" mkdir "%GMDIR%"',
    'powershell -NoProfile -Command "[IO.File]::WriteAllBytes($env:LOCALAPPDATA + \'\GateMon\radmin-launch.ps1\', [Convert]::FromBase64String(\'' . $b64 . '\'))"',
    'if not exist "%GMDIR%\radmin-launch.ps1" (echo GAGAL menulis peluncur. & pause & exit /b 1)',
    'reg add "HKCU\Software\Classes\radmin" /ve /d "URL:Radmin Protocol" /f >nul',
    'reg add "HKCU\Software\Classes\radmin" /v "URL Protocol" /d "" /f >nul',
    'reg add "HKCU\Software\Classes\radmin\shell\open\command" /ve /d "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File \"%GMDIR%\radmin-launch.ps1\" \"%%1\"" /f >nul',
    'if errorlevel 1 (echo GAGAL mendaftarkan link radmin://. & pause & exit /b 1)',
    'echo.',
    'echo Selesai. Link radmin:// sudah terdaftar untuk user %USERNAME%.',
    'set "RV="',
    'if exist "%ProgramFiles(x86)%\Radmin Viewer 3\Radmin.exe" set "RV=1"',
    'if exist "%ProgramFiles%\Radmin Viewer 3\Radmin.exe" set "RV=1"',
    'if not defined RV echo PERHATIAN: Radmin Viewer 3 belum terpasang di laptop ini. Pasang dari radmin.com.',
    'echo Klik "Remote via Radmin" di dashboard. Bila browser bertanya, centang "Selalu izinkan" lalu Buka.',
    'pause',
];
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="Setup-Radmin-GateMon.bat"');
header('Cache-Control: no-store');
echo implode("\r\n", $bat) . "\r\n";
