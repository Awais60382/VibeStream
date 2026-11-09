<?php
/*
 * dashboard-alerts.php  (same folder as index.php)
 *
 * Admin-only JSON endpoint behind the dashboard's Alerts card and bell.
 *   dashboard-alerts.php            current alerts (also saves them to the history table)
 *   dashboard-alerts.php?history=1  the last 40 saved alerts
 * Telegram / email messages are sent by dashboard-alerts-cron.php, not from here.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isset($_SESSION['AdminSession'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
session_write_close(); // do not hold the session lock while we query

include_once __DIR__ . '/../connection.php';
if (!(isset($pdo) && $pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'no database connection']);
    exit;
}
require_once __DIR__ . '/dashboard-alerts-lib.php';

$JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE;
$usersTable = isset($USERS_TABLE) ? (string)$USERS_TABLE : 'users';
$historyOk = vsa_history_ensure($pdo);

if (isset($_GET['history'])) {
    try {
        echo json_encode(['ok' => $historyOk, 'history' => $historyOk ? vsa_history($pdo) : []], $JSON);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'history' => []]);
    }
    exit;
}

$r = vsa_run_checks($pdo, $usersTable);
if ($historyOk) {
    try {
        vsa_record($pdo, $r['alerts']);
    } catch (Throwable $e) {
        $r['skipped'][] = 'Alert history: could not save (check database permissions).';
    }
} else {
    $r['skipped'][] = 'Alert history: could not create the admin_alert_log table (check database permissions).';
}

$cfg = vsa_config();
$ch = vsa_channels($cfg);
echo json_encode([
    'ok' => true,
    'alerts' => $r['alerts'],
    'skipped' => $r['skipped'],
    'notify' => ['telegram' => $ch['telegram'], 'email' => $ch['email'], 'min' => $cfg['min_severity']],
    'ts' => time(),
], $JSON);