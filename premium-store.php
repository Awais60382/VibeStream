<?php
/**
 * VibeStream Premium storage (MySQL via the $pdo from connection.php).
 * Put this file in the site root, next to header.php.
 *
 * Manual transfers create pending requests for an admin to review.
 * Confirmed Stripe payments are recorded by vs_premium_confirm_payment().
 */

// Usernames allowed to approve or reject payments on premium-requests.php. EDIT THIS.
const VS_PREMIUM_ADMINS = ['admin'];

// How people pay you. Shown in the payment box on premium.php. EDIT THESE LINES.
const VS_PREMIUM_PAY_INFO = [
    'Bank transfer: Bank name, account number, account title',
    'Mobile wallet: wallet number and account name',
];

// Benefits that are currently available and enforced by the site.
const VS_PREMIUM_LIMITS = [
    // playlists / watch_later / uploads_per_day are enforced by the site (see premium-perks.php).
    // 100000 or more means "no cap".
    'free'    => ['downloads' => false, 'playlists' => 5,      'watch_later' => 25,     'uploads_per_day' => 5],
    'premium' => ['downloads' => true,  'playlists' => 50,     'watch_later' => 250,    'uploads_per_day' => 20],
    'plus'    => ['downloads' => true,  'playlists' => 100000, 'watch_later' => 300,    'uploads_per_day' => 100000],
];

// Plan keys must be 10 characters or fewer (premium_members.plan is VARCHAR(10)).
// Premium: weekly / monthly / yearly.   Premium Plus: plusweek / plusmonth / plusyear.
const VS_PREMIUM_PLANS = [
    'weekly'    => ['label' => 'Weekly',       'price' => 1.99,  'days' => 7,   'tier' => 'premium'],
    'monthly'   => ['label' => 'Monthly',      'price' => 4.99,  'days' => 31,  'tier' => 'premium'],
    'yearly'    => ['label' => 'Yearly',       'price' => 39.99, 'days' => 366, 'tier' => 'premium'],
    'plusweek'  => ['label' => 'Plus Weekly',  'price' => 3.99,  'days' => 7,   'tier' => 'plus'],
    'plusmonth' => ['label' => 'Plus Monthly', 'price' => 9.99,  'days' => 31,  'tier' => 'plus'],
    'plusyear'  => ['label' => 'Plus Yearly',  'price' => 79.99, 'days' => 366, 'tier' => 'plus'],
];

// Old plans that can no longer be bought, but members / pending payments that already use them still work.
const VS_PREMIUM_LEGACY_PLANS = [
    'daily' => ['label' => 'Daily', 'price' => 0.99, 'days' => 1, 'tier' => 'premium'],
    // Not for sale: granted by the 2,500-point reward.
    'pluspoints93' => ['label' => 'Plus (3 months, rewards)', 'price' => 0, 'days' => 93, 'tier' => 'plus'],
];

// Anti-farming: most uploads that can earn points per day (5 uploads = 25 points). Edit to change.
const VS_REWARDS_UPLOAD_DAILY_CAP = 5;

// Daily quests: cap = how many count per day, points = points each. Finishing every quest also pays the bonus.
const VS_REWARDS_QUESTS = [
    'watch'   => ['cap' => 3, 'points' => 2],
    'like'    => ['cap' => 3, 'points' => 1],
    'comment' => ['cap' => 1, 'points' => 3],
];
const VS_REWARDS_QUEST_BONUS = 5;

// Rewards levels by total points ever earned: name, minimum points, colour.
const VS_REWARDS_LEVELS = [
    ['Bronze', 0, '#e79a62'], ['Silver', 250, '#aab3e8'], ['Gold', 1000, '#ffc23d'],
    ['Platinum', 2500, '#5ccbf5'], ['Diamond', 5000, '#c58bff'],
];

// Leaderboard names: true shows e.g. "aw***" instead of the full username (your own row is always shown in full).
const VS_LEADERBOARD_MASK = false;

// Points rewards: cost => plan granted. Edit here to change or add reward tiers.
const VS_REWARDS_TIERS = [
    500  => ['plan' => 'monthly',      'reason' => 'premium_monthly', 'label' => '31 days of Premium'],
    1000 => ['plan' => 'plusmonth',    'reason' => 'premium_plus',    'label' => '31 days of Premium Plus'],
    2500 => ['plan' => 'pluspoints93', 'reason' => 'premium_plus_3m', 'label' => '93 days of Premium Plus'],
];

