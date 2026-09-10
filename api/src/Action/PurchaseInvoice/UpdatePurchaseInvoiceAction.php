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
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}
 *
 * Update přijaté faktury. Standardně lze editovat pouze draft.
 * Admin může s `?force=1` upravit i received / booked / paid — cancelled zůstává immutable
 * (storná jsou součástí auditní stopy a nemají se editovat).
 */
final class UpdatePurchaseInvoiceAction
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

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $isForce = !empty($request->getQueryParams()['force']);

        if ($existing['status'] !== 'draft') {
            // Force-update: admin smí upravit received / booked / paid (s ?force=1;
            // UI: tlačítko „Odemknout k editaci" v editoru → potvrzovací modal).
            // cancelled zůstává immutable (storno = auditní stopa, nemá se editovat).
            // Bez force → 409 s návodem; force bez admin role → 403 (rozlišeno pro UI i testy).
            if ($existing['status'] === 'cancelled') {
                return Json::error($response, 'not_editable', 'Stornovanou fakturu nelze upravit (auditní stopa).', 409);
            }
            if (!$isForce) {
                return Json::error($response, 'not_editable',
                    "Faktura ve stavu '{$existing['status']}' nelze upravit. Administrátor ji může odemknout k editaci přímo v editoru dokladu (tlačítko „Odemknout k editaci“).",
                    409);
            }
            if (!$isAdmin) {
                return Json::error($response, 'forbidden',
                    'Odemknout a upravit fakturu mimo stav draft smí jen administrátor.', 403);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor scope check — pokud se mění vendor, musí patřit tenantovi
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Dodavatel neplátce DPH → bez nároku na odpočet. Default 'none' když neposláno;
        // explicitní volbu respektujeme (vědomý override), ale níže přidáme varování.
        // Plátcovství bereme ze snapshotu k datu plnění (`vendor_is_vat_payer` z těla, migrace
        // 0133) — ne z živého flagu klienta, aby u historické faktury šlo dodavatele označit
        // za plátce, i když dnes plátce není. Fallback na živý flag jen když snapshot chybí.
        $vendorIsPayer = array_key_exists('vendor_is_vat_payer', $body)
            ? (bool) $body['vendor_is_vat_payer']
            : (isset($vendor['is_vat_payer']) ? (bool) $vendor['is_vat_payer'] : true);
        $vendorNonPayer = !$vendorIsPayer;
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

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

        // Klasifikaci DPH doplní až SSOT při ukládání řádků
        // ({@see PurchaseInvoiceRepository::defaultClassificationCode()}) a hlavičku z nich
        // převezme syncHeaderClassificationFromItems() po uložení — viz CreatePurchaseInvoiceAction.

        try {
            $this->repo->updateDraft($id, $body, $supplierId);
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
        // Rounding override z body (uživatel může ručně upravit v editoru)
        if (array_key_exists('rounding', $body)) {
            $this->repo->setRounding($id, $supplierId, (float) $body['rounding']);
        }
        // Změna daňového uplatnění u už očíslované faktury → přepiš prefix interního
        // čísla na odpovídající typ (PF2602001 → NN2602001). No-op u draftu / ručních čísel.
        $this->repo->reprefixVarsymbol($id, $supplierId);

        $invoice = $this->repo->find($id, $supplierId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        // Force-edit non-draft faktury má vlastní typ auditní položky s plným
        // kontextem: CO se přepsalo (changed) + starý/nový snapshot dodavatele
        // (vendor_snapshot je jinak immutable záznam k datu zaevidování).
        $isForceEdit = $existing['status'] !== 'draft';
        $payload = null;
        if ($isForceEdit) {
            $changed = self::diffFields($existing, $invoice ?? []);
            $payload = [
                'changed'      => $changed,
                'old_snapshot' => ['vendor' => self::decodeSnapshot($existing['vendor_snapshot'] ?? null)],
                'new_snapshot' => ['vendor' => self::decodeSnapshot($invoice['vendor_snapshot'] ?? null)],
            ];
        }
        $action = $isForceEdit ? 'purchase_invoice.force_edit' : 'purchase_invoice.updated';
        // supplier_id explicitně — auto-resolve ActivityLoggeru entity purchase_invoice nezná.
        $this->logger->log($action, $user['id'] ?? null, 'purchase_invoice', $id, $payload, $ip, $request->getHeaderLine('User-Agent'), $supplierId);
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

    /**
     * Sémantické klíče polí změněných force-editem — audit detail „co se přepsalo".
     * Drž v sync s editovatelnými sloupci PurchaseInvoiceRepository::updateDraft()
     * (zrcadlí diffFields prodejní UpdateInvoiceAction).
     *
     * @return list<string>
     */
    private static function diffFields(array $old, array $new): array
    {
        $columns = [
            'vendor_id', 'vendor_invoice_number', 'document_kind', 'varsymbol',
            'issue_date', 'tax_date', 'due_date', 'received_at',
            'currency_id', 'exchange_rate', 'reverse_charge', 'prices_include_vat', 'language',
            'note_above_items', 'note_below_items', 'advance_paid_amount',
            'vat_classification_code', 'vat_deduction', 'vat_deduction_percent',
            'tax_deductible', 'is_fixed_asset', 'expense_category_id', 'vendor_is_vat_payer',
        ];
        $changed = [];
        foreach ($columns as $col) {
            // String cast sjednotí int/float/null/bool porovnání napříč PDO casty.
            if ((string) ($old[$col] ?? '') !== (string) ($new[$col] ?? '')) {
                $changed[] = preg_replace('/_id$/', '', $col);
            }
        }
        $project = static fn (array $it): array => [
            (string) ($it['description'] ?? ''),
            (string) ($it['quantity'] ?? ''),
            (string) ($it['unit'] ?? ''),
            (string) ($it['unit_price_without_vat'] ?? ''),
            (string) ($it['vat_rate_id'] ?? ''),
        ];
        if (array_map($project, array_values((array) ($old['items'] ?? [])))
            !== array_map($project, array_values((array) ($new['items'] ?? [])))) {
            $changed[] = 'items';
        }
        return $changed;
    }
}
