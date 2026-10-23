<?php

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$deptFilter   = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$levelFilter = isset($_GET['level']) ? (int)$_GET['level'] : 0;
$assigneeFilter = isset($_GET['assigned_to']) ? (int)$_GET['assigned_to'] : null;
$workloadFilter = ($_GET['workload'] ?? '') === '1';
$openOnlyFilter = ($_GET['open_only'] ?? '') === '1';
$noKeywordMatchFilter = ($_GET['no_keyword_match'] ?? '') === '1';
$escalationRepliesFilter = ($_GET['escalation_replies'] ?? '') === '1';
$dashboardMonthFilter = ($_GET['dashboard_month'] ?? '') === '1';
$dashboardCategory = $_GET['dashboard_category'] ?? '';
$dashboardCategories = [
    'all',
    'replied',
    'open',
    'l1',
    'l2',
    'l3',
    'no_keyword',
    'matched_no_reminder',
    'replied_l1',
    'replied_l2',
    'replied_l3',
    'replied_no_keyword',
    'replied_matched_no_reminder',
    'open_l1',
    'open_l2',
    'open_l3',
    'open_escalated',
    'open_keywords',
    'open_no_keyword',
    'open_matched_no_reminder',
];
if (!in_array($dashboardCategory, $dashboardCategories, true)) {
    $dashboardCategory = '';
}
$dashboardEmployeeSearch = trim($_GET['dashboard_employee_search'] ?? '');
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
$pendingViewFilter = $dashboardMonthFilter || $levelFilterIsValid || $workloadFilter || $openOnlyFilter || $noKeywordMatchFilter;
$retentionCutoff = $pendingViewFilter
    ? date('Y-m-01 00:00:00')
    : (new DateTimeImmutable('now'))->modify('-30 days')->format('Y-m-d H:i:s');
$sql .= $pendingViewFilter
    ? " AND t.received_at >= ?"
    : " AND (t.received_at >= ? OR t.status IN ('open', 'escalated'))";
$params[] = $retentionCutoff;

if ($deptFilter !== '') {
    $sql .= " AND t.department_id = ?";
    $params[] = (int)$deptFilter;
}

if ($dashboardMonthFilter) {
    $sql .= " AND t.received_at >= ? AND t.status <> 'closed'";
    $params[] = date('Y-m-01 00:00:00');
}

if ($assigneeFilter !== null) {
    $sql .= $assigneeFilter > 0 ? " AND t.assigned_to = ?" : " AND t.assigned_to IS NULL";
    if ($assigneeFilter > 0) {
        $params[] = $assigneeFilter;
    }
}

if ($dashboardEmployeeSearch !== '') {
    $sql .= " AND (u.name LIKE ? OR d.name LIKE ?)";
    $dashboardEmployeeSearchLike = '%' . $dashboardEmployeeSearch . '%';
    $params[] = $dashboardEmployeeSearchLike;
    $params[] = $dashboardEmployeeSearchLike;
}