/** The $pdo from connection.php, or default XAMPP settings if connection.php has none. */
function vs_premium_pdo(): ?PDO
{
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=vibestream;charset=utf8mb4', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function vs_premium_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_members (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            plan VARCHAR(10) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'active\',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // One row every time Premium is switched on or extended: who got it, how, and who gave it.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_grants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            source VARCHAR(12) NOT NULL,
            granted_by VARCHAR(100) NOT NULL DEFAULT \'\',
            ref VARCHAR(100) NOT NULL DEFAULT \'\',
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_grant_user (username, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            amount DECIMAL(8,2) NOT NULL,
            txn_ref VARCHAR(100) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'pending\',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY uq_txn_ref (txn_ref),
            KEY idx_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // One row each time a Premium period ends. Admins see it as a notification;
    // once an admin has seen it (seen_at is set) that member leaves the admin Members list.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_end_alerts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            plan VARCHAR(10) NOT NULL,
            started_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            ended_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            seen_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uq_period (username, started_at, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // Which "your Premium ends soon / has ended" e-mails were already sent.
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS premium_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            expires_at DATETIME NOT NULL,
            kind VARCHAR(10) NOT NULL,
            sent_at DATETIME NOT NULL,
            UNIQUE KEY uq_reminder (username, expires_at, kind)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS watch_later_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            video_id VARCHAR(191) NOT NULL,
            video_key CHAR(64) NOT NULL,
            sort_order INT UNSIGNED NOT NULL DEFAULT 0,
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_watch_later_user_video (username, video_key),
            KEY idx_watch_later_user_order (username, sort_order, added_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reward_points_accounts (
            username VARCHAR(100) NOT NULL PRIMARY KEY,
            points INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reward_points_ledger (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            amount INT NOT NULL,
            reason VARCHAR(32) NOT NULL,
            reference_key CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            UNIQUE KEY uq_reward_points_reference (reference_key),
            KEY idx_reward_points_username_created (username, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reward_points_upload_backfills (
            username VARCHAR(100) NOT NULL PRIMARY KEY,
            completed_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS reward_points_daily_checkins (
            username VARCHAR(100) NOT NULL,
            checkin_date DATE NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (username, checkin_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function vs_rewards_points(PDO $pdo, string $username): int
{
    if ($username === '') {
        return 0;
    }
    $st = $pdo->prepare('SELECT points FROM reward_points_accounts WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    return (int)($st->fetchColumn() ?: 0);
}

/** Awards one daily-quest completion (once per quest item per day, capped per day) plus the all-quests bonus. Returns points added. */
function vs_rewards_award_quest(PDO $pdo, string $username, string $quest, string $refId): int
{
    if ($username === '' || $refId === '' || !isset(VS_REWARDS_QUESTS[$quest])) {
        return 0;
    }
    vs_premium_ensure($pdo);
    $def = VS_REWARDS_QUESTS[$quest];
    $reason = 'quest_' . $quest;
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare('INSERT IGNORE INTO reward_points_accounts (username, points, updated_at) VALUES (:u, 0, NOW())');
        $account->execute([':u' => $username]);
        $lock = $pdo->prepare('SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE');
        $lock->execute([':u' => $username]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Could not lock the reward points account.');
        }
        $day = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM reward_points_ledger WHERE username = :u AND reason = :r AND amount > 0 AND created_at >= CURDATE()'
        );
        $count->execute([':u' => $username, ':r' => $reason]);
        if ((int)$count->fetchColumn() >= $def['cap']) {
            $pdo->commit();
            return 0;
        }
        $entry = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
             VALUES (:u, :a, :r, :k, NOW())'
        );
        $entry->execute([
            ':u' => $username, ':a' => $def['points'], ':r' => $reason,
            ':k' => hash('sha256', "quest-$quest\0" . strtolower($username) . "\0" . $day . "\0" . $refId),
        ]);
        if ($entry->rowCount() !== 1) {
            $pdo->commit();
            return 0; // already counted today
        }
        $total = (int)$def['points'];

        // All quests done today? Pay the bonus once.
        $allDone = true;
        foreach (VS_REWARDS_QUESTS as $name => $d) {
            $count->execute([':u' => $username, ':r' => 'quest_' . $name]);
            if ((int)$count->fetchColumn() < $d['cap']) {
                $allDone = false;
                break;
            }
        }
        if ($allDone) {
            $entry->execute([
                ':u' => $username, ':a' => VS_REWARDS_QUEST_BONUS, ':r' => 'quest_bonus',
                ':k' => hash('sha256', "quest-bonus\0" . strtolower($username) . "\0" . $day),
            ]);
            if ($entry->rowCount() === 1) {
                $total += VS_REWARDS_QUEST_BONUS;
            }
        }
        $update = $pdo->prepare('UPDATE reward_points_accounts SET points = points + :p, updated_at = NOW() WHERE username = :u');
        $update->execute([':p' => $total, ':u' => $username]);
        $pdo->commit();
        return $total;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * One-line helper for pages like watch.php: awards a quest to the signed-in member and never throws.
 * Own videos don't count. $quest: 'watch', 'like' or 'comment'; $ref: video ID or comment ID.
 */
function vs_rewards_quest_try(string $quest, string $ref, array $video = []): int
{
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 0;
        }
        $username = trim((string)($_SESSION['username'] ?? ''));
        $owner = trim((string)($video['username'] ?? ''));
        if ($username === '' || $ref === '' || ($owner !== '' && strcasecmp($owner, $username) === 0)) {
            return 0;
        }
        $pdo = vs_premium_pdo();
        return $pdo ? vs_rewards_award_quest($pdo, $username, $quest, $ref) : 0;
    } catch (Throwable $e) {
        error_log('Daily quest failed: ' . $e->getMessage());
        return 0;
    }
}

/** Today's quest progress for the rewards page. */
function vs_rewards_quest_progress(PDO $pdo, string $username): array
{
    $reasons = ['quest_watch', 'quest_like', 'quest_comment', 'quest_bonus'];
    $st = $pdo->prepare(
        'SELECT reason, COUNT(*) FROM reward_points_ledger
         WHERE username = :u AND created_at >= CURDATE() AND reason IN (\'quest_watch\', \'quest_like\', \'quest_comment\', \'quest_bonus\')
         GROUP BY reason'
    );
    $st->execute([':u' => $username]);
    $counts = array_fill_keys($reasons, 0);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $row) {
        $counts[(string)$row[0]] = (int)$row[1];
    }
    $out = [];
    foreach (VS_REWARDS_QUESTS as $name => $def) {
        $out[$name] = ['done' => min($def['cap'], $counts['quest_' . $name]), 'cap' => $def['cap'], 'points' => $def['points']];
    }
    $out['bonus'] = ['done' => $counts['quest_bonus'] > 0, 'points' => VS_REWARDS_QUEST_BONUS];
    return $out;
}

/** Small level pill (Bronze..Diamond) for the user menu; cached in the session for 2 minutes. */
function vs_rewards_level_badge_html(string $username): string
{
    $username = trim($username);
    if ($username === '' || session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    $cache = $_SESSION['vs_level_cache'] ?? null;
    if (is_array($cache) && ($cache['u'] ?? '') === $username && (time() - (int)($cache['t'] ?? 0)) < 120) {
        $earned = (int)($cache['e'] ?? 0);
    } else {
        try {
            $pdo = vs_premium_pdo();
            if (!$pdo) {
                return '';
            }
            $st = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM reward_points_ledger WHERE username = :u AND amount > 0');
            $st->execute([':u' => $username]);
            $earned = (int)$st->fetchColumn();
            $_SESSION['vs_level_cache'] = ['u' => $username, 't' => time(), 'e' => $earned];
        } catch (Throwable $e) {
            return '';
        }
    }
    if ($earned <= 0) {
        return '';
    }
    $level = VS_REWARDS_LEVELS[0];
    foreach (VS_REWARDS_LEVELS as $candidate) {
        if ($earned >= $candidate[1]) {
            $level = $candidate;
        }
    }
    return '<span class="vs-user-level" title="Rewards level" style="margin-left:6px;padding:1px 7px;border:1px solid '
        . $level[2] . ';border-radius:999px;color:' . $level[2] . ';font-size:10px;font-weight:800;vertical-align:middle">'
        . htmlspecialchars($level[0], ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Credit five points once for each successfully published video or short. */
function vs_rewards_award_upload(PDO $pdo, string $username, string $videoId): bool
{
    if ($username === '' || $videoId === '') {
        throw new InvalidArgumentException('A signed-in username and published video ID are required.');
    }
    vs_premium_ensure($pdo);
    $reference = hash('sha256', "upload\0" . strtolower($username) . "\0" . $videoId);
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare('INSERT IGNORE INTO reward_points_accounts (username, points, updated_at) VALUES (:u, 0, NOW())');
        $account->execute([':u' => $username]);
        $lock = $pdo->prepare('SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE');
        $lock->execute([':u' => $username]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Could not lock the reward points account.');
        }
        $today = $pdo->prepare(
            'SELECT COUNT(*) FROM reward_points_ledger
             WHERE username = :u AND reason = \'upload\' AND amount > 0 AND created_at >= CURDATE()'
        );
        $today->execute([':u' => $username]);
        if ((int)$today->fetchColumn() >= VS_REWARDS_UPLOAD_DAILY_CAP) {
            $pdo->commit();
            return false; // daily upload-points cap reached
        }
        $entry = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
             VALUES (:u, 5, \'upload\', :r, NOW())'
        );
        $entry->execute([':u' => $username, ':r' => $reference]);
        $awarded = $entry->rowCount() === 1;
        if ($awarded) {
            $update = $pdo->prepare('UPDATE reward_points_accounts SET points = points + 5, updated_at = NOW() WHERE username = :u');
            $update->execute([':u' => $username]);
        }
        $pdo->commit();
        return $awarded;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Credit existing published uploads once, without changing rewards already in the ledger. */
function vs_rewards_backfill_uploads(PDO $pdo, string $username): int
{
    if ($username === '') {
        return 0;
    }

    vs_premium_ensure($pdo);
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM `videos`')->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[strtolower((string)$column['Field'])] = (string)$column['Field'];
    }
    $idColumn = $columns['id'] ?? $columns['video_id'] ?? null;
    $ownerColumns = array_values(array_filter([
        $columns['username'] ?? null,
        $columns['channel_name'] ?? null,
        $columns['uploader'] ?? null,
    ]));
    if ($idColumn === null || !$ownerColumns) {
        throw new RuntimeException('The videos table is missing its upload owner or ID column.');
    }

    $quote = static function (string $identifier): string {
        return '`' . str_replace('`', '``', $identifier) . '`';
    };
    $ownerConditions = [];
    $ownerParams = [];
    foreach ($ownerColumns as $index => $ownerColumn) {
        $parameter = ':owner' . $index;
        $ownerConditions[] = 'LOWER(TRIM(COALESCE(' . $quote($ownerColumn) . ', \'\'))) = LOWER(' . $parameter . ')';
        $ownerParams[$parameter] = $username;
    }

    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_accounts (username, points, updated_at) VALUES (:u, 0, NOW())'
        );
        $account->execute([':u' => $username]);
        $backfill = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_upload_backfills (username, completed_at) VALUES (:u, NULL)'
        );
        $backfill->execute([':u' => $username]);

        $lockBackfill = $pdo->prepare(
            'SELECT completed_at FROM reward_points_upload_backfills WHERE username = :u FOR UPDATE'
        );
        $lockBackfill->execute([':u' => $username]);
        $completedAt = $lockBackfill->fetchColumn();
        if ($completedAt !== null && $completedAt !== false) {
            $pdo->commit();
            return 0;
        }

        $lockAccount = $pdo->prepare(
            'SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE'
        );
        $lockAccount->execute([':u' => $username]);
        if ($lockAccount->fetchColumn() === false) {
            throw new RuntimeException('Could not lock the reward points account for upload backfill.');
        }

        $videos = $pdo->prepare(
            'SELECT DISTINCT CAST(' . $quote($idColumn) . ' AS CHAR)
             FROM `videos`
             WHERE ' . implode(' OR ', $ownerConditions) . '
               AND ' . $quote($idColumn) . ' IS NOT NULL
               AND TRIM(CAST(' . $quote($idColumn) . ' AS CHAR)) <> \'\''
        );
        $videos->execute($ownerParams);

        $insert = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
             VALUES (:u, 5, \'upload\', :r, NOW())'
        );
        $awardedCount = 0;
        while (($videoId = $videos->fetchColumn()) !== false) {
            $reference = hash('sha256', "upload\0" . strtolower($username) . "\0" . (string)$videoId);
            $insert->execute([':u' => $username, ':r' => $reference]);
            $awardedCount += $insert->rowCount();
        }
        $videos->closeCursor();

        if ($awardedCount > 0) {
            $update = $pdo->prepare(
                'UPDATE reward_points_accounts
                 SET points = points + :points, updated_at = NOW()
                 WHERE username = :u'
            );
            $update->execute([':points' => $awardedCount * 5, ':u' => $username]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not update the reward points account after upload backfill.');
            }
        }

        $complete = $pdo->prepare(
            'UPDATE reward_points_upload_backfills SET completed_at = NOW() WHERE username = :u'
        );
        $complete->execute([':u' => $username]);
        $pdo->commit();
        return $awardedCount;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function vs_rewards_daily_checkin_claimed(PDO $pdo, string $username): bool
{
    if ($username === '') {
        return false;
    }

    $statement = $pdo->prepare(
        'SELECT 1 FROM reward_points_daily_checkins
         WHERE username = :u AND checkin_date = CURDATE()
         LIMIT 1'
    );
    $statement->execute([':u' => $username]);
    return $statement->fetchColumn() !== false;
}

/** Streak bonus for reaching a check-in streak length: every 7th day, ramping +50, +100, then +150. Edit here to change the rule. */
function vs_rewards_streak_bonus(int $streakDays): int
{
    if ($streakDays < 7 || $streakDays % 7 !== 0) {
        return 0;
    }
    $ramp = [1 => 50, 2 => 100, 3 => 150];
    return $ramp[min(3, intdiv($streakDays, 7))];
}

/** Top earners by lifetime points earned (cached for 60 seconds). */
function vs_rewards_leaderboard(PDO $pdo, int $limit = 3, string $period = 'all'): array
{
    $periods = [
        'all' => '',
        'week' => ' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)',
        'month' => ' AND created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')',
    ];
    if (!isset($periods[$period])) {
        $period = 'all';
    }
    $cache = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vs_rewards_leaders_' . $period . '_' . $limit . '.json';
    if (is_file($cache) && (time() - (int)@filemtime($cache)) < 60) {
        $cached = json_decode((string)@file_get_contents($cache), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $st = $pdo->query(
        'SELECT username, SUM(amount) AS earned FROM reward_points_ledger
         WHERE amount > 0' . $periods[$period] . ' GROUP BY username ORDER BY earned DESC, MIN(id) ASC LIMIT ' . (int)$limit
    );
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = ['name' => (string)$row['username'], 'points' => (int)$row['earned']];
    }
    @file_put_contents($cache, json_encode($rows), LOCK_EX);
    return $rows;
}

/** Adds avatar, "me" flag and optional name masking to leaderboard rows. */
function vs_rewards_decorate_leaders(array $rows, string $username, ?PDO $pdo = null): array
{
    return array_map(function (array $row) use ($username, $pdo): array {
        $me = $username !== '' && strcasecmp($row['name'], $username) === 0;
        $row['vip'] = false;
        if ($pdo) {
            try {
                $row['vip'] = vs_premium_is_active(vs_premium_get($pdo, $row['name']));
            } catch (Throwable $e) {
                $row['vip'] = false;
            }
        }
        $row['avatar'] = '';
        if (function_exists('avatar_url')) {
            try {
                $row['avatar'] = (string)avatar_url($row['name']);
            } catch (Throwable $e) {
                $row['avatar'] = '';
            }
        }
        if (VS_LEADERBOARD_MASK && !$me) {
            $row['name'] = mb_substr($row['name'], 0, 2) . '***';
        }
        $row['me'] = $me;
        return $row;
    }, $rows);
}

/** Award five points for a daily check-in, at most once per server calendar day. */
function vs_rewards_claim_daily_checkin(PDO $pdo, string $username): bool
{
    if ($username === '') {
        throw new InvalidArgumentException('A signed-in username is required for a daily check-in.');
    }

    vs_premium_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_accounts (username, points, updated_at)
             VALUES (:u, 0, NOW())'
        );
        $account->execute([':u' => $username]);
        $lock = $pdo->prepare(
            'SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE'
        );
        $lock->execute([':u' => $username]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Could not lock the reward points account for daily check-in.');
        }

        $date = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
        $checkin = $pdo->prepare(
            'INSERT IGNORE INTO reward_points_daily_checkins (username, checkin_date, created_at)
             VALUES (:u, :date, NOW())'
        );
        $checkin->execute([':u' => $username, ':date' => $date]);
        if ($checkin->rowCount() !== 1) {
            $pdo->commit();
            return false;
        }

        $reference = hash('sha256', "daily-checkin\0" . strtolower($username) . "\0" . $date);
        $entry = $pdo->prepare(
            'INSERT INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
             VALUES (:u, 5, \'daily_checkin\', :r, NOW())'
        );
        $entry->execute([':u' => $username, ':r' => $reference]);
        if ($entry->rowCount() !== 1) {
            throw new RuntimeException('Could not record the daily check-in reward.');
        }

        // Streak bonus: count consecutive check-in days ending today.
        $streakRows = $pdo->prepare(
            'SELECT DATE_FORMAT(checkin_date, \'%Y-%m-%d\') FROM reward_points_daily_checkins
             WHERE username = :u AND checkin_date <= :d ORDER BY checkin_date DESC LIMIT 60'
        );
        $streakRows->execute([':u' => $username, ':d' => $date]);
        $streakSet = array_fill_keys(array_map('strval', $streakRows->fetchAll(PDO::FETCH_COLUMN)), true);
        $streakDays = 0;
        for ($d = new DateTimeImmutable($date); isset($streakSet[$d->format('Y-m-d')]); $d = $d->modify('-1 day')) {
            $streakDays++;
        }
        $bonus = vs_rewards_streak_bonus($streakDays);
        if ($bonus > 0) {
            $bonusEntry = $pdo->prepare(
                'INSERT INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
                 VALUES (:u, :a, \'streak_bonus\', :r, NOW())'
            );
            $bonusEntry->execute([
                ':u' => $username,
                ':a' => $bonus,
                ':r' => hash('sha256', "streak-bonus\0" . strtolower($username) . "\0" . $date),
            ]);
            if ($bonusEntry->rowCount() !== 1) {
                throw new RuntimeException('Could not record the streak bonus.');
            }
        }

        $update = $pdo->prepare(
            'UPDATE reward_points_accounts
             SET points = points + :amt, updated_at = NOW()
             WHERE username = :u'
        );
        $update->execute([':u' => $username, ':amt' => 5 + $bonus]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Could not update the reward points account after daily check-in.');
        }
        $pdo->commit();
        return true;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Redeem points for a reward tier (see VS_REWARDS_TIERS; default 500 = 31 days Premium). Returns an error or null on success. */
function vs_rewards_redeem_monthly(PDO $pdo, string $username, int $cost = 500): ?string
{
    if ($username === '') {
        return 'Please sign in first.';
    }
    if (!isset(VS_REWARDS_TIERS[$cost])) {
        return 'Choose a valid reward.';
    }
    $tier = VS_REWARDS_TIERS[$cost];
    if (vs_premium_is_admin($username)) {
        return 'Admins already have Premium.';
    }
    vs_premium_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $account = $pdo->prepare('INSERT IGNORE INTO reward_points_accounts (username, points, updated_at) VALUES (:u, 0, NOW())');
        $account->execute([':u' => $username]);
        $lock = $pdo->prepare('SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE');
        $lock->execute([':u' => $username]);
        $points = $lock->fetchColumn();
        if ($points === false) {
            throw new RuntimeException('Could not lock the reward points account.');
        }
        if (vs_premium_is_active(vs_premium_get($pdo, $username))) {
            $pdo->rollBack();
            return 'Redeem your points after your current Premium period ends.';
        }
        if ((int)$points < $cost) {
            $pdo->rollBack();
            return 'You need ' . number_format($cost) . ' points to redeem ' . $tier['label'] . '.';
        }

        $reference = hash('sha256', "premium-reward\0" . strtolower($username) . "\0" . bin2hex(random_bytes(16)));
        $entry = $pdo->prepare(
            'INSERT INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
             VALUES (:u, :a, :reason, :r, NOW())'
        );
        $entry->execute([':u' => $username, ':a' => -$cost, ':reason' => $tier['reason'], ':r' => $reference]);
        $update = $pdo->prepare('UPDATE reward_points_accounts SET points = points - :c, updated_at = NOW() WHERE username = :u AND points >= :c2');
        $update->execute([':u' => $username, ':c' => $cost, ':c2' => $cost]);
        if ($update->rowCount() !== 1 || !vs_premium_set($pdo, $username, $tier['plan'], 'points')) {
            throw new RuntimeException('Could not complete the Premium points redemption.');
        }
        $pdo->commit();
        return null;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Return the private rewards details used by the Premium points page. */
function vs_rewards_page_data(PDO $pdo, string $username): array
{
    if ($username === '') {
        throw new InvalidArgumentException('A signed-in username is required to load rewards details.');
    }

    vs_premium_ensure($pdo);
    $today = (string)$pdo->query("SELECT DATE_FORMAT(CURDATE(), '%Y-%m-%d')")->fetchColumn();
    if ($today === '') {
        throw new RuntimeException('Could not determine the current rewards date.');
    }

    $checkins = $pdo->prepare(
        'SELECT DATE_FORMAT(checkin_date, \'%Y-%m-%d\')
         FROM reward_points_daily_checkins
         WHERE username = :u
         ORDER BY checkin_date DESC'
    );
    $checkins->execute([':u' => $username]);
    $checkinDates = array_map('strval', $checkins->fetchAll(PDO::FETCH_COLUMN));
    $checkinSet = array_fill_keys($checkinDates, true);

    $todayDate = new DateTimeImmutable($today);
    $streakStart = isset($checkinSet[$today])
        ? $todayDate
        : $todayDate->modify('-1 day');
    $streak = 0;
    for ($date = $streakStart; isset($checkinSet[$date->format('Y-m-d')]); $date = $date->modify('-1 day')) {
        $streak++;
    }

    $weekStart = $todayDate->modify('-6 days')->format('Y-m-d');
    $recentCheckins = array_values(array_filter(
        $checkinDates,
        static function (string $date) use ($weekStart): bool {
            return $date >= $weekStart;
        }
    ));

    $activityStatement = $pdo->prepare(
        'SELECT amount, reason, DATE_FORMAT(created_at, \'%Y-%m-%d %H:%i:%s\') AS created_at
         FROM reward_points_ledger
         WHERE username = :u
         ORDER BY created_at DESC, id DESC
         LIMIT 20'
    );
    $activityStatement->execute([':u' => $username]);
    $activity = array_map(
        static function (array $entry): array {
            return [
                'amount' => (int)$entry['amount'],
                'reason' => (string)$entry['reason'],
                'createdAt' => (string)$entry['created_at'],
            ];
        },
        $activityStatement->fetchAll(PDO::FETCH_ASSOC)
    );

    $earnedStatement = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0)
         FROM reward_points_ledger
         WHERE username = :u'
    );
    $earnedStatement->execute([':u' => $username]);

    $monthlyStatement = $pdo->prepare(
        'SELECT DATE_FORMAT(created_at, \'%Y-%m\') AS month,
                COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS earned,
                COALESCE(SUM(CASE WHEN reason = \'upload\' AND amount > 0 THEN amount ELSE 0 END), 0) AS uploads,
                COALESCE(SUM(CASE WHEN reason = \'daily_checkin\' AND amount > 0 THEN amount ELSE 0 END), 0) AS checkins,
                COALESCE(SUM(CASE WHEN amount > 0 AND reason NOT IN (\'upload\', \'daily_checkin\') THEN amount ELSE 0 END), 0) AS other,
                COALESCE(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 0) AS redeemed
         FROM reward_points_ledger
         WHERE username = :u
           AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), \'%Y-%m-01\')
         GROUP BY DATE_FORMAT(created_at, \'%Y-%m\')
         ORDER BY month ASC'
    );
    $monthlyStatement->execute([':u' => $username]);
    $monthlySummary = array_map(
        static function (array $month): array {
            return [
                'month' => (string)$month['month'],
                'earned' => (int)$month['earned'],
                'uploads' => (int)$month['uploads'],
                'checkins' => (int)$month['checkins'],
                'other' => (int)$month['other'],
                'redeemed' => (int)$month['redeemed'],
            ];
        },
        $monthlyStatement->fetchAll(PDO::FETCH_ASSOC)
    );

    $lifetime = (int)$earnedStatement->fetchColumn();
    $uploadsTodayStatement = $pdo->prepare(
        'SELECT COUNT(*) FROM reward_points_ledger
         WHERE username = :u AND reason = \'upload\' AND amount > 0 AND created_at >= CURDATE()'
    );
    $uploadsTodayStatement->execute([':u' => $username]);
    $uploadsToday = (int)$uploadsTodayStatement->fetchColumn();
    $rank = null;
    if ($lifetime > 0) {
        try {
            $rankStatement = $pdo->prepare(
                'SELECT COUNT(*) + 1 FROM (
                     SELECT username FROM reward_points_ledger WHERE amount > 0 GROUP BY username HAVING SUM(amount) > :e
                 ) ranked'
            );
            $rankStatement->execute([':e' => $lifetime]);
            $rank = (int)$rankStatement->fetchColumn();
        } catch (Throwable $e) {
            $rank = null;
        }
    }

    return [
        'today' => $today,
        'checkinDates' => $recentCheckins,
        'checkinStreak' => $streak,
        'lifetimeEarned' => $lifetime,
        'rank' => $rank,
        'uploadsToday' => $uploadsToday,
        'uploadCap' => VS_REWARDS_UPLOAD_DAILY_CAP,
        'quests' => vs_rewards_quest_progress($pdo, $username),
        'activity' => $activity,
        'monthlySummary' => $monthlySummary,
    ];
}

function vs_rewards_normalize_activity_filters(array $input): array
{
    $types = [
        'all' => '',
        'upload' => 'upload',
        'daily_checkin' => 'daily_checkin',
        'premium_monthly' => 'premium_monthly',
        'streak_bonus' => 'streak_bonus',
        'premium_plus' => 'premium_plus',
        'premium_plus_3m' => 'premium_plus_3m',
    ];
    $type = $input['type'] ?? 'all';
    if (!is_string($type) || !array_key_exists($type, $types)) {
        throw new InvalidArgumentException('Choose a valid activity type.');
    }

    $dates = [];
    foreach (['from', 'to'] as $name) {
        $value = $input[$name] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Enter a valid activity date range.');
        }
        $value = trim($value);
        if ($value !== '' && (
            !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)
            || !checkdate((int)substr($value, 5, 2), (int)substr($value, 8, 2), (int)substr($value, 0, 4))
        )) {
            throw new InvalidArgumentException('Enter valid dates for the activity filter.');
        }
        $dates[$name] = $value;
    }
    if ($dates['from'] !== '' && $dates['to'] !== '' && $dates['from'] > $dates['to']) {
        throw new InvalidArgumentException('The start date must be on or before the end date.');
    }

    $pageValue = $input['page'] ?? 1;
    if (!is_int($pageValue) && !is_string($pageValue)) {
        throw new InvalidArgumentException('Choose a valid activity page.');
    }
    $page = filter_var($pageValue, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1) {
        throw new InvalidArgumentException('Choose a valid activity page.');
    }

    return [
        'reason' => $types[$type],
        'from' => $dates['from'],
        'to' => $dates['to'],
        'page' => $page,
    ];
}

function vs_rewards_activity_conditions(string $username, array $filters): array
{
    if ($username === '') {
        throw new InvalidArgumentException('A signed-in username is required to view points activity.');
    }

    $conditions = ['username = :username'];
    $parameters = [':username' => $username];
    if ($filters['reason'] !== '') {
        $conditions[] = 'reason = :reason';
        $parameters[':reason'] = $filters['reason'];
    }
    if ($filters['from'] !== '') {
        $conditions[] = 'created_at >= :from_date';
        $parameters[':from_date'] = $filters['from'] . ' 00:00:00';
    }
    if ($filters['to'] !== '') {
        $toExclusive = (new DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d');
        $conditions[] = 'created_at < :to_date';
        $parameters[':to_date'] = $toExclusive . ' 00:00:00';
    }

    return [implode(' AND ', $conditions), $parameters];
}

function vs_rewards_activity_statement(
    PDO $pdo,
    string $username,
    array $filters,
    ?int $limit = null,
    int $offset = 0
): PDOStatement {
    [$where, $parameters] = vs_rewards_activity_conditions($username, $filters);
    $sql = 'SELECT amount, reason, DATE_FORMAT(created_at, \'%Y-%m-%d %H:%i:%s\') AS created_at
            FROM reward_points_ledger
            WHERE ' . $where . '
            ORDER BY created_at DESC, id DESC';
    if ($limit !== null) {
        $sql .= ' LIMIT :limit OFFSET :offset';
    }
    $statement = $pdo->prepare($sql);
    foreach ($parameters as $name => $value) {
        $statement->bindValue($name, $value, PDO::PARAM_STR);
    }
    if ($limit !== null) {
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    }
    $statement->execute();
    return $statement;
}

function vs_rewards_activity_page(PDO $pdo, string $username, array $filters): array
{
    [$where, $parameters] = vs_rewards_activity_conditions($username, $filters);
    $count = $pdo->prepare('SELECT COUNT(*) FROM reward_points_ledger WHERE ' . $where);
    $count->execute($parameters);
    $total = (int)$count->fetchColumn();
    $pageSize = 20;
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min($filters['page'], $pages);
    $statement = vs_rewards_activity_statement(
        $pdo,
        $username,
        $filters,
        $pageSize,
        ($page - 1) * $pageSize
    );
    $rows = array_map(
        static function (array $entry): array {
            return [
                'amount' => (int)$entry['amount'],
                'reason' => (string)$entry['reason'],
                'createdAt' => (string)$entry['created_at'],
            ];
        },
        $statement->fetchAll(PDO::FETCH_ASSOC)
    );

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
        'pageSize' => $pageSize,
    ];
}

/* ------------------------------------------------------------------ */
/* Payment instructions (editable by the owner in the admin panel)      */
/* ------------------------------------------------------------------ */

function vs_premium_settings_file(): string
{
    return __DIR__ . '/backups/premium-settings.json';
}

/** The lines shown to buyers ("send money to ..."). Empty = not set up yet, so buying stays closed. */
function vs_premium_pay_info(): array
{
    $f = vs_premium_settings_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if (is_array($d) && isset($d['pay_info']) && is_array($d['pay_info'])) {
        return array_values(array_filter(array_map(static fn($l) => trim((string)$l), $d['pay_info']), static fn($l) => $l !== ''));
    }
    foreach (VS_PREMIUM_PAY_INFO as $line) {
        // The sample lines from the code are placeholders, not real account details.
        if (stripos($line, 'Bank name, account number') !== false || stripos($line, 'wallet number and account name') !== false) {
            return [];
        }
    }
    return VS_PREMIUM_PAY_INFO;
}

function vs_premium_save_pay_info(array $lines): bool
{
    $clean = [];
    foreach ($lines as $l) {
        $l = trim(preg_replace('/\s+/', ' ', (string)$l));
        if ($l !== '') {
            $clean[] = mb_substr($l, 0, 200);
        }
    }
    $clean = array_slice($clean, 0, 6);
    $dir = dirname(vs_premium_settings_file());
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Options -Indexes\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    $f = vs_premium_settings_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = is_array($d) ? $d : [];
    $d['pay_info'] = $clean;
    return @file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

/* ------------------------------------------------------------------ */
/* Levels and what they get                                             */
/* ------------------------------------------------------------------ */

function vs_premium_plan_def(string $plan): ?array
{
    return VS_PREMIUM_PLANS[$plan] ?? VS_PREMIUM_LEGACY_PLANS[$plan] ?? null;
}

/** 'free', 'premium' or 'plus' for a premium_members row (null = free). */
function vs_premium_tier(?array $row): string
{
    if (!vs_premium_is_active($row)) {
        return 'free';
    }
    return strpos((string)$row['plan'], 'plus') === 0 ? 'plus' : 'premium';
}

/** What this person may use, including each enforced plan limit. Admins get Plus. */
function vs_premium_user_limits(?PDO $pdo, string $username): array
{
    $tier = 'free';
    if (vs_premium_is_admin($username)) {
        $tier = 'plus';
    } elseif ($pdo && $username !== '') {
        try {
            $tier = vs_premium_tier(vs_premium_get($pdo, $username));
        } catch (Throwable $e) {
            $tier = 'free';
        }
    }
    return ['tier' => $tier] + VS_PREMIUM_LIMITS[$tier];
}

function vs_premium_is_active(?array $row): bool
{
    return $row && $row['status'] === 'active' && strtotime((string)$row['expires_at']) > time();
}

function vs_premium_get(PDO $pdo, string $username): ?array
{
    $st = $pdo->prepare('SELECT * FROM premium_members WHERE username = :u LIMIT 1');
    $st->execute([':u' => $username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** Switch Premium on. If the user is already active, the new time is added on top. */
function vs_premium_set(PDO $pdo, string $username, string $plan, string $source = '', string $grantedBy = '', string $ref = ''): bool
{
    $def = VS_PREMIUM_PLANS[$plan] ?? VS_PREMIUM_LEGACY_PLANS[$plan] ?? null;
    if ($username === '' || $def === null) {
        return false;
    }
    $days = (int)$def['days'];
    $st = $pdo->prepare(
        'INSERT INTO premium_members (username, plan, status, started_at, expires_at, updated_at)
         VALUES (:u, :p, \'active\', NOW(), DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY), NOW())
         ON DUPLICATE KEY UPDATE
            started_at = IF(status = \'active\' AND expires_at > NOW(), started_at, NOW()),
            expires_at = DATE_ADD(IF(status = \'active\' AND expires_at > NOW(), expires_at, NOW()), INTERVAL ' . $days . ' DAY),
            plan = VALUES(plan), status = \'active\', updated_at = NOW()'
    );
    $ok = $st->execute([':u' => $username, ':p' => $plan]);
    if ($ok) {
        vs_premium_log_grant($pdo, $username, $plan, $source, $grantedBy, $ref);
    }
    return $ok;
}

/**
 * Remember how and from whom a member got Premium (shown on the admin premium page).
 * source: 'payment' (admin confirmed a payment), 'stripe' (paid online), 'points' (500 points),
 * 'admin' (an admin gave it). If the caller does not say, a signed-in admin session means 'admin'.
 * Never allowed to break the grant itself.
 */
function vs_premium_log_grant(PDO $pdo, string $username, string $plan, string $source = '', string $grantedBy = '', string $ref = ''): void
{
    try {
        if ($source === '') {
            $who = trim((string)($_SESSION['username'] ?? ''));
            if ($who !== '' && vs_premium_is_admin($who)) {
                $source = 'admin';
                $grantedBy = $grantedBy !== '' ? $grantedBy : $who;
            } else {
                $source = 'system';
            }
        }
        $row = vs_premium_get($pdo, $username);
        if (!$row) {
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO premium_grants (username, plan, source, granted_by, ref, started_at, expires_at, created_at)
             VALUES (:u, :p, :s, :g, :r, :st, :ex, NOW())'
        );
        $ins->execute([
            ':u' => $username, ':p' => $plan, ':s' => mb_substr($source, 0, 12),
            ':g' => mb_substr($grantedBy, 0, 100), ':r' => mb_substr($ref, 0, 100),
            ':st' => $row['started_at'], ':ex' => $row['expires_at'],
        ]);
    } catch (Throwable $e) {
        error_log('premium grant log failed: ' . $e->getMessage());
    }
}

function vs_premium_source_label(string $source): string
{
    switch ($source) {
        case 'payment': return 'Paid (confirmed by admin)';
        case 'stripe':  return 'Paid online';
        case 'points':  return 'Redeemed 500 points';
        case 'admin':   return 'Given by an admin';
        case 'system':  return 'Added by the system';
        default:        return 'Unknown (before tracking)';
    }
}

/**
 * Everything the admin premium page needs: each member with plan, status, how they got it,
 * who gave it, and when it ends. Newest first. Uses 3 queries in total, however many members.
 */
function vs_premium_admin_overview(PDO $pdo): array
{
    vs_premium_ensure($pdo);
    $members = vs_premium_all($pdo);

    $latest = [];
    foreach ($pdo->query(
        'SELECT g.username, g.source, g.granted_by, g.ref, g.created_at
         FROM premium_grants g
         JOIN (SELECT username, MAX(id) AS mid FROM premium_grants GROUP BY username) x ON x.mid = g.id'
    )->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $latest[strtolower((string)$g['username'])] = $g;
    }
    $counts = [];
    foreach ($pdo->query('SELECT username, COUNT(*) AS n FROM premium_grants GROUP BY username')->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $counts[strtolower((string)$c['username'])] = (int)$c['n'];
    }
    // Members who got Premium before grants were tracked: use their last approved payment, if any.
    $paid = [];
    foreach ($pdo->query(
        'SELECT r.username, r.txn_ref, r.updated_at
         FROM premium_requests r
         JOIN (SELECT username, MAX(id) AS mid FROM premium_requests WHERE status = \'approved\' GROUP BY username) x ON x.mid = r.id'
    )->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $paid[strtolower((string)$p['username'])] = $p;
    }

    $now = time();
    $out = [];
    foreach ($members as $m) {
        $key = strtolower((string)$m['username']);
        $source = 'unknown';
        $by = '';
        $ref = '';
        $tracked = false;
        if (isset($latest[$key])) {
            $source = (string)$latest[$key]['source'];
            $by = (string)$latest[$key]['granted_by'];
            $ref = (string)$latest[$key]['ref'];
            $tracked = true;
        } elseif (isset($paid[$key])) {
            $source = 'payment';
            $ref = (string)$paid[$key]['txn_ref'];
        }
        $expires = strtotime((string)$m['expires_at']);
        $isActive = vs_premium_is_active($m);
        $def = vs_premium_plan_def((string)$m['plan']);
        $out[] = [
            'username'     => (string)$m['username'],
            'plan'         => (string)$m['plan'],
            'plan_label'   => $def['label'] ?? (string)$m['plan'],
            'tier'         => strpos((string)$m['plan'], 'plus') === 0 ? 'plus' : 'premium',
            'status'       => $isActive ? 'active' : ((string)$m['status'] === 'cancelled' ? 'cancelled' : 'expired'),
            'started_at'   => (string)$m['started_at'],
            'expires_at'   => (string)$m['expires_at'],
            'seconds_left' => $isActive ? max(0, $expires - $now) : 0,
            'days_left'    => $isActive ? (int)ceil(max(0, $expires - $now) / 86400) : 0,
            'source'       => $source,
            'source_label' => vs_premium_source_label($source),
            'granted_by'   => $by,
            'ref'          => $ref,
            'times_given'  => $counts[$key] ?? 0,
            'tracked'      => $tracked,
        ];
    }
    return $out;
}

function vs_premium_cancel(PDO $pdo, string $username): bool
{
    $st = $pdo->prepare('UPDATE premium_members SET status = \'cancelled\', updated_at = NOW() WHERE username = :u');
    return $st->execute([':u' => $username]);
}

function vs_premium_all(PDO $pdo): array
{
    // A member whose Premium ended and whose "Premium ended" alert an admin has already seen
    // is no longer shown in the admin list (the payment history stays in premium_requests).
    $notSeen = 'NOT EXISTS (
                 SELECT 1 FROM premium_end_alerts a
                 WHERE a.username = m.username AND a.started_at = m.started_at
                   AND a.expires_at = m.expires_at AND a.seen_at IS NOT NULL
             )';
    // Only members whose account still exists: a deleted account disappears from the list.
    try {
        return $pdo->query(
            'SELECT m.* FROM premium_members m
             WHERE EXISTS (
                 SELECT 1 FROM users u
                 WHERE LOWER(u.username) COLLATE utf8mb4_general_ci = LOWER(m.username) COLLATE utf8mb4_general_ci
             ) AND ' . $notSeen . '
             ORDER BY m.started_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // If the users table cannot be read this way, fall back to the plain list.
        try {
            return $pdo->query('SELECT m.* FROM premium_members m WHERE ' . $notSeen . ' ORDER BY m.started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            return $pdo->query('SELECT * FROM premium_members ORDER BY started_at DESC')->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

/**
 * Creates a "Premium ended" alert for every Premium period that has ended (expired or cancelled)
 * and has no alert yet. Safe to call on every admin page load: each period is alerted only once.
 */
function vs_premium_sync_end_alerts(PDO $pdo): void
{
    $insert = 'INSERT IGNORE INTO premium_end_alerts (username, plan, started_at, expires_at, ended_at, created_at)
               SELECT m.username, m.plan, m.started_at, m.expires_at,
                      IF(m.status = \'active\', m.expires_at, m.updated_at), NOW()
               FROM premium_members m
               WHERE (m.status <> \'active\' OR m.expires_at <= NOW())';
    try {
        $pdo->exec($insert . ' AND EXISTS (
            SELECT 1 FROM users u
            WHERE LOWER(u.username) COLLATE utf8mb4_general_ci = LOWER(m.username) COLLATE utf8mb4_general_ci
        )');
    } catch (Throwable $e) {
        $pdo->exec($insert);
    }
}

/** Premium-ended alerts, newest first. By default only the ones no admin has seen yet. */
function vs_premium_end_alerts(PDO $pdo, bool $unseenOnly = true, int $limit = 50): array
{
    $st = $pdo->prepare(
        'SELECT * FROM premium_end_alerts' . ($unseenOnly ? ' WHERE seen_at IS NULL' : '') .
        ' ORDER BY ended_at DESC, id DESC LIMIT ' . (int)$limit
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function vs_premium_end_alerts_unseen(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COUNT(*) FROM premium_end_alerts WHERE seen_at IS NULL')->fetchColumn();
}

/** An admin has seen these alerts: those members now leave the admin Members list. */
function vs_premium_end_alerts_mark_seen(PDO $pdo, array $ids): void
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn($i) => $i > 0));
    if (!$ids) {
        return;
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('UPDATE premium_end_alerts SET seen_at = NOW() WHERE seen_at IS NULL AND id IN (' . $in . ')');
    $st->execute($ids);
}

/** Save a "I paid" request. Returns null if saved, or an error message. */
function vs_premium_request_create(PDO $pdo, string $username, string $plan, string $ref): ?string
{
    if ($username === '' || !isset(VS_PREMIUM_PLANS[$plan])) {
        return 'Unknown plan.';
    }
    $ref = trim($ref);
    if (strlen($ref) < 4 || strlen($ref) > 100) {
        return 'Enter the transaction ID from your payment (4 to 100 characters).';
    }
    $cur = vs_premium_get($pdo, $username);
    if (vs_premium_is_active($cur) && strpos((string)$cur['plan'], 'plus') === 0 && strpos($plan, 'plus') !== 0) {
        return 'You already have Premium Plus.';
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM premium_requests WHERE username = :u AND status = \'pending\'');
    $st->execute([':u' => $username]);
    if ((int)$st->fetchColumn() >= 3) {
        return 'You already have payments waiting to be confirmed.';
    }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO premium_requests (username, plan, amount, txn_ref, status, created_at, updated_at)
             VALUES (:u, :p, :a, :r, \'pending\', NOW(), NOW())'
        );
        $ins->execute([
            ':u' => $username,
            ':p' => $plan,
            ':a' => VS_PREMIUM_PLANS[$plan]['price'],
            ':r' => $ref,
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            return 'That transaction ID has already been used.';
        }
        throw $e;
    }
    // Tell the owner a payment is waiting to be checked. Never allowed to break the request.
    $ownerAlertFile = __DIR__ . '/adminpanel/owner-alert.php';
    if (is_file($ownerAlertFile)) {
        try {
            require_once $ownerAlertFile;
            vs_owner_alert_after_response(
                $pdo, 'premium', 'Premium payment waiting: ' . $username,
                'Plan: ' . (VS_PREMIUM_PLANS[$plan]['label'] ?? $plan) . ', amount: ' . number_format((float)VS_PREMIUM_PLANS[$plan]['price'], 2)
                . ', transaction ID: ' . $ref . '. Check the payment, then approve or reject it in the admin panel.'
            );
        } catch (Throwable $e) {
            error_log('owner alert failed: ' . $e->getMessage());
        }
    }
    return null;
}

function vs_premium_pending(PDO $pdo, string $username): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM premium_requests WHERE username = :u AND status = \'pending\'');
    $st->execute([':u' => $username]);
    return (int)$st->fetchColumn() > 0;
}

function vs_premium_requests(PDO $pdo, int $limit = 100): array
{
    $st = $pdo->prepare(
        'SELECT * FROM premium_requests ORDER BY (status = \'pending\') DESC, created_at DESC LIMIT ' . (int)$limit
    );
    $st->execute();
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Approve (turns Premium on) or reject a pending request. */
function vs_premium_review(PDO $pdo, int $id, bool $approve): bool
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT * FROM premium_requests WHERE id = :i AND status = \'pending\' FOR UPDATE');
        $st->execute([':i' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            $pdo->rollBack();
            return false;
        }
        $up = $pdo->prepare('UPDATE premium_requests SET status = :s, updated_at = NOW() WHERE id = :i');
        $up->execute([':s' => $approve ? 'approved' : 'rejected', ':i' => $id]);
        if ($approve) {
            vs_premium_set($pdo, (string)$r['username'], (string)$r['plan'], 'payment',
                trim((string)($_SESSION['username'] ?? '')), (string)$r['txn_ref']);
        }
        $pdo->commit();
        vs_premium_after_review($pdo, $r, $approve);
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Plug-in point for a payment gateway (Stripe, Safepay, JazzCash, ...): call this from the gateway's
 * "payment confirmed" webhook. It records the payment as approved, switches Premium on and e-mails the
 * user. Returns false if this transaction was already processed (so a repeated webhook does nothing).
 */
function vs_premium_confirm_payment(PDO $pdo, string $username, string $plan, string $txn, ?float $amount = null): bool
{
    $def = vs_premium_plan_def($plan);
    $txn = trim($txn);
    if ($username === '' || $def === null || $txn === '') {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO premium_requests (username, plan, amount, txn_ref, status, created_at, updated_at)
             VALUES (:u, :p, :a, :r, \'approved\', NOW(), NOW())'
        );
        $ins->execute([':u' => $username, ':p' => $plan, ':a' => $amount ?? $def['price'], ':r' => mb_substr($txn, 0, 100)]);
        $id = (int)$pdo->lastInsertId();
        vs_premium_set($pdo, $username, $plan, 'stripe', '', mb_substr($txn, 0, 100));
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e->getCode() === '23000') {
            return false;
        }
        throw $e;
    }
    vs_premium_after_review($pdo, ['id' => $id, 'username' => $username, 'plan' => $plan, 'amount' => $amount ?? $def['price'], 'txn_ref' => $txn], true);
    return true;
}

/* ------------------------------------------------------------------ */
/* E-mails to the buyer (uses the e-mail settings on the System check page) */
/* ------------------------------------------------------------------ */

function vs_premium_email_user(PDO $pdo, string $username, string $subject, string $body): bool
{
    try {
        $core = __DIR__ . '/adminpanel/system-check-core.php';
        if (!is_file($core)) {
            return false;
        }
        require_once $core;
        if (!vs_syscheck_mail_configured()) {
            return false;
        }
        $st = $pdo->prepare('SELECT * FROM users WHERE LOWER(username) = LOWER(:u) LIMIT 1');
        $st->execute([':u' => $username]);
        $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $to = '';
        foreach (['email', 'email_address', 'Email'] as $c) {
            if (!empty($u[$c])) {
                $to = trim((string)$u[$c]);
                break;
            }
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $err = '';
        return vs_syscheck_smtp_send(vs_syscheck_mail_settings(), $to, $subject, $body, $err);
    } catch (Throwable $e) {
        return false;
    }
}

/** Same, but after the page has been sent, so a slow mail server never makes an admin or buyer wait. */
function vs_premium_email_user_later(PDO $pdo, string $username, string $subject, string $body): void
{
    register_shutdown_function(static function () use ($pdo, $username, $subject, $body): void {
        ignore_user_abort(true);
        @set_time_limit(60);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        vs_premium_email_user($pdo, $username, $subject, $body);
    });
}

/** Tells the buyer their payment was confirmed or not. */
function vs_premium_after_review(PDO $pdo, array $r, bool $approve): void
{
    try {
        $def = vs_premium_plan_def((string)$r['plan']);
        $label = $def['label'] ?? (string)$r['plan'];
        $amount = number_format((float)$r['amount'], 2);
        $who = (string)$r['username'];
        if ($approve) {
            $row = vs_premium_get($pdo, $who);
            $until = $row ? date('F j, Y', strtotime((string)$row['expires_at'])) : '';
            vs_premium_email_user_later($pdo, $who, 'Your VibeStream Premium is active',
                "Hi " . $who . ",\n\nWe confirmed your payment of $" . $amount . " (" . $label . ", transaction " . (string)$r['txn_ref'] . ").\n"
                . ($until !== '' ? "Your Premium is active until " . $until . ".\n" : '')
                . "\nYou can open your receipt on the Premium page, under \"Your payments\".\n\nThank you for supporting VibeStream!\n");
        } else {
            vs_premium_email_user_later($pdo, $who, 'We could not confirm your VibeStream payment',
                "Hi " . $who . ",\n\nWe could not confirm your payment of $" . $amount . " (" . $label . ", transaction " . (string)$r['txn_ref'] . ").\n"
                . "If you did pay, please contact support with your payment receipt and we will fix it.\n");
        }
    } catch (Throwable $e) {
        // never break an approval because of e-mail
    }
}

/**
 * E-mails members whose Premium ends in 3 days, and those whose Premium just ended. Each e-mail is sent once.
 * Run it from system-monitor.php (hourly or daily). Returns how many e-mails were sent.
 */
function vs_premium_send_reminders(PDO $pdo): int
{
    vs_premium_ensure($pdo);
    $sent = 0;
    $jobs = [
        ['soon', "m.status = 'active' AND m.expires_at > NOW() AND m.expires_at <= DATE_ADD(NOW(), INTERVAL 3 DAY)"],
        ['ended', "m.status = 'active' AND m.expires_at <= NOW() AND m.expires_at > DATE_SUB(NOW(), INTERVAL 3 DAY)"],
    ];
    foreach ($jobs as [$kind, $where]) {
        $rows = $pdo->query('SELECT m.* FROM premium_members m WHERE ' . $where)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $m) {
            $mark = $pdo->prepare('INSERT IGNORE INTO premium_reminders (username, expires_at, kind, sent_at) VALUES (:u, :e, :k, NOW())');
            $mark->execute([':u' => $m['username'], ':e' => $m['expires_at'], ':k' => $kind]);
            if ($mark->rowCount() !== 1) {
                continue;   // already sent for this period
            }
            $date = date('F j, Y', strtotime((string)$m['expires_at']));
            $ok = $kind === 'soon'
                ? vs_premium_email_user($pdo, (string)$m['username'], 'Your VibeStream Premium ends soon',
                    "Hi " . $m['username'] . ",\n\nYour Premium ends on " . $date . ".\nTo keep no ads, high quality and downloads, pay again on the Premium page before then.\n")
                : vs_premium_email_user($pdo, (string)$m['username'], 'Your VibeStream Premium has ended',
                    "Hi " . $m['username'] . ",\n\nYour Premium ended on " . $date . ".\nYou can get it back any time on the Premium page.\n");
            $sent += $ok ? 1 : 0;
        }
    }
    return $sent;
}

/** A buyer's own payments, newest first (shown on the Premium page with a receipt link). */
function vs_premium_user_history(PDO $pdo, string $username, int $limit = 10): array
{
    $st = $pdo->prepare('SELECT id, plan, amount, status, created_at FROM premium_requests WHERE username = :u ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([':u' => $username]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $def = vs_premium_plan_def((string)$r['plan']);
        $out[] = ['id' => (int)$r['id'], 'label' => $def['label'] ?? (string)$r['plan'], 'amount' => (float)$r['amount'],
                  'status' => (string)$r['status'], 'date' => substr((string)$r['created_at'], 0, 10)];
    }
    return $out;
}

/** Admins (VS_PREMIUM_ADMINS) always have Premium, with no payment and no expiry. */
function vs_premium_is_admin(string $username): bool
{
    if ($username === '') {
        return false;
    }
    if (in_array(strtolower(trim($username)), array_map('strtolower', VS_PREMIUM_ADMINS), true)) {
        return true;
    }
    // Anyone signed in with the Admin role (the same check the admin panel uses).
    if (strtolower(trim((string)($_SESSION['username'] ?? ''))) === strtolower(trim($username))) {
        if (isset($_SESSION['AdminSession'])) {
            return true;
        }
        if (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0) {
            return true;
        }
    }
    return false;
}

/** State handed to premium.js on every page (header.php prints it). */
function vs_premium_state(?PDO $pdo, string $username): array
{
    if (empty($_SESSION['premium_csrf'])) {
        $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
    }
    $state = [
        'loggedIn' => $username !== '',
        'active'   => false,
        'pending'  => false,
        'plan'     => '',
        'since'    => 0,
        'expires'  => 0,
        'plans'    => VS_PREMIUM_PLANS,
        'payInfo'  => vs_premium_pay_info(),
        'history'  => [],
        'limits'   => VS_PREMIUM_LIMITS['free'],
        'points'   => 0,
        'pointsAvailable' => false,
        'checkinClaimed' => false,
        'checkinAwarded' => false,
        'csrf'     => $_SESSION['premium_csrf'],
        'leaders'  => [],
        'leadersWeek' => [],
        'leadersMonth' => [],
    ];
    if ($pdo) {
        try {
            $state['leaders'] = vs_rewards_decorate_leaders(vs_rewards_leaderboard($pdo, 5, 'all'), $username, $pdo);
            $state['leadersWeek'] = vs_rewards_decorate_leaders(vs_rewards_leaderboard($pdo, 5, 'week'), $username, $pdo);
            $state['leadersMonth'] = vs_rewards_decorate_leaders(vs_rewards_leaderboard($pdo, 5, 'month'), $username, $pdo);
        } catch (Throwable $e) {
            // the leaderboard must never break a page
        }
    }
    if (vs_premium_is_admin($username)) {
        $state['active']  = true;
        $state['plan']    = 'admin';
        $state['since']   = time() * 1000;
        $state['expires'] = (time() + 3153600000) * 1000; // about 100 years
        $state['limits']  = VS_PREMIUM_LIMITS['plus'];
        if ($pdo && $username !== '') {
            try {
                vs_premium_ensure($pdo);
                vs_rewards_backfill_uploads($pdo, $username);
                $state['points'] = vs_rewards_points($pdo, $username);
                $state['checkinClaimed'] = vs_rewards_daily_checkin_claimed($pdo, $username);
                $state['pointsAvailable'] = true;
            } catch (Throwable $e) {
                error_log('VibeStream Premium/rewards state could not be loaded: ' . $e->getMessage());
            }
        }
        return $state;
    }
    if ($pdo && $username !== '') {
        try {
            vs_premium_ensure($pdo);
            $row = vs_premium_get($pdo, $username);
            if (vs_premium_is_active($row)) {
                $state['active']  = true;
                $state['plan']    = $row['plan'];
                $state['since']   = strtotime((string)$row['started_at']) * 1000;
                $state['expires'] = strtotime((string)$row['expires_at']) * 1000;
            }
            $state['pending'] = vs_premium_pending($pdo, $username);
            $state['history'] = vs_premium_user_history($pdo, $username, 10);
            $state['limits']  = VS_PREMIUM_LIMITS[vs_premium_tier($row)];
            vs_rewards_backfill_uploads($pdo, $username);
            $state['points']  = vs_rewards_points($pdo, $username);
            $state['checkinClaimed'] = vs_rewards_daily_checkin_claimed($pdo, $username);
            $state['pointsAvailable'] = true;
        } catch (Throwable $e) {
            error_log('VibeStream Premium/rewards state could not be loaded: ' . $e->getMessage());
        }
    }
    return $state;
}