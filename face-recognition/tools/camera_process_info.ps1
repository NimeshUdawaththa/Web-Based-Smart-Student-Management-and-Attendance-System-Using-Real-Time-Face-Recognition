# Read ProcessId, ExecutablePath, and CommandLine for a single integer PID.
param(
    [Parameter(Mandatory = $true)]
    [ValidateRange(1, 2147483647)]
    [int]$ProcessId
)

$ErrorActionPreference = 'Stop'
$proc = Get-CimInstance -ClassName Win32_Process -Filter "ProcessId=$ProcessId" -ErrorAction SilentlyContinue
if ($null -eq $proc) {
    Write-Output '{}'
    exit 0
}

$result = [ordered]@{
    ProcessId      = [int]$proc.ProcessId
    ExecutablePath = [string]$proc.ExecutablePath
    CommandLine    = [string]$proc.CommandLine
}
$result | ConvertTo-Json -Compress
exit 0
