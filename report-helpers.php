<?php
/**
 * Shared bits for "report a harmful video or short".
 * Put this file in the main VibeStream folder (next to connection.php).
 * It does not load any other VibeStream file, so it is safe to include from watch.php and shorts.php.
 */

if (!function_exists('vs_report_pdo')) {
    /**
     * Returns a PDO connection for the report system.
     * Uses $pdo from connection.php when it exists; otherwise looks for any PDO object,
     * then builds one from the usual connection.php variables ($servername/$host, $username/$user,
     * $password/$pass, $dbname/$database) or DB_* constants.
     */
    function vs_report_pdo(): ?PDO
    {
        static $made = null;
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if ($made instanceof PDO) {
            return $made;
        }
        foreach ($GLOBALS as $v) {
            if ($v instanceof PDO) {
                return $made = $v;
            }
        }
        $pick = static function (array $vars, array $consts) {
            foreach ($vars as $n) {
                if (isset($GLOBALS[$n]) && is_string($GLOBALS[$n])) {
                    return $GLOBALS[$n];
                }
            }
            foreach ($consts as $c) {
                if (defined($c)) {
                    return (string)constant($c);
                }
            }
            return null;
        };
        $host = $pick(['servername', 'host', 'db_host', 'dbhost', 'hostname', 'server'], ['DB_HOST', 'DB_SERVER']);
        $usr  = $pick(['db_user', 'dbuser', 'db_username', 'dbusername', 'user', 'username'], ['DB_USER', 'DB_USERNAME']);
        $pw   = $pick(['db_pass', 'dbpass', 'db_password', 'dbpassword', 'password', 'pass', 'pw'], ['DB_PASS', 'DB_PASSWORD']);
        $name = $pick(['dbname', 'database', 'db_name', 'db', 'dbName'], ['DB_NAME', 'DB_DATABASE']);
        if ($host === null || $usr === null || $name === null) {
            return null;
        }
        try {
            $made = new PDO(
                'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
                $usr,
                $pw ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return $made;
        } catch (Throwable $e) {
            error_log('vs_report_pdo failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('vsr_q')) {
    function vsr_q(string $id): string
    {
        return '`' . str_replace('`', '', $id) . '`';
    }
}

if (!function_exists('vsr_cols')) {
    /** Column names of a table, keyed by lower-case name. */
    function vsr_cols(PDO $pdo, string $table): array
    {
        $out = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM ' . vsr_q($table));
            if ($stmt) {
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $out[strtolower($row['Field'])] = $row['Field'];
                }
            }
        } catch (Throwable $e) {
            return [];
        }
        return $out;
    }
}

if (!function_exists('vsr_pick')) {
    function vsr_pick(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (isset($cols[$c])) {
                return $cols[$c];
            }
        }
        return null;
    }
}

if (!function_exists('vs_report_reasons')) {
    function vs_report_reasons(): array
    {
        return [
            'sexual'    => 'Sexual content',
            'violence'  => 'Violent or repulsive content',
            'hate'      => 'Hateful or abusive content',
            'dangerous' => 'Harmful or dangerous acts',
            'spam'      => 'Spam or misleading',
            'other'     => 'Something else',
        ];
    }
}

if (!function_exists('vs_reports_ensure_table')) {
    function vs_reports_ensure_table(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `content_reports` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `content_table` VARCHAR(20) NOT NULL,
                `content_id` VARCHAR(64) NOT NULL,
                `content_type` VARCHAR(10) NOT NULL,
                `reason` VARCHAR(30) NOT NULL,
                `details` VARCHAR(500) NOT NULL DEFAULT '',
                `reporter` VARCHAR(100) NOT NULL,
                `status` VARCHAR(12) NOT NULL DEFAULT 'open',
                `created_at` DATETIME NOT NULL,
                `reviewed_by` VARCHAR(100) NULL,
                `reviewed_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_report_per_user` (`content_table`, `content_id`, `reporter`),
                KEY `status_idx` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

/** Looks one video/short up by id in one table. Returns null when it does not exist. */
if (!function_exists('vs_reports_lookup')) {
    function vs_reports_lookup(PDO $pdo, string $table, string $id): ?array
    {
        if (!in_array($table, ['videos', 'shorts'], true)) {
            return null;
        }
        $c = vsr_cols($pdo, $table);
        $idC = $c ? vsr_pick($c, ['id', 'video_id']) : null;
        if (!$idC) {
            return null;
        }
        $tC = vsr_pick($c, ['title', 'name']);
        $wC = vsr_pick($c, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
        $kC = vsr_pick($c, ['type', 'video_type']);
        $sel = [
            $tC ? vsr_q($tC) . ' AS t' : "'' AS t",
            $wC ? vsr_q($wC) . ' AS w' : "'' AS w",
            $kC ? vsr_q($kC) . ' AS k' : "'' AS k",
        ];
        $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . ' FROM ' . vsr_q($table) . ' WHERE ' . vsr_q($idC) . ' = :i LIMIT 1');
        $st->execute([':i' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $isShort = $table === 'shorts' || stripos((string)$row['k'], 'short') === 0;
        return [
            'table' => $table,
            'id'    => $id,
            'type'  => $isShort ? 'short' : 'video',
            'title' => trim((string)$row['t']) !== '' ? trim((string)$row['t']) : 'Untitled',
            'who'   => trim((string)$row['w']),
        ];
    }
}

/** Finds a video or short by id. $kind ('video' or 'short') only decides which table is tried first. */
if (!function_exists('vs_reports_find')) {
    function vs_reports_find(PDO $pdo, string $id, string $kind = 'video'): ?array
    {
        $order = $kind === 'short' ? ['shorts', 'videos'] : ['videos', 'shorts'];
        foreach ($order as $t) {
            $r = vs_reports_lookup($pdo, $t, $id);
            if ($r) {
                return $r;
            }
        }
        return null;
    }
}