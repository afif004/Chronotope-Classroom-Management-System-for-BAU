<?php
/**
 * Password Reset Module — Classroom Management System (BAU)
 *
 * Handles the forgot-password → email token → reset flow.
 * Requires: includes/auth.php, includes/email_helper.php
 */

/**
 * Initiate a password reset: generate token, store hash, send email.
 * Always returns true to prevent email enumeration.
 *
 * @param PDO    $pdo
 * @param string $email  The email entered by the user.
 * @return bool  Always true (don't reveal whether email exists).
 */
function initiatePasswordReset(PDO $pdo, string $email): bool
{
    $email = trim(strtolower($email));
    if (empty($email)) {
        return true; // silent — don't leak info
    }

    // Rate limit: max 3 reset requests per email per hour
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE identifier = ?
          AND user_agent = 'password_reset'
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() >= 3) {
        return true; // silently refuse — rate limited
    }

    // Log the attempt
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $pdo->prepare("
        INSERT INTO login_attempts (identifier, ip_address, user_agent, was_successful)
        VALUES (?, ?, 'password_reset', 0)
    ")->execute([$email, $ip]);

    // Find user
    $stmt = $pdo->prepare("
        SELECT id, full_name, email, user_type
        FROM users
        WHERE email = ?
          AND account_status = 'active'
          AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return true; // don't reveal whether email exists
    }

    // Generate token
    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

    $pdo->prepare("
        UPDATE users
        SET password_reset_token   = ?,
            password_reset_expires = ?
        WHERE id = ?
    ")->execute([$tokenHash, $expires, $user['id']]);

    // Send email
    require_once __DIR__ . '/email_helper.php';
    send_password_reset_email($user['email'], $user['full_name'], $rawToken);

    return true;
}

/**
 * Validate a reset token from a URL.
 *
 * @return array|false  User row if valid, false otherwise.
 */
function validateResetToken(PDO $pdo, string $rawToken): array|false
{
    if (empty($rawToken)) {
        return false;
    }

    $tokenHash = hash('sha256', $rawToken);

    $stmt = $pdo->prepare("
        SELECT id, full_name, email, user_type
        FROM users
        WHERE password_reset_token   = ?
          AND password_reset_expires > NOW()
        LIMIT 1
    ");
    $stmt->execute([$tokenHash]);
    $user = $stmt->fetch();

    return $user ?: false;
}

/**
 * Execute the password reset: update password, clear token, log activity.
 *
 * @return bool  true on success.
 */
function executePasswordReset(PDO $pdo, string $rawToken, string $newPassword): bool
{
    $user = validateResetToken($pdo, $rawToken);
    if (!$user) {
        return false;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo->prepare("
        UPDATE users
        SET password              = ?,
            password_reset_token  = NULL,
            password_reset_expires = NULL,
            last_password_change  = NOW(),
            failed_login_count    = 0,
            locked_until          = NULL
        WHERE id = ?
    ")->execute([$hashed, $user['id']]);

    // Clear all remember-me tokens (force re-login on all devices)
    $pdo->prepare("DELETE FROM remember_tokens WHERE user_id = ?")->execute([$user['id']]);

    // Log activity
    try {
        if (function_exists('logActivity')) {
            logActivity($pdo, $user['id'], 'password_reset', 'user', $user['id']);
        }
    } catch (\Throwable $e) {
        // non-critical
    }

    return true;
}
