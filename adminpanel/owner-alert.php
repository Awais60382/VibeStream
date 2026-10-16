<?php
/*
 * VibeStream - alerts to the site owner.
 * Put this file in the adminpanel folder (it needs system-check-core.php next to it).
 *
 *   require_once __DIR__ . '/adminpanel/owner-alert.php';      // from a file in the main folder
 *   require_once __DIR__ . '/owner-alert.php';                 // from a file inside adminpanel
 *
 *   vs_owner_alert($pdo, 'report', 'New report on a video', 'Reason: spam. Video #12');
 *
 * What it does: writes the alert to a private log (shown on the System check page) and e-mails the owner
 * with the e-mail settings from the System check page. It never breaks the page that calls it, and it
 * never e-mails more than 10 times an hour (everything is still logged).
 * Actions done by the owner himself are skipped (pass false as the last argument to force an alert).
 */

require_once __DIR__ . '/system-check-core.php';

if (!defined('VS_OWNER_ALERT_MAX_EMAILS_PER_HOUR')) {
    define('VS_OWNER_ALERT_MAX_EMAILS_PER_HOUR', 10);
}

function vs_owner_alert_file(): string
{
    return vs_syscheck_dir() . '/owner-alerts.json';
}

/** Newest first. Each entry: time, type, subject, details, actor, ip, emailed */
function vs_owner_alert_list(int $limit = 20): array
{
    $f = vs_owner_alert_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    return array_slice(is_array($d) ? $d : [], 0, max(1, $limit));
}

/**
 * Tell the owner something important happened.
 * $type is a short word (admin_added, admin_removed, account_deleted, blocked, report, premium, login, ...).
 */
