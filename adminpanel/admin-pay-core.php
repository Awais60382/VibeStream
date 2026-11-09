<?php
/*
 * VibeStream admin payroll - shared settings and calculation.
 * Used by admin-pay.php (owner) and my-pay.php (each admin sees only their own pay).
 * Put this file in the adminpanel folder. Change the pay rules (currency, minimum hours/actions) here.
 */
require_once __DIR__ . '/admin-activity-store.php';   // vs_activity_ensure()
require_once __DIR__ . '/checkin-lib.php';            // check-in hours file

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
        // Who marked the month as paid, and when (payment record)
        if (!in_array('paid_by', $pmCols, true)) {
            $pdo->exec("ALTER TABLE admin_pay_months ADD COLUMN paid_by VARCHAR(100) NOT NULL DEFAULT ''");
        }
        if (!in_array('paid_at', $pmCols, true)) {
            $pdo->exec('ALTER TABLE admin_pay_months ADD COLUMN paid_at DATETIME NULL');
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
    $q = $pdo->prepare('SELECT * FROM admin_pay_months WHERE month = ?');
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
            'paid_by' => $rec ? (string)($rec['paid_by'] ?? '') : '', 'paid_at' => $rec ? (string)($rec['paid_at'] ?? '') : '',
            'live' => $eligible ? round($hours * $rate, 2) + $bonus : 0.0,
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
