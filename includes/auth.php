<?php
/**
 * Authentication helpers
 */
require_once __DIR__ . '/../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => strtolower((string)getenv('APP_ENV')) === 'production'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
        'path' => '/',
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || $submitted === '' || !hash_equals(csrfToken(), $submitted)) {
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

    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($userId === false) {
        doLogout();
        header('Location: login.php');
        exit;
    }

    $stmt = getDB()->prepare(
        "SELECT id, name, email, role, department_id, is_active, password_set, auth_version
         FROM users WHERE id = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user
        || (int)$user['is_active'] !== 1
        || (int)$user['password_set'] !== 1
        || (int)($_SESSION['auth_version'] ?? -1) !== (int)$user['auth_version']
        || !in_array($user['role'], ['user', 'admin', 'super_admin'], true)) {
        doLogout();
        header('Location: login.php');
        exit;
    }

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['department_id'] = $user['department_id'] === null ? null : (int)$user['department_id'];
    $_SESSION['auth_version'] = (int)$user['auth_version'];
}

function requireRole($roles) {
    requireLogin();
    if (!in_array($_SESSION['role'], (array)$roles)) {
        http_response_code(403);
        exit('Access denied. You do not have permission to view this page.');
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
        && !in_array((int)$department['id'], portalDepartmentIds(), true)) {
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
    if (($_SESSION['role'] ?? '') === 'user') {
        // Employees see mails assigned to them and mails where they are in To
        // (an external mail to two people is one ticket; both see its status).
        $userId = (int)($_SESSION['user_id'] ?? 0);
        return "({$alias}.assigned_to = {$userId}"
            . " OR LOWER(COALESCE({$alias}.to_recipients_json, '')) LIKE"
            . " CONCAT('%\"', (SELECT LOWER(email) FROM users WHERE id = {$userId}), '\"%'))";
    }
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

function portalUserCondition($alias = 'u') {
    if (($_SESSION['role'] ?? '') === 'super_admin') {
        return '1=1';
    }
    $departmentIds = portalDepartmentIds();
    if (!$departmentIds) {
        return '1=0';
    }
    return $alias . '.department_id IN (' . implode(',', array_map('intval', $departmentIds)) . ')';
}

function portalUrl($path) {
    $departmentId = portalDepartmentId();
    return $departmentId === null ? $path : $path . (str_contains($path, '?') ? '&' : '?') . 'portal=department';
}

function attemptLogin($email, $password) {
    if (!is_string($email) || !is_string($password) || strlen($email) > 255 || strlen($password) > 1024) {
        return false;
    }
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "SELECT id, name, email, role, department_id, password_hash, password_set, auth_version
         FROM users WHERE email = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user
        && in_array($user['role'], ['user', 'admin', 'super_admin'], true)
        && (int)$user['password_set'] === 1
        && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['name']          = $user['name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['department_id'] = $user['department_id'];
        $_SESSION['auth_version']  = (int)$user['auth_version'];
        return true;
    }
    return false;
}

/**
 * Issue a single-use, expiring password setup token for an account that was
 * provisioned by an administrator or the CLI seed tool. Share it out of band.
 */
function createAccountActivationToken($userId, $ttlSeconds = 86400) {
    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + max(300, (int)$ttlSeconds));
    $stmt = getDB()->prepare(
        "UPDATE users
         SET activation_token_hash = ?, activation_expires_at = ?
         WHERE id = ? AND is_active = 1 AND password_set = 0"
    );
    $stmt->execute([hash('sha256', $token), $expiresAt, (int)$userId]);
    return $stmt->rowCount() === 1 ? $token : null;
}

/**
 * Require a high-entropy user-chosen password for first activation/reset.
 */
function isStrongPassword($password) {
    return is_string($password)
        && strlen($password) >= 12
        && strlen($password) <= 1024
        && preg_match('/[a-z]/', $password)
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[0-9]/', $password);
}

/**
 * Consume a setup token atomically and log in the verified account.
 */
function activateAccountWithToken($token, $newPassword) {
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/i', $token) || !isStrongPassword($newPassword)) {
        return false;
    }
    $token = strtolower($token);

    $pdo = getDB();
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT id, name, email, role, department_id, auth_version
             FROM users
             WHERE activation_token_hash = ?
               AND activation_expires_at > UTC_TIMESTAMP()
               AND is_active = 1
               AND password_set = 0
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        if (!$user || !in_array($user['role'], ['user', 'admin', 'super_admin'], true)) {
            $pdo->rollBack();
            return false;
        }
        $update = $pdo->prepare(
            "UPDATE users
             SET password_hash = ?, password_set = 1, auth_version = auth_version + 1,
                 activation_token_hash = NULL, activation_expires_at = NULL
             WHERE id = ?"
        );
        $update->execute([$hash, (int)$user['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['name']          = $user['name'];
    $_SESSION['email']         = $user['email'];
    $_SESSION['role']          = $user['role'];
    $_SESSION['department_id'] = $user['department_id'];
    $_SESSION['auth_version']  = (int)$user['auth_version'] + 1;
    return true;
}

function doLogout() {
    $params = session_get_cookie_params();
    session_unset();
    session_destroy();
    if (ini_get('session.use_cookies')) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
