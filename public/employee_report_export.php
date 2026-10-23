<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole(['admin', 'super_admin']);
require_once __DIR__ . '/../includes/functions.php';

$employeeId = filter_var($_GET['employee_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$format = $_GET['format'] ?? '';
if ($employeeId === false || !in_array($format, ['csv', 'xlsx'], true)) {
    http_response_code(400);
    exit('Select an employee and a supported report format.');
}

$user = currentUser();
$employeeSql = "SELECT u.id, u.name, u.department_id, d.name AS department_name
                FROM users u
                JOIN departments d ON d.id = u.department_id
                WHERE u.id = ? AND u.role = 'user'";
$employeeParams = [(int)$employeeId];
if ($user['role'] !== 'super_admin') {
    if (empty($user['department_id'])) {
        http_response_code(403);
        exit('You do not have access to this employee report.');
    }
    $employeeSql .= ' AND u.department_id = ?';
    $employeeParams[] = (int)$user['department_id'];
}
$employeeStmt = getDB()->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    http_response_code(404);
    exit('Employee not found or unavailable to your account.');
}

$ticketStmt = getDB()->prepare(
    "SELECT t.id, t.subject, t.sender_email, t.received_at, t.status, t.escalation_level,
            t.sla_due_at, t.replied_at,
            COALESCE(activity.reminder_count, 0) AS reminder_count,
            COALESCE(activity.escalation_count, 0) AS escalation_count
     FROM tickets t
     LEFT JOIN (
         SELECT te.ticket_id,
                SUM(te.event_type = 'no_reply_warning'
                    AND te.note LIKE 'Reminder included in consolidated digest%') AS reminder_count,
                SUM(te.event_type = 'escalated') AS escalation_count
         FROM timeline_events te
         JOIN tickets report_ticket ON report_ticket.id = te.ticket_id
         WHERE report_ticket.assigned_to = ?
         GROUP BY te.ticket_id
     ) activity ON activity.ticket_id = t.id
     WHERE t.assigned_to = ?
     ORDER BY t.received_at, t.id"
);
$ticketStmt->execute([(int)$employee['id'], (int)$employee['id']]);
$tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = ['open' => 0, 'replied' => 0, 'closed' => 0, 'escalated' => 0];
foreach ($tickets as $ticket) {
    if (isset($statusCounts[$ticket['status']])) {
        $statusCounts[$ticket['status']]++;
    }
}

$rows = [
    ['Employee Ticket Report'],
    ['Employee', $employee['name']],
    ['Department', $employee['department_name']],
    ['Generated At', date('Y-m-d H:i:s')],
    ['Scope', 'All currently retained tickets assigned to this employee'],
    ['Total Tickets', count($tickets)],
    ['Open', $statusCounts['open']],
    ['Replied', $statusCounts['replied']],
    ['Closed', $statusCounts['closed']],
    ['Escalated', $statusCounts['escalated']],
    [],
    [
        'Ticket ID',
        'Subject',
        'Sender Email',
        'Received At',
        'Current Status',
        'Escalation Level',
        'SLA Due At',
        'Replied At',
        'Reminders Sent',
        'Escalation Events',
    ],
];
foreach ($tickets as $ticket) {
    $rows[] = [
        (int)$ticket['id'],
        (string)$ticket['subject'],
        (string)$ticket['sender_email'],
        (string)$ticket['received_at'],
        (string)$ticket['status'],
        (int)$ticket['escalation_level'] + 1,
        (string)($ticket['sla_due_at'] ?? ''),
        (string)($ticket['replied_at'] ?? ''),
        (int)$ticket['reminder_count'],
        (int)$ticket['escalation_count'],
    ];
}

$safeEmployeeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $employee['name']);
$filename = 'employee-report-' . trim($safeEmployeeName, '-') . '-' . date('Y-m-d');
header('Cache-Control: no-store, private');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new RuntimeException('Unable to open employee report output.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        $values = [];
        foreach ($row as $value) {
            $value = (string)$value;
            if (preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
            $values[] = $value;
        }
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}

if (!class_exists('ZipArchive')) {
    throw new RuntimeException('Excel export requires the PHP ZipArchive extension.');
}

$columnName = static function (int $number): string {
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
};
$sheetRows = [];
foreach ($rows as $rowIndex => $row) {
    $cells = [];
    foreach (array_values($row) as $columnIndex => $value) {
        $reference = $columnName($columnIndex + 1) . ($rowIndex + 1);
        $text = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $cells[] = '<c r="' . $reference . '" t="inlineStr"><is><t xml:space="preserve">'
            . $text . '</t></is></c>';
    }
    $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
}
$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<cols>'
    . '<col min="1" max="1" width="14" customWidth="1"/>'
    . '<col min="2" max="2" width="40" customWidth="1"/>'
    . '<col min="3" max="3" width="32" customWidth="1"/>'
    . '<col min="4" max="4" width="22" customWidth="1"/>'
    . '<col min="5" max="5" width="18" customWidth="1"/>'
    . '<col min="6" max="6" width="18" customWidth="1"/>'
    . '<col min="7" max="8" width="22" customWidth="1"/>'
    . '<col min="9" max="10" width="20" customWidth="1"/>'
    . '</cols>'
    . '<sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';

$tempPath = tempnam(sys_get_temp_dir(), 'employee-report-');
if ($tempPath === false) {
    throw new RuntimeException('Unable to create a temporary Excel report.');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create the Excel report archive.');
    }
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>'
    );
    $zip->addFromString(
        '_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
    );
    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Employee Report" sheetId="1" r:id="rId1"/></sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '</Relationships>'
    );
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize the Excel report archive.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Content-Length: ' . filesize($tempPath));
    readfile($tempPath);
} finally {
    if (is_file($tempPath)) {
        unlink($tempPath);
    }
}
