<?php
/**
 * payment_verify.php — Paystack callback & dev-bypass handler
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/secrets.php';
requireStudentAuth();

$sid = (int) $_SESSION['student_id'];
$ref = sanitize($_GET['ref'] ?? '');
$bypass = isset($_GET['bypass']) && $_GET['bypass'] === '1';

if (empty($ref)) {
    setFlash('pay_error', 'Missing payment reference. Please try again.');
    redirect(BASE_URL . '/payment');
}

// Keep reference for recovery if verification does not complete
$_SESSION['pay_pending_ref_' . $sid] = $ref;

$pdo       = getDB();
$settings  = getSettings();
// S01: Decrypt the stored secret key before use
$secretKey = decryptSecret($settings['paystack_secret_key'] ?? '');

// ── Load student ─────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM students WHERE id=? LIMIT 1");
$stmt->execute([$sid]);
$student = $stmt->fetch();

if (!$student) { session_destroy(); redirect(BASE_URL . '/admissions'); }

// Already paid (possibly by webhook while user was offline) → skip straight through
if (in_array($student['payment_status'], ['paid','waived'])) {
    unset($_SESSION['pay_ref_' . $sid], $_SESSION['pay_pending_ref_' . $sid]);
    logAction('student', $sid, 'payment_already_verified', "Ref: $ref — already marked paid (webhook or prior verify)");
    if ($student['registration_status'] === 'completed') redirect(BASE_URL . '/dashboard');
    redirect(BASE_URL . '/register');
}

// ── Dev bypass (only in DEV environment, never when a real key is configured) ──
if ($bypass && empty($secretKey) && getenv('APP_ENV') === 'development') {
    $paidAmount = (float)($settings['admission_fee'] ?? 50);
    $pdo->prepare("
        UPDATE students SET payment_status='paid', payment_reference=?, payment_amount=?, payment_date=NOW()
        WHERE id=?
    ")->execute(['DEV-BYPASS-' . date('Ymd-His'), $paidAmount, $sid]);
    logAction('student', $sid, 'payment_bypass', "Dev bypass | GHS $paidAmount");
    unset($_SESSION['pay_ref_' . $sid], $_SESSION['pay_pending_ref_' . $sid]);
    redirect(BASE_URL . '/register');
}

// ── Verify with Paystack API ─────────────────────────────────────
$verified   = false;
$paidAmount = 0;

if (!empty($secretKey)) {
    // Find CA bundle — check common Laragon locations
    $caBundlePaths = [
        ini_get('curl.cainfo'),
        ini_get('openssl.cafile'),
        PHP_BINARY ? dirname(PHP_BINARY) . '/cacert.pem' : '',
        'C:/laragon/bin/php/php-8.3.26-Win32-vs16-x64/cacert.pem',
    ];
    $caBundle = '';
    foreach ($caBundlePaths as $p) {
        if ($p && file_exists($p)) { $caBundle = $p; break; }
    }

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => "https://api.paystack.co/transaction/verify/" . urlencode($ref),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $secretKey", "Cache-Control: no-cache"],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($caBundle) {
        $curlOpts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOpts);
    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    logAction('student', $sid, 'paystack_api_call', "Ref:$ref HTTP:$httpCode cURLErr:" . ($curlErr ?: 'none') . " Resp:" . substr($response ?: '', 0, 200));

    if ($curlErr) {
        // Network/SSL failure — log and show friendly error
        logAction('student', $sid, 'payment_curl_error', "cURL: $curlErr");
        setFlash('pay_error', "Could not connect to payment gateway to verify your payment (Ref: $ref). Please wait a moment and try again, or contact the school.");
        redirect(BASE_URL . '/payment');
        exit;
    }

    if ($response) {
        $data = json_decode($response, true);
        if (
            isset($data['status']) && $data['status'] === true &&
            isset($data['data']['status']) && $data['data']['status'] === 'success'
        ) {
            $metaStudentId = (int)($data['data']['metadata']['student_id'] ?? 0);
            $amountKobo      = (int)($data['data']['amount'] ?? 0);
            $expectedKobo    = (int)((float)($settings['admission_fee'] ?? 50) * 100);

            if ($metaStudentId !== $sid) {
                logAction('student', $sid, 'payment_metadata_mismatch', "Ref:$ref expected student $sid got $metaStudentId");
                setFlash('pay_error', 'Payment reference does not belong to your account. Please contact the school if you were charged.');
                redirect(BASE_URL . '/payment');
            }
            if ($amountKobo !== $expectedKobo) {
                logAction('student', $sid, 'payment_amount_mismatch', "Ref:$ref expected {$expectedKobo} kobo got {$amountKobo} kobo");
                setFlash('pay_error', 'Payment amount does not match the required admission fee. Please contact the school.');
                redirect(BASE_URL . '/payment');
            }

            $verified   = true;
            $paidAmount = $amountKobo / 100;
        } else {
            // Log what Paystack actually said
            logAction('student', $sid, 'paystack_reject', "Ref:$ref HTTP:$httpCode Msg:" . ($data['message'] ?? 'unknown'));
        }
    }
} else {
    logAction('student', $sid, 'payment_no_keys', "Paystack keys not configured");
    setFlash('pay_error', 'Payment gateway is not configured. Please contact the school administration.');
    redirect(BASE_URL . '/payment');
}

if ($verified) {
    $pdo->prepare("
        UPDATE students SET payment_status='paid', payment_reference=?, payment_amount=?, payment_date=NOW()
        WHERE id=?
    ")->execute([$ref, $paidAmount, $sid]);

    logAction('student', $sid, 'payment_success', "Ref: $ref | GHS $paidAmount");

    // SMS: student's guardian phone (may not exist yet before registration — safe)
    $guardianPhone = getAnyPhone($pdo, $sid, $student);
    if ($guardianPhone) {
        $msg = "Dear Parent/Guardian, admission payment of GHS {$paidAmount} for "
             . $student['full_name'] . " (Ref: $ref) received. "
             . "Please complete the online registration form. Helpline: "
             . ($settings['helpline_number'] ?? '');
        sendSms($guardianPhone, $msg, $settings);
    }

    unset($_SESSION['pay_ref_' . $sid], $_SESSION['pay_pending_ref_' . $sid]);

    redirect(BASE_URL . '/register');
} else {
    logAction('student', $sid, 'payment_failed', "Ref: $ref — verification failed");
    setFlash('pay_error', 'Payment verification failed. If you were charged, use "Verify my payment" below to retry with reference: ' . htmlspecialchars($ref));
    redirect(BASE_URL . '/payment');
}

// ── Helper: get any available phone number ───────────────────────
function getAnyPhone(PDO $pdo, int $sid, array $student): string {
    // Try parent_guardian_info first (may not exist before registration)
    try {
        $stmt = $pdo->prepare("SELECT guardian_phone, father_phone, mother_phone FROM parent_guardian_info WHERE student_id=? LIMIT 1");
        $stmt->execute([$sid]);
        $row = $stmt->fetch();
        if ($row) {
            return $row['guardian_phone'] ?: $row['father_phone'] ?: $row['mother_phone'] ?: '';
        }
    } catch (Exception $e) { /* table may not exist */ }

    // Fallback: student phone from CSV import (if stored)
    return $student['phone'] ?? $student['phone_number'] ?? '';
}
