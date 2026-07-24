<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Explicitní nastavení counteru číselné řady (API endpoint
 * PUT /api/settings/supplier/invoice-counter → VarsymbolGenerator::setCounter()):
 * příští vystavený doklad má dostat přesně zadané číslo, včetně SNÍŽENÍ counteru
 * (na rozdíl od syncCounter). Kolize s existujícími čísly řeší self-heal v next().
 *
 * Izolace: rok 2099 (viz VarsymbolCounterSyncTest). Soft-skip bez cfg.php / DB / template.
 */
#[Group('integration')]
final class VarsymbolSetCounterTest extends TestCase
{
    private Connection $db;
    private VarsymbolGenerator $gen;
    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $userId = 0;
    private string $template = '';
    private \DateTimeImmutable $date;
    /** @var int[] */
    private array $created = [];

    protected function setUp(): void
    {
        $rootDir = dirname(__DIR__, 4);
        if (!is_file($rootDir . '/cfg.php')) {
            $this->markTestSkipped('cfg.php neexistuje — test vyžaduje DB.');
        }
        try {
            $c = Bootstrap::buildApp()->getContainer();
            $this->db = $c->get(Connection::class);
            $this->gen = $c->get(VarsymbolGenerator::class);
            $config = $c->get(Config::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }
        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->supplierId === 0) {
            $this->markTestSkipped('Chybí supplier.');
        }
        $this->template = trim((string) ($pdo->query(
            "SELECT invoice_number_format FROM supplier WHERE id = {$this->supplierId}"
        )->fetchColumn() ?: ''));
        if ($this->template === '') {
            $this->template = trim((string) $config->get('varsymbol.templates.invoice', ''));
        }
        if ($this->template === '' || !str_contains($this->template, '{C')) {
            $this->markTestSkipped('Není template s counterem ({C+}) pro vydanou fakturu.');
        }

        $this->clientId = (int) ($pdo->query("SELECT id FROM clients WHERE supplier_id = {$this->supplierId} ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->userId = (int) ($pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($this->clientId === 0 || $this->currencyId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí client/CZK currency/user.');
        }

        $this->date = new \DateTimeImmutable('2099-06-15');
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->cleanup();
        }
    }

    private function cleanup(): void
    {
        $pdo = $this->db->pdo();
        foreach ($this->created as $id) {
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        $pdo->prepare("DELETE FROM invoice_counters WHERE supplier_id = ? AND period LIKE '2099%'")
            ->execute([$this->supplierId]);
    }

    private function insertIssued(int $counter): string
    {
        $varsymbol = $this->gen->render($this->template, $this->date, $counter);
        $pdo = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, total_without_vat, total_with_vat, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, 'issued', 0, 0, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId,
            $this->date->format('Y-m-d'), $this->date->format('Y-m-d'), $this->date->format('Y-m-d'),
            $this->currencyId, $this->userId,
        ]);
        $this->created[] = (int) $pdo->lastInsertId();
        return $varsymbol;
    }

    public function testSetCounterMakesNextIssueUseExactNumber(): void
    {
        $result = $this->gen->setCounter($this->supplierId, 'invoice', 42, $this->date);

        self::assertSame(41, $result['counter'], 'last_number = next_number - 1');
        self::assertSame($this->gen->render($this->template, $this->date, 42), $result['preview']);

        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);
        self::assertSame($result['preview'], $next, 'Příští vystavení dostane přesně nastavené číslo.');
    }

    public function testSetCounterCanLowerCounter(): void
    {
        // Counter vepředu na 50 (např. po testovacích fakturách, které byly smazané).
        $this->gen->setCounter($this->supplierId, 'invoice', 51, $this->date);
        // Na rozdíl od syncCounter jde explicitně snížit.
        $result = $this->gen->setCounter($this->supplierId, 'invoice', 5, $this->date);
        self::assertSame(4, $result['counter']);

        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);
        self::assertSame($this->gen->render($this->template, $this->date, 5), $next);
    }

    public function testNextSelfHealsWhenSetCounterCollides(): void
    {
        // Číslo 7 už je obsazené vystavenou fakturou.
        $occupied = $this->insertIssued(7);

        // Admin přesto nastaví řadu na 7 → vystavení nesmí číslo zduplikovat.
        $this->gen->setCounter($this->supplierId, 'invoice', 7, $this->date);
        $next = $this->gen->next($this->supplierId, 'invoice', $this->date);

        self::assertNotSame($occupied, $next, 'Kolize se musí self-healnout, ne zduplikovat.');
        self::assertSame($this->gen->render($this->template, $this->date, 8), $next);
    }

    public function testCounterStatusReflectsSetCounterWithoutIncrement(): void
    {
        $this->gen->setCounter($this->supplierId, 'invoice', 42, $this->date);

        $status = $this->gen->counterStatus($this->supplierId, 'invoice', $this->date);
        self::assertSame(42, $status['next_number']);
        self::assertSame($this->gen->render($this->template, $this->date, 42), $status['preview']);
        self::assertTrue($status['has_counter']);

        // Opakované čtení nesmí counter posunout (na rozdíl od next()).
        $again = $this->gen->counterStatus($this->supplierId, 'invoice', $this->date);
        self::assertSame(42, $again['next_number']);

        // next() pak skutečně vydá číslo ze statusu.
        self::assertSame($status['preview'], $this->gen->next($this->supplierId, 'invoice', $this->date));
    }

    public function testCounterStatusRejectsUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->counterStatus($this->supplierId, 'nonsense', $this->date);
    }

    public function testSetCounterRejectsInvalidInput(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'invoice', 0, $this->date);
    }

    public function testSetCounterRejectsUnsupportedType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->gen->setCounter($this->supplierId, 'nonsense', 1, $this->date);
    }
}
