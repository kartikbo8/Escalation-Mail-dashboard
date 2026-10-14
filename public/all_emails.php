<?php

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$deptFilter   = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$levelFilter = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$assigneeFilter = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
$workloadFilter = ($_GET['workload'] ?? '') === '1';
$notificationFilter = $_GET['notification'] ?? '';
$notificationLabels = [
    'reminder' => 'L1 reminder sent',
    'level2' => 'Level 2 escalation email sent',
    'level3' => 'Level 3 escalation email sent',
];
if (!isset($notificationLabels[$notificationFilter])) {
    $notificationFilter = '';
}
$searchFilter = trim($_GET['search'] ?? '');
$message = '';
$levelFilterIsValid = $levelFilter >= 1 && $levelFilter <= 3;

$sql = "SELECT
            t.*,
            d.name AS department_name,
            u.name AS assignee_name
        FROM tickets t
        LEFT JOIN departments d
            ON d.id = t.department_id
        LEFT JOIN users u
            ON u.id = t.assigned_to
        WHERE " . portalTicketCondition('t');

$params = [];
$retentionCutoff = ($levelFilterIsValid || $workloadFilter)
    ? date('Y') . '-09-01 00:00:00'
    : (new DateTimeImmutable('now'))->modify('-30 days')->format('Y-m-d H:i:s');
$sql .= ($levelFilterIsValid || $workloadFilter)
    ? " AND t.received_at >= ?"
    : " AND (t.received_at >= ? OR t.status IN ('open', 'escalated'))";
$params[] = $retentionCutoff;

if ($deptFilter !== '') {
    $sql .= " AND t.department_id = ?";
    $params[] = (int)$deptFilter;
}

if ($assigneeFilter !== null) {
    $sql .= $assigneeFilter > 0 ? " AND t.assigned_to = ?" : " AND t.assigned_to IS NULL";
    if ($assigneeFilter > 0) {
        $params[] = $assigneeFilter;
    }
}

if ($levelFilterIsValid) {
    $sql .= " AND (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')";
    $sql .= $levelFilter === 3 ? " AND t.escalation_level >= 2" : " AND t.escalation_level = ?";
    if ($levelFilter < 3) {
        $params[] = $levelFilter - 1;
    }
}

if ($workloadFilter) {
    $sql .= " AND (
        t.status = 'open'
        OR t.status = 'escalated'
        OR t.escalation_level > 0
        OR t.replied_at IS NOT NULL
        OR t.status = 'replied'
    )";
}

if ($notificationFilter === 'reminder') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'no_reply_warning'
          AND ev.note LIKE 'Reminder included in consolidated digest%'
    )";
} elseif ($notificationFilter === 'level2') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'escalated'
          AND ev.note LIKE 'Level 2 escalation email sent%'
    )";
} elseif ($notificationFilter === 'level3') {
    $sql .= " AND (
        EXISTS (
            SELECT 1 FROM timeline_events ev
            WHERE ev.ticket_id = t.id
              AND ev.event_type = 'escalated'
              AND ev.note LIKE 'Level 3 escalation email sent%'
        )
        OR EXISTS (
            SELECT 1 FROM escalations es
            WHERE es.ticket_id = t.id
              AND es.email_sent = 1
        )
    )";
}

if ($statusFilter !== '') {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
}

if ($searchFilter !== '') {
    $sql .= " AND (
        t.subject LIKE ?
        OR t.sender_email LIKE ?
        OR t.sender_name LIKE ?
    )";
    $searchTerm = '%' . $searchFilter . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY t.received_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$workloadCounts = ['open' => 0, 'escalated' => 0, 'replied' => 0];
if ($workloadFilter) {
    foreach ($tickets as $ticket) {
        if ($ticket['status'] === 'open') {
            $workloadCounts['open']++;
        }
        if ($ticket['status'] === 'escalated' || (int)$ticket['escalation_level'] > 0) {
            $workloadCounts['escalated']++;
        }
        if (!empty($ticket['replied_at']) || $ticket['status'] === 'replied') {
            $workloadCounts['replied']++;
        }
    }
}

