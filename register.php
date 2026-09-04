<?php
require_once 'db_config.php';

// Redirect logged-in users
if (isset($_SESSION['user_id'])) {
    $redirect = $_SESSION['user_type'] . '_dashboard.php';
    if (in_array($_SESSION['user_type'], ['super_admin', 'faculty_dean'])) {
        $redirect = 'admin_dashboard.php';
    }
    header('Location: ' . $redirect);
    exit();
}

// ------------------------------------------------------------
// AJAX endpoint: check username availability
// ------------------------------------------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_username') {
    header('Content-Type: application/json');
    $username = trim($_GET['username'] ?? '');
    if (strlen($username) < 4) {
        echo json_encode(['available' => false, 'message' => 'At least 4 characters']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $exists = $stmt->fetch();
    echo json_encode(['available' => !$exists, 'message' => $exists ? 'Username already taken' : 'Username available']);
    exit;
}

$success = '';
$error = '';

// Fetch degree programs for CR dropdown (with extra fields for JS)
$degree_programs = $pdo->query(
    "SELECT id, name, code, total_levels, semesters_per_level FROM degree_programs WHERE status = 'active' ORDER BY name"
)->fetchAll();

// Preload degree programs into a JSON array for the client
$degrees_json = json_encode($degree_programs);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['user_type'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_pw = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Basic validation
    if (!in_array($type, ['teacher', 'cr'])) {
        $error = 'Please select a valid account type.';
    } elseif (empty($username) || empty($password) || empty($full_name)) {
        $error = 'Full Name, Username, and Password are required.';
    } elseif (strlen($username) < 4) {
        $error = 'Username must be at least 4 characters.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm_pw) {
        $error = 'Passwords do not match.';
    } // ----- Server-side phone & email validation -----
    elseif ($phone !== '' && !preg_match('/^01\d{9}$/', $phone)) {
        $error = 'Phone number must be exactly 11 digits and start with 01.';
    } elseif ($type === 'teacher') {
        if (empty($email)) {
            $error = 'Institutional Email is required for teachers.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || substr($email, -11) !== '@bau.edu.bd') {
            $error = 'Teacher email must be a valid @bau.edu.bd address.';
        }
    }
    // ------------------------------------------------
    else {
        // Check username uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $chk->execute([$username]);
        if ($chk->fetch()) {
            $error = 'This username is already taken. Please choose another.';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            if ($type === 'teacher') {
                $teacher_id = trim($_POST['teacher_id'] ?? '');
                if (empty($teacher_id)) {
                    $error = 'Teacher ID is required.';
                    goto done;
                }
                // Insert (no email_token, email_verified)
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, user_type, email, phone, teacher_id, is_active, account_status, created_at)
                    VALUES (?, ?, ?, 'teacher', ?, ?, ?, 0, 'pending', NOW())
                ");
                $stmt->execute([$username, $hashed, $full_name, $email, $phone ?: null, $teacher_id]);
                $success = 'teacher';

            } else { // cr
                $degree_id = (int) ($_POST['degree_program_id'] ?? 0);
                $level = (int) ($_POST['assigned_level'] ?? 0);
                $semester = (int) ($_POST['assigned_semester'] ?? 0);
                $group_raw = trim($_POST['group_name'] ?? '');

                // Validate group: empty, single uppercase letter, or 'All'
                $group_name = null;
                if ($group_raw !== '') {
                    if (preg_match('/^[A-Za-z]$/u', $group_raw)) {
                        $group_name = strtoupper($group_raw);
                    } elseif (strcasecmp($group_raw, 'All') === 0) {
                        $group_name = 'All';
                    } else {
                        $error = 'Group must be a single letter (A–Z, case‑insensitive) or "All".';
                        goto done;
                    }
                }

                if (!$degree_id || !$level || !$semester) {
                    $error = 'Please select Degree Program, Level, and Semester.';
                    goto done;
                }

                $stmt = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, user_type, email, phone, 
                                       degree_program_id, assigned_level, assigned_semester, group_name,
                                       is_active, account_status, created_at)
                    VALUES (?, ?, ?, 'cr', ?, ?, ?, ?, ?, ?, 0, 'pending', NOW())
                ");
                $stmt->execute([
                    $username,
                    $hashed,
                    $full_name,
                    $email ?: null,
                    $phone ?: null,
                    $degree_id,
                    $level,
                    $semester,
                    $group_name
                ]);
                $success = 'cr';
            }
        }
    }
    done:
    ;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request an Account — Classroom Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ---------- Base (unchanged) ---------- */
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --success: #059669;
            --danger: #dc2626;
            --warning: #d97706;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --radius: 0.625rem;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, .1), 0 2px 4px -1px rgba(0, 0, 0, .06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, .1), 0 10px 10px -5px rgba(0, 0, 0, .04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            min-height: 100vh;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 16px;
        }

        .wrap {
            width: 100%;
            max-width: 560px;
        }

        .card {
            background: white;
            border-radius: 1.25rem;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            border: 1px solid var(--gray-200);
        }

        .card-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            padding: 36px 40px;
            text-align: center;
        }

        .card-header .icon {
            width: 72px;
            height: 72px;
            background: rgba(255, 255, 255, .18);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 2rem;
        }

        .card-header h1 {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .card-header p {
            opacity: .9;
            font-size: .95rem;
        }

        .card-body {
            padding: 36px 40px;
        }

        /* type switcher */
        .type-switcher {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 28px;
        }

        .type-btn {
            padding: 14px;
            border: 2px solid var(--gray-200);
            background: var(--gray-50);
            border-radius: var(--radius);
            cursor: pointer;
            text-align: center;
            transition: all .2s;
            font-family: 'Inter', sans-serif;
        }

        .type-btn .type-icon {
            font-size: 1.8rem;
            margin-bottom: 6px;
            display: block;
        }

        .type-btn .type-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: .95rem;
        }

        .type-btn .type-sub {
            font-size: .78rem;
            color: var(--gray-400);
        }

        .type-btn:hover {
            border-color: var(--primary);
            background: #eff6ff;
        }

        .type-btn.active {
            border-color: var(--primary);
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .15);
        }

        .type-btn.active .type-label {
            color: var(--primary);
        }

        /* forms */
        .form-section {
            display: none;
        }

        .form-section.active {
            display: block;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--gray-700);
            font-size: .9rem;
        }

        label span.req {
            color: var(--danger);
            margin-left: 2px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: .9rem;
            pointer-events: none;
        }

        input[type=text],
        input[type=email],
        input[type=password],
        input[type=number],
        select {
            width: 100%;
            padding: 12px 12px 12px 40px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: .95rem;
            font-family: 'Inter', sans-serif;
            color: var(--gray-800);
            background: var(--gray-50);
            transition: border-color .2s, box-shadow .2s;
        }

        select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }

        input:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, .12);
        }

        .hint {
            font-size: .78rem;
            color: var(--gray-400);
            margin-top: 4px;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            border-radius: var(--radius);
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            cursor: pointer;
            transition: all .25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow);
        }

        .alert {
            padding: 14px 18px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: .93rem;
        }

        .alert-danger {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
        }

        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        .alert i {
            flex-shrink: 0;
            margin-top: 2px;
        }

        .success-card {
            text-align: center;
            padding: 48px 32px;
        }

        .success-card .success-icon {
            width: 80px;
            height: 80px;
            background: #d1fae5;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2.2rem;
            color: var(--success);
        }

        .success-card h2 {
            font-size: 1.4rem;
            color: var(--gray-800);
            margin-bottom: 10px;
        }

        .success-card p {
            color: var(--gray-600);
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .success-card a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary);
            color: white;
            padding: 12px 28px;
            border-radius: var(--radius);
            text-decoration: none;
            font-weight: 600;
            transition: all .2s;
        }

        .success-card a:hover {
            background: var(--primary-dark);
        }

        .footer-links {
            text-align: center;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
            margin-top: 24px;
            font-size: .9rem;
            color: var(--gray-600);
        }

        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .footer-links a:hover {
            text-decoration: underline;
        }

        /* ---------- New validation styles ---------- */
        .is-valid {
            border-color: var(--success) !important;
            background: #f0fdf4 !important;
        }

        .is-invalid {
            border-color: var(--danger) !important;
            background: #fef2f2 !important;
        }

        .validation-msg {
            font-size: .75rem;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .validation-msg.error {
            color: var(--danger);
        }

        .validation-msg.success {
            color: var(--success);
        }

        .validation-msg.loading {
            color: var(--gray-600);
        }

        .spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid var(--gray-300);
            border-top: 2px solid var(--primary);
            border-radius: 50%;
            animation: spin .6s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        @media(max-width:520px) {
            .card-body {
                padding: 28px 24px;
            }

            .card-header {
                padding: 28px 24px;
            }

            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="card">
            <div class="card-header">
                <div class="icon"><i class="fas fa-user-plus"></i></div>
                <h1>Request an Account</h1>
                <p>Classroom Management System — BAU</p>
            </div>

            <div class="card-body">

                <?php if ($success): ?>
                    <div class="success-card">
                        <div class="success-icon"><i class="fas fa-clock"></i></div>
                        <h2>Registration Submitted!</h2>
                        <p>
                            Your account request has been received.<br>
                            Please <strong>wait for the admin to approve</strong> your account.<br>
                            You will be able to log in once approved.
                        </p>
                        <a href="login.php"><i class="fas fa-sign-in-alt"></i> Go to Login</a>
                    </div>
                <?php else: ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Type selector -->
                    <div class="type-switcher">
                        <div class="type-btn <?= (($_POST['user_type'] ?? '') === 'teacher' || !isset($_POST['user_type'])) ? 'active' : '' ?>"
                            id="btnTeacher" onclick="switchType('teacher')">
                            <span class="type-icon">👨‍🏫</span>
                            <div class="type-label">Teacher</div>
                            <div class="type-sub">Faculty / Lecturer</div>
                        </div>
                        <div class="type-btn <?= (($_POST['user_type'] ?? '') === 'cr') ? 'active' : '' ?>" id="btnCR"
                            onclick="switchType('cr')">
                            <span class="type-icon">⭐</span>
                            <div class="type-label">Class Representative</div>
                            <div class="type-sub">CR / Class Rep</div>
                        </div>
                    </div>

                    <!-- TEACHER FORM -->
                    <form method="POST" id="formTeacher"
                        class="form-section <?= (($_POST['user_type'] ?? 'teacher') !== 'cr') ? 'active' : '' ?>" novalidate>
                        <input type="hidden" name="user_type" value="teacher">

                        <div class="form-group">
                            <label>Full Name <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-user"></i>
                                <input type="text" name="full_name" placeholder="e.g. Dr. Md. Karim" required
                                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Teacher ID <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-id-badge"></i>
                                    <input type="text" name="teacher_id" placeholder="e.g. T-1042" required
                                        value="<?= htmlspecialchars($_POST['teacher_id'] ?? '') ?>">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Phone Number</label>
                                <div class="input-wrap">
                                    <i class="fas fa-phone"></i>
                                    <input type="text" name="phone" id="phoneTeacher" placeholder="01XXXXXXXXX"
                                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                </div>
                                <div class="validation-msg" id="phoneTeacherMsg"></div>
                                <div class="hint">Must be 11 digits, starting with 01</div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Institutional Email <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" id="emailTeacher" placeholder="you@bau.edu.bd" required
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                            <div class="validation-msg" id="emailTeacherMsg"></div>
                            <div class="hint">Must be a valid @bau.edu.bd address</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Username <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-at"></i>
                                    <input type="text" name="username" id="usernameTeacher" placeholder="Choose a username"
                                        required minlength="4" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                </div>
                                <div class="validation-msg" id="usernameTeacherMsg"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Password <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" name="password" placeholder="Min. 6 characters" required minlength="6">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Password <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" name="confirm_password" placeholder="Repeat password" required>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">
                            <i class="fas fa-paper-plane"></i> Submit Registration Request
                        </button>
                    </form>

                    <!-- CR FORM -->
                    <form method="POST" id="formCR"
                        class="form-section <?= (($_POST['user_type'] ?? '')) === 'cr' ? 'active' : '' ?>" novalidate>
                        <input type="hidden" name="user_type" value="cr">
                        <input type="hidden" name="assigned_level" id="assignedLevelHidden" value="<?= htmlspecialchars($_POST['assigned_level'] ?? '') ?>">
                        <input type="hidden" name="assigned_semester" id="assignedSemesterHidden" value="<?= htmlspecialchars($_POST['assigned_semester'] ?? '') ?>">

                        <div class="form-group">
                            <label>Full Name <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-user"></i>
                                <input type="text" name="full_name" placeholder="Your full name" required
                                    value="<?= htmlspecialchars($_POST['full_name'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Phone Number</label>
                                <div class="input-wrap">
                                    <i class="fas fa-phone"></i>
                                    <input type="text" name="phone" id="phoneCR" placeholder="01XXXXXXXXX"
                                        value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                                </div>
                                <div class="validation-msg" id="phoneCRMsg"></div>
                                <div class="hint">Must be 11 digits, starting with 01</div>
                            </div>
                            <div class="form-group">
                                <label>Email Address</label>
                                <div class="input-wrap">
                                    <i class="fas fa-envelope"></i>
                                    <input type="email" name="email" id="emailCR" placeholder="your@email.com"
                                        value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                                </div>
                                <div class="validation-msg" id="emailCRMsg"></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Degree Program <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-graduation-cap"></i>
                                <select name="degree_program_id" id="degreeSelect" required>
                                    <option value="">— Select your degree program —</option>
                                    <?php foreach ($degree_programs as $dp): ?>
                                        <option value="<?= $dp['id'] ?>"
                                            <?= (($_POST['degree_program_id'] ?? '') == $dp['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($dp['name']) ?> (<?= htmlspecialchars($dp['code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Level & Semester <span class="req">*</span></label>
                            <div class="input-wrap">
                                <i class="fas fa-layer-group"></i>
                                <select id="levelSemesterSelect" required disabled>
                                    <option value="">— First select a degree —</option>
                                </select>
                            </div>
                            <div class="hint" id="levelSemesterHint"></div>
                        </div>

                        <div class="form-group">
                            <label>Group</label>
                            <div class="input-wrap">
                                <i class="fas fa-users"></i>
                                <input type="text" name="group_name" id="groupInput" placeholder="A, B, C… or All"
                                    value="<?= htmlspecialchars($_POST['group_name'] ?? '') ?>">
                            </div>
                            <div class="validation-msg" id="groupMsg"></div>
                            <div class="hint">Single letter (A–Z) or "All" (case‑insensitive). Leave empty if no group.</div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Username <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-at"></i>
                                    <input type="text" name="username" id="usernameCR" placeholder="Choose a username"
                                        required minlength="4" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                                </div>
                                <div class="validation-msg" id="usernameCRMsg"></div>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Password <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" name="password" placeholder="Min. 6 characters" required minlength="6">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Confirm Password <span class="req">*</span></label>
                                <div class="input-wrap">
                                    <i class="fas fa-lock"></i>
                                    <input type="password" name="confirm_password" placeholder="Repeat password" required>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit" id="crSubmitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Registration Request
                        </button>
                    </form>

                    <div class="footer-links">
                        Already have an account? <a href="login.php">Sign in here</a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <script>
        // ---------- Global data ----------
        const degrees = <?= $degrees_json ?>;  // degree programs array

        // ---------- UI helpers ----------
        function switchType(type) {
            document.getElementById('btnTeacher').classList.toggle('active', type === 'teacher');
            document.getElementById('btnCR').classList.toggle('active', type === 'cr');
            document.getElementById('formTeacher').classList.toggle('active', type === 'teacher');
            document.getElementById('formCR').classList.toggle('active', type === 'cr');
        }

        function showValidation(fieldId, msgId, isValid, message) {
            const field = document.getElementById(fieldId);
            const msgDiv = document.getElementById(msgId);
            if (!field || !msgDiv) return;
            field.classList.remove('is-valid', 'is-invalid');
            msgDiv.innerHTML = '';
            if (message !== undefined) {
                field.classList.add(isValid ? 'is-valid' : 'is-invalid');
                msgDiv.innerHTML = message;
                msgDiv.className = 'validation-msg ' + (isValid ? 'success' : 'error');
            } else {
                // loading state: show spinner
                msgDiv.innerHTML = '<span class="spinner"></span> Checking...';
                msgDiv.className = 'validation-msg loading';
            }
        }

        // ---------- Phone validation (live) ----------
        function validatePhone(inputId, msgId) {
            const phone = document.getElementById(inputId).value.trim();
            if (phone === '') {
                showValidation(inputId, msgId, true, '');  // optional, no error
                return true; // phone is optional for CR, but for teacher it's optional too (required? not in form, but we just validate if entered)
            }
            const regex = /^01\d{9}$/;
            const valid = regex.test(phone);
            showValidation(inputId, msgId, valid,
                valid ? '<i class="fas fa-check-circle"></i> Valid phone number' : '<i class="fas fa-times-circle"></i> Must be exactly 11 digits, starting with 01');
            return valid;
        }

        // ---------- Teacher email validation ----------
        function validateTeacherEmail() {
            const email = document.getElementById('emailTeacher').value.trim();
            if (email === '') {
                showValidation('emailTeacher', 'emailTeacherMsg', false, '<i class="fas fa-times-circle"></i> Email is required');
                return false;
            }
            // basic email format
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                showValidation('emailTeacher', 'emailTeacherMsg', false, '<i class="fas fa-times-circle"></i> Invalid email format');
                return false;
            }
            const domain = email.substring(email.lastIndexOf('@'));
            const valid = domain === '@bau.edu.bd';
            showValidation('emailTeacher', 'emailTeacherMsg', valid,
                valid ? '<i class="fas fa-check-circle"></i> Institutional email OK' : '<i class="fas fa-times-circle"></i> Must be a @bau.edu.bd address');
            return valid;
        }

        // ---------- CR email validation (optional, just format) ----------
        function validateCREmail() {
            const email = document.getElementById('emailCR').value.trim();
            if (email === '') {
                showValidation('emailCR', 'emailCRMsg', true, ''); // optional, no error
                return true;
            }
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            const valid = emailRegex.test(email);
            showValidation('emailCR', 'emailCRMsg', valid,
                valid ? '<i class="fas fa-check-circle"></i> Valid email format' : '<i class="fas fa-times-circle"></i> Invalid email format');
            return valid;
        }

        // ---------- Username availability check (AJAX) ----------
        let usernameTimer;
        function checkUsername(inputId, msgId) {
            clearTimeout(usernameTimer);
            const username = document.getElementById(inputId).value.trim();
            if (username.length < 4) {
                showValidation(inputId, msgId, false, '<i class="fas fa-times-circle"></i> At least 4 characters');
                return;
            }
            showValidation(inputId, msgId, undefined); // loading
            usernameTimer = setTimeout(() => {
                fetch('register.php?ajax=check_username&username=' + encodeURIComponent(username))
                    .then(res => res.json())
                    .then(data => {
                        showValidation(inputId, msgId, data.available,
                            data.available ? '<i class="fas fa-check-circle"></i> ' + data.message : '<i class="fas fa-times-circle"></i> ' + data.message);
                    })
                    .catch(() => {
                        showValidation(inputId, msgId, false, '<i class="fas fa-times-circle"></i> Error checking username');
                    });
            }, 500);
        }

        // ---------- Group validation ----------
        document.getElementById('groupInput')?.addEventListener('input', function () {
            const val = this.value.trim().toUpperCase();
            const msgDiv = document.getElementById('groupMsg');
            if (val === '' || val === 'ALL' || /^[A-Z]$/.test(val)) {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
                msgDiv.innerHTML = '<i class="fas fa-check-circle" style="color:#059669;"></i>';
                msgDiv.className = 'validation-msg success';
            } else {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
                msgDiv.innerHTML = '<i class="fas fa-times-circle"></i> Invalid group format';
                msgDiv.className = 'validation-msg error';
            }
        });

        // ---------- Level-Semester combined dropdown ----------
        const degreeSelect = document.getElementById('degreeSelect');
        const levelSemesterSelect = document.getElementById('levelSemesterSelect');
        const levelHidden = document.getElementById('assignedLevelHidden');
        const semesterHidden = document.getElementById('assignedSemesterHidden');
        const hintDiv = document.getElementById('levelSemesterHint');

        function populateLevelSemester(degreeId) {
            levelSemesterSelect.innerHTML = '<option value="">— Select Level & Semester —</option>';
            levelHidden.value = '';
            semesterHidden.value = '';
            levelSemesterSelect.disabled = true;
            hintDiv.textContent = '';
            if (!degreeId) return;

            const degree = degrees.find(d => d.id == degreeId);
            if (!degree) return;

            const totalLevels = parseInt(degree.total_levels) || 4;
            const semPerLevel = parseInt(degree.semesters_per_level) || 2;

            for (let lvl = 1; lvl <= totalLevels; lvl++) {
                for (let sem = 1; sem <= semPerLevel; sem++) {
                    const option = document.createElement('option');
                    option.value = `${lvl}-${sem}`;
                    option.textContent = `Level ${lvl} — Semester ${sem}`;
                    levelSemesterSelect.appendChild(option);
                }
            }
            levelSemesterSelect.disabled = false;
            hintDiv.textContent = `Levels: 1–${totalLevels}, Semesters per level: ${semPerLevel}`;

            // If previously selected (from POST), set it
            const prevLevel = '<?= $_POST['assigned_level'] ?? '' ?>';
            const prevSemester = '<?= $_POST['assigned_semester'] ?? '' ?>';
            if (prevLevel && prevSemester) {
                const combValue = `${prevLevel}-${prevSemester}`;
                if (levelSemesterSelect.querySelector(`option[value="${combValue}"]`)) {
                    levelSemesterSelect.value = combValue;
                    updateHiddenLevels();
                }
            }
        }

        function updateHiddenLevels() {
            const value = levelSemesterSelect.value;
            if (value && value.includes('-')) {
                const [lvl, sem] = value.split('-');
                levelHidden.value = lvl;
                semesterHidden.value = sem;
            } else {
                levelHidden.value = '';
                semesterHidden.value = '';
            }
        }

        degreeSelect?.addEventListener('change', function () {
            populateLevelSemester(this.value);
        });
        levelSemesterSelect?.addEventListener('change', updateHiddenLevels);

        // Initialize on page load
        window.addEventListener('DOMContentLoaded', () => {
            if (degreeSelect?.value) {
                populateLevelSemester(degreeSelect.value);
            }
        });

        // ---------- Live validation bindings ----------
        document.getElementById('phoneTeacher')?.addEventListener('input', () => validatePhone('phoneTeacher', 'phoneTeacherMsg'));
        document.getElementById('phoneCR')?.addEventListener('input', () => validatePhone('phoneCR', 'phoneCRMsg'));
        document.getElementById('emailTeacher')?.addEventListener('input', validateTeacherEmail);
        document.getElementById('emailCR')?.addEventListener('input', validateCREmail);
        document.getElementById('usernameTeacher')?.addEventListener('input', () => checkUsername('usernameTeacher', 'usernameTeacherMsg'));
        document.getElementById('usernameCR')?.addEventListener('input', () => checkUsername('usernameCR', 'usernameCRMsg'));

        // ---------- Form submission prevention ----------
        function handleFormSubmit(form, type) {
            // custom client-side checks
            let valid = true;
            if (type === 'teacher') {
                // phone optional but if present must be valid
                const phone = document.getElementById('phoneTeacher').value.trim();
                if (phone !== '' && !/^01\d{9}$/.test(phone)) valid = false;
                if (!validateTeacherEmail()) valid = false;
                const usernameField = document.getElementById('usernameTeacher');
                if (usernameField.value.trim().length < 4) valid = false;
            } else if (type === 'cr') {
                if (!degreeSelect.value) valid = false;
                if (!levelSemesterSelect.value) valid = false;
                const phone = document.getElementById('phoneCR').value.trim();
                if (phone !== '' && !/^01\d{9}$/.test(phone)) valid = false;
                if (!validateCREmail()) valid = false;
                const usernameField = document.getElementById('usernameCR');
                if (usernameField.value.trim().length < 4) valid = false;
            }
            if (!valid) {
                alert('Please fix the highlighted errors before submitting.');
                return false;
            }
            return true;
        }

        document.getElementById('formTeacher')?.addEventListener('submit', function(e) {
            if (!handleFormSubmit(this, 'teacher')) e.preventDefault();
        });
        document.getElementById('formCR')?.addEventListener('submit', function(e) {
            if (!handleFormSubmit(this, 'cr')) e.preventDefault();
        });
    </script>
</body>

</html>