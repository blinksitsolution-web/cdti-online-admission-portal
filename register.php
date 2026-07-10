<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
requireStudentAuth();
emitCspHeader();

// Rate limit: max 5 registration POST submissions per student session per hour
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = getDB();
    $sid  = (int) $_SESSION['student_id'];
    $ip   = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rlStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM audit_logs WHERE actor_id=? AND action='registration_attempt' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $rlStmt->execute([$sid]);
    if ((int)$rlStmt->fetchColumn() >= 5) {
        http_response_code(429);
        die('Too many submission attempts. Please wait before trying again.');
    }
    logAction('student', $sid, 'registration_attempt', "IP: $ip");
}

$pdo  = getDB();
$sid  = (int) $_SESSION['student_id'];
$stmt = $pdo->prepare("SELECT * FROM students WHERE id=?");
$stmt->execute([$sid]);
$student = $stmt->fetch();

if (!$student) { session_destroy(); redirect(BASE_URL . '/admissions'); }
if ($student['registration_status'] === 'completed') { redirect(BASE_URL . '/dashboard'); }

$s = getSettings();
$logoPath   = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$schoolName = $s['school_name'] ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';

// Guard: payment required
$payEnabled = ($s['payment_enabled'] ?? '1') === '1';
if ($payEnabled && !in_array($student['payment_status'], ['paid','waived'])) {
    redirect(BASE_URL . '/payment');
}

$reportingDate = $s['reporting_date'] ?? '15th September, 2026';
$isBoarder = isBoarderResidency($student['residency'] ?? '');

// ── POST: Save registration ───────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please refresh and try again.';
    } else {
        // Collect & sanitize fields
        $dob          = sanitize($_POST['dob'] ?? '');
        $religion     = sanitize($_POST['religion'] ?? '');
        $hometown     = sanitize($_POST['hometown'] ?? '');
        $region       = sanitize($_POST['region'] ?? '');
        $nationality  = sanitize($_POST['nationality'] ?? 'Ghanaian');
        $prev_jhs     = sanitize($_POST['prev_jhs'] ?? '');
        $prev_idx     = sanitize($_POST['prev_jhs_index'] ?? '');
        $enrol_code   = sanitize($_POST['enrolment_code'] ?? '');
        $aggregate    = sanitize($_POST['aggregate'] ?? '');
        $father_name  = sanitize($_POST['father_name'] ?? '');
        $father_phone = sanitize($_POST['father_phone'] ?? '');
        $father_addr  = sanitize($_POST['father_address'] ?? '');
        $father_occ   = sanitize($_POST['father_occupation'] ?? '');
        $mother_name  = sanitize($_POST['mother_name'] ?? '');
        $mother_phone = sanitize($_POST['mother_phone'] ?? '');
        $mother_addr  = sanitize($_POST['mother_address'] ?? '');
        $mother_occ   = sanitize($_POST['mother_occupation'] ?? '');
        $grd_name     = sanitize($_POST['guardian_name'] ?? '');
        $grd_phone    = sanitize($_POST['guardian_phone'] ?? '');
        $grd_addr     = sanitize($_POST['guardian_address'] ?? '');
        $grd_rel      = sanitize($_POST['guardian_relationship'] ?? '');
        $declaration  = isset($_POST['declaration']) ? 1 : 0;
        $houseId      = (int) ($_POST['house_id'] ?? 0);

        // Validate required
        if (empty($dob))      $errors[] = 'Date of Birth is required.';
        if (empty($religion)) $errors[] = 'Religion is required.';
        if (empty($hometown)) $errors[] = 'Hometown is required.';
        if (empty($region))   $errors[] = 'Region is required.';
        if (empty($prev_jhs)) $errors[] = 'Previous JHS name is required.';
        if (empty($enrol_code)) {
            $errors[] = 'Enrolment code is required.';
        } elseif (!validateEnrolmentCode($enrol_code)) {
            $errors[] = 'Enrolment code must be 4–10 digits.';
        }
        if (empty($aggregate)) {
            $errors[] = 'BECE aggregate is required.';
        } elseif (!preg_match('/^\d{1,2}$/', $aggregate) || (int) $aggregate < 6 || (int) $aggregate > 54) {
            $errors[] = 'BECE aggregate must be a number between 6 and 54.';
        }
        if (!$declaration)    $errors[] = 'You must accept the declaration to submit.';

        if ($isBoarder) {
            if ($houseId < 1) {
                $errors[] = 'Please select a boarding house.';
            } elseif (!isHouseAvailableForStudent($pdo, $houseId, normalizeStudentGender($student['gender']), $sid)) {
                $errors[] = 'The selected house is no longer available. Please choose another house.';
            }
        } else {
            $houseId = 0;
        }

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
            $errors[] = "Father's phone number is invalid. Must match Ghanaian format.";
        }
        if (!empty($mother_phone) && !$isValidPhone($mother_phone)) {
            $errors[] = "Mother's phone number is invalid. Must match Ghanaian format.";
        }
        if (!empty($grd_phone) && !$isValidPhone($grd_phone)) {
            $errors[] = "Guardian's phone number is invalid. Must match Ghanaian format.";
        }

        $photoPath = $student['passport_photo_path'] ?? '';
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
                $errors[] = 'Passport photo must be a JPG or PNG image.';
            } elseif (!validateUploadMagicBytes($file['tmp_name'], $magicType)) {
                $errors[] = 'Passport photo file content does not match its type. Please upload a real JPG or PNG image.';
            } elseif ($file['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Passport photo must be smaller than 5MB.';
            } else {
                // S07: Re-encode image via GD to strip any embedded payloads
                $srcImage = match($magicType) {
                    'jpeg'  => @imagecreatefromjpeg($file['tmp_name']),
                    'png'   => @imagecreatefrompng($file['tmp_name']),
                    default => false,
                };
                if (!$srcImage) {
                    $errors[] = 'Could not process the uploaded image. Please try a different photo.';
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
                        $errors[] = 'Failed to save photo. Please try again.';
                    }
                }
            }
        } elseif (empty($photoPath)) {
            $errors[] = 'Passport photo is required.';
        }

        if (empty($errors)) {
            // Update student
            $pdo->prepare("UPDATE students SET
                date_of_birth=?, religion=?, hometown=?, region=?, nationality=?,
                prev_jhs_school=?, prev_jhs_index=?, enrolment_code=?, aggregate=?,
                passport_photo_path=?, house_id=?, registration_status='completed', registered_at=NOW()
                WHERE id=?
            ")->execute([$dob, $religion, $hometown, $region, $nationality,
                         $prev_jhs, $prev_idx, $enrol_code, $aggregate, $photoPath,
                         $houseId > 0 ? $houseId : null, $sid]);

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

            redirect(BASE_URL . '/dashboard');
        }
    }
}