if ($dashboardMonthFilter && $dashboardCategory !== '') {
    $l3Sent = "(
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
        OR COALESCE(t.escalation_level, 0) >= 2
    )";
    $l2Sent = "(
        EXISTS (
            SELECT 1 FROM timeline_events ev
            WHERE ev.ticket_id = t.id
              AND ev.event_type = 'escalated'
              AND ev.note LIKE 'Level 2 escalation email sent%'
        )
        OR COALESCE(t.escalation_level, 0) >= 1
    )";
    $l1Sent = "EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'no_reply_warning'
          AND ev.note LIKE 'Reminder included in consolidated digest%'
    )";
    $keywordMatched = "(COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')";
    $dashboardCategoryConditions = [
        'all' => '1 = 1',
        'replied' => "t.status = 'replied'",
        'open' => "t.status NOT IN ('replied', 'closed')",
        'l1' => "NOT $l3Sent AND NOT $l2Sent AND $l1Sent",
        'l2' => "NOT $l3Sent AND $l2Sent",
        'l3' => $l3Sent,
        'no_keyword' => "NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND NOT $keywordMatched",
        'matched_no_reminder' => "NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND $keywordMatched",
        'replied_l1' => "t.status = 'replied' AND NOT $l3Sent AND NOT $l2Sent AND $l1Sent",
        'replied_l2' => "t.status = 'replied' AND NOT $l3Sent AND $l2Sent",
        'replied_l3' => "t.status = 'replied' AND $l3Sent",
        'replied_no_keyword' => "t.status = 'replied' AND NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND NOT $keywordMatched",
        'replied_matched_no_reminder' => "t.status = 'replied' AND NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND $keywordMatched",
        'open_l1' => "t.status <> 'replied' AND NOT $l3Sent AND NOT $l2Sent AND $l1Sent",
        'open_l2' => "t.status <> 'replied' AND NOT $l3Sent AND $l2Sent",
        'open_l3' => "t.status <> 'replied' AND $l3Sent",
        'open_escalated' => "t.status <> 'replied' AND ($l3Sent OR $l2Sent OR $l1Sent)",
        'open_keywords' => "t.status NOT IN ('replied', 'closed') AND ($l3Sent OR $l2Sent OR $l1Sent OR $keywordMatched)",
        'open_no_keyword' => "t.status <> 'replied' AND NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND NOT $keywordMatched",
        'open_matched_no_reminder' => "t.status <> 'replied' AND NOT $l3Sent AND NOT $l2Sent AND NOT $l1Sent AND $keywordMatched",
    ];
    $sql .= ' AND ' . $dashboardCategoryConditions[$dashboardCategory];
}

