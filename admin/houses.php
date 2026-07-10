<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
requirePermission('houses');
emitCspHeader();

$pdo   = getDB();
$s     = getSettings();
$flash = getFlash('houses_flash');
$error = getFlash('houses_error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name     = sanitize($_POST['name'] ?? '');
            $gender   = $_POST['gender'] ?? '';
            $capacity = (int) ($_POST['capacity'] ?? 0);

            if ($name === '' || !in_array($gender, ['Male', 'Female'], true)) {
                $error = 'House name and gender are required.';
            } elseif ($capacity < 1) {
                $error = 'Capacity must be at least 1.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO houses (name, gender, capacity) VALUES (?, ?, ?)")
                        ->execute([$name, $gender, $capacity]);
                    logAction('admin', $_SESSION['admin_id'], 'house_created', "House: $name ($gender, cap $capacity)");
                    $flash = "House \"$name\" created successfully.";
                } catch (PDOException $e) {
                    $error = strpos($e->getMessage(), 'Duplicate') !== false
                        ? 'A house with that name already exists.'
                        : 'Failed to create house.';
                }
            }
        } elseif ($action === 'update') {
            $id       = (int) ($_POST['house_id'] ?? 0);
            $name     = sanitize($_POST['name'] ?? '');
            $gender   = $_POST['gender'] ?? '';
            $capacity = (int) ($_POST['capacity'] ?? 0);
            $occupied = getHouseOccupancy($pdo, $id);

            if ($id < 1 || $name === '' || !in_array($gender, ['Male', 'Female'], true)) {
                $error = 'Invalid house data.';
            } elseif ($capacity < 1) {
                $error = 'Capacity must be at least 1.';
            } elseif ($capacity < $occupied) {
                $error = "Capacity cannot be less than current occupancy ($occupied students).";
            } else {
                try {
                    $pdo->prepare("UPDATE houses SET name=?, gender=?, capacity=? WHERE id=?")
                        ->execute([$name, $gender, $capacity, $id]);
                    logAction('admin', $_SESSION['admin_id'], 'house_updated', "House ID $id: $name");
                    $flash = "House \"$name\" updated successfully.";
                } catch (PDOException $e) {
                    $error = strpos($e->getMessage(), 'Duplicate') !== false
                        ? 'A house with that name already exists.'
                        : 'Failed to update house.';
                }
            }
        } elseif ($action === 'delete') {
            $id       = (int) ($_POST['house_id'] ?? 0);
            $occupied = getHouseOccupancy($pdo, $id);

            if ($id < 1) {
                $error = 'Invalid house.';
            } elseif ($occupied > 0) {
                $error = "Cannot delete — $occupied student(s) are assigned to this house.";
            } else {
                $pdo->prepare("DELETE FROM houses WHERE id=?")->execute([$id]);
                logAction('admin', $_SESSION['admin_id'], 'house_deleted', "House ID $id");
                $flash = 'House deleted successfully.';
            }
        }
    }
    // Redirect (POST/Redirect/GET) so refreshing this page afterwards never
    // resubmits the form — that resubmission is what causes "Invalid request
    // token" on refresh, since the CSRF token in the old POST body has
    // already been rotated server-side by the time it's resent.
    if ($flash) { setFlash('houses_flash', $flash); }
    if ($error) { setFlash('houses_error', $error); }
    redirect(BASE_URL . '/admin/houses');
}

