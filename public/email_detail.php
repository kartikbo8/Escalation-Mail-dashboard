<?php
// The layout prints HTML before the POST actions below redirect; buffer it so
// header('Location') still works after Close / Mark replied / Assign.
ob_start();
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';
require_once __DIR__ . '/../includes/keyword_helper.php';

function safeOriginalEmailLink($url) {
    $url = trim((string)$url);
    $parts = parse_url($url);
    if (!is_array($parts)
        || !isset($parts['scheme'])
        || !in_array(strtolower($parts['scheme']), ['http', 'https', 'mailto'], true)
        || (in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            && (empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])))) {
        return null;
    }

    return $url;
}

function sanitizeOriginalEmailHtml($html, &$emailLinks) {
    $document = new DOMDocument('1.0', 'UTF-8');
    $previousErrorMode = libxml_use_internal_errors(true);
    $loaded = $document->loadHTML(
        '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
        LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrorMode);
    if (!$loaded) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $allowedTags = [
        'a', 'b', 'blockquote', 'br', 'caption', 'code', 'div', 'em', 'h1', 'h2',
        'h3', 'h4', 'h5', 'h6', 'hr', 'i', 'li', 'ol', 'p', 'pre', 's', 'span',
        'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul',
    ];
    $removeTags = ['audio', 'button', 'embed', 'form', 'iframe', 'img', 'input', 'object', 'script', 'style', 'svg', 'video'];
    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $removeTags): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, $removeTags, true)) {
                $node->removeChild($child);
                continue;
            }
            if (!in_array($tag, $allowedTags, true)) {
                $sanitizeNode($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $attributeName = strtolower($attribute->name);
                if ($tag === 'a' && $attributeName === 'href') {
                    continue;
                }
                if (in_array($tag, ['td', 'th'], true)
                    && in_array($attributeName, ['colspan', 'rowspan'], true)
                    && ctype_digit($attribute->value)) {
                    continue;
                }
                $child->removeAttributeNode($attribute);
            }

            if ($tag === 'a') {
                $safeUrl = safeOriginalEmailLink($child->getAttribute('href'));
                if ($safeUrl === null) {
                    $sanitizeNode($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                $child->setAttribute('href', $safeUrl);
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            $sanitizeNode($child);
        }
    };

    $body = $document->getElementsByTagName('body')->item(0);
    if (!$body) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $sanitizeNode($body);

    foreach ($body->getElementsByTagName('a') as $anchor) {
        $url = safeOriginalEmailLink($anchor->getAttribute('href'));
        if ($url !== null) {
            $emailLinks[$url] = trim($anchor->textContent) ?: $url;
        }
    }

    $safeHtml = '';
    foreach ($body->childNodes as $child) {
        $safeHtml .= $document->saveHTML($child);
    }
    return $safeHtml;
}

function renderPlainOriginalEmail($text, &$emailLinks) {
    $escapedText = htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linkedText = preg_replace_callback(
        '/https?:\/\/[^\s<>"\']+/iu',
        static function (array $matches) use (&$emailLinks): string {
            $url = rtrim($matches[0], '.,;:!?)]}');
            $trailing = substr($matches[0], strlen($url));
            $safeUrl = safeOriginalEmailLink($url);
            if ($safeUrl === null) {
                return $matches[0];
            }
            $emailLinks[$safeUrl] = $safeUrl;
            return '<a href="' . htmlspecialchars($safeUrl, ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener noreferrer">'
                . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</a>' . $trailing;
        },
        $escapedText
    );
    return nl2br($linkedText ?? $escapedText, false);
}

function formatOriginalEmailAddress($emailAddress, $fallback = '') {
    if (!is_array($emailAddress)) {
        return $fallback;
    }
    $address = trim((string)($emailAddress['address'] ?? ''));
    $name = trim((string)($emailAddress['name'] ?? ''));
    if ($address === '') {
        return $fallback;
    }
    return $name !== '' && strcasecmp($name, $address) !== 0
        ? $name . ' <' . $address . '>'
        : $address;
}

$formatEmailRecipients = static function ($recipients): string {
    if (!is_array($recipients)) {
        return '';
    }
    $addresses = [];
    foreach ($recipients as $recipient) {
        if (!is_array($recipient)) {
            continue;
        }
        $emailAddress = $recipient['emailAddress'] ?? [];
        if (!is_array($emailAddress)) {
            continue;
        }
        $address = trim((string)($emailAddress['address'] ?? ''));
        $name = trim((string)($emailAddress['name'] ?? ''));
        if ($address !== '') {
            $addresses[] = $name !== '' && strcasecmp($name, $address) !== 0
                ? $name . ' <' . $address . '>'
                : $address;
        }
    }
    return implode(', ', $addresses);
};

$pdo = getDB();
$ticketId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT t.*, d.name as department_name, u.name as assignee_name, u.email as assignee_email FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.id = ? AND " . portalTicketCondition('t')
);
$stmt->execute([$ticketId]);
$ticket = $stmt->fetch();

if (!$ticket) {
    echo '<div class="alert alert-danger">Ticket not found.</div>';
    require_once __DIR__ . '/../includes/layout_footer.php';
    exit;
}
$user = currentUser();

$emailBody = null;
$emailBodyHtml = '';
$emailBodyError = '';
$emailLinks = [];
$emailAttachments = [];
$emailFileAttachments = [];
$threadFileAttachments = [];
$emailAttachmentError = '';
$originalHeaders = [];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($ticket['graph_message_id'])) {
        $mailbox = $ticket['source_mailbox'] ?? '';
        if ($mailbox === '') {
            $mailbox = $ticket['assignee_email'] ?? '';
        }

        if ($mailbox === '') {
            $emailBodyError = 'The mailbox for this original email could not be identified.';
        } else {
            $accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);
            if (!$accessToken) {
                $emailBodyError = 'Could not load the original email because a Microsoft Graph access token is unavailable.';
            } else {
                $originalMessage = graphGetMessageForDisplay($accessToken, $mailbox, $ticket['graph_message_id']);
                if (isset($originalMessage['error'])) {
                    $emailBodyError = $originalMessage['error']['message'] ?? 'Microsoft Graph could not load the original email.';
                } else {
                    $originalHeaders = $originalMessage;
                    $emailAttachments = $originalMessage['attachments'] ?? [];
                    $emailAttachmentError = $originalMessage['attachments_error'] ?? '';
                    if (isset($originalMessage['body']['content'])) {
                        $emailBody = (string)$originalMessage['body']['content'];
                        if (strtolower($originalMessage['body']['contentType'] ?? '') === 'html') {
                            $emailBodyHtml = sanitizeOriginalEmailHtml($emailBody, $emailLinks);
                        } else {
                            $emailBodyHtml = renderPlainOriginalEmail($emailBody, $emailLinks);
                        }
                        $emailBody = trim($emailBody);
                    } else {
                        $emailBodyError = 'The original email body was not returned by Microsoft Graph.';
                    }
                    foreach ($emailAttachments as $attachment) {
                        if (!is_array($attachment) || !empty($attachment['isInline'])) {
                            continue;
                        }
                        if (($attachment['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment'
                            && !empty($attachment['id'])) {
                            $emailFileAttachments[] = $attachment;
                        }
                        $attachmentUrl = safeOriginalEmailLink($attachment['sourceUrl'] ?? '');
                        if ($attachmentUrl !== null) {
                            $emailLinks[$attachmentUrl] = trim((string)($attachment['name'] ?? 'Shared document'))
                                ?: 'Shared document';
                        }
                    }

                    // Documents sent later in the thread by the client or an employee.
                    $seenAttachments = [];
                    foreach ($emailFileAttachments as $attachment) {
                        $seenAttachments[strtolower((string)($attachment['name'] ?? '')) . '|' . ($attachment['size'] ?? '')] = true;
                    }
                    $threadMessages = graphGetConversationAttachments(
                        $accessToken,
                        $mailbox,
                        $ticket['conversation_id'] ?? '',
                        $ticket['graph_message_id']
                    );
                    if (isset($threadMessages['error'])) {
                        $emailAttachmentError = $threadMessages['error']['message'] ?? 'Could not load the email thread.';
                        $threadMessages = [];
                    }
                    foreach ($threadMessages as $threadMessage) {
                        foreach ($threadMessage['attachments'] as $attachment) {
                            if (!is_array($attachment) || !empty($attachment['isInline'])) {
                                continue;
                            }
                            $attachmentUrl = safeOriginalEmailLink($attachment['sourceUrl'] ?? '');
                            if ($attachmentUrl !== null) {
                                $emailLinks[$attachmentUrl] = trim((string)($attachment['name'] ?? 'Shared document'))
                                    ?: 'Shared document';
                            }
                            if (($attachment['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment'
                                || empty($attachment['id'])) {
                                continue;
                            }
                            $seenKey = strtolower((string)($attachment['name'] ?? '')) . '|' . ($attachment['size'] ?? '');
                            if (isset($seenAttachments[$seenKey])) {
                                continue;
                            }
                            $seenAttachments[$seenKey] = true;
                            $attachment['message_id'] = $threadMessage['id'];
                            $attachment['sent_by'] = $threadMessage['from']['emailAddress']['address'] ?? '';
                            $attachment['sent_at'] = $threadMessage['receivedDateTime'] ?? '';
                            $threadFileAttachments[] = $attachment;
                        }
                    }
                }
            }
        }
    } else {
        $emailBodyError = 'The original message ID is not available for this ticket.';
    }
}

// Handle manual actions (mark replied / close) - normally these happen automatically
// via Graph API webhook, but manual override is useful while testing.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';
    $user = currentUser();
    // Employees clear a ticket by replying in Outlook (detected automatically) or
    // by requesting closure; marking replied / snoozing could hide unanswered mail.
    $adminOnlyActions = [
        'stop_all_notifications',
        'stop_employee_notifications',
        'stop_manager_notifications',
        'assign',
        'mark_replied',
        'snooze',
    ];
    $isManagerOrAdmin = in_array($user['role'], ['admin', 'super_admin'], true);
    if (in_array($action, $adminOnlyActions, true) && !$isManagerOrAdmin) {
        http_response_code(403);
        exit('Access denied.');
    }
    // Closing is a Manager/Admin decision, otherwise an employee could close
    // every reminder away. Employees request it instead.
    if ($action === 'close' && !$isManagerOrAdmin) {
        http_response_code(403);
        exit('Emails can only be closed by a Manager or Admin. Use "Request close" instead.');
    }
    if ($action === 'request_close' && $isManagerOrAdmin) {
        http_response_code(400);
        exit('Managers and Admins can close the email directly.');
    }
    if (!in_array($action, array_merge($adminOnlyActions, ['mark_replied', 'close', 'snooze', 'request_close']), true)) {
        http_response_code(400);
        exit('Unsupported action.');
    }

    if ($action === 'stop_all_notifications') {
        $user = currentUser();
        if (in_array($user['role'], ['admin', 'super_admin'])) {
            $columns = $user['role'] === 'super_admin'
                ? 'stop_employee_notifications = 1, stop_manager_notifications = 1'
                : 'stop_employee_notifications = 1';
            $pdo->prepare("UPDATE tickets SET {$columns} WHERE id = ?")->execute([$ticketId]);
            logTimelineEvent($ticketId, 'no_reply_warning', 'All permitted notification triggers stopped manually', $user['id']);
        }
    } elseif ($action === 'stop_employee_notifications' || $action === 'stop_manager_notifications') {
        $isSuperAdmin = $user['role'] === 'super_admin';
        $isAdmin = $user['role'] === 'admin';
        $canStopEmployee = $isSuperAdmin || $isAdmin;
        $canStopManager = $isSuperAdmin;

        if (($action === 'stop_employee_notifications' && $canStopEmployee)
            || ($action === 'stop_manager_notifications' && $canStopManager)) {
            $column = $action === 'stop_employee_notifications'
                ? 'stop_employee_notifications'
                : 'stop_manager_notifications';
            $pdo->prepare("UPDATE tickets SET {$column} = 1 WHERE id = ?")->execute([$ticketId]);
            logTimelineEvent($ticketId, 'no_reply_warning',
                ($action === 'stop_employee_notifications' ? 'Employee' : 'Manager/HOD') .
                ' notification triggers stopped manually', $user['id']);
        }
    } elseif ($action === 'mark_replied') {
        $pdo->prepare("UPDATE tickets SET status='replied', replied_at=NOW() WHERE id=?")->execute([$ticketId]);
        logTimelineEvent($ticketId, 'replied', 'Marked as replied manually', $user['id']);
    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE tickets SET status='closed', closed_at=NOW() WHERE id=?")->execute([$ticketId]);
        logTimelineEvent($ticketId, 'closed', "Ticket closed by {$user['name']}", $user['id']);
    } elseif ($action === 'request_close') {
        $reason = trim(preg_replace('/\s+/', ' ', (string)($_POST['close_reason'] ?? '')));
        if ($reason === '' || mb_strlen($reason) > 500) {
            http_response_code(400);
            exit('Please give a short reason (up to 500 characters) why no reply is needed.');
        }
        if (in_array($ticket['status'], ['open', 'escalated'], true)) {
            logTimelineEvent($ticketId, 'no_reply_warning', "Close requested by {$user['name']} - no reply needed: {$reason}", $user['id']);
        }
    } elseif ($action === 'snooze') {
        $durations = ['1h' => 3600, '2h' => 7200, '1d' => 86400];
        $duration = $durations[$_POST['duration'] ?? ''] ?? 0;
        if ($duration > 0 && $ticket['status'] === 'open') {
            $snoozedUntil = new DateTime('now');
            $snoozedUntil->modify("+{$duration} seconds");
            $newStageStart = new DateTime($ticket['stage_started_at'] ?: $ticket['received_at']);
            $newDue = new DateTime($ticket['sla_due_at']);
            $newStageStart->modify("+{$duration} seconds");
            $newDue->modify("+{$duration} seconds");
            $pdo->prepare(
                "UPDATE tickets SET snoozed_until=?, stage_started_at=?, sla_due_at=?, reminder_sent_at=NULL WHERE id=?"
            )->execute([
                $snoozedUntil->format('Y-m-d H:i:s'),
                $newStageStart->format('Y-m-d H:i:s'),
                $newDue->format('Y-m-d H:i:s'),
                $ticketId,
            ]);
            logTimelineEvent($ticketId, 'no_reply_warning', 'Snoozed until ' . $snoozedUntil->format('Y-m-d H:i:s'), $user['id']);
        }
    } elseif ($action === 'assign') {
        $assigneeInput = $_POST['assignee_id'] ?? '';
        $assigneeId = $assigneeInput !== '' ? filter_var($assigneeInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]) : null;
        if ($assigneeId === false) {
            http_response_code(400);
            exit('Invalid assignee.');
        }
        $assignReason = trim($_POST['assign_reason'] ?? '');
        if ($assigneeId !== null) {
            $assigneeCheck = $pdo->prepare(
                "SELECT id FROM users WHERE id = ? AND is_active = 1 AND department_id = ? LIMIT 1"
            );
            $assigneeCheck->execute([$assigneeId, (int)$ticket['department_id']]);
            if (!$assigneeCheck->fetch()) {
                http_response_code(403);
                exit('The selected assignee is not in this ticket’s department.');
            }
        }
        $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?")->execute([$assigneeId, $ticketId]);
        $assigneeName = '';
        if ($assigneeId) {
            $an = $pdo->prepare("SELECT name FROM users WHERE id = ?");
            $an->execute([$assigneeId]);
            $assigneeName = $an->fetchColumn() ?: '';
        }
        $assignmentNote = $assigneeId ? "Assigned to {$assigneeName}" : 'Unassigned';
        if ($assignReason !== '') {
            $assignmentNote .= " - Reason: {$assignReason}";
        }
        logTimelineEvent($ticketId, 'received', $assignmentNote, $user['id']);
    }
    header("Location: email_detail.php?id={$ticketId}");
    exit;
}

