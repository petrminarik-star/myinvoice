<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\FakturoidClient;
use PHPUnit\Framework\TestCase;

/**
 * `download_url` přílohy je cizí vstup z API odpovědi a stahuje se s Authorization
 * hlavičkou — na cizí host se proto request nesmí odeslat (únik credentials).
 */
final class FakturoidAttachmentUrlGuardTest extends TestCase
{
    /** Reálný tvar z API v3: https://app.fakturoid.cz/api/v3/accounts/{slug}/expenses/{id}/attachments/{aid}/download */
    public function testAcceptsFakturoidHttpsUrl(): void
    {
        self::assertTrue(FakturoidClient::isFakturoidUrl(
            'https://app.fakturoid.cz/api/v3/accounts/applecorp/expenses/9/attachments/1/download'
        ));
        self::assertTrue(FakturoidClient::isFakturoidUrl('https://fakturoid.cz/download/1'));
        self::assertTrue(FakturoidClient::isFakturoidUrl('https://APP.Fakturoid.CZ/download/1'));
    }

    public function testRejectsForeignHost(): void
    {
        self::assertFalse(FakturoidClient::isFakturoidUrl('https://evil.example.com/download/1'));
        // Prefixové/suffixové triky s doménou
        self::assertFalse(FakturoidClient::isFakturoidUrl('https://fakturoid.cz.evil.example.com/download/1'));
        self::assertFalse(FakturoidClient::isFakturoidUrl('https://notfakturoid.cz/download/1'));
    }

    public function testRejectsNonHttpsAndGarbage(): void
    {
        self::assertFalse(FakturoidClient::isFakturoidUrl('http://app.fakturoid.cz/download/1'));
        self::assertFalse(FakturoidClient::isFakturoidUrl('file:///c:/secret.pdf'));
        self::assertFalse(FakturoidClient::isFakturoidUrl('/api/v3/accounts/x/download'));
        self::assertFalse(FakturoidClient::isFakturoidUrl(''));
    }
}
