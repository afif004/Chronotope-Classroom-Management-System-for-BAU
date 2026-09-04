<?php
/**
 * Core Functions for Classroom Management System
 */
/**
 * Check if a room is available for booking
 * CRITICAL: Only considers status = 'booked' for bookings
 *           Only considers status = 'scheduled' for schedules
 *           Cancelled bookings/schedules are IGNORED
 * 
 * @param PDO $pdo Database connection
 * @param int $room_id Room ID to check
 * @param string $date Date in Y-m-d format
 * @param string $start_time Time in H:i:s format
 * @param string $end_time Time in H:i:s format
 * @param int|null $exclude_booking_id Booking ID to exclude from check (for editing)
 * @return array ['available' => bool, 'conflicts' => array]
 */
function isRoomAvailable($pdo, $room_id, $date, $start_time, $end_time, $exclude_booking_id = null)
{
    // Check 1: One-time bookings (ONLY status = 'booked', ignore 'cancelled')
    $sql = "SELECT 
                rb.id,
                rb.start_time,
                rb.end_time,
                rb.purpose,
                u.full_name as booked_by_name
            FROM room_bookings rb
            JOIN users u ON rb.booked_by = u.id
            WHERE rb.room_id = ?
              AND rb.booking_date = ?
              AND rb.status = 'booked'  -- CRITICAL: Cancelled bookings ignored
              AND rb.start_time < ?      -- Overlap detection
              AND rb.end_time > ?";

    $params = [$room_id, $date, $end_time, $start_time];

    if ($exclude_booking_id) {
        $sql .= " AND rb.id != ?";
        $params[] = $exclude_booking_id;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($conflict = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [
            'available' => false,
            'conflicts' => [
                [
                    'type' => 'one_time_booking',
                    'booked_by' => $conflict['booked_by_name'],
                    'purpose' => $conflict['purpose'],
                    'time' => date('H:i', strtotime($conflict['start_time'])) . ' - ' . date('H:i', strtotime($conflict['end_time'])),
                    'blocking_id' => $conflict['id']
                ]
            ]
        ];
    }

    // Check 2: Recurring schedules (ONLY status = 'scheduled', ignore 'cancelled')
    $day_of_week = date('N', strtotime($date)); // 1=Mon, 7=Sun

    $sql = "SELECT 
                cs.id,
                cs.start_time,
                cs.end_time,
                cs.course_code,
                cs.course_name,
                u.full_name as teacher_name
            FROM course_schedule cs
            LEFT JOIN users u ON cs.teacher_id = u.id
            WHERE cs.room_id = ?
              AND cs.day_of_week = ?
              AND cs.status = 'scheduled'  -- CRITICAL: Cancelled schedules ignored
              AND ? BETWEEN cs.start_date AND cs.end_date
              AND cs.start_time < ?
              AND cs.end_time > ?
              -- Exclude if this specific date has an exception
              AND NOT EXISTS (
                  SELECT 1 FROM schedule_exceptions se
                  WHERE se.course_schedule_id = cs.id
                    AND se.exception_date = ?
                    AND se.exception_type = 'cancelled'
              )
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $room_id,
        $day_of_week,
        $date,
        $end_time,
        $start_time,
        $date
    ]);

    if ($conflict = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [
            'available' => false,
            'conflicts' => [
                [
                    'type' => 'recurring_schedule',
                    'teacher' => $conflict['teacher_name'],
                    'course' => $conflict['course_code'] . ' - ' . $conflict['course_name'],
                    'schedule' => 'Recurring schedule throughout semester',
                    'time' => date('H:i', strtotime($conflict['start_time'])) . ' - ' . date('H:i', strtotime($conflict['end_time'])),
                    'blocking_id' => $conflict['id']
                ]
            ]
        ];
    }

    return ['available' => true, 'conflicts' => []];
}
/**
 * Check if a recurring course schedule would conflict with existing bookings
 */
