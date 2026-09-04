-- ============================================
-- RMS Database Migration Script (SAFE VERSION)
-- From: active/freed/completed status system
-- To: booked/cancelled status system
-- Date: 2026-02-05
-- Handles various schema states gracefully
-- ============================================

USE baubrain_room_management;

-- ============================================
-- BACKUP REMINDER
-- ============================================
-- CRITICAL: Back up your database before running this migration!
-- Run: mysqldump -u username -p baubrain_room_management > backup_$(date +%Y%m%d).sql

-- ============================================
-- 1. UPDATE room_bookings TABLE
-- ============================================

-- Step 1a: Update existing status values to new system
UPDATE room_bookings 
SET status = 'booked' 
WHERE status IN ('active', 'pending');

UPDATE room_bookings 
SET status = 'cancelled' 
WHERE status IN ('freed', 'completed', 'canceled');

-- Step 1b: Modify status column to new ENUM
ALTER TABLE room_bookings 
MODIFY COLUMN status ENUM('booked', 'cancelled') DEFAULT 'booked';

-- Step 1c: Rename cancelled-related columns (only if they exist)
-- Check and rename freed_at -> cancelled_at
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND COLUMN_NAME = 'freed_at');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE room_bookings CHANGE COLUMN freed_at cancelled_at TIMESTAMP NULL',
    'SELECT "Column freed_at does not exist, skipping..." as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and rename freed_by -> cancelled_by
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND COLUMN_NAME = 'freed_by');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE room_bookings CHANGE COLUMN freed_by cancelled_by INT NULL',
    'SELECT "Column freed_by does not exist, skipping..." as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and rename freed_reason -> cancellation_reason
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND COLUMN_NAME = 'freed_reason');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE room_bookings CHANGE COLUMN freed_reason cancellation_reason VARCHAR(200) NULL',
    'SELECT "Column freed_reason does not exist, skipping..." as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 1d: Remove completed_at if it exists (not needed in new system)
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND COLUMN_NAME = 'completed_at');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE room_bookings DROP COLUMN completed_at',
    'SELECT "Column completed_at does not exist, skipping..." as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 1e: Add performance indexes (skip if already exists)
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND INDEX_NAME = 'idx_booking_lookup');

SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_booking_lookup ON room_bookings(room_id, booking_date, status, start_time, end_time)',
    'SELECT "Index idx_booking_lookup already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'room_bookings' 
    AND INDEX_NAME = 'idx_status_time');

SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_status_time ON room_bookings(status, booking_date, start_time, end_time)',
    'SELECT "Index idx_status_time already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 2. UPDATE course_schedule TABLE
-- ============================================

-- Step 2a: Update existing status values
UPDATE course_schedule 
SET status = 'scheduled' 
WHERE status IN ('active', 'pending');

UPDATE course_schedule 
SET status = 'cancelled' 
WHERE status IN ('canceled', 'completed');

-- Step 2b: Modify status column to new ENUM
ALTER TABLE course_schedule 
MODIFY COLUMN status ENUM('scheduled', 'cancelled') DEFAULT 'scheduled';

-- Step 2c: Add cancellation tracking columns
ALTER TABLE course_schedule 
ADD COLUMN IF NOT EXISTS cancelled_at TIMESTAMP NULL AFTER status,
ADD COLUMN IF NOT EXISTS cancelled_by INT NULL AFTER cancelled_at,
ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(200) NULL AFTER cancelled_by;

-- Add foreign key for cancelled_by (skip if exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'course_schedule' 
    AND CONSTRAINT_NAME = 'fk_schedule_cancelled_by');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE course_schedule ADD CONSTRAINT fk_schedule_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Foreign key fk_schedule_cancelled_by already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2d: Remove completed_at if it exists
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'course_schedule' 
    AND COLUMN_NAME = 'completed_at');

