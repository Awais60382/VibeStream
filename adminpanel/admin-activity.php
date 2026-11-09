<?php
/*
 * VibeStream admin panel - Admin activity (SITE OWNER ONLY).
 * Shows what every admin has been doing: sessions, pages opened, Premium actions.
 * Anyone who is not the owner is sent away and never sees this page exists.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    header('Location: ../login.php');
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

$hasDb = isset($pdo) && $pdo instanceof PDO;

/* ---------------------------------------------------------------
   1. OWNER ONLY: look up the logged-in admin's email and compare it
      with the site owner (the same check the sidebar uses).
---------------------------------------------------------------- */
$isOwner = false;
$uc = [];
$idCol = $unameCol = $nameCol = $emailCol = $roleCol = $laCol = null;
if ($hasDb) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $uc = vs_cols($pdo, 'users') ?: [];
        $idCol    = vs_pick($uc, ['id', 'user_id']);
        $unameCol = vs_pick($uc, ['username', 'user_name', 'login', 'uname']);
        $nameCol  = vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name']);
        $emailCol = vs_pick($uc, ['email', 'email_address', 'mail']);
        $roleCol  = vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
        $laCol    = vs_pick($uc, ['last_active', 'last_seen', 'last_login']);

        $matchCol = null;
        $matchVal = null;
        if ($idCol && !empty($_SESSION['AdminID'])) {
            $matchCol = $idCol;
            $matchVal = $_SESSION['AdminID'];
        } elseif ($unameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $unameCol;
            $matchVal = $_SESSION['AdminSession'];
        } elseif ($nameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $nameCol;
            $matchVal = $_SESSION['AdminSession'];
        }
        if ($emailCol && $matchCol) {
            $st = $pdo->prepare("SELECT `$emailCol` FROM `users` WHERE `$matchCol` = :v LIMIT 1");
            $st->execute([':v' => $matchVal]);
            $myEmail = (string)$st->fetchColumn();
            $isOwner = $myEmail !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($myEmail);
        }
    } catch (Throwable $e) {
        $isOwner = false;
    }
}
if (!$isOwner) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['admin_activity_clear_csrf'])) {
    $_SESSION['admin_activity_clear_csrf'] = bin2hex(random_bytes(32));
}
$clearableActivityTypes = [
    'session' => 'Sign-ins',
    'page_view' => 'Page views',
];
$activityNotice = $_SESSION['admin_activity_notice'] ?? null;
unset($_SESSION['admin_activity_notice']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'clear_non_payroll_activity') {
    if (!hash_equals((string)$_SESSION['admin_activity_clear_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('The request expired. Refresh the page and try again.');
    }

    try {
        vs_activity_ensure($pdo);
        $requestedTypes = $_POST['activity_types'] ?? [];
        if (!is_array($requestedTypes)) {
            $requestedTypes = [];
        }
        $selectedTypes = array_values(array_intersect(
            array_keys($clearableActivityTypes),
            array_filter($requestedTypes, 'is_string')
        ));
        if (!$selectedTypes) {
            $_SESSION['admin_activity_notice'] = [
                'type' => 'warning',
                'message' => 'Choose at least one activity type to clear. No activity was deleted.',
            ];
        } else {
            $placeholders = [];
            $deleteArgs = [];
            foreach ($selectedTypes as $index => $type) {
                $placeholder = ':activity' . $index;
                $placeholders[] = $placeholder;
                $deleteArgs[$placeholder] = $type;
            }
            $clearStmt = $pdo->prepare(
                'DELETE FROM admin_activity_log WHERE action IN (' . implode(', ', $placeholders) . ')'
            );
            $clearStmt->execute($deleteArgs);
            $selectedLabels = array_values(array_intersect_key(
                $clearableActivityTypes,
                array_flip($selectedTypes)
            ));
            $_SESSION['admin_activity_notice'] = [
                'type' => 'success',
                'message' => number_format($clearStmt->rowCount()) . ' record(s) cleared: ' . implode(' and ', $selectedLabels) . '. Payroll work activity was kept.',
            ];
        }
    } catch (Throwable $error) {
        error_log('Non-payroll admin activity could not be cleared: ' . $error->getMessage());
        $_SESSION['admin_activity_notice'] = [
            'type' => 'danger',
            'message' => 'Activity could not be cleared. Please try again.',
        ];
    }
    header('Location: admin-activity.php');
    exit;
}

/* The owner's own activity is never shown here: collect every name the owner account goes by.
   This page is owner-only, so whoever is looking at it IS the owner: every name in their session counts. */
$ownerNames = [];
$addOwner = static function ($n) use (&$ownerNames): void {
    $n = mb_strtolower(trim((string)$n));
    if ($n !== '') {
        $ownerNames[$n] = true;
    }
};
foreach (['username', 'AdminSession', 'name', 'user_name', 'full_name', 'display_name', 'AdminName'] as $sk) {
    if (isset($_SESSION[$sk]) && is_scalar($_SESSION[$sk])) {
        $addOwner($_SESSION[$sk]);
    }
}
$addOwner(vs_activity_session_name());
/* Accounts that must never appear on this page, whatever their role or email (add more names here if needed). */
foreach (['VibeStream', 'VibeStreams'] as $hiddenName) {
    $addOwner($hiddenName);
}
try {
    // Every account whose email is the owner email (any role), under every name column.
    if ($emailCol) {
        $nameCols = array_values(array_unique(array_filter([$unameCol, $nameCol])));
        if ($nameCols) {
            $osql = 'SELECT ' . implode(', ', array_map(static fn($c) => "`$c`", $nameCols)) . ", `$emailCol` AS e FROM `users`";
            foreach ($pdo->query($osql)->fetchAll(PDO::FETCH_ASSOC) as $ou) {
                $oe = trim((string)$ou['e']);
                if ($oe !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($oe)) {
                    foreach ($nameCols as $nc) {
                        $addOwner($ou[$nc] ?? '');
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    // keep whatever was found
}
$ownerNames = array_keys($ownerNames);
$exArgs = [];
$exPh = [];
foreach ($ownerNames as $i => $on) {
    $exPh[] = ':ex' . $i;
    $exArgs[':ex' . $i] = $on;
}
$exWhere = $exPh ? 'LOWER(TRIM(admin_username)) NOT IN (' . implode(',', $exPh) . ')' : '1=1';

/* ---------------------------------------------------------------
   2. FILTERS
---------------------------------------------------------------- */
$fAdmin = trim((string)($_GET['admin'] ?? ''));
$fType  = trim((string)($_GET['type'] ?? ''));
$fRange = (string)($_GET['range'] ?? '7d');
if (!in_array($fRange, ['1d', '7d', '30d'], true)) {
    $fRange = '7d';
}
$fQ   = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;

$where = [];
$args = [];
if ($exPh) {
    $where[] = $exWhere;
    $args = $exArgs;
}
if ($fAdmin !== '') {
    $where[] = 'admin_username = :admin';
    $args[':admin'] = $fAdmin;
}
if ($fType !== '') {
    $where[] = 'action = :type';
    $args[':type'] = $fType;
}
if ($fRange === '1d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
} elseif ($fRange === '7d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($fRange === '30d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}
if ($fQ !== '') {
    $where[] = '(details LIKE :q_details OR admin_username LIKE :q_admin OR page LIKE :q_page)';
    $searchPattern = '%' . $fQ . '%';
    $args[':q_details'] = $searchPattern;
    $args[':q_admin'] = $searchPattern;
    $args[':q_page'] = $searchPattern;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$qs = static function (array $over = []) use ($fAdmin, $fType, $fRange, $fQ): string {
    $p = array_merge(['admin' => $fAdmin, 'type' => $fType, 'range' => $fRange, 'q' => $fQ], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
};

/* ---------------------------------------------------------------
   3. LOAD DATA
---------------------------------------------------------------- */
$dbError = '';
/* Expire transient sign-in/page-view telemetry without removing payroll work records. */
try {
    vs_activity_ensure($pdo);
    $pdo->exec("DELETE FROM admin_activity_log WHERE action IN ('session', 'page_view') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
} catch (Throwable $e) {
    error_log('Old admin session/page-view activity could not be cleaned up: ' . $e->getMessage());
}
$rows = [];
$total = 0;
$types = [];
$admins = [];
$stToday = 0;
$stWeek = 0;
$stByAction = [];
$stOnline = 0;
$suggest = [];
$chartCounts = [];
$clearableActivityCounts = array_fill_keys(array_keys($clearableActivityTypes), 0);

try {
    vs_activity_ensure($pdo);
    $clearableCountStmt = $pdo->query(
        "SELECT action, COUNT(*) AS activity_count
         FROM admin_activity_log
         WHERE action IN ('session', 'page_view')
         GROUP BY action"
    );
    foreach ($clearableCountStmt->fetchAll(PDO::FETCH_ASSOC) as $clearableCount) {
        $clearableActivityCounts[(string)$clearableCount['action']] = (int)$clearableCount['activity_count'];
    }

    // CSV export of the current filter
    if (($_GET['download'] ?? '') === 'csv') {
        $st = $pdo->prepare('SELECT created_at, admin_username, action, details, page, ip FROM admin_activity_log' . $whereSql . ' ORDER BY id DESC LIMIT 5000');
        $st->execute($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="admin-activity-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Time', 'Admin', 'Activity', 'Details', 'Page', 'IP']);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            // Stop =, +, -, @ at the start of a cell from running as a spreadsheet formula.
            fputcsv($out, array_map(static function ($v): string {
                $s = (string)$v;
                return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
            }, $r));
        }
        exit;
    }

    $cs = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log' . $whereSql);
    $cs->execute($args);
    $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);

    $ls = $pdo->prepare('SELECT * FROM admin_activity_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $ls->execute($args);
    $rows = $ls->fetchAll(PDO::FETCH_ASSOC);

    $ts = $pdo->prepare('SELECT DISTINCT action FROM admin_activity_log WHERE ' . $exWhere . ' ORDER BY action');
    $ts->execute($exArgs);
    $types = $ts->fetchAll(PDO::FETCH_COLUMN);
    $ts = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= CURDATE() AND ' . $exWhere);
    $ts->execute($exArgs);
    $stToday = (int)$ts->fetchColumn();
    $ts = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND ' . $exWhere);
    $ts->execute($exArgs);
    $stWeek = (int)$ts->fetchColumn();
    $rg = ['1d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)', '7d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
           '30d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'][$fRange] ?? '';
    $ts = $pdo->prepare('SELECT action, COUNT(*) FROM admin_activity_log WHERE ' . $exWhere . $rg . ' GROUP BY action');
    $ts->execute($exArgs);
    foreach ($ts->fetchAll(PDO::FETCH_NUM) as $ar) {
        $stByAction[(string)$ar[0]] = (int)$ar[1];
    }

    $chartBucketExpression = $fRange === '1d'
        ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')"
        : 'DATE(created_at)';
    $chartStmt = $pdo->prepare(
        'SELECT ' . $chartBucketExpression . ' AS bucket, COUNT(*) AS activity_count
         FROM admin_activity_log' . $whereSql . '
         GROUP BY bucket
         ORDER BY bucket ASC'
    );
    $chartStmt->execute($args);
    foreach ($chartStmt->fetchAll(PDO::FETCH_ASSOC) as $chartRow) {
        $chartCounts[(string)$chartRow['bucket']] = (int)$chartRow['activity_count'];
    }

    // Search suggestions (admin names, pages and recent activity text; the owner is left out)
    $suggest = [];
    $ds = $pdo->prepare('SELECT details, MAX(id) AS m FROM admin_activity_log WHERE details <> \'\' AND ' . $exWhere . ' GROUP BY details ORDER BY m DESC LIMIT 40');
    $ds->execute($exArgs);
    foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $suggest[] = (string)$d['details'];
    }

    // Per-admin numbers from the log
    $perAdmin = [];
    $ps = $pdo->prepare(
        'SELECT admin_username, COUNT(*) AS total,
                SUM(created_at >= CURDATE()) AS today, MAX(created_at) AS last_at
         FROM admin_activity_log WHERE ' . $exWhere . ' GROUP BY admin_username'
    );
    $ps->execute($exArgs);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perAdmin[mb_strtolower(trim((string)$r['admin_username']))] = $r;
    }

    // Everyone with the Admin role, with their last-active time
    $list = [];
    if ($unameCol && $roleCol) {
        $sel = "`$unameCol` AS f_name" . ($emailCol ? ", `$emailCol` AS f_email" : '') . ($laCol ? ", `$laCol` AS f_la" : '');
        $us = $pdo->prepare("SELECT $sel FROM `users` WHERE LOWER(`$roleCol`) = 'admin' ORDER BY `$unameCol` ASC");
        $us->execute();
        foreach ($us->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $list[mb_strtolower(trim((string)$u['f_name']))] = $u;
        }
    }
    // Admins that only appear in the log (for example a renamed account)
    foreach ($perAdmin as $k => $r) {
        if (!isset($list[$k])) {
            $list[$k] = ['f_name' => $r['admin_username'], 'f_email' => '', 'f_la' => null];
        }
    }
    foreach ($list as $k => $u) {
        $name = trim((string)$u['f_name']);
        if ($name === '' || in_array($k, $ownerNames, true)) {
            continue;
        }
        $email = trim((string)($u['f_email'] ?? ''));
        $laTs = !empty($u['f_la']) ? strtotime((string)$u['f_la']) : 0;
        $logRow = $perAdmin[$k] ?? null;
        $lastLog = $logRow ? strtotime((string)$logRow['last_at']) : 0;
        $online = $laTs && (time() - $laTs) < 300;
        if ($online) {
            $stOnline++;
        }
        $admins[] = [
            'name'    => $name,
            'owner'   => $email !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($email),
            'online'  => $online,
            'last_ts' => max($laTs, $lastLog),
            'today'   => $logRow ? (int)$logRow['today'] : 0,
            'total'   => $logRow ? (int)$logRow['total'] : 0,
        ];
    }
    usort($admins, static fn($a, $b) => $b['last_ts'] <=> $a['last_ts']);
    foreach ($admins as $a) {
        $suggest[] = $a['name'];
    }
} catch (Throwable $e) {
    error_log('admin-activity.php failed: ' . $e->getMessage());
    $dbError = 'Could not load the admin activity.';
}

$chartBuckets = [];
if ($fRange === '1d') {
    $hour = new DateTimeImmutable(date('Y-m-d H:00:00'));
    for ($offset = 23; $offset >= 0; $offset--) {
        $bucketDate = $hour->modify('-' . $offset . ' hours');
        $key = $bucketDate->format('Y-m-d H:00:00');
        $chartBuckets[] = [
            'label' => $bucketDate->format('g A'),
            'title' => $bucketDate->format('M j, g:00 A'),
            'count' => $chartCounts[$key] ?? 0,
        ];
    }
} else {
    $dayCount = $fRange === '30d' ? 31 : 8;
    $today = new DateTimeImmutable(date('Y-m-d'));
    for ($offset = $dayCount - 1; $offset >= 0; $offset--) {
        $bucketDate = $today->modify('-' . $offset . ' days');
        $key = $bucketDate->format('Y-m-d');
        $chartBuckets[] = [
            'label' => $fRange === '30d' ? $bucketDate->format('j') : $bucketDate->format('D'),
            'title' => $bucketDate->format('M j, Y'),
            'count' => $chartCounts[$key] ?? 0,
        ];
    }
}
$chartMax = max(1, ...array_column($chartBuckets, 'count'));

/* ---------------------------------------------------------------
   4. SMALL HELPERS FOR THE PAGE
---------------------------------------------------------------- */
$ago = static function (int $ts): string {
    if ($ts <= 0) {
        return 'never';
    }
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return (int)floor($d / 60) . ' min ago';
    if ($d < 86400)  return (int)floor($d / 3600) . ' h ago';
    if ($d < 604800) return (int)floor($d / 86400) . ' d ago';
    return date('M j, Y', $ts);
};
$actionLabel = static function (string $a): array {
    $map = [
        'session'   => ['Signed in',  'text-bg-info'],
        'page_view' => ['Viewed',  'text-bg-secondary'],
        'premium'   => ['Gave Premium',    'text-bg-warning'],
        'video'     => ['Video',      'text-bg-primary'],
        'user'      => ['User',       'text-bg-success'],
        'settings'  => ['Changed Settings',   'text-bg-dark'],
    ];
    return $map[$a] ?? [ucfirst(str_replace('_', ' ', $a)), 'text-bg-light'];
};
$aaIcon = ['session' => 'bi-box-arrow-in-right', 'page_view' => 'bi-play-fill', 'premium' => 'bi-gem',
          'video' => 'bi-camera-video-fill', 'user' => 'bi-person-plus-fill', 'settings' => 'bi-gear-fill'];
$pmInitial = static function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'A', 0, 1));
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
        . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
        . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
    );
};

include "header.php";
?>

<style>
  .aa-page .page-heading{position:relative;isolation:isolate;overflow:visible;padding:22px 26px;border-radius:20px;border:1px solid rgba(120,110,255,.5);background:radial-gradient(120% 160% at 0 0,#1d3fd6 0%,#2a1a8f 45%,#5b1fa8 100%);box-shadow:0 0 34px rgba(99,102,241,.35)}
  .aa-page .page-heading h1{font-weight:800;font-size:2rem;color:#fff}
  .aa-page .page-heading p{color:#d4dcff!important}
  .aa-page .page-icon{width:64px;height:64px;border-radius:18px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.3);color:#fff;font-size:1.9rem;display:grid;place-items:center}
  .aa-page .eyebrow{display:inline-block;padding:3px 12px;border-radius:999px;background:#5b3df5;color:#fff;font-size:.78rem;font-weight:700;letter-spacing:0;text-transform:none}
  .aa-page .heading-actions{position:relative;z-index:30}
  .aa-page .heading-actions .btn{border-radius:12px;color:#fff;border-color:rgba(255,255,255,.4);background:rgba(10,14,60,.45)}
  .aa-clear-menu{position:static;display:inline-block}
  .aa-clear-menu>summary{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid rgba(255,120,120,.55);border-radius:12px;background:rgba(110,20,35,.38);color:#ffd4d8;font-size:.875rem;cursor:pointer;list-style:none}
  .aa-clear-menu>summary::-webkit-details-marker{display:none}
  .aa-clear-menu>summary:after{content:"▾";font-size:.75rem}
  .aa-clear-menu[open]>summary:after{transform:rotate(180deg)}
  .aa-clear-form{position:absolute;z-index:1000;top:calc(100% + 10px);right:26px;width:min(320px,calc(100vw - 32px));padding:15px;border:1px solid var(--border,#414654);border-radius:14px;background:var(--surface,#181b23);color:var(--text,#eee);box-shadow:0 16px 40px rgba(0,0,0,.32)}
  .aa-clear-title{margin:0 0 4px;font-size:.9rem;font-weight:750}
  .aa-clear-help{margin:0 0 12px;color:var(--text-secondary,#a1a1ab);font-size:.75rem;line-height:1.45}
  .aa-clear-option{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:9px 0;border-top:1px solid var(--border,#303643);font-size:.82rem}
  .aa-clear-option label{display:flex;align-items:center;gap:8px;cursor:pointer}
  .aa-clear-option input{accent-color:#e11d48}
  .aa-clear-option small{color:var(--text-secondary,#a1a1ab);white-space:nowrap}
  .aa-clear-form .btn{width:100%;margin-top:12px}
  .aa-clear-form .btn.btn-danger{border-color:#dc3545;background:#dc3545;color:#fff}
  @media(max-width:575px){.aa-clear-form{right:16px;width:min(320px,calc(100vw - 32px))}}
  .aa-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:16px}
  @media (max-width:1199px){.aa-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media (max-width:575px){.aa-tiles{grid-template-columns:minmax(0,1fr)}}
  .aa-tile{display:flex;align-items:center;gap:14px;min-width:0;padding:16px 18px;border-radius:16px;border:1px solid var(--c);color:#eef2ff;background:linear-gradient(135deg,color-mix(in srgb,var(--c) 30%,#0a1038),#0a1038 85%);box-shadow:0 0 20px color-mix(in srgb,var(--c) 30%,transparent)}
  .aa-tile .ico{width:56px;height:56px;border-radius:50%;display:grid;place-items:center;font-size:1.5rem;color:#fff;background:var(--c);flex:none}
  .aa-tile b{display:block;font-size:1.9rem;line-height:1.05;font-weight:800}
  .aa-tile span{display:block;font-size:.95rem} .aa-tile small{color:#9fb0e0}
  .aa-blue{--c:#3b82f6}.aa-green{--c:#10b981}.aa-violet{--c:#8b5cf6}.aa-pink{--c:#e11d74}.aa-orange{--c:#f59e0b}.aa-teal{--c:#06b6d4}.aa-indigo{--c:#6366f1}.aa-dark{--c:#4a5aa8}
  .badge.aa-pill{display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:12px;font-weight:600;border:1px solid var(--c,#7c8cff);color:var(--c,#cdd5ff);background:color-mix(in srgb,var(--c,#7c8cff) 16%,transparent)}
  .aa-a-session{--c:#34d399}.aa-a-page_view{--c:#38bdf8}.aa-a-user{--c:#a78bfa}.aa-a-settings{--c:#fbbf24}.aa-a-video{--c:#fb7185}.aa-a-premium{--c:#22c55e}
  .aa-page .table thead th{background:rgba(120,130,255,.12);font-weight:600}
  .aa-page .avatar-img{border-radius:50%}
  .aa-trend{padding:18px 20px}
  .aa-trend-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:18px}
  .aa-trend-head h2{margin:0;font-size:1.05rem;font-weight:750}
  .aa-trend-head p{margin:5px 0 0;color:var(--text-secondary,#a1a1ab);font-size:.82rem}
  .aa-trend-total{padding:7px 11px;border:1px solid rgba(99,102,241,.3);border-radius:999px;background:rgba(99,102,241,.1);color:#aeb5ff;font-size:.78rem;font-weight:700;white-space:nowrap}
  .aa-trend-chart{display:grid;grid-template-columns:repeat(<?= count($chartBuckets) ?>,minmax(0,1fr));align-items:end;gap:clamp(3px,1vw,12px);height:190px;padding:12px 4px 0;border-bottom:1px solid var(--border,#303643);background:repeating-linear-gradient(to top,transparent 0,transparent 46px,rgba(127,127,127,.1) 47px,transparent 48px)}
  .aa-trend-column{display:flex;min-width:0;height:100%;flex-direction:column;justify-content:flex-end;align-items:center;gap:7px}
  .aa-trend-value{min-height:14px;color:var(--text-secondary,#a1a1ab);font-size:clamp(9px,.8vw,11px);line-height:1;text-align:center}
  .aa-trend-bar-wrap{display:flex;width:100%;height:145px;align-items:flex-end;justify-content:center}
  .aa-trend-bar{width:min(100%,32px);min-height:3px;border-radius:6px 6px 2px 2px;background:linear-gradient(180deg,#818cf8,#4f46e5);box-shadow:0 0 14px rgba(99,102,241,.2);transition:height .25s ease}
  .aa-trend-label{overflow:hidden;width:100%;padding-bottom:7px;color:var(--text-secondary,#a1a1ab);font-size:clamp(8px,.72vw,10px);text-align:center;text-overflow:clip;white-space:nowrap}
  @media(max-width:575px){.aa-trend{padding:15px 12px}.aa-trend-chart{height:170px;gap:2px}.aa-trend-bar-wrap{height:125px}.aa-trend-label{font-size:8px}.aa-trend-value{font-size:8px}}
</style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 aa-page">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner only</p>
                <h1 class="h3 mb-1">Admin Activity</h1>
                <p class="text-muted mb-0">What your admins have been doing in the admin panel. Only you can see this page. Sign-in and page-view records expire after 7 days; work activity is kept for payroll.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo vs_e($qs(['download' => 'csv'])); ?>"><i class="bi bi-download" aria-hidden="true"></i> Export Report</a>
              <details class="aa-clear-menu">
                <summary><i class="bi bi-trash3" aria-hidden="true"></i> Clear activity</summary>
                <form method="post" class="aa-clear-form" onsubmit="return confirm('Delete only the selected activity types? Payroll work activity will be kept.');">
                  <input type="hidden" name="action" value="clear_non_payroll_activity">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['admin_activity_clear_csrf']); ?>">
                  <p class="aa-clear-title">Choose what to delete</p>
                  <p class="aa-clear-help">Work actions used by payroll are protected and will not be deleted.</p>
                  <?php foreach ($clearableActivityTypes as $activityType => $activityLabel): ?>
                    <div class="aa-clear-option">
                      <label>
                        <input type="checkbox" name="activity_types[]" value="<?php echo vs_e($activityType); ?>" checked>
                        <span><?php echo vs_e($activityLabel); ?></span>
                      </label>
                      <small><?php echo number_format($clearableActivityCounts[$activityType] ?? 0); ?> records</small>
                    </div>
                  <?php endforeach; ?>
                  <button type="submit" class="btn btn-danger btn-sm"><i class="bi bi-trash3" aria-hidden="true"></i> Delete selected</button>
                </form>
              </details>
            </div>
          </div>

          <?php if (is_array($activityNotice) && !empty($activityNotice['message'])): ?>
            <div class="alert alert-<?php echo vs_e($activityNotice['type'] ?? 'info'); ?> mt-3" role="status"><?php echo vs_e($activityNotice['message']); ?></div>
          <?php endif; ?>

          <?php if ($dbError !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($dbError); ?></div>
          <?php else: ?>

          <?php
          $aaRange = ['1d' => 'last 24 hours', '7d' => 'last 7 days', '30d' => 'last 30 days', 'all' => 'all time'][$fRange] ?? '';
          $aaLast = $admins ? max(array_column($admins, 'last_ts')) : 0;
          $aaTiles = [
              ['aa-blue', 'bi-people-fill', 'Admins', count($admins), 'with admin access'],
              ['aa-green', 'bi-box-arrow-in-right', 'Sign-ins', $stByAction['session'] ?? 0, $aaRange],
              ['aa-violet', 'bi-play-circle-fill', 'Pages opened', $stByAction['page_view'] ?? 0, $aaRange],
              ['aa-pink', 'bi-camera-video-fill', 'Videos managed', $stByAction['video'] ?? 0, $aaRange],
              ['aa-orange', 'bi-person-plus-fill', 'Users managed', $stByAction['user'] ?? 0, $aaRange],
              ['aa-teal', 'bi-gem', 'Premium given', $stByAction['premium'] ?? 0, $aaRange],
              ['aa-indigo', 'bi-gear-fill', 'Settings changed', $stByAction['settings'] ?? 0, $aaRange],
              ['aa-dark', 'bi-bar-chart-fill', 'Last active', $ago((int)$aaLast), $stOnline . ' online now'],
          ];
          ?>
          <section class="aa-tiles" aria-label="Activity metrics">
            <?php foreach ($aaTiles as $t): ?>
              <article class="aa-tile <?php echo vs_e($t[0]); ?>">
                <span class="ico"><i class="bi <?php echo vs_e($t[1]); ?>" aria-hidden="true"></i></span>
                <div><b><?php echo is_int($t[3]) ? number_format($t[3]) : vs_e($t[3]); ?></b><span><?php echo vs_e($t[2]); ?></span><small><?php echo vs_e($t[4]); ?></small></div>
              </article>
            <?php endforeach; ?>
          </section>

          <section class="panel mt-3 aa-trend" aria-labelledby="activityTrendTitle">
            <div class="aa-trend-head">
              <div>
                <h2 id="activityTrendTitle">Activity trend</h2>
                <p><?php echo $fRange === '1d' ? 'Activity by hour over the last 24 hours' : 'Activity by day over ' . ($fRange === '30d' ? 'the last 30 days' : 'the last 7 days'); ?><?php echo $fAdmin !== '' ? ' · ' . vs_e($fAdmin) : ''; ?></p>
              </div>
              <span class="aa-trend-total"><?php echo number_format(array_sum(array_column($chartBuckets, 'count'))); ?> events</span>
            </div>
            <div class="aa-trend-chart" role="img" aria-label="<?php echo $fRange === '1d' ? 'Hourly admin activity for the last 24 hours' : 'Daily admin activity for the selected period'; ?>">
              <?php foreach ($chartBuckets as $bucket):
                  $barHeight = $bucket['count'] > 0 ? max(5, (int)round(($bucket['count'] / $chartMax) * 100)) : 2;
              ?>
                <div class="aa-trend-column" title="<?php echo vs_e($bucket['title'] . ': ' . number_format($bucket['count']) . ' activities'); ?>">
                  <span class="aa-trend-value"><?php echo $bucket['count'] > 0 ? number_format($bucket['count']) : ''; ?></span>
                  <div class="aa-trend-bar-wrap"><span class="aa-trend-bar" style="height:<?php echo $barHeight; ?>%"></span></div>
                  <span class="aa-trend-label"><?php echo vs_e($bucket['label']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </section>

          <section class="panel mt-3" aria-label="Admins">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Admins</span></h2>
              <p class="text-muted mb-0">Click an admin to see only their activity.</p>
            </div>
            <?php if (!$admins): ?>
              <p class="text-center text-muted py-4 mb-0">No admins found yet.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">Admin</th><th scope="col">Status</th><th scope="col">Last activity</th><th scope="col" class="text-end">Today</th><th scope="col" class="text-end">All time</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($admins as $a): ?>
                      <tr>
                        <td>
                          <a class="table-media text-decoration-none" href="<?php echo vs_e($qs(['admin' => $a['name'], 'page' => ''])); ?>">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url($a['name'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($a['name'])); ?>';">
                            <strong class="content-title"><?php echo vs_e($a['name']); ?></strong>
                            <?php if ($a['owner']): ?><span class="badge text-bg-primary ms-1">Owner</span><?php endif; ?>
                          </a>
                        </td>
                        <td><span class="badge <?php echo $a['online'] ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $a['online'] ? 'Online' : 'Offline'; ?></span></td>
                        <td class="text-muted"><?php echo vs_e($ago((int)$a['last_ts'])); ?></td>
                        <td class="text-end"><?php echo number_format($a['today']); ?></td>
                        <td class="text-end"><?php echo number_format($a['total']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3" aria-label="Activity feed">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-list-ul" aria-hidden="true"></i><span>Activity</span></h2>
                <p class="text-muted mb-0"><?php echo number_format($total); ?> result<?php echo $total === 1 ? '' : 's'; ?>, newest first.</p>
              </div>
              <form method="get" class="d-flex gap-2 flex-wrap">
                <select class="form-select form-select-sm" name="admin" aria-label="Admin" style="width:auto">
                  <option value="">All admins</option>
                  <?php foreach ($admins as $a): ?>
                    <option value="<?php echo vs_e($a['name']); ?>"<?php echo $fAdmin === $a['name'] ? ' selected' : ''; ?>><?php echo vs_e($a['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="type" aria-label="Type" style="width:auto">
                  <option value="">All activity</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?php echo vs_e($t); ?>"<?php echo $fType === $t ? ' selected' : ''; ?>><?php echo vs_e($actionLabel((string)$t)[0]); ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="range" aria-label="Time range" style="width:auto">
                  <option value="1d"<?php echo $fRange === '1d' ? ' selected' : ''; ?>>Last 24 hours</option>
                  <option value="7d"<?php echo $fRange === '7d' ? ' selected' : ''; ?>>Last 7 days</option>
                  <option value="30d"<?php echo $fRange === '30d' ? ' selected' : ''; ?>>Last 30 days</option>
                </select>
                <input type="search" class="form-control form-control-sm" name="q" value="<?php echo vs_e($fQ); ?>" placeholder="Search" aria-label="Search activity" list="activity-suggest" autocomplete="off" style="max-width:180px">
                <datalist id="activity-suggest">
                  <?php foreach (array_values(array_unique($suggest)) as $sg): ?>
                    <option value="<?php echo vs_e($sg); ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($fAdmin !== '' || $fType !== '' || $fQ !== '' || $fRange !== '7d'): ?>
                  <a class="btn btn-outline-secondary btn-sm" href="admin-activity.php">Reset</a>
                <?php endif; ?>
              </form>
            </div>

            <?php if (!$rows): ?>
              <p class="text-center text-muted py-4 mb-0">No activity matches these filters.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">When</th><th scope="col">Admin</th><th scope="col">Activity</th><th scope="col">Details</th><th scope="col">IP</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r):
                        [$label, $badge] = $actionLabel((string)$r['action']);
                        $ts = strtotime((string)$r['created_at']);
                    ?>
                      <tr>
                        <td class="text-muted small text-nowrap"><?php echo vs_e(date('M j, g:i A', $ts)); ?><div><?php echo vs_e($ago($ts)); ?></div></td>
                        <td>
                          <div class="table-media">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url((string)$r['admin_username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial((string)$r['admin_username'])); ?>';">
                            <strong class="content-title"><?php echo vs_e($r['admin_username']); ?></strong>
                          </div>
                        </td>
                        <td><span class="badge aa-pill aa-a-<?php echo vs_e(preg_replace('/[^a-z_]/', '', (string)$r['action'])); ?>"><i class="bi <?php echo vs_e($aaIcon[(string)$r['action']] ?? 'bi-dot'); ?>" aria-hidden="true"></i><?php echo vs_e($label); ?></span></td>
                        <td><?php echo vs_e($r['details']); ?></td>
                        <td class="text-muted small"><?php echo vs_e($r['ip']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($pages > 1): ?>
                <div class="d-flex align-items-center justify-content-between gap-2 p-3">
                  <span class="text-muted small">Page <?php echo (int)$page; ?> of <?php echo (int)$pages; ?></span>
                  <div class="btn-group btn-group-sm">
                    <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="<?php echo vs_e($qs(['page' => $page - 1])); ?>">Newer</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="btn btn-outline-secondary" href="<?php echo vs_e($qs(['page' => $page + 1])); ?>">Older</a><?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
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
</body>
</html>