$departments = getDepartments();
if (portalDepartmentId() !== null) {
    $departments = array_values(array_filter($departments, static fn($department) => (int)$department['id'] === portalDepartmentId()));
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">
            <?php if ($workloadFilter && $assigneeFilter !== null): ?>
                <?= htmlspecialchars($tickets[0]['assignee_name'] ?? ($assigneeFilter > 0 ? 'Employee' : 'Unassigned')) ?>
                — <?= $notificationFilter !== '' ? htmlspecialchars($notificationLabels[$notificationFilter]) : 'Email Summary' ?>
            <?php else: ?>
                All Emails
            <?php endif; ?>
        </h3>
        <div class="text-muted small">
            <?= count($tickets) ?> ticket(s) found · includes normal and keyword-matched emails
            <?php if (!$levelFilterIsValid && !$workloadFilter): ?> · last 30 days<?php endif; ?>
            <?php if ($levelFilterIsValid): ?> · Level <?= $levelFilter ?><?php endif; ?>
            <?php if ($workloadFilter && $notificationFilter === ''): ?> · Open, escalated and replied emails<?php endif; ?>
            <?php if ($notificationFilter !== ''): ?> · Confirmed delivery only<?php endif; ?>
        </div>
    </div>
</div>

<?php if ($workloadFilter && $notificationFilter === ''): ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            'open' => ['Open', 'var(--st-open)'],
            'escalated' => ['Escalated', 'var(--st-escalated)'],
            'replied' => ['Replies', 'var(--st-replied)'],
        ] as $status => [$label, $color]): ?>
            <div class="col-md-4">
                <div class="stat-card" style="--accent: <?= $color ?>;">
                    <div class="num"><?= $workloadCounts[$status] ?></div>
                    <div class="label"><?= $label ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="GET" class="row g-2 mb-3">
    <?php if ($workloadFilter): ?>
        <input type="hidden" name="workload" value="1">
        <?php if ($assigneeFilter !== null): ?><input type="hidden" name="assigned_to" value="<?= $assigneeFilter ?>"><?php endif; ?>
        <?php if ($notificationFilter !== ''): ?><input type="hidden" name="notification" value="<?= htmlspecialchars($notificationFilter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
    <?php endif; ?>
    <div class="col-md-2">
        <select name="department" class="form-select form-select-sm">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= (string)$deptFilter === (string)$d['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="open" <?= $statusFilter === 'open' ? 'selected' : '' ?>>Open</option>
            <option value="replied" <?= $statusFilter === 'replied' ? 'selected' : '' ?>>Replied</option>
            <option value="closed" <?= $statusFilter === 'closed' ? 'selected' : '' ?>>Closed</option>
            <option value="escalated" <?= $statusFilter === 'escalated' ? 'selected' : '' ?>>Escalated</option>
        </select>
    </div>
    <div class="col-md-4">
        <input type="search" name="search" class="form-control form-control-sm" placeholder="Search subject / sender" value="<?= htmlspecialchars($searchFilter) ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
    </div>
</form>

<div class="table-responsive">
    <table class="table table-hover align-middle all-emails-table">
        <colgroup>
            <col style="width: 38%;">
            <col style="width: 23%;">
            <col style="width: 13%;">
            <col style="width: 10%;">
            <col style="width: 13%;">
            <col style="width: 165px;">
        </colgroup>
        <thead class="table-light">
            <tr>
                <th>Subject</th>
                <th>From</th>
                <th>Dept</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Received</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tickets)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No emails found.</td></tr>
            <?php endif; ?>

            <?php foreach ($tickets as $t): ?>
                <tr>
                    <td>
                        <a href="email_detail.php?id=<?= (int)$t['id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($t['subject'] ?: '(No subject)') ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($t['sender_email'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($t['department_name'] ?? '-') ?></td>
                    <td><?= statusBadge($t['status']) ?></td>
                    <td><?= priorityBadge($t['priority_tag'] ?? 'normal') ?></td>
                    <td><?= !empty($t['received_at'])
                        ? htmlspecialchars(date('d M Y, h:i A', strtotime($t['received_at'])))
                        : '-' ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