SET @sql = IF(@col_exists > 0,
    'ALTER TABLE course_schedule DROP COLUMN completed_at',
    'SELECT "Column completed_at does not exist, skipping..." as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2e: Add performance indexes
SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'course_schedule' 
    AND INDEX_NAME = 'idx_schedule_active');

SET @sql = IF(@idx_exists = 0,
    'CREATE INDEX idx_schedule_active ON course_schedule(room_id, day_of_week, status, start_date, end_date)',
    'SELECT "Index idx_schedule_active already exists" as message');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 3. ADD semesters TABLE degree-specific columns
-- ============================================

ALTER TABLE semesters 
ADD COLUMN IF NOT EXISTS degree_program_id INT NULL AFTER is_active,
ADD COLUMN IF NOT EXISTS level INT NULL AFTER degree_program_id;

-- Add foreign key for degree_program_id (skip if exists)
SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS 
    WHERE CONSTRAINT_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'semesters' 
    AND CONSTRAINT_NAME = 'fk_semester_degree');

SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE semesters ADD CONSTRAINT fk_semester_degree FOREIGN KEY (degree_program_id) REFERENCES degree_programs(id) ON DELETE CASCADE',
    'SELECT "Foreign key fk_semester_degree already exists" as message');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================
-- 4. CREATE schedule_exceptions TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS schedule_exceptions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    course_schedule_id INT NOT NULL,
    exception_date DATE NOT NULL,
    exception_type ENUM('cancelled', 'rescheduled') NOT NULL,
    -- For rescheduled instances (future enhancement)
    new_room_id INT NULL,
    new_start_time TIME NULL,
    new_end_time TIME NULL,
    reason VARCHAR(200),
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (course_schedule_id) REFERENCES course_schedule(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (new_room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    
    UNIQUE(course_schedule_id, exception_date),
    INDEX idx_exception_lookup (course_schedule_id, exception_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. CREATE booking_attempts TABLE
-- ============================================

CREATE TABLE IF NOT EXISTS booking_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    user_type ENUM('teacher', 'cr') NOT NULL,
    room_id INT NOT NULL,
    attempted_date DATE NOT NULL,
    attempted_start_time TIME NOT NULL,
    attempted_end_time TIME NOT NULL,
    purpose VARCHAR(200),
    -- Conflict tracking
    conflict_type ENUM('room_booked', 'schedule_conflict') NOT NULL,
    blocking_booking_id INT NULL,
    blocking_schedule_id INT NULL,
    -- Resolution tracking
    is_resolved BOOLEAN DEFAULT FALSE,
    notified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (blocking_booking_id) REFERENCES room_bookings(id) ON DELETE SET NULL,
    FOREIGN KEY (blocking_schedule_id) REFERENCES course_schedule(id) ON DELETE SET NULL,
    
    INDEX idx_unresolved (room_id, attempted_date, is_resolved),
    INDEX idx_user_attempts (user_id, is_resolved),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. VERIFY MIGRATION
-- ============================================

SELECT '========================================' as ' ';
SELECT 'MIGRATION VERIFICATION' as ' ';
SELECT '========================================' as ' ';

-- Check room_bookings status values
SELECT 'Room Bookings Status Distribution:' as ' ';
SELECT 
    status, 
    COUNT(*) as count 
FROM room_bookings 
GROUP BY status;

-- Check course_schedule status values
SELECT 'Course Schedule Status Distribution:' as ' ';
SELECT 
    status, 
    COUNT(*) as count 
FROM course_schedule 
GROUP BY status;

-- Verify new tables exist
SELECT 'New Tables Created:' as ' ';
SHOW TABLES LIKE 'schedule_exceptions';
SHOW TABLES LIKE 'booking_attempts';

-- Verify column renames
SELECT 'Room Bookings Columns (should show cancelled_* columns):' as ' ';
SHOW COLUMNS FROM room_bookings LIKE '%cancel%';

SELECT '========================================' as ' ';
SELECT 'MIGRATION COMPLETE!' as ' ';
SELECT 'Next: Run test_implementation.php' as ' ';
SELECT '========================================' as ' ';
