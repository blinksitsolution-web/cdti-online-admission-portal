<?php
/**
 * Admin API: Export all admitted students for offline kiosk mode.
 *
 * Returns a compact JSON payload containing:
 *   - All students (safe fields only — no sensitive personal data)
 *   - School settings needed for offline display
 *
 * Supports ETag-based conditional GET (If-None-Match) to avoid re-downloading
 * unchanged data — important on slow connections.
 *
 * Only accessible to authenticated admins. Never cache on shared proxies.
 */
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db.php';
requireAdminAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getDB();

// ── Fetch students ─────────────────────────────────────────────────────
// Export only fields needed for kiosk lookup + form pre-fill.
// enrolment_code is included so the kiosk can do client-side lookup only;
// server-side sync re-validates by index_number.
$rows = $pdo->query("
    SELECT
        index_number,
        full_name,
        program,
        gender,
        residency,
        aggregate,
        payment_status,
        enrolment_code
    FROM students
    ORDER BY index_number
")->fetchAll(PDO::FETCH_ASSOC);

$students = [];
foreach ($rows as $r) {
    $students[] = [
        'idx'   => $r['index_number'],
        'name'  => $r['full_name'],
        'prog'  => $r['program'],
        'gen'   => $r['gender'],
        'res'   => $r['residency'],
        'agg'   => $r['aggregate'] ?? '',
        'paid'  => in_array($r['payment_status'], ['paid', 'waived']),
        'code'  => $r['enrolment_code'] ?? '',
    ];
}

// ── School settings for offline display ───────────────────────────────
$s = getSettings();
$logoPath = !empty($s['school_logo_path']) ? asset($s['school_logo_path']) : asset('assets/img/logo.png');

$payload = [
    'students'    => $students,
    'count'       => count($students),
    'exportedAt'  => time(),
    'school' => [
        'name'        => $s['school_name']      ?? 'CDTI',
        'address'     => $s['school_address']   ?? '',
        'phone'       => $s['school_phone']      ?? '',
        'email'       => $s['school_email']      ?? '',
        'helpline'    => $s['helpline_number']   ?? '',
        'logoUrl'     => $logoPath,
        'year'        => $s['academic_year']     ?? date('Y'),
        'reportDate'  => $s['reporting_date']    ?? '',
        'payEnabled'  => ($s['payment_enabled']  ?? '1') === '1',
    ],
];

$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$etag = '"' . md5($json) . '"';

// ETag conditional GET — return 304 if client has the same data
$clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
if ($clientEtag && $clientEtag === $etag) {
    http_response_code(304);
    header('ETag: ' . $etag);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('ETag: ' . $etag);
header('Cache-Control: private, no-store'); // never shared proxy cache

// Gzip compression — important for large student datasets on slow connections
$acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
if (str_contains($acceptEncoding, 'gzip')) {
    header('Content-Encoding: gzip');
    echo gzencode($json, 6);
} else {
    echo $json;
}
