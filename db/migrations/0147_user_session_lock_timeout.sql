-- Volitelný osobní timeout automatického zámku. NULL zachovává politiku
-- správce; kladná hodnota může být pouze stejně přísná nebo přísnější.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS session_lock_after_minutes SMALLINT UNSIGNED NULL,
  ADD CONSTRAINT IF NOT EXISTS chk_users_session_lock_after_minutes
    CHECK (
      session_lock_after_minutes IS NULL
      OR session_lock_after_minutes BETWEEN 1 AND 1440
    );
