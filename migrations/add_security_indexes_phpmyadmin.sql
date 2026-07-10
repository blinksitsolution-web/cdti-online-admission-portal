-- ============================================================
-- Security Indexes Migration — phpMyAdmin compatible
-- MySQL 5.7+ / MariaDB 10.3+
-- Run once in phpMyAdmin → SQL tab
-- ============================================================

DROP PROCEDURE IF EXISTS add_security_indexes;

DELIMITER $$

CREATE PROCEDURE add_security_indexes()
BEGIN
    -- D01: students.payment_reference
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'students'
          AND INDEX_NAME   = 'idx_students_payment_reference'
    ) THEN
        ALTER TABLE students
            ADD INDEX idx_students_payment_reference (payment_reference);
        SELECT 'D01: idx_students_payment_reference created.' AS result;
    ELSE
        SELECT 'D01: idx_students_payment_reference already exists, skipped.' AS result;
    END IF;

    -- D02: parent_guardian_info.student_id
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'parent_guardian_info'
          AND INDEX_NAME   = 'idx_pgi_student_id'
    ) THEN
        ALTER TABLE parent_guardian_info
            ADD INDEX idx_pgi_student_id (student_id);
        SELECT 'D02: idx_pgi_student_id created.' AS result;
    ELSE
        SELECT 'D02: idx_pgi_student_id already exists, skipped.' AS result;
    END IF;
END$$

DELIMITER ;

CALL add_security_indexes();
DROP PROCEDURE IF EXISTS add_security_indexes;
