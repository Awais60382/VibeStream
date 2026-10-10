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

$presets = [
    '7'      => 'Last 7 days',
    '30'     => 'Last 30 days',
    '90'     => 'Last 90 days',
    '365'    => 'Last 12 months',
    'all'    => 'All time',
    'custom' => 'Custom dates',
];
$allSections = [
    'summary'    => 'Summary numbers',
    'daily'      => 'Breakdown over time',
    'uploaders'  => 'Top uploaders',
    'latest'     => 'Latest uploads',
    'engagement' => 'Likes and comments',
    'newusers'   => 'New users',
];

$generate = isset($_GET['generate']);
$title = trim((string)($_GET['title'] ?? ''));
$title = $title === '' ? 'VibeStream report' : mb_substr($title, 0, 80);
$range = (string)($_GET['range'] ?? '30');
if (!isset($presets[$range])) {
    $range = '30';
}
$fromIn = trim((string)($_GET['from'] ?? ''));
$toIn   = trim((string)($_GET['to'] ?? ''));
$picked = $generate
    ? array_values(array_intersect(array_keys($allSections), (array)($_GET['sections'] ?? [])))
    : array_keys($allSections);

$errors = [];
$notes  = [];
$fromTs = null;
$toTs   = null;
$rangeLabel = $presets[$range];

if ($generate) {
    if ($range === 'custom') {
        $f = DateTime::createFromFormat('Y-m-d', $fromIn);
        $t = DateTime::createFromFormat('Y-m-d', $toIn);
        if (!$f || $f->format('Y-m-d') !== $fromIn || !$t || $t->format('Y-m-d') !== $toIn) {
            $errors[] = 'Pick both dates for a custom range.';
        } else {
            $fromTs = strtotime($fromIn . ' 00:00:00');
            $toTs   = strtotime($toIn . ' 23:59:59');
            if ($fromTs > $toTs) {
                $errors[] = 'The start date must be before the end date.';
            } else {
                $rangeLabel = date('M j, Y', $fromTs) . ' to ' . date('M j, Y', $toTs);
            }
        }
    } elseif ($range !== 'all') {
        $n      = (int)$range;
        $toTs   = strtotime('today 23:59:59');
        $fromTs = strtotime('-' . ($n - 1) . ' days', strtotime('today'));
    }
    if (!$picked) {
        $errors[] = 'Tick at least one section to include.';
    }
}

