# 15 — Částečné úhrady faktur, evidence plateb, daňové doklady k přijaté platbě (#89 + rozšíření)

> Stav: schváleno k implementaci 2026-06-11. Pokrývá issue #89 (evidence plateb, částečné úhrady,
> N:1 párování) + rozšíření: zálohové faktury s částečnou úhradou a **daňový doklad k přijaté
> platbě** (DUZP = datum platby) ke každé přijaté úhradě zálohy.

## Co už existuje (audit 2026-06-11)

- **Emailová avíza** — hotová (`BankEmailNoticeScanner` + parsery ČSOB/UniCredit/RB/regex,
  cron `cron-bank-email-notices` à 30 min). Avízo → syntetická `bank_transactions` řádka →
  `StatementMatcher::match()`. Z nového kódu tedy avíza profitují automaticky.
- **Bankovní párování** — `StatementMatcher`: příchozí platby 1:1 (`bank_transactions.matched_invoice_id`,
  jen exact ±0,05 / partial ±1 Kč „k ruční kontrole“), odchozí N:N přes `payment_matches`.
- **Proforma → finální doklad** — `FinalFromProformaCreator` (kopie položek, `advance_paid_amount`
  = total proformy, `amount_to_pay` generovaný sloupec `total_with_vat - advance_paid_amount`).
  DUZP finálního dokladu = datum platby. Proforma je z VAT ledgeru vyloučena
  (`invoice_type != 'proforma'`), daňová událost teče výhradně přes finální doklad.
- **Stavy faktur** — ENUM `draft/issued/sent/reminded/paid/cancelled`; ~50 SQL dotazů filtruje
  `status IN ('issued','sent','reminded'[,'paid'])`.

## Klíčová architektonická rozhodnutí

1. **Status ENUM se NEROZŠIŘUJE.** `invoices.status` zůstává lifecycle (draft→issued→sent→reminded
   →paid→cancelled). Platební stav je **odvozená dimenze `payment_status`**
   (`unpaid | partially_paid | paid | overpaid`) počítaná z nového stored sloupce
   `invoices.paid_total` vs. `amount_to_pay`. Důvod: ~50 SQL filtrů na status zůstane netknutých
   (DPH/KH/CRM/exporty), částečně uhrazená faktura **zůstává pohledávkou** (status `issued/sent/
   reminded`) — jen se v pohledávkových agregacích sčítá `amount_to_pay - paid_total`.
2. **`amount_to_pay` formule se NEMĚNÍ** (`total_with_vat - advance_paid_amount`, STORED).
   Je to „K úhradě“ na dokumentu (PDF) — statická veličina dokladu. Platby jsou post-dokladové
   události; zbývá-k-úhradě = `amount_to_pay - paid_total` se počítá v aplikaci/SQL.
3. **Nová tabulka `invoice_payments`** (N:1 k faktuře) — pro vydané faktury i proformy.
   Příchozí bankovní párování zůstává na `bank_transactions.matched_invoice_id` (ten je přirozeně
   N:1 — víc transakcí může ukazovat na jednu fakturu); platba má FK `bank_transaction_id`
   (UNIQUE → idempotence rematch).
4. **Nový `invoice_type = 'tax_document'`** = daňový doklad k přijaté platbě (§ 28 odst. 2 ZDPH).
   Vzniká k platbě zálohy (proformy): draft, `parent_invoice_id` = proforma, `tax_date` (DUZP)
   = datum platby, položky DPH **shora** (koeficient § 37, `prices_include_vat=1`), částka platby
   rozdělena mezi sazby proformy poměrně dle brutto vah. `advance_paid_amount` = brutto platby
   → `amount_to_pay = 0` → auto-paid při vystavení. Čísluje se v řadě **faktur** (sdílí counter
   i template s `invoice` — žádná nová konfigurace). Jen pro plátce DPH a ne-reverse-charge
   (u RC se záloha nedaní — daň vzniká až k DUZP plnění).
5. **Finální doklad (vyúčtování) dle § 37a ZDPH**: existují-li k proformě vystavené daňové
   doklady k platbě, `FinalFromProformaCreator` přidá **záporné odpočtové řádky** per doklad
   per sazba (základ z daňového dokladu, DPH dopočtená standardně — § 37a definuje základ jako
   rozdíl základů). `advance_paid_amount` finálu = `paid_total - Σ brutto daňových dokladů`
   (= přijaté platby bez vlastního daňového dokladu). Bez daňových dokladů zůstává dnešní
   chování (plné položky, advance = zaplaceno).
6. **Mark-paid = zkratka** (zpětná kompatibilita dle issue): vytvoří platbu na zbývající částku.
   Unmark-paid (admin) smaže platby (blokováno, má-li platba bankovní vazbu nebo vystavený
   daňový doklad).

## DB — migrace 0108

