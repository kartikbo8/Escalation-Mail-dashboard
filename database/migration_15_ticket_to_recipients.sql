-- Store original To recipients so a reply from any listed recipient can be
-- classified against the ticket. CC recipients are intentionally not tracked.
USE escalation_tracker;

ALTER TABLE tickets
    ADD COLUMN to_recipients_json TEXT DEFAULT NULL AFTER sender_email;
