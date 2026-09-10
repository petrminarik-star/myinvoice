<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Ares;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Ares\AresClient;
use MyInvoice\Service\Ares\CrpDphClient;
use MyInvoice\Service\Ares\ViesClient;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regrese: české OSVČ mají historické DIČ = "CZ" + rodné číslo (9–10 číslic),
 * což NENÍ jejich 8místné IČO (reálný příklad CZ8901311870 = Ing. Jiří Zikmund,
 * aktivní plátce). ViesClient ale pro CZ posílal číselnou část DIČ do ARES jako
 * IČO → ARES vrátil "nenalezeno" → uživateli svítilo "DIČ není platné nebo
 * neexistuje", přestože je to platný plátce ověřitelný přes VIES.
 *
 * Oprava: na ARES routujeme CZ jen když číselná část = 8 číslic (IČO); jinak
 * (a když ARES subjekt nenajde) propadneme na autoritativní VIES. ARES "nenalezeno"
 * už nesmí být tvrdý negativ se source 'ares'.
 *
 * Test běží offline: DB (vies_cache) je mockované PDO bez záznamu, VIES REST/SOAP
 * jsou vypnuté prázdnou konfigurací → lookup propadne až na source 'error'. Pointa
 * není výsledek "error", ale že source NENÍ 'ares' (tj. nevznikl tvrdý negativ).
 */
final class ViesClientCzRoutingTest extends TestCase
{
    /**
     * @param string|false $cachedPayload JSON, který vrátí každé fetchColumn()
     *                                    (simuluje zásah cache), false = cache prázdná.
     */
    private function makeClient(string|false $cachedPayload = false): ViesClient
    {
        $config = new Config([
            'vies'   => ['rest_api' => '', 'wsdl' => '', 'cache_ttl' => 86400, 'timeout' => 8],
            'ares'   => ['api' => '', 'timeout' => 5],
            'crpdph' => ['endpoint' => '', 'cache_ttl' => 86400, 'timeout' => 8],
        ]);

        // Connection s mockovaným PDO → fromCache vrátí $cachedPayload, cache() je no-op.
        $stmt = $this->createStub(PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetchColumn')->willReturn($cachedPayload);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $connection = new Connection($config);
        $ref = new \ReflectionProperty(Connection::class, 'pdo');
        $ref->setValue($connection, $pdo);

        $ares   = new AresClient($config, $connection, new NullLogger());
        $crpdph = new CrpDphClient($config, $connection, new NullLogger());

        return new ViesClient($config, $connection, new NullLogger(), $ares, $crpdph);
    }

    public function testCzOsvcDicWithRodneCisloDoesNotHardNegateViaAres(): void
    {
        // 10místné DIČ (rodné číslo) — NESMÍ skončit jako tvrdý ARES negativ.
        $r = $this->makeClient()->lookup('CZ8901311870');
        self::assertNotSame('ares', $r['source'], 'OSVČ DIČ nemá projít přes ARES-dle-IČO');
        // Offline (VIES vypnuté) → propadne na 'error'; v produkci by VIES vrátil valid.
        self::assertSame('error', $r['source']);
    }

    public function testCzNineDigitDicAlsoSkipsAres(): void
    {
        // 9místné DIČ (kratší rodné číslo) — stejné chování.
        $r = $this->makeClient()->lookup('CZ123456789');
        self::assertNotSame('ares', $r['source']);
    }

    public function testTryAresReturnsNullWhenSubjectNotFound(): void
    {
        // tryAres na "nenalezeno" (zde díky non-8místnému vstupu, který AresClient
        // odmítne bez I/O) musí vrátit null → fallback na VIES, ne tvrdý negativ.
        $client = $this->makeClient();
        $m = new \ReflectionMethod(ViesClient::class, 'tryAres');
        $result = $m->invoke($client, '123', 'CZ123');
        self::assertNull($result);
    }

    public function testCzGroupDicRoutesToCrpDphNotVies(): void
    {
        // Skupinová registrace DPH (kmen 699*): VIES ji neeviduje → routujeme na
        // registr plátců DPH. Zásah crpdph_cache (found=true) musí dát valid=true
        // se source 'crpdph'; kdyby routing spadl do vies_cache, vrátil by se
        // source 'cache' (payload nemá klíč `valid`) → test by selhal.
        $payload = '{"found":true,"unreliable":false,"accounts":[],"fu_code":"451","source":"fresh"}';
        $r = $this->makeClient($payload)->lookup('CZ699000797');
        self::assertSame('crpdph', $r['source']);
        self::assertTrue($r['valid']);
        self::assertTrue($r['group_registration'] ?? false);
        // Registr plátců název ani adresu subjektu nevrací — kontrakt, na kterém
        // stojí guardy ve frontendu (prázdný název se nesmí propsat do formuláře
        // klienta ani do hlášky „DIČ je platné — <název>“).
        self::assertSame('', $r['name'] ?? null);
        self::assertSame('', $r['address'] ?? null);
        self::assertArrayHasKey('parsed', $r);
        self::assertNull($r['parsed']);
    }

    public function testCzGroupDicUnavailableRegistryIsSoftError(): void
    {
        // Registr plátců DPH nedostupný (offline: prázdný endpoint, prázdná cache)
        // → source 'error' ("služba nedostupná"), NIKDY tvrdý negativ přes VIES/ARES.
        $r = $this->makeClient()->lookup('CZ699000797');
        self::assertSame('error', $r['source']);
        self::assertFalse($r['valid']);
    }
}
