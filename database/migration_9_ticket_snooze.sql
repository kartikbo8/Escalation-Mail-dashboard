-- Migration 9: Ticket snooze
-- Pauses reminders and escalation for a selected period.
USE escalation_tracker;

ALTER TABLE tickets
    ADD COLUMN snoozed_until DATETIME DEFAULT NULL AFTER reminder_sent_at;