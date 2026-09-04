<?php
/**
 * New Core Functions for Business Logic
 * These functions implement the algorithms from algorithms.md
 */

/**
 * Cancel a single instance of a recurring schedule
 * Algorithm 3: Cancel Schedule Instance
 * 
 * @param PDO $pdo Database connection
 * @param int $schedule_id Course schedule ID
 * @param string $date Specific date to cancel (Y-m-d format)
 * @param int $cancelled_by User ID performing the cancellation
 * @param string $reason Reason for cancellation
 * @return array ['success' => bool, 'error' => string]
 */
function cancelScheduleInstance($pdo, $schedule_id, $date, $cancelled_by, $reason = '')
{
    try {
        $pdo->beginTransaction();

        // Get schedule details
        $stmt = $pdo->prepare("
            SELECT cs.*, r.room_name, u.full_name as teacher_name
            FROM course_schedule cs
            JOIN rooms r ON cs.room_id = r.id
            LEFT JOIN users u ON cs.teacher_id = u.id
            WHERE cs.id = ?
        ");
        $stmt->execute([$schedule_id]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Schedule not found'];
        }

        if ($schedule['status'] != 'scheduled') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Schedule is not active'];
        }

        // Verify date falls within schedule range
        if ($date < $schedule['start_date'] || $date > $schedule['end_date']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Date is outside schedule range'];
        }

        // Verify day of week matches
        $day_of_week = date('N', strtotime($date));
        if ($day_of_week != $schedule['day_of_week']) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Date does not match schedule day of week'];
        }

        // Check if exception already exists
        $stmt = $pdo->prepare("
            SELECT id FROM schedule_exceptions 
            WHERE course_schedule_id = ? AND exception_date = ?
        ");
        $stmt->execute([$schedule_id, $date]);

        if ($stmt->fetch()) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'This class instance is already cancelled'];
        }

        // Create exception
        $stmt = $pdo->prepare("
            INSERT INTO schedule_exceptions (
                course_schedule_id, exception_date, exception_type,
                created_by, reason
            ) VALUES (?, ?, 'cancelled', ?, ?)
        ");
        $stmt->execute([$schedule_id, $date, $cancelled_by, $reason]);

        // Notify relevant CRs
        if ($schedule['degree_program_id']) {
            $crs = getCRsForDegree($pdo, $schedule['degree_program_id'], $schedule['level'], $schedule['semester_num']);
            foreach ($crs as $cr) {
                createNotification(
                    $pdo,
                    $cr['id'],
                    'Class Cancelled',
                    "{$schedule['course_code']} class in {$schedule['room_name']} on " .
                    date('M d, Y', strtotime($date)) .
                    " has been cancelled. Reason: " . ($reason ?: 'Not specified'),
                    'schedule_change',
                    'schedule',
                    $schedule_id
                );
            }
        }

        // Log activity
        logActivity(
            $pdo,
            $cancelled_by,
            'cancel_schedule_instance',
            'course_schedule',
            $schedule_id,
            $schedule['room_id'],
            json_encode(['date' => $date, 'reason' => $reason])
        );

        $pdo->commit();
        return ['success' => true];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Cancel schedule instance error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to cancel class instance'];
    }
}

/**
 * Cancel entire recurring schedule
 * Algorithm 4: Cancel Entire Schedule
 */
