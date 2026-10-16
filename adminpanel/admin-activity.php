<?php
/*
 * VibeStream admin panel - Admin activity (SITE OWNER ONLY).
 * Shows what every admin has been doing: sessions, pages opened, Premium actions.
 * Anyone who is not the owner is sent away and never sees this page exists.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    header('Location: ../login.php');
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

$hasDb = isset($pdo) && $pdo instanceof PDO;

/* ---------------------------------------------------------------
   1. OWNER ONLY: look up the logged-in admin's email and compare it
      with the site owner (the same check the sidebar uses).
---------------------------------------------------------------- */
$isOwner = false;
$uc = [];
$idCol = $unameCol = $nameCol = $emailCol = $roleCol = $laCol = null;
if ($hasDb) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $uc = vs_cols($pdo, 'users') ?: [];
        $idCol    = vs_pick($uc, ['id', 'user_id']);
        $unameCol = vs_pick($uc, ['username', 'user_name', 'login', 'uname']);
        $nameCol  = vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name']);
        $emailCol = vs_pick($uc, ['email', 'email_address', 'mail']);
        $roleCol  = vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
        $laCol    = vs_pick($uc, ['last_active', 'last_seen', 'last_login']);

        $matchCol = null;
        $matchVal = null;
        if ($idCol && !empty($_SESSION['AdminID'])) {
            $matchCol = $idCol;
            $matchVal = $_SESSION['AdminID'];
        } elseif ($unameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $unameCol;
            $matchVal = $_SESSION['AdminSession'];
        } elseif ($nameCol && !empty($_SESSION['AdminSession'])) {
            $matchCol = $nameCol;
            $matchVal = $_SESSION['AdminSession'];
        }
        if ($emailCol && $matchCol) {
            $st = $pdo->prepare("SELECT `$emailCol` FROM `users` WHERE `$matchCol` = :v LIMIT 1");
            $st->execute([':v' => $matchVal]);
            $myEmail = (string)$st->fetchColumn();
            $isOwner = $myEmail !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($myEmail);
        }
    } catch (Throwable $e) {
        $isOwner = false;
    }
}
if (!$isOwner) {
    header('Location: index.php');
    exit;
}

/* The owner's own activity is never shown here: collect every name the owner account goes by.
   This page is owner-only, so whoever is looking at it IS the owner: every name in their session counts. */
$ownerNames = [];
$addOwner = static function ($n) use (&$ownerNames): void {
    $n = mb_strtolower(trim((string)$n));
    if ($n !== '') {
        $ownerNames[$n] = true;
    }
};
foreach (['username', 'AdminSession', 'name', 'user_name', 'full_name', 'display_name', 'AdminName'] as $sk) {
    if (isset($_SESSION[$sk]) && is_scalar($_SESSION[$sk])) {
        $addOwner($_SESSION[$sk]);
    }
}
$addOwner(vs_activity_session_name());
try {
    // Every account whose email is the owner email (any role), under every name column.
    if ($emailCol) {
        $nameCols = array_values(array_unique(array_filter([$unameCol, $nameCol])));
        if ($nameCols) {
            $osql = 'SELECT ' . implode(', ', array_map(static fn($c) => "`$c`", $nameCols)) . ", `$emailCol` AS e FROM `users`";
            foreach ($pdo->query($osql)->fetchAll(PDO::FETCH_ASSOC) as $ou) {
                $oe = trim((string)$ou['e']);
                if ($oe !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($oe)) {
                    foreach ($nameCols as $nc) {
                        $addOwner($ou[$nc] ?? '');
                    }
                }
            }
        }
    }
} catch (Throwable $e) {
    // keep whatever was found
}
$ownerNames = array_keys($ownerNames);
$exArgs = [];
$exPh = [];
foreach ($ownerNames as $i => $on) {
    $exPh[] = ':ex' . $i;
    $exArgs[':ex' . $i] = $on;
}
$exWhere = $exPh ? 'LOWER(admin_username) NOT IN (' . implode(',', $exPh) . ')' : '1=1';

/* ---------------------------------------------------------------
   2. FILTERS
---------------------------------------------------------------- */
$fAdmin = trim((string)($_GET['admin'] ?? ''));
$fType  = trim((string)($_GET['type'] ?? ''));
$fRange = (string)($_GET['range'] ?? '7d');
if (!in_array($fRange, ['1d', '7d', '30d', 'all'], true)) {
    $fRange = '7d';
}
$fQ   = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;

