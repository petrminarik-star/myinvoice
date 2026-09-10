<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\PurchaseInvoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Konzistence hlavičkového `reverse_charge` s klasifikací položek (audit 2026-07,
 * doklad PF2602010) — a hlavně HRANICE toho chování po review PR #245 (bod 4).
 *
 * Příznak se smí přepnout POUZE z kódů, které uživatel zadal VÝSLOVNĚ; to řeší
 * Create/Update akce přes VatClassificationDefaulter::anyReverseChargeCode()
 * ještě před doplněním defaultů. Repository::replaceItems() příznak nemění —
 * jinak by doklad „zahraniční dodavatel, 0 %, vědomě reverse_charge = 0" tiše
 * přepsal každý re-import i každé uložení, protože default položek 24e/24
 * na RC flagu nezávisí.
 *
 * Izolováno pod existujícím supplierem, vše uklizeno v tearDown.
 * Soft-skip pokud chybí cfg.php (CI runner bez DB).
 */
#[Group('integration')]
final class PurchaseReverseChargeConsistencyTest extends TestCase
{
    private Connection $db;
    private PurchaseInvoiceRepository $repo;
    private VatClassificationDefaulter $defaulter;

    private int $supplierId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private int $czId = 0;
    private int $ieId = 0;
    private int $zeroRateId = 0;

    /** @var int[] */
    private array $vendorIds = [];
    /** @var int[] */
    private array $piIds = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB connection.');
        }
        try {
            $container       = Bootstrap::buildApp()->getContainer();
            $this->db        = $container->get(Connection::class);
            $this->repo      = $container->get(PurchaseInvoiceRepository::class);
            $this->defaulter = $container->get(VatClassificationDefaulter::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE code='CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->czId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='CZ' LIMIT 1")->fetchColumn() ?: 0);
        $this->ieId       = (int) ($pdo->query("SELECT id FROM countries WHERE iso2='IE' LIMIT 1")->fetchColumn() ?: 0);
        $this->zeroRateId = (int) ($pdo->query(
            "SELECT id FROM vat_rates WHERE rate_percent = 0 AND is_reverse_charge = 0 AND country = 'CZ' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);

        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->userId === 0
            || $this->czId === 0 || $this->ieId === 0 || $this->zeroRateId === 0
        ) {
            $this->markTestSkipped('Chybí základní data v DB (countries CZ/IE, 0% sazba).');
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) return;
        $pdo = $this->db->pdo();
        foreach ($this->piIds as $id) {
            $pdo->prepare('DELETE FROM purchase_invoice_items WHERE purchase_invoice_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM purchase_invoices WHERE id = ?')->execute([$id]);
        }
        foreach ($this->vendorIds as $id) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$id]);
        }
        $this->db->close();
    }

    /**
     * Regrese k review #245 bod 4: uživatel vědomě nechal reverse_charge = 0
     * u zahraničního dodavatele s 0 % sazbou. Položky dostanou defaultem kód
     * 24e, ale hlavička se NESMÍ přepsat — ani při opakovaném uložení.
     */
    public function testDefaultedForeignCodesDoNotFlipHeaderFlag(): void
    {
        $vendor = $this->vendor('Zahraniční dodavatel IE', 'IE6388047V', $this->ieId);
        $id = $this->draft($vendor, 'PRC-1', reverseCharge: false);

        $this->repo->replaceItems($id, [$this->item('Cloud služba')]);
        self::assertSame(0, $this->storedFlag($id),
            'Defaultovaná klasifikace nesmí přepnout vědomě vypnutý reverse_charge.');
        self::assertSame(['24e'], $this->storedCodes($id),
            'Položka přesto dostane default 24e (přijetí služby z EU) — výkazy ji podle kódu najdou.');

        // Druhé uložení (re-import / editace) chování nemění.
        $this->repo->replaceItems($id, [$this->item('Cloud služba (znovu)')]);
        self::assertSame(0, $this->storedFlag($id),
            'Ani opakované uložení nesmí hlavičku tiše přepsat.');
    }

    /** Explicitní RC kód od uživatele příznak přepnout MÁ — hrdlem jsou Create/Update akce. */
    public function testExplicitReverseChargeCodeIsDetectedForActions(): void
    {
        $vendor = $this->vendor('Zahraniční dodavatel IE 2', 'IE6388047W', $this->ieId);

        self::assertTrue(
            $this->defaulter->anyReverseChargeCode(['24e'], $this->supplierId),
            'Kód 24e je v číselníku v režimu přenesené povinnosti — akce podle toho zapnou flag a vrátí warning.',
        );
        self::assertFalse(
            $this->defaulter->anyReverseChargeCode(['42'], $this->supplierId),
            'Tuzemská klasifikace bez přenesení povinnosti flag zapínat nesmí.',
        );

        // Doklad s explicitním kódem uložený repozitářem si kód udrží; flag
        // nastavuje volající akce (tady simulujeme její výsledek).
        $id = $this->draft($vendor, 'PRC-2', reverseCharge: true);
        $this->repo->replaceItems($id, [$this->item('Poradenství', code: '24e')]);
        self::assertSame(['24e'], $this->storedCodes($id));
        self::assertSame(1, $this->storedFlag($id));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function storedFlag(int $piId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT reverse_charge FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$piId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private function storedCodes(int $piId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT vat_classification_code FROM purchase_invoice_items WHERE purchase_invoice_id = ? ORDER BY order_index'
        );
        $stmt->execute([$piId]);
        return array_map(static fn ($v): string => (string) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function vendor(string $name, string $dic, int $countryId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO clients (supplier_id, company_name, street, city, zip, country_id, dic,
                                  main_email, language, currency_default_id, is_customer, is_vendor)
             VALUES (?, ?, "Test 1", "Dublin", "D02", ?, ?, "v@example.com", "cs", ?, 0, 1)'
        );
        $stmt->execute([$this->supplierId, $name, $countryId, $dic, $this->currencyId]);
        $id = (int) $this->db->pdo()->lastInsertId();
        $this->vendorIds[] = $id;
        return $id;
    }

    private function draft(int $vendorId, string $number, bool $reverseCharge): int
    {
        $id = $this->repo->createDraft([
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $number,
            'document_kind'         => 'invoice',
            'issue_date'            => '2099-06-10',
            'tax_date'              => '2099-06-10',
            'due_date'              => '2099-06-24',
            'currency_id'           => $this->currencyId,
            'reverse_charge'        => $reverseCharge,
        ], $this->userId, $this->supplierId);
        $this->piIds[] = $id;
        return $id;
    }

    /** @return array<string,mixed> */
    private function item(string $description, ?string $code = null): array
    {
        return [
            'description'             => $description,
            'quantity'                => 1,
            'unit'                    => 'ks',
            'unit_price_without_vat'  => 1000.0,
            'vat_rate_id'             => $this->zeroRateId,
            'order_index'             => 0,
            'vat_classification_code' => $code,
        ];
    }
}
