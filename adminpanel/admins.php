<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$adminCsrf = $_SESSION['admin_csrf'];

$USERS_TABLE = 'users';
$ADMIN_ROLE  = 'Admin';

$myId   = $_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;
$myName = mb_strtolower(trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? '')));
$q      = trim((string)($_GET['q'] ?? ''));

$fatal  = '';
$admins = [];
$totalAdmins = 0;
$isOwner = false;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $isOwner = vs_is_owner($pdo);

    $stats = vs_uploader_stats($pdo);
    $byKey = [];
    foreach ($stats['rows'] as $r) {
        $byKey[$r['key']] = $r;
    }

    $uc = vs_cols($pdo, $USERS_TABLE);
    if (!$uc) {
        $fatal = 'The "' . $USERS_TABLE . '" table was not found.';
    } else {
        $map = [
            'id'          => vs_pick($uc, ['id', 'user_id']),
            'username'    => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
            'name'        => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
            'email'       => vs_pick($uc, ['email', 'email_address', 'mail']),
            'role'        => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
            'status'      => vs_pick($uc, ['status', 'account_status', 'is_active', 'active']),
            'joined'      => vs_pick($uc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']),
            'avatar'      => vs_pick($uc, ['avatar', 'profile_image', 'profile_pic', 'photo', 'picture', 'image']),
            'last_active' => vs_pick($uc, ['last_active', 'last_seen', 'last_login']),
        ];

        if (!$map['role']) {
            $fatal = 'The "' . $USERS_TABLE . '" table has no role column, so admins cannot be told apart from users.';
        } else {
            // Delete an admin account. ONLY the owner email (VS_OWNER_EMAIL) may do this.
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_admin') {
                $code  = 'failed';
                $delId = trim((string)($_POST['id'] ?? ''));
                if (!hash_equals($adminCsrf, (string)($_POST['csrf'] ?? ''))) {
                    $code = 'expired';
                } elseif (!$isOwner) {
                    $code = 'denied';
                    vs_admin_log($pdo, 'user', 'Tried to delete admin account #' . mb_substr($delId, 0, 20) . ' (blocked: owner only)');
                } elseif (!$map['id'] || $delId === '') {
                    $code = 'failed';
                } else {
                    try {
                        $emailSel = $map['email'] ? vs_q($map['email']) : "''";
                        $nameSel  = $map['username'] ? vs_q($map['username']) : ($map['name'] ? vs_q($map['name']) : "''");
                        $find = $pdo->prepare(
                            'SELECT ' . $emailSel . ' AS e, ' . $nameSel . ' AS n FROM ' . vs_q($USERS_TABLE)
                            . ' WHERE ' . vs_q($map['id']) . ' = :i AND LOWER(TRIM(' . vs_q($map['role']) . ')) = :role LIMIT 1'
                        );
                        $find->execute([':i' => $delId, ':role' => mb_strtolower($ADMIN_ROLE)]);
                        $target = $find->fetch(PDO::FETCH_ASSOC);
                        if (!$target) {
                            $code = 'missing';
                        } elseif (strtolower(trim((string)$target['e'])) === strtolower(VS_OWNER_EMAIL)) {
                            $code = 'protected';
                        } elseif ($myId !== null && (string)$myId === $delId) {
                            $code = 'self';
                        } else {
                            $del = $pdo->prepare(
                                'DELETE FROM ' . vs_q($USERS_TABLE)
                                . ' WHERE ' . vs_q($map['id']) . ' = :i AND LOWER(TRIM(' . vs_q($map['role']) . ')) = :role LIMIT 1'
                            );
                            // Tell the database lock (install-owner-lock.php) this delete is from the verified owner.
                            $pdo->exec('SET @vs_owner_ok = 1');
                            try {
                                $del->execute([':i' => $delId, ':role' => mb_strtolower($ADMIN_ROLE)]);
                            } finally {
                                $pdo->exec('SET @vs_owner_ok = 0');
                            }
                            $code = $del->rowCount() > 0 ? 'deleted' : 'missing';
                            if ($code === 'deleted') {
                                $who = trim((string)($target['n'] ?? ''));
                                vs_admin_log($pdo, 'user', 'Deleted admin account ' . ($who !== '' ? '"' . mb_substr($who, 0, 60) . '"' : '#' . mb_substr($delId, 0, 20)));
                            }
                        }
                    } catch (Throwable $e) {
                        $code = 'failed';
                    }
                }
                header('Location: admins.php?msg=' . urlencode($code));
                exit;
            }

            $select = [];
            foreach ($map as $alias => $col) {
                if ($col) {
                    $select[] = vs_q($col) . ' AS f_' . $alias;
                }
            }
            $order = $map['joined'] ? vs_q($map['joined']) . ' ASC' : ($map['id'] ? vs_q($map['id']) . ' ASC' : '1');
            try {
                // Only rows whose role is Admin are ever selected: regular users never reach this page.
                $stmt = $pdo->prepare(
                    'SELECT ' . implode(', ', $select) . ' FROM ' . vs_q($USERS_TABLE)
                    . ' WHERE LOWER(TRIM(' . vs_q($map['role']) . ')) = :role ORDER BY ' . $order
                );
                $stmt->execute([':role' => mb_strtolower($ADMIN_ROLE)]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $rows  = [];
                $fatal = 'Could not load admins: ' . $e->getMessage();
            }

            foreach ($rows as $u) {
                $uname = trim((string)($u['f_username'] ?? ''));
                $full  = trim((string)($u['f_name'] ?? ''));
                $email = trim((string)($u['f_email'] ?? ''));
                $display = $full !== '' ? $full : ($uname !== '' ? $uname : ($email !== '' ? $email : 'Admin #' . ($u['f_id'] ?? '?')));

                $up = null;
                foreach ([$uname, $full] as $cand) {
                    $k = mb_strtolower(trim($cand));
                    if ($k !== '' && isset($byKey[$k])) {
                        $up = $byKey[$k];
                        break;
                    }
                }

                $avatarName = $uname !== '' ? $uname : $display;
                $stored     = vs_avatar_src($u['f_avatar'] ?? '', '../');
                $avatar     = avatar_url($avatarName, (string)($u['f_avatar'] ?? ''));
                if ($avatar === '') {
                    $avatar = $stored;
                }

                $isOnline = false;
                if (!empty($u['f_last_active']) && ($laTs = strtotime((string)$u['f_last_active']))) {
                    $isOnline = (time() - $laTs) <= 120;
                }

                $idStr = (string)($u['f_id'] ?? '');
                $isMe  = ($myId !== null && $idStr !== '' && (string)$myId === $idStr)
                      || ($myName !== '' && ($myName === mb_strtolower($uname) || $myName === mb_strtolower($full)));

                $admins[] = [
                    'id'       => $idStr,
                    'name'     => $display,
                    'username' => $uname,
                    'email'    => $email,
                    'avatar'   => $avatar,
                    'status'   => (string)($u['f_status'] ?? ''),
                    'joined'   => !empty($u['f_joined']) && strtotime((string)$u['f_joined']) ? date('M d, Y', strtotime((string)$u['f_joined'])) : '',
                    'last'     => !empty($u['f_last_active']) ? vs_ago((string)$u['f_last_active']) : '',
                    'online'   => $isOnline,
                    'videos'   => $up ? (int)$up['videos'] : 0,
                    'shorts'   => $up ? (int)$up['shorts'] : 0,
                    'views'    => $up ? (int)$up['views'] : 0,
                    'key'      => $up ? $up['key'] : '',
                    'is_me'    => $isMe,
                    'is_owner' => strtolower($email) === strtolower(VS_OWNER_EMAIL),
                ];
            }

            $totalAdmins = count($admins);
            if ($q !== '') {
                $admins = array_values(array_filter($admins, function ($a) use ($q) {
                    return mb_strpos(mb_strtolower($a['name'] . ' ' . $a['username'] . ' ' . $a['email']), mb_strtolower($q)) !== false;
                }));
            }
        }
    }
}

