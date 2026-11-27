<?php

require_once __DIR__ . '/site-branding.php';

if (!function_exists('vs_page_loader_assets')) {
    function vs_page_loader_assets(): void
    {
        $cssVersion = (int)filemtime(__DIR__ . '/page-loader.css');
        $jsVersion = (int)filemtime(__DIR__ . '/page-loader.js');
        ?>
<link rel="stylesheet" href="<?= htmlspecialchars(vs_site_url('page-loader.css') . '?v=' . $cssVersion, ENT_QUOTES, 'UTF-8') ?>">
<script>document.documentElement.classList.add('vs-page-loading');</script>
<script src="<?= htmlspecialchars(vs_site_url('page-loader.js') . '?v=' . $jsVersion, ENT_QUOTES, 'UTF-8') ?>" defer></script>
        <?php
    }

    function vs_page_loader_markup(): void
    {
        $state = vs_branding_state();
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $isAdminPage = preg_match('~(?:^|/)adminpanel(?:/|$)~', $scriptName) === 1;
        $logoUrl = $isAdminPage
            ? vs_branding_admin_logo_url($state)
            : vs_branding_logo_url();
        $defaultLogoUrl = vs_site_url(VS_DEFAULT_LOGO_PATH);
        $siteName = vs_branding_app_name($state);
        ?>
<div class="vs-page-loader" id="vsPageLoader" role="status" aria-live="polite" aria-hidden="false">
    <div class="vs-page-loader__mark">
        <span class="vs-page-loader__ring" aria-hidden="true"></span>
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="" onerror="this.onerror=null;this.src='<?= htmlspecialchars($defaultLogoUrl, ENT_QUOTES, 'UTF-8') ?>';">
    </div>
    <span class="vs-page-loader__text">Loading <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
</div>
        <?php
    }
}
