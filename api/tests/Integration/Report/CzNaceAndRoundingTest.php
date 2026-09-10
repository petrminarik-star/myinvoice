<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\DphPriznaniAction;
use MyInvoice\Action\Settings\SettingsAction;
use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Middleware\SupplierScopeMiddleware;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response as Psr7Response;

/**
 * BUG 7 — CZ-NACE (c_okec) a EPO propustné chyby 30/49:
 *   - PUT supplieru s oddílem NACE („74") → 422 (EPO kód v číselníku nenajde),
 *   - platný vstup „73.11" se normalizuje na 731100 a propíše do VetaD c_okec,
 *   - rozdíl zaokrouhlení daně na ř. 40 (součet z dokladů vs. dopočet ze
 *     zaokrouhleného základu) vrací warning s chybou 49, ale preview je 200
 *     a XML se kvůli tomu nepřepisuje.
 *
 * Izolace: období 2050-05 (parsePeriod povoluje roky jen 2020–2050; 2050-06
 * používá EpoIdentityGuardTest), supplier pole se v tearDown vrací.
 */
#[Group('integration')]
final class CzNaceAndRoundingTest extends TestCase
{
    private Connection $db;
    private DphPriznaniAction $dp3;
    private SettingsAction $settings;

    private int $supplierId = 0;
    private int $userId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $clientId = 0;
    /** @var int[] */
    private array $purchaseIds = [];
    /** @var array<string,mixed> */
    private array $original = [];

    private const FIELDS = [
        'financial_office_code', 'workplace_code', 'dic', 'taxpayer_type', 'email',
        'phone', 'cz_nace_code', 'opr_jmeno', 'opr_prijmeni', 'opr_postaveni',
        'is_vat_payer', 'vat_period',
    ];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db       = $c->get(Connection::class);
            $this->dp3      = $c->get(DphPriznaniAction::class);
            $this->settings = $c->get(SettingsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0 || $this->currencyId === 0 || $this->vatRateId === 0) {
            $this->markTestSkipped('Chybí supplier/admin/CZK/vat_rate.');
        }

        $cols = implode(', ', self::FIELDS);
        $this->original = $pdo->query("SELECT {$cols} FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(PDO::FETCH_ASSOC) ?: [];
        $this->setSupplier($this->completeSupplier());
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $pdo = $this->db->pdo();
        foreach ($this->purchaseIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
        }
        if ($this->original !== []) {
            $this->setSupplier($this->original);
        }
    }

    /** @param array<string,mixed> $values */
    private function setSupplier(array $values): void
    {
        $sets = [];
        $params = [];
        foreach ($values as $col => $val) {
            $sets[] = "{$col} = ?";
            $params[] = $val;
        }
        $params[] = $this->supplierId;
        $this->db->pdo()->prepare('UPDATE supplier SET ' . implode(', ', $sets) . ' WHERE id = ?')
            ->execute($params);
    }

    /** @return array<string,mixed> */
    private function completeSupplier(): array
    {
        return [
            'financial_office_code' => '451',
            'workplace_code'        => '2005',
            'dic'                   => 'CZ12345678',
            'taxpayer_type'         => 'po',
            'email'                 => 'cznace-test@example.com',
            'phone'                 => '+420722944990',
            'cz_nace_code'          => '731100',
            'opr_jmeno'             => 'Jan',
            'opr_prijmeni'          => 'Testovací',
            'opr_postaveni'         => 'jednatel',
            'is_vat_payer'          => 1,
            'vat_period'            => 'monthly',
        ];
    }

