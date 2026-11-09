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
  .vs-ban-table { width: 100%; }
  .vs-ban-table td, .vs-ban-table th { vertical-align: middle; }
  .vs-ban-list { border-top: 1px solid var(--admin-border); }
  .vs-ban-row { display:grid; grid-template-columns:minmax(180px,1fr) 150px 180px 110px; gap:1rem; align-items:center; padding:1rem 0; border-bottom:1px solid var(--admin-border); }
  .vs-ban-history { border-top: 1px solid var(--admin-border); }
  .vs-ban-history-row { display:grid; grid-template-columns:minmax(180px,1fr) 180px 170px; gap:1rem; padding:.85rem 0; border-bottom:1px solid var(--admin-border); }
  .vs-ban-history-row:last-child { border-bottom: 0; }
  @media (max-width: 767px) {
    .vs-ban-history-row { grid-template-columns:1fr; gap:.25rem; }
  }
  @media (max-width: 767px) { .vs-ban-row { grid-template-columns:1fr; gap:.5rem; } }
</style>
<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-4">
    <div class="page-heading">
      <div class="page-heading-copy">
        <span class="page-icon"><i class="bi bi-person-fill-slash" aria-hidden="true"></i></span>
        <div><p class="eyebrow mb-1">Moderation</p><h1 class="h3 mb-1">Banned Users</h1><p class="text-muted mb-0">View temporary and permanent account bans.</p></div>
      </div>
      <div class="heading-actions"><a class="btn btn-outline-secondary btn-sm" href="Users.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> All users</a></div>
    </div>

    <?php if ($fatal !== ''): ?><div class="alert alert-danger mt-3"><?php echo vs_e($fatal); ?></div><?php endif; ?>
    <?php if ($message !== ''): ?><div class="alert alert-info mt-3"><?php echo vs_e($message); ?></div><?php endif; ?>

    <section class="panel mt-3">
      <div class="panel-header"><div><h2 class="h5 mb-1 section-title"><i class="bi bi-shield-exclamation" aria-hidden="true"></i><span>Add ban</span></h2></div></div>
      <form method="post" action="banned-users.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
        <input type="hidden" name="action" value="ban">
        <div class="col-12 col-md-4">
          <label class="form-label" for="ban-user">User</label>
          <select class="form-select" id="ban-user" name="user_id" required>
            <option value="">Select a user</option>
            <?php foreach ($users as $user): ?>
              <option value="<?php echo vs_e($user['id']); ?>"><?php echo vs_e($user['name'] . ($user['email'] ? ' - ' . $user['email'] : '')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-3">
          <label class="form-label" for="ban-duration">Ban lasts</label>
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
        <div class="col-12 col-md-5">
          <label class="form-label" for="ban-reason">Reason (optional)</label>
          <input class="form-control" id="ban-reason" name="reason" maxlength="500" placeholder="Reason shown in moderation records">
        </div>
        <div class="col-12"><button class="btn btn-danger" type="submit"><i class="bi bi-person-fill-slash" aria-hidden="true"></i> Ban user</button></div>
      </form>
    </section>

    <section class="panel mt-3">
      <div class="panel-header"><div><h2 class="h5 mb-1 section-title"><i class="bi bi-person-fill-lock" aria-hidden="true"></i><span>Active bans (<?php echo count($bans); ?>)</span></h2></div></div>
      <?php if (!$bans): ?>
        <p class="text-muted mb-0 py-3">No users are currently banned.</p>
      <?php else: ?>
        <div class="vs-ban-list">
          <?php foreach ($bans as $ban):
            $permanent = (int)$ban['is_permanent'] === 1;
            $expires = !$permanent && !empty($ban['expires_epoch']) ? (int)$ban['expires_epoch'] : null;
            $remaining = $expires ? max(0, $expires - time()) : 0;
            $remainingText = $remaining <= 0 ? 'Expired' : (
              $remaining >= 86400 ? floor($remaining / 86400) . 'd ' . floor(($remaining % 86400) / 3600) . 'h' :
              ($remaining >= 3600 ? floor($remaining / 3600) . 'h ' . floor(($remaining % 3600) / 60) . 'm' :
              floor($remaining / 60) . 'm ' . ($remaining % 60) . 's')
            );
          ?>
            <div class="vs-ban-row">
              <div><strong><?php echo vs_e($ban['account_name'] ?: $ban['username'] ?: 'User #' . $ban['user_id']); ?></strong><div class="text-muted small">User ID: <?php echo vs_e($ban['user_id']); ?></div><?php if (!empty($ban['reason'])): ?><div class="small mt-1"><?php echo vs_e($ban['reason']); ?></div><?php endif; ?></div>
              <div><?php if ($permanent): ?><span class="badge text-bg-danger">Permanent</span><?php else: ?><span class="badge text-bg-warning">Temporary</span><?php endif; ?></div>
              <div><div class="small text-muted"><?php echo $permanent ? 'No expiry' : 'Time remaining'; ?></div><strong<?php echo (!$permanent && $expires) ? ' class="vs-countdown" data-expires="' . (int)$expires . '"' : ''; ?>><?php echo $permanent ? 'Permanent ban' : vs_e($remainingText); ?></strong><?php if (!$permanent && $expires): ?><div class="small text-muted">Until <?php echo vs_e(date('M j, Y g:i A', $expires)); ?></div><?php endif; ?></div>
              <form method="post" action="banned-users.php" onsubmit="return confirm('Remove this ban?');">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <input type="hidden" name="action" value="unban">
                <input type="hidden" name="user_id" value="<?php echo vs_e($ban['user_id']); ?>">
                <button type="submit" class="btn btn-success btn-sm">Unban</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="panel mt-3">
      <div class="panel-header"><div><h2 class="h5 mb-1 section-title"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Ban history</span></h2></div></div>
      <?php if (!$banHistory): ?>
        <p class="text-muted mb-0 py-3">No ban actions have been recorded yet.</p>
      <?php else: ?>
        <div class="vs-ban-history">
          <?php foreach ($banHistory as $event): ?>
            <div class="vs-ban-history-row">
              <div><strong><?php echo vs_e($event['details']); ?></strong></div>
              <div><span class="text-muted small">Admin</span><div><?php echo vs_e($event['admin_username']); ?></div></div>
              <div><span class="text-muted small">Date</span><div><?php echo vs_e(date('M j, Y g:i A', strtotime((string)$event['created_at']))); ?></div></div>
            </div>
          <?php endforeach; ?>
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