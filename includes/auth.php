<?php
/**
 * Authentication & Security Module — Classroom Management System (BAU)
 *
 * Centralised helpers for:
 *  - Secure session management (hardened cookie params, regeneration)
 *  - Login rate limiting  (DB-backed, 10-min lockout after 5 failures)
 *  - Email-OTP account unlock
 *  - "Remember Me" persistent tokens
 *
 * Include via db_config.php — this file does NOT start a session itself;
 * call secureSessionStart() explicitly from db_config.php.
 */

// ── SESSION HARDENING ────────────────────────────────────────────────────────

/**
 * Start a session with hardened cookie parameters.
 * Call this ONCE from db_config.php instead of raw session_start().
 */
function secureSessionStart(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // already running
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_set_cookie_params([
        'lifetime' => 0,                   // browser-session cookie
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,            // HTTPS only when available
        'httponly'  => true,                // no JS access
        'samesite'  => 'Lax',              // CSRF mitigation
    ]);

    // Reject uninitialized session IDs (prevents fixation via URL)
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '1800'); // 30-minute idle timeout

    session_start();
}

/**
 * Regenerate session ID (call on login to prevent session fixation).
 */
function regenerateSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

// ── RATE LIMITING & LOCKOUT ──────────────────────────────────────────────────

/**
 * Record a login attempt (success or failure).
 */
function recordLoginAttempt(PDO $pdo, string $identifier, bool $success): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $stmt = $pdo->prepare("
        INSERT INTO login_attempts (identifier, ip_address, user_agent, was_successful)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$identifier, $ip, $ua, $success ? 1 : 0]);

    if ($success) {
        // Reset failed counter on user row
        $pdo->prepare("UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE username = ?")
            ->execute([$identifier]);
    } else {
        // Increment failed counter
        $pdo->prepare("
            UPDATE users
            SET failed_login_count = failed_login_count + 1,
                locked_until = CASE
                    WHEN failed_login_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 10 MINUTE)
                    ELSE locked_until
                END
            WHERE username = ?
        ")->execute([$identifier]);
    }
}

/**
 * Check how many recent failed attempts exist for an identifier.
 *
 * @return int  Number of failed attempts in the last 10 minutes.
 */
function getRecentFailedAttempts(PDO $pdo, string $identifier): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE identifier = ?
          AND was_successful = 0
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ");
    $stmt->execute([$identifier]);
    return (int) $stmt->fetchColumn();
}

/**
 * Is the account currently locked?
 *
 * @return array  ['locked' => bool, 'minutes_left' => int, 'has_email' => bool]
 */
