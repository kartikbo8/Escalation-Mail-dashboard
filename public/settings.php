<?php
require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['super_admin']); // Settings = company-wide policy (SLA, HOD, keywords, mailboxes) - Director/HR tier only

$pdo = getDB();
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'sla') {
        foreach ($_POST['sla'] as $deptId => $hours) {
            $stmt = $pdo->prepare("UPDATE sla_settings SET response_hours = ? WHERE department_id = ?");
            $stmt->execute([(int)$hours, (int)$deptId]);

            // If no row existed for this department yet (e.g. a department added
            // after the initial seed), the UPDATE above silently affects 0 rows -
            // create the row instead so the value actually saves.
            if ($stmt->rowCount() === 0) {
                $exists = $pdo->prepare("SELECT id FROM sla_settings WHERE department_id = ?");
                $exists->execute([(int)$deptId]);
                if (!$exists->fetch()) {
                    $pdo->prepare("INSERT INTO sla_settings (department_id, response_hours) VALUES (?, ?)")
                        ->execute([(int)$deptId, (int)$hours]);
                }
            }
        }
        $message = 'SLA settings updated.';
    }

    if ($formType === 'working_hours') {
        foreach ($_POST['working'] as $dow => $vals) {
            $isWorking = isset($vals['is_working']) ? 1 : 0;
            $stmt = $pdo->prepare(
                "UPDATE working_hours SET is_working_day=?, start_time=?, end_time=? WHERE day_of_week=?"
            );
            $stmt->execute([$isWorking, $vals['start'], $vals['end'], (int)$dow]);
        }
        $message = 'Working hours updated.';
    }

    if ($formType === 'add_holiday') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO holidays (holiday_date, name) VALUES (?, ?)");
        $stmt->execute([$_POST['holiday_date'], $_POST['holiday_name']]);
        $message = 'Holiday added.';
    }

    if ($formType === 'delete_holiday') {
        $pdo->prepare("DELETE FROM holidays WHERE id = ?")->execute([(int)$_POST['holiday_id']]);
        $message = 'Holiday removed.';
    }

    if ($formType === 'add_contact') {
        $stmt = $pdo->prepare(
            "INSERT INTO escalation_contacts (role_type, name, email, department_id) VALUES (?, ?, ?, ?)"
        );
        $deptId = $_POST['contact_department'] !== '' ? $_POST['contact_department'] : null;
        $stmt->execute([$_POST['contact_role'], $_POST['contact_name'], $_POST['contact_email'], $deptId]);
        $message = 'Contact added.';
    }

    if ($formType === 'delete_contact') {
        $pdo->prepare("DELETE FROM escalation_contacts WHERE id = ?")->execute([(int)$_POST['contact_id']]);
        $message = 'Contact removed.';
    }

    if ($formType === 'add_keyword') {
        $stmt = $pdo->prepare("INSERT IGNORE INTO keyword_categories (category, keyword) VALUES (?, ?)");
        $stmt->execute([$_POST['keyword_category'], trim($_POST['keyword_text'])]);
        $message = 'Keyword added.';
    }

    if ($formType === 'delete_keyword') {
        $pdo->prepare("DELETE FROM keyword_categories WHERE id = ?")->execute([(int)$_POST['keyword_id']]);
        $message = 'Keyword removed.';
    }

    if ($formType === 'set_hod') {
        $hodId = $_POST['hod_user_id'] !== '' ? $_POST['hod_user_id'] : null;
        $pdo->prepare("UPDATE departments SET hod_user_id = ? WHERE id = ?")
            ->execute([$hodId, (int)$_POST['department_id']]);
        $message = 'HOD updated.';
    }

    if ($formType === 'save_group') {
        $groupEmail = strtolower(trim((string)($_POST['group_email'] ?? '')));
        $ownerId = (int)($_POST['owner_user_id'] ?? 0);
        $managerId = (int)($_POST['manager_user_id'] ?? 0) ?: null;
        $validUser = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1");
        $validUser->execute([$ownerId]);
        $ownerOk = (int)$validUser->fetchColumn() === 1;
        $managerOk = true;
        if ($managerId) {
            $validUser->execute([$managerId]);
            $managerOk = (int)$validUser->fetchColumn() === 1;
        }
        if (!filter_var($groupEmail, FILTER_VALIDATE_EMAIL) || !$ownerOk || !$managerOk) {
            $message = 'Group not saved: enter a valid group email and choose an active owner (and manager).';
        } else {
            $pdo->prepare(
                "INSERT INTO group_mailboxes (email, owner_user_id, manager_user_id) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE owner_user_id = VALUES(owner_user_id), manager_user_id = VALUES(manager_user_id)"
            )->execute([$groupEmail, $ownerId, $managerId]);
            $message = "Group {$groupEmail} saved. New mails to this group go to its owner.";
        }
    }

    if ($formType === 'delete_group') {
        $pdo->prepare("DELETE FROM group_mailboxes WHERE id = ?")->execute([(int)$_POST['group_id']]);
        $message = 'Group removed.';
    }

    if ($formType === 'assign_group_tickets') {
        // Move this group's still-open tickets (not yet escalated) to the owner.
        $groupStmt = $pdo->prepare(
            "SELECT g.email, g.owner_user_id, u.name AS owner_name
             FROM group_mailboxes g JOIN users u ON u.id = g.owner_user_id WHERE g.id = ?"
        );
        $groupStmt->execute([(int)$_POST['group_id']]);
        $group = $groupStmt->fetch();
        if ($group) {
            $ticketStmt = $pdo->prepare(
                "SELECT id FROM tickets
                 WHERE status = 'open' AND escalation_level = 0 AND assigned_to <> ?
                   AND LOWER(COALESCE(to_recipients_json, '')) LIKE ?"
            );
            $ticketStmt->execute([(int)$group['owner_user_id'], '%"' . strtolower($group['email']) . '"%']);
            $ticketIds = $ticketStmt->fetchAll(PDO::FETCH_COLUMN);
            $assign = $pdo->prepare("UPDATE tickets SET assigned_to = ? WHERE id = ?");
            foreach ($ticketIds as $ticketId) {
                $assign->execute([(int)$group['owner_user_id'], (int)$ticketId]);
                logTimelineEvent((int)$ticketId, 'received',
                    "Assigned to {$group['owner_name']} - owner of group {$group['email']}", currentUser()['id']);
            }
            $message = count($ticketIds) . " open ticket(s) of {$group['email']} assigned to {$group['owner_name']}.";
        }
    }

    if ($formType === 'set_mailbox') {
        $mailbox = trim($_POST['mailbox_email']) !== '' ? trim($_POST['mailbox_email']) : null;
        $pdo->prepare("UPDATE departments SET mailbox_email = ? WHERE id = ?")
            ->execute([$mailbox, (int)$_POST['department_id']]);
        $message = 'Department mailbox updated.';
    }
}

