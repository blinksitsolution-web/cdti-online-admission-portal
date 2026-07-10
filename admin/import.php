<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
requirePermission('import');
emitCspHeader();

$pdo          = getDB();
$s            = getSettings();
$importResult = null;

// ── POST: Process CSV ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_csv'])) {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        redirect(BASE_URL . '/admin/import.php');
    }
    if (empty($_FILES['csv_file']['name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $importResult = ['imported'=>0,'skipped'=>0,'errors'=>['No file uploaded or upload error.'],'headers'=>[]];
    } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
        $importResult = ['imported'=>0,'skipped'=>0,'errors'=>['File too large. Maximum allowed size is 5 MB.'],'headers'=>[]];
    } elseif (!in_array(strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION)), ['csv','txt'], true)) {
        $importResult = ['imported'=>0,'skipped'=>0,'errors'=>['Invalid file type. Only .csv and .txt files are accepted.'],'headers'=>[]];
    } else {
        $handle   = fopen($_FILES['csv_file']['tmp_name'], 'r');
        // Read BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);

        $rawHeaders = fgetcsv($handle);
        // Normalise headers → lowercase, strip spaces/special chars
        $headers = array_map(fn($h) => strtolower(preg_replace('/[^a-z0-9]/i','_', trim($h))), $rawHeaders);

        // ── Smart column mapper ────────────────────────────────────
        // Maps CSV column names → our DB fields
        $colMap = [];
        $aliases = [
            'index_number'    => ['index_no','index','indexno','index_number','index_no_','idx'],
            'full_name'       => ['names','full_name','name','student_name','fullname'],
            'gender'          => ['gender','sex'],
            'program'         => ['department','program','programme','dept','course'],
            'residency'       => ['status','residency','boarding','day_boarding'],
            'aggregate'       => ['aggregate','agg','bece_agg'],
            'enrolment_code'  => ['enrolment_code','enroll_code','code','enrollment_code'],
            'date_of_birth'   => ['dob','date_of_birth','birth_date','birthdate'],
            'phone_number'    => ['phone_no','phone','phone_number','contact','mobile'],
        ];
        $mappedIndices = [];
        foreach ($headers as $i => $h) {
            foreach ($aliases as $dbField => $possible) {
                if (in_array($h, $possible) && !isset($colMap[$dbField])) {
                    $colMap[$dbField] = $i;
                    $mappedIndices[] = $i;
                    break;
                }
            }
        }

        // Enforce index_number column width to 30 characters in DB
        try {
            $pdo->exec("ALTER TABLE students MODIFY COLUMN index_number VARCHAR(30) NOT NULL");
        } catch (Exception $e) {}

        // Ensure phone_number column exists (once, before the row loop — not per-row)
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN phone_number VARCHAR(30) DEFAULT NULL");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
        }

        // Identify custom columns not in the standard mapping, and add them to the DB
        // ("IF NOT EXISTS" is MariaDB-only and errors on real MySQL — always add plain,
        // then swallow only the "already exists" case so $customColumns still gets set.)
        $customColumns = [];
        foreach ($headers as $i => $h) {
            if (!in_array($i, $mappedIndices) && !empty($h)) {
                $colName = preg_replace('/[^a-z0-9_]/', '', $h);
                // Limit to 64 chars max for MySQL column names
                $colName = substr($colName, 0, 64);
                if (!empty($colName) && strlen($colName) >= 3) {
                    try {
                        $pdo->exec("ALTER TABLE students ADD COLUMN `$colName` TEXT DEFAULT NULL");
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
                    }
                    $customColumns[$colName] = $i;
                }
            }
        }

        $imported = 0; $skipped = 0; $errors = [];
        $row      = 1;
        $maxRows  = 5000;

        while (($data = fgetcsv($handle)) !== false) {
            $row++;
            if ($row > $maxRows + 1) {
                $errors[] = "Import stopped at row $maxRows — file exceeds the maximum allowed rows.";
                break;
            }
            if (count(array_filter($data)) === 0) continue; // skip blank rows

            // Extract using column map
            $get = fn(string $f, string $def = '') => isset($colMap[$f]) ? trim($data[$colMap[$f]] ?? '') : $def;

            $index  = $get('index_number');
            $name   = $get('full_name');
            $gender = $get('gender');
            $prog   = $get('program');
            $res    = $get('residency', 'Day');
            $agg    = $get('aggregate');
            $code   = $get('enrolment_code');
            $dob    = $get('date_of_birth');
            $phone  = $get('phone_number');

            // ── Validate index: accept 8–15 alphanumeric characters ──
            $index = preg_replace('/\s+/', '', $index);
            if (empty($index) || !preg_match('/^[A-Za-z0-9]{6,20}$/', $index)) {
                $errors[] = "Row $row: Invalid index '$index' — skipped.";
                $skipped++; continue;
            }

            // Normalise name
            if (empty($name)) { $errors[] = "Row $row: Name empty — skipped."; $skipped++; continue; }
            $name = ucwords(strtolower(trim($name)));

            // Normalise gender (accept m/f/male/female)
            $gNorm = strtolower(trim($gender));
            $gender = match(true) {
                in_array($gNorm,['m','male','boy'])   => 'Male',
                in_array($gNorm,['f','female','girl'])=> 'Female',
                default                               => 'Male'
            };

            // Normalise residency
            $rNorm = strtolower(trim($res));
            $res = match(true) {
                in_array($rNorm,['boarding','boarder','board'])=> 'Boarder',
                default => 'Day',
            };

            // Normalise program — map common abbreviations
            $prog = trim($prog) ?: 'General';

            // Normalise date of birth
            $dobParsed = null;
            if ($dob) {
                $ts = strtotime($dob);
                if ($ts) $dobParsed = date('Y-m-d', $ts);
            }

            try {
                $pdo->prepare("
                    INSERT INTO students (index_number, full_name, gender, program, residency, aggregate, enrolment_code, date_of_birth)
                    VALUES (?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                      full_name=VALUES(full_name), gender=VALUES(gender), program=VALUES(program),
                      residency=VALUES(residency), aggregate=VALUES(aggregate),
                      enrolment_code=VALUES(enrolment_code),
                      date_of_birth=COALESCE(VALUES(date_of_birth), date_of_birth)
                ")->execute([$index, $name, $gender, $prog, $res, $agg, $code, $dobParsed]);

                // Also save phone if we got it (optional extra column; column ensured before the loop)
                if ($phone) {
                    $pdo->prepare("UPDATE students SET phone_number=? WHERE index_number=?")->execute([$phone, $index]);
                }

                // Save custom columns
                foreach ($customColumns as $colName => $csvIdx) {
                    $val = trim($data[$csvIdx] ?? '');
                    try {
                        $pdo->prepare("UPDATE students SET `$colName`=? WHERE index_number=?")->execute([$val, $index]);
                    } catch (Exception $e) {}
                }

                $imported++;
            } catch (PDOException $e) {
                $errors[] = "Row $row ($index): " . $e->getMessage();
                $skipped++;
            }
        }
        fclose($handle);

        logAction('admin', $_SESSION['admin_id'], 'csv_import', "Imported:$imported Skipped:$skipped");
        $importResult = compact('imported','skipped','errors','headers');
    }
    // Redirect (POST/Redirect/GET) so refreshing this page afterwards never
    // resubmits the CSV upload — that resubmission would both hit "Invalid
    // request token" (the old POST body's CSRF token is already rotated
    // server-side) and risk re-importing the same file a second time.
    // $importResult is a structured report (counts + per-row errors), too
    // rich for the plain-string setFlash()/getFlash() helpers, so it's
    // stashed directly in the session instead.
    $_SESSION['import_result'] = $importResult;
    redirect(BASE_URL . '/admin/import.php');
}

