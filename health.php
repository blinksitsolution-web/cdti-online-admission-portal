<?php
/**
 * health.php — Uptime / deployment health check endpoint.
 * Returns 200 JSON when DB is reachable, 503 otherwise.
 * Safe to expose publicly — leaks no sensitive data.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/includes/db.php';

$checks = ['db' => false];
$status = 503;

try {
    $pdo = getDB();
    $pdo->query('SELECT 1');
    $checks['db'] = true;
    $status = 200;
} catch (Exception $e) {
    // DB unreachable — status stays 503
}

http_response_code($status);
echo json_encode([
    'status' => $status === 200 ? 'ok' : 'degraded',
    'checks' => $checks,
    'ts'     => gmdate('c'),
]);
