<?php
require_once __DIR__ . '/layout.php';
if (is_file(__DIR__ . '/premium-perks.php')) {
    require_once __DIR__ . '/premium-perks.php';
}
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/subscription-store.php';
include __DIR__ . '/connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'vibestream');
}
$conn->set_charset('utf8mb4');

$viewer = trim((string)($_SESSION['username'] ?? ''));
$profileUser = '';
foreach (['u', 'user', 'username', 'channel'] as $param) {
    if (isset($_GET[$param]) && trim((string)$_GET[$param]) !== '') {
        $profileUser = trim((string)$_GET[$param]);
        break;
    }
}
if ($profileUser === '') {
    $profileUser = $viewer;
}
if ($profileUser === '') {
    header('Location: login.php');
    exit;
}
$isMe = $viewer !== '' && strcasecmp($profileUser, $viewer) === 0;
if ($isMe) {
    $profileUser = $viewer;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/** Channel description lives in its own small table (created on first use). The `username` column is renamed together with the user. */
function vs_channel_profile_ensure(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `channel_profiles` (
            `username` VARCHAR(100) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT \'\',
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

/**
 * Renames a user everywhere the old name is stored.
 * Returns '' on success, or an error message to show the user.
 */
function vs_rename_user(mysqli $conn, string $old, string $new): string
{
    $new = trim($new);
    if ($new === $old) {
        return 'That is already your name.';
    }
    if (!preg_match('/^[\p{L}\p{N}_.-]{3,30}$/u', $new)) {
        return 'Use 3-30 characters: letters, numbers, dot, dash or underscore (no spaces).';
    }
    $isAdmin = strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') === 0;
    if (!$isAdmin && in_array(mb_strtolower($new), ['admin', 'administrator', 'root', 'system', 'support', 'guest'], true)) {
        return 'That name is reserved. Please choose another one.';
    }

    // Every text column that stores a user name (found automatically).
    $nameColumns = ['username', 'user_name', 'uploader', 'channel', 'channel_name', 'subscriber', 'subscriber_name',
                    'author', 'owner', 'commenter', 'posted_by', 'created_by'];
    $in = implode(',', array_map(static fn($c) => "'" . $c . "'", $nameColumns));
    $sql = "SELECT c.TABLE_NAME, c.COLUMN_NAME
            FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND c.DATA_TYPE IN ('varchar','char','tinytext','text','mediumtext','longtext')
              AND c.COLUMN_NAME IN ($in)";
    $targets = [];
    $res = $conn->query($sql);
    if (!$res) {
        error_log('Rename: could not inspect tables: ' . $conn->error);
        return 'Could not change your name right now. Please try again later.';
    }
    while ($row = $res->fetch_row()) {
        $targets[] = [$row[0], $row[1]];
    }
    $res->close();
    if (!in_array(['users', 'username'], $targets, true)) {
        return 'Could not change your name right now. Please try again later.';
    }

    $q = static function (string $id): string { return '`' . str_replace('`', '``', $id) . '`'; };
    $run = static function (string $sql, string $a, string $b) use ($conn): int {
        $st = $conn->prepare($sql);
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ss', $a, $b);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException($err);
        }
        $n = $st->affected_rows;
        $st->close();
        return $n;
    };

    try {
        // Refuse a name that is already used anywhere (this also protects other people's uploads).
        foreach ($targets as [$table, $column]) {
            $st = $conn->prepare('SELECT 1 FROM ' . $q($table) . ' WHERE ' . $q($column) . ' = ? AND BINARY ' . $q($column) . ' <> BINARY ? LIMIT 1');
            if (!$st) {
                throw new RuntimeException($conn->error);
            }
            $st->bind_param('ss', $new, $old);
            $st->execute();
            $st->store_result();
            $taken = $st->num_rows > 0;
            $st->close();
            if ($taken) {
                return 'That name is already taken. Please choose another one.';
            }
        }

        $conn->begin_transaction();
        if ($run('UPDATE `users` SET `username` = ? WHERE `username` = ?', $new, $old) < 1) {
            $conn->rollback();
            return 'Could not find your account to update.';
        }
        foreach ($targets as [$table, $column]) {
            if ($table === 'users' && $column === 'username') {
                continue;
            }
            $run('UPDATE ' . $q($table) . ' SET ' . $q($column) . ' = ? WHERE ' . $q($column) . ' = ?', $new, $old);
        }
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Rename failed: ' . $e->getMessage());
        return 'Could not change your name right now. Please try again later.';
    }
    return '';
}

/** Keeps the profile picture and banner when a user is renamed (files are stored under a key made from the name). */
function vs_move_avatar(string $old, string $new): void
{
    if (avatar_key($old) === avatar_key($new)) {
        return;
    }
    $from = avatar_path($old);
    if ($from !== '' && is_file($from)) {
        $to = dirname($from) . '/' . avatar_key($new) . '.' . pathinfo($from, PATHINFO_EXTENSION);
        if (!@rename($from, $to)) {
            error_log('Could not move the avatar from ' . $from . ' to ' . $to);
        }
    }
    banner_move($old, $new);
}

if (!function_exists('thumb_for')) {
    function thumb_for(array $row, $src, $realYt) {
        if ($realYt) return "https://i.ytimg.com/vi/{$realYt}/hqdefault.jpg";
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $c) {
            if (!empty($row[$c])) return $row[$c];
        }
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#1e1e24"/><text x="50%" y="50%" fill="#6f6f7a" font-family="Arial" font-size="16" text-anchor="middle" dy=".3em">No preview</text></svg>');
    }
}
if (!function_exists('vs_short_views')) {
    function vs_short_views($n) {
        $n = (int)$n;
        if ($n >= 1000000000) { $v = $n / 1000000000; $u = 'B'; }
        elseif ($n >= 1000000) { $v = $n / 1000000; $u = 'M'; }
        elseif ($n >= 1000) { $v = $n / 1000; $u = 'K'; }
        else { return (string)$n; }
        $txt = $v >= 10 ? (string)round($v) : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        return $txt . $u;
    }
}
if (!function_exists('vs_yt_time_ago')) {
    function vs_yt_time_ago($datetime) {
        if (empty($datetime)) return '';
        $t = strtotime($datetime);
        if (!$t) return '';
        $diff = max(0, time() - $t);
        foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]] as $u) {
            if ($diff >= $u[1]) {
                $n = (int)floor($diff / $u[1]);
                return $n . ' ' . $u[0] . ($n === 1 ? '' : 's') . ' ago';
            }
        }
        return 'Just now';
    }
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'err', 'text' => 'That request was not allowed.'];
    $action = (string)($_POST['action'] ?? '');

    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        if ($isMe) {
            if ($action === 'avatar') {
                $err = avatar_save($viewer, $_FILES['avatar'] ?? null);
                $flash = $err === ''
                    ? ['type' => 'ok', 'text' => 'Avatar updated.']
                    : ['type' => 'err', 'text' => $err];

            } elseif ($action === 'banner') {
                $err = banner_save($viewer, $_FILES['banner'] ?? null);
                $flash = $err === ''
                    ? ['type' => 'ok', 'text' => 'Banner updated.']
                    : ['type' => 'err', 'text' => $err];

            } elseif ($action === 'banner_remove') {
                banner_delete($viewer);
                $flash = ['type' => 'ok', 'text' => 'Banner removed.'];

            } elseif ($action === 'description') {
                $desc = trim((string)($_POST['description'] ?? ''));
                $desc = (string)preg_replace('/(\r\n|\r|\n){3,}/', "\n\n", $desc);
                if (mb_strlen($desc) > 500) {
                    $flash = ['type' => 'err', 'text' => 'The description can be at most 500 characters.'];
                } else {
                    try {
                        vs_channel_profile_ensure($conn);
                        $st = $conn->prepare('INSERT INTO `channel_profiles` (`username`, `description`, `updated_at`) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `updated_at` = NOW()');
                        $st->bind_param('ss', $viewer, $desc);
                        $st->execute();
                        $st->close();
                        $flash = ['type' => 'ok', 'text' => 'Description saved.'];
                    } catch (Throwable $e) {
                        error_log('Description save failed: ' . $e->getMessage());
                        $flash = ['type' => 'err', 'text' => 'Could not save the description right now.'];
                    }
                }

            } elseif ($action === 'rename') {
                $newName = trim((string)($_POST['new_name'] ?? ''));
                $err = vs_rename_user($conn, $viewer, $newName);
                if ($err === '') {
                    vs_move_avatar($viewer, $newName);
                    foreach (['username', 'UserSession', 'AdminSession'] as $sessionKey) {
                        if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === $viewer) {
                            $_SESSION[$sessionKey] = $newName;
                        }
                    }
                    $flash = ['type' => 'ok', 'text' => 'Your name was changed to ' . $newName . '.'];
                } else {
                    $flash = ['type' => 'err', 'text' => $err];
                }
            }
        } elseif ($action === 'subscribe' || $action === 'unsubscribe') {
            if ($viewer === '') {
                header('Location: login.php');
                exit;
            }
            $exists = false;
            if ($st = $conn->prepare('SELECT 1 FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1')) {
                $st->bind_param('s', $profileUser);
                $st->execute();
                $st->store_result();
                $exists = $st->num_rows > 0;
                $st->close();
            }
            if (!$exists) {
                $flash = ['type' => 'err', 'text' => 'This channel was not found.'];
            } else {
                try {
                    vs_subscription_change($conn, $profileUser, $profileUser, '', $action === 'subscribe');
                    $flash = ['type' => 'ok', 'text' => ($action === 'subscribe' ? 'Subscribed to ' : 'Unsubscribed from ') . '@' . $profileUser . '.'];
                } catch (Throwable $e) {
                    $flash = ['type' => 'err', 'text' => $e->getMessage()];
                }
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: profile.php' . ($isMe ? '' : '?u=' . urlencode($profileUser)));
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Load the channel ---------- */
$accountFound = true;
if (!$isMe) {
    $accountFound = false;
    if ($st = $conn->prepare('SELECT `username` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1')) {
        $st->bind_param('s', $profileUser);
        $st->execute();
        $st->bind_result($foundName);
        if ($st->fetch()) {
            $accountFound = true;
            $profileUser = (string)$foundName;
        }
        $st->close();
    }
}

$videos = [];
$shorts = [];
if ($accountFound) {
    $vCols = [];
    if ($r = $conn->query('SHOW COLUMNS FROM `videos`')) {
        while ($c = $r->fetch_assoc()) {
            $vCols[strtolower($c['Field'])] = true;
        }
        $r->close();
    }
    $uploaderCol = '';
    foreach (['username', 'channel_name', 'uploader'] as $cand) {
        if (isset($vCols[$cand])) { $uploaderCol = $cand; break; }
    }
    if ($uploaderCol !== '') {
        $order = isset($vCols['created_at']) ? 'created_at DESC' : 'id DESC';
        $st = $conn->prepare("SELECT * FROM `videos` WHERE LOWER(`$uploaderCol`) = LOWER(?) ORDER BY $order");
        if ($st) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (isset($row['type']) && strtolower((string)$row['type']) === 'short') {
                    $shorts[] = $row;
                } else {
                    $videos[] = $row;
                }
            }
            $st->close();
        }
    }
}

