<?php
/**
 * Migration: add permissions column to admins table
 * Run once: https://yourdomain.com/migrations/add_permissions.php
 */
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

// "IF NOT EXISTS" is MariaDB-only and errors on real MySQL.
try {
    $pdo->exec("ALTER TABLE admins ADD COLUMN permissions TEXT DEFAULT NULL AFTER role");
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') === false) { throw $e; }
}

// Give all existing superadmins full permissions
$all = ['dashboard','students','sms','import','houses','settings','users'];
$pdo->prepare("UPDATE admins SET permissions = ? WHERE role = 'superadmin'")
    ->execute([implode(',', $all)]);

echo "Migration complete. Superadmins updated with full permissions.";
