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
(static function (): void {
    $dirs = [__DIR__ . '/..', __DIR__];
    foreach ($dirs as $d) {
        $f = $d . '/report-helpers.php';
        if (is_file($f)) { require_once $f; return; }
    }
    foreach ($dirs as $d) {                      // tolerate names like 123_report-helpers.php
        $hits = glob($d . '/*report-helpers.php') ?: [];
        if ($hits) { require_once $hits[0]; return; }
    }
    http_response_code(500);
    exit('report-helpers.php was not found. Put it in ' . htmlspecialchars(realpath(__DIR__ . '/..') ?: __DIR__ . '/..') . ' (the main VibeStream folder).');
})();

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];
$me   = trim((string)($_SESSION['AdminSession'] ?? 'Admin'));

$status = (string)($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'reviewed', 'all'], true)) {
    $status = 'open';
}
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'video', 'short'], true)) {
    $type = 'all';
}

$reasons = vs_report_reasons();
$fatal   = '';
$groups  = [];
$counts  = ['open' => 0, 'videos' => 0, 'shorts' => 0, 'total' => 0];

$msgs = [
    'dismissed' => 'Report dismissed. The video stays online.',
    'removed'   => 'Video removed and its reports closed.',
    'expired'   => 'Session expired. Try again.',
    'failed'    => 'Something went wrong. Please try again.',
];

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $pdo.';
} else {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        vs_reports_ensure_table($pdo);

        // ---- actions ----
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code   = 'failed';
            $action = (string)($_POST['action'] ?? '');
            $tbl    = (string)($_POST['table'] ?? '');
            $cid    = trim((string)($_POST['id'] ?? ''));
            $now    = date('Y-m-d H:i:s');

            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                $code = 'expired';
            } elseif (!in_array($tbl, ['videos', 'shorts'], true) || $cid === '') {
                $code = 'failed';
            } elseif ($action === 'dismiss') {
                $up = $pdo->prepare(
                    "UPDATE `content_reports` SET `status` = 'dismissed', `reviewed_by` = :b, `reviewed_at` = :a
                     WHERE `content_table` = :t AND `content_id` = :i AND `status` = 'open'"
                );
                $up->execute([':b' => $me, ':a' => $now, ':t' => $tbl, ':i' => $cid]);
                $code = 'dismissed';
            } elseif ($action === 'remove') {
                $c   = vs_cols($pdo, $tbl);
                $idC = $c ? vs_pick($c, ['id', 'video_id']) : null;
                if ($idC) {
                    $pdo->beginTransaction();
                    $del = $pdo->prepare('DELETE FROM ' . vs_q($tbl) . ' WHERE ' . vs_q($idC) . ' = :i LIMIT 1');
                    $del->execute([':i' => $cid]);
                    $up = $pdo->prepare(
                        "UPDATE `content_reports` SET `status` = 'removed', `reviewed_by` = :b, `reviewed_at` = :a
                         WHERE `content_table` = :t AND `content_id` = :i AND `status` = 'open'"
                    );
                    $up->execute([':b' => $me, ':a' => $now, ':t' => $tbl, ':i' => $cid]);
                    $pdo->commit();
                    $code = 'removed';
                }
            }
            header('Location: reported.php?status=' . urlencode($status) . '&type=' . urlencode($type) . '&msg=' . urlencode($code));
            exit;
        }

        // ---- numbers for the cards ----
        $counts['open']  = (int)$pdo->query("SELECT COUNT(*) FROM `content_reports` WHERE `status` = 'open'")->fetchColumn();
        $counts['total'] = (int)$pdo->query('SELECT COUNT(*) FROM `content_reports`')->fetchColumn();
        $counts['videos'] = (int)$pdo->query("SELECT COUNT(DISTINCT `content_table`, `content_id`) FROM `content_reports` WHERE `status` = 'open' AND `content_type` = 'video'")->fetchColumn();
        $counts['shorts'] = (int)$pdo->query("SELECT COUNT(DISTINCT `content_table`, `content_id`) FROM `content_reports` WHERE `status` = 'open' AND `content_type` = 'short'")->fetchColumn();

        // ---- the list ----
        $where  = [];
        $params = [];
        if ($status === 'open') {
            $where[] = "`status` = 'open'";
        } elseif ($status === 'reviewed') {
            $where[] = "`status` <> 'open'";
        }
        if ($type !== 'all') {
            $where[] = '`content_type` = :ty';
            $params[':ty'] = $type;
        }
        $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $st = $pdo->prepare(
            'SELECT `id`, `content_table`, `content_id`, `content_type`, `reason`, `details`, `reporter`, `status`, `created_at`
             FROM `content_reports`' . $w . ' ORDER BY `created_at` DESC LIMIT 3000'
        );
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['content_table'] . '|' . $r['content_id'];
            if (!isset($groups[$k])) {
                $groups[$k] = [
                    'table' => $r['content_table'], 'id' => $r['content_id'], 'type' => $r['content_type'],
                    'reports' => [], 'open' => 0, 'last' => $r['created_at'], 'reasons' => [], 'status' => $r['status'],
                ];
            }
            $groups[$k]['reports'][] = $r;
            if ($r['status'] === 'open') {
                $groups[$k]['open']++;
            }
            $groups[$k]['reasons'][$r['reason']] = ($groups[$k]['reasons'][$r['reason']] ?? 0) + 1;
        }
        uasort($groups, static function (array $a, array $b): int {
            return [$b['open'] > 0, count($b['reports']), $b['last']] <=> [$a['open'] > 0, count($a['reports']), $a['last']];
        });
        $groups = array_slice($groups, 0, 100, true);
        foreach ($groups as $k => $g) {
            $groups[$k]['info'] = vs_reports_lookup($pdo, $g['table'], $g['id']);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('reported.php failed: ' . $e->getMessage());
        $fatal = 'Could not load the reports. Please try again.';
    }
}

