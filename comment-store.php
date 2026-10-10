<?php
/**
 * VibeStream comments (used by watch.php and shorts.php).
 *
 * Works with comments.js / comments.css. Replies are normal comments that start with
 * "@name " (what the Reply button types), so no extra column is needed.
 *
 * Needs the existing `comments` table (id, video_id, username, comment_text). Missing
 * columns / tables (created_at, hearted, vs_comment_votes) are added automatically the
 * first time they are needed.
 *
 * Public functions:
 *   vs_comments_is_admin()
 *   vs_comments_fetch($conn, $videoId, $viewerName)
 *   vs_comments_handle_action($conn, $action, $videoId)   -> array|null
 *   vs_comment_new_item_html($id, $user, $text)
 *   vs_comments_panel_html($options)
 *
 * NOTE: this file has nothing to do with notifications. Those live in notifications-store.php.
 */

if (!function_exists('vs_comments_esc')) {
    function vs_comments_esc($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('vs_comments_viewer')) {
    function vs_comments_viewer(): string
    {
        return trim((string)($_SESSION['username'] ?? ''));
    }
}

if (!function_exists('vs_comments_is_admin')) {
    function vs_comments_is_admin(): bool
    {
        foreach (['AdminSession', 'AdminId', 'AdminID', 'admin_id'] as $key) {
            if (!empty($_SESSION[$key])) {
                return true;
            }
        }
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        return $role === 'admin' || !empty($_SESSION['is_admin']);
    }
}

if (!function_exists('vs_comments_ensure_schema')) {
    function vs_comments_ensure_schema(mysqli $conn): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $result = $conn->query('SHOW COLUMNS FROM `comments`');
        if (!$result) {
            throw new RuntimeException('The comments table is missing.');
        }
        $cols = [];
        while ($row = $result->fetch_assoc()) {
            $cols[strtolower((string)$row['Field'])] = true;
        }
        $result->free();

        if (empty($cols['created_at'])) {
            $conn->query('ALTER TABLE `comments` ADD COLUMN `created_at` DATETIME NULL DEFAULT CURRENT_TIMESTAMP');
        }
        if (empty($cols['hearted'])) {
            $conn->query('ALTER TABLE `comments` ADD COLUMN `hearted` TINYINT(1) NOT NULL DEFAULT 0');
        }

        $conn->query(<<<'SQL'
CREATE TABLE IF NOT EXISTS `vs_comment_votes` (
    `comment_id` BIGINT UNSIGNED NOT NULL,
    `voter` VARCHAR(191) NOT NULL,
    `vote` TINYINT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`comment_id`, `voter`),
    KEY `idx_vs_comment_votes_voter` (`voter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        $done = true;
    }
}

if (!function_exists('vs_comments_time_ago')) {
    function vs_comments_time_ago(int $ts): string
    {
        if ($ts <= 0) {
            return '';
        }
        $diff = max(0, time() - $ts);
        if ($diff < 60) {
            return 'just now';
        }
        $units = [
            ['year', 31536000],
            ['month', 2592000],
            ['week', 604800],
            ['day', 86400],
            ['hour', 3600],
            ['minute', 60],
        ];
        foreach ($units as [$name, $secs]) {
            if ($diff >= $secs) {
                $n = (int)floor($diff / $secs);
                return $n . ' ' . $name . ($n === 1 ? '' : 's') . ' ago';
            }
        }
        return 'just now';
    }
}

if (!function_exists('vs_comments_avatar')) {
    function vs_comments_avatar(string $name): string
    {
        if ($name === '' || !function_exists('avatar_url')) {
            return '';
        }
        try {
            return (string)avatar_url($name);
        } catch (Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('vs_comments_avatar_html')) {
    function vs_comments_avatar_html(string $name, string $extraClass = ''): string
    {
        $url = vs_comments_avatar($name);
        $initial = mb_strtoupper(mb_substr($name !== '' ? $name : 'G', 0, 1));
        $html = '<span class="vs-cm-av' . ($extraClass !== '' ? ' ' . $extraClass : '') . '">';
        $html .= '<span class="vs-cm-initial">' . vs_comments_esc($initial) . '</span>';
        if ($url !== '') {
            $html .= '<img src="' . vs_comments_esc($url) . '" alt="" loading="lazy" onerror="this.remove()">';
        }
        return $html . '</span>';
    }
}

if (!function_exists('vs_comments_icon')) {
    function vs_comments_icon(string $name): string
    {
        $open = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"';
        switch ($name) {
            case 'like':
                return $open . '><path d="M7 11v10H4a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1h3Zm0 0 3.4-6.3A2 2 0 0 1 12.2 4c1.3 0 2.2 1.2 1.9 2.4L13.4 10H19a2 2 0 0 1 1.95 2.45l-1.5 6.5A2 2 0 0 1 17.5 20.5H9a2 2 0 0 1-2-2V11"/></svg>';
            case 'dislike':
                return $open . ' style="transform:rotate(180deg)"><path d="M7 11v10H4a1 1 0 0 1-1-1v-8a1 1 0 0 1 1-1h3Zm0 0 3.4-6.3A2 2 0 0 1 12.2 4c1.3 0 2.2 1.2 1.9 2.4L13.4 10H19a2 2 0 0 1 1.95 2.45l-1.5 6.5A2 2 0 0 1 17.5 20.5H9a2 2 0 0 1-2-2V11"/></svg>';
            case 'heart':
                return $open . '><path d="M12 20.5s-7.5-4.7-9.6-9.4A5.3 5.3 0 0 1 12 6.2a5.3 5.3 0 0 1 9.6 4.9c-2.1 4.7-9.6 9.4-9.6 9.4Z"/></svg>';
            case 'heart-sm':
                return '<svg viewBox="0 0 24 24" width="14" height="14" fill="#ff4e45" stroke="#ff4e45" stroke-width="1.5" aria-hidden="true"><path d="M12 20.5s-7.5-4.7-9.6-9.4A5.3 5.3 0 0 1 12 6.2a5.3 5.3 0 0 1 9.6 4.9c-2.1 4.7-9.6 9.4-9.6 9.4Z"/></svg>';
            case 'more':
                return '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg>';
            case 'sort':
                return $open . '><path d="M3 6h18M6 12h12M10 18h4"/></svg>';
            case 'close':
                return $open . '><path d="m6 6 12 12M18 6 6 18"/></svg>';
        }
        return '';
    }
}

/**
 * Who owns the video + what the viewer may do. Returns
 * ['owner' => string, 'is_owner' => bool, 'is_admin' => bool]
 */
if (!function_exists('vs_comments_video_context')) {
    function vs_comments_video_context(mysqli $conn, string $videoId, string $viewer): array
    {
        $owner = '';
        try {
            $stmt = $conn->prepare('SELECT username, channel_name FROM videos WHERE id = ? LIMIT 1');
            $stmt->bind_param('s', $videoId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                $owner = trim((string)($row['username'] ?? ''));
                if ($owner === '') {
                    $owner = trim((string)($row['channel_name'] ?? ''));
                }
            }
        } catch (Throwable $e) {
            error_log('VibeStream comments owner lookup: ' . $e->getMessage());
        }
        return [
            'owner' => $owner,
            'is_owner' => $viewer !== '' && $owner !== '' && strcasecmp($viewer, $owner) === 0,
            'is_admin' => vs_comments_is_admin(),
        ];
    }
}

/** All comments of a video (newest first) with like counts and the viewer's own vote. */
if (!function_exists('vs_comments_fetch')) {
    function vs_comments_fetch(mysqli $conn, string $videoId, string $viewerName = ''): array
    {
        vs_comments_ensure_schema($conn);
        $viewer = trim($viewerName);
        $voter = mb_strtolower($viewer);
        $ctx = vs_comments_video_context($conn, $videoId, $viewer);

        $sql = 'SELECT c.id, c.username, c.comment_text, c.hearted,
                       COALESCE(UNIX_TIMESTAMP(c.created_at), 0) AS ts,
                       (SELECT COUNT(*) FROM vs_comment_votes v WHERE v.comment_id = c.id AND v.vote = 1) AS likes,
                       COALESCE((SELECT v2.vote FROM vs_comment_votes v2 WHERE v2.comment_id = c.id AND v2.voter = ? LIMIT 1), 0) AS my_vote
                FROM comments c
                WHERE c.video_id = ?
                ORDER BY c.id DESC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not load comments.');
        }
        $stmt->bind_param('ss', $voter, $videoId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($rows as &$row) {
            $author = trim((string)$row['username']);
            $row['id'] = (int)$row['id'];
            $row['ts'] = (int)$row['ts'];
            $row['likes'] = (int)$row['likes'];
            $row['my_vote'] = (int)$row['my_vote'];
            $row['hearted'] = !empty($row['hearted']);
            $row['owner'] = $ctx['owner'];
            $row['can_heart'] = $ctx['is_owner'] || $ctx['is_admin'];
            $row['can_delete'] = $ctx['is_owner'] || $ctx['is_admin']
                || ($viewer !== '' && strcasecmp($author, $viewer) === 0);
        }
        unset($row);

        return $rows;
    }
}

/** HTML for one comment. $c: id, username, comment_text, ts, likes, my_vote, hearted, owner, can_heart, can_delete */
if (!function_exists('vs_comments_item_html')) {
    function vs_comments_item_html(array $c): string
    {
        $id = (int)($c['id'] ?? 0);
        $user = trim((string)($c['username'] ?? ''));
        $text = (string)($c['comment_text'] ?? '');
        $ts = (int)($c['ts'] ?? 0);
        $likes = (int)($c['likes'] ?? 0);
        $myVote = (int)($c['my_vote'] ?? 0);
        $hearted = !empty($c['hearted']);
        $owner = (string)($c['owner'] ?? '');

        $h = '<div class="vs-cm-item" id="comment-' . $id . '" data-id="' . $id . '" data-user="' . vs_comments_esc($user)
            . '" data-ts="' . $ts . '" data-likes="' . $likes . '">';
        $h .= vs_comments_avatar_html($user);
        $h .= '<div class="vs-cm-main">';

        $h .= '<div class="vs-cm-meta"><span class="vs-cm-user">' . vs_comments_esc($user !== '' ? $user : 'Guest') . '</span>';
        $h .= '<span class="vs-cm-time">' . vs_comments_esc(vs_comments_time_ago($ts)) . '</span>';
        $h .= '<span class="vs-cm-hearted" title="Hearted by ' . vs_comments_esc($owner !== '' ? $owner : 'the creator') . '"' . ($hearted ? '' : ' hidden') . '>';
        $ownerAvatar = vs_comments_avatar($owner);
        $h .= '<span class="vs-cm-hav">' . ($ownerAvatar !== '' ? '<img src="' . vs_comments_esc($ownerAvatar) . '" alt="" onerror="this.remove()">' : '') . '</span>';
        $h .= vs_comments_icon('heart-sm') . '</span>';
        $h .= '</div>';

        $h .= '<div class="vs-cm-text">' . vs_comments_esc($text) . '</div>';

        $h .= '<div class="vs-cm-actions">';
        $h .= '<button type="button" class="vs-cm-btn vs-cm-like' . ($myVote === 1 ? ' on' : '') . '" data-cm="like" aria-label="Like" aria-pressed="' . ($myVote === 1 ? 'true' : 'false') . '">'
            . vs_comments_icon('like') . '<span class="vs-cm-likes">' . ($likes > 0 ? $likes : '') . '</span></button>';
        $h .= '<button type="button" class="vs-cm-btn vs-cm-dislike' . ($myVote === -1 ? ' on' : '') . '" data-cm="dislike" aria-label="Dislike" aria-pressed="' . ($myVote === -1 ? 'true' : 'false') . '">'
            . vs_comments_icon('dislike') . '</button>';
        if (!empty($c['can_heart'])) {
            $h .= '<button type="button" class="vs-cm-btn vs-cm-heart' . ($hearted ? ' on' : '') . '" data-cm="heart" aria-label="Heart" aria-pressed="' . ($hearted ? 'true' : 'false') . '">'
                . vs_comments_icon('heart') . '</button>';
        }
        $h .= '<button type="button" class="vs-cm-btn vs-cm-reply" data-cm="reply">Reply</button>';
        $h .= '</div>';
        $h .= '</div>';

        $h .= '<div class="vs-cm-morewrap">';
        $h .= '<button type="button" class="vs-cm-iconbtn" data-cm="menu" aria-label="Comment actions">' . vs_comments_icon('more') . '</button>';
        $h .= '<div class="vs-cm-menu" hidden>';
        $h .= '<button type="button" data-cm="copy">Copy text</button>';
        if (!empty($c['can_delete'])) {
            $h .= '<button type="button" data-cm="delete">Delete</button>';
        }
        $h .= '</div></div>';

        return $h . '</div>';
    }
}

/** HTML for a comment that was just posted (returned to comments.js as d.html). */
if (!function_exists('vs_comment_new_item_html')) {
    function vs_comment_new_item_html(int $id, string $user, string $text): string
    {
        $viewer = vs_comments_viewer();
        $canHeart = vs_comments_is_admin();
        $canDelete = vs_comments_is_admin() || ($viewer !== '' && strcasecmp($viewer, $user) === 0);
        $owner = '';

        $conn = $GLOBALS['conn'] ?? null;
        if ($conn instanceof mysqli) {
            try {
                $stmt = $conn->prepare('SELECT c.video_id FROM comments c WHERE c.id = ? LIMIT 1');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $ctx = vs_comments_video_context($conn, (string)$row['video_id'], $viewer);
                    $owner = $ctx['owner'];
                    $canHeart = $canHeart || $ctx['is_owner'];
                    $canDelete = $canDelete || $ctx['is_owner'];
                }
            } catch (Throwable $e) {
                error_log('VibeStream new comment html: ' . $e->getMessage());
            }
        }

        return vs_comments_item_html([
            'id' => $id,
            'username' => $user,
            'comment_text' => $text,
            'ts' => time(),
            'likes' => 0,
            'my_vote' => 0,
            'hearted' => false,
            'owner' => $owner,
            'can_heart' => $canHeart,
            'can_delete' => $canDelete,
        ]);
    }
}

if (!function_exists('vs_comments_count')) {
    function vs_comments_count(mysqli $conn, string $videoId): int
    {
        $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM comments WHERE video_id = ?');
        $stmt->bind_param('s', $videoId);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}

/**
 * Handles the extra comment actions posted by comments.js:
 *   comment_vote (comment_id, vote = 1 | -1), comment_heart (comment_id), comment_delete (comment_id)
 * Returns the JSON array to send, or null when $action is not a comment action.
 */
if (!function_exists('vs_comments_handle_action')) {
    function vs_comments_handle_action(mysqli $conn, string $action, string $videoId): ?array
    {
        if (!in_array($action, ['comment_vote', 'comment_heart', 'comment_delete'], true)) {
            return null;
        }

        vs_comments_ensure_schema($conn);
        $viewer = vs_comments_viewer();
        $commentId = (int)($_POST['comment_id'] ?? 0);
        if ($commentId <= 0) {
            return ['success' => false, 'error' => 'Missing comment.'];
        }

        $stmt = $conn->prepare('SELECT id, username, hearted FROM comments WHERE id = ? AND video_id = ? LIMIT 1');
        $stmt->bind_param('is', $commentId, $videoId);
        $stmt->execute();
        $comment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$comment) {
            return ['success' => false, 'error' => 'That comment no longer exists.'];
        }

        $ctx = vs_comments_video_context($conn, $videoId, $viewer);

        if ($action === 'comment_vote') {
            if ($viewer === '') {
                return ['success' => false, 'error' => 'Sign in to like or dislike comments.'];
            }
            $vote = ((int)($_POST['vote'] ?? 1)) < 0 ? -1 : 1;
            $voter = mb_strtolower($viewer);

            $stmt = $conn->prepare('SELECT vote FROM vs_comment_votes WHERE comment_id = ? AND voter = ? LIMIT 1');
            $stmt->bind_param('is', $commentId, $voter);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing && (int)$existing['vote'] === $vote) {
                $stmt = $conn->prepare('DELETE FROM vs_comment_votes WHERE comment_id = ? AND voter = ?');
                $stmt->bind_param('is', $commentId, $voter);
                $stmt->execute();
                $stmt->close();
                $myVote = 0;
            } else {
                $stmt = $conn->prepare('INSERT INTO vs_comment_votes (comment_id, voter, vote) VALUES (?, ?, ?)
                                        ON DUPLICATE KEY UPDATE vote = VALUES(vote)');
                $stmt->bind_param('isi', $commentId, $voter, $vote);
                $stmt->execute();
                $stmt->close();
                $myVote = $vote;
            }

            $stmt = $conn->prepare('SELECT COUNT(*) AS n FROM vs_comment_votes WHERE comment_id = ? AND vote = 1');
            $stmt->bind_param('i', $commentId);
            $stmt->execute();
            $likes = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
            $stmt->close();

            return ['success' => true, 'likes' => $likes, 'my_vote' => $myVote];
        }

        if ($action === 'comment_heart') {
            if (!$ctx['is_owner'] && !$ctx['is_admin']) {
                return ['success' => false, 'error' => 'Only the creator can heart comments.'];
            }
            $hearted = empty($comment['hearted']) ? 1 : 0;
            $stmt = $conn->prepare('UPDATE comments SET hearted = ? WHERE id = ?');
            $stmt->bind_param('ii', $hearted, $commentId);
            $stmt->execute();
            $stmt->close();
            return ['success' => true, 'hearted' => $hearted === 1];
        }

        // comment_delete
        $isAuthor = $viewer !== '' && strcasecmp(trim((string)$comment['username']), $viewer) === 0;
        if (!$isAuthor && !$ctx['is_owner'] && !$ctx['is_admin']) {
            return ['success' => false, 'error' => 'You can only delete your own comments.'];
        }
        $stmt = $conn->prepare('DELETE FROM comments WHERE id = ?');
        $stmt->bind_param('i', $commentId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare('DELETE FROM vs_comment_votes WHERE comment_id = ?');
        $stmt->bind_param('i', $commentId);
        $stmt->execute();
        $stmt->close();

        return ['success' => true, 'comment_count' => vs_comments_count($conn, $videoId)];
    }
}

/**
 * The whole comments panel (header, list, composer).
 * Options: endpoint, video_id, comments, viewer, is_admin, viewer_avatar, modal, disabled, list_id
 */
if (!function_exists('vs_comments_panel_html')) {
    function vs_comments_panel_html(array $o): string
    {
        $endpoint = (string)($o['endpoint'] ?? 'watch.php');
        $videoId = (string)($o['video_id'] ?? '');
        $comments = is_array($o['comments'] ?? null) ? $o['comments'] : [];
        $viewer = trim((string)($o['viewer'] ?? ''));
        $modal = !empty($o['modal']);
        $disabled = !empty($o['disabled']);
        $listId = (string)($o['list_id'] ?? 'comment-list');
        $count = count($comments);

        $h = '<section class="vs-cm' . ($modal ? ' vs-cm-modal' : '') . '" data-endpoint="' . vs_comments_esc($endpoint)
            . '" data-video-id="' . vs_comments_esc($videoId) . '" data-logged-in="' . ($viewer !== '' ? '1' : '0') . '">';

        $h .= '<div class="vs-cm-head">';
        $h .= '<h3>Comments</h3><span class="vs-cm-count" data-cm-count>' . $count . '</span><span class="vs-cm-spacer"></span>';
        $h .= '<div class="vs-cm-sortwrap">';
        $h .= '<button type="button" class="vs-cm-iconbtn" data-cm="sort" aria-label="Sort comments">' . vs_comments_icon('sort') . '</button>';
        $h .= '<div class="vs-cm-menu vs-cm-sortmenu" hidden>';
        $h .= '<button type="button" data-cm="sort-top">Top comments</button>';
        $h .= '<button type="button" data-cm="sort-new" class="on">Newest first</button>';
        $h .= '</div></div>';
        if ($modal) {
            $h .= '<button type="button" class="vs-cm-iconbtn" id="closeBtn" aria-label="Close comments">' . vs_comments_icon('close') . '</button>';
        }
        $h .= '</div>';

        $h .= '<div class="vs-cm-list" id="' . vs_comments_esc($listId) . '">';
        if ($comments) {
            foreach ($comments as $c) {
                $h .= vs_comments_item_html($c);
            }
        } else {
            $h .= '<div class="vs-cm-empty">No comments yet. Be the first one.</div>';
        }
        $h .= '</div>';

        $h .= '<div class="vs-cm-compose">';
        $h .= vs_comments_avatar_html($viewer, 'vs-cm-av-sm');
        $h .= '<input type="text" class="vs-cm-input" maxlength="500" placeholder="Add a comment..." autocomplete="off"' . ($disabled ? ' disabled' : '') . '>';
        $h .= '<button type="button" class="vs-cm-post" data-cm="post"' . ($disabled ? ' disabled' : '') . '>Comment</button>';
        $h .= '</div>';

        return $h . '</section>';
    }
}