# Membuat ulang folder agent\installer\wheels (library agent untuk instalasi OFFLINE) + SHA256SUMS.txt.
# Jalankan di laptop server yang ada internet dan Python 3 (versi apa saja), mis.:
#   powershell -ExecutionPolicy Bypass -File buat-wheels.ps1
#   powershell -ExecutionPolicy Bypass -File buat-wheels.ps1 -Python "C:\Python312\python.exe"
#   powershell -ExecutionPolicy Bypass -File buat-wheels.ps1 -HashOnly    (hanya hitung ulang SHA256SUMS.txt)
# Wheels diunduh dari PyPI (HTTPS, hash diverifikasi pip) untuk CPython 3.9 - 3.15 Windows 64-bit,
# sama dengan rentang yang diterima install_agent.ps1.
param([string]$Python = 'python', [switch]$HashOnly)
$ErrorActionPreference = 'Stop'
$Here = Split-Path -Parent $MyInvocation.MyCommand.Path
$Wheels = Join-Path $Here 'installer\wheels'
$Req = Join-Path $Here 'requirements.txt'

if (-not $HashOnly) {
    if (Test-Path $Wheels) { Remove-Item $Wheels -Recurse -Force }
    New-Item -ItemType Directory -Force $Wheels | Out-Null
    foreach ($v in '3.9', '3.10', '3.11', '3.12', '3.13', '3.14', '3.15') {
        Write-Host "Mengunduh library untuk Python $v (64-bit)..."
        $ErrorActionPreference = 'Continue'
        & $Python -m pip download --disable-pip-version-check -q -r $Req --only-binary=:all: --platform win_amd64 --python-version $v --implementation cp -d $Wheels
        $code = $LASTEXITCODE
        $ErrorActionPreference = 'Stop'
        if ($code -ne 0) { Write-Host "  GAGAL untuk Python $v" -ForegroundColor Red; exit 1 }
    }
}

# Manifest integritas: diperiksa install_agent.ps1 sebelum memasang (file tak terdaftar / beda hash = ditolak)
$lines = Get-ChildItem $Wheels -File -Filter '*.whl' | Sort-Object Name | ForEach-Object {
    (Get-FileHash $_.FullName -Algorithm SHA256).Hash.ToLower() + '  ' + $_.Name
}
[IO.File]::WriteAllText((Join-Path $Wheels 'SHA256SUMS.txt'), (($lines -join "`r`n") + "`r`n"), (New-Object Text.UTF8Encoding($false)))
$mb = (Get-ChildItem $Wheels -File | Measure-Object Length -Sum).Sum / 1MB
Write-Host ("Selesai: {0} wheel, {1:N1} MB, SHA256SUMS.txt diperbarui di {2}" -f $lines.Count, $mb, $Wheels) -ForegroundColor Green
