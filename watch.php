<?php
session_start();
require_once __DIR__ . '/avatar.php';   
require_once __DIR__ . '/icons.php';    
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/sidebar-navigation.php';
require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/history-store.php';
require_once __DIR__ . '/comment-store.php';
require_once __DIR__ . '/helpers.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
vs_maintenance_gate();
include "connection.php";

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
        $priority = ['video_url','video_path','video_file','video','url','file_path','filepath','file','filename','source','src','link','media_url','youtube_url','youtube_id','yt_id','video_link','embed_url','embed'];
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

        $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?");
        $stmt->bind_param("s", $vid);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$v) json_out(['success' => false, 'error' => 'Video not found']);

        foreach (['liked_videos', 'disliked_videos', 'subscribed_channels'] as $k) {
            if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) $_SESSION[$k] = [];
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

            $mineActive = in_array($vid, $_SESSION[$mine], true);
            $otherActive = in_array($vid, $_SESSION[$other], true);
            if ($mineActive) {
                $sql = "UPDATE videos SET $myCol = GREATEST(COALESCE($myCol,0) - 1, 0) WHERE id = ?";
            } elseif ($otherActive) {
                $sql = "UPDATE videos SET $myCol = COALESCE($myCol,0) + 1, $otherCol = GREATEST(COALESCE($otherCol,0) - 1, 0) WHERE id = ?";
            } else {
                $sql = "UPDATE videos SET $myCol = COALESCE($myCol,0) + 1 WHERE id = ?";
            }

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

            $_SESSION[$mine] = $mineActive
                ? array_values(array_diff($_SESSION[$mine], [$vid]))
                : array_merge($_SESSION[$mine], [$vid]);
            if (!$mineActive && $otherActive) {
                $_SESSION[$other] = array_values(array_diff($_SESSION[$other], [$vid]));
            }

            // Tell the uploader someone liked their video (only when a like was added).
            if ($isLike && !$mineActive && !empty($_SESSION['username'])) {
                vs_notify_video_event($conn, $v, 'like', (string)$_SESSION['username']);
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
    $where = $hasTypeColumn ? "WHERE COALESCE(type,'video') <> 'short'" : "";
    $result = $conn->query("SELECT * FROM videos $where ORDER BY id DESC LIMIT 1");
    $video = $result ? $result->fetch_assoc() : null;
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

// Count a view every time the video is opened (same as shorts.php).
// Raise this number (seconds) if you want repeat opens by the same person to count less often.
$WATCH_VIEW_COOLDOWN_SECONDS = 0;
if (!isset($_SESSION['view_times']) || !is_array($_SESSION['view_times'])) {
    $_SESSION['view_times'] = [];
}
if ($id !== 0 && $id !== '0' && $id !== '') {
    $nowTs = time();
    $lastViewTs = isset($_SESSION['view_times'][$id]) ? (int)$_SESSION['view_times'][$id] : 0;
    if ($lastViewTs === 0 || ($nowTs - $lastViewTs) >= $WATCH_VIEW_COOLDOWN_SECONDS) {
        $upd = $conn->prepare("UPDATE videos SET views = COALESCE(views,0) + 1 WHERE id = ?");
        if ($upd) {
            $upd->bind_param("s", $id);
            if ($upd->execute()) {
                $_SESSION['view_times'][$id] = $nowTs;
                $video['views'] = (int)($video['views'] ?? 0) + 1;
            }
            $upd->close();
        }
    }
}

$title = $video['title'] ?? 'Video not found';
$views = (int)($video['views'] ?? 0);
$channel = trim((string)($video['channel_name'] ?? ''));
if ($channel === '') $channel = trim((string)($video['username'] ?? 'Unknown channel'));
$subs = (int)($video['subscribers'] ?? 0);
$upload_date = $video['upload_date'] ?? 'Recently';
$description = $video['description'] ?? '';
$likes = (int)($video['likes'] ?? 0);
$dislikes = (int)($video['dislikes'] ?? 0);
if (!$hasDislikesColumn) {
    $dislikes = 0;
}

$src = find_source($video, __DIR__);
$yt_id = $src !== '' ? extract_youtube_id($src, true) : null;
$is_direct = $src !== '' && !$yt_id;

$channelOwner = trim((string)($video['username'] ?? ''));
if ($channelOwner === '') $channelOwner = $channel;
$avatar_url = avatar_for_record($channelOwner, $video);
if ($avatar_url === '' && $channelOwner !== $channel) $avatar_url = avatar_url($channel);
$user_avatar = avatar_or_cartoon($_SESSION['username'] ?? '');
$channelInitial = mb_strtoupper(mb_substr(trim((string)$channel) !== '' ? trim((string)$channel) : 'V', 0, 1));

foreach (['liked_videos', 'disliked_videos', 'subscribed_channels'] as $k) {
    if (!isset($_SESSION[$k]) || !is_array($_SESSION[$k])) $_SESSION[$k] = [];
}
try {
    vs_subscription_session_sync($conn);
} catch (Throwable $subscriptionError) {
    error_log('Could not synchronize VibeStream subscriptions: ' . $subscriptionError->getMessage());
}
if (!$hasDislikesColumn) {
    $_SESSION['disliked_videos'] = [];
}
$userHasLiked = in_array($id, $_SESSION['liked_videos'], true);
$userHasDisliked = in_array($id, $_SESSION['disliked_videos'], true);
$channelKey = vs_subscription_key($channel);
$userHasSubscribed = in_array($channelKey, $_SESSION['subscribed_channels'], true);

$viewerName = trim((string)($_SESSION['username'] ?? ''));
try {
    $comments = vs_comments_fetch($conn, (string)$id, $viewerName);
} catch (Throwable $commentsError) {
    error_log('Comments failed: ' . $commentsError->getMessage());
    $comments = [];
}
$commentCount = count($comments);

$related = [];
$relSql = $hasTypeColumn
    ? "SELECT * FROM videos WHERE id <> ? AND COALESCE(type,'video') <> 'short' ORDER BY RAND() LIMIT 10"
    : "SELECT * FROM videos WHERE id <> ? ORDER BY RAND() LIMIT 10";
if ($stmt = $conn->prepare($relSql)) {
    $stmt->bind_param("s", $id);
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
    <link rel="stylesheet" href="style.css?v=5">

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
            background-color: var(--surface-2); border-radius: var(--radius-md); padding: 12px; margin-top: 12px;
            font-size: 14px; line-height: 1.5; white-space: pre-line;
        }
        .description-meta { font-weight: 600; margin-bottom: 8px; }

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
        .recommendations-title { font-size: 16px; margin: 0 0 16px 0; }
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
    <link rel="stylesheet" href="theme.css?v=3">
    <link rel="stylesheet" href="navbar-fix.css?v=3">
    <link rel="stylesheet" href="comments.css?v=1">
</head>
<body>
    <?php render_navbar('watch'); ?>

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
                                    src="https://www.youtube.com/embed/<?php echo htmlspecialchars($yt_id); ?>?autoplay=1&amp;rel=0"
                                    title="Video player"
                                    frameborder="0"
                                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allowfullscreen></iframe>
                            <?php elseif ($is_direct): ?>
                                <video id="main-video" src="<?php echo htmlspecialchars($src); ?>" controls autoplay playsinline>
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
                                        <h3 id="main-channel"><?php echo htmlspecialchars($channel); ?></h3>
                                        <p id="main-subs"><?php echo $subs; ?> subscriber<?php echo $subs == 1 ? '' : 's'; ?></p>
                                    </div>
                                    <button id="subscribe-btn" type="button" class="subscribe-btn<?php echo $userHasSubscribed ? ' subscribed' : ''; ?>">
                                        <?php echo $userHasSubscribed ? 'Subscribed' : 'Subscribe'; ?>
                                    </button>
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
                                    <button class="btn-pill" type="button">Share</button>
                                    <button class="btn-pill" type="button">Download</button>
                                    <button class="btn-pill" type="button">Save</button>
                                    <?php if (!empty($id)) { $reportContentId = (string)$id; $reportKind = 'video'; if (is_file(__DIR__ . '/report-widget.php')) { include __DIR__ . '/report-widget.php'; } } ?>
                                </div>
                            </div>
                        </div>

                        <div class="description-box">
                            <div class="description-meta">
                                <span id="main-views"><?php echo number_format($views); ?> views</span> &bull;
                                <span id="main-date"><?php echo htmlspecialchars($upload_date); ?></span>
                            </div>
                            <div id="main-description"><?php echo htmlspecialchars($description); ?></div>
                        </div>

                        <?php echo vs_comments_panel_html([
                            'endpoint' => 'watch.php',
                            'video_id' => (string)$id,
                            'comments' => $comments,
                            'viewer' => $viewerName,
                            'is_admin' => vs_comments_is_admin(),
                            'viewer_avatar' => $viewerName !== '' ? avatar_url($viewerName) : '',
                            'list_id' => 'comment-list',
                        ]); ?>
                    </div>

                    <aside class="recommendations-section">
                        <h3 class="recommendations-title">Related Videos</h3>
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
                                   data-vm-download="<?php echo htmlspecialchars((string)$rec_src); ?>"<?php } ?>>
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
    <script src="video-menu.js?v=3"></script>

    <script src="comments.js?v=1"></script>
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
                    body: new URLSearchParams({ action, video_id: VIDEO_ID, ...extra })
                });
                return await res.json();
            } catch (e) {
                console.error(e);
                return { success: false, error: 'Server error' };
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
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

            subBtn.addEventListener('click', async () => {
                subBtn.disabled = true;
                const data = await postAction('subscribe');
                subBtn.disabled = false;
                if (!data.success) return toast(data.error || 'Subscribe failed');

                subBtn.textContent = data.subscribed ? 'Subscribed' : 'Subscribe';
                subBtn.classList.toggle('subscribed', data.subscribed);
                subsText.textContent = data.subscribers + (data.subscribers == 1 ? ' subscriber' : ' subscribers');
            });
        });
    </script>
</body>
</html>
<?php if (isset($conn)) {  } ?>