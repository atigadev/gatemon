# GateMon Agent installer (dijalankan oleh install_agent.bat sebagai Administrator)
# 1. Konfigurasi: config.json dari paket, atau login dengan kode instalasi sekali pakai (username + password)
# 2. Python: dipakai yang sudah ada, atau dipasang otomatis dari python-*.exe di folder paket
# 3. Salin file ke C:\GateMon-Agent   4. Pasang library (offline bila ada folder wheels)   5. Tugas otomatis + jalankan
# Catatan: file ini sengaja hanya berisi karakter ASCII (PowerShell 5.1 membaca file tanpa BOM sebagai ANSI).
# -User/-Pass opsional untuk instalasi tanpa tanya (misal lewat skrip); tanpa itu password diketik tersembunyi.
# -Python: pakai python.exe tertentu; -SkipTask: tanpa membuat tugas otomatis (untuk teknisi / pengujian).
# -NoWoL: jangan ubah pengaturan Wake-on-LAN (Fast Startup & daya kartu jaringan).
param([switch]$DryRun, [string]$Target = 'C:\GateMon-Agent', [string]$User = '', [string]$Pass = '', [string]$Python = '', [switch]$SkipTask, [switch]$NoWoL)

$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$TaskName = 'GateMonAgent'

function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }
# jendela tetap terbuka agar hasilnya terbaca (dilewati bila dijalankan lewat skrip / input dialihkan)
function Pause-End { if (-not $DryRun -and -not [Console]::IsInputRedirected) { Read-Host 'Tekan Enter untuk menutup' | Out-Null } }
function Fail($text) { Say ''; Say "GAGAL: $text" Red; Pause-End; exit 1 }
# config.json harus UTF-8 tanpa BOM agar terbaca oleh json.load di agent.py
function Write-Utf8($path, $text) { [IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding($false))) }

Say ''
Say '=== GateMon Agent - instalasi ===' Cyan
Say ''

