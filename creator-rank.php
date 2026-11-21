<?php
/*
 * VibeStream - shared creator ranking helpers.
 * Put this file in the main VibeStream folder, next to studio.php and creator-analytics.php.
 *
 * - Ranks creators by total video views. Ties go to the channel that uploaded first, so there is only ever one #1.
 * - Saves a once-a-day rank snapshot (table `creator_rank_snapshots`, created automatically) so pages can show "up 2 / down 1".
 * - Remembers the last rank it saw (table `creator_rank_state`, created automatically) so the #1 celebration
 *   plays when someone newly takes the top spot.
 */
if (!function_exists('vs_creator_rank_info')) {
    /**
     * @return array{rank:?int,total:int,views:int,change:?int,became_first:bool}
     *   change: places gained since the last daily snapshot (positive = moved up), null if there is no earlier snapshot.
     */
    function vs_creator_rank_info(mysqli $conn, string $username, bool $persist = true): array
    {
        $info = ['rank' => null, 'total' => 0, 'views' => 0, 'change' => null, 'became_first' => false];
        $quote = static function (string $name): string {
            return '`' . str_replace('`', '``', $name) . '`';
        };
        try {
            $cols = [];
            $res = $conn->query('SHOW COLUMNS FROM `videos`');
            while ($c = $res->fetch_assoc()) {
                $cols[strtolower((string)$c['Field'])] = (string)$c['Field'];
            }
            $res->free();
            $owner = '';
            foreach (['username', 'channel_name', 'creator', 'uploader', 'author', 'user'] as $candidate) {
                if (isset($cols[$candidate])) { $owner = $cols[$candidate]; break; }
            }
            if ($owner === '' || !isset($cols['views'])) {
                return $info;
            }
            $dateCol = '';
            foreach (['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date'] as $candidate) {
                if (isset($cols[$candidate])) { $dateCol = $cols[$candidate]; break; }
            }
            $ownerSql = $quote($owner);
            $sql = 'SELECT LOWER(TRIM(CAST(' . $ownerSql . ' AS CHAR))) AS creator_key, '
                . 'COALESCE(SUM(COALESCE(' . $quote($cols['views']) . ', 0)), 0) AS total_views, '
                . ($dateCol !== '' ? 'MIN(' . $quote($dateCol) . ')' : 'NULL') . ' AS first_upload '
                . 'FROM `videos` WHERE NULLIF(TRIM(CAST(' . $ownerSql . ' AS CHAR)), \'\') IS NOT NULL '
                . 'GROUP BY LOWER(TRIM(CAST(' . $ownerSql . ' AS CHAR)))';
            $result = $conn->query($sql);
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            $result->free();
            usort($rows, static function ($a, $b) {
                $byViews = (int)$b['total_views'] <=> (int)$a['total_views'];
                if ($byViews !== 0) { return $byViews; }
                $aDate = (string)($a['first_upload'] ?? '');
                $bDate = (string)($b['first_upload'] ?? '');
                if ($aDate !== $bDate) {
                    if ($aDate === '') { return 1; }
                    if ($bDate === '') { return -1; }
                    return strcmp($aDate, $bDate);   // earlier channel wins a tie
                }
                return strcmp((string)$a['creator_key'], (string)$b['creator_key']);
            });
            $info['total'] = count($rows);
            $me = mb_strtolower($username, 'UTF-8');
            foreach ($rows as $index => $row) {
                if ((string)$row['creator_key'] === $me) {
                    $info['rank'] = $index + 1;
                    $info['views'] = (int)$row['total_views'];
                    break;
                }
            }
        } catch (Throwable $rankError) {
            error_log('Creator ranking could not be loaded: ' . $rankError->getMessage());
            return $info;
        }
        if ($info['rank'] === null) {
            return $info;
        }

        // Daily snapshot + last-seen rank (best effort: the rank above is still returned if these tables fail)
        try {
            $key = mb_strtolower($username, 'UTF-8');
            $conn->query(
                'CREATE TABLE IF NOT EXISTS `creator_rank_snapshots` (
                    `creator` VARCHAR(191) NOT NULL,
                    `snap_date` DATE NOT NULL,
                    `rank_pos` INT NOT NULL,
                    `total_views` BIGINT NOT NULL DEFAULT 0,
                    PRIMARY KEY (`creator`, `snap_date`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $conn->query(
                'CREATE TABLE IF NOT EXISTS `creator_rank_state` (
                    `creator` VARCHAR(191) NOT NULL,
                    `last_rank` INT NULL,
                    PRIMARY KEY (`creator`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $prevStmt = $conn->prepare('SELECT `rank_pos` FROM `creator_rank_snapshots` WHERE `creator` = ? AND `snap_date` < CURDATE() ORDER BY `snap_date` DESC LIMIT 1');
            $prevStmt->bind_param('s', $key);
            $prevStmt->execute();
            $prevRow = $prevStmt->get_result()->fetch_row();
            $prevStmt->close();
            if ($prevRow) {
                $info['change'] = (int)$prevRow[0] - (int)$info['rank'];
            }

            $stateStmt = $conn->prepare('SELECT `last_rank` FROM `creator_rank_state` WHERE `creator` = ? LIMIT 1');
            $stateStmt->bind_param('s', $key);
            $stateStmt->execute();
            $stateRow = $stateStmt->get_result()->fetch_row();
            $stateStmt->close();
            $lastRank = $stateRow ? ($stateRow[0] === null ? null : (int)$stateRow[0]) : null;

            if ($persist) {
                $info['became_first'] = $info['rank'] === 1 && $lastRank !== 1 && $info['views'] > 0;
                $rank = (int)$info['rank'];
                $views = (int)$info['views'];
                $snap = $conn->prepare('INSERT INTO `creator_rank_snapshots` (`creator`, `snap_date`, `rank_pos`, `total_views`) VALUES (?, CURDATE(), ?, ?) ON DUPLICATE KEY UPDATE `rank_pos` = VALUES(`rank_pos`), `total_views` = VALUES(`total_views`)');
                $snap->bind_param('sii', $key, $rank, $views);
                $snap->execute();
                $snap->close();
                $state = $conn->prepare('INSERT INTO `creator_rank_state` (`creator`, `last_rank`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `last_rank` = VALUES(`last_rank`)');
                $state->bind_param('si', $key, $rank);
                $state->execute();
                $state->close();
            }
        } catch (Throwable $snapError) {
            error_log('Creator rank history could not be saved: ' . $snapError->getMessage());
        }
        return $info;
    }
}

if (!function_exists('vs_creator_is_verified')) {
    /**
     * Best-effort: looks for a "verified" flag on your users table (is_verified / verified / verified_at ...).
     * Returns false when no such column exists, so nobody gets a badge by accident.
     */
    function vs_creator_is_verified(mysqli $conn, string $username): bool
    {
        try {
            foreach (['users', 'user', 'accounts'] as $table) {
                $exists = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
                if (!$exists || $exists->num_rows === 0) { continue; }
                $cols = [];
                $res = $conn->query('SHOW COLUMNS FROM `' . $table . '`');
                while ($c = $res->fetch_assoc()) {
                    $cols[strtolower((string)$c['Field'])] = (string)$c['Field'];
                }
                $res->free();
                $nameCol = '';
                foreach (['username', 'user_name', 'name', 'channel_name'] as $candidate) {
                    if (isset($cols[$candidate])) { $nameCol = $cols[$candidate]; break; }
                }
                $flagCol = '';
                foreach (['is_verified', 'verified', 'verified_at', 'is_verified_creator', 'verified_creator', 'blue_tick'] as $candidate) {
                    if (isset($cols[$candidate])) { $flagCol = $cols[$candidate]; break; }
                }
                if ($nameCol === '' || $flagCol === '') { continue; }
                $stmt = $conn->prepare('SELECT `' . str_replace('`', '``', $flagCol) . '` FROM `' . $table . '` WHERE LOWER(`' . str_replace('`', '``', $nameCol) . '`) = LOWER(?) LIMIT 1');
                $stmt->bind_param('s', $username);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_row();
                $stmt->close();
                if (!$row || $row[0] === null) { return false; }
                $value = strtolower(trim((string)$row[0]));
                return !in_array($value, ['', '0', 'no', 'false', 'off', '0000-00-00', '0000-00-00 00:00:00'], true);
            }
        } catch (Throwable $verifiedError) {
            error_log('Verified flag could not be read: ' . $verifiedError->getMessage());
        }
        return false;
    }
}

if (!function_exists('vs_creator_rank_history')) {
    /** Daily rank snapshots for the last $days days, as ['Y-m-d' => rank]. Empty when nothing is saved yet. */
    function vs_creator_rank_history(mysqli $conn, string $username, int $days = 30): array
    {
        $out = [];
        try {
            $days = max(2, min(365, $days));
            $key = mb_strtolower($username, 'UTF-8');
            $stmt = $conn->prepare(
                'SELECT `snap_date`, `rank_pos` FROM `creator_rank_snapshots`
                 WHERE `creator` = ? AND `snap_date` >= DATE_SUB(CURDATE(), INTERVAL ' . ($days - 1) . ' DAY)
                 ORDER BY `snap_date` ASC'
            );
            $stmt->bind_param('s', $key);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $out[(string)$row['snap_date']] = (int)$row['rank_pos'];
            }
            $stmt->close();
        } catch (Throwable $historyError) {
            error_log('Creator rank history could not be read: ' . $historyError->getMessage());
        }
        return $out;
    }
}

if (!function_exists('vs_creator_rank_chart')) {
    /** Small rank-over-time line (rank #1 is at the top). Returns '' until there are at least two snapshots. */
    function vs_creator_rank_chart(array $history, int $days = 30, string $gradId = 'vsRankG'): string
    {
        if (count($history) < 2) {
            return '<div class="vs-rank-note"><span>Your rank history appears after your second day on the leaderboard.</span></div>';
        }
        $days = max(2, $days);
        $ranks = array_values($history);
        $best = min($ranks);
        $worst = max($ranks);
        $start = strtotime(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')));
        $width = 240;
        $height = 56;
        $points = [];
        foreach ($history as $date => $rank) {
            $time = strtotime((string)$date);
            if ($time === false) { continue; }
            $x = 6 + max(0, min(1, ($time - $start) / (($days - 1) * 86400))) * ($width - 12);
            $y = $best === $worst ? $height / 2 : 8 + (($rank - $best) / ($worst - $best)) * ($height - 16);
            $points[] = [round($x, 1), round($y, 1)];
        }
        if (count($points) < 2) {
            return '<div class="vs-rank-note"><span>Your rank history appears after your second day on the leaderboard.</span></div>';
        }
        $line = '';
        foreach ($points as $i => $p) {
            $line .= ($i === 0 ? 'M' : ' L') . $p[0] . ',' . $p[1];
        }
        $last = $points[count($points) - 1];
        $area = $line . ' L' . $last[0] . ',' . $height . ' L' . $points[0][0] . ',' . $height . ' Z';
        return '<svg class="vs-rank-chart" viewBox="0 0 ' . $width . ' ' . $height . '" role="img" aria-label="Your rank over the last ' . (int)$days . ' days">'
            . '<defs><linearGradient id="' . htmlspecialchars($gradId, ENT_QUOTES) . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="currentColor" stop-opacity=".28"/><stop offset="1" stop-color="currentColor" stop-opacity="0"/></linearGradient></defs>'
            . '<path d="' . $area . '" fill="url(#' . htmlspecialchars($gradId, ENT_QUOTES) . ')"/>'
            . '<path d="' . $line . '" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="currentColor"/></svg>'
            . '<div class="vs-rank-note"><span>Rank over the last ' . (int)$days . ' days</span><span>Best #' . (int)$best . '</span><span>Lowest #' . (int)$worst . '</span></div>';
    }
}
