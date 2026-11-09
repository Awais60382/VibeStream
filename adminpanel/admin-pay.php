<?php
/*
 * VibeStream admin panel - Admin Payroll (SITE OWNER ONLY).
 * Monthly work hours, what each admin did, rate, total pay, and Paid / Pending status.
 *   Hours   : check-in time tracking (data/checkin-daily.json) + manual adjustments
 *   Work    : Admin activity log (admin_activity_log table)
 *   Saved   : rates, titles, paid status, adjustments and a payroll log (tables are created automatically)
 * Anyone who is not the owner is sent away.
 * Needs page-theme.php (the shared look) in the same folder.
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
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/owner-notice-store.php';     // vs_notice_me(), vs_notice_admins()
require_once __DIR__ . '/admin-activity-store.php';   // vs_activity_ensure()
require_once __DIR__ . '/checkin-lib.php';            // check-in hours file

if (!isset($pdo) || !($pdo instanceof PDO) || !function_exists('vs_is_owner') || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo "<script>alert('Access Denied: Only the site owner can open Admin Payroll.'); window.location.href='index.php';</script>";
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* ================= SETTINGS: edit these ================= */
const VS_PAY_CURRENCY     = 'PKR';
const VS_PAY_RATE_DEFAULT = 0.0;   // pay per hour for an admin with no rate set (change rates with "Update Rates")
const VS_PAY_MIN_HOURS    = 10;    // an admin is only paid if they worked at least this many hours in the month...
const VS_PAY_MIN_ACTIONS  = 5;     // ...AND did at least this many actions (videos, users, premium, settings...)
/* ======================================================== */

/* ---------------------------------------------------------------- helpers (unique names: header.php cannot overwrite them) */
function vs_pay_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function vs_pay_money(float $v): string
{
    return VS_PAY_CURRENCY . ' ' . number_format($v, 2);
}

