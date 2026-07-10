<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
startSecureSession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect(BASE_URL . '/admin/index.php'); }
if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
    setFlash('admin_error', 'Invalid request. Please try again.');
    redirect(BASE_URL . '/admin/index.php');
}

$username = sanitize($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    setFlash('admin_error', 'Username and password are required.');
    redirect(BASE_URL . '/admin/index.php');
}

$pdo = getDB();
$ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// S04: Brute-force protection — max 10 failed attempts per IP in 15 minutes,
// and max 5 failed attempts per username in 15 minutes.
$windowSql = "AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";

$stmtIp = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action = 'admin_login_failed' AND ip_address = ? $windowSql"
);
$stmtIp->execute([$ip]);
if ((int)$stmtIp->fetchColumn() >= 10) {
    logAction('admin', null, 'admin_login_blocked', "IP rate-limited: $ip");
    setFlash('admin_error', 'Too many failed login attempts from your IP. Please wait 15 minutes.');
    redirect(BASE_URL . '/admin/index.php');
}

$stmtUser = $pdo->prepare(
    "SELECT COUNT(*) FROM audit_logs
     WHERE action = 'admin_login_failed' AND details LIKE ? $windowSql"
);
$stmtUser->execute(["Username: $username%"]);
if ((int)$stmtUser->fetchColumn() >= 5) {
    logAction('admin', null, 'admin_login_blocked', "Username rate-limited: $username from $ip");
    setFlash('admin_error', 'Too many failed attempts for this account. Please wait 15 minutes.');
    redirect(BASE_URL . '/admin/index.php');
}

$stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
$stmt->execute([$username]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, $admin['password_hash'])) {
    logAction('admin', null, 'admin_login_failed', "Username: $username | IP: $ip");
    // Uniform delay to prevent timing-based username enumeration
    usleep(random_int(200000, 400000));
    setFlash('admin_error', 'Invalid username or password. Please try again.');
    redirect(BASE_URL . '/admin/index.php');
}

// Success
$_SESSION['admin_id']            = $admin['id'];
$_SESSION['admin_username']      = $admin['username'];
$_SESSION['admin_full_name']     = $admin['full_name'];
$_SESSION['admin_role']          = $admin['role'];
$_SESSION['admin_permissions']   = $admin['role'] === 'superadmin'
    ? 'dashboard,students,sms,import,houses,settings,users'
    : ($admin['permissions'] ?? '');
$_SESSION['admin_last_activity'] = time();
session_regenerate_id(true);

$pdo->prepare("UPDATE admins SET last_login=NOW() WHERE id=?")->execute([$admin['id']]);
logAction('admin', $admin['id'], 'admin_login_success', "Username: $username | IP: $ip");

redirect(BASE_URL . '/admin/dashboard.php');
