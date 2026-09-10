<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\PhpCliLocator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

/**
 * Nativní nasazení production bundle z GitHub releasu
 * (`<produkt>-X.Y.Z.tar.gz`; který produkt a repozitář, určuje
 * {@see ReleaseChannel} — aktualizace MyInvoice, nebo přechod na MyÚčto).
 *
 * Bundle je kompletní deployable strom — `api/vendor/`, `web/dist/`,
 * `manual/generated/` i `manual/manual.pdf` jsou představěné, takže host
 * nepotřebuje Composer, Node ani pnpm. Konfigurace a uživatelská data
 * v bundlu vůbec nejsou (CI je z tarballu vylučuje) a swap je navíc přeskočí
 * podle {@see self::PROTECTED_PATHS}.
 *
 * Pipeline (`run()`), spouštěná detached CLI workerem `api/bin/native-update.php`:
 *   1. preflight  — prostředí, práva, místo na disku, PHP CLI, phar
 *   2. download   — release asset podle tagu (host allowlist, HTTPS only)
 *   3. verify     — SHA-256 proti `.sha256` assetu
 *   4. extract    — do staging dir, s validací všech cest v archivu
 *   5. backup     — kopie souborů, které swap přepíše (pro rollback)
 *   6. swap       — nakopírování bundlu přes instalaci (bez mazání)
 *   7. migrate    — `php api/bin/migrate.php` už novým kódem
 *   8. finish     — teprve teď se přepíše `VERSION`
 *
 * `VERSION` se úmyslně píše jako poslední krok: dokud migrace neproběhnou,
 * instalace se hlásí starou verzí, takže přerušený update nevypadá jako
 * dokončený a UI dál nabízí aktualizaci.
 *
 * Selhání swapu spustí rollback ze zálohy. Selhání migrace rollback
 * NEspouští — schéma už může být částečně změněné a vracet kód pod novým
 * schématem by škodilo víc; výsledek se označí `failed` a řeší se ručně.
 *
 * Bezpečnostní model: důvěřujeme GitHub releasu přes TLS. SHA-256 z `.sha256`
 * assetu chrání proti poškozenému přenosu, ne proti kompromitovanému
 * účtu/repu (checksum má stejný trust root jako tarball). Update smí spustit
 * jen superadmin.
 */
final class NativeUpdateService
{
    /**
     * Kroky pipeline v pořadí — UI je zobrazuje jako progress.
     *
     * Přechod na jiný produkt ({@see ReleaseChannel::requiresDatabaseBackup})
     * má navíc krok `db_backup`: u aktualizace se schéma posouvá o pár migrací
     * a rollback znamená vrátit kód, kdežto tady se nad databází dojede celá
     * sada migrací nástupce a zpátky už žádná cesta nevede. Dump proto není
     * doporučení, ale součást pipeline — bez něj se neswapuje.
     */
    public const STEPS = ['preflight', 'download', 'verify', 'extract', 'backup', 'swap', 'migrate', 'finish'];

    /** Krok navíc pro přechod mezi produkty; vkládá se hned za preflight. */
    public const STEP_DB_BACKUP = 'db_backup';


    /**
     * Odkud smí bundle přijít. GitHub redirectuje asset download na svůj
     * blob storage, takže hosty musíme povolit i pro cíl redirectu.
     */
    private const ALLOWED_DOWNLOAD_HOSTS = [
        'github.com',
        'objects.githubusercontent.com',
        'release-assets.githubusercontent.com',
    ];

    /**
     * Nikdy nepřepisovat — konfigurace, uživatelská data, git metadata.
     * Porovnává se na relativní cestu vůči rootu (přesná shoda nebo prefix
     * `cesta/`). Bundle je tyhle položky neobsahuje, tohle je druhá pojistka.
     */
    private const PROTECTED_PATHS = [
        'cfg.php',
        'cfg.local.php',
        'cfg.docker.php',
        '.env',
        'storage',
        'private',
        'log',
        'tmp',
        '.git',
        'api/vendor.prod',
    ];

    private const MAX_BUNDLE_BYTES = 600 * 1024 * 1024;
    private const MIN_FREE_BYTES   = 512 * 1024 * 1024;
    private const HTTP_TIMEOUT     = 1800;
    private const API_TIMEOUT      = 20;

    private readonly string $rootDir;
    private readonly string $stateDir;
    private readonly ReleaseChannel $channel;

    /** Relativní cesty už přepsané v aktuálním swapu — podklad pro rollback. */
    private array $swapped = [];

    /** Odložené `.myinvoice-old` soubory, které zatím nešlo smazat (zamčené). */
    private array $parked = [];

    public function __construct(
        ?string $rootDir = null,
        ?string $stateDir = null,
        ?ReleaseChannel $channel = null,
    ) {
        $this->channel = $channel ?? ReleaseChannel::myinvoice();
        $this->rootDir = rtrim($rootDir ?? Bootstrap::rootDir(), '/\\');
        // Shodná logika jako VersionService::stateBaseDir() — flag/result/log
        // musí končit tam, kde je hledá VersionService.
        $this->stateDir = rtrim($stateDir ?? (Config::resolveDataDir() ?? $this->rootDir), '/\\');
    }

