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



$myId = $_SESSION['user_id'] ?? $_SESSION['id'] ?? $_SESSION['userid'] ?? $_SESSION['uid'] ?? null;


$q          = trim((string)($_GET['q'] ?? ''));
$roleFilter = trim((string)($_GET['role'] ?? ''));
$onlyUp     = (($_GET['uploaders'] ?? '') === '1');

$fatal     = '';      
$warning   = '';      
$mode      = 'users';
$people    = [];
$unmatched = [];
$show      = ['role' => false, 'status' => false, 'date' => false, 'online' => false];
$dateLabel = 'Joined';
$canUpdate = false; 
$stats     = ['rows' => [], 'totals' => ['uploaders' => 0, 'videos' => 0, 'shorts' => 0, 'views' => 0], 'has_date' => false, 'error' => ''];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    $stats = vs_uploader_stats($pdo);
    $byKey = [];
    foreach ($stats['rows'] as $r) {
        $byKey[$r['key']] = $r;
    }
    if ($stats['error'] !== '') {
        $warning = $stats['error'];
    }

    $uc = vs_cols($pdo, $USERS_TABLE);

    if ($uc) {
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

        $canUpdate = (bool)($map['id'] && $map['role']);

        $select = [];
        foreach ($map as $alias => $col) {
            if ($col) {
                $select[] = vs_q($col) . ' AS f_' . $alias;
            }
        }

        if (!$select) {
            $fatal = 'The "' . $USERS_TABLE . '" table has no columns this page can display.';
        } else {
            $order = $map['joined'] ? vs_q($map['joined']) . ' DESC' : ($map['id'] ? vs_q($map['id']) . ' DESC' : '1');
            try {
                $stmt = $pdo->query('SELECT ' . implode(', ', $select) . ' FROM ' . vs_q($USERS_TABLE) . ' ORDER BY ' . $order . ' LIMIT 500');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            } catch (Throwable $e) {
                $rows  = [];
                $fatal = 'Could not load users: ' . $e->getMessage();
            }

            $matched = [];
            foreach ($rows as $u) {
                $uname = trim((string)($u['f_username'] ?? ''));
                $full  = trim((string)($u['f_name'] ?? ''));
                $email = trim((string)($u['f_email'] ?? ''));
                $display = $full !== '' ? $full : ($uname !== '' ? $uname : ($email !== '' ? $email : 'User #' . ($u['f_id'] ?? '?')));

                $up = null;
                foreach ([$uname, $full] as $cand) {
                    $k = mb_strtolower(trim($cand));
                    if ($k !== '' && isset($byKey[$k])) {
                        $up = $byKey[$k];
                        $matched[$k] = true;
                        break;
                    }
                }

                $joined = '';
                if (!empty($u['f_joined']) && ($ts = strtotime((string)$u['f_joined']))) {
                    $joined = date('M d, Y', $ts);
                }


                $isOnline = false;
                if (!empty($u['f_last_active'])) {
                    $laTs = strtotime((string)$u['f_last_active']);
                    if ($laTs) {
                        $isOnline = (time() - $laTs) <= 120;
                    }
                }

                $roleText = trim((string)($u['f_role'] ?? ''));

                $people[] = [
                    'id'       => (string)($u['f_id'] ?? ''),
                    'name'     => $display,
                    'sub'      => $email !== '' ? $email : ($uname !== '' && $uname !== $display ? '@' . $uname : ''),
                    'avatar'   => vs_avatar_src($u['f_avatar'] ?? '', $AVATAR_BASE),
                    'role'     => $roleText,
                    'is_admin' => strcasecmp($roleText, $ADMIN_ROLE) === 0,
                    'status'   => (string)($u['f_status'] ?? ''),
                    'date'     => $joined,
                    'videos'   => $up ? $up['videos'] : 0,
                    'shorts'   => $up ? $up['shorts'] : 0,
                    'views'    => $up ? $up['views'] : 0,
                    'key'      => $up ? $up['key'] : '',
                    'online'   => $isOnline,
                ];
            }

            $show = [
                'role'   => (bool)$map['role'],
                'status' => (bool)$map['status'],
                'date'   => (bool)$map['joined'],
                'online' => (bool)$map['last_active'],
            ];

            foreach ($byKey as $k => $r) {
                if (!isset($matched[$k])) {
                    $unmatched[] = $r;
                }
            }
        }
    } else {

        $mode      = 'uploaders';
        $dateLabel = 'Last upload';
        $show['date'] = $stats['has_date'];
        foreach ($stats['rows'] as $r) {
            $people[] = [
                'id'       => '',
                'name'     => $r['name'],
                'sub'      => $r['total'] . ' upload' . ($r['total'] === 1 ? '' : 's'),
                'avatar'   => '',
                'role'     => '',
                'is_admin' => false,
                'status'   => '',
                'date'     => vs_ago($r['last']),
                'videos'   => $r['videos'],
                'shorts'   => $r['shorts'],
                'views'    => $r['views'],
                'key'      => $r['key'],
                'online'   => false,
            ];
        }
    }
}


