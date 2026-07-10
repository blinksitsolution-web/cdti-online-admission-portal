<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
emitCspHeader();

$pdo   = getDB();
$s     = getSettings();
$flash = getFlash('profile_flash');
$error = getFlash('profile_error');

$adminId = (int)$_SESSION['admin_id'];
$stmt = $pdo->prepare("SELECT * FROM admins WHERE id=?");
$stmt->execute([$adminId]);
$admin = $stmt->fetch();

if (!$admin) {
    session_destroy();
    redirect(BASE_URL . '/admin/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        $fullName = sanitize($_POST['full_name'] ?? '');
        $username = sanitize($_POST['username'] ?? '');
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        // Validations
        if (empty($fullName) || empty($username)) {
            $error = 'Full name and username are required.';
        } elseif (empty($currentPassword)) {
            $error = 'Please enter your current password to authorize changes.';
        } elseif (!password_verify($currentPassword, $admin['password_hash'])) {
            $error = 'Incorrect current password.';
        } else {
            // Check if username is taken
            $checkStmt = $pdo->prepare("SELECT id FROM admins WHERE username=? AND id!=?");
            $checkStmt->execute([$username, $adminId]);
            if ($checkStmt->fetch()) {
                $error = 'The username is already taken by another administrator.';
            } else {
                $updatePass = false;
                $newHash = '';
                if (!empty($newPassword)) {
                    if ($newPassword !== $confirmPassword) {
                        $error = 'New password and confirmation do not match.';
                    } elseif (strlen($newPassword) < 6) {
                        $error = 'New password must be at least 6 characters long.';
                    } else {
                        $updatePass = true;
                        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
                    }
                }

                if (empty($error)) {
                    if ($updatePass) {
                        $updateStmt = $pdo->prepare("UPDATE admins SET full_name=?, username=?, password_hash=? WHERE id=?");
                        $updateStmt->execute([$fullName, $username, $newHash, $adminId]);
                    } else {
                        $updateStmt = $pdo->prepare("UPDATE admins SET full_name=?, username=? WHERE id=?");
                        $updateStmt->execute([$fullName, $username, $adminId]);
                    }

                    // Update session
                    $_SESSION['admin_full_name'] = $fullName;
                    $_SESSION['admin_username'] = $username;

                    logAction('admin', $adminId, 'profile_updated', 'Admin details updated');
                    $flash = 'Profile updated successfully!';
                }
            }
        }
    }
    // Redirect (POST/Redirect/GET) so refreshing this page afterwards never
    // resubmits the form — that resubmission is what causes "Invalid request
    // token" on refresh, since the CSRF token in the old POST body has
    // already been rotated server-side by the time it's resent.
    if ($flash) { setFlash('profile_flash', $flash); }
    if ($error) { setFlash('profile_error', $error); }
    redirect(BASE_URL . '/admin/profile');
}

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'profile';
$topbarTitle = '👤 My Admin Profile';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Profile | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
</head>
<body>
<div class="admin-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body" style="max-width:800px; margin:0 auto;">

      <!-- Alerts -->
      <?php if ($flash): ?>
      <div class="alert-success">&#9989; <?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert-error">&#10060; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <div class="admin-card">
        <div class="admin-card-header">
          <h6><i class="fa-solid fa-user-gear"></i> Update Login & Profile Details</h6>
        </div>
        <div class="admin-card-body">
          <form method="POST" autocomplete="off" id="profileForm">
            <?= csrfField() ?>

            <div class="form-row">
              <div class="form-group">
                <label>Full Name *</label>
                <input type="text" name="full_name" value="<?= htmlspecialchars($admin['full_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Username *</label>
                <input type="text" name="username" value="<?= htmlspecialchars($admin['username'] ?? '') ?>" required>
              </div>
            </div>

            <div class="form-row" style="margin-top:0.5rem; border-top:1px solid var(--border-light); padding-top:1.25rem;">
              <div class="form-group">
                <label>New Password <small style="text-transform:none;color:var(--text-muted);">(Leave blank to keep current)</small></label>
                <input type="password" name="new_password" placeholder="Enter new password" autocomplete="new-password">
              </div>
              <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password">
              </div>
            </div>

            <div style="margin-top:0.5rem; border-top:1px solid var(--border-light); padding-top:1.25rem;">
              <div class="form-group" style="max-width:400px; margin: 0 auto 1.5rem;">
                <label style="color:#e63946;font-weight:700;text-align:center;display:block;">Current Password *</label>
                <input type="password" name="current_password" placeholder="Verify your current password" required style="border: 2px solid #e63946; text-align:center;">
                <small style="display:block;text-align:center;color:var(--text-muted);margin-top:0.4rem;">Required to save any profile changes.</small>
              </div>
            </div>

            <div style="text-align:center; margin-top:1.5rem;">
              <button type="submit" class="btn-save" style="min-width:200px;">
                <i class="fa-solid fa-floppy-disk"></i> Save Profile Changes
              </button>
            </div>

          </form>
        </div>
      </div>

    </div>
  </main>
</div>
</body>
</html>
