<?php
/*
 * VibeStream - Creator Studio (free for every uploader).
 * Put this file in the main VibeStream folder, next to creator-analytics.php.
 *
 * Shows the signed-in creator's OWN numbers: views, likes, comments, subscribers, a 30-day views chart
 * and a table of all their videos and Shorts. The deeper daily charts (likes, comments, new subscribers)
 * with live refresh stay in Creator Analytics, which is a Premium benefit.
 */
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/premium-perks.php';
include __DIR__ . '/connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    header('Location: login.php?next=' . rawurlencode('studio.php'));
    exit;
}

$h = static function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$q = static function (string $name): string { return '`' . str_replace('`', '``', $name) . '`'; };

$limits = vs_premium_user_limits(isset($pdo) && $pdo instanceof PDO ? $pdo : null, $username);
$isPremium = in_array((string)$limits['tier'], ['premium', 'plus'], true);

$sort = (string)($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'views', 'likes', 'comments'], true)) {
    $sort = 'newest';
}

$error = '';
$videos = [];
$totals = ['views' => 0, 'likes' => 0, 'comments' => 0, 'videos' => 0, 'shorts' => 0, 'subscribers' => null, 'views_30d' => null];
$creatorRanking = ['rank' => null, 'total_creators' => 0];
$days = [];
$viewsByDay = [];
for ($offset = 29; $offset >= 0; $offset--) {
    $day = date('Y-m-d', strtotime('-' . $offset . ' days'));
    $days[] = $day;
    $viewsByDay[$day] = 0;
}
$viewsAvailable = false;

try {
    $tableExists = static function (string $table) use ($conn): bool {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $ok = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $ok;
    };
    $columnsOf = static function (string $table) use ($conn, $q): array {
        $res = $conn->query('SHOW COLUMNS FROM ' . $q($table));
        $found = [];
        while ($c = $res->fetch_assoc()) {
            $found[strtolower((string)$c['Field'])] = (string)$c['Field'];
        }
        $res->free();
        return $found;
    };

    $cols = $columnsOf('videos');
    $owner = $cols['username'] ?? ($cols['channel_name'] ?? '');
    if ($owner === '' || !isset($cols['id'])) {
        throw new RuntimeException('The videos table has no creator or id column.');
    }
    $select = [
        $q($cols['id']) . ' AS video_id',
        isset($cols['title']) ? $q($cols['title']) . ' AS title' : "'' AS title",
        isset($cols['type']) ? $q($cols['type']) . ' AS type' : "'video' AS type",
        isset($cols['views']) ? 'COALESCE(' . $q($cols['views']) . ', 0) AS views' : '0 AS views',
        isset($cols['likes']) ? 'COALESCE(' . $q($cols['likes']) . ', 0) AS likes' : '0 AS likes',
        isset($cols['created_at']) ? $q($cols['created_at']) . ' AS created_at' : 'NULL AS created_at',
    ];
    $thumbnailColumns = [];
    foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'image', 'image_url', 'image_path', 'poster', 'cover', 'cover_image', 'preview'] as $candidate) {
        if (isset($cols[$candidate])) {
            $thumbnailColumns[] = 'NULLIF(TRIM(CAST(' . $q($cols[$candidate]) . ' AS CHAR)), \'\')';
        }
    }
    $select[] = $thumbnailColumns ? 'COALESCE(' . implode(', ', $thumbnailColumns) . ") AS thumbnail" : "'' AS thumbnail";
    $sourceColumns = [];
    foreach (['video_url', 'video_path', 'video_file', 'url', 'file_path', 'filepath', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url'] as $candidate) {
        if (isset($cols[$candidate])) {
            $sourceColumns[] = 'NULLIF(TRIM(CAST(' . $q($cols[$candidate]) . ' AS CHAR)), \'\')';
        }
    }
    $select[] = $sourceColumns ? 'COALESCE(' . implode(', ', $sourceColumns) . ") AS source" : "'' AS source";
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM `videos` WHERE LOWER(' . $q($owner) . ') = LOWER(?)';
    if (isset($cols['created_at'])) {
        $sql .= ' ORDER BY ' . $q($cols['created_at']) . ' DESC';
    }
    $sql .= ' LIMIT 300';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $videos = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Comments on this creator's videos, per video
    $commentCounts = [];
    if ($tableExists('comments')) {
        $cc = $columnsOf('comments');
        if (isset($cc['video_id'])) {
            $cstmt = $conn->prepare(
                'SELECT CAST(c.' . $q($cc['video_id']) . ' AS CHAR) AS vid, COUNT(*) AS total
                 FROM `comments` c INNER JOIN `videos` v ON CAST(v.' . $q($cols['id']) . ' AS CHAR) = CAST(c.' . $q($cc['video_id']) . ' AS CHAR)
                 WHERE LOWER(v.' . $q($owner) . ') = LOWER(?) GROUP BY vid'
            );
            $cstmt->bind_param('s', $username);
            $cstmt->execute();
            foreach ($cstmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                $commentCounts[(string)$r['vid']] = (int)$r['total'];
            }
            $cstmt->close();
        }
    }
    foreach ($videos as &$v) {
        $v['views'] = (int)$v['views'];
        $v['likes'] = (int)$v['likes'];
        $v['comments'] = $commentCounts[(string)$v['video_id']] ?? 0;
        $v['is_short'] = strtolower((string)$v['type']) === 'short';
        $v['thumbnail'] = trim((string)($v['thumbnail'] ?? ''));
        $source = trim((string)($v['source'] ?? ''));
        if ($v['thumbnail'] !== '' && !preg_match('#^(https?:)?//#i', $v['thumbnail']) && $v['thumbnail'][0] !== '/') {
            $v['thumbnail'] = './' . ltrim($v['thumbnail'], '/');
        }
        if ($v['thumbnail'] === '') {
            foreach ([$source, (string)$v['video_id']] as $sourceValue) {
                if (preg_match('/^[A-Za-z0-9_-]{11}$/', $sourceValue, $match)) {
                    $v['thumbnail'] = 'https://i.ytimg.com/vi/' . rawurlencode($match[0]) . '/hqdefault.jpg';
                    break;
                }
                if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $sourceValue, $match)) {
                    $v['thumbnail'] = 'https://i.ytimg.com/vi/' . rawurlencode($match[1]) . '/hqdefault.jpg';
                    break;
                }
            }
        }
        $totals['views'] += $v['views'];
        $totals['likes'] += $v['likes'];
        $totals['comments'] += $v['comments'];
        $v['is_short'] ? $totals['shorts']++ : $totals['videos']++;
    }
    unset($v);

    // Rank creators by total views, using the same view field as the cards above.
    if (isset($cols['views'])) {
        try {
            $rankingSql = 'SELECT LOWER(TRIM(CAST(' . $q($owner) . ' AS CHAR))) AS creator_key, '
                . 'COALESCE(SUM(COALESCE(' . $q($cols['views']) . ', 0)), 0) AS total_views '
                . 'FROM `videos` WHERE NULLIF(TRIM(CAST(' . $q($owner) . ' AS CHAR)), \'\') IS NOT NULL '
                . 'GROUP BY LOWER(TRIM(CAST(' . $q($owner) . ' AS CHAR))) ORDER BY total_views DESC, creator_key ASC';
            $rankingResult = $conn->query($rankingSql);
            $creatorRows = $rankingResult->fetch_all(MYSQLI_ASSOC);
            $previousViews = null;
            $competitionRank = 0;
            foreach ($creatorRows as $index => $row) {
                $creatorViews = (int)$row['total_views'];
                if ($previousViews === null || $creatorViews !== $previousViews) {
                    $competitionRank = $index + 1;
                }
                $previousViews = $creatorViews;
                if ((string)$row['creator_key'] === mb_strtolower($username, 'UTF-8')) {
                    $creatorRanking['rank'] = $competitionRank;
                    break;
                }
            }
            $creatorRanking['total_creators'] = count($creatorRows);
            $rankingResult->free();
        } catch (Throwable $rankingError) {
            error_log('Creator Studio ranking could not be loaded: ' . $rankingError->getMessage());
        }
    }

    // Subscribers
    if ($tableExists('user_subscriptions')) {
        $sc = $columnsOf('user_subscriptions');
        if (isset($sc['channel_username'])) {
            $sstmt = $conn->prepare('SELECT COUNT(*) FROM `user_subscriptions` WHERE LOWER(' . $q($sc['channel_username']) . ') = LOWER(?)');
            $sstmt->bind_param('s', $username);
            $sstmt->execute();
            $totals['subscribers'] = (int)$sstmt->get_result()->fetch_row()[0];
            $sstmt->close();
        }
    }

    // Views per day, last 30 days (only if the view log table exists)
    if ($tableExists('video_view_events')) {
        $vstmt = $conn->prepare(
            "SELECT DATE(`viewed_at`) AS d, COUNT(*) AS total FROM `video_view_events`
             WHERE LOWER(`creator`) = LOWER(?) AND `content_type` IN ('video', 'short')
               AND `viewed_at` >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(`viewed_at`)"
        );
        $vstmt->bind_param('s', $username);
        $vstmt->execute();
        foreach ($vstmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (array_key_exists((string)$r['d'], $viewsByDay)) {
                $viewsByDay[(string)$r['d']] = (int)$r['total'];
            }
        }
        $vstmt->close();
        $viewsAvailable = true;
        $totals['views_30d'] = array_sum($viewsByDay);
    }

    // Sort the table
    if ($sort !== 'newest') {
        usort($videos, static function ($a, $b) use ($sort) {
            return $b[$sort] <=> $a[$sort];
        });
    }
} catch (Throwable $e) {
    error_log('Creator Studio could not be loaded: ' . $e->getMessage());
    $error = 'Your studio could not be loaded right now. Please try again later.';
}

