<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Import;

use MyInvoice\Service\Import\WalletBankTransactionImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsování Wallet recordů: VS ze zprávy pro příjemce + rozklad counterParty.
 * Vzory odpovídají reálným datům z Airbank/RB syncu (viz komentáře u metod).
 */
final class WalletRecordParsingTest extends TestCase
{
    /** @return array<string, array{0:?string, 1:?string}> */
    public static function noteProvider(): array
    {
        return [
            'faktura + číslo'        => ['Faktura 20260004', '20260004'],
            'číslo + firma'          => ['20260001 SEARGINEER S.R.O.', '20260001'],
            'explicitní VS'          => ['VS: 1234', '1234'],
            'explicitní VS bez dvojtečky' => ['platba vs 20265001 děkuji', '20265001'],
            'nová řada s.r.o.'       => ['Faktura 20265001', '20265001'],
            'bez čísla'              => ['Detem pro radost z Lysolaji', null],
            'číslo účtu se nebere'   => ['prevod 2201230338/2010', null],
            'krátké číslo (částka) se nebere' => ['záloha 5000', null],
            'datum se nebere'        => ['nájem 24.7.2026', null],
            'prázdná nota'           => ['', null],
            'null nota'              => [null, null],
        ];
    }

    #[DataProvider('noteProvider')]
    public function testVariableSymbolFromNote(?string $note, ?string $expected): void
    {
        self::assertSame($expected, WalletBankTransactionImporter::variableSymbolFromNote($note));
    }

    /** @return array<string, array{0:?string, 1:?string, 2:?string, 3:?string}> */
    public static function counterPartyProvider(): array
    {
        return [
            'jen účet'            => ['3149505123/0800', '3149505123', '0800', null],
            'jméno + účet'        => ['Kašík, František, 2201230338/2010', '2201230338', '2010', 'Kašík, František'],
            'jméno bez čárky'     => ['Sontág, Nikolas 2502819947/2010', '2502819947', '2010', 'Sontág, Nikolas'],
            'účet s prefixem'     => ['Radka Minaříková, 131-3007270277/0100', '131-3007270277', '0100', 'Radka Minaříková'],
            'karetní obchodník'   => ['OPENAI *CHATGPT SUBSCR', null, null, 'OPENAI *CHATGPT SUBSCR'],
            'jen text'            => ['Air Bank', null, null, 'Air Bank'],
            'prázdné'             => ['', null, null, null],
            'null'                => [null, null, null, null],
        ];
    }

    #[DataProvider('counterPartyProvider')]
    public function testParseCounterParty(?string $raw, ?string $account, ?string $bank, ?string $name): void
    {
        $parsed = WalletBankTransactionImporter::parseCounterParty($raw);
        self::assertSame($account, $parsed['account']);
        self::assertSame($bank, $parsed['bank']);
        self::assertSame($name, $parsed['name']);
    }

    public function testWalletCurrencyCodePrefersInitialBalance(): void
    {
        self::assertSame('CZK', WalletBankTransactionImporter::walletCurrencyCode([
            'initialBalance' => ['value' => 0.01, 'currencyCode' => 'czk'],
            'balance' => ['currencyCode' => 'EUR'],
        ]));
        self::assertSame('EUR', WalletBankTransactionImporter::walletCurrencyCode([
            'balance' => ['currencyCode' => 'EUR'],
        ]));
        self::assertNull(WalletBankTransactionImporter::walletCurrencyCode([]));
    }
}
