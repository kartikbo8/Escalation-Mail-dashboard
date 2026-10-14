<?php
/**
 * includes/keyword_helper.php
 * Handles: (1) classifying emails by keyword category, (2) detecting
 * auto-replies (out-of-office / security auto-responses) so they are
 * never counted as a genuine reply.
 */
require_once __DIR__ . '/../config/db.php';

/**
 * Automated senders whose notifications must never enter the reminder or
 * escalation workflow, even when their subject contains a configured keyword.
 */
function isIgnoredSender($senderEmail) {
    $ignoredSenders = [
        'hrms@afcindia.in',
        'noreply.afcone@afcindia.in',
        'afcdesign@afcindia.in',
        'legal@afcindia.in',
        'sales09@afcindia.in',
        'studio.superadmin@afcindia.in',
        'studio.admin1@afcindia.in',
        'studio.superuser1@afcindia.in',
        'studio.user2@afcindia.in',
        'studio.user1@afcindia.in',
        'boardingpass@transaction.airindiaexpress.com',
        '_alerts@updates.rbl.bank.in',
        'alert@info.bigbasket.com',
        'alerts@axis.bank.in',
        'alerts@bwbusinessworld.com',
        'alerts@emails.nobrokerhood.com',
        'alerts@financialexpressonline.org',
        'alerts@hdfcbank.bank.in',
        'alerts@notification.my.rbl.bank.in',
        'alerts@rbl.bank.in',
        'alerts@uptimelinked.com',
        'automation@app.smartsheet.com',
        'bankalerts@kotak.bank.in',
        'business-noreply@global.metamail.com',
        'cmsalerts@kotak.bank.in',
        'comments-noreply@docs.google.com',
        'do-not-reply@rapidcoat.com',
        'donotreply.evoting@cdslindia.co.in',
        'donotreply.kyc@dhl.com',
        'donotreply@gst.gov.in',
        'donotreplyin@dhl.com',
        'erp.alerts@indiqube.com',
        'groups-noreply@linkedin.com',
        'import.alert@dhl.com',
        'mailer-daemon@googlemail.com',
        'mailer-daemon@mailerdaemon.mta.salesforce.com',
        'messages-noreply@linkedin.com',
        'messaging-digest-noreply@linkedin.com',
        'microsoft-noreply@microsoft.com',
        'newsletters-noreply@linkedin.com',
        'no-reply-forms@webflow',
        'no-reply@amazonpay.in',
        'no-reply@business.amazon.in',
        'no-reply@cashflo.io',
        'no-reply@dm.pvrcinemas.net',
        'no-reply@flyai.airindia.com',
        'no-reply@icicilombard.com',
        'no-reply@info.bookmyshow',
        'no-reply@npcl.rpsg.in',
        'no-reply@offers.ruloans.com',
        'no-reply@outlook.mail.microsoft',
        'no-reply@porter.in',
        'no-reply@razorpay.com',
        'no-reply@sampark.gov.in',
        'no-reply@sg.newsletter.agoda-emails.com',
        'no-reply@sharepointonline.com',
        'no-reply@sine.co',
        'no-reply@teams.mail.microsoft',
        'no-reply@updates.rdash.io',
        'no-reply@zipgrid.com',
        'noreply-8@apollo-privacy.com',
        'noreply-ai-notification@airindia.com',
        'noreply-userservice@makemytrip.com',
        'noreply.eglsupport@axis.bank.in',
        'noreply@aixfeedback.airindiaexpress.com',
        'noreply@aixflightinformation.airindiaexpress.com',
        'noreply@alerts.superprocure.com',
        'noreply@alerts2.superprocure.com',
        'noreply@content.goibibo.com',
        'noreply@email.globus.ch',
        'noreply@email.openai.com',
        'noreply@email.radissonhotels.com',
        'noreply@gem.gov.in',
        'noreply@icicilombard.com',
        'noreply@justdial.com',
        'noreply@makemytrip.com',
        'noreply@motilaloswal.com',
        'noreply@nobrokerhood.com',
        'noreply@safexpress.com',
        'noreply@salesforce.com',
        'noreply@statuspage.io',
        'noreply@tm.openai.com',
        'noreply@tx.wework.co.in',
        'noreply@verification.salesforce.com',
        'noreply@wetransfer.com',
        'noreply@zen-makemytrip.com',
        'salesforce@afcindia.in',
        'salesforce-noreply@afcindia.in',
        'notifications-noreply@linkedin.com',
        'office365alerts@microsoft.com',
        'office365reports@microsoft.com',
        'postmaster@archeegroup.com',
        'postmaster@axisbank.onmicrosoft.com',
        'postmaster@idfcfirstbank.com',
        'postmaster@indusind.onmicrosoft.com',
        'postmaster@sworks.co.in',
        'sapalert1@ozone-india.com',
        'updates-noreply@linkedin.com',
    ];

    $sender = strtolower(trim($senderEmail ?? ''));
    if (in_array($sender, $ignoredSenders, true)) {
        return true;
    }

    $localPart = strstr($sender, '@', true) ?: $sender;
    if (preg_match('/(^|[._-])salesforce([._-]|$)/', $localPart)) {
        return true;
    }

    return (bool)preg_match('/(^|[._-])(no-?reply|do-?not-?reply|donotreply|noreply)([._-]|$)/', $localPart);
}

