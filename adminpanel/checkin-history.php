<?php
require_once __DIR__ . '/checkin-lib.php';

$admin = vs_checkin_admin_name();
if (!$admin) { http_response_code(403); exit('Please log in first.'); }
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/checkin-owner-names.php';
require_once __DIR__ . '/checkin-insights.php';
require_once __DIR__ . '/work-hours.php';

$isOwner = false;
try {
    $isOwner = isset($pdo) && $pdo instanceof PDO && vs_is_owner($pdo);
} catch (Throwable $error) {
    error_log('Check-in history owner check failed: ' . $error->getMessage());
}
if (!$isOwner) { http_response_code(403); exit('Only the owner can view check-in history.'); }

$ownerNames = vs_checkin_owner_names($pdo);
if (empty($_SESSION['vs_csrf'])) $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    if (!hash_equals($_SESSION['vs_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash = 'Session expired, try again.';
    } else {
        [$okArchive, $resArchive] = vs_checkin_archive_history((int)($_POST['days'] ?? 90));
        $flash = $okArchive
            ? ($resArchive > 0 ? 'Archived ' . $resArchive . ' old event' . ($resArchive === 1 ? '' : 's') . ' into a separate file.' : 'Nothing was old enough to archive.')
            : (string)$resArchive;
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (!function_exists('csv_safe')) {
    function csv_safe($v) {
        $v = (string)$v;
        return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
    }
}

// ---- load events (newest first, owner excluded) ----
$all = [];
$admins = [];
$events = [];
foreach (array_reverse(vs_checkin_annotate_devices(vs_checkin_json_read(VS_CHECKIN_HISTORY))) as $e) {
    if (!is_array($e)) continue;
    $who = trim((string)($e['admin'] ?? ''));
    if (isset($ownerNames[mb_strtolower($who)])) continue;
    $e['_ts'] = strtotime((string)($e['at'] ?? '')) ?: 0;
    $all[] = $e;
    if ($who !== '') $admins[$who] = true;
    $ev = trim((string)($e['event'] ?? ''));
    if ($ev !== '') $events[$ev] = true;
}
ksort($admins, SORT_NATURAL | SORT_FLAG_CASE);
ksort($events, SORT_NATURAL | SORT_FLAG_CASE);

// ---- filters ----
$fAdmin = trim((string)($_GET['admin'] ?? ''));
$fEvent = trim((string)($_GET['event'] ?? ''));
$fFrom  = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$fTo    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
$fQ     = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$fFlag  = !empty($_GET['flag']) ? '1' : '';
$fromTs = $fFrom ? strtotime($fFrom . ' 00:00:00') : 0;
$toTs   = $fTo   ? strtotime($fTo . ' 23:59:59') : 0;

$rows = array_values(array_filter($all, function ($e) use ($fAdmin, $fEvent, $fromTs, $toTs, $fQ, $fFlag) {
    if ($fFlag !== '' && empty($e['_flags'])) return false;
    if ($fAdmin !== '' && trim((string)($e['admin'] ?? '')) !== $fAdmin) return false;
    if ($fEvent !== '' && trim((string)($e['event'] ?? '')) !== $fEvent) return false;
    if ($fromTs && $e['_ts'] < $fromTs) return false;
    if ($toTs && $e['_ts'] > $toTs) return false;
    if ($fQ !== '') {
        $hay = mb_strtolower(($e['admin'] ?? '') . ' ' . ($e['note'] ?? '') . ' ' . ($e['status'] ?? '') . ' ' . ($e['event'] ?? ''));
        if (mb_strpos($hay, mb_strtolower($fQ)) === false) return false;
    }
    return true;
}));

$filters = ['admin' => $fAdmin, 'event' => $fEvent, 'from' => $fFrom, 'to' => $fTo, 'q' => $fQ, 'flag' => $fFlag];
$qs = function (array $extra = []) use ($filters) {
    return '?' . http_build_query(array_filter(array_merge($filters, $extra), fn($v) => $v !== '' && $v !== null));
};

// ---- CSV export of the filtered rows ----
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="checkin-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Admin', 'Event', 'Status', 'Note', 'IP', 'Browser', 'Flags']);
    foreach ($rows as $e) {
        fputcsv($out, array_map('csv_safe', [$e['at'] ?? '', $e['admin'] ?? '', $e['event'] ?? '', $e['status'] ?? '',
                                              $e['note'] ?? '', $e['ip'] ?? '', $e['ua'] ?? '', implode(', ', $e['_flags'] ?? [])]));
    }
    exit;
}

// ---- time online report (uses the date filters; defaults to the last 7 days) ----
$repTo   = $toTs ?: (strtotime('tomorrow') - 1);
$repFrom = $fromTs ?: strtotime('today -6 days');
if ($repFrom > $repTo) $repFrom = strtotime(date('Y-m-d', $repTo));
$sessions = vs_checkin_sessions(array_reverse($all));
if ($fAdmin !== '') $sessions = array_values(array_filter($sessions, fn($s) => $s[0] === $fAdmin));
$hoursMap = vs_checkin_hours($sessions, $repFrom, $repTo);
$repDays = [];
$dayCursor = strtotime(date('Y-m-d', $repFrom));
$dayCount = (int)floor((strtotime(date('Y-m-d', $repTo)) - $dayCursor) / 86400) + 1;
if ($dayCount <= 14) {
    for ($i = 0; $i < $dayCount; $i++) { $repDays[] = date('Y-m-d', strtotime('+' . $i . ' day', $dayCursor)); }
}
$repTotals = [];
foreach ($hoursMap as $who => $perDay) $repTotals[$who] = array_sum($perDay);
arsort($repTotals);
$repMax = $repTotals ? max($repTotals) : 0;

if (($_GET['export'] ?? '') === 'hours') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="time-online-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Admin', 'Date', 'Minutes online']);
    foreach ($repTotals as $who => $_total) {
        $perDay = $hoursMap[$who];
        ksort($perDay);
        foreach ($perDay as $day => $sec) fputcsv($out, [csv_safe($who), $day, (int)round($sec / 60)]);
    }
    exit;
}


// ---- attendance vs. working hours ----
$whAdmins = vs_wh_read()['admins'];
if ($fAdmin !== '') $whAdmins = array_filter($whAdmins, fn($r) => ($r['name'] ?? '') === $fAdmin);
$attDays = $repDays;
if (!$attDays) {
    for ($i = 13; $i >= 0; $i--) $attDays[] = date('Y-m-d', strtotime('-' . $i . ' day', $repTo));
}
$attSessions = [];
foreach ($sessions as $attS) $attSessions[mb_strtolower($attS[0])][] = $attS;
$attNow = time();
$attCell = function (array $st) use ($h) {
    switch ($st['state']) {
        case 'ok':     return '<span class="att ok">On time</span>';
        case 'flag':
            $p = [];
            if ($st['late'])  $p[] = 'Late ' . vs_wh_fmt_min((int)$st['late']);
            if ($st['early']) $p[] = 'Left ' . vs_wh_fmt_min((int)$st['early']) . ' early';
            return '<span class="att warn">' . $h(implode(' · ', $p)) . '</span>';
        case 'notin':  return '<span class="att warn">Not in yet</span>';
        case 'absent': return '<span class="att bad">Absent</span>';
    }
    return '<span class="mut">—</span>';
};

// ---- pagination ----
$perPage = 25;
$total = count($rows);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$slice = array_slice($rows, ($page - 1) * $perPage, $perPage);

$pillClass = ['Available' => ['Free', 'free'], 'Busy' => ['Busy', 'busy'], 'Moderating' => ['Moderating', 'mod'],
              'Away' => ['Away', 'away'], 'On break' => ['On break', 'break'], 'Offline' => ['Offline', 'offline']];
$pill = function ($status) use ($pillClass, $h) {
    if (!isset($pillClass[$status])) return $status !== '' ? $h($status) : '<span class="mut">—</span>';
    return '<span class="pill pill-' . $pillClass[$status][1] . '"><i></i>' . $h($pillClass[$status][0]) . '</span>';
};
$checkinUrl = vs_checkin_url();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Check-In History · VibeStream</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
  :root{--bg:#070914;--line:rgba(139,111,255,.22);--txt:#f4f5ff;--mut:#9aa0c3;--free:#22e08a;--busy:#ffb020;--mod:#1e9bff;--break:#c9bbff}
  *{box-sizing:border-box}
  html,body{margin:0}
  body{min-height:100vh;color:var(--txt);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:
    radial-gradient(ellipse 60% 40% at 0% 0%,rgba(120,50,255,.45),transparent 60%),
    radial-gradient(ellipse 50% 40% at 100% 100%,rgba(60,60,255,.3),transparent 60%),var(--bg)}
  a{color:inherit;text-decoration:none}
  .wrap{width:min(100%,1180px);margin:0 auto;padding:32px 20px 56px}
  h1{margin:0 0 6px;font-size:clamp(28px,4vw,40px);font-weight:800;letter-spacing:-.03em}
  h1 span{background:linear-gradient(90deg,#9b4dff,#6a4bff);-webkit-background-clip:text;background-clip:text;color:transparent}
  .back{color:#8f7bff;font-weight:600}
  .card{border:1px solid var(--line);border-radius:22px;background:linear-gradient(160deg,rgba(18,22,46,.92),rgba(10,13,30,.94));padding:22px 26px;box-shadow:0 20px 60px rgba(0,0,0,.35);margin-top:20px}
  form.filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
  .f{display:flex;flex-direction:column;gap:5px;font-size:12px;color:var(--mut);font-weight:600}
  .f input,.f select{padding:10px 12px;border:1px solid rgba(139,111,255,.28);border-radius:10px;background:rgba(20,26,56,.55);color:#fff;font:inherit;font-size:14px;outline:none;min-width:140px}
  .f input:focus,.f select:focus{border-color:#8b6fff}
  .f select option{background:#0e1224}
  .btn{padding:10px 18px;border:0;border-radius:10px;background:linear-gradient(90deg,#7b2ff7,#4a4bff);color:#fff;font:inherit;font-weight:700;font-size:14px;cursor:pointer}
  .btn.ghost{background:rgba(139,111,255,.14);color:#c9bbff}
  .meta{display:flex;justify-content:space-between;align-items:center;margin-top:18px;color:var(--mut);font-size:14px}
  .tablewrap{overflow-x:auto;margin-top:10px}
  table{width:100%;border-collapse:collapse;font-size:14px}
  th{text-align:left;color:var(--mut);font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.05em;padding:10px 12px;border-bottom:1px solid rgba(139,111,255,.25);white-space:nowrap}
  td{padding:11px 12px;border-bottom:1px solid rgba(139,111,255,.12);vertical-align:top}
  td.when{white-space:nowrap;color:#b9bde0}
  td.who{font-weight:700;white-space:nowrap}
  td.note{max-width:280px;color:#d7daf5;word-break:break-word}
  td.ua{max-width:220px;color:var(--mut);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .mut{color:var(--mut)}
  .pill{display:inline-flex;align-items:center;gap:8px;padding:4px 12px 4px 9px;border-radius:999px;font-size:13px;font-weight:600}
  .pill i{width:10px;height:10px;border-radius:50%;background:currentColor}
  .pill-free{color:var(--free);background:rgba(34,224,138,.13)}
  .pill-busy{color:var(--busy);background:rgba(255,176,32,.14)}
  .pill-mod{color:var(--mod);background:rgba(30,155,255,.15)}
  .pill-away{color:#c27bff;background:rgba(180,92,255,.16)}
  .pill-break{color:var(--break);background:rgba(201,187,255,.14)}
  .pill-offline{color:#9aa0c3;background:rgba(154,160,195,.14)}
  .flash{margin-top:16px;padding:12px 14px;border:1px solid rgba(139,111,255,.35);border-radius:12px;background:rgba(139,111,255,.12);color:#d7daf5;font-size:14px}
  .warnbox{margin:0 0 12px;padding:12px 14px;border:1px solid rgba(255,120,110,.4);border-radius:12px;background:rgba(231,76,60,.12);color:#ffb0a8;font-size:14px;font-weight:600}
  form.archive{display:flex;flex-wrap:wrap;gap:10px;align-items:center;color:#b9bde0;font-size:14px}
  form.archive select{padding:9px 12px;border:1px solid rgba(139,111,255,.28);border-radius:10px;background:rgba(20,26,56,.55);color:#fff;font:inherit;font-size:14px}
  form.archive select option{background:#0e1224}
  .att{font-size:12px;font-weight:700;white-space:nowrap}
  .att.ok{color:var(--free)}
  .att.warn{color:var(--busy)}
  .att.bad{color:#ff8a80}
  .badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:rgba(255,176,32,.16);color:#ffc65c;font-size:11px;font-weight:700;white-space:nowrap}
  .f.check{flex-direction:row;align-items:center;gap:8px;padding-bottom:10px}
  .f.check input{min-width:0;width:auto}
  h2.sec{margin:0 0 4px;font-size:20px}
  .sub{margin:0 0 12px;color:var(--mut);font-size:13px}
  td.num,th.num{text-align:right;white-space:nowrap}
  .bar{display:block;height:6px;margin-top:6px;border-radius:999px;background:rgba(139,111,255,.15);overflow:hidden}
  .bar b{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#7b2ff7,#4a4bff)}
  .empty{padding:36px 10px;text-align:center;color:var(--mut)}
  .pager{display:flex;gap:8px;justify-content:center;align-items:center;margin-top:18px;font-size:14px}
  .pager a,.pager span{padding:8px 14px;border-radius:10px;border:1px solid var(--line)}
  .pager a{color:#c9bbff;background:rgba(139,111,255,.1)}
  .pager span.off{color:#5d6288}
</style>
</head>
<body>
<main class="wrap">
  <a class="back" href="<?= $h($checkinUrl) ?>#team">&larr; Back to check-in</a>
  <a class="back" style="margin-left:18px" href="failed-admin-logins.php">Failed admin sign-ins &rarr;</a>
  <h1 style="margin-top:12px">Check-In <span>History</span></h1>
  <?php if ($flash !== ''): ?><div class="flash" role="status"><?= $h($flash) ?></div><?php endif; ?>

  <section class="card">
    <h2 class="sec">Time online</h2>
    <p class="sub"><?= $h(date('M j', $repFrom)) ?> &ndash; <?= $h(date('M j, Y', $repTo)) ?> &middot; estimated from check-in and check-out events (a session without a check-out is capped at 8 hours). Use the date filters below to change the range. <a class="back" href="<?= $h($qs(['export' => 'hours'])) ?>">Download CSV</a></p>
    <div class="tablewrap">
    <?php if (!$repTotals): ?>
      <div class="empty">No online time recorded in this range.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Admin</th>
          <?php foreach ($repDays as $d): ?><th class="num"><?= $h(date('D j', strtotime($d))) ?></th><?php endforeach; ?>
          <th class="num">Total</th></tr></thead>
        <tbody>
        <?php foreach ($repTotals as $who => $sec): ?>
          <tr>
            <td class="who"><?= $h($who) ?></td>
            <?php foreach ($repDays as $d): ?><td class="num mut"><?= $h(vs_checkin_fmt_duration($hoursMap[$who][$d] ?? 0)) ?></td><?php endforeach; ?>
            <td class="num"><strong><?= $h(vs_checkin_fmt_duration($sec)) ?></strong>
              <span class="bar"><b style="width:<?= $repMax ? max(2, (int)round($sec / $repMax * 100)) : 0 ?>%"></b></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <h2 class="sec">Attendance</h2>
    <p class="sub">Compared with each admin's working hours (set with the Hours button on the check-in page). Times use the server time zone.</p>
    <div class="tablewrap">
    <?php if (!$whAdmins): ?>
      <div class="empty">No working hours have been set yet.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>Admin</th><th>Hours</th>
          <?php foreach ($attDays as $d): ?><th class="num"><?= $h(date('D j', strtotime($d))) ?></th><?php endforeach; ?>
          <th class="num">Late / absent</th></tr></thead>
        <tbody>
        <?php foreach ($whAdmins as $key => $row):
          $lateDays = 0; $absentDays = 0; $cells = [];
          foreach ($attDays as $d) {
              $st = vs_wh_day_status($row, $attSessions[$key] ?? [], $d, $attNow);
              if ($st['state'] === 'absent') $absentDays++;
              if ($st['state'] === 'flag' && $st['late']) $lateDays++;
              $cells[] = $attCell($st);
          } ?>
          <tr>
            <td class="who"><?= $h($row['name'] ?? $key) ?></td>
            <td class="mut" style="white-space:nowrap"><?= $h($row['start'] . '–' . $row['end']) ?></td>
            <?php foreach ($cells as $c): ?><td class="num"><?= $c ?></td><?php endforeach; ?>
            <td class="num"><?= (int)$lateDays ?> / <?= (int)$absentDays ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <form class="filters" method="get">
      <label class="f">Admin
        <select name="admin"><option value="">All admins</option>
          <?php foreach ($admins as $name => $_): ?><option value="<?= $h($name) ?>"<?= $name === $fAdmin ? ' selected' : '' ?>><?= $h($name) ?></option><?php endforeach; ?>
        </select></label>
      <label class="f">Event
        <select name="event"><option value="">All events</option>
          <?php foreach ($events as $name => $_): ?><option value="<?= $h($name) ?>"<?= $name === $fEvent ? ' selected' : '' ?>><?= $h($name) ?></option><?php endforeach; ?>
        </select></label>
      <label class="f">From <input type="date" name="from" value="<?= $h($fFrom) ?>"></label>
      <label class="f">To <input type="date" name="to" value="<?= $h($fTo) ?>"></label>
      <label class="f">Search <input type="text" name="q" value="<?= $h($fQ) ?>" placeholder="name, note, status"></label>
      <label class="f check"><input type="checkbox" name="flag" value="1"<?= $fFlag ? ' checked' : '' ?>> New devices only</label>
      <button class="btn" type="submit">Filter</button>
      <a class="btn ghost" href="checkin-history.php">Reset</a>
    </form>

    <div class="meta">
      <span><?= $total ?> event<?= $total === 1 ? '' : 's' ?> &middot; page <?= $page ?> of <?= $pages ?></span>
      <a class="btn ghost" href="<?= $h($qs(['export' => 'csv'])) ?>">Download CSV</a>
    </div>

    <div class="tablewrap">
    <?php if (!$slice): ?>
      <div class="empty">No events match these filters.</div>
    <?php else: ?>
      <table>
        <thead><tr><th>When</th><th>Admin</th><th>Event</th><th>Status</th><th>Note</th><th>IP</th><th>Browser</th></tr></thead>
        <tbody>
        <?php foreach ($slice as $e): ?>
          <tr>
            <td class="when"><?= $e['_ts'] ? $h(date('M j, Y • H:i', $e['_ts'])) : '—' ?></td>
            <td class="who"><?= $h($e['admin'] ?? '') ?></td>
            <td><?= $h($e['event'] ?? '') ?><?php foreach (($e['_flags'] ?? []) as $flag): ?><span class="badge">&#9888; <?= $h($flag) ?></span><?php endforeach; ?></td>
            <td><?= $pill((string)($e['status'] ?? '')) ?></td>
            <td class="note"><?= ($e['note'] ?? '') !== '' ? $h($e['note']) : '<span class="mut">—</span>' ?></td>
            <td class="mut"><?= $h($e['ip'] ?? '') ?></td>
            <td class="ua" title="<?= $h($e['ua'] ?? '') ?>"><?= $h($e['ua'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    </div>

    <?php if ($pages > 1): ?>
    <nav class="pager">
      <?php if ($page > 1): ?><a href="<?= $h($qs(['page' => $page - 1])) ?>">&larr; Prev</a><?php else: ?><span class="off">&larr; Prev</span><?php endif; ?>
      <span><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= $h($qs(['page' => $page + 1])) ?>">Next &rarr;</a><?php else: ?><span class="off">Next &rarr;</span><?php endif; ?>
    </nav>
    <?php endif; ?>
  </section>
  <section class="card">
    <h2 class="sec">Tidy history</h2>
    <p class="sub">Moves old events into a separate archive file (and keeps a .bak copy of the history) so this page stays fast. Nothing is deleted.</p>
    <form method="post" class="archive" onsubmit="return confirm('Archive the old events now?');">
      <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
      <input type="hidden" name="action" value="archive">
      <span>Archive events older than</span>
      <select name="days">
        <option value="30">30 days</option>
        <option value="60">60 days</option>
        <option value="90" selected>90 days</option>
        <option value="180">180 days</option>
      </select>
      <button class="btn" type="submit">Archive</button>
    </form>
  </section>
</main>
</body>
</html>