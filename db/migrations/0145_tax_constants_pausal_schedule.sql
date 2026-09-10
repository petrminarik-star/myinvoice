-- Paušální daň: měsíční záloha se může změnit uprostřed roku (od 1. 7. 2026 klesá
-- 1. pásmo z 9 984 na 9 162 Kč), roční částka je nově ODVOZENÁ z rozvrhu
-- `pausal_monthly` (viz Service\Tax\PausalSchedule) a do override se neukládá.
--
-- Starší override v `tax_constants` drží jen roční částky. Řádky, které jsou jen
-- kopií tehdejších výchozích hodnot (vznikly „přidáním roku" nebo uložením
-- nezměněného editoru), by tak zakonzervovaly chybnou roční částku 119 808 Kč.
-- Takové řádky klíč zahodí a spadnou na opravený default; ručně upravené hodnoty
-- (jiné než tehdejší defaulty) zůstávají netknuté — respektujeme záměr admina.
--
-- Idempotentní: po odstranění klíče podmínka už neplatí.
UPDATE tax_constants
   SET data = JSON_REMOVE(data, '$.pausal_annual')
 WHERE JSON_VALUE(data, '$.pausal_monthly') IS NULL
   AND JSON_EXTRACT(data, '$.pausal_monthly') IS NULL
   AND CAST(JSON_VALUE(data, '$.pausal_annual.band2') AS UNSIGNED) = 200940
   AND CAST(JSON_VALUE(data, '$.pausal_annual.band3') AS UNSIGNED) = 325668
   AND CAST(JSON_VALUE(data, '$.pausal_annual.band1') AS UNSIGNED) IN (104592, 119808);
