<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
requirePermission('sms');
emitCspHeader();

$pdo = getDB();
$s   = getSettings();

// Dynamically create sms_templates table if it doesn't exist (ensures seamless deployment on Hostinger)
$pdo->exec("CREATE TABLE IF NOT EXISTS sms_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(100) NOT NULL UNIQUE,
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$flash = getFlash('sms_flash');
$error = getFlash('sms_error');

// ── Handle Post Actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'send_sms') {
            $message    = trim($_POST['message'] ?? '');
            $targetType = $_POST['target_type'] ?? '';
            $program    = $_POST['program'] ?? '';
            $studentId  = (int)($_POST['student_id'] ?? 0);

            if (empty($message)) {
                $error = 'Please enter a message to send.';
            } elseif (empty($targetType)) {
                $error = 'Please select a target audience.';
            } else {
                // Find target students
                $studentIds = [];
                if ($targetType === 'all') {
                    $stmt = $pdo->prepare("SELECT id FROM students WHERE registration_status = 'completed'");
                    $stmt->execute();
                    $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                } elseif ($targetType === 'program') {
                    if (empty($program)) {
                        $error = 'Please select a program.';
                    } else {
                        $stmt = $pdo->prepare("SELECT id FROM students WHERE program = ? AND registration_status = 'completed'");
                        $stmt->execute([$program]);
                        $studentIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    }
                } elseif ($targetType === 'student') {
                    if ($studentId <= 0) {
                        $error = 'Please select a student.';
                    } else {
                        $studentIds = [$studentId];
                    }
                }

                if (empty($error)) {
                    if (empty($studentIds)) {
                        $error = 'No registered students found matching the selected target.';
                    } else {
                        // Queue SMS to all matching parents
                        $smsCount = 0;
                        $stmtQueue = $pdo->prepare("INSERT INTO sms_queue (phone, message, status) VALUES (?, ?, 'pending')");
                        
                        foreach ($studentIds as $sid) {
                            $parentPhones = getStudentParentPhones($pdo, $sid);
                            foreach ($parentPhones as $phone) {
                                $stmtQueue->execute([$phone, $message]);
                                $smsCount++;
                            }
                        }

                        if ($smsCount > 0) {
                            logAction('admin', $_SESSION['admin_id'], 'bulk_sms_queued', "Target: $targetType | Messages: $smsCount");
                            $flash = "Successfully queued {$smsCount} SMS message(s) for sending in the background.";
                        } else {
                            $error = "No parent phone numbers could be found for the selected students.";
                        }
                    }
                }
            }
        } elseif ($action === 'save_template') {
            $title = trim($_POST['template_title'] ?? '');
            $msg   = trim($_POST['template_message'] ?? '');

            if (empty($title) || empty($msg)) {
                $error = 'Please enter both template title and message content.';
            } else {
                try {
                    $stmt = $pdo->prepare("INSERT INTO sms_templates (title, message) VALUES (?, ?) ON DUPLICATE KEY UPDATE message = ?");
                    $stmt->execute([$title, $msg, $msg]);
                    $flash = "Template '{$title}' saved successfully.";
                } catch (Exception $e) {
                    $error = "Failed to save template: " . $e->getMessage();
                }
            }
        } elseif ($action === 'delete_template') {
            $tid = (int)($_POST['template_id'] ?? 0);
            if ($tid > 0) {
                $stmt = $pdo->prepare("DELETE FROM sms_templates WHERE id = ?");
                $stmt->execute([$tid]);
                $flash = "Template deleted successfully.";
            }
        }
    }
    // Redirect (POST/Redirect/GET) so refreshing this page afterwards never
    // resubmits the form — that resubmission is what causes "Invalid request
    // token" on refresh, since the CSRF token in the old POST body has
    // already been rotated server-side by the time it's resent.
    if ($flash) { setFlash('sms_flash', $flash); }
    if ($error) { setFlash('sms_error', $error); }
    redirect(BASE_URL . '/admin/sms');
}

