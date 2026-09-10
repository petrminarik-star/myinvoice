<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Update;

use MyInvoice\Service\Update\ReleaseChannel;
use MyInvoice\Service\Update\TargetRequirements;
use PHPUnit\Framework\TestCase;

/**
 * Přechod na nástupce musí umřít v preflightu, ne uprostřed migrací.
 *
 * MyÚčto chce novější PHP i MariaDB než MyInvoice. Když prostředí nestačí,
 * swap se povede (soubory se kopírovat dají vždycky), ale migrace spadnou —
 * a instalace zůstane s vyměněným kódem nad starým schématem, tedy rozbitá.
 * Jediná cesta zpátky je obnova zálohy, takže se to musí zjistit dřív, než se
 * sáhne na první soubor.
 */
final class TargetRequirementsTest extends TestCase
{
    /**
     * Kanál běžné aktualizace požadavky neověřuje — aplikace zrovna běží,
     * takže je prostředí z definice splňuje. Nesmí tedy nic blokovat ani na
     * stroji, kde by laťka nástupce neprošla.
     */
    public function testSameProductChannelChecksNothing(): void
    {
        $result = TargetRequirements::check(ReleaseChannel::myinvoice(), __DIR__);

        self::assertSame([], $result['blockers']);
    }

    public function testSuccessorChannelDeclaresItsRequirements(): void
    {
        $channel = ReleaseChannel::myucto();

        self::assertSame('8.5.0', $channel->minPhpVersion);
        self::assertSame('11.8', $channel->minMariaDbVersion);
        self::assertContains('bcmath', $channel->requiredExtensions);
        self::assertTrue(
            $channel->isSuccessorHandover,
            'Přechod na MyÚčto musí být označený jako výměna produktu — podle toho se po '
            . 'migracích vypíná účetnictví, které instalace z MyInvoice nikdy nevedla.'
        );
    }

    /**
     * Kontrola databáze se vrací pod id `db_version` a Docker větev
     * {@see \MyInvoice\Service\Upgrade\MyuctoUpgradeService::preflight()} podle
     * toho id vybírá jedinou kontrolu, která tam dává smysl — PHP a rozšíření
     * si nástupce přiveze ve vlastním image, ale db kontejner zůstává původní.
     * Přejmenování id by tu kontrolu tiše ztratilo.
     */
    public function testDatabaseCheckKeepsIdTheDockerBranchFiltersOn(): void
    {
        $ids = array_column(TargetRequirements::check(ReleaseChannel::myucto(), __DIR__)['checks'], 'id');

        self::assertContains('db_version', $ids);
    }

    /**
     * Chybějící rozšíření je blocker, ne varování: bez něj nástupce nenaběhne
     * a „zkusíme to a uvidíme" končí půlkou nasazené aplikace.
     */
    public function testMissingExtensionBlocks(): void
    {
        $channel = self::channelRequiring(['naprosto_neexistujici_rozsireni']);

        $result = TargetRequirements::check($channel, __DIR__);

        self::assertNotSame([], $result['blockers']);
        self::assertStringContainsString('naprosto_neexistujici_rozsireni', $result['blockers'][0]);
    }

    public function testTooOldPhpBlocks(): void
    {
        $channel = self::channelRequiring([], minPhp: '99.0.0');

        $result = TargetRequirements::check($channel, __DIR__);

        self::assertNotSame([], $result['blockers']);
        self::assertStringContainsString('PHP 99.0.0', $result['blockers'][0]);
    }

    /**
     * Konstruktor kanálu je private (kanály jsou dva pojmenované), takže si
     * testovací variantu poskládáme reflexí — jinak by se kvůli testu musela
     * do produkčního kódu přidat továrna, kterou nikdo jiný nepotřebuje.
     *
     * @param list<string> $extensions
     */
    private static function channelRequiring(array $extensions, ?string $minPhp = null): ReleaseChannel
    {
        $ref = new \ReflectionClass(ReleaseChannel::class);
        /** @var ReleaseChannel $channel */
        $channel = $ref->newInstanceWithoutConstructor();

        foreach ([
            'repo'                => 'radekhulan/myucto',
            'bundlePrefix'        => 'myucto',
            'productName'         => 'MyÚčto.cz',
            'requiresDatabaseBackup' => true,
            'minPhpVersion'       => $minPhp,
            'minMariaDbVersion'   => null,
            'requiredExtensions'  => $extensions,
            'isSuccessorHandover' => true,
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setValue($channel, $value);
        }

        return $channel;
    }
}
