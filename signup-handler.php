<?php
// signup-handler.php - creates an account from Signup.php (replaces code.php?action=signup_form).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/dashboard-events.php';

/** Back to the form with a message (kept in the session, not in the URL) and the typed username/email. */
function sh_fail($msg)
{
    $_SESSION['signup_error'] = (string)$msg;
    header('Location: Signup.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: Signup.php');
    exit;
}

// Remember what was typed (never the password) so the form can be refilled after an error.
$_SESSION['signup_old'] = [
    'username' => mb_substr(trim((string)($_POST['username'] ?? '')), 0, 60),
    'email'    => mb_substr(trim((string)($_POST['email'] ?? '')), 0, 190),
];

if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
    sh_fail('Your session expired. Please try again.');
}

// Hidden "website" field: real people leave it empty, simple bots fill it in.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    header('Location: index.php');
    exit;
}

// Basic throttle: at most 8 attempts per 10 minutes per browser session.
$now = time();
$attempts = array_values(array_filter((array)($_SESSION['signup_attempts'] ?? []), fn($t) => $t > $now - 600));
if (count($attempts) >= 8) {
    sh_fail('Too many attempts. Please wait a few minutes and try again.');
}
$attempts[] = $now;
$_SESSION['signup_attempts'] = $attempts;

try {
    ob_start();
    include __DIR__ . '/connection.php'; // $conn (mysqli)
    ob_end_clean();

    $username = trim((string)($_POST['username'] ?? ''));
    $email    = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass     = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[\p{L}\p{N}_.-]{3,30}$/u', $username)) {
        sh_fail('Username must be 3-30 characters: letters, numbers, dot, dash or underscore (no spaces).');
    }
    if (in_array(mb_strtolower($username), ['admin', 'administrator', 'root', 'system', 'support', 'guest', 'moderator', 'vibestream'], true)) {
        sh_fail('That username is reserved. Please choose another one.');
    }
    if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sh_fail('Please enter a valid email address.');
    }
    if (strlen($pass) < 8) {
        sh_fail('Password must be at least 8 characters.');
    }
    if (strlen($pass) > 72) {
        sh_fail('Password must be 72 characters or fewer.');
    }
    if (mb_strtolower($pass) === mb_strtolower($username) || mb_strtolower($pass) === $email) {
        sh_fail('Password cannot be the same as your username or email.');
    }
    if ($pass !== $confirm) {
        sh_fail('The two passwords do not match.');
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
    $rCol = $pick(['role', 'user_type']);
    $iCol = $pick(['id', 'user_id']);
    if (!$uCol || !$pCol) {
        throw new RuntimeException('users table needs username and password columns');
    }

    // Already used?
    $sql = 'SELECT 1 FROM `users` WHERE `' . $uCol . '` = ?';
    $types = 's';
    $args = [$username];
    if ($eCol) {
        $sql .= ' OR `' . $eCol . '` = ?';
        $types .= 's';
        $args[] = $email;
    }
    $st = $conn->prepare($sql . ' LIMIT 1');
    $st->bind_param($types, ...$args);
    $st->execute();
    $st->store_result();
    $exists = $st->num_rows > 0;
    $st->close();
    if ($exists) {
        sh_fail('That username or email is already registered.');
    }

    $fields = [$uCol => $username, $pCol => password_hash($pass, PASSWORD_DEFAULT)];
    if ($eCol) {
        $fields[$eCol] = $email;
    }
    if ($rCol) {
        $fields[$rCol] = 'User';
    }
    $names = '`' . implode('`,`', array_keys($fields)) . '`';
    $marks = implode(',', array_fill(0, count($fields), '?'));
    $ins = $conn->prepare("INSERT INTO `users` ($names) VALUES ($marks)");
    $ins->bind_param(str_repeat('s', count($fields)), ...array_values($fields));
    $ins->execute();
    $newId = $conn->insert_id;
    $ins->close();
    vs_dashboard_log_signup_ip($conn, (int)$newId);

    // Sign the new user straight in.
    session_regenerate_id(true);
    unset($_SESSION['signup_old'], $_SESSION['signup_error'], $_SESSION['signup_attempts']);
    $_SESSION['username'] = $username;
    $_SESSION['email']    = $email;
    $_SESSION['role']     = 'User';
    if ($iCol && $newId) {
        $_SESSION['user_id'] = $newId;
        $_SESSION['id'] = $newId;
    }
    header('Location: about.php');   // welcome page; its button leads on to index.php
    exit;
} catch (Throwable $e) {
    error_log('signup-handler.php: ' . $e->getMessage());
    // 1062 = duplicate key: two people registered the same name/email at the same moment.
    if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
        sh_fail('That username or email is already registered.');
    }
    sh_fail('Could not create your account. Please try again later.');
}