<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/premium-perks.php';
require_once __DIR__ . '/avatar.php';
include __DIR__ . '/connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    header('Location: login.php?next=' . rawurlencode('creator-analytics.php'));
    exit;
}

$limits = vs_premium_user_limits($pdo instanceof PDO ? $pdo : null, $username);
$isPremium = in_array((string)$limits['tier'], ['premium', 'plus'], true);
$error = '';
$days = [];
$viewsByDay = [];
$chartMetrics = [
    'views' => ['label' => 'Views', 'color' => '#f5b942', 'available' => false, 'values' => []],
    'likes' => ['label' => 'Likes', 'color' => '#ef6b8a', 'available' => false, 'values' => []],
    'comments' => ['label' => 'Comments', 'color' => '#60a5fa', 'available' => false, 'values' => []],
    'subscribers' => ['label' => 'New subscribers', 'color' => '#34d399', 'available' => false, 'values' => []],
];
$videos = [];
$totals = ['views' => 0, 'likes' => 0, 'comments' => 0, 'video_count' => 0, 'subscribers' => null, 'new_subscribers' => null, 'views_30d' => null, 'live_viewers' => 0];
$creatorRanking = ['rank' => null, 'total_creators' => 0, 'views' => 0, 'top_three' => false];

for ($offset = 29; $offset >= 0; $offset--) {
    $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $days[] = $day;
    $viewsByDay[$day] = 0;
    foreach ($chartMetrics as &$metric) {
        $metric['values'][$day] = 0;
    }
    unset($metric);
}

