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
        'notifications@afcindia.in',
        // Marketing / newsletter senders whose subjects use urgent words (TODAY, CRITICAL...).
        'surveys@grantthornton.in',
        'news@pockethrms.com',
        'connect@marketingnow.hp.com',
        'techcomms@mail.salesforce.com',
        'info@marsh.com',
        'insights@kornferry.com',
        'shashwat.singh@aon.com',
        'steffie.nitin.bansode@aon.com',
        'aditi@payzorro.io',
        'ardourinternationalofficial@gmail.com',
        'info@wefloweraura.com',
        'commerciale@neovisione.com',
        'global-trade@desk.larkagent.us',
        'liamt@engiconstructionsth.com',
        'marcus.tan@couserasz.com',
        'workshop@gmail.com',
        // Marketing / webinar / gifting promo senders (seen Aug-Oct 2026).
        'webinars@grantthornton.in',
        'alerts@jobsalert.shine.com',
        'insights@reply.kornferry.com',
        'info@druva.com',
        'prasad.kachraj@greatplacetowork.in',
        'ashish.agrawal@greatplacetowork.in',
        'rashi.girish.bhutada@aon.com',
        'founder@ambitionbox.com',
        'gfordinstitute779@gmail.com',
        'trainingonline716@gmail.com',
        'mahima@itsonlineweb.com',
        'innercircle@tajhotels.com',
        'vrutika.bhimani@rpsg.in',
        'contact@thegiftstudio.com',
        'info@updates.igp.com',
        'corporate@igp.com',
        'marketing@shiv.enterprises',
        'aakash.srivastava@benepik.com',
        'care@updates.nicobar.com',
        'email@updates.floweraura.com',
        'heropenindia@gmail.com',
        'consumers@updates.consumer.pluxee.in',
        'juhi.bajaj@pluxeegroup.com',
        'business@ma.ongrid.in',
    ];

    $sender = strtolower(trim($senderEmail ?? ''));
    // Internal Salesforce mailbox carries real work; keep it in the normal workflow.
    if ($sender === 'salesforce@afcindia.in') {
        return false;
    }
    if (in_array($sender, $ignoredSenders, true)) {
        return true;
    }

    // Job portals: every address on these domains is automated.
    if (preg_match('/@(?:[a-z0-9-]+\.)*(?:naukri\.com|workindia\.in)$/i', $sender)) {
        return true;
    }

    $localPart = strstr($sender, '@', true) ?: $sender;
    if (preg_match('/(^|[._-])salesforce([._-]|$)/', $localPart)) {
        return true;
    }

    return (bool)preg_match('/(^|[._-])(no-?reply|do-?not-?reply|donotreply|noreply)([._-]|$)/', $localPart);
}

/**
 * Reminder/escalation alerts sent by this tracker itself. They land back in
 * monitored mailboxes and must never become tickets or escalate again.
 */
function isSystemNotificationMail($senderEmail, $subject) {
    if (strtolower(trim($senderEmail ?? '')) === 'notifications@afcindia.in') {
        return true;
    }

    // Match only the exact subjects built in cron/check_escalations.php, so a
    // client mail like "[Reminder] Payment pending" is still treated as original.
    return (bool)preg_match(
        '/^\s*(?i:(?:re|fw|fwd)\s*:\s*)*\[(?:'
            . '(?:REMINDER\] \d+ pending|ESCALATION ALERT\] \d+ overdue) email task\(s\)'
            . '|ACTION REQUIRED - Level \d Escalation\] Employee response overdue:'
            . '|FINAL ESCALATION\] Unresolved after Manager\/HOD:'
            . '|REMINDER\] Escalated item still open:)/',
        $subject ?? ''
    );
}

/**
 * Mails that never need a reply: bounces, meeting responses, Salesforce
 * approval notices, and outside promos/phishing. They may still be tracked,
 * but must not get reminders or escalations.
 */
