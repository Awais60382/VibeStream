<?php
/*
 * VibeStream - account safety helpers (login activity, forgot password, e-mail verification).
 * Put this file in the main VibeStream folder. It creates its own tables the first time it is used.
 * Nothing in here ever stops a page from loading: every function swallows its own errors.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$vsAsMailFile = __DIR__ . '/mail-config.php';
if (is_file($vsAsMailFile)) {
    require_once $vsAsMailFile;
}

function vs_as_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_activity (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL,
        success TINYINT(1) NOT NULL DEFAULT 1,
        ip VARCHAR(45) NOT NULL DEFAULT \'\',
        device VARCHAR(120) NOT NULL DEFAULT \'\',
        user_agent VARCHAR(255) NOT NULL DEFAULT \'\',
        is_new TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(60) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL,
        KEY idx_user_time (username, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL DEFAULT \'\',
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        ip VARCHAR(45) NOT NULL DEFAULT \'\',
        created_at DATETIME NOT NULL,
        UNIQUE KEY uq_token (token_hash),
        KEY idx_email_time (email, created_at),
        KEY idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS email_verifications (
        username VARCHAR(100) NOT NULL PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        token_hash CHAR(64) NULL,
        token_expires DATETIME NULL,
        last_sent DATETIME NULL,
        verified_at DATETIME NULL,
        KEY idx_token (token_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

/** The real column names of the users table (username, email, password), found the same way login-handler.php does. */
function vs_as_user_cols(PDO $pdo): array
{
    $cols = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $cols[strtolower((string)$r['Field'])] = (string)$r['Field'];
    }
    $pick = static function (array $names) use ($cols) {
        foreach ($names as $n) {
            if (isset($cols[$n])) {
                return $cols[$n];
            }
        }
        return null;
    };
    return [
        'user' => $pick(['username', 'user_name']),
        'email' => $pick(['email', 'email_address']),
        'pass' => $pick(['password', 'password_hash', 'pass']),
    ];
}

function vs_as_ip(): string
{
    return mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ------------------------------ CSRF ------------------------------ */
function vs_as_csrf_field(): string
{
    if (empty($_SESSION['vs_as_csrf'])) {
        $_SESSION['vs_as_csrf'] = bin2hex(random_bytes(32));
    }
    return '<input type="hidden" name="as_csrf" value="' . htmlspecialchars((string)$_SESSION['vs_as_csrf'], ENT_QUOTES, 'UTF-8') . '">';
}
function vs_as_csrf_ok(): bool
{
    return !empty($_SESSION['vs_as_csrf']) && hash_equals((string)$_SESSION['vs_as_csrf'], (string)($_POST['as_csrf'] ?? ''));
}

/* ------------------------- login activity ------------------------- */
function vs_as_device_label(string $ua): string
{
    $browser = 'Browser';
    foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $browser = $name;
            break;
        }
    }
    $os = 'unknown device';
    foreach (['iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) {
            $os = $name;
            break;
        }
    }
    return $browser . ' on ' . $os;
}

/** Save one sign-in (or failed sign-in) for this account. Never throws. */
function vs_login_log(?PDO $pdo, string $username, bool $success, string $note = ''): void
{
    if (!$pdo || $username === '') {
        return;
    }
    try {
        vs_as_ensure($pdo);
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $device = vs_as_device_label($ua);
        $ip = vs_as_ip();
        $isNew = 0;
        if ($success) {
            $st = $pdo->prepare('SELECT 1 FROM login_activity WHERE username = :u AND success = 1 AND ip = :ip AND device = :d LIMIT 1');
            $st->execute([':u' => $username, ':ip' => $ip, ':d' => $device]);
            $seenBefore = (bool)$st->fetchColumn();
            $any = $pdo->prepare('SELECT 1 FROM login_activity WHERE username = :u AND success = 1 LIMIT 1');
            $any->execute([':u' => $username]);
            // the very first logged sign-in is not "new device": there is nothing to compare with
            $isNew = (!$seenBefore && (bool)$any->fetchColumn()) ? 1 : 0;
        }
        $ins = $pdo->prepare('INSERT INTO login_activity (username, success, ip, device, user_agent, is_new, note, created_at)
                              VALUES (:u, :s, :ip, :d, :ua, :n, :note, NOW())');
        $ins->execute([':u' => mb_substr($username, 0, 100), ':s' => $success ? 1 : 0, ':ip' => $ip, ':d' => $device,
                       ':ua' => $ua, ':n' => $isNew, ':note' => mb_substr($note, 0, 60)]);
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM login_activity WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
        }
    } catch (Throwable $e) {
        error_log('vs_login_log: ' . $e->getMessage());
    }
}

