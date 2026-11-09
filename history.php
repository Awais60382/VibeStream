<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/history-store.php';
require_once __DIR__ . '/vip-badge.php';

if (empty($_SESSION['username'])) {
    header('Location: login.php?redirect=history.php');
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = vs_history_user_id($conn);
if ($userId <= 0) {
    http_response_code(403);
    exit('Sign in again to see your watch history.');
}

$csrf = $_SESSION['history_csrf'] ?? '';
if (!is_string($csrf) || $csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['history_csrf'] = $csrf;
}

// Current filter (All / Videos / Shorts) and search
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'videos', 'shorts'], true)) {
    $type = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

$historyUrl = static function (string $q, string $type): string {
    $params = [];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($type !== 'all') {
        $params['type'] = $type;
    }
    return 'history.php' . ($params ? '?' . http_build_query($params) : '');
};

$errorMessage = '';
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errorMessage = 'That request expired. Refresh the page and try again.';
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'message' => $errorMessage]);
            exit;
        }
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'remove') {
                vs_history_remove($conn, $userId, trim((string)($_POST['video_id'] ?? '')));
                $_SESSION['history_notice'] = 'Removed from watch history.';
            } elseif ($action === 'clear') {
                vs_history_clear($conn, $userId);
                $_SESSION['history_notice'] = 'Your watch history was cleared.';
            } elseif ($action === 'pause') {
                vs_history_set_paused($conn, $userId, true);
                $_SESSION['history_notice'] = 'Watch history is paused. Videos you watch will not be saved.';
            } elseif ($action === 'resume') {
                vs_history_set_paused($conn, $userId, false);
                $_SESSION['history_notice'] = 'Watch history is on again.';
            }
            if ($isAjax) {
                unset($_SESSION['history_notice']);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
            }
            $backType = (string)($_POST['type'] ?? 'all');
            header('Location: ' . $historyUrl(trim((string)($_POST['q'] ?? '')), in_array($backType, ['videos', 'shorts'], true) ? $backType : 'all'));
            exit;
        } catch (Throwable $error) {
            error_log('Watch history update failed: ' . $error->getMessage());
            $errorMessage = 'Could not update your watch history. Please try again.';
            if ($isAjax) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'message' => $errorMessage]);
                exit;
            }
        }
    }
}

$successMessage = (string)($_SESSION['history_notice'] ?? '');
unset($_SESSION['history_notice']);

$paused = false;
try {
    $paused = vs_history_paused($conn, $userId);
} catch (Throwable $error) {
    $paused = false;
}

$entries = [];
try {
    $entries = vs_history_fetch($conn, $userId, $search, 200);
} catch (Throwable $error) {
    error_log('Watch history load failed: ' . $error->getMessage());
    $errorMessage = $errorMessage !== '' ? $errorMessage : 'Could not load your watch history.';
}

// Numbers for the banner and the filter chips (counted before the Videos / Shorts filter is applied)
$countAll = count($entries);
$countShorts = 0;
$countToday = 0;
$todayStamp = date('Y-m-d');
foreach ($entries as $countEntry) {
    if ((($countEntry['video']['type'] ?? '') === 'short')) {
        $countShorts++;
    }
    if (date('Y-m-d', (int)$countEntry['watched_ts']) === $todayStamp) {
        $countToday++;
    }
}
$countVideos = $countAll - $countShorts;

if ($type !== 'all') {
    $wantShorts = ($type === 'shorts');
    $entries = array_values(array_filter($entries, static function ($e) use ($wantShorts) {
        return ((($e['video']['type'] ?? '') === 'short')) === $wantShorts;
    }));
}

if (!function_exists('vs_history_thumb')) {
    function vs_history_thumb(array $row): string
    {
        $src = function_exists('find_source') ? find_source($row) : '';
        $yt = ($src !== '' && function_exists('extract_youtube_id')) ? extract_youtube_id($src, true) : null;
        if ($yt) {
            return 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
        }
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $col) {
            if (!empty($row[$col])) {
                return (string)$row[$col];
            }
        }
        return '';
    }
}

// Group by day (Today / Yesterday / "Friday, Oct 9"); inside a day, Shorts first, then videos
$groups = [];
$todayKey = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
foreach ($entries as $entry) {
    $ts = (int)$entry['watched_ts'];
    $key = date('Y-m-d', $ts);
    if (!isset($groups[$key])) {
        if ($key === $todayKey) {
            $label = 'Today';
        } elseif ($key === $yesterdayKey) {
            $label = 'Yesterday';
        } else {
            $label = date('l, M j', $ts) . (date('Y', $ts) !== date('Y') ? ', ' . date('Y', $ts) : '');
        }
        $groups[$key] = ['label' => $label, 'shorts' => [], 'videos' => []];
    }
    $isShortEntry = (($entry['video']['type'] ?? '') === 'short');
    $groups[$key][$isShortEntry ? 'shorts' : 'videos'][] = $entry;
}

