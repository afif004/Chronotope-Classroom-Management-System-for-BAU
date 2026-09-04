<?php
require_once 'db_config.php';

// ── Filter params ─────────────────────────────────────────────────────────────
$faculty_filter = isset($_GET['faculty']) && is_numeric($_GET['faculty']) ? (int) $_GET['faculty'] : null;
$building_id = isset($_GET['building']) && is_numeric($_GET['building']) ? (int) $_GET['building'] : null;
$degree_id = isset($_GET['degree']) && is_numeric($_GET['degree']) ? (int) $_GET['degree'] : null;
$level = isset($_GET['level']) && is_numeric($_GET['level']) ? (int) $_GET['level'] : null;
$semester = isset($_GET['semester']) && is_numeric($_GET['semester']) ? (int) $_GET['semester'] : null;
$view = $_GET['view'] ?? 'week';
$date_param = $_GET['date'] ?? date('Y-m-d');
$ongoing_only = isset($_GET['ongoing']) && $_GET['ongoing'] == '1';

if ($ongoing_only) {
    $date = date('Y-m-d');
} else {
    $date = $date_param;
}

$facultiesStmt = $pdo->query("SELECT id, name, code FROM faculties WHERE status = 'active' ORDER BY name");
$allFaculties = $facultiesStmt->fetchAll();

$degreeStmt = $pdo->query("SELECT id, name, total_levels, semesters_per_level FROM degree_programs WHERE status = 'active' ORDER BY name");
$degreePrograms = $degreeStmt->fetchAll();

if ($faculty_filter !== null) {
    $bStmt = $pdo->prepare("SELECT id, name, code, faculty_id FROM buildings WHERE faculty_id = ? ORDER BY name");
    $bStmt->execute([$faculty_filter]);
    $allBuildings = $bStmt->fetchAll();
} else {
    $buildingsStmt = $pdo->query("SELECT id, name, code, faculty_id FROM buildings ORDER BY name");
    $allBuildings = $buildingsStmt->fetchAll();
}
if ($building_id && !in_array($building_id, array_column($allBuildings, 'id'))) {
    $building_id = null;
}

if ($view === 'week') {
    $dayOfWeek = (int) date('w', strtotime($date));
    $startDate = date('Y-m-d', strtotime($date . ' -' . $dayOfWeek . ' days'));
    $endDate = date('Y-m-d', strtotime($startDate . ' +4 days'));
} else {
    $startDate = $date;
    $endDate = $date;
}

$roomIdSql = "
    SELECT DISTINCT cs.room_id
    FROM course_schedule cs
    WHERE cs.status = 'scheduled'
      AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))
";
$params = [$endDate, $startDate];

$datesInRange = [];
$current = $startDate;
while ($current <= $endDate) {
    $datesInRange[] = $current;
    $current = date('Y-m-d', strtotime($current . ' +1 day'));
}
$dayConditions = [];
foreach ($datesInRange as $d) {
    $dow = (int) date('N', strtotime($d));
    $dayConditions[] = "cs.day_of_week = $dow";
}
$roomIdSql .= " AND (" . implode(' OR ', $dayConditions) . ")";

if ($degree_id) {
    $roomIdSql .= " AND cs.degree_program_id = ?";
    $params[] = $degree_id;
}
if ($level) {
    $roomIdSql .= " AND cs.level = ?";
    $params[] = $level;
}
if ($semester) {
    $roomIdSql .= " AND cs.semester = ?";
    $params[] = $semester;
}
$roomIdStmt = $pdo->prepare($roomIdSql);
$roomIdStmt->execute($params);
$filteredRoomIds = $roomIdStmt->fetchAll(PDO::FETCH_COLUMN);

if (!$degree_id) {
    $roomsSql = "
        SELECT r.*, b.name AS building_name, b.code AS building_code,
               f.floor_number, fac.name AS faculty_name
        FROM rooms r
        JOIN floors f ON r.floor_id = f.id
        JOIN buildings b ON f.building_id = b.id
        LEFT JOIN faculties fac ON r.faculty_id = fac.id
        WHERE 1=1
    ";
    $roomParams = [];
    if ($building_id !== null) {
        $roomsSql .= " AND b.id = ?";
        $roomParams[] = $building_id;
    } elseif ($faculty_filter !== null) {
        $roomsSql .= " AND r.faculty_id = ?";
        $roomParams[] = $faculty_filter;
    }
    $roomsSql .= " ORDER BY b.name, f.floor_number, r.room_number";
    $roomsStmt = $pdo->prepare($roomsSql);
    $roomsStmt->execute($roomParams);
    $allRooms = $roomsStmt->fetchAll();
} else {
    if (empty($filteredRoomIds)) {
        $allRooms = [];
    } else {
        $inClause = implode(',', array_fill(0, count($filteredRoomIds), '?'));
        $roomsSql = "
            SELECT r.*, b.name AS building_name, b.code AS building_code,
                   f.floor_number, fac.name AS faculty_name
            FROM rooms r
            JOIN floors f ON r.floor_id = f.id
            JOIN buildings b ON f.building_id = b.id
            LEFT JOIN faculties fac ON r.faculty_id = fac.id
            WHERE r.id IN ($inClause)
        ";
        $roomParams = $filteredRoomIds;
        if ($building_id !== null) {
            $roomsSql .= " AND b.id = ?";
            $roomParams[] = $building_id;
        } elseif ($faculty_filter !== null) {
            $roomsSql .= " AND r.faculty_id = ?";
            $roomParams[] = $faculty_filter;
        }
        $roomsSql .= " ORDER BY b.name, f.floor_number, r.room_number";
        $roomsStmt = $pdo->prepare($roomsSql);
        $roomsStmt->execute($roomParams);
        $allRooms = $roomsStmt->fetchAll();
    }
}

$timeSlots = $pdo->query("SELECT * FROM time_slots ORDER BY slot_order")->fetchAll();

$dayOfWeek = (int) date('w', strtotime($date));
$weekDays = [];
$dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu'];
for ($i = 0; $i < 5; $i++) {
    $d = date('Y-m-d', strtotime($date . ' -' . $dayOfWeek . ' days +' . $i . ' days'));
    $weekDays[] = [
        'date' => $d,
        'day_name' => $dayNames[$i],
        'day_number' => date('j', strtotime($d)),
        'is_today' => $d === date('Y-m-d'),
    ];
}
$weekStart = $weekDays[0]['date'];
$weekEnd = $weekDays[4]['date'];
$weekRangeLabel = date('M j', strtotime($weekStart)) . ' – ' . date('M j, Y', strtotime($weekEnd));

function tsOverlaps($sS, $sE, $eS, $eE) {
    return strtotime($eS) < strtotime($sE) && strtotime($eE) > strtotime($sS);
}

function shortDegreeName($fullName) {
    $map = [
        'Agriculture' => 'BSc Ag', 'Veterinary' => 'DVM', 'Animal Husbandry' => 'BSc AH',
        'Agricultural Economics' => 'BSc Econ', 'Agricultural Engineering' => 'BSc Eng', 'Fisheries' => 'BSc Fish',
    ];
    foreach ($map as $key => $short) {
        if (stripos($fullName, $key) !== false) return $short;
    }
    $words = explode(' ', $fullName);
    if (count($words) >= 2) return substr($words[0],0,1) . substr($words[1],0,1) . '?';
    return substr($fullName, 0, 8);
}

