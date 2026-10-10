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

vs_admin_messages_ensure_table($conn);

/* ---- group chat tables ---- */
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

/* ---- other admins ---- */
$admins = [];
$res = $conn->query("SELECT id, username FROM users WHERE LOWER(role) = 'admin' ORDER BY username");
while ($row = $res->fetch_assoc()) {
    if ((int)$row['id'] !== $meId) {
        $admins[(int)$row['id']] = (string)$row['username'];
    }
}
$res->free();

/* ---- group actions: create, send, add people, leave ---- */
$groupActs = ['create_group', 'group_send', 'group_add', 'group_leave'];
$act = (string)($_POST['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($act, $groupActs, true)) {
    $gid = (int)($_POST['group'] ?? 0);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        if ($meId <= 0) {
            throw new RuntimeException('Your admin account could not be identified. Sign in again.');
        }

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

        $group = vs_group_member($conn, $gid, $meId);
        if (!$group) {
            throw new RuntimeException('You are not in that group.');
        }

        if ($act === 'group_send') {
            $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
            if ($body === '') {
                throw new RuntimeException('Type a message first.');
            }
            if (mb_strlen($body) > 2000) {
                throw new RuntimeException('Messages can be at most 2000 characters.');
            }
            $ins = $conn->prepare('INSERT INTO admin_group_messages (group_id, sender_id, sender_name, body, created_at) VALUES (?, ?, ?, ?, NOW())');
            $ins->bind_param('iiss', $gid, $meId, $meName, $body);
            $ins->execute();
            $msgId = (int)$conn->insert_id;
            $ins->close();

            $read = $conn->prepare('UPDATE admin_group_members SET last_read_id = GREATEST(last_read_id, ?) WHERE group_id = ? AND user_id = ?');
            $read->bind_param('iii', $msgId, $gid, $meId);
            $read->execute();
            $read->close();
            header('Location: messages.php?group=' . $gid);
            exit;
        }

        if ($act === 'group_add') {
            if ((int)$group['created_by'] !== $meId) {
                throw new RuntimeException('Only the person who made the group can add people.');
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

        if ($act === 'group_leave') {
            $del = $conn->prepare('DELETE FROM admin_group_members WHERE group_id = ? AND user_id = ?');
            $del->bind_param('ii', $gid, $meId);
            $del->execute();
            $del->close();

            $left = count(vs_group_members($conn, $gid));
            if ($left === 0) {
                $d1 = $conn->prepare('DELETE FROM admin_group_messages WHERE group_id = ?');
                $d1->bind_param('i', $gid);
                $d1->execute();
                $d1->close();
                $d2 = $conn->prepare('DELETE FROM admin_groups WHERE id = ?');
                $d2->bind_param('i', $gid);
                $d2->execute();
                $d2->close();
            } elseif ((int)$group['created_by'] === $meId) {
                $up = $conn->prepare('UPDATE admin_groups SET created_by = (SELECT MIN(user_id) FROM admin_group_members WHERE group_id = ?) WHERE id = ?');
                $up->bind_param('ii', $gid, $gid);
                $up->execute();
                $up->close();
            }
            header('Location: messages.php');
            exit;
        }
    } catch (Throwable $error) {
        $errorMsg = $error instanceof mysqli_sql_exception ? 'Something went wrong. Please try again.' : $error->getMessage();
        if ($gid > 0) {
            $_GET['group'] = $gid;
        }
    }
}

/* ---- send a direct message ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($act, $groupActs, true)) {
    $toId = (int)($_POST['to'] ?? 0);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        if ($meId <= 0) {
            throw new RuntimeException('Your admin account could not be identified. Sign in again.');
        }
        if (!isset($admins[$toId])) {
            throw new RuntimeException('Choose another admin to message.');
        }
        $body = trim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? '')));
        if ($body === '') {
            throw new RuntimeException('Type a message first.');
        }
        if (mb_strlen($body) > 2000) {
            throw new RuntimeException('Messages can be at most 2000 characters.');
        }
        if (!vs_admin_send_message($conn, $meId, $meName, $toId, $body)) {
            throw new RuntimeException('The message could not be sent. Please try again.');
        }
        header('Location: messages.php?with=' . $toId);
        exit;
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
        $_GET['with'] = $toId;
    }
}

/* ---- open a group or a conversation ---- */
$groupId = (int)($_GET['group'] ?? 0);
$group = $groupId > 0 ? vs_group_member($conn, $groupId, $meId) : null;
if (!$group) {
    $groupId = 0;
}

$withId = (int)($_GET['with'] ?? 0);
if ($groupId > 0 || !isset($admins[$withId])) {
    $withId = 0;
}

$groupMembers = [];
if ($groupId > 0) {
    $markG = $conn->prepare(
        'UPDATE admin_group_members
         SET last_read_id = (SELECT COALESCE(MAX(id), 0) FROM admin_group_messages WHERE group_id = ?)
         WHERE group_id = ? AND user_id = ?'
    );
    $markG->bind_param('iii', $groupId, $groupId, $meId);
    $markG->execute();
    $markG->close();
    $groupMembers = vs_group_members($conn, $groupId);
}

if ($withId > 0 && $meId > 0) {
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

function vs_msg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function vs_msg_time(?string $when): string
{
    $ts = $when ? strtotime($when) : false;
    if (!$ts) {
        return '';
    }
    return date(date('Y-m-d') === date('Y-m-d', $ts) ? 'g:i A' : 'M j, g:i A', $ts);
}

function vs_msg_thread_html(mysqli $conn, int $meId, int $withId, string $meName, string $withName): string
{
    $stmt = $conn->prepare(
        'SELECT id, sender_id, body, created_at FROM (
            SELECT id, sender_id, body, created_at FROM admin_messages
            WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
            ORDER BY id DESC LIMIT 200
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('iiii', $meId, $withId, $withId, $meId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return '<div class="msg-empty">No messages yet. Say hello below.</div>';
    }
    $html = '';
    foreach ($rows as $row) {
        $mine = (int)$row['sender_id'] === $meId;
        $html .= '<div class="msg-row ' . ($mine ? 'me' : 'them') . '">'
            . avatar_html($mine ? $meName : $withName, 32)
            . '<div class="msg-bubble ' . ($mine ? 'me' : 'them') . '">'
            . '<div class="msg-text">' . nl2br(vs_msg_h($row['body'])) . '</div>'
            . '<div class="msg-time">' . vs_msg_h(vs_msg_time((string)$row['created_at'])) . '</div>'
            . '</div></div>';
    }
    return $html;
}

function vs_msg_group_thread_html(mysqli $conn, int $meId, int $groupId, string $meName): string
{
    $stmt = $conn->prepare(
        'SELECT id, sender_id, sender_name, body, created_at FROM (
            SELECT id, sender_id, sender_name, body, created_at FROM admin_group_messages
            WHERE group_id = ? ORDER BY id DESC LIMIT 200
         ) recent ORDER BY id ASC'
    );
    $stmt->bind_param('i', $groupId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$rows) {
        return '<div class="msg-empty">No messages yet. Start the conversation below.</div>';
    }
    $html = '';
    foreach ($rows as $row) {
        $mine = (int)$row['sender_id'] === $meId;
        $who = $mine ? $meName : (string)$row['sender_name'];
        $html .= '<div class="msg-row ' . ($mine ? 'me' : 'them') . '">'
            . avatar_html($who, 32)
            . '<div class="msg-bubble ' . ($mine ? 'me' : 'them') . '">'
            . ($mine ? '' : '<div class="msg-sender">' . vs_msg_h($who) . '</div>')
            . '<div class="msg-text">' . nl2br(vs_msg_h($row['body'])) . '</div>'
            . '<div class="msg-time">' . vs_msg_h(vs_msg_time((string)$row['created_at'])) . '</div>'
            . '</div></div>';
    }
    return $html;
}

/* ---- live refresh endpoint (polled by the page) ---- */
if (isset($_GET['partial'])) {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if ($groupId > 0) {
        echo vs_msg_group_thread_html($conn, $meId, $groupId, $meName);
    } else {
        echo $withId > 0 ? vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId]) : '';
    }
    exit;
}

/* ---- contact list (unread count + last message) ---- */
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
    'SELECT id, sender_id, body, created_at FROM admin_messages
     WHERE (sender_id = ? AND recipient_id = ?) OR (sender_id = ? AND recipient_id = ?)
     ORDER BY id DESC LIMIT 1'
);
$contacts = [];
foreach ($admins as $id => $name) {
    $lastStmt->bind_param('iiii', $meId, $id, $id, $meId);
    $lastStmt->execute();
    $last = $lastStmt->get_result()->fetch_assoc();
    $contacts[] = [
        'id' => $id,
        'name' => $name,
        'unread' => $unreadBy[$id] ?? 0,
        'last_id' => (int)($last['id'] ?? 0),
        'preview' => $last ? (((int)$last['sender_id'] === $meId ? 'You: ' : '') . mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)$last['body'])), 0, 60)) : 'No messages yet',
        'time' => $last ? vs_msg_time((string)$last['created_at']) : '',
    ];
}
usort($contacts, function ($a, $b) {
    return $b['last_id'] <=> $a['last_id'] ?: strcasecmp($a['name'], $b['name']);
});

