# AGENTS.md — pravidla pro AI agenty a přispěvatele

Pokyny pro práci s tímto repozitářem (Claude Code, Codex, Cursor, Copilot a další).
Platí pro celý repozitář. Obecný popis projektu je v [README.md](README.md),
vývojářská spec v [`source/`](source/00-README.md).

## ⚠️ Nejdřív zvaž MyÚčto.cz

**Těžiště vývoje se přesunulo do [radekhulan/myucto](https://github.com/radekhulan/myucto).**
MyÚčto je nástupce MyInvoice; sdílí s ním společný základ i historii v gitu
a pravidelně z něj přebírá změny. Veškerá funkcionalita MyInvoice v něm zůstává
navždy zdarma, nadstavba (podvojné účetnictví, uzávěrky, majetek, sklad,
EPO podání, rozšířené opravy DPH) je volitelně komerční.

**Praktický důsledek pro agenty i přispěvatele:**

- **Novou funkci piš rovnou do MyÚčta**, ne sem. Odtud by se do MyÚčta stejně
  musela portovat a v MyInvoice by zůstala neúplná (chybí jí účetní vrstva,
  na kterou se váže).
- **Opravu chyby** dělej tam, kde chyba je. Když je ve sdíleném základu, oprav ji
  **v MyÚčtu** — MyÚčto z MyInvoice merguje, ne naopak, takže oprava udělaná zde
  se do MyÚčta dostane až dalším mergem a hrozí konflikt s tamní úpravou téhož
  místa. Do MyInvoice patří jen to, co se MyÚčta netýká.
- **Než začneš, ověř, jestli to v MyÚčtu už není hotové.** Řada věcí, které tu
  chybí, tam existuje — nemá smysl je psát podruhé.
- **Aditivní styl platí i tady.** MyÚčto tenhle repozitář merguje, takže velký
  refaktor sdílených souborů mu prodraží každý další merge. Drž změny malé
  a lokalizované.
- **Rozsah čísel migrací:** `0125`–`0999` patří MyInvoice, `1000+` je vyhrazené
  pro MyÚčto. Nikdy sem nezakládej migraci s číslem `1000` a vyšším, i kdyby
  řada zdánlivě volná byla — kolidovala by při mergu.

## O projektu

MyInvoice.cz — český self-hosted fakturační a účetní systém (vystavené + přijaté
faktury, multi-supplier, DPH/KH/SH výkazy, EPO XML, CRM, REST API).
Backend PHP 8.5 + Slim 4, frontend Vue 3 + TypeScript + Vite + Tailwind,
databáze MariaDB 10.6+ (doporučeno 11.x).

## Layout repozitáře

- `api/` — PHP backend (Slim, autowired actions, services, repositories); `api/bin/` = CLI skripty, `api/tests/` = PHPUnit
- `web/` — Vue 3 + TS frontend; zdrojáky ve `web/src/`, lokalizace ve `web/src/i18n/`
- `web/dist/` — produkční build frontendu; **gitignorovaný**, staví se lokálně a v CI do release bundlu
- `db/migrations/` — SQL migrace (číslované, idempotentní)
- `manual/` — uživatelský manuál (Markdown, česky); `manual/generated/` = vyrenderované HTML
- `source/` — vývojářská spec a plány
- `tools/` — pomocné skripty (generování manuálu, převody obrázků)
- `cmd/` — cron/deploy wrappery (`.sh` + `.cmd`/`.ps1`)

## Příkazy

```bash
# Frontend — build (NUTNÉ po každé změně web/src; web/dist/ se necommituje)
cd web && pnpm build            # = vue-tsc --noEmit && vite build (npm run build funguje též)
cd web && pnpm type-check       # jen typová kontrola

# PHP testy (PHPUnit 13)
cd api && php vendor/bin/phpunit                  # vše
cd api && php vendor/bin/phpunit --filter Xyz     # podmnožina

# Migrace — VŽDY přes migrate.php, NIKDY mysql klientem přímo
php api/bin/migrate.php
php api/bin/migrate.php --status

# Manuál — regenerovat po každé změně manual/*.md
php tools/generateManualHtml.php
php tools/exportManualToPdf.php
```

## Tvrdá pravidla

### Migrace
- Nová migrace = nový číslovaný soubor v `db/migrations/`, spouští se **výhradně** přes `php api/bin/migrate.php`.
- Každá migrace musí být **idempotentní** (opakovatelně spustitelná): používej nativní MariaDB `IF [NOT] EXISTS` (`ADD COLUMN IF NOT EXISTS`, `CREATE TABLE IF NOT EXISTS`, …), ne PREPARE/EXECUTE triky.
- Cílová DB je MariaDB 10.6+: v SQL preferuj **window functions a CTE** před vnořenými subselecty; nepoužívej `SQL_CALC_FOUND_ROWS`.

### i18n
- Veškeré nové UI texty přes `t()` z vue-i18n — **nikdy** natvrdo česky/anglicky v šablonách. Vždy doplň **obě** locale (`web/src/i18n/cs.json` i `en.json`).
- Pole/seznamy překladů přes `tm()` + `rt()` — `t()` pole stringifikuje.
- Literální `{` `}` v textu zprávy escapuj jako `{'{token}'}` — jinak to vue-i18n bere jako interpolaci a render tiše spadne.

### OpenAPI sync
- Při **jakékoli** změně veřejného API (nová route, změna serializace, nový/změněný sloupec promítnutý do JSON, nové query/body pole) ihned aktualizuj `api/openapi.yaml` — jak `paths` (`/api/v1/*`), tak `components/schemas`.
- Po editaci ověř: YAML se parsuje, žádné duplicitní klíče (PyYAML je tiše přepíše — použij striktní loader), žádné dangling `$ref`.
- Veřejné API je kurátorovaný read-only subset; mutace číselníků a interní plumbing se nedokumentují.

### DPH a daňová správnost
- Veškerá evidence DPH jde přes `VatLedgerService` — nikdy neobcházet vlastním SQL.
- Výkazy a rekapitulace sumují **řádky** (`invoice_items` / per-řádkové totály), ne hlavičku dokladu.
- Při zásahu do daní/DPH proaktivně ověř daňovou správnost (zařazení do správného období), ne jen „napojení na existující kód". Kontroluj **symetrii** filtrů: obě strany evidence proti všem typům dokladů (`invoice_type` vs `document_kind`); proforma = záloha na vstupu.
- Každá nová cesta, která tvoří doklad z jiného dokladu (proforma → faktura, dobropis, kopie, recurring), musí přenést `prices_include_vat` — jinak se brutto cena chybně přepočítá jako netto.
- Agregace nákladů z `purchase_invoices` musí vyřadit spárované/zaplacené zálohové doklady (jinak se náklad počítá 2×).
- Dotazy na pohledávky (unpaid/overdue/aging/cashflow) musí mít guard `(invoice_type NOT IN ('invoice','proforma') OR amount_to_pay > 0)` — finální doklad ze zaplacené proformy má `amount_to_pay = 0`.

### Multiplatformnost (Windows / Linux / Docker)
- Veškerý kód musí být spustitelný a testovatelný na **Windows (IIS), Linuxu i v Dockeru** — žádná platformně specifická zkratka, která jinde rozbije běh.
- Proto jsou pomocné skripty záměrně **„zdvojené"**: ke každému `.sh` existuje ekvivalentní `.ps1`/`.cmd` (typicky v `cmd/`). Při změně jednoho udržuj v synchronizaci i druhý; nový skript přidávej rovnou v obou variantách.
- Webserver konfigurace existuje paralelně pro **Apache (`.htaccess`) i IIS (`web.config`)** — změny rewrite pravidel, hlaviček apod. promítej do obou.
- V PHP nepředpokládej konkrétní oddělovač cest ani casing souborového systému (viz guardy níže); rozdíly platforem řeš v kódu, ne podmínkou „jen na Windows".

### Runtime cesty a bezpečnost
- Cesty do `storage/` a `log/` vždy přes `RuntimePaths` (respektuje `MYINVOICE_DATA_DIR`), nikdy `Bootstrap::rootDir()`. Statické assety zůstávají na root dir.
- Path-traversal guardy musí být case-insensitive (Windows `realpath()` vrací nekonzistentní casing — porovnávej `strtolower` obě strany).
- Citlivé údaje (hesla, API klíče, connection stringy) nikdy do kódu, testů ani dokumentace.

### Frontend
- Po každé změně ve `web/src` spusť `pnpm build` — aplikace běží z `web/dist/`, takže bez buildu
  změnu neuvidíš ani neotestuješ; samotný `vue-tsc` nestačí. `web/dist/` je gitignorovaný a do
  release balíčku jej znovu staví CI (`.github/workflows/docker-publish.yml`).
- Drž se existujícího design language (sjednocené boxy, status badges, mobile cards) — před vymýšlením nového vzoru se podívej, jak to dělají sousední stránky.

## Testy

- PHPUnit 13, testy v `api/tests/{Unit,Integration,Architecture}`. Nové chování pokrývej testem; PR nesmí rozbít existující testy.
- **Pouze syntetická testovací data** — repo je veřejné. Žádné reálné doklady, výpisy, IBANy, čísla dokladů ani identifikátory skutečných protistran.
- České bankovní účty v testech musí projít mod-11 validací; ověřený placeholder: `1000000005 / 0100`.
- ISDOC export se validuje proti oficiálnímu XSD (`api/xsd/isdoc-invoice-6.0.2.xsd`).

## Manuál

- Zdroj v `manual/*.md` (česky). Při změně funkcionality viditelné uživatelem aktualizuj příslušnou kapitolu.
- Piš **jen aktuální stav** — žádné „od verze X.Y.Z", žádné odkazy na historii vývoje.
- Po změně Markdownu regeneruj výstupy: `php tools/generateManualHtml.php` + `php tools/exportManualToPdf.php`.
- Vzhled manuálu (`manual/manual.css`) zrcadlí design tokeny aplikace (`web/src/styles/main.css`) včetně dark mode — při změně tokenů udržuj synchronizaci.

## Konvence

- Drž se stylu okolního kódu (pojmenování, idiomy, hustota komentářů). Nepřidávej komentáře, které kód jen opakují.
- **Nepřejmenovávej interní identifikátory** — namespace `MyInvoice\`, proměnné
  `MYINVOICE_*`, cookie, localStorage a Redis klíče, ISDOC namespace. MyÚčto je
  sdílí, takže přejmenování rozbije merge i kompatibilitu dat. Branding se mění
  jen v tom, co vidí uživatel (UI texty, e-maily, dokumentace, loga).
- Commit messages česky, conventional-commits styl: `feat(scope): …`, `fix(scope): …`, `release: X.Y.Z — …` (viz `git log`).
- Změny v `CHANGELOG.md` a `VERSION` dělá maintainer při release — v běžném PR na ně nesahej.
- Necommituj vygenerované artefakty (`web/dist/`, `manual/generated/`, `manual/manual.pdf` jsou gitignorované).
