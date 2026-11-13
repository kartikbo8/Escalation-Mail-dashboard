<?php
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['super_admin', 'admin']);

$pdo = getDB();
$message = '';
$activationToken = null;
$managedUserScope = portalUserCondition('u');
if (currentUser()['role'] === 'admin') {
    $managedUserScope .= " AND u.role = 'user'";
}
$validateManagerId = static function ($value, $targetUserId = null) use ($pdo) {
    if ($value === null || $value === '') {
        return null;
    }
    $managerId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($managerId === false || ($targetUserId !== null && (int)$managerId === (int)$targetUserId)) {
        return false;
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM users u WHERE u.id = ? AND " . portalUserCondition('u') . " LIMIT 1"
    );
    $stmt->execute([(int)$managerId]);
    return $stmt->fetch() ? (int)$managerId : false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $formType = $_POST['form_type'] ?? '';
    $postedUserId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $targetUser = null;
    if ($formType !== 'add_user') {
        if ($postedUserId === false) {
            http_response_code(400);
            exit('Invalid user.');
        }
        $targetStmt = $pdo->prepare(
            "SELECT id, role, is_active, password_set
             FROM users u WHERE u.id = ? AND {$managedUserScope} LIMIT 1"
        );
        $targetStmt->execute([$postedUserId]);
        $targetUser = $targetStmt->fetch();
        if (!$targetUser) {
            http_response_code(403);
            exit('User not found or access denied.');
        }
    }

    if ($formType === 'add_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $role = (string)($_POST['role'] ?? 'user');
        $deptId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
        $allowedDeptIds = portalDepartmentIds();
        $roleAllowed = in_array($role, ['user', 'admin', 'super_admin'], true)
            && (currentUser()['role'] === 'super_admin' || $role === 'user');
        $deptAllowed = currentUser()['role'] === 'super_admin'
            || ($deptId !== null && in_array($deptId, $allowedDeptIds, true));
        if ($name === '' || strlen($name) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)
            || !$roleAllowed || !$deptAllowed) {
            http_response_code(400);
            exit('Invalid user details or access denied.');
        }
        $existing = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $existing->execute([$email]);

        if ($existing->fetch()) {
            $message = 'A user with this email already exists.';
        } else {
            $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $managerId = $validateManagerId($_POST['reporting_manager_id'] ?? null);
            $manager2Id = $validateManagerId($_POST['reporting_manager_2_id'] ?? null);
            if ($managerId === false || $manager2Id === false) {
                http_response_code(403);
                exit('Invalid reporting manager or access denied.');
            }

            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, password_set, role, department_id, reporting_manager_id, reporting_manager_2_id)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)"
            );
            $stmt->execute([$name, $email, $hash, $role, $deptId, $managerId, $manager2Id]);
            $activationToken = createAccountActivationToken((int)$pdo->lastInsertId());
            $message = 'Account created. Give its one-time activation token to the intended account holder through a verified channel.';
        }
    }

    if ($formType === 'update_manager') {
        $userId = (int)$postedUserId;
        $currentUser = $pdo->prepare("SELECT reporting_manager_id, reporting_manager_2_id FROM users WHERE id = ?");
        $currentUser->execute([$userId]);
        $current = $currentUser->fetch();

        $managerId = $validateManagerId(
            $_POST['reporting_manager_id'] ?? ($current['reporting_manager_id'] ?? null),
            $userId
        );
        $manager2Id = $validateManagerId(
            $_POST['reporting_manager_2_id'] ?? ($current['reporting_manager_2_id'] ?? null),
            $userId
        );
        if ($managerId === false || $manager2Id === false) {
            http_response_code(403);
            exit('Invalid reporting manager or access denied.');
        }

        $pdo->prepare("UPDATE users SET reporting_manager_id = ?, reporting_manager_2_id = ? WHERE id = ?")
            ->execute([$managerId, $manager2Id, $userId]);
        $message = 'Reporting managers updated.';
    }

    if ($formType === 'update_department') {
        $userId = (int)$postedUserId;
        $departmentId = $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
        if (currentUser()['role'] !== 'super_admin'
            && ($departmentId === null || !in_array($departmentId, portalDepartmentIds(), true))) {
            http_response_code(403);
            exit('Access denied.');
        }
        $pdo->prepare("UPDATE users SET department_id = ? WHERE id = ?")->execute([$departmentId, $userId]);
        $message = 'Department updated.';
    }

    if ($formType === 'update_hod') {
        // HOD is department-level data, not a per-user field. Editing it from the
        // Users grid was clearing the whole department because each employee in that
        // department shares the same department_id. The real editor is Settings.php.
        $message = 'HOD is managed at department level from the Settings page.';
    }

    if ($formType === 'set_active') {
        // An inactive mailbox owner gets no reminders or escalations, so only
        // the Director/HR tier may switch accounts on or off.
        requireRole(['super_admin']);
        $userId = (int)$postedUserId;
        $isActive = (int)($_POST['is_active'] ?? 0) === 1 ? 1 : 0;
        $pdo->prepare("UPDATE users SET is_active = ?, auth_version = auth_version + 1 WHERE id = ?")
            ->execute([$isActive, $userId]);
        $message = 'User status updated.';
    }

    if ($formType === 'set_monitor_mailbox') {
        requireRole(['super_admin']); // turning on live mail monitoring for someone's personal mailbox
        $userId = (int)$postedUserId;
        $monitor = (int)($_POST['monitor'] ?? 0) === 1 ? 1 : 0;
        $pdo->prepare("UPDATE users SET monitor_mailbox = ? WHERE id = ?")->execute([$monitor, $userId]);
        $message = $monitor ? 'Mailbox monitoring turned ON - this is now LIVE.' : 'Mailbox monitoring turned off.';
    }

    if ($formType === 'update_role') {
        requireRole(['super_admin']); // only Director/HR tier can grant admin/super_admin
        $userId = (int)$postedUserId;
        $newRole = $_POST['role'];
        if (in_array($newRole, ['user', 'admin', 'super_admin'])) {
            $pdo->prepare(
                "UPDATE users
                 SET role = ?, auth_version = auth_version + 1,
                     activation_token_hash = NULL, activation_expires_at = NULL
                 WHERE id = ?"
            )->execute([$newRole, $userId]);
            $message = 'Role updated.';
        }
    }

    if ($formType === 'issue_password_reset') {
        requireRole(['super_admin']); // only Director/HR tier can reset a login password
        $userId = (int)$postedUserId;
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $pdo->prepare(
            "UPDATE users
             SET password_hash = ?, password_set = 0, auth_version = auth_version + 1,
                 activation_token_hash = NULL, activation_expires_at = NULL
             WHERE id = ?"
        )->execute([$hash, $userId]);
        $activationToken = createAccountActivationToken($userId);
        $message = $activationToken
            ? 'Password reset token created. Give it to the account holder through a verified channel.'
            : 'Could not create a reset token. Ensure the account is active.';
    }

    if ($formType === 'delete_user') {
        requireRole(['super_admin']);
        $userId = (int)$postedUserId;

        if ($userId === (int)$_SESSION['user_id']) {
            $message = 'You cannot delete your own account.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $message = $stmt->rowCount() ? 'User deleted.' : 'User not found.';
        }
    }

    if ($formType === 'issue_activation') {
        requireRole(['super_admin']);
        $activationToken = createAccountActivationToken((int)$postedUserId);
        $message = $activationToken
            ? 'One-time activation token created. Give it to the account holder through a verified channel.'
            : 'Could not issue token. The account may already be active or have a password.';
    }
}

