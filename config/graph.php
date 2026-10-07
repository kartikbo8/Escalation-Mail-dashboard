<?php
/**
 * Microsoft Graph API credentials.
 * Fill these in after Azure App Registration (Step 2 of the roadmap).
 * Never commit real values to a public repo - keep this file private.
 */

define('GRAPH_CLIENT_ID', getenv('GRAPH_CLIENT_ID') ?: '');
define('GRAPH_CLIENT_SECRET', getenv('GRAPH_CLIENT_SECRET') ?: '');
define('GRAPH_TENANT_ID', getenv('GRAPH_TENANT_ID') ?: '');
define('GRAPH_REDIRECT_URI', getenv('GRAPH_REDIRECT_URI') ?: 'http://localhost:8080/escalation-tracker/public/callback.php');

// Scopes needed: read mail, send mail, maintain access
define('GRAPH_SCOPES', 'offline_access Mail.Read Mail.Send User.Read');

define('GRAPH_AUTH_URL', 'https://login.microsoftonline.com/' . GRAPH_TENANT_ID . '/oauth2/v2.0/authorize');
define('GRAPH_TOKEN_URL', 'https://login.microsoftonline.com/' . GRAPH_TENANT_ID . '/oauth2/v2.0/token');
define('GRAPH_API_BASE', 'https://graph.microsoft.com/v1.0');