function vs_owner_alert(?PDO $pdo, string $type, string $subject, string $details = '', bool $skipIfOwner = true): void
{
    try {
        if ($skipIfOwner && $pdo instanceof PDO && function_exists('vs_is_owner') && vs_is_owner($pdo)) {
            return;
        }
        $actor = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
        if ($actor === '') {
            $actor = PHP_SAPI === 'cli' ? 'system' : 'a visitor';
        }
        $ip = PHP_SAPI === 'cli' ? '' : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $now = time();

        $all = vs_owner_alert_list(100);
        $sentLastHour = 0;
        foreach ($all as $a) {
            if (!empty($a['emailed']) && ($now - (int)($a['time'] ?? 0)) < 3600) {
                $sentLastHour++;
            }
        }

        $subject = mb_substr(trim($subject), 0, 150);
        $details = mb_substr(trim($details), 0, 500);
        $emailed = false;
        if ($sentLastHour < VS_OWNER_ALERT_MAX_EMAILS_PER_HOUR) {
            $emailed = vs_syscheck_mail(
                'VibeStream alert: ' . $subject,
                $subject . "\n\n"
                . ($details !== '' ? $details . "\n\n" : '')
                . 'Who: ' . $actor . "\n"
                . 'When: ' . date('M j, Y g:i A', $now) . "\n"
                . ($ip !== '' ? 'IP: ' . $ip . "\n" : '')
                . "\nSee all alerts: admin panel > System check.\n"
            );
        }

        array_unshift($all, [
            'time' => $now, 'type' => mb_substr($type, 0, 30), 'subject' => $subject,
            'details' => $details, 'actor' => mb_substr($actor, 0, 100), 'ip' => $ip, 'emailed' => $emailed,
        ]);
        if (vs_syscheck_ensure_dir()) {
            @file_put_contents(vs_owner_alert_file(), json_encode(array_slice($all, 0, 100), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }
    } catch (Throwable $e) {
        // an alert must never break the page that raised it
    }
}

/**
 * Same as vs_owner_alert(), but runs AFTER the visitor already got the page, so a slow mail server
 * never makes a visitor wait. Use this in code that visitors trigger (reports, payments).
 */
function vs_owner_alert_after_response(?PDO $pdo, string $type, string $subject, string $details = '', bool $skipIfOwner = true): void
{
    register_shutdown_function(static function () use ($pdo, $type, $subject, $details, $skipIfOwner): void {
        try {
            ignore_user_abort(true);
            @set_time_limit(60);
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            vs_owner_alert($pdo, $type, $subject, $details, $skipIfOwner);
        } catch (Throwable $e) {
            // never a problem for the visitor
        }
    });
}

/* ------------------------------------------------------------------ */
/* Login watch: repeated wrong passwords, and sign-ins after many failures */
/* ------------------------------------------------------------------ */

function vs_login_watch_file(): string
{
    return vs_syscheck_dir() . '/login-failures.json';
}

function vs_login_watch_load(): array
{
    $f = vs_login_watch_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = is_array($d) ? $d : [];
    return $d + ['acct' => [], 'ip' => [], 'signup' => [], 'alerted' => []];
}

function vs_login_watch_save(array $d): void
{
    $cut = time() - 3600;
    foreach (['acct', 'ip', 'signup'] as $k) {
        foreach ($d[$k] as $name => $times) {
            $times = array_values(array_filter((array)$times, static fn($t) => (int)$t > $cut));
            if ($times) {
                $d[$k][$name] = $times;
            } else {
                unset($d[$k][$name]);
            }
        }
        $d[$k] = array_slice($d[$k], -300, null, true);
    }
    $d['alerted'] = array_filter((array)$d['alerted'], static fn($t) => (int)$t > $cut);
    if (vs_syscheck_ensure_dir()) {
        @file_put_contents(vs_login_watch_file(), json_encode($d), LOCK_EX);
    }
}

function vs_login_recent(array $times, int $seconds = 600): int
{
    $since = time() - $seconds;
    return count(array_filter($times, static fn($t) => (int)$t > $since));
}

/** True when this address already failed 10 times in the last 10 minutes. */
function vs_login_blocked(string $ip): bool
{
    try {
        $d = vs_login_watch_load();
        return $ip !== '' && vs_login_recent((array)($d['ip'][$ip] ?? [])) >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Call after a wrong password. $account is the real account name if the typed name exists, otherwise ''
 * (a name that does not exist is never saved: it could be a password typed in the wrong box).
 */
function vs_login_failed(string $account, string $ip): void
{
    try {
        $now = time();
        $d = vs_login_watch_load();
        $key = strtolower($account);
        if ($account !== '') {
            $d['acct'][$key][] = $now;
        }
        if ($ip !== '') {
            $d['ip'][$ip][] = $now;
        }
        $quiet = static fn(string $k): bool => ($now - (int)($d['alerted'][$k] ?? 0)) >= 1800;
        if ($account !== '' && vs_login_recent((array)$d['acct'][$key]) >= 5 && $quiet('a:' . $key)) {
            $d['alerted']['a:' . $key] = $now;
            vs_owner_alert_after_response(null, 'login', 'Repeated failed logins on account "' . $account . '"',
                vs_login_recent((array)$d['acct'][$key]) . ' wrong passwords in 10 minutes' . ($ip !== '' ? '. Last address: ' . $ip : '') . '.', false);
        }
        if ($ip !== '' && vs_login_recent((array)$d['ip'][$ip]) >= 10 && $quiet('i:' . $ip)) {
            $d['alerted']['i:' . $ip] = $now;
            vs_owner_alert_after_response(null, 'login', 'Many failed logins from one address',
                vs_login_recent((array)$d['ip'][$ip]) . ' wrong passwords in 10 minutes from ' . $ip . '. That address is blocked from signing in for a few minutes.', false);
        }
        vs_login_watch_save($d);
    } catch (Throwable $e) {
        // never a problem for the login page
    }
}

/** Call after a correct password. Alerts when it came right after several wrong ones. */
function vs_login_succeeded(string $account): void
{
    try {
        $key = strtolower($account);
        $d = vs_login_watch_load();
        $n = vs_login_recent((array)($d['acct'][$key] ?? []));
        if ($n >= 3) {
            vs_owner_alert_after_response(null, 'login', '"' . $account . '" signed in after ' . $n . ' failed attempts',
                'If this was not them, someone may have guessed the password.', false);
        }
        unset($d['acct'][$key]);
        vs_login_watch_save($d);
    } catch (Throwable $e) {
        // never a problem for the login page
    }
}

/* ------------------------------------------------------------------ */
/* New sign-ups                                                        */
/* ------------------------------------------------------------------ */

/** True when this address already created 10 accounts in the last hour. */
function vs_signup_blocked(string $ip): bool
{
    try {
        $d = vs_login_watch_load();
        return $ip !== '' && vs_login_recent((array)($d['signup'][$ip] ?? []), 3600) >= 10;
    } catch (Throwable $e) {
        return false;
    }
}

/** Call after an account was created: tells the owner, and warns when one address makes many accounts. */
function vs_signup_done(string $username, string $email, string $ip): void
{
    try {
        $now = time();
        $d = vs_login_watch_load();
        if ($ip !== '') {
            $d['signup'][$ip][] = $now;
        }
        $n = $ip !== '' ? vs_login_recent((array)$d['signup'][$ip], 3600) : 1;
        vs_owner_alert_after_response(null, 'signup', 'New user joined: ' . $username,
            'E-mail: ' . $email . ($ip !== '' ? '. Address: ' . $ip : '') . '.', false);
        if ($n >= 5 && ($now - (int)($d['alerted']['s:' . $ip] ?? 0)) >= 1800) {
            $d['alerted']['s:' . $ip] = $now;
            vs_owner_alert_after_response(null, 'signup', 'Many sign-ups from one address',
                $n . ' accounts were created from ' . $ip . ' in the last hour. This can be a bot. At 10 in an hour, new sign-ups from that address are blocked.', false);
        }
        vs_login_watch_save($d);
    } catch (Throwable $e) {
        // never a problem for the sign-up page
    }
}