<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/creator-analytics-events.php';
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$id = trim((string)($_POST['video_id'] ?? ''));
$contentType = trim((string)($_POST['content_type'] ?? ''));
$eventType = trim((string)($_POST['event_type'] ?? ''));
$seconds = filter_var($_POST['seconds'] ?? 0, FILTER_VALIDATE_INT);

if ($id === '' || strlen($id) > 191 || !in_array($contentType, ['video', 'short'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid content']);
    exit;
}
if (!in_array($eventType, ['watch_time', 'share'], true)
    || ($eventType === 'watch_time' && ($seconds === false || $seconds < 1 || $seconds > 30))) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid analytics event']);
    exit;
}

try {
    $columns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM `videos`');
    while ($column = $columnResult->fetch_assoc()) {
        $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }

    $idColumn = $columns['id'] ?? '';
    $ownerColumn = '';
    foreach (['username', 'channel_name', 'creator', 'uploader', 'author', 'user'] as $candidate) {
        if (isset($columns[$candidate])) {
            $ownerColumn = $columns[$candidate];
            break;
        }
    }
    if ($idColumn === '' || $ownerColumn === '') {
        throw new RuntimeException('The videos table is missing its ID or creator field.');
    }

    $select = $conn->prepare(
        'SELECT `' . str_replace('`', '``', $ownerColumn) . '` AS creator
         FROM `videos` WHERE `' . str_replace('`', '``', $idColumn) . '` = ? LIMIT 1'
    );
    $select->bind_param('s', $id);
    $select->execute();
    $row = $select->get_result()->fetch_assoc();
    $select->close();
    $creator = trim((string)($row['creator'] ?? ''));
    if ($creator === '') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Content not found']);
        exit;
    }

    vs_creator_analytics_ensure_events_table($conn);
    $watchedSeconds = $eventType === 'watch_time' ? (int)$seconds : 0;
    $insert = $conn->prepare(
        'INSERT INTO `creator_analytics_events`
            (`content_type`, `content_id`, `creator`, `event_type`, `watched_seconds`)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->bind_param('ssssi', $contentType, $id, $creator, $eventType, $watchedSeconds);
    $insert->execute();
    $insert->close();

    echo json_encode(['success' => true]);
} catch (Throwable $error) {
    error_log('Creator analytics event could not be saved: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save analytics event']);
}
