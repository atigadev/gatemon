# GateMon Agent - uninstall (dijalankan oleh uninstall_agent.bat sebagai Administrator, dari salinan di TEMP)
# 1. Hapus tugas otomatis   2. Hentikan proses agent, browser gate & penjaga update yang memakai folder agent
# 3. Kembalikan wallpaper asli dan Windows Update bila pernah diubah GateMon   4. HAPUS seluruh folder agent
# Python dan library-nya TIDAK dihapus (bisa dipakai program lain); hapus lewat Settings > Apps bila perlu.
# Pengaman: hanya menghapus folder yang berisi agent.py + config.json GateMon, bukan folder sistem / profil.
# -Dir: folder agent (bawaan: dari tugas terjadwal, lalu C:\GateMon-Agent)   -Yes: tanpa konfirmasi
# Catatan: file ini sengaja hanya berisi karakter ASCII (PowerShell 5.1 membaca file tanpa BOM sebagai ANSI).
param([string]$Dir = '', [switch]$Yes)

$ErrorActionPreference = 'Continue'
function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }
function Pause-End { if (-not $Yes -and -not [Console]::IsInputRedirected) { Read-Host 'Tekan Enter untuk menutup' | Out-Null } }
function Fail($text) { Say ''; Say "GAGAL: $text" Red; Pause-End; exit 1 }

Say ''
Say '=== GateMon Agent - uninstall ===' Cyan
Say ''
$me = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'Jalankan uninstall_agent.bat (akan meminta izin Administrator).' }

