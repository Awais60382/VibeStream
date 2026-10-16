<?php
/*
 * VibeStream admin panel - System check engine + failure alerts.
 * Put this file in the adminpanel folder (next to system-check.php).
 *
 *   vs_syscheck_run($pdo)       runs every check and returns ['groups' => ..., 'tally' => ...]
 *   vs_syscheck_record($result) saves the result; when a NEW problem appears it writes an alert
 *                               to the history and emails the owner (VS_OWNER_EMAIL)
 *   vs_syscheck_banner($pdo)    red bar for the owner on every admin page (call it from header.php)
 *
 * Nothing in here is allowed to break a page: the banner swallows its own errors.
 */

if (defined('VS_SYSCHECK_CORE_LOADED')) {
    return;
}
define('VS_SYSCHECK_CORE_LOADED', true);

require_once __DIR__ . '/owner-guard.php';

const VS_SYSCHECK_ICON_FONT_VERSION = '1.11.3';   // Bootstrap Icons version the repair button installs
const VS_SYSCHECK_EVERY = 300;        // the banner re-checks at most every 5 minutes
const VS_SYSCHECK_REMIND = 86400;     // email a reminder once a day while the same problem stays unacknowledged

function vs_syscheck_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/* ------------------------------------------------------------------ */
/* Saved state (a small file, so it still works when the database is down) */
/* ------------------------------------------------------------------ */

function vs_syscheck_dir(): string
{
    return __DIR__ . '/../backups';
}

function vs_syscheck_state_file(): string
{
    return vs_syscheck_dir() . '/system-alerts.json';
}

function vs_syscheck_state(): array
{
    $f = vs_syscheck_state_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = is_array($d) ? $d : [];
    return $d + ['last_run' => 0, 'signature' => '', 'bad' => [], 'acknowledged' => false, 'last_notified' => 0, 'history' => []];
}

/** Makes sure the private "backups" folder exists and is blocked from the web. */
function vs_syscheck_ensure_dir(): bool
{
    $dir = vs_syscheck_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return is_dir($dir);
}

