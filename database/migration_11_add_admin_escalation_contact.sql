-- Migration 11: Allow Admin recipients for final escalation testing
USE escalation_tracker;

ALTER TABLE escalation_contacts
    MODIFY role_type ENUM('founder', 'hr', 'admin') NOT NULL;