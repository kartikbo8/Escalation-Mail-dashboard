<?php

require_once __DIR__ . '/graph_helper.php';

const WEEKLY_REPORT_SENDER_MAILBOX = 'notifications@afcindia.in';
const WEEKLY_REPORT_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

function weeklyReportPeriod(?DateTimeImmutable $now = null): array {
    $timezone = new DateTimeZone(date_default_timezone_get());
    $now = $now ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);
    $thisMonday = $now->modify('monday this week')->setTime(0, 0);
    $weekStart = $thisMonday->modify('-7 days');

    return [
        'start' => $weekStart,
        'end_exclusive' => $thisMonday,
        'end_inclusive' => $thisMonday->modify('-1 second'),
    ];
}

function weeklyReportCreateDeliveryTable(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS weekly_report_deliveries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            report_key VARCHAR(320) NOT NULL UNIQUE,
            week_start DATE NOT NULL,
            week_end DATE NOT NULL,
            recipient_email VARCHAR(255) NOT NULL,
            report_scope ENUM('department', 'full') NOT NULL,
            department_id INT DEFAULT NULL,
            delivery_status ENUM('sent', 'failed') NOT NULL,
            attempted_at DATETIME NOT NULL,
            sent_at DATETIME DEFAULT NULL,
            last_error VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_weekly_report_period (week_start, week_end)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function weeklyReportFetchDepartments(PDO $pdo, ?int $departmentId = null): array {
    if ($departmentId !== null) {
        $statement = $pdo->prepare('SELECT id, name FROM departments WHERE id = ?');
        $statement->execute([$departmentId]);
        $department = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$department) {
            throw new RuntimeException("Department {$departmentId} does not exist.");
        }
        return [$department];
    }

    return $pdo->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
}

