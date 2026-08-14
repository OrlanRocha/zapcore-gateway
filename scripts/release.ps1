[CmdletBinding(DefaultParameterSetName = 'Bump')]
param(
    [Parameter(Mandatory, ParameterSetName = 'Bump')]
    [ValidateSet('major', 'minor', 'patch')]
    [string]$Bump,

    [Parameter(Mandatory, ParameterSetName = 'Version')]
    [ValidatePattern('^\d+\.\d+\.\d+$')]
    [string]$Version,

    [switch]$NoCommit
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$versionFile = Join-Path $root 'VERSION'
$changelogFile = Join-Path $root 'CHANGELOG.md'
$workerRoot = Join-Path $root 'worker-baileys'

function Invoke-Checked {
    param([string]$Command, [string[]]$Arguments, [string]$WorkingDirectory = $root)

    Push-Location -LiteralPath $WorkingDirectory
    try {
        & $Command @Arguments
        if ($LASTEXITCODE -ne 0) {
            throw "$Command failed with exit code $LASTEXITCODE."
        }
    } finally {
        Pop-Location
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $root '.git'))) {
    throw 'Release must run inside the ZapCore Git repository.'
}

$dirty = (& git -C $root status --porcelain) -join "`n"
if ($LASTEXITCODE -ne 0) {
    throw 'Unable to inspect Git status.'
}
if ($dirty) {
    throw 'Git working tree must be clean before creating a release.'
}

$currentText = (Get-Content -LiteralPath $versionFile -Raw).Trim()
if ($currentText -notmatch '^(\d+)\.(\d+)\.(\d+)$') {
    throw "Invalid current version in VERSION: $currentText"
}

$current = [version]$currentText
if ($PSCmdlet.ParameterSetName -eq 'Bump') {
    switch ($Bump) {
        'major' { $next = [version]::new($current.Major + 1, 0, 0) }
        'minor' { $next = [version]::new($current.Major, $current.Minor + 1, 0) }
        'patch' { $next = [version]::new($current.Major, $current.Minor, $current.Build + 1) }
    }
    $nextText = $next.ToString(3)
} else {
    $next = [version]$Version
    $nextText = $Version
}

if ($next -le $current) {
    throw "New version $nextText must be greater than current version $currentText."
}

& git -C $root rev-parse --verify --quiet "refs/tags/v$nextText" | Out-Null
if ($LASTEXITCODE -eq 0) {
    throw "Tag v$nextText already exists."
}

Write-Host "Validating release v$nextText..."
$phpFiles = Get-ChildItem -Path (Join-Path $root 'backend-php\app'), (Join-Path $root 'backend-php\public') -Recurse -Filter '*.php'
foreach ($file in $phpFiles) {
    & php -l $file.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint failed: $($file.FullName)"
    }
}
Invoke-Checked -Command 'npm.cmd' -Arguments @('run', 'build') -WorkingDirectory $workerRoot

[System.IO.File]::WriteAllText($versionFile, "$nextText`n", [System.Text.UTF8Encoding]::new($false))
Invoke-Checked -Command 'npm.cmd' -Arguments @('version', $nextText, '--no-git-tag-version', '--allow-same-version') -WorkingDirectory $workerRoot

$changelog = Get-Content -LiteralPath $changelogFile -Raw
if ($changelog -notmatch '(?m)^## \[Unreleased\]\s*$') {
    throw 'CHANGELOG.md must contain an [Unreleased] section.'
}
$releaseHeading = "## [Unreleased]`r`n`r`n## [$nextText] - $(Get-Date -Format 'yyyy-MM-dd')"
$unreleasedPattern = [regex]::new('^## \[Unreleased\]\s*$', [System.Text.RegularExpressions.RegexOptions]::Multiline)
$changelog = $unreleasedPattern.Replace($changelog, $releaseHeading, 1)
[System.IO.File]::WriteAllText($changelogFile, $changelog, [System.Text.UTF8Encoding]::new($false))

if ($NoCommit) {
    Write-Host "Release v$nextText prepared without commit or tag."
    exit 0
}

Invoke-Checked -Command 'git' -Arguments @('add', 'VERSION', 'CHANGELOG.md', 'worker-baileys/package.json', 'worker-baileys/package-lock.json')
Invoke-Checked -Command 'git' -Arguments @('commit', '-m', "chore(release): v$nextText")
Invoke-Checked -Command 'git' -Arguments @('tag', '-a', "v$nextText", '-m', "ZapCore Gateway v$nextText")

Write-Host "Release v$nextText created. Review it before pushing."
