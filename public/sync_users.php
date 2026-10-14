<?php
/**
 * public/sync_users.php
 *
 * Pulls every employee (name, email, and manager if set in Azure AD/Entra)
 * from Microsoft 365 via Graph API and creates/updates matching rows in the
 * local `users` table. Saves manually typing every employee.
 *
 * Requires: "User.Read.All" Application permission + Admin Consent granted
 * in Azure (same place you granted Mail.Read/Mail.Send earlier).
 *
 * HOD assignment still happens manually from Settings - Azure AD doesn't
 * have a native "HOD" concept, only manager chains.
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin']); // bulk directory sync is a powerful action - Director/HR tier only
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';

$pdo = getDB();
$log = [];
$created = 0;
$updated = 0;
$managersLinked = 0;
$skipped = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    requireCsrfToken();

    $accessToken = graphGetServiceToken();

    if (!$accessToken) {
        $log[] = "FAILED to get an access token. Check config/graph.php and Azure Admin Consent.";
    } else {
        $azureUsers = graphListAllUsers($accessToken);

        if (isset($azureUsers['error'])) {
            $log[] = "FAILED to list users: " . ($azureUsers['error']['message'] ?? json_encode($azureUsers['error']));
            $log[] = "Common cause: 'User.Read.All' Application permission missing or not admin-consented yet.";
        } else {
            $log[] = "Found " . count($azureUsers) . " enabled member accounts in Microsoft 365.";

            // Departments, for loose name matching (Azure AD 'department' field -> our departments table)
            $depts = getDepartments();
            $deptByName = [];
            foreach ($depts as $d) {
                $deptByName[strtolower($d['name'])] = $d['id'];
            }

            // ---- Pass 1: create or update every user by email ----
            $emailToLocalId = [];

            foreach ($azureUsers as $au) {
                $email = strtolower($au['mail'] ?: $au['userPrincipalName']);
                $name  = $au['displayName'] ?: $email;
                $deptId = null;

                if (!empty($au['department']) && isset($deptByName[strtolower($au['department'])])) {
                    $deptId = $deptByName[strtolower($au['department'])];
                }

                $existing = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $existing->execute([$email]);
                $row = $existing->fetch();

                if ($row) {
                    $pdo->prepare("UPDATE users SET name = ?, department_id = COALESCE(?, department_id) WHERE id = ?")
                        ->execute([$name, $deptId, $row['id']]);
                    $emailToLocalId[$email] = $row['id'];
                    $updated++;
                } else {
                    // Random unusable password - these accounts are for tracking/notifications;
                    // give them dashboard access later via Users page if they need to log in.
                    $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                    $pdo->prepare(
                        "INSERT INTO users (name, email, password_hash, password_set, role, department_id) VALUES (?, ?, ?, 0, 'user', ?)"
                    )->execute([$name, $email, $randomPassword, $deptId]);
                    $emailToLocalId[$email] = $pdo->lastInsertId();
                    $created++;
                }
            }

            $log[] = "{$created} new employee(s) created, {$updated} existing updated.";

            // ---- Pass 2: link reporting managers (needs everyone to exist first) ----
            foreach ($azureUsers as $au) {
                $email = strtolower($au['mail'] ?: $au['userPrincipalName']);
                $azureId = $au['id'];

                $manager = graphGetUserManager($accessToken, $azureId);

                if ($manager && !empty($manager['email'])) {
                    $managerEmail = strtolower($manager['email']);
                    if (isset($emailToLocalId[$managerEmail]) && isset($emailToLocalId[$email])) {
                        $pdo->prepare("UPDATE users SET reporting_manager_id = ? WHERE id = ?")
                            ->execute([$emailToLocalId[$managerEmail], $emailToLocalId[$email]]);
                        $managersLinked++;
                    } else {
                        $skipped++;
                    }
                }
            }

            $log[] = "{$managersLinked} reporting-manager link(s) set automatically (from Azure AD's Manager field).";
            if ($skipped > 0) {
                $log[] = "{$skipped} manager relationship(s) skipped - manager account not synced/found locally.";
            }
            $log[] = "Note: HOD per department is NOT in Azure AD - set that manually from the Settings page.";
        }
    }
}

require_once __DIR__ . '/../includes/layout_header.php';
?>

<h3 class="mb-4">Sync Employees from Microsoft 365</h3>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <p>
            This pulls every enabled employee account from AFC's Microsoft 365 directory
            (name, email, and their manager - if set in Azure AD) and creates/updates
            matching rows in the <a href="users.php">Users</a> table. No mail is read or sent -
            this only reads directory profile information.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="sync">
            <button type="submit" class="btn btn-primary">Sync Now</button>
        </form>
    </div>
</div>

<?php if (!empty($log)): ?>
<div class="card shadow-sm">
    <div class="card-header">Sync Result</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($log as $line): ?>
            <li class="list-group-item"><?= htmlspecialchars($line) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<p class="mt-3"><a href="users.php">&rarr; Go to Users page to review</a></p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_footer.php'; ?>
