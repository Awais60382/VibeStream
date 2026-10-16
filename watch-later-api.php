<?php
/*
 * VibeStream - details for the Watch later page.
 * watch-later-api.php?ids=abc,def  ->  JSON list of those videos/shorts that exist (public info only).
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$ids = [];
foreach (explode(',', (string)($_GET['ids'] ?? '')) as $raw) {
    $raw = trim($raw);
    if (preg_match('/^[A-Za-z0-9_-]{4,40}$/', $raw)) {
        $ids[$raw] = true;
    }
    if (count($ids) >= 300) {
        break;
    }
}
$ids = array_keys($ids);
if (!$ids) {
    echo '[]';
    exit;
}

require_once __DIR__ . '/connection.php';   // gives $pdo

$out = [];
try {
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, title, type, username, channel_name, views, created_at, video_url FROM videos WHERE id IN ($marks)");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $yt = '';
        if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', (string)$row['video_url'], $m)
            || preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', (string)$row['video_url'], $m)) {
            $yt = $m[1];
        }
        $channel = trim((string)($row['channel_name'] ?? ''));
        $out[] = [
            'id'      => (string)$row['id'],
            'title'   => (string)$row['title'],
            'kind'    => ($row['type'] === 'short') ? 'short' : 'video',
            'channel' => $channel !== '' ? $channel : (string)$row['username'],
            'views'   => (int)$row['views'],
            'ts'      => (int)strtotime((string)$row['created_at']),
            'thumb'   => $yt !== '' ? "https://i.ytimg.com/vi/{$yt}/mqdefault.jpg" : '',
        ];
    }
} catch (Throwable $e) {
    error_log('watch-later-api failed: ' . $e->getMessage());
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
