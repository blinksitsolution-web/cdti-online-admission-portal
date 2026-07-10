<?php
/**
 * search.php — AJAX endpoint for autocomplete name search
 *
 * S06: Rate-limited to 30 requests per IP per minute.
 * S06: Returns only first name + last initial to reduce PII exposure.
 * Requires a valid CSRF token passed as a GET parameter.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json');

// S06: Require CSRF token so random crawlers cannot scrape the endpoint
if (!verifyCsrfToken($_GET['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

// S06: IP-based rate limiting — 30 requests per minute
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$pdo = getDB();
$stmtRate = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action = 'student_search' AND ip_address = ?
       AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
);
$stmtRate->execute([$ip]);
if ((int)$stmtRate->fetchColumn() >= 30) {
    http_response_code(429);
    echo json_encode([]);
    exit;
}
logAction('student', null, 'student_search', "IP: $ip");

$query = trim($_GET['query'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT full_name, gender, program, residency
     FROM students
     WHERE full_name LIKE ? AND registration_status != 'completed'
     ORDER BY full_name ASC LIMIT 10"
);
$stmt->execute(['%' . $query . '%']);
$rows = $stmt->fetchAll();

// S06: Mask full name — show first name + last initial only (e.g. "Kwame B.")
$results = [];
foreach ($rows as $row) {
    $parts     = explode(' ', trim($row['full_name']));
    $firstName = $parts[0];
    $lastInit  = count($parts) > 1 ? strtoupper(substr(end($parts), 0, 1)) . '.' : '';
    $masked    = trim($firstName . ' ' . $lastInit);

    $results[] = [
        'full_name' => $masked,
        'gender'    => $row['gender'],
        'program'   => $row['program'],
        'residency' => $row['residency'],
        'info'      => $row['gender'] . ' | ' . $row['program'] . ' | ' . $row['residency'],
    ];
}

echo json_encode($results);