if (-not $DryRun -and -not $SkipTask) {   # Administrator perlu untuk C:\GateMon-Agent dan tugas terjadwal
    $me =New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
    if (-not $me.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { Fail 'Jalankan install_agent.bat (akan meminta izin Administrator).' }
}

# ---------- 1. konfigurasi ----------
$cfgText = $null
$pkgCfg = Join-Path $Here 'config.json'
if (Test-Path $pkgCfg) {
    Say '[1/5] config.json ada di paket, login dilewati.'
    $cfgText = [IO.File]::ReadAllText($pkgCfg)
} else {
    $server = ''
    $sf = Join-Path $Here 'server.txt'
    if (Test-Path $sf) { $server = ([string](Get-Content $sf -TotalCount 1)).Trim() }
    if (-not $server) { $server = (Read-Host 'Alamat server GateMon (contoh http://192.168.1.10/gatemon)').Trim() }
    $server = $server.TrimEnd('/')
    Say "[1/5] Login ke server $server"
    Say '      Username = ID laptop, password = kode instalasi dari menu Devices (sekali pakai).'
    $r = $null
    for ($try = 1; $try -le 3 -and -not $r; $try++) {
        if ($User -and $Pass) {
            $user = $User; $pass = $Pass; $try = 3   # tanpa tanya: satu kali coba
        } else {
            $user = (Read-Host '      Username').Trim()
            $sec = Read-Host '      Password' -AsSecureString
            $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($sec)
            $pass = [Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
            [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
        }
        $body = @{ username = $user; password = $pass; hostname = $env:COMPUTERNAME } | ConvertTo-Json
        try {
            $r = Invoke-RestMethod -Uri "$server/api/enroll.php" -Method Post -ContentType 'application/json' -Body $body -TimeoutSec 20 -UseBasicParsing
        } catch {
            $msg = $_.Exception.Message
            $code = 0
            $resp = $_.Exception.Response
            if ($resp) { $code = [int]$resp.StatusCode }
            # pesan dari server (JSON {"error": ...}) ada di ErrorDetails pada PowerShell 5.1
            if ($_.ErrorDetails -and $_.ErrorDetails.Message) {
                try { $j = $_.ErrorDetails.Message | ConvertFrom-Json; if ($j.error) { $msg = $j.error } } catch {}
            }
            if ($code -eq 401) { Say "      Ditolak: $msg" Yellow; $r = $null; continue }
            if ($code -eq 0) { Fail "Server tidak bisa dihubungi ($msg). Periksa jaringan dan alamat server di server.txt." }
            Fail $msg
        }
    }
    if (-not $r -or -not $r.ok) { Fail 'Login gagal. Periksa username/password, atau buat kode baru di menu Devices.' }
    $cfg = $r.config
    $cfg | Add-Member -NotePropertyName server_url -NotePropertyValue $server -Force
    $cfgText = $cfg | ConvertTo-Json
    Say "      OK: terdaftar sebagai $($r.device.key) - $($r.device.name)" Green
}

if ($DryRun) {
    New-Item -ItemType Directory -Force $Target | Out-Null
    Write-Utf8 (Join-Path $Target 'config.json') $cfgText
    Say "DryRun: config.json ditulis ke $Target (Python, library dan tugas otomatis dilewati)." Cyan
    exit 0
}

# ---------- 2. python ----------
Say '[2/5] Memeriksa Python...'
$wheels = Join-Path $Here 'wheels'
$offline = Test-Path $wheels
# Versi + arsitektur sebuah python.exe. Ok = cocok dengan wheels offline (CPython 3.9 - 3.15, 64-bit).
function Test-Python($exe) {
    $ErrorActionPreference = 'Continue'
    $o = & $exe -c "import sys,struct; print('%d.%d %d' % (sys.version_info[0], sys.version_info[1], struct.calcsize('P')*8))" 2>$null
    if ($LASTEXITCODE -ne 0 -or -not $o) { return $null }
    $parts = ([string]$o).Trim().Split(' ')
    $ver = [version]$parts[0]; $bits = [int]$parts[1]
    $ok = (-not $offline) -or ($ver -ge [version]'3.9' -and $ver -le [version]'3.15' -and $bits -eq 64)
    return [pscustomobject]@{ Exe = $exe; Ver = $ver; Bits = $bits; Ok = $ok }
}
function Find-Pythons {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    $list = @()
    foreach ($c in @(Get-Command python.exe -All -ErrorAction SilentlyContinue)) {
        if ($c.Source -notmatch 'WindowsApps') { $list += $c.Source }   # lewati alias Microsoft Store
    }
    foreach ($p in @("$env:ProgramFiles\Python3*\python.exe", "$env:LOCALAPPDATA\Programs\Python\Python3*\python.exe")) {
        $list += @(Get-ChildItem $p -ErrorAction SilentlyContinue | Sort-Object FullName -Descending | ForEach-Object { $_.FullName })
    }
    $seen = @{}
    foreach ($e in $list) {
        $k = $e.ToLower()
        if (-not $seen.ContainsKey($k)) { $seen[$k] = 1; $t = Test-Python $e; if ($t) { $t } }
    }
}
function Pick-Python { @(Find-Pythons) | Where-Object { $_.Ok } | Select-Object -First 1 }

if ($Python) {
    $cand = Test-Python $Python
    if (-not $cand -or -not $cand.Ok) { Fail "Python $Python tidak bisa dipakai (perlu 3.9 - 3.15 64-bit)." }
} else { $cand = Pick-Python }
if (-not $cand) {
    $found = @(Find-Pythons)
    if ($found.Count) { Say ('      Python yang ada tidak cocok dengan library offline: ' + (($found | ForEach-Object { "$($_.Ver) $($_.Bits)-bit" }) -join ', ')) Yellow }
    $inst = Get-ChildItem $Here -Filter 'python-*.exe' -ErrorAction SilentlyContinue | Select-Object -First 1
    if (-not $inst) { Fail 'Python 3.9 - 3.15 (64-bit) belum ada dan file installer python-*.exe tidak ada di folder paket.' }
    Say "      Memasang $($inst.Name), mohon tunggu beberapa menit..."
    $p = Start-Process $inst.FullName -ArgumentList '/quiet', 'InstallAllUsers=1', 'PrependPath=1', 'Include_pip=1', 'Include_test=0', 'Include_launcher=1' -Wait -PassThru
    if ($p.ExitCode -ne 0) { Fail "Installer Python gagal (kode $($p.ExitCode))." }
    $cand = Pick-Python
    if (-not $cand) { Fail 'Python sudah dipasang tetapi python.exe yang cocok tidak ditemukan.' }
}
$py = $cand.Exe
$pyw = Join-Path (Split-Path $py) 'pythonw.exe'
if (-not (Test-Path $pyw)) { Fail "pythonw.exe tidak ditemukan di $(Split-Path $py)" }
Say "      Python: $py ($($cand.Ver), $($cand.Bits)-bit)" Green

# ---------- 3. salin file ----------
Say "[3/5] Menyalin file ke $Target ..."
try { Stop-ScheduledTask -TaskName $TaskName -ErrorAction Stop } catch {}
New-Item -ItemType Directory -Force $Target | Out-Null
foreach ($f in 'agent.py', 'requirements.txt', 'install_agent.bat', 'install_agent.ps1', 'uninstall_agent.bat', 'uninstall_agent.ps1', 'BACA-SAYA.txt') {
    $s = Join-Path $Here $f
    if ((Test-Path $s) -and ((Resolve-Path $s).Path -ne (Join-Path $Target $f))) { Copy-Item $s $Target -Force }
}
Write-Utf8 (Join-Path $Target 'config.json') $cfgText

# ---------- 4. library python ----------
Say '[4/5] Memasang library Python...'
$ErrorActionPreference = 'Continue'   # perintah python menulis ke stderr; yang dinilai exit code-nya
& $py -m pip --version *> $null
if ($LASTEXITCODE -ne 0) {
    Say '      pip belum ada, memasang pip bawaan Python (offline)...'
    & $py -m ensurepip --default-pip *> $null
}
$ErrorActionPreference = 'Stop'
$pipArgs = @('-m', 'pip', 'install', '--disable-pip-version-check', '--no-warn-script-location', '-r', (Join-Path $Target 'requirements.txt'))
if ($offline) {
    # Keamanan: setiap file di folder wheels harus terdaftar di SHA256SUMS.txt dengan hash yang sama.
    # File tambahan yang tidak terdaftar juga ditolak (pip memilih versi tertinggi di folder, jadi wheel sisipan berbahaya).
    $sumFile = Join-Path $wheels 'SHA256SUMS.txt'
    if (-not (Test-Path $sumFile)) { Fail 'SHA256SUMS.txt tidak ada di folder wheels; paket instalasi tidak lengkap.' }
    $sums = @{}
    foreach ($ln in Get-Content $sumFile) {
        if ($ln -match '^\s*([0-9a-fA-F]{64})\s+\*?(\S+)\s*$') { $sums[$Matches[2]] = $Matches[1].ToLower() }
    }
    $files = @(Get-ChildItem $wheels -File | Where-Object { $_.Name -ne 'SHA256SUMS.txt' })
    foreach ($f in $files) {
        if (-not $sums.ContainsKey($f.Name)) { Fail "File tidak dikenal di folder wheels: $($f.Name). Instalasi dihentikan demi keamanan." }
        if ((Get-FileHash $f.FullName -Algorithm SHA256).Hash.ToLower() -ne $sums[$f.Name]) { Fail "File library rusak atau berubah: $($f.Name). Unduh ulang paket instalasi." }
    }
    Say "      $($files.Count) file library terverifikasi SHA-256, dipasang offline (tanpa internet)." Green
    $pipArgs += @('--no-index', '--find-links', $wheels, '--only-binary=:all:')
} else {
    Say '      Folder wheels tidak ada di paket: library diunduh dari internet.' Yellow
}
$ErrorActionPreference = 'Continue'
& $py @pipArgs
$pipExit = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
if ($pipExit -ne 0) {
    if ($offline) { Fail "pip gagal memasang library dari folder wheels untuk Python $($cand.Ver) $($cand.Bits)-bit. Perbarui wheels di server dengan agent\buat-wheels.ps1." }
    Fail 'pip gagal memasang library. Pastikan ada internet, atau sertakan folder wheels di paket.'
}

if ($SkipTask) { Say '[5/5] Tugas otomatis dilewati (-SkipTask).' Yellow; Say 'Selesai.' Green; Pause-End; exit 0 }

# ---------- Wake-on-LAN (tidak menggagalkan instalasi) ----------
# Hanya mengubah: Fast Startup (dimatikan agar laptop bisa dibangunkan dari kondisi mati) dan pengaturan daya
# kartu jaringan FISIK (Wake on Magic Packet). Tidak membuka port / layanan apa pun. Lewati dengan -NoWoL.
if (-not $NoWoL) {
    Say '[+] Menyiapkan Wake-on-LAN...'
    $ErrorActionPreference = 'Continue'
    try {
        Set-ItemProperty 'HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\Power' -Name HiberbootEnabled -Value 0 -Type DWord -ErrorAction Stop
        Say '      Fast Startup: dimatikan' Green
    } catch { Say "      Fast Startup: gagal diubah ($($_.Exception.Message))" Yellow }
    foreach ($a in @(Get-NetAdapter -Physical -ErrorAction SilentlyContinue | Where-Object { $_.Name -notmatch 'Bluetooth' })) {
        $done = @()
        try { Set-NetAdapterPowerManagement -Name $a.Name -WakeOnMagicPacket Enabled -ErrorAction Stop; $done += 'Wake on Magic Packet' } catch {}
        foreach ($p in @(Get-NetAdapterAdvancedProperty -Name $a.Name -ErrorAction SilentlyContinue | Where-Object { $_.DisplayName -match 'Magic Packet|Shutdown Wake|Wake on LAN|WOL' })) {
            $val = $p.ValidDisplayValues | Where-Object { $_ -match '^(Enabled|On)' } | Select-Object -First 1
            if ($val -and $p.DisplayValue -ne $val) {
                try { Set-NetAdapterAdvancedProperty -Name $a.Name -DisplayName $p.DisplayName -DisplayValue $val -NoRestart -ErrorAction Stop; $done += $p.DisplayName } catch {}
            }
        }
        & powercfg /deviceenablewake "$($a.InterfaceDescription)" *> $null
        $res = if ($done) { ($done -join ', ') + ' aktif' } else { 'tidak didukung / sudah aktif' }
        Say ("      {0} ({1}): {2}" -f $a.Name, $a.InterfaceDescription, $res)
    }
    Say '      Wake on LAN juga harus diaktifkan di BIOS/UEFI laptop (manual, sekali). Berlaku penuh setelah restart.' Yellow
    $ErrorActionPreference = 'Stop'
}

# ---------- 5. tugas otomatis ----------
Say '[5/5] Membuat tugas otomatis saat login dan menjalankan agent...'
# jalankan sebagai user yang sedang login di layar (perlu untuk screenshot, pesan, dan membuka browser gate)
$runAs = (Get-CimInstance Win32_ComputerSystem).UserName
if (-not $runAs) { $runAs = "$env:USERDOMAIN\$env:USERNAME" }
$action = New-ScheduledTaskAction -Execute $pyw -Argument "`"$Target\agent.py`"" -WorkingDirectory $Target
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $runAs
$principal = New-ScheduledTaskPrincipal -UserId $runAs -LogonType Interactive -RunLevel Highest
# tetap jalan saat baterai, tanpa batas waktu, restart otomatis bila agent berhenti
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null
Start-ScheduledTask -TaskName $TaskName

Say ''
Say "Selesai. Agent terpasang di $Target dan berjalan otomatis setiap $runAs login." Green
Say 'Cek laptop ini di dashboard GateMon (biasanya online dalam beberapa detik).' Green
Pause-End
