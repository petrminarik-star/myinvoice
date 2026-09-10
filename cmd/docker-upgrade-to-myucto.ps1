<#
.SYNOPSIS
Přechod běžící MyInvoice.cz Docker instalace na MyÚčto.cz (Windows host).

.DESCRIPTION
Port `docker-upgrade-to-myucto.sh` pro hosty bez bashe. Dělá totéž a ve
stejném pořadí — kdyby se ty dva soubory rozešly, je to chyba, ne volba:

  0. ověří požadavky MyÚčta (MariaDB >= 11.8; PHP a rozšíření si nástupce
     přiveze ve vlastním image, ta půlka je z definice splněná)
  1. zazálohuje databázi uvnitř kontejneru (bez zálohy skript nepokračuje)
  2. zastaví app kontejner (db běží dál)
  3. přepne image myinvoice → myucto v .env a compose souborech
  4. stáhne nový image
  5. nastartuje app; migrace dojede docker-entrypoint.sh sám
  6. počká, až aplikace odpoví
  7. vypne účetnictví — instalace přišla z MyInvoice, kde se neúčtovalo

MyÚčto je nástupce MyInvoice od téhož autora, postavený na stejném základu.
Jeho migrace 1000+ navazují na schéma MyInvoice, takže přechod je in-place:
vymění se image a nad STÁVAJÍCÍ databází se dojedou zbylé migrace. Data se
nikam nekopírují a volume `/data` (uploady, PDF, cfg.local.php) zůstává beze
změny — layout je u obou stejný.

POZOR: tohle NENÍ aktualizace, ale výměna produktu. Zpátky vede jedině obnova
zálohy z kroku 1 — návrat image samotný nestačí, protože schéma už bude mít
migrace MyÚčta.

.PARAMETER Yes
Přeskočí potvrzení.

.PARAMETER Tag
Verze MyÚčta místo `latest`, např. `5.17.0`.