    private function putSupplier(array $body): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/suppliers/' . $this->supplierId)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withParsedBody($body);
        return $this->settings->updateSupplierById($req, new Psr7Response(), ['id' => (string) $this->supplierId]);
    }

    private function dp3Request(string $path, array $query): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams($query);
    }

    /** @return array<string,mixed> */
    private static function json(Psr7Response $resp): array
    {
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSupplierPutWithNaceDivisionIs422(): void
    {
        $resp = $this->putSupplier(['cz_nace_code' => '74']);
        self::assertSame(422, $resp->getStatusCode(), (string) $resp->getBody());
        $body = self::json($resp);
        self::assertStringContainsString('4místný kód třídy', (string) $body['error']['message']);

        // Neuložilo se — v DB zůstává původní kompletní hodnota.
        $stored = $this->db->pdo()->query(
            "SELECT cz_nace_code FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn();
        self::assertSame('731100', (string) $stored);
    }

    public function testSupplierPutNormalizesClassCodeAndXmlCarriesCOkec(): void
    {
        $resp = $this->putSupplier(['cz_nace_code' => '73.11']);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $stored = $this->db->pdo()->query(
            "SELECT cz_nace_code FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn();
        self::assertSame('731100', (string) $stored, '73.11 se normalizuje na 731100.');

        $resp = $this->dp3->download(
            $this->dp3Request('/api/reports/dphdp3', ['year' => '2050', 'month' => '5', 'period' => 'monthly', 'form' => 'B']),
            new Psr7Response(),
        );
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $resp->getBody()->rewind();
        $xml = new \SimpleXMLElement((string) $resp->getBody());
        self::assertSame('731100', (string) $xml->DPHDP3->VetaD['c_okec']);

        // Úklid archivovaného podání z downloadu.
        $this->db->pdo()->prepare(
            'DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = 2050'
        )->execute([$this->supplierId]);
    }

    public function testSupplierPutWithExpiredNace20CodeSavesWithWarning(): void
    {
        // Kód 62020/620200 platil v číselníku do 31. 12. 2025 (přechod na NACE
        // rev. 2.1, nástupce 622000) — přesně scénář issue #157. Uložení se
        // NEBLOKUJE (snapshot číselníku může zestárnout), ale odpověď nese
        // cz_nace_warning s datem konce platnosti.
        $resp = $this->putSupplier(['cz_nace_code' => '62020']);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $body = self::json($resp);
        self::assertArrayHasKey('cz_nace_warning', $body);
        self::assertStringContainsString('31. 12. 2025', (string) $body['cz_nace_warning']);
        self::assertStringContainsString('NACE rev. 2.1', (string) $body['cz_nace_warning']);

        $stored = $this->db->pdo()->query(
            "SELECT cz_nace_code FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn();
        self::assertSame('620200', (string) $stored, 'ČSÚ zápis 62020 se kanonizuje na 620200.');

        // Aktivní kód warning nemá — a vrátí DB do stavu pro ostatní testy.
        $resp = $this->putSupplier(['cz_nace_code' => '731100']);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        self::assertArrayNotHasKey('cz_nace_warning', self::json($resp));
    }

    public function testRoundingDifferenceOnLine40YieldsWarning49AndStill200(): void
    {
        $pdo = $this->db->pdo();
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($czId === 0) {
            $this->markTestSkipped('Chybí země CZ.');
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, dic, street, city, zip, country_id, main_email, language, currency_default_id, is_vendor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$this->supplierId, 'CZ dodavatel — zaokrouhlení', 'CZ44455566', 'Testovací 2', 'Praha', '11000', $czId, 'rounding@example.com', 'cs', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();

        // Základ 1000.49 → v přiznání 1000; daň z dokladu 209.00 → 209;
        // dopočet EPO round(1000 × 21 %) = 210 → rozdíl 1 Kč = propustná chyba 49.
        $d = '2050-05-10';
        $pdo->prepare(
            'INSERT INTO purchase_invoices
                (supplier_id, vendor_id, vendor_invoice_number, document_kind, issue_date, tax_date,
                 due_date, received_at, currency_id, reverse_charge, vendor_snapshot,
                 total_without_vat, total_vat, total_with_vat, status, vat_classification_code,
                 vat_deduction, vat_deduction_percent, created_by)
             VALUES (?, ?, ?, "invoice", ?, ?, ?, ?, ?, 0, "{}", 1000.49, 209.00, 1209.49, "received", ?, "full", 100, ?)'
        )->execute([$this->supplierId, $this->clientId, 'ROUND-49', $d, $d, $d, $d, $this->currencyId, '40', $this->userId]);
        $piId = (int) $pdo->lastInsertId();
        $this->purchaseIds[] = $piId;
        $pdo->prepare(
            'INSERT INTO purchase_invoice_items
                (purchase_invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index, vat_classification_code)
             VALUES (?, "Test položka", 1, "ks", 1000.49, ?, 21, 1000.49, 209.00, 1209.49, 0, "40")'
        )->execute([$piId, $this->vatRateId]);

        $resp = $this->dp3->preview(
            $this->dp3Request('/api/reports/dphdp3/preview', ['year' => '2050', 'month' => '5', 'period' => 'monthly']),
            new Psr7Response(),
        );
        self::assertSame(200, $resp->getStatusCode(), 'Warning zaokrouhlení NESMÍ blokovat preview.');
        $body = self::json($resp);
        $warnings = implode(' | ', $body['warnings'] ?? []);
        self::assertStringContainsString('chybu 49', $warnings);
        self::assertStringContainsString('ř. 40', $warnings);
        self::assertStringContainsString('neupravuj', $warnings);

        // Bod 6 zadání: hodnoty v summary zůstávají součtem daně z dokladů (209), ne dopočtem (210).
        self::assertEqualsWithDelta(209.0, (float) ($body['summary']['lines']['40']['vat'] ?? 0), 0.01);
    }
}
