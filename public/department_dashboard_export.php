<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/functions.php';

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Excel export requires the PHP ZipArchive extension.');
}

$pdo = getDB();
$reportDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');
$reportStart = $reportDate . ' 00:00:00';
$reportEnd = (new DateTimeImmutable($reportDate))->modify('+1 day')->format('Y-m-d 00:00:00');
$departmentStmt = $pdo->query(
    "SELECT d.id, d.name
     FROM departments d
     WHERE " . portalDepartmentCondition('d') . "
     ORDER BY d.name"
);
$departments = $departmentStmt->fetchAll(PDO::FETCH_ASSOC);

$emptyCounts = static function (): array {
    return [
        'total' => 0,
        'replied' => 0,
        'open' => 0,
        'l1' => 0,
        'l1_replied' => 0,
        'l1_open' => 0,
        'l2' => 0,
        'l2_replied' => 0,
        'l2_open' => 0,
        'l3' => 0,
        'l3_replied' => 0,
        'l3_open' => 0,
        'no_keyword' => 0,
        'no_keyword_replied' => 0,
        'no_keyword_open' => 0,
        'matched_no_reminder' => 0,
        'matched_no_reminder_replied' => 0,
        'matched_no_reminder_open' => 0,
    ];
};
$departmentCounts = [];
$departmentNames = [];
$grandTotalCounts = $emptyCounts();
foreach ($departments as $department) {
    $departmentId = (int)$department['id'];
    $departmentNames[$departmentId] = $department['name'];
    $departmentCounts[$departmentId] = $emptyCounts();
}
$employeeCounts = [];

$ticketStmt = $pdo->prepare(
    "SELECT t.department_id, t.assigned_to, t.status,
            COALESCE(t.escalation_level, 0) AS escalation_level,
            COALESCE(t.is_important, 0) AS is_important,
            COALESCE(t.escalation_queued, 0) AS escalation_queued,
            (
                EXISTS (
                    SELECT 1 FROM timeline_events ev
                    WHERE ev.ticket_id = t.id
                      AND ev.event_type = 'escalated'
                      AND ev.note LIKE 'Level 3 escalation email sent%'
                )
                OR EXISTS (
                    SELECT 1 FROM escalations es
                    WHERE es.ticket_id = t.id
                      AND es.email_sent = 1
                )
            ) AS l3_sent,
            EXISTS (
                SELECT 1 FROM timeline_events ev
                WHERE ev.ticket_id = t.id
                  AND ev.event_type = 'escalated'
                  AND ev.note LIKE 'Level 2 escalation email sent%'
            ) AS l2_sent,
            EXISTS (
                SELECT 1 FROM timeline_events ev
                WHERE ev.ticket_id = t.id
                  AND ev.event_type = 'no_reply_warning'
                  AND ev.note LIKE 'Reminder included in consolidated digest%'
            ) AS l1_sent
     FROM tickets t
     WHERE t.received_at >= ?
       AND t.received_at < ?
       AND t.status <> 'closed'
       AND " . portalTicketCondition('t')
);
$ticketStmt->execute([$reportStart, $reportEnd]);

while ($ticket = $ticketStmt->fetch(PDO::FETCH_ASSOC)) {
    $departmentId = (int)$ticket['department_id'];
    if (!isset($departmentCounts[$departmentId])) {
        continue;
    }

    $statusCategory = $ticket['status'] === 'replied' ? 'replied' : 'open';
    $counts = &$departmentCounts[$departmentId];
    $counts['total']++;
    $counts[$statusCategory]++;
    $grandTotalCounts['total']++;
    $grandTotalCounts[$statusCategory]++;

    if ((int)$ticket['escalation_level'] >= 2 || (int)$ticket['l3_sent'] === 1) {
        $category = 'l3';
    } elseif ((int)$ticket['escalation_level'] >= 1 || (int)$ticket['l2_sent'] === 1) {
        $category = 'l2';
    } elseif ((int)$ticket['l1_sent'] === 1) {
        $category = 'l1';
    } elseif (
        (int)$ticket['is_important'] === 1
        || (int)$ticket['escalation_queued'] === 1
        || $ticket['status'] === 'escalated'
    ) {
        $category = 'matched_no_reminder';
    } else {
        $category = 'no_keyword';
    }

    $counts[$category]++;
    $counts[$category . '_' . $statusCategory]++;
    $grandTotalCounts[$category]++;
    $grandTotalCounts[$category . '_' . $statusCategory]++;

    $assigneeId = $ticket['assigned_to'] === null ? 0 : (int)$ticket['assigned_to'];
    $employeeKey = $departmentId . ':' . $assigneeId;
    if (!isset($employeeCounts[$employeeKey])) {
        $employeeCounts[$employeeKey] = [
            'department_id' => $departmentId,
            'employee_id' => $assigneeId,
            'employee_name' => 'Unassigned',
            'counts' => $emptyCounts(),
        ];
    }
    $employeeCounts[$employeeKey]['counts']['total']++;
    $employeeCounts[$employeeKey]['counts'][$statusCategory]++;
    $employeeCounts[$employeeKey]['counts'][$category]++;
    $employeeCounts[$employeeKey]['counts'][$category . '_' . $statusCategory]++;
    unset($counts);
}

