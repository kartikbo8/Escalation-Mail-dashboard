<?php
/**
 * Authentication helpers
 */
require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requireCsrfToken() {
    $submitted = $_POST['csrf_token'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $sameOrigin = ($origin !== '' && parse_url($origin, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? ''))
        || ($referer !== '' && parse_url($referer, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? ''));

    if ((!$submitted || !hash_equals(csrfToken(), $submitted)) && !$sameOrigin) {
        http_response_code(403);
        exit('Invalid security token. Please reload the page and try again.');
    }
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        die('Access denied. You do not have permission to view this page.');
    }
}

function currentUser() {
    if (!isLoggedIn()) return null;
    return [
        'id'            => $_SESSION['user_id'],
        'name'          => $_SESSION['name'],
        'email'         => $_SESSION['email'],
        'role'          => $_SESSION['role'],
        'department_id' => $_SESSION['department_id'],
    ];
}

function setPortalDepartment($departmentSlug) {
    if ($departmentSlug === '' || $departmentSlug === 'all' || $departmentSlug === 'merge') {
        if (($_SESSION['role'] ?? '') === 'super_admin') {
            unset($_SESSION['portal_department_id'], $_SESSION['portal_department_name']);
        } elseif (!empty($_SESSION['department_id'])) {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$_SESSION['department_id']]);
            $department = $stmt->fetch();
            $_SESSION['portal_department_id'] = (int)$department['id'];
            $_SESSION['portal_department_name'] = $department['name'];
        }
        return null;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, name FROM departments WHERE LOWER(REPLACE(name, ' ', '-')) = ? LIMIT 1");
    $stmt->execute([strtolower(trim($departmentSlug))]);
    $department = $stmt->fetch();

    if (!$department) {
        http_response_code(404);
        exit('Department portal not found.');
    }

    if (($_SESSION['role'] ?? '') !== 'super_admin'
        && (int)($_SESSION['department_id'] ?? 0) !== (int)$department['id']) {
        http_response_code(403);
        exit('You do not have access to this department portal.');
    }

    $_SESSION['portal_department_id'] = (int)$department['id'];
    $_SESSION['portal_department_name'] = $department['name'];
    return $department;
}

function portalDepartmentId() {
    if (($_SESSION['role'] ?? '') !== 'super_admin' && !empty($_SESSION['department_id'])) {
        return (int)$_SESSION['department_id'];
    }
    return isset($_SESSION['portal_department_id']) ? (int)$_SESSION['portal_department_id'] : null;
}

function portalDepartmentIds() {
    static $departmentIds = false;
    if ($departmentIds !== false) {
        return $departmentIds;
    }

    if (($_SESSION['role'] ?? '') === 'super_admin') {
        $selectedDepartmentId = portalDepartmentId();
        $departmentIds = $selectedDepartmentId === null ? null : [$selectedDepartmentId];
        return $departmentIds;
    }

    $departmentId = (int)($_SESSION['department_id'] ?? 0);
    if ($departmentId > 0) {
        $departmentIds = [$departmentId];
        return $departmentIds;
    }

    if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
        $departmentIds = [];
        return $departmentIds;
    }

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM departments WHERE hod_user_id = ?");
    $userId = (int)$_SESSION['user_id'];
    $stmt->execute([$userId]);
    $departmentIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    return $departmentIds;
}

function portalDepartmentName() {
    if (!empty($_SESSION['portal_department_name'])) {
        return $_SESSION['portal_department_name'];
    }

    if (($_SESSION['role'] ?? '') !== 'super_admin' && !empty($_SESSION['department_id'])) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$_SESSION['department_id']]);
        return $stmt->fetchColumn() ?: null;
    }

    return null;
}

function portalTicketCondition($alias = 't') {
    $departmentIds = portalDepartmentIds();
    if ($departmentIds === null) {
        return '1=1';
    }
    if (!$departmentIds) {
        return '1=0';
    }
    return $alias . '.department_id IN (' . implode(',', $departmentIds) . ')';
}

function portalDepartmentCondition($alias = 'd') {
    $departmentIds = portalDepartmentIds();
    if ($departmentIds === null) {
        return '1=1';
    }
    if (!$departmentIds) {
        return '1=0';
    }
    return $alias . '.id IN (' . implode(',', $departmentIds) . ')';
}

function portalUrl($path) {
    $departmentId = portalDepartmentId();
    return $departmentId === null ? $path : $path . (str_contains($path, '?') ? '&' : '?') . 'portal=department';
}

function attemptLogin($email, $password) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['name']          = $user['name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        return true;
    }
    return false;
}

/**
 * Look up an account by email that still needs to self-set its first
 * password (password_set = 0) - e.g. an employee synced in from
 * Microsoft 365 who has never logged in here before.
 *
 * IMPORTANT: only plain 'user' accounts are eligible for self-service.
 * An 'admin' or 'super_admin' account that has never had its password
 * set must be activated by an existing Super Admin (via the password
 * reset button on the Users page) - otherwise anyone who knows that
 * email could claim an admin-level account before the real person does.
 */
function findAccountNeedingFirstPassword($email) {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 AND password_set = 0 AND role = 'user'"
    );
    $stmt->execute([$email]);
    return $stmt->fetch() ?: null;
}

/**
 * Detect an admin/super_admin account that exists but hasn't been
 * activated yet (password_set = 0) - used only to show a clear message,
 * never to let them self-serve a password.
 */
function findUnactivatedPrivilegedAccount($email) {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT id FROM users WHERE email = ? AND is_active = 1 AND password_set = 0 AND role IN ('admin','super_admin')"
    );
    $stmt->execute([$email]);
    return $stmt->fetch() ? true : false;
}

/**
 * Set a first-time password for an account and log them straight in.
 * Only call this after findAccountNeedingFirstPassword() confirmed the
 * account is eligible.
 */
function setFirstPasswordAndLogin($userId, $newPassword) {
    $pdo = getDB();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE users SET password_hash = ?, password_set = 1 WHERE id = ?")
        ->execute([$hash, $userId]);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    session_regenerate_id(true);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['department_id'] = $user['department_id'];
}

function doLogout() {
    session_unset();
    session_destroy();
}
