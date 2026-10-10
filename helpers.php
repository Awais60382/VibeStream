<?php
/**
 * VibeStream shared helpers.
 * Load with: require_once __DIR__ . '/helpers.php';
 */

if (!function_exists('vs_notification_esc')) {
    function vs_notification_esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}