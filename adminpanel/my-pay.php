<?php
/*
 * VibeStream admin panel - My pay (every admin sees ONLY their own pay).
 * Shows this month's hours worked, task bonuses, and how much will be paid.
 * Put this file in the adminpanel folder (needs admin-pay-core.php next to it).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    header('Location: ../login.php');
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/owner-notice-store.php';     // vs_notice_me()
require_once __DIR__ . '/admin-pay-core.php';         // same calculation as the owner's Admin Payroll page

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('No database connection found.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// The owner is not paid, so he gets no pay cards here, but he can open this page to see and mark the team's payments.
$vsIsOwnerView = function_exists('vs_is_owner') && vs_is_owner($pdo);
vs_pay_ensure($pdo);

$PM = [];
$me = vs_notice_me($pdo);
$PM['name'] = (string)$me['name'];
$PM['key']  = mb_strtolower(trim($PM['name']));

$PM['month'] = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $PM['month']) || $PM['month'] > date('Y-m')) {
    $PM['month'] = date('Y-m');
}
$PM['monthLabel'] = date('F Y', strtotime($PM['month'] . '-01'));
$PM['prevMonth']  = date('Y-m', strtotime($PM['month'] . '-01 -1 month'));
$PM['nextMonth']  = date('Y-m', strtotime($PM['month'] . '-01 +1 month'));

/* settings (rate, shift) of this admin only */
$PM['settings'] = [];
$q = $pdo->prepare('SELECT * FROM admin_pay_settings WHERE username = ? LIMIT 1');
$q->execute([$PM['key']]);
if ($row = $q->fetch(PDO::FETCH_ASSOC)) {
    $PM['settings'][$PM['key']] = $row;
}
$PM['self'] = [['username' => $PM['name']]];

$PM['cur'] = $vsIsOwnerView ? null : (vs_pay_build($pdo, $PM['month'], $PM['self'], $PM['settings'])['rows'][0] ?? null);

/* last 6 months, own numbers only */
$PM['history'] = [];
for ($i = 0; $i < ($vsIsOwnerView ? 0 : 6); $i++) {
    $m = date('Y-m', strtotime(date('Y-m-01') . ' -' . $i . ' months'));
    $r = vs_pay_build($pdo, $m, $PM['self'], $PM['settings'])['rows'][0] ?? null;
    if ($r) {
        $PM['history'][] = ['month' => $m, 'row' => $r];
    }
}

/* hours per day (this admin only) */
$PM['days'] = [];
foreach (vs_checkin_json_read(VS_CHECKIN_DAILY) as $day => $names) {
    if (strpos((string)$day, $PM['month']) !== 0 || !is_array($names)) {
        continue;
    }
    foreach ($names as $nm => $sc) {
        if (mb_strtolower(trim((string)$nm)) === $PM['key'] && (int)$sc > 0) {
            $PM['days'][(string)$day] = ((int)$sc) / 3600;
        }
    }
}
krsort($PM['days']);

/* bonuses and hour adjustments (this admin only) */
$q = $pdo->prepare('SELECT amount, reason, created_at FROM admin_pay_bonus WHERE month = ? AND username = ? ORDER BY created_at DESC');
$q->execute([$PM['month'], $PM['key']]);
$PM['bonuses'] = $q->fetchAll(PDO::FETCH_ASSOC);
if (!$PM['bonuses']) {    // task rewards may have been saved with the exact-case username
    $q = $pdo->prepare('SELECT amount, reason, created_at FROM admin_pay_bonus WHERE month = ? AND LOWER(TRIM(username)) = ? ORDER BY created_at DESC');
    $q->execute([$PM['month'], $PM['key']]);
    $PM['bonuses'] = $q->fetchAll(PDO::FETCH_ASSOC);
}
$q = $pdo->prepare('SELECT hours, reason, created_at FROM admin_pay_adjust WHERE month = ? AND username = ? ORDER BY id DESC');
$q->execute([$PM['month'], $PM['key']]);
$PM['adjusts'] = $q->fetchAll(PDO::FETCH_ASSOC);

/* ------------------------------------------------------------------
   Who may mark payments as paid: admins whose role label (set by the owner under Rates & Work Time)
   contains "CEO" or "Manager". They can never mark their own pay.
------------------------------------------------------------------- */
$PM['title']  = (string)($PM['settings'][$PM['key']]['title'] ?? '');
$PM['canPay'] = $vsIsOwnerView || (bool)preg_match('/\b(ceo|manager)\b/i', $PM['title']);
if (empty($_SESSION['my_pay_csrf'])) {
    $_SESSION['my_pay_csrf'] = bin2hex(random_bytes(16));
}
$PM['csrf'] = (string)$_SESSION['my_pay_csrf'];