function cancelEntireSchedule($pdo, $schedule_id, $cancelled_by, $reason = '')
{
    try {
        $pdo->beginTransaction();

        // Get schedule details
        $stmt = $pdo->prepare("
            SELECT cs.*, r.room_name
            FROM course_schedule cs
            JOIN rooms r ON cs.room_id = r.id
            WHERE cs.id = ?
        ");
        $stmt->execute([$schedule_id]);
        $schedule = $stmt->fetch();

        if (!$schedule) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Schedule not found'];
        }

        if ($schedule['status'] != 'scheduled') {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Schedule is not active'];
        }

        // Update schedule status to 'cancelled'
        $stmt = $pdo->prepare("
            UPDATE course_schedule 
            SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ?
            WHERE id = ?
        ");
        $stmt->execute([$cancelled_by, $reason, $schedule_id]);

        // Notify relevant CRs
        if ($schedule['degree_program_id']) {
            $crs = getCRsForDegree($pdo, $schedule['degree_program_id'], $schedule['level'], $schedule['semester_num']);
            foreach ($crs as $cr) {
                createNotification(
                    $pdo,
                    $cr['id'],
                    'Recurring Class Cancelled',
                    "The recurring schedule for {$schedule['course_code']} in {$schedule['room_name']} " .
                    "has been cancelled. Reason: " . ($reason ?: 'Not specified'),
                    'schedule_change',
                    'schedule',
                    $schedule_id
                );
            }
        }

        // Log activity
        logActivity(
            $pdo,
            $cancelled_by,
            'cancel_entire_schedule',
            'course_schedule',
            $schedule_id,
            $schedule['room_id'],
            json_encode(['reason' => $reason])
        );

        $pdo->commit();
        return ['success' => true];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Cancel schedule error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to cancel schedule'];
    }
}

/**
 * Get room utilization statistics
 * Used for analytics dashboard
 */
function getRoomUtilization($pdo, $room_id, $start_date, $end_date)
{
    // Total available hours (weekdays only, 8am-8pm)
    $total_days = 0;
    $current = strtotime($start_date);
    $end = strtotime($end_date);

    while ($current <= $end) {
        $day_of_week = date('N', $current);
        if ($day_of_week <= 5) { // Mon-Fri only
            $total_days++;
        }
        $current = strtotime('+1 day', $current);
    }

    $total_available_hours = $total_days * 12; // 12 hours per day (8am-8pm)

    // Calculate booked hours from one-time bookings
    $stmt = $pdo->prepare("
        SELECT SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600) as booked_hours
        FROM room_bookings
        WHERE room_id = ?
          AND booking_date BETWEEN ? AND ?
          AND status = 'booked'
    ");
    $stmt->execute([$room_id, $start_date, $end_date]);
    $one_time_hours = $stmt->fetchColumn() ?: 0;

    // Calculate scheduled hours from recurring schedules
    $stmt = $pdo->prepare("
        SELECT 
            cs.day_of_week,
            TIME_TO_SEC(TIMEDIFF(cs.end_time, cs.start_time)) / 3600 as hours_per_occurrence,
            cs.start_date,
            cs.end_date
        FROM course_schedule cs
        WHERE cs.room_id = ?
          AND cs.status = 'scheduled'
          AND cs.start_date <= ?
          AND cs.end_date >= ?
    ");
    $stmt->execute([$room_id, $end_date, $start_date]);
    $schedules = $stmt->fetchAll();

    $recurring_hours = 0;
    foreach ($schedules as $sched) {
        // Count occurrences of this day of week in the date range
        $occurrences = 0;
        $current = strtotime(max($start_date, $sched['start_date']));
        $end = strtotime(min($end_date, $sched['end_date']));

        while ($current <= $end) {
            if (date('N', $current) == $sched['day_of_week']) {
                $occurrences++;
            }
            $current = strtotime('+1 day', $current);
        }

        $recurring_hours += $occurrences * $sched['hours_per_occurrence'];
    }

    $total_booked_hours = $one_time_hours + $recurring_hours;
    $utilization_percentage = $total_available_hours > 0
        ? round(($total_booked_hours / $total_available_hours) * 100, 2)
        : 0;

    return [
        'room_id' => $room_id,
        'total_available_hours' => $total_available_hours,
        'booked_hours' => round($total_booked_hours, 2),
        'utilization_percentage' => $utilization_percentage
    ];
}

/**
 * Get booking statistics for analytics
 */
function getBookingStats($pdo, $start_date, $end_date)
{
    $stats = [];

    // Total bookings
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM room_bookings
        WHERE booking_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['total_bookings'] = $stmt->fetchColumn();

    // Active bookings
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as active
        FROM room_bookings
        WHERE booking_date BETWEEN ? AND ?
          AND status = 'booked'
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['active_bookings'] = $stmt->fetchColumn();

    // Cancelled bookings
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cancelled
        FROM room_bookings
        WHERE booking_date BETWEEN ? AND ?
          AND status = 'cancelled'
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['cancelled_bookings'] = $stmt->fetchColumn();

    // Failed attempts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as failed
        FROM booking_attempts
        WHERE attempted_date BETWEEN ? AND ?
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['failed_attempts'] = $stmt->fetchColumn();

    // Conflict breakdown
    $stmt = $pdo->prepare("
        SELECT conflict_type, COUNT(*) as count
        FROM booking_attempts
        WHERE attempted_date BETWEEN ? AND ?
        GROUP BY conflict_type
    ");
    $stmt->execute([$start_date, $end_date]);
    $stats['conflicts_by_type'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    return $stats;
}

/**
 * Get peak usage times for a room
 */
function getPeakUsageTimes($pdo, $room_id, $start_date, $end_date)
{
    // Group bookings by hour
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(start_time) as hour,
            COUNT(*) as booking_count
        FROM room_bookings
        WHERE room_id = ?
          AND booking_date BETWEEN ? AND ?
          AND status = 'booked'
        GROUP BY HOUR(start_time)
        ORDER BY booking_count DESC
        LIMIT 5
    ");
    $stmt->execute([$room_id, $start_date, $end_date]);

    return $stmt->fetchAll();
}
