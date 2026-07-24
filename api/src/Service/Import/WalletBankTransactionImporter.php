<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Bank\EmailNoticeReconciler;
use MyInvoice\Service\Bank\StatementMatcher;
use PDO;

/**
 * Import bankovních pohybů z Wallet (BudgetBakers) jako virtuální výpis
 * source='wallet' — zrcadlí IdokladBankTransactionImporter.
 *
 * Specifika Walletu oproti iDokladu:
 *   - record NEMÁ pole pro variabilní symbol — VS se vytahuje regexem z `note`
 *     (zpráva pro příjemce; klienti do ní číslo faktury typicky píšou),
 *     viz variableSymbolFromNote(). Špatně vytažený kandidát je neškodný —
 *     matcher vyžaduje existenci faktury s daným číslem + sedící částku.
 *   - `counterParty` nese číslo protiúčtu, volitelně s předřazeným jménem
 *     („Kašík, František, 2201230338/2010" i „3149505123/0800"), u karetních
 *     plateb jen text obchodníka — viz parseCounterParty().
 *   - record id je UUID (neuspořádané) → inkrementální kurzor je datumový:
 *     supplier.wallet_last_synced_at minus překryv (bankovní sync Walletu má
 *     zpoždění a pohyby dosynchronizovává zpětně); duplicitám brání dedup
 *     přes source_ref.
 *   - platba navázaná na už zaplacenou fakturu opraví datum úhrady podle
 *     skutečného data z banky (fixPaidDate) — ruční „Zaplaceno" mívá datum
 *     kliknutí, ne platby. Nikdy nesahá na úhrady držené bankovní platbou.
 */
final class WalletBankTransactionImporter
{
    /** Překryv inkrementálního okna (dny) — Wallet dosynchronizovává zpětně. */
    private const INCREMENTAL_OVERLAP_DAYS = 14;
    /** Hloubka plného importu (první běh / bez kurzoru). */
    private const FULL_WINDOW = '-12 months';

    public function __construct(
        private readonly Connection $db,
        private readonly WalletClient $wallet,
        private readonly StatementMatcher $matcher,
        private readonly EmailNoticeReconciler $reconciler,
    ) {}

