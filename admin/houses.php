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
            $name      = sanitize($_POST['name'] ?? '');
            $gender    = $_POST['gender'] ?? '';
            $capacity  = (int) ($_POST['capacity'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '' || !in_array($gender, ['Male', 'Female', 'Both'], true)) {
                $error = 'House name and gender category are required.';
            } elseif ($capacity < 1) {
                $error = 'Capacity must be at least 1.';
            } else {
                try {
                    $pdo->prepare("INSERT INTO houses (name, gender, capacity, is_active) VALUES (?, ?, ?, ?)")
                        ->execute([$name, $gender, $capacity, $is_active]);
                    logAction('admin', $_SESSION['admin_id'], 'house_created', "House: $name ($gender, cap $capacity, active:$is_active)");
                    $flash = "House \"$name\" created successfully.";
                } catch (PDOException $e) {
                    $error = strpos($e->getMessage(), 'Duplicate') !== false
                        ? 'A house with that name already exists.'
                        : 'Failed to create house.';
                }
            }
        } elseif ($action === 'update') {
            $id        = (int) ($_POST['house_id'] ?? 0);
            $name      = sanitize($_POST['name'] ?? '');
            $gender    = $_POST['gender'] ?? '';
            $capacity  = (int) ($_POST['capacity'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            $occupied  = getHouseOccupancy($pdo, $id);

            if ($id < 1 || $name === '' || !in_array($gender, ['Male', 'Female', 'Both'], true)) {
                $error = 'Invalid house data.';
            } elseif ($capacity < 1) {
                $error = 'Capacity must be at least 1.';
            } elseif ($capacity < $occupied) {
                $error = "Capacity cannot be less than current occupancy ($occupied students).";
            } else {
                try {
                    $pdo->prepare("UPDATE houses SET name=?, gender=?, capacity=?, is_active=? WHERE id=?")
                        ->execute([$name, $gender, $capacity, $is_active, $id]);
                    logAction('admin', $_SESSION['admin_id'], 'house_updated', "House ID $id: $name (active:$is_active)");
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
    if ($flash) { setFlash('houses_flash', $flash); }
    if ($error) { setFlash('houses_error', $error); }
    redirect(BASE_URL . '/admin/houses');
}

$houses = $pdo->query("
    SELECT h.*,
           (SELECT COUNT(*) FROM students s WHERE s.house_id = h.id AND s.registration_status = 'completed') AS occupied
    FROM houses h
    ORDER BY h.name
")->fetchAll();

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'houses';
$topbarTitle = '<i class="fa-solid fa-house-chimney"></i> House Management';

// Helper: badge class for gender
function genderBadgeClass(string $g): string {
    return match($g) {
        'Male'   => 'badge-gender-m',
        'Female' => 'badge-gender-f',
        default  => 'badge-gender-b',
    };
}
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
    .houses-grid { display:grid; grid-template-columns:1fr 360px; gap:1.25rem; align-items:start; }
    @media(max-width:900px) { .houses-grid { grid-template-columns:1fr; } }
    .capacity-bar { height:6px; background:var(--progress-bg); border-radius:3px; overflow:hidden; margin-top:0.35rem; }
    .capacity-bar span { display:block; height:100%; background:var(--primary-light); border-radius:3px; }
    .capacity-bar.full span { background:var(--danger); }
    /* Gender badges */
    .badge-gender-m { background:rgba(0,111,160,0.15); color:#4dd8ff; border:1px solid rgba(0,111,160,0.3); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .badge-gender-f { background:rgba(230,57,70,0.12); color:#ff6b7a; border:1px solid rgba(230,57,70,0.25); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .badge-gender-b { background:rgba(102,61,189,0.15); color:#b39dff; border:1px solid rgba(102,61,189,0.3); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    /* Status badges */
    .badge-active   { background:rgba(0,200,83,0.12); color:#00c853; border:1px solid rgba(0,200,83,0.3); padding:2px 10px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .badge-inactive { background:rgba(200,0,0,0.10); color:#ff5252; border:1px solid rgba(200,0,0,0.25); padding:2px 10px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    /* Form card */
    .form-card { background:var(--bg-card); border:1px solid var(--border-light); border-radius:12px; padding:1.25rem; }
    .form-card h3 { font-size:0.95rem; margin-bottom:1rem; color:var(--text-primary); }
    .form-card .form-group { margin-bottom:0.85rem; }
    .form-card label { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:0.8px; color:var(--text-secondary); margin-bottom:0.35rem; font-weight:600; }
    .form-card input, .form-card select {
      width:100%; padding:0.55rem 0.75rem; background:var(--bg-input);
      border:1px solid var(--border); border-radius:8px; color:var(--text-primary); font-family:inherit; font-size:0.85rem;
    }
    .form-card input:focus, .form-card select:focus { border-color:var(--primary); outline:none; }
    /* Toggle switch */
    .toggle-wrap { display:flex; align-items:center; gap:0.6rem; }
    .toggle-switch { position:relative; display:inline-block; width:40px; height:22px; flex-shrink:0; }
    .toggle-switch input { opacity:0; width:0; height:0; }
    .toggle-slider { position:absolute; inset:0; background:rgba(255,255,255,0.15); border-radius:22px; cursor:pointer; transition:.2s; }
    .toggle-slider:before { content:''; position:absolute; height:16px; width:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:.2s; }
    .toggle-switch input:checked + .toggle-slider { background:var(--primary); }
    .toggle-switch input:checked + .toggle-slider:before { transform:translateX(18px); }
    .toggle-label { font-size:0.8rem; color:var(--text-secondary); }
    /* Edit row */
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
        <!-- ── Houses Table ─────────────────────────────── -->
        <div>
          <div class="tbl-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>House Name</th>
                  <th>Gender</th>
                  <th>Status</th>
                  <th>Capacity</th>
                  <th>Occupied</th>
                  <th>Available</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($houses)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:2rem;">No houses yet. Create one using the form.</td></tr>
                <?php else: foreach ($houses as $h):
                  $occupied  = (int) $h['occupied'];
                  $available = max(0, (int) $h['capacity'] - $occupied);
                  $pct       = $h['capacity'] > 0 ? min(100, round($occupied / $h['capacity'] * 100)) : 0;
                  $isFull    = $available === 0;
                  $isActive  = (int) ($h['is_active'] ?? 1);
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($h['name']) ?></strong>
                    <div class="capacity-bar <?= $isFull ? 'full' : '' ?>"><span style="width:<?= $pct ?>%"></span></div>
                  </td>
                  <td>
                    <span class="<?= genderBadgeClass($h['gender']) ?>">
                      <?= htmlspecialchars($h['gender']) ?>
                    </span>
                  </td>
                  <td>
                    <?php if ($isActive): ?>
                    <span class="badge-active">Active</span>
                    <?php else: ?>
                    <span class="badge-inactive">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td><?= (int) $h['capacity'] ?></td>
                  <td><?= $occupied ?></td>
                  <td>
                    <?php if (!$isActive): ?>
                    <span style="color:var(--text-muted);font-size:0.8rem;">Hidden</span>
                    <?php elseif ($isFull): ?>
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
                <!-- Inline edit row -->
                <tr class="edit-row" id="edit-<?= (int) $h['id'] ?>">
                  <td colspan="7">
                    <form method="POST" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:flex-end;padding:0.5rem 0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="house_id" value="<?= (int) $h['id'] ?>">
                      <div style="flex:1;min-width:140px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($h['name']) ?>" required>
                      </div>
                      <div style="min-width:120px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Gender Category</label>
                        <select name="gender" required>
                          <option value="Male"   <?= $h['gender']==='Male'   ? 'selected':'' ?>>Male</option>
                          <option value="Female" <?= $h['gender']==='Female' ? 'selected':'' ?>>Female</option>
                          <option value="Both"   <?= $h['gender']==='Both'   ? 'selected':'' ?>>Both (Male &amp; Female)</option>
                        </select>
                      </div>
                      <div style="min-width:90px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Capacity</label>
                        <input type="number" name="capacity" value="<?= (int) $h['capacity'] ?>" min="<?= $occupied ?>" required>
                      </div>
                      <div style="min-width:110px;">
                        <label style="font-size:0.7rem;color:var(--text-muted);">Status</label>
                        <div class="toggle-wrap" style="margin-top:0.3rem;">
                          <label class="toggle-switch">
                            <input type="checkbox" name="is_active" value="1" <?= $isActive ? 'checked' : '' ?>>
                            <span class="toggle-slider"></span>
                          </label>
                          <span class="toggle-label">Active</span>
                        </div>
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
            <strong>Active</strong> houses are shown to all admitted boarder students (male &amp; female) on the application form.
            <strong>Inactive</strong> houses are hidden from students but remain in the system.
          </p>
        </div>

        <!-- ── Create House Form ──────────────────────────── -->
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
                <option value="">Select category</option>
                <option value="Both">Both (Male &amp; Female)</option>
                <option value="Male">Male only</option>
                <option value="Female">Female only</option>
              </select>
            </div>
            <div class="form-group">
              <label>Capacity</label>
              <input type="number" name="capacity" min="1" value="50" required>
            </div>
            <div class="form-group">
              <label>Status</label>
              <div class="toggle-wrap">
                <label class="toggle-switch">
                  <input type="checkbox" name="is_active" value="1" checked>
                  <span class="toggle-slider"></span>
                </label>
                <span class="toggle-label">Active (visible to students)</span>
              </div>
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

document.querySelectorAll('.btn-toggle-edit').forEach(function (btn) {
  btn.addEventListener('click', function () { toggleEdit(this.dataset.houseId); });
});
document.querySelectorAll('.house-delete-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm('Delete this house? This cannot be undone.')) e.preventDefault();
  });
});
</script>
</body>
</html>
