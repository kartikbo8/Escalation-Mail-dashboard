USE escalation_tracker;

ALTER TABLE tickets
    ADD COLUMN source_mailbox VARCHAR(255) DEFAULT NULL AFTER graph_message_id;
