<?php
/**
 * KIMTECH Admission Portal — Web Setup Script
 * Visit: http://kimtech.myonlineadmission.com/setup.php
 * DELETE THIS FILE after setup is complete!
 */

// Hard block: APP_ENV=production OR db_prod.php exists OR non-local host
if (getenv('APP_ENV') === 'production' || file_exists(__DIR__ . '/includes/db_prod.php')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Setup is disabled in production. Use CLI migrations and seeds instead.');
}

$isLocal = (DIRECTORY_SEPARATOR === '\\')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', 'localhost')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1')
    || str_contains($_SERVER['HTTP_HOST'] ?? '', '.test');

if (!$isLocal) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Setup is disabled in production. Use CLI migrations and seeds instead.');
}

// ── Try multiple connection methods ──────────────────────────────
$connections = [
    ['host' => 'localhost',  'port' => 3306,  'label' => 'localhost:3306'],
    ['host' => '127.0.0.1', 'port' => 3306,  'label' => '127.0.0.1:3306'],
    ['host' => '.',          'port' => 3306,  'label' => 'named-pipe (.)'],
    ['host' => 'localhost',  'port' => 33060, 'label' => 'localhost:33060'],
];

$pdo     = null;
$connOk  = false;
$connMsg = '';

foreach ($connections as $c) {
    try {
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
        $pdo = new PDO($dsn, 'root', '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $connOk  = true;
        $connMsg = "✅ Connected via <strong>{$c['label']}</strong>";

        // Update db.php with working config
        $dbPhpContent = "<?php\ndefine('DB_HOST', '{$c['host']}');\ndefine('DB_PORT', {$c['port']});\ndefine('DB_NAME', 'kimtech_admission');\ndefine('DB_USER', 'root');\ndefine('DB_PASS', '');\ndefine('DB_CHARSET', 'utf8mb4');\n\nfunction getDB(): PDO {\n    static \$pdo = null;\n    if (\$pdo === null) {\n        \$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);\n        \$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];\n        try { \$pdo = new PDO(\$dsn, DB_USER, DB_PASS, \$options); }\n        catch (PDOException \$e) { error_log('DB: '.\$e->getMessage()); die(json_encode(['error'=>'Database connection failed.'])); }\n    }\n    return \$pdo;\n}";
        file_put_contents(__DIR__ . '/includes/db.php', $dbPhpContent);
        break;
    } catch (PDOException $e) {
        $connMsg .= "❌ {$c['label']}: " . $e->getMessage() . "<br>";
    }
}

$steps   = [];
$success = false;

if ($connOk && isset($_POST['run_setup'])) {
    try {
        // 1. Create database
        $pdo->exec("CREATE DATABASE IF NOT EXISTS kimtech_admission CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE kimtech_admission");
        $steps[] = ['ok' => true,  'msg' => 'Database <code>kimtech_admission</code> created / verified'];

        // 2. Create tables
        $sql = file_get_contents(__DIR__ . '/migrations/001_create_tables.sql');
        // Split and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt) && !str_starts_with(ltrim($stmt), '--') && !str_starts_with(ltrim($stmt), '/*')) {
                $pdo->exec($stmt);
            }
        }
        $steps[] = ['ok' => true, 'msg' => 'All tables created: <code>students, parent_guardian_info, admins, system_settings, audit_logs</code>'];

        // 3. Seed admin
        $adminHash = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT IGNORE INTO admins (username, password_hash, full_name, role) VALUES (?,?,?,?)")
            ->execute(['admin', $adminHash, 'System Administrator', 'superadmin']);
        $steps[] = ['ok' => true, 'msg' => 'Admin account created: <code>admin</code> / <code>admin123</code>'];

        // 4. Seed settings
        $settings = [
            ['school_name','KIKAM TECHNICAL INSTITUTE (KIMTECH)'],
            ['school_address','Kikam, Western Region, Ghana'],
            ['school_phone','+233 0552290973'],
            ['school_email','info@kimtech.edu.gh'],
            ['academic_year','2026/2027'],
            ['principal_name','The Principal'],
            ['registration_deadline','2026-09-30 23:59:59'],
            ['helpline_number','+233 0552290973'],
            ['cssps_year','2026'],
            ['reporting_date','15th September, 2026'],
            ['school_logo_path','assets/img/logo.png'],
            ['principal_signature_path',''],
            ['prospectus_boarder_path',''],
            ['prospectus_day_path',''],
        ];
        $stmtS = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_val) VALUES (?,?)");
        foreach ($settings as $s) { $stmtS->execute($s); }
        $steps[] = ['ok' => true, 'msg' => '14 system settings seeded'];

        // 5. Seed sample students
        $programs  = ['General Science','General Arts','Business','Technical','Visual Arts','Home Economics'];
        $residency = ['Boarder','Day'];
        $regions   = ['Western','Ashanti','Greater Accra','Eastern','Central'];
        $aggregates = ['6','8','10','12','14','16','18','20'];
        $names = [
            ['Kwame Asante Boateng','Male'],['Akosua Mensah Darko','Female'],
            ['Kofi Agyemang Prempeh','Male'],['Abena Owusu Sarpong','Female'],
            ['Yaw Kusi Appiah','Male'],['Ama Adutwum Frimpong','Female'],
            ['Kwesi Antwi Bonsu','Male'],['Adwoa Boateng Nyarko','Female'],
            ['Fiifi Dadzie Quaye','Male'],['Efua Asante Wiredu','Female'],
            ['Kobby Yeboah Asare','Male'],['Akua Forson Kumah','Female'],
            ['Nana Ofori Atta','Male'],['Afua Acheampong Sefa','Female'],
            ['Ato Mensah Larbi','Male'],['Maame Serwah Boadi','Female'],
            ['Kwabena Baah Kyei','Male'],['Esinam Kpodo Agbeko','Female'],
            ['Bright Owusu Darko','Male'],['Celestina Appiah Kuffour','Female'],
        ];
        $stmtI = $pdo->prepare("INSERT IGNORE INTO students (index_number,enrolment_code,full_name,gender,program,residency,aggregate,region) VALUES (?,?,?,?,?,?,?,?)");
        $base  = 100000000001;
        foreach ($names as $i => $n) {
            $stmtI->execute([
                strval($base+$i), strval(rand(10000,99999)),
                $n[0], $n[1],
                $programs[array_rand($programs)],
                $residency[array_rand($residency)],
                $aggregates[array_rand($aggregates)],
                $regions[array_rand($regions)]
            ]);
        }
        $steps[] = ['ok' => true, 'msg' => '20 sample student placement records seeded'];
        $steps[] = ['ok' => true, 'msg' => '<strong>🎉 Setup Complete!</strong> Delete this file before go-live.'];
        $success = true;

    } catch (PDOException $e) {
        $steps[] = ['ok' => false, 'msg' => 'DB Error: ' . htmlspecialchars($e->getMessage())];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>KIMTECH — Setup & Installation</title>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Inter', sans-serif; background: #0d1b2a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .card { background: #fff; border-radius: 16px; padding: 2rem; width: 100%; max-width: 620px; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
    h1 { color: #0d1b2a; font-size: 1.3rem; font-weight: 800; margin-bottom: 0.25rem; }
    .subtitle { color: #888; font-size: 0.82rem; margin-bottom: 1.5rem; }
    .conn-box { background: #f8f9fb; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1rem; font-size: 0.82rem; border-left: 4px solid #2dc653; }
    .conn-box.error { border-left-color: #e63946; background: #fff5f5; }
    .step { display: flex; align-items: flex-start; gap: 0.6rem; padding: 0.5rem 0; border-bottom: 1px solid #f0f2f5; font-size: 0.88rem; }
    .step .icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
    .step.ok { color: #155724; }
    .step.fail { color: #721c24; }
    .btn { display: block; width: 100%; padding: 0.85rem; background: linear-gradient(135deg, #006fa0, #0097d6); color: #fff; border: none; border-radius: 10px; font-size: 1rem; font-weight: 700; cursor: pointer; margin-top: 1.5rem; letter-spacing: 0.5px; }
    .btn:hover { opacity: 0.9; }
    .btn.disabled { background: #ccc; cursor: not-allowed; }
    .links { margin-top: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .link-btn { flex: 1; padding: 0.6rem 0.75rem; border-radius: 8px; font-size: 0.82rem; font-weight: 600; text-decoration: none; text-align: center; }
    .link-btn.portal { background: #006fa0; color: #fff; }
    .link-btn.admin  { background: #0d1b2a; color: #fff; }
    .link-btn.delete { background: #e63946; color: #fff; }
    .warning { background: #fff8e1; border: 1px solid #f4a261; border-radius: 8px; padding: 0.75rem 1rem; margin-top: 1rem; font-size: 0.8rem; color: #7a5c00; }
    code { background: #f0f2f5; padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 0.9em; }
    pre { background: #f8f9fb; border-radius: 8px; padding: 0.75rem; font-size: 0.78rem; overflow-x: auto; margin-top: 0.5rem; border: 1px solid #e8ecf0; }
  </style>
</head>
<body>
<div class="card">
  <h1>🎓 KIMTECH Admission Portal — Setup</h1>
  <p class="subtitle">Database initialization & seeding tool. Run once, then delete this file.</p>

  <!-- Connection Status -->
  <div class="conn-box <?= $connOk ? '' : 'error' ?>">
    <strong>Database Connection:</strong><br>
    <?= $connMsg ?>
  </div>

  <?php if (!$connOk): ?>
  <div style="background:#fff5f5;border:1px solid #f8d7da;border-radius:8px;padding:1rem;margin-bottom:1rem;font-size:0.82rem;color:#721c24;">
    <strong>⚠ Cannot connect to MySQL.</strong><br><br>
    <strong>Fix options:</strong><br>
    1. Open <strong>Laragon</strong> app → Click <strong>"Start All"</strong> to restart services.<br>
    2. Or open HeidiSQL from Laragon → run the SQL file manually:<br>
    <code>C:\laragon\www\kimtech.myonlineadmission.com\migrations\001_create_tables.sql</code><br><br>
    <strong>Then visit this page again.</strong>
  </div>
  <?php endif; ?>

  <!-- Setup Steps Results -->
  <?php if (!empty($steps)): ?>
  <div style="border:1px solid #e8ecf0;border-radius:8px;padding:0.75rem;margin-bottom:1rem;">
    <?php foreach ($steps as $step): ?>
    <div class="step <?= $step['ok'] ? 'ok' : 'fail' ?>">
      <span class="icon"><?= $step['ok'] ? '✅' : '❌' ?></span>
      <span><?= $step['msg'] ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($success): ?>
  <!-- SUCCESS: Show links -->
  <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:1rem;margin-bottom:1rem;font-size:0.88rem;color:#155724;">
    <strong>🎉 Installation Complete!</strong><br>
    Admin login: <code>admin</code> / <code>admin123</code><br>
    Change the admin password after first login!
  </div>
  <div class="links">
    <a href="/" class="link-btn portal">🏠 Student Portal</a>
    <a href="<?= BASE_URL ?>/admin/" class="link-btn admin">🔐 Admin Panel</a>
    <a href="?delete=1" class="link-btn delete" onclick="return confirm('Delete setup.php?')">🗑 Delete setup.php</a>
  </div>

  <?php elseif ($connOk): ?>
  <form method="POST">
    <div style="font-size:0.82rem;color:#555;margin-bottom:0.5rem;">This will create the database, all tables, seed 20 sample students, and configure the admin account.</div>
    <button type="submit" name="run_setup" value="1" class="btn">🚀 Run Setup Now</button>
  </form>
  <?php else: ?>
  <button class="btn disabled" disabled>🚀 Run Setup (Fix DB connection first)</button>
  <?php endif; ?>

  <div class="warning">
    ⚠ <strong>Security Warning:</strong> Delete <code>setup.php</code> from your server after installation is complete.
    This file grants full database access to anyone who can visit it.
  </div>

  <!-- Manual SQL fallback -->
  <details style="margin-top:1rem;">
    <summary style="cursor:pointer;font-size:0.82rem;color:#006fa0;font-weight:600;">📋 Manual Setup via HeidiSQL (if DB connection fails)</summary>
    <div style="font-size:0.78rem;color:#555;margin-top:0.5rem;line-height:1.6;">
      1. Open <strong>Laragon</strong> → Database → HeidiSQL<br>
      2. Connect with: Host=<code>127.0.0.1</code>, User=<code>root</code>, Password=<em>(empty)</em><br>
      3. Open the SQL file:<br>
      <code>C:\laragon\www\kimtech.myonlineadmission.com\migrations\001_create_tables.sql</code><br>
      4. Then run:<br>
      <code>C:\laragon\www\kimtech.myonlineadmission.com\seeds\seed.php</code> via PHP CLI<br><br>
      <strong>Or paste this quick SQL to create the DB:</strong>
      <pre>CREATE DATABASE IF NOT EXISTS kimtech_admission CHARACTER SET utf8mb4;
USE kimtech_admission;
-- then run 001_create_tables.sql</pre>
    </div>
  </details>

</div>

<?php
// Handle delete
if (isset($_GET['delete']) && $_GET['delete'] === '1') {
    unlink(__FILE__);
    header('Location: /');
    exit;
}
?>
</body>
</html>
