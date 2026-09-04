#!/usr/bin/env php
<?php
/**
 * Send Daily Schedule Reminders
 * Notifies teachers and CRs about classes happening tomorrow
 * 
 * Usage: php send_daily_reminders.php
 * Cron: 0 18 * * * (Every day at 6 PM)
 */

require_once __DIR__ . '/../db_config.php';
require_once __DIR__ . '/../includes/functions.php';

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$day_of_week = date('N', strtotime($tomorrow));

try {
    echo "[" . date('Y-m-d H:i:s') . "] Sending daily reminders for $tomorrow\n";

    $reminder_count = 0;

    // 1. Reminders for one-time bookings
    $stmt = $pdo->prepare("
        SELECT 
            rb.*,
            r.room_name,
            r.room_number,
            b.name as building_name,
            u.id as teacher_id,
            u.full_name as teacher_name
        FROM room_bookings rb
        JOIN rooms r ON rb.room_id = r.id
        JOIN buildings b ON r.building_id = b.id
        JOIN users u ON rb.booked_by = u.id
        WHERE rb.booking_date = ?
          AND rb.status = 'booked'
    ");
    $stmt->execute([$tomorrow]);
    $bookings = $stmt->fetchAll();

    foreach ($bookings as $booking) {
        // Notify teacher
        createNotification(
            $pdo,
            $booking['teacher_id'],
            'Class Reminder',
            "You have a class tomorrow in {$booking['room_name']} ({$booking['building_name']}) " .
            "from " . date('H:i', strtotime($booking['start_time'])) .
            " to " . date('H:i', strtotime($booking['end_time'])),
            'reminder',
            'booking',
            $booking['id']
        );
        $reminder_count++;

        // Notify CRs if applicable
        if ($booking['degree_program_id']) {
            $crs = getCRsForDegree($pdo, $booking['degree_program_id'], $booking['level'], $booking['semester']);
            foreach ($crs as $cr) {
                createNotification(
                    $pdo,
                    $cr['id'],
                    'Class Reminder',
                    "Class by {$booking['teacher_name']} tomorrow in {$booking['room_name']} ({$booking['building_name']}) " .
                    "from " . date('H:i', strtotime($booking['start_time'])) .
                    " to " . date('H:i', strtotime($booking['end_time'])),
                    'reminder',
                    'booking',
                    $booking['id']
                );
                $reminder_count++;
            }
        }
    }

    // 2. Reminders for recurring schedules
    $stmt = $pdo->prepare("
        SELECT 
            cs.*,
            r.room_name,
            r.room_number,
            b.name as building_name,
            u.id as teacher_id,
            u.full_name as teacher_name
        FROM course_schedule cs
        JOIN rooms r ON cs.room_id = r.id
        JOIN buildings b ON r.building_id = b.id
        LEFT JOIN users u ON cs.teacher_id = u.id
        WHERE cs.day_of_week = ?
          AND cs.status = 'scheduled'
          AND ? BETWEEN cs.start_date AND cs.end_date
          -- Exclude if this date has an exception
          AND NOT EXISTS (
              SELECT 1 FROM schedule_exceptions se
              WHERE se.course_schedule_id = cs.id
                AND se.exception_date = ?
                AND se.exception_type = 'cancelled'
          )
    ");
    $stmt->execute([$day_of_week, $tomorrow, $tomorrow]);
    $schedules = $stmt->fetchAll();

    foreach ($schedules as $schedule) {
        // Notify teacher
        if ($schedule['teacher_id']) {
            createNotification(
                $pdo,
                $schedule['teacher_id'],
                'Class Reminder',
                "You have {$schedule['course_code']} class tomorrow in {$schedule['room_name']} ({$schedule['building_name']}) " .
                "from " . date('H:i', strtotime($schedule['start_time'])) .
                " to " . date('H:i', strtotime($schedule['end_time'])),
                'reminder',
                'schedule',
                $schedule['id']
            );
            $reminder_count++;
        }

        // Notify CRs
        if ($schedule['degree_program_id']) {
            $crs = getCRsForDegree($pdo, $schedule['degree_program_id'], $schedule['level'], $schedule['semester_num']);
            foreach ($crs as $cr) {
                createNotification(
                    $pdo,
                    $cr['id'],
                    'Class Reminder',
                    "{$schedule['course_code']} class tomorrow in {$schedule['room_name']} ({$schedule['building_name']}) " .
                    "from " . date('H:i', strtotime($schedule['start_time'])) .
                    " to " . date('H:i', strtotime($schedule['end_time'])),
                    'reminder',
                    'schedule',
                    $schedule['id']
                );
                $reminder_count++;
            }
        }
    }

    echo "Sent {$reminder_count} reminders for " . count($bookings) . " bookings and " . count($schedules) . " recurring schedules\n";
    echo "[" . date('Y-m-d H:i:s') . "] Reminders sent successfully\n";

} catch (Exception $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    error_log("Daily reminder error: " . $e->getMessage());
    exit(1);
}
