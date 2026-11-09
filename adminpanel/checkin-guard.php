<?php
// Included by header.php (right after the AdminSession login check).
require_once __DIR__ . '/checkin-lib.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$vsAdmin = vs_checkin_admin_name();
$vsScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if ($vsScript === 'checkin.php') {
    return;
}

if (!$vsAdmin) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/task-store.php';
$vsOpenTasks = vs_task_open_for($vsAdmin);
$vsExpectedStatus = $vsOpenTasks ? 'Busy' : 'Available';
$vsCheckins = vs_checkin_all();
$vsRecord = is_array($vsCheckins[$vsAdmin] ?? null) ? $vsCheckins[$vsAdmin] : null;
$vsValidCheckin = vs_checkin_active($vsAdmin)
    && is_array($vsRecord)
    && (($vsRecord['status'] ?? '') === $vsExpectedStatus);

if (!$vsValidCheckin) {
    unset($_SESSION['vs_checkin'], $_SESSION['vs_checkin_for'], $_SESSION['vs_checkin_ts']);
    $vsNext = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($vsNext === '' || strpos($vsNext, '//') === 0 || strpos($vsNext, '\\') !== false || $vsNext[0] !== '/') {
        $vsNext = '';
    }
    $vsLoginUrl = vs_checkin_url() . ($vsNext !== '' ? '?next=' . urlencode($vsNext) : '');
    header('Location: ' . $vsLoginUrl);
    exit;
}

vs_checkin_touch($vsAdmin);