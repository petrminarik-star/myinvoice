<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Tax;

use MyInvoice\Service\Tax\PausalSchedule;
use PHPUnit\Framework\TestCase;

/**
 * Rozvrh měsíčních záloh paušální daně. Referenční scénář je rok 2026: 1. pásmo
 * 9 984 Kč do června, od 1. 7. 2026 jen 9 162 Kč → ročně 114 876 Kč.
 */
final class PausalScheduleTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private function segments2026(): array
    {
        return PausalSchedule::normalize([
            ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
            ['from' => '2026-07-01', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139],
        ]);
    }

    public function testAnnualSumsTwelveMonthlyAdvances(): void
    {
        $annual = PausalSchedule::annual(2026, $this->segments2026());
        self::assertSame(114876, $annual['band1']); // 6× 9 984 + 6× 9 162
        self::assertSame(200940, $annual['band2']);
        self::assertSame(325668, $annual['band3']);
    }

    public function testMonthlyAtPicksSegmentEffectiveForMonth(): void
    {
        $s = $this->segments2026();
        self::assertSame(9984, PausalSchedule::monthlyAt(2026, 6, $s)['band1']);
        self::assertSame(9162, PausalSchedule::monthlyAt(2026, 7, $s)['band1']);
        self::assertSame(9162, PausalSchedule::monthlyAt(2026, 12, $s)['band1']);
    }

    /** Rok mimo rozsah rozvrhu: dřívější → první sazba, pozdější → poslední × 12. */
    public function testYearOutsideScheduleRange(): void
    {
        $s = $this->segments2026();
        self::assertSame(9984, PausalSchedule::monthlyAt(2025, 8, $s)['band1']);
        self::assertSame(109944, PausalSchedule::annual(2027, $s)['band1']); // 12× 9 162
    }

    public function testBreakdownGroupsMonthsWithSameRate(): void
    {
        $b = PausalSchedule::breakdown(2026, $this->segments2026());
        self::assertCount(2, $b);
        self::assertSame(['2026-01-01', '2026-06-30', 6, 9984], [$b[0]['from'], $b[0]['to'], $b[0]['months'], $b[0]['band1']]);
        self::assertSame(['2026-07-01', '2026-12-31', 6, 9162], [$b[1]['from'], $b[1]['to'], $b[1]['months'], $b[1]['band1']]);
        self::assertSame(12, array_sum(array_column($b, 'months')));
    }

    /** Rok bez změny sazby = jediné období přes celý rok. */
    public function testBreakdownSingleRate(): void
    {
        $b = PausalSchedule::breakdown(2025, PausalSchedule::normalize([
            ['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139],
        ]));
        self::assertCount(1, $b);
        self::assertSame(12, $b[0]['months']);
        self::assertSame('2025-12-31', $b[0]['to']);
    }

    public function testNormalizeSortsDeduplicatesAndDropsInvalid(): void
    {
        $s = PausalSchedule::normalize([
            ['from' => '2026-07-15', 'band1' => 9162, 'band2' => 16745, 'band3' => 27139], // → 2026-07-01
            ['from' => '2026-01-01', 'band1' => 9984, 'band2' => 16745, 'band3' => 27139],
            ['from' => 'nesmysl', 'band1' => 1, 'band2' => 2, 'band3' => 3],               // zahodit
            ['from' => '2026-04-01', 'band1' => 1],                                        // neúplné → zahodit
        ]);
        self::assertSame(['2026-01-01', '2026-07-01'], array_column($s, 'from'));
    }

    public function testFromAnnualSplitsLegacyValueIntoFlatSegment(): void
    {
        $s = PausalSchedule::fromAnnual(2025, ['band1' => 104592, 'band2' => 200940, 'band3' => 325668]);
        self::assertSame([['from' => '2025-01-01', 'band1' => 8716, 'band2' => 16745, 'band3' => 27139]], $s);
    }

    public function testEmptyScheduleIsSafe(): void
    {
        self::assertSame([], PausalSchedule::normalize('nesmysl'));
        self::assertSame(['band1' => 0, 'band2' => 0, 'band3' => 0], PausalSchedule::annual(2026, []));
    }
}