$msgs = [
    'deleted'   => 'Admin account deleted.',
    'denied'    => 'Only the owner can delete admin accounts.',
    'protected' => 'The owner account cannot be deleted.',
    'self'      => 'You cannot delete your own account here.',
    'missing'   => 'That admin was not found.',
    'expired'   => 'Session expired. Try again.',
    'failed'    => 'Could not delete that admin.',
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Accounts</p>
                <h1 class="h3 mb-1">Admins</h1>
                <p class="text-muted mb-0">Everyone who has administrator access to VibeStream. Regular users are not listed here.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
              <a class="btn btn-primary btn-sm" href="Users.php"><i class="bi bi-people" aria-hidden="true"></i> All Users</a>
              <?php if ($isOwner): ?>
                <a class="btn btn-outline-danger btn-sm" href="free-name.php"><i class="bi bi-person-x" aria-hidden="true"></i> Delete names</a>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info mt-3 mb-0" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <?php if ($fatal !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($fatal); ?></div>
          <?php else: ?>
          <section class="panel mt-3" aria-label="Admin accounts">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-shield-check" aria-hidden="true"></i><span>All Admins</span></h2>
                <p class="text-muted mb-0">Showing <?php echo count($admins); ?> of <?php echo (int)$totalAdmins; ?></p>
              </div>
              <form class="d-flex gap-2" method="get" action="admins.php">
                <input class="form-control form-control-sm" type="search" name="q" value="<?php echo vs_e($q); ?>" placeholder="Search admins" aria-label="Search admins">
                <button class="btn btn-primary btn-sm" type="submit">Search</button>
                <?php if ($q !== ''): ?><a class="btn btn-outline-secondary btn-sm" href="admins.php">Reset</a><?php endif; ?>
              </form>
            </div>

            <?php if (!$admins): ?>
              <p class="text-center text-muted py-4 mb-0">No admins found<?php echo $q !== '' ? ' for "' . vs_e($q) . '"' : ''; ?>.</p>
            <?php else: ?>
            <div class="row g-3">
              <?php foreach ($admins as $a):
                  [$statusLabel, $statusClass] = vs_status($a['status']);
              ?>
              <div class="col-12 col-md-6 col-xl-4">
                <div class="border rounded-3 p-3 h-100">
                  <div class="d-flex align-items-center gap-3">
                    <?php echo vs_avatar($a['name'], $a['avatar']); ?>
                    <div class="min-w-0">
                      <p class="fw-semibold mb-0"><?php echo vs_e($a['name']); ?>
                        <?php if ($a['is_me']): ?><span class="badge text-bg-secondary">You</span><?php endif; ?>
                      </p>
                      <?php if ($a['username'] !== ''): ?><p class="text-muted small mb-0">@<?php echo vs_e($a['username']); ?></p><?php endif; ?>
                      <?php if ($a['email'] !== ''): ?><p class="text-muted small mb-0 vs-mail"><?php echo vs_e($a['email']); ?></p><?php endif; ?>
                    </div>
                  </div>

                  <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                    <?php if ($a['is_owner']): ?><span class="badge text-bg-warning">Owner</span><?php else: ?><span class="badge text-bg-primary">Admin</span><?php endif; ?>
                    <?php if ($a['status'] !== ''): ?><span class="badge <?php echo $statusClass; ?>"><?php echo vs_e($statusLabel); ?></span><?php endif; ?>
                    <span class="small text-muted">
                      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:5px;background-color:<?php echo $a['online'] ? '#2ecc71' : '#888'; ?>;"></span><?php echo $a['online'] ? 'Online' : 'Offline'; ?>
                    </span>
                  </div>

                  <div class="d-flex gap-4 mt-3 small">
                    <span><strong class="vs-num"><?php echo (int)$a['videos']; ?></strong> videos</span>
                    <span><strong class="vs-num"><?php echo (int)$a['shorts']; ?></strong> shorts</span>
                    <span><strong class="vs-num"><?php echo number_format($a['views']); ?></strong> views</span>
                  </div>

                  <p class="text-muted small mt-3 mb-0">
                    <?php if ($a['joined'] !== ''): ?>Joined <?php echo vs_e($a['joined']); ?><?php endif; ?>
                    <?php if ($a['joined'] !== '' && $a['last'] !== ''): ?> · <?php endif; ?>
                    <?php if ($a['last'] !== ''): ?>Last active <?php echo vs_e($a['last']); ?><?php endif; ?>
                  </p>

                  <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($a['key'] !== '' && ($a['videos'] + $a['shorts']) > 0): ?>
                      <a class="btn btn-light btn-sm" href="videos.php?uploader=<?php echo urlencode($a['key']); ?>">View uploads</a>
                    <?php endif; ?>
                    <?php if ($a['is_me']): ?>
                      <a class="btn btn-primary btn-sm" href="profile.php">My profile</a>
                    <?php elseif ($a['id'] !== ''): ?>
                      <a class="btn btn-primary btn-sm" href="user_profile.php?id=<?php echo urlencode($a['id']); ?>">Update admin</a>
                    <?php endif; ?>
                    <?php if ($isOwner && !$a['is_me'] && !$a['is_owner'] && $a['id'] !== ''): ?>
                      <form method="post" action="admins.php" class="d-inline" onsubmit="return confirm('Delete this admin account? This cannot be undone.');">
                        <input type="hidden" name="csrf" value="<?php echo vs_e($adminCsrf); ?>">
                        <input type="hidden" name="action" value="delete_admin">
                        <input type="hidden" name="id" value="<?php echo vs_e($a['id']); ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete admin</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>