# Stop one integer PID. The PHP caller must already have verified ownership.
param(
    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int]$ProcessId
)

$ErrorActionPreference = 'Stop'
$taskkill = Join-Path $env:SystemRoot 'System32\taskkill.exe'
if (-not (Test-Path -LiteralPath $taskkill)) {
    [Console]::Error.WriteLine('taskkill.exe not found')
    exit 1
}

& $taskkill /PID $ProcessId /F | Out-Null
exit $LASTEXITCODE