$flash = $msgs[$_GET['msg'] ?? ''] ?? '';
$qs = static fn(array $over): string => 'reported.php?' . http_build_query(array_merge(['status' => $GLOBALS['status'], 'type' => $GLOBALS['type']], $over));

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-flag" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Safety</p>
                <h1 class="h3 mb-1">Reported content</h1>
                <p class="text-muted mb-0">Videos and shorts that people on the main site reported as harmful.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info mt-3 mb-0" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <?php if ($fatal !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($fatal); ?></div>
          <?php else: ?>

          <div class="row g-3 mt-1">
            <?php foreach ([['Open reports', $counts['open']], ['Videos reported', $counts['videos']], ['Shorts reported', $counts['shorts']], ['All reports ever', $counts['total']]] as [$cl, $cv]): ?>
              <div class="col-6 col-xl-3">
                <div class="border rounded-3 p-3 h-100">
                  <p class="text-muted small mb-1"><?php echo vs_e($cl); ?></p>
                  <p class="h3 mb-0 vs-num"><?php echo number_format($cv); ?></p>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <section class="panel mt-3" aria-label="Reported videos and shorts">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-flag" aria-hidden="true"></i><span>Reports</span></h2>
                <p class="text-muted mb-0">Showing <?php echo count($groups); ?> item<?php echo count($groups) === 1 ? '' : 's'; ?>, most reported first.</p>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Status">
                  <?php foreach (['open' => 'Open', 'reviewed' => 'Reviewed', 'all' => 'All'] as $sk => $sl): ?>
                    <a class="btn <?php echo $status === $sk ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['status' => $sk])); ?>"><?php echo vs_e($sl); ?></a>
                  <?php endforeach; ?>
                </div>
                <div class="btn-group btn-group-sm" role="group" aria-label="Type">
                  <?php foreach (['all' => 'Videos and shorts', 'video' => 'Videos', 'short' => 'Shorts'] as $tk => $tl): ?>
                    <a class="btn <?php echo $type === $tk ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['type' => $tk])); ?>"><?php echo vs_e($tl); ?></a>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <?php if (!$groups): ?>
              <p class="text-center text-muted py-4 mb-0">Nothing here. No <?php echo $status === 'open' ? 'open ' : ''; ?>reports<?php echo $type !== 'all' ? ' for ' . ($type === 'short' ? 'shorts' : 'videos') : ''; ?>.</p>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">Content</th>
                    <th scope="col">Creator</th>
                    <th scope="col">Type</th>
                    <th scope="col">Reports</th>
                    <th scope="col" class="vs-hide-sm">Reasons</th>
                    <th scope="col" class="text-end">Last reported</th>
                    <th scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g):
                    $info = $g['info'];
                    $isOpen = $g['open'] > 0;
                ?>
                  <tr>
                    <td>
                      <strong><?php echo $info ? vs_e($info['title']) : 'Removed or not found'; ?></strong>
                      <p class="text-muted small mb-0">ID <?php echo vs_e($g['id']); ?></p>
                    </td>
                    <td><?php echo $info && $info['who'] !== '' ? vs_e($info['who']) : '-'; ?></td>
                    <td><span class="badge <?php echo $g['type'] === 'short' ? 'text-bg-info' : 'text-bg-primary'; ?>"><?php echo $g['type'] === 'short' ? 'Short' : 'Video'; ?></span></td>
                    <td>
                      <details>
                        <summary><span class="badge <?php echo $isOpen ? 'text-bg-danger' : 'text-bg-secondary'; ?>"><?php echo count($g['reports']); ?></span> <?php echo $isOpen ? 'open' : 'reviewed'; ?></summary>
                        <ul class="list-unstyled small mt-2 mb-0">
                          <?php foreach ($g['reports'] as $r): ?>
                            <li class="mb-2">
                              <strong><?php echo vs_e($reasons[$r['reason']] ?? $r['reason']); ?></strong>
                              by <?php echo vs_e($r['reporter']); ?>
                              <span class="text-muted">(<?php echo vs_e(vs_ago($r['created_at'])); ?><?php echo $r['status'] !== 'open' ? ', ' . vs_e($r['status']) : ''; ?>)</span>
                              <?php if ($r['details'] !== ''): ?><br><span class="text-muted"><?php echo vs_e($r['details']); ?></span><?php endif; ?>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </details>
                    </td>
                    <td class="vs-hide-sm">
                      <?php foreach ($g['reasons'] as $rk => $rn): ?>
                        <span class="badge text-bg-light"><?php echo vs_e($reasons[$rk] ?? $rk); ?><?php echo $rn > 1 ? ' x' . (int)$rn : ''; ?></span>
                      <?php endforeach; ?>
                    </td>
                    <td class="text-end text-muted"><?php echo vs_e(vs_ago($g['last'])); ?></td>
                    <td class="text-end">
                      <?php if ($isOpen): ?>
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                          <form method="post" action="<?php echo vs_e($qs([])); ?>" class="d-inline">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="dismiss">
                            <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>">
                            <input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                            <button type="submit" class="btn btn-light btn-sm">Dismiss</button>
                          </form>
                          <?php if ($info): ?>
                          <form method="post" action="<?php echo vs_e($qs([])); ?>" class="d-inline" onsubmit="return confirm('Remove this <?php echo $g['type'] === 'short' ? 'short' : 'video'; ?> from the site? This cannot be undone.');">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="table" value="<?php echo vs_e($g['table']); ?>">
                            <input type="hidden" name="id" value="<?php echo vs_e($g['id']); ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                          </form>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <span class="text-muted small"><?php echo vs_e(ucfirst($g['status'])); ?></span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
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