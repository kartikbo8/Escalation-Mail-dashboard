-- ============================================================
-- Migration 5: Subject keyword -> Important + Escalation Queue
-- Run AFTER migration_4_department_cleanup.sql
--
-- Any configured HR keyword found in an incoming email SUBJECT makes
-- the ticket important and places it in the application's escalation queue.
-- The ticket remains OPEN so the normal SLA / Level 1-3 escalation flow
-- can continue to operate.
-- ============================================================
USE escalation_tracker;

ALTER TABLE tickets
    ADD COLUMN is_important TINYINT(1) NOT NULL DEFAULT 0 AFTER matched_keyword,
    ADD COLUMN escalation_queued TINYINT(1) NOT NULL DEFAULT 0 AFTER is_important;

CREATE INDEX idx_important_queue ON tickets (is_important, escalation_queued);