$departments  = getDepartments();
$slaSettings  = executePreparedQuery($pdo, "SELECT * FROM sla_settings")->fetchAll(PDO::FETCH_ASSOC);
$slaByDept    = array_column($slaSettings, 'response_hours', 'department_id');
$workingHours = executePreparedQuery($pdo, "SELECT * FROM working_hours ORDER BY day_of_week")->fetchAll();
$holidays     = executePreparedQuery($pdo, "SELECT * FROM holidays ORDER BY holiday_date")->fetchAll();
$contacts     = executePreparedQuery($pdo,
    "SELECT ec.*, d.name as department_name FROM escalation_contacts ec
     LEFT JOIN departments d ON d.id = ec.department_id ORDER BY ec.role_type"
)->fetchAll();
$keywords     = executePreparedQuery($pdo, "SELECT * FROM keyword_categories ORDER BY category, keyword")->fetchAll();
$allUsers     = executePreparedQuery($pdo, "SELECT id, name FROM users ORDER BY name")->fetchAll();
$activeUsers  = executePreparedQuery($pdo, "SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name")->fetchAll();
$groupMailboxes = executePreparedQuery($pdo,
    "SELECT g.*, o.name AS owner_name, m.name AS manager_name,
            (SELECT COUNT(*) FROM tickets t
             WHERE t.status = 'open' AND t.escalation_level = 0 AND t.assigned_to <> g.owner_user_id
               AND LOWER(COALESCE(t.to_recipients_json, '')) LIKE CONCAT('%\"', LOWER(g.email), '\"%')) AS open_elsewhere
     FROM group_mailboxes g
     JOIN users o ON o.id = g.owner_user_id
     LEFT JOIN users m ON m.id = g.manager_user_id
     ORDER BY g.email"
)->fetchAll();
$deptWithHod  = executePreparedQuery($pdo,
    "SELECT d.*, u.name as hod_name FROM departments d LEFT JOIN users u ON u.id = d.hod_user_id ORDER BY d.name"
)->fetchAll();

