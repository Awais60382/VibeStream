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

const VS_PREMIUM_PLANS = [
    'daily'   => ['label' => 'Daily',   'price' => 0.99,  'days' => 1],
    'monthly' => ['label' => 'Monthly', 'price' => 4.99,  'days' => 31],
    'yearly'  => ['label' => 'Yearly',  'price' => 39.99, 'days' => 366],
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
    if ($username === '' || !isset(VS_PREMIUM_PLANS[$plan])) {
        return false;
    }
    $days = (int)VS_PREMIUM_PLANS[$plan]['days'];
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
    // Only members whose account still exists: a deleted account disappears from the list.
    try {
        return $pdo->query(
            'SELECT m.* FROM premium_members m
             WHERE EXISTS (
                 SELECT 1 FROM users u
                 WHERE LOWER(u.username) COLLATE utf8mb4_general_ci = LOWER(m.username) COLLATE utf8mb4_general_ci
             )
             ORDER BY m.started_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // If the users table cannot be read this way, fall back to the plain list.
        return $pdo->query('SELECT * FROM premium_members ORDER BY started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
    }
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