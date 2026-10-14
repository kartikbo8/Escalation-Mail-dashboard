-- ============================================================
-- Migration 6: Per-ticket notification controls
-- Run after migration_5_subject_keyword_priority.sql
-- ============================================================
USE escalation_tracker;

ALTER TABLE tickets
    ADD COLUMN stop_employee_notifications TINYINT(1) NOT NULL DEFAULT 0 AFTER reminder_sent_at,
    ADD COLUMN stop_manager_notifications TINYINT(1) NOT NULL DEFAULT 0 AFTER stop_employee_notifications;