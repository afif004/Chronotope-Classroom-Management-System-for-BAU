<?php
require_once 'db_config.php';
require_once 'includes/csrf.php';

// ── Auth check ──
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_type'], ['super_admin','faculty_dean','admin'])) {
    header('Location: login.php'); exit();
}
$admin_id   = (int)$_SESSION['user_id'];
$admin_type = $_SESSION['user_type'];
$faculty_id = $_SESSION['faculty_id'] ?? null; // set at login

// If old 'admin' type still present, force logout to re‑login after migration
if ($admin_type === 'admin') {
    session_destroy(); header('Location: login.php'); exit();
}

// Re‑validate from DB
$_chk = $pdo->prepare("SELECT id, faculty_id FROM users WHERE id=? AND user_type IN ('super_admin','faculty_dean') AND is_active=1 AND account_status='active'");
$_chk->execute([$admin_id]);
$u = $_chk->fetch();
if (!$u) { session_destroy(); header('Location: login.php'); exit(); }
if ($admin_type === 'faculty_dean') $faculty_id = (int)$u['faculty_id'];
$admin_name = $_SESSION['full_name'];

$message = ''; $error = '';

// ── Audit helper ──
function logAdminAction($pdo, $admin_id, $action, $entity_type, $entity_id = null, $old = null, $new = null) {
    try {
        $pdo->prepare("INSERT INTO admin_audit_log (admin_id,action,entity_type,entity_id,old_value,new_value,ip_address) VALUES (?,?,?,?,?,?,?)")
            ->execute([$admin_id, $action, $entity_type, $entity_id,
                $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,
                $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (PDOException $e) {}
}

// ── Faculty scope helper (used in queries) ──
function facultyWhere($table, $faculty_id, $alias = null) {
    if ($faculty_id === null) return '';
    // Different tables have different ways to reach faculty
    switch ($table) {
        case 'users': // via degree_program.faculty_id
            return " AND u.degree_program_id IN (SELECT id FROM degree_programs WHERE faculty_id = $faculty_id)";
        case 'buildings':
            return " AND b.faculty_id = $faculty_id";
        case 'rooms': // via building
            return " AND b.faculty_id = $faculty_id";
        case 'degree_programs':
            return " AND dp.faculty_id = $faculty_id";
        case 'departments':
            return " AND d.faculty_id = $faculty_id";
        default: return '';
    }
}

// ── POST handler ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please refresh and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            switch ($action) {

            /* -- USER APPROVALS (unchanged) -------------------------------- */
            case 'approve_user':
                $uid = (int)$_POST['id'];
                $pdo->prepare("UPDATE users SET account_status='active', is_active=1 WHERE id=? AND account_status='pending'")->execute([$uid]);
                logAdminAction($pdo, $admin_id, 'Approved user', 'user', $uid);
                $message = 'User approved — they can now log in.';
                break;

            case 'reject_user':
                $uid = (int)$_POST['id'];
                $u = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $u->execute([$uid]); $u = $u->fetch();
                $pdo->prepare("DELETE FROM users WHERE id=? AND account_status='pending'")->execute([$uid]);
                logAdminAction($pdo, $admin_id, 'Rejected registration', 'user', $uid, $u ?? []);
                $message = 'Registration rejected.';
                break;

            case 'toggle_user_status':
                $uid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT account_status FROM users WHERE id=?"); $cur->execute([$uid]); $cur = $cur->fetch();
                $ns  = ($cur['account_status'] === 'active') ? 'inactive' : 'active';
                $pdo->prepare("UPDATE users SET account_status=?, is_active=? WHERE id=?")->execute([$ns, $ns === 'active' ? 1 : 0, $uid]);
                logAdminAction($pdo, $admin_id, 'Set user status to '.$ns, 'user', $uid);
                $message = 'User status set to '.ucfirst($ns).'.';
                break;

            case 'edit_user':
                $uid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT * FROM users WHERE id=?"); $old->execute([$uid]); $old = $old->fetch();
                if (!$old || $old['user_type'] === 'admin') { $error = 'Cannot edit this account.'; break; }
                $fn = trim($_POST['full_name']); $em = trim($_POST['email'] ?? ''); $ph = trim($_POST['phone'] ?? '');
                if ($old['user_type'] === 'teacher') {
                    $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=?,teacher_id=? WHERE id=?")
                        ->execute([$fn, $em, $ph, trim($_POST['teacher_id'] ?? ''), $uid]);
                } else {
                    $pdo->prepare("UPDATE users SET full_name=?,email=?,phone=?,group_name=?,assigned_level=?,assigned_semester=?,degree_program_id=? WHERE id=?")
                        ->execute([$fn, $em, $ph,
                            trim($_POST['group_name'] ?? '') ?: null,
                            (int)$_POST['assigned_level'], (int)$_POST['assigned_semester'],
                            (int)$_POST['degree_program_id'], $uid]);
                }
                logAdminAction($pdo, $admin_id, 'Edited user', 'user', $uid, $old, ['full_name' => $fn]);
                $message = 'User updated.';
                break;

            /* -- FACULTY (only super_admin) -------------------------------- */
            case 'add_faculty':
                if ($admin_type !== 'super_admin') { $error = 'Unauthorized'; break; }
                $pdo->prepare("INSERT INTO faculties (name,code) VALUES (?,?)")
                    ->execute([trim($_POST['name']), strtoupper(trim($_POST['code']))]);
                logAdminAction($pdo, $admin_id, 'Added faculty', 'faculty', $pdo->lastInsertId());
                $message = 'Faculty added.';
                break;

            case 'edit_faculty':
                if ($admin_type !== 'super_admin') { $error = 'Unauthorized'; break; }
                $fid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT name,code FROM faculties WHERE id=?"); $old->execute([$fid]); $old = $old->fetch();
                $pdo->prepare("UPDATE faculties SET name=?,code=? WHERE id=?")->execute([trim($_POST['name']), strtoupper(trim($_POST['code'])), $fid]);
                logAdminAction($pdo, $admin_id, 'Edited faculty', 'faculty', $fid, $old, ['name' => trim($_POST['name'])]);
                $message = 'Faculty updated.';
                break;

            case 'toggle_faculty':
                if ($admin_type !== 'super_admin') { $error = 'Unauthorized'; break; }
                $fid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT status FROM faculties WHERE id=?"); $cur->execute([$fid]); $cur = $cur->fetch();
                $ns  = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE faculties SET status=? WHERE id=?")->execute([$ns, $fid]);
                logAdminAction($pdo, $admin_id, 'Faculty status '.$ns, 'faculty', $fid);
                $message = 'Faculty '.ucfirst($ns).'.';
                break;

            /* -- DEPARTMENT (scoped) ------------------------------------ */
            case 'add_department':
                $fac_id = (int)$_POST['faculty_id'];
                if ($admin_type === 'faculty_dean' && $fac_id != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("INSERT INTO departments (faculty_id,name,code) VALUES (?,?,?)")
                    ->execute([$fac_id, trim($_POST['name']), strtoupper(trim($_POST['code']))]);
                logAdminAction($pdo, $admin_id, 'Added department', 'department', $pdo->lastInsertId());
                $message = 'Department added.';
                break;

            case 'edit_department':
                $did = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT * FROM departments WHERE id=?"); $old->execute([$did]); $old = $old->fetch();
                $fac_id = (int)$_POST['faculty_id'];
                if ($admin_type === 'faculty_dean' && ($fac_id != $faculty_id || $old['faculty_id'] != $faculty_id)) { $error = 'Unauthorized'; break; }
                $pdo->prepare("UPDATE departments SET name=?,code=?,faculty_id=? WHERE id=?")
                    ->execute([trim($_POST['name']), strtoupper(trim($_POST['code'])), $fac_id, $did]);
                logAdminAction($pdo, $admin_id, 'Edited department', 'department', $did, $old);
                $message = 'Department updated.';
                break;

            case 'toggle_department':
                $did = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT status, faculty_id FROM departments WHERE id=?"); $cur->execute([$did]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns  = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE departments SET status=? WHERE id=?")->execute([$ns, $did]);
                $message = 'Department '.ucfirst($ns).'.';
                break;

            /* -- DEGREE PROGRAMS (scoped) ------------------------------- */
            case 'add_degree':
                if ($admin_type !== 'super_admin') { $error = 'Unauthorized'; break; }
                $pdo->prepare("INSERT INTO degree_programs (faculty_id,name,code,level_type,total_levels,semesters_per_level) VALUES (?,?,?,?,?,?)")
                    ->execute([(int)$_POST['faculty_id'], trim($_POST['name']), strtoupper(trim($_POST['code'])),
                        $_POST['level_type'], (int)$_POST['total_levels'], (int)($_POST['semesters_per_level'] ?? 2)]);
                logAdminAction($pdo, $admin_id, 'Added degree', 'degree_program', $pdo->lastInsertId());
                $message = 'Degree program added.';
                break;

            case 'edit_degree':
                $dpid = (int)$_POST['id'];
                $old  = $pdo->prepare("SELECT * FROM degree_programs WHERE id=?"); $old->execute([$dpid]); $old = $old->fetch();
                $fac_id = (int)$_POST['faculty_id'];
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("UPDATE degree_programs SET name=?,code=?,faculty_id=?,level_type=?,total_levels=?,semesters_per_level=? WHERE id=?")
                    ->execute([trim($_POST['name']), strtoupper(trim($_POST['code'])),
                        $fac_id, $_POST['level_type'],
                        (int)$_POST['total_levels'], (int)($_POST['semesters_per_level'] ?? 2), $dpid]);
                logAdminAction($pdo, $admin_id, 'Edited degree', 'degree_program', $dpid, $old);
                $message = 'Degree program updated.';
                break;

            case 'toggle_degree':
                $dpid = (int)$_POST['id'];
                $cur  = $pdo->prepare("SELECT status, faculty_id FROM degree_programs WHERE id=?"); $cur->execute([$dpid]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns   = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE degree_programs SET status=? WHERE id=?")->execute([$ns, $dpid]);
                $message = 'Degree program '.ucfirst($ns).'.';
                break;

            /* -- COURSES (scoped) --------------------------------------- */
            case 'add_course':
                $dp_id = (int)$_POST['degree_program_id'];
                $level = (int)$_POST['level'];
                $sem   = (int)$_POST['semester'];
                $code  = trim($_POST['course_code']);
                $name  = trim($_POST['course_name']);
                // check faculty scope
                $chk = $pdo->prepare("SELECT faculty_id FROM degree_programs WHERE id=?");
                $chk->execute([$dp_id]); $dp_fac = $chk->fetchColumn();
                if ($admin_type === 'faculty_dean' && $dp_fac != $faculty_id) { $error = 'Unauthorized'; break; }
                if (!$dp_id || !$level || !$sem || !$code || !$name) {
                    $error = 'All fields are required.'; break;
                }
                try {
                    $pdo->prepare("INSERT INTO courses (degree_program_id, level, semester, course_code, course_name) VALUES (?,?,?,?,?)")
                        ->execute([$dp_id, $level, $sem, $code, $name]);
                    logAdminAction($pdo, $admin_id, 'Added course', 'course', $pdo->lastInsertId());
                    $message = 'Course added.';
                } catch (PDOException $e) {
                    $error = ((int)$e->getCode() === 23000) ? 'Course code already exists for this program/level/semester.' : throw $e;
                }
                break;

            case 'edit_course':
                $cid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT c.*, dp.faculty_id FROM courses c JOIN degree_programs dp ON c.degree_program_id=dp.id WHERE c.id=?"); $old->execute([$cid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("UPDATE courses SET course_code=?, course_name=?, degree_program_id=?, level=?, semester=? WHERE id=?")
                    ->execute([trim($_POST['course_code']), trim($_POST['course_name']),
                        (int)$_POST['degree_program_id'], (int)$_POST['level'], (int)$_POST['semester'], $cid]);
                logAdminAction($pdo, $admin_id, 'Edited course', 'course', $cid, $old);
                $message = 'Course updated.';
                break;

            case 'toggle_course':
                $cid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT c.status, dp.faculty_id FROM courses c JOIN degree_programs dp ON c.degree_program_id=dp.id WHERE c.id=?"); $cur->execute([$cid]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns  = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE courses SET status=? WHERE id=?")->execute([$ns, $cid]);
                $message = 'Course '.ucfirst($ns).'.';
                break;

            case 'delete_course':
                $cid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT c.*, dp.faculty_id FROM courses c JOIN degree_programs dp ON c.degree_program_id=dp.id WHERE c.id=?"); $old->execute([$cid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("DELETE FROM courses WHERE id=?")->execute([$cid]);
                logAdminAction($pdo, $admin_id, 'Deleted course', 'course', $cid, $old);
                $message = 'Course deleted.';
                break;

            /* -- BUILDINGS (scoped) ------------------------------------- */
            case 'add_building':
                $fac = (int)$_POST['faculty_id'];
                if ($admin_type === 'faculty_dean' && $fac != $faculty_id) { $error = 'Unauthorized'; break; }
                if (!$fac) { $error = 'Faculty is required.'; break; }
                $name = trim($_POST['name']);
                $code = trim($_POST['code'] ?? '');
                $desc = trim($_POST['description'] ?? '');
                $num_floors = (int)($_POST['num_floors'] ?? 0);
                if ($num_floors < 0) $num_floors = 0;
                $pdo->prepare("INSERT INTO buildings (name,code,description,faculty_id) VALUES (?,?,?,?)")
                    ->execute([$name, $code ?: null, $desc ?: null, $fac]);
                $building_id = $pdo->lastInsertId();
                for ($i = 0; $i < $num_floors; $i++) {
                    $pdo->prepare("INSERT INTO floors (building_id,floor_number) VALUES (?,?)")
                        ->execute([$building_id, $i]);
                }
                logAdminAction($pdo, $admin_id, 'Added building', 'building', $building_id);
                $message = 'Building added with floors.';
                break;

            case 'edit_building':
                $bid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT * FROM buildings WHERE id=?"); $old->execute([$bid]); $old = $old->fetch();
                $fac = (int)$_POST['faculty_id'];
                if ($admin_type === 'faculty_dean' && ($fac != $faculty_id || $old['faculty_id'] != $faculty_id)) { $error = 'Unauthorized'; break; }
                if (!$fac) { $error = 'Faculty is required.'; break; }
                $pdo->prepare("UPDATE buildings SET name=?,code=?,description=?,faculty_id=? WHERE id=?")
                    ->execute([trim($_POST['name']), trim($_POST['code'] ?? '') ?: null, trim($_POST['description'] ?? '') ?: null, $fac, $bid]);
                logAdminAction($pdo, $admin_id, 'Edited building', 'building', $bid, $old);
                $message = 'Building updated.';
                break;

            case 'toggle_building':
                $bid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT status, faculty_id FROM buildings WHERE id=?"); $cur->execute([$bid]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns  = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE buildings SET status=? WHERE id=?")->execute([$ns, $bid]);
                $message = 'Building '.ucfirst($ns).'.';
                break;

            /* -- FLOORS (scoped) ---------------------------------------- */
            case 'add_floor':
                $bid = (int)$_POST['building_id'];
                $chk = $pdo->prepare("SELECT b.faculty_id FROM buildings b WHERE b.id=?"); $chk->execute([$bid]); $b_fac = $chk->fetchColumn();
                if ($admin_type === 'faculty_dean' && $b_fac != $faculty_id) { $error = 'Unauthorized'; break; }
                try {
                    $pdo->prepare("INSERT INTO floors (building_id,floor_number) VALUES (?,?)")
                        ->execute([$bid, (int)$_POST['floor_number']]);
                    $message = 'Floor added.';
                } catch (PDOException $e) {
                    $error = ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate') !== false)
                        ? 'Floor '.(int)$_POST['floor_number'].' already exists in this building.'
                        : throw $e;
                }
                break;

            case 'edit_floor':
                $fid = (int)$_POST['id']; $fn = (int)$_POST['floor_number'];
                $old = $pdo->prepare("SELECT f.*, b.faculty_id FROM floors f JOIN buildings b ON f.building_id=b.id WHERE f.id=?"); $old->execute([$fid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                try {
                    $pdo->prepare("UPDATE floors SET floor_number=? WHERE id=?")->execute([$fn, $fid]);
                    logAdminAction($pdo, $admin_id, 'Edited floor to '.$fn, 'floor', $fid, $old);
                    $message = 'Floor updated.';
                } catch (PDOException $e) {
                    $error = ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate') !== false)
                        ? 'Floor '.$fn.' already exists in this building.'
                        : throw $e;
                }
                break;

            case 'toggle_floor':
                $fid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT f.status, b.faculty_id FROM floors f JOIN buildings b ON f.building_id=b.id WHERE f.id=?"); $cur->execute([$fid]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns  = ($cur['status'] ?? 'active') === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE floors SET status=? WHERE id=?")->execute([$ns, $fid]);
                $message = 'Floor '.ucfirst($ns).'.';
                break;

            /* -- ROOMS (scoped) ----------------------------------------- */
            case 'add_room':
                $floor_id = (int)$_POST['floor_id'];
                $chk = $pdo->prepare("SELECT b.faculty_id FROM floors f JOIN buildings b ON f.building_id=b.id WHERE f.id=?"); $chk->execute([$floor_id]); $fac = $chk->fetchColumn();
                if ($admin_type === 'faculty_dean' && $fac != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("INSERT INTO rooms (floor_id,faculty_id,room_number,room_name,room_type,capacity,has_projector,has_ac,description,status) VALUES (?,?,?,?,?,?,?,?,?,'active')")
                    ->execute([$floor_id, (int)($_POST['faculty_id'] ?? 0) ?: null,
                        trim($_POST['room_number']), trim($_POST['room_name']),
                        $_POST['room_type'], (int)$_POST['capacity'],
                        isset($_POST['has_projector']) ? 1 : 0, isset($_POST['has_ac']) ? 1 : 0,
                        trim($_POST['description'] ?? '')]);
                logAdminAction($pdo, $admin_id, 'Added room', 'room', $pdo->lastInsertId());
                $message = 'Room added.';
                break;

            case 'edit_room':
                $rid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT r.*, b.faculty_id FROM rooms r JOIN floors f ON r.floor_id=f.id JOIN buildings b ON f.building_id=b.id WHERE r.id=?"); $old->execute([$rid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $new_rnum = trim($_POST['room_number']);
                $pdo->prepare("UPDATE rooms SET room_number=?,room_name=?,room_type=?,capacity=?,has_projector=?,has_ac=?,description=?,faculty_id=? WHERE id=?")
                    ->execute([$new_rnum, trim($_POST['room_name']), $_POST['room_type'],
                        (int)$_POST['capacity'], isset($_POST['has_projector']) ? 1 : 0,
                        isset($_POST['has_ac']) ? 1 : 0, trim($_POST['description'] ?? ''),
                        (int)($_POST['faculty_id'] ?? 0) ?: null, $rid]);
                logAdminAction($pdo, $admin_id, 'Edited room', 'room', $rid, $old);
                $message = 'Room updated.';
                break;

            case 'toggle_room':
                $rid = (int)$_POST['id'];
                $cur = $pdo->prepare("SELECT r.status, b.faculty_id FROM rooms r JOIN floors f ON r.floor_id=f.id JOIN buildings b ON f.building_id=b.id WHERE r.id=?"); $cur->execute([$rid]); $cur = $cur->fetch();
                if ($admin_type === 'faculty_dean' && $cur['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $ns  = $cur['status'] === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE rooms SET status=? WHERE id=?")->execute([$ns, $rid]);
                logAdminAction($pdo, $admin_id, 'Room status '.$ns, 'room', $rid);
                $message = $ns === 'inactive' ? 'Room marked as Maintenance.' : 'Room activated.';
                break;

            /* -- SEMESTERS (scoped) ------------------------------------- */
            case 'add_semester':
                $dp_id = (int)$_POST['degree_program_id'];
                $chk = $pdo->prepare("SELECT faculty_id FROM degree_programs WHERE id=?"); $chk->execute([$dp_id]); $dp_fac = $chk->fetchColumn();
                if ($admin_type === 'faculty_dean' && $dp_fac != $faculty_id) { $error = 'Unauthorized'; break; }
                $level = (int)$_POST['level'];
                $snum  = (int)$_POST['semester_num'];
                $name  = trim($_POST['name']);
                $start = $_POST['start_date'];
                $end   = $_POST['end_date'];
                $grp   = trim($_POST['group_name'] ?? '');
                if (!$dp_id || !$level || !$snum || !$name || !$start || !$end) {
                    $error = 'All required fields must be filled.'; break;
                }
                if ($start >= $end) { $error = 'End date must be after start date.'; break; }
                try {
                    // Removed academic_session column
                    $pdo->prepare("INSERT INTO semesters (name,start_date,end_date,degree_program_id,level,semester_num,group_name,created_by) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$name, $start, $end, $dp_id, $level, $snum, $grp, $admin_id]);
                    logAdminAction($pdo, $admin_id, 'Added semester', 'semester', $pdo->lastInsertId(), null, ['name' => $name]);
                    $message = 'Semester added successfully.';
                } catch (PDOException $e) {
                    $error = ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate') !== false)
                        ? 'A semester for this Degree / Level / Semester# / Group already exists.'
                        : throw $e;
                }
                break;

            case 'edit_semester':
                $sid   = (int)$_POST['id'];
                $old   = $pdo->prepare("SELECT s.*, dp.faculty_id FROM semesters s JOIN degree_programs dp ON s.degree_program_id=dp.id WHERE s.id=?"); $old->execute([$sid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $start = $_POST['start_date'];
                $end   = $_POST['end_date'];
                $grp   = trim($_POST['group_name'] ?? '');
                if ($start >= $end) { $error = 'End date must be after start date.'; break; }
                try {
                    // Removed academic_session column
                    $pdo->prepare("UPDATE semesters SET name=?,start_date=?,end_date=?,degree_program_id=?,level=?,semester_num=?,group_name=? WHERE id=?")
                        ->execute([trim($_POST['name']), $start, $end, (int)$_POST['degree_program_id'],
                            (int)$_POST['level'], (int)$_POST['semester_num'], $grp, $sid]);
                    logAdminAction($pdo, $admin_id, 'Edited semester', 'semester', $sid, $old, ['name' => trim($_POST['name'])]);
                    $message = 'Semester updated.';
                } catch (PDOException $e) {
                    $error = ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate') !== false)
                        ? 'Another semester with the same Degree / Level / Semester# / Group exists.'
                        : throw $e;
                }
                break;

            case 'delete_semester':
                $sid = (int)$_POST['id'];
                $old = $pdo->prepare("SELECT s.*, dp.faculty_id FROM semesters s JOIN degree_programs dp ON s.degree_program_id=dp.id WHERE s.id=?"); $old->execute([$sid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $schk = $pdo->prepare("SELECT COUNT(*) FROM course_schedule WHERE semester_id=?"); $schk->execute([$sid]);
                if ((int)$schk->fetchColumn() > 0) { $error = 'Cannot delete: this semester has course schedules linked to it.'; break; }
                $pdo->prepare("DELETE FROM semesters WHERE id=?")->execute([$sid]);
                logAdminAction($pdo, $admin_id, 'Deleted semester', 'semester', $sid, $old);
                $message = 'Semester deleted.';
                break;

            /* -- SCHEDULE OVERSIGHT (scoped) ---------------------------- */
            case 'cancel_schedule':
                $csid   = (int)$_POST['id'];
                $reason = trim($_POST['reason'] ?? 'Cancelled by administrator');
                $old    = $pdo->prepare("SELECT cs.*, dp.faculty_id FROM course_schedule cs JOIN degree_programs dp ON cs.degree_program_id=dp.id WHERE cs.id=?"); $old->execute([$csid]); $old = $old->fetch();
                if ($admin_type === 'faculty_dean' && $old['faculty_id'] != $faculty_id) { $error = 'Unauthorized'; break; }
                $pdo->prepare("UPDATE course_schedule SET status='cancelled',cancelled_at=NOW(),cancelled_by=?,cancellation_reason=? WHERE id=?")
                    ->execute([$admin_id, $reason, $csid]);
                logAdminAction($pdo, $admin_id, 'Cancelled schedule', 'course_schedule', $csid, $old, ['reason' => $reason]);
                if ($old && $old['teacher_id']) {
                    $msg = "Your schedule for {$old['course_code']} has been cancelled by the administrator. Reason: $reason";
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, notify_admin) VALUES (?, 'Schedule Cancelled', ?, 'system', 1)")
                        ->execute([$old['teacher_id'], $msg]);
                }
                $message = 'Schedule cancelled.';
                break;

            /* -- NEW FEATURES (already present) ------------------------- */
            case 'add_degree_with_sems':
                if ($admin_type !== 'super_admin') { $error = 'Unauthorized'; break; }
                $pdo->beginTransaction();
                $fac = (int)$_POST['faculty_id'];
                $name = trim($_POST['name']);
                $code = strtoupper(trim($_POST['code']));
                $lvl_type = $_POST['level_type'];
                $total = (int)$_POST['total_levels'];
                $spl = (int)($_POST['semesters_per_level']);
                if (!$fac || !$name || !$code || !$total || !$spl) { $error = 'All fields required.'; break; }

                $pdo->prepare("INSERT INTO degree_programs (faculty_id,name,code,level_type,total_levels,semesters_per_level) VALUES (?,?,?,?,?,?)")
                    ->execute([$fac, $name, $code, $lvl_type, $total, $spl]);
                $degId = $pdo->lastInsertId();

                // generate semesters (start/end NULL)
                for ($lvl=1; $lvl<=$total; $lvl++) {
                    for ($s=1; $s<=$spl; $s++) {
                        $sname = "L{$lvl}-S{$s} " . strtoupper($code);
                        $pdo->prepare("INSERT INTO semesters (name,start_date,end_date,degree_program_id,level,semester_num,group_name,created_by) VALUES (?,NULL,NULL,?,?,?,'',?)")
                            ->execute([$sname, $degId, $lvl, $s, $admin_id]);
                    }
                }
                $pdo->commit();
                logAdminAction($pdo, $admin_id, 'Added degree with semesters', 'degree_program', $degId);
                $message = 'Degree program and semesters created.';
                break;

            case 'set_semester_dates':
                $degId = (int)$_POST['degree_program_id'];
                if ($admin_type === 'faculty_dean') {
                    $chk = $pdo->prepare("SELECT faculty_id FROM degree_programs WHERE id=?");
                    $chk->execute([$degId]); $fac = $chk->fetchColumn();
                    if ($fac != $faculty_id) { $error = 'Unauthorized'; break; }
                }
                $dates = $_POST['dates'] ?? []; // array of [semester_id => ['start'=>,'end'=>]]
                foreach ($dates as $sid => $dts) {
                    if ($dts['start'] && $dts['end']) {
                        $pdo->prepare("UPDATE semesters SET start_date=?, end_date=? WHERE id=? AND degree_program_id=?")
                            ->execute([$dts['start'], $dts['end'], $sid, $degId]);
                    }
                }
                $message = 'Semester dates updated.';
                break;

            case 'add_courses_ls':
                $dp_id = (int)$_POST['degree_program_id'];
                $level = (int)$_POST['level'];
                $sem   = (int)$_POST['semester'];
                if ($admin_type === 'faculty_dean') {
                    $chk = $pdo->prepare("SELECT faculty_id FROM degree_programs WHERE id=?");
                    $chk->execute([$dp_id]); $fac = $chk->fetchColumn();
                    if ($fac != $faculty_id) { $error = 'Unauthorized'; break; }
                }
                $courses = $_POST['courses'] ?? []; // array of ['code'=>,'name'=>]
                foreach ($courses as $c) {
                    $code = trim($c['code']);
                    $name = trim($c['name']);
                    if ($code && $name) {
                        try {
                            $pdo->prepare("INSERT INTO courses (degree_program_id,level,semester,course_code,course_name) VALUES (?,?,?,?,?)")
                                ->execute([$dp_id, $level, $sem, $code, $name]);
                        } catch (PDOException $e) {
                            if ($e->getCode() != 23000) throw $e; // ignore duplicate key
                        }
                    }
                }
                $message = 'Courses added.';
                break;

            } // end switch
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        }
    }
}

// ── DATA FETCHING (faculty scoped where needed) ──
$fw = $admin_type === 'faculty_dean' && $faculty_id ? "WHERE f.id = $faculty_id" : "";
$faculties = $pdo->query("SELECT * FROM faculties $fw ORDER BY name")->fetchAll();

$departments = $pdo->query("SELECT d.*, f.name AS faculty_name FROM departments d JOIN faculties f ON d.faculty_id=f.id " . ($admin_type==='faculty_dean'?"WHERE d.faculty_id=$faculty_id":"") . " ORDER BY f.name, d.name")->fetchAll();

$degree_programs = $pdo->query("SELECT dp.*, f.name AS faculty_name FROM degree_programs dp JOIN faculties f ON dp.faculty_id=f.id " . ($admin_type==='faculty_dean'?"WHERE dp.faculty_id=$faculty_id":"") . " ORDER BY f.name, dp.name")->fetchAll();

$buildings = $pdo->query("SELECT b.*, f.name AS faculty_name FROM buildings b LEFT JOIN faculties f ON b.faculty_id=f.id " . ($admin_type==='faculty_dean'?"WHERE b.faculty_id=$faculty_id":"") . " ORDER BY b.name")->fetchAll();

$floors = $pdo->query("SELECT fl.*, b.name AS building_name, b.id AS building_id FROM floors fl JOIN buildings b ON fl.building_id=b.id " . ($admin_type==='faculty_dean'?"WHERE b.faculty_id=$faculty_id":"") . " ORDER BY b.name, fl.floor_number")->fetchAll();

$rooms = $pdo->query("SELECT r.*, fl.floor_number, b.name AS building_name, b.id AS building_id, fac.name AS faculty_name FROM rooms r JOIN floors fl ON r.floor_id=fl.id JOIN buildings b ON fl.building_id=b.id LEFT JOIN faculties fac ON r.faculty_id=fac.id " . ($admin_type==='faculty_dean'?"WHERE b.faculty_id=$faculty_id":"") . " ORDER BY b.name, fl.floor_number, r.room_number")->fetchAll();

$pending_users = $pdo->query("SELECT u.*, dp.name AS degree_name FROM users u LEFT JOIN degree_programs dp ON u.degree_program_id=dp.id WHERE u.account_status='pending' " . ($admin_type==='faculty_dean'?"AND dp.faculty_id=$faculty_id":"") . " ORDER BY u.created_at DESC")->fetchAll();
$active_users  = $pdo->query("SELECT u.*, dp.name AS degree_name FROM users u LEFT JOIN degree_programs dp ON u.degree_program_id=dp.id WHERE u.user_type NOT IN ('super_admin','faculty_dean') AND u.account_status!='pending' " . ($admin_type==='faculty_dean'?"AND dp.faculty_id=$faculty_id":"") . " ORDER BY u.user_type, u.full_name")->fetchAll();
$pending_count = count($pending_users);

// Semesters query – removed s.academic_session from ORDER BY
$semesters = $pdo->query("SELECT s.*, dp.name AS degree_name, dp.total_levels, dp.semesters_per_level FROM semesters s LEFT JOIN degree_programs dp ON s.degree_program_id=dp.id " . ($admin_type==='faculty_dean'?"WHERE dp.faculty_id=$faculty_id":"") . " ORDER BY dp.name, s.level, s.semester_num, s.start_date DESC")->fetchAll();

$all_schedules = $pdo->query("SELECT cs.*, r.room_name, r.room_number, b.name AS building_name, u.full_name AS teacher_name, dp.name AS degree_name FROM course_schedule cs JOIN rooms r ON cs.room_id=r.id JOIN floors fl ON r.floor_id=fl.id JOIN buildings b ON fl.building_id=b.id LEFT JOIN users u ON cs.teacher_id=u.id LEFT JOIN degree_programs dp ON cs.degree_program_id=dp.id " . ($admin_type==='faculty_dean'?"WHERE dp.faculty_id=$faculty_id":"") . " ORDER BY cs.status='cancelled', cs.degree_program_id, cs.level, cs.day_of_week, cs.start_time LIMIT 300")->fetchAll();

$teachers = $pdo->query("SELECT id, full_name FROM users WHERE user_type='teacher' AND account_status='active'")->fetchAll();

$activity_log = $pdo->query("SELECT al.*, u.full_name, u.user_type, r.room_name, r.room_number FROM activity_log al JOIN users u ON al.user_id=u.id LEFT JOIN rooms r ON al.room_id=r.id " . ($admin_type==='faculty_dean'?"WHERE (r.id IN (SELECT r2.id FROM rooms r2 JOIN floors f2 ON r2.floor_id=f2.id JOIN buildings b2 ON f2.building_id=b2.id WHERE b2.faculty_id=$faculty_id) OR u.degree_program_id IN (SELECT id FROM degree_programs WHERE faculty_id=$faculty_id))":"") . " ORDER BY al.created_at DESC LIMIT 100")->fetchAll();

$audit_log = $pdo->query("SELECT al.*, u.full_name AS admin_name FROM admin_audit_log al JOIN users u ON al.admin_id=u.id " . ($admin_type==='faculty_dean'?"JOIN users au ON al.admin_id=au.id WHERE au.faculty_id=$faculty_id":"") . " ORDER BY al.created_at DESC LIMIT 100")->fetchAll();

$courses = $pdo->query("SELECT c.*, dp.name AS degree_name FROM courses c JOIN degree_programs dp ON c.degree_program_id=dp.id " . ($admin_type==='faculty_dean'?"WHERE dp.faculty_id=$faculty_id":"") . " ORDER BY dp.name, c.level, c.semester, c.course_code")->fetchAll();

$admin_notifications = $pdo->prepare("SELECT * FROM notifications WHERE notify_admin = 1 " . ($admin_type==='faculty_dean'?"AND related_id IN (SELECT id FROM course_schedule WHERE degree_program_id IN (SELECT id FROM degree_programs WHERE faculty_id=$faculty_id))":"") . " ORDER BY created_at DESC LIMIT 50");
$admin_notifications->execute();
$admin_notifications = $admin_notifications->fetchAll();

// Stats (scoped for faculty dean, global for super admin)
$stat_rooms = count(array_filter($rooms, fn($r)=>$r['status']==='active'));
$stat_users = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type NOT IN ('super_admin','faculty_dean') AND is_active=1 " . ($admin_type==='faculty_dean'?"AND degree_program_id IN (SELECT id FROM degree_programs WHERE faculty_id=$faculty_id)":""))->fetchColumn();
$stat_schedules = $pdo->query("SELECT COUNT(*) FROM course_schedule WHERE status='scheduled' " . ($admin_type==='faculty_dean'?"AND degree_program_id IN (SELECT id FROM degree_programs WHERE faculty_id=$faculty_id)":""))->fetchColumn();
$stat_pending = $pending_count;

// For super admin: also fetch global stats for comparison
$global_rooms = $global_users = $global_schedules = 0;
if ($admin_type === 'super_admin') {
    $global_rooms = $pdo->query("SELECT COUNT(*) FROM rooms WHERE status='active'")->fetchColumn();
    $global_users = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type NOT IN ('super_admin','faculty_dean') AND is_active=1")->fetchColumn();
    $global_schedules = $pdo->query("SELECT COUNT(*) FROM course_schedule WHERE status='scheduled'")->fetchColumn();
}

$semestersByDegree = [];
foreach ($semesters as $s) $semestersByDegree[$s['degree_program_id']][] = $s;

// Group courses by degree/level/semester
$coursesByLS = [];
foreach ($courses as $c) {
    $key = $c['degree_program_id'].'_'.$c['level'].'_'.$c['semester'];
    $coursesByLS[$key][] = $c;
}

$floorsByBuilding = [];
foreach ($floors as $fl) $floorsByBuilding[$fl['building_id']][] = $fl;

// JS payloads
$floorsJson = json_encode($floors, JSON_UNESCAPED_UNICODE);
$degreesJson = json_encode(array_map(fn($dp)=>['id'=>$dp['id'],'name'=>$dp['name'],'total_levels'=>$dp['total_levels'],'spl'=>$dp['semesters_per_level']??2], $degree_programs), JSON_UNESCAPED_UNICODE);
$dayNames = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

// Additional helper for cascade warnings (same as 1st file)
function getAffectedItems($pdo, $type, $id) {
    $items = [];
    if ($type === 'faculty') {
        $stmt = $pdo->prepare("SELECT name FROM degree_programs WHERE faculty_id=? AND status='active'");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($type === 'building') {
        $stmt = $pdo->prepare("SELECT r.room_name FROM rooms r JOIN floors fl ON r.floor_id=fl.id WHERE fl.building_id=? AND r.status='active'");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($type === 'room') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM room_bookings WHERE room_id=? AND status='booked' AND booking_date >= CURDATE()");
        $stmt->execute([$id]);
        $futureBookings = $stmt->fetchColumn();
        if ($futureBookings > 0) $items[] = "$futureBookings upcoming booking(s)";
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM course_schedule WHERE room_id=? AND status='scheduled' AND (is_whole_semester=1 OR end_date >= CURDATE())");
        $stmt->execute([$id]);
        $futureSchedules = $stmt->fetchColumn();
        if ($futureSchedules > 0) $items[] = "$futureSchedules scheduled class(es)";
    }
    return $items;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Admin — CMS</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Noto+Sans+Bengali:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
  --p:#2563eb;--pd:#1d4ed8;--pl:#eff6ff;
  --s:#059669;--sd:#047857;--sl:#f0fdf4;
  --d:#dc2626;--dd:#b91c1c;--dl:#fef2f2;
  --w:#d97706;--i:#0891b2;
  --sb:#0f172a;--bg:#f8fafc;--wh:#fff;--br:#e2e8f0;
  --g4:#94a3b8;--g6:#475569;--g7:#334155;--g8:#1e293b;--g9:#0f172a;
  --sh:0 4px 6px -1px rgba(0,0,0,.05),0 2px 4px -2px rgba(0,0,0,.03);
  --r:.75rem;--rs:.5rem;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--g8);min-height:100vh;display:flex}
.bn{font-family:'Noto Sans Bengali',sans-serif}
/* SIDEBAR */
.sidebar{width:260px;background:var(--sb);color:#fff;position:fixed;height:100vh;overflow-y:auto;z-index:1000;transition:transform .25s;display:flex;flex-direction:column}
.sb-brand{padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
.sb-brand h2{font-size:1rem;font-weight:700;display:flex;align-items:center;gap:9px}
.sb-brand .sub{font-size:.69rem;color:rgba(255,255,255,.33);margin-top:3px}
.sb-user{padding:13px 20px;background:rgba(255,255,255,.04);border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:10px}
.avatar{width:33px;height:33px;background:linear-gradient(135deg,var(--p),#7c3aed);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.83rem;flex-shrink:0}
.uname{font-size:.81rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.urole{font-size:.64rem;color:rgba(255,255,255,.36)}
.nav-sec{padding:8px 0}
.nav-title{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.27);padding:6px 20px 3px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 20px;cursor:pointer;transition:all .18s;border-left:3px solid transparent;font-size:.84rem;color:rgba(255,255,255,.58);text-decoration:none}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff}
.nav-item.active{background:rgba(37,99,235,.22);color:#fff;border-left-color:var(--p)}
.nav-item i{width:16px;text-align:center;font-size:.83rem}
.nav-badge{margin-left:auto;background:var(--d);color:#fff;font-size:.63rem;font-weight:700;padding:2px 7px;border-radius:20px}
.nav-bn{font-size:.58rem;color:rgba(255,255,255,.27);font-family:'Noto Sans Bengali',sans-serif;margin-left:auto}
.sb-foot{margin-top:auto;padding:13px 20px;border-top:1px solid rgba(255,255,255,.08)}
.sb-foot a{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.42);font-size:.81rem;text-decoration:none}

/* MAIN */
.main{margin-left:260px;flex:1;padding:24px;min-height:100vh}
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.topbar h1{font-size:1.4rem;font-weight:700;color:var(--g9);display:flex;align-items:center;gap:8px}
.topbar h1 .bn-sub{font-size:.85rem;font-weight:400;color:var(--g4);margin-left:4px}
.datechip{background:var(--wh);border:1px solid var(--br);border-radius:var(--rs);padding:6px 12px;font-size:.79rem;color:var(--g6)}

/* ALERTS */
.alert{padding:12px 17px;border-radius:var(--rs);margin-bottom:16px;display:flex;align-items:center;gap:9px;font-size:.87rem}
.a-ok{background:var(--sl);border:1px solid #bbf7d0;color:#166534}
.a-err{background:var(--dl);border:1px solid #fecaca;color:#b91c1c}

/* SECTIONS */
.sec{display:none}.sec.active{display:block}

/* CARDS */
.card{background:var(--wh);border-radius:var(--r);box-shadow:var(--sh);border:1px solid var(--br);margin-bottom:16px;overflow:hidden}
.card-hd{padding:12px 20px;border-bottom:1px solid var(--br);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;background:#fafbfc}
.card-hd h3{font-size:.93rem;font-weight:700;color:var(--g8);display:flex;align-items:center;gap:6px}
.card-bd{padding:16px 20px}

/* STATS */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:20px}
.stat{background:var(--wh);border:1px solid var(--br);border-radius:var(--r);padding:16px;border-top:3px solid var(--p)}
.stat.g{border-top-color:var(--s)}.stat.a{border-top-color:var(--w)}.stat.r{border-top-color:var(--d)}.stat.t{border-top-color:var(--i)}
.sv{font-size:1.8rem;font-weight:800;color:var(--g9);line-height:1;margin-bottom:4px}
.sl-{font-size:.76rem;color:var(--g4);font-weight:500}

/* FORMS */
.fg-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end}
label.lbl{font-size:.81rem;font-weight:600;color:var(--g7)}
.lbl-bn{font-size:.67rem;color:var(--g4);font-family:'Noto Sans Bengali',sans-serif;margin-left:4px}
.fc{padding:9px 11px;border:1.5px solid var(--br);border-radius:var(--rs);font-size:.87rem;background:#fff;width:100%}
.fc:focus{outline:none;border-color:var(--p);box-shadow:0 0 0 3px rgba(37,99,235,.1)}
select.fc{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 11px center;padding-right:30px}

/* FILTER ROW */
.filter-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.filter-row select, .filter-row input{padding:6px 10px;border:1px solid var(--br);border-radius:var(--rs);font-size:.8rem}

/* SEARCH */
.srch{position:relative}
.srch input{padding:7px 11px 7px 34px;border:1.5px solid var(--br);border-radius:var(--rs);font-size:.83rem;width:220px}
.srch i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--g4)}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:var(--rs);border:none;font-size:.83rem;font-weight:600;cursor:pointer;transition:all .18s;white-space:nowrap}
.btn-p{background:var(--p);color:#fff}.btn-p:hover{background:var(--pd)}
.btn-s{background:var(--s);color:#fff}.btn-s:hover{background:var(--sd)}
.btn-d{background:var(--d);color:#fff}.btn-d:hover{background:var(--dd)}
.btn-w{background:var(--w);color:#fff}
.btn-o{background:transparent;border:1.5px solid var(--br);color:var(--g6)}.btn-o:hover{border-color:var(--p);color:var(--p)}
.btn-sm{padding:5px 11px;font-size:.76rem}

/* TABLES */
.tw{overflow-x:auto;border-radius:var(--rs);border:1px solid var(--br)}
table{width:100%;border-collapse:collapse;min-width:500px}
thead{background:#f8fafc;border-bottom:2px solid var(--br)}
th{padding:10px 14px;font-size:.72rem;font-weight:700;color:var(--g6);text-transform:uppercase;letter-spacing:.05em;text-align:left}
td{padding:10px 14px;font-size:.85rem;border-bottom:1px solid var(--br)}
tr:last-child td{border-bottom:none}
tbody tr:hover{background:#f8fafc}

/* CHIPS */
.chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:.71rem;font-weight:600}
.chip::before{content:'';width:5px;height:5px;border-radius:50%;background:currentColor}
.c-ok{background:#dcfce7;color:#166534}
.c-off{background:#fef3c7;color:#92400e}
.c-pend{background:#dbeafe;color:#1e40af}
.tag-t{background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:12px;font-size:.69rem}
.tag-cr{background:#fce7f3;color:#190c15;padding:2px 8px;border-radius:12px;font-size:.69rem}

/* PENDING CARDS */
.pc{display:grid;grid-template-columns:1fr auto;gap:12px;padding:14px;border:1px solid var(--br);border-radius:var(--rs);margin-bottom:8px}
.pc-name{font-size:.9rem;font-weight:700;margin-bottom:4px}
.pc-meta{font-size:.77rem;color:var(--g4);line-height:1.7}
.cr-banner{font-size:.73rem;background:var(--pl);color:var(--pd);border:1px solid #bfdbfe;border-radius:4px;padding:3px 9px;margin-top:4px;display:inline-block}

/* EDIT ROW */
.edit-row{display:none;background:#f8fafc;padding:16px 20px;border-top:1px solid var(--br)}
.edit-row.show{display:block}

/* MODALS */
.mo{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;padding:20px}
.mo.on{display:flex}
.mbox{background:#fff;border-radius:1.2rem;padding:32px;max-width:500px;width:100%;max-height:80vh;overflow-y:auto;text-align:left}
.m-icon{font-size:2.5rem;margin-bottom:12px}
.m-title{font-size:1.3rem;font-weight:800;margin-bottom:8px}
.m-body{font-size:.88rem;color:var(--g6);margin-bottom:22px}
.m-body ul{list-style:disc;padding-left:20px;margin:10px 0}
.m-btns{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.m-yes{background:var(--d);color:#fff;border:none;padding:14px;border-radius:var(--rs);font-weight:700;cursor:pointer}
.m-yes.g{background:var(--s)}
.m-no{background:#f1f5f9;color:var(--g7);border:none;padding:14px;border-radius:var(--rs);font-weight:700;cursor:pointer}

.empty{text-align:center;padding:32px;color:var(--g4)}

.mob-btn{display:none;position:fixed;top:12px;left:12px;z-index:1100;background:var(--p);color:#fff;border:none;border-radius:var(--rs);padding:9px 13px;cursor:pointer}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
  .main{margin-left:0;padding:14px}.mob-btn{display:flex!important}
}

/* Degree card specific */
.degree-card { margin-bottom: 20px; }
.degree-header { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: #f1f5f9; border-radius: 8px; cursor: pointer; }
.degree-header:hover { background: #e2e8f0; }
.semester-actions { display: flex; gap: 8px; }
.sub-table { margin-top: 10px; background: #f8fafc; border-radius: 8px; padding: 8px; }
.sub-table table { min-width: auto; }
.inline-course-row { display: flex; gap: 10px; margin-bottom: 6px; align-items: center; }
.inline-course-row input { flex: 1; }
</style>
</head>
<body>
<button class="mob-btn" onclick="document.getElementById('sidebar').classList.toggle('open')"><i class="fas fa-bars"></i></button>
<aside class="sidebar" id="sidebar">
  <div class="sb-brand"><h2><i class="fas fa-school"></i> Admin Panel</h2><div class="sub"><?= $admin_type==='faculty_dean'?'Faculty Dean':'Super Admin' ?></div></div>
  <div class="sb-user"><div class="avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div><div><div class="uname"><?= htmlspecialchars($admin_name) ?></div><div class="urole"><?= $admin_type==='faculty_dean'?'Faculty Dean':'Super Admin' ?></div></div></div>
  <div class="nav-sec">
    <div class="nav-title">Overview</div>
    <a class="nav-item active" onclick="showSec('dashboard')" href="#"><i class="fas fa-chart-line"></i> Dashboard</a>
    <a class="nav-item" onclick="showSec('pending')" href="#"><i class="fas fa-user-clock"></i> Pending <?php if($pending_count): ?><span class="nav-badge"><?= $pending_count ?></span><?php endif; ?></a>
    <a class="nav-item" onclick="showSec('users')" href="#"><i class="fas fa-users"></i> Users</a>
    <a class="nav-item" onclick="showSec('notifications')" href="#"><i class="fas fa-bell"></i> Notifications <?php if(count($admin_notifications)): ?><span class="nav-badge"><?= count($admin_notifications) ?></span><?php endif; ?></a>
  </div>
  <div class="nav-sec">
    <div class="nav-title">Academic</div>
    <?php if ($admin_type==='super_admin'): ?><a class="nav-item" onclick="showSec('faculties')" href="#"><i class="fas fa-university"></i> Faculties</a><?php endif; ?>
    <a class="nav-item" onclick="showSec('departments')" href="#"><i class="fas fa-sitemap"></i> Departments</a>
    <a class="nav-item" onclick="showSec('degrees')" href="#"><i class="fas fa-graduation-cap"></i> Degree Programs</a>
    <a class="nav-item" onclick="showSec('schedules')" href="#"><i class="fas fa-book-open"></i> Schedules</a>
  </div>
  <div class="nav-sec">
    <div class="nav-title">Facilities</div>
    <a class="nav-item" onclick="showSec('buildings')" href="#"><i class="fas fa-building"></i> Buildings & Floors</a>
    <a class="nav-item" onclick="showSec('rooms')" href="#"><i class="fas fa-door-open"></i> Rooms</a>
  </div>
  <div class="nav-sec">
    <div class="nav-title">Logs</div>
    <a class="nav-item" onclick="showSec('audit')" href="#"><i class="fas fa-shield-alt"></i> Audit Log</a>
    <a class="nav-item" onclick="showSec('actlog')" href="#"><i class="fas fa-stream"></i> Activity Log</a>
  </div>
  <div class="sb-foot"><a href="logout.php"><i class="fas fa-sign-out-alt"></i> Sign Out</a></div>
</aside>

<main class="main">
  <div class="topbar">
    <h1 id="pageTitle">Dashboard <span class="bn-sub" id="pageTitleBn">ড্যাশবোর্ড</span></h1>
    <div style="display:flex;gap:9px"><div class="datechip"><i class="fas fa-calendar"></i> <?= date('d M Y') ?></div><a href="logout.php" class="btn btn-o btn-sm"><i class="fas fa-sign-out-alt"></i></a></div>
  </div>

  <?php if($message): ?><div class="alert a-ok"><i class="fas fa-check-circle"></i><?= htmlspecialchars($message) ?></div><?php endif; ?>
  <?php if($error):   ?><div class="alert a-err"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($error) ?></div><?php endif; ?>

  <!-- DASHBOARD -->
  <section class="sec active" id="sec-dashboard">
    <div class="stats">
      <div class="stat"><div class="sv"><?= $stat_rooms ?></div><div class="sl-">Active Rooms</div></div>
      <div class="stat g"><div class="sv"><?= $stat_users ?></div><div class="sl-">Active Users</div></div>
      <div class="stat a"><div class="sv"><?= $stat_schedules ?></div><div class="sl-">Scheduled</div></div>
      <div class="stat r"><div class="sv"><?= $stat_pending ?></div><div class="sl-">Pending</div></div>
      <div class="stat t"><div class="sv"><?= count($degree_programs) ?></div><div class="sl-">Programs</div></div>
    </div>
    <?php if ($admin_type==='super_admin'): ?>
    <div class="card"><div class="card-hd"><h3>Global Overview</h3></div>
        <div class="card-bd" style="display:flex;gap:20px">
            <div>Total Rooms: <?=$global_rooms?></div>
            <div>Total Users: <?=$global_users?></div>
            <div>Total Schedules: <?=$global_schedules?></div>
        </div>
    </div>
    <?php endif; ?>
    <?php if($pending_count > 0): ?>
    <div class="card" style="border-left:4px solid var(--d)"><div class="card-hd" style="background:var(--dl)"><h3 style="color:var(--d)"><i class="fas fa-bell"></i> <?= $pending_count ?> awaiting approval</h3><button class="btn btn-d btn-sm" onclick="showSec('pending')">Review <i class="fas fa-arrow-right"></i></button></div></div>
    <?php endif; ?>
    <div class="card"><div class="card-hd"><h3><i class="fas fa-bolt"></i> Quick Actions</h3></div><div class="card-bd" style="display:flex;flex-wrap:wrap;gap:8px">
      <button class="btn btn-p" onclick="showSec('pending')"><i class="fas fa-user-check"></i> Approve</button>
      <button class="btn btn-s" onclick="showSec('degrees')"><i class="fas fa-calendar-alt"></i> Semesters</button>
      <button class="btn btn-i" onclick="showSec('schedules')"><i class="fas fa-book-open"></i> Schedules</button>
      <button class="btn btn-o" onclick="showSec('rooms')"><i class="fas fa-door-open"></i> Rooms</button>
      <button class="btn btn-o" onclick="showSec('audit')"><i class="fas fa-shield-alt"></i> Audit</button>
    </div></div>
  </section>

  <!-- PENDING APPROVALS -->
  <section class="sec" id="sec-pending">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-user-clock"></i> Pending Approvals <span class="lbl-bn">অপেক্ষমাণ</span></h3></div>
    <div class="card-bd">
      <?php if(empty($pending_users)): ?><div class="empty"><i class="fas fa-check-circle" style="color:var(--s)"></i><p>No pending requests</p></div>
      <?php else: foreach($pending_users as $u): // CR count detection using existing $cr_counts? We'll recompute inline ?>
      <?php $existingCRs = null; if($u['user_type']==='cr' && $u['degree_program_id'] && $u['assigned_level'] && $u['assigned_semester']){ $k = $u['degree_program_id'].'_'.$u['assigned_level'].'_'.$u['assigned_semester']; $sch = $pdo->prepare("SELECT COUNT(*) FROM users WHERE user_type='cr' AND account_status='active' AND degree_program_id=? AND assigned_level=? AND assigned_semester=?"); $sch->execute([$u['degree_program_id'], $u['assigned_level'], $u['assigned_semester']]); $existingCRs = (int)$sch->fetchColumn(); } ?>
      <div class="pc">
        <div>
          <div class="pc-name"><?= htmlspecialchars($u['full_name']) ?> <?= $u['user_type']==='teacher'?'<span class="tag-t">Teacher</span>':'<span class="tag-cr">CR</span>' ?></div>
          <div class="pc-meta"><?= htmlspecialchars($u['username']) ?> &nbsp;|&nbsp;
            <?php if($u['user_type']==='teacher'): ?>ID: <?= htmlspecialchars($u['teacher_id']??'—') ?><br>Email: <?= htmlspecialchars($u['email']??'—') ?>
            <?php else: ?><?= htmlspecialchars($u['degree_name']??'—') ?> L<?= $u['assigned_level'] ?> S<?= $u['assigned_semester'] ?><?php if($u['group_name']): ?> | Group: <?= htmlspecialchars($u['group_name']) ?><?php endif; ?><?php if($existingCRs !== null): ?><span class="cr-banner"><i class="fas fa-info-circle"></i> <?= $existingCRs ?> active CR<?= $existingCRs!=1?'s':'' ?></span><?php endif; ?><?php endif; ?>
            <br><i class="far fa-clock"></i> <?= date('d M Y', strtotime($u['created_at'])) ?></div>
        </div>
        <div class="pc-actions">
          <form method="POST" id="apr_<?=$u['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="approve_user"><input type="hidden" name="id" value="<?=$u['id']?>"></form>
          <form method="POST" id="rej_<?=$u['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="reject_user"><input type="hidden" name="id" value="<?=$u['id']?>"></form>
          <button class="btn btn-s btn-sm" onclick="confirm2('g','apr_<?=$u['id']?>','Approve?','<strong><?= addslashes(htmlspecialchars($u['full_name'])) ?></strong> will be able to log in.')"><i class="fas fa-check"></i></button>
          <button class="btn btn-d btn-sm" onclick="confirm2('r','rej_<?=$u['id']?>','Reject?','Permanently remove <strong><?= addslashes(htmlspecialchars($u['full_name'])) ?></strong>.')"><i class="fas fa-times"></i></button>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div></div>
  </section>

  <!-- USERS -->
  <section class="sec" id="sec-users">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-users"></i> All Users (<?= count($active_users) ?>)</h3><div class="srch"><i class="fas fa-search"></i><input type="text" placeholder="Search" oninput="filterTbl(this,'userTbl')"></div></div>
    <div class="tw"><table id="userTbl"><thead><tr><th>Name</th><th>Username</th><th>Type</th><th>Details</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($active_users as $u): $ust = $u['account_status'] ?? ($u['is_active'] ? 'active' : 'inactive'); ?>
    <tr data-s="<?= strtolower($u['full_name'].' '.$u['username']) ?>">
      <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td><td><?= htmlspecialchars($u['username']) ?></td>
      <td><?= $u['user_type']==='teacher'?'<span class="tag-t">Teacher</span>':'<span class="tag-cr">CR</span>' ?></td>
      <td style="font-size:.77rem"><?= $u['user_type']==='teacher'? 'ID: '.htmlspecialchars($u['teacher_id']??'—') : htmlspecialchars($u['degree_name']??'—').' L'.$u['assigned_level'].' S'.$u['assigned_semester'] ?></td>
      <td><span class="chip <?= $ust==='active'?'c-ok':'c-off' ?>"><?= ucfirst($ust) ?></span></td>
      <td><form method="POST" id="tu_<?=$u['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="toggle_user_status"><input type="hidden" name="id" value="<?=$u['id']?>"></form>
        <div style="display:flex;gap:4px"><?php if($ust==='active'): ?><button class="btn btn-d btn-sm" onclick="confirm2('','tu_<?=$u['id']?>','Deactivate?','')"><i class="fas fa-ban"></i></button><?php else: ?><button class="btn btn-s btn-sm" onclick="confirm2('g','tu_<?=$u['id']?>','Activate?','')"><i class="fas fa-check"></i></button><?php endif; ?><button class="btn btn-o btn-sm" onclick="toggleED('eu<?=$u['id']?>')"><i class="fas fa-edit"></i></button></div></td>
    </tr>
    <tr id="ed-eu<?=$u['id']?>"><td colspan="6"><div class="edit-row"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="<?=$u['id']?>">
      <div class="fg"><label class="lbl">Full Name</label><input type="text" name="full_name" class="fc" value="<?= htmlspecialchars($u['full_name']) ?>" required></div>
      <div class="fg"><label class="lbl">Email</label><input type="email" name="email" class="fc" value="<?= htmlspecialchars($u['email']??'') ?>"></div>
      <div class="fg"><label class="lbl">Phone</label><input type="text" name="phone" class="fc" value="<?= htmlspecialchars($u['phone']??'') ?>"></div>
      <?php if($u['user_type']==='teacher'): ?><div class="fg"><label class="lbl">Teacher ID</label><input type="text" name="teacher_id" class="fc" value="<?= htmlspecialchars($u['teacher_id']??'') ?>"></div>
      <?php else: ?><div class="fg"><label class="lbl">Degree</label><select name="degree_program_id" class="fc"><?php foreach($degree_programs as $dp): ?><option value="<?=$dp['id']?>" <?=$dp['id']==$u['degree_program_id']?'selected':''?>><?= htmlspecialchars($dp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="fg"><label class="lbl">Level</label><input type="number" name="assigned_level" class="fc" value="<?=$u['assigned_level']?>" min="1"></div>
      <div class="fg"><label class="lbl">Semester</label><input type="number" name="assigned_semester" class="fc" value="<?=$u['assigned_semester']?>"></div>
      <div class="fg"><label class="lbl">Group</label><input type="text" name="group_name" class="fc" value="<?= htmlspecialchars($u['group_name']??'') ?>"></div><?php endif; ?>
      <div class="fg"><button type="submit" class="btn btn-p btn-sm">Save</button></div>
    </form></div></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </section>

  <!-- NOTIFICATIONS -->
  <section class="sec" id="sec-notifications">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-bell"></i> System Notifications</h3></div><div class="card-bd">
      <?php if(empty($admin_notifications)): ?><div class="empty"><i class="fas fa-check-circle" style="color:var(--s)"></i><p>No notifications</p></div>
      <?php else: foreach($admin_notifications as $n): ?><div class="notification-item" style="padding:10px 0; border-bottom:1px solid var(--br)"><div class="notification-title" style="font-weight:bold"><?= htmlspecialchars($n['title']) ?></div><div class="notification-message" style="font-size:.8rem"><?= htmlspecialchars($n['message']) ?></div><div class="notification-time" style="font-size:.7rem; color:var(--g4)"><?= date('M d, Y g:i A', strtotime($n['created_at'])) ?></div></div><?php endforeach; endif; ?>
    </div></div>
  </section>

  <!-- FACULTIES (only for super_admin) -->
  <?php if ($admin_type === 'super_admin'): ?>
  <section class="sec" id="sec-faculties">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-plus-circle"></i> Add Faculty</h3></div><div class="card-bd"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="add_faculty">
      <div class="fg"><label class="lbl">Faculty Name</label><input type="text" name="name" class="fc" required></div>
      <div class="fg"><label class="lbl">Code</label><input type="text" name="code" class="fc" required></div>
      <div class="fg"><button type="submit" class="btn btn-p">Add Faculty</button></div>
    </form></div></div>
    <div class="card"><div class="card-hd"><h3><i class="fas fa-list"></i> Faculties</h3></div><div class="tw"><table><thead><tr><th>Name</th><th>Code</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($faculties as $f): $fa=($f['status']??'active')==='active'; ?>
    <tr><td><strong><?= htmlspecialchars($f['name']) ?></strong></td><td><?= htmlspecialchars($f['code']) ?></td><td><span class="chip <?=$fa?'c-ok':'c-off'?>"><?=$fa?'Active':'Inactive'?></span></td>
    <td><form method="POST" id="tf_<?=$f['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="toggle_faculty"><input type="hidden" name="id" value="<?=$f['id']?>"></form>
      <div style="display:flex;gap:4px"><button class="btn btn-o btn-sm" onclick="toggleED('fac<?=$f['id']?>')"><i class="fas fa-edit"></i></button>
      <?php if($fa):?><button class="btn btn-w btn-sm" onclick="confirm2('','tf_<?=$f['id']?>','Deactivate?','')"><i class="fas fa-pause"></i></button><?php else:?><button class="btn btn-s btn-sm" onclick="confirm2('g','tf_<?=$f['id']?>','Activate?','')"><i class="fas fa-play"></i></button><?php endif;?></div>
    </td></tr>
    <tr id="ed-fac<?=$f['id']?>"><td colspan="4"><div class="edit-row"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="edit_faculty"><input type="hidden" name="id" value="<?=$f['id']?>">
      <div class="fg"><input type="text" name="name" class="fc" value="<?= htmlspecialchars($f['name']) ?>" required></div>
      <div class="fg"><input type="text" name="code" class="fc" value="<?= htmlspecialchars($f['code']) ?>" required></div>
      <div class="fg"><button type="submit" class="btn btn-p btn-sm">Save</button></div>
    </form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>
  <?php endif; ?>

  <!-- DEPARTMENTS -->
  <section class="sec" id="sec-departments">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-plus-circle"></i> Add Department</h3></div><div class="card-bd"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="add_department">
      <div class="fg"><label class="lbl">Faculty</label><select name="faculty_id" class="fc" required><?php foreach($faculties as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
      <div class="fg"><label class="lbl">Department Name</label><input type="text" name="name" class="fc" required></div>
      <div class="fg"><label class="lbl">Code</label><input type="text" name="code" class="fc" required></div>
      <div class="fg"><button type="submit" class="btn btn-p">Add Department</button></div>
    </form></div></div>
    <div class="card"><div class="card-hd"><h3><i class="fas fa-sitemap"></i> Departments</h3></div><div class="tw"><table><thead><tr><th>Faculty</th><th>Name</th><th>Code</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($departments as $d): $da=($d['status']??'active')==='active'; ?>
    <tr><td><?=htmlspecialchars($d['faculty_name'])?></td><td><strong><?=htmlspecialchars($d['name'])?></strong></td><td><?=htmlspecialchars($d['code'])?></td><td><span class="chip <?=$da?'c-ok':'c-off'?>"><?=$da?'Active':'Inactive'?></span></td>
    <td><form method="POST" id="td_<?=$d['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="toggle_department"><input type="hidden" name="id" value="<?=$d['id']?>"></form>
      <div style="display:flex;gap:4px"><button class="btn btn-o btn-sm" onclick="toggleED('dep<?=$d['id']?>')"><i class="fas fa-edit"></i></button>
      <?php if($da):?><button class="btn btn-w btn-sm" onclick="confirm2('','td_<?=$d['id']?>','Deactivate?','')"><i class="fas fa-pause"></i></button><?php else:?><button class="btn btn-s btn-sm" onclick="confirm2('g','td_<?=$d['id']?>','Activate?','')"><i class="fas fa-play"></i></button><?php endif;?></div>
    </td></tr>
    <tr id="ed-dep<?=$d['id']?>"><td colspan="5"><div class="edit-row"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="edit_department"><input type="hidden" name="id" value="<?=$d['id']?>">
      <div class="fg"><select name="faculty_id" class="fc"><?php foreach($faculties as $f):?><option value="<?=$f['id']?>" <?=$f['id']==$d['faculty_id']?'selected':''?>><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
      <div class="fg"><input type="text" name="name" class="fc" value="<?=htmlspecialchars($d['name'])?>" required></div>
      <div class="fg"><input type="text" name="code" class="fc" value="<?=htmlspecialchars($d['code'])?>" required></div>
      <div class="fg"><button type="submit" class="btn btn-p btn-sm">Save</button></div>
    </form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- DEGREE PROGRAMS (with semester auto‑generation and inline courses) -->
  <section class="sec" id="sec-degrees">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-plus-circle"></i> Add Degree Program</h3></div>
        <div class="card-bd"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="add_degree_with_sems">
            <div class="fg"><label class="lbl">Faculty</label><select name="faculty_id" class="fc" required><option value="">Select</option><?php foreach($faculties as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
            <div class="fg"><label class="lbl">Program Name</label><input type="text" name="name" class="fc" required></div>
            <div class="fg"><label class="lbl">Code</label><input type="text" name="code" class="fc" required></div>
            <div class="fg"><label class="lbl">Level Type</label><select name="level_type" class="fc"><option value="bachelor">Bachelor</option><option value="masters">Masters</option><option value="phd">PhD</option></select></div>
            <div class="fg"><label class="lbl">Total Levels</label><input type="number" name="total_levels" class="fc" value="4" min="1"></div>
            <div class="fg"><label class="lbl">Sem/Level</label><input type="number" name="semesters_per_level" class="fc" value="2" min="1"></div>
            <div class="fg"><button type="submit" class="btn btn-p">Add Program & Generate Semesters</button></div>
        </form></div>
    </div>

    <?php foreach($degree_programs as $dp): $dpa=($dp['status']??'active')==='active'; ?>
    <div class="card degree-card">
      <div class="degree-header" onclick="toggleDegreeSemesters(<?=$dp['id']?>)">
        <div><strong><?=htmlspecialchars($dp['name'])?></strong> (<?=htmlspecialchars($dp['code'])?>) <span class="chip <?=$dpa?'c-ok':'c-off'?>"><?=$dpa?'Active':'Inactive'?></span></div>
        <div class="semester-actions" onclick="event.stopPropagation()">
          <button class="btn btn-o btn-sm" onclick="openSetDatesModal(<?=$dp['id']?>)"><i class="fas fa-calendar"></i> Set Dates</button>
          <button class="btn btn-o btn-sm" onclick="toggleED('deg<?=$dp['id']?>')"><i class="fas fa-edit"></i> Edit</button>
          <form method="POST" id="tdp_<?=$dp['id']?>" style="display:inline"><?= csrfField() ?><input type="hidden" name="action" value="toggle_degree"><input type="hidden" name="id" value="<?=$dp['id']?>"></form>
          <?php if($dpa):?><button class="btn btn-w btn-sm" onclick="confirm2('','tdp_<?=$dp['id']?>','Deactivate?','')"><i class="fas fa-pause"></i></button><?php else:?><button class="btn btn-s btn-sm" onclick="confirm2('g','tdp_<?=$dp['id']?>','Activate?','')"><i class="fas fa-play"></i></button><?php endif;?>
        </div>
      </div>
      <!-- Edit Degree Row -->
      <div class="edit-row" id="ed-deg<?=$dp['id']?>"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="edit_degree"><input type="hidden" name="id" value="<?=$dp['id']?>">
        <div class="fg"><select name="faculty_id" class="fc"><?php foreach($faculties as $f): ?><option value="<?=$f['id']?>" <?=$f['id']==$dp['faculty_id']?'selected':''?>><?=htmlspecialchars($f['name'])?></option><?php endforeach; ?></select></div>
        <div class="fg"><input type="text" name="name" class="fc" value="<?=htmlspecialchars($dp['name'])?>"></div>
        <div class="fg"><input type="text" name="code" class="fc" value="<?=htmlspecialchars($dp['code'])?>"></div>
        <div class="fg"><select name="level_type" class="fc"><option value="bachelor" <?=$dp['level_type']=='bachelor'?'selected':''?>>Bachelor</option><option value="masters" <?=$dp['level_type']=='masters'?'selected':''?>>Masters</option><option value="phd" <?=$dp['level_type']=='phd'?'selected':''?>>PhD</option></select></div>
        <div class="fg"><input type="number" name="total_levels" class="fc" value="<?=$dp['total_levels']?>"></div>
        <div class="fg"><input type="number" name="semesters_per_level" class="fc" value="<?=$dp['semesters_per_level']??2?>"></div>
        <div class="fg"><button type="submit" class="btn btn-p btn-sm">Save</button></div>
      </form></div>
      <div id="semesters-<?=$dp['id']?>" class="sub-table" style="display:none;">
          <?php $degSems = $semestersByDegree[$dp['id']] ?? []; ?>
          <?php if(empty($degSems)):?><p>No semesters yet.</p>
          <?php else:?>
          <table class="degree-sems-table tw">
              <thead><tr><th>Level</th><th>Sem</th><th>Group</th><th>Name</th><th>Start</th><th>End</th><th>Courses</th><th>Actions</th></tr></thead>
              <tbody>
              <?php foreach($degSems as $s):
                  $key = $dp['id'].'_'.$s['level'].'_'.$s['semester_num'];
                  $semCourses = $coursesByLS[$key] ?? [];
                  $hasDates = $s['start_date'] && $s['end_date'];
              ?>
              <tr>
                  <td>L<?=$s['level']?></td><td>S<?=$s['semester_num']?></td><td><?=$s['group_name']?:'—'?></td>
                  <td><?=htmlspecialchars($s['name'])?></td>
                  <td><?=$hasDates ? date('d M',strtotime($s['start_date'])) : '<span class="text-muted">—</span>'?></td>
                  <td><?=$hasDates ? date('d M',strtotime($s['end_date'])) : '<span class="text-muted">—</span>'?></td>
                  <td>
                      <?php foreach($semCourses as $c):?>
                          <span style="display:inline-block; background:#e2e8f0; padding:2px 6px; border-radius:4px; margin:2px; font-size:0.7rem;"><?=htmlspecialchars($c['course_code'])?></span>
                      <?php endforeach;?>
                      <?php if(empty($semCourses)):?><span class="chip c-off">No courses</span><?php endif;?>
                  </td>
                  <td><button class="btn btn-sm btn-o" onclick="openAddCoursesModal(<?=$dp['id']?>,<?=$s['level']?>,<?=$s['semester_num']?>)"><i class="fas fa-plus"></i> Courses</button></td>
              </tr>
              <?php endforeach;?>
              </tbody>
          </table>
          <?php endif;?>
      </div>
    </div>
    <?php endforeach;?>
  </section>

  <!-- SCHEDULES -->
  <section class="sec" id="sec-schedules">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-calendar-alt"></i> Course Schedules</h3>
      <div class="filter-row"><select id="filterSchDegree"><option value="">All Degrees</option><?php foreach($degree_programs as $dp):?><option value="<?=$dp['id']?>"><?=htmlspecialchars($dp['name'])?></option><?php endforeach;?></select>
      <select id="filterSchTeacher"><option value="">All Teachers</option><?php foreach($teachers as $t):?><option value="<?=$t['id']?>"><?=htmlspecialchars($t['full_name'])?></option><?php endforeach;?></select>
      <select id="filterSchStatus"><option value="">All Status</option><option value="scheduled">Scheduled</option><option value="cancelled">Cancelled</option></select>
      <button class="btn btn-o btn-sm" onclick="filterSchedules()"><i class="fas fa-filter"></i></button><button class="btn btn-o btn-sm" onclick="resetSchFilters()"><i class="fas fa-undo"></i></button>
    </div></div>
    <div class="tw"><table id="schTbl"><thead><tr><th>Degree</th><th>Level/Sem</th><th>Course</th><th>Teacher</th><th>Room</th><th>Day</th><th>Time</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($all_schedules as $sch): $dayName = $dayNames[$sch['day_of_week']] ?? ''; ?>
    <tr data-degree="<?=$sch['degree_program_id']?>" data-teacher="<?=$sch['teacher_id']?>" data-status="<?=$sch['status']?>">
      <td><?=htmlspecialchars($sch['degree_name'])?></td><td>L<?=$sch['level']?> S<?=$sch['semester']?></td>
      <td><?=htmlspecialchars($sch['course_code'])?><br><small><?=htmlspecialchars($sch['course_name'])?></small></td>
      <td><?=htmlspecialchars($sch['teacher_name']??'—')?></td>
      <td><?=htmlspecialchars($sch['building_name'])?> - <?=htmlspecialchars($sch['room_number'])?> (<?=htmlspecialchars($sch['room_name'])?>)</td>
      <td><?=$dayName?></td><td><?=substr($sch['start_time'],0,5)?> - <?=substr($sch['end_time'],0,5)?></td>
      <td><span class="chip <?=$sch['status']==='scheduled'?'c-ok':'c-off'?>"><?=ucfirst($sch['status'])?></span></td>
      <td><?php if($sch['status']==='scheduled'): ?><button class="btn btn-d btn-sm" onclick="openCancelSch(<?=$sch['id']?>,'<?=htmlspecialchars($sch['course_code'])?>')"><i class="fas fa-ban"></i></button><?php endif; ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- BUILDINGS & FLOORS -->
  <section class="sec" id="sec-buildings">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-building"></i> Add Building</h3></div><div class="card-bd"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="add_building">
      <div class="fg"><label class="lbl">Faculty</label><select name="faculty_id" class="fc" required><?php foreach($faculties as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
      <div class="fg"><label class="lbl">Building Name</label><input type="text" name="name" class="fc" required></div>
      <div class="fg"><label class="lbl">Code (opt)</label><input type="text" name="code" class="fc"></div>
      <div class="fg"><label class="lbl"># Floors</label><input type="number" name="num_floors" class="fc" value="0" min="0"></div>
      <div class="fg"><label class="lbl">Description</label><input type="text" name="description" class="fc"></div>
      <div class="fg"><button type="submit" class="btn btn-p">Add Building</button></div>
    </form></div></div>
    <div class="card"><div class="card-hd"><h3><i class="fas fa-list"></i> Buildings & Floors</h3></div><div class="tw"><table><thead><tr><th>Faculty</th><th>Building</th><th>Code</th><th>Status</th><th>Floors</th><th></th></tr></thead><tbody>
    <?php foreach($buildings as $bld): $ba=($bld['status']??'active')==='active'; $bldFloors = $floorsByBuilding[$bld['id']] ?? []; ?>
    <tr><td><?=htmlspecialchars($bld['faculty_name']??'—')?></td><td><strong><?=htmlspecialchars($bld['name'])?></strong></td><td><?=htmlspecialchars($bld['code']??'—')?></td><td><span class="chip <?=$ba?'c-ok':'c-off'?>"><?=$ba?'Active':'Inactive'?></span></td>
    <td><?php foreach($bldFloors as $fl): echo "Floor {$fl['floor_number']} "; endforeach; ?></td>
    <td><form method="POST" id="tb_<?=$bld['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="toggle_building"><input type="hidden" name="id" value="<?=$bld['id']?>"></form>
      <div style="display:flex;gap:4px"><button class="btn btn-o btn-sm" onclick="toggleED('bld<?=$bld['id']?>')"><i class="fas fa-edit"></i></button>
      <?php if($ba):?><button class="btn btn-w btn-sm" onclick="confirm2('','tb_<?=$bld['id']?>','Deactivate?','')"><i class="fas fa-pause"></i></button><?php else:?><button class="btn btn-s btn-sm" onclick="confirm2('g','tb_<?=$bld['id']?>','Activate?','')"><i class="fas fa-play"></i></button><?php endif;?>
      <button class="btn btn-sm btn-p" onclick="showAddFloor(<?=$bld['id']?>)"><i class="fas fa-plus"></i> Floor</button></div>
    </td></tr>
    <tr id="ed-bld<?=$bld['id']?>"><td colspan="6"><div class="edit-row"><form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="edit_building"><input type="hidden" name="id" value="<?=$bld['id']?>">
      <div class="fg"><select name="faculty_id" class="fc"><?php foreach($faculties as $f):?><option value="<?=$f['id']?>" <?=$f['id']==$bld['faculty_id']?'selected':''?>><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
      <div class="fg"><input type="text" name="name" class="fc" value="<?=htmlspecialchars($bld['name'])?>"></div>
      <div class="fg"><input type="text" name="code" class="fc" value="<?=htmlspecialchars($bld['code']??'')?>"></div>
      <div class="fg"><input type="text" name="description" class="fc" value="<?=htmlspecialchars($bld['description']??'')?>"></div>
      <div class="fg"><button type="submit" class="btn btn-p btn-sm">Save</button></div>
    </form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- ROOMS -->
  <section class="sec" id="sec-rooms">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-door-open"></i> Add / Edit Rooms</h3></div><div class="card-bd">
      <form method="POST" class="fg-grid"><?= csrfField() ?><input type="hidden" name="action" value="add_room">
        <div class="fg"><label class="lbl">Faculty</label><select name="faculty_id" id="roomFaculty" class="fc" onchange="loadBuildings(this.value)" required><?php foreach($faculties as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
        <div class="fg"><label class="lbl">Building</label><select name="building_id" id="roomBuilding" class="fc" onchange="loadFloorsForRoom(this.value)" disabled><option value="">Select Faculty first</option></select></div>
        <div class="fg"><label class="lbl">Floor</label><select name="floor_id" id="roomFloor" class="fc" disabled><option value="">Select Building first</option></select></div>
        <div class="fg"><label class="lbl">Room Number</label><input type="text" name="room_number" class="fc" required></div>
        <div class="fg"><label class="lbl">Room Name</label><input type="text" name="room_name" class="fc"></div>
        <div class="fg"><label class="lbl">Type</label><select name="room_type" class="fc"><option value="lecture">Lecture</option><option value="lab">Lab</option><option value="office">Office</option></select></div>
        <div class="fg"><label class="lbl">Capacity</label><input type="number" name="capacity" class="fc" value="30"></div>
        <div class="fg"><label><input type="checkbox" name="has_projector"> Projector</label></div>
        <div class="fg"><label><input type="checkbox" name="has_ac"> AC</label></div>
        <div class="fg"><label class="lbl">Description</label><input type="text" name="description" class="fc"></div>
        <div class="fg"><button type="submit" class="btn btn-p">Add Room</button></div>
      </form>
    </div></div>
    <div class="card"><div class="card-hd"><h3><i class="fas fa-list"></i> Rooms List</h3><div class="srch"><i class="fas fa-search"></i><input type="text" placeholder="Search" oninput="filterTbl(this,'roomsTbl')"></div></div>
    <div class="tw"><table id="roomsTbl"><thead><tr><th>Building</th><th>Floor</th><th>Room #</th><th>Name</th><th>Type</th><th>Capacity</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach($rooms as $r): $ra=($r['status']??'active')==='active'; ?>
    <tr data-s="<?=strtolower($r['building_name'].' '.$r['room_number'].' '.$r['room_name'])?>">
      <td><?=htmlspecialchars($r['building_name'])?></td><td><?=$r['floor_number']==0?'Ground':$r['floor_number']?></td>
      <td><strong><?=htmlspecialchars($r['room_number'])?></strong></td><td><?=htmlspecialchars($r['room_name'])?></td>
      <td><?=ucfirst($r['room_type'])?></td><td><?=$r['capacity']?></td>
      <td><span class="chip <?=$ra?'c-ok':'c-off'?>"><?=$ra?'Active':'Maintenance'?></span></td>
      <td><form method="POST" id="tr_<?=$r['id']?>"><?= csrfField() ?><input type="hidden" name="action" value="toggle_room"><input type="hidden" name="id" value="<?=$r['id']?>"></form>
        <div style="display:flex;gap:4px"><button class="btn btn-o btn-sm" onclick="openRoomEdit({id:<?=$r['id']?>, rnum:'<?=addslashes($r['room_number'])?>', rname:'<?=addslashes($r['room_name'])?>', rtype:'<?=$r['room_type']?>', cap:<?=$r['capacity']?>, proj:<?=$r['has_projector']?1:0?>, ac:<?=$r['has_ac']?1:0?>, desc:'<?=addslashes($r['description']??'')?>', fac:'<?=$r['faculty_id']?>'})"><i class="fas fa-edit"></i></button>
        <?php if($ra):?><button class="btn btn-w btn-sm" onclick="confirm2('','tr_<?=$r['id']?>','Mark Maintenance?','')"><i class="fas fa-tools"></i></button><?php else:?><button class="btn btn-s btn-sm" onclick="confirm2('g','tr_<?=$r['id']?>','Activate?','')"><i class="fas fa-check"></i></button><?php endif;?></div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- AUDIT LOG -->
  <section class="sec" id="sec-audit">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-shield-alt"></i> Administrator Audit Log</h3></div><div class="tw"><table><thead><tr><th>Admin</th><th>Action</th><th>Entity</th><th>Details</th><th>IP</th><th>Time</th></tr></thead><tbody>
    <?php foreach($audit_log as $log): ?>
    <tr><td><?=htmlspecialchars($log['admin_name'])?></td><td><?=htmlspecialchars($log['action'])?></td><td><?=htmlspecialchars($log['entity_type'])?> #<?=$log['entity_id']?></td>
    <td style="font-size:.75rem">Old: <?=htmlspecialchars($log['old_value'])?><br>New: <?=htmlspecialchars($log['new_value'])?></td>
    <td><?=htmlspecialchars($log['ip_address'])?></td><td><?=date('d M H:i',strtotime($log['created_at']))?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- ACTIVITY LOG -->
  <section class="sec" id="sec-actlog">
    <div class="card"><div class="card-hd"><h3><i class="fas fa-stream"></i> User Activity Log</h3></div><div class="tw"><table><thead><tr><th>User</th><th>Action</th><th>Room</th><th>IP</th><th>Time</th></tr></thead><tbody>
    <?php foreach($activity_log as $log): ?>
    <tr><td><?=htmlspecialchars($log['full_name'])?> (<?=ucfirst($log['user_type'])?>)</td><td><?=htmlspecialchars($log['action'])?></td><td><?=htmlspecialchars($log['room_name']??'—')?></td>
    <td><?=htmlspecialchars($log['ip_address'])?></td><td><?=date('d M H:i',strtotime($log['created_at']))?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
  </section>

  <!-- MODALS -->
  <!-- General Confirm Modal -->
  <div class="mo" id="moConfirm">
    <div class="mbox"><div class="m-icon" id="moIcon">⚠️</div><div class="m-title" id="moTitle">Confirm</div><div class="m-body" id="moBody"></div>
    <div class="m-btns"><button class="m-yes" id="moYes" onclick="doConfirm()">Yes</button><button class="m-no" onclick="closeMo('moConfirm')">Cancel</button></div></div>
    <input type="hidden" id="moFormId">
  </div>

  <!-- Cancel Schedule Modal -->
  <div class="mo" id="moCancelSch">
    <div class="mbox"><div class="m-title">Cancel Schedule <span id="csch_label"></span></div>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="cancel_schedule"><input type="hidden" name="id" id="csch_id">
      <div class="fg"><label>Reason</label><textarea name="reason" class="fc" rows="2"></textarea></div>
      <div class="m-btns"><button type="submit" class="m-yes">Cancel Class</button><button type="button" class="m-no" onclick="closeMo('moCancelSch')">Back</button></div>
    </form></div>
  </div>

  <!-- Edit Room Modal -->
  <div class="mo" id="moRoomEdit">
    <div class="mbox"><div class="m-title">Edit Room</div>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit_room"><input type="hidden" name="id" id="er_id">
      <div class="fg-grid">
        <div class="fg"><label>Room Number</label><input type="text" name="room_number" id="er_rnum" class="fc" required></div>
        <div class="fg"><label>Room Name</label><input type="text" name="room_name" id="er_rname" class="fc"></div>
        <div class="fg"><label>Type</label><select name="room_type" id="er_rtype" class="fc"><option value="lecture">Lecture</option><option value="lab">Lab</option><option value="office">Office</option></select></div>
        <div class="fg"><label>Capacity</label><input type="number" name="capacity" id="er_cap" class="fc"></div>
        <div class="fg"><label><input type="checkbox" name="has_projector" id="er_proj"> Projector</label></div>
        <div class="fg"><label><input type="checkbox" name="has_ac" id="er_ac"> AC</label></div>
        <div class="fg"><label>Description</label><input type="text" name="description" id="er_desc" class="fc"></div>
        <div class="fg"><label>Faculty</label><select name="faculty_id" id="er_fac" class="fc"><?php foreach($faculties as $f):?><option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option><?php endforeach;?></select></div>
        <div class="fg"><button type="submit" class="btn btn-p">Save</button></div>
      </div>
      <div class="m-btns"><button type="button" class="m-no" onclick="closeMo('moRoomEdit')">Cancel</button></div>
    </form></div>
  </div>

  <!-- Set Semester Dates Modal -->
  <div class="mo" id="setDatesModal">
    <div class="mbox" style="max-width:600px;"><div class="m-title">Set Semester Dates</div>
    <form method="POST" id="setDatesForm"><?= csrfField() ?><input type="hidden" name="action" value="set_semester_dates">
        <input type="hidden" name="degree_program_id" id="datesDegreeId">
        <div id="datesTable" class="tw" style="max-height:300px;overflow-y:auto;"></div>
        <div class="m-btns"><button type="submit" class="m-yes g">Save</button><button type="button" class="m-no" onclick="closeMo('setDatesModal')">Cancel</button></div>
    </form></div>
  </div>

  <!-- Add Courses Modal -->
  <div class="mo" id="addCoursesModal">
    <div class="mbox modal-inline"><div class="m-title">Add Courses for <span id="addCoursesLabel"></span></div>
    <form method="POST" id="addCoursesForm"><?= csrfField() ?><input type="hidden" name="action" value="add_courses_ls">
        <input type="hidden" name="degree_program_id" id="coursesDegreeId">
        <input type="hidden" name="level" id="coursesLevel">
        <input type="hidden" name="semester" id="coursesSemester">
        <div id="coursesContainer"></div>
        <button type="button" class="btn btn-o btn-sm" onclick="addCourseRow()">+ Add Another Course</button>
        <div class="m-btns" style="margin-top:15px"><button type="submit" class="m-yes g">Save Courses</button><button type="button" class="m-no" onclick="closeMo('addCoursesModal')">Cancel</button></div>
    </form></div>
  </div>

<script>
const DEGREES = <?= $degreesJson ?>;
const FLOORS  = <?= $floorsJson ?>;
const BUILDINGS = <?= json_encode($buildings, JSON_UNESCAPED_UNICODE) ?>;
const TITLES = {
    dashboard:'Dashboard', pending:'Pending', users:'Users', notifications:'Notifications',
    faculties:'Faculties', departments:'Departments', degrees:'Programs', schedules:'Schedules',
    buildings:'Buildings', rooms:'Rooms', audit:'Audit Log', actlog:'Activity Log'
};

function showSec(id) {
  document.querySelectorAll('.sec').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('sec-'+id).classList.add('active');
  const nav = document.querySelector('[onclick="showSec(\''+id+'\')"]');
  if(nav) nav.classList.add('active');
  document.getElementById('pageTitle').innerHTML = TITLES[id] + ' <span class="bn-sub">' + (TITLES[id+'Bn']||'') + '</span>';
  sessionStorage.setItem('adminSec', id);
  document.getElementById('sidebar').classList.remove('open');
}
window.addEventListener('load', ()=>{
  const saved = sessionStorage.getItem('adminSec');
  if(saved && document.getElementById('sec-'+saved)) showSec(saved);
  else showSec('dashboard');
});

function toggleED(key){ const el=document.getElementById('ed-'+key); if(!el)return; const inner=el.querySelector('.edit-row'); const isOpen=inner.classList.contains('show'); document.querySelectorAll('.edit-row.show').forEach(r=>r.classList.remove('show')); if(!isOpen)inner.classList.add('show'); }
function confirm2(type,formId,title,body){
  document.getElementById('moFormId').value=formId; document.getElementById('moTitle').textContent=title; document.getElementById('moBody').innerHTML=body;
  const yes=document.getElementById('moYes'), ico=document.getElementById('moIcon');
  if(type==='g'){ ico.textContent='✅'; yes.className='m-yes g'; yes.textContent='Yes, Proceed'; }
  else if(type==='r'){ ico.textContent='🗑️'; yes.className='m-yes'; yes.textContent='Yes, Delete'; }
  else { ico.textContent='⚠️'; yes.className='m-yes'; yes.textContent='Yes'; }
  document.getElementById('moConfirm').classList.add('on');
}
function doConfirm(){ const f=document.getElementById(document.getElementById('moFormId').value); if(f)f.submit(); }
function closeMo(id){ document.getElementById(id).classList.remove('on'); }
function openCancelSch(id,code){ document.getElementById('csch_id').value=id; document.getElementById('csch_label').textContent=code; document.getElementById('moCancelSch').classList.add('on'); }
function openRoomEdit(d){
  document.getElementById('er_id').value=d.id; document.getElementById('er_rnum').value=d.rnum; document.getElementById('er_rname').value=d.rname;
  document.getElementById('er_rtype').value=d.rtype; document.getElementById('er_cap').value=d.cap;
  document.getElementById('er_proj').checked=!!d.proj; document.getElementById('er_ac').checked=!!d.ac;
  document.getElementById('er_desc').value=d.desc||''; document.getElementById('er_fac').value=d.fac||'';
  document.getElementById('moRoomEdit').classList.add('on');
}

function loadBuildings(facultyId) {
  const bldSelect = document.getElementById('roomBuilding'); const floorSelect = document.getElementById('roomFloor');
  bldSelect.innerHTML = '<option value="">Select Building</option>'; floorSelect.innerHTML = '<option value="">Select Building first</option>'; floorSelect.disabled = true;
  if (!facultyId) { bldSelect.disabled = true; return; }
  bldSelect.disabled = false;
  BUILDINGS.filter(b => b.faculty_id == facultyId).forEach(b => { const opt = document.createElement('option'); opt.value = b.id; opt.textContent = b.name; bldSelect.appendChild(opt); });
}
function loadFloorsForRoom(buildingId) {
  const floorSelect = document.getElementById('roomFloor');
  floorSelect.innerHTML = '<option value="">Select Floor</option>';
  if (!buildingId) { floorSelect.disabled = true; return; }
  floorSelect.disabled = false;
  FLOORS.filter(f => f.building_id == buildingId).forEach(f => { const opt = document.createElement('option'); opt.value = f.id; opt.textContent = f.floor_number == 0 ? 'Ground Floor' : 'Floor '+f.floor_number; floorSelect.appendChild(opt); });
}

function filterSchedules() {
  const degree = document.getElementById('filterSchDegree').value; const teacher = document.getElementById('filterSchTeacher').value; const status = document.getElementById('filterSchStatus').value;
  document.querySelectorAll('#schTbl tbody tr').forEach(tr => { const show = (!degree || tr.dataset.degree === degree) && (!teacher || tr.dataset.teacher === teacher) && (!status || tr.dataset.status === status); tr.style.display = show ? '' : 'none'; });
}
function resetSchFilters() { document.getElementById('filterSchDegree').value = ''; document.getElementById('filterSchTeacher').value = ''; document.getElementById('filterSchStatus').value = ''; filterSchedules(); }
function filterTbl(input,tblId){ const q=input.value.toLowerCase(), tbl=document.getElementById(tblId); if(!tbl)return; tbl.querySelectorAll('tbody tr').forEach(tr=>{ if(tr.id&&tr.id.startsWith('ed-'))return; const s=(tr.dataset.s||tr.textContent).toLowerCase(); tr.style.display=!q||s.includes(q)?'':'none'; const next=tr.nextElementSibling; if(next&&next.id&&next.id.startsWith('ed-'))next.style.display=tr.style.display; }); }

function toggleDegreeSemesters(id) { const el = document.getElementById('semesters-'+id); el.style.display = el.style.display==='none'?'block':'none'; }
function openSetDatesModal(degId) {
    document.getElementById('datesDegreeId').value = degId;
    const sems = <?= json_encode($semestersByDegree, JSON_UNESCAPED_UNICODE) ?>[degId] || [];
    let html = '<table class="tw"><thead><tr><th>Level</th><th>Sem</th><th>Start Date</th><th>End Date</th></tr></thead><tbody>';
    sems.forEach(s => {
        html += `<tr><td>L${s.level}</td><td>S${s.semester_num}</td>
                 <td><input type="date" name="dates[${s.id}][start]" value="${s.start_date||''}" class="fc"></td>
                 <td><input type="date" name="dates[${s.id}][end]" value="${s.end_date||''}" class="fc"></td></tr>`;
    });
    html += '</tbody></table>';
    document.getElementById('datesTable').innerHTML = html;
    document.getElementById('setDatesModal').classList.add('on');
}
function openAddCoursesModal(degId, level, sem) {
    document.getElementById('coursesDegreeId').value = degId;
    document.getElementById('coursesLevel').value = level;
    document.getElementById('coursesSemester').value = sem;
    document.getElementById('addCoursesLabel').textContent = `Level ${level} Semester ${sem}`;
    document.getElementById('coursesContainer').innerHTML = '';
    addCourseRow();
    document.getElementById('addCoursesModal').classList.add('on');
}
function addCourseRow() {
    const container = document.getElementById('coursesContainer');
    const row = document.createElement('div'); row.className = 'inline-course-row';
    row.innerHTML = `<input type="text" name="courses[][code]" class="fc" placeholder="Course Code" required>
                     <input type="text" name="courses[][name]" class="fc" placeholder="Course Name" required>
                     <button type="button" class="btn btn-d btn-sm" onclick="this.parentElement.remove()"><i class="fas fa-trash"></i></button>`;
    container.appendChild(row);
}
function showAddFloor(buildingId) {
    let floorNum = prompt("Enter floor number (0 for ground):");
    if(floorNum !== null && floorNum !== '') {
        let form = document.createElement('form'); form.method='POST';
        let csrf = document.createElement('input'); csrf.type='hidden'; csrf.name='csrf_token'; csrf.value='<?= $_SESSION['csrf_token'] ?? '' ?>';
        let act = document.createElement('input'); act.type='hidden'; act.name='action'; act.value='add_floor';
        let bid = document.createElement('input'); bid.type='hidden'; bid.name='building_id'; bid.value=buildingId;
        let fn = document.createElement('input'); fn.type='hidden'; fn.name='floor_number'; fn.value=floorNum;
        form.appendChild(csrf); form.appendChild(act); form.appendChild(bid); form.appendChild(fn);
        document.body.appendChild(form); form.submit();
    }
}
</script>
</body>
</html>