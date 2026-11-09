<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/live-store.php';
require_once __DIR__ . '/youtube-api.php';
if (is_file(__DIR__ . '/vip-badge.php')) {
    require_once __DIR__ . '/vip-badge.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$pdoOk = isset($pdo) && $pdo instanceof PDO;
if (!$pdoOk) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database unavailable.']);
    exit;
}

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$username = trim((string)($_SESSION['username'] ?? $_SESSION['AdminUsername'] ?? ''));

function vs_live_require_csrf(): void
{
    $expected = (string)($_SESSION['live_csrf'] ?? '');
    $given = (string)($_POST['csrf'] ?? '');
    if ($expected === '' || $given === '' || !hash_equals($expected, $given)) {
        throw new RuntimeException('Your session expired. Refresh and try again.');
    }
}

try {
    vs_live_ensure($pdo);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($action === 'list') {
        $rows = $pdo->query(
            "SELECT id, username, title, description, stream_url, thumbnail_url, status, scheduled_at,
                    started_at, viewer_count
             FROM live_streams
             WHERE status IN ('live', 'scheduled')
             ORDER BY status = 'live' DESC, started_at DESC, scheduled_at ASC
             LIMIT 50"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['ok' => true, 'streams' => $rows]);
        exit;
    }

    if ($action === 'heartbeat') {
        $streamId = (int)($_GET['stream_id'] ?? 0);
        if ($streamId <= 0) {
            throw new RuntimeException('Stream not found.');
        }
        $hbStatus = $pdo->prepare('SELECT status FROM live_streams WHERE id = ? LIMIT 1');
        $hbStatus->execute([$streamId]);
        $hbState = $hbStatus->fetchColumn();
        if (!$hbState) {
            throw new RuntimeException('Stream not found.');
        }
        if ($hbState !== 'live') {
            echo json_encode(['ok' => true, 'viewer_count' => 0, 'status' => (string)$hbState]);
            exit;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (empty($_COOKIE['vs_live_viewer'])) {
            setcookie('vs_live_viewer', bin2hex(random_bytes(32)), 0, '/', '', false, true);
            $_COOKIE['vs_live_viewer'] = hash('sha256', session_id() . microtime(true) . random_bytes(16));
        }
        $key = hash('sha256', (string)$_COOKIE['vs_live_viewer']);
        $pdo->prepare(
            'INSERT INTO live_viewer_sessions (stream_id, session_key, username, last_seen)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen = NOW()'
        )->execute([$streamId, $key, $username]);
        $pdo->exec("DELETE FROM live_viewer_sessions WHERE last_seen < DATE_SUB(NOW(), INTERVAL 90 SECOND)");
        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM live_viewer_sessions WHERE stream_id = ? AND last_seen >= DATE_SUB(NOW(), INTERVAL 90 SECOND)'
        );
        $countStmt->execute([$streamId]);
        $count = (int)$countStmt->fetchColumn();
        $pdo->prepare('UPDATE live_streams SET viewer_count = ? WHERE id = ?')->execute([$count, $streamId]);
        echo json_encode(['ok' => true, 'viewer_count' => $count, 'status' => 'live']);
        exit;
    }

    if ($action === 'chat_list') {
        $streamId = (int)($_GET['stream_id'] ?? 0);
        if ($streamId <= 0) {
            throw new RuntimeException('Stream not found.');
        }
        $streamCheck = $pdo->prepare('SELECT status FROM live_streams WHERE id = ? LIMIT 1');
        $streamCheck->execute([$streamId]);
        $chatStreamStatus = $streamCheck->fetchColumn();
        if (!$chatStreamStatus) {
            throw new RuntimeException('Stream not found.');
        }
        $stmt = $pdo->prepare(
            'SELECT username, message, created_at FROM live_chat_messages
             WHERE stream_id = ? ORDER BY id DESC LIMIT 100'
        );
        $stmt->execute([$streamId]);
        $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
        // Premium members and admins get a VIP flag so the chat can show their badge.
        foreach ($messages as &$chatRow) {
            $chatRow['vip'] = function_exists('vs_vip_is') ? vs_vip_is((string)$chatRow['username'], $pdo) : false;
        }
        unset($chatRow);
        echo json_encode(['ok' => true, 'messages' => $messages, 'status' => (string)$chatStreamStatus]);
        exit;
    }

    if ($action === 'chat_send') {
        vs_live_require_csrf();
        if ($username === '') {
            throw new RuntimeException('Sign in to chat.');
        }
        $streamId = (int)($_POST['stream_id'] ?? 0);
        $message = trim((string)($_POST['message'] ?? ''));
        $message = mb_substr($message, 0, 500);
        if ($streamId <= 0 || $message === '') {
            throw new RuntimeException('Stream and message are required.');
        }
        $check = $pdo->prepare("SELECT status FROM live_streams WHERE id = ? LIMIT 1");
        $check->execute([$streamId]);
        $streamStatus = $check->fetchColumn();
        if (!$streamStatus) {
            throw new RuntimeException('Stream not found.');
        }
        if ($streamStatus !== 'live') {
            throw new RuntimeException('Chat is only available while the stream is live.');
        }
        $pdo->prepare('INSERT INTO live_chat_messages (stream_id, username, message) VALUES (?, ?, ?)')->execute([$streamId, $username, $message]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($username === '') {
        throw new RuntimeException('Sign in to manage a live stream.');
    }

    if ($action === 'yt_lookup') {
        // Read-only: fetch title, description and thumbnail for a pasted YouTube link (key stays on the server).
        $videoId = vs_yt_video_id((string)($_GET['url'] ?? ''));
        if ($videoId === '') {
            throw new RuntimeException('Paste a valid YouTube link first.');
        }
        $details = vs_yt_video_details($videoId, 60);
        if (!$details) {
            throw new RuntimeException('Could not load that video from YouTube. Check the link, the API key and your server internet access.');
        }
        echo json_encode(['ok' => true, 'video' => $details]);
        exit;
    }

    if ($action === 'save') {
        vs_live_require_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
        $description = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 1000);
        $streamUrl = trim((string)($_POST['stream_url'] ?? ''));
        $thumbnailUrl = trim((string)($_POST['thumbnail_url'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('A stream title is required.');
        }
        foreach ([$streamUrl, $thumbnailUrl] as $url) {
            if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
                throw new RuntimeException('Stream and thumbnail must be valid URLs.');
            }
        }
        $scheduled = trim((string)($_POST['scheduled_at'] ?? ''));
        $scheduledAt = $scheduled !== '' ? date('Y-m-d H:i:s', strtotime($scheduled)) : null;

        if ($id > 0) {
            $own = $pdo->prepare('SELECT username FROM live_streams WHERE id = ? LIMIT 1');
            $own->execute([$id]);
            if (strcasecmp((string)$own->fetchColumn(), $username) !== 0) {
                throw new RuntimeException('You can only edit your own streams.');
            }
            $pdo->prepare(
                'UPDATE live_streams
                 SET title = ?, description = ?, stream_url = ?, thumbnail_url = ?, scheduled_at = ?, status = CASE WHEN status IN ("live", "ended") THEN status ELSE "scheduled" END
                 WHERE id = ?'
            )->execute([$title, $description, $streamUrl, $thumbnailUrl, $scheduledAt, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO live_streams (username, title, description, stream_url, thumbnail_url, status, scheduled_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$username, $title, $description, $streamUrl, $thumbnailUrl, $scheduledAt ? 'scheduled' : 'offline', $scheduledAt]);
            $id = (int)$pdo->lastInsertId();
        }
        echo json_encode(['ok' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'go_live' || $action === 'end') {
        vs_live_require_csrf();
        $id = (int)($_POST['id'] ?? 0);
        $own = $pdo->prepare('SELECT username FROM live_streams WHERE id = ? LIMIT 1');
        $own->execute([$id]);
        if (strcasecmp((string)$own->fetchColumn(), $username) !== 0) {
            throw new RuntimeException('You can only control your own streams.');
        }
        if ($action === 'go_live') {
            $pdo->prepare("UPDATE live_streams SET status = 'live', started_at = NOW(), ended_at = NULL WHERE id = ?")->execute([$id]);
        } else {
            $pdo->prepare("UPDATE live_streams SET status = 'ended', ended_at = NOW(), viewer_count = 0 WHERE id = ?")->execute([$id]);
            $pdo->prepare('DELETE FROM live_viewer_sessions WHERE stream_id = ?')->execute([$id]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new RuntimeException('Unknown action.');
} catch (Throwable $error) {
    http_response_code(400);
    error_log('live-api.php: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'error' => $error instanceof RuntimeException ? $error->getMessage() : 'Could not complete the request.']);
}