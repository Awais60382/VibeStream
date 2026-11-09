<?php
session_start();
if (is_file(__DIR__ . '/premium-perks.php')) {
    require_once __DIR__ . '/premium-perks.php';
}
require_once __DIR__ . '/avatar.php';   
require_once __DIR__ . '/icons.php';    
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/sidebar-navigation.php';
require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/history-store.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/comment-store.php';
require_once __DIR__ . '/context-notes-store.php';
require_once __DIR__ . '/liked-store.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vip-badge.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
vs_maintenance_gate();
include "connection.php";
require_once __DIR__ . '/dashboard-events.php';

if (!function_exists('column_exists')) {
    function column_exists($conn, $table, $col) {
        static $cache = [];
        $key = "$table.$col";
        if (isset($cache[$key])) return $cache[$key];
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($col);
        $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $cache[$key] = ($r && $r->num_rows > 0);
    }
}
$hasLikesColumn = column_exists($conn, 'videos', 'likes');
$hasDislikesColumn = column_exists($conn, 'videos', 'dislikes');

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
        $priority = ['video_url','video_path','video_file','video','url','file_path','filepath','file','filename','source','src','link','media_url','youtube_url','youtube_id','yt_id','video_link','embed_url','embed','id'];
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

if (!function_exists('thumb_for')) {
    function thumb_for(array $row, $src, $realYt) {
        if ($realYt) return "https://i.ytimg.com/vi/{$realYt}/hqdefault.jpg";
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $c) {
            if (!empty($row[$c])) return $row[$c];
        }
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#1e1e24"/><text x="50%" y="50%" fill="#6f6f7a" font-family="Arial" font-size="16" text-anchor="middle" dy=".3em">No preview</text></svg>');
    }
}

if (!function_exists('time_ago')) {
    function time_ago($datetime) {
        if (empty($datetime)) return 'Recently';
        $time = strtotime($datetime);
        $diff = time() - $time;
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('M j, Y', $time);
    }
}
if (!function_exists('avatar_or_cartoon')) {
    function avatar_or_cartoon($username) {
        $username = trim((string)$username);
        $url = avatar_url($username);
        if ($url !== '') return $url;
        return 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($username !== '' ? $username : 'GuestUser');
    }
}

if (!function_exists('render_navbar')) {
    function render_navbar($active = 'watch') {
        $siteName = vs_branding_app_name();
        $isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
        $username = $isLoggedIn ? $_SESSION['username'] : '';
        $initial = $isLoggedIn ? strtoupper(substr($username, 0, 1)) : 'G';
        $navAvatar = $isLoggedIn ? avatar_url($username) : '';
        $siteLogoUrl = vs_branding_logo_url();
        ?>
        <header class="app-navbar">
            <div class="nav-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <a class="brand" href="index.php">
                    <img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl); ?>" alt="">
                    <span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
                </a>
            </div>

            <div class="nav-center">
                <a class="nav-link<?php echo $active === 'watch' ? ' active' : ''; ?>" href="watch.php">Watch</a>
                <a class="nav-link<?php echo $active === 'shorts' ? ' active' : ''; ?>" href="shorts.php">Shorts</a>
            </div>

            <div class="nav-right">
                <button type="button" class="vs-neon-toggle" data-vs-neon-toggle aria-label="Turn neon off" title="Turn neon off" aria-pressed="true">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M14 1 2 14h8l-1 9 13-15h-8z"/></svg>
                </button>
                <button class="vs-theme-toggle" type="button" data-vs-theme-toggle aria-label="Switch to light mode" title="Switch to light mode">
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg><svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
                </button>
                <?php echo vs_notification_bell_html(); ?>
                <a href="upload.php" class="create-btn">+ Create</a>
                <?php if ($isLoggedIn): ?>
                    <?php echo vs_user_menu_html($username, $navAvatar); ?>
                <?php else: ?>
                    <a href="login.php" class="sign-in-btn">Sign In</a>
                <?php endif; ?>
            </div>
        </header>
        <?php
    }
}

if (!function_exists('render_sidebar')) {
    function render_sidebar($active = 'home') {
        vs_render_sidebar((string)$active);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    function json_out($arr) {
        echo json_encode($arr);
        exit;
    }

    try {
        $vid = trim((string)($_POST['video_id'] ?? ''));
        $action = $_POST['action'] ?? '';

        if ($vid === '') json_out(['success' => false, 'error' => 'Missing video ID']);

        if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            json_out(['success' => false, 'error' => 'That request expired. Refresh the page and try again.']);
        }

        $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?");
        $stmt->bind_param("s", $vid);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$v) json_out(['success' => false, 'error' => 'Video not found']);

        if ($action === 'progress') {
            if (empty($_SESSION['username'])) {
                http_response_code(401);
                json_out(['success' => false, 'error' => 'Sign in to save playback progress.']);
            }
            if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                json_out(['success' => false, 'error' => 'That request expired. Refresh the page and try again.']);
            }
            $position = filter_var($_POST['position'] ?? null, FILTER_VALIDATE_INT);
            $duration = filter_var($_POST['duration'] ?? null, FILTER_VALIDATE_INT);
            if ($position === false || $duration === false || $position < 0 || $duration <= 0 || $duration > 604800) {
                http_response_code(400);
                json_out(['success' => false, 'error' => 'Invalid playback progress.']);
            }
            vs_history_save_progress($conn, $vid, $position, $duration);
            json_out(['success' => true]);
        }

        foreach (['liked_videos', 'disliked_videos', 'subscribed_channels'] as $k) {
            if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) $_SESSION[$k] = [];
        }
        if ($action === 'like' || $action === 'dislike') {
            vs_likes_session_sync($conn);
        }
        if ($action === 'subscribe') {
            vs_subscription_session_sync($conn);
        }
        if (!$hasDislikesColumn) {
            $_SESSION['disliked_videos'] = [];
        }

        $counts = function () use ($conn, $vid, $hasLikesColumn, $hasDislikesColumn) {
            if (!$hasLikesColumn) {
                throw new RuntimeException('Likes are not available because the videos table has no likes column.');
            }
            $dislikesSelect = $hasDislikesColumn ? 'COALESCE(dislikes,0)' : '0';
            $stmt = $conn->prepare("SELECT COALESCE(likes,0) AS l, $dislikesSelect AS d FROM videos WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Could not read the video vote counts.');
            }
            $stmt->bind_param("s", $vid);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Could not read the video vote counts.');
            }
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$result) {
                throw new RuntimeException('Could not read the video vote counts.');
            }
            return $result;
        };

        if ($action === 'like' || $action === 'dislike') {
            $isLike = ($action === 'like');
            if (!$hasLikesColumn) {
                throw new RuntimeException('Likes are not available because the videos table has no likes column.');
            }
            if (!$isLike && !$hasDislikesColumn) {
                json_out([
                    'success' => false,
                    'error' => 'Dislikes are not enabled in this database. Likes are available.',
                ]);
            }
            $mine = $isLike ? 'liked_videos' : 'disliked_videos';
            $other = $isLike ? 'disliked_videos' : 'liked_videos';
            $myCol = $isLike ? 'likes' : 'dislikes';
            $otherCol = $isLike ? 'dislikes' : 'likes';

            $mineActive = $isLike
                ? vs_likes_has($conn, $vid)
                : in_array($vid, $_SESSION[$mine], true);
            $otherActive = $isLike
                ? in_array($vid, $_SESSION[$other], true)
                : vs_likes_has($conn, $vid);
            if ($mineActive) {
                $sql = "UPDATE videos SET $myCol = GREATEST(COALESCE($myCol,0) - 1, 0) WHERE id = ?";
            } elseif ($otherActive) {
                $sql = "UPDATE videos SET $myCol = COALESCE($myCol,0) + 1, $otherCol = GREATEST(COALESCE($otherCol,0) - 1, 0) WHERE id = ?";
            } else {
                $sql = "UPDATE videos SET $myCol = COALESCE($myCol,0) + 1 WHERE id = ?";
            }

            $likedBefore = $_SESSION['liked_videos'];
            $dislikedBefore = $_SESSION['disliked_videos'];
            try {
                $conn->begin_transaction();
                $upd = $conn->prepare($sql);
                if (!$upd) {
                    throw new RuntimeException('Could not save your video vote. Please try again.');
                }
                $upd->bind_param("s", $vid);
                if (!$upd->execute() || $upd->affected_rows !== 1) {
                    $upd->close();
                    throw new RuntimeException('Could not save your video vote. Please try again.');
                }
                $upd->close();

                if ($isLike) {
                    vs_likes_set($conn, $vid, !$mineActive);
                    if (!$mineActive && $otherActive) {
                        $_SESSION[$other] = array_values(array_diff($_SESSION[$other], [$vid]));
                    }
                } else {
                    $_SESSION[$mine] = $mineActive
                        ? array_values(array_diff($_SESSION[$mine], [$vid]))
                        : array_merge($_SESSION[$mine], [$vid]);
                    if (!$mineActive && $otherActive) {
                        vs_likes_set($conn, $vid, false);
                    }
                }
                $conn->commit();
            } catch (Throwable $voteError) {
                $conn->rollback();
                $_SESSION['liked_videos'] = $likedBefore;
                $_SESSION['disliked_videos'] = $dislikedBefore;
                throw $voteError;
            }

            // Tell the uploader someone liked their video (only when a like was added).
            if ($isLike && !$mineActive && !empty($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? '')) {
                $actor = (string)($_SESSION['username'] ?? $_SESSION['AdminSession']);
                vs_notify_video_event($conn, $v, 'like', $actor);
            }

            $c = $counts();
            json_out([
                'success' => true,
                'likes' => (int)$c['l'],
                'dislikes' => (int)$c['d'],
                'liked' => in_array($vid, $_SESSION['liked_videos'], true),
                'disliked' => in_array($vid, $_SESSION['disliked_videos'], true),
            ]);
        }

        if ($action === 'subscribe') {
            $channelName = trim((string)($v['channel_name'] ?? ''));
            $channelUsername = trim((string)($v['username'] ?? ''));
            if ($channelName === '') $channelName = $channelUsername;
            $result = vs_subscription_change($conn, $channelName, $channelUsername, $vid);
            json_out(['success' => true] + $result);
        }

        if ($action === 'comment') {
            $text = mb_substr(trim((string)($_POST['comment_text'] ?? '')), 0, 500);
            if ($text === '') json_out(['success' => false, 'error' => 'Comment is empty']);
            $user = $_SESSION['username'] ?? 'Guest';

            $ins = $conn->prepare("INSERT INTO comments (video_id, username, comment_text) VALUES (?, ?, ?)");
            $ins->bind_param("sss", $vid, $user, $text);
            $ins->execute();
            $newCommentId = (int)$conn->insert_id;
            $ins->close();
            vs_notify_video_event($conn, $v, 'comment', (string)$user, $text);
            vs_notify_comment_reply($conn, $v, (string)$user, $text, $newCommentId);

            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM comments WHERE video_id = ?");
            $stmt->bind_param("s", $vid);
            $stmt->execute();
            $n = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            json_out([
                'success' => true,
                'username' => $user,
                'comment_text' => $text,
                'comment_count' => $n,
                'id' => $newCommentId,
                'html' => vs_comment_new_item_html($newCommentId, (string)$user, $text),
                'avatar' => avatar_or_cartoon($user), 
            ]);
        }

        $commentResult = vs_comments_handle_action($conn, (string)$action, $vid);
        if ($commentResult !== null) json_out($commentResult);

        json_out(['success' => false, 'error' => 'Unknown action']);
    } catch (Throwable $e) {
        json_out(['success' => false, 'error' => $e->getMessage()]);
    }
}

