-- ============================================================
-- KIMTECH Online Admission Portal — Database Schema
-- Run: mysql -u root -p kimtech_admission < 001_create_tables.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS kimtech_admission
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE kimtech_admission;

-- --------------------------------------------------------
-- Students (core placement + registration table)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
  id                  INT PRIMARY KEY AUTO_INCREMENT,
  index_number        VARCHAR(12) NOT NULL UNIQUE,
  enrolment_code      VARCHAR(20) DEFAULT NULL,
  full_name           VARCHAR(200) NOT NULL,
  gender              ENUM('Male','Female') NOT NULL,
  date_of_birth       DATE DEFAULT NULL,
  religion            VARCHAR(50) DEFAULT NULL,
  hometown            VARCHAR(100) DEFAULT NULL,
  region              VARCHAR(100) DEFAULT NULL,
  nationality         VARCHAR(100) DEFAULT 'Ghanaian',
  prev_jhs_school     VARCHAR(200) DEFAULT NULL,
  prev_jhs_index      VARCHAR(20) DEFAULT NULL,
  program             VARCHAR(100) DEFAULT NULL,
  residency           ENUM('Boarder','Day') NOT NULL DEFAULT 'Day',
  aggregate           VARCHAR(10) DEFAULT NULL,
  registration_status ENUM('not_started','in_progress','completed') DEFAULT 'not_started',
  registered_at       DATETIME DEFAULT NULL,
  passport_photo_path VARCHAR(500) DEFAULT NULL,
  is_unlisted         TINYINT(1) DEFAULT 0,
  house_id            INT DEFAULT NULL,
  payment_status      ENUM('pending','paid','waived') DEFAULT 'pending',
  payment_reference   VARCHAR(100) DEFAULT NULL,
  payment_amount      DECIMAL(10,2) DEFAULT NULL,
  payment_date        DATETIME DEFAULT NULL,
  created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_full_name (full_name),
  INDEX idx_status (registration_status),
  INDEX idx_payment_ref (payment_reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Parent / Guardian Information
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS parent_guardian_info (
  id                    INT PRIMARY KEY AUTO_INCREMENT,
  student_id            INT NOT NULL,
  father_name           VARCHAR(200) DEFAULT NULL,
  father_phone          VARCHAR(20)  DEFAULT NULL,
  father_address        TEXT         DEFAULT NULL,
  father_occupation     VARCHAR(100) DEFAULT NULL,
  mother_name           VARCHAR(200) DEFAULT NULL,
  mother_phone          VARCHAR(20)  DEFAULT NULL,
  mother_address        TEXT         DEFAULT NULL,
  mother_occupation     VARCHAR(100) DEFAULT NULL,
  guardian_name         VARCHAR(200) DEFAULT NULL,
  guardian_phone        VARCHAR(20)  DEFAULT NULL,
  guardian_address      TEXT         DEFAULT NULL,
  guardian_relationship VARCHAR(100) DEFAULT NULL,
  created_at            DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pgi_student_id (student_id),
  FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Admins
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS admins (
  id            INT PRIMARY KEY AUTO_INCREMENT,
  username      VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  full_name     VARCHAR(200) DEFAULT NULL,
  role          ENUM('superadmin','staff') DEFAULT 'staff',
  last_login    DATETIME DEFAULT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- System Settings (key-value store)
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_settings (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  setting_key VARCHAR(100) NOT NULL UNIQUE,
  setting_val TEXT DEFAULT NULL,
  updated_at  DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Boarding Houses
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS houses (
  id         INT PRIMARY KEY AUTO_INCREMENT,
  name       VARCHAR(100) NOT NULL UNIQUE,
  gender     ENUM('Male','Female') NOT NULL,
  capacity   INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- house_id on students (run add_houses_migration.php if upgrading existing DB)
-- ALTER TABLE students ADD COLUMN house_id INT DEFAULT NULL;
-- ALTER TABLE students ADD CONSTRAINT fk_students_house_id FOREIGN KEY (house_id) REFERENCES houses(id) ON DELETE SET NULL;

-- --------------------------------------------------------
-- Audit Logs
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id         INT PRIMARY KEY AUTO_INCREMENT,
  actor_type ENUM('student','admin','system') NOT NULL,
  actor_id   INT DEFAULT NULL,
  action     VARCHAR(200) NOT NULL,
  details    TEXT DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_actor (actor_type, actor_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
