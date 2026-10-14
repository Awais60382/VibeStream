<?php

require_once __DIR__ . '/sidebar-subscriptions.php';
require_once __DIR__ . '/category-presets.php';

/**
 * Prefix a relative sidebar link with $GLOBALS['vs_sidebar_base'].
 * Pages in /adminpanel set it to '../' so links still reach the main site.
 */
if (!function_exists('vs_sidebar_url')) {
    function vs_sidebar_url(string $path): string
    {
        $base = (string)($GLOBALS['vs_sidebar_base'] ?? '');
        if ($base === '' || preg_match('#^([a-z][a-z0-9+.-]*:|//|/|\#)#i', $path)) {
            return $path;
        }
        return $base . $path;
    }
}

// Load the shared branding/category store so the sidebar reads the same
// categories that adminpanel/categories.php saves.
$vsBrandingFile = __DIR__ . '/site-branding.php';
if (is_file($vsBrandingFile)) {
    require_once $vsBrandingFile;
}

function vs_render_sidebar(string $active = 'home'): void
{
    $svg = static fn(string $inner): string =>
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';

    $icons = [
        'home' => $svg('<path d="M3 10 12 3l9 7"/><path d="M5 9v12h14V9M9 21v-7h6v7"/>'),
        'watch' => $svg('<rect x="3" y="4" width="18" height="16" rx="3"/><path d="m10 8 6 4-6 4z"/>'),
        'shorts' => $svg('<path d="M17.77 10.32l-1.2-.5L18 9.06a3.74 3.74 0 0 0-3.5-6.62L6 6.94a3.74 3.74 0 0 0 .23 6.74l1.2.49L6 14.93a3.75 3.75 0 0 0 3.5 6.63l8.5-4.5a3.74 3.74 0 0 0-.23-6.74z"/><path d="M10 14.65v-5.3L15 12z" fill="currentColor" stroke="none"/>'),
        'profile' => $svg('<rect x="4" y="3" width="16" height="18" rx="3"/><circle cx="12" cy="10" r="3"/><path d="M7 19c.8-2.4 2.6-3.6 5-3.6s4.2 1.2 5 3.6"/>'),
        'history' => $svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>'),
        'subscriptions' => $svg('<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M6 3h12M9 11l5 3-5 3z"/>'),
        'upload' => $svg('<path d="M12 16V4m-5 5 5-5 5 5"/><path d="M4 16v4h16v-4"/>'),
        'trend' => $svg('<path d="M4 18 10 12l4 4 6-8"/><path d="M14 8h6v6"/>'),
        'admin' => $svg('<path d="M12 2 4 5v6c0 5 3.4 8.8 8 11 4.6-2.2 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>'),
        'premium' => $svg('<path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M10 3 8 9l4 12 4-12-2-6"/>'),
        // Explore categories
        'music' => $svg('<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'),
        'gaming' => $svg('<path d="M6 11h4M8 9v4"/><path d="M15 12h.01M18 10h.01"/><path d="M17.3 5H6.7a4 4 0 0 0-3.9 3.2l-1.3 6.4A3 3 0 0 0 4.4 18c1 0 1.9-.5 2.4-1.3L8 15h8l1.2 1.7c.5.8 1.4 1.3 2.4 1.3a3 3 0 0 0 2.9-3.4l-1.3-6.4A4 4 0 0 0 17.3 5z"/>'),
        'movies' => $svg('<rect x="3" y="9" width="18" height="12" rx="2"/><path d="m3 9 2.5-5 3.5 1.5L6.5 9M9 5.5 12.5 4 15 9M12.5 4l4 1.5L14 9M16.5 5.5 20 7l-1.5 2"/>'),
        'live' => $svg('<circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19.1 4.9a10 10 0 0 1 0 14.2M4.9 19.1a10 10 0 0 1 0-14.2"/>'),
        'sports' => $svg('<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>'),
        'news' => $svg('<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h6v5H7zM16 8h1M16 11h1M7 16h10"/>'),
        'fashion' => $svg('<path d="M8 3 3 6l2 4 3-1v12h8V9l3 1 2-4-5-3a4 4 0 0 1-8 0z"/>'),
        'education' => $svg('<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5M22 9v6"/>'),
        'flag' => $svg('<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>'),
        'tag' => $svg('<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>'),
        'chevron-down' => $svg('<path d="m6 9 6 6 6-6"/>'),
    ];
    // Ready-made category icons (Anime, Facts, Comedy, ...)
    $presets = vs_category_presets();
    foreach ($presets as $presetSlug => $preset) {
        $icons['cat_' . $presetSlug] = $svg($preset[2]);
    }
    // Icons the owner replaced on the admin "Site icons" page
    if (is_file(__DIR__ . '/ui-icons.php')) {
        require_once __DIR__ . '/ui-icons.php';
        foreach (array_keys(vs_ui_icon_all_names()) as $uiName) {
            if (isset($icons[$uiName])) {
                $uiImg = vs_ui_icon_img($uiName, 24, (string)($GLOBALS['vs_sidebar_base'] ?? ''));
                if ($uiImg !== '') {
                    $icons[$uiName] = $uiImg;
                }
            }
        }
    }
    $categoryIconMap = [
        'music' => 'music', 'gaming' => 'gaming', 'games' => 'gaming', 'game' => 'gaming',
        'movies' => 'movies', 'movie' => 'movies', 'film' => 'movies', 'films' => 'movies',
        'live' => 'live', 'sports' => 'sports', 'sport' => 'sports', 'news' => 'news',
        'fashion' => 'fashion', 'education' => 'education', 'learning' => 'education', 'anime' => 'movies',
    ];

    $canFilterCategories = true;
    if (array_key_exists('hasCategoryColumn', $GLOBALS)) {
        $canFilterCategories = (bool)$GLOBALS['hasCategoryColumn'];
    } elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && function_exists('column_exists')) {
        $canFilterCategories = column_exists($GLOBALS['conn'], 'videos', 'category');
    }

    $trendingCategories = ['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education', 'Anime'];
    $categoryColors = [];
    if (function_exists('vs_default_categories')) {
        try {
            $configured = vs_default_categories();
            if ($configured) {
                $trendingCategories = $configured;
            }
        } catch (Throwable $error) {
            // keep the built-in list
        }
    }
    // Colours chosen in Admin > Categories (keyed by lowercase category name)
    if (function_exists('vs_category_colors') && function_exists('vs_branding_state')) {
        try {
            $categoryColors = (array)vs_category_colors(vs_branding_state());
        } catch (Throwable $error) {
            $categoryColors = [];
        }
    }
    $exploreItems = [];
    foreach ($trendingCategories as $category) {
        $slug = strtolower($category);
        $filterQuery = $canFilterCategories ? ['category' => $slug] : ['q' => $slug];
        $color = (string)($categoryColors[mb_strtolower($category)] ?? '');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = isset($presets[$slug]) ? $presets[$slug][1] : '';
        }
        $exploreItems[] = [
            'key' => 'category',
            'category' => $slug,
            'label' => $category,
            'href' => 'index.php?' . http_build_query($filterQuery) . '#videos-grid',
            'icon' => isset($presets[$slug]) ? 'cat_' . $slug : ($categoryIconMap[$slug] ?? 'tag'),
            'color' => $color,
        ];
    }

    // Layout (same order as YouTube): Home/Shorts -> Subscriptions -> You -> Explore
    $sections = [
        [
            'id' => 'main',
            'items' => [
                ['key' => 'home', 'label' => 'Home', 'href' => 'index.php', 'icon' => 'home'],
                ['key' => 'shorts', 'label' => 'Shorts', 'href' => 'shorts.php', 'icon' => 'shorts'],
                ['key' => 'watch', 'label' => 'Watch', 'href' => 'watch.php', 'icon' => 'watch'],
            ],
        ],
        ['id' => 'subscriptions-list'],
        [
            'id' => 'you',
            'title' => 'You',
            'href' => 'profile.php',
            'items' => [
                ['key' => 'profile', 'label' => 'Your Channel', 'href' => 'profile.php', 'icon' => 'profile'],
                ['key' => 'history', 'label' => 'History', 'href' => 'history.php', 'icon' => 'history'],
                ['key' => 'subscriptions', 'label' => 'All subscriptions', 'href' => 'subscriptions.php', 'icon' => 'subscriptions'],
                ['key' => 'upload', 'label' => 'Upload Video', 'href' => 'upload.php', 'icon' => 'upload'],
                ['key' => 'premium', 'label' => 'Premium', 'href' => 'premium.php', 'icon' => 'premium', 'color' => '#f5b942'],
            ],
        ],
        ['id' => 'explore', 'title' => 'Explore', 'items' => $exploreItems, 'collapse' => 3],
    ];
    if (isset($_SESSION['AdminSession']) || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0)) {
        $sections[] = [
            'id' => 'admin',
            'items' => [
                ['key' => 'adminpanel', 'label' => 'Admin Panel', 'href' => 'adminpanel/index.php', 'icon' => 'admin'],
                ['key' => 'adminpremium', 'label' => 'Premium members', 'href' => 'adminpanel/premium.php', 'icon' => 'premium'],
                ['key' => 'adminreports', 'label' => 'Reported content', 'href' => 'adminpanel/reported.php', 'icon' => 'flag'],
                ['key' => 'adminvidcats', 'label' => 'Video categories', 'href' => 'adminpanel/video-categories.php', 'icon' => 'tag'],
            ],
        ];
    }

    $isLoggedIn = !empty($_SESSION['username']);
    $username = $isLoggedIn ? (string)$_SESSION['username'] : '';
    $selectedCategory = $canFilterCategories ? strtolower(trim((string)($_GET['category'] ?? ''))) : '';
    $searchTerm = strtolower(trim((string)($_GET['q'] ?? '')));
    $selectedTrend = $searchTerm !== '' ? $searchTerm : strtolower(trim((string)($_GET['category'] ?? '')));
    $isTrendingFilter = !$canFilterCategories
        && in_array($selectedTrend, array_column($exploreItems, 'category'), true);
    ?>
    <aside class="app-sidebar" id="appSidebar" aria-label="Main navigation">
        <div class="sidebar-top">
            <span class="sidebar-heading">VibeStream</span>
            <button type="button" class="sidebar-close" id="sidebarClose" data-sidebar-close aria-label="Close menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <?php foreach ($sections as $section): ?>
            <?php
            if ($section['id'] === 'subscriptions-list') {
                try {
                    $subsHtml = (string)vs_sidebar_subscriptions_html();
                    if (trim($subsHtml) !== '') {
                        echo '<div class="vs-subs-wrap is-collapsed" data-vs-collapse data-vs-limit="7">' . $subsHtml . '</div>';
                    }
                } catch (Throwable $subscriptionSidebarError) {
                    error_log('Sidebar subscriptions failed: ' . $subscriptionSidebarError->getMessage());
                }
                continue;
            }
            if (empty($section['items'])) {
                continue;
            }
            $title = $section['title'] ?? '';
            $titleHref = $section['href'] ?? '';
            $collapse = (int)($section['collapse'] ?? 0);
            $collapsible = $collapse > 0 && count($section['items']) > $collapse;
            ?>
            <nav class="sidebar-block sidebar-section<?php echo $collapsible ? ' is-collapsed' : ''; ?>"<?php echo $collapsible ? ' data-vs-collapse' : ''; ?> aria-label="<?php echo htmlspecialchars($title !== '' ? $title : 'Main'); ?>">
                <?php if ($title !== '' && $titleHref !== ''): ?>
                    <a class="sidebar-block-title" href="<?php echo htmlspecialchars(vs_sidebar_url($titleHref)); ?>">
                        <?php echo htmlspecialchars($title); ?>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                <?php elseif ($title !== ''): ?>
                    <h2 class="sidebar-block-title"><?php echo htmlspecialchars($title); ?></h2>
                <?php endif; ?>
                <?php foreach ($section['items'] as $itemIndex => $item):
                    $isActive = ($item['key'] === $active && !($item['key'] === 'home' && ($selectedCategory !== '' || $isTrendingFilter)))
                        || ($item['key'] === 'category' && $active === 'home' && (
                            $canFilterCategories
                                ? $selectedCategory === ($item['category'] ?? '')
                                : $selectedTrend === ($item['category'] ?? '')
                        ));
                    $iconStyle = !empty($item['color']) ? ' style="color: ' . htmlspecialchars($item['color']) . '"' : '';
                ?>
                    <a class="sidebar-link<?php echo $isActive ? ' active' : ''; ?><?php echo ($collapsible && $itemIndex >= $collapse) ? ' vs-extra' : ''; ?>" href="<?php echo htmlspecialchars(vs_sidebar_url($item['href'])); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                        <span class="sidebar-icon"<?php echo $iconStyle; ?>><?php echo $icons[$item['icon']]; ?></span>
                        <span class="sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if ($collapsible): ?>
                    <button type="button" class="sidebar-link vs-more-btn" data-vs-more aria-expanded="false">
                        <span class="sidebar-icon"><?php echo $icons['chevron-down']; ?></span>
                        <span class="sidebar-label">Show more</span>
                    </button>
                <?php endif; ?>
            </nav>
        <?php endforeach; ?>
        <a class="sidebar-account" href="<?php echo htmlspecialchars(vs_sidebar_url($isLoggedIn ? 'profile.php' : 'login.php')); ?>">
            <?php if ($isLoggedIn): ?>
                <?php echo avatar_html($username, 34, 'sidebar-account-avatar'); ?>
                <span class="sidebar-account-copy">
                    <strong><?php echo htmlspecialchars($username); ?></strong>
                    <small>Your channel</small>
                </span>
            <?php else: ?>
                <span class="sidebar-account-avatar sidebar-account-initial">G</span>
                <span class="sidebar-account-copy">
                    <strong>Guest</strong>
                    <small>Sign in to VibeStream</small>
                </span>
            <?php endif; ?>
        </a>
    </aside>
    <?php
    vs_sidebar_extras($icons['chevron-down']);
}