$importResult = $_SESSION['import_result'] ?? null;
unset($_SESSION['import_result']);

$logoPath  = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav = 'import';
$topbarTitle = '<i class="fa-solid fa-file-import"></i> Import Placement Data';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Import Data | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    .upload-zone {
      border:2px dashed var(--border); border-radius:12px; padding:2.5rem 1.5rem;
      text-align:center; cursor:pointer; transition:all 0.25s; background:var(--bg-input);
    }
    .upload-zone:hover, .upload-zone.has-file { border-color:var(--primary); background:rgba(0,111,160,0.05); }
    .upload-zone .icon { font-size:2.5rem; margin-bottom:0.75rem; display:block; }
    .upload-zone strong { display:block; color:var(--text-primary); font-size:0.9rem; margin-bottom:0.25rem; }
    .upload-zone small { color:var(--text-secondary); font-size:0.78rem; }
    .result-num { text-align:center; padding:1.25rem 0.5rem; }
    .result-num .big { font-size:2.5rem; font-weight:900; line-height:1; }
    .result-num .lbl { font-size:0.72rem; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:0.25rem; }
    .err-list { background:rgba(230,57,70,0.07); border:1px solid rgba(230,57,70,0.2);
      border-radius:8px; padding:0.75rem; max-height:220px; overflow-y:auto; margin-top:0.75rem; }
    .err-list div { font-size:0.78rem; color:#e63946; padding:0.15rem 0; border-bottom:1px solid rgba(230,57,70,0.1); }
    .col-guide { border-collapse:collapse; width:100%; font-size:0.8rem; }
    .col-guide td { padding:0.4rem 0.5rem; border-bottom:1px solid var(--border-light); vertical-align:top; }
    .col-guide td:first-child { color:var(--primary-light); font-weight:700; white-space:nowrap; }
    .col-guide td:nth-child(2) { color:var(--text-primary); font-weight:600; }
    .col-guide td:last-child { color:var(--text-secondary); font-size:0.73rem; }
    .hint-box { background:rgba(0,111,160,0.08); border:1px solid rgba(0,111,160,0.2); border-radius:8px; padding:0.75rem; margin-top:0.75rem; font-size:0.79rem; color:var(--text-secondary); line-height:1.65; }
    .imp-grid { display:grid; grid-template-columns:1fr 380px; gap:1.25rem; }
    @media(max-width:860px) { .imp-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<div class="admin-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-body">

      <!-- Results -->
      <?php if ($importResult !== null): ?>
      <div class="admin-card" style="margin-bottom:1.25rem;">
        <div class="admin-card-header"><h6><i class="fa-solid fa-chart-column"></i> Import Results</h6></div>
        <div class="admin-card-body">
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:1rem;">
            <div class="result-num">
              <div class="big" style="color:var(--success);"><?= $importResult['imported'] ?></div>
              <div class="lbl">Imported / Updated</div>
            </div>
            <div class="result-num">
              <div class="big" style="color:var(--danger);"><?= $importResult['skipped'] ?></div>
              <div class="lbl">Skipped</div>
            </div>
            <div class="result-num">
              <div class="big" style="color:var(--primary-light);"><?= $importResult['imported'] + $importResult['skipped'] ?></div>
              <div class="lbl">Total Rows</div>
            </div>
          </div>
          <?php if (!empty($importResult['errors'])): ?>
          <div style="font-size:0.82rem;font-weight:700;color:var(--danger);margin-bottom:0.35rem;">
            ⚠ <?= count($importResult['errors']) ?> rows had issues (showing first 30):
          </div>
          <div class="err-list">
            <?php foreach (array_slice($importResult['errors'], 0, 30) as $e): ?>
            <div>• <?= htmlspecialchars($e) ?></div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div style="background:rgba(45,198,83,0.1);border:1px solid rgba(45,198,83,0.25);border-radius:8px;padding:0.75rem;color:var(--success);font-size:0.85rem;font-weight:600;">
            <i class="fa-solid fa-circle-check"></i> All rows imported successfully with no errors!
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="imp-grid">
        <!-- Upload Panel -->
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-file-csv"></i> Upload CSV File</h6></div>
          <div class="admin-card-body">
            <form method="POST" enctype="multipart/form-data" id="importForm">
              <?= csrfField() ?>
              <input type="hidden" name="import_csv" value="1">

              <div class="upload-zone" id="dropZone">
                <span class="icon" id="uploadIcon"><i class="fa-solid fa-file-csv"></i></span>
                <strong id="fileLabel">Click or drag &amp; drop your CSV file here</strong>
                <small id="fileName">Accepts .csv files from Excel or Google Sheets</small>
              </div>
              <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" style="display:none;">

              <div style="margin-top:1.25rem;display:flex;gap:0.75rem;flex-wrap:wrap;">
                <button type="submit" class="btn-admin btn-admin-primary" id="importBtn" style="padding:0.7rem 1.75rem;" disabled>
                  <i class="fa-solid fa-file-import"></i> Import Now
                </button>
                <a href="<?= BASE_URL ?>/admin/students.php" class="btn-admin" style="background:var(--bg-input);color:var(--text-secondary);padding:0.7rem 1.25rem;border:1px solid var(--border-light);border-radius:8px;text-decoration:none;">
                  <i class="fa-solid fa-users"></i> View All Students
                </a>
              </div>
            </form>
          </div>
        </div>

        <!-- CSV Guide -->
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-circle-info"></i> CSV Format Guide</h6></div>
          <div class="admin-card-body">
            <p style="font-size:0.82rem;color:var(--text-secondary);margin-bottom:0.75rem;line-height:1.6;">
              The importer <strong style="color:var(--text-primary);">auto-detects columns</strong> by name — no fixed order required.
              These column names are recognised:
            </p>
            <table class="col-guide">
              <tr><td>Index No</td>        <td>index_no, INDEX NO</td>      <td>Student index number</td></tr>
              <tr><td>Names</td>           <td>names, full_name, name</td>   <td>Student full name</td></tr>
              <tr><td>Gender</td>          <td>gender, sex</td>              <td>male / female / m / f</td></tr>
              <tr><td>Department</td>      <td>department, programme</td>    <td>Programme name</td></tr>
              <tr><td>Status</td>          <td>status, residency</td>        <td>Day / Boarding</td></tr>
              <tr><td>DOB</td>             <td>dob, date_of_birth</td>       <td>Date of birth (optional)</td></tr>
              <tr><td>Phone No</td>        <td>phone_no, phone</td>          <td>Contact number (optional)</td></tr>
              <tr><td>Aggregate</td>       <td>aggregate, agg</td>           <td>BECE aggregate (optional)</td></tr>
            </table>
            <div class="hint-box">
              <i class="fa-solid fa-circle-info"></i> <strong>First row</strong> is always treated as a header and skipped.<br>
              Existing students (matched by index number) will be <strong>updated</strong>, not duplicated.<br>
              Extra CSV columns not listed above are <strong>safely ignored</strong>.
            </div>
            <a href="data:text/csv;charset=utf-8,INDEX%20NO%2CNAMES%2CGENDER%2CDOB%2CPHONE%20NO%2CDEPARTMENT%2CSTATUS%0A41700600925%2CBENTUM%20REBECCA%2Cfemale%2C2009-09-24%2C0244000001%2CBUILDING%20CONSTRUCTION%2CDay%0A41700803325%2COGLIA%20BANDINI%2Cmale%2C2006-10-17%2C0244000002%2CELECTRICAL%20ENGINEERING%2CBoarding"
               download="sample_cdti_placement.csv"
               class="btn-admin btn-admin-primary btn-admin-sm" style="margin-top:0.85rem;display:inline-block;">
              <i class="fa-solid fa-download"></i> Download Sample CSV (matching your file format)
            </a>
          </div>
        </div>
      </div>

    </div>
  </main>
</div>
<script nonce="<?= generateCspNonce() ?>">
function fileChosen(input) {
  if (!input.files[0]) return;
  document.getElementById('fileLabel').textContent = '✅ ' + input.files[0].name;
  document.getElementById('fileName').textContent  = (input.files[0].size/1024).toFixed(1) + ' KB — ready to import';
  document.getElementById('uploadIcon').textContent = '✅';
  document.getElementById('dropZone').classList.add('has-file');
  document.getElementById('importBtn').disabled = false;
}
function handleDrop(e) {
  e.preventDefault();
  const dt = e.dataTransfer;
  if (dt.files.length) {
    document.getElementById('csv_file').files = dt.files;
    fileChosen(document.getElementById('csv_file'));
  }
}

// Wired up here instead of inline onclick/ondragover/ondragleave/ondrop/onchange
// attributes, which CSP's nonce'd script-src always blocks regardless of nonce.
const dropZoneEl = document.getElementById('dropZone');
dropZoneEl.addEventListener('click', function () {
  document.getElementById('csv_file').click();
});
dropZoneEl.addEventListener('dragover', function (e) {
  e.preventDefault();
  this.style.borderColor = 'var(--primary)';
});
dropZoneEl.addEventListener('dragleave', function () {
  this.style.borderColor = '';
});
dropZoneEl.addEventListener('drop', handleDrop);
document.getElementById('csv_file').addEventListener('change', function () { fileChosen(this); });
</script>
</body>
</html>
