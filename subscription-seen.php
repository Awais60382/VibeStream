<?php
/*
 * VibeStream - marks a subscribed channel as opened, so its blue "new upload" dot goes away.
 * Called by sidebar-subscriptions.php:  POST subscription-seen.php  key=<channel name>
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

require_once __DIR__ . '/connection.php';          // gives $conn (mysqli)
require_once __DIR__ . '/sidebar-subscriptions.php';

$key = trim((string)($_POST['key'] ?? ''));
if (!isset($conn) || !($conn instanceof mysqli) || $key === '' || empty($_SESSION['username'])) {
    echo '{"ok":false}';
    exit;
}

try {
    $userId = vs_sub_user_id($conn);
    session_write_close();
    vs_sub_mark_seen($conn, $userId, $key);
    echo '{"ok":true}';
} catch (Throwable $e) {
    error_log('subscription-seen failed: ' . $e->getMessage());
    echo '{"ok":false}';
}