function vs_pay_ensure(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_settings (
        username VARCHAR(100) NOT NULL PRIMARY KEY,
        rate DECIMAL(10,2) NOT NULL DEFAULT 0,
        title VARCHAR(60) NOT NULL DEFAULT \'\'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    try {
        $psCols = $pdo->query('SHOW COLUMNS FROM admin_pay_settings')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('shift_start', $psCols, true)) {
            $pdo->exec("ALTER TABLE admin_pay_settings ADD COLUMN shift_start CHAR(5) NOT NULL DEFAULT ''");
        }
        if (!in_array('shift_end', $psCols, true)) {
            $pdo->exec("ALTER TABLE admin_pay_settings ADD COLUMN shift_end CHAR(5) NOT NULL DEFAULT ''");
        }
        if (!in_array('work_days', $psCols, true)) {
            $pdo->exec("ALTER TABLE admin_pay_settings ADD COLUMN work_days VARCHAR(20) NOT NULL DEFAULT ''");
        }
    } catch (Throwable $ex) {
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_months (
        month CHAR(7) NOT NULL,
        username VARCHAR(100) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT \'pending\',
        paid_amount DECIMAL(12,2) NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (month, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    try {
        $pmCols = $pdo->query('SHOW COLUMNS FROM admin_pay_months')->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('note', $pmCols, true)) {
            $pdo->exec("ALTER TABLE admin_pay_months ADD COLUMN note VARCHAR(200) NOT NULL DEFAULT ''");
        }
        if (!in_array('override', $pmCols, true)) {
            $pdo->exec('ALTER TABLE admin_pay_months ADD COLUMN override TINYINT(1) NOT NULL DEFAULT 0');
        }
    } catch (Throwable $ex) {
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_adjust (
        id INT AUTO_INCREMENT PRIMARY KEY,
        month CHAR(7) NOT NULL,
        username VARCHAR(100) NOT NULL,
        hours DECIMAL(7,2) NOT NULL,
        reason VARCHAR(200) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL,
        KEY idx_month_user (month, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    // Task rewards: one row per finished task (created by assign-task.php), added to that month's pay.
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_bonus (
        task_id VARCHAR(32) NOT NULL PRIMARY KEY,
        month CHAR(7) NOT NULL,
        username VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        reason VARCHAR(200) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL,
        KEY idx_month_user (month, username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS admin_pay_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        text VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function vs_pay_log(PDO $pdo, string $text): void
{
    try {
        $pdo->prepare('INSERT INTO admin_pay_log (text, created_at) VALUES (?, NOW())')->execute([mb_substr($text, 0, 255)]);
    } catch (Throwable $ex) {
    }
}

/** Work time helper: hours in one shift (supports overnight), and expected hours in a month. */
function vs_pay_shift_hours(string $a, string $b): float
{
    if (!preg_match('/^\d{2}:\d{2}$/', $a) || !preg_match('/^\d{2}:\d{2}$/', $b)) {
        return 0.0;
    }
    $m1 = (int)substr($a, 0, 2) * 60 + (int)substr($a, 3, 2);
    $m2 = (int)substr($b, 0, 2) * 60 + (int)substr($b, 3, 2);
    if ($m2 <= $m1) {
        $m2 += 1440;
    }
    return ($m2 - $m1) / 60;
}

function vs_pay_expected(string $month, string $days, float $perDay): float
{
    $set = array_filter(array_map('intval', explode(',', $days)), static fn($d) => $d >= 1 && $d <= 7);
    if (!$set || $perDay <= 0) {
        return 0.0;
    }
    $n = 0;
    $total = (int)date('t', strtotime($month . '-01'));
    for ($d = 1; $d <= $total; $d++) {
        if (in_array((int)date('N', strtotime($month . '-' . sprintf('%02d', $d))), $set, true)) {
            $n++;
        }
    }
    return round($n * $perDay, 2);
}

function vs_pay_fmt_hours(float $h): string
{
    return number_format($h, 2);
}

/** Everything for one month. */
function vs_pay_build(PDO $pdo, string $month, array $admins, array $settings): array
{
    $start = $month . '-01 00:00:00';
    $end   = date('Y-m-01 00:00:00', strtotime($start . ' +1 month'));

    $secs = [];
    foreach (vs_checkin_json_read(VS_CHECKIN_DAILY) as $day => $names) {
        if (strpos((string)$day, $month) !== 0 || !is_array($names)) {
            continue;
        }
        foreach ($names as $nm => $sc) {
            $key = mb_strtolower(trim((string)$nm));
            $secs[$key] = ($secs[$key] ?? 0) + (int)$sc;
        }
    }

    $adj = [];
    $q = $pdo->prepare('SELECT username, SUM(hours) AS h FROM admin_pay_adjust WHERE month = ? GROUP BY username');
    $q->execute([$month]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $adj[mb_strtolower((string)$r['username'])] = (float)$r['h'];
    }

    $bonusBy = [];
    $q = $pdo->prepare('SELECT username, SUM(amount) AS a FROM admin_pay_bonus WHERE month = ? GROUP BY username');
    $q->execute([$month]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bonusBy[mb_strtolower(trim((string)$r['username']))] = (float)$r['a'];
    }

    $work = [];
    try {
        vs_activity_ensure($pdo);
        $q = $pdo->prepare('SELECT admin_username, action, COUNT(*) AS n FROM admin_activity_log
                            WHERE created_at >= ? AND created_at < ? GROUP BY admin_username, action');
        $q->execute([$start, $end]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $work[mb_strtolower(trim((string)$r['admin_username']))][(string)$r['action']] = (int)$r['n'];
        }
    } catch (Throwable $ex) {
    }

    $paid = [];
    $q = $pdo->prepare('SELECT username, status, paid_amount, note, override FROM admin_pay_months WHERE month = ?');
    $q->execute([$month]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $paid[mb_strtolower((string)$r['username'])] = $r;
    }

    $labels = ['video' => 'Video work', 'user' => 'User management', 'premium' => 'Premium', 'settings' => 'Settings', 'other' => 'Other'];
    $rows = [];
    $used = [];
    $tot = ['hours' => 0.0, 'pay' => 0.0, 'paid' => 0, 'pending' => 0, 'nopay' => 0, 'active' => 0, 'paidAmt' => 0.0, 'pendingAmt' => 0.0];

    foreach ($admins as $a) {
        $name = (string)$a['username'];
        $key  = mb_strtolower(trim($name));
        $used[$key] = true;
        $st   = $settings[$key] ?? null;
        $rate = $st ? (float)$st['rate'] : VS_PAY_RATE_DEFAULT;
        $hoursWorked = ($secs[$key] ?? 0) / 3600;
        $hours = max(0.0, $hoursWorked + ($adj[$key] ?? 0));
        $acts = $work[$key] ?? [];
        $tasks = [];
        $actions = 0;
        arsort($acts);
        foreach ($acts as $type => $cnt) {
            if ($type === 'session' || $type === 'page_view') {
                continue;
            }
            $actions += $cnt;
            $tasks[] = [$labels[$type] ?? ucfirst($type), $cnt];
        }
        $why = [];
        if ($hours < VS_PAY_MIN_HOURS) {
            $why[] = 'only ' . vs_pay_fmt_hours($hours) . ' hrs (needs ' . VS_PAY_MIN_HOURS . ')';
        }
        if ($actions < VS_PAY_MIN_ACTIONS) {
            $why[] = 'only ' . $actions . ' action' . ($actions === 1 ? '' : 's') . ' (needs ' . VS_PAY_MIN_ACTIONS . ')';
        }
        $rec = $paid[$key] ?? null;
        $override = $rec && !empty($rec['override']) && $rec['status'] !== 'nopay';   // owner chose "Pay anyway"
        $eligible = !$why || $override;
        $note = '';
        $manual = false;
        $bonus = $bonusBy[$key] ?? 0.0;                                   // task rewards earned this month
        $live = $eligible ? round($hours * $rate, 2) + $bonus : 0.0;
        if ($rec && $rec['status'] === 'paid') {
            $status = 'paid';
            $amount = $rec['paid_amount'] !== null ? (float)$rec['paid_amount'] : $live;
        } elseif ($rec && $rec['status'] === 'nopay') {
            $status = 'nopay';                      // owner chose "Do not pay"
            $amount = 0.0;
            $manual = true;
            $note = trim((string)($rec['note'] ?? ''));
            array_unshift($why, 'marked Do not pay by the owner' . ($note !== '' ? ': ' . $note : ''));
        } elseif ($eligible) {
            $status = 'pending';
            $amount = $live;
        } else {
            $status = 'nopay';
            $amount = 0.0;
        }
        $tot['hours'] += $hours;
        $tot['pay'] += $amount;
        if ($hours > 0) {
            $tot['active']++;
        }
        if ($status === 'paid') {
            $tot['paid']++;
            $tot['paidAmt'] += $amount;
        } elseif ($status === 'pending') {
            $tot['pending']++;
            $tot['pendingAmt'] += $amount;
        } else {
            $tot['nopay']++;
        }
        $rows[] = [
            'key' => $key, 'name' => $name, 'title' => $st ? (string)$st['title'] : '', 'rate' => $rate,
            'hours' => $hours, 'adj' => $adj[$key] ?? 0.0, 'sessions' => (int)($acts['session'] ?? ($work[$key]['session'] ?? 0)),
            'pages' => (int)($work[$key]['page_view'] ?? 0), 'actions' => $actions, 'tasks' => $tasks,
            'eligible' => $eligible, 'why' => $why, 'amount' => $amount, 'status' => $status, 'bonus' => $bonus,
            'manual' => $manual, 'note' => $note, 'override' => $override,
            'wstart' => $st ? (string)($st['shift_start'] ?? '') : '', 'wend' => $st ? (string)($st['shift_end'] ?? '') : '',
            'wdays' => $st ? (string)($st['work_days'] ?? '') : '',
            'expected' => $st ? vs_pay_expected($month, (string)($st['work_days'] ?? ''), vs_pay_shift_hours((string)($st['shift_start'] ?? ''), (string)($st['shift_end'] ?? ''))) : 0.0,
        ];
    }

    $unmatched = [];
    foreach ($secs as $key => $sc) {
        if (!isset($used[$key])) {
            $unmatched[$key] = $sc / 3600;
        }
    }
    return ['rows' => $rows, 'tot' => $tot, 'unmatched' => $unmatched];
}

function vs_pay_pct(float $cur, float $prev): ?float
{
    return $prev > 0 ? (($cur - $prev) / $prev) * 100 : null;
}

function vs_pay_color(string $name): string
{
    $c = ['#6c5ce7', '#0984e3', '#00b894', '#e17055', '#d63031', '#e84393', '#fdcb6e', '#0abde3'];
    return $c[abs(crc32($name)) % count($c)];
}

function vs_pay_initials(string $name): string
{
    $name = trim($name);
    return mb_strtoupper(mb_substr($name, 0, 2)) ?: '?';
}

/* ---------------------------------------------------------------- sample data (preview only: add ?demo=1 to the address) */
function vs_pay_demo(float $scale): array
{
    $defs = [
        // name, role, rate, hours, adj, sessions, pages, status, tasks
        ['Ayesha Khan',  'Lead Moderator',  450, 86.50, 0.0,  31, 412, 'paid',    [['Video work', 64], ['User management', 38], ['Settings', 7]]],
        ['Hamza Ali',    'Video Editor',    400, 74.25, 2.5,  27, 365, 'paid',    [['Video work', 91], ['Premium', 12]]],
        ['Sara Malik',   'Content Manager', 420, 92.00, 0.0,  34, 448, 'paid',    [['Video work', 77], ['Settings', 15], ['Other', 9]]],
        ['Bilal Ahmed',  'Support Admin',   350, 58.50, 0.0,  22, 297, 'pending', [['User management', 52], ['Premium', 21]]],
        ['Hina Raza',    'Moderator',       380, 41.75, -1.0, 19, 233, 'pending', [['User management', 33], ['Video work', 18]]],
        ['Usman Tariq',  'Junior Admin',    300,  6.50, 0.0,   4,  58, 'nopay',   [['Video work', 3]]],
    ];
    $rows = [];
    $tot = ['hours' => 0.0, 'pay' => 0.0, 'paid' => 0, 'pending' => 0, 'nopay' => 0, 'active' => 0, 'paidAmt' => 0.0, 'pendingAmt' => 0.0];
    foreach ($defs as $d) {
        $hours = round($d[3] * $scale, 2);
        $actions = 0;
        foreach ($d[8] as $t) {
            $actions += $t[1];
        }
        $actions = (int)round($actions * $scale);
        $why = [];
        if ($d[7] === 'nopay') {
            $why[] = 'only ' . vs_pay_fmt_hours($hours) . ' hrs (needs ' . VS_PAY_MIN_HOURS . ')';
            if ($actions < VS_PAY_MIN_ACTIONS) {
                $why[] = 'only ' . $actions . ' actions (needs ' . VS_PAY_MIN_ACTIONS . ')';
            }
        }
        $amount = $d[7] === 'nopay' ? 0.0 : round($hours * $d[2], 2);
        $tot['hours'] += $hours;
        $tot['pay'] += $amount;
        $tot['active']++;
        if ($d[7] === 'paid') {
            $tot['paid']++;
            $tot['paidAmt'] += $amount;
        } elseif ($d[7] === 'pending') {
            $tot['pending']++;
            $tot['pendingAmt'] += $amount;
        } else {
            $tot['nopay']++;
        }
        $rows[] = [
            'key' => mb_strtolower($d[0]), 'name' => $d[0], 'title' => $d[1], 'rate' => (float)$d[2],
            'hours' => $hours, 'adj' => $d[4], 'sessions' => $d[5], 'pages' => $d[6], 'actions' => $actions, 'tasks' => $d[8],
            'eligible' => $d[7] !== 'nopay', 'why' => $why, 'amount' => $amount, 'status' => $d[7],
            'wstart' => '09:00', 'wend' => '17:00', 'wdays' => '1,2,3,4,5', 'expected' => 176.0,
            'manual' => false, 'note' => '', 'override' => false,
        ];
    }
    return ['rows' => $rows, 'tot' => $tot, 'unmatched' => []];
}

/* ---------------------------------------------------------------- setup */
vs_pay_ensure($pdo);
$PP = [];                                             // everything the page shows lives in $PP
$PP['me'] = vs_notice_me($pdo);
$PP['admins'] = vs_notice_admins($pdo, (int)$PP['me']['id']);
$PP['byKey'] = [];
foreach ($PP['admins'] as $a) {
    $PP['byKey'][mb_strtolower(trim((string)$a['username']))] = (string)$a['username'];
}

$PP['month'] = (string)($_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $PP['month']) || $PP['month'] > date('Y-m')) {
    $PP['month'] = date('Y-m');
}
$PP['sel'] = mb_strtolower(trim((string)($_GET['admin'] ?? '')));

if (empty($_SESSION['admin_pay_csrf'])) {
    $_SESSION['admin_pay_csrf'] = bin2hex(random_bytes(16));
}
$PP['csrf'] = $_SESSION['admin_pay_csrf'];

/* ---------------------------------------------------------------- actions (POST, then redirect) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do    = (string)($_POST['do'] ?? '');
    $pMon  = (string)($_POST['month'] ?? $PP['month']);
    $pUser = mb_strtolower(trim((string)($_POST['admin'] ?? '')));
    $flash = ['ok' => false, 'msg' => 'The request expired. Refresh the page and try again.'];
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $pMon) || $pMon > date('Y-m')) {
        $pMon = date('Y-m');
    }
    if (hash_equals($PP['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash['msg'] = 'Something went wrong. Please try again.';
        try {
            if (($do === 'paid' || $do === 'pending' || $do === 'nopay' || $do === 'allow') && isset($PP['byKey'][$pUser])) {
                $settingsNow = [];
                foreach ($pdo->query('SELECT * FROM admin_pay_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $settingsNow[mb_strtolower($r['username'])] = $r;
                }
                $built = vs_pay_build($pdo, $pMon, $PP['admins'], $settingsNow);
                $amt = 0.0;
                $wasOverride = false;
                foreach ($built['rows'] as $r) {
                    if ($r['key'] === $pUser) {
                        $amt = $r['eligible'] ? round($r['hours'] * $r['rate'], 2) + (float)($r['bonus'] ?? 0) : 0.0;
                        $wasOverride = !empty($r['override']);
                    }
                }
                $dbStatus = $do === 'allow' ? 'pending' : $do;
                $note = $do === 'nopay' ? mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200) : '';
                $ovr = ($do === 'allow' || ($do === 'paid' && $wasOverride)) ? 1 : 0;
                $pdo->prepare('INSERT INTO admin_pay_months (month, username, status, paid_amount, note, override, updated_at) VALUES (?,?,?,?,?,?,NOW())
                               ON DUPLICATE KEY UPDATE status = VALUES(status), paid_amount = VALUES(paid_amount), note = VALUES(note), override = VALUES(override), updated_at = NOW()')
                    ->execute([$pMon, $pUser, $dbStatus, $dbStatus === 'paid' ? $amt : null, $note, $ovr]);
                $doText = ['paid' => 'paid', 'pending' => 'pending', 'nopay' => 'do not pay', 'allow' => 'allowed to be paid (owner override)'][$do];
                vs_pay_log($pdo, $PP['byKey'][$pUser] . ' marked as ' . $doText . ' (' . $pMon . ')' . ($note !== '' ? ': ' . $note : ''));
                $flash = ['ok' => true, 'msg' => $PP['byKey'][$pUser] . ' marked as ' . $doText . '.'];
            } elseif ($do === 'adjust' && isset($PP['byKey'][$pUser])) {
                $hrs = round((float)($_POST['hours'] ?? 0), 2);
                $why = trim((string)($_POST['reason'] ?? ''));
                if ($hrs == 0.0 || abs($hrs) > 500) {
                    $flash['msg'] = 'Enter the hours to add or remove (use a minus sign to remove).';
                } elseif ($why === '') {
                    $flash['msg'] = 'Write the reason for the adjustment.';
                } else {
                    $pdo->prepare('INSERT INTO admin_pay_adjust (month, username, hours, reason, created_at) VALUES (?,?,?,?,NOW())')
                        ->execute([$pMon, $pUser, $hrs, mb_substr($why, 0, 200)]);
                    vs_pay_log($pdo, ($hrs > 0 ? '+' : '') . $hrs . ' hrs for ' . $PP['byKey'][$pUser] . ': ' . mb_substr($why, 0, 80));
                    $flash = ['ok' => true, 'msg' => 'Saved: ' . ($hrs > 0 ? '+' : '') . $hrs . ' hrs for ' . $PP['byKey'][$pUser] . '.'];
                }
            } elseif ($do === 'rates') {
                $rates  = (array)($_POST['rate'] ?? []);
                $titles = (array)($_POST['title'] ?? []);
                $n = 0;
                foreach ($PP['byKey'] as $key => $real) {
                    if (!array_key_exists($key, $rates)) {
                        continue;
                    }
                    $rate = max(0, min(1000000, round((float)$rates[$key], 2)));
                    $ws = trim((string)($_POST['wstart'][$key] ?? ''));
                    $we = trim((string)($_POST['wend'][$key] ?? ''));
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $ws) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $we)) {
                        $ws = '';
                        $we = '';
                    }
                    $wd = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['wdays'][$key] ?? [])), static fn($d) => $d >= 1 && $d <= 7)));
                    sort($wd);
                    $pdo->prepare('INSERT INTO admin_pay_settings (username, rate, title, shift_start, shift_end, work_days) VALUES (?,?,?,?,?,?)
                                   ON DUPLICATE KEY UPDATE rate = VALUES(rate), title = VALUES(title),
                                   shift_start = VALUES(shift_start), shift_end = VALUES(shift_end), work_days = VALUES(work_days)')
                        ->execute([$key, $rate, mb_substr(trim((string)($titles[$key] ?? '')), 0, 60), $ws, $we, implode(',', $wd)]);
                    $n++;
                }
                vs_pay_log($pdo, 'Pay rates and work times updated');
                $flash = ['ok' => true, 'msg' => 'Rates and work times saved for ' . $n . ' admin' . ($n === 1 ? '' : 's') . '.'];
            }
        } catch (Throwable $ex) {
            error_log('admin-pay action failed: ' . $ex->getMessage());
        }
    }
    $_SESSION['admin_pay_flash'] = $flash;
    header('Location: admin-pay.php?month=' . urlencode($pMon) . ($pUser !== '' ? '&admin=' . urlencode($pUser) : ''));
    exit;
}
$PP['flash'] = $_SESSION['admin_pay_flash'] ?? null;
unset($_SESSION['admin_pay_flash']);

/* ---------------------------------------------------------------- load data */
$PP['settings'] = [];
foreach ($pdo->query('SELECT * FROM admin_pay_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $PP['settings'][mb_strtolower($r['username'])] = $r;
}
$PP['cur']  = vs_pay_build($pdo, $PP['month'], $PP['admins'], $PP['settings']);
$PP['prev'] = vs_pay_build($pdo, date('Y-m', strtotime($PP['month'] . '-01 -1 month')), $PP['admins'], $PP['settings']);
$PP['demo'] = (($_GET['demo'] ?? '') === '1' && ($_GET['export'] ?? '') !== 'csv');   // sample-data preview, never saved
if ($PP['demo']) {
    $PP['cur']  = vs_pay_demo(1.0);
    $PP['prev'] = vs_pay_demo(0.88);
}

/* ---------------------------------------------------------------- export (CSV opens in Excel) */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="admin-payroll-' . $PP['month'] . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Admin', 'Role', 'Total hours', 'Work sessions', 'Actions', 'Rate (' . VS_PAY_CURRENCY . '/hr)', 'Total pay (' . VS_PAY_CURRENCY . ')', 'Status', 'Reason']);
    $statusText = ['paid' => 'Paid', 'pending' => 'Pending', 'nopay' => 'Do not pay'];
    foreach ($PP['cur']['rows'] as $r) {
        fputcsv($out, [$r['name'], $r['title'], vs_pay_fmt_hours($r['hours']), $r['sessions'], $r['actions'], number_format($r['rate'], 2, '.', ''),
                       number_format($r['amount'], 2, '.', ''), $statusText[$r['status']], $r['status'] === 'nopay' ? implode('; ', $r['why']) : '']);
    }
    fputcsv($out, ['Total', '', vs_pay_fmt_hours($PP['cur']['tot']['hours']), '', '', '', number_format($PP['cur']['tot']['pay'], 2, '.', ''), '', '']);
    fclose($out);
    exit;
}

/* selected admin for the side panel */
$PP['selRow'] = null;
foreach ($PP['cur']['rows'] as $r) {
    if ($r['key'] === $PP['sel']) {
        $PP['selRow'] = $r;
    }
}
if (!$PP['selRow'] && $PP['cur']['rows']) {
    $PP['selRow'] = $PP['cur']['rows'][0];
}
$PP['selActivity'] = [];
$PP['selAdjust'] = [];
if ($PP['selRow']) {
    try {
        $q = $pdo->prepare('SELECT details, created_at FROM admin_activity_log WHERE admin_username = ? ORDER BY id DESC LIMIT 5');
        $q->execute([$PP['selRow']['name']]);
        $PP['selActivity'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
    }
    try {
        $q = $pdo->prepare('SELECT hours, reason, created_at FROM admin_pay_adjust WHERE month = ? AND username = ? ORDER BY id DESC LIMIT 3');
        $q->execute([$PP['month'], $PP['selRow']['key']]);
        $PP['selAdjust'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $ex) {
    }
}
$PP['log'] = [];
try {
    $PP['log'] = $pdo->query('SELECT text, created_at FROM admin_pay_log ORDER BY id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) {
}
if ($PP['demo']) {
    $PP['selActivity'] = [
        ['details' => 'Published 3 new videos to the Shorts feed', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 hours'))],
        ['details' => 'Approved 12 pending comments', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['details' => 'Updated premium plan pricing', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ['details' => 'Removed 2 reported videos', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))],
    ];
    $PP['selAdjust'] = [
        ['hours' => '2.50', 'reason' => 'Extra video editing for launch week', 'created_at' => date('Y-m-d H:i:s', strtotime('-5 days'))],
    ];
    $PP['log'] = [
        ['text' => 'Sara Malik marked as paid (' . $PP['month'] . ')', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 hours'))],
        ['text' => 'Pay rates updated', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['text' => '+2.5 hrs for Hamza Ali: Extra video editing', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ['text' => 'Ayesha Khan marked as paid (' . $PP['month'] . ')', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))],
        ['text' => 'Bilal Ahmed marked as pending (' . $PP['month'] . ')', 'created_at' => date('Y-m-d H:i:s', strtotime('-4 days'))],
    ];
}
$PP['monthLabel'] = date('F Y', strtotime($PP['month'] . '-01'));
$PP['prevMonth']  = date('Y-m', strtotime($PP['month'] . '-01 -1 month'));
$PP['nextMonth']  = date('Y-m', strtotime($PP['month'] . '-01 +1 month'));

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
require_once __DIR__ . '/page-theme.php';
vx_theme_css();

/* ---- small helpers for this page ---- */
function vs_pay_nice(float $v): float
{
    if ($v <= 0) {
        return 1.0;
    }
    $e = floor(log10($v));
    $f = $v / (10 ** $e);
    $nf = $f <= 1 ? 1 : ($f <= 2 ? 2 : ($f <= 5 ? 5 : 10));
    return $nf * (10 ** $e);
}

function vs_pay_k(float $v): string
{
    if ($v >= 1000000) {
        return rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    }
    if ($v >= 1000) {
        return rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'K';
    }
    return number_format($v, 0);
}

function vs_pay_short(float $v): string
{
    return VS_PAY_CURRENCY . ' ' . number_format($v, fmod($v, 1.0) == 0.0 ? 0 : 2);
}

function vs_pay_trend(?float $p): string
{
    if ($p === null) {
        return '<span class="text-muted">no data last month</span>';
    }
    $up = $p >= 0;
    return '<span class="' . ($up ? 'vx-trend-up' : 'vx-trend-down') . '"><i class="bi ' . ($up ? 'bi-arrow-up' : 'bi-arrow-down') . '"></i> '
        . ($up ? '+' : '-') . number_format(abs($p), 0) . '%</span> <span class="text-muted">vs. last month</span>';
}

/** Smooth curve through points (Catmull-Rom converted to bezier). */
function vs_pp_smooth(array $pts): string
{
    $n = count($pts);
    if ($n < 2) {
        return '';
    }
    $d = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min($n - 1, $i + 2)];
        $d .= ' C' . round($p1[0] + ($p2[0] - $p0[0]) / 6, 1) . ',' . round($p1[1] + ($p2[1] - $p0[1]) / 6, 1)
            . ' ' . round($p2[0] - ($p3[0] - $p1[0]) / 6, 1) . ',' . round($p2[1] - ($p3[1] - $p1[1]) / 6, 1)
            . ' ' . round($p2[0], 1) . ',' . round($p2[1], 1);
    }
    return $d;
}

/** Small trend line for a stat tile (real values if they differ, a gentle wave if they are all equal). */
function vs_pp_spark(array $vals, string $id, string $c1, string $c2): string
{
    $vals = array_values($vals);
    if (count($vals) < 2) {
        $vals = [0, 0];
    }
    $n = count($vals);
    $min = min($vals);
    $max = max($vals);
    $flat = ($max - $min) < 1e-9;
    $pts = [];
    foreach ($vals as $i => $v) {
        $t = $flat ? 0.45 + 0.22 * sin($i * 1.3 + $max) + 0.12 * $i / ($n - 1) : ($v - $min) / ($max - $min);
        $pts[] = [4 + $i * 112 / ($n - 1), 38 - (0.08 + 0.84 * max(0, min(1, $t))) * 32];
    }
    $line = vs_pp_smooth($pts);
    $last = $pts[$n - 1];
    return '<svg class="pp-spark" viewBox="0 0 120 44" preserveAspectRatio="none" aria-hidden="true"><defs>'
        . '<linearGradient id="' . $id . 's" x1="0" x2="1"><stop offset="0" stop-color="' . $c1 . '"/><stop offset="1" stop-color="' . $c2 . '"/></linearGradient>'
        . '<linearGradient id="' . $id . 'f" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $c2 . '" stop-opacity=".35"/><stop offset="1" stop-color="' . $c2 . '" stop-opacity="0"/></linearGradient></defs>'
        . '<path d="' . $line . ' L116,44 L4,44 Z" fill="url(#' . $id . 'f)"/>'
        . '<path d="' . $line . '" fill="none" stroke="url(#' . $id . 's)" stroke-width="2" stroke-linecap="round" vector-effect="non-scaling-stroke"/>'
        . '<circle cx="' . round($last[0], 1) . '" cy="' . round($last[1], 1) . '" r="2.6" fill="' . $c2 . '"/></svg>';
}

/** "↑ 12%" in green / red; empty when there is nothing to compare with. */
function vs_pp_pct(?float $p): string
{
    if ($p === null) {
        return '';
    }
    $up = $p >= 0;
    return '<span class="pp-trend ' . ($up ? 'up' : 'down') . '">' . ($up ? '&uarr;' : '&darr;') . ' ' . number_format(abs($p), 0) . '%</span>';
}

$statusMeta = [
    'paid'    => ['Paid', 'vx-pill-ok'],
    'pending' => ['Pending', 'vx-pill-purple'],
    'nopay'   => ['Do not pay', 'vx-pill-bad'],
];
$cur  = $PP['cur'];
$tot  = $cur['tot'];
$ptot = $PP['prev']['tot'];
$isCurMonth = $PP['month'] === date('Y-m');
$prevLabel  = date('M Y', strtotime($PP['prevMonth'] . '-01'));
$curShort   = date('M Y', strtotime($PP['month'] . '-01'));

/* last 6 months for the chart (this month + previous are already built) */
$chart = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($PP['month'] . '-01 -' . $i . ' month'));
    if ($i === 0) {
        $b = $cur;
    } elseif ($i === 1) {
        $b = $PP['prev'];
    } else {
        try {
            $b = vs_pay_build($pdo, $m, $PP['admins'], $PP['settings']);
        } catch (Throwable $ex) {
            $b = ['tot' => ['hours' => 0.0, 'pay' => 0.0]];
        }
    }
    $chart[] = [date('M', strtotime($m . '-01')), (float)$b['tot']['hours'], (float)$b['tot']['pay']];
}
if ($PP['demo']) {
    $dh = [232.0, 256.5, 281.0, 297.5, (float)$ptot['hours'], (float)$tot['hours']];
    $dp = [91400.0, 103250.0, 114800.0, 121600.0, (float)$ptot['pay'], (float)$tot['pay']];
    foreach ($chart as $i => $c) {
        $chart[$i][1] = $dh[$i];
        $chart[$i][2] = $dp[$i];
    }
}
$hMax = vs_pay_nice(max(array_column($chart, 1)));
$pMax = vs_pay_nice(max(array_column($chart, 2)));
$pL = 48; $pR = 590; $pT = 16; $pB = 212;
$slot = ($pR - $pL) / count($chart);

/* donut */
$adminCount = count($cur['rows']);
$donutTotal = max(1, $tot['paid'] + $tot['pending'] + $tot['nopay']);
$circ = 251.33;
$segs = [['Paid', '#19d3a2', $tot['paid']], ['Pending', '#a855f7', $tot['pending']], ['Do not pay', '#f0475a', $tot['nopay']]];

$diffPct = vs_pay_pct($tot['pay'], $ptot['pay']);
$noRates = true;
foreach ($cur['rows'] as $r) {
    if ($r['rate'] > 0) {
        $noRates = false;
    }
}
?>
<?php
$ppCrown = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M3 8l4.5 4L12 5l4.5 7L21 8l-1.6 10H4.6L3 8zm1.8 12h14.4v1.6H4.8V20z"/></svg>';
$hoursSeries = array_column($chart, 1);
$paySeries   = array_column($chart, 2);
$pctHours = vs_pay_pct($tot['hours'], $ptot['hours']);
$pctPay   = vs_pay_pct($tot['pay'], $ptot['pay']);
$pctPaid  = vs_pay_pct((float)$tot['paid'], (float)$ptot['paid']);
$ppStatus = ['paid' => ['Paid', 'paid'], 'pending' => ['Pending', 'pending'], 'nopay' => ['Do not pay', 'nopay']];

/* smooth overview chart */
$ptsH = [];
$ptsP = [];
foreach ($chart as $i => $c) {
    $cx = $pL + $slot * ($i + 0.5);
    $ptsH[] = [$cx, $pB - ($pB - $pT) * ($c[1] / $hMax)];
    $ptsP[] = [$cx, $pB - ($pB - $pT) * ($c[2] / $pMax)];
}
$dH = vs_pp_smooth($ptsH);
$dP = vs_pp_smooth($ptsP);
$xFirst = round($ptsP[0][0], 1);
$xLast = round($ptsP[count($ptsP) - 1][0], 1);
?>
<link rel="stylesheet" href="./assets/css/neon-theme.css?v=1">
<style>
  /* ===== premium look (admin-pay only) ===== */
  /* icon tiles: ".vx-stat span" in page-theme.php was shrinking/greying them; these out-rank it */
  .vx .vx-stat span.vx-stat-ico{width:58px;height:58px;border-radius:16px;color:#fff;font-size:1.65rem;line-height:1;position:relative;
    box-shadow:0 8px 18px -6px var(--vx-glow,rgba(81,70,234,.55)),inset 0 1px 0 rgba(255,255,255,.35)}
  .vx .vx-stat span.vx-stat-ico i{color:#fff;font-size:inherit;line-height:1;filter:drop-shadow(0 1px 2px rgba(0,0,0,.25))}
  .vx .vx-stat.vx-s-admins .vx-stat-ico{background:linear-gradient(145deg,#8b7dff,#4a3fe0);--vx-glow:rgba(81,70,234,.55)}
  .vx .vx-stat.vx-s-hours  .vx-stat-ico{background:linear-gradient(145deg,#4cc3ff,#1d6bf0);--vx-glow:rgba(29,107,240,.5)}
  .vx .vx-stat.vx-s-pending .vx-stat-ico{background:linear-gradient(145deg,#c77dff,#8a3fd6);--vx-glow:rgba(138,63,214,.5)}
  .vx .vx-stat.vx-s-paid   .vx-stat-ico{background:linear-gradient(145deg,#3fe0a0,#12a56b);--vx-glow:rgba(18,165,107,.5)}
  .vx .vx-stat{position:relative;overflow:hidden;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
  .vx .vx-stat::after{content:"";position:absolute;right:-30px;top:-30px;width:110px;height:110px;border-radius:50%;background:var(--vx-glow,rgba(81,70,234,.5));opacity:.07;pointer-events:none}
  .vx .vx-stat:hover{transform:translateY(-3px);border-color:var(--vx-brand2);box-shadow:0 14px 30px -12px var(--vx-glow,rgba(81,70,234,.5))}
  .vx .vx-stat b{font-size:2rem;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
  .vx .vx-stat .vx-stat-label{font-size:.78rem!important;text-transform:uppercase;letter-spacing:.06em;font-weight:700;margin-bottom:2px;color:var(--vx-muted)!important}
  /* header + cards */
  .vx>.vx-card:first-child,.vx .vx-head:first-child{position:relative}
  .vx .vx-ico{background:linear-gradient(145deg,var(--vx-brand2),var(--vx-brand));color:#fff;border:0;box-shadow:0 8px 18px -6px rgba(81,70,234,.55)}
  .vx .vx-ico i{color:#fff}
  .vx .vx-card{transition:box-shadow .18s ease,border-color .18s ease}
  .vx .vx-title{letter-spacing:-.01em}
  .vx .vx-btn{transition:transform .15s ease,filter .15s ease,box-shadow .15s ease}
  .vx .vx-btn:hover{transform:translateY(-1px)}
  /* table */
  .vx .vx-table thead th{text-transform:uppercase;font-size:.7rem;letter-spacing:.07em;font-weight:700}
  .vx .vx-table tbody tr{transition:background .15s ease}
  .vx .vx-table tbody tr:hover{background:var(--vx-sel)}
  .vx .vx-table td strong{font-variant-numeric:tabular-nums}
  .vx .pay-av{box-shadow:0 4px 10px -3px rgba(0,0,0,.3)}
  .vx .vx-pill{display:inline-flex;align-items:center;gap:6px}
  .vx .vx-pill::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
  .vx .vx-qa{transition:transform .15s ease,border-color .15s ease,background .15s ease}
  .vx .vx-qa:hover{transform:translateY(-2px)}
  .vx .vx-act-ico{box-shadow:0 4px 10px -3px rgba(0,0,0,.3)}
  .vx-trend-up{color:var(--vx-up);font-weight:700}.vx-trend-down{color:var(--vx-down);font-weight:700}
  .vx-month{display:flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid var(--vx-line);border-radius:12px;background:var(--vx-soft)}
  .vx-month a,.vx-month span.dis{color:var(--vx-ghost-fg);width:28px;height:28px;display:grid;place-items:center;border:1px solid var(--vx-ghost-bd);border-radius:8px;text-decoration:none;background:var(--vx-ghost-bg)}
  .vx-month span.dis{opacity:.4}
  .vx-month input[type=month]{background:transparent;border:0;color:var(--vx-text);color-scheme:var(--vx-scheme);width:150px;font-weight:600}
  .vx-month small{color:var(--vx-muted);display:block;line-height:1}
  .vx-chartrow{display:grid;grid-template-columns:minmax(0,1fr) 290px;gap:16px}
  .vx-legend{display:flex;gap:16px;font-size:.78rem;color:var(--vx-muted);align-items:center}
  .vx-legend i{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px}
  .pay-av{width:36px;height:36px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:.78rem;flex:none;border:2px solid rgba(255,255,255,.35)}
  .vx-role{color:var(--vx-text);font-size:.82rem}
  .vx-qgrid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .vx-qgrid .vx-qa{flex-direction:column;align-items:flex-start;position:relative;min-height:112px}
  .vx-qgrid .vx-qa>i.bi-chevron-right{position:absolute;right:10px;bottom:10px}
  .vx-qgrid .vx-qa span{flex:none}
  .vx-sum{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--vx-line-soft)}
  .vx-sum:last-child{border-bottom:0}
  .vx-dgrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px}
  .vx-mini{border:1px solid var(--vx-line);border-radius:10px;padding:10px 12px;height:100%;background:var(--vx-soft)}
  .vx-mini small{display:block;color:var(--vx-muted);font-size:.74rem}
  .vx-mini strong{font-size:.95rem;color:var(--vx-strong)}
  .vx-minis{display:grid;grid-template-columns:1fr 1fr;gap:8px}
  .vx-modal{background:var(--vx-card);background-color:var(--vx-input);color:var(--vx-text);border:1px solid var(--vx-line)}
  .vx-modal .modal-header,.vx-modal .modal-footer{border-color:var(--vx-line)}
  .vx-modal .modal-title{color:var(--vx-strong)}
  .vx-modal .btn-close{filter:var(--vx-close-filter,none)}
  .vx-modal .form-control{background-color:var(--vx-input);border:1px solid var(--vx-line);color:var(--vx-text)}
  .vx-modal .table{--bs-table-bg:transparent;--bs-table-color:var(--vx-text);--bs-table-border-color:var(--vx-line)}
  .vx-modal .text-muted{color:var(--vx-muted)!important}
  :is([data-bs-theme="dark"],[data-theme="dark"]) .vx-modal{--vx-close-filter:invert(1);background:#0a1240}
  @media (max-width:1199px){.vx-chartrow{grid-template-columns:minmax(0,1fr)}.vx-dgrid{grid-template-columns:minmax(0,1fr)}}
  @media print{
    .pay-noprint,.sidebar,.topbar,.admin-footer,header,nav{display:none!important}
    .vx,.vx-card{background:#fff!important;box-shadow:none!important}
    .vx-card *,.vx *{color:#000!important}
  }
</style>
<style>
  .pp { --pp-text:#eef0ff; --pp-muted:#9aa3d0; --pp-line:rgba(99,102,241,.4); --pp-line-soft:rgba(99,102,241,.18);
        --pp-card:linear-gradient(160deg,rgba(16,20,74,.92),rgba(8,11,46,.96)); --pp-head:rgba(26,30,96,.55); --pp-field:rgba(8,11,46,.85);
        --pp-hover:rgba(99,102,241,.12); --pp-glow:rgba(99,102,241,.38); color:var(--pp-text); }
  .pp-wrap { display:flex; flex-direction:column; gap:18px; }
  .pp .vx-card, .pp .pp-card { border-radius:16px; border:1px solid var(--pp-line); background:var(--pp-card); box-shadow:0 14px 42px rgba(3,5,24,.28), inset 0 1px rgba(255,255,255,.04); }
  .pp .vx-card { padding:20px; }
  .pp-card { padding:20px; }
  .pp-card-head { display:flex; align-items:center; gap:12px; margin:0 0 14px; }
  .pp-card-head h2 { margin:0; font-size:20px; font-weight:800; color:var(--pp-text); flex:1; }
  .pp-chip-ico { display:grid; place-items:center; width:38px; height:38px; flex:0 0 38px; border-radius:11px; color:#a78bfa; font-size:1.3rem; filter:drop-shadow(0 0 8px rgba(124,58,237,.8)); }

  /* banner */
  .pp-hero { position:relative; overflow:hidden; display:flex; flex-wrap:wrap; align-items:center; gap:18px 26px; min-height:128px; padding:18px 28px; border-radius:16px; border:1px solid rgba(124,92,255,.75);
    background:radial-gradient(520px 200px at 78% 50%,rgba(99,60,255,.45),transparent 70%),linear-gradient(105deg,#0a0838 0%,#13106a 55%,#220f86 100%);
    box-shadow:0 0 32px rgba(99,60,255,.32), inset 0 1px rgba(255,255,255,.08); color:#fff; }
  .pp-waves { position:absolute; inset:0; width:100%; height:100%; pointer-events:none; }
  .pp-hero-ico, .pp-hero-copy, .pp-hero-owner { position:relative; z-index:1; }
  .pp-hero-ico svg { display:block; width:82px; height:82px; filter:drop-shadow(0 0 16px rgba(168,85,247,.85)); }
  .pp-hero-copy { flex:1 1 280px; min-width:0; }
  .pp-hero h1 { margin:0; font-size:clamp(28px,3.4vw,42px); line-height:1.1; font-weight:800; letter-spacing:-.02em; color:#fff; }
  .pp-hero p { margin:6px 0 0; font-size:17px; color:#d8d6ff; }
  .pp-crown-art { position:absolute; right:300px; bottom:-30px; width:190px; height:170px; color:#b36bff; opacity:.9; pointer-events:none; filter:drop-shadow(0 0 22px rgba(168,85,247,.85)); }
  .pp-hero-owner { display:inline-flex; align-items:center; gap:14px; margin-left:auto; padding:12px 24px 12px 16px; border-radius:999px; border:1px solid rgba(139,92,246,.7); background:rgba(10,8,50,.6); backdrop-filter:blur(6px); }
  .pp-hero-owner svg { width:38px; height:38px; flex:0 0 38px; color:#c4b5fd; filter:drop-shadow(0 0 8px rgba(168,85,247,.8)); }
  .pp-hero-owner b { display:block; font-size:17px; color:#fff; }
  .pp-hero-owner small { display:block; max-width:210px; margin-top:1px; font-size:11px; line-height:1.3; color:#c9c6ff; }

  /* stat tiles */
  .pp-stats { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; }
  .pp-stat { position:relative; overflow:hidden; display:flex; align-items:center; gap:16px; padding:18px 20px; border-radius:14px; border:1px solid var(--pp-line); background:var(--pp-card); transition:transform .18s,border-color .18s,box-shadow .18s; }
  .pp-stat:hover { transform:translateY(-3px); border-color:rgba(167,139,250,.8); box-shadow:0 0 24px var(--pp-glow); }
  .pp-stat-ico { display:grid; place-items:center; width:58px; height:58px; flex:0 0 58px; border-radius:50%; color:#fff; font-size:1.7rem; box-shadow:0 0 22px var(--g,rgba(99,102,241,.6)), inset 0 1px rgba(255,255,255,.3); }
  .pp-stat.hours .pp-stat-ico { background:linear-gradient(145deg,#38bdf8,#1d6bf0); --g:rgba(29,107,240,.65); }
  .pp-stat.adm .pp-stat-ico { background:none; color:#a78bfa; font-size:2.3rem; box-shadow:none; filter:drop-shadow(0 0 10px rgba(124,58,237,.75)); }
  .pp-stat.earn .pp-stat-ico { background:none; color:#c084fc; font-size:2.3rem; box-shadow:none; filter:drop-shadow(0 0 10px rgba(168,85,247,.75)); }
  .pp-stat.paid .pp-stat-ico { background:linear-gradient(145deg,#8b5cf6,#5b3fe8); --g:rgba(124,58,237,.65); }
  .pp-stat-text { position:relative; z-index:1; min-width:0; }
  .pp-stat-text small { display:block; font-size:15px; font-weight:600; color:var(--pp-text); }
  .pp-stat-text b { display:inline-block; margin-top:2px; font-size:30px; line-height:1.15; font-weight:800; font-variant-numeric:tabular-nums; color:var(--pp-text); }
  .pp-stat-text b em { font-style:normal; font-size:.55em; font-weight:700; margin-left:2px; }
  .pp-stat-text b.sm { font-size:24px; }
  .pp-trend { margin-left:8px; font-size:14px; font-weight:700; }
  .pp-trend.up { color:#22e58b; } .pp-trend.down { color:#ff6b86; }
  .pp-sub { display:block; margin-top:2px; font-size:12.5px; color:var(--pp-muted); }
  .pp-spark { position:absolute; right:12px; bottom:10px; width:42%; height:50px; pointer-events:none; }

  /* chart + quick actions */
  .pp-top { display:grid; grid-template-columns:minmax(0,1.9fr) minmax(0,1fr); gap:18px; }
  .pp-month { display:inline-flex; align-items:center; gap:6px; padding:5px 8px 5px 14px; border-radius:12px; border:1px solid var(--pp-line); background:var(--pp-field); }
  .pp-month input[type=month] { background:transparent; border:0; outline:0; color:var(--pp-text); color-scheme:dark; width:150px; font-weight:600; font-size:15px; }
  .pp-month a, .pp-month span.dis { display:grid; place-items:center; width:28px; height:28px; border-radius:8px; border:1px solid var(--pp-line); color:var(--pp-text); text-decoration:none; background:rgba(99,102,241,.12); }
  .pp-month span.dis { opacity:.35; }
  .pp-legend { display:flex; gap:18px; margin:0 0 4px 4px; font-size:14px; font-weight:600; color:var(--pp-text); }
  .pp-legend i { display:inline-block; width:12px; height:12px; margin-right:7px; border-radius:50%; vertical-align:-1px; }
  .pp-axis { font-size:11px; fill:var(--pp-muted); }
  .pp-axis.r { fill:#6cb4ff; }
  .pp-grid-line { stroke:rgba(99,102,241,.22); }
  .pp-qa-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
  .pp-qa { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; min-height:132px; padding:14px 10px; border-radius:14px; border:1px solid rgba(255,255,255,.18); color:#fff; text-align:center; text-decoration:none; cursor:pointer; font:inherit; transition:transform .18s, box-shadow .18s, filter .18s; }
  .pp-qa:hover { transform:translateY(-3px); filter:brightness(1.1); color:#fff; }
  .pp-qa i { font-size:2rem; line-height:1; filter:drop-shadow(0 2px 6px rgba(0,0,0,.35)); }
  .pp-qa b { font-size:15px; font-weight:800; }
  .pp-qa small { font-size:11.5px; line-height:1.3; opacity:.9; }
  .pp-qa.rates { background:linear-gradient(145deg,#8b5cf6,#5b21d6); box-shadow:0 0 22px rgba(124,58,237,.5); }
  .pp-qa.months { background:linear-gradient(145deg,#3b82f6,#1d4fd8); box-shadow:0 0 22px rgba(37,99,235,.5); }
  .pp-qa.logs { background:linear-gradient(145deg,#10b981,#047857); box-shadow:0 0 22px rgba(16,185,129,.45); }
  .pp-more { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
  .pp-more a, .pp-more button { display:inline-flex; align-items:center; gap:7px; height:36px; padding:0 14px; border-radius:999px; border:1px solid var(--pp-line); background:rgba(99,102,241,.1); color:var(--pp-text); font:inherit; font-size:13px; font-weight:600; text-decoration:none; cursor:pointer; transition:border-color .15s, box-shadow .15s; }
  .pp-more a:hover, .pp-more button:hover { border-color:#a78bfa; box-shadow:0 0 14px rgba(124,58,237,.45); color:var(--pp-text); }

  /* table */
  .pp-listhead { display:flex; flex-wrap:wrap; align-items:center; gap:12px; padding:16px 20px; }
  .pp-find { position:relative; }
  .pp-find i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:#a78bfa; pointer-events:none; }
  .pp-field { height:42px; border-radius:12px; border:1px solid var(--pp-line); background:var(--pp-field); color:var(--pp-text); font-size:15px; outline:0; }
  .pp-field:focus { border-color:#a78bfa; box-shadow:0 0 0 1px #a78bfa, 0 0 16px rgba(124,58,237,.45); }
  .pp-find .pp-field { width:230px; padding:0 14px 0 40px; }
  select.pp-field { width:150px; padding:0 34px 0 14px; appearance:none; -webkit-appearance:none; cursor:pointer;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%23a78bfa' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; }
  select.pp-field option { background:#0c0f2f; color:#eef0ff; }
  .pp-note { margin:0; padding:0 20px 12px; font-size:12.5px; color:var(--pp-muted); }
  .pp-scroll { overflow-x:auto; }
  .pp-table { width:100%; min-width:900px; border-collapse:collapse; }
  .pp-table th { padding:15px 14px; background:var(--pp-head); border-top:1px solid var(--pp-line); border-bottom:1px solid var(--pp-line); color:var(--pp-text); font-size:14.5px; font-weight:700; text-align:left; white-space:nowrap; }
  .pp-table td { padding:10px 14px; border-bottom:1px solid var(--pp-line-soft); color:var(--pp-text); font-size:15px; vertical-align:middle; }
  .pp-table tbody tr { transition:background .15s; }
  .pp-table tbody tr:hover { background:var(--pp-hover); }
  .pp-table tbody tr.sel { background:rgba(99,102,241,.16); }
  .pp-table .nw { white-space:nowrap; }
  .pp-table .r { text-align:right; }
  .pp-table .mono { display:block; margin-top:2px; font-size:11.5px; color:var(--pp-muted); white-space:normal; max-width:190px; }
  .pp-table .why { display:block; margin-top:4px; font-size:11.5px; color:var(--pp-muted); white-space:normal; max-width:190px; }
  .pp-av { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:50%; color:#fff; font-weight:800; font-size:.85rem; border:2px solid rgba(255,255,255,.35); box-shadow:0 0 12px rgba(0,0,0,.35); }
  .pp-title { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-size:13px; font-weight:700; border:1px solid transparent; }
  .pp-title svg { width:14px; height:14px; }
  .pp-title.ceo { background:rgba(245,158,11,.16); border-color:rgba(245,158,11,.6); color:#fcd34d; }
  .pp-title.adm { background:rgba(124,58,237,.28); border-color:rgba(139,92,246,.6); color:#e6d6ff; }
  .pp-pill { display:inline-flex; align-items:center; gap:8px; padding:4px 14px 4px 10px; border-radius:999px; font-size:13px; font-weight:700; border:1px solid transparent; }
  .pp-pill::before { content:""; width:11px; height:11px; border-radius:50%; background:currentColor; box-shadow:0 0 8px currentColor; }
  .pp-pill.paid { background:rgba(34,229,139,.14); border-color:rgba(34,229,139,.5); color:#2ee59d; }
  .pp-pill.pending { background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.55); color:#fbbf24; }
  .pp-pill.nopay { background:rgba(255,90,122,.14); border-color:rgba(255,90,122,.5); color:#ff7a95; }
  .pp-acts { display:flex; justify-content:flex-end; gap:8px; }
  .pp-acts form { margin:0; }
  .pp-ib { display:grid; place-items:center; width:36px; height:36px; border:0; border-radius:10px; color:#fff; font-size:1rem; text-decoration:none; cursor:pointer; transition:transform .15s, filter .15s; }
  .pp-ib:hover { transform:translateY(-2px); filter:brightness(1.15); color:#fff; }
  .pp-ib.view { background:linear-gradient(135deg,#0ea5e9,#2563eb); box-shadow:0 0 12px rgba(14,165,233,.45); }
  .pp-ib.edit { background:linear-gradient(135deg,#7c3aed,#5b3fe8); box-shadow:0 0 12px rgba(124,58,237,.5); }
  .pp-ib.ok { background:linear-gradient(135deg,#10b981,#047857); box-shadow:0 0 12px rgba(16,185,129,.45); }
  .pp-ib.wait { background:linear-gradient(135deg,#f59e0b,#d97706); box-shadow:0 0 12px rgba(245,158,11,.45); }
  .pp-ib.bad { background:linear-gradient(135deg,#ef2b3d,#d11a2a); box-shadow:0 0 12px rgba(239,43,61,.45); }
  .pp-empty { padding:36px 14px !important; text-align:center; color:var(--pp-muted) !important; }
  .pp-foot { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; padding:14px 20px; }
  .pp-foot span { font-size:14.5px; }
  .pp-pager { display:inline-flex; gap:6px; }
  .pp-pager button { display:grid; place-items:center; min-width:36px; height:36px; padding:0 8px; border:1px solid var(--pp-line); border-radius:8px; background:rgba(99,102,241,.1); color:var(--pp-text); font:inherit; font-weight:700; cursor:pointer; }
  .pp-pager button:hover:not(:disabled) { background:rgba(99,102,241,.3); }
  .pp-pager button:disabled { opacity:.35; cursor:default; }
  .pp-pager button.on { border-color:transparent; background:linear-gradient(135deg,#c026d3,#7c3aed); color:#fff; box-shadow:0 0 14px rgba(192,38,211,.55); }

  .pp-grid3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:18px; align-items:start; }
  .pp .vx-card-title { color:var(--pp-text); }

  @media (max-width:1280px) { .pp-top { grid-template-columns:minmax(0,1fr); } .pp-grid3 { grid-template-columns:minmax(0,1fr) minmax(0,1fr); } .pp-crown-art { display:none; } }
  @media (max-width:1100px) { .pp-stats { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:760px) {
    .pp-stats, .pp-grid3 { grid-template-columns:minmax(0,1fr); } .pp-hero { padding:16px; } .pp-hero-ico svg { width:54px; height:54px; } .pp-hero-owner { margin-left:0; }
    .pp-qa-grid { grid-template-columns:minmax(0,1fr); } .pp-find .pp-field { width:100%; } .pp-find { flex:1 1 100%; }
  }
  @media print {
    .pay-noprint,.sidebar,.topbar,.admin-footer,header,nav{display:none!important}
    .pp,.pp .vx-card,.pp-card{background:#fff!important;box-shadow:none!important}
    .pp *{color:#000!important}
  }

  /* light theme */
  html[data-theme="light"] .pp, html[data-bs-theme="light"] .pp {
    --pp-text:#1d1544; --pp-muted:#5f6690; --pp-line:rgba(99,102,241,.35); --pp-line-soft:rgba(99,102,241,.14);
    --pp-card:linear-gradient(160deg,#ffffff,#f6f5ff); --pp-head:rgba(99,102,241,.08); --pp-field:#ffffff; --pp-hover:rgba(99,102,241,.07); --pp-glow:rgba(99,102,241,.28);
  }
  html[data-theme="light"] .pp-month input[type=month], html[data-bs-theme="light"] .pp-month input[type=month] { color-scheme:light; }
  html[data-theme="light"] select.pp-field option { background:#fff; color:#1d1544; }
  html[data-theme="light"] .pp-title.adm { color:#5b21b6; } html[data-theme="light"] .pp-title.ceo { color:#a16207; }
  html[data-theme="light"] .pp-pill.paid { color:#0a8a52; } html[data-theme="light"] .pp-pill.pending { color:#b45309; } html[data-theme="light"] .pp-pill.nopay { color:#b4233f; }
  html[data-theme="light"] .pp-trend.up { color:#0a8a52; } html[data-theme="light"] .pp-axis.r { fill:#2563eb; }
</style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="vx pp">
          <div class="pp-wrap">

            <!-- Banner -->
            <section class="pp-hero">
              <svg class="pp-waves" viewBox="0 0 1400 130" preserveAspectRatio="none" aria-hidden="true">
                <defs><linearGradient id="ppw" x1="0" x2="1"><stop offset="0" stop-color="#4f46e5" stop-opacity="0"/><stop offset=".6" stop-color="#8b5cf6"/><stop offset="1" stop-color="#e879f9"/></linearGradient></defs>
                <path d="M0 110 C260 60 520 135 800 90 S1150 25 1400 95" fill="none" stroke="url(#ppw)" stroke-width="1.6" opacity=".75"/>
                <path d="M0 85 C240 118 560 15 860 66 S1200 108 1400 36" fill="none" stroke="url(#ppw)" stroke-width="1" opacity=".5"/>
              </svg>
              <div class="pp-hero-ico" aria-hidden="true">
                <svg viewBox="0 0 84 84" fill="none">
                  <defs><linearGradient id="ppHx" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c084fc"/><stop offset="1" stop-color="#6d28d9"/></linearGradient></defs>
                  <path d="M42 4 76 26v32L42 80 8 58V26L42 4z" fill="url(#ppHx)" stroke="#e9d5ff" stroke-width="2.5" stroke-linejoin="round"/>
                  <path d="M22 32l9 8 11-14 11 14 9-8-3 22H25l-3-22zm3 26h34v5H25z" fill="#fff"/>
                </svg>
              </div>
              <div class="pp-hero-copy">
                <h1>Admin Payroll</h1>
                <p>Manage admin salaries, work hours and payments.</p>
              </div>
              <div class="pp-crown-art" aria-hidden="true"><?= $ppCrown ?></div>
              <div class="pp-hero-owner">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 2.5 20 5.5v6c0 5-3.4 8.6-8 10-4.6-1.4-8-5-8-10v-6l8-3z"/><path d="m8.5 12 2.5 2.5 4.5-5" stroke-linecap="round"/></svg>
                <div><b>Owner Only</b><small>Only the site owner can access this page.</small></div>
              </div>
            </section>

            <?php if ($PP['flash']): ?>
              <div class="vx-alert vx-alert-<?php echo $PP['flash']['ok'] ? 'success' : 'danger'; ?>" role="status"><span><?php echo vs_pay_h($PP['flash']['msg']); ?></span></div>
            <?php endif; ?>
            <?php if ($cur['rows'] && $noRates): ?>
              <div class="vx-alert vx-alert-warning"><span>No pay rate is set yet, so every amount is <?php echo vs_pay_h(vs_pay_money(0)); ?>. Press <strong>Update Rates</strong> to set each admin's rate per hour.</span></div>
            <?php endif; ?>

            <?php if (!empty($PP['demo'])): ?>
              <div class="vx-alert vx-alert-warning pay-noprint"><span><i class="bi bi-eye"></i> <strong>Sample data preview.</strong> These admins, hours and amounts are made up so you can see the finished design. Nothing is saved.</span><a href="admin-pay.php?month=<?php echo vs_pay_h($PP['month']); ?>">Back to real data</a></div>
            <?php endif; ?>

            <!-- Numbers -->
            <section class="pp-stats" aria-label="Summary">
              <article class="pp-stat hours">
                <span class="pp-stat-ico"><i class="bi bi-clock-fill" aria-hidden="true"></i></span>
                <div class="pp-stat-text"><small>Total Hours</small><b><?php echo vs_pay_h(vs_pay_fmt_hours($tot['hours'])); ?><em>h</em></b><?php echo vs_pp_pct($pctHours); ?></div>
                <?php echo vs_pp_spark($hoursSeries, 'ppa1', '#38bdf8', '#3b82f6'); ?>
              </article>
              <article class="pp-stat adm">
                <span class="pp-stat-ico"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
                <div class="pp-stat-text"><small>Total Admins</small><b><?php echo (int)$adminCount; ?></b><span class="pp-trend up"><?php echo (int)$tot['active']; ?> active</span></div>
                <?php echo vs_pp_spark(array_fill(0, 6, (float)$adminCount), 'ppa2', '#14b8a6', '#2ee59d'); ?>
              </article>
              <article class="pp-stat earn">
                <span class="pp-stat-ico"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                <div class="pp-stat-text"><small>Total Earnings</small><b class="sm"><?php echo vs_pay_h(vs_pay_short($tot['pay'])); ?></b><?php echo vs_pp_pct($pctPay); ?></div>
                <?php echo vs_pp_spark($paySeries, 'ppa3', '#7c3aed', '#c084fc'); ?>
              </article>
              <article class="pp-stat paid">
                <span class="pp-stat-ico"><i class="bi bi-check-lg" aria-hidden="true"></i></span>
                <div class="pp-stat-text"><small>Paid This Month</small><b><?php echo (int)$tot['paid']; ?></b><?php echo vs_pp_pct($pctPaid); ?></div>
                <?php echo vs_pp_spark([(float)$ptot['paid'], (float)$tot['paid'], (float)$tot['paid'], (float)$tot['paid']], 'ppa4', '#3b82f6', '#60a5fa'); ?>
              </article>
            </section>

            <!-- Chart + quick actions -->
            <div class="pp-top">
              <section class="pp-card" aria-label="Monthly payroll overview">
                <div class="pp-card-head">
                  <span class="pp-chip-ico"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i></span>
                  <h2>Monthly Overview</h2>
                  <div class="pp-month pay-noprint">
                    <input type="month" value="<?php echo vs_pay_h($PP['month']); ?>" max="<?php echo date('Y-m'); ?>" aria-label="Month"
                           onchange="if(this.value){location.href='admin-pay.php?month='+this.value}">
                    <a href="admin-pay.php?month=<?php echo vs_pay_h($PP['prevMonth']); ?><?php echo !empty($PP['demo']) ? '&amp;demo=1' : ''; ?>" aria-label="Previous month"><i class="bi bi-chevron-left"></i></a>
                    <?php if (!$isCurMonth): ?>
                      <a href="admin-pay.php?month=<?php echo vs_pay_h($PP['nextMonth']); ?><?php echo !empty($PP['demo']) ? '&amp;demo=1' : ''; ?>" aria-label="Next month"><i class="bi bi-chevron-right"></i></a>
                    <?php else: ?>
                      <span class="dis"><i class="bi bi-chevron-right"></i></span>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="pp-legend"><span><i style="background:#b04dff;box-shadow:0 0 8px #b04dff"></i>Earnings</span><span><i style="background:#2f9bff;box-shadow:0 0 8px #2f9bff"></i>Hours</span></div>
                <svg viewBox="0 0 690 250" width="100%" role="img" aria-label="Earnings and hours for the last 6 months" style="display:block">
                  <defs>
                    <linearGradient id="ppEarF" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#b04dff" stop-opacity=".38"/><stop offset="1" stop-color="#b04dff" stop-opacity="0"/></linearGradient>
                    <linearGradient id="ppHrsF" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#2f9bff" stop-opacity=".34"/><stop offset="1" stop-color="#2f9bff" stop-opacity="0"/></linearGradient>
                    <filter id="ppGlow" x="-10%" y="-30%" width="120%" height="160%"><feGaussianBlur stdDeviation="3" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
                  </defs>
                  <?php for ($k = 0; $k <= 4; $k++): $gy = $pB - ($pB - $pT) * $k / 4; $hv = $hMax * $k / 4; $pv = $pMax * $k / 4; ?>
                    <line x1="<?php echo $pL; ?>" x2="<?php echo $pR; ?>" y1="<?php echo round($gy, 1); ?>" y2="<?php echo round($gy, 1); ?>" class="pp-grid-line" stroke-width="1"/>
                    <text x="<?php echo $pL - 8; ?>" y="<?php echo round($gy + 4, 1); ?>" text-anchor="end" class="pp-axis"><?php echo vs_pay_h(vs_pay_k($pv)); ?></text>
                    <text x="<?php echo $pR + 8; ?>" y="<?php echo round($gy + 4, 1); ?>" class="pp-axis r"><?php echo vs_pay_h(number_format($hv, fmod($hv, 1.0) == 0.0 ? 0 : 1)); ?>h</text>
                  <?php endfor; ?>
                  <path d="<?php echo $dH; ?> L<?php echo $xLast; ?>,<?php echo $pB; ?> L<?php echo $xFirst; ?>,<?php echo $pB; ?> Z" fill="url(#ppHrsF)"/>
                  <path d="<?php echo $dP; ?> L<?php echo $xLast; ?>,<?php echo $pB; ?> L<?php echo $xFirst; ?>,<?php echo $pB; ?> Z" fill="url(#ppEarF)"/>
                  <path d="<?php echo $dH; ?>" fill="none" stroke="#2f9bff" stroke-width="3" stroke-linecap="round" filter="url(#ppGlow)"/>
                  <path d="<?php echo $dP; ?>" fill="none" stroke="#b04dff" stroke-width="3" stroke-linecap="round" filter="url(#ppGlow)"/>
                  <?php foreach ($chart as $i => $c): ?>
                    <circle cx="<?php echo round($ptsH[$i][0], 1); ?>" cy="<?php echo round($ptsH[$i][1], 1); ?>" r="4" fill="#fff" stroke="#2f9bff" stroke-width="2"><title><?php echo vs_pay_h($c[0] . ': ' . vs_pay_fmt_hours($c[1]) . ' hrs'); ?></title></circle>
                    <circle cx="<?php echo round($ptsP[$i][0], 1); ?>" cy="<?php echo round($ptsP[$i][1], 1); ?>" r="4" fill="#fff" stroke="#b04dff" stroke-width="2"><title><?php echo vs_pay_h($c[0] . ': ' . vs_pay_short($c[2])); ?></title></circle>
                    <text x="<?php echo round($ptsP[$i][0], 1); ?>" y="<?php echo $pB + 20; ?>" text-anchor="middle" class="pp-axis"><?php echo vs_pay_h($c[0]); ?></text>
                  <?php endforeach; ?>
                </svg>
              </section>

              <section class="pp-card pay-noprint" aria-label="Quick actions">
                <div class="pp-card-head">
                  <span class="pp-chip-ico" style="color:#c084fc"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
                  <h2>Quick Actions</h2>
                </div>
                <div class="pp-qa-grid">
                  <button type="button" class="pp-qa rates" data-bs-toggle="modal" data-bs-target="#payRates"><i class="bi bi-gear-fill" aria-hidden="true"></i><b>Update Rates</b><small>Set admin pay rates</small></button>
                  <a class="pp-qa months" href="#payTable"><i class="bi bi-calendar3" aria-hidden="true"></i><b>Manage Months</b><small>Mark as paid / pending</small></a>
                  <a class="pp-qa logs" href="#payLog"><i class="bi bi-file-earmark-text-fill" aria-hidden="true"></i><b>View Logs</b><small>Check payroll logs</small></a>
                </div>
                <div class="pp-more">
                  <a href="<?php echo $PP['selRow'] ? '#payAdjust' : '#payTable'; ?>"><i class="bi bi-plus-slash-minus" aria-hidden="true"></i> Adjust Hours</a>
                  <button type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print report</button>
                  <a href="admin-pay.php?month=<?php echo vs_pay_h($PP['month']); ?>&amp;export=csv"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
                  <?php if (empty($PP['demo'])): ?><a href="admin-pay.php?month=<?php echo vs_pay_h($PP['month']); ?>&amp;demo=1"><i class="bi bi-eye" aria-hidden="true"></i> Preview sample data</a><?php endif; ?>
                </div>
              </section>
            </div>

            <!-- Admin payroll list -->
            <section class="pp-card" style="padding:0;overflow:hidden" aria-label="Admin payroll list">
              <div class="pp-listhead">
                <span class="pp-chip-ico"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
                <h2 style="margin:0;font-size:20px;font-weight:800;flex:1;min-width:160px">Admin Payroll List</h2>
                <label class="pp-find pay-noprint"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="payFind" class="pp-field" placeholder="Search admins..." aria-label="Search admins" autocomplete="off"></label>
                <select id="payFilter" class="pp-field pay-noprint" aria-label="Filter by status">
                  <option value="">All Status</option><option value="paid">Paid</option><option value="pending">Pending</option><option value="nopay">Do not pay</option>
                </select>
              </div>
              <p class="pp-note">An admin is paid only with at least <?php echo (int)VS_PAY_MIN_HOURS; ?> hrs and <?php echo (int)VS_PAY_MIN_ACTIONS; ?> actions in the month.</p>
              <div class="pp-scroll">
                <table class="pp-table" id="payTable">
                  <thead><tr>
                    <th>#</th><th>Avatar</th><th>Username</th><th>Title</th><th>Hours</th><th>Actions</th><th>Rate</th><th>Status</th><th>Total Pay</th><th class="r pay-noprint" aria-label="Manage"></th>
                  </tr></thead>
                  <tbody>
                  <?php if (!$cur['rows']): ?>
                    <tr><td colspan="10" class="pp-empty">No admin accounts found.</td></tr>
                  <?php else: foreach ($cur['rows'] as $i => $r):
                      $meta = $ppStatus[$r['status']];
                      $isSel = $PP['selRow'] && $PP['selRow']['key'] === $r['key'];
                      $ttl = $r['title'] !== '' ? $r['title'] : 'Admin';
                      $ttlCeo = stripos($ttl, 'ceo') !== false;
                      $acts = (int)($r['actions'] ?? 0);
                  ?>
                    <tr data-row data-name="<?php echo vs_pay_h(mb_strtolower($r['name'])); ?>" data-status="<?php echo vs_pay_h($r['status']); ?>"<?php echo $isSel ? ' class="sel"' : ''; ?>>
                      <td><strong><?php echo $i + 1; ?></strong></td>
                      <td><span class="pp-av" style="background:<?php echo vs_pay_color($r['name']); ?>"><?php echo vs_pay_h(vs_pay_initials($r['name'])); ?></span></td>
                      <td class="nw"><strong><?php echo vs_pay_h($r['name']); ?></strong></td>
                      <td><span class="pp-title <?php echo $ttlCeo ? 'ceo' : 'adm'; ?>"><?php echo $ttlCeo ? $ppCrown : '<i class="bi bi-gem" aria-hidden="true"></i>'; ?><?php echo vs_pay_h($ttl); ?></span></td>
                      <td class="nw"><?php echo vs_pay_h(vs_pay_fmt_hours($r['hours'])); ?> h<?php if ($r['adj'] != 0): ?><span class="mono"><?php echo ($r['adj'] > 0 ? '+' : '') . vs_pay_h(vs_pay_fmt_hours($r['adj'])); ?> adjusted</span><?php endif; ?></td>
                      <td><?php echo (int)$acts; ?></td>
                      <td class="nw"><?php echo vs_pay_h(VS_PAY_CURRENCY . ' ' . number_format($r['rate'], fmod($r['rate'], 1.0) == 0.0 ? 0 : 2)); ?></td>
                      <td>
                        <span class="pp-pill <?php echo $meta[1]; ?>"><?php echo $meta[0]; ?></span>
                        <?php if ($r['status'] === 'nopay'): ?><span class="why"><?php echo vs_pay_h(implode('; ', $r['why'])); ?></span><?php endif; ?>
                      </td>
                      <td class="nw"><strong><?php echo vs_pay_h(vs_pay_short($r['amount'])); ?></strong><?php if (!empty($r['bonus'])): ?><span class="mono">incl. <?php echo vs_pay_h(vs_pay_short($r['bonus'])); ?> task bonus</span><?php endif; ?></td>
                      <td class="r pay-noprint">
                        <div class="pp-acts">
                          <a class="pp-ib view" title="View details" aria-label="View details" href="admin-pay.php?month=<?php echo vs_pay_h($PP['month']); ?>&amp;admin=<?php echo urlencode($r['key']); ?><?php echo !empty($PP['demo']) ? '&amp;demo=1' : ''; ?>#payDetail"><i class="bi bi-eye"></i></a>
                          <button type="button" class="pp-ib edit" title="Edit rate and work time" aria-label="Edit rate and work time" data-bs-toggle="modal" data-bs-target="#payRates"><i class="bi bi-pencil-fill"></i></button>
                          <?php foreach (['paid' => ['Mark as Paid', 'bi-check2', 'ok'], 'pending' => ['Mark as Pending', 'bi-clock', 'wait'], 'nopay' => ['Do not pay', 'bi-slash-circle', 'bad']] as $act => $b): if ($r['status'] === $act) { continue; } if ($act === 'paid' && empty($r['eligible'])) { continue; } $actKey = ($act === 'pending' && empty($r['eligible'])) ? 'allow' : $act; if ($actKey === 'allow') { $b = ['Pay anyway (ignore the hours/actions minimum)', 'bi-unlock', 'wait']; } ?>
                            <form method="post" action="admin-pay.php">
                              <input type="hidden" name="csrf" value="<?php echo vs_pay_h($PP['csrf']); ?>"><input type="hidden" name="do" value="<?php echo $actKey; ?>"><input type="hidden" name="reason" value="">
                              <input type="hidden" name="month" value="<?php echo vs_pay_h($PP['month']); ?>"><input type="hidden" name="admin" value="<?php echo vs_pay_h($r['key']); ?>">
                              <button class="pp-ib <?php echo $b[2]; ?>" type="submit" onclick="return vsPayAsk(this)" title="<?php echo $b[0]; ?>" aria-label="<?php echo $b[0]; ?>"><i class="bi <?php echo $b[1]; ?>"></i></button>
                            </form>
                          <?php endforeach; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                    <tr id="payNone" hidden><td colspan="10" class="pp-empty">No admins match your search.</td></tr>
                  </tbody>
                </table>
              </div>
              <div class="pp-foot">
                <span id="payCount">Showing 1 to <?php echo (int)min(8, $adminCount); ?> of <?php echo (int)$adminCount; ?> admins</span>
                <div class="pp-pager pay-noprint" id="payPager" aria-label="Pages"></div>
              </div>
              <div style="padding:0 20px 16px">
<?php if ($cur['unmatched']): ?>
                    <div class="vx-alert vx-alert-warning small" style="margin-top:10px">
                      <span>Time was also recorded under names not in your admin list:
                      <?php $bits = []; foreach ($cur['unmatched'] as $k => $h) { $bits[] = vs_pay_h($k) . ' (' . vs_pay_h(vs_pay_fmt_hours($h)) . ' hrs)'; } echo implode(', ', $bits); ?>.
                      Usually a mistyped or old username. That time is not counted for anyone.</span></div>
                  <?php endif; ?>
              </div>
            </section>

            <!-- Status, activity, summary -->
            <div class="pp-grid3">
                  <section class="vx-card" aria-label="Payment status">
                    <h2 class="vx-card-title"><span class="vx-fileico" style="background:#16a34a;border-color:#16a34a;color:#fff"><i class="bi bi-check2" aria-hidden="true"></i></span> Payment Status</h2>
                    <div style="display:grid;place-items:center;margin:4px 0 10px">
                      <svg viewBox="0 0 100 100" width="170" height="170" role="img" aria-label="Paid, pending and not-to-pay admins">
                        <circle cx="50" cy="50" r="40" fill="none" class="vx-svg-track" stroke-width="12"/>
                        <?php $off = 0; foreach ($segs as $sg): if ($sg[2] <= 0) { continue; } $len = $circ * $sg[2] / $donutTotal; ?>
                          <circle cx="50" cy="50" r="40" fill="none" stroke="<?php echo $sg[1]; ?>" stroke-width="12" stroke-linecap="butt" stroke-dasharray="<?php echo round(max(0, $len - 1.5), 2); ?> <?php echo round($circ - max(0, $len - 1.5), 2); ?>" stroke-dashoffset="<?php echo round(-$off, 2); ?>" transform="rotate(-90 50 50)"/>
                        <?php $off += $len; endforeach; ?>
                        <text x="50" y="52" text-anchor="middle" font-size="20" font-weight="800" class="vx-svg-center"><?php echo (int)$adminCount; ?></text>
                        <text x="50" y="64" text-anchor="middle" font-size="7.5" class="vx-svg-lbl">Admins</text>
                      </svg>
                    </div>
                    <?php foreach ($segs as $sg): if ($sg[0] === 'Do not pay' && $sg[2] <= 0) { continue; } ?>
                      <div class="vx-sum" style="padding:6px 0;border:0">
                        <span><span style="color:<?php echo $sg[1]; ?>">●</span> <?php echo vs_pay_h($sg[0]); ?></span>
                        <span><strong><?php echo (int)$sg[2]; ?></strong> &nbsp; <span class="text-muted"><?php echo (int)round($sg[2] / $donutTotal * 100); ?>%</span></span>
                      </div>
                    <?php endforeach; ?>
                  </section>
                <section class="vx-card" id="payLog" aria-label="Recent payroll activity">
                  <h2 class="vx-card-title"><span class="vx-fileico"><i class="bi bi-journal-text"></i></span> Recent Payroll Activity</h2>
                  <?php if (!$PP['log']): ?>
                    <div class="text-muted small">Nothing yet. Marking someone as paid or adjusting hours shows up here.</div>
                  <?php else: foreach ($PP['log'] as $l): $good = stripos((string)$l['text'], 'as paid') !== false; ?>
                    <div class="vx-act">
                      <span class="vx-act-ico <?php echo $good ? 'vx-act-ok' : ''; ?>" style="<?php echo $good ? '' : 'background:#4b3fe0'; ?>"><i class="bi bi-cash-stack"></i></span>
                      <div class="vx-act-body"><b style="font-weight:500"><?php echo vs_pay_h($l['text']); ?></b><small><?php echo vs_pay_h(date('M j, Y · g:i A', strtotime($l['created_at']))); ?></small></div>
                      <span class="vx-pill <?php echo $good ? 'vx-pill-ok' : 'vx-pill-info'; ?>" style="min-width:0"><?php echo $good ? 'Success' : 'Info'; ?></span>
                    </div>
                  <?php endforeach; endif; ?>
                </section>
                <section class="vx-card" aria-label="Payroll summary">
                  <div class="vx-head" style="margin-bottom:6px">
                    <h2 class="vx-card-title" style="margin:0;flex:1"><span class="vx-fileico"><i class="bi bi-calendar3"></i></span> Payroll Summary</h2>
                    <a href="#payTable" class="small">View Details</a>
                  </div>
                  <div class="vx-sum"><span>This Month (<?php echo vs_pay_h($curShort); ?>)</span><strong><?php echo vs_pay_h(vs_pay_short($tot['pay'])); ?></strong></div>
                  <div class="vx-sum"><span>Last Month (<?php echo vs_pay_h($prevLabel); ?>)</span><strong><?php echo vs_pay_h(vs_pay_short($ptot['pay'])); ?></strong></div>
                  <div class="vx-sum"><span style="color:var(--vx-ok-t)">Difference</span>
                    <strong><?php if ($diffPct === null): ?><span class="text-muted">&mdash;</span><?php else: ?><span class="<?php echo $diffPct >= 0 ? 'vx-trend-up' : 'vx-trend-down'; ?>"><i class="bi <?php echo $diffPct >= 0 ? 'bi-arrow-up' : 'bi-arrow-down'; ?>"></i> <?php echo ($diffPct >= 0 ? '+' : '-') . number_format(abs($diffPct), 1); ?>%</span><?php endif; ?></strong></div>
                </section>
            </div>

            <!-- Selected admin -->
                <!-- Selected admin -->
                <?php $s = $PP['selRow']; if ($s): $meta = $statusMeta[$s['status']]; ?>
                <section class="vx-card" id="payDetail" aria-label="Selected admin">
                  <div class="vx-dgrid">
                    <div>
                      <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
                        <span class="pay-av" style="width:52px;height:52px;font-size:1rem;background:<?php echo vs_pay_color($s['name']); ?>"><?php echo vs_pay_h(vs_pay_initials($s['name'])); ?></span>
                        <div style="flex:1"><strong><?php echo vs_pay_h($s['name']); ?></strong><div class="text-muted small"><?php echo vs_pay_h($s['title'] !== '' ? $s['title'] : 'Admin'); ?></div></div>
                        <span class="vx-pill <?php echo $s['hours'] > 0 ? 'vx-pill-ok' : 'vx-pill-info'; ?>"><?php echo $s['hours'] > 0 ? 'Active' : 'No time'; ?></span>
                      </div>
                      <div class="small" style="font-weight:600;margin-bottom:8px">This Month (<?php echo vs_pay_h($PP['monthLabel']); ?>)</div>
                      <div class="vx-minis">
                        <div class="vx-mini"><small>Total Hours</small><strong><?php echo vs_pay_h(vs_pay_fmt_hours($s['hours'])); ?> hrs</strong></div>
                        <div class="vx-mini"><small>Work Sessions</small><strong><?php echo (int)$s['sessions']; ?></strong></div>
                        <div class="vx-mini" style="grid-column:1/-1"><small>Tasks / Activities</small>
                          <strong class="small"><?php echo $s['tasks'] ? vs_pay_h(implode(', ', array_map(static fn($t) => $t[0] . ' ' . $t[1], array_slice($s['tasks'], 0, 4)))) : 'No actions'; ?></strong>
                          <small><?php echo (int)$s['pages']; ?> pages opened</small></div>
                        <div class="vx-mini" style="grid-column:1/-1"><small>Work time</small>
                          <?php if ($s['expected'] > 0): $pct = min(100, round($s['hours'] / $s['expected'] * 100)); $dn2 = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
                            <strong class="small"><?php echo vs_pay_h($s['wstart'] . ' – ' . $s['wend']); ?> · <?php echo vs_pay_h(implode(', ', array_map(static fn($d) => $dn2[(int)$d] ?? '', array_filter(explode(',', $s['wdays']))))); ?></strong>
                            <small><?php echo vs_pay_h(vs_pay_fmt_hours($s['hours'])); ?> of <?php echo vs_pay_h(vs_pay_fmt_hours($s['expected'])); ?> hrs expected this month (<?php echo (int)$pct; ?>%)</small>
                          <?php else: ?>
                            <strong class="small">No schedule set</strong><small>Use Rates &amp; Work Time to set one</small>
                          <?php endif; ?></div>
                        <div class="vx-mini"><small>Rate</small><strong><?php echo vs_pay_h(VS_PAY_CURRENCY . ' ' . number_format($s['rate'], 2)); ?> /hr</strong></div>
                        <?php if (!empty($s['bonus'])): ?><div class="vx-mini"><small>Task bonus</small><strong><?php echo vs_pay_h(vs_pay_money($s['bonus'])); ?></strong><small><?php echo !empty($s['eligible']) ? 'included in Total Pay' : 'counts once they are payable'; ?></small></div><?php endif; ?>
                        <div class="vx-mini"><small>Total Pay</small><strong><?php echo vs_pay_h(vs_pay_money($s['amount'])); ?></strong></div>
                      </div>
                      <div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 8px"><span class="small" style="font-weight:600">Payment Status</span><span class="vx-pill <?php echo $meta[1]; ?>"><?php echo $meta[0]; ?></span></div>
                      <?php if ($s['status'] === 'nopay'): ?><div class="text-muted small" style="margin-bottom:8px"><?php echo vs_pay_h(implode('; ', $s['why'])); ?></div><?php endif; ?>
                      <?php if (!empty($s['override'])): ?><div class="text-muted small" style="margin-bottom:8px"><i class="bi bi-unlock"></i> The owner allowed this admin to be paid even though they are under the minimum.</div><?php endif; ?>
                      <div style="display:flex;gap:8px" class="pay-noprint">
                        <?php foreach (['paid' => ['Mark as Paid', '', 'bi-check2'], 'pending' => ['Mark as Pending', 'vx-btn-ghost', 'bi-clock'], 'nopay' => ['Do not pay', 'vx-btn-ghost', 'bi-slash-circle']] as $act => $b): if ($s['status'] === $act) { continue; } if ($act === 'paid' && empty($s['eligible'])) { continue; } $actKey = ($act === 'pending' && empty($s['eligible'])) ? 'allow' : $act; if ($actKey === 'allow') { $b = ['Pay anyway', 'vx-btn-ghost', 'bi-unlock']; } ?>
                          <form method="post" action="admin-pay.php" style="flex:1;margin:0">
                            <input type="hidden" name="csrf" value="<?php echo vs_pay_h($PP['csrf']); ?>"><input type="hidden" name="do" value="<?php echo $actKey; ?>"><input type="hidden" name="reason" value="">
                            <input type="hidden" name="month" value="<?php echo vs_pay_h($PP['month']); ?>"><input type="hidden" name="admin" value="<?php echo vs_pay_h($s['key']); ?>">
                            <button class="vx-btn vx-btn-sm vx-btn-block <?php echo $b[1]; ?>" type="submit" onclick="return vsPayAsk(this)"><i class="bi <?php echo $b[2]; ?>"></i> <?php echo $b[0]; ?></button>
                          </form>
                        <?php endforeach; ?>
                      </div>
                    </div>

                    <div id="payAdjust" class="pay-noprint">
                      <div style="font-weight:700;margin-bottom:2px">Adjust Hours</div>
                      <div class="text-muted small" style="margin-bottom:10px">Add hours, or use a minus to remove (for example -2).</div>
                      <form method="post" action="admin-pay.php" class="m-0">
                        <input type="hidden" name="csrf" value="<?php echo vs_pay_h($PP['csrf']); ?>"><input type="hidden" name="do" value="adjust">
                        <input type="hidden" name="month" value="<?php echo vs_pay_h($PP['month']); ?>"><input type="hidden" name="admin" value="<?php echo vs_pay_h($s['key']); ?>">
                        <div class="d-flex gap-2 mb-2">
                          <input class="form-control form-control-sm" style="max-width:110px" id="payHrs" type="number" name="hours" step="0.25" min="-500" max="500" placeholder="Hours" aria-label="Hours to add or remove" required>
                          <input class="form-control form-control-sm" id="payWhy" type="text" name="reason" maxlength="200" placeholder="Reason" aria-label="Reason" required>
                        </div>
                        <button class="vx-btn vx-btn-sm vx-btn-block" type="submit">Save</button>
                      </form>
                      <?php foreach ($PP['selAdjust'] as $ad): ?>
                        <div class="small text-muted" style="margin-top:8px"><?php echo ($ad['hours'] > 0 ? '+' : '') . vs_pay_h(rtrim(rtrim((string)$ad['hours'], '0'), '.')); ?> hrs · <?php echo vs_pay_h($ad['reason']); ?></div>
                      <?php endforeach; ?>
                    </div>

                    <div>
                      <div style="font-weight:700;margin-bottom:4px"><?php echo vs_pay_h($s['name']); ?>: Recent Activity</div>
                      <?php if (!$PP['selActivity']): ?><div class="text-muted small">No activity recorded yet.</div>
                      <?php else: foreach ($PP['selActivity'] as $ac): ?>
                        <div class="vx-act"><span class="vx-act-ico" style="background:#4b3fe0"><i class="bi bi-activity"></i></span><div class="vx-act-body"><b style="font-weight:500"><?php echo vs_pay_h($ac['details']); ?></b><small><?php echo vs_pay_h(date('M j, Y · g:i A', strtotime($ac['created_at']))); ?></small></div></div>
                      <?php endforeach; endif; ?>
                    </div>
                  </div>
                </section>
                <?php endif; ?>

          </div>
          </div>
        </div>
      </main>

      <!-- Update rates + work time -->
      <div class="modal fade" id="payRates" tabindex="-1" aria-labelledby="payRatesTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content vx-modal">
          <form method="post" action="admin-pay.php">
            <input type="hidden" name="csrf" value="<?php echo vs_pay_h($PP['csrf']); ?>"><input type="hidden" name="do" value="rates">
            <input type="hidden" name="month" value="<?php echo vs_pay_h($PP['month']); ?>">
            <div class="modal-header"><h2 class="modal-title h5" id="payRatesTitle">Rates &amp; Work Time</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <p class="text-muted small">Rate is the pay per hour in <?php echo VS_PAY_CURRENCY; ?>. Work time is the shift and the days each admin is expected to work; it sets their monthly target hours. Leave the times empty for no set schedule.</p>
              <table class="table align-middle">
                <thead><tr><th>Admin</th><th>Role label</th><th style="width:130px">Rate (<?php echo VS_PAY_CURRENCY; ?>/hr)</th><th style="width:230px">Shift (from – to)</th><th>Work days</th></tr></thead>
                <tbody>
                <?php $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
                <?php foreach ($cur['rows'] as $r): $k = vs_pay_h($r['key']); $on = array_map('intval', explode(',', $r['wdays'])); ?>
                  <tr><td><strong><?php echo vs_pay_h($r['name']); ?></strong></td>
                    <td><input class="form-control form-control-sm" type="text" maxlength="60" name="title[<?php echo $k; ?>]" value="<?php echo vs_pay_h($r['title']); ?>" placeholder="Admin"></td>
                    <td><input class="form-control form-control-sm" type="number" step="0.01" min="0" name="rate[<?php echo $k; ?>]" value="<?php echo vs_pay_h(number_format($r['rate'], 2, '.', '')); ?>"></td>
                    <td><div class="d-flex gap-1 align-items-center"><input class="form-control form-control-sm" type="time" name="wstart[<?php echo $k; ?>]" value="<?php echo vs_pay_h($r['wstart']); ?>" aria-label="Shift start"><span>–</span><input class="form-control form-control-sm" type="time" name="wend[<?php echo $k; ?>]" value="<?php echo vs_pay_h($r['wend']); ?>" aria-label="Shift end"></div></td>
                    <td><div class="d-flex flex-wrap gap-2 small">
                      <?php foreach ($dayNames as $n => $dn): ?>
                        <label class="d-flex align-items-center gap-1" style="cursor:pointer"><input type="checkbox" name="wdays[<?php echo $k; ?>][]" value="<?php echo $n; ?>"<?php echo in_array($n, $on, true) ? ' checked' : ''; ?>><?php echo $dn; ?></label>
                      <?php endforeach; ?>
                    </div></td></tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="modal-footer"><button type="button" class="vx-btn vx-btn-ghost vx-btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" class="vx-btn vx-btn-sm">Save</button></div>
          </form>
        </div></div>
      </div>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4" style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px">
          <span><strong style="background:linear-gradient(90deg,#c026d3,#a78bfa);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent">VibeStream</strong> Admin Panel</span><span style="opacity:.7">Manage &bull; Monitor &bull; Grow</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script>
    // "Do not pay" asks the owner for a short reason (optional); pressing Cancel stops the action.
    function vsPayAsk(btn) {
      var f = btn.form;
      var d = f && f.querySelector('[name=do]');
      if (!d || d.value !== 'nopay') { return true; }
      var r = window.prompt('Why is this admin not being paid this month? (optional, saved in the payroll log)', '');
      if (r === null) { return false; }
      f.querySelector('[name=reason]').value = r;
      return true;
    }
  </script>
  <script>
    // Search + status filter + pages (8 admins per page)
    (function () {
      var find = document.getElementById('payFind'), filter = document.getElementById('payFilter'), count = document.getElementById('payCount');
      var pager = document.getElementById('payPager'), none = document.getElementById('payNone');
      var rows = Array.prototype.slice.call(document.querySelectorAll('#payTable tbody tr[data-row]'));
      if (!rows.length || !find || !filter) { return; }
      var PER = 8, page = 1;
      function list() {
        var q = (find.value || '').toLowerCase().trim(), st = filter.value;
        return rows.filter(function (tr) {
          return (!q || tr.getAttribute('data-name').indexOf(q) !== -1) && (!st || tr.getAttribute('data-status') === st);
        });
      }
      function render() {
        var l = list(), total = Math.max(1, Math.ceil(l.length / PER));
        if (page > total) { page = total; }
        var from = (page - 1) * PER, to = Math.min(from + PER, l.length);
        rows.forEach(function (tr) { tr.hidden = true; });
        l.slice(from, to).forEach(function (tr) { tr.hidden = false; });
        if (none) { none.hidden = l.length !== 0; }
        if (count) { count.textContent = l.length ? 'Showing ' + (from + 1) + ' to ' + to + ' of ' + l.length + ' admins' : 'Showing 0 of ' + rows.length + ' admins'; }
        if (pager) {
          var h = '';
          if (total > 1) {
            h += '<button type="button" data-p="' + (page - 1) + '" aria-label="Previous page"' + (page === 1 ? ' disabled' : '') + '>&lsaquo;</button>';
            for (var p = 1; p <= total; p++) { h += '<button type="button" data-p="' + p + '"' + (p === page ? ' class="on" aria-current="page"' : '') + '>' + p + '</button>'; }
            h += '<button type="button" data-p="' + (page + 1) + '" aria-label="Next page"' + (page === total ? ' disabled' : '') + '>&rsaquo;</button>';
          } else {
            h = '<button type="button" disabled aria-label="Previous page">&lsaquo;</button><button type="button" class="on" aria-current="page">1</button><button type="button" disabled aria-label="Next page">&rsaquo;</button>';
          }
          pager.innerHTML = h;
        }
      }
      if (pager) {
        pager.addEventListener('click', function (e) {
          var b = e.target.closest('button[data-p]');
          if (!b || b.disabled) { return; }
          page = parseInt(b.getAttribute('data-p'), 10) || 1; render();
        });
      }
      find.addEventListener('input', function () { page = 1; render(); });
      filter.addEventListener('change', function () { page = 1; render(); });
      render();
    })();
  </script>
</body>
</html>