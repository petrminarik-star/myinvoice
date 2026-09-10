<?php

declare(strict_types=1);

/**
 * MyInvoice.cz — worker pro přechod na MyÚčto.cz.
 *
 * Zazálohuje databázi, stáhne production bundle MyÚčta z GitHub release,
 * ověří SHA-256, nasadí ho přes běžící instalaci a dojede migrace. Pipeline
 * i bezpečnostní model popisuje {@see \MyInvoice\Service\Update\NativeUpdateService};
 * co se liší proti běžné aktualizaci, popisuje
 * {@see \MyInvoice\Service\Upgrade\MyuctoUpgradeService}.
 *
 * Spouští se detached z UI (Systém → Přechod na MyÚčto), ale jde ho zavolat
 * i ručně — je to normální CLI skript:
 *
 *   php api/bin/myucto-upgrade.php --target=5.15.0
 *   php api/bin/myucto-upgrade.php --target=5.15.0 --preflight   # jen kontrola
 *
 * Průběh se zapisuje do `storage/myucto-upgrade-requested.json` (krok +
 * heartbeat), výsledek do `storage/myucto-upgrade-result.json`, plný log do
 * `storage/myucto-upgrade-<timestamp>.log`. UI čte všechny tři.
 *
 * POZOR: tohle není aktualizace, ale výměna produktu za jeho nástupce. Nad
 * databází doběhne celá sada migrací MyÚčta a zpět už cesta nevede jinudy než
 * obnovou zálohy — tu si worker pořídí sám hned na začátku a při jejím selhání
 * skončí dřív, než cokoliv změní.
 */

require __DIR__ . '/../vendor/autoload.php';

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}
if (!defined('STDOUT')) {
    define('STDOUT', fopen('php://stdout', 'wb'));
}

use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\ReleaseChannel;

$target      = null;
$requestedBy = 'cli';
$preflight   = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--target=')) {
        $target = trim(substr($arg, strlen('--target=')));
    } elseif (str_starts_with($arg, '--requested-by=')) {
        $requestedBy = trim(substr($arg, strlen('--requested-by=')));
    } elseif ($arg === '--preflight') {
        $preflight = true;
    }
}

if ($target === null || $target === '') {
    fwrite(STDERR, "Použití: php api/bin/myucto-upgrade.php --target=X.Y.Z [--preflight]\n");
    exit(2);
}
if (!NativeUpdateService::isValidVersion($target)) {
    fwrite(STDERR, "Cílová verze musí být semver X.Y.Z, dostal jsem: {$target}\n");
    exit(2);
}

// Přechod běží dlouho (záloha + download + 10k souborů + stovky migrací)
// a nesmí ho zabít timeout ani odpojený klient.
@set_time_limit(0);
@ignore_user_abort(true);

$service = new NativeUpdateService(null, null, ReleaseChannel::myucto());

if ($preflight) {
    $result = $service->preflight($target);
    fwrite(STDOUT, ($result['ok'] ? "PREFLIGHT OK\n" : "PREFLIGHT BLOKOVÁN\n"));
    foreach ($result['blockers'] as $b) {
        fwrite(STDOUT, '  [blocker] ' . $b . "\n");
    }
    foreach ($result['warnings'] as $w) {
        fwrite(STDOUT, '  [warning] ' . $w . "\n");
    }
    exit($result['ok'] ? 0 : 1);
}

$result = $service->run($target, $requestedBy);

fwrite(STDOUT, strtoupper((string) ($result['status'] ?? 'unknown')) . ': ' . ($result['message'] ?? '') . "\n");

exit(($result['status'] ?? '') === 'applied' ? 0 : 1);
