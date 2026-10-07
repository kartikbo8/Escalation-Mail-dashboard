<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$portalName = portalDepartmentName();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $portalName ? htmlspecialchars($portalName) . ' Portal - ' : '' ?>Email Escalation Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><?= $portalName ? htmlspecialchars($portalName) . ' Portal' : 'Escalation Tracker' ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNavigation" aria-controls="mainNavigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNavigation">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="all_emails.php">All Emails</a></li>
                <li class="nav-item"><a class="nav-link" href="email_list.php">Email List</a></li>
                <?php if (in_array($user['role'], ['super_admin', 'admin'])): ?>
                <li class="nav-item"><a class="nav-link" href="users.php">Users</a></li>
                <li class="nav-item"><a class="nav-link" href="escalation_matrix_export.php">Escalation Excel</a></li>
                <?php endif; ?>
                <?php if ($user['role'] === 'super_admin'): ?>
                <li class="nav-item"><a class="nav-link" href="settings.php">Settings</a></li>
                <?php endif; ?>
                <?php if ($portalName): ?>
                <li class="nav-item"><a class="nav-link" href="portal.php?department=all">Merge Portal</a></li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text text-white me-3">
                <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
