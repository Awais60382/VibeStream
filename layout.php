<?php
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/notifications-store.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/sidebar-navigation.php';
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();

if (session_status() === PHP_SESSION_NONE) session_start();
vs_maintenance_gate();

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

if (!function_exists('avatar_hue')) {
    function avatar_hue($name) {
        return abs(crc32(strtolower((string)$name))) % 360;
    }
}

if (!function_exists('render_navbar')) {
    function render_navbar($active = '') {
        $isLoggedIn = isset($_SESSION['username']) && $_SESSION['username'] !== '';
        $username = $isLoggedIn ? $_SESSION['username'] : '';
        $initial = $isLoggedIn ? strtoupper(substr($username, 0, 1)) : 'G';
        $siteLogoUrl = vs_branding_logo_url();
        $siteName = vs_branding_app_name();
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
                    <span><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
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
                    <?php echo vs_user_menu_html($username, avatar_url($username)); ?>
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

if (!function_exists('vs_base_css')) {
    function vs_base_css() {
        return <<<'CSS'
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
button, input, textarea, select { font: inherit; }

.app-navbar {
    position: fixed; top: 0; left: 0; right: 0; height: var(--navbar-height);
    display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 18px;
    padding: 0 20px; background: rgba(15, 15, 15, 0.96); backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--border); z-index: 1000;
}
.nav-left, .nav-center, .nav-right { display: flex; align-items: center; gap: 14px; }
.nav-center { justify-content: center; }
.nav-right { justify-content: flex-end; }
.brand { display: inline-flex; align-items: center; gap: 9px; font-size: 22px; font-weight: 700; letter-spacing: 0.2px; white-space: nowrap; }
.brand .site-brand-icon { width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex: 0 0 auto; }
.nav-toggle {
    width: 44px; height: 44px; border: 1px solid var(--border); background: transparent; border-radius: 12px;
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
.user-link { display: inline-flex; align-items: center; gap: 10px; }
.user-avatar-circle {
    width: 32px; height: 32px; border-radius: 50%; background: #3ea6ff; color: #ffffff;
    font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;
}
.user-name { color: #ffffff; font-size: 14px; font-weight: 600; white-space: nowrap; }
.logout-link {
    color: #b9b9bb; font-size: 13px; padding-left: 12px; margin-left: 2px;
    border-left: 1px solid #444449; white-space: nowrap;
}
.logout-link:hover { color: #ffffff; }

.app-layout { min-height: 100vh; padding-top: var(--navbar-height); }
.app-sidebar {
    padding: 18px 12px 24px; background: #111111; border-right: 1px solid var(--border);
    overflow-y: auto;
}
.sidebar-top {
    padding: 4px 4px 12px 12px;
}
.sidebar-heading {
    font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase;
    letter-spacing: 0.08em;
}
.sidebar-nav { display: flex; flex-direction: column; gap: 6px; }
.sidebar-link {
    display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px;
    color: var(--text); font-size: 15px; font-weight: 500; transition: background 0.2s ease, color 0.2s ease;
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

.page { max-width: 1100px; margin: 0 auto; padding: 28px 24px 56px; }

.toast {
    position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: var(--surface-2);
    color: #ffffff; padding: 10px 18px; border-radius: 10px; font-size: 14px; display: none; z-index: 1200;
}

@media (max-width: 760px) {
    .app-navbar { grid-template-columns: minmax(0, 1fr) auto; padding: 0 12px; gap: 12px; }
    .nav-left { min-width: 0; }
    .nav-right { gap: 8px; }
    .brand { font-size: 20px; }
    .nav-center { display: none; }
    .create-btn { display: none; }
    .user-name, .logout-link { display: none; }
    .user-badge-pill { padding-right: 6px; }
    .page { padding: 20px 14px 48px; }
}
CSS;
    }
}

if (!function_exists('vs_base_js')) {
    function vs_base_js() {
        return <<<'JS'
function toast(msg) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.style.display = 'block';
    setTimeout(function () { t.style.display = 'none'; }, 3000);
}
JS;
    }
}

if (!function_exists('vs_page_start')) {
    function vs_page_start($title, $active = '', $extraCss = '') {
        $siteName = vs_branding_app_name();
        $defaultTheme = vs_branding_default_theme();
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= $defaultTheme === 'dark' ? '#b02a2a' : '#ffffff' ?>">
    <meta name="vs-default-theme" content="<?= htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="description" content="<?= htmlspecialchars(vs_branding_app_description(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="branding-icon.php">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="theme.js?v=3"></script>
    <style><?= vs_base_css() ?>
<?= $extraCss ?></style>
    <link rel="stylesheet" href="sidebar.css">
    <link rel="stylesheet" href="theme.css?v=3">
</head>
<body>
    <?php render_navbar($active); ?>
    <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="app-layout">
        <?php render_sidebar($active); ?>
        <main class="app-main">
<?php
    }
}

if (!function_exists('vs_page_end')) {
    function vs_page_end($extraJs = '') {
        ?>
        </main>
    </div>
    </div>
    <div class="toast" id="toast"></div>
    <script src="sidebar.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('sw.js').catch(function (error) {
                    console.error('VibeStream offline support could not be enabled.', error);
                });
            });
        }
    </script>
    <script><?= vs_base_js() ?>
<?= $extraJs ?></script>
</body>
</html>
<?php
    }
}