$countPeople    = count($people);
$countUploaders = 0;
foreach ($people as $p) {
    if ($p['videos'] + $p['shorts'] > 0) {
        $countUploaders++;
    }
}


$roles = [];
foreach ($people as $p) {
    if ($p['role'] !== '') {
        $roles[mb_strtolower($p['role'])] = $p['role'];
    }
}
ksort($roles);

$filtered = array_values(array_filter($people, function ($p) use ($q, $roleFilter, $onlyUp) {
    if ($onlyUp && ($p['videos'] + $p['shorts']) === 0) {
        return false;
    }
    if ($roleFilter !== '' && mb_strtolower($p['role']) !== mb_strtolower($roleFilter)) {
        return false;
    }
    if ($q !== '') {
        $hay = mb_strtolower($p['name'] . ' ' . $p['sub'] . ' ' . $p['role']);
        if (mb_strpos($hay, mb_strtolower($q)) === false) {
            return false;
        }
    }
    return true;
}));

$colCount = 5
    + ($show['role'] ? 1 : 0)
    + ($show['status'] ? 1 : 0)
    + ($show['date'] ? 1 : 0)
    + ($show['online'] ? 1 : 0); 

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Accounts</p>
                <h1 class="h3 mb-1">Manage Users</h1>
                <p class="text-muted mb-0">See who has an account on VibeStream and how much each person has uploaded.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
              <a class="btn btn-primary btn-sm" href="videos.php"><i class="bi bi-collection-play" aria-hidden="true"></i> Manage Videos</a>
            </div>
          </div>

          <section class="row g-3 mt-1" aria-label="User metrics">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top">
                  <span class="metric-label"><?php echo $mode === 'users' ? 'Users' : 'Uploaders'; ?></span>
                  <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo (int)$countPeople; ?></div>
                <div class="metric-meta"><span><?php echo $mode === 'users' ? 'registered accounts' : 'names found on videos'; ?></span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-success">
                <div class="metric-top">
                  <span class="metric-label">Uploaders</span>
                  <span class="metric-icon"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo (int)$countUploaders; ?></div>
                <div class="metric-meta"><span>have published content</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top">
                  <span class="metric-label">Videos</span>
                  <span class="metric-icon"><i class="bi bi-collection-play" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($stats['totals']['videos']); ?></div>
                <div class="metric-meta"><span>long-form uploads</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top">
                  <span class="metric-label">Shorts</span>
                  <span class="metric-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($stats['totals']['shorts']); ?></div>
                <div class="metric-meta"><span>short-form uploads</span></div>
              </article>
            </div>
          </section>

          <?php if ($fatal !== ''): ?>
            <div class="alert alert-danger mt-3"><?php echo vs_e($fatal); ?></div>
          <?php endif; ?>
          <?php if ($warning !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($warning); ?></div>
          <?php endif; ?>
          <?php if ($mode === 'uploaders' && $fatal === ''): ?>
            <div class="alert alert-info mt-3">No "<?php echo vs_e($USERS_TABLE); ?>" table was found, so this list is built from the uploader names on your videos. If your accounts live in a table with another name, change <code>$USERS_TABLE</code> at the top of users.php.</div>
          <?php endif; ?>
          <?php if ($mode === 'users' && $fatal === '' && !$canUpdate): ?>
            <div class="alert alert-info mt-3">The "Update" buttons are hidden because the "<?php echo vs_e($USERS_TABLE); ?>" table needs both an <code>id</code> column and a <code>role</code> column.</div>
          <?php endif; ?>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-person-lines-fill" aria-hidden="true"></i><span><?php echo $mode === 'users' ? 'All Users' : 'All Uploaders'; ?></span></h2>
                <p class="text-muted mb-0">Showing <?php echo count($filtered); ?> of <?php echo (int)$countPeople; ?></p>
              </div>
            </div>

            <form class="row g-2 align-items-center mb-3" method="get" action="users.php">
              <div class="col-12 col-md-5">
                <input class="form-control form-control-sm" type="search" name="q" value="<?php echo vs_e($q); ?>" placeholder="Search by name, email or role" aria-label="Search users">
              </div>
              <?php if ($roles): ?>
              <div class="col-6 col-md-3">
                <select class="form-select form-select-sm" name="role" aria-label="Filter by role">
                  <option value="">All roles</option>
                  <?php foreach ($roles as $rk => $rv): ?>
                    <option value="<?php echo vs_e($rv); ?>"<?php echo mb_strtolower($roleFilter) === $rk ? ' selected' : ''; ?>><?php echo vs_e($rv); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <?php endif; ?>
              <div class="col-6 col-md-auto">
                <div class="form-check mb-0">
                  <input class="form-check-input" type="checkbox" name="uploaders" value="1" id="onlyUploaders"<?php echo $onlyUp ? ' checked' : ''; ?>>
                  <label class="form-check-label" for="onlyUploaders">Uploaders only</label>
                </div>
              </div>
              <div class="col-12 col-md-auto d-flex gap-2">
                <button class="btn btn-primary btn-sm" type="submit">Filter</button>
                <a class="btn btn-outline-secondary btn-sm" href="users.php">Reset</a>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col"><?php echo $mode === 'users' ? 'User' : 'Uploader'; ?></th>
                    <?php if ($show['online']): ?><th scope="col">Online</th><?php endif; ?>
                    <?php if ($show['role']): ?><th scope="col">Role</th><?php endif; ?>
                    <?php if ($show['status']): ?><th scope="col">Status</th><?php endif; ?>
                    <th scope="col" class="text-end">Videos</th>
                    <th scope="col" class="text-end">Shorts</th>
                    <th scope="col" class="text-end">Views</th>
                    <?php if ($show['date']): ?><th scope="col"><?php echo vs_e($dateLabel); ?></th><?php endif; ?>
                    <th scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($filtered): foreach ($filtered as $p):
                    $hasUploads = ($p['videos'] + $p['shorts']) > 0;
                    [$statusLabel, $statusClass] = vs_status($p['status']);
                    $isMe = ($myId !== null && $p['id'] !== '' && (string)$myId === $p['id']);
                ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?php echo vs_avatar($p['name'], $p['avatar']); ?>
                        <div>
                          <p class="fw-semibold mb-0"><?php echo vs_e($p['name']); ?><?php if ($isMe): ?> <span class="badge text-bg-secondary">You</span><?php endif; ?></p>
                          <?php if ($p['sub'] !== ''): ?><p class="text-muted small mb-0 vs-mail"><?php echo vs_e($p['sub']); ?></p><?php endif; ?>
                        </div>
                      </div>
                    </td>
                    <?php if ($show['online']): ?>
                    <td>
                      <span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;background-color:<?php echo $p['online'] ? '#2ecc71' : '#888'; ?>;"></span>
                      <?php echo $p['online'] ? 'Online' : 'Offline'; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show['role']): ?>
                    <td>
                      <?php if ($p['is_admin']): ?>
                        <span class="badge text-bg-primary">Admin</span>
                      <?php elseif ($p['role'] !== ''): ?>
                        <?php echo vs_e(ucfirst($p['role'])); ?>
                      <?php else: ?>
                        <span class="text-muted">—</span>
                      <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php if ($show['status']): ?><td><span class="badge <?php echo $statusClass; ?>"><?php echo vs_e($statusLabel); ?></span></td><?php endif; ?>
                    <td class="text-end vs-num"><?php echo (int)$p['videos']; ?></td>
                    <td class="text-end vs-num"><?php echo (int)$p['shorts']; ?></td>
                    <td class="text-end"><?php echo number_format($p['views']); ?></td>
                    <?php if ($show['date']): ?><td class="text-muted"><?php echo $p['date'] !== '' ? vs_e($p['date']) : '—'; ?></td><?php endif; ?>
                    <td class="text-end">
                      <div class="d-inline-flex flex-wrap justify-content-end align-items-center gap-1">
                        <?php if ($hasUploads): ?>
                          <a class="btn btn-light btn-sm" href="videos.php?uploader=<?php echo urlencode($p['key']); ?>">View uploads</a>
                        <?php else: ?>
                          <span class="text-muted small">No uploads</span>
                        <?php endif; ?>

                        <?php if ($canUpdate && $p['id'] !== ''): ?>
                          <a class="btn btn-primary btn-sm" href="user_profile.php?id=<?php echo urlencode($p['id']); ?>">Update <?php echo $p['is_admin'] ? 'admin' : 'user'; ?></a>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="<?php echo (int)$colCount; ?>" class="text-center text-muted py-4">No users match these filters. <a href="users.php">Clear filters</a></td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>

            <?php if ($mode === 'users' && $unmatched): ?>
              <p class="text-muted small mt-3 mb-0">
                Uploads under names that don't match any account:
                <?php
                $bits = [];
                foreach ($unmatched as $r) {
                    $bits[] = '<a class="text-decoration-none" href="videos.php?uploader=' . urlencode($r['key']) . '">' . vs_e($r['name']) . '</a> (' . (int)$r['total'] . ')';
                }
                echo implode(', ', $bits);
                ?>.
              </p>
            <?php endif; ?>
          </section>
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