$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$keywordCategoryLabels = [
    'customer_project'   => 'Customer/Project (High Priority)',
    'ppc_internal'       => 'PPC/Internal',
    'escalation_trigger' => 'Urgent (badge shows the matched word)',
];
?>

<h3 class="mb-4">Settings</h3>

<?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- SLA Settings -->
<div class="card shadow-sm mb-4">
    <div class="card-header">SLA (Response Hours per Department)</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="sla">
            <div class="row g-3">
                <?php foreach ($departments as $d): ?>
                    <div class="col-md-4">
                        <label class="form-label"><?= htmlspecialchars($d['name']) ?></label>
                        <input type="number" min="1" class="form-control"
                               name="sla[<?= $d['id'] ?>]"
                               value="<?= $slaByDept[$d['id']] ?? 48 ?>">
                    </div>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary mt-3">Save SLA Settings</button>
        </form>
    </div>
</div>

<!-- Department Mailboxes - which live Outlook mailbox the cron reads/sends from -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Department Mailboxes (Outlook connection)</div>
    <div class="card-body">
        <p class="text-muted small">
            Only departments with a mailbox set here will be checked by the cron scripts -
            everything else stays completely untouched. Leave blank until you're ready to
            go live for that department.
        </p>
        <table class="table">
            <thead><tr><th>Department</th><th>Connected Mailbox</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td colspan="2">
                            <form method="POST" class="d-flex gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="set_mailbox">
                                <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                                <input type="email" name="mailbox_email" class="form-control form-control-sm"
                                       placeholder="e.g. sales@afcindia.in" value="<?= htmlspecialchars($d['mailbox_email'] ?? '') ?>">
                                <button class="btn btn-sm btn-outline-primary">Save</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Working Hours -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Working Hours</div>
    <div class="card-body">
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="working_hours">
            <table class="table">
                <thead><tr><th>Day</th><th>Working?</th><th>Start</th><th>End</th></tr></thead>
                <tbody>
                <?php foreach ($workingHours as $wh): $dow = $wh['day_of_week']; ?>
                    <tr>
                        <td><?= $dayNames[$dow] ?></td>
                        <td>
                            <input type="checkbox" name="working[<?= $dow ?>][is_working]"
                                   <?= $wh['is_working_day'] ? 'checked' : '' ?>>
                        </td>
                        <td>
                            <input type="time" class="form-control form-control-sm"
                                   name="working[<?= $dow ?>][start]" value="<?= $wh['start_time'] ?>">
                        </td>
                        <td>
                            <input type="time" class="form-control form-control-sm"
                                   name="working[<?= $dow ?>][end]" value="<?= $wh['end_time'] ?>">
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <button class="btn btn-primary">Save Working Hours</button>
        </form>
    </div>
</div>

<!-- Holidays -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Holidays</div>
    <div class="card-body">
        <form method="POST" class="row g-2 mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="add_holiday">
            <div class="col-auto">
                <input type="date" name="holiday_date" class="form-control" required>
            </div>
            <div class="col-auto">
                <input type="text" name="holiday_name" class="form-control" placeholder="Holiday name" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">Add Holiday</button>
            </div>
        </form>
        <ul class="list-group">
            <?php foreach ($holidays as $h): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <?= $h['holiday_date'] ?> - <?= htmlspecialchars($h['name']) ?>
                    <form method="POST" class="m-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_type" value="delete_holiday">
                        <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<!-- Founder/HR Contacts -->
