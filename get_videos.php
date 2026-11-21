<?php
header('Content-Type: application/json');
require 'connection.php'; 

$scheduleColumn = $pdo->query("SHOW COLUMNS FROM `videos` LIKE 'scheduled_at'");
if (!$scheduleColumn) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not verify video publication status.']);
    exit;
}
if ($scheduleColumn->fetchColumn()) {
    $stmt = $pdo->prepare(
        'SELECT id, title, views FROM videos
         WHERE scheduled_at IS NULL OR scheduled_at <= ?'
    );
    $stmt->execute([date('Y-m-d H:i:s')]);
} else {
    $stmt = $pdo->query("SELECT id, title, views FROM videos");
}
$videos = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $videos[$row['id']] = [
        'title' => $row['title'],
        'views' => (int)$row['views']
    ];
}

echo json_encode($videos);
?>