$userSearch = trim($_GET['search'] ?? '');
$userSql = "SELECT u.*, d.name as department_name, d.hod_user_id,
            m.name as manager_name,
            m2.name as manager_2_name,
            h.name as hod_name
            FROM users u
            LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN users m ON m.id = u.reporting_manager_id
            LEFT JOIN users m2 ON m2.id = u.reporting_manager_2_id
            LEFT JOIN users h ON h.id = d.hod_user_id";
$userParams = [];
$userScope = $managedUserScope;

if ($userSearch !== '') {
    $userSql .= " WHERE {$userScope} AND (u.name LIKE ? OR u.email LIKE ? OR u.role LIKE ? OR d.name LIKE ?)";
    $userTerm = '%' . $userSearch . '%';
    $userParams = [$userTerm, $userTerm, $userTerm, $userTerm];
} else {
    $userSql .= " WHERE {$userScope}";
}

$userSql .= " ORDER BY u.name";
$userStmt = $pdo->prepare($userSql);
$userStmt->execute($userParams);
$users = $userStmt->fetchAll();

$departments = getDepartments();
$allUsersStmt = $pdo->prepare("SELECT u.id, u.name FROM users u WHERE " . portalUserCondition('u') . " ORDER BY u.name");
$allUsersStmt->execute();
$allUsers = $allUsersStmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4 users-page-header">
    <h3 class="mb-0">Users &amp; Reporting Hierarchy</h3>
    <a href="escalation_matrix_export.php" class="btn btn-success">Download Excel Sheet</a>
