<?php
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
(static function (): void {
    $dirs = [__DIR__ . '/..', __DIR__];
    foreach ($dirs as $d) {
        $f = $d . '/report-helpers.php';
        if (is_file($f)) { require_once $f; return; }
    }
    foreach ($dirs as $d) {                      // tolerate names like 123_report-helpers.php
        $hits = glob($d . '/*report-helpers.php') ?: [];
        if ($hits) { require_once $hits[0]; return; }
    }
    http_response_code(500);
    exit('report-helpers.php was not found. Put it in ' . htmlspecialchars(realpath(__DIR__ . '/..') ?: __DIR__ . '/..') . ' (the main VibeStream folder).');
})();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];
$me   = trim((string)($_SESSION['AdminSession'] ?? 'Admin'));

$status = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'reviewed', 'all'], true)) {
    $status = 'open';
}
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'video', 'short'], true)) {
    $type = 'all';
}

$reasons = vs_report_reasons() + ['child' => 'Child safety', 'other' => 'Something else'];   // older reports keep their old wording
$repLetter = static function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'U', 0, 1));
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/><text x="32" y="43" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">' . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>');
};
$fatal   = '';
$groups  = [];
$admins  = [];
$notesByContent = [];
$adminLoadError = '';
$counts  = ['open' => 0, 'videos' => 0, 'shorts' => 0, 'total' => 0];

$msgs = [
    'dismissed' => 'Report dismissed. The video stays online.',
    'removed'   => 'Video removed and its reports closed.',
    'assigned'  => 'Report assignment updated.',
    'noted'     => 'Internal note added.',
    'note_empty' => 'Enter a note before saving.',
    'invalid_assignee' => 'Choose an admin from the assignment list.',
    'expired'   => 'Session expired. Try again.',
    'failed'    => 'Something went wrong. Please try again.',
];

