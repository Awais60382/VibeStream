<?php
/**
 * VibeStream Premium storage (MySQL via the $pdo from connection.php).
 * Put this file in the site root, next to header.php.
 *
 * How paying works:
 *  1. The user sends money using one of the methods in VS_PREMIUM_PAY_INFO.
 *  2. They type the transaction ID on premium.php. That creates a "pending" request.
 *  3. You open premium-requests.php, check the payment arrived, and press Approve.
 *  4. Approve switches Premium on. Nobody gets Premium without that step.
 * Later, a real payment provider can call vs_premium_set() after a confirmed payment.
 */

// Usernames allowed to approve or reject payments on premium-requests.php. EDIT THIS.
const VS_PREMIUM_ADMINS = ['admin'];

// How people pay you. Shown in the payment box on premium.php. EDIT THESE LINES.
const VS_PREMIUM_PAY_INFO = [
    'Bank transfer: Bank name, account number, account title',
    'Mobile wallet: wallet number and account name',
];

// What each level gets. Used by the site to decide quality, ads and downloads (see vs_premium_user_limits()).
const VS_PREMIUM_LIMITS = [
    'free'    => ['max_height' => 720,  'ads' => true,  'downloads' => false, 'background_play' => false],
    'premium' => ['max_height' => 1080, 'ads' => false, 'downloads' => true,  'background_play' => true],
    'plus'    => ['max_height' => 2160, 'ads' => false, 'downloads' => true,  'background_play' => true],
];

// Plan keys must be 10 characters or fewer (premium_members.plan is VARCHAR(10)).
// Premium: weekly / monthly / yearly.   Premium Plus: plusweek / plusmonth / plusyear.
const VS_PREMIUM_PLANS = [
    'weekly'    => ['label' => 'Weekly',       'price' => 1.99,  'days' => 7,   'tier' => 'premium'],
    'monthly'   => ['label' => 'Monthly',      'price' => 4.99,  'days' => 31,  'tier' => 'premium'],
    'yearly'    => ['label' => 'Yearly',       'price' => 39.99, 'days' => 366, 'tier' => 'premium'],
    'plusweek'  => ['label' => 'Plus Weekly',  'price' => 3.99,  'days' => 7,   'tier' => 'plus'],
    'plusmonth' => ['label' => 'Plus Monthly', 'price' => 9.99,  'days' => 31,  'tier' => 'plus'],
    'plusyear'  => ['label' => 'Plus Yearly',  'price' => 79.99, 'days' => 366, 'tier' => 'plus'],
];

// Old plans that can no longer be bought, but members / pending payments that already use them still work.
const VS_PREMIUM_LEGACY_PLANS = [
    'daily' => ['label' => 'Daily', 'price' => 0.99, 'days' => 1, 'tier' => 'premium'],
];

