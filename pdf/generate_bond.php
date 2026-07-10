<?php
/**
 * generate_bond.php — Bond / Undertaking Form
 * Parent/guardian signs before or on reporting day
 */
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';

if (isset($_GET['admin']) && (string)$_GET['admin'] === '1') {
    requireAdminAuth();
    $isAdmin = true;
    $sid     = (int)($_GET['sid'] ?? 0);
} else {
    requireStudentAuth();
    $isAdmin = false;
    $sid     = (int)($_SESSION['student_id'] ?? 0);
}

if (!$sid) { header('Location: ' . BASE_URL . '/admissions.php'); exit; }

$pdo = getDB();

$pStmt = $pdo->prepare("SELECT s.*, p.* FROM students s LEFT JOIN parent_guardian_info p ON p.student_id=s.id WHERE s.id=? AND s.registration_status='completed' LIMIT 1");
$pStmt->execute([$sid]);
$student = $pStmt->fetch();
if (!$student) die('<p style="padding:2rem;font-family:sans-serif;color:red;">⚠ Registration not found.</p>');

$s              = getSettings();
$schoolName     = $s['school_name']    ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$schoolAddr     = $s['school_address'] ?? 'Sanzule, Western Region, Ghana';
$academicYear   = $s['academic_year']  ?? '2026/2027';
$principalName  = $s['principal_name'] ?? 'The Principal';
$houseMasterName = $s['house_master_name'] ?? '';
$reportingDate  = $s['reporting_date'] ?? '15th September, 2026';
$logoSrc        = !empty($s['school_logo_path']) ? fileToDataUri($s['school_logo_path']) : '';
$stampSrc       = !empty($s['school_stamp_path']) ? fileToDataUri($s['school_stamp_path']) : '';
$letterheadSrc  = !empty($s['letterhead_header_path']) ? fileToDataUri($s['letterhead_header_path']) : '';
$sigSrc         = !empty($s['principal_signature_path']) ? fileToDataUri($s['principal_signature_path']) : '';
$houseMasterSigSrc = !empty($s['house_master_signature_path']) ? fileToDataUri($s['house_master_signature_path']) : '';
// Display-only: strip a leading "Mr." courtesy title, matching the admission letter
$principalNameDisplay = preg_replace('/^Mr\.?\s+/i', '', $principalName);

// Check if admin uploaded a custom bond form PDF
$bondPdfPath = $s['bond_form_path'] ?? '';
if (!empty($bondPdfPath)) {
    $fullPath = appPath($bondPdfPath);
    if (file_exists($fullPath)) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="bond_form.pdf"');
        readfile($fullPath);
        exit;
    }
}

