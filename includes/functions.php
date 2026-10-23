<?php
/**
 * Shared helper functions
 */
require_once __DIR__ . '/../config/db.php';

/**
 * Core working-hours walker: add N working hours to a start datetime,
 * skipping non-working days (from working_hours table) and holidays.
 * Used for both the final SLA deadline and the halfway reminder checkpoint.
 */
function calculateDueDateByHours($startDateTime, $hoursNeeded) {
    $pdo = getDB();

    $stmt = $pdo->query("SELECT * FROM working_hours");
    $workingDays = [];
    foreach ($stmt->fetchAll() as $row) {
        $workingDays[(int)$row['day_of_week']] = $row;
    }

    $stmt = $pdo->query("SELECT holiday_date FROM holidays");
    $holidays = array_column($stmt->fetchAll(), 'holiday_date');

    $current = new DateTime($startDateTime);
    $remainingMinutes = $hoursNeeded * 60;

    while ($remainingMinutes > 0) {
        $dow = (int)$current->format('w');
        $dateStr = $current->format('Y-m-d');

        $isHoliday = in_array($dateStr, $holidays);
        $dayConfig = $workingDays[$dow] ?? null;

        if (!$isHoliday && $dayConfig && $dayConfig['is_working_day']) {
            $dayStart = new DateTime($dateStr . ' ' . $dayConfig['start_time']);
            $dayEnd   = new DateTime($dateStr . ' ' . $dayConfig['end_time']);

            if ($current < $dayStart) $current = clone $dayStart;

            if ($current < $dayEnd) {
                $availableMinutesToday = ($dayEnd->getTimestamp() - $current->getTimestamp()) / 60;

                if ($remainingMinutes <= $availableMinutesToday) {
                    $remainingSeconds = (int)round($remainingMinutes * 60);
                    $current->modify("+{$remainingSeconds} seconds");
                    $remainingMinutes = 0;
                    break;
                } else {
                    $remainingMinutes -= $availableMinutesToday;
                    $current = clone $dayEnd;
                }
            }
        }

        $current->modify('+1 day');
        $current->setTime(0, 0, 0);
    }

    return $current->format('Y-m-d H:i:s');
}

/**
 * Determine whether the sender is external to AFC, so we can apply the longer SLA.
 */
function isExternalSenderEmail($senderEmail) {
    $email = strtolower(trim((string)($senderEmail ?? '')));
    if ($email === '') {
        return false;
    }

    return !str_ends_with($email, '@afcindia.in');
}

/**
 * Get the SLA response hours configured for a department or sender type.
 * External senders use a 24-working-hour SLA; Design/PPC use 2 hours; others use 3.
 */
function getSlaHours($departmentId, $senderEmail = null) {
    $pdo = getDB();

    if (isExternalSenderEmail($senderEmail)) {
        return 24;
    }

    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
    $stmt->execute([$departmentId]);
    $departmentName = strtolower((string)($stmt->fetchColumn() ?: ''));

    if (in_array($departmentName, ['design', 'ppc'], true)) {
        return 2;
    }

    $stmt = $pdo->prepare("SELECT response_hours FROM sla_settings WHERE department_id = ?");
    $stmt->execute([$departmentId]);
    $sla = $stmt->fetch();
    return $sla ? (float)$sla['response_hours'] : 3;
}

/**
 * Final SLA deadline for the active escalation stage.
 * EOD + acknowledgement mails get 4 working hours so their reminder can go
 * after 2 hours; other mails follow the configured department/sender SLA.
 */
function calculateSlaDueDate($startDateTime, $departmentId, $senderEmail = null, $matchedKeyword = null) {
    if (strcasecmp(trim((string)$matchedKeyword), 'EOD + ACKNOWLEDGEMENT') === 0) {
        return calculateDueDateByHours($startDateTime, 4);
    }

    return calculateDueDateByHours($startDateTime, getSlaHours($departmentId, $senderEmail));
}

/**
 * Reminder checkpoint for the active escalation stage.
 * EOD + acknowledgement mails get a 2-hour checkpoint; other mails follow
 * the existing sender/department reminder schedule.
 */
function calculateReminderDueDate($startDateTime, $departmentId, $senderEmail = null, $matchedKeyword = null) {
    if (strcasecmp(trim((string)$matchedKeyword), 'EOD + ACKNOWLEDGEMENT') === 0) {
        return calculateDueDateByHours($startDateTime, 2);
    }

    if (isExternalSenderEmail($senderEmail)) {
        $hours = 12;
    } else {
        $hours = in_array(strtolower((string)($departmentId ? getDepartmentNameById($departmentId) : '')), ['design', 'ppc'], true)
            ? 1
            : 1.5;
    }

    return calculateDueDateByHours($startDateTime, $hours);
}

function getDepartmentNameById($departmentId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ? LIMIT 1");
    $stmt->execute([$departmentId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function statusBadge($status) {
    $map = [
        'open'      => 'secondary',
        'replied'   => 'info',
        'closed'    => 'success',
        'escalated' => 'danger',
    ];
    $color = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-$color\">" . ucfirst($status) . "</span>";
}

function priorityBadge($tag) {
    $map = [
        'customer_project'   => ['bg-danger',  'Customer/Project'],
        'ppc_internal'       => ['bg-warning text-dark', 'PPC/Internal'],
        'escalation_trigger' => ['bg-dark',     'Escalation Paused'],
        'normal'             => ['bg-light text-dark border', 'Normal'],
    ];
    [$color, $label] = $map[$tag] ?? $map['normal'];
    return "<span class=\"badge {$color}\">{$label}</span>";
}

function logTimelineEvent($ticketId, $eventType, $note = null, $userId = null) {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        "INSERT INTO timeline_events (ticket_id, event_type, event_time, note, created_by)
         VALUES (?, ?, NOW(), ?, ?)"
    );
    $stmt->execute([$ticketId, $eventType, $note, $userId]);
}

function getDepartments() {
    $pdo = getDB();
    return $pdo->query("SELECT * FROM departments ORDER BY name")->fetchAll();
}
