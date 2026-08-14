$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$workerRoot = Join-Path $projectRoot 'worker-baileys'
$entrypoint = Join-Path $workerRoot 'dist\index.js'
$node = Join-Path $env:ProgramFiles 'nodejs\node.exe'
$workerPort = 3333
$envFile = Join-Path $workerRoot '.env'

if (Test-Path -LiteralPath $envFile) {
    $envContent = Get-Content -LiteralPath $envFile -Raw
    if ($envContent -match '(?m)^WORKER_PORT\s*=\s*(\d+)\s*$') {
        $workerPort = [int]$Matches[1]
    }
}

if (-not (Test-Path -LiteralPath $node)) {
    $node = (Get-Command node.exe -ErrorAction Stop).Source
}

if (-not (Test-Path -LiteralPath $entrypoint)) {
    throw "Worker build not found at $entrypoint. Run npm run build first."
}

Set-Location -LiteralPath $workerRoot

while ($true) {
    while (Get-NetTCPConnection -LocalPort $workerPort -State Listen -ErrorAction SilentlyContinue) {
        Start-Sleep -Seconds 5
    }

    & $node $entrypoint
    Start-Sleep -Seconds 5
}