if (!function_exists('vs_random_pick_video')) {
    /**
     * Picks a random video for the Watch / Shorts buttons.
     * It remembers what this visitor was already shown (in their session) and
     * goes through every video before any repeats, so each press feels fresh.
     */
    function vs_random_pick_video(mysqli $conn, string $sessionKey, string $where): ?array
    {
        $seen = (isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey])) ? array_values($_SESSION[$sessionKey]) : [];
        $last = $seen ? (string)end($seen) : '';
        $rounds = [$seen, $last !== '' ? [$last] : [], []];
        foreach ($rounds as $index => $exclude) {
            if ($index > 0 && $exclude === $rounds[$index - 1]) {
                continue;
            }
            $sql = "SELECT * FROM videos WHERE $where";
            if ($exclude) {
                $sql .= ' AND id NOT IN (' . implode(',', array_fill(0, count($exclude), '?')) . ')';
            }
            $sql .= ' ORDER BY RAND() LIMIT 1';
            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                return null;
            }
            if ($exclude) {
                $params = array_map('strval', $exclude);
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            }
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                /* a finished round starts again from just the video that was picked */
                $keep = ($index === 0) ? $seen : [];
                $keep[] = (string)$row['id'];
                $_SESSION[$sessionKey] = array_slice($keep, -500);
                return $row;
            }
        }
        return null;
    }
}

$hasTypeColumn = column_exists($conn, 'videos', 'type');
$id = trim($_GET['id'] ?? '');
$video = null;

if ($id !== '') {
    if ($stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?")) {
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $video = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$video) {
    // No (valid) video in the address: show a different random one every time
    // the Watch button is pressed, then move to that video's own address so a
    // refresh or a shared link keeps pointing at it.
    $where = $hasTypeColumn ? "COALESCE(type,'video') <> 'short'" : "1=1";
    $video = vs_random_pick_video($conn, 'random_seen_watch', $where);
    if ($video && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header('Cache-Control: no-store');
        header('Location: watch.php?id=' . urlencode((string)$video['id']));
        exit;
    }
}

if (!$video) {
    $video = [
        'id' => 0,
        'title' => 'Welcome to ' . $siteName,
        'channel_name' => $siteName,
        'subscribers' => 0,
        'upload_date' => 'just now',
        'description' => 'Upload your first video to get started.',
        'likes' => 0,
        'dislikes' => 0,
        'video_url' => '',
        'views' => 0,
    ];
}

if ($hasTypeColumn && ($video['type'] ?? '') === 'short') {
    header("Location: shorts.php?id=" . urlencode($video['id']));
    exit;
}

$id = $video['id'];


// Save to the signed-in user's watch history (History page)
if ($id !== 0 && !empty($_SESSION['username'])) {
    try {
        vs_history_record($conn, (string)$id);
    } catch (Throwable $historyError) {
        error_log('Watch history failed: ' . $historyError->getMessage());
    }
}
$savedProgress = 0;
if ($id !== 0 && !empty($_SESSION['username'])) {
    try {
        $savedProgress = vs_history_get_progress($conn, (string)$id);
    } catch (Throwable $progressError) {
        error_log('Playback progress load failed: ' . $progressError->getMessage());
    }
}

$title = $video['title'] ?? 'Video not found';
$views = (int)($video['views'] ?? 0);
$channel = trim((string)($video['channel_name'] ?? ''));
if ($channel === '') $channel = trim((string)($video['username'] ?? 'Unknown channel'));
$subs = (int)($video['subscribers'] ?? 0);
$upload_date = $video['upload_date'] ?? '';
if (trim((string)$upload_date) === '' && !empty($video['created_at'])) {
    $upload_date = time_ago($video['created_at']);
}
if (trim((string)$upload_date) === '') $upload_date = 'Recently';
$description = $video['description'] ?? '';
$likes = (int)($video['likes'] ?? 0);
$dislikes = (int)($video['dislikes'] ?? 0);
if (!$hasDislikesColumn) {
    $dislikes = 0;
}

$src = find_source($video, __DIR__);
$yt_id = $src !== '' ? extract_youtube_id($src, true) : null;
$is_direct = $src !== '' && !$yt_id;
$playerPoster = $yt_id ? thumb_for($video, $src, $yt_id) : thumb_for($video, $src, null);
$requestScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$playerOrigin = $requestScheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$requestedStart = array_key_exists('t', $_GET) ? (int)$_GET['t'] : $savedProgress;
$startAt = max(0, min(86400, $requestedStart)); // ?t=SECONDS opens the video at that time

$channelOwner = trim((string)($video['username'] ?? ''));
if ($channelOwner === '') $channelOwner = $channel;
$trySubsFallback = $subs;
$subs = vs_subscription_channel_count($conn, $channel, $channelOwner, $trySubsFallback);
$viewerName = trim((string)($_SESSION['username'] ?? ''));
$isOwnChannel = $viewerName !== '' && strcasecmp($viewerName, $channelOwner) === 0;
$avatar_url = avatar_for_record($channelOwner, $video);
if ($avatar_url === '' && $channelOwner !== $channel) $avatar_url = avatar_url($channel);
$user_avatar = avatar_or_cartoon($_SESSION['username'] ?? '');
$channelInitial = mb_strtoupper(mb_substr(trim((string)$channel) !== '' ? trim((string)$channel) : 'V', 0, 1));

foreach (['liked_videos', 'disliked_videos', 'subscribed_channels'] as $k) {
    if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) $_SESSION[$k] = [];
}
try {
    vs_likes_session_sync($conn);
    $userHasLiked = vs_likes_has($conn, (string)$id);
} catch (Throwable $likesError) {
    error_log('Could not synchronize saved video likes: ' . $likesError->getMessage());
    $userHasLiked = in_array((string)$id, array_map('strval', $_SESSION['liked_videos']), true);
}
try {
    vs_subscription_session_sync($conn);
} catch (Throwable $subscriptionError) {
    error_log('Could not synchronize VibeStream subscriptions: ' . $subscriptionError->getMessage());
}
if (!$hasDislikesColumn) {
    $_SESSION['disliked_videos'] = [];
}
$userHasDisliked = in_array($id, $_SESSION['disliked_videos'], true);
$channelKey = vs_subscription_key($channel);
$userHasSubscribed = in_array($channelKey, $_SESSION['subscribed_channels'], true);

try {
    $comments = vs_comments_fetch($conn, (string)$id, $viewerName);
} catch (Throwable $commentsError) {
    error_log('Comments failed: ' . $commentsError->getMessage());
    $comments = [];
}
$commentCount = count($comments);
$contextNotes = [];
$contextNotesAvailable = true;
try {
    $contextNotes = vs_context_notes_fetch($conn, (string)$id, 'video');
} catch (Throwable $contextNotesError) {
    error_log('Community context notes failed: ' . $contextNotesError->getMessage());
    $contextNotesAvailable = false;
}

$related = [];
$relatedParams = [$id];
$relatedTypes = 's';
$relatedOrder = [];
$hasCategoryColumn = column_exists($conn, 'videos', 'category');
if ($hasCategoryColumn && trim((string)($video['category'] ?? '')) !== '') {
    $relatedOrder[] = "CASE WHEN LOWER(TRIM(category)) = LOWER(TRIM(?)) THEN 0 ELSE 1 END";
    $relatedParams[] = trim((string)$video['category']);
    $relatedTypes .= 's';
}
$relatedOrder[] = 'COALESCE(views, 0) DESC';
$relatedOrder[] = 'created_at DESC';
$relSql = $hasTypeColumn
    ? "SELECT * FROM videos WHERE id <> ? AND COALESCE(type,'video') <> 'short' ORDER BY " . implode(', ', $relatedOrder) . ' LIMIT 10'
    : "SELECT * FROM videos WHERE id <> ? ORDER BY " . implode(', ', $relatedOrder) . ' LIMIT 10';
