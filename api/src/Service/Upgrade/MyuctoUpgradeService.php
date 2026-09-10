<?php

declare(strict_types=1);

namespace MyInvoice\Service\Upgrade;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Update\NativeUpdateService;
use MyInvoice\Service\Update\ReleaseChannel;
use MyInvoice\Service\Update\TargetRequirements;
use PDO;

/**
 * Přechod instalace MyInvoice.cz na MyÚčto.cz.
 *
 * MyÚčto je nástupce MyInvoice od téhož autora, postavený na stejném základu.
 * Migrace MyÚčta číslované 1000+ navazují na schéma MyInvoice, takže přechod
 * je **in-place**: vymění se kód a nad stávající databází se dojedou zbylé
 * migrace. Data se nikam nekopírují a druhá databáze se nezakládá.
 *
 * Pipeline je táž jako u běžné aktualizace ({@see NativeUpdateService}), jen
 * s jiným {@see ReleaseChannel} a s povinným dumpem databáze před swapem.
 * Ten dump je jediná cesta zpátky: aktualizace posune schéma o pár migrací,
 * kdežto tady se nad ním dojede celá sada migrací nástupce.
 *
 * Stav si služba drží ve vlastním jmenném prostoru (`app_meta` klíče
 * `myucto_*`, flag `storage/myucto-upgrade-*.json`), aby se rozjetý přechod
 * nepletl s běžnou aktualizací v Systém → Aktualizace.
 *
 * Docker se neřeší frontou pro watcher: host-side watcher sleduje flag běžné
 * aktualizace a o přechodu nic neví, a kontejner navíc nemůže přepsat vlastní
 * image — swap souborů uvnitř by zmizel při restartu. Docker proto dostane
 * přesné příkazy pro hosta (`cmd/docker-upgrade-to-myucto.sh`) místo fronty,
 * která by nikdy nedoběhla.
 */
final class MyuctoUpgradeService
{
    private const META_KEYS = [
        'myucto_latest_version',
        'myucto_release_notes',
        'myucto_release_url',
        'myucto_published_at',
        'myucto_last_check_at',
        'myucto_last_check_error',
    ];

    private const HTTP_TIMEOUT = 10;

    /** Po jak dlouhém tichu považujeme běžící přechod za spadlý. */
    private const FLAG_TTL = 1800;

    /**
     * První verze MyÚčta, jejíž `api/public/index.php` umí režim údržby.
     * Starší cíl okno po swapu nedohlídá — viz varování v {@see self::preflight()}.
     */
    private const FIRST_TARGET_WITH_MAINTENANCE_GATE = '5.17.0';

    /** Záloha starší než tohle už není důkaz, že uživatel zálohoval teď. */
    private const BACKUP_FRESH_SECONDS = 86400;

    private readonly PDO $db;
    private readonly string $rootDir;
    private readonly ReleaseChannel $channel;
    private readonly NativeUpdateService $native;

    public function __construct(Connection $connection)
    {
        $this->db      = $connection->pdo();
        $this->rootDir = Bootstrap::rootDir();
        $this->channel = ReleaseChannel::myucto();
        $this->native  = new NativeUpdateService(null, null, $this->channel);
    }

    // ---------- stav -------------------------------------------------------

    /** @return array<string,mixed> */
    public function getStatus(): array
    {
        $cache = $this->loadCache();
        $lastCheckAt = $cache['myucto_last_check_at'] ?? null;

        $stale = true;
        if (is_string($lastCheckAt) && $lastCheckAt !== '') {
            $stale = (time() - (int) strtotime($lastCheckAt)) > 86400;
        }

        return [
            'current_version'  => $this->getCurrentVersion(),
            'myucto_version'   => $cache['myucto_latest_version'] ?? null,
            'release_notes_md' => $cache['myucto_release_notes'] ?? null,
            'release_url'      => $cache['myucto_release_url'] ?? null,
            'published_at'     => $cache['myucto_published_at'] ?? null,
            'last_check_at'    => $lastCheckAt,
            'last_check_error' => $cache['myucto_last_check_error'] ?? null,
            'cache_stale'      => $stale,
            'environment'      => $this->detectEnvironment(),
            'in_progress'      => $this->isInProgress(),
            'progress'         => $this->loadProgress(),
            'last_result'      => $this->loadResult(),
            'backup'           => $this->latestBackup(),
            'steps'            => $this->native->steps(),
        ];
    }

