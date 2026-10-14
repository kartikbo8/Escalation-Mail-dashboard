-- ============================================================
-- Migration 8: Per-Employee Mailbox Monitoring
-- Run after migration_7_self_password_setup.sql
--
-- AFC doesn't use shared department mailboxes - every employee has their
-- own name@afcindia.in mailbox that customers email directly. Instead of
-- one shared mailbox per department, we now monitor INDIVIDUAL employee
-- mailboxes. Tickets created from a monitored employee's mailbox are
-- auto-assigned to that employee (no manual "Assign" step needed).
-- ============================================================
USE escalation_tracker;

ALTER TABLE users
    ADD COLUMN monitor_mailbox TINYINT(1) NOT NULL DEFAULT 0 AFTER reporting_manager_id;
