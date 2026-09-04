<?php
require_once __DIR__ . '/db_config.php';

// AJAX endpoint for live stats (now expects faculty_id, numeric)
if (isset($_GET['ajax_stats'])) {
    header('Content-Type: application/json');

    $facultyIdParam = isset($_GET['faculty_id']) ? (int)$_GET['faculty_id'] : 0;
    $today          = date('Y-m-d');
    $nowTime        = date('H:i:s');
    $todayDay       = date('l');

    try {
        $facultyId   = $facultyIdParam > 0 ? $facultyIdParam : null;
        $facultyName = 'All Faculties';

        if ($facultyId) {
            $stmtF = $pdo->prepare("SELECT name FROM faculties WHERE id = :id AND status = 'active'");
            $stmtF->execute([':id' => $facultyId]);
            $fName = $stmtF->fetchColumn();
            if ($fName) {
                $facultyName = $fName;
            }
        }

        // Total active rooms
        if ($facultyId) {
            $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE faculty_id = :fid AND status = 'active'");
            $stmtTotal->execute([':fid' => $facultyId]);
        } else {
            $stmtTotal = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'active'");
        }
        $totalRooms = (int)$stmtTotal->fetchColumn();

        // Rooms currently IN USE right now
        $schedSql = "
            SELECT DISTINCT r.id
            FROM rooms r
            JOIN course_schedule cs ON cs.room_id = r.id
            WHERE r.status = 'active'
              AND cs.status = 'active'
              AND cs.day_of_week = :dow
              AND cs.start_time <= :now
              AND cs.end_time   > :now
              AND (
                    cs.is_whole_semester = 1
                    OR (cs.start_date <= :today AND cs.end_date >= :today)
              )
        ";
        $schedParams = [':dow' => $todayDay, ':now' => $nowTime, ':today' => $today];
        if ($facultyId) {
            $schedSql .= " AND r.faculty_id = :fid";
            $schedParams[':fid'] = $facultyId;
        }
        $stmtSched = $pdo->prepare($schedSql);
        $stmtSched->execute($schedParams);
        $inUseFromSched = $stmtSched->fetchAll(PDO::FETCH_COLUMN);

        $bookSql = "
            SELECT DISTINCT r.id
            FROM rooms r
            JOIN room_bookings rb ON rb.room_id = r.id
            WHERE r.status = 'active'
              AND rb.status = 'confirmed'
              AND rb.booking_date = :today
              AND rb.start_time  <= :now
              AND rb.end_time     > :now
        ";
        $bookParams = [':today' => $today, ':now' => $nowTime];
        if ($facultyId) {
            $bookSql .= " AND r.faculty_id = :fid";
            $bookParams[':fid'] = $facultyId;
        }
        $stmtBook = $pdo->prepare($bookSql);
        $stmtBook->execute($bookParams);
        $inUseFromBook = $stmtBook->fetchAll(PDO::FETCH_COLUMN);

        $inUseIds  = array_unique(array_merge($inUseFromSched, $inUseFromBook));
        $inUseCount = count($inUseIds);

        // Rooms reserved for LATER today
        $resSql = "
            SELECT DISTINCT r.id
            FROM rooms r
            LEFT JOIN course_schedule cs
                   ON cs.room_id = r.id
                  AND cs.status = 'active'
                  AND cs.day_of_week = :dow
                  AND cs.start_time > :now
                  AND (cs.is_whole_semester = 1
                       OR (cs.start_date <= :today AND cs.end_date >= :today))
            LEFT JOIN room_bookings rb
                   ON rb.room_id = r.id
                  AND rb.status = 'confirmed'
                  AND rb.booking_date = :today2
                  AND rb.start_time > :now2
            WHERE r.status = 'active'
              AND (cs.id IS NOT NULL OR rb.id IS NOT NULL)
        ";
        $resParams = [
            ':dow'    => $todayDay,
            ':now'    => $nowTime,
            ':today'  => $today,
            ':today2' => $today,
            ':now2'   => $nowTime,
        ];
        if ($facultyId) {
            $resSql .= " AND r.faculty_id = :fid";
            $resParams[':fid'] = $facultyId;
        }
        $stmtRes = $pdo->prepare($resSql);
        $stmtRes->execute($resParams);
        $reservedIds   = array_diff($stmtRes->fetchAll(PDO::FETCH_COLUMN), $inUseIds);
        $reservedCount = count($reservedIds);

        $availableCount = max(0, $totalRooms - $inUseCount - $reservedCount);
        $utilPct = $totalRooms > 0 ? round(($inUseCount / $totalRooms) * 100) : 0;

        $bkTodaySql = "
            SELECT COUNT(*) FROM room_bookings rb
            JOIN rooms r ON rb.room_id = r.id
            WHERE rb.booking_date = :today AND r.status = 'active'
        ";
        $bkTodayParams = [':today' => $today];
        if ($facultyId) {
            $bkTodaySql .= " AND r.faculty_id = :fid";
            $bkTodayParams[':fid'] = $facultyId;
        }
        $stmtBkToday = $pdo->prepare($bkTodaySql);
        $stmtBkToday->execute($bkTodayParams);
        $bookingsTodayCount = (int)$stmtBkToday->fetchColumn();

        echo json_encode([
            'success'      => true,
            'faculty_name' => $facultyName,
            'total'        => $totalRooms,
            'available'    => $availableCount,
            'in_use'       => $inUseCount,
            'reserved'     => $reservedCount,
            'utilization'  => $utilPct,
            'bookings_today' => $bookingsTodayCount,
            'as_of'        => date('h:i A'),
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─────────────────────────────────────────────────────────────
// NORMAL PAGE RENDER
// ─────────────────────────────────────────────────────────────

// Pre-load faculties from DB for the dropdown (with room counts)
try {
    $stmtFacs = $pdo->query("
        SELECT f.id, f.name, f.code,
               COUNT(r.id) AS room_count
        FROM faculties f
        LEFT JOIN rooms r ON r.faculty_id = f.id AND r.status = 'active'
        WHERE f.status = 'active'
        GROUP BY f.id
        ORDER BY f.name ASC
    ");
    $faculties = $stmtFacs->fetchAll();
} catch (Exception $e) {
    $faculties = [];
}

// University-wide glance stats
try {
    $totalRoomsAll = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE status='active'")->fetchColumn();
    $totalFacs     = (int)$pdo->query("SELECT COUNT(*) FROM faculties WHERE status='active'")->fetchColumn();
} catch (Exception $e) {
    $totalRoomsAll = 0;
    $totalFacs     = 0;
}

$facMap = [
    'agriculture' => ['🌾', 'Faculty of Agriculture'],
    'veterinary'  => ['🐂', 'Faculty of Veterinary Science'],
    'animal'      => ['🐔', 'Faculty of Animal Husbandry'],
    'economics'   => ['💲', 'Faculty of Agri. Economics'],
    'engineering' => ['👨‍🔧', 'Agri. Engineering & Technology'],
    'fisheries'   => ['🐟', 'Faculty of Fisheries'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
  <title>Classroom Management — BAU</title>
  <meta name="description" content="Bangladesh Agricultural University — Classroom Management System">
  <style>
    /* (styles unchanged from original, omitted for brevity but present in final file) */
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    :root {
      --green-50:  #edfaf4;
      --green-100: #d0f4e4;
      --green-200: #a3e8cd;
      --green-400: #34b880;
      --green-500: #1e9e68;
      --green-600: #0f7a50;
      --green-700: #085c3a;
      --green-800: #053d27;

      --blue-50:  #eef5fd;
      --blue-100: #cfe1f8;
      --blue-200: #9dc5f1;
      --blue-400: #3e87d4;
      --blue-500: #1e6dbd;
      --blue-600: #1255a0;
      --blue-700: #0c3f7f;
      --blue-800: #082a5a;

      --slate-50:  #f8fafc;
      --slate-100: #f1f5f9;
      --slate-200: #e2e8f0;
      --slate-300: #cbd5e1;
      --slate-400: #94a3b8;
      --slate-500: #64748b;
      --slate-600: #475569;
      --slate-700: #334155;
      --slate-800: #1e293b;
      --slate-900: #0f172a;

      --font-display: Georgia, 'Times New Roman', serif;
      --font-body: 'Segoe UI', system-ui, -apple-system, sans-serif;

      --radius-sm: 6px;
      --radius-md: 10px;
      --radius-lg: 16px;
      --radius-xl: 22px;
      --radius-pill: 999px;

      --shadow-xs: 0 1px 2px rgba(15,23,42,0.06);
      --shadow-sm: 0 2px 6px rgba(15,23,42,0.07), 0 1px 2px rgba(15,23,42,0.05);
      --shadow-md: 0 6px 20px rgba(15,23,42,0.09), 0 2px 6px rgba(15,23,42,0.05);
    }

    html { scroll-behavior: smooth; }
    body {
      font-family: var(--font-body);
      background: var(--slate-100);
      color: var(--slate-800);
      line-height: 1.6;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* HEADER */
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
      z-index: 100;
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
      width: 50px; height: 50px;
      border-radius: var(--radius-md);
      background: #fff;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0;
      padding: 5px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.22), 0 0 0 1.5px rgba(255,255,255,0.18);
    }
    .logo-img-wrap img { width:100%; height:100%; object-fit:contain; display:block; }
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
      width: 1px; height: 38px;
      background: rgba(255,255,255,0.15);
      flex-shrink: 0; margin: 0 4px;
    }
    .signin-btn {
      display: inline-flex; align-items: center; gap: 8px;
      background: rgba(255,255,255,0.11);
      border: 1.5px solid rgba(255,255,255,0.28);
      color: #fff; text-decoration: none;
      font-size: 0.86rem; font-weight: 600;
      padding: 9px 22px; border-radius: var(--radius-pill);
      cursor: pointer;
      transition: background 0.18s, border-color 0.18s, transform 0.1s;
      white-space: nowrap; flex-shrink: 0; letter-spacing: 0.01em;
    }
    .signin-btn:hover { background:rgba(255,255,255,0.2); border-color:rgba(255,255,255,0.5); transform:translateY(-1px); }
    .signin-btn:active { transform: translateY(0); }
    .signin-btn svg { width:15px; height:15px; flex-shrink:0; }

    /* HERO - default (desktop) full height, mobile 16:9 */
    .hero {
      position: relative;
      width: 100%;
      background-size: cover;
      background-position: center 57%;
      background-repeat: no-repeat;
      min-height: 600px;
      height: auto;
    }
    @media (max-width: 768px) {
      .hero {
        aspect-ratio: 16 / 9;
        min-height: auto;
      }
    }
    .hero-overlay {
      position: absolute; inset: 0;
      background: linear-gradient(to bottom,rgba(5,30,55,0.15) 0%,rgba(5,30,55,0.48) 65%,rgba(5,30,55,0.72) 100%);
      pointer-events: none;
    }
    .hero-green-cast {
      position: absolute; top:0; left:0; bottom:0; width:35%;
      background: linear-gradient(90deg, rgba(15,122,80,0.15) 0%, transparent 100%);
      pointer-events: none;
    }

    /* MAIN LAYOUT */
    .main-wrap {
      flex: 1; max-width: 1180px; width: 100%;
      margin: 0 auto;
      padding: clamp(1.4rem,4vw,2.5rem) clamp(1rem,4vw,2rem);
      display: grid;
      grid-template-columns: 1fr 308px;
      gap: 1.6rem;
      align-items: start;
    }
    @media (max-width: 820px) { .main-wrap { grid-template-columns: 1fr; } }

    .left-col { display:flex; flex-direction:column; gap:1.4rem; }

    /* MAIN CARD */
    .main-card {
      background: #fff;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-xl);
      box-shadow: var(--shadow-md);
      overflow: hidden;
    }
    .card-top-bar {
      background: linear-gradient(90deg, var(--green-50) 0%, var(--blue-50) 100%);
      border-bottom: 1px solid var(--slate-200);
      padding: 1.35rem 1.8rem;
      display: flex; align-items: center; gap: 14px;
    }
    .card-icon {
      width:48px; height:48px; border-radius:var(--radius-md);
      background: linear-gradient(135deg, var(--green-500) 0%, var(--blue-500) 100%);
      display:flex; align-items:center; justify-content:center;
      flex-shrink:0;
      box-shadow: 0 4px 10px rgba(14,105,73,0.22);
    }
    .card-icon svg { width:22px; height:22px; }
    .card-title h2 { font-size:1.08rem; font-weight:700; color:var(--slate-900); }
    .card-title p  { font-size:0.78rem; color:var(--slate-400); margin-top:2px; }
    .card-body { padding:1.7rem 1.8rem; }

    .field-label {
      display:flex; align-items:center; gap:6px;
      font-size:0.74rem; font-weight:700; color:var(--slate-600);
      text-transform:uppercase; letter-spacing:0.09em; margin-bottom:8px;
    }
    .field-label svg { width:13px; height:13px; color:var(--green-500); flex-shrink:0; }

    .select-wrap { position:relative; margin-bottom:1.3rem; }
    .select-wrap select {
      width:100%; padding:12px 44px 12px 16px;
      border:1.5px solid var(--slate-200); border-radius:var(--radius-md);
      background:var(--slate-50); color:var(--slate-800);
      font-family:var(--font-body); font-size:0.92rem;
      appearance:none; cursor:pointer;
      transition:border-color 0.15s, box-shadow 0.15s, background 0.15s;
    }
    .select-wrap select:focus {
      outline:none; border-color:var(--green-400);
      box-shadow:0 0 0 3px rgba(52,184,128,0.14); background:#fff;
    }
    .select-wrap select:hover:not(:focus) { border-color:var(--slate-300); background:#fff; }
    .chevron { position:absolute; right:14px; top:50%; transform:translateY(-50%); pointer-events:none; color:var(--slate-400); }
    .chevron svg { width:16px; height:16px; display:block; }

    .submit-btn {
      display:flex; align-items:center; justify-content:center; gap:9px;
      width:100%; padding:13px 24px;
      background:linear-gradient(135deg, var(--green-500) 0%, var(--blue-500) 100%);
      color:#fff; border:none; border-radius:var(--radius-pill);
      font-family:var(--font-body); font-size:0.95rem; font-weight:700;
      cursor:pointer; box-shadow:0 4px 14px rgba(14,105,73,0.25);
      transition:opacity 0.15s, transform 0.1s, box-shadow 0.15s;
    }
    .submit-btn:hover { opacity:0.9; transform:translateY(-1px); box-shadow:0 6px 18px rgba(14,105,73,0.3); }
    .submit-btn:active { transform:translateY(0); }
    .submit-btn svg { width:16px; height:16px; }

    .card-divider { border:none; border-top:1px dashed var(--slate-200); margin:1.4rem 0; }
    .section-label {
      font-size:0.68rem; font-weight:700; text-transform:uppercase;
      letter-spacing:0.11em; color:var(--slate-400); margin-bottom:10px;
    }

    /* faculty grid */
    .faculty-grid {
      display:grid;
      grid-template-columns:repeat(auto-fill, minmax(148px, 1fr));
      gap:10px;
    }
    .fac-card {
      border:1px solid var(--slate-200); border-radius:var(--radius-md);
      padding:15px 13px; background:#fff;
      display:flex; flex-direction:column; gap:9px;
      text-decoration:none;
      transition:border-color 0.15s, box-shadow 0.15s, transform 0.12s;
      cursor:pointer;
    }
    .fac-card:hover {
      border-color:var(--blue-200);
      box-shadow:0 4px 14px rgba(18,85,160,0.11);
      transform:translateY(-2px);
    }
    .fac-emoji { font-size:1.8rem; line-height:1; }
    .fac-name  { font-size:0.78rem; font-weight:700; color:var(--slate-700); line-height:1.3; }
    .fac-rooms { font-size:0.67rem; color:var(--slate-400); }

    /* SIDEBAR */
    .sidebar { display:flex; flex-direction:column; gap:1.2rem; }

    .sidebar-card {
      background:#fff; border:1px solid var(--slate-200);
      border-radius:var(--radius-lg); box-shadow:var(--shadow-sm);
      overflow:hidden;
    }
    .sidebar-card-head {
      padding:13px 16px 11px; border-bottom:1px solid var(--slate-100);
      display:flex; align-items:center; justify-content:space-between;
    }
    .sidebar-card-head h3 {
      font-size:0.73rem; font-weight:700; text-transform:uppercase;
      letter-spacing:0.1em; color:var(--slate-500);
    }
    .live-badge {
      display:flex; align-items:center; gap:5px;
      background:var(--green-50); color:var(--green-700);
      font-size:0.62rem; font-weight:700;
      padding:3px 9px; border-radius:var(--radius-pill);
      border:1px solid var(--green-100);
      text-transform:uppercase; letter-spacing:0.06em;
    }
    .live-dot {
      width:5px; height:5px; border-radius:50%; background:var(--green-500);
      animation:pulse-dot 1.5s ease-in-out infinite;
    }
    @keyframes pulse-dot {
      0%,100%{opacity:1;transform:scale(1);}
      50%{opacity:0.4;transform:scale(0.72);}
    }
    .sidebar-card-body { padding:14px 16px; }

    .overview-scope {
      font-size:0.71rem; color:var(--slate-400);
      margin-bottom:10px; display:flex; align-items:center; gap:5px;
      min-height:18px;
    }
    .overview-scope strong { color:var(--green-600); font-weight:600; }

    .stats-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
    .stat-tile {
      background:var(--slate-50); border:1px solid var(--slate-100);
      border-radius:var(--radius-md); padding:12px 11px;
      transition: background 0.25s;
    }
    .stat-tile .tv {
      font-family:var(--font-display); font-size:1.55rem; font-weight:700;
      color:var(--slate-900); line-height:1; margin-bottom:3px;
      transition: color 0.25s;
    }
    .stat-tile .tv.green { color:var(--green-600); }
    .stat-tile .tv.blue  { color:var(--blue-600);  }
    .stat-tile .tv.amber { color:#b45309; }
    .stat-tile .tl {
      font-size:0.67rem; color:var(--slate-400);
      text-transform:uppercase; letter-spacing:0.06em; font-weight:600;
    }

    .stat-tile.loading .tv {
      background:var(--slate-200); color:transparent;
      border-radius:4px; animation:shimmer 1s ease-in-out infinite;
      display:inline-block; min-width:40px;
    }
    @keyframes shimmer {
      0%,100%{opacity:1;}50%{opacity:0.45;}
    }

    .util-wrap { margin-top:12px; }
    .util-label {
      display:flex; justify-content:space-between;
      font-size:0.7rem; color:var(--slate-500); margin-bottom:6px;
    }
    .util-label span:last-child { font-weight:700; color:var(--slate-700); }
    .util-track { height:7px; background:var(--slate-100); border-radius:var(--radius-pill); overflow:hidden; }
    .util-fill {
      height:100%; border-radius:var(--radius-pill);
      background:linear-gradient(90deg, var(--green-400), var(--blue-400));
      width:0; transition:width 0.9s cubic-bezier(0.22,1,0.36,1);
    }

    .as-of {
      margin-top:10px; font-size:0.67rem; color:var(--slate-400);
      text-align:right; display:flex; align-items:center; justify-content:flex-end; gap:4px;
    }
    .as-of svg { width:10px; height:10px; }

    .refresh-btn {
      display:inline-flex; align-items:center; gap:5px;
      font-size:0.68rem; color:var(--green-600);
      background:none; border:none; cursor:pointer; padding:0;
      font-family:var(--font-body); font-weight:600;
      transition:color 0.15s;
    }
    .refresh-btn:hover { color:var(--green-700); }
    .refresh-btn svg { width:12px; height:12px; }
    .refresh-btn.spinning svg { animation:spin 0.8s linear infinite; }

    .fade-up { opacity:0; transform:translateY(16px); transition:opacity 0.52s ease, transform 0.52s ease; }
    .fade-up.visible { opacity:1; transform:translateY(0); }

    .footer {
      background:var(--slate-900); color:rgba(255,255,255,0.42);
      padding:1.6rem clamp(1rem,5vw,3.5rem);
      display:flex; align-items:center; justify-content:space-between;
      flex-wrap:wrap; gap:1rem; margin-top:auto;
    }
    .footer-left .fl1 { font-size:0.82rem; color:rgba(255,255,255,0.72); font-weight:600; margin-bottom:3px; }
    .footer-left .fl2 { font-size:0.7rem; }
    .footer-links { display:flex; gap:1.4rem; flex-wrap:wrap; }
    .footer-links a { font-size:0.73rem; color:rgba(255,255,255,0.36); text-decoration:none; transition:color 0.15s; }
    .footer-links a:hover { color:rgba(255,255,255,0.75); }

    @media (max-width: 640px) {
      .card-top-bar { padding:1rem 1.2rem; }
      .card-body { padding:1.2rem; }
      .faculty-grid { grid-template-columns:repeat(2,1fr); }
      .brand-text .name { font-size: 0.85rem; white-space: normal; word-break: keep-all; }
      .brand-text .sub { font-size: 0.65rem; white-space: normal; }
      .signin-btn { padding: 6px 12px; font-size: 0.75rem; gap: 5px; }
      .signin-btn svg { width: 12px; height: 12px; }
      .header { gap: 0.6rem; padding: 0 1rem; height: auto; min-height: 72px; }
      .logo-img-wrap { width: 42px; height: 42px; }
      .brand { gap: 8px; }
    }

    @keyframes spin { to { transform:rotate(360deg); } }
  </style>
</head>
<body>

<header class="header">
  <div class="brand">
    <div class="logo-img-wrap">
      <img src="images/bau-logo.png" alt="BAU Logo">
    </div>
    <div class="brand-text">
      <div class="name">Bangladesh Agricultural University</div>
      <div class="sub">Classroom Management System</div>
    </div>
  </div>
  <div class="header-sep"></div>
  <a href="login.php" class="signin-btn">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
         stroke-linecap="round" stroke-linejoin="round">
      <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
      <polyline points="10 17 15 12 10 7"/>
      <line x1="15" y1="12" x2="3" y2="12"/>
    </svg>
    Sign In
  </a>
</header>

<section class="hero" id="hero">
  <div class="hero-overlay"></div>
  <div class="hero-green-cast"></div>
</section>

<div class="main-wrap">

  <div class="left-col">
    <div class="main-card fade-up">

      <div class="card-top-bar">
        <div class="card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <rect x="3" y="3" width="18" height="18" rx="2"/>
            <path d="M3 9h18"/><path d="M9 21V9"/>
          </svg>
        </div>
        <div class="card-title">
          <h2>Browse Classrooms</h2>
          <p>Select a faculty or view all available rooms at once</p>
        </div>
      </div>

      <div class="card-body">
        <form action="view-classroom.php" method="GET" id="classroomForm">

          <div class="field-label">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
              <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
              <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            Faculty (Optional)
          </div>

          <div class="select-wrap">
            <select name="faculty" id="facultySelect">
              <option value="">All Faculties</option>
              <?php foreach ($faculties as $fac): ?>
              <option value="<?= $fac['id'] ?>" data-code="<?= htmlspecialchars(strtolower($fac['code'])) ?>">
                <?= htmlspecialchars($fac['name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="chevron">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                   stroke-linecap="round" stroke-linejoin="round">
                <polyline points="6 9 12 15 18 9"/>
              </svg>
            </div>
          </div>

          <button type="submit" class="submit-btn" id="viewBtn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round">
              <circle cx="11" cy="11" r="8"/>
              <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            View Classrooms
          </button>
        </form>

        <hr class="card-divider">

        <div class="section-label">Browse by faculty</div>
        <div class="faculty-grid">

          <?php if (!empty($faculties)):
            foreach ($faculties as $fac):
              $code = strtolower($fac['code']);
              $emoji = isset($facMap[$code][0]) ? $facMap[$code][0] : '';
              $displayName = $facMap[$code][1] ?? htmlspecialchars($fac['name']);
              $roomCount = (int)$fac['room_count'];
          ?>
          <a href="view-classroom.php?faculty=<?= $fac['id'] ?>" class="fac-card">
            <div class="fac-emoji"><?= $emoji ?></div>
            <div class="fac-name"><?= htmlspecialchars($displayName) ?></div>
            <div class="fac-rooms"><?= $roomCount ?> classroom<?= $roomCount !== 1 ? 's' : '' ?></div>
          </a>
          <?php endforeach; else: ?>
          <!-- fallback cards (unchanged) -->
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <div class="sidebar">

    <div class="sidebar-card fade-up" id="overviewCard" style="transition-delay:0.1s">
      <div class="sidebar-card-head">
        <h3>Today's Overview</h3>
        <div class="live-badge"><div class="live-dot"></div> Live</div>
      </div>
      <div class="sidebar-card-body">

        <div class="overview-scope" id="overviewScope">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round" style="width:12px;height:12px;flex-shrink:0;">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
          </svg>
          <span id="scopeText">All Faculties</span>
        </div>

        <div class="stats-grid" id="statsGrid">
          <div class="stat-tile" id="tile-available">
            <div class="tv green" id="val-available">—</div>
            <div class="tl">Available</div>
          </div>
          <div class="stat-tile" id="tile-inuse">
            <div class="tv blue" id="val-inuse">—</div>
            <div class="tl">In Use</div>
          </div>
          <div class="stat-tile" id="tile-reserved">
            <div class="tv amber" id="val-reserved">—</div>
            <div class="tl">Reserved</div>
          </div>
          <div class="stat-tile" id="tile-total">
            <div class="tv" id="val-total">—</div>
            <div class="tl">Total Rooms</div>
          </div>
        </div>

        <div class="util-wrap">
          <div class="util-label">
            <span>Current utilization</span>
            <span id="utilPct">—%</span>
          </div>
          <div class="util-track">
            <div class="util-fill" id="utilFill"></div>
          </div>
        </div>

        <div class="as-of">
          <button class="refresh-btn" id="refreshBtn" title="Refresh stats">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" id="refreshIcon">
              <polyline points="23 4 23 10 17 10"/>
              <polyline points="1 20 1 14 7 14"/>
              <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Refresh
          </button>
          <span style="margin-left:6px;color:var(--slate-300);">·</span>
          <span id="asOfTime" style="margin-left:4px;">—</span>
        </div>

      </div>
    </div>

    <div class="sidebar-card fade-up" style="transition-delay:0.18s">
      <div class="sidebar-card-head"><h3>About this Portal</h3></div>
      <div class="sidebar-card-body" style="font-size:0.8rem;color:var(--slate-600);line-height:1.68;">
        <p>This portal lets students and faculty view classroom availability and check schedules across all departments of <strong style="color:var(--slate-800);">Bangladesh Agricultural University</strong>.</p>
        <p style="margin-top:10px;font-size:0.73rem;color:var(--slate-400);">For booking or administrative access, please sign in with your BAU credentials.</p>
      </div>
    </div>

    <div class="sidebar-card fade-up" style="transition-delay:0.26s">
      <div class="sidebar-card-head"><h3>University at a Glance</h3></div>
      <div class="sidebar-card-body">
        <div class="stats-grid">
          <div class="stat-tile">
            <div class="tv green"><?= $totalFacs ?></div>
            <div class="tl">Faculties</div>
          </div>
          <div class="stat-tile">
            <div class="tv blue"><?= $totalRoomsAll ?>+</div>
            <div class="tl">Classrooms</div>
          </div>
          <div class="stat-tile">
            <div class="tv">12K+</div>
            <div class="tl">Students</div>
          </div>
          <div class="stat-tile">
            <div class="tv amber">700+</div>
            <div class="tl">Staff</div>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<footer class="footer">
  <div class="footer-left">
    <div class="fl1">Bangladesh Agricultural University · Classroom Management System</div>
    <div class="fl2">&copy; <?= date('Y') ?> BAU · Team TinUstad , Joyeeta Sarkar, and Noushin.  </div>
  </div>
  <div class="footer-links">
    <a href="#">Privacy</a>
    <a href="#">Terms</a>
    <a href="#">Support</a>
    <a href="#">Help</a>
  </div>
</footer>

<script>
(function () {
'use strict';

var images = [
  'images/bie.jpg',
  'images/aet.jpg',
  'images/developers.jpg',
  'images/bau.jpg'
];
var hero    = document.getElementById('hero');
var current = 0;
var slideTimer;

images.forEach(function(src){ var i=new Image(); i.src=src; });

function setSlide(idx){
  if(!hero||!images[idx]) return;
  hero.style.backgroundImage = "url('"+images[idx]+"')";
  current = idx;
}
function next(){ setSlide((current+1)%images.length); }
setSlide(0);
slideTimer = setInterval(next, 5500);

var fadeEls = document.querySelectorAll('.fade-up');
var io = new IntersectionObserver(function(entries){
  entries.forEach(function(e){
    if(e.isIntersecting){ e.target.classList.add('visible'); io.unobserve(e.target); }
  });
},{threshold:0.07});
fadeEls.forEach(function(el){ io.observe(el); });

var form = document.getElementById('classroomForm');
var btn  = document.getElementById('viewBtn');
if(form && btn){
  form.addEventListener('submit', function(){
    btn.innerHTML =
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"'+
      ' stroke-linecap="round" stroke-linejoin="round"'+
      ' style="width:16px;height:16px;animation:spin 0.9s linear infinite">'+
      '<path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Loading…';
    btn.disabled = true;
    btn.style.opacity = '0.72';
  });
}

var tileIds  = ['available','inuse','reserved','total'];
var REFRESH_MS = 60000;
var autoTimer;

function setLoading(on){
  tileIds.forEach(function(id){
    var tile = document.getElementById('tile-'+id);
    if(tile){ on ? tile.classList.add('loading') : tile.classList.remove('loading'); }
  });
  var rb = document.getElementById('refreshBtn');
  if(rb){ on ? rb.classList.add('spinning') : rb.classList.remove('spinning'); }
}

function animateBar(pct){
  var fill = document.getElementById('utilFill');
  if(!fill) return;
  fill.style.width = '0';
  setTimeout(function(){ fill.style.width = pct+'%'; }, 80);
}

function updateUI(data){
  document.getElementById('val-available').textContent = data.available;
  document.getElementById('val-inuse').textContent     = data.in_use;
  document.getElementById('val-reserved').textContent  = data.reserved;
  document.getElementById('val-total').textContent     = data.total;
  document.getElementById('utilPct').textContent       = data.utilization+'%';
  document.getElementById('scopeText').textContent     = data.faculty_name;
  document.getElementById('asOfTime').textContent      = data.as_of;
  animateBar(data.utilization);
}

function fetchStats(facultyId){
  setLoading(true);
  var url = '?ajax_stats=1&faculty_id=' + encodeURIComponent(facultyId||'');
  fetch(url)
    .then(function(r){ return r.json(); })
    .then(function(data){
      setLoading(false);
      if(data.success){ updateUI(data); }
    })
    .catch(function(){
      setLoading(false);
    });
}

function resetAutoRefresh(facultyId){
  clearInterval(autoTimer);
  autoTimer = setInterval(function(){ fetchStats(facultyId); }, REFRESH_MS);
}

var facultySel = document.getElementById('facultySelect');
if(facultySel){
  facultySel.addEventListener('change', function(){
    var id = this.value;
    fetchStats(id);
    resetAutoRefresh(id);
  });
}

var refreshBtn = document.getElementById('refreshBtn');
if(refreshBtn){
  refreshBtn.addEventListener('click', function(){
    var id = facultySel ? facultySel.value : '';
    fetchStats(id);
    resetAutoRefresh(id);
  });
}

// Initial load: fetch stats for "all" (empty string)
fetchStats('');
resetAutoRefresh('');

})();
</script>
</body>
</html>