<?php
/**
 * Reapply the current subject-keyword rules to existing tickets.
 * Run after changing keyword rules:
 * C:\xampp\php\php.exe cron\reclassify_ticket_keywords.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/keyword_helper.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = getDB();
$tickets = $pdo->query(
    'SELECT id, subject, sender_email, priority_tag, matched_keyword, is_important, escalation_queued FROM tickets'
)->fetchAll();

$update = $pdo->prepare(
    'UPDATE tickets
     SET priority_tag = ?, matched_keyword = ?, is_important = ?, escalation_queued = ?
     WHERE id = ?'
);

$changed = 0;
foreach ($tickets as $ticket) {
    $match = isExcludedSubject($ticket['subject'])
        ? ['category' => 'normal', 'keyword' => null]
        : matchTicketKeywords($ticket['subject']);
    if ($match['category'] !== 'escalation_trigger' && isIgnoredSender($ticket['sender_email'] ?? '')) {
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

echo "Reclassified {$changed} of " . count($tickets) . " ticket(s).\n";