    /**
     * Kroky pipeline pro tenhle kanál — UI i `progress()` počítají z nich.
     *
     * @return list<string>
     */
    public function steps(): array
    {
        if (!$this->channel->requiresDatabaseBackup) {
            return self::STEPS;
        }
        $steps = self::STEPS;
        array_splice($steps, 1, 0, [self::STEP_DB_BACKUP]);

        return $steps;
    }

    // ---------- preflight -------------------------------------------------

    /**
     * Ověří, že nativní update na `$target` má smysl zkoušet. Blockery update
     * neumožní, warnings jsou informativní.
     *
     * Vedle nich vrací `checks` — jednotlivé kontroly VČETNĚ těch, které
     * dopadly dobře. UI z nich staví checklist, protože „nic nebrání" není
     * před nevratnou operací dost: člověk chce vidět, co se ověřilo a s jakou
     * naměřenou hodnotou.
     *
     * @return array{
     *     ok:bool,
     *     supported:bool,
     *     blockers:list<string>,
     *     warnings:list<string>,
     *     checks:list<array{id:string, label:string, status:string, actual:string, expected:string}>
     * }
     */
    public function preflight(string $target): array
    {
        $blockers = [];
        $warnings = [];
        $checks   = [];

        if (!self::isValidVersion($target)) {
            $blockers[] = 'Cílová verze „' . $target . '" není platný semver (X.Y.Z).';
        }

        if (!function_exists('gzopen')) {
            $blockers[] = 'Chybí rozšíření zlib — bez něj nelze rozbalit tar.gz bundle.';
        }
        if (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            $blockers[] = 'Není dostupné ani cURL, ani allow_url_fopen — bundle nelze stáhnout.';
        }
        $cli = PhpCliLocator::resolve();
        $checks[] = $this->preflightCheck(
            'php_cli',
            'PHP CLI pro worker a migrace',
            $cli !== null,
            $cli ?? 'nenalezena (PHP_BINARY=' . PHP_BINARY . ')',
            'spustitelná binárka',
        );
        if ($cli === null) {
            $blockers[] = 'Nenalezena PHP CLI binárka (PHP_BINARY=' . PHP_BINARY . ') — nelze spustit worker ani migrace.';
        }

        $unwritable = [];
        foreach (['', 'api', 'web', 'manual'] as $sub) {
            $dir = $sub === '' ? $this->rootDir : $this->rootDir . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            if (!$this->isDirWritable($dir)) {
                $unwritable[] = $sub === '' ? '(root instalace)' : $sub;
                $blockers[] = 'Adresář ' . ($sub === '' ? '(root instalace)' : $sub) . ' není zapisovatelný pro uživatele webserveru.';
            }
        }
        $checks[] = $this->preflightCheck(
            'writable_paths',
            'Práva zápisu do instalace',
            $unwritable === [],
            $unwritable === [] ? 'zapisovatelné' : 'nelze zapsat: ' . implode(', ', $unwritable),
            'root, api, web, manual',
        );

        if (!$this->canReplaceFiles()) {
            $blockers[] = 'Existující soubory nelze přepsat (rename/unlink selhal) — zkontroluj práva a zámky souborů.';
        }

        $free = @disk_free_space($this->stateDir);
        $freeOk = !is_float($free) || $free >= self::MIN_FREE_BYTES;
        $checks[] = $this->preflightCheck(
            'disk_space',
            'Volné místo na disku',
            $freeOk,
            is_float($free) ? $this->humanBytes((int) $free) : 'nezjištěno',
            '>= ' . $this->humanBytes(self::MIN_FREE_BYTES),
        );
        if (!$freeOk) {
            $blockers[] = 'Na disku je jen ' . $this->humanBytes((int) $free)
                . ' volného místa; bundle + záloha potřebují aspoň ' . $this->humanBytes(self::MIN_FREE_BYTES) . '.';
        }

        if (is_dir($this->rootDir . '/.git')) {
            $warnings[] = 'Instalace je git checkout — po aktualizaci bundlem bude pracovní kopie špinavá. '
                . 'Ve vývoji preferuj `git checkout v' . $target . '`.';
        }
        if (ini_get('opcache.enable') && !filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN)) {
            $warnings[] = 'opcache.validate_timestamps je vypnuté — po aktualizaci restartuj php-fpm / IIS application pool, '
                . 'jinak poběží stará bytecode cache.';
        }

        // Dosud se ověřovalo jen to, jestli update proběhne. Tohle ověřuje,
        // jestli výsledek poběží — u přechodu na nástupce s vyššími nároky
        // je to rozdíl mezi „nespustí se" a „skončí půl na půl".
        $req = TargetRequirements::check($this->channel, $this->rootDir);
        $blockers = array_merge($blockers, $req['blockers']);
        $warnings = array_merge($warnings, $req['warnings']);

        // Požadavky cíle napřed: to je to, co člověk před přechodem řeší.
        // Prostředí updatu (práva, místo, CLI) je až za nimi.
        $checks = array_merge($req['checks'], $checks);

