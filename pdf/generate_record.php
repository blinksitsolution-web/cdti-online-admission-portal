<?php
/**
 * generate_record.php — Personal Record Form PDF
 * Fixed: uses appPath() instead of DOCUMENT_ROOT
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

$stmt = $pdo->prepare("SELECT s.*, p.*, h.name AS house_name FROM students s
    LEFT JOIN parent_guardian_info p ON p.student_id=s.id
    LEFT JOIN houses h ON h.id=s.house_id
    WHERE s.id=? AND s.registration_status='completed' LIMIT 1");
$stmt->execute([$sid]);
$student = $stmt->fetch();
if (!$student) die('<p style="padding:2rem;font-family:sans-serif;color:red;">⚠ Registration not found or incomplete.</p>');

$s            = getSettings();
$schoolName   = $s['school_name']   ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$schoolAddr   = $s['school_address'] ?? 'Sanzule, Western Region, Ghana';
$academicYear = $s['academic_year'] ?? '2026/2027';

// ── Encode images using appPath() ─────────────────────────────────
$logoSrc       = !empty($s['school_logo_path'])          ? fileToDataUri($s['school_logo_path'])          : '';
$photoSrc      = !empty($student['passport_photo_path'])  ? fileToDataUri($student['passport_photo_path'])  : '';
$letterheadSrc = !empty($s['letterhead_header_path'])     ? fileToDataUri($s['letterhead_header_path'])     : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Personal Record — <?= htmlspecialchars($student['full_name']) ?></title>
  <style>
    @page { size: A4; margin: 0; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial,Helvetica,sans-serif; font-size:10pt; color:#000; background:#fff; }
    .page { width:210mm; min-height:297mm; padding:0mm 18mm 10mm; }
    .letterhead { display:flex; align-items:center; border-bottom:3px double #003366; padding-bottom:10px; margin-bottom:10px; }
    .letterhead.letterhead-image { display:block; padding-bottom:0; margin-bottom:0; border-bottom:none; }
    .letterhead-banner { width:100%; display:block; object-fit:contain; }
    .letterhead .logo { width:70px; height:70px; object-fit:contain; margin-right:15px; border-radius:50%; }
    .letterhead .logo-placeholder { width:70px; height:70px; background:#e8eef8; border-radius:50%; margin-right:15px; display:flex; align-items:center; justify-content:center; font-size:1.8rem; }
    .school-name { font-size:14pt; font-weight:bold; color:#003366; text-transform:uppercase; }
    .school-sub  { font-size:8.5pt; color:#555; }
    .photo-row { text-align:right; margin-top:2px; }
    .doc-title { text-align:center; margin:4px 0 8px; padding-bottom:5px; border-bottom:2px solid #003366; }
    .doc-title h2 { font-size:12pt; font-weight:bold; text-transform:uppercase; letter-spacing:1px; color:#003366; }
    .section-header { padding:5px 8px; font-size:9pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; margin:6px 0 4px; color:#fff; }
    .section-header.blue   { background:#003366; }
    .section-header.green  { background:#2a7a2a; }
    .section-header.orange { background:#8a5a00; }
    .section-header.gray   { background:#555; }
    .info-table { width:100%; border-collapse:collapse; font-size:9.5pt; }
    .info-table th { background:#f0f4ff; padding:4px 8px; text-align:left; font-size:8.5pt; color:#333; border:1px solid #ccd; font-weight:600; }
    .info-table td { padding:4px 8px; border:1px solid #dde; }
    .info-table tr:nth-child(even) td { background:#fafbff; }
    .photo-box { width:30mm; height:38mm; border:1.5px solid #333; display:flex; align-items:center; justify-content:center; background:#f5f5f5; flex-shrink:0; overflow:hidden; margin-left:auto; }
    .photo-box img { width:100%; height:100%; object-fit:cover; }
    .photo-box .no-photo { font-size:8pt; color:#aaa; text-align:center; padding:5px; }
    .declaration-box { border:1px solid #333; padding:8px; margin-top:6px; border-radius:2px; }
    .sig-row { display:flex; justify-content:space-between; margin-top:12px; }
    .sig-col { text-align:center; }
    .sig-line-box { border-top:1px solid #333; width:150px; margin:16px auto 4px; }
    .sig-name { font-size:8.5pt; font-weight:bold; }
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
    <div style="flex:1;">
      <div class="school-name"><?= htmlspecialchars($schoolName) ?></div>
      <div class="school-sub"><?= htmlspecialchars($schoolAddr) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Passport Photo (top-right) -->
  <div class="photo-row">
    <div class="photo-box">
      <?php if ($photoSrc): ?>
      <img src="<?= $photoSrc ?>" alt="Passport Photo">
      <?php else: ?>
      <div class="no-photo">Passport<br>Photo</div>
      <?php endif; ?>
    </div>
    <div style="font-size:7pt;color:#888;margin-top:3px;">Passport Photo</div>
  </div>

  <!-- Title (below photo, underlined) -->
  <div class="doc-title"><h2>Personal Record Form — Academic Year <?= htmlspecialchars($academicYear) ?></h2></div>

  <!-- Section 1: Personal -->
  <div class="section-header blue">Section 1: Personal Information</div>
  <table class="info-table">
    <tr><th style="width:22%;">Full Name</th><td colspan="3"><strong><?= htmlspecialchars($student['full_name']) ?></strong></td></tr>
    <tr>
      <th>Index Number</th><td style="font-family:monospace;"><?= htmlspecialchars($student['index_number']) ?></td>
      <th>Gender</th><td><?= htmlspecialchars($student['gender']) ?></td>
    </tr>
    <tr>
      <th>Date of Birth</th><td><?= $student['date_of_birth'] ? date('jS F, Y', strtotime($student['date_of_birth'])) : '—' ?></td>
      <th>Religion</th><td><?= htmlspecialchars($student['religion'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>Hometown</th><td><?= htmlspecialchars($student['hometown'] ?? '—') ?></td>
      <th>Region</th><td><?= htmlspecialchars($student['region'] ?? '—') ?></td>
    </tr>
    <tr>
      <th>Nationality</th><td><?= htmlspecialchars($student['nationality'] ?? 'Ghanaian') ?></td>
      <th>Enrolment Code</th><td><?= htmlspecialchars($student['enrolment_code'] ?? '—') ?></td>
    </tr>
  </table>

  <!-- Section 2: Placement -->
  <div class="section-header green">Section 2: Placement & Academic Details</div>
  <table class="info-table">
    <tr>
      <th style="width:22%;">Programme</th><td><strong><?= htmlspecialchars($student['program'] ?? '—') ?></strong></td>
      <th style="width:22%;">Residency</th><td><strong><?= htmlspecialchars($student['residency']) ?></strong></td>
    </tr>
    <tr>
      <th>BECE Aggregate</th><td><?= htmlspecialchars($student['aggregate'] ?? '—') ?></td>
      <th>Previous JHS</th><td><?= htmlspecialchars($student['prev_jhs_school'] ?? '—') ?></td>
    </tr>
    <?php if (isBoarderResidency($student['residency'] ?? '')): ?>
    <tr>
      <th>Student House</th><td colspan="3"><strong><?= htmlspecialchars($student['house_name'] ?? '—') ?></strong></td>
    </tr>
    <?php endif; ?>
    <tr>
      <th>Prev. JHS Index</th><td><?= htmlspecialchars($student['prev_jhs_index'] ?? '—') ?></td>
      <th>Academic Year</th><td><?= htmlspecialchars($academicYear) ?></td>
    </tr>
  </table>

  <!-- Section 3: Family -->
  <div class="section-header orange">Section 3: Parent / Guardian Information</div>
  <table class="info-table">
    <thead>
      <tr><th style="width:18%;"></th><th>Father</th><th>Mother</th><th>Guardian</th></tr>
    </thead>
    <tbody>
      <tr><th>Full Name</th><td><?= htmlspecialchars($student['father_name'] ?? '—') ?></td><td><?= htmlspecialchars($student['mother_name'] ?? '—') ?></td><td><?= htmlspecialchars($student['guardian_name'] ?? '—') ?></td></tr>
      <tr><th>Phone</th><td><?= htmlspecialchars($student['father_phone'] ?? '—') ?></td><td><?= htmlspecialchars($student['mother_phone'] ?? '—') ?></td><td><?= htmlspecialchars($student['guardian_phone'] ?? '—') ?></td></tr>
      <tr><th>Occupation</th><td><?= htmlspecialchars($student['father_occupation'] ?? '—') ?></td><td><?= htmlspecialchars($student['mother_occupation'] ?? '—') ?></td><td><?= htmlspecialchars($student['guardian_relationship'] ?? '—') ?></td></tr>
      <tr><th>Address</th><td><?= htmlspecialchars($student['father_address'] ?? '—') ?></td><td><?= htmlspecialchars($student['mother_address'] ?? '—') ?></td><td><?= htmlspecialchars($student['guardian_address'] ?? '—') ?></td></tr>
    </tbody>
  </table>

  <!-- Section 4: Declaration -->
  <div class="section-header gray">Section 4: Student Declaration</div>
  <div class="declaration-box">
    <p style="font-size:9pt;line-height:1.7;color:#333;">
      I, <strong><?= htmlspecialchars($student['full_name']) ?></strong>, hereby declare that all information provided in this Personal Record Form is true, accurate, and correct to the best of my knowledge. I understand that any false declaration may result in the withdrawal of my admission.
    </p>
    <div class="sig-row">
      <div class="sig-col"><div class="sig-line-box"></div><div class="sig-name">Student's Signature</div></div>
      <div class="sig-col"><div class="sig-line-box"></div><div class="sig-name">Date</div></div>
      <div class="sig-col"><div class="sig-line-box"></div><div class="sig-name">Parent / Guardian Signature</div></div>
    </div>
  </div>

  <!-- Footer -->
  <div style="text-align:center;margin-top:14px;font-size:7.5pt;color:#888;border-top:1px solid #ddd;padding-top:6px;">
    <?= htmlspecialchars($schoolName) ?> | Personal Record Form | Generated: <?= date('d/m/Y H:i') ?>
  </div>
</div>
</body>
</html>
