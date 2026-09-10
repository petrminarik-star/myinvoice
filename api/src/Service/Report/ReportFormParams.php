<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Parser query parametrů `form` (khdph_forma / dapdph_forma) a `d_zjist`
 * pro generování opravných/následných/dodatečných podání — sdílený mezi
 * KontrolniHlaseniAction a DphPriznaniAction.
 *
 * Pravidla:
 *   - `form` musí být z povolené množiny (default 'B' = řádné),
 *   - `d_zjist` (datum zjištění důvodů, § 141 DŘ / § 101f ZDPH) je POVINNÉ
 *     u následných/dodatečných forem (N/E u KH, D/E u DP3), volitelné u O,
 *   - formát přesně DD.MM.YYYY (EPO formát data) a musí jít o platné datum.
 */
final class ReportFormParams
{
    /**
     * @param array<string,mixed> $query query parametry requestu
     * @param list<string> $allowedForms povolené hodnoty form
     * @param list<string> $formsRequiringDzjist formy s povinným d_zjist
     * @return array{form:string, d_zjist:?string, error:?string}
     */
    public static function fromQuery(array $query, array $allowedForms, array $formsRequiringDzjist): array
    {
        $form = strtoupper(trim((string) ($query['form'] ?? 'B')));
        if ($form === '') {
            $form = 'B';
        }
        $dZjist = trim((string) ($query['d_zjist'] ?? ''));
        $dZjist = $dZjist === '' ? null : $dZjist;

        if (!in_array($form, $allowedForms, true)) {
            return ['form' => 'B', 'd_zjist' => null,
                    'error' => "Neplatná forma podání '{$form}'. Povolené: " . implode(', ', $allowedForms) . '.'];
        }
        if ($dZjist !== null && !self::isValidEpoDate($dZjist)) {
            return ['form' => $form, 'd_zjist' => null,
                    'error' => "Neplatné datum zjištění '{$dZjist}' — očekává se formát DD.MM.YYYY."];
        }
        if ($dZjist === null && in_array($form, $formsRequiringDzjist, true)) {
            return ['form' => $form, 'd_zjist' => null,
                    'error' => 'Pro tuto formu podání je povinné datum zjištění důvodů (d_zjist, formát DD.MM.YYYY).'];
        }
        // d_zjist bez smyslu u řádného podání — tiše zahodit, ať se nepropíše do XML.
        if ($form === 'B') {
            $dZjist = null;
        }
        return ['form' => $form, 'd_zjist' => $dZjist, 'error' => null];
    }

    /** Přesný formát DD.MM.YYYY + reálná platnost data (žádné 31.02.). */
    public static function isValidEpoDate(string $value): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        return $dt !== false && $dt->format('d.m.Y') === $value;
    }
}
