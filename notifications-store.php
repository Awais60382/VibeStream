<?php
/**
 * In-app notifications.
 *
 * Types: video / short (a channel you subscribe to uploaded), comment, like,
 * subscriber (someone subscribed to you), message (admin messages).
 * Tables are created / upgraded automatically the first time they are needed.
 */

require_once __DIR__ . '/install-app.php';

if (!function_exists('vs_notification_esc')) {
function vs_notification_esc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
}

if (!function_exists('vs_notifications_ensure_table')) {
function vs_notifications_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $exists = $conn->query("SHOW TABLES LIKE 'user_notifications'");
    if ($exists && $exists->num_rows > 0) {
        $exists->free();
    } else {
        $conn->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS `user_notifications` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `user_id` INT NOT NULL,
    `type` VARCHAR(20) NOT NULL DEFAULT 'upload',
    `channel_name` VARCHAR(255) NOT NULL DEFAULT '',
    `actor` VARCHAR(255) NOT NULL DEFAULT '',
    `video_id` VARCHAR(255) NOT NULL DEFAULT '',
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `message` VARCHAR(500) NOT NULL DEFAULT '',
    `link` VARCHAR(500) NOT NULL DEFAULT '',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_notifications_user` (`user_id`, `is_read`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
    }
    // older installs: add the "actor" column (who liked / commented / subscribed)
    $col = $conn->query("SHOW COLUMNS FROM `user_notifications` LIKE 'actor'");
    if ($col) {
        if ($col->num_rows === 0) {
            $conn->query("ALTER TABLE `user_notifications` ADD COLUMN `actor` VARCHAR(255) NOT NULL DEFAULT '' AFTER `channel_name`");
        }
        $col->free();
    }
    $done = true;
}
}

/* ---------- Per-user notification settings ---------- */

if (!function_exists('vs_notification_prefs_ensure_table')) {
function vs_notification_prefs_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS `user_notification_prefs` (
    `user_id` INT NOT NULL,
    `uploads` TINYINT(1) NOT NULL DEFAULT 1,
    `comments` TINYINT(1) NOT NULL DEFAULT 1,
    `likes` TINYINT(1) NOT NULL DEFAULT 1,
    `subscribers` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $done = true;
}
}

if (!function_exists('vs_notification_prefs')) {
function vs_notification_prefs(?mysqli $conn, int $userId): array
{
    $prefs = ['uploads' => 1, 'comments' => 1, 'likes' => 1, 'subscribers' => 1];
    if (!$conn || $userId <= 0) {
        return $prefs;
    }
    try {
        vs_notification_prefs_ensure_table($conn);
        $stmt = $conn->prepare('SELECT uploads, comments, likes, subscribers FROM user_notification_prefs WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            foreach ($prefs as $key => $unused) {
                $prefs[$key] = (int)$row[$key] ? 1 : 0;
            }
        }
    } catch (Throwable $error) {
        // keep defaults
    }
    return $prefs;
}
}

if (!function_exists('vs_notification_prefs_save')) {
function vs_notification_prefs_save(mysqli $conn, int $userId, array $prefs): bool
{
    try {
        vs_notification_prefs_ensure_table($conn);
        $u = empty($prefs['uploads']) ? 0 : 1;
        $c = empty($prefs['comments']) ? 0 : 1;
        $l = empty($prefs['likes']) ? 0 : 1;
        $s = empty($prefs['subscribers']) ? 0 : 1;
        $stmt = $conn->prepare(
            'INSERT INTO user_notification_prefs (user_id, uploads, comments, likes, subscribers) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE uploads = VALUES(uploads), comments = VALUES(comments), likes = VALUES(likes), subscribers = VALUES(subscribers)'
        );
        $stmt->bind_param('iiiii', $userId, $u, $c, $l, $s);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (Throwable $error) {
        error_log('VibeStream notification settings: ' . $error->getMessage());
        return false;
    }
}
}

if (!function_exists('vs_notification_pref_group')) {
function vs_notification_pref_group(string $type): string
{
    switch ($type) {
        case 'video':
        case 'short':
            return 'uploads';
        case 'comment':
        case 'reply':
            return 'comments';
        case 'like':
        case 'comment_like':
        case 'heart':
            return 'likes';
        case 'subscriber':
            return 'subscribers';
    }
    return '';
}
}

/* ---------- Creating notifications ---------- */

/**
 * Tell everyone subscribed to $channel that a new video/short is up.
 * Never throws: a notification problem must not break an upload.
 */
if (!function_exists('vs_notify_subscribers')) {
function vs_notify_subscribers(mysqli $conn, string $channel, string $type, string $videoId, string $title): int
{
    $channel = trim($channel);
    if ($channel === '' || $videoId === '' || stripos($channel, 'guest') === 0) {
        return 0;
    }

    try {
        vs_notifications_ensure_table($conn);
        vs_notification_prefs_ensure_table($conn);

        $channelHash = hash('sha256', mb_strtolower($channel));
        $channelLower = mb_strtolower($channel);
        $stmt = $conn->prepare(
            'SELECT DISTINCT s.subscriber_id FROM user_subscriptions s
             LEFT JOIN user_notification_prefs p ON p.user_id = s.subscriber_id
             WHERE (s.channel_key = ? OR LOWER(s.channel_username) = ?) AND (p.uploads IS NULL OR p.uploads = 1)
             LIMIT 5000'
        );
        $stmt->bind_param('ss', $channelHash, $channelLower);
        $stmt->execute();
        $result = $stmt->get_result();
        $subscribers = [];
        while ($row = $result->fetch_assoc()) {
            $subscribers[] = (int)$row['subscriber_id'];
        }
        $stmt->close();

        $self = (int)($_SESSION['user_id'] ?? 0);
        $subscribers = array_values(array_filter(array_unique($subscribers), function ($id) use ($self) {
            return $id > 0 && $id !== $self;
        }));
        if (!$subscribers) {
            return 0;
        }

        $kind = $type === 'short' ? 'short' : 'video';
        $link = ($kind === 'short' ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode($videoId);
        $title = mb_substr(trim($title), 0, 200);
        $message = mb_substr($channel . ' uploaded a new ' . $kind . ($title !== '' ? ': ' . $title : ''), 0, 500);
        $channelStored = mb_substr($channel, 0, 255);
        $videoStored = mb_substr($videoId, 0, 255);

        $insert = $conn->prepare(
            'INSERT INTO user_notifications (user_id, type, channel_name, actor, video_id, title, message, link)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $sent = 0;
        foreach ($subscribers as $userId) {
            $insert->bind_param('isssssss', $userId, $kind, $channelStored, $channelStored, $videoStored, $title, $message, $link);
            $insert->execute();
            $sent++;
        }
        $insert->close();
        return $sent;
    } catch (Throwable $error) {
        error_log('VibeStream notifications: ' . $error->getMessage());
        return 0;
    }
}
}

if (!function_exists('vs_user_id_by_name')) {
function vs_user_id_by_name(mysqli $conn, string $username): int
{
    $username = trim($username);
    if ($username === '') {
        return 0;
    }
    try {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['id'] ?? 0);
    } catch (Throwable $error) {
        return 0;
    }
}
}

/**
 * Add one notification for one user, honouring their settings.
 * $dedupe: 'once' = never repeat the same actor/type/video,
 *          'fold' = replace an earlier unread one from the same actor on the same video.
 * Never throws.
 */
if (!function_exists('vs_notify_user')) {
function vs_notify_user(
    mysqli $conn,
    int $toUserId,
    string $type,
    string $actor,
    string $videoId,
    string $title,
    string $message,
    string $link,
    string $dedupe = ''
): bool {
    try {
        if ($toUserId <= 0) {
            return false;
        }
        $self = (int)($_SESSION['user_id'] ?? 0);
        if ($self > 0 && $self === $toUserId) {
            return false;
        }
        vs_notifications_ensure_table($conn);

        $group = vs_notification_pref_group($type);
        if ($group !== '') {
            $prefs = vs_notification_prefs($conn, $toUserId);
            if (empty($prefs[$group])) {
                return false;
            }
        }

        $actor = mb_substr($actor, 0, 255);
        $videoId = mb_substr($videoId, 0, 255);
        $link = mb_substr($link, 0, 500);
        $title = mb_substr($title, 0, 255);
        $message = mb_substr($message, 0, 500);
        $link = mb_substr($link, 0, 500);

        if ($dedupe === 'once') {
            $chk = $conn->prepare('SELECT 1 FROM user_notifications WHERE user_id = ? AND type = ? AND actor = ? AND video_id = ? LIMIT 1');
            $chk->bind_param('isss', $toUserId, $type, $actor, $videoId);
            $chk->execute();
            $already = (bool)$chk->get_result()->fetch_row();
            $chk->close();
            if ($already) {
                return false;
            }
        } elseif ($dedupe === 'link') {
            // never repeat the same actor/type for the same link (used for comment likes and hearts)
            $chk = $conn->prepare('SELECT 1 FROM user_notifications WHERE user_id = ? AND type = ? AND actor = ? AND link = ? LIMIT 1');
            $chk->bind_param('isss', $toUserId, $type, $actor, $link);
            $chk->execute();
            $already = (bool)$chk->get_result()->fetch_row();
            $chk->close();
            if ($already) {
                return false;
            }
        } elseif ($dedupe === 'fold') {
            $del = $conn->prepare('DELETE FROM user_notifications WHERE user_id = ? AND type = ? AND actor = ? AND video_id = ? AND is_read = 0');
            $del->bind_param('isss', $toUserId, $type, $actor, $videoId);
            $del->execute();
            $del->close();
        }

        $ins = $conn->prepare('INSERT INTO user_notifications (user_id, type, channel_name, actor, video_id, title, message, link) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->bind_param('isssssss', $toUserId, $type, $actor, $actor, $videoId, $title, $message, $link);
        $ok = $ins->execute();
        $ins->close();
        return (bool)$ok;
    } catch (Throwable $error) {
        error_log('VibeStream notify user: ' . $error->getMessage());
        return false;
    }
}
}

/** Someone liked / commented on a video or short -> tell its uploader. */
if (!function_exists('vs_notify_video_event')) {
function vs_notify_video_event(mysqli $conn, array $video, string $type, string $actor, string $text = ''): bool
{
    $owner = trim((string)($video['username'] ?? ''));
    $actor = trim($actor);
    if ($owner === '' || $actor === '' || stripos($owner, 'guest') === 0 || strcasecmp($owner, $actor) === 0) {
        return false;
    }
    $toId = vs_user_id_by_name($conn, $owner);
    if ($toId <= 0) {
        return false;
    }

    $isShort = (string)($video['type'] ?? '') === 'short';
    $kind = $isShort ? 'short' : 'video';
    $videoId = (string)($video['id'] ?? '');
    $title = mb_substr(trim((string)($video['title'] ?? '')), 0, 200);
    $link = ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode($videoId);

    if ($type === 'like') {
        $message = $actor . ' liked your ' . $kind . ($title !== '' ? ': ' . $title : '');
        return vs_notify_user($conn, $toId, 'like', $actor, $videoId, $title, $message, $link, 'once');
    }
    if ($type === 'comment') {
        $preview = mb_substr(trim((string)preg_replace('/\s+/', ' ', $text)), 0, 90);
        $message = $actor . ' commented on your ' . $kind . ($preview !== '' ? ': "' . $preview . '"' : '');
        return vs_notify_user($conn, $toId, 'comment', $actor, $videoId, $title, $message, $link, 'fold');
    }
    return false;
}
}

/** Someone subscribed to a channel -> tell the channel owner. */
if (!function_exists('vs_notify_new_subscriber')) {
function vs_notify_new_subscriber(mysqli $conn, string $channelUsername, string $subscriberName): bool
{
    $channelUsername = trim($channelUsername);
    $subscriberName = trim($subscriberName);
    if ($channelUsername === '' || $subscriberName === '' || strcasecmp($channelUsername, $subscriberName) === 0) {
        return false;
    }
    $toId = vs_user_id_by_name($conn, $channelUsername);
    if ($toId <= 0) {
        return false;
    }
    return vs_notify_user(
        $conn, $toId, 'subscriber', $subscriberName, '', '',
        $subscriberName . ' subscribed to your channel',
        'profile.php?u=' . rawurlencode($subscriberName),
        'once'
    );
}
}

/**
 * A notification about one comment.
 *   reply        -> "@name replied: ..."                 (someone answered your comment)
 *   comment_like -> "@name liked your comment: ..."
 *   heart        -> "Your comment got a heart from @name!" (the video's creator hearted it)
 * $toUser is the comment's author, $actor is who did it. Links to the comment itself.
 */
if (!function_exists('vs_notify_comment_event')) {
function vs_notify_comment_event(mysqli $conn, array $video, int $commentId, string $type, string $toUser, string $actor, string $text = ''): bool
{
    $toUser = trim($toUser);
    $actor = trim($actor);
    $videoId = (string)($video['id'] ?? '');
    if ($toUser === '' || $actor === '' || $videoId === '' || stripos($toUser, 'guest') === 0 || strcasecmp($toUser, $actor) === 0) {
        return false;
    }
    $toId = vs_user_id_by_name($conn, $toUser);
    if ($toId <= 0) {
        return false;
    }

    $isShort = (string)($video['type'] ?? '') === 'short';
    $title = mb_substr(trim((string)($video['title'] ?? '')), 0, 200);
    $link = ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode($videoId) . ($commentId > 0 ? '#comment-' . $commentId : '');
    $preview = mb_substr(trim((string)preg_replace('/\s+/', ' ', $text)), 0, 90);
    $quoted = $preview !== '' ? ': "' . $preview . '"' : '';

    if ($type === 'reply') {
        $message = '@' . $actor . ' replied' . $quoted;
        $dedupe = '';
    } elseif ($type === 'heart') {
        $message = 'Your comment got a ' . "\u{2665}" . ' from @' . $actor . '!';
        $dedupe = 'link';
    } else {
        $type = 'comment_like';
        $message = '@' . $actor . ' liked your comment' . $quoted;
        $dedupe = 'link';
    }
    return vs_notify_user($conn, $toId, $type, $actor, $videoId, $title, $message, $link, $dedupe);
}
}

/**
 * If a new comment starts with "@someone" (what the Reply button writes) and that person
 * has commented on this video, tell them. Never throws.
 */
if (!function_exists('vs_notify_comment_reply')) {
function vs_notify_comment_reply(mysqli $conn, array $video, string $actor, string $text, int $commentId): bool
{
    try {
        $text = trim($text);
        $videoId = (string)($video['id'] ?? '');
        if ($text === '' || mb_substr($text, 0, 1) !== '@' || $videoId === '') {
            return false;
        }
        $stmt = $conn->prepare('SELECT DISTINCT username FROM comments WHERE video_id = ?');
        $stmt->bind_param('s', $videoId);
        $stmt->execute();
        $res = $stmt->get_result();
        $target = '';
        while ($row = $res->fetch_assoc()) {
            $name = trim((string)$row['username']);
            if ($name === '') {
                continue;
            }
            $tag = '@' . $name;
            $len = mb_strlen($tag);
            if (mb_strtolower(mb_substr($text, 0, $len)) !== mb_strtolower($tag)) {
                continue;
            }
            $next = mb_substr($text, $len, 1);
            if ($next !== '' && !preg_match('/[\s,.:;!?]/u', $next)) {
                continue;
            }
            if (mb_strlen($name) > mb_strlen($target)) {
                $target = $name;
            }
        }
        $stmt->close();
        if ($target === '') {
            return false;
        }
        // the video's owner already gets "commented on your video", so do not tell them twice
        $owner = trim((string)($video['username'] ?? ''));
        if ($owner !== '' && strcasecmp($owner, $target) === 0) {
            return false;
        }
        $body = trim(mb_substr($text, mb_strlen('@' . $target)));
        return vs_notify_comment_event($conn, $video, $commentId, 'reply', $target, $actor, $body !== '' ? $body : $text);
    } catch (Throwable $error) {
        error_log('VibeStream reply notification: ' . $error->getMessage());
        return false;
    }
}
}

/* ---------- Reading notifications ---------- */

if (!function_exists('vs_notifications_unread_count')) {
function vs_notifications_unread_count(?mysqli $conn, int $userId): int
{
    if (!$conn || $userId <= 0) {
        return 0;
    }
    try {
        vs_notifications_ensure_table($conn);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM user_notifications WHERE user_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $count;
    } catch (Throwable $error) {
        return 0;
    }
}
}

/** Best-effort thumbnail for a videos row (same sources the home page uses). */
if (!function_exists('vs_notif_thumb')) {
function vs_notif_thumb(array $row): string
{
    $columns = ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed', 'id'];
    foreach ($columns as $col) {
        $v = trim((string)($row[$col] ?? ''));
        if ($v === '') {
            continue;
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) {
            return 'https://i.ytimg.com/vi/' . $v . '/hqdefault.jpg';
        }
        if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $v, $m)
            || preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $v, $m)) {
            return 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg';
        }
    }
    foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $col) {
        if (!empty($row[$col])) {
            return (string)$row[$col];
        }
    }
    return '';
}
}

/** Adds 'thumb' (video preview) and 'avatar' (who did it) to each row. */
if (!function_exists('vs_notifications_decorate')) {
function vs_notifications_decorate(?mysqli $conn, array $rows): array
{
    $ids = [];
    foreach ($rows as $r) {
        $vid = (string)($r['video_id'] ?? '');
        if ($vid !== '') {
            $ids[$vid] = true;
        }
    }
    $videos = [];
    if ($conn && $ids) {
        try {
            $list = array_keys($ids);
            $marks = implode(',', array_fill(0, count($list), '?'));
            $stmt = $conn->prepare("SELECT * FROM videos WHERE id IN ($marks)");
            $stmt->bind_param(str_repeat('s', count($list)), ...$list);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($v = $res->fetch_assoc()) {
                $videos[(string)$v['id']] = $v;
            }
            $stmt->close();
        } catch (Throwable $error) {
            // thumbnails are optional
        }
    }
    foreach ($rows as $i => $r) {
        $vid = (string)($r['video_id'] ?? '');
        $rows[$i]['thumb'] = ($vid !== '' && isset($videos[$vid])) ? vs_notif_thumb($videos[$vid]) : '';
        $name = trim((string)($r['actor'] ?? ''));
        if ($name === '') {
            $name = trim((string)($r['channel_name'] ?? ''));
        }
        $avatar = '';
        if ($name !== '' && function_exists('avatar_url') && ($r['type'] ?? '') !== 'message') {
            try {
                $avatar = (string)avatar_url($name);
            } catch (Throwable $error) {
                $avatar = '';
            }
        }
        $rows[$i]['avatar'] = $avatar;
    }
    return $rows;
}
}

if (!function_exists('vs_notifications_list')) {
function vs_notifications_list(?mysqli $conn, int $userId, int $limit = 6, int $offset = 0): array
{
    if (!$conn || $userId <= 0) {
        return [];
    }
    try {
        vs_notifications_ensure_table($conn);
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $stmt = $conn->prepare('SELECT id, type, channel_name, actor, video_id, title, message, link, is_read, created_at FROM user_notifications WHERE user_id = ? ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->bind_param('iii', $userId, $limit, $offset);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return vs_notifications_decorate($conn, $rows);
    } catch (Throwable $error) {
        return [];
    }
}
}

/** Latest notifications for the bell dropdown. */
if (!function_exists('vs_notifications_recent')) {
function vs_notifications_recent(?mysqli $conn, int $userId, int $limit = 6): array
{
    return vs_notifications_list($conn, $userId, $limit);
}
}

/* ---------- Rendering ---------- */

if (!function_exists('vs_notification_item_html')) {
function vs_notification_item_html(array $n): string
{
    $e = 'vs_notification_esc';
    $id = (int)($n['id'] ?? 0);
    $unread = !(int)($n['is_read'] ?? 1);
    $type = (string)($n['type'] ?? 'video');
    $link = (string)($n['link'] ?? '');
    if ($link === '') {
        $link = 'notifications.php';
    }
    $name = trim((string)($n['actor'] ?? ''));
    if ($name === '') {
        $name = trim((string)($n['channel_name'] ?? ''));
    }
    $initial = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : '!';
    $hue = abs(crc32(mb_strtolower($name))) % 360;
    $avatarBg = 'linear-gradient(135deg,hsl(' . $hue . ' 65% 45%),hsl(' . (($hue + 40) % 360) . ' 70% 35%))';

    $html = '<div class="vs-n-item' . ($unread ? ' unread' : '') . '" data-id="' . $id . '" data-type="' . $e($type) . '">'
        . '<a class="vs-n-link" href="' . $e($link) . '">'
        . '<span class="vs-n-dot" aria-hidden="true"></span>'
        . '<span class="vs-n-av" style="background:' . $e($avatarBg) . '">' . $e($initial);
    if (!empty($n['avatar'])) {
        $html .= '<img src="' . $e($n['avatar']) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    $html .= '</span>'
        . '<span class="vs-n-body">'
        . '<span class="vs-n-msg">' . $e($n['message'] ?? '') . '</span>'
        . '<span class="vs-n-time">' . $e(vs_notification_time_ago((string)($n['created_at'] ?? ''))) . '</span>'
        . '</span>';
    if (!empty($n['thumb'])) {
        $html .= '<img class="vs-n-thumb" src="' . $e($n['thumb']) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    $html .= '</a>'
        . '<button type="button" class="vs-n-more" data-vs-n-more aria-label="More options" aria-haspopup="true">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>'
        . '</button>'
        . '<div class="vs-n-menu" hidden>'
        . '<button type="button" data-vs-n-act="read">Mark as read</button>'
        . '<button type="button" data-vs-n-act="delete">Remove this notification</button>'
        . '</div>'
        . '</div>';
    return $html;
}
}

if (!function_exists('vs_notification_empty_html')) {
function vs_notification_empty_html(): string
{
    return '<div class="vs-n-empty">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>'
        . '<strong>Your notifications live here</strong>'
        . '<span>Subscribe to channels and get told when they upload, plus likes and comments on your videos.</span>'
        . '</div>';
}
}

if (!function_exists('vs_notification_list_html')) {
function vs_notification_list_html(array $items): string
{
    if (!$items) {
        return vs_notification_empty_html();
    }
    $html = '';
    foreach ($items as $item) {
        $html .= vs_notification_item_html($item);
    }
    return $html;
}
}

if (!function_exists('vs_notification_css')) {
function vs_notification_css(): string
{
    return <<<'CSS'
/* YouTube-style notifications panel */
.nav-notif-menu { width: 480px; max-width: calc(100vw - 24px); padding: 0; border-radius: 12px; }
:root:not([data-theme="light"]) .nav-notif-menu { background: #282828; border-color: #3a3a3a; }
.nav-bell-badge[hidden] { display: none; }
.vs-n-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 0 12px 0 16px; height: 56px; border-bottom: 1px solid var(--border, #303030); }
.vs-n-head h3 { margin: 0; font-size: 16px; font-weight: 500; color: var(--text, #f1f1f1); }
.vs-n-head-actions { display: flex; align-items: center; gap: 4px; }
.vs-n-gear { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; color: var(--text, #f1f1f1); text-decoration: none; }
.vs-n-gear:hover { background: var(--surface-hover, rgba(127,127,127,.18)); }
.vs-n-gear svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linecap: round; stroke-linejoin: round; }
.vs-n-list { max-height: min(70vh, 560px); overflow-y: auto; }
.vs-n-item { position: relative; display: flex; align-items: center; color: var(--text, #f1f1f1); }
.vs-n-item:hover { background: var(--surface-hover, rgba(127,127,127,.14)); }
.vs-n-item.unread { background: rgba(62,166,255,.08); }
.vs-n-item.unread:hover { background: rgba(62,166,255,.14); }
.vs-n-link { display: flex; align-items: center; gap: 12px; flex: 1; min-width: 0; padding: 12px 0 12px 6px; color: inherit; text-decoration: none; }
.vs-n-dot { width: 6px; height: 6px; flex: 0 0 6px; border-radius: 50%; background: transparent; }
.vs-n-item.unread .vs-n-dot { background: var(--blue, #3ea6ff); }
.vs-n-av { position: relative; width: 48px; height: 48px; flex: 0 0 48px; border-radius: 50%; overflow: hidden; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 18px; }
.vs-n-av img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.vs-n-av.is-app { background: #fff; border-radius: 12px; }
.vs-n-av.is-app img { object-fit: contain; padding: 4px; }
.vs-n-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 6px; }
.vs-n-msg { font-size: 14px; line-height: 20px; font-weight: 400; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.vs-n-time { font-size: 12px; line-height: 18px; color: var(--muted, #aaaaaa); }
.vs-n-thumb { width: 108px; height: 60px; flex: 0 0 108px; border-radius: 8px; object-fit: cover; background: var(--surface-2, #272727); }
.vs-n-more { width: 40px; height: 40px; margin: 0 8px 0 4px; flex: 0 0 40px; display: grid; place-items: center; border: 0; background: none; border-radius: 50%; cursor: pointer; color: var(--text, #f1f1f1); }
.vs-n-more:hover { background: var(--surface-2, rgba(127,127,127,.2)); }
.vs-n-more svg { width: 20px; height: 20px; fill: currentColor; }
.vs-n-menu { position: absolute; right: 14px; top: calc(100% - 18px); z-index: 20; min-width: 230px; padding: 8px 0; border-radius: 12px; background: var(--surface-raised, var(--surface, #1f1f1f)); border: 1px solid var(--border, #303030); box-shadow: 0 12px 30px rgba(0,0,0,.4); }
:root:not([data-theme="light"]) .vs-n-menu { background: #212121; }
.vs-n-menu[hidden] { display: none; }
.vs-n-menu button { display: block; width: 100%; text-align: left; background: none; border: 0; cursor: pointer; font: inherit; font-size: 14px; color: var(--text, #f1f1f1); padding: 10px 16px; }
.vs-n-menu button:hover { background: var(--surface-hover, rgba(127,127,127,.18)); }
.vs-n-foot { display: flex; align-items: center; justify-content: space-between; border-top: 1px solid var(--border, #303030); }
.vs-n-foot .nav-notif-all { flex: 1; border-top: 0; padding: 14px 16px; text-align: center; font-size: 14px; font-weight: 500; }
.vs-n-readall { flex: 1; background: none; border: 0; border-right: 1px solid var(--border, #303030); cursor: pointer; font: inherit; font-size: 14px; font-weight: 500; color: var(--blue, #3ea6ff); padding: 14px 16px; }
.vs-n-readall:hover { background: var(--surface-hover, rgba(127,127,127,.14)); }
.vs-n-empty { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 8px; padding: 48px 24px 56px; color: var(--muted, #aaaaaa); font-size: 14px; line-height: 20px; }
.vs-n-empty svg { width: 56px; height: 56px; fill: none; stroke: currentColor; stroke-width: 1.4; stroke-linecap: round; stroke-linejoin: round; opacity: .8; }
.vs-n-empty strong { color: var(--text, #f1f1f1); font-size: 16px; font-weight: 500; }
@media (max-width: 600px) {
  .vs-n-thumb { width: 72px; height: 40px; flex-basis: 72px; }
  .vs-n-av { width: 40px; height: 40px; flex-basis: 40px; font-size: 16px; }
}
CSS;
}
}

/**
 * The bell shown in the navbar. Clicking it opens a YouTube-style dropdown with
 * the latest notifications (avatar, text, time, video thumbnail, a "..." menu),
 * a settings gear and a "View all notifications" link. The count updates itself.
 */
if (!function_exists('vs_notification_bell_html')) {
function vs_notification_bell_html(): string
{
    static $scriptDone = false;

    $userId = (int)($_SESSION['user_id'] ?? 0);
    $signedIn = $userId > 0 && !empty($_SESSION['username']);
    $conn = (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) ? $GLOBALS['conn'] : null;
    $count = 0;
    $items = [];
    if ($signedIn) {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        $count = vs_notifications_unread_count($conn, $userId);
        $items = vs_notifications_list($conn, $userId, 8);
    }
    $e = 'vs_notification_esc';
    $label = $count > 0 ? 'Notifications (' . $count . ' unread)' : 'Notifications';

    $html = vs_install_app_html();
    $html .= '<div class="nav-notif" data-vs-notif data-signed="' . ($signedIn ? '1' : '0') . '"'
        . ($signedIn ? ' data-csrf="' . $e($_SESSION['csrf']) . '" data-api="notifications-api.php"' : '') . '>'
        . '<button type="button" class="nav-bell" data-vs-notif-toggle aria-haspopup="true" aria-expanded="false" aria-label="' . $e($label) . '" title="Notifications">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/></svg>'
        . '<span class="nav-bell-badge" data-vs-badge' . ($count > 0 ? '' : ' hidden') . '>' . ($count > 99 ? '99+' : (string)$count) . '</span>'
        . '</button>';

    $html .= '<div class="nav-notif-menu" role="menu" hidden>';
    $html .= '<div class="vs-n-head"><h3>Notifications</h3><div class="vs-n-head-actions">';
    if ($signedIn) {
        $html .= '<a class="vs-n-gear" href="notifications.php#settings" title="Notification settings" aria-label="Notification settings">'
            . '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></a>';
    }
    $html .= '</div></div>';
    if (!$signedIn) {
        $html .= '<div class="vs-n-empty"><strong>Sign in to see notifications</strong><span>Get told about new uploads, likes and comments.</span></div>';
        $html .= '<div class="vs-n-foot"><a class="nav-notif-all" href="login.php?redirect=notifications.php">Sign in</a></div>';
    } else {
        $html .= '<div class="vs-n-list" data-vs-n-list>' . vs_notification_list_html($items) . '</div>';
        $html .= '<div class="vs-n-foot"><button type="button" class="vs-n-readall" data-vs-n-readall>Mark all as read</button>'
            . '<a class="nav-notif-all" href="notifications.php">View all</a></div>';
    }
    $html .= '</div></div>';

    if (!$scriptDone) {
        $scriptDone = true;
        $html .= '<style>' . vs_notification_css() . '</style>';
        $html .= <<<'JS'
<script>
(function () {
  if (window.__vsNotifInit) return;
  window.__vsNotifInit = true;

  function root() { return document.querySelector('[data-vs-notif]'); }
  function signed() { var r = root(); return r && r.getAttribute('data-signed') === '1'; }
  function post(params) {
    var r = root(); if (!r) return Promise.resolve({ success: false });
    var body = new URLSearchParams(params);
    body.set('csrf', r.getAttribute('data-csrf') || '');
    return fetch(r.getAttribute('data-api'), {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body
    }).then(function (res) { return res.json(); }).catch(function () { return { success: false }; });
  }
  function setBadge(n) {
    document.querySelectorAll('[data-vs-badge]').forEach(function (b) {
      if (n > 0) { b.textContent = n > 99 ? '99+' : String(n); b.hidden = false; } else { b.hidden = true; }
    });
  }
  function closeMenus(except) {
    document.querySelectorAll('.vs-n-menu').forEach(function (m) { if (m !== except) m.hidden = true; });
  }
  function closeBell() {
    document.querySelectorAll('[data-vs-notif]').forEach(function (wrap) {
      var menu = wrap.querySelector('.nav-notif-menu'), btn = wrap.querySelector('[data-vs-notif-toggle]');
      if (menu) menu.hidden = true;
      if (btn) btn.setAttribute('aria-expanded', 'false');
    });
    closeMenus(null);
  }
  function refreshList() {
    var r = root(); if (!r || !signed()) return;
    fetch(r.getAttribute('data-api') + '?action=list', { credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (d) {
        if (!d || !d.success) return;
        var list = r.querySelector('[data-vs-n-list]');
        if (list) list.innerHTML = d.html;
        setBadge(d.unread);
      }).catch(function () {});
  }
  function emptyCheck(container) {
    if (container && !container.querySelector('.vs-n-item')) {
      container.innerHTML = '<div class="vs-n-empty"><strong>Your notifications live here</strong><span>Nothing new right now.</span></div>';
    }
  }

  document.addEventListener('click', function (event) {
    var t = event.target;
    var more = t.closest ? t.closest('[data-vs-n-more]') : null;
    if (more) {
      var menu = more.parentNode.querySelector('.vs-n-menu');
      var open = menu.hidden;
      closeMenus(menu);
      menu.hidden = !open;
      event.preventDefault();
      return;
    }
    var act = t.closest ? t.closest('[data-vs-n-act]') : null;
    if (act) {
      var item = act.closest('.vs-n-item'), id = item.getAttribute('data-id');
      var container = item.parentNode;
      if (act.getAttribute('data-vs-n-act') === 'delete') {
        post({ action: 'delete', id: id }).then(function (d) { if (d && d.success) setBadge(d.unread); });
        item.remove(); emptyCheck(container);
      } else {
        item.classList.remove('unread');
        post({ action: 'read', id: id }).then(function (d) { if (d && d.success) setBadge(d.unread); });
        item.querySelector('.vs-n-menu').hidden = true;
      }
      return;
    }
    var readAll = t.closest ? t.closest('[data-vs-n-readall]') : null;
    if (readAll) {
      document.querySelectorAll('.vs-n-item.unread').forEach(function (i) { i.classList.remove('unread'); });
      post({ action: 'read_all' }).then(function (d) { if (d && d.success) setBadge(d.unread); });
      return;
    }
    var link = t.closest ? t.closest('.vs-n-link') : null;
    if (link) {
      var it = link.closest('.vs-n-item');
      if (it && it.classList.contains('unread')) post({ action: 'read', id: it.getAttribute('data-id') });
      return;
    }
    var wrap = t.closest ? t.closest('[data-vs-notif]') : null;
    if (!wrap) { closeBell(); return; }
    var toggle = t.closest('[data-vs-notif-toggle]');
    closeMenus(null);
    if (!toggle) return;
    var m = wrap.querySelector('.nav-notif-menu');
    var willOpen = m.hidden;
    m.hidden = !willOpen;
    toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    if (willOpen) refreshList();
  });
  document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeBell(); });

  // keep the red count fresh without a page refresh
  if (signed()) {
    setInterval(function () {
      if (document.visibilityState !== 'visible') return;
      var r = root();
      fetch(r.getAttribute('data-api') + '?action=poll', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (d) { if (d && d.success) setBadge(d.unread); }).catch(function () {});
    }, 30000);
  }
})();
</script>
JS;
    }

    return $html;
}
}

/* ---------- Admin <-> admin messages ---------- */

/** Best-effort id of whoever is signed in (works for admin and normal sessions). */
if (!function_exists('vs_current_user_id')) {
function vs_current_user_id(?mysqli $conn = null): int
{
    foreach (['user_id', 'AdminId', 'AdminID', 'UserId'] as $key) {
        if (!empty($_SESSION[$key]) && (int)$_SESSION[$key] > 0) {
            return (int)$_SESSION[$key];
        }
    }
    $username = (string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? '');
    if ($conn && $username !== '') {
        try {
            $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return (int)($row['id'] ?? 0);
        } catch (Throwable $error) {
            return 0;
        }
    }
    return 0;
}
}

if (!function_exists('vs_admin_messages_ensure_table')) {
function vs_admin_messages_ensure_table(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `admin_messages` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `sender_id` INT NOT NULL,
            `recipient_id` INT NOT NULL,
            `body` TEXT NOT NULL,
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin_messages_inbox` (`recipient_id`, `is_read`),
            KEY `idx_admin_messages_pair` (`sender_id`, `recipient_id`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}
}

if (!function_exists('vs_admin_messages_unread')) {
function vs_admin_messages_unread(?mysqli $conn, int $userId): int
{
    if (!$conn || $userId <= 0) {
        return 0;
    }
    try {
        vs_admin_messages_ensure_table($conn);
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM admin_messages WHERE recipient_id = ? AND is_read = 0');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $count = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $count;
    } catch (Throwable $error) {
        return 0;
    }
}
}

/**
 * Store a message from one admin to another and drop a bell notification
 * for the recipient. Repeated unread messages from the same sender are
 * folded into a single notification so the bell is not flooded.
 */
if (!function_exists('vs_admin_send_message')) {
function vs_admin_send_message(mysqli $conn, int $fromId, string $fromName, int $toId, string $body): bool
{
    try {
        vs_admin_messages_ensure_table($conn);
        $stmt = $conn->prepare('INSERT INTO admin_messages (sender_id, recipient_id, body) VALUES (?, ?, ?)');
        $stmt->bind_param('iis', $fromId, $toId, $body);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('VibeStream admin message failed: ' . $error->getMessage());
        return false;
    }

    try {
        vs_notifications_ensure_table($conn);
        $link = 'adminpanel/messages.php?with=' . $fromId;
        $del = $conn->prepare("DELETE FROM user_notifications WHERE user_id = ? AND type = 'message' AND link = ? AND is_read = 0");
        $del->bind_param('is', $toId, $link);
        $del->execute();
        $del->close();

        $preview = mb_substr(trim((string)preg_replace('/\s+/', ' ', $body)), 0, 80);
        $message = mb_substr($fromName . ' sent you a message: ' . $preview, 0, 500);
        $type = 'message';
        $channel = mb_substr($fromName, 0, 255);
        $ins = $conn->prepare('INSERT INTO user_notifications (user_id, type, channel_name, title, message, link) VALUES (?, ?, ?, ?, ?, ?)');
        $title = 'New message';
        $ins->bind_param('isssss', $toId, $type, $channel, $title, $message, $link);
        $ins->execute();
        $ins->close();
    } catch (Throwable $error) {
        error_log('VibeStream message notification failed: ' . $error->getMessage());
    }
    return true;
}
}

if (!function_exists('vs_notification_time_ago')) {
function vs_notification_time_ago(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    $plural = function (int $n, string $word): string {
        return $n . ' ' . $word . ($n === 1 ? '' : 's') . ' ago';
    };
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return $plural((int)floor($diff / 60), 'minute');
    if ($diff < 86400) return $plural((int)floor($diff / 3600), 'hour');
    if ($diff < 604800) return $plural((int)floor($diff / 86400), 'day');
    if ($diff < 2592000) return $plural((int)floor($diff / 604800), 'week');
    return date('M j, Y', $ts);
}
}