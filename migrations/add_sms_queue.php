<?php
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = getDB();
    
    // Create sms_queue table
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_queue (
      id INT PRIMARY KEY AUTO_INCREMENT,
      phone VARCHAR(20) NOT NULL,
      message TEXT NOT NULL,
      status ENUM('pending', 'sent', 'failed') DEFAULT 'pending',
      attempts INT DEFAULT 0,
      last_attempt DATETIME DEFAULT NULL,
      error_message TEXT DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    echo "Successfully created sms_queue table.\n";
    echo "Migration completed successfully!\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
