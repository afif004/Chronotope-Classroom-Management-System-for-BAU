<?php
require_once 'db_config.php';



$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password';
    } else {
        // First check if account exists but is pending approval
        $stmtCheck = $pdo->prepare("SELECT account_status FROM users WHERE username = ?");
        $stmtCheck->execute([$username]);
        $checkUser = $stmtCheck->fetch();

        if ($checkUser && $checkUser['account_status'] === 'pending') {
            $error = 'PENDING';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 AND account_status = 'active'");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['email']     = $user['email'];

                if ($user['user_type'] == 'cr') {
                    $_SESSION['degree_program_id']  = $user['degree_program_id'];
                    $_SESSION['assigned_level']     = $user['assigned_level'];
                    $_SESSION['assigned_semester']  = $user['assigned_semester'];
                    $_SESSION['batch']              = $user['batch'];
                }

                logActivity($pdo, $user['id'], 'user_login', 'user', $user['id']);
                // Map admin-type roles to the single admin dashboard
                if (in_array($user['user_type'], ['super_admin', 'faculty_dean'])) {
                    $redirect = 'admin_dashboard.php';
                } else {
                    $redirect = $user['user_type'] . '_dashboard.php';
                }
                header('Location: ' . $redirect);
                exit();
            } else {
                $error = 'Invalid username or password';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Classroom Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <style>
        :root {
            --primary: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #3b82f6;
            --secondary: #64748b;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --transition: all 0.2s ease-in-out;
            --transition-slow: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            padding: 20px;
            position: relative;
            overflow-x: hidden;
        }

        /* Background decorative elements */
        .bg-decoration {
            position: absolute;
            width: 100%;
            height: 100%;
            z-index: 1;
            overflow: hidden;
        }

        .circle {
            position: absolute;
            border-radius: 50%;
            background: linear-gradient(135deg, rgba(37, 99, 235, 0.05) 0%, rgba(37, 99, 235, 0.02) 100%);
        }

        .circle-1 {
            width: 300px;
            height: 300px;
            top: -150px;
            right: -150px;
            animation: float 20s infinite ease-in-out;
        }

        .circle-2 {
            width: 200px;
            height: 200px;
            bottom: -100px;
            left: -100px;
            animation: float 25s infinite ease-in-out reverse;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(30px, -30px) rotate(120deg); }
            66% { transform: translate(-20px, 20px) rotate(240deg); }
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            z-index: 10;
            animation: slideIn 0.6s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-card {
            background: white;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            transition: var(--transition-slow);
            border: 1px solid var(--gray-200);
        }

        .login-card:hover {
            box-shadow: var(--shadow-lg), 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .login-header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 40px 32px;
            text-align: center;
            position: relative;
        }

        .login-header::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.5), transparent);
            animation: shimmer 3s infinite linear;
        }

        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .logo-container {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(255, 255, 255, 0.4); }
            50% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(255, 255, 255, 0); }
        }

        .logo-container i {
            font-size: 2.5rem;
        }

        .login-header h1 {
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .login-header p {
            opacity: 0.9;
            font-size: 1rem;
            font-weight: 400;
        }

        .login-form {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .input-container {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            transition: var(--transition);
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            color: var(--gray-800);
            transition: var(--transition);
            background: var(--gray-50);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
        }

        .form-control:focus + .input-icon {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            transition: var(--transition);
            padding: 4px;
            border-radius: var(--radius-sm);
        }

        .password-toggle:hover {
            color: var(--gray-600);
            background: var(--gray-100);
        }

        .error-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 16px;
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.05) 0%, rgba(239, 68, 68, 0.02) 100%);
            border: 1px solid rgba(239, 68, 68, 0.2);
            border-radius: var(--radius);
            margin-bottom: 24px;
            color: var(--danger);
            font-size: 0.95rem;
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-2px); }
            40%, 60% { transform: translateX(2px); }
        }

        .error-message i {
            font-size: 1.2rem;
        }

        .btn {
            width: 100%;
            padding: 16px;
            font-size: 1rem;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: var(--radius);
            cursor: pointer;
            transition: var(--transition-slow);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .loader {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .additional-links {
            display: flex;
            justify-content: space-between;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--gray-200);
            font-size: 0.9rem;
        }

        .link {
            color: var(--gray-600);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }

        .link:hover {
            color: var(--primary);
            gap: 8px;
        }

        .user-type-info {
            margin-top: 24px;
            padding: 16px;
            background: var(--gray-50);
            border-radius: var(--radius);
            border: 1px solid var(--gray-200);
        }

        .user-type-info h3 {
            font-size: 0.95rem;
            color: var(--gray-700);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .user-types {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .user-type-badge {
            padding: 6px 12px;
            background: white;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            color: var(--gray-700);
            transition: var(--transition);
        }

        .user-type-badge:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }

        .back-link {
            text-align: center;
            margin-top: 32px;
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
            padding: 8px 16px;
            border-radius: var(--radius);
        }

        .back-link a:hover {
            background: var(--gray-100);
            gap: 12px;
        }

        /* Responsive Design */
        @media (max-width: 640px) {
            .login-container {
                max-width: 100%;
            }
            
            .login-form {
                padding: 32px 24px;
            }
            
            .login-header {
                padding: 32px 24px;
            }
            
            .logo-container {
                width: 70px;
                height: 70px;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
            
            .additional-links {
                flex-direction: column;
                gap: 16px;
            }
        }

        /* Focus styles for accessibility */
        .form-control:focus-visible,
        .btn:focus-visible,
        .link:focus-visible {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }

        /* Success animation for form submission */
        @keyframes success {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .success-animation {
            animation: success 0.5s ease;
        }

        /* Loading bar animation */
        .loading-bar {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--primary-light));
            transition: width 0.3s ease;
        }
    </style>
</head>

<body>
    <!-- Background decorations -->
    <div class="bg-decoration">
        <div class="circle circle-1"></div>
        <div class="circle circle-2"></div>
    </div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="loading-bar" id="loadingBar"></div>
            
            <div class="login-header">
                <div class="logo-container">
                    <i class="fas fa-graduation-cap"></i>
                </div>
                <h1>Classroom Management</h1>
                <p>Sign in to your account</p>
            </div>

            <form class="login-form" method="POST" id="loginForm">
                <?php if ($error === 'PENDING'): ?>
                    <div class="error-message" style="background:linear-gradient(135deg,rgba(217,119,6,.08),rgba(217,119,6,.03));border-color:rgba(217,119,6,.3);color:#92400e;">
                        <i class="fas fa-clock" style="color:#d97706;"></i>
                        <span>Your account is <strong>awaiting admin approval</strong>. You will be able to log in once the admin approves your request.</span>
                    </div>
                <?php elseif ($error): ?>
                    <div class="error-message">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-container">
                        <i class="fas fa-user input-icon"></i>
                        <input type="text" id="username" name="username" class="form-control" required
                            placeholder="Enter your username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                            autocomplete="username">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-container">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" class="form-control" required
                            placeholder="Enter your password"
                            autocomplete="current-password">
                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="submitBtn">
                    <span id="btnText">Sign In</span>
                    <div class="loader" id="btnLoader"></div>
                </button>

                <div class="additional-links">
                    <a href="register.php" class="link">
                        <i class="fas fa-user-plus"></i> New user? Request an account
                    </a>
                    <a href="index.php" class="link">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>

                <div class="user-type-info">
                    <h3><i class="fas fa-info-circle"></i> Available user types:</h3>
                    <div class="user-types">
                        <span class="user-type-badge">👨‍🎓 Student</span>
                        <span class="user-type-badge">👨‍🏫 Teacher</span>
                        <span class="user-type-badge">⭐ Class Rep</span>
                        <span class="user-type-badge">⚙️ Admin</span>
                    </div>
                </div>

                <div class="back-link">
                    <a href="index.php">
                        <i class="fas fa-arrow-left"></i>
                        Back to homepage
                    </a>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Password visibility toggle
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const toggleIcon = togglePassword.querySelector('i');

        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle icon
            toggleIcon.classList.toggle('fa-eye');
            toggleIcon.classList.toggle('fa-eye-slash');
            
            // Add animation to button
            this.classList.add('success-animation');
            setTimeout(() => {
                this.classList.remove('success-animation');
            }, 500);
        });

        // Form submission with loading state
        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnLoader = document.getElementById('btnLoader');
        const loadingBar = document.getElementById('loadingBar');

        loginForm.addEventListener('submit', function(e) {
            // Validate form
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!username || !password) {
                e.preventDefault();
                return;
            }
            
            // Show loading state
            submitBtn.disabled = true;
            btnText.style.opacity = '0.5';
            btnLoader.style.display = 'block';
            
            // Animate loading bar
            let width = 0;
            const interval = setInterval(() => {
                if (width >= 100) {
                    clearInterval(interval);
                } else {
                    width += 10;
                    loadingBar.style.width = width + '%';
                }
            }, 50);
            
            // Add visual feedback
            submitBtn.classList.add('success-animation');
        });

        // Input validation and focus effects
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            // Add focus/blur effects
            input.addEventListener('focus', function() {
                this.parentElement.style.transform = 'translateY(-2px)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.style.transform = 'translateY(0)';
            });
            
            // Add input validation styling
            input.addEventListener('input', function() {
                if (this.value.trim()) {
                    this.style.borderColor = 'var(--success)';
                } else {
                    this.style.borderColor = 'var(--gray-200)';
                }
            });
        });

        // Auto-focus username field on page load
        window.addEventListener('load', function() {
            document.getElementById('username').focus();
            
            // Add subtle entrance animation to form elements
            const formElements = document.querySelectorAll('.form-group, .btn, .additional-links, .user-type-info');
            formElements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    el.style.transition = 'all 0.5s ease';
                    el.style.opacity = '1';
                    el.style.transform = 'translateY(0)';
                }, 100 * index);
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + / focuses username
            if (e.ctrlKey && e.key === '/') {
                e.preventDefault();
                document.getElementById('username').focus();
            }
            
            // Escape clears form
            if (e.key === 'Escape') {
                loginForm.reset();
                inputs.forEach(input => {
                    input.style.borderColor = 'var(--gray-200)';
                });
            }
            
            // Enter submits form (standard behavior, but we'll add visual feedback)
            if (e.key === 'Enter' && (e.target.type !== 'submit')) {
                submitBtn.classList.add('success-animation');
                setTimeout(() => {
                    submitBtn.classList.remove('success-animation');
                }, 500);
            }
        });

        // Add hover effects for user type badges
        const userBadges = document.querySelectorAll('.user-type-badge');
        userBadges.forEach(badge => {
            badge.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px) scale(1.05)';
            });
            
            badge.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });c

        // Add form validation before submission
        loginForm.addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!username || !password) {
                e.preventDefault();
                
                // Highlight empty fields
                if (!username) {
                    document.getElementById('username').style.borderColor = 'var(--danger)';
                    document.getElementById('username').focus();
                }
                if (!password) {
                    document.getElementById('password').style.borderColor = 'var(--danger)';
                }
                
                // Show error message if not already shown
                if (!document.querySelector('.error-message')) {
                    const errorDiv = document.createElement('div');
                    errorDiv.className = 'error-message';
                    errorDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>Please fill in all fields</span>';
                    loginForm.prepend(errorDiv);
                }
            }
        });

        // Smooth scroll to top if there's an error
        if (document.querySelector('.error-message')) {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        }
    </script>
</body>

</html>