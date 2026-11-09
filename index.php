<?php
session_start();
include "connection.php";
// Banned accounts are signed out and shown the ban card (skipped safely if ban-guard.php is missing).
if (is_file(__DIR__ . '/ban-guard.php')) {
    try {
        require_once __DIR__ . '/ban-guard.php';
        if (function_exists('vs_bg_guard')) { vs_bg_guard(); }
    } catch (Throwable $banGuardError) {
        error_log('ban-guard: ' . $banGuardError->getMessage());
    }
}
require_once __DIR__ . '/dashboard-events.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/sidebar-navigation.php';
require_once __DIR__ . '/vip-badge.php';
require_once __DIR__ . '/live-store.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
vs_maintenance_gate();

$liveStreams = [];
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        vs_live_ensure($pdo);
        $liveStreams = $pdo->query(
            "SELECT id, username, title, description, stream_url, thumbnail_url, status, scheduled_at, viewer_count
             FROM live_streams
             WHERE status IN ('live', 'scheduled')
             ORDER BY status = 'live' DESC, started_at DESC, scheduled_at ASC
             LIMIT 12"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $error) {
    error_log('index.php live streams: ' . $error->getMessage());
    $liveStreams = [];
}

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
                <button type="button" class="vs-neon-toggle" data-vs-neon-toggle aria-label="Turn neon off" title="Turn neon off" aria-pressed="true">
                    <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M14 1 2 14h8l-1 9 13-15h-8z"/></svg>
                </button>
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
        <?php echo vs_site_announcement_html($GLOBALS['pdo'] ?? null); ?>
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
$categoryInput = $_GET['category'] ?? '';
$selectedCategory = strtolower(trim(is_scalar($categoryInput) ? (string)$categoryInput : ''));
if (!preg_match('/^[a-z0-9 _-]{1,40}$/', $selectedCategory)) {
    $selectedCategory = '';
}
$searchInput = $_GET['q'] ?? '';
$search = trim(is_scalar($searchInput) ? (string)$searchInput : '');
$search = mb_substr($search, 0, 100);
$typeInput = $_GET['type'] ?? 'all';
$selectedType = strtolower(trim(is_scalar($typeInput) ? (string)$typeInput : 'all'));
if (!in_array($selectedType, ['all', 'video', 'short'], true) || (!$hasTypeColumn && $selectedType === 'short')) {
    $selectedType = 'all';
}
$creatorInput = $_GET['creator'] ?? '';
$creatorFilter = trim(mb_substr(is_scalar($creatorInput) ? (string)$creatorInput : '', 0, 80));
$sortInput = $_GET['sort'] ?? 'relevance';
$searchSort = strtolower(trim(is_scalar($sortInput) ? (string)$sortInput : 'relevance'));
if (!in_array($searchSort, ['relevance', 'latest', 'popular'], true)) {
    $searchSort = 'relevance';
}
$feedInput = $_GET['feed'] ?? 'for_you';
$feedMode = strtolower(trim(is_scalar($feedInput) ? (string)$feedInput : 'for_you'));
if (!in_array($feedMode, ['for_you', 'latest', 'popular'], true)) {
    $feedMode = 'for_you';
}
if (!$hasCategoryColumn) {
    if ($selectedCategory !== '' && $search === '') {
        $search = $selectedCategory;
    }
    $selectedCategory = '';
}
$hasSearchFilters = $search !== '' || $creatorFilter !== '';

$perPage = $selectedType === 'short' ? 48 : 24;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$where = $selectedType === 'short' && $hasTypeColumn
    ? "type = 'short'"
    : ($hasTypeColumn ? "(type IS NULL OR type <> 'short')" : "1=1");
$params = [];
$types = '';
$shortWhere = $hasTypeColumn ? "type = 'short'" : "1=0";
$shortParams = [];
$shortTypes = '';

if ($selectedCategory !== '' && $hasCategoryColumn) {
    $where .= " AND LOWER(TRIM(category)) = ?";
    $params[] = $selectedCategory;
    $types .= 's';
    $shortWhere .= " AND LOWER(TRIM(category)) = ?";
    $shortParams[] = $selectedCategory;
    $shortTypes .= 's';
}

if ($search !== '') {
    $where .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
    $shortWhere .= " AND (title LIKE ? OR username LIKE ? OR channel_name LIKE ?)";
    array_push($shortParams, $like, $like, $like);
    $shortTypes .= 'sss';
}

if ($creatorFilter !== '') {
    $where .= " AND (username LIKE ? OR channel_name LIKE ?)";
    $creatorLike = '%' . $creatorFilter . '%';
    array_push($params, $creatorLike, $creatorLike);
    $types .= 'ss';
    $shortWhere .= " AND (username LIKE ? OR channel_name LIKE ?)";
    array_push($shortParams, $creatorLike, $creatorLike);
    $shortTypes .= 'ss';
}

