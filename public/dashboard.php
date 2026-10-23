<?php
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$dashboardStartDate = date('Y-m-01 00:00:00');
$departmentStmt = $pdo->query(
    "SELECT id, name
     FROM departments d
     WHERE " . portalDepartmentCondition('d') . "
     ORDER BY name"
);
$dashboardDepartments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);
$departmentIds = array_map(static fn(array $department): int => (int)$department['id'], $dashboardDepartments);
$selectedEmployeeDepartmentId = filter_var(
    $_GET['employee_department_id'] ?? '',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($selectedEmployeeDepartmentId === false
    || !in_array($selectedEmployeeDepartmentId, $departmentIds, true)) {
    $selectedEmployeeDepartmentId = null;
}

$levelStatsStmt = $pdo->prepare(
    "SELECT d.id AS department_id, d.name AS department_name,
            employee_department.id AS profile_department_id,
            employee_department.name AS profile_department_name,
            u.id AS assignee_id, COALESCE(u.name, 'Unassigned') AS assignee_name,
            COUNT(t.id) AS total_mail_count,
            SUM(CASE WHEN t.status = 'replied' THEN 1 ELSE 0 END) AS replied_count,
            SUM(CASE WHEN t.status <> 'replied' THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 1 THEN 1 ELSE 0 END) AS l1_count,
            SUM(CASE WHEN delivery.l3_sent = 0 AND delivery.l2_sent = 1 THEN 1 ELSE 0 END) AS l2_count,
            SUM(CASE WHEN delivery.l3_sent = 1 THEN 1 ELSE 0 END) AS l3_count,
            SUM(CASE WHEN delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND NOT (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS no_keyword_count,
            SUM(CASE WHEN delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS matched_no_reminder_count,
            SUM(CASE WHEN t.status = 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 1 THEN 1 ELSE 0 END) AS l1_replied,
            SUM(CASE WHEN t.status <> 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 1 THEN 1 ELSE 0 END) AS l1_open,
            SUM(CASE WHEN t.status = 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 1 THEN 1 ELSE 0 END) AS l2_replied,
            SUM(CASE WHEN t.status <> 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 1 THEN 1 ELSE 0 END) AS l2_open,
            SUM(CASE WHEN t.status = 'replied' AND delivery.l3_sent = 1 THEN 1 ELSE 0 END) AS l3_replied,
            SUM(CASE WHEN t.status <> 'replied' AND delivery.l3_sent = 1 THEN 1 ELSE 0 END) AS l3_open,
            SUM(CASE WHEN t.status = 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND NOT (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS no_keyword_replied,
            SUM(CASE WHEN t.status <> 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND NOT (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS no_keyword_open,
            SUM(CASE WHEN t.status = 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS matched_no_reminder_replied,
            SUM(CASE WHEN t.status <> 'replied' AND delivery.l3_sent = 0 AND delivery.l2_sent = 0 AND delivery.l1_sent = 0
                           AND (COALESCE(t.is_important, 0) = 1 OR COALESCE(t.escalation_queued, 0) = 1 OR t.status = 'escalated')
                     THEN 1 ELSE 0 END) AS matched_no_reminder_open
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
     LEFT JOIN departments employee_department ON employee_department.id = u.department_id
     LEFT JOIN (
         SELECT ticket.id AS ticket_id,
                GREATEST(
                    COALESCE(event_delivery.l3_sent, 0),
                    COALESCE(escalation_delivery.l3_sent, 0),
                    CASE WHEN COALESCE(ticket.escalation_level, 0) >= 2 THEN 1 ELSE 0 END
                ) AS l3_sent,
                GREATEST(
                    COALESCE(event_delivery.l2_sent, 0),
                    CASE WHEN COALESCE(ticket.escalation_level, 0) >= 1 THEN 1 ELSE 0 END
                ) AS l2_sent,
                COALESCE(event_delivery.l1_sent, 0) AS l1_sent
         FROM tickets ticket
         LEFT JOIN (
             SELECT ticket_id,
                    MAX(CASE WHEN event_type = 'escalated' AND note LIKE 'Level 3 escalation email sent%' THEN 1 ELSE 0 END) AS l3_sent,
                    MAX(CASE WHEN event_type = 'escalated' AND note LIKE 'Level 2 escalation email sent%' THEN 1 ELSE 0 END) AS l2_sent,
                    MAX(CASE WHEN event_type = 'no_reply_warning' AND note LIKE 'Reminder included in consolidated digest%' THEN 1 ELSE 0 END) AS l1_sent
             FROM timeline_events
             GROUP BY ticket_id
         ) event_delivery ON event_delivery.ticket_id = ticket.id
         LEFT JOIN (
             SELECT ticket_id, MAX(email_sent = 1) AS l3_sent
             FROM escalations
             GROUP BY ticket_id
         ) escalation_delivery ON escalation_delivery.ticket_id = ticket.id
     ) delivery ON delivery.ticket_id = t.id
     WHERE t.received_at >= ?
       AND t.status <> 'closed'
       AND " . portalTicketCondition('t') . "
       AND " . portalDepartmentCondition('d') . "
     GROUP BY d.id, d.name, employee_department.id, employee_department.name, u.id, u.name
     ORDER BY d.name, assignee_name"
);
$levelStatsStmt->execute([$dashboardStartDate]);
$levelStats = $levelStatsStmt->fetchAll();

$employeeInsights = [];
$employeeCountMetrics = [
    'total_mail_count', 'replied_count', 'open_count',
    'l1_count', 'l2_count', 'l3_count', 'no_keyword_count', 'matched_no_reminder_count',
    'l1_replied', 'l1_open', 'l2_replied', 'l2_open', 'l3_replied', 'l3_open',
    'no_keyword_replied', 'no_keyword_open',
    'matched_no_reminder_replied', 'matched_no_reminder_open',
];
foreach ($levelStats as $row) {
    $assigneeId = $row['assignee_id'] === null ? 0 : (int)$row['assignee_id'];
    $employeeKey = $assigneeId > 0
        ? 'employee:' . $assigneeId
        : 'unassigned:' . (int)$row['department_id'];
    if (!isset($employeeInsights[$employeeKey])) {
        $employeeInsights[$employeeKey] = [
            'department_id' => $assigneeId > 0
                ? (int)($row['profile_department_id'] ?? 0)
                : (int)$row['department_id'],
            'department_name' => $assigneeId > 0
                ? ($row['profile_department_name'] ?: 'Unassigned')
                : $row['department_name'],
            'assignee_id' => $assigneeId,
            'assignee_name' => $row['assignee_name'],
        ];
        foreach ($employeeCountMetrics as $metric) {
            $employeeInsights[$employeeKey][$metric] = 0;
        }
    }
    foreach ($employeeCountMetrics as $metric) {
        $employeeInsights[$employeeKey][$metric] += (int)$row[$metric];
    }
}
if ($selectedEmployeeDepartmentId !== null) {
    $employeeInsights = array_filter(
        $employeeInsights,
        static fn(array $employee): bool => (int)$employee['department_id'] === $selectedEmployeeDepartmentId
    );
}
$departmentEmployeeOptions = $employeeInsights;
$selectedEmployeeKey = trim((string)($_GET['employee_key'] ?? ''));
if (!array_key_exists($selectedEmployeeKey, $departmentEmployeeOptions)) {
    $selectedEmployeeKey = '';
}
if ($selectedEmployeeKey !== '') {
    $employeeInsights = [$selectedEmployeeKey => $departmentEmployeeOptions[$selectedEmployeeKey]];
}

$summary = [
    'total' => 0, 'replied' => 0, 'open' => 0,
    'replied_l1' => 0, 'replied_l2' => 0, 'replied_l3' => 0,
    'replied_no_keyword' => 0, 'replied_matched_no_reminder' => 0,
    'open_l1' => 0, 'open_l2' => 0, 'open_l3' => 0,
    'open_no_keyword' => 0, 'open_matched_no_reminder' => 0,
];
$departmentMetrics = [];
foreach ($levelStats as $row) {
    $summary['total'] += (int)$row['total_mail_count'];
    $summary['replied'] += (int)$row['replied_count'];
    $summary['open'] += (int)$row['open_count'];
    $departmentId = (int)$row['department_id'];
    if (!isset($departmentMetrics[$departmentId])) {
        $departmentMetrics[$departmentId] = [
            'department_id' => $departmentId,
            'department_name' => $row['department_name'],
            'total_mail_count' => 0,
            'replied_count' => 0,
            'open_count' => 0,
            'open_keywords_count' => 0,
            'open_no_keyword_count' => 0,
            'open_l1_count' => 0,
            'open_l2_count' => 0,
            'open_l3_count' => 0,
            'open_matched_no_reminder_count' => 0,
        ];
    }
    foreach ([
        'total_mail_count', 'replied_count', 'open_count',
    ] as $metric) {
        $departmentMetrics[$departmentId][$metric] += (int)$row[$metric];
    }
    foreach ([
        'open_l1_count' => 'l1_open',
        'open_l2_count' => 'l2_open',
        'open_l3_count' => 'l3_open',
        'open_matched_no_reminder_count' => 'matched_no_reminder_open',
    ] as $metric => $column) {
        $departmentMetrics[$departmentId][$metric] += (int)$row[$column];
    }
    $departmentMetrics[$departmentId]['open_keywords_count'] += (int)$row['l1_open']
        + (int)$row['l2_open']
        + (int)$row['l3_open']
        + (int)$row['matched_no_reminder_open'];
    $departmentMetrics[$departmentId]['open_no_keyword_count'] += (int)$row['no_keyword_open'];
    foreach ([
        'replied_l1' => 'l1_replied', 'replied_l2' => 'l2_replied', 'replied_l3' => 'l3_replied',
        'replied_no_keyword' => 'no_keyword_replied', 'replied_matched_no_reminder' => 'matched_no_reminder_replied',
        'open_l1' => 'l1_open', 'open_l2' => 'l2_open', 'open_l3' => 'l3_open',
        'open_no_keyword' => 'no_keyword_open', 'open_matched_no_reminder' => 'matched_no_reminder_open',
    ] as $summaryKey => $column) {
        $summary[$summaryKey] += (int)$row[$column];
    }
}
foreach ($departmentMetrics as $department) {
    if ($department['open_count'] !== $department['open_keywords_count'] + $department['open_no_keyword_count']) {
        throw new LogicException('Department open email categories do not reconcile with the open total.');
    }
    if ($department['total_mail_count'] !== $department['replied_count'] + $department['open_count']) {
        throw new LogicException('Department status totals do not reconcile with the department total.');
    }
}
$repliedBreakdownTotal = $summary['replied_l1'] + $summary['replied_l2'] + $summary['replied_l3']
    + $summary['replied_no_keyword'] + $summary['replied_matched_no_reminder'];
$openBreakdownTotal = $summary['open_l1'] + $summary['open_l2'] + $summary['open_l3']
    + $summary['open_no_keyword'] + $summary['open_matched_no_reminder'];
if ($summary['total'] !== $summary['replied'] + $summary['open']
    || $repliedBreakdownTotal !== $summary['replied']
    || $openBreakdownTotal !== $summary['open']) {
    throw new LogicException('Dashboard email categories do not reconcile with the status totals.');
}
$emailListUrl = static function (string $category, ?array $employee = null): string {
    $filters = [
        'dashboard_month' => '1',
        'dashboard_category' => $category,
    ];
    if ($employee !== null) {
        $filters['assigned_to'] = (string)($employee['assignee_id'] ?? 0);
        if ((int)($employee['assignee_id'] ?? 0) === 0) {
            $filters['department'] = (string)$employee['department_id'];
        }
    }

    return htmlspecialchars('all_emails.php?' . http_build_query($filters), ENT_QUOTES, 'UTF-8');
};
$departmentEmailListUrl = static function (string $category, int $departmentId): string {
    $filters = [
        'dashboard_month' => '1',
        'dashboard_category' => $category,
        'department' => (string)$departmentId,
    ];
    return htmlspecialchars('all_emails.php?' . http_build_query($filters), ENT_QUOTES, 'UTF-8');
};
?>

<div class="dashboard-page">
<div class="d-flex justify-content-between align-items-center mb-3 dashboard-page-header">
    <div>
        <h3 class="mb-1">Email overview</h3>
        <div class="text-muted small">Month to date · from <?= date('d M Y', strtotime($dashboardStartDate)) ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="department_dashboard_export.php" class="btn btn-success btn-sm">Download Excel Sheet (Yesterday)</a>
        <a href="report.php" target="_blank" class="btn btn-outline-secondary btn-sm">Status report (PDF)</a>
    </div>
</div>

<section class="dashboard-pending-overview mb-4" aria-labelledby="pending-overview-title">
    <div class="dashboard-section-heading">
        <div>
            <h4 id="pending-overview-title">At a glance</h4>
            <p>Closed emails are excluded. Each email appears in one status and one follow-up category only.</p>
        </div>
    </div>
    <div class="dashboard-director-totals">
        <a href="<?= $emailListUrl('all') ?>" class="dashboard-director-total dashboard-total dashboard-metric-link">
            <span class="dashboard-total-number"><?= $summary['total'] ?></span>
            <span class="dashboard-total-label">Total emails</span>
            <span class="dashboard-total-help">Received this month · excluding closed</span>
        </a>
        <a href="<?= $emailListUrl('replied') ?>" class="dashboard-director-total dashboard-replied dashboard-metric-link">
            <span class="dashboard-total-number"><?= $summary['replied'] ?></span>
            <span class="dashboard-total-label">Replied</span>
            <span class="dashboard-total-help">Marked as replied</span>
        </a>
        <a href="<?= $emailListUrl('open') ?>" class="dashboard-director-total dashboard-open dashboard-metric-link">
            <span class="dashboard-total-number"><?= $summary['open'] ?></span>
            <span class="dashboard-total-label">Open</span>
            <span class="dashboard-total-help">Still needs attention</span>
        </a>
    </div>
</section>

<section class="dashboard-reconciliation mb-4" aria-labelledby="reconciliation-title">
    <div class="dashboard-section-heading">
        <div>
            <h4 id="reconciliation-title">Where are the emails?</h4>
            <p>Each row adds up to the Replied and Open totals above. L1, L2 and L3 show the highest level reached, so an email is never counted in two levels.</p>
        </div>
    </div>
    <div class="dashboard-breakdown-columns">
        <?php foreach ([
            ['title' => 'Why these ' . $summary['replied'] . ' emails are replied', 'total' => $summary['replied'], 'suffix' => 'replied'],
            ['title' => 'Why these ' . $summary['open'] . ' emails are open', 'total' => $summary['open'], 'suffix' => 'open'],
        ] as $statusGroup): ?>
            <div class="dashboard-breakdown-group">
                <h5>
                    <span><?= htmlspecialchars($statusGroup['title'], ENT_QUOTES, 'UTF-8') ?></span>
                    <a href="<?= $emailListUrl($statusGroup['suffix']) ?>" class="dashboard-breakdown-total"><?= (int)$statusGroup['total'] ?></a>
                </h5>
                <?php if ($statusGroup['suffix'] === 'open'): ?>
                    <div class="dashboard-open-explainer">
                        <a href="<?= $emailListUrl('open_escalated') ?>" class="dashboard-open-explainer-item is-escalated">
                            <span>Escalation sent · L1/L2/L3</span>
                            <strong><?= $summary['open_l1'] + $summary['open_l2'] + $summary['open_l3'] ?></strong>
                        </a>
                        <a href="<?= $emailListUrl('open_matched_no_reminder') ?>" class="dashboard-open-explainer-item is-follow-up">
                            <span>Keyword matched · reminder not sent</span>
                            <strong><?= $summary['open_matched_no_reminder'] ?></strong>
                        </a>
                        <a href="<?= $emailListUrl('open_no_keyword') ?>" class="dashboard-open-explainer-item is-not-escalated">
                            <span>No keywords · not escalated</span>
                            <strong><?= $summary['open_no_keyword'] ?></strong>
                        </a>
                    </div>
                <?php endif; ?>
                <div class="dashboard-breakdown-grid">
                    <?php foreach ([
                        ['L1 · Employee reminder sent', 'l1'],
                        ['L2 · Manager / HOD contacted', 'l2'],
                        ['L3 · HR / Admin contacted', 'l3'],
                        ['No escalation keywords matched', 'no_keyword'],
                        ['Keyword matched · no reminder sent', 'matched_no_reminder'],
                    ] as [$label, $category]): ?>
                        <?php $metricKey = $statusGroup['suffix'] . '_' . $category; ?>
                        <a href="<?= $emailListUrl($metricKey) ?>" class="dashboard-breakdown-item dashboard-metric-link">
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <strong><?= (int)$summary[$metricKey] ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="dashboard-department-metrics mb-4" aria-labelledby="department-metrics-title">
    <div class="dashboard-section-heading">
        <div>
            <h4 id="department-metrics-title">Department metrics</h4>
            <p>Month-to-date emails are split into Replied and mutually exclusive open follow-up categories.</p>
        </div>
    </div>
    <div class="dashboard-department-grid">
        <?php foreach ($departmentMetrics as $department): ?>
            <article class="dashboard-department-card">
                <h5><?= htmlspecialchars($department['department_name'], ENT_QUOTES, 'UTF-8') ?></h5>
                <div class="dashboard-department-metric-grid">
                    <?php foreach ([
                        ['Total', 'total_mail_count', 'all', 'is-total'],
                        ['Replied', 'replied_count', 'replied', 'is-replied'],
                        ['L1', 'open_l1_count', 'open_l1', 'is-l1'],
                        ['L2', 'open_l2_count', 'open_l2', 'is-l2'],
                        ['L3', 'open_l3_count', 'open_l3', 'is-l3'],
                        ['No keywords', 'open_no_keyword_count', 'open_no_keyword', 'is-no-keyword'],
                        ['No reminder', 'open_matched_no_reminder_count', 'open_matched_no_reminder', 'is-no-reminder'],
                    ] as [$label, $metric, $category, $colorClass]): ?>
                        <a href="<?= $departmentEmailListUrl($category, (int)$department['department_id']) ?>" class="dashboard-department-metric dashboard-metric-link <?= $colorClass ?>">
                            <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                            <strong><?= (int)$department[$metric] ?></strong>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (empty($departmentMetrics)): ?>
            <p class="text-muted mb-0">No emails found for this period.</p>
        <?php endif; ?>
    </div>
</section>

<section id="employee-escalations" class="employee-insights mb-4" aria-labelledby="employee-insights-title">
    <div class="employee-insights-header">
        <div>
            <span class="employee-insights-eyebrow">Team insights</span>
            <h4 id="employee-insights-title">Email breakdown by employee</h4>
            <p>Each employee’s total is split into replied and open emails, then grouped by the highest escalation level.</p>
        </div>
        <form method="GET" action="dashboard.php" class="employee-search-form employee-insights-search">
            <label class="visually-hidden" for="employee-department-filter">Filter employees by department</label>
            <select
                id="employee-department-filter"
                name="employee_department_id"
                class="form-select form-select-sm"
                onchange="sessionStorage.setItem('dashboardEmployeeFilterScrollY', String(window.scrollY)); this.form.submit()"
            >
                <option value="">All departments</option>
                <?php foreach ($dashboardDepartments as $department): ?>
                    <option
                        value="<?= (int)$department['id'] ?>"
                        <?= $selectedEmployeeDepartmentId === (int)$department['id'] ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="employee-filter">Filter by employee</label>
            <select
                id="employee-filter"
                name="employee_key"
                class="form-select form-select-sm"
                onchange="sessionStorage.setItem('dashboardEmployeeFilterScrollY', String(window.scrollY)); this.form.submit()"
            >
                <option value="">All employees</option>
                <?php foreach ($departmentEmployeeOptions as $employeeKey => $employee): ?>
                    <option
                        value="<?= htmlspecialchars($employeeKey, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $selectedEmployeeKey === $employeeKey ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($employee['assignee_name'] . ' — ' . $employee['department_name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selectedEmployeeDepartmentId !== null || $selectedEmployeeKey !== ''): ?>
                <a href="dashboard.php#employee-escalations" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="employee-insights-list">
        <?php foreach ($employeeInsights as $row): ?>
            <?php
            $employeeInitials = '';
            foreach (preg_split('/\s+/', trim($row['assignee_name'])) as $namePart) {
                if ($namePart !== '') {
                    $employeeInitials .= mb_substr($namePart, 0, 1);
                }
                if (mb_strlen($employeeInitials) >= 2) {
                    break;
                }
            }
            ?>
            <article class="employee-insight-card">
                <header class="employee-insight-top">
                    <div class="employee-insight-person">
                        <span class="employee-insight-avatar" aria-hidden="true"><?= htmlspecialchars(mb_strtoupper($employeeInitials), ENT_QUOTES, 'UTF-8') ?></span>
                        <div class="employee-insight-identity">
                            <a href="<?= $emailListUrl('all', $row) ?>" class="employee-insight-name">
                                <?= htmlspecialchars($row['assignee_name']) ?>
                            </a>
                            <a href="<?= $emailListUrl('all', $row) ?>" class="employee-insight-department">
                                <?= htmlspecialchars($row['department_name']) ?>
                            </a>
                        </div>
                    </div>
                    <a href="<?= $emailListUrl('all', $row) ?>" class="employee-insight-total">
                        <strong><?= (int)$row['total_mail_count'] ?></strong>
                        <span>Total emails</span>
                    </a>
                </header>
                <div class="employee-insight-status">
                    <a href="<?= $emailListUrl('replied', $row) ?>" class="employee-insight-status-item is-replied">
                        <span>Replied</span>
                        <strong><?= (int)$row['replied_count'] ?></strong>
                    </a>
                    <a href="<?= $emailListUrl('open_l1', $row) ?>" class="employee-insight-status-item is-open">
                        <span>L1</span>
                        <strong><?= (int)$row['l1_open'] ?></strong>
                    </a>
                    <a href="<?= $emailListUrl('open_l2', $row) ?>" class="employee-insight-status-item is-open">
                        <span>L2</span>
                        <strong><?= (int)$row['l2_open'] ?></strong>
                    </a>
                    <a href="<?= $emailListUrl('open_l3', $row) ?>" class="employee-insight-status-item is-open">
                        <span>L3</span>
                        <strong><?= (int)$row['l3_open'] ?></strong>
                    </a>
                </div>
                <div class="employee-insight-categories">
                    <?php foreach ([
                        ['l1_count', 'l1_replied', 'l1_open', 'L1', 'Employee reminder sent', 'l1', 'replied_l1', 'open_l1'],
                        ['l2_count', 'l2_replied', 'l2_open', 'L2', 'Manager / HOD contacted', 'l2', 'replied_l2', 'open_l2'],
                        ['l3_count', 'l3_replied', 'l3_open', 'L3', 'HR / Admin contacted', 'l3', 'replied_l3', 'open_l3'],
                        ['no_keyword_count', 'no_keyword_replied', 'no_keyword_open', 'No keywords', 'No escalation keyword matched', 'no_keyword', 'replied_no_keyword', 'open_no_keyword'],
                        ['matched_no_reminder_count', 'matched_no_reminder_replied', 'matched_no_reminder_open', 'No reminder', 'Keyword matched · reminder not sent', 'matched_no_reminder', 'replied_matched_no_reminder', 'open_matched_no_reminder'],
                    ] as [$totalColumn, $repliedColumn, $openColumn, $categoryTitle, $categoryHelp, $totalCategory, $repliedCategory, $openCategory]): ?>
                        <div class="employee-insight-category">
                            <a href="<?= $emailListUrl($totalCategory, $row) ?>" class="employee-insight-category-main">
                                <span><?= htmlspecialchars($categoryTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                <strong><?= (int)$row[$totalColumn] ?></strong>
                            </a>
                            <span class="employee-insight-category-help"><?= htmlspecialchars($categoryHelp, ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="employee-insight-category-status">
                            <a href="<?= $emailListUrl($repliedCategory, $row) ?>" class="is-replied">Replied <?= (int)$row[$repliedColumn] ?></a>
                            <a href="<?= $emailListUrl($openCategory, $row) ?>" class="is-open"><?= in_array($totalCategory, ['l1', 'l2', 'l3'], true) ? strtoupper($totalCategory) : 'Open' ?> <?= (int)$row[$openColumn] ?></a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if (empty($employeeInsights)): ?>
            <div class="employee-insights-empty">
                No employees found for the selected department or employee.
            </div>
        <?php endif; ?>
    </div>
    <div class="employee-delivery-note">
        <strong>How to read:</strong> Total = Replied + Open. Each email is assigned to exactly one category. L1/L2/L3 indicate the highest confirmed reminder level; “No keywords” means no escalation keyword was detected.
    </div>
</section>

 </div>
<script>
    (() => {
        const savedScrollY = sessionStorage.getItem('dashboardEmployeeFilterScrollY');
        if (savedScrollY === null) return;

        sessionStorage.removeItem('dashboardEmployeeFilterScrollY');
        const scrollY = Number(savedScrollY);
        if (Number.isFinite(scrollY)) {
            requestAnimationFrame(() => window.scrollTo(0, scrollY));
        }
    })();
</script>
<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