$totalViews = 0;
$totalLikes = 0;
foreach (array_merge($videos, $shorts) as $row) {
    $totalViews += (int)($row['views'] ?? 0);
    $totalLikes += (int)($row['likes'] ?? 0);
}
$defaultTab = (count($videos) === 0 && count($shorts) > 0) ? 'shorts' : 'videos';

// "Popular" = most views, likes and comments together. A like counts 5 views and a comment counts 10 views.
$commentCounts = [];
$allIds = array_map(static fn($r) => (string)$r['id'], array_merge($videos, $shorts));
if ($allIds) {
    try {
        $marks = implode(',', array_fill(0, count($allIds), '?'));
        $st = $conn->prepare("SELECT `video_id`, COUNT(*) FROM `comments` WHERE `video_id` IN ($marks) GROUP BY `video_id`");
        $st->bind_param(str_repeat('s', count($allIds)), ...$allIds);
        $st->execute();
        foreach ($st->get_result()->fetch_all(MYSQLI_NUM) as $cr) {
            $commentCounts[(string)$cr[0]] = (int)$cr[1];
        }
        $st->close();
    } catch (Throwable $e) {
        error_log('Profile comment counts unavailable: ' . $e->getMessage());
    }
}
$popScore = static function (array $row) use ($commentCounts): int {
    return (int)($row['views'] ?? 0) + 5 * (int)($row['likes'] ?? 0) + 10 * (int)($commentCounts[(string)$row['id']] ?? 0);
};
$avatarUrl = $accountFound ? avatar_url($profileUser) : '';
$bannerUrl = $accountFound ? banner_url($profileUser) : '';

