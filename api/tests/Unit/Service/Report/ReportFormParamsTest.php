<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Report;

use MyInvoice\Service\Report\DphPriznaniBuilder;
use MyInvoice\Service\Report\KontrolniHlaseniBuilder;
use MyInvoice\Service\Report\ReportFormParams;
use PHPUnit\Framework\TestCase;

/**
 * Parsování query parametrů `form` (khdph_forma / dapdph_forma) a `d_zjist`
 * pro opravná/následná/dodatečná podání.
 */
final class ReportFormParamsTest extends TestCase
{
    public function testDefaultIsRegularForm(): void
    {
        $r = ReportFormParams::fromQuery([], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertSame(['form' => 'B', 'd_zjist' => null, 'error' => null], $r);
    }

    public function testInvalidFormRejected(): void
    {
        // 'D' (dodatečné) je forma DP3, u KH neexistuje — musí být odmítnuta.
        $r = ReportFormParams::fromQuery(['form' => 'D'], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNotNull($r['error']);

        $r = ReportFormParams::fromQuery(['form' => 'X'], DphPriznaniBuilder::FORMS, DphPriznaniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNotNull($r['error']);

        // Dodatečné DP3 formy D/E jsou vypnuté, dokud builder neumí dopočet
        // rozdílů proti poslední známé dani (§ 141/2 DŘ) — odmítají se jako
        // neplatná forma, i s vyplněným d_zjist.
        foreach (['D', 'E'] as $additional) {
            $r = ReportFormParams::fromQuery(
                ['form' => $additional, 'd_zjist' => '05.08.2026'],
                DphPriznaniBuilder::FORMS,
                DphPriznaniBuilder::FORMS_REQUIRING_DZJIST,
            );
            $this->assertNotNull($r['error'], "forma '{$additional}' musí být na DP3 odmítnuta");
            $this->assertStringContainsString('Neplatná forma', (string) $r['error']);
        }
    }

    public function testFollowUpRequiresDzjist(): void
    {
        // Následné KH (N) bez data zjištění → chyba.
        $r = ReportFormParams::fromQuery(['form' => 'N'], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNotNull($r['error']);

        // S platným datem projde.
        $r = ReportFormParams::fromQuery(['form' => 'N', 'd_zjist' => '05.08.2026'], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNull($r['error']);
        $this->assertSame('N', $r['form']);
        $this->assertSame('05.08.2026', $r['d_zjist']);
    }

    public function testCorrectiveFormAllowsOptionalDzjist(): void
    {
        // Opravné (O) datum nevyžaduje…
        $r = ReportFormParams::fromQuery(['form' => 'O'], DphPriznaniBuilder::FORMS, DphPriznaniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNull($r['error']);
        $this->assertNull($r['d_zjist']);
        // …ale zadané propustí.
        $r = ReportFormParams::fromQuery(['form' => 'O', 'd_zjist' => '01.07.2026'], DphPriznaniBuilder::FORMS, DphPriznaniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNull($r['error']);
        $this->assertSame('01.07.2026', $r['d_zjist']);
    }

    public function testDzjistFormatValidation(): void
    {
        foreach (['2026-08-05', '5.8.2026', '31.02.2026', 'nesmysl', '05/08/2026'] as $bad) {
            $r = ReportFormParams::fromQuery(['form' => 'N', 'd_zjist' => $bad], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
            $this->assertNotNull($r['error'], "'{$bad}' musí být odmítnuto (očekává se DD.MM.YYYY)");
        }
        $this->assertTrue(ReportFormParams::isValidEpoDate('29.02.2028'), 'přestupný rok je platný');
        $this->assertFalse(ReportFormParams::isValidEpoDate('29.02.2026'), 'nepřestupný rok neplatný');
    }

    public function testRegularFormDropsDzjistSilently(): void
    {
        // d_zjist u řádného podání nemá smysl → tiše zahodit, do XML se nesmí propsat.
        $r = ReportFormParams::fromQuery(['form' => 'B', 'd_zjist' => '05.08.2026'], KontrolniHlaseniBuilder::FORMS, KontrolniHlaseniBuilder::FORMS_REQUIRING_DZJIST);
        $this->assertNull($r['error']);
        $this->assertNull($r['d_zjist']);
    }
}
