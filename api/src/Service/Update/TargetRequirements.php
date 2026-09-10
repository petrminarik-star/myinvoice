<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;
use Throwable;

/**
 * Minimální požadavky produktu, na který se instalace chystá přejít.
 *
 * PROČ SAMOSTATNĚ: preflight {@see NativeUpdateService::preflight()} dosud
 * ověřoval jen to, jestli update PROBĚHNE (zlib, práva, místo na disku) — ne
 * jestli výsledek POBĚŽÍ. U aktualizace v rámci téhož produktu to nevadí:
 * aplikace zrovna běží, takže PHP i databáze zjevně stačí. U přechodu na
 * nástupce to neplatí — MyÚčto chce novější PHP i MariaDB než MyInvoice, a
 * když je prostředí nesplní, swap se povede, migrace spadnou a instalace
 * skončí půl na půl. Tomu se dá předejít jen tím, že se to zjistí PŘED
 * stažením bundlu.
 *
 * Kontroly jsou schválně jen ty, které rozhodují o „poběží / nepoběží", a jsou
 * to blockery, ne varování: půlka nasazeného nástupce není stav, do kterého má
 * smysl pustit člověka jedním kliknutím. Hodnoty odpovídají diagnostice
 * nástupce (`EnvironmentCheckService` v MyÚčtu, sekce Nástroje → Diagnostika),
 * takže co tady projde, projde i tam.
 */
final class TargetRequirements
{
    private function __construct() {}

    /**
     * Vrací vedle blockerů i JEDNOTLIVÉ kontroly, a to včetně těch, které
     * dopadly dobře. Samotné „nic nebrání" totiž není informace, se kterou by
     * se dalo rozhodnout: člověk před nevratnou operací chce vidět, co přesně
     * se ověřilo a s jakou naměřenou hodnotou — stejně jako v Diagnostice.
     * Tvar položky odpovídá tamnímu `EnvironmentCheckService`.
     *
     * @return array{
     *     blockers:list<string>,
     *     warnings:list<string>,
     *     checks:list<array{id:string, label:string, status:string, actual:string, expected:string}>
     * }
     */
    public static function check(ReleaseChannel $channel, string $rootDir): array
    {
        $blockers = [];
        $warnings = [];
        $checks   = [];

        $minPhp = $channel->minPhpVersion;
        if ($minPhp !== null) {
            $ok = version_compare(PHP_VERSION, $minPhp, '>=');
            $checks[] = self::check_('php_version', 'Verze PHP', $ok, PHP_VERSION, '>= ' . $minPhp);
            if (!$ok) {
                $blockers[] = $channel->productName . ' vyžaduje PHP ' . $minPhp . ' nebo novější, tahle instalace běží na '
                    . PHP_VERSION . '. Povyš PHP dřív, než přechod spustíš — po výměně kódu už aplikace nenaběhne.';
            }
        }

        if ($channel->requiredExtensions !== []) {
            $missing = [];
            foreach ($channel->requiredExtensions as $ext) {
                if (!extension_loaded($ext)) {
                    $missing[] = $ext;
                }
            }
            $checks[] = self::check_(
                'php_extensions',
                'Rozšíření PHP',
                $missing === [],
                $missing === []
                    ? 'všech ' . count($channel->requiredExtensions) . ' dostupných'
                    : 'chybí ' . implode(', ', $missing),
                implode(', ', $channel->requiredExtensions),
            );
            if ($missing !== []) {
                $blockers[] = $channel->productName . ' vyžaduje PHP rozšíření, která tady chybí: '
                    . implode(', ', $missing) . '. Doinstaluj je (php.ini) a zkus to znovu.';
            }
        }

        $minDb = $channel->minMariaDbVersion;
        if ($minDb !== null) {
            [$server, $version] = self::databaseVersion($rootDir);
            $expected = 'MariaDB >= ' . $minDb;

            if ($server === null) {
                // Bez databáze nemá přechod smysl začínat — migrace jsou jeho
                // druhá polovina a bez spojení by skončil hned po swapu.
                $checks[] = self::check_('db_version', 'Verze databáze', false, 'nedostupná', $expected);
                $blockers[] = 'Nepodařilo se zjistit verzi databáze (spojení selhalo). '
                    . $channel->productName . ' vyžaduje MariaDB ' . $minDb . ' nebo novější.';
            } elseif ($server !== 'MariaDB') {
                $checks[] = self::check_('db_version', 'Verze databáze', false, $server . ' ' . $version, $expected);
                $blockers[] = $channel->productName . ' běží jen na MariaDB ' . $minDb . ' a novější; '
                    . 'tahle instalace používá ' . $server . ' ' . $version . '.';
            } else {
                $ok = version_compare($version, $minDb, '>=');
                $checks[] = self::check_('db_version', 'Verze databáze', $ok, 'MariaDB ' . $version, $expected);
                if (!$ok) {
                    $blockers[] = $channel->productName . ' vyžaduje MariaDB ' . $minDb . ' nebo novější, tahle instalace má '
                        . $version . '. Migrace nástupce používají novější syntaxi a na starší MariaDB spadnou '
                        . 'uprostřed — povyš databázi dřív, než přechod spustíš.';
                }
            }
        }

        return ['blockers' => $blockers, 'warnings' => $warnings, 'checks' => $checks];
    }

    /**
     * @return array{id:string, label:string, status:string, actual:string, expected:string}
     */
    private static function check_(string $id, string $label, bool $ok, string $actual, string $expected): array
    {
        return [
            'id'       => $id,
            'label'    => $label,
            'status'   => $ok ? 'ok' : 'fail',
            'actual'   => $actual,
            'expected' => $expected,
        ];
    }

    /**
     * @return array{0:?string, 1:string} „MariaDB"/„MySQL"/null a číselná verze
     */
    private static function databaseVersion(string $rootDir): array
    {
        try {
            $pdo = (new Connection(Config::load($rootDir)))->pdo();
            $raw = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return [null, ''];
        }

        $server = stripos($raw, 'mariadb') !== false ? 'MariaDB' : 'MySQL';

        // „11.8.8-MariaDB-log" → „11.8.8"; stejná normalizace jako v diagnostice nástupce.
        preg_match('/^\d+(\.\d+)*/', $raw, $m);

        return [$server, $m[0] ?? '0'];
    }
}
