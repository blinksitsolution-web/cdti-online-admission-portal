<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
requireAdminAuth();
emitCspHeader();

$pdo = getDB();
$s   = getSettings();

$totalPlaced     = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalRegistered = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE registration_status='completed'")->fetchColumn();
$totalBoarders   = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE residency='Boarder' OR residency='Boarding'")->fetchColumn();
$totalDay        = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE residency='Day'")->fetchColumn();
$totalPaid       = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE payment_status='paid'")->fetchColumn();
$progressPct     = $totalPlaced > 0 ? round($totalRegistered / $totalPlaced * 100) : 0;

$programs = $pdo->query("
  SELECT program, COUNT(*) as total_placed,
         SUM(registration_status='completed') as registered
  FROM students GROUP BY program ORDER BY total_placed DESC
")->fetchAll();

// CSSPS Aggregate Range Stats
$aggregatesData = $pdo->query("
    SELECT 
      SUM(CASE WHEN CAST(aggregate AS UNSIGNED) BETWEEN 6 AND 9 THEN 1 ELSE 0 END) as range_6_9,
      SUM(CASE WHEN CAST(aggregate AS UNSIGNED) BETWEEN 10 AND 19 THEN 1 ELSE 0 END) as range_10_19,
      SUM(CASE WHEN CAST(aggregate AS UNSIGNED) BETWEEN 20 AND 30 THEN 1 ELSE 0 END) as range_20_30,
      SUM(CASE WHEN CAST(aggregate AS UNSIGNED) BETWEEN 31 AND 39 THEN 1 ELSE 0 END) as range_31_39,
      SUM(CASE WHEN CAST(aggregate AS UNSIGNED) BETWEEN 40 AND 52 THEN 1 ELSE 0 END) as range_40_52
    FROM students 
    WHERE aggregate IS NOT NULL AND aggregate != ''
")->fetch();

// Gender split per department
$genderDeptData = $pdo->query("
    SELECT 
      program,
      SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as males,
      SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as females
    FROM students 
    WHERE program IS NOT NULL AND program != ''
    GROUP BY program
    ORDER BY program ASC
")->fetchAll();

$recent = $pdo->query("
  SELECT full_name, index_number, program, registered_at
  FROM students WHERE registration_status='completed'
  ORDER BY registered_at DESC LIMIT 8
")->fetchAll();

$logoPath    = !empty($s['school_logo_path']) ? $s['school_logo_path'] : 'assets/img/logo.png';
$activeNav   = 'dashboard';
$topbarTitle = '<i class="fa-solid fa-chart-simple"></i> Dashboard Overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard | <?= htmlspecialchars($s['school_name'] ?? 'CDTI') ?> Admin</title>
  <link rel="icon" href="<?= asset($logoPath) ?>">
  <link rel="stylesheet" href="<?= asset('admin/assets/css/admin.css') ?>">
  <script src="<?= asset('admin/assets/js/theme.js') ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    .dash-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; }
    .admin-table th, .admin-table td { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:160px; }
    .admin-table th:first-child, .admin-table td:first-child { max-width:200px; white-space:normal; }
    @media(max-width:860px) { .dash-grid { grid-template-columns:1fr; } }
  </style>
</head>
<body>
<div class="admin-layout">
  <?php include __DIR__ . '/includes/sidebar.php'; ?>
  <main class="admin-main">
    <?php include __DIR__ . '/includes/topbar.php'; ?>
    <div class="page-body">

      <!-- Stat Cards -->
      <div class="stats-grid" style="margin-bottom:1.25rem;">
        <div class="stat-card blue">
          <div class="icon"><i class="fa-solid fa-users"></i></div>
          <div class="value"><?= number_format($totalPlaced) ?></div>
          <div class="label">Total Placed</div>
        </div>
        <div class="stat-card green">
          <div class="icon"><i class="fa-solid fa-circle-check"></i></div>
          <div class="value"><?= number_format($totalRegistered) ?></div>
          <div class="label">Registered</div>
        </div>
        <div class="stat-card orange">
          <div class="icon"><i class="fa-solid fa-sun"></i></div>
          <div class="value"><?= number_format($totalDay) ?></div>
          <div class="label">Day Students</div>
        </div>
        <div class="stat-card purple">
          <div class="icon"><i class="fa-solid fa-house-chimney"></i></div>
          <div class="value"><?= number_format($totalBoarders) ?></div>
          <div class="label">Boarders</div>
        </div>
        <div class="stat-card" style="border-top-color:#2dc653;">
          <div class="icon"><i class="fa-solid fa-credit-card"></i></div>
          <div class="value"><?= number_format($totalPaid) ?></div>
          <div class="label">Fee Paid</div>
        </div>
      </div>

      <!-- Progress -->
      <div class="progress-section" style="margin-bottom:1.25rem;">
        <div class="progress-header">
          <h6><i class="fa-solid fa-chart-line"></i> Registration Progress</h6>
          <span class="pct"><?= $progressPct ?>% Complete</span>
        </div>
        <div class="prog-bar-container">
          <div class="prog-bar-fill" style="width:<?= $progressPct ?>%;"></div>
        </div>
        <p class="progress-sub"><?= number_format($totalRegistered) ?> of <?= number_format($totalPlaced) ?> students have completed registration.</p>
      </div>

      <!-- Charts Section -->
      <div class="dash-grid" style="margin-bottom:1.25rem;">
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-chart-pie"></i> CSSPS Aggregate Distribution</h6></div>
          <div class="admin-card-body" style="padding:1.25rem; min-height: 280px; display: flex; align-items: center; justify-content: center;">
            <div style="width: 100%; max-width: 280px; margin: 0 auto; height: 200px;">
              <canvas id="aggregateChart"></canvas>
            </div>
          </div>
        </div>
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-chart-bar"></i> Gender Distribution by Department</h6></div>
          <div class="admin-card-body" style="padding:1.25rem; min-height: 280px;">
            <div style="width: 100%; height: 200px; position: relative;">
              <canvas id="genderDeptChart"></canvas>
            </div>
          </div>
        </div>
      </div>

      <!-- Two-column -->
      <div class="dash-grid">
        <div class="admin-card">
          <div class="admin-card-header"><h6><i class="fa-solid fa-book"></i> Programme Breakdown</h6></div>
          <div class="admin-card-body" style="padding:0;">
            <table class="admin-table">
              <thead><tr><th style="min-width:120px;">Programme</th><th>Placed</th><th>Reg.</th><th style="min-width:100px;">Progress</th></tr></thead>
              <tbody>
                <?php foreach ($programs as $p):
                  $pct2 = $p['total_placed'] > 0 ? round($p['registered']/$p['total_placed']*100) : 0; ?>
                <tr>
                  <td style="white-space:normal;line-height:1.3;"><?= htmlspecialchars($p['program']) ?></td>
                  <td><?= $p['total_placed'] ?></td>
                  <td><span style="color:var(--success);font-weight:700;"><?= $p['registered'] ?></span></td>
                  <td>
                    <div style="display:flex;align-items:center;gap:6px;">
                      <div class="prog-bar-container" style="height:6px;width:60px;flex-shrink:0;">
                        <div class="prog-bar-fill" style="width:<?= $pct2 ?>%;"></div>
                      </div>
                      <span style="font-size:0.75rem;color:var(--text-secondary);"><?= $pct2 ?>%</span>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="admin-card">
          <div class="admin-card-header">
            <h6><i class="fa-solid fa-clock"></i> Recent Registrations</h6>
            <a href="<?= BASE_URL ?>/admin/students.php" style="font-size:0.78rem;color:var(--primary-light);">View all &rarr;</a>
          </div>
          <div class="admin-card-body" style="padding:0;">
            <table class="admin-table">
              <thead><tr><th>Name</th><th>Programme</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                  <td style="white-space:normal;line-height:1.3;">
                    <strong style="font-size:0.84rem;"><?= htmlspecialchars($r['full_name']) ?></strong><br>
                    <small style="color:var(--text-secondary);font-family:monospace;font-size:0.72rem;"><?= htmlspecialchars($r['index_number']) ?></small>
                  </td>
                  <td style="white-space:normal;font-size:0.8rem;"><?= htmlspecialchars($r['program']) ?></td>
                  <td style="font-size:0.78rem;color:var(--text-secondary);white-space:nowrap;"><?= date('d M, H:i', strtotime($r['registered_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:2rem;">No registrations yet</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ── Offline Kiosk Sync Card ─────────────────────────────────────── -->
      <div class="admin-card" style="margin-top:1.25rem;border-top:3px solid #006fa0;" id="kioskSyncCard">
        <div class="admin-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
          <h6 style="display:flex;align-items:center;gap:0.5rem;">
            <i class="fa-solid fa-wifi-slash" style="color:#4dd8ff;"></i>
            Offline Kiosk — Student Data Sync
          </h6>
          <a href="<?= BASE_URL ?>/offline-kiosk.html" target="_blank"
            style="font-size:0.78rem;background:rgba(0,111,160,0.2);border:1px solid rgba(0,180,255,0.3);color:#4dd8ff;padding:0.3rem 0.8rem;border-radius:20px;text-decoration:none;white-space:nowrap;">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Kiosk
          </a>
        </div>
        <div class="admin-card-body" style="padding:1.25rem;">

          <p style="color:var(--text-secondary);font-size:0.84rem;line-height:1.6;margin-bottom:1rem;">
            Download all admitted student records to <strong style="color:var(--text-primary);">this browser</strong> so
            students can register with <strong style="color:#4dd8ff;">no internet</strong> on this device.
            Data syncs to the server automatically when connectivity returns.
          </p>

          <!-- Status indicator -->
          <div id="kioskStatusBar" style="display:flex;align-items:flex-start;gap:0.75rem;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.08);border-radius:10px;padding:0.85rem 1rem;margin-bottom:1rem;">
            <div id="kioskStatusDot" style="width:12px;height:12px;border-radius:50%;background:#555;flex-shrink:0;margin-top:3px;"></div>
            <div style="flex:1;">
              <div id="kioskStatusText" style="font-size:0.88rem;color:var(--text-primary);font-weight:600;">Checking…</div>
              <div id="kioskStatusSub" style="font-size:0.78rem;color:var(--text-muted);margin-top:0.2rem;"></div>
            </div>
          </div>

          <!-- Progress bar (hidden until sync starts) -->
          <div id="kioskProgress" style="display:none;margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;font-size:0.78rem;color:var(--text-muted);margin-bottom:0.3rem;">
              <span>Downloading student data…</span>
              <span id="kioskProgressText">0%</span>
            </div>
            <div style="background:rgba(255,255,255,0.08);border-radius:4px;height:6px;overflow:hidden;">
              <div id="kioskProgressFill" style="height:100%;background:linear-gradient(90deg,#006fa0,#4dd8ff);width:0%;transition:width 0.4s ease;"></div>
            </div>
          </div>

          <!-- Warning: stale data -->
          <div id="kioskStaleWarning" style="display:none;background:rgba(255,165,0,0.1);border:1px solid rgba(255,165,0,0.3);border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:1rem;font-size:0.82rem;color:#ffaa40;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            Kiosk data is more than 24 hours old. New students added since the last sync will not be available offline. Re-sync recommended.
          </div>

          <!-- Error display -->
          <div id="kioskSyncError" style="display:none;background:rgba(220,60,60,0.1);border:1px solid rgba(220,60,60,0.3);border-radius:8px;padding:0.65rem 0.85rem;margin-bottom:1rem;font-size:0.82rem;color:#ff8080;"></div>

          <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
            <button id="kioskSyncBtn" type="button"
              style="background:#006fa0;color:#fff;border:none;border-radius:9px;padding:0.6rem 1.25rem;font-size:0.88rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:0.5rem;transition:background 0.2s;">
              <i class="fa-solid fa-cloud-arrow-down" id="kioskSyncIcon"></i>
              <span id="kioskSyncLabel">Sync Now</span>
            </button>
            <a href="<?= BASE_URL ?>/offline-kiosk.html" target="_blank"
              style="background:rgba(0,111,160,0.15);border:1px solid rgba(0,180,255,0.25);color:#4dd8ff;border-radius:9px;padding:0.6rem 1.25rem;font-size:0.88rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:0.5rem;">
              <i class="fa-solid fa-computer"></i> Open Registration Kiosk
            </a>
          </div>

        </div>
      </div>
      <!-- / Offline Kiosk Sync Card -->

    </div>
  </main>
</div>
<script nonce="<?= generateCspNonce() ?>">
// ── Aggregate Chart (Doughnut) ──────────────────────────────────────────────
const aggCtx = document.getElementById('aggregateChart').getContext('2d');
const aggregateChart = new Chart(aggCtx, {
  type: 'doughnut',
  data: {
    labels: ['6 - 9', '10 - 19', '20 - 30', '31 - 39', '40 - 52'],
    datasets: [{
      data: [
        <?= (int)($aggregatesData['range_6_9'] ?? 0) ?>,
        <?= (int)($aggregatesData['range_10_19'] ?? 0) ?>,
        <?= (int)($aggregatesData['range_20_30'] ?? 0) ?>,
        <?= (int)($aggregatesData['range_31_39'] ?? 0) ?>,
        <?= (int)($aggregatesData['range_40_52'] ?? 0) ?>
      ],
      backgroundColor: [
        '#2dc653', // Emerald green
        '#0097d6', // Neon blue
        '#f77f00', // Vibrant orange
        '#9d4edd', // Purple
        '#d90429'  // Red
      ],
      borderWidth: 1,
      borderColor: 'rgba(15, 30, 45, 0.8)'
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'bottom',
        labels: {
          color: '#a0aec0',
          font: { family: 'inherit', size: 11 },
          padding: 15
        }
      },
      tooltip: {
        backgroundColor: '#0f1e2d',
        titleColor: '#fff',
        bodyColor: '#ccc',
        borderColor: 'rgba(0, 111, 160, 0.3)',
        borderWidth: 1
      }
    }
  }
});

// ── Gender by Department Chart (Bar) ────────────────────────────────────────
const genderCtx = document.getElementById('genderDeptChart').getContext('2d');

const deptLabels = <?= json_encode(array_map('htmlspecialchars', array_column($genderDeptData, 'program'))) ?>;
const maleData = <?= json_encode(array_column($genderDeptData, 'males')) ?>;
const femaleData = <?= json_encode(array_column($genderDeptData, 'females')) ?>;

const genderDeptChart = new Chart(genderCtx, {
  type: 'bar',
  data: {
    labels: deptLabels,
    datasets: [
      {
        label: 'Male',
        data: maleData,
        backgroundColor: '#0097d6',
        borderRadius: 4
      },
      {
        label: 'Female',
        data: femaleData,
        backgroundColor: '#ec4899', // Pinkish/Magenta for Female
        borderRadius: 4
      }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        position: 'top',
        labels: {
          color: '#a0aec0',
          font: { family: 'inherit', size: 11 }
        }
      },
      tooltip: {
        backgroundColor: '#0f1e2d',
        titleColor: '#fff',
        bodyColor: '#ccc',
        borderColor: 'rgba(0, 111, 160, 0.3)',
        borderWidth: 1
      }
    },
    scales: {
      x: {
        grid: { color: 'rgba(255, 255, 255, 0.05)' },
        ticks: { 
          color: '#a0aec0',
          font: { size: 10 },
          callback: function(val, index) {
            const label = this.getLabelForValue(val);
            return label.length > 15 ? label.substr(0, 12) + '...' : label;
          }
        }
      },
      y: {
        grid: { color: 'rgba(255, 255, 255, 0.05)' },
        ticks: { color: '#a0aec0', precision: 0 }
      }
    }
  }
});
</script>

<!-- Kiosk DB for offline sync card -->
<script src="<?= asset('assets/js/kiosk-db.js') ?>"></script>
<script nonce="<?= generateCspNonce() ?>">
// ── Offline Kiosk Sync Card Logic ──────────────────────────────────────────
(function () {
  var STALE_MS   = 24 * 60 * 60 * 1000; // 24 hours
  var syncBtn    = document.getElementById('kioskSyncBtn');
  var syncLabel  = document.getElementById('kioskSyncLabel');
  var syncIcon   = document.getElementById('kioskSyncIcon');
  var statusText = document.getElementById('kioskStatusText');
  var statusSub  = document.getElementById('kioskStatusSub');
  var statusDot  = document.getElementById('kioskStatusDot');
  var progressEl = document.getElementById('kioskProgress');
  var progressFill = document.getElementById('kioskProgressFill');
  var progressText = document.getElementById('kioskProgressText');
  var staleWarn  = document.getElementById('kioskStaleWarning');
  var errEl      = document.getElementById('kioskSyncError');

  function setDot(color) { statusDot.style.background = color; }
  function showErr(msg) { errEl.textContent = msg; errEl.style.display = 'block'; }
  function hideErr() { errEl.style.display = 'none'; }

  function setProgress(pct) {
    progressFill.style.width = pct + '%';
    progressText.textContent = pct + '%';
  }

  function fmtDate(ms) {
    if (!ms) return 'never';
    var d = new Date(ms);
    return d.toLocaleDateString() + ' ' + d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  // Read current meta and update the card
  function refreshStatus() {
    KioskDB.getSyncMeta().then(function (meta) {
      if (!meta || !meta.syncedAt) {
        setDot('#ff6b6b');
        statusText.textContent = 'Not synced — kiosk is not ready for offline use';
        statusSub.textContent  = 'Click "Sync Now" to download student data to this browser.';
        staleWarn.style.display = 'none';
        return;
      }
      var age = Date.now() - meta.syncedAt;
      var isStale = age > STALE_MS;
      setDot(isStale ? '#ffaa40' : '#2dc653');
      statusText.textContent = (meta.count || 0).toLocaleString() + ' students cached — kiosk ready';
      statusSub.textContent  = 'Last sync: ' + fmtDate(meta.syncedAt);
      staleWarn.style.display = isStale ? 'block' : 'none';
    }).catch(function () {
      setDot('#ff6b6b');
      statusText.textContent = 'Could not read kiosk database';
    });
  }

  // Do the actual sync
  function doSync() {
    hideErr();
    syncBtn.disabled = true;
    syncLabel.textContent = 'Syncing…';
    syncIcon.className = 'fa-solid fa-spinner fa-spin';
    progressEl.style.display = 'block';
    setProgress(10);

    // Use stored ETag for conditional GET
    var headers = { 'Accept': 'application/json', 'Accept-Encoding': 'gzip' };
    KioskDB.getSyncMeta().then(function (meta) {
      if (meta && meta.etag) headers['If-None-Match'] = meta.etag;
      return fetch('<?= BASE_URL ?>/admin/api/offline-export.php', {
        credentials: 'same-origin',
        headers: headers,
      });
    }).then(function (res) {
      setProgress(50);
      if (res.status === 304) {
        // Data unchanged — just refresh timestamp
        return KioskDB.getSyncMeta().then(function(m) {
          return KioskDB.setSyncMeta(Object.assign({}, m, { syncedAt: Date.now() }));
        }).then(function() {
          setProgress(100);
          return { noChange: true };
        });
      }
      if (!res.ok) throw new Error('Server error ' + res.status);
      var etag = res.headers.get('ETag') || '';
      return res.json().then(function (data) {
        setProgress(70);
        return KioskDB.bulkStoreStudents(data.students).then(function (count) {
          setProgress(90);
          // Cache school settings for offline display
          if (data.school) {
            return KioskDB.setSchoolMeta(data.school).then(function() {
              return KioskDB.setSyncMeta({ count: count, syncedAt: Date.now(), etag: etag });
            }).then(function() { return { count: count }; });
          }
          return KioskDB.setSyncMeta({ count: count, syncedAt: Date.now(), etag: etag })
            .then(function() { return { count: count }; });
        });
      });
    }).then(function (result) {
      setProgress(100);
      setTimeout(function () {
        progressEl.style.display = 'none';
        setProgress(0);
        syncBtn.disabled = false;
        syncLabel.textContent = result && result.noChange ? 'Already up to date' : 'Sync Now';
        syncIcon.className = 'fa-solid fa-cloud-arrow-down';
        refreshStatus();
      }, 800);
    }).catch(function (err) {
      progressEl.style.display = 'none';
      setProgress(0);
      syncBtn.disabled = false;
      syncLabel.textContent = 'Sync Now';
      syncIcon.className = 'fa-solid fa-cloud-arrow-down';
      showErr('Sync failed: ' + (err.message || 'unknown error'));
      refreshStatus();
    });
  }

  if (syncBtn) syncBtn.addEventListener('click', doSync);
  refreshStatus();

  // Auto-refresh status every 60s (in case another tab synced)
  setInterval(refreshStatus, 60000);
})();

</body>
</html>
