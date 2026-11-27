<?php
/**
 * insights-stats.php  (place in /adminpanel, next to dashboard-insights-api.php)
 * Real numbers for the VibeStream Insights page: KPI totals, views chart, top content, countries, watch time.
 * Anything the database does not track is returned as null / empty — never made up.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['AdminSession'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Administrator access required.']);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/../geoip-country.php';

$p = (int)($_GET['period'] ?? 30);
if (!in_array($p, [7, 30, 90], true)) {
    $p = 30;
}
$dateNames = ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'timestamp', 'viewed_at', 'liked_at', 'subscribed_at', 'watched_at'];

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $stmt->execute([':t' => $table]);
    return (int)$stmt->fetchColumn() > 0;
};
$trend = static function (int $cur, int $prev): ?float {
    if ($prev <= 0) {
        return null;
    }
    return round(($cur - $prev) / $prev * 100, 1);
};
$curSql = static fn(string $col): string => $col . ' >= DATE_SUB(NOW(), INTERVAL ' . $GLOBALS['p'] . ' DAY)';
$prevSql = static fn(string $col): string => $col . ' >= DATE_SUB(NOW(), INTERVAL ' . (2 * $GLOBALS['p']) . ' DAY) AND ' . $col . ' < DATE_SUB(NOW(), INTERVAL ' . $GLOBALS['p'] . ' DAY)';

/** Count rows (or SUM a column) across several candidate tables: returns [total, current period, previous period]. */
$aggregate = static function (PDO $pdo, array $tables, ?array $sumCandidates = null) use ($tableExists, $dateNames, $curSql, $prevSql): ?array {
    $found = false;
    $total = 0.0;
    $cur = 0.0;
    $prev = 0.0;
    foreach ($tables as $table) {
        if (!$tableExists($pdo, $table)) {
            continue;
        }
        $cols = vs_cols($pdo, $table);
        $sumCol = $sumCandidates ? vs_pick($cols, $sumCandidates) : null;
        if ($sumCandidates && !$sumCol) {
            continue;
        }
        $expr = $sumCol ? 'COALESCE(SUM(' . vs_q($sumCol) . '), 0)' : 'COUNT(*)';
        $found = true;
        $total += (float)$pdo->query('SELECT ' . $expr . ' FROM ' . vs_q($table))->fetchColumn();
        $dateCol = vs_pick($cols, $dateNames);
        if ($dateCol) {
            $q = vs_q($dateCol);
            $cur += (float)$pdo->query('SELECT ' . $expr . ' FROM ' . vs_q($table) . ' WHERE ' . $curSql($q))->fetchColumn();
            $prev += (float)$pdo->query('SELECT ' . $expr . ' FROM ' . vs_q($table) . ' WHERE ' . $prevSql($q))->fetchColumn();
        }
    }
    return $found ? ['total' => $total, 'cur' => $cur, 'prev' => $prev] : null;
};

$out = [
    'ok' => true,
    'period' => $p,
    'totals' => ['views' => null, 'likes' => null, 'comments' => null, 'subscribers' => null, 'watch_hours' => null,
        'views_trend' => null, 'likes_trend' => null, 'comments_trend' => null, 'subs_trend' => null, 'watch_trend' => null],
    'chart' => ['labels' => [], 'values' => [], 'previous' => []],
    'top_content' => [],
    'countries' => [],
    'countries_source' => '',
    'tracking' => ['views' => false, 'countries' => false, 'subscribers' => false, 'watch_time' => false],
];

