<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
    http_response_code(403);
    exit('Administrator access required.');
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
/* Action items for the admin landing dashboard. */
require_once __DIR__ . '/checkin-lib.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

$overviewIsOwner = isset($pdo) && $pdo instanceof PDO && vs_is_owner($pdo);
$overviewAdmin = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
$dashboardOverview = [
    'owner' => $overviewIsOwner,
    'reports' => null,
    'premium' => null,
    'failedLogins' => null,
    'payroll' => null,
    'backup' => null,
    'unread' => 0,
    'tasks' => [],
    'tasksTotal' => 0,
    'online' => [],
    'onlineTotal' => 0,
    'activity' => [],
];

if (isset($pdo) && $pdo instanceof PDO) {
    if (vs_admin_route_allowed('reported.php') && function_exists('vs_reports_ensure_table')) {
        try {
            vs_reports_ensure_table($pdo);
            $dashboardOverview['reports'] = (int)$pdo->query(
                "SELECT COUNT(*) FROM `content_reports` WHERE LOWER(TRIM(`status`)) = 'open'"
            )->fetchColumn();
        } catch (Throwable $error) {
            error_log('Dashboard overview could not load reports: ' . $error->getMessage());
        }
    } elseif (vs_admin_route_allowed('reported.php')) {
        error_log('Dashboard overview could not load reports: report helpers are missing.');
    }

    if (vs_admin_route_allowed('premium.php')) {
        try {
            $premiumColumns = vs_cols($pdo, 'premium_requests');
            $premiumStatus = $premiumColumns ? vs_pick($premiumColumns, ['status', 'state', 'request_status']) : null;
            if ($premiumStatus) {
                $dashboardOverview['premium'] = (int)$pdo->query(
                    'SELECT COUNT(*) FROM `premium_requests` WHERE LOWER(TRIM(' . vs_q($premiumStatus) . ")) = 'pending'"
                )->fetchColumn();
            }
        } catch (Throwable $error) {
            error_log('Dashboard overview could not load premium requests: ' . $error->getMessage());
        }
    }

    if ($overviewIsOwner) {
        try {
            require_once __DIR__ . '/admin-alert-store.php';
            vs_admin_alerts_ensure($pdo);
            $failedLoginStmt = $pdo->query(
                "SELECT COUNT(*) FROM `admin_alerts`
                 WHERE `kind` = 'admin_login_failed' AND `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $dashboardOverview['failedLogins'] = (int)$failedLoginStmt->fetchColumn();
        } catch (Throwable $error) {
            error_log('Dashboard overview could not load failed sign-ins: ' . $error->getMessage());
        }

        try {
            $requiredPayColumns = [
                'admin_pay_settings' => ['username', 'rate'],
                'admin_pay_adjust' => ['month', 'username', 'hours'],
                'admin_pay_bonus' => ['month', 'username', 'amount'],
                'admin_pay_months' => ['month', 'username', 'status', 'paid_amount', 'override', 'note'],
                'admin_activity_log' => ['created_at', 'admin_username', 'action'],
            ];
            $payrollSchemaReady = true;
            foreach ($requiredPayColumns as $table => $columns) {
                $availableColumns = vs_cols($pdo, $table);
                foreach ($columns as $column) {
                    if (!vs_pick($availableColumns, [$column])) {
                        $payrollSchemaReady = false;
                        break 2;
                    }
                }
            }
            if ($payrollSchemaReady) {
                require_once __DIR__ . '/owner-notice-store.php';
                require_once __DIR__ . '/admin-pay-core.php';
                $payOwner = vs_notice_me($pdo);
                $payAdmins = vs_notice_admins($pdo, (int)$payOwner['id']);
                $paySettings = [];
                foreach ($pdo->query('SELECT * FROM `admin_pay_settings`')->fetchAll(PDO::FETCH_ASSOC) as $paySetting) {
                    $paySettings[mb_strtolower(trim((string)$paySetting['username']))] = $paySetting;
                }
                $currentPayroll = vs_pay_build($pdo, date('Y-m'), $payAdmins, $paySettings);
                $dashboardOverview['payroll'] = [
                    'pendingAdmins' => (int)$currentPayroll['tot']['pending'],
                    'pendingAmount' => (float)$currentPayroll['tot']['pendingAmt'],
                    'currency' => VS_PAY_CURRENCY,
                ];
            }
        } catch (Throwable $error) {
            error_log('Dashboard overview could not load pending payroll: ' . $error->getMessage());
        }

        try {
            require_once __DIR__ . '/backup-core.php';
            $backupFiles = vs_backup_files();
            $backupHistory = vs_backup_history();
            $lastBackup = isset($backupHistory[0]['created']) ? strtotime((string)$backupHistory[0]['created']) : false;
            if (!$lastBackup && $backupFiles) {
                $latestBackupPath = vs_backup_dir() . '/' . $backupFiles[0];
                $lastBackup = filemtime($latestBackupPath) ?: false;
            }
            $backupStatus = !$lastBackup
                ? 'missing'
                : (time() - $lastBackup >= VS_BACKUP_STALE_DAYS * 86400 ? 'overdue' : 'current');
            if (is_dir(vs_backup_dir()) && !is_writable(vs_backup_dir())) {
                $backupStatus = 'unavailable';
            }
            $dashboardOverview['backup'] = [
                'status' => $backupStatus,
                'lastBackup' => $lastBackup ? (int)$lastBackup : null,
                'files' => count($backupFiles),
            ];
        } catch (Throwable $error) {
            error_log('Dashboard overview could not load backup status: ' . $error->getMessage());
        }
    }

    try {
        if ($overviewIsOwner) {
            vs_activity_ensure($pdo);
        }
        $activityCols = vs_cols($pdo, 'admin_activity_log');
        if ($overviewIsOwner && $activityCols) {
            $activityDate = vs_pick($activityCols, ['created_at', 'timestamp', 'date']);
            $activityName = vs_pick($activityCols, ['admin_username', 'username', 'admin']);
            $activityType = vs_pick($activityCols, ['action', 'type', 'event']);
            $activityDetails = vs_pick($activityCols, ['details', 'description', 'message']);
            if ($activityDate && ($activityName || $activityType || $activityDetails)) {
                $select = [
                    $activityName ? vs_q($activityName) . ' AS admin_name' : "'' AS admin_name",
                    $activityType ? vs_q($activityType) . ' AS action_name' : "'' AS action_name",
                    $activityDetails ? vs_q($activityDetails) . ' AS action_details' : "'' AS action_details",
                    vs_q($activityDate) . ' AS action_date',
                ];
                $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM `admin_activity_log` ORDER BY ' . vs_q($activityDate) . ' DESC LIMIT 5');
                $dashboardOverview['activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $error) {
        error_log('Dashboard overview could not load recent admin activity: ' . $error->getMessage());
    }
}

if (isset($conn) && $conn instanceof mysqli &&
    function_exists('vs_admin_messages_unread') &&
    function_exists('vs_current_user_id')) {
    try {
        $dashboardOverview['unread'] = vs_admin_messages_unread($conn, vs_current_user_id($conn));
    } catch (Throwable $error) {
        error_log('Dashboard overview could not load unread admin messages: ' . $error->getMessage());
    }
}

$allOverviewTasks = vs_task_all();
foreach ($allOverviewTasks as $task) {
    if (($task['status'] ?? '') !== 'open') {
        continue;
    }
    if (!$overviewIsOwner && mb_strtolower(trim((string)($task['admin'] ?? ''))) !== mb_strtolower($overviewAdmin)) {
        continue;
    }
    $dashboardOverview['tasks'][] = $task;
}
$dashboardOverview['tasksTotal'] = count($dashboardOverview['tasks']);
usort($dashboardOverview['tasks'], static function (array $a, array $b): int {
    return (strtotime((string)($a['assigned_at'] ?? '')) ?: 0) <=> (strtotime((string)($b['assigned_at'] ?? '')) ?: 0);
});
$dashboardOverview['tasks'] = array_slice($dashboardOverview['tasks'], 0, 5);

foreach (vs_checkin_all() as $name => $record) {
    if (!is_array($record) || (!$overviewIsOwner && !empty($record['is_owner']))) {
        continue;
    }
    $status = vs_checkin_effective($record);
    if ($status === 'Offline') {
        continue;
    }
    $dashboardOverview['online'][] = [
        'name' => (string)$name,
        'status' => $status,
        'note' => trim((string)($record['note'] ?? '')),
    ];
}
usort($dashboardOverview['online'], static function (array $a, array $b): int {
    return strcasecmp($a['name'], $b['name']);
});
$dashboardOverview['onlineTotal'] = count($dashboardOverview['online']);
$dashboardOverview['online'] = array_slice($dashboardOverview['online'], 0, 6);
