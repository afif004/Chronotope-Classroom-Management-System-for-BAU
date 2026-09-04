<?php
/**
 * CSRF Protection Helper — RMS
 * Include via db_config.php or at the top of any page that handles POST.
 */

/**
 * Return (and lazily create) the session-bound CSRF token.
 */
function generateCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted token against the session token.
 * Uses timing-safe comparison to prevent timing attacks.
 */
function verifyCsrfToken(string $submitted): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $stored = $_SESSION['csrf_token'] ?? '';
    if (empty($stored) || empty($submitted)) {
        return false;
    }
    return hash_equals($stored, $submitted);
}

/**
 * Emit a hidden CSRF input field. Call inside every <form>.
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}
