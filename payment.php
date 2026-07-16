<?php
/**
 * payment.php — Paystack admission fee payment page
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db.php';
emitCspHeader(true); // Paystack Inline JS injects its own <script> elements — see helpers.php
requireStudentAuth();

$pdo = getDB();
$sid = (int) $_SESSION['student_id'];
$s   = getSettings();

$stmt = $pdo->prepare("SELECT * FROM students WHERE id=? LIMIT 1");
$stmt->execute([$sid]);
$student = $stmt->fetch();

if (!$student) { session_destroy(); redirect(BASE_URL . '/admissions'); }

// Already paid or waived → go forward
if (in_array($student['payment_status'], ['paid','waived'])) {
    if ($student['registration_status'] === 'completed') redirect(BASE_URL . '/dashboard');
    redirect(BASE_URL . '/register');
}

// Already completed registration
if ($student['registration_status'] === 'completed') redirect(BASE_URL . '/dashboard');

$paystackPub  = getCredential('PAYSTACK_PUBLIC_KEY', 'paystack_public_key')['value'];
$admissionFee = (float)($s['admission_fee'] ?? 50);
$feeKobo      = (int)($admissionFee * 100);
$schoolName   = $s['school_name'] ?? 'CDTI';
$academicYear = $s['academic_year'] ?? '2026/2027';
$logoPath     = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';

// ── CRITICAL: only generate reference ONCE per session per student ──
// If we regenerate every page load, Paystack returns the OLD ref
// but session already has a NEW ref → verification fails → loop
$sessionKey = 'pay_ref_' . $sid;
if (empty($_SESSION[$sessionKey])) {
    // Collision-resistant reference (avoid md5 truncation collisions)
    $_SESSION[$sessionKey] = 'CDTI-' . strtoupper(bin2hex(random_bytes(10))); // 20 hex chars
}
$payRef = $_SESSION[$sessionKey];


// Also store for verify page
$_SESSION['pay_student_id'] = $sid;
$_SESSION['pay_amount']     = $admissionFee;

// Pre-save reference to DB so webhook can match it even if user never returns
$pdo->prepare("
    UPDATE students SET payment_reference = ?
    WHERE id = ? AND payment_status NOT IN ('paid','waived')
")->execute([$payRef, $sid]);

// NOTE: pay_pending_ref_{$sid} is intentionally NOT set here. It is only ever
// set by payment_verify.php once a real payment attempt has actually happened
// (Paystack redirect or manual verify). That keeps the "Already paid? Verify
// your payment" recovery box hidden for students who haven't tried to pay yet.

$payError = getFlash('pay_error');
$pendingRef = $_SESSION['pay_pending_ref_' . $sid] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('pay_error', 'Invalid request. Please try again.');
        redirect(BASE_URL . '/payment');
    }
    $manualRef = trim((string) ($_POST['payment_reference'] ?? ''));
    if ($manualRef !== '' && !preg_match('/^[A-Za-z0-9\-_.]+$/', $manualRef)) {
        setFlash('pay_error', 'Invalid payment reference format.');
        redirect(BASE_URL . '/payment');
    }
    if (empty($manualRef)) {
        setFlash('pay_error', 'Please enter your Paystack payment reference.');
        redirect(BASE_URL . '/payment');
    }
    redirect(BASE_URL . '/payment_verify?ref=' . urlencode($manualRef));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admission Fee Payment | <?= htmlspecialchars($schoolName) ?></title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
  <style>
    body { margin:0; padding:0; font-family:'Inter',sans-serif;
      background:linear-gradient(135deg,#050e1a 0%,#0a1f35 50%,#06152b 100%);
      min-height:100vh; }
    .pay-nav {
      padding:0.85rem 1.5rem; display:flex; align-items:center; justify-content:space-between;
      border-bottom:1px solid rgba(255,255,255,0.06);
      background:rgba(5,14,26,0.85); backdrop-filter:blur(10px);
    }
    .pay-nav .brand { display:flex; align-items:center; gap:0.6rem; text-decoration:none; }
    .pay-nav .brand img { width:38px; height:38px; border-radius:50%; object-fit:contain; border:2px solid rgba(0,150,200,0.4); }
    .pay-nav .brand span { color:#fff; font-weight:700; font-size:0.85rem; }
    .pay-nav a.logout { color:rgba(255,255,255,0.45); font-size:0.8rem; text-decoration:none; }

    .pay-wrap { max-width:460px; margin:3rem auto; padding:0 1rem 4rem; }

    /* Steps indicator */
    .pay-steps { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:2rem; }
    .pay-step { display:flex; align-items:center; gap:0.4rem; font-size:0.75rem; font-weight:600; }
    .pay-step .num {
      width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center;
      font-size:0.72rem; font-weight:800;
    }
    .pay-step.done .num  { background:#2dc653; color:#fff; }
    .pay-step.active .num{ background:#006fa0; color:#fff; box-shadow:0 0 0 3px rgba(0,111,160,0.3); }
    .pay-step.next .num  { background:rgba(255,255,255,0.1); color:rgba(255,255,255,0.4); }
    .pay-step .lbl { color:rgba(255,255,255,0.5); }
    .pay-step.active .lbl{ color:#fff; }
    .pay-step-line { width:40px; height:2px; background:rgba(255,255,255,0.1); margin:0 0.25rem; flex-shrink:0; }
    .pay-step-line.done { background:#2dc653; }

    .payment-card {
      background:rgba(10,25,45,0.92); border:1px solid rgba(0,111,160,0.3);
      border-radius:20px; padding:2.25rem 2rem;
      box-shadow:0 20px 60px rgba(0,0,0,0.5); backdrop-filter:blur(10px);
    }
    .payment-card h2 { color:#fff; font-weight:900; text-align:center; font-size:1.1rem;
      text-transform:uppercase; letter-spacing:1px; margin-bottom:0.2rem; }
    .payment-card .sub { color:rgba(255,255,255,0.45); text-align:center; font-size:0.78rem; margin-bottom:1.5rem; }

    .student-info {
      background:rgba(0,111,160,0.1); border:1px solid rgba(0,111,160,0.2);
      border-radius:10px; padding:0.9rem 1rem; margin-bottom:1.5rem;
    }
    .student-info p { color:#bcd; font-size:0.84rem; margin:0.2rem 0; }
    .student-info strong { color:#fff; }

    .fee-display { text-align:center; margin-bottom:1.75rem; }
    .fee-display .amount { font-size:3.2rem; font-weight:900; color:#4dd8ff; line-height:1; }
    .fee-display .currency { font-size:1.1rem; color:rgba(255,255,255,0.5); font-weight:600; vertical-align:top; margin-top:0.5rem; display:inline-block; }
    .fee-display .label { color:rgba(255,255,255,0.4); font-size:0.75rem; text-transform:uppercase; letter-spacing:1px; margin-top:0.35rem; }

    .btn-pay {
      display:block; width:100%; padding:1rem;
      background:linear-gradient(135deg,#00b96b,#00e88a);
      color:#fff; font-weight:800; font-size:1rem; border:none; border-radius:12px;
      cursor:pointer; transition:all 0.3s; text-align:center; letter-spacing:0.5px;
      box-shadow:0 8px 25px rgba(0,185,107,0.4);
    }
    .btn-pay:hover:not(:disabled) { transform:translateY(-2px); box-shadow:0 12px 30px rgba(0,185,107,0.6); }
    .btn-pay:disabled { opacity:0.5; cursor:not-allowed; }
    .btn-pay.loading::after { content:' ⏳'; }

    .pay-notice { margin-top:0.85rem; text-align:center; color:rgba(255,255,255,0.3); font-size:0.73rem; }
    .warn-box { background:rgba(255,100,100,0.1); border:1px solid rgba(255,100,100,0.3);
      border-radius:10px; padding:0.9rem; margin-bottom:1rem; color:#ff8888; font-size:0.82rem; text-align:center; }
    .err-box  { background:rgba(230,57,70,0.12); border:1px solid rgba(230,57,70,0.3);
      border-radius:10px; padding:0.85rem; margin-bottom:1.25rem; color:#e63946; font-size:0.85rem; text-align:center; }
    .dev-bypass { margin-top:1rem; text-align:center; }
    .dev-bypass a { color:rgba(255,255,255,0.3); font-size:0.72rem; text-decoration:underline; cursor:pointer; }
    .recovery-box {
      margin-top:1.25rem; padding:1rem; border-radius:10px;
      background:rgba(77,216,255,0.08); border:1px solid rgba(77,216,255,0.25);
    }
    .recovery-box h3 { color:#4dd8ff; font-size:0.88rem; margin:0 0 0.35rem; font-weight:700; }
    .recovery-box p { color:rgba(255,255,255,0.55); font-size:0.76rem; margin:0 0 0.75rem; line-height:1.45; }
    .recovery-box input {
      width:100%; padding:0.65rem 0.8rem; border-radius:8px; border:1px solid rgba(255,255,255,0.15);
      background:rgba(0,0,0,0.25); color:#fff; font-size:0.82rem; margin-bottom:0.6rem;
    }
    .btn-verify {
      display:block; width:100%; padding:0.7rem; border:none; border-radius:8px;
      background:rgba(0,111,160,0.85); color:#fff; font-weight:700; font-size:0.82rem; cursor:pointer;
    }
    .btn-verify:hover { background:#006fa0; }
    .btn-retry {
      display:block; width:100%; padding:0.7rem; border-radius:8px; margin-bottom:0.75rem;
      background:rgba(45,198,83,0.15); border:1px solid rgba(45,198,83,0.35); color:#2dc653;
      font-weight:700; font-size:0.82rem; text-align:center; text-decoration:none;
    }
  </style>
</head>
<body>
<nav class="pay-nav">
  <a href="<?= BASE_URL ?>/index" class="brand">
    <img src="<?= asset($logoPath) ?>" alt="Logo">
    <span><?= htmlspecialchars($schoolName) ?></span>
  </a>
  <a href="<?= BASE_URL ?>/logout" class="logout">Logout</a>
</nav>

<div class="pay-wrap">
  <!-- Step Progress -->
  <div class="pay-steps">
    <div class="pay-step done"><div class="num">✓</div><div class="lbl">Verify</div></div>
    <div class="pay-step-line done"></div>
    <div class="pay-step active"><div class="num">2</div><div class="lbl">Payment</div></div>
    <div class="pay-step-line"></div>
    <div class="pay-step next"><div class="num">3</div><div class="lbl">Register</div></div>
    <div class="pay-step-line"></div>
    <div class="pay-step next"><div class="num">4</div><div class="lbl">Download</div></div>
  </div>

  <div class="payment-card">
    <?php if ($payError): ?>
    <div class="err-box">⚠ <?= htmlspecialchars($payError) ?></div>
    <?php endif; ?>

    <h2>Admission Fee Payment</h2>
    <p class="sub"><?= htmlspecialchars($academicYear) ?> Academic Year</p>

    <?php if (empty($paystackPub)): ?>
    <div class="warn-box">
      ⚠ <strong>Payment gateway not configured.</strong><br>
      The admin has not set up Paystack keys yet.<br>
      <small>Admin → Settings → Payment tab → enter Paystack keys.</small>
    </div>
    <?php endif; ?>

    <div class="student-info">
      <p>👤 <strong><?= htmlspecialchars($student['full_name']) ?></strong></p>
      <p>📇 Index: <strong><?= htmlspecialchars($student['index_number']) ?></strong></p>
      <p>📚 Programme: <strong><?= htmlspecialchars($student['program']) ?></strong></p>
      <p>🏠 Residency: <strong><?= htmlspecialchars($student['residency']) ?></strong></p>
    </div>

    <div class="fee-display">
      <div>
        <span class="currency">GHS</span>
        <span class="amount"><?= number_format($admissionFee, 2) ?></span>
      </div>
      <div class="label">Admission Processing Fee</div>
    </div>

    <?php if (!empty($paystackPub)): ?>
    <button type="button" class="btn-pay" id="payBtn">
      💳 Pay GHS <?= number_format($admissionFee, 2) ?> via Paystack
    </button>
    <?php else: ?>
    <button type="button" class="btn-pay" disabled>💳 Payment Gateway Not Configured</button>
    <?php endif; ?>

    <div class="pay-notice">🔒 Secured by Paystack &nbsp;|&nbsp; Cards, Mobile Money &amp; Bank Transfer accepted</div>

    <?php if (!empty($paystackPub) && ($payError || $pendingRef)): ?>
    <div class="recovery-box">
      <h3>Already paid? Verify your payment</h3>
      <p>If money was deducted but you were not redirected to registration, retry verification here so your payment is not lost.</p>
      <?php if ($pendingRef): ?>
      <a class="btn-retry" href="<?= BASE_URL ?>/payment_verify?ref=<?= urlencode($pendingRef) ?>">
        Retry with last reference (<?= htmlspecialchars($pendingRef) ?>)
      </a>
      <?php endif; ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="verify_payment" value="1">
        <input type="text" name="payment_reference" placeholder="Enter Paystack reference (e.g. CDTI-...)" value="<?= htmlspecialchars($pendingRef) ?>" required>
        <button type="submit" class="btn-verify">Verify my payment</button>
      </form>
    </div>
    <?php endif; ?>

<?php if (getenv('APP_ENV') === 'development' && empty($paystackPub)): ?>
     <!-- Dev bypass when keys not configured -->
     <div class="dev-bypass">
       <a href="<?= BASE_URL ?>/payment_verify?ref=<?= $payRef ?>&bypass=1">
         [Dev mode: Skip payment →]
       </a>
     </div>
     <?php endif; ?>
  </div>
</div>

<script nonce="<?= generateCspNonce() ?>" src="https://js.paystack.co/v1/inline.js"></script>
<script nonce="<?= generateCspNonce() ?>">
function payWithPaystack() {
  var btn = document.getElementById('payBtn');
  btn.disabled = true;
  btn.classList.add('loading');
  btn.textContent = '⏳ Opening payment popup...';

  var handler = PaystackPop.setup({
    key:      '<?= htmlspecialchars($paystackPub) ?>',
    email:    'student<?= $sid ?>@cdtisanzule.edu.gh',
    amount:   <?= $feeKobo ?>,
    currency: 'GHS',
    ref:      '<?= $payRef ?>',
    firstname:'<?= htmlspecialchars(explode(' ', $student['full_name'])[0]) ?>',
    metadata: {
      student_id:   '<?= $sid ?>',
      index_number: '<?= htmlspecialchars($student['index_number']) ?>',
      programme:    '<?= htmlspecialchars($student['program']) ?>'
    },
    callback: function(response) {
      btn.textContent = '✅ Payment received! Verifying...';
      if (response && response.reference) {
        try { sessionStorage.setItem('pay_pending_ref_<?= $sid ?>', response.reference); } catch (e) {}
      }
      window.location.href = '<?= BASE_URL ?>/payment_verify?ref=' + encodeURIComponent(response.reference);
    },
    onClose: function() {
      btn.disabled = false;
      btn.classList.remove('loading');
      btn.textContent = '💳 Pay GHS <?= number_format($admissionFee, 2) ?> via Paystack';
    }
  });
  handler.openIframe();
}

document.getElementById('payBtn').addEventListener('click', payWithPaystack);
</script>
</body>
</html>
