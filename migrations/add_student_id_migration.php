<?php
/**
 * Migration: Add admission_number + student_id_sequences table
 *
 * - admission_number replaces the old manual system with an auto-generated
 *   format: CDTI/[PROG-CODE]/[YEAR]/[0001]
 * - student_id_sequences acts as an atomic per-program-per-year counter to
 *   prevent race conditions when two students register at the same time.
 *
 * Run: navigate to /migrations/add_student_id_migration.php in your browser
 * (or run via CLI if DB credentials are accessible from CLI).
 */
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = getDB();

    // 1. Add admission_number column to students (NULL until registration completes)
    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN admission_number VARCHAR(30) DEFAULT NULL UNIQUE AFTER index_number");
        echo "✓ Added admission_number column to students table.\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "⚠ admission_number column already exists — skipping.\n";
        } else {
            throw $e;
        }
    }

    // 2. Create the atomic sequence counter table
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_id_sequences (
        id           INT PRIMARY KEY AUTO_INCREMENT,
        program_code VARCHAR(10) NOT NULL,
        year         CHAR(4)     NOT NULL,
        last_seq     INT         NOT NULL DEFAULT 0,
        UNIQUE KEY uq_prog_year (program_code, year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "✓ Created student_id_sequences table.\n";

    echo "\nMigration completed successfully!\n";
    echo "Format: CDTI/[PROG-CODE]/[YEAR]/[0001]\n";
    echo "Example: CDTI/BC/2026/0001  (Building Construction, 1st student)\n";

} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