function roomStatusForDate($pdo, $date, &$rooms, &$timeSlots, $degree_id = null, $level = null, $semester = null) {
    $dow = (int) date('N', strtotime($date));
    $result = [];
    foreach ($rooms as $room) {
        $stmt = $pdo->prepare("
            SELECT rb.*, u.full_name AS booked_by_name, u.phone AS booked_by_phone
            FROM room_bookings rb
            LEFT JOIN users u ON rb.booked_by = u.id
            WHERE rb.room_id = ? AND rb.booking_date = ? AND rb.status = 'booked'
            ORDER BY rb.start_time
        ");
        $stmt->execute([$room['id'], $date]);
        $bookings = $stmt->fetchAll();

        $sql = "
            SELECT cs.*, 
                   u.full_name AS teacher_name, u.phone AS teacher_phone,
                   dp.name AS degree_name,
                   cr.full_name AS cr_name, cr.phone AS cr_phone
            FROM course_schedule cs
            LEFT JOIN users u ON cs.teacher_id = u.id
            LEFT JOIN degree_programs dp ON cs.degree_program_id = dp.id
            LEFT JOIN users cr ON cr.degree_program_id = cs.degree_program_id 
                AND cr.assigned_level = cs.level 
                AND cr.assigned_semester = cs.semester 
                AND cr.user_type = 'cr'
            WHERE cs.room_id = ? AND cs.day_of_week = ? AND cs.status = 'scheduled'
              AND (cs.is_whole_semester = 1 OR (cs.start_date <= ? AND cs.end_date >= ?))
        ";
        $params = [$room['id'], $dow, $date, $date];
        if ($degree_id) { $sql .= " AND cs.degree_program_id = ?"; $params[] = $degree_id; }
        if ($level) { $sql .= " AND cs.level = ?"; $params[] = $level; }
        if ($semester) { $sql .= " AND cs.semester = ?"; $params[] = $semester; }
        $sql .= " ORDER BY cs.start_time";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $classes = $stmt->fetchAll();

        $slots = [];
        foreach ($timeSlots as $slot) {
            $status = 'available';
            $data = null;
            foreach ($classes as $c) {
                if (tsOverlaps($slot['start_time'], $slot['end_time'], $c['start_time'], $c['end_time'])) {
                    $status = 'class';
                    $data = [
                        'type' => 'class', 'id' => $c['id'], 'course_code' => $c['course_code'],
                        'course_name' => $c['course_name'], 'teacher_name' => $c['teacher_name'],
                        'teacher_phone' => $c['teacher_phone'], 'cr_name' => $c['cr_name'],
                        'cr_phone' => $c['cr_phone'], 'degree_name' => $c['degree_name'],
                        'level' => $c['level'], 'semester' => $c['semester'],
                        'group_name' => $c['group_name'], 'batch' => $c['batch'] ?? '',
                        'start_time' => $c['start_time'], 'end_time' => $c['end_time']
                    ];
                    break;
                }
            }
            if ($status === 'available') {
                foreach ($bookings as $b) {
                    if (tsOverlaps($slot['start_time'], $slot['end_time'], $b['start_time'], $b['end_time'])) {
                        $status = 'booked';
                        $data = [
                            'type' => 'booking', 'id' => $b['id'], 'purpose' => $b['purpose'],
                            'booked_by' => $b['booked_by_name'], 'booked_by_phone' => $b['booked_by_phone'],
                            'start_time' => $b['start_time'], 'end_time' => $b['end_time']
                        ];
                        break;
                    }
                }
            }
            $slots[$slot['id']] = ['slot' => $slot, 'status' => $status, 'data' => $data];
        }
        $result[$room['id']] = ['bookings' => $bookings, 'classes' => $classes, 'slots' => $slots];
    }
    return $result;
}

$weekStatuses = [];
foreach ($weekDays as $wd) {
    $weekStatuses[$wd['date']] = roomStatusForDate($pdo, $wd['date'], $allRooms, $timeSlots, $degree_id, $level, $semester);
}

$currentTime = date('H:i:s');
$currentSlot = null;
foreach ($timeSlots as $slot) {
    if ($currentTime >= $slot['start_time'] && $currentTime <= $slot['end_time']) {
        $currentSlot = $slot;
        break;
    }
}
$ongoingRoomIds = [];
$hasOngoingClasses = false;
if ($currentSlot) {
    $todayStatus = $weekStatuses[date('Y-m-d')] ?? [];
    foreach ($todayStatus as $roomId => $status) {
        foreach ($status['slots'] as $slotInfo) {
            if ($slotInfo['slot']['id'] == $currentSlot['id'] && $slotInfo['status'] === 'class') {
                $ongoingRoomIds[] = $roomId;
                $hasOngoingClasses = true;
                break;
            }
        }
    }
}
$showNoOngoingModal = false;
if ($ongoing_only && !$hasOngoingClasses) {
    $showNoOngoingModal = true;
    $ongoing_only = false;
}

if ($ongoing_only && $currentSlot) {
    $rooms = array_filter($allRooms, function ($r) use ($ongoingRoomIds) { return in_array($r['id'], $ongoingRoomIds); });
    $rooms = array_values($rooms);
} else {
    $rooms = $allRooms;
}

$totalSlots = 0; $classSlots = 0; $bookedSlots = 0;
$slotsPerDay = count($timeSlots);

if ($view === 'week') {
    foreach ($rooms as $room) {
        foreach ($weekDays as $wd) {
            $rs = $weekStatuses[$wd['date']][$room['id']] ?? null;
            if ($rs) {
                foreach ($rs['slots'] as $slotInfo) {
                    $totalSlots++;
                    if ($slotInfo['status'] === 'class') $classSlots++;
                    elseif ($slotInfo['status'] === 'booked') $bookedSlots++;
                }
            } else { $totalSlots += $slotsPerDay; }
        }
    }
} else {
    $todayStatuses = $weekStatuses[$date] ?? [];
    foreach ($rooms as $room) {
        $rs = $todayStatuses[$room['id']] ?? null;
        if ($rs) {
            foreach ($rs['slots'] as $slotInfo) {
                $totalSlots++;
                if ($slotInfo['status'] === 'class') $classSlots++;
                elseif ($slotInfo['status'] === 'booked') $bookedSlots++;
            }
        } else { $totalSlots += $slotsPerDay; }
    }
}

$freeSlots = $totalSlots - $classSlots - $bookedSlots;
$utilizationPercent = $totalSlots > 0 ? round(($classSlots + $bookedSlots) / $totalSlots * 100, 1) : 0;

$facultyLabel = 'All Faculties';
if ($faculty_filter !== null && !$building_id) {
    foreach ($allFaculties as $f) {
        if ($f['id'] == $faculty_filter) { $facultyLabel = $f['name']; break; }
    }
}
$buildingLabel = '';
if ($building_id) {
    foreach ($allBuildings as $b) {
        if ($b['id'] == $building_id) { $buildingLabel = $b['name']; break; }
    }
}
$degreeLabel = '';
if ($degree_id) {
    foreach ($degreePrograms as $dp) {
        if ($dp['id'] == $degree_id) { $degreeLabel = $dp['name']; break; }
    }
}
$pageSubtitle = $building_id ? "🏛️ {$buildingLabel}" : $facultyLabel;
if ($degree_id) $pageSubtitle .= " • {$degreeLabel}";
if ($level && $semester) $pageSubtitle .= " • Level {$level} – Semester {$semester}";
elseif ($level) $pageSubtitle .= " • Level {$level}";
elseif ($semester) $pageSubtitle .= " • Semester {$semester}";
if ($ongoing_only) $pageSubtitle .= " • 🔴 Ongoing Now";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>BAU Classroom Status • Smart Live View</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

:root {
    --green-50: #edfaf4; --green-100: #d0f4e4; --green-200: #a3e8cd; --green-400: #34b880;
    --green-500: #1e9e68; --green-600: #0f7a50; --green-700: #085c3a; --green-800: #053d27;
    --blue-50: #eef5fd; --blue-100: #cfe1f8; --blue-200: #9dc5f1; --blue-400: #3e87d4;
    --blue-500: #1e6dbd; --blue-600: #1255a0; --blue-700: #0c3f7f; --blue-800: #082a5a;
    --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-200: #e2e8f0; --slate-300: #cbd5e1;
    --slate-400: #94a3b8; --slate-500: #64748b; --slate-600: #475569; --slate-700: #334155;
    --slate-800: #1e293b; --slate-900: #0f172a;
    --font-body: 'Inter', 'Segoe UI', system-ui, sans-serif;
    --radius-sm: 6px; --radius-md: 10px; --radius-lg: 16px; --radius-xl: 22px; --radius-pill: 999px;
    --shadow-xs: 0 1px 2px rgba(15,23,42,0.06);
    --shadow-sm: 0 2px 6px rgba(15,23,42,0.07), 0 1px 2px rgba(15,23,42,0.05);
    --shadow-md: 0 6px 20px rgba(15,23,42,0.09), 0 2px 6px rgba(15,23,42,0.05);
    --shadow-lg: 0 20px 32px -12px rgba(15,23,42,0.15);
    --primary: var(--green-600); --danger: #e63946; --warning: #f4a261;
    --header-h: 72px;
}

body {
    font-family: var(--font-body);
    background: linear-gradient(135deg, var(--slate-100) 0%, #e2e8f0 100%);
    color: var(--slate-800);
    line-height: 1.5;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    font-size: 16px;
}

/* ── HEADER ── */
.header {
    background: linear-gradient(105deg, #053d27 0%, #064e3b 25%, #0c3f7f 72%, #082a5a 100%);
    padding: 0 clamp(1rem, 5vw, 3.5rem);
    height: 72px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    position: sticky;
    top: 0;
    z-index: 200;
    box-shadow: 0 4px 20px rgba(6,30,60,0.38);
}

.brand {
    display: flex;
    align-items: center;
    gap: 13px;
    min-width: 0;
    flex-shrink: 1;
}

.logo-img-wrap {
    width: 50px;
    height: 50px;
    border-radius: var(--radius-md);
    background: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    padding: 5px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.22), 0 0 0 1.5px rgba(255,255,255,0.18);
}

.logo-img-wrap img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
}

.brand-text {
    min-width: 0;
    line-height: 1.3;
}

.brand-text .name {
    font-size: 1rem;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    text-shadow: 0 1px 4px rgba(0,0,0,0.2);
}

.brand-text .sub {
    font-size: 0.72rem;
    color: rgba(255,255,255,0.58);
    font-weight: 400;
    letter-spacing: 0.02em;
    white-space: nowrap;
}

.header-sep {
    width: 1px;
    height: 38px;
    background: rgba(255,255,255,0.15);
    flex-shrink: 0;
    margin: 0 4px;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    flex-shrink: 0;
}

.back-home {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(255,255,255,0.11);
    border: 1.5px solid rgba(255,255,255,0.28);
    border-radius: var(--radius-pill);
    padding: 9px 20px;
    color: white;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.86rem;
    transition: all 0.18s;
    white-space: nowrap;
    letter-spacing: 0.01em;
}

.back-home svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}