<div class="card shadow-sm mb-4">
    <div class="card-header">HR / Admin Escalation Contacts</div>
    <div class="card-body">
        <form method="POST" class="row g-2 mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="add_contact">
            <div class="col-auto">
                <select name="contact_role" class="form-select" required>
                    <option value="founder">Founder</option>
                    <option value="hr">HR</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="contact_name" class="form-control" placeholder="Name" required>
            </div>
            <div class="col-auto">
                <input type="email" name="contact_email" class="form-control" placeholder="Email" required>
            </div>
            <div class="col-auto">
                <select name="contact_department" class="form-select">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">Add Contact</button>
            </div>
        </form>
        <ul class="list-group">
            <?php foreach ($contacts as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span class="badge bg-secondary me-2"><?= ucfirst($c['role_type']) ?></span>
                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['email']) ?>)
                    - <?= htmlspecialchars($c['department_name'] ?? 'All Departments') ?>
                    <form method="POST" class="m-0">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_type" value="delete_contact">
                        <input type="hidden" name="contact_id" value="<?= $c['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<!-- Group mail owners (distribution lists like northdesign@, orderlogin@) -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Group Mail Owners</div>
    <div class="card-body">
        <p class="text-muted small">
            A mail sent to a group address reaches every member's mailbox, but the tracker makes one ticket.
            It is assigned to the group <strong>owner</strong>; the Level 1 reminder goes to the owner and the
            group <strong>manager</strong>, and the manager is also included at Level 2. A reply from any AFC
            member on the thread closes it.
        </p>
        <form method="POST" class="row g-2 mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="save_group">
            <div class="col-md-4">
                <input type="email" name="group_email" class="form-control" placeholder="Group email, e.g. northdesign@afcindia.in" required>
            </div>
            <div class="col-md-3">
                <select name="owner_user_id" class="form-select" required>
                    <option value="">-- Owner --</option>
                    <?php foreach ($activeUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="manager_user_id" class="form-select">
                    <option value="">-- Manager (optional) --</option>
                    <?php foreach ($activeUsers as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100">Save Group</button>
            </div>
        </form>
        <?php if (!$groupMailboxes): ?>
            <div class="text-muted small">No groups configured yet - group mails go to whichever member's mailbox is read first.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Group</th><th>Owner</th><th>Manager</th><th>Open tickets with someone else</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($groupMailboxes as $g): ?>
                            <tr>
                                <td><?= htmlspecialchars($g['email']) ?></td>
                                <td><?= htmlspecialchars($g['owner_name']) ?></td>
                                <td><?= htmlspecialchars($g['manager_name'] ?? 'Not set') ?></td>
                                <td>
                                    <?= (int)$g['open_elsewhere'] ?>
                                    <?php if ((int)$g['open_elsewhere'] > 0): ?>
                                        <form method="POST" class="d-inline ms-2">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="form_type" value="assign_group_tickets">
                                            <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                                            <button class="btn btn-sm btn-outline-secondary"
                                                onclick="return confirm('Assign these open tickets (not yet escalated) to <?= htmlspecialchars($g['owner_name'], ENT_QUOTES) ?>?');">
                                                Assign to owner
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <form method="POST" class="m-0">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="form_type" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Department Heads (HOD) - used for Level 2 escalation -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Department Heads (HOD) - Level 2 Escalation</div>
    <div class="card-body">
        <table class="table">
            <thead><tr><th>Department</th><th>Current HOD</th><th>Change</th></tr></thead>
            <tbody>
                <?php foreach ($deptWithHod as $d): ?>
                    <tr>
                        <td><?= htmlspecialchars($d['name']) ?></td>
                        <td><?= htmlspecialchars($d['hod_name'] ?? 'Not set') ?></td>
                        <td>
                            <form method="POST" class="d-flex gap-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="form_type" value="set_hod">
                                <input type="hidden" name="department_id" value="<?= $d['id'] ?>">
                                <select name="hod_user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">-- None --</option>
                                    <?php foreach ($allUsers as $u): ?>
                                        <option value="<?= $u['id'] ?>" <?= $d['hod_user_id'] == $u['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($u['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="text-muted small mb-0">
            Each employee's Reporting Manager is set from the <a href="users.php">Users</a> page.
            Level 2 notifies both the employee's Reporting Manager AND their department's HOD.
        </p>
    </div>
</div>

<!-- Keyword Categories - used to classify/tag incoming emails -->
<div class="card shadow-sm mb-4">
    <div class="card-header">Keyword Categories (Email Classification)</div>
    <div class="card-body">
        <form method="POST" class="row g-2 mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="form_type" value="add_keyword">
            <div class="col-auto">
                <select name="keyword_category" class="form-select" required>
                    <?php foreach ($keywordCategoryLabels as $key => $label): ?>
                        <option value="<?= $key ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <input type="text" name="keyword_text" class="form-control" placeholder="Keyword or phrase" required>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">Add Keyword</button>
            </div>
        </form>

        <?php foreach ($keywordCategoryLabels as $catKey => $catLabel): ?>
            <h6 class="mt-3"><?= htmlspecialchars($catLabel) ?></h6>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <?php foreach ($keywords as $k): if ($k['category'] !== $catKey) continue; ?>
                    <form method="POST" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="form_type" value="delete_keyword">
                        <input type="hidden" name="keyword_id" value="<?= $k['id'] ?>">
                        <button class="btn btn-sm btn-outline-secondary">
                            <?= htmlspecialchars($k['keyword']) ?> &times;
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
