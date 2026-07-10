<?php
/**
 * generate_letter.php — Admission Letter PDF
 * Fixed: uses fileToDataUri() so signature & stamp show correctly
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

$stmt = $pdo->prepare("SELECT * FROM students WHERE id=? AND registration_status='completed' LIMIT 1");
$stmt->execute([$sid]);
$student = $stmt->fetch();
if (!$student) die('<p style="padding:2rem;font-family:sans-serif;color:red;">⚠ Registration not found.</p>');

$s              = getSettings();
$schoolName     = $s['school_name']      ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$schoolAddr     = $s['school_address']   ?? 'Sanzule, Western Region, Ghana';
$schoolPhone    = $s['school_phone']     ?? '+233 0542947685';
$schoolEmail    = $s['school_email']     ?? 'info@cdti.com';
$academicYear   = $s['academic_year']    ?? '2026/2027';
$principalName  = $s['principal_name']   ?? 'The Principal';
// Display-only: strip a leading "Mr." courtesy title from the signature block
$principalNameDisplay = preg_replace('/^Mr\.?\s+/i', '', $principalName);
$reportingDate  = $s['reporting_date']   ?? '15th September, 2026';
$csspsYear      = $s['cssps_year']       ?? date('Y');

// ── Inline images as base64 ──────────────────────────────────────
$logoSrc        = !empty($s['school_logo_path'])          ? fileToDataUri($s['school_logo_path'])          : '';
$sigSrc         = !empty($s['principal_signature_path'])  ? fileToDataUri($s['principal_signature_path'])  : '';
$stampSrc       = !empty($s['school_stamp_path'])          ? fileToDataUri($s['school_stamp_path'])          : '';
$letterheadSrc  = !empty($s['letterhead_header_path'])     ? fileToDataUri($s['letterhead_header_path'])     : '';

// ── Sequential admission reference number (CDTI/ADM/<year>/0001) ──
// Assigned once per student, first time their letter is generated, then
// persisted so reprints always show the same reference.
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN admission_ref_seq INT DEFAULT NULL");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
}
if (empty($student['admission_ref_seq'])) {
    $nextSeq = (int) $pdo->query("SELECT COALESCE(MAX(admission_ref_seq), 0) + 1 FROM students")->fetchColumn();
    $pdo->prepare("UPDATE students SET admission_ref_seq = ? WHERE id = ?")->execute([$nextSeq, $sid]);
    $student['admission_ref_seq'] = $nextSeq;
}
$refNumber = 'CDTI/ADM/' . date('Y') . '/' . str_pad((string) $student['admission_ref_seq'], 4, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Admission Letter — <?= htmlspecialchars($student['full_name']) ?></title>
  <style>
    @page { size: A4; margin: 0; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Times New Roman',Times,serif; font-size:11pt; color:#000; background:#fff; }
    .page { width:210mm; min-height:297mm; padding:2mm 20mm 12mm; }
    .letterhead { display:flex; align-items:center; border-bottom:2px solid #003366; padding-bottom:12px; margin-bottom:8px; }
    .letterhead.letterhead-image { display:block; padding-bottom:0; margin-bottom:0; border-bottom:none; }
    .letterhead-banner { width:100%; display:block; object-fit:contain; }
    .letterhead .logo { width:75px; height:75px; object-fit:contain; margin-right:15px; border-radius:50%; }
    .letterhead .logo-placeholder { width:75px; height:75px; background:#e8eef8; border-radius:50%; margin-right:15px; display:flex; align-items:center; justify-content:center; font-size:2rem; }
    .letterhead .school-block { flex:1; text-align:center; }
    .school-name { font-size:16pt; font-weight:bold; color:#003366; text-transform:uppercase; letter-spacing:0.5px; }
    .school-sub  { font-size:9pt; color:#555; margin-top:2px; }
    .contact-bar { font-size:8.5pt; color:#666; margin-top:4px; }
    .contact-bar span { margin:0 8px; }
    .meta-row { display:flex; justify-content:space-between; margin:4px 0; font-size:9.5pt; }
    .doc-title { text-align:center; margin:8px 0; }
    .doc-title h2 { font-size:13pt; font-weight:bold; text-transform:uppercase; text-decoration:underline; }
    .salutation { margin:12px 0 8px; font-weight:bold; font-size:11pt; }
    .body-text { line-height:1.8; text-align:justify; margin-bottom:8px; font-size:11pt; }
    .body-text strong { font-weight:bold; text-transform:uppercase; }
    .details-table { width:100%; border-collapse:collapse; margin:10px 0; font-size:10.5pt; }
    .details-table td { padding:5px 10px; border:1px solid #ccc; }
    .details-table td:first-child { font-weight:bold; background:#f0f4ff; width:38%; }
    .conditions-box { background:#fffbea; border:1px solid #e0a000; border-radius:4px; padding:10px 14px; margin:10px 0; }
    .conditions-box h4 { color:#8a5a00; font-size:10pt; margin-bottom:6px; }
    .conditions-box ol { padding-left:1.2rem; font-size:9.5pt; color:#333; }
    .conditions-box ol li { margin-bottom:4px; line-height:1.5; color:#333; }
    .sign-section { margin-top:18px; display:flex; justify-content:flex-start; align-items:flex-end; }
    .sign-left { }
    .sign-stamp { margin-left:35px; }
    .sig-image { max-height:55px; max-width:150px; object-fit:contain; display:block; margin-bottom:0; }
    .sig-line { border-top:1px solid #000; width:160px; margin-top:35px; }
    .sig-name { font-size:10pt; font-weight:bold; margin-top:3px; }
    .sig-title { font-size:9pt; color:#555; }
    .stamp-box { width:110px; height:80px; border:1.5px dashed #999; border-radius:4px; display:flex; align-items:center; justify-content:center; overflow:hidden; font-size:8pt; color:#aaa; text-align:center; }
    .stamp-box img { width:100%; height:100%; object-fit:contain; }
    .footer-bar { text-align:center; margin-top:20px; border-top:1px solid #ddd; padding-top:6px; font-size:8pt; color:#888; }
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
    <div class="logo-placeholder">🎓</div>
    <?php endif; ?>
    <div class="school-block">
      <div class="school-name"><?= htmlspecialchars($schoolName) ?></div>
      <div class="school-sub">A Senior High Technical School</div>
      <div class="contact-bar">
        <span>📍 <?= htmlspecialchars($schoolAddr) ?></span>
        <span>📞 <?= htmlspecialchars($schoolPhone) ?></span>
        <span>✉ <?= htmlspecialchars($schoolEmail) ?></span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Meta -->
  <div class="meta-row">
    <div>Ref: <?= htmlspecialchars($refNumber) ?></div>
    <div>Date: <?= date('jS F, Y') ?></div>
  </div>

  <!-- Title -->
  <div class="doc-title">
    <h2>Letter of Admission — Academic Year <?= htmlspecialchars($academicYear) ?></h2>
  </div>

  <!-- Salutation -->
  <p class="salutation">Dear <?= htmlspecialchars($student['full_name']) ?>,</p>

  <!-- Body -->
  <p class="body-text">
    We are pleased to inform you that you have been offered admission to <strong><?= htmlspecialchars($schoolName) ?></strong>
    for the <strong><?= htmlspecialchars($academicYear) ?></strong> academic year based on your placement by the
    <strong>Computerised School Selection and Placement System (CSSPS)</strong>.
    Please find your admission details below:
  </p>

  <!-- Details Table -->
  <table class="details-table">
    <tr><td>Full Name</td><td><?= htmlspecialchars($student['full_name']) ?></td></tr>
    <tr><td>Index Number</td><td style="font-family:monospace;"><?= htmlspecialchars($student['index_number']) ?></td></tr>
    <tr><td>Gender</td><td><?= htmlspecialchars($student['gender']) ?></td></tr>
    <tr><td>Programme</td><td><span style="color:#006600;font-weight:bold;"><?= htmlspecialchars($student['program']) ?></span></td></tr>
    <tr><td>Residency</td><td><?= htmlspecialchars($student['residency']) ?></td></tr>
    <tr><td>BECE Aggregate</td><td><?= htmlspecialchars($student['aggregate'] ?? '—') ?></td></tr>
    <tr><td>Academic Year</td><td><?= htmlspecialchars($academicYear) ?></td></tr>
    <tr><td>Reporting Date</td><td><span style="color:#cc0000;font-weight:bold;"><?= htmlspecialchars($reportingDate) ?></span></td></tr>
  </table>

  <!-- Conditions -->
  <div class="conditions-box">
    <h4>⚠ CONDITIONS OF ADMISSION:</h4>
    <ol>
      <li>This letter is valid only when accompanied by your original CSSPS placement form.</li>
      <li>You are required to report with all required documents on or before the stated reporting date.</li>
      <li>Failure to report on time may result in forfeiture of your admission.</li>
      <li>Your admission is subject to verification of all submitted documents.</li>
    </ol>
  </div>

  <!-- Closing -->
  <p class="body-text">We congratulate you on your placement and look forward to welcoming you to <strong><?= htmlspecialchars($schoolName) ?></strong>.</p>
  <p class="body-text">Yours faithfully,</p>

  <!-- Signature & Stamp -->
  <div class="sign-section">
    <div class="sign-left">
      <?php if ($sigSrc): ?>
      <img src="<?= $sigSrc ?>" alt="Principal Signature" class="sig-image">
      <?php else: ?>
      <div class="sig-line"></div>
      <?php endif; ?>
      <div class="sig-name"><?= htmlspecialchars($principalNameDisplay) ?></div>
      <div class="sig-title">PRINCIPAL</div>
    </div>
    <div class="sign-stamp" style="text-align:center;">
      <div class="stamp-box">
        <?php if ($stampSrc): ?>
        <img src="<?= $stampSrc ?>" alt="School Stamp">
        <?php else: ?>
        STAMP
        <?php endif; ?>
      </div>
      <div style="font-size:8.5pt;color:#888;margin-top:5px;">School Stamp</div>
    </div>
  </div>

  <!-- Footer -->
  <div class="footer-bar">
    <?= htmlspecialchars($schoolName) ?> | <?= htmlspecialchars($schoolAddr) ?> | Generated: <?= date('d/m/Y H:i') ?>
  </div>
</div>
</body>
</html>
