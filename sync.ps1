# Build the remote CRM from the monorepo, copy deployable files here, commit and push.
# Usage:  .\sync.ps1 "commit message"      (add -SkipBuild to reuse the last build)
param(
    [string]$Message = "Deploy CRM $(Get-Date -Format 'yyyy-MM-dd HH:mm')",
    [switch]$SkipBuild,
    [switch]$NoPush
)

$ErrorActionPreference = 'Stop'
$Source = 'D:\Gate-Way-Guarantee'
$Deploy = $PSScriptRoot

if (-not $SkipBuild) {
    Push-Location "$Source\crm-web"
    try {
        npm run build:remote
        if ($LASTEXITCODE -ne 0) { throw "Frontend build failed" }
    } finally {
        Pop-Location
    }
}

function Sync-Dir([string]$From, [string]$To) {
    robocopy $From $To /MIR /NFL /NDL /NJH /NJS /NP /XF error_log | Out-Null
    if ($LASTEXITCODE -ge 8) { throw "robocopy failed: $From -> $To" }
}

robocopy "$Source\crm-php\host" $Deploy index.html .htaccess /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "robocopy failed: host root files" }
Sync-Dir "$Source\crm-php\host\assets" "$Deploy\assets"
Sync-Dir "$Source\crm-php\api" "$Deploy\api"
Sync-Dir "$Source\crm-php\cron" "$Deploy\cron"
Sync-Dir "$Source\crm-php\install" "$Deploy\install"
$global:LASTEXITCODE = 0

Push-Location $Deploy
try {
    git add -A
    git diff --cached --quiet
    if ($LASTEXITCODE -eq 0) {
        Write-Host "No changes to deploy."
        return
    }
    git commit -m $Message
    if (-not $NoPush) {
        $remote = git remote
        if ($remote) {
            git push
        } else {
            Write-Host "Committed. No git remote configured yet - see README.md."
        }
    }
} finally {
    Pop-Location
}