try {
    $hasEvents = $tableExists($pdo, 'video_view_events');
    $out['tracking']['views'] = $hasEvents;
    $out['tracking']['subscribers'] = $tableExists($pdo, 'user_subscriptions');
    $out['tracking']['watch_time'] = $tableExists($pdo, 'creator_analytics_events');

    /* ---------- Views: KPI + chart ---------- */
    if ($hasEvents) {
        $cur = (int)$pdo->query('SELECT COUNT(*) FROM `video_view_events` WHERE ' . $curSql('`viewed_at`'))->fetchColumn();
        $prev = (int)$pdo->query('SELECT COUNT(*) FROM `video_view_events` WHERE ' . $prevSql('`viewed_at`'))->fetchColumn();
        $out['totals']['views'] = (int)$pdo->query('SELECT COUNT(*) FROM `video_view_events`')->fetchColumn();
        $out['totals']['views_trend'] = $trend($cur, $prev);

        // Prefer lifetime counters stored on videos/shorts if they are larger (events table may be newer than the site).
        $lifetime = 0;
        foreach (['videos', 'shorts'] as $t) {
            if ($tableExists($pdo, $t)) {
                $vc = vs_pick(vs_cols($pdo, $t), ['views', 'view_count']);
                if ($vc) {
                    $lifetime += (int)$pdo->query('SELECT COALESCE(SUM(' . vs_q($vc) . '), 0) FROM ' . vs_q($t))->fetchColumn();
                }
            }
        }
        if ($lifetime > (int)$out['totals']['views']) {
            $out['totals']['views'] = $lifetime;
        }

        $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
        $rows = $pdo->query(
            'SELECT DATE(`viewed_at`) AS `d`, COUNT(*) AS `c` FROM `video_view_events`
             WHERE `viewed_at` >= DATE_SUB(CURDATE(), INTERVAL ' . (2 * $p - 1) . ' DAY) GROUP BY DATE(`viewed_at`)'
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $base = new DateTimeImmutable($today);
        for ($i = $p - 1; $i >= 0; $i--) {
            $day = $base->modify('-' . $i . ' day');
            $prevDay = $base->modify('-' . ($i + $p) . ' day');
            $out['chart']['labels'][] = $day->format('M j');
            $out['chart']['values'][] = (int)($rows[$day->format('Y-m-d')] ?? 0);
            $out['chart']['previous'][] = (int)($rows[$prevDay->format('Y-m-d')] ?? 0);
        }
    }

    /* ---------- Likes / Comments / Subscribers / Watch time ---------- */
    $metricTrendEnabled = ['likes' => true];
    $metrics = [
        'likes' => $tableExists($pdo, 'video_likes')
            ? [['video_likes'], null]
            : [['videos'], ['likes', 'like_count']],
        'comments' => [['comments'], null],
        'subscribers' => [['user_subscriptions'], null],
        'watch' => [['creator_analytics_events'], ['watched_seconds']],
    ];
    if (!$tableExists($pdo, 'video_likes')) {
        $metricTrendEnabled['likes'] = false;
    }
    foreach ($metrics as $key => [$tables, $sumCols]) {
        try {
            $a = $aggregate($pdo, $tables, $sumCols);
        } catch (Throwable $e) {
            error_log('insights-stats ' . $key . ': ' . $e->getMessage());
            $a = null;
        }
        if (!$a) {
            continue;
        }
        if ($key === 'watch') {
            $out['totals']['watch_hours'] = round($a['total'] / 3600, 1);
            $out['totals']['watch_trend'] = $trend((int)$a['cur'], (int)$a['prev']);
        } elseif ($key === 'subscribers') {
            $out['totals']['subscribers'] = (int)$a['total'];
            $out['totals']['subs_trend'] = $trend((int)$a['cur'], (int)$a['prev']);
        } else {
            $out['totals'][$key] = (int)$a['total'];
            $out['totals'][$key . '_trend'] = ($metricTrendEnabled[$key] ?? true)
                ? $trend((int)$a['cur'], (int)$a['prev'])
                : null;
        }
    }

    /* ---------- Top content for the chosen period ---------- */
    if ($hasEvents && $tableExists($pdo, 'videos')) {
        $videoCols = vs_cols($pdo, 'videos');
        $videoIdCol = vs_pick($videoCols, ['id', 'video_id']);
        $scheduledCol = vs_pick($videoCols, ['scheduled_at']);
        $publishedFilter = $scheduledCol
            ? ' AND (v.' . vs_q($scheduledCol) . ' IS NULL OR v.' . vs_q($scheduledCol) . ' <= NOW())'
            : '';
        if ($videoIdCol) {
        $joinCondition = 'v.' . vs_q($videoIdCol) . ' = e.`content_id`';
        $columnInfo = $pdo->prepare(
            'SELECT `TABLE_NAME`, `CHARACTER_SET_NAME`, `COLLATION_NAME`
             FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND ((`TABLE_NAME` = \'videos\' AND `COLUMN_NAME` = :video_id)
                 OR (`TABLE_NAME` = \'video_view_events\' AND `COLUMN_NAME` = \'content_id\'))'
        );
        $columnInfo->execute([':video_id' => $videoIdCol]);
        $idColumns = [];
        foreach ($columnInfo->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $idColumns[(string)$column['TABLE_NAME']] = $column;
        }
        $videoIdMeta = $idColumns['videos'] ?? null;
        $eventIdMeta = $idColumns['video_view_events'] ?? null;
        if ($videoIdMeta && $eventIdMeta
            && !empty($videoIdMeta['CHARACTER_SET_NAME'])
            && !empty($videoIdMeta['COLLATION_NAME'])
            && !empty($eventIdMeta['CHARACTER_SET_NAME'])
            && !empty($eventIdMeta['COLLATION_NAME'])
            && ($videoIdMeta['CHARACTER_SET_NAME'] !== $eventIdMeta['CHARACTER_SET_NAME']
                || $videoIdMeta['COLLATION_NAME'] !== $eventIdMeta['COLLATION_NAME'])
            && preg_match('/^[a-z0-9_]+$/i', (string)$videoIdMeta['CHARACTER_SET_NAME'])
            && preg_match('/^[a-z0-9_]+$/i', (string)$videoIdMeta['COLLATION_NAME'])) {
            $eventId = 'CONVERT(e.`content_id` USING ' . $videoIdMeta['CHARACTER_SET_NAME'] . ') COLLATE ' . $videoIdMeta['COLLATION_NAME'];
            $joinCondition = 'v.' . vs_q($videoIdCol) . ' = ' . $eventId;
        }
        $top = $pdo->query(
            'SELECT e.`content_type`, e.`content_id`, e.`creator`, COUNT(*) AS `views`
             FROM `video_view_events` e
             INNER JOIN `videos` v ON ' . $joinCondition . '
             WHERE ' . $curSql('e.`viewed_at`') . $publishedFilter . '
             GROUP BY e.`content_type`, e.`content_id`, e.`creator`
             ORDER BY `views` DESC LIMIT 5'
        )->fetchAll(PDO::FETCH_ASSOC);
        $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $resolveUrl = static function (string $raw) use ($projectRoot): string {
            $raw = preg_replace('~^\./+~', '', trim(str_replace('\\', '/', $raw)));
            if ($raw === '') {
                return '';
            }
            if (preg_match('~^(?:https?:)?//|^data:~i', $raw)) {
                return $raw;
            }
            $rel = ltrim($raw, '/');
            foreach ([$rel, 'uploads/' . $rel, 'media/' . $rel, 'videos/' . $rel, 'uploads/videos/' . $rel, 'media/videos/' . $rel] as $candidate) {
                if (is_file($projectRoot . '/' . $candidate)) {
                    return '../' . $candidate;
                }
            }
            return '';
        };
        foreach ($top as $row) {
            $title = '';
            $thumb = '';
            $cols = $videoCols;
            $titleCol = vs_pick($cols, ['title', 'video_title', 'name']);
            $thumbCol = vs_pick($cols, ['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'image', 'image_url', 'image_path', 'img', 'poster', 'cover', 'cover_image', 'preview']);
            $srcCol = vs_pick($cols, ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed']);
            $typeCol = vs_pick($cols, ['type', 'content_type']);
            $select = [$titleCol ? vs_q($titleCol) . ' AS `title`' : "'' AS `title`",
                $thumbCol ? vs_q($thumbCol) . ' AS `thumb`' : "'' AS `thumb`",
                $srcCol ? vs_q($srcCol) . ' AS `src`' : "'' AS `src`",
                $typeCol ? vs_q($typeCol) . ' AS `item_type`' : "'' AS `item_type`"];
            $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM `videos` WHERE ' . vs_q($videoIdCol) . ' = :id LIMIT 1');
            $stmt->execute([':id' => (string)$row['content_id']]);
            $found = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$found) {
                continue;
            }
            $title = trim((string)$found['title']);
            $thumb = $resolveUrl((string)$found['thumb']);
            $src = trim((string)$found['src']);
            if ($thumb === '' && preg_match('~^[A-Za-z0-9_-]{11}$~', $src)) {
                $youtubeId = $src;
            } elseif ($thumb === '' && preg_match('~(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})~i', $src, $m)) {
                $youtubeId = $m[1];
            } else {
                $youtubeId = '';
            }
            if ($thumb === '' && $youtubeId !== '') {
                $thumb = 'https://i.ytimg.com/vi/' . $youtubeId . '/hqdefault.jpg';
            }
            $isShort = stripos((string)($found['item_type'] ?: $row['content_type']), 'short') !== false;
            $out['top_content'][] = [
                'type' => $isShort ? 'Short' : 'Video',
                'id' => (string)$row['content_id'],
                'title' => $title,
                'creator' => (string)$row['creator'],
                'views' => (int)$row['views'],
                'thumb' => $thumb,
            ];
        }
        }
    }

    /* ---------- Countries: only from real stored data ---------- */
    $names = ['PK' => 'Pakistan', 'IN' => 'India', 'US' => 'USA', 'GB' => 'UK', 'CA' => 'Canada', 'AU' => 'Australia', 'DE' => 'Germany',
        'FR' => 'France', 'SA' => 'Saudi Arabia', 'AE' => 'UAE', 'BD' => 'Bangladesh', 'TR' => 'Turkey'];
    $countrySql = null;
    if ($hasEvents && vs_geoip_country_tracking_available()) {
        $cc = vs_pick(vs_cols($pdo, 'video_view_events'), ['country_code']);
        if ($cc) {
            $countrySql = ['SELECT ' . vs_q($cc) . ' AS `c`, COUNT(*) AS `n` FROM `video_view_events` WHERE ' . $curSql('`viewed_at`')
                . ' AND ' . vs_q($cc) . " REGEXP '^[A-Za-z]{2}$' GROUP BY " . vs_q($cc) . ' ORDER BY `n` DESC LIMIT 6', 'views'];
        }
    }
    if ($countrySql) {
        $out['tracking']['countries'] = true;
        $countryTotal = (int)$pdo->query(
            'SELECT COUNT(*) FROM `video_view_events` WHERE ' . $curSql('`viewed_at`')
            . ' AND ' . vs_q($cc) . " REGEXP '^[A-Za-z]{2}$'"
        )->fetchColumn();
        $rows = $pdo->query($countrySql[0])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $raw = trim((string)$r['c']);
            $code = strtoupper($raw);
            $isCode = strlen($code) === 2;
            $out['countries'][] = [
                'code' => $isCode ? $code : '??',
                'name' => $isCode ? ($names[$code] ?? $code) : $raw,
                'pct' => $countryTotal > 0 ? round((int)$r['n'] / $countryTotal * 100, 1) : 0,
            ];
        }
        $out['countries_source'] = $countrySql[1];
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $error) {
    error_log('Insights stats failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
