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
require_once __DIR__ . '/../premium-store.php';

if (empty($_SESSION['premium_csrf'])) {
    $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['premium_csrf'];
$hasDb = isset($pdo) && $pdo instanceof PDO;
$dbError = '';
$members = [];
$requests = [];

if ($hasDb) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        vs_premium_ensure($pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                header('Location: premium.php?msg=expired');
                exit;
            }
            $act = (string)($_POST['action'] ?? '');

            // Approve or reject a payment request
            if ($act === 'approve' || $act === 'reject') {
                $done = vs_premium_review($pdo, (int)($_POST['id'] ?? 0), $act === 'approve');
                header('Location: premium.php?msg=' . ($done ? ($act === 'approve' ? 'approved' : 'rejected') : 'handled'));
                exit;
            }

            // Grant or revoke Premium by hand
            $who = trim((string)($_POST['username'] ?? ''));
            if ($act === 'revoke' && $who !== '') {
                vs_premium_cancel($pdo, $who);
                header('Location: premium.php?msg=revoked');
                exit;
            }
            if ($act === 'grant' && $who !== '') {
                $ok = vs_premium_set($pdo, $who, (string)($_POST['plan'] ?? ''));
                header('Location: premium.php?msg=' . ($ok ? 'granted' : 'failed'));
                exit;
            }
        }

        $members  = vs_premium_all($pdo);
        $requests = vs_premium_requests($pdo, 100);
    } catch (Throwable $e) {
        $dbError = 'Could not read premium members: ' . $e->getMessage();
    }
} else {
    $dbError = 'No database connection found. Check that ../connection.php creates $pdo.';
}

$planLabel = function (string $p): string {
    return VS_PREMIUM_PLANS[$p]['label'] ?? ucfirst($p);
};

$stActive = 0; $stNew = 0; $stEnded = 0; $stRev = 0.0;
foreach ($members as $i => $m) {
    $on = vs_premium_is_active($m);
    $members[$i]['_active'] = $on;
    if ($on) {
        $stActive++;
        $stRev += VS_PREMIUM_PLANS[$m['plan']]['price'] ?? 0;
    } else {
        $stEnded++;
    }
    if (strtotime((string)$m['started_at']) > time() - 7 * 86400) {
        $stNew++;
    }
}
$stPending = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending') {
        $stPending++;
    }
}

if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="premium-members.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Username', 'Plan', 'Joined', 'Expires', 'Status']);
    foreach ($members as $m) {
        fputcsv($out, [$m['username'], $m['plan'], $m['started_at'], $m['expires_at'], $m['_active'] ? 'Active' : 'Ended']);
    }
    exit;
}

