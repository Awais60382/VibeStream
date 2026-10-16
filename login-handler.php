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

// Owner alerts and the 10-failures-per-address block (optional: skipped if the file is missing).
$lhAlertFile = __DIR__ . '/adminpanel/owner-alert.php';
if (is_file($lhAlertFile)) {
    try {
        require_once $lhAlertFile;
    } catch (Throwable $e) {
        error_log('login-handler.php: owner alerts unavailable: ' . $e->getMessage());
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
        if (function_exists('vs_login_failed')) {
            vs_login_failed($user ? (string)$user[$uCol] : '', $lhIp);
        }
        lh_fail('Wrong username/email or password.');
    }

    // Blocked accounts.
    foreach (['status', 'account_status'] as $c) {
        if (isset($cols[$c]) && in_array(strtolower((string)$user[$cols[$c]]), ['banned', 'blocked', 'disabled', 'suspended'], true)) {
            lh_fail('This account has been disabled.');
        }
    }
    foreach (['is_banned', 'banned', 'is_blocked'] as $c) {
        if (isset($cols[$c]) && (int)$user[$cols[$c]] === 1) {
            lh_fail('This account has been disabled.');
        }
    }

    session_regenerate_id(true);
    $role = (string)($user[$pick(['role', 'user_type'])] ?? 'user');
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
    if (function_exists('vs_login_succeeded')) {
        vs_login_succeeded((string)$user[$uCol]);
    }

    if (strcasecmp($role, 'admin') === 0) {
        $_SESSION['AdminSession'] = $user[$uCol];
        if ($idCol) {
            $_SESSION['AdminID'] = $user[$idCol];
        }
        header('Location: adminpanel/index.php');
    } else {
        header('Location: index.php');
    }
    exit;
} catch (Throwable $e) {
    error_log('login-handler.php: ' . $e->getMessage());
    lh_fail('Sign-in failed. Please try again later.');
}