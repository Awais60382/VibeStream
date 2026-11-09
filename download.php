<?php
/**
 * Premium download. Put this file in the SITE ROOT (next to watch.php).
 *   download.php?id=<video id>
 * Only Premium members and admins can use it. Everyone else gets a 403 message.
 */
session_start();
include_once __DIR__ . '/connection.php';
require_once __DIR__ . '/vip-badge.php';

function vs_dl_fail(int $code, string $message): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

if (!function_exists('extract_youtube_id')) {
    function extract_youtube_id($raw, $allowBare = false) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        if ($allowBare && preg_match('/^[A-Za-z0-9_-]{11}$/', $raw)) return $raw;
        if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $raw, $m)) return $m[1];
        if (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $raw, $m)) return $m[1];
        return null;
    }
}
if (!function_exists('find_source')) {
    function find_source(array $row, $baseDir = __DIR__) {
        $priority = ['video_url','video_path','video_file','video','url','file_path','filepath','file','filename','source','src','link','media_url','youtube_url','youtube_id','yt_id','video_link','embed_url','embed'];
        foreach ($priority as $col) {
            $v = trim((string)($row[$col] ?? ''));
            if ($v === '') continue;
            if (extract_youtube_id($v, true)) return $v;
            if (preg_match('#^(https?:)?//#i', $v)) return $v;
            $v = ltrim($v, '/');
            foreach ([$v, "uploads/$v", "videos/$v", "uploads/videos/$v", "media/$v"] as $p) {
                if (is_file($baseDir . '/' . $p)) return $p;
            }
            if (preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)(\?.*)?$/i', $v)) return $v;
        }
        return '';
    }
}

$user = trim((string)($_SESSION['username'] ?? ''));
if ($user === '' || !isset($conn) || !($conn instanceof mysqli) || !vs_viewer_is_vip($conn)) {
    vs_dl_fail(403, 'Downloads are a Premium feature.');
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '' || strlen($id) > 191) {
    vs_dl_fail(400, 'Missing video.');
}
$stmt = $conn->prepare('SELECT * FROM videos WHERE id = ? LIMIT 1');
$stmt->bind_param('s', $id);
$stmt->execute();
$video = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$video) {
    vs_dl_fail(404, 'Video not found.');
}

$src = find_source($video, __DIR__);
if ($src === '') {
    vs_dl_fail(404, 'This video has no file to download.');
}
if (extract_youtube_id($src, true)) {
    vs_dl_fail(422, 'This video is hosted on YouTube, so it cannot be downloaded here.');
}
if (preg_match('#^https?://#i', $src)) {
    header('Location: ' . $src);   // a file hosted somewhere else: send the member straight to it
    exit;
}

// A file on this site: it must really be inside the site folder and be a video file.
$base = realpath(__DIR__);
$path = realpath($base . '/' . ltrim(preg_replace('/\?.*$/', '', $src), '/'));
$ext  = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
$types = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogg' => 'video/ogg', 'ogv' => 'video/ogg', 'm4v' => 'video/mp4', 'mov' => 'video/quicktime'];
if (!$base || !$path || strpos($path, $base . DIRECTORY_SEPARATOR) !== 0 || !is_file($path) || !isset($types[$ext])) {
    vs_dl_fail(404, 'The video file could not be found.');
}

$name = trim(preg_replace('/[^\p{L}\p{N} _.-]+/u', '', (string)($video['title'] ?? 'video')));
$name = ($name !== '' ? mb_substr($name, 0, 80) : 'video') . '.' . $ext;

session_write_close();   // do not hold the session open while a big file is sent
set_time_limit(0);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-transform');
readfile($path);
exit;
