<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/rebuild-snapshots (admin only)
 *
 * „Obnovit údaje klienta" — lehčí alternativa k odemčení celého formuláře:
 * přepíše POUZE snapshoty (client/supplier/bank) z aktuálních live dat,
 * a to i u uzamčeného (issued/sent/paid) dokladu; částky, stav, varsymbol
 * i datumy zůstávají nedotčené. Zároveň ZNEPLATNÍ vygenerované PDF (stará
 * verze se archivuje) — u dokladu v už podaném období se tedy nově
 * vygenerované PDF může lišit od toho, které odběratel dostal.
 *
 * POZOR na motivaci: na výkazy DPH akce NEMÁ vliv. VatLedgerService čte DIČ
 * i název protistrany z živé tabulky `clients` (viz VatLedgerService::
 * fetchSales/fetchPurchases, sloupec `c.dic AS counterparty_dic`), ne ze
 * snapshotů — po změně DIČ nebo přechodu do skupinové registrace jsou KH
 * i přiznání správně i bez této akce. Projeví se jen v PDF a v exportech,
 * které snapshoty čtou (ISDOC, Pohoda, Stereo).
 *
 * Stornovaný doklad (cancelled) zůstává immutable — auditní stopa.
 * Akce se audituje jako `invoice.rebuild_snapshots` se starým i novým
 * snapshotem (stejná granularita jako invoice.force_edit).
 */
final class RebuildInvoiceSnapshotsAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly InvoicePdfRenderer $pdf,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $existing = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $existing)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (($user['role'] ?? '') !== 'admin') {
            return Json::error($response, 'forbidden', 'Obnovit údaje klienta na dokladu smí jen administrátor.', 403);
        }
        if ($existing['status'] === 'cancelled') {
            return Json::error($response, 'not_editable', 'Stornovaný doklad nelze měnit (auditní stopa).', 409);
        }
        // U draftu se snapshoty nepoužívají (renderer bere live data) — operace
        // by byla matoucí no-op, vystavením se snapshoty pořídí čerstvé.
        if ($existing['status'] === 'draft') {
            return Json::error($response, 'not_applicable',
                'Koncept snapshoty nepoužívá — údaje klienta se doplní živé při vystavení.', 409);
        }

        $old = [
            'client'   => self::decodeSnapshot($existing['client_snapshot'] ?? null),
            'supplier' => self::decodeSnapshot($existing['supplier_snapshot'] ?? null),
        ];

        // Přepíše client/supplier/bank snapshot z live dat (SnapshotBuilder) —
        // nic jiného (žádný recompute, žádná změna totals/stavů/čísel).
        $this->pdf->rebuildSnapshots($id);
        // Starý PDF soubor nese původní údaje → invalidovat, ať se vygeneruje znovu.
        $this->pdf->invalidate($id, 'invalidate_update');

        $invoice = $this->repo->find($id);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.rebuild_snapshots', $user['id'] ?? null, 'invoice', $id, [
            'old_snapshot' => $old,
            'new_snapshot' => [
                'client'   => self::decodeSnapshot($invoice['client_snapshot'] ?? null),
                'supplier' => self::decodeSnapshot($invoice['supplier_snapshot'] ?? null),
            ],
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $invoice);
    }

    /** JSON snapshot (string z DB) → pole pro auditní payload; neparsovatelný → null. */
    private static function decodeSnapshot(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
