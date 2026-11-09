<?php
/*
 * VibeStream admin panel - Assign tasks (SITE OWNER ONLY).
 * Put this file in the adminpanel folder next to header.php / checkin.php.
 *
 *  - Only the site owner can open it (anyone else is sent back to index.php).
 *  - Assigning a task:  saves it, sets that admin to "Busy" on the check-in team list,
 *                       and drops a notification in their bell menu.
 *  - When the admin presses "Mark done" on checkin.php (or you cancel it here) the task closes,
 *    and once their last open task is closed they go back to "Available".
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (!isset($_SESSION['AdminSession'])) {
    header('Location: ../login.php');
    exit;
}

// header.php prints the page shell. Buffer it so we can still redirect after checking the owner / saving a form.
ob_start();
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/checkin-lib.php';

if (empty($isSiteOwner)) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['assign_task_csrf'])) {
    $_SESSION['assign_task_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string)$_SESSION['assign_task_csrf'];
$esc  = static fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

$self = (string)($_SESSION['AdminSession'] ?? '');
$ownerName = trim((string)($adminUsername ?? '')) !== '' ? trim((string)$adminUsername) : ($self !== '' ? $self : 'Owner');
$hasPdo = isset($pdo) && $pdo instanceof PDO;

/** Drop a notification into an admin's bell menu (the same user_notifications table the header reads). */
function vs_at_notify($pdo, $conn, int $userId, string $message, string $link): bool
{
    if ($userId <= 0 || !($pdo instanceof PDO)) {
        return false;
    }
    try {
        if (function_exists('vs_notifications_ensure_table') && $conn instanceof mysqli) {
            vs_notifications_ensure_table($conn);
        }
    } catch (Throwable $e) {
        // table probably exists already
    }
    $now = date('Y-m-d H:i:s');   // PHP clock (Pakistan time), same clock the bell uses for "x minutes ago"
    $attempts = [
        ['INSERT INTO user_notifications (user_id, message, link, is_read, created_at) VALUES (?, ?, ?, 0, ?)', [$userId, $message, $link, $now]],
        ['INSERT INTO user_notifications (user_id, message, link) VALUES (?, ?, ?)', [$userId, $message, $link]],
    ];
    foreach ($attempts as [$sql, $args]) {
        try {
            $st = $pdo->prepare($sql);
            if ($st && $st->execute($args)) {
                return true;
            }
        } catch (Throwable $e) {
            error_log('assign-task notify failed: ' . $e->getMessage());
        }
    }
    return false;
}

