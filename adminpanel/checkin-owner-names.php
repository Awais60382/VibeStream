<?php
// Shared helper: names that belong to the site owner (the owner never appears in check-in lists).
if (!function_exists('vs_checkin_owner_names')) {
    function vs_checkin_owner_names($pdo = null) {
        $names = [];
        $add = function ($name) use (&$names) {
            $name = mb_strtolower(trim((string)$name));
            if ($name !== '') $names[$name] = true;
        };

        foreach (['VibeStream', 'VibeStreams'] as $name) $add($name);
        foreach (['Vibe Stream', 'VibeStreamOwner', 'VibeStream Owner'] as $name) $add($name);
        foreach (['username', 'AdminSession', 'name', 'user_name', 'full_name', 'display_name', 'AdminName'] as $key) {
            if (!empty($_SESSION[$key])) $add($_SESSION[$key]);
        }

        if ($pdo instanceof PDO && function_exists('vs_cols') && function_exists('vs_pick')) {
            try {
                $cols = vs_cols($pdo, 'users');
                $emailCol = vs_pick($cols, ['email', 'email_address', 'mail']);
                $nameCols = array_values(array_filter([
                    vs_pick($cols, ['username', 'user_name', 'login', 'uname']),
                    vs_pick($cols, ['name', 'full_name', 'display_name']),
                ]));
                if ($emailCol && $nameCols) {
                    $select = implode(', ', array_map(fn($col) => vs_q($col), array_unique(array_merge($nameCols, [$emailCol]))));
                    foreach ($pdo->query('SELECT ' . $select . ' FROM `users`') as $row) {
                        $email = trim((string)($row[$emailCol] ?? ''));
                        if ($email !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($email)) {
                            foreach ($nameCols as $nameCol) $add($row[$nameCol] ?? '');
                        }
                    }
                }
            } catch (Throwable $error) {
                error_log('Check-in owner names could not be loaded: ' . $error->getMessage());
            }
        }
        return $names;
    }
}
