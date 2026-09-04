-- ============================================
-- Classroom Management System Database Schema
-- Fresh installation - Run this to set up DB
-- ============================================

-- Create database
CREATE DATABASE IF NOT EXISTS room_management;
USE room_management;

-- ============================================
-- 1. FACULTIES TABLE
-- ============================================
CREATE TABLE faculties (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- 2. DEGREE PROGRAMS TABLE
-- ============================================
CREATE TABLE degree_programs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    faculty_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) UNIQUE NOT NULL,
    level_type ENUM('bachelor', 'masters', 'phd') DEFAULT 'bachelor',
    total_levels INT DEFAULT 4,
    semesters_per_level INT DEFAULT 2,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE CASCADE
);

-- ============================================
-- 3. SEMESTERS TABLE
-- ============================================
CREATE TABLE semesters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN DEFAULT FALSE,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- 4. USERS TABLE
-- ============================================
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    user_type ENUM('admin', 'teacher', 'cr') NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    -- CR specific fields
    degree_program_id INT NULL,
    assigned_level INT NULL,
    assigned_semester INT NULL,
    batch VARCHAR(20) NULL,
    -- Status
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (degree_program_id) REFERENCES degree_programs(id) ON DELETE SET NULL
);

-- ============================================
-- 5. BUILDINGS TABLE
-- ============================================
CREATE TABLE buildings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(10) UNIQUE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================
-- 6. FLOORS TABLE
-- ============================================
CREATE TABLE floors (
    id INT PRIMARY KEY AUTO_INCREMENT,
    building_id INT NOT NULL,
    floor_number INT NOT NULL,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    UNIQUE(building_id, floor_number)
);

-- ============================================
-- 7. ROOMS TABLE
-- ============================================
CREATE TABLE rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    floor_id INT NOT NULL,
    faculty_id INT NULL,
    room_number VARCHAR(20) NOT NULL,
    room_name VARCHAR(100) NOT NULL,
    room_type ENUM('lab', 'theory', 'seminar', 'auditorium') NOT NULL DEFAULT 'theory',
    capacity INT DEFAULT 50,
    has_projector BOOLEAN DEFAULT TRUE,
    has_ac BOOLEAN DEFAULT FALSE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (floor_id) REFERENCES floors(id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES faculties(id) ON DELETE SET NULL,
    UNIQUE(floor_id, room_number)
);

-- ============================================
-- 8. TIME SLOTS TABLE (predefined slots)
-- ============================================
CREATE TABLE time_slots (
    id INT PRIMARY KEY AUTO_INCREMENT,
    slot_name VARCHAR(20) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    slot_order INT NOT NULL
);

-- Insert default time slots
INSERT INTO time_slots (slot_name, start_time, end_time, slot_order) VALUES
('Slot 1', '08:00:00', '09:00:00', 1),
('Slot 2', '09:00:00', '10:00:00', 2),
('Slot 3', '10:00:00', '11:00:00', 3),
('Slot 4', '11:00:00', '12:00:00', 4),
('Slot 5', '12:00:00', '13:00:00', 5),
('Slot 6', '13:00:00', '14:00:00', 6),
('Slot 7', '14:00:00', '15:00:00', 7),
('Slot 8', '15:00:00', '16:00:00', 8),
('Slot 9', '16:00:00', '17:00:00', 9),
('Slot 10', '17:00:00', '18:00:00', 10);

