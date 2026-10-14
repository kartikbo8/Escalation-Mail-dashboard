<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

// Overall status counts for keyword-matched, queued, or escalated tickets only
$ticketScope = "(is_important = 1 OR escalation_queued = 1 OR status = 'escalated')";
$statusCounts = $pdo->query("SELECT status, COUNT(*) as total FROM tickets t WHERE " . portalTicketCondition('t') . " AND {$ticketScope} GROUP BY status")
    ->fetchAll(PDO::FETCH_KEY_PAIR);
foreach (['open', 'replied', 'closed', 'escalated'] as $s) {
    if (!isset($statusCounts[$s])) $statusCounts[$s] = 0;
}

// Per-department breakdown
$deptStats = $pdo->query(
    "SELECT d.name,
        SUM(CASE WHEN t.status = 'open' THEN 1 ELSE 0 END) as open_count,
        SUM(CASE WHEN t.status = 'replied' THEN 1 ELSE 0 END) as replied_count,
        SUM(CASE WHEN t.status = 'closed' THEN 1 ELSE 0 END) as closed_count,
        SUM(CASE WHEN t.status = 'escalated' THEN 1 ELSE 0 END) as escalated_count,
        COUNT(t.id) as total
     FROM departments d
    LEFT JOIN tickets t ON t.department_id = d.id AND " . portalTicketCondition('t') . " AND {$ticketScope}
    WHERE " . portalDepartmentCondition('d') . "
     GROUP BY d.id, d.name
     ORDER BY d.name"
)->fetchAll();

// Currently escalated (Level 3 / final) tickets - need attention
$escalatedTickets = $pdo->query(
    "SELECT t.*, d.name as department_name, u.name as assignee_name
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'escalated' AND " . portalTicketCondition('t') . " AND {$ticketScope}
     ORDER BY t.sla_due_at ASC"
)->fetchAll();

// Currently at Level 2 (with manager/HOD, not yet final)
$level2Tickets = $pdo->query(
    "SELECT t.*, d.name as department_name, u.name as assignee_name
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'open' AND t.escalation_level = 1 AND " . portalTicketCondition('t') . " AND {$ticketScope}
     ORDER BY t.sla_due_at ASC"
)->fetchAll();

// Open tickets nearing breach (next 4 hours)
$nearingBreach = $pdo->query(
    "SELECT t.*, d.name as department_name, u.name as assignee_name
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
    WHERE t.status = 'open' AND t.escalation_level = 0 AND " . portalTicketCondition('t') . " AND {$ticketScope}
       AND t.sla_due_at IS NOT NULL AND t.sla_due_at <= DATE_ADD(NOW(), INTERVAL 4 HOUR)
     ORDER BY t.sla_due_at ASC"
)->fetchAll();

$generatedAt = date('d M Y, h:i A');
require_once __DIR__ . '/../includes/layout_header.php';
?>
<style>
    .report-box { font-family: Arial, Helvetica, sans-serif; }
    .report-box h1 { font-size: 20px; margin-bottom: 2px; }
    .report-box .subtitle { color: #666; margin-bottom: 24px; }
    .report-box h2 { font-size: 15px; margin-top: 28px; border-bottom: 2px solid #333; padding-bottom: 4px; }
    .report-box table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .report-box th, .report-box td { text-align: left; padding: 6px 8px; border-bottom: 1px solid #ddd; font-size: 13px; }
    .report-box th { background: #f2f2f2; }
    .report-box .summary-cards { display: flex; gap: 12px; margin-top: 10px; }
    .report-box .card { flex: 1; border: 1px solid #ddd; border-radius: 6px; padding: 12px; text-align: center; }
    .report-box .card .num { font-size: 22px; font-weight: bold; }
    .report-box .no-data { color: #888; font-style: italic; }
    .print-bar { margin-bottom: 20px; }
    @media print {
        .navbar, .print-bar { display: none !important; }
    }
</style>

<div class="report-box">

<div class="print-bar">
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">🖨️ Print / Save as PDF</button>
</div>

<h1>Email Escalation Tracker - Status Report</h1>
<div class="subtitle">Generated on <?= $generatedAt ?></div>

<h2>Overall Summary</h2>
<div class="summary-cards">
    <div class="card"><div class="num"><?= $statusCounts['open'] ?></div>Open</div>
    <div class="card"><div class="num"><?= $statusCounts['replied'] ?></div>Replied</div>
    <div class="card"><div class="num"><?= $statusCounts['closed'] ?></div>Closed</div>
    <div class="card"><div class="num"><?= $statusCounts['escalated'] ?></div>Escalated (Final)</div>
</div>

<h2>By Department</h2>
<table>
    <thead>
        <tr><th>Department</th><th>Open</th><th>Replied</th><th>Closed</th><th>Escalated</th><th>Total</th></tr>
    </thead>
    <tbody>
        <?php foreach ($deptStats as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['name']) ?></td>
                <td><?= $d['open_count'] ?></td>
                <td><?= $d['replied_count'] ?></td>
                <td><?= $d['closed_count'] ?></td>
                <td><?= $d['escalated_count'] ?></td>
                <td><strong><?= $d['total'] ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<h2>Final Escalations (Level 3 - HR/Director notified)</h2>
<?php if (empty($escalatedTickets)): ?>
    <p class="no-data">None currently.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Subject</th><th>Department</th><th>Assigned To</th><th>Received</th><th>Deadline</th></tr></thead>
        <tbody>
            <?php foreach ($escalatedTickets as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['subject']) ?></td>
                    <td><?= htmlspecialchars($t['department_name']) ?></td>
                    <td><?= htmlspecialchars($t['assignee_name'] ?? 'Unassigned') ?></td>
                    <td><?= $t['received_at'] ?></td>
                    <td><?= $t['sla_due_at'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>At Level 2 (Manager/HOD notified, not yet final)</h2>
<?php if (empty($level2Tickets)): ?>
    <p class="no-data">None currently.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Subject</th><th>Department</th><th>Assigned To</th><th>Level 2 Deadline</th></tr></thead>
        <tbody>
            <?php foreach ($level2Tickets as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['subject']) ?></td>
                    <td><?= htmlspecialchars($t['department_name']) ?></td>
                    <td><?= htmlspecialchars($t['assignee_name'] ?? 'Unassigned') ?></td>
                    <td><?= $t['sla_due_at'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Nearing SLA Breach (next 4 hours, Level 1)</h2>
<?php if (empty($nearingBreach)): ?>
    <p class="no-data">Nothing urgent right now.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Subject</th><th>Department</th><th>Assigned To</th><th>Deadline</th></tr></thead>
        <tbody>
            <?php foreach ($nearingBreach as $t): ?>
                <tr>
                    <td><?= htmlspecialchars($t['subject']) ?></td>
                    <td><?= htmlspecialchars($t['department_name']) ?></td>
                    <td><?= htmlspecialchars($t['assignee_name'] ?? 'Unassigned') ?></td>
                    <td><?= $t['sla_due_at'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
