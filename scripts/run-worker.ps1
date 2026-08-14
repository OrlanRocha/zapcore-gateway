$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$workerRoot = Join-Path $projectRoot 'worker-baileys'
$entrypoint = Join-Path $workerRoot 'dist\index.js'
$node = Join-Path $env:ProgramFiles 'nodejs\node.exe'

if (-not (Test-Path -LiteralPath $node)) {
    $node = (Get-Command node.exe -ErrorAction Stop).Source
}

if (-not (Test-Path -LiteralPath $entrypoint)) {
    throw "Worker build not found at $entrypoint. Run npm run build first."
}

Set-Location -LiteralPath $workerRoot

while ($true) {
    & $node $entrypoint
    Start-Sleep -Seconds 5
}