.EXAMPLE
pwsh cmd/docker-upgrade-to-myucto.ps1
pwsh cmd/docker-upgrade-to-myucto.ps1 -Yes -Tag 5.17.0
#>
[CmdletBinding()]
param(
    [switch] $Yes,
    [string] $Tag = 'latest'
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$MinDbVersion = [version] '11.8'

$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location $projectRoot

function Fail([string] $message) {
    Write-Error $message
    exit 1
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Fail 'docker není v PATH'
}
# Úspěch nativního příkazu se na Windows pozná z $LASTEXITCODE, ne z $?.
docker compose version *> $null
if ($LASTEXITCODE -ne 0) { Fail "je potřeba plugin 'docker compose' (v2)" }
if (-not (Test-Path '.env')) { Fail '.env nenalezen — tohle není adresář s instalací' }

# Preferuj production compose, pokud nad ním stack opravdu běží.
$composeArgs = @()
if (Test-Path 'docker-compose.production.yml') {
    $running = docker compose -f docker-compose.production.yml ps --status running -q app 2>$null
    if ($running) { $composeArgs = @('-f', 'docker-compose.production.yml') }
}
function dc { docker compose @composeArgs @args }

# Které soubory drží jméno image. Měníme jen ty, co ho opravdu obsahují,
# ať se needitují nesouvisející compose soubory.
$targetFiles = @(
    '.env', 'docker-compose.yml', 'docker-compose.production.yml', 'docker-compose.portainer.yml'
) | Where-Object { (Test-Path $_) -and (Select-String -Path $_ -Pattern 'radekhulan/myinvoice' -Quiet) }

if ($targetFiles.Count -eq 0) {
    Write-Host "CHYBA: v .env ani compose souborech není image 'radekhulan/myinvoice'." -ForegroundColor Red
    Write-Host '       Buď už na MyÚčtu jsi, nebo stavíš image lokálně ze zdrojů'
    Write-Host "       (pak přejdi klonem repa myucto a 'docker compose up -d --build')."
    exit 1
}

Write-Host '============================================================'
Write-Host ' Přechod MyInvoice.cz → MyÚčto.cz'
Write-Host ''
Write-Host " Změním image na ghcr.io/radekhulan/myucto:$Tag v:"
$targetFiles | ForEach-Object { Write-Host "   - $_" }
Write-Host ''
Write-Host ' Databáze SE NEMĚNÍ — dojedou nad ní migrace MyÚčta.'
Write-Host ' Volume s daty zůstávají. Zpátky vede jen obnova zálohy.'
Write-Host '============================================================'
Write-Host ''

# --- 0. požadavky nástupce ------------------------------------------------
# V Dockeru je databáze JEDINÁ sdílená součást: PHP i rozšíření si nástupce
# přiveze ve vlastním image, ale db kontejner zůstává ten původní. Instalace
# založená na plovoucím tagu `mariadb:11` může běžet na čemkoli z řady 11.x
# a `docker compose up` na už stažený image nesáhne, takže se stará verze
# udrží roky. Migrace MyÚčta ale chtějí 11.8.
Write-Host '==> Ověřuji požadavky MyÚčta…'
$dbRaw = dc exec -T db mariadb --version 2>$null
if (-not $dbRaw) {
    Fail "nepodařilo se zjistit verzi databáze (kontejner 'db' neodpovídá). MyÚčto vyžaduje MariaDB $MinDbVersion nebo novější."
}
if ($dbRaw -notmatch 'mariadb') {
    Fail "databáze není MariaDB. MyÚčto běží jen na MariaDB $MinDbVersion a novější. Naměřeno: $dbRaw"
}
if ($dbRaw -notmatch '(\d+\.\d+\.\d+)') {
    Fail "z výstupu se nepodařilo přečíst verzi databáze: $dbRaw"
}
$dbVersion = [version] $Matches[1]
if ($dbVersion -lt $MinDbVersion) {
    Write-Host ''
    Write-Host "CHYBA: MyÚčto vyžaduje MariaDB $MinDbVersion nebo novější, tenhle stack má $dbVersion." -ForegroundColor Red
    Write-Host '       Přechod NEspouštím — instalace zůstává beze změny.'
    Write-Host ''
    Write-Host '       Databázi povyš zvlášť, PŘED přechodem (dvě nevratné operace naráz se nedělají):'
    Write-Host '         1) docker compose exec -T app php api/bin/cron-backup.php     # záloha'
    Write-Host "         2) v compose souboru přepiš image db na mariadb:$MinDbVersion"
    Write-Host '         3) docker compose pull db && docker compose up -d db'
    Write-Host '         4) ověř, že aplikace funguje, a spusť tenhle skript znovu'
    exit 1
}
Write-Host "    MariaDB $dbVersion (>= $MinDbVersion) OK. PHP a rozšíření si MyÚčto přiveze ve vlastním image."
Write-Host ''

if (-not $Yes) {
    $answer = Read-Host 'Pokračovat? [napiš ANO]'
    if ($answer -ne 'ANO') { Write-Host 'Zrušeno.'; exit 0 }
}

# --- 1. záloha databáze ---------------------------------------------------
# Fatální krok: nevratnou migraci schématu bez zálohy nepouštíme.
Write-Host '==> Zálohuji databázi…'
dc exec -T app php api/bin/cron-backup.php
if ($LASTEXITCODE -ne 0) {
    Fail 'záloha databáze selhala — přechod NEspouštím. Instalace zůstává beze změny. Zazálohuj ručně a spusť znovu.'
}
Write-Host '    Záloha hotova (storage/backup/ ve volume app-data).'

# --- 2. zastavit app ------------------------------------------------------
Write-Host '==> Zastavuji app kontejner (db běží dál)…'
dc stop app

# --- 3. přepnout image ----------------------------------------------------
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
Write-Host "==> Přepínám image na MyÚčto (zálohy souborů: *.bak-$stamp)…"
foreach ($f in $targetFiles) {
    Copy-Item $f "$f.bak-$stamp"
    $content = (Get-Content $f -Raw) -replace 'radekhulan/myinvoice', 'radekhulan/myucto'
    if ($Tag -ne 'latest') {
        $content = $content -replace '(ghcr\.io/radekhulan/myucto):[A-Za-z0-9._-]*', "`$1:$Tag"
    }
    # Bez BOM: docker compose i sh čtou tyhle soubory a BOM jim vadí.
    [System.IO.File]::WriteAllText((Resolve-Path $f), $content, (New-Object System.Text.UTF8Encoding $false))
    Write-Host "    $f"
}

function Restore-Files {
    foreach ($f in $targetFiles) {
        if (Test-Path "$f.bak-$stamp") { Move-Item "$f.bak-$stamp" $f -Force }
    }
}

# --- 4. stáhnout image ----------------------------------------------------
Write-Host '==> Stahuji image MyÚčta…'
dc pull app
if ($LASTEXITCODE -ne 0) {
    Write-Host 'CHYBA: image se nepodařilo stáhnout — vracím soubory zpět.' -ForegroundColor Red
    Restore-Files
    dc up -d app
    exit 1
}

# --- 5. start (entrypoint dojede migrace) ---------------------------------
Write-Host '==> Startuji MyÚčto; migrace dojede entrypoint…'
dc up -d app

# --- 6. health check ------------------------------------------------------
$appPort = '8080'
$envPort = Select-String -Path '.env' -Pattern '^APP_PORT=(.*)$' | Select-Object -First 1
if ($envPort) { $appPort = $envPort.Matches[0].Groups[1].Value.Trim() }

Write-Host '==> Čekám, až aplikace odpoví (migrací je hodně, může to chvíli trvat)…'
$ok = $false
foreach ($i in 1..150) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:$appPort/api/health" -TimeoutSec 5 -UseBasicParsing
        if ($r.StatusCode -eq 200) { $ok = $true; break }
    } catch {
        # Kontejner ještě migruje nebo nenaběhl — zkoušíme dál.
    }
    Start-Sleep -Seconds 2
}

