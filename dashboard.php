<?php
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
requireStudentAuth();
emitCspHeader();
header('X-Robots-Tag: noindex, nofollow');

$pdo  = getDB();
$sid  = (int) $_SESSION['student_id'];
$stmt = $pdo->prepare("SELECT s.*, p.* FROM students s LEFT JOIN parent_guardian_info p ON p.student_id=s.id WHERE s.id=?");
$stmt->execute([$sid]);
$student = $stmt->fetch();

if (!$student || $student['registration_status'] !== 'completed') {
    redirect(BASE_URL . '/register');
}

$s           = getSettings();
$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$reportDate  = $s['reporting_date'] ?? 'As announced by the school';
$schoolName  = $s['school_name'] ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$photoSrc    = !empty($student['passport_photo_path']) ? asset($student['passport_photo_path']) : '';

// Determine dept tools PDF for student's programme
$prog = strtolower($student['program'] ?? '');
$deptKey = match(true) {
    str_contains($prog,'fashion')                                         => 'fashion',
    str_contains($prog,'building') || str_contains($prog,'construct')     => 'building',
    str_contains($prog,'electrical')                                      => 'electrical',
    str_contains($prog,'computer')                                        => 'computer',
    str_contains($prog,'home ec') || str_contains($prog,'hospitality')    => 'home_economics',
    str_contains($prog,'welding') || str_contains($prog,'fabricat')       => 'welding',
    default => ''
};
$deptToolsPdf = !empty($deptKey) ? ($s["dept_tools_{$deptKey}_path"] ?? '') : '';
$deptToolsLabels = [
    'fashion'        => ['<i class="fa-solid fa-scissors"></i>','Fashion Design','Tools & Equipment List'],
    'building'       => ['<i class="fa-solid fa-trowel-bricks"></i>','Building & Constr.','Tools & Equipment List'],
    'electrical'     => ['<i class="fa-solid fa-bolt"></i>','Electrical Eng.','Tools & Equipment List'],
    'computer'       => ['<i class="fa-solid fa-laptop-code"></i>','Computer Hardware','Tools & Equipment List'],
    'home_economics' => ['<i class="fa-solid fa-utensils"></i>','Home Economics','Tools & Equipment List'],
    'welding'        => ['<i class="fa-solid fa-screwdriver-wrench"></i>','Welding & Fab.','Tools & Equipment List'],
];
$deptLabel = $deptToolsLabels[$deptKey] ?? ['<i class="fa-solid fa-box-open"></i>','Dept. Tools','Tools & Equipment List'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | <?= htmlspecialchars($student['full_name']) ?></title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/css/uikit.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    .success-banner { background:linear-gradient(135deg,rgba(45,198,83,0.15),rgba(0,111,160,0.15));border:1px solid #2dc653;border-radius:14px;padding:1.5rem;text-align:center;margin-bottom:1.75rem; }
    .doc-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(155px,1fr)); gap:1rem; margin-bottom:1.5rem; }
    .download-card { display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1.5rem 1rem;
      background:rgba(10,25,45,0.7);border:1px solid rgba(0,111,160,0.35);border-radius:14px;text-decoration:none;
      transition:all 0.25s;text-align:center;cursor:pointer;min-height:130px; }
    .download-card:hover { transform:translateY(-4px);border-color:#0097d6;box-shadow:0 8px 25px rgba(0,111,160,0.35);text-decoration:none; }
    .download-card .icon { font-size:2.2rem;margin-bottom:0.6rem;display:block; }
    .download-card .title { color:#fff;font-weight:700;font-size:0.88rem;margin-bottom:0.2rem;line-height:1.3; }
    .download-card .subtitle { color:rgba(255,255,255,0.45);font-size:0.75rem;line-height:1.4; }
    .download-card.bond { border-color:rgba(244,162,97,0.5); }
    .download-card.bond:hover { border-color:#f4a261;box-shadow:0 8px 25px rgba(244,162,97,0.25); }
    .doc-checklist { background:rgba(15,25,35,0.88);border:1px solid rgba(0,111,160,0.3);border-radius:12px;padding:1.25rem;margin-top:1.25rem; }
    .doc-checklist li { color:#ccc;padding:0.3rem 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:0.88rem; }
    .student-info-box { display:flex;gap:1.25rem;align-items:flex-start;background:rgba(10,25,45,0.7);
      border:1px solid rgba(0,111,160,0.25);border-radius:14px;padding:1.25rem;margin-bottom:1.5rem; }
    .student-photo { width:80px;height:80px;border-radius:8px;object-fit:cover;border:2px solid rgba(0,111,160,0.5);flex-shrink:0; }
    .student-details h3 { color:#fff;font-weight:800;font-size:1.05rem;margin:0 0 0.4rem; }
    .student-details p { color:#aaa;font-size:0.85rem;margin:0.2rem 0; }
    .payment-badge { display:inline-block;background:rgba(45,198,83,0.15);border:1px solid #2dc653;color:#2dc653;
      padding:2px 10px;border-radius:20px;font-size:0.75rem;font-weight:700;margin-top:4px; }
  </style>
  <script>function disableBack(){window.history.forward();}setTimeout(disableBack,0);window.onunload=function(){null;};</script>
</head>
<body>

<!-- Navbar -->
<div data-uk-sticky="sel-target: .uk-navbar-container; cls-active: uk-navbar-sticky">
  <nav class="uk-navbar-container uk-light" style="background:rgba(5,14,26,0.97);backdrop-filter:blur(10px);">
    <div class="uk-container">
      <div data-uk-navbar>
        <div class="uk-navbar-left">
          <img src="<?= asset($logoPath) ?>" width="45" height="45" alt="Logo" style="border-radius:50%;border:2px solid rgba(0,150,200,0.4);object-fit:contain;">
          <a class="uk-navbar-item uk-logo" href="<?= BASE_URL ?>/index" style="font-weight:700;font-size:0.92rem;color:#fff;"><?= htmlspecialchars($schoolName) ?></a>
        </div>
        <div class="uk-navbar-right">
          <a href="<?= BASE_URL ?>/logout" class="uk-button uk-button-default uk-button-small"
             style="border-radius:20px;color:#fff;border-color:rgba(255,255,255,0.3);">Logout</a>
        </div>
      </div>
    </div>
  </nav>
</div>

<section style="margin-top:80px;padding:1.5rem 0 80px;position:relative;z-index:1;">
  <div class="uk-container" style="max-width:720px;">

    <!-- Success Banner -->
    <div class="success-banner">
      <span style="font-size:2.8rem;display:block;"><i class="fa-solid fa-circle-check" style="color:#2dc653;"></i></span>
      <h3 style="color:#2dc653;font-weight:900;margin:0.5rem 0;">Registration Successful!</h3>
      <p style="color:#ccc;font-size:0.9rem;margin:0;">Your admission registration has been completed. Download all your documents below.</p>
    </div>

    <!-- Student Info -->
    <div class="student-info-box">
      <?php if ($photoSrc): ?>
      <img src="<?= $photoSrc ?>" alt="Passport" class="student-photo">
      <?php else: ?>
      <div class="student-photo" style="background:rgba(0,111,160,0.3);display:flex;align-items:center;justify-content:center;font-size:2.2rem;color:var(--text-secondary);"><i class="fa-solid fa-user"></i></div>
      <?php endif; ?>
      <div class="student-details">
        <h3><?= htmlspecialchars($student['full_name']) ?></h3>
        <p><i class="fa-solid fa-id-card"></i> Index: <strong style="color:#fff;"><?= htmlspecialchars($student['index_number']) ?></strong></p>
        <p><i class="fa-solid fa-graduation-cap"></i> Programme: <strong style="color:#fff;"><?= htmlspecialchars($student['program']) ?></strong></p>
        <p><i class="fa-solid fa-house-user"></i> Residency: <strong style="color:#fff;"><?= htmlspecialchars($student['residency']) ?></strong></p>
        <p><i class="fa-solid fa-calendar-check"></i> Registered: <strong style="color:#2dc653;"><?= date('d M Y, H:i', strtotime($student['registered_at'] ?? 'now')) ?></strong></p>
        <?php if ($student['payment_status'] === 'paid'): ?>
        <div class="payment-badge"><i class="fa-solid fa-credit-card"></i> Fee Paid — Ref: <?= htmlspecialchars($student['payment_reference'] ?? '—') ?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Documents -->
    <h4 style="color:#fff;font-weight:700;text-transform:uppercase;letter-spacing:1px;margin-bottom:1rem;">
      <i class="fa-solid fa-download"></i> Download Your Documents
    </h4>
    <div class="doc-grid">
      <a href="<?= BASE_URL ?>/pdf/generate_letter" target="_blank" class="download-card">
        <span class="icon"><i class="fa-solid fa-file-signature"></i></span>
        <div class="title">Admission Letter</div>
        <div class="subtitle">Your official offer of admission</div>
      </a>
      <a href="<?= BASE_URL ?>/pdf/generate_record" target="_blank" class="download-card">
        <span class="icon"><i class="fa-solid fa-file-invoice"></i></span>
        <div class="title">Personal Record</div>
        <div class="subtitle">Full registration dossier</div>
      </a>
      <a href="<?= BASE_URL ?>/pdf/generate_prospectus" target="_blank" class="download-card">
        <span class="icon"><i class="fa-solid fa-box-open"></i></span>
        <div class="title">Prospectus / Items</div>
        <div class="subtitle"><?= $student['residency'] === 'Boarder' ? 'Boarding items list' : 'Day student items list' ?></div>
      </a>
      <a href="<?= BASE_URL ?>/pdf/generate_bond" target="_blank" class="download-card bond">
        <span class="icon"><i class="fa-solid fa-file-contract"></i></span>
        <div class="title">Bond Form</div>
        <div class="subtitle">Parent/guardian undertaking — print &amp; sign</div>
      </a>
      <?php if (!empty($deptToolsPdf)): ?>
      <a href="<?= asset($deptToolsPdf) ?>" target="_blank" class="download-card" style="border-color:rgba(255,200,50,0.4);">
        <span class="icon"><?= $deptLabel[0] ?></span>
        <div class="title"><?= $deptLabel[1] ?></div>
        <div class="subtitle"><?= $deptLabel[2] ?></div>
      </a>
      <?php else: ?>
      <a href="<?= BASE_URL ?>/pdf/generate_prospectus" target="_blank" class="download-card" style="border-color:rgba(255,200,50,0.4);">
        <span class="icon"><?= $deptLabel[0] ?></span>
        <div class="title"><?= $deptLabel[1] ?></div>
        <div class="subtitle">Items &amp; equipment list</div>
      </a>
      <?php endif; ?>
    </div>

    <!-- Checklist -->
    <div class="doc-checklist">
      <h5 style="color:#f4a261;text-transform:uppercase;font-weight:700;font-size:0.88rem;margin-bottom:1rem;">
        <i class="fa-solid fa-clipboard-list"></i> Documents to Bring on Reporting Day
      </h5>
      <ul style="list-style:none;padding:0;margin:0;">
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Placement form (1 original + 1 photocopy)</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Admission Letter (2 printed copies)</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Personal Record form (printed &amp; signed)</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Bond Form (signed by parent/guardian)</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Birth Certificate / Baptismal Certificate / Ghana Card</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> Health Insurance Card (Must be Active) </li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> 2 Passport-size photographs</li>
        <li><i class="fa-solid fa-check" style="color:#2dc653;margin-right:6px;"></i> All required items from Prospectus list</li>
      </ul>
    </div>

    <!-- Reporting Date -->
    <div style="background:rgba(244,162,97,0.1);border:1px solid #f4a261;border-radius:12px;padding:1rem;margin-top:1.25rem;text-align:center;">
      <p style="color:#f4a261;font-weight:700;margin:0;font-size:0.95rem;"><i class="fa-solid fa-calendar-days"></i> Reporting Date: <?= htmlspecialchars($reportDate) ?></p>
      <p style="color:rgba(255,255,255,0.45);font-size:0.78rem;margin:0.25rem 0 0;">Please arrive on or before this date with all required documents.</p>
    </div>

  </div>
</section>

<footer style="position:relative;z-index:1;border-top:1px solid rgba(255,255,255,0.06);padding:1.25rem;text-align:center;">
  <p style="color:rgba(255,255,255,0.3);font-size:0.78rem;margin:0;">
    &copy; <?= date('Y') ?> <?= htmlspecialchars($schoolName) ?> &mdash; All Rights Reserved.<br>
    <small>Built by <a href="https://jamesackahblay-portfolio.blinksitsolution.com/" target="_blankstyle="color:#4dd8ff;text-decoration:none;">Blinks I.T. Solution</a></small>
  </p>
</footer>

<script src="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/js/uikit.min.js"></script>
</body>
</html>
