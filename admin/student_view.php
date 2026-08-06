<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
emitCspHeader();

$pdo = getDB();
$s   = getSettings();
$id  = (int) ($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/admin/students.php');

$stmt = $pdo->prepare("SELECT s.*, p.*, h.name AS house_name FROM students s
    LEFT JOIN parent_guardian_info p ON p.student_id=s.id
    LEFT JOIN houses h ON h.id=s.house_id
    WHERE s.id=?");
$stmt->execute([$id]);
$student = $stmt->fetch();
if (!$student) redirect(BASE_URL . '/admin/students.php');

$isBoarder = isBoarderResidency($student['residency'] ?? '');
$houseOptions = [];
if ($isBoarder) {
    // All active houses available — no gender filter
    $houseStmt = $pdo->prepare("SELECT id, name, gender, capacity FROM houses WHERE is_active = 1 ORDER BY name");
    $houseStmt->execute();
    foreach ($houseStmt->fetchAll() as $house) {
        $house['occupied'] = getHouseOccupancy($pdo, (int) $house['id'], $id);
        $houseOptions[] = $house;
    }
}

$success = getFlash('admin_success');
$error   = getFlash('admin_error');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('admin_error','Invalid request.'); redirect('/admin/student_view?id='.$id);
    }
    
    $residency = sanitize($_POST['residency'] ?? 'Day');
    $gender    = sanitize($_POST['gender'] ?? $student['gender']);
    $houseId = 0;
    if (isBoarderResidency($residency)) {
        $houseId = (int) ($_POST['house_id'] ?? 0);
        if ($houseId < 1) {
            setFlash('admin_error', 'Please select a boarding house for boarder students.');
            redirect('/admin/student_view?id=' . $id);
        } elseif (!isHouseAvailableForStudent($pdo, $houseId, normalizeStudentGender($gender), $id)) {
            setFlash('admin_error', 'The selected house is at capacity.');
            redirect('/admin/student_view?id=' . $id);
        }
    }
    
    $dob = sanitize($_POST['dob'] ?? '');
    if (empty($dob)) { $dob = null; }
    
    $pdo->prepare("UPDATE students SET
        full_name=?, gender=?, date_of_birth=?, religion=?, hometown=?, region=?,
        nationality=?, prev_jhs_school=?, program=?, residency=?, aggregate=?,
        enrolment_code=?, registration_status=?, house_id=?
        WHERE id=?
    ")->execute([
        sanitize($_POST['full_name']), sanitize($gender),
        $dob, sanitize($_POST['religion']),
        sanitize($_POST['hometown']), sanitize($_POST['region']),
        sanitize($_POST['nationality']), sanitize($_POST['prev_jhs']),
        sanitize($_POST['program']), $residency,
        sanitize($_POST['aggregate']), sanitize($_POST['enrolment_code']),
        sanitize($_POST['registration_status']),
        $houseId > 0 ? $houseId : null,
        $id
    ]);
    logAction('admin', $_SESSION['admin_id'], 'student_updated', "Student ID: $id");
    setFlash('admin_success','Student record updated successfully.');
    redirect('/admin/student_view?id='.$id);
}