function weeklyReportFetchTickets(PDO $pdo, DateTimeImmutable $start, DateTimeImmutable $endExclusive, ?int $departmentId = null): array {
    $departmentClause = $departmentId === null ? '' : ' AND t.department_id = ?';
    $statement = $pdo->prepare(
        "SELECT t.id, t.department_id, t.assigned_to AS employee_id, d.name AS department_name,
                COALESCE(u.name, 'Unassigned') AS employee_name,
                t.subject, t.sender_email, t.received_at, t.status, t.escalation_level,
                t.replied_at,
                COALESCE(activity.escalated_count, 0) AS escalated_count,
                COALESCE(activity.replied_count, 0) AS replied_count,
                COALESCE(activity.reminder_count, 0) AS reminder_count,
                COALESCE(activity.level_2_sent_count, 0) AS level_2_sent_count,
                GREATEST(COALESCE(activity.level_3_sent_count, 0),
                         COALESCE(delivery.level_3_sent_count, 0)) AS level_3_sent_count
         FROM tickets t
         JOIN departments d ON d.id = t.department_id
         LEFT JOIN users u ON u.id = t.assigned_to
         LEFT JOIN (
             SELECT ticket_id,
                    MAX(event_type = 'escalated') AS escalated_count,
                    MAX(event_type = 'replied') AS replied_count,
                    MAX(event_type = 'no_reply_warning'
                        AND note LIKE 'Reminder included in consolidated digest%') AS reminder_count,
                    MAX(event_type = 'escalated'
                        AND note LIKE 'Level 2 escalation email sent%') AS level_2_sent_count,
                    MAX(event_type = 'escalated'
                        AND note LIKE 'Level 3 escalation email sent%') AS level_3_sent_count
             FROM timeline_events
             WHERE event_time >= ? AND event_time < ?
             GROUP BY ticket_id
         ) activity ON activity.ticket_id = t.id
         LEFT JOIN (
             SELECT ticket_id, MAX(email_sent = 1) AS level_3_sent_count
             FROM escalations
             WHERE email_sent_at >= ? AND email_sent_at < ?
             GROUP BY ticket_id
         ) delivery ON delivery.ticket_id = t.id
         WHERE t.received_at >= ? AND t.received_at < ?{$departmentClause}
         ORDER BY d.name, employee_name, t.received_at, t.id"
    );
    $params = [
        $start->format('Y-m-d H:i:s'),
        $endExclusive->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s'),
        $endExclusive->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s'),
        $endExclusive->format('Y-m-d H:i:s'),
    ];
    if ($departmentId !== null) {
        $params[] = $departmentId;
    }
    $statement->execute($params);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function weeklyReportSummarizeTickets(array $tickets): array {
    $summary = [
        'received' => 0,
        'escalated' => 0,
        'open' => 0,
        'replied' => 0,
        'reminders' => 0,
        'level_2_sent' => 0,
        'level_3_sent' => 0,
    ];

    foreach ($tickets as $ticket) {
        $summary['received']++;
        $summary['escalated'] += (int)$ticket['escalated_count'];
        $summary['open'] += $ticket['status'] === 'open' ? 1 : 0;
        $summary['replied'] += (int)$ticket['replied_count'];
        $summary['reminders'] += (int)$ticket['reminder_count'];
        $summary['level_2_sent'] += (int)$ticket['level_2_sent_count'];
        $summary['level_3_sent'] += (int)$ticket['level_3_sent_count'];
    }

    return $summary;
}

function weeklyReportExcelColumn(int $number): string {
    $column = '';
    while ($number > 0) {
        $number--;
        $column = chr(65 + ($number % 26)) . $column;
        $number = intdiv($number, 26);
    }
    return $column;
}

function weeklyReportWorksheetXml(array $rows, array $columnWidths): string {
    $xmlRows = [];
    $rowNumber = 0;
    $filterHeaderRow = 1;
    foreach ($rows as $row) {
        $rowNumber++;
        $firstTwoCells = array_slice(array_values($row), 0, 2);
        $isTableHeader = $firstTwoCells === ['Department', 'Emails Received']
            || $firstTwoCells === ['Employee', 'Emails Received']
            || $firstTwoCells === ['Employee', 'Subject'];
        if ($isTableHeader) {
            $filterHeaderRow = $rowNumber;
        }
        $cells = [];
        foreach (array_values($row) as $index => $value) {
            $cellReference = weeklyReportExcelColumn($index + 1) . $rowNumber;
            $style = ($rowNumber === 1 || $isTableHeader) ? ' s="1"' : '';
            if (is_int($value) || is_float($value)) {
                $cells[] = '<c r="' . $cellReference . '"' . $style . '><v>' . $value . '</v></c>';
                continue;
            }
            $text = htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $cells[] = '<c r="' . $cellReference . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">'
                . $text . '</t></is></c>';
        }
        $xmlRows[] = '<row r="' . $rowNumber . '">' . implode('', $cells) . '</row>';
    }

    $columns = [];
    foreach ($columnWidths as $index => $width) {
        $column = $index + 1;
        $columns[] = '<col min="' . $column . '" max="' . $column . '" width="' . $width . '" customWidth="1"/>';
    }

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
        . '<cols>' . implode('', $columns) . '</cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A' . $filterHeaderRow . ':' . weeklyReportExcelColumn(count($columnWidths)) . $rowNumber . '"/>'
        . '</worksheet>';
}

function weeklyReportBuildWorkbook(PDO $pdo, DateTimeImmutable $start, DateTimeImmutable $endExclusive, ?int $departmentId = null): string {
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('PHP ZipArchive extension is required to generate weekly Excel reports.');
    }

    $departments = weeklyReportFetchDepartments($pdo, $departmentId);
    $tickets = weeklyReportFetchTickets($pdo, $start, $endExclusive, $departmentId);
    $ticketsByDepartment = [];
    foreach ($tickets as $ticket) {
        $ticketsByDepartment[(int)$ticket['department_id']][] = $ticket;
    }

    $sheets = [];
    if ($departmentId === null) {
        $overview = [[
            'Department', 'Emails Received', 'Escalated During Week', 'Open at Report Time',
            'Replies During Week', 'L1 Reminders Sent', 'L2 Emails Delivered', 'L3 Emails Delivered',
        ]];
        foreach ($departments as $department) {
            $departmentTickets = $ticketsByDepartment[(int)$department['id']] ?? [];
            $counts = weeklyReportSummarizeTickets($departmentTickets);
            $overview[] = [
                $department['name'],
                $counts['received'],
                $counts['escalated'],
                $counts['open'],
                $counts['replied'],
                $counts['reminders'],
                $counts['level_2_sent'],
                $counts['level_3_sent'],
            ];
        }
        $sheets[] = ['name' => 'Overview', 'rows' => $overview, 'widths' => [24, 19, 23, 22, 19, 19, 20, 20]];
    }

    foreach ($departments as $department) {
        $departmentTickets = $ticketsByDepartment[(int)$department['id']] ?? [];
        $ticketsByEmployee = [];
        foreach ($departmentTickets as $ticket) {
            $employeeKey = $ticket['employee_id'] === null
                ? 'unassigned'
                : 'user:' . (int)$ticket['employee_id'];
            $ticketsByEmployee[$employeeKey]['name'] = $ticket['employee_name'];
            $ticketsByEmployee[$employeeKey]['tickets'][] = $ticket;
        }

        $rows = [
            ['Weekly Department Report', $department['name']],
            ['Reporting Period', $start->format('d M Y') . ' - ' . $endExclusive->modify('-1 day')->format('d M Y')],
            [],
            ['Employee Summary'],
            ['Employee', 'Emails Received', 'Escalated During Week', 'Open at Report Time',
                'Replies During Week', 'L1 Reminders Sent', 'L2 Emails Delivered', 'L3 Emails Delivered'],
        ];
        foreach ($ticketsByEmployee as $employee) {
            $employeeTickets = $employee['tickets'];
            $counts = weeklyReportSummarizeTickets($employeeTickets);
            $rows[] = [
                $employee['name'],
                $counts['received'],
                $counts['escalated'],
                $counts['open'],
                $counts['replied'],
                $counts['reminders'],
                $counts['level_2_sent'],
                $counts['level_3_sent'],
            ];
        }
        $rows[] = [];
        $rows[] = ['Email Details'];
        $rows[] = ['Employee', 'Subject', 'Sender', 'Received', 'Current Status', 'Escalation Level',
            'Escalated During Week', 'Replied During Week', 'L1 Reminder Sent', 'L2 Email Delivered', 'L3 Email Delivered'];
        foreach ($departmentTickets as $ticket) {
            $rows[] = [
                $ticket['employee_name'],
                $ticket['subject'],
                $ticket['sender_email'],
                $ticket['received_at'],
                $ticket['status'],
                (int)$ticket['escalation_level'] + 1,
                (int)$ticket['escalated_count'],
                (int)$ticket['replied_count'],
                (int)$ticket['reminder_count'],
                (int)$ticket['level_2_sent_count'],
                (int)$ticket['level_3_sent_count'],
            ];
        }

        $sheetName = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $department['name']);
        $sheets[] = [
            'name' => substr(trim($sheetName), 0, 31),
            'rows' => $rows,
            'widths' => [25, 42, 32, 22, 18, 16, 22, 20, 18, 20, 20],
        ];
    }

    if (!$sheets) {
        throw new RuntimeException('No department worksheets were available for the weekly report.');
    }

    $tempPath = tempnam(sys_get_temp_dir(), 'weekly-report-');
    if ($tempPath === false) {
        throw new RuntimeException('Unable to create a temporary workbook file.');
    }

    try {
        $zip = new ZipArchive();
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open the temporary workbook archive.');
        }

        $contentTypes = ['<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>',
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'];
        $workbookSheets = [];
        $relationships = [];
        foreach ($sheets as $index => $sheet) {
            $sheetNumber = $index + 1;
            $sheetId = 'rId' . $sheetNumber;
            $safeName = htmlspecialchars($sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $workbookSheets[] = '<sheet name="' . $safeName . '" sheetId="' . $sheetNumber . '" r:id="' . $sheetId . '"/>';
            $relationships[] = '<Relationship Id="' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
            $contentTypes[] = '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $sheetNumber . '.xml', weeklyReportWorksheetXml($sheet['rows'], $sheet['widths']));
        }
        $relationships[] = '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . implode('', $contentTypes) . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
            . implode('', $workbookSheets) . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . implode('', $relationships) . '</Relationships>');
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDE7F5"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
        $zip->close();

        $contents = file_get_contents($tempPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the generated workbook.');
        }
        return $contents;
    } finally {
        if (is_file($tempPath)) {
            unlink($tempPath);
        }
    }
}

