<?php
require_once __DIR__ . '/includes/helpers.php';
startSecureSession();
$s           = getSettings();
$schoolName  = $s['school_name']  ?? 'CHARLOTTE DOLPHYNE TECHNICAL INSTITUTE';
$academicYear = $s['academic_year'] ?? '2026/2027';
$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$helpline    = $s['helpline_number'] ?? '+233 0542947685';

$programmes = [
  ['icon'=>'<i class="fa-solid fa-chart-line" style="color:#4dd8ff;"></i>',             'name'=>'Business Information Technology',           'desc'=>'Manage business processes with modern information systems and technology'],
  ['icon'=>'<i class="fa-solid fa-screwdriver-wrench" style="color:#4dd8ff;"></i>',     'name'=>'Welding & Fabrication Technology',          'desc'=>'Metal joining, structural fabrication and industrial welding technology'],
  ['icon'=>'<i class="fa-solid fa-bolt" style="color:#4dd8ff;"></i>',                  'name'=>'Electrical Engineering Technology',          'desc'=>'Installation, maintenance and repair of electrical systems and technology'],
  ['icon'=>'<i class="fa-solid fa-scissors" style="color:#4dd8ff;"></i>',              'name'=>'Fashion Design Technology',                 'desc'=>'Learn modern garment design, pattern making and textile technology'],
  ['icon'=>'<i class="fa-solid fa-utensils" style="color:#4dd8ff;"></i>',              'name'=>'Hospitality & Catering Management',         'desc'=>'Catering, nutrition, food science and hospitality management'],
  ['icon'=>'<i class="fa-solid fa-trowel-bricks" style="color:#4dd8ff;"></i>',         'name'=>'Building Construction Technology',          'desc'=>'Master bricklaying, tiling, carpentry and structural works technology'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($schoolName) ?> | Online Admission Portal</title>
  <meta name="description" content="<?= htmlspecialchars($schoolName) ?> Online Admission System — <?= $academicYear ?>. CSSPS placement registration.">
  <link rel="icon" href="<?= asset($logoPath) ?>" type="image/png">
  <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.21.0/dist/css/uikit.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <style>
    /* ── Hero ─────────────────────────────────────────────── */
    .hero-section {
      min-height: 100vh;
      background: linear-gradient(135deg, #050e1a 0%, #0a1f35 40%, #06152b 100%);
      position: relative; overflow: hidden;
      display: flex; flex-direction: column;
    }
    .hero-section::before {
      content:''; position:absolute; inset:0;
      background: url('<?= asset('assets/img/bg.jpg') ?>') center/cover no-repeat;
      opacity: 0.08;
    }
    /* Animated grid lines */
    .hero-section::after {
      content:''; position:absolute; inset:0;
      background-image: linear-gradient(rgba(0,111,160,0.06) 1px, transparent 1px),
                        linear-gradient(90deg, rgba(0,111,160,0.06) 1px, transparent 1px);
      background-size: 50px 50px;
      animation: gridMove 20s linear infinite;
    }
    @keyframes gridMove { from{background-position:0 0} to{background-position:50px 50px} }

    /* ── Navbar ─────────────────────────────────────────── */
    .cdti-nav {
      position: relative; z-index: 10;
      padding: 1rem 2rem;
      display: flex; align-items: center; justify-content: space-between;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .cdti-nav .brand { display:flex; align-items:center; gap:0.75rem; text-decoration:none; }
    .cdti-nav .brand .logo-ring img { width: 44px; height: 44px; }
    .cdti-nav .brand-text .name { color:#fff; font-weight:800; font-size:0.92rem; text-transform:uppercase; letter-spacing:0.5px; line-height:1.2; }
    .cdti-nav .brand-text .motto { color:rgba(255,255,255,0.45); font-size:0.7rem; letter-spacing:1px; }
    .cdti-nav .nav-links { display:flex; gap:1.5rem; align-items:center; }
    .cdti-nav .nav-links a { color:rgba(255,255,255,0.7); font-size:0.85rem; text-decoration:none; transition:color 0.2s; font-weight:500; }
    .cdti-nav .nav-links a:hover { color:#fff; }
    .cdti-nav .btn-admission {
      background: linear-gradient(135deg, #006fa0, #0097d6);
      color:#fff; padding:0.55rem 1.4rem; border-radius:30px;
      font-weight:700; font-size:0.85rem; text-decoration:none;
      transition:all 0.2s; box-shadow:0 4px 15px rgba(0,111,160,0.4);
    }
    .cdti-nav .btn-admission:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,111,160,0.6); color:#fff; }

    /* ── Hero Content ────────────────────────────────────── */
    .hero-content {
      flex:1; position:relative; z-index:5;
      display:flex; flex-direction:column; align-items:center; justify-content:center;
      text-align:center; padding: 3rem 1.5rem 2rem;
    }
    .hero-badge {
      display:inline-block; background:rgba(0,111,160,0.2); border:1px solid rgba(0,111,160,0.4);
      color:#4dd8ff; padding:0.35rem 1rem; border-radius:30px; font-size:0.75rem;
      font-weight:600; text-transform:uppercase; letter-spacing:1.5px; margin-bottom:1.5rem;
      animation: growIn 0.9s cubic-bezier(0.34, 1.56, 0.64, 1) forwards, growPulse 2.5s ease-in-out 1s infinite;
      transform-origin: center center;
    }
    .hero-logo-wrap {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 14px;
      border-radius: 50%;
      background: rgba(3, 8, 16, 0.85);
      border: 3px solid rgba(0, 150, 200, 0.5);
      box-shadow: 0 0 45px rgba(0, 150, 200, 0.35);
      margin-bottom: 1.5rem;
      overflow: visible;
      animation: fadeInDown 0.5s ease;
    }
    .hero-logo {
      width: 110px; height: 110px; object-fit: contain;
      border-radius: 0;
      display: block;
    }
    .hero-title {
      font-size: clamp(1.6rem, 4vw, 2.8rem);
      font-weight: 900; color: #fff;
      line-height: 1.15; margin-bottom: 0.5rem;
      text-shadow: 0 2px 20px rgba(0,0,0,0.5);
      animation: fadeInUp 0.7s ease;
    }
    .hero-title span { color: #4dd8ff; }
    .hero-subtitle {
      color: rgba(255,255,255,0.6); font-size:1rem; margin-bottom: 0.75rem;
      letter-spacing: 2px; text-transform: uppercase; font-weight:500;
      animation: fadeInUp 0.8s ease;
    }
    .hero-year {
      display:inline-block; background:rgba(255,200,0,0.15); border:1px solid rgba(255,200,0,0.3);
      color:#ffd700; padding:0.4rem 1.2rem; border-radius:30px; font-size:0.88rem;
      font-weight:700; margin-bottom:2.5rem;
      animation: fadeInUp 0.9s ease;
    }
    .hero-cta {
      display: flex; gap:1rem; justify-content:center; flex-wrap:wrap; margin-bottom:3rem;
      animation: fadeInUp 1s ease;
    }
    .btn-start {
      background: linear-gradient(135deg, #006fa0, #0097d6);
      color:#fff; padding:1rem 2.5rem; border-radius:50px;
      font-weight:800; font-size:1.05rem; text-decoration:none;
      transition:all 0.3s; box-shadow:0 8px 25px rgba(0,111,160,0.5);
      display:inline-flex; align-items:center; gap:0.5rem;
    }
    .btn-start:hover { transform:translateY(-3px); box-shadow:0 12px 35px rgba(0,111,160,0.7); color:#fff; }
    .btn-how {
      background: rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.2);
      color:#fff; padding:1rem 2rem; border-radius:50px;
      font-weight:600; font-size:0.95rem; text-decoration:none;
      transition:all 0.3s;
    }
    .btn-how:hover { background: rgba(255,255,255,0.15); color:#fff; }

    /* ── Stats Bar ───────────────────────────────────────── */
    .stats-bar {
      position:relative; z-index:5;
      background: rgba(255,255,255,0.04); border-top:1px solid rgba(255,255,255,0.06);
      padding:1.25rem 2rem;
      display:flex; justify-content:center; gap:3rem; flex-wrap:wrap;
    }
    .stat-item { text-align:center; }
    .stat-item .num { font-size:1.7rem; font-weight:900; color:#4dd8ff; line-height:1; }
    .stat-item .lbl { font-size:0.72rem; color:rgba(255,255,255,0.5); text-transform:uppercase; letter-spacing:1px; margin-top:3px; }

    /* ── Section: Programmes ─────────────────────────────── */
    .section-programmes { background:linear-gradient(180deg,#06152b 0%,#080f18 100%); padding:5rem 0; }
    .section-title { text-align:center; margin-bottom:3rem; }
    .section-title h2 { font-size:2rem; font-weight:900; color:#fff; margin-bottom:0.5rem; }
    .section-title h2 span { color:#4dd8ff; }
    .section-title p { color:rgba(255,255,255,0.5); font-size:0.95rem; max-width:500px; margin:0 auto; }
    .section-title .divider { width:60px; height:4px; background:linear-gradient(90deg,#006fa0,#0097d6); border-radius:2px; margin:1rem auto; }

    .prog-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:1.25rem; }
    .prog-card {
      background:rgba(13,27,42,0.8); border:1px solid rgba(0,111,160,0.2);
      border-radius:16px; padding:2rem;
      transition:all 0.3s; border-top:3px solid transparent; cursor:default;
      backdrop-filter:blur(4px);
    }
    .prog-card:hover { transform:translateY(-6px); border-color:rgba(0,111,160,0.5); border-top-color:#0097d6; box-shadow:0 12px 35px rgba(0,111,160,0.2); }
    .prog-card .prog-icon { font-size:2.5rem; margin-bottom:1rem; display:block; }
    .prog-card h3 { font-size:1rem; font-weight:800; color:#fff; margin-bottom:0.5rem; }
    .prog-card p { color:rgba(255,255,255,0.5); font-size:0.85rem; line-height:1.6; margin:0; }

    /* ── Section: How to Apply ───────────────────────────────── */
    .section-how { background:linear-gradient(180deg,#080f18 0%,#06152b 100%); padding:5rem 0; }
    .steps-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:2rem; text-align:center; }
    .step-box { position:relative; }
    .step-box .step-num {
      width:64px; height:64px; border-radius:50%;
      background:linear-gradient(135deg,#006fa0,#0097d6);
      color:#fff; font-size:1.4rem; font-weight:900;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 1rem; box-shadow:0 8px 24px rgba(0,111,160,0.4);
    }
    .step-box h4 { font-size:0.95rem; font-weight:700; color:#fff; margin-bottom:0.4rem; }
    .step-box p { font-size:0.82rem; color:rgba(255,255,255,0.5); line-height:1.6; margin:0; }

    /* ── Section: Notice ─────────────────────────────────── */
    .section-notice { background:linear-gradient(180deg,#06152b 0%,#050e1a 100%); padding:4rem 0; border-top:1px solid rgba(0,111,160,0.12); }
    .notice-card { background:rgba(255,255,255,0.03); border:1px solid rgba(0,111,160,0.25); border-radius:16px; padding:2rem; backdrop-filter:blur(4px); }
    .notice-card h3 { color:#ffd700; font-weight:800; margin-bottom:1.25rem; font-size:1.1rem; }
    .notice-card ul li { color:rgba(255,255,255,0.72); font-size:0.88rem; margin-bottom:0.55rem; line-height:1.65; }

    /* ── Footer ──────────────────────────────────────────── */
    .site-footer { background:#030810; padding:2rem; text-align:center; border-top:1px solid rgba(255,255,255,0.05); }
    .site-footer p { color:rgba(255,255,255,0.35); font-size:0.8rem; margin:0; }
    .site-footer a { color:#4dd8ff; text-decoration:none; }

    /* ── How to Apply inline span override ──────────────── */
    .section-how .section-title h2 span { color:#4dd8ff; }

    /* ── Modal ───────────────────────────────────────────── */
    .modal-content { background:#0f1e2d; color:#fff; border:1px solid rgba(0,111,160,0.4); border-radius:16px; }
    .modal-header { border-bottom:1px solid rgba(255,255,255,0.1); }
    .modal-footer { border-top:1px solid rgba(255,255,255,0.1); }
    .modal-title { color:#fff; }
    .modal-body p { color:#ccc; line-height:1.7; margin-bottom:0.6rem; font-size:0.9rem; }
    .modal-body p strong u { color:#4dd8ff; }
    .agree-cb { display:flex; align-items:center; gap:0.5rem; margin-top:1rem; }
    .agree-cb input { width:18px; height:18px; accent-color:#006fa0; cursor:pointer; }
    .agree-cb label { color:#ccc; font-size:0.88rem; cursor:pointer; margin:0; }

    /* ── Animations ──────────────────────────────────────── */
    @keyframes growIn {
      0%   { opacity:0; transform:scale(0.4); }
      70%  { transform:scale(1.08); }
      100% { opacity:1; transform:scale(1); }
    }
    @keyframes growPulse {
      0%, 100% { transform:scale(1); }
      50%      { transform:scale(1.06); }
    }
    @keyframes fadeInDown { from{opacity:0;transform:translateY(-20px)} to{opacity:1;transform:none} }
    @keyframes fadeInUp   { from{opacity:0;transform:translateY(20px)}  to{opacity:1;transform:none} }

    @media(max-width:600px) {
      .cdti-nav { padding:0.75rem 1rem; }
      .cdti-nav .nav-links { display:none; }
      .stats-bar { gap:1.5rem; }
    }
  </style>
</head>
<body style="margin:0;padding:0;font-family:'Inter',sans-serif;">
<a href="#main-content" class="skip-nav" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;z-index:9999;background:#006fa0;color:#fff;padding:0.5rem 1rem;border-radius:0 0 4px 0;font-weight:700;">&nbsp;Skip to main content</a>

<!-- ═══════════════════════════════════════════════════════════
     MUST READ MODAL
════════════════════════════════════════════════════════════════ -->
<div id="mustReadModal" class="modal fade" data-backdrop="static" data-keyboard="false" role="dialog">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header text-center d-block pt-4">
        <img src="<?= asset($logoPath) ?>" alt="Logo" style="width:60px;height:60px;object-fit:contain;border-radius:50%;border:2px solid #006fa0;display:block;margin:0 auto 0.75rem;">
        <h4 class="modal-title" style="font-size:1.05rem;font-weight:800;">
          HOW TO REGISTER<br><small style="color:#e63946;font-weight:700;"><i class="fa-solid fa-triangle-exclamation"></i> MUST READ BEFORE PROCEEDING</small>
        </h4>
      </div>
      <div class="modal-body px-4">
        <p>1. Click <strong><u>Start Admission</u></strong> and enter your <strong><u>12-digit CSSPS Index Number</u></strong>.</p>
        <p>2. Pay the <strong><u>admission processing fee</u></strong> using your debit/credit card via Paystack.</p>
        <p>3. Fill in the <strong><u>5-step registration form</u></strong> with your correct bio-data.</p>
        <p>4. Upload a clear <strong><u>passport-size photograph</u></strong>.</p>
        <p>5. Download your <strong><u>Admission Letter, Personal Record Form, Prospectus & Bond Form</u></strong>.</p>
        <p>6. If your index number is not found, use the <strong style="color:#4dd8ff;"><u>🔍 Search by Name</u></strong> option.</p>
        <div class="agree-cb">
          <input type="checkbox" id="agreeCheck">
          <label for="agreeCheck">I have read and understood the instructions above.</label>
        </div>
      </div>
      <div class="modal-footer px-4 pb-4">
        <button type="button" id="agreeBtn" class="btn btn-primary btn-block" style="border-radius:30px;font-weight:700;padding:0.75rem;">
          ✅ I Understand — Proceed
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     HERO SECTION
════════════════════════════════════════════════════════════════ -->
<section class="hero-section">

  <!-- Navbar -->
  <nav class="cdti-nav">
    <a href="<?= BASE_URL ?>/index" class="brand">
      <span class="logo-ring"><img src="<?= asset($logoPath) ?>" alt="<?= htmlspecialchars($schoolName) ?>"></span>
      <div class="brand-text">
        <div class="name"><?= htmlspecialchars($schoolName) ?></div>
        <div class="motto">Knowledge &amp; Skills — Sanzule</div>
      </div>
    </a>
    <div class="nav-links">
      <a href="<?= BASE_URL ?>/index">Home</a>
      <a href="#programmes">Programmes</a>
      <a href="#how-to-apply">How to Apply</a>
      <a href="<?= BASE_URL ?>/admissions" class="btn-admission"><i class="fa-solid fa-graduation-cap"></i> Start Admission</a>
    </div>
  </nav>

  <!-- Hero Content -->
  <div class="hero-content" id="main-content">
    <div class="hero-badge grow-text"><i class="fa-solid fa-clipboard-list"></i> <?= htmlspecialchars($academicYear) ?> CSSPS Admission — Now Open</div>
    <div class="hero-logo-wrap">
      <img src="<?= asset($logoPath) ?>" alt="School Logo" class="hero-logo">
    </div>
    <h1 class="hero-title"><?= htmlspecialchars($schoolName) ?></h1>
    <p class="hero-subtitle">Online Admission Registration Portal</p>
    <div class="hero-year">🗓 Academic Year: <?= htmlspecialchars($academicYear) ?></div>

    <div class="hero-cta">
      <a href="<?= BASE_URL ?>/admissions" class="btn-start"><i class="fa-solid fa-graduation-cap"></i> Begin Admission Process <i class="fa-solid fa-arrow-right"></i></a>
      <a href="#how-to-apply" class="btn-how"><i class="fa-solid fa-circle-question"></i> How It Works</a>
    </div>

    <!-- Helpline -->
    <div style="color:rgba(255,255,255,0.45);font-size:0.82rem;letter-spacing:0.5px;">
      📞 Helpline: <a href="tel:<?= htmlspecialchars($helpline) ?>" style="color:#4dd8ff;text-decoration:none;font-weight:600;"><?= htmlspecialchars($helpline) ?></a>
    </div>
  </div>

  <!-- Stats Bar -->
  <div class="stats-bar">
    <div class="stat-item"><div class="num">6</div><div class="lbl">Programmes</div></div>
    <div class="stat-item"><div class="num">2003</div><div class="lbl">Established</div></div>
    <div class="stat-item"><div class="num">100%</div><div class="lbl">CSSPS Certified</div></div>
    <div class="stat-item"><div class="num">24/7</div><div class="lbl">Online Portal</div></div>
  </div>

</section>

<!-- ═══════════════════════════════════════════════════════════
     PROGRAMMES
════════════════════════════════════════════════════════════════ -->
<section id="programmes" class="section-programmes">
  <div class="container">
    <div class="section-title">
      <h2>Our <span>Programmes</span></h2>
      <div class="divider"></div>
      <p>Hands-on technical and vocational programmes designed for industry-ready graduates.</p>
    </div>
    <div class="prog-grid">
      <?php foreach ($programmes as $p): ?>
      <div class="prog-card">
        <span class="prog-icon"><?= $p['icon'] ?></span>
        <h3><?= htmlspecialchars($p['name']) ?></h3>
        <p><?= htmlspecialchars($p['desc']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     HOW TO APPLY
════════════════════════════════════════════════════════════════ -->
<section id="how-to-apply" class="section-how">
  <div class="container">
    <div class="section-title">
      <h2>How to <span style="color:#006fa0;">Apply</span></h2>
      <div class="divider"></div>
      <p>Complete your CSSPS admission in 5 simple steps — no paperwork needed online.</p>
    </div>
    <div class="steps-grid">
      <div class="step-box">
        <div class="step-num">1</div>
        <h4>Check Placement</h4>
        <p>Print your CSSPS placement form. Your 12-digit index number is on it.</p>
      </div>
      <div class="step-box">
        <div class="step-num">2</div>
        <h4>Enter Index Number</h4>
        <p>Go to Admissions and enter your index number to verify your placement.</p>
      </div>
      <div class="step-box">
        <div class="step-num">3</div>
        <h4>Pay Processing Fee</h4>
        <p>Pay the admission processing fee securely via Paystack card payment.</p>
      </div>
      <div class="step-box">
        <div class="step-num">4</div>
        <h4>Complete Registration</h4>
        <p>Fill the 5-step form: bio-data, family info, photo upload, and review.</p>
      </div>
      <div class="step-box">
        <div class="step-num">5</div>
        <h4>Download Documents</h4>
        <p>Download your Admission Letter, Personal Record, Prospectus and Bond Form.</p>
      </div>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     NOTICE SECTION
════════════════════════════════════════════════════════════════ -->
<section class="section-notice">
  <div class="container">
    <div class="row">
      <div class="col-md-6 mb-4">
        <div class="notice-card">
          <h3><i class="fa-solid fa-triangle-exclamation" style="color:#ffd700;"></i> Very Important Notice</h3>
          <ul style="padding-left:1.25rem;">
            <li>Ensure you have your printed <strong>CSSPS Placement Form</strong> available.</li>
            <li>Your <strong>Enrolment Code</strong> is printed on your placement form — it is required.</li>
            <li>Admission is <strong>INCOMPLETE</strong> without your Enrolment Code.</li>
            <li>Admission processing fee must be paid <strong>before</strong> registration form is shown.</li>
          </ul>
        </div>
      </div>
      <div class="col-md-6 mb-4">
        <div class="notice-card">
          <h3><i class="fa-solid fa-file-import"></i> Documents to Bring on Reporting Day</h3>
          <ul style="padding-left:1.25rem;">
            <li>Placement form (1 original + 1 photocopy)</li>
            <li>Admission Letter (2 printed copies)</li>
            <li>Personal Record Form (printed &amp; signed)</li>
            <li>Bond Form (signed by parent/guardian)</li>
            <li>Birth Certificate / Ghana Card</li>
            <li>4 passport-size photographs</li>
          </ul>
        </div>
      </div>
    </div>
    <div class="text-center mt-3">
      <a href="<?= BASE_URL ?>/admissions" class="btn-start" style="text-decoration:none;">
        <i class="fa-solid fa-graduation-cap"></i> Start Your Admission Now <i class="fa-solid fa-arrow-right"></i>
      </a>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════════════════════════
     FOOTER
════════════════════════════════════════════════════════════════ -->
<footer class="site-footer">
  <p>
    © <?= date('Y') ?> <strong style="color:rgba(255,255,255,0.7);"><?= htmlspecialchars($schoolName) ?></strong> — Sanzule, Western Region, Ghana<br>
    <small>Built by <a href="#" style="color:#4dd8ff;">Blinks I.T. Solution</a> &nbsp;|&nbsp; Helpline: <a href="tel:<?= htmlspecialchars($helpline) ?>"><?= htmlspecialchars($helpline) ?></a></small>
  </p>
</footer>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function() {
  $('#mustReadModal').modal('show');
  $('#agreeBtn').on('click', function() {
    if (!$('#agreeCheck').is(':checked')) {
      $(this).text('⚠ Please tick the checkbox first!').addClass('btn-danger').removeClass('btn-primary');
      setTimeout(() => { $(this).text('✅ I Understand — Proceed').removeClass('btn-danger').addClass('btn-primary'); }, 1500);
      return;
    }
    $('#mustReadModal').modal('hide');
  });
  // Smooth scroll for anchor links
  $('a[href^="#"]').on('click', function(e) {
    var target = $(this.hash);
    if (target.length) {
      e.preventDefault();
      $('html,body').animate({ scrollTop: target.offset().top - 80 }, 700);
    }
  });
});
</script>
</body>
</html>