if (!function_exists('vs_hx_views')) {
    function vs_hx_views($n): string
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
if (!function_exists('vs_hx_ago')) {
    function vs_hx_ago(int $ts): string
    {
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
if (!function_exists('vs_hx_clock')) {
    function vs_hx_clock(int $s): string
    {
        $s = max(0, $s);
        return $s >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60)
            : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }
}
if (!function_exists('vs_hx_duration')) {
    function vs_hx_duration($raw, int $fallback = 0): string
    {
        $raw = trim((string)$raw);
        if ($raw !== '' && !ctype_digit($raw)) {
            return $raw;
        }
        $s = $raw !== '' ? (int)$raw : 0;
        if ($s <= 0) {
            $s = $fallback;
        }
        return $s > 0 ? vs_hx_clock($s) : '';
    }
}
/** Escapes text and wraps search matches in <mark>. */
if (!function_exists('vs_hx_mark')) {
    function vs_hx_mark(string $text, string $query): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        if ($query === '') {
            return $safe;
        }
        $needle = htmlspecialchars($query, ENT_QUOTES, 'UTF-8');
        return (string)preg_replace('/(' . preg_quote($needle, '/') . ')/iu', '<mark>$1</mark>', $safe);
    }
}

$extraCss = <<<'CSS'
.history-page { max-width: 1400px; margin: 0 auto; padding: 16px 24px 64px; }

/* ===== Banner ===== */
.hx-banner {
    position: relative; overflow: hidden; display: flex; flex-wrap: wrap; align-items: center; gap: 22px 34px;
    min-height: 150px; margin: 0 0 24px; padding: 22px 40px; border-radius: 18px; border: 1px solid rgba(138,92,255,.5);
    background:
        radial-gradient(520px 220px at 6% 50%, rgba(124,58,237,.55), transparent 70%),
        radial-gradient(640px 240px at 96% 10%, rgba(236,72,153,.30), transparent 70%),
        linear-gradient(100deg, #060724 0%, #110f5c 46%, #1b1375 74%, #090530 100%);
    box-shadow: 0 0 36px -10px rgba(138,92,255,.7);
}
.hx-waves { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.hx-banner-icon, .hx-banner-text, .hx-stats { position: relative; z-index: 1; }
.hx-banner-icon svg { display: block; width: 84px; height: 84px; filter: drop-shadow(0 0 14px rgba(138,92,255,.9)); }
.hx-banner-text { flex: 1 1 280px; min-width: 0; }
.hx-banner h1 { margin: 0; font-size: clamp(32px, 4.4vw, 50px); line-height: 1.08; font-weight: 800; letter-spacing: -0.02em; color: #fff; }
.hx-banner h1 span { background: linear-gradient(90deg, #ff3ec8, #b84dff); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 12px rgba(255,62,200,.45)); }
.hx-banner p { margin: 6px 0 0; font-size: 17px; color: #d9dcff; }
.hx-stats { display: flex; flex-wrap: wrap; gap: 12px; }
.hx-stat { min-width: 108px; padding: 11px 16px; border-radius: 14px; border: 1px solid rgba(255,255,255,.16); background: rgba(8,8,40,.55); backdrop-filter: blur(6px); }
.hx-stat b { display: block; font-size: 24px; line-height: 1.1; font-weight: 800; color: #fff; }
.hx-stat span { display: block; margin-top: 2px; font-size: 12px; letter-spacing: .04em; text-transform: uppercase; color: #aab0ff; }
.hx-stat.accent b { background: linear-gradient(90deg, #00e5ff, #8a5cff); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; }

/* ===== Layout ===== */
.history-layout { display: grid; grid-template-columns: minmax(0, 1fr) 330px; gap: 34px; align-items: start; }
.history-main { min-width: 0; }
.history-side { position: sticky; top: calc(var(--navbar-height, 72px) + 16px); display: flex; flex-direction: column; gap: 14px; }

/* Filter chips */
.hx-chips { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 18px; }
.hx-chip { display: inline-flex; align-items: center; gap: 8px; height: 42px; padding: 0 18px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font-size: 15px; font-weight: 600; text-decoration: none; transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
.hx-chip svg { width: 18px; height: 18px; flex: 0 0 18px; }
.hx-chip em { font-style: normal; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: rgba(138,92,255,.22); }
.hx-chip:hover { transform: translateY(-1px); }
.hx-chip.active { background: linear-gradient(135deg, #8a5cff, #5b3fe8); color: #fff; border-color: transparent; box-shadow: 0 0 22px rgba(138,92,255,.65); }
.hx-chip.active em { background: rgba(255,255,255,.22); }

/* Day headings */
.hx-day[hidden], .hx-row[hidden] { display: none !important; }
.hx-day-title { display: flex; align-items: center; gap: 10px; margin: 26px 0 14px; font-size: 21px; font-weight: 700; }
.hx-day:first-of-type .hx-day-title { margin-top: 4px; }
.hx-day-title svg { width: 24px; height: 24px; flex: 0 0 24px; }
.hx-day-title em { font-style: normal; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--surface-2); color: var(--muted); }
.hx-day-title::after { content: ""; flex: 1 1 auto; height: 1px; margin-left: 6px; background: var(--border); }

/* ===== Shorts shelf ===== */
.hx-shelf { margin: 0 0 22px; }
.hx-shelf-head { display: flex; align-items: center; gap: 10px; margin: 0 0 12px; }
.hx-shelf-head svg { width: 24px; height: 24px; color: #ff2e93; filter: drop-shadow(0 0 6px rgba(255,46,147,.8)); }
.hx-shelf-head h3 { margin: 0; font-size: 19px; font-weight: 700; }
.hx-rowwrap { position: relative; }
.hx-row-shorts { display: flex; gap: 14px; overflow-x: auto; scroll-snap-type: x proximity; scrollbar-width: none; padding: 4px 2px 6px; }
.hx-row-shorts::-webkit-scrollbar { display: none; }
.hx-short { flex: 0 0 176px; width: 176px; scroll-snap-align: start; }
.hx-short-thumb { position: relative; display: block; aspect-ratio: 9 / 14; overflow: hidden; border-radius: 16px; background: var(--surface-2); }
.hx-short-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .35s ease; }
.hx-short:hover .hx-short-thumb img { transform: scale(1.05); }
.hx-short-shade { position: absolute; inset: auto 0 0 0; height: 55%; background: linear-gradient(180deg, transparent, rgba(0,0,0,.78)); pointer-events: none; }
.hx-short-views { position: absolute; left: 10px; bottom: 9px; display: inline-flex; align-items: center; gap: 5px; color: #fff; font-size: 13px; font-weight: 700; text-shadow: 0 1px 4px rgba(0,0,0,.6); }
.hx-short-views svg { width: 13px; height: 13px; }
.hx-short form { margin: 0; }
.hx-short-title { display: -webkit-box; overflow: hidden; margin: 9px 2px 0; color: var(--text); font-size: 14px; font-weight: 600; line-height: 1.35; text-decoration: none; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.hx-short-title:hover { text-decoration: underline; }
.hx-x { position: absolute; top: 8px; right: 8px; z-index: 3; display: grid; place-items: center; width: 32px; height: 32px; border: 0; border-radius: 50%; background: rgba(5,6,15,.72); color: #fff; cursor: pointer; opacity: 0; transition: opacity .15s ease, background .15s ease, transform .15s ease; }
.hx-x svg { width: 16px; height: 16px; }
.hx-short:hover .hx-x, .hx-x:focus-visible { opacity: 1; }
.hx-x:hover { background: #ff2e93; transform: scale(1.08); }
@media (hover: none) { .hx-x { opacity: 1; } }
.hx-arrow { position: absolute; top: 40%; z-index: 5; display: none; place-items: center; width: 44px; height: 44px; border: 0; border-radius: 50%; background: rgba(40,40,46,.92); color: #fff; cursor: pointer; box-shadow: 0 2px 10px rgba(0,0,0,.45); transform: translateY(-50%); }
.hx-arrow.show { display: grid; }
.hx-arrow.prev { left: -10px; }
.hx-arrow.next { right: -10px; }
.hx-arrow svg { width: 20px; height: 20px; }

/* ===== Video rows ===== */
.hx-row { position: relative; display: grid; grid-template-columns: 246px minmax(0, 1fr) auto; gap: 18px; align-items: start; margin: 0 0 14px; padding: 10px; border-radius: 16px; border: 1px solid var(--border); background: var(--surface); transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease, opacity .3s ease; }
.hx-row.is-removing { opacity: 0; transform: translateX(26px) scale(.98); }
.hx-thumb { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 12px; background: var(--surface-2); }
.hx-thumb img { display: block; width: 100%; height: 100%; object-fit: cover; transition: transform .35s ease; }
.hx-row:hover .hx-thumb img { transform: scale(1.05); }
.hx-thumb-empty { display: grid; place-items: center; width: 100%; height: 100%; color: var(--muted); background: linear-gradient(135deg, #25252c, #17171b); }
.hx-thumb-empty svg { width: 38px; height: 38px; }
.hx-duration { position: absolute; right: 8px; bottom: 10px; padding: 2px 7px; border-radius: 6px; background: rgba(0,0,0,.82); color: #fff; font-size: 12.5px; font-weight: 700; }
.hx-progress { position: absolute; right: 0; bottom: 0; left: 0; height: 4px; background: rgba(255,255,255,.3); }
.hx-progress span { display: block; height: 100%; background: linear-gradient(90deg, #8a5cff, #ff2e93); }
.hx-info { min-width: 0; padding-top: 2px; }
.hx-title { display: -webkit-box; overflow: hidden; margin: 0 0 6px; color: var(--text); font-size: 18px; font-weight: 700; line-height: 1.3; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.hx-info > a:hover .hx-title { text-decoration: underline; }
.hx-channel { display: flex; align-items: center; gap: 6px; margin: 0 0 4px; font-size: 14.5px; color: var(--muted); }
.hx-channel a { color: var(--muted); text-decoration: none; font-weight: 600; }
.hx-channel a:hover { color: var(--text); }
.hx-meta { margin: 0 0 8px; color: var(--muted); font-size: 13px; }
.hx-desc { display: -webkit-box; overflow: hidden; margin: 0; color: var(--muted); font-size: 13px; line-height: 1.5; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.hx-resume { display: inline-flex; align-items: center; gap: 7px; margin: 10px 0 0; padding: 6px 14px 6px 10px; border-radius: 999px; border: 1px solid rgba(138,92,255,.45); background: rgba(138,92,255,.14); color: var(--text); font-size: 13px; font-weight: 600; text-decoration: none; transition: box-shadow .18s ease, border-color .18s ease; }
.hx-resume svg { width: 14px; height: 14px; color: #ff2e93; }
.hx-resume:hover { border-color: #ff2e93; box-shadow: 0 0 16px -2px rgba(255,46,147,.6); }
mark { background: rgba(255,46,147,.35); color: inherit; border-radius: 4px; padding: 0 2px; }
.hx-tools { display: flex; align-items: center; gap: 4px; }
.hx-tools form { margin: 0; }
.hx-iconbtn { display: grid; place-items: center; width: 38px; height: 38px; border: 0; border-radius: 50%; background: transparent; color: var(--muted); cursor: pointer; transition: background .15s ease, color .15s ease; }
.hx-iconbtn svg { width: 19px; height: 19px; }
.hx-iconbtn:hover { background: rgba(255,46,147,.18); color: #ff5aa8; }
.hx-quick { opacity: 0; transition: opacity .15s ease; }
.hx-row:hover .hx-quick, .hx-quick:focus-within { opacity: 1; }
@media (hover: none) { .hx-quick { opacity: 1; } }

/* Three-dot menu */
.hm { position: relative; flex: 0 0 auto; }
.hm summary { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 50%; color: var(--text); cursor: pointer; list-style: none; }
.hm summary::-webkit-details-marker { display: none; }
.hm summary:hover, .hm[open] summary { background: rgba(138,92,255,.2); }
.hm summary svg { width: 20px; height: 20px; }
.hm-menu { position: absolute; right: 0; top: 42px; z-index: 30; min-width: 230px; padding: 8px 0; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); box-shadow: 0 8px 28px rgba(0,0,0,.45); }
.hm-menu form { margin: 0; }
.hm-menu a, .hm-menu button { display: flex; align-items: center; gap: 14px; width: 100%; padding: 10px 16px; border: 0; background: transparent; color: var(--text); font: inherit; font-size: 14px; text-align: left; text-decoration: none; cursor: pointer; }
.hm-menu a:hover, .hm-menu button:hover { background: var(--surface-2); }
.hm-menu svg { width: 20px; height: 20px; flex: 0 0 20px; }

/* Load more */
.hx-more-wrap { display: flex; justify-content: center; margin: 28px 0 0; }
.hx-more-wrap[hidden] { display: none; }
.hx-more { display: inline-flex; align-items: center; gap: 10px; height: 48px; padding: 0 34px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font-size: 16px; font-weight: 600; cursor: pointer; transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
.hx-more svg { width: 20px; height: 20px; }
.hx-more:hover { transform: translateY(-1px); border-color: rgba(138,92,255,.7); box-shadow: 0 0 18px -2px rgba(138,92,255,.6); }

/* ===== Side panel ===== */
.hx-card { padding: 16px; border-radius: 16px; border: 1px solid var(--border); background: var(--surface); }
.hx-search { display: flex; align-items: center; gap: 12px; height: 50px; padding: 0 16px; border-radius: 999px; border: 1px solid var(--border); background: var(--surface-2); }
.hx-search svg { width: 20px; height: 20px; flex: 0 0 20px; color: var(--muted); }
.hx-search input { width: 100%; min-width: 0; border: 0; outline: 0; background: transparent; color: var(--text); font-size: 15px; }
.hx-search input::placeholder { color: var(--muted); }
.hx-search kbd { flex: 0 0 auto; padding: 1px 8px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg); color: var(--muted); font: inherit; font-size: 12px; }
.hx-status { display: flex; align-items: center; gap: 12px; }
.hx-dot { position: relative; width: 12px; height: 12px; flex: 0 0 12px; border-radius: 50%; background: #2ee59d; box-shadow: 0 0 12px #2ee59d; }
.hx-dot::after { content: ""; position: absolute; inset: -5px; border-radius: 50%; border: 2px solid #2ee59d; opacity: 0; animation: hxPulse 2s ease-out infinite; }
.hx-status.is-paused .hx-dot { background: #ffb13c; box-shadow: 0 0 12px #ffb13c; }
.hx-status.is-paused .hx-dot::after { border-color: #ffb13c; animation: none; }
@keyframes hxPulse { 0% { transform: scale(.6); opacity: .8; } 100% { transform: scale(1.6); opacity: 0; } }
.hx-status-text strong { display: block; font-size: 15px; }
.hx-status-text small { display: block; margin-top: 2px; color: var(--muted); font-size: 12.5px; line-height: 1.4; }
.hx-side-actions { display: flex; flex-direction: column; gap: 10px; margin-top: 14px; }
.hx-side-actions form { margin: 0; }
.hx-btn { display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%; height: 46px; padding: 0 18px; border-radius: 999px; border: 1px solid rgba(138,92,255,.45); background: var(--surface-2); color: var(--text); font-size: 15px; font-weight: 600; cursor: pointer; transition: box-shadow .18s ease, border-color .18s ease, transform .18s ease; }
.hx-btn svg { width: 19px; height: 19px; flex: 0 0 19px; }
.hx-btn:hover { transform: translateY(-1px); border-color: #8a5cff; box-shadow: 0 0 16px -2px rgba(138,92,255,.6); }
.hx-btn.danger:hover { border-color: #ff2e93; box-shadow: 0 0 16px -2px rgba(255,46,147,.6); color: #ff5aa8; }
.hx-tip { margin: 0; color: var(--muted); font-size: 12.5px; line-height: 1.5; }

/* Messages + toast */
.history-alert, .history-empty { margin: 0 0 18px; padding: 14px 20px; border-radius: 12px; }
.history-alert { background: rgba(220,60,60,.14); color: #ff9b9b; }
.history-success { background: rgba(45,180,120,.14); color: #278552; }
html[data-theme="dark"] .history-success { color: #8de2b4; }
.history-empty { padding: 50px 20px; border: 1px dashed var(--border); border-radius: 18px; text-align: center; color: var(--muted); }
.history-empty h2 { margin: 0 0 8px; color: var(--text); }
.hx-toast { position: fixed; left: 50%; bottom: 28px; z-index: 200; padding: 12px 22px; border-radius: 999px; background: #151a3a; color: #fff; border: 1px solid rgba(0,229,255,.55); box-shadow: 0 0 24px rgba(0,229,255,.4); font-size: 14px; font-weight: 600; opacity: 0; pointer-events: none; transform: translate(-50%, 14px); transition: opacity .25s ease, transform .25s ease; }
.hx-toast.show { opacity: 1; transform: translate(-50%, 0); }

@media (max-width: 1060px) {
    .history-layout { grid-template-columns: minmax(0, 1fr); gap: 18px; }
    .history-side { position: static; order: -1; }
    .hx-side-actions { flex-direction: row; flex-wrap: wrap; }
    .hx-side-actions form { flex: 1 1 180px; }
}
@media (max-width: 760px) {
    .history-page { padding-inline: 12px; }
    .hx-banner { padding: 18px 20px; gap: 16px; }
    .hx-banner-icon svg { width: 54px; height: 54px; }
    .hx-banner p { font-size: 14px; }
    .hx-stat { min-width: 92px; padding: 9px 12px; }
    .hx-stat b { font-size: 20px; }
    .hx-row { grid-template-columns: 138px minmax(0, 1fr) auto; gap: 12px; padding: 8px; }
    .hx-title { font-size: 15px; }
    .hx-desc { display: none; }
    .hx-quick { display: none; }
    .hx-short { flex-basis: 150px; width: 150px; }
}
@media (max-width: 480px) {
    .hx-row { grid-template-columns: 116px minmax(0, 1fr) auto; gap: 10px; }
    .hx-title { font-size: 14px; }
}
@media (prefers-reduced-motion: reduce) { .hx-dot::after { animation: none; } .hx-row, .hx-thumb img, .hx-short-thumb img { transition: none; } }

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


/* ---- history page ---- */
html:not([data-theme="light"]) body .hx-chip { background: rgba(23,28,66,.7); border-color: rgba(138,92,255,.35); }
html:not([data-theme="light"]) body .hx-chip:hover { border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .hx-chip.active { border-color: transparent; }
html:not([data-theme="light"]) body .hx-day-title { text-shadow: 0 0 14px rgba(138,92,255,.5); }
html:not([data-theme="light"]) body .hx-day-title svg { color: #00e5ff; filter: drop-shadow(0 0 5px rgba(0,229,255,.6)); }
html:not([data-theme="light"]) body .hx-day-title::after { background: rgba(138,92,255,.3); }
html:not([data-theme="light"]) body .hx-row { background: linear-gradient(180deg, rgba(14,18,44,.9), rgba(8,10,28,.95)); border-color: rgba(138,92,255,.25); }
html:not([data-theme="light"]) body .hx-row:hover { border-color: rgba(0,229,255,.6); box-shadow: 0 0 26px -8px rgba(0,229,255,.55), 0 0 50px -20px rgba(138,92,255,.6); transform: translateY(-2px); }
html:not([data-theme="light"]) body .hx-row.is-removing { transform: translateX(26px) scale(.98); }
html:not([data-theme="light"]) body .hx-info > a:hover .hx-title { color: #00e5ff; text-decoration: none; text-shadow: 0 0 10px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .hx-short-thumb, html:not([data-theme="light"]) body .hx-thumb { border: 1px solid rgba(138,92,255,.3); }
html:not([data-theme="light"]) body .hx-short:hover .hx-short-thumb { border-color: #00e5ff; box-shadow: 0 0 22px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .hx-arrow { background: rgba(10,13,31,.9); border: 1px solid rgba(0,229,255,.6); color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .hm-menu { background: rgba(10,13,31,.96); border-color: rgba(0,229,255,.5); box-shadow: 0 0 30px -4px rgba(0,229,255,.5), 0 0 60px -16px rgba(138,92,255,.6); }
html:not([data-theme="light"]) body .hm-menu a:hover, html:not([data-theme="light"]) body .hm-menu button:hover { background: rgba(0,229,255,.12); }
html:not([data-theme="light"]) body .hx-card { background: linear-gradient(180deg, rgba(14,18,44,.88), rgba(8,10,28,.94)); border-color: rgba(138,92,255,.4); box-shadow: 0 0 26px -12px rgba(138,92,255,.6); }
html:not([data-theme="light"]) body .hx-search { background: rgba(10,13,31,.9); border-color: rgba(138,92,255,.55); box-shadow: 0 0 10px -2px rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .hx-search:focus-within { border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 18px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .hx-btn, html:not([data-theme="light"]) body .hx-more { background: rgba(23,28,66,.6); }
html:not([data-theme="light"]) body .history-alert { box-shadow: 0 0 18px -6px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .history-success { box-shadow: 0 0 18px -6px rgba(60,220,130,.6); }
html:not([data-theme="light"]) body .history-empty { background: rgba(10,13,31,.88); border: 1px dashed rgba(0,229,255,.5); box-shadow: 0 0 24px -8px rgba(0,229,255,.4); }

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


/* ---- history page ---- */
html[data-theme="light"] body .hx-chip { background: #ffffff; border-color: rgba(124,77,255,.35); }
html[data-theme="light"] body .hx-chip:hover { border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
html[data-theme="light"] body .hx-chip.active { background: linear-gradient(135deg, #7c4dff, #5b3fe8); border-color: transparent; }
html[data-theme="light"] body .hx-day-title svg { color: #7c4dff; }
html[data-theme="light"] body .hx-day-title::after { background: rgba(124,77,255,.3); }
html[data-theme="light"] body .hx-row { background: #ffffff; border-color: rgba(124,77,255,.3); }
html[data-theme="light"] body .hx-row:hover { border-color: #00b8e6; box-shadow: 0 0 24px -8px rgba(0,184,230,.55); transform: translateY(-2px); }
html[data-theme="light"] body .hx-row.is-removing { transform: translateX(26px) scale(.98); }
html[data-theme="light"] body .hx-info > a:hover .hx-title { color: #0086b3; text-decoration: none; }
html[data-theme="light"] body .hx-short-thumb, html[data-theme="light"] body .hx-thumb { border: 1px solid rgba(124,77,255,.3); }
html[data-theme="light"] body .hx-arrow { background: #ffffff; border: 1px solid rgba(0,184,230,.6); color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.45); }
html[data-theme="light"] body .hm-menu { background: #ffffff; border-color: rgba(0,184,230,.5); box-shadow: 0 0 30px -4px rgba(0,184,230,.5); }
html[data-theme="light"] body .hm-menu a:hover, html[data-theme="light"] body .hm-menu button:hover { background: rgba(0,184,230,.12); }
html[data-theme="light"] body .hx-card { background: #ffffff; border-color: rgba(124,77,255,.4); box-shadow: 0 0 22px -12px rgba(124,77,255,.5); }
html[data-theme="light"] body .hx-search { background: #ffffff; border-color: rgba(124,77,255,.5); }
html[data-theme="light"] body .hx-search:focus-within { border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 16px rgba(0,184,230,.45); }
html[data-theme="light"] body .hx-btn, html[data-theme="light"] body .hx-more { background: #ffffff; }
html[data-theme="light"] body .history-empty { background: #ffffff; border: 1px dashed rgba(0,184,230,.5); }

CSS;

vs_page_start('Watch history', 'history', $extraCss);

$icoTrash = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>';
$icoBolt = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 2 4 14h6.5L9.5 22 20 9.5h-6.8z"/></svg>';
$icoClose = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
$icoCalendar = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4M7.5 13.5h.01M12 13.5h.01M16.5 13.5h.01M7.5 17h.01M12 17h.01"/></svg>';
$icoPlay = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>';
?>
<link rel="stylesheet" href="search-suggest.css">
<script src="search-suggest.js"></script>
<div class="history-page">

  <section class="hx-banner">
    <svg class="hx-waves" viewBox="0 0 1400 150" preserveAspectRatio="none" aria-hidden="true">
      <defs>
        <linearGradient id="hxw1" x1="0" x2="1"><stop offset="0" stop-color="#00e5ff" stop-opacity="0"/><stop offset=".45" stop-color="#6a7bff"/><stop offset="1" stop-color="#b84dff" stop-opacity=".2"/></linearGradient>
        <linearGradient id="hxw2" x1="0" x2="1"><stop offset="0" stop-color="#8a5cff" stop-opacity="0"/><stop offset=".55" stop-color="#c43bff"/><stop offset="1" stop-color="#ff2e93"/></linearGradient>
      </defs>
      <path d="M0 125 C200 25 380 150 640 82 S1100 -8 1400 70" fill="none" stroke="url(#hxw1)" stroke-width="2"/>
      <path d="M0 142 C260 72 520 160 800 104 S1150 34 1400 116" fill="none" stroke="url(#hxw2)" stroke-width="2"/>
      <path d="M0 80 C240 115 520 12 820 68 S1180 112 1400 22" fill="none" stroke="url(#hxw2)" stroke-width="1" opacity=".5"/>
    </svg>
    <div class="hx-banner-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none" stroke="url(#hxIco)" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <defs><linearGradient id="hxIco" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6d5bff"/><stop offset="1" stop-color="#ff2e93"/></linearGradient></defs>
        <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>
      </svg>
    </div>
    <div class="hx-banner-text">
      <h1>Watch <span>History</span></h1>
      <p>Pick up where you left off &mdash; everything you watched, in one place.</p>
    </div>
    <div class="hx-stats" aria-label="Watch history numbers">
      <div class="hx-stat accent"><b><?= $h(vs_hx_views($countAll)) ?></b><span>Watched</span></div>
      <div class="hx-stat"><b><?= $h(vs_hx_views($countVideos)) ?></b><span>Videos</span></div>
      <div class="hx-stat"><b><?= $h(vs_hx_views($countShorts)) ?></b><span>Shorts</span></div>
      <div class="hx-stat"><b><?= $h(vs_hx_views($countToday)) ?></b><span>Today</span></div>
    </div>
  </section>

  <div class="history-layout">

    <div class="history-main">
      <nav class="hx-chips" aria-label="Filter watch history">
        <a class="hx-chip<?= $type === 'all' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'all')) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.8"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.8"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.8"/></svg>All<em><?= $h(vs_hx_views($countAll)) ?></em></a>
        <a class="hx-chip<?= $type === 'videos' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'videos')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="5" width="19" height="14" rx="3.5"/><path d="M10 9.2v5.6l4.8-2.8z" fill="currentColor"/></svg>Videos<em><?= $h(vs_hx_views($countVideos)) ?></em></a>
        <a class="hx-chip<?= $type === 'shorts' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'shorts')) ?>"><?= $icoBolt ?>Shorts<em><?= $h(vs_hx_views($countShorts)) ?></em></a>
      </nav>

      <?php if ($errorMessage !== ''): ?>
        <div class="history-alert" role="alert"><?= $h($errorMessage) ?></div>
      <?php endif; ?>
      <?php if ($successMessage !== ''): ?>
        <div class="history-alert history-success" role="status"><?= $h($successMessage) ?></div>
      <?php endif; ?>

      <?php if (!$entries && $errorMessage === ''): ?>
        <div class="history-empty">
          <?php if ($search !== ''): ?>
            <h2>No results for &ldquo;<?= $h($search) ?>&rdquo;</h2>
            <p>Try a different title or channel name.</p>
          <?php elseif ($type !== 'all'): ?>
            <h2>No <?= $type === 'shorts' ? 'Shorts' : 'videos' ?> in your history</h2>
            <p>Switch to &ldquo;All&rdquo; to see everything you watched.</p>
          <?php else: ?>
            <h2>Nothing watched yet</h2>
            <p>Videos and Shorts you watch will show up here.</p>
          <?php endif; ?>
          <a class="create-btn" href="index.php">Explore videos</a>
        </div>
      <?php endif; ?>

      <?php foreach ($groups as $group): ?>
        <section class="hx-day">
          <h2 class="hx-day-title"><?= $icoCalendar ?><?= $h($group['label']) ?><em><?= count($group['shorts']) + count($group['videos']) ?></em></h2>

          <?php if ($group['shorts']): ?>
            <section class="hx-shelf" aria-label="Shorts you watched">
              <div class="hx-shelf-head"><?= $icoBolt ?><h3>Shorts</h3></div>
              <div class="hx-rowwrap">
                <button type="button" class="hx-arrow prev" aria-label="Previous Shorts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5-7 7 7 7"/></svg></button>
                <div class="hx-row-shorts">
                  <?php foreach ($group['shorts'] as $entry):
                      $row = $entry['video'];
                      $videoId = (string)($row['id'] ?? '');
                      $href = 'shorts.php?id=' . urlencode($videoId);
                      $title = trim((string)($row['title'] ?? '')) ?: 'Untitled short';
                      $views = (int)($row['views'] ?? 0);
                      $thumb = vs_history_thumb($row);
                  ?>
                    <article class="hx-short" data-hx-item>
                      <a class="hx-short-thumb" href="<?= $h($href) ?>" aria-label="Watch <?= $h($title) ?>">
                        <?php if ($thumb !== ''): ?><img src="<?= $h($thumb) ?>" alt="" loading="lazy"><?php endif; ?>
                        <span class="hx-short-shade"></span>
                        <span class="hx-short-views"><?= $icoPlay ?><?= $h(vs_hx_views($views)) ?></span>
                      </a>
                      <form method="post" data-hx-remove>
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
                        <input type="hidden" name="q" value="<?= $h($search) ?>">
                        <input type="hidden" name="type" value="<?= $h($type) ?>">
                        <button type="submit" class="hx-x" title="Remove from watch history" aria-label="Remove <?= $h($title) ?> from watch history"><?= $icoClose ?></button>
                      </form>
                      <a class="hx-short-title" href="<?= $h($href) ?>"><?= vs_hx_mark($title, $search) ?></a>
                    </article>
                  <?php endforeach; ?>
                </div>
                <button type="button" class="hx-arrow next" aria-label="Next Shorts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></button>
              </div>
            </section>
          <?php endif; ?>

          <?php foreach ($group['videos'] as $entry):
              $row = $entry['video'];
              $videoId = (string)($row['id'] ?? '');
              $baseHref = 'watch.php?id=' . urlencode($videoId);
              $href = $baseHref;
              $progressSeconds = (int)($entry['progress_seconds'] ?? 0);
              $progressDuration = (int)($entry['duration_seconds'] ?? 0);
              $progressPercent = ($progressSeconds > 0 && $progressDuration > $progressSeconds + 5)
                  ? min(99, (int)round($progressSeconds * 100 / $progressDuration))
                  : 0;
              if ($progressPercent > 0) {
                  $href .= '&t=' . $progressSeconds;
              }
              $title = trim((string)($row['title'] ?? '')) ?: 'Untitled video';
              $channel = trim((string)($row['channel_name'] ?? ''));
              if ($channel === '') {
                  $channel = trim((string)($row['username'] ?? '')) ?: 'Unknown channel';
              }
              $profileName = trim((string)($row['username'] ?? '')) ?: $channel;
              $views = (int)($row['views'] ?? 0);
              $description = trim((string)($row['description'] ?? ''));
              $thumb = vs_history_thumb($row);
              $duration = vs_hx_duration($row['duration'] ?? '', $progressDuration);
          ?>
            <article class="hx-row" data-hx-item data-hx-row>
              <a class="hx-thumb" href="<?= $h($baseHref) ?>" aria-label="Watch <?= $h($title) ?>">
                <?php if ($thumb !== ''): ?>
                  <img src="<?= $h($thumb) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <span class="hx-thumb-empty"><?= $icoPlay ?></span>
                <?php endif; ?>
                <?php if ($duration !== ''): ?><span class="hx-duration"><?= $h($duration) ?></span><?php endif; ?>
                <?php if ($progressPercent > 0): ?>
                  <span class="hx-progress" role="progressbar" aria-label="Watched <?= $progressPercent ?> percent" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?>"><span style="width: <?= $progressPercent ?>%;"></span></span>
                <?php endif; ?>
              </a>

              <div class="hx-info">
                <a href="<?= $h($baseHref) ?>"><h3 class="hx-title"><?= vs_hx_mark($title, $search) ?></h3></a>
                <p class="hx-channel"><a href="profile.php?u=<?= urlencode($profileName) ?>"><?= vs_hx_mark($channel, $search) ?></a><?= vs_vip_badge($profileName, $conn, 15) ?></p>
                <p class="hx-meta"><?= $h(vs_hx_views($views)) ?> views &bull; Watched <?= $h(vs_hx_ago((int)$entry['watched_ts'])) ?></p>
                <?php if ($description !== ''): ?><p class="hx-desc"><?= $h($description) ?></p><?php endif; ?>
                <?php if ($progressPercent > 0): ?>
                  <a class="hx-resume" href="<?= $h($href) ?>"><?= $icoPlay ?>Resume from <?= $h(vs_hx_clock($progressSeconds)) ?> &middot; <?= $progressPercent ?>%</a>
                <?php endif; ?>
              </div>

              <div class="hx-tools">
                <form method="post" class="hx-quick" data-hx-remove>
                  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
                  <input type="hidden" name="q" value="<?= $h($search) ?>">
                  <input type="hidden" name="type" value="<?= $h($type) ?>">
                  <button type="submit" class="hx-iconbtn" title="Remove from watch history" aria-label="Remove <?= $h($title) ?> from watch history"><?= $icoClose ?></button>
                </form>
                <details class="hm">
                  <summary aria-label="More options for <?= $h($title) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></summary>
                  <div class="hm-menu">
                    <a href="profile.php?u=<?= urlencode($profileName) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>Go to channel</a>
                    <button type="button" data-hx-copy="<?= $h($baseHref) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>Copy link</button>
                    <form method="post" data-hx-remove>
                      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
                      <input type="hidden" name="q" value="<?= $h($search) ?>">
                      <input type="hidden" name="type" value="<?= $h($type) ?>">
                      <button type="submit"><?= $icoTrash ?>Remove from watch history</button>
                    </form>
                  </div>
                </details>
              </div>
            </article>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <?php if ($entries): ?>
        <div class="hx-more-wrap" hidden>
          <button type="button" class="hx-more" id="hxMore"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>Load more</button>
        </div>
      <?php endif; ?>
    </div>

    <aside class="history-side" aria-label="Watch history options">
      <form class="hx-search" method="get" action="history.php" role="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" id="hxSearch" name="q" value="<?= $h($search) ?>" placeholder="Search watch history" aria-label="Search watch history" autocomplete="off" data-vs-suggest-input="history">
        <kbd aria-hidden="true">/</kbd>
        <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= $h($type) ?>"><?php endif; ?>
      </form>

      <div class="hx-card">
        <div class="hx-status<?= $paused ? ' is-paused' : '' ?>" aria-live="polite">
          <span class="hx-dot" aria-hidden="true"></span>
          <div class="hx-status-text">
            <strong><?= $paused ? 'Watch history is paused' : 'Watch history is on' ?></strong>
            <small><?= $paused ? 'Videos and Shorts you watch are not being saved.' : 'Your watch history is being saved.' ?></small>
          </div>
        </div>
        <div class="hx-side-actions">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="<?= $paused ? 'resume' : 'pause' ?>">
            <input type="hidden" name="q" value="<?= $h($search) ?>">
            <input type="hidden" name="type" value="<?= $h($type) ?>">
            <button type="submit" class="hx-btn">
              <?php if ($paused): ?>
                <?= $icoPlay ?>Turn on watch history
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="5" y="4" width="5" height="16" rx="1.3"/><rect x="14" y="4" width="5" height="16" rx="1.3"/></svg>Pause watch history
              <?php endif; ?>
            </button>
          </form>
          <form method="post" onsubmit="return confirm('Clear your entire watch history?');">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="hx-btn danger"><?= $icoTrash ?>Clear all watch history</button>
          </form>
        </div>
      </div>

      <p class="hx-tip">Tip: press <strong>/</strong> to jump to search. Removing a video only takes it out of this list.</p>
    </aside>

  </div>
</div>
<div class="hx-toast" id="hxToast" role="status" aria-live="polite"></div>
<script>
(function () {
  var toastEl = document.getElementById('hxToast'), toastTimer;
  function toast(msg) {
    toastEl.textContent = msg; toastEl.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2400);
  }

  // "Load more": show 10 video rows at a time (everything is visible if JavaScript is off)
  var PAGE = 10, shown = PAGE;
  var days = Array.prototype.slice.call(document.querySelectorAll('.hx-day'));
  var more = document.getElementById('hxMore');
  var moreWrap = document.querySelector('.hx-more-wrap');
  function rows() { return Array.prototype.slice.call(document.querySelectorAll('[data-hx-row]')); }
  function render() {
    var all = rows();
    all.forEach(function (r, i) { r.hidden = i >= shown; });
    days.forEach(function (d) {
      var visibleRow = d.querySelector('[data-hx-row]:not([hidden])');
      var hasShelf = d.querySelector('.hx-shelf');
      var hasRows = d.querySelector('[data-hx-row]');
      d.hidden = !visibleRow && !hasShelf && !!hasRows;
    });
    if (moreWrap) { moreWrap.hidden = shown >= all.length; }
  }
  if (more) { more.addEventListener('click', function () { shown += PAGE; render(); }); }
  render();

  // Remove without reloading the page (falls back to a normal form post)
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-hx-remove]');
    if (!form || !window.fetch) return;
    e.preventDefault();
    var item = form.closest('[data-hx-item]');
    fetch(window.location.href, { method: 'POST', body: new FormData(form), headers: { 'X-Requested-With': 'fetch' }, credentials: 'same-origin' })
      .then(function (res) { return res.json(); })
      .then(function (data) {
        if (!data || !data.ok) { throw new Error((data && data.message) || 'failed'); }
        if (item) {
          item.classList.add('is-removing');
          setTimeout(function () {
            var day = item.closest('.hx-day');
            item.remove();
            if (day && !day.querySelector('[data-hx-item]')) { day.remove(); days = days.filter(function (d) { return d !== day; }); }
            render();
            if (!document.querySelector('[data-hx-item]')) { window.location.reload(); }
          }, 280);
        }
        toast('Removed from watch history');
      })
      .catch(function () { form.submit(); });
  });

  // Shorts shelf arrows
  document.querySelectorAll('.hx-rowwrap').forEach(function (wrap) {
    var row = wrap.querySelector('.hx-row-shorts'), next = wrap.querySelector('.hx-arrow.next'), prev = wrap.querySelector('.hx-arrow.prev');
    if (!row) return;
    function update() {
      if (next) next.classList.toggle('show', row.scrollWidth - row.clientWidth - row.scrollLeft > 6);
      if (prev) prev.classList.toggle('show', row.scrollLeft > 6);
    }
    if (next) next.addEventListener('click', function () { row.scrollBy({ left: Math.max(row.clientWidth * 0.8, 240), behavior: 'smooth' }); });
    if (prev) prev.addEventListener('click', function () { row.scrollBy({ left: -Math.max(row.clientWidth * 0.8, 240), behavior: 'smooth' }); });
    row.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  });

  // Copy link
  document.querySelectorAll('[data-hx-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var url = new URL(btn.getAttribute('data-hx-copy'), window.location.href).href;
      function done() { toast('Link copied'); var d = btn.closest('details'); if (d) d.removeAttribute('open'); }
      function fallback() {
        var t = document.createElement('textarea');
        t.value = url; t.style.position = 'fixed'; t.style.opacity = '0';
        document.body.appendChild(t); t.select();
        try { document.execCommand('copy'); done(); } catch (err) { window.prompt('Copy this link:', url); }
        document.body.removeChild(t);
      }
      if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, fallback); } else { fallback(); }
    });
  });

  // Menus: close on outside click / Escape; "/" focuses search
  document.addEventListener('click', function (e) {
    document.querySelectorAll('.hm[open]').forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { document.querySelectorAll('.hm[open]').forEach(function (d) { d.removeAttribute('open'); }); }
    var tag = (document.activeElement && document.activeElement.tagName) || '';
    if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(tag) && !e.ctrlKey && !e.metaKey) {
      var s = document.getElementById('hxSearch');
      if (s) { e.preventDefault(); s.focus(); }
    }
  });
})();
</script>
<?php
vs_page_end();