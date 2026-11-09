<?php
/*
 * dashboard-alerts-cron.php  (same folder as index.php)
 *
 * Run this from a scheduled job (cron) every 5 minutes. It checks the site, saves the alert
 * history, and sends new warning/critical alerts to Telegram and/or email.
 *
 *   Cron line:   0-59/5 * * * * php /full/path/to/admin/dashboard-alerts-cron.php
 *                (that means every 5 minutes; in cPanel use Cron Jobs and choose "Once Per Five Minutes")
 *   Test setup:  php dashboard-alerts-cron.php --test      sends one test message
 *   See output:  php dashboard-alerts-cron.php --verbose
 *
 * It does nothing when opened in a browser.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$verbose = in_array('--verbose', $argv ?? [], true);
$test = in_array('--test', $argv ?? [], true);

include_once __DIR__ . '/../connection.php';
if (!(isset($pdo) && $pdo instanceof PDO)) {
    fwrite(STDERR, "alerts: no database connection from ../connection.php\n");
    exit(1);
}
require_once __DIR__ . '/dashboard-alerts-lib.php';

$cfg = vsa_config();
$ch = vsa_channels($cfg);

if ($test) {
    if (!$ch['telegram'] && !$ch['email']) {
        fwrite(STDERR, "Nothing is set up yet. Fill in dashboard-alerts-config.php first.\n");
        exit(1);
    }
    $text = '[TEST] ' . $cfg['site_name'] . ": alerts are connected. You will get a message like this when something needs attention.\n" . date('Y-m-d H:i:s');
    $failed = false;
    if ($ch['telegram']) {
        [$ok, $err] = vsa_send_telegram($cfg, $text);
        echo 'Telegram: ' . ($ok ? 'sent' : 'FAILED - ' . $err) . "\n";
        $failed = $failed || !$ok;
    } else {
        echo "Telegram: not set up\n";
    }
    if ($ch['email']) {
        [$ok, $err] = vsa_send_email($cfg, $cfg['site_name'] . ': test alert', $text);
        echo 'Email: ' . ($ok ? 'sent' : 'FAILED - ' . $err) . "\n";
        $failed = $failed || !$ok;
    } else {
        echo "Email: not set up\n";
    }
    exit($failed ? 1 : 0);
}

if (!vsa_history_ensure($pdo)) {
    fwrite(STDERR, "alerts: could not create the admin_alert_log table (check database permissions)\n");
    exit(1);
}

$usersTable = isset($USERS_TABLE) ? (string)$USERS_TABLE : 'users';
$r = vsa_run_checks($pdo, $usersTable);
vsa_record($pdo, $r['alerts']);
$n = vsa_notify_pending($pdo, $cfg);

if ($verbose) {
    echo date('Y-m-d H:i:s') . ' alerts now: ' . count($r['alerts']) . ', sent: ' . $n['sent'] . ', failed: ' . $n['failed'] . "\n";
    foreach ($r['skipped'] as $s) {
        echo '  skipped: ' . $s . "\n";
    }
}
foreach ($n['errors'] as $e) {
    fwrite(STDERR, 'alerts: ' . $e . "\n"); // cron mails this to you
}
exit($n['failed'] > 0 ? 1 : 0);
