<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requirePermission('users');
emitCspHeader();

$pdo   = getDB();
$s     = getSettings();
$flash = getFlash('users_flash');
$error = getFlash('users_error');

// Ensure permissions column exists (auto-migrate).
// "IF NOT EXISTS" is MariaDB-only and always errors on real MySQL, so add plain
// and swallow only the "already exists" case — otherwise this silently never ran.
try {
    $pdo->exec("ALTER TABLE admins ADD COLUMN permissions TEXT DEFAULT NULL AFTER role");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
}

$allPerms = [
    'dashboard' => ['icon' => 'fa-chart-line',      'label' => 'Dashboard'],
    'students'  => ['icon' => 'fa-user-graduate',   'label' => 'Students / Placements'],
    'sms'       => ['icon' => 'fa-message',         'label' => 'Send Bulk SMS'],
    'import'    => ['icon' => 'fa-file-import',     'label' => 'Import Data'],
    'houses'    => ['icon' => 'fa-house-chimney',   'label' => 'Houses'],
    'settings'  => ['icon' => 'fa-sliders',         'label' => 'Settings'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? '';

        // ── Create user ───────────────────────────────────────────
        if ($action === 'create') {
            $fullName = sanitize($_POST['full_name'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? 'staff';
            $perms    = (array)($_POST['permissions'] ?? []);

            if (empty($fullName) || empty($username) || empty($password)) {
                $error = 'Full name, username and password are required.';
            } elseif (strlen($password) < 6) {
                $error = 'Password must be at least 6 characters.';
            } elseif (!in_array($role, ['superadmin', 'staff'])) {
                $error = 'Invalid role.';
            } else {
                // Superadmin always gets all permissions
                $permStr = $role === 'superadmin'
                    ? implode(',', array_keys($allPerms)) . ',users'
                    : implode(',', array_filter(array_map('trim', $perms)));

                try {
                    $pdo->prepare("INSERT INTO admins (username, password_hash, full_name, role, permissions) VALUES (?,?,?,?,?)")
                        ->execute([$username, password_hash($password, PASSWORD_BCRYPT), $fullName, $role, $permStr]);
                    logAction('admin', $_SESSION['admin_id'], 'user_created', "Created: $username ($role)");
                    $flash = "User \"$fullName\" created successfully.";
                } catch (PDOException $e) {
                    $error = str_contains($e->getMessage(), 'Duplicate') ? 'Username already exists.' : 'Failed to create user.';
                }
            }
        }

        // ── Update user ───────────────────────────────────────────
        elseif ($action === 'update') {
            $uid      = (int)($_POST['user_id'] ?? 0);
            $fullName = sanitize($_POST['full_name'] ?? '');
            $username = sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $role     = $_POST['role'] ?? 'staff';
            $perms    = (array)($_POST['permissions'] ?? []);

            if ($uid < 1 || empty($fullName) || empty($username)) {
                $error = 'Full name and username are required.';
            } elseif (!in_array($role, ['superadmin', 'staff'])) {
                $error = 'Invalid role.';
            } else {
                $permStr = $role === 'superadmin'
                    ? implode(',', array_keys($allPerms)) . ',users'
                    : implode(',', array_filter(array_map('trim', $perms)));

                try {
                    if (!empty($password)) {
                        if (strlen($password) < 6) { $error = 'Password must be at least 6 characters.'; goto done; }
                        $pdo->prepare("UPDATE admins SET full_name=?, username=?, password_hash=?, role=?, permissions=? WHERE id=?")
                            ->execute([$fullName, $username, password_hash($password, PASSWORD_BCRYPT), $role, $permStr, $uid]);
                    } else {
                        $pdo->prepare("UPDATE admins SET full_name=?, username=?, role=?, permissions=? WHERE id=?")
                            ->execute([$fullName, $username, $role, $permStr, $uid]);
                    }
                    // Refresh session if editing self
                    if ($uid === (int)$_SESSION['admin_id']) {
                        $_SESSION['admin_full_name']   = $fullName;
                        $_SESSION['admin_username']    = $username;
                        $_SESSION['admin_role']        = $role;
                        $_SESSION['admin_permissions'] = $permStr;
                    }
                    logAction('admin', $_SESSION['admin_id'], 'user_updated', "Updated user ID $uid: $username");
                    $flash = "User \"$fullName\" updated successfully.";
                } catch (PDOException $e) {
                    $error = str_contains($e->getMessage(), 'Duplicate') ? 'Username already exists.' : 'Failed to update user.';
                }
            }
        }

        // ── Delete user ───────────────────────────────────────────
        elseif ($action === 'delete') {
            $uid = (int)($_POST['user_id'] ?? 0);
            if ($uid === (int)$_SESSION['admin_id']) {
                $error = 'You cannot delete your own account.';
            } elseif ($uid > 0) {
                $pdo->prepare("DELETE FROM admins WHERE id=?")->execute([$uid]);
                logAction('admin', $_SESSION['admin_id'], 'user_deleted', "Deleted user ID $uid");
                $flash = 'User deleted successfully.';
            }
        }
    }
}
done:
// Redirect (POST/Redirect/GET) so refreshing this page afterwards never
// resubmits the form — that resubmission is what causes "Invalid request
// token" on refresh, since the CSRF token in the old POST body has already
// been rotated server-side by the time it's resent. Placed at this shared
// label so it also runs after the goto-early-exit path above.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($flash) { setFlash('users_flash', $flash); }
    if ($error) { setFlash('users_error', $error); }
    redirect(BASE_URL . '/admin/users');
}

$admins = $pdo->query("SELECT id, username, full_name, role, permissions, last_login, created_at FROM admins ORDER BY role DESC, full_name ASC")->fetchAll();

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'users';
$topbarTitle = '<i class="fa-solid fa-users-gear"></i> Manage Admin Users';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Users | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    .perm-grid { display:grid; grid-template-columns:1fr 1fr; gap:0.4rem; margin-top:0.5rem; }
    .perm-item { display:flex; align-items:center; gap:0.5rem; padding:0.45rem 0.6rem;
      background:var(--bg-input); border:1px solid var(--border); border-radius:8px;
      font-size:0.8rem; color:var(--text-secondary); cursor:pointer; transition:all 0.2s; }
    .perm-item:has(input:checked) { background:rgba(0,111,160,0.12); border-color:rgba(0,111,160,0.4); color:var(--text-primary); }
    .perm-item input { width:14px; height:14px; accent-color:var(--primary); cursor:pointer; }
    .role-badge-superadmin { background:rgba(157,78,221,0.15); color:#c77dff; border:1px solid rgba(157,78,221,0.3); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .role-badge-staff { background:rgba(0,111,160,0.12); color:#4dd8ff; border:1px solid rgba(0,111,160,0.25); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .perm-tags { display:flex; flex-wrap:wrap; gap:4px; margin-top:4px; }
    .perm-tag { background:rgba(0,111,160,0.1); color:#4dd8ff; border:1px solid rgba(0,111,160,0.2); padding:1px 7px; border-radius:10px; font-size:0.65rem; font-weight:600; }
    .form-card { background:var(--bg-card); border:1px solid var(--border-light); border-radius:12px; padding:1.25rem; }
    .form-card h3 { font-size:0.95rem; margin:0 0 1rem; color:var(--text-primary); display:flex; align-items:center; gap:0.5rem; }
    .form-card .form-group { margin-bottom:0.85rem; }
    .form-card label { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-secondary); margin-bottom:0.35rem; font-weight:600; }
    .form-card input[type=text], .form-card input[type=password], .form-card select {
      width:100%; padding:0.55rem 0.75rem; background:var(--bg-input);
      border:1px solid var(--border); border-radius:8px; color:var(--text-primary); font-family:inherit; font-size:0.85rem;
    }
    .form-card input:focus, .form-card select:focus { border-color:var(--primary); outline:none; }
    .edit-row { display:none; }
    .edit-row.active { display:table-row; }
    .edit-form { background:rgba(0,111,160,0.05); border:1px solid rgba(0,111,160,0.15); border-radius:10px; padding:1.25rem; margin:0.5rem 0; }
    .you-badge { background:rgba(45,198,83,0.12); color:#2dc653; border:1px solid rgba(45,198,83,0.25); padding:1px 7px; border-radius:10px; font-size:0.65rem; font-weight:700; margin-left:4px; }

    /* ── Create User Modal ──────────────────────────────────────── */
    .modal-overlay {
      display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6);
      z-index:1000; align-items:center; justify-content:center; padding:1rem;
    }
    .modal-overlay.active { display:flex; }
    .modal-overlay .form-card { max-width:480px; width:100%; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,0.5); }
    .modal-box-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; }
    .modal-box-header h3 { margin:0; }
    .modal-close { background:none; border:none; font-size:1.4rem; line-height:1; color:var(--text-secondary); cursor:pointer; padding:0.2rem 0.5rem; }
    .modal-close:hover { color:var(--text-primary); }
  </style>