function vs_syscheck_save(array $st): bool
{
    if (!vs_syscheck_ensure_dir()) {
        return false;
    }
    $st['history'] = array_slice((array)($st['history'] ?? []), 0, 30);
    return @file_put_contents(vs_syscheck_state_file(), json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

/* ------------------------------------------------------------------ */
/* E-mail: built-in SMTP sender (works on XAMPP, e.g. with a Gmail app password) */
/* ------------------------------------------------------------------ */

function vs_syscheck_mail_file(): string
{
    return vs_syscheck_dir() . '/mail-settings.json';
}

/** Settings guessed from the owner e-mail address, so only the password is left to type. */
function vs_syscheck_mail_defaults(): array
{
    $email = defined('VS_OWNER_EMAIL') ? strtolower(trim((string)VS_OWNER_EMAIL)) : '';
    $at = strrpos($email, '@');
    $domain = $at !== false ? substr($email, $at + 1) : '';
    $host = 'smtp.gmail.com';
    $port = 587;
    $secure = 'tls';
    if (in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com'], true)) {
        [$host, $port, $secure] = ['smtp-mail.outlook.com', 587, 'tls'];
    } elseif (strpos($domain, 'yahoo.') === 0) {
        [$host, $port, $secure] = ['smtp.mail.yahoo.com', 465, 'ssl'];
    } elseif (in_array($domain, ['icloud.com', 'me.com', 'mac.com'], true)) {
        [$host, $port, $secure] = ['smtp.mail.me.com', 587, 'tls'];
    }
    $valid = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    return ['host' => $host, 'port' => $port, 'secure' => $secure, 'user' => $valid, 'pass' => '', 'from' => $valid, 'verify' => true];
}

function vs_syscheck_mail_settings(): array
{
    $f = vs_syscheck_mail_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = is_array($d) ? $d : [];
    return $d + vs_syscheck_mail_defaults();
}

/** A certificate list for checking the mail server, found automatically on XAMPP. */
function vs_syscheck_ca_file(): string
{
    $candidates = [
        (string)ini_get('openssl.cafile'),
        (string)ini_get('curl.cainfo'),
        dirname(PHP_BINARY) . '/extras/ssl/cacert.pem',
        dirname(PHP_BINARY) . '/curl-ca-bundle.crt',
        dirname(PHP_BINARY) . '/../apache/bin/curl-ca-bundle.crt',
        __DIR__ . '/../../../php/extras/ssl/cacert.pem',
        __DIR__ . '/../../../apache/bin/curl-ca-bundle.crt',
    ];
    foreach ($candidates as $c) {
        if ($c !== '' && is_file($c)) {
            return $c;
        }
    }
    return '';
}

function vs_syscheck_mail_configured(): bool
{
    $c = vs_syscheck_mail_settings();
    return trim((string)$c['host']) !== '' && trim((string)$c['user']) !== '' && (string)$c['pass'] !== '';
}

function vs_syscheck_mail_save(array $c): bool
{
    if (!vs_syscheck_ensure_dir()) {
        return false;
    }
    return @file_put_contents(vs_syscheck_mail_file(), json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}

function vs_syscheck_mail_last_error(): string
{
    return (string)($GLOBALS['vs_syscheck_mail_error'] ?? '');
}

/** Talks SMTP directly. $err gets a readable reason when it fails. */
function vs_syscheck_smtp_send(array $c, string $to, string $subject, string $body, string &$err = ''): bool
{
    $host = trim((string)$c['host']);
    $port = (int)$c['port'];
    $secure = (string)$c['secure'];
    $verify = !empty($c['verify']);
    $from = filter_var(trim((string)($c['from'] ?? '')), FILTER_VALIDATE_EMAIL) ?: trim((string)$c['user']);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $err = 'The "From" address is not a valid e-mail. Type your full e-mail address in the username or From box.';
        return false;
    }
    $ssl = ['verify_peer' => $verify, 'verify_peer_name' => $verify, 'allow_self_signed' => !$verify];
    if ($verify && ($ca = vs_syscheck_ca_file()) !== '') {
        $ssl['cafile'] = $ca;
    }
    $ctx = stream_context_create(['ssl' => $ssl]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $err = 'Could not connect to ' . $host . ':' . $port . ' (' . trim((string)$errstr) . '). Check the host and port, your internet, and that a firewall is not blocking it.';
        return false;
    }
    stream_set_timeout($fp, 12);
    $read = static function () use ($fp): array {
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return [(int)substr($text, 0, 3), trim($text)];
    };
    $cmd = static function (string $line, array $okCodes, string $what) use ($fp, $read, &$err): bool {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $okCodes, true)) {
            $err = $what . ' was refused by the mail server: ' . ($text !== '' ? $text : 'no answer');
            return false;
        }
        return true;
    };
    $done = static function (bool $ok) use ($fp): bool {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
        return $ok;
    };

    [$code, $text] = $read();
    if ($code !== 220) {
        $err = 'The mail server did not greet us properly: ' . $text;
        return $done(false);
    }
    if (!$cmd('EHLO localhost', [250], 'Hello')) {
        return $done(false);
    }
    if ($secure === 'tls') {
        if (!$cmd('STARTTLS', [220], 'The secure (TLS) request')) {
            return $done(false);
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            $err = 'Could not start a secure connection. If the message mentions a certificate, tick "Skip certificate check" below (XAMPP often has no certificate list).';
            return $done(false);
        }
        if (!$cmd('EHLO localhost', [250], 'Hello (secure)')) {
            return $done(false);
        }
    }
    if (!$cmd('AUTH LOGIN', [334], 'Login') || !$cmd(base64_encode((string)$c['user']), [334], 'The username')) {
        return $done(false);
    }
    if (!$cmd(base64_encode((string)$c['pass']), [235], 'The password')) {
        $err = 'The mail server refused the login. For Gmail you must use an App Password (not your normal password). ' . $err;
        return $done(false);
    }
    if (!$cmd('MAIL FROM:<' . $from . '>', [250], 'The sender address') || !$cmd('RCPT TO:<' . $to . '>', [250, 251], 'The receiver address') || !$cmd('DATA', [354], 'Sending the message')) {
        return $done(false);
    }
    $msg = "Date: " . date('r') . "\r\n"
        . "From: VibeStream <" . $from . ">\r\n"
        . "To: <" . $to . ">\r\n"
        . "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
        . "Message-ID: <" . bin2hex(random_bytes(8)) . "@vibestream.local>\r\n"
        . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");
    fwrite($fp, $msg . "\r\n.\r\n");
    [$code, $text] = $read();
    if ($code !== 250) {
        $err = 'The mail server did not accept the message: ' . $text;
        return $done(false);
    }
    return $done(true);
}

/** Sends a plain e-mail to the owner: through your SMTP settings if filled in, otherwise PHP mail(). */
function vs_syscheck_mail(string $subject, string $body): bool
{
    $GLOBALS['vs_syscheck_mail_error'] = '';
    if (!defined('VS_OWNER_EMAIL')) {
        $GLOBALS['vs_syscheck_mail_error'] = 'No owner e-mail is set (VS_OWNER_EMAIL in owner-guard.php).';
        return false;
    }
    $to = trim((string)VS_OWNER_EMAIL);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $GLOBALS['vs_syscheck_mail_error'] = 'The owner e-mail in owner-guard.php is not a valid address.';
        return false;
    }
    try {
        if (vs_syscheck_mail_configured()) {
            $err = '';
            $ok = vs_syscheck_smtp_send(vs_syscheck_mail_settings(), $to, $subject, $body, $err);
            $GLOBALS['vs_syscheck_mail_error'] = $ok ? '' : $err;
            return $ok;
        }
        if (!function_exists('mail')) {
            $GLOBALS['vs_syscheck_mail_error'] = 'This PHP has no mail function. Fill in the e-mail settings.';
            return false;
        }
        $ok = (bool)@mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        if (!$ok) {
            $GLOBALS['vs_syscheck_mail_error'] = 'This server has no mail setup. Fill in the e-mail settings (SMTP) below.';
        }
        return $ok;
    } catch (Throwable $e) {
        $GLOBALS['vs_syscheck_mail_error'] = $e->getMessage();
        return false;
    }
}

