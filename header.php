<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['username']);
$username = $isLoggedIn ? $_SESSION['username'] : '';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/user-menu.php';
require_once __DIR__ . '/site-branding.php';
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
    <link rel="manifest" href="manifest.php">
    <link rel="apple-touch-icon" href="branding-icon.php">
    <title><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <script src="theme.js?v=3"></script>
    <link rel="stylesheet" href="style.css?v=5">
    <link rel="stylesheet" href="theme.css?v=3">
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
            <input type="text" placeholder="Search">
        </div>
        <div class="nav-right" style="display: flex; align-items: center; gap: 12px;">
            <button class="vs-theme-toggle" type="button" data-vs-theme-toggle aria-label="Switch to light mode" title="Switch to light mode">
                <svg class="icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg><svg class="icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            </button>
            <a href="upload.php" class="upload-btn">+ Create</a>
            
            <?php if ($isLoggedIn): ?>
                <?php echo vs_user_menu_html($username, $navAvatar); ?>
            <?php else: ?>
                <a href="login.php" class="upload-btn login-btn">Sign In</a>
            <?php endif; ?>
        </div>
    </header>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register('sw.js').catch(function (error) {
                    console.error('VibeStream offline support could not be enabled.', error);
                });
            });
        }
    </script>