/* ---- group list (unread count + last message) ---- */
$groupList = [];
if ($meId > 0) {
    $gStmt = $conn->prepare(
        'SELECT g.id, g.name, m.last_read_id FROM admin_groups g
         JOIN admin_group_members m ON m.group_id = g.id WHERE m.user_id = ?'
    );
    $gStmt->bind_param('i', $meId);
    $gStmt->execute();
    $gRows = $gStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $gStmt->close();

    $gUnread = $conn->prepare('SELECT COUNT(*) FROM admin_group_messages WHERE group_id = ? AND id > ? AND sender_id <> ?');
    $gLast = $conn->prepare('SELECT id, sender_id, sender_name, body, created_at FROM admin_group_messages WHERE group_id = ? ORDER BY id DESC LIMIT 1');
    foreach ($gRows as $g) {
        $gid2 = (int)$g['id'];
        $readId = (int)$g['last_read_id'];
        $gUnread->bind_param('iii', $gid2, $readId, $meId);
        $gUnread->execute();
        $gUnread->bind_result($unreadN);
        $gUnread->fetch();
        $gUnread->free_result();

        $gLast->bind_param('i', $gid2);
        $gLast->execute();
        $lastG = $gLast->get_result()->fetch_assoc();

        $snippet = $lastG ? mb_substr(trim((string)preg_replace('/\s+/', ' ', (string)$lastG['body'])), 0, 50) : '';
        $groupList[] = [
            'id' => $gid2,
            'name' => (string)$g['name'],
            'unread' => (int)$unreadN,
            'last_id' => (int)($lastG['id'] ?? 0),
            'preview' => $lastG
                ? (((int)$lastG['sender_id'] === $meId ? 'You' : (string)$lastG['sender_name']) . ': ' . $snippet)
                : 'No messages yet',
            'time' => $lastG ? vs_msg_time((string)$lastG['created_at']) : '',
        ];
    }
    $gUnread->close();
    $gLast->close();
    usort($groupList, function ($a, $b) {
        return $b['last_id'] <=> $a['last_id'] ?: strcasecmp($a['name'], $b['name']);
    });
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

$pollUrl = $groupId > 0
    ? 'messages.php?group=' . $groupId . '&partial=1'
    : ($withId > 0 ? 'messages.php?with=' . $withId . '&partial=1' : '');

require_once __DIR__ . '/header.php';
?>

<style>
  .msg-page h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
  .msg-page .msg-sub { color: var(--admin-muted); margin: 0 0 20px; }
  .msg-shell { display: grid; grid-template-columns: 300px 1fr; gap: 16px; height: calc(100vh - 230px); min-height: 460px; }
  .msg-side, .msg-main {
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 16px; overflow: hidden;
    display: flex; flex-direction: column; min-height: 0;
  }
  .msg-side-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 14px 18px; font-weight: 700; border-bottom: 1px solid var(--admin-border); }
  .msg-new {
    border: 0; border-radius: 999px; padding: 5px 12px; background: var(--admin-primary); color: #fff;
    font-size: 0.78rem; font-weight: 700; cursor: pointer;
  }
  .msg-new:hover { background: var(--admin-primary-dark); }
  .msg-none { padding: 14px 18px; font-size: 0.85rem; color: var(--admin-muted); border-bottom: 1px solid var(--admin-border); }
  .msg-contacts { overflow-y: auto; flex: 1; }
  .msg-contact {
    display: flex; align-items: center; gap: 12px; padding: 12px 18px; color: var(--admin-text); text-decoration: none;
    border-bottom: 1px solid var(--admin-border);
  }
  .msg-contact:hover { background: rgba(127, 127, 127, 0.1); color: var(--admin-text); }
  .msg-contact.active { background: rgba(124, 58, 237, 0.12); }
  .msg-contact-copy { min-width: 0; flex: 1; }
  .msg-contact-name { display: flex; justify-content: space-between; gap: 8px; font-weight: 600; }
  .msg-contact-time { font-weight: 400; font-size: 0.75rem; color: var(--admin-muted); white-space: nowrap; }
  .msg-contact-preview { font-size: 0.85rem; color: var(--admin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .msg-unread { background: #dc2626; color: #fff; border-radius: 999px; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; }
  .msg-gavatar {
    flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    background: var(--admin-primary); color: #fff; font-weight: 700;
  }
  .msg-gavatar.sm { flex-basis: 36px; width: 36px; height: 36px; }
  .msg-main-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid var(--admin-border); font-weight: 700; flex-wrap: wrap; }
  .msg-head-copy { min-width: 0; flex: 1; }
  .msg-head-members { display: block; font-weight: 400; font-size: 0.8rem; color: var(--admin-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .msg-head-actions { display: flex; gap: 8px; align-items: flex-start; }
  .msg-small-btn {
    border: 1px solid var(--admin-border); border-radius: 999px; padding: 5px 12px; background: transparent;
    color: var(--admin-text); font-size: 0.78rem; font-weight: 600; cursor: pointer; list-style: none;
  }
  .msg-small-btn:hover { background: rgba(127, 127, 127, 0.12); }
  .msg-small-btn.danger { color: #dc2626; }
  .msg-add { position: relative; }
  .msg-add summary::-webkit-details-marker { display: none; }
  .msg-add-box {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; width: 240px; padding: 12px;
    background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 12px; box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
  }
  .msg-pick { max-height: 200px; overflow-y: auto; margin: 8px 0 12px; display: flex; flex-direction: column; gap: 6px; }
  .msg-pick label { display: flex; align-items: center; gap: 8px; font-weight: 400; cursor: pointer; }
  .msg-thread { flex: 1; overflow-y: auto; padding: 18px; display: flex; flex-direction: column; gap: 8px; min-height: 0; }
  .msg-row { display: flex; align-items: flex-end; gap: 8px; max-width: 85%; }
  .msg-row.me { align-self: flex-end; flex-direction: row-reverse; }
  .msg-row.them { align-self: flex-start; }
  .msg-bubble { min-width: 0; padding: 9px 14px; border-radius: 16px; overflow-wrap: anywhere; }
  .msg-bubble.me { background: var(--admin-primary); color: #fff; border-bottom-right-radius: 4px; }
  .msg-bubble.them { background: var(--admin-bg); border: 1px solid var(--admin-border); color: var(--admin-text); border-bottom-left-radius: 4px; }
  .msg-sender { font-size: 0.75rem; font-weight: 700; color: var(--admin-primary); margin-bottom: 2px; }
  .msg-time { font-size: 0.7rem; opacity: 0.7; margin-top: 3px; text-align: right; }
  .msg-empty { margin: auto; color: var(--admin-muted); text-align: center; padding: 24px; }
  .msg-form { display: flex; gap: 10px; padding: 12px; border-top: 1px solid var(--admin-border); }
  .msg-form textarea {
    flex: 1; resize: none; min-height: 46px; max-height: 120px; padding: 11px 14px; border-radius: 12px;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none;
  }
  .msg-form textarea:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .msg-form button {
    border: 0; border-radius: 12px; padding: 0 22px; background: var(--admin-primary); color: #fff; font-weight: 700; cursor: pointer;
  }
  .msg-form button:hover { background: var(--admin-primary-dark); }
  .msg-placeholder { margin: auto; text-align: center; color: var(--admin-muted); padding: 24px; }
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
  @media (max-width: 860px) {
    .msg-shell { grid-template-columns: 1fr; height: auto; }
    .msg-main { min-height: 420px; }
    .msg-contacts { max-height: 300px; }
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
      <div class="msg-shell">
        <aside class="msg-side" aria-label="Chats">
          <div class="msg-contacts">
            <div class="msg-side-title">
              <span>Groups</span>
              <button type="button" class="msg-new" id="msgNewGroup">+ New group</button>
            </div>
            <?php if (!$groupList): ?>
              <div class="msg-none">No groups yet. Create one to chat with several admins at once.</div>
            <?php endif; ?>
            <?php foreach ($groupList as $g): ?>
              <a class="msg-contact<?php echo $g['id'] === $groupId ? ' active' : ''; ?>" href="messages.php?group=<?php echo (int)$g['id']; ?>">
                <span class="msg-gavatar" aria-hidden="true"><?php echo vs_msg_h(mb_strtoupper(mb_substr($g['name'], 0, 1))); ?></span>
                <span class="msg-contact-copy">
                  <span class="msg-contact-name"><span><?php echo vs_msg_h($g['name']); ?></span><span class="msg-contact-time"><?php echo vs_msg_h($g['time']); ?></span></span>
                  <span class="msg-contact-preview d-block"><?php echo vs_msg_h($g['preview']); ?></span>
                </span>
                <?php if ($g['unread'] > 0): ?><span class="msg-unread"><?php echo (int)$g['unread']; ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>

            <div class="msg-side-title"><span>Admins</span></div>
            <?php foreach ($contacts as $contact): ?>
              <a class="msg-contact<?php echo $contact['id'] === $withId ? ' active' : ''; ?>" href="messages.php?with=<?php echo (int)$contact['id']; ?>">
                <?php echo avatar_html($contact['name'], 40); ?>
                <span class="msg-contact-copy">
                  <span class="msg-contact-name"><span><?php echo vs_msg_h($contact['name']); ?></span><span class="msg-contact-time"><?php echo vs_msg_h($contact['time']); ?></span></span>
                  <span class="msg-contact-preview d-block"><?php echo vs_msg_h($contact['preview']); ?></span>
                </span>
                <?php if ($contact['unread'] > 0): ?><span class="msg-unread"><?php echo (int)$contact['unread']; ?></span><?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        </aside>

        <section class="msg-main" aria-label="Conversation">
          <?php if ($groupId > 0): ?>
            <div class="msg-main-head">
              <span class="msg-gavatar sm" aria-hidden="true"><?php echo vs_msg_h(mb_strtoupper(mb_substr((string)$group['name'], 0, 1))); ?></span>
              <span class="msg-head-copy">
                <?php echo vs_msg_h($group['name']); ?>
                <span class="msg-head-members"><?php echo count($groupMembers); ?> members: <?php echo vs_msg_h(implode(', ', $memberLine)); ?></span>
              </span>
              <span class="msg-head-actions">
                <?php if ($isGroupOwner && $addCandidates): ?>
                  <details class="msg-add">
                    <summary class="msg-small-btn">Add people</summary>
                    <form method="post" class="msg-add-box">
                      <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                      <input type="hidden" name="action" value="group_add">
                      <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                      <div class="msg-pick">
                        <?php foreach ($addCandidates as $aid => $aname): ?>
                          <label><input type="checkbox" name="members[]" value="<?php echo (int)$aid; ?>"> <?php echo vs_msg_h($aname); ?></label>
                        <?php endforeach; ?>
                      </div>
                      <button type="submit" class="msg-small-btn">Add</button>
                    </form>
                  </details>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Leave this group?');">
                  <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
                  <input type="hidden" name="action" value="group_leave">
                  <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
                  <button type="submit" class="msg-small-btn danger">Leave group</button>
                </form>
              </span>
            </div>
            <div class="msg-thread" id="msgThread"><?php echo vs_msg_group_thread_html($conn, $meId, $groupId, $meName); ?></div>
            <form method="post" class="msg-form" id="msgForm" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
              <input type="hidden" name="action" value="group_send">
              <input type="hidden" name="group" value="<?php echo (int)$groupId; ?>">
              <textarea name="body" id="msgBody" maxlength="2000" placeholder="Message the group... (Enter to send, Shift+Enter for a new line)" required></textarea>
              <button type="submit">Send</button>
            </form>
          <?php elseif ($withId > 0): ?>
            <div class="msg-main-head">
              <?php echo avatar_html($admins[$withId], 36); ?>
              <span><?php echo vs_msg_h($admins[$withId]); ?></span>
            </div>
            <div class="msg-thread" id="msgThread"><?php echo vs_msg_thread_html($conn, $meId, $withId, $meName, $admins[$withId]); ?></div>
            <form method="post" class="msg-form" id="msgForm" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo vs_msg_h($csrf); ?>">
              <input type="hidden" name="to" value="<?php echo (int)$withId; ?>">
              <textarea name="body" id="msgBody" maxlength="2000" placeholder="Write a message... (Enter to send, Shift+Enter for a new line)" required></textarea>
              <button type="submit">Send</button>
            </form>
          <?php else: ?>
            <div class="msg-placeholder">Choose an admin or a group on the left to start a conversation.</div>
          <?php endif; ?>
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
    var modal = document.getElementById('msgModal');
    var openBtn = document.getElementById('msgNewGroup');
    if (!modal || !openBtn) return;
    function close() { modal.hidden = true; }
    openBtn.addEventListener('click', function () {
      modal.hidden = false;
      var input = document.getElementById('msgGroupName');
      if (input) input.focus();
    });
    document.getElementById('msgModalClose').addEventListener('click', close);
    modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
  })();
</script>
<?php endif; ?>
<?php if ($pollUrl !== ''): ?>
<script>
  (function () {
    var thread = document.getElementById('msgThread');
    var body = document.getElementById('msgBody');
    var form = document.getElementById('msgForm');
    if (!thread) return;

    function toBottom() { thread.scrollTop = thread.scrollHeight; }
    toBottom();

    if (body && form) {
      body.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !event.shiftKey) {
          event.preventDefault();
          if (body.value.trim() !== '') form.submit();
        }
      });
    }

    var url = <?php echo json_encode($pollUrl); ?>;
    setInterval(function () {
      if (document.hidden) return;
      fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) { return response.ok ? response.text() : null; })
        .then(function (html) {
          if (html === null || html === thread.innerHTML) return;
          var nearBottom = thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80;
          thread.innerHTML = html;
          if (nearBottom) toBottom();
        })
        .catch(function () {});
    }, 8000);
  })();
</script>
<?php endif; ?>
</body>
</html>