if ($stmt = $conn->prepare($relSql)) {
    $stmt->bind_param($relatedTypes, ...$relatedParams);
    $stmt->execute();
    $rres = $stmt->get_result();
    while ($rrow = $rres->fetch_assoc()) $related[] = $rrow;
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="vs-default-theme" content="<?php echo htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="theme-color" content="<?php echo $defaultTheme === 'dark' ? '#b02a2a' : '#ffffff'; ?>">
    <meta name="description" content="<?php echo htmlspecialchars(vs_branding_app_description(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="branding-icon.php?size=96">
    <link rel="shortcut icon" type="image/png" href="branding-icon.php?size=96">
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="branding-icon.php">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?> - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="theme.js?v=3"></script>
    <script src="brain.js?v=<?php echo (int)@filemtime(__DIR__ . '/brain.js'); ?>" defer></script>
    <link rel="stylesheet" href="neon-off.css?v=7">
    <script src="neon-switch.js?v=7"></script>
    <link rel="stylesheet" href="style.css?v=5">
    <link rel="stylesheet" href="context-notes.css?v=1">

    <style>
        :root {
            --bg: #0f0f0f;
            --surface: #181818;
            --surface-2: #272727;
            --surface-3: #1f1f1f;
            --text: #f1f1f1;
            --muted: #aaaaaa;
            --border: #303030;
            --accent: #b02a2a;
            --accent-hover: #1f1f1f;
            --accent-soft: rgba(176, 42, 42, 0.18);
            --blue: #3ea6ff;
            --navbar-height: 72px;
            --sidebar-width: 240px;
            --radius-md: 12px;
            --radius-pill: 999px;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background-color: var(--bg);
            color: var(--text);
            font-family: Roboto, Arial, sans-serif;
            overflow-x: hidden;
        }

        a { color: inherit; text-decoration: none; }
        button, input { font: inherit; }

        .app-navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--navbar-height);
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 18px;
            padding: 0 20px;
            background: rgba(15, 15, 15, 0.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            z-index: 1000;
        }

        .nav-left, .nav-center, .nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .nav-center { justify-content: center; }
        .nav-right { justify-content: flex-end; }

        .brand {
                    display: inline-flex; align-items: center; gap: 9px;
                    font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }
        .brand .site-brand-icon { width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex: 0 0 auto; }

        .nav-toggle {
            width: 44px;
            height: 44px;
            border: none;
            background: transparent;
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 5px;
            padding: 0 10px;
            cursor: pointer;
        }

        .nav-toggle:hover { background: var(--surface-3); }
        .nav-toggle span {
            display: block;
            width: 100%;
            height: 2px;
            border-radius: 999px;
            background: var(--text);
        }

        .nav-link {
            background: var(--surface-2);
            border-radius: var(--radius-pill);
            padding: 8px 14px;
            font-size: 14px;
            color: var(--text);
            border: 1px solid transparent;
        }

        .nav-link.active {
            background: var(--accent-soft);
            color: #ffb3b3;
            border-color: rgba(176, 42, 42, 0.45);
        }

        .create-btn, .sign-in-btn {
            background: var(--accent);
            color: #ffffff;
            padding: 10px 16px;
            border-radius: var(--radius-pill);
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .user-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(180deg, #252526 0%, #1b1b1d 100%);
            border: 1px solid #3a3a3d;
            border-radius: 999px;
            padding: 5px 14px 5px 6px;
            min-height: 46px;
        }

        .user-avatar-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #3ea6ff;
            color: #ffffff;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            overflow: hidden;
        }
        .user-avatar-circle img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .user-name {
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            white-space: nowrap;
        }

        .logout-link {
            color: #b9b9bb;
            font-size: 13px;
            padding-left: 12px;
            margin-left: 2px;
            border-left: 1px solid #444449;
            white-space: nowrap;
        }

        .logout-link:hover { color: #ffffff; }

        .app-layout { min-height: 100vh; padding-top: var(--navbar-height); }

        .app-sidebar {
            padding: 18px 12px 24px;
            background: #111111;
            border-right: 1px solid var(--border);
            overflow-y: auto;
        }

        .sidebar-heading {
            font-size: 12px;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            padding: 4px 12px 12px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 10px;
            border-radius: 14px;
            color: var(--text);
            font-size: 15px;
            font-weight: 500;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-link:hover { background: var(--accent-hover); }
        .sidebar-link.active { background: var(--accent); color: #ffffff; }

        .sidebar-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-icon svg {
            width: 20px;
            height: 20px;
        }

        .watch-container {
            display: flex;
            gap: 24px;
            padding: 24px;
            margin: 0 auto;
            max-width: 1480px;
        }

        .watch-container.theater-mode {
            display: block;
            max-width: none;
        }
        .watch-container.theater-mode .main-player-section,
        .watch-container.theater-mode .recommendations-section {
            width: 100%;
            max-width: 1600px;
            margin-left: auto;
            margin-right: auto;
        }
        .watch-container.theater-mode .recommendations-section { margin-top: 28px; }
        .watch-container.theater-mode .video-wrapper {
            padding-bottom: min(56.25%, 72vh);
        }

        .main-player-section { flex: 1; min-width: 0; }

        .video-wrapper {
            position: relative;
            padding-bottom: 56.25%;
            height: 0;
            overflow: hidden;
            border-radius: var(--radius-md);
            background-color: #000000;
        }

        .video-wrapper iframe, .video-wrapper video {
            position: absolute;
            top: 0; left: 0; width: 100%; height: 100%; border: 0;
        }
        .youtube-click-player {
            position: absolute;
            inset: 0;
            z-index: 4;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border: 0;
            padding: 0;
            background: #000;
            cursor: pointer;
        }
        .youtube-click-player.is-hidden { display: none; }
        .youtube-click-player img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .youtube-play-button {
            position: relative;
            z-index: 1;
            width: 72px;
            height: 50px;
            border: 0;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at 35% 28%, rgba(255,255,255,.28), transparent 18%),
                linear-gradient(145deg, #ff2e93 0%, #9d4edd 48%, #00d9ff 100%);
            border: 2px solid rgba(255,255,255,.92);
            opacity: .88;
            box-shadow:
                0 0 20px rgba(255,46,147,.82),
                0 0 36px rgba(138,92,255,.62),
                0 12px 32px rgba(0,0,0,.48);
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .youtube-click-player:hover .youtube-play-button {
            transform: scale(1.08);
            box-shadow:
                0 0 32px rgba(255,46,147,1),
                0 0 54px rgba(138,92,255,.82),
                0 14px 36px rgba(0,0,0,.52);
        }
        .youtube-play-button::before {
            content: "V";
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            font-family: Arial Black, Impact, sans-serif;
            font-size: 32px;
            line-height: 1;
            font-style: italic;
            font-weight: 900;
            letter-spacing: -2px;
            transform: translate(-1px, 0);
            color: #fff;
            text-shadow:
                -2px 0 0 rgba(0,229,255,.88),
                2px 2px 0 rgba(138,92,255,.88),
                0 5px 12px rgba(0,0,0,.48);
        }
        .youtube-error-player {
            position: absolute;
            inset: 0;
            z-index: 5;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 20px;
            text-align: center;
            color: #fff;
            background: rgba(0,0,0,.88);
        }
        .youtube-error-player.is-visible { display: flex; }
        .youtube-error-player strong { font-size: 16px; }
        .youtube-error-player a {
            display: inline-flex;
            align-items: center;
            min-height: 42px;
            padding: 9px 16px;
            border-radius: 999px;
            background: #ff2e93;
            color: #fff;
            font-weight: 700;
        }

        .mini-player-placeholder { width: 100%; height: 0; flex: 0 0 auto; }
        .mini-player-placeholder.is-active { height: auto; aspect-ratio: 16 / 9; }
        .video-wrapper.mini-player-active {
            position: fixed; right: 20px; bottom: 20px; z-index: 2200;
            width: min(360px, calc(100vw - 24px)); height: auto; aspect-ratio: 16 / 9;
            padding: 0; border: 1px solid rgba(255,255,255,.25);
            border-radius: 12px; box-shadow: 0 12px 38px rgba(0,0,0,.55);
        }
        .mini-player-actions {
            position: absolute; top: 8px; right: 8px; z-index: 3;
            display: none; gap: 6px;
        }
        .video-wrapper.mini-player-active .mini-player-actions { display: flex; }
        .mini-player-actions button {
            min-width: 34px; min-height: 34px; padding: 6px 10px;
            border: 1px solid rgba(255,255,255,.28); border-radius: 8px;
            background: rgba(0,0,0,.76); color: #fff; font: inherit;
            font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .mini-player-actions button:hover { background: rgba(45,45,45,.95); }
        @media (max-width: 640px) {
            .video-wrapper.mini-player-active { right: 12px; bottom: 12px; width: min(320px, calc(100vw - 24px)); }
        }

        .video-wrapper .unavailable-msg {
            position: absolute;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            color: var(--muted); font-size: 15px; text-align: center; width: 90%; margin: 0;
        }

        .video-title { font-size: 20px; font-weight: 700; margin: 12px 0 8px 0; line-height: 1.4; }
        .channel-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 12px; flex-wrap: wrap; gap: 12px;
        }
        .channel-info { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .channel-avatar {
            display: grid; place-items: center;
            width: 44px; height: 44px; border: 2px solid rgba(255,255,255,.78);
            border-radius: 50%; background: linear-gradient(135deg, #1687ff, #a334d9);
            color: #ffffff; overflow: hidden; flex: 0 0 44px;
            box-shadow: 0 2px 9px rgba(0,0,0,.32);
        }
        .channel-avatar img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; }
        .channel-avatar .avatar-initial { font-size: 17px; font-weight: 800; line-height: 1; }
        .channel-text { min-width: 0; }
        .channel-text h3 { margin: 0; font-size: 16px; font-weight: 600; }
        .channel-text p { margin: 2px 0 0 0; font-size: 12px; color: var(--muted); }

        .subscribe-btn {
            background-color: var(--text); color: var(--bg); border: none; padding: 8px 16px;
            border-radius: 18px; font-weight: 600; font-size: 14px; cursor: pointer;
            margin-left: 12px; transition: background 0.2s ease;
        }
        .subscribe-btn:hover { background-color: #d9d9d9; }
        .subscribe-btn.subscribed { background-color: var(--surface-2); color: var(--text); }
        .subscribe-btn:disabled { opacity: 0.6; cursor: default; }

        .action-buttons { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .btn-pill {
            background-color: var(--surface-2); color: var(--text); border: none; padding: 8px 14px;
            border-radius: 18px; font-size: 14px; font-weight: 500; cursor: pointer;
            display: flex; align-items: center; gap: 6px; transition: background 0.2s ease;
        }
        .btn-pill:hover { background-color: #3f3f3f; }

        .like-dislike-group { display: flex; background-color: var(--surface-2); border-radius: 18px; overflow: hidden; }
        .like-dislike-group button {
            background: none; border: none; color: var(--text); padding: 8px 14px;
            font-size: 14px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .like-dislike-group button svg { width: 18px; height: 18px; flex: 0 0 18px; }
        .like-dislike-group button:hover:not(:disabled) { background-color: #3f3f3f; }
        .like-dislike-group #like-btn.active { background-color: rgba(62, 166, 255, 0.14); color: var(--blue); }
        .like-dislike-group #dislike-btn.active { background-color: rgba(255, 107, 99, 0.14); color: #ff8a83; }
        .like-dislike-group button:disabled { color: #777777; cursor: not-allowed; opacity: .55; }
        .like-dislike-group .divider { width: 1px; background-color: #3f3f3f; margin: 6px 0; }

        .description-box {
            display: block; width: fit-content; min-width: 160px; max-width: min(100%, 420px); box-sizing: border-box;
            background-color: var(--surface-2); border-radius: var(--radius-md); padding: 8px 12px; margin-top: 10px;
            font-size: 13px; line-height: 1.4; white-space: normal;
        }
        .description-meta { font-weight: 600; white-space: nowrap; }
        .description-meta + #main-description { margin-top: 6px; }
        #main-description { white-space: pre-line; overflow-wrap: anywhere; max-height: 5.6em; overflow-y: auto; }

        .comments-section { margin-top: 24px; }
        .comments-header { display: flex; align-items: center; gap: 24px; margin-bottom: 16px; }
        .comments-header h3 { margin: 0; font-size: 20px; }
        .add-comment-box { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 24px; }
        .comment-input-container { flex: 1; display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
        .comment-input-line {
            width: 100%; background: none; border: none; border-bottom: 1px solid #3f3f3f;
            color: var(--text); padding: 8px 0; font-size: 14px; outline: none;
        }
        .comment-input-line:focus { border-bottom-color: var(--text); }
        .comment-submit-btn {
            background-color: var(--blue); color: var(--bg); border: none; padding: 8px 16px;
            border-radius: 18px; font-weight: 600; font-size: 14px; cursor: pointer;
        }
        .comment-submit-btn:disabled { background-color: var(--surface-2); color: var(--muted); cursor: not-allowed; }

        .comment-list { display: flex; flex-direction: column; gap: 16px; }
        .comment-item { display: flex; gap: 12px; align-items: flex-start; }
        .comment-body { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .comment-author {
            font-size: 13px; font-weight: 600; color: var(--text); display: flex; gap: 8px; align-items: center;
        }
        .comment-time { font-weight: 400; color: var(--muted); font-size: 12px; }
        .comment-text { font-size: 14px; color: var(--text); line-height: 1.4; word-break: break-word; }

        .recommendations-section { width: 380px; min-width: 340px; }
        .recommendations-title { font-size: 16px; margin: 0; }
        .rec-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 16px 0; }
        .autoplay-toggle { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--text-muted, #aaa); cursor: pointer; user-select: none; }
        .autoplay-toggle input { position: absolute; opacity: 0; pointer-events: none; }
        .autoplay-track { position: relative; width: 34px; height: 18px; border-radius: 999px; background: #555; transition: background 0.15s; }
        .autoplay-track::after { content: ""; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform 0.15s; }
        .autoplay-toggle input:checked + .autoplay-track { background: #3ea6ff; }
        .autoplay-toggle input:checked + .autoplay-track::after { transform: translateX(16px); }
        .autoplay-toggle input:focus-visible + .autoplay-track { outline: 2px solid #fff; outline-offset: 2px; }
        .upnext {
            position: fixed; left: 20px; bottom: 20px; z-index: 2000; max-width: min(360px, calc(100vw - 40px));
            display: flex; flex-direction: column; gap: 10px; padding: 14px 16px; border-radius: 14px;
            background: #212121; color: #fff; border: 1px solid #3a3a3a; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        }
        .upnext b { font-size: 13px; color: #aaa; font-weight: 600; }
        .upnext span { font-size: 15px; line-height: 1.3; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .upnext div { display: flex; gap: 8px; }
        .upnext button { border: 0; border-radius: 999px; padding: 7px 14px; font-weight: 600; cursor: pointer; background: #3a3a3a; color: #fff; }
        .upnext button.primary { background: #fff; color: #000; }
        .video-card.compact {
            display: flex; flex-direction: row; gap: 12px; align-items: flex-start;
            margin-bottom: 12px; cursor: pointer; text-decoration: none;
        }
        .video-card.compact:hover .video-title { color: var(--blue); }
        .video-card.compact .thumbnail {
            position: relative; width: 168px; min-width: 168px; height: 94px;
            border-radius: var(--radius-md); overflow: hidden; background-color: var(--surface-2);
        }
        .video-card.compact .thumbnail img { width: 100%; height: 100%; object-fit: cover; }
        .video-card.compact .video-info { display: flex; flex-direction: column; }
        .video-card.compact .video-title {
            font-size: 14px; font-weight: 600; line-height: 1.3; margin: 0 0 4px 0;
            color: var(--text); display: -webkit-box; -webkit-line-clamp: 2;
            -webkit-box-orient: vertical; overflow: hidden;
        }
        .video-card.compact .channel-name, .video-card.compact .video-meta { font-size: 12px; color: var(--muted); margin: 2px 0; }

        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
            background: var(--surface-2); color: #ffffff; padding: 10px 18px; border-radius: 8px;
            font-size: 14px; display: none; z-index: 1200;
        }

        @media (max-width: 1200px) {
            .watch-container { gap: 18px; }
            .recommendations-section { width: 340px; min-width: 300px; }
        }

        @media (max-width: 1000px) {
            .watch-container { flex-direction: column; padding: 16px; }
            .recommendations-section { width: 100%; min-width: 0; }
            .watch-container.theater-mode .video-wrapper { padding-bottom: 56.25%; }
        }

        @media (max-width: 760px) {
            .app-navbar { grid-template-columns: auto 1fr auto; gap: 12px; padding: 0 12px; }
            .brand { font-size: 20px; }
            .nav-center { display: none; }
            .create-btn { display: none; }
            .user-name, .logout-link { display: none; }
            .user-badge-pill { padding-right: 6px; }
        }

        @media (max-width: 640px) {
            .channel-bar { align-items: flex-start; }
            .channel-info { width: 100%; flex-wrap: nowrap; gap: 9px; }
            .channel-text { flex: 1 1 auto; }
            .subscribe-btn { margin-left: auto; padding: 8px 13px; }
            .action-buttons { width: 100%; gap: 6px; }
            .like-dislike-group button { padding: 8px 10px; }
            .btn-pill { padding: 8px 10px; }
            .video-card.compact .thumbnail {
                width: 140px; min-width: 140px; height: 78px;
            }
        }
    </style>
    <!-- Must stay AFTER the <style> block above -->
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="theme.css?v=5">
    <link rel="stylesheet" href="navbar-fix.css?v=4">
    <link rel="stylesheet" href="comments.css?v=1">
    <!-- NEON THEME (dark + light). Stays after theme.css so it wins. -->
    <style id="neon-dark">
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2e93; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #00e5ff;
            --neon-cyan: #00e5ff; --neon-pink: #ff2e93; --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(0,229,255,.35); box-shadow: 0 1px 24px -4px rgba(0,229,255,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) .brand { background: linear-gradient(90deg, #00e5ff, #8a5cff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) .nav-toggle span { background: #00e5ff; box-shadow: 0 0 6px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) .nav-toggle:hover, html:not([data-theme="light"]) .theme-toggle:hover { background: rgba(0,229,255,.14); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) .create-btn, html:not([data-theme="light"]) .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) #search-input:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 20px rgba(0,229,255,.55); }
        html:not([data-theme="light"]) .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) .sidebar-link:hover { background: rgba(0,229,255,.10); box-shadow: inset 3px 0 0 #00e5ff, 0 0 16px -4px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) .sidebar-heading { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #8a5cff, #ff2e93); border-radius: 8px; }
        html:not([data-theme="light"]) ::selection { background: rgba(255,46,147,.4); color: #fff; }

        /* ---- watch page ---- */
        html:not([data-theme="light"]) .video-wrapper { border: 1px solid rgba(138,92,255,.5); box-shadow: 0 0 28px -4px rgba(0,229,255,.45), 0 0 60px -16px rgba(138,92,255,.55); }
        html:not([data-theme="light"]) .video-title { text-shadow: 0 0 14px rgba(138,92,255,.55); }
        html:not([data-theme="light"]) .channel-avatar { box-shadow: 0 0 0 2px rgba(255,46,147,.75), 0 0 12px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) .subscribe-btn { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 18px rgba(255,46,147,.5); transition: box-shadow .18s ease, transform .18s ease; }
        html:not([data-theme="light"]) .subscribe-btn:hover { background: linear-gradient(90deg, #8a5cff, #ff2e93); box-shadow: 0 0 26px rgba(255,46,147,.8); transform: translateY(-1px); }
        html:not([data-theme="light"]) .subscribe-btn.subscribed { background: rgba(23,28,66,.7); color: #eaf0ff; border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 14px rgba(0,229,255,.35); }
        html:not([data-theme="light"]) .btn-pill, html:not([data-theme="light"]) .like-dislike-group { background: rgba(23,28,66,.7); border: 1px solid rgba(138,92,255,.4); color: #eaf0ff; transition: box-shadow .18s ease, border-color .18s ease; }
        html:not([data-theme="light"]) .btn-pill:hover { background: rgba(0,229,255,.14); border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) .like-dislike-group button:hover:not(:disabled) { background: rgba(0,229,255,.14); }
        html:not([data-theme="light"]) .like-dislike-group .divider { background: rgba(138,92,255,.5); }
        html:not([data-theme="light"]) .like-dislike-group #like-btn.active { background: rgba(0,229,255,.18); color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.8); }
        html:not([data-theme="light"]) .like-dislike-group #dislike-btn.active { background: rgba(255,46,147,.18); color: #ff7ac0; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) .description-box { background: rgba(10,13,31,.88); border: 1px solid rgba(138,92,255,.35); box-shadow: 0 0 22px -10px rgba(138,92,255,.6); }
        html:not([data-theme="light"]) .comment-input-line { background: transparent; color: #eaf0ff; }
        html:not([data-theme="light"]) .comment-input-line:focus { border-bottom-color: #00e5ff; box-shadow: 0 6px 14px -8px rgba(0,229,255,.8); outline: none; }
        html:not([data-theme="light"]) .comment-submit-btn { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 14px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) .comment-submit-btn:disabled { background: rgba(23,28,66,.7); color: #8f9bd0; box-shadow: none; }
        html:not([data-theme="light"]) .comment-author { color: #00e5ff; }
        html:not([data-theme="light"]) .autoplay-toggle input:checked + .autoplay-track { background: linear-gradient(90deg, #8a5cff, #00e5ff); box-shadow: 0 0 10px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) .upnext { background: rgba(10,13,31,.88); border: 1px solid rgba(0,229,255,.45); box-shadow: 0 0 20px -6px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) .upnext button.primary { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; box-shadow: 0 0 12px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) .video-card.compact .thumbnail { border: 1px solid rgba(138,92,255,.28); transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease; }
        html:not([data-theme="light"]) .video-card.compact:hover .thumbnail { border-color: #00e5ff; box-shadow: 0 0 20px rgba(0,229,255,.55), 0 0 40px -8px rgba(138,92,255,.5); transform: translateY(-2px); }
        html:not([data-theme="light"]) .video-card.compact:hover .video-title { color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) .toast { border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 22px rgba(0,229,255,.45); }
    </style>
    <style id="neon-light">
html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2e93; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #00b8e6;
            --neon-cyan: #00b8e6; --neon-pink: #ff2e93; --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(0,184,230,.55); box-shadow: 0 1px 22px -4px rgba(0,184,230,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] .brand { background: linear-gradient(90deg, #00b8e6, #7c4dff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] .nav-toggle span { background: #7c4dff; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] .nav-toggle:hover, html[data-theme="light"] .theme-toggle:hover { background: rgba(0,184,230,.14); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] .theme-toggle svg { color: #7c4dff; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] .create-btn, html[data-theme="light"] .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] #search-input:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 20px rgba(0,184,230,.55); }
        html[data-theme="light"] .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] .sidebar-link:hover { background: rgba(0,184,230,.12); box-shadow: inset 3px 0 0 #00b8e6, 0 0 16px -4px rgba(0,184,230,.5); }
        html[data-theme="light"] .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] .sidebar-heading { color: #7c4dff; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #7c4dff, #ff2e93); border-radius: 8px; }
        html[data-theme="light"] ::selection { background: rgba(255,46,147,.4); color: #0b1030; }

        /* ---- watch page ---- */
        html[data-theme="light"] .video-wrapper { border: 1px solid rgba(124,77,255,.5); box-shadow: 0 0 28px -4px rgba(0,184,230,.45), 0 0 60px -16px rgba(124,77,255,.55); }
        html[data-theme="light"] .video-title { text-shadow: 0 0 14px rgba(124,77,255,.25); }
        html[data-theme="light"] .channel-avatar { box-shadow: 0 0 0 2px rgba(255,46,147,.75), 0 0 12px rgba(255,46,147,.5); }
        html[data-theme="light"] .subscribe-btn { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 18px rgba(255,46,147,.5); transition: box-shadow .18s ease, transform .18s ease; }
        html[data-theme="light"] .subscribe-btn:hover { background: linear-gradient(90deg, #7c4dff, #ff2e93); box-shadow: 0 0 26px rgba(255,46,147,.8); transform: translateY(-1px); }
        html[data-theme="light"] .subscribe-btn.subscribed { background: #ffffff; color: #0b1030; border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 14px rgba(0,184,230,.35); }
        html[data-theme="light"] .btn-pill, html[data-theme="light"] .like-dislike-group { background: #ffffff; border: 1px solid rgba(124,77,255,.4); color: #0b1030; transition: box-shadow .18s ease, border-color .18s ease; }
        html[data-theme="light"] .btn-pill:hover { background: rgba(0,184,230,.14); border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
        html[data-theme="light"] .like-dislike-group button:hover:not(:disabled) { background: rgba(0,184,230,.14); }
        html[data-theme="light"] .like-dislike-group .divider { background: rgba(124,77,255,.5); }
        html[data-theme="light"] .like-dislike-group #like-btn.active { background: rgba(0,184,230,.18); color: #0086b3; text-shadow: 0 0 10px rgba(0,184,230,.8); }
        html[data-theme="light"] .like-dislike-group #dislike-btn.active { background: rgba(255,46,147,.18); color: #d4147a; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] .description-box { background: #ffffff; border: 1px solid rgba(124,77,255,.35); box-shadow: 0 0 22px -10px rgba(124,77,255,.6); }
        html[data-theme="light"] .comment-input-line { background: transparent; color: #0b1030; }
        html[data-theme="light"] .comment-input-line:focus { border-bottom-color: #00b8e6; box-shadow: 0 6px 14px -8px rgba(0,184,230,.8); outline: none; }
        html[data-theme="light"] .comment-submit-btn { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 14px rgba(255,46,147,.45); }
        html[data-theme="light"] .comment-submit-btn:disabled { background: #ffffff; color: #55608f; box-shadow: none; }
        html[data-theme="light"] .comment-author { color: #0086b3; }
        html[data-theme="light"] .autoplay-toggle input:checked + .autoplay-track { background: linear-gradient(90deg, #7c4dff, #00b8e6); box-shadow: 0 0 10px rgba(0,184,230,.7); }
        html[data-theme="light"] .upnext { background: #ffffff; border: 1px solid rgba(0,184,230,.45); box-shadow: 0 0 20px -6px rgba(0,184,230,.5); }
        html[data-theme="light"] .upnext button.primary { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; box-shadow: 0 0 12px rgba(255,46,147,.5); }

        /* speed menu, share menu and keyboard help */
        .vs-pop { position: fixed; z-index: 2100; min-width: 210px; max-width: calc(100vw - 16px); padding: 8px; border-radius: 14px; display: flex; flex-direction: column; gap: 2px; background: #212121; color: #fff; border: 1px solid #3a3a3a; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5); }
        .vs-pop-title { padding: 4px 12px 6px; font-size: 12px; font-weight: 600; opacity: 0.7; }
        .vs-pop button { display: block; width: 100%; text-align: left; border: 0; background: transparent; color: inherit; padding: 8px 12px; border-radius: 8px; cursor: pointer; font-size: 14px; }
        .vs-pop button:hover { background: rgba(127, 127, 127, 0.22); }
        .vs-pop button.on { font-weight: 700; }
        .vs-pop button.on::after { content: " \2713"; }
        .vs-pop label { display: flex; align-items: center; gap: 8px; padding: 8px 12px 6px; font-size: 13px; opacity: 0.85; cursor: pointer; }
        .vs-help { position: fixed; inset: 0; z-index: 2200; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0, 0, 0, 0.6); }
        .vs-help-box { width: min(420px, 100%); max-height: 90vh; overflow-y: auto; padding: 20px 22px; border-radius: 16px; background: #212121; color: #fff; border: 1px solid #3a3a3a; }
        .vs-help-box h3 { margin: 0 0 12px; font-size: 17px; }
        .vs-help-row { display: flex; justify-content: space-between; gap: 16px; padding: 6px 0; font-size: 14px; border-bottom: 1px solid rgba(127, 127, 127, 0.2); }
        .vs-help-row kbd { font: inherit; font-size: 12px; padding: 1px 8px; border-radius: 6px; background: rgba(127, 127, 127, 0.28); white-space: nowrap; }
        html:not([data-theme="light"]) .vs-pop, html:not([data-theme="light"]) .vs-help-box { background: rgba(10,13,31,.94); border: 1px solid rgba(0,229,255,.45); box-shadow: 0 0 20px -6px rgba(0,229,255,.5); }
        html[data-theme="light"] .vs-pop, html[data-theme="light"] .vs-help-box { background: #ffffff; color: #0b1030; border: 1px solid rgba(0,184,230,.45); box-shadow: 0 0 20px -6px rgba(0,184,230,.5); }
        html[data-theme="light"] .video-card.compact .thumbnail { border: 1px solid rgba(124,77,255,.28); transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease; }
        html[data-theme="light"] .video-card.compact:hover .thumbnail { border-color: #00b8e6; box-shadow: 0 0 20px rgba(0,184,230,.55), 0 0 40px -8px rgba(124,77,255,.5); transform: translateY(-2px); }
        html[data-theme="light"] .video-card.compact:hover .video-title { color: #0086b3; text-shadow: 0 0 10px rgba(0,184,230,.5); }
        html[data-theme="light"] .toast { border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 22px rgba(0,184,230,.45); }
    </style>
    <link rel="preconnect" href="https://www.youtube.com">
    <link rel="preconnect" href="https://i.ytimg.com">
    <link rel="dns-prefetch" href="https://www.google.com">
    <link rel="stylesheet" href="sidebar-rail.css?v=1">
    <script src="sidebar-rail.js?v=1"></script>
</head>
<body>
    <?php render_navbar('watch'); ?>
    <?php echo vs_site_announcement_html($GLOBALS['pdo'] ?? null); ?>

    <div class="admin-shell">
        <div class="sidebar-backdrop" data-sidebar-close></div>

        <div class="app-layout">
            <?php render_sidebar('watch'); ?>

            <main class="app-main">
                <div class="watch-container">
                    <div class="main-player-section">
                        <div class="video-wrapper">
                            <?php if ($yt_id): ?>
                                <iframe
                                    id="main-iframe"
                                    src="about:blank"
                                    title="Video player"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allowfullscreen></iframe>
                                <button class="youtube-click-player" id="youtube-click-player" type="button" aria-label="Play video">
                                    <img src="<?php echo htmlspecialchars($playerPoster); ?>" alt="">
                                    <span class="youtube-play-button" aria-hidden="true">
                                    </span>
                                </button>
                                <div class="youtube-error-player" id="youtube-error-player">
                                    <strong>This browser cannot play the embedded video.</strong>
                                    <a href="https://www.youtube.com/watch?v=<?php echo htmlspecialchars($yt_id); ?>" target="_blank" rel="noopener noreferrer">Watch on YouTube</a>
                                </div>
                            <?php elseif ($is_direct): ?>
                                <video id="main-video" src="<?php echo htmlspecialchars($src); ?>" controls autoplay playsinline preload="auto">
                                    Your browser does not support the video tag.
                                </video>
                            <?php else: ?>
                                <p class="unavailable-msg">
                                    Video unavailable<br>
                                    <span style="font-size:12px; color:#777;">
                                        No video link or file was saved for this video (id <?php echo htmlspecialchars((string)$id); ?>).
                                    </span>
                                </p>
                            <?php endif; ?>
                            <?php if ($yt_id || $is_direct): ?>
                                <div class="mini-player-actions" aria-label="Mini-player controls">
                                    <button type="button" id="mini-player-return" title="Return to video">Return</button>
                                    <button type="button" id="mini-player-close" title="Close mini-player" aria-label="Close mini-player">&times;</button>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="video-details-container">
                            <h1 id="main-title" class="video-title"><?php echo htmlspecialchars($title); ?></h1>

                            <div class="channel-bar">
                                <div class="channel-info">
                                    <div class="channel-avatar">
                                        <?php if ($avatar_url !== ''): ?>
                                            <img id="main-avatar" src="<?php echo htmlspecialchars($avatar_url); ?>" alt="<?php echo htmlspecialchars($channel); ?> channel avatar">
                                        <?php else: ?>
                                            <span class="avatar-initial" aria-label="<?php echo htmlspecialchars($channel); ?> channel avatar"><?php echo htmlspecialchars($channelInitial); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="channel-text">
                                        <h3 id="main-channel"><a href="profile.php?u=<?php echo rawurlencode($channelOwner); ?>"><?php echo htmlspecialchars($channel); ?></a><?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html($channelOwner) : ''; ?></h3>
                                        <p id="main-subs"><?php echo $subs; ?> subscriber<?php echo $subs == 1 ? '' : 's'; ?></p>
                                    </div>
                                    <?php if (!$isOwnChannel): ?>
                                        <button id="subscribe-btn" type="button" class="subscribe-btn<?php echo $userHasSubscribed ? ' subscribed' : ''; ?>">
                                            <?php echo $userHasSubscribed ? 'Subscribed' : 'Subscribe'; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <div class="action-buttons">
                                    <div class="like-dislike-group">
                                        <button id="like-btn" type="button" aria-label="Like video" aria-pressed="<?php echo $userHasLiked ? 'true' : 'false'; ?>" class="<?php echo $userHasLiked ? 'active' : ''; ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 10v12"/><path d="M15 5.88 14 10h5.83a2 2 0 0 1 1.92 2.56l-2.33 8A2 2 0 0 1 17.5 22H7l-3.7-.92A2 2 0 0 1 1.8 19.14v-6.28a2 2 0 0 1 1.5-1.94L7 10l4.7-7.06A2 2 0 0 1 15 5.88Z"/></svg>
                                            <span id="main-likes"><?php echo number_format($likes); ?></span>
                                        </button>
                                        <div class="divider"></div>
                                        <button id="dislike-btn" type="button" aria-label="Dislike video" aria-pressed="<?php echo $userHasDisliked ? 'true' : 'false'; ?>" class="<?php echo $userHasDisliked ? 'active' : ''; ?>"<?php echo $hasDislikesColumn ? '' : ' disabled title="Dislikes are not enabled in this database"'; ?>>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17 14V2"/><path d="M9 18.12 10 14H4.17a2 2 0 0 1-1.92-2.56l2.33-8A2 2 0 0 1 6.5 2H17l3.7.92a2 2 0 0 1 1.5 1.94v6.28a2 2 0 0 1-1.5 1.94L17 14l-4.7 7.06A2 2 0 0 1 9 18.12Z"/></svg>
                                            <span id="main-dislikes"><?php echo number_format($dislikes); ?></span>
                                        </button>
                                    </div>
                                    <button class="btn-pill" type="button" id="share-btn">Share</button>
                                    <button class="btn-pill" type="button" id="speed-btn" title="Playback speed (&lt; and &gt; keys)">Speed 1&times;</button>
                                    <button class="btn-pill" type="button" id="theater-btn" aria-pressed="false" title="Toggle theater mode">Theater mode</button>
                                    <?php if ($is_direct): ?>
                                        <button class="btn-pill" type="button" id="pip-btn" aria-pressed="false" title="Picture-in-picture">Picture-in-picture</button>
                                    <?php endif; ?>
                                    <?php if ($is_direct): ?>
                                        <a class="btn-pill" id="download-btn" href="<?php echo htmlspecialchars($src); ?>" download>Download</a>
                                    <?php else: ?>
                                        <button class="btn-pill" type="button" id="download-btn" disabled title="Downloads are available for uploaded videos">Download</button>
                                    <?php endif; ?>
                                    <a class="btn-pill" href="playlists.php?add=<?php echo rawurlencode((string)$id); ?>">Add to playlist</a>
                                    <?php if (!empty($id)) { $reportContentId = (string)$id; $reportKind = 'video'; if (is_file(__DIR__ . '/report-widget.php')) { include __DIR__ . '/report-widget.php'; } } ?>
                                </div>
                            </div>
                        </div>

                        <div class="description-box">
                            <div class="description-meta"><span id="main-views"><?php echo number_format($views); ?> views</span> &bull; <span id="main-date"><?php echo htmlspecialchars($upload_date); ?></span></div>
                            <?php if (trim((string)$description) !== ''): ?>
                            <div id="main-description"><?php echo htmlspecialchars(trim((string)$description)); ?></div>
                            <?php else: ?>
                            <div id="main-description" hidden></div>
                            <?php endif; ?>
                        </div>

                        <?php echo vs_ads_html(); ?>

                        <?php echo vs_comments_panel_html([
                            'endpoint' => 'watch.php',
                            'video_id' => (string)$id,
                            'comments' => $comments,
                            'viewer' => $viewerName,
                            'is_admin' => vs_comments_is_admin(),
                            'viewer_avatar' => $viewerName !== '' ? avatar_url($viewerName) : '',
                            'list_id' => 'comment-list',
                            'csrf_token' => vs_csrf_token(),
                        ]); ?>
                        <?php echo vs_context_notes_panel_html($contextNotes, (string)$id, 'video', vs_csrf_token(), $viewerName !== '', $contextNotesAvailable); ?>
                    </div>

                    <aside class="recommendations-section">
                        <div class="rec-head">
                            <h3 class="recommendations-title">Related Videos</h3>
                            <label class="autoplay-toggle" title="Play the next video automatically when this one ends">
                                <span>Autoplay</span>
                                <input type="checkbox" id="autoplay-toggle" checked>
                                <span class="autoplay-track" aria-hidden="true"></span>
                            </label>
                        </div>
                        <?php if ($related): ?>
                            <?php foreach ($related as $rec):
                                $rec_src = find_source($rec, __DIR__);
                                $rec_ytid = $rec_src !== '' ? extract_youtube_id($rec_src, true) : null;
                                $rec_thumb = thumb_for($rec, $rec_src, $rec_ytid);
                            ?>
                                <a href="watch.php?id=<?php echo urlencode($rec['id']); ?>" class="video-card compact"
                                   data-vm-id="<?php echo htmlspecialchars((string)$rec['id']); ?>"
                                   data-vm-title="<?php echo htmlspecialchars((string)($rec['title'] ?? 'Untitled')); ?>"
                                   data-vm-channel="<?php echo htmlspecialchars((string)($rec['channel_name'] ?? $rec['username'] ?? '')); ?>"
                                   data-vm-kind="video"
                                   data-vm-url="watch.php?id=<?php echo urlencode($rec['id']); ?>"<?php if ($rec_ytid === null && $rec_src !== '') { ?>
                                   <?php echo vs_download_attr((string)$rec['id'], $conn ?? null); ?><?php } ?>>
                                    <div class="thumbnail">
                                        <img src="<?php echo htmlspecialchars($rec_thumb); ?>" alt="Thumbnail" loading="lazy">
                                    </div>
                                    <div class="video-info">
                                        <h4 class="video-title"><?php echo htmlspecialchars($rec['title'] ?? 'Untitled'); ?></h4>
                                        <p class="channel-name"><?php echo htmlspecialchars($rec['channel_name'] ?? $rec['username'] ?? 'Unknown'); ?></p>
                                        <p class="video-meta"><?php echo number_format((int)($rec['views'] ?? 0)); ?> views &bull; <?php echo time_ago($rec['created_at'] ?? null); ?></p>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p style="color:#aaa; font-size:14px;">No related videos found.</p>
                        <?php endif; ?>
                    </aside>
                </div>
            </main>
        </div>
    </div><!-- /.admin-shell -->

    <div class="toast" id="toast"></div>

    <script src="sidebar.js"></script>
    <script src="video-menu.js?v=4"></script>

    <script src="comments.js?v=1"></script>
    <script src="context-notes.js?v=1"></script>
    <script>
        const VIDEO_ID = <?php echo json_encode((string)$id); ?>;

        function toast(msg) {
            const t = document.getElementById('toast');
            t.textContent = msg;
            t.style.display = 'block';
            setTimeout(() => {
                t.style.display = 'none';
            }, 3000);
        }

        async function postAction(action, extra = {}) {
            try {
                const res = await fetch('watch.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action,
                        video_id: VIDEO_ID,
                        csrf_token: <?php echo json_encode(vs_csrf_token()); ?>,
                        ...extra
                    })
                });
                return await res.json();
            } catch (e) {
                console.error(e);
                return { success: false, error: 'Server error' };
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const youtubePoster = document.getElementById('youtube-click-player');
            const youtubeFrame = document.getElementById('main-iframe');
            const directVideo = document.getElementById('main-video');

            const recordView = async () => {
                try {
                    const response = await fetch('view-api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            video_id: VIDEO_ID,
                            content_type: 'video'
                        })
                    });
                    const result = await response.json();
                    if (result.success) {
                        const label = document.getElementById('main-views');
                        if (label) label.textContent = Number(result.views).toLocaleString() + ' views';
                    }
                } catch (error) {
                    console.error('Could not record view:', error);
                }
            };

            if (directVideo) {
                directVideo.addEventListener('playing', recordView);
            }

            if (youtubeFrame) {
                let lastYoutubeState = -1;
                window.addEventListener('message', (event) => {
                    if (event.source !== youtubeFrame.contentWindow) return;
                    let data = event.data;
                    if (typeof data === 'string') {
                        try { data = JSON.parse(data); } catch (error) { return; }
                    }
                    const state = data && data.event === 'onStateChange'
                        ? data.info
                        : (data && data.event === 'infoDelivery' && data.info ? data.info.playerState : null);
                    if (state === 1 && lastYoutubeState !== 1) recordView();
                    if (typeof state === 'number') lastYoutubeState = state;
                });
            }
            if (youtubePoster && youtubeFrame) {
                const youtubeId = <?php echo json_encode((string)$yt_id); ?>;
                const youtubeStart = <?php echo (int)$startAt; ?>;
                const youtubeError = document.getElementById('youtube-error-player');
                youtubePoster.addEventListener('click', () => {
                    const params = new URLSearchParams({
                        autoplay: '1',
                        rel: '0',
                        playsinline: '1',
                        enablejsapi: '1',
                        origin: window.location.origin
                    });
                    if (youtubeStart > 0) params.set('start', String(youtubeStart));
                    youtubeFrame.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(youtubeId) + '?' + params.toString();
                    youtubePoster.classList.add('is-hidden');

                    window.addEventListener('message', (event) => {
                        if (event.source !== youtubeFrame.contentWindow) return;
                        let data = event.data;
                        if (typeof data === 'string') {
                            try { data = JSON.parse(data); } catch (error) { return; }
                        }
                        const state = data && data.event === 'onStateChange'
                            ? data.info
                            : (data && data.event === 'infoDelivery' && data.info ? data.info.playerState : null);
                        if ([1, 2, 5].includes(state)) {
                            youtubeFrame.dataset.started = '1';
                            if (youtubeError) youtubeError.classList.remove('is-visible');
                        }
                    });
                    window.setTimeout(() => {
                        if (youtubeFrame.dataset.started !== '1' && youtubeError) {
                            youtubeError.classList.add('is-visible');
                        }
                    }, 7000);
                }, { once: true });
            }

            const likeBtn = document.getElementById('like-btn');
            const dislikeBtn = document.getElementById('dislike-btn');
            const dislikesAvailable = !dislikeBtn.disabled;
            const subBtn = document.getElementById('subscribe-btn');
            const subsText = document.getElementById('main-subs');

            function applyVotes(data) {
                const formatCount = (count) => (Number(count) || 0).toLocaleString();
                document.getElementById('main-likes').textContent = formatCount(data.likes);
                document.getElementById('main-dislikes').textContent = formatCount(data.dislikes ?? 0);
                likeBtn.classList.toggle('active', !!data.liked);
                dislikeBtn.classList.toggle('active', !!data.disliked);
                likeBtn.setAttribute('aria-pressed', String(!!data.liked));
                dislikeBtn.setAttribute('aria-pressed', String(!!data.disliked));
            }

            async function updateVote(action) {
                likeBtn.disabled = true;
                dislikeBtn.disabled = true;
                try {
                    const data = await postAction(action);
                    if (!data.success) return toast(data.error || 'Vote failed');
                    applyVotes(data);
                } finally {
                    likeBtn.disabled = false;
                    dislikeBtn.disabled = !dislikesAvailable;
                }
            }
            likeBtn.addEventListener('click', () => updateVote('like'));
            dislikeBtn.addEventListener('click', () => updateVote('dislike'));

            if (subBtn) {
                subBtn.addEventListener('click', async () => {
                    subBtn.disabled = true;
                    const data = await postAction('subscribe');
                    subBtn.disabled = false;
                    if (!data.success) return toast(data.error || 'Subscribe failed');

                    subBtn.textContent = data.subscribed ? 'Subscribed' : 'Subscribe';
                    subBtn.classList.toggle('subscribed', data.subscribed);
                    subsText.textContent = data.subscribers + (data.subscribers == 1 ? ' subscriber' : ' subscribers');
                });
            }
        });
    </script>
    <script>
        /* Autoplay next video + working Share button */
        (function () {
            const KEY = 'vs_autoplay_next';
            const toggle = document.getElementById('autoplay-toggle');
            const first = document.querySelector('.recommendations-section a.video-card');
            const video = document.getElementById('main-video');
            const frame = document.getElementById('main-iframe');
            let on = true;
            try { on = window.localStorage.getItem(KEY) !== '0'; } catch (e) {}
            let timer = null;
            let box = null;

            function cancel() {
                if (timer) { clearInterval(timer); timer = null; }
                if (box) { box.remove(); box = null; }
            }
            function go() {
                const href = first ? first.getAttribute('href') : '';
                cancel();
                if (href) window.location.href = href;
            }
            function playNext() {
                if (!on || !first || timer) return;
                let left = 5;
                box = document.createElement('div');
                box.className = 'upnext';
                const label = document.createElement('b');
                const title = document.createElement('span');
                title.textContent = first.getAttribute('data-vm-title') || 'The next video';
                const row = document.createElement('div');
                const now = document.createElement('button');
                now.type = 'button';
                now.className = 'primary';
                now.textContent = 'Play now';
                now.addEventListener('click', go);
                const stop = document.createElement('button');
                stop.type = 'button';
                stop.textContent = 'Cancel';
                stop.addEventListener('click', cancel);
                row.appendChild(now);
                row.appendChild(stop);
                box.appendChild(label);
                box.appendChild(title);
                box.appendChild(row);
                document.body.appendChild(box);
                const paint = () => { label.textContent = 'Up next in ' + left + 's'; };
                paint();
                timer = setInterval(() => { left -= 1; if (left <= 0) { go(); } else { paint(); } }, 1000);
            }

            if (toggle) {
                toggle.checked = on;
                toggle.addEventListener('change', () => {
                    on = toggle.checked;
                    try { window.localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
                    if (!on) cancel();
                });
            }

            if (video) {
                video.addEventListener('ended', playNext);
                video.addEventListener('play', cancel);
            }
            if (frame) {
                const listen = () => {
                    try { frame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), '*'); } catch (e) {}
                };
                frame.addEventListener('load', () => { listen(); setTimeout(listen, 800); setTimeout(listen, 2500); });
                window.addEventListener('message', (e) => {
                    if (e.source !== frame.contentWindow) return;
                    let d = e.data;
                    if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
                    if (!d) return;
                    let state = null;
                    if (d.event === 'onStateChange') state = d.info;
                    else if (d.event === 'infoDelivery' && d.info && typeof d.info.playerState === 'number') state = d.info.playerState;
                    if (state === 0) playNext();
                    else if (state === 1) cancel();
                });
            }

        })();
    </script>
    <script>
        /* Playback speed, keyboard shortcuts and Share menu */
        (function () {
            const video = document.getElementById('main-video');
            const frame = document.getElementById('main-iframe');
            const wrap = document.querySelector('.video-wrapper');
            const speedBtn = document.getElementById('speed-btn');
            const shareBtn = document.getElementById('share-btn');
            const theaterBtn = document.getElementById('theater-btn');
            const pipBtn = document.getElementById('pip-btn');
            const watchContainer = document.querySelector('.watch-container');
            const miniPlayerReturn = document.getElementById('mini-player-return');
            const miniPlayerClose = document.getElementById('mini-player-close');
            const VIDEO_ID = <?php echo json_encode((string)$id); ?>;
            const START = <?php echo (int)$startAt; ?>;
            const PROGRESS_CSRF = <?php echo json_encode(vs_csrf_token()); ?>;
            const CAN_SAVE_PROGRESS = <?php echo !empty($_SESSION['username']) ? 'true' : 'false'; ?>;
            const RATES = [0.25, 0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];
            const THEATER_KEY = 'vs_watch_theater_mode';
            const store = {
                get: (k) => { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
                set: (k, v) => { try { window.localStorage.setItem(k, v); } catch (e) {} }
            };
            let rate = parseFloat(store.get('vs_speed')) || 1;
            if (RATES.indexOf(rate) === -1) rate = 1;
            const yt = { time: 0, duration: 0, state: -1, muted: false, volume: 100 };

            function say(msg) { if (typeof toast === 'function') toast(msg); }
            function setTheaterMode(enabled, announce) {
                if (!theaterBtn || !watchContainer) return;
                watchContainer.classList.toggle('theater-mode', enabled);
                theaterBtn.setAttribute('aria-pressed', String(enabled));
                theaterBtn.textContent = enabled ? 'Exit theater' : 'Theater mode';
                theaterBtn.title = enabled ? 'Exit theater mode' : 'Toggle theater mode';
                store.set(THEATER_KEY, enabled ? '1' : '0');
                if (announce) say(enabled ? 'Theater mode on' : 'Theater mode off');
            }
            function fmt(sec) {
                sec = Math.max(0, Math.floor(sec));
                const h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
                return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(s).padStart(2, '0');
            }
            function rateLabel(r) { return r + '\u00d7'; }

            /* ---- talking to the player ---- */
            function ytCmd(func, args) {
                if (!frame) return;
                try { frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: args || [] }), '*'); } catch (e) {}
            }
            function getTime() { return video ? (video.currentTime || 0) : yt.time; }
            function getDuration() { return video ? (video.duration || 0) : yt.duration; }
            function togglePlay() {
                if (video) { if (video.paused) { const p = video.play(); if (p && p.catch) p.catch(function () {}); } else { video.pause(); } }
                else if (frame) { ytCmd(yt.state === 1 ? 'pauseVideo' : 'playVideo'); }
            }
            function seekTo(sec) {
                sec = Math.max(0, sec);
                if (video) { const d = video.duration; video.currentTime = isFinite(d) ? Math.min(sec, d) : sec; }
                else if (frame) { yt.time = sec; ytCmd('seekTo', [sec, true]); }
            }
            function seekBy(delta) { seekTo(getTime() + delta); }
            function toggleMute() {
                if (video) { video.muted = !video.muted; say(video.muted ? 'Muted' : 'Sound on'); }
                else if (frame) { yt.muted = !yt.muted; ytCmd(yt.muted ? 'mute' : 'unMute'); say(yt.muted ? 'Muted' : 'Sound on'); }
            }
            function changeVolume(delta) {
                if (video) {
                    video.muted = false;
                    video.volume = Math.max(0, Math.min(1, video.volume + delta / 100));
                    say('Volume ' + Math.round(video.volume * 100) + '%');
                } else if (frame) {
                    yt.volume = Math.max(0, Math.min(100, yt.volume + delta));
                    ytCmd('setVolume', [yt.volume]);
                    if (yt.volume > 0) { yt.muted = false; ytCmd('unMute'); }
                    say('Volume ' + yt.volume + '%');
                }
            }
            function toggleFullscreen() {
                const el = wrap || video || frame;
                if (!el) return;
                if (document.fullscreenElement) { document.exitFullscreen(); }
                else if (el.requestFullscreen) { el.requestFullscreen(); }
            }
            function togglePictureInPicture() {
                if (!video || !('pictureInPictureEnabled' in document)) return;
                if (document.pictureInPictureElement) {
                    document.exitPictureInPicture().catch(function () {});
                } else if (video.requestPictureInPicture) {
                    video.requestPictureInPicture().catch(function () { say('Picture-in-picture is not available'); });
                }
            }
            function setRate(r, announce) {
                rate = r;
                store.set('vs_speed', String(r));
                if (video) video.playbackRate = r; else if (frame) ytCmd('setPlaybackRate', [r]);
                if (speedBtn) speedBtn.innerHTML = 'Speed ' + rateLabel(r).replace('\u00d7', '&times;');
                if (announce) say('Speed ' + rateLabel(r));
            }
            function stepRate(dir) {
                const i = RATES.indexOf(rate);
                const n = Math.max(0, Math.min(RATES.length - 1, (i === -1 ? RATES.indexOf(1) : i) + dir));
                setRate(RATES[n], true);
            }

            if (video) {
                const ready = () => {
                    video.playbackRate = rate;
                    if (START > 0 && isFinite(video.duration) && START < video.duration) video.currentTime = START;
                };
                if (video.readyState >= 1) ready(); else video.addEventListener('loadedmetadata', ready, { once: true });
                video.addEventListener('play', () => { video.playbackRate = rate; });
            }
            if (frame) {
                window.addEventListener('message', (e) => {
                    if (e.source !== frame.contentWindow) return;
                    let d = e.data;
                    if (typeof d === 'string') { try { d = JSON.parse(d); } catch (x) { return; } }
                    if (!d) return;
                    if (d.event === 'onStateChange') {
                        yt.state = d.info;
                        if (d.info === 1 && rate !== 1) ytCmd('setPlaybackRate', [rate]);
                        return;
                    }
                    const i = d.info;
                    if (!i || typeof i !== 'object') return;
                    if (typeof i.currentTime === 'number') yt.time = i.currentTime;
                    if (typeof i.duration === 'number') yt.duration = i.duration;
                    if (typeof i.muted === 'boolean') yt.muted = i.muted;
                    if (typeof i.volume === 'number') yt.volume = i.volume;
                    if (typeof i.playerState === 'number') {
                        const was = yt.state;
                        yt.state = i.playerState;
                        if (i.playerState === 1 && was !== 1 && rate !== 1) ytCmd('setPlaybackRate', [rate]);
                    }
                });
            }
            if (CAN_SAVE_PROGRESS && (video || frame)) {
                function saveProgress(complete) {
                    const duration = Math.floor(getDuration());
                    const position = complete ? duration : Math.floor(getTime());
                    if (duration <= 0 || duration > 604800 || position <= 0) return;
                    const payload = new URLSearchParams({
                        action: 'progress',
                        video_id: VIDEO_ID,
                        position: String(position),
                        duration: String(duration),
                        csrf_token: PROGRESS_CSRF
                    });
                    if (document.visibilityState === 'hidden' && navigator.sendBeacon) {
                        navigator.sendBeacon('watch.php', new Blob([payload.toString()], {
                            type: 'application/x-www-form-urlencoded'
                        }));
                        return;
                    }
                    fetch('watch.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: payload,
                        keepalive: true
                    }).catch(function (error) { console.error('Playback progress save failed:', error); });
                }

                const progressTimer = window.setInterval(() => saveProgress(false), 15000);
                if (video) video.addEventListener('ended', () => saveProgress(true));
                if (frame) {
                    window.addEventListener('message', (e) => {
                        if (e.source !== frame.contentWindow) return;
                        let data = e.data;
                        if (typeof data === 'string') {
                            try { data = JSON.parse(data); } catch (error) { return; }
                        }
                        const state = data && data.event === 'onStateChange'
                            ? data.info
                            : (data && data.event === 'infoDelivery' && data.info ? data.info.playerState : null);
                        if (state === 0) {
                            saveProgress(true);
                        }
                    });
                }
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'hidden') saveProgress(false);
                });
                window.addEventListener('pagehide', () => {
                    window.clearInterval(progressTimer);
                    saveProgress(false);
                });
            }
            if (!video && !frame && speedBtn) speedBtn.hidden = true;
            if (speedBtn) setRate(rate, false);

            /* ---- small pop-up menus ---- */
            let pop = null;
            function closePop() { if (pop) { pop.remove(); pop = null; } }
            function openPop(anchor, fill) {
                if (pop && pop._anchor === anchor) { closePop(); return; }
                closePop();
                pop = document.createElement('div');
                pop.className = 'vs-pop';
                pop._anchor = anchor;
                fill(pop);
                document.body.appendChild(pop);
                const r = anchor.getBoundingClientRect();
                const w = pop.offsetWidth, h = pop.offsetHeight;
                const left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
                let top = r.bottom + 6;
                if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 6);
                pop.style.left = left + 'px';
                pop.style.top = top + 'px';
            }
            document.addEventListener('click', (e) => {
                if (pop && !pop.contains(e.target) && !(pop._anchor && pop._anchor.contains(e.target))) closePop();
            });
            window.addEventListener('scroll', () => closePop(), { passive: true });
            function popTitle(parent, text) {
                const t = document.createElement('div');
                t.className = 'vs-pop-title';
                t.textContent = text;
                parent.appendChild(t);
            }

            if (speedBtn) {
                speedBtn.addEventListener('click', () => {
                    openPop(speedBtn, (p) => {
                        popTitle(p, 'Playback speed');
                        RATES.forEach((r) => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.textContent = r === 1 ? 'Normal' : rateLabel(r);
                            if (r === rate) b.className = 'on';
                            b.addEventListener('click', () => { setRate(r, false); closePop(); });
                            p.appendChild(b);
                        });
                    });
                });
            }

            if (shareBtn) {
                shareBtn.addEventListener('click', () => {
                    openPop(shareBtn, (p) => {
                        const cur = Math.floor(getTime());
                        const base = new URL('watch.php?id=' + encodeURIComponent(VIDEO_ID), window.location.href).href;
                        const check = document.createElement('input');
                        check.type = 'checkbox';
                        check.disabled = cur < 3;
                        const urlNow = () => (check.checked && cur >= 3) ? base + '&t=' + cur : base;

                        popTitle(p, 'Share');
                        const copy = document.createElement('button');
                        copy.type = 'button';
                        copy.textContent = 'Copy link';
                        copy.addEventListener('click', async () => {
                            try { await navigator.clipboard.writeText(urlNow()); say('Link copied'); }
                            catch (e) { window.prompt('Copy this link', urlNow()); }
                            closePop();
                        });
                        p.appendChild(copy);

                        const wa = document.createElement('button');
                        wa.type = 'button';
                        wa.textContent = 'WhatsApp';
                        wa.addEventListener('click', () => {
                            window.open('https://wa.me/?text=' + encodeURIComponent(document.title + ' ' + urlNow()), '_blank', 'noopener');
                            closePop();
                        });
                        p.appendChild(wa);

                        if (navigator.share) {
                            const more = document.createElement('button');
                            more.type = 'button';
                            more.textContent = 'More apps\u2026';
                            more.addEventListener('click', async () => {
                                try { await navigator.share({ title: document.title, url: urlNow() }); } catch (e) {}
                                closePop();
                            });
                            p.appendChild(more);
                        }

                        const lab = document.createElement('label');
                        lab.appendChild(check);
                        lab.appendChild(document.createTextNode('Start at ' + fmt(cur)));
                        p.appendChild(lab);
                    });
                });
            }

            if (theaterBtn && watchContainer) {
                setTheaterMode(store.get(THEATER_KEY) === '1', false);
                theaterBtn.addEventListener('click', () => {
                    setTheaterMode(!watchContainer.classList.contains('theater-mode'), false);
                });
            }

            if (pipBtn && video) {
                if (!document.pictureInPictureEnabled || !video.requestPictureInPicture) {
                    pipBtn.hidden = true;
                } else {
                    pipBtn.addEventListener('click', togglePictureInPicture);
                    video.addEventListener('enterpictureinpicture', () => {
                        pipBtn.setAttribute('aria-pressed', 'true');
                        pipBtn.textContent = 'Exit picture-in-picture';
                    });
                    video.addEventListener('leavepictureinpicture', () => {
                        pipBtn.setAttribute('aria-pressed', 'false');
                        pipBtn.textContent = 'Picture-in-picture';
                    });
                }
            }

            if (wrap && (video || frame) && 'IntersectionObserver' in window) {
                const placeholder = document.createElement('div');
                placeholder.className = 'mini-player-placeholder';
                placeholder.setAttribute('aria-hidden', 'true');
                wrap.parentNode.insertBefore(placeholder, wrap);

                let dismissed = false;
                let miniPlayerActive = false;
                const setMiniPlayer = (enabled) => {
                    if (miniPlayerActive === enabled) return;
                    miniPlayerActive = enabled;
                    placeholder.classList.toggle('is-active', enabled);
                    wrap.classList.toggle('mini-player-active', enabled);
                };
                const observer = new IntersectionObserver(([entry]) => {
                    if (dismissed) return;
                    const rect = entry.boundingClientRect;
                    setMiniPlayer(!entry.isIntersecting && rect.bottom < 0 && rect.top < 0);
                }, { threshold: 0, rootMargin: '0px 0px 0px 0px' });
                observer.observe(wrap);

                if (miniPlayerReturn) {
                    miniPlayerReturn.addEventListener('click', () => {
                        placeholder.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    });
                }
                if (miniPlayerClose) {
                    miniPlayerClose.addEventListener('click', () => {
                        dismissed = true;
                        setMiniPlayer(false);
                        observer.disconnect();
                        placeholder.remove();
                    });
                }
            }

            /* ---- keyboard shortcuts ---- */
            let help = null;
            function closeHelp() { if (help) { help.remove(); help = null; } }
            function toggleHelp() {
                if (help) { closeHelp(); return; }
                const rows = [
                    ['Space or K', 'Play / pause'], ['J', 'Back 10 seconds'], ['L', 'Forward 10 seconds'],
                    ['\u2190 / \u2192', 'Back / forward 5 seconds'], ['\u2191 / \u2193', 'Volume up / down'], ['M', 'Mute'],
                    ['F', 'Fullscreen'], ['P', 'Picture-in-picture'], ['T', 'Theater mode'], ['< / >', 'Slower / faster'], ['0 to 9', 'Jump to 0% to 90%'], ['?', 'Show this help']
                ];
                help = document.createElement('div');
                help.className = 'vs-help';
                const box = document.createElement('div');
                box.className = 'vs-help-box';
                const h = document.createElement('h3');
                h.textContent = 'Keyboard shortcuts';
                box.appendChild(h);
                rows.forEach((r) => {
                    const row = document.createElement('div');
                    row.className = 'vs-help-row';
                    const a = document.createElement('kbd');
                    a.textContent = r[0];
                    const b = document.createElement('span');
                    b.textContent = r[1];
                    row.appendChild(b);
                    row.appendChild(a);
                    box.appendChild(row);
                });
                help.appendChild(box);
                help.addEventListener('click', closeHelp);
                document.body.appendChild(help);
            }

            document.addEventListener('keydown', (e) => {
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                const t = e.target;
                const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
                if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) return;
                if (e.key === 'Escape') { closePop(); closeHelp(); return; }
                if (!video && !frame) return;
                if ((tag === 'button' || tag === 'a' || tag === 'summary') && (e.key === ' ' || e.key === 'Enter')) return;
                const k = e.key;
                let handled = true;
                if (k === ' ' || k === 'k' || k === 'K') togglePlay();
                else if (k === 'j' || k === 'J') seekBy(-10);
                else if (k === 'l' || k === 'L') seekBy(10);
                else if (k === 'ArrowLeft') seekBy(-5);
                else if (k === 'ArrowRight') seekBy(5);
                else if (k === 'ArrowUp') changeVolume(10);
                else if (k === 'ArrowDown') changeVolume(-10);
                else if (k === 'm' || k === 'M') toggleMute();
                else if (k === 'f' || k === 'F') toggleFullscreen();
                else if (k === 'p' || k === 'P') togglePictureInPicture();
                else if (k === 't' || k === 'T') {
                    setTheaterMode(!watchContainer.classList.contains('theater-mode'), true);
                }
                else if (k === '>') stepRate(1);
                else if (k === '<') stepRate(-1);
                else if (k.length === 1 && k >= '0' && k <= '9' && !e.shiftKey) { const d = getDuration(); if (d) seekTo(d * Number(k) / 10); else handled = false; }
                else if (k === '?') toggleHelp();
                else handled = false;
                if (handled) e.preventDefault();
            });
        })();
    </script>
</body>
</html>
<?php if (isset($conn)) {  } ?>