function validateScheduleAgainstBookings($pdo, $room_id, $day_of_week, $start_time, $end_time, $start_date = null, $end_date = null, $exclude_schedule_id = null)
{
    // Get semester bounds if not provided
    if (!$start_date || !$end_date) {
        $semester = getActiveSemester($pdo);
        if ($semester) {
            $start_date = $start_date ?? $semester['start_date'];
            $end_date = $end_date ?? $semester['end_date'];
        } else {
            // Default to next 120 days if no active semester
            $start_date = $start_date ?? date('Y-m-d');
            $end_date = $end_date ?? date('Y-m-d', strtotime('+120 days'));
        }
    }

    // Check for conflicts with room_bookings
    // We need to find bookings that fall on the schedule's day_of_week and have overlapping times
    $sql = "SELECT rb.id, rb.booking_date, rb.start_time, rb.end_time, rb.purpose, u.full_name
            FROM room_bookings rb
            JOIN users u ON rb.booked_by = u.id
            WHERE rb.room_id = ?
            AND rb.status = 'active'
            AND rb.booking_date BETWEEN ? AND ?
            AND rb.start_time < ?
            AND rb.end_time > ?
            AND DAYOFWEEK(rb.booking_date) = ?";

    // Convert our day_of_week (1=Mon...7=Sun) to MySQL DAYOFWEEK (1=Sun, 2=Mon...7=Sat)
    // Our: 1=Mon, 2=Tue, 3=Wed, 4=Thu, 5=Fri, 6=Sat, 7=Sun
    // MySQL: 1=Sun, 2=Mon, 3=Tue, 4=Wed, 5=Thu, 6=Fri, 7=Sat
    $mysql_day = ($day_of_week % 7) + 1; // 1->2, 2->3, ..., 6->7, 7->1

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$room_id, $start_date, $end_date, $end_time, $start_time, $mysql_day]);

    $conflicts = $stmt->fetchAll();

    if (!empty($conflicts)) {
        return [
            'valid' => false,
            'conflicts' => $conflicts,
            'message' => 'This schedule conflicts with ' . count($conflicts) . ' existing booking(s) on ' . getDayName($day_of_week) . 's'
        ];
    }

    // Also check for conflicts with other course schedules
    $sql2 = "SELECT id FROM course_schedule
             WHERE room_id = ?
             AND day_of_week = ?
             AND status = 'scheduled'
             AND start_time < ?
             AND end_time > ?";

    $params = [$room_id, $day_of_week, $end_time, $start_time];

    if ($exclude_schedule_id) {
        $sql2 .= " AND id != ?";
        $params[] = $exclude_schedule_id;
    }

    $stmt = $pdo->prepare($sql2);
    $stmt->execute($params);

    if ($stmt->fetch()) {
        return [
            'valid' => false,
            'message' => 'This schedule conflicts with another course schedule on ' . getDayName($day_of_week) . 's'
        ];
    }

    return ['valid' => true];
}
/**
 * Get room availability for a specific date
 */
function getRoomScheduleForDate($pdo, $room_id, $date)
{
    $day_of_week = date('N', strtotime($date));
    $schedule = [];
    // Get bookings for the date
    $stmt = $pdo->prepare("
        SELECT rb.*, u.full_name as booked_by_name, 'booking' as type
        FROM room_bookings rb
        JOIN users u ON rb.booked_by = u.id
        WHERE rb.room_id = ? AND rb.booking_date = ? AND rb.status = 'active'
        ORDER BY rb.start_time
    ");
    $stmt->execute([$room_id, $date]);
    $schedule = array_merge($schedule, $stmt->fetchAll());
    // Get scheduled classes for this day
    $stmt = $pdo->prepare("
        SELECT cs.*, u.full_name as teacher_name, dp.name as degree_name, 'schedule' as type
        FROM course_schedule cs
        LEFT JOIN users u ON cs.teacher_id = u.id
        LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
        WHERE cs.room_id = ? AND cs.day_of_week = ? AND cs.status = 'scheduled'
        AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))
        ORDER BY cs.start_time
    ");
    $stmt->execute([$room_id, $day_of_week, $date, $date]);
    $schedule = array_merge($schedule, $stmt->fetchAll());
    // Sort by start time
    usort($schedule, function ($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });
    return $schedule;
}
/**
 * Create a notification
 */
