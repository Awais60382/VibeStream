<?php

function vs_dashboard_table_exists(mysqli $conn, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $exists = (int)$stmt->get_result()->fetch_row()[0] > 0;
    $stmt->close();
    return $cache[$table] = $exists;
}

function vs_dashboard_log_view(mysqli $conn, string $contentType, string $contentId, string $creator): void
{
    try {
        if (!vs_dashboard_table_exists($conn, 'video_view_events')) {
            return;
        }
        $stmt = $conn->prepare(
            'INSERT INTO `video_view_events` (`content_type`, `content_id`, `creator`, `viewed_at`)
             VALUES (?, ?, ?, NOW())'
        );
        $stmt->bind_param('sss', $contentType, $contentId, $creator);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Dashboard view event could not be saved: ' . $error->getMessage());
    }
}

function vs_dashboard_track_activity_day(): void
{
    static $tracked = false;
    if ($tracked) {
        return;
    }
    global $pdo;
    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    if ($userId < 1 || !($pdo instanceof PDO)) {
        return;
    }

    try {
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_activity_days'");
        $existsStmt->execute();
        $exists = (int)$existsStmt->fetchColumn() > 0;
        if (!$exists) {
            return;
        }
        $stmt = $pdo->prepare('INSERT IGNORE INTO `user_activity_days` (`user_id`, `active_date`) VALUES (:user_id, CURDATE())');
        $stmt->execute([':user_id' => $userId]);
        $tracked = true;
    } catch (Throwable $error) {
        error_log('Dashboard activity day could not be saved: ' . $error->getMessage());
    }
}

