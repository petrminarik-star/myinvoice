-- Wallet (BudgetBakers) integrace — import bankovních pohybů přes REST API
-- (rest.budgetbakers.com) jako virtuální výpis source='wallet', stejný vzor
-- jako iDoklad (0136). Idempotence přes (source, source_ref) drží aplikační
-- vrstva (viz zdůvodnění v 0136 — záměrně bez unique klíče).

ALTER TABLE bank_statements
    MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad','wallet') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
    MODIFY COLUMN source ENUM('statement','email_notice','idoklad','wallet') NOT NULL DEFAULT 'statement';

ALTER TABLE import_jobs
    MODIFY COLUMN source ENUM(
        'idoklad', 'fakturoid', 'pdf_isdoc_inbox', 'pdf_ai', 'monthly_export',
        'document_zip_import', 'document_zip_export', 'document_folder_import',
        'wallet'
    ) NOT NULL;

-- Wallet osobní Bearer token (web.budgetbakers.com/settings/rest-api), šifrovaný
-- přes SecretEncryption (AES-256-GCM). JWT bývá dlouhý → 2048.
-- wallet_last_synced_at = kurzor inkrementálního importu pohybů.
ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS wallet_api_token_enc VARBINARY(2048) NULL,
    ADD COLUMN IF NOT EXISTS wallet_last_synced_at TIMESTAMP NULL DEFAULT NULL;
