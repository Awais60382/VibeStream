<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (empty($_SESSION['AdminSession'])) {
    header('Location: ../login.php');
    exit;
}
$tcLiveRequest = isset($_GET['tc_live']) && is_string($_GET['tc_live']) && $_GET['tc_live'] === '1';
// The live fragment must enforce check-in as well as login and route permissions.
require_once __DIR__ . '/checkin-guard.php';
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../csrf.php';

$tcPinnedCreatorKey = '';
$tcPinnedCreatorName = '';
$tcConfigMessage = '';
$tcConfigError = '';

try {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `top_creator_settings` (
            `setting_key` VARCHAR(64) NOT NULL PRIMARY KEY,
            `creator_key` VARCHAR(191) NOT NULL DEFAULT '',
            `creator_name` VARCHAR(191) NOT NULL DEFAULT '',
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tc_pin_action'])) {
        if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $tcConfigError = 'Your session expired. Please try again.';
        } else {
            $tcPinAction = (string)$_POST['tc_pin_action'];
            if ($tcPinAction === 'clear') {
                $clearStmt = $conn->prepare("DELETE FROM `top_creator_settings` WHERE `setting_key` = 'featured_creator'");
                $clearStmt->execute();
                $clearStmt->close();
                $tcConfigMessage = 'Pinned Top Creator removed.';
            } elseif ($tcPinAction === 'pin') {
                $creatorName = mb_substr(trim((string)($_POST['pinned_creator'] ?? '')), 0, 191);
                $creatorKey = mb_strtolower($creatorName, 'UTF-8');
                if ($creatorName === '') {
                    $tcConfigError = 'Select a creator to pin.';
                } else {
                    $videoColumns = [];
                    $videoColumnResult = $conn->query('SHOW COLUMNS FROM `videos`');
                    while ($videoColumn = $videoColumnResult->fetch_assoc()) {
                        $videoColumns[strtolower((string)$videoColumn['Field'])] = (string)$videoColumn['Field'];
                    }
                    $videoColumnResult->close();
                    $creatorExpressions = [];
                    foreach (['username', 'channel_name', 'uploader'] as $candidate) {
                        if (isset($videoColumns[$candidate])) {
                            $column = '`' . str_replace('`', '``', $videoColumns[$candidate]) . '`';
                            $creatorExpressions[] = $column;
                        }
                    }
                    $creatorExists = false;
                    if ($creatorExpressions) {
                        $coalesced = 'COALESCE(' . implode(', ', array_map(static fn(string $column): string => 'NULLIF(TRIM(CAST(' . $column . ' AS CHAR)), \'\')', $creatorExpressions)) . ')';
                        $existsStmt = $conn->prepare('SELECT 1 FROM `videos` WHERE LOWER(TRIM(CAST(' . $coalesced . ' AS CHAR))) = ? LIMIT 1');
                        $existsStmt->bind_param('s', $creatorKey);
                        $existsStmt->execute();
                        $creatorExists = (bool)$existsStmt->get_result()->fetch_row();
                        $existsStmt->close();
                    }
                    if (!$creatorExists) {
                        $tcConfigError = 'That creator could not be found in the video rankings.';
                    } else {
                        $upsertStmt = $conn->prepare(
                            "INSERT INTO `top_creator_settings` (`setting_key`, `creator_key`, `creator_name`)
                             VALUES ('featured_creator', ?, ?)
                             ON DUPLICATE KEY UPDATE `creator_key` = VALUES(`creator_key`), `creator_name` = VALUES(`creator_name`)"
                        );
                        $upsertStmt->bind_param('ss', $creatorKey, $creatorName);
                        $upsertStmt->execute();
                        $upsertStmt->close();
                        $tcConfigMessage = $creatorName . ' is now the pinned Top Creator.';
                    }
                }
            }
        }
    }

    $settingsResult = $conn->query(
        "SELECT `creator_key`, `creator_name` FROM `top_creator_settings`
         WHERE `setting_key` = 'featured_creator' LIMIT 1"
    );
    if ($settingsResult) {
        if ($settingsRow = $settingsResult->fetch_assoc()) {
            $tcPinnedCreatorKey = (string)$settingsRow['creator_key'];
            $tcPinnedCreatorName = (string)$settingsRow['creator_name'];
        }
        $settingsResult->close();
    }
} catch (Throwable $configError) {
    error_log('Top creator configuration could not be loaded or saved: ' . $configError->getMessage());
    $tcConfigError = 'Pinned Top Creator settings are unavailable right now.';
}

$sortOptions = [
    'views' => 'By Views',
    'subscribers' => 'By Subscribers',
    'likes' => 'By Likes',
    'videos' => 'By Videos',
    'growth' => 'Fastest View Growth',
    'engagement' => 'By Engagement Rate',
];
$periodOptions = [
    'day' => 'Last 24 hours',
    'week' => 'Last 7 days',
    'month' => 'Last 30 days',
    'all' => 'All time',
];
// Ignore array-shaped query parameters instead of emitting conversion warnings.
$getString = static fn(string $key, string $default = ''): string =>
    isset($_GET[$key]) && is_string($_GET[$key]) ? $_GET[$key] : $default;
$sort = $getString('sort', 'views');
if (!isset($sortOptions[$sort])) {
    $sort = 'views';
}
$period = $getString('period', 'all');
if (!isset($periodOptions[$period])) {
    $period = 'all';
}
$search = trim($getString('q'));
$search = mb_substr($search, 0, 80);
$creators = [];
$pageError = '';
$viewHistoryAvailable = false;
$subscriberHistoryAvailable = false;
$category = mb_strtolower(trim($getString('category')), 'UTF-8');
$categoryOptions = [];
$creatorCategoryMap = [];
$premiumStatusAvailable = false;
$premiumByCreator = [];

try {
    $videoColumns = [];
    $columnResult = $conn->query('SHOW COLUMNS FROM `videos`');
    while ($column = $columnResult->fetch_assoc()) {
        $videoColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }
    $columnResult->close();

    $quoteColumn = static fn(string $column): string =>
        '`' . str_replace('`', '``', $column) . '`';
    $ownerColumns = [];
    foreach (['username', 'channel_name', 'uploader'] as $candidate) {
        if (isset($videoColumns[$candidate])) {
            $ownerColumns[] = 'NULLIF(TRIM(CAST(' . $quoteColumn($videoColumns[$candidate]) . ' AS CHAR)), \'\')';
        }
    }
    if (!$ownerColumns) {
        throw new RuntimeException('The videos table does not have a creator name column.');
    }
    $ownerExpression = count($ownerColumns) === 1 ? $ownerColumns[0] : 'COALESCE(' . implode(', ', $ownerColumns) . ')';
    $viewsExpression = isset($videoColumns['views'])
        ? 'COALESCE(SUM(' . $quoteColumn($videoColumns['views']) . '), 0)'
        : '0';
    $likesExpression = isset($videoColumns['likes'])
        ? 'COALESCE(SUM(' . $quoteColumn($videoColumns['likes']) . '), 0)'
        : '0';
    $storedSubscribersExpression = isset($videoColumns['subscribers'])
        ? 'COALESCE(MAX(' . $quoteColumn($videoColumns['subscribers']) . '), 0)'
        : '0';

    $sql = 'SELECT LOWER(' . $ownerExpression . ') AS creator_key, MAX(' . $ownerExpression . ') AS creator, '
        . 'COUNT(*) AS video_count, ' . $viewsExpression . ' AS total_views, '
        . $likesExpression . ' AS total_likes, ' . $storedSubscribersExpression . ' AS stored_subscribers '
        . 'FROM `videos` WHERE ' . $ownerExpression . ' IS NOT NULL '
        . 'GROUP BY LOWER(' . $ownerExpression . ')';
    $result = $conn->query($sql);
    $creators = $result->fetch_all(MYSQLI_ASSOC);
    // Offer category filtering only when the videos schema has a usable category label.
    $categoryColumn = null;
    foreach (['category_name', 'category', 'genre'] as $candidate) {
        if (isset($videoColumns[$candidate])) { $categoryColumn = $videoColumns[$candidate]; break; }
    }
    if ($categoryColumn !== null) {
        $categoryExpression = 'NULLIF(TRIM(CAST(' . $quoteColumn($categoryColumn) . ' AS CHAR)), \'\')';
        $categorySql = 'SELECT LOWER(' . $ownerExpression . ') AS creator_key, ' . $categoryExpression . ' AS category_name '
            . 'FROM `videos` WHERE ' . $ownerExpression . ' IS NOT NULL AND ' . $categoryExpression . ' IS NOT NULL '
            . 'GROUP BY LOWER(' . $ownerExpression . '), ' . $categoryExpression;
        $categoryResult = $conn->query($categorySql);
        while ($row = $categoryResult->fetch_assoc()) {
            $creatorKey = (string)$row['creator_key'];
            $categoryName = trim((string)$row['category_name']);
            if ($categoryName === '') { continue; }
            $categoryKey = mb_strtolower($categoryName, 'UTF-8');
            $creatorCategoryMap[$creatorKey][$categoryKey] = $categoryName;
            $categoryOptions[$categoryKey] = $categoryName;
        }
        $categoryResult->close();
        natcasesort($categoryOptions);
        foreach ($creators as &$creator) {
            $creatorCategories = $creatorCategoryMap[(string)$creator['creator_key']] ?? [];
            $creator['category_keys'] = array_keys($creatorCategories);
        }
        unset($creator);
    }
    if ($category !== '' && !isset($categoryOptions[$category])) { $category = ''; }
    $result->close();

    $subscriptionTable = $conn->query(
        "SELECT COUNT(*) AS table_count FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name = 'user_subscriptions'"
    );
    $hasSubscriptionTable = (int)($subscriptionTable->fetch_assoc()['table_count'] ?? 0) > 0;
    $subscriptionTable->close();
    if ($hasSubscriptionTable) {
        $subscriptionColumns = [];
        $subscriptionColumnResult = $conn->query('SHOW COLUMNS FROM `user_subscriptions`');
        while ($column = $subscriptionColumnResult->fetch_assoc()) {
            $subscriptionColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
        }
        $subscriptionColumnResult->close();

        $channelColumns = [];
        foreach (['channel_username', 'channel_name'] as $candidate) {
            if (isset($subscriptionColumns[$candidate])) {
                $column = $quoteColumn($subscriptionColumns[$candidate]);
                $channelColumns[] = 'NULLIF(TRIM(CAST(' . $column . ' AS CHAR)), \'\')';
            }
        }
        if ($channelColumns) {
            $channelExpression = count($channelColumns) === 1 ? $channelColumns[0] : 'COALESCE(' . implode(', ', $channelColumns) . ')';
            $hasSubscriptionDate = isset($subscriptionColumns['created_at']);
            $subscriberSql = 'SELECT LOWER(' . $channelExpression . ') AS creator_key, COUNT(*) AS subscriber_count';
            if ($hasSubscriptionDate) {
                $createdAt = $quoteColumn($subscriptionColumns['created_at']);
                $subscriberSql .= ', SUM(' . $createdAt . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS subscribers_7d'
                    . ', SUM(' . $createdAt . ' >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND '
                    . $createdAt . ' < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS subscribers_previous_7d';
            }
            $subscriberSql .= ' '
                . 'FROM `user_subscriptions` WHERE ' . $channelExpression . ' IS NOT NULL '
                . 'GROUP BY LOWER(' . $channelExpression . ')';
            $subscriberResult = $conn->query($subscriberSql);
            $subscriberCounts = [];
            while ($row = $subscriberResult->fetch_assoc()) {
                $subscriberCounts[(string)$row['creator_key']] = [
                    'count' => (int)$row['subscriber_count'],
                    'current' => (int)($row['subscribers_7d'] ?? 0),
                    'previous' => (int)($row['subscribers_previous_7d'] ?? 0),
                ];
            }
            $subscriberResult->close();
            foreach ($creators as &$creator) {
                $key = (string)$creator['creator_key'];
                $subscriptionMetrics = $subscriberCounts[$key] ?? null;
                $creator['subscriber_count'] = $subscriptionMetrics['count'] ?? 0;
                $creator['subscribers_7d'] = $subscriptionMetrics['current'] ?? 0;
                $creator['subscribers_previous_7d'] = $subscriptionMetrics['previous'] ?? 0;
            }
            unset($creator);
            $subscriberHistoryAvailable = $hasSubscriptionDate;
        }
    }

    $viewTableResult = $conn->query(
        "SELECT COUNT(*) AS table_count FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name = 'video_view_events'"
    );
    $hasViewEventsTable = (int)($viewTableResult->fetch_assoc()['table_count'] ?? 0) > 0;
    $viewTableResult->close();
    if ($hasViewEventsTable) {
        $viewColumns = [];
        $viewColumnResult = $conn->query('SHOW COLUMNS FROM `video_view_events`');
        while ($column = $viewColumnResult->fetch_assoc()) {
            $viewColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
        }
        $viewColumnResult->close();
        if (isset($viewColumns['creator'], $viewColumns['viewed_at'])) {
            $eventCreator = $quoteColumn($viewColumns['creator']);
            $eventDate = $quoteColumn($viewColumns['viewed_at']);
            $viewSql = 'SELECT LOWER(TRIM(' . $eventCreator . ')) AS creator_key, '
                . 'SUM(' . $eventDate . ' >= DATE_SUB(NOW(), INTERVAL 1 DAY)) AS views_1d, '
                . 'SUM(' . $eventDate . ' >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS views_7d, '
                . 'SUM(' . $eventDate . ' >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND '
                . $eventDate . ' < DATE_SUB(NOW(), INTERVAL 7 DAY)) AS views_previous_7d, '
                . 'SUM(' . $eventDate . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS views_30d '
                . 'FROM `video_view_events` WHERE TRIM(' . $eventCreator . ') <> \'\' AND '
                . $eventDate . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY) '
                . 'GROUP BY LOWER(TRIM(' . $eventCreator . '))';
            $viewResult = $conn->query($viewSql);
            $viewCounts = [];
            while ($row = $viewResult->fetch_assoc()) {
                $viewCounts[(string)$row['creator_key']] = [
                    'day' => (int)$row['views_1d'],
                    'current' => (int)$row['views_7d'],
                    'previous' => (int)$row['views_previous_7d'],
                    'month' => (int)$row['views_30d'],
                ];
            }
            $viewResult->close();
            foreach ($creators as &$creator) {
                $viewMetrics = $viewCounts[(string)$creator['creator_key']] ?? null;
                $creator['views_1d'] = $viewMetrics['day'] ?? 0;
                $creator['views_7d'] = $viewMetrics['current'] ?? 0;
                $creator['views_previous_7d'] = $viewMetrics['previous'] ?? 0;
                $creator['views_30d'] = $viewMetrics['month'] ?? 0;
            }
            unset($creator);
            $viewHistoryAvailable = true;
        }
    }
} catch (Throwable $error) {
    error_log('Top creators page could not load creator rankings: ' . $error->getMessage());
    $pageError = 'Creator rankings are unavailable right now. Please try again later.';
}
try {
    $premiumTable = $conn->query(
        "SELECT COUNT(*) AS table_count FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name = 'premium_members'"
    );
    $hasPremiumTable = (int)($premiumTable->fetch_assoc()['table_count'] ?? 0) > 0;
    $premiumTable->close();
    if ($hasPremiumTable) {
        $premiumResult = $conn->query(
            "SELECT LOWER(TRIM(username)) AS creator_key, plan FROM premium_members "
            . "WHERE status = 'active' AND expires_at > NOW()"
        );
        while ($premiumRow = $premiumResult->fetch_assoc()) {
            $premiumByCreator[(string)$premiumRow['creator_key']] =
                strpos((string)$premiumRow['plan'], 'plus') === 0 ? 'Plus' : 'Premium';
        }
        $premiumResult->close();
        $premiumStatusAvailable = true;
    }
} catch (Throwable $premiumError) {
    error_log('Top creators premium status could not be loaded: ' . $premiumError->getMessage());
}

// Missing history must not masquerade as measured zero views.
$historyNotice = '';
if ($pageError === '' && !$viewHistoryAvailable) {
    if ($period !== 'all') {
        $period = 'all';
        $historyNotice = 'View history is not configured. Showing all-time views instead.';
    }
    if ($sort === 'growth') {
        $sort = 'views';
        $historyNotice = 'View growth is unavailable. Ranked by all-time views instead.';
    }
}
$sortColumns = [
    'views' => 'ranking_views',
    'subscribers' => 'subscriber_count',
    'likes' => 'total_likes',
    'videos' => 'video_count',
    'growth' => 'view_growth',
    'engagement' => 'engagement_rate',
];
$sortColumn = $sortColumns[$sort];
foreach ($creators as &$creator) {
    if (!isset($creator['subscriber_count'])) {
        $creator['subscriber_count'] = (int)($creator['stored_subscribers'] ?? 0);
    }
    foreach (['video_count', 'total_views', 'total_likes', 'subscriber_count', 'views_1d', 'views_7d', 'views_previous_7d', 'views_30d', 'subscribers_7d', 'subscribers_previous_7d'] as $countKey) {
        $creator[$countKey] = (int)($creator[$countKey] ?? 0);
    }
    $creator['view_growth'] = $creator['views_7d'] - $creator['views_previous_7d'];
    $creator['subscriber_growth'] = $creator['subscribers_7d'] - $creator['subscribers_previous_7d'];
    $creator['ranking_views'] = match ($period) {
        'day' => $creator['views_1d'],
        'week' => $creator['views_7d'],
        'month' => $creator['views_30d'],
        default => $creator['total_views'],
    };
    $creator['engagement_rate'] = $creator['total_views'] > 0
        ? ($creator['total_likes'] / $creator['total_views']) * 100
        : 0.0;
    $creator['estimated_payout'] = ($creator['ranking_views'] / 100000) * 1000;
    $creator['premium_tier'] = $premiumStatusAvailable
        ? ($premiumByCreator[(string)$creator['creator_key']] ?? 'Free')
        : 'Unavailable';
    $creator['milestones'] = [];
    if ($creator['total_views'] >= 1000000) { $creator['milestones'][] = 'views'; }
    if ($creator['subscriber_count'] >= 1000000) { $creator['milestones'][] = 'subscribers'; }
    if ($creator['total_likes'] >= 1000000) { $creator['milestones'][] = 'likes'; }
}
unset($creator);
if ($category !== '') {
    $creators = array_values(array_filter($creators, static fn(array $creator): bool =>
        in_array($category, $creator['category_keys'] ?? [], true)
    ));
}
usort($creators, static function (array $left, array $right) use ($sortColumn): int {
    return ($right[$sortColumn] <=> $left[$sortColumn])
        ?: ($right['total_views'] <=> $left['total_views'])
        ?: strcasecmp((string)$left['creator'], (string)$right['creator']);
});

