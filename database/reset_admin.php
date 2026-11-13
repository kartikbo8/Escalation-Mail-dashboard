<?php
/**
 * Revoke an existing administrator password and issue a one-time setup token.
 * Run only from a trusted local/server CLI after applying migration 16:
 *   php database/reset_admin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/db.php';

fwrite(STDOUT, 'Existing super-admin email: ');
$email = strtolower(trim((string)fgets(STDIN)));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid email is required.\n");
    exit(1);
}

$pdo = getDB();
$lookup = $pdo->prepare(
    "SELECT id, email FROM users
     WHERE email = ? AND role IN ('admin', 'super_admin') AND is_active = 1
     LIMIT 1"
);
$lookup->execute([$email]);
$admin = $lookup->fetch(PDO::FETCH_ASSOC);
if (!$admin) {
    fwrite(STDERR, "No active super-admin account matches that email.\n");
    exit(1);
}

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
$expiresAt = gmdate('Y-m-d H:i:s', time() + 86400);
$update = $pdo->prepare(
    "UPDATE users
     SET password_hash = ?, password_set = 0,
         activation_token_hash = ?, activation_expires_at = ?,
         auth_version = auth_version + 1
     WHERE id = ? AND role IN ('admin', 'super_admin') AND is_active = 1"
);
$update->execute([$placeholderHash, $tokenHash, $expiresAt, (int)$admin['id']]);
if ($update->rowCount() !== 1) {
    fwrite(STDERR, "Administrator reset did not complete.\n");
    exit(1);
}

fwrite(STDOUT, "Password reset token for {$admin['email']} (one-time; expires in 24 hours): {$token}\n");
fwrite(STDOUT, "Deliver it to the verified account holder through a separate trusted channel.\n");
