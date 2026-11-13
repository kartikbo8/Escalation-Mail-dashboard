<?php
/**
 * Shared helper functions
 */
require_once __DIR__ . '/../config/db.php';

// Dashboard, email lists and the sidebar show the last 7 days of mail.
const DATA_WINDOW_DAYS = 7;

/** Start of the shown data window: midnight, DATA_WINDOW_DAYS days back including today. */
function dataWindowStart() {
    return date('Y-m-d 00:00:00', strtotime('-' . (DATA_WINDOW_DAYS - 1) . ' days'));
}

/**
 * Date range picked on the dashboard (?from=YYYY-MM-DD&to=YYYY-MM-DD), or the
 * default last-DATA_WINDOW_DAYS window when none / an invalid one is given.
 * 'start' is inclusive and 'end' exclusive, ready for received_at >= ? AND < ?.
 */
function selectedDateRange(array $query) {
    $parse = static function ($value) {
        $value = is_string($value) ? trim($value) : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    };
    $today = new DateTimeImmutable('today');
    $from = $parse($query['from'] ?? '');
    $to = $parse($query['to'] ?? '');
    $isCustom = $from !== null || $to !== null;

    $from = $from ?? new DateTimeImmutable(dataWindowStart());
    $to = min($to ?? $today, $today);
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [
        'from' => $from->format('Y-m-d'),
        'to' => $to->format('Y-m-d'),
        'start' => $from->format('Y-m-d 00:00:00'),
        'end' => $to->modify('+1 day')->format('Y-m-d 00:00:00'),
        'is_custom' => $isCustom,
        'label' => $isCustom
            ? $from->format('d M Y') . ' – ' . $to->format('d M Y')
            : 'Last ' . DATA_WINDOW_DAYS . ' days · ' . $from->format('d M') . ' – ' . $to->format('d M Y'),
    ];
}

/**
 * Core working-hours walker: add N working hours to a start datetime,
 * skipping non-working days (from working_hours table) and holidays.
 * Used for both the final SLA deadline and the halfway reminder checkpoint.
 */
function calculateDueDateByHours($startDateTime, $hoursNeeded) {
    $pdo = getDB();

    $stmt = executePreparedQuery($pdo, "SELECT * FROM working_hours");
    $workingDays = [];
    foreach ($stmt->fetchAll() as $row) {
        $workingDays[(int)$row['day_of_week']] = $row;
    }

    $stmt = executePreparedQuery($pdo, "SELECT holiday_date FROM holidays");
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

    // Internal mails: reminder at 2h, Level 2 at this SLA (default 4h = 2h after the reminder).
    $stmt = $pdo->prepare("SELECT response_hours FROM sla_settings WHERE department_id = ?");
    $stmt->execute([$departmentId]);
    $sla = $stmt->fetch();
    return $sla ? (float)$sla['response_hours'] : 4;
}

/**
 * Urgent mails (Urgent keyword, incl. EOD + acknowledgement) run every
 * escalation step on a 1-hour window.
 */
function isUrgentEscalation($priorityTag = null, $matchedKeyword = null) {
    return $priorityTag === 'escalation_trigger'
        || strcasecmp(trim((string)$matchedKeyword), 'EOD + ACKNOWLEDGEMENT') === 0;
}

/**
 * Final SLA deadline for the active escalation stage (when Level 2 goes out).
 * Urgent mails: 2 working hours (reminder at 1h, Level 2 1h later); other
 * mails follow the configured department/sender SLA.
 */
function calculateSlaDueDate($startDateTime, $departmentId, $senderEmail = null, $matchedKeyword = null, $priorityTag = null) {
    if (isUrgentEscalation($priorityTag, $matchedKeyword)) {
        return calculateDueDateByHours($startDateTime, 2);
    }

    return calculateDueDateByHours($startDateTime, getSlaHours($departmentId, $senderEmail));
}

/**
 * Reminder checkpoint for the active escalation stage: 1 hour for urgent
 * mails, 2 hours for internal mails, 12 hours for client mails.
 */
function calculateReminderDueDate($startDateTime, $departmentId, $senderEmail = null, $matchedKeyword = null, $priorityTag = null) {
    if (isUrgentEscalation($priorityTag, $matchedKeyword)) {
        return calculateDueDateByHours($startDateTime, 1);
    }

    // Client mails keep their longer window; internal mails get the reminder after 2 hours.
    return calculateDueDateByHours($startDateTime, isExternalSenderEmail($senderEmail) ? 12 : 2);
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

function priorityBadge($tag, $matchedKeyword = null) {
    $map = [
        'customer_project'   => ['bg-danger',  'Customer/Project'],
        'ppc_internal'       => ['bg-warning text-dark', 'PPC/Internal'],
        'escalation_trigger' => ['bg-dark',     'Urgent'],
        'normal'             => ['bg-light text-dark border', 'Normal'],
    ];
    [$color, $label] = $map[$tag] ?? $map['normal'];
    // Urgent mails show the word that triggered them (TODAY, ASAP, URGENT...).
    if ($tag === 'escalation_trigger' && trim((string)$matchedKeyword) !== '') {
        $label = strtoupper(trim((string)$matchedKeyword));
    }
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $title = $tag === 'escalation_trigger' ? ' title="Urgent keyword"' : '';
    return "<span class=\"badge {$color}\"{$title}>{$label}</span>";
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
    return executePreparedQuery($pdo, "SELECT * FROM departments ORDER BY name")->fetchAll();
}

/**
 * Configured group / distribution-list address (Settings > Group mail owners)
 * among a mail's To recipients, or null. Recipients: array of addresses or the
 * tickets.to_recipients_json string.
 */
function findGroupMailbox($recipients) {
    static $groups = null;
    if ($groups === null) {
        $groups = [];
        $rows = executePreparedQuery(getDB(),
            "SELECT g.email, g.owner_user_id, g.manager_user_id
             FROM group_mailboxes g
             JOIN users owner ON owner.id = g.owner_user_id AND owner.is_active = 1"
        )->fetchAll();
        foreach ($rows as $row) {
            $groups[strtolower(trim($row['email']))] = $row;
        }
    }
    if (is_string($recipients)) {
        $recipients = json_decode($recipients, true);
    }
    foreach ((array)$recipients as $recipient) {
        $address = is_string($recipient) ? strtolower(trim($recipient)) : '';
        if (isset($groups[$address])) {
            return $groups[$address];
        }
    }
    return null;
}