if (-not $ok) {
    Write-Host ''
    Write-Host 'CHYBA: aplikace neodpověděla do 5 minut.' -ForegroundColor Red
    Write-Host "       Projdi 'docker compose logs app' — migrace mohly selhat."
    Write-Host "       Soubory s původním image jsou v *.bak-$stamp."
    Write-Host '       POZOR: návrat na starý image sám nestačí, pokud už migrace'
    Write-Host '       proběhly — obnov databázi ze zálohy z kroku 1.'
    exit 1
}

# --- 7. předání nástupci --------------------------------------------------
# MyÚčto zavádí přepínač „Vést účetnictví" s defaultem zapnuto. To je správně
# pro firmu, která v MyÚčtu účtuje, ale ne pro instalaci, která právě přišla
# z MyInvoice a účetnictví nikdy nevedla: ta by dostala plné účetní menu a
# k tomu režim „daňová evidence", tedy default sloupce — u s.r.o. rovnou
# špatně, protože ta vede podvojné účetnictví ze zákona.
#
# Nativní přechod tohle řeší uvnitř aplikace, jenže tudy se nejde: v Dockeru
# se jen vymění image a migrace dojede entrypoint.
#
# Selhání nesmí shodit přechod: image i schéma jsou v pořádku a aplikace běží.
Write-Host '==> Vypínám účetnictví (instalace přišla z MyInvoice, kde se neúčtovalo)…'
$sql = 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "UPDATE supplier SET accounting_enabled = 0 WHERE accounting_enabled = 1"'
dc exec -T db sh -c $sql 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host '    Hotovo. Zapíná se v Nastavení → Licenční moduly spolu s volbou režimu.'
} else {
    Write-Host '    VAROVÁNÍ: nepodařilo se vypnout účetnictví — udělej to v Nastavení →' -ForegroundColor Yellow
    Write-Host '              Licenční moduly, nebo zkontroluj, že heslo v .env sedí.' -ForegroundColor Yellow
}

Write-Host ''
Write-Host '============================================================'
Write-Host " Hotovo — běží MyÚčto.cz na http://localhost:$appPort"
Write-Host ''
Write-Host ' Logy:            docker compose logs -f app'
Write-Host " Původní compose: *.bak-$stamp (po ověření smaž)"
Write-Host '============================================================'
