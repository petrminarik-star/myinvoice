<?php

declare(strict_types=1);

/**
 * Automatická synchronizace bankovních pohybů z Wallet (BudgetBakers).
 *
 * Pro každého suppliera s nastaveným Wallet tokenem spustí inkrementální
 * import pohybů + párování plateb (WalletBankTransactionImporter). Supplier
 * bez tokenu se tiše přeskakuje; běžící UI job (import_jobs source='wallet')
 * daný supplier přeskočí, aby se import nepral sám se sebou. Duplicitám
 * navíc brání dedup přes source_ref.
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Import\WalletBankTransactionImporter;
use MyInvoice\Service\Import\WalletClient;
use MyInvoice\Service\Import\WalletSyncInProgressException;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-wallet-sync');

$container = Bootstrap::buildApp()->getContainer();
$wallet   = $container->get(WalletClient::class);
$importer = $container->get(WalletBankTransactionImporter::class);
$jobs     = $container->get(ImportJobRepository::class);

$supplierIds = array_map('intval', $conn->pdo()->query('SELECT id FROM supplier')->fetchAll(\PDO::FETCH_COLUMN));

$summary = ['suppliers' => 0, 'created' => 0, 'matched' => 0, 'paid_dates_fixed' => 0, 'errors' => 0, 'details' => []];

foreach ($supplierIds as $sid) {
    if ($sid <= 0 || $wallet->getToken($sid) === null) {
        continue;
    }
    $runningUiJob = false;
    foreach ($jobs->listForTenant($sid, 'wallet', limit: 3) as $job) {
        if (in_array($job['status'], ['queued', 'running'], true)) {
            $runningUiJob = true;
            break;
        }
    }
    if ($runningUiJob) {
        $summary['details'][] = "supplier {$sid}: přeskočen (běží UI import)";
        continue;
    }

    $summary['suppliers']++;
    try {
        $r = $importer->import($sid, dryRun: false, incremental: true);
        $summary['created'] += $r['created'];
        $summary['matched'] += $r['matched'];
        $summary['paid_dates_fixed'] += $r['paid_dates_fixed'];
        if ($r['created'] > 0 || $r['matched'] > 0) {
            $summary['details'][] = "supplier {$sid}: +{$r['created']} pohybů, {$r['matched']} spárováno, {$r['paid_dates_fixed']} oprav data úhrady";
        }
    } catch (WalletSyncInProgressException $e) {
        $summary['details'][] = "supplier {$sid}: Wallet ještě synchronizuje (409) — příští běh";
    } catch (\Throwable $e) {
        $summary['errors']++;
        $summary['details'][] = "supplier {$sid}: FAIL " . $e->getMessage();
    }
}

echo '[' . date('Y-m-d H:i:s') . '] wallet-sync: ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";

$conn->pdo()->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.wallet_sync', ?)"
)->execute([json_encode($summary, JSON_UNESCAPED_UNICODE)]);

$run->finish($summary['errors'] > 0 ? 'error' : 'ok', $summary, $summary['errors'] > 0 ? 'Některé Wallet synchronizace selhaly.' : null);
exit($summary['errors'] === 0 ? 0 : 1);
