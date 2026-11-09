<?php
/*
 * VibeStream admin panel - work-time ping.
 * The admin pages call this every minute while the tab is open and the admin is active,
 * so work time keeps counting even when they stay on one page (checkin-lib.php only counted on page loads).
 * Put this file in the adminpanel folder next to checkin-lib.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_SESSION['AdminSession'])) {
    http_response_code(204);
    exit;
}
require_once __DIR__ . '/checkin-lib.php';

$name = vs_checkin_admin_name();
if ($name) {
    $now  = time();
    $prev = (int)($_SESSION['vs_touch_at'] ?? 0);
    // After a long quiet gap count only one minute for this ping, never the idle time in between.
    if ($prev > 0 && ($now - $prev) > 120) {
        $_SESSION['vs_touch_at'] = $now - 60;
    }
    vs_checkin_touch($name);
}
session_write_close();
http_response_code(204);
