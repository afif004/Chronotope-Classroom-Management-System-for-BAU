<?php
/**
 * Email Helper — Classroom Management System (BAU)
 *
 * Wraps PHP's native mail() for simple SMTP-free sending on shared hosting.
 * If you need SMTP (e.g. Gmail, SendGrid), replace send_email() body with
 * a PHPMailer or Symfony Mailer call — the callers in register.php and
 * verify_email.php don't change at all.
 *
 * CONFIGURATION:  Set these two constants here or in db_config.php before
 * this file is included.
 */

// ── Site identity ────────────────────────────────────────────────────────────
if (!defined('SITE_NAME'))    define('SITE_NAME',    'Classroom Management System — BAU');
if (!defined('SITE_FROM'))    define('SITE_FROM',    'noreply@bau.edu.bd');  // change to your verified sender
if (!defined('SITE_BASE_URL')) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_BASE_URL', $proto . '://' . $host . BASE_URL);
}

// ── Core send function ───────────────────────────────────────────────────────
/**
 * Send an HTML email.
 *
 * @param  string $to       Recipient address
 * @param  string $subject  Email subject
 * @param  string $html     Full HTML body
 * @return bool             true on success
 */
function send_email(string $to, string $subject, string $html): bool
{
    $from    = SITE_FROM;
    $name    = SITE_NAME;
    $headers = implode("\r\n", [
        "MIME-Version: 1.0",
        "Content-type: text/html; charset=UTF-8",
        "From: {$name} <{$from}>",
        "Reply-To: {$from}",
        "X-Mailer: PHP/" . phpversion(),
    ]);

    return mail($to, $subject, $html, $headers);

    /*
     * ── PHPMailer alternative (uncomment & composer require phpmailer/phpmailer) ──
     *
     * $mail = new PHPMailer\PHPMailer\PHPMailer(true);
     * $mail->isSMTP();
     * $mail->Host       = 'smtp.gmail.com';
     * $mail->SMTPAuth   = true;
     * $mail->Username   = 'your@gmail.com';
     * $mail->Password   = 'app-password';
     * $mail->SMTPSecure = 'tls';
     * $mail->Port       = 587;
     * $mail->setFrom($from, $name);
     * $mail->addAddress($to);
     * $mail->isHTML(true);
     * $mail->Subject = $subject;
     * $mail->Body    = $html;
     * return $mail->send();
     */
}

