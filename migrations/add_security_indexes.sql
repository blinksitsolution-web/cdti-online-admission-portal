-- D01: Index on students.payment_reference (webhook + verify lookups)
ALTER TABLE students
    ADD INDEX IF NOT EXISTS idx_students_payment_reference (payment_reference);

-- D02: Index on parent_guardian_info.student_id (FK join lookups)
ALTER TABLE parent_guardian_info
    ADD INDEX IF NOT EXISTS idx_pgi_student_id (student_id);