function isNoReplyNeededMail($senderEmail, $subject) {
    $sender = strtolower(trim($senderEmail ?? ''));
    $subject = (string)($subject ?? '');

    if (preg_match('/^(?:mailer-daemon|postmaster)@/', $sender)
        || preg_match('/^\s*(?:undeliverable|delivery (?:status notification|has failed)|mail delivery failed)\b/i', $subject)) {
        return true;
    }
    if (preg_match('/^\s*(?:accepted|declined|tentative|canceled|cancelled)\s*:/i', $subject)) {
        return true;
    }
    if ($sender === 'salesforce@afcindia.in' && preg_match('/\bhas been approved\b/i', $subject)) {
        return true;
    }
    if (!str_ends_with($sender, '@afcindia.in') && preg_match(
        '/\b(?:webinar|newsletter|gifting|register now|diwali sale)\b'
            . '|\b(?:mailbox termination|deactivation notice|card has been locked|password (?:will |is about to |has )?expir)/i',
        $subject
    )) {
        return true;
    }

    return false;
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

function isEodAcknowledgementContent($subject, $body = '') {
    $content = (string)$subject . "\n" . (string)$body;
    $hasEod = preg_match('/\b(?:eod|end\s+of\s+(?:the\s+)?day)\b/i', $content) === 1;
    $hasAcknowledgement = preg_match(
        '/\b(?:ack|acknowledg(?:e|ed|es|ing)|acknowledgements?|acknowledgments?)\b/i',
        $content
    ) === 1;

    return $hasEod && $hasAcknowledgement;
}

function normalizeMailBodyText($body, $contentType = '') {
    $body = (string)$body;
    if (strcasecmp((string)$contentType, 'html') === 0) {
        $body = preg_replace('/<[^>]*>/', ' ', $body);
        $body = strip_tags($body);
    }

    return html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Scan the subject and full message body against configured keyword categories.
 * Returns the highest-priority matching category and keyword.
 */
/**
 * Keep only the newest reply - drop the quoted earlier thread below it.
 * HTML mails lose their line breaks when flattened, so Outlook's
 * "From: ... Sent:" header is also matched mid-line.
 */
function stripQuotedReplyText($body) {
    $parts = preg_split(
        '/\R\s*(?:From:|-----\s*Original Message|_{5,}|On .{5,120} wrote:|Sent from my )'
            . '|\sFrom:\s.{1,200}?\sSent:\s/iu',
        (string)$body
    );
    return $parts === false ? (string)$body : $parts[0];
}

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

/**
 * Keywords are matched in the mail BODY only - never in the subject. The
 * subject is used just to drop known false positives (job-opportunity spam).
 */
function matchTicketKeywords($subject, $body = '') {
    $pdo = getDB();
    $content = (string)($body ?? '');

    if (isFalsePositiveKeywordSubject($subject)) {
        return ['category' => 'normal', 'keyword' => null];
    }

    if (isEodAcknowledgementContent('', $content)) {
        return ['category' => 'escalation_trigger', 'keyword' => 'EOD + ACKNOWLEDGEMENT'];
    }

    // Keep the escalation queue deterministic even when the configured
    // keyword table is empty or a fresh database seed does not carry the
    // trigger row yet. This matches the user's requested urgent escalation
    // path and avoids false positives such as job-opportunity phrasing.
    if (preg_match('/\b(?:urgent|urgently)\b/i', $content) === 1) {
        return ['category' => 'escalation_trigger', 'keyword' => 'URGENT'];
    }

    // Add requested order-intent phrases the same way the rest of the
    // keyword engine understands high-signal customer/project signals.
    if (preg_match('/\b(?:new\s+order|new\s+order\s+request|new\s+project\s+order|order\s+request|order\s+confirmation)\b/i', $content) === 1) {
        return ['category' => 'customer_project', 'keyword' => 'NEW ORDER'];
    }

    $stmt = executePreparedQuery($pdo, "SELECT category, keyword FROM keyword_categories");
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
        if ($needle !== '' && preg_match($pattern, $content) === 1) {
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
