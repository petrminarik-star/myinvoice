<?php

declare(strict_types=1);

namespace MyInvoice\Service\Update;

/**
 * Odkud se bere production bundle, který {@see NativeUpdateService} nasazuje.
 *
 * Pipeline updateru (stáhni → ověř SHA → rozbal → zazálohuj → přepiš →
 * zmigruj) je pro obě cesty stejná; liší se jen repozitář, jméno assetu
 * a produkt, na který se instalace dostane:
 *
 *   • {@see self::myinvoice()} — běžná aktualizace MyInvoice.cz na novější verzi
 *   • {@see self::myucto()}    — přechod instalace na MyÚčto.cz
 *
 * Přechod na MyÚčto není update, ale výměna produktu za jeho nástupce.
 * Technicky je to táž operace: MyÚčto vychází ze stejného základu a jeho
 * migrace 1000+ navazují na schéma MyInvoice, takže se pouštějí nad
 * existující databází (in-place, bez kopírování dat jinam).
 */
final class ReleaseChannel
{
    /**
     * @param string $repo        GitHub `owner/repo` s releasy
     * @param string $bundlePrefix prefix assetu i top-level adresáře v archivu
     *                             (`<prefix>-X.Y.Z.tar.gz`, uvnitř `<prefix>-X.Y.Z/`)
     * @param string $productName  jméno produktu pro logy a hlášky
     * @param bool   $requiresDatabaseBackup  vynutit dump databáze před swapem
     * @param ?string $minPhpVersion     minimální PHP cílového produktu (null = neověřovat)
     * @param ?string $minMariaDbVersion minimální MariaDB cílového produktu (null = neověřovat)
     * @param list<string> $requiredExtensions PHP rozšíření, bez kterých cíl nenaběhne
     * @param bool $isSuccessorHandover  tohle není update, ale výměna produktu za nástupce
     *                                   (po migracích se doladí stav — viz
     *                                   {@see NativeUpdateService::handoverToSuccessor()})
     */
    private function __construct(
        public readonly string $repo,
        public readonly string $bundlePrefix,
        public readonly string $productName,
        public readonly bool $requiresDatabaseBackup = false,
        public readonly ?string $minPhpVersion = null,
        public readonly ?string $minMariaDbVersion = null,
        public readonly array $requiredExtensions = [],
        public readonly bool $isSuccessorHandover = false,
    ) {}

    /**
     * Aktualizace v rámci téhož produktu požadavky neověřuje: aplikace zrovna
     * běží, takže je prostředí z definice splňuje. Kdyby některé vydání laťku
     * zvedlo, doplní se sem stejně jako u nástupce.
     */
    public static function myinvoice(): self
    {
        return new self('radekhulan/myinvoice', 'myinvoice', 'MyInvoice.cz');
    }

    /**
     * Požadavky MyÚčta drž v souladu s jeho `EnvironmentCheckService`
     * (MIN_PHP, MIN_MARIADB, REQUIRED_EXTENSIONS) — instalace je ověří dřív,
     * než sáhne na první soubor. Po swapu už je na zjištění „tohle prostředí
     * na to nestačí" pozdě: kód je vyměněný a zpátky vede jen obnova zálohy.
     */
    public static function myucto(): self
    {
        return new self(
            'radekhulan/myucto',
            'myucto',
            'MyÚčto.cz',
            requiresDatabaseBackup: true,
            minPhpVersion: '8.5.0',
            minMariaDbVersion: '11.8',
            requiredExtensions: [
                'bcmath', 'ctype', 'dom', 'fileinfo', 'filter', 'gd', 'iconv', 'json',
                'libxml', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'SimpleXML',
                'xmlreader', 'zip', 'zlib',
            ],
            isSuccessorHandover: true,
        );
    }

    /** API endpoint pro release podle tagu — volá se s připojenou verzí (`…/v` . $target). */
    public function releaseByTagApi(): string
    {
        return 'https://api.github.com/repos/' . $this->repo . '/releases/tags/v';
    }

    public function latestReleaseApi(): string
    {
        return 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
    }

    public function bundleName(string $version): string
    {
        return $this->bundlePrefix . '-' . $version . '.tar.gz';
    }

    /** Top-level adresář uvnitř tarballu — každá cesta v archivu ho musí mít. */
    public function archivePrefix(string $version): string
    {
        return $this->bundlePrefix . '-' . $version . '/';
    }

    /** Glob pro úklid stažených bundlů v pracovním adresáři. */
    public function bundleGlob(): string
    {
        return $this->bundlePrefix . '-*.tar.gz';
    }

    /**
     * Prefix stavových souborů ve `storage/` (flag, result, log).
     *
     * Přechod na MyÚčto má vlastní jmenný prostor, aby se nemíchal
     * s běžnou aktualizací MyInvoice — jinak by rozjetý přechod vypadal
     * v „Aktualizace" jako běžící update a naopak.
     */
    public function statePrefix(): string
    {
        return $this->bundlePrefix === 'myinvoice' ? 'upgrade' : $this->bundlePrefix . '-upgrade';
    }

    public function flagPath(string $stateDir): string
    {
        return $stateDir . '/storage/' . $this->statePrefix() . '-requested.json';
    }

    public function resultPath(string $stateDir): string
    {
        return $stateDir . '/storage/' . $this->statePrefix() . '-result.json';
    }

    public function downloadBaseUrl(string $version): string
    {
        return 'https://github.com/' . $this->repo . '/releases/download/v' . $version . '/';
    }
}
