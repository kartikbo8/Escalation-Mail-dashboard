-- Apply after migration_7_self_password_setup.sql on existing installations.
-- Fresh installations already contain these columns in schema.sql.
ALTER TABLE users
    ADD COLUMN activation_token_hash CHAR(64) DEFAULT NULL AFTER password_set,
    ADD COLUMN activation_expires_at DATETIME DEFAULT NULL AFTER activation_token_hash,
    ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 0 AFTER activation_expires_at;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_activation_token_hash (activation_token_hash);

-- Revoke the legacy seeded admin password that migration 7 explicitly trusted.
-- Run reset_admin.php from a trusted CLI to issue a one-time replacement token.
UPDATE users
SET password_set = 0,
    activation_token_hash = NULL,
    activation_expires_at = NULL,
    auth_version = auth_version + 1
WHERE email = 'admin@example.com'
  AND role IN ('admin', 'super_admin');
