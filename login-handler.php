<?php
// login-handler.php - email/username + password sign-in (replaces code.php?action=login_form).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function lh_fail($msg)
{
    header('Location: login.php?error=' . urlencode($msg));
    header('Content-Length: 0');   // the visitor is redirected at once, even if an owner alert is still being sent
    header('Connection: close');
    flush();
    exit;
}

// A banned account is not signed in: it is sent to banned.php, which shows the reason and the time left.
function lh_banned(array $ban)
{
    $_SESSION['vs_ban_card'] = $ban;
    header('Location: banned.php');
    header('Content-Length: 0');
    header('Connection: close');
    flush();
    exit;
}

// Owner alerts and the 10-failures-per-address block (optional: skipped if the file is missing).
$lhAlertFile = __DIR__ . '/adminpanel/owner-alert.php';
if (is_file($lhAlertFile)) {
    try {
        require_once $lhAlertFile;
    } catch (Throwable $e) {
        error_log('login-handler.php: owner alerts unavailable: ' . $e->getMessage());
    }
}
require_once __DIR__ . '/adminpanel/admin-alert-store.php';
// Login activity page (optional: skipped if the file is missing).
$lhSafetyFile = __DIR__ . '/account-safety-store.php';
if (is_file($lhSafetyFile)) {
    try {
        require_once $lhSafetyFile;
    } catch (Throwable $e) {
        error_log('login-handler.php: login activity unavailable: ' . $e->getMessage());
    }
}
$lhIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

