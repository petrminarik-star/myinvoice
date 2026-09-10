<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Report;

use MyInvoice\Action\Report\KontrolniHlaseniAction;
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
 * EPO identifikace (BUG 6, oprava 2026-07): před generováním XML se kontroluje
 * úplnost identifikace daňového subjektu. Chybí-li pole, které má v EPO
 * schématu `use="required"` (kód FÚ, DIČ, typ poplatníka), vrací STAŽENÍ XML
 * 422 `epo_identity_incomplete` s výčtem `missing[]` — dřív se vygenerovalo
 * validně vypadající XML, které EPO odmítlo až při podání.
 *
 * NÁHLED se nikdy neblokuje (uživatel musí vidět čísla i s neúplným
 * nastavením) a pole, která jsou v XSD `optional` (ÚzP, e-mail, opr_*),
 * generování nezastaví — jsou jen doporučená (viz review PR #245 bod 3).
 *
 * Testy dočasně přepisují EPO pole PRVNÍHO supplier-a a v tearDown je vrací.
 * Období 2050-06 (prázdné; parsePeriod povoluje roky jen 2020–2050) → žádná data, jen VetaD/VetaP.
 */
#[Group('integration')]
final class EpoIdentityGuardTest extends TestCase
{
    private Connection $db;
    private KontrolniHlaseniAction $kh;

    private int $supplierId = 0;
    private int $userId = 0;
    /** @var array<string,mixed> původní hodnoty supplier polí (vráceno v tearDown) */
    private array $original = [];

    private const FIELDS = [
        'financial_office_code', 'workplace_code', 'dic', 'taxpayer_type', 'email',
        'phone', 'cz_nace_code', 'opr_jmeno', 'opr_prijmeni', 'opr_postaveni', 'is_vat_payer',
    ];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->kh = $c->get(KontrolniHlaseniAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/admin user.');
        }

        $cols = implode(', ', self::FIELDS);
        $this->original = $pdo->query("SELECT {$cols} FROM supplier WHERE id = {$this->supplierId}")
            ->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($this->original === []) {
            $this->markTestSkipped('Supplier row nedostupný.');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db) || $this->original === []) {
            return;
        }
        $this->setSupplier($this->original);
        // Download testy archivují podání za 2050 → uklidit.
        $this->db->pdo()->prepare(
            "DELETE FROM tax_submissions WHERE supplier_id = ? AND period_year = 2050"
        )->execute([$this->supplierId]);
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

    /** Kompletní EPO identita (výchozí bod testů — jednotlivé testy pole nulují). */
    private function completeSupplier(): array
    {
        return [
            'financial_office_code' => '451',
            'workplace_code'        => '2005',
            'dic'                   => 'CZ12345678',
            'taxpayer_type'         => 'po',
            'email'                 => 'epo-test@example.com',
            'phone'                 => '+420722944990',
            'cz_nace_code'          => '622000', // aktivní kód číselníku (NACE 2.1); 620200 expirovalo 2025-12-31
            'opr_jmeno'             => 'Jan',
            'opr_prijmeni'          => 'Testovací',
            'opr_postaveni'         => 'jednatel',
            'is_vat_payer'          => 1,
        ];
    }

    private function request(string $path): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', $path)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withQueryParams(['year' => '2050', 'month' => '6']);
    }

    /** @return array<string,mixed> */
    private static function json(Psr7Response $resp): array
    {
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private static function missingFields(array $body): array
    {
        return array_column($body['error']['missing'] ?? [], 'field');
    }

    public function testMissingFinancialOfficeCodeBlocksDownloadButNotPreview(): void
    {
        // c_ufo má v dphkh1.xsd use="required" → stažení XML 422…
        $this->setSupplier(['financial_office_code' => null] + $this->completeSupplier());

        $resp = $this->kh->download($this->request('/api/reports/dphkh1'), new Psr7Response());
        self::assertSame(422, $resp->getStatusCode());
        $body = self::json($resp);
        self::assertSame('epo_identity_incomplete', $body['error']['code']);
        self::assertContains('financial_office_code', self::missingFields($body));
        self::assertSame('/admin/settings#epo', $body['error']['settings_url'] ?? null);

        // …ale náhled se musí zobrazit, jen s výčtem chybějících polí v těle.
        $resp = $this->kh->preview($this->request('/api/reports/dphkh1/preview'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $preview = self::json($resp);
        self::assertArrayHasKey('summary', $preview);
        self::assertSame(['financial_office_code'], array_column($preview['missing'] ?? [], 'field'));
    }

    public function testMissingWorkplaceCodeIsWarningOnly(): void
    {
        // c_pracufo je v XSD use="optional" — nesmí blokovat ani stažení XML.
        $this->setSupplier(['workplace_code' => null] + $this->completeSupplier());

        $resp = $this->kh->download($this->request('/api/reports/dphkh1'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $resp = $this->kh->preview($this->request('/api/reports/dphkh1/preview'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $preview = self::json($resp);
        self::assertSame([], $preview['missing'] ?? []);
        self::assertStringContainsString('územního pracoviště', implode(' | ', $preview['warnings'] ?? []));
    }

    public function testPoWithoutOprPrijmeniIsWarningOnly(): void
    {
        // opr_* jsou v XSD use="optional" — náhled i stažení projdou, jen varujeme.
        $this->setSupplier(['opr_prijmeni' => null] + $this->completeSupplier());

        $resp = $this->kh->preview($this->request('/api/reports/dphkh1/preview'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $preview = self::json($resp);
        self::assertSame([], $preview['missing'] ?? []);
        self::assertStringContainsString('Příjmení oprávněné osoby', implode(' | ', $preview['warnings'] ?? []));

        $resp = $this->kh->download($this->request('/api/reports/dphkh1'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
    }

    public function testCompleteSupplierProducesXmlWithIdentityAttributes(): void
    {
        $this->setSupplier($this->completeSupplier());

        $resp = $this->kh->download($this->request('/api/reports/dphkh1'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $resp->getBody()->rewind();
        $xml = new \SimpleXMLElement((string) $resp->getBody());
        $vetaP = $xml->DPHKH1->VetaP;
        self::assertSame('451',       (string) $vetaP['c_ufo']);
        self::assertSame('2005',      (string) $vetaP['c_pracufo']);
        self::assertSame('Jan',       (string) $vetaP['opr_jmeno']);
        self::assertSame('Testovací', (string) $vetaP['opr_prijmeni']);
        self::assertSame('jednatel',  (string) $vetaP['opr_postaveni']);
    }

    public function testMissingPhoneIsWarningOnly(): void
    {
        $this->setSupplier(['phone' => null] + $this->completeSupplier());

        $resp = $this->kh->preview($this->request('/api/reports/dphkh1/preview'), new Psr7Response());
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());
        $warnings = implode(' | ', self::json($resp)['warnings'] ?? []);
        self::assertStringContainsString('Telefon', $warnings, 'Chybějící telefon jen varuje, negeneruje 422.');
    }
}