$events = $pdo->prepare("SELECT * FROM timeline_events WHERE ticket_id = ? ORDER BY event_time ASC");
$events->execute([$ticketId]);
$timeline = $events->fetchAll();

// Latest employee "no reply needed" request that is still pending (not followed
// by a close, reopen or reply).
$pendingCloseRequest = null;
foreach ($timeline as $event) {
    if (strpos((string)$event['note'], 'Close requested by ') === 0) {
        $pendingCloseRequest = $event;
    } elseif (in_array($event['event_type'], ['closed', 'reopened', 'replied'], true)) {
        $pendingCloseRequest = null;
    }
}
if (!in_array($ticket['status'], ['open', 'escalated'], true)) {
    $pendingCloseRequest = null;
}

$assignableStmt = $pdo->prepare(
    "SELECT id, name FROM users WHERE is_active = 1 AND department_id = ? ORDER BY name"
);
$assignableStmt->execute([(int)$ticket['department_id']]);
$assignableUsers = $assignableStmt->fetchAll();
$canManageTicket = in_array($user['role'], ['admin', 'super_admin'], true);

$eventLabels = [
    'received'         => ['Email Received', 'primary'],
    'replied'          => ['Replied', 'info'],
    'no_reply_warning' => ['No Reply Warning', 'warning'],
    'escalated'        => ['Escalated', 'danger'],
    'closed'           => ['Closed', 'success'],
    'reopened'         => ['Reopened', 'primary'],
];

