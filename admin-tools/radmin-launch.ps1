# GateMon: dipanggil browser saat tombol "Remote via Radmin" diklik (link radmin://IP atau radmin://IP:PORT).
# Keamanan: alamat divalidasi ketat (hanya IPv4 / nama host + port) sebelum Radmin Viewer dijalankan,
# jadi link radmin:// dari halaman lain tidak bisa dipakai untuk menjalankan perintah apa pun.
# File ini sengaja hanya berisi karakter ASCII.
param([string]$Url = '', [switch]$DryRun)

function Msg($text) {
    if ($DryRun) { Write-Output "PESAN: $text"; return }
    Add-Type -AssemblyName PresentationFramework
    [System.Windows.MessageBox]::Show($text, 'GateMon - Remote via Radmin') | Out-Null
}

$addr = [uri]::UnescapeDataString(($Url -replace '^radmin:(//)?', '')).Trim().Trim('/')
if ($addr -notmatch '^(?<h>(\d{1,3}\.){3}\d{1,3}|[A-Za-z0-9]([A-Za-z0-9-]{0,62})(\.[A-Za-z0-9]([A-Za-z0-9-]{0,62}))*)(:(?<p>\d{1,5}))?$') {
    Msg "Alamat Radmin tidak valid: $addr"; exit 1
}
$hostName = $Matches['h']
$port = if ($Matches['p']) { [int]$Matches['p'] } else { 4899 }
if ($hostName -match '^\d+(\.\d+){3}$' -and @($hostName.Split('.') | Where-Object { [int]$_ -gt 255 }).Count) { Msg "Alamat IP tidak valid: $hostName"; exit 1 }
if ($port -lt 1 -or $port -gt 65535) { Msg "Port tidak valid: $port"; exit 1 }

$exe = @("${env:ProgramFiles(x86)}\Radmin Viewer 3\Radmin.exe", "$env:ProgramFiles\Radmin Viewer 3\Radmin.exe") |
    Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
if (-not $exe) { Msg 'Radmin Viewer 3 belum terpasang di laptop ini. Pasang dari radmin.com lalu coba lagi.'; exit 1 }

$arg = "/connect:${hostName}:$port"
if ($DryRun) { Write-Output "JALANKAN: `"$exe`" $arg"; exit 0 }
Start-Process -FilePath $exe -ArgumentList $arg
