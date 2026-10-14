<?php
// google-login.php - verifies a Google ID token and signs the user in. Always answers with JSON.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function gl_out($ok, $data = [], $code = 200)
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    echo json_encode(array_merge(['success' => (bool)$ok], $data));
    exit;
}

set_error_handler(function ($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        gl_out(false, ['message' => 'Invalid request.'], 405);
    }

    require_once __DIR__ . '/google-oauth.php';
    if (!GOOGLE_OAUTH_ENABLED || GOOGLE_OAUTH_CLIENT_ID === '') {
        gl_out(false, ['message' => 'Google sign-in is turned off.'], 403);
    }

    $credential = trim((string)($_POST['credential'] ?? ''));
    if ($credential === '') {
        gl_out(false, ['message' => 'Missing Google credential.'], 400);
    }

    // 1) Ask Google to validate the token (no client secret needed).
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        $raw = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Google: ' . $curlErr);
        }
    } else {
        $raw = file_get_contents($url);
        if ($raw === false) {
            throw new RuntimeException('Could not reach Google (enable the curl or openssl extension).');
        }
    }
    $claims = json_decode($raw, true);
    if (!is_array($claims) || isset($claims['error']) || empty($claims['email'])) {
        gl_out(false, ['message' => 'Google could not verify this sign-in.'], 401);
    }

    // 2) Check the claims.
    if (($claims['aud'] ?? '') !== GOOGLE_OAUTH_CLIENT_ID) {
        gl_out(false, ['message' => 'This token was issued for a different app.'], 401);
    }
    if (!in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
        gl_out(false, ['message' => 'Invalid token issuer.'], 401);
    }
    if ((int)($claims['exp'] ?? 0) < time()) {
        gl_out(false, ['message' => 'Sign-in expired. Please try again.'], 401);
    }
    if (!in_array((string)($claims['email_verified'] ?? ''), ['true', '1'], true)) {
        gl_out(false, ['message' => 'Your Google email is not verified.'], 401);
    }
    $expectedNonce = (string)($_SESSION['google_oauth_nonce'] ?? '');
    if ($expectedNonce === '' || !hash_equals($expectedNonce, (string)($claims['nonce'] ?? ''))) {
        gl_out(false, ['message' => 'Sign-in session expired. Reload the page and try again.'], 401);
    }
    unset($_SESSION['google_oauth_nonce']);

    $email = strtolower(trim($claims['email']));
    $name  = trim((string)($claims['name'] ?? ''));

    // 3) Database.
    include __DIR__ . '/connection.php'; // gives $conn (mysqli)
    $cols = [];
    $res = $conn->query('SHOW COLUMNS FROM `users`');
    while ($r = $res->fetch_assoc()) {
        $cols[strtolower($r['Field'])] = true;
    }
    $res->close();
    if (empty($cols['email']) || empty($cols['username'])) {
        throw new RuntimeException('The users table needs "email" and "username" columns.');
    }

    $st = $conn->prepare('SELECT * FROM `users` WHERE `email` = ? LIMIT 1');
    $st->bind_param('s', $email);
    $st->execute();
    $user = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$user) {
        // Build a unique username.
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '', $name !== '' ? str_replace(' ', '', $name) : explode('@', $email)[0]);
        if (strlen($base) < 3) {
            $base = 'user' . $base;
        }
        $base = substr($base, 0, 24);
        $username = $base;
        for ($i = 0; $i < 50; $i++) {
            $chk = $conn->prepare('SELECT 1 FROM `users` WHERE `username` = ? LIMIT 1');
            $chk->bind_param('s', $username);
            $chk->execute();
            $chk->store_result();
            $taken = $chk->num_rows > 0;
            $chk->close();
            if (!$taken) {
                break;
            }
            $username = $base . random_int(10, 9999);
        }

        $fields = ['username' => $username, 'email' => $email];
        if (!empty($cols['password'])) {
            $fields['password'] = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        }
        if (!empty($cols['role'])) {
            $fields['role'] = 'User';
        }
        $names = '`' . implode('`,`', array_keys($fields)) . '`';
        $marks = implode(',', array_fill(0, count($fields), '?'));
        $ins = $conn->prepare("INSERT INTO `users` ($names) VALUES ($marks)");
        $ins->bind_param(str_repeat('s', count($fields)), ...array_values($fields));
        $ins->execute();
        $ins->close();

        $st = $conn->prepare('SELECT * FROM `users` WHERE `email` = ? LIMIT 1');
        $st->bind_param('s', $email);
        $st->execute();
        $user = $st->get_result()->fetch_assoc();
        $st->close();
    }

    // 4) Log in.
    session_regenerate_id(true);
    $_SESSION['username'] = $user['username'];
    $_SESSION['email']    = $user['email'];
    $_SESSION['role']     = $user['role'] ?? 'user';
    if (isset($user['id'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['id'] = $user['id'];
    }

    $isAdmin = strcasecmp((string)$_SESSION['role'], 'admin') === 0;
    if ($isAdmin) {
        // The admin panel (adminpanel/header.php) only lets you in when these are set.
        $_SESSION['AdminSession'] = $user['username'];
        if (isset($user['id'])) {
            $_SESSION['AdminID'] = $user['id'];
        }
    }

    $redirect = $isAdmin ? 'adminpanel/index.php' : 'index.php';
    gl_out(true, ['redirect' => $redirect]);
} catch (Throwable $e) {
    error_log('google-login.php: ' . $e->getMessage());
    gl_out(false, ['message' => 'Google sign-in failed: ' . $e->getMessage()], 500);
}