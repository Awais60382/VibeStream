<?php
/**
 * Admin action watcher. Put this file in the adminpanel folder, next to owner-alert.php.
 *
 *   vs_admin_watch($pdo, 'premium', 'Approved Premium payment request #12');
 *
 * It does two things in one call:
 *   1. writes the action to the Admin activity log (same as vs_admin_log), and
 *   2. when the person doing it is NOT the site owner, sends the owner an alert, so the owner
 *      always hears about changes an admin made on their own.
 * The owner's own actions are logged but never alert the owner.
 */
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';
require_once __DIR__ . '/owner-alert.php';

if (!function_exists('vs_admin_watch')) {
    function vs_admin_watch(PDO $pdo, string $type, string $summary, bool $alertOwner = true): void
    {
        try {
            if (function_exists('vs_admin_log')) {
                vs_admin_log($pdo, $type, $summary);
            }
        } catch (Throwable $e) {
            error_log('vs_admin_watch log failed: ' . $e->getMessage());
        }
        if (!$alertOwner) {
            return;
        }
        try {
            if (!function_exists('vs_owner_alert') || !function_exists('vs_is_owner') || vs_is_owner($pdo)) {
                return;
            }
            $who = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? ''));
            $who = $who !== '' ? $who : 'An admin';
            vs_owner_alert(
                $pdo,
                'admin_action',
                'Admin action: ' . mb_substr($who . ' - ' . $summary, 0, 150),
                'This was done by an admin, not by you. Open Admin activity to see the full log.'
            );
        } catch (Throwable $e) {
            error_log('vs_admin_watch alert failed: ' . $e->getMessage());
        }
    }
}
