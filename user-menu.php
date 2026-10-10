<?php
/**
 * VibeStream - signed-in user menu (avatar + name + caret with a dropdown),
 * styled like the admin panel's profile menu. Used by index.php, layout.php and header.php.
 */
if (!function_exists('vs_user_menu_html')) {
    function vs_user_menu_html(string $username, string $avatarUrl = ''): string
    {
        $e = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $initial = $username !== '' ? mb_strtoupper(mb_substr($username, 0, 1)) : 'G';

        $svg = function (string $paths): string {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
                 . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
        };
        $icons = [
            'profile' => $svg('<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>'),
            'subs'    => $svg('<rect x="3" y="5" width="18" height="12" rx="2"/><path d="M7 21h10M10 9l5 3-5 3z"/>'),
            'bell'    => $svg('<path d="M6 8a6 6 0 1 1 12 0c0 7 3 8 3 8H3s3-1 3-8"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>'),
            'admin'   => $svg('<path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/>'),
            'logout'  => $svg('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/>'),
            'caret'   => $svg('<path d="M6 9l6 6 6-6"/>'),
        ];

        // Only signed-in admins get the Admin panel shortcut (the panel itself re-checks access on the server).
        $isAdmin = !empty($_SESSION['AdminSession'])
            || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'admin') === 0);
        $adminItem = $isAdmin
            ? '<a class="vs-user-item" role="menuitem" href="adminpanel/index.php"><span class="vs-user-icon">' . $icons['admin'] . '</span><span>Admin panel</span></a>'
            : '';

        $avatar = '<span class="vs-user-avatar"><span class="vs-user-initial">' . $e($initial) . '</span>';
        if ($avatarUrl !== '') {
            $avatar .= '<img src="' . $e($avatarUrl) . '" alt="" onerror="this.remove()">';
        }
        $avatar .= '</span>';

        return '<div class="vs-user-menu" data-vs-user-menu>'
            . '<button type="button" class="vs-user-toggle" data-vs-user-toggle aria-haspopup="true" aria-expanded="false">'
            . $avatar
            . '<span class="vs-user-name">' . $e($username) . '</span>'
            . '<span class="vs-user-caret">' . $icons['caret'] . '</span>'
            . '</button>'
            . '<div class="vs-user-dropdown" role="menu" hidden>'
            . '<a class="vs-user-item" role="menuitem" href="profile.php"><span class="vs-user-icon">' . $icons['profile'] . '</span><span>Profile</span></a>'
            . '<a class="vs-user-item" role="menuitem" href="subscriptions.php"><span class="vs-user-icon">' . $icons['subs'] . '</span><span>Subscriptions</span></a>'
            . '<a class="vs-user-item" role="menuitem" href="notifications.php"><span class="vs-user-icon">' . $icons['bell'] . '</span><span>Notifications</span></a>'
            . $adminItem
            . '<div class="vs-user-divider"></div>'
            . '<a class="vs-user-item vs-user-danger" role="menuitem" href="logout.php"><span class="vs-user-icon">' . $icons['logout'] . '</span><span>Log out</span></a>'
            . '</div>'
            . '</div>';
    }
}