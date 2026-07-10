<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/secrets.php';
requireAdminAuth();
requirePermission('settings');
// Emit CSP header before any output — must be called before topbar.php
emitCspHeader();

$pdo   = getDB();
$s     = getSettings();
$flash = getFlash('settings_flash');
$error = getFlash('settings_error');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        // Redirect (POST/Redirect/GET) so refreshing this page afterwards
        // never resubmits the stale form — that resubmission is exactly what
        // was causing "Invalid request token" on refresh, since the CSRF
        // token in that old POST body had already been rotated server-side.
        setFlash('settings_error', 'Invalid request token. Please try again.');
        redirect(BASE_URL . '/admin/settings');
    } else {
        $logoPath   = $s['school_logo_path'] ?? '';
        $sigPath    = $s['principal_signature_path'] ?? '';
        $stampPath  = $s['school_stamp_path'] ?? '';
        $bondPath   = $s['bond_form_path'] ?? '';
        $prospPath  = $s['school_prospectus_path'] ?? '';
        $letterheadPath = $s['letterhead_header_path'] ?? '';
        $houseMasterSigPath = $s['house_master_signature_path'] ?? '';
        $uploadDir = APP_ROOT . '/uploads/settings/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $imgMimes = ['image/png', 'image/jpeg', 'image/jpg', 'image/gif', 'image/webp'];
        $uploadFile = function(string $field, string $prefix, array $mimes, string $current) use ($uploadDir): string {
            if (empty($_FILES[$field]['tmp_name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return $current;
            $mime = mime_content_type($_FILES[$field]['tmp_name']);
            if (!in_array($mime, $mimes)) return $current;
            $ext  = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
            $fn   = $prefix . '_' . time() . '.' . $ext;
            $dest = $uploadDir . $fn;
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
                if ($current && file_exists(APP_ROOT . '/' . $current)) @unlink(APP_ROOT . '/' . $current);
                return 'uploads/settings/' . $fn;
            }
            return $current;
        };

        $logoPath  = $uploadFile('school_logo',    'school_logo',   $imgMimes, $logoPath);
        $sigPath   = $uploadFile('principal_sig',   'principal_sig', ['image/png','image/jpeg','image/jpg'], $sigPath);
        $stampPath = $uploadFile('school_stamp',    'school_stamp',  ['image/png','image/jpeg','image/jpg'], $stampPath);
        $bondPath  = $uploadFile('bond_form_pdf',   'bond_form',     ['application/pdf'], $bondPath);
        $prospPath = $uploadFile('school_prospectus','school_prospectus',['application/pdf'], $prospPath);
        $letterheadPath = $uploadFile('letterhead_header', 'letterhead_header', $imgMimes, $letterheadPath);
        $houseMasterSigPath = $uploadFile('house_master_sig', 'house_master_sig', ['image/png','image/jpeg','image/jpg'], $houseMasterSigPath);

        $deptKeys  = ['fashion','building','electrical','computer','home_economics','welding'];
        $deptPaths = [];
        foreach ($deptKeys as $dk) {
            $deptPaths[$dk] = $uploadFile("dept_{$dk}_pdf","tools_{$dk}",['application/pdf'],$s["dept_tools_{$dk}_path"] ?? '');
        }

        $map = [
            'school_name'              => sanitize($_POST['school_name'] ?? ''),
            'school_address'           => sanitize($_POST['school_address'] ?? ''),
            'school_phone'             => sanitize($_POST['school_phone'] ?? ''),
            'school_email'             => sanitize($_POST['school_email'] ?? ''),
            'academic_year'            => sanitize($_POST['academic_year'] ?? ''),
            'cssps_year'               => sanitize($_POST['cssps_year'] ?? ''),
            'principal_name'           => sanitize($_POST['principal_name'] ?? ''),
            'house_master_name'        => sanitize($_POST['house_master_name'] ?? ''),
            'reporting_date'           => sanitize($_POST['reporting_date'] ?? ''),
            'helpline_number'          => sanitize($_POST['helpline_number'] ?? ''),
            'school_logo_path'         => $logoPath,
            'principal_signature_path' => $sigPath,
            'school_stamp_path'        => $stampPath,
            'bond_form_path'           => $bondPath,
            'school_prospectus_path'   => $prospPath,
            'letterhead_header_path'   => $letterheadPath,
            'house_master_signature_path' => $houseMasterSigPath,
            'admission_fee'            => number_format((float)($_POST['admission_fee'] ?? 50), 2, '.', ''),
            'payment_enabled'          => isset($_POST['payment_enabled']) ? '1' : '0',
            'paystack_public_key'      => sanitize($_POST['paystack_public_key'] ?? ''),
            'paystack_secret_key'      => sanitize($_POST['paystack_secret_key'] ?? ''), // overwritten below
            'hubtel_client_id'         => sanitize($_POST['hubtel_client_id'] ?? ''),    // overwritten below
            'hubtel_client_secret'     => sanitize($_POST['hubtel_client_secret'] ?? ''), // overwritten below
            'hubtel_sender_id'         => sanitize($_POST['hubtel_sender_id'] ?? ''),
            'school_whatsapp_link'     => sanitize($_POST['school_whatsapp_link'] ?? ''),
            'sms_congratulations_template' => sanitize($_POST['sms_congratulations_template'] ?? ''),
        ];
        foreach ($deptKeys as $dk) { $map["dept_tools_{$dk}_path"] = $deptPaths[$dk]; }

        // S01+S02: Encrypt sensitive API credentials before storing in DB.
        // If the submitted value is blank, keep the existing stored value unchanged.
        // If the submitted value equals the masked display value, also keep existing.
        $sensitiveKeys = ['paystack_secret_key', 'hubtel_client_id', 'hubtel_client_secret'];
        foreach ($sensitiveKeys as $sk) {
            $submitted = trim($_POST[$sk] ?? '');
            $existing  = $s[$sk] ?? '';
            $existingPlain = decryptSecret($existing);
            // Blank or unchanged masked value — keep existing stored (possibly encrypted) value
            if ($submitted === '' || $submitted === maskSecret($existingPlain)) {
                $map[$sk] = $existing;
            } else {
                $map[$sk] = encryptSecret($submitted);
            }
        }

        $upsert = $pdo->prepare("INSERT INTO system_settings (setting_key,setting_val) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_val=?");
        foreach ($map as $k => $v) { $upsert->execute([$k,$v,$v]); }
        logAction('admin', $_SESSION['admin_id'], 'settings_updated', 'System settings saved');
        // Redirect (POST/Redirect/GET) — see note above the CSRF-failure branch.
        setFlash('settings_flash', 'Settings saved successfully!');
        redirect(BASE_URL . '/admin/settings');
    }
}

$logoPath    = $s['school_logo_path'] ?? '';
$sigPath     = $s['principal_signature_path'] ?? '';
$stampPath   = $s['school_stamp_path'] ?? '';
$bondPath    = $s['bond_form_path'] ?? '';
$prospPath   = $s['school_prospectus_path'] ?? '';
$letterheadPath = $s['letterhead_header_path'] ?? '';
$houseMasterSigPath = $s['house_master_signature_path'] ?? '';
$activeNav   = 'settings';
$topbarTitle = '<i class="fa-solid fa-gears"></i> System Settings';
$depts = [
    'fashion'        => '<i class="fa-solid fa-scissors"></i> Fashion Design Technology',
    'building'       => '<i class="fa-solid fa-trowel-bricks"></i> Building & Construction',
    'electrical'     => '<i class="fa-solid fa-bolt"></i> Electrical Engineering',
    'computer'       => '<i class="fa-solid fa-laptop-code"></i> Computer Hardware & Networking',
    'home_economics' => '<i class="fa-solid fa-utensils"></i> Home Economics & Hospitality',
    'welding'        => '<i class="fa-solid fa-screwdriver-wrench"></i> Welding & Fabrication',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Settings | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath ?: 'assets/img/logo.png') ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    /* Settings-specific extras */
    .section-num {
      display: inline-flex; align-items: center; justify-content: center;
      width: 26px; height: 26px; border-radius: 50%;
      background: var(--primary); color: #fff;
      font-size: 0.72rem; font-weight: 800; flex-shrink: 0;
    }
    .tab-nav {
      display: flex; gap: 0.35rem; flex-wrap: wrap;
      background: var(--bg-card); border: 1px solid var(--border-light);
      border-radius: 12px; padding: 0.4rem; margin-bottom: 1.5rem;
    }
    .tab-btn {
      flex: 1; min-width: 120px; padding: 0.6rem 0.75rem;
      border: none; border-radius: 8px; cursor: pointer;
      font-size: 0.8rem; font-weight: 700; font-family: inherit;
      color: var(--text-secondary); background: transparent;
      transition: all 0.2s; text-align: center; white-space: nowrap;
    }
    .tab-btn:hover { background: var(--bg-input); color: var(--text-primary); }
    .tab-btn.active { background: var(--primary); color: #fff; box-shadow: 0 4px 12px rgba(0,111,160,0.4); }
    .tab-pane { display: none; }
    .tab-pane.active { display: block; }
    .save-bar {
      position: sticky; bottom: 0; z-index: 50;
      background: var(--bg-surface);
      border-top: 1px solid var(--border-light);
      padding: 1rem 1.75rem;
      display: flex; align-items: center; justify-content: space-between;
      backdrop-filter: blur(10px);
    }
    .save-bar span { color: var(--text-secondary); font-size: 0.82rem; }
  </style>
</head>
<body>
<div class="admin-layout">

  <?php include __DIR__ . '/includes/sidebar.php'; ?>

  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <div class="page-body">

      <!-- Alerts -->
      <?php if ($flash): ?>
      <div class="alert-success">&#9989; <?= htmlspecialchars($flash) ?></div>
      <?php endif; ?>
      <?php if ($error): ?>
      <div class="alert-error">&#10060; <?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" id="settingsForm">
        <?= csrfField() ?>

        <!-- Tab Navigation -->
        <div class="tab-nav" role="tablist">
          <button type="button" class="tab-btn active" data-tab="school"   id="tab-school"><i class="fa-solid fa-school"></i> School Info</button>
          <button type="button" class="tab-btn"        data-tab="uploads"  id="tab-uploads"><i class="fa-solid fa-cloud-arrow-up"></i> Uploads</button>
          <button type="button" class="tab-btn"        data-tab="depts"    id="tab-depts"><i class="fa-solid fa-screwdriver-wrench"></i> Dept Tools</button>
          <button type="button" class="tab-btn"        data-tab="payment"  id="tab-payment"><i class="fa-solid fa-credit-card"></i> Payment</button>
          <button type="button" class="tab-btn"        data-tab="sms"      id="tab-sms"><i class="fa-solid fa-comment-sms"></i> SMS</button>
        </div>

        <!-- 1. School Information -->
        <div class="tab-pane active" id="pane-school">
          <div class="settings-section">
            <h3><span class="section-num">1</span> School Information</h3>
            <div class="form-row">
              <div class="form-group">
                <label>School Full Name *</label>
                <input type="text" name="school_name" value="<?= htmlspecialchars($s['school_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label>Academic Year *</label>
                <input type="text" name="academic_year" value="<?= htmlspecialchars($s['academic_year'] ?? '') ?>" placeholder="2026/2027">
              </div>
            </div>
            <div class="form-row three">
              <div class="form-group">
                <label>Physical Address</label>
                <input type="text" name="school_address" value="<?= htmlspecialchars($s['school_address'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="school_phone" value="<?= htmlspecialchars($s['school_phone'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="school_email" value="<?= htmlspecialchars($s['school_email'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row three">
              <div class="form-group">
                <label>Principal / Head's Name</label>
                <input type="text" name="principal_name" value="<?= htmlspecialchars($s['principal_name'] ?? '') ?>">
              </div>
              <div class="form-group">
                <label>Reporting Date (for letter)</label>
                <input type="text" name="reporting_date" value="<?= htmlspecialchars($s['reporting_date'] ?? '') ?>" placeholder="15th September, 2026">
              </div>
              <div class="form-group">
                <label>Helpline / Contact Number</label>
                <input type="text" name="helpline_number" value="<?= htmlspecialchars($s['helpline_number'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>CSSPS Year</label>
                <input type="text" name="cssps_year" value="<?= htmlspecialchars($s['cssps_year'] ?? date('Y')) ?>">
              </div>
              <div class="form-group">
                <label>Snr. House Master's Name</label>
                <input type="text" name="house_master_name" value="<?= htmlspecialchars($s['house_master_name'] ?? '') ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- 2. Uploads -->
        <div class="tab-pane" id="pane-uploads">
          <div class="settings-section">
            <h3><span class="section-num">2</span> School Logo</h3>
            <div class="form-group">
              <label>School Logo <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(PNG / JPG &mdash; shown on all pages &amp; PDF documents)</small></label>
              <input type="file" name="school_logo" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp" style="width:auto;">
              <div class="upload-preview">
                <?php if ($logoPath): ?>
                  <img src="<?= asset($logoPath) ?>" alt="Logo">
                  <span class="ok">&#9989; Logo uploaded &amp; active</span>
                <?php else: ?>
                  <span class="no-file">No logo uploaded &mdash; default placeholder used</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">2b</span> Letterhead Header Image</h3>
            <div class="form-group">
              <label>Letterhead Banner <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(PNG / JPG &mdash; full official letterhead graphic; shown at the top of the Admission Letter &amp; Bond/Undertaking Form instead of the logo + text block)</small></label>
              <input type="file" name="letterhead_header" accept="image/png,image/jpeg,image/jpg,image/gif,image/webp" style="width:auto;">
              <div class="upload-preview">
                <?php if ($letterheadPath): ?>
                  <img src="<?= asset($letterheadPath) ?>" alt="Letterhead">
                  <span class="ok">&#9989; Letterhead uploaded &amp; active</span>
                <?php else: ?>
                  <span class="no-file">No letterhead uploaded &mdash; logo + school name/address text is used instead</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">3</span> Principal Signature</h3>
            <div class="form-group">
              <label>Signature Image <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(PNG preferred &mdash; shown on Admission Letter)</small></label>
              <input type="file" name="principal_sig" accept="image/png,image/jpeg,image/jpg" style="width:auto;">
              <div class="upload-preview">
                <?php if ($sigPath): ?>
                  <img src="<?= asset($sigPath) ?>" alt="Signature">
                  <span class="ok">&#9989; Signature uploaded</span>
                <?php else: ?>
                  <span class="no-file">No signature &mdash; a blank line is shown on the letter</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">3b</span> Snr. House Master Signature</h3>
            <div class="form-group">
              <label>Signature Image <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(PNG preferred &mdash; shown on the Bond/Undertaking Form)</small></label>
              <input type="file" name="house_master_sig" accept="image/png,image/jpeg,image/jpg" style="width:auto;">
              <div class="upload-preview">
                <?php if ($houseMasterSigPath): ?>
                  <img src="<?= asset($houseMasterSigPath) ?>" alt="Snr. House Master Signature">
                  <span class="ok">&#9989; Signature uploaded</span>
                <?php else: ?>
                  <span class="no-file">No signature &mdash; a blank line is shown on the bond form</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">4</span> School Stamp</h3>
            <div class="form-group">
              <label>Stamp Image <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(PNG / JPG &mdash; shown on Admission Letter &amp; Bond Form)</small></label>
              <input type="file" name="school_stamp" accept="image/png,image/jpeg,image/jpg" style="width:auto;">
              <div class="upload-preview">
                <?php if ($stampPath): ?>
                  <img src="<?= asset($stampPath) ?>" alt="Stamp">
                  <span class="ok">&#9989; Stamp uploaded</span>
                <?php else: ?>
                  <span class="no-file">No stamp &mdash; "STAMP" placeholder shown on documents</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">5</span> Bond Form PDF <small style="font-size:0.75rem;font-weight:400;text-transform:none;color:var(--text-muted);">(Optional override)</small></h3>
            <div class="form-group">
              <label>Custom Bond Form PDF <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(If uploaded, this replaces the auto-generated bond form for all students)</small></label>
              <input type="file" name="bond_form_pdf" accept="application/pdf" style="width:auto;">
              <div class="upload-preview">
                <?php if ($bondPath): ?>
                  <span class="ok">&#9989; Custom bond PDF uploaded &nbsp;&mdash;&nbsp;</span>
                  <a href="<?= asset($bondPath) ?>" target="_blank">&#128196; Preview</a>
                <?php else: ?>
                  <span class="no-file">No custom PDF &mdash; auto-generated bond form is used (recommended)</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="settings-section">
            <h3><span class="section-num">6</span> School Prospectus PDF</h3>
            <div class="form-group">
              <label>Official Prospectus PDF <small style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--text-muted);">(Optional &mdash; students always get the personalised auto-generated version)</small></label>
              <input type="file" name="school_prospectus" accept="application/pdf" style="width:auto;">
              <div class="upload-preview">
                <?php if ($prospPath): ?>
                  <span class="ok">&#x2705; Prospectus PDF uploaded &nbsp;&mdash;&nbsp;</span>
                  <a href="<?= asset($prospPath) ?>" target="_blank">&#128196; Preview</a>
                <?php else: ?>
                  <span class="no-file">No PDF uploaded &mdash; students download the auto-generated personalised prospectus</span>
                <?php endif; ?>
              </div>
              <div style="background:rgba(0,111,160,0.08);border:1px solid rgba(0,111,160,0.2);border-radius:8px;padding:0.75rem;margin-top:0.75rem;font-size:0.79rem;color:var(--text-secondary);line-height:1.65;">
                &#x2139;&#xFE0F; The auto-generated prospectus always shows student name, programme, residency + items lists. Department Tools PDFs are uploaded per department in the Dept Tools tab.
              </div>
            </div>
          </div>
        </div><!-- /pane-uploads -->

        <!-- 6. Department Tools -->
        <div class="tab-pane" id="pane-depts">
          <div class="settings-section">
            <h3><span class="section-num">6</span> Department Tools & Equipment PDFs</h3>
            <p style="font-size:0.83rem;color:var(--text-secondary);margin-bottom:1.5rem;line-height:1.7;">
              Upload a PDF items &amp; equipment list for each department. Students download their programme-specific version from the <strong style="color:var(--text-primary);">Prospectus</strong>.
              If no PDF is uploaded for a department, a built-in default items list is shown automatically.
            </p>
            <div class="dept-grid">
              <?php foreach ($depts as $dk => $dlabel):
                $existing = $s["dept_tools_{$dk}_path"] ?? '';
              ?>
              <div class="dept-item">
                <label><?= $dlabel ?></label>
                <input type="file" name="dept_<?= $dk ?>_pdf" accept="application/pdf">
                <?php if ($existing): ?>
                  <a href="<?= asset($existing) ?>" target="_blank" class="pdf-link">&#128196; View uploaded PDF</a>
                <?php else: ?>
                  <span class="no-pdf">Using built-in default items list</span>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- 7. Payment -->
        <div class="tab-pane" id="pane-payment">
          <div class="settings-section">
            <h3><span class="section-num">7</span> Payment Gateway &mdash; Paystack</h3>

            <div class="form-row">
              <div class="form-group">
                <label>Admission Fee Amount (GHS)</label>
                <input type="number" name="admission_fee" value="<?= htmlspecialchars($s['admission_fee'] ?? '50') ?>" min="0" step="0.01">
              </div>
              <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:0.15rem;">
                <div class="toggle-wrap">
                  <input type="checkbox" id="payment_enabled" name="payment_enabled" <?= ($s['payment_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                  <label for="payment_enabled">Require payment before student can access registration form</label>
                </div>
              </div>
            </div>

            <div class="form-row" style="margin-top:0.5rem;">
              <div class="form-group">
                <label>Paystack Public Key</label>
                <input type="text" name="paystack_public_key" value="<?= htmlspecialchars($s['paystack_public_key'] ?? '') ?>" placeholder="pk_live_xxxxxxxxxxxxxxxx" autocomplete="off">
              </div>
              <div class="form-group">
                <label>Paystack Secret Key</label>
                <input type="password" name="paystack_secret_key"
                       value="<?= htmlspecialchars(maskSecret(decryptSecret($s['paystack_secret_key'] ?? ''))) ?>"
                       placeholder="sk_live_xxxxxxxxxxxxxxxx" autocomplete="new-password">
                <div style="margin-top:0.35rem;">
                  <label style="display:flex;align-items:center;gap:0.4rem;text-transform:none;letter-spacing:0;font-weight:400;font-size:0.78rem;cursor:pointer;">
                    <input type="checkbox" id="showSecretKey" style="width:14px;height:14px;"> Show secret key
                  </label>
                  <small style="color:var(--text-muted);font-size:0.72rem;">Leave blank or unchanged to keep the existing key.</small>
                </div>
              </div>
            </div>

            <div class="info-box yellow">
              &#128161; Get your Paystack keys from
              <a href="https://dashboard.paystack.com/#/settings/developer" target="_blank">dashboard.paystack.com</a>.
              Use <code>pk_test_</code> / <code>sk_test_</code> for testing, <code>pk_live_</code> / <code>sk_live_</code> for production.
              Payments are processed in <strong>GHS (Ghana Cedis)</strong>.
            </div>
          </div>
        </div>

        <!-- 8. SMS -->
        <div class="tab-pane" id="pane-sms">
          <div class="settings-section">
            <h3><span class="section-num">8</span> SMS Notifications &mdash; Hubtel</h3>
            <p style="font-size:0.83rem;color:var(--text-secondary);margin-bottom:1.25rem;line-height:1.7;">
              Students &amp; guardians receive an SMS after payment and after successful registration. Leave these fields blank to disable SMS notifications.
            </p>
            <div class="form-row three">
              <div class="form-group">
                <label>Hubtel Client ID</label>
                <input type="text" name="hubtel_client_id"
                       value="<?= htmlspecialchars(maskSecret(decryptSecret($s['hubtel_client_id'] ?? ''))) ?>"
                       placeholder="xxxxxxxx" autocomplete="off">
                <small style="color:var(--text-muted);font-size:0.72rem;">Leave blank or unchanged to keep the existing ID.</small>
              </div>
              <div class="form-group">
                <label>Hubtel Client Secret</label>
                <input type="password" name="hubtel_client_secret"
                       value="<?= htmlspecialchars(maskSecret(decryptSecret($s['hubtel_client_secret'] ?? ''))) ?>"
                       placeholder="xxxxxxxx" autocomplete="new-password">
                <small style="color:var(--text-muted);font-size:0.72rem;">Leave blank or unchanged to keep the existing secret.</small>
              </div>
              <div class="form-group">
                <label>SMS Sender ID <small style="text-transform:none;font-weight:400;">(max 11 chars)</small></label>
                <input type="text" name="hubtel_sender_id" value="<?= htmlspecialchars($s['hubtel_sender_id'] ?? 'CDTI') ?>" maxlength="11" placeholder="CDTI">
              </div>
            </div>
            <div class="form-row" style="margin-top: 1rem;">
              <div class="form-group" style="flex: 1 1 100%;">
                <label>Parent WhatsApp Group Link</label>
                <input type="text" name="school_whatsapp_link" value="<?= htmlspecialchars($s['school_whatsapp_link'] ?? '') ?>" placeholder="https://chat.whatsapp.com/...">
              </div>
            </div>
            <div class="form-row" style="margin-top: 1rem;">
              <div class="form-group" style="flex: 1 1 100%;">
                <label>Congratulations SMS Template</label>
                <textarea name="sms_congratulations_template" rows="4" style="width:100%; padding:0.55rem; border-radius:6px; background:#0f1e2d; border:1px solid rgba(0,111,160,0.3); color:#fff; font-family:inherit; font-size:0.85rem;" placeholder="Dear parent, {name}'s admission registration for {school} ({program}) was submitted. Join WhatsApp: {whatsapp_link}"><?= htmlspecialchars($s['sms_congratulations_template'] ?? "Dear Parent/Guardian, {name}'s admission registration for {school} ({program}) has been submitted successfully. Please download all required documents from the portal and report on {date}. Join the Parent WhatsApp Group: {whatsapp_link}. Helpline: {helpline}") ?></textarea>
                <div style="font-size:0.75rem; color:var(--text-secondary); margin-top:0.4rem; line-height: 1.45;">
                  Available placeholders: <code>{name}</code> (student's full name), <code>{school}</code> (school name), <code>{program}</code> (program of study), <code>{date}</code> (reporting date), <code>{whatsapp_link}</code> (parent WhatsApp link), <code>{helpline}</code> (school helpline).
                </div>
              </div>
            </div>
            <div class="info-box blue">
              &#128161; Get your Hubtel API credentials from
              <a href="https://developers.hubtel.com/" target="_blank">developers.hubtel.com</a>.
              SMS is sent to both the father's and mother's phone numbers provided in the student's registration form.
            </div>
          </div>
        </div>

        <!-- Sticky Save Bar -->
        <div class="save-bar">
          <span>&#128161; All changes are saved across all tabs when you click Save.</span>
          <button type="submit" class="btn-save">&#128190; Save All Settings</button>
        </div>

      </form>
    </div><!-- /page-body -->
  </main>
</div>

<script nonce="<?= generateCspNonce() ?>">
// Tab switching
function switchTab(id) {
  document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('pane-' + id).classList.add('active');
  document.getElementById('tab-' + id).classList.add('active');
  sessionStorage.setItem('cdti_settings_tab', id);
}
// Wire up tab buttons (moved off inline onclick, which CSP's nonce'd script-src
// always blocks regardless of nonce placement).
document.querySelectorAll('.tab-btn').forEach(function (btn) {
  btn.addEventListener('click', function () { switchTab(this.dataset.tab); });
});
// Restore last active tab
(function() {
  const last = sessionStorage.getItem('cdti_settings_tab');
  if (last) switchTab(last);
})();
// Flash success - auto-dismiss after 4s
const alertEl = document.querySelector('.alert-success');
if (alertEl) setTimeout(() => { alertEl.style.opacity='0'; alertEl.style.transition='opacity 0.5s'; setTimeout(()=>alertEl.remove(),500); }, 4000);
// Show/hide Paystack secret key
const showSecretKeyEl = document.getElementById('showSecretKey');
if (showSecretKeyEl) {
  showSecretKeyEl.addEventListener('change', function () {
    this.closest('.form-group').querySelector('input[type=password]').type = this.checked ? 'text' : 'password';
  });
}
</script>
</body>
</html>