$maxDay = max(1, $viewsByDay ? max($viewsByDay) : 1);
$maxViews = 1;
foreach ($videos as $v) {
    $maxViews = max($maxViews, $v['views']);
}
$topVideos = $videos;
usort($topVideos, static function ($a, $b) {
    return $b['views'] <=> $a['views'];
});
$topVideos = array_slice($topVideos, 0, 3);
$chartPoints = [];
$chartWidth = 720;
$chartHeight = 250;
$chartLeft = 50;
$chartRight = 16;
$chartTop = 16;
$chartBottom = 30;
$chartInnerWidth = $chartWidth - $chartLeft - $chartRight;
$chartInnerHeight = $chartHeight - $chartTop - $chartBottom;
$chartAxisMax = static function (float $value): float {
    if ($value <= 4) {
        return 4;
    }
    $power = pow(10, floor(log($value) / log(10)));
    $fraction = $value / $power;
    $niceFraction = $fraction <= 1 ? 1 : ($fraction <= 2 ? 2 : ($fraction <= 4 ? 4 : ($fraction <= 5 ? 5 : 10)));
    return $niceFraction * $power;
};
$chartScale = $chartAxisMax((float)$maxDay);
$chartY = static function (float $value) use ($chartTop, $chartInnerHeight, $chartScale): float {
    return $chartTop + $chartInnerHeight * (1 - (max(0, $value) / $chartScale));
};
foreach (array_values($viewsByDay) as $index => $value) {
    $x = $chartLeft + ($index / max(1, count($viewsByDay) - 1)) * $chartInnerWidth;
    $y = $chartY((float)$value);
    $chartPoints[] = [round($x, 1), round($y, 1)];
}
$chartLine = 'M' . $chartPoints[0][0] . ',' . $chartPoints[0][1];
for ($index = 0, $lastPoint = count($chartPoints) - 1; $index < $lastPoint; $index++) {
    $p0 = $chartPoints[max(0, $index - 1)];
    $p1 = $chartPoints[$index];
    $p2 = $chartPoints[$index + 1];
    $p3 = $chartPoints[min($lastPoint, $index + 2)];
    $c1x = $p1[0] + ($p2[0] - $p0[0]) / 6;
    $c2x = $p2[0] - ($p3[0] - $p1[0]) / 6;
    $lowY = min($p1[1], $p2[1]);
    $highY = max($p1[1], $p2[1]);
    $c1y = min($highY, max($lowY, $p1[1] + ($p2[1] - $p0[1]) / 6));
    $c2y = min($highY, max($lowY, $p2[1] - ($p3[1] - $p1[1]) / 6));
    $chartLine .= ' C' . round($c1x, 1) . ',' . round($c1y, 1)
        . ' ' . round($c2x, 1) . ',' . round($c2y, 1)
        . ' ' . $p2[0] . ',' . $p2[1];
}
$chartArea = $chartLine . ' L' . $chartPoints[count($chartPoints) - 1][0] . ',' . ($chartHeight - $chartBottom)
    . ' L' . $chartPoints[0][0] . ',' . ($chartHeight - $chartBottom) . ' Z';