/** Add a finished task's reward to that admin's pay for this month (read by admin-pay.php). */
function vs_at_bonus($pdo, array $task, float $amount): bool
{
    if (!($pdo instanceof PDO) || $amount <= 0) {
        return false;
    }
    try {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_bonus (
            task_id VARCHAR(32) NOT NULL PRIMARY KEY,
            month CHAR(7) NOT NULL,
            username VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reason VARCHAR(200) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL,
            KEY idx_month_user (month, username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $name = (string)($task['admin'] ?? '');
        $st = $pdo->prepare('INSERT IGNORE INTO admin_pay_bonus (task_id, month, username, amount, reason, created_at) VALUES (?, ?, ?, ?, ?, ?)');
        $ok = $st && $st->execute([(string)($task['id'] ?? ''), date('Y-m'), mb_strtolower(trim($name)), $amount,
            mb_substr('Task done: ' . (string)($task['title'] ?? ''), 0, 200), date('Y-m-d H:i:s')]);
        if ($ok) {
            try {
                $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_log (id INT AUTO_INCREMENT PRIMARY KEY, text VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
                $pdo->prepare('INSERT INTO admin_pay_log (text, created_at) VALUES (?, ?)')
                    ->execute([mb_substr('+' . number_format($amount, 2) . ' task bonus for ' . $name . ': ' . (string)($task['title'] ?? ''), 0, 255), date('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
            }
        }
        return (bool)$ok;
    } catch (Throwable $e) {
        error_log('assign-task bonus failed: ' . $e->getMessage());
        return false;
    }
}

$payCur = defined('VS_PAY_CURRENCY') ? VS_PAY_CURRENCY : 'PKR';

// ---- Who can be given a task: every admin account (not the owner) + anyone already on the check-in team ----
$team = [];   // username => user id (0 when we could not find the account)
if ($hasPdo && function_exists('vs_cols') && function_exists('vs_pick')) {
    try {
        $uc  = vs_cols($pdo, 'users');
        $idc = vs_pick($uc, ['id', 'user_id']);
        $unc = vs_pick($uc, ['username', 'user_name', 'login', 'uname']);
        $rc  = vs_pick($uc, ['role', 'user_role', 'account_type', 'type']);
        if ($idc && $unc && $rc) {
            $rows = $pdo->query("SELECT `$idc` AS uid, `$unc` AS uname FROM `users` WHERE LOWER(`$rc`) = 'admin' ORDER BY `$unc`")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $n = trim((string)$r['uname']);
                if ($n !== '') {
                    $team[$n] = (int)$r['uid'];
                }
            }
        }
    } catch (Throwable $ex) {
        error_log('assign-task: could not list admins: ' . $ex->getMessage());
    }
}
$checkins = vs_checkin_all();
foreach ($checkins as $n => $r) {
    if (!isset($team[$n])) {
        $team[$n] = 0;
    }
}
foreach ($checkins as $n => $r) {
    if (!empty($r['is_owner'])) {
        unset($team[$n]);
    }
}
unset($team[$self], $team[$ownerName]);

// Current status of everyone (Offline when they have not been active for a while), then sort: free people first.
$statusOf = [];
foreach ($team as $n => $uid) {
    $rec = $checkins[$n] ?? null;
    $statusOf[$n] = $rec ? vs_checkin_effective($rec) : 'Offline';
}
$statusRank = ['Available' => 0, 'Moderating' => 1, 'Busy' => 2, 'On break' => 3, 'Away' => 4, 'Offline' => 5];
uksort($team, static function ($a, $b) use ($statusOf, $statusRank) {
    $ra = $statusRank[$statusOf[$a]] ?? 9;
    $rb = $statusRank[$statusOf[$b]] ?? 9;
    return $ra <=> $rb ?: strcasecmp((string)$a, (string)$b);
});
$availableNow = array_keys(array_filter($statusOf, static fn($s) => $s === 'Available'));

// ---- Forms ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $res = 'failed';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $res = 'expired';
    } elseif ($act === 'assign') {
        $target  = trim((string)($_POST['target'] ?? ''));
        $title   = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 100));
        $details = trim(mb_substr((string)($_POST['details'] ?? ''), 0, 500));
        $reward  = max(0.0, min(10000000.0, round((float)($_POST['reward'] ?? 0), 2)));
        if (!array_key_exists($target, $team)) {
            $res = 'badadmin';
        } elseif ($title === '') {
            $res = 'notitle';
        } elseif (!vs_task_assign($target, $title, $details, $ownerName, $reward)) {
            $res = 'failed';
        } else {
            $link = basename(__DIR__) . '/my-tasks.php';   // the bell prefixes "../" to this
            $msg  = mb_substr($ownerName . ' added a task for you: ' . $title, 0, 250);
            $res  = vs_at_notify($hasPdo ? $pdo : null, $conn ?? null, (int)$team[$target], $msg, $link) ? 'assigned' : 'assigned_nobell';
        }
    } elseif ($act === 'cancel' || $act === 'done') {
        // Only the owner closes tasks: "done" when the work is finished, "cancel" when it is no longer needed.
        $tid  = (string)($_POST['task_id'] ?? '');
        $task = vs_task_all()[$tid] ?? null;
        $newStatus = $act === 'done' ? 'done' : 'cancelled';
        if (!$task) {
            $res = 'missing';
        } elseif (!vs_task_finish($tid, $ownerName, $newStatus)) {
            $res = 'closed';
        } else {
            $res = $newStatus;
            $reward = (float)($task['reward'] ?? 0);
            if ($newStatus === 'done' && $reward > 0) {
                // Finished task = pay raise: the reward goes into this month's payroll (admin-pay.php)
                if (vs_at_bonus($hasPdo ? $pdo : null, $task, $reward)) {
                    $res = 'done_bonus';
                    $aid = (int)($team[$task['admin']] ?? 0);
                    if ($aid > 0) {
                        $cur = defined('VS_PAY_CURRENCY') ? VS_PAY_CURRENCY : 'PKR';
                        vs_at_notify($pdo, $conn ?? null, $aid,
                            mb_substr($ownerName . ' added a bonus of ' . $cur . ' ' . number_format($reward, 2) . ' to your pay for: ' . (string)$task['title'], 0, 250),
                            basename(__DIR__) . '/my-tasks.php');
                    }
                } else {
                    $res = 'done_nobonus';
                }
            }
        }
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: assign-task.php?msg=' . urlencode($res));
    exit;
}

$msgs = [
    'assigned'        => ['ok',  'Task assigned. The admin is now Busy and has been notified.'],
    'assigned_nobell' => ['ok',  'Task assigned and the admin is now Busy, but the bell notification could not be saved. They will still see the task on their check-in page.'],
    'done'            => ['ok',  'Task marked done. If it was their last one, that admin is Available again.'],
    'done_bonus'      => ['ok',  'Task marked done. Its reward was added to that admin\'s pay for this month (see Payroll).'],
    'done_nobonus'    => ['bad', 'Task marked done, but the reward could not be added to Payroll. Add it there with Adjust Hours.'],
    'cancelled'       => ['ok',  'Task cancelled. If it was their last one, that admin is Available again.'],
    'badadmin'        => ['bad', 'Pick an admin from the list.'],
    'notitle'         => ['bad', 'Give the task a title.'],
    'failed'          => ['bad', 'Could not save the task. Check that the adminpanel/data folder is writable.'],
    'expired'         => ['bad', 'Session expired. Try again.'],
    'missing'         => ['bad', 'That task no longer exists.'],
    'closed'          => ['bad', 'That task was already finished.'],
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? null;

// ---- Data for the page ----
$allTasks = vs_task_all();
$openTasks = [];
$closedTasks = [];
$openCount = [];
foreach (array_reverse($allTasks, true) as $t) {
    if (($t['status'] ?? '') === 'open') {
        $openTasks[] = $t;
        $openCount[$t['admin'] ?? ''] = ($openCount[$t['admin'] ?? ''] ?? 0) + 1;
    } elseif (count($closedTasks) < 15) {
        $closedTasks[] = $t;
    }
}

/** <time> tag that the script at the bottom turns into the viewer's own local time. */
$lt = static function ($at) use ($esc): string {
    $iso = '';
    try {
        if ((string)$at !== '') {
            $iso = (new DateTimeImmutable((string)$at))->format('c');
        }
    } catch (Exception $ex) {
    }
    return '<time class="at-time" datetime="' . $esc($iso) . '">' . $esc($at) . '</time>';
};
?>

      <style>
        .at-page { --at-card:linear-gradient(160deg,#0d1334,#080c28); --at-card2:#0d1440; --at-line:#1d2b63; --at-text:#eef2ff; --at-muted:#8a9bc7;
                   --at-ico-bg:#1e2a63; --at-head-ico:#c4b5fd; --at-chip-bg:#0d1440; --at-chip-bd:#26357a; --at-chip:#c7d2fe; --at-input:#0a1030;
                   --at-ok-bg:rgba(16,185,129,.16); --at-ok:#34d399; --at-bad-bg:rgba(244,63,94,.16); --at-bad:#fb7185; --at-hover:rgba(99,102,241,.08);
                   --at-ghost-bd:#4c3fd0; --at-ghost:#c7d2fe; --at-shadow:0 8px 28px rgba(2,4,20,.35); color:var(--at-text); }
        html[data-theme="light"] .at-page { --at-card:#ffffff; --at-card2:#f5f7fd; --at-line:#dde3f3; --at-text:#1b2447; --at-muted:#5f6c93;
                   --at-ico-bg:#ece9fe; --at-head-ico:#6d28d9; --at-chip-bg:#eef1fb; --at-chip-bd:#d5dcf2; --at-chip:#4338ca; --at-input:#ffffff;
                   --at-ok-bg:#d1fae5; --at-ok:#047857; --at-bad-bg:#ffe4e6; --at-bad:#be123c; --at-hover:#f5f3ff;
                   --at-ghost-bd:#c4b5fd; --at-ghost:#5b21b6; --at-shadow:0 6px 20px rgba(40,50,110,.08); }

        .at-head { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
        .at-head-ico { display:grid; place-items:center; width:48px; height:48px; border-radius:14px; background:var(--at-ico-bg); color:var(--at-head-ico); font-size:1.5rem; }
        .at-head h1 { margin:0; font-size:1.45rem; font-weight:800; color:var(--at-text); }
        .at-head p { margin:2px 0 0; color:var(--at-muted); font-size:.85rem; }
        .at-flash { margin-bottom:14px; padding:10px 14px; border-radius:10px; font-size:.88rem; border:1px solid var(--at-chip-bd); }
        .at-flash.ok  { background:var(--at-ok-bg);  color:var(--at-ok);  border-color:transparent; }
        .at-flash.bad { background:var(--at-bad-bg); color:var(--at-bad); border-color:transparent; }

        .at-grid { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.2fr); gap:14px; align-items:start; }
        .at-col { display:flex; flex-direction:column; gap:14px; min-width:0; }
        .at-card { border:1px solid var(--at-line); border-radius:14px; background:var(--at-card); box-shadow:var(--at-shadow); min-width:0; }
        .at-card-head { display:flex; align-items:center; gap:12px; padding:14px 16px; border-bottom:1px solid var(--at-line); }
        .at-card-ico { display:grid; place-items:center; width:40px; height:40px; flex:0 0 40px; border-radius:12px; background:var(--at-ico-bg); color:var(--at-head-ico); font-size:1.2rem; }
        .at-card-head h2 { margin:0; font-size:1rem; font-weight:800; color:var(--at-text); }
        .at-card-head p { margin:1px 0 0; color:var(--at-muted); font-size:.76rem; }
        .at-card-head > div { flex:1; min-width:0; }
        .at-chip { flex:none; padding:4px 12px; border:1px solid var(--at-chip-bd); border-radius:8px; background:var(--at-chip-bg); color:var(--at-chip); font-size:.76rem; font-weight:700; white-space:nowrap; }

        .at-form { padding:14px 16px 16px; }
        .at-form label { display:block; margin:12px 0 6px; font-size:.8rem; font-weight:600; color:var(--at-muted); }
        .at-form label:first-child { margin-top:0; }
        .at-form select, .at-form input[type=text], .at-form textarea { width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid var(--at-line); background:var(--at-input); color:var(--at-text); font-size:.92rem; font-family:inherit; }
        .at-form textarea { min-height:84px; resize:vertical; }
        .at-form select:focus, .at-form input:focus, .at-form textarea:focus { outline:2px solid #8b5cf6; outline-offset:1px; }
        .at-hint { margin:6px 0 0; color:var(--at-muted); font-size:.74rem; }
        .at-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:7px 14px; border-radius:8px; border:1px solid var(--at-ghost-bd); background:transparent; color:var(--at-ghost); font-size:.8rem; font-weight:600; cursor:pointer; text-decoration:none; white-space:nowrap; }
        .at-btn:hover { background:var(--at-hover); color:var(--at-ghost); }
        .at-btn.primary { width:100%; margin-top:16px; padding:11px 14px; font-size:.92rem; border-color:#7c3aed; background:linear-gradient(90deg,#7c3aed,#6366f1); color:#fff; box-shadow:0 0 12px rgba(124,58,237,.3); }
        .at-btn.primary:hover { background:linear-gradient(90deg,#8b5cf6,#6d73f5); color:#fff; }
        .at-btn.danger { border-color:var(--at-bad); color:var(--at-bad); }
        .at-btn.ok { border-color:var(--at-ok); color:var(--at-ok); }
        .at-actions { display:flex; flex-direction:column; gap:6px; flex:none; }
        .at-actions form { margin:0; }
        .at-avail { display:flex; flex-wrap:wrap; gap:8px; padding:14px 16px; }
        .at-avail-pill { display:inline-flex; align-items:center; gap:7px; padding:6px 14px; border-radius:999px; background:var(--at-ok-bg); color:var(--at-ok); font-size:.85rem; font-weight:700; }
        .at-avail-pill .at-dot { margin:0; background:var(--at-ok); }

        .at-list { margin:0; padding:0; list-style:none; }
        .at-item { display:flex; align-items:flex-start; gap:12px; padding:12px 16px; border-top:1px solid var(--at-line); }
        .at-item:first-child { border-top:0; }
        .at-item:hover { background:var(--at-hover); }
        .at-item-main { flex:1; min-width:0; }
        .at-item-title { font-weight:700; font-size:.92rem; color:var(--at-text); overflow-wrap:anywhere; }
        .at-item-sub { margin-top:2px; color:var(--at-muted); font-size:.76rem; }
        .at-item-details { margin-top:4px; color:var(--at-muted); font-size:.8rem; overflow-wrap:anywhere; }
        .at-empty { padding:22px 16px; color:var(--at-muted); font-size:.85rem; text-align:center; }
        .at-reward { color:#fbbf24; font-weight:700; text-shadow:0 0 8px rgba(251,191,36,.6); font-size:.76rem; }
        .at-form input[type=number] { width:100%; box-sizing:border-box; padding:10px 12px; border-radius:10px; border:1px solid rgba(168,85,247,.5); background:var(--at-input); color:var(--at-text); font-size:.92rem; box-shadow:inset 0 0 12px rgba(168,85,247,.12); }
        .at-form input[type=number]:focus { outline:none; border-color:var(--neon-b); box-shadow:0 0 0 2px rgba(34,211,238,.35), 0 0 18px rgba(34,211,238,.45); }
        .at-who { display:inline-block; padding:2px 10px; border-radius:999px; background:var(--at-chip-bg); border:1px solid var(--at-chip-bd); color:var(--at-chip); font-size:.72rem; font-weight:700; }

        .at-table-wrap { overflow-x:auto; padding:0 16px 8px; }
        .at-table { width:100%; border-collapse:collapse; font-size:.84rem; }
        .at-table th { padding:9px 8px; color:var(--at-muted); font-size:.72rem; font-weight:700; text-align:left; text-transform:uppercase; letter-spacing:.04em; }
        .at-table td { padding:9px 8px; border-top:1px solid var(--at-line); color:var(--at-text); vertical-align:middle; }
        .at-table td.muted { color:var(--at-muted); font-size:.78rem; }
        .at-dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:6px; }
        .at-badge { display:inline-block; padding:3px 10px; border-radius:6px; font-size:.72rem; font-weight:700; }
        .at-badge.ok  { background:var(--at-ok-bg);  color:var(--at-ok); }
        .at-badge.bad { background:var(--at-bad-bg); color:var(--at-bad); }

                /* ---------- neon look ---------- */
        .at-page { --neon-a:#a855f7; --neon-b:#22d3ee; --neon-c:#f472b6; }
        html[data-theme="light"] .at-page { --neon-a:#7c3aed; --neon-b:#0891b2; --neon-c:#db2777; }
        .at-page h1 { background:linear-gradient(90deg,var(--neon-a),var(--neon-b)); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; text-shadow:none; filter:drop-shadow(0 0 10px rgba(168,85,247,.55)); }
        .at-head-ico, .at-card-ico { color:var(--neon-b); border:1px solid rgba(34,211,238,.55); box-shadow:0 0 14px rgba(34,211,238,.4), inset 0 0 10px rgba(168,85,247,.25); text-shadow:0 0 10px var(--neon-b); }
        .at-card { border:1px solid rgba(168,85,247,.6); box-shadow:0 0 0 1px rgba(34,211,238,.12), 0 0 24px rgba(168,85,247,.28), inset 0 0 40px rgba(34,211,238,.05); transition:box-shadow .2s, border-color .2s; }
        .at-card:hover { border-color:rgba(34,211,238,.75); box-shadow:0 0 0 1px rgba(34,211,238,.3), 0 0 32px rgba(34,211,238,.35), inset 0 0 40px rgba(168,85,247,.07); }
        .at-card-head { border-bottom:1px solid rgba(168,85,247,.35); }
        .at-card-head h2 { text-shadow:0 0 12px rgba(168,85,247,.6); }
        .at-chip { color:var(--neon-b); border-color:rgba(34,211,238,.6); box-shadow:0 0 10px rgba(34,211,238,.3); text-shadow:0 0 8px rgba(34,211,238,.6); }
        .at-dot { box-shadow:0 0 0 3px rgba(255,255,255,.07), 0 0 10px 2px rgba(120,200,255,.6); }
        .at-flash.ok, .at-flash.bad { border:1px solid currentColor; box-shadow:0 0 16px -2px currentColor; }
        .at-badge.ok { box-shadow:0 0 10px rgba(52,211,153,.45); text-shadow:0 0 6px rgba(52,211,153,.7); }
        .at-badge.bad { box-shadow:0 0 10px rgba(251,113,133,.45); text-shadow:0 0 6px rgba(251,113,133,.7); }
        .at-table td, .at-table th { border-color:rgba(168,85,247,.25); }
        .at-form select, .at-form input[type=text], .at-form textarea { border-color:rgba(168,85,247,.5); box-shadow:inset 0 0 12px rgba(168,85,247,.12); }
        .at-form select:focus, .at-form input:focus, .at-form textarea:focus { outline:none; border-color:var(--neon-b); box-shadow:0 0 0 2px rgba(34,211,238,.35), 0 0 18px rgba(34,211,238,.45); }
        .at-btn { border-color:rgba(168,85,247,.7); box-shadow:0 0 10px rgba(168,85,247,.3); }
        .at-btn:hover { box-shadow:0 0 18px rgba(168,85,247,.6); }
        .at-btn.primary { border-color:transparent; background:linear-gradient(90deg,#a855f7,#22d3ee); color:#05071a; font-weight:800; letter-spacing:.02em; box-shadow:0 0 18px rgba(168,85,247,.65), 0 0 34px rgba(34,211,238,.35); }
        .at-btn.primary:hover { background:linear-gradient(90deg,#c084fc,#67e8f9); box-shadow:0 0 24px rgba(192,132,252,.85), 0 0 44px rgba(34,211,238,.5); color:#05071a; }
        .at-btn.ok { box-shadow:0 0 12px rgba(52,211,153,.45); } .at-btn.ok:hover { box-shadow:0 0 22px rgba(52,211,153,.8); }
        .at-btn.danger { box-shadow:0 0 12px rgba(251,113,133,.4); } .at-btn.danger:hover { box-shadow:0 0 22px rgba(251,113,133,.75); }
        .at-avail-pill { border:1px solid rgba(52,211,153,.7); box-shadow:0 0 14px rgba(52,211,153,.45); text-shadow:0 0 8px rgba(52,211,153,.7); }
        .at-avail-pill .at-dot { box-shadow:0 0 10px 2px rgba(52,211,153,.9); }
        .at-who { color:var(--neon-c); border-color:rgba(244,114,182,.6); box-shadow:0 0 10px rgba(244,114,182,.3); }
        .at-item:hover { box-shadow:inset 3px 0 0 var(--neon-b), inset 0 0 24px rgba(34,211,238,.08); }


        @media (max-width:991.98px) { .at-grid { grid-template-columns:minmax(0,1fr); } }
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 at-page">
          <div class="at-head">
            <span class="at-head-ico"><i class="bi bi-list-check" aria-hidden="true"></i></span>
            <div>
              <h1>Assign tasks</h1>
              <p>Owner only. The admin is marked Busy and notified until you mark the task done, and a finished task can raise their pay.</p>
            </div>
          </div>

          <?php if ($flash): ?>
            <div class="at-flash <?php echo $esc($flash[0]); ?>" role="status"><?php echo $esc($flash[1]); ?></div>
          <?php endif; ?>

          <div class="at-grid">
            <div class="at-col">
              <!-- Who is free -->
              <section class="at-card" aria-label="Available admins">
                <div class="at-card-head">
                  <span class="at-card-ico"><i class="bi bi-person-check" aria-hidden="true"></i></span>
                  <div><h2>Available now</h2><p>Online and not working on a task</p></div>
                  <span class="at-chip"><?php echo count($availableNow); ?> free</span>
                </div>
                <?php if (!$availableNow): ?>
                  <p class="at-empty">Nobody is available right now.</p>
                <?php else: ?>
                  <div class="at-avail">
                    <?php foreach ($availableNow as $n): ?>
                      <span class="at-avail-pill"><span class="at-dot"></span><?php echo $esc($n); ?></span>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>

              <!-- New task -->
              <section class="at-card" aria-label="Assign a task">
                <div class="at-card-head">
                  <span class="at-card-ico"><i class="bi bi-plus-circle" aria-hidden="true"></i></span>
                  <div><h2>New task</h2><p>Pick an admin and describe the work</p></div>
                </div>
                <?php if (!$team): ?>
                  <p class="at-empty">No other admins found yet.</p>
                <?php else: ?>
                <form method="post" class="at-form">
                  <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
                  <input type="hidden" name="action" value="assign">
                  <label for="atTarget">Admin</label>
                  <select id="atTarget" name="target" required>
                    <option value="">Choose an admin…</option>
                    <?php foreach ($team as $n => $uid): ?>
                      <option value="<?php echo $esc($n); ?>"><?php echo $esc($n . ' — ' . $statusOf[$n]); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label for="atTitle">Task</label>
                  <input type="text" id="atTitle" name="title" maxlength="100" required placeholder="e.g. Review the reported videos">
                  <label for="atDetails">Details (optional)</label>
                  <textarea id="atDetails" name="details" maxlength="500" placeholder="Anything they need to know"></textarea>
                  <label for="atReward">Reward when done (<?php echo $esc($payCur); ?>, optional)</label>
                  <input type="number" id="atReward" name="reward" min="0" max="10000000" step="0.01" value="0" inputmode="decimal">
                  <p class="at-hint">Added to their pay for the month once you mark the task done. Leave 0 for no reward.</p>
                  <p class="at-hint">They will show as Busy on the team list and get a bell notification.</p>
                  <button type="submit" class="at-btn primary"><i class="bi bi-send" aria-hidden="true"></i> Assign task</button>
                </form>
                <?php endif; ?>
              </section>

              <!-- Team status -->
              <section class="at-card" aria-label="Team status">
                <div class="at-card-head">
                  <span class="at-card-ico"><i class="bi bi-people" aria-hidden="true"></i></span>
                  <div><h2>Team right now</h2><p>Status from the admin check-in</p></div>
                </div>
                <?php if (!$team): ?>
                  <p class="at-empty">No admins yet.</p>
                <?php else: ?>
                <div class="at-table-wrap">
                  <table class="at-table">
                    <thead><tr><th>Admin</th><th>Status</th><th>Open tasks</th></tr></thead>
                    <tbody>
                      <?php foreach ($team as $n => $uid):
                          $eff = $statusOf[$n]; ?>
                        <tr>
                          <td><?php echo $esc($n); ?></td>
                          <td><span class="at-dot" style="background:<?php echo $esc(vs_checkin_color($eff)); ?>"></span><?php echo $esc($eff); ?></td>
                          <td><?php echo (int)($openCount[$n] ?? 0); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </section>
            </div>

            <div class="at-col">
              <!-- Open tasks -->
              <section class="at-card" aria-label="Open tasks">
                <div class="at-card-head">
                  <span class="at-card-ico"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                  <div><h2>In progress</h2><p>Press Mark done when the work is finished</p></div>
                  <span class="at-chip"><?php echo count($openTasks); ?> open</span>
                </div>
                <?php if (!$openTasks): ?>
                  <p class="at-empty"><strong>All finished.</strong> There is nothing left to do.</p>
                <?php else: ?>
                <ul class="at-list">
                  <?php foreach ($openTasks as $t): ?>
                    <li class="at-item">
                      <div class="at-item-main">
                        <div class="at-item-title"><?php echo $esc($t['title'] ?? ''); ?></div>
                        <?php if (!empty($t['details'])): ?><div class="at-item-details"><?php echo $esc($t['details']); ?></div><?php endif; ?>
                        <div class="at-item-sub"><span class="at-who"><?php echo $esc($t['admin'] ?? ''); ?></span> &middot; assigned <?php echo $lt($t['assigned_at'] ?? ''); ?><?php if (!empty($t['reward'])): ?> &middot; <span class="at-reward"><?php echo $esc($payCur . ' ' . number_format((float)$t['reward'], 2)); ?> reward</span><?php endif; ?></div>
                      </div>
                      <div class="at-actions">
                        <form method="post" onsubmit="return confirm('Mark this task as done? The admin becomes Available if it was their last one.');">
                          <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
                          <input type="hidden" name="action" value="done">
                          <input type="hidden" name="task_id" value="<?php echo $esc($t['id'] ?? ''); ?>">
                          <button type="submit" class="at-btn ok"><i class="bi bi-check-lg" aria-hidden="true"></i> Mark done</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Cancel this task?');">
                          <input type="hidden" name="csrf" value="<?php echo $esc($csrf); ?>">
                          <input type="hidden" name="action" value="cancel">
                          <input type="hidden" name="task_id" value="<?php echo $esc($t['id'] ?? ''); ?>">
                          <button type="submit" class="at-btn danger">Cancel</button>
                        </form>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <?php endif; ?>
              </section>

              <!-- History -->
              <section class="at-card" aria-label="Finished tasks">
                <div class="at-card-head">
                  <span class="at-card-ico"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                  <div><h2>Finished</h2><p>Latest done and cancelled tasks</p></div>
                </div>
                <?php if (!$closedTasks): ?>
                  <p class="at-empty">Nothing finished yet.</p>
                <?php else: ?>
                <div class="at-table-wrap">
                  <table class="at-table">
                    <thead><tr><th>Admin</th><th>Task</th><th>Result</th><th>When</th></tr></thead>
                    <tbody>
                      <?php foreach ($closedTasks as $t): $done = ($t['status'] ?? '') === 'done'; ?>
                        <tr>
                          <td><?php echo $esc($t['admin'] ?? ''); ?></td>
                          <td><?php echo $esc($t['title'] ?? ''); ?><?php if (!empty($t['reward']) && $done): ?><span class="at-reward" style="display:block"><?php echo $esc('+' . $payCur . ' ' . number_format((float)$t['reward'], 2)); ?></span><?php endif; ?></td>
                          <td><span class="at-badge <?php echo $done ? 'ok' : 'bad'; ?>"><?php echo $done ? 'Done' : 'Cancelled'; ?></span></td>
                          <td class="muted"><?php echo $lt($t['closed_at'] ?? ''); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php endif; ?>
              </section>
            </div>
          </div>
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
  // Show times in this computer's local time
  (function () {
    function pad(v) { return String(v).padStart(2, '0'); }
    document.querySelectorAll('time.at-time').forEach(function (el) {
      var d = new Date(el.getAttribute('datetime'));
      if (isNaN(d.getTime())) { return; }
      el.textContent = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    });
  })();
  </script>
</body>
</html>