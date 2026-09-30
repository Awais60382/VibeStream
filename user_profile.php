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


$USERS_TABLE = 'users';   
$AVATAR_BASE = '../';    
$ADMIN_ROLE  = 'Admin'; 
$USER_ROLE   = 'User';    



if (empty($_SESSION['csrf_users'])) {
    $_SESSION['csrf_users'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_users'];


$flash = $_SESSION['users_flash'] ?? null;
unset($_SESSION['users_flash']);

$myId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;

$userId = trim((string)($_POST['user_id'] ?? $_GET['id'] ?? ''));

$fatal  = '';
$person = null;

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    $uc = vs_cols($pdo, $USERS_TABLE);

    if (!$uc) {
        $fatal = 'The "' . $USERS_TABLE . '" table was not found. Change $USERS_TABLE at the top of user_profile.php.';
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

        if (!$map['id'] || !$map['role']) {
            $fatal = 'Roles cannot be changed because the "' . $USERS_TABLE . '" table needs both an id column and a role column.';
        } elseif ($userId === '') {
            $fatal = 'No user was selected. Go back to Manage Users and click Update on a person.';
        } else {

            if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['make_admin', 'make_user'], true)) {
                $makeAdmin = ($_POST['action'] === 'make_admin');
                $msg = ['type' => 'danger', 'text' => 'Something went wrong. Nothing was changed.'];

                if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                    $msg['text'] = 'Your session expired. Reload the page and try again.';
                } else {
                    try {
                        $st = $pdo->prepare('SELECT ' . vs_q($map['role']) . ' AS r FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($map['id']) . ' = :id');
                        $st->execute([':id' => $userId]);
                        $cur = $st->fetch(PDO::FETCH_ASSOC);

                        if (!$cur) {
                            $msg['text'] = 'That user was not found.';
                        } else {
                            $isAdminNow = strcasecmp(trim((string)$cur['r']), $ADMIN_ROLE) === 0;

                            if ($makeAdmin === $isAdminNow) {
                                $msg = ['type' => 'info', 'text' => $makeAdmin ? 'This person is already an admin.' : 'This person is already a normal user.'];
                            } elseif (!$makeAdmin && $myId !== null && (string)$myId === $userId) {
                                $msg['text'] = 'You cannot remove your own admin access.';
                            } else {
                                $blocked = false;
                                if (!$makeAdmin) {
                                    $cnt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE LOWER(TRIM(' . vs_q($map['role']) . ')) = :r');
                                    $cnt->execute([':r' => strtolower($ADMIN_ROLE)]);
                                    if ((int)$cnt->fetchColumn() <= 1) {
                                        $blocked = true;
                                        $msg['text'] = 'You cannot remove the last admin.';
                                    }
                                }
                                if (!$blocked) {
                                    $up = $pdo->prepare('UPDATE ' . vs_q($USERS_TABLE) . ' SET ' . vs_q($map['role']) . ' = :role WHERE ' . vs_q($map['id']) . ' = :id');
                                    $up->execute([':role' => $makeAdmin ? $ADMIN_ROLE : $USER_ROLE, ':id' => $userId]);
                                    $msg = ['type' => 'success', 'text' => $makeAdmin ? 'Done. This person is now an admin.' : 'Done. This person is now a normal user.'];
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        $msg['text'] = 'Could not change the role: ' . $e->getMessage();
                    }
                }

                $_SESSION['users_flash'] = $msg;
                header('Location: user_profile.php?id=' . urlencode($userId));
                exit;
            }

            $select = [];
            foreach ($map as $alias => $col) {
                if ($col) {
                    $select[] = vs_q($col) . ' AS f_' . $alias;
                }
            }

            try {
                $st = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1');
                $st->execute([':id' => $userId]);
                $u = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $u     = false;
                $fatal = 'Could not load this user: ' . $e->getMessage();
            }

            if ($u) {
                $uname = trim((string)($u['f_username'] ?? ''));
                $full  = trim((string)($u['f_name'] ?? ''));
                $email = trim((string)($u['f_email'] ?? ''));
                $display = $full !== '' ? $full : ($uname !== '' ? $uname : ($email !== '' ? $email : 'User #' . $userId));

                $stats = vs_uploader_stats($pdo);
                $byKey = [];
                foreach ($stats['rows'] as $r) {
                    $byKey[$r['key']] = $r;
                }
                $up = null;
                foreach ([$uname, $full] as $cand) {
                    $k = mb_strtolower(trim($cand));
                    if ($k !== '' && isset($byKey[$k])) {
                        $up = $byKey[$k];
                        break;
                    }
                }

                $joined = '';
                if (!empty($u['f_joined']) && ($ts = strtotime((string)$u['f_joined']))) {
                    $joined = date('M d, Y', $ts);
                }

                $lastSeen = '';
                $isOnline = false;
                if (!empty($u['f_last_active']) && ($laTs = strtotime((string)$u['f_last_active']))) {
                    $lastSeen = date('M d, Y g:i A', $laTs);
                    $isOnline = (time() - $laTs) <= 120;
                }

                $roleText = trim((string)($u['f_role'] ?? ''));

                $person = [
                    'id'        => $userId,
                    'name'      => $display,
                    'username'  => $uname,
                    'email'     => $email,
                    'avatar'    => vs_avatar_src($u['f_avatar'] ?? '', $AVATAR_BASE),
                    'role'      => $roleText,
                    'is_admin'  => strcasecmp($roleText, $ADMIN_ROLE) === 0,
                    'status'    => (string)($u['f_status'] ?? ''),
                    'joined'    => $joined,
                    'last_seen' => $lastSeen,
                    'online'    => $isOnline,
                    'videos'    => $up ? $up['videos'] : 0,
                    'shorts'    => $up ? $up['shorts'] : 0,
                    'views'     => $up ? $up['views'] : 0,
                    'key'       => $up ? $up['key'] : '',
                ];
            } elseif ($fatal === '') {
                $fatal = 'That user was not found.';
            }
        }
    }
}

$isMe = ($person && $myId !== null && (string)$myId === $person['id']);

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Accounts</p>
                <h1 class="h3 mb-1">User Profile</h1>
                <p class="text-muted mb-0">Look at this person's account and change what they are allowed to do.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="users.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Users</a>
            </div>
          </div>

          <?php if ($flash && is_array($flash)): ?>
            <div class="alert alert-<?php echo vs_e($flash['type'] ?? 'info'); ?> mt-3"><?php echo vs_e($flash['text'] ?? ''); ?></div>
          <?php endif; ?>

          <?php if ($fatal !== ''): ?>
            <div class="alert alert-danger mt-3"><?php echo vs_e($fatal); ?></div>
          <?php elseif ($person): ?>

          <section class="panel mt-3">
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <?php echo vs_avatar($person['name'], $person['avatar']); ?>
              <div>
                <h2 class="h5 mb-1">
                  <?php echo vs_e($person['name']); ?>
                  <?php if ($person['is_admin']): ?><span class="badge text-bg-primary">Admin</span><?php else: ?><span class="badge text-bg-secondary">User</span><?php endif; ?>
                  <?php if ($isMe): ?><span class="badge text-bg-info">You</span><?php endif; ?>
                </h2>
                <p class="text-muted mb-0">
                  <?php echo $person['email'] !== '' ? vs_e($person['email']) : ($person['username'] !== '' ? '@' . vs_e($person['username']) : ''); ?>
                </p>
              </div>
            </div>

            <hr>

            <div class="row g-3">
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Role</p>
                <p class="fw-semibold mb-0"><?php echo $person['role'] !== '' ? vs_e(ucfirst($person['role'])) : '—'; ?></p>
              </div>
              <?php if ($map['status']): [$statusLabel, $statusClass] = vs_status($person['status']); ?>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Status</p>
                <p class="mb-0"><span class="badge <?php echo $statusClass; ?>"><?php echo vs_e($statusLabel); ?></span></p>
              </div>
              <?php endif; ?>
              <?php if ($map['joined']): ?>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Joined</p>
                <p class="fw-semibold mb-0"><?php echo $person['joined'] !== '' ? vs_e($person['joined']) : '—'; ?></p>
              </div>
              <?php endif; ?>
              <?php if ($map['last_active']): ?>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Last active</p>
                <p class="fw-semibold mb-0">
                  <span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background-color:<?php echo $person['online'] ? '#2ecc71' : '#888'; ?>;"></span>
                  <?php echo $person['online'] ? 'Online now' : ($person['last_seen'] !== '' ? vs_e($person['last_seen']) : 'Never'); ?>
                </p>
              </div>
              <?php endif; ?>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Videos</p>
                <p class="fw-semibold mb-0"><?php echo (int)$person['videos']; ?></p>
              </div>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Shorts</p>
                <p class="fw-semibold mb-0"><?php echo (int)$person['shorts']; ?></p>
              </div>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Views</p>
                <p class="fw-semibold mb-0"><?php echo number_format($person['views']); ?></p>
              </div>
              <div class="col-6 col-md-3">
                <p class="text-muted small mb-1">Uploads</p>
                <p class="mb-0">
                  <?php if (($person['videos'] + $person['shorts']) > 0): ?>
                    <a class="btn btn-light btn-sm" href="videos.php?uploader=<?php echo urlencode($person['key']); ?>">View uploads</a>
                  <?php else: ?>
                    <span class="text-muted">No uploads</span>
                  <?php endif; ?>
                </p>
              </div>
            </div>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Change role</span></h2>
                <p class="text-muted mb-0">Admins can manage the whole panel: users, videos and settings. Pick one option.</p>
              </div>
            </div>

            <div class="row g-3">
              <!-- Option 1 -->
              <div class="col-12 col-md-6">
                <div class="border rounded p-3 h-100">
                  <p class="text-muted small mb-1">Option 1</p>
                  <h3 class="h6 mb-2">Make this user an admin</h3>
                  <p class="text-muted small">They will be able to open this admin panel and manage users and videos. They must log out and back in first.</p>
                  <form method="post" action="user_profile.php?id=<?php echo urlencode($person['id']); ?>" onsubmit="return confirm(<?php echo vs_e(json_encode('Make ' . $person['name'] . ' an admin? They will be able to manage this whole panel.', JSON_HEX_APOS | JSON_HEX_QUOT)); ?>);">
                    <input type="hidden" name="action" value="make_admin">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <input type="hidden" name="user_id" value="<?php echo vs_e($person['id']); ?>">
                    <button class="btn btn-primary btn-sm" type="submit"<?php echo $person['is_admin'] ? ' disabled' : ''; ?>>Make this user an admin</button>
                    <?php if ($person['is_admin']): ?><span class="text-muted small ms-2">Already an admin</span><?php endif; ?>
                  </form>
                </div>
              </div>

              <!-- Option 2 -->
              <div class="col-12 col-md-6">
                <div class="border rounded p-3 h-100">
                  <p class="text-muted small mb-1">Option 2</p>
                  <h3 class="h6 mb-2">Make this admin a user</h3>
                  <p class="text-muted small">They lose admin access and become a normal user. Their videos and account stay as they are.</p>
                  <form method="post" action="user_profile.php?id=<?php echo urlencode($person['id']); ?>" onsubmit="return confirm(<?php echo vs_e(json_encode('Remove admin access from ' . $person['name'] . '?', JSON_HEX_APOS | JSON_HEX_QUOT)); ?>);">
                    <input type="hidden" name="action" value="make_user">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <input type="hidden" name="user_id" value="<?php echo vs_e($person['id']); ?>">
                    <button class="btn btn-outline-danger btn-sm" type="submit"<?php echo (!$person['is_admin'] || $isMe) ? ' disabled' : ''; ?>>Make this admin a user</button>
                    <?php if (!$person['is_admin']): ?>
                      <span class="text-muted small ms-2">Not an admin</span>
                    <?php elseif ($isMe): ?>
                      <span class="text-muted small ms-2">You can't remove your own access</span>
                    <?php endif; ?>
                  </form>
                </div>
              </div>
            </div>
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
