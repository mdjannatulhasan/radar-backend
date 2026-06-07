-- ============================================================
-- PURGE: Students and all related academic data
-- Safe to run on both local dev and VPS production DB
-- Tables with cascadeOnDelete will auto-clean, but we delete
-- explicitly here for auditability and FK safety.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Student-linked data (cascade would handle most, but explicit is safer)
TRUNCATE TABLE pps_assessments;
TRUNCATE TABLE pps_attendance;
TRUNCATE TABLE pps_behavior_cards;
TRUNCATE TABLE pps_classroom_ratings;
TRUNCATE TABLE pps_extracurricular;
TRUNCATE TABLE pps_performance_snapshots;
TRUNCATE TABLE pps_alerts;
TRUNCATE TABLE pps_counseling_sessions;
TRUNCATE TABLE pps_welfare_interventions;
TRUNCATE TABLE pps_term_marks;
TRUNCATE TABLE pps_pretest_marks;
TRUNCATE TABLE pps_result_summary;
TRUNCATE TABLE pps_notification_logs;

-- 2. Enrollment & academic year
TRUNCATE TABLE student_enrollments;
TRUNCATE TABLE academic_years;

-- 3. Students
TRUNCATE TABLE students;

-- 4. Class structure
TRUNCATE TABLE pps_class_configs;
TRUNCATE TABLE pps_class_sections;
TRUNCATE TABLE pps_teacher_assignments;
TRUNCATE TABLE pps_exam_scopes;
TRUNCATE TABLE pps_exam_definitions;

-- 5. Taxonomy / config tables
TRUNCATE TABLE pps_classes;
TRUNCATE TABLE pps_sections;
TRUNCATE TABLE pps_subjects;
TRUNCATE TABLE pps_departments;
TRUNCATE TABLE pps_streams;
TRUNCATE TABLE pps_grade_config;

SET FOREIGN_KEY_CHECKS = 1;

-- Verify
SELECT 'students'              AS tbl, COUNT(*) AS remaining FROM students
UNION ALL SELECT 'student_enrollments',     COUNT(*) FROM student_enrollments
UNION ALL SELECT 'pps_assessments',         COUNT(*) FROM pps_assessments
UNION ALL SELECT 'pps_term_marks',          COUNT(*) FROM pps_term_marks
UNION ALL SELECT 'pps_result_summary',      COUNT(*) FROM pps_result_summary
UNION ALL SELECT 'pps_classes',             COUNT(*) FROM pps_classes
UNION ALL SELECT 'pps_sections',            COUNT(*) FROM pps_sections
UNION ALL SELECT 'pps_subjects',            COUNT(*) FROM pps_subjects
UNION ALL SELECT 'pps_departments',         COUNT(*) FROM pps_departments
UNION ALL SELECT 'pps_streams',             COUNT(*) FROM pps_streams;
