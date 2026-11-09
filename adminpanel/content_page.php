<?php
/**
 * Shared admin list for videos.php and shorts.php.
 * Set $CONTENT_KIND to 'video' or 'short' before including this file.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../vip-badge.php';

// CSRF token for the delete buttons (same token checkin.php uses)
if (empty($_SESSION['vs_csrf'])) {
    $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
}

$CONTENT_KIND = (isset($CONTENT_KIND) && $CONTENT_KIND === 'short') ? 'short' : 'video';
$isShortPage  = ($CONTENT_KIND === 'short');
$pageFile     = $isShortPage ? 'shorts.php' : 'videos.php';
$otherFile    = $isShortPage ? 'videos.php' : 'shorts.php';
$noun         = $isShortPage ? 'short' : 'video';
$nouns        = $isShortPage ? 'shorts' : 'videos';
$Nouns        = ucfirst($nouns);

$uploader = isset($_GET['uploader']) ? trim((string)$_GET['uploader']) : '';

$allContent = [];
$commentCounts = [];
// `id` is the video ID / URL text, so sort by the upload date when the table has one.
$vCols   = vs_cols($pdo, 'videos');
$dateCol = vs_pick($vCols, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date']);
$orderBy = $dateCol ? vs_q($dateCol) . ' DESC, id DESC' : 'id DESC';
try {
    if ($uploader !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM videos
             WHERE LOWER(COALESCE(NULLIF(TRIM(username), ''), 'admin')) = ?
             ORDER BY $orderBy"
        );
        $stmt->execute([mb_strtolower($uploader, 'UTF-8')]);
    } else {
        $stmt = $pdo->query("SELECT * FROM videos ORDER BY $orderBy");
    }
    $allContent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $commentCounts = vs_comment_counts($pdo);
} catch (PDOException $e) {
    $allContent = [];
    $dbError = $e->getMessage();
}

// Keep only this page's kind: videos.php never lists shorts, shorts.php only lists shorts.
$rows = [];
$otherCount = 0;
foreach ($allContent as $row) {
    if (vs_is_short($row['type'] ?? 'video') === $isShortPage) {
        $rows[] = $row;
    } else {
        $otherCount++;
    }
}

// Totals for the summary cards (based on what is listed below).
$sum = ['views' => 0, 'liked' => 0, 'likes' => 0, 'commented' => 0, 'comments' => 0];
foreach ($rows as $row) {
    $likes = max(0, (int)($row['likes'] ?? 0));
    $cm    = (int)($commentCounts[(string)$row['id']] ?? 0);
    $sum['views'] += (int)($row['views'] ?? 0);
    if ($likes > 0) { $sum['liked']++; $sum['likes'] += $likes; }
    if ($cm > 0)    { $sum['commented']++; $sum['comments'] += $cm; }
}
$total = count($rows);


// ---- extra data for the redesigned list ----
$statusCol = vs_pick($vCols, ['status', 'visibility', 'state']);

// Everyone who has uploaded this kind of content (for the "All Uploaders" box)
$uploaderOptions = [];
try {
    foreach ($pdo->query('SELECT username, type FROM videos')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (vs_is_short($r['type'] ?? 'video') !== $isShortPage) {
            continue;
        }
        $n = trim((string)($r['username'] ?? ''));
        if ($n === '') {
            $n = 'Admin';
        }
        $k = mb_strtolower($n, 'UTF-8');
        if (!isset($uploaderOptions[$k])) {
            $uploaderOptions[$k] = $n;
        }
    }
    asort($uploaderOptions, SORT_NATURAL | SORT_FLAG_CASE);
} catch (Throwable $e) {
    $uploaderOptions = [];
}

/** Picture for a row: the YouTube picture, the saved thumbnail, or a grey placeholder. */
if (!function_exists('vs_cp_thumb')) {
    function vs_cp_thumb(array $row, bool $short): string
    {
        $yt = '';
        foreach (['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed', 'id'] as $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v === '') {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) {
                $yt = $v;
                break;
            }
            if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $v, $m)) {
                $yt = $m[1];
                break;
            }
        }
        if ($yt !== '') {
            return 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
        }
        foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'image', 'img', 'poster'] as $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') {
                return preg_match('#^(https?:)?//|^data:#i', $v) ? $v : '../' . ltrim($v, '/');
            }
        }
        return vs_cp_placeholder_thumb($short);
    }
}
if (!function_exists('vs_cp_placeholder_thumb')) {
    function vs_cp_placeholder_thumb(bool $short): string
    {
        $w = $short ? 90 : 160;
        $hh = $short ? 120 : 90;
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $hh . '"><rect width="100%" height="100%" fill="#2a2d3a"/>'
            . '<path d="M' . ($w / 2 - 9) . ' ' . ($hh / 2 - 12) . 'l22 12-22 12z" fill="#7c8099"/></svg>'
        );
    }
}
if (!function_exists('vs_cp_video_source')) {
    function vs_cp_video_source(array $row): string
    {
        $columns = ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed', 'id'];
        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return '';
        }

        foreach ($columns as $column) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value === '' || preg_match('/^[A-Za-z0-9_-]{11}$/', $value) || preg_match('#(?:youtube\.com|youtu\.be)#i', $value)) {
                continue;
            }
            if (preg_match('#^https?://#i', $value)) {
                $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
                if (preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)$/i', $path)) {
                    return $value;
                }
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', $value), '/');
            if ($relative === '' || preg_match('#(^|/)\.\.?(/|$)#', $relative)) {
                continue;
            }
            foreach ([$relative, 'uploads/' . $relative, 'videos/' . $relative, 'uploads/videos/' . $relative, 'media/' . $relative] as $candidate) {
                if (!preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)$/i', $candidate)) {
                    continue;
                }
                $file = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate));
                if ($file === false || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
                    continue;
                }
                return '../' . implode('/', array_map('rawurlencode', explode('/', $candidate)));
            }
        }
        return '';
    }
}
if (!function_exists('vs_cp_initial')) {
    function vs_cp_initial(string $n): string
    {
        $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'A', 0, 1));
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
            . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
            . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
        );
    }
}

