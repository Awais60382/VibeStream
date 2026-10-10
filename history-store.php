<?php
/**
 * Watch history store.
 *
 * - vs_history_record()  : call when a video / short is opened (watch.php, shorts.php)
 * - vs_history_fetch()   : newest-first list of videos the user watched (history.php)
 * - vs_history_remove()  : remove one video from the history
 * - vs_history_clear()   : wipe the whole history
 *
 * The table is created automatically the first time it is needed.
 */

require_once __DIR__ . '/subscription-store.php';

function vs_history_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `watch_history` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `video_id` VARCHAR(64) NOT NULL,
            `watched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_watch_history_user_video` (`user_id`, `video_id`),
            KEY `idx_watch_history_user_time` (`user_id`, `watched_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

function vs_history_user_id(mysqli $conn): int
{
    $id = vs_subscription_user_id();
    if ($id > 0) {
        return $id;
    }
    $username = trim((string)($_SESSION['username'] ?? ''));
    if ($username === '') {
        return 0;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $username);
    if ($stmt->execute()) {
        $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    }
    $stmt->close();
    if ($id > 0) {
        $_SESSION['user_id'] = $id;
    }
    return max($id, 0);
}

function vs_history_record(mysqli $conn, string $videoId): void
{
    $videoId = trim($videoId);
    if ($videoId === '' || $videoId === '0' || strlen($videoId) > 64) {
        return;
    }
    $userId = vs_history_user_id($conn);
    if ($userId <= 0) {
        return;
    }
    vs_history_ensure_table($conn);
    $stmt = $conn->prepare(
        'INSERT INTO watch_history (user_id, video_id, watched_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE watched_at = NOW()'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('is', $userId, $videoId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array<int, array{video: array, watched_ts: int}>
 */
function vs_history_fetch(mysqli $conn, int $userId, string $search = '', int $limit = 200): array
{
    vs_history_ensure_table($conn);

    $stmt = $conn->prepare(
        'SELECT video_id, UNIX_TIMESTAMP(watched_at) AS ts
         FROM watch_history WHERE user_id = ?
         ORDER BY watched_at DESC, id DESC LIMIT 500'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not load your watch history.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $order = [];
    while ($r = $res->fetch_assoc()) {
        $order[] = ['id' => (string)$r['video_id'], 'ts' => (int)$r['ts']];
    }
    $stmt->close();
    if (!$order) {
        return [];
    }

    // Separate query (no JOIN) so differing table collations can never break it.
    $ids = array_values(array_unique(array_column($order, 'id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $q = $conn->prepare("SELECT * FROM videos WHERE id IN ($placeholders)");
    if (!$q) {
        throw new RuntimeException('Could not load the videos in your history.');
    }
    $q->bind_param(str_repeat('s', count($ids)), ...$ids);
    $q->execute();
    $res = $q->get_result();
    $videos = [];
    while ($row = $res->fetch_assoc()) {
        $videos[(string)$row['id']] = $row;
    }
    $q->close();

    $needle = mb_strtolower(trim($search));
    $out = [];
    foreach ($order as $o) {
        $row = $videos[$o['id']] ?? null;
        if (!$row) {
            continue; // video was deleted
        }
        if ($needle !== '') {
            $hay = mb_strtolower(
                (string)($row['title'] ?? '') . ' ' . (string)($row['channel_name'] ?? '') . ' ' . (string)($row['username'] ?? '')
            );
            if (mb_strpos($hay, $needle) === false) {
                continue;
            }
        }
        $out[] = ['video' => $row, 'watched_ts' => $o['ts']];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function vs_history_remove(mysqli $conn, int $userId, string $videoId): void
{
    vs_history_ensure_table($conn);
    $stmt = $conn->prepare('DELETE FROM watch_history WHERE user_id = ? AND video_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not update your watch history.');
    }
    $stmt->bind_param('is', $userId, $videoId);
    $stmt->execute();
    $stmt->close();
}

function vs_history_clear(mysqli $conn, int $userId): void
{
    vs_history_ensure_table($conn);
    $stmt = $conn->prepare('DELETE FROM watch_history WHERE user_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not clear your watch history.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
