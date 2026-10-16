<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../notifications-store.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('The database is unavailable.');
}

if (empty($_SESSION['vs_messages_csrf'])) {
    $_SESSION['vs_messages_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['vs_messages_csrf'];

$errorMsg = '';
$meId = vs_current_user_id($conn);
$meName = (string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? 'Admin');
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

vs_admin_messages_ensure_table($conn);

const VS_MSG_MAX_UPLOAD = 10485760; /* 10 MB */

/* =====================================================================
   Small helpers
   ===================================================================== */

function vs_msg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Send a JSON reply and stop. */
function vs_msg_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Message text as safe HTML: escaped, links clickable, line breaks kept. */
function vs_msg_text_html(string $body): string
{
    $safe = vs_msg_h($body);
    $safe = preg_replace_callback('~https?://(?:(?!&quot;|&#039;|&lt;|&gt;)[^\s<])+~i', function ($m) {
        $url = $m[0];
        $tail = '';
        while ($url !== '' && preg_match('~[.,;:!?)\]]$~', $url)) {
            $tail = substr($url, -1) . $tail;
            $url = substr($url, 0, -1);
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $tail;
    }, $safe);
    return nl2br((string)$safe);
}

function vs_msg_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/** Short label for the chat list: 3:41 PM, Yesterday, Mon, Oct 3. */
function vs_msg_time(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) {
        return '';
    }
    $days = (int)round((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
    if ($days <= 0) {
        return date('g:i A', $ts);
    }
    if ($days === 1) {
        return 'Yesterday';
    }
    if ($days < 7) {
        return date('D', $ts);
    }
    return date(date('Y') === date('Y', $ts) ? 'M j' : 'M j, Y', $ts);
}

/** Clock time shown inside a message bubble. */
function vs_msg_clock(int $ts): string
{
    return $ts > 0 ? date('g:i A', $ts) : '';
}

/** Divider label between days inside a conversation. */
function vs_msg_day_label(int $ts): string
{
    $days = (int)round((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
    if ($days <= 0) {
        return 'Today';
    }
    if ($days === 1) {
        return 'Yesterday';
    }
    if ($days < 7) {
        return date('l', $ts);
    }
    return date(date('Y') === date('Y', $ts) ? 'F j' : 'F j, Y', $ts);
}

/* =====================================================================
   Database tables
   ===================================================================== */

function vs_groups_ensure_tables(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            created_by INT NOT NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_group_members (
            group_id INT NOT NULL,
            user_id INT NOT NULL,
            last_read_id INT NOT NULL DEFAULT 0,
            joined_at DATETIME NOT NULL,
            PRIMARY KEY (group_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS admin_group_messages (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            sender_id INT NOT NULL,
            sender_name VARCHAR(100) NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_group (group_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
vs_groups_ensure_tables($conn);

/**
 * Adds the newer columns (edited, reply, attachment) and tables (reactions,
 * presence, pinned/muted chats). Runs once per login session.
 * Returns an error text when something could not be created, otherwise null.
 */
function vs_msg_schema(mysqli $conn): ?string
{
    if (!empty($_SESSION['vs_msg_schema_v3'])) {
        return null;
    }
    try {
        $cols = [
            'edited_at' => 'DATETIME NULL DEFAULT NULL',
            'reply_to' => 'INT NULL DEFAULT NULL',
            'att_name' => 'VARCHAR(255) NULL DEFAULT NULL',
            'att_path' => 'VARCHAR(120) NULL DEFAULT NULL',
            'att_type' => 'VARCHAR(100) NULL DEFAULT NULL',
            'att_size' => 'INT NULL DEFAULT NULL',
        ];
        foreach (['admin_messages', 'admin_group_messages'] as $table) {
            $have = [];
            $res = $conn->query("SHOW COLUMNS FROM `$table`");
            while ($r = $res->fetch_assoc()) {
                $have[strtolower((string)$r['Field'])] = true;
            }
            $res->free();
            $add = [];
            foreach ($cols as $name => $def) {
                if (!isset($have[$name])) {
                    $add[] = "ADD COLUMN `$name` $def";
                }
            }
            if ($add) {
                $conn->query("ALTER TABLE `$table` " . implode(', ', $add));
            }
        }
        $conn->query(
            'CREATE TABLE IF NOT EXISTS admin_msg_reactions (
                kind CHAR(1) NOT NULL,
                msg_id INT NOT NULL,
                user_id INT NOT NULL,
                emoji VARCHAR(16) NOT NULL,
                PRIMARY KEY (kind, msg_id, user_id),
                KEY idx_msg (kind, msg_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $conn->query(
            'CREATE TABLE IF NOT EXISTS admin_presence (
                user_id INT NOT NULL PRIMARY KEY,
                last_seen DATETIME NOT NULL,
                typing_chat VARCHAR(20) NULL DEFAULT NULL,
                typing_at DATETIME NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $conn->query(
            'CREATE TABLE IF NOT EXISTS admin_chat_prefs (
                user_id INT NOT NULL,
                chat_key VARCHAR(20) NOT NULL,
                pinned TINYINT NOT NULL DEFAULT 0,
                muted TINYINT NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, chat_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $_SESSION['vs_msg_schema_v3'] = 1;
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
}

$schemaError = vs_msg_schema($conn);
if ($schemaError !== null) {
    http_response_code(500);
    exit('Messages could not update its database tables: ' . vs_msg_h($schemaError));
}

/* Polling and downloads should not hold the session lock. */
if ((isset($_GET['partial']) || isset($_GET['dl'])) && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

/* =====================================================================
   Groups
   ===================================================================== */

/** The group row if $uid is a member of it, otherwise null. */
function vs_group_member(mysqli $conn, int $groupId, int $uid): ?array
{
    if ($groupId <= 0 || $uid <= 0) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT g.id, g.name, g.created_by FROM admin_groups g
         JOIN admin_group_members m ON m.group_id = g.id
         WHERE g.id = ? AND m.user_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $groupId, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** [user_id => username] of everyone in the group. */
function vs_group_members(mysqli $conn, int $groupId): array
{
    $stmt = $conn->prepare(
        'SELECT m.user_id, COALESCE(u.username, \'(removed)\') AS username
         FROM admin_group_members m LEFT JOIN users u ON u.id = m.user_id
         WHERE m.group_id = ? ORDER BY username'
    );
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $out = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['user_id']] = (string)$row['username'];
    }
    $stmt->close();
    return $out;
}

/* =====================================================================
   Presence (online / typing) and chat preferences (pinned / muted)
   ===================================================================== */

function vs_msg_heartbeat(mysqli $conn, int $uid): void
{
    if ($uid <= 0) {
        return;
    }
    $s = $conn->prepare('INSERT INTO admin_presence (user_id, last_seen) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE last_seen = NOW()');
    $s->bind_param('i', $uid);
    $s->execute();
    $s->close();
}

/** $chat is "d:<other user id>" or "g:<group id>", or null to stop typing. */
function vs_msg_set_typing(mysqli $conn, int $uid, ?string $chat): void
{
    if ($uid <= 0) {
        return;
    }
    if ($chat === null) {
        $s = $conn->prepare('UPDATE admin_presence SET typing_chat = NULL, typing_at = NULL WHERE user_id = ?');
        $s->bind_param('i', $uid);
    } else {
        $s = $conn->prepare(
            'INSERT INTO admin_presence (user_id, last_seen, typing_chat, typing_at) VALUES (?, NOW(), ?, NOW())
             ON DUPLICATE KEY UPDATE last_seen = NOW(), typing_chat = VALUES(typing_chat), typing_at = NOW()'
        );
        $s->bind_param('is', $uid, $chat);
    }
    $s->execute();
    $s->close();
}

function vs_msg_presence_map(mysqli $conn): array
{
    $out = [];
    $res = $conn->query(
        'SELECT user_id, last_seen, typing_chat,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seen_ago,
                IF(typing_at IS NULL, 9999, TIMESTAMPDIFF(SECOND, typing_at, NOW())) AS typing_ago
         FROM admin_presence'
    );
    while ($r = $res->fetch_assoc()) {
        $out[(int)$r['user_id']] = $r;
    }
    $res->free();
    return $out;
}

function vs_msg_status_text(?array $p): string
{
    if (!$p || empty($p['last_seen'])) {
        return 'Offline';
    }
    if ((int)$p['seen_ago'] <= 45) {
        return 'Online';
    }
    $ts = strtotime((string)$p['last_seen']);
    if (!$ts) {
        return 'Offline';
    }
    $days = (int)round((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
    if ($days <= 0) {
        return 'Last seen today at ' . date('g:i A', $ts);
    }
    if ($days === 1) {
        return 'Last seen yesterday at ' . date('g:i A', $ts);
    }
    return 'Last seen ' . date('M j', $ts);
}

/** Names of admins (not me) who are typing in the chat identified by $chatKey. */
function vs_msg_typing_names(array $presence, array $admins, int $meId, string $chatKey): array
{
    $names = [];
    foreach ($presence as $uid => $p) {
        if ($uid === $meId || !isset($admins[$uid])) {
            continue;
        }
        if ((string)($p['typing_chat'] ?? '') === $chatKey && (int)$p['typing_ago'] <= 5) {
            $names[] = $admins[$uid];
        }
    }
    return $names;
}

function vs_msg_typing_text(array $names): string
{
    if (!$names) {
        return '';
    }
    return implode(', ', $names) . (count($names) > 1 ? ' are typing…' : ' is typing…');
}

/** [chat key => ['pinned' => 0|1, 'muted' => 0|1]] for the current admin. Keys look like d5 or g12. */
function vs_msg_prefs(mysqli $conn, int $uid): array
{
    $out = [];
    $s = $conn->prepare('SELECT chat_key, pinned, muted FROM admin_chat_prefs WHERE user_id = ?');
    $s->bind_param('i', $uid);
    $s->execute();
    $res = $s->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(string)$row['chat_key']] = ['pinned' => (int)$row['pinned'], 'muted' => (int)$row['muted']];
    }
    $s->close();
    return $out;
}

/* =====================================================================
   Attachments
   ===================================================================== */

function vs_msg_upload_dir(): string
{
    $dir = __DIR__ . '/uploads/messages';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents(
            $ht,
            "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
        );
    }
    return $dir;
}

/** Validates and stores an uploaded file. Returns its details, or null when no file was sent. */
function vs_msg_store_upload($file): ?array
{
    if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $err = (int)$file['error'];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That file is larger than the server allows. Raise upload_max_filesize and post_max_size in php.ini, or send a smaller file.');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The file could not be uploaded. Please try again.');
    }
    if ((int)$file['size'] > VS_MSG_MAX_UPLOAD) {
        throw new RuntimeException('Files can be at most 10 MB.');
    }

    $name = trim((string)preg_replace('/[^\w.\- ()\[\]]+/u', '_', basename((string)$file['name'])));
    $name = mb_substr($name, 0, 120);
    if ($name === '') {
        $name = 'file';
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $allowed = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'zip' => 'application/zip',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
    ];
    if (!isset($allowed[$ext])) {
        throw new RuntimeException('That file type is not allowed. You can send images, PDFs, Office files, text, zip, mp3 and mp4.');
    }
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && @getimagesize((string)$file['tmp_name']) === false) {
        throw new RuntimeException('That image file looks damaged.');
    }

    $dir = vs_msg_upload_dir();
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('The uploads folder is not writable.');
    }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $stored)) {
        throw new RuntimeException('The file could not be saved.');
    }
    return ['name' => $name, 'path' => $stored, 'type' => $allowed[$ext], 'size' => (int)$file['size']];
}

function vs_msg_remove_file(?string $path): void
{
    if ($path !== null && $path !== '') {
        $full = vs_msg_upload_dir() . '/' . basename($path);
        if (is_file($full)) {
            @unlink($full);
        }
    }
}

/** A message the current admin is allowed to see, or null. $kind is "dm" or "group". */
function vs_msg_visible(mysqli $conn, string $kind, int $mid, int $meId): ?array
{
    if ($mid <= 0) {
        return null;
    }
    if ($kind === 'group') {
        $s = $conn->prepare(
            'SELECT m.id, m.sender_id, m.group_id, m.body, m.att_name, m.att_path, m.att_type, m.att_size
             FROM admin_group_messages m
             JOIN admin_group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
             WHERE m.id = ? LIMIT 1'
        );
        $s->bind_param('ii', $meId, $mid);
    } else {
        $s = $conn->prepare(
            'SELECT id, sender_id, recipient_id, body, att_name, att_path, att_type, att_size
             FROM admin_messages WHERE id = ? AND (sender_id = ? OR recipient_id = ?) LIMIT 1'
        );
        $s->bind_param('iii', $mid, $meId, $meId);
    }
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return $row ?: null;
}

/* =====================================================================
   Sending
   ===================================================================== */

/** Sends a direct message (this also raises the bell notification) and returns its id. */
function vs_msg_post_dm(mysqli $conn, int $meId, string $meName, int $toId, string $body, int $replyTo, ?array $att): int
{
    if (!vs_admin_send_message($conn, $meId, $meName, $toId, $body)) {
        throw new RuntimeException('The message could not be sent. Please try again.');
    }
    $q = $conn->prepare('SELECT COALESCE(MAX(id), 0) FROM admin_messages WHERE sender_id = ? AND recipient_id = ?');
    $q->bind_param('ii', $meId, $toId);
    $q->execute();
    $id = 0;
    $q->bind_result($id);
    $q->fetch();
    $q->close();

    if ($id > 0 && ($replyTo > 0 || $att)) {
        $rt = null;
        if ($replyTo > 0) {
            $c = $conn->prepare(
                'SELECT 1 FROM admin_messages WHERE id = ?
                 AND ((sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)) LIMIT 1'
            );
            $c->bind_param('iiiii', $replyTo, $meId, $toId, $toId, $meId);
            $c->execute();
            $c->store_result();
            if ($c->num_rows > 0) {
                $rt = $replyTo;
            }
            $c->close();
        }
        $an = $att['name'] ?? null;
        $ap = $att['path'] ?? null;
        $at = $att['type'] ?? null;
        $as = $att['size'] ?? null;
        $u = $conn->prepare('UPDATE admin_messages SET reply_to = ?, att_name = ?, att_path = ?, att_type = ?, att_size = ? WHERE id = ?');
        $u->bind_param('isssii', $rt, $an, $ap, $at, $as, $id);
        $u->execute();
        $u->close();
    }
    return (int)$id;
}

/** Posts a message into a group the sender already belongs to and returns its id. */
function vs_msg_post_group(mysqli $conn, int $gid, int $meId, string $meName, string $body, int $replyTo, ?array $att): int
{
    $rt = null;
    if ($replyTo > 0) {
        $c = $conn->prepare('SELECT 1 FROM admin_group_messages WHERE id = ? AND group_id = ? LIMIT 1');
        $c->bind_param('ii', $replyTo, $gid);
        $c->execute();
        $c->store_result();
        if ($c->num_rows > 0) {
            $rt = $replyTo;
        }
        $c->close();
    }
    $an = $att['name'] ?? null;
    $ap = $att['path'] ?? null;
    $at = $att['type'] ?? null;
    $as = $att['size'] ?? null;
    $ins = $conn->prepare(
        'INSERT INTO admin_group_messages (group_id, sender_id, sender_name, body, created_at, reply_to, att_name, att_path, att_type, att_size)
         VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?)'
    );
    $ins->bind_param('iississsi', $gid, $meId, $meName, $body, $rt, $an, $ap, $at, $as);
    $ins->execute();
    $msgId = (int)$conn->insert_id;
    $ins->close();

    $read = $conn->prepare('UPDATE admin_group_members SET last_read_id = GREATEST(last_read_id, ?) WHERE group_id = ? AND user_id = ?');
    $read->bind_param('iii', $msgId, $gid, $meId);
    $read->execute();
    $read->close();
    return $msgId;
}

/** Reads and checks the text of an outgoing message. Returns the body (may be empty when a file is attached). */
function vs_msg_clean_body(bool $hasFile): string
{
    $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
    if ($body === '' && !$hasFile) {
        throw new RuntimeException('Type a message first.');
    }
    if (mb_strlen($body) > 2000) {
        throw new RuntimeException('Messages can be at most 2000 characters.');
    }
    return $body;
}

/* =====================================================================
   Rendering
   ===================================================================== */

/** Reactions for a set of messages: [msg id => [emoji => ['n' => count, 'mine' => bool]]]. $kind is "d" or "g". */
function vs_msg_reactions(mysqli $conn, string $kind, array $ids, int $meId): array
{
    if (!$ids) {
        return [];
    }
    $list = implode(',', array_map('intval', $ids));
    $stmt = $conn->prepare("SELECT msg_id, emoji, user_id FROM admin_msg_reactions WHERE kind = ? AND msg_id IN ($list)");
    $stmt->bind_param('s', $kind);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $mid = (int)$r['msg_id'];
        $e = (string)$r['emoji'];
        if (!isset($out[$mid][$e])) {
            $out[$mid][$e] = ['n' => 0, 'mine' => false];
        }
        $out[$mid][$e]['n']++;
        if ((int)$r['user_id'] === $meId) {
            $out[$mid][$e]['mine'] = true;
        }
    }
    $stmt->close();
    return $out;
}

/**
 * Turns message rows into chat bubbles: day dividers, a "New messages" line,
 * runs of messages from the same person grouped together, quoted replies,
 * attachments, reactions, and seen ticks in 1:1 chats.
 */
function vs_msg_render_rows(array $rows, int $meId, callable $nameFor, bool $isGroup, string $kind, array $reactions, int $unreadFrom): string
{
    $stamp = function (array $r): int {
        return (int)strtotime((string)$r['created_at']);
    };
    $joins = function (?array $a, ?array $b) use ($stamp): bool {
        if (!$a || !$b || (int)$a['sender_id'] !== (int)$b['sender_id']) {
            return false;
        }
        $ta = $stamp($a);
        $tb = $stamp($b);
        return date('Y-m-d', $ta) === date('Y-m-d', $tb) && abs($tb - $ta) <= 300;
    };

    $html = '';
    $lastDay = '';
    $count = count($rows);
    for ($i = 0; $i < $count; $i++) {
        $row = $rows[$i];
        $ts = $stamp($row);
        $day = date('Y-m-d', $ts);
        if ($day !== $lastDay) {
            $html .= '<div class="msg-day"><span>' . vs_msg_h(vs_msg_day_label($ts)) . '</span></div>';
            $lastDay = $day;
        }
        $divider = $unreadFrom > 0 && (int)$row['id'] === $unreadFrom;
        if ($divider) {
            $html .= '<div class="msg-newdiv"><span>New messages</span></div>';
        }

        $first = $divider || !$joins($rows[$i - 1] ?? null, $row);
        $last = !$joins($row, $rows[$i + 1] ?? null);
        $mine = (int)$row['sender_id'] === $meId;
        $who = $nameFor($row, $mine);
        $side = $mine ? 'me' : 'them';
        $id = (int)$row['id'];

        $hasAtt = !empty($row['att_path']);
        $marker = $hasAtt && (string)$row['body'] === '📎 ' . (string)$row['att_name'];
        $text = $marker ? '' : (string)$row['body'];

        $ticks = '';
        if (!$isGroup && $mine) {
            $seen = !empty($row['is_read']);
            $ticks = '<span class="msg-tick' . ($seen ? ' read' : '') . '" title="' . ($seen ? 'Seen' : 'Sent') . '">'
                . '<i class="bi ' . ($seen ? 'bi-check2-all' : 'bi-check2') . '" aria-hidden="true"></i></span>';
        }

        /* quoted reply */
        $quote = '';
        if (!empty($row['reply_to'])) {
            if ($row['r_sender'] === null) {
                $quote = '<div class="msg-quote gone"><span class="msg-qtext">The original message was deleted</span></div>';
            } else {
                $rMine = (int)$row['r_sender'] === $meId;
                $rWho = $rMine ? 'You' : $nameFor(['sender_id' => $row['r_sender'], 'sender_name' => (string)($row['r_name'] ?? '')], false);
                $rBody = (string)$row['r_body'];
                if (!empty($row['r_att']) && $rBody === '📎 ' . (string)$row['r_att']) {
                    $rBody = '📎 ' . (string)$row['r_att'];
                }
                $rText = mb_substr(trim((string)preg_replace('/\s+/', ' ', $rBody)), 0, 90);
                $quote = '<div class="msg-quote" data-ref="' . (int)$row['reply_to'] . '">'
                    . '<span class="msg-qname">' . vs_msg_h($rWho) . '</span>'
                    . '<span class="msg-qtext">' . vs_msg_h($rText) . '</span></div>';
            }
        }

        /* attachment */
        $att = '';
        if ($hasAtt) {
            $url = 'messages.php?dl=1&kind=' . ($isGroup ? 'g' : 'd') . '&id=' . $id;
            $isImg = strpos((string)$row['att_type'], 'image/') === 0;
            if ($isImg) {
                $att = '<a class="msg-img" href="' . vs_msg_h($url) . '" target="_blank" rel="noopener"><img src="' . vs_msg_h($url) . '" alt="' . vs_msg_h($row['att_name']) . '" loading="lazy"></a>';
            } else {
                $att = '<a class="msg-file" href="' . vs_msg_h($url) . '" download>'
                    . '<span class="msg-file-ico" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>'
                    . '<span class="msg-file-copy"><b>' . vs_msg_h($row['att_name']) . '</b><small>' . vs_msg_h(vs_msg_size((int)$row['att_size'])) . '</small></span></a>';
            }
        }

        /* reactions */
        $chips = '';
        if (!empty($reactions[$id])) {
            foreach ($reactions[$id] as $emoji => $info) {
                $chips .= '<button type="button" class="msg-chip' . ($info['mine'] ? ' mine' : '') . '" data-emoji="' . vs_msg_h($emoji) . '">'
                    . vs_msg_h($emoji) . ' ' . (int)$info['n'] . '</button>';
            }
            $chips = '<div class="msg-reacts">' . $chips . '</div>';
        }

        $html .= '<div class="msg-row ' . $side . ($first ? ' first' : '') . ($last ? ' last' : '') . '"'
            . ' data-id="' . $id . '" data-who="' . vs_msg_h($who) . '" data-body="' . vs_msg_h($text) . '"'
            . ($marker ? ' data-marker="1"' : '') . ($hasAtt ? ' data-att="1"' : '') . '>';
        if (!$mine) {
            $html .= '<span class="msg-av">' . ($last ? avatar_html($who, 32) : '') . '</span>';
        }
        $html .= '<div class="msg-bubble ' . $side . ($hasAtt ? ' has-att' : '') . '">'
            . ($isGroup && !$mine && $first ? '<div class="msg-sender">' . vs_msg_h($who) . '</div>' : '')
            . $quote . $att
            . ($text !== '' ? '<div class="msg-text">' . vs_msg_text_html($text) . '</div>' : '')
            . '<div class="msg-meta">' . (!empty($row['edited_at']) ? '<span class="msg-edited">edited</span>' : '')
            . '<span class="msg-time">' . vs_msg_h(vs_msg_clock($ts)) . '</span>' . $ticks . '</div>'
            . $chips
            . '</div>'
            . '<span class="msg-acts"><button type="button" class="msg-more" title="Message options" aria-label="Message options" aria-haspopup="menu"><i class="bi bi-three-dots" aria-hidden="true"></i></button></span>'
            . '</div>';
    }
    return $html;
}

function vs_msg_thread_html(mysqli $conn, int $meId, int $withId, string $meName, string $withName, int $unreadFrom = 0): string
{
    $stmt = $conn->prepare(
        'SELECT id, sender_id, body, created_at, is_read, edited_at, reply_to, att_name, att_path, att_type, att_size, r_sender, r_body, r_att FROM (
            SELECT m.id, m.sender_id, m.body, m.created_at, m.is_read, m.edited_at, m.reply_to, m.att_name, m.att_path, m.att_type, m.att_size,
                   r.sender_id AS r_sender, r.body AS r_body, r.att_name AS r_att
            FROM admin_messages m LEFT JOIN admin_messages r ON r.id = m.reply_to
            WHERE (m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)
            ORDER BY m.id DESC LIMIT 200
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('iiii', $meId, $withId, $withId, $meId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return '<div class="msg-empty">No messages yet. Say hello below.</div>';
    }
    $reactions = vs_msg_reactions($conn, 'd', array_column($rows, 'id'), $meId);
    return vs_msg_render_rows($rows, $meId, function (array $r, bool $mine) use ($meName, $withName) {
        return $mine ? $meName : $withName;
    }, false, 'd', $reactions, $unreadFrom);
}

function vs_msg_group_thread_html(mysqli $conn, int $meId, int $groupId, string $meName, int $unreadFrom = 0): string
{
    $stmt = $conn->prepare(
        'SELECT id, sender_id, sender_name, body, created_at, edited_at, reply_to, att_name, att_path, att_type, att_size, r_sender, r_name, r_body, r_att FROM (
            SELECT m.id, m.sender_id, m.sender_name, m.body, m.created_at, m.edited_at, m.reply_to, m.att_name, m.att_path, m.att_type, m.att_size,
                   r.sender_id AS r_sender, r.sender_name AS r_name, r.body AS r_body, r.att_name AS r_att
            FROM admin_group_messages m LEFT JOIN admin_group_messages r ON r.id = m.reply_to AND r.group_id = m.group_id
            WHERE m.group_id = ? ORDER BY m.id DESC LIMIT 200
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return '<div class="msg-empty">No messages yet. Start the conversation below.</div>';
    }
    $reactions = vs_msg_reactions($conn, 'g', array_column($rows, 'id'), $meId);
    return vs_msg_render_rows($rows, $meId, function (array $r, bool $mine) use ($meName) {
        return $mine ? $meName : (string)$r['sender_name'];
    }, true, 'g', $reactions, $unreadFrom);
}

/** Preview text for a message row in the chat list (attachments show their file name). */
function vs_msg_preview(array $row): string
{
    $body = (string)$row['body'];
    if (!empty($row['att_path']) && $body === '📎 ' . (string)$row['att_name']) {
        return $body;
    }
    return trim((string)preg_replace('/\s+/', ' ', $body));
}

/** Chat list data: groups and admins with last message, unread count, pinned/muted and typing/online state. */
function vs_msg_sidebar_data(mysqli $conn, int $meId, array $admins, array $prefs, array $presence): array
{
    /* ---- admins ---- */
    $unreadBy = [];
    $unreadStmt = $conn->prepare('SELECT sender_id, COUNT(*) AS n FROM admin_messages WHERE recipient_id = ? AND is_read = 0 GROUP BY sender_id');
    $unreadStmt->bind_param('i', $meId);
    $unreadStmt->execute();
    $unreadRes = $unreadStmt->get_result();
    while ($row = $unreadRes->fetch_assoc()) {
        $unreadBy[(int)$row['sender_id']] = (int)$row['n'];
    }
    $unreadStmt->close();

    $lastStmt = $conn->prepare(
        'SELECT id, sender_id, body, created_at, att_name, att_path FROM admin_messages
         WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
         ORDER BY id DESC LIMIT 1'
    );
    $contacts = [];
    foreach ($admins as $id => $name) {
        $lastStmt->bind_param('iiii', $meId, $id, $id, $meId);
        $lastStmt->execute();
        $last = $lastStmt->get_result()->fetch_assoc();
        $p = $presence[$id] ?? null;
        $key = 'd' . $id;
        $contacts[] = [
            'id' => $id,
            'key' => $key,
            'name' => $name,
            'unread' => $unreadBy[$id] ?? 0,
            'last_id' => (int)($last['id'] ?? 0),
            'plain' => $last ? mb_substr(vs_msg_preview($last), 0, 60) : '',
            'preview' => $last
                ? (((int)$last['sender_id'] === $meId ? 'You: ' : '') . mb_substr(vs_msg_preview($last), 0, 60))
                : 'No messages yet',
            'time' => $last ? vs_msg_time((string)$last['created_at']) : '',
            'pinned' => (int)($prefs[$key]['pinned'] ?? 0),
            'muted' => (int)($prefs[$key]['muted'] ?? 0),
            'online' => $p && (int)$p['seen_ago'] <= 45,
            'typing' => $p && (string)($p['typing_chat'] ?? '') === 'd:' . $meId && (int)$p['typing_ago'] <= 5,
        ];
    }
    $lastStmt->close();
    usort($contacts, function ($a, $b) {
        return $b['pinned'] <=> $a['pinned'] ?: $b['last_id'] <=> $a['last_id'] ?: strcasecmp($a['name'], $b['name']);
    });

    /* ---- groups ---- */
    $groupList = [];
    $gStmt = $conn->prepare(
        'SELECT g.id, g.name, m.last_read_id FROM admin_groups g
         JOIN admin_group_members m ON m.group_id = g.id WHERE m.user_id = ?'
    );
    $gStmt->bind_param('i', $meId);
    $gStmt->execute();
    $gRows = $gStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $gStmt->close();

    $gUnread = $conn->prepare('SELECT COUNT(*) FROM admin_group_messages WHERE group_id = ? AND id > ? AND sender_id <> ?');
    $gLast = $conn->prepare('SELECT id, sender_id, sender_name, body, created_at, att_name, att_path FROM admin_group_messages WHERE group_id = ? ORDER BY id DESC LIMIT 1');
    foreach ($gRows as $g) {
        $gid2 = (int)$g['id'];
        $readId = (int)$g['last_read_id'];
        $gUnread->bind_param('iii', $gid2, $readId, $meId);
        $gUnread->execute();
        $unreadN = 0;
        $gUnread->bind_result($unreadN);
        $gUnread->fetch();
        $gUnread->free_result();

        $gLast->bind_param('i', $gid2);
        $gLast->execute();
        $lastG = $gLast->get_result()->fetch_assoc();

        $snippet = $lastG ? mb_substr(vs_msg_preview($lastG), 0, 50) : '';
        $key = 'g' . $gid2;
        $groupList[] = [
            'id' => $gid2,
            'key' => $key,
            'name' => (string)$g['name'],
            'unread' => (int)$unreadN,
            'last_id' => (int)($lastG['id'] ?? 0),
            'plain' => $lastG ? (string)$lastG['sender_name'] . ': ' . $snippet : '',
            'preview' => $lastG
                ? (((int)$lastG['sender_id'] === $meId ? 'You' : (string)$lastG['sender_name']) . ': ' . $snippet)
                : 'No messages yet',
            'time' => $lastG ? vs_msg_time((string)$lastG['created_at']) : '',
            'pinned' => (int)($prefs[$key]['pinned'] ?? 0),
            'muted' => (int)($prefs[$key]['muted'] ?? 0),
            'typing' => vs_msg_typing_text(vs_msg_typing_names($presence, $admins, $meId, 'g:' . $gid2)),
        ];
    }
    $gUnread->close();
    $gLast->close();
    usort($groupList, function ($a, $b) {
        return $b['pinned'] <=> $a['pinned'] ?: $b['last_id'] <=> $a['last_id'] ?: strcasecmp($a['name'], $b['name']);
    });

    $total = 0;
    $chats = [];
    foreach ($contacts as $c) {
        $chats[$c['key']] = ['u' => $c['unread'], 'm' => $c['muted'], 'n' => $c['name'], 'p' => $c['plain']];
        if (!$c['muted']) {
            $total += $c['unread'];
        }
    }
    foreach ($groupList as $g) {
        $chats[$g['key']] = ['u' => $g['unread'], 'm' => $g['muted'], 'n' => $g['name'], 'p' => $g['plain']];
        if (!$g['muted']) {
            $total += $g['unread'];
        }
    }
    return ['groups' => $groupList, 'contacts' => $contacts, 'unread' => $total, 'chats' => $chats];
}

function vs_msg_flags_html(array $c): string
{
    $f = ($c['pinned'] ? '<span title="Pinned"><i class="bi bi-pin-angle-fill" aria-hidden="true"></i></span>' : '')
        . ($c['muted'] ? '<span title="Muted"><i class="bi bi-bell-slash-fill" aria-hidden="true"></i></span>' : '');
    return $f !== '' ? '<span class="msg-flags">' . $f . '</span>' : '';
}

function vs_msg_group_items(array $groupList, int $groupId): string
{
    if (!$groupList) {
        return '<div class="msg-none">No groups yet. Create one to chat with several admins at once.</div>';
    }
    $html = '';
    foreach ($groupList as $g) {
        $preview = $g['typing'] !== ''
            ? '<span class="msg-typing">' . vs_msg_h($g['typing']) . '</span>'
            : vs_msg_h($g['preview']);
        $html .= '<a class="msg-contact' . ($g['id'] === $groupId ? ' active' : '') . ($g['unread'] > 0 ? ' has-unread' : '') . ($g['muted'] ? ' is-muted' : '') . '"'
            . ' href="messages.php?group=' . (int)$g['id'] . '" data-key="' . vs_msg_h($g['key']) . '" data-name="' . vs_msg_h(mb_strtolower($g['name'])) . '">'
            . '<span class="msg-gavatar" aria-hidden="true">' . vs_msg_h(mb_strtoupper(mb_substr($g['name'], 0, 1))) . '</span>'
            . '<span class="msg-contact-copy">'
            . '<span class="msg-contact-name"><span class="msg-cname">' . vs_msg_h($g['name']) . '</span>' . vs_msg_flags_html($g) . '<span class="msg-contact-time">' . vs_msg_h($g['time']) . '</span></span>'
            . '<span class="msg-contact-line"><span class="msg-contact-preview">' . $preview . '</span>'
            . ($g['unread'] > 0 ? '<span class="msg-unread">' . (int)$g['unread'] . '</span>' : '')
            . '</span></span></a>';
    }
    return $html;
}

function vs_msg_admin_items(array $contacts, int $withId): string
{
    $html = '';
    foreach ($contacts as $c) {
        $preview = $c['typing']
            ? '<span class="msg-typing">typing…</span>'
            : vs_msg_h($c['preview']);
        $html .= '<a class="msg-contact' . ($c['id'] === $withId ? ' active' : '') . ($c['unread'] > 0 ? ' has-unread' : '') . ($c['muted'] ? ' is-muted' : '') . '"'
            . ' href="messages.php?with=' . (int)$c['id'] . '" data-key="' . vs_msg_h($c['key']) . '" data-name="' . vs_msg_h(mb_strtolower($c['name'])) . '">'
            . '<span class="msg-avwrap">' . avatar_html($c['name'], 40) . ($c['online'] ? '<span class="msg-dot" title="Online"></span>' : '') . '</span>'
            . '<span class="msg-contact-copy">'
            . '<span class="msg-contact-name"><span class="msg-cname">' . vs_msg_h($c['name']) . '</span>' . vs_msg_flags_html($c) . '<span class="msg-contact-time">' . vs_msg_h($c['time']) . '</span></span>'
            . '<span class="msg-contact-line"><span class="msg-contact-preview">' . $preview . '</span>'
            . ($c['unread'] > 0 ? '<span class="msg-unread">' . (int)$c['unread'] . '</span>' : '')
            . '</span></span></a>';
    }
    return $html;
}

/* =====================================================================
   Other admins, attachment downloads
   ===================================================================== */

$admins = [];
$res = $conn->query("SELECT id, username FROM users WHERE LOWER(role) = 'admin' ORDER BY username");
while ($row = $res->fetch_assoc()) {
    if ((int)$row['id'] !== $meId) {
        $admins[(int)$row['id']] = (string)$row['username'];
    }
}
$res->free();

/* ---- download / show an attachment (only people in that chat can open it) ---- */
if (isset($_GET['dl'])) {
    $dlKind = (string)($_GET['kind'] ?? '') === 'g' ? 'group' : 'dm';
    $dlRow = $meId > 0 ? vs_msg_visible($conn, $dlKind, (int)($_GET['id'] ?? 0), $meId) : null;
    $dlFile = $dlRow && !empty($dlRow['att_path']) ? vs_msg_upload_dir() . '/' . basename((string)$dlRow['att_path']) : '';
    if ($dlFile === '' || !is_file($dlFile)) {
        http_response_code(404);
        exit('File not found.');
    }
    $dlType = (string)($dlRow['att_type'] ?: 'application/octet-stream');
    $dlName = (string)($dlRow['att_name'] ?: 'file');
    header('Content-Type: ' . $dlType);
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . filesize($dlFile));
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: ' . (strpos($dlType, 'image/') === 0 ? 'inline' : 'attachment')
        . '; filename="' . addcslashes($dlName, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($dlName));
    readfile($dlFile);
    exit;
}

/* =====================================================================
   Actions (POST). Fetch requests get JSON back, plain forms get a redirect.
   ===================================================================== */
$actions = [
    'send', 'group_send', 'create_group', 'group_add', 'group_leave', 'group_rename', 'group_remove', 'group_owner',
    'delete_msg', 'edit_msg', 'react_msg', 'forward_msg', 'chat_pref', 'typing',
];
$act = (string)($_POST['action'] ?? '');
$gid = (int)($_POST['group'] ?? 0);
$toId = (int)($_POST['to'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($act === '') {
        $act = 'send';
    }
    $upload = null;
    try {
        if (!in_array($act, $actions, true)) {
            throw new RuntimeException('That action is not available.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        if ($meId <= 0) {
            throw new RuntimeException('Your admin account could not be identified. Sign in again.');
        }
        $kindIn = (string)($_POST['kind'] ?? '') === 'group' ? 'group' : 'dm';

        /* ---- typing indicator ---- */
        if ($act === 'typing') {
            if ($kindIn === 'group' && vs_group_member($conn, $gid, $meId)) {
                vs_msg_set_typing($conn, $meId, 'g:' . $gid);
            } elseif ($kindIn === 'dm' && isset($admins[$toId])) {
                vs_msg_set_typing($conn, $meId, 'd:' . $toId);
            }
            vs_msg_json(['ok' => true]);
        }

        /* ---- send a direct message ---- */
        if ($act === 'send') {
            if (!isset($admins[$toId])) {
                throw new RuntimeException('Choose another admin to message.');
            }
            $upload = vs_msg_store_upload($_FILES['file'] ?? null);
            $body = vs_msg_clean_body($upload !== null);
            if ($body === '' && $upload) {
                $body = '📎 ' . $upload['name'];
            }
            vs_msg_post_dm($conn, $meId, $meName, $toId, $body, (int)($_POST['reply_to'] ?? 0), $upload);
            vs_msg_set_typing($conn, $meId, null);
            if ($isAjax) {
                vs_msg_json(['ok' => true]);
            }
            header('Location: messages.php?with=' . $toId);
            exit;
        }

        /* ---- chat preferences: pin / mute ---- */
        if ($act === 'chat_pref') {
            $key = (string)($_POST['key'] ?? '');
            $field = (string)($_POST['field'] ?? '');
            $val = (int)($_POST['value'] ?? 0) ? 1 : 0;
            if (!preg_match('/^[dg][0-9]{1,10}$/', $key) || !in_array($field, ['pinned', 'muted'], true)) {
                throw new RuntimeException('That setting could not be saved.');
            }
            if ($field === 'pinned') {
                $s = $conn->prepare('INSERT INTO admin_chat_prefs (user_id, chat_key, pinned) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE pinned = VALUES(pinned)');
            } else {
                $s = $conn->prepare('INSERT INTO admin_chat_prefs (user_id, chat_key, muted) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE muted = VALUES(muted)');
            }
            $s->bind_param('isi', $meId, $key, $val);
            $s->execute();
            $s->close();
            vs_msg_json(['ok' => true]);
        }

        /* ---- create a group ---- */
        if ($act === 'create_group') {
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) {
                throw new RuntimeException('Give the group a name (up to 60 characters).');
            }
            $ids = [];
            foreach ((array)($_POST['members'] ?? []) as $m) {
                $m = (int)$m;
                if (isset($admins[$m])) {
                    $ids[$m] = $m;
                }
            }
            if (!$ids) {
                throw new RuntimeException('Pick at least one other admin for the group.');
            }
            $conn->begin_transaction();
            try {
                $ins = $conn->prepare('INSERT INTO admin_groups (name, created_by, created_at) VALUES (?, ?, NOW())');
                $ins->bind_param('si', $name, $meId);
                $ins->execute();
                $newId = (int)$conn->insert_id;
                $ins->close();

                $mem = $conn->prepare('INSERT INTO admin_group_members (group_id, user_id, last_read_id, joined_at) VALUES (?, ?, 0, NOW())');
                $uid = 0;
                $mem->bind_param('ii', $newId, $uid);
                foreach (array_merge([$meId], array_values($ids)) as $uid) {
                    $mem->execute();
                }
                $mem->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            header('Location: messages.php?group=' . $newId);
            exit;
        }

        /* ---- react to a message (same emoji again takes it back) ---- */
        if ($act === 'react_msg') {
            $emojiIn = str_replace("\u{FE0F}", '', (string)($_POST['emoji'] ?? ''));
            $emoji = null;
            foreach (['👍', '❤️', '😂', '😮', '😢', '🙏'] as $opt) {
                if (str_replace("\u{FE0F}", '', $opt) === $emojiIn) {
                    $emoji = $opt;
                }
            }
            if ($emoji === null) {
                throw new RuntimeException('That reaction is not available.');
            }
            $mid = (int)($_POST['id'] ?? 0);
            if (!vs_msg_visible($conn, $kindIn, $mid, $meId)) {
                throw new RuntimeException('That message could not be found.');
            }
            $k = $kindIn === 'group' ? 'g' : 'd';
            $cur = $conn->prepare('SELECT emoji FROM admin_msg_reactions WHERE kind = ? AND msg_id = ? AND user_id = ?');
            $cur->bind_param('sii', $k, $mid, $meId);
            $cur->execute();
            $curRow = $cur->get_result()->fetch_assoc();
            $cur->close();
            if ($curRow && (string)$curRow['emoji'] === $emoji) {
                $r = $conn->prepare('DELETE FROM admin_msg_reactions WHERE kind = ? AND msg_id = ? AND user_id = ?');
                $r->bind_param('sii', $k, $mid, $meId);
            } else {
                $r = $conn->prepare('INSERT INTO admin_msg_reactions (kind, msg_id, user_id, emoji) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)');
                $r->bind_param('siis', $k, $mid, $meId, $emoji);
            }
            $r->execute();
            $r->close();
            vs_msg_json(['ok' => true]);
        }

        /* ---- forward a message to another chat ---- */
        if ($act === 'forward_msg') {
            $src = vs_msg_visible($conn, $kindIn, (int)($_POST['id'] ?? 0), $meId);
            if (!$src) {
                throw new RuntimeException('That message could not be found.');
            }
            if (!preg_match('/^([dg])([0-9]{1,10})$/', (string)($_POST['target'] ?? ''), $tm)) {
                throw new RuntimeException('Choose where to forward the message.');
            }
            $targetId = (int)$tm[2];
            $srcBody = (string)$src['body'];
            $att = null;
            if (!empty($src['att_path'])) {
                $from = vs_msg_upload_dir() . '/' . basename((string)$src['att_path']);
                if (!is_file($from)) {
                    throw new RuntimeException('The original file is no longer available.');
                }
                $newName = bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo((string)$src['att_path'], PATHINFO_EXTENSION));
                if (!@copy($from, vs_msg_upload_dir() . '/' . $newName)) {
                    throw new RuntimeException('The file could not be forwarded.');
                }
                $att = ['name' => (string)$src['att_name'], 'path' => $newName, 'type' => (string)$src['att_type'], 'size' => (int)$src['att_size']];
                $upload = $att;
            }
            $isMarker = $att && $srcBody === '📎 ' . $att['name'];
            $fwdBody = $isMarker ? $srcBody : mb_substr('↪ Forwarded' . "\n" . $srcBody, 0, 2000);

            if ($tm[1] === 'd') {
                if (!isset($admins[$targetId])) {
                    throw new RuntimeException('Choose another admin to forward to.');
                }
                vs_msg_post_dm($conn, $meId, $meName, $targetId, $fwdBody, 0, $att);
            } else {
                if (!vs_group_member($conn, $targetId, $meId)) {
                    throw new RuntimeException('You are not in that group.');
                }
                vs_msg_post_group($conn, $targetId, $meId, $meName, $fwdBody, 0, $att);
            }
            $upload = null; /* the copy now belongs to the new message */
            vs_msg_json(['ok' => true]);
        }

        /* ---- edit one of my own messages ---- */
        if ($act === 'edit_msg') {
            $mid = (int)($_POST['id'] ?? 0);
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($mid <= 0) {
                throw new RuntimeException('That message could not be found.');
            }
            if ($body === '') {
                throw new RuntimeException('A message cannot be empty. Delete it instead.');
            }
            if (mb_strlen($body) > 2000) {
                throw new RuntimeException('Messages can be at most 2000 characters.');
            }
            if ($kindIn === 'group') {
                if (!vs_group_member($conn, $gid, $meId)) {
                    throw new RuntimeException('You are not in that group.');
                }
                $upd = $conn->prepare('UPDATE admin_group_messages SET body = ?, edited_at = NOW() WHERE id = ? AND group_id = ? AND sender_id = ?');
                $upd->bind_param('siii', $body, $mid, $gid, $meId);
            } else {
                $upd = $conn->prepare('UPDATE admin_messages SET body = ?, edited_at = NOW() WHERE id = ? AND sender_id = ?');
                $upd->bind_param('sii', $body, $mid, $meId);
            }
            $upd->execute();
            $upd->close();
            vs_msg_json(['ok' => true]);
        }

        /* ---- delete one of my own messages ---- */
        if ($act === 'delete_msg') {
            $mid = (int)($_POST['id'] ?? 0);
            if ($mid <= 0) {
                throw new RuntimeException('That message could not be found.');
            }
            $attPath = null;
            if ($kindIn === 'group') {
                if (!vs_group_member($conn, $gid, $meId)) {
                    throw new RuntimeException('You are not in that group.');
                }
                $sel = $conn->prepare('SELECT att_path FROM admin_group_messages WHERE id = ? AND group_id = ? AND sender_id = ?');
                $sel->bind_param('iii', $mid, $gid, $meId);
                $sel->execute();
                $selRow = $sel->get_result()->fetch_assoc();
                $sel->close();
                $attPath = $selRow['att_path'] ?? null;
                $del = $conn->prepare('DELETE FROM admin_group_messages WHERE id = ? AND group_id = ? AND sender_id = ?');
                $del->bind_param('iii', $mid, $gid, $meId);
            } else {
                $sel = $conn->prepare('SELECT att_path FROM admin_messages WHERE id = ? AND sender_id = ?');
                $sel->bind_param('ii', $mid, $meId);
                $sel->execute();
                $selRow = $sel->get_result()->fetch_assoc();
                $sel->close();
                $attPath = $selRow['att_path'] ?? null;
                $del = $conn->prepare('DELETE FROM admin_messages WHERE id = ? AND sender_id = ?');
                $del->bind_param('ii', $mid, $meId);
            }
            $del->execute();
            $removed = $del->affected_rows > 0;
            $del->close();
            if ($removed) {
                vs_msg_remove_file($attPath);
                $k = $kindIn === 'group' ? 'g' : 'd';
                $rd = $conn->prepare('DELETE FROM admin_msg_reactions WHERE kind = ? AND msg_id = ?');
                $rd->bind_param('si', $k, $mid);
                $rd->execute();
                $rd->close();
            }
            if ($isAjax) {
                vs_msg_json(['ok' => $removed, 'error' => $removed ? '' : 'You can only delete your own messages.']);
            }
            header('Location: messages.php' . ($gid > 0 ? '?group=' . $gid : ''));
            exit;
        }

        /* ---- group actions below need membership ---- */
        $group = vs_group_member($conn, $gid, $meId);
        if (!$group) {
            throw new RuntimeException('You are not in that group.');
        }
        $isOwner = (int)$group['created_by'] === $meId;

        if ($act === 'group_send') {
            $upload = vs_msg_store_upload($_FILES['file'] ?? null);
            $body = vs_msg_clean_body($upload !== null);
            if ($body === '' && $upload) {
                $body = '📎 ' . $upload['name'];
            }
            vs_msg_post_group($conn, $gid, $meId, $meName, $body, (int)($_POST['reply_to'] ?? 0), $upload);
            vs_msg_set_typing($conn, $meId, null);
            if ($isAjax) {
                vs_msg_json(['ok' => true]);
            }
            header('Location: messages.php?group=' . $gid);
            exit;
        }

        if ($act === 'group_add') {
            if (!$isOwner) {
                throw new RuntimeException('Only the group owner can add people.');
            }
            $current = vs_group_members($conn, $gid);
            $maxStmt = $conn->prepare('SELECT COALESCE(MAX(id), 0) FROM admin_group_messages WHERE group_id = ?');
            $maxStmt->bind_param('i', $gid);
            $maxStmt->execute();
            $maxStmt->bind_result($maxId);
            $maxStmt->fetch();
            $maxStmt->close();

            $add = $conn->prepare('INSERT IGNORE INTO admin_group_members (group_id, user_id, last_read_id, joined_at) VALUES (?, ?, ?, NOW())');
            $uid = 0;
            $add->bind_param('iii', $gid, $uid, $maxId);
            $added = 0;
            foreach ((array)($_POST['members'] ?? []) as $m) {
                $uid = (int)$m;
                if (isset($admins[$uid]) && !isset($current[$uid])) {
                    $add->execute();
                    $added++;
                }
            }
            $add->close();
            if ($added === 0) {
                throw new RuntimeException('Pick at least one admin to add.');
            }
            header('Location: messages.php?group=' . $gid);
            exit;
        }

        if ($act === 'group_rename') {
            if (!$isOwner) {
                throw new RuntimeException('Only the group owner can rename the group.');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 60) {
                throw new RuntimeException('Give the group a name (up to 60 characters).');
            }
            $up = $conn->prepare('UPDATE admin_groups SET name = ? WHERE id = ?');
            $up->bind_param('si', $name, $gid);
            $up->execute();
            $up->close();
            header('Location: messages.php?group=' . $gid);
            exit;
        }

        if ($act === 'group_remove' || $act === 'group_owner') {
            if (!$isOwner) {
                throw new RuntimeException('Only the group owner can do that.');
            }
            $target = (int)($_POST['user'] ?? 0);
            $members = vs_group_members($conn, $gid);
            if ($target === $meId || !isset($members[$target])) {
                throw new RuntimeException('Pick another member of this group.');
            }
            if ($act === 'group_remove') {
                $d = $conn->prepare('DELETE FROM admin_group_members WHERE group_id = ? AND user_id = ?');
                $d->bind_param('ii', $gid, $target);
            } else {
                $d = $conn->prepare('UPDATE admin_groups SET created_by = ? WHERE id = ?');
                $d->bind_param('ii', $target, $gid);
            }
            $d->execute();
            $d->close();
            header('Location: messages.php?group=' . $gid);
            exit;
        }

        if ($act === 'group_leave') {
            $del = $conn->prepare('DELETE FROM admin_group_members WHERE group_id = ? AND user_id = ?');
            $del->bind_param('ii', $gid, $meId);
            $del->execute();
            $del->close();

            $left = count(vs_group_members($conn, $gid));
            if ($left === 0) {
                $files = $conn->prepare('SELECT att_path FROM admin_group_messages WHERE group_id = ? AND att_path IS NOT NULL');
                $files->bind_param('i', $gid);
                $files->execute();
                $fr = $files->get_result();
                while ($f = $fr->fetch_assoc()) {
                    vs_msg_remove_file((string)$f['att_path']);
                }
                $files->close();
                $d1 = $conn->prepare('DELETE FROM admin_group_messages WHERE group_id = ?');
                $d1->bind_param('i', $gid);
                $d1->execute();
                $d1->close();
                $d2 = $conn->prepare('DELETE FROM admin_groups WHERE id = ?');
                $d2->bind_param('i', $gid);
                $d2->execute();
                $d2->close();
            } elseif ($isOwner) {
                $up = $conn->prepare('UPDATE admin_groups SET created_by = (SELECT MIN(user_id) FROM admin_group_members WHERE group_id = ?) WHERE id = ?');
                $up->bind_param('ii', $gid, $gid);
                $up->execute();
                $up->close();
            }
            header('Location: messages.php');
            exit;
        }
    } catch (Throwable $error) {
        if (!empty($upload['path'])) {
            vs_msg_remove_file((string)$upload['path']);
        }
        $failMsg = $error instanceof mysqli_sql_exception ? 'Something went wrong. Please try again.' : $error->getMessage();
        if ($isAjax) {
            vs_msg_json(['ok' => false, 'error' => $failMsg], 422);
        }
        $errorMsg = $failMsg;
        if ($gid > 0) {
            $_GET['group'] = $gid;
        } elseif ($toId > 0) {
            $_GET['with'] = $toId;
        }
    }
}

/* =====================================================================
   Open a group or a conversation
   ===================================================================== */
$partial = (string)($_GET['partial'] ?? '');

$groupId = (int)($_GET['group'] ?? 0);
$group = $groupId > 0 ? vs_group_member($conn, $groupId, $meId) : null;
if (!$group) {
    $groupId = 0;
}

$withId = (int)($_GET['with'] ?? 0);
if ($groupId > 0 || !isset($admins[$withId])) {
    $withId = 0;
}

/* where the "New messages" line goes (worked out before the chat is marked as read) */
$unreadFrom = 0;
if ($partial === '' && $meId > 0) {
    if ($groupId > 0) {
        $uq = $conn->prepare(
            'SELECT COALESCE(MIN(m.id), 0) FROM admin_group_messages m
             JOIN admin_group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
             WHERE m.group_id = ? AND m.id > gm.last_read_id AND m.sender_id <> ?'
        );
        $uq->bind_param('iii', $meId, $groupId, $meId);
    } elseif ($withId > 0) {
        $uq = $conn->prepare('SELECT COALESCE(MIN(id), 0) FROM admin_messages WHERE recipient_id = ? AND sender_id = ? AND is_read = 0');
        $uq->bind_param('ii', $meId, $withId);
    } else {
        $uq = null;
    }
    if ($uq) {
        $uq->execute();
        $uq->bind_result($unreadFrom);
        $uq->fetch();
        $uq->close();
        $unreadFrom = (int)$unreadFrom;
    }
} elseif ($partial === 'thread') {
    $unreadFrom = max(0, (int)($_GET['uf'] ?? 0));
}

/* A hidden browser tab keeps polling the chat list but must not mark messages as read. */
$markRead = !($partial === 'sidebar' && !isset($_GET['view']));

$groupMembers = [];
if ($groupId > 0) {
    if ($markRead) {
        $markG = $conn->prepare(
            'UPDATE admin_group_members
             SET last_read_id = (SELECT COALESCE(MAX(id), 0) FROM admin_group_messages WHERE group_id = ?)
             WHERE group_id = ? AND user_id = ?'
        );
        $markG->bind_param('iii', $groupId, $groupId, $meId);
        $markG->execute();
        $markG->close();
    }
    $groupMembers = vs_group_members($conn, $groupId);
}

if ($withId > 0 && $meId > 0 && $markRead) {
    $mark = $conn->prepare('UPDATE admin_messages SET is_read = 1 WHERE recipient_id = ? AND sender_id = ? AND is_read = 0');
    $mark->bind_param('ii', $meId, $withId);
    $mark->execute();
    $mark->close();

    vs_notifications_ensure_table($conn);
    $notifLink = 'adminpanel/messages.php?with=' . $withId;
    $markNotif = $conn->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND type = 'message' AND link = ?");
    $markNotif->bind_param('is', $meId, $notifLink);
    $markNotif->execute();
    $markNotif->close();
}

vs_msg_heartbeat($conn, $meId);
$presence = vs_msg_presence_map($conn);
$prefs = $meId > 0 ? vs_msg_prefs($conn, $meId) : [];

/* ---- live refresh endpoints (polled by the page) ---- */
if ($partial === '1' || $partial === 'thread') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $typingText = '';
    $statusText = '';
    if ($groupId > 0) {
        $typingText = vs_msg_typing_text(vs_msg_typing_names($presence, $admins, $meId, 'g:' . $groupId));
    } elseif ($withId > 0) {
        $pp = $presence[$withId] ?? null;
        $statusText = vs_msg_status_text($pp);
        if ($pp && (string)($pp['typing_chat'] ?? '') === 'd:' . $meId && (int)$pp['typing_ago'] <= 5) {
            $typingText = 'typing…';
        }
    }
    header('X-Typing: ' . rawurlencode($typingText));
    header('X-Status: ' . rawurlencode($statusText));
    if ($groupId > 0) {
        echo vs_msg_group_thread_html($conn, $meId, $groupId, $meName, $unreadFrom);
    } else {
        echo $withId > 0 ? vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId], $unreadFrom) : '';
    }
    exit;
}

$side = $meId > 0
    ? vs_msg_sidebar_data($conn, $meId, $admins, $prefs, $presence)
    : ['groups' => [], 'contacts' => [], 'unread' => 0, 'chats' => []];
if ($partial === 'sidebar') {
    vs_msg_json([
        'groups' => vs_msg_group_items($side['groups'], $groupId),
        'admins' => vs_msg_admin_items($side['contacts'], $withId),
        'unread' => (int)$side['unread'],
        'chats' => $side['chats'],
    ]);
}

$isGroupOwner = $groupId > 0 && (int)$group['created_by'] === $meId;
$addCandidates = [];
if ($isGroupOwner) {
    foreach ($admins as $aid => $aname) {
        if (!isset($groupMembers[$aid])) {
            $addCandidates[$aid] = $aname;
        }
    }
}
$memberLine = [];
foreach ($groupMembers as $mid => $mname) {
    $memberLine[] = $mid === $meId ? 'You' : $mname;
}
usort($memberLine, function ($a, $b) {
    return $a === 'You' ? -1 : ($b === 'You' ? 1 : strcasecmp($a, $b));
});

$chatOpen = $groupId > 0 || $withId > 0;
$chatKey = $groupId > 0 ? 'g' . $groupId : ($withId > 0 ? 'd' . $withId : '');
$chatPrefs = $prefs[$chatKey] ?? ['pinned' => 0, 'muted' => 0];
$pollBase = $groupId > 0 ? 'group=' . $groupId : ($withId > 0 ? 'with=' . $withId : '');
$headStatus = $withId > 0 ? vs_msg_status_text($presence[$withId] ?? null) : '';
$groupSub = $groupId > 0 ? count($groupMembers) . ' members: ' . implode(', ', $memberLine) : '';
$jsConfig = [
    'csrf' => $csrf,
    'me' => $meId,
    'kind' => $groupId > 0 ? 'group' : ($withId > 0 ? 'dm' : ''),
    'group' => $groupId,
    'with' => $withId,
    'key' => $chatKey,
    'threadUrl' => $chatOpen ? 'messages.php?' . $pollBase . '&partial=thread' . ($unreadFrom > 0 ? '&uf=' . $unreadFrom : '') : '',
    'sidebarUrl' => 'messages.php?partial=sidebar' . ($pollBase !== '' ? '&' . $pollBase : ''),
];

require_once __DIR__ . '/header.php';
?>

<style>
  .msg-page h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
  .msg-page .msg-sub { color: var(--admin-muted); margin: 0 0 20px; }
  .msg-page i.bi { line-height: 1; vertical-align: -0.125em; }
  .msg-shell { display: grid; grid-template-columns: 320px 1fr; gap: 16px; height: calc(100vh - 230px); min-height: 480px; }
  .msg-side, .msg-main {
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 16px; overflow: hidden;
    display: flex; flex-direction: column; min-height: 0; position: relative;
  }

  /* chat list */
  .msg-search { display: flex; gap: 8px; align-items: center; padding: 12px 14px; border-bottom: 1px solid var(--admin-border); }
  .msg-search input {
    flex: 1; min-width: 0; box-sizing: border-box; padding: 9px 14px; border-radius: 999px; font-size: 0.88rem;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none;
  }
  .msg-search input:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .msg-alert-btn {
    flex: none; width: 36px; height: 36px; border-radius: 50%; border: 1px solid var(--admin-border); background: transparent;
    color: var(--admin-text); cursor: pointer; font-size: 1rem; display: inline-flex; align-items: center; justify-content: center;
  }
  .msg-alert-btn:hover { background: rgba(127, 127, 127, 0.15); }
  .msg-alert-btn.off { opacity: 0.55; }
  .msg-side-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 18px 6px; font-size: 0.8rem; font-weight: 700; color: var(--admin-muted); }
  .msg-new {
    border: 0; border-radius: 999px; padding: 4px 12px; background: var(--admin-primary); color: #fff;
    font-size: 0.76rem; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 4px;
  }
  .msg-new:hover { background: var(--admin-primary-dark); }
  .msg-none { padding: 6px 18px 14px; font-size: 0.85rem; color: var(--admin-muted); }
  .msg-contacts { overflow-y: auto; flex: 1; }
  .msg-contact {
    display: flex; align-items: center; gap: 12px; padding: 10px 18px; color: var(--admin-text); text-decoration: none;
  }
  .msg-contact[hidden] { display: none; }
  .msg-contact:hover { background: rgba(127, 127, 127, 0.1); color: var(--admin-text); }
  .msg-contact.active { background: rgba(124, 58, 237, 0.14); }
  .msg-contact-copy { min-width: 0; flex: 1; }
  .msg-contact-name { display: flex; align-items: center; gap: 6px; font-weight: 600; }
  .msg-cname { min-width: 0; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .msg-flags { flex: none; display: inline-flex; gap: 4px; font-size: 0.72rem; opacity: 0.8; }
  .msg-contact-time { flex: none; font-weight: 400; font-size: 0.74rem; color: var(--admin-muted); white-space: nowrap; }
  .msg-contact.has-unread .msg-contact-time { color: var(--admin-primary); font-weight: 700; }
  .msg-contact.is-muted.has-unread .msg-contact-time { color: var(--admin-muted); font-weight: 400; }
  .msg-contact-line { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 1px; }
  .msg-contact-preview { min-width: 0; font-size: 0.85rem; color: var(--admin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .msg-contact.has-unread .msg-contact-preview { color: var(--admin-text); font-weight: 600; }
  .msg-contact.has-draft .msg-contact-preview { color: var(--admin-text); font-weight: 400; }
  .msg-draft-tag { color: #dc2626; font-weight: 600; }
  .msg-typing { color: #16a34a; font-weight: 600; }
  .msg-unread { flex: none; background: var(--admin-primary); color: #fff; border-radius: 999px; font-size: 0.7rem; font-weight: 700; padding: 1px 7px; min-width: 20px; text-align: center; }
  .msg-contact.is-muted .msg-unread { background: #6b7280; }
  .msg-avwrap { position: relative; flex: none; display: inline-flex; }
  .msg-dot { position: absolute; right: 0; bottom: 0; width: 11px; height: 11px; border-radius: 50%; background: #22c55e; border: 2px solid var(--admin-surface); }
  .msg-gavatar {
    flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    background: var(--admin-primary); color: #fff; font-weight: 700;
  }
  .msg-gavatar.sm { flex-basis: 36px; width: 36px; height: 36px; }

  /* conversation header */
  .msg-main-head { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-bottom: 1px solid var(--admin-border); font-weight: 700; flex-wrap: wrap; }
  .msg-back {
    display: none; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%;
    color: var(--admin-text); text-decoration: none; font-size: 1.25rem; line-height: 1;
  }
  .msg-back:hover { background: rgba(127, 127, 127, 0.15); color: var(--admin-text); }
  .msg-head-copy { min-width: 0; flex: 1; line-height: 1.25; }
  .msg-head-sub { display: block; font-weight: 400; font-size: 0.8rem; color: var(--admin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .msg-head-sub.typing { color: #16a34a; font-weight: 600; }
  .msg-head-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
  .msg-hbtn {
    width: 34px; height: 34px; border-radius: 50%; border: 1px solid transparent; background: transparent; color: var(--admin-text);
    cursor: pointer; font-size: 1.05rem; display: inline-flex; align-items: center; justify-content: center;
  }
  .msg-hbtn:hover { background: rgba(127, 127, 127, 0.15); }
  .msg-hbtn.on { background: rgba(124, 58, 237, 0.2); border-color: var(--admin-primary); color: var(--admin-primary); }
  .msg-small-btn {
    border: 1px solid var(--admin-border); border-radius: 999px; padding: 5px 12px; background: transparent;
    color: var(--admin-text); font-size: 0.78rem; font-weight: 600; cursor: pointer; list-style: none;
    display: inline-flex; align-items: center; gap: 5px;
  }
  .msg-small-btn:hover { background: rgba(127, 127, 127, 0.12); }
  .msg-small-btn.danger { color: #dc2626; }
  .msg-add { position: relative; }
  .msg-add summary::-webkit-details-marker { display: none; }
  .msg-add-box {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; width: 250px; padding: 12px;
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  }
  .msg-add-box.wide { width: 300px; }
  .msg-pick { max-height: 200px; overflow-y: auto; margin: 8px 0 12px; display: flex; flex-direction: column; gap: 6px; }
  .msg-pick label { display: flex; align-items: center; gap: 8px; font-weight: 400; cursor: pointer; }
  .msg-member { display: flex; align-items: center; gap: 6px; font-weight: 400; padding: 4px 0; }
  .msg-member > span { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .msg-member form { margin: 0; }
  .msg-member .msg-small-btn { padding: 2px 8px; font-size: 0.7rem; }
  .msg-owner-tag { flex: none; font-size: 0.68rem; color: var(--admin-primary); font-weight: 700; }

  /* search inside a chat */
  .msg-findbar { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-bottom: 1px solid var(--admin-border); background: var(--admin-surface); }
  .msg-findbar[hidden] { display: none; }
  .msg-findbar input {
    flex: 1; min-width: 0; padding: 7px 12px; border-radius: 999px; border: 1px solid var(--admin-border);
    background: var(--admin-bg); color: var(--admin-text); outline: none; font-size: 0.88rem;
  }
  .msg-findbar input:focus { border-color: var(--admin-primary); }
  .msg-findcount { font-size: 0.78rem; color: var(--admin-muted); white-space: nowrap; }
  .msg-findbar button {
    border: 0; background: transparent; color: var(--admin-text); cursor: pointer; width: 28px; height: 28px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .msg-findbar button:hover { background: rgba(127, 127, 127, 0.18); }
  .msg-row.hit .msg-bubble { box-shadow: 0 0 0 2px rgba(250, 204, 21, 0.55); }
  .msg-row.current .msg-bubble { box-shadow: 0 0 0 3px #facc15; }

  /* thread */
  .msg-thread { flex: 1; overflow-y: auto; padding: 14px 18px 18px; display: flex; flex-direction: column; gap: 2px; min-height: 0; background: var(--admin-bg); }
  .msg-day { align-self: center; margin: 12px 0 6px; }
  .msg-day span { display: inline-block; padding: 3px 12px; border-radius: 999px; background: rgba(127, 127, 127, 0.2); color: var(--admin-muted); font-size: 0.74rem; font-weight: 600; }
  .msg-newdiv { display: flex; align-items: center; gap: 10px; margin: 12px 0 6px; color: var(--admin-primary); font-size: 0.75rem; font-weight: 700; }
  .msg-newdiv::before, .msg-newdiv::after { content: ""; flex: 1; height: 1px; background: var(--admin-primary); opacity: 0.5; }
  .msg-row { display: flex; align-items: flex-end; gap: 8px; max-width: 78%; }
  .msg-row.first { margin-top: 8px; }
  .msg-row.me { align-self: flex-end; flex-direction: row-reverse; }
  .msg-row.them { align-self: flex-start; }
  .msg-row.pending { opacity: 0.6; }
  .msg-row.flash .msg-bubble { animation: msgflash 1.4s ease; }
  @keyframes msgflash { 0%, 60% { box-shadow: 0 0 0 3px var(--admin-primary); } 100% { box-shadow: 0 0 0 0 transparent; } }
  .msg-av { flex: 0 0 32px; width: 32px; height: 32px; display: inline-flex; }
  .msg-bubble { min-width: 0; padding: 7px 12px 5px; border-radius: 18px; overflow-wrap: anywhere; }
  .msg-bubble.has-att { padding: 5px 5px 5px; }
  .msg-bubble.has-att .msg-text, .msg-bubble.has-att .msg-sender, .msg-bubble.has-att .msg-meta { padding-left: 7px; padding-right: 7px; }
  .msg-bubble.me { background: var(--admin-primary); color: #fff; }
  .msg-bubble.me a { color: #fff; text-decoration: underline; }
  .msg-bubble.them { background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--admin-text); }
  .msg-bubble.them a { color: var(--admin-primary); }
  .msg-row.me:not(.first) .msg-bubble { border-top-right-radius: 6px; }
  .msg-row.me:not(.last) .msg-bubble { border-bottom-right-radius: 6px; }
  .msg-row.me.last .msg-bubble { border-bottom-right-radius: 4px; }
  .msg-row.them:not(.first) .msg-bubble { border-top-left-radius: 6px; }
  .msg-row.them:not(.last) .msg-bubble { border-bottom-left-radius: 6px; }
  .msg-row.them.last .msg-bubble { border-bottom-left-radius: 4px; }
  .msg-text { line-height: 1.4; }
  .msg-sender { font-size: 0.75rem; font-weight: 700; color: var(--admin-primary); margin-bottom: 1px; }
  .msg-meta { display: flex; justify-content: flex-end; align-items: center; gap: 5px; margin-top: 2px; font-size: 0.68rem; opacity: 0.72; }
  .msg-edited { font-style: italic; }
  .msg-tick { display: inline-flex; align-items: center; font-size: 1rem; }
  .msg-bubble.me .msg-tick.read { color: #7dd3fc; opacity: 1; }
  .msg-quote {
    display: flex; flex-direction: column; gap: 1px; margin: 0 0 5px; padding: 5px 9px; border-left: 3px solid var(--admin-primary);
    border-radius: 8px; background: rgba(127, 127, 127, 0.18); cursor: pointer; font-size: 0.8rem; min-width: 120px;
  }
  .msg-quote.gone { cursor: default; font-style: italic; opacity: 0.8; }
  .msg-bubble.me .msg-quote { background: rgba(0, 0, 0, 0.22); border-left-color: #fff; }
  .msg-qname { font-weight: 700; }
  .msg-qtext { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 260px; opacity: 0.9; }
  .msg-img { display: block; }
  .msg-img img { display: block; max-width: 260px; max-height: 300px; width: auto; height: auto; border-radius: 13px; object-fit: cover; }
  .msg-file {
    display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-radius: 12px; text-decoration: none !important;
    background: rgba(127, 127, 127, 0.18); color: inherit !important; min-width: 190px;
  }
  .msg-file-ico { font-size: 1.6rem; display: inline-flex; }
  .msg-file-copy { display: flex; flex-direction: column; min-width: 0; }
  .msg-file-copy b { font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 200px; }
  .msg-file-copy small { opacity: 0.75; }
  .msg-reacts { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
  .msg-bubble.has-att .msg-reacts { padding: 0 6px 2px; }
  .msg-chip {
    border: 1px solid var(--admin-border); background: rgba(127, 127, 127, 0.18); color: inherit; border-radius: 999px;
    padding: 1px 8px; font-size: 0.78rem; cursor: pointer; line-height: 1.5;
  }
  .msg-chip.mine { border-color: var(--admin-primary); background: rgba(124, 58, 237, 0.25); }
  .msg-acts { flex: none; align-self: center; display: inline-flex; opacity: 0; transition: opacity 0.12s; }
  .msg-row:hover .msg-acts, .msg-acts:focus-within { opacity: 1; }
  @media (hover: none) { .msg-acts { opacity: 0.6; } }
  .msg-more {
    border: 0; background: transparent; color: var(--admin-muted); width: 28px; height: 28px; border-radius: 50%; cursor: pointer;
    font-size: 1.05rem; line-height: 1; display: inline-flex; align-items: center; justify-content: center;
  }
  .msg-more:hover { color: var(--admin-text); background: rgba(127, 127, 127, 0.2); }
  .msg-row.editing .msg-bubble { outline: 2px solid var(--admin-primary); outline-offset: 2px; }
  .msg-empty { margin: auto; color: var(--admin-muted); text-align: center; padding: 24px; }

  /* message menu */
  .msg-menu {
    position: fixed; z-index: 1090; min-width: 210px; padding: 6px; background: var(--admin-surface); color: var(--admin-text);
    border: 1px solid var(--admin-border); border-radius: 14px; box-shadow: 0 12px 34px rgba(0, 0, 0, 0.4);
  }
  .msg-menu[hidden] { display: none; }
  .msg-menu-react { display: flex; justify-content: space-between; gap: 2px; padding: 2px 2px 6px; border-bottom: 1px solid var(--admin-border); margin-bottom: 4px; }
  .msg-menu-react button { border: 0; background: transparent; font-size: 1.3rem; padding: 3px 6px; border-radius: 8px; cursor: pointer; }
  .msg-menu-react button:hover { background: rgba(127, 127, 127, 0.22); }
  .msg-menu-react button.mine { background: rgba(124, 58, 237, 0.3); }
  .msg-menu-item {
    display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; border: 0; background: transparent; color: inherit; padding: 8px 12px;
    border-radius: 8px; cursor: pointer; font-size: 0.9rem;
  }
  .msg-menu-item i { font-size: 1rem; width: 1.1rem; text-align: center; opacity: 0.85; }
  .msg-menu-item:hover { background: rgba(127, 127, 127, 0.18); }
  .msg-menu-item.danger { color: #dc2626; }

  /* bars above the composer (reply, edit, attachment) */
  .msg-bars:empty { display: none; }
  .msg-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 16px; font-size: 0.85rem;
    border-top: 1px solid var(--admin-border); background: rgba(124, 58, 237, 0.1); color: var(--admin-text);
  }
  .msg-bar[hidden] { display: none; }
  .msg-bar-copy { min-width: 0; display: flex; flex-direction: column; }
  .msg-bar-copy b { color: var(--admin-primary); }
  .msg-bar-copy span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--admin-muted); }
  .msg-bar-thumb { width: 38px; height: 38px; border-radius: 8px; object-fit: cover; flex: none; }
  .msg-bar-ico { flex: none; font-size: 1.4rem; color: var(--admin-primary); display: inline-flex; }
  .msg-bar-left { display: flex; align-items: center; gap: 10px; min-width: 0; }
  .msg-bar button { border: 0; background: transparent; color: var(--admin-primary); font-weight: 700; cursor: pointer; flex: none; }

  /* composer */
  .msg-form { display: flex; align-items: flex-end; gap: 6px; padding: 10px 12px 6px; border-top: 1px solid var(--admin-border); position: relative; }
  .msg-form textarea {
    flex: 1; resize: none; min-height: 44px; max-height: 140px; padding: 11px 16px; border-radius: 22px; line-height: 1.35;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none; font: inherit;
  }
  .msg-form textarea:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .msg-round {
    flex: none; width: 44px; height: 44px; border: 0; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: opacity 0.15s;
  }
  .msg-round.ghost { background: transparent; color: var(--admin-muted); font-size: 1.3rem; }
  .msg-round.ghost:hover { background: rgba(127, 127, 127, 0.15); color: var(--admin-text); }
  .msg-round.send { background: var(--admin-primary); color: #fff; font-size: 1.1rem; }
  .msg-round.send:hover:not(:disabled) { background: var(--admin-primary-dark); }
  .msg-round.send:disabled { opacity: 0.4; cursor: default; }
  .msg-hint { display: flex; justify-content: flex-end; gap: 6px; align-items: center; padding: 0 16px 8px; font-size: 0.74rem; color: var(--admin-muted); cursor: pointer; }
  .msg-hint input { margin: 0; }
  .msg-emoji {
    position: absolute; left: 12px; bottom: calc(100% + 6px); z-index: 15; display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px;
    padding: 8px; max-width: calc(100% - 24px);
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 14px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  }
  .msg-emoji[hidden] { display: none; }
  .msg-emoji button { border: 0; background: transparent; font-size: 1.3rem; padding: 4px 6px; border-radius: 8px; cursor: pointer; }
  .msg-emoji button:hover { background: rgba(127, 127, 127, 0.2); }
  .msg-down {
    position: absolute; right: 20px; bottom: 96px; z-index: 12; width: 40px; height: 40px; border-radius: 50%;
    border: 1px solid var(--admin-border); background: var(--admin-surface); color: var(--admin-text); font-size: 1.1rem;
    cursor: pointer; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3); display: inline-flex; align-items: center; justify-content: center;
  }
  .msg-down[hidden] { display: none; }
  .msg-down span { position: absolute; top: -8px; right: -4px; background: var(--admin-primary); color: #fff; border-radius: 999px; font-size: 0.68rem; font-weight: 700; padding: 0 6px; min-width: 18px; }
  .msg-down span:empty { display: none; }
  .msg-toast {
    position: absolute; left: 50%; bottom: 100px; transform: translateX(-50%); z-index: 30; max-width: 90%;
    background: #dc2626; color: #fff; padding: 8px 16px; border-radius: 10px; font-size: 0.85rem; box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
  }
  .msg-toast[hidden] { display: none; }
  .msg-placeholder { margin: auto; text-align: center; color: var(--admin-muted); padding: 24px; }
  .msg-placeholder i { display: block; font-size: 2.4rem; margin-bottom: 10px; opacity: 0.6; }

  /* dialogs (new group, forward) */
  .msg-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6); display: flex; align-items: center; justify-content: center; padding: 16px; z-index: 1080; }
  .msg-modal[hidden] { display: none; }
  .msg-modal-box {
    width: min(440px, 100%); max-height: 90vh; overflow-y: auto; padding: 24px; border-radius: 16px;
    background: var(--admin-surface); border: 1px solid var(--admin-border); color: var(--admin-text);
  }
  .msg-modal-box h2 { font-size: 1.2rem; margin: 0 0 14px; }
  .msg-modal-box label.msg-label { display: block; font-weight: 600; margin: 12px 0 6px; }
  .msg-modal-box input[type="text"] {
    width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 10px;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none;
  }
  .msg-modal-actions { display: flex; gap: 10px; margin-top: 18px; }
  .msg-modal-actions button { flex: 1; border: 0; border-radius: 10px; padding: 11px; font-weight: 700; cursor: pointer; background: var(--admin-primary); color: #fff; }
  .msg-modal-actions button.ghost { background: rgba(127, 127, 127, 0.25); color: var(--admin-text); }
  .msg-fwd-list { display: flex; flex-direction: column; gap: 2px; max-height: 50vh; overflow-y: auto; margin-top: 8px; }
  .msg-fwd-item {
    display: flex; align-items: center; gap: 10px; text-align: left; border: 0; background: transparent; color: inherit;
    padding: 10px 12px; border-radius: 10px; cursor: pointer; font-size: 0.95rem;
  }
  .msg-fwd-item:hover { background: rgba(127, 127, 127, 0.18); }
  .msg-fwd-item i { opacity: 0.8; }

  /* phones: one pane at a time, like a real chat app */
  @media (max-width: 860px) {
    .msg-shell { grid-template-columns: 1fr; height: calc(100vh - 190px); min-height: 440px; }
    .msg-shell.chat-open .msg-side { display: none; }
    .msg-shell:not(.chat-open) .msg-main { display: none; }
    .msg-back { display: inline-flex; }
    .msg-row { max-width: 90%; }
    .msg-img img { max-width: 220px; }
    .msg-emoji { grid-template-columns: repeat(6, 1fr); }
  }
</style>

<main class="admin-content p-3 p-lg-4">
  <div class="msg-page">
    <h1>Messages</h1>
    <p class="msg-sub">Send private messages to the other admins, or start a group chat with several of them.</p>

    <?php if ($errorMsg !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo vs_msg_h($errorMsg); ?></div>
    <?php endif; ?>

    <?php if (!$admins): ?>
      <div class="msg-side" style="padding: 36px 22px; text-align: center; color: var(--admin-muted);">
        There are no other admins yet. Make someone an admin from the Users page, then you can message each other here.
      </div>
    <?php else: ?>
      <div class="msg-shell<?php echo $chatOpen ? ' chat-open' : ''; ?>">
        <aside class="msg-side" aria-label="Chats">
          <div class="msg-search">
            <input type="search" id="msgSearch" placeholder="Search chats" autocomplete="off" aria-label="Search chats">
            <button type="button" class="msg-alert-btn" id="msgAlertBtn" title="Sound and desktop alerts for new messages" aria-label="Toggle alerts"><i class="bi bi-bell" aria-hidden="true"></i></button>
          </div>
          <div class="msg-contacts">
            <div class="msg-side-title">
              <span>Groups</span>
              <button type="button" class="msg-new" id="msgNewGroup"><i class="bi bi-plus-lg" aria-hidden="true"></i> New group</button>
            </div>
            <div id="msgGroupList"><?php echo vs_msg_group_items($side['groups'], $groupId); ?></div>

            <div class="msg-side-title"><span>Admins</span></div>
            <div id="msgAdminList"><?php echo vs_msg_admin_items($side['contacts'], $withId); ?></div>
          </div>
        </aside>

        <section class="msg-main" aria-label="Conversation">
          <?php if ($chatOpen): ?>
            <div class="msg-main-head">
              <a class="msg-back" href="messages.php" aria-label="Back to chats"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
              <?php if ($groupId > 0): ?>
                <span class="msg-gavatar sm" aria-hidden="true"><?php echo vs_msg_h(mb_strtoupper(mb_substr((string)$group['name'], 0, 1))); ?></span>
                <span class="msg-head-copy">
                  <span id="msgHeadName"><?php echo vs_msg_h($group['name']); ?></span>
                  <span class="msg-head-sub" id="msgHeadSub" data-default="<?php echo vs_msg_h($groupSub); ?>"><?php echo vs_msg_h($groupSub); ?></span>
                </span>
              <?php else: ?>
                <?php echo avatar_html($admins[$withId], 36); ?>
                <span class="msg-head-copy">
                  <span id="msgHeadName"><?php echo vs_msg_h($admins[$withId]); ?></span>
                  <span class="msg-head-sub" id="msgHeadSub" data-default="<?php echo vs_msg_h($headStatus); ?>"><?php echo vs_msg_h($headStatus); ?></span>
                </span>
              <?php endif; ?>
              <span class="msg-head-actions">
                <button type="button" class="msg-hbtn" id="msgFindBtn" title="Search in this chat" aria-label="Search in this chat"><i class="bi bi-search" aria-hidden="true"></i></button>
                <button type="button" class="msg-hbtn<?php echo $chatPrefs['pinned'] ? ' on' : ''; ?>" id="msgPinBtn" data-field="pinned" data-value="<?php echo (int)$chatPrefs['pinned']; ?>" title="<?php echo $chatPrefs['pinned'] ? 'Unpin chat' : 'Pin chat to the top'; ?>" aria-label="Pin chat"><i class="bi <?php echo $chatPrefs['pinned'] ? 'bi-pin-angle-fill' : 'bi-pin-angle'; ?>" aria-hidden="true"></i></button>
                <button type="button" class="msg-hbtn<?php echo $chatPrefs['muted'] ? ' on' : ''; ?>" id="msgMuteBtn" data-field="muted" data-value="<?php echo (int)$chatPrefs['muted']; ?>" title="<?php echo $chatPrefs['muted'] ? 'Unmute chat' : 'Mute chat'; ?>" aria-label="Mute chat"><i class="bi <?php echo $chatPrefs['muted'] ? 'bi-bell-slash-fill' : 'bi-bell-slash'; ?>" aria-hidden="true"></i></button>
                <?php if ($groupId > 0): ?>
                  <details class="msg-add">
                    <summary class="msg-small-btn"><i class="bi bi-people" aria-hidden="true"></i> Members</summary>
                    <div class="msg-add-box wide">
                      <div class="msg-pick" style="margin-bottom: 0;">
                        <?php foreach ($groupMembers as $mid => $mname): ?>
                          <div class="msg-member">
                            <span><?php echo vs_msg_h($mid === $meId ? $mname . ' (you)' : $mname); ?></span>
                            <?php if ((int)$group['created_by'] === $mid): ?><em class="msg-owner-tag">Owner</em><?php endif; ?>
                            <?php if ($isGroupOwner && $mid !== $meId): ?>
                              <form method="post" onsubmit="return confirm('Make <?php echo vs_msg_h(addslashes($mname)); ?> the owner of this group?');">
                                <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                                <input type="hidden" name="action" value="group_owner">
                                <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                                <input type="hidden" name="user" value="<?php echo (int)$mid; ?>">
                                <button type="submit" class="msg-small-btn"><i class="bi bi-star" aria-hidden="true"></i> Make owner</button>
                              </form>
                              <form method="post" onsubmit="return confirm('Remove <?php echo vs_msg_h(addslashes($mname)); ?> from this group?');">
                                <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                                <input type="hidden" name="action" value="group_remove">
                                <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                                <input type="hidden" name="user" value="<?php echo (int)$mid; ?>">
                                <button type="submit" class="msg-small-btn danger"><i class="bi bi-person-x" aria-hidden="true"></i> Remove</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </details>
                  <?php if ($isGroupOwner && $addCandidates): ?>
                    <details class="msg-add">
                      <summary class="msg-small-btn"><i class="bi bi-person-plus" aria-hidden="true"></i> Add people</summary>
                      <form method="post" class="msg-add-box">
                        <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                        <input type="hidden" name="action" value="group_add">
                        <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                        <div class="msg-pick">
                          <?php foreach ($addCandidates as $aid => $aname): ?>
                            <label><input type="checkbox" name="members[]" value="<?php echo (int)$aid; ?>"> <?php echo vs_msg_h($aname); ?></label>
                          <?php endforeach; ?>
                        </div>
                        <button type="submit" class="msg-small-btn"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add</button>
                      </form>
                    </details>
                  <?php endif; ?>
                  <?php if ($isGroupOwner): ?>
                    <button type="button" class="msg-small-btn" id="msgRenameBtn" data-name="<?php echo vs_msg_h($group['name']); ?>"><i class="bi bi-pencil" aria-hidden="true"></i> Rename</button>
                    <form method="post" id="msgRenameForm" style="display: none;">
                      <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                      <input type="hidden" name="action" value="group_rename">
                      <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                      <input type="hidden" name="name" id="msgRenameName" value="">
                    </form>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Leave this group?');" style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                    <input type="hidden" name="action" value="group_leave">
                    <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                    <button type="submit" class="msg-small-btn danger"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Leave</button>
                  </form>
                <?php endif; ?>
              </span>
            </div>

            <div class="msg-findbar" id="msgFindBar" hidden>
              <input type="search" id="msgFind" placeholder="Search in this chat" autocomplete="off" aria-label="Search in this chat">
              <span class="msg-findcount" id="msgFindCount"></span>
              <button type="button" id="msgFindPrev" title="Previous match" aria-label="Previous match"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
              <button type="button" id="msgFindNext" title="Next match" aria-label="Next match"><i class="bi bi-chevron-down" aria-hidden="true"></i></button>
              <button type="button" id="msgFindClose" title="Close search" aria-label="Close search"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>

            <div class="msg-thread" id="msgThread"><?php
              echo $groupId > 0
                  ? vs_msg_group_thread_html($conn, $meId, $groupId, $meName, $unreadFrom)
                  : vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId], $unreadFrom);
            ?></div>

            <div class="msg-bars" id="msgBars"></div>

            <form method="post" class="msg-form" id="msgForm" autocomplete="off" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
              <?php if ($groupId > 0): ?>
                <input type="hidden" name="action" value="group_send">
                <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
              <?php else: ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="to" value="<?php echo (int)$withId; ?>">
              <?php endif; ?>
              <button type="button" class="msg-round ghost" id="msgEmojiBtn" aria-label="Insert emoji" aria-expanded="false"><i class="bi bi-emoji-smile" aria-hidden="true"></i></button>
              <button type="button" class="msg-round ghost" id="msgAttachBtn" aria-label="Attach a file" title="Attach a file (or paste an image)"><i class="bi bi-paperclip" aria-hidden="true"></i></button>
              <input type="file" id="msgFile" hidden accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.csv,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.mp3,.mp4">
              <textarea name="body" id="msgBody" rows="1" maxlength="2000" placeholder="<?php echo $groupId > 0 ? 'Message the group' : 'Write a message'; ?>"></textarea>
              <button type="submit" class="msg-round send" id="msgSend" aria-label="Send message"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
              <div class="msg-emoji" id="msgEmoji" hidden></div>
            </form>
            <label class="msg-hint"><input type="checkbox" id="msgEnterSend" checked> Enter sends the message (Shift+Enter for a new line)</label>

            <button type="button" class="msg-down" id="msgDown" hidden aria-label="Jump to the latest message"><i class="bi bi-arrow-down" aria-hidden="true"></i><span id="msgDownN"></span></button>
          <?php else: ?>
            <div class="msg-placeholder"><i class="bi bi-chat-dots" aria-hidden="true"></i>Choose an admin or a group on the left to start a conversation.</div>
          <?php endif; ?>
          <div class="msg-toast" id="msgToast" role="status" hidden></div>
        </section>
      </div>

      <div class="msg-modal" id="msgModal" hidden>
        <div class="msg-modal-box" role="dialog" aria-modal="true" aria-labelledby="msgModalTitle">
          <h2 id="msgModalTitle">New group</h2>
          <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
            <input type="hidden" name="action" value="create_group">
            <label class="msg-label" for="msgGroupName">Group name</label>
            <input type="text" id="msgGroupName" name="name" maxlength="60" placeholder="For example: Moderators" required>
            <label class="msg-label">Members</label>
            <div class="msg-pick">
              <?php foreach ($admins as $aid => $aname): ?>
                <label><input type="checkbox" name="members[]" value="<?php echo (int)$aid; ?>"> <?php echo vs_msg_h($aname); ?></label>
              <?php endforeach; ?>
            </div>
            <div class="msg-modal-actions">
              <button type="button" class="ghost" id="msgModalClose">Cancel</button>
              <button type="submit">Create group</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</main>

</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<?php if ($admins): ?>
<script>
  (function () {
    var cfg = <?php echo json_encode($jsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

    function $(id) { return document.getElementById(id); }
    var thread = $('msgThread');
    var form = $('msgForm');
    var body = $('msgBody');
    var toast = $('msgToast');
    var search = $('msgSearch');
    var groupList = $('msgGroupList');
    var adminList = $('msgAdminList');
    var alertBtn = $('msgAlertBtn');
    var baseTitle = document.title.replace(/^\(\d+\)\s*/, '');
    var toastTimer = null;
    var QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /* ---------- helpers ---------- */
    var store = {
      get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
      set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} },
      del: function (k) { try { window.localStorage.removeItem(k); } catch (e) {} }
    };
    function norm(e) { return String(e || '').replace(/️/g, ''); }

    /* Bootstrap Icons helper: icon('bi-reply') -> <i class="bi bi-reply"></i> */
    function icon(name) {
      var i = document.createElement('i');
      i.className = 'bi ' + name;
      i.setAttribute('aria-hidden', 'true');
      return i;
    }

    function post(fd) {
      return fetch('messages.php', {
        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'Unexpected reply from the server.' }; });
      });
    }
    function showToast(text, ok) {
      if (!toast) return;
      toast.style.background = ok ? '#16a34a' : '';
      toast.textContent = text;
      toast.hidden = false;
      clearTimeout(toastTimer);
      toastTimer = setTimeout(function () { toast.hidden = true; }, 4000);
    }
    function field(fd, name, value) { fd.append(name, value); return fd; }
    function baseFd(action) {
      var fd = new FormData();
      fd.append('csrf_token', cfg.csrf);
      fd.append('action', action);
      fd.append('kind', cfg.kind);
      fd.append('group', String(cfg.group));
      fd.append('to', String(cfg.with));
      return fd;
    }

    /* ---------- new group dialog ---------- */
    (function () {
      var modal = $('msgModal');
      var openBtn = $('msgNewGroup');
      if (!modal || !openBtn) return;
      function close() { modal.hidden = true; }
      openBtn.addEventListener('click', function () {
        modal.hidden = false;
        var input = $('msgGroupName');
        if (input) input.focus();
      });
      $('msgModalClose').addEventListener('click', close);
      modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
      document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
    })();

    /* ---------- group rename ---------- */
    (function () {
      var btn = $('msgRenameBtn');
      if (!btn) return;
      btn.addEventListener('click', function () {
        var name = window.prompt('Group name', btn.getAttribute('data-name') || '');
        if (name === null) return;
        name = name.trim();
        if (name === '') return;
        $('msgRenameName').value = name;
        $('msgRenameForm').submit();
      });
    })();

    /* ---------- alerts: sound + desktop notification for new messages ---------- */
    var alertsOn = store.get('vs_msg_alerts') !== '0';
    var audioCtx = null;
    function initAudio() {
      try {
        if (!audioCtx) {
          var AC = window.AudioContext || window.webkitAudioContext;
          if (AC) audioCtx = new AC();
        }
        if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
      } catch (e) {}
    }
    document.addEventListener('click', initAudio);
    document.addEventListener('keydown', initAudio);
    function beep() {
      initAudio();
      if (!audioCtx) return;
      try {
        var o = audioCtx.createOscillator();
        var g = audioCtx.createGain();
        var t = audioCtx.currentTime;
        o.type = 'sine';
        o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(0.12, t + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t + 0.28);
        o.connect(g);
        g.connect(audioCtx.destination);
        o.start();
        o.stop(t + 0.3);
      } catch (e) {}
    }
    function paintAlertBtn() {
      if (!alertBtn) return;
      alertBtn.classList.toggle('off', !alertsOn);
      alertBtn.innerHTML = '';
      alertBtn.appendChild(icon(alertsOn ? 'bi-bell' : 'bi-bell-slash'));
      alertBtn.title = alertsOn ? 'Alerts are on. Click to turn them off.' : 'Alerts are off. Click to turn them on.';
    }
    if (alertBtn) {
      paintAlertBtn();
      alertBtn.addEventListener('click', function () {
        alertsOn = !alertsOn;
        store.set('vs_msg_alerts', alertsOn ? '1' : '0');
        paintAlertBtn();
        if (alertsOn) {
          beep();
          if ('Notification' in window && Notification.permission === 'default') {
            try { Notification.requestPermission(); } catch (e) {}
          }
        }
      });
    }
    function notifyNew(name, text) {
      if (!alertsOn) return;
      beep();
      if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
        try { new Notification(name, { body: text || 'New message' }); } catch (e) {}
      }
    }

    /* ---------- drafts: unsent text is kept per chat in this browser ---------- */
    function draftKey(k) { return 'vs_msg_draft:' + cfg.me + ':' + k; }
    function getDraft(k) { return k ? (store.get(draftKey(k)) || '') : ''; }
    function setDraft(k, v) {
      if (!k) return;
      if (v.trim() === '') store.del(draftKey(k)); else store.set(draftKey(k), v);
    }
    function applyDrafts() {
      var items = document.querySelectorAll('.msg-contact[data-key]');
      for (var i = 0; i < items.length; i++) {
        var key = items[i].getAttribute('data-key');
        var prev = items[i].querySelector('.msg-contact-preview');
        var text = key === cfg.key ? '' : getDraft(key);
        if (!prev || text === '') { items[i].classList.remove('has-draft'); continue; }
        var tag = document.createElement('span');
        tag.className = 'msg-draft-tag';
        tag.textContent = 'Draft: ';
        prev.textContent = '';
        prev.appendChild(tag);
        prev.appendChild(document.createTextNode(text.replace(/\s+/g, ' ').slice(0, 50)));
        items[i].classList.add('has-draft');
      }
    }
    window.addEventListener('storage', applyDrafts);

    /* ---------- chat list: search + live refresh ---------- */
    function applyFilter() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var items = document.querySelectorAll('#msgGroupList .msg-contact, #msgAdminList .msg-contact');
      for (var i = 0; i < items.length; i++) {
        items[i].hidden = q !== '' && (items[i].getAttribute('data-name') || '').indexOf(q) === -1;
      }
    }
    if (search) search.addEventListener('input', applyFilter);

    var lastGroups = null, lastAdmins = null, prevUnread = null;
    function refreshSidebar() {
      /* a hidden tab keeps polling, but only a visible one marks the open chat as read */
      var url = cfg.sidebarUrl + (document.hidden ? '' : '&view=1');
      return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (data) {
          if (!data) return;
          if (groupList && lastGroups !== data.groups) { groupList.innerHTML = data.groups; lastGroups = data.groups; }
          if (adminList && lastAdmins !== data.admins) { adminList.innerHTML = data.admins; lastAdmins = data.admins; }
          applyDrafts();
          applyFilter();
          document.title = (data.unread > 0 ? '(' + data.unread + ') ' : '') + baseTitle;

          var chats = data.chats || {};
          var keys = Object.keys(chats);
          if (prevUnread !== null) {
            keys.forEach(function (k) {
              var c = chats[k];
              if (c.u > (prevUnread[k] || 0) && !c.m && (document.hidden || k !== cfg.key)) notifyNew(c.n, c.p);
            });
          }
          prevUnread = {};
          keys.forEach(function (k) { prevUnread[k] = chats[k].u; });
        })
        .catch(function () {});
    }

    /* ---------- conversation ---------- */
    var refreshThread = function () { return Promise.resolve(); };

    function initConversation() {
      var sendBtn = $('msgSend');
      var emojiBtn = $('msgEmojiBtn');
      var emojiBox = $('msgEmoji');
      var attachBtn = $('msgAttachBtn');
      var fileInput = $('msgFile');
      var down = $('msgDown');
      var downN = $('msgDownN');
      var headSub = $('msgHeadSub');
      var bars = $('msgBars');
      var pending = 0, missed = 0, atBottom = true;
      var pendingFile = null, thumbUrl = '';
      var replyTo = null, editingId = null, stash = '';

      /* ----- scrolling ----- */
      function nearBottom() { return thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80; }
      function updateDown() {
        if (!down) return;
        var near = nearBottom();
        if (near) missed = 0;
        down.hidden = near;
        if (downN) downN.textContent = missed > 0 ? String(missed) : '';
      }
      function toBottom() { thread.scrollTop = thread.scrollHeight; atBottom = true; updateDown(); }
      function rowCount() { return thread.querySelectorAll('.msg-row').length; }
      thread.addEventListener('scroll', function () { atBottom = nearBottom(); updateDown(); closeMenu(); });
      thread.addEventListener('load', function () { if (atBottom) toBottom(); }, true);
      if (down) down.addEventListener('click', function () { missed = 0; toBottom(); });

      /* ----- header status: online / last seen / typing ----- */
      function applyHead(headers) {
        if (!headSub) return;
        var typing = '', status = '';
        try {
          typing = decodeURIComponent(headers.get('X-Typing') || '');
          status = decodeURIComponent(headers.get('X-Status') || '');
        } catch (e) {}
        if (typing) {
          headSub.textContent = typing;
          headSub.classList.add('typing');
        } else {
          headSub.textContent = (cfg.kind === 'dm' && status) ? status : (headSub.getAttribute('data-default') || '');
          headSub.classList.remove('typing');
        }
      }

      /* ----- live thread ----- */
      refreshThread = function () {
        if (!cfg.threadUrl) return Promise.resolve();
        return fetch(cfg.threadUrl, { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) {
            if (!r.ok) return null;
            applyHead(r.headers);
            return r.text();
          })
          .then(function (html) {
            if (html === null || pending > 0) return;
            var probe = document.createElement('div');
            probe.innerHTML = html;
            if (probe.innerHTML === thread.innerHTML) return;
            var wasNear = atBottom || nearBottom();
            var before = rowCount();
            thread.innerHTML = html;
            markEditing();
            applyFind(true);
            var added = rowCount() - before;
            if (wasNear) { toBottom(); }
            else { if (added > 0) missed += added; updateDown(); }
          })
          .catch(function () {});
      };

      /* start at the first unread message, or at the bottom */
      var divider = thread.querySelector('.msg-newdiv');
      if (divider) {
        thread.scrollTop = Math.max(0, divider.getBoundingClientRect().top - thread.getBoundingClientRect().top + thread.scrollTop - 70);
        atBottom = nearBottom();
        updateDown();
      } else {
        toBottom();
        window.addEventListener('load', toBottom);
      }

      /* ----- composer helpers ----- */
      function autosize() {
        body.style.height = 'auto';
        body.style.height = Math.min(body.scrollHeight + 2, 140) + 'px';
      }
      function syncSend() {
        sendBtn.disabled = body.value.trim() === '' && !(pendingFile && editingId === null);
      }
      function makeBar() {
        var b = document.createElement('div');
        b.className = 'msg-bar';
        b.hidden = true;
        bars.appendChild(b);
        return b;
      }
      var replyBar = makeBar(), editBar = makeBar(), fileBar = makeBar();
      function fillBar(bar, title, sub, onCancel, thumb, iconName) {
        bar.innerHTML = '';
        var left = document.createElement('div');
        left.className = 'msg-bar-left';
        if (thumb) {
          var img = document.createElement('img');
          img.className = 'msg-bar-thumb';
          img.src = thumb;
          img.alt = '';
          left.appendChild(img);
        } else if (iconName) {
          var ic = document.createElement('span');
          ic.className = 'msg-bar-ico';
          ic.appendChild(icon(iconName));
          left.appendChild(ic);
        }
        var copy = document.createElement('div');
        copy.className = 'msg-bar-copy';
        var b = document.createElement('b');
        b.textContent = title;
        copy.appendChild(b);
        if (sub) {
          var s = document.createElement('span');
          s.textContent = sub;
          copy.appendChild(s);
        }
        left.appendChild(copy);
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', onCancel);
        bar.appendChild(left);
        bar.appendChild(cancel);
        bar.hidden = false;
      }

      /* ----- reply ----- */
      function clearReply() { replyTo = null; replyBar.hidden = true; replyBar.innerHTML = ''; }
      function setReply(row) {
        var text = row.getAttribute('data-body') || (row.getAttribute('data-att') ? '📎 Attachment' : '');
        replyTo = { id: row.getAttribute('data-id') };
        fillBar(replyBar, 'Replying to ' + (row.classList.contains('me') ? 'yourself' : (row.getAttribute('data-who') || '')),
          text.replace(/\s+/g, ' ').slice(0, 90), clearReply, '', 'bi-reply-fill');
        body.focus();
      }

      /* ----- attachment ----- */
      function clearFile() {
        pendingFile = null;
        if (fileInput) fileInput.value = '';
        if (thumbUrl) { try { URL.revokeObjectURL(thumbUrl); } catch (e) {} thumbUrl = ''; }
        fileBar.hidden = true;
        fileBar.innerHTML = '';
        syncSend();
      }
      function setFile(f) {
        if (!f) return;
        if (editingId !== null) { showToast('Finish editing before attaching a file.'); return; }
        if (f.size > 10 * 1024 * 1024) { showToast('Files can be at most 10 MB.'); return; }
        clearFile();
        pendingFile = f;
        if (f.type && f.type.indexOf('image/') === 0) {
          try { thumbUrl = URL.createObjectURL(f); } catch (e) { thumbUrl = ''; }
        }
        var kb = f.size >= 1048576 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(f.size / 1024)) + ' KB';
        fillBar(fileBar, f.name, kb, clearFile, thumbUrl, 'bi-file-earmark-text');
        syncSend();
        body.focus();
      }
      if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () { if (fileInput.files && fileInput.files[0]) setFile(fileInput.files[0]); });
      }
      body.addEventListener('paste', function (event) {
        var files = event.clipboardData && event.clipboardData.files;
        if (files && files.length) { event.preventDefault(); setFile(files[0]); }
      });

      /* ----- edit ----- */
      function markEditing() {
        var old = thread.querySelector('.msg-row.editing');
        if (old) old.classList.remove('editing');
        if (editingId === null) return;
        var row = thread.querySelector('.msg-row[data-id="' + editingId + '"]');
        if (row) row.classList.add('editing'); else cancelEdit();
      }
      function cancelEdit() {
        if (editingId === null) return;
        editingId = null;
        body.value = stash;
        stash = '';
        editBar.hidden = true;
        editBar.innerHTML = '';
        markEditing();
        autosize();
        syncSend();
      }
      function startEdit(row) {
        if (editingId === null) stash = body.value;
        editingId = row.getAttribute('data-id');
        body.value = row.getAttribute('data-body') || '';
        fillBar(editBar, 'Editing message', '', cancelEdit, '', 'bi-pencil-fill');
        markEditing();
        autosize();
        syncSend();
        body.focus();
        body.selectionStart = body.selectionEnd = body.value.length;
      }
      function saveEdit() {
        var text = body.value.trim();
        if (text === '') return;
        var row = thread.querySelector('.msg-row[data-id="' + editingId + '"]');
        if (row && (row.getAttribute('data-body') || '').trim() === text) { cancelEdit(); return; }
        var fd = baseFd('edit_msg');
        fd.append('id', editingId);
        fd.append('body', text);
        sendBtn.disabled = true;
        function finish(res) {
          if (res && res.ok) {
            editingId = null;
            body.value = stash;
            stash = '';
            editBar.hidden = true;
            editBar.innerHTML = '';
            autosize();
            refreshThread().then(refreshSidebar);
          } else {
            showToast((res && res.error) ? res.error : 'You appear to be offline. Try again.');
          }
          syncSend();
        }
        post(fd).then(finish, function () { finish(null); });
      }

      /* ----- sending ----- */
      var lastTyping = 0;
      function pingTyping() {
        var now = Date.now();
        if (now - lastTyping < 2500) return;
        lastTyping = now;
        post(baseFd('typing')).catch(function () {});
      }

      function send() {
        if (editingId !== null) { saveEdit(); return; }
        var text = body.value.trim();
        if (text === '' && !pendingFile) return;
        var fd = new FormData(form);
        fd.set('body', text);
        if (pendingFile) fd.set('file', pendingFile, pendingFile.name);
        if (replyTo) fd.set('reply_to', replyTo.id);

        var empty = thread.querySelector('.msg-empty');
        if (empty) empty.remove();
        var row = document.createElement('div');
        row.className = 'msg-row me first last pending';
        var bubble = document.createElement('div');
        bubble.className = 'msg-bubble me';
        var txt = document.createElement('div');
        txt.className = 'msg-text';
        txt.style.whiteSpace = 'pre-wrap';
        txt.textContent = text !== '' ? text : '📎 ' + pendingFile.name;
        var meta = document.createElement('div');
        meta.className = 'msg-meta';
        meta.textContent = 'Sending…';
        bubble.appendChild(txt);
        bubble.appendChild(meta);
        row.appendChild(bubble);
        thread.appendChild(row);
        toBottom();

        var sentFile = pendingFile;
        body.value = '';
        setDraft(cfg.key, '');
        clearFile();
        clearReply();
        autosize();
        syncSend();
        pending++;

        function done(res) {
          pending--;
          if (res && res.ok) {
            refreshThread().then(function () { toBottom(); refreshSidebar(); });
            return;
          }
          row.remove();
          if (body.value === '') { body.value = text; setDraft(cfg.key, text); autosize(); }
          if (sentFile && !pendingFile) setFile(sentFile);
          syncSend();
          showToast((res && res.error) ? res.error : 'You appear to be offline. Try again.');
        }
        post(fd).then(function (res) { done(res); }, function () { done(null); });
        body.focus();
      }

      form.addEventListener('submit', function (event) { event.preventDefault(); send(); });

      var enterBox = $('msgEnterSend');
      var enterSends = store.get('vs_msg_enter') !== '0';
      function paintEnter() {
        if (!enterBox) return;
        enterBox.checked = enterSends;
        var label = enterBox.parentNode;
        if (label && label.lastChild && label.lastChild.nodeType === 3) {
          label.lastChild.textContent = enterSends
            ? ' Enter sends the message (Shift+Enter for a new line)'
            : ' Enter adds a new line (Ctrl+Enter sends)';
        }
      }
      if (enterBox) {
        paintEnter();
        enterBox.addEventListener('change', function () {
          enterSends = enterBox.checked;
          store.set('vs_msg_enter', enterSends ? '1' : '0');
          paintEnter();
        });
      }
      body.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
          if (editingId !== null) { event.preventDefault(); cancelEdit(); return; }
          if (replyTo) { clearReply(); return; }
        }
        if (event.key === 'Enter' && !event.isComposing) {
          var go = enterSends ? !event.shiftKey : (event.ctrlKey || event.metaKey);
          if (go) { event.preventDefault(); send(); }
        }
      });
      body.addEventListener('input', function () {
        autosize();
        syncSend();
        if (editingId === null) {
          setDraft(cfg.key, body.value);
          if (body.value.trim() !== '') pingTyping();
        }
      });
      body.value = getDraft(cfg.key);
      autosize();
      syncSend();
      body.focus();

      /* ----- emoji picker ----- */
      if (emojiBtn && emojiBox) {
        var emojis = ['😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎', '🤔', '😅', '😭', '😢', '😡', '😮', '🥳', '😴',
                      '👍', '👎', '👏', '🙏', '💪', '🙌', '👋', '🤝', '❤️', '💔', '🔥', '🎉', '✅', '❌', '👀', '💯'];
        emojis.forEach(function (e) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = e;
          b.addEventListener('click', function () {
            var s = body.selectionStart || 0, t = body.selectionEnd || 0;
            body.value = body.value.slice(0, s) + e + body.value.slice(t);
            body.selectionStart = body.selectionEnd = s + e.length;
            body.focus();
            autosize();
            syncSend();
            if (editingId === null) setDraft(cfg.key, body.value);
          });
          emojiBox.appendChild(b);
        });
        emojiBtn.addEventListener('click', function (event) {
          event.preventDefault();
          event.stopPropagation();
          emojiBox.hidden = !emojiBox.hidden;
          emojiBtn.setAttribute('aria-expanded', emojiBox.hidden ? 'false' : 'true');
          if (!emojiBox.hidden) body.focus();
        });
        document.addEventListener('click', function (event) {
          if (!emojiBox.hidden && !emojiBox.contains(event.target)) emojiBox.hidden = true;
        });
        document.addEventListener('keydown', function (event) { if (event.key === 'Escape') emojiBox.hidden = true; });
      }

      /* ----- message menu: react, reply, forward, copy, edit, delete ----- */
      var menu = document.createElement('div');
      menu.className = 'msg-menu';
      menu.setAttribute('role', 'menu');
      menu.hidden = true;
      document.body.appendChild(menu);
      var menuRow = null;
      function closeMenu() { menu.hidden = true; menuRow = null; }

      function openMenu(btn) {
        var row = btn.closest('.msg-row');
        if (!row) return;
        if (!menu.hidden && menuRow === row) { closeMenu(); return; }
        menuRow = row;
        var mine = row.classList.contains('me');
        var marker = row.getAttribute('data-marker') === '1';
        var text = row.getAttribute('data-body') || '';
        menu.innerHTML = '';

        var mineChip = row.querySelector('.msg-chip.mine');
        var myReact = mineChip ? norm(mineChip.getAttribute('data-emoji')) : '';
        var rr = document.createElement('div');
        rr.className = 'msg-menu-react';
        QUICK.forEach(function (e) {
          var b = document.createElement('button');
          b.type = 'button';
          b.textContent = e;
          b.setAttribute('data-react', e);
          if (norm(e) === myReact) b.classList.add('mine');
          rr.appendChild(b);
        });
        menu.appendChild(rr);

        function item(label, act, iconName, danger) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'msg-menu-item' + (danger ? ' danger' : '');
          b.setAttribute('data-act', act);
          b.appendChild(icon(iconName));
          var span = document.createElement('span');
          span.textContent = label;
          b.appendChild(span);
          menu.appendChild(b);
        }
        item('Reply', 'reply', 'bi-reply');
        item('Forward', 'forward', 'bi-forward');
        if (text !== '') item('Copy text', 'copy', 'bi-clipboard');
        if (mine && !marker) item('Edit', 'edit', 'bi-pencil');
        if (mine) item('Delete', 'delete', 'bi-trash', true);

        menu.hidden = false;
        var r = btn.getBoundingClientRect();
        var mw = menu.offsetWidth, mh = menu.offsetHeight;
        var left = Math.min(Math.max(8, r.left), window.innerWidth - mw - 8);
        var top = r.bottom + 4;
        if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
        menu.style.left = left + 'px';
        menu.style.top = top + 'px';
      }

      function react(id, emoji) {
        var fd = baseFd('react_msg');
        fd.append('id', id);
        fd.append('emoji', emoji);
        post(fd).then(function (res) {
          if (res && res.ok) refreshThread();
          else showToast((res && res.error) || 'The reaction could not be saved.');
        }, function () { showToast('You appear to be offline. Try again.'); });
      }

      function copyText(text) {
        function fallback() {
          var ta = document.createElement('textarea');
          ta.value = text;
          ta.style.position = 'fixed';
          ta.style.opacity = '0';
          document.body.appendChild(ta);
          ta.select();
          try { document.execCommand('copy'); showToast('Copied', true); } catch (e) { showToast('Could not copy.'); }
          document.body.removeChild(ta);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(text).then(function () { showToast('Copied', true); }, fallback);
        } else {
          fallback();
        }
      }

      function deleteMessage(row) {
        if (!window.confirm('Delete this message for everyone?')) return;
        var fd = baseFd('delete_msg');
        fd.append('id', row.getAttribute('data-id'));
        post(fd).then(function (res) {
          if (res && res.ok) { refreshThread().then(refreshSidebar); }
          else { showToast((res && res.error) || 'The message could not be deleted.'); }
        }, function () { showToast('You appear to be offline. Try again.'); });
      }

      function openForward(row) {
        var id = row.getAttribute('data-id');
        var modal = document.createElement('div');
        modal.className = 'msg-modal';
        var box = document.createElement('div');
        box.className = 'msg-modal-box';
        var h = document.createElement('h2');
        h.textContent = 'Forward to…';
        var list = document.createElement('div');
        list.className = 'msg-fwd-list';
        function close() { document.removeEventListener('keydown', onKey); modal.remove(); }
        function onKey(e) { if (e.key === 'Escape') close(); }
        var chats = document.querySelectorAll('.msg-contact[data-key]');
        for (var i = 0; i < chats.length; i++) {
          (function (a) {
            var nameEl = a.querySelector('.msg-cname');
            var key = a.getAttribute('data-key');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'msg-fwd-item';
            b.appendChild(icon(key.charAt(0) === 'g' ? 'bi-people-fill' : 'bi-person-fill'));
            var label = document.createElement('span');
            label.textContent = nameEl ? nameEl.textContent : key;
            b.appendChild(label);
            b.addEventListener('click', function () {
              close();
              var fd = baseFd('forward_msg');
              fd.append('id', id);
              fd.append('target', key);
              post(fd).then(function (res) {
                if (res && res.ok) {
                  showToast('Message forwarded', true);
                  refreshSidebar();
                  if (key === cfg.key) refreshThread();
                } else {
                  showToast((res && res.error) || 'The message could not be forwarded.');
                }
              }, function () { showToast('You appear to be offline. Try again.'); });
            });
            list.appendChild(b);
          })(chats[i]);
        }
        var actions = document.createElement('div');
        actions.className = 'msg-modal-actions';
        var cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'ghost';
        cancel.textContent = 'Cancel';
        cancel.addEventListener('click', close);
        actions.appendChild(cancel);
        box.appendChild(h);
        box.appendChild(list);
        box.appendChild(actions);
        modal.appendChild(box);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', onKey);
        document.body.appendChild(modal);
      }

      function jumpTo(id) {
        var target = thread.querySelector('.msg-row[data-id="' + id + '"]');
        if (!target) { showToast('That message is further back than the loaded history.'); return; }
        target.scrollIntoView({ block: 'center' });
        target.classList.remove('flash');
        void target.offsetWidth;
        target.classList.add('flash');
      }

      menu.addEventListener('click', function (event) {
        var row = menuRow;
        if (!row || !event.target.closest) return;
        var rb = event.target.closest('[data-react]');
        var ib = event.target.closest('[data-act]');
        closeMenu();
        if (rb) { react(row.getAttribute('data-id'), rb.getAttribute('data-react')); return; }
        if (!ib) return;
        var act = ib.getAttribute('data-act');
        if (act === 'reply') setReply(row);
        else if (act === 'forward') openForward(row);
        else if (act === 'copy') copyText(row.getAttribute('data-body') || '');
        else if (act === 'edit') startEdit(row);
        else if (act === 'delete') deleteMessage(row);
      });
      document.addEventListener('click', function (event) {
        if (menu.hidden) return;
        if (menu.contains(event.target) || (event.target.closest && event.target.closest('.msg-more'))) return;
        closeMenu();
      });
      document.addEventListener('keydown', function (event) { if (event.key === 'Escape') closeMenu(); });
      window.addEventListener('resize', closeMenu);

      thread.addEventListener('click', function (event) {
        var t = event.target;
        if (!t.closest) return;
        var more = t.closest('.msg-more');
        if (more) { event.preventDefault(); openMenu(more); return; }
        var chip = t.closest('.msg-chip');
        if (chip) {
          var chipRow = chip.closest('.msg-row');
          if (chipRow) react(chipRow.getAttribute('data-id'), chip.getAttribute('data-emoji'));
          return;
        }
        var quote = t.closest('.msg-quote[data-ref]');
        if (quote) jumpTo(quote.getAttribute('data-ref'));
      });

      /* ----- search inside this chat ----- */
      var findBar = $('msgFindBar'), findInput = $('msgFind'), findCount = $('msgFindCount'), findBtn = $('msgFindBtn');
      var hits = [], hitIdx = -1;
      function paintFind() {
        findCount.textContent = hits.length ? (hitIdx + 1) + ' of ' + hits.length : (findInput.value.trim() ? 'No results' : '');
      }
      function applyFind(keep) {
        if (!findInput) return;
        var q = findInput.value.trim().toLowerCase();
        var rows = thread.querySelectorAll('.msg-row');
        hits = [];
        for (var i = 0; i < rows.length; i++) {
          rows[i].classList.remove('hit', 'current');
          if (q !== '' && (rows[i].getAttribute('data-body') || '').toLowerCase().indexOf(q) !== -1) {
            rows[i].classList.add('hit');
            hits.push(rows[i]);
          }
        }
        if (!hits.length) { hitIdx = -1; paintFind(); return; }
        if (!keep || hitIdx < 0 || hitIdx >= hits.length) hitIdx = hits.length - 1;
        hits[hitIdx].classList.add('current');
        paintFind();
        if (!keep) hits[hitIdx].scrollIntoView({ block: 'center' });
      }
      function stepFind(dir) {
        if (!hits.length) return;
        hits[hitIdx].classList.remove('current');
        hitIdx = (hitIdx + dir + hits.length) % hits.length;
        hits[hitIdx].classList.add('current');
        paintFind();
        hits[hitIdx].scrollIntoView({ block: 'center' });
      }
      if (findBtn && findBar && findInput) {
        findBtn.addEventListener('click', function () {
          findBar.hidden = !findBar.hidden;
          if (!findBar.hidden) findInput.focus(); else { findInput.value = ''; applyFind(false); }
        });
        findInput.addEventListener('input', function () { hitIdx = -1; applyFind(false); });
        findInput.addEventListener('keydown', function (event) {
          if (event.key === 'Enter') { event.preventDefault(); stepFind(event.shiftKey ? -1 : 1); }
          if (event.key === 'Escape') { findBar.hidden = true; findInput.value = ''; applyFind(false); }
        });
        $('msgFindPrev').addEventListener('click', function () { stepFind(-1); });
        $('msgFindNext').addEventListener('click', function () { stepFind(1); });
        $('msgFindClose').addEventListener('click', function () { findBar.hidden = true; findInput.value = ''; applyFind(false); });
      }

      /* ----- pin and mute this chat ----- */
      var PREF_ICONS = {
        msgPinBtn: { off: 'bi-pin-angle', on: 'bi-pin-angle-fill', offTitle: 'Pin chat to the top', onTitle: 'Unpin chat' },
        msgMuteBtn: { off: 'bi-bell-slash', on: 'bi-bell-slash-fill', offTitle: 'Mute chat', onTitle: 'Unmute chat' }
      };
      ['msgPinBtn', 'msgMuteBtn'].forEach(function (id) {
        var b = $(id);
        if (!b) return;
        b.addEventListener('click', function () {
          var next = b.getAttribute('data-value') === '1' ? 0 : 1;
          var fd = new FormData();
          fd.append('csrf_token', cfg.csrf);
          fd.append('action', 'chat_pref');
          fd.append('key', cfg.key);
          fd.append('field', b.getAttribute('data-field'));
          fd.append('value', String(next));
          post(fd).then(function (res) {
            if (res && res.ok) {
              var p = PREF_ICONS[id];
              b.setAttribute('data-value', String(next));
              b.classList.toggle('on', next === 1);
              b.title = next ? p.onTitle : p.offTitle;
              b.innerHTML = '';
              b.appendChild(icon(next ? p.on : p.off));
              refreshSidebar();
            } else {
              showToast((res && res.error) || 'That setting could not be saved.');
            }
          }, function () { showToast('You appear to be offline. Try again.'); });
        });
      });

      setInterval(function () { if (!document.hidden) refreshThread(); }, 2500);
      document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshThread(); });
    }

    if (thread && form && body) initConversation();

    /* ---------- keep the chat list fresh ---------- */
    setInterval(refreshSidebar, 4000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) refreshSidebar(); });
    applyDrafts();
    refreshSidebar();
  })();
</script>
<?php endif; ?>
</body>
</html>