$R = null; // the finished report data
if ($generate && !$errors) {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        $errors[] = 'No database connection found. Check that ../connection.php creates $pdo.';
    } else {
        try {
            $inRange = static function (int $ts) use ($fromTs, $toTs): bool {
                return ($fromTs === null || $ts >= $fromTs) && ($toTs === null || $ts <= $toTs);
            };
            $spanDays = $fromTs !== null ? (int)ceil(($toTs - $fromTs) / 86400) : null;
            $mode = ($spanDays !== null && $spanDays <= 62) ? 'day' : 'month';
            $bucketKey   = static fn(int $ts): string => $mode === 'day' ? date('Y-m-d', $ts) : date('Y-m', $ts);
            $bucketLabel = static fn(int $ts): string => $mode === 'day' ? date('D, M j', $ts) : date('F Y', $ts);
            $blank = static fn(string $label): array => ['label' => $label, 'videos' => 0, 'shorts' => 0, 'views' => 0, 'users' => 0];

            $buckets = [];
            if ($fromTs !== null) {
                $cur = $mode === 'day' ? $fromTs : strtotime(date('Y-m-01', $fromTs));
                $guard = 0;
                while ($cur <= $toTs && $guard++ < 400) {
                    $buckets[$bucketKey($cur)] = $blank($bucketLabel($cur));
                    $cur = strtotime($mode === 'day' ? '+1 day' : '+1 month', $cur);
                }
            }
            $touch = static function (int $ts) use (&$buckets, $bucketKey, $bucketLabel, $blank): string {
                $k = $bucketKey($ts);
                if (!isset($buckets[$k])) {
                    $buckets[$k] = $blank($bucketLabel($ts));
                }
                return $k;
            };

            // ---- uploads ----
            $videosN = 0;
            $shortsN = 0;
            $viewsN  = 0;
            $upl     = [];
            $ups     = [];
            foreach (['videos', 'shorts'] as $tbl) {
                $tc = vs_cols($pdo, $tbl);
                if (!$tc) {
                    continue;
                }
                $cTitle = vs_pick($tc, ['title', 'name']);
                $cWho   = vs_pick($tc, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
                $cView  = vs_pick($tc, ['views', 'view_count']);
                $cDate  = vs_pick($tc, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'date']);
                $cType  = vs_pick($tc, ['type', 'video_type']);
                if (!$cDate && $fromTs !== null) {
                    $notes[] = 'The "' . $tbl . '" table has no date column, so it was left out of this date range.';
                    continue;
                }
                $sel = [
                    $cTitle ? vs_q($cTitle) . ' AS t' : "'' AS t",
                    $cWho   ? vs_q($cWho) . ' AS w' : "'' AS w",
                    $cView  ? vs_q($cView) . ' AS v' : '0 AS v',
                    $cDate  ? vs_q($cDate) . ' AS d' : "'' AS d",
                    $cType  ? vs_q($cType) . ' AS k' : "'' AS k",
                ];
                $sql = 'SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($tbl);
                $par = [];
                if ($cDate && $fromTs !== null) {
                    $sql .= ' WHERE ' . vs_q($cDate) . ' BETWEEN :f AND :t';
                    $par = [':f' => date('Y-m-d H:i:s', $fromTs), ':t' => date('Y-m-d H:i:s', $toTs)];
                }
                $sql .= ($cDate ? ' ORDER BY ' . vs_q($cDate) . ' DESC' : '') . ' LIMIT 50000';
                $st = $pdo->prepare($sql);
                $st->execute($par);
                while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                    $ts = $cDate ? (int)strtotime((string)$row['d']) : 0;
                    if ($fromTs !== null && (!$ts || !$inRange($ts))) {
                        continue;
                    }
                    $isShort = $tbl === 'shorts' || stripos((string)$row['k'], 'short') === 0;
                    $views = max(0, (int)$row['v']);
                    $who   = trim((string)$row['w']);
                    $key   = $who === '' ? '' : mb_strtolower($who);
                    if (!isset($upl[$key])) {
                        $upl[$key] = ['name' => $who === '' ? 'Unknown creator' : $who, 'videos' => 0, 'shorts' => 0, 'views' => 0];
                    }
                    if ($isShort) {
                        $shortsN++;
                        $upl[$key]['shorts']++;
                    } else {
                        $videosN++;
                        $upl[$key]['videos']++;
                    }
                    $viewsN += $views;
                    $upl[$key]['views'] += $views;
                    if ($ts) {
                        $bk = $touch($ts);
                        $buckets[$bk][$isShort ? 'shorts' : 'videos']++;
                        $buckets[$bk]['views'] += $views;
                    }
                    $ups[] = [
                        'ts'    => $ts,
                        'title' => trim((string)$row['t']) !== '' ? trim((string)$row['t']) : 'Untitled upload',
                        'who'   => $who === '' ? 'Unknown creator' : $who,
                        'type'  => $isShort ? 'Short' : 'Video',
                        'views' => $views,
                    ];
                }
            }
            usort($ups, static fn($a, $b) => $b['ts'] <=> $a['ts']);
            uasort($upl, static fn($a, $b) => ($b['videos'] + $b['shorts']) <=> ($a['videos'] + $a['shorts']));

            // ---- users ----
            $totalUsers = null;
            $newUsers   = null;
            $activeUsers = null;
            $newList    = [];
            $uc = vs_cols($pdo, $USERS_TABLE);
            if ($uc) {
                $cJoin = vs_pick($uc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']);
                $cLast = vs_pick($uc, ['last_active', 'last_seen', 'last_login']);
                $cUser = vs_pick($uc, ['username', 'user_name', 'login', 'uname']);
                $cName = vs_pick($uc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']);
                $cRole = vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
                $sel = [
                    $cJoin ? vs_q($cJoin) . ' AS j' : "'' AS j",
                    $cLast ? vs_q($cLast) . ' AS l' : "'' AS l",
                    $cUser ? vs_q($cUser) . ' AS u' : "'' AS u",
                    $cName ? vs_q($cName) . ' AS n' : "'' AS n",
                    $cRole ? vs_q($cRole) . ' AS r' : "'' AS r",
                ];
                $st = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($USERS_TABLE) . ' LIMIT 100000');
                $totalUsers = 0;
                $newUsers = $cJoin || $range === 'all' ? 0 : null;
                $activeUsers = $cLast ? 0 : null;
                while ($st && ($row = $st->fetch(PDO::FETCH_ASSOC))) {
                    $totalUsers++;
                    $jts = $cJoin ? (int)strtotime((string)$row['j']) : 0;
                    $lts = $cLast ? (int)strtotime((string)$row['l']) : 0;
                    $isNew = $range === 'all' ? true : ($jts && $inRange($jts));
                    if ($isNew) {
                        if ($newUsers !== null) {
                            $newUsers++;
                        }
                        if ($jts) {
                            $buckets[$touch($jts)]['users']++;
                        }
                        $newList[] = [
                            'ts'   => $jts,
                            'user' => trim((string)$row['u']) !== '' ? trim((string)$row['u']) : trim((string)$row['n']),
                            'role' => trim((string)$row['r']),
                        ];
                    }
                    if ($cLast && $lts && $inRange($lts)) {
                        $activeUsers++;
                    }
                }
                usort($newList, static fn($a, $b) => $b['ts'] <=> $a['ts']);
            }
            ksort($buckets);

            $R = [
                'videos' => $videosN, 'shorts' => $shortsN, 'views' => $viewsN,
                'uploaders' => count($upl), 'topUp' => array_slice(array_values($upl), 0, 10),
                'latest' => array_slice($ups, 0, 10),
                'buckets' => $buckets, 'mode' => $mode,
                'totalUsers' => $totalUsers, 'newUsers' => $newUsers, 'activeUsers' => $activeUsers,
                'newList' => array_slice($newList, 0, 50), 'newListTotal' => count($newList),
                'eng' => in_array('engagement', $picked, true) ? vs_engagement_stats($pdo) : null,
            ];
        } catch (Throwable $e) {
            error_log('report failed: ' . $e->getMessage());
            $errors[] = 'Could not build the report. Please try again.';
        }
    }
}