function vs_login_history(PDO $pdo, string $username, int $limit = 50): array
{
    vs_as_ensure($pdo);
    $st = $pdo->prepare('SELECT success, ip, device, is_new, note, created_at FROM login_activity
                         WHERE username = :u ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([':u' => $username]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* ------------------------------ e-mail ------------------------------ */
function vs_as_mail_ready(): bool
{
    return defined('VS_MAIL_USER') && VS_MAIL_USER !== '' && defined('VS_MAIL_PASS') && VS_MAIL_PASS !== '';
}

/** The site address used inside e-mail links (from mail-config.php, never from the visitor's request). */
function vs_as_site_url(): string
{
    return defined('VS_SITE_URL') ? rtrim((string)VS_SITE_URL, '/') : '';
}

/** Send a plain-text e-mail through SMTP. Returns false instead of throwing. */
function vs_as_mail(string $to, string $subject, string $body): bool
{
    if (!vs_as_mail_ready() || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $fp = null;
    try {
        $host = (string)VS_MAIL_HOST;
        $port = (int)VS_MAIL_PORT;
        $ssl = $port === 465;
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !defined('VS_MAIL_VERIFY_TLS') || VS_MAIL_VERIFY_TLS,
            'verify_peer_name' => !defined('VS_MAIL_VERIFY_TLS') || VS_MAIL_VERIFY_TLS,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            error_log('vs_as_mail connect failed: ' . $errstr);
            return false;
        }
        stream_set_timeout($fp, 12);
        $read = static function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (strlen($line) < 4 || $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = static function (string $c) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            return $read();
        };
        $code = static function (string $r): int { return (int)substr($r, 0, 3); };

        if ($code($read()) !== 220) { return false; }
        $domain = 'localhost';
        if ($code($cmd('EHLO ' . $domain)) !== 250) { return false; }
        if (!$ssl) {
            if ($code($cmd('STARTTLS')) !== 220) { return false; }
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('vs_as_mail: TLS failed');
                return false;
            }
            if ($code($cmd('EHLO ' . $domain)) !== 250) { return false; }
        }
        if ($code($cmd('AUTH LOGIN')) !== 334) { return false; }
        if ($code($cmd(base64_encode((string)VS_MAIL_USER))) !== 334) { return false; }
        if ($code($cmd(base64_encode((string)VS_MAIL_PASS))) !== 235) {
            error_log('vs_as_mail: login to the mail server was refused (check VS_MAIL_USER / VS_MAIL_PASS)');
            return false;
        }
        $from = (string)(defined('VS_MAIL_FROM') && VS_MAIL_FROM !== '' ? VS_MAIL_FROM : VS_MAIL_USER);
        $clean = static function (string $s): string { return trim(str_replace(["\r", "\n"], ' ', $s)); };
        $fromName = $clean((string)(defined('VS_MAIL_FROM_NAME') ? VS_MAIL_FROM_NAME : 'VibeStream'));
        if ($code($cmd('MAIL FROM:<' . $clean($from) . '>')) !== 250) { return false; }
        $rc = $code($cmd('RCPT TO:<' . $clean($to) . '>'));
        if ($rc !== 250 && $rc !== 251) { return false; }
        if ($code($cmd('DATA')) !== 354) { return false; }

        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <' . $clean($from) . '>',
            'To: <' . $clean($to) . '>',
            'Subject: =?UTF-8?B?' . base64_encode($clean($subject)) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@vibestream.local>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];
        $text = str_replace(["\r\n", "\r"], "\n", $body);
        $text = preg_replace('/^\./m', '..', $text);
        $text = str_replace("\n", "\r\n", $text);
        fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . $text . "\r\n.\r\n");
        $ok = $code($read()) === 250;
        $cmd('QUIT');
        return $ok;
    } catch (Throwable $e) {
        error_log('vs_as_mail: ' . $e->getMessage());
        return false;
    } finally {
        if (is_resource($fp)) {
            @fclose($fp);
        }
    }
}

/* ------------------------- forgot password ------------------------- */
/**
 * Start a password reset for an e-mail address. Always behaves the same from the outside
 * (the page says "if an account exists..."), so nobody can use it to find out who has an account.
 * Returns false only when the site cannot send e-mail at all.
 */