if (($_GET['download'] ?? '') === 'report') {
    $plans = [];
    foreach (VS_PREMIUM_PLANS as $key => $def) {
        $plans[$key] = 0;
    }
    foreach ($members as $m) {
        if ($m['_active'] && isset($plans[$m['plan']])) {
            $plans[$m['plan']]++;
        }
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="premium-report-' . date('Y-m-d') . '.txt"');
    echo "VibeStream Premium report\n";
    echo "Generated: " . date('Y-m-d H:i') . "\n\n";
    echo "Active members: $stActive\n";
    foreach ($plans as $key => $count) {
        echo "  " . $planLabel($key) . " plans: $count\n";
    }
    echo "Joined in the last 7 days: $stNew\n";
    echo "Ended (cancelled or expired): $stEnded\n";
    echo "Payments waiting for approval: $stPending\n";
    echo "Revenue from active plans: $" . number_format($stRev, 2) . "\n\n";
    echo "Members (newest first)\n";
    echo "Username | Plan | Joined | Expires | Status\n";
    foreach ($members as $m) {
        echo implode(' | ', [$m['username'], $m['plan'], $m['started_at'], $m['expires_at'], $m['_active'] ? 'Active' : 'Ended']) . "\n";
    }
    exit;
}

$msgs = [
    'granted'  => 'Premium granted.',
    'revoked'  => 'Premium cancelled for that user.',
    'approved' => 'Payment approved. Premium is now active for that user.',
    'rejected' => 'Payment rejected.',
    'handled'  => 'That payment request was already handled.',
    'failed'   => 'Could not grant Premium. Check the username and plan.',
    'expired'  => 'Session expired. Try again.',
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';

$pmInitial = function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'U', 0, 1));
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
              <span class="page-icon"><i class="bi bi-gem" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Premium</p>
                <h1 class="h3 mb-1">Premium members</h1>
                <p class="text-muted mb-0">Users who upgraded to VibeStream Premium on the main site. Admins always have Premium.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="?download=csv"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
              <a class="btn btn-primary btn-sm" href="?download=report"><i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info mt-3 mb-0" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <section class="row g-3 mt-1" aria-label="Premium metrics">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top">
                  <span class="metric-label">Active members</span>
                  <span class="metric-icon"><i class="bi bi-gem" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($stActive); ?></div>
                <div class="metric-meta"><span>with Premium right now</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-success">
                <div class="metric-top">
                  <span class="metric-label">New this week</span>
                  <span class="metric-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($stNew); ?></div>
                <div class="metric-meta"><span class="text-success">joined</span> <span>in the last 7 days</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top">
                  <span class="metric-label">Revenue</span>
                  <span class="metric-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value">$<?php echo number_format($stRev, 2); ?></div>
                <div class="metric-meta"><span>from active plans</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top">
                  <span class="metric-label">Ended</span>
                  <span class="metric-icon"><i class="bi bi-person-dash" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($stEnded); ?></div>
                <div class="metric-meta"><span>cancelled or expired</span></div>
              </article>
            </div>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title">
                  <i class="bi bi-receipt" aria-hidden="true"></i><span>Payment requests</span>
                  <?php if ($stPending > 0): ?><span class="badge text-bg-warning ms-2"><?php echo (int)$stPending; ?> waiting</span><?php endif; ?>
                </h2>
                <p class="text-muted mb-0">Check that the money arrived, then approve. Approving turns Premium on for that user.</p>
              </div>
            </div>
            <?php if ($dbError === '' && !$requests): ?>
              <div class="vs-empty text-muted text-center py-4">
                <p class="fw-semibold mb-1">No payment requests yet</p>
                <p class="mb-0">They appear here when a user sends their transaction ID on the Premium page.</p>
              </div>
            <?php elseif ($requests): ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">User</th>
                      <th scope="col">Plan</th>
                      <th scope="col">Amount</th>
                      <th scope="col">Transaction ID</th>
                      <th scope="col">Sent</th>
                      <th scope="col">Status</th>
                      <th scope="col" class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($requests as $r):
                        $badge = $r['status'] === 'approved' ? 'text-bg-success' : ($r['status'] === 'rejected' ? 'text-bg-danger' : 'text-bg-warning');
                    ?>
                      <tr>
                        <td><strong class="content-title"><?php echo vs_e($r['username']); ?></strong></td>
                        <td><span class="content-type-badge"><?php echo vs_e($planLabel((string)$r['plan'])); ?></span></td>
                        <td>$<?php echo vs_e(number_format((float)$r['amount'], 2)); ?></td>
                        <td><code><?php echo vs_e($r['txn_ref']); ?></code></td>
                        <td class="text-muted"><?php echo vs_e(date('M j, Y H:i', strtotime((string)$r['created_at']))); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo vs_e(ucfirst((string)$r['status'])); ?></span></td>
                        <td class="text-end">
                          <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" class="d-inline">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                              <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm">Approve</button>
                              <button type="submit" name="action" value="reject" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Reject this payment?');">Reject</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Members</span></h2>
                <p class="text-muted mb-0">Everyone who has bought Premium, newest first.</p>
              </div>
              <div class="d-flex gap-2 flex-wrap">
                <input type="search" class="form-control form-control-sm" id="pmSearch" placeholder="Search username" aria-label="Search members">
                <select class="form-select form-select-sm" id="pmFilter" aria-label="Filter members">
                  <option value="all">All</option>
                  <option value="active">Active</option>
                  <option value="ended">Ended</option>
                </select>
              </div>
            </div>

            <?php if ($dbError !== ''): ?>
              <div class="vs-empty text-muted text-center py-4"><?php echo vs_e($dbError); ?></div>
            <?php elseif (!$members): ?>
              <div class="vs-empty text-muted text-center py-4">
                <p class="fw-semibold mb-1">No premium members yet</p>
                <p class="mb-0">Members appear here as soon as you approve a payment.</p>
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Member</th>
                      <th scope="col">Plan</th>
                      <th scope="col">Joined</th>
                      <th scope="col">Expires</th>
                      <th scope="col">Status</th>
                      <th scope="col" class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody id="pmRows">
                    <?php foreach ($members as $m): ?>
                      <tr data-name="<?php echo vs_e(mb_strtolower($m['username'])); ?>" data-status="<?php echo $m['_active'] ? 'active' : 'ended'; ?>">
                        <td>
                          <div class="table-media">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url($m['username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($m['username'])); ?>';">
                            <strong class="content-title"><?php echo vs_e($m['username']); ?></strong>
                          </div>
                        </td>
                        <td><span class="content-type-badge"><?php echo vs_e($planLabel((string)$m['plan'])); ?></span></td>
                        <td class="text-muted"><?php echo vs_e(date('M j, Y', strtotime((string)$m['started_at']))); ?></td>
                        <td class="text-muted"><?php echo vs_e(date('M j, Y', strtotime((string)$m['expires_at']))); ?></td>
                        <td><span class="badge <?php echo $m['_active'] ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $m['_active'] ? 'Active' : 'Ended'; ?></span></td>
                        <td class="text-end">
                          <?php if ($m['_active']): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel Premium for this user?');">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="revoke">
                              <input type="hidden" name="username" value="<?php echo vs_e($m['username']); ?>">
                              <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-gift" aria-hidden="true"></i><span>Give Premium</span></h2>
                <p class="text-muted mb-0">Grant Premium to a user by username, for example as a reward or to test.</p>
              </div>
            </div>
            <form method="post" class="d-flex gap-2 flex-wrap p-3 pt-0">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="action" value="grant">
              <input type="text" class="form-control form-control-sm" name="username" placeholder="Username" aria-label="Username" required style="max-width:240px">
              <select class="form-select form-select-sm" name="plan" aria-label="Plan" style="max-width:160px">
                <?php foreach (VS_PREMIUM_PLANS as $key => $def): ?>
                  <option value="<?php echo vs_e($key); ?>" <?php echo $key === 'monthly' ? 'selected' : ''; ?>><?php echo vs_e($def['label']); ?></option>
                <?php endforeach; ?>
              </select>
              <button type="submit" class="btn btn-primary btn-sm">Give Premium</button>
            </form>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>Developed by <a href="https://M.Awais.com" target="_blank">Muhammad Awais</a></span>
        </div>
      </footer>
    </div>
  </div>

  <script>
  (function () {
    var q = document.getElementById('pmSearch'), f = document.getElementById('pmFilter');
    if (!q || !f) return;
    function apply() {
      var term = (q.value || '').toLowerCase(), want = f.value;
      document.querySelectorAll('#pmRows tr').forEach(function (tr) {
        var ok = tr.dataset.name.indexOf(term) > -1 && (want === 'all' || tr.dataset.status === want);
        tr.hidden = !ok;
      });
    }
    q.addEventListener('input', apply);
    f.addEventListener('change', apply);
  })();
  </script>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>