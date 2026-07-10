<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
startSecureSession();

// Redirect if already logged in
if (isAdminLoggedIn()) { redirect(BASE_URL . '/admin/dashboard'); }

emitCspHeader();
header('X-Robots-Tag: noindex, nofollow');
$nonce = generateCspNonce();

// S05: Show explicit timeout message when session expired
$timedOut = isset($_GET['timeout']) && $_GET['timeout'] === '1';
$error = $timedOut ? 'Your session has expired. Please log in again.' : getFlash('admin_error');
$s = getSettings();
$logoPath = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$schoolName = $s['school_name'] ?? 'CDTI';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login | <?= htmlspecialchars($schoolName) ?> Admission Portal</title>
  <link rel="icon" type="image/png" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap');
    *, *::before, *::after { box-sizing: border-box; }
    
    body {
      font-family: 'Outfit', sans-serif;
      background: url('<?= asset("assets/img/admin_bg.jpg") ?>') center/cover no-repeat fixed;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1rem;
      margin: 0;
      position: relative;
      overflow-x: hidden;
    }
    
    body::before {
      content: '';
      position: fixed;
      inset: 0;
      background: radial-gradient(circle at center, rgba(10, 25, 47, 0.35) 0%, rgba(3, 8, 16, 0.88) 100%);
      z-index: 0;
    }
    
    .login-container {
      width: 100%;
      max-width: 420px;
      position: relative;
      z-index: 1;
      animation: cardAppear 0.8s cubic-bezier(0.16, 1, 0.3, 1) forwards;
      opacity: 0;
      transform: translateY(25px);
    }
    
    @keyframes cardAppear {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
    
    .login-card {
      background: rgba(10, 25, 47, 0.65);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 24px;
      border: 1px solid rgba(0, 180, 255, 0.15);
      padding: 2.25rem;
      box-shadow: 0 25px 60px rgba(0, 0, 0, 0.65), 
                  0 0 50px rgba(0, 180, 255, 0.08);
      position: relative;
    }
    
    .logo-wrapper {
      width: fit-content;
      margin: 0 auto 1.25rem;
      background: #030810;
      border-radius: 50%;
      padding: 14px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5),
                  0 0 40px rgba(0, 180, 255, 0.3);
      border: 2px solid rgba(0, 180, 255, 0.4);
      animation: logoPulse 4s infinite ease-in-out;
      overflow: visible;
    }
    
    @keyframes logoPulse {
      0%, 100% { box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 40px rgba(0, 180, 255, 0.3); }
      50% { box-shadow: 0 10px 30px rgba(0,0,0,0.5), 0 0 55px rgba(0, 180, 255, 0.5); }
    }
    
    .logo-wrapper .logo {
      width: 100px;
      height: 100px;
      border-radius: 0;
      object-fit: contain;
      display: block;
    }
    
    .login-card h4 {
      text-align: center;
      font-weight: 800;
      color: #ffffff;
      margin-top: 0;
      margin-bottom: 0.35rem;
      font-size: 1.2rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      text-shadow: 0 0 15px rgba(0, 180, 255, 0.35);
      line-height: 1.3;
    }
    
    .login-card p {
      text-align: center;
      color: rgba(255, 255, 255, 0.55);
      font-size: 0.85rem;
      margin-bottom: 2rem;
    }
    
    .form-group {
      margin-bottom: 1.5rem;
      position: relative;
    }
    
    .form-group label {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 1.2px;
      color: rgba(0, 180, 255, 0.85);
      font-weight: 700;
      display: block;
      margin-bottom: 0.5rem;
      padding-left: 2px;
    }
    
    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }
    
    .input-wrapper .input-icon {
      position: absolute;
      left: 1.1rem;
      color: rgba(255, 255, 255, 0.45);
      font-size: 0.95rem;
      pointer-events: none;
      transition: color 0.3s;
    }
    
    .form-control {
      width: 100%;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, 0.1);
      background: rgba(255, 255, 255, 0.03);
      padding: 0.85rem 1rem 0.85rem 2.85rem;
      font-size: 0.92rem;
      color: #ffffff;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      font-family: inherit;
    }
    
    .form-control::placeholder {
      color: rgba(255, 255, 255, 0.25);
    }
    
    .form-control:focus {
      outline: none;
      border-color: #00b4ff;
      background: rgba(255, 255, 255, 0.06);
      box-shadow: 0 0 15px rgba(0, 180, 255, 0.25);
      color: #ffffff;
    }
    
    .form-control:focus + .input-icon {
      color: #00b4ff;
    }
    
    .password-toggle {
      position: absolute;
      right: 1.1rem;
      color: rgba(255, 255, 255, 0.45);
      cursor: pointer;
      transition: color 0.3s;
      z-index: 10;
      padding: 0.25rem;
    }
    
    .password-toggle:hover {
      color: #00b4ff;
    }
    
    .btn-login {
      background: linear-gradient(135deg, #006fa0, #00b4ff);
      border: none;
      border-radius: 12px;
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
      padding: 0.95rem;
      width: 100%;
      margin-top: 1rem;
      cursor: pointer;
      transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
      letter-spacing: 0.5px;
      box-shadow: 0 4px 20px rgba(0, 180, 255, 0.3);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.6rem;
      font-family: inherit;
    }
    
    .btn-login:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 30px rgba(0, 180, 255, 0.5);
    }
    
    .btn-login:active {
      transform: translateY(0);
    }
    
    .footer-text {
      text-align: center;
      margin-top: 2rem;
      font-size: 0.75rem;
      color: rgba(255, 255, 255, 0.45);
      letter-spacing: 0.5px;
      line-height: 1.5;
    }
    
    .footer-text a {
      color: #00b4ff;
      text-decoration: none;
      font-weight: 600;
      transition: color 0.2s;
    }
    
    .footer-text a:hover {
      color: #4dd8ff;
      text-decoration: underline;
    }
    
    .alert-danger {
      background: rgba(230, 57, 70, 0.15);
      border: 1px solid rgba(230, 57, 70, 0.3);
      color: #ff4d5a;
      font-size: 0.85rem;
      border-radius: 10px;
      padding: 0.75rem 1rem;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.6rem;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <div class="login-card">
      <div class="logo-wrapper">
        <img src="<?= asset($logoPath) ?>" alt="Logo" class="logo">
      </div>
      <h4><?= htmlspecialchars($schoolName) ?></h4>
      <p>Admin Login — Enter your credentials</p>

      <?php if ($error): ?>
      <div class="alert alert-danger">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><?= htmlspecialchars($error) ?></span>
      </div>
      <?php endif; ?>

      <form method="POST" action="login.inc" autocomplete="off">
        <?= csrfField() ?>
        <div class="form-group">
          <label>Username</label>
          <div class="input-wrapper">
            <input type="text" class="form-control" name="username" required autocomplete="off" placeholder="Enter username">
            <i class="fa-solid fa-user input-icon"></i>
          </div>
        </div>
        <div class="form-group">
          <label>Password</label>
          <div class="input-wrapper">
            <input type="password" class="form-control" name="password" id="passwordInput" required autocomplete="new-password" placeholder="Enter password">
            <i class="fa-solid fa-lock input-icon"></i>
            <i class="fa-solid fa-eye password-toggle" id="togglePassword"></i>
          </div>
        </div>
        <button type="submit" class="btn-login" name="login">
          <i class="fa-solid fa-right-to-bracket"></i> Login to Dashboard
        </button>
      </form>
      <p class="footer-text">© <?= date('Y') ?> <a href="https://jamesackahblay-portfolio.blinksitsolution.com/" target="_blank">BLINKS IT SOLUTION</a><br>All Rights Reserved</p>
    </div>
  </div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script nonce="<?= $nonce ?>">
document.getElementById('togglePassword')?.addEventListener('click', function() {
  const passwordInput = document.getElementById('passwordInput');
  const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
  passwordInput.setAttribute('type', type);
  
  // Toggle eye icon class
  this.classList.toggle('fa-eye');
  this.classList.toggle('fa-eye-slash');
});

<?php if ($error): ?>
Swal.fire({
  icon: 'error',
  title: 'Login Failed',
  text: <?= json_encode($error) ?>,
  background: '#0a192f',
  color: '#fff',
  confirmButtonColor: '#006fa0'
});
<?php endif; ?>
</script>
</body>
</html>
