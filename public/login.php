<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $emailInput = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    if (attemptLogin($email, $password)) {
        header('Location: dashboard.php');
        exit;
    }
    $error = 'Invalid email or password. Accounts awaiting setup need an administrator-issued activation token.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Escalation Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme.css" rel="stylesheet">
</head>
<body>
<div class="auth-shell">
    <div class="auth-card">
        <h4 class="mb-1 text-center"><span class="auth-brand-dot"></span>Escalation Tracker</h4>
        <p class="text-center text-muted mb-4" style="font-size:.85rem;">Email Response &amp; Escalation Matrix</p>

        <?php if ($error): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="mb-3">
                <label class="form-label" style="font-size:.85rem; font-weight:500;">Email</label>
                <input type="email" name="email" class="form-control" required autofocus autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="form-label" style="font-size:.85rem; font-weight:500;">Password</label>
                <input type="password" name="password" class="form-control" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-primary w-100">Sign in</button>
        </form>
        <p class="text-center text-muted mt-3 mb-0" style="font-size:.8rem;">
            Have an administrator-issued setup token? <a href="activate.php">Activate or reset your password</a>.
        </p>
    </div>
</div>
</body>
</html>
