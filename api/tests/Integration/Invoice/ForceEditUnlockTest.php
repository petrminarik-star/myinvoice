<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Integration\Invoice;

use MyInvoice\Action\Invoice\RebuildInvoiceSnapshotsAction;
use MyInvoice\Action\Invoice\UpdateInvoiceAction;
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
 * Zámek stavu dokladu + odemykání (BUG 5, oprava 2026-07):
 *   - PUT vystavené faktury bez force → 409 a hláška vede k odemčení v UI,
 *   - PUT s ?force=1 jako non-admin → 403,
 *   - PUT s ?force=1 jako admin → 200, varsymbol immutable, snapshoty přepsané
 *     z live dat + auditní položka invoice.force_edit s old/new snapshotem,
 *   - POST /rebuild-snapshots na zaplacené faktuře → 200, mění se JEN snapshoty
 *     (total_with_vat / status / paid_at / varsymbol bit-shodné).
 *
 * Izolace v roce 2099 pod existujícím supplierem; úklid v tearDown.
 */
#[Group('integration')]
final class ForceEditUnlockTest extends TestCase
{
    private Connection $db;
    private UpdateInvoiceAction $update;
    private RebuildInvoiceSnapshotsAction $rebuild;

    private int $supplierId = 0;
    private int $clientId = 0;
    private int $currencyId = 0;
    private int $vatRateId = 0;
    private int $userId = 0;
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
            $this->db      = $c->get(Connection::class);
            $this->update  = $c->get(UpdateInvoiceAction::class);
            $this->rebuild = $c->get(RebuildInvoiceSnapshotsAction::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI/DB nedostupné: ' . $e->getMessage());
        }

        $pdo = $this->db->pdo();
        $this->supplierId = (int) ($pdo->query('SELECT id FROM supplier ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->currencyId = (int) ($pdo->query("SELECT id FROM currencies WHERE supplier_id = {$this->supplierId} AND code = 'CZK' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        $this->vatRateId  = (int) ($pdo->query('SELECT id FROM vat_rates ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        $this->userId     = (int) ($pdo->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
        if ($this->supplierId === 0 || $this->currencyId === 0 || $this->vatRateId === 0 || $this->userId === 0) {
            $this->markTestSkipped('Chybí supplier/CZK/vat_rate/admin user.');
        }
        $this->cleanup();
        // Vlastní testovací klient (CI seed žádné klienty nemá) — smaže se v tearDown.
        $czId = (int) ($pdo->query("SELECT id FROM countries WHERE iso2 = 'CZ' LIMIT 1")->fetchColumn() ?: 0);
        if ($czId === 0) {
            $this->markTestSkipped('Chybí země CZ v číselníku.');
        }
        $pdo->prepare(
            'INSERT INTO clients (supplier_id, company_name, dic, street, city, zip, country_id, main_email, language, currency_default_id, is_customer)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([$this->supplierId, 'ForceEdit test klient s.r.o.', 'CZ87654321', 'Testovací 1', 'Praha', '11000', $czId, 'force-edit@example.com', 'cs', $this->currencyId]);
        $this->clientId = (int) $pdo->lastInsertId();
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
            $pdo->prepare('DELETE FROM activity_log WHERE entity_type = ? AND entity_id = ?')->execute(['invoice', $id]);
            $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
        }
        $this->created = [];
        if ($this->clientId > 0) {
            $pdo->prepare('DELETE FROM clients WHERE id = ?')->execute([$this->clientId]);
            $this->clientId = 0;
        }
    }

    /** Vloží non-draft fakturu se ZASTARALÝM snapshotem klienta; vrátí id. */
    private function insertLockedInvoice(string $status, string $varsymbol, ?string $paidAt = null): int
    {
        $pdo = $this->db->pdo();
        $d = '2099-06-15';
        $staleSnapshot = json_encode(['company_name' => 'ZASTARALÝ SNAPSHOT s.r.o.', 'dic' => 'CZ00000000'], JSON_UNESCAPED_UNICODE);
        $pdo->prepare(
            "INSERT INTO invoices
                (invoice_type, varsymbol, client_id, supplier_id, issue_date, tax_date, due_date,
                 currency_id, status, paid_at, total_without_vat, total_vat, total_with_vat,
                 client_snapshot, supplier_snapshot, created_by)
             VALUES ('invoice', ?, ?, ?, ?, ?, ?, ?, ?, ?, 100, 21, 121, ?, ?, ?)"
        )->execute([
            $varsymbol, $this->clientId, $this->supplierId, $d, $d, $d,
            $this->currencyId, $status, $paidAt, $staleSnapshot, $staleSnapshot, $this->userId,
        ]);
        $id = (int) $pdo->lastInsertId();
        $this->created[] = $id;
        $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat, vat_rate_id,
                 vat_rate_snapshot, total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, 1, ?, 100, ?, 21, 100, 21, 121, 0)'
        )->execute([$id, 'Test položka', 'ks', $this->vatRateId]);
        return $id;
    }

