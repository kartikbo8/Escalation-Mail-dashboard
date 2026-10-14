-- Allow the ticket audit trail to record bulk reopen actions.
USE escalation_tracker;

ALTER TABLE timeline_events
    MODIFY event_type ENUM('received', 'replied', 'no_reply_warning', 'escalated', 'closed', 'reopened') NOT NULL;
