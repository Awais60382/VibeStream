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
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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
        'payInfo'  => VS_PREMIUM_PAY_INFO,
        'csrf'     => $_SESSION['premium_csrf'],
    ];
    if (vs_premium_is_admin($username)) {
        $state['active']  = true;
        $state['plan']    = 'admin';
        $state['since']   = time() * 1000;
        $state['expires'] = (time() + 3153600000) * 1000; // about 100 years
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
        } catch (Throwable $e) {
            // never break a page because of premium
        }
    }
    return $state;
}