function createNotification($pdo, $user_id, $title, $message, $type, $related_type = null, $related_id = null)
{
    $stmt = $pdo->prepare("
        INSERT INTO notifications (user_id, title, message, type, related_type, related_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$user_id, $title, $message, $type, $related_type, $related_id]);
}
/**
 * Get unread notification count for a user
 */
function getUnreadNotificationCount($pdo, $user_id)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return $stmt->fetch()['count'];
}
/**
 * Get notifications for a user
 */
function getNotifications($pdo, $user_id, $limit = 20)
{
    $stmt = $pdo->prepare("
        SELECT * FROM notifications 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT ?
    ");
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, (int) $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
/**
 * Log an activity
 */
function logActivity($pdo, $user_id, $action, $entity_type, $entity_id = null, $room_id = null, $details = null)
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO activity_log (user_id, action, entity_type, entity_id, room_id, details, ip_address)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    return $stmt->execute([$user_id, $action, $entity_type, $entity_id, $room_id, $details, $ip]);
}
/**
 * Get active semester
 */
function getActiveSemester($pdo)
{
    $stmt = $pdo->query("SELECT * FROM semesters WHERE is_active = 1 LIMIT 1");
    return $stmt->fetch();
}
/**
 * Book a room (instant booking)
 * Updated to use 'booked' status and log failed attempts
 */
function bookRoom($pdo, $room_id, $user_id, $date, $start_time, $end_time, $purpose, $extra_data = [])
{
    // Validate date is not in past
    if ($date < date('Y-m-d')) {
        return ['success' => false, 'error' => 'Cannot book past dates'];
    }

    // Check availability first
    $availability = isRoomAvailable($pdo, $room_id, $date, $start_time, $end_time);

    if (!$availability['available']) {
        // Log failed attempt to booking_attempts table
        $conflicts = $availability['conflicts'];
        $conflict = $conflicts[0]; // Get first conflict

        $conflict_type = ($conflict['type'] == 'one_time_booking') ? 'room_booked' : 'schedule_conflict';
        $blocking_booking_id = ($conflict['type'] == 'one_time_booking') ? $conflict['blocking_id'] : null;
        $blocking_schedule_id = ($conflict['type'] == 'recurring_schedule') ? $conflict['blocking_id'] : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO booking_attempts (
                    user_id, user_type, room_id, 
                    attempted_date, attempted_start_time, attempted_end_time,
                    purpose, conflict_type, 
                    blocking_booking_id, blocking_schedule_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $user = getUserById($pdo, $user_id);
            $stmt->execute([
                $user_id,
                $user['user_type'],
                $room_id,
                $date,
                $start_time,
                $end_time,
                $purpose,
                $conflict_type,
                $blocking_booking_id,
                $blocking_schedule_id
            ]);
        } catch (Exception $e) {
            error_log("Failed to log booking attempt: " . $e->getMessage());
        }

        // Return error with conflict details
        return [
            'success' => false,
            'error' => 'Room is not available for the selected time slot',
            'conflicts' => $conflicts
        ];
    }

    // Check semester bounds
    $semester = getActiveSemester($pdo);
    if ($semester && $date > $semester['end_date']) {
        return ['success' => false, 'error' => 'Cannot book beyond the current semester end date'];
    }

    // Insert booking with status = 'booked'
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO room_bookings (
                room_id, booked_by, purpose, booking_date, start_time, end_time, 
                degree_program_id, level, semester, group_name, batch, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked')
        ");

        $stmt->execute([
            $room_id,
            $user_id,
            $purpose,
            $date,
            $start_time,
            $end_time,
            $extra_data['degree_program_id'] ?? null,
            $extra_data['level'] ?? null,
            $extra_data['semester'] ?? null,
            $extra_data['group_name'] ?? null,
            $extra_data['batch'] ?? null
        ]);

        $booking_id = $pdo->lastInsertId();

        // Log activity
        logActivity(
            $pdo,
            $user_id,
            'book_room',
            'room_booking',
            $booking_id,
            $room_id,
            json_encode(['purpose' => $purpose, 'date' => $date])
        );

        $pdo->commit();
        return ['success' => true, 'booking_id' => $booking_id];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Booking error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to create booking'];
    }
}
/**
 * Cancel a room booking
 * Updated to use status = 'cancelled' and new column names
 */
function freeRoom($pdo, $booking_id, $user_id, $reason = '')
{
    try {
        $pdo->beginTransaction();

        // Get booking details first
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, u.full_name as booked_by_name, u.id as booked_by_id, u.user_type
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN users u ON rb.booked_by = u.id
            WHERE rb.id = ?
        ");
        $stmt->execute([$booking_id]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Booking not found'];
        }

        if ($booking['status'] == 'cancelled') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Booking is already cancelled'];
        }

        // Cannot cancel past bookings
        $booking_end = $booking['booking_date'] . ' ' . $booking['end_time'];
        if (strtotime($booking_end) < time()) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Cannot cancel past bookings'];
        }

        // Update booking status to 'cancelled'
        $stmt = $pdo->prepare("
            UPDATE room_bookings 
            SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$user_id, $reason, $booking_id]);

        // Notify CRs if this was a class
        $current_user = getUserById($pdo, $user_id);
        if ($booking['degree_program_id']) {
            $crs = getCRsForDegree($pdo, $booking['degree_program_id'], $booking['level'], $booking['semester']);
            foreach ($crs as $cr) {
                createNotification(
                    $pdo,
                    $cr['id'],
                    'Class Cancelled',
                    "Your class in {$booking['room_name']} on " .
                    date('M d, Y', strtotime($booking['booking_date'])) .
                    " has been cancelled. Reason: " . ($reason ?: 'Not specified'),
                    'schedule_change',
                    'booking',
                    $booking_id
                );
            }
        }

        // Notify users who failed to book this slot
        $stmt = $pdo->prepare("
            SELECT * FROM booking_attempts
            WHERE room_id = ?
              AND attempted_date = ?
              AND is_resolved = FALSE
              AND attempted_start_time < ?
              AND attempted_end_time > ?
        ");
        $stmt->execute([
            $booking['room_id'],
            $booking['booking_date'],
            $booking['end_time'],
            $booking['start_time']
        ]);

        $failed_attempts = $stmt->fetchAll();
        foreach ($failed_attempts as $attempt) {
            createNotification(
                $pdo,
                $attempt['user_id'],
                'Room Now Available',
                "Good news! {$booking['room_name']} is now available on " .
                date('M d, Y', strtotime($booking['booking_date'])) .
                " from {$booking['start_time']} to {$booking['end_time']}. Reason: " . ($reason ?: 'Booking cancelled'),
                'booking_freed',
                'booking',
                $booking_id
            );

            // Mark attempt as resolved
            $update_stmt = $pdo->prepare("
                UPDATE booking_attempts 
                SET is_resolved = TRUE, notified_at = NOW()
                WHERE id = ?
            ");
            $update_stmt->execute([$attempt['id']]);
        }

        // Log activity
        logActivity(
            $pdo,
            $user_id,
            'free_room',
            'room_booking',
            $booking_id,
            $booking['room_id'],
            json_encode(['reason' => $reason])
        );

        $pdo->commit();
        return ['success' => true];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Cancel booking error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to cancel booking'];
    }
}
/**
 * Get user by ID
 */