$houses = $pdo->query("
    SELECT h.*,
           (SELECT COUNT(*) FROM students s WHERE s.house_id = h.id AND s.registration_status = 'completed') AS occupied
    FROM houses h
    ORDER BY h.gender, h.name
")->fetchAll();

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'houses';
$topbarTitle = '<i class="fa-solid fa-house-chimney"></i> House Management';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Houses | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    .houses-grid { display:grid; grid-template-columns:1fr 340px; gap:1.25rem; align-items:start; }
    @media(max-width:900px) { .houses-grid { grid-template-columns:1fr; } }
    .capacity-bar { height:6px; background:var(--progress-bg); border-radius:3px; overflow:hidden; margin-top:0.35rem; }
    .capacity-bar span { display:block; height:100%; background:var(--primary-light); border-radius:3px; }
    .capacity-bar.full span { background:var(--danger); }
    .badge-gender-m { background:rgba(0,111,160,0.15); color:#4dd8ff; border:1px solid rgba(0,111,160,0.3); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .badge-gender-f { background:rgba(230,57,70,0.12); color:#ff6b7a; border:1px solid rgba(230,57,70,0.25); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .form-card { background:var(--bg-card); border:1px solid var(--border-light); border-radius:12px; padding:1.25rem; }
    .form-card h3 { font-size:0.95rem; margin-bottom:1rem; color:var(--text-primary); }
    .form-card .form-group { margin-bottom:0.85rem; }
    .form-card label { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-secondary); margin-bottom:0.35rem; font-weight:600; }
    .form-card input, .form-card select {
      width:100%; padding:0.55rem 0.75rem; background:var(--bg-input);
      border:1px solid var(--border); border-radius:8px; color:var(--text-primary); font-family:inherit; font-size:0.85rem;
    }
    .form-card input:focus, .form-card select:focus { border-color:var(--primary); outline:none; }
    .edit-row { display:none; background:rgba(0,111,160,0.06); }
    .edit-row.active { display:table-row; }
    .tbl-wrap { overflow-x:auto; }
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

      <div class="houses-grid">
        <div>
          <div class="tbl-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>House Name</th>
                  <th>Gender</th>
                  <th>Capacity</th>
                  <th>Occupied</th>
                  <th>Available</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($houses)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);padding:2rem;">No houses yet. Create one using the form.</td></tr>
                <?php else: foreach ($houses as $h):
                  $occupied  = (int) $h['occupied'];
                  $available = max(0, (int) $h['capacity'] - $occupied);
                  $pct       = $h['capacity'] > 0 ? min(100, round($occupied / $h['capacity'] * 100)) : 0;
                  $isFull    = $available === 0;
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($h['name']) ?></strong>
                    <div class="capacity-bar <?= $isFull ? 'full' : '' ?>"><span style="width:<?= $pct ?>%"></span></div>
                  </td>
                  <td>
                    <span class="<?= $h['gender'] === 'Female' ? 'badge-gender-f' : 'badge-gender-m' ?>">
                      <?= htmlspecialchars($h['gender']) ?>
                    </span>
                  </td>
                  <td><?= (int) $h['capacity'] ?></td>
                  <td><?= $occupied ?></td>
                  <td>
                    <?php if ($isFull): ?>
                    <span style="color:var(--danger);font-weight:700;font-size:0.8rem;">Full</span>
                    <?php else: ?>
                    <span style="color:var(--success);font-weight:600;"><?= $available ?></span>
                    <?php endif; ?>
                  </td>
                  <td style="white-space:nowrap;">
                    <button type="button" class="btn-admin btn-admin-sm btn-toggle-edit" data-house-id="<?= (int) $h['id'] ?>"><i class="fa-solid fa-pen"></i> Edit</button>
                    <?php if ($occupied === 0): ?>
                    <form method="POST" style="display:inline;" class="house-delete-form">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="house_id" value="<?= (int) $h['id'] ?>">
                      <button type="submit" class="btn-admin btn-admin-sm" style="color:var(--danger);"><i class="fa-solid fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <tr class="edit-row" id="edit-<?= (int) $h['id'] ?>">
                  <td colspan="6">
                    <form method="POST" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;padding:0.5rem 0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="house_id" value="<?= (int) $h['id'] ?>">
                      <div style="flex:1;min-width:140px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($h['name']) ?>" required>
                      </div>
                      <div style="min-width:110px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Gender</label>
                        <select name="gender" required>
                          <option value="Male"   <?= $h['gender']==='Male'  ?'selected':'' ?>>Male</option>
                          <option value="Female" <?= $h['gender']==='Female'?'selected':'' ?>>Female</option>
                        </select>
                      </div>
                      <div style="min-width:90px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Capacity</label>
                        <input type="number" name="capacity" value="<?= (int) $h['capacity'] ?>" min="<?= $occupied ?>" required>
                      </div>
                      <button type="submit" class="btn-admin btn-admin-primary btn-admin-sm">Save</button>
                      <button type="button" class="btn-admin btn-admin-sm btn-toggle-edit" data-house-id="<?= (int) $h['id'] ?>">Cancel</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <p style="font-size:0.78rem;color:var(--text-muted);margin-top:0.75rem;line-height:1.6;">
            Full houses are hidden from the student admission form. Students only see houses matching their gender with available spaces.
          </p>
        </div>

        <div class="form-card">
          <h3><i class="fa-solid fa-plus"></i> Add New House</h3>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
              <label>House Name</label>
              <input type="text" name="name" placeholder="e.g. Aggrey House" required>
            </div>
            <div class="form-group">
              <label>Gender Category</label>
              <select name="gender" required>
                <option value="">Select gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </div>
            <div class="form-group">
              <label>Capacity</label>
              <input type="number" name="capacity" min="1" value="50" required>
            </div>
            <button type="submit" class="btn-admin btn-admin-primary" style="width:100%;"><i class="fa-solid fa-house-chimney"></i> Create House</button>
          </form>
        </div>
      </div>

    </div>
  </main>
</div>
<script nonce="<?= generateCspNonce() ?>">
function toggleEdit(id) {
  const row = document.getElementById('edit-' + id);
  if (row) row.classList.toggle('active');
}
const alertEl = document.querySelector('.alert-success');
if (alertEl) setTimeout(() => { alertEl.style.opacity='0'; alertEl.style.transition='opacity 0.5s'; setTimeout(()=>alertEl.remove(),500); }, 4000);

// Wired up here instead of inline onclick/onsubmit attributes, which CSP's
// nonce'd script-src always blocks regardless of nonce placement.
document.querySelectorAll('.btn-toggle-edit').forEach(function (btn) {
  btn.addEventListener('click', function () { toggleEdit(this.dataset.houseId); });
});
document.querySelectorAll('.house-delete-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm('Delete this house?')) e.preventDefault();
  });
});
</script>
</body>
</html>
