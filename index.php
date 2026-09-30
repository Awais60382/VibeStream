<?php
session_start();
include "connection.php";
require_once __DIR__ . '/icons.php';

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

if (!function_exists('render_navbar')) {
    function render_navbar($active = 'home') {
        $isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
        $username = $isLoggedIn ? $_SESSION['username'] : '';
        $searchVal = htmlspecialchars($_GET['q'] ?? '');
        $initial = $isLoggedIn ? strtoupper(substr($username, 0, 1)) : 'G';
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
                <form action="index.php" method="get" class="search-form">
                    <input type="text" name="q" id="search-input" placeholder="Search videos" value="<?php echo $searchVal; ?>">
                </form>
            </div>

            <div class="nav-right">
                <a href="upload.php" class="create-btn">+ Create</a>
                <?php if ($isLoggedIn): ?>
                    <div class="user-badge user-badge-pill">
                        <div class="user-avatar-circle"><?php echo $initial; ?></div>
                        <span class="user-name"><?php echo htmlspecialchars($username); ?></span>
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
    function render_sidebar($active = 'home') {
        $icons = [
            'home' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg>',
            'watch' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M10 8.5v7l6-3.5-6-3.5z" fill="currentColor" stroke="none"/></svg>',
            'shorts' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16"/><path d="m5 4 3 4M11 4l3 4M17 4l2 4"/><rect x="3" y="8" width="18" height="12" rx="2"/></svg>',
            'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17V4"/><path d="m6 9 6-6 6 6"/><path d="M4 17v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>',
            'profile' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v1"/></svg>',
        ];
        $items = [
            ['key' => 'home',   'label' => 'Home',   'href' => 'index.php'],
            ['key' => 'watch',  'label' => 'Watch',  'href' => 'watch.php'],
            ['key' => 'shorts', 'label' => 'Shorts', 'href' => 'shorts.php'],
            ['key' => 'upload', 'label' => 'Upload', 'href' => 'upload.php'],
            ['key' => 'profile', 'label' => 'Profile', 'href' => 'profile.php'],
        ];
        if (isset($_SESSION['AdminSession']) || (isset($_SESSION['role']) && strcasecmp($_SESSION['role'], 'Admin') === 0)) {
            $items[] = ['key' => 'adminpanel', 'label' => 'Admin Panel', 'href' => 'adminpanel/index.php'];
            $icons['adminpanel'] = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>';
        }
        ?>
        <aside class="app-sidebar" id="appSidebar">
            <div class="sidebar-top">
                <div class="sidebar-heading">Browse</div>
                <button type="button" class="sidebar-close" id="sidebarClose" data-sidebar-close aria-label="Close menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>
            <nav class="sidebar-nav">
                <?php foreach ($items as $item): ?>
                    <a class="sidebar-link<?php echo $active === $item['key'] ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($item['href']); ?>">
                        <span class="sidebar-icon"><?php echo $icons[$item['key']]; ?></span>
                        <span class="sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-divider"></div>

            <nav class="sidebar-nav secondary">
                <a class="sidebar-link" href="#videos-grid">
                    <span class="sidebar-icon"></span>
                    <span class="sidebar-label">All Videos</span>
                </a>
                <a class="sidebar-link" href="#shorts-shelf">
                    <span class="sidebar-icon"></span>
                    <span class="sidebar-label">Shorts Shelf</span>
                </a>
            </nav>
        </aside>
        <?php
    }
}

$hasTypeColumn = column_exists($conn, 'videos', 'type');
$search = trim($_GET['q'] ?? '');

$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = $hasTypeColumn ? "(type IS NULL OR type <> 'short')" : "1=1";
$params = [];
$types = '';

if ($search !== '') {
    $where .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like];
    $types = 'sss';
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM videos WHERE $where");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalVideos = (int)$countStmt->get_result()->fetch_assoc()['n'];
$countStmt->close();
$totalPages = max(1, (int)ceil($totalVideos / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "SELECT * FROM videos WHERE $where ORDER BY created_at DESC LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$bindTypes = $types . 'ii';
$bindParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$shorts = [];
if ($hasTypeColumn) {
    $sSql = "SELECT * FROM videos WHERE type = 'short'";
    if ($search !== '') $sSql .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
    $sSql .= " ORDER BY created_at DESC LIMIT 12";
    $sStmt = $conn->prepare($sSql);
    if ($search !== '') {
        $like = '%' . $search . '%';
        $sStmt->bind_param('sss', $like, $like, $like);
    }
    $sStmt->execute();
    $shorts = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();
}

$categories = ['all'];
if (column_exists($conn, 'videos', 'category')) {
    $catRes = $conn->query("SELECT DISTINCT LOWER(TRIM(category)) AS c FROM videos WHERE category IS NOT NULL AND category <> ''");
    while ($catRes && ($row = $catRes->fetch_assoc())) {
        if ($row['c'] !== '') $categories[] = $row['c'];
    }
}
$categories = array_values(array_unique($categories));

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $search !== '' ? 'Search: ' . htmlspecialchars($search) . ' - ' : ''; ?>VibeStream</title>
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --bg: #0f0f0f;
            --surface: #181818;
            --surface-2: #272727;
            --surface-3: #1f1f1f;
            --surface-4: #202024;
            --text: #f1f1f1;
            --muted: #aaaaaa;
            --border: #303030;
            --accent: #b02a2a;
            --accent-hover: #1f1f1f;
            --accent-soft: rgba(176, 42, 42, 0.18);
            --blue: #3ea6ff;
            --navbar-height: 72px;
            --sidebar-width: 240px;
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            background: var(--bg);
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
            grid-template-columns: auto minmax(280px, 560px) auto;
            align-items: center;
            gap: 18px;
            padding: 0 20px;
            background: rgba(15, 15, 15, 0.96);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            z-index: 1000;
        }

        .nav-left,
        .nav-right {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .nav-center {
            display: flex;
            justify-content: center;
        }

        .brand {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.2px;
            white-space: nowrap;
        }

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

        .search-form {
            width: 100%;
            max-width: 560px;
        }

        #search-input {
            width: 100%;
            height: 46px;
            background: #121212;
            border: 1px solid #2e2e2e;
            color: var(--text);
            border-radius: 999px;
            padding: 0 18px;
            outline: none;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        #search-input:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(62, 166, 255, 0.14);
        }

        .create-btn,
        .sign-in-btn {
            background: var(--accent);
            color: #ffffff;
            padding: 10px 16px;
            border-radius: 999px;
            font-size: 14px;
            font-weight: 700;
            white-space: nowrap;
        }

        .create-btn:hover,
        .sign-in-btn:hover {
            filter: brightness(1.05);
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
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
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

        .logout-link:hover {
            color: #ffffff;
        }

        .app-layout {
            min-height: 100vh;
            padding-top: var(--navbar-height);
        }

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
            padding: 12px 14px;
            border-radius: 14px;
            color: var(--text);
            font-size: 15px;
            font-weight: 500;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .sidebar-link:hover { background: var(--accent-hover); }

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

        .sidebar-divider {
            height: 1px;
            background: var(--border);
            margin: 18px 10px;
        }

        .content-wrap {
            max-width: 1500px;
            margin: 0 auto;
            padding: 22px 24px 34px;
        }

        .hero-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
            padding: 18px 20px;
            border-radius: 20px;
            background: linear-gradient(135deg, #19191c 0%, #111111 60%, #191216 100%);
            border: 1px solid rgba(255,255,255,0.05);
        }

        .hero-text h2 {
            margin: 0 0 6px;
            font-size: 22px;
        }

        .hero-text p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            border-radius: 999px;
            background: rgba(176, 42, 42, 0.14);
            color: #ffb7b7;
            border: 1px solid rgba(176, 42, 42, 0.28);
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .chips-bar {
            display: flex;
            gap: 10px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 22px;
            scrollbar-width: none;
        }

        .chips-bar::-webkit-scrollbar { display: none; }

        .chip {
            border: 1px solid #353535;
            background: var(--surface-2);
            color: var(--text);
            padding: 9px 14px;
            border-radius: 999px;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }

        .chip:hover { background: #323232; }

        .chip.active {
            background: var(--accent);
            border-color: rgba(176, 42, 42, 0.55);
            color: #ffffff;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }

        .section-head h2 {
            margin: 0;
            font-size: 20px;
        }

        .section-head p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .video-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 22px 18px;
        }

        .video-card {
            min-width: 0;
        }

        .video-card-link {
            display: block;
            color: inherit;
        }

        .thumbnail {
            position: relative;
            aspect-ratio: 16 / 9;
            border-radius: 16px;
            overflow: hidden;
            background: var(--surface-2);
            box-shadow: 0 10px 24px rgba(0,0,0,0.18);
        }

        .thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.22s ease;
        }

        .video-card-link:hover .thumbnail img,
        .short-card-link:hover .short-card-thumb img {
            transform: scale(1.03);
        }

        .duration-badge {
            position: absolute;
            right: 10px;
            bottom: 10px;
            background: rgba(0,0,0,0.8);
            color: #ffffff;
            border-radius: 8px;
            padding: 4px 7px;
            font-size: 12px;
            font-weight: 600;
        }

        .video-details-flex {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }

        .channel-avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2c74d8, #45b2ff);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #ffffff;
            font-weight: 700;
        }

        .avatar-initial {
            font-size: 15px;
            line-height: 1;
        }

        .video-info {
            min-width: 0;
        }

        .video-info h3 {
            margin: 0 0 6px;
            font-size: 16px;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .video-card-link:hover .video-info h3 {
            color: var(--blue);
        }

        .channel-name,
        .video-meta {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.45;
        }

        .pagination {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 8px;
            margin: 28px 0 6px;
        }

        .pagination a,
        .pagination span {
            min-width: 42px;
            text-align: center;
            padding: 10px 14px;
            border-radius: 12px;
            background: var(--surface-2);
            color: var(--text);
            text-decoration: none;
            font-size: 14px;
        }

        .pagination .current {
            background: var(--blue);
            color: #0f0f0f;
            font-weight: 700;
        }

        .shorts-section {
            margin-top: 34px;
        }

        .shorts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 18px;
        }

        .short-card-link {
            display: block;
            color: inherit;
        }

        .short-card-thumb {
            position: relative;
            aspect-ratio: 9 / 16;
            border-radius: 20px;
            overflow: hidden;
            background: var(--surface-2);
        }

        .short-card-thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform 0.22s ease;
        }

        .short-card-info {
            padding: 12px 4px 0;
        }

        .short-card-title {
            font-size: 15px;
            font-weight: 600;
            line-height: 1.4;
            margin-bottom: 6px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .short-card-views {
            color: var(--muted);
            font-size: 13px;
        }

        .empty-state {
            grid-column: 1 / -1;
            text-align: center;
            color: var(--muted);
            padding: 70px 14px;
            font-size: 15px;
            background: rgba(255,255,255,0.02);
            border: 1px dashed rgba(255,255,255,0.08);
            border-radius: 18px;
        }

        @media (max-width: 1250px) {
            .app-navbar {
                grid-template-columns: auto minmax(220px, 1fr) auto;
            }
        }

        @media (max-width: 1000px) {
            .app-navbar {
                grid-template-columns: auto 1fr auto;
                gap: 12px;
            }

            .content-wrap { padding: 18px 16px 28px; }
            .nav-center { min-width: 0; }
            .hero-strip {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 760px) {
            .app-navbar {
                grid-template-columns: auto 1fr;
                padding: 0 12px;
            }

            .brand { font-size: 20px; }

            .nav-right {
                justify-content: flex-end;
            }

            .create-btn {
                display: none;
            }

            .user-name,
            .logout-link {
                display: none;
            }

            .user-badge-pill {
                padding-right: 6px;
            }

            .hero-text h2 {
                font-size: 20px;
            }

            .video-grid {
                grid-template-columns: 1fr;
            }

            .shorts-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 540px) {
            .app-navbar {
                height: 68px;
            }

            :root {
                --navbar-height: 68px;
            }

            .shorts-grid {
                grid-template-columns: 1fr 1fr;
                gap: 14px;
            }

            .hero-badge {
                white-space: normal;
            }
        }
    </style>
    <!-- Must stay AFTER the <style> block above -->
    <link rel="stylesheet" href="sidebar.css">
</head>
<body>
    <?php render_navbar('home'); ?>

    <div class="admin-shell">
        <div class="sidebar-backdrop" data-sidebar-close></div>

        <div class="app-layout">
            <?php render_sidebar('home'); ?>

            <main class="app-main">
                <div class="content-wrap">
                    <section class="hero-strip">
                        <div class="hero-text">
                            <h2><?php echo $search !== '' ? 'Search results for "' . htmlspecialchars($search) . '"' : 'Welcome back to VibeStream'; ?></h2>
                            <p><?php echo $search !== '' ? 'Browse matching videos and shorts with the updated VibeStream layout.' : 'Fresh videos, quick filters, and a cleaner homepage that matches your watch and shorts pages.'; ?></p>
                        </div>
                        <div class="hero-badge"><?php echo number_format($totalVideos); ?> video<?php echo $totalVideos == 1 ? '' : 's'; ?> available</div>
                    </section>

                    <nav class="chips-bar" aria-label="Video categories">
                        <?php foreach ($categories as $cat): ?>
                            <button class="chip<?php echo $cat === 'all' ? ' active' : ''; ?>" data-category="<?php echo htmlspecialchars($cat); ?>">
                                <?php echo htmlspecialchars(ucfirst($cat)); ?>
                            </button>
                        <?php endforeach; ?>
                    </nav>

                    <section id="videos-grid">
                        <div class="section-head">
                            <div>
                                <h1>Videos</h1>
                                <p><?php echo $search !== '' ? 'Filtered by your search query.' : 'Latest uploads from your library.'; ?></p>
                            </div>
                        </div>

                        <section class="video-grid">
                            <?php if (!$videos): ?>
                                <div class="empty-state">
                                    <?php echo $search !== ''
                                        ? 'No videos match "' . htmlspecialchars($search) . '".'
                                        : 'No videos yet — be the first to upload one!'; ?>
                                </div>
                            <?php endif; ?>

                            <?php foreach ($videos as $row):
                                $title = htmlspecialchars($row['title'] ?? 'Untitled');
                                $username = htmlspecialchars($row['username'] ?? $row['channel_name'] ?? 'Anonymous');
                                $views = number_format($row['views'] ?? 0);
                                $category = strtolower(trim($row['category'] ?? 'all'));
                                $duration = $row['duration'] ?? null;
                                $uploaded = time_ago($row['created_at'] ?? null);

                                $src = find_source($row, __DIR__);
                                $realYt = $src !== '' ? extract_youtube_id($src, true) : null;
                                $thumb = thumb_for($row, $src, $realYt);
                                $watchHref = "watch.php?id=" . urlencode($row['id']);
                            ?>
                                <div class="video-card" data-category="<?php echo htmlspecialchars($category); ?>">
                                    <a href="<?php echo htmlspecialchars($watchHref); ?>" class="video-card-link">
                                        <div class="thumbnail">
                                            <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo $title; ?>" loading="lazy">
                                            <?php if (!empty($duration)): ?>
                                                <span class="duration-badge"><?php echo htmlspecialchars($duration); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="video-details-flex">
                                            <div class="channel-avatar">
                                                <span class="avatar-initial"><?php echo strtoupper(substr($username, 0, 1)); ?></span>
                                            </div>
                                            <div class="video-info">
                                                <h3><?php echo $title; ?></h3>
                                                <p class="channel-name"><?php echo $username; ?></p>
                                                <p class="video-meta"><?php echo $views; ?> views • <?php echo $uploaded; ?></p>
                                            </div>
                                        </div>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </section>
                    </section>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php for ($p = 1; $p <= $totalPages; $p++):
                                $qs = http_build_query(array_merge($_GET, ['page' => $p]));
                            ?>
                                <?php if ($p === $page): ?>
                                    <span class="current"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a href="?<?php echo $qs; ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($shorts): ?>
                        <section class="shorts-section" id="shorts-shelf">
                            <div class="section-head">
                                <div>
                                    <h1>Shorts</h1>
                                    <p>Quick vertical content in the same updated theme.</p>
                                </div>
                            </div>

                            <div class="shorts-grid">
                                <?php foreach ($shorts as $row):
                                    $title = htmlspecialchars($row['title'] ?? 'Untitled');
                                    $views = number_format($row['views'] ?? 0);
                                    $src = find_source($row, __DIR__);
                                    $ytId = $src !== '' ? extract_youtube_id($src, true) : null;
                                    $thumb = thumb_for($row, $src, $ytId);
                                ?>
                                    <div class="short-card">
                                        <a href="shorts.php?id=<?php echo urlencode($row['id']); ?>" class="short-card-link">
                                            <div class="short-card-thumb">
                                                <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo $title; ?>" loading="lazy">
                                            </div>
                                            <div class="short-card-info">
                                                <div class="short-card-title"><?php echo $title; ?></div>
                                                <div class="short-card-views"><?php echo $views; ?> views</div>
                                            </div>
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <script src="sidebar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const chips = document.querySelectorAll('.chip');
            const videoCards = document.querySelectorAll('.video-card');

            chips.forEach(chip => {
                chip.addEventListener('click', () => {
                    chips.forEach(c => c.classList.remove('active'));
                    chip.classList.add('active');

                    const category = chip.getAttribute('data-category');

                    videoCards.forEach(card => {
                        const cardCategory = card.getAttribute('data-category');
                        card.style.display = (category === 'all' || cardCategory === category) ? '' : 'none';
                    });
                });
            });
        });
    </script>
</body>
</html>