<?php
/** Admin access roles. Unassigned admins retain their existing full admin access. */
const VS_ADMIN_ACCESS_ROLES = [
    'full_admin' => 'Full Admin',
    'content_moderator' => 'Content Moderator',
    'support' => 'Support',
    'finance' => 'Finance',
    'custom' => 'Custom sections',
];

const VS_ADMIN_ACCESS_SECTIONS = [
    'content' => [
        'label' => 'Content',
        'routes' => ['products.php', 'videos.php', 'editvideos.php', 'shorts.php', 'content_page.php', 'categories.php', 'video-categories.php', 'reported.php', 'context-notes.php'],
    ],
    'users' => [
        'label' => 'User support',
        'routes' => ['Users.php', 'banned-users.php', 'user_profile.php'],
    ],
    'finance' => [
        'label' => 'Finance',
        'routes' => ['premium.php', 'admin-premium.php', 'profit-loss.php', 'notifications.php', 'mark_premium_alerts_seen.php'],
    ],
    'analytics' => [
        'label' => 'Analytics',
        'routes' => ['insights.php', 'insights-people.php', 'dashboard-insights-api.php', 'upload-failures.php', 'top-creators.php'],
    ],
    'admin_management' => [
        'label' => 'Admin management',
        'routes' => ['admins.php'],
    ],
];

function vs_admin_access_file(): string
{
    return __DIR__ . '/data/admin-access.json';
}

function vs_admin_access_sections_file(): string
{
    return __DIR__ . '/data/admin-access-sections.json';
}

/** @return array<string,list<string>> user ID => explicitly selected section keys */
function vs_admin_access_sections_all(): array
{
    $file = vs_admin_access_sections_file();
    if (!is_file($file)) {
        return [];
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Could not read the custom admin access settings.');
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        throw new RuntimeException('The custom admin access settings are invalid JSON.');
    }
    $sections = [];
    foreach ($data as $id => $assigned) {
        $id = (string)$id;
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $id) || !is_array($assigned)) {
            throw new RuntimeException('The custom admin access settings contain an invalid assignment.');
        }
        foreach ($assigned as $section) {
            if (!is_string($section) || !isset(VS_ADMIN_ACCESS_SECTIONS[$section])) {
                throw new RuntimeException('The custom admin access settings contain an invalid section.');
            }
        }
        $sections[$id] = array_values(array_unique($assigned));
    }
    return $sections;
}

/** Null means the account still uses its preset role; an empty list means no optional sections. */
function vs_admin_access_sections(string $userId): ?array
{
    $all = vs_admin_access_sections_all();
    return array_key_exists($userId, $all) ? $all[$userId] : null;
}

function vs_admin_access_set_sections(string $userId, array $sections): void
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $userId)) {
        throw new RuntimeException('Choose a valid admin account.');
    }
    foreach ($sections as $section) {
        if (!is_string($section) || !isset(VS_ADMIN_ACCESS_SECTIONS[$section])) {
            throw new RuntimeException('Choose valid admin sections.');
        }
    }
    $all = vs_admin_access_sections_all();
    $all[$userId] = array_values(array_unique($sections));

    $dir = dirname(vs_admin_access_sections_file());
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the protected admin settings folder.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess) && file_put_contents($htaccess, "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not protect the admin settings folder.');
    }
    $index = $dir . '/index.html';
    if (!is_file($index) && file_put_contents($index, '', LOCK_EX) === false) {
        throw new RuntimeException('Could not protect the admin settings folder.');
    }
    $encoded = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents(vs_admin_access_sections_file(), $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not save the custom admin access settings.');
    }
}

/** @return array<string,string> database user ID => role */
function vs_admin_access_all(): array
{
    $file = vs_admin_access_file();
    if (!is_file($file)) {
        return [];
    }
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Could not read the admin access settings.');
    }
    $data = json_decode($contents);
    if (!$data instanceof stdClass) {
        throw new RuntimeException('The admin access settings are invalid JSON.');
    }
    $roles = [];
    foreach (get_object_vars($data) as $id => $role) {
        $id = (string)$id;
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $id)
            || !is_string($role)
            || !isset(VS_ADMIN_ACCESS_ROLES[$role])
            || $role === 'full_admin') {
            throw new RuntimeException('The admin access settings contain an invalid role assignment.');
        }
        $roles[$id] = $role;
    }
    return $roles;
}

