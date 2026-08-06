<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
requirePermission('students');

$pdo = getDB();
$s   = getSettings();

// ── Export CSV ────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="students_' . date('Y-m-d') . '.csv"');
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Admission No.','Index Number','Full Name','Gender','Program','Residency','Aggregate','Payment','Status','Registered At']);
    $rows = $pdo->query("SELECT admission_number,index_number,full_name,gender,program,residency,aggregate,payment_status,registration_status,registered_at FROM students ORDER BY full_name")->fetchAll();
    foreach ($rows as $row) fputcsv($fp, $row);
    fclose($fp); exit;
}
emitCspHeader();

// ── Filters & Pagination ──────────────────────────────────────────
$search    = sanitize($_GET['search']    ?? '');
$program   = sanitize($_GET['program']   ?? '');
$residency = sanitize($_GET['residency'] ?? '');
$status    = sanitize($_GET['status']    ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

$where = []; $params = [];
if ($search)    { $where[] = "(full_name LIKE ? OR index_number LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($program)   { $where[] = "program = ?";   $params[] = $program; }
if ($residency) { $where[] = "residency = ?"; $params[] = $residency; }
if ($status)    { $where[] = "registration_status = ?"; $params[] = $status; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalStmt  = $pdo->prepare("SELECT COUNT(*) FROM students $whereSql");
$totalStmt->execute($params);
$totalRows  = (int)$totalStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));

$stmt = $pdo->prepare("SELECT * FROM students $whereSql ORDER BY full_name ASC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$students = $stmt->fetchAll();

$programs = $pdo->query("SELECT DISTINCT program FROM students WHERE program IS NOT NULL AND program != '' ORDER BY program")->fetchAll(PDO::FETCH_COLUMN);

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'students';
$topbarTitle = '<i class="fa-solid fa-users"></i> Students & Placements';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Students | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    .filter-card { background:var(--bg-card); border:1px solid var(--border-light); border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.25rem; }
    .filter-grid { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:center; }
    .filter-grid input, .filter-grid select {
      flex:1; min-width:160px; padding:0.55rem 0.8rem;
      background:var(--bg-input); border:1px solid var(--border); border-radius:8px;
      font-size:0.84rem; color:var(--text-primary); font-family:inherit;
    }
    .filter-grid input:focus, .filter-grid select:focus { border-color:var(--primary); outline:none; }
    .badge-paid   { background:rgba(45,198,83,0.12); color:#2dc653; border:1px solid rgba(45,198,83,0.25); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .badge-unpaid { background:rgba(230,57,70,0.1); color:#e63946; border:1px solid rgba(230,57,70,0.2); padding:2px 8px; border-radius:20px; font-size:0.68rem; font-weight:700; }
    .tbl-wrap { overflow-x:auto; }
    .admin-table th { position:sticky; top:0; z-index:5; }
    .row-num { color:var(--text-muted); font-size:0.78rem; }
  </style>
</head>
<body>
<div class="admin-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-body">

      <!-- Summary bar -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:0.5rem;">
        <div style="font-size:0.85rem;color:var(--text-secondary);">
          Total: <strong style="color:var(--text-primary);"><?= number_format($totalRows) ?></strong> students
          <?php if ($search || $program || $residency || $status): ?>
          &nbsp;|&nbsp; <a href="<?= BASE_URL ?>/admin/students.php" style="color:var(--danger);font-size:0.8rem;">✕ Clear filters</a>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:0.5rem;">
          <a href="<?= BASE_URL ?>/admin/import.php" class="btn-admin btn-admin-primary btn-admin-sm"><i class="fa-solid fa-file-import"></i> Import CSV</a>
          <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn-admin btn-admin-success btn-admin-sm"><i class="fa-solid fa-file-export"></i> Export CSV</a>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-card">
        <form method="GET">
          <div class="filter-grid">
            <input type="text" name="search" placeholder="🔍 Search name or index..." value="<?= htmlspecialchars($search) ?>" style="min-width:220px;">
            <select name="program">
              <option value="">All Programmes</option>
              <?php foreach ($programs as $p): ?>
              <option value="<?= htmlspecialchars($p) ?>" <?= $program===$p?'selected':'' ?>><?= htmlspecialchars($p) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="residency">
              <option value="">All Residency</option>
              <option value="Day"     <?= $residency==='Day'    ?'selected':'' ?>>Day</option>
              <option value="Boarder" <?= $residency==='Boarder'?'selected':'' ?>>Boarder</option>
            </select>
            <select name="status">
              <option value="">All Status</option>
              <option value="completed"  <?= $status==='completed' ?'selected':'' ?>>Completed</option>
              <option value="not_started"<?= $status==='not_started'?'selected':'' ?>>Not Started</option>
              <option value="in_progress"<?= $status==='in_progress'?'selected':'' ?>>In Progress</option>
            </select>
            <button type="submit" class="btn-admin btn-admin-primary" style="white-space:nowrap;padding:0.55rem 1.25rem;">Filter</button>
          </div>
        </form>
      </div>

      <!-- Table -->
      <div class="admin-card">
        <div class="admin-card-header">
          <h6><i class="fa-solid fa-list-check"></i> Placement Records</h6>
          <span style="font-size:0.78rem;color:var(--text-secondary);">Showing <?= count($students) ?> of <?= number_format($totalRows) ?></span>
        </div>
        <div class="admin-card-body" style="padding:0;">
          <div class="tbl-wrap">
            <table class="admin-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Admission No.</th>
                  <th>Index No.</th>
                  <th>Gender</th>
                  <th>Programme</th>
                  <th>Res.</th>
                  <th>Agg.</th>
                  <th>Payment</th>
                  <th>Reg. Status</th>
                  <th>Registered</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($students as $i => $st): ?>
                <tr>
                  <td class="row-num"><?= $offset + $i + 1 ?></td>
                  <td><strong style="font-size:0.85rem;"><?= htmlspecialchars($st['full_name']) ?></strong></td>
                  <td style="font-family:monospace;font-size:0.78rem;color:#4dd8ff;white-space:nowrap;"><?= htmlspecialchars($st['admission_number'] ?? '—') ?></td>
                  <td style="font-family:monospace;font-size:0.8rem;color:var(--text-secondary);"><?= htmlspecialchars($st['index_number']) ?></td>
                  <td style="font-size:0.82rem;"><?= htmlspecialchars($st['gender']) ?></td>
                  <td style="font-size:0.82rem;max-width:140px;word-wrap:break-word;"><?= htmlspecialchars($st['program']) ?></td>
                  <td style="font-size:0.82rem;"><?= htmlspecialchars($st['residency']) ?></td>
                  <td><?= htmlspecialchars($st['aggregate'] ?? '—') ?></td>
                  <td>
                    <?php $pay = $st['payment_status'] ?? 'unpaid'; ?>
                    <span class="badge-<?= $pay === 'paid' ? 'paid' : 'unpaid' ?>">
                      <?= strtoupper($pay) ?>
                    </span>
                  </td>
                  <td>
                    <span class="badge-status badge-<?= $st['registration_status'] ?>">
                      <?= strtoupper(str_replace('_',' ', $st['registration_status'])) ?>
                    </span>
                  </td>
                  <td style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;">
                    <?= $st['registered_at'] ? date('d M Y', strtotime($st['registered_at'])) : '—' ?>
                  </td>
                  <td>
                    <a href="<?= BASE_URL ?>/admin/student_view.php?id=<?= $st['id'] ?>" class="btn-admin btn-admin-primary btn-admin-sm">View</a>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($students)): ?>
                <tr><td colspan="12" style="text-align:center;padding:3rem;color:var(--text-muted);">
                  No students found<?= ($search || $program || $residency || $status) ? ' matching your filters' : ' — import placement data first' ?>.
                </td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
          <div style="padding:0.75rem 1.25rem;">
            <ul class="pagination">
              <?php if ($page > 1): ?>
              <li><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>">‹</a></li>
              <?php endif; ?>
              <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
              <li class="<?= $p===$page?'active':'' ?>">
                <?php if ($p===$page): ?><span><?= $p ?></span>
                <?php else: ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>"><?= $p ?></a>
                <?php endif; ?>
              </li>
              <?php endfor; ?>
              <?php if ($page < $totalPages): ?>
              <li><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>">›</a></li>
              <?php endif; ?>
            </ul>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>
</div>
<script nonce="<?= generateCspNonce() ?>">
// Live search debounce
let searchTimer;
document.querySelector('input[name=search]')?.addEventListener('input', function() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => this.closest('form').submit(), 600);
});
</script>
</body>
</html>