function getUserById($pdo, $user_id)
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}
/**
 * Get CRs for a specific degree program, level, semester
 */
function getCRsForDegree($pdo, $degree_program_id, $level = null, $semester = null)
{
    $sql = "SELECT * FROM users WHERE user_type = 'cr' AND degree_program_id = ?";
    $params = [$degree_program_id];
    if ($level) {
        $sql .= " AND assigned_level = ?";
        $params[] = $level;
    }
    if ($semester) {
        $sql .= " AND assigned_semester = ?";
        $params[] = $semester;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
/**
 * Get teacher schedule for a date range
 */
function getTeacherSchedule($pdo, $teacher_id, $start_date, $end_date = null)
{
    if (!$end_date) {
        $end_date = $start_date;
    }
    $schedule = [];
    // Get from course_schedule
    $stmt = $pdo->prepare("
        SELECT cs.*, r.room_name, r.room_number, b.name as building_name, 
               dp.name as degree_name, 'schedule' as source
        FROM course_schedule cs
        JOIN rooms r ON cs.room_id = r.id
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
        WHERE cs.teacher_id = ? AND cs.status = 'scheduled'
        AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))
        ORDER BY cs.day_of_week, cs.start_time
    ");
    $stmt->execute([$teacher_id, $end_date, $start_date]);
    $schedule['courses'] = $stmt->fetchAll();
    // Get bookings
    $stmt = $pdo->prepare("
        SELECT rb.*, r.room_name, r.room_number, b.name as building_name,
               dp.name as degree_name, 'booking' as source
        FROM room_bookings rb
        JOIN rooms r ON rb.room_id = r.id
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
        WHERE rb.booked_by = ? AND rb.status = 'active'
        AND rb.booking_date BETWEEN ? AND ?
        ORDER BY rb.booking_date, rb.start_time
    ");
    $stmt->execute([$teacher_id, $start_date, $end_date]);
    $schedule['bookings'] = $stmt->fetchAll();
    return $schedule;
}
/**
 * Get CR's group schedule
 */
function getCRSchedule($pdo, $degree_program_id, $level, $semester, $group = null, $start_date = null, $end_date = null)
{
    $sql = "
        SELECT cs.*, r.room_name, r.room_number, b.name as building_name,
               u.full_name as teacher_name, dp.name as degree_name
        FROM course_schedule cs
        JOIN rooms r ON cs.room_id = r.id
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        LEFT JOIN users u ON cs.teacher_id = u.id
        LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
        WHERE cs.degree_program_id = ? AND cs.level = ? AND cs.semester = ? AND cs.status = 'scheduled'
    ";
    $params = [$degree_program_id, $level, $semester];
    if ($group) {
        $sql .= " AND (cs.group_name = ? OR cs.group_name IS NULL)";
        $params[] = $group;
    }
    if ($start_date && $end_date) {
        $sql .= " AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))";
        $params[] = $end_date;
        $params[] = $start_date;
    }
    $sql .= " ORDER BY cs.day_of_week, cs.start_time";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
/**
 * Get all rooms with optional filters
 */
function getRooms($pdo, $filters = [])
{
    $sql = "
        SELECT r.*, b.name as building_name, b.code as building_code, 
               f.floor_number, fac.name as faculty_name
        FROM rooms r
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        LEFT JOIN faculties fac ON r.faculty_id = fac.id
        WHERE 1=1
    ";
    $params = [];
    if (!empty($filters['building_id'])) {
        $sql .= " AND f.building_id = ?";
        $params[] = $filters['building_id'];
    }
    if (!empty($filters['room_type'])) {
        $sql .= " AND r.room_type = ?";
        $params[] = $filters['room_type'];
    }
    if (!empty($filters['faculty_id'])) {
        $sql .= " AND r.faculty_id = ?";
        $params[] = $filters['faculty_id'];
    }
    if (!empty($filters['min_capacity'])) {
        $sql .= " AND r.capacity >= ?";
        $params[] = $filters['min_capacity'];
    }
    $sql .= " ORDER BY b.name, f.floor_number, r.room_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
/**
 * Get free rooms for a specific date and time
 */
function getFreeRooms($pdo, $date, $start_time, $end_time, $filters = [])
{
    $all_rooms = getRooms($pdo, $filters);
    $free_rooms = [];
    foreach ($all_rooms as $room) {
        $availability = isRoomAvailable($pdo, $room['id'], $date, $start_time, $end_time);
        if ($availability['available']) {
            $free_rooms[] = $room;
        }
    }
    return $free_rooms;
}
/**
 * Format time for display
 */
function formatTime($time)
{
    return date('g:i A', strtotime($time));
}
/**
 * Get day name from number
 */
function getDayName($day_number)
{
    $days = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    return $days[$day_number] ?? '';
}
/**
 * Get short day name
 */
function getShortDayName($day_number)
{
    $days = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    return $days[$day_number] ?? '';
}
