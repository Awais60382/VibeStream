<?php
/**
 * Watch history store.
 *
 * - vs_history_record()  : call when a video / short is opened (watch.php, shorts.php)
 * - vs_history_fetch()   : newest-first list of videos the user watched (history.php)
 * - vs_history_remove()  : remove one video from the history
 * - vs_history_clear()   : wipe the whole history
 * - vs_history_paused()  : true when the user turned watch history off (nothing is recorded)
 * - vs_history_set_paused() : pause / resume watch history
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
    if (!$conn->query(
        'CREATE TABLE IF NOT EXISTS `watch_history` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `video_id` VARCHAR(64) NOT NULL,
            `watched_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `progress_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_watch_history_user_video` (`user_id`, `video_id`),
            KEY `idx_watch_history_user_time` (`user_id`, `watched_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    )) {
        throw new RuntimeException('Could not prepare your watch history.');
    }

    foreach ([
        'progress_seconds' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'duration_seconds' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM `watch_history` LIKE '$column'");
        if (!$check) {
            throw new RuntimeException('Could not check your watch history schema.');
        }
        $exists = $check->num_rows > 0;
        $check->free();
        if (!$exists && !$conn->query("ALTER TABLE `watch_history` ADD COLUMN `$column` $definition")) {
            throw new RuntimeException('Could not update your watch history schema.');
        }
    }
    $done = true;
}

function vs_history_ensure_prefs_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `watch_history_prefs` (
            `user_id` INT NOT NULL,
            `paused` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

/** True when this user paused their watch history. */
function vs_history_paused(mysqli $conn, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    vs_history_ensure_prefs_table($conn);
    $stmt = $conn->prepare('SELECT paused FROM watch_history_prefs WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $paused = false;
    if ($stmt->execute()) {
        $paused = (int)($stmt->get_result()->fetch_assoc()['paused'] ?? 0) === 1;
    }
    $stmt->close();
    return $paused;
}

function vs_history_set_paused(mysqli $conn, int $userId, bool $paused): void
{
    vs_history_ensure_prefs_table($conn);
    $flag = $paused ? 1 : 0;
    $stmt = $conn->prepare(
        'INSERT INTO watch_history_prefs (user_id, paused) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE paused = VALUES(paused)'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not update your watch history setting.');
    }
    $stmt->bind_param('ii', $userId, $flag);
    $stmt->execute();
    $stmt->close();
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
    if ($userId <= 0 || vs_history_paused($conn, $userId)) {
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

function vs_history_get_progress(mysqli $conn, string $videoId): int
{
    $videoId = trim($videoId);
    $userId = vs_history_user_id($conn);
    if ($videoId === '' || $userId <= 0 || vs_history_paused($conn, $userId)) {
        return 0;
    }
    vs_history_ensure_table($conn);
    $stmt = $conn->prepare(
        'SELECT progress_seconds FROM watch_history WHERE user_id = ? AND video_id = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not load your playback progress.');
    }
    $stmt->bind_param('is', $userId, $videoId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not load your playback progress.');
    }
    $progress = (int)($stmt->get_result()->fetch_assoc()['progress_seconds'] ?? 0);
    $stmt->close();
    return max(0, $progress);
}

function vs_history_save_progress(mysqli $conn, string $videoId, int $position, int $duration): void
{
    $videoId = trim($videoId);
    $userId = vs_history_user_id($conn);
    if ($videoId === '' || strlen($videoId) > 64 || $userId <= 0 || vs_history_paused($conn, $userId)) {
        return;
    }
    if ($duration <= 0 || $duration > 604800 || $position < 0) {
        throw new InvalidArgumentException('Invalid playback progress.');
    }

    vs_history_ensure_table($conn);
    $position = min($position, $duration);
    if ($position >= max(0, $duration - 5)) {
        $position = 0;
    }

    $stmt = $conn->prepare(
        'INSERT INTO watch_history (user_id, video_id, watched_at, progress_seconds, duration_seconds)
         VALUES (?, ?, NOW(), ?, ?)
         ON DUPLICATE KEY UPDATE progress_seconds = VALUES(progress_seconds), duration_seconds = VALUES(duration_seconds)'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not save your playback progress.');
    }
    $stmt->bind_param('isii', $userId, $videoId, $position, $duration);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not save your playback progress.');
    }
    $stmt->close();
}

/**
 * @return array<int, array{video: array, watched_ts: int, progress_seconds: int, duration_seconds: int}>
 */
function vs_history_continue_fetch(mysqli $conn, int $userId, int $limit = 8): array
{
    vs_history_ensure_table($conn);
    $limit = max(1, min(24, $limit));
    $stmt = $conn->prepare(
        'SELECT video_id, UNIX_TIMESTAMP(watched_at) AS ts, progress_seconds, duration_seconds
         FROM watch_history
         WHERE user_id = ? AND progress_seconds > 0 AND duration_seconds > progress_seconds + 5
         ORDER BY watched_at DESC, id DESC LIMIT 100'
    );
    if (!$stmt) {
        throw new RuntimeException('Could not load your unfinished videos.');
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not load your unfinished videos.');
    }
    $res = $stmt->get_result();
    $order = [];
    while ($row = $res->fetch_assoc()) {
        $order[] = [
            'id' => (string)$row['video_id'],
            'ts' => (int)$row['ts'],
            'progress' => (int)$row['progress_seconds'],
            'duration' => (int)$row['duration_seconds'],
        ];
    }
    $stmt->close();
    if (!$order) {
        return [];
    }

    $ids = array_values(array_unique(array_column($order, 'id')));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $query = $conn->prepare("SELECT * FROM videos WHERE id IN ($placeholders)");
    if (!$query) {
        throw new RuntimeException('Could not load your unfinished videos.');
    }
    $query->bind_param(str_repeat('s', count($ids)), ...$ids);
    if (!$query->execute()) {
        $query->close();
        throw new RuntimeException('Could not load your unfinished videos.');
    }
    $res = $query->get_result();
    $videos = [];
    while ($row = $res->fetch_assoc()) {
        $videos[(string)$row['id']] = $row;
    }
    $query->close();

    $out = [];
    foreach ($order as $item) {
        $video = $videos[$item['id']] ?? null;
        if (!$video || (($video['type'] ?? '') === 'short')) {
            continue;
        }
        $out[] = [
            'video' => $video,
            'watched_ts' => $item['ts'],
            'progress_seconds' => $item['progress'],
            'duration_seconds' => $item['duration'],
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * @return array<int, array{video: array, watched_ts: int, progress_seconds: int, duration_seconds: int}>
 */
function vs_history_fetch(mysqli $conn, int $userId, string $search = '', int $limit = 200): array
{
    vs_history_ensure_table($conn);

    $stmt = $conn->prepare(
        'SELECT video_id, UNIX_TIMESTAMP(watched_at) AS ts, progress_seconds, duration_seconds
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
        $order[] = [
            'id' => (string)$r['video_id'],
            'ts' => (int)$r['ts'],
            'progress' => (int)$r['progress_seconds'],
            'duration' => (int)$r['duration_seconds'],
        ];
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
        $out[] = [
            'video' => $row,
            'watched_ts' => $o['ts'],
            'progress_seconds' => $o['progress'],
            'duration_seconds' => $o['duration'],
        ];
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