/* ------------------------------------------------------------------ */
/* The checks                                                          */
/* ------------------------------------------------------------------ */

/** @return array{groups: array<string, array<int, array{0:string,1:string,2:string}>>, tally: array<string,int>} */
function vs_syscheck_run(?PDO $pdo): array
{
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    $groups = [];
    $fixes = [];   // problems the owner can repair with one button: [id, label]
    $tally = ['bad' => 0, 'warn' => 0, 'ok' => 0];
    $add = static function (string $group, string $label, string $status, string $detail = '') use (&$groups, &$tally): void {
        $groups[$group][] = [$label, $status, $detail];
        if (isset($tally[$status])) {
            $tally[$status]++;
        }
    };

    /* ---- Files that must sit in the main VibeStream folder ---- */
    $misplaced = [];
    foreach (vs_syscheck_expected_files() as $f) {
        if (is_file($root . '/' . $f)) {
            $add('Files in the main VibeStream folder', $f, 'ok');
        } else {
            $inAdmin = is_file(__DIR__ . '/' . $f);
            if ($inAdmin) {
                $misplaced[] = $f;
            }
            $add('Files in the main VibeStream folder', $f, 'bad', $inAdmin
                ? 'Missing here, but a copy is in the adminpanel folder. Use the repair button below to copy it to the main folder.'
                : 'Missing. Put this file in the main VibeStream folder (next to index.php).');
        }
    }
    if ($misplaced) {
        $fixes['move_files'] = 'Copy ' . count($misplaced) . ' file(s) from adminpanel to the main folder (' . implode(', ', array_slice($misplaced, 0, 4)) . (count($misplaced) > 4 ? ', ...' : '') . ')';
    }

    /* ---- Database ---- */
    $have = [];
    if (!($pdo instanceof PDO)) {
        $add('Database', 'Connection', 'bad', 'No database connection. Check that MySQL is running and that ../connection.php is correct.');
    } else {
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $ver = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
            $dbn = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
            $add('Database', 'Connection', 'ok', "Connected to \"{$dbn}\" (MySQL/MariaDB {$ver}).");
            $size = (float)$pdo->query('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = DATABASE()')->fetchColumn();
            $add('Database', 'Size', 'ok', number_format($size / 1048576, 2) . ' MB');
            $have = array_map('strtolower', $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
            $optional = ['premium_members', 'premium_requests'];
            foreach (['users', 'videos', 'comments', 'content_reports', 'premium_members', 'premium_requests', 'user_notifications', 'user_subscriptions', 'watch_history', 'video_likes'] as $t) {
                if (in_array($t, $have, true)) {
                    $add('Database', 'Table ' . $t, 'ok');
                } elseif (in_array($t, $optional, true)) {
                    $add('Database', 'Table ' . $t, 'warn', 'Not created yet. It is created automatically the first time Premium is used.');
                } else {
                    $add('Database', 'Table ' . $t, 'bad', 'Missing. Import your SQL backup or the original database file.');
                }
            }
            $trg = array_map('strtolower', $pdo->query('SHOW TRIGGERS')->fetchAll(PDO::FETCH_COLUMN));
            if (in_array('vs_users_lock_delete', $trg, true)) {
                $add('Database', 'Owner lock (admin protection)', 'ok', 'Installed.');
            } else {
                $add('Database', 'Owner lock (admin protection)', 'warn', 'Not installed. Use the button below (or open install-owner-lock.php once while signed in as the owner).');
                if (is_file(__DIR__ . '/install-owner-lock.php')) {
                    $fixes['owner_lock'] = 'Install the owner lock (opens install-owner-lock.php)';
                }
            }
            // The owner account must exist, otherwise every owner-only page locks everyone out.
            if (defined('VS_OWNER_EMAIL') && in_array('users', $have, true)) {
                $ucols = array_map('strtolower', $pdo->query('SHOW COLUMNS FROM `users`')->fetchAll(PDO::FETCH_COLUMN));
                $pick = static function (array $names) use ($ucols): ?string {
                    foreach ($names as $n) {
                        if (in_array($n, $ucols, true)) {
                            return $n;
                        }
                    }
                    return null;
                };
                $ec = $pick(['email', 'email_address', 'mail']);
                $rc = $pick(['role', 'user_role', 'usertype', 'user_type', 'account_type']);
                if ($ec && $rc) {
                    $st = $pdo->prepare("SELECT COUNT(*) FROM `users` WHERE LOWER(TRIM(`$ec`)) = LOWER(TRIM(?)) AND LOWER(TRIM(`$rc`)) = 'admin'");
                    $st->execute([(string)VS_OWNER_EMAIL]);
                    if ((int)$st->fetchColumn() > 0) {
                        $add('Database', 'Owner account', 'ok', 'An Admin account uses the owner e-mail.');
                    } else {
                        $add('Database', 'Owner account', 'bad', 'No Admin account uses the owner e-mail set in owner-guard.php, so owner-only pages (Backups, System check) are locked.');
                    }
                }
            }
        } catch (Throwable $e) {
            $add('Database', 'Connection', 'bad', 'The database check failed: ' . $e->getMessage());
        }
    }

    /* ---- Folders ---- */
    foreach (['uploads', 'uploads/site-branding', 'uploads/ui-icons', 'backups'] as $d) {
        $p = $root . '/' . $d;
        if (is_dir($p)) {
            $add('Folders the site writes to', $d, is_writable($p) ? 'ok' : 'bad', is_writable($p) ? '' : 'Not writable. Give the web server permission to write here.');
        } else {
            $parent = dirname($p);
            $add('Folders the site writes to', $d, is_writable($parent) ? 'ok' : 'warn',
                is_writable($parent) ? 'Does not exist yet. It is created when first needed.' : 'Does not exist and its parent folder is not writable.');
            if (is_writable($parent) && $d !== 'backups') {
                $fixes['make_folders'] = 'Create the missing uploads folders';
            }
        }
    }
    $free = @disk_free_space($root);
    if ($free !== false) {
        $status = $free < 200 * 1048576 ? 'bad' : ($free < 500 * 1048576 ? 'warn' : 'ok');
        $add('Folders the site writes to', 'Free disk space', $status,
            number_format($free / 1073741824, 1) . ' GB free' . ($status === 'bad' ? '. Almost full: uploads and backups will start failing.' : ''));
    }

    /* ---- Security ---- */
    $secrets = vs_syscheck_secret_files($root);
    if ($secrets) {
        $rootHt = is_file($root . '/.htaccess') ? (string)@file_get_contents($root . '/.htaccess') : '';
        foreach ($secrets as $s) {
            $base = basename($s);
            $blocked = $rootHt !== '' && (strpos($rootHt, $base) !== false
                || (stripos($base, 'client_secret') === 0 && strpos($rootHt, 'client_secret') !== false));
            if ($blocked) {
                $add('Security', $base, 'ok', 'Private file in the website folder, but blocked from the web by .htaccess. Safest is still to move it outside the website folder.');
            } else {
                $add('Security', $base, 'bad', 'This private file sits in the public website folder, so anyone who guesses the address can download it. Use the repair button below to block it (or move it outside the website folder).');
                $fixes['block_secrets'] = 'Block private files (client secret, .sql, .zip, .env) from the web';
            }
        }
    } else {
        $add('Security', 'Private files in the website folder', 'ok', 'No client secret, .sql, .zip or .env files found.');
    }
    if (is_dir($root . '/backups')) {
        $hasHt = is_file($root . '/backups/.htaccess');
        $add('Security', 'Backups folder is blocked from the web', $hasHt ? 'ok' : 'bad',
            $hasHt ? '' : 'The backups folder has no .htaccess. Use the repair button below, or open the Backups page once.');
        if (!$hasHt) {
            $fixes['backups_htaccess'] = 'Block the backups folder from the web';
        }
    }
    if (is_dir($root . '/uploads')) {
        $uh = $root . '/uploads/.htaccess';
        if (is_file($uh) && stripos((string)@file_get_contents($uh), 'php') !== false) {
            $add('Security', 'Uploads folder cannot run PHP', 'ok');
        } else {
            $add('Security', 'Uploads folder cannot run PHP', 'warn', 'If someone ever gets a .php file into uploads, the server would run it. Use the repair button below to block that.');
            $fixes['uploads_htaccess'] = 'Block PHP files inside the uploads folder';
        }
    }
    $dbUser = $GLOBALS['user'] ?? null;
    $dbPass = $GLOBALS['password'] ?? null;
    if ($dbUser === 'root' && $dbPass === '') {
        $add('Security', 'Database login', 'warn', 'The database uses "root" with no password. That is fine on your own computer, but change it before putting the site online.');
    }
    if (filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN)) {
        $add('Security', 'Error display', 'warn', 'PHP shows errors on the page. Turn display_errors off before going online.');
    }

    /* ---- PHP ---- */
    $phpOk = version_compare(PHP_VERSION, '7.4.0', '>=');
    $add('PHP', 'Version', $phpOk ? 'ok' : 'bad', PHP_VERSION . ($phpOk ? '' : ' (the site needs 7.4 or newer)'));
    $hints = ['gd' => 'Needed to check uploaded images.', 'zip' => 'Needed for full .zip backups.', 'zlib' => 'Needed for compressed .sql.gz backups.'];
    foreach (['pdo_mysql' => true, 'mysqli' => true, 'mbstring' => true, 'json' => true, 'fileinfo' => true, 'gd' => false, 'zip' => false, 'zlib' => false] as $ext => $required) {
        if (extension_loaded($ext)) {
            $add('PHP', 'Extension ' . $ext, 'ok');
        } else {
            $add('PHP', 'Extension ' . $ext, $required ? 'bad' : 'warn',
                ($hints[$ext] ?? 'Required by the site.') . ' Turn it on in php.ini (remove the ";" before extension=' . $ext . ') and restart Apache.');
        }
    }
    foreach (['upload_max_filesize', 'post_max_size', 'memory_limit', 'max_execution_time'] as $k) {
        $add('PHP', $k, 'ok', (string)ini_get($k));
    }
    $upMax = vs_syscheck_bytes((string)ini_get('upload_max_filesize'));
    $postMax = vs_syscheck_bytes((string)ini_get('post_max_size'));
    if ($postMax > 0 && $upMax > $postMax) {
        $add('PHP', 'Upload size settings', 'warn', 'upload_max_filesize is bigger than post_max_size, so large video uploads will fail. Make post_max_size at least as big in php.ini.');
    }
    $errLog = (string)ini_get('error_log');
    if ($errLog !== '' && is_file($errLog) && filesize($errLog) > 50 * 1048576) {
        $add('PHP', 'PHP error log', 'warn', number_format(filesize($errLog) / 1048576, 0) . ' MB. Open it to see what keeps failing, then empty it.');
    }

    /* ---- Icons: every icon the admin pages ask for must exist in the icon font, or it shows as a blank square ---- */
    $iconCss = __DIR__ . '/assets/vendors/bootstrap-icons/bootstrap-icons.css';
    if (!is_file($iconCss)) {
        $add('Icons', 'Admin icon font', 'warn', 'adminpanel/assets/vendors/bootstrap-icons/bootstrap-icons.css is missing, so admin icons are blank. Use the repair button below.');
        $fixes['icon_font'] = 'Download the admin icon font (Bootstrap Icons ' . VS_SYSCHECK_ICON_FONT_VERSION . ')';
    } else {
        $css = (string)@file_get_contents($iconCss);
        $used = [];
        foreach (glob(__DIR__ . '/*.php') ?: [] as $phpFile) {
            if (preg_match_all('/\bbi-([a-z0-9]+(?:-[a-z0-9]+)*)\b/', (string)@file_get_contents($phpFile), $mm)) {
                foreach ($mm[1] as $n) {
                    $used[$n] = true;
                }
            }
        }
        $missing = [];
        foreach (array_keys($used) as $n) {
            if (strpos($css, '.bi-' . $n . '::before') === false && strpos($css, '.bi-' . $n . ':before') === false) {
                $missing[] = $n;
            }
        }
        if (!$missing) {
            $add('Icons', 'Admin icon font', 'ok', count($used) . ' icons used in the admin panel, all found in the icon font.');
        } else {
            sort($missing);
            $add('Icons', 'Admin icon font', 'warn', 'These icons are missing from your icon font and show as blank squares: ' . implode(', ', array_slice($missing, 0, 12))
                . (count($missing) > 12 ? ', ...' : '') . '. Your icon font is too old. Use the repair button below.');
            $fixes['icon_font'] = 'Update the admin icon font (Bootstrap Icons ' . VS_SYSCHECK_ICON_FONT_VERSION . ')';
        }
    }
    $uiIconDir = $root . '/uploads/ui-icons';
    if (is_file($uiIconDir . '/icons.json')) {
        $bad = [];
        $iconMap = json_decode((string)@file_get_contents($uiIconDir . '/icons.json'), true);
        foreach (is_array($iconMap) ? $iconMap : [] as $iconName => $iconFile) {
            if (!is_file($uiIconDir . '/' . basename((string)$iconFile))) {
                $bad[] = (string)$iconName;
            }
        }
        $add('Icons', 'Replaced site icons', $bad ? 'warn' : 'ok',
            $bad ? 'These replaced icons point to a file that no longer exists (the built-in icon is shown instead): ' . implode(', ', $bad) : count((array)$iconMap) . ' replaced icon(s), all files present.');
    }

    /* ---- Backups ---- */
    $autoErr = (string)(vs_syscheck_state()['auto_backup_error'] ?? '');
    if ($autoErr !== '') {
        $add('Backups', 'Automatic backup', 'bad', 'The last automatic backup failed: ' . $autoErr);
    }
    $bfiles = glob($root . '/backups/vibestream-backup-*') ?: [];
    if (is_dir($root . '/backups')) {
        $bsize = 0;
        foreach ($bfiles as $bf) {
            $bsize += (int)@filesize($bf);
        }
        $add('Backups', 'Saved backups on the server', $bsize > 5 * 1073741824 ? 'warn' : 'ok',
            count($bfiles) . ' file' . (count($bfiles) === 1 ? '' : 's') . ', ' . number_format($bsize / 1048576, 1) . ' MB'
            . ($bsize > 5 * 1073741824 ? '. They take a lot of disk space; download the old ones and delete them.' : ''));
    }

    /* ---- Numbers (each one on its own, so one failure does not hide the others) ---- */
    if ($pdo instanceof PDO && $have) {
        $count = static function (string $sql) use ($pdo): ?int {
            try {
                return (int)$pdo->query($sql)->fetchColumn();
            } catch (Throwable $e) {
                return null;
            }
        };
        $rows = [
            ['Users', 'users', 'SELECT COUNT(*) FROM users'],
            ['Videos', 'videos', "SELECT COUNT(*) FROM videos WHERE type = 'video'"],
            ['Shorts', 'videos', "SELECT COUNT(*) FROM videos WHERE type = 'short'"],
        ];
        foreach ($rows as [$label, $table, $sql]) {
            if (in_array($table, $have, true) && ($v = $count($sql)) !== null) {
                $add('Numbers', $label, 'ok', (string)$v);
            }
        }
        if (in_array('content_reports', $have, true) && ($open = $count("SELECT COUNT(*) FROM content_reports WHERE status = 'open'")) !== null) {
            $add('Numbers', 'Open reports', $open > 0 ? 'warn' : 'ok', $open > 0 ? $open . ' waiting. Open Reported content.' : '0');
        }
        if (in_array('premium_requests', $have, true) && ($pend = $count("SELECT COUNT(*) FROM premium_requests WHERE status = 'pending'")) !== null) {
            $add('Numbers', 'Premium requests waiting', $pend > 0 ? 'warn' : 'ok', $pend > 0 ? $pend . ' waiting. Open Notifications.' : '0');
        }
    }
    $lastBackup = 0;
    $bh = $root . '/backups/history.json';
    if (is_file($bh)) {
        $arr = json_decode((string)@file_get_contents($bh), true);
        if (is_array($arr) && $arr) {
            $lastBackup = (int)strtotime((string)($arr[0]['created'] ?? ''));
        }
    }
    if ($lastBackup === 0) {
        $add('Numbers', 'Last backup', 'bad', 'Never. Use the repair button below, or open Backups and make one.');
        $fixes['make_backup'] = 'Make a backup now';
    } else {
        $days = (int)floor((time() - $lastBackup) / 86400);
        $add('Numbers', 'Last backup', $days >= 7 ? 'warn' : 'ok', date('M j, Y g:i A', $lastBackup) . ' (' . $days . ($days === 1 ? ' day' : ' days') . ' ago)');
        if ($days >= 7) {
            $fixes['make_backup'] = 'Make a backup now';
        }
    }

    return ['groups' => $groups, 'tally' => $tally, 'fixes' => $fixes];
}

