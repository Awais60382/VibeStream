<?php
/**
 * Shared admin list for videos.php and shorts.php.
 * Set $CONTENT_KIND to 'video' or 'short' before including this file.
 * Restyled to match the neon-red "Manage Videos / Manage Shorts" mockups.
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

// CSRF token for the delete buttons
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

// Keep only this page's kind
$rows = [];
$otherCount = 0;
foreach ($allContent as $row) {
    if (vs_is_short($row['type'] ?? 'video') === $isShortPage) {
        $rows[] = $row;
    } else {
        $otherCount++;
    }
}

// Totals for the summary cards
$sum = ['views' => 0, 'liked' => 0, 'likes' => 0, 'commented' => 0, 'comments' => 0];
foreach ($rows as $row) {
    $likes = max(0, (int)($row['likes'] ?? 0));
    $cm    = (int)($commentCounts[(string)$row['id']] ?? 0);
    $sum['views'] += (int)($row['views'] ?? 0);
    if ($likes > 0) { $sum['liked']++; $sum['likes'] += $likes; }
    if ($cm > 0)    { $sum['commented']++; $sum['comments'] += $cm; }
}
$total = count($rows);

// Extra data for the redesigned list
$statusCol = vs_pick($vCols, ['status', 'visibility', 'state']);

// Everyone who has uploaded this kind of content
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

/** Thumbnail for a row */
if (!function_exists('vs_cp_thumb')) {
    function vs_cp_thumb(array $row, bool $short): ?string
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
        foreach (['thumbnail_url', 'thumbnail', 'thumb_url', 'thumb', 'image_url', 'image_path', 'image', 'img', 'poster_url', 'poster', 'cover_image', 'cover', 'preview_image', 'preview'] as $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v === '') {
                continue;
            }
            if (preg_match('#^(https?:)?//|^(data|blob):#i', $v)) {
                return $v;
            }

            $root = realpath(__DIR__ . '/..');
            if ($root === false) {
                continue;
            }
            $v = str_replace('\\', '/', $v);
            if (preg_match('/^[A-Za-z]:\//', $v)) {
                $realPath = realpath($v);
                if ($realPath === false || stripos($realPath, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
                    continue;
                }
                $v = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
            } else {
                $v = preg_replace('#^(?:\.\.?/)+#', '', $v);
                $v = ltrim($v, '/');
                $realPath = realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $v));
                if ($realPath === false || stripos($realPath, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($realPath)) {
                    continue;
                }
                $v = str_replace('\\', '/', substr($realPath, strlen($root) + 1));
            }
            return '../' . implode('/', array_map('rawurlencode', explode('/', $v)));
        }
        return null;
    }
}
if (!function_exists('vs_cp_placeholder_thumb')) {
    function vs_cp_placeholder_thumb(bool $short): string
    {
        $w = $short ? 90 : 160;
        $hh = $short ? 120 : 90;
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $hh . '"><rect width="100%" height="100%" fill="#1a0c0d"/>'
            . '<path d="M' . ($w / 2 - 9) . ' ' . ($hh / 2 - 12) . 'l22 12-22 12z" fill="#ff343c"/></svg>'
        );
    }
}
if (!function_exists('vs_cp_generated_thumb')) {
    function vs_cp_generated_thumb(array $row): string
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['id'] ?? ''));
        if ($id === '') {
            return '';
        }

        $root = realpath(__DIR__ . '/..');
        if ($root === false) {
            return '';
        }

        $thumbDir = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'thumbnails';
        $thumbPath = $thumbDir . DIRECTORY_SEPARATOR . $id . '.jpg';
        $thumbUrl = '../uploads/thumbnails/' . rawurlencode($id) . '.jpg';
        if (is_file($thumbPath) && filesize($thumbPath) > 0) {
            return $thumbUrl;
        }

        $source = vs_cp_video_source($row);
        if ($source === '') {
            return '';
        }
        $sourcePath = preg_replace('#^../#', '', $source);
        $sourcePath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $sourcePath);
        if (!is_file($sourcePath)) {
            return '';
        }

        $ffmpeg = trim((string)getenv('FFMPEG_BINARY'));
        if ($ffmpeg === '' || !is_file($ffmpeg)) {
            $candidates = [
                'C:\\Users\\USER\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0.2-full_build\\bin\\ffmpeg.exe',
                'C:\\ffmpeg\\bin\\ffmpeg.exe',
                'C:\\xampppp\\ffmpeg.exe',
            ];
            $ffmpeg = '';
            foreach ($candidates as $candidate) {
                if (is_file($candidate)) {
                    $ffmpeg = $candidate;
                    break;
                }
            }
        }
        if ($ffmpeg === '' || !is_file($ffmpeg)) {
            return '';
        }

        if (!is_dir($thumbDir) && !mkdir($thumbDir, 0777, true)) {
            return '';
        }

        $cmd = escapeshellarg($ffmpeg)
            . ' -y -ss 00:00:01 -i ' . escapeshellarg($sourcePath)
            . ' -frames:v 1 -q:v 2 ' . escapeshellarg($thumbPath)
            . ' 2>NUL';
        exec($cmd, $output, $exitCode);
        return $exitCode === 0 && is_file($thumbPath) && filesize($thumbPath) > 0 ? $thumbUrl : '';
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
            if (preg_match('/^[A-Za-z]:\//', str_replace('\\', '/', $value))) {
                $absolutePath = realpath($value);
                if ($absolutePath === false || stripos($absolutePath, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($absolutePath)) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
            } else {
                $relative = preg_replace('#^(?:\.\.?/)+#', '', $relative);
            }
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
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#b50d16"/>'
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

// Numbers for the stat cards
if (!function_exists('vs_cp_short')) {
    function vs_cp_short(int $n): string
    {
        if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
        if ($n >= 10000)   { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
        return number_format($n);
    }
}
if (!function_exists('vs_cp_spark')) {
    function vs_cp_spark(array $v, string $gid): string
    {
        $w = 170; $hgt = 64;
        $v = array_values($v);
        if (count($v) < 2 || min($v) === max($v)) {
            $v = [2, 2.6, 3.4, 4.6, 5.4, 5, 4.4, 4.8, 6.4, 8.4, 10.6];
        }
        $n = count($v);
        $min = min($v); $max = max($v); $rng = max(1, $max - $min);
        $pts = []; $lx = 0; $ly = 0;
        foreach ($v as $i => $val) {
            $x = 2 + $i * ($w - 4) / ($n - 1);
            $y = ($hgt - 6) - (($val - $min) / $rng) * ($hgt - 14);
            $lx = round($x, 1); $ly = round($y, 1);
            $pts[] = $lx . ',' . $ly;
        }
        $line = implode(' ', $pts);
        $area = '2,' . $hgt . ' ' . $line . ' ' . $lx . ',' . $hgt;
        return '<svg class="mv-spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<defs>'
            . '<linearGradient id="' . $gid . 'a" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff2a32" stop-opacity=".55"/><stop offset="1" stop-color="#ff2a32" stop-opacity="0"/></linearGradient>'
            . '<linearGradient id="' . $gid . 'l" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#b50d16"/><stop offset="1" stop-color="#ff3b42"/></linearGradient>'
            . '</defs>'
            . '<polygon fill="url(#' . $gid . 'a)" points="' . $area . '"/>'
            . '<polyline fill="none" stroke="url(#' . $gid . 'l)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" points="' . $line . '"/></svg>';
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
        /* ================= Neon red / black — Manage Videos & Manage Shorts ================= */
        .mv-page{
          --mv-red:#ff1f27;--mv-red-2:#ff343c;--mv-red-deep:#a50a11;
          --mv-card:linear-gradient(145deg,rgba(24,6,7,.96),rgba(6,3,3,.98));
          --mv-line:rgba(255,42,50,.55);--mv-row:rgba(255,42,50,.16);
          --mv-text:#fff7f7;--mv-muted:#c9b4b4;--mv-field:#070303;--mv-hover:rgba(255,42,50,.10);
          color:var(--mv-text)
        }
        html[data-theme="light"] .mv-page{--mv-card:linear-gradient(145deg,#fff,#fff8f8);--mv-line:rgba(190,25,34,.30);--mv-row:rgba(190,25,34,.14);--mv-text:#241717;--mv-muted:#755f5f;--mv-field:#fff;--mv-hover:rgba(190,25,34,.06)}

        /* ---- hero ---- */
        .mv-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:22px 30px;border-radius:22px;border:1.5px solid var(--mv-line);position:relative;overflow:hidden;color:#fff;
          background:
            radial-gradient(ellipse at 100% 0,rgba(255,26,26,.40),transparent 46%),
            repeating-linear-gradient(125deg,transparent 0 70px,rgba(255,26,26,.07) 71px 96px),
            linear-gradient(110deg,#1b0607,#080303 62%,#170405);
          box-shadow:0 0 34px -8px rgba(255,26,26,.70),inset 0 0 70px -34px rgba(255,42,50,.55)}
        .mv-hero:before{content:"";position:absolute;inset:0;pointer-events:none;
          background:radial-gradient(520px 200px at 80% 15%,rgba(255,26,26,.26),transparent 70%),radial-gradient(420px 180px at 5% 100%,rgba(255,42,50,.20),transparent 70%)}
        .mv-hero>*{position:relative;z-index:1}
        .mv-hero-logo{width:96px;height:96px;flex:none}
        .mv-hero-logo svg{width:100%;height:100%;display:block;filter:drop-shadow(0 0 16px rgba(255,26,26,.85))}
        .mv-hero h1{font-size:clamp(1.9rem,3.6vw,2.9rem);font-weight:800;margin:0 0 4px;line-height:1.08;color:#fff;letter-spacing:-.01em}
        .mv-hero h1 span{color:var(--mv-red);text-shadow:0 0 22px rgba(255,26,26,.6)}
        .mv-hero p{margin:0;color:rgba(255,255,255,.92);font-size:1.05rem}
        .mv-hero p b{font-weight:500;color:var(--mv-red)}
        .mv-hero-art{position:absolute!important;left:50%;top:50%;width:190px;height:130px;transform:translate(-8%,-50%);opacity:.9;pointer-events:none;z-index:0!important}
        .mv-hero-art svg{width:100%;height:100%;display:block}
        .mv-hero-right{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .mv-total,.mv-btn{display:inline-flex;align-items:center;gap:9px;padding:11px 22px;border-radius:999px;border:1.5px solid rgba(255,42,50,.65);background:rgba(0,0,0,.62);color:#fff;font-weight:700;font-size:.95rem;text-decoration:none;white-space:nowrap;box-shadow:0 0 18px -5px rgba(255,26,26,.65),inset 0 0 14px -8px rgba(255,42,50,.9)}
        .mv-btn:hover{color:#fff;border-color:#ff5a60;box-shadow:0 0 22px -3px rgba(255,26,26,.8)}
        .mv-btn.primary{background:linear-gradient(180deg,#ff3a42,#c20d15);border-color:#ff6a70;box-shadow:0 0 24px -2px rgba(255,26,26,.75),inset 0 1px 0 rgba(255,255,255,.35)}

        /* ---- stat cards ---- */
        .mv-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px}
        .mv-stat{display:flex;align-items:center;gap:18px;padding:18px 22px;border-radius:20px;border:1.5px solid var(--mv-line);background:var(--mv-card);min-width:0;position:relative;overflow:hidden;
          box-shadow:0 0 26px -9px rgba(255,26,26,.65),inset 0 1px 0 rgba(255,255,255,.05),inset 0 -22px 40px -30px rgba(255,26,26,.55);transition:border-color .18s,box-shadow .18s}
        .mv-stat:hover{border-color:var(--mv-red-2);box-shadow:0 0 28px -4px rgba(255,26,26,.7),inset 0 -22px 40px -30px rgba(255,26,26,.6)}
        .mv-stat-ico{width:68px;height:68px;border-radius:50%;display:grid;place-items:center;font-size:30px;flex:none;color:#ff2a32;
          background:radial-gradient(circle at 50% 35%,rgba(255,42,50,.30),rgba(120,8,14,.35));box-shadow:0 0 22px -4px rgba(255,26,26,.8),inset 0 0 14px rgba(255,26,26,.25)}
        .mv-stat-ico i{filter:drop-shadow(0 0 6px rgba(255,26,26,.8))}
        .mv-stat-body{min-width:0;position:relative;z-index:1}
        .mv-stat-body small{display:block;color:var(--mv-text);font-size:1.02rem;font-weight:500}
        .mv-stat-num{display:flex;align-items:baseline;gap:14px;flex-wrap:wrap}
        .mv-stat-num strong{font-size:2.3rem;line-height:1.15;font-weight:800;color:var(--mv-text)}
        .mv-trend{font-weight:600;font-size:.95rem;color:#22c777;white-space:nowrap}
        .mv-trend.flat{color:var(--mv-muted);font-weight:500}
        .mv-sub{display:flex;align-items:flex-start;gap:8px;font-size:.95rem;color:var(--mv-text);margin-top:2px;line-height:1.25}
        .mv-sub i{color:var(--mv-red);font-size:1rem;filter:drop-shadow(0 0 5px rgba(255,26,26,.7))}
        .mv-spark{position:absolute;right:14px;bottom:10px;width:36%;max-width:170px;height:64px;filter:drop-shadow(0 0 6px rgba(255,26,26,.75));pointer-events:none}

        /* ---- filter bar ---- */
        .mv-filter{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:14px 20px;margin-top:16px;border-radius:20px;border:1.5px solid var(--mv-line);background:var(--mv-card);box-shadow:0 0 24px -10px rgba(255,26,26,.55),inset 0 1px 0 rgba(255,255,255,.04)}
        .mv-filter label.mv-lbl{font-weight:800;font-size:1.1rem;white-space:nowrap}
        .mv-select{min-width:210px;padding:11px 42px 11px 20px;border-radius:999px;border:1.5px solid var(--mv-line);font-weight:600;background:var(--mv-field) url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23ff343c' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'><path d='M6 9l6 6 6-6'/></svg>") no-repeat right 16px center;color:var(--mv-text);appearance:none;-webkit-appearance:none}
        .mv-toggle{display:flex;gap:12px;margin:0 8px}
        .mv-pill{display:inline-flex;align-items:center;gap:9px;padding:11px 24px;border-radius:999px;border:1.5px solid rgba(255,255,255,.18);background:var(--mv-field);color:var(--mv-text);font-weight:700;text-decoration:none;white-space:nowrap}
        .mv-pill:hover{color:var(--mv-text);border-color:var(--mv-red-2)}
        .mv-pill.on{background:linear-gradient(180deg,#ff3a42,#c20d15);border-color:#ff6a70;color:#fff;box-shadow:0 0 20px rgba(255,26,26,.65),inset 0 1px 0 rgba(255,255,255,.3)}
        .mv-search{position:relative;flex:1 1 260px;min-width:200px}
        .mv-search i{position:absolute;left:18px;top:50%;transform:translateY(-50%);color:var(--mv-text);font-size:1.05rem}
        .mv-search input{width:100%;padding:12px 18px 12px 48px;border-radius:14px;border:1.5px solid var(--mv-line);background:var(--mv-field);color:var(--mv-text)}
        .mv-search input::placeholder{color:var(--mv-muted)}
        .mv-search input:focus,.mv-select:focus{outline:none;border-color:var(--mv-red-2);box-shadow:0 0 0 1px var(--mv-red-2),0 0 16px rgba(255,26,26,.40)}
        .mv-bulk{padding:10px 18px;border-radius:999px;border:0;background:linear-gradient(180deg,#ff3a42,#c20d15);color:#fff;font-weight:700;box-shadow:0 0 16px rgba(255,26,26,.5)}

        /* ---- table card ---- */
        .mv-tablecard{margin-top:16px;padding:0;border-radius:20px;overflow:hidden;position:relative;border:1.5px solid var(--mv-line);
          background:
            linear-gradient(135deg,transparent 0 62%,rgba(255,26,26,.10) 62.2% 63.4%,transparent 63.6%),
            linear-gradient(225deg,rgba(255,26,26,.22),transparent 20%),
            linear-gradient(145deg,rgba(18,5,6,.97),rgba(4,2,2,.99));
          box-shadow:0 0 30px -10px rgba(255,26,26,.70),inset 0 0 60px -34px rgba(255,26,26,.65)}
        .mv-tablecard:before,.mv-tablecard:after{content:"";position:absolute;bottom:0;width:150px;height:110px;pointer-events:none;opacity:.8}
        .mv-tablecard:before{left:0;background:repeating-linear-gradient(135deg,transparent 0 16px,rgba(255,26,26,.18) 17px 19px);-webkit-mask:linear-gradient(45deg,#000,transparent 65%);mask:linear-gradient(45deg,#000,transparent 65%)}
        .mv-tablecard:after{right:0;background:repeating-linear-gradient(135deg,transparent 0 16px,rgba(255,26,26,.30) 17px 19px);-webkit-mask:linear-gradient(-45deg,#000,transparent 65%);mask:linear-gradient(-45deg,#000,transparent 65%)}
        .mv-tablecard .table-responsive{position:relative;z-index:1}
        .cp-table{--bs-table-bg:transparent;--bs-table-color:var(--mv-text);--bs-table-hover-bg:var(--mv-hover);--bs-table-hover-color:var(--mv-text);margin:0}
        .cp-table>:not(caption)>*>*{background-color:transparent;color:var(--mv-text);border-bottom:1px solid var(--mv-row);padding:10px 8px;vertical-align:middle}
        .cp-table thead th{font-size:.92rem;font-weight:800;text-transform:uppercase;letter-spacing:.01em;white-space:nowrap;padding-top:16px;padding-bottom:16px;border-bottom:1.5px solid var(--mv-line);background:rgba(0,0,0,.35)}
        .cp-table tbody tr:hover>*{background-color:var(--mv-hover)}
        .cp-table .cp-check{text-align:center}
        .cp-check-control{display:inline-flex;align-items:center;justify-content:center;min-width:30px;min-height:30px;cursor:pointer}
        .cp-table .form-check-input{display:inline-block;float:none;position:relative;z-index:2;pointer-events:auto;margin:0;background-color:#050202;background-image:none;border:2px solid rgba(255,255,255,.35);width:1.25rem;height:1.25rem;border-radius:6px;cursor:pointer}
        .cp-table .form-check-input:checked{background-color:var(--mv-red)!important;background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16' fill='none'%3e%3cpath stroke='%23fff' stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='m3 8 3 3 7-7'/%3e%3c/svg%3e")!important;background-position:center;background-repeat:no-repeat;background-size:14px;border-color:var(--mv-red-2)!important;box-shadow:0 0 8px rgba(255,42,50,.35)!important}
        .cp-table .form-check-input:focus-visible{outline:2px solid var(--mv-red-2);outline-offset:2px;box-shadow:0 0 0 3px rgba(255,42,50,.2)}
        .cp-thumb{width:100%;height:100%;object-fit:cover;background:#1a0c0d;display:block}
        .cp-thumb-link{position:relative;display:block;width:100%;max-width:128px;aspect-ratio:16/9;border-radius:10px;overflow:hidden;background:#1a0c0d;border:1px solid var(--mv-row)}
        .cp-thumb-link.short{width:44px;max-width:44px;aspect-ratio:9/16}
        .cp-thumb-link .cp-thumb{position:absolute;inset:0}
        .cp-thumb-link:after{content:"";position:absolute;left:50%;top:50%;width:0;height:0;border-top:7px solid transparent;border-bottom:7px solid transparent;border-left:11px solid #fff;transform:translate(-35%,-50%);filter:drop-shadow(0 1px 3px #000);opacity:0;transition:opacity .15s;pointer-events:none}
        .cp-thumb-link:hover:after{opacity:1}
        .cp-thumb-link:hover{box-shadow:0 0 0 2px var(--mv-red-2),0 0 16px rgba(255,26,26,.55)}
        .mv-dur{position:absolute;right:6px;bottom:6px;padding:1px 7px;border-radius:6px;background:rgba(0,0,0,.78);color:#fff;font-size:.74rem;font-weight:600;z-index:1}
        .cp-title{font-weight:600;line-height:1.3;overflow-wrap:anywhere}
        .cp-id{font-size:.76rem;color:var(--mv-muted);overflow-wrap:anywhere;margin-top:2px}
        .cp-who{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none;white-space:nowrap;min-width:0}
        .cp-who span{min-width:0;overflow:hidden;text-overflow:ellipsis}
        .cp-who img{flex:none;width:36px;height:36px;border-radius:50%;object-fit:cover}
        .cp-who:hover{color:inherit}
        .mv-num{white-space:nowrap}
        .mv-num i{color:var(--mv-red);margin-right:6px}
        .mv-status{display:inline-flex;align-items:center;gap:7px;padding:4px 14px;border-radius:999px;font-size:.82rem;font-weight:600;border:1px solid transparent}
        .mv-status:before{content:"";width:9px;height:9px;border-radius:50%;background:currentColor}
        .mv-status.ok{color:#22c777;background:rgba(34,199,119,.12);border-color:rgba(34,199,119,.55);box-shadow:0 0 12px -3px rgba(34,199,119,.6)}
        .mv-status.warn{color:#e69a1a;background:rgba(245,166,35,.14);border-color:rgba(245,166,35,.55)}
        .mv-status.off{color:var(--mv-muted);background:rgba(128,128,128,.14);border-color:rgba(128,128,128,.4)}
        .cp-actions{white-space:nowrap}
        .cp-actions form{display:inline;margin:0}
        .cp-table th.cp-actions-h{text-align:right}
        .mv-act{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:10px;border:1px solid transparent;color:#fff;text-decoration:none;font-size:15px;cursor:pointer;margin-left:4px;padding:0}
        .mv-act:hover{color:#fff;filter:brightness(1.18)}
        .mv-act.view{background:#a50a11;border-color:#ff343c;box-shadow:0 0 12px -3px rgba(255,52,60,.9)}
        .mv-act.edit{background:#b50d16;border-color:#ff4d52;box-shadow:0 0 12px -3px rgba(255,77,82,.9)}
        .mv-act.del{background:#e0242c;border-color:#ff5560;box-shadow:0 0 12px -3px rgba(255,85,96,.9)}
        .cp-foot{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid var(--mv-line)}
        .cp-foot span{font-size:.88rem;color:var(--mv-muted)}
        .cp-pager{display:flex;gap:6px;flex-wrap:wrap}
        .cp-pager button{min-width:36px;height:36px;padding:0 12px;border-radius:10px;border:1px solid transparent;background:transparent;color:var(--mv-text);font-weight:600}
        .cp-pager button:hover:not(:disabled){border-color:var(--mv-line)}
        .cp-pager button.on{background:linear-gradient(180deg,#ff3a42,#c20d15);border-color:#ff6a70;color:#fff;box-shadow:0 0 16px rgba(255,26,26,.7)}
        .cp-pager button:disabled{opacity:.4;cursor:default}

        /* ---- empty state ---- */
        .mv-empty{min-height:300px;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:34px 20px 40px;text-align:center}
        .mv-empty-art{width:230px;height:150px;margin-bottom:6px}
        .mv-empty-art svg{width:100%;height:100%;display:block;overflow:visible}
        .mv-empty strong{font-size:1.7rem;line-height:1.2;color:#fff;font-weight:800}
        .mv-empty strong span{color:var(--mv-red);text-shadow:0 0 16px rgba(255,26,26,.55)}
        .mv-empty p{margin:6px 0 16px;color:#e9dede;font-size:1.05rem}
        .mv-empty .mv-btn.primary{min-height:46px;padding:11px 30px}
        html[data-theme="light"] .mv-empty strong{color:#241717}
        html[data-theme="light"] .mv-empty p{color:#755f5f}

        html[data-theme="light"] .mv-hero{
          color:#241717;
          background:
            radial-gradient(ellipse at 100% 0,rgba(255,26,26,.12),transparent 46%),
            repeating-linear-gradient(125deg,transparent 0 70px,rgba(190,25,34,.035) 71px 96px),
            linear-gradient(110deg,#fff,#fff7f7 62%,#fff)
        }
        html[data-theme="light"] .mv-hero:before{background:radial-gradient(520px 200px at 80% 15%,rgba(255,26,26,.08),transparent 70%),radial-gradient(420px 180px at 5% 100%,rgba(255,42,50,.06),transparent 70%)}
        html[data-theme="light"] .mv-hero h1{color:#241717}
        html[data-theme="light"] .mv-hero p{color:#655353}
        html[data-theme="light"] .mv-total,
        html[data-theme="light"] .mv-btn:not(.primary){background:#fff;color:#402626;border-color:var(--mv-line);box-shadow:0 4px 14px rgba(70,20,20,.07)}
        html[data-theme="light"] .mv-btn:not(.primary):hover{color:#8f1018;border-color:#dc252e}
        html[data-theme="light"] .mv-stat,
        html[data-theme="light"] .mv-filter{box-shadow:0 5px 20px rgba(65,20,20,.07),inset 0 1px 0 rgba(255,255,255,.8)}
        html[data-theme="light"] .mv-stat-ico{background:radial-gradient(circle at 50% 35%,rgba(255,42,50,.13),rgba(190,25,34,.07));box-shadow:0 4px 15px rgba(190,25,34,.12),inset 0 0 12px rgba(255,26,26,.08)}
        html[data-theme="light"] .mv-select{border-color:var(--mv-line)}
        html[data-theme="light"] .mv-pill{border-color:var(--mv-line)}
        html[data-theme="light"] .mv-search input{border-color:var(--mv-line)}
        html[data-theme="light"] .mv-tablecard{
          background:
            linear-gradient(135deg,transparent 0 62%,rgba(190,25,34,.045) 62.2% 63.4%,transparent 63.6%),
            linear-gradient(225deg,rgba(255,26,26,.07),transparent 20%),
            linear-gradient(145deg,#fff,#fffafa)!important;
          box-shadow:0 7px 24px rgba(65,20,20,.08),inset 0 0 45px -34px rgba(190,25,34,.15)!important
        }
        html[data-theme="light"] .mv-tablecard:before{background:repeating-linear-gradient(135deg,transparent 0 16px,rgba(190,25,34,.08) 17px 19px)}
        html[data-theme="light"] .mv-tablecard:after{background:repeating-linear-gradient(135deg,transparent 0 16px,rgba(190,25,34,.12) 17px 19px)}
        html[data-theme="light"] .cp-table thead th{background:rgba(190,25,34,.045)}
        html[data-theme="light"] .cp-table .form-check-input:not(:checked){background-color:#fff;border-color:rgba(90,45,45,.4)}
        html[data-theme="light"] .cp-table .form-check-input:checked{background-color:var(--mv-red)!important;border-color:var(--mv-red-2)!important}
        html[data-theme="light"] .cp-thumb,
        html[data-theme="light"] .cp-thumb-link{background:#f4eeee}
        html[data-theme="light"] .cp-foot{border-color:var(--mv-line)}
        html[data-theme="light"] .cp-pager button{color:var(--mv-text)}

        @media (min-width:1280px){
          .cp-table{table-layout:fixed;width:100%}
          .cp-table td,.cp-table th{overflow:hidden;text-overflow:ellipsis}
          .cp-table td.cp-actions{overflow:visible}
        }
        @media (max-width:1279.98px){.cp-table{min-width:900px}}
        @media (max-width:1199.98px){.mv-hero-art{display:none}}
        @media (max-width:991.98px){.mv-stats{grid-template-columns:minmax(0,1fr)}.mv-toggle{margin:0}.mv-hero-right{margin-left:0}}
        @media (max-width:575.98px){.mv-hero{padding:18px}.mv-hero-logo{width:60px;height:60px}.mv-search{flex-basis:100%}.mv-stat-ico{width:54px;height:54px;font-size:24px}}
        @media (prefers-reduced-motion:reduce){.mv-stat{transition:none}}
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 mv-page<?php echo $isShortPage ? ' is-shorts' : ''; ?>">

          <!-- ═══════ HERO ═══════ -->
          <section class="mv-hero" aria-label="<?php echo $Nouns; ?>">
            <span class="mv-hero-logo">
              <?php if ($isShortPage): ?>
                <svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="mvLg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff5a5a"/><stop offset="1" stop-color="#c20d15"/></linearGradient></defs>
                  <g transform="translate(14, 10) scale(3)">
                    <path d="m18.931 9.99-1.441-.601 1.717-.913a4.48 4.48 0 0 0 1.874-6.078 4.506 4.506 0 0 0-6.09-1.874L4.792 5.929a4.504 4.504 0 0 0-2.402 4.193 4.521 4.521 0 0 0 2.666 3.904c.036.012 1.442.6 1.442.6l-1.706.901a4.51 4.51 0 0 0-2.369 3.967A4.528 4.528 0 0 0 6.93 24c.725 0 1.437-.174 2.08-.508l10.21-5.406a4.494 4.494 0 0 0 2.39-4.192 4.525 4.525 0 0 0-2.678-3.904Z" fill="url(#mvLg)"/>
                    <path d="M9.597 15.19V8.824l6.007 3.184z" fill="#fff"/>
                  </g></svg>
              <?php else: ?>
                <svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="mvLg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff4a4a"/><stop offset="1" stop-color="#c20d15"/></linearGradient></defs>
                  <path d="M20 12 L88 50 L20 88 Z" fill="url(#mvLg)" stroke="url(#mvLg)" stroke-width="10" stroke-linejoin="round"/>
                  <path d="M20 12 L88 50 L56 50 Z" fill="rgba(255,255,255,.16)" stroke="rgba(255,255,255,.16)" stroke-width="6" stroke-linejoin="round"/></svg>
              <?php endif; ?>
            </span>
            <div>
              <h1>Manage <span><?php echo $Nouns; ?></span></h1>
              <p>View and manage all <?php echo $nouns; ?> on <b><?php echo $h($mvSiteName); ?></b>.</p>
            </div>
            <span class="mv-hero-art" aria-hidden="true">
              <?php if ($isShortPage): ?>
                <svg viewBox="0 0 190 130">
                  <defs>
                    <linearGradient id="heroClapSlate" x1="0%" y1="0%" x2="0%" y2="100%">
                      <stop offset="0%" stop-color="#303036"/>
                      <stop offset="15%" stop-color="#202024"/>
                      <stop offset="100%" stop-color="#101012"/>
                    </linearGradient>
                    <linearGradient id="heroClapStick" x1="0%" y1="0%" x2="0%" y2="100%">
                      <stop offset="0%" stop-color="#38383e"/>
                      <stop offset="100%" stop-color="#141416"/>
                    </linearGradient>
                    <linearGradient id="heroClapPlay" x1="0%" y1="0%" x2="100%" y2="100%">
                      <stop offset="0%" stop-color="#ff4d55"/>
                      <stop offset="60%" stop-color="#ee0e1b"/>
                      <stop offset="100%" stop-color="#ad0610"/>
                    </linearGradient>
                    <clipPath id="heroTopClip"><rect x="0" y="0" width="105" height="18" rx="3.5"/></clipPath>
                    <clipPath id="heroBtmClip"><rect x="0" y="0" width="105" height="18" rx="3.5"/></clipPath>
                  </defs>
                  <g transform="translate(95, 68) rotate(-12)">
                    <rect x="-52" y="-14" width="105" height="70" rx="6" fill="#000" opacity="0.6" filter="drop-shadow(0 6px 14px rgba(0,0,0,0.8))"/>
                    <rect x="-52" y="-14" width="105" height="70" rx="5" fill="url(#heroClapSlate)" stroke="#44444c" stroke-width="1.2"/>
                    <!-- Lower stick with stripes -->
                    <g transform="translate(-52, -14)" clip-path="url(#heroBtmClip)">
                      <rect x="0" y="0" width="105" height="18" fill="url(#heroClapStick)"/>
                      <polygon points="15,0 29,0 14,18 0,18" fill="#e8eaed"/>
                      <polygon points="38,0 52,0 37,18 23,18" fill="#e8eaed"/>
                      <polygon points="61,0 75,0 60,18 46,18" fill="#e8eaed"/>
                      <polygon points="84,0 98,0 83,18 69,18" fill="#e8eaed"/>
                    </g>
                    <line x1="-52" y1="4" x2="53" y2="4" stroke="#0c0c0e" stroke-width="1.5"/>
                    <!-- Glowing Play Triangle -->
                    <path d="M-10,18 L16,31 L-10,44 Z" fill="url(#heroClapPlay)" filter="drop-shadow(0 0 10px rgba(255,26,38,0.95))"/>
                    <!-- Open Top Stick -->
                    <g transform="translate(-52, -14) rotate(-26)">
                      <rect x="0" y="0" width="105" height="18" rx="3.5" fill="#000" opacity="0.35" filter="drop-shadow(0 4px 8px rgba(0,0,0,0.5))"/>
                      <g clip-path="url(#heroTopClip)">
                        <rect x="0" y="0" width="105" height="18" fill="url(#heroClapStick)"/>
                        <polygon points="15,0 29,0 14,18 0,18" fill="#e8eaed"/>
                        <polygon points="38,0 52,0 37,18 23,18" fill="#e8eaed"/>
                        <polygon points="61,0 75,0 60,18 46,18" fill="#e8eaed"/>
                        <polygon points="84,0 98,0 83,18 69,18" fill="#e8eaed"/>
                        <line x1="0" y1="1" x2="105" y2="1" stroke="rgba(255,255,255,0.4)" stroke-width="1.2"/>
                      </g>
                      <rect x="0" y="0" width="105" height="18" rx="3.5" fill="none" stroke="#44444c" stroke-width="1.2"/>
                    </g>
                    <!-- Hinge Bracket -->
                    <rect x="-53" y="-16" width="15" height="23" rx="2.5" fill="#26262a" stroke="#484852" stroke-width="0.9"/>
                    <circle cx="-45.5" cy="-10" r="2.5" fill="#888892" stroke="#161618" stroke-width="0.7"/>
                    <circle cx="-45.5" cy="-0.5" r="2.5" fill="#888892" stroke="#161618" stroke-width="0.7"/>
                  </g>
                </svg>
              <?php else: ?>
                <svg viewBox="0 0 190 130"><g fill="none" stroke="#ff343c" stroke-width="4" stroke-linejoin="round" stroke-linecap="round" opacity=".55">
                  <path d="M40 104 24 40l40 30 31-48 31 48 40-30-16 64z"/><path d="M36 116h118" /><circle cx="24" cy="36" r="7"/><circle cx="95" cy="16" r="7"/><circle cx="166" cy="36" r="7"/></g></svg>
              <?php endif; ?>
            </span>
            <div class="mv-hero-right">
              <span class="mv-total"><i class="bi <?php echo $isShortPage ? 'bi-youtube' : 'bi-camera-video-fill'; ?>" aria-hidden="true"></i> Total <?php echo $Nouns; ?>: <?php echo number_format($total); ?></span>
              <a class="mv-btn" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
              <a class="mv-btn primary" href="addvideos.php"><i class="bi bi-plus-lg" aria-hidden="true"></i> Upload New</a>
            </div>
          </section>

          <?php if (isset($dbError)): ?>
            <div class="alert alert-danger mt-3"><?php echo $h($dbError); ?></div>
          <?php endif; ?>

          <!-- ═══════ STAT CARDS ═══════ -->
          <section class="mv-stats" aria-label="<?php echo $Nouns; ?> engagement">
            <article class="mv-stat">
              <span class="mv-stat-ico views"><i class="bi bi-eye-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Views</small>
                <div class="mv-stat-num">
                  <strong title="<?php echo number_format($sum['views']); ?>"><?php echo $h(vs_cp_short((int)$sum['views'])); ?></strong>
                  <?php if ($mvWeek['views'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['views'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                </div>
                <span class="mv-sub"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span><?php echo $h(vs_cp_short((int)$mvWeek['views'])); ?> from last week</span></span>
              </div>
              <?php echo vs_cp_spark($mvSeries['views'], 'mvSpV'); ?>
            </article>
            <article class="mv-stat">
              <span class="mv-stat-ico likes"><i class="bi bi-hand-thumbs-up-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Likes</small>
                <div class="mv-stat-num">
                  <strong title="<?php echo number_format($sum['likes']); ?>"><?php echo $h(vs_cp_short((int)$sum['likes'])); ?></strong>
                  <?php if ($mvWeek['likes'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['likes'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                </div>
                <span class="mv-sub"><i class="bi bi-heart-fill" aria-hidden="true"></i><span><?php echo number_format($sum['liked']); ?> <?php echo $isShortPage ? 'liked ' . $nouns : 'from last week'; ?></span></span>
              </div>
              <?php echo vs_cp_spark($mvSeries['likes'], 'mvSpL'); ?>
            </article>
            <article class="mv-stat">
              <span class="mv-stat-ico comments"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i></span>
              <div class="mv-stat-body">
                <small>Total Comments</small>
                <div class="mv-stat-num">
                  <strong title="<?php echo number_format($sum['comments']); ?>"><?php echo $h(vs_cp_short((int)$sum['comments'])); ?></strong>
                  <?php if ($mvWeek['comments'] > 0): ?><span class="mv-trend"><i class="bi bi-arrow-up" aria-hidden="true"></i> +<?php echo $h(vs_cp_short((int)$mvWeek['comments'])); ?></span><?php else: ?><span class="mv-trend flat">none this week</span><?php endif; ?>
                </div>
                <span class="mv-sub"><i class="bi bi-chat-square-text-fill" aria-hidden="true"></i><span><?php echo number_format($sum['commented']); ?> <?php echo $isShortPage ? 'commented ' . $nouns : 'from last week'; ?></span></span>
              </div>
              <?php echo vs_cp_spark($mvSeries['comments'], 'mvSpC'); ?>
            </article>
          </section>

          <!-- ═══════ FILTER BAR ═══════ -->
          <section class="mv-filter" aria-label="Filters">
            <label class="mv-lbl" for="cpUploader">Filter by Uploader:</label>
            <select class="mv-select" id="cpUploader" aria-label="Filter by uploader">
              <option value="">All Uploaders</option>
              <?php foreach ($uploaderOptions as $k => $n): ?>
                <option value="<?php echo $h($k); ?>"<?php echo mb_strtolower($uploader, 'UTF-8') === $k ? ' selected' : ''; ?>><?php echo $h($n); ?></option>
              <?php endforeach; ?>
            </select>
            <div class="mv-toggle">
              <a class="mv-pill<?php echo !$isShortPage ? ' on' : ''; ?>" href="videos.php<?php echo $uploader !== '' ? '?uploader=' . urlencode($uploader) : ''; ?>"><i class="bi bi-youtube" aria-hidden="true"></i> All Videos</a>
              <a class="mv-pill<?php echo $isShortPage ? ' on' : ''; ?>" href="shorts.php<?php echo $uploader !== '' ? '?uploader=' . urlencode($uploader) : ''; ?>"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> All Shorts</a>
            </div>
            <div class="mv-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="cpSearch" placeholder="Search <?php echo $nouns; ?>..." aria-label="Search <?php echo $nouns; ?>"></div>
            <button type="button" class="mv-bulk" id="cpBulk" hidden><i class="bi bi-trash" aria-hidden="true"></i> Delete selected (<span id="cpBulkN">0</span>)</button>
          </section>

          <!-- ═══════ TABLE ═══════ -->
          <section class="panel mv-tablecard" aria-label="<?php echo $Nouns; ?> list">
            <div class="table-responsive">
              <table class="table cp-table align-middle mb-0">
                <colgroup>
                  <col style="width:3.5%"><col style="width:4%"><col style="width:11%"><col style="width:20.5%"><col style="width:16%">
                  <col style="width:7%"><col style="width:7%"><col style="width:9%"><col style="width:10%"><col style="width:12%">
                </colgroup>
                <thead>
                  <tr>
                    <th scope="col" class="cp-check"><label class="cp-check-control"><input class="form-check-input" type="checkbox" id="cpAll" aria-label="Select all visible <?php echo $nouns; ?>"></label></th>
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
                          $generatedThumb = $thumb === null ? vs_cp_generated_thumb($row) : '';
                          $dur = trim((string)($row['duration'] ?? ''));
                  ?>
                  <tr class="pg-row" data-search="<?php echo $h(mb_strtolower($title . ' ' . $idStr . ' ' . $who, 'UTF-8')); ?>">
                    <td class="cp-check"><label class="cp-check-control"><input class="form-check-input cp-pick" type="checkbox" value="<?php echo $h($idStr); ?>" aria-label="Select <?php echo $h($title); ?>"></label></td>
                    <td class="text-muted"><?php echo $sno++; ?></td>
                    <td>
                      <a class="cp-thumb-link<?php echo $isShortPage ? ' short' : ''; ?>" href="<?php echo $h($siteLinkBase . rawurlencode($idStr)); ?>" title="Play <?php echo $h($title !== '' ? $title : 'Untitled'); ?>" aria-label="Play <?php echo $h($title !== '' ? $title : 'Untitled'); ?>" target="_blank" rel="noopener">
                        <?php if ($thumb !== null): ?>
                          <img class="cp-thumb" src="<?php echo $h($thumb); ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?php echo $h(vs_cp_placeholder_thumb($isShortPage)); ?>';">
                        <?php elseif ($generatedThumb !== ''): ?>
                          <img class="cp-thumb" src="<?php echo $h($generatedThumb); ?>" alt="" loading="lazy">
                        <?php else: ?>
                          <img class="cp-thumb" src="<?php echo $h(vs_cp_placeholder_thumb($isShortPage)); ?>" alt="" loading="lazy">
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

                  <!-- ═══════ EMPTY STATE ═══════ -->
                  <tr id="cpEmpty"<?php echo $total > 0 ? ' hidden' : ''; ?>>
                    <td colspan="10" class="p-0">
                      <?php if ($isShortPage): ?>
                        <div class="mv-empty">
                          <div class="mv-empty-art" aria-hidden="true">
                            <svg viewBox="0 0 240 160">
                              <defs>
                                <filter id="intenseNeon" x="-50%" y="-50%" width="200%" height="200%">
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="6" result="blur1"/>
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="blur2"/>
                                  <feMerge>
                                    <feMergeNode in="blur1"/>
                                    <feMergeNode in="blur2"/>
                                    <feMergeNode in="SourceGraphic"/>
                                  </feMerge>
                                </filter>
                                <filter id="haloFilter" x="-40%" y="-40%" width="180%" height="180%">
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="8" result="blur"/>
                                  <feMerge>
                                    <feMergeNode in="blur"/>
                                    <feMergeNode in="SourceGraphic"/>
                                  </feMerge>
                                </filter>
                                <radialGradient id="ringGlowAura" cx="50%" cy="50%" r="50%">
                                  <stop offset="0%" stop-color="#ff1420" stop-opacity="0.8"/>
                                  <stop offset="35%" stop-color="#ff1a26" stop-opacity="0.45"/>
                                  <stop offset="70%" stop-color="#990810" stop-opacity="0.15"/>
                                  <stop offset="100%" stop-color="#ff1420" stop-opacity="0"/>
                                </radialGradient>
                                <linearGradient id="sBodyGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                  <stop offset="0%" stop-color="#2a0609"/>
                                  <stop offset="30%" stop-color="#120204"/>
                                  <stop offset="70%" stop-color="#070102"/>
                                  <stop offset="100%" stop-color="#200406"/>
                                </linearGradient>
                                <linearGradient id="sRimGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                  <stop offset="0%" stop-color="#ff555e"/>
                                  <stop offset="50%" stop-color="#ff1a26"/>
                                  <stop offset="100%" stop-color="#c90813"/>
                                </linearGradient>
                                <linearGradient id="sPlayGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                  <stop offset="0%" stop-color="#ff4a52"/>
                                  <stop offset="55%" stop-color="#f00d1a"/>
                                  <stop offset="100%" stop-color="#ad0610"/>
                                </linearGradient>
                              </defs>

                              <!-- Background platform glow -->
                              <ellipse cx="120" cy="78" rx="104" ry="28" fill="url(#ringGlowAura)"/>

                              <!-- Concentric glowing platform rings -->
                              <g fill="none">
                                <ellipse cx="120" cy="78" rx="102" ry="26" stroke="#ff1f27" stroke-width="4.5" opacity="0.55" filter="url(#haloFilter)"/>
                                <ellipse cx="120" cy="78" rx="100" ry="25" stroke="#ff2831" stroke-width="2.2" filter="url(#intenseNeon)"/>
                                <ellipse cx="120" cy="78" rx="76" ry="19" stroke="#ff1a24" stroke-width="1.8" opacity="0.85" filter="drop-shadow(0 0 5px #ff1a24)"/>
                                <ellipse cx="120" cy="78" rx="50" ry="13" stroke="#ff2d36" stroke-width="1.4" opacity="0.95"/>
                              </g>

                              <!-- Sparkle Stars -->
                              <g fill="#ff3b44" filter="drop-shadow(0 0 4px #ff1f27)">
                                <path d="M52 38 Q52 44 58 44 Q52 44 52 50 Q52 44 46 44 Q52 44 52 38 Z"/>
                                <path d="M28 65 Q28 69 32 69 Q28 69 28 73 Q28 69 24 69 Q28 69 28 65 Z"/>
                                <path d="M172 23 Q172 28 177 28 Q172 28 172 33 Q172 28 167 28 Q172 28 172 23 Z"/>
                                <path d="M198 52 Q198 56 202 56 Q198 56 198 60 Q198 56 194 56 Q198 56 198 52 Z"/>
                                <circle cx="44" cy="76" r="1.4" opacity="0.8"/>
                                <circle cx="160" cy="46" r="1.4" opacity="0.8"/>
                                <circle cx="192" cy="72" r="1.4" opacity="0.8"/>
                              </g>

                              <!-- Shorts S Badge -->
                              <g transform="translate(120, 70)">
                                <g transform="scale(3.8) translate(-12, -12)">
                                  <path d="m18.931 9.99-1.441-.601 1.717-.913a4.48 4.48 0 0 0 1.874-6.078 4.506 4.506 0 0 0-6.09-1.874L4.792 5.929a4.504 4.504 0 0 0-2.402 4.193 4.521 4.521 0 0 0 2.666 3.904c.036.012 1.442.6 1.442.6l-1.706.901a4.51 4.51 0 0 0-2.369 3.967A4.528 4.528 0 0 0 6.93 24c.725 0 1.437-.174 2.08-.508l10.21-5.406a4.494 4.494 0 0 0 2.39-4.192 4.525 4.525 0 0 0-2.678-3.904Z"
                                    fill="url(#sBodyGrad)"
                                    stroke="url(#sRimGrad)"
                                    stroke-width="1.1"
                                    filter="drop-shadow(0 0 10px rgba(255,26,38,0.95))"/>

                                  <!-- Glass highlight arc -->
                                  <path d="M5.2 6.6 C6.8 3.5 10.4 1.4 14.2 2.2 C16.5 2.7 18.2 4.2 18.6 6.2"
                                    fill="none"
                                    stroke="rgba(255,255,255,0.48)"
                                    stroke-width="0.75"
                                    stroke-linecap="round"/>

                                  <!-- Bottom subtle highlight -->
                                  <path d="M5.4 17.8 C5.8 19.8 7.5 21.3 9.8 21.8 C13.6 22.6 17.2 20.5 18.8 17.4"
                                    fill="none"
                                    stroke="rgba(255,40,50,0.35)"
                                    stroke-width="0.6"
                                    stroke-linecap="round"/>

                                  <!-- Glowing Ruby Red Play Button -->
                                  <path d="M9.1 15.6V8.4l6.8 3.6z"
                                    fill="url(#sPlayGrad)"
                                    stroke="rgba(255,100,110,0.5)"
                                    stroke-width="0.3"
                                    filter="drop-shadow(0 0 5px rgba(255,30,40,0.95))"/>
                                </g>
                              </g>
                            </svg>
                          </div>
                          <strong>No <span>shorts</span> found!</strong>
                          <p>Upload some shorts to get started.</p>
                          <a class="mv-btn primary" href="addvideos.php"><i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i> Upload Short</a>
                        </div>
                      <?php else: ?>
                        <div class="mv-empty">
                          <div class="mv-empty-art" aria-hidden="true">
                            <svg viewBox="0 0 240 160">
                              <defs>
                                <filter id="vClapNeon" x="-50%" y="-50%" width="200%" height="200%">
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="6" result="blur1"/>
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="2.5" result="blur2"/>
                                  <feMerge>
                                    <feMergeNode in="blur1"/>
                                    <feMergeNode in="blur2"/>
                                    <feMergeNode in="SourceGraphic"/>
                                  </feMerge>
                                </filter>
                                <filter id="vClapHalo" x="-40%" y="-40%" width="180%" height="180%">
                                  <feGaussianBlur in="SourceGraphic" stdDeviation="8" result="blur"/>
                                  <feMerge>
                                    <feMergeNode in="blur"/>
                                    <feMergeNode in="SourceGraphic"/>
                                  </feMerge>
                                </filter>
                                <radialGradient id="vClapAura" cx="50%" cy="50%" r="50%">
                                  <stop offset="0%" stop-color="#ff1420" stop-opacity="0.8"/>
                                  <stop offset="35%" stop-color="#ff1a26" stop-opacity="0.45"/>
                                  <stop offset="70%" stop-color="#990810" stop-opacity="0.15"/>
                                  <stop offset="100%" stop-color="#ff1420" stop-opacity="0"/>
                                </radialGradient>
                                <linearGradient id="vSlateGrad" x1="0%" y1="0%" x2="0%" y2="100%">
                                  <stop offset="0%" stop-color="#303036"/>
                                  <stop offset="12%" stop-color="#222226"/>
                                  <stop offset="100%" stop-color="#101013"/>
                                </linearGradient>
                                <linearGradient id="vStickBg" x1="0%" y1="0%" x2="0%" y2="100%">
                                  <stop offset="0%" stop-color="#3a3a40"/>
                                  <stop offset="100%" stop-color="#161618"/>
                                </linearGradient>
                                <linearGradient id="vPlayGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                  <stop offset="0%" stop-color="#ff525a"/>
                                  <stop offset="55%" stop-color="#ee0e1b"/>
                                  <stop offset="100%" stop-color="#b00610"/>
                                </linearGradient>
                                <radialGradient id="vBadgeSphere" cx="34%" cy="30%" r="68%">
                                  <stop offset="0%" stop-color="#ff7a82"/>
                                  <stop offset="22%" stop-color="#ff333d"/>
                                  <stop offset="65%" stop-color="#d40813"/>
                                  <stop offset="100%" stop-color="#720308"/>
                                </radialGradient>
                                <clipPath id="vTopStickClip"><rect x="0" y="0" width="98" height="17" rx="3.5"/></clipPath>
                                <clipPath id="vBtmStickClip"><rect x="0" y="0" width="98" height="17" rx="3.5"/></clipPath>
                              </defs>

                              <!-- Platform Glow & Rings -->
                              <ellipse cx="120" cy="85" rx="106" ry="28" fill="url(#vClapAura)"/>
                              <g fill="none">
                                <ellipse cx="120" cy="85" rx="104" ry="26" stroke="#ff1f27" stroke-width="4.5" opacity="0.55" filter="url(#vClapHalo)"/>
                                <ellipse cx="120" cy="85" rx="102" ry="25" stroke="#ff2831" stroke-width="2.2" filter="url(#vClapNeon)"/>
                                <ellipse cx="120" cy="85" rx="78" ry="19" stroke="#ff1a24" stroke-width="1.8" opacity="0.85" filter="drop-shadow(0 0 5px #ff1a24)"/>
                                <ellipse cx="120" cy="85" rx="52" ry="13" stroke="#ff2d36" stroke-width="1.4" opacity="0.95"/>
                              </g>

                              <!-- Sparkle Stars -->
                              <g fill="#ff3b44" filter="drop-shadow(0 0 4px #ff1f27)">
                                <path d="M46 36 Q46 42 52 42 Q46 42 46 48 Q46 42 40 42 Q46 42 46 36 Z"/>
                                <path d="M24 68 Q24 72 28 72 Q24 72 24 76 Q24 72 20 72 Q24 72 24 68 Z"/>
                                <path d="M180 24 Q180 29 185 29 Q180 29 180 34 Q180 29 175 29 Q180 29 180 24 Z"/>
                                <path d="M210 50 Q210 54 214 54 Q210 54 210 58 Q210 54 206 54 Q210 54 210 50 Z"/>
                                <circle cx="42" cy="78" r="1.4" opacity="0.8"/>
                                <circle cx="168" cy="46" r="1.4" opacity="0.8"/>
                                <circle cx="200" cy="74" r="1.4" opacity="0.8"/>
                              </g>

                              <!-- 3D Clapperboard Group -->
                              <g transform="translate(112, 68) rotate(-8)">
                                <rect x="-49" y="-13" width="98" height="66" rx="5" fill="#000" opacity="0.65" filter="drop-shadow(0 8px 16px rgba(0,0,0,0.85))"/>
                                <rect x="-49" y="-13" width="98" height="66" rx="5" fill="url(#vSlateGrad)" stroke="#38383e" stroke-width="1.2"/>

                                <!-- Lower stick with diagonal stripes -->
                                <g transform="translate(-49, -13)" clip-path="url(#vBtmStickClip)">
                                  <rect x="0" y="0" width="98" height="17" fill="url(#vStickBg)"/>
                                  <polygon points="14,0 27,0 13,17 0,17" fill="#f0f2f5"/>
                                  <polygon points="36,0 49,0 35,17 22,17" fill="#f0f2f5"/>
                                  <polygon points="58,0 71,0 57,17 44,17" fill="#f0f2f5"/>
                                  <polygon points="80,0 93,0 79,17 66,17" fill="#f0f2f5"/>
                                </g>
                                <line x1="-49" y1="4" x2="49" y2="4" stroke="#0e0e10" stroke-width="1.4"/>

                                <!-- Glowing Red Play Button -->
                                <path d="M-10,17 L14,29 L-10,41 Z" fill="url(#vPlayGrad)" stroke="rgba(255,110,120,0.55)" stroke-width="0.8" filter="drop-shadow(0 0 10px rgba(255,26,38,0.95))"/>

                                <!-- Open Top Stick -->
                                <g transform="translate(-49, -13) rotate(-26)">
                                  <rect x="0" y="0" width="98" height="17" rx="3.5" fill="#000" opacity="0.35" filter="drop-shadow(0 4px 8px rgba(0,0,0,0.6))"/>
                                  <g clip-path="url(#vTopStickClip)">
                                    <rect x="0" y="0" width="98" height="17" fill="url(#vStickBg)"/>
                                    <polygon points="14,0 27,0 13,17 0,17" fill="#f0f2f5"/>
                                    <polygon points="36,0 49,0 35,17 22,17" fill="#f0f2f5"/>
                                    <polygon points="58,0 71,0 57,17 44,17" fill="#f0f2f5"/>
                                    <polygon points="80,0 93,0 79,17 66,17" fill="#f0f2f5"/>
                                    <line x1="0" y1="1" x2="98" y2="1" stroke="rgba(255,255,255,0.5)" stroke-width="1.2"/>
                                  </g>
                                  <rect x="0" y="0" width="98" height="17" rx="3.5" fill="none" stroke="#38383e" stroke-width="1.2"/>
                                </g>

                                <!-- Metal Hinge Bracket -->
                                <rect x="-50" y="-15" width="14" height="22" rx="2.5" fill="#28282c" stroke="#484850" stroke-width="0.9"/>
                                <circle cx="-43" cy="-9" r="2.4" fill="#90909a" stroke="#161618" stroke-width="0.7"/>
                                <circle cx="-43" cy="0" r="2.4" fill="#90909a" stroke="#161618" stroke-width="0.7"/>
                                <line x1="-44.2" y1="-9" x2="-41.8" y2="-9" stroke="#333" stroke-width="0.8"/>
                                <line x1="-44.2" y1="0" x2="-41.8" y2="0" stroke="#333" stroke-width="0.8"/>
                              </g>

                              <!-- 3D Glossy Red Exclamation Badge -->
                              <g transform="translate(164, 88)">
                                <circle cx="0" cy="3" r="21" fill="#000" opacity="0.6" filter="drop-shadow(0 4px 10px rgba(0,0,0,0.7))"/>
                                <circle cx="0" cy="0" r="20.5" fill="none" stroke="#ff2632" stroke-width="2.2" opacity="0.75" filter="drop-shadow(0 0 10px #ff1a26)"/>
                                <circle cx="0" cy="0" r="20" fill="url(#vBadgeSphere)"/>
                                <ellipse cx="-6" cy="-8" rx="10" ry="5.5" transform="rotate(-30, -6, -8)" fill="rgba(255,255,255,0.48)"/>
                                <g fill="#ffffff" filter="drop-shadow(0 1.5px 3px rgba(0,0,0,0.45))">
                                  <path d="M-2.5,-10 C-2.5,-11.4 -1.4,-12.2 0,-12.2 C1.4,-12.2 2.5,-11.4 2.5,-10 L1.8,1.2 C1.8,2.3 0.9,3.2 0,3.2 C-0.9,3.2 -1.8,2.3 -1.8,1.2 Z"/>
                                  <circle cx="0" cy="8.2" r="2.6"/>
                                </g>
                              </g>
                            </svg>
                          </div>
                          <strong>No videos <span>found!</span></strong>
                          <p>Upload some videos to get started.</p>
                          <a class="mv-btn primary" href="addvideos.php"><i class="bi bi-cloud-arrow-up-fill" aria-hidden="true"></i> Upload Video</a>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="cp-foot"><span id="cpInfo"></span><div class="cp-pager" id="cpPager"></div></div>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a></span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script src="../video-thumbnails.js?v=1" defer></script>
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

    // uploader filter
    document.getElementById('cpUploader').addEventListener('change', function () {
      window.location.href = this.value ? pageFile + '?uploader=' + encodeURIComponent(this.value) : pageFile;
    });

    // row selection
    var all = document.getElementById('cpAll'), bulk = document.getElementById('cpBulk'), bulkN = document.getElementById('cpBulkN');
    function picks() { return Array.prototype.slice.call(body.querySelectorAll('.cp-pick:checked')); }
    function updateBulk() {
      var n = picks().length;
      bulk.hidden = n === 0;
      bulkN.textContent = n;
      var visible = rows.filter(function (r) { return !r.hidden; });
      all.checked = visible.length > 0 && visible.every(function (r) { return r.querySelector('.cp-pick').checked; });
      all.indeterminate = visible.some(function (r) { return r.querySelector('.cp-pick').checked; }) && !all.checked;
    }
    all.addEventListener('change', function () {
      rows.forEach(function (r) { if (!r.hidden) { r.querySelector('.cp-pick').checked = all.checked; } });
      updateBulk();
    });
    body.addEventListener('change', function (e) { if (e.target.classList.contains('cp-pick')) { updateBulk(); } });

    // bulk delete
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

    // copy ID
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