    /** @return array{created:int,skipped:int,matched:int,unmapped:int,paid_dates_fixed:int} */
    public function import(int $supplierId, bool $dryRun, bool $incremental = false): array
    {
        $result = ['created' => 0, 'skipped' => 0, 'matched' => 0, 'unmapped' => 0, 'paid_dates_fixed' => 0];
        $pdo = $this->db->pdo();
        $accounts = $this->mappedAccounts($pdo, $supplierId, $dryRun);
        $since = $this->sinceDate($pdo, $supplierId, $incremental);

        foreach ($this->wallet->getRecords($supplierId, $since) as $record) {
            $externalId = trim((string) ($record['id'] ?? ''));
            $accountUuid = trim((string) ($record['accountId'] ?? ''));
            if ($externalId === '' || $accountUuid === '') {
                $result['skipped']++;
                continue;
            }

            $sourceRef = $supplierId . ':' . $externalId;
            if ($this->exists($pdo, $sourceRef)) {
                $result['skipped']++;
                continue;
            }

            $account = $accounts[$accountUuid] ?? null;
            if ($account === null) {
                $result['unmapped']++;
                continue;
            }

            $amountObj = is_array($record['amount'] ?? null) ? $record['amount'] : [];
            $amount = (float) ($amountObj['value'] ?? 0);
            $currency = strtoupper(trim((string) (($amountObj['currencyCode'] ?? '') ?: $account['currency'])));
            $date = self::date($record['recordDate'] ?? null);
            if ($date === null || abs($amount) < 0.005) {
                $result['skipped']++;
                continue;
            }
            if ($dryRun) {
                $result['created']++;
                continue;
            }

            $note = self::text($record['note'] ?? null, 255);
            $cp = self::parseCounterParty(isset($record['counterParty']) ? (string) $record['counterParty'] : null);

            $statementId = 0;
            $txId = 0;
            try {
                $statementId = $this->statement($pdo, $supplierId, $accountUuid, $date, $account);
                $pdo->prepare(
                    "INSERT INTO bank_transactions
                        (source, source_ref, statement_id, posted_at, amount, currency,
                         variable_symbol, counterparty_account, counterparty_bank,
                         counterparty_name, description, bank_ref)
                     VALUES ('wallet', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                )->execute([
                    $sourceRef, $statementId, $date, number_format($amount, 2, '.', ''), $currency,
                    self::variableSymbolFromNote($note),
                    $cp['account'], $cp['bank'], $cp['name'],
                    $note ?? $cp['name'], substr($externalId, 0, 40),
                ]);
                $txId = (int) $pdo->lastInsertId();

                // Stejná platba už importovaná z autoritativního zdroje (GPC/PDF
                // výpis) → wallet dvojče se rovnou ignoruje, nic nepárujeme.
                $authoritativeTwinId = $this->reconciler->ignoreSecondaryWhenAuthoritativeTwinExists($txId);
                if ($authoritativeTwinId === null) {
                    $match = $this->matcher->match($txId);
                    if (in_array($match['status'], ['auto_exact', 'auto_partial'], true)) {
                        $result['matched']++;
                    }
                    if ($this->fixPaidDate($pdo, $txId, $date)) {
                        $result['paid_dates_fixed']++;
                    }
                }
                $this->refreshStatement($pdo, $statementId);
                $result['created']++;
            } catch (\Throwable $e) {
                if ($txId > 0) {
                    $pdo->prepare('DELETE FROM bank_transactions WHERE id = ?')->execute([$txId]);
                }
                if ($statementId > 0) {
                    $this->refreshStatement($pdo, $statementId);
                }
                throw $e;
            }
        }

        if (!$dryRun) {
            $pdo->prepare('UPDATE supplier SET wallet_last_synced_at = NOW() WHERE id = ?')->execute([$supplierId]);
        }
        return $result;
    }

    /**
     * Vytáhne kandidáta na variabilní symbol ze zprávy pro příjemce.
     * „Faktura 20260004" → 20260004, „VS: 1234" → 1234, „20265001 FIRMA" → 20265001.
     * Číslo přilepené na / nebo - (protiúčet, datum) se nebere.
     */
    public static function variableSymbolFromNote(?string $note): ?string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return null;
        }
        if (preg_match('/\bVS[.:\s]*(\d{1,10})\b/i', $note, $m)) {
            return $m[1];
        }
        if (preg_match('/(?<![\d\/\-])(\d{6,10})(?![\d\/\-])/', $note, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Rozparsuje Wallet `counterParty`: „Jméno, 123-4567890/0100",
     * „4567890/0100", nebo prostý text (obchodník u karetní platby).
     *
     * @return array{account:?string, bank:?string, name:?string}
     */
    public static function parseCounterParty(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['account' => null, 'bank' => null, 'name' => null];
        }
        if (preg_match('/(?:^|[\s,])((?:\d{1,6}-)?\d{2,10})\/(\d{4})\s*$/', $raw, $m, PREG_OFFSET_CAPTURE)) {
            $name = trim(substr($raw, 0, (int) $m[0][1]), " ,\t");
            return [
                'account' => $m[1][0],
                'bank'    => $m[2][0],
                'name'    => $name !== '' ? self::text($name, 190) : null,
            ];
        }
        return ['account' => null, 'bank' => null, 'name' => self::text($raw, 190)];
    }

    private function sinceDate(PDO $pdo, int $supplierId, bool $incremental): string
    {
        if ($incremental) {
            $stmt = $pdo->prepare('SELECT wallet_last_synced_at FROM supplier WHERE id = ?');
            $stmt->execute([$supplierId]);
            $last = $stmt->fetchColumn();
            if ($last !== false && $last !== null && $last !== '') {
                return (new \DateTimeImmutable((string) $last))
                    ->modify('-' . self::INCREMENTAL_OVERLAP_DAYS . ' days')->format('Y-m-d');
            }
        }
        return (new \DateTimeImmutable('today'))->modify(self::FULL_WINDOW)->format('Y-m-d');
    }

    private function exists(PDO $pdo, string $sourceRef): bool
    {
        $s = $pdo->prepare("SELECT 1 FROM bank_transactions WHERE source = 'wallet' AND source_ref = ? LIMIT 1");
        $s->execute([$sourceRef]);
        return (bool) $s->fetchColumn();
    }

    /**
     * Mapované Wallet účty (provider='wallet', matched) → lokální účet.
     * V dry-run navíc dopočte kandidáty z živých /accounts (stejně jako iDoklad),
     * aby náhled fungoval i před prvním uložením mapování.
     *
     * @return array<string,array<string,mixed>> keyed by Wallet account UUID
     */
    private function mappedAccounts(PDO $pdo, int $supplierId, bool $includeDryRunCandidates): array
    {
        $s = $pdo->prepare(
            "SELECT m.external_account_id, c.id, c.account_number, c.bank_code, c.iban, c.code AS currency
               FROM external_bank_account_mappings m
               JOIN currencies c ON c.id = m.currency_id AND c.supplier_id = m.supplier_id
              WHERE m.supplier_id = ? AND m.provider = 'wallet'
                AND m.sync_status = 'matched' AND c.is_active = 1"
        );
        $s->execute([$supplierId]);
        $mapped = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $mapped[(string) $row['external_account_id']] = $row;
        }
        if (!$includeDryRunCandidates) {
            return $mapped;
        }

        $currencies = $pdo->prepare(
            'SELECT id, account_number, bank_code, iban, code AS currency
               FROM currencies WHERE supplier_id = ? AND is_active = 1 ORDER BY id'
        );
        $currencies->execute([$supplierId]);
        $byCode = [];
        foreach ($currencies->fetchAll(PDO::FETCH_ASSOC) ?: [] as $currency) {
            $byCode[strtoupper((string) $currency['currency'])][] = $currency;
        }
        foreach ($this->wallet->getAccounts($supplierId) as $external) {
            $uuid = trim((string) ($external['id'] ?? ''));
            $number = trim((string) ($external['bankAccountNumber'] ?? ''));
            if ($uuid === '' || $number === '' || isset($mapped[$uuid])) {
                continue;
            }
            $code = strtoupper((string) (self::walletCurrencyCode($external) ?? 'CZK'));
            // Wallet nemá IBAN ani kód banky — matchExternalBankAccount si vystačí s číslem.
            $selection = IdokladImportService::matchExternalBankAccount(
                ['AccountNumber' => $number, 'Iban' => ''],
                $byCode[$code] ?? [],
            );
            if ($selection['status'] !== 'matched' || $selection['currency_id'] === null) {
                continue;
            }
            foreach ($byCode[$code] ?? [] as $candidate) {
                if ((int) $candidate['id'] === $selection['currency_id']) {
                    $mapped[$uuid] = $candidate;
                    break;
                }
            }
        }
        return $mapped;
    }

