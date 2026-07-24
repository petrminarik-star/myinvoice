<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ImportJobRepository;
use Psr\Log\LoggerInterface;

/**
 * Wallet (BudgetBakers) import — orchestrace jobu (worker), zrcadlí
 * IdokladImportService v zúženém rozsahu: synchronizace bankovních účtů
 * (mapování Wallet účet → lokální účet) + import bankovních pohybů.
 * Doklady Wallet nemá — jen banka.
 */
final class WalletImportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly WalletClient $wallet,
        private readonly WalletBankTransactionImporter $bankTransactions,
        private readonly ImportJobRepository $jobs,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string,mixed>|null $paramsOverride  pro přímé volání (cron) bez job řádku
     */
    public function run(int $jobId): void
    {
        $job = $this->loadJob($jobId);
        if (!$this->jobs->markRunning($jobId)) {
            return;
        }
        try {
            $params = $job['params'] ?? [];
            $supplierId = (int) $job['supplier_id'];
            $dryRun = !empty($params['dry_run']);
            $incremental = !empty($params['incremental']);

            $this->jobs->appendLog($jobId, 'Wallet import zahájen' . ($dryRun ? ' (dry-run)' : '') . ($incremental ? ', inkrementální' : '') . '.');

            if (!empty($params['include_bank_accounts']) || ($params['include_bank_accounts'] ?? null) === null) {
                $accounts = $this->syncBankAccounts($supplierId, $dryRun);
                $this->jobs->appendLog($jobId, sprintf(
                    'Wallet účty: spárováno %d, bez shody %d, přeskočeno bez čísla účtu %d.%s',
                    $accounts['matched'], $accounts['unmatched'], $accounts['no_number'], $dryRun ? ' (dry-run)' : ''
                ));
            }

            if (!empty($params['include_bank_transactions']) || ($params['include_bank_transactions'] ?? null) === null) {
                $this->jobs->appendLog($jobId, 'Synchronizuji bankovní pohyby z Walletu…');
                $bank = $this->bankTransactions->import($supplierId, $dryRun, $incremental);
                $this->jobs->appendLog($jobId, sprintf(
                    'Bankovní pohyby: vytvořeno %d, spárováno %d, opravených dat úhrady %d, přeskočeno %d, mimo mapované účty %d.%s',
                    $bank['created'], $bank['matched'], $bank['paid_dates_fixed'], $bank['skipped'], $bank['unmapped'], $dryRun ? ' (dry-run)' : ''
                ));
            }

            $this->jobs->appendLog($jobId, 'Wallet import dokončen.');
            $this->jobs->markCompleted($jobId);
        } catch (WalletSyncInProgressException $e) {
            $this->jobs->appendLog($jobId, $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Wallet import failed', ['job_id' => $jobId, 'error' => $e->getMessage()]);
            $this->jobs->appendLog($jobId, 'FAIL: ' . $e->getMessage());
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /**
     * Mapování Wallet účtů na lokální bankovní účty (currencies) — upsert do
     * external_bank_account_mappings (provider='wallet'). Účty bez čísla
     * (hotovost) se přeskakují. Nikdy nezakládá ani nepřepisuje lokální účty.
     *
     * @return array{matched:int,unmatched:int,ambiguous:int,no_number:int}
     */
    public function syncBankAccounts(int $supplierId, bool $dryRun): array
    {
        $pdo = $this->db->pdo();
        $upsert = $pdo->prepare(
            "INSERT INTO external_bank_account_mappings
                (supplier_id, provider, external_account_id, currency_id, external_currency_id,
                 external_bank_id, account_number, iban, name, is_default, sync_status, synced_at)
             VALUES (?, 'wallet', ?, ?, ?, NULL, ?, NULL, ?, 0, ?, NOW())
             ON DUPLICATE KEY UPDATE
                currency_id = VALUES(currency_id), external_currency_id = VALUES(external_currency_id),
                account_number = VALUES(account_number), name = VALUES(name),
                sync_status = VALUES(sync_status), synced_at = NOW()"
        );

        $matched = 0; $unmatched = 0; $ambiguous = 0; $noNumber = 0;
        foreach ($this->wallet->getAccounts($supplierId) as $external) {
            $uuid = trim((string) ($external['id'] ?? ''));
            if ($uuid === '') {
                continue;
            }
            $number = trim((string) ($external['bankAccountNumber'] ?? ''));
            if ($number === '' || !empty($external['archived'])) {
                $noNumber++;
                continue;
            }
            $code = strtoupper((string) (WalletBankTransactionImporter::walletCurrencyCode($external) ?? 'CZK'));
            $stmt = $pdo->prepare(
                'SELECT id, account_number, bank_code, iban FROM currencies
                  WHERE supplier_id = ? AND code = ? AND is_active = 1 ORDER BY id'
            );
            $stmt->execute([$supplierId, $code]);
            // Wallet nedává IBAN ani kód banky — match jen podle čísla účtu.
            $selection = IdokladImportService::matchExternalBankAccount(
                ['AccountNumber' => $number, 'Iban' => ''],
                $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [],
            );
            if ($selection['status'] === 'matched') $matched++;
            elseif ($selection['status'] === 'ambiguous') $ambiguous++;
            else $unmatched++;
            if ($dryRun) {
                continue;
            }
            $upsert->execute([
                $supplierId, $uuid, $selection['currency_id'], $code,
                $number, mb_substr((string) ($external['name'] ?? ''), 0, 120) ?: null,
                $selection['status'],
            ]);
        }
        return ['matched' => $matched, 'unmatched' => $unmatched, 'ambiguous' => $ambiguous, 'no_number' => $noNumber];
    }

    private function loadJob(int $jobId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM import_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Import job #{$jobId} nenalezen.");
        }
        if (!empty($row['params'])) {
            $row['params'] = json_decode((string) $row['params'], true);
        }
        return $row;
    }
}
