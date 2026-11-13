<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $tokenInput = $_POST['activation_token'] ?? '';
    $token = is_string($tokenInput) ? trim($tokenInput) : '';
    $password = $_POST['new_password'] ?? '';
    $confirmation = $_POST['confirm_password'] ?? '';

    if (!isStrongPassword($password)) {
        $error = 'Use at least 12 characters with uppercase, lowercase, and a number.';
    } elseif (!is_string($confirmation) || !hash_equals($password, $confirmation)) {
        $error = 'Passwords do not match.';
    } elseif (!activateAccountWithToken($token, $password)) {
        $error = 'This token is invalid, expired, already used, or the account is inactive.';
    } else {
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <title>Activate Account - Escalation Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <h4 class="mb-1 text-center"><span class="auth-brand-dot"></span>Set your password</h4>
        <p class="text-center text-muted mb-4" style="font-size:.85rem;">Use the single-use token provided by your administrator.</p>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label">Activation token</label>
                <input type="text" name="activation_token" class="form-control" required
                       minlength="64" maxlength="64" pattern="[a-fA-F0-9]{64}" autocomplete="one-time-code">
            </div>
            <div class="mb-3">
                <label class="form-label">New password</label>
                <input type="password" name="new_password" class="form-control" required minlength="12"
                       autocomplete="new-password">
                <div class="form-text">At least 12 characters, including uppercase, lowercase, and a number.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm password</label>
                <input type="password" name="confirm_password" class="form-control" required minlength="12"
                       autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">Set password</button>
        </form>
    </div>
</div>
</body>
</html>