/** Downloads a file over https (cURL, or file_get_contents). Returns null and sets $err on failure. */
function vs_syscheck_download(string $url, string &$err = ''): ?string
{
    $ca = vs_syscheck_ca_file();
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true]);
        if ($ca !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($out === false || $code !== 200) {
            $err = $out === false ? curl_error($ch) : 'The server answered ' . $code . '.';
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return (string)$out;
    }
    $ssl = ['verify_peer' => true];
    if ($ca !== '') {
        $ssl['cafile'] = $ca;
    }
    $out = @file_get_contents($url, false, stream_context_create(['ssl' => $ssl, 'http' => ['timeout' => 40]]));
    if ($out === false) {
        $err = 'The download failed (PHP cannot open https addresses here).';
        return null;
    }
    return $out;
}

/** Private files that should never sit in the public website folder. */
function vs_syscheck_secret_files(string $root): array
{
    return array_merge(glob($root . '/client_secret*.json') ?: [], glob($root . '/*.sql') ?: [], glob($root . '/*.zip') ?: [], glob($root . '/.env') ?: []);
}

/** Files that must sit in the main VibeStream folder. */
function vs_syscheck_expected_files(): array
{
    return [
        'connection.php', 'header.php', 'index.php', 'watch.php', 'shorts.php', 'profile.php', 'premium.php',
        'premium-store.php', 'premium.js', 'site-branding.php', 'category-icons.php', 'category-presets.php',
        'icons.php', 'sidebar-navigation.php', 'ui-icons.php', 'search-suggest.php', 'search-suggest.js',
        'search-suggest.css', 'report-widget.php', 'report-content.php', 'report-helpers.php', 'video-menu.js',
        'sidebar.js', 'manifest.php', 'branding-icon.php', 'sw.js',
    ];
}

