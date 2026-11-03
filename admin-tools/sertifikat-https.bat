@echo off
REM Sertifikat HTTPS GateMon (CA lokal) untuk aplikasi PWA & push notification. Contoh:
REM   sertifikat-https.bat C:\GateMon-kunci
REM   sertifikat-https.bat C:\GateMon-kunci 10.5.5.100 gatemon.lan
setlocal
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
"%PHP%" "%~dp0sertifikat-https.php" %*
set "RC=%ERRORLEVEL%"
pause
exit /b %RC%