        return [
            'ok'        => $blockers === [],
            'supported' => true,
            'blockers'  => $blockers,
            'warnings'  => $warnings,
            'checks'    => $checks,
        ];
    }

    /**
     * @return array{id:string, label:string, status:string, actual:string, expected:string}
     */
    private function preflightCheck(string $id, string $label, bool $ok, string $actual, string $expected): array
    {
        return [
            'id'       => $id,
            'label'    => $label,
            'status'   => $ok ? 'ok' : 'fail',
            'actual'   => $actual,
            'expected' => $expected,
        ];
    }

    // ---------- pipeline --------------------------------------------------

    /**
     * Kompletní update. Volá se z CLI workeru, ne z HTTP requestu.
     *
     * @return array<string,mixed> result payload (zapsaný i do upgrade-result.json)
     */
    public function run(string $target, string $requestedBy): array
    {
        $log = $this->logPath();

        if (!self::isValidVersion($target)) {
            return $this->finishFailed($target, 'Cílová verze „' . $target . '" není platný semver.', $log);
        }

        $this->appendLog($log, str_repeat('=', 60));
        $this->appendLog($log, $this->channel->productName . ' — nasazuji v' . $target . ' (žádal ' . $requestedBy . ')');
        $this->appendLog($log, 'root=' . $this->rootDir . ' state=' . $this->stateDir);

        $work = $this->stateDir . '/storage/updates/' . $target;

        try {
            $this->progress($target, 'preflight', 'Kontroluji prostředí…');
            $pf = $this->preflight($target);
            foreach ($pf['warnings'] as $w) {
                $this->appendLog($log, 'WARN: ' . $w);
            }
            if (!$pf['ok']) {
                throw new RuntimeException('Preflight selhal: ' . implode(' ', $pf['blockers']));
            }
            $this->ensureDir($work);

            if ($this->channel->requiresDatabaseBackup) {
                $this->progress($target, self::STEP_DB_BACKUP, 'Zálohuji databázi…');
                $this->backupDatabase($log);
            }

            $this->progress($target, 'download', 'Stahuji production bundle…');
            [$bundlePath, $expectedSha] = $this->downloadBundle($target, $work, $log);

            $this->progress($target, 'verify', 'Ověřuji SHA-256 kontrolní součet…');
            $this->verifyChecksum($bundlePath, $expectedSha, $log);

            $this->progress($target, 'extract', 'Rozbaluji bundle…');
            $stage = $this->extractBundle($bundlePath, $work, $target, $log);

            $this->progress($target, 'backup', 'Zakládám zálohu přepisovaných souborů…');
            $backup = $work . '/backup';
            $this->ensureDir($backup);

            // Od téhle chvíle je instalace nekonzistentní (půl staré, půl nové
            // verze; schéma ještě neposunuté) a musí requestům odpovídat 503,
            // ne fatálem z půlky autoloadu. Značka padá až po migracích.
            MaintenanceMode::begin($this->stateDir, $this->channel->productName, $target);
            $this->appendLog($log, 'Režim údržby zapnut — requesty dostanou 503 do konce migrací.');

            $this->progress($target, 'swap', 'Nasazuji nové soubory…');
            $swapped = $this->swap($stage, $backup, $target, $log);

            $this->progress($target, 'migrate', 'Spouštím databázové migrace…');
            $this->runMigrations($log);

            if ($this->channel->isSuccessorHandover) {
                $this->handoverToSuccessor($log);
            }

            MaintenanceMode::end($this->stateDir);
            $this->appendLog($log, 'Režim údržby vypnut.');

            $this->progress($target, 'finish', 'Dokončuji…');
            $this->writeVersionFile($target, $stage, $log);
            $this->cleanupWork($work, $log);
            $this->pruneOldBackups($target, $log);
            $this->cleanupParked($log);

            $this->appendLog($log, 'HOTOVO: nasazena verze ' . $target . ' (' . $swapped . ' souborů, záloha: ' . $backup . ')');

            return $this->finishApplied($target, $swapped, $backup, $log, $pf['warnings']);
        } catch (Throwable $e) {
            $this->appendLog($log, 'CHYBA: ' . $e->getMessage());
            $this->cleanupParked($log);
            return $this->finishFailed($target, $e->getMessage(), $log);
        } finally {
            // I update, který skončil chybou, musí instalaci vrátit do provozu.
            // Bez tohohle by ji značka držela na 503 až do vlastní expirace,
            // tedy i ve chvíli, kdy rollback vrátil funkční starou verzi.
            MaintenanceMode::end($this->stateDir);
        }
    }

    // ---------- download / verify -----------------------------------------

    /**
     * Najde v releasu podle tagu asset `<produkt>-X.Y.Z.tar.gz` + jeho `.sha256`,
     * stáhne oba.
     *
     * @return array{0:string, 1:string} cesta k bundlu, očekávaný sha256
     */
    private function downloadBundle(string $target, string $work, string $log): array
    {
        $release = $this->httpGetJson($this->channel->releaseByTagApi() . $target);
        $assets  = is_array($release['assets'] ?? null) ? $release['assets'] : [];

        $bundleName = $this->channel->bundleName($target);
        $bundleUrl  = null;
        $shaUrl     = null;
        $expectSize = null;
        foreach ($assets as $a) {
            $name = (string) ($a['name'] ?? '');
            $url  = (string) ($a['browser_download_url'] ?? '');
            if ($name === $bundleName) {
                $bundleUrl  = $url;
                $expectSize = isset($a['size']) ? (int) $a['size'] : null;
            } elseif ($name === $bundleName . '.sha256') {
                $shaUrl = $url;
            }
        }
        if ($bundleUrl === null) {
            throw new RuntimeException('Release v' . $target . ' neobsahuje asset ' . $bundleName . '.');
        }
        if ($shaUrl === null) {
            throw new RuntimeException('Release v' . $target . ' neobsahuje kontrolní součet ' . $bundleName . '.sha256.');
        }
        if ($expectSize !== null && $expectSize > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Bundle je ' . $this->humanBytes($expectSize) . ', limit je '
                . $this->humanBytes(self::MAX_BUNDLE_BYTES) . '.');
        }

        $bundlePath = $work . '/' . $bundleName;
        $this->appendLog($log, 'Stahuji ' . $bundleUrl);
        $this->downloadToFile($bundleUrl, $bundlePath, $target);
        $actualSize = (int) @filesize($bundlePath);
        $this->appendLog($log, 'Staženo ' . $this->humanBytes($actualSize));
        if ($expectSize !== null && $actualSize !== $expectSize) {
            throw new RuntimeException('Velikost bundlu nesouhlasí (čekáno ' . $expectSize . ' B, stáhnuto ' . $actualSize . ' B).');
        }

        $shaRaw = $this->httpGetString($shaUrl);
        if (!preg_match('/\b([0-9a-f]{64})\b/i', $shaRaw, $m)) {
            throw new RuntimeException('Asset ' . $bundleName . '.sha256 neobsahuje čitelný SHA-256.');
        }

        return [$bundlePath, strtolower($m[1])];
    }

    private function verifyChecksum(string $bundlePath, string $expectedSha, string $log): void
    {
        $actual = hash_file('sha256', $bundlePath);
        if (!is_string($actual)) {
            throw new RuntimeException('SHA-256 stáhnutého bundlu nelze spočítat.');
        }
        $actual = strtolower($actual);
        $this->appendLog($log, 'SHA-256 ' . $actual);
        if (!hash_equals($expectedSha, $actual)) {
            @unlink($bundlePath);
            throw new RuntimeException('Kontrolní součet nesouhlasí (čekáno ' . $expectedSha . ', spočítáno ' . $actual
                . '). Bundle byl smazán, aktualizace zrušena.');
        }
    }

    // ---------- extract ----------------------------------------------------

    /**
     * Rozbalí bundle do čistého staging dir přes {@see TarGzExtractor}, který
     * validuje každou cestu v archivu (prefix `<produkt>-X.Y.Z/`, žádné `..`,
     * absolutní cesty ani odkazy). Pak ověří, že strom vypadá jako kompletní
     * production bundle.
     *
     * @return string cesta ke staged stromu (obsah bez horního prefixu)
     */
    private function extractBundle(string $bundlePath, string $work, string $target, string $log): string
    {
        $stageRoot = $work . '/stage';
        $this->removeTree($stageRoot);
        $this->ensureDir($stageRoot);

        $prefix = $this->channel->archivePrefix($target);
        $files  = (new TarGzExtractor())->extract($bundlePath, $stageRoot, $prefix);
        $this->appendLog($log, 'Rozbaleno ' . count($files) . ' souborů');

        // Ve staging rootu smí být právě jeden adresář — verzovaný prefix.
        // Cokoliv dalšího znamená, že archiv obsahoval obsah, který jsme
        // nečekali, a bundle nenasadíme.
        $topLevel = array_values(array_diff((array) @scandir($stageRoot), ['.', '..']));
        if ($topLevel !== [rtrim($prefix, '/')]) {
            throw new RuntimeException('Rozbalený bundle má nečekaný obsah v kořeni: '
                . implode(', ', array_map('strval', $topLevel)) . '.');
        }

        $stage = $stageRoot . '/' . rtrim($prefix, '/');
        if (!is_dir($stage)) {
            throw new RuntimeException('Rozbalený bundle neobsahuje očekávaný adresář ' . rtrim($prefix, '/') . '.');
        }

        $this->assertStagedTreeSane($stage, $target);
        $this->appendLog($log, 'Staging strom OK: ' . $stage);

        return $stage;
    }

    /** Rozbalený strom musí vypadat jako plnohodnotný production bundle. */
    private function assertStagedTreeSane(string $stage, string $target): void
    {
        $versionFile = $stage . '/VERSION';
        if (!is_file($versionFile)) {
            throw new RuntimeException('Bundle neobsahuje soubor VERSION.');
        }
        $staged = trim((string) @file_get_contents($versionFile));
        if ($staged !== $target) {
            throw new RuntimeException('Bundle hlásí verzi „' . $staged . '", čekána „' . $target . '".');
        }
        foreach (['api/vendor/autoload.php', 'api/public/index.php', 'api/src', 'web/dist', 'db/migrations'] as $required) {
            if (!file_exists($stage . '/' . $required)) {
                throw new RuntimeException('Bundle neobsahuje ' . $required . ' — nejde o kompletní production bundle.');
            }
        }
    }

    // ---------- backup + swap ---------------------------------------------

    /**
     * Nakopíruje staged strom přes instalaci. Soubory, které přepisuje,
     * nejdřív zazálohuje. Nic nemaže — soubory, které v novém bundlu nejsou,
     * zůstávají (bezpečnější a stejné chování jako ruční `tar -xzf`).
     *
     * `VERSION` se v tomhle kroku úmyslně přeskočí, píše ho až `finish`.
     *
     * @return int počet nasazených souborů
     */
    private function swap(string $stage, string $backup, string $target, string $log): int
    {
        $this->swapped = [];
        $files = 0;

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($stage, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        try {
            /** @var \SplFileInfo $item */
            foreach ($it as $item) {
                $rel = $this->relativePath($stage, $item->getPathname());
                if ($rel === null || $rel === 'VERSION' || $this->isProtected($rel)) {
                    continue;
                }
                $dest = $this->rootDir . '/' . $rel;

                if ($item->isDir()) {
                    $this->ensureDir($dest);
                    continue;
                }
                if (!$item->isFile()) {
                    continue;
                }

                if (is_file($dest)) {
                    $this->backupFile($rel, $dest, $backup);
                }
                // Do rollback listu se cesta zapisuje ještě PŘED přepisem:
                // když replaceFile selže napůl (cíl už smazaný, nová verze
                // nezapsaná), musí ho rollback vrátit ze zálohy taky.
                $this->swapped[] = $rel;
                $this->replaceFile($item->getPathname(), $dest);
                $files++;

                if ($files % 500 === 0) {
                    $this->progress($target, 'swap', 'Nasazuji nové soubory… (' . $files . ')');
                }
            }
        } catch (Throwable $e) {
            $this->appendLog($log, 'Swap selhal po ' . $files . ' souborech — spouštím rollback.');
            $restored = $this->rollback($backup, $log);
            throw new RuntimeException('Nasazení souborů selhalo (' . $e->getMessage() . '). Rollback vrátil '
                . $restored . ' souborů ze zálohy ' . $backup . '.', 0, $e);
        }

        $this->appendLog($log, 'Swap OK: ' . $files . ' souborů');

        return $files;
    }

    private function backupFile(string $rel, string $dest, string $backup): void
    {
        $target = $backup . '/' . $rel;
        $this->ensureDir(dirname($target));
        if (!@copy($dest, $target)) {
            throw new RuntimeException('Nelze zazálohovat ' . $rel . ' do ' . $target . '.');
        }
    }

    /**
     * Přepis souboru s co nejkratším okamžikem nekonzistence: zapiš vedle,
     * pak přejmenuj. Na Windows rename přes existující soubor selže vždy,
     * proto fallbacky.
     *
     * Zamčený cíl (na Windows typicky `api/bin/native-update.php` — vlastní
     * běžící worker: PHP drží handle na spouštěný skript) nejde přepsat ani
     * copy, ani unlink+rename: `unlink()` sice uspěje, ale jméno zůstane
     * v delete-pending stavu až do konce procesu, takže se na něj nedá nic
     * zapsat — a soubor po doběhnutí zmizí. Otevřený soubor ale Windows
     * dovolí *přejmenovat*, proto cíl nejdřív uhneme stranou (`.myinvoice-old`)
     * a novou verzi přesuneme na uvolněné jméno. Když ani to nevyjde, vrátíme
     * původní soubor zpátky — instalace po neúspěšném přepisu nikdy nesmí
     * zůstat bez souboru.
     */
    private function replaceFile(string $src, string $dest): void
    {
        $this->ensureDir(dirname($dest));
        $tmp = $dest . '.myinvoice-upd';
        if (!@copy($src, $tmp)) {
            throw new RuntimeException('Nelze zapsat ' . $tmp . '.');
        }
        if (@rename($tmp, $dest)) {
            return;
        }

        $parked = null;
        if (is_file($dest)) {
            $candidate = $dest . '.myinvoice-old';
            @unlink($candidate);
            if (@rename($dest, $candidate)) {
                $parked = $candidate;
            }
        }
        if (@rename($tmp, $dest) || @copy($tmp, $dest)) {
            @unlink($tmp);
            if ($parked !== null) {
                $this->discardParked($parked);
            }
            return;
        }
        // Uhnout stranou nešlo — zbývá destruktivní varianta (cíl je pak už
        // stejně jen v záloze, ze které umí rollback).
        if ($parked === null && is_file($dest) && @unlink($dest)
            && (@rename($tmp, $dest) || @copy($tmp, $dest))) {
            @unlink($tmp);
            return;
        }
        if ($parked !== null) {
            @rename($parked, $dest);
        }
        @unlink($tmp);
        throw new RuntimeException('Nelze přepsat ' . $dest . ' (soubor je zamčený nebo chybí práva).');
    }

    /**
     * Zahodí odloženou původní verzi souboru. Na Windows je zámek držený jen
     * do konce procesu, takže co teď nejde smazat, zkusíme na konci pipeline
     * ještě jednou {@see self::cleanupParked()}.
     */
    private function discardParked(string $path): void
    {
        if (@unlink($path)) {
            return;
        }
        $this->parked[] = $path;
    }

    private function cleanupParked(string $log): void
    {
        $left = [];
        foreach ($this->parked as $path) {
            if (is_file($path) && !@unlink($path)) {
                $left[] = $path;
            }
        }
        $this->parked = [];
        if ($left !== []) {
            $this->appendLog($log, 'Zbyly odložené soubory (smaž ručně po restartu): ' . implode(', ', $left));
        }
    }

    /** Vrátí zazálohované soubory zpět na místo. @return int počet obnovených */
    private function rollback(string $backup, string $log): int
    {
        $restored = 0;
        foreach ($this->swapped as $rel) {
            $src = $backup . '/' . $rel;
            if (!is_file($src)) {
                continue;
            }
            try {
                $this->replaceFile($src, $this->rootDir . '/' . $rel);
                $restored++;
            } catch (Throwable $e) {
                $this->appendLog($log, 'Rollback: nelze obnovit ' . $rel . ' (' . $e->getMessage() . ')');
            }
        }
        $this->appendLog($log, 'Rollback obnovil ' . $restored . ' z ' . count($this->swapped) . ' souborů.');

        return $restored;
    }

    // ---------- migrace + finish -----------------------------------------

    /** Spustí `api/bin/migrate.php` samostatným CLI procesem — už novým kódem. */
    /**
     * Dump databáze před swapem — pojistka pro přechod na jiný produkt.
     *
     * Používá `api/bin/cron-backup.php`, tedy tentýž kód jako noční záloha:
     * má vyřešenou detekci `mariadb-dump`/`mysqldump` (včetně Windows cest),
     * cílový adresář i případné šifrování. Duplikovat tu logiku jen kvůli
     * upgradu by znamenalo druhé místo, kde se to může rozejít.
     *
     * Selhání je fatální. Pustit nevratnou migraci schématu bez zálohy je
     * horší než upgrade nespustit vůbec — instalace zůstane netknutá.
     */
    private function backupDatabase(string $log): void
    {
        $php = PhpCliLocator::resolve();
        if ($php === null) {
            throw new RuntimeException('Nenalezena PHP CLI binárka pro spuštění zálohy databáze.');
        }
        $script = $this->rootDir . '/api/bin/cron-backup.php';
        if (!is_file($script)) {
            throw new RuntimeException('Chybí ' . $script . ' — zálohu databáze nelze pořídit, '
                . 'a bez ní přechod nespouštím. Zazálohuj ručně a použij ruční postup.');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $this->appendLog($log, '$ ' . $cmd);

        $out = [];
        $rc  = 0;
        @exec($cmd, $out, $rc);
        foreach ($out as $line) {
            $this->appendLog($log, '  ' . $line);
        }
        if ($rc !== 0) {
            throw new RuntimeException('Záloha databáze selhala (exit ' . $rc . '). Přechod nespouštím — '
                . 'instalace zůstává beze změny. Podrobnosti v logu ' . basename($log) . '.');
        }
        $this->appendLog($log, 'Záloha databáze hotova.');
    }

    private function runMigrations(string $log): void
    {
        $php = PhpCliLocator::resolve();
        if ($php === null) {
            throw new RuntimeException('Nenalezena PHP CLI binárka pro spuštění migrací.');
        }
        $script = $this->rootDir . '/api/bin/migrate.php';
        if (!is_file($script)) {
            throw new RuntimeException('Chybí ' . $script . ' — nasazený bundle je neúplný.');
        }

        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
        $this->appendLog($log, '$ ' . $cmd);

        $out = [];
        $rc  = 0;
        @exec($cmd, $out, $rc);
        foreach ($out as $line) {
            $this->appendLog($log, '  ' . $line);
        }
        if ($rc !== 0) {
            throw new RuntimeException('Migrace selhaly (exit ' . $rc . '). Kód je už nasazený, schéma ne — '
                . 'projdi log ' . basename($log) . ' a dokonči `php api/bin/migrate.php` ručně.');
        }
        $this->appendLog($log, 'Migrace OK');
    }

    /**
     * Doladění stavu po výměně produktu za nástupce.
     *
     * ÚČETNICTVÍ SE VYPÍNÁ. MyÚčto zavádí přepínač „Vést účetnictví" s
     * defaultem zapnuto — což je správně pro firmu, která v MyÚčtu už účtuje,
     * ale ne pro instalaci, která právě přišla z MyInvoice a účetnictví nikdy
     * nevedla. Ta by po přechodu dostala plné účetní menu a k tomu režim
     * „daňová evidence", tedy default sloupce `accounting_mode` — u s.r.o.
     * rovnou špatně, protože ta vede podvojné účetnictví ze zákona.
     *
     * Nechat to na uživateli není totéž jako vypnout: režim účetnictví
     * rozhoduje o tom, jak se doklady účtují, a MyÚčto ho po prvním zaúčtování
     * nepřepíná zadarmo. Přechod proto nechá agendu skrytou a volbu (vést /
     * nevést, a v jakém režimu) nechá na vědomém rozhodnutí — mzdy, sklad
     * i OSS se chovají stejně, ty jsou vypnuté už z principu.
     *
     * Selhání nesmí shodit celý přechod: kód i schéma jsou v tuhle chvíli
     * v pořádku a nasazená verze je platná. Zapíše se do logu a jde se dál.
     */
    private function handoverToSuccessor(string $log): void
    {
        try {
            $pdo = (new Connection(Config::load($this->rootDir)))->pdo();

            $hasColumn = $pdo->query(
                "SHOW COLUMNS FROM supplier LIKE 'accounting_enabled'"
            )->fetch();
            if ($hasColumn === false) {
                $this->appendLog($log, 'Předání nástupci: sloupec supplier.accounting_enabled neexistuje — přeskočeno.');
                return;
            }

            $stmt = $pdo->prepare('UPDATE supplier SET accounting_enabled = 0 WHERE accounting_enabled = 1');
            $stmt->execute();

            $this->appendLog($log, 'Předání nástupci: účetnictví vypnuto u ' . $stmt->rowCount()
                . ' firem — instalace přišla z MyInvoice.cz, kde se neúčtovalo. '
                . 'Zapíná se v Nastavení → Licenční moduly spolu s volbou režimu.');
        } catch (Throwable $e) {
            $this->appendLog($log, 'VAROVÁNÍ: předání nástupci selhalo (' . $e->getMessage()
                . '). Účetnictví zůstalo zapnuté — vypni ho v Nastavení → Licenční moduly.');
        }
    }

    /** Poslední krok — teprve teď se instalace prohlásí za novou verzi. */
    private function writeVersionFile(string $target, string $stage, string $log): void
    {
        $src = $stage . '/VERSION';
        $this->replaceFile($src, $this->rootDir . '/VERSION');
        $this->appendLog($log, 'VERSION → ' . $target);
    }

    private function cleanupWork(string $work, string $log): void
    {
        $this->removeTree($work . '/stage');
        foreach ((array) @glob($work . '/' . $this->channel->bundleGlob()) as $f) {
            @unlink((string) $f);
        }
        $this->appendLog($log, 'Úklid: staging a tarball smazány, záloha zachována.');
    }

    /** Nechá poslední dvě zálohy, starší smaže — ať nenaroste disk. */
    private function pruneOldBackups(string $keepTarget, string $log): void
    {
        $base = $this->stateDir . '/storage/updates';
        $dirs = array_values(array_filter((array) @glob($base . '/*'), 'is_dir'));
        if (count($dirs) <= 2) {
            return;
        }
        usort($dirs, static fn ($a, $b) => (@filemtime((string) $a) ?: 0) <=> (@filemtime((string) $b) ?: 0));
        $removable = array_slice($dirs, 0, count($dirs) - 2);
        foreach ($removable as $dir) {
            if (basename((string) $dir) === $keepTarget) {
                continue;
            }
            $this->removeTree((string) $dir);
            $this->appendLog($log, 'Smazána stará záloha ' . basename((string) $dir));
        }
    }

    // ---------- progress / result ----------------------------------------

    /**
     * Zapíše aktuální krok do upgrade flagu. `heartbeat_at` drží flag živý —
     * VersionService podle něj pozná, že worker běží (a neproškrtne ho TTL).
     */
    private function progress(string $target, string $step, string $message): void
    {
        $flag = $this->channel->flagPath($this->stateDir);
        $payload = [];
        if (is_file($flag)) {
            $decoded = json_decode((string) @file_get_contents($flag), true);
            $payload = is_array($decoded) ? $decoded : [];
        }
        $payload['mode']           = 'native';
        $payload['target_version'] = $target;
        $payload['step']           = $step;
        $steps = $this->steps();
        $payload['step_index']     = (int) array_search($step, $steps, true) + 1;
        $payload['step_count']     = count($steps);
        $payload['step_message']   = $message;
        $payload['heartbeat_at']   = date(\DateTimeInterface::ATOM);

        $this->ensureDir(dirname($flag));
        @file_put_contents($flag, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /** @param list<string> $warnings */
    private function finishApplied(string $target, int $files, string $backup, string $log, array $warnings): array
    {
        $message = 'Nasazena verze ' . $target . ' z production bundlu (' . $files . ' souborů). '
            . 'Záloha přepsaných souborů: ' . $backup . '.';
        if ($warnings !== []) {
            $message .= ' ' . implode(' ', $warnings);
        }

        return $this->writeResult([
            'status'         => 'applied',
            'mode'           => 'native',
            'target_version' => $target,
            'applied_at'     => date(\DateTimeInterface::ATOM),
            'message'        => $message,
            'log_path'       => $log,
            'backup_path'    => $backup,
        ]);
    }

    private function finishFailed(string $target, string $error, string $log): array
    {
        return $this->writeResult([
            'status'         => 'failed',
            'mode'           => 'native',
            'target_version' => $target,
            'applied_at'     => date(\DateTimeInterface::ATOM),
            'message'        => $error . ' Podrobnosti: ' . $log,
            'log_path'       => $log,
        ]);
    }

    /** @param array<string,mixed> $result */
    private function writeResult(array $result): array
    {
        $path = $this->channel->resultPath($this->stateDir);
        $this->ensureDir(dirname($path));
        @file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        // Flag musí zmizet, jinak UI zůstane na „upgrade probíhá".
        @unlink($this->channel->flagPath($this->stateDir));

        return $result;
    }

    // ---------- HTTP ------------------------------------------------------

    /** @return array<string,mixed> */
    private function httpGetJson(string $url): array
    {
        $raw  = $this->httpGetString($url);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('GitHub API vrátil ne-JSON odpověď (' . $url . ').');
        }

        return $data;
    }

    private function httpGetString(string $url): string
    {
        $this->assertAllowedUrl($url);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('curl_init selhal.');
            }
            curl_setopt_array($ch, $this->curlBaseOptions() + [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::API_TIMEOUT,
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            // curl_close() je od PHP 8.5 deprecated (a od 8.0 no-op) — handle
            // uvolní GC sám, jak vyjde z rozsahu.
            unset($ch);
            if (!is_string($body)) {
                throw new RuntimeException('Stažení ' . $url . ' selhalo: ' . $err);
            }
            if ($code >= 400) {
                throw new RuntimeException('GitHub vrátil HTTP ' . $code . ' pro ' . $url . '.');
            }

            return $body;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => "User-Agent: MyInvoice.cz/native-update\r\nAccept: application/vnd.github+json\r\n",
            'timeout'       => self::API_TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 5,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!is_string($body)) {
            throw new RuntimeException('Stažení ' . $url . ' selhalo (allow_url_fopen).');
        }

        return $body;
    }

    private function downloadToFile(string $url, string $dest, string $target): void
    {
        $this->assertAllowedUrl($url);
        $this->ensureDir(dirname($dest));
        @unlink($dest);

        $fh = @fopen($dest, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Nelze zapisovat do ' . $dest . '.');
        }

        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch === false) {
                    throw new RuntimeException('curl_init selhal.');
                }
                $lastBeat = 0;
                curl_setopt_array($ch, $this->curlBaseOptions() + [
                    CURLOPT_FILE       => $fh,
                    CURLOPT_TIMEOUT    => self::HTTP_TIMEOUT,
                    CURLOPT_NOPROGRESS => false,
                    CURLOPT_PROGRESSFUNCTION => function ($_c, $total, $now) use ($target, &$lastBeat): int {
                        if ($now > self::MAX_BUNDLE_BYTES) {
                            return 1; // abort
                        }
                        if (time() - $lastBeat >= 5) {
                            $lastBeat = time();
                            $pct = $total > 0 ? ' (' . (int) round($now / $total * 100) . ' %)' : '';
                            $this->progress($target, 'download', 'Stahuji production bundle…' . $pct);
                        }

                        return 0;
                    },
                ]);
                $ok   = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err  = curl_error($ch);
                unset($ch); // viz httpGetString() — curl_close() je deprecated
                if ($ok === false) {
                    throw new RuntimeException('Stahování bundlu selhalo: ' . ($err !== '' ? $err : 'přerušeno'));
                }
                if ($code >= 400) {
                    throw new RuntimeException('Stahování bundlu vrátilo HTTP ' . $code . '.');
                }

                return;
            }

            $ctx = stream_context_create(['http' => [
                'method'          => 'GET',
                'header'          => "User-Agent: MyInvoice.cz/native-update\r\n",
                'timeout'         => self::HTTP_TIMEOUT,
                'follow_location' => 1,
                'max_redirects'   => 5,
            ]]);
            $in = @fopen($url, 'rb', false, $ctx);
            if ($in === false) {
                throw new RuntimeException('Nelze otevřít ' . $url . ' (allow_url_fopen).');
            }
            $copied = @stream_copy_to_stream($in, $fh, self::MAX_BUNDLE_BYTES);
            fclose($in);
            if ($copied === false || $copied === 0) {
                throw new RuntimeException('Stahování bundlu selhalo (0 B).');
            }
        } finally {
            fclose($fh);
        }
    }

    /** @return array<int,mixed> */
    private function curlBaseOptions(): array
    {
        $opts = [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_USERAGENT      => 'MyInvoice.cz/native-update',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github+json'],
        ];
        // Redirect z GitHubu smí jít jen zase na HTTPS — nikdy na http/file/ftp.
        if (defined('CURLOPT_REDIR_PROTOCOLS_STR')) {
            $opts[CURLOPT_PROTOCOLS_STR]       = 'https';
            $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https';
        } elseif (defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTPS;
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        return $opts;
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== 'https') {
            throw new RuntimeException('Povolené je jen HTTPS, dostal jsem: ' . $url);
        }
        $allowed = array_merge(self::ALLOWED_DOWNLOAD_HOSTS, ['api.github.com']);
        if (!in_array($host, $allowed, true)) {
            throw new RuntimeException('Nepovolený host pro stahování: ' . $host);
        }
    }

    // ---------- utils -----------------------------------------------------

    public static function isValidVersion(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;
    }

    /** Leží relativní cesta v chráněné množině? */
    public function isProtected(string $rel): bool
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        foreach (self::PROTECTED_PATHS as $p) {
            if ($rel === $p || str_starts_with($rel, $p . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return string|null relativní cesta s `/`, nebo null když leží mimo base */
    private function relativePath(string $base, string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $base)) {
            return null;
        }
        $rel = substr($path, strlen($base));

        return $rel === '' ? null : $rel;
    }

    private function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nelze vytvořit adresář ' . $dir . '.');
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        /** @var \SplFileInfo $item */
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    private function isDirWritable(string $dir): bool
    {
        $probe = $dir . '/.myinvoice-write-probe';
        $ok = @file_put_contents($probe, 'x') !== false;
        @unlink($probe);

        return $ok;
    }

    /** Ověří, že jde přepsat existující soubor (rename/unlink nejsou blokované). */
    private function canReplaceFiles(): bool
    {
        $probe = $this->rootDir . '/.myinvoice-replace-probe';
        if (@file_put_contents($probe, 'old') === false) {
            return false;
        }
        try {
            $this->replaceFile($probe, $probe);

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            $this->parked = [];
            @unlink($probe);
            @unlink($probe . '.myinvoice-upd');
            @unlink($probe . '.myinvoice-old');
        }
    }

    private function logPath(): string
    {
        return $this->stateDir . '/storage/' . $this->channel->statePrefix() . '-' . gmdate('Ymd\THis\Z') . '.log';
    }

    private function appendLog(string $path, string $line): void
    {
        $this->ensureDir(dirname($path));
        @file_put_contents($path, '[' . gmdate('H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'kB', 'MB', 'GB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