function isAccountLocked(PDO $pdo, string $username): array
{
    $stmt = $pdo->prepare("
        SELECT locked_until, email
        FROM users WHERE username = ?
    ");
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if (!$row || !$row['locked_until']) {
        return ['locked' => false, 'minutes_left' => 0, 'has_email' => false];
    }

    $lockedUntil = strtotime($row['locked_until']);
    if ($lockedUntil > time()) {
        $minutesLeft = (int) ceil(($lockedUntil - time()) / 60);
        return [
            'locked'       => true,
            'minutes_left' => $minutesLeft,
            'has_email'    => !empty($row['email']),
        ];
    }

    return ['locked' => false, 'minutes_left' => 0, 'has_email' => !empty($row['email'])];
}

// ── OTP UNLOCK ───────────────────────────────────────────────────────────────

/**
 * Generate a 6-digit OTP, store its hash, and email it to the user.
 *
 * @return bool  true if OTP was sent successfully.
 */
function generateUnlockOtp(PDO $pdo, string $username): bool
{
    $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || empty($user['email'])) {
        return false;
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otpHash = hash('sha256', $otp);

    $pdo->prepare("
        UPDATE users
        SET unlock_otp = ?, unlock_otp_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE)
        WHERE id = ?
    ")->execute([$otpHash, $user['id']]);

    // Send email
    require_once __DIR__ . '/email_helper.php';
    return send_unlock_otp_email($user['email'], $user['full_name'], $otp);
}

/**
 * Verify OTP and unlock the account.
 *
 * @return bool  true if OTP is valid and account is unlocked.
 */
function verifyUnlockOtp(PDO $pdo, string $username, string $otp): bool
{
    $otpHash = hash('sha256', $otp);

    $stmt = $pdo->prepare("
        SELECT id FROM users
        WHERE username = ?
          AND unlock_otp = ?
          AND unlock_otp_expires > NOW()
    ");
    $stmt->execute([$username, $otpHash]);

    if (!$stmt->fetch()) {
        return false;
    }

    // Clear lockout and OTP
    $pdo->prepare("
        UPDATE users
        SET failed_login_count = 0,
            locked_until       = NULL,
            unlock_otp         = NULL,
            unlock_otp_expires = NULL
        WHERE username = ?
    ")->execute([$username]);

    return true;
}

// ── REMEMBER ME ──────────────────────────────────────────────────────────────

define('REMEMBER_COOKIE_NAME', 'rms_remember');
define('REMEMBER_COOKIE_DAYS', 30);

/**
 * Create a remember-me token, store its hash in DB, and set the cookie.
 */
function createRememberToken(PDO $pdo, int $userId): void
{
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (REMEMBER_COOKIE_DAYS * 86400));

    // Remove old tokens for this user (limit to 5 active sessions)
    $pdo->prepare("
        DELETE FROM remember_tokens WHERE user_id = ?
          AND id NOT IN (
              SELECT id FROM (
                  SELECT id FROM remember_tokens WHERE user_id = ?
                  ORDER BY created_at DESC LIMIT 4
              ) AS keep
          )
    ")->execute([$userId, $userId]);

    $pdo->prepare("
        INSERT INTO remember_tokens (user_id, token_hash, expires_at)
        VALUES (?, ?, ?)
    ")->execute([$userId, $tokenHash, $expiresAt]);

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(REMEMBER_COOKIE_NAME, $rawToken, [
        'expires'  => time() + (REMEMBER_COOKIE_DAYS * 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Validate a remember-me cookie and auto-login the user.
 *
 * @return array|false  User row if valid, false otherwise.
 */
function validateRememberToken(PDO $pdo): array|false
{
    $rawToken = $_COOKIE[REMEMBER_COOKIE_NAME] ?? '';
    if (empty($rawToken)) {
        return false;
    }

    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare("
        SELECT rt.id AS token_id, rt.user_id, u.*
        FROM remember_tokens rt
        JOIN users u ON rt.user_id = u.id
        WHERE rt.token_hash = ?
          AND rt.expires_at > NOW()
          AND u.is_active = 1
          AND u.account_status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $row = $stmt->fetch();

    if (!$row) {
        // Invalid or expired — clear the cookie
        clearRememberCookie();
        return false;
    }

    // Rotate token (prevent token reuse)
    $pdo->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$row['token_id']]);
    createRememberToken($pdo, $row['user_id']);

    return $row;
}

/**
 * Clear all remember-me tokens for a user (on logout or password change).
 */
function clearRememberToken(PDO $pdo, int $userId): void
{
    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$userId]);
    clearRememberCookie();
}

/**
 * Remove the remember-me cookie from the browser.
 */
function clearRememberCookie(): void
{
    if (isset($_COOKIE[REMEMBER_COOKIE_NAME])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(REMEMBER_COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly'  => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[REMEMBER_COOKIE_NAME]);
    }
}

// ── CLEANUP ──────────────────────────────────────────────────────────────────

/**
 * Purge expired tokens and old login attempts (call from a cron or lazily).
 * Safe to call on every page load — fast indexed queries.
 */
function cleanupAuthArtifacts(PDO $pdo): void
{
    // Clean expired remember tokens
    $pdo->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");

    // Clean old login attempts (> 24 hours)
    $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
}
