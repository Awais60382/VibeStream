<?php
require_once __DIR__ . '/layout.php';
if (is_file(__DIR__ . '/premium-perks.php')) {
    require_once __DIR__ . '/premium-perks.php';
}
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/subscription-store.php';
if (is_file(__DIR__ . '/premium-store.php')) { require_once __DIR__ . '/premium-store.php'; }
include __DIR__ . '/connection.php';

// Page that shows points / rewards (used by the Rewards tab button). Change it if yours has another name.
$rewardsPage = 'premium-points.php';

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

/** Channel details live in their own small table (created / extended on first use). The `username` column is renamed together with the user. */
function vs_channel_profile_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `channel_profiles` (
            `username` VARCHAR(100) NOT NULL,
            `description` VARCHAR(500) NOT NULL DEFAULT \'\',
            `updated_at` DATETIME NULL,
            PRIMARY KEY (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $extra = [
        'location' => 'VARCHAR(80)',
        'link_youtube' => 'VARCHAR(255)',
        'link_discord' => 'VARCHAR(255)',
        'link_x' => 'VARCHAR(255)',
        'link_website' => 'VARCHAR(255)',
    ];
    foreach ($extra as $column => $type) {
        $res = $conn->query("SHOW COLUMNS FROM `channel_profiles` LIKE '" . $conn->real_escape_string($column) . "'");
        $missing = $res && $res->num_rows === 0;
        if ($res) {
            $res->free();
        }
        if ($missing) {
            $conn->query("ALTER TABLE `channel_profiles` ADD COLUMN `$column` $type NOT NULL DEFAULT ''");
        }
    }
}

/** Checks a social / website link. Returns '' (empty), the cleaned https URL, or null when it is not acceptable. */
function vs_clean_link(string $raw, array $hosts): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (mb_strlen($raw) > 200) {
        return null;
    }
    if (!preg_match('#^https?://#i', $raw)) {
        $raw = 'https://' . $raw;
    }
    if (!filter_var($raw, FILTER_VALIDATE_URL)) {
        return null;
    }
    $parts = parse_url($raw);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
        return null;
    }
    if ($hosts) {
        $ok = false;
        foreach ($hosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            return null;
        }
    }
    return $raw;
}

