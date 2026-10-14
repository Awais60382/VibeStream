<?php
/**
 * adminpanel/admin-activity-store.php
 * Records what admins do in the admin panel (shown only to the site owner on admin-activity.php).
 *
 *   vs_admin_log($pdo, 'premium', 'Gave Premium (monthly) to sam');
 *
 * Logging never breaks a page: every function swallows its own errors.
 */

function vs_activity_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_username VARCHAR(100) NOT NULL,
            action VARCHAR(30) NOT NULL,
            details VARCHAR(500) NOT NULL DEFAULT \'\',
            page VARCHAR(100) NOT NULL DEFAULT \'\',
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL,
            KEY idx_admin_time (admin_username, created_at),
            KEY idx_time (created_at),
            KEY idx_action (action)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $done = true;
}

/** The name of the admin who is logged in right now (as stored in the session). */
function vs_activity_session_name(): string
{
    return trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
}

/**
 * Save one activity line.
 * $action: session | page_view | premium | video | user | settings | other
 */
function vs_admin_log(?PDO $pdo, string $action, string $details = '', string $admin = ''): void
{
    if (!$pdo) {
        return;
    }
    try {
        vs_activity_ensure($pdo);
        $admin = $admin !== '' ? $admin : vs_activity_session_name();
        if ($admin === '') {
            return;
        }
        $st = $pdo->prepare(
            'INSERT INTO admin_activity_log (admin_username, action, details, page, ip, created_at)
             VALUES (:a, :t, :d, :p, :ip, NOW())'
        );
        $st->execute([
            ':a'  => mb_substr($admin, 0, 100),
            ':t'  => mb_substr($action, 0, 30),
            ':d'  => mb_substr($details, 0, 500),
            ':p'  => mb_substr(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), 0, 100),
            ':ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ]);
        // Keep the table small: now and then drop lines older than 180 days.
        if (random_int(1, 100) === 1) {
            $pdo->exec('DELETE FROM admin_activity_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)');
        }
    } catch (Throwable $e) {
        // logging must never break the admin panel
    }
}

/** Friendly page names for the "opened a page" lines. */
function vs_activity_page_label(string $file): string
{
    $map = [
        'index.php' => 'Dashboard', 'videos.php' => 'Videos', 'shorts.php' => 'Shorts',
        'addvideos.php' => 'Add video', 'editvideos.php' => 'Edit video', 'categories.php' => 'Categories',
        'reported.php' => 'Reported content', 'users.php' => 'Users', 'admins.php' => 'Admins',
        'user_profile.php' => 'Users profile', 'messages.php' => 'Messages', 'premium.php' => 'Premium',
        'notifications.php' => 'Notifications', 'profile.php' => 'Profile',
        'platform-settings.php' => 'App settings',
    ];
    $k = strtolower($file);
    return $map[$k] ?? $file;
}

/**
 * Called from header.php on every admin page: one "started a session" line per login
 * and one "opened <page>" line per page (the same page is not logged again within 2 minutes).
 */
function vs_admin_log_page_view(?PDO $pdo, string $admin, string $file): void
{
    if (!$pdo || $admin === '' || strtolower($file) === 'admin-activity.php') {
        return;
    }
    if (empty($_SESSION['vs_activity_session_logged'])) {
        $_SESSION['vs_activity_session_logged'] = true;
        vs_admin_log($pdo, 'session', 'Started an admin panel session', $admin);
    }
    $last = $_SESSION['vs_activity_last_page'] ?? null;
    if (is_array($last) && ($last[0] ?? '') === $file && (time() - (int)($last[1] ?? 0)) < 120) {
        return;
    }
    $_SESSION['vs_activity_last_page'] = [$file, time()];
    vs_admin_log($pdo, 'page_view', 'Opened ' . vs_activity_page_label($file), $admin);
}
