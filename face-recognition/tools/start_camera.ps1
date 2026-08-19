# Starts the SmartAMS live-recognition process and prints the new PID.
# Paths are derived from this script location. No caller-supplied executables.

$ErrorActionPreference = 'Stop'

$toolsDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$faceRoot = Split-Path -Parent $toolsDir
$python = Join-Path $faceRoot 'venv\Scripts\python.exe'

if (-not (Test-Path -LiteralPath $python)) {
    [Console]::Error.WriteLine('Python venv not found')
    exit 1
}

$runtimeDir = Join-Path $faceRoot 'runtime'
if (-not (Test-Path -LiteralPath $runtimeDir)) {
    New-Item -ItemType Directory -Path $runtimeDir | Out-Null
}

$proc = Start-Process `
    -FilePath $python `
    -WorkingDirectory $faceRoot `
    -ArgumentList @('-m', 'recognition.live_recognition') `
    -PassThru `
    -WindowStyle Normal

if ($null -eq $proc -or $proc.Id -le 0) {
    [Console]::Error.WriteLine('Could not start recognition process')
    exit 1
}

Write-Output $proc.Id
exit 0