$isAutomatedSender = isIgnoredSender($ticket['sender_email']);
$escalationLevel = (int)($ticket['escalation_level'] ?? 0);
$formatTicketTime = static function ($value) {
    $time = $value ? strtotime($value) : false;
    return $time ? date('d M Y, h:i A', $time) : '-';
};
$backUrl = !empty($ticket['is_important']) ? 'email_list.php' : 'all_emails.php';
$backLabel = !empty($ticket['is_important']) ? 'Email List' : 'All Emails';

if ($isAutomatedSender) {
    $flowClass = 'is-automated';
    $flowTitle = 'Automated sender - All Emails only';
    $flowText = 'This mail comes from an automated / notification sender. It is kept for record only: no reminder or escalation will be sent.';
} elseif ($escalationLevel >= 2) {
    $flowClass = 'is-final';
    $flowTitle = 'Level 3 escalation completed';
    $flowText = 'Final escalation has been sent to HR/Admin. The reminder and escalation cycle will not restart for this ticket.';
} elseif (!empty($ticket['is_important'])) {
    $flowClass = 'is-important';
    $flowTitle = 'Important - in escalation queue';
    $flowText = 'Matched keyword: ' . ($ticket['matched_keyword'] ?: '-')
        . '. Reminder goes to the employee first, then escalates to Manager/HOD and HR/Admin if there is no reply.';
} else {
    $flowClass = 'is-normal';
    $flowTitle = 'Normal email';
    $flowText = 'No priority keyword matched. It stays with the assigned employee and will not escalate to Manager/HOD/HR.';
}
?>

