<?php
/**
 * Fresh CSRF token endpoint for the offline sync engine (Req. 5, 8).
 *
 * The sync engine calls this immediately before every submit attempt so it
 * never reuses the token baked into the page HTML at load time — a device
 * can sit offline for hours before syncing, long enough for that token to
 * be stale even though the session itself is still valid.
 *
 * Session-authenticated like every other student page, but returns JSON
 * 401 instead of requireStudentAuth()'s redirect — a fetch() call has
 * nowhere useful to follow a redirect to.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

startSecureSession();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'reason' => 'method_not_allowed']);
    exit;
}

if (empty($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'reason' => 'unauthenticated']);
    exit;
}

if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['status' => 'error', 'reason' => 'session_expired']);
    exit;
}
$_SESSION['last_activity'] = time();

echo json_encode(['status' => 'success', 'token' => generateCsrfToken()]);
