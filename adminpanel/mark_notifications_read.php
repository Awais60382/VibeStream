<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    http_response_code(403);
    exit('Administrator privileges required.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../notifications-store.php';

$csrf = (string)($_SESSION['admin_notif_csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('That request expired. Go back, refresh the page and try again.');
}

$target  = (string)($_POST['target'] ?? 'all');   // 'branding' = just the site notice, 'all' = everything
$userId  = (int)($_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? 0);
$readerKey = $userId > 0
    ? 'id:' . $userId
    : 'name:' . mb_strtolower(trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? '')));

// 1) Site notices (e.g. "The site logo was updated"): remember that THIS admin has read it.
try {
    $state = vs_branding_state();
    if (is_array($state['notification'] ?? null) && $readerKey !== 'name:') {
        $readBy = $state['notification']['read_by'] ?? [];
        if (!is_array($readBy)) {
            $readBy = [];
        }
        if (!in_array($readerKey, $readBy, true)) {
            $readBy[] = $readerKey;
            $state['notification']['read_by'] = array_values($readBy);
            vs_branding_save_state($state);
        }
    }
} catch (Throwable $e) {
    error_log('Could not mark the site notification as read: ' . $e->getMessage());
}

// 2) "Someone you follow uploaded" notifications for this admin account.
if ($target === 'all' && $userId > 0 && isset($conn) && $conn instanceof mysqli) {
    try {
        $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    } catch (Throwable $e) {
        error_log('Could not mark notifications as read: ' . $e->getMessage());
    }
}

// Go back to the page the admin was on (only same-folder .php pages are allowed).
$return = (string)($_POST['return'] ?? 'index.php');
$file   = basename((string)parse_url($return, PHP_URL_PATH));
if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $file) || !is_file(__DIR__ . '/' . $file)) {
    $file = 'index.php';
}
$query = (string)parse_url($return, PHP_URL_QUERY);
if ($query !== '' && !preg_match('/^[A-Za-z0-9_=&%.\-]*$/', $query)) {
    $query = '';
}
header('Location: ' . $file . ($query !== '' ? '?' . $query : ''));
exit;
