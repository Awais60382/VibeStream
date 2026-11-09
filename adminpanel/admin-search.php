<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') !== 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Administrator access is required.']);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/../notifications-store.php';

$query = trim((string)($_GET['q'] ?? ''));
$query = mb_substr($query, 0, 100);
$out = ['ok' => true, 'videos' => [], 'users' => [], 'messages' => []];
if (mb_strlen($query) < 2) {
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
$like = '%' . $escaped . '%';

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $columns = vs_cols($pdo, 'videos');
        $id = vs_pick($columns, ['id', 'video_id']);
        $title = vs_pick($columns, ['title', 'video_title', 'name']);
        $type = vs_pick($columns, ['type', 'video_type']);
        $username = vs_pick($columns, ['username', 'uploader', 'channel_name']);
        if ($id && $title) {
            $selectType = $type ? 'COALESCE(' . vs_q($type) . ", 'video')" : "'video'";
            $selectUser = $username ? 'COALESCE(' . vs_q($username) . ", '')" : "''";
            $where = vs_q($title) . " LIKE ? ESCAPE '!'";
            if ($username) { $where .= ' OR ' . vs_q($username) . " LIKE ? ESCAPE '!'"; }
            $sql = 'SELECT ' . vs_q($id) . ' AS result_id, ' . vs_q($title) . ' AS result_title, '
                . $selectType . ' AS result_type, ' . $selectUser . ' AS result_user FROM `videos` WHERE ' . $where
                . ' ORDER BY ' . vs_q($title) . ' LIMIT 5';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($username ? [$like, $like] : [$like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isShort = strtolower((string)$row['result_type']) === 'short';
                $out['videos'][] = [
                    'title' => (string)$row['result_title'],
                    'subtitle' => ($isShort ? 'Short' : 'Video') . ((string)$row['result_user'] !== '' ? ' · ' . $row['result_user'] : ''),
                    'href' => ($isShort ? 'shorts.php' : 'videos.php') . '?q=' . rawurlencode($query),
                    'icon' => $isShort ? 'bi-play-btn' : 'bi-camera-video',
                ];
            }
        }
    } catch (Throwable $error) {
        error_log('Admin global video search failed: ' . $error->getMessage());
    }

    try {
        $columns = vs_cols($pdo, 'users');
        $id = vs_pick($columns, ['id', 'user_id']);
        $username = vs_pick($columns, ['username', 'user_name', 'login', 'uname']);
        $name = vs_pick($columns, ['name', 'full_name', 'fullname', 'display_name']);
        $role = vs_pick($columns, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
        $email = vs_pick($columns, ['email', 'email_address', 'mail']);
        if ($id && $username) {
            $searchFields = array_values(array_filter([$username, $name, $email]));
            $where = implode(' OR ', array_map(static fn($field) => vs_q($field) . " LIKE ? ESCAPE '!'", $searchFields));
            $display = $name ? 'COALESCE(NULLIF(' . vs_q($name) . ", ''), " . vs_q($username) . ')' : vs_q($username);
            $selectRole = $role ? 'COALESCE(' . vs_q($role) . ", 'User')" : "'User'";
            $stmt = $pdo->prepare('SELECT ' . vs_q($id) . ' AS result_id, ' . vs_q($username) . ' AS result_username, '
                . $display . ' AS result_name, ' . $selectRole . ' AS result_role FROM `users` WHERE ' . $where
                . ' ORDER BY ' . vs_q($username) . ' LIMIT 5');
            $stmt->execute(array_fill(0, count($searchFields), $like));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $out['users'][] = [
                    'title' => (string)$row['result_name'],
                    'subtitle' => '@' . (string)$row['result_username'] . ' · ' . ucfirst((string)$row['result_role']),
                    'href' => 'user_profile.php?id=' . rawurlencode((string)$row['result_id']),
                    'icon' => 'bi-person',
                ];
            }
        }
    } catch (Throwable $error) {
        error_log('Admin global user search failed: ' . $error->getMessage());
    }
}

if (isset($conn) && $conn instanceof mysqli) {
    $userId = vs_current_user_id($conn);
    if ($userId > 0) {
        try {
            $table = $conn->query("SHOW TABLES LIKE 'admin_messages'");
            if ($table && $table->num_rows > 0) {
                $stmt = $conn->prepare(
                    'SELECT m.id, m.sender_id, m.recipient_id, m.body,
                            CASE WHEN m.sender_id = ? THEN m.recipient_id ELSE m.sender_id END AS other_id,
                            COALESCE(u.username, "Admin") AS other_name
                     FROM admin_messages m
                     LEFT JOIN users u ON u.id = CASE WHEN m.sender_id = ? THEN m.recipient_id ELSE m.sender_id END
                     WHERE m.body LIKE ? AND (m.sender_id = ? OR m.recipient_id = ?)
                     ORDER BY m.id DESC LIMIT 5'
                );
                $stmt->bind_param('iisii', $userId, $userId, $like, $userId, $userId);
                $stmt->execute();
                $rows = $stmt->get_result();
                while ($row = $rows->fetch_assoc()) {
                    $snippet = trim((string)preg_replace('/\s+/', ' ', (string)$row['body']));
                    $out['messages'][] = [
                        'title' => mb_substr($snippet, 0, 90),
                        'subtitle' => 'Conversation with ' . (string)$row['other_name'],
                        'href' => 'messages.php?with=' . (int)$row['other_id'],
                        'icon' => 'bi-chat-dots',
                    ];
                }
                $stmt->close();
            }
        } catch (Throwable $error) {
            error_log('Admin global direct-message search failed: ' . $error->getMessage());
        }

        try {
            $tables = $conn->query("SHOW TABLES LIKE 'admin_group_messages'");
            if ($tables && $tables->num_rows > 0) {
                $stmt = $conn->prepare(
                    'SELECT gm.body, gm.group_id, g.name
                     FROM admin_group_messages gm
                     INNER JOIN admin_group_members member ON member.group_id = gm.group_id AND member.user_id = ?
                     INNER JOIN admin_groups g ON g.id = gm.group_id
                     WHERE gm.body LIKE ? OR g.name LIKE ?
                     ORDER BY gm.id DESC LIMIT 5'
                );
                $stmt->bind_param('iss', $userId, $like, $like);
                $stmt->execute();
                $rows = $stmt->get_result();
                while ($row = $rows->fetch_assoc()) {
                    $snippet = trim((string)preg_replace('/\s+/', ' ', (string)$row['body']));
                    $out['messages'][] = [
                        'title' => mb_substr($snippet !== '' ? $snippet : (string)$row['name'], 0, 90),
                        'subtitle' => 'Group · ' . (string)$row['name'],
                        'href' => 'messages.php?group=' . (int)$row['group_id'],
                        'icon' => 'bi-people',
                    ];
                }
                $stmt->close();
            }
        } catch (Throwable $error) {
            error_log('Admin global group-message search failed: ' . $error->getMessage());
        }
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
