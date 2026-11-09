<?php
/**
 * Shared database and lookup helpers for reporting harmful videos or shorts.
 */

if (!function_exists('vs_report_pdo')) {
    function vs_report_pdo(): ?PDO
    {
        static $made = null;
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
        if ($made instanceof PDO) {
            return $made;
        }
        foreach ($GLOBALS as $value) {
            if ($value instanceof PDO) {
                return $made = $value;
            }
        }

        $pick = static function (array $vars, array $consts): ?string {
            foreach ($vars as $name) {
                if (isset($GLOBALS[$name]) && is_string($GLOBALS[$name])) {
                    return $GLOBALS[$name];
                }
            }
            foreach ($consts as $name) {
                if (defined($name)) {
                    return (string)constant($name);
                }
            }
            return null;
        };
        $host = $pick(['servername', 'host', 'db_host', 'dbhost', 'hostname', 'server'], ['DB_HOST', 'DB_SERVER']);
        $user = $pick(['db_user', 'dbuser', 'db_username', 'dbusername', 'user', 'username'], ['DB_USER', 'DB_USERNAME']);
        $password = $pick(['db_pass', 'dbpass', 'db_password', 'dbpassword', 'password', 'pass', 'pw'], ['DB_PASS', 'DB_PASSWORD']);
        $database = $pick(['dbname', 'database', 'db_name', 'db', 'dbName'], ['DB_NAME', 'DB_DATABASE']);
        if ($host === null || $user === null || $database === null) {
            return null;
        }

        try {
            $made = new PDO(
                'mysql:host=' . $host . ';dbname=' . $database . ';charset=utf8mb4',
                $user,
                $password ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            return $made;
        } catch (Throwable $error) {
            error_log('Report database connection failed: ' . $error->getMessage());
            return null;
        }
    }
}

if (!function_exists('vsr_q')) {
    function vsr_q(string $identifier): string
    {
        return '`' . str_replace('`', '', $identifier) . '`';
    }
}

if (!function_exists('vsr_cols')) {
    function vsr_cols(PDO $pdo, string $table): array
    {
        try {
            $statement = $pdo->query('SHOW COLUMNS FROM ' . vsr_q($table));
            $columns = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $column) {
                $columns[strtolower((string)$column['Field'])] = $column['Field'];
            }
            return $columns;
        } catch (Throwable $error) {
            return [];
        }
    }
}

if (!function_exists('vsr_pick')) {
    function vsr_pick(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $columns[$candidate];
            }
        }
        return null;
    }
}

if (!function_exists('vs_report_reasons')) {
    function vs_report_reasons(): array
    {
        return [
            'sexual' => 'Sexual content',
            'violence' => 'Violent or repulsive content',
            'hate' => 'Hateful or abusive content',
            'dangerous' => 'Harmful or dangerous acts',
            'spam' => 'Spam or misleading',
            'other' => 'Something else',
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
                `assigned_to` VARCHAR(100) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `one_report_per_user` (`content_table`, `content_id`, `reporter`),
                KEY `status_idx` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $columns = vsr_cols($pdo, 'content_reports');
        if ($columns && !isset($columns['assigned_to'])) {
            $pdo->exec('ALTER TABLE `content_reports` ADD COLUMN `assigned_to` VARCHAR(100) NULL');
        }
    }
}

if (!function_exists('vs_reports_lookup')) {
    function vs_reports_lookup(PDO $pdo, string $table, string $id): ?array
    {
        if (!in_array($table, ['videos', 'shorts'], true)) {
            return null;
        }
        $columns = vsr_cols($pdo, $table);
        $idColumn = $columns ? vsr_pick($columns, ['id', 'video_id']) : null;
        if (!$idColumn) {
            return null;
        }
        $titleColumn = vsr_pick($columns, ['title', 'name']);
        $authorColumn = vsr_pick($columns, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
        $typeColumn = vsr_pick($columns, ['type', 'video_type']);
        $fields = [
            $titleColumn ? vsr_q($titleColumn) . ' AS t' : "'' AS t",
            $authorColumn ? vsr_q($authorColumn) . ' AS w' : "'' AS w",
            $typeColumn ? vsr_q($typeColumn) . ' AS k' : "'' AS k",
        ];
        $statement = $pdo->prepare(
            'SELECT ' . implode(', ', $fields) . ' FROM ' . vsr_q($table) .
            ' WHERE ' . vsr_q($idColumn) . ' = :id LIMIT 1'
        );
        $statement->execute([':id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'table' => $table,
            'id' => $id,
            'type' => $table === 'shorts' || stripos((string)$row['k'], 'short') === 0 ? 'short' : 'video',
            'title' => trim((string)$row['t']) !== '' ? trim((string)$row['t']) : 'Untitled',
            'who' => trim((string)$row['w']),
        ];
    }
}

if (!function_exists('vs_reports_find')) {
    function vs_reports_find(PDO $pdo, string $id, string $kind = 'video'): ?array
    {
        $tables = $kind === 'short' ? ['shorts', 'videos'] : ['videos', 'shorts'];
        foreach ($tables as $table) {
            $report = vs_reports_lookup($pdo, $table, $id);
            if ($report !== null) {
                return $report;
            }
        }
        return null;
    }
}
