<?php
// Load .env file if present (before any environment checks)
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

// ── Database config ─────────────────────────────────────────────────
// For production (Hostinger), create includes/db_prod.php with your credentials
// This file is gitignored and never committed to source control

$isLocal = (DIRECTORY_SEPARATOR === '\\')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1');

// Define DB constants based on environment
if ($isLocal) {
    // Local (Laragon) defaults
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3307);
    define('DB_NAME', 'kimtech_admission');
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    // Production: db_prod.php is required (copy from db_prod.php.template)
    $prodConfig = __DIR__ . '/db_prod.php';
    if (!file_exists($prodConfig)) {
        error_log('Production DB config missing: create includes/db_prod.php from db_prod.php.template');
        if (php_sapi_name() !== 'cli') {
            header('HTTP/1.1 503 Service Unavailable');
            header('Content-Type: text/plain; charset=UTF-8');
        }
        die("Database configuration error: create includes/db_prod.php on the production server (see db_prod.php.template).");
    }
    require_once $prodConfig;
    if (!defined('DB_HOST') || !defined('DB_PORT') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
        error_log('Production DB config incomplete: db_prod.php must define DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS');
        if (php_sapi_name() !== 'cli') {
            header('HTTP/1.1 503 Service Unavailable');
            header('Content-Type: text/plain; charset=UTF-8');
        }
        die('Database configuration error: includes/db_prod.php is missing required constants.');
    }
}
define('DB_CHARSET', 'utf8mb4');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('DB Connection Error: ' . $e->getMessage());
            
            // Set HTTP status code to 503 (Service Unavailable)
            if (php_sapi_name() !== 'cli') {
                header('HTTP/1.1 503 Service Unavailable');
                header('Retry-After: 30'); // Recommend retrying in 30 seconds
            }
            
            // Check if request expects JSON
            $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
            $xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            
            if (str_contains($accept, 'application/json') || $xhr) {
                if (php_sapi_name() !== 'cli') {
                    header('Content-Type: application/json; charset=UTF-8');
                }
                die(json_encode([
                    'success' => false,
                    'error' => 'The server is currently experiencing high traffic. Please try again in a few moments.'
                ]));
            }
            
            // Otherwise, render a beautiful HTML error page
            ?>
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Service Unavailable | KIMTECH</title>
                <style>
                    body{background:#050e1a;color:#fff;font-family:sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center;margin:0;}
                    .box{text-align:center;max-width:460px;padding:2.5rem;background:rgba(10,31,53,0.8);border:1px solid rgba(0,111,160,0.3);border-radius:16px;}
                    h1{font-size:1.6rem;font-weight:700;margin-bottom:1rem;}
                    p{color:#a0aec0;font-size:0.95rem;line-height:1.6;margin-bottom:2rem;}
                    a{display:inline-block;background:linear-gradient(135deg,#006fa0,#0097d6);color:#fff;padding:0.7rem 2rem;border-radius:30px;text-decoration:none;font-weight:600;font-size:0.85rem;}
                </style>
            </head>
            <body>
                <div class="box">
                    <div style="font-size:3rem;margin-bottom:1rem;">&#x26A0;&#xFE0F;</div>
                    <h1>Server Temporarily Busy</h1>
                    <p>The admission portal is experiencing high traffic. Please wait a moment and try again.</p>
                    <a href="" onclick="window.location.reload();return false;">Try Again</a>
                </div>
            </body>
            </html>
            <?php
            die();
        }
    }
    return $pdo;
}
