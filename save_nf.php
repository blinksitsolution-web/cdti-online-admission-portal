<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admissions');
}

if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Invalid request. Please try again.');
    redirect('admissions');
}

$rawIndex = trim($_POST['index_number'] ?? '');
$rawCode  = trim($_POST['usercode'] ?? '');

if (!validateIndexNumber($rawIndex) || empty($rawCode)) {
    setFlash('error', 'Please provide a valid index number and enrolment code.');
    setFlash('show_search', '1');
    redirect('admissions');
}

$pdo = getDB();
$stmt = $pdo->prepare("SELECT * FROM students WHERE index_number = ? LIMIT 1");
$stmt->execute([$rawIndex]);
$student = $stmt->fetch();

if (!$student) {
    logAction('student', null, 'unlisted_search_failed', "Index: $rawIndex not found");
    setFlash('error', 'Index Number not found. Please check and try again.');
    setFlash('show_search', '1');
    redirect('admissions');
}

// Verify enrolment code — support both bcrypt-hashed (new) and plaintext (legacy) codes
$storedCode = $student['enrolment_code'] ?? '';
$codeValid = false;
if (str_starts_with($storedCode, '$2y$')) {
    // F02: bcrypt-hashed code (post-migration)
    $codeValid = password_verify($rawCode, $storedCode);
} else {
    // Legacy plaintext — timing-safe compare
    $codeValid = hash_equals($storedCode, $rawCode);
    // Opportunistically upgrade to bcrypt on successful login
    if ($codeValid) {
        $pdo->prepare("UPDATE students SET enrolment_code=? WHERE id=?")
            ->execute([password_hash($rawCode, PASSWORD_BCRYPT), $student['id']]);
    }
}
if (!$codeValid) {
    logAction('student', $student['id'], 'unlisted_code_mismatch', "Index: $rawIndex, wrong code entered");
    setFlash('error', 'Enrolment code does not match. Please verify your placement form.');
    setFlash('show_search', '1');
    redirect('admissions');
}

// Mark as unlisted-resolved and start session
$pdo->prepare("UPDATE students SET is_unlisted=1 WHERE id=?")->execute([$student['id']]);

$_SESSION['student_id']    = $student['id'];
$_SESSION['index_number']  = $student['index_number'];
$_SESSION['full_name']     = $student['full_name'];
$_SESSION['program']       = $student['program'];
$_SESSION['residency']     = $student['residency'];
$_SESSION['last_activity'] = time();
session_regenerate_id(true);

logAction('student', $student['id'], 'unlisted_login_success', "Matched via name search");

$s = getSettings();

if ($student['registration_status'] === 'completed') {
    redirect(BASE_URL . '/dashboard');
}

$paymentEnabled = ($s['payment_enabled'] ?? '1') === '1';
if ($paymentEnabled && !in_array($student['payment_status'], ['paid', 'waived'])) {
    redirect(BASE_URL . '/payment');
}

redirect(BASE_URL . '/register');