/** "128M" / "2G" / "512K" from php.ini as bytes (0 = unlimited or unknown). */
function vs_syscheck_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') {
        return 0;
    }
    $n = (float)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return (int)$n;
}

/** One-click repairs. @return array{0:bool,1:string} [worked, message] */
function vs_syscheck_fix(string $id, ?PDO $pdo = null): array
{
    $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    if ($id === 'uploads_htaccess') {
        $dir = $root . '/uploads';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [false, 'Could not create the uploads folder.'];
        }
        $rules = "# VibeStream: files in uploads must never run as code\n"
            . "Options -Indexes\n"
            . "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp)$\">\n"
            . "  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
            . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n"
            . "</FilesMatch>\n";
        return @file_put_contents($dir . '/.htaccess', $rules) !== false
            ? [true, 'uploads/.htaccess created. PHP files in uploads are now blocked.']
            : [false, 'Could not write uploads/.htaccess. Check folder permissions.'];
    }
    if ($id === 'backups_htaccess') {
        return vs_syscheck_save(vs_syscheck_state())
            ? [true, 'The backups folder is now blocked from the web.']
            : [false, 'Could not write to the backups folder. Check folder permissions.'];
    }
    if ($id === 'make_folders') {
        $made = 0;
        foreach (['uploads', 'uploads/site-branding', 'uploads/ui-icons'] as $d) {
            if (!is_dir($root . '/' . $d) && @mkdir($root . '/' . $d, 0755, true)) {
                $made++;
            }
        }
        return [true, $made . ' folder(s) created.'];
    }
    if ($id === 'icon_font') {
        $dir = __DIR__ . '/assets/vendors/bootstrap-icons';
        $base = 'https://cdn.jsdelivr.net/npm/bootstrap-icons@' . VS_SYSCHECK_ICON_FONT_VERSION . '/font/';
        $files = ['bootstrap-icons.css' => 'bootstrap-icons.css', 'fonts/bootstrap-icons.woff2' => 'fonts/bootstrap-icons.woff2', 'fonts/bootstrap-icons.woff' => 'fonts/bootstrap-icons.woff'];
        $got = [];
        foreach ($files as $remote => $local) {
            $err = '';
            $data = vs_syscheck_download($base . $remote, $err);
            if ($data === null || strlen($data) < 20000) {
                return [false, 'Could not download ' . $remote . '. ' . ($err !== '' ? $err : 'The file was empty.') . ' Check that this computer is online (or download Bootstrap Icons ' . VS_SYSCHECK_ICON_FONT_VERSION . ' by hand and copy its css and fonts folder into adminpanel/assets/vendors/bootstrap-icons).'];
            }
            if ($remote === 'bootstrap-icons.css' && strpos($data, '.bi-heart-pulse::before') === false) {
                return [false, 'The downloaded icon file looks wrong, so nothing was changed.'];
            }
            $got[$local] = $data;
        }
        if (!is_dir($dir . '/fonts') && !@mkdir($dir . '/fonts', 0755, true) && !is_dir($dir . '/fonts')) {
            return [false, 'Could not create the icon font folder. Check folder permissions.'];
        }
        if (is_file($dir . '/bootstrap-icons.css') && !is_file($dir . '/bootstrap-icons.css.bak')) {
            @copy($dir . '/bootstrap-icons.css', $dir . '/bootstrap-icons.css.bak');   // keep the old one, just in case
        }
        foreach ($got as $local => $data) {
            if (@file_put_contents($dir . '/' . $local, $data) === false) {
                return [false, 'Could not write ' . $local . '. Check folder permissions.'];
            }
        }
        return [true, 'Icon font updated to Bootstrap Icons ' . VS_SYSCHECK_ICON_FONT_VERSION . '. Press Ctrl+F5 in the browser to see the new icons.'];
    }
    if ($id === 'block_secrets') {
        $ht = $root . '/.htaccess';
        $cur = is_file($ht) ? (string)@file_get_contents($ht) : '';
        $rules = '';
        foreach (vs_syscheck_secret_files($root) as $f) {
            $base = basename($f);
            if (strpos($base, '"') !== false || strpos($cur, $base) !== false) {
                continue;
            }
            $rules .= "<Files \"" . $base . "\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n"
                . "  <IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n  </IfModule>\n</Files>\n";
        }
        if ($rules === '') {
            return [false, 'Nothing to block.'];
        }
        $block = ($cur !== '' && substr($cur, -1) !== "\n" ? "\n" : '') . "\n# VibeStream private files: never send these to a browser\n" . $rules;
        return @file_put_contents($ht, $block, FILE_APPEND | LOCK_EX) !== false
            ? [true, 'Private files are now blocked from the web in .htaccess. For the best protection also move them outside the website folder.']
            : [false, 'Could not write the .htaccess file in the main VibeStream folder. Check folder permissions.'];
    }
    if ($id === 'move_files') {
        $copied = 0;
        foreach (vs_syscheck_expected_files() as $f) {
            if (!is_file($root . '/' . $f) && is_file(__DIR__ . '/' . $f) && @copy(__DIR__ . '/' . $f, $root . '/' . $f)) {
                $copied++;
            }
        }
        return [$copied > 0, $copied . ' file(s) copied to the main VibeStream folder. The copies in adminpanel were left in place; you can delete them.'];
    }
    if ($id === 'make_backup') {
        if (!($pdo instanceof PDO)) {
            return [false, 'No database connection, so no backup could be made.'];
        }
        try {
            require_once __DIR__ . '/backup-core.php';
            $format = class_exists('ZipArchive') ? 'zip' : (function_exists('gzopen') ? 'gz' : 'sql');
            $entry = vs_backup_create($pdo, $format, 'system check');
            $entry['kept'] = true;
            vs_backup_history_add($entry);
            vs_backup_prune();
            return [true, 'Backup saved on the server: ' . $entry['file'] . ' (' . vs_backup_human($entry['size']) . ').'];
        } catch (Throwable $e) {
            return [false, 'The backup failed: ' . $e->getMessage()];
        }
    }
    return [false, 'Unknown repair.'];
}

