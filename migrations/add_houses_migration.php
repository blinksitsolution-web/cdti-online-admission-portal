<?php
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo = getDB();
    
    // 1. Create houses table
    $pdo->exec("CREATE TABLE IF NOT EXISTS houses (
      id INT PRIMARY KEY AUTO_INCREMENT,
      name VARCHAR(100) NOT NULL UNIQUE,
      gender ENUM('Male', 'Female') NOT NULL,
      capacity INT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Successfully created houses table.\n";
    
    // 2. Add house_id column to students table if not exists
    try {
        $pdo->exec("ALTER TABLE students ADD COLUMN house_id INT DEFAULT NULL");
        echo "Successfully added house_id column to students table.\n";
    } catch (PDOException $e) {
        // SQLSTATE[42S21]: Column already exists
        if (strpos($e->getMessage(), 'Duplicate column name') !== false || $e->getCode() == '42S21') {
            echo "house_id column already exists in students table.\n";
        } else {
            throw $e;
        }
    }
    
    // 3. Add foreign key constraint
    try {
        $pdo->exec("ALTER TABLE students ADD CONSTRAINT fk_students_house_id FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE SET NULL");
        echo "Successfully added foreign key constraint for house_id.\n";
    } catch (PDOException $e) {
        echo "Constraint status: " . $e->getMessage() . "\n";
    }
    
    echo "Migration completed successfully!\n";

    // 4. Normalise legacy residency values (Boarding → Boarder)
    $fixed = $pdo->exec("UPDATE students SET residency = 'Boarder' WHERE LOWER(TRIM(residency)) IN ('boarding', 'board')");
    if ($fixed) {
        echo "Normalised $fixed student residency record(s) to Boarder.\n";
    }
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
