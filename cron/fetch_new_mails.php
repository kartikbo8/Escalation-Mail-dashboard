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
 * auto-response). Auto-replies are logged but do NOT change ticket status.
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

        if (!$ticket && !$isOurReply) {
            if (isExcludedSubject($subject)) {
                continue;
            }

            // New incoming email -> configured subject keywords take priority over AI.
            // Current policy: employee reminder at 1 hour, manager escalation at 2 hours.
            $slaDue = calculateSlaDueDate($receivedAt, $departmentId, $senderEmail);
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
                : matchTicketKeywords($subject);

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

            $insert = $pdo->prepare(
                "INSERT INTO tickets (department_id, subject, sender_email, conversation_id,
                 graph_message_id, source_mailbox, status, priority_tag, matched_keyword, is_important, escalation_queued,
                 assigned_to, received_at, stage_started_at, sla_due_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $departmentId, $subject, $senderEmail, $conversationId,
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
                    : " (no keyword matched - will not escalate beyond employee)"));
            $created++;

        } elseif (
            $ticket
            && $isOurReply
            && (
                in_array($ticket['status'], ['open', 'escalated'], true)
                || ($ticket['status'] === 'closed' && (int)$ticket['escalation_level'] > 0)
            )
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
