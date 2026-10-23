<?php
/**
 * cron/fetch_new_mails.php
 *
 * Polling approach (run every 5-10 min via Task Scheduler/crontab).
 * AFC doesn't use shared department mailboxes - every employee has their own
 * name@afcindia.in mailbox. This checks every active employee mailbox.
 *
 * New conversationId -> create a ticket (status=open), tagged by keyword match.
 * Reply from the mailbox owner on an OPEN ticket -> mark 'replied' -- BUT ONLY
 * if it's a genuine reply, not an auto-reply (out-of-office / security
 * auto-response). Client replies are AI-classified: confirmed resolution stops
 * reminders; unresolved replies reopen the reminder workflow.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';
require_once __DIR__ . '/../includes/keyword_helper.php';
require_once __DIR__ . '/../includes/ai_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$syncSince = null;
$syncUntil = null;
foreach (array_slice($argv, 1) as $argument) {
    if (strpos($argument, '--date=') === 0) {
        $dateValue = substr($argument, 7);
        $indiaTimezone = new DateTimeZone('Asia/Kolkata');
        $syncDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $indiaTimezone);
        if (!$syncDate || $syncDate->format('Y-m-d') !== $dateValue) {
            fwrite(STDERR, "Invalid date. Use --date=YYYY-MM-DD.\n");
            exit(2);
        }

        $utcTimezone = new DateTimeZone('UTC');
        $syncSince = $syncDate->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z');
        $syncUntil = $syncDate->modify('+1 day')->setTimezone($utcTimezone)->format('Y-m-d\TH:i:s\Z');
    }
}

$pdo = getDB();

function normalizeThreadSubject($subject) {
    $normalized = preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', trim((string)($subject ?? '')));
    $normalized = preg_replace('/\s+/', ' ', $normalized);
    return strtolower($normalized);
}

function findExistingTicketForIncomingMail($pdo, $senderEmail, $subject, $receivedAt, $conversationId, $messageId) {
    $ticket = null;

    if (!empty($messageId) || !empty($conversationId)) {
        $select = $pdo->prepare(
            "SELECT * FROM tickets
             WHERE graph_message_id = ?
                OR conversation_id = ?
             ORDER BY received_at DESC, id DESC
             LIMIT 1"
        );
        $select->execute([$messageId, $conversationId]);
        $ticket = $select->fetch();
    }

    if ($ticket) {
        return $ticket;
    }

    $normalizedSubject = normalizeThreadSubject($subject);
    if ($normalizedSubject !== '') {
        $select = $pdo->prepare(
            "SELECT * FROM tickets
             WHERE status = 'open'
               AND LOWER(sender_email) = LOWER(?)
               AND LOWER(subject) LIKE LOWER(?)
             ORDER BY received_at DESC, id DESC
             LIMIT 1"
        );
        $select->execute([$senderEmail, '%' . str_replace('%', '\%', $normalizedSubject) . '%']);
        $ticket = $select->fetch();
    }

    if ($ticket) {
        return $ticket;
    }

    $select = $pdo->prepare(
        "SELECT * FROM tickets
         WHERE status = 'open'
           AND LOWER(sender_email) = LOWER(?)
           AND LOWER(subject) = LOWER(?)
           AND received_at = ?
         ORDER BY id DESC
         LIMIT 1"
    );
    $select->execute([$senderEmail, strtolower($subject), $receivedAt]);
    return $select->fetch();
}

function ticketHasToRecipient($ticket, $email) {
    $email = strtolower(trim((string)$email));
    $recipients = json_decode((string)($ticket['to_recipients_json'] ?? ''), true);
    if ($email === '' || !is_array($recipients)) {
        return false;
    }

    foreach ($recipients as $recipient) {
        if (is_string($recipient) && strtolower(trim($recipient)) === $email) {
            return true;
        }
    }

    return false;
}

// Automatically fetch an app-only access token (requires Application
// permissions + admin consent in Azure - see README). Falls back to a
// manually-set env var if you're still testing with delegated permissions.
$accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);

if (!$accessToken) {
    echo "Could not get a Graph API access token. Check config/graph.php credentials and Azure admin consent.\n";
    exit;
}

/**
 * Process one mailbox's messages into tickets/replies.
 * $ownerUserId identifies the employee whose mailbox is being checked, so
 * new tickets are auto-assigned to that employee.
 */