```sql
CREATE TABLE IF NOT EXISTS invoice_payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id ... NOT NULL,
  invoice_id BIGINT UNSIGNED NOT NULL,            -- FK invoices, ON DELETE CASCADE
  paid_on DATE NOT NULL,
  amount DECIMAL(12,2) NOT NULL,                  -- v měně faktury, > 0
  currency CHAR(3) NOT NULL,                      -- denorm kód měny faktury
  variable_symbol VARCHAR(20) NULL,
  bank_reference VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  source ENUM('manual','mark_paid','bank','legacy') NOT NULL DEFAULT 'manual',
  bank_transaction_id BIGINT UNSIGNED NULL,       -- UNIQUE, FK ON DELETE SET NULL
  tax_document_invoice_id BIGINT UNSIGNED NULL,   -- FK invoices ON DELETE SET NULL
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS paid_total DECIMAL(12,2) NOT NULL DEFAULT 0;
ALTER TABLE invoices MODIFY invoice_type ENUM('invoice','proforma','credit_note','cancellation','tax_document');
-- Backfill (idempotentní): pro status='paid' faktury/proformy s amount_to_pay>0 vlož
-- 'legacy' platbu (amount=amount_to_pay, paid_on=COALESCE(paid_at,issue_date)),
-- napoj bank tx kde existuje právě jedna auto/manual matched; přepočti paid_total.
```

## Backend

**Nové:**
- `Service/Invoice/InvoicePaymentService` — record/delete platby; přepočet `paid_total`;
  přechod statusu: zbývá ≤ 0,05 → `paid` (+ `paid_at` = datum poslední platby, PDF invalidate,
  stats, volitelný děkovný e-mail); smazání platby z paid faktury → revert na `sent`/`issued`.
  `payment_status` resolver (tolerance 0,05): paid_total=0→unpaid; <due→partially_paid;
  ≈due→paid; >due+tol→overpaid.
- `Service/Invoice/PaymentTaxDocumentCreator` — daňový doklad k platbě (viz rozhodnutí 4);
  alokace platby mezi sazby = čistá statická funkce (testovatelná, largest-remainder).
- Akce + routy: `GET/POST /api/invoices/{id}/payments`, `DELETE /api/invoices/{id}/payments/{pid}`,
  `POST /api/invoices/{id}/payments/{pid}/tax-document`.

**Úpravy:**
- `MarkPaidAction` → platba na zbytek přes service (response beze změny).
- `UnmarkPaidAction` → guard i na platby s bank vazbou / daňovým dokladem; smaže platby.
- `StatementMatcher` (příchozí) → porovnává **zbývající** částku (`amount_to_pay - paid_total`);
  exact/overpay → platba + paid; podplatba s exact VS shodou → platba + `auto_partial`
  (faktura zůstává pohledávkou); u proformy: plná úhrada → final draft (tax_date = posted_at),
  částečná → draft daňového dokladu k platbě (plátce DPH, ne-RC).
- `BankStatementAction` manual match/unmatch → vytvoření/smazání platby.
- `FinalFromProformaCreator`, `IssueFinalFromProformaAction` (povolit i částečně uhrazenou
  proformu), `InvoiceAmountPolicy` (tax_document), `InvoiceValidation`, `VarsymbolGenerator`
  (alias tax_document→invoice řada), `IssueInvoiceAction` (auto-paid tax_document,
  paid_at = tax_date), PDF (`docTypeLabel`, twig label odpočtu dle typu).
- Pohledávkové agregace: `SUM(amount_to_pay)` → `SUM(amount_to_pay - paid_total)` a guard
  `amount_to_pay > 0` → `amount_to_pay - paid_total > 0` v: SummaryAction (overdue, unpaid
  upcoming, aging, splatné dnes), CrmAggregationService, GetProjectAction, GetClientAction,
  InvoiceRepository (unpaid/overdue filtry). Tržby/DPH dotazy: doplnit `tax_document` tam, kde
  se filtruje `invoice_type` (revenue = taxdoc + final s odpočty ⇒ bez dvojího počítání).
- VatLedger: `tax_document` projde přirozeně (`!= 'proforma'`); ověřit type filtry.

## Frontend

- Detail faktury: box **Platby** (datum, částka, zdroj, VS/reference, poznámka, odkaz na bank tx
  / daňový doklad, mazání) + „Zbývá uhradit“; dolní lišta: **„Částečná úhrada“** (modal: částka
  předvyplněná zbytkem, datum, VS/reference/poznámka, u proformy checkbox „vystavit daňový doklad
  k platbě“) vedle stávajícího „Faktura zaplacena“ (default 100 %).
- Badge `partially_paid` (amber) / `overpaid` (purple) — zobrazované místo lifecycle badge,
  když nesou informaci; seznam faktur + detail.
- `invoices.ts`: typy `InvoicePayment`, `payment_status`, `paid_total` + API volání; i18n cs+en.

## OpenAPI

`/api/v1/invoices/{id}/payments` (GET/POST), `/payments/{pid}` (DELETE), schema `InvoicePayment`,
`Invoice` + `paid_total`/`payment_status`, `invoice_type` enum + `tax_document`.

## Testy

Unit: payment_status resolver + tolerance; alokace platby mezi sazby (1 a více sazeb, zbytky);
deduction řádky finálu + advance výpočet; VarsymbolGenerator alias; InvoiceAmountPolicy
(tax_document); StatementMatcher partial/overpay (dle stávajícího stylu testů).

## Mimo rozsah (vědomě)

- Částečné úhrady přijatých faktur (payment_matches už N:N umí; UI sumarizace případně později).
- Kurzové rozdíly částečných úhrad cizoměnových faktur (platba se eviduje v měně faktury;
  částečná CZK platba EUR faktury se přepočítá kurzem faktury, zatímco úplný FX match
  vyrovná celý zbytek a skutečnou CZK částku ponechá na bankovní transakci).
- Přeplatky → automatický dobropis / vratka.
