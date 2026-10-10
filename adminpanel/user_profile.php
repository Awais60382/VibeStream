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
require_once __DIR__ . '/release-username.php';

$USERS_TABLE  = 'users';
$VIDEOS_TABLE = 'videos';
$AVATAR_BASE  = '../';
$ADMIN_ROLE   = 'Admin';
$USER_ROLE    = 'user';

$myId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;
$id   = trim((string)($_GET['id'] ?? $_POST['id'] ?? ''));

if (empty($_SESSION['vs_user_edit_csrf'])) {
    $_SESSION['vs_user_edit_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['vs_user_edit_csrf'];

$fatal     = '';
$formError = '';
$deleted   = '';
$flash     = $_SESSION['vs_user_flash'] ?? '';
unset($_SESSION['vs_user_flash']);
$map  = [];
$vmap = [];
$user = null;

$run = static function (PDO $pdo, string $sql, array $params): PDOStatement {
    $st = $pdo->prepare($sql);
    if (!$st || !$st->execute($params)) {
        throw new RuntimeException('Database query failed.');
    }
    return $st;
};

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} elseif ($id === '') {
    $fatal = 'No user was selected. Go back to the users list and press Update.';
} else {
    $uc = vs_cols($pdo, $USERS_TABLE);
    if (!$uc) {
        $fatal = 'The "' . $USERS_TABLE . '" table could not be read.';
    } else {
        $map = [
            'id'       => vs_pick($uc, ['id', 'user_id']),
            'username' => vs_pick($uc, ['username', 'user_name', 'login', 'uname']),
            'name'     => vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
            'email'    => vs_pick($uc, ['email', 'email_address', 'mail']),
            'role'     => vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
            'avatar'   => vs_pick($uc, ['avatar', 'profile_image', 'profile_pic', 'photo', 'picture', 'image']),
            'joined'   => vs_pick($uc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']),
        ];
        if (!$map['id'] || !$map['role']) {
            $fatal = 'The "' . $USERS_TABLE . '" table needs both an id column and a role column.';
        }
    }
    $vc = vs_cols($pdo, $VIDEOS_TABLE);
    if ($vc) {
        $vmap = [
            'uploader' => vs_pick($vc, ['username', 'uploader', 'user_name', 'channel']),
            'type'     => vs_pick($vc, ['type']),
        ];
    }
}

$loadUser = static function () use ($pdo, $USERS_TABLE, $map, $id, $run) {
    $st = $run($pdo, 'SELECT * FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($map['id']) . ' = :id LIMIT 1', [':id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

if ($fatal === '') {
    try {
        $user = $loadUser();
    } catch (Throwable $e) {
        error_log('user_profile load failed: ' . $e->getMessage());
        $fatal = 'Could not load this user.';
    }
    if ($fatal === '' && !$user) {
        $fatal = 'That user was not found. It may have been deleted.';
    }
}

$isSelf   = ($myId !== null && $id !== '' && (string)$myId === $id);
$username = ($user && $map['username']) ? trim((string)($user[$map['username']] ?? '')) : '';
$curRole  = ($user && $map['role']) ? trim((string)($user[$map['role']] ?? '')) : '';
$isAdminUser = strcasecmp($curRole, $ADMIN_ROLE) === 0;

$countAdmins = static function () use ($pdo, $USERS_TABLE, $map, $ADMIN_ROLE, $run): int {
    $st = $run($pdo, 'SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE LOWER(' . vs_q($map['role']) . ') = LOWER(:r)', [':r' => $ADMIN_ROLE]);
    return (int)$st->fetchColumn();
};

/* ---------- Actions ---------- */
if ($fatal === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new InvalidArgumentException('The request expired. Refresh the page and try again.');
        }
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'make_admin') {
            if ($isAdminUser) {
                throw new InvalidArgumentException('This account is already an admin.');
            }
            $run($pdo, 'UPDATE ' . vs_q($USERS_TABLE) . ' SET ' . vs_q($map['role']) . ' = :r WHERE ' . vs_q($map['id']) . ' = :id', [':r' => $ADMIN_ROLE, ':id' => $id]);
            $_SESSION['vs_user_flash'] = ($username !== '' ? $username : 'The user') . ' is now an admin.';
            header('Location: user_profile.php?id=' . urlencode($id));
            exit;

        } elseif ($action === 'make_user') {
            if (!$isAdminUser) {
                throw new InvalidArgumentException('This account is already a normal user.');
            }
            if ($isSelf) {
                throw new InvalidArgumentException('You cannot remove your own admin role.');
            }
            if ($countAdmins() <= 1) {
                throw new InvalidArgumentException('This is the last admin account, so it cannot be changed to a user.');
            }
            $run($pdo, 'UPDATE ' . vs_q($USERS_TABLE) . ' SET ' . vs_q($map['role']) . ' = :r WHERE ' . vs_q($map['id']) . ' = :id', [':r' => $USER_ROLE, ':id' => $id]);
            $_SESSION['vs_user_flash'] = ($username !== '' ? $username : 'The admin') . ' is now a normal user.';
            header('Location: user_profile.php?id=' . urlencode($id));
            exit;

        } elseif ($action === 'delete_videos') {
            if ($username === '' || empty($vmap['uploader'])) {
                throw new InvalidArgumentException('Videos could not be matched to this user.');
            }
            $st = $run($pdo, 'DELETE FROM ' . vs_q($VIDEOS_TABLE) . ' WHERE LOWER(' . vs_q($vmap['uploader']) . ') = LOWER(:u)', [':u' => $username]);
            $_SESSION['vs_user_flash'] = 'Deleted ' . (int)$st->rowCount() . ' upload(s) from ' . $username . '.';
            header('Location: user_profile.php?id=' . urlencode($id));
            exit;

        } elseif ($action === 'delete_account') {
            if ($isSelf) {
                throw new InvalidArgumentException('You cannot delete your own account.');
            }
            if ($isAdminUser && $countAdmins() <= 1) {
                throw new InvalidArgumentException('This is the last admin account, so it cannot be deleted.');
            }
            $alsoVideos    = (($_POST['videos_choice'] ?? 'keep') === 'delete');
            $removedVideos = 0;
            $pdo->beginTransaction();
            try {
                if ($alsoVideos && $username !== '' && !empty($vmap['uploader'])) {
                    $st = $run($pdo, 'DELETE FROM ' . vs_q($VIDEOS_TABLE) . ' WHERE LOWER(' . vs_q($vmap['uploader']) . ') = LOWER(:u)', [':u' => $username]);
                    $removedVideos = (int)$st->rowCount();
                }
                $run($pdo, 'DELETE FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($map['id']) . ' = :id', [':id' => $id]);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
            if ($username !== '') {
                $key = md5(mb_strtolower(trim($username)));
                foreach (['jpg', 'png', 'webp', 'gif'] as $ext) {
                    $p = __DIR__ . '/../uploads/avatars/' . $key . '.' . $ext;
                    if (is_file($p)) {
                        @unlink($p);
                    }
                }
                // Free the name everywhere so it can be used again.
                try {
                    vs_release_username($pdo, $username, 'deleted_' . preg_replace('/\W/', '', $id));
                } catch (Throwable $releaseError) {
                    error_log('Could not free user name: ' . $releaseError->getMessage());
                }
            }
            $deleted = ($username !== '' ? $username : 'The account') . ' was deleted. '
                . ($alsoVideos ? 'Their ' . $removedVideos . ' upload(s) were deleted too.' : 'Their uploads were kept and now show under the name deleted_' . preg_replace('/\\W/', '', $id) . '.');
            $user = null;
        }
    } catch (InvalidArgumentException $e) {
        $formError = $e->getMessage();
    } catch (Throwable $e) {
        error_log('user_profile action failed: ' . $e->getMessage());
        $formError = 'Something went wrong. Please try again.';
    }
}

/* ---------- Numbers shown in the page ---------- */
$videoCount = 0;
$shortCount = 0;
if ($user && $username !== '' && !empty($vmap['uploader'])) {
    try {
        $st = $run($pdo, 'SELECT COUNT(*) FROM ' . vs_q($VIDEOS_TABLE) . ' WHERE LOWER(' . vs_q($vmap['uploader']) . ') = LOWER(:u)', [':u' => $username]);
        $total = (int)$st->fetchColumn();
        if (!empty($vmap['type'])) {
            $st = $run($pdo, 'SELECT COUNT(*) FROM ' . vs_q($VIDEOS_TABLE) . ' WHERE LOWER(' . vs_q($vmap['uploader']) . ') = LOWER(:u) AND LOWER(' . vs_q($vmap['type']) . ") = 'short'", [':u' => $username]);
            $shortCount = (int)$st->fetchColumn();
        }
        $videoCount = $total - $shortCount;
    } catch (Throwable $e) {
    }
}
$uploadTotal = $videoCount + $shortCount;

$full = ($user && $map['name']) ? trim((string)($user[$map['name']] ?? '')) : '';
$email = ($user && $map['email']) ? trim((string)($user[$map['email']] ?? '')) : '';
$display = $full !== '' ? $full : ($username !== '' ? $username : 'User');
$avatar = ($user && $map['avatar']) ? vs_avatar_src($user[$map['avatar']] ?? '', $AVATAR_BASE) : '';
$joined = '';
if ($user && $map['joined'] && !empty($user[$map['joined']]) && ($ts = strtotime((string)$user[$map['joined']]))) {
    $joined = date('M d, Y', $ts);
}
$backUrl = $isAdminUser ? 'admins.php' : 'Users.php';

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Accounts</p>
                <h1 class="h3 mb-1">Update <?php echo $isAdminUser ? 'admin' : 'user'; ?></h1>
                <p class="text-muted mb-0">Change this account's role or remove the account and its uploads.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo vs_e($backUrl); ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to list</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-success mt-3" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>
          <?php if ($formError !== ''): ?>
            <div class="alert alert-danger mt-3" role="alert"><?php echo vs_e($formError); ?></div>
          <?php endif; ?>

          <?php if ($deleted !== ''): ?>
            <div class="alert alert-success mt-3" role="status"><?php echo vs_e($deleted); ?></div>
            <a class="btn btn-primary btn-sm" href="Users.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to users</a>
          <?php elseif ($fatal !== ''): ?>
            <div class="alert alert-danger mt-3"><?php echo vs_e($fatal); ?></div>
          <?php else: ?>

            <section class="panel mt-3">
              <div class="d-flex align-items-center gap-3">
                <?php echo vs_avatar($display, $avatar); ?>
                <div>
                  <h2 class="h5 mb-0">
                    <?php echo vs_e($display); ?>
                    <?php if ($isSelf): ?><span class="badge text-bg-secondary">You</span><?php endif; ?>
                    <?php if ($isAdminUser): ?><span class="badge text-bg-primary">Admin</span><?php else: ?><span class="badge text-bg-light">User</span><?php endif; ?>
                  </h2>
                  <p class="text-muted small mb-0">
                    <?php if ($username !== ''): ?>@<?php echo vs_e($username); ?><?php endif; ?>
                    <?php if ($email !== ''): ?> &middot; <?php echo vs_e($email); ?><?php endif; ?>
                    <?php if ($joined !== ''): ?> &middot; Joined <?php echo vs_e($joined); ?><?php endif; ?>
                  </p>
                </div>
              </div>
            </section>

            <section class="row g-3 mt-1" aria-label="Change role">
              <div class="col-12 col-lg-6">
                <div class="panel h-100">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-shield-plus" aria-hidden="true"></i><span>Make user an admin</span></h2>
                  <p class="text-muted">Admins can open this admin panel, manage users, videos and app settings.</p>
                  <?php if ($isAdminUser): ?>
                    <button class="btn btn-primary btn-sm" type="button" disabled>Already an admin</button>
                  <?php else: ?>
                    <form method="post" action="user_profile.php?id=<?php echo urlencode($id); ?>"
                          data-msg="Make <?php echo vs_e($display); ?> an admin? They will get full access to the admin panel."
                          onsubmit="return confirm(this.dataset.msg);">
                      <input type="hidden" name="csrf_token" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo vs_e($id); ?>">
                      <input type="hidden" name="action" value="make_admin">
                      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-shield-plus" aria-hidden="true"></i> Make admin</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>

              <div class="col-12 col-lg-6">
                <div class="panel h-100">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-shield-minus" aria-hidden="true"></i><span>Make admin a user</span></h2>
                  <p class="text-muted">Removes admin access. They can still sign in and use the website as a normal user.</p>
                  <?php if (!$isAdminUser): ?>
                    <button class="btn btn-outline-secondary btn-sm" type="button" disabled>Already a normal user</button>
                  <?php elseif ($isSelf): ?>
                    <button class="btn btn-outline-secondary btn-sm" type="button" disabled>You can't change your own role</button>
                  <?php else: ?>
                    <form method="post" action="user_profile.php?id=<?php echo urlencode($id); ?>"
                          data-msg="Make <?php echo vs_e($display); ?> a normal user? They will lose admin access."
                          onsubmit="return confirm(this.dataset.msg);">
                      <input type="hidden" name="csrf_token" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="id" value="<?php echo vs_e($id); ?>">
                      <input type="hidden" name="action" value="make_user">
                      <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-shield-minus" aria-hidden="true"></i> Make user</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </section>

            <section class="panel mt-3" aria-label="Danger zone" style="border-color: rgba(220,53,69,.55);">
              <h2 class="h5 mb-1 section-title text-danger"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Danger zone</span></h2>
              <p class="text-muted">These actions cannot be undone.</p>

              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 py-3 border-top">
                <div>
                  <p class="fw-semibold mb-0">Delete account</p>
                  <p class="text-muted small mb-0">Permanently removes this account and its profile picture. Use the switch below to also delete their videos and shorts.</p>
                </div>
                <?php if ($isSelf): ?>
                  <button class="btn btn-danger btn-sm" type="button" disabled>You can't delete your own account</button>
                <?php else: ?>
                  <form method="post" action="user_profile.php?id=<?php echo urlencode($id); ?>"
                        class="text-start"
                        data-msg="Permanently delete the account of <?php echo vs_e($display); ?>? Their uploads will be kept. This cannot be undone."
                        data-msg-delete="Permanently delete the account of <?php echo vs_e($display); ?> AND all of their videos and shorts? This cannot be undone."
                        onsubmit="var c = this.querySelector('input[name=videos_choice]:checked'); return confirm(c &amp;&amp; c.value === 'delete' ? this.dataset.msgDelete : this.dataset.msg);">
                    <input type="hidden" name="csrf_token" value="<?php echo vs_e($csrf); ?>">
                    <input type="hidden" name="id" value="<?php echo vs_e($id); ?>">
                    <input type="hidden" name="action" value="delete_account">
                    <?php if (true): ?>
                      <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" name="videos_choice" id="vc_delete" value="delete">
                        <label class="form-check-label" for="vc_delete">Also delete their videos and shorts<?php if ($uploadTotal > 0): ?> (<?php echo (int)$videoCount; ?> video<?php echo $videoCount === 1 ? '' : 's'; ?>, <?php echo (int)$shortCount; ?> short<?php echo $shortCount === 1 ? '' : 's'; ?>)<?php endif; ?></label>
                        <div class="form-text">
                          <?php if (empty($vmap['uploader'])): ?>
                            Could not find the uploader column in the videos table, so uploads cannot be matched to this user.
                          <?php elseif ($uploadTotal === 0): ?>
                            No uploads were found for this user. Off keeps anything they may have.
                          <?php else: ?>
                            Off keeps their uploads on the site.
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php else: ?>
                      <input type="hidden" name="videos_choice" value="keep">
                    <?php endif; ?>
                    <button class="btn btn-danger btn-sm" type="submit"><i class="bi bi-person-x" aria-hidden="true"></i> Delete account</button>
                  </form>
                <?php endif; ?>
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