$tcRankSnapshot = [];
foreach ($creators as $index => &$creator) {
    $creator['global_rank'] = $index + 1;
    $tcRankSnapshot[] = ['key' => (string)$creator['creator_key'], 'rank' => $index + 1];
}
unset($creator);
$tcPinCreatorOptions = $creators;
$tcGeneratedAt = gmdate('c');
if ($search !== '') {
    $needle = mb_strtolower($search);
    $creators = array_values(array_filter($creators, static fn(array $creator): bool =>
        mb_stripos((string)$creator['creator'], $needle) !== false
    ));
}
if ($getString('export') === 'csv') {
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if ($pageError !== '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $pageError;
        exit;
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="top-creators-' . gmdate('Y-m-d') . '.csv"');
    $csv = fopen('php://output', 'w');
    fwrite($csv, "\xEF\xBB\xBF");
    fputcsv($csv, ['Rank', 'Creator', 'Premium status', 'Current subscribers', 'Views (' . $periodOptions[$period] . ')', 'Estimated payout (PKR)', 'Engagement rate (%)', '1M views milestone', '1M subscribers milestone', '1M likes milestone', 'All-time likes', 'All-time videos', 'Views last 7d', 'Views previous 7d', 'Generated UTC'], ',', '"', '');
    foreach ($creators as $row) {
        $csvName = (string)$row['creator'];
        if (preg_match('/^[\s\x00-\x20]*[=+@-]/u', $csvName)) { $csvName = "'" . $csvName; }
        fputcsv($csv, [$row['global_rank'], $csvName, $row['premium_tier'], $row['subscriber_count'], $row['ranking_views'], number_format($row['estimated_payout'], 2, '.', ''), number_format($row['engagement_rate'], 2, '.', ''), in_array('views', $row['milestones'], true) ? 'Yes' : 'No', in_array('subscribers', $row['milestones'], true) ? 'Yes' : 'No', in_array('likes', $row['milestones'], true) ? 'Yes' : 'No', $row['total_likes'], $row['video_count'], $viewHistoryAvailable ? $row['views_7d'] : 'Unavailable', $viewHistoryAvailable ? $row['views_previous_7d'] : 'Unavailable', $tcGeneratedAt], ',', '"', '');
    }
    fclose($csv);
    exit;
}
// Overview totals include every matching creator, not just this page.
// Build genuine per-day series from timestamped events, scoped to the filtered creator set.
$trendLabels = [];
$trendDays = [];
for ($dayOffset = 29; $dayOffset >= 0; $dayOffset--) {
    $dayKey = date('Y-m-d', strtotime('-' . $dayOffset . ' days'));
    $trendDays[] = $dayKey;
    $trendLabels[] = date('M j', strtotime($dayKey));
}
$viewTrend = array_fill_keys($trendDays, 0);
$subscriberTrend = array_fill_keys($trendDays, 0);
$trendAvailable = ['views' => false, 'subscribers' => false];
$trendCreatorKeys = array_fill_keys(array_map(static fn(array $creator): string => (string)$creator['creator_key'], $creators), true);
try {
    if ($viewHistoryAvailable && isset($viewColumns['creator'], $viewColumns['viewed_at'])) {
        $trendCreator = $quoteColumn($viewColumns['creator']);
        $trendDate = $quoteColumn($viewColumns['viewed_at']);
        $trendSql = 'SELECT DATE(' . $trendDate . ') AS trend_day, LOWER(TRIM(' . $trendCreator . ')) AS creator_key, COUNT(*) AS total '
            . 'FROM `video_view_events` WHERE ' . $trendDate . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND ' . $trendDate . ' <= NOW() '
            . 'AND TRIM(' . $trendCreator . ') <> \'\' GROUP BY DATE(' . $trendDate . '), LOWER(TRIM(' . $trendCreator . '))';
        $trendResult = $conn->query($trendSql);
        while ($row = $trendResult->fetch_assoc()) {
            $dayKey = (string)$row['trend_day'];
            if (array_key_exists($dayKey, $viewTrend) && isset($trendCreatorKeys[(string)$row['creator_key']])) {
                $viewTrend[$dayKey] += (int)$row['total'];
            }
        }
        $trendResult->close();
        $trendAvailable['views'] = true;
    }
} catch (Throwable $trendError) {
    error_log('Top creators view trend could not be loaded: ' . $trendError->getMessage());
}
try {
    if ($subscriberHistoryAvailable && isset($subscriptionColumns['created_at'], $channelExpression)) {
        $trendDate = $quoteColumn($subscriptionColumns['created_at']);
        $trendSql = 'SELECT DATE(' . $trendDate . ') AS trend_day, LOWER(' . $channelExpression . ') AS creator_key, COUNT(*) AS total '
            . 'FROM `user_subscriptions` WHERE ' . $trendDate . ' >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND ' . $trendDate . ' <= NOW() '
            . 'AND ' . $channelExpression . ' IS NOT NULL GROUP BY DATE(' . $trendDate . '), LOWER(' . $channelExpression . ')';
        $trendResult = $conn->query($trendSql);
        while ($row = $trendResult->fetch_assoc()) {
            $dayKey = (string)$row['trend_day'];
            if (array_key_exists($dayKey, $subscriberTrend) && isset($trendCreatorKeys[(string)$row['creator_key']])) {
                $subscriberTrend[$dayKey] += (int)$row['total'];
            }
        }
        $trendResult->close();
        $trendAvailable['subscribers'] = true;
    }
} catch (Throwable $trendError) {
    error_log('Top creators subscriber trend could not be loaded: ' . $trendError->getMessage());
}
$tcTopCreators = array_slice($creators, 0, 3);
$tcFeaturedLead = null;
if ($tcPinnedCreatorKey !== '') {
    foreach ($creators as $creatorCandidate) {
        if ((string)$creatorCandidate['creator_key'] === $tcPinnedCreatorKey) {
            $tcFeaturedLead = $creatorCandidate;
            break;
        }
    }
}
$summary = ['creators' => count($creators), 'views' => 0, 'subscribers' => 0, 'likes' => 0, 'videos' => 0];
foreach ($creators as $creatorSummary) {
    $summary['views'] += $creatorSummary['ranking_views'];
    $summary['subscribers'] += $creatorSummary['subscriber_count'];
    $summary['likes'] += $creatorSummary['total_likes'];
    $summary['videos'] += $creatorSummary['video_count'];
}
unset($creatorSummary);
$milestoneCounts = ['views' => 0, 'subscribers' => 0, 'likes' => 0];
foreach ($creators as $creator) {
    foreach ($creator['milestones'] as $milestone) {
        $milestoneCounts[$milestone]++;
    }
}

// Pagination
$perPage = 10;
$totalCreators = count($creators);
$totalPages = max(1, (int)ceil($totalCreators / $perPage));
$currentPage = (int)$getString('page', '1');
$currentPage = min(max(1, $currentPage), $totalPages);
$offset = ($currentPage - 1) * $perPage;
$pageCreators = array_slice($creators, $offset, $perPage);
$showingFrom = $totalCreators > 0 ? $offset + 1 : 0;
$showingTo = $offset + count($pageCreators);
$creatorAlertCounts = ['no_recent_views' => 0, 'declining_views' => 0];
if ($viewHistoryAvailable) {
    foreach ($creators as $creator) {
        if ($creator['views_7d'] === 0) {
            $creatorAlertCounts['no_recent_views']++;
        } elseif ($creator['view_growth'] < 0) {
            $creatorAlertCounts['declining_views']++;
        }
    }
}
$topVideosAvailable = isset($videoColumns['views'], $videoColumns['title']) || isset($videoColumns['views'], $videoColumns['name']);
if ($topVideosAvailable && $pageError === '') {
    $videoTitleColumn = $videoColumns['title'] ?? $videoColumns['name'];
    $videoIdColumn = $videoColumns['id'] ?? $videoColumns['video_id'] ?? null;
    $videoLikesColumn = $videoColumns['likes'] ?? null;
    foreach ($pageCreators as &$pageCreator) {
        $pageCreator['top_videos'] = [];
        try {
            $videoSelect = $quoteColumn($videoTitleColumn) . ' AS video_title, '
                . ($videoIdColumn !== null ? $quoteColumn($videoIdColumn) : "''") . ' AS video_id, '
                . $quoteColumn($videoColumns['views']) . ' AS video_views, '
                . ($videoLikesColumn !== null ? $quoteColumn($videoLikesColumn) : '0') . ' AS video_likes';
            $videoSql = 'SELECT ' . $videoSelect . ' FROM `videos` WHERE LOWER(' . $ownerExpression . ') = ? '
                . 'ORDER BY ' . $quoteColumn($videoColumns['views']) . ' DESC LIMIT 3';
            $videoStatement = $conn->prepare($videoSql);
            $creatorKey = (string)$pageCreator['creator_key'];
            $videoStatement->bind_param('s', $creatorKey);
            $videoStatement->execute();
            $videoResult = $videoStatement->get_result();
            while ($video = $videoResult->fetch_assoc()) {
                $pageCreator['top_videos'][] = [
                    'title' => (string)($video['video_title'] ?? 'Untitled'),
                    'id' => (string)($video['video_id'] ?? ''),
                    'views' => (int)($video['video_views'] ?? 0),
                    'likes' => (int)($video['video_likes'] ?? 0),
                ];
            }
            $videoResult->free();
            $videoStatement->close();
        } catch (Throwable $videoError) {
            error_log('Top creator videos could not be loaded: ' . $videoError->getMessage());
            $topVideosAvailable = false;
            break;
        }
    }
    unset($pageCreator);
}

$buildUrl = static function (array $overrides) use ($sort, $search, $period, $category): string {
    $params = array_merge(['sort' => $sort, 'period' => $period, 'q' => $search, 'category' => $category, 'page' => 1], $overrides);
    if ($params['q'] === '') {
        unset($params['q']);
    }
    if ($params['period'] === 'all') {
        unset($params['period']);
    }
    if ($params['category'] === '') {
        unset($params['category']);
    }
    if ((int)$params['page'] <= 1) {
        unset($params['page']);
    }
    return 'top-creators.php' . ($params ? '?' . http_build_query($params) : '');
};

$formatCompact = static function (int $n): string {
    if ($n >= 1000000000) {
        return rtrim(rtrim(number_format($n / 1000000000, 1), '0'), '.') . 'B';
    }
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string)$n;
};

$icons = [
    'eye' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>',
    'users' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="8" r="3.6"/><path d="M2 20c0-3.6 3.1-6 7-6s7 2.4 7 6z"/><circle cx="17.5" cy="9" r="2.6" opacity=".75"/><path d="M17.5 14c2.9 0 4.9 1.8 4.9 4.6V20H18c0-2.2-.6-4.2-2-5.4.5-.4 1.1-.6 1.5-.6z" opacity=".75"/></svg>',
    'like' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 10h4v11H2zM8 10l4-7c1.7 0 3 1.3 3 3v3h5.2c1.3 0 2.2 1.2 1.9 2.4l-1.8 8c-.2 1-1.1 1.6-2 1.6H8z"/></svg>',
    'video' => '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="2" y="4" width="20" height="16" rx="4"/><path d="M10 8.5v7l6-3.5z" fill="#14102e"/></svg>',
    'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/></svg>',
    'star' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l2.9 6.6 7.1.6-5.4 4.7 1.6 7-6.2-3.7-6.2 3.7 1.6-7L2 9.2l7.1-.6z"/></svg>',
    'chart' => '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="12" width="4" height="9" rx="1"/><rect x="10" y="4" width="4" height="17" rx="1"/><rect x="17" y="9" width="4" height="12" rx="1"/></svg>',
    'info' => '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/><path d="M11 10h2v7h-2zM11 7h2v2h-2z" fill="#14102e"/></svg>',
    'crown' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M2 7l5 4 5-7 5 7 5-4-2 12H4z"/><circle cx="2" cy="7" r="1.6"/><circle cx="12" cy="4" r="1.6"/><circle cx="22" cy="7" r="1.6"/></svg>',
];

