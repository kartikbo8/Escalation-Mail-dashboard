
<?php

require_once __DIR__ . '/../includes/layout_header.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = getDB();

$deptFilter   = $_GET['department'] ?? '';
$statusFilter = $_GET['status'] ?? 'open';
$searchFilter = trim($_GET['search'] ?? '');
$message = '';

/*
 * ---------------------------------------------------------
 * Email List Filter
 * ---------------------------------------------------------
 *
 * This page shows only keyword-matched emails in the escalation queue.
 */

/* ---------------------------------------------------------
 * Bulk actions
 * --------------------------------------------------------- */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(
        $_POST['action'] ?? '',
        ['stop_selected_triggers', 'close_selected', 'reopen_selected'],
        true
    )
) {
    requireCsrfToken();
    $selectedIds = array_values(
        array_filter(
            array_map('intval', $_POST['ticket_ids'] ?? []),
            static fn($id) => $id > 0
        )
    );

    $action = $_POST['action'];

    if (
        $selectedIds
        && in_array(currentUser()['role'], ['admin', 'super_admin'], true)
    ) {
        $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));

        if ($action === 'stop_selected_triggers') {

            $columns = currentUser()['role'] === 'super_admin'
                ? 'stop_employee_notifications = 1, stop_manager_notifications = 1'
                : 'stop_employee_notifications = 1';

            $stmt = $pdo->prepare(
                "UPDATE tickets
                 SET {$columns}
                                 WHERE id IN ({$placeholders})
                                     AND " . portalTicketCondition('tickets')
            );

            $stmt->execute($selectedIds);

            $message = count($selectedIds) . ' selected trigger(s) stopped.';

        } elseif ($action === 'close_selected') {

            $stmt = $pdo->prepare(
                "UPDATE tickets
                 SET status = 'closed',
                     closed_at = NOW()
                 WHERE id IN ({$placeholders})
                                     AND " . portalTicketCondition('tickets') . "
                   AND status <> 'closed'"
            );

            $stmt->execute($selectedIds);

            foreach ($selectedIds as $ticketId) {
                logTimelineEvent(
                    $ticketId,
                    'closed',
                    'Closed in bulk from Email List',
                    currentUser()['id']
                );
            }

            $message = count($selectedIds) . ' selected ticket(s) closed.';

        } elseif ($action === 'reopen_selected') {

            $stmt = $pdo->prepare(
                "UPDATE tickets
                 SET status = 'open',
                     closed_at = NULL
                 WHERE id IN ({$placeholders})
                                     AND " . portalTicketCondition('tickets') . "
                   AND status = 'closed'"
            );

            $stmt->execute($selectedIds);

            foreach ($selectedIds as $ticketId) {
                logTimelineEvent(
                    $ticketId,
                    'reopened',
                    'Reopened in bulk from Email List',
                    currentUser()['id']
                );
            }

            $message = count($selectedIds) . ' selected ticket(s) reopened.';
        }

    } else {
        $message = 'Please select at least one email.';
    }
}


/* ---------------------------------------------------------
 * Fetch only keyword-matched tickets
 * --------------------------------------------------------- */

$sql = "SELECT
            t.*,
            d.name AS department_name
        FROM tickets t
        LEFT JOIN departments d
            ON d.id = t.department_id
        WHERE " . portalTicketCondition('t');

    $sql .= " AND t.is_important = 1";

$params = [];
$retentionCutoff = (new DateTimeImmutable('now'))->modify('-30 days');
$sql .= " AND (t.received_at >= ? OR t.status IN ('open', 'escalated'))";
$params[] = $retentionCutoff->format('Y-m-d H:i:s');


/* ---------------------------------------------------------
 * Department filter
 * --------------------------------------------------------- */

if ($deptFilter !== '') {
    $sql .= " AND t.department_id = ?";
    $params[] = (int)$deptFilter;
}


/* ---------------------------------------------------------
 * Status filter
 * --------------------------------------------------------- */

if ($statusFilter !== '') {
    $sql .= " AND t.status = ?";
    $params[] = $statusFilter;
} else {
    $sql .= " AND t.status <> 'closed'";
}


