<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['AdminSession'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Administrator access required.']);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/../dashboard-events.php';

$adminName = trim((string)($_SESSION['AdminSession'] ?? 'Admin'));
$csrf = (string)($_SESSION['vs_insights_csrf'] ?? '');
$ipHashKey = vs_dashboard_ip_hash_key();
$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table_name');
    $stmt->execute([':table_name' => $table]);
    return (int)$stmt->fetchColumn() > 0;
};
$columns = static function (PDO $pdo, string $table): array {
    return vs_cols($pdo, $table);
};
$pick = static function (array $columns, array $candidates): ?string {
    return vs_pick($columns, $candidates);
};
$dateColumnNames = ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'timestamp'];
$response = [
    'ok' => true,
    'ts' => time(),
    'live' => ['views_per_minute' => 0, 'top_video' => null, 'trending' => [], 'rising' => [], 'spikes' => []],
    'spam' => ['same_ip_accounts' => [], 'ip_hashing_enabled' => false],
    'storage' => [],
    'broken' => ['count' => 0, 'items' => []],
    'retention' => ['available' => false, 'cohorts' => ['day1' => null, 'day7' => null, 'day30' => null]],
    'goals' => ['signups' => ['current' => 0, 'target' => 0], 'uploads' => ['current' => 0, 'target' => 0]],
    'announcement' => '',
    'notes' => [],
    'summary' => ['email' => '', 'frequency' => 'off'],
    'search' => [],
];

