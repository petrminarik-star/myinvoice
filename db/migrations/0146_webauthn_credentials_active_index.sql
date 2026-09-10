-- Aktivní passkeys se vypisují podle uživatele a data vytvoření.
-- Nejdřív přidáme pokrývající index, teprve potom odstraníme jeho kratší
-- redundantní variantu, aby opakované spuštění zůstalo bezpečné.

ALTER TABLE webauthn_credentials
  ADD INDEX IF NOT EXISTS idx_webauthn_credentials_user_active_created
    (user_id, revoked_at, created_at);

ALTER TABLE webauthn_credentials
  DROP INDEX IF EXISTS idx_webauthn_credentials_user;
