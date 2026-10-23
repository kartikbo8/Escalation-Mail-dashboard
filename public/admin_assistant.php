<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'super_admin']);
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';
require_once __DIR__ . '/../includes/ai_helper.php';

$pdo = getDB();
$user = currentUser();
$error = '';
$notice = '';
$preview = null;
$actionLabels = [
    'pause_notifications' => 'Pause notification triggers allowed for your role',
    'escalate_admin' => 'Send an alert to configured HR/Admin contacts',
    'send_reminder' => 'Send a reminder to the assigned employee',
];
$employeeQuery = "SELECT u.id, u.name, u.department_id, d.name AS department_name
                  FROM users u
                  JOIN departments d ON d.id = u.department_id
                  WHERE u.role = 'user'";
$employeeParams = [];
if ($user['role'] !== 'super_admin') {
    $employeeQuery .= ' AND u.department_id = ?';
    $employeeParams[] = (int)$user['department_id'];
}
$employeeQuery .= ' ORDER BY u.name, d.name';
$employeeStmt = $pdo->prepare($employeeQuery);
$employeeStmt->execute($employeeParams);
$reportEmployees = $employeeStmt->fetchAll();
$employeeReportReady = null;
$employeeReportError = '';

function getAssistantTicket($pdo, $ticketId, $user) {
    $sql = "SELECT t.*, d.name AS department_name, u.name AS assignee_name, u.email AS assignee_email
            FROM tickets t
            JOIN departments d ON d.id = t.department_id
            LEFT JOIN users u ON u.id = t.assigned_to
            WHERE t.id = ?";
    $params = [$ticketId];
    if ($user['role'] !== 'super_admin') {
        if (empty($user['department_id'])) {
            return null;
        }
        $sql .= " AND t.department_id = ?";
        $params[] = (int)$user['department_id'];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

function getAssistantEscalationContacts($pdo, $ticket) {
    $stmt = $pdo->prepare(
        "SELECT name, email FROM escalation_contacts
         WHERE role_type IN ('hr', 'admin')
           AND email <> ''
           AND (department_id = ? OR department_id IS NULL)
         ORDER BY role_type, name"
    );
    $stmt->execute([$ticket['department_id']]);
    $contactsByEmail = [];
    foreach ($stmt->fetchAll() as $contact) {
        $email = strtolower(trim((string)$contact['email']));
        if ($email !== '') {
            $contactsByEmail[$email] = $contact;
        }
    }
    return array_values($contactsByEmail);
}

function assistantRecipientEmails($contacts) {
    $emails = array_map(
        static fn($contact) => strtolower(trim((string)$contact['email'])),
        $contacts
    );
    sort($emails, SORT_STRING);
    return $emails;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $postAction = $_POST['action'] ?? '';

    if ($postAction === 'employee_report') {
        $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        foreach ($reportEmployees as $employee) {
            if ($employeeId !== false && (int)$employee['id'] === $employeeId) {
                $employeeReportReady = $employee;
                break;
            }
        }
        if (!$employeeReportReady) {
            $employeeReportError = 'Select an employee available in your department to prepare the report.';
        }
    } elseif ($postAction === 'interpret') {
        $commandInput = $_POST['command'] ?? '';
        $command = is_string($commandInput) ? trim($commandInput) : '';
        if ($command === '' || strlen($command) > 240) {
            $error = 'Enter a short command with a ticket number or employee name. Do not paste email content.';
        } elseif (preg_match('/\b(?:report|workload|performance|spreadsheet|workbook|excel|csv)\b|रिपोर्ट/iu', $command)
            || (!empty($_SESSION['assistant_employee_report_request'])
                && preg_match('/\b(?:pause|stop|suspend|disable|hold|reminder|remind|alert|escalate|notify)\b/i', $command) !== 1)) {
            $matchedEmployees = [];
            foreach ($reportEmployees as $employee) {
                if (stripos($command, $employee['name']) !== false) {
                    $matchedEmployees[] = $employee;
                }
            }
            if (count($matchedEmployees) === 1) {
                $employeeReportReady = $matchedEmployees[0];
                unset($_SESSION['assistant_employee_report_request']);
            } elseif (count($matchedEmployees) > 1) {
                $employeeReportError = 'More than one employee name matched. Select the correct employee below.';
                $_SESSION['assistant_employee_report_request'] = true;
            } else {
                $employeeReportError = 'Tell me the employee’s name, or select the employee below. No ticket number is needed for an employee report.';
                $_SESSION['assistant_employee_report_request'] = true;
            }
        } else {
            unset($_SESSION['assistant_employee_report_request']);
            $parsed = parseAdminAssistantCommand($command);
            if ($parsed === null) {
                $error = 'AI could not safely interpret that command. Please use a clear action and ticket ID.';
            } elseif ($parsed['action'] === 'unknown' || $parsed['ticket_id'] === null) {
                $error = 'Please specify one action and an explicit ticket ID. Example: "Ticket 123 send reminder".';
            } else {
                $ticket = getAssistantTicket($pdo, $parsed['ticket_id'], $user);
                if (!$ticket) {
                    $error = 'Ticket not found or you do not have access to it.';
                } elseif (!isset($actionLabels[$parsed['action']])) {
                    $error = 'Unsupported assistant action.';
                } elseif ($parsed['action'] === 'pause_notifications'
                    && !in_array($ticket['status'], ['open', 'escalated'], true)) {
                    $error = 'Only an active open/escalated ticket can have its notification triggers paused.';
                } elseif ($parsed['action'] === 'send_reminder'
                    && ($ticket['status'] !== 'open' || empty($ticket['assignee_email']))) {
                    $error = 'A reminder requires an open ticket with an assigned employee.';
                } else {
                    $pendingAction = [
                        'action' => $parsed['action'],
                        'ticket_id' => (int)$ticket['id'],
                        'created_at' => time(),
                    ];
                    if ($parsed['action'] === 'escalate_admin') {
                        $contacts = getAssistantEscalationContacts($pdo, $ticket);
                        if (!$contacts) {
                            $error = 'No HR/Admin escalation contacts are configured for this department.';
                        } else {
                            $pendingAction['recipient_emails'] = assistantRecipientEmails($contacts);
                        }
                    } elseif ($parsed['action'] === 'send_reminder') {
                        $pendingAction['assignee_email'] = strtolower(trim($ticket['assignee_email']));
                    }
                    if ($error !== '') {
                        unset($_SESSION['admin_assistant_pending']);
                    } else {
                        $_SESSION['admin_assistant_pending'] = $pendingAction;
                    }
                }
            }
        }
    } elseif ($postAction === 'cancel') {
        unset($_SESSION['admin_assistant_pending']);
        $notice = 'Pending action cancelled. No changes were made.';
    } elseif ($postAction === 'confirm') {
        $pending = $_SESSION['admin_assistant_pending'] ?? null;
        unset($_SESSION['admin_assistant_pending']);
        if (!is_array($pending)
            || !isset($pending['action'], $pending['ticket_id'], $pending['created_at'])
            || time() - (int)$pending['created_at'] > 300
            || !isset($actionLabels[$pending['action']])) {
            $error = 'Confirmation expired or unavailable. Please submit the command again.';
        } else {
            $ticket = getAssistantTicket($pdo, (int)$pending['ticket_id'], $user);
            if (!$ticket) {
                $error = 'Ticket not found or you do not have access to it.';
            } else {
                $action = $pending['action'];
                if ($action === 'pause_notifications') {
                    if (!in_array($ticket['status'], ['open', 'escalated'], true)) {
                        $error = 'Only an active open/escalated ticket can have its notification triggers paused.';
                    } else {
                        $columns = $user['role'] === 'super_admin'
                            ? 'stop_employee_notifications = 1, stop_manager_notifications = 1'
                            : 'stop_employee_notifications = 1';
                        $pdo->prepare("UPDATE tickets SET {$columns} WHERE id = ?")
                            ->execute([$ticket['id']]);
                        logTimelineEvent(
                            $ticket['id'],
                            'no_reply_warning',
                            'AI Action Assistant paused permitted notification triggers; ticket status unchanged',
                            $user['id']
                        );
                        $notice = $user['role'] === 'super_admin'
                            ? 'Employee and manager/HOD notification triggers paused. Ticket status was not changed.'
                            : 'Employee notification triggers paused. Manager/HOD escalation notifications may still continue. Ticket status was not changed.';
                    }
                } elseif ($action === 'send_reminder') {
                    if ($ticket['status'] !== 'open' || empty($ticket['assignee_email'])) {
                        $error = 'A reminder can only be sent for an open ticket with an assigned employee.';
                    } elseif (strtolower(trim($ticket['assignee_email'])) !== ($pending['assignee_email'] ?? '')) {
                        $error = 'The assigned employee changed after preview. Review the ticket and submit the command again.';
                    } else {
                        $accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);
                        if (!$accessToken) {
                            logTimelineEvent(
                                $ticket['id'],
                                'no_reply_warning',
                                'AI Action Assistant reminder not sent: Microsoft Graph access token unavailable',
                                $user['id']
                            );
                            $error = 'Microsoft Graph access token is unavailable; reminder was not sent.';
                        } else {
                            $body = '<p>This is a manual reminder to review the following pending email task.</p>'
                                . '<p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>From:</strong> ' . htmlspecialchars($ticket['sender_email'], ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Department:</strong> ' . htmlspecialchars($ticket['department_name'], ENT_QUOTES, 'UTF-8') . '<br>'
                                . '<strong>Current deadline:</strong> ' . htmlspecialchars((string)$ticket['sla_due_at'], ENT_QUOTES, 'UTF-8') . '</p>';
                            $sent = graphSendMail(
                                $accessToken,
                                $ticket['assignee_email'],
                                '[MANUAL REMINDER] ' . $ticket['subject'],
                                $body,
                                'notifications@afcindia.in'
                            );
                            logTimelineEvent(
                                $ticket['id'],
                                'no_reply_warning',
                                $sent
                                    ? "AI Action Assistant sent a manual reminder to {$ticket['assignee_email']}"
                                    : "AI Action Assistant reminder delivery failed for {$ticket['assignee_email']}",
                                $user['id']
                            );
                            $notice = $sent ? 'Reminder sent to the assigned employee.' : '';
                            if (!$sent) {
                                $error = 'Reminder delivery failed. The failure was recorded; check Graph permissions and logs.';
                            }
                        }
                    }
                } elseif ($action === 'escalate_admin') {
                    if (!in_array($ticket['status'], ['open', 'escalated'], true)) {
                        $error = 'Only an active open/escalated ticket can be manually escalated.';
                    } else {
                        $contacts = getAssistantEscalationContacts($pdo, $ticket);
                        if (!$contacts) {
                            $error = 'No HR/Admin escalation contacts are configured for this department.';
                        } elseif (!isset($pending['recipient_emails'])
                            || assistantRecipientEmails($contacts) !== $pending['recipient_emails']) {
                            $error = 'The HR/Admin recipient list changed after preview. Review the recipients and submit the command again.';
                        } else {
                            $accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);
                            if (!$accessToken) {
                                logTimelineEvent(
                                    $ticket['id'],
                                    'escalated',
                                    'AI Action Assistant manual alert not sent: Microsoft Graph access token unavailable',
                                    $user['id']
                                );
                                $error = 'Microsoft Graph access token is unavailable; escalation email was not sent.';
                            } else {
                                $body = '<p>An administrator manually requested attention for this active ticket.</p>'
                                    . '<p><strong>Subject:</strong> ' . htmlspecialchars($ticket['subject'], ENT_QUOTES, 'UTF-8') . '<br>'
                                    . '<strong>From:</strong> ' . htmlspecialchars($ticket['sender_email'], ENT_QUOTES, 'UTF-8') . '<br>'
                                    . '<strong>Department:</strong> ' . htmlspecialchars($ticket['department_name'], ENT_QUOTES, 'UTF-8') . '<br>'
                                    . '<strong>Status:</strong> ' . htmlspecialchars($ticket['status'], ENT_QUOTES, 'UTF-8') . '<br>'
                                    . '<strong>Ticket:</strong> #' . (int)$ticket['id'] . '</p>';
                                $allSent = true;
                                $sentCount = 0;
                                foreach ($contacts as $contact) {
                                    $sent = graphSendMail(
                                        $accessToken,
                                        $contact['email'],
                                        '[MANUAL ADMIN ESCALATION] ' . $ticket['subject'],
                                        $body,
                                        'notifications@afcindia.in'
                                    );
                                    $allSent = $allSent && $sent;
                                    $sentCount += $sent ? 1 : 0;
                                    logTimelineEvent(
                                        $ticket['id'],
                                        'escalated',
                                        $sent
                                            ? "AI Action Assistant sent manual alert to {$contact['email']}"
                                            : "AI Action Assistant manual alert delivery failed for {$contact['email']}",
                                        $user['id']
                                    );
                                }
                                if ($allSent) {
                                    $notice = "Manual alert sent to all " . count($contacts) . " configured HR/Admin contact(s). Ticket's automatic stage was not changed.";
                                } else {
                                    $error = "Only {$sentCount} of " . count($contacts) . " HR/Admin alert(s) were accepted by Graph. Check logs before retrying to avoid duplicates.";
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

$pending = $_SESSION['admin_assistant_pending'] ?? null;
if (is_array($pending)
    && isset($pending['created_at'])
    && isset($pending['ticket_id'], $pending['action'])
    && time() - (int)$pending['created_at'] <= 300) {
    $pendingTicket = getAssistantTicket($pdo, (int)$pending['ticket_id'], $user);
    if (!$pendingTicket || !isset($actionLabels[$pending['action']])) {
        unset($_SESSION['admin_assistant_pending']);
        $pending = null;
    } else {
        $preview = [
            'action' => $pending['action'],
            'ticket' => $pendingTicket,
            'snapshot_matches' => true,
        ];
        if ($pending['action'] === 'send_reminder') {
            $preview['snapshot_matches'] = strtolower(trim((string)$pendingTicket['assignee_email']))
                === ($pending['assignee_email'] ?? '');
        } elseif ($pending['action'] === 'escalate_admin') {
            $currentContacts = getAssistantEscalationContacts($pdo, $pendingTicket);
            $preview['contacts'] = $currentContacts;
            $preview['snapshot_matches'] = assistantRecipientEmails($currentContacts)
                === ($pending['recipient_emails'] ?? []);
        }
    }
} else {
    unset($_SESSION['admin_assistant_pending']);
}

require_once __DIR__ . '/../includes/layout_header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <h3 class="mb-2">AI Action Assistant</h3>
                <p class="text-muted">
                    Use the floating AI Help button on any page for application questions. Request a confirmed ticket action below.
                </p>
                <div class="alert alert-info">
                    Ticket actions are previewed and require confirmation. Do not share email content, credentials, tokens, or personal data in AI Help chat.
                </div>

                <section class="border rounded p-3 mb-4" aria-labelledby="employee-report-heading">
                    <h4 id="employee-report-heading">Employee report</h4>
                    <p class="text-muted">
                        Choose an employee by name to download an all-time report of currently retained tickets.
                        Closed tickets older than 10 days may have been removed by the system.
                    </p>
                    <?php if ($employeeReportError !== ''): ?>
                        <div class="alert alert-warning"><?= htmlspecialchars($employeeReportError, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($employeeReportReady): ?>
                        <div class="alert alert-success">
                            Report is ready for <?= htmlspecialchars($employeeReportReady['name'], ENT_QUOTES, 'UTF-8') ?>.
                            Download either format:
                            <a class="alert-link"
                                href="employee_report_export.php?employee_id=<?= (int)$employeeReportReady['id'] ?>&amp;format=xlsx">Excel workbook</a>
                            or
                            <a class="alert-link"
                                href="employee_report_export.php?employee_id=<?= (int)$employeeReportReady['id'] ?>&amp;format=csv">CSV file</a>.
                        </div>
                    <?php endif; ?>
                    <form method="POST" class="row g-2 align-items-end">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="employee_report">
                        <div class="col-md-8">
                            <label for="report-employee" class="form-label">Employee</label>
                            <div class="employee-report-picker">
                                <input id="report-employee" type="search" class="form-control"
                                    placeholder="Search employee full name..." autocomplete="off" required>
                                <input type="hidden" name="employee_id" value="<?= $employeeReportReady ? (int)$employeeReportReady['id'] : '' ?>">
                                <div class="employee-report-options" hidden>
                                <?php foreach ($reportEmployees as $employee): ?>
                                    <button type="button" class="employee-report-option"
                                        data-employee-id="<?= (int)$employee['id'] ?>"
                                        data-employee-label="<?= htmlspecialchars(
                                            $employee['name'] . ' — ' . $employee['department_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>">
                                        <?= htmlspecialchars(
                                            $employee['name'] . ' — ' . $employee['department_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </button>
                                <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-outline-primary" type="submit">Prepare employee report</button>
                        </div>
                    </form>
                </section>

                <section aria-labelledby="assistant-actions-heading">
                    <h4 id="assistant-actions-heading">Ticket actions</h4>
                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if ($notice !== ''): ?>
                        <div class="alert alert-success"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                <?php if ($preview): ?>
                    <div class="border rounded p-3 mb-3">
                        <h5>Confirm this action</h5>
                        <p><strong>Action:</strong> <?= htmlspecialchars($actionLabels[$preview['action']], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-1"><strong>Ticket:</strong> #<?= (int)$preview['ticket']['id'] ?> —
                            <?= htmlspecialchars($preview['ticket']['subject'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-1"><strong>From:</strong> <?= htmlspecialchars($preview['ticket']['sender_email'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-1"><strong>Department / status:</strong>
                            <?= htmlspecialchars($preview['ticket']['department_name'], ENT_QUOTES, 'UTF-8') ?> /
                            <?= htmlspecialchars($preview['ticket']['status'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mb-3"><strong>Assigned:</strong>
                            <?= htmlspecialchars($preview['ticket']['assignee_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($preview['action'] === 'pause_notifications'): ?>
                            <div class="alert alert-warning">
                                <?php if ($user['role'] === 'super_admin'): ?>
                                    This pauses employee and manager/HOD notification triggers. It does not close the ticket.
                                <?php else: ?>
                                    This pauses employee notification triggers only. Manager/HOD escalation notifications may still continue.
                                <?php endif; ?>
                            </div>
                        <?php elseif ($preview['action'] === 'send_reminder'): ?>
                            <p><strong>Reminder recipient:</strong>
                                <?= htmlspecialchars($preview['ticket']['assignee_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php elseif ($preview['action'] === 'escalate_admin'): ?>
                            <?php $previewContacts = $preview['contacts']; ?>
                            <p class="mb-1"><strong>Configured HR/Admin recipients:</strong></p>
                            <?php if ($previewContacts): ?>
                                <ul>
                                    <?php foreach ($previewContacts as $contact): ?>
                                        <li><?= htmlspecialchars($contact['name'], ENT_QUOTES, 'UTF-8') ?> —
                                            <?= htmlspecialchars($contact['email'], ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="alert alert-warning">No eligible recipients are configured. Nothing can be sent.</div>
                            <?php endif; ?>
                            <?php if (!$preview['snapshot_matches']): ?>
                                <div class="alert alert-warning">The recipient list changed after the command was interpreted. Cancel and submit the command again to review the updated list.</div>
                            <?php endif; ?>
                            <div class="alert alert-info">This sends a manual alert but does not change the ticket's automatic escalation stage.</div>
                        <?php elseif ($preview['action'] === 'send_reminder' && !$preview['snapshot_matches']): ?>
                            <div class="alert alert-warning">The assigned employee changed after the command was interpreted. Cancel and submit the command again.</div>
                        <?php endif; ?>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <button class="btn btn-danger" name="action" value="confirm"
                                <?= !$preview['snapshot_matches']
                                    || ($preview['action'] === 'escalate_admin' && !$previewContacts)
                                    ? 'disabled'
                                    : '' ?>>
                                Confirm and execute
                            </button>
                        </form>
                        <form method="POST" class="d-inline">
                            <?= csrfField() ?>
                            <button class="btn btn-outline-secondary" name="action" value="cancel">Cancel</button>
                        </form>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="interpret">
                        <label for="assistant-command" class="form-label">Your command</label>
                        <textarea id="assistant-command" name="command" class="form-control mb-2" rows="2" maxlength="240"
                            placeholder="Examples: Ticket 123 send reminder; employee report for Priya Sharma" required></textarea>
                        <button class="btn btn-primary">Interpret command</button>
                    </form>
                <?php endif; ?>

                <div class="small text-muted mt-3">
                    Examples: “Ticket 123 pause notifications”, “Ticket 123 escalate to admin”,
                    “Ticket 123 send reminder”, “employee report for Priya Sharma”.
                    Employee reports and ticket actions are limited to your department unless you are Super Admin.
                </div>
                </section>
            </div>
        </div>
    </div>
</div>

<style>
    .employee-report-picker { position: relative; }
    .employee-report-options {
        position: absolute;
        z-index: 20;
        top: calc(100% + 3px);
        left: 0;
        width: 100%;
        max-height: 280px;
        overflow-y: auto;
        padding: .35rem;
        border: 1px solid #ced4da;
        border-radius: .375rem;
        background: #fff;
        box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .12);
    }
    .employee-report-options:not([hidden]) { display: grid; }
    .employee-report-option {
        padding: .5rem .65rem;
        border: 0;
        border-radius: .25rem;
        background: transparent;
        color: inherit;
        text-align: left;
        white-space: normal;
        overflow-wrap: anywhere;
    }
    .employee-report-option:hover,
    .employee-report-option:focus { background: #f1f4f8; }
</style>
<script>
    (() => {
        const picker = document.querySelector('.employee-report-picker');
        if (!picker) return;

        const search = picker.querySelector('input[type="search"]');
        const selectedId = picker.querySelector('input[type="hidden"]');
        const options = picker.querySelector('.employee-report-options');
        const buttons = [...picker.querySelectorAll('.employee-report-option')];

        const filterOptions = () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visibleCount = 0;
            buttons.forEach((button) => {
                const visible = button.textContent.trim().toLocaleLowerCase().includes(query);
                button.hidden = !visible;
                visibleCount += visible ? 1 : 0;
            });
            options.hidden = visibleCount === 0;
        };

        search.addEventListener('focus', () => {
            options.hidden = false;
            filterOptions();
        });
        search.addEventListener('input', () => {
            selectedId.value = '';
            filterOptions();
        });
        buttons.forEach((button) => {
            button.addEventListener('click', () => {
                search.value = button.dataset.employeeLabel;
                selectedId.value = button.dataset.employeeId;
                options.hidden = true;
            });
        });
        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) options.hidden = true;
        });

        if (selectedId.value) {
            const selectedOption = buttons.find((button) => button.dataset.employeeId === selectedId.value);
            if (selectedOption) search.value = selectedOption.dataset.employeeLabel;
        }
    })();
</script>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