.back-home:hover {
    background: rgba(255,255,255,0.2);
    border-color: rgba(255,255,255,0.5);
    transform: translateY(-1px);
}

.back-home:active {
    transform: translateY(0);
}

.signin-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(255,255,255,0.11);
    border: 1.5px solid rgba(255,255,255,0.28);
    color: #fff;
    text-decoration: none;
    font-size: 0.86rem;
    font-weight: 600;
    padding: 9px 22px;
    border-radius: var(--radius-pill);
    cursor: pointer;
    transition: all 0.18s;
    white-space: nowrap;
    letter-spacing: 0.01em;
}

.signin-btn svg {
    width: 15px;
    height: 15px;
    flex-shrink: 0;
}

.signin-btn:hover {
    background: rgba(255,255,255,0.2);
    border-color: rgba(255,255,255,0.5);
    transform: translateY(-1px);
}

.signin-btn:active {
    transform: translateY(0);
}

/* ── TOOLBAR ── */
.toolbar {
    background: rgba(255,255,255,0.97);
    backdrop-filter: blur(12px);
    padding: 0.65rem clamp(0.75rem, 3vw, 2rem);
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    gap: 0.65rem;
    position: sticky;
    top: var(--header-h);
    z-index: 100;
    border-bottom: 1px solid var(--slate-200);
    box-shadow: var(--shadow-sm);
}

.filter-group {
    display: flex;
    gap: 0.5rem 1rem;
    flex-wrap: wrap;
    background: white;
    padding: 0.6rem 0.9rem;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--slate-200);
    align-items: center;
    flex: 1 1 100%;
}

.filter-item {
    display: flex;
    align-items: center;
    gap: 6px;
    flex: 1 1 120px;
    min-width: 0;
}

.filter-item label {
    font-weight: 700;
    font-size: clamp(0.55rem, 1.5vw, 0.7rem);
    text-transform: uppercase;
    color: var(--slate-500);
    letter-spacing: 0.5px;
    white-space: nowrap;
    flex-shrink: 0;
}

.filter-item select {
    border: 1px solid var(--slate-200);
    background: var(--slate-50);
    font-weight: 500;
    font-size: clamp(0.7rem, 2vw, 0.85rem);
    padding: 5px 8px;
    outline: none;
    cursor: pointer;
    font-family: inherit;
    width: 100%;
    min-width: 0;
    border-radius: var(--radius-sm);
    transition: all 0.2s;
}

.filter-item select:focus {
    background: white;
    border-color: var(--green-400);
    box-shadow: 0 0 0 3px rgba(52,184,128,0.15);
}

.filter-item select:disabled {
    background: #f1f5f9;
    color: #94a3b8;
    cursor: not-allowed;
}

.clear-btn {
    background: white;
    border: 1px solid var(--slate-300);
    padding: 5px 14px;
    border-radius: 48px;
    font-weight: 600;
    font-size: clamp(0.65rem, 1.8vw, 0.8rem);
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--slate-700);
    white-space: nowrap;
    flex-shrink: 0;
}

.clear-btn:hover {
    background: var(--slate-100);
    border-color: var(--slate-400);
}

.toolbar-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
    width: 100%;
}

.view-tabs {
    display: flex;
    background: white;
    border-radius: 48px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--slate-200);
    flex-shrink: 0;
}

.view-tab {
    padding: 7px 18px;
    font-size: clamp(0.7rem, 2vw, 0.85rem);
    font-weight: 700;
    text-decoration: none;
    color: var(--slate-600);
    transition: all 0.2s;
    cursor: pointer;
    white-space: nowrap;
}

.view-tab.active {
    background: linear-gradient(135deg, var(--green-500) 0%, var(--blue-500) 100%);
    color: white;
}

.ongoing-btn {
    background: white;
    border: 1px solid var(--slate-200);
    padding: 7px 16px;
    border-radius: 48px;
    font-weight: 700;
    font-size: clamp(0.7rem, 2vw, 0.8rem);
    cursor: pointer;
    transition: all 0.2s;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    flex-shrink: 0;
}

.ongoing-btn.active {
    background: var(--danger);
    color: white;
    border-color: var(--danger);
    box-shadow: 0 0 0 3px rgba(230,57,70,0.3);
}

.nav-group {
    display: flex;
    gap: 6px;
    background: white;
    padding: 4px 10px;
    border-radius: 48px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--slate-200);
    align-items: center;
    flex-wrap: wrap;
    margin-left: auto;
}

.nav-btn {
    padding: 5px 12px;
    border-radius: 40px;
    background: var(--slate-100);
    font-weight: 600;
    font-size: clamp(0.65rem, 1.8vw, 0.8rem);
    cursor: pointer;
    transition: all 0.2s;
    border: none;
    white-space: nowrap;
}

.nav-btn:hover {
    background: var(--green-500);
    color: white;
}

.period-label {
    font-weight: 700;
    padding: 0 8px;
    font-size: clamp(0.65rem, 1.8vw, 0.85rem);
    white-space: nowrap;
}

.date-picker-nav {
    border: 1px solid var(--slate-200);
    background: var(--slate-50);
    font-weight: 500;
    font-size: clamp(0.65rem, 1.8vw, 0.85rem);
    padding: 4px 8px;
    border-radius: 40px;
    font-family: inherit;
    cursor: pointer;
}

.date-picker-nav:focus {
    background: white;
    border-color: var(--green-400);
    outline: none;
}

/* ── MAIN ── */
.main {
    flex: 1;
    padding: 1.25rem clamp(0.75rem, 3vw, 2rem);
    max-width: 1600px;
    margin: 0 auto;
    width: 100%;
}

/* ── STATS ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 0.75rem;
    margin-bottom: 1.25rem;
}

.stat-card {
    background: rgba(255,255,255,0.96);
    border-radius: var(--radius-lg);
    padding: clamp(0.75rem, 2vw, 1rem) clamp(0.75rem, 2vw, 1.5rem);
    box-shadow: var(--shadow-md);
    border: 1px solid var(--slate-200);
    transition: all 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
    background: white;
}

.stat-value {
    font-size: clamp(1.2rem, 4vw, 2rem);
    font-weight: 800;
    background: linear-gradient(135deg, var(--green-600), var(--blue-600));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    line-height: 1.2;
}

.stat-label {
    font-size: clamp(0.55rem, 1.5vw, 0.65rem);
    text-transform: uppercase;
    font-weight: 700;
    color: var(--slate-500);
    letter-spacing: 0.5px;
    margin-top: 4px;
}

.stat-desc {
    font-size: clamp(0.5rem, 1.4vw, 0.6rem);
    color: var(--slate-400);
    margin-top: 4px;
    line-height: 1.3;
}

/* ── LEGEND ── */
.legend {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: clamp(0.4rem, 1.5vw, 1rem);
    margin-bottom: 1.25rem;
    flex-wrap: nowrap;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    padding-bottom: 2px;
    scrollbar-width: none;
}

.legend::-webkit-scrollbar {
    display: none;
}

.legend-item {
    background: rgba(255,255,255,0.85);
    padding: 5px 14px;
    border-radius: 60px;
    font-size: clamp(0.6rem, 1.6vw, 0.7rem);
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--slate-200);
    white-space: nowrap;
    flex-shrink: 0;
}

/* ── CALENDAR ── */
.cal-wrapper {
    background: rgba(255,255,255,0.96);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-lg);
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border: 1px solid var(--slate-200);
}

.cal-grid {
    display: grid;
    gap: 1px;
    background: var(--slate-200);
    font-size: clamp(0.6rem, 1.5vw, 0.75rem);
}

.cal-grid.week {
    grid-template-columns: minmax(130px, 200px) repeat(5, minmax(110px, 1fr));
    min-width: 720px;
}

.cal-grid.day {
    grid-template-columns: minmax(130px, 200px) repeat(var(--slots-count, 9), minmax(90px, 1fr));
    min-width: 700px;
}

.cal-cell {
    background: white;
    padding: clamp(6px, 1.5vw, 10px) clamp(4px, 1vw, 8px);
    transition: background 0.1s;
}

.cal-cell.hdr {
    background: var(--slate-50);
    font-weight: 700;
    text-align: center;
    color: var(--slate-700);
    padding: clamp(6px, 1.5vw, 10px) clamp(4px, 1vw, 6px);
}

.cal-cell.hdr.recess-slot {
    background: #fef9e3;
    border-bottom: 2px solid #fbbf24;
}

.cal-cell.room-name {
    background: var(--slate-50);
    border-right: 1px solid var(--slate-200);
    font-weight: 600;
}

.rn-building {
    font-weight: 800;
    color: var(--green-600);
    font-size: clamp(0.65rem, 1.5vw, 0.8rem);
}

