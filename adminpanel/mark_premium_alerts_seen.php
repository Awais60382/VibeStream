<?php
/**
 * adminpanel/mark_premium_alerts_seen.php
 * Called by the bell menu's "Mark all as read": marks every "Premium ended" alert as seen,
 * so those members leave the Premium members list. Admins only.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    http_response_code(403);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || !hash_equals((string)($_SESSION['admin_notif_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../premium-store.php';

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        vs_premium_ensure($pdo);
        $ids = array_column(vs_premium_end_alerts($pdo, true, 500), 'id');
        vs_premium_end_alerts_mark_seen($pdo, $ids);
    } catch (Throwable $e) {
        error_log('mark_premium_alerts_seen failed: ' . $e->getMessage());
    }
}
http_response_code(204);