function isExcludedSubject($subject) {
    $excludedKeywords = [
        'holiday',
        'non-working day',
        'non working day',
        'vacation',
        'office closed',
        'office closure',
        'festival',
        'restricted holiday',
        'gazetted holiday',
    ];

    foreach ($excludedKeywords as $keyword) {
        if (stripos($subject ?? '', $keyword) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Scan a SUBJECT LINE ONLY against the keyword_categories table and return
 * the highest-priority matching category + the specific keyword matched.
 * Priority order: escalation_trigger > customer_project > ppc_internal.
 * Per HR's matrix, only the SUBJECT is checked (not the body) - a keyword
 * match here marks the ticket "important" and queues it for the escalation
 * flow (Level 1 -> 2 -> 3). No match = stays with the employee, no escalation.
 *
 * Returns ['category' => string, 'keyword' => string] or
 * ['category' => 'normal', 'keyword' => null] if nothing matches.
 */
function isFalsePositiveKeywordSubject($subject) {
    $text = strtolower($subject ?? '');

    $patterns = [
        '/\burgent\b.*\b(job opportunity|job|developer required|hiring|recruitment|vacancy)\b/i',
        '/\b(job opportunity|developer required|hiring|recruitment|vacancy)\b.*\burgent\b/i',
        '/\burgent\b.*\bopportunity\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }

    return false;
}

function matchTicketKeywords($subject) {
    $pdo = getDB();
    $haystack = strtolower($subject ?? '');

    if (isFalsePositiveKeywordSubject($subject)) {
        return ['category' => 'normal', 'keyword' => null];
    }

    // Keep the escalation queue deterministic even when the configured
    // keyword table is empty or a fresh database seed does not carry the
    // trigger row yet. This matches the user's requested urgent escalation
    // path and avoids false positives such as job-opportunity phrasing.
    if (preg_match('/\b(?:urgent|urgently)\b/i', (string)($subject ?? '')) === 1) {
        return ['category' => 'escalation_trigger', 'keyword' => 'URGENT'];
    }

    // Add requested order-intent phrases the same way the rest of the subject
    // keyword engine understands high-signal customer/project signals.
    if (preg_match('/\b(?:new\s+order|new\s+order\s+request|new\s+project\s+order|order\s+request|order\s+confirmation)\b/i', (string)($subject ?? '')) === 1) {
        return ['category' => 'customer_project', 'keyword' => 'NEW ORDER'];
    }

    $stmt = $pdo->query("SELECT category, keyword FROM keyword_categories");
    $rows = $stmt->fetchAll();

    $priorityOrder = ['escalation_trigger', 'customer_project', 'ppc_internal'];
    $matches = []; // category => first matched keyword

    foreach ($rows as $row) {
        $needle = strtolower($row['keyword']);
        // Match complete words/phrases only. For example, the configured
        // keyword "PO" must not make "Opportunity" an important email.
        // A number immediately after a keyword is allowed for references
        // such as "PO1943909".
        $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote($needle, '/') . '(?![\\p{L}])/iu';
        if ($needle !== '' && preg_match($pattern, $subject ?? '') === 1) {
            if (!isset($matches[$row['category']])) {
                $matches[$row['category']] = $row['keyword'];
            }
        }
    }

    foreach ($priorityOrder as $cat) {
        if (isset($matches[$cat])) {
            return ['category' => $cat, 'keyword' => $matches[$cat]];
        }
    }

    return ['category' => 'normal', 'keyword' => null];
}

/**
 * Heuristic check: is this message an automatic reply (out-of-office,
 * mailbox-full, security auto-response, delivery/read receipt) rather
 * than a genuine human reply? These should NEVER close/mark a ticket
 * as replied.
 *
 * $subject   - message subject
 * $headers   - optional array of internetMessageHeaders from Graph API
 *              (call messages endpoint with $select=internetMessageHeaders
 *              to get these)
 */
function isAutoReply($subject, $headers = null) {
    $subjectLower = strtolower($subject ?? '');

    $autoSubjectPhrases = [
        'automatic reply', 'auto reply', 'auto-reply', 'out of office',
        'out-of-office', 'ooo:', 'autoresponder', 'undeliverable',
        'delivery status notification', 'read receipt', 'undelivered mail',
        'mail delivery failed', 'automatic response',
    ];
    foreach ($autoSubjectPhrases as $phrase) {
        if (strpos($subjectLower, $phrase) !== false) {
            return true;
        }
    }

    // Check standard auto-reply headers if Graph API headers were fetched
    if (is_array($headers)) {
        foreach ($headers as $h) {
            $name = strtolower($h['name'] ?? '');
            $value = strtolower($h['value'] ?? '');

            if ($name === 'x-auto-response-suppress') return true;
            if ($name === 'auto-submitted' && $value !== 'no') return true;
            if ($name === 'x-autoreply' || $name === 'x-autorespond') return true;
            if ($name === 'precedence' && in_array($value, ['auto_reply', 'bulk', 'junk'])) return true;
        }
    }

    return false;
}
