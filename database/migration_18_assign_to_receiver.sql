-- Internal mails used to be assigned to the SENDER employee, so the sender got
-- the reminders. Move still-open tickets to the person who received them.

-- 1) Receiver = owner of the mailbox the ticket was found in.
UPDATE tickets t
JOIN users sender_user ON sender_user.id = t.assigned_to
JOIN users receiver ON LOWER(receiver.email) = LOWER(t.source_mailbox)
SET t.assigned_to = receiver.id
WHERE t.status = 'open'
  AND LOWER(sender_user.email) = LOWER(t.sender_email)
  AND receiver.is_active = 1
  AND receiver.id <> t.assigned_to
  AND NOT EXISTS (SELECT 1 FROM group_mailboxes gm WHERE gm.owner_user_id = t.assigned_to
                  AND LOWER(COALESCE(t.to_recipients_json, '')) LIKE CONCAT('%"', LOWER(gm.email), '"%'));

-- 2) Older tickets without source_mailbox: first active, LIVE user in To.
UPDATE tickets t
JOIN users sender_user ON sender_user.id = t.assigned_to
JOIN users receiver ON receiver.id = (
        SELECT u.id FROM users u
        WHERE u.is_active = 1 AND u.monitor_mailbox = 1
          AND LOWER(u.email) <> LOWER(t.sender_email)
          AND LOWER(COALESCE(t.to_recipients_json, '')) LIKE CONCAT('%"', LOWER(u.email), '"%')
        ORDER BY u.id LIMIT 1)
SET t.assigned_to = receiver.id
WHERE t.status = 'open'
  AND t.source_mailbox IS NULL
  AND LOWER(sender_user.email) = LOWER(t.sender_email);