/* ------------------------------------------------------------------ */
/* Alerts                                                              */
/* ------------------------------------------------------------------ */

/**
 * Saves a finished check. A NEW set of problems writes an alert to the history and e-mails the owner.
 * The same unchanged problems are only re-sent once a day, and not at all after "Got it" was pressed.
 * @return array the saved state
 */
function vs_syscheck_record(array $result): array
{
    $bad = [];
    foreach ($result['groups'] as $group => $rows) {
        foreach ($rows as [$label, $status, $detail]) {
            if ($status === 'bad') {
                $bad[] = ['group' => (string)$group, 'label' => (string)$label, 'detail' => (string)$detail];
            }
        }
    }
    $names = array_map(static fn($b) => $b['group'] . ' | ' . $b['label'], $bad);
    sort($names);
    $sig = $bad ? md5(implode("\n", $names)) : '';

    $st = vs_syscheck_state();
    $now = time();
    $st['last_run'] = $now;
    $st['bad'] = $bad;
    $history = (array)$st['history'];

    if (!$bad) {
        if ($st['signature'] !== '') {
            array_unshift($history, ['time' => $now, 'type' => 'ok', 'text' => 'All problems are fixed.', 'emailed' => false]);
        }
        $st['signature'] = '';
        $st['acknowledged'] = false;
        $st['last_notified'] = 0;
    } else {
        $isNew = $sig !== $st['signature'];
        $remind = !$isNew && empty($st['acknowledged']) && ($now - (int)$st['last_notified']) >= VS_SYSCHECK_REMIND;
        if ($isNew || $remind) {
            $lines = [];
            foreach ($bad as $b) {
                $lines[] = '- ' . $b['label'] . ' (' . $b['group'] . '): ' . $b['detail'];
            }
            $count = count($bad);
            $emailed = vs_syscheck_mail(
                'VibeStream: ' . $count . ' problem' . ($count === 1 ? '' : 's') . ' found',
                "The VibeStream system check found " . $count . " problem" . ($count === 1 ? '' : 's') . ":\n\n"
                . implode("\n", $lines) . "\n\nOpen the admin panel > System check for details.\n"
            );
            if ($isNew) {
                $st['signature'] = $sig;
                $st['acknowledged'] = false;
                $short = array_map(static fn($b) => $b['label'], array_slice($bad, 0, 5));
                array_unshift($history, [
                    'time' => $now, 'type' => 'problem', 'emailed' => $emailed,
                    'text' => $count . ' problem' . ($count === 1 ? '' : 's') . ': ' . implode(', ', $short) . ($count > 5 ? ', ...' : ''),
                ]);
            }
            $st['last_notified'] = $now;
        }
    }
    $st['history'] = $history;
    vs_syscheck_save($st);
    return $st;
}