$availableHouses = $isBoarder ? getAvailableHouses($pdo, normalizeStudentGender($student['gender']), $sid) : [];
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
  <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/css/uikit.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
        <div class="uk-navbar-right">
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

      <?php if (!empty($errors)): ?>
      <div class="alert-error" style="flex-direction:column;gap:0.25rem;align-items:flex-start;">
        <?php foreach ($errors as $e): ?><div><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
      </div>
      <?php endif; ?>

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

        <form method="POST" action="<?= BASE_URL ?>/register" enctype="multipart/form-data" id="regForm" autocomplete="off">
        <?= csrfField() ?>

        <!-- STEP 1: Bio-Data -->
        <div class="form-step active" id="step1">
          <h5 style="color:var(--primary-light);margin-bottom:1rem;font-weight:700;">Step 1 — Bio-Data</h5>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Date of Birth *</label>
                <input class="uk-input" type="date" name="dob" id="dob" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Religion *</label>
                <select class="uk-input portal-select" name="religion" id="religion" required>
                  <option value="">Select Religion</option>
                  <option value="Christian">Christian</option>
                  <option value="Muslim">Muslim</option>
                  <option value="Traditional">Traditional</option>
                  <option value="Other">Other</option>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Hometown *</label>
                <input class="uk-input" type="text" name="hometown" placeholder="e.g. Kikam">
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Region *</label>
                <select class="uk-input portal-select" name="region" id="region" required>
                  <option value="">Select Region</option>
                  <?php foreach ($ghanaRegions as $r): ?>
                  <option value="<?= $r ?>"><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Nationality</label>
                <input class="uk-input" type="text" name="nationality" value="Ghanaian">
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Previous JHS Index No.</label>
                <input class="uk-input" type="text" name="prev_jhs_index" value="<?= htmlspecialchars($student['prev_jhs_index'] ?: $student['index_number']) ?>" placeholder="BECE Index Number">
              </div>
            </div>
          </div>
          <div class="uk-margin">
            <label class="uk-form-label">Previous JHS School Name *</label>
            <input class="uk-input" type="text" name="prev_jhs" value="<?= htmlspecialchars($student['prev_jhs_school'] ?? '') ?>" placeholder="e.g. Kikam RC JHS">
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
                       value="<?= htmlspecialchars($student['aggregate'] ?? '') ?>"
                       placeholder="e.g. 12" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="uk-margin">
                <label class="uk-form-label">Enrolment Code (from placement form) *</label>
                <input class="uk-input" type="text" name="enrolment_code" id="enrolment_code"
                       value="<?= htmlspecialchars($student['enrolment_code'] ?? '') ?>"
                       placeholder="Your enrolment code" required>
              </div>
            </div>
          </div>
          <?php if ($isBoarder): ?>
          <div class="uk-margin">
            <label class="uk-form-label">Boarding House * <small style="color:#4dd8ff;">(<?= htmlspecialchars($student['gender']) ?> houses only)</small></label>
            <?php if (empty($availableHouses)): ?>
            <div class="alert-error" style="margin-top:0.5rem;">
              <i class="fa-solid fa-triangle-exclamation"></i>
              No boarding houses are currently available for <?= htmlspecialchars($student['gender']) ?> students. Please contact the school helpline.
            </div>
            <?php else: ?>
            <select class="uk-input portal-select" name="house_id" id="house_id" required>
              <option value="">Select your house</option>
              <?php foreach ($availableHouses as $house): ?>
              <option value="<?= (int) $house['id'] ?>">
                <?= htmlspecialchars($house['name']) ?> (<?= (int) $house['remaining'] ?> bed<?= $house['remaining'] === 1 ? '' : 's' ?> left)
              </option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
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
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Father's Full Name</label><input class="uk-input" type="text" name="father_name" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="father_phone" id="father_phone" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Occupation</label><input class="uk-input" type="text" name="father_occupation" placeholder="e.g. Farmer"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="father_address" placeholder="Home address"></div></div>
          </div>
          <!-- Mother -->
          <p style="color:var(--warning);font-weight:600;margin:1rem 0 0.5rem;font-size:0.85rem;text-transform:uppercase;">Mother's Information</p>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Mother's Full Name</label><input class="uk-input" type="text" name="mother_name" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="mother_phone" id="mother_phone" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Occupation</label><input class="uk-input" type="text" name="mother_occupation" placeholder="e.g. Trader"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="mother_address" placeholder="Home address"></div></div>
          </div>
          <!-- Guardian -->
          <p style="color:var(--warning);font-weight:600;margin:1rem 0 0.5rem;font-size:0.85rem;text-transform:uppercase;">Guardian Information (if applicable)</p>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Guardian Full Name</label><input class="uk-input" type="text" name="guardian_name" placeholder="Full name"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Phone Number <small style="color:#4dd8ff;font-size:0.72rem;">(Ghana: 024x/054x/055x...)</small></label><input class="uk-input" type="tel" name="guardian_phone" id="guardian_phone" placeholder="e.g. 0244000001" maxlength="13"></div></div>
          </div>
          <div class="row">
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Relationship</label><input class="uk-input" type="text" name="guardian_relationship" placeholder="e.g. Uncle, Aunt"></div></div>
            <div class="col-md-6"><div class="uk-margin"><label class="uk-form-label">Address</label><input class="uk-input" type="text" name="guardian_address" placeholder="Home address"></div></div>
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
            <label class="upload-label">
              <span class="upload-icon"><i class="fa-solid fa-camera"></i></span>
              <span style="color:#ccc;font-size:0.9rem;">Click to select your passport photo</span><br>
              <small style="color:rgba(255,255,255,0.4);">JPG / PNG — Max 1MB</small>
            </label>
          </div>
          <input type="file" name="passport_photo" id="passport_photo" accept="image/jpeg,image/png,image/jpg">
          <img id="photoPreview" class="photo-preview hidden" src="" alt="Passport preview">
          <p id="photoInfo" style="text-align:center;color:var(--text-muted);font-size:0.78rem;margin-top:0.5rem;"></p>
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
            <tr><td>Boarding House</td><td id="rv_house">—</td></tr>
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
              <input type="checkbox" name="declaration" id="declaration" required>
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

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script nonce="<?= generateCspNonce() ?>">
let currentStep = 1;
const totalSteps = 5;

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
    const houseEl = document.getElementById('house_id');
    if (houseEl && !houseEl.value) {
      Swal.fire({icon:'warning',title:'Required',text:'Please select a boarding house.',background:'#0f1e2d',color:'#fff'});
      return false;
    }
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
  const houseEl = document.getElementById('house_id');
  const rvHouse = document.getElementById('rv_house');
  if (houseEl && rvHouse) {
    rvHouse.textContent = houseEl.options[houseEl.selectedIndex]?.text || '—';
  }
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
document.getElementById('uploadBox').addEventListener('click', function () {
  document.getElementById('passport_photo').click();
});
document.getElementById('passport_photo').addEventListener('change', previewPhoto);
['father_phone', 'mother_phone', 'guardian_phone'].forEach(function (id) {
  var el = document.getElementById(id);
  if (el) el.addEventListener('input', function () { formatGhPhone(this); });
});

// Init
updateStepUI(1);
</script>
</body>
</html>
