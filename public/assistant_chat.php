<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Only POST requests are supported.']);
    exit;
}

requireCsrfToken();

$actionInput = $_POST['action'] ?? '';
$action = is_string($actionInput) ? $actionInput : '';
if ($action === 'clear_history') {
    unset($_SESSION['assistant_widget_chat']);
    echo json_encode(['cleared' => true]);
    exit;
}
if ($action !== 'chat') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported chat action.']);
    exit;
}

$questionInput = $_POST['question'] ?? '';
$question = is_string($questionInput) ? trim($questionInput) : '';
$questionLength = preg_match_all('/./us', $question, $questionCharacters);
if ($question === ''
    || strlen($question) > 4800
    || $questionLength === false
    || $questionLength > 1200) {
    http_response_code(400);
    echo json_encode(['error' => 'Enter a question of up to 1,200 characters.']);
    exit;
}

$history = $_SESSION['assistant_widget_chat'] ?? [];
if (!is_array($history)) {
    $history = [];
}
$history = array_slice($history, -8);
while ($history
    && is_array($history[0])
    && ($history[0]['role'] ?? '') === 'assistant') {
    array_shift($history);
}

$user = currentUser();
$previousUserQuestion = '';
for ($index = count($history) - 1; $index >= 0; $index--) {
    if (is_array($history[$index])
        && ($history[$index]['role'] ?? '') === 'user'
        && is_string($history[$index]['text'] ?? null)) {
        $previousUserQuestion = $history[$index]['text'];
        break;
    }
}

$actionPatterns = '/\b(?:send|issue|trigger|create|remind|alert|escalate|notify|pause|stop|suspend|disable|hold)\b.*\b(?:reminder|hr|admin|notification|notifications|trigger|triggers)\b'
    . '|\b(?:reminder|hr|admin|notification|notifications|trigger|triggers)\b.*\b(?:send|issue|trigger|create|remind|alert|escalate|notify|pause|stop|suspend|disable|hold)\b'
    . '|\b(?:bhej|bhejo|bhejdo|bhej\s+do|kar\s+do|kardo|rok|roko|band)\b.*\b(?:reminder|yaad dila|hr|admin|notification|notifications|trigger|triggers)\b'
    . '|\b(?:reminder|yaad dila|hr|admin|notification|notifications|trigger|triggers)\b.*\b(?:bhej|bhejo|bhejdo|bhej\s+do|kar\s+do|kardo|rok|roko|band)\b'
    . '|(?:बंद|रोक).*(?:notification|notifications|trigger|triggers)|(?:notification|notifications|trigger|triggers).*(?:बंद|रोक)/iu';
$isInformationalQuestion = preg_match(
    '/^\s*(?:how|what|why|when|where|should\s+i|can\s+i|kaise|kya|kyun|kab|kahan)\b'
    . '|\b(?:tell\s+me\s+how|explain\s+how|steps\s+to|instructions\s+for)\b/iu',
    $question
) === 1;
$isActionRequest = !$isInformationalQuestion && preg_match($actionPatterns, $question) === 1;
$isActionFollowUp = preg_match('/\b(?:please do it|do it|you do it|go ahead|yes|haan|han|kar do|kardo|aap kar do)\b/i', $question) === 1;
$actionIntentCount = 0;
if (preg_match('/\b(?:pause|stop|suspend|disable|hold|band|rok|roko)\b.*\b(?:notification|notifications|trigger|triggers)\b|\b(?:notification|notifications|trigger|triggers)\b.*\b(?:pause|stop|suspend|disable|hold|band|rok|roko)\b|(?:बंद|रोक).*(?:notification|notifications|trigger|triggers)|(?:notification|notifications|trigger|triggers).*(?:बंद|रोक)/iu', $question)) {
    $actionIntentCount++;
}
if (preg_match('/\b(?:escalate|alert|notify)\b.*\b(?:hr|admin|administrator)\b|\b(?:hr|admin|administrator)\b.*\b(?:escalate|alert|notify)\b/i', $question)) {
    $actionIntentCount++;
}
if (preg_match('/\b(?:reminder|remind)\b/i', $question)) {
    $actionIntentCount++;
}
$actionMessage = static function ($english, $hindi) use ($question) {
    return adminAssistantRequestedHindi($question) ? $hindi : $english;
};
$actionCommand = $question;
if (!$isActionRequest && $isActionFollowUp && $previousUserQuestion !== '') {
    $previousParsed = parseAdminAssistantCommandFallback($previousUserQuestion);
    if ($previousParsed['action'] !== 'unknown') {
        $isActionRequest = true;
        $actionCommand = $previousUserQuestion . ' ' . $question;
    }
}

