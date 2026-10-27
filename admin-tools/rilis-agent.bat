@echo off
REM Rilis update agent bertanda tangan. Contoh:
REM   rilis-agent.bat kunci-baru   E:\gatemon-update.key   (sekali)
REM   rilis-agent.bat tanda-tangan E:\gatemon-update.key   (setiap versi baru)
setlocal
set "PHP=C:\xampp\php\php.exe"
if not exist "%PHP%" set "PHP=php"
"%PHP%" -d extension=sodium "%~dp0rilis-agent.php" %*
set "RC=%ERRORLEVEL%"
pause
exit /b %RC%