// Otherwise generate dynamic bond form
$guardianName  = $student['guardian_name']  ?: $student['father_name']  ?: '________________________';
$guardianPhone = $student['guardian_phone'] ?: $student['father_phone'] ?: '________________________';
$guardianAddr  = $student['guardian_address'] ?: $student['father_address'] ?: '________________________';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Bond Form — <?= htmlspecialchars($student['full_name']) ?></title>
  <style>
    @page { size: A4; margin: 0; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Times New Roman',Times,serif; font-size:11pt; color:#000; background:#fff; }
    .page { width:210mm; min-height:297mm; padding:0mm 20mm 10mm; }
    .letterhead { display:flex; align-items:center; border-bottom:3px double #003366; padding-bottom:10px; margin-bottom:10px; }
    .letterhead.letterhead-image { display:block; padding-bottom:0; margin-bottom:0; border-bottom:none; }
    .letterhead-banner { width:100%; display:block; object-fit:contain; }
    .logo { width:70px; height:70px; object-fit:contain; margin-right:15px; border-radius:50%; }
    .logo-ph { width:70px;height:70px;background:#e8eef8;border-radius:50%;margin-right:15px;display:flex;align-items:center;justify-content:center;font-size:2rem; }
    .school-name { font-size:15pt; font-weight:bold; color:#003366; text-transform:uppercase; }
    .school-sub  { font-size:8.5pt; color:#555; }
    .doc-title { text-align:center; margin:4px 0; }
    .doc-title h2 { font-size:14pt; font-weight:bold; text-decoration:underline; text-transform:uppercase; }
    .doc-title p  { font-size:10pt; color:#555; margin-top:4px; }
    .body-text { line-height:1.9; text-align:justify; margin-bottom:10px; font-size:11pt; }
    .blank { border-bottom:1px solid #000; display:inline-block; min-width:180px; vertical-align:bottom; }
    .undertaking-list { list-style:decimal; padding-left:1.4rem; margin:8px 0; }
    .undertaking-list li { margin-bottom:6px; line-height:1.6; font-size:11pt; }
    .sign-table { width:100%; border-collapse:collapse; margin-top:16px; }
    .sign-table td { padding:10px 10px 6px; vertical-align:bottom; width:50%; text-align:center; }
    .sign-line { border-top:1px solid #000; margin-bottom:4px; }
    .sign-label { font-size:9pt; font-weight:bold; }
    .sign-sub   { font-size:8.5pt; color:#666; }
    .sign-image { max-height:45px; max-width:140px; object-fit:contain; display:block; margin:0 auto; }
    .stamp-sig-row { display:flex; align-items:center; justify-content:center; gap:10px; }
    .stamp-box { width:65px; height:48px; border:1.5px dashed #999; border-radius:4px; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:7pt; color:#aaa; flex-shrink:0; }
    .stamp-box img { width:100%; height:100%; object-fit:contain; }
    .office-box { border:1px solid #ccc; padding:10px; margin-top:10px; border-radius:3px; }
    .office-box h4 { font-size:10pt; font-weight:bold; color:#003366; margin-bottom:8px; text-transform:uppercase; }
    .footer-bar { text-align:center; margin-top:20px; border-top:1px solid #ddd; padding-top:6px; font-size:7.5pt; color:#888; }
    .no-print { text-align:center; padding:12px; background:#f0f4ff; border-bottom:1px solid #dde; }
    .no-print button { background:#003366; color:#fff; border:none; padding:9px 24px; border-radius:6px; cursor:pointer; margin:0 5px; font-size:0.92rem; }
    .no-print button.sec { background:#666; }
    @media print { .no-print { display:none !important; } }
  </style>
</head>
<body>
<div class="no-print">
  <button onclick="window.print()">🖨 Print / Save as PDF</button>
  <button class="sec" onclick="window.close()">✕ Close</button>
</div>
<div class="page">
  <!-- Letterhead -->
  <?php if ($letterheadSrc): ?>
  <div class="letterhead letterhead-image">
    <img src="<?= $letterheadSrc ?>" alt="<?= htmlspecialchars($schoolName) ?>" class="letterhead-banner">
  </div>
  <?php else: ?>
  <div class="letterhead">
    <?php if ($logoSrc): ?>
    <img src="<?= $logoSrc ?>" alt="Logo" class="logo">
    <?php else: ?>
    <div class="logo-ph">🎓</div>
    <?php endif; ?>
    <div style="flex:1;text-align:center;">
      <div class="school-name"><?= htmlspecialchars($schoolName) ?></div>
      <div class="school-sub"><?= htmlspecialchars($schoolAddr) ?></div>
      <div class="school-sub">Academic Year: <?= htmlspecialchars($academicYear) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Title -->
  <div class="doc-title">
    <h2>Undertaking Form</h2>
    <p>To be signed by Parent / Guardian before or on reporting day</p>
  </div>

  <!-- Body -->
  <p class="body-text">
    I, <span class="blank"><?= htmlspecialchars($guardianName) ?></span>,
    parent / guardian of <span class="blank"><strong><?= htmlspecialchars($student['full_name']) ?></strong></span>,
    with Index Number <span class="blank" style="font-family:monospace;"><?= htmlspecialchars($student['index_number']) ?></span>,
    admitted to <strong><?= htmlspecialchars($schoolName) ?></strong> for the
    <strong><?= htmlspecialchars($academicYear) ?></strong> academic year under the
    <strong><?= htmlspecialchars($student['program']) ?></strong> programme, do hereby solemnly undertake as follows:
  </p>

  <ol class="undertaking-list">
    <li>I accept full responsibility for the good conduct and behaviour of my ward while in school and in the school's community.</li>
    <li>I will ensure my ward reports to school on or before <strong><?= htmlspecialchars($reportingDate) ?></strong> with all required documents and items.</li>
    <li>I will not encourage my ward to absent themselves from school without written permission from the school authorities.</li>
    <li>I understand that <strong><?= htmlspecialchars($schoolName) ?></strong> reserves the right to dismiss my ward for gross misconduct, academic dishonesty, or consistent fee default.</li>
    <li>I will cooperate with the school authorities in all disciplinary matters affecting my ward.</li>
    <li>I hereby confirm that all information provided in the admission form is true and correct, and I accept that false declarations may lead to withdrawal of admission.</li>
  </ol>

  <p class="body-text" style="margin-top:12px;">
    Contact Address: <span class="blank"><?= htmlspecialchars($guardianAddr) ?></span><br>
    Phone Number: <span class="blank"><?= htmlspecialchars($guardianPhone) ?></span>
  </p>

  <!-- Signatures -->
  <table class="sign-table">
    <tr>
      <td>
        <div class="sign-line"></div>
        <div class="sign-label">Parent / Guardian Signature</div>
        <div class="sign-sub"><?= htmlspecialchars($guardianName) ?></div>
        <div class="sign-sub">Date: ___________________</div>
      </td>
      <td>
        <div class="sign-line"></div>
        <div class="sign-label">Student Signature</div>
        <div class="sign-sub"><?= htmlspecialchars($student['full_name']) ?></div>
        <div class="sign-sub">Date: ___________________</div>
      </td>
    </tr>
    <tr>
      <td>
        <?php if ($houseMasterSigSrc): ?>
        <img src="<?= $houseMasterSigSrc ?>" alt="Snr. House Master Signature" class="sign-image">
        <?php else: ?>
        <div class="sign-line"></div>
        <?php endif; ?>
        <div class="sign-label">Snr. House Master's Signature</div>
        <?php if ($houseMasterName): ?>
        <div class="sign-sub"><?= htmlspecialchars($houseMasterName) ?></div>
        <?php endif; ?>
        <div class="sign-sub">Date: ___________________</div>
      </td>
      <td>
        <?php if ($sigSrc): ?>
        <img src="<?= $sigSrc ?>" alt="Principal Signature" class="sign-image">
        <?php else: ?>
        <div class="sign-line" style="width:110px;margin:0 auto 4px;"></div>
        <?php endif; ?>
        <div class="stamp-sig-row">
          <div style="text-align:center;">
            <div class="sign-label">Principal's Signature</div>
            <div class="sign-sub"><?= htmlspecialchars($principalNameDisplay) ?></div>
          </div>
          <div class="stamp-box">
            <?php if ($stampSrc): ?>
            <img src="<?= $stampSrc ?>" alt="School Stamp">
            <?php else: ?>STAMP<?php endif; ?>
          </div>
        </div>
        <div class="sign-sub">Date: ___________________</div>
      </td>
    </tr>
  </table>

  <!-- Office Use Box -->
  <div class="office-box">
    <h4>For Office Use Only</h4>
    <table style="width:100%;font-size:9.5pt;">
      <tr>
        <td>Received by: _________________________</td>
        <td>Date Received: _____________________</td>
        <td>File No: ___________________________</td>
      </tr>
    </table>
  </div>

  <div class="footer-bar">
    <?= htmlspecialchars($schoolName) ?> | Bond Form | Generated: <?= date('d/m/Y H:i') ?>
  </div>
</div>
</body>
</html>