$ghanaRegions = ['Greater Accra','Ashanti','Western','Eastern','Central','Volta','Brong-Ahafo','Northern','Upper East','Upper West','Western North','Ahafo','Bono East','North East','Oti','Savannah'];
$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'students';
$topbarTitle = '<i class="fa-solid fa-arrow-left"></i> <a href="' . BASE_URL . '/admin/students" style="color:var(--primary);text-decoration:none;font-weight:bold;">Students</a> / ' . htmlspecialchars($student['full_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>View Student | KIMTECH Admin</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="<?= BASE_URL ?>/admin/assets/css/admin.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
</head>
<body>
<div class="admin-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <div class="admin-topbar">
      <h5><?= $topbarTitle ?></h5>
      <div class="topbar-right">
        <a href="<?= BASE_URL ?>/pdf/generate_letter?sid=<?= $id ?>&admin=1" target="_blank" class="btn-save" style="background:var(--primary);color:#fff;border:none;margin-right:0.5rem;padding:0.4rem 1rem;font-size:0.82rem;font-weight:700;border-radius:6px;text-decoration:none;"><i class="fa-solid fa-file-pdf"></i> Admission Letter</a>
        <a href="<?= BASE_URL ?>/pdf/generate_record?sid=<?= $id ?>&admin=1" target="_blank" class="btn-save" style="background:#2dc653;color:#fff;border:none;margin-right:1rem;padding:0.4rem 1rem;font-size:0.82rem;font-weight:700;border-radius:6px;text-decoration:none;"><i class="fa-solid fa-file-invoice"></i> Personal Record</a>
        <button id="themeToggleBtn" class="theme-toggle" style="margin-right:1rem;">
          <span class="toggle-icon"><i class="fa-solid fa-sun"></i></span> Light
        </button>
        <div class="admin-info"><i class="fa-solid fa-user-shield"></i> <?= htmlspecialchars($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Admin') ?></div>
      </div>
    </div>
    <div class="page-body">

    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="row">
      <!-- STUDENT EDIT FORM -->
      <div class="col-md-8">
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-user-pen"></i> Edit Student Record</h6></div>
          <div class="admin-card-body">
            <form method="POST" class="admin-form">
              <?= csrfField() ?>
              <input type="hidden" name="update_student" value="1">
              <div class="row">
                <div class="col-md-8 form-group"><label>Full Name</label><input type="text" name="full_name" value="<?= htmlspecialchars($student['full_name']) ?>" required></div>
                <div class="col-md-4 form-group"><label>Gender</label>
                  <select name="gender"><option value="Male" <?= $student['gender']==='Male'?'selected':''?>>Male</option><option value="Female" <?= $student['gender']==='Female'?'selected':''?>>Female</option></select>
                </div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group"><label>Date of Birth</label><input type="date" name="dob" value="<?= htmlspecialchars($student['date_of_birth'] ?? '') ?>"></div>
                <div class="col-md-4 form-group"><label>Religion</label><input type="text" name="religion" value="<?= htmlspecialchars($student['religion'] ?? '') ?>"></div>
                <div class="col-md-4 form-group"><label>Hometown</label><input type="text" name="hometown" value="<?= htmlspecialchars($student['hometown'] ?? '') ?>"></div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group"><label>Region</label>
                  <select name="region"><?php foreach ($ghanaRegions as $r): ?><option value="<?= $r ?>" <?= $student['region']===$r?'selected':''?>><?= $r ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4 form-group"><label>Nationality</label><input type="text" name="nationality" value="<?= htmlspecialchars($student['nationality'] ?? 'Ghanaian') ?>"></div>
                <div class="col-md-4 form-group"><label>Prev. JHS</label><input type="text" name="prev_jhs" value="<?= htmlspecialchars($student['prev_jhs_school'] ?? '') ?>"></div>
              </div>
              <div class="row">
                <div class="col-md-4 form-group"><label>Program</label><input type="text" name="program" value="<?= htmlspecialchars($student['program'] ?? '') ?>"></div>
                <div class="col-md-4 form-group"><label>Residency</label>
                  <select name="residency" id="residency"><option value="Boarder" <?= $student['residency']==='Boarder'?'selected':''?>>Boarder</option><option value="Day" <?= $student['residency']==='Day'?'selected':''?>>Day</option></select>
                </div>
                <div class="col-md-4 form-group" id="houseField" style="<?= $isBoarder ? '' : 'display:none;' ?>">
                  <label>Student House</label>
                  <select name="house_id" id="house_id">
                    <option value="">Select house</option>
                    <?php foreach ($houseOptions as $house): ?>
                    <option value="<?= (int) $house['id'] ?>" <?= (int)($student['house_id'] ?? 0) === (int) $house['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($house['name']) ?> (<?= (int) $house['occupied'] ?>/<?= (int) $house['capacity'] ?>)
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-2 form-group"><label>Aggregate</label><input type="text" name="aggregate" value="<?= htmlspecialchars($student['aggregate'] ?? '') ?>"></div>
                <div class="col-md-2 form-group"><label>Enrolment Code</label><input type="text" name="enrolment_code" value="<?= htmlspecialchars($student['enrolment_code'] ?? '') ?>"></div>
              </div>
              <div class="form-group"><label>Registration Status</label>
                <select name="registration_status">
                  <option value="not_started" <?= $student['registration_status']==='not_started'?'selected':''?>>Not Started</option>
                  <option value="in_progress" <?= $student['registration_status']==='in_progress'?'selected':''?>>In Progress</option>
                  <option value="completed" <?= $student['registration_status']==='completed'?'selected':''?>>Completed</option>
                </select>
              </div>
              <button type="submit" class="btn-admin btn-admin-primary"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            </form>
          </div>
        </div>
      </div>

      <!-- STUDENT SIDEBAR INFO -->
      <div class="col-md-4">
        <div class="admin-card">
          <div class="admin-card-body text-center">
            <?php if (!empty($student['passport_photo_path'])): ?>
            <img src="<?= asset($student['passport_photo_path']) ?>" alt="Passport photo" style="width:100px;height:100px;border-radius:50%;object-fit:cover;border:3px solid #006fa0;margin-bottom:1rem;">
            <?php else: ?>
            <div style="width:100px;height:100px;border-radius:50%;background:#e8ecf0;display:flex;align-items:center;justify-content:center;font-size:2.5rem;margin:0 auto 1rem;"><i class="fa-solid fa-user-large" style="color:var(--text-secondary);"></i></div>
            <?php endif; ?>
            <h6 style="font-weight:700;"><?= htmlspecialchars($student['full_name']) ?></h6>
            <p style="font-size:0.78rem;color:#888;margin:0.25rem 0;"><?= htmlspecialchars($student['index_number']) ?></p>
            <span class="badge-status badge-<?= $student['registration_status'] ?>"><?= str_replace('_',' ',$student['registration_status']) ?></span>
            <hr>
            <table style="width:100%;font-size:0.82rem;text-align:left;">
              <?php if (!empty($student['admission_number'])): ?>
              <tr><td style="color:#888;padding:0.2rem 0;">Admission No.</td><td><strong style="color:#4dd8ff;font-family:monospace;"><?= htmlspecialchars($student['admission_number']) ?></strong></td></tr>
              <?php endif; ?>
              <tr><td style="color:#888;padding:0.2rem 0;">Program</td><td><strong><?= htmlspecialchars($student['program']) ?></strong></td></tr>
              <tr><td style="color:#888;">Residency</td><td><strong><?= htmlspecialchars($student['residency']) ?></strong></td></tr>
              <?php if ($isBoarder): ?>
              <tr><td style="color:#888;">Student House</td><td><strong><?= htmlspecialchars($student['house_name'] ?? '—') ?></strong></td></tr>
              <?php endif; ?>
              <tr><td style="color:#888;">Gender</td><td><?= htmlspecialchars($student['gender']) ?></td></tr>
              <tr><td style="color:#888;">Aggregate</td><td><?= htmlspecialchars($student['aggregate']) ?></td></tr>
              <?php if ($student['registered_at']): ?>
              <tr><td style="color:#888;">Registered</td><td><?= date('d M Y', strtotime($student['registered_at'])) ?></td></tr>
              <?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script nonce="<?= generateCspNonce() ?>">
document.getElementById('themeToggleBtn')?.addEventListener('click', toggleAdminTheme);
document.getElementById('residency')?.addEventListener('change', function () {
  const houseField = document.getElementById('houseField');
  const houseSelect = document.getElementById('house_id');
  if (!houseField || !houseSelect) return;
  const isBoarder = this.value === 'Boarder';
  houseField.style.display = isBoarder ? '' : 'none';
  houseSelect.required = isBoarder;
  if (!isBoarder) houseSelect.value = '';
});
</script>
</body>
</html>
