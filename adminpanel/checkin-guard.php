<?php
// Included by header.php (right after the AdminSession login check).
require_once __DIR__ . '/checkin-lib.php';

$vsAdmin = vs_checkin_admin_name();
if ($vsAdmin && basename($_SERVER['SCRIPT_NAME']) !== 'checkin.php') {
    if (!vs_checkin_active($vsAdmin)) {
        // New login, or away for 30+ minutes: must check in again (change allowed, removal never).
        unset($_SESSION['vs_checkin']);
        header('Location: ' . vs_checkin_url() . '?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
        exit;
    }
    vs_checkin_touch($vsAdmin);
}