$countStmt = $conn->prepare("SELECT COUNT(*) AS n FROM videos WHERE $where");
if ($types) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$totalVideos = (int)$countStmt->get_result()->fetch_assoc()['n'];
$countStmt->close();
$totalShorts = 0;
if ($hasTypeColumn) {
    $shortCountStmt = $conn->prepare("SELECT COUNT(*) AS n FROM videos WHERE $shortWhere");
    if ($shortTypes !== '') $shortCountStmt->bind_param($shortTypes, ...$shortParams);
    $shortCountStmt->execute();
    $totalShorts = (int)$shortCountStmt->get_result()->fetch_assoc()['n'];
    $shortCountStmt->close();
}
if ($selectedType === 'short') {
    $totalVideos = $totalShorts;
}
$totalPages = max(1, (int)ceil($totalVideos / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

if ($page <= 1 || empty($_SESSION['feed_seed'])) {
    $_SESSION['feed_seed'] = random_int(1, 2147483646);
}
$feedSeed = (int)$_SESSION['feed_seed'];

$orderParams = [];
$orderTypes = '';
if ($hasSearchFilters) {
    if ($searchSort === 'latest') {
        $videoOrder = 'created_at DESC';
    } elseif ($searchSort === 'popular') {
        $videoOrder = 'COALESCE(views, 0) DESC, created_at DESC';
    } else {
        $videoOrder = '(title LIKE ?) DESC, (username LIKE ?) DESC, COALESCE(views, 0) DESC, created_at DESC';
        $orderParams = [$search !== '' ? $search . '%' : '%', $creatorFilter !== '' ? $creatorFilter . '%' : '%'];
        $orderTypes = 'ss';
    }
} elseif ($feedMode === 'latest') {
    $videoOrder = 'created_at DESC';
} elseif ($feedMode === 'for_you') {
    $videoOrder = "RAND($feedSeed)";
} else {
    $videoOrder = 'COALESCE(views, 0) DESC, created_at DESC';
}
$sql = "SELECT * FROM videos WHERE $where ORDER BY $videoOrder LIMIT ? OFFSET ?";
$videos = [];
if ($selectedType !== 'short') {
    $stmt = $conn->prepare($sql);
    $bindTypes = $types . $orderTypes . 'ii';
    $bindParams = array_merge($params, $orderParams, [$perPage, $offset]);
    $stmt->bind_param($bindTypes, ...$bindParams);
    $stmt->execute();
    $videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

if ($search !== '' && $page === 1 && isset($pdo) && $pdo instanceof PDO) {
    try {
        $searchTable = $pdo->query("SHOW TABLES LIKE 'search_logs'");
        if ($searchTable && $searchTable->fetchColumn()) {
            $searchResultCount = $selectedType === 'short'
                ? $totalShorts
                : ($totalVideos + ($selectedType === 'all' ? $totalShorts : 0));
            $logSearch = $pdo->prepare(
                'INSERT INTO `search_logs` (`query`, `results`, `user_id`, `created_at`)
                 VALUES (:query, :results, :user_id, NOW())'
            );
            $userIdForSearch = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
            $logSearch->execute([
                ':query' => mb_substr($search, 0, 100),
                ':results' => $searchResultCount,
                ':user_id' => ($userIdForSearch !== false && $userIdForSearch > 0) ? $userIdForSearch : null,
            ]);
        }
    } catch (Throwable $searchLogError) {
        error_log('Search query could not be recorded: ' . $searchLogError->getMessage());
    }
}

$shortsPerPage = 48;   // shorts loaded per page (2 shorts after every video, so 2 x the videos per page)
$shorts = [];
if ($hasTypeColumn && $selectedType !== 'video') {
    if ($hasSearchFilters) {
        if ($searchSort === 'latest') {
            $shortOrder = 'created_at DESC';
            $shortOrderParams = [];
            $shortOrderTypes = '';
        } elseif ($searchSort === 'popular') {
            $shortOrder = 'COALESCE(views, 0) DESC, created_at DESC';
            $shortOrderParams = [];
            $shortOrderTypes = '';
        } else {
            $shortOrder = '(title LIKE ?) DESC, (username LIKE ?) DESC, COALESCE(views, 0) DESC, created_at DESC';
            $shortOrderParams = [$search !== '' ? $search . '%' : '%', $creatorFilter !== '' ? $creatorFilter . '%' : '%'];
            $shortOrderTypes = 'ss';
        }
    } else {
        $shortOrder = "RAND($feedSeed)";
        $shortOrderParams = [];
        $shortOrderTypes = '';
    }
    $sSql = "SELECT * FROM videos WHERE $shortWhere ORDER BY $shortOrder LIMIT ? OFFSET ?";
    $sStmt = $conn->prepare($sSql);
    $sParams = array_merge($shortParams, $shortOrderParams, [$shortsPerPage, ($page - 1) * $shortsPerPage]);
    $sTypes = $shortTypes . $shortOrderTypes . 'ii';
    $sStmt->bind_param($sTypes, ...$sParams);
    $sStmt->execute();
    $shorts = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $sStmt->close();
}

$categories = ['all'];
$categoryLabels = [];
$categoryColors = [];
$categoryCounts = [];
$configuredCategories = [];
try {
    foreach (vs_categories_with_colors() as $configuredCategory) {
        $categoryName = trim((string)($configuredCategory['name'] ?? ''));
        $categorySlug = mb_strtolower($categoryName);
        if ($categorySlug === '' || !preg_match('/^[a-z0-9 _-]{1,40}$/', $categorySlug)) {
            continue;
        }
        $categories[] = $categorySlug;
        $categoryLabels[$categorySlug] = $categoryName;
        $categoryColors[$categorySlug] = (string)($configuredCategory['color'] ?? '');
        $categoryCounts[$categorySlug] = 0;
    }
} catch (Throwable $categoryError) {
    error_log('Home page could not load configured categories: ' . $categoryError->getMessage());
}
if (count($categories) === 1) {
    foreach (['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education', 'Anime'] as $defaultCategory) {
        $categorySlug = mb_strtolower($defaultCategory);
        $categories[] = $categorySlug;
        $categoryLabels[$categorySlug] = $defaultCategory;
        $categoryCounts[$categorySlug] = 0;
    }
}
if ($hasCategoryColumn) {
    try {
        $catRes = $conn->query("SELECT LOWER(TRIM(category)) AS c, COUNT(*) AS n FROM videos WHERE category IS NOT NULL AND category <> '' GROUP BY LOWER(TRIM(category))");
        while ($catRes && ($row = $catRes->fetch_assoc())) {
            $categorySlug = trim((string)($row['c'] ?? ''));
            if ($categorySlug !== '' && preg_match('/^[a-z0-9 _-]{1,40}$/', $categorySlug)) {
                $categories[] = $categorySlug;
                $categoryLabels[$categorySlug] = $categoryLabels[$categorySlug] ?? ucfirst($categorySlug);
                $categoryCounts[$categorySlug] = (int)$row['n'];
            }
        }
    } catch (Throwable $categoryError) {
        error_log('Home page could not load video categories: ' . $categoryError->getMessage());
    }
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
    <script src="sidebar.js" defer></script>
    <script src="sidebar-rail.js"></script>
    <script src="theme.js?v=3"></script>
    <script src="brain.js?v=<?php echo (int)@filemtime(__DIR__ . '/brain.js'); ?>" defer></script>
    <link rel="stylesheet" href="neon-off.css?v=7">
    <script src="neon-switch.js?v=7"></script>
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
            gap: 7px;
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
        .category-chip-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 18px; width: 18px; height: 18px; }
        .category-chip-icon svg, .category-chip-icon img { display: block; width: 18px; height: 18px; object-fit: contain; }
        .category-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; padding: 2px 6px; border-radius: 999px; background: rgba(128,128,128,.15); font-size: 11px; line-height: 1.2; font-variant-numeric: tabular-nums; }

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

        .home-live-section { margin: 0 0 24px; }
        .home-live-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
        .home-live-card {
            min-width: 0; border-radius: 16px; overflow: hidden; background: var(--surface);
            border: 1px solid var(--border); text-decoration: none; color: inherit;
            transition: transform .18s ease, box-shadow .18s ease;
        }
        .home-live-card:hover { transform: translateY(-2px); box-shadow: 0 14px 30px rgba(0,0,0,.18); }
        .home-live-thumb { position: relative; aspect-ratio: 16 / 9; background: linear-gradient(135deg,#23205c,#7c3aed); }
        .home-live-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        .home-live-status { position:absolute; left:10px; bottom:10px; border-radius:999px; padding:5px 8px; color:#fff; background:#e11d48; font-size:11px; font-weight:900; letter-spacing:.04em; }
        .home-live-status.scheduled { background:#7c3aed; }
        .home-live-copy { padding: 12px; }
        .home-live-copy strong { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; margin-bottom:5px; }
        .home-live-meta { color:var(--muted); font-size:13px; }
        @media(max-width:1050px){ .home-live-grid{grid-template-columns:repeat(3,minmax(0,1fr));} }
        @media(max-width:760px){ .home-live-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;} }
        @media(max-width:480px){ .home-live-grid{grid-template-columns:1fr;} }

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
        .video-info h3 a:hover { color: var(--blue); }

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
                grid-template-columns: minmax(0, 1fr) auto;
                grid-template-rows: 48px 44px;
                height: 112px;
                align-content: center;
                gap: 4px 8px;
                padding: 0 12px;
            }

            :root { --navbar-height: 112px; }

            .nav-left { grid-column: 1; grid-row: 1; min-width: 0; }
            .nav-center { grid-column: 1 / -1; grid-row: 2; width: 100%; min-width: 0; }
            .nav-right { grid-column: 2; grid-row: 1; gap: 6px; min-width: 0; }
            .search-form { max-width: none; }
            .brand { font-size: 20px; }

            .nav-right { justify-content: flex-end; }

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
                height: 112px;
                padding: 0 10px;
            }

            :root {
                --navbar-height: 112px;
            }

            .brand { font-size: 18px; }
            .nav-toggle { flex: 0 0 40px; width: 40px; height: 40px; }
            .nav-left { gap: 8px; }
            .nav-right { gap: 4px; }
            #search-input { height: 40px; }

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
        .search-filter-form {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            align-items: end; gap: 10px; margin: 14px 0 18px; padding: 14px;
            border: 1px solid var(--border, rgba(128,128,128,.25)); border-radius: 14px;
            background: var(--surface, rgba(128,128,128,.06));
        }
        .search-filter-form label { display: grid; gap: 5px; min-width: 0; color: var(--muted); font-size: 12px; }
        .search-filter-form input, .search-filter-form select {
            width: 100%; min-width: 0; min-height: 40px; padding: 8px 10px;
            border: 1px solid var(--border, rgba(128,128,128,.35)); border-radius: 9px;
            background: var(--surface-2, #fff); color: var(--text, #111);
        }
        .search-filter-form button, .search-filter-clear {
            display: inline-flex; align-items: center; justify-content: center; min-height: 40px;
            padding: 8px 14px; border: 0; border-radius: 9px; background: var(--accent, #8a5cff);
            color: #fff; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer;
        }
        .search-filter-clear { background: transparent; color: var(--text); border: 1px solid var(--border, rgba(128,128,128,.35)); }
        .search-filter-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        @media(max-width:520px) { .search-filter-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
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
    <link rel="stylesheet" href="theme.css?v=5">
    <link rel="stylesheet" href="search-suggest.css?v=2">
    <script src="search-suggest.js?v=2" defer></script>
    <!-- NEON DARK THEME: only applies when the site is NOT in light mode. Stays after theme.css so it wins. -->
    <style id="neon-dark">
        html:not([data-theme="light"]) {
            --bg: #05060f;
            --surface: #0a0d1f;
            --surface-2: #121634;
            --surface-3: #171c42;
            --surface-4: #0e1230;
            --text: #eaf0ff;
            --muted: #8f9bd0;
            --border: #1d2557;
            --accent: #ff2e93;
            --accent-hover: #171c42;
            --accent-soft: rgba(255, 46, 147, 0.18);
            --blue: #00e5ff;
            --neon-cyan: #00e5ff;
            --neon-pink: #ff2e93;
            --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body {
            background:
                radial-gradient(900px 480px at 8% -8%, rgba(138, 92, 255, 0.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0, 229, 255, 0.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255, 46, 147, 0.08), transparent 70%),
                #05060f;
            background-attachment: fixed;
        }

        /* top bar */
        html:not([data-theme="light"]) .app-navbar {
            background: rgba(5, 6, 15, 0.82);
            border-bottom: 1px solid rgba(0, 229, 255, 0.35);
            box-shadow: 0 1px 24px -4px rgba(0, 229, 255, 0.35), 0 8px 30px -12px rgba(138, 92, 255, 0.4);
        }
        html:not([data-theme="light"]) .brand {
            background: linear-gradient(90deg, var(--neon-cyan), var(--neon-violet) 55%, var(--neon-pink));
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent; color: transparent;
            filter: drop-shadow(0 0 8px rgba(138, 92, 255, 0.65));
        }
        html:not([data-theme="light"]) .nav-toggle span { background: var(--neon-cyan); box-shadow: 0 0 6px var(--neon-cyan); }
        html:not([data-theme="light"]) .nav-toggle:hover,
        html:not([data-theme="light"]) .theme-toggle:hover { background: rgba(0, 229, 255, 0.12); box-shadow: 0 0 14px rgba(0, 229, 255, 0.35); }
        html:not([data-theme="light"]) .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(255, 225, 77, 0.8)); }

        /* search */
        html:not([data-theme="light"]) #search-input {
            background: rgba(10, 13, 31, 0.9);
            border: 1px solid rgba(138, 92, 255, 0.55);
            color: var(--text);
            box-shadow: 0 0 10px -2px rgba(138, 92, 255, 0.45);
            transition: box-shadow .2s ease, border-color .2s ease;
        }
        html:not([data-theme="light"]) #search-input::placeholder { color: #6f7bb0; }
        html:not([data-theme="light"]) #search-input:focus {
            outline: none; border-color: var(--neon-cyan);
            box-shadow: 0 0 0 1px var(--neon-cyan), 0 0 22px rgba(0, 229, 255, 0.55);
        }
        html:not([data-theme="light"]) .search-form button { color: var(--neon-cyan); }

        /* user pill + logout */
        html:not([data-theme="light"]) .user-badge-pill {
            background: linear-gradient(180deg, rgba(23, 28, 66, 0.9), rgba(10, 13, 31, 0.95));
            border: 1px solid rgba(255, 46, 147, 0.55);
            box-shadow: 0 0 14px -2px rgba(255, 46, 147, 0.45);
        }
        html:not([data-theme="light"]) .logout-link:hover { color: var(--neon-pink); text-shadow: 0 0 10px rgba(255, 46, 147, 0.8); }

        /* sidebar */
        html:not([data-theme="light"]) .app-sidebar {
            background: linear-gradient(180deg, rgba(10, 13, 31, 0.97), rgba(5, 6, 15, 0.98));
            border-right: 1px solid rgba(138, 92, 255, 0.4);
            box-shadow: 4px 0 28px -10px rgba(138, 92, 255, 0.45);
        }
        html:not([data-theme="light"]) .app-sidebar a { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) .app-sidebar a:hover {
            background: rgba(0, 229, 255, 0.10); color: #fff; box-shadow: inset 3px 0 0 var(--neon-cyan), 0 0 16px -4px rgba(0, 229, 255, 0.5);
        }
        html:not([data-theme="light"]) .app-sidebar a.active,
        html:not([data-theme="light"]) .app-sidebar .active {
            background: linear-gradient(90deg, rgba(255, 46, 147, 0.22), rgba(138, 92, 255, 0.12));
            color: #fff; box-shadow: inset 3px 0 0 var(--neon-pink), 0 0 18px -4px rgba(255, 46, 147, 0.55);
        }
        html:not([data-theme="light"]) .sidebar-heading { color: var(--neon-cyan); text-shadow: 0 0 8px rgba(0, 229, 255, 0.55); }

        /* category chips */
        html:not([data-theme="light"]) .chips-bar { background: rgba(5, 6, 15, 0.85); backdrop-filter: blur(8px); }
        html:not([data-theme="light"]) .chip {
            background: rgba(23, 28, 66, 0.7); color: var(--text);
            border: 1px solid rgba(138, 92, 255, 0.35);
            transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease;
        }
        html:not([data-theme="light"]) .chip:hover {
            background: rgba(0, 229, 255, 0.12); border-color: var(--neon-cyan);
            box-shadow: 0 0 14px rgba(0, 229, 255, 0.5); transform: translateY(-1px);
        }
        html:not([data-theme="light"]) .chip.active {
            background: linear-gradient(90deg, var(--neon-violet), var(--neon-pink)); color: #fff; border-color: transparent;
            box-shadow: 0 0 18px rgba(255, 46, 147, 0.6);
        }
        html:not([data-theme="light"]) .chips-next { background: #05060f; box-shadow: -14px 0 12px #05060f; color: var(--neon-cyan); }

        /* hero strip + badge */
        html:not([data-theme="light"]) .hero-strip {
            background: linear-gradient(135deg, rgba(20, 24, 70, 0.85), rgba(10, 13, 31, 0.9) 60%, rgba(60, 14, 60, 0.6));
            border: 1px solid rgba(138, 92, 255, 0.5);
            box-shadow: 0 0 30px -8px rgba(138, 92, 255, 0.6), inset 0 0 40px -20px rgba(0, 229, 255, 0.4);
        }
        html:not([data-theme="light"]) .hero-badge {
            background: rgba(255, 46, 147, 0.14); color: #ff7ac0;
            border: 1px solid rgba(255, 46, 147, 0.6); text-shadow: 0 0 8px rgba(255, 46, 147, 0.8);
            box-shadow: 0 0 12px -2px rgba(255, 46, 147, 0.5);
        }

        /* thumbnails + cards */
        html:not([data-theme="light"]) .thumbnail,
        html:not([data-theme="light"]) .short-card-thumb {
            border: 1px solid rgba(138, 92, 255, 0.25);
            box-shadow: 0 0 0 0 rgba(0, 229, 255, 0);
            transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease;
        }
        html:not([data-theme="light"]) .video-card-link:hover .thumbnail,
        html:not([data-theme="light"]) .short-card-link:hover .short-card-thumb {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 22px rgba(0, 229, 255, 0.55), 0 0 44px -6px rgba(138, 92, 255, 0.55);
            transform: translateY(-3px);
        }
        html:not([data-theme="light"]) .video-card-link:hover .video-info h3,
        html:not([data-theme="light"]) .short-card-link:hover .short-card-title { color: var(--neon-cyan); text-shadow: 0 0 10px rgba(0, 229, 255, 0.6); }
        html:not([data-theme="light"]) .duration-badge { background: rgba(5, 6, 15, 0.85); color: var(--neon-cyan); border: 1px solid rgba(0, 229, 255, 0.45); }
        html:not([data-theme="light"]) .channel-avatar { box-shadow: 0 0 0 2px rgba(255, 46, 147, 0.7), 0 0 10px rgba(255, 46, 147, 0.5); }
        html:not([data-theme="light"]) .channel-name,
        html:not([data-theme="light"]) .video-meta,
        html:not([data-theme="light"]) .short-card-views { color: var(--muted); }
        html:not([data-theme="light"]) .feed-shorts-title svg,
        html:not([data-theme="light"]) .feed-shorts-title .shorts-title-icon { color: var(--neon-pink); filter: drop-shadow(0 0 6px rgba(255, 46, 147, 0.8)); }
        html:not([data-theme="light"]) .feed-shorts-title { text-shadow: 0 0 12px rgba(138, 92, 255, 0.7); }

        /* pagination + empty state */
        html:not([data-theme="light"]) .pagination a,
        html:not([data-theme="light"]) .pagination span { border: 1px solid rgba(138, 92, 255, 0.4); background: rgba(10, 13, 31, 0.8); }
        html:not([data-theme="light"]) .pagination a:hover { border-color: var(--neon-cyan); box-shadow: 0 0 14px rgba(0, 229, 255, 0.5); color: #fff; }
        html:not([data-theme="light"]) .pagination .current {
            background: linear-gradient(90deg, var(--neon-violet), var(--neon-pink)); border-color: transparent; color: #fff; box-shadow: 0 0 16px rgba(255, 46, 147, 0.6);
        }
        html:not([data-theme="light"]) .empty-state { background: rgba(10, 13, 31, 0.7); border: 1px dashed rgba(0, 229, 255, 0.45); box-shadow: 0 0 24px -8px rgba(0, 229, 255, 0.4); }

        /* scrollbar */
        html:not([data-theme="light"]) ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--neon-violet), var(--neon-pink)); border-radius: 8px; }
        html:not([data-theme="light"]) ::selection { background: rgba(255, 46, 147, 0.45); color: #fff; }

        @media (prefers-reduced-motion: reduce) {
            html:not([data-theme="light"]) * { transition: none !important; }
        }
    </style>
    <!-- NEON LIGHT THEME: same neon style, on a bright background (only when the site is in light mode) -->
    <style id="neon-light">
        html[data-theme="light"] {
            --bg: #f4f6ff;
            --surface: #ffffff;
            --surface-2: #eceffd;
            --surface-3: #e3e8fc;
            --surface-4: #f0f2ff;
            --text: #0b1030;
            --muted: #55608f;
            --border: #cfd6f7;
            --accent: #ff2e93;
            --accent-hover: #e3e8fc;
            --accent-soft: rgba(255, 46, 147, 0.12);
            --blue: #0090e0;
            --neon-cyan: #00b8e6;
            --neon-pink: #ff2e93;
            --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body {
            background:
                radial-gradient(900px 480px at 8% -8%, rgba(124, 77, 255, 0.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0, 184, 230, 0.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255, 46, 147, 0.10), transparent 70%),
                #f4f6ff;
            background-attachment: fixed;
        }

        html[data-theme="light"] .app-navbar {
            background: rgba(255, 255, 255, 0.85);
            border-bottom: 1px solid rgba(0, 184, 230, 0.55);
            box-shadow: 0 1px 22px -4px rgba(0, 184, 230, 0.45), 0 8px 30px -12px rgba(124, 77, 255, 0.35);
        }
        html[data-theme="light"] .brand {
            background: linear-gradient(90deg, var(--neon-cyan), var(--neon-violet) 55%, var(--neon-pink));
            -webkit-background-clip: text; background-clip: text;
            -webkit-text-fill-color: transparent; color: transparent;
            filter: drop-shadow(0 0 6px rgba(124, 77, 255, 0.45));
        }
        html[data-theme="light"] .nav-toggle span { background: var(--neon-violet); box-shadow: 0 0 6px rgba(124, 77, 255, 0.6); }
        html[data-theme="light"] .nav-toggle:hover,
        html[data-theme="light"] .theme-toggle:hover { background: rgba(0, 184, 230, 0.14); box-shadow: 0 0 14px rgba(0, 184, 230, 0.4); }
        html[data-theme="light"] .theme-toggle svg { color: var(--neon-violet); filter: drop-shadow(0 0 5px rgba(124, 77, 255, 0.6)); }

        html[data-theme="light"] #search-input {
            background: #ffffff;
            border: 1px solid rgba(124, 77, 255, 0.55);
            color: var(--text);
            box-shadow: 0 0 10px -2px rgba(124, 77, 255, 0.4);
            transition: box-shadow .2s ease, border-color .2s ease;
        }
        html[data-theme="light"] #search-input::placeholder { color: #8a94c4; }
        html[data-theme="light"] #search-input:focus {
            outline: none; border-color: var(--neon-cyan);
            box-shadow: 0 0 0 1px var(--neon-cyan), 0 0 20px rgba(0, 184, 230, 0.5);
        }
        html[data-theme="light"] .search-form button { color: var(--neon-violet); }

        html[data-theme="light"] .user-badge-pill {
            background: linear-gradient(180deg, #ffffff, #f1f3ff);
            border: 1px solid rgba(255, 46, 147, 0.55);
            box-shadow: 0 0 14px -3px rgba(255, 46, 147, 0.45);
        }
        html[data-theme="light"] .logout-link:hover { color: var(--neon-pink); text-shadow: 0 0 8px rgba(255, 46, 147, 0.5); }

        html[data-theme="light"] .app-sidebar {
            background: linear-gradient(180deg, #ffffff, #f4f6ff);
            border-right: 1px solid rgba(124, 77, 255, 0.35);
            box-shadow: 4px 0 26px -12px rgba(124, 77, 255, 0.45);
        }
        html[data-theme="light"] .app-sidebar a { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] .app-sidebar a:hover {
            background: rgba(0, 184, 230, 0.12); box-shadow: inset 3px 0 0 var(--neon-cyan), 0 0 14px -4px rgba(0, 184, 230, 0.5);
        }
        html[data-theme="light"] .app-sidebar a.active,
        html[data-theme="light"] .app-sidebar .active {
            background: linear-gradient(90deg, rgba(255, 46, 147, 0.16), rgba(124, 77, 255, 0.10));
            box-shadow: inset 3px 0 0 var(--neon-pink), 0 0 16px -4px rgba(255, 46, 147, 0.45);
        }
        html[data-theme="light"] .sidebar-heading { color: var(--neon-violet); text-shadow: 0 0 8px rgba(124, 77, 255, 0.35); }

        html[data-theme="light"] .chips-bar { background: rgba(244, 246, 255, 0.88); backdrop-filter: blur(8px); }
        html[data-theme="light"] .chip {
            background: #ffffff; color: var(--text);
            border: 1px solid rgba(124, 77, 255, 0.35);
            transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease;
        }
        html[data-theme="light"] .chip:hover {
            background: rgba(0, 184, 230, 0.10); border-color: var(--neon-cyan);
            box-shadow: 0 0 14px rgba(0, 184, 230, 0.5); transform: translateY(-1px);
        }
        html[data-theme="light"] .chip.active {
            background: linear-gradient(90deg, var(--neon-violet), var(--neon-pink)); color: #fff; border-color: transparent;
            box-shadow: 0 0 18px rgba(255, 46, 147, 0.5);
        }
        html[data-theme="light"] .chips-next { background: #f4f6ff; box-shadow: -14px 0 12px #f4f6ff; color: var(--neon-violet); }

        html[data-theme="light"] .hero-strip {
            background: linear-gradient(135deg, #ffffff 0%, #eef1ff 55%, #fff0f8 100%);
            border: 1px solid rgba(124, 77, 255, 0.45);
            box-shadow: 0 0 28px -8px rgba(124, 77, 255, 0.5), inset 0 0 40px -22px rgba(0, 184, 230, 0.5);
        }
        html[data-theme="light"] .hero-badge {
            background: rgba(255, 46, 147, 0.10); color: #d4147a;
            border: 1px solid rgba(255, 46, 147, 0.55);
            box-shadow: 0 0 12px -2px rgba(255, 46, 147, 0.4);
        }

        html[data-theme="light"] .thumbnail,
        html[data-theme="light"] .short-card-thumb {
            border: 1px solid rgba(124, 77, 255, 0.28);
            transition: box-shadow .22s ease, border-color .22s ease, transform .22s ease;
        }
        html[data-theme="light"] .video-card-link:hover .thumbnail,
        html[data-theme="light"] .short-card-link:hover .short-card-thumb {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 20px rgba(0, 184, 230, 0.5), 0 0 40px -6px rgba(124, 77, 255, 0.45);
            transform: translateY(-3px);
        }
        html[data-theme="light"] .video-card-link:hover .video-info h3,
        html[data-theme="light"] .short-card-link:hover .short-card-title { color: #0086b3; text-shadow: 0 0 8px rgba(0, 184, 230, 0.35); }
        html[data-theme="light"] .duration-badge { background: rgba(11, 16, 48, 0.88); color: #7defff; border: 1px solid rgba(0, 184, 230, 0.55); }
        html[data-theme="light"] .channel-avatar { box-shadow: 0 0 0 2px rgba(255, 46, 147, 0.7), 0 0 10px rgba(255, 46, 147, 0.4); }
        html[data-theme="light"] .feed-shorts-title svg,
        html[data-theme="light"] .feed-shorts-title .shorts-title-icon { color: var(--neon-pink); filter: drop-shadow(0 0 5px rgba(255, 46, 147, 0.55)); }
        html[data-theme="light"] .feed-shorts-title { text-shadow: 0 0 10px rgba(124, 77, 255, 0.35); }

        html[data-theme="light"] .pagination a,
        html[data-theme="light"] .pagination span { border: 1px solid rgba(124, 77, 255, 0.4); background: #ffffff; }
        html[data-theme="light"] .pagination a:hover { border-color: var(--neon-cyan); box-shadow: 0 0 14px rgba(0, 184, 230, 0.5); }
        html[data-theme="light"] .pagination .current {
            background: linear-gradient(90deg, var(--neon-violet), var(--neon-pink)); border-color: transparent; color: #fff; box-shadow: 0 0 16px rgba(255, 46, 147, 0.5);
        }
        html[data-theme="light"] .empty-state { background: rgba(255, 255, 255, 0.8); border: 1px dashed rgba(0, 184, 230, 0.55); box-shadow: 0 0 24px -8px rgba(0, 184, 230, 0.4); }

        html[data-theme="light"] ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, var(--neon-violet), var(--neon-pink)); border-radius: 8px; }
        html[data-theme="light"] ::selection { background: rgba(255, 46, 147, 0.3); }

        @media (prefers-reduced-motion: reduce) {
            html[data-theme="light"] * { transition: none !important; }
        }
    </style>
    <link rel="stylesheet" href="sidebar-rail.css?v=1">
</head>
<body>
    <?php render_navbar('home'); ?>

    <div class="admin-shell">
        <div class="sidebar-backdrop" data-sidebar-close></div>

        <div class="app-layout">
            <?php render_sidebar('home'); ?>

            <main class="app-main">
                <div class="content-wrap">
                    <?php if ($hasSearchFilters): ?>
                        <h1 class="search-title"><?php echo $search !== '' ? 'Search results for &ldquo;' . htmlspecialchars($search, ENT_QUOTES, 'UTF-8') . '&rdquo;' : 'Creator results'; ?></h1>
                        <form class="search-filter-form" action="index.php" method="get" aria-label="Filter search results">
                            <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                            <label>
                                Creator
                                <input type="text" name="creator" value="<?php echo htmlspecialchars($creatorFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Any creator" maxlength="80">
                            </label>
                            <label>
                                Content type
                                <select name="type">
                                    <option value="all"<?php echo $selectedType === 'all' ? ' selected' : ''; ?>>Videos and Shorts</option>
                                    <option value="video"<?php echo $selectedType === 'video' ? ' selected' : ''; ?>>Videos only</option>
                                    <?php if ($hasTypeColumn): ?><option value="short"<?php echo $selectedType === 'short' ? ' selected' : ''; ?>>Shorts only</option><?php endif; ?>
                                </select>
                            </label>
                            <?php if ($hasCategoryColumn): ?>
                                <label>
                                    Category
                                    <select name="category">
                                        <option value="">All categories</option>
                                        <?php foreach (array_slice($categories, 1) as $filterCategory): ?>
                                            <option value="<?php echo htmlspecialchars($filterCategory, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $selectedCategory === $filterCategory ? ' selected' : ''; ?>><?php echo htmlspecialchars($categoryLabels[$filterCategory] ?? ucfirst($filterCategory), ENT_QUOTES, 'UTF-8'); ?><?php if ($hasCategoryColumn): ?> (<?php echo number_format((int)($categoryCounts[$filterCategory] ?? 0)); ?>)<?php endif; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            <?php endif; ?>
                            <label>
                                Sort by
                                <select name="sort">
                                    <option value="relevance"<?php echo $searchSort === 'relevance' ? ' selected' : ''; ?>>Most relevant</option>
                                    <option value="latest"<?php echo $searchSort === 'latest' ? ' selected' : ''; ?>>Newest</option>
                                    <option value="popular"<?php echo $searchSort === 'popular' ? ' selected' : ''; ?>>Most viewed</option>
                                </select>
                            </label>
                            <div class="search-filter-actions">
                                <button type="submit">Apply filters</button>
                                <a class="search-filter-clear" href="index.php">Clear</a>
                            </div>
                        </form>
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
                                <?php if ($cat !== 'all'): ?>
                                    <span class="category-chip-icon" style="color:<?php echo htmlspecialchars($categoryColors[$cat] ?? 'currentColor', ENT_QUOTES, 'UTF-8'); ?>"><?php echo vs_category_icon_html($categoryLabels[$cat] ?? ucfirst($cat), 18); ?></span>
                                <?php endif; ?>
                                <?php echo htmlspecialchars($cat === 'all' ? 'All' : ($categoryLabels[$cat] ?? ucfirst($cat)), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($cat !== 'all' && $hasCategoryColumn): ?><span class="category-count" aria-label="<?php echo number_format((int)($categoryCounts[$cat] ?? 0)); ?> videos"><?php echo number_format((int)($categoryCounts[$cat] ?? 0)); ?></span><?php endif; ?>
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
                    <?php if ($search === ''): ?>
                    <nav class="chips-bar" aria-label="Video feed">
                        <?php foreach (['for_you' => 'For you', 'latest' => 'Latest', 'popular' => 'Popular'] as $mode => $label):
                            $feedQuery = $_GET;
                            unset($feedQuery['page']);
                            $feedQuery['feed'] = $mode;
                            $feedHref = 'index.php?' . http_build_query($feedQuery);
                        ?>
                            <a class="chip<?php echo $feedMode === $mode ? ' active' : ''; ?>" href="<?php echo htmlspecialchars($feedHref, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $feedMode === $mode ? ' aria-current="page"' : ''; ?>>
                                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?>
                            </a>
                        <?php endforeach; ?>
                    </nav>
                    <?php endif; ?>

                    <?php if ($search === ''): ?>
                    <section class="home-live-section" id="homeLiveSection" aria-label="Live streams"<?= empty($liveStreams) ? ' style="display:none"' : '' ?>>
                        <div class="section-head">
                            <div>
                                <h2>Live &amp; upcoming</h2>
                                <p>Streams happening now and scheduled next</p>
                            </div>
                            <a class="channel-name" href="live.php">View all</a>
                        </div>
                        <div class="home-live-grid" id="homeLiveGrid">
                            <?php foreach ($liveStreams as $liveStream):
                                $liveId = (int)$liveStream['id'];
                                $liveTitle = htmlspecialchars((string)$liveStream['title'], ENT_QUOTES, 'UTF-8');
                                $liveUser = htmlspecialchars((string)$liveStream['username'], ENT_QUOTES, 'UTF-8');
                                $liveStatus = (string)$liveStream['status'];
                                $liveThumb = trim((string)$liveStream['thumbnail_url']);
                                $liveYoutubeId = $liveThumb !== '' ? extract_youtube_id($liveThumb, true) : null;
                                if ($liveYoutubeId !== null) {
                                    $liveThumb = 'https://i.ytimg.com/vi/' . rawurlencode($liveYoutubeId) . '/hqdefault.jpg';
                                }
                            ?>
                            <a class="home-live-card" href="live.php?stream=<?= $liveId ?>">
                                <div class="home-live-thumb">
                                    <?php if ($liveThumb !== ''): ?>
                                    <img src="<?= htmlspecialchars($liveThumb, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $liveTitle ?>" loading="lazy" onerror="this.style.display='none'">
                                    <?php endif; ?>
                                    <span class="home-live-status<?= $liveStatus === 'scheduled' ? ' scheduled' : '' ?>"><?= $liveStatus === 'live' ? 'LIVE' : 'UPCOMING' ?></span>
                                </div>
                                <div class="home-live-copy">
                                    <strong><?= $liveTitle ?></strong>
                                    <span class="home-live-meta"><?= $liveUser ?> · <?= $liveStatus === 'live' ? number_format((int)$liveStream['viewer_count']) . ' watching' : 'Scheduled' ?></span>
                                </div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <script>
                    (() => {
                        const section = document.getElementById('homeLiveSection');
                        const grid = document.getElementById('homeLiveGrid');
                        if (!section || !grid) return;
                        const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
                        const youtubeId = (url) => {
                            const match = String(url || '').match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?.*?v=|embed\/|shorts\/|live\/|v\/))([A-Za-z0-9_-]{11})/i)
                                || String(url || '').match(/[?&]v=([A-Za-z0-9_-]{11})/i);
                            return match ? match[1] : '';
                        };
                        async function refreshLive() {
                            try {
                                const response = await fetch('live-api.php?action=list', {cache:'no-store', credentials:'same-origin'});
                                const data = await response.json();
                                if (!response.ok || !data.ok) return;
                                if (!data.streams.length) { section.style.display = 'none'; return; }
                                section.style.display = '';
                                grid.innerHTML = data.streams.slice(0, 12).map(stream => {
                                    const status = stream.status === 'live' ? 'LIVE' : 'UPCOMING';
                                    const scheduledClass = stream.status === 'scheduled' ? ' scheduled' : '';
                                    const ytId = youtubeId(stream.thumbnail_url || '');
                                    const thumb = ytId ? 'https://i.ytimg.com/vi/' + encodeURIComponent(ytId) + '/hqdefault.jpg' : (stream.thumbnail_url || '');
                                    const meta = stream.status === 'live' ? Number(stream.viewer_count || 0).toLocaleString() + ' watching' : 'Scheduled';
                                    return '<a class="home-live-card" href="live.php?stream=' + Number(stream.id) + '">' +
                                        '<div class="home-live-thumb">' +
                                        (thumb ? '<img src="' + escapeHtml(thumb) + '" alt="' + escapeHtml(stream.title) + '" loading="lazy" onerror="this.style.display=\'none\'">' : '') +
                                        '<span class="home-live-status' + scheduledClass + '">' + status + '</span></div>' +
                                        '<div class="home-live-copy"><strong>' + escapeHtml(stream.title) + '</strong>' +
                                        '<span class="home-live-meta">' + escapeHtml(stream.username) + ' · ' + escapeHtml(meta) + '</span></div></a>';
                                }).join('');
                            } catch (error) {}
                        }
                        refreshLive();
                        setInterval(refreshLive, 15000);
                    })();
                    </script>
                    <?php endif; ?>

                    <?php echo vs_ads_html(); ?>

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
                             <?php echo vs_download_attr((string)$row['id'], $conn ?? null); ?><?php } ?>>
                            <a href="<?php echo htmlspecialchars($watchHref); ?>" class="video-card-link">
                                <div class="thumbnail">
                                    <img src="<?php echo htmlspecialchars($thumb); ?>" alt="<?php echo $title; ?>" loading="lazy">
                                    <?php if (!empty($duration)): ?>
                                        <span class="duration-badge"><?php echo htmlspecialchars($duration); ?></span>
                                    <?php endif; ?>
                                </div>
                            </a>
                            <div class="video-details-flex">
                                <a class="channel-avatar" href="profile.php?u=<?php echo rawurlencode($uploader); ?>" aria-label="Visit <?php echo $username; ?>'s channel">
                                    <?php if ($uploaderAvatar !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($uploaderAvatar); ?>" alt="" loading="lazy">
                                    <?php else: ?>
                                        <span class="avatar-initial"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($uploader, 0, 1))); ?></span>
                                    <?php endif; ?>
                                </a>
                                <div class="video-info">
                                    <h3><a href="<?php echo htmlspecialchars($watchHref); ?>"><?php echo $title; ?></a></h3>
                                    <a class="channel-name" href="profile.php?u=<?php echo rawurlencode($uploader); ?>"><?php echo $username; ?></a>
                                    <p class="video-meta"><?php echo $views; ?> views &bull; <?php echo $uploaded; ?></p>
                                </div>
                            </div>
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
    <script src="video-menu.js?v=4"></script>
</body>
</html>