-- WebAuthn/passkeys, účelové step-up proofs a bezpečnostní stav browser session.
-- Bezpečnostní DATETIME(6) hodnoty ukládá aplikační vrstva výhradně v UTC.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS webauthn_user_handle VARBINARY(64) NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_users_webauthn_user_handle (webauthn_user_handle);

CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  credential_id       VARBINARY(1024) NOT NULL,
  credential_id_hash  BINARY(32) NOT NULL,
  public_key          BLOB NOT NULL,
  sign_count          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  transports_json     LONGTEXT NULL,
  aaguid               BINARY(16) NULL,
  label                VARCHAR(100) NOT NULL,
  backup_eligible      TINYINT(1) NOT NULL DEFAULT 0,
  backup_state         TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME(6) NOT NULL,
  last_used_at         DATETIME(6) NULL,
  revoked_at           DATETIME(6) NULL,
  UNIQUE KEY uq_webauthn_credentials_id_hash (credential_id_hash),
  KEY idx_webauthn_credentials_user (user_id, revoked_at),
  CONSTRAINT fk_webauthn_credentials_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webauthn_ceremonies (
  flow_token_hash  BINARY(32) PRIMARY KEY,
  challenge        VARBINARY(64) NOT NULL,
  purpose          VARCHAR(32) NOT NULL,
  operation        VARCHAR(190) NULL,
  user_id          BIGINT UNSIGNED NULL,
  session_id_hash  BINARY(32) NULL,
  options_json     LONGTEXT NOT NULL,
  ip               VARBINARY(16) NOT NULL,
  user_agent       VARCHAR(255) NOT NULL,
  expires_at       DATETIME(6) NOT NULL,
  used_at          DATETIME(6) NULL,
  created_at       DATETIME(6) NOT NULL,
  KEY idx_webauthn_ceremonies_expiry (expires_at, used_at),
  KEY idx_webauthn_ceremonies_user_purpose (user_id, purpose, created_at),
  CONSTRAINT fk_webauthn_ceremonies_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_step_up_proofs (
  token_hash          BINARY(32) PRIMARY KEY,
  user_id             BIGINT UNSIGNED NOT NULL,
  session_id_hash     BINARY(32) NOT NULL,
  operation           VARCHAR(190) NOT NULL,
  auth_method         VARCHAR(32) NOT NULL,
  auth_credential_id  BIGINT UNSIGNED NULL,
  expires_at          DATETIME(6) NOT NULL,
  used_at             DATETIME(6) NULL,
  created_at          DATETIME(6) NOT NULL,
  KEY idx_mfa_step_up_proofs_expiry (expires_at, used_at),
  KEY idx_mfa_step_up_proofs_session (session_id_hash, operation, used_at),
  CONSTRAINT fk_mfa_step_up_proofs_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_mfa_step_up_proofs_credential
    FOREIGN KEY (auth_credential_id) REFERENCES webauthn_credentials(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS auth_method VARCHAR(32) NOT NULL DEFAULT 'legacy',
  ADD COLUMN IF NOT EXISTS assurance_level VARCHAR(16) NOT NULL DEFAULT 'legacy',
  ADD COLUMN IF NOT EXISTS mfa_verified_at DATETIME(6) NULL,
  ADD COLUMN IF NOT EXISTS auth_credential_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS last_user_activity_at DATETIME(6) NULL,
  ADD COLUMN IF NOT EXISTS locked_at DATETIME(6) NULL,
  ADD COLUMN IF NOT EXISTS lock_reason VARCHAR(16) NULL,
  ADD COLUMN IF NOT EXISTS last_unlock_at DATETIME(6) NULL,
  ADD COLUMN IF NOT EXISTS last_unlock_method VARCHAR(16) NULL,
  ADD COLUMN IF NOT EXISTS session_family_id VARBINARY(32) NULL,
  ADD COLUMN IF NOT EXISTS generation INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS replaced_at DATETIME(6) NULL,
  ADD COLUMN IF NOT EXISTS revoked_at DATETIME(6) NULL;

-- Staré session dostanou jeden celý idle timeout od deploye. UUID hash je zde
-- jen jednorázový kompatibilní backfill pro MariaDB 10.6; nové hodnoty vytváří
-- aplikační vrstva přes random_bytes().
UPDATE sessions
   SET last_user_activity_at = UTC_TIMESTAMP(6)
 WHERE last_user_activity_at IS NULL;

UPDATE sessions
   SET session_family_id = UNHEX(SHA2(CONCAT(UUID(), ':', id, ':', UUID()), 256))
 WHERE session_family_id IS NULL;

ALTER TABLE sessions
  MODIFY COLUMN IF EXISTS last_user_activity_at DATETIME(6) NOT NULL,
  MODIFY COLUMN IF EXISTS session_family_id VARBINARY(32) NOT NULL,
  ADD UNIQUE INDEX IF NOT EXISTS uq_sessions_family_generation (session_family_id, generation),
  ADD INDEX IF NOT EXISTS idx_sessions_family_revocation (session_family_id, revoked_at),
  ADD INDEX IF NOT EXISTS idx_sessions_user_revocation (user_id, revoked_at, expires_at);
