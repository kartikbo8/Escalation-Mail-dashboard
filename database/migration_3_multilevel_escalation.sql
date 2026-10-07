-- ============================================================
-- Migration 3: Multi-Level Escalation (Employee -> Manager/HOD -> Director/HR)
-- Run this in phpMyAdmin SQL tab AFTER migration_2_escalation_matrix.sql
-- ============================================================
USE escalation_tracker;

-- Each user can have a reporting manager (another user)
ALTER TABLE users
    ADD COLUMN reporting_manager_id INT DEFAULT NULL AFTER department_id,
    ADD CONSTRAINT fk_reporting_manager FOREIGN KEY (reporting_manager_id)
        REFERENCES users(id) ON DELETE SET NULL;

-- Each department has one Head of Department (HOD)
ALTER TABLE departments
    ADD COLUMN hod_user_id INT DEFAULT NULL AFTER mailbox_email,
    ADD CONSTRAINT fk_hod_user FOREIGN KEY (hod_user_id)
        REFERENCES users(id) ON DELETE SET NULL;

-- Track which escalation level a ticket is currently at, and when the
-- CURRENT level's 48-hour clock started (resets each time it moves up a level)
ALTER TABLE tickets
    ADD COLUMN escalation_level TINYINT NOT NULL DEFAULT 0 AFTER status,
    -- 0 = Level 1 (assigned employee), 1 = Level 2 (manager/HOD), 2 = Level 3 (director/HR, terminal)
    ADD COLUMN stage_started_at DATETIME DEFAULT NULL AFTER received_at;

-- Backfill stage_started_at for any existing rows so the cron doesn't choke
UPDATE tickets SET stage_started_at = received_at WHERE stage_started_at IS NULL;