$where = [];
$args = [];
if ($exPh) {
    $where[] = $exWhere;
    $args = $exArgs;
}
if ($fAdmin !== '') {
    $where[] = 'admin_username = :admin';
    $args[':admin'] = $fAdmin;
}
if ($fType !== '') {
    $where[] = 'action = :type';
    $args[':type'] = $fType;
}
if ($fRange === '1d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)';
} elseif ($fRange === '7d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($fRange === '30d') {
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}
if ($fQ !== '') {
    $where[] = '(details LIKE :q OR admin_username LIKE :q OR page LIKE :q)';
    $args[':q'] = '%' . $fQ . '%';
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$qs = static function (array $over = []) use ($fAdmin, $fType, $fRange, $fQ): string {
    $p = array_merge(['admin' => $fAdmin, 'type' => $fType, 'range' => $fRange, 'q' => $fQ], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
};

/* ---------------------------------------------------------------
   3. LOAD DATA
---------------------------------------------------------------- */
$dbError = '';
$rows = [];
$total = 0;
$types = [];
$admins = [];
$stToday = 0;
$stWeek = 0;
$stOnline = 0;
$suggest = [];

try {
    vs_activity_ensure($pdo);

    // CSV export of the current filter
    if (($_GET['download'] ?? '') === 'csv') {
        $st = $pdo->prepare('SELECT created_at, admin_username, action, details, page, ip FROM admin_activity_log' . $whereSql . ' ORDER BY id DESC LIMIT 5000');
        $st->execute($args);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="admin-activity-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Time', 'Admin', 'Activity', 'Details', 'Page', 'IP']);
        while ($r = $st->fetch(PDO::FETCH_NUM)) {
            // Stop =, +, -, @ at the start of a cell from running as a spreadsheet formula.
            fputcsv($out, array_map(static function ($v): string {
                $s = (string)$v;
                return ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) ? "'" . $s : $s;
            }, $r));
        }
        exit;
    }

    $cs = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log' . $whereSql);
    $cs->execute($args);
    $total = (int)$cs->fetchColumn();
    $pages = max(1, (int)ceil($total / $per));
    $page = min($page, $pages);

    $ls = $pdo->prepare('SELECT * FROM admin_activity_log' . $whereSql . ' ORDER BY id DESC LIMIT ' . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $ls->execute($args);
    $rows = $ls->fetchAll(PDO::FETCH_ASSOC);

    $ts = $pdo->prepare('SELECT DISTINCT action FROM admin_activity_log WHERE ' . $exWhere . ' ORDER BY action');
    $ts->execute($exArgs);
    $types = $ts->fetchAll(PDO::FETCH_COLUMN);
    $ts = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= CURDATE() AND ' . $exWhere);
    $ts->execute($exArgs);
    $stToday = (int)$ts->fetchColumn();
    $ts = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND ' . $exWhere);
    $ts->execute($exArgs);
    $stWeek = (int)$ts->fetchColumn();

    // Search suggestions (admin names, pages and recent activity text; the owner is left out)
    $suggest = [];
    $ds = $pdo->prepare('SELECT details, MAX(id) AS m FROM admin_activity_log WHERE details <> \'\' AND ' . $exWhere . ' GROUP BY details ORDER BY m DESC LIMIT 40');
    $ds->execute($exArgs);
    foreach ($ds->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $suggest[] = (string)$d['details'];
    }

    // Per-admin numbers from the log
    $perAdmin = [];
    $ps = $pdo->prepare(
        'SELECT admin_username, COUNT(*) AS total,
                SUM(created_at >= CURDATE()) AS today, MAX(created_at) AS last_at
         FROM admin_activity_log WHERE ' . $exWhere . ' GROUP BY admin_username'
    );
    $ps->execute($exArgs);
    foreach ($ps->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $perAdmin[mb_strtolower((string)$r['admin_username'])] = $r;
    }

    // Everyone with the Admin role, with their last-active time
    $list = [];
    if ($unameCol && $roleCol) {
        $sel = "`$unameCol` AS f_name" . ($emailCol ? ", `$emailCol` AS f_email" : '') . ($laCol ? ", `$laCol` AS f_la" : '');
        $us = $pdo->prepare("SELECT $sel FROM `users` WHERE LOWER(`$roleCol`) = 'admin' ORDER BY `$unameCol` ASC");
        $us->execute();
        foreach ($us->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $list[mb_strtolower(trim((string)$u['f_name']))] = $u;
        }
    }
    // Admins that only appear in the log (for example a renamed account)
    foreach ($perAdmin as $k => $r) {
        if (!isset($list[$k])) {
            $list[$k] = ['f_name' => $r['admin_username'], 'f_email' => '', 'f_la' => null];
        }
    }
    foreach ($list as $k => $u) {
        $name = trim((string)$u['f_name']);
        if ($name === '' || in_array($k, $ownerNames, true)) {
            continue;
        }
        $email = trim((string)($u['f_email'] ?? ''));
        $laTs = !empty($u['f_la']) ? strtotime((string)$u['f_la']) : 0;
        $logRow = $perAdmin[$k] ?? null;
        $lastLog = $logRow ? strtotime((string)$logRow['last_at']) : 0;
        $online = $laTs && (time() - $laTs) < 300;
        if ($online) {
            $stOnline++;
        }
        $admins[] = [
            'name'    => $name,
            'owner'   => $email !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($email),
            'online'  => $online,
            'last_ts' => max($laTs, $lastLog),
            'today'   => $logRow ? (int)$logRow['today'] : 0,
            'total'   => $logRow ? (int)$logRow['total'] : 0,
        ];
    }
    usort($admins, static fn($a, $b) => $b['last_ts'] <=> $a['last_ts']);
    foreach ($admins as $a) {
        $suggest[] = $a['name'];
    }
} catch (Throwable $e) {
    error_log('admin-activity.php failed: ' . $e->getMessage());
    $dbError = 'Could not load the admin activity.';
}

/* ---------------------------------------------------------------
   4. SMALL HELPERS FOR THE PAGE
---------------------------------------------------------------- */
$ago = static function (int $ts): string {
    if ($ts <= 0) {
        return 'never';
    }
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return (int)floor($d / 60) . ' min ago';
    if ($d < 86400)  return (int)floor($d / 3600) . ' h ago';
    if ($d < 604800) return (int)floor($d / 86400) . ' d ago';
    return date('M j, Y', $ts);
};
$actionLabel = static function (string $a): array {
    $map = [
        'session'   => ['Signed in',  'text-bg-info'],
        'page_view' => ['Page view',  'text-bg-secondary'],
        'premium'   => ['Premium',    'text-bg-warning'],
        'video'     => ['Video',      'text-bg-primary'],
        'user'      => ['User',       'text-bg-success'],
        'settings'  => ['Settings',   'text-bg-dark'],
    ];
    return $map[$a] ?? [ucfirst(str_replace('_', ' ', $a)), 'text-bg-light'];
};
$pmInitial = static function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'A', 0, 1));
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
        . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
        . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
    );
};

