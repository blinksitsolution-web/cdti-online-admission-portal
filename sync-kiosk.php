<?php
/**
 * sync-kiosk.php — Sessionless sync endpoint for offline kiosk submissions.
 *
 * Unlike register.php, this endpoint does NOT require a PHP session.
 * Authentication is by index_number lookup (same security model as autenticate.php).
 *
 * Accepts multipart/form-data POST with:
 *   index_number   — student's CSSPS index number (required, identifies student)
 *   client_uuid    — idempotency key (UUID v4)
 *   + all registration fields (same as register.php)
 *   + passport_photo file (optional — student may not have camera on kiosk device)
 *
 * Returns JSON:  { status, reason?, message?, admission_number? }
 *
 * Payment handling (per admin decision):
 *   Kiosk submissions are ALWAYS accepted regardless of payment_status.
 *   If unpaid, payment_status stays as-is; admin reviews later.
 *   The student's registration_status becomes 'completed' regardless.
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Only accept POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'reason' => 'method_not_allowed']);
    exit;
}

function kioskJson(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function kioskSanitize(mixed $v): string {
    return trim(strip_tags((string)($v ?? '')));
}

// ── Extract + validate index_number ────────────────────────────────────────
$rawIndex = kioskSanitize($_POST['index_number'] ?? '');
if (!validateIndexNumber($rawIndex)) {
    kioskJson(422, ['status' => 'error', 'reason' => 'validation',
        'errors' => [['field' => 'index_number', 'msg' => 'Invalid index number.']]]);
}

// ── Rate limit: 10 kiosk POST attempts per IP per 30 min ──────────────────
$pdo = getDB();
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs WHERE ip_address=? AND action='kiosk_sync_attempt'
     AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
);
$rlStmt->execute([$ip]);
if ((int)$rlStmt->fetchColumn() >= 10) {
    kioskJson(429, ['status' => 'error', 'reason' => 'rate_limited',
        'message' => 'Too many attempts. Please wait 30 minutes.']);
}

// ── Look up student ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM students WHERE index_number=? LIMIT 1");
$stmt->execute([$rawIndex]);
$student = $stmt->fetch();

if (!$student) {
    logAction('student', null, 'kiosk_sync_attempt', "Index not found: $rawIndex | IP: $ip");
    kioskJson(404, ['status' => 'error', 'reason' => 'not_found',
        'message' => 'Index number not found in the system.']);
}

$sid = (int)$student['id'];
logAction('student', $sid, 'kiosk_sync_attempt', "Kiosk sync | IP: $ip");

// ── Idempotency: already completed with this exact UUID? ──────────────────
$clientUuid = '';
$rawUuid = $_POST['client_uuid'] ?? '';
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $rawUuid)) {
    $clientUuid = strtolower($rawUuid);
}

if ($student['registration_status'] === 'completed') {
    if ($clientUuid !== '' && $student['submission_uuid'] === $clientUuid) {
        // Retry of a submission that already succeeded — return success with the number
        kioskJson(200, [
            'status'           => 'success',
            'already_completed'=> true,
            'admission_number' => $student['admission_number'] ?? null,
        ]);
    }
    kioskJson(409, ['status' => 'conflict', 'reason' => 'already_registered',
        'message' => 'This student\'s registration was already completed.']);
}

// ── Extract form fields ────────────────────────────────────────────────────
$s           = getSettings();
$dob         = kioskSanitize($_POST['dob'] ?? '');
$religion    = kioskSanitize($_POST['religion'] ?? '');
$hometown    = kioskSanitize($_POST['hometown'] ?? '');
$region      = kioskSanitize($_POST['region'] ?? '');
$nationality = kioskSanitize($_POST['nationality'] ?? 'Ghanaian') ?: 'Ghanaian';
$prev_jhs    = kioskSanitize($_POST['prev_jhs'] ?? '');
$prev_idx    = kioskSanitize($_POST['prev_jhs_index'] ?? '') ?: $student['index_number'];
$enrol_code  = kioskSanitize($_POST['enrolment_code'] ?? '');
$aggregate   = kioskSanitize($_POST['aggregate'] ?? '');

$father_name  = kioskSanitize($_POST['father_name'] ?? '');
$father_phone = kioskSanitize($_POST['father_phone'] ?? '');
$father_addr  = kioskSanitize($_POST['father_address'] ?? '');
$father_occ   = kioskSanitize($_POST['father_occupation'] ?? '');
$mother_name  = kioskSanitize($_POST['mother_name'] ?? '');
$mother_phone = kioskSanitize($_POST['mother_phone'] ?? '');
$mother_addr  = kioskSanitize($_POST['mother_address'] ?? '');
$mother_occ   = kioskSanitize($_POST['mother_occupation'] ?? '');
$grd_name     = kioskSanitize($_POST['guardian_name'] ?? '');
$grd_phone    = kioskSanitize($_POST['guardian_phone'] ?? '');
$grd_addr     = kioskSanitize($_POST['guardian_address'] ?? '');
$grd_rel      = kioskSanitize($_POST['guardian_relationship'] ?? '');

// ── Validate required fields ───────────────────────────────────────────────
$errors = [];
if (empty($dob))        $errors[] = ['field' => 'dob',       'step' => 1, 'msg' => 'Date of Birth is required.'];
if (empty($religion))   $errors[] = ['field' => 'religion',  'step' => 1, 'msg' => 'Religion is required.'];
if (empty($hometown))   $errors[] = ['field' => 'hometown',  'step' => 1, 'msg' => 'Hometown is required.'];
if (empty($region))     $errors[] = ['field' => 'region',    'step' => 1, 'msg' => 'Region is required.'];
if (empty($prev_jhs))   $errors[] = ['field' => 'prev_jhs',  'step' => 1, 'msg' => 'Previous JHS name is required.'];
if (empty($enrol_code)) {
    $errors[] = ['field' => 'enrolment_code', 'step' => 2, 'msg' => 'Enrolment code is required.'];
} elseif (!validateEnrolmentCode($enrol_code)) {
    $errors[] = ['field' => 'enrolment_code', 'step' => 2, 'msg' => 'Enrolment code must be 4–10 letters and/or numbers.'];
}
if (empty($aggregate)) {
    $errors[] = ['field' => 'aggregate', 'step' => 2, 'msg' => 'BECE aggregate is required.'];
} elseif (!preg_match('/^\d{1,2}$/', $aggregate) || (int)$aggregate < 6 || (int)$aggregate > 54) {
    $errors[] = ['field' => 'aggregate', 'step' => 2, 'msg' => 'Aggregate must be 6–54.'];
}

if (!empty($errors)) {
    kioskJson(422, ['status' => 'error', 'reason' => 'validation', 'errors' => $errors]);
}

// ── Photo processing ───────────────────────────────────────────────────────
$photoPath = $student['passport_photo_path'] ?? '';
if (!empty($_FILES['passport_photo']['name']) && $_FILES['passport_photo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['passport_photo'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $magicType = match($mime) {
        'image/jpeg', 'image/jpg' => 'jpeg',
        'image/png'               => 'png',
        default                   => '',
    };

    if ($magicType && $file['size'] <= 5 * 1024 * 1024 && validateUploadMagicBytes($file['tmp_name'], $magicType)) {
        $srcImage = $magicType === 'jpeg'
            ? @imagecreatefromjpeg($file['tmp_name'])
            : @imagecreatefrompng($file['tmp_name']);

        if ($srcImage) {
            $origW = imagesx($srcImage);
            $origH = imagesy($srcImage);
            $maxDim = 800;
            if ($origW > $maxDim || $origH > $maxDim) {
                $ratio  = min($maxDim / $origW, $maxDim / $origH);
                $newW   = (int)round($origW * $ratio);
                $newH   = (int)round($origH * $ratio);
                $canvas = imagecreatetruecolor($newW, $newH);
                imagecopyresampled($canvas, $srcImage, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
                imagedestroy($srcImage);
                $srcImage = $canvas;
            }
            $destDir = __DIR__ . '/uploads/passports/';
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $filename = 'K_' . $student['index_number'] . '_' . time() . '.jpg';
            if (imagejpeg($srcImage, $destDir . $filename, 85)) {
                $photoPath = 'uploads/passports/' . $filename;
            }
            imagedestroy($srcImage);
        }
    }
}

// ── Auto house assignment ──────────────────────────────────────────────────
$isBoarder       = isBoarderResidency($student['residency'] ?? '');
$assignedHouseId = null;
if ($isBoarder) {
    $assignedHouseId = autoAssignHouse($pdo);
}

// ── Auto admission number ──────────────────────────────────────────────────
$academicYear = $s['academic_year'] ?? date('Y');
if (preg_match('/(\d{4})/', $academicYear, $ym)) {
    $academicYear = $ym[1];
}
$admissionNumber = generateAdmissionNumber($pdo, $student['program'] ?? '', $academicYear);

// ── Save to database ───────────────────────────────────────────────────────
// Payment: kiosk mode always allows registration; payment_status is unchanged.
// Admin reviews/resolves payment separately.
$pdo->prepare("UPDATE students SET
    date_of_birth=?, religion=?, hometown=?, region=?, nationality=?,
    prev_jhs_school=?, prev_jhs_index=?, enrolment_code=?, aggregate=?,
    passport_photo_path=?, house_id=?, admission_number=?,
    submission_uuid=?, registration_status='completed', registered_at=NOW()
    WHERE id=?
")->execute([
    $dob, $religion, $hometown, $region, $nationality,
    $prev_jhs, $prev_idx, $enrol_code, $aggregate,
    $photoPath, $assignedHouseId, $admissionNumber,
    $clientUuid !== '' ? $clientUuid : null,
    $sid,
]);

// Parent/guardian info
$pdo->prepare("DELETE FROM parent_guardian_info WHERE student_id=?")->execute([$sid]);
$pdo->prepare("INSERT INTO parent_guardian_info
    (student_id, father_name, father_phone, father_address, father_occupation,
     mother_name, mother_phone, mother_address, mother_occupation,
     guardian_name, guardian_phone, guardian_address, guardian_relationship)
    VALUES (?,?,?,?,?, ?,?,?,?, ?,?,?,?)
")->execute([
    $sid,
    $father_name, $father_phone, $father_addr, $father_occ,
    $mother_name, $mother_phone, $mother_addr, $mother_occ,
    $grd_name, $grd_phone, $grd_addr, $grd_rel,
]);

logAction('student', $sid, 'kiosk_registration_completed',
    "Offline kiosk sync | AdmNo: $admissionNumber | IP: $ip");

// ── SMS confirmation ───────────────────────────────────────────────────────
$smsTemplate = $s['sms_congratulations_template']
    ?? "Dear Parent/Guardian, {name}'s admission registration for {school} ({program}) has been completed. Helpline: {helpline}";
$smsMsg = strtr($smsTemplate, [
    '{name}'     => $student['full_name'],
    '{school}'   => $s['school_name'] ?? 'CDTI',
    '{program}'  => $student['program'],
    '{date}'     => $s['reporting_date'] ?? '',
    '{whatsapp_link}' => $s['school_whatsapp_link'] ?? '',
    '{helpline}' => $s['helpline_number'] ?? '',
]);
$parentPhones = getStudentParentPhones($pdo, $sid);
foreach ($parentPhones as $phone) {
    sendSms($phone, $smsMsg, $s);
}

kioskJson(200, [
    'status'           => 'success',
    'admission_number' => $admissionNumber,
    'house_assigned'   => $isBoarder,
]);