if ($isPremium) {
    try {
        $columns = [];
        $columnResult = $conn->query('SHOW COLUMNS FROM `videos`');
        while ($column = $columnResult->fetch_assoc()) {
            $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
        }
        $ownerColumn = '';
        foreach (['username', 'channel_name', 'creator', 'uploader', 'author', 'user'] as $ownerCandidate) {
            if (isset($columns[$ownerCandidate])) {
                $ownerColumn = $columns[$ownerCandidate];
                break;
            }
        }
        if ($ownerColumn === '' || !isset($columns['id'])) {
            throw new RuntimeException('Your videos table is missing the creator or video ID field needed for analytics.');
        }

        $select = [
            '`' . str_replace('`', '``', $columns['id']) . '` AS video_id',
            isset($columns['title']) ? '`' . str_replace('`', '``', $columns['title']) . '` AS title' : "'' AS title",
            isset($columns['type']) ? '`' . str_replace('`', '``', $columns['type']) . '` AS type' : "'video' AS type",
            isset($columns['views']) ? 'COALESCE(`' . str_replace('`', '``', $columns['views']) . '`, 0) AS views' : '0 AS views',
            isset($columns['likes']) ? 'COALESCE(`' . str_replace('`', '``', $columns['likes']) . '`, 0) AS likes' : '0 AS likes',
        ];
        $dateColumn = '';
        foreach (['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date'] as $dateCandidate) {
            if (isset($columns[$dateCandidate])) {
                $dateColumn = $columns[$dateCandidate];
                break;
            }
        }
        $select[] = $dateColumn !== ''
            ? '`' . str_replace('`', '``', $dateColumn) . '` AS created_at'
            : 'NULL AS created_at';

        $thumbnailExpressions = [];
        foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'image', 'image_url', 'image_path', 'img', 'poster', 'cover', 'cover_image', 'preview'] as $thumbnailCandidate) {
            if (isset($columns[$thumbnailCandidate])) {
                $thumbnailExpressions[] = 'NULLIF(TRIM(CAST(`' . str_replace('`', '``', $columns[$thumbnailCandidate]) . '` AS CHAR)), \'\')';
            }
        }
        $select[] = $thumbnailExpressions !== []
            ? 'COALESCE(' . implode(', ', $thumbnailExpressions) . ") AS thumbnail"
            : "'' AS thumbnail";

        $sourceExpressions = [];
        foreach (['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed'] as $sourceCandidate) {
            if (isset($columns[$sourceCandidate])) {
                $sourceExpressions[] = 'NULLIF(TRIM(CAST(`' . str_replace('`', '``', $columns[$sourceCandidate]) . '` AS CHAR)), \'\')';
            }
        }
        $select[] = $sourceExpressions !== []
            ? 'COALESCE(' . implode(', ', $sourceExpressions) . ") AS source"
            : "'' AS source";
        $sql = 'SELECT ' . implode(', ', $select) . ' FROM `videos` WHERE LOWER(`'
            . str_replace('`', '``', $ownerColumn) . '`) = LOWER(?)';
        if ($dateColumn !== '') {
            $sql .= ' ORDER BY `' . str_replace('`', '``', $dateColumn) . '` DESC';
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $totals['video_count'] = count($videos);
        foreach ($videos as $video) {
            $totals['views'] += (int)$video['views'];
            $totals['likes'] += (int)$video['likes'];
        }
        usort($videos, static function ($a, $b) {
            return (int)$b['views'] <=> (int)$a['views'];
        });
        $videos = array_slice($videos, 0, 3);

        if (isset($columns['views'])) {
            $ownerSqlColumn = '`' . str_replace('`', '``', $ownerColumn) . '`';
            $viewSqlColumn = '`' . str_replace('`', '``', $columns['views']) . '`';
            $rankingResult = $conn->query(
                'SELECT LOWER(TRIM(CAST(' . $ownerSqlColumn . ' AS CHAR))) AS creator_key, '
                . 'COALESCE(SUM(COALESCE(' . $viewSqlColumn . ', 0)), 0) AS total_views '
                . 'FROM `videos` WHERE NULLIF(TRIM(CAST(' . $ownerSqlColumn . ' AS CHAR)), \'\') IS NOT NULL '
                . 'GROUP BY LOWER(TRIM(CAST(' . $ownerSqlColumn . ' AS CHAR))) '
                . 'ORDER BY total_views DESC, creator_key ASC'
            );
            $rankingRows = $rankingResult->fetch_all(MYSQLI_ASSOC);
            $rankingResult->close();
            $creatorRanking['total_creators'] = count($rankingRows);
            $previousViews = null;
            $competitionRank = 0;
            foreach ($rankingRows as $index => $rankingRow) {
                $rankingViews = (int)$rankingRow['total_views'];
                if ($previousViews === null || $rankingViews !== $previousViews) {
                    $competitionRank = $index + 1;
                }
                $previousViews = $rankingViews;
                if ((string)$rankingRow['creator_key'] === mb_strtolower($username, 'UTF-8')) {
                    $creatorRanking['rank'] = $competitionRank;
                    $creatorRanking['views'] = $rankingViews;
                    $creatorRanking['top_three'] = $competitionRank <= 3;
                    break;
                }
            }
        }

        try {
            $conn->query(
                'CREATE TABLE IF NOT EXISTS `video_view_events` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `content_type` VARCHAR(16) NOT NULL,
                    `content_id` VARCHAR(191) NOT NULL,
                    `creator` VARCHAR(191) NOT NULL DEFAULT \'\',
                    `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_video_view_events_creator_recent` (`creator`, `viewed_at`),
                    KEY `idx_video_view_events_content_recent` (`content_type`, `content_id`, `viewed_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $viewStmt = $conn->prepare(
                "SELECT DATE(`viewed_at`) AS view_day, COUNT(*) AS total
                 FROM `video_view_events`
                 WHERE LOWER(`creator`) = LOWER(?) AND `content_type` IN ('video', 'short')
                   AND `viewed_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                 GROUP BY DATE(`viewed_at`)"
            );
            $viewStmt->bind_param('s', $username);
            $viewStmt->execute();
            foreach ($viewStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $day = (string)$row['view_day'];
                if (array_key_exists($day, $viewsByDay)) {
                    $viewsByDay[$day] = (int)$row['total'];
                    $chartMetrics['views']['values'][$day] = (int)$row['total'];
                }
            }
            $viewStmt->close();
            $chartMetrics['views']['available'] = true;
            $totals['views_30d'] = array_sum($viewsByDay);
        } catch (Throwable $viewError) {
            error_log('Creator daily view events are unavailable: ' . $viewError->getMessage());
        }

        try {
            $liveTable = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'live_streams'"
            )->fetchColumn();
            if ((int)$liveTable > 0) {
                $liveStmt = $pdo->prepare(
                    "SELECT COALESCE(SUM(viewer_count), 0)
                     FROM live_streams
                     WHERE LOWER(username) = LOWER(?) AND status = 'live'"
                );
                $liveStmt->execute([$username]);
                $totals['live_viewers'] = (int)$liveStmt->fetchColumn();
            }
        } catch (Throwable $liveError) {
            error_log('Creator live viewer analytics unavailable: ' . $liveError->getMessage());
        }

        $subscriptionTable = $conn->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'user_subscriptions'"
        );
        if ((int)$subscriptionTable->fetch_row()[0] > 0) {
            $subColumns = [];
            $subColumnResult = $conn->query('SHOW COLUMNS FROM `user_subscriptions`');
            while ($column = $subColumnResult->fetch_assoc()) {
                $subColumns[strtolower((string)$column['Field'])] = (string)$column['Field'];
            }
            if (isset($subColumns['channel_username'])) {
                $countStmt = $conn->prepare('SELECT COUNT(*) FROM `user_subscriptions` WHERE LOWER(`' . str_replace('`', '``', $subColumns['channel_username']) . '`) = LOWER(?)');
                $countStmt->bind_param('s', $username);
                $countStmt->execute();
                $totals['subscribers'] = (int)$countStmt->get_result()->fetch_row()[0];
                $countStmt->close();
                if (isset($subColumns['created_at'])) {
                    $subscriptionDate = '`' . str_replace('`', '``', $subColumns['created_at']) . '`';
                    $growthStmt = $conn->prepare(
                        'SELECT DATE(' . $subscriptionDate . ') AS activity_day, COUNT(*) AS total
                         FROM `user_subscriptions`
                         WHERE LOWER(`' . str_replace('`', '``', $subColumns['channel_username']) . '`) = LOWER(?)
                           AND ' . $subscriptionDate . ' >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                         GROUP BY DATE(' . $subscriptionDate . ')'
                    );
                    $growthStmt->bind_param('s', $username);
                    $growthStmt->execute();
                    foreach ($growthStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                        $day = (string)$row['activity_day'];
                        if (array_key_exists($day, $chartMetrics['subscribers']['values'])) {
                            $chartMetrics['subscribers']['values'][$day] = (int)$row['total'];
                        }
                    }
                    $growthStmt->close();
                    $chartMetrics['subscribers']['available'] = true;
                    $totals['new_subscribers'] = array_sum($chartMetrics['subscribers']['values']);
                }
            }
        }

        $tableExists = static function (string $table) use ($conn): bool {
            $check = $conn->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
            );
            $check->bind_param('s', $table);
            $check->execute();
            $exists = (bool)$check->get_result()->fetch_row();
            $check->close();
            return $exists;
        };
        $readColumns = static function (string $table) use ($conn): array {
            $result = $conn->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            $found = [];
            while ($column = $result->fetch_assoc()) {
                $found[strtolower((string)$column['Field'])] = (string)$column['Field'];
            }
            $result->free();
            return $found;
        };
        $quotedOwner = 'v.`' . str_replace('`', '``', $ownerColumn) . '`';

        if ($tableExists('comments')) {
            $commentColumns = $readColumns('comments');
            if (isset($commentColumns['video_id'], $commentColumns['created_at'])) {
                $commentDate = '`' . str_replace('`', '``', $commentColumns['created_at']) . '`';
                $commentVideo = '`' . str_replace('`', '``', $commentColumns['video_id']) . '`';
                $commentCountStmt = $conn->prepare(
                    'SELECT COUNT(*)
                     FROM `comments` c
                     INNER JOIN `videos` v ON CAST(v.`' . str_replace('`', '``', $columns['id']) . '` AS CHAR) = CAST(c.' . $commentVideo . ' AS CHAR)
                     WHERE LOWER(' . $quotedOwner . ') = LOWER(?)'
                );
                $commentCountStmt->bind_param('s', $username);
                $commentCountStmt->execute();
                $totals['comments'] = (int)$commentCountStmt->get_result()->fetch_row()[0];
                $commentCountStmt->close();
                $commentStmt = $conn->prepare(
                    'SELECT DATE(c.' . $commentDate . ') AS activity_day, COUNT(*) AS total
                     FROM `comments` c
                     INNER JOIN `videos` v ON CAST(v.`' . str_replace('`', '``', $columns['id']) . '` AS CHAR) = CAST(c.' . $commentVideo . ' AS CHAR)
                     WHERE LOWER(' . $quotedOwner . ') = LOWER(?)
                       AND c.' . $commentDate . ' >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     GROUP BY DATE(c.' . $commentDate . ')'
                );
                $commentStmt->bind_param('s', $username);
                $commentStmt->execute();
                foreach ($commentStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $day = (string)$row['activity_day'];
                    if (array_key_exists($day, $chartMetrics['comments']['values'])) {
                        $chartMetrics['comments']['values'][$day] = (int)$row['total'];
                    }
                }
                $commentStmt->close();
                $chartMetrics['comments']['available'] = true;
            }
        }

        if ($tableExists('video_likes')) {
            $likeColumns = $readColumns('video_likes');
            if (isset($likeColumns['video_id'], $likeColumns['created_at'])) {
                $likeDate = '`' . str_replace('`', '``', $likeColumns['created_at']) . '`';
                $likeVideo = '`' . str_replace('`', '``', $likeColumns['video_id']) . '`';
                $likeStmt = $conn->prepare(
                    'SELECT DATE(l.' . $likeDate . ') AS activity_day, COUNT(*) AS total
                     FROM `video_likes` l
                     INNER JOIN `videos` v ON CAST(v.`' . str_replace('`', '``', $columns['id']) . '` AS CHAR) = CAST(l.' . $likeVideo . ' AS CHAR)
                     WHERE LOWER(' . $quotedOwner . ') = LOWER(?)
                       AND l.' . $likeDate . ' >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
                     GROUP BY DATE(l.' . $likeDate . ')'
                );
                $likeStmt->bind_param('s', $username);
                $likeStmt->execute();
                foreach ($likeStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                    $day = (string)$row['activity_day'];
                    if (array_key_exists($day, $chartMetrics['likes']['values'])) {
                        $chartMetrics['likes']['values'][$day] = (int)$row['total'];
                    }
                }
                $likeStmt->close();
                $chartMetrics['likes']['available'] = true;
            }
        }
    } catch (Throwable $analyticsError) {
        error_log('Creator analytics could not be loaded: ' . $analyticsError->getMessage());
        $error = 'Analytics could not be loaded right now. Please try again later.';
    }
}

$chartPayload = [];
foreach ($chartMetrics as $key => $metric) {
    $chartPayload[$key] = [
        'label' => $metric['label'],
        'color' => $metric['color'],
        'available' => $metric['available'],
        'values' => array_values($metric['values']),
    ];
}
$chartLabels = array_map(static function ($day) { return date('M j', strtotime($day)); }, $days);
$h = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
if ((string)($_GET['live'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $liveTopContent = [];
    foreach ($videos as $video) {
        $thumbnail = trim((string)($video['thumbnail'] ?? ''));
        $source = trim((string)($video['source'] ?? ''));
        $youtubeId = '';
        foreach ([$source, (string)$video['video_id']] as $sourceValue) {
            $sourceValue = trim((string)$sourceValue);
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $sourceValue)) {
                $youtubeId = $sourceValue;
                break;
            }
            if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $sourceValue, $youtubeMatch)) {
                $youtubeId = $youtubeMatch[1];
                break;
            }
        }
        if ($thumbnail === '' && $youtubeId !== '') {
            $thumbnail = 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
        } elseif ($thumbnail !== '' && !preg_match('#^(https?:)?//|^data:#i', $thumbnail) && $thumbnail[0] !== '/') {
            $thumbnail = './' . ltrim($thumbnail, '/');
        }
        if ($thumbnail === '') {
            $thumbnail = 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="136" height="88"><rect width="136" height="88" fill="#1b2040"/><path d="M50 25l48 19-48 19z" fill="#7c4dff"/></svg>');
        }
        $isShort = strtolower((string)$video['type']) === 'short';
        $ageLabel = '';
        if (!empty($video['created_at']) && ($createdTimestamp = strtotime((string)$video['created_at'])) !== false) {
            $seconds = max(0, time() - $createdTimestamp);
            foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]] as $unit) {
                if ($seconds >= $unit[1]) {
                    $count = intdiv($seconds, $unit[1]);
                    $ageLabel = $count . ' ' . $unit[0] . ($count === 1 ? '' : 's') . ' ago';
                    break;
                }
            }
        }
        $liveTopContent[] = [
            'id' => (string)$video['video_id'],
            'title' => (string)($video['title'] !== '' ? $video['title'] : 'Untitled'),
            'views' => (int)$video['views'],
            'type' => $isShort ? 'Short' : 'Video',
            'is_short' => $isShort,
            'thumbnail' => $thumbnail,
            'age' => $ageLabel,
        ];
    }
    echo json_encode([
        'ok' => $error === '',
        'metrics' => $chartPayload,
        'labels' => $chartLabels,
        'live_viewers' => $totals['live_viewers'],
        'creator_rank' => $creatorRanking['rank'],
        'total_creators' => $creatorRanking['total_creators'],
        'top_content' => $liveTopContent,
        'totals' => [
            'views' => $totals['views'],
            'likes' => $totals['likes'],
            'comments' => $totals['comments'],
            'video_count' => $totals['video_count'],
            'subscribers' => $totals['subscribers'],
            'new_subscribers' => $totals['new_subscribers'],
        ],
    ], JSON_THROW_ON_ERROR);
    exit;
}

$maxContentViews = 1;
foreach ($videos as $video) {
    $maxContentViews = max($maxContentViews, (int)$video['views']);
}

$compact = static function ($n): string {
    $n = (float)$n;
    $abs = abs($n);
    if ($abs >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
    if ($abs >= 1000) { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
    return number_format($n);
};
// Change in the last 15 days vs the 15 days before (real data only; null when there is nothing to compare)
$trendOf = static function (array $metric): ?float {
    if (empty($metric['available'])) { return null; }
    $v = array_values($metric['values']);
    $prev = array_sum(array_slice($v, 0, 15));
    $curr = array_sum(array_slice($v, 15));
    if ($prev <= 0) { return null; }
    return ($curr - $prev) / $prev * 100;
};
// Last 7 days as bar heights (percent) for the little bars on each stat card
$sparkOf = static function (array $metric): array {
    $v = array_map('intval', array_slice(array_values($metric['values']), -7));
    $max = $v ? max($v) : 0;
    $out = [];
    foreach ($v as $x) { $out[] = $max > 0 ? max(14, (int)round($x / $max * 100)) : 14; }
    return $out;
};
$ago = static function ($value): string {
    $t = $value ? strtotime((string)$value) : false;
    if (!$t) { return ''; }
    $d = max(0, time() - $t);
    foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]] as $unit) {
        if ($d >= $unit[1]) {
            $n = intdiv($d, $unit[1]);
            return $n . ' ' . $unit[0] . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
};
$iconSet = [
    'eye'      => ['s', '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>'],
    'thumb'    => ['s', '<path d="M7 10v11H3V10h4zM7 10l4-8c1.7 0 3 1.3 3 3v3h5.5a2 2 0 0 1 2 2.3l-1.4 8a2 2 0 0 1-2 1.7H7"/>'],
    'message'  => ['s', '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>'],
    'users'    => ['s', '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14.2c2.6.2 4.5 2 4.5 4.8"/>'],
    'user'     => ['f', '<circle cx="12" cy="7.5" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7z"/>'],
    'play'     => ['f', '<path d="M6.5 4.2v15.6c0 .8.9 1.3 1.6.8l12-7.8c.6-.4.6-1.2 0-1.6l-12-7.8c-.7-.5-1.6 0-1.6.8z"/>'],
    'clock'    => ['s', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'],
    'heart'    => ['f', '<path d="M12 21s-8-5.2-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 10c0 5.8-8 11-8 11z"/>'],
    'star'     => ['f', '<path d="M12 2.8l2.9 5.9 6.5.9-4.7 4.6 1.1 6.4-5.8-3-5.8 3 1.1-6.4L2.6 9.6l6.5-.9z"/>'],
    'calendar' => ['s', '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>'],
    'chevron'  => ['s', '<path d="M6 9l6 6 6-6"/>'],
    'trend'    => ['s', '<path d="M3 17l6-6 4 4 8-9"/><path d="M15 6h6v6"/>'],
    'up'       => ['s', '<path d="M12 19V5M5 12l7-7 7 7"/>'],
    'down'     => ['s', '<path d="M12 5v14M19 12l-7 7-7-7"/>'],
    'arrow'    => ['s', '<path d="M5 12h14M13 6l6 6-6 6"/>'],
    'analytics'=> ['s', '<path d="M3 20h18M6 16v-4M12 16V8M18 16V4"/><path d="M4 9l5-4 4 2 6-5"/>'],
    'trophy'   => ['s', '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M7 6H4v2a4 4 0 0 0 4 4M17 6h3v2a4 4 0 0 1-4 4"/>'],
    'rocket'   => ['s', '<path d="M5 15c-1.5 0-2.5 1-3 4 3-.5 4-1.5 4-3M8 13l-3 3M11 16c5-2 8-7 9-14-7 1-12 4-14 9l5 5z"/><circle cx="15.5" cy="8.5" r="1.5"/>'],
    'verified' => ['r', '<circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M7.5 12.5l3 3 6-6.5" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'],
];
$ic = static function (string $name) use ($iconSet): string {
    if (!isset($iconSet[$name])) { return ''; }
    [$mode, $body] = $iconSet[$name];
    $attr = $mode === 'f' ? 'fill="currentColor"'
        : ($mode === 's' ? 'fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' : '');
    return '<svg class="ca-ico" viewBox="0 0 24 24" aria-hidden="true" ' . $attr . '>' . $body . '</svg>';
};

$extraCss = <<<'CSS'
.ca-page{--ca-line:rgba(124,92,255,.30);--ca-card:linear-gradient(180deg,rgba(26,20,66,.88),rgba(12,10,34,.92));--ca-muted:#a9a6d6;--ca-track:rgba(139,92,246,.22);--ca-pt:#0d0b2b;max-width:1400px;margin:0 auto;padding:14px clamp(16px,1.8vw,26px) 60px;color:var(--text,#eaf0ff)}
.ca-page *{box-sizing:border-box}
.ca-ico{width:1em;height:1em;flex:0 0 auto;display:inline-block;vertical-align:-.125em}

/* header */
.ca-profile{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:96px;overflow:hidden;padding:15px 20px;margin-bottom:14px;border:1px solid rgba(139,92,246,.22);border-radius:20px;background:linear-gradient(105deg,rgba(22,13,51,.98),rgba(12,11,30,.97) 56%,rgba(31,9,71,.94));}
.ca-profile::after{content:"";position:absolute;top:-95px;right:9%;width:310px;height:245px;pointer-events:none;transform:rotate(-25deg);background:linear-gradient(115deg,transparent 35%,rgba(124,0,255,.05) 36%,rgba(124,0,255,.23) 53%,rgba(124,0,255,.05) 69%,transparent 70%);filter:blur(1px)}
.ca-profile-user{position:relative;z-index:1;display:flex;align-items:center;gap:20px;min-width:0}
.ca-avatar{width:78px;height:78px;flex:0 0 78px;display:grid;place-items:center;border:1px solid rgba(176,87,255,.6);border-radius:18px;object-fit:cover;background:linear-gradient(145deg,#8a00ff,#4211a0);box-shadow:0 0 22px rgba(124,0,255,.38);color:#fff;font-size:38px;font-weight:800}
.ca-profile-user h1{display:flex;align-items:center;gap:10px;margin:0;color:#fff;font-size:clamp(26px,3.3vw,38px);line-height:1.1;font-weight:800;letter-spacing:-.02em}
.ca-verified{display:inline-flex;font-size:.62em;color:#8b5cf6}
.ca-profile-user p{margin:5px 0 0;color:#c5bedf;font-size:15px}
.ca-profile-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.ca-period{position:relative;z-index:1;display:inline-flex;align-items:center;gap:10px;height:44px;padding:0 16px;border-radius:13px;border:1px solid rgba(139,92,246,.52);background:rgba(15,12,35,.76);font-weight:600;font-size:14px;box-shadow:0 0 18px -6px rgba(139,92,246,.5)}
.ca-period .ca-ico{font-size:18px}
/* stat cards */
.ca-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 14px}
.ca-kpi{--k:#8b5cf6;display:grid;grid-template-columns:48px minmax(0,1fr) auto;grid-template-rows:auto auto auto;grid-template-areas:"icon label spark" "icon value spark" "icon trend spark";align-items:center;column-gap:10px;min-height:104px;padding:12px;border-radius:17px;overflow:hidden;border:1px solid color-mix(in srgb,var(--k) 38%,rgba(70,74,130,.35));background:linear-gradient(145deg,color-mix(in srgb,var(--k) 13%,#0b0a24),rgba(14,14,34,.96));box-shadow:0 12px 30px -24px var(--k);transition:transform .15s ease,box-shadow .15s ease}
.ca-kpi:hover{transform:translateY(-2px);box-shadow:0 18px 44px -18px var(--k)}
.k-views{--k:#8b5cf6}.k-likes{--k:#ec4899}.k-comments{--k:#3b82f6}.k-subs{--k:#10b981}
.ca-kpi-icon{grid-area:icon;width:44px;height:44px;display:grid;place-items:center;border-radius:13px;background:color-mix(in srgb,var(--k) 32%,#100d2b);color:color-mix(in srgb,var(--k) 72%,#fff);font-size:23px}
.ca-kpi-label{grid-area:label;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:600;color:#e5e2f5}
.ca-kpi-value{grid-area:value;margin-top:2px;font-size:clamp(25px,2.4vw,32px);font-weight:800;line-height:1.05;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.ca-trend{grid-area:trend;display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin-top:2px;font-size:12px;font-weight:700}
.ca-trend.is-up{color:#34d399}.ca-trend.is-down{color:#fb7185}.ca-trend.is-flat{color:var(--ca-muted)}
.ca-trend small{font-size:10px;font-weight:500;color:var(--ca-muted)}
.ca-spark{grid-area:spark;display:flex;align-items:flex-end;gap:3px;height:48px}
.ca-spark i{display:block;width:5px;border-radius:3px 3px 1px 1px;background:linear-gradient(180deg,color-mix(in srgb,var(--k) 55%,#fff),var(--k));opacity:.9}

/* panels */
.ca-panel{margin-top:14px;padding:17px 18px;border-radius:18px;border:1px solid var(--ca-line);background:var(--ca-card);box-shadow:0 0 30px -16px rgba(139,92,246,.6)}
.ca-panel-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
.ca-panel h2{display:flex;align-items:center;gap:10px;margin:0;font-size:17px;font-weight:700}
.ca-panel h2 .ca-ico{font-size:22px;color:#a78bfa}
.ca-view-all{display:inline-flex;align-items:center;gap:6px;font-size:15px;font-weight:700;color:#a78bfa;text-decoration:none}
.ca-view-all:hover{color:#c4b5fd}
.ca-overview-grid{display:grid;grid-template-columns:minmax(0,2.7fr) minmax(250px,1fr);align-items:stretch;gap:16px}
.ca-overview-grid>.ca-panel{min-width:0;margin-top:0}
.ca-rank-panel{display:flex;flex-direction:column;justify-content:space-between;min-height:270px;background:radial-gradient(circle at 85% 55%,rgba(245,185,66,.11),transparent 40%),linear-gradient(160deg,rgba(27,24,47,.97),rgba(12,10,29,.97))}
.ca-rank-heading{display:flex;align-items:center;gap:10px}
.ca-rank-heading .ca-ico{color:#f5c84c!important}
.ca-rank-display{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 0}
.ca-rank-number{display:block;color:#b454ff;font-size:clamp(42px,5vw,58px);font-weight:900;line-height:1;letter-spacing:-.05em;text-shadow:0 0 25px rgba(160,60,255,.25)}
.ca-rank-caption{display:block;margin-top:8px;color:var(--ca-muted);font-size:12px}
.ca-rank-emblem{display:grid;place-items:center;width:86px;height:86px;color:#f7ce52;font-size:57px;filter:drop-shadow(0 5px 13px rgba(245,185,66,.42))}
.ca-rank-emblem .ca-ico{font-size:70px}
.ca-rank-badge{display:flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:8px;border-radius:12px;background:linear-gradient(100deg,#4a0da1,#351078);color:#f4eaff;font-size:12px;font-weight:700;text-align:center}
.ca-rank-badge .ca-ico{color:#ffdc58}

/* chart */
.ca-legend{display:flex;flex-wrap:wrap;gap:6px 18px}
.ca-chip{display:inline-flex;align-items:center;gap:8px;padding:4px 2px;border:0;background:none;color:var(--ca-muted);font:inherit;font-size:14px;cursor:pointer;transition:color .15s,opacity .15s}
.ca-chip:hover{color:var(--text,#fff)}
.ca-chip i{width:12px;height:12px;border-radius:50%;display:inline-block;box-shadow:0 0 8px var(--c)}
.ca-chip[aria-pressed="false"]{opacity:.45}
.ca-chip[aria-pressed="false"] i{background:transparent!important;box-shadow:inset 0 0 0 2px var(--c)}
.ca-chip[disabled]{cursor:default;opacity:.35}
.ca-chart-wrap{position:relative;width:100%;touch-action:pan-y}
.ca-chart{display:block;width:100%;height:auto;overflow:visible;user-select:none}
.ca-chart text{fill:var(--ca-muted);font-size:12px;font-family:inherit}
.ca-chart .grid{stroke:rgba(139,92,246,.22);stroke-width:1;shape-rendering:crispEdges}
.ca-chart .series{fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 0 5px currentColor)}
.ca-chart .cursor{stroke-width:1.2}
.ca-chart .pt{stroke:var(--ca-pt);stroke-width:2.5}
.ca-tip{position:absolute;top:6px;pointer-events:none;min-width:130px;max-width:calc(100% - 16px);padding:10px 14px;border-radius:12px;background:rgba(10,8,30,.94);border:1px solid rgba(139,92,246,.55);box-shadow:0 10px 30px rgba(0,0,0,.4);font-size:13px;opacity:0;transition:opacity .12s,left .12s;z-index:2}
.ca-tip.on{opacity:1}
.ca-tip strong{display:block;margin-bottom:6px;font-size:13px}
.ca-tip div{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:2px 0}
.ca-tip span{display:inline-flex;align-items:center;gap:6px;color:var(--ca-muted)}
.ca-tip i{width:8px;height:8px;border-radius:50%;display:inline-block}
.ca-tip em{font-style:normal;font-weight:700;font-variant-numeric:tabular-nums;text-align:right}
.ca-live-status{margin:10px 0 0;font-size:12px;color:var(--ca-muted);display:inline-flex;align-items:center;gap:7px}
.ca-live-status::before{content:"";width:8px;height:8px;border-radius:50%;background:#34d399;box-shadow:0 0 10px #34d399;animation:caPulse 2s infinite}
@keyframes caPulse{70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}

/* top videos + stats overview */
.ca-bottom-grid{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(280px,1fr);gap:16px;margin-top:16px}
.ca-bottom-grid .ca-panel{margin-top:0}
.ca-content-list{display:grid}
.ca-content-row{display:grid;grid-template-columns:30px 112px minmax(0,1fr) minmax(120px,190px);align-items:center;gap:14px;padding:10px 0;border-top:1px solid var(--ca-line)}
.ca-content-rank{font-size:18px;font-weight:700;text-align:center}
.ca-content-thumb{width:112px;height:64px;border-radius:10px;object-fit:cover;background:#1b1646}
.ca-content-play{position:relative;width:112px;height:64px;padding:0;border:0;border-radius:10px;background:#1b1646;cursor:pointer;overflow:hidden;text-decoration:none;box-shadow:0 0 0 0 rgba(139,92,246,0);transition:transform .15s,box-shadow .15s}
.ca-content-play:hover{transform:translateY(-1px);box-shadow:0 0 18px rgba(139,92,246,.45)}
.ca-content-play::after{content:"";position:absolute;left:50%;top:50%;z-index:2;width:34px;height:34px;transform:translate(-50%,-50%);border-radius:50%;background:rgba(10,8,30,.82);border:1px solid rgba(255,255,255,.55);box-shadow:0 4px 18px rgba(0,0,0,.45)}
.ca-content-play::before{content:"";position:absolute;z-index:3;left:calc(50% - 4px);top:calc(50% - 7px);border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid #fff;filter:drop-shadow(0 0 5px rgba(167,139,250,.9))}
.ca-content-play video,.ca-content-play .ca-content-thumb{position:absolute;inset:0;display:block;width:100%;height:100%;object-fit:cover;border-radius:10px}
.ca-content-play video{pointer-events:none}
.ca-content-info{min-width:0}
.ca-content-info a{display:block;color:inherit;text-decoration:none;font-weight:700;font-size:16px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ca-content-info a:hover{color:#c4b5fd}
.ca-content-info small{display:block;margin-top:4px;color:var(--ca-muted);font-size:14px}
.ca-content-view{display:grid;gap:8px;justify-items:center}
.ca-views-num{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:16px}
.ca-bar{width:100%;height:8px;border-radius:99px;background:var(--ca-track);overflow:hidden}
.ca-bar span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#6d3cff,#a78bfa)}
.ca-stats-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.ca-stat-row{display:flex;align-items:center;gap:9px;min-width:0;padding:10px;border:1px solid rgba(124,92,255,.19);border-radius:12px;background:rgba(124,92,255,.07);font-size:12px}
.ca-stat-icon{width:24px;font-size:19px;display:grid;place-items:center;color:var(--ic,#a78bfa)}
.ca-stat-name{flex:1;min-width:0}
.ca-stat-value{font-weight:700;font-variant-numeric:tabular-nums}
.ca-promo{display:flex;align-items:center;gap:12px;margin-top:12px;padding:13px;border:1px solid rgba(145,38,255,.55);border-radius:15px;background:linear-gradient(105deg,#4412a1,#24104e 78%);box-shadow:0 0 22px rgba(113,28,234,.18)}
.ca-promo-icon{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:13px;background:rgba(255,255,255,.12);color:#fff;font-size:27px}
.ca-promo-copy{flex:1;min-width:0}
.ca-promo-copy strong{display:block;font-size:13px}
.ca-promo-copy span{display:block;margin-top:3px;color:#d4c6f5;font-size:11px;line-height:1.35}
.ca-promo-link{display:inline-flex;align-items:center;gap:5px;flex:0 0 auto;padding:8px 11px;border-radius:10px;background:#8100ff;color:#fff;font-size:11px;font-weight:700;text-decoration:none}
.ca-promo-link:hover{background:#9e3aff;color:#fff}

/* states */
.ca-empty{padding:16px;border-radius:12px;border:1px dashed var(--ca-line);color:var(--ca-muted);margin:12px 0}
.ca-alert{padding:16px;border-radius:14px;margin:12px 0;border:1px solid rgba(236,72,153,.55);background:rgba(236,72,153,.12)}
.ca-upgrade{display:inline-flex;margin-top:14px;padding:11px 20px;border-radius:999px;font-weight:700;text-decoration:none;color:#fff;background:linear-gradient(90deg,#7c3aed,#ec4899);box-shadow:0 0 20px rgba(236,72,153,.45);transition:transform .15s,box-shadow .15s}
.ca-upgrade:hover{transform:translateY(-1px);box-shadow:0 0 30px rgba(236,72,153,.7)}

@media (max-width:1180px){
  .ca-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ca-overview-grid{grid-template-columns:1fr}
  .ca-bottom-grid{grid-template-columns:1fr}
}
@media (max-width:560px){
  .ca-grid{grid-template-columns:1fr}
  .ca-panel{padding:15px}
  .ca-avatar{width:64px;height:64px;flex-basis:64px}
  .ca-profile{padding:13px;gap:10px}
  .ca-profile-actions{width:100%}
  .ca-overview-grid{grid-template-columns:1fr}
  .ca-rank-panel{min-height:220px}
  .ca-content-row{grid-template-columns:24px 78px minmax(0,1fr)}
  .ca-content-thumb{width:78px;height:48px}
  .ca-content-play{width:78px;height:48px}
  .ca-content-view{grid-column:2 / -1;justify-items:start}
  .ca-promo{flex-wrap:wrap}
  .ca-promo-link{margin-left:auto}
}
@media (prefers-reduced-motion:reduce){.ca-live-status::before{animation:none}.ca-kpi,.ca-upgrade{transition:none}}
/* ===== NEON THEME (dark + light) ===== */
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2e93; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #00e5ff;
            --neon-cyan: #00e5ff; --neon-pink: #ff2e93; --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) body .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(0,229,255,.35); box-shadow: 0 1px 24px -4px rgba(0,229,255,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) body .brand { background: linear-gradient(90deg, #00e5ff, #8a5cff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) body .nav-toggle span { background: #00e5ff; box-shadow: 0 0 6px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) body .nav-toggle:hover, html:not([data-theme="light"]) body .theme-toggle:hover { background: rgba(0,229,255,.14); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) body .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) body .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) body .create-btn, html:not([data-theme="light"]) body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) body #search-input:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 20px rgba(0,229,255,.55); }
        html:not([data-theme="light"]) body .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) body .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) body .sidebar-link:hover { background: rgba(0,229,255,.10); box-shadow: inset 3px 0 0 #00e5ff, 0 0 16px -4px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body .sidebar-heading { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #8a5cff, #ff2e93); border-radius: 8px; }
        html:not([data-theme="light"]) body ::selection { background: rgba(255,46,147,.4); color: #fff; }


html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2e93; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #00b8e6;
            --neon-cyan: #00b8e6; --neon-pink: #ff2e93; --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] body .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(0,184,230,.55); box-shadow: 0 1px 22px -4px rgba(0,184,230,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] body .brand { background: linear-gradient(90deg, #00b8e6, #7c4dff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] body .nav-toggle span { background: #7c4dff; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] body .nav-toggle:hover, html[data-theme="light"] body .theme-toggle:hover { background: rgba(0,184,230,.14); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] body .theme-toggle svg { color: #7c4dff; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] body .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] body .create-btn, html[data-theme="light"] body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] body #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] body #search-input:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 20px rgba(0,184,230,.55); }
        html[data-theme="light"] body .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] body .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] body .sidebar-link:hover { background: rgba(0,184,230,.12); box-shadow: inset 3px 0 0 #00b8e6, 0 0 16px -4px rgba(0,184,230,.5); }
        html[data-theme="light"] body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] body .sidebar-heading { color: #7c4dff; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] body ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #7c4dff, #ff2e93); border-radius: 8px; }
        html[data-theme="light"] body ::selection { background: rgba(255,46,147,.4); color: #0b1030; }

/* ---- creator analytics (light) ---- */
html[data-theme="light"] .ca-page{--ca-line:rgba(124,77,255,.25);--ca-card:#ffffff;--ca-muted:#55608f;--ca-track:rgba(124,77,255,.15);--ca-pt:#ffffff}
html[data-theme="light"] .ca-kpi{background:linear-gradient(145deg,color-mix(in srgb,var(--k) 16%,#fff),#fff)}
html[data-theme="light"] .ca-kpi-label{color:#0b1030}
html[data-theme="light"] .ca-kpi-icon{background:color-mix(in srgb,var(--k) 15%,#fff);color:var(--k)}
html[data-theme="light"] .ca-period{background:#fff}
html[data-theme="light"] .ca-rank-panel{background:radial-gradient(circle at 85% 55%,rgba(245,185,66,.14),transparent 40%),linear-gradient(160deg,#fff,#f8f7ff)}
html[data-theme="light"] .ca-rank-badge{color:#fff}
html[data-theme="light"] .ca-promo{color:#fff}
html[data-theme="light"] .ca-tip{background:#fff}
html[data-theme="light"] .ca-chart .grid{stroke:rgba(124,77,255,.2)}
html[data-theme="light"] .ca-chip:hover{color:#0b1030}
html[data-theme="light"] .ca-content-info a:hover,html[data-theme="light"] .ca-view-all:hover{color:#6d28d9}
html[data-theme="light"] .ca-view-all{color:#7c4dff}
html[data-theme="light"] .ca-content-thumb{background:#e3e8fc}

/* Match the dashboard's purple-on-charcoal navigation palette. */
html:not([data-theme="light"]) body .app-navbar{background:rgba(5,5,16,.91);border-bottom-color:rgba(124,0,255,.28);box-shadow:0 1px 22px -8px rgba(124,0,255,.4)}
html:not([data-theme="light"]) body .brand{background:linear-gradient(90deg,#fff,#fff 55%,#9b37ff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 8px rgba(124,0,255,.45))}
html:not([data-theme="light"]) body #search-input{border-color:rgba(139,92,246,.42);box-shadow:0 0 10px -2px rgba(124,0,255,.25)}
html:not([data-theme="light"]) body #search-input:focus{border-color:#9b37ff;box-shadow:0 0 0 1px #9b37ff,0 0 18px rgba(124,0,255,.4)}
html:not([data-theme="light"]) body .app-sidebar{border-right-color:rgba(139,92,246,.28);box-shadow:4px 0 24px -12px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body .sidebar-link:hover{background:rgba(124,0,255,.12);box-shadow:inset 3px 0 0 #8b00ff,0 0 14px -5px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body .sidebar-link.active{background:linear-gradient(90deg,rgba(124,0,255,.76),rgba(96,0,212,.72));box-shadow:inset 3px 0 0 #c58aff,0 0 18px -5px rgba(124,0,255,.5)}
html:not([data-theme="light"]) body .sidebar-heading{color:#b48aff;text-shadow:0 0 8px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body{background:radial-gradient(850px 460px at 62% -12%,rgba(91,0,177,.13),transparent 70%),#05040e;background-attachment:fixed}
.ca-sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
CSS;
vs_page_start('Creator Analytics', 'creator-analytics', $extraCss);
?>
<div class="ca-page">
    <?php if (!$isPremium): ?>
        <div class="ca-alert">Creator Analytics is a Premium benefit. Upgrade to see your private channel performance.</div>
        <a class="ca-upgrade" href="premium.php">Explore Premium</a>
    <?php elseif ($error !== ''): ?>
        <div class="ca-alert" role="alert"><?= $h($error) ?></div>
    <?php else: ?>
        <?php
        $kpis = [
            ['views',       'k-views',    'Total Views',     'eye',     $totals['views']],
            ['likes',       'k-likes',    'Total Likes',     'thumb',   $totals['likes']],
            ['comments',    'k-comments', 'Total Comments',  'message', $totals['comments']],
            ['subscribers', 'k-subs',     'Subscribers',     'users',   $totals['subscribers']],
        ];
        ?>
        <section class="ca-profile" id="caCreatorRanking" data-rank="<?= $creatorRanking['rank'] === null ? '' : (int)$creatorRanking['rank'] ?>">
            <div class="ca-profile-user">
                <span class="ca-avatar" aria-hidden="true"><?= $ic('analytics') ?></span>
                <div>
                    <h1>Creator Analytics <span class="ca-sr-only">for <?= $h($username) ?></span></h1>
                    <p>Track your growth, understand your audience and achieve more!</p>
                </div>
            </div>
            <div class="ca-profile-actions">
                <span class="ca-period"><?= $ic('calendar') ?> Last 30 Days <?= $ic('chevron') ?></span>
            </div>
        </section>

        <section class="ca-grid" aria-label="Creator totals">
            <?php foreach ($kpis as $kpi): ?>
                <?php
                [$key, $cls, $label, $icon, $value] = $kpi;
                $trend = $trendOf($chartMetrics[$key]);
                ?>
                <article class="ca-kpi <?= $cls ?>" data-kpi="<?= $key ?>">
                    <span class="ca-kpi-icon"><?= $ic($icon) ?></span>
                    <div class="ca-kpi-label"><?= $h($label) ?></div>
                    <div class="ca-kpi-value" <?= $value === null ? '' : 'title="' . $h(number_format((int)$value)) . '"' ?>><?= $value === null ? '—' : $h($compact($value)) ?></div>
                    <div class="ca-trend <?= $trend === null ? 'is-flat' : ($trend >= 0 ? 'is-up' : 'is-down') ?>">
                        <?php if ($trend === null): ?>
                            <span>No trend yet</span>
                        <?php else: ?>
                            <?= $ic($trend >= 0 ? 'up' : 'down') ?><span><?= $trend >= 0 ? '+' : '−' ?><?= number_format(abs($trend), 1) ?>%</span>
                        <?php endif; ?>
                        <small>vs previous 15 days</small>
                    </div>
                    <div class="ca-spark" aria-hidden="true">
                        <?php foreach ($sparkOf($chartMetrics[$key]) as $pct): ?><i style="height:<?= (int)$pct ?>%"></i><?php endforeach; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="ca-overview-grid">
        <section class="ca-panel">
            <div class="ca-panel-head">
                <h2><?= $ic('trend') ?> Your Channel Performance</h2>
                <div class="ca-legend" id="caActivityLegend" aria-label="Chart metrics"></div>
            </div>
            <div class="ca-chart-wrap" id="caChartWrap">
                <svg class="ca-chart" id="caActivityChart" role="img" aria-label="Daily views, likes, comments, and new subscribers during the last 30 days"></svg>
                <div class="ca-tip" id="caTip" role="presentation"></div>
            </div>
            <p class="ca-live-status" id="caLiveStatus" role="status">LIVE · real data · refreshes every 5 seconds</p>
            <script>
                (function () {
                    var metrics = <?= json_encode($chartPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
                    var labels = <?= json_encode($chartLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
                    var wrap = document.getElementById('caChartWrap');
                    var svg = document.getElementById('caActivityChart');
                    var tip = document.getElementById('caTip');
                    var legend = document.getElementById('caActivityLegend');
                    var status = document.getElementById('caLiveStatus');
                    var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    var hidden = {};
                    var shown = null;      // values currently drawn (tweened)
                    var hoverIndex = -1;   // -1 = resting on the latest day
                    var updating = false;
                    var frame = 0;
                    if (!wrap || !svg || !legend || !status) { return; }

                    var PAD = { l: 50, r: 16, t: 16, b: 30 };

                    function keysOf() { return Object.keys(metrics); }
                    function activeKeys() {
                        return keysOf().filter(function (k) { return metrics[k].available && !hidden[k]; });
                    }
                    function fmt(n) { return Math.round(Number(n) || 0).toLocaleString(); }
                    function one(x) { return String(Math.round(x * 10) / 10); }
                    function short(n) {
                        n = Number(n) || 0;
                        if (n >= 1e6) { return one(n / 1e6) + 'M'; }
                        if (n >= 1e3) { return one(n / 1e3) + 'K'; }
                        return one(n);
                    }
                    function peak(a) { return a.reduce(function (m, v) { return Math.max(m, Number(v) || 0); }, 0); }
                    function niceMax(v) {
                        if (v <= 4) { return 4; }
                        var p = Math.pow(10, Math.floor(Math.log(v) / Math.LN10));
                        var f = v / p;
                        var n = f <= 1 ? 1 : f <= 2 ? 2 : f <= 4 ? 4 : f <= 5 ? 5 : 10;
                        return n * p;
                    }

                    function smoothPath(pts) {
                        if (pts.length < 2) { return ''; }
                        var d = 'M' + pts[0][0].toFixed(1) + ',' + pts[0][1].toFixed(1);
                        for (var i = 0; i < pts.length - 1; i++) {
                            var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
                            var lo = Math.min(p1[1], p2[1]), hi = Math.max(p1[1], p2[1]);
                            var c1y = Math.min(hi, Math.max(lo, p1[1] + (p2[1] - p0[1]) / 6));
                            var c2y = Math.min(hi, Math.max(lo, p2[1] - (p3[1] - p1[1]) / 6));
                            var c1x = p1[0] + (p2[0] - p0[0]) / 6;
                            var c2x = p2[0] - (p3[0] - p1[0]) / 6;
                            d += ' C' + c1x.toFixed(1) + ',' + c1y.toFixed(1) + ' ' + c2x.toFixed(1) + ',' + c2y.toFixed(1) + ' ' + p2[0].toFixed(1) + ',' + p2[1].toFixed(1);
                        }
                        return d;
                    }

                    function geometry() {
                        var W = Math.max(280, wrap.clientWidth || 600);
                        var H = W < 520 ? 220 : 250;
                        return { W: W, H: H, iw: W - PAD.l - PAD.r, ih: H - PAD.t - PAD.b };
                    }

                    function cursorIndex() { return hoverIndex >= 0 ? hoverIndex : labels.length - 1; }

                    function draw() {
                        var g = geometry();
                        var vals = shown || {};
                        var act = activeKeys();
                        var n = labels.length;
                        var peakAll = 1;
                        act.forEach(function (k) { peakAll = Math.max(peakAll, peak(vals[k] || [])); });
                        var axisMax = niceMax(peakAll);   // one shared scale, like the dashboard mockup
                        function xFor(i) { return PAD.l + i * g.iw / Math.max(1, n - 1); }
                        function yFor(v) { return PAD.t + g.ih * (1 - (Number(v) || 0) / axisMax); }

                        var out = '<defs>';
                        act.forEach(function (k) {
                            out += '<linearGradient id="caGrad-' + k + '" x1="0" y1="0" x2="0" y2="1">'
                                + '<stop offset="0" stop-color="' + metrics[k].color + '" stop-opacity="' + (k === 'views' ? '.32' : '.08') + '"/>'
                                + '<stop offset="1" stop-color="' + metrics[k].color + '" stop-opacity="0"/></linearGradient>';
                        });
                        out += '</defs>';

                        // horizontal grid + y labels
                        for (var i = 0; i <= 4; i++) {
                            var y = PAD.t + g.ih * i / 4;
                            out += '<line class="grid" x1="' + PAD.l + '" x2="' + (g.W - PAD.r) + '" y1="' + y + '" y2="' + y + '"/>';
                            out += '<text x="' + (PAD.l - 10) + '" y="' + (y + 4) + '" text-anchor="end">' + short(axisMax * (1 - i / 4)) + '</text>';
                        }

                        // vertical grid + x labels
                        var step = g.W < 520 ? 10 : 5;
                        for (var j = 0; j < n; j += 1) {
                            if (j % step !== 0 && j !== n - 1) { continue; }
                            if (j !== n - 1 && n - 1 - j < step - 1) { continue; }
                            var anchor = j === 0 ? 'start' : (j === n - 1 ? 'end' : 'middle');
                            out += '<line class="grid" x1="' + xFor(j) + '" x2="' + xFor(j) + '" y1="' + PAD.t + '" y2="' + (PAD.t + g.ih + 6) + '"/>';
                            out += '<text x="' + xFor(j) + '" y="' + (g.H - 8) + '" text-anchor="' + anchor + '">' + labels[j] + '</text>';
                        }

                        if (!act.length) {
                            out += '<text x="' + (g.W / 2) + '" y="' + (g.H / 2) + '" text-anchor="middle">No metric selected</text>';
                        }

                        // series
                        act.forEach(function (k) {
                            var pts = (vals[k] || []).map(function (v, idx) { return [xFor(idx), yFor(v)]; });
                            var line = smoothPath(pts);
                            if (!line) { return; }
                            var base = PAD.t + g.ih;
                            out += '<path d="' + line + ' L' + pts[pts.length - 1][0].toFixed(1) + ',' + base + ' L' + pts[0][0].toFixed(1) + ',' + base + ' Z" fill="url(#caGrad-' + k + ')"/>';
                            out += '<path class="series" style="color:' + metrics[k].color + '" d="' + line + '" stroke="' + metrics[k].color + '"/>';
                        });

                        // cursor + points (rests on the latest day until you hover)
                        if (act.length) {
                            var ci = cursorIndex();
                            var cx = xFor(ci);
                            out += '<line class="cursor" stroke="' + metrics[act[0]].color + '" x1="' + cx + '" x2="' + cx + '" y1="' + PAD.t + '" y2="' + (PAD.t + g.ih) + '"/>';
                            act.forEach(function (k) {
                                out += '<circle class="pt" cx="' + cx + '" cy="' + yFor((vals[k] || [])[ci]) + '" r="5" fill="' + metrics[k].color + '"/>';
                            });
                        }

                        svg.setAttribute('viewBox', '0 0 ' + g.W + ' ' + g.H);
                        svg.setAttribute('width', g.W);
                        svg.setAttribute('height', g.H);
                        svg.innerHTML = out;
                    }

                    function showTip() {
                        var act = activeKeys();
                        if (!act.length || !labels.length) { tip.classList.remove('on'); return; }
                        var g = geometry();
                        var ci = cursorIndex();
                        tip.textContent = '';
                        var title = document.createElement('strong');
                        title.textContent = labels[ci];
                        tip.appendChild(title);
                        act.forEach(function (k) {
                            var row = document.createElement('div');
                            var name = document.createElement('span');
                            var dot = document.createElement('i');
                            dot.style.backgroundColor = metrics[k].color;
                            name.appendChild(dot);
                            name.appendChild(document.createTextNode(metrics[k].label));
                            var val = document.createElement('em');
                            val.textContent = fmt((metrics[k].values || [])[ci]);
                            row.appendChild(name);
                            row.appendChild(val);
                            tip.appendChild(row);
                        });
                        var x = PAD.l + ci * g.iw / Math.max(1, labels.length - 1);
                        var w = tip.getBoundingClientRect().width || 160;
                        var left = x > g.W / 2 ? x - w - 14 : x + 14;
                        var maxLeft = Math.max(8, wrap.clientWidth - w - 8);
                        tip.style.left = Math.max(8, Math.min(maxLeft, left)) + 'px';
                        tip.classList.add('on');
                    }

                    function renderLegend() {
                        legend.textContent = '';
                        keysOf().forEach(function (key) {
                            var metric = metrics[key];
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'ca-chip';
                            btn.style.setProperty('--c', metric.color);
                            btn.disabled = !metric.available;
                            if (!metric.available) { btn.title = 'Not tracked yet'; }
                            btn.setAttribute('aria-pressed', metric.available && !hidden[key] ? 'true' : 'false');
                            var marker = document.createElement('i');
                            marker.style.backgroundColor = metric.color;
                            btn.appendChild(marker);
                            btn.appendChild(document.createTextNode(metric.label));
                            btn.addEventListener('click', function () {
                                hidden[key] = !hidden[key];
                                renderLegend();
                                draw();
                                showTip();
                            });
                            legend.appendChild(btn);
                        });
                    }

                    function copyValues(src) {
                        var o = {};
                        keysOf().forEach(function (k) {
                            o[k] = (src[k] && src[k].values || []).map(function (v) { return Number(v) || 0; });
                        });
                        return o;
                    }

                    function update(next, nextLabels) {
                        metrics = next;
                        if (nextLabels && nextLabels.length) { labels = nextLabels; }
                        var target = copyValues(metrics);
                        renderLegend();
                        if (reduceMotion || !shown) {
                            shown = target;
                            draw();
                            showTip();
                            return;
                        }
                        var from = shown;
                        var began = 0;
                        if (frame) { window.cancelAnimationFrame(frame); }
                        function step(ts) {
                            if (!began) { began = ts; }
                            var p = Math.min(1, (ts - began) / 600);
                            var e = 1 - Math.pow(1 - p, 3);
                            var cur = {};
                            keysOf().forEach(function (k) {
                                cur[k] = target[k].map(function (v, i) {
                                    var f = from[k] && from[k][i] !== undefined ? from[k][i] : v;
                                    return f + (v - f) * e;
                                });
                            });
                            shown = cur;
                            draw();
                            if (p < 1) { frame = window.requestAnimationFrame(step); }
                            else { shown = target; frame = 0; showTip(); }
                        }
                        frame = window.requestAnimationFrame(step);
                    }

                    function pointer(ev) {
                        var rect = svg.getBoundingClientRect();
                        var g = geometry();
                        var x = (ev.clientX - rect.left) * (g.W / Math.max(1, rect.width));
                        var idx = Math.round((x - PAD.l) / g.iw * (labels.length - 1));
                        idx = Math.max(0, Math.min(labels.length - 1, idx));
                        if (idx !== hoverIndex) {
                            hoverIndex = idx;
                            draw();
                            showTip();
                        }
                    }
                    svg.addEventListener('pointermove', pointer);
                    svg.addEventListener('pointerdown', pointer);
                    svg.addEventListener('pointerleave', function () {
                        hoverIndex = -1;
                        draw();
                        showTip();
                    });

                    var resizeTimer = 0;
                    window.addEventListener('resize', function () {
                        window.clearTimeout(resizeTimer);
                        resizeTimer = window.setTimeout(function () { draw(); showTip(); }, 80);
                    });

                    update(metrics, labels);

                    function refresh() {
                        if (document.hidden || updating) { return; }
                        updating = true;
                        fetch('creator-analytics.php?live=1', { cache: 'no-store', credentials: 'same-origin' })
                            .then(function (response) {
                                if (!response.ok) { throw new Error('Analytics refresh failed with status ' + response.status); }
                                return response.json();
                            })
                            .then(function (data) {
                                if (!data.ok || !data.metrics) { throw new Error('Live analytics data is unavailable.'); }
                                update(data.metrics, data.labels);
                                status.textContent = 'LIVE · real data · updated just now';
                                updateLiveContent(data);
                            })
                            .catch(function (error) {
                                status.textContent = 'Live update unavailable; showing the last recorded data.';
                                console.error('Creator analytics could not be refreshed.', error);
                            })
                            .then(function () { updating = false; });
                    }
                    function compactLive(n) {
                        n = Number(n) || 0;
                        if (n >= 1000000) { return (Math.round(n / 100000) / 10).toFixed(1).replace(/\.0$/, '') + 'M'; }
                        if (n >= 1000) { return (Math.round(n / 100) / 10).toFixed(1).replace(/\.0$/, '') + 'K'; }
                        return n.toLocaleString();
                    }
                    function updateLiveContent(data) {
                        var list = document.getElementById('caContentList');
                        if (list && Array.isArray(data.top_content)) {
                            if (!data.top_content.length) {
                                list.innerHTML = '<p class="ca-empty">Your published videos and Shorts will appear here.</p>';
                            } else {
                                var maxViews = data.top_content.reduce(function (max, item) { return Math.max(max, Number(item.views) || 0); }, 0) || 1;
                                var fragment = document.createDocumentFragment();
                                data.top_content.forEach(function (item, index) {
                                    var row = document.createElement('article');
                                    row.className = 'ca-content-row';
                                    row.dataset.contentId = String(item.id);
                                    var rank = document.createElement('span');
                                    rank.className = 'ca-content-rank';
                                    rank.textContent = String(index + 1);
                                    var link = document.createElement('a');
                                    link.className = 'ca-content-play';
                                    link.href = (item.is_short ? 'shorts.php?id=' : 'watch.php?id=') + encodeURIComponent(String(item.id));
                                    link.target = '_blank';
                                    link.rel = 'noopener';
                                    link.setAttribute('aria-label', 'Play ' + item.title);
                                    var img = document.createElement('img');
                                    img.className = 'ca-content-thumb';
                                    img.src = item.thumbnail;
                                    img.alt = '';
                                    link.appendChild(img);
                                    var info = document.createElement('div');
                                    info.className = 'ca-content-info';
                                    var title = document.createElement('a');
                                    title.className = 'ca-play-title';
                                    title.href = link.href;
                                    title.target = '_blank';
                                    title.rel = 'noopener';
                                    title.textContent = item.title;
                                    var meta = document.createElement('small');
                                    meta.textContent = compactLive(item.views) + ' views · ' + item.type + (item.age ? ' · ' + item.age : '');
                                    info.appendChild(title);
                                    info.appendChild(meta);
                                    var view = document.createElement('div');
                                    view.className = 'ca-content-view';
                                    var views = document.createElement('span');
                                    views.className = 'ca-views-num';
                                    views.textContent = '◉ ' + compactLive(item.views);
                                    var bar = document.createElement('div');
                                    bar.className = 'ca-bar';
                                    var fill = document.createElement('span');
                                    fill.style.width = Math.max(4, Math.round(Number(item.views) / maxViews * 100)) + '%';
                                    bar.appendChild(fill);
                                    view.appendChild(views);
                                    view.appendChild(bar);
                                    row.appendChild(rank);
                                    row.appendChild(link);
                                    row.appendChild(info);
                                    row.appendChild(view);
                                    fragment.appendChild(row);
                                });
                                list.replaceChildren(fragment);
                            }
                        }
                        var totals = data.totals || {};
                        [['views','views'], ['likes','likes'], ['comments','comments'], ['subscribers','subscribers']].forEach(function (pair) {
                            var kpi = document.querySelector('[data-kpi="' + pair[0] + '"] .ca-kpi-value');
                            if (kpi && totals[pair[1]] !== undefined && totals[pair[1]] !== null) {
                                kpi.textContent = compactLive(totals[pair[1]]);
                                kpi.title = Number(totals[pair[1]]).toLocaleString();
                            }
                        });
                        [['video_count','video_count'], ['likes','likes'], ['comments','comments'], ['new_subscribers','new_subscribers']].forEach(function (pair) {
                            var stat = document.querySelector('[data-stat="' + pair[0] + '"] .ca-stat-value');
                            if (stat && totals[pair[1]] !== undefined && totals[pair[1]] !== null) {
                                stat.textContent = pair[1] === 'video_count'
                                    ? Number(totals[pair[1]]).toLocaleString()
                                    : compactLive(totals[pair[1]]);
                            }
                        });
                        var rankNode = document.querySelector('[data-rank-number]');
                        var rankCaption = document.querySelector('[data-rank-caption]');
                        var rankBadge = document.querySelector('[data-rank-badge-text]');
                        var rank = Number(data.creator_rank) || 0;
                        var creatorCount = Number(data.total_creators) || 0;
                        if (rankNode) { rankNode.textContent = rank > 0 ? '#' + rank.toLocaleString() : '—'; }
                        if (rankCaption) {
                            rankCaption.textContent = creatorCount > 0
                                ? 'Among ' + creatorCount.toLocaleString() + ' Creators'
                                : 'Ranking appears as creators earn views';
                        }
                        if (rankBadge) {
                            rankBadge.textContent = rank > 0 && rank <= 3
                                ? 'Top 3 creators by views!'
                                : rank > 0 ? 'Keep growing your audience' : 'Publish to start your ranking';
                        }
                        var liveViewers = document.querySelector('[data-stat="live_viewers"] .ca-stat-value');
                        if (liveViewers && data.live_viewers !== undefined && data.live_viewers !== null) {
                            liveViewers.textContent = Number(data.live_viewers).toLocaleString();
                        }
                    }
                    window.setInterval(refresh, 5000);
                })();
            </script>
        </section>
            <aside class="ca-panel ca-rank-panel" aria-label="Creator ranking">
                <div class="ca-rank-heading">
                    <h2><?= $ic('trophy') ?> Your Ranking</h2>
                </div>
                <div class="ca-rank-display">
                    <div>
                        <strong class="ca-rank-number" data-rank-number><?= $creatorRanking['rank'] === null ? '—' : '#' . number_format((int)$creatorRanking['rank']) ?></strong>
                        <span class="ca-rank-caption" data-rank-caption>
                            <?= $creatorRanking['total_creators'] > 0
                                ? 'Among ' . number_format((int)$creatorRanking['total_creators']) . ' Creators'
                                : 'Ranking appears as creators earn views' ?>
                        </span>
                    </div>
                    <span class="ca-rank-emblem" aria-hidden="true"><?= $ic('trophy') ?></span>
                </div>
                <div class="ca-rank-badge">
                    <span class="ca-rank-badge-icon" aria-hidden="true"><?= $creatorRanking['top_three'] ? $ic('star') : $ic('trend') ?></span>
                    <span data-rank-badge-text>
                    <?php if ($creatorRanking['top_three']): ?>
                        Top 3 creators by views!
                    <?php elseif ($creatorRanking['rank'] !== null): ?>
                        Keep growing your audience
                    <?php else: ?>
                        Publish to start your ranking
                    <?php endif; ?>
                    </span>
                </div>
            </aside>
        </div>

        <div class="ca-bottom-grid">
            <section class="ca-panel">
                <div class="ca-panel-head"><h2><?= $ic('play') ?> Your Top Videos &amp; Shorts</h2><a class="ca-view-all" href="studio.php">View All <?= $ic('arrow') ?></a></div>
                <?php if (!$videos): ?>
                    <p class="ca-empty">Your published videos and Shorts will appear here.</p>
                <?php else: ?>
                    <div class="ca-content-list" id="caContentList">
                        <?php foreach ($videos as $rank => $video): ?>
                            <?php
                            $isShort = strtolower((string)$video['type']) === 'short';
                            $thumbnail = trim((string)($video['thumbnail'] ?? ''));
                            $source = trim((string)($video['source'] ?? ''));
                            if ($source === '') $source = trim((string)($video['video_id'] ?? ''));
                            $youtubeId = '';
                            foreach ([$source, (string)$video['video_id']] as $sourceValue) {
                                $sourceValue = trim((string)$sourceValue);
                                if (preg_match('/^[A-Za-z0-9_-]{11}$/', $sourceValue)) {
                                    $youtubeId = $sourceValue;
                                    break;
                                }
                                if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $sourceValue, $youtubeMatch)) {
                                    $youtubeId = $youtubeMatch[1];
                                    break;
                                }
                            }
                            if ($thumbnail === '') {
                                $thumbnail = $youtubeId !== ''
                                    ? 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg'
                                    : 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="136" height="88"><rect width="136" height="88" fill="#1b2040"/><path d="M50 25l48 19-48 19z" fill="#7c4dff"/></svg>');
                            } elseif (!preg_match('#^(https?:)?//|^data:#i', $thumbnail) && $thumbnail[0] !== '/') {
                                $thumbnail = './' . ltrim($thumbnail, '/');
                            }
                            $playSource = '';
                            if ($youtubeId === '' && $source !== '') {
                                if (preg_match('#^(https?:)?//#i', $source) || preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)(\?.*)?$/i', $source)) {
                                    $playSource = $source;
                                } else {
                                    $sourcePath = ltrim(str_replace('\\', '/', $source), '/');
                                    foreach (['', 'uploads/', 'videos/', 'uploads/videos/', 'uploads/shorts/', 'shorts/', 'media/'] as $base) {
                                        $relative = $base . $sourcePath;
                                        if (is_file(__DIR__ . '/' . $relative)) {
                                            $playSource = './' . $relative;
                                            break;
                                        }
                                    }
                                }
                            }
                            if ($playSource !== '' && !preg_match('#^(https?:)?//#i', $playSource) && $playSource[0] !== '/') {
                                $playSource = preg_replace('#^\.\/#', './', $playSource);
                            }
                            $age = $ago($video['created_at'] ?? null);
                            $barWidth = max(4, (int)round((int)$video['views'] / $maxContentViews * 100));
                            ?>
                            <article class="ca-content-row" data-content-id="<?= $h((string)$video['video_id']) ?>">
                                <span class="ca-content-rank"><?= (int)$rank + 1 ?></span>
                                <a class="ca-content-play"
                                    href="<?= ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode((string)$video['video_id']) ?>"
                                    target="_blank" rel="noopener"
                                    aria-label="Play <?= $h($video['title'] !== '' ? $video['title'] : 'Untitled') ?>">
                                    <?php if ($playSource !== ''): ?>
                                        <video src="<?= $h($playSource) ?>#t=0.5" preload="metadata" muted playsinline tabindex="-1"></video>
                                    <?php endif; ?>
                                    <img class="ca-content-thumb" src="<?= $h($thumbnail) ?>" alt="">
                                </a>
                                <div class="ca-content-info">
                                    <a class="ca-play-title" href="<?= ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode((string)$video['video_id']) ?>" target="_blank" rel="noopener"><?= $h($video['title'] !== '' ? $video['title'] : 'Untitled') ?></a>
                                    <small><?= $h($compact($video['views'])) ?> views · <?= $isShort ? 'Short' : 'Video' ?><?= $age !== '' ? ' · ' . $h($age) : '' ?></small>
                                </div>
                                <div class="ca-content-view">
                                    <span class="ca-views-num"><?= $ic('eye') ?> <?= $h($compact($video['views'])) ?></span>
                                    <div class="ca-bar"><span style="width:<?= $barWidth ?>%"></span></div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <aside class="ca-panel">
                <div class="ca-panel-head"><h2 style="--ic:#a78bfa"><?= $ic('trend') ?> Quick Stats</h2><a class="ca-view-all" href="studio.php">View Details <?= $ic('arrow') ?></a></div>
                <div class="ca-stats-list">
                    <div class="ca-stat-row" data-stat="video_count" style="--ic:#a78bfa"><span class="ca-stat-icon"><?= $ic('play') ?></span><span class="ca-stat-name">Total Videos</span><strong class="ca-stat-value"><?= number_format($totals['video_count']) ?></strong></div>
                    <div class="ca-stat-row" data-stat="new_subscribers" style="--ic:#a78bfa"><span class="ca-stat-icon"><?= $ic('user') ?></span><span class="ca-stat-name">New Subscribers</span><strong class="ca-stat-value"><?= $totals['new_subscribers'] === null ? '—' : $h($compact($totals['new_subscribers'])) ?></strong></div>
                    <div class="ca-stat-row" data-stat="comments" style="--ic:#60a5fa"><span class="ca-stat-icon"><?= $ic('message') ?></span><span class="ca-stat-name">Comments</span><strong class="ca-stat-value"><?= $h($compact($totals['comments'])) ?></strong></div>
                    <div class="ca-stat-row" data-stat="live_viewers" style="--ic:#22c55e"><span class="ca-stat-icon"><?= $ic('users') ?></span><span class="ca-stat-name">Live Viewers</span><strong class="ca-stat-value"><?= number_format((int)$totals['live_viewers']) ?></strong></div>
                </div>
                <div class="ca-promo">
                    <span class="ca-promo-icon" aria-hidden="true"><?= $ic('rocket') ?></span>
                    <div class="ca-promo-copy"><strong>Keep your momentum going!</strong><span>Your audience is ready for what comes next.</span></div>
                    <a class="ca-promo-link" href="studio.php">Open Studio <?= $ic('arrow') ?></a>
                </div>
            </aside>
        </div>
    <?php endif; ?>
</div>
<?php
vs_page_end();