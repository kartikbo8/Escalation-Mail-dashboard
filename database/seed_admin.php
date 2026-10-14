<?php
/**
 * database/seed_admin.php
 *
 * Run this ONCE from browser or CLI to create your first login:
 *   http://localhost/escalation-tracker/database/seed_admin.php
 *
 * Delete this file (or move it out of public reach) after creating your admin user.
 */
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

$name     = 'Admin';
$email    = 'admin@example.com';
$password = 'admin123';   // change this after first login
$role     = 'super_admin';

$existing = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$existing->execute([$email]);

if ($existing->fetch()) {
    echo "User with email {$email} already exists. Nothing to do.";
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare(
    "INSERT INTO users (name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, NULL)"
);
$stmt->execute([$name, $email, $hash, $role]);

echo "Admin user created.<br>Email: {$email}<br>Password: {$password}<br>";
echo "<strong>Delete this file now for security.</strong>";