include "header.php";
vs_print_styles();
?>

      <style>
        @media print {
          .no-print, nav, aside, .admin-footer, .sidebar, .admin-sidebar, .topbar, .navbar { display: none !important; }
          .dashboard-content { padding: 0 !important; margin: 0 !important; }
        }
      </style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading no-print">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Reports</p>
                <h1 class="h3 mb-1">Create report</h1>
                <p class="text-muted mb-0">Choose a time period and what to include, then generate a report you can print or save as a PDF.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
          </div>

          <?php foreach ($errors as $err): ?>
            <div class="alert alert-danger mt-3 mb-0 no-print" role="alert"><?php echo vs_e($err); ?></div>
          <?php endforeach; ?>

          <section class="panel mt-3 no-print" aria-label="Report options">
            <form method="get" action="report.php" id="reportForm">
              <input type="hidden" name="generate" value="1">
              <div class="row g-3">
                <div class="col-12 col-lg-6">
                  <label class="form-label" for="title">Report title</label>
                  <input class="form-control" type="text" id="title" name="title" maxlength="80" value="<?php echo vs_e($title === 'VibeStream report' && !$generate ? '' : $title); ?>" placeholder="VibeStream report">
                </div>
                <div class="col-12 col-lg-6">
                  <label class="form-label" for="range">Time period</label>
                  <select class="form-select" id="range" name="range">
                    <?php foreach ($presets as $k => $label): ?>
                      <option value="<?php echo vs_e($k); ?>"<?php echo $range === (string)$k ? ' selected' : ''; ?>><?php echo vs_e($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="row g-2 mt-1" id="customDates"<?php echo $range === 'custom' ? '' : ' hidden'; ?>>
                    <div class="col-6"><input class="form-control" type="date" name="from" value="<?php echo vs_e($fromIn); ?>" aria-label="From date"></div>
                    <div class="col-6"><input class="form-control" type="date" name="to" value="<?php echo vs_e($toIn); ?>" aria-label="To date"></div>
                  </div>
                </div>
                <div class="col-12">
                  <span class="form-label d-block">Include</span>
                  <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($allSections as $k => $label): ?>
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="sec_<?php echo vs_e($k); ?>" name="sections[]" value="<?php echo vs_e($k); ?>"<?php echo in_array($k, $picked, true) ? ' checked' : ''; ?>>
                        <label class="form-check-label" for="sec_<?php echo vs_e($k); ?>"><?php echo vs_e($label); ?></label>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <div class="col-12">
                  <button class="btn btn-primary" type="submit"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Generate report</button>
                </div>
              </div>
            </form>
          </section>

          <?php if ($R !== null): ?>
          <section class="panel mt-3" aria-label="Report">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <h2 class="h4 mb-1"><?php echo vs_e($title); ?></h2>
                <p class="text-muted mb-0"><?php echo vs_e($rangeLabel); ?> &middot; generated <?php echo vs_e(date('M j, Y H:i')); ?> by <?php echo vs_e($_SESSION['AdminSession'] ?? 'Admin'); ?></p>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm no-print" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print / Save as PDF</button>
            </div>

            <?php foreach ($notes as $note): ?>
              <div class="alert alert-warning mt-2 mb-0" role="status"><?php echo vs_e($note); ?></div>
            <?php endforeach; ?>

            <?php if (in_array('summary', $picked, true)): ?>
              <?php
                $cards = [
                    ['Videos uploaded', number_format($R['videos'])],
                    ['Shorts uploaded', number_format($R['shorts'])],
                    ['Views on those uploads', number_format($R['views'])],
                    ['Uploaders', number_format($R['uploaders'])],
                    ['New users', $R['newUsers'] === null ? 'n/a' : number_format($R['newUsers'])],
                    ['Active users', $R['activeUsers'] === null ? 'n/a' : number_format($R['activeUsers'])],
                ];
              ?>
              <div class="row g-3 mt-1">
                <?php foreach ($cards as [$cl, $cv]): ?>
                  <div class="col-6 col-lg-4">
                    <div class="border rounded-3 p-3 h-100">
                      <p class="text-muted small mb-1"><?php echo vs_e($cl); ?></p>
                      <p class="h4 mb-0 vs-num"><?php echo vs_e($cv); ?></p>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <p class="text-muted small mt-2 mb-0">
                <?php if ($R['totalUsers'] !== null): ?>Registered users in total: <strong><?php echo number_format($R['totalUsers']); ?></strong>. <?php endif; ?>
                Active users are people last seen inside this period.
              </p>
            <?php endif; ?>

            <?php if (in_array('daily', $picked, true)): ?>
              <h3 class="h5 mt-4 mb-2"><?php echo $R['mode'] === 'day' ? 'Day by day' : 'Month by month'; ?></h3>
              <?php if (!$R['buckets']): ?>
                <p class="text-muted mb-0">Nothing happened in this period.</p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col"><?php echo $R['mode'] === 'day' ? 'Day' : 'Month'; ?></th><th scope="col" class="text-end">Videos</th><th scope="col" class="text-end">Shorts</th><th scope="col" class="text-end">Views</th><th scope="col" class="text-end">New users</th></tr></thead>
                  <tbody>
                  <?php foreach ($R['buckets'] as $b): ?>
                    <tr>
                      <td><?php echo vs_e($b['label']); ?></td>
                      <td class="text-end vs-num"><?php echo (int)$b['videos']; ?></td>
                      <td class="text-end vs-num"><?php echo (int)$b['shorts']; ?></td>
                      <td class="text-end vs-num"><?php echo number_format($b['views']); ?></td>
                      <td class="text-end vs-num"><?php echo (int)$b['users']; ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array('uploaders', $picked, true)): ?>
              <h3 class="h5 mt-4 mb-2">Top uploaders</h3>
              <?php if (!$R['topUp']): ?>
                <p class="text-muted mb-0">No uploads in this period.</p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">Uploader</th><th scope="col" class="text-end">Videos</th><th scope="col" class="text-end">Shorts</th><th scope="col" class="text-end">Views</th></tr></thead>
                  <tbody>
                  <?php foreach ($R['topUp'] as $u): ?>
                    <tr>
                      <td><?php echo vs_e($u['name']); ?></td>
                      <td class="text-end vs-num"><?php echo (int)$u['videos']; ?></td>
                      <td class="text-end vs-num"><?php echo (int)$u['shorts']; ?></td>
                      <td class="text-end vs-num"><?php echo number_format($u['views']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array('latest', $picked, true)): ?>
              <h3 class="h5 mt-4 mb-2">Latest uploads in this period</h3>
              <?php if (!$R['latest']): ?>
                <p class="text-muted mb-0">No uploads in this period.</p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">Title</th><th scope="col">Creator</th><th scope="col">Type</th><th scope="col" class="text-end">Views</th><th scope="col" class="text-end">Published</th></tr></thead>
                  <tbody>
                  <?php foreach ($R['latest'] as $c): ?>
                    <tr>
                      <td><?php echo vs_e($c['title']); ?></td>
                      <td><?php echo vs_e($c['who']); ?></td>
                      <td><?php echo vs_e($c['type']); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($c['views']); ?></td>
                      <td class="text-end text-muted"><?php echo $c['ts'] ? vs_e(date('M j, Y H:i', $c['ts'])) : '-'; ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array('engagement', $picked, true)): ?>
              <h3 class="h5 mt-4 mb-2">Likes and comments <span class="text-muted small">(all time)</span></h3>
              <?php if ($R['eng'] === null || $R['eng']['error'] !== ''): ?>
                <p class="text-muted mb-0"><?php echo vs_e($R['eng']['error'] ?? 'Not available.'); ?></p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">Type</th><th scope="col" class="text-end">Uploaded</th><th scope="col" class="text-end">Total likes</th><th scope="col" class="text-end">Total comments</th></tr></thead>
                  <tbody>
                  <?php foreach (['Videos' => $R['eng']['video'], 'Shorts' => $R['eng']['short']] as $lbl => $e): ?>
                    <tr>
                      <td><?php echo vs_e($lbl); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['items']); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['likes']); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['comments']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <p class="text-muted small mt-2 mb-0">Likes and comments are not stored with dates, so these totals cover everything, not just this period.</p>
              <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array('newusers', $picked, true)): ?>
              <h3 class="h5 mt-4 mb-2">New users in this period</h3>
              <?php if (!$R['newList']): ?>
                <p class="text-muted mb-0"><?php echo $R['newUsers'] === null ? 'The users table has no join date column, so new users cannot be listed.' : 'No new users in this period.'; ?></p>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">User</th><th scope="col">Role</th><th scope="col" class="text-end">Joined</th></tr></thead>
                  <tbody>
                  <?php foreach ($R['newList'] as $nu): ?>
                    <tr>
                      <td><?php echo vs_e($nu['user'] !== '' ? $nu['user'] : 'Unnamed'); ?></td>
                      <td><?php echo vs_e($nu['role'] !== '' ? $nu['role'] : '-'); ?></td>
                      <td class="text-end text-muted"><?php echo $nu['ts'] ? vs_e(date('M j, Y', $nu['ts'])) : '-'; ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if ($R['newListTotal'] > count($R['newList'])): ?>
                <p class="text-muted small mt-2 mb-0">Showing the newest <?php echo count($R['newList']); ?> of <?php echo number_format($R['newListTotal']); ?>.</p>
              <?php endif; ?>
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

  <script>
    (function () {
      var sel = document.getElementById('range');
      var box = document.getElementById('customDates');
      if (!sel || !box) return;
      sel.addEventListener('change', function () { box.hidden = (sel.value !== 'custom'); });
    })();
  </script>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>