if ($levelFilterIsValid) {
    $sql .= " AND (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')";
    $sql .= $levelFilter === 3 ? " AND t.escalation_level >= 2" : " AND t.escalation_level = ?";
    $sql .= " AND t.status NOT IN ('replied', 'closed')";
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

if ($openOnlyFilter) {
    $sql .= " AND t.status NOT IN ('replied', 'closed')";
}

if ($escalationRepliesFilter) {
    $sql .= " AND (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')
              AND t.status = 'replied'";
}

if ($noKeywordMatchFilter) {
    $sql .= " AND t.status = 'open'
              AND NOT (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')";
}

if ($notificationFilter === 'reminder') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'no_reply_warning'
          AND ev.note LIKE 'Reminder included in consolidated digest%'
    )
    AND NOT EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'escalated'
          AND ev.note LIKE 'Level 2 escalation email sent%'
    )
    AND NOT EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'escalated'
          AND ev.note LIKE 'Level 3 escalation email sent%'
    )
    AND NOT EXISTS (
        SELECT 1 FROM escalations es
        WHERE es.ticket_id = t.id
          AND es.email_sent = 1
    )";
} elseif ($notificationFilter === 'level2') {
    $sql .= " AND EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'escalated'
          AND ev.note LIKE 'Level 2 escalation email sent%'
    )
    AND NOT EXISTS (
        SELECT 1 FROM timeline_events ev
        WHERE ev.ticket_id = t.id
          AND ev.event_type = 'escalated'
          AND ev.note LIKE 'Level 3 escalation email sent%'
    )
    AND NOT EXISTS (
        SELECT 1 FROM escalations es
        WHERE es.ticket_id = t.id
          AND es.email_sent = 1
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
    if ($statusFilter === 'escalated') {
        $sql .= " AND (
            t.status = 'escalated'
            OR (t.escalation_level >= 2 AND t.status NOT IN ('replied', 'closed'))
        )";
    } else {
        $sql .= " AND t.status = ?";
        $params[] = $statusFilter;
    }
} elseif (!$dashboardMonthFilter) {
    $sql .= " AND (t.status <> 'closed' OR t.escalation_level >= 2)";
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
$portalDepartmentIds = portalDepartmentIds();
if ($portalDepartmentIds !== null) {
    $departments = array_values(array_filter(
        $departments,
        static fn($department) => in_array((int)$department['id'], $portalDepartmentIds, true)
    ));
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">
            <?php if ($dashboardMonthFilter && $assigneeFilter !== null): ?>
                <?= htmlspecialchars($tickets[0]['assignee_name'] ?? ($assigneeFilter > 0 ? 'Employee' : 'Unassigned')) ?>
                — <?= htmlspecialchars(ucwords(str_replace('_', ' ', $dashboardCategory))) ?>
            <?php elseif ($dashboardMonthFilter): ?>
                <?= htmlspecialchars(ucwords(str_replace('_', ' ', $dashboardCategory))) ?> Emails
            <?php elseif (($workloadFilter || $noKeywordMatchFilter) && $assigneeFilter !== null): ?>
                <?= htmlspecialchars($tickets[0]['assignee_name'] ?? ($assigneeFilter > 0 ? 'Employee' : 'Unassigned')) ?>
                — <?= $noKeywordMatchFilter
                    ? 'No Keyword Match'
                    : ($escalationRepliesFilter
                        ? 'Escalated Replies'
                        : ($notificationFilter !== '' ? htmlspecialchars($notificationLabels[$notificationFilter]) : 'Email Summary')) ?>
            <?php elseif ($noKeywordMatchFilter): ?>
                No Keyword Match Emails
            <?php else: ?>
                All Emails
            <?php endif; ?>
        </h3>
        <div class="text-muted small">
            <?= count($tickets) ?> ticket(s) found · includes normal and keyword-matched emails
            <?php if (!$pendingViewFilter): ?> · last 30 days<?php endif; ?>
            <?php if ($dashboardMonthFilter): ?> · received this month · closed emails excluded<?php endif; ?>
            <?php if ($noKeywordMatchFilter): ?> · open emails with no escalation keyword match<?php endif; ?>
            <?php if ($levelFilterIsValid): ?> · Pending Level <?= $levelFilter ?><?php endif; ?>
            <?php if ($workloadFilter && $notificationFilter === '' && !$escalationRepliesFilter): ?> · Open, escalated and replied emails<?php endif; ?>
            <?php if ($escalationRepliesFilter): ?> · Replied escalations only<?php endif; ?>
            <?php if ($notificationFilter !== ''): ?> · Confirmed delivery only<?php endif; ?>
        </div>
    </div>
</div>

<?php if ($workloadFilter && $notificationFilter === '' && !$escalationRepliesFilter): ?>
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
    <?php if ($dashboardMonthFilter): ?>
        <input type="hidden" name="dashboard_month" value="1">
        <input type="hidden" name="dashboard_category" value="<?= htmlspecialchars($dashboardCategory, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($dashboardEmployeeSearch !== ''): ?><input type="hidden" name="dashboard_employee_search" value="<?= htmlspecialchars($dashboardEmployeeSearch, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <?php if ($assigneeFilter !== null): ?><input type="hidden" name="assigned_to" value="<?= $assigneeFilter ?>"><?php endif; ?>
    <?php endif; ?>
    <?php if ($workloadFilter): ?>
        <input type="hidden" name="workload" value="1">
        <?php if ($assigneeFilter !== null): ?><input type="hidden" name="assigned_to" value="<?= $assigneeFilter ?>"><?php endif; ?>
        <?php if ($notificationFilter !== ''): ?><input type="hidden" name="notification" value="<?= htmlspecialchars($notificationFilter, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
        <?php if ($escalationRepliesFilter): ?><input type="hidden" name="escalation_replies" value="1"><?php endif; ?>
    <?php endif; ?>
    <?php if ($openOnlyFilter): ?>
        <input type="hidden" name="open_only" value="1">
        <?php if (!$workloadFilter && $assigneeFilter !== null): ?><input type="hidden" name="assigned_to" value="<?= $assigneeFilter ?>"><?php endif; ?>
    <?php endif; ?>
    <?php if ($noKeywordMatchFilter): ?>
        <input type="hidden" name="no_keyword_match" value="1">
        <?php if (!$workloadFilter && $assigneeFilter !== null): ?><input type="hidden" name="assigned_to" value="<?= $assigneeFilter ?>"><?php endif; ?>
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