// ── Fetch Lists ──────────────────────────────────────────────────────────────
$programsList = $pdo->query("
    SELECT DISTINCT program 
    FROM students 
    WHERE program IS NOT NULL AND program != '' 
    ORDER BY program
")->fetchAll(PDO::FETCH_COLUMN);

$studentsList = $pdo->query("
    SELECT id, full_name, index_number, program 
    FROM students 
    WHERE registration_status = 'completed' 
    ORDER BY full_name
")->fetchAll();

$templatesList = $pdo->query("
    SELECT * FROM sms_templates 
    ORDER BY title ASC
")->fetchAll();

// Fetch SMS statistics
$smsStats = $pdo->query("
    SELECT 
      SUM(status='pending') as pending_count,
      SUM(status='sent') as sent_count,
      SUM(status='failed') as failed_count
    FROM sms_queue
")->fetch();

$pendingCount = (int)($smsStats['pending_count'] ?? 0);
$sentCount    = (int)($smsStats['sent_count'] ?? 0);
$failedCount  = (int)($smsStats['failed_count'] ?? 0);

// Pagination setup
$limit = 10;
$page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalSmsRecords = (int)$pdo->query("SELECT COUNT(*) FROM sms_queue")->fetchColumn();
$totalPages      = max(1, (int)ceil($totalSmsRecords / $limit));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

// Fetch SMS records for current page
$stmtSms = $pdo->prepare("
    SELECT * FROM sms_queue 
    ORDER BY id DESC 
    LIMIT ? OFFSET ?
");
$stmtSms->bindValue(1, $limit, PDO::PARAM_INT);
$stmtSms->bindValue(2, $offset, PDO::PARAM_INT);
$stmtSms->execute();
$recentSms = $stmtSms->fetchAll();

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'sms';
$topbarTitle = '<i class="fa-solid fa-message"></i> Send Bulk SMS';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Send Bulk SMS | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <style>
    .sms-grid {
      display: grid;
      grid-template-columns: 1.4fr 1fr;
      gap: 1.25rem;
    }
    @media(max-width: 992px) {
      .sms-grid {
        grid-template-columns: 1fr;
      }
    }
    .admin-input, .admin-select, textarea {
      width: 100%;
      padding: 0.75rem;
      border-radius: 6px;
      background: #0f1e2d;
      border: 1px solid rgba(0, 111, 160, 0.3);
      color: #fff;
      font-size: 0.9rem;
      font-family: inherit;
      box-sizing: border-box;
      transition: all 0.2s;
    }
    .admin-input:focus, .admin-select:focus, textarea:focus {
      outline: none;
      border-color: var(--primary-light);
      box-shadow: 0 0 10px rgba(0, 111, 160, 0.5);
    }
    .form-group {
      margin-bottom: 1.25rem;
    }
    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      font-size: 0.82rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-secondary);
      font-weight: 600;
    }
    .char-counter {
      text-align: right;
      font-size: 0.78rem;
      color: var(--text-secondary);
      margin-top: 0.4rem;
    }
    .btn-submit {
      background: linear-gradient(135deg, #006fa0, #0097d6);
      color: #fff;
      border: none;
      padding: 0.75rem 2rem;
      border-radius: 30px;
      font-weight: 700;
      font-size: 0.9rem;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 4px 15px rgba(0, 111, 160, 0.4);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .btn-submit:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 111, 160, 0.6);
    }
    .alert {
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-success {
      background: rgba(45, 198, 83, 0.15);
      border: 1px solid rgba(45, 198, 83, 0.3);
      color: #2dc653;
    }
    .alert-danger {
      background: rgba(220, 53, 69, 0.15);
      border: 1px solid rgba(220, 53, 69, 0.3);
      color: #ff4d4d;
    }
    .template-item {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.05);
      border-radius: 8px;
      padding: 1rem;
      margin-bottom: 1rem;
      transition: all 0.2s;
    }
    .template-item:hover {
      background: rgba(0, 111, 160, 0.05);
      border-color: rgba(0, 111, 160, 0.2);
    }
    .template-title {
      font-weight: 700;
      color: #fff;
      font-size: 0.9rem;
      margin-bottom: 0.35rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .template-body {
      font-size: 0.8rem;
      color: var(--text-secondary);
      line-height: 1.4;
      margin-bottom: 0.75rem;
    }
    .template-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
    }
    .btn-tpl-use {
      background: rgba(0, 111, 160, 0.2);
      border: 1px solid rgba(0, 111, 160, 0.4);
      color: #4dd8ff;
      padding: 0.3rem 0.8rem;
      border-radius: 20px;
      font-size: 0.72rem;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.2s;
    }
    .btn-tpl-use:hover {
      background: linear-gradient(135deg, #006fa0, #0097d6);
      color: #fff;
      border-color: transparent;
    }
    .btn-tpl-del {
      background: transparent;
      border: none;
      color: #ff4d4d;
      cursor: pointer;
      padding: 0.3rem 0.5rem;
      font-size: 0.85rem;
      transition: color 0.2s;
    }
    .btn-tpl-del:hover {
      color: #ff1a1a;
    }
    .badge-status {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 0.25rem 0.6rem;
      border-radius: 30px;
      font-size: 0.72rem;
      font-weight: 700;
    }
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
        <div class="alert alert-success" style="max-width: 1200px; margin: 0 auto 1.5rem auto;">
          <i class="fa-solid fa-circle-check"></i>
          <div><?= htmlspecialchars($flash) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-danger" style="max-width: 1200px; margin: 0 auto 1.5rem auto;">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <div><?= htmlspecialchars($error) ?></div>
        </div>
      <?php endif; ?>

      <!-- Send SMS and Templates Layout Grid -->
      <div class="sms-grid" style="max-width: 1200px; margin: 0 auto;">
        
        <!-- Left Column: SMS Composer -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h6><i class="fa-solid fa-paper-plane"></i> Send SMS Announcement</h6>
          </div>
          <div class="admin-card-body" style="padding: 2rem;">
            <form action="" method="POST" id="smsForm">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="send_sms">

              <!-- Message Body -->
              <div class="form-group">
                <label>Message Content</label>
                <textarea name="message" id="message" rows="5" placeholder="Type your PTA announcement, notification, or message here..." required></textarea>
                <div class="char-counter">
                  <span id="charCount">0</span> characters | <span id="smsPages">1</span> SMS page(s)
                </div>
              </div>

              <!-- Target Selection -->
              <div class="form-group">
                <label>Target Audience</label>
                <select name="target_type" id="target_type" class="admin-select" required>
                  <option value="">-- Select Audience --</option>
                  <option value="all" <?= isset($_POST['target_type']) && $_POST['target_type'] === 'all' ? 'selected' : '' ?>>All Parents</option>
                  <option value="program" <?= isset($_POST['target_type']) && $_POST['target_type'] === 'program' ? 'selected' : '' ?>>By Department / Program</option>
                  <option value="student" <?= isset($_POST['target_type']) && $_POST['target_type'] === 'student' ? 'selected' : '' ?>>Single Student's Parent</option>
                </select>
              </div>

              <!-- Program Filter -->
              <div class="form-group" id="program_group" style="display: none;">
                <label>Select Department / Program</label>
                <select name="program" class="admin-select">
                  <option value="">-- Select Program --</option>
                  <?php foreach ($programsList as $prog): ?>
                    <option value="<?= htmlspecialchars($prog) ?>" <?= isset($_POST['program']) && $_POST['program'] === $prog ? 'selected' : '' ?>>
                      <?= htmlspecialchars($prog) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Student Filter -->
              <div class="form-group" id="student_group" style="display: none;">
                <label>Search and Select Student</label>
                <input type="text" id="student_search" placeholder="Type student name or index number to filter..." class="admin-input" style="margin-bottom: 0.5rem;">
                <select name="student_id" id="student_select" class="admin-select">
                  <option value="">-- Select Student --</option>
                  <?php foreach ($studentsList as $st): ?>
                    <option value="<?= $st['id'] ?>" data-search="<?= strtolower(htmlspecialchars($st['full_name'] . ' ' . $st['index_number'])) ?>" <?= isset($_POST['student_id']) && (int)$_POST['student_id'] === (int)$st['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($st['full_name']) ?> (<?= htmlspecialchars($st['index_number']) ?>) - <?= htmlspecialchars($st['program']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Submit Button -->
              <div style="margin-top: 2rem;">
                <button type="submit" class="btn-submit">
                  <i class="fa-solid fa-paper-plane"></i> Queue Message
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- Right Column: SMS Templates -->
        <div class="admin-card">
          <div class="admin-card-header">
            <h6><i class="fa-solid fa-file-invoice"></i> Saved SMS Templates</h6>
          </div>
          <div class="admin-card-body" style="padding: 1.5rem;">
            
            <!-- Save New Template Form -->
            <details style="margin-bottom: 1.5rem; background:rgba(0,111,160,0.05); border: 1px solid rgba(0, 111, 160, 0.15); border-radius: 8px; padding: 1rem;">
              <summary style="cursor: pointer; font-size: 0.85rem; font-weight: 700; color: #4dd8ff; outline: none;">
                <i class="fa-solid fa-circle-plus mr-1"></i> Save Message as Template
              </summary>
              <form action="" method="POST" style="margin-top: 1rem;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_template">
                
                <div class="form-group">
                  <label style="font-size: 0.75rem;">Template Title</label>
                  <input type="text" name="template_title" placeholder="e.g. PTA Meeting Notice" class="admin-input" required>
                </div>
                
                <div class="form-group">
                  <label style="font-size: 0.75rem;">Template Body</label>
                  <textarea name="template_message" id="template_message" rows="3" placeholder="Type template body..." required></textarea>
                </div>
                
                <button type="submit" class="btn-tpl-use" style="width: 100%; border-radius: 4px; padding: 0.5rem; font-size: 0.8rem;">
                  <i class="fa-solid fa-floppy-disk mr-1"></i> Save Template
                </button>
              </form>
            </details>

            <!-- Templates List -->
            <div style="max-height: 400px; overflow-y: auto; padding-right: 0.25rem;">
              <?php foreach ($templatesList as $tpl): ?>
                <div class="template-item">
                  <div class="template-title">
                    <span><?= htmlspecialchars($tpl['title']) ?></span>
                    <form action="" method="POST" class="template-delete-form" style="margin:0;">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete_template">
                      <input type="hidden" name="template_id" value="<?= $tpl['id'] ?>">
                      <button type="submit" class="btn-tpl-del" title="Delete Template">
                        <i class="fa-solid fa-trash-can"></i>
                      </button>
                    </form>
                  </div>
                  <div class="template-body">
                    <?= nl2br(htmlspecialchars($tpl['message'])) ?>
                  </div>
<div class="template-actions">
                     <button type="button" class="btn-tpl-use" data-message="<?= htmlspecialchars($tpl['message']) ?>">
                       <i class="fa-solid fa-file-import mr-1"></i> Use Template
                     </button>
                   </div>
                </div>
              <?php endforeach; ?>
              
              <?php if (empty($templatesList)): ?>
                <div style="text-align: center; color: var(--text-muted); padding: 2.5rem 1rem; font-size: 0.85rem;">
                  <i class="fa-solid fa-paste" style="font-size: 2rem; margin-bottom: 0.75rem; display: block; color: rgba(255,255,255,0.08);"></i>
                  No templates saved yet. You can create one above.
                </div>
              <?php endif; ?>
            </div>

          </div>
        </div>

      </div>

      <!-- SMS Queue & History Section -->
      <div class="admin-card" style="max-width: 1200px; margin: 2rem auto 0 auto;">
        <div class="admin-card-header" style="display:flex; justify-content:space-between; align-items:center;">
          <h6><i class="fa-solid fa-clock-rotate-left"></i> SMS Queue & History (Last 50)</h6>
          <div style="display:flex; gap:10px;">
            <span class="badge-status" style="background:#f77f00; color:#fff;">
              Pending: <?= $pendingCount ?>
            </span>
            <span class="badge-status" style="background:#2dc653; color:#fff;">
              Sent: <?= $sentCount ?>
            </span>
            <span class="badge-status" style="background:#d90429; color:#fff;">
              Failed: <?= $failedCount ?>
            </span>
          </div>
        </div>
        <div class="admin-card-body" style="padding:0; overflow-x:auto;">
          <table class="admin-table">
            <thead>
              <tr>
                <th style="width: 150px;">Recipient</th>
                <th>Message</th>
                <th style="width: 100px;">Status</th>
                <th style="width: 80px; text-align:center;">Attempts</th>
                <th style="width: 150px;">Last Attempt</th>
                <th style="width: 200px;">Error Log</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentSms as $sms): 
                $statusColor = '';
                $statusIcon = '';
                $statusLabel = ucfirst($sms['status']);
                if ($sms['status'] === 'sent') {
                    $statusColor = '#2dc653';
                    $statusIcon = 'fa-circle-check';
                } elseif ($sms['status'] === 'failed') {
                    $statusColor = '#ff4d4d';
                    $statusIcon = 'fa-circle-xmark';
                } else {
                    $statusColor = '#f77f00';
                    $statusIcon = 'fa-circle-notch fa-spin';
                }
              ?>
              <tr>
                <td>
                  <strong style="font-size:0.85rem; color:#fff;"><?= htmlspecialchars($sms['phone']) ?></strong><br>
                  <small style="color:var(--text-secondary); font-size:0.75rem;">Created: <?= date('d M, H:i', strtotime($sms['created_at'])) ?></small>
                </td>
                <td style="white-space: normal; line-height: 1.45; font-size: 0.82rem; color: #cbd5e0; max-width: 400px;">
                  <?= htmlspecialchars($sms['message']) ?>
                </td>
                <td>
                  <span style="color: <?= $statusColor ?>; font-weight: 700; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fa-solid <?= $statusIcon ?>"></i><?= $statusLabel ?>
                  </span>
                </td>
                <td style="text-align: center; font-size:0.85rem;"><?= $sms['attempts'] ?></td>
                <td style="font-size:0.8rem; color:var(--text-secondary);">
                  <?= $sms['last_attempt'] ? date('d M Y, H:i', strtotime($sms['last_attempt'])) : '&mdash;' ?>
                </td>
                <td style="white-space: normal; font-size: 0.78rem; color: #ff6b6b; line-height: 1.3; max-width: 200px;">
                  <?= $sms['error_message'] ? htmlspecialchars($sms['error_message']) : '&mdash;' ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($recentSms)): ?>
              <tr>
                <td colspan="6" style="text-align:center; color:var(--text-muted); padding:3.5rem 1rem;">
                  <i class="fa-solid fa-message-slash" style="font-size:2.5rem; margin-bottom:1rem; display:block; color:rgba(255,255,255,0.06);"></i>
                  No SMS records in queue or dispatch history.
                </td>
              </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination controls -->
        <?php if ($totalPages > 1): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; padding:1.25rem; border-top:1px solid rgba(255,255,255,0.05); flex-wrap:wrap; gap:10px;">
            <span style="font-size:0.8rem; color:var(--text-secondary);">
              Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalSmsRecords) ?> of <?= $totalSmsRecords ?> records
            </span>
            <div style="display:flex; gap:10px; align-items:center;">
              
              <!-- Prev Button -->
              <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn-tpl-use" style="text-decoration:none; padding:0.4rem 1rem; border-radius:4px; font-size:0.78rem;">
                  <i class="fa-solid fa-chevron-left mr-1"></i> Previous
                </a>
              <?php else: ?>
                <button class="btn-tpl-use" disabled style="opacity:0.3; cursor:not-allowed; padding:0.4rem 1rem; border-radius:4px; font-size:0.78rem;">
                  <i class="fa-solid fa-chevron-left mr-1"></i> Previous
                </button>
              <?php endif; ?>

              <span style="font-size:0.85rem; color:#fff; font-weight:600;">
                Page <?= $page ?> of <?= $totalPages ?>
              </span>

              <!-- Next Button -->
              <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn-tpl-use" style="text-decoration:none; padding:0.4rem 1rem; border-radius:4px; font-size:0.78rem;">
                  Next <i class="fa-solid fa-chevron-right ml-1"></i>
                </a>
              <?php else: ?>
                <button class="btn-tpl-use" disabled style="opacity:0.3; cursor:not-allowed; padding:0.4rem 1rem; border-radius:4px; font-size:0.78rem;">
                  Next <i class="fa-solid fa-chevron-right ml-1"></i>
                </button>
              <?php endif; ?>

            </div>
          </div>
        <?php endif; ?>

      </div>

    </div>
  </main>
</div>

<script nonce="<?= generateCspNonce() ?>">
// Use saved template
document.addEventListener('click', function(e) {
  if (e.target.closest('.btn-tpl-use') && e.target.closest('.template-item')) {
    const msg = e.target.closest('.btn-tpl-use').getAttribute('data-message');
    if (msg) {
      const composer = document.getElementById('message');
      composer.value = msg;
      composer.focus();
      updateCounter();
    }
  }
});

// Toggle showing input groups based on selected audience
function toggleFilters() {
  const target = document.getElementById('target_type').value;
  document.getElementById('program_group').style.display = target === 'program' ? 'block' : 'none';
  document.getElementById('student_group').style.display = target === 'student' ? 'block' : 'none';
}

// Initial toggle (in case form is redisplayed after error)
toggleFilters();

// Wired up here instead of inline onchange/onsubmit attributes, which CSP's
// nonce'd script-src always blocks regardless of nonce placement.
document.getElementById('target_type').addEventListener('change', toggleFilters);
document.querySelectorAll('.template-delete-form').forEach(function (form) {
  form.addEventListener('submit', function (e) {
    if (!confirm('Are you sure you want to delete this template?')) e.preventDefault();
  });
});

// Search filter logic for student select
const searchInput = document.getElementById('student_search');
const selectEl = document.getElementById('student_select');
const originalOptions = Array.from(selectEl.options);

searchInput.addEventListener('input', function() {
  const query = this.value.toLowerCase().trim();
  
  selectEl.innerHTML = '';
  
  originalOptions.forEach(opt => {
    if (opt.value === '' || opt.getAttribute('data-search').includes(query)) {
      selectEl.appendChild(opt);
    }
  });
});

// Character and SMS page counter
const msgTextarea = document.getElementById('message');
const charCountSpan = document.getElementById('charCount');
const smsPagesSpan = document.getElementById('smsPages');

function updateCounter() {
  const len = msgTextarea.value.length;
  charCountSpan.textContent = len;
  
  let pages = 1;
  if (len > 160) {
    pages = Math.ceil(len / 153);
  } else if (len === 0) {
    pages = 0;
  }
  smsPagesSpan.textContent = pages;
}

msgTextarea.addEventListener('input', updateCounter);
updateCounter(); // Initial trigger
</script>
</body>
</html>
