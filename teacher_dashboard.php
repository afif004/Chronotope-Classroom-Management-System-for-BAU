<?php
require_once 'db_config.php';
require_once 'room_status.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'teacher') {
    header('Location: login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'];
$teacher_name_short = implode(' ', array_slice(explode(' ', $teacher_name), 0, 2));

if (!function_exists('formatTime')) {
    function formatTime($time)
    {
        return date('g:i A', strtotime($time));
    }
}
if (!function_exists('getDayName')) {
    function getDayName($day)
    {
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        return $days[$day - 1] ?? 'Unknown';
    }
}

// ────────────────────────────────────────────────────────────────────────────
// STATUS & BOOKING SECTION – DATA FETCHING
// ────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/functions.php';

$sb_view = $_GET['view'] ?? 'week';
$sb_date = $_GET['date'] ?? date('Y-m-d');
$sb_section = $_GET['section'] ?? 'dashboard';

$timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();
$allFaculties = $pdo->query("SELECT id, name, code FROM faculties WHERE status='active' ORDER BY name")->fetchAll();

$sb_faculty_filter = isset($_GET['sb_faculty']) && is_numeric($_GET['sb_faculty']) ? (int) $_GET['sb_faculty'] : null;
$sb_building_filter = isset($_GET['sb_building']) && is_numeric($_GET['sb_building']) ? (int) $_GET['sb_building'] : null;
$sb_degree_filter = isset($_GET['sb_degree']) && is_numeric($_GET['sb_degree']) ? (int) $_GET['sb_degree'] : null;

if ($sb_faculty_filter !== null) {
    $bStmt = $pdo->prepare("SELECT id, name, code, faculty_id FROM buildings WHERE faculty_id = ? AND status='active' ORDER BY name");
    $bStmt->execute([$sb_faculty_filter]);
    $sb_buildings = $bStmt->fetchAll();
} else {
    $sb_buildings = $pdo->query("SELECT id, name, code, faculty_id FROM buildings WHERE status='active' ORDER BY name")->fetchAll();
}

$degree_programs = $pdo->query("
    SELECT dp.*, f.name AS faculty_name, f.id AS faculty_id
    FROM degree_programs dp
    JOIN faculties f ON dp.faculty_id = f.id
    WHERE dp.status='active'
    ORDER BY f.name, dp.name
")->fetchAll();

$sbRoomsSql = "
    SELECT r.*, b.name AS building_name, b.code AS building_code,
           fl.floor_number, fac.id AS faculty_id, fac.name AS faculty_name
    FROM rooms r
    JOIN floors fl ON r.floor_id = fl.id
    JOIN buildings b ON fl.building_id = b.id
    LEFT JOIN faculties fac ON r.faculty_id = fac.id
    WHERE r.status = 'active'
";
$sbRoomParams = [];
if ($sb_building_filter !== null) {
    $sbRoomsSql .= " AND b.id = ?";
    $sbRoomParams[] = $sb_building_filter;
} elseif ($sb_faculty_filter !== null) {
    $sbRoomsSql .= " AND r.faculty_id = ?";
    $sbRoomParams[] = $sb_faculty_filter;
} elseif ($sb_degree_filter !== null) {
    $degStmt = $pdo->prepare("SELECT faculty_id FROM degree_programs WHERE id = ?");
    $degStmt->execute([$sb_degree_filter]);
    $degFac = $degStmt->fetchColumn();
    if ($degFac) {
        $sbRoomsSql .= " AND r.faculty_id = ?";
        $sbRoomParams[] = $degFac;
    }
}
$sbRoomsSql .= " ORDER BY b.name, fl.floor_number, r.room_number";
$sbRoomsStmt = $pdo->prepare($sbRoomsSql);
$sbRoomsStmt->execute($sbRoomParams);
$sb_rooms = $sbRoomsStmt->fetchAll();

$sb_dayOfWeek = (int) date('w', strtotime($sb_date));
$sb_weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = date('Y-m-d', strtotime($sb_date . ' +' . ($i - $sb_dayOfWeek) . ' days'));
    $sb_weekDays[] = [
        'date' => $d,
        'day_name' => date('D', strtotime($d)),
        'day_number' => date('j', strtotime($d)),
        'is_today' => ($d === date('Y-m-d')),
    ];
}

