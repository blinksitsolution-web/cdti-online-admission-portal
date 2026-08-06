<?php
/**
 * Migration: Add is_active to houses + expand gender ENUM to include 'Both'
 *
 * Run once via CLI:  php migrations/add_house_active_both_migration.php
 * Or via the browser if your setup allows direct PHP execution.
 */
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = getDB();

    // 1. Expand gender ENUM to include 'Both'
    $pdo->exec("ALTER TABLE houses MODIFY COLUMN gender ENUM('Male','Female','Both') NOT NULL DEFAULT 'Both'");
    echo "Successfully expanded gender ENUM to include 'Both'.\n";

    // 2. Add is_active column (default 1 = active so all existing houses stay visible)
    try {
        $pdo->exec("ALTER TABLE houses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER capacity");
        echo "Successfully added is_active column to houses table.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false || $e->getCode() === '42S21') {
            echo "is_active column already exists in houses table — skipping.\n";
        } else {
            throw $e;
        }
    }

    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
