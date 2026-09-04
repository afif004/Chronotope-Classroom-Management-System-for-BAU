<?php
require_once 'db_config.php';
require_once 'room_status.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'cr') {
    header('Location: login.php');
    exit();
}

$cr_id = $_SESSION['user_id'];

// Fetch CR details: assigned degree, level, semester, group, email, phone, full_name
$stmt = $pdo->prepare("
    SELECT u.*, dp.name AS degree_name, dp.total_levels, dp.semesters_per_level
    FROM users u
    LEFT JOIN degree_programs dp ON u.degree_program_id = dp.id
    WHERE u.id = ?
");
$stmt->execute([$cr_id]);
$cr = $stmt->fetch();
if (!$cr || !$cr['degree_program_id']) {
    die("CR profile incomplete. Please contact administrator.");
}

$assigned_degree_id = $cr['degree_program_id'];
$assigned_level = $cr['assigned_level'];
$assigned_semester = $cr['assigned_semester'];
$assigned_group = $cr['group_name'] ?: null;
$cr_name = $cr['full_name'];
$cr_name_short = implode(' ', array_slice(explode(' ', $cr_name), 0, 2));
$cr_email = $cr['email'];
$cr_phone = $cr['phone'];

// Helper functions
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
if (!function_exists('tsOverlaps')) {
    function tsOverlaps($start1, $end1, $start2, $end2)
    {
        return $start1 < $end2 && $start2 < $end1;
    }
}

// Fetch common data
require_once __DIR__ . '/includes/functions.php';

$timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();
$allFaculties = $pdo->query("SELECT id, name, code FROM faculties WHERE status='active' ORDER BY name")->fetchAll();

$sb_view = $_GET['view'] ?? 'week';
$sb_date = $_GET['date'] ?? date('Y-m-d');
$sb_section = $_GET['section'] ?? 'dashboard';

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

if (!function_exists('roomStatusForDate')) {
    function roomStatusForDate($pdo, $date, $rooms, $timeSlots) {
        $dayOfWeek = date('N', strtotime($date));
        $statuses = [];
        foreach ($rooms as $room) {
            $statuses[$room['id']] = ['slots' => [], 'bookings' => [], 'classes' => []];
        }

        // Bookings (unchanged – uses users table for booked_by)
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

        // Classes – CORRECTED JOIN to use teachers table
        $stmt = $pdo->prepare("
            SELECT cs.*, t.full_name AS teacher_name, t.phone AS teacher_phone,
                   r.id AS room_id, dp.name AS degree_name, t.id AS teacher_id
            FROM course_schedule cs
            JOIN teachers t ON cs.teacher_id = t.id        -- ← changed from 'users u' to 'teachers t'
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

        // Slot occupancy logic (unchanged)
        foreach ($rooms as $room) {
            $rid = $room['id'];
            $bookings = $statuses[$rid]['bookings'];
            $classes  = $statuses[$rid]['classes'];

            foreach ($timeSlots as $ts) {
                $slotStart = $ts['start_time'];
                $slotEnd   = $ts['end_time'];
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
                                'cr_phone' => 'N/A'
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
        usort($occupied, function($a, $b) {
    return strcmp($a[0], $b[0]);
});
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

// Notification functions for CRs of same group
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

// AJAX handlers
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] == 'mark_notification_read') {
        $notification_id = $_POST['notification_id'] ?? 0;
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $cr_id]);
        echo json_encode(['success' => true]);
        exit();
    }

    if ($_GET['ajax'] == 'get_course_department') {
        $course_id = (int) ($_GET['course_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT department_id FROM courses WHERE id = ?");
        $stmt->execute([$course_id]);
        $deptId = $stmt->fetchColumn();
        echo json_encode(['department_id' => $deptId ? (int) $deptId : null]);
        exit();
    }

    if ($_GET['ajax'] == 'refresh_sb_grid') {
        // Same as before (unchanged)
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
        if ($view === 'week') { ?>
            <div class="sb-week-actions"></div>
            <div class="sb-week-wrapper">
                <div class="sb-grid-scroll">
                    <table class="sb-week-table">
                        <thead>
                            <tr>
                                <th class="sb-room-col">Room</th><?php foreach ($sb_weekDays as $wd): ?>
                                    <th class="<?= $wd['is_today'] ? 'sb-today-col' : '' ?>"><?= $wd['day_name'] ?><br><span
                                            class="sb-day-num"><?= $wd['day_number'] ?></span></th><?php endforeach; ?>
                            <tr>
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
        <?php } else { ?>
            <div class="sb-day-heading">
                <span><?= date('l, F j, Y', strtotime($sb_date)) ?><?php if ($sb_date < date('Y-m-d')): ?> <span
                            class="sb-past-badge">Past Date — Read Only</span><?php endif; ?></span>
            </div>
            <div class="sb-grid-scroll">
                <table class="sb-day-table">
                    <thead>
                        <tr>
                            <th class="sb-room-col">Room</th><?php foreach ($timeSlots as $ts): ?>
                                <th class="sb-slot-th"><?= date('g:i', strtotime($ts['start_time'])) ?><span
                                        class="sb-slot-sub"><?= htmlspecialchars($ts['slot_name']) ?></span></th><?php endforeach; ?>
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
                                                class="sb-slot-label sb-class-label"><?= htmlspecialchars($data['course_code'] ?? 'Class') ?></span>
                                        <?php elseif ($status === 'booked'): ?><span class="sb-slot-label sb-booked-label">Booked</span>
                                        <?php else: ?><span class="sb-slot-label">Past</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php }
        $html = ob_get_clean();
        echo json_encode(['success' => true, 'html' => $html]);
        exit();
    }

    // --------------------------------------------------------------
    // BOOKING AJAX (using teachers table)
    // --------------------------------------------------------------
    if ($_GET['ajax'] == 'sb_book_room') {
        $room_id = (int) ($_POST['room_id'] ?? 0);
        $booking_date = $_POST['booking_date'] ?? '';
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $teacher_id = (int) ($_POST['teacher_id'] ?? 0);

        if (!$room_id || !$booking_date || !$start_time || !$end_time || !$purpose || !$course_id || !$teacher_id) {
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

        // Use CR's assigned group (read-only)
        $degree_program_id = $assigned_degree_id;
        $level = $assigned_level;
        $semester_num = $assigned_semester;
        $group_name = $assigned_group;

        // Verify semester active on booking date
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
            echo json_encode(['success' => false, 'error' => 'No active semester found for your group on this date.']);
            exit();
        }

        // Verify course belongs to CR's degree/level/semester
        $courseCheck = $pdo->prepare("
            SELECT id, department_id FROM courses
            WHERE id = ? AND degree_program_id = ? AND level = ? AND semester = ? AND status = 'active'
        ");
        $courseCheck->execute([$course_id, $degree_program_id, $level, $semester_num]);
        $course = $courseCheck->fetch();
        if (!$course) {
            echo json_encode(['success' => false, 'error' => 'Selected course does not match your degree/level/semester.']);
            exit();
        }

        // Verify teacher belongs to the course's department (using teachers table)
        $teacherCheck = $pdo->prepare("
            SELECT t.id, t.department_id, c.department_id AS course_dept
            FROM teachers t
            CROSS JOIN courses c
            WHERE t.id = ? AND c.id = ?
        ");
        $teacherCheck->execute([$teacher_id, $course_id]);
        $teacher = $teacherCheck->fetch();
        if (!$teacher) {
            echo json_encode(['success' => false, 'error' => 'Selected teacher does not exist.']);
            exit();
        }
        if ($course['department_id'] !== null && $teacher['department_id'] != $course['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Teacher must belong to the same department as the course.']);
            exit();
        }

        $current_user_id = $cr_id;
        $availability = isRoomAvailable($pdo, $room_id, $booking_date, $start_time, $end_time);

        if (!$availability['available']) {
            $conflict = $availability['conflicts'][0] ?? [];
            $conflictDetail = ($conflict['type'] === 'one_time_booking')
                ? "Already booked by {$conflict['booked_by']} for \"{$conflict['purpose']}\" at {$conflict['time']}"
                : "Scheduled class: {$conflict['course']} by {$conflict['teacher']} at {$conflict['time']}";
            echo json_encode(['success' => false, 'error' => $conflictDetail]);
            exit();
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO room_bookings
                    (room_id, booked_by, teacher_id, course_id, booking_date, start_time, end_time, purpose,
                     degree_program_id, level, semester, group_name, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'booked', NOW())
            ");
            $stmt->execute([
                $room_id,
                $current_user_id,
                $teacher_id,
                $course_id,
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

            // Log activity
            $actStmt = $pdo->prepare("
                INSERT INTO activity_log (user_id, action, entity_type, entity_id, room_id, details, ip_address)
                VALUES (?, 'book_room', 'room_booking', ?, ?, ?, ?)
            ");
            $actStmt->execute([
                $current_user_id,
                $new_booking_id,
                $room_id,
                json_encode(['purpose' => $final_purpose, 'date' => $booking_date, 'time' => "$start_time - $end_time", 'course_id' => $course_id, 'teacher_id' => $teacher_id]),
                $_SERVER['REMOTE_ADDR'] ?? ''
            ]);

            // Notify other CRs of same group
            $newBooking = [
                'id' => $new_booking_id,
                'degree_program_id' => $degree_program_id,
                'level' => $level,
                'semester' => $semester_num,
                'group_name' => $group_name,
                'booking_date' => $booking_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'purpose' => $final_purpose
            ];
            notifyCRsAboutBookingChange($pdo, $newBooking, 'created');

            $bookerStmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE id = ?");
            $bookerStmt->execute([$current_user_id]);
            $booker = $bookerStmt->fetch();

            // Fetch teacher name for response
            $teacherNameStmt = $pdo->prepare("SELECT full_name FROM teachers WHERE id = ?");
            $teacherNameStmt->execute([$teacher_id]);
            $teacherName = $teacherNameStmt->fetchColumn();

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
                    'course_id' => $course_id,
                    'teacher_id' => $teacher_id,
                    'teacher_name' => $teacherName
                ],
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // --------------------------------------------------------------
    // EDIT BOOKING (using teachers table)
    // --------------------------------------------------------------
    if ($_GET['ajax'] == 'edit_booking') {
        $booking_id = (int) ($_POST['booking_id'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $purpose_custom = trim($_POST['purpose_custom'] ?? '');
        $start_time = $_POST['start_time'] ?? '';
        $end_time = $_POST['end_time'] ?? '';
        $course_id = (int) ($_POST['course_id'] ?? 0);
        $teacher_id = (int) ($_POST['teacher_id'] ?? 0);

        if (!$booking_id || !$start_time || !$end_time || !$course_id || !$teacher_id) {
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

        $stmt = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? AND booked_by = ? AND status = 'booked'");
        $stmt->execute([$booking_id, $cr_id]);
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

        // Verify course belongs to CR's group
        $courseCheck = $pdo->prepare("
            SELECT id, department_id FROM courses
            WHERE id = ? AND degree_program_id = ? AND level = ? AND semester = ? AND status = 'active'
        ");
        $courseCheck->execute([$course_id, $assigned_degree_id, $assigned_level, $assigned_semester]);
        $course = $courseCheck->fetch();
        if (!$course) {
            echo json_encode(['success' => false, 'error' => 'Selected course does not match your degree/level/semester.']);
            exit();
        }

        // Verify teacher belongs to course department
        $teacherCheck = $pdo->prepare("
            SELECT t.id, t.department_id, c.department_id AS course_dept
            FROM teachers t
            CROSS JOIN courses c
            WHERE t.id = ? AND c.id = ?
        ");
        $teacherCheck->execute([$teacher_id, $course_id]);
        $teacher = $teacherCheck->fetch();
        if (!$teacher) {
            echo json_encode(['success' => false, 'error' => 'Selected teacher does not exist.']);
            exit();
        }
        if ($course['department_id'] !== null && $teacher['department_id'] != $course['department_id']) {
            echo json_encode(['success' => false, 'error' => 'Teacher must belong to the same department as the course.']);
            exit();
        }

        // Check availability (excluding current booking)
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
                    purpose = ?, start_time = ?, end_time = ?, course_id = ?, teacher_id = ?
                WHERE id = ?
            ")->execute([$final_purpose, $start_time, $end_time, $course_id, $teacher_id, $booking_id]);
            echo json_encode(['success' => true, 'message' => 'Booking updated successfully.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        }
        exit();
    }

    // --------------------------------------------------------------
    // AJAX endpoints for dynamic dropdowns (using teachers)
    // --------------------------------------------------------------
    if ($_GET['ajax'] == 'get_courses_for_booking_cr') {
        $degree_id = (int) ($_GET['degree_program_id'] ?? 0);
        $level = (int) ($_GET['level'] ?? 0);
        $semester = (int) ($_GET['semester'] ?? 0);
        if (!$degree_id || !$level || !$semester) {
            echo json_encode([]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT id, course_code, course_name, department_id
            FROM courses
            WHERE degree_program_id = ? AND level = ? AND semester = ? AND status = 'active'
            ORDER BY course_code
        ");
        $stmt->execute([$degree_id, $level, $semester]);
        echo json_encode($stmt->fetchAll());
        exit();
    }

    if ($_GET['ajax'] == 'get_teachers_for_course') {
        $course_id = (int) ($_GET['course_id'] ?? 0);
        if (!$course_id) {
            echo json_encode([]);
            exit();
        }
        // Get course department
        $deptStmt = $pdo->prepare("SELECT department_id FROM courses WHERE id = ?");
        $deptStmt->execute([$course_id]);
        $deptId = $deptStmt->fetchColumn();

        if ($deptId) {
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, phone
                FROM teachers
                WHERE department_id = ?
                ORDER BY full_name
            ");
            $stmt->execute([$deptId]);
        } else {
            // Course has no department – show all teachers
            $stmt = $pdo->prepare("
                SELECT id, full_name, email, phone
                FROM teachers
                ORDER BY full_name
            ");
            $stmt->execute();
        }
        echo json_encode($stmt->fetchAll());
        exit();
    }

    // Add a new teacher on the fly
    if ($_GET['ajax'] == 'add_teacher') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $department_id = (int) ($_POST['department_id'] ?? 0);

        if (empty($full_name) || !$department_id) {
            echo json_encode(['success' => false, 'error' => 'Name and department are required.']);
            exit();
        }

        // Check duplicate by email or phone (only if provided)
        $where = [];
        $params = [];
        if (!empty($email)) {
            $where[] = "email = ?";
            $params[] = $email;
        }
        if (!empty($phone)) {
            $where[] = "phone = ?";
            $params[] = $phone;
        }

        if (!empty($where)) {
            $checkSql = "SELECT id FROM teachers WHERE " . implode(" OR ", $where) . " LIMIT 1";
            $exists = $pdo->prepare($checkSql);
            $exists->execute($params);
            if ($exists->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Teacher with this email or phone already exists.']);
                exit();
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO teachers (full_name, email, phone, department_id)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$full_name, !empty($email) ? $email : null, !empty($phone) ? $phone : null, $department_id]);
        $new_id = $pdo->lastInsertId();

        echo json_encode([
            'success' => true,
            'teacher' => [
                'id' => (int) $new_id,
                'full_name' => $full_name,
                'email' => $email,
                'phone' => $phone,
                'department_id' => $department_id
            ]
        ]);
        exit();
    }

    // other AJAX endpoints (unchanged: get_booking_details, profile endpoints)
    if ($_GET['ajax'] == 'get_booking_details') {
        $booking_id = (int) ($_GET['id'] ?? 0);
        if (!$booking_id) {
            echo json_encode(['success' => false]);
            exit();
        }
        $stmt = $pdo->prepare("
            SELECT rb.*, r.room_name, b.name as building_name, c.course_code, c.course_name,
                   t.full_name AS teacher_name, t.email AS teacher_email, t.phone AS teacher_phone
            FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            LEFT JOIN courses c ON rb.course_id = c.id
            LEFT JOIN teachers t ON rb.teacher_id = t.id
            WHERE rb.id = ? AND rb.booked_by = ?
        ");
        $stmt->execute([$booking_id, $cr_id]);
        $booking = $stmt->fetch();
        if ($booking) {
            echo json_encode(['success' => true, 'booking' => $booking]);
        } else {
            echo json_encode(['success' => false]);
        }
        exit();
    }

    // Profile AJAX endpoints (unchanged)
    if ($_GET['ajax'] == 'check_semester_end') {
        $stmt = $pdo->prepare("
            SELECT end_date FROM semesters
            WHERE degree_program_id = ? AND level = ? AND semester_num = ?
              AND (group_name = ? OR (group_name IS NULL AND ? IS NULL))
            ORDER BY end_date DESC LIMIT 1
        ");
        $stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group, $assigned_group]);
        $sem = $stmt->fetch();
        $ended = $sem && strtotime($sem['end_date']) < strtotime(date('Y-m-d'));
        echo json_encode(['ended' => $ended, 'end_date' => $sem['end_date'] ?? null]);
        exit();
    }
    if ($_GET['ajax'] == 'get_target_level_semesters') {
        $stmt = $pdo->prepare("
            SELECT DISTINCT level, semester_num
            FROM semesters
            WHERE degree_program_id = ?
              AND (level > ? OR (level = ? AND semester_num > ?))
            ORDER BY level, semester_num
        ");
        $stmt->execute([$assigned_degree_id, $assigned_level, $assigned_level, $assigned_semester]);
        $options = $stmt->fetchAll();
        echo json_encode($options);
        exit();
    }
    if ($_GET['ajax'] == 'get_groups_for_target') {
        $level = (int) ($_GET['level'] ?? 0);
        $semester = (int) ($_GET['semester'] ?? 0);
        if (!$level || !$semester) {
            echo json_encode([]);
            exit();
        }
        $stmt = $pdo->prepare("SELECT DISTINCT group_name FROM semesters WHERE degree_program_id = ? AND level = ? AND semester_num = ? AND group_name IS NOT NULL AND group_name != '' ORDER BY group_name");
        $stmt->execute([$assigned_degree_id, $level, $semester]);
        $groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($groups);
        exit();
    }
    if ($_GET['ajax'] == 'update_basic_profile') {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $errors = [];
        if (empty($full_name))
            $errors[] = "Full name is required.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            $errors[] = "Invalid email format.";
        if (!preg_match('/^01[3-9]\d{8}$/', $phone))
            $errors[] = "Invalid Bangladeshi mobile number (01XXXXXXXXX).";
        if (!empty($errors)) {
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit();
        }
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $cr_id]);
        if ($check->fetch()) {
            echo json_encode(['success' => false, 'errors' => ['Email already used by another account.']]);
            exit();
        }
        $old = ['full_name' => $cr['full_name'], 'email' => $cr['email'], 'phone' => $cr['phone']];
        $update = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?");
        $update->execute([$full_name, $email, $phone, $cr_id]);
        $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'profile_edit', 'user', ?, ?, ?)");
        $log->execute([$cr_id, $cr_id, json_encode(['old' => $old, 'new' => ['full_name' => $full_name, 'email' => $email, 'phone' => $phone]]), $_SERVER['REMOTE_ADDR'] ?? '']);
        $_SESSION['full_name'] = $full_name;
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully.']);
        exit();
    }
    if ($_GET['ajax'] == 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (empty($current) || empty($new) || empty($confirm)) {
            echo json_encode(['success' => false, 'error' => 'All password fields are required.']);
            exit();
        }
        if ($new !== $confirm) {
            echo json_encode(['success' => false, 'error' => 'New passwords do not match.']);
            exit();
        }
        if (strlen($new) < 6) {
            echo json_encode(['success' => false, 'error' => 'Password must be at least 6 characters.']);
            exit();
        }
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$cr_id]);
        $user = $stmt->fetch();
        if (!password_verify($current, $user['password'])) {
            echo json_encode(['success' => false, 'error' => 'Current password is incorrect.']);
            exit();
        }
        $new_hash = password_hash($new, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update->execute([$new_hash, $cr_id]);
        $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'profile_edit', 'user', ?, 'Password changed', ?)");
        $log->execute([$cr_id, $cr_id, $_SERVER['REMOTE_ADDR'] ?? '']);
        echo json_encode(['success' => true, 'message' => 'Password changed successfully.']);
        exit();
    }
    if ($_GET['ajax'] == 'update_semester_assignment') {
        $new_level = (int) ($_POST['level'] ?? 0);
        $new_semester = (int) ($_POST['semester'] ?? 0);
        $new_group = trim($_POST['group_name'] ?? '') ?: null;
        $stmt = $pdo->prepare("SELECT end_date FROM semesters WHERE degree_program_id = ? AND level = ? AND semester_num = ? AND (group_name = ? OR (group_name IS NULL AND ? IS NULL)) ORDER BY end_date DESC LIMIT 1");
        $stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group, $assigned_group]);
        $current_sem = $stmt->fetch();
        if (!$current_sem || strtotime($current_sem['end_date']) >= strtotime(date('Y-m-d'))) {
            echo json_encode(['success' => false, 'error' => 'You cannot change level/semester before current semester ends.']);
            exit();
        }
        $stmt = $pdo->prepare("SELECT 1 FROM semesters WHERE degree_program_id = ? AND level = ? AND semester_num = ? AND (group_name = ? OR (group_name IS NULL AND ? IS NULL)) LIMIT 1");
        $stmt->execute([$assigned_degree_id, $new_level, $new_semester, $new_group, $new_group]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Selected level/semester/group combination does not exist.']);
            exit();
        }
        $old = ['level' => $assigned_level, 'semester' => $assigned_semester, 'group' => $assigned_group];
        $update = $pdo->prepare("UPDATE users SET assigned_level = ?, assigned_semester = ?, group_name = ? WHERE id = ?");
        $update->execute([$new_level, $new_semester, $new_group, $cr_id]);
        $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?, 'profile_edit', 'user', ?, ?, ?)");
        $log->execute([$cr_id, $cr_id, json_encode(['old' => $old, 'new' => ['level' => $new_level, 'semester' => $new_semester, 'group' => $new_group]]), $_SERVER['REMOTE_ADDR'] ?? '']);
        $_SESSION['assigned_level'] = $new_level;
        $_SESSION['assigned_semester'] = $new_semester;
        $_SESSION['group_name'] = $new_group;
        echo json_encode(['success' => true, 'message' => 'Semester/group updated. Page will reload.']);
        exit();
    }

    if ($_GET['ajax'] == 'get_department_name') {
        $dept_id = (int) ($_GET['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM departments WHERE id = ?");
        $stmt->execute([$dept_id]);
        $name = $stmt->fetchColumn();
        echo json_encode(['name' => $name ?: '']);
        exit();
    }
    exit();
}

// Handle POST requests (free_room)
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'free_room') {
    $booking_id = $_POST['booking_id'];
    $reason = $_POST['reason'] ?? '';
    try {
        $stmt = $pdo->prepare("SELECT * FROM room_bookings WHERE id = ? AND booked_by = ? AND status='booked'");
        $stmt->execute([$booking_id, $cr_id]);
        $booking = $stmt->fetch();
        if ($booking) {
            $pdo->prepare("UPDATE room_bookings SET status = 'cancelled', cancelled_at = NOW(), cancelled_by = ?, cancellation_reason = ? WHERE id = ?")
                ->execute([$cr_id, $reason, $booking_id]);
            $booking['cancellation_reason'] = $reason;
            notifyCRsAboutBookingChange($pdo, $booking, 'cancelled');
            $message = "Booking cancelled successfully!";
        } else {
            $error = "Booking not found or not yours.";
        }
    } catch (PDOException $e) {
        $error = "Failed to cancel booking: " . $e->getMessage();
    }
}

// Fetch data for dashboard (original, unchanged)
$today = date('Y-m-d');
$current_day = date('N');

$group_schedule = [];

$stmt = $pdo->prepare("
    SELECT cs.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
           dp.name as degree_name, 'schedule' as source_type
    FROM course_schedule cs
    JOIN rooms r ON cs.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
    WHERE cs.degree_program_id = ? AND cs.level = ? AND cs.semester = ?
      AND (cs.group_name = ? OR cs.group_name IS NULL OR cs.group_name = '')
      AND cs.status = 'scheduled'
    ORDER BY cs.day_of_week, cs.start_time
");
$stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group]);
$group_course_schedules = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT rb.*, r.room_name, r.room_number, r.room_type, b.name as building_name,
           dp.name as degree_name, 'booking' as source_type,
           DAYOFWEEK(rb.booking_date) as mysql_day
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    LEFT JOIN degree_programs dp ON rb.degree_program_id = dp.id
    WHERE rb.degree_program_id = ? AND rb.level = ? AND rb.semester = ?
      AND (rb.group_name = ? OR rb.group_name IS NULL OR rb.group_name = '')
      AND rb.status = 'booked'
    ORDER BY rb.booking_date, rb.start_time
");
$stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group]);
$group_booking_schedules = $stmt->fetchAll();

foreach ($group_booking_schedules as &$booking) {
    $booking['day_of_week'] = ($booking['mysql_day'] + 5) % 7 + 1;
    $booking['course_code'] = 'BOOKED';
    $booking['course_name'] = $booking['purpose'];
}

$group_schedule = array_merge($group_course_schedules, $group_booking_schedules);
usort($group_schedule, function ($a, $b) {
    if ($a['day_of_week'] != $b['day_of_week'])
        return $a['day_of_week'] - $b['day_of_week'];
    return strcmp($a['start_time'], $b['start_time']);
});

$todays_classes = array_filter($group_schedule, function ($item) use ($current_day) {
    return $item['day_of_week'] == $current_day;
});

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
$stmt->execute([$cr_id]);
$my_bookings = $stmt->fetchAll();

$notifications = [];
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$cr_id]);
$notifications = $stmt->fetchAll();

$unread_count = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$cr_id]);
$result = $stmt->fetch();
$unread_count = $result['count'];

$stats = [
    'todays_classes' => count($todays_classes),
    'total_schedules' => count($group_schedule),
    'active_bookings' => count(array_filter($my_bookings, function ($b) {
        return $b['status'] == 'booked'; })),
    'unread_notifications' => $unread_count
];

$week_start = date('Y-m-d', strtotime('monday this week'));
$week_dates = [];
for ($i = 0; $i < 7; $i++) {
    $week_dates[] = date('Y-m-d', strtotime($week_start . " +$i days"));
}

$week_bookings = [];
$stmt = $pdo->prepare("
    SELECT * FROM room_bookings
    WHERE degree_program_id = ? AND level = ? AND semester = ?
      AND (group_name = ? OR group_name IS NULL OR group_name = '')
      AND status = 'booked' AND booking_date BETWEEN ? AND ?
    ORDER BY booking_date, start_time
");
$stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group, $week_start, date('Y-m-d', strtotime($week_start . ' +6 days'))]);
$week_bookings = $stmt->fetchAll();

$bookings_by_date = [];
foreach ($week_bookings as $b) {
    $bookings_by_date[$b['booking_date']][] = $b;
}

$week_course_schedules = [];
$stmt = $pdo->prepare("
    SELECT cs.*, r.room_name
    FROM course_schedule cs
    JOIN rooms r ON cs.room_id = r.id
    WHERE cs.degree_program_id = ? AND cs.level = ? AND cs.semester = ?
      AND (cs.group_name = ? OR cs.group_name IS NULL OR cs.group_name = '')
      AND cs.status = 'scheduled'
");
$stmt->execute([$assigned_degree_id, $assigned_level, $assigned_semester, $assigned_group]);
$week_course_schedules = $stmt->fetchAll();

$rooms = $pdo->query("
    SELECT r.*, b.name as building_name, f.floor_number
    FROM rooms r
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    ORDER BY b.name, r.room_name
")->fetchAll();

$degree_programs_all = $pdo->query("
    SELECT dp.*, f.name as faculty_name 
    FROM degree_programs dp 
    JOIN faculties f ON dp.faculty_id = f.id 
    ORDER BY f.name, dp.name
")->fetchAll();

$degree_sem_data = [];
foreach ($degree_programs_all as $dp) {
    $degree_sem_data[$dp['id']] = [
        'total_levels' => $dp['total_levels'],
        'semesters_per_level' => $dp['semesters_per_level'] ?? 2
    ];
}

// Purpose options (excluding Seminar)
$purpose_options = [
    'Theory Class',
    'Lab Class',
    'Class Test (Theory)',
    'Class Test (Lab)',
    'Midterm Exam',
    'Final Exam',
    'Make‑up Class',
    'Meeting',
    'Other'
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Representative Dashboard - CMS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* CSS remains exactly as in the original file (unchanged) – all styles present */
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

        .week-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }

        .week-day {
            background: var(--white);
            border-radius: var(--radius-sm);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .week-day-header {
            padding: 12px;
            background: var(--light);
            border-bottom: 1px solid var(--medium-gray);
            font-weight: 600;
            text-align: center;
        }

        .week-day-header.active {
            background: var(--primary);
            color: white;
        }

        .week-day-content {
            padding: 15px;
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

        .my-week-calendar {
            display: grid;
            grid-template-columns: 100px repeat(7, 1fr);
            gap: 2px;
            background: var(--medium-gray);
            border: 1px solid var(--medium-gray);
            border-radius: 8px;
            overflow: hidden;
        }

        .my-week-header {
            background: var(--light);
            padding: 8px 4px;
            font-weight: 700;
            text-align: center;
            font-size: 0.8rem;
        }

        .my-week-time {
            background: var(--light);
            padding: 6px 4px;
            font-weight: 600;
            text-align: right;
            font-size: 0.75rem;
        }

        .my-week-cell {
            background: var(--white);
            padding: 4px;
            min-height: 50px;
            cursor: pointer;
            transition: background 0.15s;
        }

        .my-week-cell:hover {
            background: #f8fafc;
        }

        .my-week-class {
            background: #fef2f2;
            border-radius: 4px;
            padding: 4px;
            margin-bottom: 2px;
            font-size: 0.7rem;
            border-left: 3px solid #ef4444;
        }

        .my-week-booking {
            background: #fffbeb;
            border-radius: 4px;
            padding: 4px;
            margin-bottom: 2px;
            font-size: 0.7rem;
            border-left: 3px solid #f59e0b;
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

            .week-grid {
                grid-template-columns: repeat(7, 1fr);
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

        .profile-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--medium-gray);
        }

        .profile-tab {
            padding: 10px 20px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-weight: 600;
        }

        .profile-tab.active {
            border-bottom-color: var(--primary);
            color: var(--primary);
        }

        .profile-pane {
            display: none;
        }

        .profile-pane.active {
            display: block;
        }

        .validation-error {
            color: var(--danger);
            font-size: 0.8rem;
            margin-top: 4px;
        }

        .field-success {
            border-color: var(--success) !important;
        }

        .field-error {
            border-color: var(--danger) !important;
        }

        .purpose-custom-field.hidden {
            display: none;
        }

        /* Styles for add teacher modal */
        #add-teacher-modal .modal {
            max-width: 500px;
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
                <li class="nav-item" onclick="showSection('profile', event)"><i class="fas fa-user-edit"></i> Edit
                    Profile</li>
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
                <li class="nav-item" onclick="showSection('profile', event)"><i class="fas fa-user-edit"></i> Edit
                    Profile</li>
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
                    <h1><?= htmlspecialchars($cr_name_short) ?>'s Dashboard</h1>
                    <p>Manage room bookings for <?= htmlspecialchars($cr['degree_name']) ?> (Level
                        <?= $assigned_level ?>, Semester <?= $assigned_semester ?>)
                        <?= $assigned_group ? " - Group {$assigned_group}" : '' ?></p>
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
                        <div class="stat-label">Total Schedule Items</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['active_bookings'] ?></div>
                        <div class="stat-label">My Active Bookings</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?= $stats['unread_notifications'] ?></div>
                        <div class="stat-label">Notifications</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Today's Classes & Bookings</h3><button class="btn btn-outline btn-sm"
                            onclick="showSection('schedule', event)">View Full Schedule</button>
                    </div>
                    <?php if (empty($todays_classes)): ?>
                        <div class="empty-state">
                            <h3>No classes or bookings today</h3>
                        </div>
                    <?php else:
                        foreach ($todays_classes as $item): ?>
                            <div class="schedule-card">
                                <div class="schedule-time"><?= formatTime($item['start_time']) ?> -
                                    <?= formatTime($item['end_time']) ?></div>
                                <div class="schedule-title"><?= htmlspecialchars($item['course_name']) ?> <span
                                        class="badge badge-secondary"><?= $item['source_type'] == 'booking' ? 'Booking' : htmlspecialchars($item['course_code']) ?></span>
                                </div>
                                <div class="schedule-meta"><span>📍
                                        <?= htmlspecialchars($item['building_name'] . ' - ' . $item['room_name']) ?></span><span>👤
                                        <?= $item['source_type'] == 'booking' ? 'Booked by CR' : 'Teacher: ' . htmlspecialchars($item['teacher_name'] ?? '') ?></span>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                </div>
            </section>

            <!-- My Schedule Section -->
            <section id="schedule" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">My Group's Schedule</h3>
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
                                $start = substr($ts['start_time'], 0, 5);
                                $item = null;
                                foreach ($todays_classes as $c) {
                                    if ($c['start_time'] == $ts['start_time']) {
                                        $item = $c;
                                        break;
                                    }
                                }
                                ?>
                                <div class="schedule-time-slot"><?= date('g:i A', strtotime($ts['start_time'])) ?></div>
                                <div class="schedule-class-detail">
                                    <?php if ($item): ?>
                                        <strong><?= $item['source_type'] == 'booking' ? '📘 Booking' : htmlspecialchars($item['course_code']) ?></strong>
                                        - <?= htmlspecialchars($item['course_name']) ?><br>
                                        <small><?= htmlspecialchars($item['building_name'] . ' - ' . $item['room_name']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">No class/booking</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- My Week Calendar -->
                    <div id="schedule-week" style="display: none;">
                        <?php $day_names = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']; ?>
                        <div class="my-week-calendar">
                            <div class="my-week-header"></div>
                            <?php foreach ($week_dates as $idx => $date): ?>
                                <div class="my-week-header">
                                    <?= $day_names[$idx] ?><br><small><?= date('M j', strtotime($date)) ?></small></div>
                            <?php endforeach; ?>
                            <?php foreach ($timeSlots as $ts): ?>
                                <div class="my-week-time"><?= date('g:i', strtotime($ts['start_time'])) ?></div>
                                <?php for ($d = 0; $d < 7; $d++):
                                    $current_date = $week_dates[$d];
                                    $day_of_week = $d + 1;
                                    $cell_items = [];
                                    foreach ($week_course_schedules as $cs) {
                                        if ($cs['day_of_week'] == $day_of_week && tsOverlaps($ts['start_time'], $ts['end_time'], $cs['start_time'], $cs['end_time'])) {
                                            $cell_items[] = ['type' => 'class', 'data' => $cs];
                                        }
                                    }
                                    if (isset($bookings_by_date[$current_date])) {
                                        foreach ($bookings_by_date[$current_date] as $b) {
                                            if (tsOverlaps($ts['start_time'], $ts['end_time'], $b['start_time'], $b['end_time'])) {
                                                $cell_items[] = ['type' => 'booking', 'data' => $b];
                                            }
                                        }
                                    }
                                    ?>
                                    <div class="my-week-cell" data-date="<?= $current_date ?>"
                                        data-time-start="<?= $ts['start_time'] ?>" data-time-end="<?= $ts['end_time'] ?>">
                                        <?php foreach ($cell_items as $item): ?>
                                            <?php if ($item['type'] == 'class'):
                                                $c = $item['data']; ?>
                                                <div class="my-week-class">
                                                    <?= htmlspecialchars($c['course_code']) ?><br><small><?= htmlspecialchars($c['room_name']) ?></small>
                                                </div>
                                            <?php else:
                                                $b = $item['data']; ?>
                                                <div class="my-week-booking" data-booking-id="<?= $b['id'] ?>"
                                                    data-purpose="<?= htmlspecialchars($b['purpose']) ?>"
                                                    data-date="<?= $current_date ?>">
                                                    <?= htmlspecialchars($b['purpose']) ?><br><small><?= htmlspecialchars($b['room_name'] ?? 'Room') ?></small>
                                                </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endfor; ?>
                            <?php endforeach; ?>
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
                                                <?= formatTime($booking['end_time']) ?></td>
                                            <td><?= htmlspecialchars($booking['purpose']) ?></td>
                                            <td><span
                                                    class="badge badge-<?= $booking['status'] == 'booked' ? 'success' : ($booking['status'] == 'cancelled' ? 'warning' : 'secondary') ?>"><?= ucfirst($booking['status']) ?></span>
                                            </td>
                                            <td><?php if ($booking['status'] == 'booked' && $booking['booking_date'] >= date('Y-m-d')): ?><button
                                                        class="btn btn-sm btn-danger"
                                                        onclick="freeRoomModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['room_name']) ?>')">Cancel</button><?php else: ?><span
                                                        class="text-muted">-</span><?php endif; ?></td>
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
                                    <?= htmlspecialchars($dp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Faculty</label>
                        <select id="sb-faculty"
                            onchange="sbUpdateUrl({sb_faculty: this.value, sb_degree: '', sb_building: ''})">
                            <option value="">All Faculties</option>
                            <?php foreach ($allFaculties as $f): ?>
                                <option value="<?= $f['id'] ?>" <?= $sb_faculty_filter == $f['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label>Building</label>
                        <select id="sb-building"
                            onchange="sbUpdateUrl({sb_building: this.value, sb_faculty: '', sb_degree: ''})">
                            <option value="">All Buildings</option>
                            <?php foreach ($sb_buildings as $b): ?>
                                <option value="<?= $b['id'] ?>" data-faculty-id="<?= $b['faculty_id'] ?>"
                                    <?= $sb_building_filter == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['name']) ?></option>
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
                    <div class="sb-week-actions"></div>
                    <div class="sb-week-wrapper">
                        <div class="sb-grid-scroll">
                            <table class="sb-week-table">
                                <thead>
                                    <tr>
                                        <th class="sb-room-col">Room</th><?php foreach ($sb_weekDays as $wd): ?>
                                            <th class="<?= $wd['is_today'] ? 'sb-today-col' : '' ?>"><?= $wd['day_name'] ?><br><span
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
                                                    <div class="sb-occ-bar"><?php if ($pClass > 0): ?>
                                                            <div class="sb-bar-class" style="width:<?= $pClass ?>%"></div>
                                                        <?php endif; ?>            <?php if ($pBooked > 0): ?>
                                                            <div class="sb-bar-booked" style="width:<?= $pBooked ?>%"></div>
                                                        <?php endif; ?>            <?php if ($pAvail > 0): ?>
                                                            <div class="sb-bar-avail" style="width:<?= $pAvail ?>%"></div><?php endif; ?>
                                                    </div>
                                                    <div class="sb-cell-stats"><?php if ($classCount > 0): ?><span
                                                                class="sb-stat-class"><?= $classCount ?>c</span><?php endif; ?><?php if ($bookedCount > 0): ?><span
                                                                class="sb-stat-booked"><?= $bookedCount ?>b</span><?php endif; ?><?php if ($availCount > 0): ?><span
                                                                class="sb-stat-avail"><?= $availCount ?>f</span><?php endif; ?></div>
                                                </td>
                                            <?php endforeach; ?>
                                        <tr>
                                        <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="sb-day-heading">
                        <span><?= date('l, F j, Y', strtotime($sb_date)) ?><?php if ($sb_date < date('Y-m-d')): ?> <span
                                    class="sb-past-badge">Past Date — Read Only</span><?php endif; ?></span>
                    </div>
                    <div class="sb-grid-scroll">
                        <table class="sb-day-table">
                            <thead>
                                <tr>
                                    <th class="sb-room-col">Room</th><?php foreach ($timeSlots as $ts): ?>
                                        <th class="sb-slot-th"><?= date('g:i', strtotime($ts['start_time'])) ?><span
                                                class="sb-slot-sub"><?= htmlspecialchars($ts['slot_name']) ?></span></th>
                                    <?php endforeach; ?></td>
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
                                                        class="sb-slot-label sb-class-label"><?= htmlspecialchars($data['course_code'] ?? 'Class') ?></span>
                                                <?php elseif ($status === 'booked'): ?><span
                                                        class="sb-slot-label sb-booked-label">Booked</span>
                                                <?php else: ?><span class="sb-slot-label">Past</span>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Edit Profile Section (unchanged) -->
            <section id="profile" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Edit Profile</h3>
                    </div>
                    <div class="profile-tabs">
                        <div class="profile-tab active" data-tab="basic">Basic Info</div>
                        <div class="profile-tab" data-tab="password">Change Password</div>
                        <div class="profile-tab" data-tab="semester">Semester / Group</div>
                    </div>
                    <div id="profile-basic" class="profile-pane active">
                        <form id="basic-info-form">
                            <div class="form-group"><label class="form-label">Full Name</label><input type="text"
                                    id="full_name" class="form-control"
                                    value="<?= htmlspecialchars($cr['full_name']) ?>" required>
                                <div class="validation-error" id="name-error"></div>
                            </div>
                            <div class="form-group"><label class="form-label">Email</label><input type="email"
                                    id="email" class="form-control" value="<?= htmlspecialchars($cr['email']) ?>"
                                    required>
                                <div class="validation-error" id="email-error"></div>
                            </div>
                            <div class="form-group"><label class="form-label">Phone (Bangladeshi mobile)</label><input
                                    type="tel" id="phone" class="form-control"
                                    value="<?= htmlspecialchars($cr['phone']) ?>" required>
                                <div class="validation-error" id="phone-error"></div>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Profile</button>
                            <div id="basic-success" class="alert alert-success" style="display:none; margin-top:15px;">
                            </div>
                            <div id="basic-error" class="alert alert-danger" style="display:none; margin-top:15px;">
                            </div>
                        </form>
                    </div>
                    <div id="profile-password" class="profile-pane">
                        <form id="password-form">
                            <div class="form-group"><label class="form-label">Current Password</label><input
                                    type="password" id="current_password" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">New Password (min 6 chars)</label><input
                                    type="password" id="new_password" class="form-control" required></div>
                            <div class="form-group"><label class="form-label">Confirm New Password</label><input
                                    type="password" id="confirm_password" class="form-control" required></div>
                            <button type="submit" class="btn btn-primary">Change Password</button>
                            <div id="password-success" class="alert alert-success"
                                style="display:none; margin-top:15px;"></div>
                            <div id="password-error" class="alert alert-danger" style="display:none; margin-top:15px;">
                            </div>
                        </form>
                    </div>
                    <div id="profile-semester" class="profile-pane">
                        <div id="semester-status"></div>
                        <form id="semester-form">
                            <div class="form-group"><label class="form-label">Current Level</label><input type="text"
                                    class="form-control" readonly value="Level <?= $assigned_level ?>"></div>
                            <div class="form-group"><label class="form-label">Current Semester</label><input type="text"
                                    class="form-control" readonly value="Semester <?= $assigned_semester ?>"></div>
                            <div class="form-group"><label class="form-label">Current Group</label><input type="text"
                                    class="form-control" readonly
                                    value="<?= htmlspecialchars($assigned_group ?? 'None') ?>"></div>
                            <div id="semester-update-fields" style="display:none;">
                                <div class="form-group"><label class="form-label">New Level</label><select
                                        id="new_level" class="form-control" required></select></div>
                                <div class="form-group"><label class="form-label">New Semester</label><select
                                        id="new_semester" class="form-control" required disabled>
                                        <option>Select level first</option>
                                    </select></div>
                                <div class="form-group"><label class="form-label">New Group (optional)</label><select
                                        id="new_group" class="form-control">
                                        <option value="">All Groups</option>
                                    </select></div>
                                <button type="submit" class="btn btn-primary">Update Semester/Group</button>
                            </div>
                        </form>
                        <div id="semester-success" class="alert alert-success" style="display:none; margin-top:15px;">
                        </div>
                        <div id="semester-error" class="alert alert-danger" style="display:none; margin-top:15px;">
                        </div>
                    </div>
                </div>
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
                <h3 class="modal-title">Cancel Booking</h3><button class="modal-close"
                    onclick="closeFreeModal()">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="free_room"><input type="hidden" name="booking_id"
                    id="free-booking-id">
                <p>Are you sure you want to cancel the booking for <strong id="free-room-name"></strong>?</p>
                <p class="text-muted">Other class representatives of your group will be notified.</p>
                <div class="form-group"><label class="form-label">Reason (Optional)</label><input type="text"
                        name="reason" class="form-control" placeholder="e.g., Rescheduled"></div>
                <div style="display:flex; gap:12px; justify-content:space-between;"><button type="button"
                        class="btn btn-outline" onclick="closeFreeModal()">Cancel</button><button type="submit"
                        class="btn btn-danger">Cancel Booking</button></div>
            </form>
        </div>
    </div>

    <!-- Booking Modal (enhanced with Course & Teacher) -->
    <!-- Booking Modal (simplified) -->
<div class="modal-overlay" id="sb-book-modal">
    <div class="modal" style="max-width:550px;">
        <div class="modal-header">
            <h3 class="modal-title" id="sb-modal-title">Book Room</h3>
            <button class="modal-close" onclick="sbCloseBookModal()">×</button>
        </div>
        <div id="sb-modal-alert" style="display:none;padding:10px 20px;"><div class="alert alert-danger" id="sb-modal-alert-msg"></div></div>
        <div id="sb-modal-success" style="display:none;padding:10px 20px;"><div class="alert alert-success" id="sb-modal-success-msg"></div></div>
        <div id="sb-modal-form-wrap" style="padding:0 20px 20px;">
            <div class="form-row" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label class="form-label">Room</label>
                    <input type="text" id="sb-display-room" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="text" id="sb-display-date" class="form-control" readonly>
                </div>
            </div>
            <div class="form-row" style="grid-template-columns:1fr 1fr;">
                <div class="form-group">
                    <label class="form-label">Start Time</label>
                    <input type="time" id="sb-start-time" class="form-control" step="300" required>
                </div>
                <div class="form-group">
                    <label class="form-label">End Time</label>
                    <input type="time" id="sb-end-time" class="form-control" step="300" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Course *</label>
                <select id="sb-course" class="form-control" required>
                    <option value="">Select course...</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Teacher *</label>
                <select id="sb-teacher" class="form-control" required disabled>
                    <option value="">Select course first</option>
                </select>
            </div>
            <button type="button" class="btn btn-sm btn-outline mt-1" id="sb-add-teacher-btn" style="display:none;">+ Add new teacher</button>
            <div class="form-group">
                <label class="form-label">Purpose *</label>
                <select id="sb-purpose" class="form-control" required>
                    <option value="">-- Select purpose --</option>
                    <?php foreach ($purpose_options as $opt): ?>
                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="sb-purpose-custom-wrap" class="purpose-custom-field hidden">
                    <input type="text" id="sb-purpose-custom" class="form-control mt-2" placeholder="Enter custom purpose...">
                </div>
            </div>
            <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn btn-outline" onclick="sbCloseBookModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="sb-submit-btn" onclick="sbSubmitBooking()">
                    <span id="sb-submit-text">Book Room</span>
                </button>
            </div>
        </div>
    </div>
</div>

    <!-- Edit Booking Modal (enhanced with Course & Teacher) -->
    <div class="modal-overlay" id="edit-booking-modal">
        <div class="modal" style="max-width:600px;">
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
                <div class="form-group"><label class="form-label">Degree Program</label><input type="text"
                        class="form-control" readonly value="<?= htmlspecialchars($cr['degree_name']) ?>"></div>
                <div class="form-row">
                    <div class="form-group"><label>Level</label><input type="text" class="form-control" readonly
                            value="Level <?= $assigned_level ?>"></div>
                    <div class="form-group"><label>Semester</label><input type="text" class="form-control" readonly
                            value="Semester <?= $assigned_semester ?>"></div>
                </div>
                <div class="form-group"><label class="form-label">Group</label><input type="text" class="form-control"
                        readonly value="<?= $assigned_group ?: 'All Groups' ?>"></div>
                <div class="form-group"><label class="form-label">Course *</label><select id="edit-booking-course"
                        class="form-control" required>
                        <option value="">Loading...</option>
                    </select></div>
                <div class="form-group"><label class="form-label">Teacher *</label><select id="edit-booking-teacher"
                        class="form-control" required disabled>
                        <option value="">Select course first</option>
                    </select></div>
                <button type="button" class="btn btn-sm btn-outline mt-1" id="sb-add-teacher-btn-edit"
                    style="display:none;">+ Add new teacher</button>
                <div class="form-group"><label class="form-label">Purpose *</label><select
                        id="edit-booking-purpose-select" class="form-control" required>
                        <option value="">-- Select purpose --</option><?php foreach ($purpose_options as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="edit-booking-purpose-custom-wrap" class="purpose-custom-field hidden"><input type="text"
                            id="edit-booking-purpose-custom" class="form-control" placeholder="Enter custom purpose...">
                    </div><input type="hidden" id="edit-booking-purpose" name="purpose">
                </div>
                <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;"><button type="button"
                        class="btn btn-outline" onclick="closeEditBookingModal()">Cancel</button><button type="submit"
                        class="btn btn-primary">Save Changes</button></div>
            </form>
        </div>
    </div>

    <!-- Add Teacher Modal (hidden initially) -->
<div id="add-teacher-modal" class="modal-overlay" style="display: none;">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <h3>Add New Teacher</h3>
            <button class="modal-close" onclick="closeAddTeacherModal()">×</button>
        </div>
        <div class="modal-body" style="padding:0 20px 20px;">
            <div id="add-teacher-alert" style="display:none; margin-bottom:16px;"></div>
            <div class="form-group">
                <label class="form-label">Full Name *</label>
                <input type="text" id="new-teacher-name" class="form-control" placeholder="e.g. Dr. Smith">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" id="new-teacher-email" class="form-control" placeholder="teacher@university.edu">
            </div>
            <div class="form-group">
                <label class="form-label">Phone</label>
                <input type="text" id="new-teacher-phone" class="form-control" placeholder="01XXXXXXXXX">
            </div>
            <input type="hidden" id="new-teacher-dept" value="">
            <div style="display:flex; gap:12px; justify-content:flex-end; margin-top:16px;">
                <button type="button" class="btn btn-outline" onclick="closeAddTeacherModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm-add-teacher">Add Teacher</button>
            </div>
        </div>
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

    <script>
        const TODAY = new Date().toISOString().slice(0, 10);
        let sbView = '<?= $sb_view ?>';
        let sbDate = '<?= $sb_date ?>';
        let currentSlotEl = null;
        let currentFreeBlocks = [];
        const PURPOSE_OPTIONS = <?= json_encode($purpose_options) ?>;

        // ========== HAMBURGER MENU FIX ==========
        function toggleMobileSidebar() {
            const mobileSidebar = document.getElementById('mobileSidebar');
            const pageBlur = document.getElementById('pageBlur');
            mobileSidebar.classList.toggle('active');
            pageBlur.classList.toggle('active');
            document.body.style.overflow = mobileSidebar.classList.contains('active') ? 'hidden' : '';
        }

        function closeMobileSidebar() {
            const mobileSidebar = document.getElementById('mobileSidebar');
            const pageBlur = document.getElementById('pageBlur');
            mobileSidebar.classList.remove('active');
            pageBlur.classList.remove('active');
            document.body.style.overflow = '';
        }
        // ========================================

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
            document.getElementById('schedule-today').style.display = view === 'today' ? 'block' : 'none';
            document.getElementById('schedule-week').style.display = view === 'week' ? 'block' : 'none';
            document.getElementById('schedule-bookings').style.display = view === 'bookings' ? 'block' : 'none';
            document.querySelectorAll('#schedule .tab').forEach((t, i) => {
                const views = ['today', 'week', 'bookings'];
                t.classList.toggle('active', views[i] === view);
            });
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

        document.getElementById('sb-btn-prev')?.addEventListener('click', () => { let d = new Date(sbDate + 'T12:00:00'); d.setDate(d.getDate() + (sbView === 'week' ? -7 : -1)); sbUpdateUrl({ date: d.toISOString().slice(0, 10) }); });
        document.getElementById('sb-btn-next')?.addEventListener('click', () => { let d = new Date(sbDate + 'T12:00:00'); d.setDate(d.getDate() + (sbView === 'week' ? 7 : 1)); sbUpdateUrl({ date: d.toISOString().slice(0, 10) }); });
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
                        const heading = document.querySelector('.sb-day-heading');
                        heading.nextElementSibling.remove();
                        heading.insertAdjacentHTML('afterend', data.html);
                    }
                    document.getElementById('sb-date-input').value = today;
                    sbDate = today;
                    attachSBClickHandlers();
                }
            } catch (e) { console.error(e); }
            finally { if (gridContainer) gridContainer.style.opacity = '1'; }
        });

        // ========== ATTACH CLICK HANDLERS ==========
        function attachSBClickHandlers() {
            document.querySelectorAll('.sb-week-cell').forEach(cell => cell.addEventListener('click', () => sbUpdateUrl({ view: 'day', date: cell.dataset.date })));
            document.querySelectorAll('.sb-day-slot').forEach(slot => {
                slot.removeEventListener('click', handleSlotClick);
                slot.addEventListener('click', (e) => handleSlotClick(slot));
            });
        }
        // ===========================================

        function handleSlotClick(slot) {
            const status = slot.dataset.status;
            const detail = slot.dataset.detail ? JSON.parse(slot.dataset.detail) : null;
            if (status === 'booked' && detail && detail.booked_by_id == <?= $cr_id ?>) {
                openEditBookingModal({
                    id: detail.id,
                    room_name: slot.dataset.roomName,
                    building_name: slot.dataset.building,
                    booking_date: slot.dataset.date,
                    start_time: detail.start_time,
                    end_time: detail.end_time,
                    purpose: detail.purpose,
                    course_id: detail.course_id,
                    teacher_id: detail.teacher_id,
                    degree_program_id: detail.degree_program_id,
                    level: detail.level,
                    semester: detail.semester,
                    group_name: detail.group_name
                });
            } else if (status === 'class' || status === 'booked') {
                sbShowDetailModal(detail, slot.dataset.roomName, slot.dataset.building, slot.dataset.slotStart + '–' + slot.dataset.slotEnd);
            } else if (slot.classList.contains('sb-bookable')) {
                sbOpenBookModal(slot);
            }
        }

        document.addEventListener('click', (e) => {
            const myWeekBooking = e.target.closest('.my-week-booking');
            if (myWeekBooking && myWeekBooking.dataset.bookingId) {
                fetch(`?ajax=get_booking_details&id=${myWeekBooking.dataset.bookingId}`)
                    .then(r => r.json())
                    .then(data => { if (data.success) openEditBookingModal(data.booking); });
            }
        });

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
            document.getElementById('sb-course').innerHTML = '<option value="">Select course...</option>';
            document.getElementById('sb-teacher').innerHTML = '<option value="">Select course first</option>';
            document.getElementById('sb-teacher').disabled = true;
            document.getElementById('sb-add-teacher-btn').style.display = 'none';
            document.getElementById('sb-purpose').value = '';
            document.getElementById('sb-purpose-custom').value = '';
            document.getElementById('sb-purpose-custom-wrap').classList.add('hidden');
            document.getElementById('sb-modal-alert').style.display = 'none';
            document.getElementById('sb-modal-success').style.display = 'none';
            document.getElementById('sb-modal-form-wrap').style.display = '';
            document.getElementById('sb-submit-btn').disabled = false;
            document.getElementById('sb-submit-text').innerText = 'Book Room';
            loadCoursesForCR();
            document.getElementById('sb-book-modal').classList.add('active');
        }
        window.sbCloseBookModal = () => document.getElementById('sb-book-modal').classList.remove('active');
        document.getElementById('sb-book-modal').addEventListener('click', e => { if (e.target === document.getElementById('sb-book-modal')) sbCloseBookModal(); });
        document.getElementById('sb-detail-modal').addEventListener('click', e => { if (e.target === document.getElementById('sb-detail-modal')) e.target.classList.remove('active'); });

        async function loadCoursesForCR() {
            const degree_id = <?= $assigned_degree_id ?>;
            const level = <?= $assigned_level ?>;
            const semester = <?= $assigned_semester ?>;
            const resp = await fetch(`?ajax=get_courses_for_booking_cr&degree_program_id=${degree_id}&level=${level}&semester=${semester}`);
            const courses = await resp.json();
            const courseSelect = document.getElementById('sb-course');
            courseSelect.innerHTML = '<option value="">Select course</option>';
            courses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${c.course_code} - ${c.course_name}`;
                opt.dataset.dept = c.department_id || '';
                courseSelect.appendChild(opt);
            });
        }
        document.getElementById('sb-course').addEventListener('change', async function () {
            const courseId = this.value;
            const teacherSelect = document.getElementById('sb-teacher');
            const addBtn = document.getElementById('sb-add-teacher-btn');

            if (!courseId) {
                teacherSelect.innerHTML = '<option value="">Select course first</option>';
                teacherSelect.disabled = true;
                addBtn.style.display = 'none';
                return;
            }

            teacherSelect.disabled = true;
            teacherSelect.innerHTML = '<option value="">Loading teachers...</option>';
            const resp = await fetch(`?ajax=get_teachers_for_course&course_id=${courseId}`);
            const teachers = await resp.json();
            teacherSelect.innerHTML = '<option value="">Select teacher</option>';
            teachers.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = `${t.full_name}${t.email ? ' (' + t.email + ')' : ''}`;
                teacherSelect.appendChild(opt);
            });
            teacherSelect.disabled = false;

            // Get department ID from the selected course <option>
            const selectedOption = this.options[this.selectedIndex];
            let deptId = selectedOption?.dataset.dept || '';

            // If no department is set, try to fetch it via a separate AJAX call (fallback)
            if (!deptId) {
                const deptResp = await fetch(`?ajax=get_course_department&course_id=${courseId}`);
                const deptData = await deptResp.json();
                if (deptData.department_id) {
                    deptId = deptData.department_id;
                    // Update the dataset for future use
                    selectedOption.dataset.dept = deptId;
                }
            }

            if (deptId) {
                addBtn.style.display = 'inline-block';
                addBtn.dataset.deptId = deptId;
            } else {
                addBtn.style.display = 'none';
                console.warn('No department associated with this course – cannot add teacher.');
            }
        });

        // Helper to open Add Teacher modal
        window.openAddTeacherModal = async function (targetDropdownId, deptId) {
            if (!deptId) {
                alert('Cannot add teacher – no department associated with the selected course.');
                return;
            }

            const modalDiv = document.getElementById('add-teacher-modal');
            const deptInput = document.getElementById('new-teacher-dept');
            
            // Show loading or ID while fetching name
            deptInput.value = 'Loading department name...';
            modalDiv.dataset.deptId = deptId;
            modalDiv.dataset.targetDropdownId = targetDropdownId;
            modalDiv.style.display = 'flex';

            // Clear previous inputs
            document.getElementById('new-teacher-name').value = '';
            document.getElementById('new-teacher-email').value = '';
            document.getElementById('new-teacher-phone').value = '';

            try {
                const resp = await fetch(`?ajax=get_department_name&id=${deptId}`);
                const data = await resp.json();
                deptInput.value = data.name || ('ID: ' + deptId);
            } catch (e) {
                deptInput.value = 'ID: ' + deptId;
            }
        };

// Add new teacher functionality
document.getElementById('sb-add-teacher-btn')?.addEventListener('click', function() {
    const deptId = this.dataset.deptId;
    if (!deptId) {
        // Should not happen because button only appears when deptId exists
        showInlineMessage('add-teacher-alert', 'danger', 'No department associated with the selected course.');
        return;
    }

    const modalDiv = document.getElementById('add-teacher-modal');
    // Set hidden department ID
    document.getElementById('new-teacher-dept').value = deptId;
    // Clear form fields
    document.getElementById('new-teacher-name').value = '';
    document.getElementById('new-teacher-email').value = '';
    document.getElementById('new-teacher-phone').value = '';
    // Clear any previous messages
    const alertDiv = document.getElementById('add-teacher-alert');
    alertDiv.style.display = 'none';
    alertDiv.innerHTML = '';

    // Show modal
    modalDiv.classList.add('active');
    modalDiv.style.display = 'flex';
});

// Helper to show inline messages inside the modal
function showInlineMessage(containerId, type, message) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
    container.style.display = 'block';
}

function closeAddTeacherModal() {
    const modalDiv = document.getElementById('add-teacher-modal');
    modalDiv.classList.remove('active');
    modalDiv.style.display = 'none';
}

// Close modal when clicking outside
document.getElementById('add-teacher-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddTeacherModal();
});

// Confirm add teacher
// Confirm add teacher
document.getElementById('confirm-add-teacher')?.addEventListener('click', async function() {
    const modalDiv = document.getElementById('add-teacher-modal');
    const deptId = document.getElementById('new-teacher-dept').value;
    const name = document.getElementById('new-teacher-name').value.trim();
    const email = document.getElementById('new-teacher-email').value.trim();
    const phone = document.getElementById('new-teacher-phone').value.trim();

    if (!name) {
        showInlineMessage('add-teacher-alert', 'danger', 'Please enter the teacher’s full name.');
        return;
    }

    const formData = new FormData();
    formData.append('full_name', name);
    formData.append('email', email);
    formData.append('phone', phone);
    formData.append('department_id', deptId);

    const button = this;
    button.disabled = true;
    button.textContent = 'Adding...';

    try {
        const resp = await fetch('?ajax=add_teacher', { method: 'POST', body: formData });
        const data = await resp.json();

        if (data.success) {
            showInlineMessage('add-teacher-alert', 'success',
                'Teacher <strong>' + data.teacher.full_name + '</strong> added successfully!');
            // Refresh the teacher dropdown for the current course (only the booking modal's dropdown)
            const courseSelect = document.getElementById('sb-course');
            const courseId = courseSelect.value;
            const teacherSelect = document.getElementById('sb-teacher');
            
            // Fetch teachers for this course
            const tResp = await fetch(`?ajax=get_teachers_for_course&course_id=${courseId}`);
            const teachers = await tResp.json();

            // Rebuild the dropdown completely – avoids any duplication
            teacherSelect.innerHTML = '<option value="">Select teacher</option>';
            teachers.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = `${t.full_name}${t.email ? ' (' + t.email + ')' : ''}`;
                teacherSelect.appendChild(opt);
            });
            teacherSelect.disabled = false;

            // Select the newly added teacher
            if (data.teacher && data.teacher.id) {
                teacherSelect.value = data.teacher.id;
            }

            // Close modal after short delay
            setTimeout(() => {
                closeAddTeacherModal();
                // Reset alert for next use
                const alertDiv = document.getElementById('add-teacher-alert');
                alertDiv.innerHTML = '';
                alertDiv.style.display = 'none';
            }, 1500);
        } else {
            showInlineMessage('add-teacher-alert', 'danger', data.error || 'Failed to add teacher.');
        }
    } catch (e) {
        showInlineMessage('add-teacher-alert', 'danger', 'Network error. Please try again.');
    } finally {
        button.disabled = false;
        button.textContent = 'Add Teacher';
    }
});

        async function sbSubmitBooking() {
            if (!currentSlotEl) return;
            const courseId = document.getElementById('sb-course').value;
            const teacherId = document.getElementById('sb-teacher').value;
            const purposeSelect = document.getElementById('sb-purpose');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('sb-purpose-custom').value.trim();
            if (!courseId || !teacherId) { sbShowModalAlert('Course and Teacher are required.'); return; }
            if (purpose === 'Other' && !purposeCustom) { sbShowModalAlert('Please enter a custom purpose.'); return; }
            if (!purpose) { sbShowModalAlert('Please select a purpose.'); return; }
            const startTime = document.getElementById('sb-start-time').value;
            const endTime = document.getElementById('sb-end-time').value;
            if (startTime >= endTime) { sbShowModalAlert('End time must be after start time.'); return; }
            const submitBtn = document.getElementById('sb-submit-btn');
            const submitTxt = document.getElementById('sb-submit-text');
            submitBtn.disabled = true; submitTxt.innerText = 'Booking...';
            const formData = new FormData();
            formData.append('room_id', currentSlotEl.dataset.roomId);
            formData.append('booking_date', currentSlotEl.dataset.date);
            formData.append('start_time', startTime);
            formData.append('end_time', endTime);
            formData.append('purpose', purpose);
            formData.append('purpose_custom', purposeCustom);
            formData.append('course_id', courseId);
            formData.append('teacher_id', teacherId);
            try {
                const resp = await fetch(`?ajax=sb_book_room`, { method: 'POST', body: formData });
                const data = await resp.json();
                if (data.success) {
                    sbShowModalSuccess('Room booked successfully!');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    sbShowModalAlert(data.error || 'Could not book room.');
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
            <div class="sb-detail-row"><div class="sb-detail-label">Degree:</div><div class="sb-detail-value">${detail.degree_name || 'N/A'} (Level ${detail.level}, Sem ${detail.semester})</div></div>`;
            } else {
                title.innerText = `📅 ${detail.purpose}`;
                body.innerHTML = `<div class="sb-detail-row"><div class="sb-detail-label">Room:</div><div class="sb-detail-value">${roomName} (${building})</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Time:</div><div class="sb-detail-value">${timeStr || (detail.start_time + '–' + detail.end_time)}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Purpose:</div><div class="sb-detail-value">${detail.purpose}</div></div>
            <div class="sb-detail-row"><div class="sb-detail-label">Booked by:</div><div class="sb-detail-value">${detail.booked_by || 'N/A'}<br>📞 ${detail.booked_by_phone || 'N/A'}</div></div>`;
            }
            document.getElementById('sb-detail-modal').classList.add('active');
        }
        function sbShowModalAlert(msg) {
            document.getElementById('sb-modal-alert-msg').innerHTML = msg;
            document.getElementById('sb-modal-alert').style.display = 'block';
            document.getElementById('sb-modal-success').style.display = 'none';
        }
        function sbShowModalSuccess(msg) {
            document.getElementById('sb-modal-success-msg').innerText = msg;
            document.getElementById('sb-modal-success').style.display = 'block';
            document.getElementById('sb-modal-alert').style.display = 'none';
        }

        let currentEditBooking = null;
        function openEditBookingModal(booking) {
            currentEditBooking = booking;
            document.getElementById('edit-booking-id').value = booking.id;
            document.getElementById('edit-booking-room').value = booking.room_name + ' (' + (booking.building_name || '') + ')';
            document.getElementById('edit-booking-date').value = booking.booking_date;
            document.getElementById('edit-booking-start').value = booking.start_time;
            document.getElementById('edit-booking-end').value = booking.end_time;
            loadEditCourses(booking.course_id, booking.teacher_id);
            const purposeSelect = document.getElementById('edit-booking-purpose-select');
            const customWrap = document.getElementById('edit-booking-purpose-custom-wrap');
            const customInput = document.getElementById('edit-booking-purpose-custom');
            const hiddenPurpose = document.getElementById('edit-booking-purpose');
            let existingPurpose = booking.purpose || '';
            let found = PURPOSE_OPTIONS.some(opt => opt === existingPurpose);
            if (found) {
                purposeSelect.value = existingPurpose;
                customWrap.classList.add('hidden');
                hiddenPurpose.value = existingPurpose;
            } else {
                purposeSelect.value = 'Other';
                customWrap.classList.remove('hidden');
                customInput.value = existingPurpose;
                hiddenPurpose.value = existingPurpose;
            }
            document.getElementById('edit-booking-modal').classList.add('active');
        }
        function closeEditBookingModal() { document.getElementById('edit-booking-modal').classList.remove('active'); }

        async function loadEditCourses(selectedCourseId, selectedTeacherId) {
            const degree_id = <?= $assigned_degree_id ?>;
            const level = <?= $assigned_level ?>;
            const semester = <?= $assigned_semester ?>;
            const resp = await fetch(`?ajax=get_courses_for_booking_cr&degree_program_id=${degree_id}&level=${level}&semester=${semester}`);
            const courses = await resp.json();
            const courseSelect = document.getElementById('edit-booking-course');
            courseSelect.innerHTML = '<option value="">Select course</option>';
            courses.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${c.course_code} - ${c.course_name}`;
                opt.dataset.dept = c.department_id || '';
                if (c.id == selectedCourseId) opt.selected = true;
                courseSelect.appendChild(opt);
            });
            if (selectedCourseId) {
                await loadTeachersForEditCourse(selectedCourseId, selectedTeacherId);
                
                // Show Add Teacher button if course has department
                const selectedOption = Array.from(courseSelect.options).find(opt => opt.value == selectedCourseId);
                let deptId = selectedOption?.dataset.dept || '';
                const addBtnEdit = document.getElementById('sb-add-teacher-btn-edit');
                
                if (deptId) {
                    addBtnEdit.style.display = 'inline-block';
                    addBtnEdit.dataset.deptId = deptId;
                } else {
                    addBtnEdit.style.display = 'none';
                }
            }
            courseSelect.addEventListener('change', async function () {
                const cid = this.value;
                const addBtnEdit = document.getElementById('sb-add-teacher-btn-edit');
                
                await loadTeachersForEditCourse(cid, null);
                
                // Show/hide Add Teacher button for edit modal
                if (!cid) {
                    addBtnEdit.style.display = 'none';
                    return;
                }
                
                const selectedOption = this.options[this.selectedIndex];
                let deptId = selectedOption?.dataset.dept || '';
                
                if (!deptId) {
                    const deptResp = await fetch(`?ajax=get_course_department&course_id=${cid}`);
                    const deptData = await deptResp.json();
                    if (deptData.department_id) {
                        deptId = deptData.department_id;
                        selectedOption.dataset.dept = deptId;
                    }
                }
                
                if (deptId) {
                    addBtnEdit.style.display = 'inline-block';
                    addBtnEdit.dataset.deptId = deptId;
                } else {
                    addBtnEdit.style.display = 'none';
                }
            });
        }
        async function loadTeachersForEditCourse(courseId, selectedTeacherId) {
            const teacherSelect = document.getElementById('edit-booking-teacher');
            if (!courseId) {
                teacherSelect.innerHTML = '<option value="">Select course first</option>';
                teacherSelect.disabled = true;
                return;
            }
            teacherSelect.disabled = true;
            teacherSelect.innerHTML = '<option value="">Loading teachers...</option>';
            const resp = await fetch(`?ajax=get_teachers_for_course&course_id=${courseId}`);
            const teachers = await resp.json();
            teacherSelect.innerHTML = '<option value="">Select teacher</option>';
            teachers.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.textContent = `${t.full_name}${t.email ? ' (' + t.email + ')' : ''}`;
                if (selectedTeacherId && t.id == selectedTeacherId) opt.selected = true;
                teacherSelect.appendChild(opt);
            });
            teacherSelect.disabled = false;
        }
        document.getElementById('edit-booking-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const bookingId = document.getElementById('edit-booking-id').value;
            const courseId = document.getElementById('edit-booking-course').value;
            const teacherId = document.getElementById('edit-booking-teacher').value;
            const purposeSelect = document.getElementById('edit-booking-purpose-select');
            let purpose = purposeSelect.value;
            let purposeCustom = document.getElementById('edit-booking-purpose-custom').value.trim();
            const start = document.getElementById('edit-booking-start').value;
            const end = document.getElementById('edit-booking-end').value;
            if (!courseId || !teacherId) { alert('Course and Teacher are required.'); return; }
            if (purpose === 'Other' && !purposeCustom) { alert('Please enter a custom purpose.'); return; }
            if (!purpose) { alert('Please select a purpose.'); return; }
            const finalPurpose = (purpose === 'Other') ? purposeCustom : purpose;
            const formData = new FormData();
            formData.append('booking_id', bookingId);
            formData.append('purpose', purpose);
            formData.append('purpose_custom', purposeCustom);
            formData.append('start_time', start);
            formData.append('end_time', end);
            formData.append('course_id', courseId);
            formData.append('teacher_id', teacherId);
            const resp = await fetch('?ajax=edit_booking', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                document.getElementById('edit-booking-alert-msg').innerText = data.error;
                document.getElementById('edit-booking-alert').style.display = 'block';
            }
        });

        // Profile editing scripts (unchanged)
        const basicForm = document.getElementById('basic-info-form');
        const nameInput = document.getElementById('full_name');
        const emailInput = document.getElementById('email');
        const phoneInput = document.getElementById('phone');
        function validatePhone(phone) { return /^01[3-9]\d{8}$/.test(phone); }
        function validateEmail(email) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email); }
        function showFieldError(fieldId, errorId, message) {
            const input = document.getElementById(fieldId);
            const errorDiv = document.getElementById(errorId);
            input.classList.remove('field-success', 'field-error');
            if (message) {
                input.classList.add('field-error');
                errorDiv.innerText = message;
            } else {
                input.classList.add('field-success');
                errorDiv.innerText = '';
            }
        }
        nameInput.addEventListener('blur', () => { if (!nameInput.value.trim()) showFieldError('full_name', 'name-error', 'Full name is required'); else showFieldError('full_name', 'name-error', ''); });
        emailInput.addEventListener('blur', () => { if (!validateEmail(emailInput.value)) showFieldError('email', 'email-error', 'Invalid email address'); else showFieldError('email', 'email-error', ''); });
        phoneInput.addEventListener('blur', () => { if (!validatePhone(phoneInput.value)) showFieldError('phone', 'phone-error', 'Must be a valid Bangladeshi mobile number (01XXXXXXXXX)'); else showFieldError('phone', 'phone-error', ''); });
        basicForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const full_name = nameInput.value.trim();
            const email = emailInput.value.trim();
            const phone = phoneInput.value.trim();
            if (!full_name || !validateEmail(email) || !validatePhone(phone)) {
                document.getElementById('basic-error').innerText = 'Please correct the highlighted fields.';
                document.getElementById('basic-error').style.display = 'block';
                return;
            }
            const formData = new FormData();
            formData.append('full_name', full_name);
            formData.append('email', email);
            formData.append('phone', phone);
            const resp = await fetch('?ajax=update_basic_profile', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('basic-success').innerText = data.message;
                document.getElementById('basic-success').style.display = 'block';
                document.getElementById('basic-error').style.display = 'none';
                setTimeout(() => location.reload(), 1500);
            } else {
                document.getElementById('basic-error').innerText = data.errors ? data.errors.join(', ') : 'Update failed';
                document.getElementById('basic-error').style.display = 'block';
                document.getElementById('basic-success').style.display = 'none';
            }
        });
        document.getElementById('password-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const current = document.getElementById('current_password').value;
            const newPwd = document.getElementById('new_password').value;
            const confirm = document.getElementById('confirm_password').value;
            if (newPwd !== confirm) {
                document.getElementById('password-error').innerText = 'New passwords do not match.';
                document.getElementById('password-error').style.display = 'block';
                return;
            }
            if (newPwd.length < 6) {
                document.getElementById('password-error').innerText = 'Password must be at least 6 characters.';
                document.getElementById('password-error').style.display = 'block';
                return;
            }
            const formData = new FormData();
            formData.append('current_password', current);
            formData.append('new_password', newPwd);
            formData.append('confirm_password', confirm);
            const resp = await fetch('?ajax=change_password', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('password-success').innerText = data.message;
                document.getElementById('password-success').style.display = 'block';
                document.getElementById('password-error').style.display = 'none';
                document.getElementById('password-form').reset();
            } else {
                document.getElementById('password-error').innerText = data.error;
                document.getElementById('password-error').style.display = 'block';
                document.getElementById('password-success').style.display = 'none';
            }
        });
        async function checkSemesterEnd() {
            const resp = await fetch('?ajax=check_semester_end');
            const data = await resp.json();
            const statusDiv = document.getElementById('semester-status');
            const fieldsDiv = document.getElementById('semester-update-fields');
            if (!data.ended) {
                statusDiv.innerHTML = `<div class="alert alert-warning">Your current semester ends on ${data.end_date}. You can only change level/semester after that date.</div>`;
                fieldsDiv.style.display = 'none';
            } else {
                statusDiv.innerHTML = `<div class="alert alert-success">Your current semester has ended. You may promote to the next level/semester.</div>`;
                fieldsDiv.style.display = 'block';
                loadTargetLevels();
            }
        }
        async function loadTargetLevels() {
            const resp = await fetch('?ajax=get_target_level_semesters');
            const levels = await resp.json();
            const levelSelect = document.getElementById('new_level');
            levelSelect.innerHTML = '<option value="">Select Level</option>';
            if (levels.length === 0) {
                levelSelect.innerHTML = '<option value="">No higher level/semester available</option>';
                return;
            }
            const uniqueLevels = [...new Map(levels.map(l => [l.level, l.level])).values()];
            uniqueLevels.forEach(lvl => {
                const opt = document.createElement('option');
                opt.value = lvl;
                opt.textContent = 'Level ' + lvl;
                levelSelect.appendChild(opt);
            });
        }
        document.getElementById('new_level').addEventListener('change', async function () {
            const level = this.value;
            const semSelect = document.getElementById('new_semester');
            semSelect.innerHTML = '<option value="">Loading...</option>';
            semSelect.disabled = true;
            if (!level) return;
            const resp = await fetch(`?ajax=get_target_level_semesters&level=${level}`);
            const combos = await resp.json();
            const semestersForLevel = combos.filter(c => c.level == level).map(c => c.semester_num);
            semSelect.innerHTML = '<option value="">Select Semester</option>';
            semestersForLevel.forEach(sem => {
                const opt = document.createElement('option');
                opt.value = sem;
                opt.textContent = 'Semester ' + sem;
                semSelect.appendChild(opt);
            });
            semSelect.disabled = false;
            loadGroupsForTarget(level, semSelect.value);
        });
        document.getElementById('new_semester').addEventListener('change', function () {
            const level = document.getElementById('new_level').value;
            const sem = this.value;
            loadGroupsForTarget(level, sem);
        });
        async function loadGroupsForTarget(level, sem) {
            const groupSelect = document.getElementById('new_group');
            groupSelect.innerHTML = '<option value="">All Groups</option>';
            if (!level || !sem) return;
            const resp = await fetch(`?ajax=get_groups_for_target&level=${level}&semester=${sem}`);
            const groups = await resp.json();
            groups.forEach(g => {
                const opt = document.createElement('option');
                opt.value = g;
                opt.textContent = g;
                groupSelect.appendChild(opt);
            });
        }
        document.getElementById('semester-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const level = document.getElementById('new_level').value;
            const semester = document.getElementById('new_semester').value;
            const group = document.getElementById('new_group').value;
            if (!level || !semester) {
                document.getElementById('semester-error').innerText = 'Please select level and semester.';
                document.getElementById('semester-error').style.display = 'block';
                return;
            }
            const formData = new FormData();
            formData.append('level', level);
            formData.append('semester', semester);
            formData.append('group_name', group);
            const resp = await fetch('?ajax=update_semester_assignment', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.success) {
                document.getElementById('semester-success').innerText = data.message;
                document.getElementById('semester-success').style.display = 'block';
                document.getElementById('semester-error').style.display = 'none';
                setTimeout(() => location.reload(), 1500);
            } else {
                document.getElementById('semester-error').innerText = data.error;
                document.getElementById('semester-error').style.display = 'block';
                document.getElementById('semester-success').style.display = 'none';
            }
        });

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
                    if (val && val !== '') { if (hidden) hidden.value = val; }
                    else { if (hidden) hidden.value = ''; }
                }
            };
            select.addEventListener('change', update);
            if (customInput) customInput.addEventListener('input', () => { if (select.value === 'Other' && hidden) hidden.value = customInput.value; });
            update();
        }

        async function refreshSBGrid() {
            const view = sbView;
            const date = sbDate;
            const resp = await fetch(`?ajax=refresh_sb_grid&date=${date}&view=${view}`);
            const data = await resp.json();
            if (data.success) {
                if (view === 'week') {
                    document.querySelector('.sb-week-wrapper').outerHTML = data.html;
                } else {
                    const oldContent = document.querySelector('#status-booking .sb-day-heading')?.parentElement;
                    if (oldContent) oldContent.outerHTML = data.html;
                }
                attachSBClickHandlers();
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');
            const sectionIndex = { 'dashboard': 0, 'schedule': 1, 'status-booking': 2, 'profile': 3, 'notifications': 4 };
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
            checkSemesterEnd();
            setupPurposeDropdown('sb-purpose', 'sb-purpose-custom-wrap', 'sb-purpose-custom', null);
            setupPurposeDropdown('edit-booking-purpose-select', 'edit-booking-purpose-custom-wrap', 'edit-booking-purpose-custom', 'edit-booking-purpose');
            document.querySelectorAll('.profile-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.profile-tab').forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    const target = tab.dataset.tab;
                    document.querySelectorAll('.profile-pane').forEach(pane => pane.classList.remove('active'));
                    if (target === 'basic') document.getElementById('profile-basic').classList.add('active');
                    else if (target === 'password') document.getElementById('profile-password').classList.add('active');
                    else if (target === 'semester') document.getElementById('profile-semester').classList.add('active');
                });
            });
        });
    </script>
</body>

</html>