<?php
require_once 'db_config.php';
require_once 'room_status.php'; // Added for room status display

// Check if user is logged in and is CR
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'cr') {
    header('Location: login.php');
    exit();
}

$cr_id = $_SESSION['user_id'];
$cr_name = $_SESSION['full_name'];
$degree_program_id = $_SESSION['degree_program_id'] ?? null;
$assigned_level = $_SESSION['assigned_level'] ?? null;
$assigned_semester = $_SESSION['assigned_semester'] ?? null;
$batch = $_SESSION['batch'] ?? null;

// Get only first two words of user's name
$cr_name_short = implode(' ', array_slice(explode(' ', $cr_name), 0, 2));

// Get CR's degree info
$degree_info = null;
if ($degree_program_id) {
    $stmt = $pdo->prepare("SELECT dp.*, f.name as faculty_name FROM degree_programs dp JOIN faculties f ON dp.faculty_id = f.id WHERE dp.id = ?");
    $stmt->execute([$degree_program_id]);
    $degree_info = $stmt->fetch();
}

// Handle POST requests
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'book_room') {
        $room_id = $_POST['room_id'];
        $purpose = $_POST['purpose'];
        $booking_date = $_POST['booking_date'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];

        $extra_data = [
            'degree_program_id' => $degree_program_id,
            'level' => $assigned_level,
            'semester' => $assigned_semester,
            'group_name' => $_POST['group_name'] ?? null,
            'batch' => $batch
        ];

        $result = bookRoom($pdo, $room_id, $cr_id, $booking_date, $start_time, $end_time, $purpose, $extra_data);

        if ($result['success']) {
            $message = "Room booked successfully!";
        } else {
            $error = $result['error'];
        }
    }

    if (isset($_POST['action']) && $_POST['action'] == 'free_room') {
        $booking_id = $_POST['booking_id'];
        $reason = $_POST['reason'] ?? '';

        $result = freeRoom($pdo, $booking_id, $cr_id, $reason);

        if ($result['success']) {
            $message = "Room freed successfully! The teacher has been notified.";
        } else {
            $error = $result['error'];
        }
    }
}

// Get data
$today = date('Y-m-d');
$current_day = date('N');

// Group's schedule
$group_schedule = [];
if ($degree_program_id && $assigned_level && $assigned_semester) {
    $group_schedule = getCRSchedule($pdo, $degree_program_id, $assigned_level, $assigned_semester);
}

// Today's classes
$todays_classes = array();
if (!empty($group_schedule)) {
    foreach ($group_schedule as $class) {
        if ($class['day_of_week'] == $current_day) {
            $todays_classes[] = $class;
        }
    }
}

// All rooms
$rooms = getRooms($pdo);

// Buildings for filter
$buildings = $pdo->query("SELECT * FROM buildings ORDER BY name")->fetchAll();

