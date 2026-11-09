<?php
/**
 * Liked videos store. Put this file in the SITE ROOT (next to watch.php).
 *
 * Likes used to live only in the PHP session, so they were lost when a member signed out or the
 * session expired, and a member could like the same video again after signing back in.
 * This file saves each signed-in member's likes by account ID in the `video_likes` table (created
 * automatically) and keeps $_SESSION['liked_videos'] in step with it. Guests still use the session only.
 *
 *   vs_likes_is_member()            true for a signed-in (non-guest) account
 *   vs_likes_session_sync($conn)    call once per request, after session_start()
 *   vs_likes_has($conn, $id)        has the current visitor liked this video?
 *   vs_likes_set($conn, $id, $on)   save / remove a like for the current member
 *   vs_likes_ids($conn)             liked video ids, newest first
 */
if (!function_exists('vs_likes_ids')) {

    function vs_likes_user(): string
    {
        $username = trim((string)($_SESSION['username'] ?? ''));
        return $username !== '' ? $username : trim((string)($_SESSION['AdminSession'] ?? ''));
    }

    function vs_likes_name_key(): string
    {
        $username = mb_strtolower(vs_likes_user());
        if (strlen('name:' . $username) <= 120) {
            return 'name:' . $username;
        }
        return 'name:' . hash('sha256', $username);
    }

    function vs_likes_account_key(): string
    {
        foreach (['AdminId', 'AdminID', 'user_id', 'id'] as $key) {
            if (!isset($_SESSION[$key]) || !is_scalar($_SESSION[$key])) {
                continue;
            }
            $id = trim((string)$_SESSION[$key]);
            if ($id !== '' && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $id)) {
                return 'id:' . $id;
            }
        }

        return vs_likes_name_key();
    }

    function vs_likes_is_member(): bool
    {
        $isAdminSession = trim((string)($_SESSION['AdminSession'] ?? '')) !== '';
        return vs_likes_user() !== ''
            && ($isAdminSession || strcasecmp((string)($_SESSION['role'] ?? ''), 'guest') !== 0);
    }

    /** Creates the table the first time it is needed. */
    function vs_likes_ensure_table(mysqli $conn): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            if (!$conn->query(
                'CREATE TABLE IF NOT EXISTS `video_likes` ('
                . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
                . ' `username` VARCHAR(100) NOT NULL,'
                . ' `video_id` VARCHAR(100) NOT NULL,'
                . ' `account_id` VARCHAR(120) NULL DEFAULT NULL,'
                . ' `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
                . ' UNIQUE KEY `uniq_user_video` (`username`, `video_id`),'
                . ' UNIQUE KEY `uniq_account_video` (`account_id`, `video_id`),'
                . ' KEY `idx_user_created` (`username`, `created_at`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            )) {
                return $ok = false;
            }

            $columns = $conn->query("SHOW COLUMNS FROM `video_likes` LIKE 'account_id'");
            if (!$columns || $columns->num_rows === 0) {
                if (!$conn->query('ALTER TABLE `video_likes` ADD COLUMN `account_id` VARCHAR(120) NULL DEFAULT NULL')) {
                    return $ok = false;
                }
            }

            $index = $conn->query("SHOW INDEX FROM `video_likes` WHERE `Key_name` = 'uniq_account_video'");
            if (!$index || $index->num_rows === 0) {
                if (!$conn->query('ALTER TABLE `video_likes` ADD UNIQUE KEY `uniq_account_video` (`account_id`, `video_id`)')) {
                    return $ok = false;
                }
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('video_likes table could not be prepared: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }

    /** Moves username-keyed likes into the member's stable account key. */
    function vs_likes_migrate_legacy(mysqli $conn): void
    {
        if (!vs_likes_is_member()) {
            return;
        }
        $user = vs_likes_user();
        $accountKey = vs_likes_account_key();
        $oldNameKey = vs_likes_name_key();
        $select = $conn->prepare(
            'SELECT `id` FROM `video_likes` WHERE `username` = ? AND (`account_id` IS NULL OR `account_id` = ?)'
        );
        if (!$select) {
            throw new RuntimeException('Could not prepare the saved-like migration.');
        }
        $select->bind_param('ss', $user, $oldNameKey);
        if (!$select->execute()) {
            $select->close();
            throw new RuntimeException('Could not load legacy saved likes.');
        }
        $rows = $select->get_result()->fetch_all(MYSQLI_ASSOC);
        $select->close();

        foreach ($rows as $row) {
            $rowId = (int)$row['id'];
            try {
                $update = $conn->prepare(
                    'UPDATE `video_likes` SET `account_id` = ? WHERE `id` = ? AND (`account_id` IS NULL OR `account_id` = ?)'
                );
                if (!$update) {
                    throw new RuntimeException('Could not prepare the saved-like migration.');
                }
                $update->bind_param('sis', $accountKey, $rowId, $oldNameKey);
                if (!$update->execute()) {
                    $update->close();
                    throw new RuntimeException('Could not migrate a saved like.');
                }
                $update->close();
            } catch (mysqli_sql_exception $error) {
                if ((int)$error->getCode() !== 1062) {
                    throw $error;
                }
                $delete = $conn->prepare('DELETE FROM `video_likes` WHERE `id` = ? AND (`account_id` IS NULL OR `account_id` = ?)');
                if (!$delete) {
                    throw new RuntimeException('Could not finish the saved-like migration.');
                }
                $delete->bind_param('is', $rowId, $oldNameKey);
                if (!$delete->execute()) {
                    $delete->close();
                    throw new RuntimeException('Could not finish the saved-like migration.');
                }
                $delete->close();
            }
        }
    }

    /** @return string[] ids the member liked, newest first */
    function vs_likes_db_ids(mysqli $conn): array
    {
        if (!vs_likes_is_member()) {
            return [];
        }
        if (!vs_likes_ensure_table($conn)) {
            throw new RuntimeException('The saved-like store is not available.');
        }
        vs_likes_migrate_legacy($conn);
        $ids = [];
        try {
            $accountKey = vs_likes_account_key();
            $st = $conn->prepare('SELECT `video_id` FROM `video_likes` WHERE `account_id` = ? ORDER BY `created_at` DESC, `id` DESC LIMIT 1000');
            if (!$st) {
                throw new RuntimeException('Could not prepare the liked videos lookup.');
            }
            $st->bind_param('s', $accountKey);
            if (!$st->execute()) {
                $st->close();
                throw new RuntimeException('Could not load liked videos.');
            }
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $ids[] = (string)$row['video_id'];
            }
            $st->close();
        } catch (Throwable $e) {
            error_log('Could not read liked videos: ' . $e->getMessage());
            throw $e;
        }
        return $ids;
    }

    /**
     * Makes $_SESSION['liked_videos'] match the database for a signed-in member.
     * Likes a visitor made as a guest (still in the session) are saved to the account the first time.
     * If a different member signs in on the same session, the old list is dropped, not copied across.
     */
    function vs_likes_session_sync(mysqli $conn): void
    {
        if (!isset($_SESSION['liked_videos']) || !is_array($_SESSION['liked_videos'])) {
            $_SESSION['liked_videos'] = [];
        }
        $_SESSION['liked_videos'] = array_values(array_unique(array_map('strval', $_SESSION['liked_videos'])));
        if (!vs_likes_is_member()) {
            return;
        }
        if (!vs_likes_ensure_table($conn)) {
            throw new RuntimeException('The saved-like store is not available.');
        }
        $user = vs_likes_user();
        $accountKey = vs_likes_account_key();
        $owner = (string)($_SESSION['liked_videos_owner'] ?? '');
        $sameOwner = $owner === $accountKey || ($owner !== '' && strcasecmp($owner, $user) === 0);
        if ($owner !== '' && !$sameOwner) {
            $_SESSION['liked_videos'] = [];
        }
        try {
            vs_likes_migrate_legacy($conn);
            if (!$sameOwner && $_SESSION['liked_videos']) {
                $ins = $conn->prepare('INSERT IGNORE INTO `video_likes` (`username`, `video_id`) VALUES (?, ?)');
                if (!$ins) {
                    throw new RuntimeException('Could not prepare guest-like migration.');
                }
                foreach ($_SESSION['liked_videos'] as $vid) {
                    if ($vid === '' || strlen($vid) > 100) {
                        continue;
                    }
                    $ins->bind_param('ss', $user, $vid);
                    if (!$ins->execute()) {
                        $ins->close();
                        throw new RuntimeException('Could not migrate guest likes to your account.');
                    }
                    $migrated = $conn->prepare(
                        'UPDATE `video_likes` SET `account_id` = ? WHERE `username` = ? AND `video_id` = ? AND `account_id` IS NULL'
                    );
                    if (!$migrated) {
                        $ins->close();
                        throw new RuntimeException('Could not associate guest likes with your account.');
                    }
                    $migrated->bind_param('sss', $accountKey, $user, $vid);
                    if (!$migrated->execute()) {
                        $migrated->close();
                        $ins->close();
                        throw new RuntimeException('Could not associate guest likes with your account.');
                    }
                    $migrated->close();
                }
                $ins->close();
            }
            $_SESSION['liked_videos'] = vs_likes_db_ids($conn);
            $_SESSION['liked_videos_owner'] = $accountKey;
        } catch (Throwable $e) {
            error_log('Could not sync liked videos: ' . $e->getMessage());
            throw $e;
        }
    }

    function vs_likes_has(mysqli $conn, $videoId): bool
    {
        $videoId = (string)$videoId;
        if ($videoId === '') {
            return false;
        }
        if (vs_likes_is_member()) {
            try {
                if (!vs_likes_ensure_table($conn)) {
                    throw new RuntimeException('The saved-like store is not available.');
                }
                vs_likes_migrate_legacy($conn);
                $accountKey = vs_likes_account_key();
                $st = $conn->prepare('SELECT 1 FROM `video_likes` WHERE `account_id` = ? AND `video_id` = ? LIMIT 1');
                if (!$st) {
                    throw new RuntimeException('Could not prepare the saved-like check.');
                }
                $st->bind_param('ss', $accountKey, $videoId);
                if (!$st->execute()) {
                    $st->close();
                    throw new RuntimeException('Could not check the saved like.');
                }
                $has = (bool)$st->get_result()->fetch_row();
                $st->close();
                return $has;
            } catch (Throwable $e) {
                error_log('Could not check a like: ' . $e->getMessage());
                throw $e;
            }
        }
        return in_array($videoId, array_map('strval', (array)($_SESSION['liked_videos'] ?? [])), true);
    }

    /** Saves or removes the like for the signed-in member. Guests are kept in the session only. */
    function vs_likes_set(mysqli $conn, $videoId, bool $liked): void
    {
        $videoId = (string)$videoId;
        if ($videoId === '') {
            return;
        }
        if (strlen($videoId) > 100) {
            throw new RuntimeException('The video ID is too long to save a like.');
        }
        $isMember = vs_likes_is_member();
        if ($isMember && !vs_likes_ensure_table($conn)) {
            throw new RuntimeException('The saved-like store is not available.');
        }
        if ($isMember) {
            vs_likes_migrate_legacy($conn);
            $user = vs_likes_user();
            $accountKey = vs_likes_account_key();
            $st = $conn->prepare($liked
                ? 'INSERT IGNORE INTO `video_likes` (`username`, `video_id`, `account_id`) VALUES (?, ?, ?)'
                : 'DELETE FROM `video_likes` WHERE `account_id` = ? AND `video_id` = ?');
            if (!$st) {
                throw new RuntimeException('Could not prepare the saved-like update.');
            }
            if ($liked) {
                $st->bind_param('sss', $user, $videoId, $accountKey);
            } else {
                $st->bind_param('ss', $accountKey, $videoId);
            }
            if (!$st->execute()) {
                $st->close();
                throw new RuntimeException('Could not save your like.');
            }
            $st->close();
        }

        $list = array_map('strval', (array)($_SESSION['liked_videos'] ?? []));
        $list = array_values(array_diff($list, [$videoId]));
        if ($liked) {
            $list[] = $videoId;
        }
        $_SESSION['liked_videos'] = $list;

        if (!$isMember) {
            return;
        }
    }

    /** Liked video ids, newest first. Members: from the database. Guests: from this session. */
    function vs_likes_ids(mysqli $conn): array
    {
        if (vs_likes_is_member()) {
            return vs_likes_db_ids($conn);
        }
        $ids = array_map('strval', (array)($_SESSION['liked_videos'] ?? []));
        $ids = array_values(array_unique(array_filter($ids, static fn(string $id): bool => $id !== '' && strlen($id) <= 191)));
        return array_reverse($ids);
    }
}
