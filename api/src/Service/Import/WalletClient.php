<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use GuzzleHttp\Client;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Auth\SecretEncryption;
use Psr\Log\LoggerInterface;

/**
 * Wallet by BudgetBakers REST API client (rest.budgetbakers.com).
 *
 * Auth: osobní Bearer token (Premium účet), generuje se na
 * https://web.budgetbakers.com/settings/rest-api. Token je statický — žádný
 * OAuth refresh; šifrovaně v supplier.wallet_api_token_enc. Po vygenerování
 * PRVNÍHO tokenu Wallet spouští interní synchronizaci dat a do jejího
 * dokončení vrací API 409 (viz WalletSyncInProgressException).
 *
 * Endpoints (base https://rest.budgetbakers.com/wallet/v1/api — pozor,
 * prefix /wallet je povinný, bez něj 404):
 *   - GET /accounts   → { accounts: [...] }
 *   - GET /records?recordDate=gte.YYYY-MM-DD&limit=200&offset=N
 *                     → { records: [...], limit, offset, nextOffset }
 *
 * Částka recordu je objekt {value, currencyCode}; kladná = příjem, záporná
 * = výdaj. Rate limit 300 req/h (token bucket) — stránkování po 200 ho při
 * běžném importu nevyčerpá, 429 se hlásí jako chyba s radou počkat.
 */
final class WalletClient
{
    private const API_BASE = 'https://rest.budgetbakers.com/wallet/v1/api';
    private const TIMEOUT  = 30;
    public const PAGE_LIMIT = 200;

    private Client $http;

    public function __construct(
        private readonly Connection $db,
        private readonly SecretEncryption $crypto,
        private readonly LoggerInterface $logger,
    ) {
        $this->http = new Client(['timeout' => self::TIMEOUT, 'http_errors' => false]);
    }

    public function getToken(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT wallet_api_token_enc FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $enc = $stmt->fetchColumn();
        if ($enc === false || $enc === null || $enc === '') {
            return null;
        }
        try {
            return $this->crypto->decrypt((string) $enc);
        } catch (\Throwable) {
            $this->logger->error('Wallet token decryption failed', ['supplier_id' => $supplierId]);
            return null;
        }
    }

    /** Prázdný token = smazání (a reset inkrementálního kurzoru). */
    public function setToken(int $supplierId, string $token): void
    {
        $enc = $token === '' ? null : $this->crypto->encrypt($token);
        $this->db->pdo()->prepare(
            'UPDATE supplier SET wallet_api_token_enc = ?, wallet_last_synced_at = NULL WHERE id = ?'
        )->execute([$enc, $supplierId]);
    }

    /**
     * Test tokenu — GET /accounts. 409 (Wallet ještě synchronizuje) je z pohledu
     * validace tokenu úspěch.
     *
     * @return array{ok:bool, accounts:?int, sync_in_progress?:bool, error?:string}
     */
    public function testConnection(int $supplierId, ?string $tokenOverride = null): array
    {
        try {
            $accounts = $this->getAccounts($supplierId, $tokenOverride);
            return ['ok' => true, 'accounts' => count($accounts)];
        } catch (WalletSyncInProgressException) {
            return ['ok' => true, 'accounts' => null, 'sync_in_progress' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'accounts' => null, 'error' => $e->getMessage()];
        }
    }

    /** @return list<array<string,mixed>> */
    public function getAccounts(int $supplierId, ?string $tokenOverride = null): array
    {
        $data = $this->request($supplierId, '/accounts', [], $tokenOverride);
        $accounts = $data['accounts'] ?? [];
        return is_array($accounts) ? array_values(array_filter($accounts, 'is_array')) : [];
    }

    /**
     * Iterátor přes všechny records od $sinceDate (YYYY-MM-DD, filtr recordDate).
     *
     * @return iterable<array<string,mixed>>
     */
    public function getRecords(int $supplierId, string $sinceDate): iterable
    {
        $offset = 0;
        do {
            $data = $this->request($supplierId, '/records', [
                'recordDate' => 'gte.' . $sinceDate,
                'limit'      => self::PAGE_LIMIT,
                'offset'     => $offset,
            ]);
            $records = is_array($data['records'] ?? null) ? $data['records'] : [];
            foreach ($records as $record) {
                if (is_array($record)) {
                    yield $record;
                }
            }
            $offset = (int) ($data['nextOffset'] ?? ($offset + self::PAGE_LIMIT));
        } while (count($records) === self::PAGE_LIMIT);
    }

    /** @return array<string,mixed> */
    private function request(int $supplierId, string $path, array $query = [], ?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?? $this->getToken($supplierId);
        if ($token === null || $token === '') {
            throw new \RuntimeException('Wallet API token není nastaven pro tohoto suppliera.');
        }
        $resp = $this->http->get(self::API_BASE . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
            'query'   => $query,
        ]);
        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();

        if ($code === 401 || $code === 403) {
            throw new \RuntimeException(
                "Wallet API odmítlo token (HTTP {$code}) — vygeneruj nový na web.budgetbakers.com/settings/rest-api."
            );
        }
        if ($code === 409) {
            throw new WalletSyncInProgressException(
                'Wallet po vygenerování tokenu ještě synchronizuje data — zkus to za pár minut.'
            );
        }
        if ($code === 429) {
            throw new \RuntimeException('Wallet API rate limit (300 požadavků/hod) — zkus to později.');
        }
        if ($code !== 200) {
            throw new \RuntimeException("Wallet GET {$path} selhal (HTTP {$code}): " . substr($body, 0, 300));
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Wallet GET {$path} vrátil nevalidní JSON.");
        }
        return $data;
    }
}