</head>
<body>
<div class="admin-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-body">

      <?php if ($flash): ?>
      <div class="alert-success">&#9989; <?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert-error">&#10060; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <!-- Users Table -->
      <div class="admin-card">
        <div class="admin-card-header">
          <h6><i class="fa-solid fa-users"></i> Admin Users (<?= count($admins) ?>)</h6>
          <button type="button" class="btn-admin btn-admin-primary btn-admin-sm" id="openCreateModal">
            <i class="fa-solid fa-user-plus"></i> Add User
          </button>
        </div>
        <div class="admin-card-body" style="padding:0;">
          <table class="admin-table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Permissions</th>
                  <th>Last Login</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($admins as $u):
                  $isMe    = (int)$u['id'] === (int)$_SESSION['admin_id'];
                  $uPerms  = array_filter(array_map('trim', explode(',', $u['permissions'] ?? '')));
                ?>
                <tr>
                  <td>
                    <strong style="font-size:0.85rem;"><?= htmlspecialchars($u['full_name'] ?? '') ?></strong>
                    <?php if ($isMe): ?><span class="you-badge">You</span><?php endif; ?>
                  </td>
                  <td style="font-family:monospace;font-size:0.82rem;color:var(--text-secondary);"><?= htmlspecialchars($u['username']) ?></td>
                  <td><span class="role-badge-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span></td>
                  <td>
                    <?php if ($u['role'] === 'superadmin'): ?>
                      <span style="color:#c77dff;font-size:0.75rem;font-weight:700;">All Access</span>
                    <?php else: ?>
                      <div class="perm-tags">
                        <?php foreach ($uPerms as $p): ?>
                          <span class="perm-tag"><?= htmlspecialchars($p) ?></span>
                        <?php endforeach; ?>
                        <?php if (empty($uPerms)): ?>
                          <span style="color:var(--text-muted);font-size:0.75rem;">No permissions</span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:0.78rem;color:var(--text-secondary);">
                    <?= $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : 'Never' ?>
                  </td>
                  <td style="white-space:nowrap;">
                    <button type="button" class="btn-admin btn-admin-sm btn-admin-primary btn-toggle-edit" data-user-id="<?= (int)$u['id'] ?>">
                      <i class="fa-solid fa-pen"></i> Edit
                    </button>
                    <?php if (!$isMe): ?>
                    <form method="POST" style="display:inline;" class="user-delete-form" data-confirm-name="<?= htmlspecialchars($u['full_name']) ?>">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                      <button type="submit" class="btn-admin btn-admin-sm" style="color:var(--danger);"><i class="fa-solid fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <!-- Inline Edit Row -->
                <tr class="edit-row" id="edit-<?= (int)$u['id'] ?>">
                  <td colspan="6">
                    <div class="edit-form">
                      <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;margin-bottom:0.75rem;">
                          <div>
                            <label style="font-size:0.7rem;color:var(--text-muted);display:block;margin-bottom:0.3rem;">Full Name</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($u['full_name'] ?? '') ?>" required style="width:100%;padding:0.5rem;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:0.84rem;">
                          </div>
                          <div>
                            <label style="font-size:0.7rem;color:var(--text-muted);display:block;margin-bottom:0.3rem;">Username</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($u['username']) ?>" required style="width:100%;padding:0.5rem;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:0.84rem;">
                          </div>
                          <div>
                            <label style="font-size:0.7rem;color:var(--text-muted);display:block;margin-bottom:0.3rem;">New Password <small>(leave blank to keep)</small></label>
                            <input type="password" name="password" placeholder="New password..." style="width:100%;padding:0.5rem;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:0.84rem;">
                          </div>
                          <div>
                            <label style="font-size:0.7rem;color:var(--text-muted);display:block;margin-bottom:0.3rem;">Role</label>
                            <select name="role" class="role-select" data-perm-target="ep-<?= (int)$u['id'] ?>" style="width:100%;padding:0.5rem;background:var(--bg-input);border:1px solid var(--border);border-radius:6px;color:var(--text-primary);font-family:inherit;font-size:0.84rem;">
                              <option value="staff"      <?= $u['role']==='staff'      ?'selected':'' ?>>Staff</option>
                              <option value="superadmin" <?= $u['role']==='superadmin' ?'selected':'' ?>>Superadmin</option>
                            </select>
                          </div>
                        </div>
                        <div id="ep-<?= (int)$u['id'] ?>" <?= $u['role']==='superadmin'?'style="display:none;"':'' ?>>
                          <label style="font-size:0.7rem;color:var(--text-muted);display:block;margin-bottom:0.4rem;">Permissions</label>
                          <div class="perm-grid">
                            <?php foreach ($allPerms as $pk => $pv): ?>
                            <label class="perm-item">
                              <input type="checkbox" name="permissions[]" value="<?= $pk ?>" <?= in_array($pk, $uPerms)?'checked':'' ?>>
                              <i class="fa-solid <?= $pv['icon'] ?>" style="font-size:0.8rem;color:var(--primary-light);"></i>
                              <?= $pv['label'] ?>
                            </label>
                            <?php endforeach; ?>
                          </div>
                        </div>
                        <div style="display:flex;gap:0.5rem;margin-top:0.85rem;">
                          <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
                          <button type="button" class="btn-admin btn-admin-sm btn-toggle-edit" data-user-id="<?= (int)$u['id'] ?>">Cancel</button>
                        </div>
                      </form>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      <!-- Create User Modal -->
      <div class="modal-overlay" id="createUserModal">
        <div class="form-card">
          <div class="modal-box-header">
            <h3><i class="fa-solid fa-user-plus"></i> Create New User</h3>
            <button type="button" class="modal-close" id="closeCreateModal" aria-label="Close">&times;</button>
          </div>
          <form method="POST" id="createForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
              <label>Full Name *</label>
              <input type="text" name="full_name" placeholder="e.g. John Mensah" required>
            </div>
            <div class="form-group">
              <label>Username *</label>
              <input type="text" name="username" placeholder="e.g. jmensah" required autocomplete="off">
            </div>
            <div class="form-group">
              <label>Password *</label>
              <input type="password" name="password" placeholder="Min. 6 characters" required autocomplete="new-password">
            </div>
            <div class="form-group">
              <label>Role *</label>
              <select name="role" class="role-select" data-perm-target="cp-new">
                <option value="staff">Staff</option>
                <option value="superadmin">Superadmin (Full Access)</option>
              </select>
            </div>
            <div class="form-group" id="cp-new">
              <label>Assign Permissions</label>
              <div class="perm-grid">
                <?php foreach ($allPerms as $pk => $pv): ?>
                <label class="perm-item">
                  <input type="checkbox" name="permissions[]" value="<?= $pk ?>" <?= $pk==='dashboard'?'checked':'' ?>>
                  <i class="fa-solid <?= $pv['icon'] ?>" style="font-size:0.8rem;color:var(--primary-light);"></i>
                  <?= $pv['label'] ?>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <button type="submit" class="btn-admin btn-admin-primary" style="width:100%;margin-top:0.5rem;">
              <i class="fa-solid fa-user-plus"></i> Create User
            </button>
          </form>
        </div>
      </div>

    </div>
  </main>