/** The red bar. Call from header.php. Only the owner ever sees it; it never throws. */
function vs_syscheck_banner(?PDO $pdo): void
{
    try {
        if (!($pdo instanceof PDO) || strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))) === 'system-check.php') {
            return;
        }
        if (!function_exists('vs_is_owner') || !vs_is_owner($pdo)) {
            return;
        }
        $st = vs_syscheck_state();
        $triedAt = (int)($_SESSION['vs_syscheck_try'] ?? 0);   // stops a re-check on every click if the file cannot be saved
        if ((time() - (int)$st['last_run']) >= VS_SYSCHECK_EVERY && (time() - $triedAt) >= 60) {
            $_SESSION['vs_syscheck_try'] = time();
            $st = vs_syscheck_record(vs_syscheck_run($pdo));
        }
        if (!$st['bad'] || !empty($st['acknowledged'])) {
            return;
        }
        $n = count($st['bad']);
        $first = $st['bad'][0];
        echo '<div class="container-fluid px-3 px-lg-4 pt-3"><div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0" role="alert">'
            . '<span><strong>System problem:</strong> ' . $n . ' thing' . ($n === 1 ? ' is' : 's are') . ' failing (e.g. '
            . vs_syscheck_h($first['label']) . ').</span>'
            . '<a class="btn btn-danger btn-sm" href="system-check.php">Open system check</a></div></div>';
    } catch (Throwable $e) {
        // the banner must never break an admin page
    }
}