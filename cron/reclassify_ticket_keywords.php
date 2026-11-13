<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Reapply the current body-keyword rules to existing tickets.
 * Run after changing keyword rules:
 * C:\xampp\php\php.exe cron\reclassify_ticket_keywords.php
 *
 * Bodies are not stored in the database, so each one is loaded from
 * Microsoft 365. A ticket whose body cannot be loaded is left unchanged.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/keyword_helper.php';
require_once __DIR__ . '/../includes/graph_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$accessToken = graphGetServiceToken();
if (!$accessToken) {
    echo "No Graph access token available. No tickets were reclassified.\n";
    exit(1);
}

$pdo = getDB();
$tickets = executePreparedQuery($pdo,
    'SELECT id, subject, sender_email, source_mailbox, graph_message_id,
            priority_tag, matched_keyword, is_important, escalation_queued
     FROM tickets'
)->fetchAll();

$update = $pdo->prepare(
    'UPDATE tickets
     SET priority_tag = ?, matched_keyword = ?, is_important = ?, escalation_queued = ?
     WHERE id = ?'
);

$changed = 0;
$skipped = 0;
foreach ($tickets as $ticket) {
    $message = graphGetMessageBody($accessToken, $ticket['source_mailbox'], $ticket['graph_message_id']);
    if (!isset($message['body']['content'])) {
        $skipped++;
        continue;
    }
    $body = normalizeMailBodyText($message['body']['content'], $message['body']['contentType'] ?? '');
    $match = isExcludedSubject($ticket['subject'])
        ? ['category' => 'normal', 'keyword' => null]
        : matchTicketKeywords($ticket['subject'], $body);
    if (isIgnoredSender($ticket['sender_email'] ?? '')) {
        $match = ['category' => 'normal', 'keyword' => null];
    }
    $isImportant = $match['category'] === 'normal' ? 0 : 1;

    if ($ticket['priority_tag'] !== $match['category']
        || $ticket['matched_keyword'] !== $match['keyword']
        || (int)$ticket['is_important'] !== $isImportant
        || (int)$ticket['escalation_queued'] !== $isImportant) {
        $update->execute([
            $match['category'], $match['keyword'], $isImportant, $isImportant, $ticket['id'],
        ]);
        $changed++;
    }
}

echo "Reclassified {$changed} of " . count($tickets) . " ticket(s); "
    . "{$skipped} left unchanged because their body could not be loaded.\n";