// ── Email template wrapper ───────────────────────────────────────────────────
function email_layout(string $preheader, string $content): string
{
    $site = htmlspecialchars(SITE_NAME);
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$site}</title>
<style>
  body{margin:0;padding:0;background:#f1f5f9;font-family:Inter,Segoe UI,sans-serif;color:#1e293b}
  .wrap{max-width:560px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08)}
  .hd{background:linear-gradient(135deg,#2563eb,#1d4ed8);padding:32px;text-align:center;color:#fff}
  .hd h1{margin:0;font-size:1.3rem;font-weight:700;letter-spacing:-.01em}
  .hd p{margin:6px 0 0;font-size:.88rem;opacity:.85}
  .bd{padding:36px 40px}
  .bd p{line-height:1.65;margin:0 0 16px;font-size:.95rem;color:#334155}
  .btn{display:inline-block;background:#2563eb;color:#fff!important;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:1rem;margin:8px 0 24px}
  .info{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px 18px;font-size:.85rem;color:#475569;margin-bottom:16px}
  .ft{background:#f8fafc;padding:20px 40px;text-align:center;font-size:.78rem;color:#94a3b8;border-top:1px solid #e2e8f0}
</style>
</head>
<body>
<div style="display:none;max-height:0;overflow:hidden">{$preheader}</div>
<div class="wrap">
  <div class="hd">
    <h1>🏫 {$site}</h1>
    <p>Bangladesh Agricultural University</p>
  </div>
  <div class="bd">{$content}</div>
  <div class="ft">
    &copy; {$year} {$site} &middot; BAU, Mymensingh<br>
    This is an automated message. Please do not reply.
  </div>
</div>
</body>
</html>
HTML;
}

// ── Pre-built: Verification email ────────────────────────────────────────────
/**
 * Send an email-verification link to a newly registered user.
 *
 * @param  string $to        Recipient email
 * @param  string $full_name User's display name
 * @param  string $token     Raw 64-char hex token (stored hashed in DB)
 * @param  string $type      'teacher' or 'cr'
 * @return bool
 */
function send_verification_email(string $to, string $full_name, string $token, string $type): bool
{
    $name    = htmlspecialchars($full_name);
    $role    = $type === 'teacher' ? 'Faculty Member' : 'Class Representative';
    $link    = SITE_BASE_URL . 'verify_email.php?token=' . urlencode($token);
    $expires = '24 hours';

    $content = <<<HTML
<p>Hello <strong>{$name}</strong>,</p>
<p>Thank you for requesting a <strong>{$role}</strong> account on the Classroom Management System.</p>
<p>Please verify your email address by clicking the button below:</p>
<p style="text-align:center"><a class="btn" href="{$link}">✉️ Verify My Email</a></p>
<div class="info">
  <strong>⏱ Link expires in {$expires}.</strong><br>
  If you did not register for this system, please ignore this email.
</div>
<p>After email verification, your account will be reviewed and <strong>approved by an administrator</strong> before you can log in.</p>
HTML;

    return send_email($to, 'Verify your email — ' . SITE_NAME, email_layout('Please verify your email to continue your registration.', $content));
}

// ── Pre-built: Admin approval notification ────────────────────────────────────
/**
 * Notify a user that their account has been approved by the admin.
 *
 * @param  string $to        Recipient email
 * @param  string $full_name User's display name
 * @param  string $type      'teacher' or 'cr'
 * @return bool
 */
function send_approval_email(string $to, string $full_name, string $type): bool
{
    $name    = htmlspecialchars($full_name);
    $role    = $type === 'teacher' ? 'Faculty Member' : 'Class Representative';
    $link    = SITE_BASE_URL . 'login.php';

    $content = <<<HTML
<p>Hello <strong>{$name}</strong>,</p>
<p>Great news! Your <strong>{$role}</strong> account on the Classroom Management System has been <strong>approved</strong> by the administrator.</p>
<p>You can now log in using your registered username and password:</p>
<p style="text-align:center"><a class="btn" href="{$link}">🔑 Log In Now</a></p>
<div class="info">
  If you have forgotten your credentials, please contact the system administrator.
</div>
HTML;

    return send_email($to, 'Account Approved — ' . SITE_NAME, email_layout('Your account has been approved. You can now log in.', $content));
}

// ── Pre-built: Account rejection notification ─────────────────────────────────
/**
 * Notify a user that their account registration has been rejected.
 *
 * @param  string $to        Recipient email
 * @param  string $full_name User's display name
 * @param  string $type      'teacher' or 'cr'
 * @param  string $reason    Optional rejection reason
 * @return bool
 */
function send_rejection_email(string $to, string $full_name, string $type, string $reason = ''): bool
{
    $name    = htmlspecialchars($full_name);
    $role    = $type === 'teacher' ? 'Faculty Member' : 'Class Representative';
    $link    = SITE_BASE_URL . 'register.php';
    $reasonHtml = $reason
        ? '<div class="info"><strong>Reason:</strong> ' . htmlspecialchars($reason) . '</div>'
        : '';

    $content = <<<HTML
<p>Hello <strong>{$name}</strong>,</p>
<p>We regret to inform you that your <strong>{$role}</strong> account request on the Classroom Management System has been <strong style="color:#dc2626;">not approved</strong> by the administrator.</p>
{$reasonHtml}
<p>If you believe this was a mistake, you may contact the system administrator or submit a new registration:</p>
<p style="text-align:center"><a class="btn" href="{$link}">📝 Register Again</a></p>
<div class="info">
  If you have any questions, please reach out to the system administrator.
</div>
HTML;

    return send_email($to, 'Registration Not Approved — ' . SITE_NAME, email_layout('Your account request was not approved.', $content));
}

// ── Pre-built: OTP unlock email ───────────────────────────────────────────────
/**
 * Send a 6-digit OTP to unlock a locked account.
 *
 * @param  string $to        Recipient email
 * @param  string $full_name User's display name
 * @param  string $otp       The 6-digit OTP (plain text)
 * @return bool
 */
function send_unlock_otp_email(string $to, string $full_name, string $otp): bool
{
    $name = htmlspecialchars($full_name);

    $content = <<<HTML
<p>Hello <strong>{$name}</strong>,</p>
<p>Your account has been temporarily locked due to multiple failed login attempts. Use the following one-time code to unlock your account:</p>
<p style="text-align:center;margin:24px 0">
  <span style="display:inline-block;background:#f1f5f9;border:2px solid #e2e8f0;border-radius:12px;padding:16px 32px;font-size:2rem;font-weight:800;letter-spacing:0.3em;color:#1e293b;font-family:monospace">{$otp}</span>
</p>
<div class="info">
  <strong>⏱ This code expires in 10 minutes.</strong><br>
  If you did not request this, please ignore this email. Your account will automatically unlock after the lockout period.
</div>
HTML;

    return send_email($to, 'Unlock Your Account — ' . SITE_NAME, email_layout('Your account unlock code.', $content));
}

// ── Pre-built: Password reset email ───────────────────────────────────────────
/**
 * Send a password reset link.
 *
 * @param  string $to        Recipient email
 * @param  string $full_name User's display name
 * @param  string $token     Raw 64-char hex token
 * @return bool
 */
function send_password_reset_email(string $to, string $full_name, string $token): bool
{
    $name = htmlspecialchars($full_name);
    $link = SITE_BASE_URL . 'reset_password.php?token=' . urlencode($token);

    $content = <<<HTML
<p>Hello <strong>{$name}</strong>,</p>
<p>We received a request to reset your password for the Classroom Management System.</p>
<p>Click the button below to set a new password:</p>
<p style="text-align:center"><a class="btn" href="{$link}">🔑 Reset My Password</a></p>
<div class="info">
  <strong>⏱ This link expires in 1 hour.</strong><br>
  If you did not request a password reset, please ignore this email — your password will remain unchanged.
</div>
HTML;

    return send_email($to, 'Reset Your Password — ' . SITE_NAME, email_layout('Reset your password to continue using the system.', $content));
}
