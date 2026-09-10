<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\TaxConstants;
use PHPUnit\Framework\TestCase;

/**
 * Zafixuje ověřené hodnoty (Finanční správa / ČSSZ / VZP, k 2026-05). Změna těchto
 * konstant musí být vědomá — proto je hlídá test.
 */
final class TaxConstantsTest extends TestCase
{
    public function testVerified2025Values(): void
    {
        $c = TaxConstants::forYear(2025);
        self::assertSame(104592, $c['pausal_annual']['band1']);  // 12× 8 716
        self::assertSame(1676052, $c['tax_high_threshold']);     // 36× prům. mzda 46 557
        self::assertSame(0.55, $c['social_assessment_pct']);
        self::assertSame(0.50, $c['health_assessment_pct']);     // ← zdravotní 50 %, ne 55 %
        self::assertSame(195540, $c['social_min_base_main']);    // 35 % × 46 557 × 12
        self::assertSame(61476, $c['social_min_base_secondary']);
        self::assertSame(111736, $c['social_secondary_participation_threshold']); // rozhodná částka vedlejší SVČ (ČSSZ)
        self::assertSame(279342, $c['health_min_base']);         // 50 % × 46 557 × 12
        self::assertSame([15204, 22320, 27840], $c['child_credits']);
    }

    public function testVerified2026Values(): void
    {
        $c = TaxConstants::forYear(2026);
        // 6× 9 984 (led–čvn) + 6× 9 162 (čvc–pro, novela od 1. 7. 2026), NE 12× 9 984
        self::assertSame(114876, $c['pausal_annual']['band1']);
        self::assertSame(200940, $c['pausal_annual']['band2']);  // 12× 16 745 — beze změny
        self::assertSame(325668, $c['pausal_annual']['band3']);  // 12× 27 139 — beze změny
        self::assertSame(1762812, $c['tax_high_threshold']);     // 36× prům. mzda 48 967
        self::assertSame(0.50, $c['health_assessment_pct']);
        self::assertSame(235044, $c['social_min_base_main']);    // 40 % × 48 967 × 12
        self::assertSame(64644, $c['social_min_base_secondary']);
        self::assertSame(117521, $c['social_secondary_participation_threshold']); // rozhodná částka vedlejší SVČ 2026 (ČSSZ)
        self::assertSame(293802, $c['health_min_base']);         // 50 % × 48 967 × 12
    }

    public function testAvailableYearsAndFallback(): void
    {
        self::assertContains(2025, TaxConstants::availableYears());
        self::assertContains(2026, TaxConstants::availableYears());
        // Neznámý budoucí rok → nejbližší předchozí známý (ne natvrdo zadrátovaný).
        self::assertSame(2026, TaxConstants::forYear(2027)['year']);
        self::assertSame(2026, TaxConstants::forYear(9999)['year']);
        // Rok před začátkem tabulky → nejstarší známý.
        self::assertSame(2025, TaxConstants::forYear(2024)['year']);
    }

    /**
     * Měsíční záloha paušální daně se může změnit uprostřed roku, roční částka je
     * proto vždy dopočítaná z rozvrhu — nikdy uložený skalár.
     */
    public function testPausalScheduleDrivesAnnualAmount(): void
    {
        $c = TaxConstants::forYear(2026);
        self::assertSame(
            [
                ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
                ['from' => '2026-07-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139],
            ],
            $c['pausal_monthly']
        );

        // 2025: jediná sazba celý rok.
        self::assertSame(
            [['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139]],
            TaxConstants::forYear(2025)['pausal_monthly']
        );
    }

    /**
     * Fallback na poslední známý rok nesmí zopakovat schod uprostřed roku — pro
     * neznámý rok platí poslední známá sazba všech 12 měsíců.
     */
    public function testFallbackYearUsesLastKnownRateWholeYear(): void
    {
        $c = TaxConstants::forYear(2027);
        self::assertSame(
            [['from' => '2027-01-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139]],
            $c['pausal_monthly']
        );
        self::assertSame(109944, $c['pausal_annual']['band1']); // 12× 9 162
    }

    /**
     * Legacy override (starší verze aplikace ukládala jen roční částky) si roční
     * hodnotu ponechá doslova — dělení dvanácti nemusí vyjít na koruny.
     */
    public function testLegacyAnnualOnlyOverrideKeepsItsAmount(): void
    {
        $c = TaxConstants::withDerived(
            ['year' => 2026, 'pausal_annual' => ['band1' => 100000, 'band2' => 200940, 'band3' => 325668]],
            2026
        );
        self::assertSame(100000, $c['pausal_annual']['band1']);
        self::assertSame(8333.33, $c['pausal_monthly'][0]['band1']);
        self::assertSame('2026-01-01', $c['pausal_monthly'][0]['from']);
    }
}