    public function getCurrentVersion(): string
    {
        $path = $this->rootDir . '/VERSION';
        if (!is_file($path)) {
            return 'unknown';
        }
        $v = trim((string) @file_get_contents($path));

        return $v !== '' ? $v : 'unknown';
    }

    /**
     * Táž heuristika jako {@see \MyInvoice\Service\Update\VersionService}:
     * kontejner má `/.dockerenv` (Podman `/run/.containerenv`), nativní
     * instalace ne.
     */
    public function detectEnvironment(): string
    {
        return (is_file('/.dockerenv') || is_file('/run/.containerenv')) ? 'docker' : 'native';
    }

    /**
     * Nejnovější dump v `storage/backup/`. UI z toho udělá „zálohu máš
     * z dneška" / „poslední je stará X dní" — rozhodnutí ale nechává na
     * člověku, protože o záloze mimo server (snapshot VPS, ruční dump)
     * aplikace vědět nemůže.
     *
     * @return array{exists:bool, newest_at:?string, newest_file:?string, fresh:bool}
     */
    public function latestBackup(): array
    {
        $dir = $this->backupDir();
        $newest = null;
        $newestTs = 0;
        foreach (['/*.zip', '/*.sql.gz', '/*.sql'] as $glob) {
            foreach ((array) @glob($dir . $glob) as $f) {
                $ts = (int) @filemtime((string) $f);
                if ($ts > $newestTs) {
                    $newestTs = $ts;
                    $newest   = (string) $f;
                }
            }
        }
        if ($newest === null) {
            return ['exists' => false, 'newest_at' => null, 'newest_file' => null, 'fresh' => false];
        }

        return [
            'exists'      => true,
            'newest_at'   => date(\DateTimeInterface::ATOM, $newestTs),
            'newest_file' => basename($newest),
            'fresh'       => (time() - $newestTs) <= self::BACKUP_FRESH_SECONDS,
        ];
    }

    private function backupDir(): string
    {
        $configured = (string) Config::load($this->rootDir)->get('storage.backup_dir', '');
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }
        $dataDir = Config::resolveDataDir();

