<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(BASE_URL . '/admissions'); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('error','Invalid request. Please try again.'); redirect(BASE_URL . '/admissions');
}

$rawIndex = trim($_POST['index_no'] ?? '');
if (!validateIndexNumber($rawIndex)) {
    setFlash('error','Please enter a valid CSSPS index number.'); redirect(BASE_URL . '/admissions');
}

$pdo = getDB();
$s   = getSettings();

// Rate limiting: 10 failed attempts per IP per 30 minutes
$ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE ip_address=? AND action='student_login_failed' AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)");
$stmt->execute([$ip]);
if ($stmt->fetchColumn() >= 10) {
    setFlash('error','Too many failed attempts. Please wait 30 minutes.'); redirect(BASE_URL . '/admissions');
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE index_number=? LIMIT 1");
$stmt->execute([$rawIndex]);
$student = $stmt->fetch();

if (!$student) {
    logAction('student', null, 'student_login_failed', "Index: $rawIndex not found");
    setFlash('error','Index Number Cannot Be Found. Use the search below to find your record.');
    setFlash('show_search','1');
    redirect(BASE_URL . '/admissions');
}

// Create session
$_SESSION['student_id']    = $student['id'];
$_SESSION['index_number']  = $student['index_number'];
$_SESSION['full_name']     = $student['full_name'];
$_SESSION['program']       = $student['program'];
$_SESSION['residency']     = $student['residency'];
$_SESSION['last_activity'] = time();
session_regenerate_id(true);

logAction('student', $student['id'], 'student_login_success', "Index: $rawIndex");

// Already completed registration
if ($student['registration_status'] === 'completed') { redirect(BASE_URL . '/dashboard'); }

// Route: Check payment
$paymentEnabled = ($s['payment_enabled'] ?? '1') === '1';
if ($paymentEnabled && !in_array($student['payment_status'], ['paid','waived'])) {
    redirect(BASE_URL . '/payment');
}

// Payment done or disabled → go to register
redirect(BASE_URL . '/register');
