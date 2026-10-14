<?php
/*
 * VibeStream - search suggestions: returns ONLY videos and shorts that exist in the `videos` table.
 * Called by search-suggest.js as search-suggest.php?q=luffy  ->  JSON list.
 * search-suggest.php?q=luffy&scope=history  ->  only videos in the signed-in user's watch history.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 100);
if ($q === '') { echo '[]'; exit; }
$scope = (($_GET['scope'] ?? '') === 'history') ? 'history' : '';

require_once __DIR__ . '/connection.php';   // gives $pdo (and $conn on your site)

function vs_suggest_thumb(string $url): string
{
    if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)
        || preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $url, $m)) {
        return "https://i.ytimg.com/vi/{$m[1]}/mqdefault.jpg";
    }
    return '';
}

$out = [];

if ($scope === 'history') {
    try {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        session_write_close();
        if (isset($conn) && $conn instanceof mysqli && !empty($_SESSION['username'])) {
            require_once __DIR__ . '/history-store.php';
            $uid = vs_history_user_id($conn);
            if ($uid > 0) {
                foreach (vs_history_fetch($conn, $uid, $q, 8) as $e) {
                    $row = $e['video'];
                    $out[] = [
                        'id'    => (string)$row['id'],
                        'title' => (string)($row['title'] ?? ''),
                        'type'  => (($row['type'] ?? '') === 'short') ? 'short' : 'video',
                        'thumb' => vs_suggest_thumb((string)($row['video_url'] ?? '')),
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        error_log('search-suggest (history) failed: ' . $e->getMessage());
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// "!" is the LIKE escape character, so a typed % or _ is searched literally.
$esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
$contains = '%' . $esc . '%';
$prefix = $esc . '%';

$sql = "SELECT id, title, type, video_url
        FROM videos
        WHERE title LIKE ? ESCAPE '!' OR channel_name LIKE ? ESCAPE '!' OR username LIKE ? ESCAPE '!'
        ORDER BY (title LIKE ? ESCAPE '!') DESC, views DESC, created_at DESC
        LIMIT 8";
try {
    $st = $pdo->prepare($sql);
    $st->execute([$contains, $contains, $contains, $prefix]);
    foreach ($st->fetchAll() as $row) {
        $out[] = [
            'id'    => (string)$row['id'],
            'title' => (string)$row['title'],
            'type'  => ($row['type'] === 'short') ? 'short' : 'video',
            'thumb' => vs_suggest_thumb((string)$row['video_url']),
        ];
    }
} catch (Throwable $e) {
    error_log('search-suggest failed: ' . $e->getMessage());
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);