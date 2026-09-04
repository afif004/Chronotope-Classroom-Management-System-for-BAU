<?php
require_once 'db_config.php';

// Get filter parameters
$degree_id = $_GET['degree'] ?? null;
$level = $_GET['level'] ?? null;
$semester = $_GET['semester'] ?? null;
$group = $_GET['group'] ?? null;
$view = $_GET['view'] ?? 'week';
$date = $_GET['date'] ?? date('Y-m-d');

// Get degree programs for filter
$degree_programs = $pdo->query("
    SELECT dp.*, f.name as faculty_name 
    FROM degree_programs dp 
    JOIN faculties f ON dp.faculty_id = f.id 
    ORDER BY f.name, dp.name
")->fetchAll();

$degree_info = null;
if ($degree_id && $level && $semester) {
    $stmt = $pdo->prepare("SELECT dp.*, f.name as faculty_name FROM degree_programs dp JOIN faculties f ON dp.faculty_id = f.id WHERE dp.id = ?");
    $stmt->execute([$degree_id]);
    $degree_info = $stmt->fetch();
}

// Always show calendar — filters are optional
$showCalendar = true;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Schedule - Classroom Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        body {
            background: var(--light);
        }

        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 1.5rem;
            color: var(--dark);
            margin: 0;
        }

        .degree-badge {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .status-badges {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .calendar-filters {
            display: flex;
            gap: 15px;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .view-switcher {
            display: flex;
            background: var(--light);
            border-radius: 8px;
            padding: 4px;
        }

        .view-switcher a {
            padding: 8px 16px;
            text-decoration: none;
            color: var(--dark);
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .view-switcher a.active {
            background: var(--primary);
            color: white;
        }

        .calendar-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
            background: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s;
        }

        .calendar-nav-btn:hover {
            background: var(--primary-dark);
        }

        .calendar-title {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--primary-dark);
            margin: 0;
        }

        .calendar-grid-wrapper {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .calendar-grid {
            display: grid;
            gap: 1px;
            background: var(--border-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }

        .week-grid {
            grid-template-columns: 150px repeat(7, 1fr);
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
            background: var(--light);
            font-weight: 600;
            color: var(--dark);
            padding: 10px 5px;
        }

        .calendar-cell.today {
            background: #dbeafe;
        }

        .calendar-cell.room-name {
            align-items: flex-start;
            background: var(--light);
            font-weight: 500;
            color: var(--dark);
            padding: 8px;
            font-size: 12px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 4px;
        }

        .status-available { background: #10b981; }
        .status-class     { background: #3b82f6; }
        .status-booked    { background: #f59e0b; }

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

        .filter-note {
            font-size: 0.85rem;
            color: #6b7280;
            margin-top: 8px;
            font-style: italic;
        }

        @media (max-width: 768px) {
            .page-container { padding: 15px; }
            .calendar-container { padding: 15px; }
            .week-grid { grid-template-columns: 120px repeat(7, 1fr); min-width: 600px; }
            .calendar-cell { padding: 6px 4px; min-height: 50px; font-size: 11px; }
            .calendar-cell.room-name { font-size: 10px; padding: 6px 4px; }
            .calendar-nav-btn { padding: 6px 12px; font-size: 13px; }
        }

        @media (max-width: 480px) {
            .page-container { padding: 10px; }
            .calendar-container { padding: 10px; }
            .week-grid { grid-template-columns: 100px repeat(7, 1fr); min-width: 500px; }
            .calendar-cell { padding: 4px 3px; min-height: 45px; font-size: 10px; }
            .calendar-cell.room-name { font-size: 9px; padding: 4px 3px; }
        }
    </style>
</head>

<body>
    <div class="page-container">
        <div class="page-header">
            <h1>📚 Room Status Calendar</h1>
            <a href="index.php" class="btn btn-outline">← Back to Home</a>
        </div>

        <!-- Filters -->
        <div class="card">
            <form method="GET" class="form-row" style="align-items: end;">
                <div class="form-group mb-0">
                    <label class="form-label">Degree Program</label>
                    <select name="degree" class="form-control">
                        <option value="">All Programs</option>
                        <?php foreach ($degree_programs as $dp): ?>
                            <option value="<?php echo $dp['id']; ?>" <?php echo $degree_id == $dp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dp['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Level</label>
                    <select name="level" class="form-control">
                        <option value="">All Levels</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php echo $level == $i ? 'selected' : ''; ?>>
                                Level <?php echo $i; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Semester</label>
                    <select name="semester" class="form-control">
                        <option value="">All Semesters</option>
                        <option value="1" <?php echo $semester == 1 ? 'selected' : ''; ?>>Semester 1</option>
                        <option value="2" <?php echo $semester == 2 ? 'selected' : ''; ?>>Semester 2</option>
                    </select>
                </div>

                <div class="form-group mb-0">
                    <label class="form-label">Group</label>
                    <input type="text" name="group" class="form-control" placeholder="Optional"
                        value="<?php echo htmlspecialchars($group ?? ''); ?>">
                </div>

                <button type="submit" class="btn btn-primary">Search</button>
            </form>
            <p class="filter-note">Showing all rooms by default. Use filters above to narrow by program/level/semester.</p>
        </div>

        <?php if ($degree_info): ?>
            <div class="status-badges">
                <span class="degree-badge"><?php echo htmlspecialchars($degree_info['name']); ?></span>
                <span class="badge badge-info">Level <?php echo $level; ?>, Semester <?php echo $semester; ?></span>
                <?php if ($group): ?>
                    <span class="badge badge-secondary">Group <?php echo htmlspecialchars($group); ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- View Switcher — always visible -->
        <div class="calendar-filters">
            <div class="view-switcher">
                <a href="?degree=<?php echo urlencode($degree_id ?? ''); ?>&level=<?php echo urlencode($level ?? ''); ?>&semester=<?php echo urlencode($semester ?? ''); ?>&group=<?php echo urlencode($group ?? ''); ?>&view=week&date=<?php echo $date; ?>"
                   class="<?php echo $view == 'week' ? 'active' : ''; ?>">Week View</a>
                <a href="?degree=<?php echo urlencode($degree_id ?? ''); ?>&level=<?php echo urlencode($level ?? ''); ?>&semester=<?php echo urlencode($semester ?? ''); ?>&group=<?php echo urlencode($group ?? ''); ?>&view=day&date=<?php echo $date; ?>"
                   class="<?php echo $view == 'day' ? 'active' : ''; ?>">Day View</a>
            </div>
        </div>

        <!-- Room Status Calendar — always rendered -->
        <?php
        if ($view == 'week') {
            displayRoomStatusCalendar($pdo, $date, 'week', $degree_id, $level, $semester, $group);
        } else {
            displayRoomStatusCalendar($pdo, $date, 'day', $degree_id, $level, $semester, $group);
        }
        ?>

    </div>
</body>

</html>

<?php
// ============================================================
// Room Status Calendar Functions
// ============================================================

function displayRoomStatusCalendar($pdo, $date = null, $view = 'week', $degree_id = null, $level = null, $semester = null, $group = null) {
    if (!$date) {
        $date = date('Y-m-d');
    }

    // Build optional room filter based on degree/level/semester
    // If filters are provided we still show ALL rooms but highlight relevant classes
    // (rooms are physical — filtering rooms by program would hide availability info)

    if ($view === 'week') {
        displayWeekView($pdo, $date, $degree_id, $level, $semester, $group);
    } elseif ($view === 'day') {
        displayDayView($pdo, $date, $degree_id, $level, $semester, $group);
    }
}

// ------------------------------------------------------------------
// Week View
// ------------------------------------------------------------------
function displayWeekView($pdo, $date, $degree_id, $level, $semester, $group) {
    $dayOfWeek = date('w', strtotime($date));

    $weekDays = [];
    for ($i = 0; $i < 7; $i++) {
        $currentDate = date('Y-m-d', strtotime($date . ' +' . ($i - $dayOfWeek) . ' days'));
        $weekDays[] = [
            'date'       => $currentDate,
            'day_name'   => date('D', strtotime($currentDate)),
            'day_number' => date('j', strtotime($currentDate)),
            'is_today'   => $currentDate == date('Y-m-d'),
        ];
    }

    $rooms = getRooms($pdo);

    $roomStatuses = [];
    foreach ($weekDays as $day) {
        $roomStatuses[$day['date']] = getRoomStatusForDate($pdo, $day['date'], $degree_id, $level, $semester, $group);
    }
    ?>
    <div class="calendar-container">
        <div class="calendar-header">
            <h3 class="calendar-title">Week View</h3>
            <div class="calendar-nav">
                <button class="calendar-nav-btn" onclick="changeWeek(-1)">← Previous Week</button>
                <span style="font-weight: 600; padding: 5px 10px;"><?php echo date('F Y', strtotime($date)); ?></span>
                <button class="calendar-nav-btn" onclick="changeWeek(1)">Next Week →</button>
            </div>
        </div>

        <div class="calendar-grid-wrapper">
            <div class="calendar-grid week-grid">
                <div class="calendar-cell header"></div>
                <?php foreach ($weekDays as $day): ?>
                    <div class="calendar-cell header <?php echo $day['is_today'] ? 'today' : ''; ?>">
                        <div style="font-weight: 700; color: var(--primary-dark); margin-bottom: 2px;"><?php echo $day['day_number']; ?></div>
                        <div style="font-size: 0.85rem; color: #6b7280;"><?php echo $day['day_name']; ?></div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($rooms as $room): ?>
                    <div class="calendar-cell room-name">
                        <div style="font-weight: 600; margin-bottom: 2px; color: var(--dark);"><?php echo htmlspecialchars($room['room_name']); ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280;"><?php echo htmlspecialchars($room['building_name']); ?></div>
                        <div class="room-info">Capacity: <?php echo $room['capacity']; ?></div>
                    </div>

                    <?php foreach ($weekDays as $day): ?>
                        <?php
                        $roomStatus = null;
                        foreach ($roomStatuses[$day['date']] as $status) {
                            if ($status['room']['id'] == $room['id']) {
                                $roomStatus = $status;
                                break;
                            }
                        }

                        $dayOverallStatus = 'available';
                        if ($roomStatus) {
                            foreach ($roomStatus['time_slots'] as $slotStatus) {
                                if ($slotStatus['status'] == 'class')  { $dayOverallStatus = 'class';  break; }
                                if ($slotStatus['status'] == 'booked') { $dayOverallStatus = 'booked'; }
                            }
                        }
                        ?>
                        <div class="calendar-cell <?php echo $day['is_today'] ? 'today' : ''; ?>">
                            <div class="room-status">
                                <span class="status-dot status-<?php echo $dayOverallStatus; ?>"></span>
                                <?php echo ucfirst($dayOverallStatus); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="status-legend">
            <div class="legend-item"><span class="status-dot status-available"></span><span>Available</span></div>
            <div class="legend-item"><span class="status-dot status-class"></span><span>Scheduled Class</span></div>
            <div class="legend-item"><span class="status-dot status-booked"></span><span>Booked</span></div>
        </div>
    </div>

    <script>
        function changeWeek(offset) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentDate = urlParams.get('date') || '<?php echo $date; ?>';
            const degree   = urlParams.get('degree')   || '';
            const level    = urlParams.get('level')    || '';
            const semester = urlParams.get('semester') || '';
            const group    = urlParams.get('group')    || '';
            const view     = 'week';

            const newDate = new Date(currentDate);
            newDate.setDate(newDate.getDate() + (offset * 7));

            const year  = newDate.getFullYear();
            const month = String(newDate.getMonth() + 1).padStart(2, '0');
            const day   = String(newDate.getDate()).padStart(2, '0');

            window.location.href = `?degree=${degree}&level=${level}&semester=${semester}&group=${group}&view=${view}&date=${year}-${month}-${day}`;
        }
    </script>
    <?php
}

// ------------------------------------------------------------------
// Day View
// ------------------------------------------------------------------
function displayDayView($pdo, $date, $degree_id, $level, $semester, $group) {
    $displayDate = $date;
    $today       = date('Y-m-d');

    $rooms      = getRooms($pdo);
    $timeSlots  = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();
    $roomStatuses = getRoomStatusForDate($pdo, $displayDate, $degree_id, $level, $semester, $group);
    ?>
    <div class="calendar-container">
        <div class="calendar-header">
            <h3 class="calendar-title"><?php echo date('l, F j, Y', strtotime($displayDate)); ?></h3>
            <div class="calendar-nav">
                <button class="calendar-nav-btn" onclick="changeDay(-1)">← Previous Day</button>
                <button class="calendar-nav-btn" onclick="changeDay(1)">Next Day →</button>
            </div>
        </div>

        <div class="calendar-grid-wrapper">
            <div class="calendar-grid" style="grid-template-columns: 120px repeat(<?php echo count($timeSlots); ?>, 1fr); min-width: 800px;">
                <div class="calendar-cell header"></div>
                <?php foreach ($timeSlots as $slot): ?>
                    <div class="calendar-cell header">
                        <div style="font-weight: 600; margin-bottom: 5px; color: var(--primary-dark);">Slot <?php echo $slot['slot_order']; ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280; line-height: 1.2;">
                            <?php echo date('g:i A', strtotime($slot['start_time'])); ?><br>
                            to <?php echo date('g:i A', strtotime($slot['end_time'])); ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($rooms as $room): ?>
                    <div class="calendar-cell room-name">
                        <div style="font-weight: 600; margin-bottom: 2px; color: var(--dark);"><?php echo htmlspecialchars($room['room_name']); ?></div>
                        <div style="font-size: 0.8rem; color: #6b7280;"><?php echo htmlspecialchars($room['building_name']); ?></div>
                        <div class="room-info">Capacity: <?php echo $room['capacity']; ?></div>
                    </div>

                    <?php
                    $roomStatus = null;
                    foreach ($roomStatuses as $status) {
                        if ($status['room']['id'] == $room['id']) { $roomStatus = $status; break; }
                    }
                    ?>

                    <?php foreach ($timeSlots as $slot): ?>
                        <?php
                        $slotStatus  = 'available';
                        $slotDetails = '';

                        if ($roomStatus && isset($roomStatus['time_slots'][$slot['id']])) {
                            $slotData   = $roomStatus['time_slots'][$slot['id']];
                            $slotStatus = $slotData['status'];

                            if ($slotData['data']) {
                                if ($slotStatus === 'class') {
                                    $slotDetails = ($slotData['data']['course_code'] ?? 'Class') . '<br>' . ($slotData['data']['teacher'] ?? '');
                                } elseif ($slotStatus === 'booked') {
                                    $slotDetails = ($slotData['data']['purpose'] ?? 'Booked') . '<br>by: ' . ($slotData['data']['booked_by'] ?? '');
                                }
                            }
                        }
                        $bgColor   = $slotStatus == 'available' ? '#d1fae5' : ($slotStatus == 'class' ? '#dbeafe' : '#fef3c7');
                        $textColor = $slotStatus == 'available' ? '#065f46' : ($slotStatus == 'class' ? '#1e40af' : '#92400e');
                        ?>
                        <div class="calendar-cell">
                            <div style="background: <?php echo $bgColor; ?>; color: <?php echo $textColor; ?>; width: 100%; height: 100%; border-radius: 4px; padding: 4px; display: flex; flex-direction: column; justify-content: center; align-items: center;">
                                <span style="font-weight: 600; font-size: 0.75rem;"><?php echo ucfirst($slotStatus); ?></span>
                                <?php if ($slotDetails): ?>
                                    <div style="font-size: 0.65rem; margin-top: 3px; text-align: center; line-height: 1.2;"><?php echo $slotDetails; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="status-legend">
            <div class="legend-item"><span class="status-dot status-available"></span><span>Available</span></div>
            <div class="legend-item"><span class="status-dot status-class"></span><span>Scheduled Class</span></div>
            <div class="legend-item"><span class="status-dot status-booked"></span><span>Booked</span></div>
        </div>
    </div>

    <script>
        function changeDay(offset) {
            const urlParams = new URLSearchParams(window.location.search);
            const currentDate = urlParams.get('date') || '<?php echo $displayDate; ?>';
            const degree   = urlParams.get('degree')   || '';
            const level    = urlParams.get('level')    || '';
            const semester = urlParams.get('semester') || '';
            const group    = urlParams.get('group')    || '';
            const view     = 'day';

            const newDate = new Date(currentDate);
            newDate.setDate(newDate.getDate() + offset);

            const year  = newDate.getFullYear();
            const month = String(newDate.getMonth() + 1).padStart(2, '0');
            const day   = String(newDate.getDate()).padStart(2, '0');

            window.location.href = `?degree=${degree}&level=${level}&semester=${semester}&group=${group}&view=${view}&date=${year}-${month}-${day}`;
        }
    </script>
    <?php
}

// ------------------------------------------------------------------
// Helper: fetch all rooms (no status column — removed broken filter)
// ------------------------------------------------------------------
function getRooms($pdo) {
    return $pdo->query("
        SELECT r.*, b.name as building_name, f.floor_number
        FROM rooms r
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        ORDER BY b.name, f.floor_number, r.room_number
    ")->fetchAll();
}

// ------------------------------------------------------------------
// Helper: get room statuses for a given date
//   Optionally filtered by degree/level/semester/group to highlight
//   relevant classes, but ALL rooms are always returned.
// ------------------------------------------------------------------
function getRoomStatusForDate($pdo, $date, $degree_id = null, $level = null, $semester = null, $group = null) {
    $rooms     = getRooms($pdo);
    $timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();
    $statuses  = [];

    foreach ($rooms as $room) {
        // Active bookings for this room/date (status = 'booked' per live schema)
        $stmt = $pdo->prepare("
            SELECT rb.*,
                   u.full_name as booked_by_name,
                   cs.course_code,
                   cs.course_name
            FROM room_bookings rb
            LEFT JOIN users u  ON rb.booked_by = u.id
            LEFT JOIN course_schedule cs ON rb.course_schedule_id = cs.id
            WHERE rb.room_id = ?
              AND rb.booking_date = ?
              AND rb.status = 'booked'
            ORDER BY rb.start_time
        ");
        $stmt->execute([$room['id'], $date]);
        $bookings = $stmt->fetchAll();

        // Scheduled classes for this room/day
        // DAYOFWEEK returns 1=Sun … 7=Sat; course_schedule uses 1=Mon … 7=Sun
        // Convert: PHP date('N') = 1(Mon)…7(Sun) matches course_schedule convention
        $dayNum = date('N', strtotime($date)); // 1=Mon … 7=Sun

        // Build optional degree/level/semester filter
        $classWhere = "cs.room_id = ? AND cs.day_of_week = ? AND cs.status = 'scheduled'";
        $classParams = [$room['id'], $dayNum];

        if ($degree_id) { $classWhere .= " AND cs.degree_program_id = ?"; $classParams[] = $degree_id; }
        if ($level)     { $classWhere .= " AND cs.level = ?";             $classParams[] = $level; }
        if ($semester)  { $classWhere .= " AND cs.semester = ?";          $classParams[] = $semester; }
        if ($group)     { $classWhere .= " AND (cs.group_name = ? OR cs.group_name IS NULL)"; $classParams[] = $group; }

        // Date range check: is_whole_semester OR specific date range covers today
        $classWhere .= " AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))";
        $classParams[] = $date;
        $classParams[] = $date;

        $stmt = $pdo->prepare("
            SELECT cs.*, u.full_name as teacher_name, dp.name as degree_name
            FROM course_schedule cs
            LEFT JOIN users u  ON cs.teacher_id = u.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            WHERE $classWhere
            ORDER BY cs.start_time
        ");
        $stmt->execute($classParams);
        $classes = $stmt->fetchAll();

        $roomStatus = [
            'room'       => $room,
            'bookings'   => $bookings,
            'classes'    => $classes,
            'time_slots' => [],
        ];

        foreach ($timeSlots as $slot) {
            $slotStatus = 'available';
            $slotData   = null;

            foreach ($classes as $class) {
                if (timeOverlaps($slot['start_time'], $slot['end_time'], $class['start_time'], $class['end_time'])) {
                    $slotStatus = 'class';
                    $slotData   = [
                        'type'        => 'class',
                        'course_code' => $class['course_code'],
                        'teacher'     => $class['teacher_name'],
                    ];
                    break;
                }
            }

            if ($slotStatus === 'available') {
                foreach ($bookings as $booking) {
                    if (timeOverlaps($slot['start_time'], $slot['end_time'], $booking['start_time'], $booking['end_time'])) {
                        $slotStatus = 'booked';
                        $slotData   = [
                            'type'      => 'booking',
                            'purpose'   => $booking['purpose'],
                            'booked_by' => $booking['booked_by_name'],
                        ];
                        break;
                    }
                }
            }

            $roomStatus['time_slots'][$slot['id']] = [
                'slot'   => $slot,
                'status' => $slotStatus,
                'data'   => $slotData,
            ];
        }

        $statuses[] = $roomStatus;
    }

    return $statuses;
}

function timeOverlaps($slotStart, $slotEnd, $eventStart, $eventEnd) {
    $slotStart  = strtotime($slotStart);
    $slotEnd    = strtotime($slotEnd);
    $eventStart = strtotime($eventStart);
    $eventEnd   = strtotime($eventEnd);
    return ($eventStart < $slotEnd && $eventEnd > $slotStart);
}
?>