$employeeIds = array_values(array_unique(array_filter(array_column($employeeCounts, 'employee_id'))));
if ($employeeIds) {
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $employeeStmt = $pdo->prepare("SELECT id, name FROM users WHERE id IN ({$placeholders})");
    $employeeStmt->execute($employeeIds);
    $employeeNames = $employeeStmt->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($employeeCounts as &$employee) {
        if ($employee['employee_id'] > 0) {
            $employee['employee_name'] = $employeeNames[$employee['employee_id']] ?? 'Unassigned';
        }
    }
    unset($employee);
}

$headers = [
    'Total Emails', 'Replied', 'Open',
    'L1 Total', 'L1 Replied', 'L1 Open',
    'L2 Total', 'L2 Replied', 'L2 Open',
    'L3 Total', 'L3 Replied', 'L3 Open',
    'No Keywords Total', 'No Keywords Replied', 'No Keywords Open',
    'Keyword Matched · No Reminder Total', 'Keyword Matched · No Reminder Replied', 'Keyword Matched · No Reminder Open',
];
$departmentRows = [['Department', ...$headers]];
$departmentRows[] = ['ALL DEPARTMENTS', ...array_values($grandTotalCounts)];
foreach ($departments as $department) {
    $departmentId = (int)$department['id'];
    $departmentRows[] = [$department['name'], ...array_values($departmentCounts[$departmentId])];
}
$employeeRows = [['Department', 'Employee', ...$headers]];
usort($employeeCounts, static function (array $left, array $right) use ($departmentNames): int {
    $departmentOrder = strcasecmp($departmentNames[$left['department_id']], $departmentNames[$right['department_id']]);
    return $departmentOrder !== 0
        ? $departmentOrder
        : strcasecmp($left['employee_name'], $right['employee_name']);
});
foreach ($employeeCounts as $employee) {
    $employeeRows[] = [
        $departmentNames[$employee['department_id']],
        $employee['employee_name'],
        ...array_values($employee['counts']),
    ];
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
$worksheetXml = static function (array $rows) use ($columnName): string {
    $xmlRows = [];
    foreach ($rows as $rowIndex => $row) {
        $rowNumber = $rowIndex + 1;
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $cellReference = $columnName($columnIndex + 1) . $rowNumber;
            $style = $rowIndex === 0 ? ' s="1"' : '';
            if (is_int($value)) {
                $cells[] = '<c r="' . $cellReference . '"' . $style . '><v>' . $value . '</v></c>';
                continue;
            }
            $text = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = '<c r="' . $cellReference . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                . $text . '</t></is></c>';
        }
        $xmlRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    }
    $lastColumn = $columnName(count($rows[0]));
    $lastRow = count($rows);
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . $lastRow . '"/>'
        . '</worksheet>';
};

$tempPath = tempnam(sys_get_temp_dir(), 'department-dashboard-');
if ($tempPath === false) {
    throw new RuntimeException('Unable to create a temporary department workbook.');
}

try {
    $zip = new ZipArchive();
    if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to open the department workbook archive.');
    }
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
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
            . '<sheets><sheet name="Department Summary" sheetId="1" r:id="rId1"/>'
            . '<sheet name="Employee Breakdown" sheetId="2" r:id="rId2"/></sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>'
    );
    $zip->addFromString(
        'xl/styles.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDE7F5"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
    );
    $zip->addFromString('xl/worksheets/sheet1.xml', $worksheetXml($departmentRows));
    $zip->addFromString('xl/worksheets/sheet2.xml', $worksheetXml($employeeRows));
    if (!$zip->close()) {
        throw new RuntimeException('Unable to finalize the department workbook.');
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Cache-Control: no-store, private');
    header('Content-Disposition: attachment; filename="all-departments-email-breakdown-' . $reportDate . '.xlsx"');
    header('Content-Length: ' . filesize($tempPath));
    readfile($tempPath);
} finally {
    if (is_file($tempPath)) {
        unlink($tempPath);
    }
}
