@echo off
REM GateMon Agent installer - cukup klik dua kali, izin Administrator akan diminta otomatis.
REM Langkah instalasi ada di install_agent.ps1 (login kode instalasi, Python, library, tugas otomatis).
net session >nul 2>&1
if not errorlevel 1 goto run
echo Meminta izin Administrator...
powershell -NoProfile -Command "Start-Process -FilePath '%~f0' -Verb RunAs"
exit /b

:run
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install_agent.ps1"
