<?php
/**
 * Add payment columns to students table (existing deployments).
 * Run: php migrations/add_payment_columns.php
 */
require_once __DIR__ . '/../includes/db.php';

$columns = [
    'payment_status'    => "ENUM('pending','paid','waived') DEFAULT 'pending'",
    'payment_reference' => 'VARCHAR(100) DEFAULT NULL',
    'payment_amount'    => 'DECIMAL(10,2) DEFAULT NULL',
    'payment_date'      => 'DATETIME DEFAULT NULL',
];

try {
    $pdo = getDB();

    foreach ($columns as $name => $definition) {
        try {
            $pdo->exec("ALTER TABLE students ADD COLUMN {$name} {$definition}");
            echo "Added column: {$name}\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false || $e->getCode() === '42S21') {
                echo "Column already exists: {$name}\n";
            } else {
                throw $e;
            }
        }
    }

    echo "Payment columns migration completed.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