try {
    ob_start();
    include __DIR__ . '/connection.php'; // $conn (mysqli)
    ob_end_clean();

    $login = trim((string)($_POST['useremail'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');
    if ($login === '' || $pass === '') {
        lh_fail('Please enter your username/email and password.');
    }

    // Block an address that failed 10 times in 10 minutes (a new session does not reset this).
    if (function_exists('vs_login_blocked') && vs_login_blocked($lhIp)) {
        lh_fail('Too many attempts. Please wait a few minutes and try again.');
    }

    // Slow down repeated failures (5 tries per 10 minutes per session).
    $now = time();
    $_SESSION['login_fails'] = array_values(array_filter($_SESSION['login_fails'] ?? [], function ($t) use ($now) {
        return $t > $now - 600;
    }));
    if (count($_SESSION['login_fails']) >= 5) {
        lh_fail('Too many attempts. Please wait a few minutes and try again.');
    }

    $cols = [];
    $res = $conn->query('SHOW COLUMNS FROM `users`');
    while ($r = $res->fetch_assoc()) {
        $cols[strtolower($r['Field'])] = $r['Field'];
    }
    $res->close();
    $pick = function (array $names) use ($cols) {
        foreach ($names as $n) {
            if (isset($cols[$n])) {
                return $cols[$n];
            }
        }
        return null;
    };
    $uCol = $pick(['username', 'user_name']);
    $eCol = $pick(['email', 'email_address']);
    $pCol = $pick(['password', 'password_hash', 'pass']);
    $roleCol = $pick(['role', 'user_role', 'usertype', 'user_type', 'account_type']);
    if (!$uCol || !$pCol) {
        throw new RuntimeException('users table needs username and password columns');
    }

    $where = '`' . $uCol . '` = ?';
    $types = 's';
    $args  = [$login];
    if ($eCol) {
        $where .= ' OR `' . $eCol . '` = ?';
        $types .= 's';
        $args[] = $login;
    }
    $st = $conn->prepare("SELECT * FROM `users` WHERE $where LIMIT 1");
    $st->bind_param($types, ...$args);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    $pendingBan = null;   // set when the account is banned; shown only after the right password (so nobody can read a ban reason by guessing a username)
    if ($user) {
        // Look the ban up now, but only show it once the password is correct. A banned account never gets a session.
        try {
            $conn->query(
                'CREATE TABLE IF NOT EXISTS `user_bans` (
                    `user_id` VARCHAR(191) NOT NULL,
                    `username` VARCHAR(191) NOT NULL DEFAULT "",
                    `reason` VARCHAR(500) NOT NULL DEFAULT "",
                    `banned_by` VARCHAR(191) NOT NULL DEFAULT "",
                    `is_permanent` TINYINT(1) NOT NULL DEFAULT 0,
                    `expires_at` DATETIME NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`user_id`),
                    KEY `idx_user_bans_username` (`username`),
                    KEY `idx_user_bans_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );

            $ban = null;
            $banIdCol = $pick(['id', 'user_id']);
            $banUserId = $banIdCol ? (string)$user[$banIdCol] : '';
            $banUsername = (string)$user[$uCol];

            if ($banUserId !== '') {
                $bst = $conn->prepare(
                    'SELECT `is_permanent`, `expires_at`, UNIX_TIMESTAMP(`expires_at`) AS `expires_epoch`, `reason`
                     FROM `user_bans`
                     WHERE CAST(`user_id` AS BINARY) = CAST(? AS BINARY)
                       AND (`is_permanent` = 1 OR (`expires_at` IS NOT NULL AND `expires_at` > NOW()))
                     LIMIT 1'
                );
                $bst->bind_param('s', $banUserId);
                $bst->execute();
                $ban = $bst->get_result()->fetch_assoc();
                $bst->close();
            }
            if (!$ban && $banUsername !== '') {
                $bst = $conn->prepare(
                    'SELECT `is_permanent`, `expires_at`, UNIX_TIMESTAMP(`expires_at`) AS `expires_epoch`, `reason`
                     FROM `user_bans`
                     WHERE CAST(`username` AS BINARY) = CAST(? AS BINARY)
                       AND (`is_permanent` = 1 OR (`expires_at` IS NOT NULL AND `expires_at` > NOW()))
                     LIMIT 1'
                );
                $bst->bind_param('s', $banUsername);
                $bst->execute();
                $ban = $bst->get_result()->fetch_assoc();
                $bst->close();
            }
            $cleanup = $conn->prepare(
                'DELETE FROM `user_bans`
                 WHERE `is_permanent` = 0 AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()
                   AND (CAST(`user_id` AS BINARY) = CAST(? AS BINARY)
                     OR CAST(`username` AS BINARY) = CAST(? AS BINARY))'
            );
            $cleanup->bind_param('ss', $banUserId, $banUsername);
            $cleanup->execute();
            $cleanup->close();

            if ($ban) {
                $permanent = (int)$ban['is_permanent'] === 1;
                $expires = (int)($ban['expires_epoch'] ?? 0);
                if ($permanent || $expires > 0) {
                    $pendingBan = [
                        'permanent' => $permanent,
                        'until'     => $permanent ? 0 : $expires,
                        'reason'    => trim((string)($ban['reason'] ?? '')),
                    ];
                }
            }
        } catch (Throwable $e) {
            error_log('login-handler.php: ban check failed: ' . $e->getMessage());
        }

        // Accounts marked banned in the users table itself (older bans without a user_bans row).
        foreach (['status', 'account_status'] as $c) {
            if (isset($cols[$c]) && in_array(strtolower((string)$user[$cols[$c]]), ['banned', 'blocked', 'disabled', 'suspended'], true)) {
                $pendingBan = $pendingBan ?? ['permanent' => true, 'until' => 0, 'reason' => ''];
            }
        }
        foreach (['is_banned', 'banned', 'is_blocked'] as $c) {
            if (isset($cols[$c]) && (int)$user[$cols[$c]] === 1) {
                $pendingBan = $pendingBan ?? ['permanent' => true, 'until' => 0, 'reason' => ''];
            }
        }
    }

    $stored = $user ? (string)$user[$pCol] : '';
    $ok = false;
    if ($user && $stored !== '') {
        if (password_verify($pass, $stored)) {
            $ok = true;
        } elseif (strlen($stored) < 60 && hash_equals($stored, $pass)) {
            // Old plain-text password: accept it once and upgrade it to a hash.
            $ok = true;
            $new = password_hash($pass, PASSWORD_DEFAULT);
            $up = $conn->prepare('UPDATE `users` SET `' . $pCol . '` = ? WHERE `' . $uCol . '` = ?');
            $up->bind_param('ss', $new, $user[$uCol]);
            $up->execute();
            $up->close();
        }
    }
    if (!$ok) {
        $_SESSION['login_fails'][] = $now;
        if ($user && function_exists('vs_login_log')) {
            vs_login_log(isset($pdo) && $pdo instanceof PDO ? $pdo : null, (string)$user[$uCol], false);
        }
        if (function_exists('vs_login_failed')) {
            vs_login_failed($user ? (string)$user[$uCol] : '', $lhIp);
        }

        $failedRole = strtolower(trim((string)($user && $roleCol ? ($user[$roleCol] ?? '') : '')));
        $failedRoleNorm = preg_replace('/[^a-z]/', '', $failedRole);
        $isAdminRole = $failedRoleNorm !== '' && (
            str_contains($failedRoleNorm, 'admin') ||
            str_contains($failedRoleNorm, 'owner') ||
            str_contains($failedRoleNorm, 'ceo') ||
            str_contains($failedRoleNorm, 'founder')
        );

        if ($user && $isAdminRole && isset($pdo) && $pdo instanceof PDO) {
            try {
                vs_admin_alerts_ensure($pdo);
                $adminName = (string)$user[$uCol];
                $alertKey = 'admin-login-failed-' . hash('sha256', mb_strtolower($adminName) . '|' . $lhIp . '|' . (string)floor($now / 60));
                $adminLabel = (str_contains($failedRoleNorm, 'ceo') || str_contains($failedRoleNorm, 'owner')) ? 'CEO' : 'admin';
                vs_admin_alert_add(
                    $pdo,
                    $alertKey,
                    'admin_login_failed',
                    'Failed ' . $adminLabel . ' sign-in',
                    'A sign-in attempt for ' . $adminName . ' failed from IP ' . ($lhIp !== '' ? $lhIp : 'unknown') . '.',
                    'failed-admin-logins.php'
                );
            } catch (Throwable $e) {
                error_log('Could not save failed admin login alert: ' . $e->getMessage());
            }
        }
        lh_fail('Wrong username/email or password.');
    }

    $banRole = strtolower(trim((string)($roleCol ? ($user[$roleCol] ?? '') : '')));
    $normalizedBanRole = preg_replace('/[^a-z]/', '', $banRole);
    $isPrivilegedAccount = $normalizedBanRole !== '' && preg_match('/admin|owner|ceo|founder/', $normalizedBanRole) === 1;
    if ($pendingBan && !$isPrivilegedAccount) {
        lh_banned($pendingBan);
    }

    session_regenerate_id(true);
    $role = $roleCol ? (string)($user[$roleCol] ?? 'user') : 'user';
    $_SESSION['username'] = $user[$uCol];
    $_SESSION['role']     = $role;
    if ($eCol) {
        $_SESSION['email'] = $user[$eCol];
    }
    $idCol = $pick(['id', 'user_id']);
    if ($idCol) {
        $_SESSION['user_id'] = $user[$idCol];
        $_SESSION['id'] = $user[$idCol];
    }
    unset($_SESSION['login_fails']);
    unset($_SESSION['vs_ban_card']);
    if (function_exists('vs_login_succeeded')) {
        vs_login_succeeded((string)$user[$uCol]);
    }
    if (function_exists('vs_login_log')) {
        vs_login_log(isset($pdo) && $pdo instanceof PDO ? $pdo : null, (string)$user[$uCol], true);
    }

    $roleNormalized = strtolower(trim($role));
    $isAdminRole = preg_match('/(^|[^a-z])(admin|owner|ceo|founder)([^a-z]|$)/i', $roleNormalized) === 1;
    if ($isAdminRole) {
        $_SESSION['AdminSession'] = $user[$uCol];
        if ($idCol) {
            $_SESSION['AdminID'] = $user[$idCol];
        }
        require_once __DIR__ . '/adminpanel/admin-session-core.php';
        vs_admin_mark_authenticated_session(
            (string)$user[$uCol],
            $idCol ? (string)$user[$idCol] : '',
            'admin'
        );
        header('Location: adminpanel/index.php');
    } else {
        header('Location: index.php');
    }
    exit;
} catch (Throwable $e) {
    error_log('login-handler.php: ' . $e->getMessage());
    lh_fail('Sign-in failed. Please try again later.');
}