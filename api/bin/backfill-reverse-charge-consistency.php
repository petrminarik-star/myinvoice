<?php

declare(strict_types=1);

/**
 * Srovnání rozporu hlavičky a klasifikace u přijatých faktur.
 *
 * PROBLÉM (audit 2026-07, např. doklad PF2602010)
 * ------------------------------------------------
 * Položka nese klasifikaci s `is_reverse_charge = 1` (5 / 23 / 24 / 24e / 25 …),
 * ale hlavička má `reverse_charge = 0`. Vzniklo typicky AI importem PDF nebo
 * auto-defaultem (zahraniční dodavatel + 0 % → 24e/24 bez ohledu na flag).
 * Výkazy se řídí položkou, takže výstup je náhodou správný, ale data si
 * odporují: zařazení do období (DUZP vs GREATEST) a SQL fallbacky se řídí
 * hlavičkou a při jakékoli změně klasifikace se výkazy rozpadnou.
 *
 * Od této opravy nové rozpory vznikat nemají tam, kde klasifikaci vybírá
 * uživatel: Create/Update akce přijaté faktury zapnou flag, jakmile je mezi
 * VÝSLOVNĚ zadanými kódy nějaký v režimu přenesené povinnosti (a řeknou o tom
 * warningem). Doklady z importů, kde kódy vzniknou až auto-defaultem, se
 * záměrně nepřepisují — tento skript dorovná HISTORICKÉ záznamy ručně.
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Najde přijaté faktury s reverse_charge = 0, kde aspoň jedna položka (nebo
 * hlavičkový kód) má klasifikaci s is_reverse_charge = 1, a nastaví
 * reverse_charge = 1. Částky, kódy ani vat_deduction NEMĚNÍ (srovnej
 * backfill-foreign-reverse-charge.php, který řeší i kódy/deduction).
 *
 * ⚠️ Už PODANÁ období: flag mění zařazení zahraničního RC do období (DUZP místo
 *    GREATEST(DUZP, vystavení)) — u dokladů s DUZP a vystavením v různých
 *    měsících se může posunout měsíc výkazu. Skript takové doklady označí
 *    „POSUN OBDOBÍ?!" — před --apply projdi a případně omez --from/--to.
 *
 * ⚠️ VĚDOMĚ vypnutý reverse_charge: skript nerozliší kód zadaný uživatelem od
 *    kódu dosazeného defaultem (zahraniční dodavatel + 0 % → 24e/24 nezávisle
 *    na flagu). Doklad, u kterého jsi příznak schválně nechal vypnutý, by tedy
 *    přepsal — proto je dry-run výchozí; seznam před --apply projdi a sporné
 *    doklady vyřaď přes --supplier/--from/--to.
 *
 * Použití:
 *   php api/bin/backfill-reverse-charge-consistency.php                   # dry-run, vše
 *   php api/bin/backfill-reverse-charge-consistency.php --supplier=2
 *   php api/bin/backfill-reverse-charge-consistency.php --from=2026-01-01 --to=2026-06-30
 *   php api/bin/backfill-reverse-charge-consistency.php --apply           # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
$from       = null;
$to         = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) $supplierId = (int) substr($a, 11);
    if (str_starts_with($a, '--from='))     $from = substr($a, 7);
    if (str_starts_with($a, '--to='))       $to   = substr($a, 5);
}

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

// Doklady s rozporem: hlavička bez RC flagu, ale položkový/hlavičkový kód je RC
// (tenant-scoped číselník: globální seed NEBO kód daného tenanta).
$params = [];
$sql = "
    SELECT pi.id, pi.supplier_id, pi.varsymbol, pi.vendor_invoice_number, pi.status,
           pi.tax_date, pi.issue_date, pi.vat_deduction, pi.vat_classification_code AS header_code,
           c.company_name AS vendor, COALESCE(co.iso2,'CZ') AS iso2,
           (SELECT GROUP_CONCAT(DISTINCT pii.vat_classification_code)
              FROM purchase_invoice_items pii
              JOIN vat_classifications vc
                ON vc.code = pii.vat_classification_code
               AND vc.archived = 0 AND vc.is_reverse_charge = 1
               AND (vc.supplier_id IS NULL OR vc.supplier_id = pi.supplier_id)
             WHERE pii.purchase_invoice_id = pi.id) AS rc_item_codes
      FROM purchase_invoices pi
      JOIN clients c    ON c.id  = pi.vendor_id
 LEFT JOIN countries co ON co.id = c.country_id
     WHERE COALESCE(pi.reverse_charge, 0) = 0
       AND pi.status <> 'cancelled'
       AND (
             EXISTS (SELECT 1
                       FROM purchase_invoice_items pii
                       JOIN vat_classifications vc
                         ON vc.code = pii.vat_classification_code
                        AND vc.archived = 0 AND vc.is_reverse_charge = 1
                        AND (vc.supplier_id IS NULL OR vc.supplier_id = pi.supplier_id)
                      WHERE pii.purchase_invoice_id = pi.id)
          OR EXISTS (SELECT 1
                       FROM vat_classifications vc
                      WHERE vc.code = pi.vat_classification_code
                        AND vc.archived = 0 AND vc.is_reverse_charge = 1
                        AND (vc.supplier_id IS NULL OR vc.supplier_id = pi.supplier_id))
       )
";
if ($supplierId !== null) { $sql .= " AND pi.supplier_id = ?"; $params[] = $supplierId; }
if ($from !== null)       { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) >= ?"; $params[] = $from; }
if ($to !== null)         { $sql .= " AND COALESCE(pi.tax_date, pi.issue_date) <= ?"; $params[] = $to; }
$sql .= " ORDER BY pi.supplier_id, COALESCE(pi.tax_date, pi.issue_date), pi.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (!$docs) {
    echo "Žádný rozpor reverse_charge vs klasifikace nenalezen — vše konzistentní.\n";
    exit(0);
}

$upd = $pdo->prepare('UPDATE purchase_invoices SET reverse_charge = 1 WHERE id = ?');

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Doklady s RC klasifikací, ale reverse_charge = 0: " . count($docs) . "\n";
echo str_repeat('-', 118) . "\n";

$changed = 0;
foreach ($docs as $d) {
    $codes = trim((string) ($d['rc_item_codes'] ?? ''));
    if ($codes === '' && !empty($d['header_code'])) {
        $codes = 'hlavička:' . $d['header_code'];
    }
    // Flag u zahraničního RC mění zařazení do období: DUZP místo pozdějšího
    // z (DUZP, vystavení). Rozdílný měsíc DUZP vs vystavení = možný posun výkazu.
    $taxD = (string) ($d['tax_date'] ?? '');
    $issD = (string) ($d['issue_date'] ?? '');
    $periodShift = $taxD !== '' && $issD !== '' && substr($taxD, 0, 7) !== substr($issD, 0, 7)
        && $d['iso2'] !== 'CZ';
    printf("  t%-2d pi#%-5d %-11s %-11s %-20s %s rc→1 [kódy: %s]%s\n",
        $d['supplier_id'], $d['id'], (string) $d['varsymbol'],
        $taxD !== '' ? $taxD : $issD,
        mb_substr((string) $d['vendor'], 0, 20), $d['iso2'], $codes,
        $periodShift ? '  ⚠ POSUN OBDOBÍ?! (DUZP ' . $taxD . ' vs vystaveno ' . $issD . ')' : '');
    if (!$dryRun) {
        $upd->execute([(int) $d['id']]);
    }
    $changed++;
}

echo str_repeat('-', 118) . "\n";
if ($dryRun) {
    echo "DRY-RUN — nic nezapsáno. Pro zápis přidej --apply (u dokladů s POSUN OBDOBÍ?! zvaž --from/--to kvůli už podaným obdobím).\n";
} else {
    echo "Hotovo: reverse_charge = 1 nastaveno u {$changed} dokladů. Výkazy se počítají živě — žádný recompute není potřeba.\n";
}