/**
 * Styles + script for "Show more / Show less" (Explore and Subscriptions). Printed once per page.
 */
function vs_sidebar_extras(string $chevron): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // Website icon (favicon) for pages whose <head> has none: use the site logo.
    $faviconUrl = '';
    try {
        $faviconUrl = function_exists('vs_branding_logo_url') ? vs_branding_logo_url() : '';
    } catch (Throwable $faviconError) {
        $faviconUrl = '';
    }
    if ($faviconUrl === '') {
        $faviconUrl = vs_sidebar_url('branding-icon.php?size=96');
    }
    ?>
    <style>
    [data-vs-collapse].is-collapsed .vs-extra { display: none !important; }
    .vs-subs-wrap { display: contents; }
    .sidebar-link.vs-more-btn { width: 100%; box-sizing: border-box; border: 0; background: transparent; color: inherit; font: inherit; text-align: left; cursor: pointer; }
    .sidebar-link.vs-more-btn .sidebar-icon svg { transition: transform .15s; }
    .vs-more-btn[aria-expanded="true"] .sidebar-icon svg { transform: rotate(180deg); }

    /* Current section: clearly highlighted in both dark and light themes */
    .app-sidebar .sidebar-link.active {
        background: rgba(255, 255, 255, 0.14) !important;
        color: var(--text, #fff) !important;
        font-weight: 700;
        position: relative;
    }
    .app-sidebar .sidebar-link.active .sidebar-label { font-weight: 700; }
    .app-sidebar .sidebar-link.active::before {
        content: ""; position: absolute; left: 0; top: 8px; bottom: 8px;
        width: 4px; border-radius: 0 4px 4px 0; background: #e53935;
    }
    :root[data-theme="light"] .app-sidebar .sidebar-link.active { background: rgba(0, 0, 0, 0.09) !important; color: #0f0f0f !important; }
    .app-sidebar .sidebar-link.active .sidebar-icon { color: #e53935 !important; }
    </style>
    <script>
    (function () {
      var CHEVRON = <?php echo json_encode($chevron); ?>;
      // Add the website icon when the page has none (shows a globe in the tab otherwise).
      if (!document.querySelector('link[rel~="icon"]')) {
        var fav = document.createElement('link');
        fav.rel = 'icon'; fav.href = <?php echo json_encode($faviconUrl); ?>;
        document.head.appendChild(fav);
      }
      function makeBtn(open) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'sidebar-link vs-more-btn'; b.setAttribute('data-vs-more', '');
        b.setAttribute('aria-expanded', open ? 'true' : 'false');
        b.innerHTML = '<span class="sidebar-icon">' + CHEVRON + '</span><span class="sidebar-label">' + (open ? 'Show less' : 'Show more') + '</span>';
        return b;
      }
      // Subscriptions: keep the first N channels, hide the rest behind "Show more".
      document.querySelectorAll('.vs-subs-wrap').forEach(function (w) {
        if (w.getAttribute('data-vs-ready')) { return; }
        w.setAttribute('data-vs-ready', '1');
        var limit = parseInt(w.getAttribute('data-vs-limit'), 10) || 7;
        var links = [].slice.call(w.querySelectorAll('a')).filter(function (a) {
          return a.querySelector('img, [class*="avatar"], canvas');
        });
        if (links.length <= limit) { w.classList.remove('is-collapsed'); return; }
        var rows = links.map(function (a) { var li = a.closest('li'); return li && w.contains(li) ? li : a; });
        rows.forEach(function (r, i) { if (i >= limit) { r.classList.add('vs-extra'); } });
        var open = rows.slice(limit).some(function (r) { return r.matches('.active, [aria-current]') || r.querySelector('.active, [aria-current]'); });
        w.classList.toggle('is-collapsed', !open);
        var btn = makeBtn(open), last = rows[rows.length - 1], par = last.parentNode, host = btn;
        if (par.tagName === 'UL' || par.tagName === 'OL') { host = document.createElement('li'); host.style.listStyle = 'none'; host.appendChild(btn); }
        par.appendChild(host);
      });
      // Explore: open by default when the selected category is one of the hidden ones.
      document.querySelectorAll('.sidebar-section[data-vs-collapse]').forEach(function (n) {
        if (n.querySelector('.vs-extra.active, .vs-extra[aria-current]')) {
          n.classList.remove('is-collapsed');
          var b = n.querySelector('[data-vs-more]');
          if (b) { b.setAttribute('aria-expanded', 'true'); b.querySelector('.sidebar-label').textContent = 'Show less'; }
        }
      });
      if (window.__vsMoreBound) { return; }
      window.__vsMoreBound = true;
      document.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('[data-vs-more]') : null;
        if (!b) { return; }
        var c = b.closest('[data-vs-collapse]');
        if (!c) { return; }
        var collapsed = c.classList.toggle('is-collapsed');
        b.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        b.querySelector('.sidebar-label').textContent = collapsed ? 'Show more' : 'Show less';
      });
    })();
    </script>
    <?php
}