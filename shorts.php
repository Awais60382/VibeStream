<?php
session_start();
include "connection.php";
require_once __DIR__ . '/avatar.php';   
require_once __DIR__ . '/icons.php';    

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

$VIEW_COOLDOWN_SECONDS = 0;

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
        if ($n >= 1000) return round($n / 1000, 1) . 'K';
        return (string)$n;
    }
}

if (!function_exists('render_navbar')) {
    function render_navbar($active = 'shorts') {
        $isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
        $username = $isLoggedIn ? $_SESSION['username'] : '';
        $initial = $isLoggedIn ? strtoupper(substr($username, 0, 1)) : 'G';
        $navAvatar = $isLoggedIn ? avatar_url($username) : '';
        ?>
        <header class="app-navbar">
            <div class="nav-left">
                <button class="nav-toggle" id="navToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false">
                    <span></span>
                    <span></span>
                    <span></span>
                </button>
                <a class="brand" href="index.php">VibeStream</a>
            </div>

            <div class="nav-center">
                <a class="nav-link<?php echo $active === 'watch' ? ' active' : ''; ?>" href="watch.php">Watch</a>
                <a class="nav-link<?php echo $active === 'shorts' ? ' active' : ''; ?>" href="shorts.php">Shorts</a>
            </div>

            <div class="nav-right">
                <a href="upload.php" class="create-btn">+ Create</a>
                <?php if ($isLoggedIn): ?>
                    <div class="user-badge-pill">
                        <a class="user-link" href="profile.php" title="View your profile">
                            <div class="user-avatar-circle">
                                <?php if ($navAvatar !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($navAvatar); ?>" alt="">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($initial); ?>
                                <?php endif; ?>
                            </div>
                            <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
                        </a>
                        <a href="logout.php" class="logout-link">Logout</a>
                    </div>
                <?php else: ?>
                    <a href="login.php" class="sign-in-btn">Sign In</a>
                <?php endif; ?>
            </div>
        </header>
        <?php
    }
}

if (!function_exists('render_sidebar')) {
    function render_sidebar($active = 'shorts') {
        $items = [
            ['key' => 'home',   'label' => 'Home',   'href' => 'index.php', 'icon' => 'gauge'],
            ['key' => 'watch',  'label' => 'Watch',  'href' => 'watch.php', 'icon' => 'box'],
            ['key' => 'shorts', 'label' => 'Shorts', 'href' => 'shorts.php', 'icon' => 'box'],
            ['key' => 'upload', 'label' => 'Upload', 'href' => 'upload.php', 'icon' => 'plus-square'],
            ['key' => 'profile', 'label' => 'Profile', 'href' => 'profile.php', 'icon' => 'contact'],
        ];
        if (isset($_SESSION['AdminSession']) || (isset($_SESSION['role']) && strcasecmp($_SESSION['role'], 'Admin') === 0)) {
            $items[] = ['key' => 'adminpanel', 'label' => 'Admin Panel', 'href' => 'adminpanel/index.php', 'icon' => 'settings'];
        }
        ?>
        <aside class="app-sidebar" id="appSidebar">
            <div class="sidebar-heading">Browse</div>
            <nav class="sidebar-nav">
                <?php foreach ($items as $item): ?>
                    <a class="sidebar-link<?php echo $active === $item['key'] ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['href']); ?>">
                        <span class="sidebar-icon"><?php echo vs_icon($item['icon']); ?></span>
                        <span class="sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>
        <?php
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

        if ($action === 'like') {
            $liked = in_array($vid, $_SESSION['liked_videos'], true);
            if ($liked) {
                $_SESSION['liked_videos'] = array_values(array_diff($_SESSION['liked_videos'], [$vid]));
                $upd = $conn->prepare("UPDATE videos SET likes = GREATEST(COALESCE(likes,0) - 1, 0) WHERE id = ?");
            } else {
                $_SESSION['liked_videos'][] = $vid;
                $upd = $conn->prepare("UPDATE videos SET likes = COALESCE(likes,0) + 1 WHERE id = ?");
            }
            $upd->bind_param("s", $vid);
            $upd->execute();
            $upd->close();

            $stmt = $conn->prepare("SELECT COALESCE(likes,0) AS n FROM videos WHERE id = ?");
            $stmt->bind_param("s", $vid);
            $stmt->execute();
            $likes = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            echo json_encode(['success' => true, 'liked' => !$liked, 'likes' => $likes]);
            exit;
        }

        if ($action === 'subscribe') {
            $ch = $v['channel_name'] ?? ($v['username'] ?? 'Unknown');
            $key = 'ch:' . mb_strtolower(trim($ch));
            $sub = in_array($key, $_SESSION['subscribed_channels'], true);

            if ($sub) {
                $_SESSION['subscribed_channels'] = array_values(array_diff($_SESSION['subscribed_channels'], [$key]));
                $upd = $conn->prepare("UPDATE videos SET subscribers = GREATEST(COALESCE(subscribers,0) - 1, 0) WHERE id = ?");
            } else {
                $_SESSION['subscribed_channels'][] = $key;
                $upd = $conn->prepare("UPDATE videos SET subscribers = COALESCE(subscribers,0) + 1 WHERE id = ?");
            }
            $upd->bind_param("s", $vid);
            $upd->execute();
            $upd->close();

            $stmt = $conn->prepare("SELECT COALESCE(subscribers,0) AS n FROM videos WHERE id = ?");
            $stmt->bind_param("s", $vid);
            $stmt->execute();
            $subs = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            echo json_encode(['success' => true, 'subscribed' => !$sub, 'subscribers' => $subs]);
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
            $ins->close();

            $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM comments WHERE video_id = ?");
            $stmt->bind_param("s", $vid);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['n'];
            $stmt->close();

            echo json_encode(['success' => true, 'username' => $user, 'comment_text' => $text, 'comment_count' => $count]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
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
} else {
    $result = $conn->query("SELECT * FROM videos WHERE type = 'short' ORDER BY created_at ASC LIMIT 1");
    $video = $result ? $result->fetch_assoc() : null;
}

$comments = [];
$prev = $next = null;
$src = '';
$yt = null;
$title = 'No shorts yet';
$channel = 'VibeStream';
$likes = 0;
$subs = 0;
$views = 0;
$description = 'Upload a short to get started.';
$avatarUrl = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($channel);
$userLiked = false;
$userSubbed = false;
$noVideo = !$video;

if ($video) {
    $id = $video['id'];

    if (!isset($_SESSION['view_times']) || !is_array($_SESSION['view_times'])) {
        $_SESSION['view_times'] = [];
    }
    $now = time();
    $lastView = isset($_SESSION['view_times'][$id]) ? (int)$_SESSION['view_times'][$id] : 0;

    if ($lastView === 0 || ($now - $lastView) >= $VIEW_COOLDOWN_SECONDS) {
        $upd = $conn->prepare("UPDATE videos SET views = COALESCE(views,0) + 1 WHERE id = ?");
        if ($upd) {
            $upd->bind_param("s", $id);
            if ($upd->execute()) {
                $_SESSION['view_times'][$id] = $now;
                $video['views'] = (int)($video['views'] ?? 0) + 1;
            }
            $upd->close();
        }
    }

    $curCreated = $video['created_at'] ?? null;

    if ($curCreated !== null) {
        $stmt = $conn->prepare(
            "SELECT id FROM videos WHERE type='short' AND (created_at > ? OR (created_at = ? AND id > ?))
             ORDER BY created_at ASC, id ASC LIMIT 1"
        );
        $stmt->bind_param("sss", $curCreated, $curCreated, $id);
        $stmt->execute();
        $next = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT id FROM videos WHERE type='short' AND (created_at < ? OR (created_at = ? AND id < ?))
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $stmt->bind_param("sss", $curCreated, $curCreated, $id);
        $stmt->execute();
        $prev = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }

    if (!$next) {
        $res = $conn->query("SELECT id FROM videos WHERE type='short' ORDER BY created_at ASC, id ASC LIMIT 1");
        $next = $res ? $res->fetch_assoc() : null;
    }
    if (!$prev) {
        $res = $conn->query("SELECT id FROM videos WHERE type='short' ORDER BY created_at DESC, id DESC LIMIT 1");
        $prev = $res ? $res->fetch_assoc() : null;
    }
    if (!$next) $next = ['id' => $id];
    if (!$prev) $prev = ['id' => $id];

    $src = find_source($video, __DIR__);
    $yt = $src ? extract_youtube_id($src) : null;

    $title = $video['title'] ?? 'Untitled';
    $channel = $video['channel_name'] ?? ($video['username'] ?? 'Unknown');
    $likes = (int)($video['likes'] ?? 0);
    $subs = (int)($video['subscribers'] ?? 0);
    $views = (int)($video['views'] ?? 0);
    $description = trim((string)($video['description'] ?? ''));
    if ($description === '') $description = 'Enjoy this short on VibeStream.';

    $channelOwner = trim((string)($video['username'] ?? ''));
    if ($channelOwner === '') $channelOwner = $channel;
    $avatarUrl = avatar_url($channelOwner);
    if ($avatarUrl === '' && $channelOwner !== $channel) $avatarUrl = avatar_url($channel);
    if ($avatarUrl === '') $avatarUrl = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' . urlencode($channel);

    $stmt = $conn->prepare("SELECT username, comment_text FROM comments WHERE video_id = ? ORDER BY id DESC");
    if ($stmt) {
        $stmt->bind_param("s", $id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $comments[] = $r;
        $stmt->close();
    }

    $userLiked = in_array($id, $_SESSION['liked_videos'] ?? [], true);
    $userSubbed = in_array('ch:' . mb_strtolower(trim($channel)), $_SESSION['subscribed_channels'] ?? [], true);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - VibeStream Shorts</title>
    <link rel="stylesheet" href="style.css">
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

        .meta-row { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .avatar { width: 40px; height: 40px; border-radius: 50%; overflow: hidden; background: #2a2a2a; flex-shrink: 0; }
        .avatar img { width: 100%; height: 100%; object-fit: cover; }
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

        .comments-modal {
            position: fixed; inset: 0; display: none; align-items: center; justify-content: center;
            background: rgba(0, 0, 0, 0.7); padding: 20px; z-index: 1100;
        }
        .comments-modal.open { display: flex; }
        .comments-box {
            width: min(460px, 100%); max-height: min(78vh, 720px); background: #16161a; border: 1px solid #2a2a31;
            border-radius: 20px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }
        .comments-top {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 18px 20px; border-bottom: 1px solid #2a2a31;
        }
        .comments-top h3 { margin: 0; font-size: 18px; }
        .close-btn { background: none; border: none; color: #aaaaaa; font-size: 24px; cursor: pointer; line-height: 1; }
        .comment-list {
            flex: 1; overflow-y: auto; padding: 18px 20px; display: flex; flex-direction: column; gap: 12px;
        }
        .comment-item {
            background: #1e1e24; border-radius: 12px; padding: 12px 14px; font-size: 13px; line-height: 1.5; word-break: break-word;
        }
        .comment-item strong { display: inline-block; margin-right: 6px; color: #ffffff; }
        .empty-comments { color: #9a9aa2; text-align: center; padding: 18px 0; font-size: 14px; }
        .comment-send { display: flex; gap: 10px; padding: 16px 20px 20px; border-top: 1px solid #2a2a31; }
        .comment-send input {
            flex: 1; background: #0d0d0f; border: 1px solid #3d3d46; border-radius: 12px;
            padding: 10px 14px; color: #ffffff; outline: none;
        }
        .comment-send input:focus { border-color: #575764; }
        .comment-send button {
            background: var(--accent); border: none; color: #ffffff; padding: 10px 16px;
            border-radius: 12px; font-weight: 700; cursor: pointer;
        }
        .toast {
            position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--surface-2);
            color: #ffffff; padding: 10px 18px; border-radius: 10px; font-size: 14px; display: none; z-index: 1200;
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
            .app-navbar { padding: 0 12px; gap: 12px; }
            .brand { font-size: 20px; }
            .nav-center { display: none; }
            .create-btn { display: none; }
            .user-name, .logout-link { display: none; }
            .user-badge-pill { padding-right: 6px; }
        }
        @media (max-width: 640px) {
            .player-card { border-radius: 20px; }
            .icon-btn .circle, .nav-btn .circle, .stat-item .circle { width: 46px; height: 46px; }
            .comments-box { max-height: 86vh; }
            .comment-send { flex-direction: column; }
        }
    </style>
</head>
<body>
    <?php render_navbar('shorts'); ?>
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
                                    <iframe
                                        src="https://www.youtube.com/embed/<?= htmlspecialchars($yt) ?>?autoplay=1&amp;mute=1&amp;loop=1&amp;playlist=<?= htmlspecialchars($yt) ?>&amp;playsinline=1&amp;rel=0"
                                        allow="autoplay; encrypted-media; picture-in-picture"
                                        allowfullscreen></iframe>
                                <?php elseif ($src): ?>
                                    <video src="<?= htmlspecialchars($src) ?>" autoplay muted loop playsinline controls></video>
                                <?php else: ?>
                                    <div class="msg"><?= $noVideo ? 'No shorts available yet. Upload one to get started.' : 'This short has no video saved.' ?></div>
                                <?php endif; ?>

                                <div class="player-gradient"></div>

                                <div class="short-overlay">
                                    <div class="meta-row">
                                        <div class="avatar">
                                            <img src="<?= htmlspecialchars($avatarUrl) ?>" alt="Channel avatar">
                                        </div>
                                        <div class="channel-stack">
                                            <p class="channel-name">@<?= htmlspecialchars($channel) ?></p>
                                            <p class="channel-meta" id="subCount"><?= short_num($subs) ?> subscriber<?= $subs == 1 ? '' : 's' ?></p>
                                        </div>
                                        <button type="button" id="subBtn" class="subscribe-btn<?= $userSubbed ? ' on' : '' ?>"<?= $noVideo ? ' disabled' : '' ?>><?= $userSubbed ? 'Subscribed' : 'Subscribe' ?></button>
                                    </div>
                                    <h1 class="short-title"><?= htmlspecialchars($title) ?></h1>
                                    <p class="short-description"><?= htmlspecialchars($description) ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- right of the short: like / comments / views -->
                    <div class="actions-column">
                        <button class="icon-btn" type="button" id="likeBtn"<?= $noVideo ? ' disabled' : '' ?>>
                            <span class="circle" id="likeIcon"><?= $userLiked ? '❤️' : '🤍' ?></span>
                            <span id="likeCount"><?= short_num($likes) ?></span>
                        </button>
                        <button class="icon-btn" type="button" id="commentBtn">
                            <span class="circle">💬</span>
                            <span id="commentCount"><?= short_num(count($comments)) ?></span>
                        </button>
                        <div class="stat-item" title="Views">
                            <span class="circle">👁</span>
                            <span id="viewCount"><?= short_num($views) ?></span>
                        </div>
                    </div>

                    <!-- far right: previous / next -->
                    <div class="nav-column">
                        <a href="<?= !$noVideo && !empty($prev['id']) ? 'shorts.php?id=' . urlencode($prev['id']) : '#' ?>" class="nav-btn<?= $noVideo ? ' disabled' : '' ?>" id="prevBtn" title="Previous short">
                            <span class="circle">↑</span>
                        </a>
                        <a href="<?= !$noVideo && !empty($next['id']) ? 'shorts.php?id=' . urlencode($next['id']) : '#' ?>" class="nav-btn<?= $noVideo ? ' disabled' : '' ?>" id="nextBtn" title="Next short">
                            <span class="circle">↓</span>
                        </a>
                    </div>

                </div>
            </div>
        </main>
    </div>

    <div class="comments-modal" id="modal">
        <div class="comments-box">
            <div class="comments-top">
                <h3>Comments</h3>
                <button type="button" class="close-btn" id="closeBtn">&times;</button>
            </div>
            <div id="list" class="comment-list">
                <?php if ($comments): ?>
                    <?php foreach ($comments as $c): ?>
                        <div class="comment-item"><strong><?= htmlspecialchars($c['username']) ?>:</strong> <?= htmlspecialchars($c['comment_text']) ?></div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-comments" id="emptyComments">No comments yet. Be the first one.</div>
                <?php endif; ?>
            </div>
            <div class="comment-send">
                <input type="text" id="commentInput" placeholder="Add a comment..." maxlength="500"<?= $noVideo ? ' disabled' : '' ?>>
                <button type="button" id="postBtn"<?= $noVideo ? ' disabled' : '' ?>>Post</button>
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

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
            if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
            if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
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
            const postBtn = $('postBtn');
            const commentInput = $('commentInput');
            const player = $('playerWrap');
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
                    const d = await send('like');
                    if (!d.success) return toast(d.error || 'Like failed');
                    $('likeIcon').textContent = d.liked ? '❤️' : '🤍';
                    $('likeCount').textContent = shortNum(d.likes);
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

            async function postComment() {
                const text = commentInput.value.trim();
                if (!text) return;
                const d = await send('comment', { comment_text: text });
                if (!d.success) return toast(d.error || 'Comment failed');

                const empty = $('emptyComments');
                if (empty) empty.remove();

                const item = document.createElement('div');
                item.className = 'comment-item';
                const name = document.createElement('strong');
                name.textContent = d.username + ':';
                item.append(name, ' ' + d.comment_text);
                $('list').prepend(item);

                commentInput.value = '';
                $('commentCount').textContent = shortNum(d.comment_count);
            }

            if (postBtn && commentInput && VIDEO_ID) {
                postBtn.addEventListener('click', postComment);
                commentInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') postComment(); });
            }

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
        });
    </script>
</body>
</html>
<?php if (isset($conn)) { $conn->close(); } ?>