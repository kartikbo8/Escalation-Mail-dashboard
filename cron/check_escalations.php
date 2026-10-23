<?php
/**
 * cron/check_escalations.php
 *
 * Run this every 15-30 minutes (Task Scheduler / crontab).
 *
 * THREE-LEVEL ESCALATION FLOW (per HR's Email Response & Escalation Matrix):
 *
 *   Level 1 (escalation_level = 0): Assigned employee has the configured
 *            response window. EOD + acknowledgement mails get a reminder
 *            after 2 working hours and a 4-hour response window.
 *   Level 2 (escalation_level = 1): If still no reply after the employee
 *            window, the
 *            employee's Reporting Manager + Department HOD are notified.
 *            They get their OWN fresh 1-hour window.
 *   Level 3 (escalation_level = 2, terminal): If STILL no reply after 1 hour,
 *            it escalates to HR + Admin via escalation_contacts,
 *            cc'ing everyone involved so far (employee, manager, HOD).
 *
 * Holidays and non-working days are automatically excluded from every
 * 48/24-hour window (see calculateDueDateByHours in functions.php), so a
 * multi-day festival does not eat into anyone's response time.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';
require_once __DIR__ . '/../includes/keyword_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$remindersOnly = in_array('--reminders-only', $argv, true);
$reminderDate = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--reminder-date=') === 0) {
        $dateValue = substr($argument, strlen('--reminder-date='));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
        if (!$date || $date->format('Y-m-d') !== $dateValue || $dateValue !== date('Y-m-d')) {
            fwrite(STDERR, "Reminder date must be today in YYYY-MM-DD format.\n");
            exit(2);
        }
        $reminderDate = $dateValue;
    }
}
if ($remindersOnly && $reminderDate === null) {
    fwrite(STDERR, "--reminders-only requires --reminder-date=YYYY-MM-DD.\n");
    exit(2);
}

$pdo = getDB();
const ESCALATION_SENDER_MAILBOX = 'notifications@afcindia.in';

// Automatically fetch an app-only access token (requires Application
// permissions + admin consent in Azure - see README). Falls back to a
// manually-set env var if you're still testing with delegated permissions.
$accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);

if (!$accessToken) {
    echo "No Graph access token available. No reminders were sent.\n";
    exit(1);
}

$senderProfile = graphApiGet(
    '/users/' . rawurlencode(ESCALATION_SENDER_MAILBOX) . '?$select=id,mail,userPrincipalName',
    $accessToken
);
if (isset($senderProfile['error']) || empty($senderProfile['id'])) {
    $message = $senderProfile['error']['message'] ?? 'Mailbox was not found or is not accessible.';
    echo "Sender mailbox " . ESCALATION_SENDER_MAILBOX . " is unavailable: {$message}\n";
    echo "No reminders were sent and no tickets were advanced.\n";
    exit(1);
}

// Keep closed tickets and their audit logs for 10 days, then remove them.
// The related timeline/escalation rows are deleted by the database cascade.
if (!$remindersOnly) {
    $pdo->exec(
        "DELETE FROM tickets
         WHERE status = 'closed'
             AND closed_at IS NOT NULL
             AND closed_at <= DATE_SUB(NOW(), INTERVAL 10 DAY)"
    );
}

function sendOrLog($accessToken, $toEmail, $subject, $body, $senderMailbox, $attachments = []) {
    if (!$toEmail || !$senderMailbox) return false;
    if ($accessToken) {
        return graphSendMail($accessToken, $toEmail, $subject, $body, $senderMailbox, $attachments);
    }
    error_log("[DRY RUN] Would send to {$toEmail}: {$subject}");
    return false;
}

function getNorthDesignEscalationRecipients($pdo) {
    $candidateNames = ['mustafa', 'bhupendra'];
    $emailMatches = [];
    foreach ($candidateNames as $name) {
        $emailMatches[] = strtolower($name . '@afcindia.in');
    }

    $whereClauses = [];
    $params = [];
    foreach ($candidateNames as $name) {
        $whereClauses[] = '(LOWER(name) = ? OR LOWER(email) = ?)';
        $params[] = strtolower($name);
        $params[] = strtolower($name . '@afcindia.in');
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE ' . implode(' OR ', $whereClauses) . ' LIMIT 10');
    $stmt->execute($params);
    $results = $stmt->fetchAll();

    if (!empty($results)) {
        return array_values($results);
    }

    return [
        ['name' => 'Mustafa', 'email' => 'mustafa@afcindia.in'],
        ['name' => 'Bhupendra', 'email' => 'bhupendra@afcindia.in'],
    ];
}

function isNorthDesignTicket($ticket) {
    if (strtolower(trim((string)($ticket['sender_email'] ?? ''))) === 'northdesign@afcindia.in') {
        return true;
    }

    $recipients = json_decode((string)($ticket['to_recipients_json'] ?? ''), true);
    if (!is_array($recipients)) {
        return false;
    }

    foreach ($recipients as $recipient) {
        if (is_string($recipient) && strtolower(trim($recipient)) === 'northdesign@afcindia.in') {
            return true;
        }
    }

    return false;
}

function getNorthDesignReminderRecipients($pdo, $ticket, $assignee) {
    if ($assignee && !empty($assignee['reporting_manager_id'])) {
        $manager = getUser($pdo, $assignee['reporting_manager_id']);
        if (
            $manager
            && !empty($manager['is_active'])
            && !empty($manager['email'])
            && (int)$manager['department_id'] === (int)$ticket['department_id']
        ) {
            return [$manager];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT u.*
         FROM departments d
         JOIN users u ON u.id = d.hod_user_id
         WHERE d.id = ? AND u.is_active = 1"
    );
    $stmt->execute([(int)$ticket['department_id']]);
    $hod = $stmt->fetch();
    if ($hod && !empty($hod['email'])) {
        return [$hod];
    }

    $contactStmt = $pdo->prepare(
        "SELECT *
         FROM escalation_contacts
         WHERE role_type IN ('hr', 'admin')
           AND (department_id = ? OR department_id IS NULL)
         ORDER BY CASE role_type WHEN 'hr' THEN 1 ELSE 2 END, name"
    );
    $contactStmt->execute([(int)$ticket['department_id']]);
    return $contactStmt->fetchAll();
}

function getEscalationRecipientsForTicket($pdo, $ticket, $assignee, $manager1, $manager2, $hod) {
    $senderEmail = strtolower(trim((string)($ticket['sender_email'] ?? '')));
    if ($senderEmail === 'northdesign@afcindia.in') {
        return getNorthDesignEscalationRecipients($pdo);
    }

    $recipients = [];
    foreach ([$manager1, $manager2, $hod] as $recipient) {
        if ($recipient && !empty($recipient['email'])) {
            $recipients[] = $recipient;
        }
    }

    return $recipients;
}

function getUser($pdo, $userId) {
    if (!$userId) return null;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function originalMailContext($accessToken, $ticket, $assignee) {
    $message = $accessToken && $assignee
        ? graphGetMessage($accessToken, $assignee['email'], $ticket['graph_message_id'])
        : null;
    if (!$message) return ['html' => '', 'attachments' => []];

    $body = $message['body']['content'] ?? '';
    if ($message['body']['contentType'] === 'html') {
        $body = strip_tags($body, '<p><br><div><ul><ol><li><b><strong><i><em><a>');
    } else {
        $body = nl2br(htmlspecialchars($body));
    }

    $attachments = [];
    foreach ($message['attachments'] ?? [] as $attachment) {
        if (!empty($attachment['isInline']) || empty($attachment['contentBytes']) || empty($attachment['name'])) {
            continue;
        }
        $attachments[] = [
            '@odata.type' => '#microsoft.graph.fileAttachment',
            'name' => $attachment['name'],
            'contentType' => $attachment['contentType'] ?? 'application/octet-stream',
            'contentBytes' => $attachment['contentBytes'],
        ];
    }

    return ['html' => "<hr><p><strong>Original Mail</strong><br>"
        . "<strong>Sent by:</strong> " . htmlspecialchars($ticket['sender_email']) . "</p>"
        . "<div style=\"padding:10px;border-left:3px solid #ccc\">{$body}</div>",
        'attachments' => $attachments];
}

// ------------------------------------------------------------
// STAGE A: Reminders (halfway through the CURRENT level's window)
// Group by conversation_id to send ONE reminder per conversation, not per ticket
// ------------------------------------------------------------
$reminderStmt = $pdo->query(
        "SELECT t.*, d.name as department_name, d.hod_user_id,
            d.mailbox_email AS notification_mailbox FROM tickets t
     JOIN departments d ON d.id = t.department_id
     JOIN users mailbox_owner ON mailbox_owner.id = t.assigned_to
         WHERE mailbox_owner.is_active = 1
           AND (
               mailbox_owner.monitor_mailbox = 1
               OR LOWER(COALESCE(t.to_recipients_json, '')) LIKE '%northdesign@afcindia.in%'
               OR LOWER(t.sender_email) = 'northdesign@afcindia.in'
           )
                     AND t.status = 'open' AND t.escalation_level = 0
                     AND t.sla_due_at IS NOT NULL AND t.sla_due_at > NOW()
                     AND t.reminder_sent_at IS NULL
                     AND t.is_important = 1 AND t.escalation_queued = 1
                     AND LOWER(t.sender_email) NOT IN (
                           'hrms@afcindia.in', 'noreply.afcone@afcindia.in',
                           'afcdesign@afcindia.in', 'legal@afcindia.in', 'sales09@afcindia.in',
                           'studio.superadmin@afcindia.in', 'studio.admin1@afcindia.in',
                           'studio.superuser1@afcindia.in', 'studio.user2@afcindia.in',
                           'studio.user1@afcindia.in'
                       )
                         AND (t.snoozed_until IS NULL OR t.snoozed_until <= NOW())
         AND t.id = (SELECT MIN(id) FROM tickets WHERE conversation_id = t.conversation_id)"
);
$reminderCount = 0;
$sentReminderCount = 0;
$reminderGroups = [];
$escalationGroups = [];  // NEW: Group escalation emails by recipient

foreach ($reminderStmt->fetchAll() as $ticket) {
    if ($ticket['status'] !== 'open') continue;
    if (isIgnoredSender($ticket['sender_email']) || isExcludedSubject($ticket['subject'])) continue;

    $stageStart = $ticket['stage_started_at'] ?: $ticket['received_at'];
    $reminderDue = calculateReminderDueDate(
        $stageStart,
        $ticket['department_id'],
        $ticket['sender_email'],
        $ticket['matched_keyword']
    );

    if (($reminderDate !== null && substr($reminderDue, 0, 10) !== $reminderDate)
        || $reminderDue > date('Y-m-d H:i:s')) {
        continue;
    }

    $assignee = getUser($pdo, $ticket['assigned_to']);
    $hod = getUser($pdo, $ticket['hod_user_id']);
    $level = (int)$ticket['escalation_level'];
    $recipients = [];
    $notificationMailbox = ESCALATION_SENDER_MAILBOX;
    $originalMail = ['html' => '', 'attachments' => []];

    if ($level === 0) {
        if (isNorthDesignTicket($ticket)) {
            if (empty($ticket['stop_manager_notifications'])) {
                foreach (getNorthDesignReminderRecipients($pdo, $ticket, $assignee) as $reminderRecipient) {
                    if (empty($reminderRecipient['email'])) {
                        continue;
                    }
                    $recipients[$reminderRecipient['email']] = $reminderRecipient;
                }
            }
        } elseif ($assignee && empty($ticket['stop_employee_notifications'])) {
            $recipients[$assignee['email']] = $assignee;
        }

        $recipients = array_values($recipients);
    }

    foreach ($recipients as $recipient) {
            // One digest per recipient per run, regardless of ticket level.
        $groupKey = strtolower($recipient['email']);
        if (!isset($reminderGroups[$groupKey])) {
            $reminderGroups[$groupKey] = [
                'recipient' => $recipient,
                'notification_mailbox' => $notificationMailbox,
                'items' => [],
                'attachments' => [],
            ];
        }
        $reminderGroups[$groupKey]['items'][] = [
            'ticket' => $ticket,
            'assignee' => $assignee,
            'original_html' => $originalMail['html'],
        ];
        $reminderGroups[$groupKey]['attachments'] = array_merge(
            $reminderGroups[$groupKey]['attachments'],
            $originalMail['attachments']
        );
    }

    if (!$recipients) {
        if ($level === 0 && isNorthDesignTicket($ticket)) {
            $skipReason = empty($ticket['stop_manager_notifications'])
                ? 'Level 1 reminder skipped: no active department manager, HOD, or HR/Admin contact is configured'
                : 'Level 1 reminder skipped: manager/HOD notifications are paused';
        } else {
            $skipReason = $level === 0
                ? 'Level 1 reminder skipped: no employee recipient'
                : 'Level 2 reminder skipped: no Manager/HOD recipient';
        }
        logTimelineEvent($ticket['id'], 'no_reply_warning', $skipReason);
    }

    $reminderCount++;
}

foreach ($reminderGroups as $group) {
    $openItems = [];
    foreach ($group['items'] as $item) {
        $statusStmt = $pdo->prepare("SELECT status FROM tickets WHERE id = ?");
        $statusStmt->execute([$item['ticket']['id']]);
        if ($statusStmt->fetchColumn() === 'open') {
            $openItems[] = $item;
        }
    }
    if (!$openItems) continue;
    $group['items'] = $openItems;

    $subject = '[REMINDER] ' . count($group['items']) . ' pending email task(s)';
    $body = '<p>These pending email tasks require your attention:</p>';
    foreach ($group['items'] as $item) {
        $ticket = $item['ticket'];
        $levelLabel = (int)$ticket['escalation_level'] === 0
            ? 'Assigned employee response pending'
            : 'Escalated response pending';
        $body .= '<hr><p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject']) . '<br>'
            . '<strong>From:</strong> ' . htmlspecialchars($ticket['sender_email']) . '<br>'
            . '<strong>Assigned Employee:</strong> ' . htmlspecialchars($item['assignee']['name'] ?? 'Unassigned') . '<br>'
            . '<strong>Department:</strong> ' . htmlspecialchars($ticket['department_name']) . '<br>'
            . '<strong>Task:</strong> ' . $levelLabel . '<br>'
            . '<strong>Deadline:</strong> ' . htmlspecialchars($ticket['sla_due_at']) . '</p>'
            . ($item['original_html'] ?: '');
    }
    $body .= '<p>Please review these pending tasks and respond before their deadlines.</p>';
    $sent = sendOrLog(
        $accessToken,
        $group['recipient']['email'],
        $subject,
        $body,
        $group['notification_mailbox'],
        $group['attachments']
    );
    if (!$sent) {
        echo "Reminder delivery failed for {$group['recipient']['email']}; tickets remain eligible for retry.\n";
        continue;
    }
    foreach ($group['items'] as $item) {
        $updated = $pdo->prepare(
            "UPDATE tickets
             SET reminder_sent_at = NOW()
             WHERE id = ? AND status = 'open' AND reminder_sent_at IS NULL"
        );
        $updated->execute([$item['ticket']['id']]);
        if ($updated->rowCount() !== 1) continue;

        $sentReminderCount++;
        logTimelineEvent(
            $item['ticket']['id'],
            'no_reply_warning',
            "Reminder included in consolidated digest for {$group['recipient']['name']}"
        );
    }
}

if ($remindersOnly) {
    echo "{$sentReminderCount} reminder(s) sent; escalation processing skipped.\n";
    exit(0);
}

// ------------------------------------------------------------
// STAGE B: Level-up when the CURRENT level's deadline has passed
// Group by conversation_id to escalate ONE ticket per conversation
// ------------------------------------------------------------
$dueStmt = $pdo->query(
        "SELECT t.*, d.name as department_name, d.hod_user_id,
            d.mailbox_email AS notification_mailbox FROM tickets t
     JOIN departments d ON d.id = t.department_id
     JOIN users mailbox_owner ON mailbox_owner.id = t.assigned_to
         WHERE mailbox_owner.is_active = 1
           AND (
               mailbox_owner.monitor_mailbox = 1
               OR LOWER(COALESCE(t.to_recipients_json, '')) LIKE '%northdesign@afcindia.in%'
               OR LOWER(t.sender_email) = 'northdesign@afcindia.in'
           )
           AND t.status = 'open' AND t.sla_due_at IS NOT NULL AND t.sla_due_at <= NOW()
             AND LOWER(t.sender_email) NOT IN (
                 'hrms@afcindia.in', 'noreply.afcone@afcindia.in',
                 'afcdesign@afcindia.in', 'legal@afcindia.in', 'sales09@afcindia.in',
                 'studio.superadmin@afcindia.in', 'studio.admin1@afcindia.in',
                 'studio.superuser1@afcindia.in', 'studio.user2@afcindia.in',
                 'studio.user1@afcindia.in'
             )
             AND (t.snoozed_until IS NULL OR t.snoozed_until <= NOW())
         AND t.id = (SELECT MIN(id) FROM tickets WHERE conversation_id = t.conversation_id)"
);
$escalatedCount = 0;

foreach ($dueStmt->fetchAll() as $ticket) {
    if ($ticket['status'] !== 'open') continue;
    if (isIgnoredSender($ticket['sender_email']) || isExcludedSubject($ticket['subject'])) continue;

    $level = (int)$ticket['escalation_level'];

    // Per HR's matrix: only emails flagged "important" (subject/body matched one of
    // the configured keywords, and is queued for escalation) go through
    // Level 2/3 escalation. A non-important email that breaches SLA stays
    // with the employee - it is NOT escalated to Manager/HOD/Director/HR.
    if (empty($ticket['is_important']) || empty($ticket['escalation_queued'])) {
        logTimelineEvent($ticket['id'], 'no_reply_warning',
            'SLA breached but not flagged important (no subject/body keyword matched) - NOT escalated per policy (stays with employee)');
        continue;
    }

    $assignee = getUser($pdo, $ticket['assigned_to']);
    $manager1 = $assignee ? getUser($pdo, $assignee['reporting_manager_id']) : null;
    $manager2 = $assignee ? getUser($pdo, $assignee['reporting_manager_2_id']) : null;
    $hod      = getUser($pdo, $ticket['hod_user_id']);
    $notificationMailbox = ESCALATION_SENDER_MAILBOX;

    // Managers/Admins are already the Level 2 decision-maker. Their own
    // overdue tickets go directly to the final HR/Admin recipients.
    if ($level === 0 && $assignee && in_array($assignee['role'], ['admin', 'super_admin'], true)) {
        $level = 1;
    }

    if ($level === 0) {
        // Move to Level 2: Manager + HOD, fresh 1-hour window
        // Employee reminder: 1 hour. Manager escalation: 2 hours after stage start.
        $newStageStart = date('Y-m-d H:i:s');
        $newDue = calculateDueDateByHours($newStageStart, 1);

        $pdo->prepare(
            "UPDATE tickets
             SET escalation_level = 1,
                 stage_started_at = ?,
                 sla_due_at = ?,
                 reminder_sent_at = NULL,
                 status = 'open',
                 closed_at = NULL
             WHERE id = ?"
        )->execute([$newStageStart, $newDue, $ticket['id']]);

        logTimelineEvent($ticket['id'], 'escalated', 'Level 2 escalation triggered; notification queued for delivery');

        $subject = "[ACTION REQUIRED - Level 2 Escalation] Employee response overdue: {$ticket['subject']}";
        $senderType = $assignee && strcasecmp($ticket['sender_email'], $assignee['email']) === 0
            ? 'Assigned Employee'
            : 'External Sender';
        $originalMail = originalMailContext($accessToken, $ticket, $assignee);
        $body = "<p>Dear Manager/HOD,</p>
                 <p>The assigned employee has not responded to the following email within the Level 1 SLA.
                 The item is now overdue and has been escalated for your intervention.</p>
                 <p><strong>Subject:</strong> {$ticket['subject']}<br>
                 <strong>From:</strong> {$ticket['sender_email']}<br>
                 <strong>Mail sent by:</strong> {$senderType}<br>
                 <strong>Department:</strong> {$ticket['department_name']}<br>" .
                 ($assignee ? "<strong>Assigned Employee:</strong> {$assignee['name']} ({$assignee['email']})<br>" : "") .
                 "<strong>Original Received:</strong> {$ticket['received_at']}<br>
                 <strong>Level 1 SLA Deadline:</strong> {$ticket['sla_due_at']}<br>
                 <strong>Level 2 Deadline:</strong> {$newDue}</p>
                 <p>Please review the original email and its attachments, ensure the required response is sent,
                 and have the ticket closed once the matter is resolved.</p>{$originalMail['html']}";

        if (empty($ticket['stop_manager_notifications'])) {
            $overrideRecipients = getEscalationRecipientsForTicket($pdo, $ticket, $assignee, $manager1, $manager2, $hod);
            foreach ($overrideRecipients as $recipient) {
                if ($recipient && !empty($recipient['email'])) {
                    // GROUP escalation emails by recipient instead of sending immediately
                    $groupKey = strtolower($recipient['email']);
                    if (!isset($escalationGroups[$groupKey])) {
                        $escalationGroups[$groupKey] = [
                            'recipient' => $recipient,
                            'notification_mailbox' => $notificationMailbox,
                            'items' => [],
                            'attachments' => [],
                        ];
                    }
                    $escalationGroups[$groupKey]['items'][] = [
                        'ticket' => $ticket,
                        'assignee' => $assignee,
                        'level' => 2,
                        'newDue' => $newDue,
                        'original_html' => $originalMail['html'],
                    ];
                    $escalationGroups[$groupKey]['attachments'] = array_merge(
                        $escalationGroups[$groupKey]['attachments'],
                        $originalMail['attachments']
                    );
                }
            }
        }
        $escalatedCount++;

    } elseif ($level === 1) {
        // Move to Level 3 (terminal): HR + Admin, cc everyone involved
        $pdo->prepare(
            "UPDATE tickets
             SET escalation_level = 2,
                 status = 'escalated',
                 closed_at = NOW()
             WHERE id = ?"
        )->execute([$ticket['id']]);

        logTimelineEvent($ticket['id'], 'escalated',
            $assignee && in_array($assignee['role'], ['admin', 'super_admin'], true)
                ? 'Level 3 escalation triggered directly; notification queued for delivery'
                : 'Level 3 escalation triggered; notification queued for delivery');

        $contactStmt = $pdo->prepare(
            "SELECT * FROM escalation_contacts WHERE department_id = ? OR department_id IS NULL"
        );
        $contactStmt->execute([$ticket['department_id']]);
        $contacts = $contactStmt->fetchAll();

        $involved = [];
        if ($assignee) $involved[] = "{$assignee['name']} ({$assignee['email']}) - Employee";
        foreach ([$manager1, $manager2] as $manager) {
            if ($manager) $involved[] = "{$manager['name']} ({$manager['email']}) - Manager";
        }
        if ($hod)      $involved[] = "{$hod['name']} ({$hod['email']}) - HOD";
        $involvedHtml = $involved ? '<li>' . implode('</li><li>', $involved) . '</li>' : '<li>None assigned</li>';

        $subject = "[FINAL ESCALATION] Unresolved after Manager/HOD: {$ticket['subject']}";
        $senderType = $assignee && strcasecmp($ticket['sender_email'], $assignee['email']) === 0
            ? 'Assigned Employee'
            : 'External Sender';
        $originalMail = originalMailContext($accessToken, $ticket, $assignee);
        $body = "<p>This item has breached BOTH the Level 1 and Level 2 SLA windows and requires immediate attention:</p>
                 <p><strong>Subject:</strong> {$ticket['subject']}<br>
                 <strong>From:</strong> {$ticket['sender_email']}<br>
                 <strong>Mail sent by:</strong> {$senderType}<br>
                 <strong>Department:</strong> {$ticket['department_name']}<br>
                 <strong>Received:</strong> {$ticket['received_at']}</p>{$originalMail['html']}
                 <p><strong>People involved so far:</strong></p><ul>{$involvedHtml}</ul>";

        foreach ($contacts as $contact) {
            // GROUP Level 3 escalation emails by recipient
            $groupKey = strtolower($contact['email']);
            if (!isset($escalationGroups[$groupKey])) {
                $escalationGroups[$groupKey] = [
                    'recipient' => $contact,
                    'notification_mailbox' => $notificationMailbox,
                    'items' => [],
                    'attachments' => [],
                ];
            }
            $escalationGroups[$groupKey]['items'][] = [
                'ticket' => $ticket,
                'assignee' => $assignee,
                'level' => 3,
                'involved' => $involved,
                'original_html' => $originalMail['html'],
                'contact_id' => $contact['id'],  // Store contact ID for logging
            ];
            $escalationGroups[$groupKey]['attachments'] = array_merge(
                $escalationGroups[$groupKey]['attachments'],
                $originalMail['attachments']
            );
        }
        $escalatedCount++;
    }
    // level === 2 is terminal - nothing further happens automatically
}

// Send all GROUPED escalation emails (consolidated by recipient)
foreach ($escalationGroups as $group) {
    $openItems = [];
    foreach ($group['items'] as $item) {
        $statusStmt = $pdo->prepare("SELECT status, escalation_level FROM tickets WHERE id = ?");
        $statusStmt->execute([$item['ticket']['id']]);
        $currentTicket = $statusStmt->fetch();
        $expectedLevel = (int)$item['level'] - 1;
        if ($currentTicket && (
            $currentTicket['status'] === 'open'
            || ($currentTicket['status'] === 'closed' && (int)$currentTicket['escalation_level'] === $expectedLevel)
            || ($currentTicket['status'] === 'escalated' && (int)$currentTicket['escalation_level'] === $expectedLevel)
        )) {
            $openItems[] = $item;
        }
    }
    if (!$openItems) continue;
    $group['items'] = $openItems;

    $subject = '[ESCALATION ALERT] ' . count($group['items']) . ' overdue email task(s)';
    $body = '<p>The following email tasks have escalated and require immediate attention:</p>';
    
    foreach ($group['items'] as $item) {
        $ticket = $item['ticket'];
        if ($item['level'] === 2) {
            $body .= '<hr><p><strong>[Level 2 Escalation - Manager/HOD Action Required]</strong></p>';
            $body .= '<p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject']) . '<br>'
                . '<strong>From:</strong> ' . htmlspecialchars($ticket['sender_email']) . '<br>'
                . '<strong>Department:</strong> ' . htmlspecialchars($ticket['department_name']) . '<br>'
                . '<strong>Level 2 Deadline:</strong> ' . htmlspecialchars($item['newDue']) . '</p>'
                . $item['original_html'];
        } elseif ($item['level'] === 3) {
            $involvedHtml = $item['involved'] ? '<li>' . implode('</li><li>', $item['involved']) . '</li>' : '<li>None assigned</li>';
            $body .= '<hr><p><strong>[Level 3 Escalation - FINAL - HR/Director Action Required]</strong></p>';
            $body .= '<p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject']) . '<br>'
                . '<strong>From:</strong> ' . htmlspecialchars($ticket['sender_email']) . '<br>'
                . '<strong>Department:</strong> ' . htmlspecialchars($ticket['department_name']) . '<br>'
                . '<strong>Received:</strong> ' . htmlspecialchars($ticket['received_at']) . '</p>'
                . '<p><strong>People involved so far:</strong></p><ul>' . $involvedHtml . '</ul>'
                . $item['original_html'];
        }
    }
    
    $body .= '<p>Please prioritize these escalated items and respond before their deadlines.</p>';
    
    $sent = sendOrLog(
        $accessToken,
        $group['recipient']['email'],
        $subject,
        $body,
        $group['notification_mailbox'],
        $group['attachments']
    );
    
    foreach ($group['items'] as $item) {
        logTimelineEvent(
            $item['ticket']['id'],
            'escalated',
            $sent
                ? "Level {$item['level']} escalation email sent to {$group['recipient']['name']} ({$group['recipient']['email']})"
                : "Level {$item['level']} escalation email delivery failed for {$group['recipient']['name']} ({$group['recipient']['email']})"
        );
        
        // Log to escalations table for Level 3 escalations
        if ($item['level'] === 3 && isset($item['contact_id'])) {
            $insert = $pdo->prepare(
                "INSERT INTO escalations (ticket_id, escalated_to_contact_id, escalated_at, email_sent, email_sent_at)
                 VALUES (?, ?, NOW(), ?, ?)"
            );
            $insert->execute([$item['ticket']['id'], $item['contact_id'], $sent ? 1 : 0, $sent ? date('Y-m-d H:i:s') : null]);
        }
    }
}

echo "{$sentReminderCount} reminder(s) sent. {$escalatedCount} ticket(s) moved to next level.\n";