        return ($dataDir !== null ? rtrim($dataDir, '/\\') : $this->rootDir) . '/storage/backup';
    }

    // ---------- kontrola verze --------------------------------------------

    /** @return array<string,mixed> */
    public function refreshLatestVersion(): array
    {
        try {
            $data = $this->fetchLatestRelease();
            $tag  = ltrim((string) ($data['tag_name'] ?? ''), 'v');
            if ($tag === '') {
                throw new \RuntimeException('GitHub release neobsahuje tag_name.');
            }
            $this->saveCache([
                'myucto_latest_version'   => $tag,
                'myucto_release_notes'    => (string) ($data['body'] ?? ''),
                'myucto_release_url'      => (string) ($data['html_url'] ?? ''),
                'myucto_published_at'     => (string) ($data['published_at'] ?? ''),
                'myucto_last_check_at'    => date(\DateTimeInterface::ATOM),
                'myucto_last_check_error' => '',
            ]);
        } catch (\Throwable $e) {
            $this->saveCache([
                'myucto_last_check_at'    => date(\DateTimeInterface::ATOM),
                'myucto_last_check_error' => $e->getMessage(),
            ]);
        }

        return $this->getStatus();
    }

    // ---------- preflight --------------------------------------------------

    /**
     * @return array{
     *     ok:bool,
     *     supported:bool,
     *     blockers:list<string>,
     *     warnings:list<string>,
     *     checks:list<array{id:string, label:string, status:string, actual:string, expected:string}>
     * }
     */
    public function preflight(?string $targetVersion = null): array
    {
        if ($this->detectEnvironment() !== 'native') {
            // Databázi ověřuj i v Dockeru — je to tam JEDINÁ sdílená součást.
            // PHP a rozšíření si nástupce přiveze ve vlastním image, takže ta
            // půlka požadavků je z definice splněná, ale db kontejner zůstává
            // ten původní. Bez téhle kontroly by se na staré MariaDB přišlo až
            // z rozbité instalace: image vyměněný, migrace spadlé.
            $req = TargetRequirements::check(ReleaseChannel::myucto(), $this->rootDir);
            $dbChecks = array_values(array_filter(
                $req['checks'],
                static fn (array $c): bool => $c['id'] === 'db_version',
            ));
            $dbBlockers = array_values(array_filter(
                $req['blockers'],
                static fn (string $b): bool => str_contains($b, 'MariaDB') || str_contains($b, 'databáz'),
            ));

            return [
                'ok'        => false,
                'supported' => false,
                'blockers'  => array_merge([
                    'V Dockeru přechod neprovádí aplikace — kontejner nemůže přepsat vlastní image. '
                        . 'Spusť ho na hostu podle příkazů níž.',
                ], $dbBlockers),
                'warnings'  => [],
                'checks'    => $dbChecks,
            ];
        }

        $target = $targetVersion ?: ($this->loadCache()['myucto_latest_version'] ?? null);
        if (!is_string($target) || $target === '') {
            return [
                'ok'        => false,
                'supported' => true,
                'blockers'  => ['Není známá verze MyÚčta — spusť nejdřív kontrolu dostupné verze.'],
                'warnings'  => [],
                'checks'    => [],
            ];
        }

        $pf = $this->native->preflight($target);

        // Bez zálohovacího skriptu by pipeline spadla až na kroku `db_backup`,
        // tedy po stažení bundlu. Radši to řekni rovnou tady.
        if (!is_file($this->rootDir . '/api/bin/cron-backup.php')) {
            $pf['blockers'][] = 'Chybí api/bin/cron-backup.php — instalace neumí pořídit zálohu databáze, '
                . 'a bez ní přechod nespouštím.';
            $pf['ok'] = false;
        }

        // Okno swapu drží nejdřív tenhle kód, ale jakmile swap vymění
        // api/public/index.php, hlídá zbytek (migrace) už nástupce svým
        // vlastním. Verze bez brány tedy okno dokončí bez ochrany a requesty
        // v něm zase spadnou na fatál — ne kvůli chybě, ale protože ta verze
        // to ještě neuměla. Říct to dopředu je lepší než překvapit v logu.
        if (version_compare($target, self::FIRST_TARGET_WITH_MAINTENANCE_GATE, '<')) {
            $pf['warnings'][] = 'MyÚčto ' . $target . ' ještě neumí režim údržby (přibyl v '
                . self::FIRST_TARGET_WITH_MAINTENANCE_GATE . '). Po výměně souborů poběží migrace bez něj, '
                . 'takže requesty v tu dobu skončí chybou místo hlášky „probíhá aktualizace". '
                . 'Přechod tím netrpí, jen to na pár minut zvenku vypadá jako výpadek.';
        }

        $backup = $this->latestBackup();
        if (!$backup['exists']) {
            $pf['warnings'][] = 'Ve storage/backup/ není žádná záloha. Přechod si jednu pořídí sám, '
                . 'ale vlastní kopie mimo server je jistota navíc.';
        } elseif (!$backup['fresh']) {
            $pf['warnings'][] = 'Poslední záloha je z ' . (string) $backup['newest_at']
                . ' — přechod si pořídí čerstvou, ale zkontroluj, že zálohy opravdu vznikají.';
        }

        return $pf;
    }

    // ---------- spuštění ---------------------------------------------------

    /** @return array<string,mixed> */
    public function trigger(?string $targetVersion, string $requestedByEmail, bool $backupConfirmed): array
    {
        // Bez explicitní verze znamená „nasaď nejnovější", a to se musí zjistit
        // TEĎ, ne z cache. Ta se plní jen ruční kontrolou a jednou za den, takže
        // instalace, která se dívala včera, nasadí včerejší verzi — a protože
        // přechod je nevratný, dozví se o novější až po něm. Přesně tak se
        // stalo, že se místo 5.17.0 nasadila 5.16.0.
        //
        // Selhání sítě přechod nezastaví: spadne se zpátky na cache a řekne se
        // to. Nedostupný GitHub je špatný důvod, proč nemoct pokračovat, ale
        // mizerný důvod, proč o tom mlčet.
        $staleFallback = null;
        if ($targetVersion === null || $targetVersion === '') {
            $before = (string) ($this->loadCache()['myucto_latest_version'] ?? '');
            $this->refreshLatestVersion();
            $cache = $this->loadCache();
            $after = (string) ($cache['myucto_latest_version'] ?? '');

            if (($cache['myucto_last_check_error'] ?? '') !== '') {
                $staleFallback = 'Nepodařilo se ověřit nejnovější verzi MyÚčta ('
                    . (string) $cache['myucto_last_check_error'] . '), nasazuji naposledy známou '
                    . $after . '.';
            } elseif ($before !== '' && $after !== '' && $before !== $after) {
                $staleFallback = 'Mezitím vyšla novější verze — nasazuji ' . $after . ' místo ' . $before . '.';
            }
        }

        $target = $targetVersion ?: ($this->loadCache()['myucto_latest_version'] ?? null);
        if (!is_string($target) || $target === '') {
            return [
                'status'  => 'error',
                'message' => 'Není známá verze MyÚčta. Spusť nejdřív kontrolu dostupné verze.',
            ];
        }
        if (!NativeUpdateService::isValidVersion($target)) {
            return [
                'status'  => 'error',
                'message' => 'Verze „' . $target . '" není platný semver.',
            ];
        }

        // Vědomé potvrzení zálohy je podmínka, ne formalita: tohle je jediná
        // operace v aplikaci, kterou nelze vzít zpět jinak než obnovou dumpu.
        if (!$backupConfirmed) {
            return [
                'status'  => 'error',
                'message' => 'Přechod nespouštím bez potvrzení, že máš zálohu databáze.',
            ];
        }

        if ($this->isInProgress()) {
            return [
                'status'  => 'error',
                'message' => 'Přechod už běží — počkej na dokončení.',
            ];
        }

        if ($this->detectEnvironment() !== 'native') {
            return $this->dockerInstructions($target);
        }

        $pf = $this->preflight($target);
        if (!$pf['ok']) {
            return $this->manualFallback($target, $pf['blockers']);
        }

        $flag = $this->channel->flagPath($this->stateBaseDir());
        if (!is_dir(dirname($flag))) {
            @mkdir(dirname($flag), 0775, true);
        }
        $steps = $this->native->steps();
        $written = @file_put_contents($flag, json_encode([
            'mode'               => 'native',
            'product'            => 'myucto',
            'target_version'     => $target,
            'requested_by_email' => $requestedByEmail,
            'requested_at'       => date(\DateTimeInterface::ATOM),
            'heartbeat_at'       => date(\DateTimeInterface::ATOM),
            'step'               => 'preflight',
            'step_index'         => 1,
            'step_count'         => count($steps),
            'step_message'       => 'Startuji přechod na MyÚčto…',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            return $this->manualFallback($target, [
                'Nelze zapsat ' . $flag . ' — zkontroluj práva na storage/.',
            ]);
        }
        @unlink($this->channel->resultPath($this->stateBaseDir()));

        $worker = $this->rootDir . '/api/bin/myucto-upgrade.php';
        if (!is_file($worker)) {
            @unlink($flag);

            return $this->manualFallback($target, [
                'Chybí ' . $worker . ' — instalaci schází worker pro přechod.',
            ]);
        }

        $spawned = BackgroundProcess::spawnPhp(
            $worker,
            ['--target=' . $target, '--requested-by=' . $requestedByEmail],
            null,
            $this->rootDir,
            $diag,
        );
        if (!$spawned) {
            @unlink($flag);

            return $this->manualFallback($target, [
                'Nepodařilo se spustit worker na pozadí (' . (string) $diag . ').',
            ]);
        }

        return [
            'status'         => 'queued',
            'environment'    => 'native',
            'target_version' => $target,
            'message'        => 'Přechod na MyÚčto ' . $target . ' běží na pozadí: nejdřív se zazálohuje '
                . 'databáze, pak se nasadí MyÚčto a dojedou migrace. Průběh se ukazuje níž.',
            'warnings'       => $staleFallback !== null
                ? array_merge([$staleFallback], $pf['warnings'])
                : $pf['warnings'],
        ];
    }

    /**
     * @param  list<string> $blockers
     * @return array<string,mixed>
     */
    private function manualFallback(string $target, array $blockers): array
    {
        $bundle = $this->channel->bundleName($target);
        $base   = $this->channel->downloadBaseUrl($target);

        return [
            'status'         => 'manual_required',
            'environment'    => 'native',
            'target_version' => $target,
            'message'        => 'Automatický přechod tady spustit nejde. Na hostu ho provedeš takhle:',
            'blockers'       => $blockers,
            'instructions'   => [
                '# 1. Záloha databáze — bez ní dál nechoď',
                'php api/bin/cron-backup.php',
                '',
                '# 2. Production bundle MyÚčta (nepotřebuje Composer ani Node)',
                'curl -LO ' . $base . $bundle,
                'curl -LO ' . $base . $bundle . '.sha256',
                'sha256sum -c ' . $bundle . '.sha256',
                '',
                '# 3. Nasazení přes instalaci — konfigurace a data zůstávají',
                'tar -xzf ' . $bundle . ' --strip-components=1 \\',
                "  --exclude='cfg.php' --exclude='cfg.local.php' --exclude='cfg.docker.php' \\",
                "  --exclude='storage' --exclude='private' --exclude='log'",
                '',
                '# 4. Migrace MyÚčta nad stávající databází',
                'php api/bin/migrate.php',
            ],
        ];
    }

    /** @return array<string,mixed> */
    /**
     * Postup pro hosta. Skript je preferovaná cesta, ale nesmí se předpokládat,
     * že ho instalace vůbec má: kdo nasazoval podle manuálu z GHCR, stáhl si
     * jen `docker-compose.production.yml` a `cfg.sample.php` — žádné `cmd/`
     * u sebe nemá. Proto se nabízí i stažení skriptu, a to z tagu právě běžící
     * verze, ne z master: skript má odpovídat produktu, který ho spouští.
     *
     * Ruční varianta je záměrně kompletní včetně kroků, které skript dělá za
     * uživatele — kontroly MariaDB a vypnutí účetnictví. Neúplný ruční postup
     * je horší než žádný: vypadá hotově a tiše přeskočí to podstatné.
     */
    private function dockerInstructions(string $target): array
    {
        $version = $this->getCurrentVersion();
        $ref     = $version !== 'unknown' ? 'v' . $version : 'master';
        $rawBase = 'https://raw.githubusercontent.com/radekhulan/myinvoice/' . $ref . '/cmd/';

        $dbExec = 'docker compose exec -T db sh -c ';

        return [
            'status'         => 'manual_required',
            'environment'    => 'docker',
            'target_version' => $target,
            'message'        => 'V Dockeru přechod provede host — kontejner nemůže přepsat vlastní image. '
                . 'Skript ověří požadavky, zazálohuje databázi, přepne image na MyÚčto a dojede migrace. '
                . 'MyÚčto vyžaduje MariaDB 11.8 nebo novější; na starší se skript zastaví dřív, '
                . 'než na cokoliv sáhne.',
            'blockers'       => [],
            'instructions'   => [
                '# Na hostu, v adresáři s docker-compose.yml',
                'bash cmd/docker-upgrade-to-myucto.sh',
                '',
                '# Windows host (PowerShell 7):',
                'pwsh cmd/docker-upgrade-to-myucto.ps1',
                '',
                '# Nemáš-li u sebe repozitář (instalace z GHCR), stáhni si skript:',
                'curl -fsSL ' . $rawBase . 'docker-upgrade-to-myucto.sh -o docker-upgrade-to-myucto.sh',
                'bash docker-upgrade-to-myucto.sh',
                '',
                '# Nebo ručně, krok po kroku:',
                $dbExec . '\'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -e "SELECT VERSION()"\'  # >= 11.8',
                'docker compose exec -T app php api/bin/cron-backup.php',
                'docker compose stop app',
                'sed -i \'s#radekhulan/myinvoice#radekhulan/myucto#\' .env docker-compose*.yml',
                'docker compose pull app',
                'docker compose up -d app   # entrypoint sám dojede migrace',
                '',
                '# Po naběhnutí: instalace přišla z MyInvoice, kde se neúčtovalo, takže se',
                '# účetnictví vypne. Zapneš ho v Nastavení → Licenční moduly i s volbou režimu.',
                $dbExec . '\'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE"'
                    . ' -e "UPDATE supplier SET accounting_enabled = 0"\'',
            ],
        ];
    }

    // ---------- flag / progress -------------------------------------------

    /**
     * Běží přechod? Self-healing jako u běžné aktualizace: flag, který se
     * přestal hlásit déle než {@see self::FLAG_TTL}, se zruší, aby UI
     * nezůstalo viset na „probíhá…".
     */
    public function isInProgress(): bool
    {
        $flag = $this->channel->flagPath($this->stateBaseDir());
        if (!is_file($flag)) {
            return false;
        }
        $payload = json_decode((string) @file_get_contents($flag), true);
        $payload = is_array($payload) ? $payload : [];

        $activityTs = isset($payload['heartbeat_at']) ? strtotime((string) $payload['heartbeat_at']) : false;
        if ($activityTs === false) {
            $activityTs = isset($payload['requested_at']) ? strtotime((string) $payload['requested_at']) : false;
        }
        if ($activityTs === false) {
            $activityTs = @filemtime($flag) ?: time();
        }
        if (time() - $activityTs > self::FLAG_TTL) {
            @unlink($flag);
            $resultPath = $this->channel->resultPath($this->stateBaseDir());
            if (!is_file($resultPath)) {
                @file_put_contents($resultPath, json_encode([
                    'status'         => 'unknown',
                    'target_version' => $payload['target_version'] ?? null,
                    'applied_at'     => date(\DateTimeInterface::ATOM),
                    'message'        => 'Přechod se přestal hlásit — worker (api/bin/myucto-upgrade.php) zřejmě '
                        . 'spadl. Projdi log storage/myucto-upgrade-*.log a zkontroluj VERSION i databázi '
                        . 'dřív, než to zkusíš znovu.',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            }

            return false;
        }

        return true;
    }

    /** @return array<string,mixed>|null */
    public function loadProgress(): ?array
    {
        $flag = $this->channel->flagPath($this->stateBaseDir());
        if (!is_file($flag)) {
            return null;
        }
        $payload = json_decode((string) @file_get_contents($flag), true);
        if (!is_array($payload) || !isset($payload['step'])) {
            return null;
        }

        return [
            'step'         => (string) $payload['step'],
            'step_index'   => (int) ($payload['step_index'] ?? 0),
            'step_count'   => (int) ($payload['step_count'] ?? count($this->native->steps())),
            'step_message' => (string) ($payload['step_message'] ?? ''),
        ];
    }

    /** @return array<string,mixed>|null */
    public function loadResult(): ?array
    {
        $path = $this->channel->resultPath($this->stateBaseDir());
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /** @return array{status:string, cleared:bool} */
    public function cancel(): array
    {
        $flag = $this->channel->flagPath($this->stateBaseDir());
        $existed = is_file($flag);
        @unlink($flag);

        return ['status' => 'ok', 'cleared' => $existed];
    }

    private function stateBaseDir(): string
    {
        return Config::resolveDataDir() ?? $this->rootDir;
    }

    // ---------- internals --------------------------------------------------

    /** @return array<string,string> */
    private function loadCache(): array
    {
        $stmt = $this->db->prepare(
            'SELECT k, v FROM app_meta WHERE k IN ('
            . implode(',', array_fill(0, count(self::META_KEYS), '?')) . ')'
        );
        $stmt->execute(self::META_KEYS);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
            $out[(string) $k] = (string) $v;
        }

        return $out;
    }

    /** @param array<string,string> $kv */
    private function saveCache(array $kv): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO app_meta (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)'
        );
        foreach ($kv as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    }

    /** @return array<string,mixed> */
    private function fetchLatestRelease(): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: MyInvoice.cz/myucto-upgrade\r\nAccept: application/vnd.github+json\r\n",
                'timeout'       => self::HTTP_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($this->channel->latestReleaseApi(), false, $ctx);
        if ($raw === false) {
            throw new \RuntimeException('GitHub Releases API neodpovídá (timeout nebo network error).');
        }
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('#^HTTP/\S+\s+(\d+)#', (string) $statusLine, $m) || (int) $m[1] >= 400) {
            throw new \RuntimeException('GitHub Releases API vrátil ' . $statusLine);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('GitHub Releases API vrátil ne-JSON odpověď.');
        }

        return $data;
    }
}