if ($isActionRequest) {
    unset($_SESSION['admin_assistant_pending']);
    if (!in_array($user['role'], ['admin', 'super_admin'], true)) {
        $answer = $actionMessage(
            'I can help get this ready for review. This ticket action requires admin access, so please ask an admin to review the ticket number and requested action.',
            'Main is action ko review ke liye tayyar karne mein madad kar sakta hoon. Is ticket action ke liye admin access zaroori hai; apne admin se ticket number aur action share karein.'
        );
        $history[] = ['role' => 'user', 'text' => $question];
        $history[] = ['role' => 'assistant', 'text' => $answer];
        $_SESSION['assistant_widget_chat'] = array_slice($history, -10);
        echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $parsed = $actionIntentCount > 1
        ? ['action' => 'unknown', 'ticket_id' => null]
        : parseAdminAssistantCommandFallback($actionCommand);
    if ($actionIntentCount > 1) {
        $answer = $actionMessage(
            'I can prepare one action at a time. Which should I prepare: a reminder, an HR/Admin alert, or a notification pause? Nothing has been sent or changed.',
            'Main ek baar mein ek action ka preview bana sakta hoon. Aap reminder, HR/Admin alert, ya notifications pause mein se kya chahte hain? Abhi kuch bheja ya badla nahi gaya.'
        );
    } elseif ($parsed === null || $parsed['action'] === 'unknown') {
        $answer = $actionMessage(
            'I can prepare a reminder, an HR/Admin alert, or a notification pause for review. Please tell me which action and the ticket number.',
            'Main reminder, HR/Admin alert, ya notifications pause karne ka preview bana sakta hoon. Kripya action aur ticket number batayein.'
        );
    } elseif ($parsed['ticket_id'] === null) {
        $answer = $actionMessage(
            'Sure—I can prepare that for review. Please include the ticket number so I can open the correct preview.',
            'Zaroor—main review ke liye preview bana sakta hoon. Sahi preview kholne ke liye ticket number bhejein.'
        );
    } else {
        $pdo = getDB();
        $sql = "SELECT t.id, t.department_id, t.status, u.email AS assignee_email
                FROM tickets t
                JOIN departments d ON d.id = t.department_id
                LEFT JOIN users u ON u.id = t.assigned_to
                WHERE t.id = ?";
        $params = [(int)$parsed['ticket_id']];
        if ($user['role'] !== 'super_admin') {
            if (empty($user['department_id'])) {
                $sql .= ' AND 1 = 0';
            } else {
                $sql .= ' AND t.department_id = ?';
                $params[] = (int)$user['department_id'];
            }
        }
        $ticketStmt = $pdo->prepare($sql);
        $ticketStmt->execute($params);
        $ticket = $ticketStmt->fetch();

        if (!$ticket) {
            $answer = $actionMessage(
                'I couldn’t find that ticket in the tickets available to your account. Please check the number or ask your administrator for access.',
                'Aapke account mein is number ka ticket nahi mila. Kripya number check karein ya access ke liye administrator se baat karein.'
            );
        } elseif (!in_array($parsed['action'], ['pause_notifications', 'escalate_admin', 'send_reminder'], true)) {
            $answer = $actionMessage(
                'I can prepare a reminder, an HR/Admin alert, or a notification pause for review. Which of those would you like for this ticket?',
                'Main is ticket ke liye reminder, HR/Admin alert, ya notifications pause karne ka preview bana sakta hoon. Aap kaunsa action chahte hain?'
            );
        } elseif ($parsed['action'] === 'pause_notifications'
            && !in_array($ticket['status'], ['open', 'escalated'], true)) {
            $answer = $actionMessage(
                'This ticket is not in an active open/escalated state, so its notification triggers cannot be paused. I have not made any changes.',
                'Yeh ticket active open/escalated status mein nahi hai, isliye notifications pause nahi ki ja sakti. Koi badlav nahi kiya gaya.'
            );
        } elseif ($parsed['action'] === 'send_reminder'
            && ($ticket['status'] !== 'open' || empty($ticket['assignee_email']))) {
            $answer = $actionMessage(
                'A reminder preview is available only for an open ticket with an assigned employee. I have not sent anything.',
                'Reminder preview sirf open ticket aur assigned employee ke liye ban sakta hai. Abhi kuch bheja nahi gaya.'
            );
        } else {
            $pendingAction = [
                'action' => $parsed['action'],
                'ticket_id' => (int)$ticket['id'],
                'created_at' => time(),
            ];
            if ($parsed['action'] === 'send_reminder') {
                $pendingAction['assignee_email'] = strtolower(trim((string)$ticket['assignee_email']));
            } elseif ($parsed['action'] === 'escalate_admin') {
                if (!in_array($ticket['status'], ['open', 'escalated'], true)) {
                    $answer = $actionMessage(
                        'This ticket is not in an active open/escalated state, so an HR/Admin alert cannot be prepared. I have not sent anything.',
                        'Yeh ticket active open/escalated status mein nahi hai, isliye HR/Admin alert preview nahi ban sakta. Abhi kuch bheja nahi gaya.'
                    );
                } else {
                    $contactsStmt = $pdo->prepare(
                        "SELECT email FROM escalation_contacts
                         WHERE role_type IN ('hr', 'admin')
                           AND email <> ''
                           AND (department_id = ? OR department_id IS NULL)"
                    );
                    $contactsStmt->execute([(int)$ticket['department_id']]);
                    $recipientEmails = [];
                    foreach ($contactsStmt->fetchAll() as $contact) {
                        $email = strtolower(trim((string)$contact['email']));
                        if ($email !== '') {
                            $recipientEmails[$email] = $email;
                        }
                    }
                    $recipientEmails = array_values($recipientEmails);
                    sort($recipientEmails, SORT_STRING);
                    if (!$recipientEmails) {
                        $answer = $actionMessage(
                            'No HR/Admin contacts are configured for this ticket’s department, so an alert preview is not available yet.',
                            'Is ticket ke department ke liye HR/Admin contacts configured nahi hain, isliye alert preview abhi available nahi hai.'
                        );
                    } else {
                        $pendingAction['recipient_emails'] = $recipientEmails;
                    }
                }
            }

            if (!isset($answer)) {
                $_SESSION['admin_assistant_pending'] = $pendingAction;
                $answer = $actionMessage(
                    'I’ve prepared the action for review. Check the ticket and recipient details on the next page, then confirm there if everything is correct. Nothing has been sent or changed yet.',
                    'Maine action ka preview tayyar kiya hai. Agle page par ticket aur recipient details check karein; sab sahi ho toh wahin confirm karein. Abhi kuch bheja ya badla nahi gaya hai.'
                );
                $actionUrl = 'admin_assistant.php';
            }
        }
    }

    $history[] = ['role' => 'user', 'text' => $question];
    $assistantMessage = ['role' => 'assistant', 'text' => $answer];
    if (isset($actionUrl)) {
        $assistantMessage['action_url'] = $actionUrl;
        $assistantMessage['action_label'] = 'Review and confirm';
    }
    $history[] = $assistantMessage;
    $_SESSION['assistant_widget_chat'] = array_slice($history, -10);
    $response = ['answer' => $answer];
    if (isset($actionUrl)) {
        $response['action_url'] = $actionUrl;
        $response['action_label'] = 'Review and confirm';
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$history[] = ['role' => 'user', 'text' => $question];

$reportIntent = preg_match('/\b(?:excel|spreadsheet|workbook|sheet|csv|download|export)\b/i', $question) === 1;
if ($reportIntent) {
    $reportDate = assistantChatParseReportDate($question);
    if ($reportDate === null) {
        $answer = 'I can prepare a daily spreadsheet of tickets received on a date. Please specify the date, for example: "Excel sheet for 1 September".';
        $history[] = ['role' => 'assistant', 'text' => $answer];
        $_SESSION['assistant_widget_chat'] = array_slice($history, -10);
        echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    $answer = 'Your daily ticket spreadsheet is ready. It includes tickets received on '
        . DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate)->format('j F Y')
        . ' that you are authorized to view. The CSV file opens in Excel.';
    $history[] = ['role' => 'assistant', 'text' => $answer, 'download_date' => $reportDate];
    $_SESSION['assistant_widget_chat'] = array_slice($history, -10);
    echo json_encode([
        'answer' => $answer,
        'download_url' => 'daily_ticket_export.php?date=' . rawurlencode($reportDate),
        'download_label' => 'Download Excel sheet',
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$answer = generateAdminAssistantHelp($history);
if (!is_string($answer) || trim($answer) === '') {
    http_response_code(502);
    echo json_encode(['error' => 'Could not generate a troubleshooting reply. Please try again.']);
    exit;
}

$history[] = ['role' => 'assistant', 'text' => $answer];
$_SESSION['assistant_widget_chat'] = array_slice($history, -10);

echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
