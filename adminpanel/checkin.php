<?php
require_once __DIR__ . '/checkin-lib.php';

$admin = vs_checkin_admin_name();
if (!$admin) { http_response_code(403); exit('Please log in first.'); }

if (empty($_SESSION['vs_csrf'])) $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
$isOwner = !empty($_SESSION['vs_is_owner']);     // set by header.php

function safe_next($n) {
    $n = (string)$n;
    return ($n !== '' && $n[0] === '/' && strpos($n, '//') !== 0 && strpos($n, '\\') === false) ? $n : '';
}
function csv_safe($v) {                           // stop spreadsheet formula injection
    $v = (string)$v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

// ---- Owner: export history as CSV ----
if ($isOwner && ($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="checkin-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['When', 'Admin', 'Event', 'Status', 'Note', 'IP', 'Browser']);
    foreach (array_reverse(vs_checkin_json_read(VS_CHECKIN_HISTORY)) as $e) {
        fputcsv($out, array_map('csv_safe', [$e['at'] ?? '', $e['admin'] ?? '', $e['event'] ?? '', $e['status'] ?? '',
                                              $e['note'] ?? '', $e['ip'] ?? '', $e['ua'] ?? '']));
    }
    exit;
}

$next  = safe_next($_GET['next'] ?? $_POST['next'] ?? '');
$error = '';
$info  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'checkin';
    if (!hash_equals($_SESSION['vs_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired, try again.';
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
        $status = $_POST['status'] ?? '';
        $note   = trim(mb_substr((string)($_POST['note'] ?? ''), 0, 140));
        if (!in_array($status, VS_CHECKIN_STATUSES, true)) {
            $error = 'Pick a status to check in.';
        } elseif (in_array($status, VS_CHECKIN_NOTE_REQUIRED, true) && $note === '') {
            $error = 'Please add a note for "' . $status . '" (why, or when you will be back).';
        } elseif (!vs_checkin_save($admin, $status, $note)) {
            $error = 'Could not save your check-in.';
        } else {
            header('Location: ' . ($next !== '' ? $next : dirname(vs_checkin_url()) . '/'));
            exit;
        }
    }
}

$mine    = vs_checkin_active($admin) ? $_SESSION['vs_checkin'] : null;   // changeable, never removable
$all     = vs_checkin_all();
$history = $isOwner ? array_reverse(array_slice(vs_checkin_json_read(VS_CHECKIN_HISTORY), -50)) : [];

// Daily summary (owner): last 7 days, active time per admin
$days = []; for ($i = 0; $i < 7; $i++) $days[] = date('Y-m-d', strtotime("-$i day"));
$daily = $isOwner ? vs_checkin_json_read(VS_CHECKIN_DAILY) : [];
$dailyAdmins = [];
foreach ($days as $d) foreach (($daily[$d] ?? []) as $n => $s) $dailyAdmins[$n] = true;
$fmt = function ($s) { $s = (int)$s; if ($s < 60) return $s > 0 ? '<1m' : '–'; return floor($s / 3600) . 'h ' . floor(($s % 3600) / 60) . 'm'; };

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$home = $next !== '' ? $next : dirname(vs_checkin_url()) . '/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Check-in</title>
<style>
  body{margin:0;background:#0f0f0f;color:#f1f1f1;font-family:Arial,sans-serif;display:flex;justify-content:center;padding:40px 16px}
  .card{background:#1a1a1a;border:1px solid #303030;border-radius:14px;padding:24px;width:100%;max-width:640px}
  h1{margin:0 0 6px;font-size:22px} h2{font-size:15px;margin:28px 0 4px;color:#ddd} p{color:#aaa;margin:0 0 18px;font-size:14px}
  label{display:block;margin:14px 0 6px;font-size:13px;color:#ccc}
  select,input[type=text],input[type=number]{width:100%;box-sizing:border-box;padding:11px;border-radius:8px;border:1px solid #3a3a3a;background:#121212;color:#fff;font-size:15px}
  button{margin-top:18px;width:100%;padding:12px;border:0;border-radius:999px;background:#3ea6ff;color:#000;font-weight:bold;font-size:15px;cursor:pointer}
  button.small{margin:0;width:auto;padding:5px 12px;font-size:12px;background:#2a2a2a;color:#ffb3b3;border:1px solid #444}
  .err{background:#3b1414;color:#ff9a9a;padding:10px;border-radius:8px;margin-bottom:12px;font-size:14px}
  .now{background:#12301d;color:#8be5a8;padding:10px;border-radius:8px;margin-bottom:6px;font-size:14px}
  .scroll{overflow-x:auto}
  table{width:100%;border-collapse:collapse;margin-top:8px;font-size:13px}
  td,th{padding:8px 4px;border-bottom:1px solid #2a2a2a;text-align:left;vertical-align:top} th{color:#888;font-weight:normal;white-space:nowrap}
  .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px}
  .back{display:inline-block;margin-top:16px;color:#3ea6ff;font-size:14px;text-decoration:none}
  .meta{display:block;color:#777;font-size:11px;margin-top:2px;word-break:break-all}
  form.inline{display:inline;margin:0}
  .row{display:flex;gap:10px;align-items:flex-end} .row>div{flex:1} .row button{margin:0;width:auto;padding:11px 20px}
</style>
</head>
<body>
<div class="card">
  <h1><?= $mine ? 'Your check-in' : 'Check in to continue' ?></h1>
  <p>Hi <?= $h($admin) ?>.
     <?= $mine ? 'You are checked in. You can change it, but it cannot be removed.' : 'You must check in before using the admin panel.' ?></p>

  <?php if ($error): ?><div class="err"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($mine): ?>
    <div class="now">Now: <b><?= $h($mine['status']) ?></b><?= $mine['note'] !== '' ? ' — ' . $h($mine['note']) : '' ?> (since <?= $h($mine['at']) ?>)</div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
    <input type="hidden" name="next" value="<?= $h($next) ?>">
    <label for="status">Status</label>
    <select id="status" name="status" required>
      <option value="">Choose…</option>
      <?php foreach (VS_CHECKIN_STATUSES as $s): ?>
        <option value="<?= $h($s) ?>" <?= ($mine && $mine['status'] === $s) ? 'selected' : '' ?>><?= $h($s) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="note" id="noteLabel">Note (optional)</label>
    <input type="text" id="note" name="note" maxlength="140" value="<?= $h($mine['note'] ?? '') ?>" placeholder="e.g. reviewing reports">
    <button type="submit"><?= $mine ? 'Change check-in' : 'Check in' ?></button>
  </form>
  <?php if ($mine): ?><a class="back" href="<?= $h($home) ?>">← Back to admin panel</a><?php endif; ?>

  <?php if ($all): ?>
  <h2 id="team">Team</h2>
  <div class="scroll">
  <table>
    <tr><th>Admin</th><th>Status</th><th>Since</th><?php if ($isOwner): ?><th></th><?php endif; ?></tr>
    <?php foreach ($all as $n => $r): $eff = vs_checkin_effective($r); ?>
      <tr>
        <td><?= $h($n) ?></td>
        <td><span class="dot" style="background:<?= $h(vs_checkin_color($eff)) ?>"></span><?= $h($eff) ?><?= ($eff !== 'Offline' && !empty($r['note'])) ? ' — ' . $h($r['note']) : '' ?></td>
        <td><?= $h($r['at'] ?? '') ?></td>
        <?php if ($isOwner): ?>
        <td>
          <?php if ($eff !== 'Offline' && $n !== $admin): ?>
          <form method="post" class="inline" onsubmit="return confirm('Make <?= $h(addslashes($n)) ?> check in again?');">
            <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
            <input type="hidden" name="action" value="force">
            <input type="hidden" name="target" value="<?= $h($n) ?>">
            <button type="submit" class="small">Force re-check-in</button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; ?>

  <?php if ($isOwner): ?>
  <h2>Settings (owner only)</h2>
  <form method="post" class="row">
    <input type="hidden" name="csrf" value="<?= $h($_SESSION['vs_csrf']) ?>">
    <input type="hidden" name="action" value="settings">
    <div>
      <label for="idle" style="margin-top:6px">Offline after this many minutes without activity (5–240)</label>
      <input type="number" id="idle" name="idle_minutes" min="5" max="240" value="<?= (int)(vs_checkin_idle() / 60) ?>">
    </div>
    <button type="submit">Save</button>
  </form>

  <h2>Active time, last 7 days (owner only)</h2>
  <?php if (!$dailyAdmins): ?><p>No activity recorded yet.</p><?php else: ?>
  <div class="scroll">
  <table>
    <tr><th>Admin</th><?php foreach ($days as $d): ?><th><?= $h(date('D j', strtotime($d))) ?></th><?php endforeach; ?></tr>
    <?php foreach (array_keys($dailyAdmins) as $n): ?>
      <tr><td><?= $h($n) ?></td><?php foreach ($days as $d): ?><td><?= $h($fmt($daily[$d][$n] ?? 0)) ?></td><?php endforeach; ?></tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p style="margin-top:8px;font-size:12px">Approximate: counted while pages are being used, up to 5 minutes per gap.</p>
  <?php endif; ?>

  <h2>History (owner only, last 50) &nbsp;<a class="back" style="margin:0;font-size:13px" href="?export=csv">Download CSV</a></h2>
  <?php if (!$history): ?><p>No history yet.</p><?php else: ?>
  <div class="scroll">
  <table>
    <tr><th>When</th><th>Admin</th><th>Event</th></tr>
    <?php foreach ($history as $e): ?>
      <tr>
        <td><?= $h($e['at'] ?? '') ?></td>
        <td><?= $h($e['admin'] ?? '') ?></td>
        <td><?= $h($e['event'] ?? '') ?> · <?= $h($e['status'] ?? '') ?><?= !empty($e['note']) ? ' — ' . $h($e['note']) : '' ?>
          <?php if (!empty($e['ip']) || !empty($e['ua'])): ?><span class="meta"><?= $h($e['ip'] ?? '') ?> · <?= $h($e['ua'] ?? '') ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <?php endif; endif; ?>
</div>
<script>
  // "On break" / "Away" need a note
  (function () {
    var need = <?= json_encode(VS_CHECKIN_NOTE_REQUIRED) ?>;
    var sel = document.getElementById('status'), note = document.getElementById('note'), lab = document.getElementById('noteLabel');
    function sync() {
      var req = need.indexOf(sel.value) !== -1;
      note.required = req;
      lab.textContent = req ? 'Note (required: why, or when you will be back)' : 'Note (optional)';
    }
    sel.addEventListener('change', sync); sync();
  })();
</script>
</body>
</html>