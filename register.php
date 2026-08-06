<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
requireStudentAuth();
emitCspHeader();

// A request from the offline sync engine (client_uuid identifies a queued
// submission; sync=1 asks for a JSON response instead of the normal
// HTML page/redirect). Neither field is ever sent by the plain HTML form,
// so a request without them behaves exactly as before this was added.
$isSyncRequest = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['sync'] ?? '') === '1';
$clientUuid = '';
if (isset($_POST['client_uuid']) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $_POST['client_uuid'])) {
    $clientUuid = strtolower($_POST['client_uuid']);
}

// Rate limit: max 5 registration POST submissions per student session per hour.
// A retry of the *same* client_uuid (the sync engine's own backoff re-attempting
// a queued item) does not consume a new slot — only the first attempt of a
// given UUID counts. See Phase 2 design §5.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    $sid  = (int) $_SESSION['student_id'];
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

    $isRetryOfSameUuid = false;
    if ($clientUuid !== '') {
        $seenStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND action='registration_attempt' AND details LIKE ?"
        );
        $seenStmt->execute([$sid, '%uuid:' . $clientUuid . '%']);
        $isRetryOfSameUuid = (int) $seenStmt->fetchColumn() > 0;
    }

    if (!$isRetryOfSameUuid) {
        $rlStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND action='registration_attempt' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        $rlStmt->execute([$sid]);
        if ((int)$rlStmt->fetchColumn() >= 5) {
            if ($isSyncRequest) {
                http_response_code(429);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'error', 'reason' => 'rate_limited', 'message' => 'Too many submission attempts. Please wait before trying again.']);
                exit;
            }
            http_response_code(429);
            die('Too many submission attempts. Please wait before trying again.');
        }
        logAction('student', $sid, 'registration_attempt', "IP: $ip" . ($clientUuid !== '' ? "; uuid:$clientUuid" : ''));
    }
}

$pdo  = getDB();
$sid  = (int) $_SESSION['student_id'];
$stmt = $pdo->prepare("SELECT * FROM students WHERE id=?");
$stmt->execute([$sid]);
$student = $stmt->fetch();

if (!$student) { session_destroy(); redirect(BASE_URL . '/admissions'); }
if ($student['registration_status'] === 'completed') {
    if ($isSyncRequest) {
        if ($clientUuid !== '' && $student['submission_uuid'] === $clientUuid) {
            // Retry of a submission that already succeeded — safe no-op.
            // Don't repeat the writes or resend the confirmation SMS.
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success', 'already_completed' => true]);
            exit;
        }
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'conflict', 'reason' => 'already_registered', 'message' => 'Your registration was already completed — no action needed.']);
        exit;
    }
    redirect(BASE_URL . '/dashboard');
}

$s = getSettings();
$logoPath   = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$schoolName = $s['school_name'] ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';

// Guard: payment required
$payEnabled = ($s['payment_enabled'] ?? '1') === '1';
if ($payEnabled && !in_array($student['payment_status'], ['paid','waived'])) {
    if ($isSyncRequest) {
        http_response_code(409);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'conflict', 'reason' => 'payment_required', 'message' => 'Your payment needs to be completed before this can be submitted.']);
        exit;
    }
    redirect(BASE_URL . '/payment');
}

$reportingDate = $s['reporting_date'] ?? '15th September, 2026';
$isBoarder = isBoarderResidency($student['residency'] ?? '');

$parentStmt = $pdo->prepare("SELECT * FROM parent_guardian_info WHERE student_id=?");
$parentStmt->execute([$sid]);
$parentInfo = $parentStmt->fetch() ?: [];

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// ── Field values: submitted value on a failed POST, existing record on a
// fresh load. This is the fix for "the form resets on a validation error" —
// the page never actually lost $_POST, the template just never looked at
// it. Every field below is now a single source of truth for the form HTML.
$dob          = $isPost ? sanitize($_POST['dob'] ?? '')                  : ($student['date_of_birth'] ?? '');
$religion     = $isPost ? sanitize($_POST['religion'] ?? '')             : ($student['religion'] ?? '');
$hometown     = $isPost ? sanitize($_POST['hometown'] ?? '')             : ($student['hometown'] ?? '');
$region       = $isPost ? sanitize($_POST['region'] ?? '')               : ($student['region'] ?? '');
$nationality  = $isPost ? sanitize($_POST['nationality'] ?? 'Ghanaian')  : ($student['nationality'] ?? 'Ghanaian');
$prev_jhs     = $isPost ? sanitize($_POST['prev_jhs'] ?? '')             : ($student['prev_jhs_school'] ?? '');
$prev_idx     = $isPost ? sanitize($_POST['prev_jhs_index'] ?? '')       : ($student['prev_jhs_index'] ?: $student['index_number']);
$enrol_code   = $isPost ? sanitize($_POST['enrolment_code'] ?? '')       : ($student['enrolment_code'] ?? '');
$aggregate    = $isPost ? sanitize($_POST['aggregate'] ?? '')            : ($student['aggregate'] ?? '');
$father_name  = $isPost ? sanitize($_POST['father_name'] ?? '')          : ($parentInfo['father_name'] ?? '');
$father_phone = $isPost ? sanitize($_POST['father_phone'] ?? '')         : ($parentInfo['father_phone'] ?? '');
$father_addr  = $isPost ? sanitize($_POST['father_address'] ?? '')       : ($parentInfo['father_address'] ?? '');
$father_occ   = $isPost ? sanitize($_POST['father_occupation'] ?? '')    : ($parentInfo['father_occupation'] ?? '');
$mother_name  = $isPost ? sanitize($_POST['mother_name'] ?? '')          : ($parentInfo['mother_name'] ?? '');
$mother_phone = $isPost ? sanitize($_POST['mother_phone'] ?? '')         : ($parentInfo['mother_phone'] ?? '');
$mother_addr  = $isPost ? sanitize($_POST['mother_address'] ?? '')       : ($parentInfo['mother_address'] ?? '');
$mother_occ   = $isPost ? sanitize($_POST['mother_occupation'] ?? '')    : ($parentInfo['mother_occupation'] ?? '');
$grd_name     = $isPost ? sanitize($_POST['guardian_name'] ?? '')        : ($parentInfo['guardian_name'] ?? '');
$grd_phone    = $isPost ? sanitize($_POST['guardian_phone'] ?? '')       : ($parentInfo['guardian_phone'] ?? '');
$grd_addr     = $isPost ? sanitize($_POST['guardian_address'] ?? '')     : ($parentInfo['guardian_address'] ?? '');
$grd_rel      = $isPost ? sanitize($_POST['guardian_relationship'] ?? '') : ($parentInfo['guardian_relationship'] ?? '');
$declaration  = $isPost ? (isset($_POST['declaration']) ? 1 : 0)         : 0;
// house_id is now auto-assigned at save time — no longer captured from POST.
// Baseline photo = whatever's already on file; the upload-processing block
// inside the POST branch below overwrites this only on a successful upload.
$photoPath    = $student['passport_photo_path'] ?? '';

