[CmdletBinding()]
param(
    [string]$TaskName = 'ZapCore Baileys Worker',
    [switch]$StartNow
)

$ErrorActionPreference = 'Stop'

$workerRoot = Join-Path (Split-Path -Parent $PSScriptRoot) 'worker-baileys'

if (-not (Test-Path -LiteralPath (Join-Path $workerRoot 'dist\index.js'))) {
    Push-Location -LiteralPath $workerRoot
    try {
        & npm run build
        if ($LASTEXITCODE -ne 0) {
            throw 'Worker build failed.'
        }
    } finally {
        Pop-Location
    }
}

$powerShell = (Get-Command powershell.exe -ErrorAction Stop).Source
$runner = Join-Path $PSScriptRoot 'run-worker.ps1'
$action = New-ScheduledTaskAction `
    -Execute $powerShell `
    -Argument "-NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$runner`"" `
    -WorkingDirectory $workerRoot
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -MultipleInstances IgnoreNew
$principal = New-ScheduledTaskPrincipal `
    -UserId "$env:USERDOMAIN\$env:USERNAME" `
    -LogonType Interactive `
    -RunLevel Limited

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description 'Keeps the ZapCore Baileys worker running for the current user.' `
    -Force | Out-Null

if ($StartNow) {
    Start-ScheduledTask -TaskName $TaskName
}

Get-ScheduledTask -TaskName $TaskName | Select-Object TaskName, State
