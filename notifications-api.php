<?php
/**
 * JSON endpoint used by the notification bell and the notifications page.
 *   GET  ?action=poll            -> unread count
 *   GET  ?action=list            -> dropdown HTML + unread count
 *   POST action=read   id=N      -> mark one as read
 *   POST action=read_all         -> mark everything as read
 *   POST action=delete id=N      -> remove one notification
 *   POST action=clear            -> remove all notifications
 *   POST action=prefs            -> save which kinds of notifications you want
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vs_api_out(array $data): void
{
    echo json_encode($data);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || empty($_SESSION['username'])) {
    vs_api_out(['success' => false, 'error' => 'Sign in required']);
}

include __DIR__ . '/connection.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/notifications-store.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    vs_api_out(['success' => false, 'error' => 'Database unavailable']);
}

try {
    vs_notifications_ensure_table($conn);
    $action = (string)($_REQUEST['action'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'poll') {
            vs_api_out(['success' => true, 'unread' => vs_notifications_unread_count($conn, $userId)]);
        }
        if ($action === 'list') {
            $items = vs_notifications_list($conn, $userId, 8);
            vs_api_out([
                'success' => true,
                'html' => vs_notification_list_html($items),
                'unread' => vs_notifications_unread_count($conn, $userId),
            ]);
        }
        vs_api_out(['success' => false, 'error' => 'Unknown action']);
    }

    $csrf = (string)($_SESSION['csrf'] ?? '');
    if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        vs_api_out(['success' => false, 'error' => 'Session expired. Refresh the page.']);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'read' && $id > 0) {
        $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'read_all') {
        $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare('DELETE FROM user_notifications WHERE id = ? AND user_id = ?');
        $stmt->bind_param('ii', $id, $userId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'clear') {
        $stmt = $conn->prepare('DELETE FROM user_notifications WHERE user_id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } elseif ($action === 'prefs') {
        vs_notification_prefs_save($conn, $userId, [
            'uploads' => !empty($_POST['uploads']),
            'comments' => !empty($_POST['comments']),
            'likes' => !empty($_POST['likes']),
            'subscribers' => !empty($_POST['subscribers']),
        ]);
    } else {
        vs_api_out(['success' => false, 'error' => 'Unknown action']);
    }

    vs_api_out(['success' => true, 'unread' => vs_notifications_unread_count($conn, $userId)]);
} catch (Throwable $error) {
    error_log('VibeStream notifications api: ' . $error->getMessage());
    vs_api_out(['success' => false, 'error' => 'Something went wrong']);
}