    private function put(int $id, string $role, bool $force): Psr7Response
    {
        $req = (new ServerRequestFactory())
            ->createServerRequest('PUT', '/api/invoices/' . $id)
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => $role])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId)
            ->withParsedBody([
                'client_id'   => $this->clientId,
                'currency_id' => $this->currencyId,
                'issue_date'  => '2099-06-15',
                'tax_date'    => '2099-06-15',
                'due_date'    => '2099-06-29',
                'varsymbol'   => 'HACKED-VS', // u vystavené se MUSÍ ignorovat (immutable)
                'items'       => [[
                    'description'            => 'Upravená položka',
                    'quantity'               => 1,
                    'unit'                   => 'ks',
                    'unit_price_without_vat' => 100,
                    'vat_rate_id'            => $this->vatRateId,
                ]],
            ]);
        if ($force) {
            $req = $req->withQueryParams(['force' => '1']);
        }
        return ($this->update)($req, new Psr7Response(), ['id' => (string) $id]);
    }

    /** @return array<string,mixed> */
    private static function json(Psr7Response $resp): array
    {
        $resp->getBody()->rewind();
        return json_decode((string) $resp->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testPutIssuedWithoutForceIs409AndMentionsUnlock(): void
    {
        $id = $this->insertLockedInvoice('issued', 'FE2099-409');
        $resp = $this->put($id, 'admin', force: false);

        self::assertSame(409, $resp->getStatusCode());
        $body = self::json($resp);
        self::assertSame('not_editable', $body['error']['code']);
        self::assertStringContainsString('Odemknout k editaci', (string) $body['error']['message'],
            'Hláška musí uživatele navést na odemčení v UI.');
    }

    public function testPutWithForceAsNonAdminIs403(): void
    {
        $id = $this->insertLockedInvoice('issued', 'FE2099-403');
        $resp = $this->put($id, 'accountant', force: true);

        self::assertSame(403, $resp->getStatusCode());
        self::assertSame('forbidden', self::json($resp)['error']['code']);
    }

    public function testPutWithForceAsAdminKeepsVarsymbolAndRebuildsSnapshots(): void
    {
        $id = $this->insertLockedInvoice('issued', 'FE2099-200');
        $resp = $this->put($id, 'admin', force: true);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $row = $this->db->pdo()->query(
            "SELECT varsymbol, client_snapshot FROM invoices WHERE id = {$id}"
        )->fetch(PDO::FETCH_ASSOC);
        self::assertSame('FE2099-200', (string) $row['varsymbol'], 'Varsymbol vystavené faktury je immutable.');
        self::assertStringNotContainsString('ZASTARALÝ SNAPSHOT', (string) $row['client_snapshot'],
            'Snapshot klienta musí být přepsán z live dat.');

        // Audit: samostatná položka invoice.force_edit s old/new snapshotem a diffem polí.
        $log = $this->db->pdo()->query(
            "SELECT payload FROM activity_log WHERE action = 'invoice.force_edit'
              AND entity_type = 'invoice' AND entity_id = {$id} ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
        self::assertNotFalse($log, 'Force-edit musí zapsat auditní položku invoice.force_edit.');
        $payload = json_decode((string) $log, true);
        self::assertArrayHasKey('old_snapshot', $payload);
        self::assertArrayHasKey('new_snapshot', $payload);
        self::assertSame('ZASTARALÝ SNAPSHOT s.r.o.', $payload['old_snapshot']['client']['company_name'] ?? null);
    }

    public function testRebuildSnapshotsOnPaidChangesOnlySnapshots(): void
    {
        $id = $this->insertLockedInvoice('paid', 'FE2099-RBS', paidAt: '2099-06-20 10:00:00');
        $pdo = $this->db->pdo();
        // Fixture nechává pdf_* a supplier/bank snapshot prázdné — bez těchto
        // sentinelů by asserty níž prošly, i kdyby akce invalidaci PDF ani zápis
        // snapshotů vůbec nedělala.
        $pdo->prepare(
            "UPDATE invoices
                SET pdf_path = 'sup-1/2099/FE2099-RBS.pdf', pdf_generated_at = '2099-06-20 11:00:00',
                    supplier_snapshot = '{\"company_name\":\"ZASTARALÝ DODAVATEL s.r.o.\"}',
                    bank_snapshot = '{\"iban\":\"CZ0000000000000000000000\"}'
              WHERE id = ?"
        )->execute([$id]);
        $before = $pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);

        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/invoices/{$id}/rebuild-snapshots")
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'admin'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $resp = ($this->rebuild)($req, new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(200, $resp->getStatusCode(), (string) $resp->getBody());

        $after = $pdo->query("SELECT * FROM invoices WHERE id = {$id}")->fetch(PDO::FETCH_ASSOC);
        // Mění se POUZE snapshoty (+ pdf invalidace sloupců) — účetní hodnoty bit-shodné.
        foreach (['total_with_vat', 'total_without_vat', 'total_vat', 'status', 'paid_at', 'varsymbol',
                  'issue_date', 'tax_date', 'client_id'] as $col) {
            self::assertSame((string) ($before[$col] ?? ''), (string) ($after[$col] ?? ''),
                "Sloupec {$col} se rebuild-snapshots NESMÍ změnit.");
        }
        self::assertStringNotContainsString('ZASTARALÝ SNAPSHOT', (string) $after['client_snapshot'],
            'Snapshot klienta byl obnoven z live dat.');
        self::assertNotSame($before['client_snapshot'], $after['client_snapshot']);
        // Přepisuje se i dodavatel a bankovní spojení, ne jen klient — na to
        // upozorňuje potvrzovací dialog (review PR #245, poznámka ke snapshotům).
        self::assertStringNotContainsString('ZASTARALÝ DODAVATEL', (string) $after['supplier_snapshot']);
        self::assertNotSame($before['supplier_snapshot'], $after['supplier_snapshot']);
        self::assertNotSame($before['bank_snapshot'], $after['bank_snapshot']);
        // …a PDF se zneplatní, takže se při dalším stažení vygeneruje z NOVÝCH
        // snapshotů — u dokladu v podaném období se může lišit od doručené verze.
        self::assertNotNull($before['pdf_path'] ?? null, 'sanity: sentinel PDF byl nastaven');
        self::assertNull($after['pdf_path'] ?? null, 'rebuild-snapshots invaliduje cached PDF.');
        self::assertNull($after['pdf_generated_at'] ?? null);

        // Audit invoice.rebuild_snapshots existuje.
        $count = (int) $pdo->query(
            "SELECT COUNT(*) FROM activity_log WHERE action = 'invoice.rebuild_snapshots'
              AND entity_type = 'invoice' AND entity_id = {$id}"
        )->fetchColumn();
        self::assertSame(1, $count);
    }

    public function testRebuildSnapshotsAsNonAdminIs403(): void
    {
        $id = $this->insertLockedInvoice('paid', 'FE2099-RB3');
        $req = (new ServerRequestFactory())
            ->createServerRequest('POST', "/api/invoices/{$id}/rebuild-snapshots")
            ->withAttribute(AuthMiddleware::ATTR_USER, ['id' => $this->userId, 'role' => 'accountant'])
            ->withAttribute(SupplierScopeMiddleware::ATTR_CURRENT_ID, $this->supplierId);
        $resp = ($this->rebuild)($req, new Psr7Response(), ['id' => (string) $id]);
        self::assertSame(403, $resp->getStatusCode());
    }
}
