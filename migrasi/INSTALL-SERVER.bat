@echo off
setlocal
title GateMon - instalasi server
REM Pasang GateMon di server ini (XAMPP) dari paket "Pindah / cadangkan server".
REM Folder gatemon lama (bila ada) dipindah ke cadangan, database lama dicadangkan sebelum ditimpa.
cd /d "%~dp0"
if not exist "%~dp0gatemon\migrasi\import.php" goto nopkg

set "XAMPP=C:\xampp"
if exist "%XAMPP%\php\php.exe" goto havexampp
set /p "XAMPP=Folder XAMPP tidak ditemukan di C:\xampp. Ketik foldernya (contoh D:\xampp): "
if not exist "%XAMPP%\php\php.exe" goto noxampp
:havexampp
set "DEST=%XAMPP%\htdocs\gatemon"
echo.
echo === GateMon - instalasi server ===
echo XAMPP : %XAMPP%
echo Tujuan: %DEST%
echo Pastikan Apache dan MySQL sudah Start di XAMPP Control Panel.
echo.
pause

if not exist "%DEST%" goto copy
for /f "delims=" %%t in ('powershell -NoProfile -Command "Get-Date -Format yyyyMMdd-HHmmss"') do set "TS=%%t"
echo Folder lama dipindah ke %DEST%-lama-%TS%
move "%DEST%" "%DEST%-lama-%TS%" >nul
if errorlevel 1 goto nomove

:copy
echo Menyalin file GateMon...
robocopy "%~dp0gatemon" "%DEST%" /E /NFL /NDL /NJH /NJS /NP >nul
if errorlevel 8 goto nocopy

echo Mengimpor database...
"%XAMPP%\php\php.exe" "%DEST%\migrasi\import.php" "%XAMPP%"
if errorlevel 1 goto failed

start "" "http://localhost/gatemon/setup.php"
echo.
pause
exit /b 0

:nopkg
echo Jalankan file ini dari dalam folder hasil ekstrak paket GateMon-Server (ada folder "gatemon" di sebelahnya).
pause
exit /b 1
:noxampp
echo php.exe tidak ditemukan di %XAMPP%\php. Pasang XAMPP dulu.
pause
exit /b 1
:nomove
echo GAGAL memindah folder lama. Tutup Explorer/editor yang membuka folder itu lalu coba lagi.
pause
exit /b 1
:nocopy
echo GAGAL menyalin file ke %DEST%.
pause
exit /b 1
:failed
echo Impor database tidak selesai. Lihat pesan di atas.
pause
exit /b 1
