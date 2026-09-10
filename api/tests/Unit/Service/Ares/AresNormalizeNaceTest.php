<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Service\Ares\AresClient;
use PHPUnit\Framework\TestCase;

/**
 * CZ-NACE prefill z ARES (BUG 7): bere se NEJDELŠÍ kód z czNace (bez „00");
 * je-li i ten kratší než 4 číslice (jen sekce/oddíl, např. „620" = 3 znaky,
 * „74" = 2), cz_nace_code zůstává prázdné + cz_nace_note vysvětlí proč —
 * číselník MFČR pro c_okec takový kód nezná (EPO propustná chyba 30).
 * Výsledek se kanonizuje proti snapshotu číselníku ČINNOSTI (73110 → 731100).
 *
 * Zachovaná regrese: jediná NACE jako numerický string se v PHP poli stával
 * int klíčem a funkce vracela int → TypeError (#76b). Vše musí být string.
 */
final class AresNormalizeNaceTest extends TestCase
{
    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function normalize(array $raw): array
    {
        $m = new \ReflectionMethod(AresClient::class, 'normalize');
        $obj = (new \ReflectionClass(AresClient::class))->newInstanceWithoutConstructor();
        return $m->invoke($obj, $raw);
    }

    public function testDivisionOnlyLeavesEmptyWithNote(): void
    {
        // „620" má jen 3 číslice (skupina bez třídy) → neprefillovat, jen poznámka.
        $n = $this->normalize(['czNace' => ['620'], 'pravniForma' => '101']);
        self::assertIsString($n['cz_nace_code']);
        self::assertSame('', $n['cz_nace_code']);
        self::assertStringContainsString('oddíl NACE 620', (string) $n['cz_nace_note']);
        self::assertSame('fo', $n['taxpayer_type']);
    }

    public function testPlaceholderSkippedDivisionStillEmpty(): void
    {
        $n = $this->normalize(['czNace' => ['00', '620']]);
        self::assertSame('', $n['cz_nace_code']);
        self::assertNotSame('', $n['cz_nace_note']);
    }

    public function testLongestCodeWinsAndIsPaddedToSix(): void
    {
        // Kratší položky (62, 46) jsou jen sekce/oddíly — vyhrává nejdelší 73110 → 731100.
        $n = $this->normalize(['czNace' => ['62', '73110', '00', '46']]);
        self::assertSame('731100', $n['cz_nace_code']);
        self::assertSame('', $n['cz_nace_note']);
    }

    public function testClassCodeIsPadded(): void
    {
        $n = $this->normalize(['czNace' => ['7311']]);
        self::assertSame('731100', $n['cz_nace_code']);
    }

    public function testMissingNaceEmpty(): void
    {
        $n = $this->normalize(['pravniForma' => '112']);
        self::assertSame('', $n['cz_nace_code']);
        self::assertSame('', $n['cz_nace_note']);
        self::assertSame('po', $n['taxpayer_type']);
    }
}
