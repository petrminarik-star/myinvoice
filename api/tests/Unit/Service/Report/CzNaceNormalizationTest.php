<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\EpoSupplierBlockBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Normalizace CZ-NACE (c_okec) — BUG 7.
 *
 * Kanonizace proti snapshotu číselníku ČINNOSTI (EpoOkecCodebook): zápis dle
 * ČSÚ se dohledá doplněním nul zprava (73.11 → 731100, 62020 → 620200),
 * kanonické hodnoty číselníku — vč. kódů sekcí 01–09, které číselník ukládá
 * numericky BEZ vodicí nuly (01.48.00 → „14800") — projdou beze změny.
 * ARES u řady subjektů eviduje jen oddíl (2 číslice, např. „74") — ten se
 * NESMÍ uložit ani odeslat (EPO propustná chyba 30). Platnost kódu
 * (expirace při přechodu na NACE rev. 2.1) testuje EpoOkecCodebookTest.
 */
final class CzNaceNormalizationTest extends TestCase
{
    /** @return list<array{0:string, 1:?string}> [vstup, očekávaný výsledek uložení] */
    public static function saveProvider(): array
    {
        return [
            ['74',     null],       // oddíl z ARES → validační chyba (neukládat)
            ['00',     null],       // placeholder → validační chyba
            ['7',      null],
            ['73.11',  '731100'],   // třída s tečkou → strip + pad
            ['7311',   '731100'],   // 4místná třída → pad na 6
            ['731100', '731100'],   // 6místný beze změny
            ['62.01',  '620100'],
            ['62020',  '620200'],   // 5místný → doplnit jednu nulu
            ['620900', '620900'],
            ['73 11',  '731100'],   // mezery se odstraní
            ['7311001', '731100'],  // delší se ořeže na 6
            ['14800',  '14800'],    // kanonická hodnota číselníku (01.48.00 bez vodicí nuly) beze změny
            ['01480',  '14800'],    // ČSÚ zápis s vodicí nulou → kanonická podoba číselníku
            ['0148',   '14800'],    // třída sekce 01 → kanonická podoba (ne „014800")
            ['999999', '999999'],   // kód mimo číselník projde (jen warning, viz EpoOkecCodebookTest)
            ['',       ''],         // prázdné = smazání pole (povolené)
            ['   ',    ''],
        ];
    }

    #[DataProvider('saveProvider')]
    public function testNormalizeCzNaceInput(string $input, ?string $expected): void
    {
        self::assertSame($expected, EpoSupplierBlockBuilder::normalizeCzNaceInput($input));
    }

    public function testNormalizeOkecOmitsTooShortCodes(): void
    {
        // Build-time: oddíl („74") se do XML NEPROPÍŠE (c_okec je optional;
        // odeslání oddílu by vyvolalo chybu 30) — vrací null = atribut vynechat.
        self::assertNull(EpoSupplierBlockBuilder::normalizeOkec('74'));
        self::assertNull(EpoSupplierBlockBuilder::normalizeOkec(''));
        self::assertSame('731100', EpoSupplierBlockBuilder::normalizeOkec('73.11'));
        self::assertSame('620200', EpoSupplierBlockBuilder::normalizeOkec('62020'));
        self::assertSame('620100', EpoSupplierBlockBuilder::normalizeOkec('62.01'));
    }
}
