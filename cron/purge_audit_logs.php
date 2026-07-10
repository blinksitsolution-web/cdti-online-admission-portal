<?php
/**
 * cron/purge_audit_logs.php — P02: Audit log retention policy.
 * Archives rows older than 12 months into audit_logs_archive, then deletes them.
 *
 * Run monthly (e.g. first day of each month at 02:00):
 *   0 2 1 * * php /home/USERNAME/public_html/cron/purge_audit_logs.php >> /dev/null 2>&1
 */
if (php_sapi_name() !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Direct access forbidden.');
}

require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

// Create archive table if it doesn't exist (mirrors audit_logs structure)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs_archive LIKE audit_logs
");

// Move rows older than 12 months into archive
$pdo->exec("
    INSERT INTO audit_logs_archive
    SELECT * FROM audit_logs
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)
");

// Delete archived rows from live table
$stmt = $pdo->exec("
    DELETE FROM audit_logs
    WHERE created_at < DATE_SUB(NOW(), INTERVAL 12 MONTH)
");

echo "Audit log purge complete. Rows archived and removed: {$stmt}\n";