# ---------- folder & nama tugas ----------
$TaskName = 'GateMonAgent'
$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if (-not $Dir -and $task -and $task.Actions[0].WorkingDirectory) { $Dir = $task.Actions[0].WorkingDirectory }
if (-not $Dir) { $Dir = 'C:\GateMon-Agent' }
$Dir = [IO.Path]::GetFullPath($Dir).TrimEnd('\')
$cfgFile = Join-Path $Dir 'config.json'
if (Test-Path $cfgFile) {
    try { $cfg = Get-Content $cfgFile -Raw | ConvertFrom-Json; if ($cfg.task_name) { $TaskName = [string]$cfg.task_name; $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue } } catch {}
}
$exists = Test-Path -LiteralPath $Dir
if ($exists) {
    # jangan pernah menghapus folder sistem / profil / akar drive, dan hanya folder yang memang berisi agent GateMon
    $guard = @($env:SystemDrive, $env:SystemRoot, $env:ProgramFiles, ${env:ProgramFiles(x86)}, $env:ProgramData, $env:USERPROFILE,
        (Split-Path $env:USERPROFILE -Parent), [Environment]::GetFolderPath('Desktop'), [Environment]::GetFolderPath('MyDocuments')) |
        Where-Object { $_ } | ForEach-Object { $_.TrimEnd('\').ToLower() }
    if ($Dir.Length -le 3 -or $guard -contains $Dir.ToLower()) { Fail "folder $Dir tidak boleh dihapus." }
    if (-not (Test-Path (Join-Path $Dir 'agent.py')) -or -not (Test-Path $cfgFile) -or -not (Select-String -Path (Join-Path $Dir 'agent.py') -Pattern 'GateMon Agent' -Quiet)) {
        Fail "$Dir bukan folder GateMon Agent (agent.py / config.json tidak ditemukan). Jalankan uninstall_agent.bat dari folder agent."
    }
}

Say "Folder agent : $Dir$(if (-not $exists) { ' (tidak ada)' })"
Say "Tugas        : $TaskName$(if (-not $task) { ' (tidak ada)' })"
Say ''
Say 'Yang akan dilakukan: hentikan & hapus agent, kembalikan wallpaper asli dan Windows Update (bila diubah),' Yellow
Say 'lalu HAPUS SELURUH isi folder di atas (log, pengaturan, profil browser gate, wallpaper unduhan).' Yellow
if (-not $Yes) {
    $a = Read-Host 'Lanjutkan? Ketik Y lalu Enter'
    if ($a -notmatch '^[Yy]') { Say 'Dibatalkan, tidak ada yang diubah.'; Pause-End; exit 0 }
}
Say ''

# ---------- 1. tugas otomatis ----------
Say '[1/4] Menghapus tugas otomatis...'
if ($task) {
    try { Stop-ScheduledTask -TaskName $TaskName -ErrorAction Stop } catch {}
    try { Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction Stop; Say "      tugas $TaskName dihapus" Green }
    catch { Say "      gagal menghapus tugas: $($_.Exception.Message)" Red }
} else { Say '      tidak ada' }

# ---------- 2. proses yang memakai folder agent ----------
# Hanya proses yang menjalankan file di folder agent (agent, browser gate berprofil folder ini, penjaga update),
# bukan semua pythonw/chrome di laptop. Proses uninstall ini sendiri dan jendelanya tidak ikut dihentikan.
Say '[2/4] Menghentikan proses agent...'
$keep = @($PID)
try { $keep += (Get-CimInstance Win32_Process -Filter "ProcessId=$PID").ParentProcessId } catch {}
$n = 0
if ($exists) {
    for ($round = 0; $round -lt 3; $round++) {
        $procs = @(Get-CimInstance Win32_Process | Where-Object {
            $keep -notcontains $_.ProcessId -and $_.Name -match '^(pythonw?|chrome|msedge|powershell)\.exe$' -and
            (([string]$_.CommandLine).ToLower().Contains($Dir.ToLower()) -or ([string]$_.ExecutablePath).ToLower().StartsWith($Dir.ToLower() + '\')) })
        if (-not $procs) { break }
        foreach ($p in $procs) { try { Stop-Process -Id $p.ProcessId -Force -ErrorAction Stop; $n++ } catch {} }
        Start-Sleep -Seconds 1
    }
}
Say "      $n proses dihentikan" Green

# ---------- 3. kembalikan pengaturan yang diubah GateMon ----------
Say '[3/4] Mengembalikan pengaturan laptop...'
$wpState = Join-Path $Dir 'wallpaper\state.json'
if (Test-Path $wpState) {
    try {
        $st = Get-Content $wpState -Raw | ConvertFrom-Json
        if ($st.sha) {
            $orig = [string]$st.orig
            if ($orig -and -not (Test-Path -LiteralPath $orig)) { $orig = '' }
            if ($st.orig_style -and @($st.orig_style).Count -eq 2) {
                Set-ItemProperty 'HKCU:\Control Panel\Desktop' -Name WallpaperStyle -Value ([string]$st.orig_style[0])
                Set-ItemProperty 'HKCU:\Control Panel\Desktop' -Name TileWallpaper -Value ([string]$st.orig_style[1])
            }
            Add-Type -Namespace GateMon -Name Wp -MemberDefinition '[DllImport("user32.dll", CharSet = CharSet.Unicode)] public static extern bool SystemParametersInfo(uint a, uint b, string c, uint d);'
            [void][GateMon.Wp]::SystemParametersInfo(0x14, 0, $orig, 3)
            Say "      wallpaper asli dikembalikan$(if (-not $orig) { ' (polos, file asli tidak ada)' })" Green
        } else { Say '      wallpaper: tidak pernah diubah GateMon' }
    } catch { Say "      wallpaper gagal dikembalikan: $($_.Exception.Message)" Yellow }
} else { Say '      wallpaper: tidak pernah diubah GateMon' }
$polState = Join-Path $Dir 'policy-state.json'
$pol = $null
if (Test-Path $polState) { try { $pol = Get-Content $polState -Raw | ConvertFrom-Json } catch {} }
if ($pol -and $pol.winupdate -eq 'off') {
    & reg.exe add 'HKLM\SOFTWARE\Policies\Microsoft\Windows\WindowsUpdate\AU' /v NoAutoUpdate /t REG_DWORD /d 0 /f | Out-Null
    foreach ($s in @(@('wuauserv', 'demand'), @('UsoSvc', 'delayed-auto'), @('WaaSMedicSvc', 'demand'))) { & sc.exe config $s[0] start= $s[1] | Out-Null }
    & sc.exe start wuauserv | Out-Null
    Say '      Windows Update diaktifkan kembali' Green
} else { Say '      Windows Update: tidak pernah dinonaktifkan GateMon' }
if ($pol -and $pol.power) { Say "      pengaturan daya dibiarkan ($($pol.power)); ubah di Settings > System > Power bila perlu" }

# ---------- 4. hapus folder ----------
Say "[4/4] Menghapus folder $Dir ..."
Set-Location $env:TEMP
if ($exists) {
    for ($i = 0; $i -lt 6 -and (Test-Path -LiteralPath $Dir); $i++) {
        Remove-Item -LiteralPath $Dir -Recurse -Force -ErrorAction SilentlyContinue
        if (Test-Path -LiteralPath $Dir) { Start-Sleep -Seconds 1 }
    }
    if (Test-Path -LiteralPath $Dir) {
        # masih ada file terkunci (mis. dibuka program lain): dihapus otomatis saat Windows dinyalakan ulang
        $left = @(Get-ChildItem -LiteralPath $Dir -Recurse -Force -ErrorAction SilentlyContinue).Count
        Set-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\RunOnce' -Name 'GateMonUninstall' -Value ('cmd.exe /c rd /s /q "' + $Dir + '"')
        Say "      $left file masih terkunci; folder dihapus otomatis setelah laptop di-restart." Yellow
    } else { Say '      folder dihapus' Green }
} else { Say '      tidak ada' }

Say ''
Say 'Selesai. GateMon Agent sudah dilepas dari laptop ini.' Green
Say 'Laptop ini tetap tercatat di dashboard (offline); hapus di menu Devices bila tidak dipakai lagi.'
Pause-End