function processMailbox($pdo, $accessToken, $mailbox, $departmentId, $ownerUserId, $since = null, $until = null) {
    $result = graphGetMessages($accessToken, $mailbox, 200, 5000, $since, $until);
    $messages = $result['value'] ?? [];
    $created = 0;
    $repliedCount = 0;

    foreach ($messages as $msg) {
        $conversationId = $msg['conversationId'];
        $senderEmail    = $msg['from']['emailAddress']['address'] ?? '';
        $toRecipients = [];
        foreach (($msg['toRecipients'] ?? []) as $toRecipient) {
            $email = trim((string)($toRecipient['emailAddress']['address'] ?? ''));
            if ($email !== '') {
                $toRecipients[strtolower($email)] = $email;
            }
        }
        $subject        = $msg['subject'] ?? '(no subject)';
        $bodyPreview    = $msg['bodyPreview'] ?? '';
        $receivedAt     = date('Y-m-d H:i:s', strtotime($msg['receivedDateTime']));

        $isOurReply = (strtolower($senderEmail) === strtolower($mailbox));
        $isAuto     = isAutoReply($subject); // headers can be added later via $select=internetMessageHeaders

        // Prevent the same incoming mail from being created multiple times.
        // Some Outlook/Graph syncs can surface the same email again with a slight
        // variation in conversationId or when the cron job runs again within the
        // same window. We check both explicit IDs and a normalized email signature.
        $ticket = findExistingTicketForIncomingMail(
            $pdo,
            $senderEmail,
            $subject,
            $receivedAt,
            $conversationId,
            $msg['id'] ?? ''
        );
        $isTrackedToReply = $ticket
            && ticketHasToRecipient($ticket, $senderEmail)
            && $receivedAt > $ticket['received_at']
            && ($ticket['replied_at'] === null || $receivedAt > $ticket['replied_at']);

        if (!$ticket && !$isOurReply) {
            if (isExcludedSubject($subject)) {
                continue;
            }

            $fullMessage = !empty($msg['id'])
                ? graphGetMessageBody($accessToken, $mailbox, $msg['id'])
                : null;
            $bodyContent = $bodyPreview;
            $bodyFetchNote = '';
            if (isset($fullMessage['body']['content'])) {
                $bodyContent = normalizeMailBodyText(
                    $fullMessage['body']['content'],
                    $fullMessage['body']['contentType'] ?? ''
                );
            } else {
                $bodyFetchError = $fullMessage['error']['message'] ?? 'message body was empty or unavailable';
                $messageId = $msg['id'] ?? '(missing id)';
                error_log("Could not load full email body for {$mailbox} message {$messageId}: {$bodyFetchError}; keyword scan is limited to body preview.");
                $bodyFetchNote = ' (full message body unavailable; keyword scan used body preview)';
            }

            // New incoming email -> configured keywords are checked in subject and full body before AI.
                        $senderUserStmt = $pdo->prepare(
                                "SELECT id FROM users
                                 WHERE is_active = 1 AND department_id IS NOT NULL
                                     AND LOWER(email) = LOWER(?)
                                 LIMIT 1"
                        );
                        $senderUserStmt->execute([$senderEmail]);
                        $senderUserId = $senderUserStmt->fetchColumn();
                        $assignedUserId = $senderUserId ? (int)$senderUserId : $ownerUserId;
            $keywordMatch = isExcludedSubject($subject)
                ? ['category' => 'normal', 'keyword' => null]
                : matchTicketKeywords($subject, $bodyContent);

            if (isIgnoredSender($senderEmail)) {
                $keywordMatch = ['category' => 'normal', 'keyword' => null];
            }

            // AI only filters unmatched subjects; it must not hide a configured trigger.
            if ($keywordMatch['category'] === 'normal') {
                $aiClassification = classifyMailWithAI($subject, $bodyPreview);
                if ($aiClassification && !$aiClassification['actionable'] && $aiClassification['confidence'] >= 0.80) {
                    $keywordMatch = ['category' => 'normal', 'keyword' => null];
                }
            }
            $isImportant = ($keywordMatch['category'] !== 'normal') ? 1 : 0;
            $slaDue = calculateSlaDueDate(
                $receivedAt,
                $departmentId,
                $senderEmail,
                $keywordMatch['keyword']
            );
            $toRecipientsJson = json_encode(array_values($toRecipients));

            $insert = $pdo->prepare(
                "INSERT INTO tickets (department_id, subject, sender_email, to_recipients_json, conversation_id,
                 graph_message_id, source_mailbox, status, priority_tag, matched_keyword, is_important, escalation_queued,
                 assigned_to, received_at, stage_started_at, sla_due_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $departmentId, $subject, $senderEmail, $toRecipientsJson, $conversationId,
                $msg['id'], $mailbox, $keywordMatch['category'], $keywordMatch['keyword'], $isImportant, $isImportant,
                $assignedUserId, $receivedAt, $receivedAt, $slaDue,
            ]);
            $newTicketId = $pdo->lastInsertId();
            $assignNote = $senderUserId
                ? ' - auto-assigned to sender employee'
                : ($ownerUserId ? ' - auto-assigned to mailbox owner' : '');
            logTimelineEvent($newTicketId, 'received', 'Email received from ' . $senderEmail . $assignNote .
                ($keywordMatch['keyword']
                    ? " (matched keyword: {$keywordMatch['keyword']} - IMPORTANT, queued for escalation)"
                    : " (no keyword matched - will not escalate beyond employee)") . $bodyFetchNote);
            $created++;

        } elseif (
            $ticket
            && $isOurReply
            && !$isTrackedToReply
            && (
                in_array($ticket['status'], ['open', 'escalated'], true)
                || ($ticket['status'] === 'closed' && (int)$ticket['escalation_level'] > 0)
            )
            && ($ticket['replied_at'] === null || $receivedAt > $ticket['replied_at'])
        ) {
            if ($isAuto) {
                logTimelineEvent($ticket['id'], 'no_reply_warning',
                    'Auto-reply detected (out-of-office/security) - NOT counted as a real reply');
            } else {
                $pdo->prepare("UPDATE tickets SET status='replied', replied_at=?, closed_at=NULL WHERE id=?")
                    ->execute([$receivedAt, $ticket['id']]);
                logTimelineEvent($ticket['id'], 'replied', 'Reply sent by mailbox owner');
                $repliedCount++;
            }
        } elseif (
            $ticket
            && (
                $isTrackedToReply
                || (!$isOurReply
                    && strcasecmp(trim($senderEmail), trim($ticket['sender_email'])) === 0)
            )
            && $receivedAt > $ticket['received_at']
            && ($ticket['replied_at'] === null || $receivedAt > $ticket['replied_at'])
        ) {
            if ($isAuto) {
                logTimelineEvent($ticket['id'], 'no_reply_warning',
                    'Client auto-reply detected - not used to stop or restart reminders');
                continue;
            }

            $clientReplyBody = $bodyPreview;
            $messageId = $msg['id'] ?? null;
            $fullMessage = $messageId
                ? graphGetMessageBody($accessToken, $mailbox, $messageId)
                : null;
            if (isset($fullMessage['body']['content'])) {
                $clientReplyBody = normalizeMailBodyText(
                    $fullMessage['body']['content'],
                    $fullMessage['body']['contentType'] ?? ''
                );
                $clientReplyBody = substr($clientReplyBody, 0, 10000);
            } else {
                error_log(
                    "Could not load full client reply body for ticket {$ticket['id']}; using body preview."
                );
            }

            $clientReply = classifyClientReplyWithAI($subject, $clientReplyBody);
            $responseSenderType = strcasecmp(trim($senderEmail), trim($ticket['sender_email'])) === 0
                ? 'client'
                : 'original To recipient';
            if ($clientReply && $clientReply['satisfied'] && $clientReply['confidence'] >= 0.80) {
                $pdo->prepare(
                    "UPDATE tickets SET status='replied', replied_at=?, closed_at=NULL WHERE id=?"
                )->execute([$receivedAt, $ticket['id']]);
                logTimelineEvent(
                    $ticket['id'],
                    'replied',
                    ucfirst($responseSenderType) . ' reply classified as resolved by AI; reminders stopped'
                );
                $repliedCount++;
                continue;
            }

            $keywordMatch = matchTicketKeywords($subject, $clientReplyBody);
            $isImportant = !empty($ticket['is_important']) || $keywordMatch['category'] !== 'normal';
            $isQueued = !empty($ticket['escalation_queued']) || $keywordMatch['category'] !== 'normal';
            $priorityTag = $keywordMatch['category'] !== 'normal' ? $keywordMatch['category'] : $ticket['priority_tag'];
            $matchedKeyword = $keywordMatch['keyword'] ?: $ticket['matched_keyword'];
            $wasAlreadyActive = $ticket['status'] === 'open';

            if ($wasAlreadyActive) {
                $pdo->prepare(
                    "UPDATE tickets
                     SET replied_at=?, is_important=?, escalation_queued=?, priority_tag=?, matched_keyword=?
                     WHERE id=?"
                )->execute([
                    $receivedAt,
                    $isImportant ? 1 : 0,
                    $isQueued ? 1 : 0,
                    $priorityTag,
                    $matchedKeyword,
                    $ticket['id'],
                ]);
            } else {
                $slaDue = calculateSlaDueDate(
                    $receivedAt,
                    $ticket['department_id'],
                    $ticket['sender_email'],
                    $matchedKeyword
                );
                $pdo->prepare(
                    "UPDATE tickets
                     SET status='open', escalation_level=0, stage_started_at=?, sla_due_at=?,
                         reminder_sent_at=NULL, snoozed_until=NULL, replied_at=?, closed_at=NULL,
                         is_important=?, escalation_queued=?, priority_tag=?, matched_keyword=?
                     WHERE id=?"
                )->execute([
                    $receivedAt,
                    $slaDue,
                    $receivedAt,
                    $isImportant ? 1 : 0,
                    $isQueued ? 1 : 0,
                    $priorityTag,
                    $matchedKeyword,
                    $ticket['id'],
                ]);
            }

            $classificationNote = !$clientReply
                ? 'AI reply classification unavailable'
                : ($clientReply['confidence'] < 0.80
                    ? 'AI reply classification uncertain'
                    : 'AI classified client reply as unresolved');
            logTimelineEvent(
                $ticket['id'],
                $wasAlreadyActive ? 'no_reply_warning' : 'reopened',
                $wasAlreadyActive
                    ? "{$classificationNote} ({$responseSenderType}); existing reminder schedule remains active"
                    : "{$classificationNote} ({$responseSenderType}); reminder workflow restarted"
            );
        }
    }

    return [$created, $repliedCount];
}

$totalCreated = 0;
$totalReplied = 0;

// Only mailboxes explicitly enabled from Users > Monitor Mailbox are scanned.
// This prevents tickets and notifications being created for every active user.
$monitoredUsers = $pdo->query(
    "SELECT id, email, department_id
     FROM users
     WHERE is_active = 1
       AND department_id IS NOT NULL
             AND monitor_mailbox = 1"
)->fetchAll();

foreach ($monitoredUsers as $u) {
    [$c, $r] = processMailbox($pdo, $accessToken, $u['email'], $u['department_id'], $u['id'], $syncSince, $syncUntil);
    $totalCreated += $c;
    $totalReplied += $r;
}

echo "Mail sync complete. {$totalCreated} new ticket(s), {$totalReplied} marked replied.\n";