function vs_reset_request(PDO $pdo, string $email): bool
{
    if (!vs_as_mail_ready() || vs_as_site_url() === '') {
        return false;
    }
    vs_as_ensure($pdo);
    $email = mb_strtolower(trim($email));
    $ip = vs_as_ip();

    // 3 requests per e-mail and 8 per address per hour
    $c = $pdo->prepare('SELECT SUM(email = :e) AS by_mail, SUM(ip = :ip) AS by_ip FROM password_resets
                        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
    $c->execute([':e' => $email, ':ip' => $ip]);
    $n = $c->fetch(PDO::FETCH_ASSOC) ?: [];
    if ((int)($n['by_mail'] ?? 0) >= 3 || (int)($n['by_ip'] ?? 0) >= 8) {
        return true;
    }

    $cols = vs_as_user_cols($pdo);
    $username = '';
    if ($cols['user'] && $cols['email']) {
        $u = $pdo->prepare('SELECT `' . $cols['user'] . '` AS u FROM `users` WHERE LOWER(`' . $cols['email'] . '`) = :e LIMIT 1');
        $u->execute([':e' => $email]);
        $username = (string)($u->fetchColumn() ?: '');
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $username !== '' ? $token : bin2hex(random_bytes(32)));
    $ins = $pdo->prepare('INSERT INTO password_resets (username, email, token_hash, expires_at, ip, created_at)
                          VALUES (:u, :e, :h, DATE_ADD(NOW(), INTERVAL 1 HOUR), :ip, NOW())');
    $ins->execute([':u' => $username, ':e' => $email, ':h' => $hash, ':ip' => $ip]);

    if ($username !== '') {
        $link = vs_as_site_url() . '/reset-password.php?token=' . $token;
        vs_as_mail($email, 'Reset your VibeStream password',
            "Hi " . $username . ",\n\n"
            . "Someone asked to reset the password of your VibeStream account. To choose a new password, open this link within 1 hour:\n\n"
            . $link . "\n\n"
            . "If you did not ask for this, you can ignore this e-mail: your password stays the same.\n");
    }
    return true;
}

/** The reset row for a link token, or null when it is wrong, used or expired. */
function vs_reset_lookup(PDO $pdo, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    vs_as_ensure($pdo);
    $st = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > NOW() AND username <> \'\' LIMIT 1');
    $st->execute([':h' => hash('sha256', $token)]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Save the new password and use up the link. */
function vs_reset_apply(PDO $pdo, array $row, string $newPassword): bool
{
    $cols = vs_as_user_cols($pdo);
    if (!$cols['user'] || !$cols['pass']) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $up = $pdo->prepare('UPDATE `users` SET `' . $cols['pass'] . '` = :p WHERE `' . $cols['user'] . '` = :u LIMIT 1');
        $up->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':u' => $row['username']]);
        $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE username = :u AND used_at IS NULL')->execute([':u' => $row['username']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('vs_reset_apply: ' . $e->getMessage());
        return false;
    }
    vs_login_log($pdo, (string)$row['username'], true, 'password reset');
    return true;
}

/* ------------------------ e-mail verification ------------------------ */
function vs_verify_status(PDO $pdo, string $username, string $email): bool
{
    if ($username === '' || $email === '') {
        return true; // nothing to verify
    }
    vs_as_ensure($pdo);
    $st = $pdo->prepare('SELECT 1 FROM email_verifications WHERE username = :u AND LOWER(email) = LOWER(:e) AND verified_at IS NOT NULL LIMIT 1');
    $st->execute([':u' => $username, ':e' => $email]);
    return (bool)$st->fetchColumn();
}

/** Send the verification link. Returns 'sent', 'wait' (asked too soon), 'nomail' (site e-mail not set up) or 'failed'. */
function vs_verify_send(PDO $pdo, string $username, string $email): string
{
    if (!vs_as_mail_ready() || vs_as_site_url() === '') {
        return 'nomail';
    }
    vs_as_ensure($pdo);
    $st = $pdo->prepare('SELECT last_sent FROM email_verifications WHERE username = :u AND LOWER(email) = LOWER(:e)');
    $st->execute([':u' => $username, ':e' => $email]);
    $last = $st->fetchColumn();
    if ($last && (time() - (int)strtotime((string)$last)) < 120) {
        return 'wait';
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO email_verifications (username, email, token_hash, token_expires, last_sent)
                   VALUES (:u, :e, :h, DATE_ADD(NOW(), INTERVAL 24 HOUR), NOW())
                   ON DUPLICATE KEY UPDATE verified_at = IF(LOWER(email) = LOWER(VALUES(email)), verified_at, NULL),
                   email = VALUES(email), token_hash = VALUES(token_hash),
                   token_expires = VALUES(token_expires), last_sent = NOW()')
        ->execute([':u' => $username, ':e' => $email, ':h' => hash('sha256', $token)]);
    $ok = vs_as_mail($email, 'Verify your VibeStream e-mail address',
        "Hi " . $username . ",\n\nPlease confirm that this e-mail address is yours by opening this link within 24 hours:\n\n"
        . vs_as_site_url() . '/verify-email.php?token=' . $token . "\n\nIf you did not create a VibeStream account, you can ignore this e-mail.\n");
    return $ok ? 'sent' : 'failed';
}

/** Confirm a link token. Returns the username on success, '' otherwise. */
function vs_verify_confirm(PDO $pdo, string $token): string
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return '';
    }
    vs_as_ensure($pdo);
    $st = $pdo->prepare('SELECT username FROM email_verifications WHERE token_hash = :h AND token_expires > NOW() LIMIT 1');
    $st->execute([':h' => hash('sha256', $token)]);
    $u = (string)($st->fetchColumn() ?: '');
    if ($u === '') {
        return '';
    }
    $pdo->prepare('UPDATE email_verifications SET verified_at = NOW(), token_hash = NULL, token_expires = NULL WHERE username = :u')->execute([':u' => $u]);
    return $u;
}