/** The $pdo from connection.php, or default XAMPP settings if connection.php has none. */
function vs_premium_pdo(): ?PDO
{
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=vibestream;charset=utf8mb4', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function vs_premium_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            plan VARCHAR(10) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'active\',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            txn_ref VARCHAR(100) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'pending\',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_txn_ref (txn_ref),
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // One row each time a Premium period ends. Admins see it as a notification;
    // once an admin has seen it (seen_at is set) that member leaves the admin Members list.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_end_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            ended_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            seen_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uq_period (username, started_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Which "your Premium ends soon / has ended" e-mails were already sent.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            kind VARCHAR(10) NOT NULL,
            sent_at DATETIME NOT NULL,
            UNIQUE KEY uq_reminder (username, expires_at, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/* ------------------------------------------------------------------ */
/* Payment instructions (editable by the owner in the admin panel)      */
/* ------------------------------------------------------------------ */

function vs_premium_settings_file(): string
{
    return __DIR__ . '/backups/premium-settings.json';
}

/** The lines shown to buyers ("send money to ..."). Empty = not set up yet, so buying stays closed. */
function vs_premium_pay_info(): array
{
    $f = vs_premium_settings_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($d) && isset($d['pay_info']) && is_array($d['pay_info'])) {
        return array_values(array_filter(array_map(static fn($l) => trim((string)$l), $d['pay_info']), static fn($l) => $l !== ''));
    }
    foreach (VS_PREMIUM_PAY_INFO as $line) {
        // The sample lines from the code are placeholders, not real account details.
        if (stripos($line, 'Bank name, account number') !== false || stripos($line, 'wallet number and account name') !== false) {
            return [];
        }
    }
    return VS_PREMIUM_PAY_INFO;
}

function vs_premium_save_pay_info(array $lines): bool
{
    $clean = [];
    foreach ($lines as $l) {
        $l = trim(preg_replace('/\s+/', ' ', (string)$l));
        if ($l !== '') {
            $clean[] = mb_substr($l, 0, 200);
        }
    }
    $clean = array_slice($clean, 0, 6);
    $dir = dirname(vs_premium_settings_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    $f = vs_premium_settings_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = is_array($d) ? $d : [];
    $d['pay_info'] = $clean;
    return @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/* ------------------------------------------------------------------ */
/* Levels and what they get                                             */
/* ------------------------------------------------------------------ */

function vs_premium_plan_def(string $plan): ?array
{
    return VS_PREMIUM_PLANS[$plan] ?? VS_PREMIUM_LEGACY_PLANS[$plan] ?? null;
}

/** 'free', 'premium' or 'plus' for a premium_members row (null = free). */
function vs_premium_tier(?array $row): string
{
    if (!vs_premium_is_active($row)) {
        return 'free';
    }
    return strpos((string)$row['plan'], 'plus') === 0 ? 'plus' : 'premium';
}

/** What this person may use: ['tier', 'max_height', 'ads', 'downloads', 'background_play']. Admins get Plus. */
function vs_premium_user_limits(?PDO $pdo, string $username): array
{
    $tier = 'free';
    if (vs_premium_is_admin($username)) {
        $tier = 'plus';
    } elseif ($pdo && $username !== '') {
        try {
            $tier = vs_premium_tier(vs_premium_get($pdo, $username));
        } catch (Throwable $e) {
            $tier = 'free';
        }
    }
    return ['tier' => $tier] + VS_PREMIUM_LIMITS[$tier];
}

function vs_premium_is_active(?array $row): bool
{
    return $row && $row['status'] === 'active' && strtotime((string)$row['expires_at']) > time();
}

function vs_premium_get(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare('SELECT * FROM premium_members WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Switch Premium on. If the user is already active, the new time is added on top. */
function vs_premium_set(PDO $pdo, string $username, string $plan): bool
{
    $def = VS_PREMIUM_PLANS[$plan] ?? VS_PREMIUM_LEGACY_PLANS[$plan] ?? null;
    if ($username === '' || $def === null) {
        return false;
    }
    $days = (int)$def['days'];
    $st = $pdo->prepare(
        'INSERT INTO premium_members (username, plan, status, started_at, expires_at, updated_at)
         VALUES (:u, :p, \'active\', NOW(), DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY), NOW())
         ON DUPLICATE KEY UPDATE
            started_at = IF(status = \'active\' AND expires_at > NOW(), started_at, NOW()),
            expires_at = DATE_ADD(IF(status = \'active\' AND expires_at > NOW(), expires_at, NOW()), INTERVAL ' . $days . ' DAY),
            plan = VALUES(plan), status = \'active\', updated_at = NOW()'
    );
    return $st->execute([':u' => $username, ':p' => $plan]);
}

function vs_premium_cancel(PDO $pdo, string $username): bool
{
    $st = $pdo->prepare('UPDATE premium_members SET status = \'cancelled\', updated_at = NOW() WHERE username = :u');
    return $st->execute([':u' => $username]);
}

function vs_premium_all(PDO $pdo): array
{
    // A member whose Premium ended and whose "Premium ended" alert an admin has already seen
    // is no longer shown in the admin list (the payment history stays in premium_requests).
    $notSeen = 'NOT EXISTS (
                 SELECT 1 FROM premium_end_alerts a
                 WHERE a.username = m.username AND a.started_at = m.started_at
                   AND a.expires_at = m.expires_at AND a.seen_at IS NOT NULL
             )';
    // Only members whose account still exists: a deleted account disappears from the list.
    try {
        return $pdo->query(
            'SELECT m.* FROM premium_members m
             WHERE EXISTS (
                 SELECT 1 FROM users u
                 WHERE LOWER(u.username) COLLATE utf8mb4_general_ci = LOWER(m.username) COLLATE utf8mb4_general_ci
             ) AND ' . $notSeen . '
             ORDER BY m.started_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // If the users table cannot be read this way, fall back to the plain list.
        try {
            return $pdo->query('SELECT m.* FROM premium_members m WHERE ' . $notSeen . ' ORDER BY m.started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            return $pdo->query('SELECT * FROM premium_members ORDER BY started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

/**
 * Creates a "Premium ended" alert for every Premium period that has ended (expired or cancelled)
 * and has no alert yet. Safe to call on every admin page load: each period is alerted only once.
 */
function vs_premium_sync_end_alerts(PDO $pdo): void
{
    $insert = 'INSERT IGNORE INTO premium_end_alerts (username, plan, started_at, expires_at, ended_at, created_at)
               SELECT m.username, m.plan, m.started_at, m.expires_at,
                      IF(m.status = \'active\', m.expires_at, m.updated_at), NOW()
               FROM premium_members m
               WHERE (m.status <> \'active\' OR m.expires_at <= NOW())';
    try {
        $pdo->exec($insert . ' AND EXISTS (
            SELECT 1 FROM users u
            WHERE LOWER(u.username) COLLATE utf8mb4_general_ci = LOWER(m.username) COLLATE utf8mb4_general_ci
        )');
    } catch (Throwable $e) {
        $pdo->exec($insert);
    }
}

/** Premium-ended alerts, newest first. By default only the ones no admin has seen yet. */
function vs_premium_end_alerts(PDO $pdo, bool $unseenOnly = true, int $limit = 50): array
{
    $st = $pdo->prepare(
        'SELECT * FROM premium_end_alerts' . ($unseenOnly ? ' WHERE seen_at IS NULL' : '') .
        ' ORDER BY ended_at DESC, id DESC LIMIT ' . (int)$limit
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function vs_premium_end_alerts_unseen(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM premium_end_alerts WHERE seen_at IS NULL')->fetchColumn();
}

/** An admin has seen these alerts: those members now leave the admin Members list. */
function vs_premium_end_alerts_mark_seen(PDO $pdo, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
    if (!$ids) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('UPDATE premium_end_alerts SET seen_at = NOW() WHERE seen_at IS NULL AND id IN (' . $in . ')');
    $st->execute($ids);
}

/** Save a "I paid" request. Returns null if saved, or an error message. */
function vs_premium_request_create(PDO $pdo, string $username, string $plan, string $ref): ?string
{
    if ($username === '' || !isset(VS_PREMIUM_PLANS[$plan])) {
        return 'Unknown plan.';
    }
    $ref = trim($ref);
    if (strlen($ref) < 4 || strlen($ref) > 100) {
        return 'Enter the transaction ID from your payment (4 to 100 characters).';
    }
    $cur = vs_premium_get($pdo, $username);
    if (vs_premium_is_active($cur) && strpos((string)$cur['plan'], 'plus') === 0 && strpos($plan, 'plus') !== 0) {
        return 'You already have Premium Plus.';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM premium_requests WHERE username = :u AND status = \'pending\'');
    $st->execute([':u' => $username]);
    if ((int)$st->fetchColumn() >= 3) {
        return 'You already have payments waiting to be confirmed.';
    }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO premium_requests (username, plan, amount, txn_ref, status, created_at, updated_at)
             VALUES (:u, :p, :a, :r, \'pending\', NOW(), NOW())'
        );
        $ins->execute([
            ':u' => $username,
            ':p' => $plan,
            ':a' => VS_PREMIUM_PLANS[$plan]['price'],
            ':r' => $ref,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 'That transaction ID has already been used.';
        }
        throw $e;
    }
    // Tell the owner a payment is waiting to be checked. Never allowed to break the request.
    $ownerAlertFile = __DIR__ . '/adminpanel/owner-alert.php';
    if (is_file($ownerAlertFile)) {
        try {
            require_once $ownerAlertFile;
            vs_owner_alert_after_response(
                $pdo, 'premium', 'Premium payment waiting: ' . $username,
                'Plan: ' . (VS_PREMIUM_PLANS[$plan]['label'] ?? $plan) . ', amount: ' . number_format((float)VS_PREMIUM_PLANS[$plan]['price'], 2)
                . ', transaction ID: ' . $ref . '. Check the payment, then approve or reject it in the admin panel.'
            );
        } catch (Throwable $e) {
            error_log('owner alert failed: ' . $e->getMessage());
        }
    }
    return null;
}

function vs_premium_pending(PDO $pdo, string $username): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM premium_requests WHERE username = :u AND status = \'pending\'');
    $st->execute([':u' => $username]);
    return (int)$st->fetchColumn() > 0;
}

function vs_premium_requests(PDO $pdo, int $limit = 100): array
{
    $st = $pdo->prepare(
        'SELECT * FROM premium_requests ORDER BY (status = \'pending\') DESC, created_at DESC LIMIT ' . (int)$limit
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Approve (turns Premium on) or reject a pending request. */
function vs_premium_review(PDO $pdo, int $id, bool $approve): bool
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM premium_requests WHERE id = :i AND status = \'pending\' FOR UPDATE');
        $st->execute([':i' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $pdo->rollBack();
            return false;
        }
        $up = $pdo->prepare('UPDATE premium_requests SET status = :s, updated_at = NOW() WHERE id = :i');
        $up->execute([':s' => $approve ? 'approved' : 'rejected', ':i' => $id]);
        if ($approve) {
            vs_premium_set($pdo, (string)$r['username'], (string)$r['plan']);
        }
        $pdo->commit();
        vs_premium_after_review($pdo, $r, $approve);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Plug-in point for a payment gateway (Stripe, Safepay, JazzCash, ...): call this from the gateway's
 * "payment confirmed" webhook. It records the payment as approved, switches Premium on and e-mails the
 * user. Returns false if this transaction was already processed (so a repeated webhook does nothing).
 */
function vs_premium_confirm_payment(PDO $pdo, string $username, string $plan, string $txn, ?float $amount = null): bool
{
    $def = vs_premium_plan_def($plan);
    $txn = trim($txn);
    if ($username === '' || $def === null || $txn === '') {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO premium_requests (username, plan, amount, txn_ref, status, created_at, updated_at)
             VALUES (:u, :p, :a, :r, \'approved\', NOW(), NOW())'
        );
        $ins->execute([':u' => $username, ':p' => $plan, ':a' => $amount ?? $def['price'], ':r' => mb_substr($txn, 0, 100)]);
        $id = (int)$pdo->lastInsertId();
        vs_premium_set($pdo, $username, $plan);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
    vs_premium_after_review($pdo, ['id' => $id, 'username' => $username, 'plan' => $plan, 'amount' => $amount ?? $def['price'], 'txn_ref' => $txn], true);
    return true;
}

/* ------------------------------------------------------------------ */
/* E-mails to the buyer (uses the e-mail settings on the System check page) */
/* ------------------------------------------------------------------ */

function vs_premium_email_user(PDO $pdo, string $username, string $subject, string $body): bool
{
    try {
        $core = __DIR__ . '/adminpanel/system-check-core.php';
        if (!is_file($core)) {
            return false;
        }
        require_once $core;
        if (!vs_syscheck_mail_configured()) {
            return false;
        }
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1');
        $st->execute([':u' => $username]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $to = '';
        foreach (['email', 'email_address', 'Email'] as $c) {
            if (!empty($u[$c])) {
                $to = trim((string)$u[$c]);
                break;
            }
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $err = '';
        return vs_syscheck_smtp_send(vs_syscheck_mail_settings(), $to, $subject, $body, $err);
    } catch (Throwable $e) {
        return false;
    }
}

/** Same, but after the page has been sent, so a slow mail server never makes an admin or buyer wait. */
function vs_premium_email_user_later(PDO $pdo, string $username, string $subject, string $body): void
{
    register_shutdown_function(static function () use ($pdo, $username, $subject, $body): void {
        ignore_user_abort(true);
        @set_time_limit(60);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        vs_premium_email_user($pdo, $username, $subject, $body);
    });
}

/** Tells the buyer their payment was confirmed or not. */
function vs_premium_after_review(PDO $pdo, array $r, bool $approve): void
{
    try {
        $def = vs_premium_plan_def((string)$r['plan']);
        $label = $def['label'] ?? (string)$r['plan'];
        $amount = number_format((float)$r['amount'], 2);
        $who = (string)$r['username'];
        if ($approve) {
            $row = vs_premium_get($pdo, $who);
            $until = $row ? date('F j, Y', strtotime((string)$row['expires_at'])) : '';
            vs_premium_email_user_later($pdo, $who, 'Your VibeStream Premium is active',
                "Hi " . $who . ",\n\nWe confirmed your payment of $" . $amount . " (" . $label . ", transaction " . (string)$r['txn_ref'] . ").\n"
                . ($until !== '' ? "Your Premium is active until " . $until . ".\n" : '')
                . "\nYou can open your receipt on the Premium page, under \"Your payments\".\n\nThank you for supporting VibeStream!\n");
        } else {
            vs_premium_email_user_later($pdo, $who, 'We could not confirm your VibeStream payment',
                "Hi " . $who . ",\n\nWe could not confirm your payment of $" . $amount . " (" . $label . ", transaction " . (string)$r['txn_ref'] . ").\n"
                . "If you did pay, please contact support with your payment receipt and we will fix it.\n");
        }
    } catch (Throwable $e) {
        // never break an approval because of e-mail
    }
}

/**
 * E-mails members whose Premium ends in 3 days, and those whose Premium just ended. Each e-mail is sent once.
 * Run it from system-monitor.php (hourly or daily). Returns how many e-mails were sent.
 */
function vs_premium_send_reminders(PDO $pdo): int
{
    vs_premium_ensure($pdo);
    $sent = 0;
    $jobs = [
        ['soon', "m.status = 'active' AND m.expires_at > NOW() AND m.expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)"],
        ['ended', "m.status = 'active' AND m.expires_at <= NOW() AND m.expires_at > DATE_SUB(NOW(), INTERVAL 3 DAY)"],
    ];
    foreach ($jobs as [$kind, $where]) {
        $rows = $pdo->query('SELECT m.* FROM premium_members m WHERE ' . $where)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $m) {
            $mark = $pdo->prepare('INSERT IGNORE INTO premium_reminders (username, expires_at, kind, sent_at) VALUES (:u, :e, :k, NOW())');
            $mark->execute([':u' => $m['username'], ':e' => $m['expires_at'], ':k' => $kind]);
            if ($mark->rowCount() !== 1) {
                continue;   // already sent for this period
            }
            $date = date('F j, Y', strtotime((string)$m['expires_at']));
            $ok = $kind === 'soon'
                ? vs_premium_email_user($pdo, (string)$m['username'], 'Your VibeStream Premium ends soon',
                    "Hi " . $m['username'] . ",\n\nYour Premium ends on " . $date . ".\nTo keep no ads, high quality and downloads, pay again on the Premium page before then.\n")
                : vs_premium_email_user($pdo, (string)$m['username'], 'Your VibeStream Premium has ended',
                    "Hi " . $m['username'] . ",\n\nYour Premium ended on " . $date . ".\nYou can get it back any time on the Premium page.\n");
            $sent += $ok ? 1 : 0;
        }
    }
    return $sent;
}

/** A buyer's own payments, newest first (shown on the Premium page with a receipt link). */
function vs_premium_user_history(PDO $pdo, string $username, int $limit = 10): array
{
    $st = $pdo->prepare('SELECT id, plan, amount, status, created_at FROM premium_requests WHERE username = :u ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([':u' => $username]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $def = vs_premium_plan_def((string)$r['plan']);
        $out[] = ['id' => (int)$r['id'], 'label' => $def['label'] ?? (string)$r['plan'], 'amount' => (float)$r['amount'],
                  'status' => (string)$r['status'], 'date' => substr((string)$r['created_at'], 0, 10)];
    }
    return $out;
}

/** Admins (VS_PREMIUM_ADMINS) always have Premium, with no payment and no expiry. */
function vs_premium_is_admin(string $username): bool
{
    if ($username === '') {
        return false;
    }
    if (in_array(strtolower(trim($username)), array_map('strtolower', VS_PREMIUM_ADMINS), true)) {
        return true;
    }
    // Anyone signed in with the Admin role (the same check the admin panel uses).
    if (strtolower(trim((string)($_SESSION['username'] ?? ''))) === strtolower(trim($username))) {
        if (isset($_SESSION['AdminSession'])) {
            return true;
        }
        if (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0) {
            return true;
        }
    }
    return false;
}

/** State handed to premium.js on every page (header.php prints it). */
function vs_premium_state(?PDO $pdo, string $username): array
{
    if (empty($_SESSION['premium_csrf'])) {
        $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
    }
    $state = [
        'loggedIn' => $username !== '',
        'active'   => false,
        'pending'  => false,
        'plan'     => '',
        'since'    => 0,
        'expires'  => 0,
        'plans'    => VS_PREMIUM_PLANS,
        'payInfo'  => vs_premium_pay_info(),
        'history'  => [],
        'limits'   => VS_PREMIUM_LIMITS['free'],
        'csrf'     => $_SESSION['premium_csrf'],
    ];
    if (vs_premium_is_admin($username)) {
        $state['active']  = true;
        $state['plan']    = 'admin';
        $state['since']   = time() * 1000;
        $state['expires'] = (time() + 3153600000) * 1000; // about 100 years
        $state['limits']  = VS_PREMIUM_LIMITS['plus'];
        return $state;
    }
    if ($pdo && $username !== '') {
        try {
            vs_premium_ensure($pdo);
            $row = vs_premium_get($pdo, $username);
            if (vs_premium_is_active($row)) {
                $state['active']  = true;
                $state['plan']    = $row['plan'];
                $state['since']   = strtotime((string)$row['started_at']) * 1000;
                $state['expires'] = strtotime((string)$row['expires_at']) * 1000;
            }
            $state['pending'] = vs_premium_pending($pdo, $username);
            $state['history'] = vs_premium_user_history($pdo, $username, 10);
            $state['limits']  = VS_PREMIUM_LIMITS[vs_premium_tier($row)];
        } catch (Throwable $e) {
            // never break a page because of premium
        }
    }
    return $state;
}