/* ---------------------------------------------------------
 * Search filter
 * --------------------------------------------------------- */

if ($searchFilter !== '') {
    $sql .= " AND (
        t.subject LIKE ?
        OR t.sender_name LIKE ?
        OR t.sender_email LIKE ?
    )";

    $searchTerm = '%' . $searchFilter . '%';

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}


/* ---------------------------------------------------------
 * Order newest first
 * --------------------------------------------------------- */

$sql .= " ORDER BY t.received_at DESC";


/* ---------------------------------------------------------
 * Execute query
 * --------------------------------------------------------- */

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$tickets = $stmt->fetchAll();

$departments = getDepartments();
$portalDepartmentIds = portalDepartmentIds();
if ($portalDepartmentIds !== null) {
    $departments = array_values(array_filter(
        $departments,
        static fn($department) => in_array((int)$department['id'], $portalDepartmentIds, true)
    ));
}

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1">Email List</h3>

        <div class="text-muted small">
            <?= count($tickets) ?> keyword-matched email(s) found
            · Last 30 days · Keyword-matched emails only
        </div>
    </div>
</div>


<?php if ($message): ?>

    <div class="alert alert-info">
        <?= htmlspecialchars($message) ?>
    </div>

<?php endif; ?>


<!-- Filters -->

<form method="GET" class="row g-2 mb-3">

    <!-- Search -->

    <div class="col-md-4">
        <div class="input-group">

            <input
                type="search"
                name="search"
                class="form-control"
                placeholder="Search subject or sender"
                value="<?= htmlspecialchars($searchFilter) ?>"
            >

            <button
                type="submit"
                class="btn btn-primary"
            >
                Search
            </button>

        </div>
    </div>


    <!-- Department -->

    <div class="col-auto">

        <select
            name="department"
            class="form-select"
            onchange="this.form.submit()"
        >

            <option value="">
                All Departments
            </option>

            <?php foreach ($departments as $d): ?>
            
                <option
                    value="<?= (int)$d['id'] ?>"
                    <?= $deptFilter == $d['id'] ? 'selected' : '' ?>
                >
                    <?= htmlspecialchars($d['name']) ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>


    <!-- Status -->

    <div class="col-auto">

        <select
            name="status"
            class="form-select"
            onchange="this.form.submit()"
        >

            <option value="">
                All Statuses
            </option>

            <?php foreach (
                ['open', 'replied', 'closed', 'escalated'] as $s
            ): ?>

                <option
                    value="<?= htmlspecialchars($s) ?>"
                    <?= $statusFilter === $s ? 'selected' : '' ?>
                >
                    <?= ucfirst($s) ?>
                </option>

            <?php endforeach; ?>

        </select>

    </div>

    <!-- Keyword filter indicator -->

    <div class="col-auto">

        <div class="form-control bg-light text-muted">
            Keyword-matched emails only
        </div>

    </div>


</form>


<!-- Email table -->

<form
    method="POST"
    class="card shadow-sm"
    id="bulk-email-form"