.rn-name {
    font-weight: 600;
    font-size: clamp(0.7rem, 1.6vw, 0.85rem);
    margin-top: 2px;
    word-break: break-word;
}

.rn-cap {
    font-size: clamp(0.5rem, 1.2vw, 0.6rem);
    color: var(--slate-500);
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 4px;
}

.week-cell {
    cursor: pointer;
    transition: transform 0.1s, box-shadow 0.1s;
}

.week-cell:hover {
    transform: scale(0.97);
    box-shadow: inset 0 0 0 2px var(--green-400);
    border-radius: 10px;
}

.week-stats {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 100%;
}

.stat-badge-group {
    display: flex;
    justify-content: space-between;
    gap: 4px;
}

.stat-badge-sm {
    border-radius: 40px;
    padding: 2px 5px;
    font-size: clamp(0.5rem, 1.3vw, 0.65rem);
    font-weight: 700;
    text-align: center;
    flex: 1;
}

.stat-badge-sm.occupied {
    background: #ffe5e5;
    color: #b91c1c;
}

.stat-badge-sm.avail {
    background: #dcfce7;
    color: #15803d;
}

.progress-stack {
    display: flex;
    height: 5px;
    border-radius: 12px;
    overflow: hidden;
    gap: 2px;
    background: #e2e8f0;
}

.progress-class {
    background: linear-gradient(90deg, #e63946, #f4acb7);
    border-radius: 12px 0 0 12px;
}

.progress-booked {
    background: linear-gradient(90deg, #f4a261, #e9c46a);
}

.progress-avail {
    background: linear-gradient(90deg, var(--green-400), #55a630);
    border-radius: 0 12px 12px 0;
}

.today-highlight {
    background: #fefce8 !important;
    border-left: 3px solid var(--warning);
}

.slot-card {
    border-radius: 10px;
    padding: clamp(5px, 1.2vw, 8px) clamp(3px, 0.8vw, 4px);
    text-align: center;
    transition: all 0.15s;
    font-weight: 600;
    font-size: clamp(0.55rem, 1.3vw, 0.7rem);
    cursor: pointer;
    height: 100%;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.slot-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.slot-available {
    background: #dcfce7;
    color: #0a5c2e;
    border-left: 3px solid var(--green-500);
}

.slot-class {
    background: #ffe5e5;
    color: #b91c1c;
    border-left: 3px solid #e63946;
}

.slot-booked {
    background: #fff3e0;
    color: #b45309;
    border-left: 3px solid #f4a261;
}

.current-slot-highlight {
    background: rgba(230,57,70,0.08);
}

.blink-red {
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(230,57,70,0.4); }
    70% { box-shadow: 0 0 0 6px rgba(230,57,70,0); }
    100% { box-shadow: 0 0 0 0 rgba(230,57,70,0); }
}

.slot-class-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    font-size: clamp(0.5rem, 1.2vw, 0.65rem);
    line-height: 1.3;
}

.slot-degree {
    font-weight: 700;
    color: #b91c1c;
}

.slot-course {
    font-weight: 500;
}

.slot-teacher {
    font-size: clamp(0.45rem, 1.1vw, 0.6rem);
    color: #4b5563;
}

.merged-slot {
    height: 100%;
}

/* ── MODALS ── */
.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(4px);
    z-index: 1000;
    justify-content: center;
    align-items: center;
    padding: 1rem;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    max-width: 500px;
    width: 100%;
    border-radius: 28px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
    animation: slideUp 0.2s ease;
}

@keyframes slideUp {
    from { transform: translateY(30px); opacity: 0; }
    to { transform: translateY(0); opacity: 1; }
}

.modal-header {
    background: linear-gradient(135deg, var(--green-600) 0%, var(--blue-600) 100%);
    color: white;
    padding: 0.85rem 1.25rem;
    font-weight: 700;
    font-size: clamp(0.85rem, 2.5vw, 1.1rem);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-close {
    background: none;
    border: none;
    color: white;
    font-size: 1.4rem;
    cursor: pointer;
    line-height: 1;
    padding: 0 4px;
}

.modal-body {
    padding: 1.25rem;
    max-height: 70vh;
    overflow-y: auto;
    font-size: clamp(0.75rem, 2vw, 0.9rem);
}

.detail-row {
    display: flex;
    margin-bottom: 0.85rem;
    border-bottom: 1px solid var(--slate-200);
    padding-bottom: 0.65rem;
    flex-wrap: wrap;
    gap: 4px;
}

.detail-label {
    font-weight: 700;
    width: 90px;
    flex-shrink: 0;
    color: var(--slate-600);
    font-size: clamp(0.7rem, 1.8vw, 0.85rem);
}

.detail-value {
    flex: 1;
    word-break: break-word;
    min-width: 120px;
    font-size: clamp(0.7rem, 1.8vw, 0.85rem);
}

.class-list-item, .booking-list-item {
    background: var(--slate-50);
    border-radius: 14px;
    padding: 0.85rem;
    margin-bottom: 0.65rem;
    cursor: pointer;
    transition: all 0.1s;
    border: 1px solid var(--slate-200);
    font-size: clamp(0.7rem, 1.8vw, 0.85rem);
}

.class-list-item:hover, .booking-list-item:hover {
    background: var(--slate-100);
    transform: translateX(4px);
}

/* ── EMPTY STATE ── */
.empty-state {
    text-align: center;
    background: white;
    border-radius: 2rem;
    padding: clamp(1.5rem, 4vw, 2rem);
    border: 1px solid var(--slate-200);
    font-size: clamp(0.8rem, 2.5vw, 1rem);
}

/* ── FOOTER ── */
.footer {
    background: var(--slate-900);
    color: rgba(255,255,255,0.42);
    padding: 1rem clamp(0.75rem, 4vw, 3rem);
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: auto;
}

.footer-left .fl1 {
    font-size: clamp(0.65rem, 1.8vw, 0.82rem);
    color: rgba(255,255,255,0.72);
    font-weight: 600;
    margin-bottom: 2px;
}

.footer-left .fl2 {
    font-size: clamp(0.55rem, 1.5vw, 0.7rem);
}

.footer-links {
    display: flex;
    gap: 1rem;
    flex-wrap: wrap;
}

.footer-links a {
    font-size: clamp(0.55rem, 1.5vw, 0.73rem);
    color: rgba(255,255,255,0.36);
    text-decoration: none;
    transition: color 0.15s;
}

.footer-links a:hover {
    color: rgba(255,255,255,0.75);
}

/* ── HAMBURGER BUTTON ── */
.hamburger-btn {
    display: none;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    gap: 5px;
    width: 40px;
    height: 40px;
    background: rgba(255,255,255,0.12);
    border: 1.5px solid rgba(255,255,255,0.28);
    border-radius: var(--radius-md);
    cursor: pointer;
    flex-shrink: 0;
    padding: 0;
}

.hamburger-btn span {
    display: block;
    width: 20px;
    height: 2px;
    background: #fff;
    border-radius: 2px;
    transition: all 0.25s;
}

.hamburger-btn.open span:nth-child(1) {
    transform: translateY(7px) rotate(45deg);
}

.hamburger-btn.open span:nth-child(2) {
    opacity: 0;
    transform: scaleX(0);
}

.hamburger-btn.open span:nth-child(3) {
    transform: translateY(-7px) rotate(-45deg);
}

/* ── MOBILE NAV DRAWER ── */
.mobile-nav-drawer {
    display: none;
    position: fixed;
    top: 0;
    right: 0;
    bottom: 0;
    width: 240px;
    background: linear-gradient(160deg, #064e3b 0%, #0c3f7f 100%);
    z-index: 300;
    flex-direction: column;
    padding: 1.5rem 1.25rem;
    gap: 0.75rem;
    box-shadow: -6px 0 24px rgba(0,0,0,0.35);
    transform: translateX(100%);
    transition: transform 0.28s cubic-bezier(0.4,0,0.2,1);
}

.mobile-nav-drawer.open {
    transform: translateX(0);
}

.mobile-nav-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 299;
    backdrop-filter: blur(2px);
}

.mobile-nav-overlay.open {
    display: block;
}

.drawer-close {
    align-self: flex-end;
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 50%;
    width: 34px;
    height: 34px;
    color: white;
    font-size: 1.2rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 0.5rem;
}

.drawer-link {
    display: flex;
    align-items: center;
    gap: 10px;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: var(--radius-md);
    padding: 12px 16px;
    color: white;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    transition: background 0.18s;
}

.drawer-link:hover {
    background: rgba(255,255,255,0.22);
}

/* ── MOBILE FILTER TOGGLE BUTTON ── */
.filter-toggle-btn {
    display: none;
    align-items: center;
    gap: 8px;
    background: white;
    border: 1px solid var(--slate-300);
    border-radius: 48px;
    padding: 8px 12px 8px 18px;
    font-weight: 700;
    font-size: 0.82rem;
    color: var(--slate-700);
    cursor: pointer;
    box-shadow: var(--shadow-sm);
    width: 100%;
    justify-content: space-between;
    transition: all 0.2s;
}

.filter-toggle-btn:hover {
    background: var(--slate-50);
    border-color: var(--slate-400);
}

.filter-toggle-btn .filter-btn-left {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1;
}

.filter-toggle-btn .filter-btn-right {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
}

.filter-toggle-btn .filter-badge {
    background: var(--green-500);
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 0.6rem;
    display: none;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.filter-toggle-btn .filter-badge.visible {
    display: flex;
}

.filter-chevron {
    width: 18px;
    height: 18px;
    color: var(--slate-400);
    transition: transform 0.25s ease;
    flex-shrink: 0;
}

.filter-toggle-btn.open .filter-chevron {
    transform: rotate(180deg);
}

/* ── MOBILE FILTER PANEL ── */
.filter-panel-mobile {
    display: none;
    flex-direction: column;
    gap: 0.6rem;
    background: white;
    border-radius: var(--radius-lg);
    border: 1px solid var(--slate-200);
    box-shadow: var(--shadow-md);
    padding: 0.85rem;
    max-height: 65vh;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
    width: 100%;
}

.filter-panel-mobile.open {
    display: flex;
}

.filter-panel-mobile .filter-item {
    flex: 1 1 100%;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}

.filter-panel-mobile .filter-item select {
    width: 100%;
}

.filter-panel-mobile .clear-btn {
    width: 100%;
    justify-content: center;
    margin-top: 4px;
}

/* ── RESPONSIVE OVERRIDES ── */
@media (max-width: 640px) {
    :root {
        --header-h: auto;
    }
    .header {
        padding: 0 1rem;
        height: auto;
        min-height: 72px;
        gap: 0.6rem;
    }
    .brand {
        gap: 8px;
    }
    .logo-img-wrap {
        width: 42px;
        height: 42px;
    }
    .brand-text .name {
        font-size: 0.85rem;
        white-space: normal;
        word-break: keep-all;
    }
    .brand-text .sub {
        font-size: 0.65rem;
        white-space: normal;
    }
    .header-sep {
        display: none;
    }
    .header-actions {
        display: none;
    }
    .hamburger-btn {
        display: flex;
    }
    .mobile-nav-drawer {
        display: flex;
    }
    .filter-group {
        display: none;
    }
    .filter-toggle-btn {
        display: flex;
    }
    .toolbar {
        position: relative;
        top: 0;
    }
    .stats-grid {
        grid-template-columns: repeat(5, minmax(88px, 1fr));
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 4px;
        scroll-snap-type: x mandatory;
        gap: 0.5rem;
    }
    .stats-grid::-webkit-scrollbar {
        display: none;
    }
    .stat-card {
        scroll-snap-align: start;
        flex-shrink: 0;
        padding: 0.65rem 0.75rem;
        min-width: 88px;
    }
    .stat-value {
        font-size: 1.35rem;
    }
    .stat-label {
        font-size: 0.56rem;
    }
    .stat-desc {
        display: none;
    }
    .legend {
        justify-content: flex-start;
        gap: 0.4rem;
    }
    .legend-item {
        font-size: 0.62rem;
        padding: 4px 10px;
        gap: 6px;
    }
    .toolbar-controls {
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
    }
    .mobile-view-ongoing-row {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 0.5rem;
        width: 100%;
    }
    .nav-group {
        margin-left: 0;
        width: 100%;
        justify-content: center;
    }
    #btnPrev {
        order: 1;
    }
    .period-label {
        order: 2;
    }
    #btnNext {
        order: 3;
    }
    #datePickerNav {
        order: 4;
    }
    #btnToday {
        order: 5;
    }
}