// ── POST: Save registration ───────────────────────────────────────
// $errors is a list of ['field'=>string|null, 'step'=>int|null, 'msg'=>string]
// — field/step drive the auto-scroll/focus behaviour below; null means a
// general error not tied to one input (e.g. CSRF failure).
$errors = [];
if ($isPost) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = ['field' => null, 'step' => null, 'msg' => 'Invalid request. Please refresh and try again.'];
    } else {
        // Validate required
        if (empty($dob))      $errors[] = ['field' => 'dob', 'step' => 1, 'msg' => 'Date of Birth is required.'];
        if (empty($religion)) $errors[] = ['field' => 'religion', 'step' => 1, 'msg' => 'Religion is required.'];
        if (empty($hometown)) $errors[] = ['field' => 'hometown', 'step' => 1, 'msg' => 'Hometown is required.'];
        if (empty($region))   $errors[] = ['field' => 'region', 'step' => 1, 'msg' => 'Region is required.'];
        if (empty($prev_jhs)) $errors[] = ['field' => 'prev_jhs', 'step' => 1, 'msg' => 'Previous JHS name is required.'];
        if (empty($enrol_code)) {
            $errors[] = ['field' => 'enrolment_code', 'step' => 2, 'msg' => 'Enrolment code is required.'];
        } elseif (!validateEnrolmentCode($enrol_code)) {
            $errors[] = ['field' => 'enrolment_code', 'step' => 2, 'msg' => 'Enrolment code must be 4–10 letters and/or numbers.'];
        }
        if (empty($aggregate)) {
            $errors[] = ['field' => 'aggregate', 'step' => 2, 'msg' => 'BECE aggregate is required.'];
        } elseif (!preg_match('/^\d{1,2}$/', $aggregate) || (int) $aggregate < 6 || (int) $aggregate > 54) {
            $errors[] = ['field' => 'aggregate', 'step' => 2, 'msg' => 'BECE aggregate must be a number between 6 and 54.'];
        }
        if (!$declaration) $errors[] = ['field' => 'declaration', 'step' => 5, 'msg' => 'You must accept the declaration to submit.'];

        // House assignment is fully automatic — no student input required.

        // Phone validation
        $isValidPhone = function(string $num): bool {
            $num = preg_replace('/\s+/', '', $num);
            if (empty($num)) return true; // optional
            if (!preg_match('/^(\+233|0233|233)?[0-9]{9,10}$/', $num)) return false;
            $test = $num;
            if (strlen($num) === 9) $test = '0' . $num;
            return preg_match('/^(\+233|233|0)(2[0-9]|5[0-9]|3[0-9])[0-9]{7}$/', $test) === 1;
        };
        if (!empty($father_phone) && !$isValidPhone($father_phone)) {
            $errors[] = ['field' => 'father_phone', 'step' => 3, 'msg' => "Father's phone number is invalid. Must match Ghanaian format."];
        }
        if (!empty($mother_phone) && !$isValidPhone($mother_phone)) {
            $errors[] = ['field' => 'mother_phone', 'step' => 3, 'msg' => "Mother's phone number is invalid. Must match Ghanaian format."];
        }
        if (!empty($grd_phone) && !$isValidPhone($grd_phone)) {
            $errors[] = ['field' => 'guardian_phone', 'step' => 3, 'msg' => "Guardian's phone number is invalid. Must match Ghanaian format."];
        }

        if (!empty($_FILES['passport_photo']['name'])) {
            $file    = $_FILES['passport_photo'];
            $allowed = ['image/jpeg', 'image/png', 'image/jpg'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $magicType = match($mime) {
                'image/jpeg', 'image/jpg' => 'jpeg',
                'image/png'               => 'png',
                default                   => ''
            };

            if (!in_array($mime, $allowed)) {
                $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Passport photo must be a JPG or PNG image.'];
            } elseif (!validateUploadMagicBytes($file['tmp_name'], $magicType)) {
                $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Passport photo file content does not match its type. Please upload a real JPG or PNG image.'];
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Passport photo must be smaller than 5MB.'];
            } else {
                // S07: Re-encode image via GD to strip any embedded payloads
                $srcImage = match($magicType) {
                    'jpeg'  => @imagecreatefromjpeg($file['tmp_name']),
                    'png'   => @imagecreatefrompng($file['tmp_name']),
                    default => false,
                };
                if (!$srcImage) {
                    $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Could not process the uploaded image. Please try a different photo.'];
                } else {
                    // Resize to max 800px on longest side
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
                    $filename = 'P_' . $student['index_number'] . '_' . time() . '.jpg';
                    $destDir  = __DIR__ . '/uploads/passports/';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    $destPath = $destDir . $filename;
                    if (imagejpeg($srcImage, $destPath, 85)) {
                        imagedestroy($srcImage);
                        $photoPath = 'uploads/passports/' . $filename;
                    } else {
                        imagedestroy($srcImage);
                        $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Failed to save photo. Please try again.'];
                    }
                }
            }
        } elseif (empty($photoPath)) {
            $errors[] = ['field' => 'passport_photo', 'step' => 4, 'msg' => 'Passport photo is required.'];
        }

        if (empty($errors)) {
            // ── Auto house assignment (boarders only, balanced load) ───────
            $assignedHouseId = null;
            if ($isBoarder) {
                $assignedHouseId = autoAssignHouse($pdo);
                // If no house is available log it but don't block registration
                if ($assignedHouseId === null) {
                    logAction('system', $sid, 'house_assignment_skipped', 'No active house with space found at registration');
                }
            }

            // ── Auto admission number ─────────────────────────────────────
            $academicYear = $s['academic_year'] ?? date('Y');
            // Extract only the first 4 digits (handles "2026/2027" format)
            if (preg_match('/(\d{4})/', $academicYear, $ym)) {
                $academicYear = $ym[1];
            }
            $admissionNumber = generateAdmissionNumber($pdo, $student['program'] ?? '', $academicYear);

            // Update student record
            $pdo->prepare("UPDATE students SET
                date_of_birth=?, religion=?, hometown=?, region=?, nationality=?,
                prev_jhs_school=?, prev_jhs_index=?, enrolment_code=?, aggregate=?,
                passport_photo_path=?, house_id=?, admission_number=?,
                submission_uuid=?, registration_status='completed', registered_at=NOW()
                WHERE id=?
            ")->execute([$dob, $religion, $hometown, $region, $nationality,
                         $prev_jhs, $prev_idx, $enrol_code, $aggregate, $photoPath,
                         $assignedHouseId, $admissionNumber,
                         $clientUuid !== '' ? $clientUuid : null, $sid]);

            // Parent info — delete old and re-insert
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
                $grd_name, $grd_phone, $grd_addr, $grd_rel
            ]);

            logAction('student', $sid, 'registration_completed', 'Full registration submitted');

            // Send SMS confirmation to both parents/guardians via Hubtel
            $smsTemplate = $s['sms_congratulations_template'] ?? "Dear Parent/Guardian, {name}'s admission registration for {school} ({program}) has been submitted successfully. Please download all required documents from the portal and report on {date}. Join the Parent WhatsApp Group: {whatsapp_link}. Helpline: {helpline}";
            $whatsappLink = $s['school_whatsapp_link'] ?? '';
            $helplineVal = $s['helpline_number'] ?? '';

            $placeholders = [
                '{name}'          => $student['full_name'],
                '{school}'        => $schoolName,
                '{program}'       => $student['program'],
                '{date}'          => $reportingDate,
                '{whatsapp_link}' => $whatsappLink,
                '{helpline}'      => $helplineVal
            ];
            $smsMsg = strtr($smsTemplate, $placeholders);

            $parentPhones = getStudentParentPhones($pdo, $sid);
            foreach ($parentPhones as $phone) {
                sendSms($phone, $smsMsg, $s);
            }

            if ($isSyncRequest) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success']);
                exit;
            }
            redirect(BASE_URL . '/dashboard');
        }
    }

    // Reached only when $errors is non-empty (the success branch above
    // always exits) — hand the sync engine structured errors instead of
    // the HTML page it has no use for.
    if ($isSyncRequest) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'reason' => 'validation', 'errors' => $errors]);
        exit;
    }
}