$chartInteraction = [];
foreach ($days as $index => $day) {
    $chartInteraction[] = [
        'x' => $chartPoints[$index][0],
        'y' => $chartPoints[$index][1],
        'label' => date('M j, Y', strtotime($day)),
        'views' => (int)$viewsByDay[$day],
    ];
}
$chartInteractionJson = json_encode($chartInteraction, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$compact = static function ($value): string {
    $number = (float)$value;
    if ($number >= 1000000) { return rtrim(rtrim(number_format($number / 1000000, 1), '0'), '.') . 'M'; }
    if ($number >= 1000) { return rtrim(rtrim(number_format($number / 1000, 1), '0'), '.') . 'K'; }
    return number_format($number);
};
$lastSeven = array_slice(array_values($viewsByDay), -7);
$maxSeven = max(1, $lastSeven ? max($lastSeven) : 1);
$previousHalf = array_sum(array_slice(array_values($viewsByDay), 0, 15));
$currentHalf = array_sum(array_slice(array_values($viewsByDay), 15));
$viewTrend = $viewsAvailable && $previousHalf >= 10
    ? (($currentHalf - $previousHalf) / $previousHalf) * 100
    : null;
$newActivity = $viewsAvailable && $previousHalf < 10 && $currentHalf > $previousHalf;
$icons = [
    'analytics' => '<path d="M3 20h18M6 16v-4M12 16V8M18 16V4"/><path d="M4 9l5-4 4 2 6-5"/>',
    'eye' => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
    'like' => '<path d="M7 10v11H3V10h4zM7 10l4-8c1.7 0 3 1.3 3 3v3h5.5a2 2 0 0 1 2 2.3l-1.4 8a2 2 0 0 1-2 1.7H7"/>',
    'comment' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14.2c2.6.2 4.5 2 4.5 4.8"/>',
    'play' => '<path d="M6.5 4.2v15.6c0 .8.9 1.3 1.6.8l12-7.8c.6-.4.6-1.2 0-1.6l-12-7.8c-.7-.5-1.6 0-1.6.8z"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>',
    'trophy' => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M7 6H4v2a4 4 0 0 0 4 4M17 6h3v2a4 4 0 0 1-4 4"/>',
    'rocket' => '<path d="M5 15c-1.5 0-2.5 1-3 4 3-.5 4-1.5 4-3M8 13l-3 3M11 16c5-2 8-7 9-14-7 1-12 4-14 9l5 5z"/><circle cx="15.5" cy="8.5" r="1.5"/>',
    'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    'trend-up' => '<path d="M4 16l6-6 4 4 6-8"/><path d="M14 6h6v6"/>',
    'trend-down' => '<path d="M4 8l6 6 4-4 6 8"/><path d="M14 18h6v-6"/>',
    'star' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9L12 3z"/>',
];
$ic = static function (string $name) use ($icons): string {
    if (!isset($icons[$name])) { return ''; }
    return '<svg class="st-icon" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $icons[$name] . '</svg>';
};

$extraCss = <<<'CSS'
.ca-page{max-width:1200px;margin:0 auto;padding:36px clamp(16px,4vw,32px) 90px;color:inherit}
.ca-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:8px}
.ca-head h1{font-size:clamp(28px,5vw,40px);margin:0;letter-spacing:-.02em}
.ca-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(245,185,66,.14);color:#f5b942;border:1px solid rgba(245,185,66,.35)}
.ca-lead{opacity:.72;margin:0 0 26px;max-width:760px;line-height:1.55}
.ca-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin:0 0 18px}
.ca-card,.ca-panel{background:var(--surface,#181818);border:1px solid var(--border,#303030);border-radius:18px}
.ca-card{position:relative;padding:18px 18px 16px;overflow:hidden;transition:transform .15s ease,border-color .15s ease}
.ca-card:hover{transform:translateY(-2px);border-color:var(--accent,#555)}
.ca-card::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--accent,#f5b942)}
.ca-label{font-size:13px;opacity:.72;display:flex;align-items:center;gap:8px}
.ca-label i{width:8px;height:8px;border-radius:50%;background:var(--accent,#f5b942);display:inline-block}
.ca-value{font-size:clamp(26px,3vw,32px);font-weight:800;margin-top:10px;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
.ca-panel{margin-top:18px;padding:22px}
.ca-panel-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}
.ca-panel h2{font-size:19px;margin:0}
.ca-live-status{font-size:12px;opacity:.75;margin:0;display:inline-flex;align-items:center;gap:7px}
.ca-live-status::before{content:"";width:8px;height:8px;border-radius:50%;background:#34d399;box-shadow:0 0 0 0 rgba(52,211,153,.6);animation:caPulse 2s infinite}
@keyframes caPulse{70%{box-shadow:0 0 0 8px rgba(52,211,153,0)}100%{box-shadow:0 0 0 0 rgba(52,211,153,0)}}
.ca-hint{font-size:12px;opacity:.55;margin:0 0 12px}
.ca-legend{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 14px}
.ca-chip{display:inline-flex;align-items:center;gap:8px;padding:7px 12px;border-radius:999px;border:1px solid var(--border,#303030);background:transparent;color:inherit;font:inherit;font-size:13px;cursor:pointer;transition:background .15s,opacity .15s,border-color .15s}
.ca-chip:hover{background:rgba(128,128,128,.12)}
.ca-chip i{width:10px;height:10px;border-radius:50%;display:inline-block}
.ca-chip b{font-variant-numeric:tabular-nums}
.ca-chip[aria-pressed="false"]{opacity:.45}
.ca-chip[aria-pressed="false"] i{background:transparent!important;box-shadow:inset 0 0 0 2px var(--c)}
.ca-chip[disabled]{cursor:default;opacity:.4}
.ca-chart-wrap{position:relative;width:100%;touch-action:pan-y}
.ca-chart{display:block;width:100%;height:auto;overflow:visible;user-select:none}
.ca-chart text{fill:var(--muted,#9a9a9a);font-size:11px;font-family:inherit}
.ca-chart .grid{stroke:rgba(128,128,128,.2);stroke-width:1;shape-rendering:crispEdges}
.ca-chart .series{fill:none;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round}
.ca-chart .cursor{stroke:rgba(128,128,128,.55);stroke-width:1;stroke-dasharray:4 4}
.ca-tip{position:absolute;top:8px;pointer-events:none;min-width:150px;max-width:calc(100% - 16px);box-sizing:border-box;padding:10px 12px;border-radius:12px;background:var(--surface,#181818);border:1px solid var(--border,#3a3a3a);box-shadow:0 10px 30px rgba(0,0,0,.35);font-size:12px;opacity:0;transition:opacity .12s;z-index:2}
.ca-tip.on{opacity:1}
.ca-tip strong{display:block;margin-bottom:6px;font-size:12px}
.ca-tip div{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:2px 0}
.ca-tip span{display:inline-flex;align-items:center;gap:6px;opacity:.8}
.ca-tip i{width:8px;height:8px;border-radius:50%;display:inline-block}
.ca-tip em{font-style:normal;font-weight:700;font-variant-numeric:tabular-nums;text-align:right}
.ca-table{width:100%;border-collapse:collapse}
.ca-table th,.ca-table td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--border,#303030);vertical-align:middle}
.ca-table tbody tr:last-child td{border-bottom:0}
.ca-table tbody tr:hover{background:rgba(128,128,128,.06)}
.ca-table th{font-size:12px;opacity:.65;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.ca-table td.num,.ca-table th.num{text-align:right;font-variant-numeric:tabular-nums}
.ca-rank{width:34px;opacity:.55;font-weight:700}
.ca-title{display:block;color:inherit;font-weight:600;text-decoration:none;max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ca-title:hover{text-decoration:underline}
.ca-bar{height:4px;border-radius:99px;background:rgba(128,128,128,.18);margin-top:8px;max-width:420px;overflow:hidden}
.ca-bar span{display:block;height:100%;border-radius:99px;background:#f5b942}
.ca-type{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;background:rgba(128,128,128,.15)}
.ca-type.short{background:rgba(96,165,250,.16);color:#60a5fa}
.ca-empty,.ca-alert{padding:16px;border-radius:12px;background:rgba(128,128,128,.12);margin:12px 0}
.ca-alert{background:rgba(245,185,66,.14);border:1px solid rgba(245,185,66,.35)}
.ca-upgrade{display:inline-flex;margin-top:14px;padding:11px 18px;background:#f5b942;color:#2a1700;border-radius:999px;font-weight:700;text-decoration:none}
@media (max-width:640px){
  .ca-panel{padding:16px}
  .ca-title{max-width:180px}
  .ca-col-type{display:none}
}
@media (prefers-reduced-motion:reduce){.ca-live-status::before{animation:none}.ca-card{transition:none}}
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

/* ---- creator analytics ---- */
html:not([data-theme="light"]) body .ca-head h1 { text-shadow: 0 0 18px rgba(138,92,255,.7); }
html:not([data-theme="light"]) body .ca-badge { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .ca-card, html:not([data-theme="light"]) body .ca-panel { background: rgba(10,13,31,.88); border: 1px solid rgba(138,92,255,.4); box-shadow: 0 0 26px -10px rgba(138,92,255,.55); }
html:not([data-theme="light"]) body .ca-card:hover { border-color: #00e5ff; box-shadow: 0 0 26px -4px rgba(0,229,255,.55), 0 0 50px -16px rgba(138,92,255,.55); }
html:not([data-theme="light"]) body .ca-card::before { box-shadow: 0 0 14px 1px currentColor; filter: saturate(1.3); }
html:not([data-theme="light"]) body .ca-label i { box-shadow: 0 0 8px currentColor; }
html:not([data-theme="light"]) body .ca-value { text-shadow: 0 0 14px rgba(0,229,255,.45); }
html:not([data-theme="light"]) body .ca-panel h2 { text-shadow: 0 0 12px rgba(138,92,255,.5); }
html:not([data-theme="light"]) body .ca-live-status::before { box-shadow: 0 0 10px #34d399, 0 0 18px rgba(52,211,153,.7); }
html:not([data-theme="light"]) body .ca-chip { background: rgba(23,28,66,.7); border: 1px solid rgba(138,92,255,.45); transition: box-shadow .18s ease, border-color .18s ease; }
html:not([data-theme="light"]) body .ca-chip:hover { background: rgba(0,229,255,.12); border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .ca-chip i { box-shadow: 0 0 8px var(--c, currentColor); }
html:not([data-theme="light"]) body .ca-chart .grid { stroke: rgba(138,92,255,.28); }
html:not([data-theme="light"]) body .ca-chart .series { filter: drop-shadow(0 0 5px currentColor) drop-shadow(0 0 2px currentColor); }
html:not([data-theme="light"]) body .ca-chart .cursor { stroke: rgba(0,229,255,.8); filter: drop-shadow(0 0 4px rgba(0,229,255,.8)); }
html:not([data-theme="light"]) body .ca-tip { background: rgba(10,13,31,.88); border: 1px solid rgba(0,229,255,.55); box-shadow: 0 0 24px -4px rgba(0,229,255,.55); }
html:not([data-theme="light"]) body .ca-table th { color: #00e5ff; opacity: .9; }
html:not([data-theme="light"]) body .ca-table th, html:not([data-theme="light"]) body .ca-table td { border-bottom: 1px solid rgba(138,92,255,.28); }
html:not([data-theme="light"]) body .ca-table tbody tr { transition: background .15s ease, box-shadow .15s ease; }
html:not([data-theme="light"]) body .ca-table tbody tr:hover { background: rgba(0,229,255,.07); box-shadow: inset 3px 0 0 #00e5ff; }
html:not([data-theme="light"]) body .ca-title:hover { color: #00e5ff; text-decoration: none; text-shadow: 0 0 10px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .ca-bar { background: rgba(138,92,255,.22); }
html:not([data-theme="light"]) body .ca-bar span { background: linear-gradient(90deg, #8a5cff, #ff2e93); box-shadow: 0 0 10px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .ca-type { border: 1px solid rgba(138,92,255,.4); }
html:not([data-theme="light"]) body .ca-type.short { background: rgba(0,229,255,.14); color: #00e5ff; border-color: rgba(0,229,255,.55); box-shadow: 0 0 10px -2px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .ca-empty { background: rgba(10,13,31,.88); border: 1px dashed rgba(0,229,255,.5); box-shadow: 0 0 22px -8px rgba(0,229,255,.4); }
html:not([data-theme="light"]) body .ca-alert { border-color: rgba(255,46,147,.6); background: rgba(255,46,147,.12); box-shadow: 0 0 20px -6px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .ca-upgrade { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; box-shadow: 0 0 20px rgba(255,46,147,.55); transition: box-shadow .18s ease, transform .18s ease; }
html:not([data-theme="light"]) body .ca-upgrade:hover { transform: translateY(-1px); box-shadow: 0 0 30px rgba(255,46,147,.85); }

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

/* ---- creator analytics ---- */
html[data-theme="light"] body .ca-head h1 { text-shadow: 0 0 18px rgba(124,77,255,.3); }
html[data-theme="light"] body .ca-badge { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
html[data-theme="light"] body .ca-card, html[data-theme="light"] body .ca-panel { background: #ffffff; border: 1px solid rgba(124,77,255,.4); box-shadow: 0 0 26px -10px rgba(124,77,255,.55); }
html[data-theme="light"] body .ca-card:hover { border-color: #00b8e6; box-shadow: 0 0 26px -4px rgba(0,184,230,.55), 0 0 50px -16px rgba(124,77,255,.55); }
html[data-theme="light"] body .ca-card::before { box-shadow: 0 0 14px 1px currentColor; filter: saturate(1.3); }
html[data-theme="light"] body .ca-label i { box-shadow: 0 0 8px currentColor; }
html[data-theme="light"] body .ca-value { text-shadow: 0 0 14px rgba(0,184,230,.25); }
html[data-theme="light"] body .ca-panel h2 { text-shadow: 0 0 12px rgba(124,77,255,.5); }
html[data-theme="light"] body .ca-live-status::before { box-shadow: 0 0 10px #34d399, 0 0 18px rgba(52,211,153,.7); }
html[data-theme="light"] body .ca-chip { background: #ffffff; border: 1px solid rgba(124,77,255,.45); transition: box-shadow .18s ease, border-color .18s ease; }
html[data-theme="light"] body .ca-chip:hover { background: rgba(0,184,230,.12); border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
html[data-theme="light"] body .ca-chip i { box-shadow: 0 0 8px var(--c, currentColor); }
html[data-theme="light"] body .ca-chart .grid { stroke: rgba(124,77,255,.28); }
html[data-theme="light"] body .ca-chart .series { filter: drop-shadow(0 0 5px currentColor) drop-shadow(0 0 2px currentColor); }
html[data-theme="light"] body .ca-chart .cursor { stroke: rgba(0,184,230,.8); filter: drop-shadow(0 0 4px rgba(0,184,230,.8)); }
html[data-theme="light"] body .ca-tip { background: #ffffff; border: 1px solid rgba(0,184,230,.55); box-shadow: 0 0 24px -4px rgba(0,184,230,.55); }
html[data-theme="light"] body .ca-table th { color: #0086b3; opacity: .9; }
html[data-theme="light"] body .ca-table th, html[data-theme="light"] body .ca-table td { border-bottom: 1px solid rgba(124,77,255,.28); }
html[data-theme="light"] body .ca-table tbody tr { transition: background .15s ease, box-shadow .15s ease; }
html[data-theme="light"] body .ca-table tbody tr:hover { background: rgba(0,184,230,.07); box-shadow: inset 3px 0 0 #00b8e6; }
html[data-theme="light"] body .ca-title:hover { color: #0086b3; text-decoration: none; text-shadow: 0 0 10px rgba(0,184,230,.5); }
html[data-theme="light"] body .ca-bar { background: rgba(124,77,255,.22); }
html[data-theme="light"] body .ca-bar span { background: linear-gradient(90deg, #7c4dff, #ff2e93); box-shadow: 0 0 10px rgba(255,46,147,.6); }
html[data-theme="light"] body .ca-type { border: 1px solid rgba(124,77,255,.4); }
html[data-theme="light"] body .ca-type.short { background: rgba(0,184,230,.14); color: #0086b3; border-color: rgba(0,184,230,.55); box-shadow: 0 0 10px -2px rgba(0,184,230,.5); }
html[data-theme="light"] body .ca-empty { background: #ffffff; border: 1px dashed rgba(0,184,230,.5); box-shadow: 0 0 22px -8px rgba(0,184,230,.4); }
html[data-theme="light"] body .ca-alert { border-color: rgba(255,46,147,.6); background: rgba(255,46,147,.12); box-shadow: 0 0 20px -6px rgba(255,46,147,.55); }
html[data-theme="light"] body .ca-upgrade { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; box-shadow: 0 0 20px rgba(255,46,147,.55); transition: box-shadow .18s ease, transform .18s ease; }
html[data-theme="light"] body .ca-upgrade:hover { transform: translateY(-1px); box-shadow: 0 0 30px rgba(255,46,147,.85); }

/* Creator Studio dashboard */
.ca-page{width:min(100%,1500px);max-width:1500px;margin:0 auto;padding:14px clamp(14px,2vw,28px) 56px;color:var(--text,#eaf0ff)}
.st-icon{width:1em;height:1em;flex:0 0 auto;display:inline-block;vertical-align:-.125em}
.st-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:112px;overflow:hidden;padding:15px 22px;margin-bottom:14px;border:1px solid rgba(139,92,246,.32);border-radius:20px;background:linear-gradient(110deg,#161032,#0d0b1c 57%,#26104b);box-shadow:0 12px 32px -24px rgba(114,0,255,.7)}
.st-hero::before{content:"";position:absolute;inset:-60% 19% -90% 26%;transform:rotate(-18deg);background:linear-gradient(110deg,transparent 34%,rgba(99,0,255,.08) 35%,rgba(117,0,255,.32) 50%,rgba(99,0,255,.08) 66%,transparent 67%);pointer-events:none}
.st-hero-main,.st-hero-actions{position:relative;z-index:1;display:flex;align-items:center}
.st-hero-main{gap:18px;min-width:0}
.st-hero-mark{display:grid;place-items:center;flex:0 0 78px;width:78px;height:78px;border:1px solid rgba(213,169,255,.72);border-radius:50%;background:radial-gradient(circle at 35% 25%,#9100ff,#26006d 72%);box-shadow:0 0 0 4px rgba(132,0,255,.2),0 0 26px rgba(132,0,255,.55);color:#fff;font-size:34px}
.st-hero-copy{min-width:0}
.st-hero h1{margin:0;color:#fff;font-size:clamp(26px,3vw,38px);font-weight:850;letter-spacing:-.03em;line-height:1.1}
.st-hero-copy p{margin:7px 0 0;color:#d0c8e8;font-size:15px}
.st-hero-copy strong{color:#fff}
.st-hero-actions{gap:10px;flex:0 0 auto}
.st-hero-badge{display:inline-flex;align-items:center;gap:7px;padding:10px 13px;border:1px solid rgba(245,185,66,.3);border-radius:12px;background:rgba(15,10,31,.65);color:#f7d66c;font-size:13px;font-weight:700}
.st-hero-badge .st-icon{font-size:17px}
.st-hero-cta{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:42px;padding:0 15px;border:1px solid rgba(191,133,255,.45);border-radius:12px;background:linear-gradient(110deg,#7410e8,#9a14ff);box-shadow:0 6px 18px rgba(104,0,212,.28);color:#fff;font-size:13px;font-weight:750;text-decoration:none}
.st-hero-cta:hover,.st-promo-link:hover{filter:brightness(1.14);color:#fff}
.ca-grid{grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 14px}
.ca-card,.ca-panel{border:1px solid rgba(105,100,154,.25);border-radius:17px;background:linear-gradient(155deg,rgba(20,19,43,.98),rgba(12,11,27,.98));box-shadow:0 10px 28px -24px rgba(0,0,0,.8)}
.ca-card{min-height:102px;padding:14px 16px 13px 18px;border-color:color-mix(in srgb,var(--accent) 32%,rgba(105,100,154,.25));transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease}
.ca-card::before{width:3px;box-shadow:0 0 15px color-mix(in srgb,var(--accent) 65%,transparent)}
.ca-card::after{content:"";position:absolute;right:-16px;bottom:-28px;width:120px;height:75px;border-radius:50%;background:var(--accent);opacity:.07;filter:blur(20px)}
.ca-card:hover{transform:translateY(-2px);border-color:var(--accent);box-shadow:0 12px 28px -18px var(--accent)}
.ca-label{color:#c4c0dc;opacity:1;font-size:13px;font-weight:600}
.ca-label i{width:9px;height:9px;box-shadow:0 0 10px var(--accent)}
.ca-value{position:relative;z-index:1;margin-top:7px;color:#f7f6ff;font-size:clamp(25px,2.4vw,32px);line-height:1.05}
.st-kpi-note{margin-top:5px;color:#8f8aa9;font-size:11px}
.st-spark{position:absolute;right:14px;bottom:13px;display:flex;align-items:flex-end;gap:4px;height:44px}
.st-spark i{display:block;width:6px;min-height:4px;border-radius:3px 3px 1px 1px;background:linear-gradient(180deg,color-mix(in srgb,var(--accent) 52%,#fff),var(--accent));box-shadow:0 0 9px color-mix(in srgb,var(--accent) 35%,transparent)}
.st-dashboard-grid{display:grid;grid-template-columns:minmax(0,2.65fr) minmax(245px,1fr);align-items:stretch;gap:14px}
.st-dashboard-grid>.ca-panel,.st-bottom-grid>.ca-panel{min-width:0;margin:0}
.ca-panel{padding:17px 18px}
.ca-panel-head{margin-bottom:10px}
.ca-panel h2{display:flex;align-items:center;gap:9px;color:#f1efff;font-size:17px;font-weight:750}
.st-section-icon{color:#a644ff;font-size:20px}
.st-period{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border:1px solid rgba(139,92,246,.26);border-radius:10px;background:rgba(139,92,246,.1);color:#d8d0f0;font-size:12px;font-weight:650}
.st-period .st-icon{color:#bc8bff}
.st-chart-summary{margin:0;color:#a8a2c2;font-size:12px}
.st-chart-summary strong{color:#f1efff;font-size:15px}
.st-chart-box{position:relative;width:100%;overflow:hidden;margin-top:8px}
.st-chart{display:block;width:100%;height:250px;overflow:visible;cursor:crosshair;touch-action:pan-y}
.st-chart:focus-visible{outline:2px solid #b44aff;outline-offset:3px;border-radius:8px}
.st-chart-tooltip{position:absolute;z-index:2;display:grid;gap:3px;min-width:112px;padding:8px 11px;border:1px solid rgba(180,74,255,.55);border-radius:10px;background:rgba(16,12,31,.96);box-shadow:0 8px 24px rgba(0,0,0,.36),0 0 18px rgba(164,45,255,.16);color:#c6bddf;font-size:11px;line-height:1.3;pointer-events:none;transform:translateX(-50%);white-space:nowrap}
.st-chart-tooltip[hidden]{display:none}
.st-chart-tooltip strong{color:#fff;font-size:14px;font-variant-numeric:tabular-nums}
.st-chart text{fill:#aaa4c1;font-family:inherit;font-size:11px}
.st-chart .st-gridline{stroke:rgba(139,92,246,.17);stroke-width:1;shape-rendering:crispEdges}
.st-chart .st-area{fill:url(#stChartFill)}
.st-chart .st-line{fill:none;stroke:#b44aff;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 0 4px rgba(158,41,255,.72))}
.st-chart .st-dot{fill:#f4e5ff;stroke:#a42dff;stroke-width:1.6}
.st-chart .st-latest-line{stroke:rgba(180,74,255,.55);stroke-width:1.2}
.st-chart .st-latest-dot{fill:#f4e5ff;stroke:#a42dff;stroke-width:2.5;filter:drop-shadow(0 0 5px rgba(164,45,255,.75))}
.st-chart .st-dot:hover{r:5}
.st-chart-summary{display:flex;align-items:baseline;gap:5px;margin:2px 0 0;color:#a8a2c2;font-size:12px}
.st-chart-summary strong{color:#f1efff;font-size:16px;font-weight:800;font-variant-numeric:tabular-nums}
@media(max-width:520px){.st-chart{height:220px}}
.st-chart-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;min-height:26px;margin-top:2px;color:#a8a2c2;font-size:11px}
.st-chart-legend{display:inline-flex;align-items:center;gap:7px;color:#b9b3d2;font-size:12px}
.st-chart-legend i{width:9px;height:9px;border-radius:50%;background:#a42dff;box-shadow:0 0 10px #a42dff}
.st-ranking{display:flex;flex-direction:column;justify-content:space-between;min-height:260px;background:radial-gradient(circle at 82% 55%,rgba(245,185,66,.1),transparent 42%),linear-gradient(155deg,#19172b,#0d0c1c)}
.st-ranking-title .st-icon{color:#f4c94f}
.st-rank-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:16px 0}
.st-rank-number{display:block;color:#b547ff;font-size:clamp(42px,5vw,58px);font-weight:900;letter-spacing:-.06em;line-height:1;text-shadow:0 0 24px rgba(154,54,255,.32)}
.st-rank-caption{display:block;margin-top:8px;color:#aaa5c0;font-size:12px}
.st-rank-emblem{display:grid;place-items:center;width:84px;height:84px;color:#f7cf52;font-size:45px;filter:drop-shadow(0 4px 12px rgba(245,185,66,.36))}
.st-rank-badge{display:flex;align-items:center;justify-content:center;gap:8px;min-height:39px;padding:8px;border-radius:11px;background:linear-gradient(100deg,#4c0ca4,#30106a);color:#fff0c1;font-size:12px;font-weight:700;text-align:center}
.st-bottom-grid{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(320px,1fr);gap:14px;margin-top:14px}
.st-top-list{display:grid}
.st-top-item{display:grid;grid-template-columns:28px 92px minmax(0,1fr) minmax(74px,110px);align-items:center;gap:12px;padding:9px 0;border-top:1px solid rgba(130,123,176,.16)}
.st-top-rank{display:grid;place-items:center;width:25px;height:25px;border-radius:50%;background:linear-gradient(145deg,#b493ff,#7762bb);color:#17122a;font-size:12px;font-weight:850}
.st-top-thumb{position:relative;display:grid;place-items:center;width:92px;height:56px;overflow:hidden;border:1px solid rgba(155,99,255,.25);border-radius:9px;background:linear-gradient(140deg,#39146a,#15142e);color:#cba7ff;text-decoration:none}
.st-top-thumb img{width:100%;height:100%;object-fit:cover}
.st-top-thumb .st-icon{position:absolute;width:18px;height:18px;padding:4px;border-radius:50%;background:rgba(8,7,18,.78);color:#fff}
.st-top-info{min-width:0}
.st-top-title{display:block;overflow:hidden;color:#f2efff;font-size:13px;font-weight:700;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}
.st-top-title:hover{color:#c78aff}
.st-top-meta{display:block;margin-top:4px;color:#9690af;font-size:11px}
.st-top-views{color:#ddd8f3;font-size:12px;font-weight:700;text-align:right}
.st-top-bar{height:5px;margin-top:7px;overflow:hidden;border-radius:99px;background:rgba(145,131,195,.16)}
.st-top-bar span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#7c20ff,#c44dff)}
.st-side-stack{display:grid;align-content:start;gap:14px}
.st-quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.st-quick-card{display:flex;align-items:center;gap:9px;min-width:0;padding:10px;border:1px solid rgba(132,105,189,.2);border-radius:11px;background:rgba(123,89,181,.07)}
.st-quick-icon{display:grid;place-items:center;flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:color-mix(in srgb,var(--quick) 22%,#16132a);color:var(--quick);font-size:17px}
.st-quick-label{display:block;color:#aaa4c1;font-size:10px}
.st-quick-value{display:block;margin-top:2px;color:#f5f2ff;font-size:14px;font-weight:750}
.st-promo{display:flex;align-items:center;gap:11px;padding:13px;border:1px solid rgba(155,54,255,.38);border-radius:14px;background:linear-gradient(105deg,#421196,#281050);box-shadow:0 0 22px -12px rgba(126,23,255,.55)}
.st-promo-icon{display:grid;place-items:center;flex:0 0 40px;width:40px;height:40px;border-radius:12px;background:rgba(255,255,255,.1);color:#fff;font-size:23px}
.st-promo-copy{flex:1;min-width:0}
.st-promo-copy strong{display:block;color:#fff;font-size:12px}
.st-promo-copy span{display:block;margin-top:4px;color:#d4c4ef;font-size:10px;line-height:1.4}
.st-promo-link{display:inline-flex;align-items:center;justify-content:center;gap:5px;flex:0 0 auto;padding:8px 10px;border-radius:9px;background:#8500ff;color:#fff;font-size:11px;font-weight:750;text-decoration:none}
.st-content-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px}
.st-content-head .ca-panel-head{margin:0}
.st-content-head .st-tabs{margin:0}
.st-sort-label{color:#aaa4c1;font-size:12px}
.st-trend{display:inline-flex;align-items:center;gap:5px;font-size:11px}
.st-trend.is-up{color:#34d399}
.st-trend.is-down{color:#fb7185}
.st-tab{padding:7px 12px;border-color:rgba(139,92,246,.25);background:rgba(139,92,246,.06);color:#c7c0dd}
.st-tab:hover{background:rgba(139,92,246,.16);color:#fff}
.st-tab.on{border-color:#8a22ff;background:linear-gradient(110deg,#7521df,#a10dff);box-shadow:0 0 16px rgba(135,18,255,.25)}
.ca-table{min-width:680px}
.ca-table th,.ca-table td{padding:13px 10px;border-color:rgba(130,123,176,.17)}
.ca-table th{color:#a8a2c2;font-weight:700}
.ca-table tbody tr:hover{background:rgba(133,49,224,.08)}
.ca-rank{color:#a98cdd;opacity:1}
.ca-title{color:#f0edff;font-size:13px}
.ca-bar{background:rgba(144,123,192,.17)}
.ca-bar span{background:linear-gradient(90deg,#7620e8,#bc48ff);box-shadow:0 0 9px rgba(145,32,255,.32)}
.ca-type{color:#d1c9e8}
.ca-type.short{background:rgba(0,191,255,.12);color:#67d8ff}
.ca-empty{color:#c2bad9}

html:not([data-theme="light"]) body .ca-page .ca-card,html:not([data-theme="light"]) body .ca-page .ca-panel{border-color:rgba(105,100,154,.25);background:linear-gradient(155deg,rgba(20,19,43,.98),rgba(12,11,27,.98));box-shadow:0 10px 28px -24px rgba(0,0,0,.8)}
html:not([data-theme="light"]) body .ca-page .ca-card:hover{border-color:var(--accent);box-shadow:0 12px 28px -18px var(--accent)}
html:not([data-theme="light"]) body .ca-page .ca-value{color:#f7f6ff;text-shadow:0 0 12px color-mix(in srgb,var(--accent) 35%,transparent)}
html:not([data-theme="light"]) body .ca-page .ca-panel h2{color:#f1efff}
html:not([data-theme="light"]) body .ca-page .st-ranking{background:radial-gradient(circle at 82% 55%,rgba(245,185,66,.1),transparent 42%),linear-gradient(155deg,#19172b,#0d0c1c)}
html:not([data-theme="light"]) body .ca-page .ca-title{color:#f0edff}
html:not([data-theme="light"]) body .ca-page .ca-table tbody tr:hover{background:rgba(133,49,224,.08);box-shadow:inset 3px 0 0 #9c40ff}
html[data-theme="light"] body .ca-page .ca-card,html[data-theme="light"] body .ca-page .ca-panel{border-color:rgba(110,77,160,.2);background:linear-gradient(155deg,#fff,#faf9ff);box-shadow:0 10px 28px -24px rgba(49,25,91,.38)}
html[data-theme="light"] body .ca-page .ca-value{color:#211737;text-shadow:none}
html[data-theme="light"] body .ca-page .ca-panel h2,html[data-theme="light"] body .ca-page .ca-title{color:#211737}
html[data-theme="light"] body .ca-page .st-ranking{background:radial-gradient(circle at 82% 55%,rgba(245,185,66,.12),transparent 42%),linear-gradient(155deg,#fff,#f8f6ff)}
html:not([data-theme="light"]) body .app-navbar{background:rgba(5,5,16,.91);border-bottom-color:rgba(124,0,255,.28);box-shadow:0 1px 22px -8px rgba(124,0,255,.4)}
html:not([data-theme="light"]) body .brand{background:linear-gradient(90deg,#fff,#fff 55%,#9b37ff);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 8px rgba(124,0,255,.45))}
html:not([data-theme="light"]) body #search-input{border-color:rgba(139,92,246,.42);box-shadow:0 0 10px -2px rgba(124,0,255,.25)}
html:not([data-theme="light"]) body #search-input:focus{border-color:#9b37ff;box-shadow:0 0 0 1px #9b37ff,0 0 18px rgba(124,0,255,.4)}
html:not([data-theme="light"]) body .app-sidebar{background:linear-gradient(180deg,rgba(10,9,25,.98),rgba(5,4,14,.99));border-right-color:rgba(139,92,246,.28);box-shadow:4px 0 24px -12px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body .sidebar-link:hover{background:rgba(124,0,255,.12);box-shadow:inset 3px 0 0 #8b00ff,0 0 14px -5px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body .sidebar-link.active{background:linear-gradient(90deg,rgba(124,0,255,.76),rgba(96,0,212,.72));box-shadow:inset 3px 0 0 #c58aff,0 0 18px -5px rgba(124,0,255,.5)}
html:not([data-theme="light"]) body .sidebar-heading{color:#b48aff;text-shadow:0 0 8px rgba(124,0,255,.35)}
html:not([data-theme="light"]) body{background:radial-gradient(850px 460px at 62% -12%,rgba(91,0,177,.13),transparent 70%),#05040e;background-attachment:fixed}
html[data-theme="light"] .ca-page{color:#17132a}
html[data-theme="light"] .st-hero{background:linear-gradient(110deg,#17102d,#241043 60%,#421285)}
html[data-theme="light"] .ca-card,html[data-theme="light"] .ca-panel{background:linear-gradient(155deg,#fff,#faf9ff);border-color:rgba(110,77,160,.2);box-shadow:0 10px 28px -24px rgba(49,25,91,.38)}
html[data-theme="light"] .ca-label,html[data-theme="light"] .st-chart-legend,html[data-theme="light"] .st-content-head .st-sort-label{color:#55506a}
html[data-theme="light"] .ca-value,html[data-theme="light"] .ca-panel h2,html[data-theme="light"] .st-top-title,html[data-theme="light"] .st-quick-value,html[data-theme="light"] .ca-title{color:#211737}
html[data-theme="light"] .st-kpi-note,html[data-theme="light"] .st-chart-summary,html[data-theme="light"] .st-rank-caption,html[data-theme="light"] .st-top-meta,html[data-theme="light"] .st-quick-label{color:#656079}
html[data-theme="light"] .st-ranking{background:radial-gradient(circle at 82% 55%,rgba(245,185,66,.12),transparent 42%),linear-gradient(155deg,#fff,#f8f6ff)}
html[data-theme="light"] .st-quick-card{background:rgba(123,89,181,.045)}
html[data-theme="light"] .ca-table th{color:#5d5576}
html[data-theme="light"] .ca-table td{color:#211737}
html[data-theme="light"] .ca-table th,html[data-theme="light"] .ca-table td,.st-top-item{border-color:rgba(90,66,133,.14)}
html[data-theme="light"] .st-chart .st-gridline{stroke:rgba(99,61,170,.15)}
html[data-theme="light"] .st-chart-tooltip{background:rgba(255,255,255,.97);color:#69627f;box-shadow:0 8px 24px rgba(49,25,91,.16)}
html[data-theme="light"] .st-chart-tooltip strong{color:#211737}
html[data-theme="light"] .st-chart text{fill:#69627f}
html[data-theme="light"] .st-chart-summary{color:#656079}
html[data-theme="light"] .st-chart-summary strong{color:#211737}
html[data-theme="light"] .st-chart-footnote{color:#656079}
@media(max-width:1150px){
  .st-dashboard-grid{grid-template-columns:minmax(0,2fr) minmax(220px,1fr)}
  .ca-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:850px){
  .st-dashboard-grid,.st-bottom-grid{grid-template-columns:1fr}
  .st-ranking{min-height:210px}
}
@media(max-width:600px){
  .ca-page{padding:12px 12px 40px}
  .st-hero{align-items:flex-start;flex-direction:column;padding:14px;gap:12px}
  .st-hero-main{gap:13px}
  .st-hero-mark{width:58px;height:58px;flex-basis:58px;font-size:27px}
  .st-hero-actions{width:100%;justify-content:space-between}
  .st-hero-badge{font-size:11px}
  .ca-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}
  .ca-card{min-height:95px;padding:12px 11px 11px 14px}
  .ca-value{font-size:24px}
  .st-spark{right:9px;bottom:9px;gap:3px;height:31px}
  .st-spark i{width:4px}
  .ca-panel{padding:14px}
  .st-top-item{grid-template-columns:24px 76px minmax(0,1fr);gap:9px}
  .st-top-thumb{width:76px;height:50px}
  .st-top-views{grid-column:3;text-align:left}
  .st-top-bar{max-width:140px}
  .st-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .st-promo{flex-wrap:wrap}
  .st-promo-link{margin-left:auto}
  .ca-table{min-width:620px}
  .ca-col-type{display:none}
}
@media(prefers-reduced-motion:reduce){
  .ca-card,.ca-upgrade,.st-hero-cta,.st-promo-link,.ca-live-status::before{transition:none!important;animation:none!important}
}

.st-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}
.st-tab{padding:7px 14px;border-radius:999px;border:1px solid var(--border,#303030);color:inherit;text-decoration:none;font-size:13px}
.st-tab:hover{background:rgba(128,128,128,.12)}
.st-tab.on{background:#7c4dff;border-color:#7c4dff;color:#fff}
.st-bars{display:block;width:100%;height:auto}
.st-bars rect{fill:#f5b942}
.st-bars rect:hover{fill:#ff2e93}
.st-bars text{fill:var(--muted,#9a9a9a);font-size:10px}
.st-tip{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px}
CSS;
vs_page_start('Creator Studio', 'studio', $extraCss);
?>
<div class="ca-page">
    <header class="st-hero">
        <div class="st-hero-main">
            <span class="st-hero-mark" aria-hidden="true"><?= $ic('play') ?></span>
            <div class="st-hero-copy">
                <h1>Creator Studio</h1>
                <p>Welcome back, <strong><?= $h($username) ?></strong>! Keep creating great content.</p>
            </div>
        </div>
        <div class="st-hero-actions">
            <?php if ($isPremium): ?>
                <span class="st-hero-badge"><?= $ic('star') ?> Premium creator</span>
            <?php else: ?>
                <span class="st-hero-badge"><?= $ic('star') ?> Your channel is growing</span>
            <?php endif; ?>
            <a class="st-hero-cta" href="upload.php">Create content <?= $ic('arrow') ?></a>
        </div>
    </header>

    <?php if ($error !== ''): ?>
        <div class="ca-alert" role="alert"><?= $h($error) ?></div>
    <?php else: ?>
        <section class="ca-grid" aria-label="Channel performance totals">
            <?php
            $kpis = [
                ['Total views', $totals['views'], '#8b35ff', $viewsAvailable ? $compact($totals['views_30d']) . ' in the last 30 days' : 'All time'],
                ['Total likes', $totals['likes'], '#ed38a8', 'Across your videos and Shorts'],
                ['Total comments', $totals['comments'], '#338dff', 'Across your videos and Shorts'],
                ['Subscribers', $totals['subscribers'], '#10c981', 'People following your channel'],
            ];
            foreach ($kpis as $index => [$label, $value, $color, $note]):
            ?>
                <article class="ca-card" style="--accent:<?= $h($color) ?>">
                    <div class="ca-label"><i></i><?= $h($label) ?></div>
                    <div class="ca-value"><?= $value === null ? '—' : $h($compact($value)) ?></div>
                    <div class="st-kpi-note"><?= $h($note) ?></div>
                    <?php if ($index === 0 && $viewsAvailable): ?>
                        <div class="st-spark" aria-hidden="true">
                            <?php foreach ($lastSeven as $value): ?><i style="height:<?= max(8, (int)round($value / $maxSeven * 100)) ?>%"></i><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="st-dashboard-grid">
            <section class="ca-panel">
                <div class="ca-panel-head">
                    <h2><span class="st-section-icon"><?= $ic('analytics') ?></span> Channel Analytics</h2>
                    <span class="st-period"><?= $ic('calendar') ?> Last 30 Days</span>
                </div>
                <?php if (!$viewsAvailable): ?>
                    <p class="ca-empty">Daily views will appear here after your videos get their first views.</p>
                <?php else: ?>
                    <div class="st-chart-summary"><strong><?= $h($compact($totals['views_30d'])) ?></strong><span>views in the last 30 days</span></div>
                    <div class="st-chart-box">
                        <div class="st-chart-tooltip" id="stChartTooltip" role="tooltip" aria-hidden="true" hidden>
                            <span data-chart-date></span>
                            <strong><span data-chart-views></span> views</strong>
                        </div>
                        <svg class="st-chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" data-chart-points="<?= $h($chartInteractionJson) ?>" data-chart-left="<?= $chartLeft ?>" data-chart-right="<?= $chartWidth - $chartRight ?>" role="img" aria-label="Daily views for the last 30 days. Move across the chart or use the arrow keys to inspect each day." aria-describedby="stChartTooltip" tabindex="0">
                            <defs><linearGradient id="stChartFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a42dff" stop-opacity=".32"/><stop offset="1" stop-color="#a42dff" stop-opacity="0"/></linearGradient></defs>
                            <?php for ($step = 0; $step <= 4; $step++):
                                $axisValue = $chartScale * (1 - $step / 4);
                                $y = $chartY($axisValue);
                            ?>
                                <line class="st-gridline" x1="<?= $chartLeft ?>" x2="<?= $chartWidth - $chartRight ?>" y1="<?= round($y, 1) ?>" y2="<?= round($y, 1) ?>"/>
                                <text x="<?= $chartLeft - 9 ?>" y="<?= round($y + 4, 1) ?>" text-anchor="end"><?= $h($compact($axisValue)) ?></text>
                            <?php endfor; ?>
                            <?php foreach ($days as $index => $day):
                                if ($index % 5 !== 0 && $index !== count($days) - 1) { continue; }
                                if ($index !== count($days) - 1 && count($days) - 1 - $index < 4) { continue; }
                                $x = $chartPoints[$index][0];
                                $anchor = $index === 0 ? 'start' : ($index === count($days) - 1 ? 'end' : 'middle');
                            ?>
                                <line class="st-gridline" x1="<?= $x ?>" x2="<?= $x ?>" y1="<?= $chartTop ?>" y2="<?= $chartTop + $chartInnerHeight + 6 ?>"/>
                                <text x="<?= $x ?>" y="<?= $chartHeight - 8 ?>" text-anchor="<?= $anchor ?>"><?= $h(date('M j', strtotime($day))) ?></text>
                            <?php endforeach; ?>
                            <path class="st-area" d="<?= $h($chartArea) ?>"/>
                            <path class="st-line" d="<?= $h($chartLine) ?>"/>
                            <?php [$latestX, $latestY] = $chartPoints[count($chartPoints) - 1]; ?>
                            <line class="st-latest-line" x1="<?= $latestX ?>" x2="<?= $latestX ?>" y1="<?= $chartTop ?>" y2="<?= $chartTop + $chartInnerHeight ?>"/>
                            <circle class="st-latest-dot" cx="<?= $latestX ?>" cy="<?= $latestY ?>" r="5">
                                <title><?= $h(date('M j', strtotime($days[count($days) - 1])) . ': ' . number_format($viewsByDay[$days[count($days) - 1]]) . ' views') ?></title>
                            </circle>
                        </svg>
                    </div>
                    <div class="st-chart-foot"><span class="st-chart-legend"><i></i> Daily views</span>
                        <?php if ($viewTrend !== null): ?>
                            <span class="st-trend <?= $viewTrend >= 0 ? 'is-up' : 'is-down' ?>"><?= $ic($viewTrend >= 0 ? 'trend-up' : 'trend-down') ?> <?= $viewTrend >= 0 ? '+' : '' ?><?= number_format($viewTrend, 1) ?>% vs previous 15 days</span>
                        <?php elseif ($newActivity): ?>
                            <span class="st-trend is-up"><?= $ic('trend-up') ?> New activity · low baseline</span>
                        <?php else: ?>
                            <span class="st-chart-footnote">Compared with the previous 15 days</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </section>

            <aside class="ca-panel st-ranking" aria-label="Creator ranking">
                <div class="ca-panel-head"><h2 class="st-ranking-title"><?= $ic('trophy') ?> Your Ranking</h2></div>
                <div class="st-rank-row">
                    <div>
                        <strong class="st-rank-number"><?= $creatorRanking['rank'] === null ? '—' : '#' . number_format((int)$creatorRanking['rank']) ?></strong>
                        <span class="st-rank-caption">
                            <?= $creatorRanking['total_creators'] > 0
                                ? 'Among ' . number_format((int)$creatorRanking['total_creators']) . ' creators'
                                : 'Ranking appears as creators earn views' ?>
                        </span>
                    </div>
                    <span class="st-rank-emblem" aria-hidden="true"><?= $ic('trophy') ?></span>
                </div>
                <div class="st-rank-badge">
                    <?= $ic($creatorRanking['rank'] !== null && $creatorRanking['rank'] <= 3 ? 'star' : 'analytics') ?>
                    <?= $creatorRanking['rank'] !== null && $creatorRanking['rank'] <= 3 ? 'Top 3 creators by views!' : 'Keep growing your audience' ?>
                </div>
            </aside>
        </div>

        <div class="st-bottom-grid">
            <section class="ca-panel">
                <div class="ca-panel-head"><h2><?= $ic('play') ?> Your Top Videos &amp; Shorts</h2><a class="ca-view-all" href="#stContentLibrary">View All <?= $ic('arrow') ?></a></div>
                <?php if (!$topVideos): ?>
                    <p class="ca-empty">Your published videos and Shorts will appear here. <a href="upload.php">Upload your first video</a>.</p>
                <?php else: ?>
                    <div class="st-top-list">
                        <?php foreach ($topVideos as $index => $video): ?>
                            <?php $videoUrl = ($video['is_short'] ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode((string)$video['video_id']); ?>
                            <article class="st-top-item">
                                <span class="st-top-rank"><?= $index + 1 ?></span>
                                <a class="st-top-thumb" href="<?= $h($videoUrl) ?>" aria-label="Play <?= $h($video['title'] !== '' ? $video['title'] : 'Untitled') ?>">
                                    <?php if ($video['thumbnail'] !== ''): ?><img src="<?= $h($video['thumbnail']) ?>" alt="" loading="lazy"><?php else: ?><?= $ic('play') ?><?php endif; ?>
                                </a>
                                <div class="st-top-info">
                                    <a class="st-top-title" href="<?= $h($videoUrl) ?>"><?= $h($video['title'] !== '' ? $video['title'] : 'Untitled') ?></a>
                                    <span class="st-top-meta"><?= $h($compact($video['views'])) ?> views · <?= $video['is_short'] ? 'Short' : 'Video' ?></span>
                                </div>
                                <div class="st-top-views"><?= $ic('eye') ?> <?= $h($compact($video['views'])) ?><div class="st-top-bar"><span style="width:<?= max(4, (int)round($video['views'] / $maxViews * 100)) ?>%"></span></div></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <div class="st-side-stack">
                <aside class="ca-panel">
                    <div class="ca-panel-head"><h2><?= $ic('analytics') ?> Quick Stats</h2><a class="ca-view-all" href="<?= $isPremium ? 'creator-analytics.php' : 'premium.php' ?>">Details <?= $ic('arrow') ?></a></div>
                    <div class="st-quick-grid">
                        <div class="st-quick-card"><span class="st-quick-icon" style="--quick:#a644ff"><?= $ic('play') ?></span><span><span class="st-quick-label">Videos</span><strong class="st-quick-value"><?= number_format($totals['videos']) ?></strong></span></div>
                        <div class="st-quick-card"><span class="st-quick-icon" style="--quick:#f04cb2"><?= $ic('play') ?></span><span><span class="st-quick-label">Shorts</span><strong class="st-quick-value"><?= number_format($totals['shorts']) ?></strong></span></div>
                        <div class="st-quick-card"><span class="st-quick-icon" style="--quick:#12ca84"><?= $ic('eye') ?></span><span><span class="st-quick-label">Views · 30 days</span><strong class="st-quick-value"><?= $totals['views_30d'] === null ? '—' : $h($compact($totals['views_30d'])) ?></strong></span></div>
                        <div class="st-quick-card"><span class="st-quick-icon" style="--quick:#328fff"><?= $ic('comment') ?></span><span><span class="st-quick-label">Comments</span><strong class="st-quick-value"><?= $h($compact($totals['comments'])) ?></strong></span></div>
                    </div>
                </aside>
                <section class="st-promo">
                    <span class="st-promo-icon" aria-hidden="true"><?= $ic('rocket') ?></span>
                    <div class="st-promo-copy">
                        <strong><?= $isPremium ? 'Take a deeper look at your growth' : 'Grow faster with VibeStream Premium' ?></strong>
                        <span><?= $isPremium ? 'See detailed audience and engagement analytics.' : 'Unlock advanced analytics and creator tools.' ?></span>
                    </div>
                    <a class="st-promo-link" href="<?= $isPremium ? 'creator-analytics.php' : 'premium.php' ?>"><?= $isPremium ? 'Analytics' : 'Go Premium' ?> <?= $ic('arrow') ?></a>
                </section>
            </div>
        </div>

        <section class="ca-panel" id="stContentLibrary">
            <div class="st-content-head">
                <div class="ca-panel-head"><h2>Your Content Library</h2><span class="st-sort-label"><?= number_format(count($videos)) ?> items</span></div>
                <nav class="st-tabs" aria-label="Sort content">
                    <?php foreach (['newest' => 'Newest', 'views' => 'Most views', 'likes' => 'Most likes', 'comments' => 'Most comments'] as $key => $label): ?>
                        <a class="st-tab<?= $sort === $key ? ' on' : '' ?>" href="?sort=<?= $h($key) ?>#stContentLibrary"<?= $sort === $key ? ' aria-current="true"' : '' ?>><?= $h($label) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php if (!$videos): ?>
                <p class="ca-empty">You have not published anything yet. <a href="upload.php">Upload your first video</a>.</p>
            <?php else: ?>
                <div style="overflow-x:auto">
                    <table class="ca-table">
                        <thead><tr><th>#</th><th>Content</th><th class="ca-col-type">Type</th><th class="num">Views</th><th class="num">Likes</th><th class="num">Comments</th><th class="ca-col-type">Published</th></tr></thead>
                        <tbody>
                        <?php foreach ($videos as $rank => $v): ?>
                            <tr>
                                <td class="ca-rank"><?= (int)$rank + 1 ?></td>
                                <td>
                                    <a class="ca-title" href="<?= ($v['is_short'] ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode((string)$v['video_id']) ?>"><?= $h($v['title'] !== '' ? $v['title'] : 'Untitled') ?></a>
                                    <div class="ca-bar" aria-hidden="true"><span style="width:<?= max(2, round($v['views'] / $maxViews * 100)) ?>%"></span></div>
                                </td>
                                <td class="ca-col-type"><span class="ca-type<?= $v['is_short'] ? ' short' : '' ?>"><?= $v['is_short'] ? 'Short' : 'Video' ?></span></td>
                                <td class="num"><?= number_format($v['views']) ?></td>
                                <td class="num"><?= number_format($v['likes']) ?></td>
                                <td class="num"><?= number_format($v['comments']) ?></td>
                                <td class="ca-col-type"><?= $v['created_at'] ? $h(date('M j, Y', strtotime((string)$v['created_at']))) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
    <script>
    (function () {
        var box = document.querySelector('.st-chart-box');
        var svg = box ? box.querySelector('.st-chart') : null;
        var tooltip = document.getElementById('stChartTooltip');
        if (!box || !svg || !tooltip) { return; }

        var points = JSON.parse(svg.getAttribute('data-chart-points') || '[]');
        var cursor = svg.querySelector('.st-latest-line');
        var marker = svg.querySelector('.st-latest-dot');
        var dateLabel = tooltip.querySelector('[data-chart-date]');
        var viewsLabel = tooltip.querySelector('[data-chart-views]');
        var activeIndex = points.length - 1;
        var dragging = false;

        function showPoint(index) {
            if (!points.length) { return; }
            activeIndex = Math.max(0, Math.min(points.length - 1, index));
            var point = points[activeIndex];
            cursor.setAttribute('x1', point.x);
            cursor.setAttribute('x2', point.x);
            marker.setAttribute('cx', point.x);
            marker.setAttribute('cy', point.y);
            marker.querySelector('title').textContent = point.label + ': ' + Number(point.views).toLocaleString() + ' views';
            dateLabel.textContent = point.label;
            viewsLabel.textContent = Number(point.views).toLocaleString();
            tooltip.hidden = false;
            tooltip.setAttribute('aria-hidden', 'false');

            var svgPoint = svg.createSVGPoint();
            svgPoint.x = point.x;
            svgPoint.y = point.y;
            var matrix = svg.getScreenCTM();
            if (!matrix) { return; }
            var screenPoint = svgPoint.matrixTransform(matrix);
            var boxRect = box.getBoundingClientRect();
            var tooltipRect = tooltip.getBoundingClientRect();
            var left = screenPoint.x - boxRect.left;
            var halfWidth = tooltipRect.width / 2;
            left = Math.max(halfWidth + 4, Math.min(boxRect.width - halfWidth - 4, left));
            var top = screenPoint.y - boxRect.top - tooltipRect.height - 12;
            top = Math.max(4, Math.min(boxRect.height - tooltipRect.height - 4, top));
            tooltip.style.left = left + 'px';
            tooltip.style.top = top + 'px';
        }

        function hideTooltip() {
            tooltip.hidden = true;
            tooltip.setAttribute('aria-hidden', 'true');
            activeIndex = points.length - 1;
            if (points.length) {
                cursor.setAttribute('x1', points[activeIndex].x);
                cursor.setAttribute('x2', points[activeIndex].x);
                marker.setAttribute('cx', points[activeIndex].x);
                marker.setAttribute('cy', points[activeIndex].y);
            }
        }

        function inspectPointer(event) {
            if (!points.length) { return; }
            var matrix = svg.getScreenCTM();
            if (!matrix) { return; }
            var svgPoint = svg.createSVGPoint();
            svgPoint.x = event.clientX;
            svgPoint.y = event.clientY;
            var localPoint = svgPoint.matrixTransform(matrix.inverse());
            var left = Number(svg.getAttribute('data-chart-left'));
            var right = Number(svg.getAttribute('data-chart-right'));
            var ratio = Math.max(0, Math.min(1, (localPoint.x - left) / (right - left)));
            showPoint(Math.round(ratio * (points.length - 1)));
        }

        svg.addEventListener('pointerenter', inspectPointer);
        svg.addEventListener('pointermove', inspectPointer);
        svg.addEventListener('pointerdown', function (event) {
            dragging = true;
            inspectPointer(event);
            if (svg.setPointerCapture) { svg.setPointerCapture(event.pointerId); }
        });
        svg.addEventListener('pointerup', function (event) {
            dragging = false;
            if (svg.hasPointerCapture && svg.hasPointerCapture(event.pointerId)) {
                svg.releasePointerCapture(event.pointerId);
            }
        });
        svg.addEventListener('pointercancel', function () { dragging = false; });
        svg.addEventListener('pointerleave', function () {
            if (!dragging && document.activeElement !== svg) { hideTooltip(); }
        });
        svg.addEventListener('focus', function () { showPoint(activeIndex); });
        svg.addEventListener('blur', hideTooltip);
        svg.addEventListener('keydown', function (event) {
            var next = activeIndex;
            if (event.key === 'ArrowLeft') { next -= 1; }
            else if (event.key === 'ArrowRight') { next += 1; }
            else if (event.key === 'Home') { next = 0; }
            else if (event.key === 'End') { next = points.length - 1; }
            else { return; }
            event.preventDefault();
            showPoint(next);
        });
    }());
    </script>
    <?php
    vs_page_end();
