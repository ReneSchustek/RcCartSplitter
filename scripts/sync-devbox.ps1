#!/usr/bin/env pwsh
# Synchronisiert RcCartSplitter zu den DevBox-Zielen plus Build-Schritte je Aenderungstyp.
# Manuelle tar-Pipeline ist in `RELEASE.md` Schritt 4 dokumentiert; dieses Skript automatisiert sie.

[CmdletBinding()]
param(
    [ValidateSet('plugins', 'live-clone', 'live-latest')]
    [string[]]$Targets = @('plugins', 'live-clone', 'live-latest'),

    [ValidateSet('cache', 'migration', 'scss', 'js', 'none')]
    [string]$Build = 'cache',

    [switch]$SkipPushCheck
)

$ErrorActionPreference = 'Stop'

$PluginName = 'RcCartSplitter'
$SshHost    = 'devbox'

$TargetMap = [ordered]@{
    'plugins'     = @{
        Path     = "/workspace/plugins/$PluginName"
        DdevPath = $null
    }
    'live-clone'  = @{
        Path     = "/workspace/shopware/instances/live-clone/custom/plugins/$PluginName"
        DdevPath = '/workspace/shopware/instances/live-clone'
    }
    'live-latest' = @{
        Path     = "/workspace/shopware/instances/live-latest/custom/plugins/$PluginName"
        DdevPath = '/workspace/shopware/instances/live-latest'
    }
}

# Build-Tabelle exakt nach BRIEF26 Aufgabe 3
$BuildSteps = @{
    'cache'     = @('php bin/console cache:clear')
    'migration' = @('php bin/console cache:clear', "php bin/console plugin:update $PluginName")
    'scss'      = @('php bin/console theme:compile')
    'js'        = @('bin/build-storefront.sh')
    'none'      = @()
}

function Write-Log {
    param(
        [Parameter(Mandatory)] [string]$Message,
        [ValidateSet('INFO', 'WARN', 'ERROR', 'CMD')] [string]$Level = 'INFO'
    )
    $stamp = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'
    $color = switch ($Level) {
        'WARN'  { 'Yellow' }
        'ERROR' { 'Red' }
        'CMD'   { 'DarkGray' }
        default { 'Gray' }
    }
    Write-Host "[$stamp] [$Level] $Message" -ForegroundColor $color
}

function Test-Preflight {
    Write-Log "Pre-Flight: SSH-Erreichbarkeit zu '$SshHost' pruefen."
    & ssh -o ConnectTimeout=5 -o BatchMode=yes $SshHost 'true' 2>$null
    if ($LASTEXITCODE -ne 0) {
        throw "SSH-Verbindung zu '$SshHost' nicht moeglich (Exit $LASTEXITCODE). Pruefe ~/.ssh/config und SSH-Agent."
    }
    Write-Log 'Pre-Flight: SSH-Verbindung OK.'

    if ($SkipPushCheck) {
        Write-Log 'Pre-Flight: Push-Check uebersprungen (-SkipPushCheck gesetzt).' -Level 'WARN'
        return
    }

    Write-Log 'Pre-Flight: lokales master gegen origin/master pruefen.'
    & git fetch origin master --quiet
    if ($LASTEXITCODE -ne 0) {
        throw "git fetch origin master fehlgeschlagen (Exit $LASTEXITCODE). DevBox-Bare-Repo nicht erreichbar?"
    }

    $unpushed = (& git rev-list --count 'origin/master..HEAD').Trim()
    if ($unpushed -ne '0') {
        throw "Lokales master ist $unpushed Commits ungepusht. Erst 'git push origin master' ausfuehren oder -SkipPushCheck nutzen."
    }
    $behind = (& git rev-list --count 'HEAD..origin/master').Trim()
    if ($behind -ne '0') {
        Write-Log "origin/master ist $behind Commits voraus — DevBox enthaelt fremden Stand. Sync ueberschreibt diesen mit dem lokalen Stand." -Level 'WARN'
    }
    Write-Log 'Pre-Flight: lokales master == origin/master.'
}

function Sync-Target {
    param(
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] [string]$RemotePath
    )
    Write-Log "Sync -> ${Name}: $RemotePath"

    $tarArgs = @(
        'cf', '-',
        '--exclude=.idea',
        '--exclude=node_modules',
        '--exclude=vendor',
        '--exclude=.git',
        '--exclude=var',
        '--exclude=.phpunit.cache',
        '--exclude=.phpunit.result.cache',
        '.'
    )

    Write-Log "tar $($tarArgs -join ' ') | ssh $SshHost 'cd $RemotePath && tar xf -'" -Level 'CMD'
    & tar @tarArgs | & ssh $SshHost "cd '$RemotePath' && tar xf -"
    if ($LASTEXITCODE -ne 0) {
        throw "Sync nach $Name fehlgeschlagen (Exit $LASTEXITCODE)."
    }
    Write-Log "Sync -> ${Name}: OK."
}

function Invoke-Build {
    param(
        [Parameter(Mandatory)] [string]$Name,
        [AllowNull()] [string]$DdevPath
    )

    if ($Build -eq 'none') {
        Write-Log "Build -> ${Name}: uebersprungen (Build=none)."
        return
    }
    if (-not $DdevPath) {
        Write-Log "Build -> ${Name}: kein DDEV-Pfad — uebersprungen (Build-Schritte gelten nur fuer Live-Instanzen)."
        return
    }

    foreach ($cmd in $BuildSteps[$Build]) {
        $remote = "cd '$DdevPath' && ddev exec $cmd"
        Write-Log "Build -> ${Name}: ssh $SshHost '$remote'" -Level 'CMD'
        & ssh $SshHost $remote
        if ($LASTEXITCODE -ne 0) {
            throw "Build-Schritt '$cmd' auf $Name fehlgeschlagen (Exit $LASTEXITCODE)."
        }
    }
    Write-Log "Build -> ${Name}: OK."
}

# Hauptablauf
Write-Log "Start: Targets=$($Targets -join ','), Build=$Build"

Test-Preflight

foreach ($name in $Targets) {
    $cfg = $TargetMap[$name]
    Sync-Target -Name $name -RemotePath $cfg.Path
    Invoke-Build -Name $name -DdevPath $cfg.DdevPath
}

Write-Log "Fertig: alle Targets synchronisiert, Build=$Build angewendet."
