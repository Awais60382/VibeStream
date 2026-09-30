<?php
session_start();

ini_set('display_errors', '0');   
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

function jout($data) {
    echo json_encode($data);
    exit;
}

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Server error: ' . $e['message']]);
    }
});

$servername = "localhost";
$username   = "root";
$password   = "";
$dbname     = "vibestream";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    jout(['success' => false, 'error' => 'Connection failed']);
}
$conn->set_charset('utf8mb4');

function column_exists($conn, $table, $col) {
    static $cache = [];
    $key = "$table.$col";
    if (isset($cache[$key])) return $cache[$key];
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $cache[$key] = ($r && $r->num_rows > 0);
}

function fetch_video($conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?");
    if (!$stmt) return null;
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function counts($conn, $id) {
    $row = fetch_video($conn, $id);
    return [
        'likes'       => (int)($row['likes']       ?? 0),
        'dislikes'    => (int)($row['dislikes']    ?? 0),
        'subscribers' => (int)($row['subscribers'] ?? 0),
    ];
}

function bump($conn, $id, $col, $delta) {
    if (!column_exists($conn, 'videos', $col)) return false;
    $sql = $delta > 0
        ? "UPDATE videos SET `$col` = COALESCE(`$col`,0) + 1 WHERE id = ?"
        : "UPDATE videos SET `$col` = GREATEST(COALESCE(`$col`,0) - 1, 0) WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param("i", $id);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

$action   = $_POST['action'] ?? '';
$video_id = (int)($_POST['video_id'] ?? 0);

if ($video_id <= 0) {
    jout(['success' => false, 'error' => 'Invalid video id']);
}

$video = fetch_video($conn, $video_id);
if (!$video) {
    jout(['success' => false, 'error' => 'Video not found']);
}

$_SESSION['liked_videos']        = $_SESSION['liked_videos']        ?? [];
$_SESSION['disliked_videos']     = $_SESSION['disliked_videos']     ?? [];
$_SESSION['subscribed_channels'] = $_SESSION['subscribed_channels'] ?? [];


switch ($action) {

    case 'like': {
        if (!column_exists($conn, 'videos', 'likes')) {
            jout(['success' => false, 'error' => 'videos.likes column is missing']);
        }

        if (in_array($video_id, $_SESSION['liked_videos'])) {
            bump($conn, $video_id, 'likes', -1);
            $_SESSION['liked_videos'] = array_values(array_diff($_SESSION['liked_videos'], [$video_id]));
            $liked = false;
        } else {
            bump($conn, $video_id, 'likes', +1);
            $_SESSION['liked_videos'][] = $video_id;
            $liked = true;

            if (in_array($video_id, $_SESSION['disliked_videos'])) {
                bump($conn, $video_id, 'dislikes', -1);
                $_SESSION['disliked_videos'] = array_values(array_diff($_SESSION['disliked_videos'], [$video_id]));
            }
        }

        $c = counts($conn, $video_id);
        jout([
            'success'  => true,
            'liked'    => $liked,
            'disliked' => in_array($video_id, $_SESSION['disliked_videos']),
            'likes'    => $c['likes'],
            'dislikes' => $c['dislikes'],
        ]);
    }

    case 'dislike': {
        if (!column_exists($conn, 'videos', 'dislikes')) {
            jout(['success' => false, 'error' => 'videos.dislikes column is missing']);
        }

        if (in_array($video_id, $_SESSION['disliked_videos'])) {
            bump($conn, $video_id, 'dislikes', -1);
            $_SESSION['disliked_videos'] = array_values(array_diff($_SESSION['disliked_videos'], [$video_id]));
            $disliked = false;
        } else {
            bump($conn, $video_id, 'dislikes', +1);
            $_SESSION['disliked_videos'][] = $video_id;
            $disliked = true;

            if (in_array($video_id, $_SESSION['liked_videos'])) {
                bump($conn, $video_id, 'likes', -1);
                $_SESSION['liked_videos'] = array_values(array_diff($_SESSION['liked_videos'], [$video_id]));
            }
        }

        $c = counts($conn, $video_id);
        jout([
            'success'  => true,
            'disliked' => $disliked,
            'liked'    => in_array($video_id, $_SESSION['liked_videos']),
            'likes'    => $c['likes'],
            'dislikes' => $c['dislikes'],
        ]);
    }

    case 'subscribe': {
        if (!column_exists($conn, 'videos', 'subscribers')) {
            jout([
                'success' => false,
                'error'   => 'videos.subscribers column is missing. Run: ALTER TABLE videos ADD COLUMN subscribers INT NOT NULL DEFAULT 0;'
            ]);
        }


        $channel = trim((string)($video['channel_name'] ?? ''));
        $key     = $channel !== '' ? 'ch:' . mb_strtolower($channel) : 'id:' . $video_id;

        $isSubbed = in_array($key, $_SESSION['subscribed_channels'], true);

        if ($channel !== '') {
            $sql = $isSubbed
                ? "UPDATE videos SET subscribers = GREATEST(COALESCE(subscribers,0) - 1, 0) WHERE channel_name = ?"
                : "UPDATE videos SET subscribers = COALESCE(subscribers,0) + 1 WHERE channel_name = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $channel);
            $stmt->execute();
            $stmt->close();
        } else {
            bump($conn, $video_id, 'subscribers', $isSubbed ? -1 : +1);
        }

        if ($isSubbed) {
            $_SESSION['subscribed_channels'] =
                array_values(array_diff($_SESSION['subscribed_channels'], [$key]));
            $subscribed = false;
        } else {
            $_SESSION['subscribed_channels'][] = $key;
            $subscribed = true;
        }

        $c = counts($conn, $video_id);
        jout([
            'success'     => true,
            'subscribed'  => $subscribed,
            'subscribers' => $c['subscribers'],
            'channel'     => $channel,
        ]);
    }

    case 'comment': {
        $user         = $_SESSION['username'] ?? 'Guest';
        $comment_text = trim($_POST['comment_text'] ?? '');

        if ($comment_text === '') {
            jout(['success' => false, 'error' => 'Empty comment']);
        }

        $stmt = $conn->prepare("INSERT INTO comments (video_id, username, comment_text) VALUES (?, ?, ?)");
        if (!$stmt) {
            jout(['success' => false, 'error' => 'Could not prepare comment insert']);
        }
        $stmt->bind_param("iss", $video_id, $user, $comment_text);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM comments WHERE video_id = ?");
        $stmt->bind_param("i", $video_id);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();

        jout([
            'success'       => true,
            'username'      => htmlspecialchars($user, ENT_QUOTES, 'UTF-8'),
            'comment_text'  => htmlspecialchars($comment_text, ENT_QUOTES, 'UTF-8'),
            'comment_count' => $count,
        ]);
    }

    default:
        jout(['success' => false, 'error' => 'Unknown action: ' . $action]);
}