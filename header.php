<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['username']);
$username = $isLoggedIn ? $_SESSION['username'] : '';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/site-branding.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';

// Premium state for premium.js (uses $pdo from connection.php, or default XAMPP settings).
$vsPdo = (isset($pdo) && $pdo instanceof PDO) ? $pdo : null;
if (!$vsPdo) {
    try {
        $vsPdo = new PDO('mysql:host=localhost;dbname=vibestream;charset=utf8mb4', 'root', '');
        $vsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (Throwable $e) {
        $vsPdo = null;
    }
}
$vsPremiumState = vs_premium_state($vsPdo, (string)$username);

$navAvatar = $isLoggedIn ? avatar_url($username) : '';
$siteLogoUrl = vs_branding_logo_url();
$siteName = vs_branding_app_name();
$defaultTheme = vs_branding_default_theme();
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
    <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php
/*
 * Paste this block into header.php, inside <head>, right BEFORE the line:
 *     <script src="theme.js?v=3"></script>
 * (Delete these comments and the opening/closing php tags if you paste it inside an existing PHP block.)
 */
?>
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
<script src="sidebar-icons.js?v=1" defer></script><script src="theme.js?v=3"></script>
    <script>window.VS_PREMIUM_STATE = <?php echo json_encode($vsPremiumState, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
    <script src="premium.js" defer></script>
    <link rel="stylesheet" href="style.css?v=5">
    <link rel="stylesheet" href="theme.css?v=3">
    <link rel="stylesheet" href="navbar-fix.css?v=8">
    <link rel="stylesheet" href="search-suggest.css?v=3">
    <script src="search-suggest.js?v=3" defer></script>
</head>
<body>
    <header class="navbar">
        <div class="nav-left">
            <a class="site-brand" href="index.php">
                <img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl); ?>" alt="">
                <span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        </div>
        <div class="nav-center">
            <div class="vs-search-wrap">
                <div class="vs-search-box">
                    <input type="text" placeholder="Search" id="vs-search-input">
                    <button class="vs-search-btn" type="button" id="vs-search-btn" aria-label="Search" title="Search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
                    </button>
                </div>
                <button class="vs-mic-btn" type="button" id="vs-mic-btn" aria-label="Search with your voice" title="Search with your voice">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/></svg>
                </button>
            </div>
        </div>
        <div class="nav-right" style="display: flex; align-items: center; gap: 12px;">
            <button class="vs-theme-toggle" type="button" data-vs-theme-toggle aria-label="Switch to light mode" title="Switch to light mode">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg><svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="premium.php" class="upload-btn" data-vs-upgrade style="background:linear-gradient(135deg,#f5b942,#ff8a3d);color:#2a1700;">Go Premium</a>
            <a href="upload.php" class="upload-btn">+ Create</a>
            
            <?php if ($isLoggedIn): ?>
                <?php echo vs_user_menu_html($username, $navAvatar); ?>
            <?php else: ?>
                <a href="login.php" class="upload-btn login-btn">Sign In</a>
            <?php endif; ?>
        </div>
    </header>
    <script>
        (function () {
            var input = document.getElementById('vs-search-input');
            var searchBtn = document.getElementById('vs-search-btn');
            var btn = document.getElementById('vs-mic-btn');
            if (!input) { return; }

            function runSearch() {
                if (!input.value.trim()) { input.focus(); return; }
                var form = input.closest('form');
                if (form) { form.requestSubmit ? form.requestSubmit() : form.submit(); return; }
                input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, which: 13, bubbles: true }));
            }
            if (searchBtn) { searchBtn.addEventListener('click', runSearch); }

            var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!btn) { return; }
            var rec = null, listening = false, msgTimer = null;
            function note(text) {
                input.placeholder = text;
                clearTimeout(msgTimer);
                msgTimer = setTimeout(function () { if (!listening) { input.placeholder = 'Search'; } }, 3500);
            }
            function stop() { listening = false; btn.classList.remove('is-listening'); input.placeholder = 'Search'; }
            btn.addEventListener('click', function () {
                if (!SR) { note('Voice search needs Chrome, Edge or Safari'); return; }
                if (listening && rec) { rec.stop(); return; }
                rec = new SR();
                rec.lang = document.documentElement.lang || 'en-US';
                rec.interimResults = true;
                rec.maxAlternatives = 1;
                rec.onstart = function () { listening = true; btn.classList.add('is-listening'); input.placeholder = 'Listening...'; };
                rec.onresult = function (e) {
                    var text = '';
                    for (var i = 0; i < e.results.length; i++) { text += e.results[i][0].transcript; }
                    input.value = text;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                };
                rec.onerror = function (e) {
                    stop();
                    if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { note('Allow microphone access (needs HTTPS)'); }
                    else if (e.error === 'no-speech') { note('No speech heard, try again'); }
                    else { note('Voice search unavailable'); }
                };
                rec.onend = function () { var had = input.value.trim(); stop(); if (had) { runSearch(); } };
                try { rec.start(); } catch (err) { stop(); }
            });
        })();
    </script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('sw.js').catch(function (error) {
                    console.error('VibeStream offline support could not be enabled.', error);
                });
            });
        }
    </script>