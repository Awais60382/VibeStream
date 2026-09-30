<?php
if (session_status() === PHP_SESSION_NONE) session_start();

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
                            <span class="user-avatar-circle"><?php echo htmlspecialchars($initial); ?></span>
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
    function render_sidebar($active = '') {
        $icons = [
            'home'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 21v-6h6v6"/></svg>',
            'watch'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M10 8.5v7l6-3.5-6-3.5z" fill="currentColor" stroke="none"/></svg>',
            'shorts'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8h16"/><path d="m5 4 3 4M11 4l3 4M17 4l2 4"/><rect x="3" y="8" width="18" height="12" rx="2"/></svg>',
            'upload'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17V4"/><path d="m6 9 6-6 6 6"/><path d="M4 17v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>',
            'profile' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a7 7 0 0 1 7-7h2a7 7 0 0 1 7 7v1"/></svg>',
        ];
        $items = [
            ['key' => 'home',    'label' => 'Home',    'href' => 'index.php'],
            ['key' => 'watch',   'label' => 'Watch',   'href' => 'watch.php'],
            ['key' => 'shorts',  'label' => 'Shorts',  'href' => 'shorts.php'],
            ['key' => 'upload',  'label' => 'Upload',  'href' => 'upload.php'],
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
        </aside>
        <?php
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
.brand { font-size: 22px; font-weight: 700; letter-spacing: 0.2px; white-space: nowrap; }
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
    .app-navbar { padding: 0 12px; gap: 12px; }
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
        ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - VibeStream</title>
    <style><?= vs_base_css() ?>
<?= $extraCss ?></style>
    <link rel="stylesheet" href="sidebar.css">
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
    <script><?= vs_base_js() ?>
<?= $extraJs ?></script>
</body>
</html>
<?php
    }
}