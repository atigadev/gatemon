@echo off
REM GateMon Agent - uninstall: hentikan agent, kembalikan pengaturan, lalu HAPUS seluruh folder agent.
REM Cukup klik dua kali, izin Administrator akan diminta otomatis. Langkahnya ada di uninstall_agent.ps1.
net session >nul 2>&1
if not errorlevel 1 goto run
echo Meminta izin Administrator...
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
exit /b

:run
if not exist "%~dp0uninstall_agent.ps1" (
  echo uninstall_agent.ps1 tidak ditemukan di %~dp0
  pause
  exit /b 1
)
REM dijalankan dari salinan di TEMP karena folder ini ikut dihapus
copy /y "%~dp0uninstall_agent.ps1" "%TEMP%\gatemon-uninstall.ps1" >nul
cd /d "%TEMP%"
powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\gatemon-uninstall.ps1" -Dir "%~dp0." & exit /b
