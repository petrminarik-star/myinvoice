<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Repository;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use PHPUnit\Framework\TestCase;

/**
 * PurchaseInvoiceRepository::defaultClassificationCode — auto-klasifikace
 * přijatých dokladů podle sazby + RC + země dodavatele, nově s parametrem
 * základní sazby z číselníku daňových konstant (dřív natvrdo 21).
 */
final class DefaultClassificationCodeTest extends TestCase
{
    public function testDomesticByRate(): void
    {
        $f = fn (float $r, bool $rc = false, ?string $iso = 'CZ') =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, $rc, $iso);

        $this->assertSame('40', $f(21.0), 'tuzemská základní');
        $this->assertSame('41', $f(12.0), 'tuzemská snížená');
        $this->assertSame('41', $f(15.0), 'historická snížená 15');
        $this->assertSame('5',  $f(21.0, rc: true), 'tuzemský RC');
        $this->assertNull($f(0.0), 'nulová sazba CZ → user vybere');
        $this->assertNull($f(19.0), 'cizí sazba (např. DE 19 %) se nemapuje');
    }

    public function testForeignVendorZeroRate(): void
    {
        $f = fn (string $iso) =>
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, false, $iso);

        // Zahraniční 0 % = reverse charge SLUŽBA (nejčastější: digitální předplatná).
        // EU → 24e (ř.5), 3. země → 24 (ř.12). Zboží (ř.3/ř.7) ze sazby nepoznáme →
        // u zboží vybírá kód AI/uživatel.
        $this->assertSame('24e', $f('DE'), 'EU vendor 0 % → služba z EU (ř.5)');
        $this->assertSame('24e', $f('IE'));
        $this->assertSame('24', $f('US'), '3. země 0 % → služba ze 3. země (ř.12)');
        $this->assertSame('24', $f('GB'));
    }

    public function testEuReverseChargeStandardRate(): void
    {
        $this->assertSame(
            '23',
            PurchaseInvoiceRepository::defaultClassificationCode(21.0, true, 'DE'),
            'EU vendor + RC + základní sazba → pořízení zboží z JČS'
        );
    }

    public function testStandardRateParameterFromTaxConstants(): void
    {
        // Hypotetická budoucí změna základní sazby na 22 % — klasifikace se musí
        // řídit parametrem (číselník daňových konstant), ne zadrátovanou 21.
        $f = fn (float $r, float $std) =>
            PurchaseInvoiceRepository::defaultClassificationCode($r, false, 'CZ', $std);

        $this->assertSame('40', $f(22.0, 22.0), 'nová základní 22 → tuzemská základní');
        $this->assertNull($f(21.0, 22.0), 'stará 21 už není základní ani snížená (16–21) → user vybere');
        $this->assertSame('41', $f(12.0, 22.0), 'snížená beze změny');
        // RC varianty respektují tentýž parametr
        $this->assertSame('5', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'CZ', 22.0));
        $this->assertSame('23', PurchaseInvoiceRepository::defaultClassificationCode(22.0, true, 'DE', 22.0));
    }

    public function testPublicAuthorityFeeStaysOutOfScope(): void
    {
        // issue #30: soudní poplatek německému soudu. Orgán veřejné moci není osobou
        // povinnou k dani (§ 5 odst. 4 ZDPH) → žádné samovyměření § 9/1, žádný kód.
        $f = fn (string $iso, bool $fee) =>
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, false, $iso, 21.0, $fee);

        $this->assertNull($f('DE', true), 'soudní poplatek zahraničnímu soudu → mimo předmět daně');
        $this->assertNull($f('CZ', true), 'tuzemský soudní poplatek → mimo předmět daně');
        $this->assertNull($f('US', true));
        $this->assertSame('24e', $f('DE', false), 'běžná EU služba se samovyměřuje dál');
    }

    public function testDomesticReverseChargeZeroRate(): void
    {
        // Tuzemský § 92a doklad je bez vyčíslené daně (0 %) — patří na ř.10 + KH B.1.
        $this->assertSame(
            '5',
            PurchaseInvoiceRepository::defaultClassificationCode(0.0, true, 'CZ'),
            'tuzemský režim přenesené povinnosti'
        );
    }

    public function testThirdCountryReverseChargeStandardRate(): void
    {
        // Dodavatel ze 3. země + RC + základní sazba → přijetí služby od neusazené osoby
        // ('24', ř. 12 + KH A.2). Dřív propadlo na tuzemský § 92a '5' (ř. 10 + KH B.1),
        // přenesenou povinnost mezi plátci v tuzemsku ale se zahraničním dodavatelem
        // uplatnit nelze.
        $this->assertSame('24', PurchaseInvoiceRepository::defaultClassificationCode(21.0, true, 'US'));
        $this->assertSame('24', PurchaseInvoiceRepository::defaultClassificationCode(21.0, true, 'GB'));
    }
}
