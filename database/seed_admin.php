<?php
/**
 * Create the initial administrator account from a local CLI session only:
 *   php database/seed_admin.php
 *
 * The displayed token is single-use, expires after 24 hours, and must be
 * delivered to the intended account holder through a separate trusted channel.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

$readValue = static function ($prompt) {
    fwrite(STDOUT, $prompt);
    return trim((string)fgets(STDIN));
};

$name = $readValue('Administrator name: ');
$email = strtolower($readValue('Administrator email: '));
if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid name and email are required.\n");
    exit(1);
}

$pdo = getDB();
$existingCount = (int)executePreparedQuery($pdo, "SELECT COUNT(*) FROM users")->fetchColumn();
if ($existingCount !== 0) {
    fwrite(STDERR, "Refusing to seed: an account already exists. Use the administrator-managed activation/reset flow.\n");
    exit(1);
}

$check = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check->execute([$email]);
if ($check->fetch()) {
    fwrite(STDERR, "That email already exists.\n");
    exit(1);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
$insert = $pdo->prepare(
    "INSERT INTO users
        (name, email, password_hash, password_set, activation_token_hash, activation_expires_at, role, department_id)
     VALUES (?, ?, ?, 0, ?, ?, 'super_admin', NULL)"
);
$insert->execute([$name, $email, $passwordHash, $tokenHash, $expiresAt]);

fwrite(STDOUT, "Administrator account created for {$email}.\n");
fwrite(STDOUT, "One-time activation token (expires in 24 hours): {$token}\n");
fwrite(STDOUT, "Give this token to the intended account holder over a separate trusted channel. It cannot be shown again.\n");