</div>

<?php if (currentUser()['role'] === 'super_admin'): ?>
<a href="sync_users.php" class="btn btn-outline-primary mb-3">🔄 Sync from Microsoft 365</a>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-info"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>
<?php if ($activationToken !== null): ?>
    <div class="alert alert-warning">
        <strong>One-time setup token</strong> — share it only with the verified account holder through a separate trusted channel.
        It expires in 24 hours and is shown only once:
        <code class="d-block text-break mt-1"><?= htmlspecialchars($activationToken, ENT_QUOTES, 'UTF-8') ?></code>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header">Add Employee</div>
    <div class="card-body">
        <p class="text-muted small">A one-time activation token is generated after creation. Verify the recipient's identity before sharing it.</p>
        <form method="POST" class="row g-2">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="add_user">
            <div class="col-md-4">
                <input type="text" name="name" class="form-control" placeholder="Full Name" required>
            </div>
            <div class="col-md-4">
                <input type="email" name="email" class="form-control" placeholder="Email" required>
            </div>
            <div class="col-md-2">
                <select name="role" class="form-select">
                    <option value="user">User</option>
                    <option value="admin">Admin</option>
                    <option value="super_admin">Super Admin</option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="department_id" class="form-select">
                    <option value="">No Department</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mt-2">
                <select name="reporting_manager_id" class="form-select">
                    <option value="">No Reporting Manager</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mt-2">
                <select name="reporting_manager_2_id" class="form-select">
                    <option value="">No 2nd Reporting Manager</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mt-2">
                <button class="btn btn-primary w-100">Add Employee</button>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm users-list-card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2 users-list-header">
        <span>All Users</span>
        <form method="GET" class="d-flex gap-2 users-search-form">
            <input type="search" name="search" class="form-control form-control-sm" placeholder="Search users" value="<?= htmlspecialchars($userSearch) ?>">
            <button type="submit" class="btn btn-sm btn-primary">Search</button>
        </form>
    </div>
    <div class="users-table-scroll">
    <table class="table table-hover mb-0 users-table">
        <thead class="table-light">
            <tr>
                <th>Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Department</th>
                <th>Reporting Manager 1</th>
                <th>Reporting Manager 2</th>
                <th>HOD</th>
                <th>📬 Monitor Mailbox</th>
                <th>Active</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users)): ?>
                <tr class="users-empty-row"><td colspan="10" class="text-center text-muted py-4">No users found.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td data-label="Name"><span class="users-cell-value"><?= htmlspecialchars($u['name']) ?></span></td>
                    <td data-label="Email"><span class="users-cell-value"><?= htmlspecialchars($u['email']) ?></span></td>
                    <td data-label="Role">
                        <?php if (currentUser()['role'] === 'super_admin'): ?>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="update_role">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <select name="role" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="user" <?= $u['role'] === 'user' ? 'selected' : '' ?>>User</option>
                                    <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin (Manager)</option>
                                    <option value="super_admin" <?= $u['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin (Director/HR)</option>
                                </select>
                            </form>
                        <?php else: ?>
                            <span class="badge bg-secondary"><?= htmlspecialchars($u['role']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Department">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="update_department">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <select name="department_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">No Department</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $u['department_id'] == $d['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td data-label="Reporting Manager 1">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="update_manager">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="reporting_manager_2_id" value="<?= $u['reporting_manager_2_id'] ?? '' ?>">
                            <div class="searchable-dropdown">
                                <button type="button" class="form-select form-select-sm text-start" onclick="toggleSearchableDropdown(this)">
                                    <?= htmlspecialchars($u['manager_name'] ?? 'No Manager') ?>
                                </button>
                                <div class="searchable-menu">
                                    <input type="search" class="form-control form-control-sm mb-2" placeholder="Find manager" oninput="filterSearchableOptions(this)">
                                    <div class="searchable-options">
                                        <button type="button" data-value="" onclick="selectSearchableOption(this)">No Manager</button>
                                        <?php foreach ($allUsers as $m): if ($m['id'] == $u['id']) continue; ?>
                                            <button type="button" data-value="<?= $m['id'] ?>" onclick="selectSearchableOption(this)">
                                                <?= htmlspecialchars($m['name']) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="reporting_manager_id" value="<?= $u['reporting_manager_id'] ?? '' ?>">
                            </div>
                        </form>
                    </td>
                    <td data-label="Reporting Manager 2">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="update_manager">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="reporting_manager_id" value="<?= $u['reporting_manager_id'] ?? '' ?>">
                            <div class="searchable-dropdown">
                                <button type="button" class="form-select form-select-sm text-start" onclick="toggleSearchableDropdown(this)">
                                    <?= htmlspecialchars($u['manager_2_name'] ?? 'No 2nd Manager') ?>
                                </button>
                                <div class="searchable-menu">
                                    <input type="search" class="form-control form-control-sm mb-2" placeholder="Find manager" oninput="filterSearchableOptions(this)">
                                    <div class="searchable-options">
                                        <button type="button" data-value="" onclick="selectSearchableOption(this)">No 2nd Manager</button>
                                        <?php foreach ($allUsers as $m): if ($m['id'] == $u['id']) continue; ?>
                                            <button type="button" data-value="<?= $m['id'] ?>" onclick="selectSearchableOption(this)">
                                                <?= htmlspecialchars($m['name']) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <input type="hidden" name="reporting_manager_2_id" value="<?= $u['reporting_manager_2_id'] ?? '' ?>">
                            </div>
                        </form>
                    </td>
                    <td data-label="HOD">
                        <div class="d-flex flex-column gap-1">
                            <span class="badge bg-light text-dark border align-self-start">
                                <?= htmlspecialchars($u['hod_name'] ?? 'No HOD') ?>
                            </span>
                            <a href="settings.php" class="small text-decoration-none">Edit department HOD</a>
                        </div>
                    </td>
                    <td data-label="Monitor Mailbox">
                        <?php if (currentUser()['role'] === 'super_admin'): ?>
                            <form method="POST" onsubmit="<?= empty($u['monitor_mailbox']) ? "return confirm('Turn ON live monitoring of {$u['email']}\\'s mailbox? This is REAL - new customer emails will start creating tickets.');" : '' ?>">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="set_monitor_mailbox">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="monitor" value="<?= $u['monitor_mailbox'] ? 0 : 1 ?>">
                                <button type="submit" class="btn btn-sm <?= $u['monitor_mailbox'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                    <?= $u['monitor_mailbox'] ? '🟢 LIVE' : 'Off' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="badge <?= $u['monitor_mailbox'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['monitor_mailbox'] ? 'LIVE' : 'Off' ?></span>
                        <?php endif; ?>
                    </td>
                    <td data-label="Account Status">
                        <?php if (currentUser()['role'] !== 'super_admin'): ?>
                            <span class="badge <?= $u['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span>
                        <?php else: ?>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="set_active">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="is_active" value="1">
                            <button type="submit" class="btn btn-sm btn-success" <?= $u['is_active'] ? 'disabled' : '' ?>>Active</button>
                        </form>
                        <form method="POST" class="mt-1">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="set_active">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="is_active" value="0">
                            <button type="submit" class="btn btn-sm btn-primary" <?= !$u['is_active'] ? 'disabled' : '' ?>>Inactive</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td data-label="Actions">
                        <?php if (currentUser()['role'] === 'super_admin'): ?>
                        <form method="POST" onsubmit="return confirm('Delete this user permanently?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="form_type" value="delete_user">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger" <?= $u['id'] == $_SESSION['user_id'] ? 'disabled title="You cannot delete your own account"' : '' ?>>Delete</button>
                        </form>
                        <?php endif; ?>
                        <?php if (currentUser()['role'] === 'super_admin' && (int)$u['password_set'] === 0): ?>
                            <form method="POST" class="mt-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="issue_activation">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">Issue setup token</button>
                            </form>
                        <?php elseif (currentUser()['role'] === 'super_admin'): ?>
                            <form method="POST" class="mt-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="issue_password_reset">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        onclick="return confirm('Invalidate this user’s current password and issue a one-time reset token?')">
                                    Reset password
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<style>
.searchable-dropdown { position: relative; }
.searchable-dropdown > button { width: 100%; background-color: #fff; }
.searchable-menu {
    display: none;
    position: absolute;
    z-index: 10;
    top: calc(100% + 3px);
    left: 0;
    width: 220px;
    padding: .5rem;
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 8px;
    box-shadow: var(--shadow-md);
}
.searchable-dropdown.is-open .searchable-menu { display: block; }
.searchable-options { max-height: 180px; overflow-y: auto; }
.searchable-options button {
    display: block;
    width: 100%;
    padding: .4rem .5rem;
    border: 0;
    background: transparent;
    color: var(--ink);
    text-align: left;
    border-radius: 5px;
}
.searchable-options button:hover { background: var(--surface-alt); }
</style>

<script>
function toggleSearchableDropdown(button) {
    const dropdown = button.parentElement;
    document.querySelectorAll('.searchable-dropdown.is-open').forEach(openDropdown => {
        if (openDropdown !== dropdown) openDropdown.classList.remove('is-open');
    });
    dropdown.classList.toggle('is-open');
    if (dropdown.classList.contains('is-open')) {
        dropdown.querySelector('input[type="search"]').focus();
    }
}

function filterSearchableOptions(searchInput) {
    const searchTerm = searchInput.value.toLowerCase();
    searchInput.nextElementSibling.querySelectorAll('button').forEach(option => {
        option.hidden = option.textContent.trim().toLowerCase().indexOf(searchTerm) === -1;
    });
}

function selectSearchableOption(option) {
    const dropdown = option.closest('.searchable-dropdown');
    dropdown.querySelector('input[type="hidden"]').value = option.dataset.value;
    dropdown.querySelector('button').textContent = option.textContent.trim();
    dropdown.classList.remove('is-open');
    dropdown.closest('form').submit();
}

document.addEventListener('click', event => {
    if (!event.target.closest('.searchable-dropdown')) {
        document.querySelectorAll('.searchable-dropdown.is-open').forEach(dropdown => dropdown.classList.remove('is-open'));
    }
});
</script>

<p class="text-muted small mt-3">
    Tip: set each department's HOD from the <a href="settings.php">Settings</a> page.
</p>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
