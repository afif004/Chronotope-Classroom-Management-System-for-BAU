<?php
/**
 * Check if a recurring schedule conflicts with existing schedules
 * 
 * @param PDO $pdo Database connection
 * @param int $room_id Room ID
 * @param int $day_of_week Day of week (1=Mon, 7=Sun)
 * @param string $start_time Start time
 * @param string $end_time End time
 * @param string|null $start_date Start date of schedule
 * @param string|null $end_date End date of schedule
 * @param int|null $exclude_schedule_id Schedule ID to exclude (for editing)
 * @return array ['available' => bool, 'conflicts' => array]
 */
function checkRecurringScheduleAvailability($pdo, $room_id, $day_of_week, $start_time, $end_time, $start_date = null, $end_date = null, $exclude_schedule_id = null)
{
    // Check for conflicts with other recurring schedules
    $sql = "SELECT 
                cs.id,
                cs.course_code,
                cs.course_name,
                cs.start_time,
                cs.end_time,
                cs.start_date,
                cs.end_date,
                u.full_name as teacher_name
            FROM course_schedule cs
            LEFT JOIN users u ON cs.teacher_id = u.id
            WHERE cs.room_id = ?
              AND cs.day_of_week = ?
              AND cs.status = 'scheduled'  -- Only check active schedules
              AND cs.start_time < ?      -- Overlap detection
              AND cs.end_time > ?";

    $params = [$room_id, $day_of_week, $end_time, $start_time];

    // Check date overlap if dates are provided
    if ($start_date && $end_date) {
        $sql .= " AND cs.end_date >= ? AND cs.start_date <= ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }

    if ($exclude_schedule_id) {
        $sql .= " AND cs.id != ?";
        $params[] = $exclude_schedule_id;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    if ($conflict = $stmt->fetch(PDO::FETCH_ASSOC)) {
        return [
            'available' => false,
            'conflicts' => [
                [
                    'type' => 'recurring_schedule',
                    'teacher' => $conflict['teacher_name'] ?? 'Unknown',
                    'course' => $conflict['course_code'] . ' - ' . $conflict['course_name'],
                    'time' => date('H:i', strtotime($conflict['start_time'])) . ' - ' . date('H:i', strtotime($conflict['end_time'])),
                    'blocking_id' => $conflict['id']
                ]
            ]
        ];
    }

    return ['available' => true, 'conflicts' => []];
}