$ghanaRegions = [
    'Greater Accra','Ashanti','Western','Eastern','Central','Volta','Brong-Ahafo',
    'Northern','Upper East','Upper West','Western North','Ahafo','Bono East',
    'North East','Oti','Savannah'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Register | <?= htmlspecialchars($student['full_name']) ?></title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="manifest" href="<?= BASE_URL ?>/manifest">
  <meta name="theme-color" content="#006fa0">
  <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
  <!-- Self-hosted (not CDN) so the service worker can cache these for offline
       loads — see Phase 2 design decision on CDN-dependency risk. -->
  <link rel="stylesheet" href="<?= asset('assets/vendor/fontawesome/css/all.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/vendor/uikit/css/uikit.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.min.css') ?>">
  <link rel="stylesheet" href="<?= asset('assets/vendor/sweetalert2/sweetalert2.min.css') ?>">
  <script nonce="<?= generateCspNonce() ?>">
    function disableBack() { window.history.forward(); }
    setTimeout(disableBack, 0);
    window.onunload = function() { null; };
  </script>
</head>
<body>
<a href="#main-content" class="skip-nav" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;z-index:9999;background:#006fa0;color:#fff;padding:0.5rem 1rem;border-radius:0 0 4px 0;font-weight:700;">Skip to main content</a>
<div data-uk-sticky="sel-target: .uk-navbar-container; cls-active: uk-navbar-sticky">
  <nav class="uk-navbar-container uk-margin uk-light">
    <div class="uk-container">
      <div data-uk-navbar>
        <div class="uk-navbar-left">
          <span class="logo-ring">
            <img src="<?= asset($logoPath) ?>" alt="Logo">
          </span>
          <a class="uk-navbar-item uk-logo" href="<?= BASE_URL ?>/index" style="margin-left:-4px;font-weight:700;"><?= htmlspecialchars($schoolName) ?></a>
        </div>
        <div class="uk-navbar-right" style="gap:0.75rem;display:flex;align-items:center;">
          <span id="networkStatus" class="network-status" role="status" aria-live="polite" data-state="online">
            <i class="fa-solid fa-wifi" id="networkStatusIcon" aria-hidden="true"></i>
            <span id="networkStatusText">Online</span>
          </span>
          <a href="<?= BASE_URL ?>/logout" class="uk-button uk-button-default uk-button-small" style="border-radius:20px;color:#fff;border-color:rgba(255,255,255,0.3);">Logout</a>
        </div>
      </div>
    </div>
  </nav>
</div>

<section style="margin-top:70px;padding:1.5rem 0 60px;position:relative;z-index:1;" id="main-content">
  <div class="uk-container" style="max-width:680px;">
    <div class="portal-card">
      <!-- Form Header -->
      <div style="text-align:center;margin-bottom:2rem;border-bottom:1px solid rgba(255,255,255,0.1);padding-bottom:1.5rem;">
        <h2 style="
          font-size:2.2rem;
          font-weight:900;
          text-transform:uppercase;
          letter-spacing:1px;
          margin-bottom:0.8rem;
          color:#ffffff;
          text-shadow:0 0 15px rgba(0, 180, 255, 0.8), 0 0 30px rgba(0, 111, 160, 0.5);
        "><i class="fa-solid fa-graduation-cap"></i> Online Admission Registration</h2>
        <div style="
          display:inline-block;
          background:rgba(0,111,160,0.2);
          border:2px solid rgba(0,180,255,0.5);
          border-radius:12px;
          padding:0.6rem 1.75rem;
          margin-top:0.5rem;
          box-shadow: 0 0 15px rgba(0,111,160,0.3);
        ">
          <span style="font-size:1.4rem;font-weight:800;color:#ffffff;letter-spacing:0.5px;display:block;margin-bottom:0.2rem;">
            <?= htmlspecialchars($student['full_name']) ?>
          </span>
          <span style="font-size:1.25rem;font-weight:700;color:#4dd8ff;font-family:monospace;display:block;">
            Index: <?= htmlspecialchars($student['index_number']) ?>
          </span>
        </div>
      </div>

      <div class="alert-error<?= empty($errors) ? ' hidden' : '' ?>" id="formErrors" role="alert" aria-live="assertive" tabindex="-1" style="flex-direction:column;gap:0.25rem;align-items:flex-start;">
        <?php foreach ($errors as $e): ?><div><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($e['msg']) ?></div><?php endforeach; ?>
      </div>

      <!-- PWA Install Banner — shown only when browser offers install prompt -->
      <div id="installBanner" style="display:none;background:linear-gradient(135deg,rgba(0,111,160,0.2),rgba(0,60,90,0.35));border:1px solid rgba(0,180,255,0.35);border-radius:12px;padding:0.85rem 1rem;margin-bottom:1rem;">
        <div style="display:flex;align-items:flex-start;gap:0.75rem;">
          <span style="font-size:1.4rem;flex-shrink:0;">📲</span>
          <div style="flex:1;">
            <strong style="color:#ffffff;font-size:0.9rem;display:block;margin-bottom:0.2rem;">Install for Offline Use</strong>
            <span id="installBannerText" style="color:rgba(255,255,255,0.7);font-size:0.8rem;line-height:1.5;display:block;">
              Add this page to your home screen. You can fill in the form even without internet &mdash; it submits automatically once you&rsquo;re back online.
            </span>
            <div style="display:flex;gap:0.5rem;margin-top:0.6rem;flex-wrap:wrap;">
              <button id="installBtn" type="button"
                style="background:#006fa0;color:#fff;border:none;border-radius:7px;padding:0.4rem 1rem;font-size:0.82rem;font-weight:700;cursor:pointer;">
                <i class="fa-solid fa-download"></i> Install App
              </button>
              <button id="installDismiss" type="button"
                style="background:transparent;color:rgba(255,255,255,0.45);border:1px solid rgba(255,255,255,0.2);border-radius:7px;padding:0.4rem 0.75rem;font-size:0.78rem;cursor:pointer;">
                Not now
              </button>
            </div>
          </div>
        </div>
      </div>

      <div id="queueBanner" class="queue-banner hidden" role="status" aria-live="polite">
        <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
        <span id="queueBannerText"></span>
      </div>


      <p id="draftStatus" role="status" aria-live="polite" style="text-align:right;font-size:0.75rem;color:var(--text-muted);margin:0 0 0.5rem;min-height:1.1em;"></p>

      <!-- PROGRESS BAR -->
      <div class="steps-container">
        <?php
        $steps = ['Bio-Data','Placement','Family','Photo','Review'];
        foreach ($steps as $i => $label): ?>
        <div class="step-item" id="stepItem<?= $i+1 ?>">
          <div class="step-circle"><?= ($i+1) ?></div>
          <span class="step-label"><?= $label ?></span>
        </div>
        <?php endforeach; ?>
      </div>

        <form method="POST" action="<?= BASE_URL ?>/register" enctype="multipart/form-data" id="regForm" autocomplete="off"
              data-index-number="<?= htmlspecialchars($student['index_number']) ?>"
              data-has-errors="<?= !empty($errors) ? '1' : '' ?>"
              data-base-url="<?= htmlspecialchars(BASE_URL) ?>">
        <?= csrfField() ?>
        <input type="hidden" name="client_uuid" id="client_uuid" value="">

        <!-- STEP 1: Bio-Data -->
        <div class="form-step active" id="step1">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 1 — Bio-Data</h5>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Date of Birth *</label>
                <input class="uk-input" type="date" name="dob" id="dob" value="<?= htmlspecialchars($dob) ?>" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Religion *</label>
                <select class="uk-input portal-select" name="religion" id="religion" required>
                  <option value="">Select Religion</option>
                  <?php foreach (['Christian','Muslim','Traditional','Other'] as $rOpt): ?>
                  <option value="<?= $rOpt ?>" <?= $religion === $rOpt ? 'selected' : '' ?>><?= $rOpt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Hometown *</label>
                <input class="uk-input" type="text" name="hometown" id="hometown" value="<?= htmlspecialchars($hometown) ?>" placeholder="e.g. Kikam">
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Region *</label>
                <select class="uk-input portal-select" name="region" id="region" required>
                  <option value="">Select Region</option>
                  <?php foreach ($ghanaRegions as $r): ?>
                  <option value="<?= $r ?>" <?= $region === $r ? 'selected' : '' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Nationality</label>
                <input class="uk-input" type="text" name="nationality" value="<?= htmlspecialchars($nationality) ?>">
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Previous JHS Index No.</label>
                <input class="uk-input" type="text" name="prev_jhs_index" value="<?= htmlspecialchars($prev_idx) ?>" placeholder="BECE Index Number">
              </div>
            </div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">Previous JHS School Name *</label>
            <input class="uk-input" type="text" name="prev_jhs" id="prev_jhs" value="<?= htmlspecialchars($prev_jhs) ?>" placeholder="e.g. Kikam RC JHS">
          </div>
          <div class="step-nav-btns">
            <button type="button" class="btn-step btn-next" data-step="1">Next <i class="fa-solid fa-arrow-right"></i></button>
          </div>
        </div>

        <!-- STEP 2: Placement Details -->
        <div class="form-step" id="step2">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 2 — Placement Details</h5>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Program (from CSSPS)</label>
                <input class="uk-input" type="text" value="<?= htmlspecialchars($student['program'] ?? '') ?>" disabled>
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Residency</label>
                <input class="uk-input" type="text" value="<?= htmlspecialchars($student['residency'] ?? '') ?>" disabled>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">BECE Aggregate *</label>
                <input class="uk-input" type="number" name="aggregate" id="aggregate" min="6" max="54" step="1"
                       value="<?= htmlspecialchars($aggregate) ?>"
                       placeholder="e.g. 12" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Enrolment Code (from placement form) *</label>
                <input class="uk-input" type="text" name="enrolment_code" id="enrolment_code"
                       value="<?= htmlspecialchars($enrol_code) ?>"
                       placeholder="Your enrolment code" required>
              </div>
            </div>
          </div>
          <?php if ($isBoarder): ?>
          <div class="uk-margin">
            <div style="background:rgba(0,111,160,0.12);border:1px solid rgba(0,180,255,0.3);border-radius:10px;padding:0.85rem 1rem;display:flex;align-items:flex-start;gap:0.75rem;">
              <i class="fa-solid fa-house-chimney" style="color:#4dd8ff;font-size:1.2rem;margin-top:2px;flex-shrink:0;"></i>
              <div>
                <strong style="color:#ffffff;font-size:0.9rem;display:block;margin-bottom:0.25rem;">Boarding House — Auto Assigned</strong>
                <span style="color:rgba(255,255,255,0.65);font-size:0.82rem;line-height:1.5;">
                  Your boarding house will be automatically assigned by the system when you complete registration.
                  Houses are balanced so that each house has an equal number of students.
                </span>
              </div>
            </div>
          </div>
          <?php endif; ?>
          <div class="step-nav-btns">
            <button type="button" class="btn-step btn-back" data-step="2"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <button type="button" class="btn-step btn-next" data-step="2">Next <i class="fa-solid fa-arrow-right"></i></button>
          </div>
        </div>

        <!-- STEP 3: Family Info -->
        <div class="form-step" id="step3">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 3 — Parent / Guardian Information</h5>
          <!-- Father -->
          <p style="color:var(--warning);font-weight:600;margin-bottom:0.5rem;font-size:0.85rem;text-transform:uppercase;">Father's Information</p>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Father's Full Name</label><input class="uk-input" type="text" name="father_name" value="<?= htmlspecialchars($father_name) ?>" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="father_phone" id="father_phone" value="<?= htmlspecialchars($father_phone) ?>" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Occupation</label><input class="uk-input" type="text" name="father_occupation" value="<?= htmlspecialchars($father_occ) ?>" placeholder="e.g. Farmer"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="father_address" value="<?= htmlspecialchars($father_addr) ?>" placeholder="Home address"></div></div>
          </div>
          <!-- Mother -->
          <p style="color:var(--warning);font-weight:600;margin:1rem 0 0.5rem;font-size:0.85rem;text-transform:uppercase;">Mother's Information</p>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Mother's Full Name</label><input class="uk-input" type="text" name="mother_name" value="<?= htmlspecialchars($mother_name) ?>" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="mother_phone" id="mother_phone" value="<?= htmlspecialchars($mother_phone) ?>" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Occupation</label><input class="uk-input" type="text" name="mother_occupation" value="<?= htmlspecialchars($mother_occ) ?>" placeholder="e.g. Trader"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="mother_address" value="<?= htmlspecialchars($mother_addr) ?>" placeholder="Home address"></div></div>
          </div>
          <!-- Guardian -->
          <p style="color:var(--warning);font-weight:600;margin:1rem 0 0.5rem;font-size:0.85rem;text-transform:uppercase;">Guardian Information (if applicable)</p>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Guardian Full Name</label><input class="uk-input" type="text" name="guardian_name" value="<?= htmlspecialchars($grd_name) ?>" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="guardian_phone" id="guardian_phone" value="<?= htmlspecialchars($grd_phone) ?>" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Relationship</label><input class="uk-input" type="text" name="guardian_relationship" value="<?= htmlspecialchars($grd_rel) ?>" placeholder="e.g. Uncle, Aunt"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="guardian_address" value="<?= htmlspecialchars($grd_addr) ?>" placeholder="Home address"></div></div>
          </div>
          <div class="step-nav-btns">
            <button type="button" class="btn-step btn-back" data-step="3"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <button type="button" class="btn-step btn-next" data-step="3">Next <i class="fa-solid fa-arrow-right"></i></button>
          </div>
        </div>

        <!-- STEP 4: Photo Upload -->
        <div class="form-step" id="step4">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 4 — Passport Photo</h5>
          <p style="color:var(--text-muted);font-size:0.85rem;margin-bottom:1rem;">Upload a clear, recent passport-size photo. JPG or PNG only. Max 1MB.</p>
          <div class="upload-box" id="uploadBox">
            <label class="upload-label" for="passport_photo">
              <span class="upload-icon" aria-hidden="true"><i class="fa-solid fa-camera"></i></span>
              <span style="color:#ccc;font-size:0.9rem;">Click to select your passport photo</span><br>
              <small style="color:rgba(255,255,255,0.4);">JPG / PNG — Max 1MB</small>
            </label>
          </div>
          <input type="file" name="passport_photo" id="passport_photo" accept="image/jpeg,image/png,image/jpg">
          <img id="photoPreview" class="photo-preview<?= $photoPath ? '' : ' hidden' ?>" src="<?= $photoPath ? asset($photoPath) : '' ?>" alt="Passport preview">
          <p id="photoInfo" style="text-align:center;color:var(--text-muted);font-size:0.78rem;margin-top:0.5rem;"><?= $photoPath ? 'A photo is already on file — select a new one only if you want to replace it.' : '' ?></p>
          <div class="step-nav-btns">
            <button type="button" class="btn-step btn-back" data-step="4"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <button type="button" class="btn-step btn-next" data-step="4">Next <i class="fa-solid fa-arrow-right"></i></button>
          </div>
        </div>

        <!-- STEP 5: Review & Consent -->
        <div class="form-step" id="step5">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 5 — Review & Submit</h5>
          <p style="color:var(--text-muted);font-size:0.84rem;margin-bottom:1rem;">Please review your information carefully before submitting.</p>
          <table class="review-table">
            <tr><td>Full Name</td><td><?= htmlspecialchars($student['full_name']) ?></td></tr>
            <tr><td>Index Number</td><td><?= htmlspecialchars($student['index_number']) ?></td></tr>
            <tr><td>Gender</td><td><?= htmlspecialchars($student['gender']) ?></td></tr>
            <tr><td>Program</td><td><?= htmlspecialchars($student['program']) ?></td></tr>
            <tr><td>Residency</td><td><?= htmlspecialchars($student['residency']) ?></td></tr>
            <tr><td>BECE Aggregate</td><td id="rv_aggregate">—</td></tr>
            <?php if ($isBoarder): ?>
            <tr><td>Boarding House</td><td><em style="color:rgba(255,255,255,0.5);font-size:0.82rem;">Auto-assigned on submission</em></td></tr>
            <?php endif; ?>
            <tr><td>Date of Birth</td><td id="rv_dob">—</td></tr>
            <tr><td>Religion</td><td id="rv_religion">—</td></tr>
            <tr><td>Hometown</td><td id="rv_hometown">—</td></tr>
            <tr><td>Region</td><td id="rv_region">—</td></tr>
            <tr><td>Prev. JHS</td><td id="rv_jhs">—</td></tr>
          </table>
          <!-- Photo preview -->
          <div class="text-center" style="margin:1rem 0;">
            <img id="reviewPhoto" src="" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid var(--primary);display:none;">
          </div>
          <!-- Declaration -->
          <div class="agree-section" style="margin-top:1rem;">
            <label>
              <input type="checkbox" name="declaration" id="declaration" <?= $declaration ? 'checked' : '' ?> required>
              &nbsp;I declare that the information provided above is true and correct to the best of my knowledge.
            </label>
          </div>
          <div class="step-nav-btns">
            <button type="button" class="btn-step btn-back" data-step="5"><i class="fa-solid fa-arrow-left"></i> Back</button>
            <button type="submit" class="btn-step btn-submit" id="submitBtn"><i class="fa-solid fa-circle-check"></i> Submit Registration</button>
          </div>
        </div>

      </form>
    </div>
  </div>
</section>

<footer style="position:relative;z-index:1;border-top:1px solid rgba(255,255,255,0.06);padding:1.25rem;text-align:center;">
  <p style="color:rgba(255,255,255,0.35);font-size:0.78rem;margin:0;">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($schoolName) ?> &mdash; All Rights Reserved.<br>
    <small>Built by <a href="#" style="color:#4dd8ff;text-decoration:none;">Blinks I.T. Solution</a></small>
  </p>
</footer>

<script src="<?= asset('assets/vendor/jquery/jquery-3.6.0.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/uikit/js/uikit.min.js') ?>"></script>
<script src="<?= asset('assets/vendor/sweetalert2/sweetalert2.all.min.js') ?>"></script>
<script src="<?= asset('assets/js/offline-db.js') ?>"></script>
<script src="<?= asset('assets/js/sync-engine.js') ?>"></script>
<script nonce="<?= generateCspNonce() ?>">
let currentStep = 1;
const totalSteps = 5;
const firstErrorStep = <?= json_encode($errors[0]['step'] ?? null) ?>;
const firstErrorField = <?= json_encode($errors[0]['field'] ?? null) ?>;

function updateStepUI(step) {
  document.querySelectorAll('.form-step').forEach((el,i) => {
    el.classList.toggle('active', i+1 === step);
  });
  document.querySelectorAll('.step-item').forEach((el,i) => {
    el.classList.remove('active','completed');
    if (i+1 === step)  el.classList.add('active');
    if (i+1 < step)   el.classList.add('completed');
  });
}

function nextStep(from) {
  if (!validateStep(from)) return;
  if (from === 4) populateReview();
  currentStep = from + 1;
  updateStepUI(currentStep);
  window.scrollTo(0, 0);
}

function prevStep(from) {
  currentStep = from - 1;
  updateStepUI(currentStep);
  window.scrollTo(0, 0);
}

function validateStep(step) {
  if (step === 1) {
    if (!document.getElementById('dob').value) { Swal.fire({icon:'warning',title:'Required',text:'Please enter your Date of Birth.',background:'#0f1e2d',color:'#fff'}); return false; }
    if (!document.getElementById('religion').value) { Swal.fire({icon:'warning',title:'Required',text:'Please select your Religion.',background:'#0f1e2d',color:'#fff'}); return false; }
  }
  if (step === 2) {
    const aggEl = document.getElementById('aggregate');
    if (aggEl && !aggEl.value.trim()) {
      Swal.fire({icon:'warning',title:'Required',text:'Please enter your BECE aggregate.',background:'#0f1e2d',color:'#fff'});
      return false;
    }
    // No house selection needed — house is auto-assigned at save time.
    return true;
  }
  if (step === 3) {
    // Validate any filled-in phone numbers
    const phones = [
      {id:'father_phone', label:"Father's phone"},
      {id:'mother_phone', label:"Mother's phone"},
      {id:'guardian_phone', label:"Guardian's phone"},
    ];
    for (const p of phones) {
      const el = document.getElementById(p.id);
      if (el && el.value.trim() && !isValidGhanaPhone(el.value)) {
        Swal.fire({icon:'warning',title:'Invalid Phone Number',
          text:p.label + ' must be a valid Ghanaian number (e.g. 0244000001 or +233244000001).',
          background:'#0f1e2d',color:'#fff'});
        el.focus(); return false;
      }
    }
  }
  return true;
}

function populateReview() {
  document.getElementById('rv_dob').textContent     = document.querySelector('[name="dob"]').value || '—';
  document.getElementById('rv_religion').textContent = document.querySelector('[name="religion"]').value || '—';
  document.getElementById('rv_hometown').textContent  = document.querySelector('[name="hometown"]').value || '—';
  document.getElementById('rv_region').textContent   = document.querySelector('[name="region"]').value || '—';
  document.getElementById('rv_jhs').textContent      = document.querySelector('[name="prev_jhs"]').value || '—';
  const aggEl = document.getElementById('aggregate');
  const rvAgg = document.getElementById('rv_aggregate');
  if (aggEl && rvAgg) rvAgg.textContent = aggEl.value || '—';
  // House is auto-assigned — nothing to populate for it in the review.
  const preview = document.getElementById('photoPreview');
  const rvPhoto = document.getElementById('reviewPhoto');
  if (preview && !preview.classList.contains('hidden')) {
    rvPhoto.src = preview.src; rvPhoto.style.display = 'block';
  }
}

function previewPhoto(e) {
  const file = e.target.files[0];
  if (!file) return;

  const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg'];
  if (!allowedTypes.includes(file.type)) {
    Swal.fire({icon:'error',title:'Invalid file type',text:'Please select a JPG or PNG image.',background:'#0f1e2d',color:'#fff'});
    e.target.value = ''; return;
  }

  const reader = new FileReader();
  reader.onload = (ev) => {
    const img = new Image();
    img.onload = () => {
      const maxDim = 400;
      let width = img.width;
      let height = img.height;
      if (width > height) {
        if (width > maxDim) {
          height = Math.round((height * maxDim) / width);
          width = maxDim;
        }
      } else {
        if (height > maxDim) {
          width = Math.round((width * maxDim) / height);
          height = maxDim;
        }
      }

      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(img, 0, 0, width, height);

      canvas.toBlob((blob) => {
        if (!blob) {
          Swal.fire({icon:'error',title:'Compression error',text:'Failed to process the photo. Please try another image.',background:'#0f1e2d',color:'#fff'});
          e.target.value = ''; return;
        }

        if (blob.size > 1024 * 1024) {
          Swal.fire({icon:'warning',title:'File too large',text:'Compressed photo is still over 1MB. Please use a smaller image.',background:'#0f1e2d',color:'#fff'});
          e.target.value = ''; return;
        }

        const compressedFile = new File([blob], 'passport_photo.jpg', { type: 'image/jpeg', lastModified: Date.now() });
        const dt = new DataTransfer();
        dt.items.add(compressedFile);
        e.target.files = dt.files;

        const imgPreview = document.getElementById('photoPreview');
        imgPreview.src = URL.createObjectURL(compressedFile);
        imgPreview.classList.remove('hidden');
        document.getElementById('photoInfo').textContent =
          file.name + ' (Compressed: ' + (compressedFile.size / 1024).toFixed(1) + ' KB)';
        document.getElementById('uploadBox').style.borderColor = 'var(--success)';

        // Compression is async — tell the autosave layer the file it reads
        // from #passport_photo is now the final compressed Blob, rather than
        // letting it race the debounce timer against this callback.
        if (window.CDTI_onPhotoReady) window.CDTI_onPhotoReady();
      }, 'image/jpeg', 0.85);
    };
    img.src = ev.target.result;
  };
  reader.readAsDataURL(file);
}

// Ghanaian phone validation
function isValidGhanaPhone(v) {
  v = v.replace(/\s/g,'');
  // Accepts: 024XXXXXXX, 054XXXXXXX, +233XXXXXXXXX, 233XXXXXXXXX etc.
  return /^(\+233|0233|233)?[0-9]{9,10}$/.test(v) &&
         /^(\+233|233|0)(2[0-9]|5[0-9]|3[0-9])[0-9]{7}$/.test(v.length===9?'0'+v:v);
}
function formatGhPhone(el) {
  let v = el.value.replace(/[^0-9+]/g,'');
  // Allow + only at start
  if (v.indexOf('+') > 0) v = v.replace(/\+/g,'');
  el.value = v;
  // Real-time border feedback
  if (v.length >= 9) {
    el.style.borderColor = isValidGhanaPhone(v) ? 'var(--success)' : 'var(--danger)';
  } else {
    el.style.borderColor = '';
  }
}
// Wire up step navigation (moved off inline onclick/onchange/oninput attributes,
// which CSP's nonce'd script-src always blocks regardless of nonce placement).
document.querySelectorAll('.btn-next').forEach(function (btn) {
  btn.addEventListener('click', function () { nextStep(parseInt(this.dataset.step, 10)); });
});
document.querySelectorAll('.btn-back').forEach(function (btn) {
  btn.addEventListener('click', function () { prevStep(parseInt(this.dataset.step, 10)); });
});
document.getElementById('uploadBox').addEventListener('click', function (e) {
  // The label now has for="passport_photo", so a click landing on the label
  // itself already opens the picker natively — only proxy the click when it
  // lands on the box's own padding (outside the label), so the full dashed
  // area stays clickable without double-opening the file dialog.
  if (e.target.closest('label')) return;
  document.getElementById('passport_photo').click();
});
document.getElementById('passport_photo').addEventListener('change', previewPhoto);
['father_phone', 'mother_phone', 'guardian_phone'].forEach(function (id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('input', function () { formatGhPhone(this); });
});

// Jumps to the step containing the first error and focuses the specific
// invalid field (or the error banner itself for a field-less error like a
// stale CSRF token). Shared by the initial server-rendered-error case below
// and by register-offline.js's client-side (fetch-based) submit handler, so
// both paths land the student in exactly the same place.
function focusFirstError(step, field) {
  if (step) {
    currentStep = step;
    updateStepUI(currentStep);
  }
  const target = (field && document.getElementById(field)) || document.getElementById('formErrors');
  if (target) {
    target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    target.focus({ preventScroll: true });
  }
}
window.CDTI_focusFirstError = focusFirstError;

// Init
if (firstErrorStep) {
  focusFirstError(firstErrorStep, firstErrorField);
} else {
  updateStepUI(1);
}
</script>
<script nonce="<?= generateCspNonce() ?>">
// ── PWA Install Banner ────────────────────────────────────────────────────────
// Shows a prompt to add the registration form to the home screen.
// For Android Chrome: intercepts the native beforeinstallprompt event.
// For iOS Safari: shows manual instructions (iOS doesn't support the event).
// Remembers dismissals for 14 days so we don't nag returning students.
(function () {
  var DISMISS_KEY = 'cdti_install_dismissed';
  var DISMISS_DURATION_MS = 14 * 24 * 60 * 60 * 1000; // 14 days

  var banner    = document.getElementById('installBanner');
  var installBtn = document.getElementById('installBtn');
  var dismissBtn = document.getElementById('installDismiss');
  var bannerText = document.getElementById('installBannerText');
  if (!banner) return;

  // Don't show if already installed (running as standalone PWA)
  if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return;
  if (window.navigator.standalone === true) return; // iOS PWA

  // Don't show if recently dismissed
  try {
    var dismissed = localStorage.getItem(DISMISS_KEY);
    if (dismissed && (Date.now() - parseInt(dismissed, 10)) < DISMISS_DURATION_MS) return;
  } catch (e) {}

  function showBanner() {
    banner.style.display = 'block';
  }

  function hideBanner(remember) {
    banner.style.display = 'none';
    if (remember) {
      try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
    }
  }

  dismissBtn && dismissBtn.addEventListener('click', function () { hideBanner(true); });

  // Android / Desktop Chrome — native install prompt
  var deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showBanner();
    installBtn && installBtn.addEventListener('click', function () {
      hideBanner(false);
      deferredPrompt.prompt();
      deferredPrompt.userChoice.then(function (choice) {
        if (choice.outcome === 'accepted') hideBanner(true);
        deferredPrompt = null;
      });
    });
  });

  // Hide after successful install
  window.addEventListener('appinstalled', function () { hideBanner(true); });

  // iOS Safari — manual instructions (no beforeinstallprompt support)
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  var isSafari = /safari/i.test(navigator.userAgent) && !/chrome|crios|fxios/i.test(navigator.userAgent);
  if (isIOS && isSafari) {
    if (bannerText) {
      bannerText.innerHTML = 'Tap <strong style="color:#4dd8ff;">\u2191 Share</strong> then <strong style="color:#4dd8ff;">"Add to Home Screen"</strong>. This lets you fill the form offline &mdash; it will submit automatically once you\'re back online.';
    }
    if (installBtn) installBtn.style.display = 'none'; // No programmatic prompt on iOS
    showBanner();
  }
})();
</script>
<script src="<?= asset('assets/js/register-offline.js') ?>"></script>

</body>
</html>