// CR's bookings
$stmt = $pdo->prepare("
    SELECT rb.*, r.room_name, r.room_number, b.name as building_name,
           u.full_name as booked_by_name
    FROM room_bookings rb
    JOIN rooms r ON rb.room_id = r.id
    JOIN floors f ON r.floor_id = f.id
    JOIN buildings b ON f.building_id = b.id
    JOIN users u ON rb.booked_by = u.id
    WHERE (rb.booked_by = ? 
           OR (rb.degree_program_id = ? AND rb.level = ? AND rb.semester = ?))
    ORDER BY rb.booking_date DESC, rb.start_time
    LIMIT 50
");
$stmt->execute([$cr_id, $degree_program_id, $assigned_level, $assigned_semester]);
$group_bookings = $stmt->fetchAll();

// Notifications
$notifications = getNotifications($pdo, $cr_id);
$unread_count = getUnreadNotificationCount($pdo, $cr_id);

// Active semester
$semester = getActiveSemester($pdo);

// Stats
$stats = [
    'todays_classes' => count($todays_classes),
    'total_schedules' => count($group_schedule),
    'active_bookings' => count(array_filter($group_bookings, function($b) { return $b['status'] == 'active'; })),
    'unread_notifications' => $unread_count
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CR Dashboard - Classroom Management System</title>
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

        /* Page Blur Overlay */
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

        /* Hamburger Menu */
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

        /* Desktop Sidebar */
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

        /* Mobile Sidebar */
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

        /* Logout Button */
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

        /* Main Content */
        .main-content {
            padding: 30px 20px 20px;
            min-height: 100vh;
        }

        /* Header */
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

        /* Group Info */
        .group-info {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 25px;
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

        /* Stats Grid */
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

        /* Cards */
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

        /* Schedule Cards */
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

        /* Tabs */
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

        /* Week Grid */
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

        /* Tables */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        th, td {
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

        /* Forms */
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

        /* Notifications */
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

        /* Alerts */
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

        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid var(--warning);
        }

        /* Filters */
        .filters {
            display: grid;
            grid-template-columns: 1fr;
            gap: 10px;
            margin-bottom: 20px;
        }

        /* Modal */
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
            max-width: 500px;
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

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        /* Buttons */
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

        /* Button active states */
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

        /* Responsive Styles */
        @media (min-width: 768px) {
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

            .filters {
                grid-template-columns: repeat(4, 1fr);
            }

            .week-grid {
                grid-template-columns: repeat(7, 1fr);
            }

            .badge-line-1,
            .badge-line-2 {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
            }
        }

        @media (min-width: 1024px) {
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

            .header-content {
                margin-top: 0;
            }
        }

        @media (max-width: 1023px) {
            .desktop-sidebar {
                display: none !important;
            }

            .hamburger-btn {
                display: flex;
            }

            .header-content h1 {
                font-size: 1.3rem;
                max-width: calc(100% - 70px);
                line-height: 1.3;
            }
        }

        @media (max-width: 767px) {
            .header-content h1 {
                font-size: 1.2rem;
                margin-bottom: 5px;
                max-width: calc(100% - 70px);
            }

            .header-content p {
                font-size: 0.85rem;
                margin-bottom: 10px;
            }

            .group-info {
                flex-direction: column;
                align-items: flex-start;
            }

            .badge-line-1,
            .badge-line-2 {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                width: 100%;
            }

            .badge-line-1 {
                margin-bottom: 8px;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-container">
        <!-- Page Blur Overlay -->
        <div class="page-blur" id="pageBlur"></div>

        <!-- Hamburger Menu Button -->
        <button class="hamburger-btn" id="hamburgerBtn">
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
            <div class="hamburger-line"></div>
        </button>

        <!-- Desktop Sidebar -->
        <aside class="sidebar desktop-sidebar">
            <ul class="nav-menu">
                <li class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </li>
                <li class="nav-item" onclick="showSection('schedule')">
                    <i class="fas fa-calendar-alt"></i> Schedule
                </li>
                <li class="nav-item" onclick="showSection('rooms')">
                    <i class="fas fa-door-open"></i> Browse Rooms
                </li>
                <li class="nav-item" onclick="showSection('room-status')">
                    <i class="fas fa-calendar-check"></i> Room Status
                </li>
                <li class="nav-item" onclick="showSection('book')">
                    <i class="fas fa-plus-circle"></i> Book Room
                </li>
                <li class="nav-item" onclick="showSection('bookings')">
                    <i class="fas fa-clipboard-list"></i> Bookings
                </li>
                <li class="nav-item" onclick="showSection('notifications')">
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </li>
            </ul>

            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </aside>

        <!-- Mobile Sidebar -->
        <aside class="sidebar mobile-sidebar" id="mobileSidebar">
            <ul class="nav-menu">
                <li class="nav-item active" onclick="showSection('dashboard')">
                    <i class="fas fa-tachometer-alt"></i> Dashboard
                </li>
                <li class="nav-item" onclick="showSection('schedule')">
                    <i class="fas fa-calendar-alt"></i> Schedule
                </li>
                <li class="nav-item" onclick="showSection('rooms')">
                    <i class="fas fa-door-open"></i> Browse Rooms
                </li>
                <li class="nav-item" onclick="showSection('room-status')">
                    <i class="fas fa-calendar-check"></i> Room Status
                </li>
                <li class="nav-item" onclick="showSection('book')">
                    <i class="fas fa-plus-circle"></i> Book Room
                </li>
                <li class="nav-item" onclick="showSection('bookings')">
                    <i class="fas fa-clipboard-list"></i> Bookings
                </li>
                <li class="nav-item" onclick="showSection('notifications')">
                    <i class="fas fa-bell"></i> Notifications
                    <?php if ($unread_count > 0): ?>
                        <span class="notification-badge"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </li>
            </ul>

            <button class="logout-btn" onclick="window.location.href='logout.php'">
                <i class="fas fa-sign-out-alt"></i> Logout
            </button>
        </aside>

        <!-- Main Content -->
        <main class="main-content" id="mainContent">
            <div class="header">
                <div class="header-content">
                    <h1><?php echo htmlspecialchars($cr_name_short); ?>'s Dashboard</h1>
                    <p>Manage bookings and schedules</p>
                </div>
                <div class="current-date">
                    <i class="far fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?>
                </div>
            </div>

            <?php if (!$degree_info): ?>
                <div class="alert alert-warning">
                    Your account is not assigned to a degree program. Please contact the administrator.
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Group Info Badges -->
            <?php if ($degree_info): ?>
                <div class="group-info">
                    <div class="badge-line-1">
                        <span class="badge badge-primary"><?php echo htmlspecialchars($degree_info['name']); ?></span>
                        <?php if ($degree_info['faculty_name']): ?>
                            <span class="badge badge-info"><?php echo htmlspecialchars($degree_info['faculty_name']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="badge-line-2">
                        <span class="badge badge-secondary">Level <?php echo $assigned_level; ?></span>
                        <span class="badge badge-secondary">Semester <?php echo $assigned_semester; ?></span>
                        <?php if ($batch): ?>
                            <span class="badge badge-secondary">Batch <?php echo htmlspecialchars($batch); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Dashboard Section -->
            <section id="dashboard" class="section">
                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $stats['todays_classes']; ?></div>
                        <div class="stat-label">Today's Classes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $stats['total_schedules']; ?></div>
                        <div class="stat-label">Weekly Classes</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $stats['active_bookings']; ?></div>
                        <div class="stat-label">Active Bookings</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $stats['unread_notifications']; ?></div>
                        <div class="stat-label">Notifications</div>
                    </div>
                </div>

                <!-- Today's Classes -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Today's Schedule</h3>
                        <button class="btn btn-outline btn-sm" onclick="showSection('schedule')">View All</button>
                    </div>

                    <?php if (empty($todays_classes)): ?>
                        <div class="empty-state">
                            <h3>No classes today</h3>
                        </div>
                    <?php else: ?>
                        <?php foreach ($todays_classes as $class): ?>
                            <div class="schedule-card">
                                <div class="schedule-time">
                                    <?php echo formatTime($class['start_time']); ?> - <?php echo formatTime($class['end_time']); ?>
                                </div>
                                <div class="schedule-title">
                                    <?php echo htmlspecialchars($class['course_name']); ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($class['course_code']); ?></span>
                                </div>
                                <div class="schedule-meta">
                                    <span>📍 <?php echo htmlspecialchars($class['building_name'] . ' - ' . $class['room_name']); ?></span>
                                    <?php if ($class['teacher_name']): ?>
                                        <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Schedule Section -->
            <section id="schedule" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Academic Schedule</h3>
                    </div>

                    <div class="tabs">
                        <div class="tab active" onclick="showScheduleView('day')">Today</div>
                        <div class="tab" onclick="showScheduleView('week')">Weekly</div>
                        <div class="tab" onclick="showScheduleView('semester')">Detailed</div>
                    </div>

                    <!-- Day View -->
                    <div id="schedule-day">
                        <?php if (empty($todays_classes)): ?>
                            <div class="empty-state">
                                <h3>No classes today</h3>
                            </div>
                        <?php else: ?>
                            <?php foreach ($todays_classes as $class): ?>
                                <div class="schedule-card">
                                    <div class="schedule-time">
                                        <?php echo formatTime($class['start_time']); ?> - <?php echo formatTime($class['end_time']); ?>
                                    </div>
                                    <div class="schedule-title"><?php echo htmlspecialchars($class['course_name']); ?></div>
                                    <div class="schedule-meta">
                                        <span>📍 <?php echo htmlspecialchars($class['building_name'] . ' - ' . $class['room_name']); ?></span>
                                        <?php if ($class['teacher_name']): ?>
                                            <span>👨‍🏫 <?php echo htmlspecialchars($class['teacher_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- Week View -->
                    <div id="schedule-week" style="display: none;">
                        <div class="week-grid">
                            <?php
                            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                            for ($d = 1; $d <= 7; $d++):
                                $day_classes = array_filter($group_schedule, fn($c) => $c['day_of_week'] == $d);
                                ?>
                                <div class="week-day">
                                    <div class="week-day-header <?php echo $d == $current_day ? 'active' : ''; ?>">
                                        <?php echo $days[$d - 1]; ?>
                                    </div>
                                    <div class="week-day-content">
                                        <?php foreach ($day_classes as $class): ?>
                                            <div style="background: var(--light); padding: 8px; border-radius: 4px; margin-bottom: 8px; font-size: 0.8rem;">
                                                <div style="font-weight: 600; color: var(--primary);">
                                                    <?php echo formatTime($class['start_time']); ?>
                                                </div>
                                                <div style="font-weight: 500;">
                                                    <?php echo htmlspecialchars($class['course_code']); ?>
                                                </div>
                                                <div class="text-muted"><?php echo htmlspecialchars($class['room_number']); ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- Semester View -->
                    <div id="schedule-semester" style="display: none;">
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Time</th>
                                        <th>Course</th>
                                        <th>Room</th>
                                        <th>Teacher</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group_schedule as $sch): ?>
                                        <tr>
                                            <td><strong><?php echo getDayName($sch['day_of_week']); ?></strong></td>
                                            <td><?php echo formatTime($sch['start_time']); ?> - <?php echo formatTime($sch['end_time']); ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($sch['course_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($sch['course_code']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($sch['building_name'] . ' - ' . $sch['room_name']); ?></td>
                                            <td><?php echo htmlspecialchars($sch['teacher_name'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Browse Rooms Section -->
            <section id="rooms" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Browse Rooms</h3>
                    </div>

                    <div class="filters">
                        <select id="filter-building" class="form-control" onchange="filterRooms()">
                            <option value="">All Buildings</option>
                            <?php foreach ($buildings as $b): ?>
                                <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filter-type" class="form-control" onchange="filterRooms()">
                            <option value="">All Types</option>
                            <option value="theory">Theory</option>
                            <option value="lab">Lab</option>
                        </select>
                    </div>

                    <div class="table-container">
                        <table id="rooms-table">
                            <thead>
                                <tr>
                                    <th>Room</th>
                                    <th>Building</th>
                                    <th>Type</th>
                                    <th>Capacity</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rooms as $room): ?>
                                    <tr data-building="<?php echo $room['building_id'] ?? ''; ?>" data-type="<?php echo $room['room_type']; ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($room['room_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($room['room_number']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($room['building_name']); ?></td>
                                        <td><span class="badge badge-secondary"><?php echo ucfirst($room['room_type']); ?></span></td>
                                        <td><?php echo $room['capacity']; ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" onclick="bookRoomQuick(<?php echo $room['id']; ?>)">Book</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Room Status Section -->
            <section id="room-status" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Room Status Calendar</h3>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn btn-outline btn-sm" onclick="changeCalendarView('week')">
                                <i class="fas fa-calendar-week"></i> Week View
                            </button>
                            <button class="btn btn-outline btn-sm" onclick="openDatePicker()">
                                <i class="fas fa-calendar-day"></i> Select Day
                            </button>
                        </div>
                    </div>
                    
                    <div id="calendar-container">
                        <?php 
                        // Get parameters
                        $calendar_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
                        $view = isset($_GET['view']) ? $_GET['view'] : 'week';
                        
                        // Ensure date is not in the past for day view
                        if ($view === 'day' && $calendar_date < date('Y-m-d')) {
                            $calendar_date = date('Y-m-d');
                        }
                        
                        displayRoomStatusCalendar($calendar_date, $view); 
                        ?>
                    </div>
                </div>
            </section>

            <!-- Book Room Section -->
            <section id="book" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Book a Room</h3>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="book_room">

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Room *</label>
                                <select name="room_id" id="book-room-id" class="form-control" required>
                                    <option value="">Select Room</option>
                                    <?php foreach ($rooms as $room): ?>
                                        <option value="<?php echo $room['id']; ?>">
                                            <?php echo htmlspecialchars($room['building_name'] . ' - ' . $room['room_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Purpose *</label>
                                <input type="text" name="purpose" class="form-control" required placeholder="e.g., Group Study">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Date *</label>
                                <input type="date" name="booking_date" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Start Time *</label>
                                <input type="time" name="start_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">End Time *</label>
                                <input type="time" name="end_time" class="form-control" required>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">Book Room</button>
                    </form>
                </div>
            </section>

            <!-- Group Bookings Section -->
            <section id="bookings" class="section" style="display: none;">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Group Bookings</h3>
                    </div>

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
                                <?php foreach ($group_bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($booking['room_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($booking['building_name']); ?></small>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($booking['booking_date'])); ?></td>
                                        <td><?php echo formatTime($booking['start_time']); ?> - <?php echo formatTime($booking['end_time']); ?></td>
                                        <td><?php echo htmlspecialchars($booking['purpose']); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $booking['status'] == 'active' ? 'success' : ($booking['status'] == 'freed' ? 'warning' : 'secondary'); ?>">
                                                <?php echo ucfirst($booking['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($booking['status'] == 'active' && $booking['booking_date'] >= date('Y-m-d')): ?>
                                                <button class="btn btn-sm btn-danger" onclick="freeRoomModal(<?php echo $booking['id']; ?>, '<?php echo htmlspecialchars($booking['room_name']); ?>')">Free</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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
                    <?php else: ?>
                        <?php foreach ($notifications as $notif): ?>
                            <div class="notification-item <?php echo $notif['is_read'] ? '' : 'unread'; ?>">
                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars($notif['message']); ?></div>
                                <div class="notification-time">
                                    <?php echo date('M d, Y g:i A', strtotime($notif['created_at'])); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <!-- Free Room Modal -->
    <div class="modal-overlay" id="free-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Free Room</h3>
                <button class="modal-close" onclick="closeFreeModal()">×</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="free_room">
                <input type="hidden" name="booking_id" id="free-booking-id">
                <p>Are you sure you want to free <strong id="free-room-name"></strong>?</p>
                <div class="form-group">
                    <label class="form-label">Reason (Optional)</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g., Class cancelled">
                </div>
                <div class="d-flex gap-3">
                    <button type="button" class="btn btn-outline" onclick="closeFreeModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Free Room</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Date Picker Modal for Day View -->
    <div class="modal-overlay" id="date-picker-modal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Select Date</h3>
                <button class="modal-close" onclick="closeDatePicker()">×</button>
            </div>
            <div style="padding: 20px;">
                <div style="text-align: center; margin-bottom: 20px;">
                    <input type="date" id="date-picker-input" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                    <button type="button" class="btn btn-outline" onclick="goToToday()">Go to Today</button>
                    <button type="button" class="btn btn-primary" onclick="goToSelectedDate()">View Selected Date</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const hamburgerBtn = document.getElementById('hamburgerBtn');
        const mobileSidebar = document.getElementById('mobileSidebar');
        const pageBlur = document.getElementById('pageBlur');

        function toggleMobileSidebar() {
            const isActive = mobileSidebar.classList.contains('active');
            mobileSidebar.classList.toggle('active');
            pageBlur.classList.toggle('active');
            
            if (isActive) {
                document.body.style.overflow = '';
            } else {
                document.body.style.overflow = 'hidden';
            }
        }

        function closeMobileSidebar() {
            mobileSidebar.classList.remove('active');
            pageBlur.classList.remove('active');
            document.body.style.overflow = '';
        }

        hamburgerBtn.addEventListener('click', toggleMobileSidebar);
        pageBlur.addEventListener('click', closeMobileSidebar);

        function showSection(sectionId) {
            document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
            document.getElementById(sectionId).style.display = 'block';

            document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
            
            const clickedItem = event.target.closest('.nav-item');
            if (clickedItem) {
                clickedItem.classList.add('active');
                
                const navIndex = Array.from(clickedItem.parentElement.children).indexOf(clickedItem);
                const otherSidebar = clickedItem.closest('.desktop-sidebar') ? 
                    document.querySelector('.mobile-sidebar .nav-menu') : 
                    document.querySelector('.desktop-sidebar .nav-menu');
                
                if (otherSidebar && otherSidebar.children[navIndex]) {
                    otherSidebar.children[navIndex].classList.add('active');
                }
            }
            
            // Update URL without reloading the page
            const url = new URL(window.location);
            url.searchParams.set('section', sectionId);
            history.pushState({}, '', url);
            
            if (window.innerWidth < 1024) {
                closeMobileSidebar();
            }
        }

        function showScheduleView(view) {
            ['day', 'week', 'semester'].forEach(v => {
                document.getElementById('schedule-' + v).style.display = v === view ? 'block' : 'none';
            });
            
            document.querySelectorAll('#schedule .tab').forEach((t, i) => {
                t.classList.toggle('active', ['day', 'week', 'semester'][i] === view);
            });
        }

        function filterRooms() {
            const building = document.getElementById('filter-building').value;
            const type = document.getElementById('filter-type').value;

            document.querySelectorAll('#rooms-table tbody tr').forEach(row => {
                let show = true;
                if (building && row.dataset.building != building) show = false;
                if (type && row.dataset.type != type) show = false;
                row.style.display = show ? '' : 'none';
            });
        }

        function bookRoomQuick(roomId) {
            showSection('book');
            document.getElementById('book-room-id').value = roomId;
        }

        function freeRoomModal(bookingId, roomName) {
            document.getElementById('free-booking-id').value = bookingId;
            document.getElementById('free-room-name').textContent = roomName;
            document.getElementById('free-modal').classList.add('active');
        }

        function closeFreeModal() {
            document.getElementById('free-modal').classList.remove('active');
        }

        function openDatePicker() {
            const currentDate = new URLSearchParams(window.location.search).get('date') || '<?php echo date('Y-m-d'); ?>';
            document.getElementById('date-picker-input').value = currentDate;
            document.getElementById('date-picker-modal').classList.add('active');
        }

        function closeDatePicker() {
            document.getElementById('date-picker-modal').classList.remove('active');
        }

        function goToToday() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('date-picker-input').value = today;
        }

        function goToSelectedDate() {
            const selectedDate = document.getElementById('date-picker-input').value;
            const view = 'day'; // Always use day view when selecting a specific date
            
            if (selectedDate) {
                // Redirect to room-status section with the selected date and day view
                window.location.href = '?section=room-status&view=' + view + '&date=' + selectedDate;
            }
            closeDatePicker();
        }

        function changeCalendarView(view) {
            const currentDate = new URLSearchParams(window.location.search).get('date') || '<?php echo date('Y-m-d'); ?>';
            
            // For day view, open the date picker
            if (view === 'day') {
                openDatePicker();
                return;
            }
            
            // For week view, redirect directly
            if (view === 'week') {
                // Redirect to room-status section with the selected view
                window.location.href = '?section=room-status&view=' + view + '&date=' + currentDate;
            }
        }

        document.getElementById('free-modal').addEventListener('click', function (e) {
            if (e.target === this) closeFreeModal();
        });

        document.getElementById('date-picker-modal').addEventListener('click', function (e) {
            if (e.target === this) closeDatePicker();
        });

        document.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const section = urlParams.get('section');
            
            if (section) {
                // Show the requested section
                document.querySelectorAll('.section').forEach(s => s.style.display = 'none');
                const sectionElement = document.getElementById(section);
                if (sectionElement) {
                    sectionElement.style.display = 'block';
                }
                
                // Update active nav item
                document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
                const navItems = document.querySelectorAll('.nav-item');
                const sectionIndex = {
                    'dashboard': 0,
                    'schedule': 1,
                    'rooms': 2,
                    'room-status': 3,
                    'book': 4,
                    'bookings': 5,
                    'notifications': 6
                };
                
                if (sectionIndex[section] !== undefined && navItems[sectionIndex[section]]) {
                    navItems[sectionIndex[section]].classList.add('active');
                    
                    // Also update the other sidebar
                    const otherSidebar = document.querySelector('.desktop-sidebar') ? 
                        document.querySelector('.mobile-sidebar .nav-menu') : 
                        document.querySelector('.desktop-sidebar .nav-menu');
                    
                    if (otherSidebar && otherSidebar.children[sectionIndex[section]]) {
                        otherSidebar.children[sectionIndex[section]].classList.add('active');
                    }
                }
                
                // Highlight the correct view button based on URL parameter
                const view = urlParams.get('view') || 'week';
                if (section === 'room-status') {
                    const weekBtn = document.querySelector('[onclick="changeCalendarView(\'week\')"]');
                    const dayBtn = document.querySelector('[onclick="openDatePicker()"]');
                    
                    if (weekBtn && dayBtn) {
                        weekBtn.classList.toggle('active', view === 'week');
                        weekBtn.classList.toggle('btn-outline', view !== 'week');
                        dayBtn.classList.toggle('active', view === 'day');
                        dayBtn.classList.toggle('btn-outline', view !== 'day');
                    }
                }
            }
            
            // Set default date for date inputs
            const today = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = today;
                }
            });
        });
    </script>
</body>
</html>