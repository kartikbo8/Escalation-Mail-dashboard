<?php
/**
 * public/test_graph_connection.php
 *
 * Quick sanity check after setting up Application Permissions + Admin Consent
 * in Azure. Open this in browser to verify the connection BEFORE running the
 * real cron scripts. Delete or restrict this file once everything works.
 *
 * Usage: http://localhost:8080/escalation-tracker/public/test_graph_connection.php?mailbox=sales@afcindia.in
 */
require_once __DIR__ . '/../includes/auth.php';
requireRole(['super_admin', 'admin']);
require_once __DIR__ . '/../includes/graph_helper.php';

header('Content-Type: text/plain');

echo "Step 1: Requesting app-only access token...\n";
$tokenResult = graphGetAppOnlyToken();

if (!isset($tokenResult['access_token'])) {
    echo "FAILED.\n";
    echo "Error: " . ($tokenResult['error'] ?? 'unknown') . "\n";
    echo "Description: " . ($tokenResult['error_description'] ?? 'none') . "\n";
    echo "\nCommon causes:\n";
    echo "- Client ID / Secret / Tenant ID wrong in config/graph.php\n";
    echo "- Client secret expired\n";
    echo "- Application permissions not added, or Admin Consent not granted yet\n";
    exit;
}

echo "SUCCESS - access token received (expires in " . ($tokenResult['expires_in'] ?? '?') . " seconds)\n\n";

$mailbox = $_GET['mailbox'] ?? null;

if (!$mailbox) {
    echo "Step 2: Skipped - no ?mailbox=someone@afcindia.in given in the URL.\n";
    echo "Try again like this to test reading a specific mailbox:\n";
    echo "  ?mailbox=your-test-mailbox@afcindia.in\n";
    exit;
}

echo "Step 2: Attempting to read 5 messages from: {$mailbox}\n";
$messages = graphGetMessages($tokenResult['access_token'], $mailbox, 5);

if (isset($messages['error'])) {
    echo "FAILED.\n";
    echo "Error: " . ($messages['error']['message'] ?? json_encode($messages['error'])) . "\n";
    echo "\nCommon causes:\n";
    echo "- Mail.Read Application permission missing or consent not granted\n";
    echo "- Mailbox address doesn't exist / typo\n";
    exit;
}

echo "SUCCESS - able to read mailbox.\n";
echo "Messages found: " . count($messages['value'] ?? []) . "\n\n";

if (count($messages['value'] ?? []) === 0) {
    echo "DEBUG - raw API response (helps figure out why 0 came back):\n";
    echo json_encode($messages, JSON_PRETTY_PRINT) . "\n\n";
}

foreach (($messages['value'] ?? []) as $msg) {
    echo "- [{$msg['receivedDateTime']}] {$msg['subject']} (from: {$msg['from']['emailAddress']['address']})\n";
}
