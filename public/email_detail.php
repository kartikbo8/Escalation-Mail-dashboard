<?php
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';

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

$emailBody = null;
$emailBodyError = '';
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
                $originalMessage = graphGetMessageBody($accessToken, $mailbox, $ticket['graph_message_id']);
                if (isset($originalMessage['error'])) {
                    $emailBodyError = $originalMessage['error']['message'] ?? 'Microsoft Graph could not load the original email.';
                } elseif (isset($originalMessage['body']['content'])) {
                    $emailBody = (string)$originalMessage['body']['content'];
                    if (strtolower($originalMessage['body']['contentType'] ?? '') === 'html') {
                        $emailBody = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1\s*>/is', '', $emailBody);
                        $emailBody = preg_replace(
                            '/<\s*br\s*\/?\s*>|<\/\s*(p|div|li|tr|h[1-6]|blockquote)\s*>/i',
                            "\n",
                            $emailBody
                        );
                        $emailBody = strip_tags($emailBody);
                    }
                    $emailBody = trim(html_entity_decode($emailBody, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                } else {
                    $emailBodyError = 'The original email body was not returned by Microsoft Graph.';
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
        logTimelineEvent($ticketId, 'closed', 'Ticket closed manually', $user['id']);
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
        $assigneeId = $_POST['assignee_id'] !== '' ? (int)$_POST['assignee_id'] : null;
        $assignReason = trim($_POST['assign_reason'] ?? '');
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

$assignableUsers = $pdo->query("SELECT id, name FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();

$eventLabels = [
    'received'         => ['Email Received', 'primary'],
    'replied'          => ['Replied', 'info'],
    'no_reply_warning' => ['No Reply Warning', 'warning'],
    'escalated'        => ['Escalated', 'danger'],
    'closed'           => ['Closed', 'success'],
    'reopened'         => ['Reopened', 'primary'],
];
?>

<a href="email_list.php" class="text-decoration-none">&larr; Back to Email List</a>

<div class="card shadow-sm mt-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h4><?= htmlspecialchars($ticket['subject']) ?></h4>
                <div class="text-muted">
                    From: <?= htmlspecialchars($ticket['sender_email']) ?> ·
                    Department: <?= htmlspecialchars($ticket['department_name']) ?>
                </div>
            </div>
            <div class="text-end">
                <?= statusBadge($ticket['status']) ?><br>
                <?php if ((int)($ticket['escalation_level'] ?? 0) === 1): ?>
                    <span class="badge bg-danger mt-1">Level 2 Escalated</span><br>
                <?php elseif ((int)($ticket['escalation_level'] ?? 0) >= 2): ?>
                    <span class="badge bg-dark mt-1">Level 3 Escalated</span><br>
                <?php endif; ?>
                <span class="mt-1 d-inline-block"><?= priorityBadge($ticket['priority_tag'] ?? 'normal') ?></span>
            </div>
        </div>

        <?php if (!empty($ticket['is_important'])): ?>
            <div class="mt-2">
                <span class="badge bg-warning">⚠️ Important - Escalation Queue</span>
                <span class="text-muted small ms-1">Matched keyword: <strong><?= htmlspecialchars($ticket['matched_keyword']) ?></strong> (found in subject)</span>
            </div>
        <?php else: ?>
            <div class="text-muted small mt-2">No priority keyword in subject - stays with employee, will NOT escalate to Manager/HOD/Director/HR</div>
        <?php endif; ?>

        <div class="row mt-3">
            <div class="col-md-3"><strong>Received:</strong> <?= $ticket['received_at'] ?></div>
            <div class="col-md-3"><strong>Reminder Sent:</strong> <?= $ticket['reminder_sent_at'] ?? '-' ?></div>
            <div class="col-md-3"><strong>SLA Due:</strong> <?= $ticket['sla_due_at'] ?? '-' ?></div>
            <div class="col-md-3"><strong>Replied At:</strong> <?= $ticket['replied_at'] ?? '-' ?></div>
            <div class="col-md-3"><strong>Snoozed Until:</strong> <?= $ticket['snoozed_until'] ?? '-' ?></div>
        </div>

        <div class="mt-3">
            <strong>Notification Controls:</strong>
            <div class="d-flex flex-wrap gap-2 mt-2">
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
                <?php elseif (in_array($user['role'], ['admin', 'super_admin'])): ?>
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

        <div class="mt-3">
            <strong>Assigned To (Level 1 employee):</strong>
            <?php if (in_array($ticket['status'], ['open', 'replied'])): ?>
                <form method="POST" class="d-inline-flex gap-2 mt-1">
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
            <?php else: ?>
                <span><?= htmlspecialchars($ticket['assignee_name'] ?? 'Unassigned') ?></span>
            <?php endif; ?>
        </div>

        <?php if (in_array($ticket['status'], ['open', 'escalated'])): ?>
        <form method="POST" class="mt-3 d-flex gap-2">
            <?= csrfField() ?>
            <button type="submit" name="action" value="mark_replied" class="btn btn-sm btn-info">Mark as Replied</button>
            <button type="submit" name="action" value="close" class="btn btn-sm btn-success">Close Ticket</button>
        </form>
        <?php if ($ticket['status'] === 'open'): ?>
        <form method="POST" class="mt-2 d-flex align-items-center gap-2">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="snooze">
            <label for="snooze-duration">Snooze:</label>
            <select id="snooze-duration" name="duration" class="form-select form-select-sm" style="width:auto;">
                <option value="1h">1 hour</option>
                <option value="2h">2 hours</option>
                <option value="1d">Tomorrow</option>
            </select>
            <button type="submit" class="btn btn-sm btn-outline-secondary">Snooze</button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<div class="card shadow-sm mt-4">
    <div class="card-header"><strong>Original Email</strong></div>
    <div class="card-body">
        <?php if ($emailBodyError !== ''): ?>
            <div class="text-muted"><?= htmlspecialchars($emailBodyError) ?></div>
        <?php elseif ($emailBody === ''): ?>
            <div class="text-muted">This email has no message body.</div>
        <?php else: ?>
            <div style="white-space:pre-wrap; overflow-wrap:anywhere;"><?= nl2br(htmlspecialchars($emailBody, ENT_QUOTES, 'UTF-8')) ?></div>
        <?php endif; ?>
    </div>
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
