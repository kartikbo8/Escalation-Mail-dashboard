<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/functions.php';

$dateInput = $_GET['date'] ?? '';
$date = is_string($dateInput) ? $dateInput : '';
$parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$parsedDate
    || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
    || $parsedDate->format('Y-m-d') !== $date) {
    http_response_code(400);
    exit('Invalid report date. Use YYYY-MM-DD.');
}

$start = $parsedDate->format('Y-m-d 00:00:00');
$endExclusive = $parsedDate->modify('+1 day')->format('Y-m-d 00:00:00');
$statement = getDB()->prepare(
    "SELECT t.id, t.subject, t.sender_email, d.name AS department_name,
            COALESCE(u.name, 'Unassigned') AS assignee_name,
            t.received_at, t.status, t.escalation_level, t.sla_due_at
     FROM tickets t
     JOIN departments d ON d.id = t.department_id
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE t.received_at >= ?
       AND t.received_at < ?
       AND " . portalTicketCondition('t') . "
     ORDER BY d.name, t.received_at, t.id"
);
$statement->execute([$start, $endExclusive]);

header('Content-Type: text/csv; charset=UTF-8');
header('Cache-Control: no-store, private');
header('Content-Disposition: attachment; filename="daily-tickets-' . $date . '.csv"');

$output = fopen('php://output', 'w');
if ($output === false) {
    throw new RuntimeException('Unable to open the daily ticket report output.');
}
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'Ticket ID',
    'Subject',
    'Sender Email',
    'Department',
    'Assigned Employee',
    'Received At',
    'Current Status',
    'Escalation Level',
    'SLA Due At',
]);
while ($ticket = $statement->fetch(PDO::FETCH_ASSOC)) {
    $values = [
        $ticket['id'],
        $ticket['subject'],
        $ticket['sender_email'],
        $ticket['department_name'],
        $ticket['assignee_name'],
        $ticket['received_at'],
        $ticket['status'],
        (int)$ticket['escalation_level'] + 1,
        $ticket['sla_due_at'] ?? '',
    ];
    foreach ($values as &$value) {
        $value = (string)$value;
        if (preg_match('/^[\s]*[=+\-@]/', $value)) {
            $value = "'" . $value;
        }
    }
    unset($value);
    fputcsv($output, $values);
}
fclose($output);