function vs_link_label(string $url): string
{
    return rtrim((string)preg_replace('#^https?://(www\.)?#i', '', $url), '/');
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

            } elseif ($action === 'details') {
                $desc = trim((string)($_POST['description'] ?? ''));
                $desc = (string)preg_replace('/(\r\n|\r|\n){3,}/', "\n\n", $desc);
                $loc = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['location'] ?? '')));
                $links = [
                    'link_youtube' => vs_clean_link((string)($_POST['link_youtube'] ?? ''), ['youtube.com', 'youtu.be']),
                    'link_discord' => vs_clean_link((string)($_POST['link_discord'] ?? ''), ['discord.gg', 'discord.com']),
                    'link_x'       => vs_clean_link((string)($_POST['link_x'] ?? ''), ['x.com', 'twitter.com']),
                    'link_website' => vs_clean_link((string)($_POST['link_website'] ?? ''), []),
                ];
                if (mb_strlen($desc) > 500) {
                    $flash = ['type' => 'err', 'text' => 'The description can be at most 500 characters.'];
                } elseif (mb_strlen($loc) > 60) {
                    $flash = ['type' => 'err', 'text' => 'The location can be at most 60 characters.'];
                } elseif (in_array(null, $links, true)) {
                    $flash = ['type' => 'err', 'text' => 'One of the links is not valid. Use a YouTube, Discord or X link, or any website address.'];
                } else {
                    try {
                        vs_channel_profile_ensure($conn);
                        $st = $conn->prepare(
                            'INSERT INTO `channel_profiles` (`username`, `description`, `location`, `link_youtube`, `link_discord`, `link_x`, `link_website`, `updated_at`)
                             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                             ON DUPLICATE KEY UPDATE `description` = VALUES(`description`), `location` = VALUES(`location`),
                                 `link_youtube` = VALUES(`link_youtube`), `link_discord` = VALUES(`link_discord`),
                                 `link_x` = VALUES(`link_x`), `link_website` = VALUES(`link_website`), `updated_at` = NOW()'
                        );
                        $st->bind_param('sssssss', $viewer, $desc, $loc, $links['link_youtube'], $links['link_discord'], $links['link_x'], $links['link_website']);
                        $st->execute();
                        $st->close();
                        $flash = ['type' => 'ok', 'text' => 'Profile details saved.'];
                    } catch (Throwable $e) {
                        error_log('Profile details save failed: ' . $e->getMessage());
                        $flash = ['type' => 'err', 'text' => 'Could not save your profile details right now.'];
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
        $videoSql = "SELECT * FROM `videos` WHERE LOWER(`$uploaderCol`) = LOWER(?)";
        $videoParams = [$profileUser];
        if (!$isMe && isset($vCols['scheduled_at'])) {
            $videoSql .= ' AND (`scheduled_at` IS NULL OR `scheduled_at` <= ?)';
            $videoParams[] = date('Y-m-d H:i:s');
        }
        $videoSql .= " ORDER BY $order";
        $st = $conn->prepare($videoSql);
        if ($st) {
            $st->bind_param(str_repeat('s', count($videoParams)), ...$videoParams);
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
$totalUploads = count($videos) + count($shorts);

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
$location = '';
$links = ['youtube' => '', 'discord' => '', 'x' => '', 'website' => ''];
$joinedAt = '';
if ($accountFound) {
    try {
        vs_channel_profile_ensure($conn);
        if ($st = $conn->prepare('SELECT `description`, `location`, `link_youtube`, `link_discord`, `link_x`, `link_website` FROM `channel_profiles` WHERE `username` = ? LIMIT 1')) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            $r = $st->get_result()->fetch_row();
            if ($r) {
                $description = (string)$r[0];
                $location = (string)$r[1];
                $links = ['youtube' => (string)$r[2], 'discord' => (string)$r[3], 'x' => (string)$r[4], 'website' => (string)$r[5]];
            }
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
$hasLinks = ($links['youtube'] . $links['discord'] . $links['x'] . $links['website']) !== '';

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
    try {
        if (function_exists('vs_subscription_channel_count')) {
            $subscriberCount = (int)vs_subscription_channel_count($conn, $profileUser, $profileUser, 0);
        }
    } catch (Throwable $e) {
        error_log('Profile subscriber count unavailable: ' . $e->getMessage());
    }
}

/* Premium status, rewards level, playlists */
$isPremium = false;
$earned = 0;
$level = null;
$nextLevel = null;
$playlists = [];
if ($accountFound) {
    try {
        if (function_exists('vs_premium_pdo') && function_exists('vs_premium_get') && function_exists('vs_premium_is_active')) {
            $premiumPdo = vs_premium_pdo();
            if ($premiumPdo) {
                $isPremium = (bool)vs_premium_is_active(vs_premium_get($premiumPdo, $profileUser));
            }
        }
    } catch (Throwable $e) {
        error_log('Profile premium status unavailable: ' . $e->getMessage());
    }
    try {
        if (function_exists('vs_rewards_level_prefetch') && defined('VS_REWARDS_LEVELS')) {
            vs_rewards_level_prefetch([$profileUser]);
            $levelStore = &vs_rewards_level_store();
            $earned = (int)($levelStore[strtolower($profileUser)] ?? 0);
            foreach (VS_REWARDS_LEVELS as $i => $candidate) {
                if ($earned >= $candidate[1]) {
                    $level = $candidate;
                    $nextLevel = VS_REWARDS_LEVELS[$i + 1] ?? null;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Profile rewards level unavailable: ' . $e->getMessage());
    }
    try {
        $uid = 0;
        if ($st = $conn->prepare('SELECT `id` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1')) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            $r = $st->get_result()->fetch_row();
            $uid = $r ? (int)$r[0] : 0;
            $st->close();
        }
        if ($uid > 0) {
            $plSql = 'SELECT p.`id`, p.`name`, p.`visibility`, COUNT(pv.`video_id`) AS n
                      FROM `playlists` p LEFT JOIN `playlist_videos` pv ON pv.`playlist_id` = p.`id`
                      WHERE p.`user_id` = ?' . ($isMe ? '' : " AND p.`visibility` = 'public'") . '
                      GROUP BY p.`id`, p.`name`, p.`visibility` ORDER BY p.`id` DESC LIMIT 24';
            $st = $conn->prepare($plSql);
            if ($st) {
                $st->bind_param('i', $uid);
                $st->execute();
                $playlists = $st->get_result()->fetch_all(MYSQLI_ASSOC);
                $st->close();
            }
        }
    } catch (Throwable $e) {
        error_log('Profile playlists unavailable: ' . $e->getMessage());
    }
}

$h = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
$brandName = function_exists('vs_branding_app_name') ? (string)vs_branding_app_name() : 'VibeStream';
$descShort = mb_strlen($description) > 110 ? mb_substr($description, 0, 110) . '…' : $description;
$topVideos = array_slice($videos, 0, 5);
$levelPct = ($level && $nextLevel) ? max(0, min(100, ($earned - $level[1]) / max(1, $nextLevel[1] - $level[1]) * 100)) : 100;

$extraCss = <<<'CSS'
body { overflow-x: clip; }

/* ===== PROFILE (red creator dashboard) ===== */
.pf {
    --pf-card: #101015; --pf-card2: #16161d; --pf-line: rgba(255,255,255,.10);
    --pf-text: #f4f4f7; --pf-muted: #a3a3ad; --pf-red: #ff2a3a; --pf-red2: #c4121f;
    --pf-gold: #ffc23d; --pf-blue: #3ea6ff; --pf-green: #2fe08a; --pf-purple: #a855f7;
    max-width: 1320px; margin: 0 auto; padding: 14px clamp(12px, 2vw, 26px) 60px; color: var(--pf-text);
}
html[data-theme="light"] .pf {
    --pf-card: #ffffff; --pf-card2: #f3f1f6; --pf-line: rgba(20,10,40,.14);
    --pf-text: #17101f; --pf-muted: #5f5870; --pf-red: #d4121f; --pf-red2: #a80c18;
    --pf-gold: #8a5a00; --pf-blue: #0b6bcb; --pf-green: #168a50; --pf-purple: #7b2fd6;
}
.pf a { color: inherit; text-decoration: none; }
.pf-flash { margin: 0 0 16px; padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 600; }
.pf-flash.ok  { background: rgba(60,170,90,.15); border: 1px solid rgba(60,170,90,.45); color: #2e9e5b; }
.pf-flash.err { background: rgba(220,60,60,.15); border: 1px solid rgba(220,60,60,.45); color: #e24a4a; }

/* banner */
.pf-banner { position: relative; overflow: hidden; border-radius: 18px; aspect-ratio: 6.2 / 1; min-height: 110px; max-height: 250px; background: #12060a;
    border: 1px solid rgba(255,42,58,.6); box-shadow: 0 0 0 1px rgba(255,42,58,.12), 0 18px 50px -26px rgba(255,42,58,.75); }
.pf-banner > img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pf-banner-default { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: clamp(10px, 3vw, 44px); padding: 0 4%;
    background:
        repeating-linear-gradient(115deg, transparent 0 46px, rgba(255,42,58,.07) 46px 48px),
        radial-gradient(520px 220px at 18% 0, rgba(255,42,58,.55), transparent 65%),
        radial-gradient(520px 240px at 100% 100%, rgba(200,12,30,.6), transparent 70%),
        linear-gradient(110deg, #1a0508 0%, #3a0b14 42%, #12040a 100%); }
.pf-b-play { width: clamp(46px, 9vw, 110px); height: auto; flex: 0 0 auto; filter: drop-shadow(0 0 18px rgba(255,42,58,.8)); }
.pf-b-word { text-align: center; color: #fff; min-width: 0; }
.pf-b-word b { display: block; font-size: clamp(24px, 5vw, 62px); line-height: 1; font-style: italic; letter-spacing: -.03em; font-weight: 800; }
.pf-b-word b span { color: #ff2a3a; }
.pf-b-word em { display: block; margin-top: 6px; font-size: clamp(11px, 1.6vw, 22px); font-style: italic; opacity: .95; white-space: nowrap; }
.pf-b-word em i { color: #ff2a3a; font-style: normal; margin: 0 .6em; }
.pf-b-crown { width: clamp(36px, 6.5vw, 96px); height: auto; flex: 0 0 auto; filter: drop-shadow(0 0 12px rgba(255,42,58,.7)); }
.pf-banner-actions { position: absolute; right: 12px; bottom: 10px; display: flex; gap: 8px; z-index: 3; }
.pf-banner-actions form { margin: 0; }

/* header */
.pf-head { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 8px 26px; align-items: start; padding: 0 clamp(8px, 2vw, 22px); margin-top: -76px; position: relative; z-index: 2; }
.pf-avatar { position: relative; width: 176px; height: 176px; border-radius: 50%; padding: 6px; flex: 0 0 auto;
    background: conic-gradient(from 200deg, #ff2a3a, #7a0b14, #ff5a5a, #ff2a3a); box-shadow: 0 0 30px rgba(255,42,58,.55); }
.pf-avatar-in { width: 100%; height: 100%; border-radius: 50%; overflow: hidden; display: grid; place-items: center; background: #0b0b0f; border: 5px solid #0b0b0f; color: #fff; font-size: 74px; font-weight: 800; }
html[data-theme="light"] .pf-avatar-in { border-color: #fff; background: #1a1220; }
.pf-avatar-in img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pf-cam { position: absolute; right: 8px; bottom: 8px; display: grid; place-items: center; width: 40px; height: 40px; border-radius: 50%; background: #1b1b22; color: #fff; border: 2px solid rgba(255,255,255,.85); cursor: pointer; }
.pf-cam svg { width: 20px; height: 20px; }
.pf-info { padding-top: 86px; min-width: 0; }
.pf-name { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: clamp(28px, 3.6vw, 40px); line-height: 1.1; font-weight: 800; word-break: break-word; }
.pf-handle { margin: 4px 0 0; color: var(--pf-muted); font-size: 18px; }
.pf-tagline { margin: 6px 0 0; font-size: 15px; }
.pf-more { background: none; border: 0; padding: 0; margin-left: 6px; color: var(--pf-muted); font: inherit; font-weight: 700; cursor: pointer; }
.pf-more:hover { color: var(--pf-text); }
.pf-meta { display: flex; flex-wrap: wrap; gap: 8px 22px; margin: 10px 0 0; color: var(--pf-muted); font-size: 14px; }
.pf-meta span { display: inline-flex; align-items: center; gap: 7px; }
.pf-meta svg { width: 17px; height: 17px; flex: 0 0 auto; }
.pf-actions { padding-top: 98px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: flex-end; }
.pf-btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 20px; border-radius: 999px; border: 1px solid var(--pf-line);
    background: var(--pf-card2); color: var(--pf-text); font: inherit; font-size: 15px; font-weight: 700; cursor: pointer; white-space: nowrap; transition: border-color .15s, box-shadow .15s, transform .15s; }
.pf-btn:hover { border-color: rgba(255,42,58,.7); box-shadow: 0 0 16px rgba(255,42,58,.3); transform: translateY(-1px); }
.pf-btn svg { width: 19px; height: 19px; flex: 0 0 auto; }
.pf-btn.icon { width: 44px; padding: 0; }
.pf-btn.red { background: linear-gradient(90deg, #ff2a3a, #c4121f); border-color: transparent; color: #fff; box-shadow: 0 0 18px rgba(255,42,58,.4); }
.pf-btn.sm { height: 34px; padding: 0 14px; font-size: 13px; }
.pf-badge-light { display: inline-flex; align-items: center; }

/* stats */
.pf-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 14px; margin: 18px 0 14px; }
.pf-stat { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-radius: 16px; border: 1px solid var(--pf-line); background: var(--pf-card); min-width: 0; }
.pf-stat > svg { width: 44px; height: 44px; flex: 0 0 auto; }
.pf-stat b { display: block; font-size: 26px; line-height: 1.1; font-weight: 800; }
.pf-stat span { display: block; margin-top: 2px; color: var(--pf-muted); font-size: 14px; }
.pf-stat.gold { border-color: rgba(255,194,61,.55); background: radial-gradient(260px 100px at 0 50%, rgba(255,194,61,.14), transparent 70%), var(--pf-card); }
.pf-stat.gold b { font-size: 18px; color: var(--pf-text); }
.pf-active { display: inline-flex; align-items: center; gap: 6px; margin-top: 5px; padding: 2px 12px; border-radius: 999px; border: 1px solid rgba(47,224,138,.55); background: rgba(47,224,138,.12); color: var(--pf-green); font-size: 13px; font-weight: 800; }
.pf-active::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: var(--pf-green); }
.pf-lvl { display: inline-block; margin-top: 4px; padding: 2px 12px; border-radius: 999px; border: 1px solid currentColor; font-size: 13px; font-weight: 800; }

/* tabs */
.pf-tabs { display: flex; gap: 10px; margin: 0 0 16px; overflow-x: auto; padding: 2px 2px 6px; scrollbar-width: none; }
.pf-tabs::-webkit-scrollbar { display: none; }
.pf-tab { display: inline-flex; align-items: center; gap: 9px; height: 48px; padding: 0 24px; flex: 0 0 auto; border-radius: 999px; border: 1px solid var(--pf-line);
    background: var(--pf-card); color: var(--pf-text); font: inherit; font-size: 15px; font-weight: 600; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
.pf-tab svg { width: 20px; height: 20px; flex: 0 0 auto; }
.pf-tab:hover { border-color: rgba(255,42,58,.6); }
.pf-tab .n { color: var(--pf-muted); font-weight: 500; font-size: 13px; }
.pf-tab[aria-selected="true"] { background: linear-gradient(90deg, #ff2a3a, #c4121f); border-color: transparent; color: #fff; box-shadow: 0 0 20px rgba(255,42,58,.45); }
.pf-tab[aria-selected="true"] .n { color: rgba(255,255,255,.85); }
.pf-tab:focus-visible, .pf-btn:focus-visible, .pf-chip:focus-visible { outline: 3px solid #ffb020; outline-offset: 2px; }
.pf-panel { display: none; }
.pf-panel.active { display: block; }

/* cards */
.pf-cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.5fr); gap: 16px; align-items: start; }
.pf-card { border: 1px solid var(--pf-line); border-radius: 16px; background: var(--pf-card); padding: 18px 20px; min-width: 0; margin-bottom: 16px; }
.pf-cols .pf-card { margin-bottom: 0; }
.pf-card-head { display: flex; align-items: center; gap: 12px; margin: 0 0 12px; }
.pf-card-head > svg { width: 28px; height: 28px; flex: 0 0 auto; }
.pf-card-head h2 { margin: 0; font-size: 20px; font-weight: 700; flex: 1; min-width: 0; }
.pf-viewall { color: var(--pf-red); font-weight: 700; font-size: 14px; background: none; border: 0; cursor: pointer; font-family: inherit; white-space: nowrap; }
.pf-viewall:hover { text-decoration: underline; }
.pf-about-text { margin: 0; font-size: 16px; line-height: 1.55; white-space: pre-line; overflow-wrap: anywhere; }
.pf-empty-note { color: var(--pf-muted); font-size: 14px; }
.pf-links { list-style: none; margin: 14px 0 0; padding: 14px 0 0; border-top: 1px solid var(--pf-line); display: grid; gap: 10px; }
.pf-links a { display: flex; align-items: center; gap: 12px; color: var(--pf-blue); font-size: 15px; overflow-wrap: anywhere; }
.pf-links a:hover { text-decoration: underline; }
.pf-links svg { width: 24px; height: 24px; flex: 0 0 auto; }

/* memberships */
.pf-member { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 14px; }
.pf-promo { position: relative; display: flex; align-items: center; gap: 18px; padding: 20px; border-radius: 16px; border: 1px solid rgba(190,90,255,.65);
    background: radial-gradient(360px 160px at 0 50%, rgba(255,194,61,.18), transparent 70%), linear-gradient(120deg, #3a0f63, #6a1bb0 60%, #4a0f86); box-shadow: 0 0 26px rgba(150,60,255,.3); color: #fff; }
.pf-promo > svg { width: 110px; height: 110px; flex: 0 0 auto; filter: drop-shadow(0 0 16px rgba(255,194,61,.5)); }
.pf-promo h3 { margin: 0 0 6px; font-size: 24px; font-weight: 800; }
.pf-promo p { margin: 0 0 14px; font-size: 15px; line-height: 1.4; color: #eadfff; }
.pf-promo .join { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; height: 42px; border-radius: 999px; border: 1px solid #d8a8ff; background: rgba(20,5,40,.55); color: #fff; font-weight: 800; font-size: 15px; }
.pf-promo .join:hover { background: rgba(20,5,40,.8); box-shadow: 0 0 20px rgba(200,130,255,.5); }
.pf-promo .join svg { width: 20px; height: 20px; }
.pf-perks { list-style: none; margin: 0; padding: 16px 18px; border-radius: 16px; border: 1px solid var(--pf-line); background: rgba(120,60,200,.10); display: grid; gap: 12px; align-content: center; }
.pf-perks li { display: flex; align-items: center; gap: 12px; font-size: 15px; }
.pf-perks svg { width: 22px; height: 22px; flex: 0 0 auto; }

/* video strips + grids */
.pf-strip { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 14px; }
.pf-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 24px 16px; }
.pf-short-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 24px 14px; }
.pf-vid { display: block; min-width: 0; transition: transform .18s ease; }
.pf-vid:hover { transform: translateY(-3px); }
.pf-thumb { position: relative; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: var(--pf-card2); border: 1px solid var(--pf-line); }
.pf-vid:hover .pf-thumb { border-color: rgba(255,42,58,.7); box-shadow: 0 0 20px rgba(255,42,58,.35); }
.pf-thumb.tall { aspect-ratio: 9 / 16; }
.pf-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pf-dur { position: absolute; right: 8px; bottom: 8px; background: rgba(0,0,0,.82); color: #fff; font-size: 12px; padding: 2px 6px; border-radius: 6px; }
.pf-vtitle { margin: 9px 0 3px; font-size: 14px; font-weight: 600; line-height: 1.35; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.pf-vmeta { margin: 0; color: var(--pf-muted); font-size: 12px; }
.pf-sort { display: flex; flex-wrap: wrap; gap: 8px; margin: 0 0 18px; }
.pf-chip { border: 1px solid var(--pf-line); cursor: pointer; height: 34px; padding: 0 16px; border-radius: 999px; background: var(--pf-card2); color: var(--pf-text); font: inherit; font-size: 14px; font-weight: 600; }
.pf-chip.active { background: var(--pf-text); color: var(--pf-card); border-color: transparent; }
.pf-empty { text-align: center; padding: 50px 16px; color: var(--pf-muted); border: 1px dashed var(--pf-line); border-radius: 18px; }
.pf-empty .pf-btn { margin-top: 14px; }

/* playlists */
.pf-pl { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
.pf-pl-item { display: flex; align-items: center; gap: 14px; padding: 16px; border-radius: 14px; border: 1px solid var(--pf-line); background: var(--pf-card2); }
.pf-pl-item svg { width: 34px; height: 34px; color: var(--pf-red); flex: 0 0 auto; }
.pf-pl-item strong { display: block; font-size: 15px; overflow-wrap: anywhere; }
.pf-pl-item span { color: var(--pf-muted); font-size: 13px; }

/* about + rewards */
.pf-dl { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px 22px; margin: 0; }
.pf-dl div { border-top: 1px solid var(--pf-line); padding-top: 10px; }
.pf-dl dt { color: var(--pf-muted); font-size: 13px; margin: 0 0 2px; }
.pf-dl dd { margin: 0; font-size: 17px; font-weight: 700; }
.pf-bar { height: 10px; border-radius: 999px; background: rgba(128,128,128,.25); overflow: hidden; margin: 12px 0 6px; }
.pf-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #ff2a3a, #ffb020); }

/* edit panel */
.pf-edit { margin: 0 0 16px; }
.pf-edit[hidden] { display: none; }
.pf-edit h2 { margin: 0 0 12px; font-size: 19px; }
.pf-form { display: grid; gap: 10px; margin: 0 0 18px; padding-bottom: 18px; border-bottom: 1px solid var(--pf-line); }
.pf-form:last-of-type { border-bottom: 0; padding-bottom: 0; margin-bottom: 0; }
.pf-form label { font-size: 13px; color: var(--pf-muted); font-weight: 600; }
.pf-form input[type=text], .pf-form textarea { width: 100%; box-sizing: border-box; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--pf-line); background: var(--pf-card2); color: var(--pf-text); font: inherit; }
.pf-form textarea { min-height: 100px; resize: vertical; }
.pf-form input:focus, .pf-form textarea:focus { outline: none; border-color: var(--pf-red); box-shadow: 0 0 0 3px rgba(255,42,58,.2); }
.pf-form small { color: var(--pf-muted); font-size: 12px; }
.pf-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pf-row-btns { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

/* ===== phones / tablets ===== */
@media (max-width: 1100px) {
    .pf-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pf-cols { grid-template-columns: 1fr; }
    .pf-strip { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .pf-member { grid-template-columns: 1fr; }
}
@media (max-width: 860px) {
    .pf-head { grid-template-columns: auto minmax(0, 1fr); margin-top: -50px; gap: 6px 16px; }
    .pf-avatar { width: 110px; height: 110px; padding: 4px; }
    .pf-avatar-in { font-size: 46px; border-width: 3px; }
    .pf-cam { width: 34px; height: 34px; right: 0; bottom: 0; }
    .pf-info { padding-top: 54px; }
    .pf-actions { grid-column: 1 / -1; padding-top: 6px; justify-content: flex-start; }
    .pf-handle { font-size: 15px; }
}
@media (max-width: 560px) {
    .pf-stats { gap: 10px; }
    .pf-stat { padding: 12px; gap: 10px; }
    .pf-stat > svg { width: 34px; height: 34px; }
    .pf-stat b { font-size: 20px; }
    .pf-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .pf-promo { flex-direction: column; text-align: center; }
    .pf-promo > svg { width: 84px; height: 84px; }
    .pf-two { grid-template-columns: 1fr; }
    .pf-btn { height: 46px; }
    .pf-banner { aspect-ratio: 16 / 5; }
    .pf-banner-actions { right: 8px; bottom: 8px; }
    .pf-b-crown { display: none; }
}
@media (prefers-reduced-motion: reduce) { .pf-vid, .pf-btn { transition: none; } .pf-vid:hover, .pf-btn:hover { transform: none; } }

/* ===== top bar like the design: centred search, no centre icons on desktop (only on this page) ===== */
html body:has(.pf) .app-navbar { background: #09090c; border-bottom: 1px solid rgba(255,255,255,.08); box-shadow: none; }
html[data-theme="light"] body:has(.pf) .app-navbar { background: #ffffff; border-bottom-color: rgba(0,0,0,.10); }
html body:has(.pf) .brand { -webkit-text-fill-color: currentColor; filter: none; }
@media (min-width: 1001px) {
    html body:has(.pf) .nav-center { display: none; }
    html body:has(.pf) .nav-search { position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%); width: min(46vw, 640px); margin: 0; }
    html body:has(.pf) .nav-search input {
        height: 48px; padding: 0 52px 0 54px; border-radius: 999px; border: 1px solid rgba(255,255,255,.14); color: #f4f4f7;
        background: #131318 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%23a3a3ad' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E") 20px center / 20px no-repeat;
    }
    html[data-theme="light"] body:has(.pf) .nav-search input {
        color: #17101f; border-color: rgba(0,0,0,.16);
        background: #f3f1f6 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235f5870' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E") 20px center / 20px no-repeat;
    }
    html body:has(.pf) .nav-search input:focus { border-color: #ff2a3a; box-shadow: 0 0 0 3px rgba(255,42,58,.22); }
}
CSS;

vs_page_start($accountFound ? '@' . $profileUser : 'Channel', 'profile', $extraCss);

/* small inline icons */
$ico = [
    'home'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3 2.5 11.5H5V20h5v-5.5h4V20h5v-8.5h2.5z"/></svg>',
    'videos'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3.5"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/></svg>',
    'shorts'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2 4 14h6.5L9.5 22 20 9.5h-6.8z"/></svg>',
    'lists'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h14M3 12h14M3 18h8M19 14v6l4-3z" fill="currentColor"/></svg>',
    'about'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>',
    'crown'   => '<svg viewBox="0 0 24 20" fill="currentColor" aria-hidden="true" style="color:#ffc23d"><path d="M2 17 0 5l6.5 5L12 1l5.5 9L24 5l-2 12zM3 19h18v1.5H3z"/></svg>',
    'star'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1z"/></svg>',
    'cam'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.5"/></svg>',
    'pencil'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20l1-4L16.5 4.5a2 2 0 0 1 3 3L8 19z"/></svg>',
    'share'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 4l7 7-7 7v-4c-6 0-9 2-11 6 1-7 4-11 11-12z"/></svg>',
    'cal'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
    'pin'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5a7 7 0 0 0-14 0C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>',
    'people'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0z"/><circle cx="17" cy="9" r="2.6"/><path d="M16.5 14.2A5.5 5.5 0 0 1 21.5 20H18c0-2.3-.6-4.2-1.5-5.8z"/></svg>',
    'check'   => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="#d946ef"/><path d="M7 12.5l3.2 3.2L17 9" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    'plus'    => function_exists('vs_icon') ? vs_icon('plus-square', 16) : '',
];
$bigCrown = '<svg viewBox="0 0 120 100" aria-hidden="true"><defs><linearGradient id="pfG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe27a"/><stop offset="1" stop-color="#e79300"/></linearGradient></defs><path d="M10 34l22 22 28-44 28 44 22-22-10 52H20z" fill="url(#pfG)"/><rect x="20" y="86" width="80" height="9" rx="4" fill="#c77a00"/><circle cx="60" cy="14" r="7" fill="#ff4a6a"/><circle cx="10" cy="34" r="6" fill="#ffe27a"/><circle cx="110" cy="34" r="6" fill="#ffe27a"/><path d="M60 52l9 12-9 12-9-12z" fill="#ff3150"/></svg>';
$tabList = [
    'home' => ['Home', $ico['home'], null],
    'videos' => ['Videos', $ico['videos'], count($videos)],
    'shorts' => ['Shorts', $ico['shorts'], count($shorts)],
    'playlists' => ['Playlists', $ico['lists'], null],
    'about' => ['About', $ico['about'], null],
    'memberships' => ['Memberships', $ico['crown'], null],
    'rewards' => ['Rewards', $ico['star'], null],
];
?>
<div class="pf">
<?php if ($flash): ?>
    <div class="pf-flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= $h($flash['text']) ?></div>
<?php endif; ?>

<?php if (!$accountFound): ?>
    <div class="pf-empty">
        <p>This channel was not found.</p>
        <a class="pf-btn red" href="index.php">Go home</a>
    </div>
<?php else: ?>
    <?php if ($isMe): ?>
        <form method="post" action="profile.php" enctype="multipart/form-data" id="bannerForm" style="display:none;">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="banner">
            <input type="file" name="banner" id="bannerFile" accept=".jpg,.jpeg,.png,.webp">
        </form>
        <form method="post" action="profile.php" enctype="multipart/form-data" id="avatarForm" style="display:none;">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="avatar">
            <input type="file" name="avatar" id="avatarFile" accept=".jpg,.jpeg,.png,.webp,.gif">
        </form>
    <?php endif; ?>

    <section class="pf-banner" aria-label="Channel banner">
        <?php if ($bannerUrl !== ''): ?>
            <img src="<?= $h($bannerUrl) ?>" alt="<?= $h($profileUser) ?> banner">
        <?php else: ?>
            <div class="pf-banner-default" aria-hidden="true">
                <svg class="pf-b-play" viewBox="0 0 120 120"><defs><linearGradient id="pfRed" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff5a5a"/><stop offset="1" stop-color="#c4121f"/></linearGradient></defs><path d="M30 14c0-6 6-9 11-6l62 40c5 3 5 10 0 13L41 101c-5 3-11 0-11-6z" fill="url(#pfRed)"/></svg>
                <div class="pf-b-word">
                    <b><?php if (preg_match('/^(.*?)(Stream)$/u', $brandName, $bm)): ?><?= $h($bm[1]) ?><span><?= $h($bm[2]) ?></span><?php else: ?><?= $h($brandName) ?><?php endif; ?></b>
                    <em>Watch <i>&bull;</i> Create <i>&bull;</i> Belong</em>
                </div>
                <svg class="pf-b-crown" viewBox="0 0 64 48" fill="none" stroke="#ff2a3a" stroke-width="3" stroke-linejoin="round"><path d="M4 14l12 12 16-22 16 22 12-12-6 30H10z"/><path d="M10 44h44"/></svg>
            </div>
        <?php endif; ?>
        <?php if ($isMe): ?>
            <div class="pf-banner-actions">
                <label class="pf-btn sm" for="bannerFile"><?= $ico['plus'] ?> <?= $bannerUrl !== '' ? 'Change banner' : 'Add banner' ?></label>
                <?php if ($bannerUrl !== ''): ?>
                    <form method="post" action="profile.php">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="banner_remove">
                        <button type="submit" class="pf-btn sm">Remove</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="pf-head">
        <div class="pf-avatar">
            <div class="pf-avatar-in">
                <?php if ($avatarUrl !== ''): ?>
                    <img src="<?= $h($avatarUrl) ?>" alt="<?= $h($profileUser) ?> avatar">
                <?php else: ?>
                    <?= $h(mb_strtoupper(mb_substr($profileUser, 0, 1))) ?>
                <?php endif; ?>
            </div>
            <?php if ($isMe): ?>
                <label class="pf-cam" for="avatarFile" title="Change profile picture" aria-label="Change profile picture"><?= $ico['cam'] ?></label>
            <?php endif; ?>
        </div>

        <div class="pf-info">
            <h1 class="pf-name"><?= $h($profileUser) ?><?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html($profileUser) : ''; ?><?php echo function_exists('vs_rewards_user_level_html') ? vs_rewards_user_level_html($profileUser, 13) : ''; ?></h1>
            <p class="pf-handle">@<?= $h($profileUser) ?></p>
            <?php if ($descShort !== ''): ?>
                <p class="pf-tagline"><?= $h(str_replace(["\r", "\n"], ' ', $descShort)) ?><?php if ($descShort !== $description): ?><button type="button" class="pf-more" data-goto="about">more</button><?php endif; ?></p>
            <?php endif; ?>
            <div class="pf-meta">
                <?php if ($joinedAt !== '' && strtotime($joinedAt)): ?><span><?= $ico['cal'] ?>Joined <?= $h(date('M Y', strtotime($joinedAt))) ?></span><?php endif; ?>
                <?php if ($location !== ''): ?><span><?= $ico['pin'] ?><?= $h($location) ?></span><?php endif; ?>
                <?php if ($subscriberCount !== null): ?><span><?= $ico['people'] ?><?= $h(vs_short_views($subscriberCount)) ?> Subscribers</span><?php endif; ?>
            </div>
        </div>

        <div class="pf-actions">
            <?php if ($isMe): ?>
                <button type="button" class="pf-btn" id="editToggle" aria-expanded="false" aria-controls="editPanel"><?= $ico['pencil'] ?> Edit Profile</button>
            <?php else: ?>
                <form method="post" action="profile.php?u=<?= urlencode($profileUser) ?>" style="margin:0;">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="action" value="<?= $isSubscribed ? 'unsubscribe' : 'subscribe' ?>">
                    <button type="submit" class="pf-btn<?= $isSubscribed ? '' : ' red' ?>"<?= $isSubscribed ? ' title="Click to unsubscribe"' : '' ?>><?= $isSubscribed ? 'Subscribed' : 'Subscribe' ?></button>
                </form>
            <?php endif; ?>
            <button type="button" class="pf-btn icon" id="shareChannel" data-u="<?= $h($profileUser) ?>" aria-label="Share channel" title="Share channel"><?= $ico['share'] ?></button>
        </div>
    </section>

    <?php if ($isMe): ?>
        <section class="pf-card pf-edit" id="editPanel" hidden aria-label="Edit profile">
            <h2>Edit profile</h2>
            <form class="pf-form" method="post" action="profile.php">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="details">
                <label for="descText">About you</label>
                <textarea id="descText" name="description" maxlength="500" placeholder="Tell people what your channel is about"><?= $h($description) ?></textarea>
                <div class="pf-two">
                    <div><label for="locText">Location</label><input type="text" id="locText" name="location" maxlength="60" value="<?= $h($location) ?>" placeholder="e.g. Pakistan"></div>
                    <div><label for="lnkWeb">Website</label><input type="text" id="lnkWeb" name="link_website" maxlength="200" value="<?= $h($links['website']) ?>" placeholder="https://"></div>
                    <div><label for="lnkYt">YouTube</label><input type="text" id="lnkYt" name="link_youtube" maxlength="200" value="<?= $h($links['youtube']) ?>" placeholder="youtube.com/@yourname"></div>
                    <div><label for="lnkDc">Discord</label><input type="text" id="lnkDc" name="link_discord" maxlength="200" value="<?= $h($links['discord']) ?>" placeholder="discord.gg/yourserver"></div>
                    <div><label for="lnkX">X</label><input type="text" id="lnkX" name="link_x" maxlength="200" value="<?= $h($links['x']) ?>" placeholder="x.com/yourname"></div>
                </div>
                <small>Up to 500 characters about you. Links must be YouTube, Discord or X addresses (the website can be anything).</small>
                <div class="pf-row-btns"><button type="submit" class="pf-btn red">Save details</button><button type="button" class="pf-btn" data-edit-close>Close</button></div>
            </form>
            <form class="pf-form" method="post" action="profile.php">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="rename">
                <label for="newName">Channel name</label>
                <input type="text" id="newName" name="new_name" value="<?= $h($profileUser) ?>" minlength="3" maxlength="30" required autocomplete="off">
                <small>3-30 characters: letters, numbers, dot, dash or underscore. Your videos, shorts and channel move to the new name.</small>
                <div class="pf-row-btns"><button type="submit" class="pf-btn">Change name</button><a class="pf-btn" href="logout.php">Log out</a></div>
            </form>
        </section>
    <?php endif; ?>

    <div class="pf-stats" aria-label="Channel statistics">
        <div class="pf-stat">
            <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M12 6c0-3 3-4.5 5.5-3l25 16c2.5 1.6 2.5 5 0 6.6l-25 16c-2.5 1.500-5.500 0-5.500-3z" fill="#ff2a3a"/></svg>
            <div><b><?= $h(vs_short_views($totalUploads)) ?></b><span>Total Videos</span></div>
        </div>
        <div class="pf-stat">
            <svg viewBox="0 0 48 48" aria-hidden="true"><path d="M2 24S10 8 24 8s22 16 22 16-8 16-22 16S2 24 2 24z" fill="#e8f1ff"/><circle cx="24" cy="24" r="10" fill="#2f7bff"/><circle cx="24" cy="24" r="5" fill="#0b1630"/></svg>
            <div><b><?= $h(vs_short_views($totalViews)) ?></b><span>Total Views</span></div>
        </div>
        <div class="pf-stat">
            <svg viewBox="0 0 48 48" aria-hidden="true"><circle cx="24" cy="15" r="8" fill="#3ea6ff"/><path d="M8 42a16 16 0 0 1 32 0z" fill="#3ea6ff"/><circle cx="9" cy="19" r="5.500" fill="#2d7fd6"/><circle cx="39" cy="19" r="5.500" fill="#2d7fd6"/></svg>
            <div><b><?= $subscriberCount !== null ? $h(vs_short_views($subscriberCount)) : '&mdash;' ?></b><span>Subscribers</span></div>
        </div>
        <?php if ($isPremium): ?>
            <div class="pf-stat gold">
                <span style="width:44px;height:44px;flex:0 0 auto;display:block"><?= $bigCrown ?></span>
                <div><b>Premium Member</b><span class="pf-active">Active</span></div>
            </div>
        <?php elseif ($level): ?>
            <div class="pf-stat gold">
                <span style="width:44px;height:44px;flex:0 0 auto;display:block"><?= $bigCrown ?></span>
                <div><b>Rewards Level</b><span class="pf-lvl" style="color:<?= $h($level[2]) ?>"><?= $h($level[0]) ?></span></div>
            </div>
        <?php else: ?>
            <div class="pf-stat gold">
                <span style="width:44px;height:44px;flex:0 0 auto;display:block"><?= $bigCrown ?></span>
                <div><b>Free Member</b><span><?= $isMe ? '<a href="premium.php" style="color:var(--pf-gold);font-weight:700">Get Premium &rarr;</a>' : 'Joined the community' ?></span></div>
            </div>
        <?php endif; ?>
    </div>

    <nav class="pf-tabs" role="tablist" aria-label="Channel sections">
        <?php foreach ($tabList as $key => $tab): ?>
            <button type="button" class="pf-tab" role="tab" data-tab="<?= $key ?>" aria-selected="<?= $key === 'home' ? 'true' : 'false' ?>"><?= $tab[1] ?><?= $h($tab[0]) ?><?php if ($tab[2] !== null): ?><span class="n"><?= (int)$tab[2] ?></span><?php endif; ?></button>
        <?php endforeach; ?>
    </nav>

    <!-- HOME -->
    <div class="pf-panel active" data-panel="home">
        <div class="pf-cols">
            <section class="pf-card" aria-labelledby="pfAboutTitle">
                <div class="pf-card-head">
                    <svg viewBox="0 0 24 24" fill="#ff2a3a" aria-hidden="true"><circle cx="12" cy="7.500" r="4.500"/><path d="M3 21a9 9 0 0 1 18 0z"/></svg>
                    <h2 id="pfAboutTitle">About Me</h2>
                    <?php if ($isMe): ?><button type="button" class="pf-btn sm" data-edit-open><?= $ico['pencil'] ?> Edit</button><?php endif; ?>
                </div>
                <?php if ($description !== ''): ?>
                    <p class="pf-about-text"><?= $h($description) ?></p>
                <?php else: ?>
                    <p class="pf-empty-note"><?= $isMe ? 'Tell people about your channel with “Edit”.' : 'This channel has no description yet.' ?></p>
                <?php endif; ?>
                <?php if ($hasLinks): ?>
                    <ul class="pf-links">
                        <?php if ($links['youtube'] !== ''): ?><li><a href="<?= $h($links['youtube']) ?>" target="_blank" rel="noopener noreferrer nofollow"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="5" width="22" height="14" rx="4" fill="#ff0000"/><path d="M10 9v6l5-3z" fill="#fff"/></svg><?= $h(vs_link_label($links['youtube'])) ?></a></li><?php endif; ?>
                        <?php if ($links['discord'] !== ''): ?><li><a href="<?= $h($links['discord']) ?>" target="_blank" rel="noopener noreferrer nofollow"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="3" width="22" height="18" rx="5" fill="#5865f2"/><circle cx="9" cy="12" r="2" fill="#fff"/><circle cx="15" cy="12" r="2" fill="#fff"/></svg><?= $h(vs_link_label($links['discord'])) ?></a></li><?php endif; ?>
                        <?php if ($links['x'] !== ''): ?><li><a href="<?= $h($links['x']) ?>" target="_blank" rel="noopener noreferrer nofollow"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4l16 16M20 4L4 20" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg><?= $h(vs_link_label($links['x'])) ?></a></li><?php endif; ?>
                        <?php if ($links['website'] !== ''): ?><li><a href="<?= $h($links['website']) ?>" target="_blank" rel="noopener noreferrer nofollow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.500 3 14.500 0 18M12 3c-3 3.500-3 14.500 0 18"/></svg><?= $h(vs_link_label($links['website'])) ?></a></li><?php endif; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="pf-card" aria-labelledby="pfMemTitle">
                <div class="pf-card-head">
                    <span style="width:30px;height:30px;display:block"><?= $bigCrown ?></span>
                    <h2 id="pfMemTitle">Memberships</h2>
                    <button type="button" class="pf-viewall" data-goto="memberships">View All &rarr;</button>
                </div>
                <div class="pf-member">
                    <div class="pf-promo">
                        <?= $bigCrown ?>
                        <div style="flex:1;min-width:0">
                            <h3>Premium Member</h3>
                            <p>Unlock more features, support <?= $isMe ? 'me' : 'this creator' ?> and enjoy exclusive perks!</p>
                            <a class="join" href="premium.php"><?= $ico['crown'] ?> <?= ($isMe && $isPremium) ? 'Manage Premium' : 'Join Now' ?></a>
                        </div>
                    </div>
                    <ul class="pf-perks">
                        <li><?= $ico['check'] ?>No Ads</li>
                        <li><?= $ico['check'] ?>Exclusive Badges</li>
                        <li><?= $ico['check'] ?>Early Access to Videos</li>
                        <li><?= $ico['check'] ?>Support Your Creator</li>
                    </ul>
                </div>
            </section>
        </div>

        <section class="pf-card" style="margin-top:16px" aria-labelledby="pfVidTitle">
            <div class="pf-card-head">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="1" y="4" width="22" height="16" rx="5" fill="#ff2a3a"/><path d="M10 8.500v7l6-3.500z" fill="#fff"/></svg>
                <h2 id="pfVidTitle"><?= $isMe ? 'My Videos' : 'Videos' ?></h2>
                <?php if ($topVideos): ?><button type="button" class="pf-viewall" data-goto="videos">View All &rarr;</button><?php endif; ?>
            </div>
            <?php if (!$topVideos): ?>
                <div class="pf-empty"><p>No videos yet.</p><?php if ($isMe): ?><a class="pf-btn red" href="upload.php">Upload your first video</a><?php endif; ?></div>
            <?php else: ?>
                <div class="pf-strip">
                    <?php foreach ($topVideos as $row):
                        $src = find_source($row, __DIR__);
                        $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                        $title = $row['title'] ?? 'Untitled';
                    ?>
                        <a class="pf-vid" href="watch.php?id=<?= urlencode((string)$row['id']) ?>">
                            <div class="pf-thumb"><img src="<?= $h(thumb_for($row, $src, $yt)) ?>" alt="<?= $h($title) ?>" loading="lazy"><?php if (!empty($row['duration'])): ?><span class="pf-dur"><?= $h($row['duration']) ?></span><?php endif; ?></div>
                            <h3 class="pf-vtitle"><?= $h($title) ?></h3>
                            <p class="pf-vmeta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views &bull; <?= $h(vs_yt_time_ago($row['created_at'] ?? null)) ?></p>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- VIDEOS -->
    <div class="pf-panel" data-panel="videos">
        <?php if (!$videos): ?>
            <div class="pf-empty"><p>No videos yet.</p><?php if ($isMe): ?><a class="pf-btn red" href="upload.php">Upload your first video</a><?php endif; ?></div>
        <?php else: ?>
            <div class="pf-sort" data-sort-for="videoGrid">
                <button type="button" class="pf-chip active" data-sort="latest">Latest</button>
                <button type="button" class="pf-chip" data-sort="popular">Popular</button>
                <button type="button" class="pf-chip" data-sort="oldest">Oldest</button>
            </div>
            <div class="pf-grid" id="videoGrid">
                <?php foreach ($videos as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $title = $row['title'] ?? 'Untitled';
                ?>
                    <a href="watch.php?id=<?= urlencode((string)$row['id']) ?>" class="pf-vid" data-views="<?= (int)($row['views'] ?? 0) ?>" data-score="<?= $popScore($row) ?>" data-ts="<?= (int)strtotime((string)($row['created_at'] ?? '')) ?>">
                        <div class="pf-thumb"><img src="<?= $h(thumb_for($row, $src, $yt)) ?>" alt="<?= $h($title) ?>" loading="lazy"><?php if (!empty($row['duration'])): ?><span class="pf-dur"><?= $h($row['duration']) ?></span><?php endif; ?></div>
                        <h3 class="pf-vtitle"><?= $h($title) ?></h3>
                        <p class="pf-vmeta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views &bull; <?= $h(vs_yt_time_ago($row['created_at'] ?? null)) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- SHORTS -->
    <div class="pf-panel" data-panel="shorts">
        <?php if (!$shorts): ?>
            <div class="pf-empty"><p>No shorts yet.</p><?php if ($isMe): ?><a class="pf-btn red" href="upload.php">Upload a short</a><?php endif; ?></div>
        <?php else: ?>
            <div class="pf-sort" data-sort-for="shortGrid">
                <button type="button" class="pf-chip active" data-sort="latest">Latest</button>
                <button type="button" class="pf-chip" data-sort="popular">Popular</button>
                <button type="button" class="pf-chip" data-sort="oldest">Oldest</button>
            </div>
            <div class="pf-short-grid" id="shortGrid">
                <?php foreach ($shorts as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $title = $row['title'] ?? 'Untitled';
                ?>
                    <a href="shorts.php?id=<?= urlencode((string)$row['id']) ?>" class="pf-vid" data-views="<?= (int)($row['views'] ?? 0) ?>" data-score="<?= $popScore($row) ?>" data-ts="<?= (int)strtotime((string)($row['created_at'] ?? '')) ?>">
                        <div class="pf-thumb tall"><img src="<?= $h(thumb_for($row, $src, $yt)) ?>" alt="<?= $h($title) ?>" loading="lazy"></div>
                        <h3 class="pf-vtitle"><?= $h($title) ?></h3>
                        <p class="pf-vmeta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views</p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- PLAYLISTS -->
    <div class="pf-panel" data-panel="playlists">
        <?php if (!$playlists): ?>
            <div class="pf-empty"><p><?= $isMe ? 'You have no playlists yet.' : 'No public playlists yet.' ?></p><?php if ($isMe): ?><a class="pf-btn red" href="playlists.php">Create a playlist</a><?php endif; ?></div>
        <?php else: ?>
            <?php if ($isMe): ?><p style="margin:0 0 14px"><a class="pf-btn" href="playlists.php">Manage playlists</a></p><?php endif; ?>
            <div class="pf-pl">
                <?php foreach ($playlists as $pl): ?>
                    <div class="pf-pl-item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h14M3 12h14M3 18h8"/><path d="M19 14v6l4-3z" fill="currentColor"/></svg>
                        <div><strong><?= $h($pl['name']) ?></strong><span><?= (int)$pl['n'] ?> video<?= (int)$pl['n'] === 1 ? '' : 's' ?><?= ($isMe && (string)$pl['visibility'] !== 'public') ? ' · ' . $h(ucfirst((string)$pl['visibility'])) : '' ?></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- ABOUT -->
    <div class="pf-panel" data-panel="about">
        <section class="pf-card">
            <div class="pf-card-head"><h2>Description</h2><?php if ($isMe): ?><button type="button" class="pf-btn sm" data-edit-open><?= $ico['pencil'] ?> Edit</button><?php endif; ?></div>
            <?php if ($description !== ''): ?><p class="pf-about-text"><?= $h($description) ?></p><?php else: ?><p class="pf-empty-note"><?= $isMe ? 'You have not written a description yet.' : 'This channel has no description yet.' ?></p><?php endif; ?>
        </section>
        <section class="pf-card">
            <div class="pf-card-head"><h2>Details</h2></div>
            <dl class="pf-dl">
                <?php if ($joinedAt !== '' && strtotime($joinedAt)): ?><div><dt>Joined</dt><dd><?= $h(date('M j, Y', strtotime($joinedAt))) ?></dd></div><?php endif; ?>
                <?php if ($location !== ''): ?><div><dt>Location</dt><dd><?= $h($location) ?></dd></div><?php endif; ?>
                <?php if ($subscriberCount !== null): ?><div><dt>Subscribers</dt><dd><?= $h(vs_short_views($subscriberCount)) ?></dd></div><?php endif; ?>
                <div><dt>Videos</dt><dd><?= count($videos) ?></dd></div>
                <div><dt>Shorts</dt><dd><?= count($shorts) ?></dd></div>
                <div><dt>Total views</dt><dd><?= $h(vs_short_views($totalViews)) ?></dd></div>
                <?php if ($isMe): ?><div><dt>Total likes</dt><dd><?= $h(vs_short_views($totalLikes)) ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($hasLinks): ?>
                <ul class="pf-links">
                    <?php foreach (['youtube', 'discord', 'x', 'website'] as $k): if ($links[$k] === '') { continue; } ?>
                        <li><a href="<?= $h($links[$k]) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $h(vs_link_label($links[$k])) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <!-- MEMBERSHIPS -->
    <div class="pf-panel" data-panel="memberships">
        <section class="pf-card">
            <div class="pf-card-head"><span style="width:30px;height:30px;display:block"><?= $bigCrown ?></span><h2>Memberships</h2></div>
            <div class="pf-member">
                <div class="pf-promo">
                    <?= $bigCrown ?>
                    <div style="flex:1;min-width:0">
                        <h3>Premium Member</h3>
                        <p><?= $isPremium ? ($isMe ? 'Your Premium is active. Thank you for supporting VibeStream!' : 'This creator is a Premium member.') : 'Unlock more features, support creators and enjoy exclusive perks!' ?></p>
                        <a class="join" href="premium.php"><?= $ico['crown'] ?> <?= ($isMe && $isPremium) ? 'Manage Premium' : 'Join Now' ?></a>
                    </div>
                </div>
                <ul class="pf-perks">
                    <li><?= $ico['check'] ?>No Ads</li>
                    <li><?= $ico['check'] ?>Exclusive Badges</li>
                    <li><?= $ico['check'] ?>Early Access to Videos</li>
                    <li><?= $ico['check'] ?>Support Your Creator</li>
                </ul>
            </div>
        </section>
    </div>

    <!-- REWARDS -->
    <div class="pf-panel" data-panel="rewards">
        <section class="pf-card">
            <div class="pf-card-head"><svg viewBox="0 0 24 24" fill="#ffc23d" aria-hidden="true"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1z"/></svg><h2>Rewards</h2></div>
            <?php if ($level): ?>
                <p style="margin:0"><span class="pf-lvl" style="color:<?= $h($level[2]) ?>;font-size:16px"><?= $h($level[0]) ?></span></p>
                <div class="pf-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($levelPct) ?>"><i style="width:<?= (int)round($levelPct) ?>%"></i></div>
                <p class="pf-empty-note" style="margin:0"><?= number_format($earned) ?> points earned<?= $nextLevel ? ' · ' . number_format($nextLevel[1] - $earned) . ' to ' . $h($nextLevel[0]) : ' · top level!' ?></p>
            <?php else: ?>
                <p class="pf-empty-note">No points earned yet. Check in daily, upload videos and complete quests to earn points.</p>
            <?php endif; ?>
            <?php if ($isMe): ?><p style="margin:16px 0 0"><a class="pf-btn red" href="<?= $h($rewardsPage) ?>">Open Rewards</a></p><?php endif; ?>
        </section>
    </div>
<?php endif; ?>
</div>
<?php
vs_page_end(<<<'JS'
(function () {
    var tabs = document.querySelectorAll('.pf-tab');
    var panels = document.querySelectorAll('.pf-panel');
    function openTab(key, updateHash) {
        var found = false;
        tabs.forEach(function (t) { if (t.getAttribute('data-tab') === key) { found = true; } });
        if (!found) { return; }
        tabs.forEach(function (t) { t.setAttribute('aria-selected', t.getAttribute('data-tab') === key ? 'true' : 'false'); });
        panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === key); });
        if (updateHash) { try { history.replaceState(null, '', '#' + key); } catch (e) {} }
    }
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () { openTab(tab.getAttribute('data-tab'), true); });
    });
    document.querySelectorAll('[data-goto]').forEach(function (b) {
        b.addEventListener('click', function () {
            openTab(b.getAttribute('data-goto'), true);
            var bar = document.querySelector('.pf-tabs');
            if (bar) { bar.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
    });
    var initial = (window.location.hash || '').replace('#', '');
    if (initial && initial !== 'edit') { openTab(initial, false); }

    // edit panel
    var edit = document.getElementById('editPanel');
    var toggle = document.getElementById('editToggle');
    function setEdit(open) {
        if (!edit) { return; }
        edit.hidden = !open;
        if (toggle) { toggle.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        if (open) { edit.scrollIntoView({ behavior: 'smooth', block: 'start' }); var f = document.getElementById('descText'); if (f) { f.focus({ preventScroll: true }); } }
    }
    if (toggle) { toggle.addEventListener('click', function () { setEdit(edit.hidden); }); }
    document.querySelectorAll('[data-edit-open]').forEach(function (b) { b.addEventListener('click', function () { setEdit(true); }); });
    document.querySelectorAll('[data-edit-close]').forEach(function (b) { b.addEventListener('click', function () { setEdit(false); }); });
    if (window.location.hash === '#edit') { setEdit(true); }

    // sorting
    document.querySelectorAll('.pf-sort').forEach(function (bar) {
        var grid = document.getElementById(bar.getAttribute('data-sort-for'));
        if (!grid) { return; }
        bar.addEventListener('click', function (e) {
            var chip = e.target.closest('.pf-chip');
            if (!chip) { return; }
            bar.querySelectorAll('.pf-chip').forEach(function (c) { c.classList.toggle('active', c === chip); });
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

    // picture + banner upload: send as soon as a file is chosen
    [['avatarFile', 'avatarForm'], ['bannerFile', 'bannerForm']].forEach(function (pair) {
        var file = document.getElementById(pair[0]);
        var form = document.getElementById(pair[1]);
        if (file && form) {
            file.addEventListener('change', function () { if (file.files && file.files.length) { form.submit(); } });
        }
    });

    // share channel
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