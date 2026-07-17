<?php
/**
 * Migration: offline-first admission sync support.
 * Run once: php migrations/add_offline_sync.php
 *
 * Adds students.submission_uuid — a client-generated UUID carried through
 * an offline-queued registration submission, used by register.php to make
 * the sync endpoint idempotent (a retried/duplicate sync of the same queued
 * item is a safe no-op instead of a duplicate write or duplicate SMS).
 * Nullable and unique-when-set: existing rows and the normal online
 * registration path (which never sets it) are unaffected.
 */
require_once __DIR__ . '/../includes/db.php';

$pdo = getDB();

// "IF NOT EXISTS" is MariaDB-only and errors on real MySQL — see other
// migrations in this folder for the same pattern.
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN submission_uuid VARCHAR(36) DEFAULT NULL");
    echo "Added column: submission_uuid\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists: submission_uuid\n";
    } else {
        throw $e;
    }
}

try {
    $pdo->exec("ALTER TABLE students ADD UNIQUE KEY uk_submission_uuid (submission_uuid)");
    echo "Added unique index: uk_submission_uuid\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "Index already exists: uk_submission_uuid\n";
    } else {
        throw $e;
    }
}

echo "Offline sync migration completed.\n";