$pdo = vs_report_pdo();
if (!$pdo) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        vs_reports_ensure_table($pdo);
        vs_report_notes_ensure_table($pdo);

        $userColumns = vsr_cols($pdo, 'users');
        $userNameColumn = $userColumns ? vsr_pick($userColumns, ['username', 'user_name', 'login', 'uname']) : null;
        $userRoleColumn = $userColumns ? vsr_pick($userColumns, ['role', 'user_role', 'usertype', 'user_type', 'account_type']) : null;
        if ($userNameColumn && $userRoleColumn) {
            $adminQuery = $pdo->prepare(
                'SELECT DISTINCT ' . vsr_q($userNameColumn) . ' AS username FROM `users`
                 WHERE LOWER(TRIM(' . vsr_q($userRoleColumn) . ')) = :role
                   AND ' . vsr_q($userNameColumn) . ' IS NOT NULL
                   AND TRIM(' . vsr_q($userNameColumn) . ') <> \'\'
                 ORDER BY username'
            );
            $adminQuery->execute([':role' => 'admin']);
            foreach ($adminQuery->fetchAll(PDO::FETCH_COLUMN) as $adminName) {
                $adminName = trim((string)$adminName);
                if ($adminName !== '') {
                    $admins[mb_strtolower($adminName, 'UTF-8')] = $adminName;
                }
            }
            $admins[mb_strtolower($me, 'UTF-8')] = $me;
            $admins = array_values($admins);
        } else {
            $admins[mb_strtolower($me, 'UTF-8')] = $me;
            $admins = array_values($admins);
            $adminLoadError = 'The admin list could not be loaded, so assignment is limited to your account.';
            error_log('Reported content assignment list could not find the users role and username columns.');
        }

        // ---- actions ----
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code   = 'failed';
            $action = (string)($_POST['action'] ?? '');
            $tbl    = (string)($_POST['table'] ?? '');
            $cid    = trim((string)($_POST['id'] ?? ''));
            $now    = date('Y-m-d H:i:s');
            $reportExists = true;
            if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))
                && in_array($tbl, ['videos', 'shorts'], true)
                && $cid !== ''
                && in_array($action, ['assign', 'add_note'], true)) {
                $check = $pdo->prepare(
                    'SELECT 1 FROM `content_reports` WHERE `content_table` = :table AND `content_id` = :id LIMIT 1'
                );
                $check->execute([':table' => $tbl, ':id' => $cid]);
                $reportExists = $check->fetchColumn() !== false;
            }

            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                $code = 'expired';
            } elseif (!in_array($tbl, ['videos', 'shorts'], true) || $cid === '') {
                $code = 'failed';
            } elseif (!$reportExists) {
                $code = 'failed';
            } elseif ($action === 'assign') {
                $assigneeValue = $_POST['assigned_to'] ?? '';
                $assignee = is_string($assigneeValue) ? trim($assigneeValue) : '';
                $allowedAdmins = array_map(static function (string $name): string {
                    return mb_strtolower($name, 'UTF-8');
                }, $admins);
                if (!is_string($assigneeValue) || ($assignee !== '' && !in_array(mb_strtolower($assignee, 'UTF-8'), $allowedAdmins, true))) {
                    $code = 'invalid_assignee';
                } else {
                    $up = $pdo->prepare(
                        'UPDATE `content_reports` SET `assigned_to` = :assignee
                         WHERE `content_table` = :table AND `content_id` = :id AND `status` = \'open\''
                    );
                    $up->execute([
                        ':assignee' => $assignee !== '' ? $assignee : null,
                        ':table' => $tbl,
                        ':id' => $cid,
                    ]);
                    $code = 'assigned';
                }
            } elseif ($action === 'add_note') {
                $noteValue = $_POST['note'] ?? '';
                $note = is_string($noteValue) ? trim($noteValue) : '';
                if ($note === '') {
                    $code = 'note_empty';
                } elseif (!is_string($noteValue) || mb_strlen($note, 'UTF-8') > 2000) {
                    $code = 'failed';
                } else {
                    $insert = $pdo->prepare(
                        'INSERT INTO `content_report_notes` (`content_table`, `content_id`, `admin`, `note`, `created_at`)
                         VALUES (:table, :id, :admin, :note, :created)'
                    );
                    $insert->execute([
                        ':table' => $tbl,
                        ':id' => $cid,
                        ':admin' => $me,
                        ':note' => $note,
                        ':created' => $now,
                    ]);
                    $code = 'noted';
                }
            } elseif ($action === 'dismiss') {
                $up = $pdo->prepare(
                    "UPDATE `content_reports` SET `status` = 'dismissed', `reviewed_by` = :b, `reviewed_at` = :a
                     WHERE `content_table` = :t AND `content_id` = :i AND `status` = 'open'"
                );
                $up->execute([':b' => $me, ':a' => $now, ':t' => $tbl, ':i' => $cid]);
                $code = 'dismissed';
            } elseif ($action === 'remove') {
                $c   = vs_cols($pdo, $tbl);
                $idC = $c ? vs_pick($c, ['id', 'video_id']) : null;
                if ($idC) {
                    $pdo->beginTransaction();
                    $del = $pdo->prepare('DELETE FROM ' . vs_q($tbl) . ' WHERE ' . vs_q($idC) . ' = :i LIMIT 1');
                    $del->execute([':i' => $cid]);
                    $up = $pdo->prepare(
                        "UPDATE `content_reports` SET `status` = 'removed', `reviewed_by` = :b, `reviewed_at` = :a
                         WHERE `content_table` = :t AND `content_id` = :i AND `status` = 'open'"
                    );
                    $up->execute([':b' => $me, ':a' => $now, ':t' => $tbl, ':i' => $cid]);
                    $pdo->commit();
                    $code = 'removed';
                }
            }
            header('Location: reported.php?status=' . urlencode($status) . '&type=' . urlencode($type) . '&msg=' . urlencode($code));
            exit;
        }

        // ---- numbers for the cards ----
        $counts['open']  = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT `content_table`, `content_id`
             FROM `content_reports` WHERE `status` = \'open\'
             GROUP BY `content_table`, `content_id`) AS `open_reported_content`'
        )->fetchColumn();
        $counts['total'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT `content_table`, `content_id`
             FROM `content_reports` GROUP BY `content_table`, `content_id`) AS `all_reported_content`'
        )->fetchColumn();
        $counts['videos'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT `content_table`, `content_id`
             FROM `content_reports` WHERE `status` = \'open\' AND `content_type` = \'video\'
             GROUP BY `content_table`, `content_id`) AS `video_reported_content`'
        )->fetchColumn();
        $counts['shorts'] = (int)$pdo->query(
            'SELECT COUNT(*) FROM (SELECT `content_table`, `content_id`
             FROM `content_reports` WHERE `status` = \'open\' AND `content_type` = \'short\'
             GROUP BY `content_table`, `content_id`) AS `short_reported_content`'
        )->fetchColumn();

        // ---- the list ----
        $where  = [];
        $params = [];
        if ($status === 'open') {
            $where[] = "`status` = 'open'";
        } elseif ($status === 'reviewed') {
            $where[] = "`status` <> 'open'";
        }
        if ($type !== 'all') {
            $where[] = '`content_type` = :ty';
            $params[':ty'] = $type;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $st = $pdo->prepare(
            'SELECT `id`, `content_table`, `content_id`, `content_type`, `reason`, `details`, `reporter`, `status`, `created_at`, `assigned_to`
             FROM `content_reports`' . $w . ' ORDER BY `created_at` DESC LIMIT 3000'
        );
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['content_table'] . '|' . $r['content_id'];
            if (!isset($groups[$k])) {
                $groups[$k] = [
                    'table' => $r['content_table'], 'id' => $r['content_id'], 'type' => $r['content_type'],
                    'reports' => [], 'open' => 0, 'last' => $r['created_at'], 'reasons' => [], 'status' => $r['status'],
                    'assigned_to' => trim((string)($r['assigned_to'] ?? '')),
                ];
            }
            $groups[$k]['reports'][] = $r;
            if ($groups[$k]['assigned_to'] === '' && trim((string)($r['assigned_to'] ?? '')) !== '') {
                $groups[$k]['assigned_to'] = trim((string)$r['assigned_to']);
            }
            if ($r['status'] === 'open') {
                $groups[$k]['open']++;
            }
            $groups[$k]['reasons'][$r['reason']] = ($groups[$k]['reasons'][$r['reason']] ?? 0) + 1;
        }
        uasort($groups, static function (array $a, array $b): int {
            return [$b['open'] > 0, count($b['reports']), $b['last']] <=> [$a['open'] > 0, count($a['reports']), $a['last']];
        });
        $groups = array_slice($groups, 0, 100, true);
        foreach ($groups as $k => $g) {
            $groups[$k]['info'] = vs_reports_lookup($pdo, $g['table'], $g['id']);
        }
        if ($groups) {
            $contentConditions = [];
            $noteParams = [];
            foreach (array_values($groups) as $index => $group) {
                $contentConditions[] = "(`content_table` = :table$index AND `content_id` = :id$index)";
                $noteParams[":table$index"] = $group['table'];
                $noteParams[":id$index"] = $group['id'];
            }
            $noteQuery = $pdo->prepare(
                'SELECT `content_table`, `content_id`, `admin`, `note`, `created_at`
                 FROM `content_report_notes` WHERE ' . implode(' OR ', $contentConditions)
                 . ' ORDER BY `created_at` DESC, `id` DESC LIMIT 2000'
            );
            $noteQuery->execute($noteParams);
            foreach ($noteQuery->fetchAll(PDO::FETCH_ASSOC) as $note) {
                $key = $note['content_table'] . '|' . $note['content_id'];
                $notesByContent[$key][] = $note;
            }
            foreach ($notesByContent as &$itemNotes) {
                $itemNotes = array_reverse($itemNotes);
            }
            unset($itemNotes);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('reported.php failed: ' . $e->getMessage());
        $fatal = 'Could not load the reports. Please try again.';
    }
}

$flash = $msgs[$_GET['msg'] ?? ''] ?? '';
$qs = static fn(array $over): string => 'reported.php?' . http_build_query(array_merge(['status' => $GLOBALS['status'], 'type' => $GLOBALS['type']], $over));

