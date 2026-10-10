<?php
/**
 * Frees a user name after an account is deleted.
 *
 * Every table that stores a user name (found automatically, same columns the
 * profile page checks when renaming) is cleaned:
 *   - subscriber columns: the deleted user's subscriptions are removed
 *   - everything else (videos, shorts, comments, ...): the name is replaced by
 *     $placeholder, so the old content stays but the name is free to use again
 *     and a new owner of the name does not inherit it.
 *
 * Premium membership rows of the deleted user are removed too, so they vanish from the Members list.
 *
 * Returns the number of rows changed, or -1 if a live account still uses the name.
 */
if (!function_exists('vs_release_username')) {
    function vs_release_username(PDO $pdo, string $username, string $placeholder): int
    {
        $username = trim($username);
        if ($username === '') {
            return 0;
        }

        $nameColumns = ['username', 'user_name', 'uploader', 'channel', 'channel_name', 'subscriber', 'subscriber_name',
                        'author', 'owner', 'commenter', 'posted_by', 'created_by'];
        $in = implode(',', array_map(static fn($c) => "'" . $c . "'", $nameColumns));
        $q = static function (string $id): string { return '`' . str_replace('`', '``', $id) . '`'; };

        $sql = "SELECT c.TABLE_NAME, c.COLUMN_NAME
                FROM information_schema.COLUMNS c
                JOIN information_schema.TABLES t
                  ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
                WHERE c.TABLE_SCHEMA = DATABASE()
                  AND c.DATA_TYPE IN ('varchar','char','tinytext','text','mediumtext','longtext')
                  AND c.COLUMN_NAME IN ($in)";
        $targets = $pdo->query($sql)->fetchAll(PDO::FETCH_NUM);

        // Never touch anything while a real account still owns this name.
        foreach ($targets as [$table, $column]) {
            if ($table !== 'users') {
                continue;
            }
            $st = $pdo->prepare('SELECT 1 FROM ' . $q('users') . ' WHERE LOWER(' . $q($column) . ') = LOWER(?) LIMIT 1');
            $st->execute([$username]);
            if ($st->fetchColumn()) {
                return -1;
            }
        }

        $changed = 0;
        foreach ($targets as [$table, $column]) {
            if ($table === 'users') {
                continue;
            }
            try {
                if (in_array($column, ['subscriber', 'subscriber_name'], true) || preg_match('/premium/i', (string)$table)) {
                    $st = $pdo->prepare('DELETE FROM ' . $q($table) . ' WHERE LOWER(' . $q($column) . ') = LOWER(?)');
                    $st->execute([$username]);
                } else {
                    $st = $pdo->prepare('UPDATE ' . $q($table) . ' SET ' . $q($column) . ' = ? WHERE LOWER(' . $q($column) . ') = LOWER(?)');
                    $st->execute([$placeholder, $username]);
                }
                $changed += $st->rowCount();
            } catch (Throwable $e) {
                error_log('release username failed on ' . $table . '.' . $column . ': ' . $e->getMessage());
            }
        }

        // Profile picture is stored under a key made from the name.
        $key = md5(mb_strtolower($username));
        foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
            $p = __DIR__ . '/../uploads/avatars/' . $key . '.' . $ext;
            if (is_file($p)) {
                @unlink($p);
            }
        }

        return $changed;
    }
}

/**
 * One-time cleanup: removes premium rows that were already renamed to "deleted_xxxx"
 * by an earlier cleanup. Returns the number of rows removed.
 */
if (!function_exists('vs_purge_deleted_premium')) {
    function vs_purge_deleted_premium(PDO $pdo): int
    {
        $nameColumns = ['username', 'user_name', 'uploader', 'channel', 'channel_name', 'subscriber', 'subscriber_name',
                        'author', 'owner', 'commenter', 'posted_by', 'created_by'];
        $in = implode(',', array_map(static fn($c) => "'" . $c . "'", $nameColumns));
        $q = static function (string $id): string { return '`' . str_replace('`', '``', $id) . '`'; };
        $targets = $pdo->query(
            "SELECT c.TABLE_NAME, c.COLUMN_NAME
             FROM information_schema.COLUMNS c
             JOIN information_schema.TABLES t
               ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
             WHERE c.TABLE_SCHEMA = DATABASE()
               AND c.DATA_TYPE IN ('varchar','char','tinytext','text','mediumtext','longtext')
               AND c.COLUMN_NAME IN ($in)"
        )->fetchAll(PDO::FETCH_NUM);

        $removed = 0;
        foreach ($targets as [$table, $column]) {
            if (!preg_match('/premium/i', (string)$table)) {
                continue;
            }
            try {
                $st = $pdo->prepare('DELETE FROM ' . $q($table) . ' WHERE ' . $q($column) . ' LIKE ?');
                $st->execute(['deleted\\_%']);
                $removed += $st->rowCount();
            } catch (Throwable $e) {
                error_log('purge premium failed on ' . $table . '.' . $column . ': ' . $e->getMessage());
            }
        }
        return $removed;
    }
}