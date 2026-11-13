<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/graph_helper.php';

requireLogin();

$ticketId = filter_var($_GET['ticket'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$attachmentId = $_GET['attachment'] ?? '';
$messageId = $_GET['message'] ?? '';
if ($ticketId === false || !is_string($attachmentId) || $attachmentId === '' || strlen($attachmentId) > 500
    || !is_string($messageId) || strlen($messageId) > 500) {
    http_response_code(400);
    exit('Invalid attachment request.');
}

$pdo = getDB();
$ticketStmt = $pdo->prepare(
    "SELECT t.graph_message_id, t.conversation_id, t.source_mailbox, u.email AS assignee_email
     FROM tickets t
     LEFT JOIN users u ON u.id = t.assigned_to
     WHERE t.id = ? AND " . portalTicketCondition('t')
);
$ticketStmt->execute([$ticketId]);
$ticket = $ticketStmt->fetch();
if (!$ticket) {
    http_response_code(404);
    exit('Email not found.');
}

$mailbox = $ticket['source_mailbox'] ?: ($ticket['assignee_email'] ?? '');
if ($mailbox === '' || empty($ticket['graph_message_id'])) {
    http_response_code(404);
    exit('The original email is unavailable.');
}

$accessToken = graphGetServiceToken() ?: (getenv('GRAPH_SERVICE_ACCESS_TOKEN') ?: null);
if (!$accessToken) {
    http_response_code(503);
    exit('Email document downloads are temporarily unavailable.');
}

// A later message in the thread may be requested, but only if it belongs to
// this ticket's conversation; otherwise any mailbox message could be fetched.
if ($messageId === '' || $messageId === $ticket['graph_message_id']) {
    $messageId = $ticket['graph_message_id'];
} else {
    $threadMessage = graphApiGet(
        '/users/' . rawurlencode($mailbox) . '/messages/' . rawurlencode($messageId) . '?$select=conversationId',
        $accessToken
    );
    if (!is_array($threadMessage) || isset($threadMessage['error'])
        || empty($ticket['conversation_id'])
        || !hash_equals((string)$ticket['conversation_id'], (string)($threadMessage['conversationId'] ?? ''))) {
        http_response_code(404);
        exit('The email document was not found.');
    }
}

$attachmentResponse = graphGetMessageAttachments($accessToken, $mailbox, $messageId);
if (isset($attachmentResponse['error'])) {
    error_log('Could not load email attachments for ticket ' . (int)$ticketId . ': '
        . ($attachmentResponse['error']['message'] ?? 'Unknown Graph error'));
    http_response_code(502);
    exit('Could not load the email document.');
}

$selectedAttachment = null;
foreach (($attachmentResponse['value'] ?? []) as $attachment) {
    if (is_array($attachment)
        && hash_equals((string)($attachment['id'] ?? ''), $attachmentId)
        && ($attachment['@odata.type'] ?? '') === '#microsoft.graph.fileAttachment'
        && empty($attachment['isInline'])) {
        $selectedAttachment = $attachment;
        break;
    }
}
if ($selectedAttachment === null) {
    http_response_code(404);
    exit('The email document was not found.');
}

$download = graphApiGetRaw(
    '/users/' . rawurlencode($mailbox)
        . '/messages/' . rawurlencode($messageId)
        . '/attachments/' . rawurlencode($attachmentId) . '/$value',
    $accessToken
);
if (isset($download['error'])) {
    error_log('Could not download email attachment for ticket ' . (int)$ticketId . ': '
        . ($download['error']['message'] ?? 'Unknown Graph error'));
    http_response_code(502);
    exit('Could not download the email document.');
}

$filename = trim((string)($selectedAttachment['name'] ?? 'email-document'));
$filename = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/', '_', $filename);
if ($filename === '' || $filename === '.' || $filename === '..') {
    $filename = 'email-document';
}
$asciiFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

header('X-Content-Type-Options: nosniff');
header('Content-Type: application/octet-stream');
header(
    'Content-Disposition: attachment; filename="' . $asciiFilename
    . '"; filename*=UTF-8\'\'' . rawurlencode($filename)
);
header('Content-Length: ' . strlen($download['content']));
echo $download['content'];
