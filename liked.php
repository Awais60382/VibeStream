<?php
/**
 * Liked videos page (opens from the "Liked videos" card on playlists.php and from the sidebar).
 * Put this file in the SITE ROOT. Needs liked-store.php (saves each member's likes in the database).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/liked-store.php';
require_once __DIR__ . '/vip-badge.php';

if (!function_exists('extract_youtube_id')) {
    function extract_youtube_id($raw, $allowBare = false) {
        $raw = trim((string)$raw);
        if ($raw === '') return null;
        if ($allowBare && preg_match('/^[A-Za-z0-9_-]{11}$/', $raw)) return $raw;
        if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $raw, $m)) return $m[1];
        if (preg_match('/[?&]v=([A-Za-z0-9_-]{11})/', $raw, $m)) return $m[1];
        return null;
    }
}
if (!function_exists('find_source')) {
    function find_source(array $row, $baseDir = __DIR__) {
        $priority = ['video_url','video_path','video_file','video','url','file_path','filepath','file','filename','source','src','link','media_url','youtube_url','youtube_id','yt_id','video_link','embed_url','embed'];
        foreach ($priority as $col) {
            $v = trim((string)($row[$col] ?? ''));
            if ($v === '') continue;
            if (extract_youtube_id($v, true)) return $v;
            if (preg_match('#^(https?:)?//#i', $v)) return $v;
            $v = ltrim($v, '/');
            foreach ([$v, "uploads/$v", "videos/$v", "uploads/videos/$v", "media/$v"] as $p) {
                if (is_file($baseDir . '/' . $p)) return $p;
            }
            if (preg_match('/\.(mp4|webm|ogg|ogv|m4v|mov)(\?.*)?$/i', $v)) return $v;
        }
        return '';
    }
}
if (!function_exists('thumb_for')) {
    function thumb_for(array $row, $src, $realYt) {
        if ($realYt) return "https://i.ytimg.com/vi/{$realYt}/hqdefault.jpg";
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $c) {
            if (!empty($row[$c])) return $row[$c];
        }
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#1e1e24"/><text x="50%" y="50%" fill="#6f6f7a" font-family="Arial" font-size="16" text-anchor="middle" dy=".3em">No preview</text></svg>');
    }
}

$username = trim((string)($_SESSION['username'] ?? ''));
$isMember = vs_likes_is_member();
$likedVideos = [];
$likedVideoIds = [];
$pageError = '';

try {
    vs_likes_session_sync($conn);
    $likedVideoIds = array_slice(vs_likes_ids($conn), 0, 500);   // newest like first
    if ($likedVideoIds) {
        $placeholders = implode(',', array_fill(0, count($likedVideoIds), '?'));
        $query = $conn->prepare('SELECT * FROM `videos` WHERE CAST(`id` AS CHAR) IN (' . $placeholders . ')');
        $query->bind_param(str_repeat('s', count($likedVideoIds)), ...$likedVideoIds);
        $query->execute();
        $likedVideos = $query->get_result()->fetch_all(MYSQLI_ASSOC);
        $query->close();
        $order = array_flip($likedVideoIds);
        usort($likedVideos, static fn(array $a, array $b): int =>
            ($order[(string)$a['id']] ?? PHP_INT_MAX) <=> ($order[(string)$b['id']] ?? PHP_INT_MAX)
        );
    }
} catch (Throwable $error) {
    error_log('liked.php: ' . $error->getMessage());
    $pageError = 'Your liked videos could not be loaded right now. Please try again.';
}

$likedCount = count($likedVideos);
$isShort = static fn(array $v): bool => in_array(strtolower((string)($v['type'] ?? '')), ['short', 'shorts'], true);
$hrefFor = static fn(array $v): string => ($isShort($v) ? 'shorts.php?id=' : 'watch.php?id=') . rawurlencode((string)$v['id']);
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$csrf = $_SESSION['liked_csrf'] ?? '';
if (!is_string($csrf) || $csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['liked_csrf'] = $csrf;
}

/** Removes a saved like and decrements its video's public like count. */
if (!function_exists('vs_lk_unlike')) {
    function vs_lk_unlike(mysqli $conn, string $id): bool
    {
        $id = trim($id);
        if ($id === '' || strlen($id) > 100) {
            return false;
        }

        $wasLiked = vs_likes_has($conn, $id);
        if (!$wasLiked) {
            vs_likes_set($conn, $id, false);
            return !vs_likes_has($conn, $id);
        }

        $conn->begin_transaction();
        try {
            $update = $conn->prepare(
                'UPDATE `videos` SET `likes` = GREATEST(COALESCE(`likes`, 0) - 1, 0) WHERE `id` = ?'
            );
            if (!$update) {
                throw new RuntimeException('Could not prepare the video like count update.');
            }
            $update->bind_param('s', $id);
            if (!$update->execute()) {
                $update->close();
                throw new RuntimeException('Could not update the video like count.');
            }
            $update->close();

            vs_likes_set($conn, $id, false);
            $conn->commit();
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }

        return !vs_likes_has($conn, $id);
    }
}
if (!function_exists('vs_lk_views')) {
    function vs_lk_views($n): string
    {
        $n = (int)$n;
        foreach ([[1000000000, 'B'], [1000000, 'M'], [1000, 'K']] as [$div, $unit]) {
            if ($n >= $div) {
                $v = $n / $div;
                $txt = $v >= 10 ? (string)round($v) : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
                return $txt . $unit;
            }
        }
        return (string)$n;
    }
}
if (!function_exists('vs_lk_ago')) {
    function vs_lk_ago($raw): string
    {
        $ts = strtotime((string)$raw);
        if (!$ts) {
            return '';
        }
        $diff = max(0, time() - $ts);
        foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $secs]) {
            if ($diff >= $secs) {
                $n = (int)floor($diff / $secs);
                return $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';
            }
        }
        return 'Just now';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['err', 'That request was not allowed.'];
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $action = (string)($_POST['action'] ?? '');
        try {
            if ($action === 'unlike') {
                $flash = vs_lk_unlike($conn, trim((string)($_POST['video_id'] ?? '')))
                    ? ['ok', 'Removed from your liked videos.']
                    : ['err', 'Could not remove that like yet. liked-store.php needs an unlike function.'];
            } elseif ($action === 'clear') {
                $removed = 0;
                foreach ($likedVideoIds as $likedId) {
                    if (vs_lk_unlike($conn, (string)$likedId)) {
                        $removed++;
                    }
                }
                $flash = ($removed > 0 || !$likedVideoIds)
                    ? ['ok', 'All likes were removed.']
                    : ['err', 'Could not remove your likes yet. liked-store.php needs an unlike function.'];
            }
        } catch (Throwable $e) {
            error_log('liked.php action failed: ' . $e->getMessage());
            $flash = ['err', 'Could not update your liked videos. Please try again.'];
        }
    }
    $_SESSION['liked_flash'] = $flash;
    header('Location: liked.php');
    exit;
}
$flash = $_SESSION['liked_flash'] ?? null;
unset($_SESSION['liked_flash']);

