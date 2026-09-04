<?php
// room_status.php
// This file contains the room status calendar display

require_once 'db_config.php';

function getRoomStatusForDate($pdo, $date)
{
    $rooms = getRooms($pdo);
    $statuses = [];

    foreach ($rooms as $room) {
        // Get bookings for this room on the given date
        $stmt = $pdo->prepare("
            SELECT rb.*, 
                   u.full_name as booked_by_name,
                   cs.course_code,
                   cs.course_name
            FROM room_bookings rb
            LEFT JOIN users u ON rb.booked_by = u.id
            LEFT JOIN course_schedule cs ON rb.course_schedule_id = cs.id
            WHERE rb.room_id = ? 
            AND rb.booking_date = ?
            AND rb.status = 'booked'       -- Fixed: using 'booked' status
            ORDER BY rb.start_time
        ");
        $stmt->execute([$room['id'], $date]);
        $bookings = $stmt->fetchAll();

        // Get scheduled classes for this room on the given date
        // CRITICAL: Exclude if there's an exception for this specific date
        $stmt = $pdo->prepare("
            SELECT cs.*, 
                   u.full_name as teacher_name,
                   dp.name as degree_name
            FROM course_schedule cs
            LEFT JOIN users u ON cs.teacher_id = u.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            WHERE cs.room_id = ?
            AND cs.day_of_week = DAYOFWEEK(?)   -- Note: MySQL DAYOFWEEK is 1=Sun, 7=Sat. Our schema might use 1=Mon.
            AND cs.status = 'scheduled'         -- Fixed: using 'scheduled' status
            AND (? BETWEEN cs.start_date AND cs.end_date)
            AND NOT EXISTS (
                SELECT 1 FROM schedule_exceptions se 
                WHERE se.course_schedule_id = cs.id 
                AND se.exception_date = ? 
                AND se.exception_type = 'cancelled'
            )
            ORDER BY cs.start_time
        ");

        // For MySQL DAYOFWEEK: 1=Sunday, 2=Monday, ..., 7=Saturday
        // Our schema uses: 1=Monday, ..., 7=Sunday (ISO-8601-like but 1-based)
        // We need to convert or check what DAYOFWEEK(?) returns vs what's in DB.
        // Let's assume the DB has 1=Monday. 
        // MySQL DAYOFWEEK('2026-02-09') -> Monday -> 2. 
        // We need 1. So (DAYOFWEEK(date) + 5) % 7 + 1 converts MySQL 1..7 (Sun-Sat) to 1..7 (Mon-Sun)

        $sql_sched = "
            SELECT cs.*, 
                   u.full_name as teacher_name,
                   dp.name as degree_name
            FROM course_schedule cs
            LEFT JOIN users u ON cs.teacher_id = u.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            WHERE cs.room_id = ?
            AND cs.day_of_week = (DAYOFWEEK(?) + 5) % 7 + 1
            AND cs.status = 'scheduled'
            AND (? BETWEEN cs.start_date AND cs.end_date)
            AND NOT EXISTS (
                SELECT 1 FROM schedule_exceptions se 
                WHERE se.course_schedule_id = cs.id 
                AND se.exception_date = ? 
                AND se.exception_type = 'cancelled'
            )
            ORDER BY cs.start_time
        ";

        $stmt = $pdo->prepare($sql_sched);
        $stmt->execute([$room['id'], $date, $date, $date]);
        $classes = $stmt->fetchAll();

        $roomStatus = [
            'room' => $room,
            'bookings' => $bookings,
            'classes' => $classes,
            'time_slots' => []
        ];

        // Get time slots
        $timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();

        foreach ($timeSlots as $slot) {
            $slotStatus = 'available'; // Default

            // Check if there's a class in this time slot
            foreach ($classes as $class) {
                if (timeOverlaps($slot['start_time'], $slot['end_time'], $class['start_time'], $class['end_time'])) {
                    $slotStatus = 'class';
                    $slotData = [
                        'type' => 'class',
                        'course_code' => $class['course_code'],
                        'teacher' => $class['teacher_name']
                    ];
                    break;
                }
            }

            // Check if there's a booking in this time slot
            if ($slotStatus === 'available') {
                foreach ($bookings as $booking) {
                    if (timeOverlaps($slot['start_time'], $slot['end_time'], $booking['start_time'], $booking['end_time'])) {
                        $slotStatus = 'booked';
                        $slotData = [
                            'type' => 'booking',
                            'purpose' => $booking['purpose'],
                            'booked_by' => $booking['booked_by_name']
                        ];
                        break;
                    }
                }
            }

            $roomStatus['time_slots'][$slot['id']] = [
                'slot' => $slot,
                'status' => $slotStatus,
                'data' => $slotData ?? null
            ];
        }

        $statuses[] = $roomStatus;
    }

    return $statuses;
}

function getDayStatusLabel($status)
{
    switch ($status) {
        case 'available':
            return 'bg-green-500';
        case 'class':
            return 'bg-blue-500';
        case 'booked':
            return 'bg-yellow-500';
        default:
            return 'bg-gray-200';
    }
}

function getDayStatusText($status)
{
    switch ($status) {
        case 'available':
            return 'Available';
        case 'class':
            return 'Class';
        case 'booked':
            return 'Booked';
        default:
            return 'Unknown';
    }
}

function timeOverlaps($slotStart, $slotEnd, $eventStart, $eventEnd)
{
    $slotStart = strtotime($slotStart);
    $slotEnd = strtotime($slotEnd);
    $eventStart = strtotime($eventStart);
    $eventEnd = strtotime($eventEnd);

    return ($eventStart < $slotEnd && $eventEnd > $slotStart);
}

function displayRoomStatusCalendar($date = null, $view = 'week')
{
    global $pdo;

    if (!$date) {
        $date = date('Y-m-d');
    }

    // Week view
    if ($view === 'week') {
        // Get day of week (0 = Sunday, 1 = Monday, etc.)
        $dayOfWeek = date('w', strtotime($date));

        // Create array of days for the week starting from Sunday
        $weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $currentDate = date('Y-m-d', strtotime($date . ' +' . ($i - $dayOfWeek) . ' days'));
            $weekDays[] = [
                'date' => $currentDate,
                'day_name' => date('D', strtotime($currentDate)),
                'day_number' => date('j', strtotime($currentDate)),
                'is_today' => $currentDate == date('Y-m-d')
            ];
        }

        // Get rooms
        $rooms = $pdo->query("
            SELECT r.*, b.name as building_name, f.floor_number
            FROM rooms r
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            ORDER BY b.name, f.floor_number, r.room_number
        ")->fetchAll();

        // For each day, get room statuses
        $roomStatuses = [];
        foreach ($weekDays as $day) {
            $roomStatuses[$day['date']] = getRoomStatusForDate($pdo, $day['date']);
        }

        // Display the calendar
        ?>
        <style>
            .calendar-container {
                background: white;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                width: 100%;
                overflow-x: hidden;
            }

            .calendar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .calendar-nav {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }

            .calendar-nav-btn {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                font-size: 14px;
            }

            .calendar-title {
                font-size: 1.2rem;
                font-weight: 700;
                color: #1e40af;
            }

            .calendar-title-wrapper {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }

            .month-display {
                font-weight: 600;
                padding: 5px 10px;
                display: none;
            }

            .calendar-grid-wrapper {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .calendar-grid {
                display: grid;
                grid-template-columns: 150px repeat(7, 1fr);
                gap: 1px;
                background: #e5e7eb;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                overflow: hidden;
                min-width: 700px;
            }

            .calendar-cell {
                background: white;
                padding: 8px 5px;
                min-height: 60px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                word-break: break-word;
            }

            .calendar-cell.header {
                background: #f3f4f6;
                font-weight: 600;
                color: #374151;
                padding: 10px 5px;
            }

            .calendar-cell.today {
                background: #dbeafe;
            }

            .calendar-cell.room-name {
                align-items: flex-start;
                background: #f9fafb;
                font-weight: 500;
                color: #1f2937;
                padding: 8px;
                font-size: 12px;
            }

            .status-dot {
                width: 10px;
                height: 10px;
                border-radius: 50%;
                display: inline-block;
                margin-right: 4px;
                flex-shrink: 0;
            }

            .status-available {
                background: #10b981;
            }

            .status-class {
                background: #3b82f6;
            }

            .status-booked {
                background: #f59e0b;
            }

            .status-legend {
                display: flex;
                gap: 15px;
                margin-top: 20px;
                justify-content: center;
                flex-wrap: wrap;
            }

            .legend-item {
                display: flex;
                align-items: center;
                gap: 6px;
                font-size: 14px;
            }

            .day-header {
                text-align: center;
                width: 100%;
            }

            .day-number {
                font-size: 1rem;
                font-weight: 700;
                color: #1e40af;
                margin-bottom: 2px;
            }

            .day-name {
                font-size: 0.85rem;
                color: #6b7280;
            }

            .room-status {
                font-size: 0.75rem;
                text-align: center;
                margin-top: 2px;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-wrap: wrap;
                gap: 3px;
                width: 100%;
            }

            .room-info {
                font-size: 0.7rem;
                color: #6b7280;
                margin-top: 2px;
            }

            @media (max-width: 768px) {
                .calendar-container {
                    padding: 15px;
                }

                .calendar-header {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .calendar-nav {
                    width: 100%;
                    justify-content: space-between;
                }

                .calendar-title {
                    font-size: 1.1rem;
                }

                .calendar-nav-btn {
                    padding: 6px 12px;
                    font-size: 13px;
                }

                .month-display {
                    display: block;
                    font-size: 0.9rem;
                    padding: 4px 8px;
                    background: #f3f4f6;
                    border-radius: 4px;
                }

                .calendar-grid {
                    grid-template-columns: 120px repeat(7, 1fr);
                    min-width: 600px;
                }

                .calendar-cell {
                    padding: 6px 4px;
                    min-height: 50px;
                    font-size: 11px;
                }

                .calendar-cell.room-name {
                    font-size: 10px;
                    padding: 6px 4px;
                }

                .day-number {
                    font-size: 0.9rem;
                }

                .day-name {
                    font-size: 0.75rem;
                }

                .room-status {
                    font-size: 0.65rem;
                }

                .status-dot {
                    width: 8px;
                    height: 8px;
                    margin-right: 3px;
                }

                .legend-item {
                    font-size: 12px;
                    gap: 4px;
                }

                .room-info {
                    font-size: 0.6rem;
                }
            }

            @media (max-width: 480px) {
                .calendar-container {
                    padding: 10px;
                }

                .calendar-grid {
                    grid-template-columns: 100px repeat(7, 1fr);
                    min-width: 500px;
                }

                .calendar-cell {
                    padding: 4px 3px;
                    min-height: 45px;
                    font-size: 10px;
                }

                .calendar-cell.room-name {
                    font-size: 9px;
                    padding: 4px 3px;
                }

                .calendar-nav-btn {
                    padding: 5px 10px;
                    font-size: 12px;
                }

                .day-number {
                    font-size: 0.8rem;
                }

                .day-name {
                    font-size: 0.7rem;
                }

                .room-status {
                    font-size: 0.6rem;
                }

                .status-dot {
                    width: 6px;
                    height: 6px;
                    margin-right: 2px;
                }
            }

            @media (max-width: 360px) {
                .calendar-grid {
                    grid-template-columns: 90px repeat(7, 1fr);
                    min-width: 450px;
                }

                .calendar-cell {
                    padding: 3px 2px;
                    min-height: 40px;
                    font-size: 9px;
                }

                .calendar-cell.room-name {
                    font-size: 8px;
                }

                .calendar-nav-btn {
                    padding: 4px 8px;
                    font-size: 11px;
                }
            }
        </style>

        <div class="calendar-container">
            <div class="calendar-header">
                <div class="calendar-title-wrapper">
                    <h3 class="calendar-title">Week View</h3>
                    <div class="month-display"><?php echo date('F Y', strtotime($date)); ?></div>
                </div>
                <div class="calendar-nav">
                    <button class="calendar-nav-btn" onclick="changeWeek(-1)">← Previous Week</button>
                    <span style="font-weight: 600; padding: 5px 10px;"
                        class="desktop-month"><?php echo date('F Y', strtotime($date)); ?></span>
                    <button class="calendar-nav-btn" onclick="changeWeek(1)">Next Week →</button>
                </div>
            </div>

            <div class="calendar-grid-wrapper">
                <div class="calendar-grid">
                    <!-- Empty corner cell -->
                    <div class="calendar-cell header"></div>

                    <!-- Day headers -->
                    <?php foreach ($weekDays as $day): ?>
                        <div class="calendar-cell header <?php echo $day['is_today'] ? 'today' : ''; ?>">
                            <div class="day-header">
                                <div class="day-name"><?php echo $day['day_name']; ?></div>
                                <div class="day-number"><?php echo $day['day_number']; ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Room rows -->
                    <?php foreach ($rooms as $room): ?>
                        <!-- Room name cell -->
                        <div class="calendar-cell room-name">
                            <div style="font-weight: 600; margin-bottom: 2px;">
                                <?php echo htmlspecialchars($room['building_name']); ?></div>
                            <div><?php echo htmlspecialchars($room['room_name']); ?></div>
                            <div class="room-info">Cap: <?php echo $room['capacity']; ?></div>
                        </div>

                        <!-- Status for each day -->
                        <?php foreach ($weekDays as $day): ?>
                            <?php
                            $roomStatus = null;
                            foreach ($roomStatuses[$day['date']] as $status) {
                                if ($status['room']['id'] == $room['id']) {
                                    $roomStatus = $status;
                                    break;
                                }
                            }

                            // Determine overall status for the day
                            $dayOverallStatus = 'available';
                            $hasClass = false;
                            $hasBooking = false;

                            if ($roomStatus) {
                                foreach ($roomStatus['time_slots'] as $slotStatus) {
                                    if ($slotStatus['status'] == 'class') {
                                        $hasClass = true;
                                    } elseif ($slotStatus['status'] == 'booked') {
                                        $hasBooking = true;
                                    }
                                }

                                if ($hasClass) {
                                    $dayOverallStatus = 'class';
                                } elseif ($hasBooking) {
                                    $dayOverallStatus = 'booked';
                                }
                            }
                            ?>

                            <div class="calendar-cell <?php echo $day['is_today'] ? 'today' : ''; ?>">
                                <div class="room-status">
                                    <span class="status-dot status-<?php echo $dayOverallStatus; ?>"></span>
                                    <?php echo getDayStatusText($dayOverallStatus); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Legend -->
            <div class="status-legend">
                <div class="legend-item">
                    <span class="status-dot status-available"></span>
                    <span>Available</span>
                </div>
                <div class="legend-item">
                    <span class="status-dot status-class"></span>
                    <span>Scheduled Class</span>
                </div>
                <div class="legend-item">
                    <span class="status-dot status-booked"></span>
                    <span>Booked</span>
                </div>
            </div>
        </div>

        <script>
            function changeWeek(offset) {
                const currentDate = new URLSearchParams(window.location.search).get('date') || '<?php echo $date; ?>';
                const newDate = new Date(currentDate);
                newDate.setDate(newDate.getDate() + (offset * 7));

                const year = newDate.getFullYear();
                const month = String(newDate.getMonth() + 1).padStart(2, '0');
                const day = String(newDate.getDate()).padStart(2, '0');

                // Redirect to room-status section with the new date
                window.location.href = '?section=room-status&date=' + year + '-' + month + '-' + day;
            }

            // Hide desktop month display on mobile
            document.addEventListener('DOMContentLoaded', function () {
                if (window.innerWidth <= 768) {
                    document.querySelector('.desktop-month').style.display = 'none';
                }
            });
        </script>
        <?php
    }
    // Day view
    elseif ($view === 'day') {
        // Get today's date
        $today = date('Y-m-d');
        $displayDate = $date;

        // Get rooms
        $rooms = $pdo->query("
            SELECT r.*, b.name as building_name, f.floor_number
            FROM rooms r
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            ORDER BY b.name, f.floor_number, r.room_number
        ")->fetchAll();

        // Get time slots
        $timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();

        // Get room statuses for the selected day
        $roomStatuses = getRoomStatusForDate($pdo, $displayDate);
        ?>
        <style>
            .day-calendar-container {
                background: white;
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                width: 100%;
                overflow-x: hidden;
            }

            .day-calendar-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-wrap: wrap;
                gap: 10px;
            }

            .day-calendar-nav {
                display: flex;
                gap: 10px;
                align-items: center;
                flex-wrap: wrap;
            }

            .day-calendar-nav-btn {
                background: #3b82f6;
                color: white;
                border: none;
                padding: 8px 16px;
                border-radius: 6px;
                cursor: pointer;
                font-weight: 600;
                font-size: 14px;
            }

            .day-calendar-nav-btn:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            .day-calendar-title {
                font-size: 1.2rem;
                font-weight: 700;
                color: #1e40af;
            }

            .day-calendar-grid-wrapper {
                width: 100%;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .day-calendar-grid {
                display: grid;
                grid-template-columns: 120px repeat(<?php echo count($timeSlots); ?>, 1fr);
                gap: 1px;
                background: #e5e7eb;
                border: 1px solid #e5e7eb;
                border-radius: 8px;
                overflow: hidden;
                min-width: 800px;
            }

            .day-calendar-cell {
                background: white;
                padding: 8px 5px;
                min-height: 80px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                text-align: center;
                word-break: break-word;
            }

            .day-calendar-cell.header {
                background: #f3f4f6;
                font-weight: 600;
                color: #374151;
                padding: 12px 5px;
            }

            .day-calendar-cell.room-name {
                align-items: flex-start;
                background: #f9fafb;
                font-weight: 500;
                color: #1f2937;
                justify-content: center;
                padding: 8px;
                font-size: 12px;
            }

            .time-slot-header {
                font-size: 0.9rem;
                font-weight: 600;
                margin-bottom: 5px;
            }

            .time-slot-time {
                font-size: 0.75rem;
                color: #6b7280;
                line-height: 1.2;
            }

            .slot-status {
                width: 100%;
                height: 100%;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                border-radius: 4px;
                font-size: 0.75rem;
                padding: 4px;
            }

            .slot-available {
                background: #d1fae5;
                color: #065f46;
            }

            .slot-class {
                background: #dbeafe;
                color: #1e40af;
            }

            .slot-booked {
                background: #fef3c7;
                color: #92400e;
            }

            .slot-details {
                font-size: 0.65rem;
                margin-top: 3px;
                text-align: center;
                word-break: break-word;
                line-height: 1.2;
            }

            .current-day {
                background: #dbeafe !important;
            }

            .room-info {
                font-size: 0.7rem;
                color: #6b7280;
                margin-top: 2px;
            }

            @media (max-width: 768px) {
                .day-calendar-container {
                    padding: 15px;
                }

                .day-calendar-header {
                    flex-direction: column;
                    align-items: flex-start;
                }

                .day-calendar-nav {
                    width: 100%;
                    justify-content: space-between;
                }

                .day-calendar-title {
                    font-size: 1.1rem;
                }

                .day-calendar-nav-btn {
                    padding: 6px 12px;
                    font-size: 13px;
                }

                .day-calendar-grid {
                    grid-template-columns: 100px repeat(<?php echo count($timeSlots); ?>, 1fr);
                    min-width: 700px;
                }

                .day-calendar-cell {
                    padding: 6px 4px;
                    min-height: 70px;
                    font-size: 11px;
                }

                .day-calendar-cell.room-name {
                    font-size: 10px;
                    padding: 6px 4px;
                }

                .time-slot-header {
                    font-size: 0.8rem;
                    margin-bottom: 3px;
                }

                .time-slot-time {
                    font-size: 0.65rem;
                }

                .slot-status {
                    font-size: 0.65rem;
                    padding: 3px;
                }

                .slot-details {
                    font-size: 0.6rem;
                    margin-top: 2px;
                }

                .room-info {
                    font-size: 0.6rem;
                }
            }

            @media (max-width: 480px) {
                .day-calendar-container {
                    padding: 10px;
                }

                .day-calendar-grid {
                    grid-template-columns: 90px repeat(<?php echo count($timeSlots); ?>, 1fr);
                    min-width: 600px;
                }

                .day-calendar-cell {
                    padding: 4px 3px;
                    min-height: 60px;
                    font-size: 10px;
                }

                .day-calendar-cell.room-name {
                    font-size: 9px;
                    padding: 4px 3px;
                }

                .day-calendar-nav-btn {
                    padding: 5px 10px;
                    font-size: 12px;
                }

                .time-slot-header {
                    font-size: 0.75rem;
                }

                .time-slot-time {
                    font-size: 0.6rem;
                }

                .slot-status {
                    font-size: 0.6rem;
                    padding: 2px;
                }

                .slot-details {
                    font-size: 0.55rem;
                }
            }

            @media (max-width: 360px) {
                .day-calendar-grid {
                    grid-template-columns: 80px repeat(<?php echo count($timeSlots); ?>, 1fr);
                    min-width: 550px;
                }

                .day-calendar-cell {
                    padding: 3px 2px;
                    min-height: 55px;
                    font-size: 9px;
                }

                .day-calendar-cell.room-name {
                    font-size: 8px;
                }

                .day-calendar-nav-btn {
                    padding: 4px 8px;
                    font-size: 11px;
                }

                .time-slot-time {
                    font-size: 0.55rem;
                }

                .slot-details {
                    font-size: 0.5rem;
                }
            }
        </style>

        <div class="day-calendar-container">
            <div class="day-calendar-header">
                <h3 class="day-calendar-title">Room Status - <?php echo date('l, F j, Y', strtotime($displayDate)); ?></h3>
                <div class="day-calendar-nav">
                    <button class="day-calendar-nav-btn" onclick="changeDay(-1)" <?php echo $displayDate <= $today ? 'disabled' : ''; ?>>← Previous Day</button>
                    <button class="day-calendar-nav-btn" onclick="changeDay(1)">Next Day →</button>
                </div>
            </div>

            <div class="day-calendar-grid-wrapper">
                <div class="day-calendar-grid">
                    <!-- Empty corner cell -->
                    <div class="day-calendar-cell header"></div>

                    <!-- Time slot headers -->
                    <?php foreach ($timeSlots as $slot): ?>
                        <div class="day-calendar-cell header">
                            <div class="time-slot-header">Slot <?php echo $slot['slot_order']; ?></div>
                            <div class="time-slot-time">
                                <?php echo date('g:i A', strtotime($slot['start_time'])); ?><br>
                                <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Room rows -->
                    <?php foreach ($rooms as $room): ?>
                        <!-- Room name cell -->
                        <div class="day-calendar-cell room-name">
                            <div style="font-weight: 600; margin-bottom: 2px;">
                                <?php echo htmlspecialchars($room['building_name']); ?></div>
                            <div><?php echo htmlspecialchars($room['room_name']); ?></div>
                            <div class="room-info">Cap: <?php echo $room['capacity']; ?></div>
                        </div>

                        <!-- Status for each time slot -->
                        <?php
                        $roomStatus = null;
                        foreach ($roomStatuses as $status) {
                            if ($status['room']['id'] == $room['id']) {
                                $roomStatus = $status;
                                break;
                            }
                        }
                        ?>

                        <?php foreach ($timeSlots as $slot): ?>
                            <?php
                            $slotStatus = 'available';
                            $slotDetails = '';

                            if ($roomStatus && isset($roomStatus['time_slots'][$slot['id']])) {
                                $slotData = $roomStatus['time_slots'][$slot['id']];
                                $slotStatus = $slotData['status'];

                                if ($slotData['data']) {
                                    if ($slotStatus === 'class') {
                                        $slotDetails = $slotData['data']['course_code'] . '<br>' . ($slotData['data']['teacher'] ?? '');
                                    } elseif ($slotStatus === 'booked') {
                                        $slotDetails = $slotData['data']['purpose'] . '<br>by: ' . ($slotData['data']['booked_by'] ?? '');
                                    }
                                }
                            }
                            ?>

                            <div class="day-calendar-cell <?php echo $displayDate == date('Y-m-d') ? 'current-day' : ''; ?>">
                                <div class="slot-status slot-<?php echo $slotStatus; ?>">
                                    <?php echo ucfirst($slotStatus); ?>
                                    <?php if ($slotDetails): ?>
                                        <div class="slot-details"><?php echo $slotDetails; ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <script>
            function changeDay(offset) {
                const currentDate = new URLSearchParams(window.location.search).get('date') || '<?php echo $displayDate; ?>';
                const newDate = new Date(currentDate);
                newDate.setDate(newDate.getDate() + offset);

                const year = newDate.getFullYear();
                const month = String(newDate.getMonth() + 1).padStart(2, '0');
                const day = String(newDate.getDate()).padStart(2, '0');

                // Redirect to room-status section with the new date
                window.location.href = '?section=room-status&view=day&date=' + year + '-' + month + '-' + day;
            }
        </script>
        <?php
    }
}
?>