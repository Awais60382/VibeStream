<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/dashboard-events.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$id = trim((string)($_POST['video_id'] ?? $_POST['id'] ?? ''));
$type = trim((string)($_POST['content_type'] ?? 'video'));
if ($id === '' || strlen($id) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid video']);
    exit;
}
if (!in_array($type, ['video', 'short'], true)) {
    $type = 'video';
}

try {
    $update = $conn->prepare('UPDATE videos SET views = COALESCE(views, 0) + 1 WHERE id = ? LIMIT 1');
    if (!$update) {
        throw new RuntimeException($conn->error);
    }
    $update->bind_param('s', $id);
    if (!$update->execute()) {
        throw new RuntimeException($update->error);
    }
    if ($update->affected_rows !== 1) {
        $update->close();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Video not found']);
        exit;
    }
    $update->close();

    $creator = '';
    $creatorStmt = $conn->prepare('SELECT COALESCE(username, channel_name, \'\') AS creator FROM videos WHERE id = ? LIMIT 1');
    if ($creatorStmt) {
        $creatorStmt->bind_param('s', $id);
        if ($creatorStmt->execute()) {
            $row = $creatorStmt->get_result()->fetch_assoc();
            $creator = (string)($row['creator'] ?? '');
        }
        $creatorStmt->close();
    }
    vs_dashboard_log_view($conn, $type, $id, $creator);

    $count = $conn->prepare('SELECT COALESCE(views, 0) AS views FROM videos WHERE id = ? LIMIT 1');
    $count->bind_param('s', $id);
    $count->execute();
    $row = $count->get_result()->fetch_assoc();
    $count->close();

    echo json_encode(['success' => true, 'views' => (int)($row['views'] ?? 0)]);
} catch (Throwable $error) {
    error_log('View count API failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not record view']);
}
