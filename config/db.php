<?php
/**
 * Database connection (PDO)
 * Localhost defaults shown below - update for live server later.
 */

// Microsoft Graph returns timestamps in UTC. Keep PHP's conversion and the
// database/display time aligned with the application's India-based users.
date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'escalation_tracker');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_CHARSET', 'utf8mb4');

function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        if (strtolower((string)getenv('APP_ENV')) === 'production'
            && (DB_USER === 'root' || DB_PASS === '')) {
            error_log('Escalation Tracker production database credentials are not configured securely.');
            http_response_code(500);
            exit('Database service is temporarily unavailable.');
        }
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // Cron runs retry for a short while so a MySQL restart does not
        // cost a whole reminder/escalation run; web requests fail fast.
        $attempts = PHP_SAPI === 'cli' ? 4 : 1;
        for ($attempt = 1; $pdo === null; $attempt++) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if ($attempt >= $attempts) {
                    error_log('Escalation Tracker database connection failed.');
                    http_response_code(500);
                    exit('Database service is temporarily unavailable.');
                }
                sleep(15);
            }
        }
    }

    return $pdo;
}

/**
 * Execute a fixed SQL statement through PDO's prepared-statement API.
 * Use prepare/execute with bound values for statements containing parameters.
 */
function executePreparedQuery(PDO $pdo, string $sql): PDOStatement {
    $statement = $pdo->prepare($sql);
    $statement->execute();
    return $statement;
}