</div>
<script nonce="<?= generateCspNonce() ?>">
function toggleEdit(id) {
  document.querySelectorAll('.edit-row').forEach(r => { if (r.id !== 'edit-' + id) r.classList.remove('active'); });
  document.getElementById('edit-' + id)?.classList.toggle('active');
}
function togglePermSection(sel, sectionId) {
  const sec = document.getElementById(sectionId);
  if (sec) sec.style.display = sel.value === 'superadmin' ? 'none' : '';
}
const alertEl = document.querySelector('.alert-success');
if (alertEl) setTimeout(() => { alertEl.style.opacity='0'; alertEl.style.transition='opacity 0.5s'; setTimeout(()=>alertEl.remove(),500); }, 4000);

// Wired up here instead of inline onclick/onchange/onsubmit attributes, which
// CSP's nonce'd script-src always blocks regardless of nonce placement.
document.querySelectorAll('.btn-toggle-edit').forEach(function (btn) {
  btn.addEventListener('click', function () { toggleEdit(this.dataset.userId); });
});
document.querySelectorAll('.role-select').forEach(function (sel) {
  sel.addEventListener('change', function () { togglePermSection(this, this.dataset.permTarget); });
});
document.querySelectorAll('.user-delete-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm('Delete user ' + this.dataset.confirmName + '?')) e.preventDefault();
  });
});

// Create User modal
const createModal = document.getElementById('createUserModal');
function openCreateModal() { createModal.classList.add('active'); }
function closeCreateModal() { createModal.classList.remove('active'); }
document.getElementById('openCreateModal').addEventListener('click', openCreateModal);
document.getElementById('closeCreateModal').addEventListener('click', closeCreateModal);
createModal.addEventListener('click', function (e) {
  if (e.target === createModal) closeCreateModal();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape' && createModal.classList.contains('active')) closeCreateModal();
});
</script>
</body>
</html>