function weeklyReportRecipients(PDO $pdo): array {
    $recipients = [];
    $departments = $pdo->query('SELECT id, name, hod_user_id FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

    foreach ($departments as $department) {
        $departmentId = (int)$department['id'];
        $managerStmt = $pdo->prepare(
            "SELECT DISTINCT m.id, m.name, m.email
             FROM users employee
             JOIN users m ON m.id IN (employee.reporting_manager_id, employee.reporting_manager_2_id)
             WHERE employee.department_id = ? AND employee.is_active = 1
               AND m.is_active = 1 AND m.email <> ''
             UNION
             SELECT h.id, h.name, h.email
             FROM departments d JOIN users h ON h.id = d.hod_user_id
             WHERE d.id = ? AND h.is_active = 1 AND h.email <> ''"
        );
        $managerStmt->execute([$departmentId, $departmentId]);
        foreach ($managerStmt->fetchAll(PDO::FETCH_ASSOC) as $recipient) {
            $email = strtolower(trim($recipient['email']));
            if ($email === '') {
                continue;
            }
            $recipientKey = $email . '|department:' . $departmentId;
            $recipients[$recipientKey] = [
                'email' => $email,
                'name' => $recipient['name'],
                'scope' => 'department',
                'department_id' => $departmentId,
                'department_name' => $department['name'],
            ];
        }
    }

    $hrAdmin = $pdo->query(
        "SELECT name, email FROM escalation_contacts
         WHERE role_type IN ('hr', 'admin') AND email <> ''
         ORDER BY role_type, name"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($hrAdmin as $recipient) {
        $email = strtolower(trim($recipient['email']));
        if ($email === '') {
            continue;
        }
        foreach (array_keys($recipients) as $existingKey) {
            if (strpos($existingKey, $email . '|') === 0) {
                unset($recipients[$existingKey]);
            }
        }
        $recipients[$email . '|full'] = [
            'email' => $email,
            'name' => $recipient['name'],
            'scope' => 'full',
            'department_id' => null,
            'department_name' => null,
        ];
    }

    return array_values($recipients);
}

function weeklyReportDeliveryKey(DateTimeImmutable $weekStart, array $recipient): string {
    $scope = $recipient['scope'] === 'full'
        ? 'full'
        : 'department:' . (int)$recipient['department_id'];
    return $weekStart->format('Y-m-d') . '|' . strtolower($recipient['email']) . '|' . $scope;
}

function weeklyReportWasSent(PDO $pdo, string $reportKey): bool {
    $statement = $pdo->prepare(
        "SELECT delivery_status FROM weekly_report_deliveries WHERE report_key = ? LIMIT 1"
    );
    $statement->execute([$reportKey]);
    return $statement->fetchColumn() === 'sent';
}

function weeklyReportRecordDelivery(PDO $pdo, string $reportKey, array $recipient, DateTimeImmutable $weekStart, DateTimeImmutable $weekEnd, bool $sent): void {
    $statement = $pdo->prepare(
        "INSERT INTO weekly_report_deliveries
            (report_key, week_start, week_end, recipient_email, report_scope, department_id,
             delivery_status, attempted_at, sent_at, last_error)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE
            delivery_status = VALUES(delivery_status),
            attempted_at = VALUES(attempted_at),
            sent_at = VALUES(sent_at),
            last_error = VALUES(last_error)"
    );
    $statement->execute([
        $reportKey,
        $weekStart->format('Y-m-d'),
        $weekEnd->format('Y-m-d'),
        $recipient['email'],
        $recipient['scope'],
        $recipient['department_id'],
        $sent ? 'sent' : 'failed',
        $sent ? date('Y-m-d H:i:s') : null,
        $sent ? null : 'Microsoft Graph did not accept the weekly report email.',
    ]);
}
