# Läuft mit Adminrechten und steuert USBPcapCMD über Dateien in captures\ctl:
#   start.txt  (Inhalt = Dateiname ohne Endung) -> Mitschnitt starten
#   stop.txt                                    -> laufenden Mitschnitt beenden
#   quit.txt                                    -> Dienst beenden
# Status steht in captures\ctl\status.txt
$root = Split-Path -Parent $PSScriptRoot
$ctl = Join-Path $root 'captures\ctl'
$out = Join-Path $root 'captures'
$exe = 'C:\Program Files\USBPcap\USBPcapCMD.exe'
New-Item -ItemType Directory -Force $ctl | Out-Null
$proc = $null
function Set-Status($s) { Set-Content -Path (Join-Path $ctl 'status.txt') -Value $s -Encoding utf8 }
function Stop-Capture {
    if ($script:proc -and -not $script:proc.HasExited) {
        Stop-Process -Id $script:proc.Id -Force
        $script:proc.WaitForExit(3000) | Out-Null
    }
    $script:proc = $null
}
Set-Status 'idle'
while ($true) {
    $start = Join-Path $ctl 'start.txt'
    if (Test-Path $start) {
        $name = (Get-Content $start -Raw).Trim()
        Remove-Item $start
        Stop-Capture
        $file = Join-Path $out "$name.pcap"
        $script:proc = Start-Process -FilePath $exe -PassThru -WindowStyle Hidden `
            -ArgumentList '-d', '\\.\USBPcap2', '--devices', '3', '--inject-descriptors', '-o', "`"$file`""
        Set-Status "recording $name"
    }
    if (Test-Path (Join-Path $ctl 'stop.txt')) {
        Remove-Item (Join-Path $ctl 'stop.txt')
        Stop-Capture
        Set-Status 'idle'
    }
    if (Test-Path (Join-Path $ctl 'quit.txt')) {
        Remove-Item (Join-Path $ctl 'quit.txt')
        Stop-Capture
        Set-Status 'stopped'
        break
    }
    Start-Sleep -Milliseconds 200
}
