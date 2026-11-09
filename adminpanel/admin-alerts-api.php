<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Administrator access required.']);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/admin-alert-store.php';

$admin = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'Admin')) ?: 'Admin';

function vs_admin_alert_count_window(PDO $pdo, string $table, string $dateColumn, string $from, string $to): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM ' . vs_q($table) . ' WHERE ' . vs_q($dateColumn) . ' >= :from AND ' . vs_q($dateColumn) . ' < :to'
    );
    $st->execute([':from' => $from, ':to' => $to]);
    return (int)$st->fetchColumn();
}

function vs_admin_alert_check_activity(PDO $pdo): void
{
    $users = vs_cols($pdo, 'users');
    $signupDate = $users ? vs_pick($users, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']) : null;
    if ($signupDate) {
        try {
            $window = (int)(floor(time() / 600) * 600);
            $start = date('Y-m-d H:i:s', $window);
            $end = date('Y-m-d H:i:s', $window + 600);
            $previousStart = date('Y-m-d H:i:s', $window - 600);
            $currentCount = vs_admin_alert_count_window($pdo, 'users', $signupDate, $start, $end);
            $previousCount = vs_admin_alert_count_window($pdo, 'users', $signupDate, $previousStart, $start);
            if ($currentCount >= 10 && ($previousCount === 0 || $currentCount >= $previousCount * 3)) {
                vs_admin_alert_add(
                    $pdo,
                    'signup-spike-' . $window,
                    'signup_spike',
                    'Signup spike detected',
                    number_format($currentCount) . ' new accounts were created in 10 minutes; the previous 10-minute window had ' . number_format($previousCount) . '.',
                    'index.php'
                );
            }
        } catch (Throwable $error) {
            error_log('Could not check signup spike alerts: ' . $error->getMessage());
        }
    }

    foreach (['videos', 'shorts'] as $table) {
        $columns = vs_cols($pdo, $table);
        $dateColumn = $columns ? vs_pick($columns, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at']) : null;
        if (!$dateColumn) {
            continue;
        }
        try {
            $now = time();
            $current = vs_admin_alert_count_window(
                $pdo,
                $table,
                $dateColumn,
                date('Y-m-d H:i:s', $now - 86400),
                date('Y-m-d H:i:s', $now + 1)
            );
            $previous = vs_admin_alert_count_window(
                $pdo,
                $table,
                $dateColumn,
                date('Y-m-d H:i:s', $now - 172800),
                date('Y-m-d H:i:s', $now - 86400)
            );
            if ($current === 0 && $previous >= 5) {
                vs_admin_alert_add(
                    $pdo,
                    'uploads-stopped-' . $table . '-' . date('Y-m-d'),
                    'upload_drop',
                    ucfirst($table) . ' uploads have stopped',
                    'There were ' . number_format($previous) . ' uploads in the previous 24 hours and none in the last 24 hours.',
                    'index.php'
                );
            }
        } catch (Throwable $error) {
            error_log('Could not check upload activity alerts for ' . $table . ': ' . $error->getMessage());
        }
    }
}

if (!in_array((string)($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['ok' => false, 'error' => 'Use GET or POST.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (empty($_SESSION['admin_alert_csrf']) || !hash_equals((string)$_SESSION['admin_alert_csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Session expired. Refresh and try again.']);
        exit;
    }
    if (($_POST['action'] ?? '') !== 'read_all') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unsupported alert action.']);
        exit;
    }
}

try {
    vs_admin_alerts_ensure($pdo);

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        vs_admin_alert_mark_all_read($pdo, $admin);
    } else {
        vs_admin_alert_check_activity($pdo);
        $diskTotal = @disk_total_space(__DIR__);
        $diskFree = @disk_free_space(__DIR__);
        if ($diskTotal && $diskFree !== false) {
            $usedPercent = (($diskTotal - $diskFree) / $diskTotal) * 100;
            if ($usedPercent >= 95 || $diskFree <= 512 * 1024 * 1024) {
                vs_admin_alert_add(
                    $pdo,
                    'disk-full-' . date('Y-m-d'),
                    'disk_full',
                    'Server disk is almost full',
                    number_format($usedPercent, 1) . '% used; ' . number_format($diskFree / 1073741824, 2) . ' GB remains free.',
                    'system-check.php'
                );
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'count' => vs_admin_alert_unread_count($pdo, $admin),
        'alerts' => vs_admin_alert_list($pdo, $admin),
        'ts' => time(),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (Throwable $error) {
    error_log('Admin alerts API failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Alerts are temporarily unavailable.']);
}
