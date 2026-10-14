<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$portalName = portalDepartmentName();
$storageMonthStart = new DateTimeImmutable('first day of last month 00:00:00');
$storageMonthEnd = $storageMonthStart->modify('first day of next month');
$storageStmt = getDB()->prepare(
    "SELECT COUNT(*) AS email_count,
            COALESCE(SUM(
                OCTET_LENGTH(COALESCE(t.subject, '')) +
                OCTET_LENGTH(COALESCE(t.sender_name, '')) +
                OCTET_LENGTH(COALESCE(t.sender_email, '')) +
                OCTET_LENGTH(COALESCE(t.conversation_id, '')) +
                OCTET_LENGTH(COALESCE(t.graph_message_id, '')) +
                OCTET_LENGTH(COALESCE(t.source_mailbox, '')) +
                OCTET_LENGTH(COALESCE(t.matched_keyword, ''))
            ), 0) AS stored_bytes
     FROM tickets t
     WHERE t.received_at >= ?
       AND t.received_at < ?
       AND " . portalTicketCondition('t')
);
$storageStmt->execute([
    $storageMonthStart->format('Y-m-d H:i:s'),
    $storageMonthEnd->format('Y-m-d H:i:s'),
]);
$monthlyEmailStorage = $storageStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $portalName ? htmlspecialchars($portalName) . ' Portal - ' : '' ?>Email Escalation Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../public/assets/css/theme.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><?= $portalName ? htmlspecialchars($portalName) . ' Portal' : 'Escalation Tracker' ?></a>
        <div class="d-flex align-items-center gap-3">
            <span class="navbar-text text-white">
                <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="app-shell">
    <aside class="app-sidebar">
        <div class="sidebar-section-label">MENU</div>
        <nav class="app-sidebar-nav" aria-label="Main navigation">
            <a class="sidebar-link" href="dashboard.php">Dashboard</a>
            <a class="sidebar-link" href="all_emails.php">All Emails</a>
            <a class="sidebar-link" href="email_list.php">Email List</a>
            <?php if (in_array($user['role'], ['super_admin', 'admin'], true)): ?>
                <a class="sidebar-link" href="users.php">Users</a>
                <a class="sidebar-link" href="escalation_matrix_export.php">Escalation Excel</a>
            <?php endif; ?>
            <?php if ($user['role'] === 'super_admin'): ?>
                <a class="sidebar-link" href="settings.php">Settings</a>
            <?php endif; ?>
            <?php if ($portalName): ?>
                <a class="sidebar-link" href="portal.php?department=all">Merge Portal</a>
            <?php endif; ?>
        </nav>

        <section class="sidebar-storage" aria-label="Email data storage from last month">
            <div class="sidebar-section-label">EMAIL DATA</div>
            <div class="sidebar-storage-month"><?= htmlspecialchars($storageMonthStart->format('F Y')) ?></div>
            <div class="sidebar-storage-size">
                <?= number_format((int)$monthlyEmailStorage['stored_bytes'] / (1024 * 1024), 2) ?> MB
            </div>
            <div class="sidebar-storage-count">
                <?= number_format((int)$monthlyEmailStorage['email_count']) ?> email(s)
            </div>
            <div class="sidebar-storage-note">
                Stored email fields only; message bodies remain in Microsoft 365.
            </div>
        </section>
    </aside>
    <main class="app-main">
        <div class="container-fluid mt-4">
