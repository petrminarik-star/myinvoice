-- Wallet (BudgetBakers) integrace odstraněna (revert 9d42f61) — účet Wallet
-- zrušen, párování plateb přebírá externí tok mimo aplikaci. Úklid stavu po
-- migraci 0145: wallet transakce/výpisy/mapování/joby pryč, ENUMy zpět na
-- stav 0136/0069, credentials sloupce pryč. Na instalaci, kde 0145 nikdy
-- neběžela, je celá migrace no-op; opakovaně spustitelná.
--
-- Úhrady faktur se NEMĚNÍ: invoice_payments.bank_transaction_id má
-- ON DELETE SET NULL — platby spárované přes Wallet zůstávají evidované,
-- jen ztratí odkaz na smazanou bankovní transakci.

DELETE pm FROM payment_matches pm
  JOIN bank_transactions bt ON bt.id = pm.bank_transaction_id
 WHERE bt.source = 'wallet';

DELETE FROM bank_transactions WHERE source = 'wallet';
DELETE FROM bank_statements   WHERE source = 'wallet';
DELETE FROM external_bank_account_mappings WHERE provider = 'wallet';
DELETE FROM import_jobs WHERE source = 'wallet';
DELETE FROM cron_runs   WHERE script = 'cron-wallet-sync';

ALTER TABLE bank_statements
    MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
    MODIFY COLUMN source ENUM('statement','email_notice','idoklad') NOT NULL DEFAULT 'statement';

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import'
    ) NOT NULL;

ALTER TABLE supplier
    DROP COLUMN IF EXISTS wallet_api_token_enc,
    DROP COLUMN IF EXISTS wallet_last_synced_at;
