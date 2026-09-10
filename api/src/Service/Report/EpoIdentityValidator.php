<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Kontrola úplnosti identifikace daňového subjektu PŘED generováním EPO XML
 * (DPHKH1 / DPHDP3 / DPHSHV).
 *
 * EpoSupplierBlockBuilder staví atributy VetaP podmíněně (if !empty) — když
 * pole v nastavení chybí, atribut se prostě vynechá a vznikne validně
 * vypadající XML, které ale EPO portál odmítne. Uživatel to zjistil až na
 * Moje daně, typicky v den lhůty. Tahle služba chybějící pole pojmenuje
 * PŘEDEM.
 *
 * Dělení kopíruje EPO schémata (api/xsd/*.xsd): pole s `use="required"`
 * blokují STAŽENÍ XML (HTTP 422 z download akcí), pole s `use="optional"`
 * jsou jen doporučená a projeví se jako warningy. NÁHLED výkazu se
 * neblokuje nikdy — čísla si uživatel musí umět zobrazit i s neúplným
 * nastavením (třeba proto, že je opisuje do formuláře na EPO webu).
 *
 * Záměrně NEsahá do builderů (KontrolniHlaseniBuilder/DphPriznaniBuilder
 * zůstávají beze změny) — volá se z akcí před buildem.
 */
final class EpoIdentityValidator
{
    /** Podporované kódy výkazů (ovlivňují jen doporučená pole). */
    public const DOC_DPHKH1 = 'dphkh1';
    public const DOC_DPHDP3 = 'dphdp3';
    public const DOC_DPHSHV = 'dphshv';

    public function __construct(private readonly \MyInvoice\Infrastructure\Database\Connection $db) {}

    /**
     * Kompletní kontrola pro report akce: načte supplier row a vrátí chybějící
     * povinná pole, strukturovaná doporučená pole i lidsky čitelné warningy.
     * Akce tak přidává jedinou závislost a tři řádky kódu.
     *
     * @return array{missing: list<array{field:string, label:string, why:string}>, recommended: list<array{field:string, label:string, why:string}>, warnings: list<string>}
     */
    public function forSupplier(int $supplierId, string $doc): array
    {
        $supplier = $this->loadSupplier($supplierId);
        if ($supplier === null) {
            // neexistující tenant řeší builder 404/výjimkou
            return ['missing' => [], 'recommended' => [], 'warnings' => []];
        }
        return [
            'missing'     => $this->validate($supplier, $doc),
            'recommended' => $this->recommended($supplier, $doc),
            'warnings'    => $this->recommendedWarnings($supplier, $doc),
        ];
    }

    /** @return array<string,mixed>|null */
    private function loadSupplier(int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT financial_office_code, workplace_code, dic, taxpayer_type, email,
                    phone, cz_nace_code, opr_jmeno, opr_prijmeni, opr_postaveni
               FROM supplier
              WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Chybějící POVINNÁ pole — neprázdný výsledek znamená, že XML nemá smysl
     * generovat, protože ho odmítne už XSD validace schématu.
     *
     * Sada odpovídá atributům, které mají v EPO schématech `use="required"`
     * (ověřeno v api/xsd/dphdp3.xsd, dphkh1.xsd i dphshv.xsd): kód FÚ (c_ufo),
     * DIČ a typ daňového subjektu (typ_ds, odvozený z typu poplatníka).
     * ÚzP (c_pracufo), e-mail a oprávněná osoba (opr_*) jsou v XSD `optional`,
     * takže je pouze doporučujeme — viz recommended(). Nechceme tvrdě blokovat
     * na polích, u kterých nemáme doloženo, že je runtime kontrola EPO opravdu
     * vyžaduje (stejná logika jako u CZ-NACE, viz issue #157).
     *
     * @param array<string,mixed> $supplier řádek `supplier` (loadSupplier builderů)
     * @param string $doc DOC_* konstanta (required pole se mezi výkazy neliší)
     * @return list<array{field:string, label:string, why:string}>
     */
    public function validate(array $supplier, string $doc): array
    {
        $missing = [];
        $add = static function (string $field, string $label, string $why) use (&$missing): void {
            $missing[] = ['field' => $field, 'label' => $label, 'why' => $why];
        };

        if (self::blank($supplier['financial_office_code'] ?? null)) {
            $add('financial_office_code', 'Kód finančního úřadu',
                'Atribut c_ufo má v EPO schématu use="required" — bez něj podání neprojde ani XSD validací.');
        }
        if (self::blank($supplier['dic'] ?? null)) {
            $add('dic', 'DIČ',
                'Kmenová část DIČ je ve VetaP povinná (use="required") — identifikace plátce.');
        }
        if (self::blank($supplier['taxpayer_type'] ?? null)) {
            $add('taxpayer_type', 'Typ poplatníka (FO/PO)',
                'Určuje povinný atribut typ_ds (F/P) — bez něj ho builder neumí správně vyplnit.');
        }

        return $missing;
    }

    /**
     * Chybějící DOPORUČENÁ pole — generování ani stažení XML neblokují, jen se
     * propíší do warnings[] náhledu.
     *
     * Patří sem všechno, co je v EPO schématech `use="optional"`: ÚzP
     * (c_pracufo), e-mail a u PO oprávněná osoba (opr_*) — plus telefon
     * (c_telef) a CZ-NACE (c_okec) u DPHDP3. Runtime kontroly EPO můžou být
     * přísnější než schéma, ale dokud to nemáme doložené konkrétním odmítnutým
     * podáním, uživatele nezastavujeme — jen ho upozorníme.
     *
     * @param array<string,mixed> $supplier
     * @return list<array{field:string, label:string, why:string, kind?:string}>
     *         kind='invalid' = pole je vyplněné, ale hodnota nesedí (expirovaný/neznámý
     *         kód číselníku); jinak jde o chybějící pole.
     */
    public function recommended(array $supplier, string $doc): array
    {
        $out = [];
        if (self::blank($supplier['workplace_code'] ?? null)) {
            $out[] = ['field' => 'workplace_code', 'label' => 'Kód územního pracoviště (ÚzP)',
                      'why' => 'Atribut c_pracufo (v XSD optional) určuje územní pracoviště FÚ — najdeš ho na mojedane.gov.cz nebo v hlavičce dřívějšího podání.'];
        }
        // DPHSHV atribut `email` ve schématu vůbec nemá — tam ho nevyžadujeme
        // ani nedoporučujeme (builder ho do souhrnného hlášení neemituje).
        if ($doc !== self::DOC_DPHSHV && self::blank($supplier['email'] ?? null)) {
            $out[] = ['field' => 'email', 'label' => 'E-mail',
                      'why' => 'Kontakt pro finanční úřad (atribut email, v XSD optional) — bez něj se FÚ nemá kam obrátit s výzvou.'];
        }
        if (($supplier['taxpayer_type'] ?? null) === 'po') {
            $oprWhy = 'U právnické osoby se očekává oprávněná osoba k podpisu (jednatel apod.); atributy opr_* jsou v XSD optional, ale FÚ je u PO fakticky vyžaduje.';
            foreach ([
                'opr_jmeno'     => 'Jméno oprávněné osoby',
                'opr_prijmeni'  => 'Příjmení oprávněné osoby',
                'opr_postaveni' => 'Postavení oprávněné osoby (funkce)',
            ] as $field => $label) {
                if (self::blank($supplier[$field] ?? null)) {
                    $out[] = ['field' => $field, 'label' => $label, 'why' => $oprWhy];
                }
            }
        }
        // Stejně jako e-mail: DPHSHV atribut c_telef ve schématu nemá a builder
        // ho tam neemituje (fillVetaP s includeContact: false).
        if ($doc !== self::DOC_DPHSHV && self::blank($supplier['phone'] ?? null)) {
            $out[] = ['field' => 'phone', 'label' => 'Telefon',
                      'why' => 'Doporučený kontakt pro FÚ (atribut c_telef) — urychluje řešení výzev.'];
        }
        if ($doc === self::DOC_DPHDP3) {
            // BUG 7: warning i pro NEÚPLNÝ kód (jen oddíl z ARES, např. „74") —
            // builder ho do XML nepropíše (chyba 30 by podání nezastavila, ale
            // FÚ ji hlásí jako propustnou chybu a žádá opravu). Kompletní kód
            // se navíc ověřuje proti snapshotu číselníku ČINNOSTI: expirovaný
            // (přechod na NACE rev. 2.1 k 1. 1. 2026) nebo neznámý kód build
            // neblokuje, ale uživatel se to dozví dopředu, ne až z chyby 30.
            $nace = (string) ($supplier['cz_nace_code'] ?? '');
            $naceDigits = preg_replace('/\D/', '', $nace) ?? '';
            if (self::blank($nace) || strlen($naceDigits) < 4) {
                $out[] = ['field' => 'cz_nace_code', 'label' => 'CZ-NACE kód',
                          'why' => 'c_okec se v přiznání nevyplní a EPO nahlásí propustnou chybu 30 („Hlavní ekonomická činnost by měla odpovídat nějaké hodnotě z číselníku"). Doplň kód třídy dle číselníku, např. 731100.'];
            } else {
                $resolved = EpoOkecCodebook::normalize($nace);
                if ($resolved !== null && $resolved['status'] === EpoOkecCodebook::STATUS_EXPIRED) {
                    $out[] = ['field' => 'cz_nace_code', 'label' => 'CZ-NACE kód', 'kind' => 'invalid',
                              'why' => sprintf(
                                  'Kód %s (%s) měl v číselníku EPO platnost do %s — číselník přešel na NACE rev. 2.1. EPO nahlásí propustnou chybu 30; vyber v Nastavení aktuální kód činnosti.',
                                  $resolved['code'],
                                  mb_strtolower((string) $resolved['name']),
                                  (new \DateTimeImmutable((string) $resolved['valid_to']))->format('j. n. Y')
                              )];
                } elseif ($resolved !== null && $resolved['status'] === EpoOkecCodebook::STATUS_UNKNOWN) {
                    $out[] = ['field' => 'cz_nace_code', 'label' => 'CZ-NACE kód', 'kind' => 'invalid',
                              'why' => sprintf(
                                  'Kód %s není ve snapshotu číselníku EPO — pokud nejde o novou hodnotu číselníku, EPO nahlásí propustnou chybu 30.',
                                  $resolved['code']
                              )];
                }
            }
        }
        return $out;
    }

    /**
     * Doporučená pole jako lidsky čitelné warning řetězce pro summary/preview.
     *
     * @param array<string,mixed> $supplier
     * @return list<string>
     */
    public function recommendedWarnings(array $supplier, string $doc): array
    {
        return array_map(
            static fn (array $r): string => ($r['kind'] ?? 'missing') === 'invalid'
                ? "Daňové nastavení — pole „{$r['label']}“: {$r['why']}"
                : "V daňovém nastavení chybí doporučené pole „{$r['label']}“ — {$r['why']}",
            $this->recommended($supplier, $doc),
        );
    }

    private static function blank(mixed $v): bool
    {
        return $v === null || trim((string) $v) === '';
    }
}
