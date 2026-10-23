<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
$user = currentUser();
$portalName = portalDepartmentName();
$assistantWidgetHistory = $_SESSION['assistant_widget_chat'] ?? [];
if (!is_array($assistantWidgetHistory)) {
    $assistantWidgetHistory = [];
}
$storageMonthStart = new DateTimeImmutable('first day of this month 00:00:00');
$storageMonthEnd = $storageMonthStart->modify('first day of next month');
$storageStmt = getDB()->prepare(
    "SELECT COUNT(*) AS email_count,
            COALESCE(SUM(
                OCTET_LENGTH(COALESCE(t.subject, '')) +
                OCTET_LENGTH(COALESCE(t.sender_name, '')) +
                OCTET_LENGTH(COALESCE(t.sender_email, '')) +
                OCTET_LENGTH(COALESCE(t.conversation_id, '')) +
                OCTET_LENGTH(COALESCE(t.graph_message_id, '')) +
                OCTET_LENGTH(COALESCE(t.source_mailbox, '')) +
                OCTET_LENGTH(COALESCE(t.matched_keyword, ''))
            ), 0) AS stored_bytes
     FROM tickets t
     WHERE t.received_at >= ?
       AND t.received_at < ?
       AND " . portalTicketCondition('t')
);
$storageStmt->execute([
    $storageMonthStart->format('Y-m-d H:i:s'),
    $storageMonthEnd->format('Y-m-d H:i:s'),
]);
$monthlyEmailStorage = $storageStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $portalName ? htmlspecialchars($portalName) . ' Portal - ' : '' ?>Email Escalation Tracker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/theme.css?v=<?= filemtime(__DIR__ . '/../public/assets/css/theme.css') ?>" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><?= $portalName ? htmlspecialchars($portalName) . ' Portal' : 'Escalation Tracker' ?></a>
        <div class="d-flex align-items-center gap-3">
            <span class="navbar-text text-white">
                <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['role']) ?>)
            </span>
            <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
    </div>
</nav>

<div class="assistant-widget" data-chat-url="assistant_chat.php" data-csrf-token="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
    <section class="assistant-widget-panel" id="assistant-widget-panel" aria-label="AI help chat" hidden>
        <header class="assistant-widget-header">
            <div>
                <strong>AI Help</strong>
                <span>Ask about this app; no need to repeat its name</span>
            </div>
            <button type="button" class="assistant-widget-close" aria-label="Close AI help" title="Close">×</button>
        </header>
        <div class="assistant-widget-messages" aria-live="polite" aria-relevant="additions text">
            <?php foreach ($assistantWidgetHistory as $chatMessage): ?>
                <?php if (is_array($chatMessage)
                    && isset($chatMessage['role'], $chatMessage['text'])
                    && in_array($chatMessage['role'], ['user', 'assistant'], true)
                    && is_string($chatMessage['text'])): ?>
                    <div class="assistant-widget-message <?= $chatMessage['role'] === 'user' ? 'is-user' : 'is-assistant' ?>">
                        <?= nl2br(htmlspecialchars($chatMessage['text'], ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                    <?php if ($chatMessage['role'] === 'assistant'
                        && isset($chatMessage['download_date'])
                        && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$chatMessage['download_date'])): ?>
                        <a class="assistant-widget-download"
                            href="daily_ticket_export.php?date=<?= rawurlencode($chatMessage['download_date']) ?>"
                            download>Download Excel sheet</a>
                    <?php endif; ?>
                    <?php if ($chatMessage['role'] === 'assistant'
                        && ($chatMessage['action_url'] ?? '') === 'admin_assistant.php'): ?>
                        <a class="assistant-widget-download" href="admin_assistant.php">
                            <?= htmlspecialchars(
                                is_string($chatMessage['action_label'] ?? null)
                                    ? $chatMessage['action_label']
                                    : 'Review and confirm',
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if (!$assistantWidgetHistory): ?>
                <div class="assistant-widget-empty">Hi! Ask me about app errors or how the escalation workflow works.</div>
            <?php endif; ?>
        </div>
        <div class="assistant-widget-notice">Don't share passwords, tokens, personal data, or email content.</div>
        <form class="assistant-widget-form">
            <label class="visually-hidden" for="assistant-widget-input">Ask AI Help</label>
            <textarea id="assistant-widget-input" name="question" rows="1" maxlength="1200"
                placeholder="Type your question..." required></textarea>
            <button type="submit" aria-label="Send question">Send</button>
        </form>
        <div class="assistant-widget-status" role="status" aria-live="polite"></div>
    </section>
    <button type="button" class="assistant-widget-launcher" aria-controls="assistant-widget-panel"
        aria-expanded="false" aria-label="Open AI help chat" title="AI Help">
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M4 4.75A2.75 2.75 0 0 1 6.75 2h10.5A2.75 2.75 0 0 1 20 4.75v8.5A2.75 2.75 0 0 1 17.25 16H10l-5.5 4v-4.5A2.75 2.75 0 0 1 4 13.5V4.75Zm4.25 3a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Zm3.75 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Zm3.75 0a1.25 1.25 0 1 0 0 2.5 1.25 1.25 0 0 0 0-2.5Z"/>
        </svg>
    </button>
</div>

<div class="app-shell">
    <aside class="app-sidebar">
        <div class="sidebar-section-label">MENU</div>
        <nav class="app-sidebar-nav" aria-label="Main navigation">
            <a class="sidebar-link" href="dashboard.php">Dashboard</a>
            <a class="sidebar-link" href="all_emails.php">All Emails</a>
            <a class="sidebar-link" href="email_list.php">Email List</a>
            <?php if (in_array($user['role'], ['super_admin', 'admin'], true)): ?>
                <a class="sidebar-link" href="admin_assistant.php">AI Action Assistant</a>
                <a class="sidebar-link" href="users.php">Users</a>
                <a class="sidebar-link" href="escalation_matrix_export.php">Escalation Excel</a>
            <?php endif; ?>
            <?php if ($user['role'] === 'super_admin'): ?>
                <a class="sidebar-link" href="settings.php">Settings</a>
            <?php endif; ?>
            <?php if ($portalName): ?>
                <a class="sidebar-link" href="portal.php?department=all">Merge Portal</a>
            <?php endif; ?>
        </nav>

        <section class="sidebar-storage" aria-label="Email data storage from this month">
            <div class="sidebar-section-label">EMAIL DATA</div>
            <div class="sidebar-storage-month"><?= htmlspecialchars($storageMonthStart->format('F Y')) ?></div>
            <div class="sidebar-storage-size">
                <?= number_format((int)$monthlyEmailStorage['stored_bytes'] / (1024 * 1024), 2) ?> MB
            </div>
            <div class="sidebar-storage-count">
                <?= number_format((int)$monthlyEmailStorage['email_count']) ?> email(s)
            </div>
            <div class="sidebar-storage-note">
                Stored email fields only; message bodies remain in Microsoft 365.
            </div>
        </section>
    </aside>
    <main class="app-main">
        <div class="container-fluid mt-4">
