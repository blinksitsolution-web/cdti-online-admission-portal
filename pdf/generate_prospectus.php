<?php
/**
 * generate_prospectus.php — Items / Equipment Prospectus for student's programme
 * Admin note removed. Now shows dept-specific tools if uploaded, otherwise defaults.
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

$s            = getSettings();
$schoolName   = $s['school_name']    ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$schoolAddr   = $s['school_address'] ?? 'Sanzule, Western Region, Ghana';
$academicYear = $s['academic_year']  ?? '2026/2027';
$logoSrc      = !empty($s['school_logo_path']) ? fileToDataUri($s['school_logo_path']) : '';

// Map programme to dept key
$prog = strtolower($student['program'] ?? '');
$deptKey = match(true) {
    str_contains($prog,'fashion')     => 'fashion',
    str_contains($prog,'building') || str_contains($prog,'construct') => 'building',
    str_contains($prog,'electrical')  => 'electrical',
    str_contains($prog,'computer')    => 'computer',
    str_contains($prog,'home ec') || str_contains($prog,'hospitality') => 'home_economics',
    str_contains($prog,'welding') || str_contains($prog,'fabricat')    => 'welding',
    default => ''
};

// Note: We no longer redirect to the department tools PDF here.
// The department tools PDF is linked directly on the student dashboard.

// Default items per programme
$itemLists = [
    'fashion' => [
        'Tools & Equipment'  => ['Sewing machine','Mannequin/dress form','Tape measure','Scissors (fabric & embroidery)','Seam ripper','Pins and pincushion','Needles (hand & machine)','Iron and ironing board','Chalk/marking pen'],
        'Required Materials' => ['3 metres assorted fabric (plain & patterned)','Thread (assorted colours)','Interlining/lining fabric','Buttons and zippers','Pattern paper'],
        'Uniform & Clothing' => ['2 sets school uniform','1 plain overall/apron','Plain white T-shirt (lab use)','Closed-toe shoes'],
    ],
    'building' => [
        'Tools & Equipment'  => ['Steel trowel','Float (wooden & steel)','Spirit level (600mm)','Brick hammer','Bolster chisel','Line and pins','Measuring tape (5m)','Plumb bob','Square'],
        'Required Materials' => ['Safety helmet','Safety boots','Work gloves','Reflective vest','Overalls (2 sets)'],
        'Uniform & Clothing' => ['2 sets school uniform','Steel-toed work boots','Safety goggles'],
    ],
    'electrical' => [
        'Tools & Equipment'  => ['Digital multimeter','Wire stripper','Combination pliers','Flat & Phillips screwdrivers','Voltage tester','Soldering iron','Electrical tape','Cable crimper'],
        'Required Materials' => ['Electrical safety gloves','Rubber-soled boots','Safety goggles','Overalls (2 sets)'],
        'Uniform & Clothing' => ['2 sets school uniform','Insulated work boots','Hard hat (yellow)'],
    ],
    'computer' => [
        'Tools & Equipment'  => ['Precision screwdriver set','ESD anti-static wrist strap','Crimping tool','Cable tester','USB bootable drive','Network cable kit'],
        'Required Materials' => ['Notebook (technical)','USB flash drive (32GB+)','Ethernet cable (2m)'],
        'Uniform & Clothing' => ['2 sets school uniform','Closed-toe shoes'],
    ],
    'home_economics' => [
        'Tools & Equipment'  => ['Chopping board','Chef knife set','Measuring cups & spoons','Mixing bowls','Frying pan & saucepan set','Apron & oven gloves'],
        'Required Materials' => ['Kitchen textbook (Home Economics 1 & 2)','Recipe notebook','Hygiene kit (hand wash, sanitiser)'],
        'Uniform & Clothing' => ['2 sets school uniform','White apron (2)','Hair net/cap','Closed-toe non-slip shoes'],
    ],
    'welding' => [
        'Tools & Equipment'  => ['Welding helmet/shield','Welding gloves (leather)','Chipping hammer','Wire brush','Angle grinder (personal)','Steel ruler','Centre punch','Hacksaw'],
        'Required Materials' => ['Leather overalls or welding jacket','Safety boots (steel toe)','Ear protection','Safety goggles'],
        'Uniform & Clothing' => ['2 sets school uniform','Steel-toe leather boots','Leather welding apron'],
    ],
];

$items     = $itemLists[$deptKey] ?? [];
$boarder   = isBoarderResidency($student['residency'] ?? '');
$commonItems = [
    'Bedding & Linen (Boarders only)' => $boarder ? ['1 mattress cover','2 bed sheets (white)','1 pillow & pillowcase','1 blanket or duvet','1 mosquito net'] : null,
    'Stationery' => ['Exercise books (12)','Mathematics set','Scientific calculator','Pens, pencils & ruler','Dictionary'],
    'Toiletries'  => ['Toothbrush & toothpaste','Soap & sponge','Deodorant','Sanitary/personal hygiene items'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Prospectus — <?= htmlspecialchars($student['full_name']) ?></title>
  <style>
    @page { size: A4; margin: 0; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:Arial,Helvetica,sans-serif; font-size:10pt; color:#000; background:#fff; }
    .page { width:210mm; min-height:297mm; padding:12mm 18mm; }
    .letterhead { display:flex; align-items:center; border-bottom:3px double #003366; padding-bottom:10px; margin-bottom:10px; }
    .logo { width:70px; height:70px; object-fit:contain; margin-right:15px; border-radius:50%; }
    .logo-placeholder { width:70px;height:70px;background:#e8eef8;border-radius:50%;margin-right:15px;display:flex;align-items:center;justify-content:center;font-size:1.8rem; }
    .school-name { font-size:14pt; font-weight:bold; color:#003366; text-transform:uppercase; }
    .school-sub  { font-size:8.5pt; color:#555; }
    .doc-title { text-align:center; margin:10px 0 12px; background:#003366; color:#fff; padding:7px; border-radius:3px; }
    .doc-title h2 { font-size:12pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; }
    .prog-banner { text-align:center; background:#f0f7f0; border:1px solid #c3e6c3; border-radius:5px; padding:7px; margin-bottom:12px; font-size:10pt; }
    .prog-banner strong { color:#006600; font-size:11pt; }
    .cat-header { background:#003366; color:#fff; padding:5px 10px; font-size:9.5pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.5px; margin:10px 0 6px; border-radius:2px; }
    .items-list { list-style:none; padding:0; margin:0 0 6px; display:grid; grid-template-columns:1fr 1fr; gap:2px 10px; }
    .items-list li { padding:4px 0 4px 14px; font-size:9.5pt; border-bottom:1px solid #f0f0f0; position:relative; }
    .items-list li::before { content:'✓'; position:absolute; left:0; color:#006600; font-weight:bold; }
    .notice-box { background:#fff8dc; border:1px solid #e0a000; border-radius:4px; padding:10px; margin:12px 0; }
    .notice-box p { font-size:9pt; color:#665500; line-height:1.6; }
    .footer-bar { text-align:center; margin-top:16px; border-top:1px solid #ddd; padding-top:6px; font-size:7.5pt; color:#888; }
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
  <div class="letterhead">
    <?php if ($logoSrc): ?>
    <img src="<?= $logoSrc ?>" alt="Logo" class="logo">
    <?php else: ?>
    <div class="logo-placeholder">🎓</div>
    <?php endif; ?>
    <div>
      <div class="school-name"><?= htmlspecialchars($schoolName) ?></div>
      <div class="school-sub"><?= htmlspecialchars($schoolAddr) ?></div>
    </div>
  </div>

  <div class="doc-title">
    <h2>Student Prospectus & Items List — <?= htmlspecialchars($academicYear) ?></h2>
  </div>

  <div class="prog-banner">
    Student: <strong><?= htmlspecialchars($student['full_name']) ?></strong> &nbsp;|&nbsp;
    Programme: <strong><?= htmlspecialchars($student['program']) ?></strong> &nbsp;|&nbsp;
    Residency: <strong><?= htmlspecialchars($student['residency']) ?></strong>
  </div>

  <?php if (!empty($s['school_prospectus_path'])): ?>
    <div class="no-print" style="margin: 15px 0; padding: 15px; background: rgba(0,111,160,0.15); border: 1px solid rgba(0,111,160,0.3); border-radius: 8px; text-align: center; color: #fff;">
      <p style="margin: 0 0 10px 0; font-size: 0.95rem; font-weight: bold; color: #fff;">Official School Prospectus PDF</p>
      <a href="<?= asset($s['school_prospectus_path']) ?>" target="_blank" style="display: inline-block; background: #003366; color: #fff; padding: 8px 20px; border-radius: 6px; text-decoration: none; font-size: 0.9rem; font-weight: bold; border: 1px solid rgba(255,255,255,0.2); transition: background 0.2s;" onmouseover="this.style.background='#004b8d'" onmouseout="this.style.background='#003366'">📥 Download Original PDF Document</a>
    </div>
    <div style="margin-top:20px; width:100%; height:1100px; border:2px solid #003366; border-radius:8px; overflow:hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
      <object data="<?= asset($s['school_prospectus_path']) ?>" type="application/pdf" width="100%" height="100%">
        <iframe src="<?= asset($s['school_prospectus_path']) ?>" width="100%" height="100%" style="border:none;"></iframe>
      </object>
    </div>
  <?php else: ?>
    <?php if (!empty($items)): ?>
      <?php foreach ($items as $cat => $catItems): ?>
      <div class="cat-header">📦 <?= htmlspecialchars($cat) ?></div>
      <ul class="items-list">
        <?php foreach ($catItems as $item): ?>
        <li><?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php foreach ($commonItems as $cat => $catItems): ?>
      <?php if ($catItems === null) continue; ?>
      <div class="cat-header"><?= $cat === 'Bedding & Linen (Boarders only)' ? '🛏' : ($cat === 'Stationery' ? '📝' : '🧴') ?> <?= htmlspecialchars($cat) ?></div>
      <ul class="items-list">
        <?php foreach ($catItems as $item): ?>
        <li><?= htmlspecialchars($item) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="notice-box">
    <p>
      <strong>⚠ Please Note:</strong> All items should be clearly labelled with the student's full name.
      Items listed are the <em>minimum requirements</em>. Do not bring excessive valuables or electronics to school.
      All students must report by <strong><?= htmlspecialchars($s['reporting_date'] ?? '15th September, 2026') ?></strong>.
    </p>
  </div>

  <div class="footer-bar">
    <?= htmlspecialchars($schoolName) ?> | Student Prospectus &amp; Items List | Generated: <?= date('d/m/Y H:i') ?> | Built by Blinks I.T. Solution
  </div>
</div>
</body>
</html>
