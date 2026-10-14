<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
requireRole(['super_admin', 'admin']);

$pdo = getDB();

$contacts = $pdo->query(
    "SELECT ec.role_type, ec.name, ec.email, ec.department_id, d.name AS department_name
     FROM escalation_contacts ec
     LEFT JOIN departments d ON d.id = ec.department_id
     ORDER BY ec.role_type, ec.name"
)->fetchAll(PDO::FETCH_ASSOC);

$contactsByDepartment = [];
foreach ($contacts as $contact) {
    $key = $contact['department_id'] === null ? 'all' : (string)$contact['department_id'];
    $contactsByDepartment[$key][] = $contact;
}

$users = $pdo->query(
    "SELECT u.name AS employee_name, u.email AS employee_email, u.role AS employee_role, d.name AS department_name,
            m.name AS manager_name, m.email AS manager_email,
            m2.name AS manager_2_name, m2.email AS manager_2_email,
            h.name AS hod_name, h.email AS hod_email, d.id AS department_id
     FROM users u
     LEFT JOIN departments d ON d.id = u.department_id
     LEFT JOIN users m ON m.id = u.reporting_manager_id
     LEFT JOIN users m2 ON m2.id = u.reporting_manager_2_id
     LEFT JOIN users h ON h.id = d.hod_user_id
     WHERE u.is_active = 1
     ORDER BY d.name, u.name"
)->fetchAll(PDO::FETCH_ASSOC);

$filename = 'escalation-matrix-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'Employee Name',
    'Employee Email',
    'Department',
    'Level 1: First Recipient',
    'Level 2: Manager/HOD Recipients',
    'Level 3: HR/Founder/Admin Recipients',
    'Complete Escalation Flow',
]);

foreach ($users as $user) {
    $formatPerson = static function (?string $name, ?string $email): string {
        if (!$name) {
            return '';
        }
        return $name . ($email ? ' <' . $email . '>' : '');
    };

    $level2 = [];
    $directToLevel3 = in_array($user['employee_role'], ['admin', 'super_admin'], true);
    if (!$directToLevel3) {
        foreach ([
            $formatPerson($user['manager_name'], $user['manager_email']),
            $formatPerson($user['manager_2_name'], $user['manager_2_email']),
            $formatPerson($user['hod_name'], $user['hod_email']),
        ] as $recipient) {
            if ($recipient && !in_array($recipient, $level2, true)) {
                $level2[] = $recipient;
            }
        }
    }

    $level3 = [];
    $departmentKey = $user['department_id'] === null ? '' : (string)$user['department_id'];
    foreach (array_merge($contactsByDepartment['all'] ?? [], $contactsByDepartment[$departmentKey] ?? []) as $contact) {
        $recipient = $contact['name'] . ' <' . $contact['email'] . '>';
        if (!in_array($recipient, $level3, true)) {
            $level3[] = $recipient;
        }
    }

    $level1 = $formatPerson($user['employee_name'], $user['employee_email']);
    $level2Text = $directToLevel3 ? 'Skipped - manager/admin goes directly to Level 3' : implode('; ', $level2);
    $level3Text = implode('; ', $level3);

    fputcsv($output, [
        $user['employee_name'],
        $user['employee_email'],
        $user['department_name'] ?? '',
        $level1,
        $level2Text,
        $level3Text,
        $level1 . ($level2Text ? ' -> ' . $level2Text : '') . ($level3Text ? ' -> ' . $level3Text : ''),
    ]);
}

fclose($output);
exit;