$extraCss = <<<'CSS'
.lk-page { width: 100%; max-width: 1600px; margin: 0 auto; padding: 16px clamp(14px, 2vw, 28px) 64px; }

/* Banner */
.lk-banner {
    position: relative; overflow: hidden; display: flex; align-items: flex-start; gap: 34px;
    min-height: 270px; padding: 62px 48px 96px; border-radius: 16px; border: 1px solid rgba(138,92,255,.5);
    background:
        radial-gradient(600px 240px at 78% 40%, rgba(124,58,237,.55), transparent 70%),
        radial-gradient(420px 200px at 10% 20%, rgba(0,120,255,.25), transparent 70%),
        linear-gradient(100deg, #04041a 0%, #0b0733 45%, #170a55 75%, #0a0630 100%);
    box-shadow: 0 0 34px -10px rgba(138,92,255,.65);
}
.lk-waves { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.lk-banner-icon, .lk-banner-text { position: relative; z-index: 1; }
.lk-banner-icon svg { display: block; width: 96px; height: 96px; margin-top: 8px; filter: drop-shadow(0 0 14px rgba(255,46,200,.8)); }
.lk-banner h1 { margin: 0; font-size: clamp(36px, 5vw, 64px); line-height: 1.05; font-weight: 800; letter-spacing: -0.02em; color: #fff; }
.lk-banner h1 span { background: linear-gradient(90deg, #ff3ec8, #b84dff); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 14px rgba(255,62,200,.45)); }
.lk-banner p { margin: 8px 0 0; font-size: 20px; color: #e6e8ff; }
.lk-heart-art { position: absolute; right: 2%; top: 4px; width: min(46%, 640px); height: 100%; pointer-events: none; }

/* Stats card */
.lk-stats {
    position: relative; z-index: 2; display: flex; flex-wrap: wrap; align-items: center; gap: 20px;
    margin: -72px 14px 22px; padding: 18px 24px; border-radius: 16px; border: 1px solid rgba(138,92,255,.4);
    background: var(--surface); background: color-mix(in srgb, var(--surface) 92%, transparent);
    backdrop-filter: blur(8px); box-shadow: 0 0 26px -12px rgba(138,92,255,.7);
}
.lk-stats-ic { display: grid; place-items: center; width: 84px; height: 84px; flex: 0 0 84px; border-radius: 50%; border: 2px solid #c43bff; color: #ff3ec8; box-shadow: 0 0 20px rgba(196,59,255,.55), inset 0 0 14px rgba(196,59,255,.3); }
.lk-stats-ic svg { width: 42px; height: 42px; }
.lk-stats-text { flex: 1 1 220px; min-width: 0; }
.lk-stats-text small { display: block; font-size: 18px; color: var(--text); }
.lk-count { display: block; margin-top: 2px; font-size: 38px; font-weight: 800; line-height: 1.1; }
.lk-count span { background: linear-gradient(90deg, #ff3ec8, #b84dff); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; }
.lk-stats form { margin: 0; }
.lk-pill { display: inline-flex; align-items: center; gap: 10px; height: 50px; padding: 0 26px; border-radius: 999px; border: 1px solid rgba(138,92,255,.5); background: var(--surface-2); color: var(--text); font-size: 15px; font-weight: 600; cursor: pointer; transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
.lk-pill svg { width: 19px; height: 19px; }
.lk-pill:hover { transform: translateY(-1px); border-color: #ff2e93; box-shadow: 0 0 18px -2px rgba(255,46,147,.6); }

/* Toolbar */
.lk-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; margin: 0 14px 18px; }
.lk-chips { display: flex; flex-wrap: wrap; gap: 12px; }
.lk-chip { display: inline-flex; align-items: center; gap: 9px; height: 46px; padding: 0 24px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
.lk-chip svg { width: 20px; height: 20px; flex: 0 0 20px; }
.lk-chip:hover { transform: translateY(-1px); }
.lk-chip[aria-current="page"] { background: linear-gradient(135deg, #8a5cff, #5b3fe8); color: #fff; border-color: transparent; box-shadow: 0 0 22px rgba(138,92,255,.65); }
.lk-search { display: flex; align-items: center; gap: 10px; width: min(100%, 330px); height: 46px; padding: 0 20px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); }
.lk-search svg { width: 20px; height: 20px; flex: 0 0 20px; color: var(--muted); }
.lk-search input { width: 100%; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font-size: 15px; }
.lk-search input::placeholder { color: var(--muted); }

/* Grid + cards */
.lk-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin: 0 14px; }
.lk-card { position: relative; display: grid; grid-template-columns: minmax(130px, 190px) minmax(0, 1fr); gap: 16px; align-items: start; min-height: 144px; padding: 8px; border-radius: 14px; border: 1px solid var(--border); background: var(--surface); }
.lk-card[hidden], .lk-empty[hidden], .lk-pager[hidden] { display: none !important; }
.lk-thumb { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 10px; background: var(--surface-2); align-self: center; }
.lk-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; }
.lk-duration { position: absolute; right: 6px; bottom: 6px; padding: 2px 7px; border-radius: 6px; background: rgba(0,0,0,.8); color: #fff; font-size: 13px; font-weight: 700; }
.lk-short-tag { position: absolute; left: 6px; top: 6px; display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px 2px 6px; border-radius: 999px; background: rgba(0,0,0,.72); color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .04em; }
.lk-short-tag svg { width: 12px; height: 12px; color: #ff2e93; }
.lk-info { min-width: 0; padding: 8px 34px 34px 0; }
.lk-title { display: -webkit-box; overflow: hidden; margin: 0 0 8px; color: var(--text); font-size: 16px; font-weight: 700; line-height: 1.3; text-decoration: none; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.lk-title:hover { text-decoration: underline; }
.lk-channel { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; color: var(--muted); font-size: 15px; }
.lk-meta { margin: 0; color: var(--muted); font-size: 13px; }
.lk-menu { position: absolute; top: 8px; right: 8px; }
.lk-menu summary { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; color: var(--text); cursor: pointer; list-style: none; }
.lk-menu summary::-webkit-details-marker { display: none; }
.lk-menu summary:hover, .lk-menu[open] summary { background: rgba(138,92,255,.2); }
.lk-menu summary svg { width: 20px; height: 20px; }
.lk-menu-list { position: absolute; right: 0; top: 38px; z-index: 30; min-width: 220px; padding: 8px 0; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
.lk-menu-list form { margin: 0; }
.lk-menu-list a, .lk-menu-list button { display: flex; align-items: center; gap: 14px; width: 100%; padding: 10px 16px; border: 0; background: transparent; color: var(--text); font: inherit; font-size: 14px; text-align: left; text-decoration: none; cursor: pointer; }
.lk-menu-list a:hover, .lk-menu-list button:hover { background: var(--surface-2); }
.lk-menu-list svg { width: 20px; height: 20px; flex: 0 0 20px; }
.lk-heart-form { position: absolute; right: 12px; bottom: 10px; margin: 0; }
.lk-heart { display: grid; place-items: center; width: 36px; height: 36px; border: 0; border-radius: 50%; background: transparent; color: #8a3cff; cursor: pointer; transition: transform .15s ease, filter .15s ease; }
.lk-heart svg { width: 26px; height: 26px; }
.lk-heart:hover { transform: scale(1.15); filter: drop-shadow(0 0 8px rgba(138,60,255,.9)); }

/* Pagination */
.lk-pager { display: flex; justify-content: center; margin: 34px 0 0; }
.lk-pager-inner { display: inline-flex; align-items: center; gap: 2px; padding: 4px; border-radius: 16px; border: 1px solid var(--border); background: var(--surface-2); }
.lk-pager button { display: grid; place-items: center; min-width: 46px; height: 46px; padding: 0 10px; border: 0; border-radius: 12px; background: transparent; color: var(--text); font: inherit; font-size: 16px; font-weight: 600; cursor: pointer; }
.lk-pager button:hover:not(:disabled) { background: rgba(138,92,255,.2); }
.lk-pager button:disabled { opacity: .35; cursor: default; }
.lk-pager button[aria-current="page"] { background: linear-gradient(135deg, #8a5cff, #5b3fe8); color: #fff; box-shadow: 0 0 18px rgba(138,92,255,.7); }
.lk-pager svg { width: 18px; height: 18px; }
.lk-pager .gap { min-width: 24px; text-align: center; color: var(--muted); }

/* Messages */
.lk-flash, .lk-notice { margin: 0 14px 18px; padding: 14px 20px; border-radius: 12px; background: var(--surface-2); color: var(--text); }
.lk-flash.ok { background: rgba(45,180,120,.14); color: #278552; }
html[data-theme="dark"] .lk-flash.ok { color: #8de2b4; }
.lk-flash.err { background: rgba(220,60,60,.14); color: #ff9b9b; }
.lk-error { margin: 0 14px 18px; padding: 14px 16px; border-radius: 12px; background: var(--surface-2); }
.lk-error p { margin: 8px 0 0; color: var(--muted); }
.lk-empty { margin: 0 14px; padding: 46px 20px; border: 1px dashed var(--border); border-radius: 18px; text-align: center; color: var(--muted); }
.lk-empty h2 { margin: 0 0 8px; color: var(--text); }

@media (max-width: 1500px) { .lk-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 1000px) { .lk-heart-art { opacity: .55; width: 60%; } }
@media (max-width: 860px) { .lk-grid { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 700px) {
    .lk-banner { gap: 16px; min-height: 220px; padding: 34px 20px 88px; }
    .lk-banner-icon svg { width: 54px; height: 54px; }
    .lk-banner p { font-size: 15px; }
    .lk-heart-art { display: none; }
    .lk-stats { margin: -64px 4px 18px; padding: 14px 16px; }
    .lk-stats-ic { width: 60px; height: 60px; flex-basis: 60px; }
    .lk-stats-ic svg { width: 30px; height: 30px; }
    .lk-count { font-size: 28px; }
    .lk-toolbar, .lk-grid, .lk-flash, .lk-notice, .lk-error, .lk-empty { margin-inline: 4px; }
    .lk-search { width: 100%; }
    .lk-chip { padding: 0 16px; font-size: 14px; height: 40px; }
    .lk-card { grid-template-columns: 120px minmax(0, 1fr); gap: 12px; }
}

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


/* ---- liked videos page ---- */
html:not([data-theme="light"]) body .lk-chip { background: rgba(23,28,66,.7); border-color: rgba(138,92,255,.35); }
html:not([data-theme="light"]) body .lk-chip:hover { border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .lk-chip[aria-current="page"] { border-color: transparent; }
html:not([data-theme="light"]) body .lk-search { background: rgba(10,13,31,.9); border-color: rgba(138,92,255,.55); box-shadow: 0 0 10px -2px rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .lk-search:focus-within { border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 18px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .lk-pill { background: rgba(23,28,66,.6); }
html:not([data-theme="light"]) body .lk-card { background: linear-gradient(180deg, rgba(14,18,44,.88), rgba(8,10,28,.94)); border-color: rgba(138,92,255,.25); transition: border-color .2s ease, box-shadow .2s ease; }
html:not([data-theme="light"]) body .lk-card:hover { border-color: rgba(0,229,255,.6); box-shadow: 0 0 24px -8px rgba(0,229,255,.55); }
html:not([data-theme="light"]) body .lk-thumb { border: 1px solid rgba(138,92,255,.3); }
html:not([data-theme="light"]) body .lk-title:hover { color: #00e5ff; text-decoration: none; text-shadow: 0 0 10px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .lk-menu-list { background: rgba(10,13,31,.96); border-color: rgba(0,229,255,.5); box-shadow: 0 0 30px -4px rgba(0,229,255,.5), 0 0 60px -16px rgba(138,92,255,.6); }
html:not([data-theme="light"]) body .lk-menu-list a:hover, html:not([data-theme="light"]) body .lk-menu-list button:hover { background: rgba(0,229,255,.12); }
html:not([data-theme="light"]) body .lk-pager-inner { background: rgba(10,13,31,.9); border-color: rgba(138,92,255,.4); }
html:not([data-theme="light"]) body .lk-empty { background: rgba(10,13,31,.88); border: 1px dashed rgba(0,229,255,.5); box-shadow: 0 0 24px -8px rgba(0,229,255,.4); }

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


/* ---- liked videos page ---- */
html[data-theme="light"] body .lk-chip { background: #ffffff; border-color: rgba(124,77,255,.35); }
html[data-theme="light"] body .lk-chip:hover { border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
html[data-theme="light"] body .lk-chip[aria-current="page"] { background: linear-gradient(135deg, #7c4dff, #5b3fe8); border-color: transparent; }
html[data-theme="light"] body .lk-search { background: #ffffff; border-color: rgba(124,77,255,.5); }
html[data-theme="light"] body .lk-search:focus-within { border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 16px rgba(0,184,230,.45); }
html[data-theme="light"] body .lk-pill { background: #ffffff; }
html[data-theme="light"] body .lk-card { background: #ffffff; border-color: rgba(124,77,255,.3); transition: border-color .2s ease, box-shadow .2s ease; }
html[data-theme="light"] body .lk-card:hover { border-color: #00b8e6; box-shadow: 0 0 22px -8px rgba(0,184,230,.55); }
html[data-theme="light"] body .lk-thumb { border: 1px solid rgba(124,77,255,.3); }
html[data-theme="light"] body .lk-title:hover { color: #0086b3; text-decoration: none; }
html[data-theme="light"] body .lk-menu-list { background: #ffffff; border-color: rgba(0,184,230,.5); box-shadow: 0 0 30px -4px rgba(0,184,230,.5); }
html[data-theme="light"] body .lk-menu-list a:hover, html[data-theme="light"] body .lk-menu-list button:hover { background: rgba(0,184,230,.12); }
html[data-theme="light"] body .lk-pager-inner { background: #ffffff; border-color: rgba(124,77,255,.4); }
html[data-theme="light"] body .lk-empty { background: #ffffff; border: 1px dashed rgba(0,184,230,.5); }

CSS;

vs_page_start('Liked videos', 'liked', $extraCss);

$icoTrash = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>';
$icoBolt = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2 4 14h6.5L9.5 22 20 9.5h-6.8z"/></svg>';
$heartPath = 'M12 21s-7.5-4.6-9.3-9.2C1.3 8.3 3.4 5 6.7 5c2 0 3.6 1.1 5.3 3 1.7-1.9 3.3-3 5.3-3 3.3 0 5.4 3.3 4 6.8C19.5 16.4 12 21 12 21z';
?>
<main class="lk-page">

  <section class="lk-banner">
    <svg class="lk-waves" viewBox="0 0 1400 270" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <linearGradient id="lkw1" x1="0" x2="1"><stop offset="0" stop-color="#00a3ff" stop-opacity=".1"/><stop offset=".5" stop-color="#4f7bff"/><stop offset="1" stop-color="#b84dff" stop-opacity=".3"/></linearGradient>
        <linearGradient id="lkw2" x1="0" x2="1"><stop offset="0" stop-color="#8a5cff" stop-opacity="0"/><stop offset=".6" stop-color="#c43bff"/><stop offset="1" stop-color="#ff2e93"/></linearGradient>
      </defs>
      <path d="M0 215 C220 160 420 250 700 190 S1150 120 1400 175" fill="none" stroke="url(#lkw1)" stroke-width="2"/>
      <path d="M0 240 C280 190 560 262 840 215 S1180 160 1400 220" fill="none" stroke="url(#lkw2)" stroke-width="1.6"/>
      <path d="M0 190 C300 235 620 140 940 200 S1250 235 1400 150" fill="none" stroke="url(#lkw1)" stroke-width="1.2" opacity=".6"/>
    </svg>

    <div class="lk-banner-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="url(#lkIco)" stroke-width="1.5" stroke-linejoin="round">
        <defs><linearGradient id="lkIco" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#8a5cff"/><stop offset="1" stop-color="#ff2ec8"/></linearGradient></defs>
        <path d="<?= $heartPath ?>"/>
      </svg>
    </div>
    <div class="lk-banner-text">
      <h1>Liked <span>Videos</span></h1>
      <p>Your favourite videos, all in one place <span aria-hidden="true" style="-webkit-text-fill-color:#ff2e4d;color:#ff2e4d;background:none;filter:none">&#10084;&#65039;</span></p>
    </div>

    <svg class="lk-heart-art" viewBox="0 0 640 270" preserveAspectRatio="xMaxYMid meet" aria-hidden="true">
      <defs>
        <linearGradient id="lkH1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#00e5ff"/><stop offset=".5" stop-color="#8a5cff"/><stop offset="1" stop-color="#ff2ec8"/></linearGradient>
        <linearGradient id="lkH2" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff2ec8"/><stop offset="1" stop-color="#4f7bff"/></linearGradient>
        <linearGradient id="lkP" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a98bff"/><stop offset="1" stop-color="#ff2ec8"/></linearGradient>
        <filter id="lkGlow" x="-30%" y="-30%" width="160%" height="160%"><feGaussianBlur stdDeviation="5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <g filter="url(#lkGlow)" fill="none" stroke-linejoin="round" stroke-linecap="round">
        <ellipse cx="400" cy="150" rx="250" ry="62" transform="rotate(-14 400 150)" stroke="url(#lkH2)" stroke-width="5"/>
        <ellipse cx="400" cy="150" rx="215" ry="46" transform="rotate(-14 400 150)" stroke="#00e5ff" stroke-width="2" opacity=".7"/>
        <g transform="translate(300 18) scale(10.2)"><path d="<?= $heartPath ?>" stroke="url(#lkH1)" stroke-width=".9"/></g>
        <g transform="translate(332 48) scale(8.2)"><path d="<?= $heartPath ?>" stroke="url(#lkH2)" stroke-width=".8" opacity=".9"/></g>
        <path d="M385 102 v82 l70-41z" fill="url(#lkP)" stroke="#ffffff" stroke-width="2" stroke-opacity=".5"/>
      </g>
      <g filter="url(#lkGlow)" fill="#b84dff" opacity=".9">
        <g transform="translate(246 24) scale(2.4) rotate(-12 12 12)"><path d="<?= $heartPath ?>"/></g>
        <g transform="translate(560 96) scale(2.8) rotate(14 12 12)"><path d="<?= $heartPath ?>" fill="#8a5cff"/></g>
        <g transform="translate(486 168) scale(2.3) rotate(8 12 12)"><path d="<?= $heartPath ?>" fill="#ff2ec8"/></g>
        <g transform="translate(180 128) scale(1.4)"><path d="<?= $heartPath ?>" fill="#8a5cff"/></g>
      </g>
    </svg>
  </section>

  <section class="lk-stats" aria-label="Liked videos summary">
    <span class="lk-stats-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><path d="M7 10v11H3a1 1 0 0 1-1-1V11a1 1 0 0 1 1-1h4Zm2 0 3.4-7.2A2 2 0 0 1 14.2 2c1.4 0 2.4 1.3 2.1 2.7L15.6 8H20a2 2 0 0 1 1.9 2.6l-2.3 8A2 2 0 0 1 17.7 20H9Z"/></svg></span>
    <div class="lk-stats-text">
      <small>You have liked</small>
      <b class="lk-count"><span><?= number_format($likedCount) ?></span> <?= $likedCount === 1 ? 'Video' : 'Videos' ?></b>
    </div>
    <?php if ($likedCount > 0): ?>
      <form method="post" onsubmit="return confirm('Remove all your likes? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="lk-pill"><?= $icoTrash ?>Remove All Likes</button>
      </form>
    <?php endif; ?>
  </section>

  <?php if ($pageError !== ''): ?>
    <div class="lk-error" role="alert"><strong>Liked videos are unavailable.</strong><p><?= $h($pageError) ?></p></div>
  <?php endif; ?>
  <?php if ($flash): ?>
    <div class="lk-flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>" role="status"><?= $h($flash[1]) ?></div>
  <?php endif; ?>
  <?php if (!$isMember): ?>
    <div class="lk-notice" role="status">You are not signed in, so these likes are only kept for this visit. <a href="login.php?redirect=liked.php">Sign in</a> to keep your liked videos.</div>
  <?php elseif (count($likedVideoIds) > $likedCount): ?>
    <div class="lk-notice" role="status">Unavailable videos are hidden</div>
  <?php endif; ?>

  <div class="lk-toolbar">
    <nav class="lk-chips" aria-label="Filter liked videos">
      <button class="lk-chip" type="button" data-lk-kind="all" aria-current="page"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.8"/></svg>All</button>
      <button class="lk-chip" type="button" data-lk-kind="video"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3.5"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/></svg>Videos</button>
      <button class="lk-chip" type="button" data-lk-kind="short"><?= $icoBolt ?>Shorts</button>
    </nav>
    <label class="lk-search">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
      <input type="search" id="lkSearch" placeholder="Search your liked videos..." aria-label="Search your liked videos" autocomplete="off">
    </label>
  </div>

  <?php if ($likedVideos): ?>
    <div class="lk-grid" id="lkGrid">
      <?php foreach ($likedVideos as $video):
          $source = find_source($video, __DIR__);
          $youtubeId = $source !== '' ? extract_youtube_id($source, true) : null;
          $thumbnail = thumb_for($video, $source, $youtubeId);
          $short = $isShort($video);
          $kind = $short ? 'short' : 'video';
          $title = trim((string)($video['title'] ?? '')) ?: 'Untitled';
          $creator = trim((string)($video['username'] ?? $video['channel_name'] ?? ''));
          $videoHref = $hrefFor($video);
          $videoId = (string)($video['id'] ?? '');
          $ago = vs_lk_ago($video['created_at'] ?? '');
      ?>
        <article class="lk-card" data-lk-card data-kind="<?= $kind ?>" data-search="<?= $h(mb_strtolower($title . ' ' . $creator)) ?>">
          <a class="lk-thumb" href="<?= $h($videoHref) ?>" aria-label="Watch <?= $h($title) ?>">
            <img src="<?= $h($thumbnail) ?>" alt="" loading="lazy">
            <?php if ($short): ?><span class="lk-short-tag"><?= $icoBolt ?>SHORT</span><?php endif; ?>
            <?php if (!empty($video['duration'])): ?><span class="lk-duration"><?= $h((string)$video['duration']) ?></span><?php endif; ?>
          </a>
          <div class="lk-info">
            <a class="lk-title" href="<?= $h($videoHref) ?>"><?= $h($title) ?></a>
            <?php if ($creator !== ''): ?><p class="lk-channel"><?= $h($creator) ?><?= vs_vip_badge($creator, $conn) ?></p><?php endif; ?>
            <p class="lk-meta"><?= $h(vs_lk_views($video['views'] ?? 0)) ?> views<?= $ago !== '' ? ' &bull; ' . $h($ago) : '' ?></p>
          </div>

          <details class="lk-menu">
            <summary aria-label="More options for <?= $h($title) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></summary>
            <div class="lk-menu-list">
              <a href="<?= $h($videoHref) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>Open video</a>
              <button type="button" data-lk-copy="<?= $h($videoHref) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>Copy link</button>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action" value="unlike">
                <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
                <button type="submit"><?= $icoTrash ?>Remove from liked videos</button>
              </form>
            </div>
          </details>

          <form class="lk-heart-form" method="post">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="unlike">
            <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
            <button type="submit" class="lk-heart" title="Unlike" aria-label="Unlike <?= $h($title) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="<?= $heartPath ?>"/></svg></button>
          </form>
        </article>
      <?php endforeach; ?>
    </div>
    <p class="lk-empty" id="lkEmpty" hidden>Nothing here yet.</p>
    <nav class="lk-pager" id="lkPager" aria-label="Pages" hidden><div class="lk-pager-inner" id="lkPagerInner"></div></nav>
  <?php else: ?>
    <div class="lk-empty"><h2>No liked videos yet</h2><p>Videos you like will be collected here.</p></div>
  <?php endif; ?>
</main>

<script>
(function () {
  var PER_PAGE = 12;
  var cards = Array.prototype.slice.call(document.querySelectorAll('[data-lk-card]'));
  var kindButtons = document.querySelectorAll('[data-lk-kind]');
  var search = document.getElementById('lkSearch');
  var empty = document.getElementById('lkEmpty');
  var pager = document.getElementById('lkPager');
  var pagerInner = document.getElementById('lkPagerInner');
  var state = { kind: 'all', q: '', page: 1 };

  function filtered() {
    return cards.filter(function (c) {
      if (state.kind !== 'all' && c.getAttribute('data-kind') !== state.kind) return false;
      return !state.q || c.getAttribute('data-search').indexOf(state.q) !== -1;
    });
  }
  var chevL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m15 5-7 7 7 7"/></svg>';
  var chevR = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m9 5 7 7-7 7"/></svg>';

  function pageList(total, cur) {
    var out = [], i;
    if (total <= 7) { for (i = 1; i <= total; i++) out.push(i); return out; }
    out.push(1);
    if (cur > 3) out.push('...');
    for (i = Math.max(2, cur - 1); i <= Math.min(total - 1, cur + 1); i++) out.push(i);
    if (cur < total - 2) out.push('...');
    out.push(total);
    return out;
  }

  function render() {
    if (!pager) return;
    var list = filtered();
    var pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    if (state.page > pages) state.page = pages;
    var from = (state.page - 1) * PER_PAGE, to = from + PER_PAGE;
    cards.forEach(function (c) { c.hidden = true; });
    list.forEach(function (c, i) { c.hidden = !(i >= from && i < to); });
    if (empty) empty.hidden = list.length !== 0;
    pager.hidden = pages <= 1;

    var html = '<button type="button" data-p="' + (state.page - 1) + '" aria-label="Previous page"' + (state.page === 1 ? ' disabled' : '') + '>' + chevL + '</button>';
    pageList(pages, state.page).forEach(function (p) {
      html += p === '...'
        ? '<span class="gap">&hellip;</span>'
        : '<button type="button" data-p="' + p + '"' + (p === state.page ? ' aria-current="page"' : '') + '>' + p + '</button>';
    });
    html += '<button type="button" data-p="' + (state.page + 1) + '" aria-label="Next page"' + (state.page === pages ? ' disabled' : '') + '>' + chevR + '</button>';
    pagerInner.innerHTML = html;
  }

  if (pagerInner) {
    pagerInner.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-p]');
      if (!b || b.disabled) return;
      state.page = parseInt(b.getAttribute('data-p'), 10) || 1;
      render();
      var grid = document.getElementById('lkGrid');
      if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }
  kindButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      kindButtons.forEach(function (b) { b.removeAttribute('aria-current'); });
      btn.setAttribute('aria-current', 'page');
      state.kind = btn.getAttribute('data-lk-kind'); state.page = 1; render();
    });
  });
  if (search) {
    search.addEventListener('input', function () { state.q = search.value.trim().toLowerCase(); state.page = 1; render(); });
  }
  render();

  // Copy link
  document.querySelectorAll('[data-lk-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = new URL(btn.getAttribute('data-lk-copy'), window.location.href).href;
      function done() {
        if (typeof toast === 'function') { toast('Link copied'); }
        var d = btn.closest('details'); if (d) { d.removeAttribute('open'); }
      }
      function fallback() {
        var t = document.createElement('textarea');
        t.value = url; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (e) { window.prompt('Copy this link:', url); }
        document.body.removeChild(t);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, fallback); } else { fallback(); }
    });
  });

  // Close three-dot menus when clicking elsewhere / Escape
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.lk-menu[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.lk-menu[open]').forEach(function (d) { d.removeAttribute('open'); });
  });
})();
</script>
<?php vs_page_end(); ?>