<?php
session_start();
require_once __DIR__ . '/liked-store.php';

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
if ($action === 'like' || $action === 'dislike') {
    try {
        vs_likes_session_sync($conn);
    } catch (Throwable $error) {
        jout(['success' => false, 'error' => 'Could not load your saved votes. Please try again.']);
    }
}


switch ($action) {

    case 'like': {
        if (!column_exists($conn, 'videos', 'likes')) {
            jout(['success' => false, 'error' => 'videos.likes column is missing']);
        }

        $likedBefore = $_SESSION['liked_videos'];
        $dislikedBefore = $_SESSION['disliked_videos'];
        $liked = vs_likes_has($conn, (string)$video_id);
        $disliked = in_array((string)$video_id, array_map('strval', $_SESSION['disliked_videos']), true);
        try {
            $conn->begin_transaction();
            if (!bump($conn, $video_id, 'likes', $liked ? -1 : +1)) {
                throw new RuntimeException('Could not update the video like count.');
            }
            vs_likes_set($conn, (string)$video_id, !$liked);
            if (!$liked && $disliked) {
                if (!bump($conn, $video_id, 'dislikes', -1)) {
                    throw new RuntimeException('Could not clear the video dislike count.');
                }
                $_SESSION['disliked_videos'] = array_values(array_diff(array_map('strval', $_SESSION['disliked_videos']), [(string)$video_id]));
            }
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            $_SESSION['liked_videos'] = $likedBefore;
            $_SESSION['disliked_videos'] = $dislikedBefore;
            jout(['success' => false, 'error' => 'Could not save your like. Please try again.']);
        }

        $c = counts($conn, $video_id);
        jout([
            'success'  => true,
            'liked'    => !$liked,
            'disliked' => in_array((string)$video_id, array_map('strval', $_SESSION['disliked_videos']), true),
            'likes'    => $c['likes'],
            'dislikes' => $c['dislikes'],
        ]);
    }

    case 'dislike': {
        if (!column_exists($conn, 'videos', 'dislikes')) {
            jout(['success' => false, 'error' => 'videos.dislikes column is missing']);
        }

        $dislikedBefore = $_SESSION['disliked_videos'];
        $likedBefore = $_SESSION['liked_videos'];
        $disliked = in_array((string)$video_id, array_map('strval', $_SESSION['disliked_videos']), true);
        $liked = vs_likes_has($conn, (string)$video_id);
        try {
            $conn->begin_transaction();
            if (!bump($conn, $video_id, 'dislikes', $disliked ? -1 : +1)) {
                throw new RuntimeException('Could not update the video dislike count.');
            }
            if (!$disliked && $liked) {
                if (!bump($conn, $video_id, 'likes', -1)) {
                    throw new RuntimeException('Could not clear the video like count.');
                }
                vs_likes_set($conn, (string)$video_id, false);
            }
            $_SESSION['disliked_videos'] = $disliked
                ? array_values(array_diff(array_map('strval', $_SESSION['disliked_videos']), [(string)$video_id]))
                : array_merge(array_map('strval', $_SESSION['disliked_videos']), [(string)$video_id]);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            $_SESSION['liked_videos'] = $likedBefore;
            $_SESSION['disliked_videos'] = $dislikedBefore;
            jout(['success' => false, 'error' => 'Could not save your dislike. Please try again.']);
        }

        $c = counts($conn, $video_id);
        jout([
            'success'  => true,
            'disliked' => !$disliked,
            'liked'    => vs_likes_has($conn, (string)$video_id),
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