    /** Měna Wallet účtu (initialBalance/balance.currencyCode). */
    public static function walletCurrencyCode(array $account): ?string
    {
        foreach (['initialBalance', 'balance'] as $key) {
            $obj = $account[$key] ?? null;
            if (is_array($obj) && !empty($obj['currencyCode'])) {
                return strtoupper((string) $obj['currencyCode']);
            }
        }
        return null;
    }

    /** @param array<string,mixed> $account */
    private function statement(PDO $pdo, int $supplierId, string $accountUuid, string $date, array $account): int
    {
        $month = substr($date, 0, 7);
        $ref = $supplierId . ':' . $accountUuid . ':' . $month;
        $hash = hash('sha256', 'wallet:' . $ref);
        $pdo->prepare(
            "INSERT INTO bank_statements
                (source, source_ref, file_name, file_hash, account_number, bank_code, currency, statement_date)
             VALUES ('wallet', ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE statement_date = GREATEST(statement_date, VALUES(statement_date))"
        )->execute([
            $ref, 'Wallet ' . $month, $hash, (string) $account['account_number'],
            self::text($account['bank_code'] ?? null, 4), (string) $account['currency'], $date,
        ]);
        $s = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $s->execute([$hash]);
        return (int) $s->fetchColumn();
    }

    /**
     * Platba navázaná na už zaplacenou fakturu: pokud úhradu nedrží žádná
     * bankovní platba (ruční „Zaplaceno") a datum nesedí, oprav paid_at
     * (a jedinou ruční platbu) podle skutečného data platby z banky.
     */
    private function fixPaidDate(PDO $pdo, int $txId, string $txDate): bool
    {
        $stmt = $pdo->prepare('SELECT matched_invoice_id FROM bank_transactions WHERE id = ?');
        $stmt->execute([$txId]);
        $invoiceId = (int) ($stmt->fetchColumn() ?: 0);
        if ($invoiceId <= 0) {
            return false;
        }

        $inv = $pdo->prepare('SELECT status, paid_at FROM invoices WHERE id = ?');
        $inv->execute([$invoiceId]);
        $invoice = $inv->fetch(PDO::FETCH_ASSOC);
        if (!$invoice || $invoice['status'] !== 'paid' || (string) $invoice['paid_at'] === $txDate) {
            return false;
        }

        $p = $pdo->prepare('SELECT id, source FROM invoice_payments WHERE invoice_id = ? ORDER BY id');
        $p->execute([$invoiceId]);
        $payments = $p->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($payments as $payment) {
            if ($payment['source'] === 'bank') {
                return false; // úhradu už drží bankovní platba — nesahat
            }
        }
        if (count($payments) > 1) {
            return false; // částečné úhrady s vlastní historií — datum nechme být
        }
        if (count($payments) === 1) {
            $pdo->prepare('UPDATE invoice_payments SET paid_on = ? WHERE id = ?')
                ->execute([$txDate, (int) $payments[0]['id']]);
        }
        $pdo->prepare('UPDATE invoices SET paid_at = ? WHERE id = ?')->execute([$txDate, $invoiceId]);
        return true;
    }

    private function refreshStatement(PDO $pdo, int $statementId): void
    {
        $pdo->prepare(
            "UPDATE bank_statements bs SET
                transaction_count = (SELECT COUNT(*) FROM bank_transactions WHERE statement_id = bs.id),
                matched_count = (SELECT COUNT(*) FROM bank_transactions
                                  WHERE statement_id = bs.id
                                    AND match_status IN ('auto_exact','auto_partial','manual'))
              WHERE bs.id = ?"
        )->execute([$statementId]);
    }

    private static function date(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $max);
    }
}
