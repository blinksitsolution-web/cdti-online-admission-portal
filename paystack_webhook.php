<?php
/**
 * paystack_webhook.php — Paystack server-to-server webhook
 *
 * Set this URL in Paystack Dashboard → Settings → API Keys & Webhooks:
 *   https://kimtech.myonlineadmission.com/paystack_webhook
 *
 * This file MUST NOT require session/auth — Paystack calls it directly.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// ── Load secret key (env-first, DB fallback) ───────────────────────
$settings  = getSettings();
$secretKey = getCredential('PAYSTACK_SECRET_KEY', 'paystack_secret_key')['value'];

if (empty($secretKey)) {
    http_response_code(500);
    logAction('system', null, 'webhook_error', 'Paystack secret key not configured');
    exit('Configuration error');
}

// ── Verify HMAC signature ─────────────────────────────────────────
$expectedSig = hash_hmac('sha512', $rawBody, $secretKey);
if (!hash_equals($expectedSig, $signature)) {
    http_response_code(401);
    logAction('system', null, 'webhook_invalid_signature', 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit('Invalid signature');
}

// ── Parse event ───────────────────────────────────────────────────
$event = json_decode($rawBody, true);
if (!$event || ($event['event'] ?? '') !== 'charge.success') {
    // Acknowledge other events without processing
    http_response_code(200);
    exit('OK');
}

$data      = $event['data'] ?? [];
$ref       = $data['reference'] ?? '';
$status    = $data['status']    ?? '';
$amountKobo = (int)($data['amount'] ?? 0);
$paidAmount = $amountKobo / 100;

if ($status !== 'success' || empty($ref)) {
    http_response_code(200);
    exit('OK');
}

// ── Find student by payment reference ────────────────────────────
// Reference format: CDTI-XXXXXXXXXXXX (generated in payment.php)
$pdo = getDB();

// First check: already processed (idempotency)
$check = $pdo->prepare("SELECT id, payment_status FROM students WHERE payment_reference = ? LIMIT 1");
$check->execute([$ref]);
$existing = $check->fetch();
if ($existing && $existing['payment_status'] === 'paid') {
    http_response_code(200);
    exit('OK'); // Already handled
}

// Match student by reference prefix: CDTI-{md5(id_date)}
// We stored ref as CDTI-{substr(md5($sid.'_'.date('Ymd')),0,12)}
// So we look up by the reference itself stored in session — but webhook
// has no session. Instead, match via metadata.student_id from Paystack.
$studentId = (int)($data['metadata']['student_id'] ?? 0);

if ($studentId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
    $stmt->execute([$studentId]);
    $student = $stmt->fetch();
} else {
    // Fallback: try to find by partial reference match
    $student = null;
}

if (!$student) {
    logAction('system', null, 'webhook_student_not_found', "Ref: $ref | student_id: $studentId");
    http_response_code(200); // Still 200 so Paystack doesn't retry forever
    exit('OK');
}

// Skip if already paid
if (in_array($student['payment_status'], ['paid', 'waived'])) {
    http_response_code(200);
    exit('OK');
}

// Ensure webhook is applied to the student row identified by the reference
$refMatch = $pdo->prepare("SELECT id, payment_status FROM students WHERE payment_reference = ? LIMIT 1");
$refMatch->execute([$ref]);
$refRow = $refMatch->fetch();
if (!$refRow || !in_array($refRow['payment_status'], ['pending'])) {
    // Don't mark paid if reference doesn't map cleanly
    logAction('system', $student['id'] ?? null, 'webhook_reference_mismatch', "Ref: $ref did not match pending student record");
    http_response_code(200);
    exit('OK');
}

$studentIdToUpdate = (int)$refRow['id'];
if ($studentIdToUpdate !== (int)$student['id']) {
    // Prefer reference match (prevents metadata tampering)
    $student = $pdo->prepare("SELECT * FROM students WHERE id = ? LIMIT 1");
    // (re-fetch via update statement below)
}

$expectedKobo = (int)((float)($settings['admission_fee'] ?? 50) * 100);

if ($amountKobo !== $expectedKobo) {
    logAction('system', $student['id'], 'webhook_amount_mismatch', "Ref: $ref | expected {$expectedKobo} kobo got {$amountKobo} kobo");
    http_response_code(200);
    exit('OK');
}

// ── Mark payment as paid ──────────────────────────────────────────
$pdo->prepare("
    UPDATE students
    SET payment_status = 'paid',
        payment_reference = ?,
        payment_amount = ?,
        payment_date = NOW()
    WHERE id = ? AND payment_status NOT IN ('paid','waived')
")->execute([$ref, $paidAmount, $studentIdToUpdate]);

logAction('system', $studentIdToUpdate, 'webhook_payment_success', "Ref: $ref | GHS $paidAmount | via Paystack webhook");


// ── Send SMS notification ─────────────────────────────────────────
try {
    $guardianPhone = '';
    $pgStmt = $pdo->prepare("SELECT guardian_phone, father_phone, mother_phone FROM parent_guardian_info WHERE student_id = ? LIMIT 1");
    $pgStmt->execute([$student['id']]);
    $pg = $pgStmt->fetch();
    if ($pg) {
        $guardianPhone = $pg['guardian_phone'] ?: $pg['father_phone'] ?: $pg['mother_phone'] ?: '';
    }
    if (empty($guardianPhone)) {
        $guardianPhone = $student['phone'] ?? $student['phone_number'] ?? '';
    }

    if ($guardianPhone) {
        $msg = "Dear Parent/Guardian, admission payment of GHS {$paidAmount} for "
             . $student['full_name'] . " (Ref: $ref) received. "
             . "Please complete the online registration form. Helpline: "
             . ($settings['helpline_number'] ?? '');
        sendSms($guardianPhone, $msg, $settings);
    }
} catch (Exception $e) {
    error_log('Webhook SMS error: ' . $e->getMessage());
}

http_response_code(200);
exit('OK');
