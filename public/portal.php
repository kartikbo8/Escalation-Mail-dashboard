<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$departmentSlug = strtolower(trim($_GET['department'] ?? 'all'));
setPortalDepartment($departmentSlug);

header('Location: dashboard.php');
exit;