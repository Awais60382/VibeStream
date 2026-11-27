<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once __DIR__ . '/../connection.php';
include_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/admin-activity-store.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];
$fatal = '';
$message = '';
$bans = [];
$banHistory = [];
$users = [];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found.';
} else {
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `user_bans` (
                `user_id` VARCHAR(191) NOT NULL,
                `username` VARCHAR(191) NOT NULL DEFAULT "",
                `reason` VARCHAR(500) NOT NULL DEFAULT "",
                `banned_by` VARCHAR(191) NOT NULL DEFAULT "",
                `is_permanent` TINYINT(1) NOT NULL DEFAULT 0,
                `expires_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`user_id`),
                KEY `idx_user_bans_username` (`username`),
                KEY `idx_user_bans_expires` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $uc = vs_cols($pdo, 'users');
        $map = $uc ? [
            'id' => vs_pick($uc, ['id', 'user_id']),
            'username' => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
            'name' => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name']),
            'email' => vs_pick($uc, ['email', 'email_address', 'mail']),
            'role' => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
            'status' => vs_pick($uc, ['status', 'account_status']),
        ] : [];
        $isPrivilegedRole = static function ($role): bool {
            $normalized = preg_replace('/[^a-z]/', '', strtolower(trim((string)$role)));
            return $normalized !== '' && preg_match('/admin|owner|ceo|founder/', $normalized) === 1;
        };

        if (!$uc || !$map['id']) {
            $fatal = 'The users table needs an id column to manage bans.';
        } else {
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                $action = (string)($_POST['action'] ?? '');
                $targetId = trim((string)($_POST['user_id'] ?? ''));

                if ($action === 'ban' && $targetId !== '') {
                    $duration = (string)($_POST['duration'] ?? '24h');
                    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
                    $permanent = $duration === 'permanent';
                    $durations = ['1m' => 60, '1h' => 3600, '2h' => 7200, '3h' => 10800, '24h' => 86400, '7d' => 604800, '30d' => 2592000];
                    if (!$permanent && !isset($durations[$duration])) {
                        header('Location: banned-users.php?msg=' . urlencode('Pick a valid ban length.'));
                        exit;
                    }
                    $expiresEpoch = null;
                    if (!$permanent) {
                        $dbNow = (int)$pdo->query('SELECT UNIX_TIMESTAMP(NOW())')->fetchColumn();
                        $expiresEpoch = $dbNow + $durations[$duration];
                    }

                    $select = [vs_q($map['id']) . ' AS uid'];
                    if ($map['username']) $select[] = vs_q($map['username']) . ' AS uname';
                    if ($map['name']) $select[] = vs_q($map['name']) . ' AS fullname';
                    if ($map['email']) $select[] = vs_q($map['email']) . ' AS email';
                    if ($map['role']) $select[] = vs_q($map['role']) . ' AS role';
                    $find = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM ' . vs_q('users') . ' WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1');
                    $find->execute([':id' => $targetId]);
                    $target = $find->fetch(PDO::FETCH_ASSOC);

                    if ($target && $isPrivilegedRole($target['role'] ?? '')) {
                        $message = 'Owner, CEO, founder, and admin accounts cannot be banned.';
                    } elseif ($target) {
                        $username = trim((string)($target['uname'] ?? $target['fullname'] ?? $target['email'] ?? ''));
                        $adminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'Admin'));
                        $save = $pdo->prepare(
                            'INSERT INTO `user_bans` (`user_id`, `username`, `reason`, `banned_by`, `is_permanent`, `expires_at`)
                             VALUES (:user_id, :username, :reason, :banned_by, :permanent,
                                     IF(:expires_check IS NULL, NULL, FROM_UNIXTIME(:expires_epoch)))
                             ON DUPLICATE KEY UPDATE `username`=VALUES(`username`), `reason`=VALUES(`reason`),
                             `banned_by`=VALUES(`banned_by`), `is_permanent`=VALUES(`is_permanent`),
                             `expires_at`=VALUES(`expires_at`), `updated_at`=CURRENT_TIMESTAMP'
                        );
                        $save->execute([
                            ':user_id' => $targetId,
                            ':username' => $username,
                            ':reason' => $reason,
                            ':banned_by' => $adminName,
                            ':permanent' => $permanent ? 1 : 0,
                            ':expires_check' => $expiresEpoch,
                            ':expires_epoch' => $expiresEpoch,
                        ]);
                        $verify = $pdo->prepare('SELECT `user_id`, `is_permanent`, `expires_at`, UNIX_TIMESTAMP(`expires_at`) AS `expires_epoch` FROM `user_bans` WHERE `user_id` = :id LIMIT 1');
                        $verify->execute([':id' => $targetId]);
                        $savedBan = $verify->fetch(PDO::FETCH_ASSOC);
                        if (!$savedBan) {
                            throw new RuntimeException('The ban was not saved to the database.');
                        }
                        if ($map['status']) {
                            if ($permanent) {
                                $banStatus = $pdo->prepare('UPDATE ' . vs_q('users') . ' SET ' . vs_q($map['status']) . ' = :status WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1');
                                $banStatus->execute([':status' => 'banned', ':id' => $targetId]);
                            } else {
                                // a temporary ban replaces an older permanent one, so the account is not stuck as "banned" after it ends
                                $banStatus = $pdo->prepare('UPDATE ' . vs_q('users') . ' SET ' . vs_q($map['status']) . ' = :active WHERE ' . vs_q($map['id']) . ' = :id AND ' . vs_q($map['status']) . ' = :banned LIMIT 1');
                                $banStatus->execute([':active' => 'active', ':id' => $targetId, ':banned' => 'banned']);
                            }
                        }
                        if (function_exists('vs_admin_log')) {
                            $banDetail = ($permanent ? 'Permanently banned' : 'Temporarily banned until ' . date('Y-m-d H:i:s', (int)$savedBan['expires_epoch'])) . ' account #' . mb_substr($targetId, 0, 20) . ($username !== '' ? ' (' . mb_substr($username, 0, 60) . ')' : '');
                            if ($reason !== '') $banDetail .= ' - Reason: ' . mb_substr($reason, 0, 200);
                            vs_admin_log($pdo, 'user', $banDetail);
                        }
                        $message = $permanent ? 'The user is permanently banned.' : 'The user is banned until ' . date('M j, Y g:i A', (int)$savedBan['expires_epoch']) . '.';
                    } else {
                        $message = 'The selected account could not be found.';
                    }
                }

                if ($action === 'unban' && $targetId !== '') {
                    $delete = $pdo->prepare('DELETE FROM `user_bans` WHERE `user_id` = :id LIMIT 1');
                    $delete->execute([':id' => $targetId]);
                    if ($map['status']) {
                        $update = $pdo->prepare('UPDATE ' . vs_q('users') . ' SET ' . vs_q($map['status']) . ' = :active WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1');
                        $update->execute([':active' => 'active', ':id' => $targetId]);
                    }
                    foreach (['is_banned', 'banned', 'is_blocked'] as $flag) {
                        if (isset($uc[$flag])) {
                            $flagUpdate = $pdo->prepare('UPDATE ' . vs_q('users') . ' SET ' . vs_q($uc[$flag]) . ' = 0 WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1');
                            $flagUpdate->execute([':id' => $targetId]);
                        }
                    }
                    if (function_exists('vs_admin_log')) {
                        vs_admin_log($pdo, 'user', 'Removed ban from account #' . mb_substr($targetId, 0, 20));
                    }
                    $message = 'The user is no longer banned.';
                }

                if ($action === 'ban' || $action === 'unban') {
                    header('Location: banned-users.php?msg=' . urlencode($message));
                    exit;
                }
            }

            if (isset($_GET['msg'])) {
                $message = trim((string)$_GET['msg']);
            }

            $selectUser = [vs_q($map['id']) . ' AS uid'];
            if ($map['username']) $selectUser[] = vs_q($map['username']) . ' AS uname';
            if ($map['name']) $selectUser[] = vs_q($map['name']) . ' AS fullname';
            if ($map['email']) $selectUser[] = vs_q($map['email']) . ' AS email';
            if ($map['role']) $selectUser[] = vs_q($map['role']) . ' AS role';
            if ($map['status']) $selectUser[] = vs_q($map['status']) . ' AS status';
            foreach (['is_banned', 'banned', 'is_blocked'] as $flag) {
                if (isset($uc[$flag])) $selectUser[] = vs_q($uc[$flag]) . ' AS ' . $flag;
            }
            $selfId = '';
            foreach (['user_id', 'id', 'userid', 'uid'] as $sk) {
                if (!empty($_SESSION[$sk]) && is_scalar($_SESSION[$sk])) { $selfId = (string)$_SESSION[$sk]; break; }
            }
            $all = $pdo->query('SELECT ' . implode(', ', $selectUser) . ' FROM ' . vs_q('users') . ' ORDER BY ' . vs_q($map['id']) . ' DESC LIMIT 1000')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($all as $row) {
                $display = trim((string)($row['fullname'] ?: ($row['uname'] ?: ($row['email'] ?: 'User #' . $row['uid']))));
                if (!$isPrivilegedRole($row['role'] ?? '') && (string)$row['uid'] !== $selfId) {
                    $users[] = ['id' => (string)$row['uid'], 'name' => $display, 'email' => (string)($row['email'] ?? '')];
                }
            }

            $pdo->exec('DELETE FROM `user_bans` WHERE `is_permanent` = 0 AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()');
            $bans = $pdo->query(
                'SELECT b.*, b.`username` AS account_name, UNIX_TIMESTAMP(b.`expires_at`) AS `expires_epoch`
                 FROM `user_bans` b
                 ORDER BY b.`is_permanent` DESC, b.`expires_at` ASC, b.`created_at` DESC'
            )->fetchAll(PDO::FETCH_ASSOC);

            // Owner, CEO, founder and admin accounts are never shown as banned; old ban rows for them are removed.
            $privilegedIds = [];
            foreach ($all as $row) {
                if ($isPrivilegedRole($row['role'] ?? '')) $privilegedIds[(string)$row['uid']] = true;
            }
            if ($privilegedIds) {
                $purge = $pdo->prepare('DELETE FROM `user_bans` WHERE `user_id` = :id');
                $kept = [];
                foreach ($bans as $b) {
                    if (isset($privilegedIds[(string)$b['user_id']])) {
                        $purge->execute([':id' => (string)$b['user_id']]);
                    } else {
                        $kept[] = $b;
                    }
                }
                $bans = $kept;
            }

            // Include users that are already banned through their existing status/flag columns.
            foreach ($all as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                $flagged = false;
                foreach (['is_banned', 'banned', 'is_blocked'] as $flag) {
                    if ((int)($row[$flag] ?? 0) === 1) $flagged = true;
                }
                $legacy = in_array($status, ['banned', 'blocked', 'disabled', 'suspended'], true) || $flagged;
                if (!$legacy) continue;
                $found = false;
                foreach ($bans as $ban) {
                    if ((string)$ban['user_id'] === (string)$row['uid']) $found = true;
                }
                if (!$found && !$isPrivilegedRole($row['role'] ?? '')) {
                    $display = trim((string)($row['fullname'] ?: ($row['uname'] ?: ($row['email'] ?: 'User #' . $row['uid']))));
                    $bans[] = [
                        'user_id' => (string)$row['uid'],
                        'username' => (string)($row['uname'] ?? ''),
                        'account_name' => $display,
                        'reason' => '',
                        'banned_by' => '',
                        'is_permanent' => 1,
                        'expires_at' => null,
                        'created_at' => '',
                        '_legacy' => true,
                    ];
                }
            }

            try {
                if (function_exists('vs_activity_ensure')) {
                    vs_activity_ensure($pdo);
                }
                $historyStmt = $pdo->prepare(
                    'SELECT `admin_username`, `details`, `created_at`
                     FROM `admin_activity_log`
                     WHERE `action` = :action
                       AND (`details` LIKE :permanent
                         OR `details` LIKE :temporary
                         OR `details` LIKE :removed)
                     ORDER BY `created_at` DESC, `id` DESC LIMIT 50'
                );
                $historyStmt->execute([
                    ':action' => 'user',
                    ':permanent' => 'Permanently banned%',
                    ':temporary' => 'Temporarily banned%',
                    ':removed' => 'Removed ban%',
                ]);
                $banHistory = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                error_log('Could not load ban history: ' . $e->getMessage());
                $banHistory = [];
            }
        }
    } catch (Throwable $e) {
        $fatal = 'Could not load banned users: ' . $e->getMessage();
    }
}

include __DIR__ . '/header.php';
vs_print_styles();
?>
<style>
:root {
  --bu-page:radial-gradient(850px 460px at 85% 0%,rgba(81,44,220,.18),transparent 68%),#0c1020;
  --bu-card:#121729;
  --bu-card-soft:#171d33;
  --bu-input:#0e1426;
  --bu-line:rgba(143,157,211,.15);
  --bu-text:#f2f4ff;
  --bu-muted:#9ba6c5;
  --bu-purple:#7446ff;
  --bu-blue:#48a9ff;
  --bu-shadow:0 16px 38px rgba(0,0,0,.2);
}
html[data-theme="light"],html[data-bs-theme="light"] {
  --bu-page:radial-gradient(850px 460px at 85% 0%,rgba(116,70,255,.09),transparent 68%),#f4f6fc;
  --bu-card:#fff;
  --bu-card-soft:#f5f6fc;
  --bu-input:#f9faff;
  --bu-line:rgba(72,86,130,.16);
  --bu-text:#1b2238;
  --bu-muted:#68738f;
  --bu-shadow:0 12px 30px rgba(37,47,83,.08);
}
main.dashboard-content.bu-page {
  min-height:100vh;
  color:var(--bu-text)!important;
  background:var(--bu-page)!important;
}
.bu-container { max-width:1480px; }
.bu-back-row { margin-bottom:10px; }
.bu-back-link {
  display:inline-flex; align-items:center; gap:8px; padding:6px 12px;
  border:1px solid var(--bu-line); border-radius:10px; background:var(--bu-card);
  color:var(--bu-muted); font-size:.82rem; font-weight:650; text-decoration:none;
  transition:background .18s,border-color .18s,color .18s;
}
.bu-back-link:hover { border-color:var(--bu-purple); background:var(--bu-card-soft); color:var(--bu-text); }
.bu-hero {
  position:relative; display:flex; align-items:center; justify-content:space-between;
  gap:20px; min-height:92px; margin-bottom:20px; overflow:hidden;
}
.bu-hero-copy { position:relative; z-index:1; display:flex; align-items:center; gap:16px; }
.bu-hero-icon {
  display:grid; place-items:center; flex:0 0 56px; width:56px; height:56px;
  border-radius:17px; background:linear-gradient(145deg,#885dff,#4b25d7);
  color:#fff; font-size:1.75rem; box-shadow:0 8px 24px rgba(103,63,255,.3);
}
.bu-eyebrow { margin:0 0 3px; color:var(--bu-purple); font-size:.72rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; }
.bu-hero h1 { margin:0; color:var(--bu-text); font-size:clamp(1.7rem,3vw,2.1rem); font-weight:800; letter-spacing:-.035em; }
.bu-hero p { margin:3px 0 0; color:var(--bu-muted); font-size:.92rem; }
.bu-hero-mark { position:absolute; top:-42px; right:22px; color:rgba(99,66,231,.13); font-size:9rem; line-height:1; transform:rotate(-8deg); }
.bu-alert { padding:12px 15px; margin:0 0 14px; border:1px solid var(--bu-line); border-radius:12px; }
.bu-alert-danger { border-color:rgba(255,77,100,.32); background:rgba(255,77,100,.1); color:#ff9baa; }
.bu-alert-info { border-color:rgba(72,169,255,.3); background:rgba(72,169,255,.09); color:var(--bu-text); }
.bu-card {
  margin-top:16px; padding:17px; border:1px solid var(--bu-line); border-radius:16px;
  background:var(--bu-card); box-shadow:var(--bu-shadow);
}
.bu-card-heading { display:flex; align-items:center; gap:10px; margin:0 0 14px; }
.bu-section-icon {
  display:grid; place-items:center; flex:0 0 30px; width:30px; height:30px;
  border-radius:10px; background:rgba(116,70,255,.16); color:#9b7aff; font-size:1rem;
}
.bu-section-icon.history { background:rgba(72,169,255,.14); color:var(--bu-blue); }
.bu-card-heading h2 { margin:0; color:var(--bu-text); font-size:1rem; font-weight:750; }
.bu-count { color:var(--bu-muted); font-weight:600; }
.bu-ban-form {
  display:grid; grid-template-columns:minmax(190px,1.05fr) minmax(155px,.8fr) minmax(220px,1.8fr) auto;
  align-items:end; gap:12px;
}
.bu-field { min-width:0; }
.bu-label { display:block; margin:0 0 6px; color:var(--bu-muted)!important; font-size:.82rem; font-weight:600; }
.bu-control { position:relative; }
.bu-control > i { position:absolute; top:50%; left:13px; z-index:2; color:var(--bu-muted); font-size:1rem; pointer-events:none; transform:translateY(-50%); }
.bu-control .form-control,.bu-control .form-select {
  min-height:41px; padding:8px 12px 8px 39px; border:1px solid var(--bu-line)!important;
  border-radius:9px; background-color:var(--bu-input)!important; color:var(--bu-text)!important;
  box-shadow:none; font-size:.86rem;
}
.bu-control .form-control::placeholder { color:var(--bu-muted); opacity:.8; }
.bu-control .form-control:focus,.bu-control .form-select:focus {
  border-color:var(--bu-purple)!important; box-shadow:0 0 0 3px rgba(116,70,255,.17);
}
.bu-control .form-select option { background:var(--bu-card); color:var(--bu-text); }
.bu-submit {
  min-height:41px; padding:8px 17px; border:0; border-radius:9px;
  background:linear-gradient(120deg,#ef2349,#d41238); color:#fff;
  font-size:.86rem; font-weight:750; white-space:nowrap; box-shadow:0 6px 16px rgba(220,25,61,.2);
  transition:filter .15s,transform .15s;
}
.bu-submit:hover { filter:brightness(1.1); transform:translateY(-1px); }
.bu-ban-list { border-top:1px solid var(--bu-line); }
.bu-ban-row {
  display:grid; grid-template-columns:minmax(175px,1.15fr) minmax(120px,1fr) 115px minmax(145px,1fr) auto;
  align-items:center; gap:14px; min-height:62px; padding:8px 5px;
  border-bottom:1px solid var(--bu-line);
}
.bu-ban-row:last-child { border-bottom:0; }
.bu-user-cell { display:flex; align-items:center; gap:11px; min-width:0; }
.bu-avatar {
  display:grid; place-items:center; flex:0 0 38px; width:38px; height:38px;
  border:2px solid rgba(126,91,255,.35); border-radius:50%;
  background:linear-gradient(145deg,#7446ff,#318de8); color:#fff; font-size:1.25rem;
}
.bu-user-copy { min-width:0; }
.bu-user-copy strong { display:block; overflow:hidden; color:var(--bu-text); font-size:.88rem; text-overflow:ellipsis; white-space:nowrap; }
.bu-user-copy small,.bu-meta { display:block; color:var(--bu-muted); font-size:.75rem; }
.bu-reason { overflow:hidden; color:var(--bu-text); font-size:.83rem; text-overflow:ellipsis; white-space:nowrap; }
.bu-reason.empty { color:var(--bu-muted); }
.bu-status {
  display:inline-flex; justify-content:center; align-items:center; gap:6px;
  width:max-content; max-width:100%; padding:5px 10px; border-radius:8px;
  font-size:.75rem; font-weight:750; white-space:nowrap;
}
.bu-status::before { width:8px; height:8px; border-radius:50%; background:currentColor; content:""; }
.bu-status.permanent { background:rgba(234,40,69,.19); color:#ff6478; }
.bu-status.temporary { background:rgba(238,164,22,.18); color:#ffc34f; }
.bu-expiry strong { display:block; color:var(--bu-text); font-size:.82rem; }
.bu-unban { justify-self:end; }
.bu-unban button {
  display:inline-flex; align-items:center; justify-content:center; gap:6px; min-width:89px;
  padding:7px 12px; border:0; border-radius:8px; background:#11a960; color:#fff;
  font-size:.8rem; font-weight:700; transition:filter .15s;
}
.bu-unban button:hover { background:#0f9956; color:#fff; filter:brightness(1.08); }
.bu-empty { padding:14px 4px; color:var(--bu-muted); font-size:.86rem; }
.bu-table-wrap { overflow-x:auto; }
.bu-table { width:100%; margin:0; border-collapse:separate; border-spacing:0; color:var(--bu-text)!important; font-size:.82rem; }
.bu-table th {
  padding:9px 12px; border:0!important; background:var(--bu-card-soft)!important;
  color:var(--bu-muted)!important; font-size:.74rem; font-weight:750; text-align:left; white-space:nowrap;
}
.bu-table th:first-child { border-radius:9px 0 0 9px; }
.bu-table th:last-child { border-radius:0 9px 9px 0; }
.bu-table td { padding:10px 12px; border-bottom:1px solid var(--bu-line)!important; background:transparent!important; color:var(--bu-text)!important; vertical-align:middle; }
.bu-table tbody tr:last-child td { border-bottom:0!important; }
.bu-table tbody tr:hover td { background:rgba(116,70,255,.045)!important; }
.bu-history-detail { min-width:320px; }
.bu-history-detail strong { display:block; font-size:.81rem; font-weight:600; }
.bu-history-admin,.bu-history-date { white-space:nowrap; }
@media (max-width: 1100px) {
  .bu-ban-form { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .bu-field.reason { grid-column:1/-1; }
  .bu-submit { justify-self:start; }
  .bu-ban-row { grid-template-columns:minmax(160px,1.2fr) minmax(100px,1fr) 110px minmax(130px,1fr); }
  .bu-unban { grid-column:4; justify-self:start; }
}
@media (max-width: 700px) {
  .bu-hero { min-height:78px; }
  .bu-hero-icon { flex-basis:48px; width:48px; height:48px; font-size:1.5rem; }
  .bu-hero-mark { right:-8px; font-size:7rem; }
  .bu-card { padding:14px; }
  .bu-ban-form { grid-template-columns:1fr; }
  .bu-field.reason { grid-column:auto; }
  .bu-submit { width:100%; }
  .bu-ban-row { grid-template-columns:minmax(0,1fr) auto; gap:8px 12px; padding:12px 4px; }
  .bu-user-cell,.bu-reason { grid-column:1/-1; }
  .bu-status { grid-column:1; }
  .bu-expiry { grid-column:1; }
  .bu-unban { grid-column:2; grid-row:3/5; justify-self:end; align-self:center; }
  .bu-history-detail { min-width:260px; }
}
</style>
<main class="dashboard-content bu-page">
  <div class="container-fluid px-3 px-lg-4 py-4 bu-container">
    <div class="bu-back-row"><a class="bu-back-link" href="Users.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>All users</a></div>
    <header class="bu-hero">
      <div class="bu-hero-copy">
        <span class="bu-hero-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
        <div><p class="bu-eyebrow">Moderation</p><h1>Banned Users</h1><p>View temporary and permanent account bans.</p></div>
      </div>
      <i class="bi bi-play-fill bu-hero-mark" aria-hidden="true"></i>
    </header>

    <?php if ($fatal !== ''): ?><div class="bu-alert bu-alert-danger" role="alert"><?php echo vs_e($fatal); ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="bu-alert bu-alert-info" role="status"><?php echo vs_e($message); ?></div><?php endif; ?>

    <section class="bu-card">
      <h2 class="bu-card-heading"><span class="bu-section-icon"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>Add ban</h2>
      <form method="post" action="banned-users.php" class="bu-ban-form">
        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
        <input type="hidden" name="action" value="ban">
        <div class="bu-field">
          <label class="bu-label" for="ban-user">Select User</label>
          <div class="bu-control"><i class="bi bi-person-fill" aria-hidden="true"></i>
            <select class="form-select" id="ban-user" name="user_id" required>
              <option value="" selected disabled hidden>Choose a user...</option>
              <?php foreach ($users as $user): ?>
                <option value="<?php echo vs_e($user['id']); ?>"><?php echo vs_e($user['name'] . ($user['email'] ? ' - ' . $user['email'] : '')); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="bu-field">
          <label class="bu-label" for="ban-duration">Ban Duration</label>
          <div class="bu-control"><i class="bi bi-clock-history" aria-hidden="true"></i>
            <select class="form-select" id="ban-duration" name="duration">
              <option value="1m">1 minute</option>
              <option value="1h">1 hour</option>
              <option value="2h">2 hours</option>
              <option value="3h">3 hours</option>
              <option value="24h" selected>24 hours</option>
              <option value="7d">7 days</option>
              <option value="30d">30 days</option>
              <option value="permanent">Permanent</option>
            </select>
          </div>
        </div>
        <div class="bu-field reason">
          <label class="bu-label" for="ban-reason">Reason (Optional)</label>
          <div class="bu-control"><i class="bi bi-pencil-fill" aria-hidden="true"></i>
            <input class="form-control" id="ban-reason" name="reason" maxlength="500" placeholder="Enter reason...">
          </div>
        </div>
        <button class="bu-submit" type="submit"><i class="bi bi-slash-circle" aria-hidden="true"></i> Ban user</button>
      </form>
    </section>

    <section class="bu-card">
      <h2 class="bu-card-heading"><span class="bu-section-icon"><i class="bi bi-slash-circle" aria-hidden="true"></i></span>Active bans <span class="bu-count">(<?php echo count($bans); ?>)</span></h2>
      <?php if (!$bans): ?>
        <p class="bu-empty">No users are currently banned.</p>
      <?php else: ?>
        <div class="bu-ban-list">
          <?php foreach ($bans as $ban):
            $permanent = (int)$ban['is_permanent'] === 1;
            $expires = !$permanent && !empty($ban['expires_epoch']) ? (int)$ban['expires_epoch'] : null;
            $remaining = $expires ? max(0, $expires - time()) : 0;
            $remainingText = $remaining <= 0 ? 'Expired' : (
              $remaining >= 86400 ? floor($remaining / 86400) . 'd ' . floor(($remaining % 86400) / 3600) . 'h' :
              ($remaining >= 3600 ? floor($remaining / 3600) . 'h ' . floor($remaining % 3600 / 60) . 'm' :
              floor($remaining / 60) . 'm ' . $remaining % 60 . 's')
            );
          ?>
            <div class="bu-ban-row">
              <div class="bu-user-cell">
                <span class="bu-avatar"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                <div class="bu-user-copy"><strong><?php echo vs_e($ban['account_name'] ?: $ban['username'] ?: 'User #' . $ban['user_id']); ?></strong><small>ID: #<?php echo vs_e($ban['user_id']); ?></small></div>
              </div>
              <div class="bu-reason<?php echo empty($ban['reason']) ? ' empty' : ''; ?>" title="<?php echo vs_e($ban['reason'] ?: 'No reason provided'); ?>"><?php echo vs_e($ban['reason'] ?: 'No reason provided'); ?></div>
              <div><span class="bu-status <?php echo $permanent ? 'permanent' : 'temporary'; ?>"><?php echo $permanent ? 'Permanent' : 'Temporary'; ?></span></div>
              <div class="bu-expiry">
                <div class="bu-meta"><?php echo $permanent ? 'No expiry date' : 'Expires'; ?></div>
                <strong<?php echo (!$permanent && $expires) ? ' class="vs-countdown" data-expires="' . (int)$expires . '"' : ''; ?>><?php echo $permanent ? 'Permanent ban' : vs_e($remainingText); ?></strong>
                <?php if (!$permanent && $expires): ?><div class="bu-meta">Until <?php echo vs_e(date('M j, Y g:i A', $expires)); ?></div><?php endif; ?>
              </div>
              <form class="bu-unban" method="post" action="banned-users.php" onsubmit="return confirm('Remove this ban?');">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <input type="hidden" name="action" value="unban">
                <input type="hidden" name="user_id" value="<?php echo vs_e($ban['user_id']); ?>">
                <button type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Unban</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="bu-card">
      <h2 class="bu-card-heading"><span class="bu-section-icon history"><i class="bi bi-clock-history" aria-hidden="true"></i></span>Ban history</h2>
      <?php if (!$banHistory): ?>
        <p class="bu-empty">No ban actions have been recorded yet.</p>
      <?php else: ?>
        <div class="bu-table-wrap">
          <table class="bu-table">
            <thead><tr><th>Activity</th><th>Banned by</th><th>Date</th></tr></thead>
            <tbody>
              <?php foreach ($banHistory as $event): ?>
                <tr>
                  <td class="bu-history-detail"><strong><?php echo vs_e($event['details']); ?></strong></td>
                  <td class="bu-history-admin"><?php echo vs_e($event['admin_username']); ?></td>
                  <td class="bu-history-date"><?php echo vs_e(date('M j, Y - H:i', strtotime((string)$event['created_at']))); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var els = document.querySelectorAll('.vs-countdown');
  if (!els.length) return;
  var offset = <?php echo time(); ?> - Math.floor(Date.now() / 1000);   // keep the server clock, not the browser clock
  function fmt(r) {
    if (r >= 86400) return Math.floor(r / 86400) + 'd ' + Math.floor((r % 86400) / 3600) + 'h';
    if (r >= 3600) return Math.floor(r / 3600) + 'h ' + Math.floor((r % 3600) / 60) + 'm';
    return Math.floor(r / 60) + 'm ' + (r % 60) + 's';
  }
  function tick() {
    var now = Math.floor(Date.now() / 1000) + offset, expired = false;
    els.forEach(function (el) {
      var left = parseInt(el.dataset.expires, 10) - now;
      if (left <= 0) { el.textContent = 'Expired'; expired = true; } else { el.textContent = fmt(left); }
    });
    if (expired) { clearInterval(timer); setTimeout(function () { location.replace('banned-users.php'); }, 1200); }
  }
  var timer = setInterval(tick, 1000);
  tick();
})();
</script>
</body>
</html>