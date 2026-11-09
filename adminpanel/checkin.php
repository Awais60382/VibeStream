<?php
require_once __DIR__ . '/checkin-lib.php';

$admin = vs_checkin_admin_name();
if (!$admin) { http_response_code(403); exit('Please log in first.'); }
vs_checkin_log_login_once($admin);
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/../avatar.php';

if (empty($_SESSION['vs_csrf'])) $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
$isOwner = false;
try {
    $isOwner = isset($pdo) && $pdo instanceof PDO && vs_is_owner($pdo);
} catch (Throwable $error) {
    error_log('Check-in owner access could not be verified: ' . $error->getMessage());
}
$_SESSION['vs_is_owner'] = $isOwner ? 1 : 0;
$ownerNames = vs_checkin_owner_names(isset($pdo) && $pdo instanceof PDO ? $pdo : null);

function safe_next($n) {
    $n = (string)$n;
    if ($n === '' || $n[0] !== '/' || strpos($n, '//') === 0 || strpos($n, '\\') !== false) return '';
    if (preg_match('/[\x00-\x20\x7f]/', $n)) return '';
    return $n;
}
function csv_safe($v) {                           // stop spreadsheet formula injection
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

function vs_checkin_owner_names($pdo = null) {
    $names = [];
    $add = function ($name) use (&$names) {
        $name = mb_strtolower(trim((string)$name));
        if ($name !== '') $names[$name] = true;
    };

    foreach (['VibeStream', 'VibeStreams'] as $name) $add($name);
    foreach (['Vibe Stream', 'VibeStreamOwner', 'VibeStream Owner'] as $name) $add($name);
    foreach (['username', 'AdminSession', 'name', 'user_name', 'full_name', 'display_name', 'AdminName'] as $key) {
        if (!empty($_SESSION[$key])) $add($_SESSION[$key]);
    }

    if ($pdo instanceof PDO && function_exists('vs_cols') && function_exists('vs_pick')) {
        try {
            $cols = vs_cols($pdo, 'users');
            $emailCol = vs_pick($cols, ['email', 'email_address', 'mail']);
            $nameCols = array_values(array_filter([
                vs_pick($cols, ['username', 'user_name', 'login', 'uname']),
                vs_pick($cols, ['name', 'full_name', 'display_name']),
            ]));
            if ($emailCol && $nameCols) {
                $select = implode(', ', array_map(fn($col) => vs_q($col), array_unique(array_merge($nameCols, [$emailCol]))));
                foreach ($pdo->query('SELECT ' . $select . ' FROM `users`') as $row) {
                    $email = trim((string)($row[$emailCol] ?? ''));
                    if ($email !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($email)) {
                        foreach ($nameCols as $nameCol) $add($row[$nameCol] ?? '');
                    }
                }
            }
        } catch (Throwable $error) {
            error_log('Check-in owner names could not be loaded: ' . $error->getMessage());
        }
    }
    return $names;
}

// ---- Owner: export history as CSV ----
if ($isOwner && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="checkin-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Admin', 'Event', 'Status', 'Note', 'IP', 'Browser']);
    foreach (array_reverse(vs_checkin_json_read(VS_CHECKIN_HISTORY)) as $e) {
        $eventAdmin = mb_strtolower(trim((string)($e['admin'] ?? '')));
        if (isset($ownerNames[$eventAdmin])) continue;
        fputcsv($out, array_map('csv_safe', [$e['at'] ?? '', $e['admin'] ?? '', $e['event'] ?? '', $e['status'] ?? '',
                                              $e['note'] ?? '', $e['ip'] ?? '', $e['ua'] ?? '']));
    }
    exit;
}

$next  = safe_next($_GET['next'] ?? $_POST['next'] ?? '');
$error = '';
$info  = '';
$openTasks = vs_task_open_for($admin);
$hasOpenTasks = !empty($openTasks);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'checkin';
    if (!hash_equals($_SESSION['vs_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired, try again.';
    } elseif ($action === 'checkout') {
        if (!vs_checkin_checkout($admin)) {
            $error = 'Could not check out.';
        } else {
            $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . vs_checkin_url());
            exit;
        }
    } elseif ($action === 'force' || $action === 'settings') {
        if (!$isOwner) {
            $error = 'Only the owner can do that.';
        } elseif ($action === 'force') {
            $target = (string)($_POST['target'] ?? '');
            if ($target !== '' && $target !== $admin && vs_checkin_force($target, $admin)) {
                header('Location: ' . vs_checkin_url() . '#team'); exit;
            }
            $error = 'Could not force that admin to check in again.';
        } else {
            vs_checkin_set_idle_minutes((int)($_POST['idle_minutes'] ?? 30));
            header('Location: ' . vs_checkin_url() . '#team'); exit;
        }
    } else {
        $status = $hasOpenTasks ? 'Busy' : 'Available';
        $note   = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 140));
        $openTasks = vs_task_open_for($admin);
        $hasOpenTasks = !empty($openTasks);
        if ($hasOpenTasks) {
            $status = 'Busy';
        } else {
            $status = 'Available';
        }
        if (!in_array($status, VS_CHECKIN_STATUSES, true)) {
            $error = 'Pick a status to check in.';
        } elseif ($status === 'Available' && $hasOpenTasks) {
            $error = 'You have an open task assigned by the owner, so you cannot check in as Free yet.';
        } elseif (in_array($status, VS_CHECKIN_NOTE_REQUIRED, true) && $note === '') {
            $error = 'Please add a note for "' . $status . '" (why, or when you will be back).';
        } elseif (!vs_checkin_save($admin, $status, $note, $hasOpenTasks)) {
            $error = 'Could not save your check-in.';
        } else {
            $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
            header('Location: ' . ($next !== '' ? $next : dirname(vs_checkin_url()) . '/'));
            exit;
        }
    }
}

