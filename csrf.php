<?php
/**
 * VibeStream - tiny CSRF helper.
 * Put in the main VibeStream folder. Use:
 *   <form method="post"> <?= vs_csrf_field() ?> ... </form>
 *   if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) { http_response_code(403); exit; }
 */
if (!function_exists('vs_csrf_token')) {
    function vs_csrf_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['vs_csrf']) || !is_string($_SESSION['vs_csrf'])) {
            $_SESSION['vs_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['vs_csrf'];
    }

    function vs_csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(vs_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    function vs_csrf_valid($token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return is_string($token) && $token !== ''
            && !empty($_SESSION['vs_csrf']) && is_string($_SESSION['vs_csrf'])
            && hash_equals($_SESSION['vs_csrf'], $token);
    }
}
