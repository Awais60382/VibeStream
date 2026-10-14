<?php
session_start();
include "connection.php";
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/sidebar-navigation.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
vs_maintenance_gate();

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

if (!function_exists('vs_short_views')) {
    // 448K, 1.2M, 11M ... like YouTube
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
    // 3 days ago, 2 weeks ago, 1 year ago ...
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
        $siteName = vs_branding_app_name();
        $isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
        $username = $isLoggedIn ? $_SESSION['username'] : '';
        $searchVal = htmlspecialchars($_GET['q'] ?? '');
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
                <form action="index.php" method="get" class="search-form">
                    <input type="text" name="q" id="search-input" placeholder="Search videos" value="<?php echo $searchVal; ?>">
                </form>
            </div>

            <div class="nav-right">
                <button type="button" class="theme-toggle" id="themeToggle" data-vs-theme-toggle aria-label="Switch to light mode" title="Toggle light / dark theme">
                    <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>
                    <svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
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

$hasTypeColumn = column_exists($conn, 'videos', 'type');
$hasCategoryColumn = column_exists($conn, 'videos', 'category');
$selectedCategory = strtolower(trim((string)($_GET['category'] ?? '')));
if (!preg_match('/^[a-z0-9 _-]{1,40}$/', $selectedCategory)) {
    $selectedCategory = '';
}
$search = trim($_GET['q'] ?? '');
if (!$hasCategoryColumn) {
    if ($selectedCategory !== '' && $search === '') {
        $search = $selectedCategory;
    }
    $selectedCategory = '';
}

$perPage = 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = $hasTypeColumn ? "(type IS NULL OR type <> 'short')" : "1=1";
$params = [];
$types = '';

if ($selectedCategory !== '' && $hasCategoryColumn) {
    $where .= " AND LOWER(TRIM(category)) = ?";
    $params[] = $selectedCategory;
    $types .= 's';
}

if ($search !== '') {
    $where .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
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

// Shuffle: a new random order on every refresh of page 1; pages 2+ keep the same order while you page.
// (Searches stay newest-first.)
if ($page <= 1 || empty($_SESSION['feed_seed'])) {
    $_SESSION['feed_seed'] = random_int(1, 2147483646);
}
$feedSeed = (int)$_SESSION['feed_seed'];
$videoOrder = $search === '' ? "RAND($feedSeed)" : 'created_at DESC';
$sql = "SELECT * FROM videos WHERE $where ORDER BY $videoOrder LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$bindTypes = $types . 'ii';
$bindParams = array_merge($params, [$perPage, $offset]);
$stmt->bind_param($bindTypes, ...$bindParams);
$stmt->execute();
$videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$shortsPerPage = 48;   // shorts loaded per page (2 shorts after every video, so 2 x the videos per page)
$shorts = [];
if ($hasTypeColumn) {
    $shortOrder = $search === '' ? "RAND($feedSeed)" : 'created_at DESC';
    $sSql = "SELECT * FROM videos WHERE type = 'short'";
    $sParams = [];
    $sTypes = '';
    if ($selectedCategory !== '' && $hasCategoryColumn) {
        $sSql .= " AND LOWER(TRIM(category)) = ?";
        $sParams[] = $selectedCategory;
        $sTypes .= 's';
    }
    if ($search !== '') {
        $sSql .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
        $like = '%' . $search . '%';
        array_push($sParams, $like, $like, $like);
        $sTypes .= 'sss';
    }
    $sSql .= " ORDER BY $shortOrder LIMIT " . (int)$shortsPerPage . " OFFSET " . (int)(($page - 1) * $shortsPerPage);
    $sStmt = $conn->prepare($sSql);
    if ($sTypes !== '') {
        $sStmt->bind_param($sTypes, ...$sParams);
    }
    $sStmt->execute();
    $shorts = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();
}

$categories = ['all'];
if ($hasCategoryColumn) {
    $catRes = $conn->query("SELECT DISTINCT LOWER(TRIM(category)) AS c FROM videos WHERE category IS NOT NULL AND category <> ''");
    while ($catRes && ($row = $catRes->fetch_assoc())) {
        if ($row['c'] !== '') $categories[] = $row['c'];
    }
}
$configuredCategories = [];
try {
    $configuredCategories = (array)vs_default_categories();
} catch (Throwable $categoryError) {
    $configuredCategories = [];
}
if (!$configuredCategories) {
    // same built-in list the sidebar falls back to
    $configuredCategories = ['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education', 'Anime'];
}
foreach ($configuredCategories as $defaultCategory) {
    $categories[] = strtolower(trim((string)$defaultCategory));
}
$categories = array_values(array_unique($categories));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?php echo $defaultTheme === 'dark' ? '#b02a2a' : '#ffffff'; ?>">
    <meta name="vs-default-theme" content="<?php echo htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="description" content="<?php echo htmlspecialchars(vs_branding_app_description(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="icon" type="image/png" sizes="96x96" href="branding-icon.php?size=96">
    <link rel="shortcut icon" type="image/png" href="branding-icon.php?size=96">
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="branding-icon.php">
    <title><?php echo $search !== '' ? 'Search: ' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . ' - ' : ''; ?><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
    require_once __DIR__ . '/sidebar-icons-store.php';
    $vsSiteIcons = [];
    foreach (vs_si_load()['site'] as $k => $entry) {
        $vsSiteIcons[$k] = [
            'url'  => $entry['file'] . '?v=' . (int)@filemtime(__DIR__ . '/' . $entry['file']),
            'mode' => $entry['mode'],
        ];
    }
    ?>
    <script>window.VS_SIDEBAR_ICONS = <?php echo json_encode($vsSiteIcons, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="sidebar-icons.js?v=1" defer></script>
    <script src="theme.js?v=3"></script>
    <link rel="stylesheet" href="style.css?v=5">
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
            display: inline-flex;
            align-items: center;
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

        /* ---------- Theme toggle ---------- */
        .theme-toggle {
            width: 44px;
            height: 44px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text);
            border-radius: 50%;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s ease, transform 0.2s ease;
        }
        .theme-toggle:hover { background: var(--surface-3); }
        .theme-toggle:active { transform: scale(0.92); }
        .theme-toggle svg { width: 22px; height: 22px; }
        .theme-toggle .icon-moon { display: none; }
        :root[data-theme="light"] .theme-toggle .icon-sun { display: none; }
        :root[data-theme="light"] .theme-toggle .icon-moon { display: block; }

        /* ---------- Light theme ---------- */
        :root[data-theme="light"] {
            color-scheme: light;
            --bg: #ffffff;
            --surface: #f9f9f9;
            --surface-2: #f0f0f0;
            --surface-3: #e9e9e9;
            --surface-4: #f2f2f2;
            --text: #0f0f0f;
            --muted: #606060;
            --border: #e0e0e0;
            --accent-hover: #ececec;
            --accent-soft: rgba(176, 42, 42, 0.10);
            --blue: #065fd4;
        }
        :root[data-theme="dark"] { color-scheme: dark; }
        :root[data-theme="light"] .app-navbar { background: rgba(255, 255, 255, 0.96); }
        :root[data-theme="light"] #search-input { background: #ffffff; border-color: #ccc; }
        :root[data-theme="light"] #search-input:focus { box-shadow: 0 0 0 3px rgba(6, 95, 212, 0.14); }
        :root[data-theme="light"] .app-sidebar { background: #ffffff; }
        :root[data-theme="light"] .hero-strip {
            background: linear-gradient(135deg, #f7f7f9 0%, #ffffff 60%, #fbf1f1 100%);
            border-color: rgba(0, 0, 0, 0.08);
        }
        :root[data-theme="light"] .hero-badge {
            background: rgba(176, 42, 42, 0.08);
            color: #9a2222;
            border-color: rgba(176, 42, 42, 0.25);
        }
        :root[data-theme="light"] .user-badge-pill {
            background: linear-gradient(180deg, #ffffff 0%, #f3f3f3 100%);
            border-color: #d6d6d6;
            box-shadow: none;
        }
        :root[data-theme="light"] .user-name { color: #0f0f0f; }
        :root[data-theme="light"] .logout-link { color: #606060; border-left-color: #d0d0d0; }
        :root[data-theme="light"] .logout-link:hover { color: #0f0f0f; }
        :root[data-theme="light"] .pagination .current { color: #ffffff; }
        :root[data-theme="light"] .empty-state {
            background: rgba(0, 0, 0, 0.02);
            border-color: rgba(0, 0, 0, 0.12);
        }

        /* ---------- Mixed feed: videos / shorts rows ---------- */
        .feed-block { margin-bottom: 8px; }
        .feed-shorts { margin: 10px 0 30px; }
        .feed-shorts-title {
            display: flex; align-items: center; gap: 10px; margin: 0 0 14px;
            font-size: 20px; font-weight: 700; color: var(--text);
        }
        .feed-shorts-title svg { width: 24px; height: 24px; color: var(--accent); }
        .feed-shorts-title .shorts-title-icon { display: block; flex: 0 0 auto; width: 21px; height: 26px; background-color: currentColor; -webkit-mask: url("data:image/png;base64,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") center / contain no-repeat; mask: url("data:image/png;base64,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") center / contain no-repeat; }
        .feed-shorts .shorts-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
        @media (max-width: 1100px) {
            .feed-shorts .shorts-grid {
                grid-template-columns: none; grid-auto-flow: column; grid-auto-columns: minmax(150px, 38%);
                overflow-x: auto; scroll-snap-type: x proximity; padding-bottom: 6px;
            }
            .feed-shorts .short-card { scroll-snap-align: start; }
        }

        /* ---------- YouTube-style home page ---------- */
        .content-wrap { max-width: none; padding: 0 24px 40px; }
        .search-title { margin: 18px 0 0; font-size: 18px; font-weight: 500; }

        .chips-bar {
            position: sticky; top: var(--navbar-height); z-index: 40;
            gap: 12px; margin: 0 -24px 20px; padding: 12px 24px;
            background: var(--bg);
        }
        .chip {
            height: 32px; padding: 0 12px; border: 0; border-radius: 8px;
            background: rgba(255, 255, 255, 0.10); color: var(--text);
            font-size: 14px; font-weight: 500;
        }
        .chip:hover { background: rgba(255, 255, 255, 0.20); }
        .chip.active { background: #f1f1f1; color: #0f0f0f; border: 0; }
        :root[data-theme="light"] .chips-bar { background: var(--surface-base, #ffffff); }
        :root[data-theme="light"] .chip { background: rgba(0, 0, 0, 0.05); }
        :root[data-theme="light"] .chip:hover { background: rgba(0, 0, 0, 0.10); }
        :root[data-theme="light"] .chip.active { background: #0f0f0f; color: #ffffff; }

        .chips-next {
            position: sticky; right: 0; margin-left: auto; flex: 0 0 32px; width: 32px; height: 32px;
            display: none; align-items: center; justify-content: center; border: 0; border-radius: 50%;
            background: var(--bg); color: var(--text); font-size: 22px; line-height: 1; cursor: pointer;
            box-shadow: -14px 0 12px var(--bg);
        }
        .chips-next:hover { background: rgba(128, 128, 128, 0.25); }
        .chip { text-decoration: none; }

        /* 4 across on wide screens, then 3 / 2 / 1 */
        .video-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 40px 16px; }
        /* desktop: if a row has fewer than 4 videos, stretch them so there is no empty spot */
        @media (min-width: 768px) { .feed-desktop .video-grid.feed-block { grid-template-columns: repeat(var(--cols, 4), minmax(0, 1fr)); } }
        @media (max-width: 1099px) { .video-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 899px) { .video-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 639px)  { .video-grid { grid-template-columns: 1fr; gap: 26px; } }

        /* cards: no box, just thumbnail + text (theme.css gives cards a background, so these are stronger) */
        html[data-theme] body .video-card,
        html[data-theme] body .short-card {
            background: transparent; border: 0; box-shadow: none; border-radius: 0; padding: 0;
        }
        .thumbnail { border-radius: 12px; box-shadow: none; }
        .video-card-link:hover .thumbnail img,
        .short-card-link:hover .short-card-thumb img { transform: none; }
        .duration-badge { right: 8px; bottom: 8px; padding: 2px 5px; border-radius: 4px; font-size: 12px; font-weight: 500; }

        .video-details-flex { gap: 12px; margin-top: 12px; }
        .channel-avatar { width: 36px; height: 36px; }
        .video-info h3 { margin: 0 0 4px; font-size: 15px; font-weight: 500; line-height: 21px; }
        .video-card-link:hover .video-info h3 { color: var(--text); }
        .channel-name, .video-meta { font-size: 13px; line-height: 19px; }

        /* Shorts shelf: one row of 6 tall cards */
        .feed-shorts { margin: 8px 0 40px; }
        .feed-shorts-title { margin: 0 0 12px; font-size: 20px; font-weight: 700; }
        .feed-shorts .shorts-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 16px; }
        .short-card-thumb { border-radius: 12px; }
        .short-card-info { padding: 10px 0 0; }
        .short-card-title { margin-bottom: 4px; font-size: 14px; font-weight: 500; line-height: 20px; }
        .short-card-views { font-size: 12px; }
        @media (max-width: 1300px) {
            .feed-shorts .shorts-grid {
                grid-template-columns: none; grid-auto-flow: column; grid-auto-columns: minmax(150px, 22%);
                overflow-x: auto; scroll-snap-type: x proximity; padding-bottom: 6px; scrollbar-width: none;
            }
            .feed-shorts .shorts-grid::-webkit-scrollbar { display: none; }
            .feed-shorts .short-card { scroll-snap-align: start; }
        }
        @media (max-width: 639px) {
            .content-wrap { padding: 0 12px 32px; }
            .chips-bar { margin: 0 -12px 14px; padding: 10px 12px; }
            .feed-shorts .shorts-grid { grid-auto-columns: minmax(140px, 42%); }
        }
        /* ---------- Feed: desktop keeps the grid; phones get 1 video, then 2 shorts, repeat ---------- */
        .feed-mobile { display: none; }
        @media (max-width: 767px) {
            .feed-desktop { display: none; }
            .feed-mobile { display: block; }
            #videos-grid .feed-block.video-grid { grid-template-columns: 1fr; }
            #videos-grid .feed-shorts .shorts-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr)); grid-auto-flow: row; grid-auto-columns: auto;
                overflow: visible; padding-bottom: 0;
            }
        }
    </style>
    <!-- Must stay AFTER the <style> block above -->
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="theme.css?v=3">
    <link rel="stylesheet" href="search-suggest.css?v=2">
    <script src="search-suggest.js?v=2" defer></script>
</head>
<body>
    <?php render_navbar('home'); ?>

    <div class="admin-shell">
        <div class="sidebar-backdrop" data-sidebar-close></div>

        <div class="app-layout">
            <?php render_sidebar('home'); ?>

            <main class="app-main">
                <div class="content-wrap">
                    <?php if ($search !== ''): ?>
                        <h1 class="search-title">Search results for &ldquo;<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>&rdquo;</h1>
                    <?php endif; ?>

                    <nav class="chips-bar" aria-label="Video categories">
                        <?php foreach ($categories as $cat): ?>
                            <?php
                                $chipKey = $hasCategoryColumn ? 'category' : 'q';
                                $currentChip = $hasCategoryColumn ? $selectedCategory : strtolower($search);
                                $isCategoryActive = $cat === 'all' ? $currentChip === '' : $currentChip === $cat;
                                $categoryQuery = $_GET;
                                unset($categoryQuery['page'], $categoryQuery['category']);
                                if (!$hasCategoryColumn) { unset($categoryQuery['q']); }
                                if ($cat !== 'all') {
                                    $categoryQuery[$chipKey] = $cat;
                                }
                                $categoryHref = 'index.php' . ($categoryQuery ? '?' . http_build_query($categoryQuery) : '');
                            ?>
                            <a class="chip<?php echo $isCategoryActive ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($categoryHref); ?>"<?php echo $isCategoryActive ? ' aria-current="page"' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cat)); ?>
                            </a>
                        <?php endforeach; ?>
                        <button type="button" class="chips-next" id="chipsNext" aria-label="Scroll categories">&#8250;</button>
                    </nav>
                    <script>
                    (function () {
                        var bar = document.querySelector('.chips-bar'), btn = document.getElementById('chipsNext');
                        if (!bar || !btn) return;
                        function sync() { btn.style.display = (bar.scrollWidth - bar.clientWidth - bar.scrollLeft > 4) ? 'flex' : 'none'; }
                        btn.addEventListener('click', function () { bar.scrollBy({ left: 300, behavior: 'smooth' }); });
                        bar.addEventListener('scroll', sync); window.addEventListener('resize', sync); sync();
                        var act = bar.querySelector('.chip.active'); if (act && act.offsetLeft > bar.clientWidth - 80) { bar.scrollLeft = act.offsetLeft - 80; }
                    })();
                    </script>

                    <?php
                    // ----- Feed layout: videos, shorts, videos, shorts ... ending with videos -----
                    // Desktop: a row of 4 videos, then a row of 6 shorts, repeat.
                    // Phone / mobile app: 1 video, then 2 shorts, repeat.
                    $desktopFeed = ['first' => 4, 'per' => 4, 'shortsRow' => 6, 'shortsMax' => 36];
                    $mobileFeed  = ['first' => 1, 'per' => 1, 'shortsRow' => 2, 'shortsMax' => 48, 'shortsFirst' => ($search !== '')];

                    $renderVideo = function (array $row) {
                        $title = htmlspecialchars($row['title'] ?? 'Untitled');
                        $uploader = trim((string)($row['username'] ?? $row['channel_name'] ?? 'Anonymous'));
                        $username = htmlspecialchars($uploader);
                        $uploaderAvatar = avatar_for_record($uploader, $row);
                        $views = vs_short_views($row['views'] ?? 0);
                        $category = strtolower(trim($row['category'] ?? 'all'));
                        $duration = $row['duration'] ?? null;
                        $uploaded = vs_yt_time_ago($row['created_at'] ?? null);
                        $src = find_source($row, __DIR__);
                        $realYt = $src !== '' ? extract_youtube_id($src, true) : null;
                        $thumb = thumb_for($row, $src, $realYt);
                        $watchHref = "watch.php?id=" . urlencode($row['id']);
                        ?>
                        <div class="video-card" data-category="<?php echo htmlspecialchars($category); ?>"
                             data-vm-id="<?php echo htmlspecialchars((string)$row['id']); ?>"
                             data-vm-title="<?php echo htmlspecialchars((string)($row['title'] ?? 'Untitled')); ?>"
                             data-vm-channel="<?php echo $username; ?>"
                             data-vm-kind="video"
                             data-vm-url="<?php echo htmlspecialchars($watchHref); ?>"<?php if ($realYt === null && $src !== '') { ?>
                             data-vm-download="<?php echo htmlspecialchars((string)$src); ?>"<?php } ?>>
                            <a href="<?php echo htmlspecialchars($watchHref); ?>" class="video-card-link">
                                <div class="thumbnail">
                                    <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo $title; ?>" loading="lazy">
                                    <?php if (!empty($duration)): ?>
                                        <span class="duration-badge"><?php echo htmlspecialchars($duration); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="video-details-flex">
                                    <div class="channel-avatar">
                                        <?php if ($uploaderAvatar !== ''): ?>
                                            <img src="<?php echo htmlspecialchars($uploaderAvatar); ?>" alt="<?php echo htmlspecialchars($uploader); ?> avatar" loading="lazy">
                                        <?php else: ?>
                                            <span class="avatar-initial"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($uploader, 0, 1))); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="video-info">
                                        <h3><?php echo $title; ?></h3>
                                        <p class="channel-name"><?php echo $username; ?></p>
                                        <p class="video-meta"><?php echo $views; ?> views &bull; <?php echo $uploaded; ?></p>
                                    </div>
                                </div>
                            </a>
                        </div>
                        <?php
                    };

                    $renderShort = function (array $row) {
                        $title = htmlspecialchars($row['title'] ?? 'Untitled');
                        $views = vs_short_views($row['views'] ?? 0);
                        $src = find_source($row, __DIR__);
                        $ytId = $src !== '' ? extract_youtube_id($src, true) : null;
                        $thumb = thumb_for($row, $src, $ytId);
                        ?>
                        <div class="short-card"
                             data-vm-id="<?php echo htmlspecialchars((string)$row['id']); ?>"
                             data-vm-title="<?php echo htmlspecialchars((string)($row['title'] ?? 'Untitled')); ?>"
                             data-vm-channel="<?php echo htmlspecialchars(trim((string)($row['username'] ?? $row['channel_name'] ?? ''))); ?>"
                             data-vm-kind="short"
                             data-vm-url="shorts.php?id=<?php echo urlencode($row['id']); ?>">
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
                        <?php
                    };

                    // build the order: V S V S V S V ...
                    $buildFeed = function (array $vids, array $shs, array $cfg) {
                        $blocks = [];
                        $shs = array_slice($shs, 0, $cfg['shortsMax']);
                        $vi = 0; $si = 0;
                        $vCount = count($vids); $sCount = count($shs);
                        if (!empty($cfg['shortsFirst']) && $sCount) { $blocks[] = ['s', array_slice($shs, 0, $cfg['shortsRow'])]; $si += $cfg['shortsRow']; }
                        while ($vi < $vCount || $si < $sCount) {
                            if ($vi < $vCount) { $take = ($vi === 0) ? $cfg['first'] : $cfg['per']; $blocks[] = ['v', array_slice($vids, $vi, $take)]; $vi += $take; }
                            if ($si < $sCount) { $blocks[] = ['s', array_slice($shs, $si, $cfg['shortsRow'])]; $si += $cfg['shortsRow']; }
                        }
                        return $blocks;
                    };
                    $feedSets = [
                        'feed-desktop' => $buildFeed($videos, $shorts, $desktopFeed),
                        'feed-mobile'  => $buildFeed($videos, $shorts, $mobileFeed),
                    ];
                    ?>
                    <section id="videos-grid">
                        <?php if (!$videos && !$shorts): ?>
                            <section class="video-grid">
                                <div class="empty-state">
                                    <?php echo $search !== ''
                                        ? 'No videos match "' . htmlspecialchars($search) . '".'
                                        : ($selectedCategory !== ''
                                            ? 'No videos in "' . htmlspecialchars(ucfirst($selectedCategory)) . '" yet.'
                                            : 'No videos yet — be the first to upload one!'); ?>
                                </div>
                            </section>
                        <?php endif; ?>

                        <?php foreach ($feedSets as $feedClass => $feedBlocks): $firstShortsRow = true; ?>
                        <div class="<?php echo $feedClass; ?>">
                        <?php foreach ($feedBlocks as $block): ?>
                            <?php if ($block[0] === 'v'): ?>
                                <section class="video-grid feed-block" style="--cols: <?php echo max(3, min(4, count($block[1]))); ?>;">
                                    <?php foreach ($block[1] as $row) { $renderVideo($row); } ?>
                                </section>
                            <?php else: ?>
                                <section class="shorts-section feed-shorts"<?php echo ($firstShortsRow && $feedClass === 'feed-desktop') ? ' id="shorts-shelf"' : ''; ?>>
                                    <div class="feed-shorts-title">
                                        <span class="shorts-title-icon" aria-hidden="true"></span>
                                        Shorts
                                    </div>
                                    <div class="shorts-grid">
                                        <?php foreach ($block[1] as $row) { $renderShort($row); } ?>
                                    </div>
                                </section>
                                <?php $firstShortsRow = false; ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
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
                </div>
            </main>
        </div>
    </div>

    <?php /* The Report popup loads on demand from video-menu.js (report-widget.php), so the cards' three dots never depend on it. */ ?>
    <script src="sidebar.js"></script>
    <script src="video-menu.js?v=3"></script>
</body>
</html>