include "header.php";
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner only</p>
                <h1 class="h3 mb-1">Admin activity</h1>
                <p class="text-muted mb-0">What your admins have been doing in the admin panel. Only you can see this page.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo vs_e($qs(['download' => 'csv'])); ?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
            </div>
          </div>

          <?php if ($dbError !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($dbError); ?></div>
          <?php else: ?>

          <section class="row g-3 mt-1" aria-label="Activity metrics">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top"><span class="metric-label">Admins</span><span class="metric-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span></div>
                <div class="metric-value"><?php echo number_format(count($admins)); ?></div>
                <div class="metric-meta"><span>with admin access</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-success">
                <div class="metric-top"><span class="metric-label">Online now</span><span class="metric-icon"><i class="bi bi-broadcast" aria-hidden="true"></i></span></div>
                <div class="metric-value"><?php echo number_format($stOnline); ?></div>
                <div class="metric-meta"><span>active in the last 5 minutes</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top"><span class="metric-label">Actions today</span><span class="metric-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span></div>
                <div class="metric-value"><?php echo number_format($stToday); ?></div>
                <div class="metric-meta"><span>logged since midnight</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top"><span class="metric-label">Last 7 days</span><span class="metric-icon"><i class="bi bi-calendar-week" aria-hidden="true"></i></span></div>
                <div class="metric-value"><?php echo number_format($stWeek); ?></div>
                <div class="metric-meta"><span>actions in total</span></div>
              </article>
            </div>
          </section>

          <section class="panel mt-3" aria-label="Admins">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Admins</span></h2>
              <p class="text-muted mb-0">Click an admin to see only their activity.</p>
            </div>
            <?php if (!$admins): ?>
              <p class="text-center text-muted py-4 mb-0">No admins found yet.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">Admin</th><th scope="col">Status</th><th scope="col">Last activity</th><th scope="col" class="text-end">Today</th><th scope="col" class="text-end">All time</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($admins as $a): ?>
                      <tr>
                        <td>
                          <a class="table-media text-decoration-none" href="<?php echo vs_e($qs(['admin' => $a['name'], 'page' => ''])); ?>">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url($a['name'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($a['name'])); ?>';">
                            <strong class="content-title"><?php echo vs_e($a['name']); ?></strong>
                            <?php if ($a['owner']): ?><span class="badge text-bg-primary ms-1">Owner</span><?php endif; ?>
                          </a>
                        </td>
                        <td><span class="badge <?php echo $a['online'] ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $a['online'] ? 'Online' : 'Offline'; ?></span></td>
                        <td class="text-muted"><?php echo vs_e($ago((int)$a['last_ts'])); ?></td>
                        <td class="text-end"><?php echo number_format($a['today']); ?></td>
                        <td class="text-end"><?php echo number_format($a['total']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3" aria-label="Activity feed">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-list-ul" aria-hidden="true"></i><span>Activity</span></h2>
                <p class="text-muted mb-0"><?php echo number_format($total); ?> result<?php echo $total === 1 ? '' : 's'; ?>, newest first.</p>
              </div>
              <form method="get" class="d-flex gap-2 flex-wrap">
                <select class="form-select form-select-sm" name="admin" aria-label="Admin" style="width:auto">
                  <option value="">All admins</option>
                  <?php foreach ($admins as $a): ?>
                    <option value="<?php echo vs_e($a['name']); ?>"<?php echo $fAdmin === $a['name'] ? ' selected' : ''; ?>><?php echo vs_e($a['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="type" aria-label="Type" style="width:auto">
                  <option value="">All activity</option>
                  <?php foreach ($types as $t): ?>
                    <option value="<?php echo vs_e($t); ?>"<?php echo $fType === $t ? ' selected' : ''; ?>><?php echo vs_e($actionLabel((string)$t)[0]); ?></option>
                  <?php endforeach; ?>
                </select>
                <select class="form-select form-select-sm" name="range" aria-label="Time range" style="width:auto">
                  <option value="1d"<?php echo $fRange === '1d' ? ' selected' : ''; ?>>Last 24 hours</option>
                  <option value="7d"<?php echo $fRange === '7d' ? ' selected' : ''; ?>>Last 7 days</option>
                  <option value="30d"<?php echo $fRange === '30d' ? ' selected' : ''; ?>>Last 30 days</option>
                  <option value="all"<?php echo $fRange === 'all' ? ' selected' : ''; ?>>All time</option>
                </select>
                <input type="search" class="form-control form-control-sm" name="q" value="<?php echo vs_e($fQ); ?>" placeholder="Search" aria-label="Search activity" list="activity-suggest" autocomplete="off" style="max-width:180px">
                <datalist id="activity-suggest">
                  <?php foreach (array_values(array_unique($suggest)) as $sg): ?>
                    <option value="<?php echo vs_e($sg); ?>"></option>
                  <?php endforeach; ?>
                </datalist>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <?php if ($fAdmin !== '' || $fType !== '' || $fQ !== '' || $fRange !== '7d'): ?>
                  <a class="btn btn-outline-secondary btn-sm" href="admin-activity.php">Reset</a>
                <?php endif; ?>
              </form>
            </div>

            <?php if (!$rows): ?>
              <p class="text-center text-muted py-4 mb-0">No activity matches these filters.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">When</th><th scope="col">Admin</th><th scope="col">Activity</th><th scope="col">Details</th><th scope="col">IP</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($rows as $r):
                        [$label, $badge] = $actionLabel((string)$r['action']);
                        $ts = strtotime((string)$r['created_at']);
                    ?>
                      <tr>
                        <td class="text-muted small text-nowrap"><?php echo vs_e(date('M j, g:i A', $ts)); ?><div><?php echo vs_e($ago($ts)); ?></div></td>
                        <td>
                          <div class="table-media">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url((string)$r['admin_username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial((string)$r['admin_username'])); ?>';">
                            <strong class="content-title"><?php echo vs_e($r['admin_username']); ?></strong>
                          </div>
                        </td>
                        <td><span class="badge <?php echo vs_e($badge); ?>"><?php echo vs_e($label); ?></span></td>
                        <td><?php echo vs_e($r['details']); ?></td>
                        <td class="text-muted small"><?php echo vs_e($r['ip']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($pages > 1): ?>
                <div class="d-flex align-items-center justify-content-between gap-2 p-3">
                  <span class="text-muted small">Page <?php echo (int)$page; ?> of <?php echo (int)$pages; ?></span>
                  <div class="btn-group btn-group-sm">
                    <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="<?php echo vs_e($qs(['page' => $page - 1])); ?>">Newer</a><?php endif; ?>
                    <?php if ($page < $pages): ?><a class="btn btn-outline-secondary" href="<?php echo vs_e($qs(['page' => $page + 1])); ?>">Older</a><?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
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