$mine    = vs_checkin_active($admin) ? $_SESSION['vs_checkin'] : null;   // changeable, never removable

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$selectedStatus = $hasOpenTasks ? 'Busy' : 'Available';
$statusLabels = ['Available' => 'Free'];
$recentLogins = [];
if ($isOwner) {
    foreach (array_reverse(vs_checkin_json_read(VS_CHECKIN_HISTORY)) as $event) {
        if (($event['event'] ?? '') === 'logged in') {
            $loginName = mb_strtolower(trim((string)($event['admin'] ?? '')));
            if (!isset($ownerNames[$loginName])) $recentLogins[] = $event;
            if (count($recentLogins) >= 10) break;
        }
    }
}

// ---- Team status (latest status per admin, built from check-in history) ----
$statusMeta = [
    'Available'  => ['Free',       'Available',         'free'],
    'Busy'       => ['Busy',       'Working',           'busy'],
    'Moderating' => ['Moderating', 'Reviewing content', 'mod'],
    'Away'       => ['Away',       'Be right back',     'away'],
    'On break'   => ['On break',   'Taking a break',    'break'],
    'Offline'    => ['Offline',    'Not checked in',    'offline'],
];
$teamMap = [];
if ($isOwner) {
    foreach (vs_checkin_all() as $who => $record) {
        $who = trim((string)$who);
        if ($who === '' || !is_array($record) || isset($ownerNames[mb_strtolower($who)])) continue;
        $status = vs_checkin_effective($record);
        if (!isset($statusMeta[$status])) continue;
        $seen = strtotime((string)($record['seen'] ?? $record['at'] ?? '')) ?: 0;
        $teamMap[$who] = ['status' => $status, 'ts' => $seen];
    }
    foreach (vs_task_all() as $task) {
        if (!is_array($task) || ($task['status'] ?? '') !== 'open') continue;
        $who = trim((string)($task['admin'] ?? ''));
        if ($who === '' || isset($ownerNames[mb_strtolower($who)])) continue;
        if (!isset($teamMap[$who])) {
            $teamMap[$who] = ['status' => 'Busy', 'ts' => strtotime((string)($task['assigned_at'] ?? '')) ?: time()];
        } elseif ($teamMap[$who]['status'] !== 'Offline') {
            $teamMap[$who]['status'] = 'Busy';
        }
    }
    uasort($teamMap, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $teamMap = array_slice($teamMap, 0, 8, true);
}

$recentLogins = array_values(array_filter($recentLogins, static function (array $event) use ($ownerNames): bool {
    return !isset($ownerNames[mb_strtolower(trim((string)($event['admin'] ?? '')))]);
}));
$teamMap = array_filter($teamMap, static function (array $member, string $who) use ($ownerNames): bool {
    return !isset($ownerNames[mb_strtolower(trim($who))]);
}, ARRAY_FILTER_USE_BOTH);
function ago($ts) {
    if (!$ts) return '';
    $d = max(0, time() - $ts);
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . 'm ago';
    if ($d < 86400) return floor($d / 3600) . 'h ago';
    return floor($d / 86400) . 'd ago';
}
function pill($meta) {
    return '<span class="pill pill-' . $meta[2] . '"><i></i>' . htmlspecialchars($meta[0]) . '</span>';
}
$initial = fn($n) => strtoupper(mb_substr(trim((string)$n) ?: '?', 0, 1));
$noteVal = (string)($_POST['note'] ?? ($mine['note'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Check-In · VibeStream</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  :root{--bg:#070914;--card:#0e1224;--line:rgba(139,111,255,.22);--txt:#f4f5ff;--mut:#9aa0c3;--pur:#8b3dff;--pur2:#5b3bff;--free:#22e08a;--busy:#ffb020;--mod:#1e9bff;--away:#b45cff;--break:#c9bbff}
  *{box-sizing:border-box}
  html,body{margin:0}
  body{min-height:100vh;color:var(--txt);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:
    radial-gradient(ellipse 60% 40% at 0% 0%,rgba(120,50,255,.45),transparent 60%),
    radial-gradient(ellipse 50% 40% at 100% 20%,rgba(90,50,255,.35),transparent 60%),
    radial-gradient(ellipse 60% 40% at 0% 100%,rgba(110,40,255,.4),transparent 60%),
    radial-gradient(ellipse 50% 40% at 100% 100%,rgba(60,60,255,.3),transparent 60%),var(--bg)}
  a{color:inherit;text-decoration:none}
  svg{display:block}
  .avatar{display:grid;place-items:center;border-radius:50%;background:linear-gradient(145deg,#a24dff,#6a2cf0);color:#fff;font-weight:700}
  /* page */
  .wrap{width:min(100%,1060px);margin:0 auto;padding:36px 20px 56px}
  .hero{text-align:center;margin-bottom:26px}
  .hero-title{display:flex;align-items:center;justify-content:center;gap:22px}
  .hero-title svg{width:64px;height:72px;filter:drop-shadow(0 0 18px rgba(139,61,255,.55))}
  h1{margin:0;font-size:clamp(34px,5vw,52px);font-weight:800;letter-spacing:-.03em}
  h1 span{background:linear-gradient(90deg,#9b4dff,#6a4bff);-webkit-background-clip:text;background-clip:text;color:transparent}
  .hero p{margin:6px 0 0;color:#b9bde0;font-size:19px}
  .card{border:1px solid var(--line);border-radius:22px;background:linear-gradient(160deg,rgba(18,22,46,.92),rgba(10,13,30,.94));padding:26px 36px;box-shadow:0 20px 60px rgba(0,0,0,.35)}
  .card h2{display:flex;align-items:center;gap:14px;margin:0 0 18px;font-size:22px;font-weight:700}
  .card h2 svg{width:36px;height:36px;color:#2aa7ff}
  .row-head{display:flex;justify-content:space-between;align-items:center}
  .row-head a{color:#8f7bff;font-weight:600;font-size:16px;display:flex;align-items:center;gap:6px}
  .row-head a svg{width:18px;height:18px}
  .err{margin-bottom:16px;padding:12px 14px;border:1px solid rgba(255,120,120,.3);border-radius:12px;background:rgba(180,45,45,.18);color:#ffb0b0;font-size:14px}
  .task-state{margin:0 0 18px;padding:13px 15px;border:1px solid;border-radius:14px}
  .task-state.assigned{border-color:rgba(255,120,110,.35);background:rgba(231,76,60,.09)}
  .task-state.clear{border-color:rgba(34,224,138,.28);background:rgba(34,224,138,.07)}
  .task-state-title{font-size:14px;font-weight:700}
  .task-state.assigned .task-state-title{color:#ffaaa3}.task-state.clear .task-state-title{color:#7df0b8}
  .task-state-copy{margin:5px 0 0;color:#b4b9dc;font-size:13px;line-height:1.5}
  .task-status-badge{display:inline-flex;align-items:center;gap:10px;margin-bottom:18px;padding:10px 16px;border-radius:999px;font-size:15px;font-weight:800}
  .task-status-badge i{width:13px;height:13px;border-radius:50%;background:currentColor;box-shadow:0 0 14px currentColor}
  .task-status-badge.busy{color:var(--busy);background:rgba(255,176,32,.14)}
  .task-status-badge.free{color:var(--free);background:rgba(34,224,138,.13)}
  .assigned-task-list{display:grid;gap:7px;margin:10px 0 0;padding:0;list-style:none}
  .assigned-task{padding:9px 11px;border:1px solid rgba(255,255,255,.08);border-radius:10px;background:rgba(5,7,18,.4)}
  .assigned-task-title{display:block;font-size:13px;font-weight:600}
  .assigned-task-by{display:block;margin-top:3px;color:#8f95bb;font-size:12px}
  /* note */
  .note-label{display:flex;align-items:center;gap:14px;margin:0 0 12px;font-size:20px;font-weight:700}
  .note-label svg{width:30px;height:30px;color:#8f7bff}
  .note-label small{font-size:13px;color:#ffb3ad;font-weight:600}
  .note-box{position:relative}
  textarea{display:block;width:100%;height:88px;resize:none;padding:16px 20px 30px;border:1px solid rgba(139,111,255,.28);border-radius:14px;background:rgba(20,26,56,.55);color:#fff;font:inherit;font-size:17px;outline:none}
  textarea::placeholder{color:#8e94bd}
  textarea:focus{border-color:#8b6fff;box-shadow:0 0 0 3px rgba(139,111,255,.18)}
  .count{position:absolute;right:20px;bottom:10px;color:#b9bde0;font-size:15px}
  .btn{display:flex;align-items:center;justify-content:center;gap:14px;width:100%;margin-top:20px;padding:17px;border:0;border-radius:14px;background:linear-gradient(90deg,#7b2ff7,#4a4bff);color:#fff;font:inherit;font-size:20px;font-weight:700;cursor:pointer;box-shadow:0 10px 30px rgba(110,60,255,.4);transition:.15s}
  .btn:hover{filter:brightness(1.1);transform:translateY(-1px)}
  .btn svg{width:30px;height:30px}
  /* lower cards */
  .grid{display:grid;grid-template-columns:1.1fr 1fr;gap:28px;margin-top:26px;align-items:start}
  .grid .card{padding:20px 24px}
  .grid h2{font-size:21px;margin:0}
  .grid h2 svg{width:32px;height:32px;color:#e9ebff}
  .grid .team h2 svg{color:#3aa8ff}
  .list{margin-top:14px}
  .li{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid rgba(139,111,255,.15);font-size:15px}
  .li:first-child{border-top:1px solid rgba(139,111,255,.25)}
  .li .when{flex:1 1 40%;color:#b9bde0}
  .li .recent-avatar{flex:0 0 34px}
  .li .name{flex:1 1 30%;font-weight:700;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .li .pillcell{flex:0 0 auto;min-width:112px;display:flex}
  .team .li{padding:8px 0}
  .team .avatar{width:38px;height:38px;font-size:17px;flex:0 0 38px}
  .team .name{flex:1 1 30%;font-size:15px}
  .team .ago{color:#c3c7e8;font-size:15px;min-width:70px;text-align:right}
  .pill{display:inline-flex;align-items:center;gap:9px;padding:5px 14px 5px 10px;border-radius:999px;font-size:15px;font-weight:600}
  .pill i{width:13px;height:13px;border-radius:50%;background:currentColor}
  .pill-free{color:var(--free);background:rgba(34,224,138,.13)}
  .pill-busy{color:var(--busy);background:rgba(255,176,32,.14)}
  .pill-mod{color:var(--mod);background:rgba(30,155,255,.15)}
  .pill-away{color:#c27bff;background:rgba(180,92,255,.16)}
  .pill-break{color:var(--break);background:rgba(201,187,255,.14)}
  .pill-offline{color:#9aa0c3;background:rgba(154,160,195,.14)}
  .empty{padding:22px 10px;color:#9aa0c3;text-align:center;font-size:14px}
  .export{display:flex;justify-content:flex-end;margin-top:10px}
  .export a{color:#8f7bff;font-size:13px;font-weight:600}
  @media(max-width:860px){.grid{grid-template-columns:1fr}.card{padding:22px 20px}}
  @media(max-width:520px){.hero-title svg{width:44px;height:50px}.hero p{font-size:15px}.li .when{font-size:13px}}
</style>
</head>
<body>
<main class="wrap">
  <header class="hero">
    <div class="hero-title">
      <svg viewBox="0 0 64 72" fill="none"><path d="M32 3l26 9v22c0 17-11 29-26 35C17 63 6 51 6 34V12z" fill="url(#sg)" stroke="#b98bff" stroke-width="2"/><path d="M20 36l9 9 16-18" stroke="#1a1050" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/><defs><linearGradient id="sg" x1="8" y1="4" x2="56" y2="68"><stop stop-color="#b04dff"/><stop offset="1" stop-color="#5b3bff"/></linearGradient></defs></svg>
      <h1>Admin <span>Check-In</span></h1>
    </div>
    <p>Your status is automatically managed by your assigned tasks.</p>
  </header>

  <form method="post" class="card" id="checkinCard">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12h5l2.5-7 5 14L17 12h5"/></svg>Your Status</h2>

    <?php if ($error): ?><div class="err" role="alert"><?= $h($error) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
    <input type="hidden" name="next" value="<?= $h($next) ?>">

    <?php if ($hasOpenTasks): ?>
      <div class="task-state assigned" role="status">
        <div class="task-status-badge busy"><i></i>Busy</div>
        <div class="task-state-title">Task assigned · Busy</div>
        <p class="task-state-copy"><?= count($openTasks) ?> open task<?= count($openTasks) === 1 ? '' : 's' ?> assigned to you. Your check-in defaults to Busy; Free is unavailable until the tasks are closed.</p>
        <ul class="assigned-task-list">
          <?php foreach ($openTasks as $task): ?>
            <li class="assigned-task">
              <span class="assigned-task-title"><?= $h($task['title'] ?? 'Assigned task') ?></span>
              <?php if (!empty($task['assigned_by'])): ?><span class="assigned-task-by">Assigned by <?= $h($task['assigned_by']) ?></span><?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="task-state clear" role="status">
        <div class="task-status-badge free"><i></i>Free</div>
        <div class="task-state-title">No assigned tasks</div>
        <p class="task-state-copy">Your status is automatically set to Free when you have no open owner-assigned tasks.</p>
      </div>
    <?php endif; ?>

    <label class="note-label" for="note">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20l1-4L16.5 4.5a2.1 2.1 0 0 1 3 3L8 19z"/><path d="M14 7l3 3"/></svg>
      <span id="noteLabel">Note (Optional)</span>
    </label>
    <div class="note-box">
      <textarea id="note" name="note" maxlength="140" placeholder="Add a note... (why, or when you will be back)"><?= $h($noteVal) ?></textarea>
      <span class="count"><span id="noteCount">0</span> / 140</span>
    </div>

    <button type="submit" class="btn">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 12.5l5 5L20 6.5"/></svg>
      <?= $mine ? 'Update Check-In' : 'Check In Now' ?>
    </button>
  </form>
  <?php if ($mine): ?>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
    <input type="hidden" name="action" value="checkout">
    <button type="submit" class="btn" style="background:#8b1e2d;border-color:#c4384b;color:#fff">
      Check Out
    </button>
  </form>
  <?php endif; ?>

  <?php if ($isOwner): ?>
  <div class="grid" id="team">
    <section class="card">
      <div class="row-head">
        <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/></svg>Recent Logins</h2>
        <a href="<?= $h(vs_checkin_url()) ?>?export=csv">View All <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
      </div>
      <div class="list">
        <?php if (!$recentLogins): ?>
          <div class="empty">No sign-ins have been recorded yet.</div>
        <?php else: foreach (array_slice($recentLogins, 0, 5) as $login):
          $ts = strtotime((string)($login['at'] ?? '')) ?: 0;
          $who = (string)($login['admin'] ?? 'Admin');
          $meta = $statusMeta[$teamMap[$who]['status'] ?? ''] ?? null; ?>
          <div class="li">
            <span class="when"><?= $ts ? $h(date('M j, Y • H:i', $ts)) : '—' ?></span>
            <span class="recent-avatar"><?= avatar_html($who, 34, 'recent-login-avatar') ?></span>
            <span class="name"><?= $h($who) ?></span>
            <span class="pillcell"><?= $meta ? pill($meta) : '' ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>

    <section class="card team">
      <h2><svg viewBox="0 0 24 24" fill="currentColor"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3 0-7 1.5-7 4.5V20h14v-2.5C16 14.5 12 13 9 13zm8 .2c2.4.2 5 1.4 5 4V20h-4v-2.5c0-1.7-.7-3.2-1-4.3z"/></svg>Team Status</h2>
      <div class="list">
        <?php if (!$teamMap): ?>
          <div class="empty">No check-ins yet.</div>
        <?php else: foreach ($teamMap as $who => $t): ?>
          <div class="li">
            <?= avatar_html($who, 38, 'team-avatar') ?>
            <span class="name"><?= $h($who) ?></span>
            <span class="pillcell"><?= pill($statusMeta[$t['status']]) ?></span>
            <span class="ago"><?= $h(ago($t['ts'])) ?></span>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </section>
  </div>
  <?php endif; ?>
</main>

<script>
(function () {
  var note = document.getElementById('note');
  var lab = document.getElementById('noteLabel');
  var cnt = document.getElementById('noteCount');

  history.replaceState({ checkinLocked: true }, '', location.href);
  window.addEventListener('popstate', function () {
    history.pushState({ checkinLocked: true }, '', location.href);
  });
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) location.reload();
  });

  function sync() {
    note.required = false;
    lab.textContent = 'Note (Optional)';
    cnt.textContent = note.value.length;
  }
  note.addEventListener('input', sync);
  sync();
})();
</script>
</body>
</html>