$extraCss = <<<'CSS'
.tc-admin { --tc-bg:#f5f3ff; --tc-glow:rgba(124,58,237,.10); --tc-panel:#ffffff; --tc-panel-2:#f3efff; --tc-chip:#ffffff; --tc-line:rgba(124,58,237,.28); --tc-row-line:rgba(124,58,237,.12); --tc-text:#1e1b3a; --tc-head:#2a2552; --tc-muted:#6b6894; --tc-icon:#5b4bb5; --tc-hover:rgba(124,58,237,.06); --tc-violet:#7c3aed; --tc-violet-2:#a855f7; --tc-blue:#3b82f6; --tc-shadow:0 12px 30px rgba(76,29,149,.10); --tc-hero-bg:radial-gradient(520px 240px at 88% 50%, rgba(167,139,250,.55), transparent 70%), radial-gradient(400px 200px at 0 100%, rgba(244,114,182,.18), transparent 70%), linear-gradient(120deg, #ede9fe, #f5f3ff 60%, #e9e2ff); --tc-hero-title:#1e1b3a; --tc-hero-sub:#5b5785; --tc-panel-bg:linear-gradient(180deg, #ffffff, #faf8ff); --tc-gold-row:linear-gradient(90deg, rgba(245,158,11,.20), rgba(245,158,11,.05)); --tc-silver-row:linear-gradient(90deg, rgba(148,163,184,.20), transparent); --tc-bronze-row:linear-gradient(90deg, rgba(217,119,6,.14), transparent); --tc-silver:#64748b; --tc-gold:#d97706; --tc-bronze:#c2570c; --tc-on-active-shadow:0 6px 18px rgba(124,58,237,.35); }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-admin { --tc-bg:#0d0a26; --tc-glow:rgba(124,58,237,.22); --tc-panel:#15103a; --tc-panel-2:#1c1650; --tc-chip:rgba(21,16,58,.85); --tc-line:rgba(138,92,255,.32); --tc-row-line:rgba(138,92,255,.16); --tc-text:#f4f2ff; --tc-head:#e6e2ff; --tc-muted:#a9a3d6; --tc-icon:#d9d4ff; --tc-hover:rgba(124,58,237,.10); --tc-shadow:0 16px 40px rgba(0,0,0,.30); --tc-hero-bg:radial-gradient(520px 240px at 88% 50%, rgba(139,92,246,.55), transparent 70%), radial-gradient(400px 200px at 0 100%, rgba(236,72,153,.18), transparent 70%), linear-gradient(120deg, #1a1250, #0f0b30 60%, #1c1060); --tc-hero-title:#ffffff; --tc-hero-sub:#d7d2ff; --tc-panel-bg:linear-gradient(180deg, rgba(28,22,80,.9), rgba(17,12,46,.95)); --tc-gold-row:linear-gradient(90deg, rgba(245,158,11,.22), rgba(245,158,11,.07)); --tc-silver-row:linear-gradient(90deg, rgba(148,163,184,.14), transparent); --tc-bronze-row:linear-gradient(90deg, rgba(217,119,6,.16), transparent); --tc-silver:#cbd5e1; --tc-gold:#fbbf24; --tc-bronze:#f59e42; --tc-on-active-shadow:0 0 22px rgba(168,85,247,.55); }
.tc-admin { background: radial-gradient(900px 400px at 100% 0, var(--tc-glow), transparent 70%), var(--tc-bg); border-radius: 20px; color: var(--tc-text); transition: background .2s ease, color .2s ease; }
.tc-page { max-width: 1280px; margin: 0 auto; padding: 8px 0 24px; }
.tc-icon { display: inline-flex; width: 1.1em; height: 1.1em; flex: 0 0 auto; }
.tc-icon svg { width: 100%; height: 100%; }

/* Hero */
.tc-hero { position: relative; display: flex; align-items: center; gap: 26px; overflow: hidden; min-height: 170px; padding: 26px 34px; border: 1px solid var(--tc-line); border-radius: 22px; background: var(--tc-hero-bg); box-shadow: var(--tc-shadow); }
.tc-hero-badge { display: grid; flex: 0 0 auto; place-items: center; width: 108px; height: 108px; border: 1px solid rgba(196,160,255,.45); border-radius: 28px; background: linear-gradient(145deg, rgba(168,85,247,.9), rgba(79,38,190,.9)); box-shadow: 0 0 34px rgba(168,85,247,.45), inset 0 0 20px rgba(255,255,255,.12); color: #fff; }
.tc-hero-badge .tc-icon { width: 58px; height: 58px; filter: drop-shadow(0 0 10px rgba(255,255,255,.6)); }
.tc-hero-text { position: relative; z-index: 1; min-width: 0; }
.tc-hero h1 { margin: 0 0 8px; color: var(--tc-hero-title); font-size: clamp(32px, 5vw, 54px); font-weight: 800; line-height: 1.05; }
.tc-hero h1 span { background: linear-gradient(90deg, #a855f7, #7c3aed); -webkit-background-clip: text; background-clip: text; color: transparent; }
.tc-hero p { margin: 0; color: var(--tc-hero-sub); font-size: 17px; }
.tc-trophy { position: absolute; top: 50%; right: 4%; width: clamp(140px, 22vw, 260px); transform: translateY(-50%); filter: drop-shadow(0 0 22px rgba(168,85,247,.5)); pointer-events: none; }

/* Toolbar */
.tc-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin: 22px 0 18px; }
.tc-pin-form { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin:-8px 0 18px; padding:12px; border:1px solid var(--tc-line); border-radius:14px; background:var(--tc-panel); }
.tc-pin-form label { font-size:12px; font-weight:800; color:var(--tc-muted); }
.tc-pin-form select { min-width:220px; height:40px; border:1px solid var(--tc-line); border-radius:10px; background:var(--tc-panel-2); color:var(--tc-text); padding:0 10px; }
.tc-pin-form button { height:40px; border:0; border-radius:10px; padding:0 14px; background:#7c3aed; color:#fff; font-weight:800; cursor:pointer; }
.tc-pin-form .tc-pin-clear { background:transparent; border:1px solid var(--tc-line); color:var(--tc-text); }
.tc-pin-success,.tc-pin-error { flex-basis:100%; font-size:12px; }
.tc-pin-success { color:#4ade80; }
.tc-pin-error { color:#fb7185; }
.tc-tabs { display: flex; flex-wrap: wrap; gap: 12px; }
.tc-tab { display: inline-flex; align-items: center; gap: 10px; min-height: 52px; padding: 0 22px; border: 1px solid var(--tc-line); border-radius: 999px; background: var(--tc-chip); color: var(--tc-text); font-weight: 600; text-decoration: none; transition: all .18s ease; }
.tc-tab .tc-icon { width: 22px; height: 22px; }
.tc-tab:hover { border-color: var(--tc-violet-2); color: var(--tc-text); }
.tc-tab.is-active, .tc-tab.is-active:hover { border-color: transparent; background: linear-gradient(100deg, #7c3aed, #a855f7); box-shadow: var(--tc-on-active-shadow); color: #fff; }
.tc-period-controls { display: flex; flex: 1 1 100%; align-items: center; flex-wrap: wrap; gap: 8px; }
.tc-period-label { margin-right: 4px; color: var(--tc-muted); font-size: 13px; font-weight: 600; }
.tc-period { display: inline-flex; align-items: center; min-height: 36px; padding: 0 14px; border: 1px solid var(--tc-line); border-radius: 999px; background: var(--tc-chip); color: var(--tc-text); font-size: 13px; font-weight: 600; text-decoration: none; transition: all .18s ease; }
.tc-period:hover { border-color: var(--tc-violet-2); color: var(--tc-text); }
.tc-period.is-active, .tc-period.is-active:hover { border-color: transparent; background: linear-gradient(100deg, #7c3aed, #a855f7); box-shadow: var(--tc-on-active-shadow); color: #fff; }
.tc-period-note { flex: 1 1 100%; margin: -4px 0 0; color: var(--tc-muted); font-size: 12px; }
.tc-period-warning { padding: 12px 16px; border: 1px solid rgba(245,158,11,.35); border-radius: 12px; background: rgba(245,158,11,.1); color: var(--tc-text); font-size: 13px; }
.tc-search { display: flex; align-items: center; gap: 10px; width: min(360px, 100%); min-height: 52px; padding: 0 18px; border: 1px solid var(--tc-line); border-radius: 999px; background: var(--tc-chip); color: var(--tc-muted); }
.tc-search input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--tc-text); font-size: 15px; }
.tc-search input::placeholder { color: var(--tc-muted); }

/* Table */
.tc-panel { overflow: hidden; border: 1px solid var(--tc-line); border-radius: 20px; background: var(--tc-panel-bg); box-shadow: var(--tc-shadow); }
.tc-scroll { overflow-x: auto; }
.tc-table { width: 100%; min-width: 1040px; margin: 0; border-collapse: collapse; color: var(--tc-text); --bs-table-bg: transparent; --bs-table-color: var(--tc-text); }
.tc-table th { padding: 20px 14px; border: 0; background: transparent; color: var(--tc-head); font-size: 15px; font-weight: 600; text-align: center; white-space: nowrap; }
.tc-table th.tc-left, .tc-table td.tc-left { text-align: left; }
.tc-table td { padding: 12px 14px; border: 0; border-top: 1px solid var(--tc-row-line); background: transparent; color: var(--tc-text); text-align: center; vertical-align: middle; font-size: 17px; }
.tc-table tbody tr { transition: background .15s ease; }
.tc-table tbody tr:hover { background: var(--tc-hover); }
.tc-rank-cell { width: 84px; padding-left: 6px; }
.tc-rank { display: inline-flex; flex-direction: column; align-items: center; justify-content: center; width: 64px; min-height: 54px; font-size: 22px; font-weight: 800; line-height: 1; }
.tc-rank .tc-icon { width: 38px; height: 34px; }
.tc-rank small { margin-top: 1px; font-size: 15px; font-weight: 800; }
.tc-rank.r1 { color: var(--tc-gold); filter: drop-shadow(0 0 6px rgba(251,191,36,.5)); }
.tc-rank.r2 { color: var(--tc-silver); }
.tc-rank.r3 { color: var(--tc-bronze); }
tr.tc-row-1, tr.tc-row-1:hover { background: var(--tc-gold-row); box-shadow: inset 0 0 0 1.5px rgba(251,191,36,.75); }
tr.tc-row-2, tr.tc-row-2:hover { background: var(--tc-silver-row); }
tr.tc-row-3, tr.tc-row-3:hover { background: var(--tc-bronze-row); }
.tc-creator { display: flex; align-items: center; gap: 16px; min-width: 0; }
.tc-avatar { display: grid; flex: 0 0 auto; width: 62px; height: 62px; place-items: center; overflow: hidden; border: 2px solid rgba(168,85,247,.55); border-radius: 50%; color: #fff; font-size: 24px; font-weight: 800; object-fit: cover; box-shadow: 0 0 14px rgba(124,58,237,.3); }
.tc-name { min-width: 0; }
.tc-name strong { display: block; max-width: 240px; overflow: hidden; color: var(--tc-text); font-size: 19px; text-overflow: ellipsis; white-space: nowrap; }
.tc-name span { display: block; max-width: 240px; overflow: hidden; color: var(--tc-muted); font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
.tc-metric { display: inline-flex; align-items: center; justify-content: center; gap: 10px; }
.tc-metric .tc-icon { width: 22px; height: 22px; color: var(--tc-icon); }
.tc-metric.is-sorted, .is-sorted { font-weight: 700; }
.tc-growth { display: flex; flex-direction: column; align-items: center; gap: 4px; min-width: 128px; font-size: 13px; font-weight: 700; font-variant-numeric: tabular-nums; }
.tc-growth .up { color: #16a34a; }
.tc-growth .down { color: #e11d48; }
.tc-growth .flat, .tc-growth small { color: var(--tc-muted); }
.tc-growth small { font-size: 11px; font-weight: 500; }
.tc-actions { display: inline-flex; align-items: center; gap: 10px; }
.tc-view { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 18px; border: 1px solid #6d4df0; border-radius: 12px; background: linear-gradient(180deg, #4b2fd0, #3a22a8); color: #fff; font-size: 15px; font-weight: 600; text-decoration: none; white-space: nowrap; transition: all .18s ease; }
.tc-view:hover { background: linear-gradient(180deg, #6b46f2, #4b2fd0); box-shadow: 0 0 18px rgba(124,58,237,.5); color: #fff; }
.tc-more { display: inline-grid; place-items: center; width: 44px; height: 44px; border: 1px solid var(--tc-line); border-radius: 50%; background: transparent; color: var(--tc-text); font-size: 20px; letter-spacing: 1px; text-decoration: none; }
.tc-more:hover { border-color: var(--tc-violet-2); color: var(--tc-text); }

/* Footer */
.tc-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px 24px; border-top: 1px solid var(--tc-row-line); color: var(--tc-muted); }
.tc-foot .tc-info { display: inline-flex; align-items: center; gap: 10px; }
.tc-foot .tc-info .tc-icon { color: var(--tc-blue); width: 22px; height: 22px; }
.tc-pager { display: flex; align-items: center; gap: 8px; }
.tc-pager a, .tc-pager span { display: inline-grid; place-items: center; min-width: 40px; height: 40px; padding: 0 10px; border: 1px solid var(--tc-line); border-radius: 10px; background: var(--tc-chip); color: var(--tc-text); font-weight: 700; text-decoration: none; }
.tc-pager a:hover { border-color: var(--tc-violet-2); }
.tc-pager .is-current { border-color: transparent; background: linear-gradient(100deg, #7c3aed, #a855f7); color: #fff; box-shadow: var(--tc-on-active-shadow); }
.tc-pager .is-disabled { opacity: .4; pointer-events: none; }
.tc-error, .tc-empty { padding: 40px 26px; color: var(--tc-muted); text-align: center; }

@media (max-width: 760px) {
  .tc-hero { gap: 16px; padding: 20px; }
  .tc-hero-badge { width: 72px; height: 72px; border-radius: 20px; }
  .tc-hero-badge .tc-icon { width: 38px; height: 38px; }
  .tc-hero p { font-size: 14px; }
  .tc-trophy { opacity: .35; right: -2%; }
  .tc-tab { min-height: 44px; padding: 0 16px; font-size: 14px; }
  .tc-period { min-height: 34px; padding: 0 11px; font-size: 12px; }
  .tc-search { width: 100%; }
}
/* Refined dashboard surfaces and hierarchy. */
.tc-admin { border-radius:24px; --tc-line:rgba(124,58,237,.16); --tc-shadow:0 4px 24px rgba(44,30,85,.045); }
.tc-admin *, .tc-admin *::before, .tc-admin *::after { box-sizing:border-box; }
.tc-page { max-width:1440px; }
.tc-hero { min-height:180px; border-radius:20px; padding:32px; gap:22px; box-shadow:none; }
.tc-hero h1 { font-size:clamp(30px,4vw,46px); letter-spacing:-.045em; line-height:1.15; }
.tc-hero h1 span { background:none; color:var(--tc-violet); }
.tc-hero p { font-size:15px; max-width:480px; line-height:1.65; }
.tc-hero-badge { width:76px; height:76px; border-radius:22px; box-shadow:0 10px 24px rgba(124,58,237,.16); }
.tc-hero-badge .tc-icon { width:38px; height:38px; filter:none; }
.tc-hero-meta { display:block; margin-top:14px; font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--tc-hero-sub); }
.tc-trophy { width:175px; right:4%; opacity:.65; filter:none; }
.tc-hero-text { padding-right:150px; }
.tc-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin:22px 0; }
.tc-stat { padding:20px 22px; border:1px solid var(--tc-line); border-radius:16px; background:var(--tc-panel); box-shadow:var(--tc-shadow); }
.tc-stat-top { display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:13px; font-weight:600; color:var(--tc-muted); }
.tc-stat-top .tc-icon { width:32px; height:32px; padding:7px; border-radius:10px; color:var(--tc-violet); background:var(--tc-panel-2); }
.tc-stat strong { display:block; margin:12px 0 4px; color:var(--tc-text); font-size:30px; font-weight:750; line-height:1.15; letter-spacing:-.035em; font-variant-numeric:tabular-nums; }
.tc-stat small { color:var(--tc-muted); font-size:12px; }
.tc-toolbar { justify-content:flex-start; gap:12px; margin:24px 0 18px; }
.tc-tabs { flex:1 1 100%; gap:6px; padding:5px; border:1px solid var(--tc-line); border-radius:14px; background:var(--tc-panel); }
.tc-tab { min-height:42px; padding:0 16px; border:0; border-radius:10px; background:transparent; font-size:13px; gap:8px; }
.tc-tab .tc-icon { width:17px; height:17px; }
.tc-tab.is-active, .tc-period.is-active { background:var(--tc-violet); box-shadow:0 2px 8px rgba(124,58,237,.14); }
.tc-period-controls { gap:7px; padding:4px 0; }
.tc-period-note { margin:5px 0 0; line-height:1.7; max-width:850px; }
.tc-search { width:min(350px,100%); min-height:44px; border-radius:10px; padding:0 14px; }
.tc-search input { font-size:14px; }
.tc-submit { min-height:44px; border:1px solid transparent; border-radius:10px; padding:0 20px; background:var(--tc-violet); color:#fff; font:inherit; font-size:13px; font-weight:700; cursor:pointer; }
.tc-submit:hover { background:#6d28d9; }
.tc-reset { color:var(--tc-muted); font-size:13px; text-underline-offset:3px; }
.tc-panel { border-radius:16px; background:var(--tc-panel); }
.tc-panel-heading { display:flex; align-items:center; justify-content:space-between; gap:14px; padding:22px 24px; border-bottom:1px solid var(--tc-row-line); }
.tc-panel-heading h2 { margin:0; color:var(--tc-text); font-size:18px; font-weight:750; letter-spacing:-.02em; }
.tc-panel-heading p { margin:6px 0 0; color:var(--tc-muted); font-size:12px; }
.tc-count { border:1px solid var(--tc-line); border-radius:999px; padding:6px 11px; font-size:11px; font-weight:650; color:var(--tc-muted); white-space:nowrap; }
.tc-table { min-width:1450px; }
.tc-table th { padding:15px 14px; background:var(--tc-panel-2); font-size:11px; text-transform:uppercase; letter-spacing:.045em; color:var(--tc-muted); }
.tc-table td { padding:16px 14px; font-size:14px; font-variant-numeric:tabular-nums; }
.tc-rank { width:42px; min-height:42px; font-size:16px; }
.tc-rank .tc-icon { width:25px; height:23px; }
.tc-rank small { font-size:11px; }
.tc-rank.r1 { filter:none; }
tr.tc-row-1, tr.tc-row-1:hover { box-shadow:inset 3px 0 0 var(--tc-gold); }
.tc-avatar { width:44px; height:44px; border-width:1px; box-shadow:none; font-size:17px; }
.tc-creator { gap:12px; }
.tc-name strong { font-size:14px; font-weight:700; max-width:200px; }
.tc-name span { font-size:12px; margin-top:3px; max-width:200px; }
.tc-metric { gap:7px; }
.tc-metric .tc-icon { width:16px; height:16px; opacity:.8; }
.tc-view { min-height:36px; padding:0 12px; font-size:12px; border-radius:8px; background:var(--tc-panel-2); color:var(--tc-text); border-color:var(--tc-line); }
.tc-view:hover { background:var(--tc-violet); box-shadow:none; color:#fff; }
.tc-view .tc-icon { width:14px; height:14px; }
.tc-foot { padding:16px 22px; font-size:12px; }
.tc-foot .tc-info .tc-icon { width:16px; height:16px; }
.tc-pager { flex-wrap:wrap; gap:5px; }
.tc-pager a, .tc-pager span { min-width:32px; height:32px; border-radius:7px; font-size:12px; padding:0 8px; }
.tc-pager .is-current { background:var(--tc-violet); box-shadow:none; }
.tc-pager .tc-ellipsis { border:0; background:transparent; min-width:18px; padding:0; }
.tc-period-warning { margin:14px 18px; line-height:1.7; font-size:12px; }
.tc-admin :is(a,button,input,[tabindex]):focus-visible { outline:3px solid var(--tc-violet-2); outline-offset:3px; }
.tc-search:focus-within { border-color:var(--tc-violet); box-shadow:0 0 0 3px var(--tc-glow); }
.tc-sr-only { position:absolute; width:1px; height:1px; padding:0; margin:-1px; overflow:hidden; clip:rect(0,0,0,0); white-space:nowrap; border:0; }
@media (max-width:1000px) { .tc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:600px) {
 .tc-hero { min-height:0; padding:22px; gap:14px; align-items:flex-start; }
 .tc-hero-text { padding-right:0; }
 .tc-hero-badge { width:46px; height:46px; border-radius:14px; }
 .tc-hero-badge .tc-icon { width:25px; height:25px; }
 .tc-hero h1 { font-size:30px; }
 .tc-hero p { font-size:13px; }
 .tc-hero-meta { font-size:9px; }
 .tc-trophy { display:none; }
 .tc-stats { gap:10px; margin:16px 0; }
 .tc-stat { padding:16px 14px; }
 .tc-stat strong { font-size:26px; }
 .tc-stat-top { font-size:11px; }
 .tc-tab { padding:0 10px; min-height:40px; font-size:12px; }
 .tc-search { flex:1 1 180px; width:auto; }
 .tc-panel-heading { padding:18px 16px; align-items:flex-start; }
 .tc-panel-heading h2 { font-size:16px; }
 .tc-count { font-size:10px; padding:5px 8px; }
 .tc-foot { padding:14px 16px; }
 .tc-pager { width:100%; justify-content:center; }
}
@media (prefers-reduced-motion:reduce) { .tc-admin, .tc-admin * { transition:none!important; scroll-behavior:auto!important; } }
/* Screenshot-inspired neon theme; local to the creator page. */
body:has(.tc-admin), .dashboard-content:has(.tc-admin) { background:#03010f; color:#f7f3ff; }
.dashboard-content:has(.tc-admin) > .container-fluid { padding:0!important; }
.tc-admin.tc-admin { --tc-bg:#03010f; --tc-panel:#0b061b; --tc-panel-2:#120929; --tc-chip:#0b061b; --tc-text:#f8f6ff; --tc-head:#dad1ff; --tc-muted:#b7abed; --tc-line:rgba(135,72,255,.44); --tc-row-line:rgba(145,110,215,.19); --tc-violet:#9000ff; --tc-violet-2:#c147ff; --tc-glow:rgba(151,0,255,.18); --tc-icon:#c8b4ff; --tc-gold:#ffda54; --tc-silver:#d9dcff; --tc-bronze:#ffad55; --tc-hover:rgba(137,43,255,.08); border:1px solid var(--tc-line); border-right:0; border-bottom:0; border-radius:20px 0 0 0; padding:0 26px 24px!important; background:radial-gradient(ellipse at 70% 0,rgba(67,0,170,.12),transparent 50%),#03010f; color:var(--tc-text); font-family:Inter,ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif; }
.tc-page { position:relative; max-width:1600px; padding:0; display:flex; flex-wrap:wrap; align-items:center; gap:18px 12px; }
.tc-hero { order:0; width:calc(100% + 52px); flex:none; margin:0 -26px; min-height:146px; padding:28px 32px; border:0; border-radius:19px 0 0 0; background:radial-gradient(ellipse at 75% 22%,rgba(159,0,255,.5),transparent 34%),repeating-linear-gradient(136deg,transparent 0 36px,rgba(117,0,255,.14) 37px 55px,transparent 56px 88px),linear-gradient(110deg,#060215 30%,#1d0053 76%,#080116); box-shadow:none; isolation:isolate; }
.tc-hero::after { content:''; position:absolute; z-index:-1; inset:0; background:linear-gradient(136deg,transparent 57%,rgba(184,48,255,.7) 57.1%,transparent 57.4%,transparent 65%,rgba(145,0,255,.5) 65.1%,transparent 65.4%); pointer-events:none; }
.tc-hero-badge { width:86px; height:86px; border:0; border-radius:0; background:none; color:#b32dff; box-shadow:none; }
.tc-hero-badge .tc-icon { width:76px; height:76px; filter:drop-shadow(0 0 14px rgba(151,0,255,.55)); }
.tc-hero h1 { font-size:clamp(30px,3.2vw,48px); letter-spacing:-.035em; color:#fff; font-weight:800; margin-bottom:6px; }
.tc-hero h1 span { color:#fff; }
.tc-hero p { color:#e9e1ff; font-size:16px; line-height:1.5; max-width:540px; }
.tc-hero-text { padding-right:0; max-width:68%; }
.tc-hero-meta { display:none; }
.tc-trophy { width:220px; right:13%; opacity:.95; transform:translateY(-48%) rotate(-9deg); filter:drop-shadow(0 0 20px #9c00ff); }
.tc-slogan { position:absolute; right:28px; top:34px; transform:rotate(-6deg); color:white; font-size:18px; font-weight:750; }
.tc-slogan::after { content:''; display:block; height:10px; margin:6px 0 0 50px; border-top:3px solid #b73aff; border-radius:50%; }
.tc-toolbar { display:contents; }
.tc-tabs { order:1; display:flex; flex:1 1 auto; gap:10px; padding:0; border:0; border-radius:0; background:transparent; }
.tc-tab { min-height:46px; padding:0 17px; border:1px solid var(--tc-line); border-radius:12px; font-size:13px; background:linear-gradient(180deg,#100823,#080414); color:#f8f6ff; white-space:nowrap; }
.tc-tab .tc-icon { width:19px; height:19px; }
.tc-tab.is-active,.tc-tab.is-active:hover,.tc-period.is-active,.tc-period.is-active:hover { background:linear-gradient(120deg,#ac00ff,#7300ff); border-color:#a01aff; box-shadow:0 0 17px rgba(136,0,255,.4),inset 0 1px 0 rgba(255,255,255,.13); color:#fff; }
.tc-tab:hover,.tc-period:hover { background:#200b42; border-color:#c147ff; }
.tc-period-controls { order:1; flex:0 1 auto; display:flex; flex-wrap:nowrap; gap:0; padding:0; margin-left:auto; }
.tc-period-label { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); }
.tc-period { min-height:46px; padding:0 15px; border-radius:0; font-size:12px; background:#0d061f; color:#e9e1ff; white-space:nowrap; }
.tc-period:first-of-type { border-radius:12px 0 0 12px; }
.tc-period:last-of-type { margin-left:8px; border-radius:12px; }
.tc-period-note { display:none; }
.tc-stats { order:2; flex:0 0 100%; margin:0 0 4px; gap:18px; }
.tc-stat { position:relative; min-height:120px; overflow:hidden; padding:18px 20px 20px 88px; border-radius:14px; border:1px solid var(--stat-color); background:radial-gradient(ellipse at 95% 100%,var(--stat-glow),transparent 60%),linear-gradient(120deg,var(--stat-tint),#08031a); box-shadow:inset 0 0 22px var(--stat-shade); isolation:isolate; }
.tc-stat::after { content:''; position:absolute; z-index:-1; left:-8%; right:-8%; bottom:-39px; height:60px; border-top:2px solid var(--stat-color); border-radius:45% 65% 0 0 / 60% 85% 0 0; background:linear-gradient(180deg,var(--stat-glow),transparent); transform:rotate(-4deg); box-shadow:0 -3px 15px var(--stat-glow); }
.tc-stat::before { content:''; position:absolute; z-index:-1; width:70%; height:50px; bottom:-37px; left:-12%; border-top:2px solid var(--stat-color); border-radius:50%; transform:rotate(14deg); }
.tc-stat-views { --stat-color:#9300ff; --stat-glow:rgba(156,0,255,.65); --stat-tint:#13004b; --stat-shade:rgba(113,0,255,.13); --stat-icon:#c7a0ff; }
.tc-stat-subscribers { --stat-color:#2649ff; --stat-glow:rgba(0,128,255,.65); --stat-tint:#00123c; --stat-shade:rgba(0,80,255,.13); --stat-icon:#1dc7ff; }
.tc-stat-likes { --stat-color:#d01398; --stat-glow:rgba(255,0,153,.6); --stat-tint:#320726; --stat-shade:rgba(255,0,153,.1); --stat-icon:#ff52b6; }
.tc-stat-videos { --stat-color:#007c66; --stat-glow:rgba(0,255,148,.5); --stat-tint:#032a27; --stat-shade:rgba(0,255,148,.1); --stat-icon:#00f39b; }
.tc-stat-top { font-size:14px; font-weight:550; color:#f2ecff; }
.tc-stat-top .tc-icon { position:absolute; left:20px; top:32px; width:46px; height:46px; padding:0; border-radius:0; color:var(--stat-icon); background:none; filter:drop-shadow(0 0 9px var(--stat-glow)); }
.tc-stat strong { font-size:clamp(25px,2.4vw,36px); color:#fff; margin:6px 0 2px; font-weight:750; letter-spacing:-.025em; }
.tc-stat small { font-size:10px; color:#c8c0e2; }
.tc-search { order:3; flex:1 1 500px; width:auto; min-height:50px; border-radius:12px; padding:0 18px; background:linear-gradient(100deg,#0d071e,#0c071b); color:#c4adff; }
.tc-search .tc-icon { width:23px; height:23px; }
.tc-search input { font-size:14px; padding:10px 0; color:#fff; }
.tc-submit { order:3; min-height:50px; background:linear-gradient(120deg,#a000ff,#7200ff); border:1px solid #9528ef; border-radius:12px; box-shadow:0 0 12px rgba(138,0,255,.18); }
.tc-reset { order:3; color:#d4bdff; }
.tc-panel { order:4; flex:0 0 100%; border-radius:14px; background:linear-gradient(115deg,#0b061a,#0b051e); overflow:visible; box-shadow:none; }
.tc-panel-heading { padding:12px 17px; border-bottom:1px solid var(--tc-row-line); }
.tc-panel-heading h2 { font-size:13px; color:#ded4ff; }
.tc-panel-heading p { font-size:11px; line-height:1.5; color:#aca0ce; margin-top:4px; }
.tc-count { color:#cfc1f4; font-size:10px; background:#100824; }
.tc-scroll { padding:0 12px; border-radius:13px; scrollbar-width:thin; scrollbar-color:#6c21ad #0c061b; }
.tc-table { min-width:1000px; border-collapse:separate; border-spacing:0; }
.tc-table th { padding:16px 10px 13px; background:transparent; font-size:11px; letter-spacing:.02em; color:#d1c5f4; font-weight:650; }
.tc-table td { padding:9px 10px; height:60px; font-size:15px; color:#f6f2ff; }
.tc-table th.tc-rank-cell,.tc-table td.tc-rank-cell { width:70px; }
.tc-table tbody tr:hover { background:rgba(136,53,255,.08); }
tr.tc-row-1,tr.tc-row-1:hover { background:linear-gradient(90deg,rgba(255,203,50,.12),rgba(255,203,50,.025)); box-shadow:none; filter:drop-shadow(0 0 4px rgba(255,200,36,.15)); }
tr.tc-row-1 td { border-top:1px solid #e2b83f; border-bottom:1px solid #e2b83f; }
tr.tc-row-1 td:first-child { border-left:1px solid #e2b83f; border-radius:11px 0 0 11px; box-shadow:inset 3px 0 10px -6px #ffdd64; }
tr.tc-row-1 td:last-child { border-right:1px solid #e2b83f; border-radius:0 11px 11px 0; }
tr.tc-row-2,tr.tc-row-3 { background:transparent; }
.tc-rank { display:inline-flex; flex-direction:row; gap:6px; width:auto; min-width:38px; min-height:36px; font-size:16px; font-weight:650; }
.tc-rank .tc-icon { width:25px; height:25px; }
.tc-rank small { font-size:14px; font-weight:500; }
.tc-avatar { width:44px; height:44px; border:1px solid #bf37ff; box-shadow:0 0 10px rgba(133,0,255,.27); font-size:24px; font-weight:500; }
.tc-name strong { font-size:15px; max-width:205px; color:#fff; }
.tc-name span { font-size:11px; color:#cec1ed; }
.tc-metric .tc-icon { display:none; }
.tc-growth { min-width:105px; gap:4px; font-size:12px; }
.tc-growth .up,.tc-growth .down,.tc-growth .flat { display:inline-flex; align-items:center; border-radius:99px; padding:4px 9px; background:rgba(0,218,124,.14); color:#29ff9e; border:1px solid rgba(0,231,126,.22); white-space:nowrap; }
.tc-growth .up::before { content:'↑'; margin-right:4px; font-weight:800; }
.tc-growth .down { background:rgba(255,67,118,.12); color:#ff7e9c; border-color:rgba(255,67,118,.25); }
.tc-growth .down::before { content:'↓'; margin-right:4px; }
.tc-growth .flat { background:rgba(150,130,190,.08); border-color:rgba(150,130,190,.2); color:#b8abd3; }
.tc-growth small { font-size:9px; color:#aa9bc9; }
.tc-view { min-height:37px; padding:0 12px; border:1px solid #a31dff; color:#fff; background:linear-gradient(120deg,#ab00ff,#6b00ff); box-shadow:0 0 12px rgba(133,0,255,.24); font-size:12px; border-radius:9px; }
.tc-view:hover { background:linear-gradient(120deg,#bc28ff,#8b22ff); box-shadow:0 0 20px rgba(148,0,255,.45); }
.tc-view .tc-icon { width:18px; height:18px; }
.tc-foot { background:#050211; margin:0 -1px -1px; padding:18px 0 0; border-top:0; color:#b7a8e5; border-radius:0; font-size:12px; }
.tc-foot .tc-info .tc-icon { display:none; }
.tc-pager a,.tc-pager span { width:36px; height:38px; border-radius:9px; background:#0e0722; border-color:rgba(135,72,255,.22); color:#d8caff; }
.tc-pager .is-current { background:linear-gradient(120deg,#9d00ff,#6900ff); box-shadow:0 0 15px rgba(127,0,255,.3); color:white; }
.tc-period-warning { margin:12px; padding:10px 13px; border-color:rgba(189,137,255,.24); color:#cfc1e9; background:rgba(119,47,188,.08); font-size:11px; }
body:has(.tc-admin) .admin-footer { background:#03010f; color:#b7abcf; border-color:#2d154c; font-size:11px; }
@media (min-width:1500px) { .tc-tab { padding:0 20px; font-size:14px; } }
@media (max-width:1250px) { .tc-period-controls { flex:1 1 100%; justify-content:flex-end; } .tc-trophy { right:7%; width:170px; } .tc-slogan { display:none; } .tc-stat { padding-left:65px; padding-right:12px; } .tc-stat-top .tc-icon { left:15px; width:35px; height:35px; } }
@media (max-width:1000px) { .tc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; } .tc-tabs { flex-wrap:wrap; } .tc-period-controls { justify-content:flex-start; } .tc-hero-text { max-width:75%; } .tc-hero p { font-size:14px; } .tc-trophy { right:2%; opacity:.55; } }
@media (max-width:600px) { .tc-admin.tc-admin { padding:0 12px 18px!important; border-radius:16px 0 0 0; } .tc-page { gap:14px 8px; } .tc-hero { width:calc(100% + 24px); margin:0 -12px; padding:24px 14px; gap:12px; min-height:126px; align-items:center; } .tc-hero-badge { width:48px; height:60px; } .tc-hero-badge .tc-icon { width:48px; height:48px; } .tc-hero-text { max-width:calc(100% - 60px); } .tc-hero h1 { font-size:30px; } .tc-hero p { font-size:12px; } .tc-trophy { display:none; } .tc-tabs { flex-wrap:nowrap; overflow:auto; flex:1 1 100%; padding-bottom:4px; gap:7px; scrollbar-width:thin; } .tc-tab { min-height:40px; padding:0 12px; font-size:12px; flex-shrink:0; } .tc-period { min-height:38px; padding:0 12px; font-size:11px; } .tc-stat { min-height:112px; padding:14px 10px 18px 51px; border-radius:11px; } .tc-stat-top { font-size:11px; } .tc-stat-top .tc-icon { top:30px; left:12px; width:29px; height:29px; } .tc-stat strong { font-size:26px; } .tc-stat small { font-size:9px; } .tc-search { flex:1 1 160px; min-height:46px; padding:0 12px; } .tc-search input { font-size:12px; } .tc-submit { min-height:46px; padding:0 14px; font-size:12px; } .tc-panel-heading { padding:12px; } .tc-panel-heading p { font-size:10px; } .tc-count { display:none; } .tc-scroll { padding:0 6px; } .tc-pager { width:auto; justify-content:flex-end; } .tc-pager a,.tc-pager span { width:30px; min-width:30px; height:32px; } }
/* Light mode explicitly overrides the screenshot's hardcoded dark surfaces. */
html[data-theme="light"] body:has(.tc-admin),html[data-theme="light"] .dashboard-content:has(.tc-admin) { background:#f6f4fc; color:#241b39; }
html[data-theme="light"] .tc-admin.tc-admin { --tc-bg:#f6f4fc; --tc-panel:#fff; --tc-panel-2:#f1ebfc; --tc-chip:#fff; --tc-text:#241b39; --tc-head:#39274e; --tc-muted:#70617f; --tc-line:rgba(124,58,237,.22); --tc-row-line:rgba(112,67,166,.13); --tc-violet:#7c3aed; --tc-violet-2:#9547dd; --tc-icon:#6e37a7; --tc-gold:#946000; --tc-silver:#626d83; --tc-bronze:#a35a20; background:radial-gradient(ellipse at 70% 0,rgba(164,105,242,.08),transparent 50%),var(--tc-bg); color:var(--tc-text); }
html[data-theme="light"] .tc-hero { background:radial-gradient(ellipse at 75% 22%,rgba(172,115,250,.28),transparent 40%),repeating-linear-gradient(136deg,transparent 0 36px,rgba(143,75,220,.05) 37px 55px,transparent 56px 88px),linear-gradient(110deg,#faf7ff,#eee2ff); }
html[data-theme="light"] .tc-hero h1,html[data-theme="light"] .tc-hero h1 span { color:#2f164d; }
html[data-theme="light"] .tc-hero p,html[data-theme="light"] .tc-slogan { color:#66507f; }
html[data-theme="light"] :is(.tc-tab,.tc-period,.tc-search,.tc-count,.tc-pager a,.tc-pager span) { background:#fff; color:var(--tc-text); border-color:var(--tc-line); }
html[data-theme="light"] :is(.tc-tab,.tc-period):hover { background:#efe5fc; color:#4e2382; }
html[data-theme="light"] :is(.tc-tab.is-active,.tc-period.is-active,.tc-pager .is-current) { background:linear-gradient(120deg,#8e2be4,#6b2ad4); color:#fff; border-color:#8e2be4; }
html[data-theme="light"] .tc-stat { background:radial-gradient(ellipse at 95% 100%,var(--stat-light-glow),transparent 65%),#fff; border-color:var(--stat-light-line); box-shadow:0 4px 18px rgba(60,30,95,.04); }
html[data-theme="light"] .tc-stat-views { --stat-light-glow:#ead7ff; --stat-light-line:#c7a2ef; --stat-icon:#7f2ec5; }
html[data-theme="light"] .tc-stat-subscribers { --stat-light-glow:#d9eaff; --stat-light-line:#a8c9f0; --stat-icon:#1962be; }
html[data-theme="light"] .tc-stat-likes { --stat-light-glow:#ffe0f1; --stat-light-line:#efacd2; --stat-icon:#b32378; }
html[data-theme="light"] .tc-stat-videos { --stat-light-glow:#d5f5e8; --stat-light-line:#99d6be; --stat-icon:#087758; }
html[data-theme="light"] .tc-stat-top { color:#5f4f72; }
html[data-theme="light"] .tc-stat strong { color:#2f2040; }
html[data-theme="light"] .tc-stat small { color:#6d5d80; }
html[data-theme="light"] .tc-stat-top .tc-icon { filter:none; }
html[data-theme="light"] .tc-stat::before,html[data-theme="light"] .tc-stat::after { opacity:.28; }
html[data-theme="light"] .tc-search input { color:var(--tc-text); }
html[data-theme="light"] .tc-reset { color:#7033ab; }
html[data-theme="light"] .tc-panel { background:#fff; }
html[data-theme="light"] .tc-panel-heading h2,html[data-theme="light"] .tc-name strong,html[data-theme="light"] .tc-table td { color:var(--tc-text); }
html[data-theme="light"] .tc-panel-heading p,html[data-theme="light"] .tc-name span,html[data-theme="light"] .tc-table th,html[data-theme="light"] .tc-growth small { color:var(--tc-muted); }
html[data-theme="light"] .tc-growth .up { color:#08733e; background:#e6f7ed; border-color:#addcc2; }
html[data-theme="light"] .tc-growth .down { color:#b2214a; background:#fff0f3; border-color:#f3becd; }
html[data-theme="light"] .tc-growth .flat { color:#71617f; background:#f3eff8; border-color:#e1d7eb; }
html[data-theme="light"] .tc-foot,html[data-theme="light"] body:has(.tc-admin) .admin-footer { background:#f6f4fc; color:#70617f; border-color:#e4d9f1; }
html[data-theme="light"] .tc-period-warning { background:#f6efff; color:#68467d; border-color:#dfc7f2; }
html[data-theme="light"] .tc-scroll { scrollbar-color:#ba94dc #f4effa; }
.tc-live-bar { order:3; display:flex; flex:1 1 100%; flex-wrap:wrap; align-items:center; gap:10px; color:var(--tc-muted); font-size:12px; }
.tc-live-bar button { border:1px solid var(--tc-line); background:var(--tc-panel); color:var(--tc-text); border-radius:8px; padding:7px 11px; font:inherit; cursor:pointer; }
.tc-live-bar button:disabled { opacity:.5; cursor:wait; }
.tc-live-dot { width:7px; height:7px; border-radius:50%; background:#20b872; box-shadow:0 0 8px rgba(32,184,114,.3); }
.tc-live-bar[data-state="error"] .tc-live-dot { background:#e99b2e; }
.tc-live-bar[data-state="paused"] .tc-live-dot { background:#8b809f; }
.tc-live-bar [role="status"] { flex:1; min-width:180px; }
.tc-tools { order:3; display:flex; flex:1 1 100%; flex-wrap:wrap; align-items:center; gap:10px; color:var(--tc-muted); font-size:12px; }
.tc-tools a,.tc-tools button,.tc-details { border:1px solid var(--tc-line); background:var(--tc-panel); color:var(--tc-text); border-radius:9px; padding:8px 12px; font:inherit; text-decoration:none; cursor:pointer; }
.tc-tools button:disabled { opacity:.5; cursor:not-allowed; }
.tc-tools p { margin:0; flex:1 1 260px; line-height:1.6; }
.tc-movement { display:block; margin-top:3px; font-size:10px; font-weight:600; white-space:nowrap; color:var(--tc-muted); }
.tc-movement[data-tone="up"] { color:#20b872; }
.tc-movement[data-tone="down"] { color:#e96b91; }
.tc-page.tc-compact .tc-table td { height:44px; padding-top:5px; padding-bottom:5px; }
.tc-page.tc-compact .tc-avatar { width:32px; height:32px; }
.tc-dialog { --tc-line:rgba(124,58,237,.22); --tc-panel:#fff; --tc-panel-2:#f1ebfc; --tc-text:#241b39; --tc-muted:#70617f; width:min(620px,94vw); max-height:85vh; overflow:auto; padding:24px; border:1px solid var(--tc-line); border-radius:18px; background:var(--tc-panel); color:var(--tc-text); box-shadow:0 25px 100px #0006; }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-dialog { --tc-line:rgba(135,72,255,.44); --tc-panel:#0b061b; --tc-panel-2:#120929; --tc-text:#f8f6ff; --tc-muted:#b7abed; }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-tier-badge:not(.is-free) { color:#e1c4ff; }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-alert-badge { color:#ffd166; }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-alert-badge.is-declining { color:#ff9eb2; }
.tc-dialog::backdrop { background:rgba(10,4,24,.7); }
.tc-dialog h2 { margin:0; color:var(--tc-text); font-size:22px; overflow-wrap:anywhere; }
.tc-dialog-head { display:flex; align-items:start; justify-content:space-between; gap:16px; }
.tc-dialog button { padding:8px 12px; border:1px solid var(--tc-line); border-radius:8px; background:var(--tc-panel-2); color:var(--tc-text); cursor:pointer; }
.tc-detail-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin:20px 0; }
.tc-detail-grid div { padding:12px; border:1px solid var(--tc-line); border-radius:10px; }
.tc-detail-grid dt { color:var(--tc-muted); font-size:12px; }
.tc-detail-grid dd { margin:5px 0 0; font-size:20px; font-weight:700; }
.tc-detail-note { color:var(--tc-muted); font-size:12px; line-height:1.7; }
.tc-tier-badge,.tc-alert-badge { display:inline-flex; align-items:center; width:max-content; padding:4px 8px; border:1px solid rgba(124,58,237,.25); border-radius:999px; background:rgba(124,58,237,.1); color:#8b48c7; font-size:11px; font-weight:750; white-space:nowrap; }
.tc-tier-badge.is-free { border-color:var(--tc-row-line); background:var(--tc-panel-2); color:var(--tc-muted); }
.tc-tier-badge.is-unavailable { border-color:rgba(148,163,184,.35); background:rgba(148,163,184,.1); color:var(--tc-muted); }
.tc-payout { color:var(--tc-text); font-weight:750; font-variant-numeric:tabular-nums; white-space:nowrap; }
.tc-engagement { font-variant-numeric:tabular-nums; }
.tc-alert-badge { border-color:rgba(217,119,6,.35); background:rgba(245,158,11,.12); color:#a66300; }
.tc-alert-badge.is-declining { border-color:rgba(225,29,72,.25); background:rgba(225,29,72,.1); color:#c02e50; }
.tc-creator-alerts { margin-top:12px; }
.tc-compare-check { display:inline-flex; align-items:center; gap:5px; color:var(--tc-muted); font-size:11px; cursor:pointer; white-space:nowrap; }
.tc-compare-check input { accent-color:var(--tc-violet); }
.tc-compare-status { color:var(--tc-muted); font-size:11px; }
.tc-top-videos { margin-top:18px; }
.tc-top-videos h3 { margin:0 0 8px; color:var(--tc-text); font-size:14px; }
.tc-top-videos ol { margin:0; padding-left:22px; color:var(--tc-muted); }
.tc-top-videos li { margin:7px 0; }
.tc-top-videos a { color:var(--tc-violet-2); font-weight:650; }
.tc-video-metrics { display:block; font-size:11px; color:var(--tc-muted); }
.tc-compare-dialog { width:min(900px,96vw); }
.tc-compare-table { width:100%; margin-top:16px; border-collapse:collapse; }
.tc-compare-table th,.tc-compare-table td { min-width:130px; padding:10px; border-bottom:1px solid var(--tc-line); text-align:left; vertical-align:top; }
.tc-compare-table th { color:var(--tc-muted); font-size:12px; }
.tc-compare-table td { color:var(--tc-text); font-size:13px; font-weight:650; }
.tc-milestone-panel { margin:14px 16px 18px; padding:16px; border:1px solid var(--tc-line); border-radius:13px; background:rgba(124,58,237,.035); }
.tc-milestone-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
.tc-milestone-card { padding:12px 14px; border:1px solid var(--tc-line); border-radius:11px; background:var(--tc-panel); }
.tc-milestone-card strong { display:block; color:var(--tc-violet-2); font-size:22px; font-variant-numeric:tabular-nums; }
.tc-milestone-card span { color:var(--tc-text); font-size:12px; font-weight:700; }
.tc-milestone-card small { display:block; margin-top:3px; color:var(--tc-muted); font-size:10px; }
.tc-milestone-badges { display:flex; flex-wrap:wrap; gap:4px; margin-top:5px; }
.tc-milestone-badge { display:inline-flex; padding:3px 7px; border:1px solid rgba(234,179,8,.35); border-radius:999px; background:rgba(245,158,11,.12); color:#946200; font-size:10px; font-weight:750; white-space:nowrap; }
:is([data-bs-theme="dark"],[data-theme="dark"],.dark,.dark-mode,.dark-theme,.theme-dark) .tc-milestone-badge { color:#ffd166; }
@media(max-width:600px) { .tc-compare-table { display:block; overflow-x:auto; } .tc-actions { flex-wrap:wrap; justify-content:center; } }
@media(max-width:700px) { .tc-milestone-panel { margin:12px 8px; padding:12px; } .tc-milestone-grid { grid-template-columns:1fr; } }
@media(max-width:600px) { .tc-tools { gap:8px; } }
@media(max-width:600px) { .tc-tools { gap:8px; } }
.tc-category-filter { order:3; display:flex; align-items:center; gap:8px; color:var(--tc-muted); font-size:12px; font-weight:650; }
.tc-category-filter select { min-height:44px; max-width:min(250px,65vw); padding:0 30px 0 12px; border:1px solid var(--tc-line); border-radius:10px; background:var(--tc-panel); color:var(--tc-text); font:inherit; }
.tc-feature-heading { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.tc-feature-heading h3 { margin:0; color:var(--tc-text); font-size:15px; font-weight:750; }
.tc-feature-heading p { margin:4px 0 0; color:var(--tc-muted); font-size:11px; }
.tc-feature-heading > span { padding:5px 9px; border:1px solid var(--tc-line); border-radius:999px; color:var(--tc-muted); font-size:10px; white-space:nowrap; }
.tc-podium,.tc-trends { margin:14px 16px 18px; padding:16px; border:1px solid var(--tc-line); border-radius:13px; background:rgba(124,58,237,.035); }
.tc-podium-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; }
.tc-podium-card { display:flex; min-height:92px; flex-direction:column; justify-content:center; gap:4px; padding:12px 14px; border:1px solid var(--tc-line); border-radius:11px; background:var(--tc-panel); color:var(--tc-text); text-decoration:none; }
.tc-podium-card:hover { border-color:var(--tc-violet-2); color:var(--tc-text); transform:translateY(-1px); }
.tc-podium-rank { color:var(--tc-violet-2); font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; }
.tc-podium-card strong { overflow:hidden; font-size:13px; text-overflow:ellipsis; white-space:nowrap; }
.tc-podium-value { color:var(--tc-text); font-size:18px; font-weight:800; font-variant-numeric:tabular-nums; }
.tc-podium-card small { color:var(--tc-muted); font-size:10px; }
.tc-podium-1 { border-color:rgba(234,179,8,.55); background:linear-gradient(135deg,rgba(234,179,8,.12),var(--tc-panel) 70%); }
.tc-podium-2 { border-color:rgba(148,163,184,.42); }
.tc-podium-3 { border-color:rgba(217,119,6,.4); }
.tc-chart-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
.tc-chart-card { min-width:0; padding:12px; border:1px solid var(--tc-row-line); border-radius:11px; background:var(--tc-panel); }
.tc-chart-card h4 { margin:0 0 8px; color:var(--tc-text); font-size:12px; font-weight:700; }
.tc-chart-card canvas { display:block; width:100%; height:190px; }
.tc-chart-card > p { margin:6px 0 0; color:var(--tc-muted); font-size:10px; }
.tc-chart-card .tc-chart-unavailable { min-height:190px; display:grid; place-items:center; text-align:center; line-height:1.7; }
html[data-theme="light"] .tc-feature-heading h3,html[data-theme="light"] .tc-podium-card,html[data-theme="light"] .tc-podium-value,html[data-theme="light"] .tc-chart-card h4 { color:#2f2040; }
html[data-theme="light"] .tc-podium,html[data-theme="light"] .tc-trends { background:#fbf9ff; }
html[data-theme="light"] .tc-category-filter select { background:#fff; color:#241b39; }
@media(max-width:700px) { .tc-podium,.tc-trends { margin:12px 8px; padding:12px; } .tc-podium-grid { grid-template-columns:1fr; } .tc-podium-card { min-height:72px; } .tc-chart-grid { grid-template-columns:1fr; } .tc-feature-heading { align-items:flex-start; } }
.tc-admin,.tc-page,.tc-panel,.tc-scroll { min-width:0; max-width:100%; }
.tc-scroll { overflow-x:hidden; }
.tc-table { width:100%; min-width:0; table-layout:fixed; }
@media(max-width:1700px) {
 .tc-admin { overflow-x:clip; }
 .tc-tabs,.tc-period-controls { flex-wrap:wrap; overflow:visible; margin-left:0; }
 .tc-period { border-radius:10px!important; margin:0!important; }
 .tc-scroll { padding:0 10px; }
 .tc-table { display:block; border-collapse:separate; }
 .tc-table thead { display:none; }
 .tc-table tbody { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; }
 .tc-table tbody tr { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); align-content:start; min-width:0; padding:10px; border:1px solid var(--tc-line); border-radius:12px; background:var(--tc-panel); }
 .tc-table tbody tr.tc-row-1,.tc-table tbody tr.tc-row-2,.tc-table tbody tr.tc-row-3 { box-shadow:inset 3px 0 0 var(--tc-gold); }
 .tc-table td { display:flex; min-width:0; width:auto; height:auto; min-height:42px; align-items:center; justify-content:space-between; gap:8px; padding:8px; border:0; border-top:1px solid var(--tc-row-line); text-align:right; font-size:13px; overflow-wrap:anywhere; }
 .tc-table td::before { content:attr(data-label); flex:0 1 auto; color:var(--tc-muted); font-size:10px; font-weight:650; text-align:left; }
 .tc-table td.tc-rank-cell { grid-column:1/-1; justify-content:flex-start; min-height:34px; padding:2px 6px; border-top:0; }
 .tc-table td.tc-rank-cell::before { content:"Rank"; }
 .tc-table td.tc-left { grid-column:1/-1; display:block; padding:10px 6px; border-top:0; text-align:left; }
 .tc-table td.tc-left::before { content:none; }
 .tc-table td:nth-child(11) { grid-column:1/-1; }
 .tc-creator { gap:10px; }
 .tc-avatar { width:42px; height:42px; font-size:16px; }
 .tc-name strong,.tc-name span { max-width:100%; }
 .tc-milestone-badges { white-space:normal; }
 .tc-growth { min-width:0; align-items:flex-end; text-align:right; }
 .tc-actions { flex-wrap:wrap; justify-content:flex-end; }
 .tc-compare-check { font-size:10px; }
}
@media(max-width:700px) {
 .tc-table tbody { grid-template-columns:minmax(0,1fr); }
 .tc-toolbar { display:flex; width:100%; }
 .tc-tabs { width:100%; }
 .tc-tab { flex:1 1 auto; justify-content:center; }
 .tc-period-controls { width:100%; justify-content:flex-start; }
 .tc-search { flex:1 1 100%; width:100%; }
 .tc-period-warning { margin:10px 8px; overflow-wrap:anywhere; }
 .tc-compare-table { display:table; width:100%; table-layout:fixed; }
 .tc-compare-table th,.tc-compare-table td { min-width:0; padding:6px 4px; font-size:10px; overflow-wrap:anywhere; }
 .tc-compare-dialog { padding:16px; }
}
/* ===== Neon "Top Creators" layout: hero + toolbar/leaderboard + Top Stats / Quick Actions side column ===== */
.tc-admin { --n-card:rgba(12,14,36,.92); --n-text:#eaf0ff; --n-muted:#9aa6d8; --n-line:rgba(138,92,255,.5); --n-glow:rgba(138,92,255,.7); }
html[data-theme="light"] .tc-admin, html[data-bs-theme="light"] .tc-admin { --n-card:#ffffff; --n-text:#0b1030; --n-muted:#55608f; --n-line:rgba(124,77,255,.4); --n-glow:rgba(124,77,255,.35); }
.tc-admin.tc-admin { border:0; border-radius:20px; padding:18px 22px 24px !important; }
.tc-admin .tc-page { display:grid; grid-template-columns:minmax(0,1fr); gap:16px; align-items:start; max-width:none; }

/* Hero */
.tc-admin .tc-page .tc-hero { position:relative; overflow:hidden; display:flex; align-items:center; gap:18px; width:auto; margin:0; min-height:0; padding:18px 22px; border-radius:18px; border:1px solid var(--n-line); box-shadow:0 0 30px -10px var(--n-glow); background:linear-gradient(115deg,rgba(106,61,255,.3),rgba(12,14,36,.92) 50%,rgba(0,229,255,.1)); }
html[data-theme="light"] .tc-admin .tc-page .tc-hero, html[data-bs-theme="light"] .tc-admin .tc-page .tc-hero { background:linear-gradient(115deg,rgba(124,77,255,.16),#fff 55%,rgba(0,184,230,.1)); }
.tc-admin .tc-hero-badge { flex:0 0 68px; width:68px; height:68px; display:grid; place-items:center; border-radius:18px; background:linear-gradient(135deg,#b44dff,#6a3dff); box-shadow:0 0 22px rgba(138,92,255,.7); }
.tc-admin .tc-hero-badge .tc-icon { width:40px; height:40px; filter:brightness(0) invert(1); }
.tc-admin .tc-hero-text { max-width:none; min-width:0; }
.tc-admin .tc-hero h1 { font-size:clamp(26px,2.6vw,36px); margin:0 0 4px; }
.tc-admin .tc-hero p { margin:0; max-width:none; font-size:16px; }
.tc-admin .tc-export { margin-left:auto; flex:0 0 auto; display:inline-flex; align-items:center; gap:10px; padding:11px 20px; border-radius:999px; border:1.5px solid #00e5ff; background:rgba(0,229,255,.1); color:#00e5ff; font-weight:700; text-decoration:none; box-shadow:0 0 16px rgba(0,229,255,.35); white-space:nowrap; transition:box-shadow .18s ease,background .18s ease; }
.tc-admin .tc-export svg { width:20px; height:20px; }
.tc-admin .tc-export:hover { background:rgba(0,229,255,.2); box-shadow:0 0 22px rgba(0,229,255,.6); color:#00e5ff; text-decoration:none; }
html[data-theme="light"] .tc-admin .tc-export, html[data-bs-theme="light"] .tc-admin .tc-export { color:#0089ad; border-color:#0089ad; }

/* Layout: stats + quick actions on top (narrow), side column on the right (wide) */
.tc-admin .tc-layout { display:grid; grid-template-columns:minmax(0,1fr); gap:16px; align-items:start; }
.tc-admin .tc-main { display:flex; flex-direction:column; gap:14px; min-width:0; }
.tc-admin .tc-side { order:-1; display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:16px; min-width:0; }
@media (min-width:1701px) {
  .tc-admin .tc-layout { grid-template-columns:minmax(0,1fr) 330px; }
  .tc-admin .tc-side { order:0; display:flex; flex-direction:column; }
}
@media (max-width:900px) { .tc-admin .tc-side { grid-template-columns:minmax(0,1fr); } }

/* Toolbar */
.tc-admin .tc-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
.tc-admin .tc-select { position:relative; display:inline-flex; align-items:center; gap:10px; min-height:50px; padding:0 40px 0 16px; border-radius:14px; border:1px solid var(--n-line); background:var(--n-card); color:var(--n-text); }
.tc-admin .tc-select::after { content:''; position:absolute; right:16px; top:50%; width:7px; height:7px; border-right:2px solid currentColor; border-bottom:2px solid currentColor; transform:translateY(-70%) rotate(45deg); pointer-events:none; }
.tc-admin .tc-select .tc-icon { width:22px; height:22px; }
.tc-admin .tc-select select { appearance:none; -webkit-appearance:none; background:transparent; border:0; outline:0; color:inherit; font-weight:700; font-size:15px; cursor:pointer; padding:0; min-width:120px; }
.tc-admin .tc-select select option { color:#111; background:#fff; }
.tc-admin .tc-select-sort { background:linear-gradient(120deg,#6a3dff,#4a2bd6); border-color:#8a5cff; color:#fff; box-shadow:0 0 18px rgba(138,92,255,.55); }
.tc-admin .tc-select:focus-within { box-shadow:0 0 0 2px #00e5ff; }
.tc-admin .tc-search { order:0; flex:1 1 240px; min-height:50px; border-radius:14px; border:1px solid var(--n-line); background:var(--n-card); }
.tc-admin .tc-submit { order:0; min-height:50px; padding:0 18px; border-radius:14px; }
.tc-admin .tc-reset { order:0; }
.tc-admin .tc-category-filter { order:0; }

/* Leaderboard panel: table first, extras below it */
.tc-admin .tc-panel { display:flex; flex-direction:column; flex:none; width:100%; border-radius:18px; border:1px solid var(--n-line); background:var(--n-card); box-shadow:0 0 26px -12px var(--n-glow); min-width:0; }
.tc-admin .tc-panel > * { order:2; min-width:0; }
.tc-admin .tc-panel > .tc-panel-heading { order:0; }
.tc-admin .tc-panel > .tc-period-warning { order:1; }
.tc-admin .tc-panel > .tc-scroll { order:2; }
.tc-admin .tc-panel > .tc-foot { order:3; }
.tc-admin .tc-panel > .tc-month { order:4; }
.tc-admin .tc-panel > .tc-podium { order:5; }
.tc-admin .tc-panel > .tc-trends { order:6; }
.tc-admin .tc-panel > .tc-milestone-panel { order:7; }
.tc-admin .tc-table th { text-transform:uppercase; letter-spacing:.06em; font-size:11px; color:var(--n-muted); }

/* Top Creator banner */
.tc-admin .tc-month { display:flex; align-items:center; gap:18px; margin:14px 17px 17px; padding:16px 22px; border-radius:16px; border:1px solid var(--n-line); background:linear-gradient(110deg,rgba(106,61,255,.38),rgba(12,14,36,.9) 60%,rgba(34,229,138,.12)); box-shadow:0 0 22px -10px var(--n-glow); }
html[data-theme="light"] .tc-admin .tc-month, html[data-bs-theme="light"] .tc-admin .tc-month { background:linear-gradient(110deg,rgba(124,77,255,.16),#fff 60%,rgba(15,184,106,.1)); }
.tc-admin .tc-month-art { flex:0 0 52px; width:52px; height:52px; display:grid; place-items:center; color:#ffc933; }
.tc-admin .tc-month-art .tc-icon { width:44px; height:44px; filter:drop-shadow(0 0 10px rgba(255,201,51,.7)); }
.tc-admin .tc-month h3 { margin:0; font-size:1.25rem; font-weight:700; color:var(--n-text); }
.tc-admin .tc-month p { margin:4px 0 0; color:var(--n-muted); }
.tc-admin .tc-month-cup { margin-left:auto; flex:0 0 64px; width:64px; height:64px; filter:drop-shadow(0 0 12px rgba(255,201,51,.6)); }

/* Side cards */
.tc-admin .tc-side .tc-stats, .tc-admin .tc-quick { flex:none; margin:0; padding:18px; border-radius:18px; border:1px solid var(--n-line); background:var(--n-card); box-shadow:0 0 26px -12px var(--n-glow); }
.tc-admin .tc-side .tc-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; }
.tc-admin .tc-quick { display:flex; flex-direction:column; gap:12px; }
@media (min-width:1701px), (max-width:1100px) { .tc-admin .tc-side .tc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
.tc-admin .tc-side-title { grid-column:1 / -1; display:flex; align-items:center; gap:12px; margin:0 0 4px; font-size:1.15rem; font-weight:700; color:var(--n-text); }
.tc-admin .tc-side-title .tc-icon { width:26px; height:26px; color:#8a5cff; }
.tc-admin .tc-side .tc-stat { display:flex; flex-direction:column; align-items:center; text-align:center; min-height:0; padding:16px 10px; border-radius:14px; border:1px solid rgba(138,92,255,.35); background:rgba(138,92,255,.08); box-shadow:none; }
.tc-admin .tc-side .tc-stat::before, .tc-admin .tc-side .tc-stat::after { display:none; }
.tc-admin .tc-side .tc-stat-top { display:contents; }
.tc-admin .tc-side .tc-stat-top .tc-icon { position:static; order:1; width:34px; height:34px; margin:0 0 6px; }
.tc-admin .tc-side .tc-stat strong { order:2; margin:0; font-size:1.7rem; }
.tc-admin .tc-side .tc-stat-top > span:first-child { order:3; margin-top:2px; font-size:.85rem; color:var(--n-muted); }
.tc-admin .tc-side .tc-stat small { display:none; }
html[data-theme="light"] .tc-admin .tc-side .tc-stat strong, html[data-bs-theme="light"] .tc-admin .tc-side .tc-stat strong { color:#0b1030; }
.tc-admin .tc-qa { display:flex; align-items:center; gap:14px; min-height:54px; padding:0 18px; border-radius:10px; border:1px solid rgba(255,255,255,.18); color:#fff; font-weight:700; text-decoration:none; transition:transform .15s ease,filter .18s ease; }
.tc-admin .tc-qa .tc-icon { width:24px; height:24px; }
.tc-admin .tc-qa:hover { transform:translateY(-2px); filter:brightness(1.12); color:#fff; text-decoration:none; }
.tc-admin .tc-qa-violet { background:linear-gradient(135deg,#6a3dff,#4a2bd6); box-shadow:0 0 18px -4px rgba(138,92,255,.8); }
.tc-admin .tc-qa-cyan { background:linear-gradient(135deg,#00c8ff,#0a74e6); box-shadow:0 0 18px -4px rgba(0,200,255,.8); }
.tc-admin .tc-qa-green { background:linear-gradient(135deg,#17c964,#0b8f55); box-shadow:0 0 18px -4px rgba(34,229,138,.7); }

@media (max-width:700px) {
  .tc-admin .tc-page .tc-hero { flex-wrap:wrap; }
  .tc-admin .tc-export { margin-left:0; }
  .tc-admin .tc-month { flex-wrap:wrap; }
  .tc-admin .tc-month-cup { display:none; }
}

/* ===== Mockup parity: real table (not cards) on desktop, side column from ~1100px ===== */
@media (min-width:1101px) {
  .tc-admin .tc-layout { grid-template-columns:minmax(0,1fr) 320px; }
  .tc-admin .tc-side { order:0; display:flex; flex-direction:column; }
  .tc-admin .tc-side .tc-stats { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
.tc-admin .tc-panel { order:1; }
.tc-admin .tc-tools { order:2; }
.tc-admin .tc-live-bar { order:3; }
@media (min-width:901px) {
  .tc-admin .tc-scroll { overflow:visible; padding:0 14px 8px; }
  .tc-admin .tc-table { display:block; width:100%; min-width:0; }
  .tc-admin .tc-table thead { display:block; }
  .tc-admin .tc-table tbody { display:flex; flex-direction:column; gap:4px; }
  .tc-admin .tc-table tr { display:grid; grid-template-columns:70px minmax(0,2.3fr) repeat(4,minmax(0,1fr)) minmax(120px,1.2fr); align-items:center; }
  .tc-admin .tc-table tbody tr { padding:0; border:1px solid transparent; border-bottom-color:var(--tc-row-line); border-radius:12px; background:transparent; box-shadow:none; }
  .tc-admin .tc-table tbody tr.tc-row-1 { border-color:#e2b83f; background:linear-gradient(90deg,rgba(255,176,32,.24),rgba(255,176,32,.05)); box-shadow:0 0 18px -6px rgba(255,200,36,.5); }
  .tc-admin .tc-table tbody tr.tc-row-1 td { border:0; border-radius:0; box-shadow:none; }
  .tc-admin .tc-table tbody tr.tc-row-2, .tc-admin .tc-table tbody tr.tc-row-3 { box-shadow:none; }
  .tc-admin .tc-table th, .tc-admin .tc-table td { display:flex; align-items:center; justify-content:center; min-width:0; height:auto; min-height:58px; padding:8px 6px; border:0; text-align:center; font-size:15px; }
  .tc-admin .tc-table th { min-height:44px; font-size:11px; }
  .tc-admin .tc-table td::before { display:none; content:none; }
  .tc-admin .tc-table td.tc-rank-cell, .tc-admin .tc-table th.tc-rank-cell { grid-column:auto; justify-content:flex-start; width:auto; padding-left:12px; }
  .tc-admin .tc-table td.tc-left, .tc-admin .tc-table th.tc-left { grid-column:auto; justify-content:flex-start; text-align:left; }
  .tc-admin .tc-table td:nth-child(11) { grid-column:auto; }
  .tc-admin .tc-table :is(th,td):nth-child(3), .tc-admin .tc-table :is(th,td):nth-child(6), .tc-admin .tc-table :is(th,td):nth-child(7), .tc-admin .tc-table :is(th,td):nth-child(10) { display:none; }
  .tc-admin .tc-table :is(th,td):nth-child(1) { order:1; }
  .tc-admin .tc-table :is(th,td):nth-child(2) { order:2; }
  .tc-admin .tc-table :is(th,td):nth-child(8) { order:3; }
  .tc-admin .tc-table :is(th,td):nth-child(5) { order:4; }
  .tc-admin .tc-table :is(th,td):nth-child(4) { order:5; }
  .tc-admin .tc-table :is(th,td):nth-child(9) { order:6; }
  .tc-admin .tc-table :is(th,td):nth-child(11) { order:7; }
  .tc-admin .tc-table td .tc-metric { font-weight:700; }
  .tc-admin .tc-actions { flex-direction:column; align-items:stretch; gap:4px; justify-content:center; }
  .tc-admin .tc-actions .tc-compare-check { justify-content:center; }
}

CSS;
if ($tcLiveRequest) {
    header('Cache-Control: no-store, private');
    ob_start();
} else {
    require_once __DIR__ . '/header.php';
}
?>
<style>
<?= $extraCss ?>
</style>
<main class="dashboard-content">
<div class="container-fluid px-3 px-lg-4 py-4">
<div class="tc-admin p-3 p-lg-4">
<section class="tc-page">
    <script type="application/json" id="tcRankData"><?= json_encode(['sort' => $sort, 'period' => $period, 'ranks' => $tcRankSnapshot], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <header class="tc-hero">
        <div class="tc-hero-badge" aria-hidden="true"><span class="tc-icon"><svg viewBox="0 0 100 100" fill="none"><defs><linearGradient id="tcHeroCup" x2="70%" y2="100%"><stop stop-color="#da6cff"/><stop offset="1" stop-color="#7800ee"/></linearGradient></defs><path d="M24 15h52v24c0 20-11 33-26 33S24 59 24 39V15Z" fill="url(#tcHeroCup)"/><path d="M24 25H9c0 22 8 32 23 32M76 25h15c0 22-8 32-23 32" stroke="#9d18f7" stroke-width="8" stroke-linejoin="round"/><path d="M45 69h10v14H45zM33 83h34l5 12H28z" fill="url(#tcHeroCup)"/><path d="m50 29 7 10-7 10-7-10z" fill="#7400c6"/></svg></span></div>
        <div class="tc-hero-text">
            <h1>Top <span>Creators</span></h1>
            <p>Discover the most successful creators on VibeStream</p>
            <span class="tc-hero-meta">VibeStream / Creator analytics</span>
        </div>
        <a class="tc-export" href="<?= htmlspecialchars($buildUrl(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 20h16"/></svg>Export Report</a>
    </header>

    <div class="tc-layout">
    <div class="tc-main">
    <form class="tc-toolbar" method="get" action="top-creators.php">
        <label class="tc-select tc-select-sort"><span class="tc-icon" aria-hidden="true"><?= $icons['chart'] ?></span>
            <select name="sort" aria-label="Rank creators by" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= $sort === $value ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select></label>
        <label class="tc-select tc-select-period"><span class="tc-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/></svg></span>
            <select name="period" aria-label="View ranking period" onchange="this.form.submit()">
                <?php foreach ($periodOptions as $value => $label): ?><option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"<?= $period === $value ? ' selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
            </select></label>
        <?php if ($categoryOptions): ?>
        <label class="tc-category-filter">Category
            <select name="category" aria-label="Filter creators by video category">
                <option value="">All categories</option>
                <?php foreach ($categoryOptions as $categoryKey => $categoryName): ?>
                    <option value="<?= htmlspecialchars((string)$categoryKey, ENT_QUOTES, 'UTF-8') ?>"<?= $category === $categoryKey ? ' selected' : '' ?>><?= htmlspecialchars((string)$categoryName, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="tc-search">
            <span class="tc-icon"><?= $icons['search'] ?></span>
            <input type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search creators..." aria-label="Search creators">
        </label>
        <button class="tc-submit" type="submit">Search</button>
        <?php if ($search !== ''): ?><a class="tc-reset" href="<?= htmlspecialchars($buildUrl(['q' => '']), ENT_QUOTES, 'UTF-8') ?>">Clear search</a><?php endif; ?>
    </form>

    <form class="tc-pin-form" method="post" action="top-creators.php">
        <?= vs_csrf_field() ?>
        <input type="hidden" name="tc_pin_action" value="pin">
        <label for="tcPinnedCreator">Featured Top Creator</label>
        <select id="tcPinnedCreator" name="pinned_creator" aria-label="Choose the featured Top Creator">
            <option value="">Use automatic ranking</option>
            <?php foreach ($tcPinCreatorOptions as $pinOption): ?>
                <?php $pinName = (string)$pinOption['creator']; ?>
                <option value="<?= htmlspecialchars($pinName, ENT_QUOTES, 'UTF-8') ?>"<?= $tcPinnedCreatorName !== '' && strcasecmp($tcPinnedCreatorName, $pinName) === 0 ? ' selected' : '' ?>><?= htmlspecialchars($pinName, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Pin creator</button>
        <?php if ($tcPinnedCreatorName !== ''): ?>
            <button type="submit" name="tc_pin_action" value="clear" class="tc-pin-clear">Clear pin</button>
        <?php endif; ?>
        <?php if ($tcConfigMessage !== ''): ?><span class="tc-pin-success" role="status"><?= htmlspecialchars($tcConfigMessage, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
        <?php if ($tcConfigError !== ''): ?><span class="tc-pin-error" role="alert"><?= htmlspecialchars($tcConfigError, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
    </form>

    <div class="tc-tools">
        <a href="<?= htmlspecialchars($buildUrl(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>">Download CSV</a>
        <button type="button" id="tcDensity" aria-pressed="false">Compact rows</button>
        <button type="button" id="tcCompareOpen" disabled>Compare creators <span id="tcCompareCount">0/3</span></button>
        <span id="tcCompareStatus" class="tc-compare-status" role="status" aria-live="polite">Select up to three creators below.</span>
        <p>Only views use the selected period; likes and videos are all-time and subscribers are current. Growth compares the last 7 days with the previous 7. Rank changes compare the last view in this browser tab using the same sort and period. Payout is an estimate at PKR 1,000 per 100,000 selected-period views.</p>
    </div>
    <div class="tc-live-bar" data-state="ready" aria-label="Live leaderboard controls">
        <span class="tc-live-dot" aria-hidden="true"></span>
        <span id="tcLiveStatus" role="status" aria-live="polite">Auto-refresh every 30 seconds</span>
        <button type="button" id="tcLivePause" aria-pressed="false">Pause updates</button>
        <button type="button" id="tcLiveRefresh">Refresh now</button>
    </div>
    <div class="tc-panel">
    <div class="tc-panel-heading"><div><h2>Creator leaderboard</h2><p>Views and estimated payout: <?= htmlspecialchars($periodOptions[$period], ENT_QUOTES, 'UTF-8') ?> &middot; Subscribers: current &middot; Likes, videos and engagement: all time &middot; Growth: last 7 vs. previous 7 days</p></div><span class="tc-count"><?= number_format($totalCreators) ?> creators</span></div>
    <?php if ($pageError === ''): ?>
        <section class="tc-milestone-panel" aria-labelledby="tcMilestoneHeading">
            <div class="tc-feature-heading"><div><h3 id="tcMilestoneHeading">1M creator milestones</h3><p>Display-only achievements across the creators matching your current filters. No reward or payment is issued.</p></div><span>1,000,000+</span></div>
            <div class="tc-milestone-grid">
                <article class="tc-milestone-card"><strong><?= number_format($milestoneCounts['views']) ?></strong><span>Creators with 1M+ all-time views</span><small>Based on total video views</small></article>
                <article class="tc-milestone-card"><strong><?= number_format($milestoneCounts['subscribers']) ?></strong><span>Creators with 1M+ current subscribers</span><small>Based on current subscriber count</small></article>
                <article class="tc-milestone-card"><strong><?= number_format($milestoneCounts['likes']) ?></strong><span>Creators with 1M+ all-time likes</span><small>Based on total video likes</small></article>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($historyNotice !== ''): ?><div class="tc-period-warning" role="status"><?= htmlspecialchars($historyNotice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($pageError === '' && (!$viewHistoryAvailable || !$subscriberHistoryAvailable)): ?>
        <div class="tc-period-warning" role="status">
            <?php if (!$viewHistoryAvailable): ?>
                Period views and view growth need view-history tracking to be set up.
            <?php endif; ?>
            <?php if ($pageError === '' && !$premiumStatusAvailable): ?>
                <div class="tc-period-warning" role="status">Premium status is unavailable because Premium membership data is not set up.</div>
            <?php endif; ?>
            <?php if ($viewHistoryAvailable && ($creatorAlertCounts['no_recent_views'] > 0 || $creatorAlertCounts['declining_views'] > 0)): ?>
                <div class="tc-period-warning tc-creator-alerts" role="status">
                    <?php if ($creatorAlertCounts['no_recent_views'] > 0): ?><?= number_format($creatorAlertCounts['no_recent_views']) ?> creator(s) had no recorded views in the last 7 days.<?php endif; ?>
                    <?php if ($creatorAlertCounts['declining_views'] > 0): ?><?= $creatorAlertCounts['no_recent_views'] > 0 ? ' ' : '' ?><?= number_format($creatorAlertCounts['declining_views']) ?> creator(s) had fewer views than the previous 7 days.<?php endif; ?>
                    Counts reflect available view events, not upload activity.
                </div>
            <?php endif; ?>
            <?php if (!$subscriberHistoryAvailable): ?>
                <?= !$viewHistoryAvailable ? ' ' : '' ?>Subscriber growth needs subscription timestamps.
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($pageError !== ''): ?>
        <div class="tc-error" role="alert"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (!$creators): ?>
        <div class="tc-empty"><?= $search !== '' ? 'No creators matched your search.' : 'Creators will appear here after they publish videos.' ?></div>
    <?php else: ?>
        <section class="tc-podium" aria-label="Top three creators by <?= htmlspecialchars($sortOptions[$sort], ENT_QUOTES, 'UTF-8') ?>">
            <div class="tc-feature-heading"><div><h3>Top creators</h3><p>Based on the current ranking and filters</p></div><span><?= htmlspecialchars($sortOptions[$sort], ENT_QUOTES, 'UTF-8') ?></span></div>
            <div class="tc-podium-grid">
            <?php foreach ($tcTopCreators as $podiumCreator):
                $podiumRank = (int)$podiumCreator['global_rank'];
                $podiumValue = (int)$podiumCreator[$sortColumn];
                $podiumValueLabel = $sort === 'growth' ? (($podiumValue > 0 ? '+' : '') . number_format($podiumValue)) : $formatCompact($podiumValue);
                $podiumName = (string)$podiumCreator['creator'];
            ?>
                <a class="tc-podium-card tc-podium-<?= $podiumRank ?>" href="<?= htmlspecialchars('../profile.php?u=' . rawurlencode($podiumName), ENT_QUOTES, 'UTF-8') ?>">
                    <span class="tc-podium-rank">#<?= $podiumRank ?></span>
                    <strong><?= htmlspecialchars($podiumName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <span class="tc-podium-value"><?= htmlspecialchars($podiumValueLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <small><?= htmlspecialchars($sortOptions[$sort], ENT_QUOTES, 'UTF-8') ?></small>
                </a>
            <?php endforeach; ?>
            </div>
        </section>
        <section class="tc-trends" aria-labelledby="tcTrendHeading">
            <div class="tc-feature-heading"><div><h3 id="tcTrendHeading">30-day growth</h3><p>Daily totals for creators matching the current category and search</p></div><span>Real event history</span></div>
            <script type="application/json" id="tcTrendData"><?= json_encode(['labels' => $trendLabels, 'views' => array_values($viewTrend), 'subscribers' => array_values($subscriberTrend), 'available' => $trendAvailable], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
            <div class="tc-chart-grid">
                <article class="tc-chart-card"><h4>Daily views</h4><?php if ($trendAvailable['views']): ?><canvas id="tcViewTrend" height="210" role="img" aria-label="Daily views over the last 30 days"></canvas><p>Views recorded per day</p><?php else: ?><p class="tc-chart-unavailable">View-event history is unavailable. Configure timestamped view tracking to see this chart.</p><?php endif; ?></article>
                <article class="tc-chart-card"><h4>New subscribers</h4><?php if ($trendAvailable['subscribers']): ?><canvas id="tcSubscriberTrend" height="210" role="img" aria-label="New subscribers per day over the last 30 days"></canvas><p>Subscriptions recorded per day</p><?php else: ?><p class="tc-chart-unavailable">Subscription timestamps are unavailable. Add a created_at timestamp to see this chart.</p><?php endif; ?></article>
            </div>
        </section>
        <div class="tc-scroll" role="region" aria-label="Creator rankings">
        <table class="tc-table">
            <caption class="tc-sr-only">Creator rankings with premium status, estimated payout and engagement rate. Views and estimated payout follow the selected period.</caption>
            <thead>
                <tr>
                    <th class="tc-rank-cell">#</th>
                    <th class="tc-left">Creator</th>
                    <th>Premium</th>
                    <th>Subscribers</th>
                    <th>Views (<?= htmlspecialchars($periodOptions[$period], ENT_QUOTES, 'UTF-8') ?>)</th>
                    <th>Est. payout</th>
                    <th>Total Likes</th>
                    <th>Videos</th>
                    <th>Engagement rate</th>
                    <th>Growth (7d)</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($pageCreators as $i => $creator):
                $rank = (int)$creator['global_rank'];
                $name = trim((string)$creator['creator']);
                $initial = mb_strtoupper(mb_substr($name, 0, 1));
                $hue = abs(crc32(mb_strtolower($name))) % 360;
                $avatar = avatar_url($name);
                $rowClass = $rank <= 3 ? ' class="tc-row-' . $rank . '"' : '';
                $profileUrl = '../profile.php?u=' . rawurlencode($name);
            ?>
                <tr<?= $rowClass ?> data-creator-key="<?= htmlspecialchars((string)$creator['creator_key'], ENT_QUOTES, 'UTF-8') ?>" data-rank="<?= $rank ?>" data-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" data-premium="<?= htmlspecialchars((string)$creator['premium_tier'], ENT_QUOTES, 'UTF-8') ?>" data-subscribers="<?= (int)$creator['subscriber_count'] ?>" data-views="<?= (int)$creator['total_views'] ?>" data-period-views="<?= (int)$creator['ranking_views'] ?>" data-payout="<?= htmlspecialchars(number_format($creator['estimated_payout'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-likes="<?= (int)$creator['total_likes'] ?>" data-videos="<?= (int)$creator['video_count'] ?>" data-engagement="<?= htmlspecialchars(number_format($creator['engagement_rate'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-growth="<?= (int)$creator['view_growth'] ?>" data-growth-available="<?= $viewHistoryAvailable ? '1' : '0' ?>" data-milestones="<?= htmlspecialchars(json_encode($creator['milestones'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>">
                    <td class="tc-rank-cell" data-label="Rank">
                        <?php if ($rank <= 3): ?>
                            <span class="tc-rank r<?= $rank ?>" aria-label="Rank <?= $rank ?>"><span class="tc-icon"><?= $icons['crown'] ?></span><small><?= $rank ?></small></span>
                        <?php else: ?>
                            <span class="tc-rank" aria-label="Rank <?= $rank ?>"><?= $rank ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="tc-left" data-label="Creator">
                        <div class="tc-creator">
                            <?php if ($avatar !== ''): ?>
                                <img class="tc-avatar" src="<?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="tc-avatar" style="background:linear-gradient(135deg,hsl(<?= $hue ?> 68% 48%),hsl(<?= ($hue + 42) % 360 ?> 70% 34%))" aria-hidden="true"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endif; ?>
                            <div class="tc-name">
                                <strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong>
                                <span>@<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($creator['milestones']): ?>
                                    <span class="tc-milestone-badges" aria-label="1 million milestones achieved">
                                        <?php foreach ($creator['milestones'] as $milestone): ?>
                                            <span class="tc-milestone-badge">1M <?= htmlspecialchars(ucfirst($milestone), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td data-label="Premium"><span class="tc-tier-badge<?= $creator['premium_tier'] === 'Free' ? ' is-free' : ($creator['premium_tier'] === 'Unavailable' ? ' is-unavailable' : '') ?>"><?= htmlspecialchars($creator['premium_tier'], ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td data-label="Subscribers"><span class="tc-metric<?= $sort === 'subscribers' ? ' is-sorted' : '' ?>" title="<?= number_format($creator['subscriber_count']) ?>"><?= $formatCompact($creator['subscriber_count']) ?></span></td>
                    <td data-label="Views"><span class="tc-metric<?= $sort === 'views' ? ' is-sorted' : '' ?>" title="<?= number_format($creator['ranking_views']) ?>"><span class="tc-icon"><?= $icons['eye'] ?></span><?= $formatCompact($creator['ranking_views']) ?></span></td>
                    <td data-label="Est. payout"><span class="tc-payout" title="PKR 1,000 per 100,000 <?= htmlspecialchars(strtolower($periodOptions[$period]), ENT_QUOTES, 'UTF-8') ?> views">PKR <?= number_format($creator['estimated_payout'], 2) ?></span></td>
                    <td data-label="Total likes"><span class="tc-metric<?= $sort === 'likes' ? ' is-sorted' : '' ?>" title="<?= number_format($creator['total_likes']) ?>"><span class="tc-icon"><?= $icons['like'] ?></span><?= $formatCompact($creator['total_likes']) ?></span></td>
                    <td data-label="Videos"><span class="<?= $sort === 'videos' ? 'is-sorted' : '' ?>" title="<?= number_format($creator['video_count']) ?>"><?= $formatCompact($creator['video_count']) ?></span></td>
                    <td data-label="Engagement rate"><span class="tc-engagement<?= $sort === 'engagement' ? ' is-sorted' : '' ?>" title="All-time likes divided by all-time views"><?= $creator['total_views'] > 0 ? number_format($creator['engagement_rate'], 2) . '%' : '—' ?></span></td>
                    <td data-label="Growth (7d)">
                        <div class="tc-growth">
                            <?php if ($viewHistoryAvailable):
                                $viewDelta = $creator['view_growth'];
                                $viewTone = $viewDelta > 0 ? 'up' : ($viewDelta < 0 ? 'down' : 'flat');
                                if ($creator['views_previous_7d'] === 0 && $creator['views_7d'] > 0) {
                                    $viewChangeLabel = 'New +' . $formatCompact($creator['views_7d']);
                                } elseif ($creator['views_previous_7d'] > 0) {
                                    $viewPercent = (int)round($viewDelta / $creator['views_previous_7d'] * 100);
                                    $viewChangeLabel = ($viewDelta > 0 ? '+' : '') . $viewPercent . '% views';
                                } else {
                                    $viewChangeLabel = 'No views';
                                }
                            ?>
                                <span class="<?= $viewTone ?>"><?= htmlspecialchars($viewChangeLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="flat">Unavailable</span>
                            <?php endif; ?>
                            <?php if ($subscriberHistoryAvailable): ?>
                                <small><?= number_format($creator['subscribers_7d']) ?> new subscribers (7d)</small>
                            <?php else: ?>
                                <small>Subscriber growth unavailable</small>
                            <?php endif; ?>
                            <?php if ($viewHistoryAvailable && $creator['views_7d'] === 0): ?>
                                <span class="tc-alert-badge">No views (7d)</span>
                            <?php elseif ($viewHistoryAvailable && $creator['view_growth'] < 0): ?>
                                <span class="tc-alert-badge is-declining">Views declining</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td data-label="Actions">
                        <div class="tc-actions">
                            <label class="tc-compare-check"><input type="checkbox" class="tc-compare-select" value="<?= htmlspecialchars((string)$creator['creator_key'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Select <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> for comparison">Compare</label>
                            <button type="button" class="tc-details" data-name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" data-rank="<?= $rank ?>" data-premium="<?= htmlspecialchars((string)$creator['premium_tier'], ENT_QUOTES, 'UTF-8') ?>" data-subscribers="<?= $creator['subscriber_count'] ?>" data-views="<?= $creator['total_views'] ?>" data-period-views="<?= $creator['ranking_views'] ?>" data-period-label="<?= htmlspecialchars($periodOptions[$period], ENT_QUOTES, 'UTF-8') ?>" data-payout="<?= htmlspecialchars(number_format($creator['estimated_payout'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-likes="<?= $creator['total_likes'] ?>" data-videos="<?= $creator['video_count'] ?>" data-engagement="<?= htmlspecialchars(number_format($creator['engagement_rate'], 2, '.', ''), ENT_QUOTES, 'UTF-8') ?>" data-current-views="<?= $viewHistoryAvailable ? $creator['views_7d'] : '' ?>" data-previous-views="<?= $viewHistoryAvailable ? $creator['views_previous_7d'] : '' ?>" data-videos-available="<?= $topVideosAvailable ? '1' : '0' ?>" data-top-videos="<?= htmlspecialchars(json_encode($creator['top_videos'] ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), ENT_QUOTES, 'UTF-8') ?>">Details</button>
                            <a class="tc-view" href="<?= htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') ?>"><span class="tc-icon" aria-hidden="true"><?= $icons['eye'] ?></span>View Profile</a>
                            
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="tc-foot">
            <span class="tc-info"><span class="tc-icon"><?= $icons['info'] ?></span>Showing <?= $showingFrom ?> to <?= $showingTo ?> of <?= $totalCreators ?> top creators</span>
            <nav class="tc-pager" aria-label="Pagination">
                <?php if ($currentPage > 1): ?><a href="<?= htmlspecialchars($buildUrl(['page' => $currentPage - 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Previous page">&lsaquo;</a><?php else: ?><span class="is-disabled" aria-disabled="true" aria-label="Previous page">&lsaquo;</span><?php endif; ?>
                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <?php if ($p !== 1 && $p !== $totalPages && abs($p - $currentPage) > 1) {
                        if ($p === $currentPage - 2 || $p === $currentPage + 2) { echo '<span class="tc-ellipsis" aria-hidden="true">&hellip;</span>'; }
                        continue;
                    } ?>
                    <?php if ($p === $currentPage): ?>
                        <span class="is-current" aria-current="page"><?= $p ?></span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($buildUrl(['page' => $p]), ENT_QUOTES, 'UTF-8') ?>"><?= $p ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($currentPage < $totalPages): ?><a href="<?= htmlspecialchars($buildUrl(['page' => $currentPage + 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Next page">&rsaquo;</a><?php else: ?><span class="is-disabled" aria-disabled="true" aria-label="Next page">&rsaquo;</span><?php endif; ?>
            </nav>
        </div>
        <?php
        $tcLead = $tcFeaturedLead ?? ($tcTopCreators[0] ?? null);
        if ($tcLead):
            $tcLeadValue = (int)$tcLead[$sortColumn];
            $tcLeadLabel = $sort === 'growth' ? (($tcLeadValue > 0 ? '+' : '') . number_format($tcLeadValue)) : $formatCompact($tcLeadValue);
        ?>
        <div class="tc-month">
            <span class="tc-month-art" aria-hidden="true"><span class="tc-icon"><?= $icons['crown'] ?></span></span>
            <div>
                <h3><?= $tcFeaturedLead ? 'Pinned Top Creator' : ($period === 'month' ? 'Top Creator of the Month' : 'Top Creator') ?></h3>
                <p><?= htmlspecialchars((string)$tcLead['creator'], ENT_QUOTES, 'UTF-8') ?><?= $tcFeaturedLead ? ' is featured' : ' is leading' ?> <?= htmlspecialchars(mb_strtolower($sortOptions[$sort], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?> with <?= htmlspecialchars($tcLeadLabel, ENT_QUOTES, 'UTF-8') ?>.</p>
            </div>
            <svg class="tc-month-cup" viewBox="0 0 64 64" aria-hidden="true"><path d="M20 8h24v14c0 9-5 15-12 15s-12-6-12-15V8z" fill="#ffc933"/><path d="M20 12H10c0 10 4 15 11 16M44 12h10c0 10-4 15-11 16" stroke="#ffc933" stroke-width="3" fill="none"/><rect x="28" y="37" width="8" height="9" fill="#e6a817"/><rect x="20" y="46" width="24" height="7" rx="2" fill="#ffc933"/></svg>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
    </div>
    <aside class="tc-side" aria-label="Creator summary and quick actions">
    <?php if ($pageError === ''): ?>
    <section class="tc-stats" aria-label="Overview of creators matching the current search">
        <h2 class="tc-side-title"><span class="tc-icon" aria-hidden="true"><?= $icons['chart'] ?></span>Top Stats</h2>
        <?php foreach ([
            ['views', 'Total Views', 'eye', $periodOptions[$period]],
            ['subscribers', 'Total Subscribers', 'users', 'Current audience'],
            ['likes', 'Total Likes', 'like', 'All time'],
            ['videos', 'Total Videos', 'video', 'All time'],
        ] as [$metricKey, $metricLabel, $metricIcon, $metricNote]): ?>
        <article class="tc-stat tc-stat-<?= htmlspecialchars($metricKey, ENT_QUOTES, 'UTF-8') ?>">
            <div class="tc-stat-top"><span><?= htmlspecialchars($metricLabel, ENT_QUOTES, 'UTF-8') ?></span><span class="tc-icon" aria-hidden="true"><?= $icons[$metricIcon] ?></span></div>
            <strong title="<?= number_format($summary[$metricKey]) ?>"><?= $formatCompact($summary[$metricKey]) ?></strong>
            <small><?= htmlspecialchars($metricNote, ENT_QUOTES, 'UTF-8') ?></small>
        </article>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
        <section class="tc-quick" aria-label="Quick actions">
            <h2 class="tc-side-title"><span class="tc-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg></span>Quick Actions</h2>
            <a class="tc-qa tc-qa-violet" href="analytics.php"><span class="tc-icon" aria-hidden="true"><?= $icons['chart'] ?></span>View Analytics</a>
            <a class="tc-qa tc-qa-cyan" href="users.php"><span class="tc-icon" aria-hidden="true"><?= $icons['users'] ?></span>Manage Creators</a>
            <a class="tc-qa tc-qa-green" href="<?= htmlspecialchars($buildUrl(['export' => 'csv']), ENT_QUOTES, 'UTF-8') ?>"><span class="tc-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0-4-4m4 4 4-4M4 20h16"/></svg></span>Export Data</a>
        </section>
    </aside>
    </div>
</section>
</div>
</div>
<dialog class="tc-dialog" id="tcDetails" aria-labelledby="tcDetailName"><div class="tc-dialog-head"><h2 id="tcDetailName">Creator analytics</h2><button type="button" id="tcDetailClose">Close</button></div><dl class="tc-detail-grid" id="tcDetailMetrics"></dl><div id="tcDetailHistory" class="tc-detail-note"></div><section class="tc-top-videos" id="tcTopVideos"></section><p class="tc-detail-note">Views, likes and videos are all-time totals unless labeled otherwise. Subscribers are current. Payout is only an estimate at PKR 1,000 per 100,000 selected-period views; it does not represent confirmed revenue or a payment.</p></dialog>
<dialog class="tc-dialog tc-compare-dialog" id="tcCompareDialog" aria-labelledby="tcCompareTitle"><div class="tc-dialog-head"><h2 id="tcCompareTitle">Compare creators</h2><button type="button" id="tcCompareClose">Close</button></div><p class="tc-detail-note">Compare up to three creators using the current leaderboard period.</p><div id="tcCompareResults"></div></dialog>
</main>
<?php
if ($tcLiveRequest) {
    $tcFragment = ob_get_clean();
    header('Content-Type: application/json; charset=UTF-8');
    if ($pageError !== '') { http_response_code(503); }
    echo json_encode(['ok' => $pageError === '', 'html' => $tcFragment, 'sort' => $sort, 'period' => $period], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<footer class="admin-footer">
    <div class="container-fluid px-3 px-lg-4">
        <span>Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a></span>
    </div>
</footer>
</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<script>
(function () {
    'use strict';
    var page = document.querySelector('.tc-page');
    var bar = document.querySelector('.tc-live-bar');
    if (!page || !bar) return;
    var status = document.getElementById('tcLiveStatus');
    var pauseButton = document.getElementById('tcLivePause');
    var refreshButton = document.getElementById('tcLiveRefresh');
    var paused = false, busy = false, timer = null, failures = 0, stopped = false, controller = null;
    function message(text, state) { status.textContent = text; bar.dataset.state = state || 'ready'; }
    function schedule() {
        window.clearTimeout(timer);
        if (!paused && !stopped && !document.hidden) timer = window.setTimeout(function () { refresh(false); }, Math.min(120000, 30000 * Math.pow(2, failures)));
    }
    async function refresh(manual) {
        window.clearTimeout(timer);
        if (busy || stopped || document.hidden || (paused && !manual)) return;
        var currentPanel = page.querySelector('.tc-panel');
        if (!manual && currentPanel && currentPanel.contains(document.activeElement)) { schedule(); return; }
        busy = true; refreshButton.disabled = true;
        var timeout = null;
        try {
            if (!navigator.onLine) throw new Error('You are offline');
            controller = new AbortController();
            timeout = window.setTimeout(function () { controller.abort(); }, 15000);
            var url = new URL('top-creators.php', window.location.href);
            var form = page.querySelector('.tc-toolbar');
            url.search = window.location.search;
            url.searchParams.set('tc_live', '1');
            var response = await fetch(url.toString(), {cache:'no-store',credentials:'same-origin',signal:controller.signal,headers:{'Accept':'application/json'}});
            if (response.redirected || response.status === 401 || response.status === 403) {
                stopped = true;
                message('Session or access changed. Reload the page to continue.', 'error');
                return;
            }
            if (!response.ok || !(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Live data unavailable');
            var data = await response.json();
            if (!data.ok || typeof data.html !== 'string') throw new Error('Invalid live data');
            if (data.sort !== form.elements.sort.value || data.period !== form.elements.period.value) {
                message('Ranking options changed. Reload the page to use the updated filters.', 'error');
                stopped = true; return;
            }
            var parsed = new DOMParser().parseFromString(data.html, 'text/html');
            var nextRankData = parsed.querySelector('#tcRankData');
            var currentRankData = page.querySelector('#tcRankData');
            if (nextRankData && currentRankData) currentRankData.replaceWith(nextRankData);
            var nextPanel = parsed.querySelector('.tc-panel');
            var nextStats = parsed.querySelector('.tc-stats');
            if (!nextPanel || !nextStats) throw new Error('Incomplete live data');
            var scroll = currentPanel.querySelector('.tc-scroll');
            var left = scroll ? scroll.scrollLeft : 0;
            var top = window.scrollY;
            var currentStats = page.querySelector('.tc-stats');
            if (currentStats) currentStats.replaceWith(nextStats); else page.appendChild(nextStats);
            currentPanel.replaceWith(nextPanel);
            if (window.tcSyncCompareSelections) window.tcSyncCompareSelections(nextPanel);
            if (window.tcDrawTrends) window.tcDrawTrends();
            if (window.tcRefreshRankMovement) window.tcRefreshRankMovement();
            var newScroll = nextPanel.querySelector('.tc-scroll');
            if (newScroll) newScroll.scrollLeft = left;
            window.scrollTo(window.scrollX, top);
            failures = 0;
            message((paused ? 'Paused · ' : 'Live · ') + 'Updated ' + new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'}), paused ? 'paused' : 'ready');
        } catch (error) {
            failures = Math.min(failures + 1, 2);
            console.warn('Creator leaderboard refresh failed.', error);
            message((navigator.onLine ? 'Refresh unavailable' : 'Offline') + ' · Keeping the last displayed data.' + (paused ? '' : ' Retrying automatically.'), 'error');
        } finally {
            window.clearTimeout(timeout); busy = false; refreshButton.disabled = false; schedule();
        }
    }
    pauseButton.addEventListener('click', function () {
        paused = !paused; pauseButton.textContent = paused ? 'Resume updates' : 'Pause updates';
        pauseButton.setAttribute('aria-pressed', String(paused));
        message(paused ? 'Automatic updates paused' : 'Automatic updates resumed', paused ? 'paused' : 'ready');
        if (!paused) refresh(false); else window.clearTimeout(timer);
    });
    refreshButton.addEventListener('click', function () { refresh(true); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) window.clearTimeout(timer); else if (!paused) refresh(false); });
    window.addEventListener('online', function () { if (!paused) refresh(false); });
    window.addEventListener('pagehide', function () { window.clearTimeout(timer); if (controller && busy) controller.abort(); });
    window.addEventListener('pageshow', function () { schedule(); });
    schedule();
})();
</script>
<script>
(function () {
 'use strict';
 var page = document.querySelector('.tc-page');
 if (!page) return;
 function refreshRankMovement() {
  var node = document.getElementById('tcRankData');
  if (!node) return;
  var data;
  try { data = JSON.parse(node.textContent || '{}'); } catch (_) { return; }
  var key = 'tc-rank-v1:' + data.sort + ':' + data.period, previous = null, current = {};
  try { previous = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch (_) {}
  (data.ranks || []).forEach(function (entry) { current[String(entry.key)] = Number(entry.rank); });
  page.querySelectorAll('tr[data-creator-key]').forEach(function (row) {
   var cell = row.querySelector('.tc-rank-cell'); if (!cell) return;
   var mark = cell.querySelector('.tc-movement');
   if (!mark) { mark = document.createElement('small'); mark.className = 'tc-movement'; mark.setAttribute('aria-label', 'Rank movement since previous view'); cell.appendChild(mark); }
   var creatorKey = String(row.dataset.creatorKey), now = Number(row.dataset.rank);
   if (!previous || !Object.prototype.hasOwnProperty.call(previous, creatorKey)) { mark.textContent = 'No prior rank'; mark.dataset.tone = 'flat'; }
   else { var delta = Number(previous[creatorKey]) - now; mark.textContent = delta > 0 ? '↑ ' + delta : (delta < 0 ? '↓ ' + Math.abs(delta) : '— No change'); mark.dataset.tone = delta > 0 ? 'up' : (delta < 0 ? 'down' : 'flat'); }
  });
  try { sessionStorage.setItem(key, JSON.stringify(current)); } catch (_) {}
 }
 window.tcRefreshRankMovement = refreshRankMovement;
 refreshRankMovement();
 var density = document.getElementById('tcDensity');
 if (density) {
  try { page.classList.toggle('tc-compact', sessionStorage.getItem('tc-compact') === '1'); } catch (_) {}
  function labelDensity() { density.setAttribute('aria-pressed', String(page.classList.contains('tc-compact'))); density.textContent = page.classList.contains('tc-compact') ? 'Comfortable rows' : 'Compact rows'; }
  labelDensity();
  density.addEventListener('click', function () { page.classList.toggle('tc-compact'); labelDensity(); try { sessionStorage.setItem('tc-compact', page.classList.contains('tc-compact') ? '1' : '0'); } catch (_) {} });
 }
 var dialog = document.getElementById('tcDetails'), metrics = document.getElementById('tcDetailMetrics'), history = document.getElementById('tcDetailHistory');
 function addMetric(label, value) { var box=document.createElement('div'), dt=document.createElement('dt'), dd=document.createElement('dd'); dt.textContent=label; dd.textContent=Number(value).toLocaleString(); box.append(dt,dd); metrics.appendChild(box); }
 page.addEventListener('click', function (event) {
  var button = event.target.closest('.tc-details'); if (!button || !dialog || !metrics || !history) return;
  var d=button.dataset; document.getElementById('tcDetailName').textContent=d.name + ' · Creator analytics'; metrics.replaceChildren();
  addMetric('Leaderboard rank',d.rank); addMetric('Current subscribers',d.subscribers); addMetric(d.periodLabel + ' views',d.periodViews); addMetric('All-time views',d.views); addMetric('All-time likes',d.likes); addMetric('Videos',d.videos);
  history.textContent = d.currentViews !== '' && d.previousViews !== '' ? 'Views last 7 days: ' + Number(d.currentViews).toLocaleString() + ' · Previous 7 days: ' + Number(d.previousViews).toLocaleString() : 'View-history tracking is not configured, so recent comparisons are unavailable.';
  if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open','');
 });
 var close=document.getElementById('tcDetailClose'); if(close) close.addEventListener('click',function(){if(dialog.close)dialog.close();else dialog.removeAttribute('open');});
})();
</script>
<script>
(function () {
 'use strict';
 var page=document.querySelector('.tc-page');
 if(!page)return;
 var details=document.getElementById('tcDetails'),detailMetrics=document.getElementById('tcDetailMetrics'),detailVideos=document.getElementById('tcTopVideos');
 var compareDialog=document.getElementById('tcCompareDialog'),compareOpen=document.getElementById('tcCompareOpen');
 var compareClose=document.getElementById('tcCompareClose'),compareCount=document.getElementById('tcCompareCount');
 var compareStatus=document.getElementById('tcCompareStatus'),compareResults=document.getElementById('tcCompareResults');
 var selected=new Map();
 function update(root){
  (root||page).querySelectorAll('.tc-compare-select').forEach(function(input){input.checked=selected.has(input.value);});
  if(compareCount)compareCount.textContent=selected.size+'/3';
  if(compareOpen)compareOpen.disabled=selected.size<2;
  if(compareStatus)compareStatus.textContent=selected.size<2?'Select at least two creators.':selected.size+' creator(s) selected.';
 }
 function value(row){return {key:row.dataset.creatorKey,name:row.dataset.name,rank:row.dataset.rank,premium:row.dataset.premium,
  subscribers:row.dataset.subscribers,views:row.dataset.periodViews,payout:row.dataset.payout,likes:row.dataset.likes,
  videos:row.dataset.videos,engagement:row.dataset.engagement,growth:row.dataset.growth,growthAvailable:row.dataset.growthAvailable,milestones:row.dataset.milestones};}
 function openComparison(){
  if(!compareResults||selected.size<2)return;
  var creators=Array.from(selected.values());
  var metrics=[['Rank',function(c){return '#'+Number(c.rank).toLocaleString();}],['Premium',function(c){return c.premium;}],
   ['Subscribers',function(c){return Number(c.subscribers).toLocaleString();}],['Selected-period views',function(c){return Number(c.views).toLocaleString();}],
   ['Estimated payout',function(c){return 'PKR '+Number(c.payout).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}],
   ['All-time likes',function(c){return Number(c.likes).toLocaleString();}],['Videos',function(c){return Number(c.videos).toLocaleString();}],
   ['Engagement rate',function(c){return Number(c.engagement).toFixed(2)+'%';}],
   ['1M milestones',function(c){var names={views:'Views',subscribers:'Subscribers',likes:'Likes'};var earned=[];try{earned=JSON.parse(c.milestones||'[]');}catch(_){}return earned.length?earned.map(function(item){return'1M '+(names[item]||item);}).join(', '):'None yet';}],
   ['View change (7d)',function(c){if(c.growthAvailable!=='1')return'Unavailable';var n=Number(c.growth);return(n>0?'+':'')+n.toLocaleString();}]];
  var table=document.createElement('table');table.className='tc-compare-table';
  var head=document.createElement('thead'),headRow=document.createElement('tr'),heading=document.createElement('th');heading.textContent='Metric';headRow.appendChild(heading);
  creators.forEach(function(c){var th=document.createElement('th');th.textContent=c.name;headRow.appendChild(th);});head.appendChild(headRow);table.appendChild(head);
  var tbody=document.createElement('tbody');
  metrics.forEach(function(metric){var row=document.createElement('tr'),label=document.createElement('th');label.scope='row';label.textContent=metric[0];row.appendChild(label);creators.forEach(function(c){var cell=document.createElement('td');cell.textContent=metric[1](c);row.appendChild(cell);});tbody.appendChild(row);});
  table.appendChild(tbody);compareResults.replaceChildren(table);
  if(compareDialog.showModal)compareDialog.showModal();else compareDialog.setAttribute('open','');
 }
 page.addEventListener('change',function(event){
  var input=event.target.closest('.tc-compare-select');if(!input)return;
  var row=input.closest('tr[data-creator-key]');if(!row)return;
  if(input.checked){if(selected.size>=3){input.checked=false;if(compareStatus)compareStatus.textContent='You can compare up to three creators at once.';return;}selected.set(input.value,value(row));}
  else selected.delete(input.value);
  update();
 });
 window.tcSyncCompareSelections=update;
 update();
 if(compareOpen)compareOpen.addEventListener('click',openComparison);
 if(compareClose)compareClose.addEventListener('click',function(){if(compareDialog.close)compareDialog.close();else compareDialog.removeAttribute('open');});
 page.addEventListener('click',function(event){
  var button=event.target.closest('.tc-details');if(!button)return;
  var data=button.dataset;
  if(detailMetrics){
   var values=[['Leaderboard rank','#'+Number(data.rank).toLocaleString()],['Membership',data.premium],
    ['Current subscribers',Number(data.subscribers).toLocaleString()],[data.periodLabel+' views',Number(data.periodViews).toLocaleString()],
    ['Estimated payout','PKR '+Number(data.payout).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})],
    ['All-time views',Number(data.views).toLocaleString()],['All-time likes',Number(data.likes).toLocaleString()],
    ['Videos',Number(data.videos).toLocaleString()],['Engagement rate',Number(data.engagement).toFixed(2)+'%']];
   var names={views:'Views',subscribers:'Subscribers',likes:'Likes'},earned=[];
   try{earned=JSON.parse(data.milestones||'[]');}catch(_){}
   values.push(['1M milestones reached',earned.length?earned.map(function(item){return'1M '+(names[item]||item);}).join(', '):'None yet']);
   var fragment=document.createDocumentFragment();
   values.forEach(function(metric){var box=document.createElement('div'),term=document.createElement('dt'),definition=document.createElement('dd');term.textContent=metric[0];definition.textContent=metric[1];box.append(term,definition);fragment.appendChild(box);});
   detailMetrics.replaceChildren(fragment);
  }
  if(detailVideos){
   detailVideos.replaceChildren();var title=document.createElement('h3');title.textContent='Top videos by views';detailVideos.appendChild(title);
   if(data.videosAvailable!=='1'){var unavailable=document.createElement('p');unavailable.className='tc-detail-note';unavailable.textContent='Top videos are unavailable because video titles are not configured.';detailVideos.appendChild(unavailable);return;}
   var videos=[];try{videos=JSON.parse(data.topVideos||'[]');}catch(_){}
   if(!videos.length){var empty=document.createElement('p');empty.className='tc-detail-note';empty.textContent='No videos found for this creator.';detailVideos.appendChild(empty);return;}
   var list=document.createElement('ol');
   videos.forEach(function(video){var item=document.createElement('li');
    if(video.id){var link=document.createElement('a');link.href='../watch.php?id='+encodeURIComponent(video.id);link.textContent=video.title||'Untitled';item.appendChild(link);}
    else item.textContent=video.title||'Untitled';
    var counts=document.createElement('span');counts.className='tc-video-metrics';counts.textContent=Number(video.views||0).toLocaleString()+' views · '+Number(video.likes||0).toLocaleString()+' likes';item.appendChild(counts);list.appendChild(item);
   });
   detailVideos.appendChild(list);
  }
 });
 [details,compareDialog].forEach(function(dialog){if(dialog)dialog.addEventListener('click',function(event){if(event.target===dialog&&dialog.close)dialog.close();});});
})();
</script>
<script>
(function () {
 'use strict';
 function drawTrend(canvasId, seriesKey, color) {
  var canvas=document.getElementById(canvasId), node=document.getElementById('tcTrendData'); if(!canvas||!node)return;
  var data; try{data=JSON.parse(node.textContent||'{}');}catch(_){return;}
  var values=data[seriesKey]||[], labels=data.labels||[], ctx=canvas.getContext('2d'); if(!ctx||!values.length)return;
  var rect=canvas.getBoundingClientRect(), width=Math.max(260,rect.width||600), height=190, ratio=window.devicePixelRatio||1;
  canvas.width=Math.round(width*ratio); canvas.height=Math.round(height*ratio); ctx.setTransform(ratio,0,0,ratio,0,0); ctx.clearRect(0,0,width,height);
  var css=getComputedStyle(document.querySelector('.tc-admin')), muted=css.getPropertyValue('--tc-muted').trim()||'#aaa', grid=css.getPropertyValue('--tc-row-line').trim()||'#444';
  var pad={l:40,r:8,t:12,b:27}, plotW=width-pad.l-pad.r, plotH=height-pad.t-pad.b, max=Math.max(1,...values.map(Number)); ctx.font='10px system-ui'; ctx.textBaseline='middle';
  for(var g=0;g<=3;g++){var y=pad.t+plotH*g/3;ctx.beginPath();ctx.strokeStyle=grid;ctx.lineWidth=1;ctx.moveTo(pad.l,y);ctx.lineTo(width-pad.r,y);ctx.stroke();ctx.fillStyle=muted;ctx.textAlign='right';ctx.fillText(Math.round(max*(3-g)/3).toLocaleString(),pad.l-6,y);}
  var points=values.map(function(v,i){return{x:pad.l+plotW*i/Math.max(1,labels.length-1),y:pad.t+plotH-Number(v)/max*plotH};});
  ctx.beginPath();points.forEach(function(p,i){if(i===0)ctx.moveTo(p.x,p.y);else ctx.lineTo(p.x,p.y);});ctx.strokeStyle=color;ctx.lineWidth=2.5;ctx.lineJoin='round';ctx.stroke();
  var fill=ctx.createLinearGradient(0,pad.t,0,height-pad.b);fill.addColorStop(0,color+'33');fill.addColorStop(1,color+'00');ctx.lineTo(points[points.length-1].x,height-pad.b);ctx.lineTo(points[0].x,height-pad.b);ctx.closePath();ctx.fillStyle=fill;ctx.fill();
  [0,Math.floor((labels.length-1)/2),labels.length-1].forEach(function(i){if(i<0)return;ctx.fillStyle=muted;ctx.textAlign=i===0?'left':(i===labels.length-1?'right':'center');ctx.fillText(labels[i]||'',points[i].x,height-10);});
 }
 window.tcDrawTrends=function(){drawTrend('tcViewTrend','views','#a855f7');drawTrend('tcSubscriberTrend','subscribers','#22c7a5');};
 window.tcDrawTrends(); var resizeTimer;window.addEventListener('resize',function(){clearTimeout(resizeTimer);resizeTimer=setTimeout(window.tcDrawTrends,120);});
})();
</script>
</body>
</html>