// ---- extra data for the redesigned page: 12-day curves for the stat cards + thumbnails ----
$repSeries = ['open' => array_fill(0, 12, 0), 'video' => array_fill(0, 12, 0), 'short' => array_fill(0, 12, 0), 'total' => array_fill(0, 12, 0)];
if ($pdo && $fatal === '') {
    try {
        $since = date('Y-m-d 00:00:00', strtotime('-11 days'));
        $q = $pdo->prepare('SELECT DATE(`created_at`) AS d, `content_type` AS t, `status` AS s, COUNT(*) AS c FROM `content_reports` WHERE `created_at` >= :s GROUP BY DATE(`created_at`), `content_type`, `status`');
        $q->execute([':s' => $since]);
        $day0 = strtotime(date('Y-m-d', strtotime('-11 days')));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $i = (int)round((strtotime((string)$r['d']) - $day0) / 86400);
            if ($i < 0 || $i > 11) { continue; }
            $c = (int)$r['c'];
            $repSeries['total'][$i] += $c;
            if ($r['s'] === 'open') { $repSeries['open'][$i] += $c; }
            if ($r['t'] === 'video') { $repSeries['video'][$i] += $c; } elseif ($r['t'] === 'short') { $repSeries['short'][$i] += $c; }
        }
    } catch (Throwable $e) {
        // the curves are decoration: never break the page
    }
}
if (!function_exists('vsr_spark')) {
    function vsr_spark(array $v, string $gid): string
    {
        $n = count($v);
        if ($n < 2) { return ''; }
        $w = 140; $hgt = 44;
        $min = min($v); $max = max($v);
        $pts = []; $lx = 0; $ly = 0;
        foreach (array_values($v) as $i => $val) {
            $x = 3 + $i * ($w - 6) / ($n - 1);
            $y = ($max === $min) ? $hgt - 6 : ($hgt - 5) - (($val - $min) / ($max - $min)) * ($hgt - 11);
            $pts[] = round($x, 1) . ',' . round($y, 1);
            $lx = round($x, 1); $ly = round($y, 1);
        }
        return '<svg class="rp-spark" viewBox="0 0 ' . $w . ' ' . $hgt . '" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#8a5cff"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>'
            . '<polyline fill="none" stroke="url(#' . $gid . ')" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/><circle cx="' . $lx . '" cy="' . $ly . '" r="3" fill="#22d3ee"/></svg>';
    }
}
if (!function_exists('vsr_placeholder')) {
    function vsr_placeholder(bool $short): string
    {
        $w = $short ? 90 : 160; $hh = $short ? 120 : 90;
        return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $hh . '"><rect width="100%" height="100%" fill="#2a2d3a"/><path d="M' . ($w / 2 - 9) . ' ' . ($hh / 2 - 12) . 'l22 12-22 12z" fill="#7c8099"/></svg>');
    }
}
if (!function_exists('vsr_thumb')) {
    /** Picture of a reported video: YouTube picture, saved thumbnail, or a grey placeholder. */
    function vsr_thumb(?PDO $pdo, string $table, string $id, bool $short): string
    {
        $row = [];
        if ($pdo && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            try {
                $st = $pdo->prepare('SELECT * FROM `' . $table . '` WHERE `id` = ? LIMIT 1');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $row = [];
            }
        }
        $cands = [$id];
        foreach (['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url'] as $c) {
            if (!empty($row[$c])) { $cands[] = (string)$row[$c]; }
        }
        foreach ($cands as $v) {
            $v = trim($v);
            if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) { return 'https://i.ytimg.com/vi/' . $v . '/hqdefault.jpg'; }
            if (preg_match('#(?:youtube\.com/(?:watch\?.*?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $v, $m)) { return 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg'; }
        }
        foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'image', 'img', 'poster'] as $c) {
            $v = trim((string)($row[$c] ?? ''));
            if ($v !== '') { return preg_match('#^(https?:)?//|^data:#i', $v) ? $v : '../' . ltrim($v, '/'); }
        }
        return vsr_placeholder($short);
    }
}
if (!function_exists('vsr_reason_class')) {
    function vsr_reason_class(string $key): string
    {
        $k = strtolower($key);
        if (strpos($k, 'spam') !== false) { return 'r-red'; }
        if (strpos($k, 'inappropriate') !== false || strpos($k, 'sexual') !== false || strpos($k, 'nudity') !== false) { return 'r-amber'; }
        if (strpos($k, 'harass') !== false || strpos($k, 'hate') !== false || strpos($k, 'abuse') !== false) { return 'r-violet'; }
        if (strpos($k, 'fake') !== false || strpos($k, 'mislead') !== false || strpos($k, 'scam') !== false) { return 'r-green'; }
        if (strpos($k, 'copyright') !== false) { return 'r-cyan'; }
        return 'r-pink';
    }
}
$repWhen = static function (string $t): array {
    $ts = strtotime($t);
    return $ts ? [date('M j, Y', $ts), date('g:i A', $ts)] : ['-', ''];
};

