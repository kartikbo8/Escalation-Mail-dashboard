<?php
require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$mode = 'login'; // 'login' or 'first_password'
$firstAccount = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $formType = $_POST['form_type'] ?? 'login';
    $email = trim($_POST['email'] ?? '');

    if ($formType === 'login') {
        // Is this an account that hasn't set its own password yet?
        $firstAccount = findAccountNeedingFirstPassword($email);

        if ($firstAccount) {
            $mode = 'first_password';
        } else {
            $password = $_POST['password'] ?? '';
            if (attemptLogin($email, $password)) {
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid email or password.';
            }
        }
    }
    
    if ($formType === 'set_first_password') {
        $userId = (int)$_POST['user_id'];
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Re-verify eligibility server-side (don't trust the hidden user_id blindly)
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND is_active = 1 AND password_set = 0");
        $stmt->execute([$userId]);
        $firstAccount = $stmt->fetch();

        if (!$firstAccount) {
            $error = 'This account is not eligible for first-time password setup.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters.';
            $mode = 'first_password';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match.';
            $mode = 'first_password';
        } else {
            setFirstPasswordAndLogin($firstAccount['id'], $newPassword);
            header('Location: dashboard.php');
            exit;
        }
    }
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

        <?php if ($mode === 'first_password' && $firstAccount): ?>
            <p class="text-muted" style="font-size:.85rem;">
                Welcome, <strong><?= htmlspecialchars($firstAccount['name']) ?></strong> - this looks like your
                first time logging in. Please create your own password.
            </p>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="form_type" value="set_first_password">
                <input type="hidden" name="user_id" value="<?= $firstAccount['id'] ?>">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.85rem; font-weight:500;">New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="6" autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:.85rem; font-weight:500;">Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <button type="submit" class="btn btn-primary w-100">Set Password &amp; Login</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="form_type" value="login">
                <div class="mb-3">
                    <label class="form-label" style="font-size:.85rem; font-weight:500;">Email</label>
                    <input type="email" name="email" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:.85rem; font-weight:500;">Password</label>
                    <input type="password" name="password" class="form-control">
                    <div class="form-text" style="font-size:.75rem;">
                        First time here? Just enter your email and click Continue - you'll be asked to set a password.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100">Continue</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
