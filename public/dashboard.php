<?php
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();
$dashboardStartDate = date('Y') . '-09-01 00:00:00';
$employeeSearch = trim($_GET['employee_search'] ?? '');

// Summary counts by status
$statusStmt = $pdo->prepare(
        "SELECT status, COUNT(*) as total FROM tickets t
         WHERE received_at >= ?
             AND " . portalTicketCondition() . "
             AND (is_important = 1 OR escalation_queued = 1 OR status = 'escalated')
         GROUP BY status"
);
$statusStmt->execute([$dashboardStartDate]);
$statusCounts = $statusStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$statuses = ['open', 'replied', 'closed', 'escalated'];
foreach ($statuses as $s) {
    if (!isset($statusCounts[$s])) $statusCounts[$s] = 0;
}
$totalTickets = array_sum($statusCounts);

// SLA Health Score: % of all-time tickets that were replied/closed WITHOUT
// ever reaching final escalation (Level 3). A simple, honest compliance signal.
$healthyCount = $statusCounts['replied'] + $statusCounts['closed'];
$slaHealth = $totalTickets > 0 ? round(($healthyCount / $totalTickets) * 100) : 100;
$healthColor = $slaHealth >= 85 ? '#5FD68A' : ($slaHealth >= 60 ? '#F2A93B' : '#F26B6B');

// Per-department escalation summary
$deptStatsStmt = $pdo->prepare(
    "SELECT d.id, d.name,
        COUNT(t.id) AS total_mails,
        SUM(CASE WHEN t.escalation_level >= 1 OR t.status = 'escalated' THEN 1 ELSE 0 END) AS total_escalations,
        SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) AS open_count,
        SUM(CASE WHEN t.status = 'replied' THEN 1 ELSE 0 END) AS replied_count
     FROM departments d
     LEFT JOIN tickets t ON t.department_id = d.id
         AND t.received_at >= ?
         AND " . portalTicketCondition('t') . "
     WHERE " . portalDepartmentCondition('d') . "
     GROUP BY d.id, d.name
     ORDER BY d.name"
);
$deptStatsStmt->execute([$dashboardStartDate]);
$deptStats = $deptStatsStmt->fetchAll();

