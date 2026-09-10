<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Roční daňové konstanty (CZ) — referenční DEFAULTY / fallback.
 *
 * V produkci se čtou přes {@see \MyInvoice\Repository\TaxConstantsRepository},
 * který vrací admin override z tabulky `tax_constants` (migrace 0079), a teprve
 * když override pro daný rok není, spadne na tyhle hodnoty. Tahle třída je tedy
 * jediný zdroj výchozích čísel v kódu (a fallback pro testy/CLI bez DB).
 *
 * Hodnoty ověřeny k 2026-07 dle Finanční správy / ČSSZ / VZP:
 *  - paušální daň 2025 8 716/16 745/27 139 Kč/měs; 2026 9 984/16 745/27 139 Kč/měs
 *    do června, od 1. 7. 2026 klesá 1. pásmo na 9 162 Kč/měs (2. a 3. beze změny —
 *    na minimum OSVČ je navázaná jen důchodová složka 1. pásma)
 *  - průměrná mzda 2025 46 557 Kč, 2026 48 967 Kč → hranice 23 % = 36×
 *  - vyměřovací základ: sociální 55 % zisku, zdravotní 50 % zisku (§7)
 *  - min. roční vyměřovací základ: soc. hlavní 35 % (2025) / 40 % (2026) prům. mzdy,
 *    zdravotní 50 % prům. mzdy × 12
 */
final class TaxConstants
{
    /**
     * Konstanty pro daný rok; neznámý rok spadne na nejbližší předchozí známý
     * (budoucí roky tak dostanou poslední ověřené hodnoty, ne natvrdo zadrátovaný
     * rok), rok před začátkem tabulky na nejstarší známý.
     *
     * `pausal_annual` se dopočítá z rozvrhu měsíčních záloh pro POŽADOVANÝ rok
     * ({@see PausalSchedule}) — u fallbacku tím vyjde „poslední platná sazba × 12".
     * @return array<string, mixed>
     */
    public static function forYear(int $year): array
    {
        if (isset(self::TABLE[$year])) {
            return self::withDerived(self::TABLE[$year], $year);
        }
        $known = self::availableYears();
        $below = array_filter($known, static fn (int $y): bool => $y < $year);
        return self::withDerived(self::TABLE[$below !== [] ? max($below) : min($known)], $year);
    }

    /**
     * Doplní odvozené klíče (dnes jen `pausal_annual` z `pausal_monthly`).
     * Voláno i repository po sloučení s DB override, aby uložená roční částka
     * nemohla přebít měsíční rozvrh.
     *
     * @param array<string, mixed> $constants
     * @return array<string, mixed>
     */
    public static function withDerived(array $constants, int $year): array
    {
        $segments = PausalSchedule::normalize($constants['pausal_monthly'] ?? []);
        if ($segments === []) {
            // Legacy override bez rozvrhu: roční částku ber doslova, měsíční
            // hodnoty jen dopočti pro zobrazení.
            $constants['pausal_monthly'] = PausalSchedule::fromAnnual($year, $constants['pausal_annual'] ?? []);
            return $constants;
        }
        // Rozvrh se ukotví k požadovanému roku: segmenty z jiného roku (fallback na
        // poslední známý rok) se srazí na jedinou sazbu platnou k 1. 1. — pro budoucí
        // rok platí poslední známá záloha celý rok, ne zopakovaný schod uprostřed roku.
        $anchored = [['from' => sprintf('%04d-01-01', $year)] + PausalSchedule::monthlyAt($year, 1, $segments)];
        foreach ($segments as $seg) {
            if (substr((string) $seg['from'], 0, 4) === (string) $year) {
                $anchored[] = $seg;
            }
        }
        $segments = PausalSchedule::normalize($anchored);

        $constants['pausal_monthly'] = $segments;
        $constants['pausal_annual']  = PausalSchedule::annual($year, $segments);
        return $constants;
    }

    public static function availableYears(): array
    {
        return array_keys(self::TABLE);
    }

    private const TABLE = [
        2025 => [
            'year' => 2025,
            // Paušální daň — měsíční záloha dle pásma; segment platí od `from`,
            // dokud ho nevystřídá další. Roční částka (`pausal_annual`) je odvozená.
            'pausal_monthly' => [
                ['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139],
            ],
            // Stropy pásem dle příjmu × výdajového paušálu (§7a ZDP).
            // Klíč = sazba výdajového paušálu; hodnota = strop pro [band1, band2, band3].
            // Pozn.: SummaryAction::FLAT_TAX_BANDS drží zjednodušenou (činnost-neutrální)
            // variantu týchž stropů; sjednotit dashboard na tento zdroj je follow-up.
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            // Slevy a zvýhodnění
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840], // 1., 2., 3.+ dítě (3.+ se opakuje)
            // Daň z příjmu
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1676052, // 36× průměrné mzdy 2025 (46 557)
            // Pojistné — sazby a vyměřovací základy (% ze zisku §7)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55, // sociální: 55 % zisku
            'health_assessment_pct' => 0.50, // zdravotní: 50 % zisku
            'social_min_base_main'      => 195540, // 35 % × 46 557 × 12
            'social_min_base_secondary' => 61476,  // min. roční zákl. vedlejší činnost
            // Rozhodná částka (daňový základ / zisk) pro povinnou účast na důchodovém
            // pojištění u vedlejší SVČ — pod ní se sociální pojištění neplatí (ČSSZ).
            'social_secondary_participation_threshold' => 111736, // 2025
            'health_min_base'           => 279342, // 50 % × 46 557 × 12
            // Výdajové paušály — strop uplatnitelných výdajů dle sazby
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            // Odpočty — stropy
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            // DPH — platí pro VŠECHNY plátce (nejen OSVČ)
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
            'vat_rate_standard' => 21.0,  // základní sazba § 47 ZDPH
            'vat_rate_reduced'  => 12.0,  // snížená sazba (od 2024 jednotná 12 %)
            'kh_item_threshold' => 10000, // limit KH: nad → A.4/B.2 jednotlivě, do → A.5/B.3 sumace
        ],
        2026 => [
            'year' => 2026,
            // Od 1. 7. 2026 klesá záloha 1. pásma z 9 984 na 9 162 Kč (novela zákona
            // o minimálním pojistném OSVČ). Roční částka 1. pásma tak vychází
            // 6× 9 984 + 6× 9 162 = 114 876 Kč, ne 12× 9 984.
            'pausal_monthly' => [
                ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
                ['from' => '2026-07-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139],
            ],
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840],
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1762812, // 36× průměrné mzdy 2026 (48 967)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55,
            'health_assessment_pct' => 0.50,
            'social_min_base_main'      => 235044, // 40 % × 48 967 × 12
            'social_min_base_secondary' => 64644,  // min. roční zákl. vedlejší činnost
            'social_secondary_participation_threshold' => 117521, // 2026 (ČSSZ)
            'health_min_base'           => 293802, // 50 % × 48 967 × 12
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
            'vat_rate_standard' => 21.0,
            'vat_rate_reduced'  => 12.0,
            'kh_item_threshold' => 10000,
        ],
    ];
}
