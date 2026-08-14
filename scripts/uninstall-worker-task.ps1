[CmdletBinding()]
param(
    [string]$TaskName = 'ZapCore Baileys Worker'
)

$ErrorActionPreference = 'Stop'

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($null -eq $task) {
    Write-Output "Scheduled task '$TaskName' is not installed."
    exit 0
}

if ($task.State -eq 'Running') {
    Stop-ScheduledTask -TaskName $TaskName
}

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
Write-Output "Scheduled task '$TaskName' removed."