function vs_admin_access_role(string $userId): string
{
    return vs_admin_access_all()[$userId] ?? 'full_admin';
}

function vs_admin_access_default_sections(string $role): array
{
    $defaults = [
        'content_moderator' => ['content'],
        'support' => ['users'],
        'finance' => ['finance'],
    ];
    return $defaults[$role] ?? ($role === 'full_admin' ? array_keys(VS_ADMIN_ACCESS_SECTIONS) : []);
}

function vs_admin_access_set(string $userId, string $role): void
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $userId) || !isset(VS_ADMIN_ACCESS_ROLES[$role])) {
        throw new RuntimeException('Choose a valid admin access role.');
    }
    $roles = vs_admin_access_all();
    if ($role === 'full_admin') {
        unset($roles[$userId]);
    } else {
        $roles[$userId] = $role;
    }

    $dir = dirname(vs_admin_access_file());
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the protected admin settings folder.');
    }
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess) && file_put_contents($htaccess, "Require all denied\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not protect the admin settings folder.');
    }
    $index = $dir . '/index.html';
    if (!is_file($index) && file_put_contents($index, '', LOCK_EX) === false) {
        throw new RuntimeException('Could not protect the admin settings folder.');
    }
    $encoded = json_encode((object)$roles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || file_put_contents(vs_admin_access_file(), $encoded, LOCK_EX) === false) {
        throw new RuntimeException('Could not save the admin access role.');
    }
}

function vs_admin_access_user_id(): string
{
    foreach (['AdminID', 'AdminId', 'user_id', 'id', 'userid', 'uid'] as $key) {
        if (isset($_SESSION[$key]) && is_scalar($_SESSION[$key]) && (string)$_SESSION[$key] !== '') {
            return (string)$_SESSION[$key];
        }
    }
    return '';
}

function vs_admin_route_allowed(string $route, ?string $role = null, ?array $sections = null): bool
{
    $userId = vs_admin_access_user_id();
    $role = $role ?? vs_admin_access_role($userId);
    if ($role === 'full_admin') {
        return true;
    }
    $common = [
        'index.php', 'profile.php', 'my-pay.php', 'my-tasks.php', 'checkin.php', 'checkin-ping.php',
        'messages.php', 'mark_notifications_read.php', 'admin-alerts-api.php', 'failed-admin-logins.php',
    ];
    if (in_array($route, $common, true)) {
        return true;
    }
    if ($role === 'custom') {
        $selectedSections = $sections ?? vs_admin_access_sections($userId) ?? [];
        foreach ($selectedSections as $section) {
            if (in_array($route, VS_ADMIN_ACCESS_SECTIONS[$section]['routes'], true)) {
                return true;
            }
        }
        return false;
    }
    $allowed = [
        'content_moderator' => [
            'products.php', 'videos.php', 'editvideos.php', 'shorts.php', 'content_page.php',
            'categories.php', 'video-categories.php', 'reported.php', 'context-notes.php',
        ],
        'support' => ['Users.php', 'banned-users.php', 'user_profile.php'],
        'finance' => ['premium.php', 'admin-premium.php', 'profit-loss.php', 'notifications.php', 'mark_premium_alerts_seen.php'],
    ];
    return in_array($route, $allowed[$role] ?? [], true);
}

/** Enforce the signed-in admin's assigned page access before page actions execute. */
function vs_admin_enforce_route(): void
{
    if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
        return;
    }
    try {
        $route = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (vs_admin_route_allowed($route)) {
            return;
        }
    } catch (Throwable $error) {
        error_log('VibeStream admin access check failed: ' . $error->getMessage());
        http_response_code(500);
        exit('Admin access settings could not be verified. Contact the site owner.');
    }
    http_response_code(403);
    exit('Access denied. Your assigned admin role does not include this page.');
}
