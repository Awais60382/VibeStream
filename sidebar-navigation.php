<?php

require_once __DIR__ . '/sidebar-subscriptions.php';

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
        'shorts' => $svg('<path d="m15 3-7 4a3 3 0 0 0 0 5l8 5a3 3 0 1 1-3 5"/><path d="m9 21 7-4a3 3 0 0 0 0-5L8 7a3 3 0 1 1 3-5"/>'),
        'profile' => $svg('<rect x="4" y="3" width="16" height="18" rx="3"/><circle cx="12" cy="10" r="3"/><path d="M7 19c.8-2.4 2.6-3.6 5-3.6s4.2 1.2 5 3.6"/>'),
        'history' => $svg('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>'),
        'subscriptions' => $svg('<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M6 3h12M9 11l5 3-5 3z"/>'),
        'upload' => $svg('<path d="M12 16V4m-5 5 5-5 5 5"/><path d="M4 16v4h16v-4"/>'),
        'trend' => $svg('<path d="M4 18 10 12l4 4 6-8"/><path d="M14 8h6v6"/>'),
        'admin' => $svg('<path d="M12 2 4 5v6c0 5 3.4 8.8 8 11 4.6-2.2 8-6 8-11V5z"/><path d="m9 12 2 2 4-4"/>'),
        'premium' => $svg('<path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M10 3 8 9l4 12 4-12-2-6"/>'),
    ];

    $canFilterCategories = true;
    if (array_key_exists('hasCategoryColumn', $GLOBALS)) {
        $canFilterCategories = (bool)$GLOBALS['hasCategoryColumn'];
    } elseif (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && function_exists('column_exists')) {
        $canFilterCategories = column_exists($GLOBALS['conn'], 'videos', 'category');
    }

    $trendingCategories = ['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education'];
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
            $color = '';
        }
        $exploreItems[] = [
            'key' => 'category',
            'category' => $slug,
            'label' => $category,
            'href' => 'index.php?' . http_build_query($filterQuery) . '#videos-grid',
            'icon' => 'trend',
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
        ['id' => 'explore', 'title' => 'Explore', 'items' => $exploreItems],
    ];
    if (isset($_SESSION['AdminSession']) || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0)) {
        $sections[] = [
            'id' => 'admin',
            'items' => [
                ['key' => 'adminpanel', 'label' => 'Admin Panel', 'href' => 'adminpanel/index.php', 'icon' => 'admin'],
                ['key' => 'adminpremium', 'label' => 'Premium members', 'href' => 'adminpanel/premium.php', 'icon' => 'premium'],
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
                    echo vs_sidebar_subscriptions_html();
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
            ?>
            <nav class="sidebar-block sidebar-section" aria-label="<?php echo htmlspecialchars($title !== '' ? $title : 'Main'); ?>">
                <?php if ($title !== '' && $titleHref !== ''): ?>
                    <a class="sidebar-block-title" href="<?php echo htmlspecialchars(vs_sidebar_url($titleHref)); ?>">
                        <?php echo htmlspecialchars($title); ?>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                    </a>
                <?php elseif ($title !== ''): ?>
                    <h2 class="sidebar-block-title"><?php echo htmlspecialchars($title); ?></h2>
                <?php endif; ?>
                <?php foreach ($section['items'] as $item):
                    $isActive = ($item['key'] === $active && !($item['key'] === 'home' && ($selectedCategory !== '' || $isTrendingFilter)))
                        || ($item['key'] === 'category' && $active === 'home' && (
                            $canFilterCategories
                                ? $selectedCategory === ($item['category'] ?? '')
                                : $selectedTrend === ($item['category'] ?? '')
                        ));
                    $iconStyle = !empty($item['color']) ? ' style="color: ' . htmlspecialchars($item['color']) . '"' : '';
                ?>
                    <a class="sidebar-link<?php echo $isActive ? ' active' : ''; ?>" href="<?php echo htmlspecialchars(vs_sidebar_url($item['href'])); ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>>
                        <span class="sidebar-icon"<?php echo $iconStyle; ?>><?php echo $icons[$item['icon']]; ?></span>
                        <span class="sidebar-label"><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
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
}