/** Admin accounts that can be paid (not the owner, not the viewer). */
$vsMpTeam = static function (PDO $pdo, string $selfKey): array {
    $out = [];
    try {
        $uc = vs_cols($pdo, 'users') ?: [];
        $un = vs_pick($uc, ['username', 'user_name', 'login', 'uname']);
        $rc = vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
        $ec = vs_pick($uc, ['email', 'email_address', 'mail']);
        if ($un && $rc) {
            $sel = "`$un` AS n" . ($ec ? ", `$ec` AS e" : ", '' AS e");
            foreach ($pdo->query("SELECT $sel FROM `users` WHERE LOWER(`$rc`) = 'admin' ORDER BY `$un`")->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $n = trim((string)$u['n']);
                $k = mb_strtolower($n);
                if ($n === '' || $k === $selfKey) {
                    continue;
                }
                if (function_exists('vs_is_site_owner_email') && trim((string)$u['e']) !== '' && vs_is_site_owner_email(strtolower(trim((string)$u['e'])))) {
                    continue;
                }
                $out[$k] = ['username' => $n];
            }
        }
    } catch (Throwable $ex) {
        error_log('my-pay team list failed: ' . $ex->getMessage());
    }
    return array_values($out);
};
$vsMpSettings = static function (PDO $pdo): array {
    $o = [];
    foreach ($pdo->query('SELECT * FROM admin_pay_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $o[mb_strtolower((string)$r['username'])] = $r;
    }
    return $o;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['ok' => false, 'msg' => 'Could not save the payment.'];
    $pMon = (string)($_POST['month'] ?? '');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $pMon) || $pMon > date('Y-m')) {
        $pMon = date('Y-m');
    }
    $pKey = mb_strtolower(trim((string)($_POST['admin'] ?? '')));
    if (!hash_equals($PM['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash['msg'] = 'The page expired. Refresh and try again.';
    } elseif (!$PM['canPay'] || ($_POST['do'] ?? '') !== 'markpaid') {
        $flash['msg'] = 'You are not allowed to mark payments as paid.';
    } elseif ($pKey === '' || $pKey === $PM['key']) {
        $flash['msg'] = 'You cannot mark your own pay as paid.';
    } else {
        try {
            $team = $vsMpTeam($pdo, $PM['key']);
            $match = array_values(array_filter($team, static fn($t) => mb_strtolower($t['username']) === $pKey));
            if (!$match) {
                $flash['msg'] = 'That admin was not found.';
            } else {
                vs_activity_ensure($pdo);   // creates a table if missing: must happen before the transaction starts
                $pdo->beginTransaction();
                $pdo->prepare('SELECT status FROM admin_pay_months WHERE month = ? AND username = ? FOR UPDATE')->execute([$pMon, $pKey]);
                $row = vs_pay_build($pdo, $pMon, $match, $vsMpSettings($pdo))['rows'][0] ?? null;
                if (!$row) {
                    $pdo->rollBack();
                    $flash['msg'] = "Could not work out that admin's pay.";
                } elseif ($row['status'] === 'paid') {
                    $pdo->rollBack();
                    $flash['msg'] = $row['name'] . ' is already marked as paid for this month.';
                } elseif ($row['status'] !== 'pending' || $row['amount'] <= 0) {
                    $pdo->rollBack();
                    $flash['msg'] = $row['name'] . ' has nothing payable for this month' . ($row['why'] ? ' (' . implode('; ', $row['why']) . ')' : '') . '.';
                } else {
                    // The amount saved is exactly the amount worked out now (hours x rate + task bonus).
                    $pdo->prepare("INSERT INTO admin_pay_months (month, username, status, paid_amount, updated_at, paid_by, paid_at)
                                   VALUES (?, ?, 'paid', ?, NOW(), ?, NOW())
                                   ON DUPLICATE KEY UPDATE status = 'paid', paid_amount = VALUES(paid_amount), updated_at = NOW(),
                                                           paid_by = VALUES(paid_by), paid_at = VALUES(paid_at)")
                        ->execute([$pMon, $pKey, $row['amount'], mb_substr($PM['name'], 0, 100)]);
                    $pdo->commit();
                    vs_pay_log($pdo, $PM['name'] . ' marked ' . $row['name'] . ' as paid: ' . vs_pay_money($row['amount']) . ' for ' . $pMon);
                    $flash = ['ok' => true, 'msg' => $row['name'] . ' marked as paid: ' . vs_pay_money($row['amount']) . '.'];
                }
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('my-pay markpaid failed: ' . $ex->getMessage());
        }
    }
    $_SESSION['my_pay_flash'] = $flash;
    header('Location: my-pay.php?month=' . urlencode($pMon));
    exit;
}
$PM['flash'] = $_SESSION['my_pay_flash'] ?? null;
unset($_SESSION['my_pay_flash']);

/* team payments (only for CEO / Manager) */
$PM['team'] = [];
if ($PM['canPay']) {
    $PM['team'] = vs_pay_build($pdo, $PM['month'], $vsMpTeam($pdo, $PM['key']), $vsMpSettings($pdo))['rows'];
}

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}

/* ---- everything below only reads $PM ---- */
$c = $PM['cur'];
$stMeta = ['paid' => ['Paid', 'text-bg-success'], 'pending' => ['Will be paid', 'text-bg-warning'], 'nopay' => ['Not paid', 'text-bg-danger']];
$hoursPay = $c ? round($c['hours'] * $c['rate'], 2) : 0.0;
$minH = (float)VS_PAY_MIN_HOURS;
$minA = (int)VS_PAY_MIN_ACTIONS;

/* ---- numbers for the new layout ---- */
$prevRow = null;
if ($c) {
    $prevRow = vs_pay_build($pdo, $PM['prevMonth'], $PM['self'], $PM['settings'])['rows'][0] ?? null;
}
$mpTrend = static function (float $now, ?float $before): string {
    if ($before === null || $before <= 0) {
        return $now > 0 ? '<span class="mp-trend up">new</span>' : '';
    }
    $p = (int)round(($now - $before) / $before * 100);
    if ($p === 0) {
        return '<span class="mp-trend">&rarr; 0%</span>';
    }
    return '<span class="mp-trend ' . ($p > 0 ? 'up' : 'down') . '">' . ($p > 0 ? '&uarr; ' : '&darr; ') . abs($p) . '%</span>';
};
$estimated = 0.0;
$negAdj = 0.0;
$basePay = 0.0;
$deduct = 0.0;
$grossT = 0.0;
if ($c) {
    $estimated = $c['status'] === 'paid' ? (float)$c['amount'] : round($hoursPay + $c['bonus'], 2);
    $negAdj = max(0.0, -(float)$c['adj']);
    $basePay = round(($c['hours'] + $negAdj) * $c['rate'], 2);   // hours before the owner took time off
    $deduct = round($negAdj * $c['rate'], 2);
    $grossT = $basePay + (float)$c['bonus'] + $deduct * 0;       // percentages are shares of base + bonus + deductions
}
$pctOf = static function (float $part, float $sum): string {
    return $sum > 0 ? number_format($part / $sum * 100, 1) . '%' : '0%';
};
$donutSum = $basePay + (float)($c['bonus'] ?? 0) + $deduct;

/* days of the viewed month for the bar chart */
$daysInMonth = (int)date('t', strtotime($PM['month'] . '-01'));
$dayHours = [];
$maxH = 0.0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    $h = (float)($PM['days'][$PM['month'] . '-' . sprintf('%02d', $d)] ?? 0);
    $dayHours[$d] = $h;
    $maxH = max($maxH, $h);
}
$yMax = max(2, (int)(ceil($maxH / 2) * 2));

$mpM = static function (float $v): string {
    return VS_PAY_CURRENCY . ' ' . (abs($v - round($v)) < 0.005 ? number_format($v, 0) : number_format($v, 2));
};
/* bonuses + hour adjustments in one list, newest first */
$mpFeed = [];
foreach ($PM['bonuses'] as $b) {
    $mpFeed[] = ['t' => strtotime((string)$b['created_at']) ?: 0, 'kind' => 'bonus', 'title' => 'Task Bonus', 'sub' => (string)$b['reason'], 'val' => '+ ' . $mpM((float)$b['amount'])];
}
foreach ($PM['adjusts'] as $ad) {
    $hv = (float)$ad['hours'];
    $mpFeed[] = ['t' => strtotime((string)$ad['created_at']) ?: 0, 'kind' => $hv < 0 ? 'minus' : 'plus', 'title' => 'Hours Adjustment', 'sub' => (string)$ad['reason'],
                 'val' => ($hv > 0 ? '+' : '') . rtrim(rtrim(number_format($hv, 2, '.', ''), '0'), '.') . ' hrs'];
}
usort($mpFeed, static fn($a, $b) => $b['t'] <=> $a['t']);
$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$shiftText = '';
if ($c && $c['wstart'] !== '' && $c['wend'] !== '') {
    $shiftText = $c['wstart'] . ' – ' . $c['wend'];
    $dl = array_filter(array_map('intval', explode(',', $c['wdays'])));
    if ($dl) {
        $shiftText .= ' · ' . implode(', ', array_map(static fn($d) => $dayNames[$d] ?? '', $dl));
    }
}
$stBadge = ['paid' => ['Paid', 'ok'], 'pending' => ['Pending', 'warn'], 'nopay' => ['Not paid', 'bad']];
?>
<link rel="stylesheet" href="./assets/css/neon-theme.css?v=1">
<style>
  .mp-page{--mp-card:rgba(14,22,62,.62);--mp-line:rgba(110,130,255,.28);--mp-soft:rgba(130,150,255,.10);--mp-text:#eef2ff;--mp-muted:#93a4d6;
           --mp-blue:#3b82f6;--mp-green:#10b981;--mp-violet:#8b5cf6;--mp-pink:#d946ef;--mp-amber:#fbbf24;--mp-red:#fb7185;color:var(--mp-text)}
  html[data-theme="light"] .mp-page,html[data-bs-theme="light"] .mp-page{--mp-card:#ffffff;--mp-line:#d6dcf2;--mp-soft:#f1f4fc;--mp-text:#1b2447;--mp-muted:#5f6c93}
  .mp-page{font-variant-numeric:tabular-nums;-webkit-font-smoothing:antialiased}
  .mp-page :focus-visible{outline:2px solid #818cf8;outline-offset:2px;border-radius:6px}
  .mp-who{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:14px 18px;margin-bottom:16px}
  .mp-who .av{width:52px;height:52px;border-radius:50%;display:grid;place-items:center;font-weight:800;color:#fff;font-size:1.05rem;flex:none}
  .mp-who .nm{font-weight:700;font-size:1.05rem;line-height:1.2}
  .mp-who .rl{color:var(--mp-muted);font-size:.85rem}
  .mp-who .chips{display:flex;gap:8px;flex-wrap:wrap;margin-left:auto}
  .mp-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid var(--mp-line);background:var(--mp-soft);font-size:.8rem;color:var(--mp-text);white-space:nowrap}
  .mp-actions{display:flex;gap:10px;flex-wrap:wrap}
  .mp-ghost{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;border-radius:10px;border:1px solid var(--mp-line);background:var(--mp-card);color:var(--mp-text);font-weight:600;font-size:.88rem;cursor:pointer}
  .mp-ghost:hover{background:var(--mp-soft)}
  .mp-slip{display:none}
  @media print{
    body *{visibility:hidden}
    #mpSlip,#mpSlip *{visibility:visible}
    #mpSlip{display:block!important;position:absolute;left:0;top:0;width:100%;padding:28px;background:#fff;color:#111;font-family:Arial,Helvetica,sans-serif}
    #mpSlip h1{font-size:22px;margin:0 0 2px} #mpSlip .sub{color:#555;font-size:12px;margin-bottom:18px}
    #mpSlip table{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:16px}
    #mpSlip th,#mpSlip td{padding:8px 6px;border-bottom:1px solid #ddd;text-align:left}
    #mpSlip td.r,#mpSlip th.r{text-align:right}
    #mpSlip tr.tot td{font-weight:700;border-top:2px solid #111;border-bottom:0;font-size:15px}
    #mpSlip .meta{display:flex;justify-content:space-between;font-size:12px;margin-bottom:14px;gap:20px}
    #mpSlip .foot{margin-top:26px;font-size:11px;color:#666}
  }
  .mp-head{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-bottom:18px}
  .mp-head .ic{width:54px;height:54px;border-radius:16px;display:grid;place-items:center;font-size:1.6rem;color:#fff;background:linear-gradient(135deg,#6d28d9,#d946ef);box-shadow:0 0 22px rgba(168,85,247,.55)}
  .mp-head h1{margin:0;font-size:1.7rem;font-weight:800;color:var(--mp-text)}
  .mp-head p{margin:2px 0 0;color:var(--mp-muted);font-size:.9rem}
  .mp-month{display:flex;align-items:center;border:1px solid var(--mp-line);border-radius:12px;overflow:hidden;background:var(--mp-card)}
  .mp-month a,.mp-month span.nav{width:42px;height:42px;display:grid;place-items:center;color:var(--mp-text);text-decoration:none}
  .mp-month a:hover{background:var(--mp-soft)}
  .mp-month span.nav.off{opacity:.3}
  .mp-month strong{padding:0 18px;border-left:1px solid var(--mp-line);border-right:1px solid var(--mp-line);height:42px;display:flex;align-items:center;gap:8px;font-weight:600}
  .mp-grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
  .mp-grid3{display:grid;grid-template-columns:1.25fr 1fr 1fr;gap:16px;margin-top:16px}
  .mp-grid3b{display:grid;grid-template-columns:1.1fr 1fr 1.25fr;gap:16px;margin-top:16px}
  @media (max-width:1199px){.mp-grid4{grid-template-columns:repeat(2,minmax(0,1fr))}.mp-grid3,.mp-grid3b{grid-template-columns:minmax(0,1fr)}}
  @media (max-width:575px){.mp-grid4{grid-template-columns:minmax(0,1fr)}}
  .mp-card{background:var(--mp-card);border:1px solid var(--mp-line);border-radius:16px;padding:18px 20px;min-width:0}
  .mp-card h2{font-size:1.05rem;font-weight:700;margin:0 0 14px;color:var(--mp-text);display:flex;align-items:center;gap:10px;justify-content:space-between}
  .mp-stat{display:flex;flex-direction:column;gap:6px;position:relative}
  .mp-stat .top{display:flex;align-items:center;gap:14px}
  .mp-stat .ico{width:54px;height:54px;border-radius:50%;display:grid;place-items:center;font-size:1.5rem;color:#fff;flex:none}
  .mp-stat .lbl{font-size:.95rem;color:var(--mp-text)}
  .mp-stat .val{font-size:2rem;font-weight:800;line-height:1.1;margin-top:4px}
  .mp-stat .val small{font-size:1.1rem;font-weight:700;opacity:.8}
  .mp-stat .foot{display:flex;justify-content:space-between;align-items:center;color:var(--mp-muted);font-size:.9rem}
  .mp-stat.s-blue{border-color:rgba(59,130,246,.7);box-shadow:0 0 22px rgba(59,130,246,.25)} .s-blue .ico{background:linear-gradient(135deg,#2563eb,#3b82f6)}
  .mp-stat.s-green{border-color:rgba(16,185,129,.7);box-shadow:0 0 22px rgba(16,185,129,.22)} .s-green .ico{background:linear-gradient(135deg,#059669,#10b981)}
  .mp-stat.s-violet{border-color:rgba(139,92,246,.75);box-shadow:0 0 22px rgba(139,92,246,.28)} .s-violet .ico{background:linear-gradient(135deg,#6d28d9,#8b5cf6)}
  .mp-stat.s-pink{border-color:rgba(217,70,239,.7);box-shadow:0 0 22px rgba(217,70,239,.25)} .s-pink .ico{background:linear-gradient(135deg,#a21caf,#d946ef)}
  .mp-trend{font-weight:700;font-size:.85rem;color:var(--mp-muted)} .mp-trend.up{color:var(--mp-green)} .mp-trend.down{color:var(--mp-red)}
  .mp-pill{display:inline-block;padding:4px 14px;border-radius:999px;font-size:.78rem;font-weight:700;border:1px solid currentColor}
  .mp-pill.ok{color:#34d399;background:rgba(16,185,129,.14)} .mp-pill.warn{color:#34d399;background:rgba(16,185,129,.14)} .mp-pill.bad{color:var(--mp-red);background:rgba(251,113,133,.14)}
  .mp-pill.warn{color:#34d399;background:rgba(16,185,129,.14)}
  .mp-break{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
  .mp-donut{position:relative;width:170px;height:170px;flex:none}
  .mp-donut svg{width:100%;height:100%;transform:rotate(-90deg)}
  .mp-donut .mid{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;font-weight:800;text-align:center}
  .mp-donut .mid small{font-weight:600;color:var(--mp-muted)}
  .mp-legend{flex:1;min-width:230px;display:flex;flex-direction:column}
  .mp-legend .row1{display:grid;grid-template-columns:1fr auto 54px;gap:10px;align-items:center;padding:11px 0;border-top:1px solid var(--mp-line)}
  .mp-legend .row1:first-child{border-top:0}
  .mp-legend .dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:8px}
  .mp-legend .p{text-align:right;color:var(--mp-muted)}
  .mp-status{display:flex;gap:16px;align-items:center;margin:8px 0 16px}
  .mp-status .ic{width:64px;height:64px;border-radius:18px;display:grid;place-items:center;font-size:1.8rem;color:#fff;background:linear-gradient(135deg,#1d4ed8,#4f46e5);flex:none;box-shadow:0 0 18px rgba(79,70,229,.5)}
  .mp-status.paid .ic{background:linear-gradient(135deg,#059669,#10b981)} .mp-status.nopay .ic{background:linear-gradient(135deg,#be123c,#fb7185)}
  .mp-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;padding:12px;border-radius:10px;border:0;color:#fff;font-weight:600;background:linear-gradient(90deg,#4f46e5,#6366f1);box-shadow:0 0 18px rgba(99,102,241,.5);cursor:pointer}
  .mp-btn:hover{filter:brightness(1.1)}
  .mp-info .row1{display:flex;justify-content:space-between;align-items:center;padding:13px 0;border-top:1px solid var(--mp-line)}
  .mp-info .row1:first-of-type{border-top:0}
  .mp-info .row1 span{color:var(--mp-muted);display:flex;align-items:center;gap:12px}
  .mp-info .row1 b{font-weight:700}
  .mp-chart{width:100%;height:230px}
  .mp-feed{display:flex;flex-direction:column}
  .mp-feed .it{display:flex;gap:14px;align-items:center;padding:14px 0;border-top:1px solid var(--mp-line)}
  .mp-feed .it:first-child{border-top:0}
  .mp-feed .ic{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;color:#fff;font-size:1.1rem;flex:none}
  .mp-feed .ic.bonus,.mp-feed .ic.plus{background:#15803d} .mp-feed .ic.minus{background:#e11d48}
  .mp-feed .mid{flex:1;min-width:0}
  .mp-feed .mid b{display:block} .mp-feed .mid small{color:var(--mp-muted);overflow-wrap:anywhere}
  .mp-feed .v{font-weight:700;white-space:nowrap} .mp-feed .v.pos{color:#34d399} .mp-feed .v.neg{color:var(--mp-red)}
  .mp-feed .d{color:var(--mp-muted);font-size:.8rem;white-space:nowrap}
  .mp-table{width:100%;border-collapse:collapse;font-size:.88rem}
  .mp-table th{padding:9px 8px;color:var(--mp-muted);font-weight:600;text-align:left;background:var(--mp-soft)}
  .mp-table td{padding:10px 8px;border-top:1px solid var(--mp-line)}
  .mp-table a{color:inherit;text-decoration:none} .mp-table a:hover{text-decoration:underline}
  .mp-table tr.cur td{background:var(--mp-soft)}
  .mp-bar{height:10px;border-radius:8px;background:rgba(127,127,160,.2);overflow:hidden}
  .mp-bar>span{display:block;height:100%;border-radius:8px;background:linear-gradient(90deg,#6c5ce7,#e84393)}
  .mp-empty{color:var(--mp-muted);font-size:.9rem;padding:14px 0}
  .mp-modal .modal-content{background:var(--mp-card,#101846);border:1px solid var(--mp-line,#2d3a8c);color:var(--mp-text,#eef2ff)}
  @media (prefers-reduced-motion:no-preference){.mp-stat{transition:transform .15s}.mp-stat:hover{transform:translateY(-2px)}}
  /* look closer to the design: tinted card backgrounds and a soft glow */
  .mp-card{box-shadow:0 8px 28px rgba(5,8,40,.45)}
  .mp-stat.s-blue{background:linear-gradient(135deg,rgba(37,99,235,.38),rgba(10,16,56,.9) 72%)}
  .mp-stat.s-green{background:linear-gradient(135deg,rgba(5,150,105,.36),rgba(10,16,56,.9) 72%)}
  .mp-stat.s-violet{background:linear-gradient(135deg,rgba(109,40,217,.4),rgba(10,16,56,.9) 72%)}
  .mp-stat.s-pink{background:linear-gradient(135deg,rgba(162,28,175,.4),rgba(10,16,56,.9) 72%)}
  html[data-theme="light"] .mp-stat.s-blue,html[data-theme="light"] .mp-stat.s-green,html[data-theme="light"] .mp-stat.s-violet,html[data-theme="light"] .mp-stat.s-pink{background:var(--mp-card)}
  .mp-head h1{font-size:1.9rem}
  .mp-stat .val{font-size:2.2rem}
</style>
      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 mp-page">

          <div class="mp-head">
            <span class="ic"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
            <div class="me-auto">
              <h1>My Pay</h1>
              <p>View your earnings, hours and payment details for this month.</p>
            </div>
            <div class="mp-actions">
            <?php if ($c): ?><button type="button" class="mp-ghost" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print payslip</button><?php endif; ?>
            <div class="mp-month">
              <a href="my-pay.php?month=<?php echo vs_pay_h($PM['prevMonth']); ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
              <strong><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo vs_pay_h($PM['monthLabel']); ?></strong>
              <?php if ($PM['month'] < date('Y-m')): ?>
                <a href="my-pay.php?month=<?php echo vs_pay_h($PM['nextMonth']); ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
              <?php else: ?>
                <span class="nav off"><i class="bi bi-chevron-right"></i></span>
              <?php endif; ?>
            </div>
            </div>
          </div>

          <?php if ($PM['flash']): ?>
            <div class="alert alert-<?php echo !empty($PM['flash']['ok']) ? 'success' : 'danger'; ?>" role="status"><?php echo vs_pay_h($PM['flash']['msg']); ?></div>
          <?php endif; ?>

          <?php if ($c): ?>
          <section class="mp-card mp-who" aria-label="Employee">
            <span class="av" style="background:<?php echo vs_pay_h(vs_pay_color($PM['name'])); ?>"><?php echo vs_pay_h(vs_pay_initials($PM['name'])); ?></span>
            <div><div class="nm"><?php echo vs_pay_h($PM['name']); ?></div><div class="rl"><?php echo vs_pay_h($c['title'] !== '' ? $c['title'] : 'Admin'); ?></div></div>
            <div class="chips">
              <?php if ($shiftText !== ''): ?><span class="mp-chip"><i class="bi bi-clock-history" aria-hidden="true"></i><?php echo vs_pay_h($shiftText); ?></span><?php endif; ?>
              <span class="mp-chip"><i class="bi bi-cash-coin" aria-hidden="true"></i><?php echo vs_pay_h($mpM((float)$c['rate'])); ?> / hour</span>
              <?php if ($c['expected'] > 0): ?><span class="mp-chip"><i class="bi bi-bullseye" aria-hidden="true"></i>Target <?php echo vs_pay_h(vs_pay_fmt_hours($c['expected'])); ?> hrs &middot; <?php echo (int)min(100, round($c['hours'] / $c['expected'] * 100)); ?>%</span><?php endif; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if ($vsIsOwnerView): ?>
            <div class="alert alert-info">You are the owner, so you are not paid. Use <strong>Team payments</strong> below to see and mark the admins' pay. Rates, work time and adjustments are on the <a href="admin-pay.php">Payroll page</a>.</div>
          <?php elseif (!$c): ?>
            <div class="alert alert-info">Your pay could not be loaded right now.</div>
          <?php else: $sb = $stBadge[$c['status']]; ?>

          <?php if ($c['rate'] <= 0): ?>
            <div class="alert alert-warning">Your hourly rate has not been set yet, so your pay shows <?php echo vs_pay_h($mpM(0)); ?>. Ask the owner to set it.</div>
          <?php endif; ?>

          <!-- top cards -->
          <div class="mp-grid4">
            <div class="mp-card mp-stat s-blue"><div class="top"><span class="ico"><i class="bi bi-clock" aria-hidden="true"></i></span><span class="lbl">Total Hours Worked</span></div>
              <div class="val"><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'])); ?> <small>hrs</small></div>
              <div class="foot"><span>This month<?php echo $c['adj'] != 0 ? ' (incl. ' . ($c['adj'] > 0 ? '+' : '') . vs_pay_h(vs_pay_fmt_hours($c['adj'])) . ' adjusted)' : ''; ?></span><?php echo $mpTrend((float)$c['hours'], $prevRow ? (float)$prevRow['hours'] : null); ?></div></div>
            <div class="mp-card mp-stat s-green"><div class="top"><span class="ico"><i class="bi bi-cash-coin" aria-hidden="true"></i></span><span class="lbl">Task Bonuses</span></div>
              <div class="val"><?php echo vs_pay_h($mpM($c['bonus'])); ?></div>
              <div class="foot"><span>This month<?php echo ($c['status'] === 'nopay' && $c['bonus'] > 0) ? ' &middot; counts once payable' : ''; ?></span><?php echo $mpTrend((float)$c['bonus'], $prevRow ? (float)$prevRow['bonus'] : null); ?></div></div>
            <div class="mp-card mp-stat s-violet"><div class="top"><span class="ico"><i class="bi bi-wallet2" aria-hidden="true"></i></span><span class="lbl">Hourly Rate</span></div>
              <div class="val"><?php echo vs_pay_h($mpM($c['rate'])); ?></div>
              <div class="foot"><span>Per hour</span></div></div>
            <div class="mp-card mp-stat s-pink"><div class="top"><span class="ico"><i class="bi bi-wallet" aria-hidden="true"></i></span><span class="lbl">Estimated Pay</span></div>
              <div class="val"><?php echo vs_pay_h($mpM($estimated)); ?></div>
              <div class="foot"><span>This month (hours &times; rate + bonuses)</span></div></div>
          </div>

          <!-- breakdown / status / quick info -->
          <div class="mp-grid3">
            <section class="mp-card" aria-label="Earnings breakdown">
              <h2>Earnings Breakdown</h2>
              <div class="mp-break">
                <div class="mp-donut">
                  <svg viewBox="0 0 120 120" aria-hidden="true">
                    <defs><linearGradient id="mpG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b82f6"/><stop offset="1" stop-color="#8b5cf6"/></linearGradient></defs>
                    <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(127,127,160,.25)" stroke-width="14"/>
                    <?php if ($donutSum > 0):
                        $C = 2 * M_PI * 50; $off = 0.0;
                        foreach ([[$basePay, 'url(#mpG)'], [(float)$c['bonus'], '#10b981'], [$deduct, '#d946ef']] as [$part, $col]):
                            if ($part <= 0) { continue; }
                            $len = $part / $donutSum * $C; ?>
                      <circle cx="60" cy="60" r="50" fill="none" stroke="<?php echo $col; ?>" stroke-width="14" stroke-dasharray="<?php echo round(max(0, $len - 1.5), 2); ?> <?php echo round($C, 2); ?>" stroke-dashoffset="<?php echo round(-$off, 2); ?>"/>
                    <?php $off += $len; endforeach; endif; ?>
                  </svg>
                  <div class="mid"><small>PKR</small><span style="font-size:1.35rem"><?php echo vs_pay_h(number_format($estimated, 0)); ?></span></div>
                </div>
                <div class="mp-legend">
                  <div class="row1"><span><span class="dot" style="background:#3b82f6"></span>Base Pay (Hours)</span><b><?php echo vs_pay_h($mpM($basePay)); ?></b><span class="p"><?php echo $pctOf($basePay, $donutSum); ?></span></div>
                  <div class="row1"><span><span class="dot" style="background:#10b981"></span>Task Bonuses</span><b><?php echo vs_pay_h($mpM((float)$c['bonus'])); ?></b><span class="p"><?php echo $pctOf((float)$c['bonus'], $donutSum); ?></span></div>
                  <div class="row1"><span><span class="dot" style="background:#d946ef"></span>Deductions</span><b><?php echo vs_pay_h($mpM($deduct)); ?></b><span class="p"><?php echo $pctOf($deduct, $donutSum); ?></span></div>
                </div>
              </div>
            </section>

            <section class="mp-card" aria-label="Payment status">
              <h2>Payment Status <span class="mp-pill <?php echo $sb[1]; ?>"><?php echo $sb[0]; ?></span></h2>
              <div class="mp-status <?php echo $c['status']; ?>">
                <span class="ic"><i class="bi <?php echo $c['status'] === 'paid' ? 'bi-check2-circle' : ($c['status'] === 'nopay' ? 'bi-x-circle' : 'bi-credit-card-2-front'); ?>" aria-hidden="true"></i></span>
                <div>
                  <?php if ($c['status'] === 'paid'): ?>
                    <b>You were paid <?php echo vs_pay_h($mpM($c['amount'])); ?>.</b>
                    <div class="small" style="color:var(--mp-muted)"><?php echo $c['paid_by'] !== '' ? 'Marked paid by ' . vs_pay_h($c['paid_by']) : 'Marked paid'; ?><?php echo $c['paid_at'] !== '' ? ' on ' . vs_pay_h(date('M j, Y g:i A', strtotime($c['paid_at']))) : ''; ?>.<?php if ($c['live'] > $c['amount'] + 0.004): ?> You earned <?php echo vs_pay_h($mpM($c['live'] - $c['amount'])); ?> more since (not yet paid).<?php endif; ?></div>
                  <?php elseif ($c['status'] === 'nopay'): ?>
                    <b>Not paid this month.</b>
                    <div class="small" style="color:var(--mp-muted)"><?php echo vs_pay_h(implode('; ', $c['why'])); ?>.</div>
                  <?php else: ?>
                    <b>Your payment is currently pending.</b>
                    <div class="small" style="color:var(--mp-muted)">It will be processed by the admin soon.</div>
                  <?php endif; ?>
                </div>
              </div>
              <button type="button" class="mp-btn" data-bs-toggle="modal" data-bs-target="#mpDetails"><i class="bi bi-info-circle" aria-hidden="true"></i> Payment Details</button>
            </section>

            <section class="mp-card mp-info" aria-label="Quick info">
              <h2>Quick Info</h2>
              <div class="row1"><span><i class="bi bi-calendar3" aria-hidden="true"></i>Month</span><b><?php echo vs_pay_h($PM['monthLabel']); ?></b></div>
              <div class="row1"><span><i class="bi bi-clock" aria-hidden="true"></i>Hours Worked</span><b><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'])); ?> hrs</b></div>
              <div class="row1"><span><i class="bi bi-cash-coin" aria-hidden="true"></i>Task Bonuses</span><b><?php echo vs_pay_h($mpM($c['bonus'])); ?></b></div>
              <div class="row1"><span><i class="bi bi-credit-card" aria-hidden="true"></i>Payment Status</span><span class="mp-pill <?php echo $sb[1]; ?>"><?php echo $sb[0]; ?></span></div>
            </section>
          </div>

          <!-- daily hours / bonuses / history -->
          <div class="mp-grid3b">
            <section class="mp-card" aria-label="Daily hours">
              <h2><span><i class="bi bi-calendar3" aria-hidden="true"></i>&nbsp; Daily Hours</span></h2>
              <?php
                $W = 560; $H = 230; $pl = 34; $pb = 26; $pt = 8; $pr = 6;
                $cw = $W - $pl - $pr; $ch = $H - $pb - $pt; $bw = $cw / $daysInMonth;
              ?>
              <svg class="mp-chart" viewBox="0 0 <?php echo $W; ?> <?php echo $H; ?>" role="img" aria-label="Hours worked each day">
                <defs><linearGradient id="mpBar" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#3b2fd6"/></linearGradient></defs>
                <?php for ($t = 0; $t <= 5; $t++): $val = $yMax / 5 * $t; $y = $pt + $ch - ($val / $yMax * $ch); ?>
                  <line x1="<?php echo $pl; ?>" x2="<?php echo $W - $pr; ?>" y1="<?php echo round($y, 1); ?>" y2="<?php echo round($y, 1); ?>" stroke="rgba(127,140,200,.25)" stroke-dasharray="3 4"/>
                  <text x="<?php echo $pl - 6; ?>" y="<?php echo round($y + 4, 1); ?>" text-anchor="end" font-size="11" fill="currentColor" opacity=".7"><?php echo rtrim(rtrim(number_format($val, 1), '0'), '.'); ?></text>
                <?php endfor; ?>
                <?php foreach ($dayHours as $d => $h): $bh = $yMax > 0 ? $h / $yMax * $ch : 0; $x = $pl + ($d - 1) * $bw; ?>
                  <?php if ($h > 0): ?><rect x="<?php echo round($x + $bw * .18, 1); ?>" y="<?php echo round($pt + $ch - $bh, 1); ?>" width="<?php echo round($bw * .64, 1); ?>" height="<?php echo round($bh, 1); ?>" rx="2" fill="url(#mpBar)"><title><?php echo vs_pay_h(date('M j', strtotime($PM['month'] . '-' . sprintf('%02d', $d))) . ': ' . vs_pay_fmt_hours($h) . ' hrs'); ?></title></rect><?php endif; ?>
                  <?php if ($d === 1 || $d % 5 === 0): ?><text x="<?php echo round($x + $bw / 2, 1); ?>" y="<?php echo $H - 6; ?>" text-anchor="middle" font-size="11" fill="currentColor" opacity=".7"><?php echo sprintf('%02d', $d); ?></text><?php endif; ?>
                <?php endforeach; ?>
              </svg>
              <?php if ($maxH <= 0): ?><div class="mp-empty">No work time recorded this month.</div><?php endif; ?>
            </section>

            <section class="mp-card" aria-label="Bonuses and adjustments">
              <h2>Bonuses &amp; Adjustments</h2>
              <?php if (!$mpFeed): ?>
                <div class="mp-empty">Nothing yet this month. Bonuses are added when the owner marks a task you were given as done.</div>
              <?php else: ?>
              <div class="mp-feed">
                <?php foreach (array_slice($mpFeed, 0, 6) as $f): ?>
                  <div class="it">
                    <span class="ic <?php echo $f['kind']; ?>"><i class="bi <?php echo $f['kind'] === 'bonus' ? 'bi-cash-stack' : ($f['kind'] === 'minus' ? 'bi-dash-lg' : 'bi-plus-lg'); ?>" aria-hidden="true"></i></span>
                    <div class="mid"><b><?php echo vs_pay_h($f['title']); ?></b><small><?php echo vs_pay_h($f['sub'] !== '' ? $f['sub'] : '—'); ?></small></div>
                    <div class="text-end"><div class="v <?php echo $f['kind'] === 'minus' ? 'neg' : 'pos'; ?>"><?php echo vs_pay_h($f['val']); ?></div><div class="d"><?php echo $f['t'] ? vs_pay_h(date('M j, Y', $f['t'])) : ''; ?></div></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
            </section>

            <section class="mp-card" aria-label="Recent history">
              <h2>Recent History</h2>
              <div class="table-responsive"><table class="mp-table">
                <thead><tr><th>Month</th><th>Hours</th><th>Bonuses</th><th>Total Pay</th><th>Status</th></tr></thead><tbody>
                <?php foreach ($PM['history'] as $hrow): $r = $hrow['row']; $hb = $stBadge[$r['status']]; ?>
                  <tr<?php echo $hrow['month'] === $PM['month'] ? ' class="cur"' : ''; ?>>
                    <td><a href="my-pay.php?month=<?php echo vs_pay_h($hrow['month']); ?>"><?php echo vs_pay_h(date('M Y', strtotime($hrow['month'] . '-01'))); ?></a></td>
                    <td><?php echo vs_pay_h(vs_pay_fmt_hours($r['hours'])); ?></td>
                    <td><?php echo vs_pay_h($mpM($r['bonus'])); ?></td>
                    <td><strong><?php echo vs_pay_h($mpM($r['status'] === 'paid' ? $r['amount'] : round($r['hours'] * $r['rate'], 2) + $r['bonus'])); ?></strong></td>
                    <td><span class="mp-pill <?php echo $hb[1]; ?>"><?php echo $hb[0]; ?></span></td>
                  </tr>
                <?php endforeach; ?>
                </tbody></table></div>
            </section>
          </div>

          <!-- Printable payslip (hidden on screen) -->
          <div id="mpSlip" class="mp-slip" aria-hidden="true">
            <h1>Payslip &middot; <?php echo vs_pay_h($PM['monthLabel']); ?></h1>
            <div class="sub">VibeStream Admin Panel</div>
            <div class="meta">
              <div><strong><?php echo vs_pay_h($PM['name']); ?></strong><br><?php echo vs_pay_h($c['title'] !== '' ? $c['title'] : 'Admin'); ?></div>
              <div style="text-align:right">Status: <strong><?php echo vs_pay_h($sb[0]); ?></strong><?php if ($c['status'] === 'paid' && $c['paid_at'] !== ''): ?><br>Paid on <?php echo vs_pay_h(date('M j, Y', strtotime($c['paid_at']))); ?><?php echo $c['paid_by'] !== '' ? ' by ' . vs_pay_h($c['paid_by']) : ''; ?><?php endif; ?></div>
            </div>
            <table>
              <thead><tr><th>Description</th><th class="r">Quantity</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
              <tbody>
                <tr><td>Hours worked</td><td class="r"><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'] + $negAdj)); ?> hrs</td><td class="r"><?php echo vs_pay_h($mpM((float)$c['rate'])); ?></td><td class="r"><?php echo vs_pay_h($mpM($basePay)); ?></td></tr>
                <?php if ($deduct > 0): ?><tr><td>Deductions (hours removed)</td><td class="r"><?php echo vs_pay_h(vs_pay_fmt_hours($negAdj)); ?> hrs</td><td class="r"><?php echo vs_pay_h($mpM((float)$c['rate'])); ?></td><td class="r">- <?php echo vs_pay_h($mpM($deduct)); ?></td></tr><?php endif; ?>
                <?php foreach ($PM['bonuses'] as $b): ?><tr><td>Task bonus: <?php echo vs_pay_h($b['reason'] !== '' ? $b['reason'] : 'Task'); ?></td><td class="r">&nbsp;</td><td class="r">&nbsp;</td><td class="r"><?php echo vs_pay_h($mpM((float)$b['amount'])); ?></td></tr><?php endforeach; ?>
                <tr class="tot"><td colspan="3">Total <?php echo $c['status'] === 'paid' ? 'paid' : 'estimated'; ?></td><td class="r"><?php echo vs_pay_h($mpM($estimated)); ?></td></tr>
              </tbody>
            </table>
            <div class="foot">Generated on <?php echo vs_pay_h(date('M j, Y g:i A')); ?>. Estimated pay becomes final when it is marked as paid.</div>
          </div>

          <!-- Payment details -->
          <div class="modal fade mp-modal" id="mpDetails" tabindex="-1" aria-labelledby="mpDetailsT" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
              <div class="modal-header"><h2 class="modal-title h5" id="mpDetailsT">Payment details &middot; <?php echo vs_pay_h($PM['monthLabel']); ?></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
              <div class="modal-body">
                <table class="table table-sm mb-3" style="--bs-table-bg:transparent;--bs-table-color:inherit">
                  <tr><td><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'])); ?> hrs &times; <?php echo vs_pay_h($mpM($c['rate'])); ?></td><td class="text-end"><?php echo vs_pay_h($mpM($hoursPay)); ?></td></tr>
                  <tr><td>Task bonuses</td><td class="text-end">+ <?php echo vs_pay_h($mpM($c['bonus'])); ?></td></tr>
                  <tr class="fw-bold"><td>Total if paid</td><td class="text-end"><?php echo vs_pay_h($mpM($hoursPay + $c['bonus'])); ?></td></tr>
                </table>
                <div class="small mb-1">Hours needed to be paid: <strong><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'])); ?></strong> of <?php echo vs_pay_h(vs_pay_fmt_hours($minH)); ?></div>
                <div class="mp-bar mb-3"><span style="width:<?php echo (int)min(100, $minH > 0 ? round($c['hours'] / $minH * 100) : 100); ?>%"></span></div>
                <div class="small mb-1">Actions needed to be paid: <strong><?php echo (int)$c['actions']; ?></strong> of <?php echo $minA; ?></div>
                <div class="mp-bar mb-3"><span style="width:<?php echo (int)min(100, $minA > 0 ? round($c['actions'] / $minA * 100) : 100); ?>%"></span></div>
                <?php if ($c['expected'] > 0): ?>
                  <div class="small mb-1">Hours of your shift this month: <strong><?php echo vs_pay_h(vs_pay_fmt_hours($c['hours'])); ?></strong> of <?php echo vs_pay_h(vs_pay_fmt_hours($c['expected'])); ?></div>
                  <div class="mp-bar mb-3"><span style="width:<?php echo (int)min(100, round($c['hours'] / $c['expected'] * 100)); ?>%"></span></div>
                <?php endif; ?>
                <p class="small mb-0" style="opacity:.75">Time on a break or away is not counted as work time.</p>
              </div>
            </div></div>
          </div>
          <?php endif; ?>

          <?php if ($PM['canPay']): ?>
          <section class="panel mt-3" aria-label="Team payments">
            <h2 class="h5 mb-1">Team payments &middot; <?php echo vs_pay_h($PM['monthLabel']); ?></h2>
            <p class="text-muted small mb-2">You can mark an admin as paid. The amount saved is their hours &times; rate plus task bonuses at that moment. You cannot mark your own pay.</p>
            <?php if (!$PM['team']): ?>
              <div class="text-muted small">No other admins found.</div>
            <?php else: ?>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
              <thead><tr><th>Admin</th><th class="text-end">Hours</th><th class="text-end">Rate</th><th class="text-end">Bonus</th><th class="text-end">Pay</th><th>Status</th><th></th></tr></thead><tbody>
              <?php foreach ($PM['team'] as $t): $tm = $stMeta[$t['status']]; ?>
                <tr>
                  <td><strong><?php echo vs_pay_h($t['name']); ?></strong><?php if ($t['title'] !== ''): ?><div class="text-muted small"><?php echo vs_pay_h($t['title']); ?></div><?php endif; ?></td>
                  <td class="text-end"><?php echo vs_pay_h(vs_pay_fmt_hours($t['hours'])); ?></td>
                  <td class="text-end"><?php echo vs_pay_h($mpM($t['rate'])); ?></td>
                  <td class="text-end"><?php echo vs_pay_h($mpM($t['bonus'])); ?></td>
                  <td class="text-end"><strong><?php echo vs_pay_h($mpM($t['amount'])); ?></strong>
                    <?php if ($t['status'] === 'paid' && $t['live'] > $t['amount'] + 0.004): ?><div class="small text-warning">+ <?php echo vs_pay_h($mpM($t['live'] - $t['amount'])); ?> earned since</div><?php endif; ?></td>
                  <td><span class="badge <?php echo $tm[1]; ?>"><?php echo $tm[0]; ?></span>
                    <?php if ($t['status'] === 'paid' && ($t['paid_by'] !== '' || $t['paid_at'] !== '')): ?><div class="text-muted small"><?php echo $t['paid_by'] !== '' ? 'by ' . vs_pay_h($t['paid_by']) : ''; ?> <?php echo $t['paid_at'] !== '' ? vs_pay_h(date('M j, g:i A', strtotime($t['paid_at']))) : ''; ?></div><?php endif; ?>
                    <?php if ($t['status'] === 'nopay' && $t['why']): ?><div class="text-muted small"><?php echo vs_pay_h(implode('; ', $t['why'])); ?></div><?php endif; ?></td>
                  <td class="text-end">
                    <?php if ($t['status'] === 'pending' && $t['amount'] > 0): ?>
                      <form method="post" class="m-0" data-ask="Mark <?php echo vs_pay_h($t['name']); ?> as paid <?php echo vs_pay_h($mpM($t['amount'])); ?> for <?php echo vs_pay_h($PM['monthLabel']); ?>?" onsubmit="return confirm(this.getAttribute('data-ask'));">
                        <input type="hidden" name="csrf" value="<?php echo vs_pay_h($PM['csrf']); ?>">
                        <input type="hidden" name="do" value="markpaid">
                        <input type="hidden" name="month" value="<?php echo vs_pay_h($PM['month']); ?>">
                        <input type="hidden" name="admin" value="<?php echo vs_pay_h($t['key']); ?>">
                        <button class="btn btn-success btn-sm" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Mark paid</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody></table></div>
            <?php endif; ?>
          </section>
          <?php endif; ?>

        </div>
      </main>

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