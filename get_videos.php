<?php
header('Content-Type: application/json');
require 'connection.php'; 

$stmt = $pdo->query("SELECT id, title, views FROM videos");
$videos = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $videos[$row['id']] = [
        'title' => $row['title'],
        'views' => (int)$row['views']
    ];
}

echo json_encode($videos);
?>