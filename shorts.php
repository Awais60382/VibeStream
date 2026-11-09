<?php
session_start();
if (is_file(__DIR__ . '/premium-perks.php')) {
    require_once __DIR__ . '/premium-perks.php';
}
include "connection.php";
require_once __DIR__ . '/dashboard-events.php';
require_once __DIR__ . '/avatar.php';   
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/icons.php';    
require_once __DIR__ . '/sidebar-navigation.php';
require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/history-store.php';
require_once __DIR__ . '/liked-store.php';
require_once __DIR__ . '/comment-store.php';
require_once __DIR__ . '/context-notes-store.php';
require_once __DIR__ . '/vip-badge.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
vs_maintenance_gate();

if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli('localhost', 'root', '', 'vibestream');
        if ($conn->connect_errno) {
            die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
        }
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

if (!function_exists('extract_youtube_id')) {
    function extract_youtube_id($raw, $allowBare = true) {
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
        $priority = ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed', 'id'];
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

if (!function_exists('short_num')) {
    function short_num($n) {
        $n = (int)$n;
        if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
        if ($n >= 1000) return round($n / 1000, 1) . 'k';
        return (string)$n;
    }
}

if (!function_exists('render_navbar')) {
    function render_navbar($active = 'shorts') {
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
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    try {
        $vid = trim((string)($_POST['video_id'] ?? ''));
        $action = $_POST['action'] ?? '';

        if ($vid === '') {
            echo json_encode(['success' => false, 'error' => 'Missing video ID.']);
            exit;
        }

        $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ?");
        $stmt->bind_param("s", $vid);
        $stmt->execute();
        $v = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$v) {
            echo json_encode(['success' => false, 'error' => 'Invalid video ID. Video not found.']);
            exit;
        }

        if (!isset($_SESSION['liked_videos']) || !is_array($_SESSION['liked_videos'])) $_SESSION['liked_videos'] = [];
        if (!isset($_SESSION['subscribed_channels']) || !is_array($_SESSION['subscribed_channels'])) $_SESSION['subscribed_channels'] = [];
        if ($action === 'subscribe') {
            vs_subscription_session_sync($conn);
        }

        if ($action === 'like') {
            vs_likes_session_sync($conn);
            $liked = vs_likes_has($conn, $vid);
            $likedBefore = $_SESSION['liked_videos'];
            try {
                $conn->begin_transaction();
                $upd = $conn->prepare($liked
                    ? "UPDATE videos SET likes = GREATEST(COALESCE(likes,0) - 1, 0) WHERE id = ?"
                    : "UPDATE videos SET likes = COALESCE(likes,0) + 1 WHERE id = ?");
                if (!$upd) {
                    throw new RuntimeException('Could not save your like. Please try again.');
                }
                $upd->bind_param("s", $vid);
                if (!$upd->execute() || $upd->affected_rows !== 1) {
                    $upd->close();
                    throw new RuntimeException('Could not save your like. Please try again.');
                }
                $upd->close();
                vs_likes_set($conn, $vid, !$liked);
                $conn->commit();
            } catch (Throwable $likeError) {
                $conn->rollback();
                $_SESSION['liked_videos'] = $likedBefore;
                throw $likeError;
            }

            if (!$liked && !empty($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? '')) {
                if (function_exists('vs_notify_video_event')) {
                    $actor = (string)($_SESSION['username'] ?? $_SESSION['AdminSession']);
                    vs_notify_video_event($conn, $v, 'like', $actor);
                }
            }

            $stmt = $conn->prepare("SELECT COALESCE(likes,0) AS n FROM videos WHERE id = ?");
            if (!$stmt) {
                throw new RuntimeException('Your like was saved, but its count could not be read.');
            }
            $stmt->bind_param("s", $vid);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Your like was saved, but its count could not be read.');
            }
            $likes = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $likes]);
            exit;
        }

        if ($action === 'subscribe') {
            $channelName = trim((string)($v['channel_name'] ?? ''));
            $channelUsername = trim((string)($v['username'] ?? ''));
            if ($channelName === '') $channelName = $channelUsername;
            $result = vs_subscription_change($conn, $channelName, $channelUsername, $vid);
            echo json_encode(['success' => true] + $result);
            exit;
        }

        if ($action === 'comment') {
            $text = trim((string)($_POST['comment_text'] ?? ''));
            if ($text === '') {
                echo json_encode(['success' => false, 'error' => 'Comment is empty']);
                exit;
            }
            $text = mb_substr($text, 0, 500);
            $user = $_SESSION['username'] ?? 'Guest';

            $ins = $conn->prepare("INSERT INTO comments (video_id, username, comment_text) VALUES (?, ?, ?)");
            if (!$ins) {
                echo json_encode(['success' => false, 'error' => 'Comments table missing or invalid']);
                exit;
            }
            $ins->bind_param("sss", $vid, $user, $text);
            $ins->execute();
            $newCommentId = (int)$conn->insert_id;
            $ins->close();
            vs_notify_video_event($conn, $v, 'comment', (string)$user, $text);
            vs_notify_comment_reply($conn, $v, (string)$user, $text, $newCommentId);

            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM comments WHERE video_id = ?");
            $stmt->bind_param("s", $vid);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            echo json_encode([
                'success' => true,
                'username' => $user,
                'comment_text' => $text,
                'comment_count' => $count,
                'id' => $newCommentId,
                'html' => vs_comment_new_item_html($newCommentId, (string)$user, $text),
                'avatar' => avatar_url($user),
            ]);
            exit;
        }

        $commentResult = vs_comments_handle_action($conn, (string)$action, (string)$vid);
        if ($commentResult !== null) {
            echo json_encode($commentResult);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
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

$id = trim($_GET['id'] ?? '');
$video = null;

if ($id !== '') {
    $stmt = $conn->prepare("SELECT * FROM videos WHERE id = ? AND type = 'short'");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $video = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$video) {
    // No (valid) short in the address: show a different random short every time
    // the Shorts button is pressed, then move to that short's own address so a
    // refresh or a shared link keeps pointing at it.
    $video = vs_random_pick_video($conn, 'random_seen_shorts', "type = 'short'");
    if ($video && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        header('Cache-Control: no-store');
        header('Location: shorts.php?id=' . urlencode((string)$video['id']));
        exit;
    }
}

$comments = [];
$prev = $next = null;
$nextId = '';
$nextSrc = '';
$nextYt = null;
$src = '';
$yt = null;
$title = 'No shorts yet';
$channel = $siteName;
$likes = 0;
$subs = 0;
$views = 0;
$description = 'Upload a short to get started.';
$avatarUrl = '';
$userLiked = false;
$userSubbed = false;
$isOwnChannel = false;
$noVideo = !$video;

if ($video) {
    $id = $video['id'];

    // Save to the signed-in user's watch history (History page)
    if (!empty($_SESSION['username'])) {
        try {
            vs_history_record($conn, (string)$id);
        } catch (Throwable $historyError) {
            error_log('Watch history failed: ' . $historyError->getMessage());
        }
    }

    // Next = a random short this visitor has not seen yet in the current round
    // (the Next button opens shorts.php, which picks it). Previous = the short
    // they were watching before this one.
    $seenKey = 'random_seen_shorts';
    if (!isset($_SESSION[$seenKey]) || !is_array($_SESSION[$seenKey])) {
        $_SESSION[$seenKey] = [];
    }
    if (!in_array((string)$id, array_map('strval', $_SESSION[$seenKey]), true)) {
        $_SESSION[$seenKey][] = (string)$id;
        $_SESSION[$seenKey] = array_slice($_SESSION[$seenKey], -500);
    }
    $trail = (isset($_SESSION['shorts_trail']) && is_array($_SESSION['shorts_trail'])) ? array_values($_SESSION['shorts_trail']) : [];
    $trailCount = count($trail);
    if ($trailCount > 0 && (string)$trail[$trailCount - 1] === (string)$id) {
        // the page was reloaded: nothing to change
    } elseif ($trailCount > 1 && (string)$trail[$trailCount - 2] === (string)$id) {
        array_pop($trail); // the visitor pressed Previous
    } else {
        $trail[] = (string)$id;
    }
    $trail = array_slice($trail, -100);
    $_SESSION['shorts_trail'] = $trail;
    $prev = count($trail) > 1 ? ['id' => $trail[count($trail) - 2]] : null;
    $next = ['id' => $id];

    // Choose the NEXT short now (and remember it), so the page can warm it up and the Next button opens it directly.
    $nextShort = null;
    $queued = (isset($_SESSION['shorts_next_for']) && is_array($_SESSION['shorts_next_for']) && (string)($_SESSION['shorts_next_for']['for'] ?? '') === (string)$id)
        ? (string)($_SESSION['shorts_next_for']['next'] ?? '') : '';
    if ($queued !== '') {
        $qs = $conn->prepare("SELECT * FROM videos WHERE id = ? AND type = 'short'");
        if ($qs) {
            $qs->bind_param('s', $queued);
            $qs->execute();
            $nextShort = $qs->get_result()->fetch_assoc() ?: null;
            $qs->close();
        }
    }
    if (!$nextShort) {
        $nextShort = vs_random_pick_video($conn, 'random_seen_shorts', "type = 'short'");
        if ($nextShort) {
            $_SESSION['shorts_next_for'] = ['for' => (string)$id, 'next' => (string)$nextShort['id']];
        }
    }
    $nextId = $nextShort ? (string)$nextShort['id'] : '';
    $nextSrc = $nextShort ? (string)find_source($nextShort, __DIR__) : '';
    $nextYt = $nextSrc !== '' ? extract_youtube_id($nextSrc) : null;

    $src = find_source($video, __DIR__);
    $yt = $src ? extract_youtube_id($src) : null;

    $title = $video['title'] ?? 'Untitled';
    $channel = trim((string)($video['channel_name'] ?? ''));
    if ($channel === '') $channel = trim((string)($video['username'] ?? 'Unknown'));
    $likes = (int)($video['likes'] ?? 0);
    $subs = (int)($video['subscribers'] ?? 0);
    $views = (int)($video['views'] ?? 0);
    $description = trim((string)($video['description'] ?? ''));
    if ($description === '') $description = 'Enjoy this short on ' . $siteName . '.';

    $channelOwner = trim((string)($video['username'] ?? ''));
    if ($channelOwner === '') $channelOwner = $channel;
    $subsFallback = $subs;
    $subs = vs_subscription_channel_count($conn, $channel, $channelOwner, $subsFallback);
    $viewerName = trim((string)($_SESSION['username'] ?? ''));
    $isOwnChannel = $viewerName !== '' && strcasecmp($viewerName, $channelOwner) === 0;
    $avatarUrl = avatar_for_record($channelOwner, $video);
    if ($avatarUrl === '' && $channelOwner !== $channel) $avatarUrl = avatar_url($channel);
    $channelInitial = mb_strtoupper(mb_substr(trim((string)$channel) !== '' ? trim((string)$channel) : 'V', 0, 1));

    try {
        $comments = vs_comments_fetch($conn, (string)$id, trim((string)($_SESSION['username'] ?? '')));
    } catch (Throwable $commentsError) {
        error_log('Comments failed: ' . $commentsError->getMessage());
        $comments = [];
    }
    $contextNotes = [];
    $contextNotesAvailable = true;
    try {
        $contextNotes = vs_context_notes_fetch($conn, (string)$id, 'short');
    } catch (Throwable $contextNotesError) {
        error_log('Community context notes failed: ' . $contextNotesError->getMessage());
        $contextNotesAvailable = false;
    }

    try {
        vs_likes_session_sync($conn);
        $userLiked = vs_likes_has($conn, (string)$id);
    } catch (Throwable $likesError) {
        error_log('Could not synchronize saved video likes: ' . $likesError->getMessage());
        $userLiked = in_array((string)$id, array_map('strval', (array)($_SESSION['liked_videos'] ?? [])), true);
    }
    try {
        vs_subscription_session_sync($conn);
    } catch (Throwable $subscriptionError) {
        error_log('Could not synchronize VibeStream subscriptions: ' . $subscriptionError->getMessage());
    }
    $userSubbed = in_array(vs_subscription_key((string)$channel), $_SESSION['subscribed_channels'] ?? [], true);
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="vs-default-theme" content="<?= htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="<?= $defaultTheme === 'dark' ? '#b02a2a' : '#ffffff' ?>">
    <meta name="description" content="<?= htmlspecialchars(vs_branding_app_description(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="branding-icon.php?size=96">
    <link rel="shortcut icon" type="image/png" href="branding-icon.php?size=96">
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="branding-icon.php">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="style.css?v=5">
    <link rel="preconnect" href="https://www.youtube.com">
    <link rel="preconnect" href="https://i.ytimg.com">
    <script src="brain.js?v=<?php echo (int)@filemtime(__DIR__ . '/brain.js'); ?>" defer></script>
    <script src="theme.js?v=3"></script>
    <link rel="stylesheet" href="neon-off.css?v=7">
    <script src="neon-switch.js?v=7"></script>
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
            --short-h: max(420px, min(calc(100vh - var(--navbar-height) - 40px), 860px));
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: radial-gradient(circle at top, #18181c 0%, var(--bg) 42%);
            color: var(--text);
            font-family: Roboto, Arial, sans-serif;
            overflow-x: hidden;
        }

        a { color: inherit; text-decoration: none; }
        button, input { font: inherit; }

        .app-navbar {
            position: fixed; top: 0; left: 0; right: 0; height: var(--navbar-height);
            display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 18px;
            padding: 0 20px; background: rgba(15, 15, 15, 0.96); backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border); z-index: 1000;
        }

        .nav-left, .nav-center, .nav-right {
            display: flex; align-items: center; gap: 14px;
        }
        .nav-center { justify-content: center; }
        .nav-right { justify-content: flex-end; }

        .brand { font-size: 22px; font-weight: 700; letter-spacing: 0.2px; white-space: nowrap; }
        .brand .site-brand-icon { width: 38px; height: 38px; }

        .nav-toggle {
            width: 44px; height: 44px; border: none; background: transparent; border-radius: 12px;
            display: flex; flex-direction: column; justify-content: center; gap: 5px; padding: 0 10px; cursor: pointer;
        }
        .nav-toggle:hover { background: var(--surface-3); }
        .nav-toggle span { display: block; width: 100%; height: 2px; border-radius: 999px; background: var(--text); }

        .nav-link {
            background: var(--surface-2); border-radius: 999px; padding: 8px 14px;
            font-size: 14px; color: var(--text); border: 1px solid transparent;
        }
        .nav-link.active { background: var(--accent-soft); color: #ffb3b3; border-color: rgba(176, 42, 42, 0.45); }

        .create-btn, .sign-in-btn {
            background: var(--accent); color: #ffffff; padding: 10px 16px; border-radius: 999px;
            font-size: 14px; font-weight: 700; white-space: nowrap;
        }

        .user-badge-pill {
            display: inline-flex; align-items: center; gap: 10px;
            background: linear-gradient(180deg, #252526 0%, #1b1b1d 100%);
            border: 1px solid #3a3a3d; border-radius: 999px; padding: 5px 14px 5px 6px; min-height: 46px;
        }
        .user-avatar-circle {
            width: 32px; height: 32px; border-radius: 50%; background: #3ea6ff; color: #ffffff;
            font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;
            overflow: hidden;
        }
        .user-avatar-circle img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .user-link { display: inline-flex; align-items: center; gap: 10px; }
        .user-name { color: #ffffff; font-size: 14px; font-weight: 600; white-space: nowrap; }
        .logout-link {
            color: #b9b9bb; font-size: 13px; padding-left: 12px; margin-left: 2px;
            border-left: 1px solid #444449; white-space: nowrap;
        }
        .logout-link:hover { color: #ffffff; }

        .app-layout { min-height: 100vh; padding-top: var(--navbar-height); }
        .app-sidebar {
            position: fixed; top: var(--navbar-height); left: 0; bottom: 0; width: var(--sidebar-width);
            padding: 18px 12px 24px; background: #111111; border-right: 1px solid var(--border);
            overflow-y: auto; z-index: 950; transition: transform 0.25s ease;
        }
        .sidebar-heading {
            font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase;
            letter-spacing: 0.08em; padding: 4px 12px 12px;
        }
        .sidebar-nav { display: flex; flex-direction: column; gap: 6px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 12px; padding: 8px 10px; border-radius: 14px;
            color: var(--text); font-size: 15px; font-weight: 500; transition: background 0.2s ease, color 0.2s ease;
        }
        .sidebar-link:hover { background: var(--accent-hover); }
        .sidebar-link.active { background: var(--accent); color: #ffffff; }
       .sidebar-link.active {
            background: var(--accent);
            color: #ffffff;
        }

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
        .app-main { margin-left: var(--sidebar-width); min-width: 0; min-height: calc(100vh - var(--navbar-height)); }

        .shorts-shell {
            min-height: calc(100vh - var(--navbar-height));
            display: flex; align-items: center; justify-content: center;
            padding: 20px 24px;
        }

        .shorts-stage {
            width: 100%; max-width: 1000px;
            display: grid; grid-template-columns: 1fr auto 1fr; align-items: end; column-gap: 22px;
        }

        .short-card {
            grid-column: 2;
            height: var(--short-h);
            aspect-ratio: 9 / 16;
            max-width: 100%;
        }
        .player-card {
            position: relative; width: 100%; height: 100%;
            border-radius: 24px; overflow: hidden; background: #000000;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.5); border: 1px solid rgba(255, 255, 255, 0.06);
        }
        .player { position: relative; width: 100%; height: 100%; background: #000000; }
        .player iframe, .player video { width: 100%; height: 100%; border: 0; object-fit: cover; display: block; }
        .player .msg {
            height: 100%; display: flex; align-items: center; justify-content: center; text-align: center;
            color: #9a9aa2; padding: 24px; font-size: 14px; line-height: 1.5;
        }
        .player-gradient {
            position: absolute; left: 0; right: 0; bottom: 0; height: 48%;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.85), rgba(0, 0, 0, 0)); pointer-events: none;
        }

        .short-overlay {
            position: absolute; left: 16px; right: 16px; bottom: 16px; z-index: 2;
            pointer-events: none;
        }
        .short-overlay .subscribe-btn { pointer-events: auto; }

        .meta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; min-width: 0; }
        .short-overlay .avatar {
            display: grid; place-items: center; width: 44px; height: 44px; flex: 0 0 44px;
            overflow: hidden; border: 2px solid rgba(255,255,255,.82); border-radius: 50%;
            background: linear-gradient(135deg, #1687ff, #a334d9); color: #ffffff;
            box-shadow: 0 2px 10px rgba(0,0,0,.4);
        }
        .short-overlay .avatar img { display: block; width: 100%; height: 100%; object-fit: cover; object-position: center; }
        .short-overlay .avatar-initial { font-size: 18px; font-weight: 800; line-height: 1; }
        .channel-stack { min-width: 0; }
        .channel-name {
            font-size: 14px; font-weight: 600; margin: 0;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .channel-meta { font-size: 12px; color: #d6d6d8; margin: 2px 0 0; }
        .subscribe-btn {
            margin-left: auto; background: #f1f1f1; color: #0f0f0f; border: none; border-radius: 999px;
            padding: 8px 15px; font-weight: 700; font-size: 13px; cursor: pointer; flex-shrink: 0;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .subscribe-btn:hover { background: #d9d9d9; }
        .subscribe-btn.on { background: rgba(60, 60, 60, 0.92); color: #f1f1f1; }

        .short-title {
            margin: 0 0 6px; font-size: 17px; line-height: 1.3; font-weight: 700;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }
        .short-description {
            margin: 0; font-size: 13px; line-height: 1.45; color: #dddddf;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
        }

        .actions-column {
            grid-column: 3; justify-self: start;
            display: flex; flex-direction: column; gap: 16px; padding-bottom: 6px;
        }
        .nav-column { display: flex; flex-direction: column; gap: 14px; }

        .icon-btn, .nav-btn, .stat-item {
            border: none; background: none; color: #ffffff; text-decoration: none;
            display: flex; flex-direction: column; align-items: center; gap: 6px; font-size: 12px;
        }
        .icon-btn, .nav-btn { cursor: pointer; }
        .icon-btn .circle, .nav-btn .circle, .stat-item .circle {
            width: 52px; height: 52px; border-radius: 50%; background: var(--surface-2);
            display: flex; align-items: center; justify-content: center; font-size: 22px;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .icon-btn:hover .circle, .nav-btn:hover .circle { background: #3a3a3a; transform: translateY(-1px); }
        .nav-btn.disabled { opacity: 0.45; pointer-events: none; }

        /* ---- like / comment / views: outline icons + theme-aware counts ---- */
        .actions-column .icon-btn, .actions-column .stat-item { color: var(--text, #f1f1f1); }
        .actions-column .icon-btn > span:last-child, .actions-column .stat-item > span:last-child {
            font-size: 13px; font-weight: 600; color: inherit;
        }
        .actions-column .circle svg {
            width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 1.8;
            stroke-linecap: round; stroke-linejoin: round;
        }
        .unmute-pill {
            position: absolute; top: 14px; left: 50%; transform: translateX(-50%); z-index: 4;
            background: rgba(0, 0, 0, 0.7); color: #ffffff; border: 1px solid rgba(255, 255, 255, 0.25);
            border-radius: 999px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .unmute-pill[hidden] { display: none; }
        /* tap to pause / double-tap to like / progress bar / repeat toggle */
        .short-card.slide-out-next { animation: slideOutUp .2s ease-in forwards; }
        .short-card.slide-out-prev { animation: slideOutDown .2s ease-in forwards; }
        .short-card.slide-in-next { animation: slideInUp .3s cubic-bezier(.2,.8,.2,1); }
        .short-card.slide-in-prev { animation: slideInDown .3s cubic-bezier(.2,.8,.2,1); }
        @keyframes slideOutUp { to { transform: translateY(-55%); opacity: 0; } }
        @keyframes slideOutDown { to { transform: translateY(55%); opacity: 0; } }
        @keyframes slideInUp { from { transform: translateY(55%); opacity: 0; } }
        @keyframes slideInDown { from { transform: translateY(-55%); opacity: 0; } }
        .player-loader { position: absolute; inset: 0; z-index: 3; display: grid; place-items: center; background: #000; pointer-events: none; transition: opacity .15s ease; }
        .player-loader::after { content: ''; width: 46px; height: 46px; border-radius: 50%; border: 4px solid rgba(255,255,255,.2); border-top-color: #fff; animation: loaderSpin .8s linear infinite; }
        .player-loader.done { opacity: 0; }
        @keyframes loaderSpin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .short-card[class*="slide-"] { animation: none !important; } }
        .tap-layer { position: absolute; left: 0; right: 0; top: 0; bottom: 56px; z-index: 1; cursor: pointer; touch-action: manipulation; -webkit-tap-highlight-color: transparent; }
        .tap-layer.yt { top: 56px; bottom: 110px; }
        .tap-burst { position: absolute; z-index: 5; width: 96px; height: 96px; margin: -48px 0 0 -48px; pointer-events: none; opacity: 0; }
        .tap-burst svg { width: 100%; height: 100%; fill: #ff2d55; filter: drop-shadow(0 4px 14px rgba(0,0,0,.5)); }
        .tap-burst.go { animation: tapBurst .8s ease-out; }
        @keyframes tapBurst { 0% { opacity: 0; transform: scale(.4); } 20% { opacity: 1; transform: scale(1.1); } 60% { opacity: 1; transform: scale(1); } 100% { opacity: 0; transform: scale(1.25) translateY(-14px); } }
        .tap-flash { position: absolute; left: 50%; top: 50%; z-index: 5; width: 72px; height: 72px; margin: -36px 0 0 -36px; border-radius: 50%; background: rgba(0,0,0,.55); display: grid; place-items: center; pointer-events: none; opacity: 0; }
        .tap-flash svg { width: 34px; height: 34px; fill: #fff; }
        .tap-flash.go { animation: tapFlash .6s ease-out; }
        @keyframes tapFlash { 0% { opacity: 0; transform: scale(.7); } 25% { opacity: 1; transform: scale(1); } 100% { opacity: 0; transform: scale(1.15); } }
        .short-progress { position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: rgba(255,255,255,.22); z-index: 3; pointer-events: none; }
        .short-progress i { display: block; height: 100%; width: 0; background: #ff2d55; }
        .actions-column .icon-btn.on .circle { color: #00b8e6; background: rgba(0,229,255,.16); border-color: rgba(0,229,255,.9) !important; }
        .icon-btn.liked .circle svg { fill: #ff2d55; stroke: #ff2d55; }
        .icon-btn.liked .circle { animation: likePop 0.3s ease; }
        @keyframes likePop { 50% { transform: scale(1.22); } }
        /* light theme: dark numbers + dark icons (covers common theme switches) */
        html[data-theme="light"] .actions-column .icon-btn, html[data-theme="light"] .actions-column .stat-item,
        body[data-theme="light"] .actions-column .icon-btn, body[data-theme="light"] .actions-column .stat-item,
        html.light .actions-column .icon-btn, html.light .actions-column .stat-item,
        body.light .actions-column .icon-btn, body.light .actions-column .stat-item,
        body.light-mode .actions-column .icon-btn, body.light-mode .actions-column .stat-item,
        html.light-theme .actions-column .icon-btn, html.light-theme .actions-column .stat-item,
        body.light-theme .actions-column .icon-btn, body.light-theme .actions-column .stat-item { color: #1a1a1a; }

        .comments-modal {
            position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
            background: rgba(0, 0, 0, 0.7); padding: 20px; z-index: 1100;
        }
        .comments-modal.open { display: flex; }
        .comments-box {
            width: min(460px, 100%); max-height: min(78vh, 720px); background: var(--surface-raised, #16161a); border: 1px solid var(--border, #2a2a31);
            color: var(--text-primary, #f4f4f6); border-radius: 20px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }
        .comments-top {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 18px 20px; border-bottom: 1px solid var(--border, #2a2a31);
        }
        .comments-top h3 { margin: 0; font-size: 18px; color: var(--text-primary, #f4f4f6); }
        .close-btn { background: none; border: none; color: var(--text-secondary, #aaaaaa); font-size: 24px; cursor: pointer; line-height: 1; }
        .comment-list {
            flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 12px;
        }
        .comment-item {
            display: flex; align-items: flex-start; gap: 10px;
            background: var(--surface-2, #1e1e24); color: var(--text-primary, #f4f4f6); border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.5; word-break: break-word;
        }
        .comment-avatar,
        .comment-avatar-initial {
            width: 32px; height: 32px; flex: 0 0 32px; border-radius: 50%; object-fit: cover;
        }
        .comment-avatar-initial {
            display: grid; place-items: center; background: linear-gradient(135deg, #1687ff, #a334d9);
            color: #ffffff; font-weight: 800;
        }
        .comment-copy { display: grid; min-width: 0; gap: 3px; }
        .comment-item strong { color: var(--text-primary, #ffffff); }
        .comment-copy { color: var(--text-primary, #f4f4f6); }
        .empty-comments { color: var(--text-secondary, #9a9aa2); text-align: center; padding: 18px 0; font-size: 14px; }
        .comment-send { display: flex; gap: 10px; padding: 16px 20px 20px; border-top: 1px solid var(--border, #2a2a31); }
        .comment-send input {
            flex: 1; background: var(--surface-base, #0d0d0f); border: 1px solid var(--border-strong, #3d3d46); border-radius: 12px;
            padding: 10px 14px; color: var(--text-primary, #ffffff); outline: none;
        }
        .comment-send input:focus { border-color: var(--text-secondary, #575764); }
        .comment-send input::placeholder { color: var(--text-secondary, #8a8a94); }
        .comment-send button {
            background: var(--accent); border: none; color: #ffffff; padding: 10px 16px;
            border-radius: 12px; font-weight: 700; cursor: pointer;
        }
        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--surface-2);
            color: var(--text-primary, #ffffff); padding: 10px 18px; border-radius: 10px; font-size: 14px; display: none; z-index: 1200;
        }
        .sidebar-overlay {
            position: fixed; top: var(--navbar-height); left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.55);
            opacity: 0; visibility: hidden; transition: opacity 0.25s ease, visibility 0.25s ease; z-index: 940;
        }
        .sidebar-overlay.show { opacity: 1; visibility: visible; }

        @media (min-width: 1001px) {
            .nav-toggle { display: none; }
            .sidebar-overlay { display: none; }
            .nav-column {
                position: fixed; right: 28px;
                top: calc(var(--navbar-height) + (100vh - var(--navbar-height)) / 2);
                transform: translateY(-50%); z-index: 900;
            }
        }
        @media (max-width: 1000px) {
            body.sidebar-open { overflow: hidden; }
            .app-sidebar { transform: translateX(-100%); width: min(82vw, 280px); box-shadow: none; }
            .app-sidebar.open { transform: translateX(0); box-shadow: 18px 0 40px rgba(0, 0, 0, 0.45); }
            .app-main { margin-left: 0; }
            .shorts-shell { padding: 16px; }

            .shorts-stage { display: flex; flex-direction: column; align-items: center; gap: 16px; }
            .short-card {
                height: auto;
                width: min(100%, calc((100vh - var(--navbar-height) - 140px) * 9 / 16));
                min-width: 260px;
            }
            .actions-column, .nav-column { flex-direction: row; gap: 18px; padding-bottom: 0; }
        }
        @media (max-width: 760px) {
            .app-navbar, .app-sidebar, .sidebar-overlay { display: none !important; }
            .app-layout, .app-main { min-height: 100dvh; padding: 0; margin: 0; }
            .shorts-shell { position: fixed; inset: 0; min-height: 0; padding: 0; }
            .shorts-stage { position: absolute; inset: 0; display: block; width: 100%; max-width: none; }
            .short-card { width: 100%; height: 100%; min-width: 0; aspect-ratio: auto; }
            .player-card { border: 0; border-radius: 0; box-shadow: none; }
            .short-overlay {
                left: 16px; right: 82px;
                bottom: calc(20px + env(safe-area-inset-bottom));
            }
            .short-title { font-size: 16px; }
            .short-description { -webkit-line-clamp: 2; }
            .actions-column {
                position: absolute; right: 12px;
                bottom: calc(190px + env(safe-area-inset-bottom));
                flex-direction: column; gap: 10px; padding: 0; z-index: 3;
            }
            .nav-column {
                position: absolute; right: 15px; top: 42%;
                flex-direction: column; gap: 8px; z-index: 3;
            }
            .icon-btn .circle, .nav-btn .circle, .stat-item .circle {
                width: 44px; height: 44px; background: rgba(20, 20, 20, 0.62);
            }
            .actions-column .icon-btn, .actions-column .stat-item { color: #ffffff !important; }
            .comments-box { max-height: 82dvh; }
        }
        @media (max-width: 640px) {
            .comments-box { max-height: 86vh; }
            .comment-send { flex-direction: column; }
        }
    </style>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="theme.css?v=5">
    <link rel="stylesheet" href="navbar-fix.css?v=3">
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

        /* ---- shorts page ---- */
        html:not([data-theme="light"]) .player-card { border: 1px solid rgba(138,92,255,.55); box-shadow: 0 0 30px -4px rgba(0,229,255,.5), 0 0 70px -16px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) .subscribe-btn { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 16px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) .subscribe-btn:hover { background: linear-gradient(90deg, #8a5cff, #ff2e93); }
        html:not([data-theme="light"]) .subscribe-btn.on { background: rgba(10,13,31,.85); color: #fff; border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) .short-overlay .avatar { box-shadow: 0 0 0 2px rgba(255,46,147,.8), 0 0 12px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) .short-title { text-shadow: 0 0 12px rgba(138,92,255,.8); }
        html:not([data-theme="light"]) .icon-btn .circle, html:not([data-theme="light"]) .nav-btn .circle, html:not([data-theme="light"]) .stat-item .circle { border: 1px solid rgba(138,92,255,.55); box-shadow: 0 0 12px -2px rgba(138,92,255,.55); transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
        html:not([data-theme="light"]) .icon-btn:hover .circle, html:not([data-theme="light"]) .nav-btn:hover .circle { border-color: #00e5ff; box-shadow: 0 0 18px rgba(0,229,255,.7); background: rgba(0,229,255,.16); }
        html:not([data-theme="light"]) .icon-btn.liked .circle { border-color: #ff2e93; box-shadow: 0 0 20px rgba(255,46,147,.75); }
        html:not([data-theme="light"]) .icon-btn.liked .circle svg { filter: drop-shadow(0 0 6px rgba(255,46,147,.9)); }
        html:not([data-theme="light"]) .unmute-pill { border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 16px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) .comments-box { background: rgba(10,13,31,.88); border: 1px solid rgba(0,229,255,.5); box-shadow: 0 0 40px -6px rgba(0,229,255,.5), 0 0 80px -20px rgba(138,92,255,.6); }
        html:not([data-theme="light"]) .comments-top h3 { text-shadow: 0 0 10px rgba(138,92,255,.6); }
        html:not([data-theme="light"]) .comment-send input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.5); color: #eaf0ff; }
        html:not([data-theme="light"]) .comment-send input:focus { border-color: #00e5ff; box-shadow: 0 0 16px rgba(0,229,255,.5); outline: none; }
        html:not([data-theme="light"]) .comment-send button { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 14px rgba(255,46,147,.5); }
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

        /* ---- shorts page ---- */
        html[data-theme="light"] .player-card { border: 1px solid rgba(124,77,255,.55); box-shadow: 0 0 30px -4px rgba(0,184,230,.5), 0 0 70px -16px rgba(255,46,147,.5); }
        html[data-theme="light"] .subscribe-btn { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 16px rgba(255,46,147,.55); }
        html[data-theme="light"] .subscribe-btn:hover { background: linear-gradient(90deg, #7c4dff, #ff2e93); }
        html[data-theme="light"] .subscribe-btn.on { background: rgba(10,13,31,.85); color: #fff; border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] .short-overlay .avatar { box-shadow: 0 0 0 2px rgba(255,46,147,.8), 0 0 12px rgba(255,46,147,.55); }
        html[data-theme="light"] .short-title { text-shadow: 0 0 12px rgba(124,77,255,.8); }
        html[data-theme="light"] .icon-btn .circle, html[data-theme="light"] .nav-btn .circle, html[data-theme="light"] .stat-item .circle { border: 1px solid rgba(124,77,255,.55); box-shadow: 0 0 12px -2px rgba(124,77,255,.55); transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
        html[data-theme="light"] .icon-btn:hover .circle, html[data-theme="light"] .nav-btn:hover .circle { border-color: #00b8e6; box-shadow: 0 0 18px rgba(0,184,230,.7); background: rgba(0,184,230,.16); }
        html[data-theme="light"] .icon-btn.liked .circle { border-color: #ff2e93; box-shadow: 0 0 20px rgba(255,46,147,.75); }
        html[data-theme="light"] .icon-btn.liked .circle svg { filter: drop-shadow(0 0 6px rgba(255,46,147,.9)); }
        html[data-theme="light"] .unmute-pill { border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 16px rgba(0,184,230,.45); }
        html[data-theme="light"] .comments-box { background: #ffffff; border: 1px solid rgba(0,184,230,.5); box-shadow: 0 0 40px -6px rgba(0,184,230,.5), 0 0 80px -20px rgba(124,77,255,.6); }
        html[data-theme="light"] .comments-top h3 { text-shadow: 0 0 10px rgba(124,77,255,.6); }
        html[data-theme="light"] .comment-send input { background: #ffffff; border: 1px solid rgba(124,77,255,.5); color: #0b1030; }
        html[data-theme="light"] .comment-send input:focus { border-color: #00b8e6; box-shadow: 0 0 16px rgba(0,184,230,.5); outline: none; }
        html[data-theme="light"] .comment-send button { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 14px rgba(255,46,147,.5); }
        html[data-theme="light"] .toast { border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 22px rgba(0,184,230,.45); }
    </style>
    <link rel="stylesheet" href="sidebar-rail.css?v=1">
    <script src="sidebar-rail.js?v=1"></script>
    <link rel="stylesheet" href="context-notes.css?v=1">
</head>
<body>
    <?php render_navbar('shorts'); ?>
    <?php echo vs_site_announcement_html($GLOBALS['pdo'] ?? null); ?>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="app-layout">
        <?php render_sidebar('shorts'); ?>

        <main class="app-main">
            <div class="shorts-shell">
                <div class="shorts-stage">

                    <!-- center: the short -->
                    <div class="short-card">
                        <div class="player-card">
                            <div class="player" id="playerWrap">
                                <?php if ($yt): ?>
                                    <iframe id="ytFrame"
                                        src="https://www.youtube.com/embed/<?= htmlspecialchars($yt) ?>?autoplay=1&amp;mute=1&amp;enablejsapi=1&amp;playsinline=1&amp;rel=0"
                                        allow="autoplay; encrypted-media; picture-in-picture"
                                        allowfullscreen></iframe>
                                <?php elseif ($src): ?>
                                    <video id="shortVideo" src="<?= htmlspecialchars($src) ?>" autoplay loop playsinline controls></video>
                                <?php else: ?>
                                    <div class="msg"><?= $noVideo ? 'No shorts available yet. Upload one to get started.' : 'This short has no video saved.' ?></div>
                                <?php endif; ?>

                                <div class="player-gradient"></div>
                                <button type="button" id="unmuteBtn" class="unmute-pill" hidden>&#128266; Tap to unmute</button>
                                <?php if ($yt || $src): ?>
                                    <div class="tap-layer<?= $yt ? ' yt' : '' ?>" id="tapLayer"></div>
                                    <div class="tap-burst" id="tapBurst"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></div>
                                    <div class="tap-flash" id="tapFlash"></div>
                                    <div class="player-loader" id="playerLoader"></div>
                                    <div class="short-progress"><i id="progressFill"></i></div>
                                <?php endif; ?>

                                <div class="short-overlay">
                                    <div class="meta-row">
                                        <div class="avatar">
                                            <?php if ($avatarUrl !== ''): ?>
                                                <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="<?= htmlspecialchars($channel) ?> channel avatar">
                                            <?php else: ?>
                                                <span class="avatar-initial" aria-label="<?= htmlspecialchars($channel) ?> channel avatar"><?= htmlspecialchars($channelInitial) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="channel-stack">
                                            <p class="channel-name">@<?= htmlspecialchars($channel) ?><?php echo function_exists('vs_vip_badge_html') ? vs_vip_badge_html($channelOwner) : ''; ?></p>
                                            <p class="channel-meta" id="subCount"><?= short_num($subs) ?> subscriber<?= $subs == 1 ? '' : 's' ?></p>
                                        </div>
                                        <?php if (!$isOwnChannel): ?>
                                            <button type="button" id="subBtn" class="subscribe-btn<?= $userSubbed ? ' on' : '' ?>"<?= $noVideo ? ' disabled' : '' ?>><?= $userSubbed ? 'Subscribed' : 'Subscribe' ?></button>
                                        <?php endif; ?>
                                    </div>
                                    <h1 class="short-title"><?= htmlspecialchars($title) ?></h1>
                                    <p class="short-description"><?= htmlspecialchars($description) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- right of the short: like / comments / views -->
                    <div class="actions-column">
                        <button class="icon-btn<?= $userLiked ? ' liked' : '' ?>" type="button" id="likeBtn" aria-label="Like this short" aria-pressed="<?= $userLiked ? 'true' : 'false' ?>"<?= $noVideo ? ' disabled' : '' ?>>
                            <span class="circle" id="likeIcon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg></span>
                            <span id="likeCount"><?= number_format($likes) ?></span>
                        </button>
                        <button class="icon-btn" type="button" id="commentBtn">
                            <span class="circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 8h8M8 12h5"/></svg></span>
                            <span id="commentCount"><?= short_num(count($comments)) ?></span>
                        </button>
                        <div class="stat-item" title="Views">
                            <span class="circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></span>
                            <span id="viewCount"><?= number_format($views) ?></span>
                        </div>
                        <button class="icon-btn on" type="button" id="loopBtn" aria-pressed="true" title="Repeat this short, or go to the next one when it ends">
                            <span class="circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 2l4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="M7 22l-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></svg></span>
                            <span id="loopLabel">Repeat</span>
                        </button>
                        <?php if (!$noVideo && !empty($video['id'])) { $reportContentId = (string)$video['id']; $reportKind = 'short'; if (is_file(__DIR__ . '/report-widget.php')) { include __DIR__ . '/report-widget.php'; } } ?>
                    </div>

                    <!-- far right: previous / next -->
                    <div class="nav-column">
                        <a href="<?= !$noVideo && !empty($prev['id']) ? 'shorts.php?id=' . urlencode($prev['id']) : '#' ?>" class="nav-btn<?= ($noVideo || empty($prev['id'])) ? ' disabled' : '' ?>" id="prevBtn" title="Previous short">
                            <span class="circle">↑</span>
                        </a>
                        <a href="<?= !$noVideo ? ($nextId !== '' ? 'shorts.php?id=' . urlencode($nextId) : 'shorts.php') : '#' ?>" class="nav-btn<?= $noVideo ? ' disabled' : '' ?>" id="nextBtn" title="Next short (random)">
                            <span class="circle">↓</span>
                        </a>
                    </div>

                </div>
            </div>
            <?php echo vs_ads_html(); ?>
        </main>
    </div>

    <div class="comments-modal" id="modal">
        <?php
        $viewerName = trim((string)($_SESSION['username'] ?? ''));
        echo vs_comments_panel_html([
            'endpoint' => 'shorts.php',
            'video_id' => (string)($video['id'] ?? ''),
            'comments' => $comments,
            'viewer' => $viewerName,
            'is_admin' => vs_comments_is_admin(),
            'viewer_avatar' => $viewerName !== '' ? avatar_url($viewerName) : '',
            'modal' => true,
            'disabled' => $noVideo,
            'list_id' => 'list',
        ]);
        ?>
    </div>
    <?php if (!$noVideo): ?>
        <?php echo vs_context_notes_panel_html($contextNotes ?? [], (string)($video['id'] ?? ''), 'short', vs_csrf_token(), !empty($_SESSION['username']), $contextNotesAvailable ?? true); ?>
    <?php endif; ?>

    <div class="toast" id="toast"></div>

    <script src="comments.js?v=1"></script>
    <script src="context-notes.js?v=1"></script>
    <script>
        const VIDEO_ID = <?= json_encode((string)($video['id'] ?? '')) ?>;
        const $ = id => document.getElementById(id);

        function toast(msg) {
            const t = $('toast');
            t.textContent = msg;
            t.style.display = 'block';
            setTimeout(() => { t.style.display = 'none'; }, 3000);
        }

        function shortNum(n) {
            n = Number(n) || 0;
            if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
            if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'k';
            return String(n);
        }

        async function send(action, extra = {}) {
            try {
                const res = await fetch('shorts.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({ action, video_id: VIDEO_ID, ...extra })
                });
                const text = await res.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Non-JSON response:', text);
                    return { success: false, error: 'Server returned invalid JSON' };
                }
            } catch (e) {
                return { success: false, error: 'Network error' };
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const navToggle = $('navToggle');
            const appSidebar = $('appSidebar');
            const sidebarOverlay = $('sidebarOverlay');
            const modal = $('modal');
            const commentBtn = $('commentBtn');
            const closeBtn = $('closeBtn');
            const likeBtn = $('likeBtn');
            const subBtn = $('subBtn');
            const player = $('playerWrap');
            const shortCard = document.querySelector('.short-card');
            const prevBtn = $('prevBtn');
            const nextBtn = $('nextBtn');

            function setSidebar(open) {
                if (!appSidebar || !sidebarOverlay) return;
                appSidebar.classList.toggle('open', open);
                sidebarOverlay.classList.toggle('show', open);
                document.body.classList.toggle('sidebar-open', open);
                if (navToggle) navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            if (navToggle) {
                navToggle.addEventListener('click', () => {
                    const willOpen = !appSidebar.classList.contains('open');
                    setSidebar(willOpen);
                });
            }

            if (sidebarOverlay) sidebarOverlay.addEventListener('click', () => setSidebar(false));
            window.addEventListener('resize', () => { if (window.innerWidth > 1000) setSidebar(false); });

            if (commentBtn) commentBtn.addEventListener('click', () => { modal.classList.add('open'); });
            if (closeBtn) closeBtn.addEventListener('click', () => modal.classList.remove('open'));
            if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) modal.classList.remove('open'); });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    setSidebar(false);
                    if (modal) modal.classList.remove('open');
                }
            });

            if (likeBtn && VIDEO_ID) {
                likeBtn.addEventListener('click', async () => {
                    likeBtn.disabled = true;
                    try {
                        const d = await send('like');
                        if (!d.success) return toast(d.error || 'Like failed');
                        likeBtn.classList.toggle('liked', !!d.liked);
                        $('likeCount').textContent = Number(d.likes).toLocaleString();
                        likeBtn.setAttribute('aria-pressed', String(d.liked));
                    } finally {
                        likeBtn.disabled = false;
                    }
                });
            }

            if (subBtn && VIDEO_ID) {
                subBtn.addEventListener('click', async () => {
                    const d = await send('subscribe');
                    if (!d.success) return toast(d.error || 'Subscribe failed');
                    subBtn.textContent = d.subscribed ? 'Subscribed' : 'Subscribe';
                    subBtn.classList.toggle('on', d.subscribed);
                    $('subCount').textContent = shortNum(d.subscribers) + (d.subscribers == 1 ? ' subscriber' : ' subscribers');
                });
            }

            // keep the Shorts comment counter in sync when comments are added / deleted
            document.addEventListener('vs-comments:count', (e) => {
                const counter = $('commentCount');
                if (counter) counter.textContent = shortNum(e.detail.count);
            });

            document.addEventListener('keydown', (e) => {
                if (modal && modal.classList.contains('open')) return;
                if (e.key === 'ArrowDown' && nextBtn && !nextBtn.classList.contains('disabled')) nextBtn.click();
                if (e.key === 'ArrowUp' && prevBtn && !prevBtn.classList.contains('disabled')) prevBtn.click();
            });

            if (player) {
                let touchStartY = 0;
                player.addEventListener('touchstart', (e) => { touchStartY = e.touches[0].clientY; }, { passive: true });
                player.addEventListener('touchend', (e) => {
                    if (!prevBtn || !nextBtn) return;
                    const dy = e.changedTouches[0].clientY - touchStartY;
                    if (Math.abs(dy) < 50) return;
                    (dy < 0 ? nextBtn : prevBtn).click();
                }, { passive: true });
            }

            // moving to another short takes a second: buttons, arrow keys, swipes and the wheel all share one 1s lock
            const NAV_LOCK_KEY = 'vs-shorts-wheel-lock-until';
            [prevBtn, nextBtn].forEach((btn) => {
                if (!btn) return;
                btn.addEventListener('click', (e) => {
                    if (Date.now() < Number(sessionStorage.getItem(NAV_LOCK_KEY) || 0)) { e.preventDefault(); e.stopImmediatePropagation(); return; }
                    sessionStorage.setItem(NAV_LOCK_KEY, String(Date.now() + 600));
                    // slide the short away like YouTube Shorts, then open the other one
                    const href = btn.getAttribute('href');
                    if (shortCard && href && href !== '#' && !btn.classList.contains('disabled') && !e.defaultPrevented) {
                        const dir = btn === nextBtn ? 'next' : 'prev';
                        e.preventDefault();
                        try { sessionStorage.setItem('vs-shorts-dir', dir); } catch (err) {}
                        shortCard.classList.add('slide-out-' + dir);
                        setTimeout(() => { window.location.href = btn.href; }, 200);
                    }
                }, true);
            });

            // arriving from prev/next: slide the new short in from the matching side
            try {
                const arrived = sessionStorage.getItem('vs-shorts-dir');
                if (arrived) { sessionStorage.removeItem('vs-shorts-dir'); if (shortCard) shortCard.classList.add('slide-in-' + arrived); }
            } catch (err) {}

            let wheelDistance = 0;
            let wheelDirection = 0;
            let wheelNavigating = false;
            if (shortCard) shortCard.addEventListener('wheel', (e) => {
                if ((modal && modal.classList.contains('open')) || Math.abs(e.deltaY) < Math.abs(e.deltaX) * 0.6) return;
                e.preventDefault();
                if (wheelNavigating || Date.now() < Number(sessionStorage.getItem('vs-shorts-wheel-lock-until') || 0)) return;

                const delta = e.deltaY * (e.deltaMode === WheelEvent.DOM_DELTA_LINE ? 16 : e.deltaMode === WheelEvent.DOM_DELTA_PAGE ? window.innerHeight : 1);
                const direction = Math.sign(delta);
                if (!direction) return;
                if (direction !== wheelDirection) wheelDistance = 0;
                wheelDirection = direction;
                wheelDistance += Math.abs(delta);

                if (wheelDistance < 14) return;
                const target = wheelDirection > 0 ? nextBtn : prevBtn;
                wheelDistance = 0;
                if (!target || target.classList.contains('disabled')) return;
                wheelNavigating = true;
                target.click();
            }, { passive: false, capture: true });
        });
    </script>
    <script>
        /* Keep the short unmuted. Browsers may block sound until the first tap/click,
           so if that happens we start muted, show a "Tap to unmute" pill, and unmute on the first interaction. */
        (function () {
            const pill = document.getElementById('unmuteBtn');
            const vid = document.getElementById('shortVideo');
            const yt = document.getElementById('ytFrame');
            if (!pill || (!vid && !yt)) return;
            let lastYoutubeViewState = -1;
            let firstDirectPlayRecorded = false;
            let lastViewRequestAt = 0;
            const recordShortView = async () => {
                const now = Date.now();
                if (now - lastViewRequestAt < 800) return;
                lastViewRequestAt = now;
                try {
                    const response = await fetch('view-api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            video_id: <?= json_encode((string)$id) ?>,
                            content_type: 'short'
                        })
                    });
                    const result = await response.json();
                    if (result.success) {
                        const label = document.getElementById('viewCount');
                        if (label) label.textContent = Number(result.views).toLocaleString();
                    }
                } catch (error) {
                    console.error('Could not record Short view:', error);
                }
            };
            if (vid) {
                vid.addEventListener('playing', () => {
                    if (!firstDirectPlayRecorded) {
                        firstDirectPlayRecorded = true;
                        recordShortView();
                    }
                });
                let previousTime = 0;
                vid.addEventListener('timeupdate', () => {
                    const duration = Number.isFinite(vid.duration) ? vid.duration : 0;
                    if (duration > 1 && previousTime > duration - 0.75 && vid.currentTime < 1) {
                        recordShortView();
                    }
                    previousTime = vid.currentTime;
                });
                let wasNearEnd = false;
                vid.addEventListener('seeked', () => {
                    const duration = Number.isFinite(vid.duration) ? vid.duration : 0;
                    if (duration > 1 && wasNearEnd && vid.currentTime < 1) recordShortView();
                    wasNearEnd = vid.currentTime > duration - 0.75;
                });
            }
            const nextBtn = document.getElementById('nextBtn');
            let advanced = false;
            const advanceToNext = () => {
                if (advanced || !nextBtn || nextBtn.classList.contains('disabled')) return;
                const wait = Number(sessionStorage.getItem('vs-shorts-wheel-lock-until') || 0) - Date.now();
                if (wait > 0) { setTimeout(advanceToNext, wait + 30); return; } // respect the 1s wait between shorts
                advanced = true;
                nextBtn.click();
            };

            // Repeat (default) or go to the next short when this one ends. The choice is remembered.
            let loopOn = true;
            try { loopOn = localStorage.getItem('vs-shorts-loop') !== '0'; } catch (e) {}
            let ytState = -1, ytCur = 0, ytDur = 0, ytStamp = 0, ytMuted = true;
            const NEXT_SRC = <?= json_encode(($nextYt || $nextSrc === '') ? '' : $nextSrc) ?>;
            const NEXT_YT = <?= json_encode((string)($nextYt ?: '')) ?>;

            // remember a deliberate mute / unmute between shorts
            let mutedPref = false;
            try { mutedPref = localStorage.getItem('vs-shorts-muted') === '1'; } catch (e) {}
            let lastAuto = 0;                          // changes the page makes by itself are not the viewer's choice
            const markAuto = () => { lastAuto = Date.now(); };
            const savePref = (m) => {
                mutedPref = m;
                try { localStorage.setItem('vs-shorts-muted', m ? '1' : '0'); } catch (e) {}
                if (m) pill.hidden = true;
            };

            // black screen + spinner for about a second while the short loads, like YouTube Shorts
            let warmed = false;
            function warmNext() {
                if (warmed) return;
                warmed = true;
                try {
                    if (navigator.connection && navigator.connection.saveData) return;
                    if (NEXT_YT) {
                        const f = document.createElement('iframe');
                        f.src = 'https://www.youtube.com/embed/' + encodeURIComponent(NEXT_YT) + '?autoplay=0&enablejsapi=1&playsinline=1&rel=0';
                        f.tabIndex = -1; f.title = ''; f.setAttribute('aria-hidden', 'true');
                        f.style.cssText = 'position:fixed;left:-9999px;top:0;width:200px;height:356px;border:0;opacity:0;pointer-events:none';
                        document.body.appendChild(f);
                    } else if (NEXT_SRC) {
                        const v = document.createElement('video');
                        v.preload = 'auto'; v.muted = true; v.src = NEXT_SRC; v.style.display = 'none';
                        document.body.appendChild(v);
                        v.load();
                    }
                } catch (e) {}
            }
            const loader = document.getElementById('playerLoader');
            const loadStart = performance.now();
            let loaderGone = false;
            const hideLoader = () => {
                if (loaderGone || !loader) return;
                loaderGone = true;
                setTimeout(() => loader.classList.add('done'), Math.max(0, 250 - (performance.now() - loadStart)));
                setTimeout(warmNext, 1200);          // only after this short is up, so it never slows it down
            };
            if (vid) { vid.addEventListener('playing', hideLoader); if (!vid.paused && vid.readyState >= 3) hideLoader(); }
            if (yt) yt.addEventListener('load', hideLoader);
            setTimeout(hideLoader, 5000); // never leave the spinner stuck
            setTimeout(warmNext, 6000);   // in case the loader never reported

            const showPill = () => { if (!mutedPref) pill.hidden = false; };
            const hidePill = () => { pill.hidden = true; };
            const ytCmd = (func, args) => {
                if (yt && yt.contentWindow) {
                    yt.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: args || [] }), '*');
                }
            };

            function unmute(force) {
                if (mutedPref && force !== true) return;   // the viewer chose to stay muted
                if (force === true) savePref(false);
                if (vid) {
                    vid.muted = false;
                    vid.volume = 1;
                    const p = vid.play();
                    if (p && p.catch) p.catch(() => { markAuto(); vid.muted = true; vid.play().catch(() => {}); showPill(); });
                    if (!vid.muted) hidePill();
                }
                if (yt) {
                    ytCmd('unMute');
                    ytCmd('setVolume', [100]);
                    ytCmd('playVideo');
                }
            }

            if (vid) {
                if (mutedPref) { markAuto(); vid.muted = true; } else vid.muted = false;
                vid.addEventListener('volumechange', () => { if (Date.now() - lastAuto > 500) savePref(vid.muted || vid.volume === 0); });
                const p = vid.play();
                if (p && p.catch) p.catch(() => { markAuto(); vid.muted = true; vid.play().catch(() => {}); showPill(); });
                vid.addEventListener('ended', () => { if (loopOn) { vid.currentTime = 0; vid.play().catch(() => {}); } else advanceToNext(); });
            }

            if (yt) {
                showPill();
                yt.addEventListener('load', () => {
                    yt.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), '*');
                    yt.contentWindow.postMessage(JSON.stringify({
                        event: 'command',
                        func: 'addEventListener',
                        args: ['onStateChange']
                    }), '*');
                    setTimeout(unmute, 600);   // does nothing when the viewer chose to stay muted
                });
                window.addEventListener('message', (e) => {
                    if (e.source !== yt.contentWindow || !/youtube\.com$/.test(new URL(e.origin).hostname)) return;
                    let d = e.data;
                    if (typeof d === 'string') { try { d = JSON.parse(d); } catch (err) { return; } }
                    if (d && d.event === 'onStateChange' && typeof d.info === 'number') {
                        ytState = d.info;
                        if (ytState === 1 && lastYoutubeViewState !== 1) recordShortView();
                        lastYoutubeViewState = ytState;
                    }
                    if (d && d.event === 'infoDelivery' && d.info) {
                        if (typeof d.info.playerState === 'number') ytState = d.info.playerState;
                        if (ytState === 1 && lastYoutubeViewState !== 1) recordShortView();
                        if (typeof d.info.playerState === 'number') lastYoutubeViewState = ytState;
                        if (typeof d.info.currentTime === 'number') { ytCur = d.info.currentTime; ytStamp = Date.now(); }
                        if (typeof d.info.duration === 'number' && d.info.duration > 0) ytDur = d.info.duration;
                    }
                    if (ytState === 1) hideLoader();
                    if ((d && d.event === 'onStateChange' && Number(d.info) === 0)
                        || (d && d.event === 'infoDelivery' && d.info && Number(d.info.playerState) === 0)) {
                        if (loopOn) { ytCmd('seekTo', [0, true]); ytCmd('playVideo'); } else advanceToNext();
                    }
                    if (d && d.event === 'infoDelivery' && d.info && typeof d.info.muted === 'boolean') {
                        ytMuted = d.info.muted;
                        d.info.muted ? showPill() : hidePill();
                    }
                });
            }

            // ----- repeat toggle -----
            const loopBtn = document.getElementById('loopBtn');
            const applyLoop = () => {
                if (vid) vid.loop = loopOn;
                if (!loopBtn) return;
                loopBtn.classList.toggle('on', loopOn);
                loopBtn.setAttribute('aria-pressed', String(loopOn));
                const lbl = document.getElementById('loopLabel');
                if (lbl) lbl.textContent = loopOn ? 'Repeat' : 'Auto-next';
            };
            applyLoop();
            if (loopBtn) loopBtn.addEventListener('click', () => {
                loopOn = !loopOn;
                try { localStorage.setItem('vs-shorts-loop', loopOn ? '1' : '0'); } catch (e) {}
                applyLoop();
            });

            // ----- tap = pause / play, double-tap = like -----
            const tap = document.getElementById('tapLayer');
            const burst = document.getElementById('tapBurst');
            const flash = document.getElementById('tapFlash');
            const likeEl = document.getElementById('likeBtn');
            const playerBox = document.getElementById('playerWrap');
            const ICON_PAUSE = '<svg viewBox="0 0 24 24"><path d="M6 4h4v16H6zM14 4h4v16h-4z"/></svg>';
            const ICON_PLAY = '<svg viewBox="0 0 24 24"><path d="M7 4l13 8-13 8z"/></svg>';
            const replay = (el) => { el.classList.remove('go'); void el.offsetWidth; el.classList.add('go'); };
            let tapTimer = null, lastTap = 0, tapIgnore = false;
            // a tap that only turns the sound on (autoplay was blocked) should not also pause the short
            document.addEventListener('pointerdown', () => { tapIgnore = !pill.hidden; }, true);
            const togglePlay = () => {
                let willPause;
                if (vid) { willPause = !vid.paused; if (willPause) vid.pause(); else vid.play().catch(() => {}); }
                else { willPause = ytState === 1; ytCmd(willPause ? 'pauseVideo' : 'playVideo'); }
                if (flash) { flash.innerHTML = willPause ? ICON_PAUSE : ICON_PLAY; replay(flash); }
            };
            if (tap) tap.addEventListener('click', (e) => {
                if (tapIgnore) { tapIgnore = false; return; }
                const now = Date.now();
                if (tapTimer && now - lastTap < 320) {            // second tap: like (never un-like)
                    clearTimeout(tapTimer); tapTimer = null;
                    if (burst && playerBox) {
                        const r = playerBox.getBoundingClientRect();
                        burst.style.left = (e.clientX - r.left) + 'px';
                        burst.style.top = (e.clientY - r.top) + 'px';
                        replay(burst);
                    }
                    if (likeEl && !likeEl.disabled && likeEl.getAttribute('aria-pressed') !== 'true') likeEl.click();
                    return;
                }
                lastTap = now;
                tapTimer = setTimeout(() => { tapTimer = null; togglePlay(); }, 320);
            });

            // ----- thin progress bar -----
            const fill = document.getElementById('progressFill');
            if (fill) {
                const tick = () => {
                    let p = 0;
                    if (vid && vid.duration) p = vid.currentTime / vid.duration;
                    else if (ytDur) p = (ytCur + (ytState === 1 ? (Date.now() - ytStamp) / 1000 : 0)) / ytDur;
                    fill.style.width = (Math.max(0, Math.min(1, p)) * 100).toFixed(2) + '%';
                    requestAnimationFrame(tick);
                };
                requestAnimationFrame(tick);
            }

            // ----- keyboard shortcuts: Space pause/play, M mute, L like -----
            const toggleMute = () => {
                const muted = vid ? vid.muted : ytMuted;
                if (muted) { unmute(true); return; }
                savePref(true);
                if (vid) { markAuto(); vid.muted = true; }
                if (yt) { ytCmd('mute'); ytMuted = true; }
                hidePill();
            };
            document.addEventListener('keydown', (e) => {
                if (e.ctrlKey || e.metaKey || e.altKey) return;
                const t = e.target;
                if (t && t.closest && t.closest('input, textarea, select, button, a, [contenteditable=""], [contenteditable="true"]')) return;
                const modal = document.getElementById('commentsModal');
                if (modal && modal.classList.contains('open')) return;
                const k = e.key.toLowerCase();
                if (k === ' ' || k === 'spacebar') { e.preventDefault(); togglePlay(); }
                else if (k === 'm') toggleMute();
                else if (k === 'l' && likeEl && !likeEl.disabled) likeEl.click();
            });

            pill.addEventListener('click', () => unmute(true));
            ['pointerdown', 'keydown', 'touchend'].forEach((ev) => {
                document.addEventListener(ev, function once() {
                    document.removeEventListener(ev, once, true);
                    unmute();
                }, true);
            });
        })();
    </script>
</body>
</html>
<?php if (isset($conn)) { $conn->close(); } ?>