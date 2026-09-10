<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices
 *
 * Vytvoří draft přijaté faktury + insertne items + přepočte sumy.
 * Vendor musí existovat a patřit aktuálnímu tenantovi.
 */
final class CreatePurchaseInvoiceAction
{
    use HandlesVarsymbolDuplicate;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor musí existovat a patřit tenantovi (anti-cross-tenant injection)
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }

        // Auto-set is_vendor=1 pokud dosud nebyl označen jako dodavatel (může být dosud jen customer).
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Dodavatel neplátce DPH → odpočet nelze uplatnit. Když volající vat_deduction
        // explicitně neposlal, vynutíme 'none' (bezpečný default); když zvolil jinak,
        // respektujeme to (vědomý override v editoru), ale níže přidáme varování.
        // Plátcovství bereme ze snapshotu k datu plnění (`vendor_is_vat_payer` z těla, migrace
        // 0133) — ne z živého flagu klienta, aby historická faktura zůstala daňově správně
        // i když dodavatel dnes plátce už není. Fallback na živý flag jen když snapshot chybí.
        $vendorIsPayer = array_key_exists('vendor_is_vat_payer', $body)
            ? (bool) $body['vendor_is_vat_payer']
            : (isset($vendor['is_vat_payer']) ? (bool) $vendor['is_vat_payer'] : true);
        $vendorNonPayer = !$vendorIsPayer;
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // Konzistence hlavičky s položkami: explicitní RC klasifikace na položce/hlavičce
        // (5/23/24/24e/25…) vynucuje reverse_charge = 1 — jinak si data odporují a při
        // změně klasifikace se výkazy rozpadnou (viz VatClassificationDefaulter).
        // PŘED auto-defaulty, aby se zbylé neoklasifikované položky defaultovaly už jako RC.
        $rcForcedByClassification = false;
        if (empty($body['reverse_charge'])) {
            $explicitCodes = [];
            foreach ((array) ($body['items'] ?? []) as $it) {
                if (!empty($it['vat_classification_code'])) {
                    $explicitCodes[] = (string) $it['vat_classification_code'];
                }
            }
            if (!empty($body['vat_classification_code'])) {
                $explicitCodes[] = (string) $body['vat_classification_code'];
            }
            if ($this->vatDefaulter->anyReverseChargeCode($explicitCodes, $supplierId)) {
                $body['reverse_charge'] = 1;
                $rcForcedByClassification = true;
            }
        }

        // Klasifikaci DPH tady ZÁMĚRNĚ nedoplňujeme. Rozhoduje jediný country-aware SSOT
        // {@see PurchaseInvoiceRepository::defaultClassificationCode()} při ukládání řádků,
        // který zná zemi dodavatele i povahu plnění; hlavičku z řádků převezme
        // syncHeaderClassificationFromItems() po uložení. Dřív tu předsazený
        // VatClassificationDefaulter kód dosadil dřív, než se SSOT vůbec dostal ke slovu
        // (DB lookup pro 21 % + RC vrací podle display_order '24e' → tuzemský § 92e doklad
        // skončil na ř. 5 + KH A.2 místo ř. 10 + KH B.1).

        try {
            $id = $this->repo->createDraft($body, $userId, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            // Ruční interní číslo koliduje s existujícím (uq_pi_supplier_varsymbol) → 409.
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }

        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        // Ruční rekapitulace DPH dle dokladu (§ 73) — uložit PŘED recompute, aby ji
        // kalkulátor zapekl do řádkových totálů.
        if (array_key_exists('vat_overrides', $body)) {
            $this->repo->setVatOverrides($id, $supplierId, is_array($body['vat_overrides']) ? $body['vat_overrides'] : null);
        }
        $this->calc->recompute($id);
        // Hlavičková klasifikace se přebírá z řádků (SSOT je defaultClassificationCode()).
        // Až PO recompute — volba dominantního kódu váží podle total_with_vat, které
        // kalkulátor dopočítává.
        $this->repo->syncHeaderClassificationFromItems($id, $supplierId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.created', $userId, 'purchase_invoice', $id, [
            'vendor_id'    => $body['vendor_id'],
            'document_kind' => $body['document_kind'] ?? 'invoice',
        ], $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        // Non-blocking varování (např. dobropis s kladným součtem — viz issue #35).
        $warnings = PurchaseInvoiceValidation::warnings($invoice ?? []);
        // Neplátce + přesto uplatněn odpočet → upozorni (uživatel vědomě přepsal).
        // VÝJIMKA reverse charge (zahraniční služba/zboží): dodavatel je z pohledu české
        // DPH neplátce ZE SVÉ PODSTATY (nefakturuje českou DPH), ale příjemce si daň
        // samovyměří a smí ji odečíst (§ 72/73) — varování by tu bylo false positive.
        if ($vendorNonPayer && !PurchaseInvoiceValidation::isReverseCharge($invoice) && ($invoice['vat_deduction'] ?? 'full') !== 'none') {
            $warnings[] = 'vendor_non_payer_deduction';
        }
        if ($rcForcedByClassification) {
            $warnings[] = 'reverse_charge_forced_by_classification';
        }
        if (!empty($warnings)) {
            $invoice['_warnings'] = $warnings;
        }
        return Json::ok($response, $invoice, 201);
    }
}
