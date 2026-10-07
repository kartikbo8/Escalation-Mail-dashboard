-- ============================================================
-- Migration 7: Self-Service First Login Password Setup
-- Run after migration_6_notification_controls.sql
--
-- password_set = 0 means the account has a system-generated placeholder
-- password (from Microsoft 365 sync, or newly added by an admin) and the
-- person has NOT yet chosen their own password. On their first login
-- attempt (by email only), login.php lets them set their own password
-- instead of checking against the placeholder.
-- password_set = 1 means they've already chosen a real password (or it
-- was explicitly reset by a Super Admin), so normal password login applies.
-- ============================================================
USE escalation_tracker;

ALTER TABLE users
    ADD COLUMN password_set TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;

-- The original seed admin account already has a real, known password
UPDATE users SET password_set = 1 WHERE email = 'admin@example.com';