function vs_dashboard_activity_streak_html(?PDO $pdo): string
{
    static $rendered = false;
    if ($rendered || !($pdo instanceof PDO)) {
        return '';
    }

    $userId = (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0);
    if ($userId < 1) {
        return '';
    }

    try {
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_activity_days'");
        $existsStmt->execute();
        if (!(int)$existsStmt->fetchColumn()) {
            return '';
        }

        $today = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
        $weekStmt = $pdo->prepare(
            'SELECT `active_date`
             FROM `user_activity_days`
             WHERE `user_id` = :user_id
               AND `active_date` >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
               AND `active_date` <= CURDATE()
             ORDER BY `active_date` ASC'
        );
        $weekStmt->execute([':user_id' => $userId]);
        $weekDates = array_fill_keys($weekStmt->fetchAll(PDO::FETCH_COLUMN), true);

        $daysStmt = $pdo->prepare(
            'SELECT `active_date`
             FROM `user_activity_days`
             WHERE `user_id` = :user_id AND `active_date` <= CURDATE()
             ORDER BY `active_date` DESC'
        );
        $daysStmt->execute([':user_id' => $userId]);
        $activeDates = $daysStmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $error) {
        error_log('Dashboard activity streak could not be loaded: ' . $error->getMessage());
        return '';
    }

    $todayDate = DateTimeImmutable::createFromFormat('!Y-m-d', $today);
    if (!$todayDate) {
        error_log('Dashboard activity streak received an invalid database date.');
        return '';
    }

    $activeThisWeek = count($weekDates);
    $activeDateSet = array_fill_keys($activeDates, true);
    $expectedDate = isset($activeDateSet[$today])
        ? $todayDate
        : $todayDate->modify('-1 day');
    $streak = 0;
    while (isset($activeDateSet[$expectedDate->format('Y-m-d')])) {
        $streak++;
        $expectedDate = $expectedDate->modify('-1 day');
    }

    $weekdayLabels = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
    $monday = $todayDate->modify('-' . ((int)$todayDate->format('N') - 1) . ' days');
    $weekMarkup = '';
    foreach ($weekdayLabels as $offset => $label) {
        $date = $monday->modify('+' . $offset . ' days')->format('Y-m-d');
        $isActive = isset($weekDates[$date]);
        $weekMarkup .= '<span class="vs-activity-day' . ($isActive ? ' is-active' : '') . '" aria-label="'
            . $label . ': ' . ($isActive ? 'active' : 'no activity') . '">' . $label . '</span>';
    }

    $rendered = true;
    return '<style>
        .vs-activity-streak{position:fixed;z-index:998;right:18px;bottom:18px;width:min(270px,calc(100vw - 36px));padding:14px 16px;border:1px solid var(--border,rgba(127,127,127,.25));border-radius:14px;background:var(--surface,#fff);color:var(--text,#222);box-shadow:0 8px 28px rgba(0,0,0,.18);font:inherit}
        .vs-activity-streak-head{display:flex;justify-content:space-between;align-items:center;gap:12px;font-weight:700}
        .vs-activity-streak-count{font-size:13px;color:var(--muted,#777)}
        .vs-activity-week{display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-top:10px}
        .vs-activity-day{display:grid;place-items:center;min-height:28px;border-radius:8px;background:rgba(127,127,127,.12);font-size:11px;font-weight:700;color:var(--muted,#777)}
        .vs-activity-day.is-active{background:#e8f7ed;color:#16743a}
        .vs-activity-streak-foot{margin-top:9px;font-size:12px;color:var(--muted,#777)}
        .vs-activity-streak-close{border:0;background:transparent;color:inherit;font:inherit;font-size:18px;line-height:1;cursor:pointer;padding:2px 4px}
        html[data-theme="dark"] .vs-activity-day.is-active{background:rgba(46,160,91,.2);color:#83dda0}
    </style>
    <aside class="vs-activity-streak" id="vsActivityStreak" aria-label="Your activity this week">
        <div class="vs-activity-streak-head">
            <span>This week <span class="vs-activity-streak-count">' . $activeThisWeek . '/7 active days</span></span>
            <button type="button" class="vs-activity-streak-close" aria-label="Dismiss activity summary">&times;</button>
        </div>
        <div class="vs-activity-week" aria-label="Weekly activity">' . $weekMarkup . '</div>
        <div class="vs-activity-streak-foot">' . $streak . ' day' . ($streak === 1 ? '' : 's') . ' in a row</div>
    </aside>
    <script>
        (function(){var card=document.getElementById("vsActivityStreak");if(!card)return;var key="vsActivityStreakDismissed:' . $userId . '";try{if(sessionStorage.getItem(key)==="1"){card.remove();return;}card.querySelector("button").addEventListener("click",function(){sessionStorage.setItem(key,"1");card.remove();});}catch(error){card.querySelector("button").addEventListener("click",function(){card.remove();});}})();
    </script>';
}

function vs_dashboard_log_signup_ip(mysqli $conn, int $userId): void
{
    $key = vs_dashboard_ip_hash_key();
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($userId < 1 || $key === '' || $ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return;
    }

    try {
        $conn->query(
            'CREATE TABLE IF NOT EXISTS `signup_ip_hashes` (
                `user_id` BIGINT UNSIGNED NOT NULL,
                `ip_hash` CHAR(64) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                KEY `idx_signup_ip_hashes_hash` (`ip_hash`),
                KEY `idx_signup_ip_hashes_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ipHash = hash_hmac('sha256', $ip, $key);
        $stmt = $conn->prepare('INSERT INTO `signup_ip_hashes` (`user_id`, `ip_hash`) VALUES (?, ?)');
        $stmt->bind_param('is', $userId, $ipHash);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        error_log('Dashboard signup signal could not be saved: ' . $error->getMessage());
    }
}

function vs_dashboard_ip_hash_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $key = trim((string)(getenv('VIBESTREAM_IP_HASH_KEY') ?: ''));
    if (strlen($key) >= 32) {
        return $key;
    }

    $localKeyFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private'
        . DIRECTORY_SEPARATOR . 'VibeStream' . DIRECTORY_SEPARATOR . 'signup-ip-hash-key.php';
    if (!is_file($localKeyFile)) {
        return $key = '';
    }

    try {
        $localKey = require $localKeyFile;
        if (is_string($localKey) && strlen(trim($localKey)) >= 32) {
            return $key = trim($localKey);
        }
        error_log('The local signup IP hash key is invalid; expected at least 32 characters.');
    } catch (Throwable $error) {
        error_log('The local signup IP hash key could not be read: ' . $error->getMessage());
    }
    return $key = '';
}

function vs_site_announcement_html(?PDO $pdo = null): string
{
    $pdo = $pdo ?? ($GLOBALS['pdo'] ?? null);
    vs_dashboard_track_activity_day();
    $announcementHtml = '';
    if (!($pdo instanceof PDO)) {
        return vs_dashboard_activity_streak_html(null);
    } else {
        try {
            $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'site_announcements'");
            $existsStmt->execute();
            if ((int)$existsStmt->fetchColumn()) {
                $message = trim((string)$pdo->query('SELECT `message` FROM `site_announcements` WHERE `id` = 1 LIMIT 1')->fetchColumn());
                if ($message !== '') {
                    $announcementHtml = '<style>
                        .vs-site-announcement{position:fixed;z-index:999;top:var(--navbar-height,72px);left:0;right:0;padding:11px 18px;background:#742626;color:#fff;text-align:center;font-weight:600}
                        body:has(.vs-site-announcement) .app-layout{padding-top:calc(var(--navbar-height,72px) + 46px)!important}
                    </style><div class="vs-site-announcement" role="status"><span aria-hidden="true">📢</span> '
                        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
                }
            }
        } catch (Throwable $error) {
            error_log('Site announcement could not be loaded: ' . $error->getMessage());
        }
    }

    return $announcementHtml . vs_dashboard_activity_streak_html($pdo);
}