if (!function_exists('tsOverlaps')) {
    function tsOverlaps($start1, $end1, $start2, $end2)
    {
        return $start1 < $end2 && $start2 < $end1;
    }
}
if (!function_exists('roomStatusForDate')) {
    function roomStatusForDate($pdo, $date, $rooms, $timeSlots)
    {
        $dayOfWeek = date('N', strtotime($date));
        $statuses = [];
        foreach ($rooms as $room) {
            $statuses[$room['id']] = ['slots' => [], 'bookings' => [], 'classes' => []];
        }

        $stmt = $pdo->prepare("
            SELECT rb.*, u.full_name AS booked_by_name, u.phone AS booked_by_phone
            FROM room_bookings rb
            JOIN users u ON rb.booked_by = u.id
            WHERE rb.booking_date = ? AND rb.status = 'booked'
        ");
        $stmt->execute([$date]);
        while ($booking = $stmt->fetch()) {
            if (isset($statuses[$booking['room_id']])) {
                $statuses[$booking['room_id']]['bookings'][] = $booking;
            }
        }

        // LEFT JOIN teachers so schedules with NULL teacher_id are not dropped
        $stmt = $pdo->prepare("
            SELECT cs.*, t.full_name AS teacher_name, t.phone AS teacher_phone,
                   r.id AS room_id, dp.name AS degree_name, t.id AS teacher_id
            FROM course_schedule cs
            LEFT JOIN teachers t ON cs.teacher_id = t.id
            JOIN rooms r ON cs.room_id = r.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            WHERE cs.day_of_week = ?
              AND (cs.is_whole_semester = 1
                   OR (cs.start_date <= ? AND cs.end_date >= ?))
              AND cs.status = 'scheduled'
        ");
        $stmt->execute([$dayOfWeek, $date, $date]);
        while ($class = $stmt->fetch()) {
            if (isset($statuses[$class['room_id']])) {
                $statuses[$class['room_id']]['classes'][] = $class;
            }
        }

        foreach ($rooms as $room) {
            $rid = $room['id'];
            $bookings = $statuses[$rid]['bookings'];
            $classes = $statuses[$rid]['classes'];

            foreach ($timeSlots as $ts) {
                $slotStart = $ts['start_time'];
                $slotEnd = $ts['end_time'];
                $status = 'available';
                $data = null;

                foreach ($bookings as $b) {
                    if (tsOverlaps($slotStart, $slotEnd, $b['start_time'], $b['end_time'])) {
                        $status = 'booked';
                        $data = [
                            'type' => 'booking',
                            'id' => $b['id'],
                            'purpose' => $b['purpose'],
                            'booked_by' => $b['booked_by_name'],
                            'booked_by_phone' => $b['booked_by_phone'],
                            'booked_by_id' => $b['booked_by'],
                            'start_time' => $b['start_time'],
                            'end_time' => $b['end_time'],
                            'degree_program_id' => $b['degree_program_id'],
                            'level' => $b['level'],
                            'semester' => $b['semester'],
                            'group_name' => $b['group_name']
                        ];
                        break;
                    }
                }

                if ($status === 'available') {
                    foreach ($classes as $c) {
                        if (tsOverlaps($slotStart, $slotEnd, $c['start_time'], $c['end_time'])) {
                            $status = 'class';
                            $data = [
                                'type' => 'class',
                                'id' => $c['id'],
                                'course_code' => $c['course_code'],
                                'course_name' => $c['course_name'],
                                'teacher_name' => $c['teacher_name'],
                                'teacher_phone' => $c['teacher_phone'],
                                'teacher_id' => $c['teacher_id'],
                                'start_time' => $c['start_time'],
                                'end_time' => $c['end_time'],
                                'degree_name' => $c['degree_name'] ?? '',
                                'degree_program_id' => $c['degree_program_id'],
                                'level' => $c['level'],
                                'semester' => $c['semester'],
                                'group_name' => $c['group_name'],
                                'cr_name' => 'N/A',
                                'cr_phone' => 'N/A',
                                'purpose' => $c['purpose'] ?? 'Theory Class'
                            ];
                            break;
                        }
                    }
                }
                $statuses[$rid]['slots'][$ts['id']] = ['status' => $status, 'data' => $data];
            }
        }
        return $statuses;
    }
}

$sb_weekStatuses = [];
foreach ($sb_weekDays as $wd) {
    $sb_weekStatuses[$wd['date']] = roomStatusForDate($pdo, $wd['date'], $sb_rooms, $timeSlots);
}

$sb_dayFreeBlocks = [];
if ($sb_view === 'day') {
    $dayStatuses = $sb_weekStatuses[$sb_date] ?? [];
    foreach ($sb_rooms as $room) {
        $rid = $room['id'];
        $rs = $dayStatuses[$rid] ?? null;
        $occupied = [];
        if ($rs) {
            foreach ($rs['bookings'] as $b) {
                $occupied[] = [$b['start_time'], $b['end_time']];
            }
            foreach ($rs['classes'] as $c) {
                $occupied[] = [$c['start_time'], $c['end_time']];
            }
        }
        usort($occupied, fn($a, $b) => strcmp($a[0], $b[0]));
        $freeBlocks = [];
        $lastEnd = '00:00:00';
        foreach ($occupied as $int) {
            if (strtotime($int[0]) > strtotime($lastEnd)) {
                $freeBlocks[] = [$lastEnd, $int[0]];
            }
            $lastEnd = max($lastEnd, $int[1]);
        }
        if (strtotime($lastEnd) < strtotime('23:59:59')) {
            $freeBlocks[] = [$lastEnd, '23:59:59'];
        }
        $sb_dayFreeBlocks[$rid] = $freeBlocks;
    }
}

function notifyCRsAboutScheduleChange($pdo, $schedule, $action, $specific_date = null)
{
    $stmt = $pdo->prepare("
        SELECT id, full_name FROM users
        WHERE user_type = 'cr' AND account_status = 'active'
          AND degree_program_id = ? AND assigned_level = ? AND assigned_semester = ?
          AND (group_name = ? OR group_name IS NULL OR group_name = '')
    ");
    $stmt->execute([$schedule['degree_program_id'], $schedule['level'], $schedule['semester'], $schedule['group_name']]);
    $crs = $stmt->fetchAll();

    $course_info = $schedule['course_code'] . ' - ' . $schedule['course_name'];
    $date_info = $specific_date ? ' on ' . date('M d, Y', strtotime($specific_date)) : '';
    $title = "Schedule {$action}: {$course_info}";
    $msg = "The class {$course_info}{$date_info} has been {$action} by the teacher.";
    if ($action == 'cancelled') {
        $msg .= " Reason: " . ($schedule['cancellation_reason'] ?? 'Not specified');
    }

    foreach ($crs as $cr) {
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, related_type, related_id) VALUES (?, ?, ?, 'schedule_change', 'schedule', ?)")
            ->execute([$cr['id'], $title, $msg, $schedule['id']]);
    }
}

function notifyCRsAboutBookingChange($pdo, $booking, $action)
{
    $stmt = $pdo->prepare("
        SELECT id, full_name FROM users
        WHERE user_type = 'cr' AND account_status = 'active'
          AND degree_program_id = ? AND assigned_level = ? AND assigned_semester = ?
          AND (group_name = ? OR group_name IS NULL OR group_name = '')
    ");
    $stmt->execute([$booking['degree_program_id'], $booking['level'], $booking['semester'], $booking['group_name']]);
    $crs = $stmt->fetchAll();

    $title = "Booking {$action}: " . ($booking['purpose'] ?? 'Room Booking');
    $msg = "A room booking for " . date('M d, Y', strtotime($booking['booking_date'])) . " ({$booking['start_time']}–{$booking['end_time']}) has been {$action}.";
    if ($action == 'cancelled' && isset($booking['cancellation_reason'])) {
        $msg .= " Reason: " . $booking['cancellation_reason'];
    }

    foreach ($crs as $cr) {
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, related_type, related_id) VALUES (?, ?, ?, 'booking_freed', 'booking', ?)")
            ->execute([$cr['id'], $title, $msg, $booking['id']]);
    }
}

// ============================================================================
// AJAX HANDLERS (includes enhanced refresh_my_schedule)
// ============================================================================
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] == 'mark_notification_read') {
        $notification_id = $_POST['notification_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $teacher_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($_GET['ajax'] == 'refresh_sb_grid') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $view = $_GET['view'] ?? 'week';
        $sb_dayOfWeek = (int) date('w', strtotime($date));
        $sb_weekDays = [];
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($date . ' +' . ($i - $sb_dayOfWeek) . ' days'));
            $sb_weekDays[] = [
                'date' => $d,
                'day_name' => date('D', strtotime($d)),
                'day_number' => date('j', strtotime($d)),
                'is_today' => ($d === date('Y-m-d')),
            ];
        }
        $sb_weekStatuses = [];
        foreach ($sb_weekDays as $wd) {
            $sb_weekStatuses[$wd['date']] = roomStatusForDate($pdo, $wd['date'], $sb_rooms, $timeSlots);
        }
        $sb_date = $date;
        $sb_dayFreeBlocks = [];
        if ($view === 'day') {
            $dayStatuses = $sb_weekStatuses[$sb_date] ?? [];
            foreach ($sb_rooms as $room) {
                $rid = $room['id'];
                $rs = $dayStatuses[$rid] ?? null;
                $occupied = [];
                if ($rs) {
                    foreach ($rs['bookings'] as $b) {
                        $occupied[] = [$b['start_time'], $b['end_time']];
                    }
                    foreach ($rs['classes'] as $c) {
                        $occupied[] = [$c['start_time'], $c['end_time']];
                    }
                }
                usort($occupied, fn($a, $b) => strcmp($a[0], $b[0]));
                $freeBlocks = [];
                $lastEnd = '00:00:00';
                foreach ($occupied as $int) {
                    if (strtotime($int[0]) > strtotime($lastEnd)) {
                        $freeBlocks[] = [$lastEnd, $int[0]];
                    }
                    $lastEnd = max($lastEnd, $int[1]);
                }
                if (strtotime($lastEnd) < strtotime('23:59:59')) {
                    $freeBlocks[] = [$lastEnd, '23:59:59'];
                }
                $sb_dayFreeBlocks[$rid] = $freeBlocks;
            }
        }

        ob_start();
        if ($view === 'week') {
            ?>
            <div class="sb-week-actions">
                <button class="btn-add-schedule" id="sbAddScheduleBtn"><i class="fas fa-plus"></i> Book for Semester</button>
            </div>
            <div class="sb-week-wrapper">
                <div class="sb-grid-scroll">
                    <table class="sb-week-table">
                        <thead>
                            </tr>
                            <th class="sb-room-col">Room</th><?php foreach ($sb_weekDays as $wd): ?>
                                <th class="<?= $wd['is_today'] ? 'sb-today-col' : '' ?>"><?= $wd['day_name'] ?><br><span
                                        class="sb-day-num"><?= $wd['day_number'] ?></span></th><?php endforeach; ?></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sb_rooms as $room): ?>
                                <tr>
                                    <td class="sb-room-label">
                                        <strong><?= htmlspecialchars($room['room_name']) ?></strong><br><small><?= htmlspecialchars($room['building_name']) ?></small>
                                    </td>
                                    <?php foreach ($sb_weekDays as $idx => $wd):
                                        $dayStatus = $sb_weekStatuses[$wd['date']][$room['id']] ?? null;
                                        $hasClass = false;
                                        $hasBooked = false;
                                        $dayClasses = [];
                                        if ($dayStatus) {
                                            foreach ($dayStatus['slots'] as $sl) {
                                                if ($sl['status'] === 'class') {
                                                    $hasClass = true;
                                                    $dayClasses[] = $sl['data'];
                                                }
                                                if ($sl['status'] === 'booked')
                                                    $hasBooked = true;
                                            }
                                        }
                                        $cellClass = $hasClass ? 'sb-cell-class' : ($hasBooked ? 'sb-cell-booked' : 'sb-cell-avail');
                                        $isPast = $wd['date'] < date('Y-m-d');
                                        $classesJson = htmlspecialchars(json_encode($dayClasses), ENT_QUOTES);
                                        $totalSlots = count($timeSlots);
                                        $classCount = 0;
                                        $bookedCount = 0;
                                        $availCount = 0;
                                        if ($dayStatus) {
                                            foreach ($dayStatus['slots'] as $sl) {
                                                if ($sl['status'] === 'class')
                                                    $classCount++;
                                                elseif ($sl['status'] === 'booked')
                                                    $bookedCount++;
                                                else
                                                    $availCount++;
                                            }
                                        } else {
                                            $availCount = $totalSlots;
                                        }
                                        $pClass = $totalSlots > 0 ? round($classCount / $totalSlots * 100) : 0;
                                        $pBooked = $totalSlots > 0 ? round($bookedCount / $totalSlots * 100) : 0;
                                        $pAvail = 100 - $pClass - $pBooked;
                                        ?>
                                        <td class="sb-week-cell <?= $cellClass ?> <?= $isPast ? 'sb-cell-past' : '' ?> <?= $wd['is_today'] ? 'sb-today-col' : '' ?>"
                                            data-date="<?= $wd['date'] ?>" data-room-id="<?= $room['id'] ?>"
                                            data-room-name="<?= htmlspecialchars($room['room_name']) ?>"
                                            data-building="<?= htmlspecialchars($room['building_name']) ?>"
                                            data-classes="<?= $classesJson ?>">
                                            <div class="sb-occ-bar">
                                                <?php if ($pClass > 0): ?>
                                                    <div class="sb-bar-class" style="width:<?= $pClass ?>%"></div><?php endif; ?>
                                                <?php if ($pBooked > 0): ?>
                                                    <div class="sb-bar-booked" style="width:<?= $pBooked ?>%"></div><?php endif; ?>
                                                <?php if ($pAvail > 0): ?>
                                                    <div class="sb-bar-avail" style="width:<?= $pAvail ?>%"></div><?php endif; ?>
                                            </div>
                                            <div class="sb-cell-stats">
                                                <?php if ($classCount > 0): ?><span
                                                        class="sb-stat-class"><?= $classCount ?>c</span><?php endif; ?>
                                                <?php if ($bookedCount > 0): ?><span
                                                        class="sb-stat-booked"><?= $bookedCount ?>b</span><?php endif; ?>
                                                <?php if ($availCount > 0): ?><span
                                                        class="sb-stat-avail"><?= $availCount ?>f</span><?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        } else {
            ?>
            <div id="sb-day-content">
                <div class="sb-day-heading">
                    <span><?= date('l, F j, Y', strtotime($sb_date)) ?><?php if ($sb_date < date('Y-m-d')): ?> <span
                                class="sb-past-badge">Past Date — Read Only</span><?php endif; ?></span>
                    <button class="btn-add-schedule" id="sbAddScheduleBtn"><i class="fas fa-plus"></i> Book for Semester</button>
                </div>
                <div class="sb-grid-scroll">
                    <table class="sb-day-table">
                        <thead>
                            <tr>
                                <th class="sb-room-col">Room</th><?php foreach ($timeSlots as $ts): ?>
                                    <th class="sb-slot-th"><?= date('g:i', strtotime($ts['start_time'])) ?><span
                                            class="sb-slot-sub"><?= htmlspecialchars($ts['slot_name']) ?></span></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $dayStatuses = $sb_weekStatuses[$sb_date] ?? [];
                            foreach ($sb_rooms as $room):
                                $rs = $dayStatuses[$room['id']] ?? null; ?>
                                <tr data-free-blocks='<?= json_encode($sb_dayFreeBlocks[$room['id']] ?? []) ?>'>
                                    <td class="sb-room-label">
                                        <strong><?= htmlspecialchars($room['room_name']) ?></strong><br><small><?= htmlspecialchars($room['building_name']) ?></small>
                                    </td>
                                    <?php
                                    $slotOccupancy = [];
                                    foreach ($timeSlots as $idx => $ts) {
                                        $slotInfo = $rs ? ($rs['slots'][$ts['id']] ?? null) : null;
                                        $status = $slotInfo ? $slotInfo['status'] : 'available';
                                        $data = $slotInfo ? $slotInfo['data'] : null;
                                        $slotOccupancy[$idx] = ['status' => $status, 'data' => $data, 'ts' => $ts];
                                    }
                                    $mergedSlots = [];
                                    $i = 0;
                                    while ($i < count($timeSlots)) {
                                        $current = $slotOccupancy[$i];
                                        $span = 1;
                                        $j = $i + 1;
                                        if (in_array($current['status'], ['class', 'booked'])) {
                                            $currentId = $current['data']['id'] ?? null;
                                            while ($j < count($timeSlots)) {
                                                $next = $slotOccupancy[$j];
                                                if ($next['status'] === $current['status'] && isset($next['data']['id']) && $next['data']['id'] == $currentId) {
                                                    $span++;
                                                    $j++;
                                                } else {
                                                    break;
                                                }
                                            }
                                        }
                                        $mergedSlots[] = ['span' => $span, 'data' => $current];
                                        $i = $j;
                                    }

                                    foreach ($mergedSlots as $m):
                                        $slotData = $m['data'];
                                        $status = $slotData['status'];
                                        $data = $slotData['data'];
                                        $ts = $slotData['ts'];
                                        $span = $m['span'];
                                        $isPast = $sb_date < date('Y-m-d');
                                        $isPastTime = ($sb_date === date('Y-m-d') && $ts['start_time'] < date('H:i:s'));
                                        $isCurrent = $sb_date === date('Y-m-d') && date('H:i:s') >= $ts['start_time'] && date('H:i:s') <= $ts['end_time'];
                                        $freeStart = $ts['start_time'];
                                        $freeEnd = $ts['end_time'];
                                        if ($status === 'class' || $status === 'booked') {
                                            if ($rs) {
                                                $occupiedRanges = [];
                                                foreach ($rs['bookings'] as $bk)
                                                    if (tsOverlaps($ts['start_time'], $ts['end_time'], $bk['start_time'], $bk['end_time']))
                                                        $occupiedRanges[] = [$bk['start_time'], $bk['end_time']];
                                                foreach ($rs['classes'] as $cl)
                                                    if (tsOverlaps($ts['start_time'], $ts['end_time'], $cl['start_time'], $cl['end_time']))
                                                        $occupiedRanges[] = [$cl['start_time'], $cl['end_time']];
                                                if (!empty($occupiedRanges)) {
                                                    usort($occupiedRanges, fn($a, $b) => strcmp($a[0], $b[0]));
                                                    $firstOccStart = $occupiedRanges[0][0];
                                                    $lastOccEnd = end($occupiedRanges)[1];
                                                    $gapBefore = strtotime($firstOccStart) - strtotime($ts['start_time']);
                                                    $gapAfter = strtotime($ts['end_time']) - strtotime($lastOccEnd);
                                                    if ($gapBefore > 0 || $gapAfter > 0) {
                                                        if ($gapBefore >= $gapAfter) {
                                                            $freeStart = $ts['start_time'];
                                                            $freeEnd = $firstOccStart;
                                                        } else {
                                                            $freeStart = $lastOccEnd;
                                                            $freeEnd = $ts['end_time'];
                                                        }
                                                        $status = 'partial';
                                                    }
                                                }
                                            }
                                        }
                                        $cellClass = match ($status) {
                                            'available' => 'sb-slot-avail', 'partial' => 'sb-slot-partial', 'class' => 'sb-slot-class', 'booked' => 'sb-slot-booked', default => 'sb-slot-avail'
                                        };
                                        $isBookable = ($status === 'available' || $status === 'partial') && !$isPast && !$isPastTime;
                                        $dataJson = htmlspecialchars(json_encode($data), ENT_QUOTES);
                                        ?>
                                        <td class="sb-day-slot <?= $cellClass ?> <?= $isBookable ? 'sb-bookable' : '' ?> <?= $isCurrent ? 'sb-slot-current' : '' ?> <?= ($isPast || $isPastTime) ? 'sb-slot-past' : '' ?>"
                                            colspan="<?= $span ?>" data-room-id="<?= $room['id'] ?>"
                                            data-room-name="<?= htmlspecialchars($room['room_name']) ?>"
                                            data-room-faculty-id="<?= $room['faculty_id'] ?? '' ?>"
                                            data-building="<?= htmlspecialchars($room['building_name']) ?>" data-date="<?= $sb_date ?>"
                                            data-slot-start="<?= $ts['start_time'] ?>" data-slot-end="<?= $ts['end_time'] ?>"
                                            data-free-start="<?= $freeStart ?>" data-free-end="<?= $freeEnd ?>" data-status="<?= $status ?>"
                                            data-detail="<?= $dataJson ?>">
                                            <?php if ($status === 'available' && !$isPast && !$isPastTime): ?><span
                                                    class="sb-slot-label">Free</span>
                                            <?php elseif ($status === 'partial' && !$isPast && !$isPastTime): ?><span
                                                    class="sb-slot-label sb-partial-label">Partial<br><small><?= date('g:i', strtotime($freeStart)) ?>–<?= date('g:i', strtotime($freeEnd)) ?></small></span>
                                            <?php elseif ($status === 'class'): ?><span
                                                    class="sb-slot-label sb-class-label"><small><?= htmlspecialchars(($data['degree_name'] ?? '') . ' L' . ($data['level'] ?? '') . ' S' . ($data['semester'] ?? '')) ?></small><br><?= htmlspecialchars($data['course_name'] ?? $data['course_code'] ?? 'Class') ?><br><small
                                                        class="sb-slot-meta"><?= htmlspecialchars($data['teacher_name'] ?? '') ?></small></span>
                                            <?php elseif ($status === 'booked'): ?><span
                                                    class="sb-slot-label sb-booked-label"><small><?= htmlspecialchars(($data['degree_name'] ?? '') . ' L' . ($data['level'] ?? '') . ' S' . ($data['semester'] ?? '')) ?></small><br><?= htmlspecialchars($data['purpose'] ?? 'Booked') ?><br><small
                                                        class="sb-slot-meta"><?= htmlspecialchars($data['booked_by'] ?? '') ?></small></span>
                                            <?php else: ?><span class="sb-slot-label">Past</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div><!-- end #sb-day-content -->
            <?php
        }
        $html = ob_get_clean();
        echo json_encode(['success' => true, 'html' => $html]);
        exit();
    }

    if ($_GET['ajax'] == 'sb_book_room') {
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $booking_date = $_POST['booking_date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');
        $course_id = (int) ($_POST['course_id'] ?? 0);

        if (!$room_id || !$booking_date || !$start_time || !$end_time || (!$purpose && !$purpose_custom)) {
            echo json_encode(['success' => false, 'error' => 'All required fields must be filled.']);
            exit();
        }
        if ($booking_date < date('Y-m-d')) {
            echo json_encode(['success' => false, 'error' => 'Cannot book rooms in the past.']);
            exit();
        }

        $final_purpose = ($purpose === 'Other') ? $purpose_custom : $purpose;
        if (empty($final_purpose)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a purpose.']);
            exit();
        }

        $degree_program_id = (int) ($_POST['degree_program_id'] ?? 0);
        $level_sem = $_POST['level_sem'] ?? '';
        if (!$degree_program_id || !$level_sem) {
            echo json_encode(['success' => false, 'error' => 'Degree Program and Level-Semester are required.']);
            exit();
        }
        preg_match('/L(\d+)\s+S(\d+)/', $level_sem, $matches);
        if (count($matches) !== 3) {
            echo json_encode(['success' => false, 'error' => 'Invalid Level-Semester format.']);
            exit();
        }
        $level = (int) $matches[1];
        $semester_num = (int) $matches[2];
        $group_name = trim($_POST['group_name'] ?? '') ?: null;

        $semStmt = $pdo->prepare("
            SELECT id, start_date, end_date FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND (group_name = ? OR group_name = '' OR group_name IS NULL)
              AND start_date <= ? AND end_date >= ?
            LIMIT 1
        ");
        $semStmt->execute([$degree_program_id, $level, $semester_num, $group_name, $booking_date, $booking_date]);
        $semester = $semStmt->fetch();
        if (!$semester) {
            echo json_encode(['success' => false, 'error' => 'No active semester found for the selected combination on this date.']);
            exit();
        }

        // Issue 5: Validate teacher's department matches the course's department
        if ($course_id) {
            $deptCheckStmt = $pdo->prepare("
                SELECT c.department_id, u.department_id AS teacher_dept_id
                FROM courses c
                CROSS JOIN users u
                WHERE c.id = ? AND u.id = ?
            ");
            $deptCheckStmt->execute([$course_id, $teacher_id]);
            $deptCheck = $deptCheckStmt->fetch();
            if (
                $deptCheck && $deptCheck['department_id'] !== null && $deptCheck['teacher_dept_id'] !== null
                && $deptCheck['department_id'] != $deptCheck['teacher_dept_id']
            ) {
                echo json_encode(['success' => false, 'error' => 'You can only book rooms for courses offered by your department.']);
                exit();
            }
        }

        $current_user_id = $teacher_id;
        $availability = isRoomAvailable($pdo, $room_id, $booking_date, $start_time, $end_time);

        if (!$availability['available']) {
            $conflict = $availability['conflicts'][0] ?? [];

            $priorityMsg = '';
            $roomFacStmt = $pdo->prepare("SELECT r.faculty_id FROM rooms r WHERE r.id = ?");
            $roomFacStmt->execute([$room_id]);
            $roomFac = $roomFacStmt->fetchColumn();

            $teacherFacStmt = $pdo->prepare("
                SELECT d.faculty_id FROM users u
                LEFT JOIN departments d ON u.department_id = d.id
                WHERE u.id = ?
            ");
            $teacherFacStmt->execute([$teacher_id]);
            $teacherFac = $teacherFacStmt->fetchColumn();

            if ($roomFac && $teacherFac && $roomFac == $teacherFac) {
                $priorityMsg = 'priority_faculty';
            }

            $conflictType = ($conflict['type'] === 'recurring_schedule') ? 'schedule_conflict' : 'room_booked';
            $blockingBookingId = ($conflict['type'] === 'one_time_booking') ? ($conflict['id'] ?? null) : null;
            $blockingScheduleId = ($conflict['type'] === 'recurring_schedule') ? ($conflict['id'] ?? null) : null;

            $baStmt = $pdo->prepare("
                INSERT INTO booking_attempts
                    (user_id, user_type, room_id, attempted_date, attempted_start_time,
                     attempted_end_time, purpose, conflict_type, blocking_booking_id, blocking_schedule_id)
                VALUES (?, 'teacher', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $baStmt->execute([
                $current_user_id,
                $room_id,
                $booking_date,
                $start_time,
                $end_time,
                $final_purpose,
                $conflictType,
                $blockingBookingId,
                $blockingScheduleId
            ]);

            $conflictDetail = ($conflict['type'] === 'one_time_booking')
                ? "Already booked by {$conflict['booked_by']} for \"{$conflict['purpose']}\" at {$conflict['time']}"
                : "Scheduled class: {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}";

            echo json_encode([
                'success' => false,
                'conflict' => $conflictDetail,
                'priority_class' => $priorityMsg,
            ]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO room_bookings
                    (room_id, booked_by, booking_date, start_time, end_time, purpose,
                     degree_program_id, level, semester, group_name, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', NOW())
            ");
            $stmt->execute([
                $room_id,
                $current_user_id,
                $booking_date,
                $start_time,
                $end_time,
                $final_purpose,
                $degree_program_id,
                $level,
                $semester_num,
                $group_name
            ]);
            $new_booking_id = $pdo->lastInsertId();

            if (function_exists('resolvePendingBookingAttempts')) {
                resolvePendingBookingAttempts($pdo, $room_id, $booking_date, $start_time, $end_time, $current_user_id);
            }

            $actStmt = $pdo->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, room_id, details, ip_address)
                VALUES (?, 'book_room', 'room_booking', ?, ?, ?, ?)
            ");
            $actStmt->execute([
                $current_user_id,
                $new_booking_id,
                $room_id,
                json_encode(['purpose' => $final_purpose, 'date' => $booking_date, 'time' => "$start_time - $end_time"]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            $bookerStmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $bookerStmt->execute([$current_user_id]);
            $booker = $bookerStmt->fetch();

            echo json_encode([
                'success' => true,
                'booking_id' => $new_booking_id,
                'booking_data' => [
                    'type' => 'booking',
                    'id' => (int) $new_booking_id,
                    'purpose' => $final_purpose,
                    'booked_by' => $booker['full_name'],
                    'booked_by_phone' => $booker['phone'],
                    'booked_by_id' => $current_user_id,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'degree_program_id' => $degree_program_id,
                    'level' => $level,
                    'semester' => $semester_num,
                    'group_name' => $group_name,
                    'course_id' => $course_id
                ],
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'edit_booking') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $degree_program_id = (int) ($_POST['degree_program_id'] ?? 0);
        $level_sem = $_POST['level_sem'] ?? '';
        $group_name = trim($_POST['group_name'] ?? '') ?: null;
        $course_id = (int) ($_POST['course_id'] ?? 0);

        if (!$booking_id || !$start_time || !$end_time || !$degree_program_id || !$level_sem) {
            echo json_encode(['success' => false, 'error' => 'All required fields must be filled.']);
            exit();
        }
        if ((!$purpose && !$purpose_custom)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a purpose.']);
            exit();
        }
        $final_purpose = ($purpose === 'Other') ? $purpose_custom : $purpose;
        if (empty($final_purpose)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a purpose.']);
            exit();
        }

        preg_match('/L(\d+)\s+S(\d+)/', $level_sem, $matches);
        if (count($matches) !== 3) {
            echo json_encode(['success' => false, 'error' => 'Invalid Level-Semester format.']);
            exit();
        }
        $level = (int) $matches[1];
        $semester_num = (int) $matches[2];

        $stmt = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? AND booked_by = ? AND status = 'booked'");
        $stmt->execute([$booking_id, $teacher_id]);
        $booking = $stmt->fetch();
        if (!$booking) {
            echo json_encode(['success' => false, 'error' => 'Booking not found or not owned by you.']);
            exit();
        }
        $now = date('Y-m-d H:i:s');
        if ($booking['booking_date'] . ' ' . $booking['start_time'] <= $now) {
            echo json_encode(['success' => false, 'error' => 'Cannot edit past or ongoing bookings.']);
            exit();
        }

        $semStmt = $pdo->prepare("
            SELECT id FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND (group_name = ? OR group_name = '' OR group_name IS NULL)
              AND start_date <= ? AND end_date >= ?
            LIMIT 1
        ");
        $semStmt->execute([$degree_program_id, $level, $semester_num, $group_name, $booking['booking_date'], $booking['booking_date']]);
        if (!$semStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No active semester found for the selected combination.']);
            exit();
        }

        $availability = isRoomAvailable($pdo, $booking['room_id'], $booking['booking_date'], $start_time, $end_time, $booking_id);
        if (!$availability['available']) {
            $conflict = $availability['conflicts'][0] ?? [];
            $msg = ($conflict['type'] ?? '') === 'one_time_booking'
                ? "Already booked by {$conflict['booked_by']} for \"{$conflict['purpose']}\" at {$conflict['time']}"
                : "Scheduled class: {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}";
            echo json_encode(['success' => false, 'error' => $msg]);
            exit();
        }

        try {
            $pdo->prepare("
                UPDATE room_bookings SET
                    purpose = ?, start_time = ?, end_time = ?,
                    degree_program_id = ?, level = ?, semester = ?, group_name = ?
                WHERE id = ?
            ")->execute([$final_purpose, $start_time, $end_time, $degree_program_id, $level, $semester_num, $group_name, $booking_id]);
            echo json_encode(['success' => true, 'message' => 'Booking updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'edit_schedule') {
        $schedule_id = (int) ($_POST['schedule_id'] ?? 0);
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $day_of_week = (int) ($_POST['day_of_week'] ?? 0);
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $group_name = trim($_POST['group_name'] ?? '') ?: null;
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');

        if (!$schedule_id || !$course_id || !$room_id || !$day_of_week || !$start_time || !$end_time) {
            echo json_encode(['success' => false, 'error' => 'All fields are required.']);
            exit();
        }
        $final_purpose = ($purpose === 'Other') ? $purpose_custom : $purpose;
        if (empty($final_purpose)) {
            echo json_encode(['success' => false, 'error' => 'Please provide a purpose.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM course_schedule WHERE id = ? AND teacher_id = ? AND status = 'scheduled'");
        $stmt->execute([$schedule_id, $teacher_id]);
        $schedule = $stmt->fetch();
        if (!$schedule) {
            echo json_encode(['success' => false, 'error' => 'Schedule not found or not owned by you.']);
            exit();
        }

        $cStmt = $pdo->prepare("SELECT course_code, course_name, degree_program_id, level, semester FROM courses WHERE id = ?");
        $cStmt->execute([$course_id]);
        $course = $cStmt->fetch();
        if (!$course) {
            echo json_encode(['success' => false, 'error' => 'Invalid course.']);
            exit();
        }

        $semStmt = $pdo->prepare("
            SELECT id FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND (group_name = ? OR group_name = '' OR group_name IS NULL)
              AND start_date <= CURDATE() AND end_date >= CURDATE()
            LIMIT 1
        ");
        $semStmt->execute([$course['degree_program_id'], $course['level'], $course['semester'], $group_name]);
        if (!$semStmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'No active semester found.']);
            exit();
        }

        require_once __DIR__ . '/includes/schedule_validation.php';
        $availability = checkRecurringScheduleAvailability(
            $pdo,
            $room_id,
            $day_of_week,
            $start_time,
            $end_time,
            $schedule['start_date'],
            $schedule['end_date'],
            $schedule_id
        );

        if (!$availability['available']) {
            $conflict = $availability['conflicts'][0];
            echo json_encode([
                'success' => false,
                'error' => "Conflict with {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}"
            ]);
            exit();
        }

        try {
            $pdo->prepare("
                UPDATE course_schedule SET
                    room_id = ?, course_code = ?, course_name = ?,
                    degree_program_id = ?, level = ?, semester = ?,
                    group_name = ?, day_of_week = ?, start_time = ?, end_time = ?,
                    purpose = ?
                WHERE id = ?
            ")->execute([
                        $room_id,
                        $course['course_code'],
                        $course['course_name'],
                        $course['degree_program_id'],
                        $course['level'],
                        $course['semester'],
                        $group_name,
                        $day_of_week,
                        $start_time,
                        $end_time,
                        $final_purpose,
                        $schedule_id
                    ]);
            notifyCRsAboutScheduleChange($pdo, array_merge($schedule, $course, ['group_name' => $group_name, 'purpose' => $final_purpose]), 'edited');
            echo json_encode(['success' => true, 'message' => 'Schedule updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'get_level_semesters') {
        $dp_id = (int) ($_GET['degree_program_id'] ?? 0);
        if (!$dp_id) {
            echo json_encode([]);
            exit();
        }
        $stmt = $pdo->prepare("SELECT total_levels, semesters_per_level FROM degree_programs WHERE id = ?");
        $stmt->execute([$dp_id]);
        $dp = $stmt->fetch();
        $options = [];
        if ($dp) {
            for ($l = 1; $l <= $dp['total_levels']; $l++) {
                for ($s = 1; $s <= ($dp['semesters_per_level'] ?? 2); $s++) {
                    $options[] = ['value' => "L$l S$s", 'text' => "L$l S$s"];
                }
            }
        }
        echo json_encode($options);
        exit();
    }

    if ($_GET['ajax'] == 'get_groups_for_semester') {
        $dp_id = (int) ($_GET['degree_program_id'] ?? 0);
        $level = (int) ($_GET['level'] ?? 0);
        $sem_num = (int) ($_GET['semester_num'] ?? 0);
        if (!$dp_id || !$level || !$sem_num) {
            echo json_encode([]);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT DISTINCT group_name FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND start_date <= CURDATE() AND end_date >= CURDATE()
              AND group_name IS NOT NULL AND group_name != ''
            ORDER BY group_name
        ");
        $stmt->execute([$dp_id, $level, $sem_num]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit();
    }

    if ($_GET['ajax'] == 'get_courses') {
        $dp_id = (int) ($_GET['degree_program_id'] ?? 0);
        $level = (int) ($_GET['level'] ?? 0);
        $sem = (int) ($_GET['semester'] ?? 0);
        if (!$dp_id || !$level || !$sem) {
            echo json_encode([]);
            exit();
        }
        // Show courses from the teacher's own department (looked up via teachers.user_id)
        $stmt = $pdo->prepare("
            SELECT id, course_code, course_name FROM courses
            WHERE degree_program_id=? AND level=? AND semester=? AND status='active'
              AND (department_id IS NULL OR department_id = (
                  SELECT department_id FROM teachers WHERE user_id = ? LIMIT 1
              ))
            ORDER BY course_code
        ");
        $stmt->execute([$dp_id, $level, $sem, $teacher_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }

    if ($_GET['ajax'] == 'get_courses_for_booking') {
        $dp_id = (int) ($_GET['degree_program_id'] ?? 0);
        $level = (int) ($_GET['level'] ?? 0);
        $semester = (int) ($_GET['semester'] ?? 0);
        if (!$dp_id || !$level || !$semester) {
            echo json_encode([]);
            exit();
        }
        // Show courses from the teacher's own department (looked up via teachers.user_id)
        $stmt = $pdo->prepare("
            SELECT id, course_code, course_name FROM courses
            WHERE degree_program_id=? AND level=? AND semester=? AND status='active'
              AND (department_id IS NULL OR department_id = (
                  SELECT department_id FROM teachers WHERE user_id = ? LIMIT 1
              ))
            ORDER BY course_code
        ");
        $stmt->execute([$dp_id, $level, $semester, $teacher_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        exit();
    }

    if ($_GET['ajax'] == 'get_semester_dates') {
        $dp_id = (int) ($_GET['degree_program_id'] ?? 0);
        $level = (int) ($_GET['level'] ?? 0);
        $sem_num = (int) ($_GET['semester_num'] ?? 0);
        $group_name = $_GET['group_name'] ?? null;
        if (!$dp_id || !$level || !$sem_num) {
            echo json_encode([]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT start_date, end_date FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND (group_name = ? OR group_name = '' OR group_name IS NULL)
              AND start_date <= CURDATE() AND end_date >= CURDATE()
            LIMIT 1
        ");
        $stmt->execute([$dp_id, $level, $sem_num, $group_name]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit();
    }

    if ($_GET['ajax'] == 'get_booking_details') {
        $booking_id = (int) ($_GET['id'] ?? 0);
        if (!$booking_id) {
            echo json_encode(['success' => false]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, b.name as building_name
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            WHERE rb.id = ? AND rb.booked_by = ?
        ");
        $stmt->execute([$booking_id, $teacher_id]);
        $booking = $stmt->fetch();
        if ($booking) {
            echo json_encode(['success' => true, 'booking' => $booking]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'get_schedule_details') {
        $schedule_id = (int) ($_GET['id'] ?? 0);
        if (!$schedule_id) {
            echo json_encode(['success' => false]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT cs.*, c.id as course_id
            FROM course_schedule cs
            JOIN courses c ON cs.course_code = c.course_code AND cs.degree_program_id = c.degree_program_id AND cs.level = c.level AND cs.semester = c.semester
            WHERE cs.id = ? AND cs.teacher_id = ?
        ");
        $stmt->execute([$schedule_id, $teacher_id]);
        $schedule = $stmt->fetch();
        if ($schedule) {
            echo json_encode(['success' => true, 'schedule' => $schedule]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'check_schedule_conflict') {
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $day_of_week = (int) ($_POST['day_of_week'] ?? 0);
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? null;
        $exclude_schedule_id = (int) ($_POST['exclude_schedule_id'] ?? 0);

        if (!$room_id || !$day_of_week || !$start_time || !$end_time) {
            echo json_encode(['conflict' => false]);
            exit();
        }

        require_once __DIR__ . '/includes/schedule_validation.php';
        $availability = checkRecurringScheduleAvailability($pdo, $room_id, $day_of_week, $start_time, $end_time, $start_date, $end_date, $exclude_schedule_id);

        if (!$availability['available']) {
            $conflict = $availability['conflicts'][0];
            $altRooms = [];
            $roomStmt = $pdo->prepare("
                SELECT r.id, r.room_name, b.name as building_name
                FROM rooms r
                JOIN floors fl ON r.floor_id = fl.id
                JOIN buildings b ON fl.building_id = b.id
                WHERE r.id != ? AND r.status = 'active'
                ORDER BY b.name, r.room_name
            ");
            $roomStmt->execute([$room_id]);
            while ($r = $roomStmt->fetch()) {
                $av = checkRecurringScheduleAvailability($pdo, $r['id'], $day_of_week, $start_time, $end_time, $start_date, $end_date);
                if ($av['available']) {
                    $altRooms[] = ['id' => $r['id'], 'name' => $r['room_name'], 'building' => $r['building_name']];
                    if (count($altRooms) >= 3)
                        break;
                }
            }
            $altTimes = [];
            $timeSlots = $pdo->query("SELECT start_time, end_time FROM time_slots ORDER BY slot_order")->fetchAll();
            foreach ($timeSlots as $ts) {
                if ($ts['start_time'] == $start_time)
                    continue;
                $av = checkRecurringScheduleAvailability($pdo, $room_id, $day_of_week, $ts['start_time'], $ts['end_time'], $start_date, $end_date);
                if ($av['available']) {
                    $altTimes[] = ['start' => $ts['start_time'], 'end' => $ts['end_time']];
                    if (count($altTimes) >= 3)
                        break;
                }
            }
            echo json_encode([
                'conflict' => true,
                'message' => "Conflict with {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}",
                'alt_rooms' => $altRooms,
                'alt_times' => $altTimes
            ]);
        } else {
            echo json_encode(['conflict' => false]);
        }
        exit();
    }

    if ($_GET['ajax'] == 'cancel_schedule') {
        $schedule_id = (int) ($_POST['schedule_id'] ?? 0);
        $cancel_type = $_POST['cancel_type'] ?? 'series';
        $date = $_POST['date'] ?? '';
        $reason = trim($_POST['reason'] ?? 'Cancelled by teacher');

        if (!$schedule_id) {
            echo json_encode(['success' => false, 'error' => 'Invalid schedule.']);
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM course_schedule WHERE id = ? AND teacher_id = ? AND status='scheduled'");
        $stmt->execute([$schedule_id, $teacher_id]);
        $schedule = $stmt->fetch();
        if (!$schedule) {
            echo json_encode(['success' => false, 'error' => 'Schedule not found or not owned by you.']);
            exit();
        }

        try {
            if ($cancel_type === 'series') {
                $pdo->prepare("UPDATE course_schedule SET status='cancelled', cancelled_at=NOW(), cancelled_by=?, cancellation_reason=? WHERE id=?")
                    ->execute([$teacher_id, $reason, $schedule_id]);
                $schedule['cancellation_reason'] = $reason;
                notifyCRsAboutScheduleChange($pdo, $schedule, 'cancelled');
                $message = "The entire schedule series has been cancelled.";
            } else {
                if (!$date) {
                    echo json_encode(['success' => false, 'error' => 'Date is required for single occurrence cancellation.']);
                    exit();
                }
                $pdo->prepare("INSERT INTO schedule_exceptions (course_schedule_id, exception_date, exception_type, reason, created_by) VALUES (?, ?, 'cancelled', ?, ?)")
                    ->execute([$schedule_id, $date, $reason, $teacher_id]);
                $schedule['cancellation_reason'] = $reason;
                notifyCRsAboutScheduleChange($pdo, $schedule, 'cancelled', $date);
                $message = "The class on " . date('M d, Y', strtotime($date)) . " has been cancelled.";
            }
            echo json_encode(['success' => true, 'message' => $message]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // Enhanced refresh_my_schedule handler that includes today's bookings
    if ($_GET['ajax'] == 'refresh_my_schedule') {
        $section = $_GET['section'] ?? 'all';
        $today = date('Y-m-d');
        $current_day = date('N');

        // Fetch teacher's recurring schedules
        $stmt = $pdo->prepare("
            SELECT cs.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
                   dp.name as degree_name
            FROM course_schedule cs
            JOIN rooms r ON cs.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            WHERE cs.teacher_id = ? AND cs.status = 'scheduled'
            ORDER BY cs.day_of_week, cs.start_time
        ");
        $stmt->execute([$teacher_id]);
        $teacher_schedule = $stmt->fetchAll();

        // Today's classes from recurring schedule
        $todays_classes = [];
        foreach ($teacher_schedule as $class) {
            if ($class['day_of_week'] == $current_day) {
                $todays_classes[] = $class;
            }
        }

        // Also fetch one-time bookings for today
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, r.room_number, b.name as building_name, dp.name as degree_name
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
            WHERE rb.booked_by = ? AND rb.booking_date = ? AND rb.status = 'booked'
            ORDER BY rb.start_time
        ");
        $stmt->execute([$teacher_id, $today]);
        $todays_bookings = $stmt->fetchAll();

        // Merge both into a map by start_time (key = start_time without seconds)
        $today_schedule = [];
        foreach ($timeSlots as $ts) {
            $key = substr($ts['start_time'], 0, 5);
            $today_schedule[$key] = null;
        }
        foreach ($todays_classes as $class) {
            $key = substr($class['start_time'], 0, 5);
            $today_schedule[$key] = ['type' => 'class', 'data' => $class];
        }
        foreach ($todays_bookings as $booking) {
            $key = substr($booking['start_time'], 0, 5);
            if (!isset($today_schedule[$key])) {
                $today_schedule[$key] = ['type' => 'booking', 'data' => $booking];
            }
        }

        // Week calendar data – build a table with colspan
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $week_dates = [];
        for ($i = 0; $i < 7; $i++) {
            $week_dates[] = date('Y-m-d', strtotime($week_start . " +$i days"));
        }
        // Fetch bookings for the week
        $week_bookings = [];
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, r.room_number, b.name as building_name
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            WHERE rb.booked_by = ? AND rb.status = 'booked' AND rb.booking_date BETWEEN ? AND ?
            ORDER BY rb.booking_date, rb.start_time
        ");
        $stmt->execute([$teacher_id, $week_start, date('Y-m-d', strtotime($week_start . ' +6 days'))]);
        $week_bookings = $stmt->fetchAll();

        $bookings_by_date = [];
        foreach ($week_bookings as $b) {
            $bookings_by_date[$b['booking_date']][] = $b;
        }

        $course_schedules = $teacher_schedule; // only recurring classes

        // Build merged week grid: [day][slot_index] => entry (null, class, booking)
        $week_grid = [];
        for ($d = 0; $d < 7; $d++) {
            $current_date = $week_dates[$d];
            $day_of_week = $d + 1;
            for ($slot_idx = 0; $slot_idx < count($timeSlots); $slot_idx++) {
                $ts = $timeSlots[$slot_idx];
                $entries = [];
                // recurring classes
                foreach ($course_schedules as $cs) {
                    if ($cs['day_of_week'] == $day_of_week && tsOverlaps($ts['start_time'], $ts['end_time'], $cs['start_time'], $cs['end_time'])) {
                        $entries[] = ['type' => 'class', 'data' => $cs];
                    }
                }
                // one‑time bookings
                if (isset($bookings_by_date[$current_date])) {
                    foreach ($bookings_by_date[$current_date] as $b) {
                        if (tsOverlaps($ts['start_time'], $ts['end_time'], $b['start_time'], $b['end_time'])) {
                            $entries[] = ['type' => 'booking', 'data' => $b];
                        }
                    }
                }
                // Take first (should not have overlapping conflicts)
                $week_grid[$d][$slot_idx] = !empty($entries) ? $entries[0] : null;
            }
        }

        // Merge consecutive slots for each day
        $merged_grid = [];
        for ($d = 0; $d < 7; $d++) {
            $merged = [];
            $i = 0;
            $slot_count = count($timeSlots);
            while ($i < $slot_count) {
                $current = $week_grid[$d][$i];
                if ($current === null) {
                    $merged[] = ['type' => 'free', 'span' => 1];
                    $i++;
                    continue;
                }
                $current_id = $current['type'] == 'class' ? $current['data']['id'] : $current['data']['id'];
                $span = 1;
                $j = $i + 1;
                while ($j < $slot_count) {
                    $next = $week_grid[$d][$j];
                    if (
                        $next !== null && $next['type'] == $current['type'] &&
                        (($current['type'] == 'class' && $next['data']['id'] == $current_id) ||
                            ($current['type'] == 'booking' && $next['data']['id'] == $current_id))
                    ) {
                        $span++;
                        $j++;
                    } else {
                        break;
                    }
                }
                $merged[] = ['type' => $current['type'], 'data' => $current['data'], 'span' => $span];
                $i = $j;
            }
            $merged_grid[$d] = $merged;
        }

        // My Bookings (unchanged)
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, r.room_number, b.name as building_name, dp.name as degree_name
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
            WHERE rb.booked_by = ?
            ORDER BY rb.booking_date DESC, rb.start_time
            LIMIT 50
        ");
        $stmt->execute([$teacher_id]);
        $my_bookings = $stmt->fetchAll();

        ob_start();
        if ($section == 'today' || $section == 'all') {
            ?>
            <div class="schedule-today-grid">
                <?php foreach ($timeSlots as $ts):
                    $key = substr($ts['start_time'], 0, 5);
                    $item = $today_schedule[$key] ?? null; ?>
                    <div class="schedule-time-slot"><?= date('g:i A', strtotime($ts['start_time'])) ?></div>
                    <div class="schedule-class-detail <?= $item ? ($item['type'] == 'class' ? 'has-schedule' : 'has-booking') : '' ?>"
                        <?php if ($item): ?> data-schedule-type="<?= $item['type'] ?>" data-schedule-id="<?= $item['data']['id'] ?>"
                            data-course-code="<?= htmlspecialchars($item['type'] == 'class' ? $item['data']['course_code'] : 'BOOKED') ?>"
                            data-date="<?= $today ?>" <?php endif; ?>>
                        <?php if ($item && $item['type'] == 'class'): ?>
                            <strong><?= htmlspecialchars($item['data']['course_code']) ?></strong> -
                            <?= htmlspecialchars($item['data']['course_name']) ?><br>
                            <small><?= htmlspecialchars($item['data']['building_name'] . ' - ' . $item['data']['room_name']) ?></small><br>
                            <span class="text-muted"><?= htmlspecialchars($item['data']['degree_name'] ?? '') ?>
                                L<?= $item['data']['level'] ?></span>
                        <?php elseif ($item && $item['type'] == 'booking'): ?>
                            <strong>📅 Booking</strong> - <?= htmlspecialchars($item['data']['purpose']) ?><br>
                            <small><?= htmlspecialchars($item['data']['building_name'] . ' - ' . $item['data']['room_name']) ?></small><br>
                            <span class="text-muted"><?= htmlspecialchars($item['data']['degree_name'] ?? '') ?>
                                L<?= $item['data']['level'] ?> S<?= $item['data']['semester'] ?></span>
                        <?php else: ?>
                            <span class="text-muted">No class</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php
        }
        if ($section == 'week' || $section == 'all') {
            $day_names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $short_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            ?>
            <div class="my-week-table-wrapper">
                <table class="my-week-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <?php for ($d = 0; $d < 7; $d++): ?>
                                <th><?= $short_names[$d] ?><br><small><?= date('M j', strtotime($week_dates[$d])) ?></small></th>
                            <?php endfor; ?>
                </table>
                </thead>
                <tbody>
                    <?php for ($slot_idx = 0; $slot_idx < count($timeSlots); $slot_idx++):
                        $ts = $timeSlots[$slot_idx];
                        // For each day, check if this slot is the start of a merged cell
                        $displayed = array_fill(0, 7, false);
                        for ($d = 0; $d < 7; $d++) {
                            $cumulative = 0;
                            foreach ($merged_grid[$d] as $entry) {
                                if ($slot_idx >= $cumulative && $slot_idx < $cumulative + $entry['span']) {
                                    if ($slot_idx == $cumulative) {
                                        $displayed[$d] = true;
                                    }
                                    break;
                                }
                                $cumulative += $entry['span'];
                            }
                        }
                        if (!in_array(true, $displayed))
                            continue;
                        ?>
                        <tr>
                            <td class="my-week-time"><?= date('g:i', strtotime($ts['start_time'])) ?> –
                                <?= date('g:i', strtotime($ts['end_time'])) ?>
                            </td>
                            <?php for ($d = 0; $d < 7; $d++):
                                $cumulative = 0;
                                $entry = null;
                                foreach ($merged_grid[$d] as $e) {
                                    if ($slot_idx >= $cumulative && $slot_idx < $cumulative + $e['span']) {
                                        $entry = $e;
                                        break;
                                    }
                                    $cumulative += $e['span'];
                                }
                                if (!$entry || $slot_idx != $cumulative)
                                    continue;
                                $span = $entry['span'];
                                $type = $entry['type'];
                                if ($type == 'free') {
                                    echo '<td class="my-week-cell free"> </td>';
                                } elseif ($type == 'class') {
                                    $c = $entry['data'];
                                    $degLabel = htmlspecialchars(($c['degree_name'] ?? '') . ' L' . $c['level'] . ' S' . $c['semester']);
                                    echo '<td class="my-week-cell class" rowspan="' . $span . '" data-schedule-type="class" data-schedule-id="' . $c['id'] . '" data-course-code="' . htmlspecialchars($c['course_code']) . '" data-date="' . $week_dates[$d] . '">
                                                <div class="my-week-class"><small class="mw-degree">' . $degLabel . '</small><br>' . htmlspecialchars($c['course_name']) . '<br><small>' . htmlspecialchars($c['room_name'] . ', ' . $c['building_name']) . '</small></div>
                                               </td>';
                                } else { // booking
                                    $b = $entry['data'];
                                    $degLabel = htmlspecialchars(($b['degree_name'] ?? '') . ' L' . ($b['level'] ?? '') . ' S' . ($b['semester'] ?? ''));
                                    echo '<td class="my-week-cell booking" rowspan="' . $span . '" data-booking-id="' . $b['id'] . '">
                                                <div class="my-week-booking"><small class="mw-degree">' . $degLabel . '</small><br>' . htmlspecialchars($b['purpose']) . '<br><small>' . htmlspecialchars($b['room_name'] ?? 'Room') . '</small></div>
                                               </td>';
                                }
                            endfor; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
                </table>
            </div>
            <?php
        }
        if ($section == 'bookings' || $section == 'all') {
            ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Room</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_bookings as $booking): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($booking['room_name']) ?></strong><br><small
                                        class="text-muted"><?= htmlspecialchars($booking['building_name']) ?></small></td>
                                <td><?= date('M d, Y', strtotime($booking['booking_date'])) ?></td>
                                <td><?= formatTime($booking['start_time']) ?> - <?= formatTime($booking['end_time']) ?></td>
                                <td><?= htmlspecialchars($booking['purpose']) ?></td>
                                <td><span
                                        class="badge badge-<?= $booking['status'] == 'booked' ? 'success' : ($booking['status'] == 'cancelled' ? 'warning' : 'secondary') ?>"><?= ucfirst($booking['status']) ?></span>
                                </td>
                                <td><?php if ($booking['status'] == 'booked' && $booking['booking_date'] >= date('Y-m-d')): ?>
                                        <button class="btn btn-sm btn-danger"
                                            onclick="freeRoomModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['room_name']) ?>')">Free</button>
                                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }
        $html = ob_get_clean();
        echo json_encode(['success' => true, 'html' => $html]);
        exit();
    }

    exit();
}

// Handle POST requests (free_room, add_schedule)
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'free_room') {
        $booking_id = $_POST['booking_id'];
        $reason = $_POST['reason'] ?? '';
        try {
            $stmt = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? AND booked_by = ?");
            $stmt->execute([$booking_id, $teacher_id]);
            $booking = $stmt->fetch();
            if ($booking) {
                $pdo->prepare("UPDATE room_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ? WHERE id = ?")
                    ->execute([$teacher_id, $reason, $booking_id]);
                $booking['cancellation_reason'] = $reason;
                notifyCRsAboutBookingChange($pdo, $booking, 'cancelled');
                $message = "Booking cancelled successfully!";
            }
        } catch (PDOException $e) {
            $error = "Failed to cancel booking: " . $e->getMessage();
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'add_schedule_ajax') {
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $day_of_week = (int) ($_POST['day_of_week'] ?? 0);
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $is_whole_semester = isset($_POST['is_whole_semester']) ? 1 : 0;
        $start_date = $_POST['start_date'] ?? null;
        $end_date = $_POST['end_date'] ?? null;
        $group_name = trim($_POST['group_name'] ?? '') ?: null;
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');

        if (!$room_id || !$course_id || !$day_of_week || !$start_time || !$end_time) {
            $error = "All fields are required.";
        } else {
            $final_purpose = ($purpose === 'Other') ? $purpose_custom : $purpose;
            if (empty($final_purpose)) {
                $error = "Please provide a purpose.";
            } else {
                $cStmt = $pdo->prepare("SELECT course_code, course_name, degree_program_id, level, semester FROM courses WHERE id = ?");
                $cStmt->execute([$course_id]);
                $course = $cStmt->fetch();
                if (!$course) {
                    $error = "Invalid course selected.";
                } else {
                    // Issue 5: Validate teacher's department matches the course's department
                    if (!empty($course['department_id'])) {
                        $teacherDeptStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
                        $teacherDeptStmt->execute([$teacher_id]);
                        $teacherDept = $teacherDeptStmt->fetchColumn();
                        if ($teacherDept && $course['department_id'] != $teacherDept) {
                            $error = "You can only schedule courses offered by your department.";
                        }
                    }
                    require_once __DIR__ . '/includes/schedule_validation.php';
                    $availability = checkRecurringScheduleAvailability($pdo, $room_id, $day_of_week, $start_time, $end_time, $start_date, $end_date);
                    if (!$availability['available']) {
                        $conflict = $availability['conflicts'][0];
                        $error = "Cannot add schedule: Conflict with {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}";
                    } else {
                        try {
                            $stmt = $pdo->prepare("
                                INSERT INTO course_schedule
                                    (room_id, teacher_id, degree_program_id, course_code, course_name,
                                     level, semester, group_name, day_of_week, start_time, end_time,
                                     is_whole_semester, start_date, end_date, purpose, created_by, created_at, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'scheduled')
                            ");
                            $stmt->execute([
                                $room_id,
                                $teacher_id,
                                $course['degree_program_id'],
                                $course['course_code'],
                                $course['course_name'],
                                $course['level'],
                                $course['semester'],
                                $group_name,
                                $day_of_week,
                                $start_time,
                                $end_time,
                                $is_whole_semester,
                                $start_date,
                                $end_date,
                                $final_purpose,
                                $teacher_id
                            ]);
                            $message = "Course schedule added successfully!";
                        } catch (PDOException $e) {
                            $error = "Failed to add schedule: " . $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}

// Get data for dashboard
$today = date('Y-m-d');
$current_day = date('N');

$teacher_schedule = [];
$stmt = $pdo->prepare("
    SELECT cs.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
           dp.name as degree_name
    FROM course_schedule cs
    JOIN rooms r ON cs.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
    WHERE cs.teacher_id = ? AND cs.status = 'scheduled'
    ORDER BY cs.day_of_week, cs.start_time
");
$stmt->execute([$teacher_id]);
$teacher_schedule = $stmt->fetchAll();

$todays_classes = [];
foreach ($teacher_schedule as $class) {
    if ($class['day_of_week'] == $current_day)
        $todays_classes[] = $class;
}

$rooms = $pdo->query("
    SELECT r.*, b.name as building_name, f.floor_number
    FROM rooms r
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    ORDER BY b.name, r.room_name
")->fetchAll();

$buildings = $pdo->query("SELECT * FROM buildings ORDER BY name")->fetchAll();
$degree_programs_all = $pdo->query("
    SELECT dp.*, f.name as faculty_name 
    FROM degree_programs dp 
    JOIN faculties f ON dp.faculty_id = f.id 
    ORDER BY f.name, dp.name
")->fetchAll();

$stmt = $pdo->prepare("
    SELECT rb.*, r.room_name, r.room_number, b.name as building_name, dp.name as degree_name
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
    WHERE rb.booked_by = ?
    ORDER BY rb.booking_date DESC, rb.start_time
    LIMIT 50
");
$stmt->execute([$teacher_id]);
$my_bookings = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT cs.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
           dp.name as degree_name, 'schedule' as source_type
    FROM course_schedule cs
    JOIN rooms r ON cs.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
    WHERE cs.teacher_id = ? AND cs.status = 'scheduled'
    ORDER BY cs.day_of_week, cs.start_time
");
$stmt->execute([$teacher_id]);
$course_schedules = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT rb.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
           dp.name as degree_name, 'booking' as source_type,
           DAYOFWEEK(rb.booking_date) as mysql_day
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
    WHERE rb.booked_by = ? AND rb.status = 'booked' AND rb.booking_date >= CURDATE()
    ORDER BY rb.booking_date, rb.start_time
");
$stmt->execute([$teacher_id]);
$booking_schedules = $stmt->fetchAll();

foreach ($booking_schedules as &$booking) {
    $booking['day_of_week'] = ($booking['mysql_day'] + 5) % 7 + 1;
    $booking['course_code'] = 'BOOKED';
    $booking['course_name'] = $booking['purpose'];
}

$my_schedules = array_merge($course_schedules, $booking_schedules);
usort($my_schedules, function ($a, $b) {
    if ($a['day_of_week'] != $b['day_of_week'])
        return $a['day_of_week'] - $b['day_of_week'];
    return strcmp($a['start_time'], $b['start_time']);
});

$notifications = [];
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$teacher_id]);
$notifications = $stmt->fetchAll();

$unread_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$teacher_id]);
$result = $stmt->fetch();
$unread_count = $result['count'];

$stats = [
    'todays_classes' => count($todays_classes),
    'total_schedules' => count($my_schedules),
    'active_bookings' => count(array_filter($my_bookings, function ($b) {
        return $b['status'] == 'booked';
    })),
    'unread_notifications' => $unread_count
];

$today_schedule = [];
foreach ($timeSlots as $ts) {
    $today_schedule[substr($ts['start_time'], 0, 5)] = null;
}
// Wrap recurring classes in typed structure
foreach ($todays_classes as $class) {
    $start = substr($class['start_time'], 0, 5);
    $today_schedule[$start] = ['type' => 'class', 'data' => $class];
}
// Merge one-time bookings for today (Issue 1 fix)
$todayBkStmt = $pdo->prepare("
    SELECT rb.*, r.room_name, r.room_number, b.name as building_name, dp.name as degree_name
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
    WHERE rb.booked_by = ? AND rb.booking_date = ? AND rb.status = 'booked'
    ORDER BY rb.start_time
");
$todayBkStmt->execute([$teacher_id, $today]);
$todays_bookings_page = $todayBkStmt->fetchAll();
foreach ($todays_bookings_page as $bk) {
    $key = substr($bk['start_time'], 0, 5);
    if (!isset($today_schedule[$key]) || $today_schedule[$key] === null) {
        $today_schedule[$key] = ['type' => 'booking', 'data' => $bk];
    }
}

$week_start = date('Y-m-d', strtotime('monday this week'));
$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $week_dates[] = date('Y-m-d', strtotime($week_start . " +$i days"));
}
$week_bookings = [];
$stmt = $pdo->prepare("
    SELECT * FROM room_bookings
    WHERE booked_by = ? AND status = 'booked' AND booking_date BETWEEN ? AND ?
    ORDER BY booking_date, start_time
");
$stmt->execute([$teacher_id, $week_start, date('Y-m-d', strtotime($week_start . ' +6 days'))]);
$week_bookings = $stmt->fetchAll();
$bookings_by_date = [];
foreach ($week_bookings as $b) {
    $bookings_by_date[$b['booking_date']][] = $b;
}

$degree_sem_data = [];
foreach ($degree_programs_all as $dp) {
    $degree_sem_data[$dp['id']] = [
        'total_levels' => $dp['total_levels'],
        'semesters_per_level' => $dp['semesters_per_level'] ?? 2
    ];
}

$purpose_options = [
    'Theory Class',
    'Lab Class',
    'Class Test (Theory)',
    'Class Test (Lab)',
    'Midterm Exam',
    'Final Exam',
    'Make‑up Class',
    'Seminar / Workshop',
    'Meeting',
    'Other'
];

// Generate initial week table for My Week (same logic as in AJAX)
$init_week_grid = [];
for ($d = 0; $d < 7; $d++) {
    $current_date = $week_dates[$d];
    $day_of_week = $d + 1;
    for ($slot_idx = 0; $slot_idx < count($timeSlots); $slot_idx++) {
        $ts = $timeSlots[$slot_idx];
        $entries = [];
        foreach ($course_schedules as $cs) {
            if ($cs['day_of_week'] == $day_of_week && tsOverlaps($ts['start_time'], $ts['end_time'], $cs['start_time'], $cs['end_time'])) {
                $entries[] = ['type' => 'class', 'data' => $cs];
            }
        }
        if (isset($bookings_by_date[$current_date])) {
            foreach ($bookings_by_date[$current_date] as $b) {
                if (tsOverlaps($ts['start_time'], $ts['end_time'], $b['start_time'], $b['end_time'])) {
                    $entries[] = ['type' => 'booking', 'data' => $b];
                }
            }
        }
        $init_week_grid[$d][$slot_idx] = !empty($entries) ? $entries[0] : null;
    }
}
$init_merged_grid = [];
for ($d = 0; $d < 7; $d++) {
    $merged = [];
    $i = 0;
    $slot_count = count($timeSlots);
    while ($i < $slot_count) {
        $current = $init_week_grid[$d][$i];
        if ($current === null) {
            $merged[] = ['type' => 'free', 'span' => 1];
            $i++;
            continue;
        }
        $current_id = $current['type'] == 'class' ? $current['data']['id'] : $current['data']['id'];
        $span = 1;
        $j = $i + 1;
        while ($j < $slot_count) {
            $next = $init_week_grid[$d][$j];
            if (
                $next !== null && $next['type'] == $current['type'] &&
                (($current['type'] == 'class' && $next['data']['id'] == $current_id) ||
                    ($current['type'] == 'booking' && $next['data']['id'] == $current_id))
            ) {
                $span++;
                $j++;
            } else {
                break;
            }
        }
        $merged[] = ['type' => $current['type'], 'data' => $current['data'], 'span' => $span];
        $i = $j;
    }
    $init_merged_grid[$d] = $merged;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - CMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-light: #3b82f6;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --light: #f8fafc;
            --medium-gray: #e2e8f0;
            --text: #334155;
            --text-light: #64748b;
            --white: #ffffff;
            --sidebar-bg: rgba(224, 242, 254, 0.95);
            --sidebar-border: rgba(186, 230, 253, 0.3);
            --sidebar-text: #0c4a6e;
            --sidebar-hover: rgba(186, 230, 253, 0.5);
            --sidebar-active: rgba(14, 165, 233, 0.3);
            --glass-blur: blur(12px);
            --shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--light);
            color: var(--text);
            line-height: 1.6;
        }

        .dashboard-container {
            position: relative;
            min-height: 100vh;
        }

        .page-blur {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.3);
            backdrop-filter: blur(3px);
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .page-blur.active {
            opacity: 1;
            visibility: visible;
        }

        .hamburger-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid var(--medium-gray);
            border-radius: var(--radius);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            gap: 5px;
            cursor: pointer;
            z-index: 1001;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .hamburger-btn:hover {
            background: var(--white);
            transform: translateY(-2px);
        }

        .hamburger-line {
            width: 22px;
            height: 2px;
            background: var(--primary);
            border-radius: 2px;
            transition: var(--transition);
        }

        .sidebar.desktop-sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: var(--sidebar-bg);
            backdrop-filter: var(--glass-blur);
            border-right: 1px solid var(--sidebar-border);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow);
        }

        .sidebar.mobile-sidebar {
            position: fixed;
            top: 90px;
            right: 20px;
            width: 280px;
            background: var(--sidebar-bg);
            backdrop-filter: var(--glass-blur);
            border: 1px solid var(--sidebar-border);
            border-radius: var(--radius);
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: var(--transition);
            box-shadow: 0 8px 32px rgba(14, 165, 233, 0.15);
            max-height: calc(100vh - 110px);
            overflow-y: auto;
        }

        .sidebar.mobile-sidebar.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .nav-menu {
            list-style: none;
            padding: 20px 0;
            flex: 1;
        }

        .nav-item {
            padding: 14px 20px;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 0.95rem;
            color: var(--sidebar-text);
            margin: 0 10px;
            border-radius: var(--radius-sm);
            font-weight: 500;
        }

        .nav-item:hover {
            background: var(--sidebar-hover);
            transform: translateX(5px);
        }

        .nav-item.active {
            background: var(--sidebar-active);
            color: #0c4a6e;
            font-weight: 600;
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 1.1rem;
        }

        .notification-badge {
            background: var(--danger);
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: auto;
            font-weight: 600;
        }

        .logout-btn {
            margin: 20px;
            padding: 14px;
            background: rgba(239, 68, 68, 0.9);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: var(--transition);
            backdrop-filter: var(--glass-blur);
        }

        .logout-btn:hover {
            background: rgba(220, 38, 38, 0.95);
            transform: translateY(-2px);
        }

        .main-content {
            padding: 30px 20px 20px;
            min-height: 100vh;
        }

        .header {
            margin-bottom: 25px;
        }

        .header-content {
            display: flex;
            flex-direction: column;
            gap: 5px;
            margin-bottom: 15px;
        }

        .header-content h1 {
            font-size: 1.5rem;
            color: var(--primary);
            font-weight: 700;
        }

        .header-content p {
            color: var(--text-light);
            font-size: 0.9rem;
        }

        .current-date {
            color: var(--text-light);
            font-size: 0.9rem;
            background: var(--white);
            padding: 8px 15px;
            border-radius: var(--radius-sm);
            display: inline-block;
            box-shadow: var(--shadow);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-primary {
            background: var(--primary);
            color: white;
        }

        .badge-secondary {
            background: var(--medium-gray);
            color: var(--text);
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .badge-success {
            background: var(--success);
            color: white;
        }

        .badge-warning {
            background: var(--warning);
            color: white;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-light);
        }

        .card {
            background: var(--white);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-bottom: 20px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .card-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
        }

        .schedule-card {
            background: var(--white);
            border-radius: var(--radius-sm);
            padding: 15px;
            margin-bottom: 12px;
            border-left: 4px solid var(--primary);
            box-shadow: var(--shadow);
        }

        .schedule-time {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .schedule-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .schedule-meta {
            font-size: 0.85rem;
            color: var(--text-light);
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid var(--medium-gray);
            margin-bottom: 20px;
            overflow-x: auto;
        }

        .tab {
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 600;
            color: var(--text-light);
            border-bottom: 3px solid transparent;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th,
        td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--medium-gray);
        }

        th {
            background: var(--light);
            font-weight: 600;
            font-size: 0.85rem;
            color: var(--text-light);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid var(--medium-gray);
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }

        .notification-item {
            padding: 15px;
            border-bottom: 1px solid var(--medium-gray);
            background: var(--white);
        }

        .notification-item.unread {
            background: #f0f9ff;
            border-left: 4px solid var(--primary);
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .notification-message {
            font-size: 0.9rem;
            color: var(--text);
        }

        .notification-time {
            font-size: 0.8rem;
            color: var(--text-light);
            margin-top: 8px;
        }

        .alert {
            padding: 12px 15px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 0.9rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid var(--danger);
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1100;
            opacity: 0;
            visibility: hidden;
            transition: var(--transition);
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: var(--white);
            border-radius: var(--radius);
            padding: 25px;
            width: 90%;
            max-width: 550px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-light);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        .btn-outline {
            background: transparent;
            border: 2px solid var(--medium-gray);
            color: var(--text);
        }

        .btn-outline.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-outline:hover {
            background: var(--primary-light);
            color: white;
            border-color: var(--primary-light);
        }

        .text-muted {
            color: var(--text-light);
        }

        .sb-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
            background: var(--white);
            padding: 12px 16px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 14px;
        }

        .sb-filter-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .sb-filter-group label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            color: var(--text-light);
            letter-spacing: 0.4px;
        }

        .sb-filter-group select {
            font-family: inherit;
            font-size: 0.85rem;
            border: 1px solid var(--medium-gray);
            border-radius: 8px;
            padding: 6px 10px;
            outline: none;
            background: var(--light);
            cursor: pointer;
        }

        .sb-filter-group select:focus {
            border-color: var(--primary);
        }

        .sb-view-tabs {
            display: flex;
            background: var(--light);
            border-radius: 999px;
            overflow: hidden;
            border: 1px solid var(--medium-gray);
        }

        .sb-view-tab {
            padding: 7px 22px;
            font-size: 0.82rem;
            font-weight: 600;
            background: transparent;
            border: none;
            cursor: pointer;
            color: var(--text-light);
            font-family: inherit;
            transition: all 0.2s;
        }

        .sb-view-tab.active {
            background: var(--primary);
            color: #fff;
        }

        .sb-nav-group {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
        }

        .sb-nav-btn {
            padding: 6px 14px;
            border: 1px solid var(--medium-gray);
            border-radius: 8px;
            background: var(--light);
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            font-family: inherit;
            transition: all 0.2s;
        }

        .sb-nav-btn:hover {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        #sb-date-input {
            font-family: inherit;
            font-size: 0.82rem;
            border: 1px solid var(--medium-gray);
            border-radius: 8px;
            padding: 6px 8px;
            outline: none;
            cursor: pointer;
        }

        .sb-legend {
            display: flex;
            gap: 18px;
            font-size: 0.78rem;
            color: var(--text-light);
            margin-bottom: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .sb-legend-dot {
            font-size: 1rem;
        }

        .sb-avail {
            color: #10b981;
        }

        .sb-class {
            color: #ef4444;
        }

        .sb-booked {
            color: #f59e0b;
        }

        .sb-grid-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .sb-week-table,
        .sb-day-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--white);
            min-width: 700px;
        }

        .sb-week-table th,
        .sb-day-table th {
            background: var(--light);
            padding: 8px 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-align: center;
            border: 1px solid var(--medium-gray);
            white-space: nowrap;
        }

        .sb-room-col {
            min-width: 140px;
            text-align: left !important;
            padding-left: 10px !important;
        }

        .sb-today-col {
            background: rgba(37, 99, 235, 0.08) !important;
        }

        .sb-day-num {
            font-size: 0.9rem;
            font-weight: 800;
        }

        .sb-slot-th {
            min-width: 72px;
            font-size: 0.7rem !important;
        }

        .sb-slot-sub {
            display: block;
            font-size: 0.6rem;
            color: var(--text-light);
            font-weight: 400;
        }

        .sb-room-label {
            padding: 8px 10px;
            border: 1px solid var(--medium-gray);
            font-size: 0.82rem;
            vertical-align: middle;
            min-width: 130px;
        }

        .sb-room-label strong {
            display: block;
            color: var(--text);
            font-size: 0.83rem;
        }

        .sb-room-label small {
            color: var(--text-light);
            font-size: 0.7rem;
        }

        .sb-week-cell {
            border: 1px solid var(--medium-gray);
            padding: 6px 4px;
            cursor: pointer;
            vertical-align: middle;
            transition: all 0.2s;
            text-align: center;
            min-width: 80px;
        }

        .sb-week-cell:hover {
            filter: brightness(0.92);
        }

        .sb-cell-avail {
            background: #ecfdf5;
        }

        .sb-cell-class {
            background: #fef2f2;
        }

        .sb-cell-booked {
            background: #fffbeb;
        }

        .sb-cell-past {
            opacity: 0.55;
            cursor: default;
        }

        .sb-occ-bar {
            display: flex;
            height: 6px;
            border-radius: 3px;
            overflow: hidden;
            margin-bottom: 4px;
            background: var(--medium-gray);
        }

        .sb-bar-class {
            background: #ef4444;
            height: 100%;
        }

        .sb-bar-booked {
            background: #f59e0b;
            height: 100%;
        }

        .sb-bar-avail {
            background: #10b981;
            height: 100%;
        }

        .sb-cell-stats {
            font-size: 0.65rem;
            color: var(--text-light);
        }

        .sb-stat-class {
            color: #ef4444;
            margin-right: 3px;
        }

        .sb-stat-booked {
            color: #f59e0b;
            margin-right: 3px;
        }

        .sb-stat-avail {
            color: #10b981;
        }

        .sb-day-slot {
            border: 1px solid var(--medium-gray);
            padding: 4px 3px;
            text-align: center;
            vertical-align: middle;
            transition: all 0.15s;
            cursor: default;
        }

        .sb-slot-avail {
            background: #ecfdf5;
        }

        .sb-slot-partial {
            background: #f0fdf4;
            border-left: 3px solid #10b981;
        }

        .sb-slot-class {
            background: #fef2f2;
        }

        .sb-slot-booked {
            background: #fffbeb;
        }

        .sb-slot-current {
            background: rgba(37, 99, 235, 0.05);
        }

        .sb-bookable {
            cursor: pointer;
        }

        .sb-bookable:hover {
            filter: brightness(0.90);
            transform: scale(1.03);
            z-index: 1;
            position: relative;
        }

        .sb-slot-past {
            opacity: 0.55;
            cursor: default;
        }

        .sb-slot-label {
            font-size: 0.65rem;
            font-weight: 600;
            color: var(--text-light);
            line-height: 1.2;
            display: block;
        }

        .sb-class-label {
            color: #b91c1c;
        }

        .sb-booked-label {
            color: #92400e;
        }

        .sb-partial-label {
            color: #065f46;
        }

        .sb-day-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 10px;
        }

        .sb-week-actions {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 10px;
        }

        .sb-past-badge {
            font-size: 0.72rem;
            background: #fef3c7;
            color: #92400e;
            padding: 2px 10px;
            border-radius: 999px;
            font-weight: 600;
        }

        .sb-detail-row {
            display: flex;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 0.88rem;
        }

        .sb-detail-label {
            min-width: 110px;
            font-weight: 600;
            color: var(--text-light);
            font-size: 0.8rem;
        }

        .sb-detail-value {
            color: var(--text);
            flex: 1;
        }

        .schedule-today-grid {
            display: grid;
            grid-template-columns: 120px 1fr;
            gap: 8px;
            margin-top: 10px;
        }

        .schedule-time-slot {
            font-weight: 600;
            padding: 8px;
            background: var(--light);
            border-radius: 4px;
            text-align: right;
        }

        .schedule-class-detail {
            padding: 8px;
            background: var(--white);
            border-radius: 4px;
            border: 1px solid var(--medium-gray);
            cursor: pointer;
        }

        .day-time-row {
            display: flex;
            gap: 8px;
            margin-bottom: 8px;
            align-items: center;
        }

        .day-time-row select,
        .day-time-row input {
            flex: 1;
        }

        .suggestions-box {
            background: #f0fdf4;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
        }

        .suggestion-item {
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            display: inline-block;
            margin: 2px;
            background: white;
            border: 1px solid #d1fae5;
        }

        .suggestion-item:hover {
            background: #d1fae5;
        }

        .sb-header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-left: auto;
        }

        .btn-add-schedule {
            background: var(--primary);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.2s;
        }

        .btn-add-schedule:hover {
            background: var(--primary-light);
        }

        .my-week-table-wrapper {
            overflow-x: auto;
        }

        .my-week-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .my-week-table th,
        .my-week-table td {
            border: 1px solid var(--medium-gray);
            padding: 5px;
            vertical-align: top;
        }

        .my-week-table th {
            background: var(--light);
            font-weight: bold;
            text-align: center;
        }

        .my-week-time {
            background: var(--light);
            font-weight: bold;
            text-align: center;
            width: 80px;
        }

        .my-week-cell {
            background: var(--white);
        }

        .my-week-cell.free {
            background: #ecfdf5;
        }

        .my-week-cell.class {
            background: #fef2f2;
        }

        .my-week-cell.booking {
            background: #fffbeb;
        }

        .my-week-class,
        .my-week-booking {
            font-size: 0.7rem;
            padding: 4px;
            border-radius: 4px;
        }

        .my-week-class {
            background: #fee2e2;
            border-left: 3px solid #ef4444;
        }

        .my-week-booking {
            background: #fef9c3;
            border-left: 3px solid #f59e0b;
        }

        /* Degree label inside week cells */
        .mw-degree {
            color: var(--text-light);
            font-size: 0.65rem;
            display: block;
            line-height: 1.2;
        }

        /* Slot meta (teacher/booker name) in day-view cells */
        .sb-slot-meta {
            color: inherit;
            opacity: 0.75;
            display: block;
            font-size: 0.7em;
            margin-top: 2px;
        }

        /* Today grid state classes */
        .schedule-class-detail.has-schedule {
            border-left: 3px solid var(--primary);
            background: rgba(37, 99, 235, 0.06);
            border-radius: 6px;
            padding: 6px 10px;
        }

        .schedule-class-detail.has-booking {
            border-left: 3px solid var(--warning);
            background: rgba(245, 158, 11, 0.07);
            border-radius: 6px;
            padding: 6px 10px;
        }

        /* Multi-line content in day-view slot cells */
        .sb-slot-label.sb-class-label,
        .sb-slot-label.sb-booked-label {
            display: flex;
            flex-direction: column;
            gap: 2px;
            white-space: normal;
            line-height: 1.3;
        }

        .purpose-custom-field.hidden {
            display: none;
        }

        @keyframes glowPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0.4);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(37, 99, 235, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(37, 99, 235, 0);
            }
        }

        .glow-effect {
            animation: glowPulse 0.6s ease-out;
        }

        @media (min-width:768px) {
            .main-content {
                padding: 40px;
            }

            .header-content h1 {
                font-size: 1.8rem;
            }

            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }

            .form-row {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }

        @media (min-width:1024px) {

            .mobile-sidebar,
            .hamburger-btn,
            .page-blur {
                display: none !important;
            }

            .desktop-sidebar {
                display: flex !important;
            }

            .main-content {
                margin-left: 280px;
                padding: 40px;
            }
        }

        @media (max-width:1023px) {
            .desktop-sidebar {
                display: none !important;
            }

            .hamburger-btn {
                display: flex;
            }

            .header-content h1 {
                font-size: 1.3rem;
                max-width: calc(100% - 70px);
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <div class="page-blur" id="pageBlur"></div>
        <button class="hamburger-btn" id="hamburgerBtn">
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
        </button>

        <aside class="sidebar desktop-sidebar">
            <ul class="nav-menu">
                <li class="nav-item active" onclick="showSection('dashboard', event)"><i
                        class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="showSection('schedule', event)"><i class="fas fa-calendar-alt"></i> My
                    Schedule</li>
                <li class="nav-item" onclick="showSection('status-booking', event)"><i
                        class="fas fa-calendar-check"></i> Status & Booking</li>
                <li class="nav-item" onclick="showSection('notifications', event)"><i class="fas fa-bell"></i>
                    Notifications <?php if ($unread_count > 0): ?><span
                            class="notification-badge"><?= $unread_count ?></span><?php endif; ?></li>
            </ul>
            <button class="logout-btn" onclick="window.location.href='logout.php'"><i class="fas fa-sign-out-alt"></i>
                Logout</button>
        </aside>

        <aside class="sidebar mobile-sidebar" id="mobileSidebar">
            <ul class="nav-menu">
                <li class="nav-item active" onclick="showSection('dashboard', event)"><i
                        class="fas fa-tachometer-alt"></i> Dashboard</li>
                <li class="nav-item" onclick="showSection('schedule', event)"><i class="fas fa-calendar-alt"></i> My
                    Schedule</li>
                <li class="nav-item" onclick="showSection('status-booking', event)"><i
                        class="fas fa-calendar-check"></i> Status & Booking</li>
                <li class="nav-item" onclick="showSection('notifications', event)"><i class="fas fa-bell"></i>
                    Notifications <?php if ($unread_count > 0): ?><span
                            class="notification-badge"><?= $unread_count ?></span><?php endif; ?></li>
            </ul>
            <button class="logout-btn" onclick="window.location.href='logout.php'"><i class="fas fa-sign-out-alt"></i>
                Logout</button>
        </aside>

        <main class="main-content" id="mainContent">
            <div class="header">
                <div class="header-content">
                    <h1><?= htmlspecialchars($teacher_name_short) ?>'s Dashboard</h1>
                    <p>Manage classes, bookings and schedules</p>
                </div>
                <div class="current-date"><i class="far fa-calendar-alt"></i> <?= date('l, F j, Y') ?></div>
            </div>

            <?php if ($message): ?>
                <div class="alert alert-success"><?= $message ?></div><?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= $error ?></div><?php endif; ?>

            <!-- Dashboard Section -->
            <section id="dashboard" class="section">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['todays_classes'] ?></div>
                        <div class="stat-label">Classes Today</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['total_schedules'] ?></div>
                        <div class="stat-label">Course Schedules</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['active_bookings'] ?></div>
                        <div class="stat-label">Active Bookings</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['unread_notifications'] ?></div>
                        <div class="stat-label">Notifications</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Today's Classes</h3><button class="btn btn-outline btn-sm"
                            onclick="showSection('schedule', event)">View Full Schedule</button>
                    </div>
                    <?php if (empty($todays_classes)): ?>
                        <div class="empty-state">
                            <h3>No classes today</h3>
                        </div>
                    <?php else:
                        foreach ($todays_classes as $class): ?>
                            <div class="schedule-card">
                                <div class="schedule-time"><?= formatTime($class['start_time']) ?> -
                                    <?= formatTime($class['end_time']) ?>
                                </div>
                                <div class="schedule-title"><?= htmlspecialchars($class['course_name']) ?> <span
                                        class="badge badge-secondary"><?= htmlspecialchars($class['course_code']) ?></span>
                                </div>
                                <div class="schedule-meta"><span>📍
                                        <?= htmlspecialchars($class['building_name'] . ' - ' . $class['room_name']) ?></span><span>👥
                                        <?= htmlspecialchars($class['degree_name'] ?? '') ?> - Level
                                        <?= $class['level'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                </div>
            </section>

            <!-- My Schedule Section -->
            <section id="schedule" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">My Schedule</h3>
                    </div>
                    <div class="tabs">
                        <div class="tab active" onclick="showScheduleView('today')">Today</div>
                        <div class="tab" onclick="showScheduleView('week')">My Week</div>
                        <div class="tab" onclick="showScheduleView('bookings')">My Bookings</div>
                    </div>

                    <!-- Today Grid -->
                    <div id="schedule-today">
                        <div class="schedule-today-grid">
                            <?php foreach ($timeSlots as $ts):
                                $key = substr($ts['start_time'], 0, 5);
                                $item = $today_schedule[$key] ?? null;
                                $itemType = $item['type'] ?? null;
                                $itemData = $item['data'] ?? null; ?>
                                <div class="schedule-time-slot"><?= date('g:i A', strtotime($ts['start_time'])) ?></div>
                                <div class="schedule-class-detail <?= $item ? ($itemType == 'class' ? 'has-schedule' : 'has-booking') : '' ?>"
                                    <?php if ($item): ?> data-schedule-type="<?= $itemType ?>"
                                        data-schedule-id="<?= $itemData['id'] ?>"
                                        data-course-code="<?= htmlspecialchars($itemType == 'class' ? $itemData['course_code'] : 'BOOKED') ?>"
                                        data-date="<?= $today ?>" <?php endif; ?>>
                                    <?php if ($item && $itemType == 'class'): ?>
                                        <span class="text-muted"
                                            style="font-size:0.8em"><?= htmlspecialchars(($itemData['degree_name'] ?? '') . ' L' . $itemData['level'] . ' S' . $itemData['semester']) ?></span><br>
                                        <strong><?= htmlspecialchars($itemData['course_name']) ?></strong><br>
                                        <small><?= htmlspecialchars($itemData['room_name'] . ', ' . $itemData['building_name']) ?></small>
                                    <?php elseif ($item && $itemType == 'booking'): ?>
                                        <span class="text-muted"
                                            style="font-size:0.8em"><?= htmlspecialchars(($itemData['degree_name'] ?? '') . ' L' . ($itemData['level'] ?? '') . ' S' . ($itemData['semester'] ?? '')) ?></span><br>
                                        <strong>📅 <?= htmlspecialchars($itemData['purpose']) ?></strong><br>
                                        <small><?= htmlspecialchars($itemData['room_name'] . ', ' . $itemData['building_name']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">No class</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- My Week Calendar - Table Version -->
                    <div id="schedule-week" style="display: none;">
                        <div class="my-week-table-wrapper">
                            <table class="my-week-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <?php for ($d = 0; $d < 7; $d++):
                                            $short_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                                            <th><?= $short_names[$d] ?><br><small><?= date('M j', strtotime($week_dates[$d])) ?></small>
                                            </th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($slot_idx = 0; $slot_idx < count($timeSlots); $slot_idx++):
                                        $ts = $timeSlots[$slot_idx];
                                        $row_printed = false;
                                        for ($d = 0; $d < 7; $d++) {
                                            $cumulative = 0;
                                            $start_of_span = false;
                                            foreach ($init_merged_grid[$d] as $entry) {
                                                if ($slot_idx >= $cumulative && $slot_idx < $cumulative + $entry['span']) {
                                                    if ($slot_idx == $cumulative)
                                                        $start_of_span = true;
                                                    break;
                                                }
                                                $cumulative += $entry['span'];
                                            }
                                            if ($start_of_span)
                                                $row_printed = true;
                                        }
                                        if (!$row_printed)
                                            continue;
                                        ?>
                                        <tr>
                                            <td class="my-week-time"><?= date('g:i', strtotime($ts['start_time'])) ?> –
                                                <?= date('g:i', strtotime($ts['end_time'])) ?>
                                            </td>
                                            <?php for ($d = 0; $d < 7; $d++):
                                                $cumulative = 0;
                                                $entry = null;
                                                foreach ($init_merged_grid[$d] as $e) {
                                                    if ($slot_idx >= $cumulative && $slot_idx < $cumulative + $e['span']) {
                                                        $entry = $e;
                                                        break;
                                                    }
                                                    $cumulative += $e['span'];
                                                }
                                                if (!$entry || $slot_idx != $cumulative)
                                                    continue;
                                                $span = $entry['span'];
                                                $type = $entry['type'];
                                                if ($type == 'free') {
                                                    echo '<td class="my-week-cell free"> </td>';
                                                } elseif ($type == 'class') {
                                                    $c = $entry['data'];
                                                    $degLabel = htmlspecialchars(($c['degree_name'] ?? '') . ' L' . $c['level'] . ' S' . $c['semester']);
                                                    echo '<td class="my-week-cell class" rowspan="' . $span . '" data-schedule-type="class" data-schedule-id="' . $c['id'] . '" data-course-code="' . htmlspecialchars($c['course_code']) . '" data-date="' . $week_dates[$d] . '">
                                                        <div class="my-week-class"><small class="mw-degree">' . $degLabel . '</small><br>' . htmlspecialchars($c['course_name']) . '<br><small>' . htmlspecialchars($c['room_name'] . ', ' . $c['building_name']) . '</small></div>
                                                       </td>';
                                                } else { // booking
                                                    $b = $entry['data'];
                                                    $degLabel = htmlspecialchars(($b['degree_name'] ?? '') . ' L' . ($b['level'] ?? '') . ' S' . ($b['semester'] ?? ''));
                                                    echo '<td class="my-week-cell booking" rowspan="' . $span . '" data-booking-id="' . $b['id'] . '">
                                                        <div class="my-week-booking"><small class="mw-degree">' . $degLabel . '</small><br>' . htmlspecialchars($b['purpose']) . '<br><small>' . htmlspecialchars($b['room_name'] ?? 'Room') . '</small></div>
                                                       </td>';
                                                }
                                            endfor; ?>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- My Bookings Tab -->
                    <div id="schedule-bookings" style="display: none;">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Room</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                        <th>Purpose</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($my_bookings as $booking): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($booking['room_name']) ?></strong><br><small
                                                    class="text-muted"><?= htmlspecialchars($booking['building_name']) ?></small>
                                            </td>
                                            <td><?= date('M d, Y', strtotime($booking['booking_date'])) ?></td>
                                            <td><?= formatTime($booking['start_time']) ?> -
                                                <?= formatTime($booking['end_time']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($booking['purpose']) ?></td>
                                            <td><span
                                                    class="badge badge-<?= $booking['status'] == 'booked' ? 'success' : ($booking['status'] == 'cancelled' ? 'warning' : 'secondary') ?>"><?= ucfirst($booking['status']) ?></span>
                                            </td>
                                            <td>
                                                <?php if ($booking['status'] == 'booked' && $booking['booking_date'] >= date('Y-m-d')): ?>
                                                    <button class="btn btn-sm btn-danger"
                                                        onclick="freeRoomModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['room_name']) ?>')">Free</button>
                                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Status & Booking Section -->
            <section id="status-booking" class="section" style="display:none;">
                <div class="sb-toolbar">
                    <div class="sb-filter-group">
                        <label>Degree</label>
                        <select id="sb-degree-filter"
                            onchange="sbUpdateUrl({sb_degree: this.value, sb_faculty: '', sb_building: ''})">
                            <option value="">All Degrees</option>
                            <?php foreach ($degree_programs as $dp): ?>
                                <option value="<?= $dp['id'] ?>" <?= $sb_degree_filter == $dp['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Faculty</label>
                        <select id="sb-faculty"
                            onchange="sbUpdateUrl({sb_faculty: this.value, sb_degree: '', sb_building: ''})">
                            <option value="">All Faculties</option>
                            <?php foreach ($allFaculties as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $sb_faculty_filter == $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label>Building</label>
                        <select id="sb-building"
                            onchange="sbUpdateUrl({sb_building: this.value, sb_faculty: '', sb_degree: ''})">
                            <option value="">All Buildings</option>
                            <?php foreach ($sb_buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" data-faculty-id="<?= $b['faculty_id'] ?>"
                                    <?= $sb_building_filter == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sb-view-tabs">
                        <button class="sb-view-tab <?= $sb_view == 'week' ? 'active' : '' ?>"
                            onclick="sbUpdateUrl({view:'week'})">Week</button>
                        <button class="sb-view-tab <?= $sb_view == 'day' ? 'active' : '' ?>"
                            onclick="sbUpdateUrl({view:'day'})">Day</button>
                    </div>
                    <div class="sb-nav-group">
                        <button class="sb-nav-btn" id="sb-btn-prev">‹ Prev</button>
                        <button class="sb-nav-btn" id="sb-btn-today">Today</button>
                        <input type="date" id="sb-date-input" value="<?= htmlspecialchars($sb_date) ?>"
                            min="<?= date('Y-m-d') ?>" onchange="sbUpdateUrl({date: this.value})">
                        <button class="sb-nav-btn" id="sb-btn-next">Next ›</button>
                    </div>
                </div>
                <div class="sb-legend">
                    <span class="sb-legend-dot sb-avail">●</span> Available (click to book)
                    <span class="sb-legend-dot sb-class">●</span> Class
                    <span class="sb-legend-dot sb-booked">●</span> Booked
                </div>

                <?php if ($sb_view === 'week'): ?>
                    <div class="sb-week-actions">
                        <button class="btn-add-schedule" id="sbAddScheduleBtn"><i class="fas fa-plus"></i> Book for
                            Semester</button>
                    </div>
                    <div class="sb-week-wrapper">
                        <div class="sb-grid-scroll">
                            <table class="sb-week-table">
                                <thead>
                                    <tr>
                                        <th class="sb-room-col">Room</th><?php foreach ($sb_weekDays as $wd): ?>
                                            <th class="<?= $wd['is_today'] ? 'sb-today-col' : '' ?>">
                                                <?= $wd['day_name'] ?><br><span
                                                    class="sb-day-num"><?= $wd['day_number'] ?></span></th><?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sb_rooms as $room): ?>
                                        <tr>
                                            <td class="sb-room-label">
                                                <strong><?= htmlspecialchars($room['room_name']) ?></strong><br><small><?= htmlspecialchars($room['building_name']) ?></small>
                                            </td>
                                            <?php foreach ($sb_weekDays as $idx => $wd):
                                                $dayStatus = $sb_weekStatuses[$wd['date']][$room['id']] ?? null;
                                                $hasClass = false;
                                                $hasBooked = false;
                                                $dayClasses = [];
                                                if ($dayStatus) {
                                                    foreach ($dayStatus['slots'] as $sl) {
                                                        if ($sl['status'] === 'class') {
                                                            $hasClass = true;
                                                            $dayClasses[] = $sl['data'];
                                                        }
                                                        if ($sl['status'] === 'booked')
                                                            $hasBooked = true;
                                                    }
                                                }
                                                $cellClass = $hasClass ? 'sb-cell-class' : ($hasBooked ? 'sb-cell-booked' : 'sb-cell-avail');
                                                $isPast = $wd['date'] < date('Y-m-d');
                                                $classesJson = htmlspecialchars(json_encode($dayClasses), ENT_QUOTES);
                                                $totalSlots = count($timeSlots);
                                                $classCount = 0;
                                                $bookedCount = 0;
                                                $availCount = 0;
                                                if ($dayStatus) {
                                                    foreach ($dayStatus['slots'] as $sl) {
                                                        if ($sl['status'] === 'class')
                                                            $classCount++;
                                                        elseif ($sl['status'] === 'booked')
                                                            $bookedCount++;
                                                        else
                                                            $availCount++;
                                                    }
                                                } else {
                                                    $availCount = $totalSlots;
                                                }
                                                $pClass = $totalSlots > 0 ? round($classCount / $totalSlots * 100) : 0;
                                                $pBooked = $totalSlots > 0 ? round($bookedCount / $totalSlots * 100) : 0;
                                                $pAvail = 100 - $pClass - $pBooked;
                                                ?>
                                                <td class="sb-week-cell <?= $cellClass ?> <?= $isPast ? 'sb-cell-past' : '' ?> <?= $wd['is_today'] ? 'sb-today-col' : '' ?>"
                                                    data-date="<?= $wd['date'] ?>" data-room-id="<?= $room['id'] ?>"
                                                    data-room-name="<?= htmlspecialchars($room['room_name']) ?>"
                                                    data-building="<?= htmlspecialchars($room['building_name']) ?>"
                                                    data-classes="<?= $classesJson ?>">
                                                    <div class="sb-occ-bar">
                                                        <?php if ($pClass > 0): ?>
                                                            <div class="sb-bar-class" style="width:<?= $pClass ?>%"></div>
                                                        <?php endif; ?>
                                                        <?php if ($pBooked > 0): ?>
                                                            <div class="sb-bar-booked" style="width:<?= $pBooked ?>%"></div>
                                                        <?php endif; ?>
                                                        <?php if ($pAvail > 0): ?>
                                                            <div class="sb-bar-avail" style="width:<?= $pAvail ?>%"></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="sb-cell-stats">
                                                        <?php if ($classCount > 0): ?><span
                                                                class="sb-stat-class"><?= $classCount ?>c</span><?php endif; ?>
                                                        <?php if ($bookedCount > 0): ?><span
                                                                class="sb-stat-booked"><?= $bookedCount ?>b</span><?php endif; ?>
                                                        <?php if ($availCount > 0): ?><span
                                                                class="sb-stat-avail"><?= $availCount ?>f</span><?php endif; ?>
                                                    </div>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div id="sb-day-content">
                        <div class="sb-day-heading">
                            <span><?= date('l, F j, Y', strtotime($sb_date)) ?><?php if ($sb_date < date('Y-m-d')): ?> <span
                                        class="sb-past-badge">Past Date — Read Only</span><?php endif; ?></span>
                            <button class="btn-add-schedule" id="sbAddScheduleBtn"><i class="fas fa-plus"></i> Book for
                                Semester</button>
                        </div>
                        <div class="sb-grid-scroll">
                            <table class="sb-day-table">
                                <thead>
                                    <tr>
                                        <th class="sb-room-col">Room</th><?php foreach ($timeSlots as $ts): ?>
                                            <th class="sb-slot-th"><?= date('g:i', strtotime($ts['start_time'])) ?><span
                                                    class="sb-slot-sub"><?= htmlspecialchars($ts['slot_name']) ?></span></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php $dayStatuses = $sb_weekStatuses[$sb_date] ?? [];
                                    foreach ($sb_rooms as $room):
                                        $rs = $dayStatuses[$room['id']] ?? null; ?>
                                        <tr data-free-blocks='<?= json_encode($sb_dayFreeBlocks[$room['id']] ?? []) ?>'>
                                            <td class="sb-room-label">
                                                <strong><?= htmlspecialchars($room['room_name']) ?></strong><br><small><?= htmlspecialchars($room['building_name']) ?></small>
                                            </td>
                                            <?php
                                            $slotOccupancy = [];
                                            foreach ($timeSlots as $idx => $ts) {
                                                $slotInfo = $rs ? ($rs['slots'][$ts['id']] ?? null) : null;
                                                $status = $slotInfo ? $slotInfo['status'] : 'available';
                                                $data = $slotInfo ? $slotInfo['data'] : null;
                                                $slotOccupancy[$idx] = ['status' => $status, 'data' => $data, 'ts' => $ts];
                                            }
                                            $mergedSlots = [];
                                            $i = 0;
                                            while ($i < count($timeSlots)) {
                                                $current = $slotOccupancy[$i];
                                                $span = 1;
                                                $j = $i + 1;
                                                if (in_array($current['status'], ['class', 'booked'])) {
                                                    $currentId = $current['data']['id'] ?? null;
                                                    while ($j < count($timeSlots)) {
                                                        $next = $slotOccupancy[$j];
                                                        if ($next['status'] === $current['status'] && isset($next['data']['id']) && $next['data']['id'] == $currentId) {
                                                            $span++;
                                                            $j++;
                                                        } else {
                                                            break;
                                                        }
                                                    }
                                                }
                                                $mergedSlots[] = ['span' => $span, 'data' => $current];
                                                $i = $j;
                                            }

                                            foreach ($mergedSlots as $m):
                                                $slotData = $m['data'];
                                                $status = $slotData['status'];
                                                $data = $slotData['data'];
                                                $ts = $slotData['ts'];
                                                $span = $m['span'];
                                                $isPast = $sb_date < date('Y-m-d');
                                                $isPastTime = ($sb_date === date('Y-m-d') && $ts['start_time'] < date('H:i:s'));
                                                $isCurrent = $sb_date === date('Y-m-d') && date('H:i:s') >= $ts['start_time'] && date('H:i:s') <= $ts['end_time'];
                                                $freeStart = $ts['start_time'];
                                                $freeEnd = $ts['end_time'];
                                                if ($status === 'class' || $status === 'booked') {
                                                    if ($rs) {
                                                        $occupiedRanges = [];
                                                        foreach ($rs['bookings'] as $bk)
                                                            if (tsOverlaps($ts['start_time'], $ts['end_time'], $bk['start_time'], $bk['end_time']))
                                                                $occupiedRanges[] = [$bk['start_time'], $bk['end_time']];
                                                        foreach ($rs['classes'] as $cl)
                                                            if (tsOverlaps($ts['start_time'], $ts['end_time'], $cl['start_time'], $cl['end_time']))
                                                                $occupiedRanges[] = [$cl['start_time'], $cl['end_time']];
                                                        if (!empty($occupiedRanges)) {
                                                            usort($occupiedRanges, fn($a, $b) => strcmp($a[0], $b[0]));
                                                            $firstOccStart = $occupiedRanges[0][0];
                                                            $lastOccEnd = end($occupiedRanges)[1];
                                                            $gapBefore = strtotime($firstOccStart) - strtotime($ts['start_time']);
                                                            $gapAfter = strtotime($ts['end_time']) - strtotime($lastOccEnd);
                                                            if ($gapBefore > 0 || $gapAfter > 0) {
                                                                if ($gapBefore >= $gapAfter) {
                                                                    $freeStart = $ts['start_time'];
                                                                    $freeEnd = $firstOccStart;
                                                                } else {
                                                                    $freeStart = $lastOccEnd;
                                                                    $freeEnd = $ts['end_time'];
                                                                }
                                                                $status = 'partial';
                                                            }
                                                        }
                                                    }
                                                }
                                                $cellClass = match ($status) {
                                                    'available' => 'sb-slot-avail', 'partial' => 'sb-slot-partial', 'class' => 'sb-slot-class', 'booked' => 'sb-slot-booked', default => 'sb-slot-avail'
                                                };
                                                $isBookable = ($status === 'available' || $status === 'partial') && !$isPast && !$isPastTime;
                                                $dataJson = htmlspecialchars(json_encode($data), ENT_QUOTES);
                                                ?>
                                                <td class="sb-day-slot <?= $cellClass ?> <?= $isBookable ? 'sb-bookable' : '' ?> <?= $isCurrent ? 'sb-slot-current' : '' ?> <?= ($isPast || $isPastTime) ? 'sb-slot-past' : '' ?>"
                                                    colspan="<?= $span ?>" data-room-id="<?= $room['id'] ?>"
                                                    data-room-name="<?= htmlspecialchars($room['room_name']) ?>"
                                                    data-room-faculty-id="<?= $room['faculty_id'] ?? '' ?>"
                                                    data-building="<?= htmlspecialchars($room['building_name']) ?>"
                                                    data-date="<?= $sb_date ?>" data-slot-start="<?= $ts['start_time'] ?>"
                                                    data-slot-end="<?= $ts['end_time'] ?>" data-free-start="<?= $freeStart ?>"
                                                    data-free-end="<?= $freeEnd ?>" data-status="<?= $status ?>"
                                                    data-detail="<?= $dataJson ?>">
                                                    <?php if ($status === 'available' && !$isPast && !$isPastTime): ?><span
                                                            class="sb-slot-label">Free</span>
                                                    <?php elseif ($status === 'partial' && !$isPast && !$isPastTime): ?><span
                                                            class="sb-slot-label sb-partial-label">Partial<br><small><?= date('g:i', strtotime($freeStart)) ?>–<?= date('g:i', strtotime($freeEnd)) ?></small></span>
                                                    <?php elseif ($status === 'class'): ?><span
                                                            class="sb-slot-label sb-class-label"><small><?= htmlspecialchars(($data['degree_name'] ?? '') . ' L' . ($data['level'] ?? '') . ' S' . ($data['semester'] ?? '')) ?></small><br><?= htmlspecialchars($data['course_name'] ?? $data['course_code'] ?? 'Class') ?><br><small
                                                                class="sb-slot-meta"><?= htmlspecialchars($data['teacher_name'] ?? '') ?></small></span>
                                                    <?php elseif ($status === 'booked'): ?><span
                                                            class="sb-slot-label sb-booked-label"><small><?= htmlspecialchars(($data['degree_name'] ?? '') . ' L' . ($data['level'] ?? '') . ' S' . ($data['semester'] ?? '')) ?></small><br><?= htmlspecialchars($data['purpose'] ?? 'Booked') ?><br><small
                                                                class="sb-slot-meta"><?= htmlspecialchars($data['booked_by'] ?? '') ?></small></span>
                                                    <?php else: ?><span class="sb-slot-label">Past</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div><!-- end #sb-day-content -->
                <?php endif; ?>
            </section>

            <!-- Notifications Section -->
            <section id="notifications" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Notifications</h3>
                    </div>
                    <?php if (empty($notifications)): ?>
                        <div class="empty-state">
                            <h3>No notifications</h3>
                        </div>
                    <?php else:
                        foreach ($notifications as $notif): ?>
                            <div class="notification-item <?= $notif['is_read'] ? '' : 'unread' ?>">
                                <div class="notification-title"><?= htmlspecialchars($notif['title']) ?></div>
                                <div class="notification-message"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="notification-time"><?= date('M d, Y g:i A', strtotime($notif['created_at'])) ?>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- Free Room Modal -->
    <div class="modal-overlay" id="free-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Free Room</h3><button class="modal-close" onclick="closeFreeModal()">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="free_room">
                <input type="hidden" name="booking_id" id="free-booking-id">
                <p>Are you sure you want to free <strong id="free-room-name"></strong>?</p>
                <p class="text-muted">The CR will be notified about this cancellation.</p>
                <div class="form-group"><label class="form-label">Reason (Optional)</label><input type="text"
                        name="reason" class="form-control" placeholder="e.g., Class cancelled"></div>
                <div style="display:flex; gap:12px; justify-content:space-between;">
                    <button type="button" class="btn btn-outline" onclick="closeFreeModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Free Room</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Booking Modal -->
    <div class="modal-overlay" id="sb-book-modal">
        <div class="modal" style="max-width:550px;">
            <div class="modal-header">
                <h3 class="modal-title" id="sb-modal-title">Book Room</h3><button class="modal-close"
                    onclick="sbCloseBookModal()">×</button>
            </div>
            <div id="sb-modal-alert" style="display:none;padding:10px 20px;">
                <div class="alert alert-danger" id="sb-modal-alert-msg"></div>
            </div>
            <div id="sb-modal-success" style="display:none;padding:10px 20px;">
                <div class="alert alert-success" id="sb-modal-success-msg"></div>
            </div>
            <div id="sb-modal-form-wrap" style="padding:0 20px 20px;">
                <div class="form-group"><label class="form-label">Room</label><input type="text" id="sb-display-room"
                        class="form-control" readonly></div>
                <div class="form-group"><label class="form-label">Date</label><input type="text" id="sb-display-date"
                        class="form-control" readonly></div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Start Time</label><input type="time"
                            id="sb-start-time" class="form-control" step="300" required></div>
                    <div class="form-group"><label class="form-label">End Time</label><input type="time"
                            id="sb-end-time" class="form-control" step="300" required></div>
                </div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Degree Program *</label>
                        <select id="sb-degree-program" class="form-control" required onchange="sbLoadLevelSemesters()">
                            <option value="">Select Program</option>
                            <?php foreach ($degree_programs as $dp): ?>
                                <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Level-Semester *</label>
                        <select id="sb-level-sem" class="form-control" required onchange="sbLoadCourses()">
                            <option value="">Select Degree first</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Course *</label>
                    <select id="sb-course" class="form-control" required>
                        <option value="">Select Level-Semester first</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Group <span
                            style="color:var(--text-light);font-size:0.8em;">(optional)</span></label>
                    <select id="sb-group" class="form-control">
                        <option value="">All Groups</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Purpose *</label>
                    <select id="sb-purpose" class="form-control" required>
                        <option value="">-- Select purpose --</option>
                        <?php foreach ($purpose_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="sb-purpose-custom-wrap" class="purpose-custom-field hidden"><input type="text"
                            id="sb-purpose-custom" class="form-control" placeholder="Enter custom purpose..."></div>
                </div>
                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
                    <button type="button" class="btn btn-outline" onclick="sbCloseBookModal()">Cancel</button>
                    <button type="button" class="btn btn-primary" id="sb-submit-btn" onclick="sbSubmitBooking()"><span
                            id="sb-submit-text">Book Room</span></button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Booking Modal -->
    <div class="modal-overlay" id="edit-booking-modal">
        <div class="modal" style="max-width:550px;">
            <div class="modal-header">
                <h3 class="modal-title">Edit Booking</h3><button class="modal-close"
                    onclick="closeEditBookingModal()">×</button>
            </div>
            <div id="edit-booking-alert" style="display:none;padding:10px 20px;">
                <div class="alert alert-danger" id="edit-booking-alert-msg"></div>
            </div>
            <form id="edit-booking-form">
                <input type="hidden" id="edit-booking-id">
                <div class="form-group"><label class="form-label">Room</label><input type="text" id="edit-booking-room"
                        class="form-control" readonly></div>
                <div class="form-group"><label class="form-label">Date</label><input type="text" id="edit-booking-date"
                        class="form-control" readonly></div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Start Time</label><input type="time"
                            id="edit-booking-start" class="form-control" step="300" required></div>
                    <div class="form-group"><label class="form-label">End Time</label><input type="time"
                            id="edit-booking-end" class="form-control" step="300" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label class="form-label">Degree Program *</label>
                        <select id="edit-booking-degree" class="form-control" required
                            onchange="loadEditLevelSemesters()">
                            <option value="">Select Program</option>
                            <?php foreach ($degree_programs as $dp): ?>
                                <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label class="form-label">Level-Semester *</label>
                        <select id="edit-booking-level-sem" class="form-control" required onchange="loadEditCourses()">
                            <option value="">Select Degree first</option>
                        </select>
                    </div>
                </div>
                <div class="form-group"><label class="form-label">Course *</label>
                    <select id="edit-booking-course" class="form-control" required>
                        <option value="">Select Level-Semester first</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Group</label>
                    <select id="edit-booking-group" class="form-control">
                        <option value="">All Groups</option>
                    </select>
                </div>
                <div class="form-group"><label class="form-label">Purpose *</label>
                    <select id="edit-booking-purpose-select" class="form-control" required>
                        <option value="">-- Select purpose --</option>
                        <?php foreach ($purpose_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit-booking-purpose-custom-wrap" class="purpose-custom-field hidden"><input type="text"
                            id="edit-booking-purpose-custom" class="form-control" placeholder="Enter custom purpose...">
                    </div>
                    <input type="hidden" id="edit-booking-purpose" name="purpose">
                </div>
                <div id="edit-booking-conflict-suggestions" style="margin-top:10px;"></div>
                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
                    <button type="button" class="btn btn-outline" onclick="closeEditBookingModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Schedule Modal -->
    <div class="modal-overlay" id="edit-schedule-modal">
        <div class="modal" style="max-width:700px;">
            <div class="modal-header">
                <h3 class="modal-title">Edit Schedule (Series)</h3><button class="modal-close"
                    onclick="closeEditScheduleModal()">×</button>
            </div>
            <div id="edit-schedule-alert" style="display:none;padding:10px 20px;">
                <div class="alert alert-danger" id="edit-schedule-alert-msg"></div>
            </div>
            <form id="edit-schedule-form">
                <input type="hidden" id="edit-schedule-id">
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Degree Program</label><select
                            id="edit-schedule-degree" class="form-control" disabled>
                            <option value="">Select Program</option><?php foreach ($degree_programs as $dp): ?>
                                <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="form-label">Level-Semester</label><select
                            id="edit-schedule-level-sem" class="form-control" disabled>
                            <option value="">Loading...</option>
                        </select></div>
                </div>
                <div class="form-group"><label class="form-label">Course</label><select id="edit-schedule-course"
                        class="form-control" required></select></div>
                <div class="form-group"><label class="form-label">Room *</label><select id="edit-schedule-room"
                        class="form-control" required><?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>">
                                <?= htmlspecialchars($room['building_name'] . ' - ' . $room['room_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label class="form-label">Day of Week *</label><select id="edit-schedule-day"
                        class="form-control" required>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                        <option value="7">Sunday</option>
                    </select></div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Start Time</label><input type="time"
                            id="edit-schedule-start" class="form-control" step="300" required></div>
                    <div class="form-group"><label class="form-label">End Time</label><input type="time"
                            id="edit-schedule-end" class="form-control" step="300" required></div>
                </div>
                <div class="form-group"><label class="form-label">Group</label><select id="edit-schedule-group"
                        class="form-control">
                        <option value="">All Groups</option>
                    </select></div>
                <div class="form-group"><label class="form-label">Purpose *</label>
                    <select id="edit-schedule-purpose-select" class="form-control" required>
                        <option value="">-- Select purpose --</option>
                        <?php foreach ($purpose_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit-schedule-purpose-custom-wrap" class="purpose-custom-field hidden"><input type="text"
                            id="edit-schedule-purpose-custom" class="form-control"
                            placeholder="Enter custom purpose..."></div>
                    <input type="hidden" id="edit-schedule-purpose" name="purpose">
                </div>
                <div id="edit-schedule-conflict-suggestions" style="margin-top:10px;"></div>
                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;"><button type="button"
                        class="btn btn-outline" onclick="closeEditScheduleModal()">Cancel</button><button type="submit"
                        class="btn btn-primary">Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- Detail Modal -->
    <div class="modal-overlay" id="sb-detail-modal">
        <div class="modal" style="max-width:460px;">
            <div class="modal-header">
                <h3 class="modal-title" id="sb-detail-title">Details</h3><button class="modal-close"
                    onclick="document.getElementById('sb-detail-modal').classList.remove('active')">×</button>
            </div>
            <div id="sb-detail-body" style="padding:20px;"></div>
        </div>
    </div>

    <!-- Add Schedule Modal -->
    <div class="modal-overlay" id="add-schedule-modal">
        <div class="modal" style="max-width:700px;">
            <div class="modal-header">
                <h3 class="modal-title">Book for Semester</h3><button class="modal-close"
                    onclick="closeAddScheduleModal()">×</button>
            </div>
            <div id="add-schedule-alert" style="display:none;padding:10px 20px;">
                <div class="alert alert-danger" id="add-schedule-alert-msg"></div>
            </div>
            <form id="add-schedule-form">
                <input type="hidden" name="action" value="add_schedule_ajax">
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Degree Program *</label><select id="as-degree"
                            class="form-control" required onchange="asLoadLevels()">
                            <option value="">Select Program</option><?php foreach ($degree_programs as $dp): ?>
                                <option value="<?= $dp['id'] ?>"><?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="form-group"><label class="form-label">Level *</label><select id="as-level"
                            class="form-control" required onchange="asLoadSemesters()">
                            <option value="">Select Degree first</option>
                        </select></div>
                </div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Semester *</label><select id="as-semester"
                            class="form-control" required onchange="asLoadCourses()">
                            <option value="">Select Level first</option>
                        </select></div>
                    <div class="form-group"><label class="form-label">Group</label><select id="as-group"
                            class="form-control">
                            <option value="">All Groups</option>
                        </select></div>
                </div>
                <div class="form-group"><label class="form-label">Course *</label><select id="as-course"
                        class="form-control" required>
                        <option value="">Select Semester first</option>
                    </select></div>
                <div class="form-group"><label class="form-label">Room *</label><select id="as-room"
                        class="form-control" required>
                        <option value="">Select Room</option><?php foreach ($rooms as $room): ?>
                            <option value="<?= $room['id'] ?>">
                                <?= htmlspecialchars($room['building_name'] . ' - ' . $room['room_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="form-group"><label class="form-label">Day of Week *</label><select id="as-day"
                        class="form-control" required>
                        <option value="1">Monday</option>
                        <option value="2">Tuesday</option>
                        <option value="3">Wednesday</option>
                        <option value="4">Thursday</option>
                        <option value="5">Friday</option>
                        <option value="6">Saturday</option>
                        <option value="7">Sunday</option>
                    </select></div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Start Time</label><input type="time"
                            id="as-start-time" class="form-control" step="300" required value="09:00"></div>
                    <div class="form-group"><label class="form-label">End Time</label><input type="time"
                            id="as-end-time" class="form-control" step="300" required value="10:00"></div>
                </div>
                <div class="form-group"><label class="form-label">Purpose *</label>
                    <select id="as-purpose-select" class="form-control" required>
                        <option value="">-- Select purpose --</option>
                        <?php foreach ($purpose_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="as-purpose-custom-wrap" class="purpose-custom-field hidden"><input type="text"
                            id="as-purpose-custom" class="form-control" placeholder="Enter custom purpose..."></div>
                    <input type="hidden" id="as-purpose" name="purpose">
                </div>
                <div class="form-group"><label><input type="checkbox" id="as-whole-semester" checked> Whole
                        Semester</label></div>
                <div class="form-row" style="grid-template-columns:1fr 1fr;">
                    <div class="form-group"><label class="form-label">Start Date</label><input type="date"
                            id="as-start-date" class="form-control" readonly></div>
                    <div class="form-group"><label class="form-label">End Date</label><input type="date"
                            id="as-end-date" class="form-control" readonly></div>
                </div>
                <div id="conflict-suggestions" style="margin-top:15px;"></div>
                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;"><button type="button"
                        class="btn btn-outline" onclick="closeAddScheduleModal()">Cancel</button><button type="submit"
                        class="btn btn-primary">Book for Semester</button></div>
            </form>
        </div>
    </div>

    <!-- Cancel Schedule Modal -->
    <div class="modal-overlay" id="cancel-schedule-modal">
        <div class="modal" style="max-width:450px;">
            <div class="modal-header">
                <h3 class="modal-title">Cancel Schedule</h3><button class="modal-close"
                    onclick="closeCancelScheduleModal()">×</button>
            </div>
            <div id="cancel-alert" style="display:none;padding:10px 20px;">
                <div class="alert alert-danger" id="cancel-alert-msg"></div>
            </div>
            <form id="cancel-schedule-form">
                <input type="hidden" id="cancel-schedule-id"><input type="hidden" id="cancel-schedule-date">
                <p id="cancel-schedule-info"></p>
                <div class="form-group"><label class="form-label">Cancel</label><select id="cancel-type"
                        class="form-control">
                        <option value="series">Entire recurring series</option>
                        <option value="single">This single occurrence only</option>
                    </select></div>
                <div class="form-group"><label class="form-label">Reason (optional)</label><textarea id="cancel-reason"
                        class="form-control" rows="2" placeholder="e.g., Class cancelled"></textarea></div>
                <div style="display:flex; gap:12px; justify-content:flex-end;"><button type="button"
                        class="btn btn-outline" onclick="closeCancelScheduleModal()">Cancel</button><button
                        type="submit" class="btn btn-danger">Confirm Cancellation</button></div>
            </form>
        </div>
    </div>

    <script>
        const DEGREE_SEM_DATA = <?= json_encode($degree_sem_data) ?>;
        const PURPOSE_OPTIONS = <?= json_encode($purpose_options) ?>;
        const TODAY = new Date().toISOString().slice(0, 10);
        let sbView = '<?= $sb_view ?>';
        let sbDate = '<?= $sb_date ?>';
        let currentSlotEl = null;
        let currentFreeBlocks = [];
        let conflictCheckTimer;

        function setupPurposeDropdown(selectId, customWrapId, customInputId, hiddenInputId) {
            const select = document.getElementById(selectId);
            const customWrap = document.getElementById(customWrapId);
            const customInput = document.getElementById(customInputId);
            const hidden = document.getElementById(hiddenInputId);
            if (!select) return;
            const update = () => {
                const val = select.value;
                if (val === 'Other') {
                    customWrap.classList.remove('hidden');
                    customInput.required = true;
                } else {
                    customWrap.classList.add('hidden');
                    customInput.required = false;
                    if (val && val !== '') {
                        if (hidden) hidden.value = val;
                    } else {
                        if (hidden) hidden.value = '';
                    }
                }
            };
            select.addEventListener('change', update);
            if (customInput) {
                customInput.addEventListener('input', () => {
                    if (select.value === 'Other' && hidden) hidden.value = customInput.value;
                });
            }
            update();
        }

        function toggleMobileSidebar() {
            const mobileSidebar = document.getElementById('mobileSidebar');
            const pageBlur = document.getElementById('pageBlur');
            mobileSidebar.classList.toggle('active');
            pageBlur.classList.toggle('active');
            document.body.style.overflow = mobileSidebar.classList.contains('active') ? 'hidden' : '';
        }
        function closeMobileSidebar() {
            document.getElementById('mobileSidebar').classList.remove('active');
            document.getElementById('pageBlur').classList.remove('active');
            document.body.style.overflow = '';
        }
        document.getElementById('hamburgerBtn').addEventListener('click', toggleMobileSidebar);
        document.getElementById('pageBlur').addEventListener('click', closeMobileSidebar);

        function showSection(sectionId, ev) {
            if (ev) ev.preventDefault();
            document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
            document.getElementById(sectionId).style.display = 'block';
            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            const clickedItem = ev ? ev.target.closest('.nav-item') : null;
            if (clickedItem) {
                clickedItem.classList.add('active');
                const navIndex = Array.from(clickedItem.parentElement.children).indexOf(clickedItem);
                const otherSidebar = clickedItem.closest('.desktop-sidebar') ? document.querySelector('.mobile-sidebar .nav-menu') : document.querySelector('.desktop-sidebar .nav-menu');
                if (otherSidebar && otherSidebar.children[navIndex]) otherSidebar.children[navIndex].classList.add('active');
            }
            const url = new URL(window.location);
            url.searchParams.set('section', sectionId);
            history.pushState({}, '', url);
            if (window.innerWidth < 1024) closeMobileSidebar();
        }

        function showScheduleView(view) {
            ['today', 'week', 'bookings'].forEach(v => {
                const el = document.getElementById('schedule-' + v);
                if (el) el.style.display = v === view ? 'block' : 'none';
            });
            document.querySelectorAll('#schedule .tab').forEach((t, i) => t.classList.toggle('active', ['today', 'week', 'bookings'][i] === view));
        }

        function freeRoomModal(bookingId, roomName) {
            document.getElementById('free-booking-id').value = bookingId;
            document.getElementById('free-room-name').textContent = roomName;
            document.getElementById('free-modal').classList.add('active');
        }
        function closeFreeModal() {
            document.getElementById('free-modal').classList.remove('active');
        }
        document.getElementById('free-modal').addEventListener('click', function (e) { if (e.target === this) closeFreeModal(); });

        function sbUpdateUrl(params) {
            const url = new URL(window.location);
            url.searchParams.set('section', 'status-booking');
            if (params.view !== undefined) url.searchParams.set('view', params.view);
            if (params.date !== undefined) url.searchParams.set('date', params.date);
            if (params.sb_degree !== undefined) params.sb_degree ? url.searchParams.set('sb_degree', params.sb_degree) : url.searchParams.delete('sb_degree');
            if (params.sb_faculty !== undefined) params.sb_faculty ? url.searchParams.set('sb_faculty', params.sb_faculty) : url.searchParams.delete('sb_faculty');
            if (params.sb_building !== undefined) params.sb_building ? url.searchParams.set('sb_building', params.sb_building) : url.searchParams.delete('sb_building');
            window.location.href = url.toString();
        }
        window.sbUpdateUrl = sbUpdateUrl;

        document.getElementById('sb-btn-prev')?.addEventListener('click', () => {
            let d = new Date(sbDate + 'T12:00:00');
            d.setDate(d.getDate() + (sbView === 'week' ? -7 : -1));
            sbUpdateUrl({ date: d.toISOString().slice(0, 10) });
        });
        document.getElementById('sb-btn-next')?.addEventListener('click', () => {
            let d = new Date(sbDate + 'T12:00:00');
            d.setDate(d.getDate() + (sbView === 'week' ? 7 : 1));
            sbUpdateUrl({ date: d.toISOString().slice(0, 10) });
        });
        document.getElementById('sb-btn-today')?.addEventListener('click', async (e) => {
            e.preventDefault();
            const today = new Date().toISOString().slice(0, 10);
            const view = sbView;
            const gridContainer = document.querySelector('.sb-grid-scroll')?.parentElement;
            if (gridContainer) gridContainer.style.opacity = '0.6';
            try {
                const resp = await fetch(`?ajax=refresh_sb_grid&date=${today}&view=${view}`);
                const data = await resp.json();
                if (data.success) {
                    if (view === 'week') {
                        document.querySelector('.sb-week-wrapper').outerHTML = data.html;
                    } else {
                        // Atomically replace the #sb-day-content wrapper (Issue 4 fix)
                        const oldContent = document.querySelector('#status-booking #sb-day-content');
                        if (oldContent) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = data.html;
                            const newContent = tempDiv.querySelector('#sb-day-content');
                            if (newContent) {
                                oldContent.outerHTML = newContent.outerHTML;
                            } else {
                                oldContent.outerHTML = data.html;
                            }
                        }
                    }
                    document.getElementById('sb-date-input').value = today;
                    sbDate = today;
                    attachSBClickHandlers();
                }
            } catch (e) { console.error(e); }
            finally { if (gridContainer) gridContainer.style.opacity = '1'; }
        });

        function attachSBClickHandlers() {
            document.querySelectorAll('.sb-week-cell').forEach(cell => cell.addEventListener('click', () => sbUpdateUrl({ view: 'day', date: cell.dataset.date })));
            document.querySelectorAll('.sb-day-slot').forEach(slot => {
                slot.removeEventListener('click', handleSlotClick);
                slot.addEventListener('click', () => handleSlotClick(slot));
            });
        }

        function handleSlotClick(slot) {
            const status = slot.dataset.status;
            const detail = slot.dataset.detail ? JSON.parse(slot.dataset.detail) : null;
            const isPastDate = slot.dataset.date < TODAY;
            const isPastTime = isPastDate || (slot.dataset.date === TODAY && slot.dataset.slotStart < new Date().toTimeString().slice(0, 5));
            if (status === 'class' && detail && detail.teacher_id == <?= $teacher_id ?> && !isPastTime) {
                openCancelScheduleModal(detail.id, detail.course_code, slot.dataset.date);
            } else if (status === 'booked' && detail && detail.booked_by_id == <?= $teacher_id ?> && !isPastTime) {
                openEditBookingModal({
                    id: detail.id,
                    room_name: slot.dataset.roomName,
                    building_name: slot.dataset.building,
                    booking_date: slot.dataset.date,
                    start_time: detail.start_time,
                    end_time: detail.end_time,
                    purpose: detail.purpose,
                    degree_program_id: detail.degree_program_id,
                    level: detail.level,
                    semester: detail.semester,
                    group_name: detail.group_name,
                    course_id: detail.course_id || null
                });
            } else if (status === 'class' || status === 'booked') {
                sbShowDetailModal(detail, slot.dataset.roomName, slot.dataset.building, slot.dataset.slotStart + '–' + slot.dataset.slotEnd);
            } else if (slot.classList.contains('sb-bookable')) {
                sbOpenBookModal(slot);
            }
        }

        function attachMyScheduleHandlers() {
            document.querySelectorAll('.schedule-class-detail').forEach(el => {
                el.removeEventListener('click', handleClassDetailClick);
                el.addEventListener('click', handleClassDetailClick);
            });
            document.querySelectorAll('.my-week-class').forEach(el => {
                el.removeEventListener('click', handleWeekClassClick);
                el.addEventListener('click', handleWeekClassClick);
            });
            document.querySelectorAll('.my-week-booking').forEach(el => {
                el.removeEventListener('click', handleWeekBookingClick);
                el.addEventListener('click', handleWeekBookingClick);
            });
        }
        function handleClassDetailClick(e) {
            const el = e.currentTarget;
            if (el.dataset.scheduleId) {
                openCancelScheduleModal(el.dataset.scheduleId, el.dataset.courseCode, el.dataset.date);
            }
        }
        function handleWeekClassClick(e) {
            const el = e.currentTarget.closest('.my-week-class');
            if (el && el.dataset.scheduleId) {
                openEditScheduleModal(el.dataset.scheduleId);
            }
        }
        function handleWeekBookingClick(e) {
            const el = e.currentTarget.closest('.my-week-booking');
            if (el && el.dataset.bookingId) {
                fetch(`?ajax=get_booking_details&id=${el.dataset.bookingId}`)
                    .then(r => r.json())
                    .then(data => { if (data.success) openEditBookingModal(data.booking); });
            }
        }

        function sbOpenBookModal(slotEl) {
            currentSlotEl = slotEl;
            const row = slotEl.closest('tr');
            try { currentFreeBlocks = JSON.parse(row.dataset.freeBlocks || '[]'); } catch (e) { currentFreeBlocks = []; }
            const roomName = slotEl.dataset.roomName;
            const building = slotEl.dataset.building;
            const date = slotEl.dataset.date;
            const freeStart = slotEl.dataset.freeStart;
            const freeEnd = slotEl.dataset.freeEnd;
            let blockStart = freeStart, blockEnd = freeEnd;
            for (let fb of currentFreeBlocks) {
                if (fb[0] <= freeStart && fb[1] >= freeEnd) { blockStart = fb[0]; blockEnd = fb[1]; break; }
            }
            document.getElementById('sb-modal-title').innerText = `Book – ${roomName}`;
            document.getElementById('sb-display-room').value = `${roomName} (${building})`;
            document.getElementById('sb-display-date').value = date;
            const startInput = document.getElementById('sb-start-time');
            const endInput = document.getElementById('sb-end-time');
            startInput.min = blockStart; startInput.max = blockEnd;
            endInput.min = blockStart; endInput.max = blockEnd;
            startInput.value = freeStart; endInput.value = freeEnd;
            document.getElementById('sb-purpose').value = '';
            document.getElementById('sb-purpose-custom').value = '';
            document.getElementById('sb-group').innerHTML = '<option value="">All Groups</option>';
            document.getElementById('sb-degree-program').value = '';
            document.getElementById('sb-level-sem').innerHTML = '<option value="">Select Degree first</option>';
            document.getElementById('sb-course').innerHTML = '<option value="">Select Level-Semester first</option>';
            document.getElementById('sb-modal-alert').style.display = 'none';
            document.getElementById('sb-modal-success').style.display = 'none';
            document.getElementById('sb-modal-form-wrap').style.display = '';
            document.getElementById('sb-submit-btn').disabled = false;
            document.getElementById('sb-submit-text').innerText = 'Book Room';
            document.getElementById('sb-book-modal').classList.add('active');
        }
        window.sbCloseBookModal = () => document.getElementById('sb-book-modal').classList.remove('active');
        document.getElementById('sb-book-modal').addEventListener('click', e => { if (e.target === document.getElementById('sb-book-modal')) sbCloseBookModal(); });
        document.getElementById('sb-detail-modal').addEventListener('click', e => { if (e.target === document.getElementById('sb-detail-modal')) e.target.classList.remove('active'); });

        async function sbLoadLevelSemesters() {
            const dpId = document.getElementById('sb-degree-program').value;
            const sel = document.getElementById('sb-level-sem');
            sel.innerHTML = '<option value="">Loading…</option>';
            if (!dpId) { sel.innerHTML = '<option value="">Select Degree first</option>'; return; }
            const resp = await fetch(`?ajax=get_level_semesters&degree_program_id=${dpId}`);
            const options = await resp.json();
            sel.innerHTML = '<option value="">Select Level-Semester</option>';
            options.forEach(opt => {
                const o = document.createElement('option'); o.value = opt.value; o.textContent = opt.text; sel.appendChild(o);
            });
            sbLoadCourses();
        }
        async function sbLoadGroups() {
            const dpId = document.getElementById('sb-degree-program').value;
            const levelSem = document.getElementById('sb-level-sem').value;
            const sel = document.getElementById('sb-group');
            sel.innerHTML = '<option value="">All Groups</option>';
            if (!dpId || !levelSem) return;
            const match = levelSem.match(/L(\d+)\s+S(\d+)/);
            if (!match) return;
            const level = match[1], sem = match[2];
            const resp = await fetch(`?ajax=get_groups_for_semester&degree_program_id=${dpId}&level=${level}&semester_num=${sem}`);
            const groups = await resp.json();
            groups.forEach(g => { const o = document.createElement('option'); o.value = g; o.textContent = g; sel.appendChild(o); });
        }
        async function sbLoadCourses() {
            const dpId = document.getElementById('sb-degree-program').value;
            const levelSem = document.getElementById('sb-level-sem').value;
            const courseSelect = document.getElementById('sb-course');
            courseSelect.innerHTML = '<option value="">Loading...</option>';
            if (!dpId || !levelSem) {
                courseSelect.innerHTML = '<option value="">Select Level-Semester first</option>';
                return;
            }
            const match = levelSem.match(/L(\d+)\s+S(\d+)/);
            if (!match) return;
            const level = match[1], sem = match[2];
            const resp = await fetch(`?ajax=get_courses_for_booking&degree_program_id=${dpId}&level=${level}&semester=${sem}`);
            const courses = await resp.json();
            courseSelect.innerHTML = '<option value="">Select Course</option>';
            courses.forEach(c => {
                const opt = document.createElement('option'); opt.value = c.id; opt.textContent = `${c.course_code} - ${c.course_name}`; courseSelect.appendChild(opt);
            });
            sbLoadGroups();
        }
        document.getElementById('sb-level-sem').addEventListener('change', sbLoadCourses);

        async function sbSubmitBooking() {
            if (!currentSlotEl) return;
            const purposeSelect = document.getElementById('sb-purpose');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('sb-purpose-custom').value.trim();
            if (purpose === 'Other' && !purposeCustom) { sbShowModalAlert('Please enter a custom purpose.'); return; }
            if (!purpose) { sbShowModalAlert('Please select a purpose.'); return; }
            const startTime = document.getElementById('sb-start-time').value;
            const endTime = document.getElementById('sb-end-time').value;
            if (startTime >= endTime) { sbShowModalAlert('End time must be after start time.'); return; }
            const dpId = document.getElementById('sb-degree-program').value;
            const levelSem = document.getElementById('sb-level-sem').value;
            const courseId = document.getElementById('sb-course').value;
            const group = document.getElementById('sb-group').value.trim() || '';
            if (!dpId || !levelSem || !courseId) { sbShowModalAlert('Degree, Level-Semester and Course are required.'); return; }
            const submitBtn = document.getElementById('sb-submit-btn');
            const submitTxt = document.getElementById('sb-submit-text');
            submitBtn.disabled = true; submitTxt.innerText = 'Booking…';
            const formData = new FormData();
            formData.append('room_id', currentSlotEl.dataset.roomId);
            formData.append('booking_date', currentSlotEl.dataset.date);
            formData.append('start_time', startTime);
            formData.append('end_time', endTime);
            formData.append('purpose', purpose);
            formData.append('purpose_custom', purposeCustom);
            formData.append('degree_program_id', dpId);
            formData.append('level_sem', levelSem);
            formData.append('course_id', courseId);
            if (group) formData.append('group_name', group);
            try {
                const resp = await fetch(`?ajax=sb_book_room`, { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) {
                    sbShowModalSuccess('Room booked successfully!');
                    await refreshSBGrid();
                    await refreshMySchedule('all');
                    submitBtn.style.display = 'none';
                    setTimeout(sbCloseBookModal, 1500);
                } else {
                    let msg = data.conflict || data.error || 'Could not book room.';
                    if (data.priority_class === 'priority_faculty') msg += ' <br><small style="color:var(--warning)">⚠️ You have faculty priority. You will be notified when available.</small>';
                    else msg += ' <br><small>You will be notified when this room is freed.</small>';
                    sbShowModalAlert(msg);
                    submitBtn.disabled = false; submitTxt.innerText = 'Try Again';
                }
            } catch (e) { sbShowModalAlert('Network error.'); submitBtn.disabled = false; submitTxt.innerText = 'Book Room'; }
        }

        function sbShowDetailModal(detail, roomName, building, timeStr) {
            const title = document.getElementById('sb-detail-title');
            const body = document.getElementById('sb-detail-body');
            if (detail.type === 'class') {
                title.innerText = `📖 ${detail.course_code}`;
                body.innerHTML = `<div class="sb-detail-row"><div class="sb-detail-label">Room:</div><div class="sb-detail-value">${roomName} (${building})</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Time:</div><div class="sb-detail-value">${timeStr || (detail.start_time + '–' + detail.end_time)}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Course:</div><div class="sb-detail-value">${detail.course_name || detail.course_code}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Teacher:</div><div class="sb-detail-value">${detail.teacher_name || 'N/A'}<br>📞 ${detail.teacher_phone || 'N/A'}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Degree:</div><div class="sb-detail-value">${detail.degree_name || 'N/A'} (Level ${detail.level}, Sem ${detail.semester})</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Purpose:</div><div class="sb-detail-value">${detail.purpose || 'Not specified'}</div></div>`;
            } else {
                title.innerText = `📅 ${detail.purpose}`;
                body.innerHTML = `<div class="sb-detail-row"><div class="sb-detail-label">Room:</div><div class="sb-detail-value">${roomName} (${building})</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Time:</div><div class="sb-detail-value">${timeStr || (detail.start_time + '–' + detail.end_time)}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Purpose:</div><div class="sb-detail-value">${detail.purpose}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Booked by:</div><div class="sb-detail-value">${detail.booked_by || 'N/A'}<br>📞 ${detail.booked_by_phone || 'N/A'}</div></div>`;
            }
            document.getElementById('sb-detail-modal').classList.add('active');
        }
        function sbShowModalAlert(htmlMsg) {
            document.getElementById('sb-modal-alert-msg').innerHTML = htmlMsg;
            document.getElementById('sb-modal-alert').style.display = '';
            document.getElementById('sb-modal-success').style.display = 'none';
        }
        function sbShowModalSuccess(msg) {
            document.getElementById('sb-modal-success-msg').innerText = msg;
            document.getElementById('sb-modal-success').style.display = '';
            document.getElementById('sb-modal-alert').style.display = 'none';
        }

        // Edit Booking
        let currentEditBooking = null;
        function openEditBookingModal(booking) {
            currentEditBooking = booking;
            document.getElementById('edit-booking-id').value = booking.id;
            document.getElementById('edit-booking-room').value = booking.room_name + ' (' + (booking.building_name || '') + ')';
            document.getElementById('edit-booking-date').value = booking.booking_date;
            document.getElementById('edit-booking-start').value = booking.start_time;
            document.getElementById('edit-booking-end').value = booking.end_time;
            document.getElementById('edit-booking-degree').value = booking.degree_program_id;
            loadEditLevelSemesters();
            setTimeout(() => {
                document.getElementById('edit-booking-level-sem').value = `L${booking.level} S${booking.semester}`;
                loadEditCourses();
                setTimeout(() => {
                    const courseSelect = document.getElementById('edit-booking-course');
                    if (booking.course_id) {
                        for (let i = 0; i < courseSelect.options.length; i++) {
                            if (courseSelect.options[i].value == booking.course_id) {
                                courseSelect.selectedIndex = i; break;
                            }
                        }
                    }
                    loadEditGroups();
                    setTimeout(() => {
                        document.getElementById('edit-booking-group').value = booking.group_name || '';
                    }, 100);
                }, 100);
            }, 50);
            const purposeSelect = document.getElementById('edit-booking-purpose-select');
            const purposeCustomWrap = document.getElementById('edit-booking-purpose-custom-wrap');
            const purposeCustomInput = document.getElementById('edit-booking-purpose-custom');
            const purposeHidden = document.getElementById('edit-booking-purpose');
            let existingPurpose = booking.purpose || '';
            let found = PURPOSE_OPTIONS.some(opt => opt === existingPurpose);
            if (found) {
                purposeSelect.value = existingPurpose;
                purposeCustomWrap.classList.add('hidden');
                purposeHidden.value = existingPurpose;
            } else {
                purposeSelect.value = 'Other';
                purposeCustomWrap.classList.remove('hidden');
                purposeCustomInput.value = existingPurpose;
                purposeHidden.value = existingPurpose;
            }
            document.getElementById('edit-booking-modal').classList.add('active');
        }
        function closeEditBookingModal() { document.getElementById('edit-booking-modal').classList.remove('active'); }
        async function loadEditLevelSemesters() {
            const dpId = document.getElementById('edit-booking-degree').value;
            const sel = document.getElementById('edit-booking-level-sem');
            sel.innerHTML = '<option value="">Loading…</option>';
            if (!dpId) { sel.innerHTML = '<option value="">Select Degree first</option>'; return; }
            const resp = await fetch(`?ajax=get_level_semesters&degree_program_id=${dpId}`);
            const options = await resp.json();
            sel.innerHTML = '<option value="">Select Level-Semester</option>';
            options.forEach(opt => {
                const o = document.createElement('option'); o.value = opt.value; o.textContent = opt.text; sel.appendChild(o);
            });
        }
        async function loadEditCourses() {
            const dpId = document.getElementById('edit-booking-degree').value;
            const levelSem = document.getElementById('edit-booking-level-sem').value;
            const courseSelect = document.getElementById('edit-booking-course');
            if (!dpId || !levelSem) { courseSelect.innerHTML = '<option value="">Select Level-Semester first</option>'; return; }
            const match = levelSem.match(/L(\d+)\s+S(\d+)/);
            if (!match) return;
            const level = match[1], sem = match[2];
            const resp = await fetch(`?ajax=get_courses_for_booking&degree_program_id=${dpId}&level=${level}&semester=${sem}`);
            const courses = await resp.json();
            courseSelect.innerHTML = '<option value="">Select Course</option>';
            courses.forEach(c => {
                const opt = document.createElement('option'); opt.value = c.id; opt.textContent = `${c.course_code} - ${c.course_name}`;
                courseSelect.appendChild(opt);
            });
        }
        async function loadEditGroups() {
            const dpId = document.getElementById('edit-booking-degree').value;
            const levelSem = document.getElementById('edit-booking-level-sem').value;
            const sel = document.getElementById('edit-booking-group');
            sel.innerHTML = '<option value="">All Groups</option>';
            if (!dpId || !levelSem) return;
            const match = levelSem.match(/L(\d+)\s+S(\d+)/);
            if (!match) return;
            const level = match[1], sem = match[2];
            const resp = await fetch(`?ajax=get_groups_for_semester&degree_program_id=${dpId}&level=${level}&semester_num=${sem}`);
            const groups = await resp.json();
            groups.forEach(g => { const o = document.createElement('option'); o.value = g; o.textContent = g; sel.appendChild(o); });
        }
        document.getElementById('edit-booking-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const bookingId = document.getElementById('edit-booking-id').value;
            const purposeSelect = document.getElementById('edit-booking-purpose-select');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('edit-booking-purpose-custom').value.trim();
            if (purpose === 'Other' && !purposeCustom) {
                document.getElementById('edit-booking-alert-msg').innerText = 'Please enter a custom purpose.';
                document.getElementById('edit-booking-alert').style.display = 'block';
                return;
            }
            if (!purpose) {
                document.getElementById('edit-booking-alert-msg').innerText = 'Please select a purpose.';
                document.getElementById('edit-booking-alert').style.display = 'block';
                return;
            }
            const start = document.getElementById('edit-booking-start').value;
            const end = document.getElementById('edit-booking-end').value;
            const dpId = document.getElementById('edit-booking-degree').value;
            const levelSem = document.getElementById('edit-booking-level-sem').value;
            const courseId = document.getElementById('edit-booking-course').value;
            const group = document.getElementById('edit-booking-group').value.trim() || null;
            if (!start || !end || !dpId || !levelSem || !courseId) {
                document.getElementById('edit-booking-alert-msg').innerText = 'All fields required.';
                document.getElementById('edit-booking-alert').style.display = 'block';
                return;
            }
            const formData = new FormData();
            formData.append('booking_id', bookingId);
            formData.append('purpose', purpose);
            formData.append('purpose_custom', purposeCustom);
            formData.append('start_time', start);
            formData.append('end_time', end);
            formData.append('degree_program_id', dpId);
            formData.append('level_sem', levelSem);
            formData.append('course_id', courseId);
            if (group) formData.append('group_name', group);
            const resp = await fetch('?ajax=edit_booking', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                alert(data.message);
                refreshMySchedule('all');
                refreshSBGrid();
                location.reload();
            } else {
                document.getElementById('edit-booking-alert-msg').innerText = data.error;
                document.getElementById('edit-booking-alert').style.display = 'block';
            }
        });

        // Edit Schedule Modal
        function openEditScheduleModal(scheduleId) {
            fetch(`?ajax=get_schedule_details&id=${scheduleId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        const s = data.schedule;
                        document.getElementById('edit-schedule-id').value = s.id;
                        document.getElementById('edit-schedule-degree').value = s.degree_program_id;
                        loadEditScheduleLevelSem(s.degree_program_id, `L${s.level} S${s.semester}`);
                        loadEditScheduleCourses(s.degree_program_id, s.level, s.semester, s.course_id);
                        document.getElementById('edit-schedule-room').value = s.room_id;
                        document.getElementById('edit-schedule-day').value = s.day_of_week;
                        document.getElementById('edit-schedule-start').value = s.start_time;
                        document.getElementById('edit-schedule-end').value = s.end_time;
                        loadEditScheduleGroups(s.degree_program_id, s.level, s.semester, s.group_name);
                        const purposeSelect = document.getElementById('edit-schedule-purpose-select');
                        const purposeCustomWrap = document.getElementById('edit-schedule-purpose-custom-wrap');
                        const purposeCustomInput = document.getElementById('edit-schedule-purpose-custom');
                        const purposeHidden = document.getElementById('edit-schedule-purpose');
                        let existingPurpose = s.purpose || 'Theory Class';
                        let found = PURPOSE_OPTIONS.some(opt => opt === existingPurpose);
                        if (found) {
                            purposeSelect.value = existingPurpose;
                            purposeCustomWrap.classList.add('hidden');
                            purposeHidden.value = existingPurpose;
                        } else {
                            purposeSelect.value = 'Other';
                            purposeCustomWrap.classList.remove('hidden');
                            purposeCustomInput.value = existingPurpose;
                            purposeHidden.value = existingPurpose;
                        }
                        document.getElementById('edit-schedule-modal').classList.add('active');
                    }
                });
        }
        function closeEditScheduleModal() { document.getElementById('edit-schedule-modal').classList.remove('active'); }
        async function loadEditScheduleLevelSem(dpId, selected) {
            const sel = document.getElementById('edit-schedule-level-sem');
            const resp = await fetch(`?ajax=get_level_semesters&degree_program_id=${dpId}`);
            const options = await resp.json();
            sel.innerHTML = '';
            options.forEach(opt => {
                const o = document.createElement('option'); o.value = opt.value; o.textContent = opt.text;
                if (opt.value === selected) o.selected = true;
                sel.appendChild(o);
            });
        }
        async function loadEditScheduleCourses(dpId, level, sem, selectedCourseId) {
            const sel = document.getElementById('edit-schedule-course');
            const resp = await fetch(`?ajax=get_courses&degree_program_id=${dpId}&level=${level}&semester=${sem}`);
            const courses = await resp.json();
            sel.innerHTML = '';
            courses.forEach(c => {
                const o = document.createElement('option'); o.value = c.id; o.textContent = `${c.course_code} - ${c.course_name}`;
                if (c.id == selectedCourseId) o.selected = true;
                sel.appendChild(o);
            });
        }
        async function loadEditScheduleGroups(dpId, level, sem, selectedGroup) {
            const sel = document.getElementById('edit-schedule-group');
            sel.innerHTML = '<option value="">All Groups</option>';
            const resp = await fetch(`?ajax=get_groups_for_semester&degree_program_id=${dpId}&level=${level}&semester_num=${sem}`);
            const groups = await resp.json();
            groups.forEach(g => {
                const o = document.createElement('option'); o.value = g; o.textContent = g;
                if (g === selectedGroup) o.selected = true;
                sel.appendChild(o);
            });
        }
        document.getElementById('edit-schedule-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('edit-schedule-id').value;
            const courseId = document.getElementById('edit-schedule-course').value;
            const roomId = document.getElementById('edit-schedule-room').value;
            const day = document.getElementById('edit-schedule-day').value;
            const start = document.getElementById('edit-schedule-start').value;
            const end = document.getElementById('edit-schedule-end').value;
            const group = document.getElementById('edit-schedule-group').value;
            const purposeSelect = document.getElementById('edit-schedule-purpose-select');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('edit-schedule-purpose-custom').value.trim();
            if (purpose === 'Other' && !purposeCustom) {
                document.getElementById('edit-schedule-alert-msg').innerText = 'Please enter a custom purpose.';
                document.getElementById('edit-schedule-alert').style.display = 'block';
                return;
            }
            if (!purpose) {
                document.getElementById('edit-schedule-alert-msg').innerText = 'Please select a purpose.';
                document.getElementById('edit-schedule-alert').style.display = 'block';
                return;
            }
            const formData = new FormData();
            formData.append('schedule_id', id);
            formData.append('course_id', courseId);
            formData.append('room_id', roomId);
            formData.append('day_of_week', day);
            formData.append('start_time', start);
            formData.append('end_time', end);
            formData.append('purpose', purpose);
            formData.append('purpose_custom', purposeCustom);
            if (group) formData.append('group_name', group);
            const resp = await fetch('?ajax=edit_schedule', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                document.getElementById('edit-schedule-alert-msg').innerText = data.error;
                document.getElementById('edit-schedule-alert').style.display = 'block';
            }
        });

        // Add Schedule Modal functions
        function openAddScheduleModal() {
            document.getElementById('add-schedule-modal').classList.add('active');
            document.getElementById('add-schedule-form').reset();
            document.getElementById('as-whole-semester').checked = true;
            document.getElementById('as-start-date').readOnly = true;
            document.getElementById('as-end-date').readOnly = true;
            document.getElementById('conflict-suggestions').innerHTML = '';
            document.getElementById('as-purpose-select').value = '';
            document.getElementById('as-purpose-custom').value = '';
            document.getElementById('as-purpose-custom-wrap').classList.add('hidden');
            document.getElementById('as-purpose').value = '';
        }
        function closeAddScheduleModal() { document.getElementById('add-schedule-modal').classList.remove('active'); }
        async function asLoadLevels() {
            const dp = document.getElementById('as-degree').value;
            const lvlSel = document.getElementById('as-level');
            lvlSel.innerHTML = '<option value="">Select Level</option>';
            if (!dp) return;
            const data = DEGREE_SEM_DATA[dp];
            if (!data) return;
            for (let i = 1; i <= data.total_levels; i++) {
                const opt = document.createElement('option'); opt.value = i; opt.textContent = 'Level ' + i; lvlSel.appendChild(opt);
            }
            asLoadSemesters();
        }
        async function asLoadSemesters() {
            const dp = document.getElementById('as-degree').value;
            const lvl = document.getElementById('as-level').value;
            const semSel = document.getElementById('as-semester');
            semSel.innerHTML = '<option value="">Select Semester</option>';
            if (!dp || !lvl) return;
            const data = DEGREE_SEM_DATA[dp];
            if (!data) return;
            const spl = data.semesters_per_level || 2;
            for (let i = 1; i <= spl; i++) {
                const opt = document.createElement('option'); opt.value = i; opt.textContent = 'Semester ' + i; semSel.appendChild(opt);
            }
            asLoadCourses();
        }
        async function asLoadCourses() {
            const dp = document.getElementById('as-degree').value;
            const lvl = document.getElementById('as-level').value;
            const sem = document.getElementById('as-semester').value;
            const courseSelect = document.getElementById('as-course');
            courseSelect.innerHTML = '<option value="">Loading…</option>';
            if (!dp || !lvl || !sem) return;
            const resp = await fetch(`?ajax=get_courses&degree_program_id=${dp}&level=${lvl}&semester=${sem}`);
            const courses = await resp.json();
            courseSelect.innerHTML = '<option value="">Select Course</option>';
            courses.forEach(c => {
                const opt = document.createElement('option'); opt.value = c.id; opt.textContent = `${c.course_code} - ${c.course_name}`; courseSelect.appendChild(opt);
            });
            const groupResp = await fetch(`?ajax=get_groups_for_semester&degree_program_id=${dp}&level=${lvl}&semester_num=${sem}`);
            const groups = await groupResp.json();
            const groupSelect = document.getElementById('as-group');
            groupSelect.innerHTML = '<option value="">All Groups</option>';
            groups.forEach(g => { const opt = document.createElement('option'); opt.value = g; opt.textContent = g; groupSelect.appendChild(opt); });
            const groupVal = document.getElementById('as-group').value;
            const dateResp = await fetch(`?ajax=get_semester_dates&degree_program_id=${dp}&level=${lvl}&semester_num=${sem}&group_name=${groupVal || ''}`);
            const dates = await dateResp.json();
            if (dates && dates.start_date && dates.end_date) {
                document.getElementById('as-start-date').value = dates.start_date;
                document.getElementById('as-end-date').value = dates.end_date;
            }
        }
        document.getElementById('as-group').addEventListener('change', async function () {
            const dp = document.getElementById('as-degree').value;
            const lvl = document.getElementById('as-level').value;
            const sem = document.getElementById('as-semester').value;
            const group = this.value;
            if (dp && lvl && sem) {
                const resp = await fetch(`?ajax=get_semester_dates&degree_program_id=${dp}&level=${lvl}&semester_num=${sem}&group_name=${group || ''}`);
                const dates = await resp.json();
                if (dates && dates.start_date && dates.end_date) {
                    document.getElementById('as-start-date').value = dates.start_date;
                    document.getElementById('as-end-date').value = dates.end_date;
                }
            }
        });
        function debouncedCheckConflict() {
            clearTimeout(conflictCheckTimer);
            conflictCheckTimer = setTimeout(checkConflict, 500);
        }
        async function checkConflict() {
            const room = document.getElementById('as-room').value;
            if (!room) return;
            const day = document.getElementById('as-day').value;
            const start = document.getElementById('as-start-time').value;
            const end = document.getElementById('as-end-time').value;
            const startDate = document.getElementById('as-start-date').value;
            const endDate = document.getElementById('as-end-date').value;
            if (!day || !start || !end) return;
            const formData = new FormData();
            formData.append('room_id', room);
            formData.append('day_of_week', day);
            formData.append('start_time', start);
            formData.append('end_time', end);
            if (startDate) formData.append('start_date', startDate);
            if (endDate) formData.append('end_date', endDate);
            const resp = await fetch('?ajax=check_schedule_conflict', { method: 'POST', body: formData });
            const data = await resp.json();
            const container = document.getElementById('conflict-suggestions');
            if (data.conflict) {
                container.innerHTML = `<div class="alert alert-danger">Conflict: ${data.message}</div>`;
                if (data.alt_rooms && data.alt_rooms.length) {
                    let altHtml = '<div class="suggestions-box"><strong>Alternative free rooms:</strong> ';
                    data.alt_rooms.forEach(r => { altHtml += `<span class="suggestion-item" onclick="selectAlternativeRoom(${r.id})">${r.building} - ${r.name}</span> `; });
                    altHtml += '</div>';
                    container.innerHTML += altHtml;
                }
                if (data.alt_times && data.alt_times.length) {
                    let altHtml = '<div class="suggestions-box"><strong>Alternative free times:</strong> ';
                    data.alt_times.forEach(t => { altHtml += `<span class="suggestion-item" onclick="selectAlternativeTime('${t.start}', '${t.end}')">${t.start}-${t.end}</span> `; });
                    altHtml += '</div>';
                    container.innerHTML += altHtml;
                }
            } else {
                container.innerHTML = '<div class="alert alert-success">No conflicts detected.</div>';
            }
        }
        function selectAlternativeRoom(roomId) {
            document.getElementById('as-room').value = roomId;
            checkConflict();
        }
        function selectAlternativeTime(start, end) {
            document.getElementById('as-start-time').value = start;
            document.getElementById('as-end-time').value = end;
            checkConflict();
        }
        document.getElementById('add-schedule-form').addEventListener('input', (e) => {
            if (e.target.matches('#as-room, #as-day, #as-start-time, #as-end-time')) debouncedCheckConflict();
        });
        document.getElementById('add-schedule-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const purposeSelect = document.getElementById('as-purpose-select');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('as-purpose-custom').value.trim();
            if (purpose === 'Other' && !purposeCustom) {
                document.getElementById('add-schedule-alert-msg').innerText = 'Please enter a custom purpose.';
                document.getElementById('add-schedule-alert').style.display = 'block';
                return;
            }
            if (!purpose) {
                document.getElementById('add-schedule-alert-msg').innerText = 'Please select a purpose.';
                document.getElementById('add-schedule-alert').style.display = 'block';
                return;
            }
            document.getElementById('as-purpose').value = purpose;
            const formData = new FormData(e.target);
            const resp = await fetch('', { method: 'POST', body: formData });
            const text = await resp.text();
            if (text.includes('alert-success')) {
                await refreshMySchedule('all');
                await refreshSBGrid();
                location.reload();
            } else {
                document.getElementById('add-schedule-alert-msg').innerText = 'Error adding schedule.';
                document.getElementById('add-schedule-alert').style.display = 'block';
            }
        });

        // Cancel schedule
        function openCancelScheduleModal(scheduleId, courseCode, date = null) {
            document.getElementById('cancel-schedule-id').value = scheduleId;
            document.getElementById('cancel-schedule-date').value = date || '';
            document.getElementById('cancel-schedule-info').innerHTML = `Cancel schedule for <strong>${courseCode}</strong>${date ? ' on ' + date : ''}?`;
            document.getElementById('cancel-schedule-modal').classList.add('active');
        }
        function closeCancelScheduleModal() { document.getElementById('cancel-schedule-modal').classList.remove('active'); }
        document.getElementById('cancel-schedule-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('cancel-schedule-id').value;
            const type = document.getElementById('cancel-type').value;
            const date = document.getElementById('cancel-schedule-date').value;
            const reason = document.getElementById('cancel-reason').value;
            const formData = new FormData();
            formData.append('schedule_id', id);
            formData.append('cancel_type', type);
            formData.append('date', date);
            formData.append('reason', reason);
            const resp = await fetch('?ajax=cancel_schedule', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                alert(data.message);
                closeCancelScheduleModal();
                location.reload();
            } else {
                document.getElementById('cancel-alert-msg').innerText = data.error;
                document.getElementById('cancel-alert').style.display = 'block';
            }
        });

        async function refreshSBGrid() {
            const view = sbView;
            const date = sbDate;
            const resp = await fetch(`?ajax=refresh_sb_grid&date=${date}&view=${view}`);
            const data = await resp.json();
            if (data.success) {
                if (view === 'week') {
                    document.querySelector('.sb-week-wrapper').outerHTML = data.html;
                } else {
                    // Atomically replace #sb-day-content to prevent duplicate sb-day-heading (Issue 4 fix)
                    const oldContent = document.querySelector('#status-booking #sb-day-content');
                    if (oldContent) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.html;
                        const newContent = tempDiv.querySelector('#sb-day-content');
                        if (newContent) {
                            oldContent.outerHTML = newContent.outerHTML;
                        } else {
                            oldContent.outerHTML = data.html;
                        }
                    }
                }
                attachSBClickHandlers();
            }
        }
        async function refreshMySchedule(section) {
            const resp = await fetch(`?ajax=refresh_my_schedule&section=${section}`);
            const data = await resp.json();
            if (data.success) {
                if (section == 'today' || section == 'all') {
                    document.getElementById('schedule-today').innerHTML = data.html.match(/<div class="schedule-today-grid">[\s\S]*?<\/div>/)?.[0] || '';
                }
                if (section == 'week' || section == 'all') {
                    const weekContainer = document.getElementById('schedule-week');
                    const weekHtml = data.html.match(/<div class="my-week-table-wrapper">[\s\S]*?<\/div>/)?.[0];
                    if (weekHtml) weekContainer.innerHTML = weekHtml;
                }
                if (section == 'bookings' || section == 'all') {
                    document.getElementById('schedule-bookings').innerHTML = data.html.match(/<div class="table-container">[\s\S]*?<\/div>/)?.[0] || '';
                }
                attachMyScheduleHandlers();
            }
        }

        function getDayName(day) {
            return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][day - 1] || '';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');
            const sectionIndex = { 'dashboard': 0, 'schedule': 1, 'status-booking': 2, 'notifications': 3 };
            if (section && sectionIndex[section] !== undefined) {
                document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
                document.getElementById(section).style.display = 'block';
                document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
                const navItems = document.querySelectorAll('.nav-item');
                if (navItems[sectionIndex[section]]) {
                    navItems[sectionIndex[section]].classList.add('active');
                    const other = document.querySelector('.desktop-sidebar') ? document.querySelector('.mobile-sidebar .nav-menu') : document.querySelector('.desktop-sidebar .nav-menu');
                    if (other && other.children[sectionIndex[section]]) other.children[sectionIndex[section]].classList.add('active');
                }
            }
            document.getElementById('sb-date-input').value = sbDate;
            attachSBClickHandlers();
            attachMyScheduleHandlers();
            document.getElementById('sbAddScheduleBtn')?.addEventListener('click', openAddScheduleModal);
            setupPurposeDropdown('sb-purpose', 'sb-purpose-custom-wrap', 'sb-purpose-custom', null);
            setupPurposeDropdown('edit-booking-purpose-select', 'edit-booking-purpose-custom-wrap', 'edit-booking-purpose-custom', 'edit-booking-purpose');
            setupPurposeDropdown('edit-schedule-purpose-select', 'edit-schedule-purpose-custom-wrap', 'edit-schedule-purpose-custom', 'edit-schedule-purpose');
            setupPurposeDropdown('as-purpose-select', 'as-purpose-custom-wrap', 'as-purpose-custom', 'as-purpose');
        });
    </script>
</body>

</html>