$description = '';
$joinedAt = '';
if ($accountFound) {
    try {
        vs_channel_profile_ensure($conn);
        if ($st = $conn->prepare('SELECT `description` FROM `channel_profiles` WHERE `username` = ? LIMIT 1')) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            $r = $st->get_result()->fetch_row();
            $description = $r ? (string)$r[0] : '';
            $st->close();
        }
        if ($st = $conn->prepare('SELECT `created_at` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1')) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            $r = $st->get_result()->fetch_row();
            $joinedAt = $r ? (string)$r[0] : '';
            $st->close();
        }
    } catch (Throwable $e) {
        error_log('Profile extras unavailable: ' . $e->getMessage());
    }
}

$isSubscribed = false;
$subscriberCount = null;
if ($accountFound) {
    if (!$isMe) {
        try {
            vs_subscription_session_sync($conn);
            $isSubscribed = in_array(vs_subscription_key($profileUser), $_SESSION['subscribed_channels'] ?? [], true);
        } catch (Throwable $e) {
            error_log('Profile subscriptions unavailable: ' . $e->getMessage());
        }
    }
    if ($isMe && ($uploaderCol ?? '') !== '' && isset($vCols['subscribers'])) {
        $st = $conn->prepare("SELECT COALESCE(MAX(`subscribers`), 0) FROM `videos` WHERE LOWER(`$uploaderCol`) = LOWER(?)");
        if ($st) {
            $st->bind_param('s', $profileUser);
            if ($st->execute()) {
                $subscriberCount = (int)$st->get_result()->fetch_row()[0];
            }
            $st->close();
        }
    }
}
$h = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$extraCss = <<<'CSS'
.profile-page { max-width: 1284px; margin: 0 auto; padding: 16px 24px 56px; }
body { overflow-x: clip; }
.flash { margin: 0 0 18px; padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 600; }
.flash.ok  { background: rgba(60, 170, 90, 0.15); border: 1px solid rgba(60, 170, 90, 0.45); color: #2e9e5b; }
.flash.err { background: rgba(220, 60, 60, 0.15); border: 1px solid rgba(220, 60, 60, 0.45); color: #d94a4a; }
.channel-banner {
    position: relative; overflow: hidden;
    width: 100%; margin: 0 0 16px; border-radius: 16px;
    aspect-ratio: 6.2 / 1; min-height: 96px; max-height: 220px;
    background: linear-gradient(120deg, #23253a, #1b2436 45%, #2c2140);
}
.channel-banner img { width: 100%; height: 100%; object-fit: cover; display: block; }
.banner-actions { position: absolute; right: 16px; bottom: 12px; display: flex; gap: 8px; z-index: 5; }
.banner-actions form { margin: 0; }
.banner-actions .btn { cursor: pointer; pointer-events: auto; }
.banner-actions .btn { background: #ffffff; color: #0f0f0f; border: 1px solid rgba(255, 255, 255, 0.7); box-shadow: 0 2px 12px rgba(0, 0, 0, 0.5); }
.banner-actions .btn:hover { background: #e9e9ee; filter: none; }
.banner-actions .btn svg { color: #0f0f0f; }
.channel-head { display: flex; flex-wrap: wrap; align-items: center; gap: 24px; }
.channel-avatar-lg {
    width: 160px; height: 160px; border-radius: 50%; object-fit: cover; flex: 0 0 auto;
    background: var(--surface-2); display: flex; align-items: center; justify-content: center;
    font-size: 64px; font-weight: 700; color: var(--text);
}
.channel-info { flex: 1 1 320px; min-width: 0; }
.channel-name { margin: 0 0 4px; font-size: 36px; line-height: 1.2; font-weight: 700; word-break: break-word; }
.channel-meta { color: var(--muted); font-size: 14px; line-height: 1.6; margin: 0 0 14px; }
.channel-handle { color: var(--text); font-weight: 600; }
.channel-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.btn {
    display: inline-flex; align-items: center; gap: 6px; border: 0; cursor: pointer;
    background: var(--surface-2); color: var(--text); height: 36px; padding: 0 16px; border-radius: 999px;
    font-size: 14px; font-weight: 600; white-space: nowrap;
}
.btn:hover { filter: brightness(0.96); }
.btn.primary { background: var(--accent); color: #ffffff; }
.btn.subscribe { background: var(--text); color: var(--bg); }
.btn svg { flex: 0 0 auto; }
.name-form {
    display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 18px 0 0;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 18px 22px;
}
.name-form[hidden] { display: none; }
.name-form input[type=text] {
    flex: 1 1 220px; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--border);
    background: var(--bg); color: var(--text);
}
.name-form small { flex: 1 1 100%; color: var(--muted); }
.name-form textarea {
    flex: 1 1 100%; min-height: 90px; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--border);
    background: var(--bg); color: var(--text); font: inherit; resize: vertical;
}
.channel-desc { margin: 0 0 14px; font-size: 14px; line-height: 1.5; max-width: 760px; white-space: pre-line; }
.link-more { background: none; border: 0; padding: 0; margin-left: 4px; color: var(--muted); font: inherit; font-weight: 600; cursor: pointer; }
.link-more:hover { color: var(--text); }
.sort-bar { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 18px; }
.chip {
    border: 0; cursor: pointer; height: 32px; padding: 0 14px; border-radius: 8px;
    background: var(--surface-2); color: var(--text); font-size: 14px; font-weight: 600;
}
.chip.active { background: var(--text); color: var(--bg); }
.about-box { max-width: 720px; }
.about-box h2 { margin: 0 0 10px; font-size: 18px; }
.about-desc { margin: 0 0 24px; font-size: 14px; line-height: 1.6; white-space: pre-line; }
.about-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px 24px; margin: 0; }
.about-list div { border-top: 1px solid var(--border); padding-top: 10px; }
.about-list dt { color: var(--muted); font-size: 13px; margin: 0 0 2px; }
.about-list dd { margin: 0; font-size: 16px; font-weight: 600; }
.tabs { display: flex; gap: 0; margin: 16px 0 0; border-bottom: 1px solid var(--border); overflow-x: auto; }
.tab {
    background: none; border: 0; border-bottom: 2px solid transparent; padding: 0 16px; height: 48px; cursor: pointer;
    font-size: 14px; font-weight: 600; color: var(--muted); white-space: nowrap;
}
.tab:hover { color: var(--text); }
.tab.active { color: var(--text); border-bottom-color: var(--text); }
.tab .count { margin-left: 6px; font-weight: 400; color: var(--muted); font-size: 13px; }
.tab-panel { display: none; padding-top: 20px; }
.tab-panel.active { display: block; }
.video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 24px 16px; }
.short-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 24px 14px; }
.p-thumb { position: relative; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: var(--surface-2); }
.p-thumb img, .p-short-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.p-short-thumb { aspect-ratio: 9 / 16; border-radius: 12px; overflow: hidden; background: var(--surface-2); }
.p-duration { position: absolute; right: 8px; bottom: 8px; background: rgba(0,0,0,.8); color: #fff; font-size: 12px; padding: 2px 6px; border-radius: 6px; }
.p-title { margin: 10px 0 4px; font-size: 14px; font-weight: 600; line-height: 1.4; }
.p-meta { margin: 0; color: var(--muted); font-size: 12px; }
.empty-state {
    text-align: center; padding: 56px 16px; color: var(--muted);
    border: 1px dashed var(--border); border-radius: 20px;
}
.empty-state .btn { margin-top: 16px; }
@media (max-width: 760px) {
    .profile-page { padding: 12px 12px 48px; }
    .channel-banner { margin-bottom: 14px; border-radius: 12px; aspect-ratio: 16 / 4; }
    .banner-actions { right: 10px; bottom: 10px; }
    .channel-head { gap: 16px; }
    .channel-avatar-lg { width: 80px; height: 80px; font-size: 34px; }
    .channel-name { font-size: 24px; }
}
/* ===== CHANNEL LAYOUT (profile redesign) ===== */
.channel-banner { max-height: 260px; margin-bottom: 0; }
.channel-head { align-items: flex-start; gap: 26px; margin-top: -68px; padding: 0 12px; position: relative; z-index: 2; }
.channel-avatar-lg { width: 168px; height: 168px; border: 5px solid var(--bg); position: relative; z-index: 2; }
.channel-info { padding-top: 78px; flex: 1 1 420px; }
.channel-name { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.channel-handle-line { margin: 0 0 8px; color: var(--muted); font-size: 15px; }
.channel-stats { display: flex; flex-wrap: wrap; gap: 14px 30px; margin: 4px 0 8px; }
.channel-stats-note { display: block; margin: 0 0 16px; color: var(--muted); font-size: 12px; }
.ch-stat { display: flex; align-items: center; gap: 10px; min-width: 0; }
.ch-stat svg { width: 26px; height: 26px; flex: 0 0 auto; color: var(--muted); }
.ch-stat b { display: block; font-size: 22px; line-height: 1.1; font-weight: 700; }
.ch-stat span { display: block; font-size: 13px; color: var(--muted); }
.tabs { gap: 8px; padding: 8px; margin: 10px 0 0; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); }
.tab { display: inline-flex; align-items: center; gap: 8px; height: 44px; padding: 0 20px; border: 0; border-radius: 12px; }
.tab svg { width: 18px; height: 18px; flex: 0 0 auto; }
.tab.active { background: linear-gradient(135deg, #6d5ffd, #4a3fe0); color: #fff; border-bottom-color: transparent; }
.tab.active .count { color: rgba(255,255,255,.8); }
.panel-cols { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; }
.panel-main { min-width: 0; }
.about-side { border: 1px solid var(--border); border-radius: 18px; background: var(--surface); padding: 20px; position: sticky; top: calc(var(--navbar-height) + 14px); }
.about-side h2 { margin: 0 0 10px; font-size: 18px; }
.about-side .about-desc { margin: 0 0 16px; font-size: 14px; line-height: 1.55; }
.about-side .about-list { grid-template-columns: 1fr 1fr; gap: 10px 16px; }
.about-side .about-list dd { font-size: 15px; }
.p-card { display: block; transition: transform .18s ease; }
.p-card:hover { transform: translateY(-3px); }
.p-thumb, .p-short-thumb { border-radius: 14px; }
.video-grid { grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); }
@media (max-width: 1100px) { .panel-cols { grid-template-columns: minmax(0, 1fr); } .about-side { position: static; } }
@media (max-width: 760px) {
    .channel-head { margin-top: -44px; gap: 14px; padding: 0 4px; }
    .channel-avatar-lg { width: 92px; height: 92px; border-width: 4px; }
    .channel-info { padding-top: 52px; flex-basis: 100%; }
    .ch-stat b { font-size: 18px; }
    .tab { padding: 0 14px; }
}
@media (prefers-reduced-motion: reduce) { .p-card { transition: none; } .p-card:hover { transform: none; } }
/* ===== NEON THEME (dark + light) ===== */
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2e93; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #00e5ff;
            --neon-cyan: #00e5ff; --neon-pink: #ff2e93; --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) body .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(0,229,255,.35); box-shadow: 0 1px 24px -4px rgba(0,229,255,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) body .brand { background: linear-gradient(90deg, #00e5ff, #8a5cff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) body .nav-toggle span { background: #00e5ff; box-shadow: 0 0 6px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) body .nav-toggle:hover, html:not([data-theme="light"]) body .theme-toggle:hover { background: rgba(0,229,255,.14); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) body .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) body .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) body .create-btn, html:not([data-theme="light"]) body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) body #search-input:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 20px rgba(0,229,255,.55); }
        html:not([data-theme="light"]) body .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) body .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) body .sidebar-link:hover { background: rgba(0,229,255,.10); box-shadow: inset 3px 0 0 #00e5ff, 0 0 16px -4px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body .sidebar-heading { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #8a5cff, #ff2e93); border-radius: 8px; }
        html:not([data-theme="light"]) body ::selection { background: rgba(255,46,147,.4); color: #fff; }

/* ---- profile page ---- */
html:not([data-theme="light"]) body .channel-banner { border: 1px solid rgba(138,92,255,.55); box-shadow: 0 0 30px -6px rgba(0,229,255,.5), 0 0 70px -20px rgba(255,46,147,.45); }
html:not([data-theme="light"]) body .channel-avatar-lg { box-shadow: 0 0 0 3px rgba(255,46,147,.85), 0 0 22px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .channel-name { text-shadow: 0 0 16px rgba(138,92,255,.6); }
html:not([data-theme="light"]) body .channel-handle { color: #00e5ff; }
html:not([data-theme="light"]) body .btn { transition: box-shadow .18s ease, transform .18s ease, filter .18s ease; }
html:not([data-theme="light"]) body .btn:hover { transform: translateY(-1px); }
html:not([data-theme="light"]) body .btn.primary { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 18px rgba(255,46,147,.5); }
html:not([data-theme="light"]) body .btn.subscribe { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; box-shadow: 0 0 18px rgba(255,46,147,.5); }
html:not([data-theme="light"]) body .banner-actions .btn { background: rgba(5,6,15,.8); color: #fff; border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 14px rgba(0,229,255,.45); }
html:not([data-theme="light"]) body .banner-actions .btn svg { color: #00e5ff; }
html:not([data-theme="light"]) body .name-form input[type=text], html:not([data-theme="light"]) body .name-form textarea { background: rgba(10,13,31,.9); color: #eaf0ff; border: 1px solid rgba(138,92,255,.5); }
html:not([data-theme="light"]) body .name-form input[type=text]:focus, html:not([data-theme="light"]) body .name-form textarea:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 16px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .chip { background: rgba(23,28,66,.7); color: #eaf0ff; border: 1px solid rgba(138,92,255,.35); transition: box-shadow .18s ease, border-color .18s ease; }
html:not([data-theme="light"]) body .chip:hover { border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .chip.active { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border-color: transparent; box-shadow: 0 0 18px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .tabs { border-bottom: 1px solid rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .tab:hover { color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.6); }
html:not([data-theme="light"]) body .tab.active { color: #00e5ff; border-bottom-color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.6); box-shadow: 0 8px 14px -10px rgba(0,229,255,.9); }
html:not([data-theme="light"]) body .p-thumb, html:not([data-theme="light"]) body .p-short-thumb { border: 1px solid rgba(138,92,255,.28); transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease; }
html:not([data-theme="light"]) body a:hover .p-thumb, html:not([data-theme="light"]) body a:hover .p-short-thumb { border-color: #00e5ff; box-shadow: 0 0 22px rgba(0,229,255,.55), 0 0 44px -8px rgba(138,92,255,.5); transform: translateY(-3px); }
html:not([data-theme="light"]) body a:hover .p-title { color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .p-duration { background: rgba(5,6,15,.88); color: #00e5ff; border: 1px solid rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .about-list div { border-top: 1px solid rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .flash.ok { box-shadow: 0 0 18px -4px rgba(60,220,130,.55); }
html:not([data-theme="light"]) body .flash.err { box-shadow: 0 0 18px -4px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .empty-state { background: rgba(10,13,31,.88); border: 1px dashed rgba(0,229,255,.5); box-shadow: 0 0 24px -8px rgba(0,229,255,.4); }

html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2e93; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #00b8e6;
            --neon-cyan: #00b8e6; --neon-pink: #ff2e93; --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] body .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(0,184,230,.55); box-shadow: 0 1px 22px -4px rgba(0,184,230,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] body .brand { background: linear-gradient(90deg, #00b8e6, #7c4dff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] body .nav-toggle span { background: #7c4dff; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] body .nav-toggle:hover, html[data-theme="light"] body .theme-toggle:hover { background: rgba(0,184,230,.14); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] body .theme-toggle svg { color: #7c4dff; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] body .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] body .create-btn, html[data-theme="light"] body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] body #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] body #search-input:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 20px rgba(0,184,230,.55); }
        html[data-theme="light"] body .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] body .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] body .sidebar-link:hover { background: rgba(0,184,230,.12); box-shadow: inset 3px 0 0 #00b8e6, 0 0 16px -4px rgba(0,184,230,.5); }
        html[data-theme="light"] body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] body .sidebar-heading { color: #7c4dff; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] body ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #7c4dff, #ff2e93); border-radius: 8px; }
        html[data-theme="light"] body ::selection { background: rgba(255,46,147,.4); color: #0b1030; }

/* ---- profile page ---- */
html[data-theme="light"] body .channel-banner { border: 1px solid rgba(124,77,255,.55); box-shadow: 0 0 30px -6px rgba(0,184,230,.5), 0 0 70px -20px rgba(255,46,147,.45); }
html[data-theme="light"] body .channel-avatar-lg { box-shadow: 0 0 0 3px rgba(255,46,147,.85), 0 0 22px rgba(255,46,147,.6); }
html[data-theme="light"] body .channel-name { text-shadow: 0 0 16px rgba(124,77,255,.3); }
html[data-theme="light"] body .channel-handle { color: #0086b3; }
html[data-theme="light"] body .btn { transition: box-shadow .18s ease, transform .18s ease, filter .18s ease; }
html[data-theme="light"] body .btn:hover { transform: translateY(-1px); }
html[data-theme="light"] body .btn.primary { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 18px rgba(255,46,147,.5); }
html[data-theme="light"] body .btn.subscribe { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; box-shadow: 0 0 18px rgba(255,46,147,.5); }
html[data-theme="light"] body .banner-actions .btn { background: rgba(5,6,15,.8); color: #fff; border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 14px rgba(0,184,230,.45); }
html[data-theme="light"] body .banner-actions .btn svg { color: #00b8e6; }
html[data-theme="light"] body .name-form input[type=text], html[data-theme="light"] body .name-form textarea { background: #ffffff; color: #0b1030; border: 1px solid rgba(124,77,255,.5); }
html[data-theme="light"] body .name-form input[type=text]:focus, html[data-theme="light"] body .name-form textarea:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 16px rgba(0,184,230,.5); }
html[data-theme="light"] body .chip { background: #ffffff; color: #0b1030; border: 1px solid rgba(124,77,255,.35); transition: box-shadow .18s ease, border-color .18s ease; }
html[data-theme="light"] body .chip:hover { border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
html[data-theme="light"] body .chip.active { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border-color: transparent; box-shadow: 0 0 18px rgba(255,46,147,.55); }
html[data-theme="light"] body .tabs { border-bottom: 1px solid rgba(124,77,255,.45); }
html[data-theme="light"] body .tab:hover { color: #0086b3; text-shadow: 0 0 10px rgba(0,184,230,.6); }
html[data-theme="light"] body .tab.active { color: #0086b3; border-bottom-color: #00b8e6; text-shadow: 0 0 10px rgba(0,184,230,.6); box-shadow: 0 8px 14px -10px rgba(0,184,230,.9); }
html[data-theme="light"] body .p-thumb, html[data-theme="light"] body .p-short-thumb { border: 1px solid rgba(124,77,255,.28); transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease; }
html[data-theme="light"] body a:hover .p-thumb, html[data-theme="light"] body a:hover .p-short-thumb { border-color: #00b8e6; box-shadow: 0 0 22px rgba(0,184,230,.55), 0 0 44px -8px rgba(124,77,255,.5); transform: translateY(-3px); }
html[data-theme="light"] body a:hover .p-title { color: #0086b3; text-shadow: 0 0 10px rgba(0,184,230,.5); }
html[data-theme="light"] body .p-duration { background: rgba(5,6,15,.88); color: #7defff; border: 1px solid rgba(0,184,230,.5); }
html[data-theme="light"] body .about-list div { border-top: 1px solid rgba(124,77,255,.45); }
html[data-theme="light"] body .flash.ok { box-shadow: 0 0 18px -4px rgba(60,220,130,.55); }
html[data-theme="light"] body .flash.err { box-shadow: 0 0 18px -4px rgba(255,46,147,.55); }
html[data-theme="light"] body .empty-state { background: #ffffff; border: 1px dashed rgba(0,184,230,.5); box-shadow: 0 0 24px -8px rgba(0,184,230,.4); }
html:not([data-theme="light"]) body .channel-avatar-lg { border-color: #05060f; box-shadow: 0 0 0 3px #8a5cff, 0 0 26px rgba(0,229,255,.55), 0 0 60px -10px rgba(255,46,147,.5); }
html:not([data-theme="light"]) body .channel-name { text-shadow: 0 0 18px rgba(138,92,255,.55); }
html:not([data-theme="light"]) body .ch-stat svg { color: #8a5cff; filter: drop-shadow(0 0 6px rgba(138,92,255,.7)); }
html:not([data-theme="light"]) body .tabs { border-color: rgba(138,92,255,.45); background: rgba(10,13,31,.85); box-shadow: 0 0 22px -10px rgba(138,92,255,.6); }
html:not([data-theme="light"]) body .tab.active { background: linear-gradient(135deg, #8a5cff, #5b3fe8); box-shadow: 0 0 18px rgba(138,92,255,.7); }
html:not([data-theme="light"]) body .about-side { border-color: rgba(138,92,255,.45); background: linear-gradient(180deg, rgba(14,18,44,.88), rgba(8,10,28,.94)); box-shadow: 0 0 26px -10px rgba(138,92,255,.55); }
html:not([data-theme="light"]) body .p-card:hover .p-thumb { box-shadow: 0 0 22px -4px rgba(0,229,255,.6); }
html[data-theme="light"] body .channel-avatar-lg { border-color: #f4f6ff; box-shadow: 0 0 0 3px #7c4dff, 0 0 22px rgba(0,184,230,.45), 0 0 50px -10px rgba(255,46,147,.4); }
html[data-theme="light"] body .ch-stat svg { color: #7c4dff; }
html[data-theme="light"] body .tabs { border-color: rgba(124,77,255,.45); background: #ffffff; box-shadow: 0 0 20px -10px rgba(124,77,255,.5); }
html[data-theme="light"] body .tab.active { background: linear-gradient(135deg, #7c4dff, #5b3fe8); box-shadow: 0 0 16px rgba(124,77,255,.55); }
html[data-theme="light"] body .about-side { border-color: rgba(124,77,255,.45); background: #ffffff; box-shadow: 0 0 22px -10px rgba(124,77,255,.45); }
html[data-theme="light"] body .p-card:hover .p-thumb { box-shadow: 0 0 20px -4px rgba(0,184,230,.55); }
CSS;

vs_page_start($accountFound ? '@' . $profileUser : 'Channel', 'profile', $extraCss);
?>
<div class="profile-page">
<?php if ($flash): ?>
    <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash['text']) ?></div>
<?php endif; ?>

<?php if (!$accountFound): ?>
    <div class="empty-state">
        <p>This channel was not found.</p>
        <a class="btn primary" href="index.php">Go home</a>
    </div>
<?php else: ?>
    <?php if ($isMe): ?>
        <form method="post" action="profile.php" enctype="multipart/form-data" id="bannerForm" style="display:none;">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="banner">
            <input type="file" name="banner" id="bannerFile" accept=".jpg,.jpeg,.png,.webp">
        </form>
    <?php endif; ?>
    <section class="channel-banner">
        <?php if ($bannerUrl !== ''): ?>
            <img src="<?= $h($bannerUrl) ?>" alt="<?= $h($profileUser) ?> banner">
        <?php endif; ?>
        <?php if ($isMe): ?>
            <div class="banner-actions">
                <label class="btn" for="bannerFile"><?= vs_icon('plus-square', 16) ?> <?= $bannerUrl !== '' ? 'Change banner' : 'Add banner' ?></label>
                <?php if ($bannerUrl !== ''): ?>
                    <form method="post" action="profile.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="banner_remove">
                        <button type="submit" class="btn">Remove banner</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="channel-head">
        <?php if ($avatarUrl !== ''): ?>
            <img class="channel-avatar-lg" src="<?= $h($avatarUrl) ?>" alt="<?= $h($profileUser) ?> avatar">
        <?php else: ?>
            <div class="channel-avatar-lg"><?= $h(mb_strtoupper(mb_substr($profileUser, 0, 1))) ?></div>
        <?php endif; ?>

        <div class="channel-info">
            <h1 class="channel-name"><?= $h($profileUser) ?><?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html($profileUser) : ''; ?></h1>
            <p class="channel-handle-line"><span class="channel-handle">@<?= $h($profileUser) ?></span></p>
            <?php if ($description !== ''): ?>
                <?php $descShort = mb_strlen($description) > 140 ? mb_substr($description, 0, 140) . '…' : $description; ?>
                <p class="channel-desc"><?= $h($descShort) ?><?php if ($descShort !== $description): ?><button type="button" class="link-more" data-goto="about">more</button><?php endif; ?></p>
            <?php endif; ?>

            <div class="channel-stats" aria-label="Public creator statistics">
                <div class="ch-stat"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg><div><b><?= $h(vs_short_views(count($videos))) ?></b><span>Videos</span></div></div>
                <div class="ch-stat"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2 4 14h6.5L9.5 22 20 9.5h-6.8z"/></svg><div><b><?= $h(vs_short_views(count($shorts))) ?></b><span>Shorts</span></div></div>
                <?php if ($isMe && $subscriberCount !== null): ?>
                <div class="ch-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/></svg><div><b><?= $h(vs_short_views($subscriberCount)) ?></b><span>Subscribers</span></div></div>
                <?php endif; ?>
                <div class="ch-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg><div><b><?= $h(vs_short_views($totalViews)) ?></b><span>Views</span></div></div>
                <?php if ($isMe): ?><div class="ch-stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.3-9.2C1.3 8.3 3.4 5 6.7 5c2 0 3.6 1.1 5.3 3 1.7-1.9 3.3-3 5.3-3 3.3 0 5.4 3.3 4 6.8C19.5 16.4 12 21 12 21z"/></svg><div><b><?= $h(vs_short_views($totalLikes)) ?></b><span>Likes</span></div></div><?php endif; ?>
            </div>
            <small class="channel-stats-note">Public totals are calculated from this channel’s uploaded videos and Shorts.</small>
            <?php if (!$isMe): ?>
                <div class="channel-actions">
                    <form method="post" action="profile.php?u=<?= urlencode($profileUser) ?>" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="<?= $isSubscribed ? 'unsubscribe' : 'subscribe' ?>">
                        <button type="submit" class="btn<?= $isSubscribed ? '' : ' subscribe' ?>"<?= $isSubscribed ? ' title="Click to unsubscribe"' : '' ?>><?php if ($isSubscribed): ?><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg> Subscribed<?php else: ?>Subscribe<?php endif; ?></button>
                    </form>
                    <button type="button" class="btn" id="shareChannel" data-u="<?= $h($profileUser) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.6l6.8-4M8.6 13.4l6.8 4"/></svg> Share channel</button>
                </div>
            <?php endif; ?>

            <?php if ($isMe): ?>
                <div class="channel-actions">
                    <form method="post" action="profile.php" enctype="multipart/form-data" id="avatarForm" style="display:none;">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="avatar">
                        <input type="file" name="avatar" id="avatarFile" accept=".jpg,.jpeg,.png,.webp,.gif">
                    </form>
                    <label class="btn" for="avatarFile"><?= vs_icon('contact', 16) ?> Change avatar</label>
                    <button type="button" class="btn" id="nameToggle"><?= vs_icon('contact', 16) ?> Change name</button>
                    <button type="button" class="btn" id="descToggle">Edit description</button>
                    <a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload</a>
                    <button type="button" class="btn" id="shareChannel" data-u="<?= $h($profileUser) ?>"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.6l6.8-4M8.6 13.4l6.8 4"/></svg> Share channel</button>
                    <a class="btn" href="logout.php">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($isMe): ?>
        <form class="name-form" id="nameForm" method="post" action="profile.php" hidden>
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <label for="newName">New name</label>
            <input type="text" id="newName" name="new_name" value="<?= $h($profileUser) ?>" minlength="3" maxlength="30" required autocomplete="off">
            <button type="submit" class="btn primary">Save</button>
            <button type="button" class="btn" id="nameCancel">Cancel</button>
            <small>3-30 characters: letters, numbers, dot, dash or underscore. Your videos, shorts and channel will move to the new name.</small>
        </form>
    <?php endif; ?>

    <?php if ($isMe): ?>
        <form class="name-form" id="descForm" method="post" action="profile.php" hidden>
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="description">
            <label for="descText">About your channel</label>
            <textarea id="descText" name="description" maxlength="500" placeholder="Tell people what your channel is about"><?= $h($description) ?></textarea>
            <button type="submit" class="btn primary">Save</button>
            <button type="button" class="btn" id="descCancel">Cancel</button>
            <small>Up to 500 characters. It shows under your name and on the About tab.</small>
        </form>
    <?php endif; ?>

    <div class="tabs" role="tablist">
        <button type="button" class="tab<?= $defaultTab === 'videos' ? ' active' : '' ?>" role="tab" data-tab="videos"><svg class="tab-ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>Videos<span class="count"><?= count($videos) ?></span></button>
        <button type="button" class="tab<?= $defaultTab === 'shorts' ? ' active' : '' ?>" role="tab" data-tab="shorts"><svg class="tab-ico" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2 4 14h6.5L9.5 22 20 9.5h-6.8z"/></svg>Shorts<span class="count"><?= count($shorts) ?></span></button>
        <button type="button" class="tab" role="tab" data-tab="about"><svg class="tab-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>About</button>
    </div>

    <div class="tab-panel<?= $defaultTab === 'videos' ? ' active' : '' ?>" data-panel="videos">
      <div class="panel-cols"><div class="panel-main">
        <?php if (!$videos): ?>
            <div class="empty-state">
                <p>No videos yet.</p>
                <?php if ($isMe): ?><a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload your first video</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="sort-bar" data-sort-for="videoGrid">
                <button type="button" class="chip active" data-sort="latest">Latest</button>
                <button type="button" class="chip" data-sort="popular">Popular</button>
                <button type="button" class="chip" data-sort="oldest">Oldest</button>
            </div>
            <div class="video-grid" id="videoGrid">
                <?php foreach ($videos as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $thumb = thumb_for($row, $src, $yt);
                    $title = $row['title'] ?? 'Untitled';
                    $duration = $row['duration'] ?? '';
                ?>
                    <a href="watch.php?id=<?= urlencode((string)$row['id']) ?>" class="p-card" data-views="<?= (int)($row['views'] ?? 0) ?>" data-score="<?= $popScore($row) ?>" data-ts="<?= (int)strtotime((string)($row['created_at'] ?? '')) ?>">
                        <div class="p-thumb">
                            <img src="<?= $h($thumb) ?>" alt="<?= $h($title) ?>" loading="lazy">
                            <?php if (!empty($duration)): ?><span class="p-duration"><?= $h($duration) ?></span><?php endif; ?>
                        </div>
                        <h3 class="p-title"><?= $h($title) ?></h3>
                        <p class="p-meta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views &bull; <?= $h(vs_yt_time_ago($row['created_at'] ?? null)) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
      </div>
      <aside class="about-side" aria-label="About this channel">
        <h2>About</h2>
        <p class="about-desc"><?php if ($description !== ''): ?><?= $h($description) ?><?php else: ?><span style="color:var(--muted)"><?= $isMe ? 'Add a description with "Edit description".' : 'No description yet.' ?></span><?php endif; ?></p>
        <dl class="about-list">
            <?php if ($joinedAt !== '' && strtotime($joinedAt)): ?><div><dt>Joined</dt><dd><?= $h(date('M j, Y', strtotime($joinedAt))) ?></dd></div><?php endif; ?>
            <?php if ($isMe && $subscriberCount !== null): ?><div><dt>Subscribers</dt><dd><?= $h(vs_short_views($subscriberCount)) ?></dd></div><?php endif; ?>
            <div><dt>Total views</dt><dd><?= $h(vs_short_views($totalViews)) ?></dd></div>
            <?php if ($isMe): ?><div><dt>Total likes</dt><dd><?= $h(vs_short_views($totalLikes)) ?></dd></div><?php endif; ?>
        </dl>
      </aside>
      </div>
    </div>

    <div class="tab-panel<?= $defaultTab === 'shorts' ? ' active' : '' ?>" data-panel="shorts">
        <?php if (!$shorts): ?>
            <div class="empty-state">
                <p>No shorts yet.</p>
                <?php if ($isMe): ?><a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload a short</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="sort-bar" data-sort-for="shortGrid">
                <button type="button" class="chip active" data-sort="latest">Latest</button>
                <button type="button" class="chip" data-sort="popular">Popular</button>
                <button type="button" class="chip" data-sort="oldest">Oldest</button>
            </div>
            <div class="short-grid" id="shortGrid">
                <?php foreach ($shorts as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $thumb = thumb_for($row, $src, $yt);
                    $title = $row['title'] ?? 'Untitled';
                ?>
                    <a href="shorts.php?id=<?= urlencode((string)$row['id']) ?>" class="p-card" data-views="<?= (int)($row['views'] ?? 0) ?>" data-score="<?= $popScore($row) ?>" data-ts="<?= (int)strtotime((string)($row['created_at'] ?? '')) ?>">
                        <div class="p-short-thumb"><img src="<?= $h($thumb) ?>" alt="<?= $h($title) ?>" loading="lazy"></div>
                        <h3 class="p-title"><?= $h($title) ?></h3>
                        <p class="p-meta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views</p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-panel" data-panel="about">
        <div class="about-box">
            <h2>Description</h2>
            <p class="about-desc"><?php if ($description !== ''): ?><?= $h($description) ?><?php else: ?><span style="color:var(--muted)"><?= $isMe ? 'You have not written a description yet. Use "Edit description" at the top of your channel.' : 'This channel has no description yet.' ?></span><?php endif; ?></p>
            <h2>Details</h2>
            <dl class="about-list">
                <?php if ($joinedAt !== '' && strtotime($joinedAt)): ?><div><dt>Joined</dt><dd><?= $h(date('M j, Y', strtotime($joinedAt))) ?></dd></div><?php endif; ?>
                <?php if ($subscriberCount !== null): ?><div><dt>Subscribers</dt><dd><?= $h(vs_short_views($subscriberCount)) ?></dd></div><?php endif; ?>
                <div><dt>Videos</dt><dd><?= count($videos) ?></dd></div>
                <div><dt>Shorts</dt><dd><?= count($shorts) ?></dd></div>
                <div><dt>Total views</dt><dd><?= $h(vs_short_views($totalViews)) ?></dd></div>
                <div><dt>Total likes</dt><dd><?= $h(vs_short_views($totalLikes)) ?></dd></div>
            </dl>
        </div>
    </div>
<?php endif; ?>
</div>
<?php
vs_page_end(<<<'JS'
(function () {
    var tabs = document.querySelectorAll('.tab');
    var panels = document.querySelectorAll('.tab-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-tab');
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === key); });
        });
    });

    var toggle = document.getElementById('nameToggle');
    var form = document.getElementById('nameForm');
    var cancel = document.getElementById('nameCancel');
    if (toggle && form) {
        toggle.addEventListener('click', function () {
            form.hidden = !form.hidden;
            if (!form.hidden) { document.getElementById('newName').focus(); }
        });
    }
    if (cancel && form) {
        cancel.addEventListener('click', function () { form.hidden = true; });
    }

    var descToggle = document.getElementById('descToggle');
    var descForm = document.getElementById('descForm');
    var descCancel = document.getElementById('descCancel');
    if (descToggle && descForm) {
        descToggle.addEventListener('click', function () {
            descForm.hidden = !descForm.hidden;
            if (!descForm.hidden) { document.getElementById('descText').focus(); }
        });
    }
    if (descCancel && descForm) {
        descCancel.addEventListener('click', function () { descForm.hidden = true; });
    }

    document.querySelectorAll('[data-goto]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.querySelector('.tab[data-tab="' + b.getAttribute('data-goto') + '"]');
            if (t) { t.click(); t.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
    });

    document.querySelectorAll('.sort-bar').forEach(function (bar) {
        var grid = document.getElementById(bar.getAttribute('data-sort-for'));
        if (!grid) { return; }
        bar.addEventListener('click', function (e) {
            var chip = e.target.closest('.chip');
            if (!chip) { return; }
            bar.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('active', c === chip); });
            var mode = chip.getAttribute('data-sort');
            var cards = Array.prototype.slice.call(grid.children);
            cards.sort(function (a, b) {
                var av = +a.dataset.views, bv = +b.dataset.views, at = +a.dataset.ts, bt = +b.dataset.ts;
                var as = +a.dataset.score, bs = +b.dataset.score;
                if (mode === 'popular') { return (bs - as) || (bv - av) || (bt - at); }
                if (mode === 'oldest') { return at - bt; }
                return bt - at;
            });
            cards.forEach(function (c) { grid.appendChild(c); });
        });
    });

    var file = document.getElementById('avatarFile');
    var avatarForm = document.getElementById('avatarForm');
    if (file && avatarForm) {
        file.addEventListener('change', function () {
            if (file.files && file.files.length) { avatarForm.submit(); }
        });
    }

    var bannerFile = document.getElementById('bannerFile');
    var bannerForm = document.getElementById('bannerForm');
    if (bannerFile && bannerForm) {
        bannerFile.addEventListener('change', function () {
            if (bannerFile.files && bannerFile.files.length) { bannerForm.submit(); }
        });
    }
    var shareBtn = document.getElementById('shareChannel');
    if (shareBtn) {
        shareBtn.addEventListener('click', function () {
            var url = new URL('profile.php?u=' + encodeURIComponent(shareBtn.getAttribute('data-u') || ''), window.location.href).href;
            function done() { if (typeof toast === 'function') { toast('Channel link copied'); } }
            function fallback() {
                var t = document.createElement('textarea');
                t.value = url; t.style.position = 'fixed'; t.style.opacity = '0';
                document.body.appendChild(t); t.select();
                try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Copy this link:', url); }
                document.body.removeChild(t);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, fallback); } else { fallback(); }
        });
    }
})();
JS
);