include "header.php";
vs_print_styles();
$statusUrls = ['open' => 'Status: Open', 'reviewed' => 'Status: Reviewed', 'all' => 'Status: All'];
$typeUrls = ['all' => 'Type: All', 'video' => 'Type: Videos', 'short' => 'Type: Shorts'];
?>
<style>
  .rp-page{--rp-card:linear-gradient(155deg,rgba(16,24,68,.96),rgba(7,12,39,.98));--rp-line:rgba(112,104,255,.38);--rp-row:rgba(112,104,255,.16);--rp-text:#eaf0ff;--rp-muted:#9aa8d1;--rp-field:rgba(8,14,46,.9);--rp-hover:rgba(124,77,255,.10);color:var(--rp-text)}
  html[data-theme="light"] .rp-page,html[data-bs-theme="light"] .rp-page{--rp-card:linear-gradient(155deg,#ffffff,#f6f7ff);--rp-line:rgba(124,77,255,.30);--rp-row:rgba(124,77,255,.14);--rp-text:#171b3b;--rp-muted:#5d6688;--rp-field:#ffffff;--rp-hover:rgba(124,77,255,.07)}
  html[data-theme="dark"] .rp-page,html[data-bs-theme="dark"] .rp-page{--rp-card:linear-gradient(155deg,rgba(16,24,68,.96),rgba(7,12,39,.98));--rp-line:rgba(112,104,255,.38);--rp-row:rgba(112,104,255,.16);--rp-text:#eaf0ff;--rp-muted:#9aa8d1;--rp-field:rgba(8,14,46,.9);--rp-hover:rgba(124,77,255,.10)}
  .rp-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:24px 30px;border-radius:20px;border:1px solid rgba(143,115,255,.55);background:linear-gradient(110deg,#1b1260 0%,#3b1b9c 52%,#12104a 100%);color:#fff;position:relative;overflow:hidden}
  .rp-hero-ico{width:84px;height:84px;flex:none;display:grid;place-items:center}
  .rp-hero-ico svg{width:100%;height:100%;display:block}
  .rp-hero h1{font-size:clamp(1.7rem,3.2vw,2.5rem);font-weight:800;margin:0 0 4px;line-height:1.1;color:#fff}
  .rp-hero h1 span{color:#c084fc}
  .rp-hero p{margin:0;color:rgba(255,255,255,.82)}
  html[data-theme="light"] .rp-hero{border-color:rgba(124,77,255,.28);background:linear-gradient(110deg,#f4efff 0%,#eee7ff 52%,#edf5ff 100%);color:#171b3b}
  html[data-theme="light"] .rp-hero h1{color:#171b3b}
  html[data-theme="light"] .rp-hero h1 span{color:#7c4dff}
  html[data-theme="light"] .rp-hero p{color:#5d6688}
  html[data-theme="light"] .rp-back{border-color:rgba(124,77,255,.30);background:rgba(124,77,255,.08);color:#4b3d8f}
  html[data-theme="light"] .rp-back:hover{background:rgba(124,77,255,.14);color:#4b3d8f}
  html[data-theme="light"] .rp-safe{border-color:rgba(124,77,255,.20);background:rgba(255,255,255,.72);color:#171b3b}
  .rp-hero-right{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;position:relative;z-index:1}
  .rp-back{display:inline-flex;align-items:center;gap:8px;padding:9px 18px;border-radius:999px;border:1px solid rgba(255,255,255,.4);background:rgba(10,12,50,.55);color:#fff;font-weight:600;text-decoration:none;white-space:nowrap}
  .rp-back:hover{color:#fff;background:rgba(255,255,255,.14)}
  .rp-safe{display:flex;align-items:center;gap:14px;padding:12px 20px;border-radius:16px;border:1px solid rgba(255,255,255,.2);background:rgba(10,12,50,.5);font-size:1.05rem;line-height:1.3}
  .rp-safe svg{width:46px;height:46px;flex:none}
  .rp-controls{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) minmax(0,2.4fr);gap:14px;margin-top:16px}
  .rp-sel,.rp-search{position:relative;border-radius:16px;border:1px solid var(--rp-line);background:var(--rp-card)}
  .rp-sel i,.rp-search i{position:absolute;left:18px;top:50%;transform:translateY(-50%);font-size:1.25rem;color:#9f86ff;pointer-events:none}
  .rp-sel select,.rp-search input{width:100%;padding:16px 44px 16px 52px;border:0;border-radius:16px;background:transparent;color:var(--rp-text);font-size:1rem;font-weight:600;appearance:none;-webkit-appearance:none}
  .rp-sel select option{color:#171b3b;background:#fff}
  html[data-theme="dark"] .rp-sel select option,html[data-bs-theme="dark"] .rp-sel select option{color:#eaf0ff;background:#0c071c}
  .rp-sel:after{content:"";position:absolute;right:18px;top:50%;width:9px;height:9px;border-right:2px solid var(--rp-muted);border-bottom:2px solid var(--rp-muted);transform:translateY(-70%) rotate(45deg);pointer-events:none}
  .rp-search input{padding-right:16px;font-weight:500}
  .rp-search input::placeholder{color:var(--rp-muted)}
  .rp-sel select:focus,.rp-search input:focus{outline:none}
  .rp-sel:focus-within,.rp-search:focus-within{border-color:#22d3ee}
  .rp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:14px}
  .rp-stat{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:16px;border:1px solid var(--rp-line);background:var(--rp-card);min-width:0;overflow:hidden}
  .rp-stat-ico{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-size:24px;flex:none;color:#fff}
  .rp-stat-ico.open{background:linear-gradient(135deg,#8a5cff,#6d28d9)}
  .rp-stat-ico.video{background:rgba(124,77,255,.28);color:#a78bfa}
  .rp-stat-ico.short{background:rgba(138,92,255,.22);color:#a78bfa}
  .rp-stat-ico.total{background:rgba(124,77,255,.2);color:#a78bfa}
  .rp-stat small{display:block;color:var(--rp-muted);font-size:.9rem}
  .rp-stat strong{font-size:1.9rem;line-height:1.1;font-weight:800}
  .rp-spark{margin-left:auto;width:36%;max-width:130px;min-width:60px;height:44px;flex:none}
  .rp-card{margin-top:14px;border-radius:18px;border:1px solid var(--rp-line);background:var(--rp-card);overflow:hidden}
  .rp-table{width:100%;border-collapse:collapse;table-layout:fixed}
  .rp-table th{padding:16px 10px;font-size:.88rem;font-weight:700;text-align:left;white-space:nowrap;border-bottom:1px solid var(--rp-line);color:var(--rp-text)}
  .rp-table td{padding:10px;vertical-align:middle;border-bottom:1px solid var(--rp-row);overflow:hidden}
  .rp-table .t-act{text-align:right}
  .rp-group:hover>tr.rp-main>td{background:var(--rp-hover)}
  .rp-no{color:var(--rp-muted);font-weight:600}
  .rp-thumb{display:block;width:100%;max-width:118px;aspect-ratio:16/9;border-radius:10px;overflow:hidden;background:#2a2d3a}
  .rp-thumb.short{max-width:44px;aspect-ratio:9/16}
  .rp-thumb img{width:100%;height:100%;object-fit:cover;display:block}
  .rp-title{font-weight:600;line-height:1.3;overflow-wrap:anywhere;color:var(--rp-text);text-decoration:none}
  .rp-title:hover{color:#a78bfa}
  .rp-id{font-size:.76rem;color:var(--rp-muted);margin-top:2px;overflow-wrap:anywhere}
  .rp-type{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:.82rem;font-weight:600;border:1px solid}
  .rp-type.video{color:#a78bfa;border-color:rgba(167,139,250,.65);background:rgba(124,77,255,.12)}
  .rp-type.short{color:#38bdf8;border-color:rgba(56,189,248,.65);background:rgba(56,189,248,.10)}
  .rp-by{display:flex;align-items:center;gap:9px;min-width:0}
  .rp-by img{width:36px;height:36px;border-radius:50%;object-fit:cover;flex:none}
  .rp-by span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .rp-by small{color:var(--rp-muted);margin-left:4px}
  .rp-reasons{display:flex;flex-wrap:wrap;gap:6px}
  .rp-reason{display:inline-block;padding:3px 12px;border-radius:999px;font-size:.8rem;font-weight:600;border:1px solid;white-space:nowrap}
  .r-red{color:#ff6b7a;border-color:#ff4d6d;background:rgba(255,77,109,.14)}
  .r-amber{color:#ffb44a;border-color:#f59e0b;background:rgba(245,158,11,.16)}
  .r-violet{color:#b79bff;border-color:#8a5cff;background:rgba(138,92,255,.16)}
  .r-green{color:#34d399;border-color:#10b981;background:rgba(16,185,129,.14)}
  .r-cyan{color:#38d5f5;border-color:#22d3ee;background:rgba(34,211,238,.12)}
  .r-pink{color:#f472b6;border-color:#ec4899;background:rgba(236,72,153,.13)}
  .rp-date{display:flex;gap:8px;align-items:flex-start;font-size:.86rem;line-height:1.3}
  .rp-date i{color:var(--rp-muted);margin-top:2px}
  .rp-date small{display:block;color:var(--rp-muted)}
  .rp-status{display:inline-flex;align-items:center;gap:7px;padding:4px 14px;border-radius:999px;font-size:.84rem;font-weight:600;border:1px solid;white-space:nowrap}
  .rp-status:before{content:"";width:10px;height:10px;border-radius:50%;background:currentColor}
  .rp-status.open{color:#ff6b7a;border-color:#ff4d6d;background:rgba(255,77,109,.12)}
  .rp-status.review{color:#ffb44a;border-color:#f59e0b;background:rgba(245,158,11,.14)}
  .rp-status.done{color:#34d399;border-color:#10b981;background:rgba(16,185,129,.12)}
  .rp-acts{display:flex;justify-content:flex-end;gap:6px;flex-wrap:nowrap}
  .rp-acts form{margin:0;display:inline}
  .rp-btn{display:inline-grid;place-items:center;width:34px;height:34px;border-radius:9px;border:1px solid transparent;color:#fff;text-decoration:none;font-size:15px;cursor:pointer;padding:0}
  .rp-btn:hover{color:#fff;filter:brightness(1.15)}
  .rp-btn.view{background:#3d2bb8;border-color:#6a55ff}
  .rp-btn.edit{background:#5a3fd6;border-color:#8a73ff}
  .rp-btn.ok{background:#0f8f5f;border-color:#22c777}
  .rp-btn.del{background:#e0242c;border-color:#ff5560}
  .rp-detail td{background:rgba(124,77,255,.06);padding:18px 22px}
  .rp-detail-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(0,1fr) minmax(0,1fr);gap:22px}
  .rp-detail h3{font-size:.95rem;font-weight:700;margin:0 0 10px;color:var(--rp-text)}
  .rp-one{display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;font-size:.88rem}
  .rp-one img{width:32px;height:32px;border-radius:50%;object-fit:cover;flex:none}
  .rp-one .muted,.rp-detail .muted{color:var(--rp-muted)}
  .rp-detail select,.rp-detail textarea{width:100%;padding:9px 12px;border-radius:10px;border:1px solid var(--rp-line);background:var(--rp-field);color:var(--rp-text)}
  .rp-detail select option{color:#171b3b;background:#fff}
  .rp-mini{display:inline-block;margin-top:8px;padding:7px 16px;border-radius:10px;border:1px solid var(--rp-line);background:transparent;color:var(--rp-text);font-weight:600;font-size:.85rem;cursor:pointer}
  .rp-mini:hover{border-color:#8f73ff}
  .report-notes{margin:8px 0}
  .report-note{padding:8px 10px;margin:6px 0;border-radius:8px;background:rgba(128,128,128,.10);white-space:pre-wrap;overflow-wrap:anywhere;font-size:.86rem}
  .report-note-meta{font-size:.75rem;color:var(--rp-muted);margin-bottom:3px}
  .rp-foot{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;padding:14px 20px;border-top:1px solid var(--rp-line)}
  .rp-foot span{font-size:.88rem;color:var(--rp-muted)}
  .rp-pager{display:flex;gap:6px;flex-wrap:wrap}
  .rp-pager button{min-width:36px;height:36px;padding:0 12px;border-radius:9px;border:1px solid transparent;background:transparent;color:var(--rp-text);font-weight:600}
  .rp-pager button:hover:not(:disabled){border-color:var(--rp-line)}
  .rp-pager button.on{background:#7c4dff;border-color:#7c4dff;color:#fff}
  .rp-pager button:disabled{opacity:.4;cursor:default}
  .rp-empty{padding:40px 16px;text-align:center;color:var(--rp-muted)}
  .rp-alert{margin-top:14px;padding:12px 16px;border-radius:12px;border:1px solid var(--rp-line);background:var(--rp-card)}
  @media (max-width:1279.98px){.rp-table{table-layout:auto;min-width:1000px}.rp-card{overflow-x:auto}}
  @media (max-width:1199.98px){.rp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.rp-controls{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.rp-search{grid-column:1/-1}.rp-detail-grid{grid-template-columns:minmax(0,1fr)}}
  @media (max-width:575.98px){.rp-hero{padding:18px}.rp-hero-ico{width:56px;height:56px}.rp-hero-right{margin-left:0}.rp-stats,.rp-controls{grid-template-columns:minmax(0,1fr)}}
</style>
<style data-vs-neon>
  /* neon glow layer: the lightning button switches this block off */
  .rp-hero{box-shadow:0 0 38px -10px rgba(138,92,255,.75),inset 0 0 60px -30px rgba(0,229,255,.35)}
  .rp-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(520px 220px at 84% 20%,rgba(255,46,147,.26),transparent 70%),radial-gradient(420px 200px at 6% 100%,rgba(0,229,255,.16),transparent 70%);pointer-events:none}
  .rp-hero-ico svg{filter:drop-shadow(0 0 14px rgba(124,77,255,.95))}
  .rp-hero h1 span{background:linear-gradient(90deg,#a78bfa,#e879f9);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 12px rgba(192,132,252,.65))}
  .rp-safe{box-shadow:0 0 22px -8px rgba(34,211,238,.55)}
  .rp-safe svg{filter:drop-shadow(0 0 8px rgba(138,92,255,.9))}
  .rp-sel,.rp-search,.rp-stat,.rp-card{box-shadow:0 0 24px -10px rgba(112,104,255,.6),inset 0 1px 0 rgba(255,255,255,.04)}
  .rp-sel:focus-within,.rp-search:focus-within{box-shadow:0 0 0 1px #22d3ee,0 0 18px rgba(34,211,238,.4)}
  .rp-stat{transition:border-color .18s ease,box-shadow .18s ease}
  .rp-stat:hover{border-color:#22d3ee;box-shadow:0 0 24px -6px rgba(34,211,238,.55)}
  .rp-stat-ico{box-shadow:0 0 18px -4px #8a5cff}
  .rp-stat strong{text-shadow:0 0 14px rgba(167,139,250,.4)}
  .rp-spark{filter:drop-shadow(0 0 5px rgba(124,77,255,.8))}
  .rp-status.open{box-shadow:0 0 12px -3px rgba(255,77,109,.7)}
  .rp-status.review{box-shadow:0 0 12px -3px rgba(245,158,11,.7)}
  .rp-status.done{box-shadow:0 0 12px -3px rgba(16,185,129,.7)}
  .rp-reason,.rp-type{box-shadow:0 0 10px -3px currentColor}
  .rp-btn.view{box-shadow:0 0 12px -3px rgba(106,85,255,.9)}
  .rp-btn.edit{box-shadow:0 0 12px -3px rgba(138,115,255,.9)}
  .rp-btn.ok{box-shadow:0 0 12px -3px rgba(34,199,119,.9)}
  .rp-btn.del{box-shadow:0 0 12px -3px rgba(255,85,96,.9)}
  .rp-pager button.on{box-shadow:0 0 16px rgba(124,77,255,.85)}
  @media (prefers-reduced-motion:reduce){.rp-stat{transition:none}}
</style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 rp-page">
          <section class="rp-hero" aria-label="Reported content">
            <span class="rp-hero-ico"><svg viewBox="0 0 100 100" aria-hidden="true"><defs><linearGradient id="rpTri" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a855f7"/><stop offset="1" stop-color="#5b21b6"/></linearGradient></defs><path d="M50 8 L94 88 Q96 94 88 94 H12 Q4 94 6 88 Z" fill="url(#rpTri)" stroke="#c4a8ff" stroke-width="2" stroke-linejoin="round"/><rect x="45" y="34" width="10" height="30" rx="5" fill="#fff"/><circle cx="50" cy="76" r="6" fill="#fff"/></svg></span>
            <div>
              <h1>Reported <span>Content</span></h1>
              <p>Review and take action on reported videos and shorts.</p>
            </div>
            <div class="rp-hero-right">
              <a class="rp-back" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
              <div class="rp-safe"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M24 3 6 10v13c0 11 7.5 19 18 22 10.5-3 18-11 18-22V10z" fill="#5b4bd6" stroke="#b8a6ff" stroke-width="1.6"/><path d="M15 24l6.5 6.5L33 18" fill="none" stroke="#fff" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Keep VibeStream safe<br>for everyone.</span></div>
            </div>
          </section>

          <?php if ($flash !== ''): ?><div class="rp-alert" role="status"><?php echo vs_e($flash); ?></div><?php endif; ?>
          <?php if ($adminLoadError !== ''): ?><div class="rp-alert" role="status"><?php echo vs_e($adminLoadError); ?></div><?php endif; ?>

          <?php if ($fatal !== ''): ?>
            <div class="rp-alert"><?php echo vs_e($fatal); ?></div>
          <?php else: ?>

          <section class="rp-controls" aria-label="Filters">
            <div class="rp-sel"><i class="bi bi-funnel-fill" aria-hidden="true"></i>
              <select aria-label="Status" onchange="window.location.href=this.value">
                <?php foreach ($statusUrls as $sk => $sl): ?><option value="<?php echo vs_e($qs(['status' => $sk])); ?>"<?php echo $status === $sk ? ' selected' : ''; ?>><?php echo vs_e($sl); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="rp-sel"><i class="bi bi-play-btn-fill" aria-hidden="true"></i>
              <select aria-label="Type" onchange="window.location.href=this.value">
                <?php foreach ($typeUrls as $tk => $tl): ?><option value="<?php echo vs_e($qs(['type' => $tk])); ?>"<?php echo $type === $tk ? ' selected' : ''; ?>><?php echo vs_e($tl); ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="rp-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="rpSearch" placeholder="Search by title, uploader..." aria-label="Search reports"></div>
          </section>

          <section class="rp-stats" aria-label="Report numbers">
            <article class="rp-stat"><span class="rp-stat-ico open"><i class="bi bi-exclamation-lg" aria-hidden="true"></i></span><div><small>Open Reports</small><strong><?php echo number_format($counts['open']); ?></strong></div><?php echo vsr_spark($repSeries['open'], 'rpSpO'); ?></article>
            <article class="rp-stat"><span class="rp-stat-ico video"><i class="bi bi-play-btn-fill" aria-hidden="true"></i></span><div><small>Video Reports</small><strong><?php echo number_format($counts['videos']); ?></strong></div><?php echo vsr_spark($repSeries['video'], 'rpSpV'); ?></article>
            <article class="rp-stat"><span class="rp-stat-ico short"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span><div><small>Short Reports</small><strong><?php echo number_format($counts['shorts']); ?></strong></div><?php echo vsr_spark($repSeries['short'], 'rpSpS'); ?></article>
            <article class="rp-stat"><span class="rp-stat-ico total"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i></span><div><small>Total Reports</small><strong><?php echo number_format($counts['total']); ?></strong></div><?php echo vsr_spark($repSeries['total'], 'rpSpT'); ?></article>
          </section>

          <section class="rp-card" aria-label="Reported videos and shorts">
            <?php if (!$groups): ?>
              <p class="rp-empty mb-0">Nothing here. No <?php echo $status === 'open' ? 'open ' : ''; ?>reports<?php echo $type !== 'all' ? ' for ' . ($type === 'short' ? 'shorts' : 'videos') : ''; ?>.</p>
            <?php else: ?>
            <table class="rp-table">
              <colgroup><col style="width:4%"><col style="width:9%"><col style="width:17%"><col style="width:8%"><col style="width:14%"><col style="width:13%"><col style="width:11%"><col style="width:10%"><col style="width:14%"></colgroup>
              <thead>
                <tr><th>#</th><th>Thumbnail</th><th>Title</th><th>Type</th><th>Reported By</th><th>Reason</th><th>Date</th><th>Status</th><th class="t-act">Actions</th></tr>
              </thead>
              <?php $rowNo = 0; foreach ($groups as $g):
                  $rowNo++;
                  $info = $g['info'];
                  $isOpen = $g['open'] > 0;
                  $isShort = $g['type'] === 'short';
                  $gid = substr(hash('sha256', $g['table'] . '|' . $g['id']), 0, 12);
                  $first = $g['reports'][0];
                  $firstBy = (string)$first['reporter'];
                  $moreBy = count($g['reports']) - 1;
                  [$d1, $d2] = $repWhen((string)$g['last']);
                  $title = $info ? (string)$info['title'] : 'Removed or not found';
                  $who = $info && $info['who'] !== '' ? (string)$info['who'] : '';
                  $siteUrl = '../' . ($isShort ? 'shorts' : 'watch') . '.php?id=' . urlencode((string)$g['id']);
                  if ($isOpen && $g['assigned_to'] !== '') { $stClass = 'review'; $stLabel = 'Under review'; }
                  elseif ($isOpen) { $stClass = 'open'; $stLabel = 'Open'; }
                  else { $stClass = 'done'; $stLabel = ucfirst((string)$g['status']); }
                  $itemNotes = $notesByContent[$g['table'] . '|' . $g['id']] ?? [];
              ?>
              <tbody class="rp-group" data-search="<?php echo vs_e(mb_strtolower($title . ' ' . $who . ' ' . $g['id'] . ' ' . $firstBy, 'UTF-8')); ?>">
                <tr class="rp-main">
                  <td class="rp-no"><?php echo $rowNo; ?></td>
                  <td><a class="rp-thumb<?php echo $isShort ? ' short' : ''; ?>"<?php echo $info ? ' href="' . vs_e($siteUrl) . '" target="_blank" rel="noopener"' : ''; ?>><img src="<?php echo vs_e(vsr_thumb($pdo, (string)$g['table'], (string)$g['id'], $isShort)); ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?php echo vs_e(vsr_placeholder($isShort)); ?>';"></a></td>
                  <td>
                    <?php if ($info): ?><a class="rp-title" href="<?php echo vs_e($siteUrl); ?>" target="_blank" rel="noopener"><?php echo vs_e($title); ?></a><?php else: ?><strong>Removed or not found</strong><?php endif; ?>
                    <div class="rp-id">ID <?php echo vs_e($g['id']); ?><?php echo $who !== '' ? ' &bull; ' . vs_e($who) : ''; ?></div>
                  </td>
                  <td><span class="rp-type <?php echo $isShort ? 'short' : 'video'; ?>"><i class="bi <?php echo $isShort ? 'bi-lightning-charge-fill' : 'bi-play-circle-fill'; ?>" aria-hidden="true"></i> <?php echo $isShort ? 'Short' : 'Video'; ?></span></td>
                  <td>
                    <div class="rp-by">
                      <img src="<?php echo vs_e(avatar_url($firstBy)); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($repLetter($firstBy)); ?>';">
                      <span><?php echo vs_e($firstBy); ?><?php if ($moreBy > 0): ?><small>+<?php echo $moreBy; ?></small><?php endif; ?></span>
                    </div>
                  </td>
                  <td><div class="rp-reasons"><?php foreach (array_slice($g['reasons'], 0, 2, true) as $rk => $rc): ?><span class="rp-reason <?php echo vsr_reason_class((string)$rk); ?>"><?php echo vs_e($reasons[$rk] ?? $rk); ?></span><?php endforeach; ?><?php if (count($g['reasons']) > 2): ?><span class="rp-reason r-violet">+<?php echo count($g['reasons']) - 2; ?></span><?php endif; ?></div></td>
                  <td><div class="rp-date"><i class="bi bi-calendar3" aria-hidden="true"></i><div><?php echo vs_e($d1); ?><small><?php echo vs_e($d2); ?></small></div></div></td>
                  <td><span class="rp-status <?php echo $stClass; ?>"><?php echo vs_e($stLabel); ?></span></td>
                  <td>
                    <div class="rp-acts">
                      <?php if ($info): ?><a class="rp-btn view" href="<?php echo vs_e($siteUrl); ?>" target="_blank" rel="noopener" title="View on the site" aria-label="View on the site"><i class="bi bi-eye-fill" aria-hidden="true"></i></a><?php endif; ?>
                      <button type="button" class="rp-btn edit" data-detail="rpd-<?php echo vs_e($gid); ?>" title="Reports, assign and notes" aria-label="Reports, assign and notes" aria-expanded="false"><i class="bi bi-pencil-fill" aria-hidden="true"></i></button>
                      <?php if ($isOpen): ?>
                      <form method="post" action="<?php echo vs_e($qs([])); ?>">
                        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>"><input type="hidden" name="action" value="dismiss">
                        <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>"><input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                        <button type="submit" class="rp-btn ok" title="Dismiss the reports (the video stays online)" aria-label="Dismiss"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                      </form>
                      <?php if ($info): ?>
                      <form method="post" action="<?php echo vs_e($qs([])); ?>" onsubmit="return confirm('Remove this <?php echo $isShort ? 'short' : 'video'; ?> from the site? This cannot be undone.');">
                        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>"><input type="hidden" name="action" value="remove">
                        <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>"><input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                        <button type="submit" class="rp-btn del" title="Remove from the site" aria-label="Remove from the site"><i class="bi bi-trash-fill" aria-hidden="true"></i></button>
                      </form>
                      <?php endif; endif; ?>
                    </div>
                  </td>
                </tr>
                <tr class="rp-detail" id="rpd-<?php echo vs_e($gid); ?>" hidden>
                  <td colspan="9">
                    <div class="rp-detail-grid">
                      <div>
                        <h3><?php echo count($g['reports']); ?> report<?php echo count($g['reports']) === 1 ? '' : 's'; ?> on this <?php echo $isShort ? 'short' : 'video'; ?></h3>
                        <?php foreach ($g['reports'] as $r): $rn = (string)$r['reporter']; $rOpen = $r['status'] === 'open'; ?>
                          <div class="rp-one">
                            <img src="<?php echo vs_e(avatar_url($rn)); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($repLetter($rn)); ?>';">
                            <div>
                              <strong><?php echo vs_e($rn); ?></strong> <span class="muted">&middot; <?php echo vs_e(vs_ago($r['created_at'])); ?><?php echo $rOpen ? '' : ', ' . vs_e($r['status']); ?></span><br>
                              <span class="rp-reason <?php echo vsr_reason_class((string)$r['reason']); ?>"><?php echo vs_e($reasons[$r['reason']] ?? $r['reason']); ?></span>
                              <?php if ($r['details'] !== ''): ?><div class="muted mt-1">&ldquo;<?php echo vs_e($r['details']); ?>&rdquo;</div><?php endif; ?>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                      <div>
                        <h3>Assigned to</h3>
                        <?php if ($isOpen): ?>
                          <form method="post" action="<?php echo vs_e($qs([])); ?>">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>"><input type="hidden" name="action" value="assign">
                            <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>"><input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                            <select name="assigned_to" id="assignee-<?php echo vs_e($gid); ?>" aria-label="Assign to an admin">
                              <option value="">Unassigned</option>
                              <?php foreach ($admins as $adminName): ?>
                                <option value="<?php echo vs_e($adminName); ?>"<?php echo strcasecmp($g['assigned_to'], $adminName) === 0 ? ' selected' : ''; ?>><?php echo vs_e($adminName); ?></option>
                              <?php endforeach; ?>
                            </select>
                            <button type="submit" class="rp-mini">Assign</button>
                          </form>
                        <?php elseif ($g['assigned_to'] !== ''): ?>
                          <p class="muted mb-0">Assigned to <?php echo vs_e($g['assigned_to']); ?></p>
                        <?php else: ?>
                          <p class="muted mb-0">Not assigned.</p>
                        <?php endif; ?>
                      </div>
                      <div>
                        <h3>Internal notes<?php echo $itemNotes ? ' (' . count($itemNotes) . ')' : ''; ?></h3>
                        <?php if ($itemNotes): ?>
                          <div class="report-notes">
                            <?php foreach ($itemNotes as $note): ?>
                              <div class="report-note"><div class="report-note-meta"><?php echo vs_e($note['admin']); ?> &middot; <?php echo vs_e(vs_ago($note['created_at'])); ?></div><?php echo vs_e($note['note']); ?></div>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <p class="muted">No internal notes yet.</p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo vs_e($qs([])); ?>">
                          <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>"><input type="hidden" name="action" value="add_note">
                          <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>"><input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                          <textarea id="note-<?php echo vs_e($gid); ?>" name="note" rows="2" maxlength="2000" placeholder="Visible only to admins" aria-label="Add internal note"></textarea>
                          <button type="submit" class="rp-mini">Save note</button>
                        </form>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
              <?php endforeach; ?>
            </table>
            <p class="rp-empty" id="rpNone" hidden>No reports match your search.</p>
            <div class="rp-foot"><span id="rpInfo"></span><div class="rp-pager" id="rpPager"></div></div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
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
    var groups = Array.prototype.slice.call(document.querySelectorAll('tbody.rp-group'));
    if (!groups.length) { return; }
    var info = document.getElementById('rpInfo'), pager = document.getElementById('rpPager'), none = document.getElementById('rpNone');
    var search = document.getElementById('rpSearch'), per = 10, page = 1;
    function render() {
      var term = (search.value || '').toLowerCase().trim();
      var match = groups.filter(function (g) { return g.dataset.search.indexOf(term) > -1; });
      var pages = Math.max(1, Math.ceil(match.length / per));
      if (page > pages) { page = pages; }
      var from = (page - 1) * per, shown = 0;
      groups.forEach(function (g) { g.hidden = true; });
      match.forEach(function (g, i) { if (i >= from && i < from + per) { g.hidden = false; shown++; } });
      none.hidden = match.length !== 0;
      info.textContent = match.length ? 'Showing ' + (from + 1) + ' to ' + (from + shown) + ' of ' + match.length + ' reports' : '';
      pager.innerHTML = '';
      if (pages > 1) {
        var add = function (label, p, on, off) {
          var b = document.createElement('button'); b.type = 'button'; b.textContent = label;
          if (on) { b.className = 'on'; }
          if (off) { b.disabled = true; }
          b.addEventListener('click', function () { page = p; render(); });
          pager.appendChild(b);
        };
        var start = Math.max(1, Math.min(page - 2, pages - 4)), end = Math.min(pages, start + 4);
        for (var p = start; p <= end; p++) { add(String(p), p, p === page, false); }
        add('Next \u203A', page + 1, false, page === pages);
      }
    }
    search.addEventListener('input', function () { page = 1; render(); });
    document.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-detail]') : null;
      if (!b) { return; }
      var row = document.getElementById(b.getAttribute('data-detail'));
      if (!row) { return; }
      row.hidden = !row.hidden;
      b.setAttribute('aria-expanded', row.hidden ? 'false' : 'true');
    });
    render();
  })();
  </script>
</body>

</html>