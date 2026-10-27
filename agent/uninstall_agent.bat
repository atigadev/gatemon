@echo off
REM Menghentikan dan menghapus GateMon Agent
schtasks /end /tn "GateMonAgent" >nul 2>&1
schtasks /delete /f /tn "GateMonAgent"
taskkill /f /im pythonw.exe >nul 2>&1
echo GateMon Agent dihapus.
pause