@media (max-width: 420px) {
    .stat-card {
        min-width: 80px;
    }
    .stat-value {
        font-size: 1.2rem;
    }
}
    </style>
</head>
<body>
    <!-- Mobile nav overlay and drawer (unchanged) -->
    <div class="mobile-nav-overlay" id="mobileNavOverlay"></div>
    <div class="mobile-nav-drawer" id="mobileNavDrawer">
        <button class="drawer-close" id="drawerClose">&times;</button>
        <a href="index.php" class="drawer-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
            Home
        </a>
        <a href="login.php" class="drawer-link">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
            Sign In
        </a>
    </div>

    <header class="header">
        <div class="brand">
            <div class="logo-img-wrap"><img src="images/bau-logo.png" alt="BAU Logo"></div>
            <div class="brand-text">
                <div class="name">Bangladesh Agricultural University</div>
                <div class="sub">Classroom Management System</div>
            </div>
        </div>
        <div class="header-sep"></div>
        <div class="header-actions">
            <a href="index.php" class="back-home">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Home
            </a>
            <a href="login.php" class="signin-btn">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Sign In
            </a>
        </div>
        <button class="hamburger-btn" id="hamburgerBtn" aria-label="Menu"><span></span><span></span><span></span></button>
    </header>

    <div class="toolbar">
        <div class="filter-group">
            <div class="filter-item"><label>🏛️ Faculty</label><select id="facultyFilter"><option value="">All Faculties</option><?php foreach ($allFaculties as $fac): ?><option value="<?= $fac['id'] ?>" <?= ($faculty_filter == $fac['id']) ? 'selected' : '' ?>><?= htmlspecialchars($fac['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>🏢 Building</label><select id="buildingFilter"><option value="">All Buildings</option><?php foreach ($allBuildings as $b): ?><option value="<?= $b['id'] ?>" data-faculty-id="<?= $b['faculty_id'] ?>" <?= ($building_id == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>📘 Degree</label><select id="degreeFilter"><option value="">All Degrees</option><?php foreach ($degreePrograms as $dp): ?><option value="<?= $dp['id'] ?>" data-total-levels="<?= $dp['total_levels'] ?>" data-spl="<?= $dp['semesters_per_level'] ?>" <?= ($degree_id == $dp['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dp['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>📖 Level‑Sem</label><select id="levelSemesterFilter" disabled><option value="">Select a degree first</option></select></div>
            <button id="clearFiltersBtn" class="clear-btn">✖ Clear All</button>
        </div>

        <button class="filter-toggle-btn" id="filterToggleBtn">
            <span class="filter-btn-left"><span>⚙️ Select Faculty, Building, Degree etc.</span></span>
            <span class="filter-btn-right"><span class="filter-badge" id="filterBadge"></span><svg class="filter-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></span>
        </button>
        <div class="filter-panel-mobile" id="filterPanelMobile">
            <div class="filter-item"><label>🏛️ Faculty</label><select id="mFacultyFilter"><option value="">All Faculties</option><?php foreach ($allFaculties as $fac): ?><option value="<?= $fac['id'] ?>" <?= ($faculty_filter == $fac['id']) ? 'selected' : '' ?>><?= htmlspecialchars($fac['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>🏢 Building</label><select id="mBuildingFilter"><option value="">All Buildings</option><?php foreach ($allBuildings as $b): ?><option value="<?= $b['id'] ?>" data-faculty-id="<?= $b['faculty_id'] ?>" <?= ($building_id == $b['id']) ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>📘 Degree</label><select id="mDegreeFilter"><option value="">All Degrees</option><?php foreach ($degreePrograms as $dp): ?><option value="<?= $dp['id'] ?>" data-total-levels="<?= $dp['total_levels'] ?>" data-spl="<?= $dp['semesters_per_level'] ?>" <?= ($degree_id == $dp['id']) ? 'selected' : '' ?>><?= htmlspecialchars($dp['name']) ?></option><?php endforeach; ?></select></div>
            <div class="filter-item"><label>📖 Level‑Semester</label><select id="mLevelSemesterFilter" disabled><option value="">Select a degree first</option></select></div>
            <button id="mClearFiltersBtn" class="clear-btn">✖ Clear All Filters</button>
        </div>

        <div class="toolbar-controls">
            <div class="mobile-view-ongoing-row">
                <div class="view-tabs"><span data-view="week" class="view-tab <?= $view === 'week' ? 'active' : '' ?>">📅 Week</span><span data-view="day" class="view-tab <?= $view === 'day' ? 'active' : '' ?>">☀️ Day</span><button id="ongoingBtn" class="ongoing-btn <?= $ongoing_only ? 'active' : '' ?>">🔴 Ongoing</button></div>
            </div>
            <div class="nav-group">
                <button class="nav-btn" id="btnPrev">← Prev</button>
                <span class="period-label" id="periodLabel"><?= $view === 'week' ? $weekRangeLabel : date('D, d M Y', strtotime($date)) ?></span>
                <button class="nav-btn" id="btnNext">Next →</button>
                <input type="date" id="datePickerNav" class="date-picker-nav" value="<?= $view === 'week' ? $weekDays[0]['date'] : $date ?>">
                <button class="nav-btn" id="btnToday" style="background: var(--green-600); color: white;"><?= $view === 'week' ? 'This Week' : 'Today' ?></button>
            </div>
        </div>
    </div>

    <div class="main">
    <?php if (empty($rooms)): ?>
        <div class="empty-state">
            <?php if ($ongoing_only): ?>
                🔴 No ongoing classes at this moment.<br><small>Try disabling "Ongoing" filter.</small>
            <?php else: ?>
                🏚️ No classrooms found for selected filter.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="stats-grid">
            <div class="stat-card"><div class="stat-value"><?= $totalSlots ?></div><div class="stat-label"><?= $view === 'week' ? 'Weekly Capacity' : 'Daily Capacity' ?></div><div class="stat-desc"><?php if ($view === 'week'): ?><?= count($rooms) ?> rooms × <?= $slotsPerDay ?> slots/day × 5 days<?php else: ?><?= count($rooms) ?> rooms × <?= $slotsPerDay ?> periods<?php endif; ?></div></div>
            <div class="stat-card"><div class="stat-value"><?= $classSlots ?></div><div class="stat-label">Regular Schedule</div><div class="stat-desc">Slots with recurring classes</div></div>
            <div class="stat-card"><div class="stat-value"><?= $bookedSlots ?></div><div class="stat-label">Ad‑hoc Bookings</div><div class="stat-desc">One‑time events & make‑up sessions</div></div>
            <div class="stat-card"><div class="stat-value"><?= $freeSlots ?></div><div class="stat-label">Remaining Vacancy</div><div class="stat-desc">Open slots for new reservations</div></div>
            <div class="stat-card"><div class="stat-value"><?= $utilizationPercent ?>%</div><div class="stat-label">Occupancy Rate</div><div class="stat-desc"><?= $view === 'week' ? 'Weekly' : 'Daily' ?> capacity booked</div></div>
        </div>
        <div class="legend">
            <div class="legend-item"><span style="background:#2b9348; width:12px; height:12px; border-radius:50%; flex-shrink:0;"></span> Available</div>
            <div class="legend-item"><span style="background:#e63946; width:12px; height:12px; border-radius:50%; flex-shrink:0;"></span> Class</div>
            <div class="legend-item"><span style="background:#f4a261; width:12px; height:12px; border-radius:50%; flex-shrink:0;"></span> Booked</div>
            <div class="legend-item"><span style="background:linear-gradient(90deg,#e63946,#f4a261,#2b9348); width:24px; height:7px; border-radius:6px; flex-shrink:0;"></span> Occupancy Stack</div>
        </div>
        <div class="cal-wrapper">
            <?php if ($view === 'week'): ?>
                <div class="cal-grid week">
                    <div class="cal-cell hdr"></div>
                    <?php foreach ($weekDays as $wd): ?><div class="cal-cell hdr <?= $wd['is_today'] ? 'today' : '' ?>"><div style="font-size:clamp(0.85rem,3vw,1rem); font-weight:800;"><?= $wd['day_number'] ?></div><div style="font-size:clamp(0.55rem,1.4vw,0.65rem);"><?= $wd['day_name'] ?></div></div><?php endforeach; ?>
                    <?php foreach ($rooms as $room): ?>
                        <div class="cal-cell room-name"><div class="rn-building"><?= htmlspecialchars($room['building_name']) ?></div><div class="rn-name"><?= htmlspecialchars($room['room_name']) ?></div><div class="rn-cap">🪑 <?= $room['capacity'] ?> seats</div></div>
                        <?php foreach ($weekDays as $wd):
                            $rs = $weekStatuses[$wd['date']][$room['id']] ?? null;
                            $total = count($timeSlots);
                            $occupiedCnt = $classCnt = $bookedCnt = 0;
                            $uniqueItems = [];
                            if ($rs) {
                                foreach ($rs['slots'] as $s) {
                                    if ($s['status'] === 'class') {
                                        $occupiedCnt++;
                                        $classCnt++;
                                        $key = 'class_' . $s['data']['id'];
                                        if (!isset($uniqueItems[$key])) {
                                            $uniqueItems[$key] = $s['data'];
                                        }
                                    } elseif ($s['status'] === 'booked') {
                                        $occupiedCnt++;
                                        $bookedCnt++;
                                        $key = 'booking_' . $s['data']['id'];
                                        if (!isset($uniqueItems[$key])) {
                                            $uniqueItems[$key] = $s['data'];
                                        }
                                    }
                                }
                            }
                            $avail = $total - $occupiedCnt;
                            $classPct  = $total ? ($classCnt / $total) * 100 : 0;
                            $bookedPct = $total ? ($bookedCnt / $total) * 100 : 0;
                            $combinedList = array_values($uniqueItems);
                            $jsonCombined = htmlspecialchars(json_encode($combinedList), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="cal-cell week-cell <?= $wd['is_today'] ? 'today-highlight' : '' ?>" data-room-name="<?= htmlspecialchars($room['room_name']) ?>" data-building="<?= htmlspecialchars($room['building_name']) ?>" data-date="<?= $wd['date'] ?>" data-items='<?= $jsonCombined ?>'>
                                <div class="week-stats">
                                    <div class="stat-badge-group"><span class="stat-badge-sm occupied">📖 <?= $occupiedCnt ?></span><span class="stat-badge-sm avail">✅ <?= $avail ?></span></div>
                                    <div class="progress-stack"><div class="progress-class" style="width: <?= $classPct ?>%"></div><div class="progress-booked" style="width: <?= $bookedPct ?>%"></div><div class="progress-avail" style="width: <?= 100 - $classPct - $bookedPct ?>%"></div></div>
                                    <div style="font-size:clamp(0.5rem,1.2vw,0.6rem); text-align:center; font-weight:500;"><?= $occupiedCnt ?>/<?= $total ?> used</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <?php $dayRooms = $weekStatuses[$date] ?? roomStatusForDate($pdo, $date, $rooms, $timeSlots, $degree_id, $level, $semester); ?>
                <div class="cal-grid day">
                    <div class="cal-cell hdr"></div>
                    <?php foreach ($timeSlots as $slot): $isRecess = ($slot['start_time'] == '13:00:00' && $slot['end_time'] == '14:00:00'); $isCurrent = ($currentSlot && $slot['id'] == $currentSlot['id'] && $date == date('Y-m-d')); ?>
                        <div class="cal-cell hdr <?= $isRecess ? 'recess-slot' : '' ?> <?= $isCurrent ? 'current-slot-highlight' : '' ?>"><strong style="font-size:clamp(0.55rem,1.3vw,0.7rem);"><?= date('g:i', strtotime($slot['start_time'])) ?>–<?= date('g:i A', strtotime($slot['end_time'])) ?></strong><?php if ($isRecess): ?><span style="display:block; font-size:clamp(0.45rem,1.1vw,0.55rem); color:#b45309;">🍽️ Recess</span><?php endif; ?><?php if ($isCurrent): ?><span style="display:block; font-size:clamp(0.45rem,1.1vw,0.55rem); color:var(--danger); margin-top:2px;">🔴 NOW</span><?php endif; ?></div>
                    <?php endforeach; ?>
                    <?php foreach ($rooms as $room): $roomStatus = $dayRooms[$room['id']] ?? null; if (!$roomStatus) continue; $slotsData = $roomStatus['slots']; $groups = []; $currentGroup = null; foreach ($timeSlots as $idx => $slot) { $slotId = $slot['id']; $slotInfo = $slotsData[$slotId] ?? ['status' => 'available', 'data' => null]; $status = $slotInfo['status']; $data = $slotInfo['data']; $groupId = $status === 'class' ? 'class_' . ($data['id'] ?? 'u') : ($status === 'booked' ? 'booked_' . ($data['id'] ?? 'u') : 'available_' . $idx); if ($currentGroup && $currentGroup['groupId'] === $groupId && $currentGroup['status'] === $status) { $currentGroup['slots'][] = $slot; $currentGroup['endIdx'] = $idx; } else { if ($currentGroup) $groups[] = $currentGroup; $currentGroup = ['groupId' => $groupId, 'status' => $status, 'data' => $data, 'slots' => [$slot], 'startIdx' => $idx, 'endIdx' => $idx]; } } if ($currentGroup) $groups[] = $currentGroup; ?>
                        <div class="cal-cell room-name"><div class="rn-building"><?= htmlspecialchars($room['building_name']) ?></div><div class="rn-name"><?= htmlspecialchars($room['room_name']) ?></div></div>
                        <?php foreach ($groups as $group): $span = count($group['slots']); $status = $group['status']; $data = $group['data']; $detail = ''; $jsonData = 'null'; if ($data) { $jsonData = htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8'); if ($status === 'class') { $detail = '<div class="slot-class-content"><span class="slot-degree">' . htmlspecialchars(shortDegreeName($data['degree_name'] ?? '')) . ' L' . $data['level'] . ' S' . $data['semester'] . '</span><span class="slot-course">' . htmlspecialchars($data['course_code'] ?? '') . '</span><span class="slot-teacher">👨‍🏫 ' . htmlspecialchars($data['teacher_name'] ?? 'N/A') . '</span></div>'; } elseif ($status === 'booked') { $detail = '<div><strong>' . htmlspecialchars($data['purpose'] ?? 'Reserved') . '</strong><br><small>by ' . htmlspecialchars($data['booked_by'] ?? '') . '</small></div>'; } } $bg = $status === 'class' ? 'slot-class' : ($status === 'booked' ? 'slot-booked' : 'slot-available'); $isOngoing = ($status === 'class' && $currentSlot && in_array($currentSlot['id'], array_column($group['slots'], 'id')) && $date == date('Y-m-d')); $firstSlot = $group['slots'][0]; $timeDisplay = date('g:i A', strtotime($firstSlot['start_time'])) . ' – ' . date('g:i A', strtotime($group['slots'][count($group['slots'])-1]['end_time'])); ?>
                            <div class="cal-cell" style="grid-column: span <?= $span ?>;"><div class="slot-card <?= $bg ?> <?= $isOngoing ? 'blink-red' : '' ?> merged-slot" data-detail='<?= $jsonData ?>' data-room="<?= htmlspecialchars($room['room_name']) ?>" data-building="<?= htmlspecialchars($room['building_name']) ?>" data-time="<?= $timeDisplay ?>"><div style="font-weight:700;"><?= ($status === 'class' || $status === 'booked') ? 'Occupied' : 'Free' ?></div><?php if ($detail): ?><div style="margin-top:3px;"><?= $detail ?></div><?php endif; ?><?php if ($isOngoing): ?><div style="font-size:clamp(0.45rem,1.1vw,0.55rem); margin-top:2px;">🔴 LIVE NOW</div><?php endif; ?></div></div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

    <!-- Modals -->
    <div id="detailModal" class="modal"><div class="modal-content"><div class="modal-header"><span id="modalTitle">Details</span><button class="modal-close">&times;</button></div><div class="modal-body" id="modalBody"></div></div></div>
    <div id="noOngoingModal" class="modal"><div class="modal-content"><div class="modal-header"><span>🔴 Ongoing Classes</span><button class="modal-close">&times;</button></div><div class="modal-body"><p>There are no ongoing classes at this moment.</p><p style="margin-top:10px; font-size:0.85rem; color:#64748b;">Please disable the "Ongoing" filter to see the full calendar.</p></div></div></div>

    <footer class="footer">
        <div class="footer-left"><div class="fl1">Bangladesh Agricultural University · Classroom Management System</div><div class="fl2">&copy; <?= date('Y') ?> BAU · Team TinUstad</div></div>
        <div class="footer-links"><a href="#">Privacy</a><a href="#">Terms</a><a href="#">Support</a><a href="#">Help</a></div>
    </footer>

    <script>
    (function() {
        const view = '<?= $view ?>';
        let currentDate = '<?= $date ?>';
        const degreePrograms = <?= json_encode($degreePrograms) ?>;
        let hasOngoing = <?= json_encode($hasOngoingClasses) ?>;
        let ongoingActive = <?= $ongoing_only ? 'true' : 'false' ?>;

        function updateUrl(params) {
            const urlParams = new URLSearchParams();
            const faculty = document.getElementById('facultyFilter')?.value || '';
            const building = document.getElementById('buildingFilter')?.value || '';
            const degree = document.getElementById('degreeFilter')?.value || '';
            const levelSemesterVal = document.getElementById('levelSemesterFilter')?.value || '';
            let level = '', semester = '';
            if (levelSemesterVal && levelSemesterVal !== 'all') {
                const parts = levelSemesterVal.split('_');
                level = parts[0] || '';
                semester = parts[1] || '';
            }
            const viewParam = params.view !== undefined ? params.view : view;
            const dateParam = params.date !== undefined ? params.date : currentDate;
            if (faculty) urlParams.set('faculty', faculty);
            if (building) urlParams.set('building', building);
            if (degree) urlParams.set('degree', degree);
            if (level) urlParams.set('level', level);
            if (semester) urlParams.set('semester', semester);
            urlParams.set('view', viewParam);
            urlParams.set('date', dateParam);
            if (params.ongoing !== undefined) { if (params.ongoing) urlParams.set('ongoing', '1'); }
            else if (ongoingActive) urlParams.set('ongoing', '1');
            window.location.href = '?' + urlParams.toString();
        }

        function showNoOngoingModalAndClear() {
            const modal = document.getElementById('noOngoingModal');
            modal.classList.add('active');
            const url = new URL(window.location.href);
            url.searchParams.delete('ongoing');
            window.history.replaceState({}, '', url);
            ongoingActive = false;
            document.getElementById('ongoingBtn').classList.remove('active');
            const closeAndReload = () => { modal.classList.remove('active'); window.location.href = url.toString(); };
            modal.querySelector('.modal-close').onclick = closeAndReload;
            modal.onclick = (e) => { if (e.target === modal) closeAndReload(); };
        }

        document.getElementById('clearFiltersBtn')?.addEventListener('click', () => {
            window.location.href = '?view=week&date=' + new Date().toISOString().slice(0,10);
        });
        document.getElementById('facultyFilter')?.addEventListener('change', (e) => { updateUrl({ faculty: e.target.value || '', building: '' }); });
        document.getElementById('buildingFilter')?.addEventListener('change', (e) => { updateUrl({ building: e.target.value || '' }); });

        const degreeSelect = document.getElementById('degreeFilter');
        const lsSelect = document.getElementById('levelSemesterFilter');
        const datePickerNav = document.getElementById('datePickerNav');

        function populateLevelSemester(degreeId, selectedValue = null) {
            if (!degreeId) { lsSelect.innerHTML = '<option value="">Select a degree first</option>'; lsSelect.disabled = true; return; }
            const dp = degreePrograms.find(d => d.id == degreeId);
            if (!dp) { lsSelect.innerHTML = '<option value="">Select a degree first</option>'; lsSelect.disabled = true; return; }
            lsSelect.disabled = false;
            let html = '<option value="all">All</option>';
            for (let lvl = 1; lvl <= dp.total_levels; lvl++) {
                for (let sem = 1; sem <= dp.semesters_per_level; sem++) {
                    const val = lvl + '_' + sem;
                    html += `<option value="${val}" ${selectedValue === val ? 'selected' : ''}>Level ${lvl} – Semester ${sem}</option>`;
                }
            }
            lsSelect.innerHTML = html;
            if (!selectedValue) lsSelect.value = 'all';
        }

        degreeSelect?.addEventListener('change', (e) => {
            populateLevelSemester(e.target.value, null);
            updateUrl({ degree: e.target.value || '', level: '', semester: '' });
        });
        lsSelect?.addEventListener('change', (e) => {
            const val = e.target.value;
            if (val === 'all') updateUrl({ level: '', semester: '' });
            else { const p = val.split('_'); updateUrl({ level: p[0], semester: p[1] }); }
        });

        datePickerNav?.addEventListener('change', (e) => {
            let newDate = e.target.value;
            if (view === 'week') {
                const picked = new Date(newDate + 'T12:00:00');
                const sunday = new Date(picked);
                sunday.setDate(picked.getDate() - picked.getDay());
                newDate = sunday.toISOString().slice(0,10);
            }
            updateUrl({ date: newDate });
        });

        document.querySelectorAll('.view-tab').forEach(tab => {
            tab.addEventListener('click', () => { const nv = tab.getAttribute('data-view'); if (nv !== view) updateUrl({ view: nv }); });
        });

        document.getElementById('btnPrev')?.addEventListener('click', () => {
            let d = new Date(currentDate + 'T12:00:00');
            d.setDate(d.getDate() + (view === 'week' ? -7 : -1));
            updateUrl({ date: d.toISOString().slice(0,10) });
        });
        document.getElementById('btnNext')?.addEventListener('click', () => {
            let d = new Date(currentDate + 'T12:00:00');
            d.setDate(d.getDate() + (view === 'week' ? 7 : 1));
            updateUrl({ date: d.toISOString().slice(0,10) });
        });
        document.getElementById('btnToday')?.addEventListener('click', () => {
            if (view === 'week') {
                const today = new Date();
                const sunday = new Date(today);
                sunday.setDate(today.getDate() - today.getDay());
                updateUrl({ date: sunday.toISOString().slice(0,10) });
            } else {
                updateUrl({ date: new Date().toISOString().slice(0,10) });
            }
        });

        const ongoingBtn = document.getElementById('ongoingBtn');
        ongoingBtn?.addEventListener('click', () => {
            if (!hasOngoing && !ongoingBtn.classList.contains('active')) showNoOngoingModalAndClear();
            else updateUrl({ ongoing: !ongoingBtn.classList.contains('active') });
        });

        <?php if ($showNoOngoingModal): ?>
        window.addEventListener('load', () => { showNoOngoingModalAndClear(); });
        <?php endif; ?>

        // Modal logic
        const modal = document.getElementById('detailModal');
        const modalTitle = document.getElementById('modalTitle');
        const modalBody = document.getElementById('modalBody');
        function closeModal() { modal.classList.remove('active'); }
        document.querySelectorAll('#detailModal .modal-close').forEach(btn => btn.addEventListener('click', closeModal));
        modal?.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

        document.querySelectorAll('.week-cell').forEach(cell => {
            cell.addEventListener('click', () => {
                const roomName = cell.dataset.roomName;
                const building = cell.dataset.building;
                const date = cell.dataset.date;
                let items = [];
                try { items = JSON.parse(cell.dataset.items || '[]'); } catch(e) { items = []; }
                modalTitle.innerText = `${roomName} (${building}) - ${date}`;
                if (items.length === 0) {
                    modalBody.innerHTML = '<p>No classes or bookings on this day.</p>';
                } else {
                    let html = '<div style="margin-bottom:12px;"><strong>📋 Scheduled items:</strong></div>';
                    items.forEach(item => {
                        if (item.type === 'class') {
                            html += `<div class="class-list-item" data-detail='${JSON.stringify(item)}'>
                                        <div><strong>📖 Class:</strong> ${item.course_code} - ${item.course_name || ''}</div>
                                        <div style="font-size:0.7rem; color:var(--slate-600);">${item.start_time} – ${item.end_time}</div>
                                        <div style="font-size:0.65rem; margin-top:4px;">👨‍🏫 ${item.teacher_name || 'N/A'}</div>
                                     </div>`;
                        } else if (item.type === 'booking') {
                            html += `<div class="booking-list-item" data-detail='${JSON.stringify(item)}'>
                                        <div><strong>📅 Booking:</strong> ${item.purpose || 'Reserved'}</div>
                                        <div style="font-size:0.7rem; color:var(--slate-600);">${item.start_time} – ${item.end_time}</div>
                                        <div style="font-size:0.65rem; margin-top:4px;">👤 ${item.booked_by || 'Unknown'}</div>
                                     </div>`;
                        }
                    });
                    modalBody.innerHTML = html;
                    document.querySelectorAll('.class-list-item, .booking-list-item').forEach(itemDiv => {
                        itemDiv.addEventListener('click', (e) => {
                            e.stopPropagation();
                            const detail = JSON.parse(itemDiv.dataset.detail);
                            if (detail.type === 'class') showClassDetail(detail, roomName, building);
                            else if (detail.type === 'booking') showBookingDetail(detail, roomName, building);
                        });
                    });
                }
                modal.classList.add('active');
            });
        });

        document.querySelectorAll('.slot-card').forEach(slot => {
            slot.addEventListener('click', () => {
                const detailStr = slot.dataset.detail;
                if (!detailStr || detailStr === 'null') return;
                const detail = JSON.parse(detailStr);
                const room = slot.dataset.room, building = slot.dataset.building, time = slot.dataset.time;
                if (detail.type === 'class') showClassDetail(detail, room, building, time);
                else if (detail.type === 'booking') showBookingDetail(detail, room, building, time);
            });
        });

        function showClassDetail(cls, room, building, timeSlot = null) {
            modalTitle.innerText = `📖 Class: ${cls.course_code}`;
            modalBody.innerHTML = `
                <div class="detail-row"><div class="detail-label">Room:</div><div class="detail-value">${room} (${building})</div></div>
                <div class="detail-row"><div class="detail-label">Time:</div><div class="detail-value">${timeSlot || (cls.start_time + ' – ' + cls.end_time)}</div></div>
                <div class="detail-row"><div class="detail-label">Degree:</div><div class="detail-value">${cls.degree_name || 'N/A'} (Level ${cls.level}, Sem ${cls.semester})</div></div>
                <div class="detail-row"><div class="detail-label">Course:</div><div class="detail-value">${cls.course_code} - ${cls.course_name || ''}</div></div>
                <div class="detail-row"><div class="detail-label">Teacher:</div><div class="detail-value">${cls.teacher_name || 'N/A'}<br>📞 ${cls.teacher_phone || 'No phone'}</div></div>
            `;
            modal.classList.add('active');
        }

        function showBookingDetail(booking, room, building, timeSlot) {
            modalTitle.innerText = `📅 Booking: ${booking.purpose}`;
            modalBody.innerHTML = `
                <div class="detail-row"><div class="detail-label">Room:</div><div class="detail-value">${room} (${building})</div></div>
                <div class="detail-row"><div class="detail-label">Time:</div><div class="detail-value">${timeSlot || (booking.start_time + ' – ' + booking.end_time)}</div></div>
                <div class="detail-row"><div class="detail-label">Purpose:</div><div class="detail-value">${booking.purpose}</div></div>
                <div class="detail-row"><div class="detail-label">Booked by:</div><div class="detail-value">${booking.booked_by || 'Unknown'}<br>📞 ${booking.booked_by_phone || 'No phone'}</div></div>
            `;
            modal.classList.add('active');
        }

        // Init level-semester dropdown
        const curDegreeId = '<?= $degree_id ?>';
        const curLevel = '<?= $level ?>';
        const curSemester = '<?= $semester ?>';
        if (curDegreeId) {
            const selectedVal = (curLevel && curSemester) ? curLevel + '_' + curSemester : null;
            populateLevelSemester(curDegreeId, selectedVal);
            if (!selectedVal && lsSelect) lsSelect.value = 'all';
        } else {
            if (lsSelect) { lsSelect.innerHTML = '<option value="">Select a degree first</option>'; lsSelect.disabled = true; }
        }

        if (datePickerNav) {
            datePickerNav.value = view === 'week' ? '<?= $weekDays[0]['date'] ?>' : '<?= $date ?>';
        }

        // Hamburger menu
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const mobileNavDrawer = document.getElementById('mobileNavDrawer');
        const mobileNavOverlay = document.getElementById('mobileNavOverlay');
        const drawerClose = document.getElementById('drawerClose');
        function openDrawer() { mobileNavDrawer.classList.add('open'); mobileNavOverlay.classList.add('open'); hamburgerBtn.classList.add('open'); }
        function closeDrawer() { mobileNavDrawer.classList.remove('open'); mobileNavOverlay.classList.remove('open'); hamburgerBtn.classList.remove('open'); }
        hamburgerBtn?.addEventListener('click', () => mobileNavDrawer.classList.contains('open') ? closeDrawer() : openDrawer());
        drawerClose?.addEventListener('click', closeDrawer);
        mobileNavOverlay?.addEventListener('click', closeDrawer);

        // Mobile filter toggle
        const filterToggleBtn = document.getElementById('filterToggleBtn');
        const filterPanelMobile = document.getElementById('filterPanelMobile');
        const filterBadge = document.getElementById('filterBadge');

        function updateFilterBadge() {
            const activeCount = [
                document.getElementById('mFacultyFilter')?.value,
                document.getElementById('mBuildingFilter')?.value,
                document.getElementById('mDegreeFilter')?.value,
                document.getElementById('mLevelSemesterFilter')?.value,
            ].filter(v => v && v !== '' && v !== 'all').length;
            if (filterBadge) {
                filterBadge.textContent = activeCount;
                filterBadge.classList.toggle('visible', activeCount > 0);
            }
        }

        filterToggleBtn?.addEventListener('click', () => {
            const isOpen = filterPanelMobile.classList.toggle('open');
            filterToggleBtn.classList.toggle('open', isOpen);
        });

        const mDegreeSelect = document.getElementById('mDegreeFilter');
        const mLsSelect = document.getElementById('mLevelSemesterFilter');

        function populateMobileLevelSemester(degreeId, selectedValue = null) {
            if (!degreeId) { mLsSelect.innerHTML = '<option value="">Select a degree first</option>'; mLsSelect.disabled = true; return; }
            const dp = degreePrograms.find(d => d.id == degreeId);
            if (!dp) { mLsSelect.innerHTML = '<option value="">Select a degree first</option>'; mLsSelect.disabled = true; return; }
            mLsSelect.disabled = false;
            let html = '<option value="all">All</option>';
            for (let lvl = 1; lvl <= dp.total_levels; lvl++) {
                for (let sem = 1; sem <= dp.semesters_per_level; sem++) {
                    const val = lvl + '_' + sem;
                    html += `<option value="${val}" ${selectedValue === val ? 'selected' : ''}>Level ${lvl} – Semester ${sem}</option>`;
                }
            }
            mLsSelect.innerHTML = html;
            if (!selectedValue) mLsSelect.value = 'all';
        }

        document.getElementById('mFacultyFilter')?.addEventListener('change', (e) => { updateFilterBadge(); updateUrl({ faculty: e.target.value || '', building: '' }); });
        document.getElementById('mBuildingFilter')?.addEventListener('change', (e) => { updateFilterBadge(); updateUrl({ building: e.target.value || '' }); });
        mDegreeSelect?.addEventListener('change', (e) => {
            populateMobileLevelSemester(e.target.value, null);
            updateFilterBadge();
            updateUrl({ degree: e.target.value || '', level: '', semester: '' });
        });
        mLsSelect?.addEventListener('change', (e) => {
            updateFilterBadge();
            const val = e.target.value;
            if (val === 'all') updateUrl({ level: '', semester: '' });
            else { const p = val.split('_'); updateUrl({ level: p[0], semester: p[1] }); }
        });
        document.getElementById('mClearFiltersBtn')?.addEventListener('click', () => {
            window.location.href = '?view=week&date=' + new Date().toISOString().slice(0,10);
        });

        if (curDegreeId) {
            const selectedVal = (curLevel && curSemester) ? curLevel + '_' + curSemester : null;
            populateMobileLevelSemester(curDegreeId, selectedVal);
        }
        updateFilterBadge();
    })();
    </script>
</body>
</html>