-- ============================================
-- 9. COURSE SCHEDULE TABLE
-- ============================================
CREATE TABLE course_schedule (
    id INT PRIMARY KEY AUTO_INCREMENT,
    room_id INT NOT NULL,
    teacher_id INT NULL,
    degree_program_id INT NULL,
    course_code VARCHAR(20) NOT NULL,
    course_name VARCHAR(100) NOT NULL,
    level INT NOT NULL,
    semester INT NOT NULL,
    group_name VARCHAR(20),
    batch VARCHAR(20),
    day_of_week INT NOT NULL, -- 1=Monday to 7=Sunday
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_whole_semester BOOLEAN DEFAULT TRUE,
    start_date DATE,
    end_date DATE,
    status ENUM('scheduled', 'cancelled') DEFAULT 'scheduled',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (degree_program_id) REFERENCES degree_programs(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- ============================================
-- 10. ROOM BOOKINGS TABLE
-- ============================================
CREATE TABLE room_bookings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    room_id INT NOT NULL,
    booked_by INT NOT NULL,
    -- Booking details
    purpose VARCHAR(200) NOT NULL,
    booking_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    -- Optional: link to course schedule
    course_schedule_id INT NULL,
    -- For classes: degree info
    degree_program_id INT NULL,
    level INT NULL,
    semester INT NULL,
    group_name VARCHAR(20),
    batch VARCHAR(20),
    -- Status: instant booking (no approval needed)
    status ENUM('active', 'freed', 'completed') DEFAULT 'active',
    -- Freeing info
    freed_at TIMESTAMP NULL,
    freed_by INT NULL,
    freed_reason VARCHAR(200),
    -- Timestamps
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (booked_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (freed_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (course_schedule_id) REFERENCES course_schedule(id) ON DELETE SET NULL,
    FOREIGN KEY (degree_program_id) REFERENCES degree_programs(id) ON DELETE SET NULL,
    -- Index for faster availability checks
    INDEX idx_room_date (room_id, booking_date, status),
    INDEX idx_booking_date (booking_date)
);

-- ============================================
-- 11. NOTIFICATIONS TABLE
-- ============================================
CREATE TABLE notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('booking_freed', 'booking_created', 'schedule_change', 'system') NOT NULL,
    related_type ENUM('booking', 'schedule') NULL,
    related_id INT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
);

-- ============================================
-- 12. ACTIVITY LOG TABLE
-- ============================================
CREATE TABLE activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    action ENUM('book_room', 'free_room', 'schedule_add', 'schedule_edit', 'schedule_cancel', 'user_login', 'user_logout') NOT NULL,
    entity_type ENUM('room_booking', 'course_schedule', 'user') NOT NULL,
    entity_id INT NULL,
    room_id INT NULL,
    details TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    INDEX idx_created_at (created_at),
    INDEX idx_user_action (user_id, action)
);

-- ============================================
-- 13. CREATE DEFAULT ADMIN USER
-- ============================================
INSERT INTO users (username, password, full_name, user_type, email) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin', 'admin@university.edu');
-- Default password: password

-- ============================================
-- SAMPLE DATA (for testing)
-- ============================================

-- Sample Faculty
INSERT INTO faculties (name, code) VALUES
('Faculty of Agriculture', 'AGRI'),
('Faculty of Veterinary Medicine', 'VET'),
('Faculty of Science', 'SCI');

-- Sample Degree Programs
INSERT INTO degree_programs (faculty_id, name, code, level_type, total_levels) VALUES
(1, 'BSc in Agriculture', 'BSC-AGRI', 'bachelor', 4),
(1, 'MS in Genetics', 'MS-GEN', 'masters', 2),
(2, 'Doctor of Veterinary Medicine', 'DVM', 'bachelor', 5),
(3, 'BSc in Chemistry', 'BSC-CHEM', 'bachelor', 4);

-- Sample Building
INSERT INTO buildings (name, code, description) VALUES
('Academic Building A', 'ABA', 'Main academic building with theory rooms'),
('Science Complex', 'SC', 'Labs and research facilities');

-- Sample Floors
INSERT INTO floors (building_id, floor_number) VALUES
(1, 0), (1, 1), (1, 2), (1, 3),
(2, 0), (2, 1), (2, 2);

-- Sample Rooms
INSERT INTO rooms (floor_id, faculty_id, room_number, room_name, room_type, capacity) VALUES
(2, 1, '101', 'Room 101', 'theory', 60),
(2, 1, '102', 'Room 102', 'theory', 40),
(3, 1, '201', 'Room 201', 'theory', 50),
(3, 2, '202', 'Room 202', 'theory', 45),
(5, 3, 'Lab-1', 'Chemistry Lab 1', 'lab', 30),
(6, 3, 'Lab-2', 'Physics Lab', 'lab', 25);

-- Sample Semester
INSERT INTO semesters (name, start_date, end_date, is_active) VALUES
('Spring 2026', '2026-01-01', '2026-05-31', TRUE);

-- Sample Teacher
INSERT INTO users (username, password, full_name, user_type, email) VALUES
('teacher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dr. John Smith', 'teacher', 'john@university.edu');

-- Sample CR
INSERT INTO users (username, password, full_name, user_type, email, degree_program_id, assigned_level, assigned_semester, batch) VALUES
('cr1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Alice Johnson', 'cr', 'alice@student.edu', 1, 2, 1, '2024');