$levelStatsStmt = $pdo->prepare(
    "SELECT d.id AS department_id, d.name AS department_name,
            u.id AS assignee_id, COALESCE(u.name, 'Unassigned') AS assignee_name,
            SUM(CASE WHEN (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')
                          AND t.escalation_level = 0 THEN 1 ELSE 0 END) AS level_1_count,
            SUM(CASE WHEN (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')
                          AND t.escalation_level = 1 THEN 1 ELSE 0 END) AS level_2_count,
            SUM(CASE WHEN (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')
                          AND t.escalation_level >= 2 THEN 1 ELSE 0 END) AS level_3_count,
            SUM(CASE WHEN t.replied_at IS NOT NULL OR t.status = 'replied' THEN 1 ELSE 0 END) AS replied_count,
            SUM(CASE WHEN COALESCE(delivery.reminder_sent, 0) = 1 THEN 1 ELSE 0 END) AS reminder_sent_count,
            SUM(CASE WHEN COALESCE(delivery.level_2_sent, 0) = 1 THEN 1 ELSE 0 END) AS level_2_sent_count,
            SUM(CASE WHEN COALESCE(delivery.level_3_sent, 0) = 1
                           OR COALESCE(level_3_log.level_3_sent, 0) = 1 THEN 1 ELSE 0 END) AS level_3_sent_count
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
     LEFT JOIN (
         SELECT ticket_id,
                MAX(CASE WHEN event_type = 'no_reply_warning'
                              AND note LIKE 'Reminder included in consolidated digest%' THEN 1 ELSE 0 END) AS reminder_sent,
                MAX(CASE WHEN event_type = 'escalated'
                              AND note LIKE 'Level 2 escalation email sent%' THEN 1 ELSE 0 END) AS level_2_sent,
                MAX(CASE WHEN event_type = 'escalated'
                              AND note LIKE 'Level 3 escalation email sent%' THEN 1 ELSE 0 END) AS level_3_sent
         FROM timeline_events
         GROUP BY ticket_id
     ) delivery ON delivery.ticket_id = t.id
     LEFT JOIN (
         SELECT ticket_id, MAX(email_sent = 1) AS level_3_sent
         FROM escalations
         GROUP BY ticket_id
     ) level_3_log ON level_3_log.ticket_id = t.id
     WHERE t.received_at >= ?
       AND " . portalTicketCondition('t') . "
       AND (? = '' OR u.name LIKE ? OR d.name LIKE ?)
     GROUP BY d.id, d.name, u.id, u.name
     HAVING level_1_count > 0 OR level_2_count > 0 OR level_3_count > 0 OR replied_count > 0
         OR reminder_sent_count > 0 OR level_2_sent_count > 0 OR level_3_sent_count > 0
     ORDER BY d.name, assignee_name"
);
$employeeSearchLike = '%' . $employeeSearch . '%';
$levelStatsStmt->execute([$dashboardStartDate, $employeeSearch, $employeeSearchLike, $employeeSearchLike]);
$levelStats = $levelStatsStmt->fetchAll();

// Tickets nearing SLA breach (due within next 4 hours, still open)
$nearingBreachStmt = $pdo->prepare(
    "SELECT t.*, d.name as department_name FROM tickets t
     JOIN departments d ON d.id = t.department_id
     WHERE t.status = 'open' AND t.sla_due_at IS NOT NULL
    AND t.received_at >= ?
    AND " . portalTicketCondition('t') . "
    AND (t.is_important = 1 OR t.escalation_queued = 1 OR t.status = 'escalated')
     AND t.sla_due_at <= DATE_ADD(NOW(), INTERVAL 4 HOUR)
     ORDER BY t.sla_due_at ASC
     LIMIT 10"
);
$nearingBreachStmt->execute([$dashboardStartDate]);
$nearingBreach = $nearingBreachStmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h3 class="mb-0">Dashboard</h3>
        <div class="text-muted small">Showing escalations received since <?= date('d M Y', strtotime($dashboardStartDate)) ?></div>
    </div>
    <a href="report.php" target="_blank" class="btn btn-outline-secondary btn-sm">📄 Status Report (PDF)</a>
</div>

<div class="hero-band">
    <div class="row align-items-center">
        <div class="col-md-4 text-center text-md-start">
            <div class="eyebrow">SLA Health Score</div>
            <div class="score" style="color: <?= $healthColor ?>;"><?= $slaHealth ?>%</div>
            <div class="score-label"><?= $healthyCount ?> of <?= $totalTickets ?> emails handled without final escalation</div>
        </div>
        <div class="col-md-8">
            <div class="row text-center mt-3 mt-md-0">
                <div class="col-3">
                    <a href="all_emails.php?status=open" class="d-block text-decoration-none text-reset">
                        <div class="score" style="font-size:1.6rem; color:var(--st-open);"><?= $statusCounts['open'] ?></div>
                        <div class="score-label" style="font-size:.78rem;">Open</div>
                    </a>
                </div>
                <div class="col-3">
                    <a href="all_emails.php?status=replied" class="d-block text-decoration-none text-reset">
                        <div class="score" style="font-size:1.6rem; color:var(--st-replied);"><?= $statusCounts['replied'] ?></div>
                        <div class="score-label" style="font-size:.78rem;">Replied</div>
                    </a>
                </div>
                <div class="col-3">
                    <a href="all_emails.php?status=closed" class="d-block text-decoration-none text-reset">
                        <div class="score" style="font-size:1.6rem; color:var(--st-closed);"><?= $statusCounts['closed'] ?></div>
                        <div class="score-label" style="font-size:.78rem;">Closed</div>
                    </a>
                </div>
                <div class="col-3">
                    <a href="all_emails.php?status=escalated" class="d-block text-decoration-none text-reset">
                        <div class="score" style="font-size:1.6rem; color:var(--st-escalated);"><?= $statusCounts['escalated'] ?></div>
                        <div class="score-label" style="font-size:.78rem;">Escalated</div>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a href="all_emails.php?status=open" class="stat-card d-block text-decoration-none" style="--accent: var(--st-open);">
            <div class="num"><?= $statusCounts['open'] ?></div>
            <div class="label">Open</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="all_emails.php?status=replied" class="stat-card d-block text-decoration-none" style="--accent: var(--st-replied);">
            <div class="num"><?= $statusCounts['replied'] ?></div>
            <div class="label">Replied</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="all_emails.php?status=closed" class="stat-card d-block text-decoration-none" style="--accent: var(--st-closed);">
            <div class="num"><?= $statusCounts['closed'] ?></div>
            <div class="label">Closed</div>
        </a>
    </div>
    <div class="col-md-3">
        <a href="all_emails.php?status=escalated" class="stat-card d-block text-decoration-none" style="--accent: var(--st-escalated);">
            <div class="num"><?= $statusCounts['escalated'] ?></div>
            <div class="label">Escalated</div>
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">Escalations by Department</div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Department</th>
                    <th class="text-center">Total Mails</th>
                    <th class="text-center">Escalations</th>
                    <th class="text-center">Open</th>
                    <th class="text-center">Replies Given</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deptStats as $d): ?>
                    <tr>
                        <td>
                            <a href="all_emails.php?department=<?= (int)$d['id'] ?>" class="text-decoration-none text-reset">
                                <?= htmlspecialchars($d['name']) ?>
                            </a>
                        </td>
                        <td class="text-center fw-semibold">
                            <a href="all_emails.php?department=<?= (int)$d['id'] ?>" class="text-decoration-none text-reset">
                                <?= (int)$d['total_mails'] ?>
                            </a>
                        </td>
                        <td class="text-center fw-semibold">
                            <a href="all_emails.php?department=<?= (int)$d['id'] ?>&amp;status=escalated" class="text-decoration-none text-reset">
                                <?= (int)$d['total_escalations'] ?>
                            </a>
                        </td>
                        <td class="text-center" style="color:var(--st-open); font-weight:600;">
                            <a href="all_emails.php?department=<?= (int)$d['id'] ?>&amp;status=open" class="text-decoration-none" style="color:inherit;">
                                <?= (int)$d['open_count'] ?>
                            </a>
                        </td>
                        <td class="text-center" style="color:var(--st-replied); font-weight:600;">
                            <a href="all_emails.php?department=<?= (int)$d['id'] ?>&amp;status=replied" class="text-decoration-none" style="color:inherit;">
                                <?= (int)$d['replied_count'] ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-4 employee-escalation-card">
    <div class="card-header employee-escalation-header">
        <span>Escalation Levels by Name &amp; Department</span>
        <form method="GET" class="employee-search-form" role="search">
            <label class="visually-hidden" for="employee-search">Search employee or department</label>
            <input
                id="employee-search"
                type="search"
                name="employee_search"
                class="form-control form-control-sm"
                placeholder="Search name or department"
                value="<?= htmlspecialchars($employeeSearch, ENT_QUOTES, 'UTF-8') ?>"
            >
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
            <?php if ($employeeSearch !== ''): ?>
                <a href="dashboard.php#employee-escalations" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table id="employee-escalations" class="table table-hover mb-0 employee-escalation-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Department</th>
                    <th class="text-center">L1</th>
                    <th class="text-center">L2</th>
                    <th class="text-center">L3</th>
                    <th class="text-center">Replies Given</th>
                    <th class="text-center">L1 Reminder Sent</th>
                    <th class="text-center">L2 Email Sent</th>
                    <th class="text-center">L3 Email Sent</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($levelStats as $row): ?>
                    <tr>
                        <td>
                            <a href="all_emails.php?department=<?= (int)$row['department_id'] ?>&amp;assigned_to=<?= (int)($row['assignee_id'] ?? 0) ?>&amp;workload=1"
                               class="employee-name-link">
                                <?= htmlspecialchars($row['assignee_name']) ?>
                            </a>
                        </td>
                        <td><span class="employee-department"><?= htmlspecialchars($row['department_name']) ?></span></td>
                        <?php foreach ([1 => 'level_1_count', 2 => 'level_2_count', 3 => 'level_3_count'] as $level => $countColumn): ?>
                            <td class="text-center fw-semibold">
                                <a href="all_emails.php?department=<?= (int)$row['department_id'] ?>&amp;assigned_to=<?= (int)($row['assignee_id'] ?? 0) ?>&amp;level=<?= $level ?>"
                                   class="level-count level-<?= $level ?>">
                                    <?= (int)$row[$countColumn] ?>
                                </a>
                            </td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold">
                            <a href="all_emails.php?department=<?= (int)$row['department_id'] ?>&amp;assigned_to=<?= (int)($row['assignee_id'] ?? 0) ?>&amp;workload=1"
                               class="level-count reply-count">
                                <?= (int)$row['replied_count'] ?>
                            </a>
                        </td>
                        <?php foreach ([
                            'reminder_sent_count' => ['reminder-sent-count', 'reminder'],
                            'level_2_sent_count' => ['level-2-sent-count', 'level2'],
                            'level_3_sent_count' => ['level-3-sent-count', 'level3'],
                        ] as $countColumn => [$countClass, $notification]): ?>
                            <td class="text-center fw-semibold">
                                <a href="all_emails.php?department=<?= (int)$row['department_id'] ?>&amp;assigned_to=<?= (int)($row['assignee_id'] ?? 0) ?>&amp;workload=1&amp;notification=<?= $notification ?>"
                                   class="level-count <?= $countClass ?>"
                                   title="Open email threads with confirmed delivery">
                                    <?= (int)$row[$countColumn] ?>
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($levelStats)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        <?= $employeeSearch !== '' ? 'No employees or departments match this search.' : 'No escalation, reply, or notification records found.' ?>
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="employee-delivery-note">
        Delivery columns count email threads with a confirmed send. Older Level 2 events may not have delivery confirmation recorded.
    </div>
</div>

<div class="row">
    <div class="col-12">
        <div class="card mb-4">
            <div class="card-header">Nearing SLA Breach (next 4 hrs)</div>
            <ul class="list-group list-group-flush">
                <?php if (empty($nearingBreach)): ?>
                    <li class="list-group-item text-muted">Nothing urgent right now.</li>
                <?php endif; ?>
                <?php foreach ($nearingBreach as $t): ?>
                    <li class="list-group-item">
                        <a href="email_detail.php?id=<?= $t['id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($t['subject']) ?>
                        </a>
                        <div class="small text-muted">
                            <?= htmlspecialchars($t['department_name']) ?> · Due <?= $t['sla_due_at'] ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
