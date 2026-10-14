<?php
/**
 * Send last week's department-scoped reports to managers and the full workbook
 * to the configured HR/Admin escalation contacts. Schedule for Monday 10:00 AM.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/weekly_report.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dryRunDirectory = null;
foreach ($argv as $argument) {
    if (strpos($argument, '--dry-run-dir=') === 0) {
        $dryRunDirectory = substr($argument, strlen('--dry-run-dir='));
    }
}
$dryRun = $dryRunDirectory !== null;

try {
    $pdo = getDB();
    weeklyReportCreateDeliveryTable($pdo);

    $lock = $pdo->prepare("SELECT GET_LOCK('escalation_tracker_weekly_reports', 0)");
    $lock->execute();
    if ((int)$lock->fetchColumn() !== 1) {
        fwrite(STDERR, "A weekly report run is already in progress.\n");
        exit(1);
    }

    $period = weeklyReportPeriod();
    $weekStart = $period['start'];
    $weekEnd = $period['end_inclusive'];
    $recipients = weeklyReportRecipients($pdo);
    if (!$recipients) {
        throw new RuntimeException('No report recipients are configured. Check department managers, HODs, and HR/Admin contacts.');
    }

    if ($dryRun) {
        if (!is_dir($dryRunDirectory) && !mkdir($dryRunDirectory, 0770, true) && !is_dir($dryRunDirectory)) {
            throw new RuntimeException("Unable to create dry-run output directory: {$dryRunDirectory}");
        }
        if (!is_writable($dryRunDirectory)) {
            throw new RuntimeException("Dry-run output directory is not writable: {$dryRunDirectory}");
        }
    } else {
        $accessToken = graphGetServiceToken();
        if (!$accessToken) {
            throw new RuntimeException('Microsoft Graph access token unavailable; no weekly reports were sent.');
        }
        $senderProfile = graphApiGet(
            '/users/' . rawurlencode(WEEKLY_REPORT_SENDER_MAILBOX) . '?$select=id',
            $accessToken
        );
        if (!empty($senderProfile['error']) || empty($senderProfile['id'])) {
            $message = $senderProfile['error']['message'] ?? 'Sender mailbox is unavailable.';
            throw new RuntimeException(WEEKLY_REPORT_SENDER_MAILBOX . ': ' . $message);
        }
    }

    $sentCount = 0;
    $skippedCount = 0;
    $failedCount = 0;
    foreach ($recipients as $recipient) {
        $reportKey = weeklyReportDeliveryKey($weekStart, $recipient);
        if (!$dryRun && weeklyReportWasSent($pdo, $reportKey)) {
            $skippedCount++;
            echo "Already sent; skipping {$recipient['email']} ({$recipient['scope']}).\n";
            continue;
        }

        try {
            $departmentId = $recipient['scope'] === 'department'
                ? (int)$recipient['department_id']
                : null;
            $workbook = weeklyReportBuildWorkbook(
                $pdo,
                $weekStart,
                $period['end_exclusive'],
                $departmentId
            );
            $scopeLabel = $recipient['scope'] === 'full'
                ? 'All Departments'
                : $recipient['department_name'];
            $filename = 'weekly-escalation-report-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $scopeLabel))
                . '-' . $weekStart->format('Y-m-d') . '.xlsx';

            if ($dryRun) {
                $safeRecipient = preg_replace('/[^A-Za-z0-9._-]+/', '_', $recipient['email']);
                $filePath = rtrim($dryRunDirectory, '\\/') . DIRECTORY_SEPARATOR . $safeRecipient . '-' . $filename;
                if (file_put_contents($filePath, $workbook, LOCK_EX) === false) {
                    throw new RuntimeException("Unable to save workbook to {$filePath}");
                }
                echo "DRY RUN: {$filePath} ({$recipient['scope']})\n";
                continue;
            }

            $body = '<p>Hello ' . htmlspecialchars($recipient['name'], ENT_QUOTES, 'UTF-8') . ',</p>'
                . '<p>Attached is the weekly email escalation report for '
                . htmlspecialchars($weekStart->format('d M Y'), ENT_QUOTES, 'UTF-8')
                . ' to ' . htmlspecialchars($weekEnd->format('d M Y'), ENT_QUOTES, 'UTF-8') . '.</p>'
                . '<p>This report includes received emails, escalations, open items, replies, and confirmed reminder/escalation email deliveries.</p>'
                . '<p>Regards,<br>Email Escalation Tracker</p>';
            $subject = 'Weekly Escalation Report - ' . $scopeLabel . ' - '
                . $weekStart->format('d M') . ' to ' . $weekEnd->format('d M Y');
            $attachment = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $filename,
                'contentType' => WEEKLY_REPORT_CONTENT_TYPE,
                'contentBytes' => base64_encode($workbook),
            ];
            $sent = graphSendMail(
                $accessToken,
                $recipient['email'],
                $subject,
                $body,
                WEEKLY_REPORT_SENDER_MAILBOX,
                [$attachment]
            );
            weeklyReportRecordDelivery($pdo, $reportKey, $recipient, $weekStart, $weekEnd, $sent);
            if ($sent) {
                $sentCount++;
                echo "Sent {$recipient['scope']} report to {$recipient['email']}.\n";
            } else {
                $failedCount++;
                fwrite(STDERR, "Graph did not accept the report for {$recipient['email']}; it can be retried.\n");
            }
        } catch (Throwable $exception) {
            $failedCount++;
            fwrite(STDERR, "Report failed for {$recipient['email']}: {$exception->getMessage()}\n");
            if (!$dryRun) {
                weeklyReportRecordDelivery($pdo, $reportKey, $recipient, $weekStart, $weekEnd, false);
            }
        }
    }

    if ($dryRun) {
        echo count($recipients) . " workbook(s) generated for {$weekStart->format('Y-m-d')} to {$weekEnd->format('Y-m-d')}; no emails sent.\n";
    } else {
        echo "{$sentCount} report(s) sent; {$skippedCount} already sent; {$failedCount} failed.\n";
    }
    $pdo->query("SELECT RELEASE_LOCK('escalation_tracker_weekly_reports')");
    exit($failedCount > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, "Weekly reports failed: {$exception->getMessage()}\n");
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->query("SELECT RELEASE_LOCK('escalation_tracker_weekly_reports')");
    }
    exit(1);
}
