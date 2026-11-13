<?php
/**
 * Microsoft Graph API helper functions
 * Requires config/graph.php to be filled in with your Azure App Registration values.
 */
require_once __DIR__ . '/../config/graph.php';

/**
 * Exchange an authorization code (from OAuth redirect) for access + refresh tokens.
 * Call this once from your callback.php after user logs in via Microsoft.
 * (Only needed for DELEGATED permission flow - not needed for app-only/Application
 * permission flow below, which is what you'd use to cover multiple mailboxes.)
 */
function graphGetTokenFromCode($code) {
    $ch = curl_init(GRAPH_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GRAPH_CLIENT_ID,
            'client_secret' => GRAPH_CLIENT_SECRET,
            'code'          => $code,
            'redirect_uri'  => GRAPH_REDIRECT_URI,
            'grant_type'    => 'authorization_code',
            'scope'         => GRAPH_SCOPES,
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Refresh an expired access token using the stored refresh token.
 */
function graphRefreshToken($refreshToken) {
    $ch = curl_init(GRAPH_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GRAPH_CLIENT_ID,
            'client_secret' => GRAPH_CLIENT_SECRET,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
            'scope'         => GRAPH_SCOPES,
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * APP-ONLY TOKEN (Client Credentials flow) - use this once your Azure app has
 * ADMIN-CONSENTED "Application permissions" (Mail.Read, Mail.Send), not
 * Delegated. This does NOT require any user to log in - the app authenticates
 * as itself and can then read/send mail for ANY mailbox in the tenant that you
 * pass into graphGetMessages()/graphSendMail() (mailbox = department's email
 * address). This is what the cron scripts should use for multi-department access.
 *
 * Returns an array with 'access_token' and 'expires_in' (seconds), or an
 * array containing 'error' on failure.
 */
function graphGetAppOnlyToken() {
    $ch = curl_init(GRAPH_TOKEN_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id'     => GRAPH_CLIENT_ID,
            'client_secret' => GRAPH_CLIENT_SECRET,
            'scope'         => 'https://graph.microsoft.com/.default',
            'grant_type'    => 'client_credentials',
        ]),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

/**
 * Convenience wrapper: get a ready-to-use app-only access token string,
 * or null (with an error logged) if something's wrong (bad secret, consent
 * not granted yet, etc). Call this at the top of cron scripts instead of
 * reading GRAPH_SERVICE_ACCESS_TOKEN from the environment.
 */
function graphGetServiceToken() {
    $result = graphGetAppOnlyToken();

    if (isset($result['access_token'])) {
        return $result['access_token'];
    }

    $error = $result['error_description'] ?? $result['error'] ?? 'Unknown error';
    error_log("Graph API app-only token fetch failed: {$error}");
    return null;
}

/**
 * Fetch ALL users in the tenant (paginated), with basic profile fields.
 * Requires "User.Read.All" Application permission + Admin Consent.
 * Filters to enabled, real (non-guest) accounts with a mailbox.
 */
function graphListAllUsers($accessToken) {
    $users = [];
    $select = 'id,displayName,mail,userPrincipalName,jobTitle,department,accountEnabled,userType';
    $filter = "accountEnabled eq true and userType eq 'Member'";
    $endpoint = "/users?\$select={$select}&\$filter=" . urlencode($filter) . "&\$top=999";

    while ($endpoint) {
        $isFullUrl = strpos($endpoint, 'https://') === 0;
        $ch = curl_init($isFullUrl ? $endpoint : GRAPH_API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        ]);
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['error'])) {
            return ['error' => $response['error']];
        }

        foreach (($response['value'] ?? []) as $u) {
            // Only keep users that actually have a mailbox
            if (!empty($u['mail']) || !empty($u['userPrincipalName'])) {
                $users[] = $u;
            }
        }

        $endpoint = $response['@odata.nextLink'] ?? null;
    }

    return $users;
}

/**
 * Fetch a single user's manager (display name + email), or null if none set.
 * Requires "User.Read.All" Application permission.
 */
function graphGetUserManager($accessToken, $userIdOrEmail) {
    $result = graphApiGet("/users/{$userIdOrEmail}/manager?\$select=displayName,mail,userPrincipalName", $accessToken);

    if (isset($result['error']) || !isset($result['id'])) {
        return null;
    }

    return [
        'name'  => $result['displayName'] ?? '',
        'email' => $result['mail'] ?? $result['userPrincipalName'] ?? '',
    ];
}

/**
 * Generic authenticated GET request to Graph API.
 */
function graphApiGet($endpoint, $accessToken) {    $ch = curl_init(GRAPH_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function graphApiGetRaw($endpoint, $accessToken) {
    $ch = curl_init(GRAPH_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/octet-stream',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '') {
        return ['error' => ['message' => $curlError !== '' ? $curlError : 'Could not download the attachment.']];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorResponse = json_decode($response, true);
        return [
            'error' => [
                'code' => $httpCode,
                'message' => $errorResponse['error']['message'] ?? 'Microsoft Graph could not download the attachment.',
            ],
        ];
    }

    return ['content' => $response];
}

/**
 * Fetch recent messages from a mailbox (delta/new messages).
 * $mailbox = 'me' or a shared mailbox address/id.
 */
function graphGetMessages($accessToken, $mailbox = 'me', $top = 200, $maxResults = 500, $since = null, $until = null) {
    $messages = [];
    $orderBy = urlencode('receivedDateTime desc');
    $baseEndpoint = ($mailbox === 'me')
        ? '/me/messages'
        : '/users/' . rawurlencode($mailbox) . '/messages';
    
    // Default to the last 7 days only. Re-reading the whole mailbox every run made
    // each sync take minutes and turned months-old mail into new tickets; an
    // older day can still be synced on purpose with fetch_new_mails.php --date=.
    if ($since === null) {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days'));
    }
    $filterExpression = 'receivedDateTime ge ' . $since;
    if ($until !== null) {
        $filterExpression .= ' and receivedDateTime lt ' . $until;
    }
    $filter = urlencode($filterExpression);
    
    $select = urlencode('id,conversationId,from,toRecipients,subject,bodyPreview,receivedDateTime');
    $endpoint = $baseEndpoint . "?\$top={$top}&\$orderby={$orderBy}&\$filter={$filter}&\$select={$select}";

    while ($endpoint && count($messages) < $maxResults) {
        $isFullUrl = strpos($endpoint, 'https://') === 0;
        $ch = curl_init($isFullUrl ? $endpoint : GRAPH_API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['error'])) {
            return $response;
        }

        foreach (($response['value'] ?? []) as $msg) {
            $messages[] = $msg;
            if (count($messages) >= $maxResults) {
                break 2;
            }
        }

        $endpoint = $response['@odata.nextLink'] ?? null;
    }

    return ['value' => $messages];
}

/**
 * Fetch one original message, including its body, for escalation context.
 */
function graphGetMessage($accessToken, $mailbox, $messageId) {
    if (!$mailbox || !$messageId) return null;
    $endpoint = "/users/" . rawurlencode($mailbox) . "/messages/" . rawurlencode($messageId)
        . "?\$select=subject,from,toRecipients,ccRecipients,body,receivedDateTime";
    $message = graphApiGet($endpoint, $accessToken);
    if (!is_array($message) || isset($message['error'])) return null;

    // No $select here: contentBytes only exists on fileAttachment, and Graph
    // rejects selecting it on the base attachment collection.
    $attachmentsEndpoint = "/users/" . rawurlencode($mailbox) . "/messages/"
        . rawurlencode($messageId) . "/attachments";
    $attachments = graphApiGet($attachmentsEndpoint, $accessToken);
    $message['attachments'] = $attachments['value'] ?? [];
    return $message;
}

/**
 * Fetch only the body of an original message for the ticket detail page.
 */
function graphGetMessageBody($accessToken, $mailbox, $messageId) {
    if (!$mailbox || !$messageId) return null;
    $endpoint = "/users/" . rawurlencode($mailbox) . "/messages/" . rawurlencode($messageId)
        . "?\$select=body";
    return graphApiGet($endpoint, $accessToken);
}

/**
 * Fetch the original email headers, body, and non-binary attachment metadata
 * for the ticket detail page.
 */
function graphGetMessageForDisplay($accessToken, $mailbox, $messageId) {
    if (!$mailbox || !$messageId) {
        return null;
    }

    $messageEndpoint = "/users/" . rawurlencode($mailbox) . "/messages/"
        . rawurlencode($messageId)
        . "?\$select=subject,from,toRecipients,ccRecipients,body,receivedDateTime,hasAttachments";
    $message = graphApiGet($messageEndpoint, $accessToken);
    if (isset($message['error']) || empty($message['hasAttachments'])) {
        return $message;
    }

    $attachmentResponse = graphGetMessageAttachments($accessToken, $mailbox, $messageId);
    if (isset($attachmentResponse['error'])) {
        $message['attachments'] = [];
        $message['attachments_error'] = $attachmentResponse['error']['message']
            ?? 'Microsoft Graph could not load attachment details.';
        return $message;
    }
    $message['attachments'] = $attachmentResponse['value'] ?? [];
    return $message;
}

function graphGetMessageAttachments($accessToken, $mailbox, $messageId) {
    if (!$mailbox || !$messageId) {
        return null;
    }

    $attachmentsEndpoint = "/users/" . rawurlencode($mailbox) . "/messages/"
        . rawurlencode($messageId) . "/attachments?\$select=id,name,contentType,size,isInline";
    $attachmentResponse = graphApiGet($attachmentsEndpoint, $accessToken);
    if (isset($attachmentResponse['error'])) {
        return $attachmentResponse;
    }

    foreach (($attachmentResponse['value'] ?? []) as $index => $attachment) {
        if (!is_array($attachment)) {
            continue;
        }
        if (($attachment['@odata.type'] ?? '') === '#microsoft.graph.referenceAttachment'
            && !empty($attachment['id'])) {
            $referenceEndpoint = "/users/" . rawurlencode($mailbox) . "/messages/"
                . rawurlencode($messageId) . "/attachments/"
                . rawurlencode($attachment['id']);
            $referenceDetails = graphApiGet($referenceEndpoint, $accessToken);
            if (!isset($referenceDetails['error'])) {
                $attachment['sourceUrl'] = $referenceDetails['sourceUrl'] ?? null;
                $attachment['providerType'] = $referenceDetails['providerType'] ?? null;
            }
        }
        $attachmentResponse['value'][$index] = $attachment;
    }

    return $attachmentResponse;
}

/**
 * Attachments sent later in the same conversation (client/employee replies),
 * oldest first. The ticket's own message is skipped.
 */
function graphGetConversationAttachments($accessToken, $mailbox, $conversationId, $excludeMessageId = '') {
    if (!$mailbox || !$conversationId) {
        return [];
    }

    $filter = "conversationId eq '" . str_replace("'", "''", $conversationId) . "'";
    $response = graphApiGet(
        "/users/" . rawurlencode($mailbox) . "/messages?\$filter=" . rawurlencode($filter)
            . "&\$select=id,from,receivedDateTime,hasAttachments&\$top=50",
        $accessToken
    );
    if (!is_array($response) || isset($response['error'])) {
        return ['error' => $response['error'] ?? ['message' => 'Could not load the email thread.']];
    }

    $messages = [];
    foreach (($response['value'] ?? []) as $message) {
        if (empty($message['hasAttachments']) || empty($message['id']) || $message['id'] === $excludeMessageId) {
            continue;
        }
        $attachmentResponse = graphGetMessageAttachments($accessToken, $mailbox, $message['id']);
        if (!is_array($attachmentResponse) || isset($attachmentResponse['error'])) {
            continue;
        }
        $message['attachments'] = $attachmentResponse['value'] ?? [];
        $messages[] = $message;
    }

    usort($messages, static function ($a, $b) {
        return strcmp($a['receivedDateTime'] ?? '', $b['receivedDateTime'] ?? '');
    });
    return $messages;
}

/**
 * Messages of one conversation as seen in $mailbox (sender, sent time, draft
 * flag, subject). Empty array when the mailbox or thread is unavailable.
 */
function graphGetConversationMessages($accessToken, $mailbox, $conversationId) {
    if (!$mailbox || !$conversationId) {
        return [];
    }

    $filter = "conversationId eq '" . str_replace("'", "''", $conversationId) . "'";
    $response = graphApiGet(
        "/users/" . rawurlencode($mailbox) . "/messages?\$filter=" . rawurlencode($filter)
            . "&\$select=from,sentDateTime,isDraft,subject&\$top=50",
        $accessToken
    );
    return is_array($response) && !isset($response['error']) ? ($response['value'] ?? []) : [];
}

/**
 * Outlook Web link to the same mail inside $targetMailbox (matched by
 * internetMessageId), so a reminder/escalation recipient can open it and
 * Reply All from their own mailbox. Null when the mail is not in that mailbox.
 */
function graphGetMessageLinkInMailbox($accessToken, $sourceMailbox, $messageId, $targetMailbox) {
    static $internetMessageIds = [];
    if (!$sourceMailbox || !$messageId || !$targetMailbox) {
        return null;
    }

    $cacheKey = strtolower($sourceMailbox) . '|' . $messageId;
    if (!array_key_exists($cacheKey, $internetMessageIds)) {
        $source = graphApiGet(
            "/users/" . rawurlencode($sourceMailbox) . "/messages/" . rawurlencode($messageId)
                . "?\$select=internetMessageId",
            $accessToken
        );
        $internetMessageIds[$cacheKey] = is_array($source) ? ($source['internetMessageId'] ?? null) : null;
    }
    $internetMessageId = $internetMessageIds[$cacheKey];
    if (!$internetMessageId) {
        return null;
    }

    $filter = "internetMessageId eq '" . str_replace("'", "''", $internetMessageId) . "'";
    $response = graphApiGet(
        "/users/" . rawurlencode($targetMailbox) . "/messages?\$filter=" . rawurlencode($filter)
            . "&\$select=webLink&\$top=1",
        $accessToken
    );
    return is_array($response) ? ($response['value'][0]['webLink'] ?? null) : null;
}

/**
 * Send an email via Graph API (used for escalation notifications).
 */
function graphSendMail($accessToken, $toEmail, $subject, $bodyHtml, $mailbox, $attachments = []) {
    if (!$mailbox) return false;
    $endpoint = "/users/{$mailbox}/sendMail";

    $payload = [
        'message' => [
            'subject' => $subject,
            'body' => [
                'contentType' => 'HTML',
                'content' => $bodyHtml,
            ],
            'toRecipients' => [
                ['emailAddress' => ['address' => $toEmail]],
            ],
            'attachments' => $attachments,
        ],
        'saveToSentItems' => false,
    ];

    $ch = curl_init(GRAPH_API_BASE . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 202) {
        error_log("Graph sendMail failed ({$httpCode}) from {$mailbox} to {$toEmail}: {$response}");
    }

    return $httpCode === 202; // 202 Accepted = sent successfully
}

/**
 * Create a webhook subscription so Graph API notifies your app when new mail arrives.
 * $notificationUrl must be a public HTTPS URL (ngrok while testing on localhost).
 */
function graphCreateSubscription($accessToken, $notificationUrl, $mailbox = 'me') {
    $resource = ($mailbox === 'me') ? '/me/mailFolders/inbox/messages' : "/users/{$mailbox}/mailFolders/inbox/messages";

    $payload = [
        'changeType'         => 'created',
        'notificationUrl'    => $notificationUrl,
        'resource'           => $resource,
        'expirationDateTime' => (new DateTime('+2 days'))->format(DateTime::ATOM),
        'clientState'        => 'escalationTrackerSecret',
    ];

    $ch = curl_init(GRAPH_API_BASE . '/subscriptions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