$untouched = 0;
foreach ($rows as $row) {
    if (max(0, (int)($row['likes'] ?? 0)) === 0 && (int)($commentCounts[(string)$row['id']] ?? 0) === 0) {
        $untouched++;
    }
}
$siteLinkBase = $isShortPage ? '../shorts.php?id=' : '../watch.php?id=';

// ---- numbers for the stat cards: short text, last-12-days curve, "this week" gain ----
if (!function_exists('vs_cp_short')) {
    function vs_cp_short(int $n): string
    {
        if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
        if ($n >= 10000)   { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
        return number_format($n);
    }
}
if (!function_exists('vs_cp_spark')) {
    /** Small line chart (inline SVG) from a list of numbers. */
    function vs_cp_spark(array $v, string $gid): string
    {
        $n = count($v);
        if ($n < 2) { return ''; }
        $w = 170; $hgt = 46;
        $min = min($v); $max = max($v); $rng = max(1, $max - $min);
        $pts = []; $lx = 0; $ly = 0;
        foreach (array_values($v) as $i => $val) {
            $x = 3 + $i * ($w - 6) / ($n - 1);
            $y = ($max === $min) ? $hgt / 2 : ($hgt - 5) - (($val - $min) / $rng) * ($hgt - 11);
            $pts[] = round($x, 1) . ',' . round($y, 1);
            $lx = round($x, 1); $ly = round($y, 1);
        }
        return '<svg class="mv-spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#7c4dff"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>'
            . '<polyline fill="none" stroke="url(#' . $gid . ')" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>'
            . '<circle cx="' . $lx . '" cy="' . $ly . '" r="3.2" fill="#22d3ee"/></svg>';
    }
}
$mvDays = 12;
$mvStart = strtotime('today') - ($mvDays - 1) * 86400;
$mvBucket = ['views' => array_fill(0, $mvDays, 0), 'likes' => array_fill(0, $mvDays, 0), 'comments' => array_fill(0, $mvDays, 0)];
$mvBase = ['views' => 0, 'likes' => 0, 'comments' => 0];
foreach ($rows as $row) {
    $vals = ['views' => (int)($row['views'] ?? 0), 'likes' => max(0, (int)($row['likes'] ?? 0)), 'comments' => (int)($commentCounts[(string)$row['id']] ?? 0)];
    $ts = ($dateCol && !empty($row[$dateCol])) ? strtotime((string)$row[$dateCol]) : false;
    if ($ts && $ts >= $mvStart) {
        $idx = min($mvDays - 1, (int)floor(($ts - $mvStart) / 86400));
        foreach ($vals as $k => $val) { $mvBucket[$k][$idx] += $val; }
    } else {
        foreach ($vals as $k => $val) { $mvBase[$k] += $val; }
    }
}
$mvSeries = []; $mvWeek = [];
foreach ($mvBucket as $k => $list) {
    $run = $mvBase[$k]; $mvSeries[$k] = [];
    foreach ($list as $val) { $run += $val; $mvSeries[$k][] = $run; }
    $mvWeek[$k] = array_sum(array_slice($list, -7));
}
$mvSiteName = 'VibeStream';

include "header.php";
$h = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};
?>

      <style>
        .mv-page{--mv-card:linear-gradient(155deg,rgba(16,24,68,.96),rgba(7,12,39,.98));--mv-line:rgba(112,104,255,.38);--mv-row:rgba(112,104,255,.16);--mv-text:#eaf0ff;--mv-muted:#9aa8d1;--mv-field:rgba(8,14,46,.9);--mv-hover:rgba(124,77,255,.10);color:var(--mv-text)}
        html[data-theme="light"] .mv-page{--mv-card:linear-gradient(155deg,#ffffff,#f6f7ff);--mv-line:rgba(124,77,255,.30);--mv-row:rgba(124,77,255,.14);--mv-text:#171b3b;--mv-muted:#5d6688;--mv-field:#ffffff;--mv-hover:rgba(124,77,255,.07)}
        .mv-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:24px 30px;border-radius:20px;border:1px solid rgba(143,115,255,.55);background:linear-gradient(110deg,#1b1260 0%,#3b1b9c 52%,#12104a 100%);color:#fff;position:relative;overflow:hidden}
        .mv-hero-logo{width:84px;height:84px;flex:none}
        .mv-hero-logo svg{width:100%;height:100%;display:block}
        .mv-hero h1{font-size:clamp(1.7rem,3.2vw,2.5rem);font-weight:800;margin:0 0 4px;line-height:1.1;color:#fff}
        .mv-hero h1 span{color:#b794ff}
        .mv-hero p{margin:0;color:rgba(255,255,255,.82)}
        .mv-hero-right{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap;position:relative;z-index:1}
        .mv-total{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:999px;border:1px solid rgba(255,255,255,.4);background:rgba(10,12,50,.55);font-weight:600;white-space:nowrap}
        .mv-btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;border-radius:12px;border:1px solid rgba(255,255,255,.35);background:rgba(255,255,255,.10);color:#fff;font-weight:600;font-size:.88rem;text-decoration:none;white-space:nowrap}
        .mv-btn:hover{background:rgba(255,255,255,.2);color:#fff}
        .mv-btn.primary{background:linear-gradient(135deg,#7c4dff,#5b3bd6);border-color:#8f73ff}
        .mv-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px}
        .mv-stat{display:flex;align-items:center;gap:16px;padding:16px 20px;border-radius:18px;border:1px solid var(--mv-line);background:var(--mv-card);min-width:0;overflow:hidden}
        .mv-stat-ico{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;font-size:26px;flex:none}
        .mv-stat-ico.views{background:rgba(34,120,255,.20);color:#4aa8ff}
        .mv-stat-ico.likes,.mv-stat-ico.comments{background:rgba(124,77,255,.22);color:#9f86ff}
        .mv-stat-body{min-width:0}
        .mv-stat-body small{display:block;color:var(--mv-muted);font-size:.9rem}
        .mv-stat-body strong{font-size:2rem;line-height:1.1;font-weight:800;color:var(--mv-text)}
        .mv-trend{margin-left:10px;font-weight:600;font-size:.86rem;color:#22c777;white-space:nowrap}
        .mv-trend.flat{color:var(--mv-muted)}
        .mv-sub{display:block;font-size:.76rem;color:var(--mv-muted);margin-top:2px}
        .mv-spark{margin-left:auto;width:38%;max-width:170px;min-width:70px;height:46px;flex:none}
        .mv-filter{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:14px 18px;margin-top:16px;border-radius:18px;border:1px solid var(--mv-line);background:var(--mv-card)}
        .mv-filter label.mv-lbl{font-weight:700;white-space:nowrap}
        .mv-select{min-width:190px;padding:9px 38px 9px 14px;border-radius:12px;border:1px solid var(--mv-line);background:var(--mv-field) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239aa8d1' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>") no-repeat right 12px center;color:var(--mv-text);appearance:none;-webkit-appearance:none}
        .mv-toggle{display:flex;gap:10px;margin:0 auto}
        .mv-pill{display:inline-flex;align-items:center;gap:8px;padding:9px 20px;border-radius:999px;border:1px solid var(--mv-line);background:var(--mv-field);color:var(--mv-text);font-weight:600;text-decoration:none;white-space:nowrap}
        .mv-pill:hover{color:var(--mv-text);border-color:#8f73ff}
        .mv-pill.on{background:linear-gradient(135deg,#8a5cff,#5b3fe8);border-color:#a48bff;color:#fff}
        .mv-search{position:relative;flex:1 1 220px;max-width:340px;min-width:180px}
        .mv-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--mv-muted)}
        .mv-search input{width:100%;padding:10px 14px 10px 40px;border-radius:12px;border:1px solid var(--mv-line);background:var(--mv-field);color:var(--mv-text)}
        .mv-search input::placeholder{color:var(--mv-muted)}
        .mv-search input:focus,.mv-select:focus{outline:none;border-color:#22d3ee}
        .mv-bulk{padding:9px 16px;border-radius:12px;border:0;background:#e0306f;color:#fff;font-weight:600}
        .mv-tablecard{margin-top:16px;padding:0;border-radius:18px;overflow:hidden}
        .cp-table{--bs-table-bg:transparent;--bs-table-color:var(--mv-text);--bs-table-hover-bg:var(--mv-hover);--bs-table-hover-color:var(--mv-text);margin:0}
        .cp-table>:not(caption)>*>*{background-color:transparent;color:var(--mv-text);border-bottom:1px solid var(--mv-row);padding:10px 8px;vertical-align:middle}
        .cp-table thead th{font-size:.86rem;font-weight:700;white-space:nowrap;padding-top:14px;padding-bottom:14px;border-bottom:1px solid var(--mv-line)}
        .cp-table tbody tr:hover>*{background-color:var(--mv-hover)}
        .cp-table .cp-check{text-align:center}
        .cp-table .form-check-input{margin:0}
        .cp-thumb{width:100%;height:100%;object-fit:cover;background:#2a2d3a;display:block}
        .cp-thumb-link{position:relative;display:block;width:100%;max-width:128px;aspect-ratio:16/9;border-radius:10px;overflow:hidden;background:#2a2d3a}
        .cp-thumb-link.short{width:44px;max-width:44px;aspect-ratio:9/16}
        .cp-thumb-link .cp-thumb{position:absolute;inset:0}
        .cp-thumb-link:after{content:"";position:absolute;left:50%;top:50%;width:0;height:0;border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid #fff;transform:translate(-35%,-50%);filter:drop-shadow(0 1px 3px #000);opacity:0;transition:opacity .15s;pointer-events:none}
        .cp-thumb-link:hover:after{opacity:1}
        .mv-dur{position:absolute;right:6px;bottom:6px;padding:1px 7px;border-radius:6px;background:rgba(0,0,0,.78);color:#fff;font-size:.74rem;font-weight:600;z-index:1}
        .cp-title{font-weight:600;line-height:1.3;overflow-wrap:anywhere}
        .cp-id{font-size:.76rem;color:var(--mv-muted);overflow-wrap:anywhere;margin-top:2px}
        .cp-who{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none;white-space:nowrap;min-width:0}
        .cp-who span{min-width:0;overflow:hidden;text-overflow:ellipsis}
        .cp-who img{flex:none}
        .cp-who:hover{color:inherit}
        .cp-who img{width:36px;height:36px;border-radius:50%;object-fit:cover}
        .mv-num{white-space:nowrap}
        .mv-num i{color:var(--mv-muted);margin-right:6px}
        .mv-status{display:inline-flex;align-items:center;gap:7px;padding:4px 14px;border-radius:999px;font-size:.82rem;font-weight:600;border:1px solid transparent}
        .mv-status:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}
        .mv-status.ok{color:#22c777;background:rgba(34,199,119,.12);border-color:rgba(34,199,119,.55)}
        .mv-status.warn{color:#e69a1a;background:rgba(245,166,35,.14);border-color:rgba(245,166,35,.55)}
        .mv-status.off{color:var(--mv-muted);background:rgba(128,128,128,.14);border-color:rgba(128,128,128,.4)}
        .cp-actions{white-space:nowrap}
        .cp-actions form{display:inline;margin:0}
        .cp-table th.cp-actions-h{text-align:right}
        .mv-act{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:9px;border:1px solid transparent;color:#fff;text-decoration:none;font-size:15px;cursor:pointer;margin-left:4px;padding:0}
        .mv-act:hover{color:#fff;filter:brightness(1.15)}
        .mv-act.view{background:#3d2bb8;border-color:#6a55ff}
        .mv-act.edit{background:#5a3fd6;border-color:#8a73ff}
        .mv-act.del{background:#e0242c;border-color:#ff5560}
        .cp-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid var(--mv-line)}
        .cp-foot span{font-size:.88rem;color:var(--mv-muted)}
        .cp-pager{display:flex;gap:6px;flex-wrap:wrap}
        .cp-pager button{min-width:36px;height:36px;padding:0 12px;border-radius:9px;border:1px solid transparent;background:transparent;color:var(--mv-text);font-weight:600}
        .cp-pager button:hover:not(:disabled){border-color:var(--mv-line)}
        .cp-pager button.on{background:#7c4dff;border-color:#7c4dff;color:#fff}
        .cp-pager button:disabled{opacity:.4;cursor:default}
        @media (min-width:1280px){
          .cp-table{table-layout:fixed;width:100%}
          .cp-table td,.cp-table th{overflow:hidden;text-overflow:ellipsis}
          .cp-table td.cp-actions{overflow:visible}
        }
        @media (max-width:1279.98px){.cp-table{min-width:900px}}
        @media (max-width:991.98px){.mv-stats{grid-template-columns:minmax(0,1fr)}.mv-toggle{margin:0}.mv-hero-right{margin-left:0}}
        @media (max-width:575.98px){.mv-hero{padding:18px}.mv-hero-logo{width:56px;height:56px}.mv-search{max-width:none}}
      </style>
      <style data-vs-neon>
        /* neon glow layer: the lightning button switches this whole block off */
        .mv-hero{box-shadow:0 0 38px -10px rgba(138,92,255,.75),inset 0 0 60px -30px rgba(0,229,255,.35)}
        .mv-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(520px 220px at 82% 20%,rgba(255,46,147,.28),transparent 70%),radial-gradient(420px 200px at 8% 100%,rgba(0,229,255,.18),transparent 70%);pointer-events:none}
        .mv-hero h1 span{background:linear-gradient(90deg,#a78bfa,#e879f9);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 12px rgba(167,139,250,.65))}
        .mv-hero-logo svg{filter:drop-shadow(0 0 14px rgba(124,77,255,.9))}
        .mv-total{box-shadow:0 0 18px -4px rgba(34,211,238,.6);border-color:rgba(34,211,238,.7)}
        .mv-stat,.mv-filter{box-shadow:0 0 24px -10px rgba(112,104,255,.6),inset 0 1px 0 rgba(255,255,255,.04)}
        .mv-stat{transition:border-color .18s ease,box-shadow .18s ease}
        .mv-stat:hover{border-color:#22d3ee;box-shadow:0 0 24px -6px rgba(34,211,238,.55)}
        .mv-stat-ico{box-shadow:0 0 20px -4px currentColor}
        .mv-stat-body strong{text-shadow:0 0 14px rgba(167,139,250,.4)}
        .mv-spark{filter:drop-shadow(0 0 5px rgba(124,77,255,.8))}
        .mv-pill.on{box-shadow:0 0 18px rgba(138,92,255,.75)}
        .mv-search input:focus,.mv-select:focus{box-shadow:0 0 0 1px #22d3ee,0 0 16px rgba(34,211,238,.4)}
        .mv-tablecard{box-shadow:0 0 28px -12px rgba(112,104,255,.55)}
        .cp-thumb-link:hover{box-shadow:0 0 0 2px #8b5cf6,0 0 16px rgba(139,92,246,.7)}
        .mv-status.ok{box-shadow:0 0 12px -3px rgba(34,199,119,.6)}
        .mv-act.view{box-shadow:0 0 12px -3px rgba(106,85,255,.9)}
        .mv-act.edit{box-shadow:0 0 12px -3px rgba(138,115,255,.9)}
        .mv-act.del{box-shadow:0 0 12px -3px rgba(255,85,96,.9)}
        .cp-pager button.on{box-shadow:0 0 16px rgba(124,77,255,.85)}
        @media (prefers-reduced-motion:reduce){.mv-stat{transition:none}}
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 mv-page">
          <section class="mv-hero" aria-label="<?php echo $Nouns; ?>">
            <span class="mv-hero-logo"><svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="mvLg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c084fc"/><stop offset="1" stop-color="#6d28d9"/></linearGradient></defs><path d="M14 8 L90 50 L14 92 Z" fill="url(#mvLg)"/><path d="M14 8 L90 50 L58 50 Z" fill="rgba(255,255,255,.18)"/></svg></span>
            <div>
              <h1>Manage <span><?php echo $Nouns; ?></span></h1>
              <p>View and manage all <?php echo $nouns; ?> on <?php echo $h($mvSiteName); ?>.</p>
            </div>
            <div class="mv-hero-right">
              <span class="mv-total"><i class="bi <?php echo $isShortPage ? 'bi-phone-fill' : 'bi-camera-video-fill'; ?>" aria-hidden="true"></i> Total <?php echo $Nouns; ?>: <?php echo number_format($total); ?></span>
              <a class="mv-btn" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
              <a class="mv-btn primary" href="addvideos.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Upload New</a>
            </div>
          </section>

          <?php if (isset($dbError)): ?>
            <div class="alert alert-danger mt-3"><?php echo $h($dbError); ?></div>
          <?php endif; ?>

          <section class="mv-stats" aria-label="<?php echo $Nouns; ?> engagement">
            <article class="mv-stat">
              <span class="mv-stat-ico views"><i class="bi bi-eye-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Views</small>
                <strong title="<?php echo number_format($sum['views']); ?>"><?php echo $h(vs_cp_short((int)$sum['views'])); ?></strong>
                <?php if ($mvWeek['views'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['views'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                <span class="mv-sub">from <?php echo $nouns; ?> uploaded this week</span>
              </div>
              <?php echo vs_cp_spark($mvSeries['views'], 'mvSpV'); ?>
            </article>
            <article class="mv-stat">
              <span class="mv-stat-ico likes"><i class="bi bi-hand-thumbs-up-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Likes</small>
                <strong title="<?php echo number_format($sum['likes']); ?>"><?php echo $h(vs_cp_short((int)$sum['likes'])); ?></strong>
                <?php if ($mvWeek['likes'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['likes'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                <span class="mv-sub"><?php echo number_format($sum['liked']); ?> liked <?php echo $nouns; ?></span>
              </div>
              <?php echo vs_cp_spark($mvSeries['likes'], 'mvSpL'); ?>
            </article>
            <article class="mv-stat">
              <span class="mv-stat-ico comments"><i class="bi bi-chat-left-text-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Comments</small>
                <strong title="<?php echo number_format($sum['comments']); ?>"><?php echo $h(vs_cp_short((int)$sum['comments'])); ?></strong>
                <?php if ($mvWeek['comments'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['comments'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                <span class="mv-sub"><?php echo number_format($sum['commented']); ?> commented <?php echo $nouns; ?></span>
              </div>
              <?php echo vs_cp_spark($mvSeries['comments'], 'mvSpC'); ?>
            </article>
          </section>

          <section class="mv-filter" aria-label="Filters">
            <label class="mv-lbl" for="cpUploader">Filter by Uploader:</label>
            <select class="mv-select" id="cpUploader" aria-label="Filter by uploader">
              <option value="">All Uploaders</option>
              <?php foreach ($uploaderOptions as $k => $n): ?>
                <option value="<?php echo $h($k); ?>"<?php echo mb_strtolower($uploader, 'UTF-8') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mv-toggle">
              <a class="mv-pill<?php echo !$isShortPage ? ' on' : ''; ?>" href="videos.php<?php echo $uploader !== '' ? '?uploader=' . urlencode($uploader) : ''; ?>"><i class="bi bi-play-btn-fill" aria-hidden="true"></i> All Videos</a>
              <a class="mv-pill<?php echo $isShortPage ? ' on' : ''; ?>" href="shorts.php<?php echo $uploader !== '' ? '?uploader=' . urlencode($uploader) : ''; ?>"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> All Shorts</a>
            </div>
            <div class="mv-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="cpSearch" placeholder="Search <?php echo $nouns; ?>..." aria-label="Search <?php echo $nouns; ?>"></div>
            <button type="button" class="mv-bulk" id="cpBulk" hidden><i class="bi bi-trash" aria-hidden="true"></i> Delete selected (<span id="cpBulkN">0</span>)</button>
          </section>

          <section class="panel mv-tablecard" aria-label="<?php echo $Nouns; ?> list">
            <div class="table-responsive">
              <table class="table cp-table align-middle mb-0">
                <colgroup>
                  <col style="width:3.5%"><col style="width:4%"><col style="width:11%"><col style="width:20.5%"><col style="width:16%">
                  <col style="width:7%"><col style="width:7%"><col style="width:9%"><col style="width:10%"><col style="width:12%">
                </colgroup>
                <thead>
                  <tr>
                    <th scope="col" class="cp-check"><input class="form-check-input" type="checkbox" id="cpAll" aria-label="Select all visible <?php echo $nouns; ?>"></th>
                    <th scope="col">#</th>
                    <th scope="col">Thumbnail</th>
                    <th scope="col">Title</th>
                    <th scope="col">Uploader</th>
                    <th scope="col" class="text-end">Views</th>
                    <th scope="col">Likes</th>
                    <th scope="col">Comments</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-end cp-actions-h">Actions</th>
                  </tr>
                </thead>
                <tbody id="cpRows">
                  <?php if ($total > 0):
                      $sno = 1;
                      foreach ($rows as $row):
                          $who = trim((string)($row['username'] ?? ''));
                          if ($who === '') { $who = 'Admin'; }
                          $likes = max(0, (int)($row['likes'] ?? 0));
                          $cm    = (int)($commentCounts[(string)$row['id']] ?? 0);
                          $title = (string)($row['title'] ?? '');
                          $stRaw = $statusCol ? strtolower(trim((string)($row[$statusCol] ?? ''))) : '';
                          if ($stRaw === '' || in_array($stRaw, ['published', 'public', 'active', 'live', '1'], true)) {
                              $stLabel = 'Public'; $stClass = 'ok';
                          } elseif (in_array($stRaw, ['draft', 'pending', 'private', 'unlisted', 'hidden', 'review', 'scheduled'], true)) {
                              $stLabel = ucfirst($stRaw); $stClass = 'warn';
                          } else {
                              $stLabel = ucfirst($stRaw); $stClass = 'off';
                          }
                          $dateVal = $dateCol && !empty($row[$dateCol]) ? date('Y-m-d', strtotime((string)$row[$dateCol])) : '';
                          $idStr = (string)$row['id'];
                          $thumb = vs_cp_thumb($row, $isShortPage);
                          $videoSource = vs_cp_video_source($row);
                          $dur = trim((string)($row['duration'] ?? ''));
                  ?>
                  <tr class="pg-row" data-search="<?php echo $h(mb_strtolower($title . ' ' . $idStr . ' ' . $who, 'UTF-8')); ?>">
                    <td class="cp-check"><input class="form-check-input cp-pick" type="checkbox" value="<?php echo $h($idStr); ?>" aria-label="Select <?php echo $h($title); ?>"></td>
                    <td class="text-muted"><?php echo $sno++; ?></td>
                    <td>
                      <a class="cp-thumb-link<?php echo $isShortPage ? ' short' : ''; ?>" href="<?php echo $h($siteLinkBase . rawurlencode($idStr)); ?>" title="Play <?php echo $h($title !== '' ? $title : 'Untitled'); ?>" aria-label="Play <?php echo $h($title !== '' ? $title : 'Untitled'); ?>" target="_blank" rel="noopener">
                        <?php if ($videoSource !== ''): ?>
                          <video class="cp-thumb" src="<?php echo $h($videoSource); ?>" poster="<?php echo $h($thumb); ?>" muted playsinline preload="metadata" tabindex="-1" aria-hidden="true"></video>
                        <?php else: ?>
                          <img class="cp-thumb" src="<?php echo $h($thumb); ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $h(vs_cp_placeholder_thumb($isShortPage)); ?>';">
                        <?php endif; ?>
                        <?php if ($dur !== '' && !$isShortPage): ?><span class="mv-dur"><?php echo $h($dur); ?></span><?php endif; ?>
                      </a>
                    </td>
                    <td>
                      <div class="cp-title"><?php echo $h($title !== '' ? $title : 'Untitled'); ?></div>
                      <div class="cp-id">ID: <?php echo $h($idStr); ?><?php echo $dateVal !== '' ? ' &bull; ' . $h($dateVal) : ''; ?></div>
                    </td>
                    <td>
                      <a class="cp-who" href="<?php echo $pageFile; ?>?uploader=<?php echo urlencode(mb_strtolower($who, 'UTF-8')); ?>">
                        <?php echo vs_vip_ring_auto_open($who, $pdo); ?>
                        <img src="<?php echo $h(avatar_url($who)); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo $h(vs_cp_initial($who)); ?>';">
                        <?php echo vs_vip_ring_auto_close($who, $pdo); ?>
                        <span><?php echo $h($who); ?><?php echo function_exists('vs_vip_badge') ? vs_vip_badge($who, $pdo, 14) : ''; ?></span>
                      </a>
                    </td>
                    <td class="text-end mv-num"><?php echo $h(vs_cp_short((int)($row['views'] ?? 0))); ?></td>
                    <td class="mv-num"><i class="bi bi-hand-thumbs-up-fill" aria-hidden="true"></i><?php echo $h(vs_cp_short($likes)); ?></td>
                    <td class="mv-num"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i><?php echo $h(vs_cp_short($cm)); ?></td>
                    <td><span class="mv-status <?php echo $stClass; ?>"><?php echo $h($stLabel); ?></span></td>
                    <td class="text-end cp-actions">
                      <a href="<?php echo $h($siteLinkBase . rawurlencode($idStr)); ?>" target="_blank" rel="noopener" class="mv-act view" title="View on the site" aria-label="View on the site"><i class="bi bi-eye-fill" aria-hidden="true"></i></a>
                      <a href="editvideos.php?editid=<?php echo urlencode($idStr); ?>" class="mv-act edit" title="Edit" aria-label="Edit"><i class="bi bi-pencil-fill" aria-hidden="true"></i></a>
                      <form action="code.php" method="POST" onsubmit="return confirm('Are you sure you want to delete this <?php echo $noun; ?>?');">
                        <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['vs_csrf']); ?>">
                        <input type="hidden" name="id" value="<?php echo $h($idStr); ?>">
                        <button type="submit" name="delete_video" value="1" class="mv-act del" title="Delete" aria-label="Delete"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                      </form>
                    </td>
                  </tr>
                  <?php
                      endforeach;
                  endif; ?>
                  <tr id="cpEmpty"<?php echo $total > 0 ? ' hidden' : ''; ?>><td colspan="10" class="text-center text-muted py-4">No <?php echo $nouns; ?> found. <a href="addvideos.php">Upload some!</a></td></tr>
                </tbody>
              </table>
            </div>
            <div class="cp-foot"><span id="cpInfo"></span><div class="cp-pager" id="cpPager"></div></div>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script>
  (function () {
    var body = document.getElementById('cpRows');
    var rows = Array.prototype.slice.call(body.querySelectorAll('tr.pg-row'));
    var info = document.getElementById('cpInfo'), pager = document.getElementById('cpPager'), empty = document.getElementById('cpEmpty');
    var search = document.getElementById('cpSearch'), per = 10, page = 1;
    var noun = <?php echo json_encode($nouns); ?>;
    var pageFile = <?php echo json_encode($pageFile); ?>;

    function render() {
      var term = (search.value || '').toLowerCase().trim();
      var match = rows.filter(function (r) { return r.dataset.search.indexOf(term) > -1; });
      var pages = Math.max(1, Math.ceil(match.length / per));
      if (page > pages) { page = pages; }
      var from = (page - 1) * per, shown = 0;
      rows.forEach(function (r) { r.hidden = true; });
      match.forEach(function (r, i) { if (i >= from && i < from + per) { r.hidden = false; shown++; } });
      empty.hidden = rows.length === 0 ? false : match.length !== 0;
      info.textContent = match.length ? 'Showing ' + (from + 1) + ' to ' + (from + shown) + ' of ' + match.length + ' results' : '';
      pager.innerHTML = '';
      if (pages > 1) {
        var add = function (label, p, on, off) {
          var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
          if (on) { b.className = 'on'; }
          if (off) { b.disabled = true; }
          b.addEventListener('click', function () { page = p; render(); });
          pager.appendChild(b);
        };
        add('\u2039', page - 1, false, page === 1);
        var start = Math.max(1, Math.min(page - 2, pages - 4)), end = Math.min(pages, start + 4);
        for (var p = start; p <= end; p++) { add(String(p), p, p === page, false); }
        add('Next \u203A', page + 1, false, page === pages);
      }
      updateBulk();
    }
    search.addEventListener('input', function () { page = 1; render(); });

    // uploader box: the page reloads with that uploader (the numbers above follow it)
    document.getElementById('cpUploader').addEventListener('change', function () {
      window.location.href = this.value ? pageFile + '?uploader=' + encodeURIComponent(this.value) : pageFile;
    });

    // selecting rows
    var all = document.getElementById('cpAll'), bulk = document.getElementById('cpBulk'), bulkN = document.getElementById('cpBulkN');
    function picks() { return Array.prototype.slice.call(body.querySelectorAll('.cp-pick:checked')); }
    function updateBulk() {
      var n = picks().length;
      bulk.hidden = n === 0;
      bulkN.textContent = n;
      var visible = rows.filter(function (r) { return !r.hidden; });
      all.checked = visible.length > 0 && visible.every(function (r) { return r.querySelector('.cp-pick').checked; });
    }
    all.addEventListener('change', function () {
      rows.forEach(function (r) { if (!r.hidden) { r.querySelector('.cp-pick').checked = all.checked; } });
      updateBulk();
    });
    body.addEventListener('change', function (e) { if (e.target.classList.contains('cp-pick')) { updateBulk(); } });

    // delete the selected ones, one after another, with the same request the single delete button sends
    bulk.addEventListener('click', function () {
      var chosen = picks();
      if (!chosen.length || !window.confirm('Delete ' + chosen.length + ' selected ' + noun + '? This cannot be undone.')) { return; }
      bulk.disabled = true;
      var csrf = <?php echo json_encode((string)$_SESSION['vs_csrf']); ?>;
      var i = 0;
      (function next() {
        if (i >= chosen.length) { window.location.reload(); return; }
        var fd = new FormData();
        fd.append('csrf', csrf);
        fd.append('id', chosen[i++].value);
        fd.append('delete_video', '1');
        fetch('code.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(next, next);
      })();
    });

    // "Copy ID" in the three-dots menu
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-copy]') : null;
      if (!b) { return; }
      var text = b.getAttribute('data-copy');
      if (navigator.clipboard) { navigator.clipboard.writeText(text); }
    });

    render();
  })();
  </script>
</body>
</html>