>

    <div class="table-responsive">

        <table class="table table-hover mb-0 align-middle email-list-table">

            <colgroup>
                <col style="width: 40px;">
                <col style="width: 300px;">
                <col style="width: 190px;">
                <col style="width: 120px;">
                <col style="width: 120px;">
                <col style="width: 155px;">
                <col style="width: 155px;">
                <col style="width: 90px;">
            </colgroup>

            <thead class="table-light">

                <tr>

                    <th>

                        <input
                            type="checkbox"
                            id="select-all-emails"
                            title="Select all keyword-matched emails"
                        >

                    </th>

                    <th>Subject</th>
                    <th>From</th>
                    <th>Department</th>
                    <th>Priority</th>
                    <th>Received</th>
                    <th>SLA Due</th>
                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php if (empty($tickets)): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center text-muted py-5"
                        >
                            No keyword-matched emails found.
                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($tickets as $t): ?>

                    <tr
                        onclick="window.location='email_detail.php?id=<?= (int)$t['id'] ?>'"
                        style="cursor:pointer;"
                    >

                        <!-- Checkbox -->

                        <td onclick="event.stopPropagation();">

                            <input
                                type="checkbox"
                                name="ticket_ids[]"
                                value="<?= (int)$t['id'] ?>"
                                class="email-checkbox"
                            >

                        </td>


                        <!-- Subject -->

                        <td>

                            <?php if (!empty($t['is_important'])): ?>

                                <span
                                    title="Keyword matched - in escalation queue"
                                    aria-label="Keyword matched"
                                >
                                    ⚠️
                                </span>

                            <?php endif; ?>


                            <?= htmlspecialchars(
                                $t['subject'] ?: '(No subject)'
                            ) ?>


                            <?php if (!empty($t['matched_keyword'])): ?>

                                <div class="small text-muted">

                                    Keyword:

                                    <strong>
                                        <?= htmlspecialchars(
                                            $t['matched_keyword']
                                        ) ?>
                                    </strong>

                                </div>

                            <?php endif; ?>

                        </td>


                        <!-- From -->

                        <td>

                            <?= htmlspecialchars(
                                $t['sender_email'] ?: '-'
                            ) ?>

                        </td>


                        <!-- Department -->

                        <td>

                            <?= htmlspecialchars(
                                $t['department_name'] ?: 'Unassigned'
                            ) ?>

                        </td>


                        <!-- Priority -->

                        <td>

                            <?= priorityBadge(
                                $t['priority_tag'] ?? 'normal'
                            ) ?>

                        </td>


                        <!-- Received -->

                        <td>

                            <?= !empty($t['received_at'])
                                ? htmlspecialchars(date('d M Y, h:i A', strtotime($t['received_at'])))
                                : '-' ?>

                        </td>


                        <!-- SLA Due -->

                        <td>

                            <?= !empty($t['sla_due_at'])
                                ? htmlspecialchars(date('d M Y, h:i A', strtotime($t['sla_due_at'])))
                                : '-' ?>

                        </td>


                        <!-- Status -->

                        <td>

                            <?= statusBadge(
                                $t['status']
                            ) ?>

                            <?php if ((int)($t['escalation_level'] ?? 0) === 1): ?>
                                <span class="badge bg-danger mt-1">Level 2 Escalated</span>
                            <?php elseif ((int)($t['escalation_level'] ?? 0) >= 2): ?>
                                <span class="badge bg-dark mt-1">Level 3 Escalated</span>
                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

            </tbody>

        </table>

    </div>


    <!-- Bulk actions -->

    <div
        class="p-3 border-top d-flex justify-content-end flex-wrap gap-2"
    >

        <button
            type="submit"
            name="action"
            value="stop_selected_triggers"
            class="btn btn-danger"
            onclick="return confirm('Stop trigger mails for all selected emails?');"
        >
            Stop Selected Trigger Mails
        </button>


        <button
            type="submit"
            name="action"
            value="close_selected"
            class="btn btn-success"
            onclick="return confirm('Close all selected tickets?');"
        >
            Close Selected Tickets
        </button>


        <button
            type="submit"
            name="action"
            value="reopen_selected"
            class="btn btn-outline-primary"
            onclick="return confirm('Reopen all selected closed tickets?');"
        >
            Open Selected Tickets
        </button>

    </div>

</form>


<script>

(function () {

    const selectAll =
        document.getElementById('select-all-emails');


    if (!selectAll) {
        return;
    }


    /*
     * Select / deselect all visible keyword-matched emails
     */

    selectAll.addEventListener('change', function () {

        document
            .querySelectorAll('.email-checkbox')
            .forEach(function (checkbox) {

                checkbox.checked = selectAll.checked;

            });

    });


    /*
     * Automatically update Select All checkbox
     */

    document
        .querySelectorAll('.email-checkbox')
        .forEach(function (checkbox) {

            checkbox.addEventListener(
                'change',
                function () {

                    const checkboxes =
                        Array.from(
                            document.querySelectorAll(
                                '.email-checkbox'
                            )
                        );


                    const allChecked =
                        checkboxes.length > 0 &&
                        checkboxes.every(
                            function (item) {
                                return item.checked;
                            }
                        );


                    selectAll.checked = allChecked;

                }
            );

        });

})();

</script>


<?php

require_once __DIR__ . '/../includes/layout_footer.php';

?>
```
