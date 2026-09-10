<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\CzechWorkingDays;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Posun termínů podání na pracovní den (§ 33 odst. 4 daňového řádu)
 * + výpočet českých svátků vč. pohyblivých Velikonoc.
 */
final class CzechWorkingDaysTest extends TestCase
{
    /** @return list<array{0:string, 1:string, 2:string}> [vstup, očekávaný posun, popis] */
    public static function shiftProvider(): array
    {
        return [
            // Motivační případ opravy: KH 06/2026 — 25.07.2026 je sobota.
            ['2026-07-25', '2026-07-27', 'sobota → pondělí'],
            ['2026-07-26', '2026-07-27', 'neděle → pondělí'],
            ['2026-07-27', '2026-07-27', 'pracovní den se nemění'],
            // 25.10.2026 = neděle, 26.10. pondělí pracovní.
            ['2026-10-25', '2026-10-26', 'neděle → pondělí'],
            // 28.10. (státní svátek) 2026 připadá na středu.
            ['2026-10-28', '2026-10-29', 'svátek uprostřed týdne → čtvrtek'],
            // Vánoce 2026: 25.12. pátek (svátek), 26.12. sobota (svátek), 27.12. neděle.
            ['2026-12-25', '2026-12-28', 'vánoční řetěz svátků a víkendu → pondělí'],
            // 1.1.2027 pátek (svátek) → pondělí 4.1.
            ['2027-01-01', '2027-01-04', 'Nový rok pátek → pondělí'],
            // Velikonoce 2026: neděle 5.4. → Velký pátek 3.4., pondělí 6.4.
            ['2026-04-03', '2026-04-07', 'Velký pátek → úterý po Velikonočním pondělí'],
            ['2026-04-06', '2026-04-07', 'Velikonoční pondělí → úterý'],
            // Běžný 25. v pracovní den — bez posunu (KH 05/2026, pondělí).
            ['2026-05-25', '2026-05-25', 'pondělí beze změny'],
        ];
    }

    #[DataProvider('shiftProvider')]
    public function testShiftToWorkingDay(string $input, string $expected, string $label): void
    {
        $shifted = CzechWorkingDays::shiftToWorkingDay(new \DateTimeImmutable($input));
        $this->assertSame($expected, $shifted->format('Y-m-d'), $label);
    }

    public function testEasterSunday(): void
    {
        // Referenční data Velikonoční neděle (gregoriánský kalendář).
        $this->assertSame('2024-03-31', CzechWorkingDays::easterSunday(2024)->format('Y-m-d'));
        $this->assertSame('2025-04-20', CzechWorkingDays::easterSunday(2025)->format('Y-m-d'));
        $this->assertSame('2026-04-05', CzechWorkingDays::easterSunday(2026)->format('Y-m-d'));
        $this->assertSame('2027-03-28', CzechWorkingDays::easterSunday(2027)->format('Y-m-d'));
        $this->assertSame('2038-04-25', CzechWorkingDays::easterSunday(2038)->format('Y-m-d'));
    }

    public function testFixedHolidays(): void
    {
        foreach (['01-01', '05-01', '05-08', '07-05', '07-06', '09-28', '10-28', '11-17', '12-24', '12-25', '12-26'] as $md) {
            $this->assertTrue(
                CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable("2026-{$md}")),
                "2026-{$md} má být svátek",
            );
        }
        $this->assertFalse(CzechWorkingDays::isPublicHoliday(new \DateTimeImmutable('2026-07-27')));
    }
}