<a href="<?= $backUrl ?>" class="text-decoration-none">&larr; Back to <?= $backLabel ?></a>

<div class="card shadow-sm mt-3 ticket-summary">
    <div class="card-body">
        <div class="ticket-summary-head">
            <div class="ticket-summary-title">
                <h4><?= htmlspecialchars($ticket['subject']) ?></h4>
                <div class="text-muted">
                    From: <?= htmlspecialchars($ticket['sender_email']) ?> ·
                    Department: <?= htmlspecialchars($ticket['department_name']) ?>
                </div>
            </div>
            <div class="ticket-summary-badges">
                <?= statusBadge($ticket['status']) ?>
                <?php if (!$isAutomatedSender && $escalationLevel === 1): ?>
                    <span class="badge bg-danger">Level 2 Escalated</span>
                <?php elseif (!$isAutomatedSender && $escalationLevel >= 2): ?>
                    <span class="badge bg-dark">Level 3 Escalated</span>
                <?php endif; ?>
                <?= priorityBadge($ticket['priority_tag'] ?? 'normal', $ticket['matched_keyword'] ?? null) ?>
            </div>
        </div>

        <?php if ($pendingCloseRequest): ?>
            <div class="ticket-flow is-close-request">
                <strong>Close requested - waiting for Manager / Admin</strong>
                <span><?= htmlspecialchars(substr($pendingCloseRequest['note'], strlen('Close requested by '))) ?>
                    (<?= htmlspecialchars($formatTicketTime($pendingCloseRequest['event_time'])) ?>).
                    <?= $canManageTicket
                        ? 'Review the email below, then use "Close Ticket" to approve. Reminders continue until it is closed.'
                        : 'Reminders continue until a Manager / Admin closes it.' ?></span>
            </div>
        <?php endif; ?>
        <div class="ticket-flow <?= $flowClass ?>">
            <strong><?= htmlspecialchars($flowTitle) ?></strong>
            <span><?= htmlspecialchars($flowText) ?></span>
        </div>

        <dl class="ticket-facts">
            <div><dt>Received</dt><dd><?= $formatTicketTime($ticket['received_at']) ?></dd></div>
            <div><dt>Assigned To</dt><dd><?= htmlspecialchars($ticket['assignee_name'] ?? 'Unassigned') ?></dd></div>
            <div><dt>Replied At</dt><dd><?= $formatTicketTime($ticket['replied_at']) ?></dd></div>
            <?php if (!$isAutomatedSender): ?>
                <div><dt>SLA Due</dt><dd><?= $formatTicketTime($ticket['sla_due_at']) ?></dd></div>
                <div><dt>Reminder Sent</dt><dd><?= $formatTicketTime($ticket['reminder_sent_at']) ?></dd></div>
                <?php if (!empty($ticket['snoozed_until'])): ?>
                    <div><dt>Snoozed Until</dt><dd><?= $formatTicketTime($ticket['snoozed_until']) ?></dd></div>
                <?php endif; ?>
            <?php endif; ?>
        </dl>

        <div class="ticket-actions">
        <?php if ($canManageTicket && !$isAutomatedSender && $escalationLevel < 2): ?>
        <div class="ticket-action-row">
            <span class="ticket-action-label">Notification controls</span>
            <div class="d-flex flex-wrap gap-2">
                <?php if ($user['role'] === 'super_admin' && (empty($ticket['stop_employee_notifications']) || empty($ticket['stop_manager_notifications']))): ?>
                    <form method="POST" onsubmit="return confirm('Stop all trigger mails for this ticket?');">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="stop_all_notifications" class="btn btn-sm btn-danger">
                            Stop All Trigger Mails
                        </button>
                    </form>
                <?php elseif ($user['role'] === 'admin' && empty($ticket['stop_employee_notifications'])): ?>
                    <form method="POST" onsubmit="return confirm('Stop the employee trigger mail for this ticket?');">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="stop_all_notifications" class="btn btn-sm btn-danger">
                            Stop Trigger Mail
                        </button>
                    </form>
                <?php endif; ?>
                <?php if (!empty($ticket['stop_employee_notifications'])): ?>
                    <span class="badge bg-secondary">Employee triggers stopped</span>
                <?php elseif ($canManageTicket): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="stop_employee_notifications" class="btn btn-sm btn-outline-warning">
                            Stop Employee Trigger Mail
                        </button>
                    </form>
                <?php endif; ?>

                <?php if (!empty($ticket['stop_manager_notifications'])): ?>
                    <span class="badge bg-secondary">Manager/HOD triggers stopped</span>
                <?php elseif ($user['role'] === 'super_admin'): ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <button type="submit" name="action" value="stop_manager_notifications" class="btn btn-sm btn-outline-warning">
                            Stop Manager/HOD Trigger Mail
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageTicket && in_array($ticket['status'], ['open', 'replied'])): ?>
        <div class="ticket-action-row">
            <span class="ticket-action-label">Assign to</span>
                <form method="POST" class="d-flex flex-wrap gap-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="assign">
                    <select name="assignee_id" class="form-select form-select-sm" style="width:auto;">
                        <option value="">-- Unassigned --</option>
                        <?php foreach ($assignableUsers as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $ticket['assigned_to'] == $u['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($u['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                        <textarea name="assign_reason" class="form-control form-control-sm" rows="1" placeholder="Comment" style="min-width:220px;" aria-label="Assignment comment"></textarea>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Assign</button>
                </form>
        </div>
        <?php endif; ?>

        <?php if (in_array($ticket['status'], ['open', 'escalated'])): ?>
        <?php if ($canManageTicket): ?>
        <div class="ticket-action-row">
            <span class="ticket-action-label">Ticket</span>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <form method="POST" class="d-flex gap-2">
                    <?= csrfField() ?>
                    <button type="submit" name="action" value="mark_replied" class="btn btn-sm btn-info">Mark as Replied</button>
                    <button type="submit" name="action" value="close" class="btn btn-sm btn-success">Close Ticket</button>
                </form>
                <?php if ($ticket['status'] === 'open' && !$isAutomatedSender): ?>
                <form method="POST" class="d-flex align-items-center gap-2 ticket-snooze">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="snooze">
                    <label for="snooze-duration" class="visually-hidden">Snooze for</label>
                    <select id="snooze-duration" name="duration" class="form-select form-select-sm" style="width:auto;">
                        <option value="1h">1 hour</option>
                        <option value="2h">2 hours</option>
                        <option value="1d">Tomorrow</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-secondary">Snooze</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!$canManageTicket): ?>
        <div class="ticket-action-row">
            <span class="ticket-action-label">How to clear this</span>
            <span class="small text-muted">Reply to the sender in Outlook (Reply All on the original email) - the tracker picks it up automatically.</span>
        </div>
        <?php endif; ?>
        <?php if (!$canManageTicket && !$pendingCloseRequest): ?>
        <div class="ticket-action-row">
            <span class="ticket-action-label">No reply needed / already handled?</span>
            <form method="POST" class="d-flex flex-wrap gap-2">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="request_close">
                <textarea name="close_reason" class="form-control form-control-sm" rows="1" maxlength="500" required
                    placeholder="e.g. FYI only / answered on phone / handled by Ajay" aria-label="Reason no reply is needed"></textarea>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Request close</button>
            </form>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</div>
<div class="card shadow-sm mt-4">
    <div class="card-header">Original Email</div>
    <?php if ($originalHeaders): ?>
        <div class="original-email-meta">
            <div>
                <strong>From</strong>
                <span><?= htmlspecialchars(
                    formatOriginalEmailAddress($originalHeaders['from']['emailAddress'] ?? null, $ticket['sender_email']),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></span>
            </div>
            <?php foreach ([
                'To' => $formatEmailRecipients($originalHeaders['toRecipients'] ?? []),
                'CC' => $formatEmailRecipients($originalHeaders['ccRecipients'] ?? []),
            ] as $label => $addresses): ?>
                <?php if ($addresses !== ''): ?>
                    <div><strong><?= $label ?></strong><span><?= htmlspecialchars($addresses, ENT_QUOTES, 'UTF-8') ?></span></div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div>
                <strong>Received</strong>
                <span><?= htmlspecialchars(
                    !empty($originalHeaders['receivedDateTime'])
                        ? date('d M Y, h:i A', strtotime($originalHeaders['receivedDateTime']))
                        : date('d M Y, h:i A', strtotime($ticket['received_at'])),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?></span>
            </div>
        </div>
    <?php endif; ?>
    <div class="card-body original-email-body">
        <?php if ($emailBodyError !== ''): ?>
            <div class="text-muted"><?= htmlspecialchars($emailBodyError) ?></div>
        <?php elseif ($emailBody === ''): ?>
            <div class="text-muted">This email has no message body.</div>
        <?php else: ?>
            <?= $emailBodyHtml ?>
        <?php endif; ?>
    </div>
    <?php if ($emailAttachmentError !== ''): ?>
        <div class="card-footer small text-warning-emphasis">
            Shared document details could not be loaded: <?= htmlspecialchars($emailAttachmentError, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($emailLinks): ?>
        <div class="original-email-links">
            <h5>Documents &amp; links in this email</h5>
            <ul>
                <?php foreach ($emailLinks as $url => $label): ?>
                    <li>
                        <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                            target="_blank" rel="noopener noreferrer">
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($emailFileAttachments): ?>
        <div class="original-email-links">
            <h5>Attached documents</h5>
            <ul>
                <?php foreach ($emailFileAttachments as $attachment): ?>
                    <?php
                    $attachmentName = trim((string)($attachment['name'] ?? 'Email document'));
                    if ($attachmentName === '') {
                        $attachmentName = 'Email document';
                    }
                    ?>
                    <li>
                        <a href="email_attachment.php?ticket=<?= (int)$ticket['id'] ?>&amp;attachment=<?= rawurlencode((string)$attachment['id']) ?>">
                            <?= htmlspecialchars($attachmentName, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php if (isset($attachment['size']) && is_numeric($attachment['size'])): ?>
                            <span class="text-muted small">
                                (<?= htmlspecialchars(number_format((int)$attachment['size'] / 1024, 1), ENT_QUOTES, 'UTF-8') ?> KB)
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php if ($threadFileAttachments): ?>
        <div class="original-email-links">
            <h5>Documents sent later in this thread</h5>
            <ul>
                <?php foreach ($threadFileAttachments as $attachment): ?>
                    <?php
                    $attachmentName = trim((string)($attachment['name'] ?? 'Email document'));
                    if ($attachmentName === '') {
                        $attachmentName = 'Email document';
                    }
                    $sentAt = $attachment['sent_at'] !== '' ? strtotime($attachment['sent_at']) : false;
                    ?>
                    <li>
                        <a href="email_attachment.php?ticket=<?= (int)$ticket['id'] ?>&amp;message=<?= rawurlencode((string)$attachment['message_id']) ?>&amp;attachment=<?= rawurlencode((string)$attachment['id']) ?>">
                            <?= htmlspecialchars($attachmentName, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <span class="text-muted small">
                            <?php if (isset($attachment['size']) && is_numeric($attachment['size'])): ?>
                                (<?= htmlspecialchars(number_format((int)$attachment['size'] / 1024, 1), ENT_QUOTES, 'UTF-8') ?> KB)
                            <?php endif; ?>
                            &middot; <?= htmlspecialchars($attachment['sent_by'] ?: 'Unknown sender', ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($sentAt): ?>
                                &middot; <?= htmlspecialchars(date('d M Y, h:i A', $sentAt), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<div class="card shadow-sm mt-4">
    <div class="card-header">Timeline</div>
    <ul class="list-group list-group-flush">
        <?php if (empty($timeline)): ?>
            <li class="list-group-item text-muted">No timeline events yet.</li>
        <?php endif; ?>
        <?php foreach ($timeline as $e):
            [$label, $color] = $eventLabels[$e['event_type']] ?? [$e['event_type'], 'secondary'];
        ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <div>
                    <span class="badge bg-<?= $color ?> me-2"><?= $label ?></span>
                    <?= htmlspecialchars($e['note'] ?? '') ?>
                </div>
                <span class="text-muted small"><?= $e['event_time'] ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