try {
    if (!$tableExists($pdo, 'video_view_events')) {
        throw new RuntimeException('Run the Live Insights block in migrations.sql first.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page and try again.']);
            exit;
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'announcement') {
            $message = trim((string)($_POST['message'] ?? ''));
            if (mb_strlen($message) > 500) {
                throw new InvalidArgumentException('Announcement must be 500 characters or fewer.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO `site_announcements` (`id`, `message`, `updated_by`)
                 VALUES (1, :message, :admin)
                 ON DUPLICATE KEY UPDATE `message` = VALUES(`message`), `updated_by` = VALUES(`updated_by`)'
            );
            $stmt->execute([':message' => $message, ':admin' => $adminName]);
        } elseif ($action === 'goal') {
            $metric = (string)($_POST['metric'] ?? '');
            $target = filter_var($_POST['target'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000000]]);
            if (!in_array($metric, ['signups', 'uploads'], true) || $target === false) {
                throw new InvalidArgumentException('Choose a valid goal and a target from 0 to 1,000,000.');
            }
            $stmt = $pdo->prepare(
                'INSERT INTO `admin_dashboard_goals` (`goal_month`, `metric`, `target`, `updated_by`)
                 VALUES (:month, :metric, :target, :admin)
                 ON DUPLICATE KEY UPDATE `target` = VALUES(`target`), `updated_by` = VALUES(`updated_by`)'
            );
            $stmt->execute([':month' => date('Y-m'), ':metric' => $metric, ':target' => $target, ':admin' => $adminName]);
        } elseif ($action === 'note') {
            $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $note = trim((string)($_POST['note'] ?? ''));
            if (!$userId || $note === '' || mb_strlen($note) > 2000) {
                throw new InvalidArgumentException('Select a user and enter a note of at most 2,000 characters.');
            }
            $userColumns = $columns($pdo, 'users');
            $userIdColumn = $pick($userColumns, ['id', 'user_id']);
            if (!$userIdColumn) {
                throw new RuntimeException('The users table has no supported ID column.');
            }
            $check = $pdo->prepare('SELECT 1 FROM `users` WHERE ' . vs_q($userIdColumn) . ' = :id LIMIT 1');
            $check->execute([':id' => $userId]);
            if (!$check->fetchColumn()) {
                throw new InvalidArgumentException('That user no longer exists.');
            }
            $stmt = $pdo->prepare('INSERT INTO `admin_user_notes` (`user_id`, `admin_name`, `note`) VALUES (:user_id, :admin, :note)');
            $stmt->execute([':user_id' => $userId, ':admin' => $adminName, ':note' => $note]);
        } elseif ($action === 'delete_note') {
            $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$noteId) {
                throw new InvalidArgumentException('Choose a valid note.');
            }
            $stmt = $pdo->prepare('DELETE FROM `admin_user_notes` WHERE `id` = :id AND `admin_name` = :admin');
            $stmt->execute([':id' => $noteId, ':admin' => $adminName]);
        } elseif ($action === 'summary') {
            $email = trim((string)($_POST['email'] ?? ''));
            $frequency = (string)($_POST['frequency'] ?? 'off');
            $adminKey = trim((string)($_SESSION['AdminID'] ?? $_SESSION['AdminSession'] ?? ''));
            if ($adminKey === '' || !in_array($frequency, ['off', 'daily', 'weekly'], true) || ($frequency !== 'off' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new InvalidArgumentException('Enter a valid email and choose daily, weekly, or off.');
            }
            if ($frequency === 'off') {
                $stmt = $pdo->prepare('DELETE FROM `admin_summary_settings` WHERE `admin_key` = :key');
                $stmt->execute([':key' => $adminKey]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO `admin_summary_settings` (`admin_key`, `email`, `frequency`)
                     VALUES (:key, :email, :frequency)
                     ON DUPLICATE KEY UPDATE `email` = VALUES(`email`), `frequency` = VALUES(`frequency`)'
                );
                $stmt->execute([':key' => $adminKey, ':email' => $email, ':frequency' => $frequency]);
            }
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }

        echo json_encode(['ok' => true, 'message' => 'Saved.']);
        exit;
    }

    $views = $pdo->query(
        "SELECT `content_type`, `content_id`, `creator`, COUNT(*) AS `views`
         FROM `video_view_events` WHERE `viewed_at` >= DATE_SUB(NOW(), INTERVAL 60 MINUTE)
         GROUP BY `content_type`, `content_id`, `creator` ORDER BY `views` DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    $titleById = [];
    if ($views && $tableExists($pdo, 'videos')) {
        $videoColumns = $columns($pdo, 'videos');
        $videoIdColumn = $pick($videoColumns, ['id', 'video_id', 'vid']);
        $videoTitleColumn = $pick($videoColumns, ['title', 'video_title', 'name']);
        if ($videoIdColumn && $videoTitleColumn) {
            $videoIds = array_values(array_unique(array_map(
                static fn(array $row): string => (string)$row['content_id'],
                $views
            )));
            $idParams = [];
            foreach ($videoIds as $index => $videoId) {
                $idParams[':video_id_' . $index] = $videoId;
            }
            $titleStmt = $pdo->prepare(
                'SELECT ' . vs_q($videoIdColumn) . ' AS `content_id`, ' . vs_q($videoTitleColumn) . ' AS `title`
                 FROM `videos` WHERE ' . vs_q($videoIdColumn) . ' IN (' . implode(', ', array_keys($idParams)) . ')'
            );
            $titleStmt->execute($idParams);
            foreach ($titleStmt->fetchAll(PDO::FETCH_ASSOC) as $video) {
                $titleById[(string)$video['content_id']] = trim((string)$video['title']);
            }
        }
    }
    $minute = (int)$pdo->query('SELECT COUNT(*) FROM `video_view_events` WHERE `viewed_at` >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)')->fetchColumn();
    $response['live']['views_per_minute'] = $minute;
    $response['live']['trending'] = array_map(static function (array $row) use ($titleById): array {
        $id = (string)$row['content_id'];
        return [
            'type' => (string)$row['content_type'],
            'id' => $id,
            'title' => $titleById[$id] ?? '',
            'creator' => (string)$row['creator'],
            'views' => (int)$row['views'],
        ];
    }, $views);
    if ($views) {
        $response['live']['top_video'] = $response['live']['trending'][0];
    }
    $rising = $pdo->query(
        "SELECT MIN(CASE
                WHEN LOWER(TRIM(`creator`)) IN ('vibestream', 'vibestreams') THEN 'VibeStream'
                ELSE TRIM(`creator`)
            END) AS `creator`,
            SUM(`viewed_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS `current_views`,
            SUM(`viewed_at` < DATE_SUB(NOW(), INTERVAL 7 DAY) AND `viewed_at` >= DATE_SUB(NOW(), INTERVAL 14 DAY)) AS `previous_views`
         FROM `video_view_events` WHERE `creator` <> '' AND `viewed_at` >= DATE_SUB(NOW(), INTERVAL 14 DAY)
         GROUP BY CASE
                WHEN LOWER(TRIM(`creator`)) IN ('vibestream', 'vibestreams') THEN 'vibestream'
                ELSE LOWER(TRIM(`creator`))
            END
         ORDER BY `current_views` DESC LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rising as $creator) {
        $current = (int)$creator['current_views'];
        $previous = (int)$creator['previous_views'];
        $response['live']['rising'][] = [
            'creator' => (string)$creator['creator'],
            'current' => $current,
            'previous' => $previous,
            'change' => $previous > 0 ? round(($current - $previous) / $previous * 100) : ($current > 0 ? 100 : 0),
        ];
    }

    $users = $columns($pdo, 'users');
    $signupDate = $pick($users, $dateColumnNames);
    if ($signupDate) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE ' . vs_q($signupDate) . ' >= :start');
        $stmt->execute([':start' => date('Y-m-01 00:00:00')]);
        $response['goals']['signups']['current'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE ' . vs_q($signupDate) . ' >= :now1 AND ' . vs_q($signupDate) . ' < :now2');
        $stmt->execute([':now1' => date('Y-m-d H:i:s', time() - 3600), ':now2' => date('Y-m-d H:i:s')]);
        $currentSignups = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE ' . vs_q($signupDate) . ' >= :prev1 AND ' . vs_q($signupDate) . ' < :prev2');
        $stmt->execute([':prev1' => date('Y-m-d H:i:s', time() - 7200), ':prev2' => date('Y-m-d H:i:s', time() - 3600)]);
        $previousSignups = (int)$stmt->fetchColumn();
        if ($currentSignups >= 5 && $currentSignups >= max(5, $previousSignups * 2)) {
            $response['live']['spikes'][] = ['kind' => 'signup', 'message' => $currentSignups . ' signups in the last hour, versus ' . $previousSignups . ' in the previous hour.'];
        }
    }

    $uploadCount = 0;
    $uploadColumns = [];
    foreach (['videos', 'shorts'] as $contentTable) {
        $contentColumns = $columns($pdo, $contentTable);
        if (!$contentColumns) {
            continue;
        }
        $uploadDate = $pick($contentColumns, $dateColumnNames);
        if (!$uploadDate) {
            continue;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($contentTable) . ' WHERE ' . vs_q($uploadDate) . ' >= :start');
        $stmt->execute([':start' => date('Y-m-01 00:00:00')]);
        $response['goals']['uploads']['current'] += (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($contentTable) . ' WHERE ' . vs_q($uploadDate) . ' >= :start');
        $stmt->execute([':start' => date('Y-m-d H:i:s', time() - 3600)]);
        $uploadCount += (int)$stmt->fetchColumn();
        $uploadColumns[$contentTable] = [$contentColumns, $uploadDate];
    }
    $pastUploads = 0;
    foreach ($uploadColumns as $contentTable => [$contentColumns, $uploadDate]) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($contentTable) . ' WHERE ' . vs_q($uploadDate) . ' >= :start AND ' . vs_q($uploadDate) . ' < :end');
        $stmt->execute([':start' => date('Y-m-d H:i:s', time() - 86400), ':end' => date('Y-m-d H:i:s', time() - 7200)]);
        $pastUploads += (int)$stmt->fetchColumn();
    }
    if ($uploadCount === 0 && $pastUploads >= 3) {
        $response['live']['spikes'][] = ['kind' => 'upload-stop', 'message' => 'No uploads in the last hour; ' . $pastUploads . ' were recorded in the preceding 22 hours.'];
    }

    $goalStmt = $pdo->prepare('SELECT `metric`, `target` FROM `admin_dashboard_goals` WHERE `goal_month` = :month');
    $goalStmt->execute([':month' => date('Y-m')]);
    foreach ($goalStmt->fetchAll(PDO::FETCH_ASSOC) as $goal) {
        if (isset($response['goals'][$goal['metric']])) {
            $response['goals'][$goal['metric']]['target'] = (int)$goal['target'];
        }
    }

    if (strlen($ipHashKey) >= 32) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `signup_ip_hashes` (
                `user_id` BIGINT UNSIGNED NOT NULL,
                `ip_hash` CHAR(64) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                KEY `idx_signup_ip_hashes_hash` (`ip_hash`),
                KEY `idx_signup_ip_hashes_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $response['spam']['ip_hashing_enabled'] = true;
        $matches = $pdo->query(
            "SELECT `ip_hash`, COUNT(*) AS `accounts` FROM `signup_ip_hashes`
             GROUP BY `ip_hash` HAVING COUNT(*) > 1 ORDER BY `accounts` DESC LIMIT 8"
        )->fetchAll(PDO::FETCH_ASSOC);
        $userColumns = $columns($pdo, 'users');
        $userIdColumn = $pick($userColumns, ['id', 'user_id']);
        $usernameColumn = $pick($userColumns, ['username', 'user_name', 'login', 'uname']);
        if ($matches && $userIdColumn && $usernameColumn) {
            $usersByHash = $pdo->prepare(
                'SELECT u.' . vs_q($usernameColumn) . '
                 FROM `signup_ip_hashes` s
                 INNER JOIN `users` u ON u.' . vs_q($userIdColumn) . ' = s.`user_id`
                 WHERE s.`ip_hash` = :ip_hash ORDER BY u.' . vs_q($usernameColumn) . ' LIMIT 20'
            );
            foreach ($matches as &$match) {
                $usersByHash->execute([':ip_hash' => (string)$match['ip_hash']]);
                $match['users'] = array_values(array_filter(array_map(
                    'strval',
                    $usersByHash->fetchAll(PDO::FETCH_COLUMN)
                ), static fn(string $name): bool => trim($name) !== ''));
                unset($match['ip_hash']);
                $match['accounts'] = (int)$match['accounts'];
            }
            unset($match);
        } else {
            foreach ($matches as &$match) {
                $match['users'] = [];
                unset($match['ip_hash']);
                $match['accounts'] = (int)$match['accounts'];
            }
            unset($match);
        }
        $response['spam']['same_ip_accounts'] = $matches;
    }

    $fileHealthCache = $_SESSION['vs_insights_file_health'] ?? null;
    if (is_array($fileHealthCache) && time() - (int)($fileHealthCache['time'] ?? 0) < 300) {
        $response['broken'] = $fileHealthCache['broken'];
        $response['storage'] = $fileHealthCache['storage'];
    } else {
        $sourceFields = ['video_path', 'video_file', 'file_path', 'filepath', 'filename', 'source', 'src', 'video_url', 'url', 'file'];
        $thumbFields = ['thumbnail', 'thumb', 'image', 'img', 'poster'];
        $projectRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $resolveLocal = static function (string $relative) use ($projectRoot): ?string {
            $relative = trim(str_replace('\\', '/', $relative));
            if ($relative === '' || preg_match('~^(?:https?:)?//|^data:~i', $relative)) {
                return null;
            }
            $relative = ltrim($relative, '/');
            $candidates = [$relative, 'uploads/' . $relative, 'videos/' . $relative, 'media/' . $relative];
            foreach ($candidates as $candidate) {
                $path = realpath($projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate));
                if ($path && is_file($path) && str_starts_with(strtolower($path), strtolower($projectRoot . DIRECTORY_SEPARATOR))) {
                    return $path;
                }
            }
            return null;
        };

        foreach (['videos', 'shorts'] as $contentTable) {
        $contentColumns = $columns($pdo, $contentTable);
        if (!$contentColumns) {
            continue;
        }
        $idColumn = $pick($contentColumns, ['id', 'video_id', 'short_id']);
        $titleColumn = $pick($contentColumns, ['title', 'name']);
        $sourceColumn = $pick($contentColumns, $sourceFields);
        $thumbColumn = $pick($contentColumns, $thumbFields);
        $viewsColumn = $pick($contentColumns, ['views', 'view_count']);
        $authorColumn = $pick($contentColumns, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
        $dateColumn = $pick($contentColumns, $dateColumnNames);
        if (!$idColumn || !$titleColumn) {
            continue;
        }
        $select = [vs_q($idColumn) . ' AS `id`', vs_q($titleColumn) . ' AS `title`'];
        foreach ([['source', $sourceColumn], ['thumb', $thumbColumn], ['views', $viewsColumn], ['creator', $authorColumn], ['created', $dateColumn]] as [$alias, $column]) {
            $select[] = $column ? vs_q($column) . ' AS ' . vs_q($alias) : "'' AS " . vs_q($alias);
        }
        $rows = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM ' . vs_q($contentTable) . ' ORDER BY ' . ($dateColumn ? vs_q($dateColumn) . ' DESC' : vs_q($idColumn) . ' DESC') . ' LIMIT 2500')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $mediaPath = $resolveLocal((string)$row['source']);
            $thumbPath = $resolveLocal((string)$row['thumb']);
            $age = strtotime((string)$row['created']);
            $reasons = [];
            $sourceValue = trim((string)$row['source']);
            $isYoutubeSource = preg_match('~(?:youtube\.com|youtu\.be)|^[A-Za-z0-9_-]{11}$~i', $sourceValue) === 1;
            if ($sourceValue === '' || (!preg_match('~^(?:https?:)?//~i', $sourceValue) && !$mediaPath && !$isYoutubeSource)) {
                $reasons[] = 'missing video file';
            }
            $thumbValue = trim((string)$row['thumb']);
            if ($thumbValue === '' && !$isYoutubeSource) {
                $reasons[] = 'missing thumbnail';
            } elseif ($thumbValue !== '' && !preg_match('~^(?:https?:)?//|^data:~i', $thumbValue) && !$thumbPath) {
                $reasons[] = 'missing thumbnail';
            }
            if ($age && $age <= time() - 7 * 86400 && (int)$row['views'] === 0) {
                $reasons[] = 'zero views after 7 days';
            }
            if ($reasons) {
                $response['broken']['count']++;
                if (count($response['broken']['items']) < 12) {
                    $response['broken']['items'][] = ['title' => (string)$row['title'], 'creator' => (string)$row['creator'], 'reasons' => $reasons, 'type' => $contentTable === 'shorts' ? 'Short' : 'Video'];
                }
            }
            if ($mediaPath && (string)$row['creator'] !== '') {
                $response['storage'][(string)$row['creator']] = ($response['storage'][(string)$row['creator']] ?? 0) + (filesize($mediaPath) ?: 0);
            }
        }
        }
        arsort($response['storage']);
        $response['storage'] = array_slice($response['storage'], 0, 8, true);
        $_SESSION['vs_insights_file_health'] = [
            'time' => time(),
            'broken' => $response['broken'],
            'storage' => $response['storage'],
        ];
    }

    if ($tableExists($pdo, 'user_activity_days') && $signupDate) {
        $userIdColumn = $pick($users, ['id', 'user_id']);
        if ($userIdColumn) {
            $retention = $pdo->query(
                'SELECT
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS `eligible1`,
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND d1.`user_id` IS NOT NULL THEN 1 ELSE 0 END) AS `returned1`,
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS `eligible7`,
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND d7.`user_id` IS NOT NULL THEN 1 ELSE 0 END) AS `returned7`,
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS `eligible30`,
                    SUM(CASE WHEN DATE(u.' . vs_q($signupDate) . ') <= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND d30.`user_id` IS NOT NULL THEN 1 ELSE 0 END) AS `returned30`
                 FROM `users` u
                 LEFT JOIN `user_activity_days` d1 ON d1.`user_id` = u.' . vs_q($userIdColumn) . ' AND d1.`active_date` = DATE_ADD(DATE(u.' . vs_q($signupDate) . '), INTERVAL 1 DAY)
                 LEFT JOIN `user_activity_days` d7 ON d7.`user_id` = u.' . vs_q($userIdColumn) . ' AND d7.`active_date` = DATE_ADD(DATE(u.' . vs_q($signupDate) . '), INTERVAL 7 DAY)
                 LEFT JOIN `user_activity_days` d30 ON d30.`user_id` = u.' . vs_q($userIdColumn) . ' AND d30.`active_date` = DATE_ADD(DATE(u.' . vs_q($signupDate) . '), INTERVAL 30 DAY)
                 WHERE u.' . vs_q($signupDate) . ' >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                   AND u.' . vs_q($signupDate) . ' < CURDATE()'
            )->fetch(PDO::FETCH_ASSOC);
            $response['retention']['available'] = true;
            foreach (['day1' => ['eligible1', 'returned1'], 'day7' => ['eligible7', 'returned7'], 'day30' => ['eligible30', 'returned30']] as $name => [$eligibleKey, $returnedKey]) {
                $eligible = (int)($retention[$eligibleKey] ?? 0);
                $returned = (int)($retention[$returnedKey] ?? 0);
                $response['retention']['cohorts'][$name] = $eligible ? round($returned / $eligible * 100, 1) : null;
            }
        }
    }

    $response['announcement'] = (string)$pdo->query('SELECT `message` FROM `site_announcements` WHERE `id` = 1 LIMIT 1')->fetchColumn();
    $notes = $pdo->prepare(
        'SELECT n.`id`, n.`user_id`, n.`admin_name`, n.`note`, n.`created_at`, u.'
        . vs_q($pick($users, ['username', 'user_name', 'name', 'full_name']) ?: 'id')
        . ' AS `username` FROM `admin_user_notes` n LEFT JOIN `users` u ON u.'
        . vs_q($pick($users, ['id', 'user_id']) ?: 'id') . ' = n.`user_id` ORDER BY n.`created_at` DESC LIMIT 20'
    );
    $notes->execute();
    $response['notes'] = $notes->fetchAll(PDO::FETCH_ASSOC);
    $response['current_admin'] = $adminName;

    $summaryKey = trim((string)($_SESSION['AdminID'] ?? $_SESSION['AdminSession'] ?? ''));
    $summaryStmt = $pdo->prepare('SELECT `email`, `frequency` FROM `admin_summary_settings` WHERE `admin_key` = :key LIMIT 1');
    $summaryStmt->execute([':key' => $summaryKey]);
    $summaryRow = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    if ($summaryRow) {
        $response['summary'] = ['email' => (string)$summaryRow['email'], 'frequency' => (string)$summaryRow['frequency']];
    }

    $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    if ($query !== '') {
        $like = '%' . $query . '%';
        foreach ([
            'users' => [['username', 'user_name', 'name', 'full_name', 'email'], 'User'],
            'videos' => [['title', 'name', 'username', 'channel_name'], 'Video'],
            'comments' => [['comment', 'content', 'body', 'text', 'comment_text', 'username'], 'Comment'],
            'video_comments' => [['comment', 'content', 'body', 'text', 'comment_text', 'username'], 'Comment'],
            'short_comments' => [['comment', 'content', 'body', 'text', 'comment_text', 'username'], 'Comment'],
        ] as $table => [$candidateNames, $kind]) {
            $foundColumns = $columns($pdo, $table);
            if (!$foundColumns) {
                continue;
            }
            $searchColumns = array_values(array_filter(array_map(static fn(string $name): ?string => in_array(strtolower($name), $foundColumns, true) ? $name : null, $candidateNames)));
            if (!$searchColumns) {
                continue;
            }
            $conditions = [];
            $params = [];
            foreach ($searchColumns as $index => $column) {
                $conditions[] = vs_q($column) . ' LIKE :q' . $index;
                $params[':q' . $index] = $like;
            }
            $idColumn = $pick($foundColumns, ['id', 'user_id', 'video_id', 'comment_id']);
            $labelColumn = $pick($foundColumns, ['username', 'user_name', 'title', 'name', 'comment', 'content', 'body', 'text', 'email']) ?: $searchColumns[0];
            $stmt = $pdo->prepare('SELECT ' . ($idColumn ? vs_q($idColumn) : "''") . ' AS `id`, ' . vs_q($labelColumn) . ' AS `label` FROM ' . vs_q($table) . ' WHERE ' . implode(' OR ', $conditions) . ' LIMIT 5');
            $stmt->execute($params);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $found) {
                $response['search'][] = ['type' => $kind, 'id' => (string)$found['id'], 'label' => mb_substr(trim((string)$found['label']), 0, 120), 'table' => $table];
            }
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Dashboard insights failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $error->getMessage()]);
}
