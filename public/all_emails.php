<?php

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$deptFilter   = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$searchFilter = trim($_GET['search'] ?? '');
$message = '';

$sql = "SELECT
            t.*,
            d.name AS department_name
        FROM tickets t
        LEFT JOIN departments d
            ON d.id = t.department_id
        WHERE " . portalTicketCondition('t');

$params = [];
$retentionCutoff = (new DateTimeImmutable('now'))->modify('-30 days');
$sql .= " AND (t.received_at >= ? OR t.status IN ('open', 'escalated'))";
$params[] = $retentionCutoff->format('Y-m-d H:i:s');

if ($deptFilter !== '') {
    $sql .= " AND t.department_id = ?";
    $params[] = (int)$deptFilter;
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

$departments = getDepartments();
if (portalDepartmentId() !== null) {
    $departments = array_values(array_filter($departments, static fn($department) => (int)$department['id'] === portalDepartmentId()));
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">All Emails</h3>
        <div class="text-muted small">
            <?= count($tickets) ?> ticket(s) found · includes normal and keyword-matched emails · last 30 days
        </div>
    </div>
</div>

<form method="GET" class="row g-2 mb-3">
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
