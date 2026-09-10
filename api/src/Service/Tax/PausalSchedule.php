<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Rozvrh měsíčních záloh paušální daně (§ 7a ZDP) v čase.
 *
 * Na rozdíl od ostatních daňových konstant není měsíční záloha veličina „na
 * zdaňovací období" — může se změnit uprostřed roku (2026: 1. pásmo 9 984 Kč
 * do června, od 1. 7. 2026 jen 9 162 Kč). Proto se drží jako seznam segmentů
 * `['from' => 'YYYY-MM-01', 'band1' => …, 'band2' => …, 'band3' => …]`, kde
 * segment platí od svého data, dokud ho nevystřídá další.
 *
 * Roční částka (`pausal_annual`) je z rozvrhu ODVOZENÁ — součet dvanácti
 * měsíčních záloh daného roku — nikdy se neukládá, aby nemohla rozejít s
 * měsíčními hodnotami.
 *
 * Stateless čisté funkce, bez DB.
 */
final class PausalSchedule
{
    public const BANDS = ['band1', 'band2', 'band3'];

    /**
     * Setřídí a očistí segmenty; zahodí položky bez platného data nebo bez částek.
     * Duplicitní datum vyhrává poslední zapsané.
     *
     * @param mixed $segments
     * @return list<array<string,mixed>>
     */
    public static function normalize(mixed $segments): array
    {
        if (!is_array($segments)) {
            return [];
        }
        $byDate = [];
        foreach ($segments as $seg) {
            if (!is_array($seg)) {
                continue;
            }
            $from = self::normalizeDate($seg['from'] ?? null);
            if ($from === null) {
                continue;
            }
            $row = ['from' => $from];
            foreach (self::BANDS as $band) {
                if (!isset($seg[$band]) || !is_numeric($seg[$band])) {
                    continue 2;
                }
                $row[$band] = self::num($seg[$band]);
            }
            $byDate[$from] = $row;
        }
        ksort($byDate);
        return array_values($byDate);
    }

    /**
     * Měsíční zálohy platné v daném měsíci. Segmenty účinné až později se ignorují;
     * pokud žádný segment ještě nezačal (rok před začátkem rozvrhu), platí první.
     *
     * @param list<array<string,mixed>> $segments už normalizované
     * @return array<string,int|float> band => částka
     */
    public static function monthlyAt(int $year, int $month, array $segments): array
    {
        if ($segments === []) {
            return array_fill_keys(self::BANDS, 0);
        }
        $key = sprintf('%04d-%02d-01', $year, $month);
        $current = $segments[0];
        foreach ($segments as $seg) {
            if ((string) $seg['from'] <= $key) {
                $current = $seg;
            }
        }
        $out = [];
        foreach (self::BANDS as $band) {
            $out[$band] = $current[$band];
        }
        return $out;
    }

    /**
     * Roční částka per pásmo = součet dvanácti měsíčních záloh roku.
     *
     * Pro rok mimo rozsah rozvrhu (typicky fallback na poslední známý rok) tím
     * automaticky vychází „poslední platná sazba × 12" — ne omylem zopakovaný
     * schod uprostřed roku.
     *
     * @param list<array<string,mixed>> $segments už normalizované
     * @return array<string,int|float>
     */
    public static function annual(int $year, array $segments): array
    {
        $sums = array_fill_keys(self::BANDS, 0.0);
        for ($m = 1; $m <= 12; $m++) {
            foreach (self::monthlyAt($year, $m, $segments) as $band => $amount) {
                $sums[$band] += (float) $amount;
            }
        }
        return array_map(static fn (float $v): int|float => self::num($v), $sums);
    }

    /**
     * Rozpad roku na období se stejnou sazbou — pro UI a pro výpočet přeplatku
     * při změně sazby uprostřed roku.
     *
     * @param list<array<string,mixed>> $segments už normalizované
     * @return list<array{from:string,to:string,months:int,band1:int|float,band2:int|float,band3:int|float}>
     */
    public static function breakdown(int $year, array $segments): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $amounts = self::monthlyAt($year, $m, $segments);
            $last = $out === [] ? null : $out[count($out) - 1];
            $same = $last !== null;
            if ($same) {
                foreach (self::BANDS as $band) {
                    if ($last[$band] !== $amounts[$band]) {
                        $same = false;
                        break;
                    }
                }
            }
            if ($same) {
                $out[count($out) - 1]['to'] = sprintf('%04d-%02d-%02d', $year, $m, (int) date('t', mktime(0, 0, 0, $m, 1, $year)));
                $out[count($out) - 1]['months']++;
                continue;
            }
            $out[] = [
                'from'   => sprintf('%04d-%02d-01', $year, $m),
                'to'     => sprintf('%04d-%02d-%02d', $year, $m, (int) date('t', mktime(0, 0, 0, $m, 1, $year))),
                'months' => 1,
            ] + $amounts;
        }
        return $out;
    }

    /**
     * Zpětná kompatibilita: starší override v `tax_constants` drží jen roční
     * částky. Rozpadneme je na jeden plochý segment (roční / 12), aby měl editor
     * i UI co zobrazit. Roční částka se pak ale bere z override doslova — dělení
     * dvanácti nemusí vyjít na koruny a nechceme ji tichým přepočtem posunout.
     *
     * @param mixed $annual
     * @return list<array<string,mixed>>
     */
    public static function fromAnnual(int $year, mixed $annual): array
    {
        if (!is_array($annual)) {
            return [];
        }
        $seg = ['from' => sprintf('%04d-01-01', $year)];
        foreach (self::BANDS as $band) {
            if (!isset($annual[$band]) || !is_numeric($annual[$band])) {
                return [];
            }
            $seg[$band] = self::num(round((float) $annual[$band] / 12, 2));
        }
        return [$seg];
    }

    /** Datum segmentu — vždy 1. den měsíce (záloha je měsíční, dřív se změnit nemůže). */
    private static function normalizeDate(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) {
            return null;
        }
        $year  = (int) $m[1];
        $month = (int) $m[2];
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return null;
        }
        return sprintf('%04d-%02d-01', $year, $month);
    }

    /** Celé koruny drží jako int (kvůli JSON i testům), jinak zaokrouhlí na haléře. */
    private static function num(int|float|string $value): int|float
    {
        $f = (float) $value;
        return fmod($f, 1.0) === 0.0 ? (int) $f : round($f, 2);
    }
}
