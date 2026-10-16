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
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

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
                if ($done) {
                    vs_admin_log($pdo, 'premium', ($act === 'approve' ? 'Approved' : 'Rejected') . ' Premium payment request #' . (int)($_POST['id'] ?? 0));
                }
                header('Location: premium.php?msg=' . ($done ? ($act === 'approve' ? 'approved' : 'rejected') : 'handled'));
                exit;
            }

            // Save the "how to pay" lines shown to buyers on premium.php
            if ($act === 'payinfo') {
                $lines = preg_split('/\R/', (string)($_POST['pay_info'] ?? '')) ?: [];
                $saved = vs_premium_save_pay_info($lines);
                if ($saved) {
                    vs_admin_log($pdo, 'premium', 'Updated Premium payment details');
                }
                header('Location: premium.php?msg=' . ($saved ? 'payinfo' : 'payinfofail'));
                exit;
            }

            // Grant or revoke Premium by hand
            $who = trim((string)($_POST['username'] ?? ''));
            if ($act === 'revoke' && $who !== '') {
                vs_premium_cancel($pdo, $who);
                vs_admin_log($pdo, 'premium', 'Cancelled Premium for ' . $who);
                header('Location: premium.php?msg=revoked');
                exit;
            }
            if ($act === 'grant' && $who !== '') {
                $ok = vs_premium_set($pdo, $who, (string)($_POST['plan'] ?? ''));
                if ($ok) {
                    vs_admin_log($pdo, 'premium', 'Gave Premium (' . (string)($_POST['plan'] ?? '') . ') to ' . $who);
                }
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

// Everyone who can get Premium: users and admins (the owner is left out), with their Premium state.
$people = [];
$peopleCount = ['all' => 0, 'has' => 0, 'not' => 0, 'admins' => 0];
if ($hasDb && $dbError === '') {
    try {
        $uc = vs_cols($pdo, 'users');
        $mName  = $uc ? vs_pick($uc, ['username', 'user_name', 'login', 'uname']) : null;
        $mEmail = $uc ? vs_pick($uc, ['email', 'email_address', 'mail']) : null;
        $mRole  = $uc ? vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']) : null;
        if ($mName) {
            $sel = vs_q($mName) . ' AS f_name'
                 . ($mEmail ? ', ' . vs_q($mEmail) . ' AS f_email' : '')
                 . ($mRole ? ', ' . vs_q($mRole) . ' AS f_role' : '');
            $urows = $pdo->query('SELECT ' . $sel . ' FROM `users` ORDER BY ' . vs_q($mName) . ' ASC')->fetchAll(PDO::FETCH_ASSOC);

            $premState = [];   // lower-case user name => true (active now) / false (ended)
            foreach ($members as $m) {
                $k = mb_strtolower(trim((string)$m['username']));
                $premState[$k] = !empty($premState[$k]) || !empty($m['_active']);
            }
            foreach ($urows as $u) {
                $nm = trim((string)($u['f_name'] ?? ''));
                $em = trim((string)($u['f_email'] ?? ''));
                if ($nm === '' || ($em !== '' && strtolower($em) === strtolower(VS_OWNER_EMAIL))) {
                    continue;   // no name to give Premium to, or the owner
                }
                $isAdmin = isset($u['f_role']) && strcasecmp(trim((string)$u['f_role']), 'Admin') === 0;
                $k = mb_strtolower($nm);
                if ($isAdmin)                          { $state = 'admin'; }
                elseif (!empty($premState[$k]))        { $state = 'active'; }
                elseif (array_key_exists($k, $premState)) { $state = 'ended'; }
                else                                   { $state = 'none'; }
                $people[] = ['name' => $nm, 'email' => $em, 'state' => $state];
                $peopleCount['all']++;
                if ($state === 'admin')  { $peopleCount['admins']++; }
                if ($state === 'admin' || $state === 'active') { $peopleCount['has']++; } else { $peopleCount['not']++; }
            }
        }
    } catch (Throwable $e) {
        error_log('premium people list failed: ' . $e->getMessage());
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
    'payinfo'  => 'Payment details saved. Buyers can now pay.',
    'payinfofail' => 'Could not save payment details. Check that the backups folder is writable.',
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
                <h2 class="h5 mb-1 section-title"><i class="bi bi-wallet2" aria-hidden="true"></i><span>Payment details</span></h2>
                <p class="text-muted mb-0">Where buyers send money. One line per method (up to 6). While this is empty, the Premium page says "Payments are not open yet".</p>
              </div>
            </div>
            <form method="post" class="p-3 pt-0">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="action" value="payinfo">
              <textarea class="form-control" name="pay_info" rows="4" maxlength="1300" aria-label="Payment details" placeholder="Bank transfer: HBL, 1234567890, Your Name&#10;Easypaisa: 03XX-XXXXXXX, Your Name"><?php echo vs_e(implode("\n", vs_premium_pay_info())); ?></textarea>
              <button type="submit" class="btn btn-primary btn-sm mt-2">Save payment details</button>
            </form>
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

            <div class="p-3 pt-0">
              <hr class="mt-0">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Filter people">
                  <button type="button" class="btn btn-primary" data-gp="all">All (<?php echo (int)$peopleCount['all']; ?>)</button>
                  <button type="button" class="btn btn-outline-primary" data-gp="has">Has Premium (<?php echo (int)$peopleCount['has']; ?>)</button>
                  <button type="button" class="btn btn-outline-primary" data-gp="not">Not upgraded (<?php echo (int)$peopleCount['not']; ?>)</button>
                  <button type="button" class="btn btn-outline-primary" data-gp="admins">Admins (<?php echo (int)$peopleCount['admins']; ?>)</button>
                </div>
                <input type="search" class="form-control form-control-sm" id="gpSearch" placeholder="Search users" aria-label="Search users" autocomplete="off" style="max-width:220px">
              </div>
              <p class="text-muted small mb-2">Showing <span id="gpCount"><?php echo (int)$peopleCount['all']; ?></span> of <?php echo (int)$peopleCount['all']; ?>. Admins always have Premium, so they have no Give button.</p>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">User</th><th scope="col">Premium</th><th scope="col" class="text-end">Give Premium</th></tr>
                  </thead>
                  <tbody id="gpRows">
                    <?php foreach ($people as $p): ?>
                      <tr data-name="<?php echo vs_e(mb_strtolower($p['name'] . ' ' . $p['email'])); ?>" data-state="<?php echo vs_e($p['state']); ?>">
                        <td>
                          <div class="table-media">
                            <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url($p['name'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($p['name'])); ?>';">
                            <div>
                              <strong class="content-title"><?php echo vs_e($p['name']); ?></strong>
                              <?php if ($p['email'] !== ''): ?><div class="text-muted small"><?php echo vs_e($p['email']); ?></div><?php endif; ?>
                            </div>
                          </div>
                        </td>
                        <td>
                          <?php if ($p['state'] === 'admin'): ?><span class="badge text-bg-primary">Admin (included)</span>
                          <?php elseif ($p['state'] === 'active'): ?><span class="badge text-bg-success">Has Premium</span>
                          <?php elseif ($p['state'] === 'ended'): ?><span class="badge text-bg-secondary">Ended</span>
                          <?php else: ?><span class="badge text-bg-warning">Not upgraded</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <?php if ($p['state'] === 'none' || $p['state'] === 'ended'): ?>
                            <form method="post" class="d-inline-flex gap-1 justify-content-end">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="grant">
                              <input type="hidden" name="username" value="<?php echo vs_e($p['name']); ?>">
                              <select class="form-select form-select-sm" name="plan" aria-label="Plan" style="width:auto">
                                <?php foreach (VS_PREMIUM_PLANS as $key => $def): ?>
                                  <option value="<?php echo vs_e($key); ?>" <?php echo $key === 'monthly' ? 'selected' : ''; ?>><?php echo vs_e($def['label']); ?></option>
                                <?php endforeach; ?>
                              </select>
                              <button type="submit" class="btn btn-primary btn-sm">Give Premium</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                    <tr id="gpEmpty"<?php echo $people ? ' hidden' : ''; ?>><td colspan="3" class="text-center text-muted py-4">No users found.</td></tr>
                  </tbody>
                </table>
              </div>
            </div>
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
  <script>
  (function () {
    var body = document.getElementById('gpRows');
    if (!body) return;
    var rows = body.querySelectorAll('tr[data-state]'), empty = document.getElementById('gpEmpty'),
        count = document.getElementById('gpCount'), search = document.getElementById('gpSearch'),
        btns = document.querySelectorAll('[data-gp]'), mode = 'all';
    function match(state) {
      if (mode === 'all') return true;
      if (mode === 'has') return state === 'admin' || state === 'active';
      if (mode === 'not') return state === 'none' || state === 'ended';
      return state === 'admin';
    }
    function apply() {
      var term = (search.value || '').toLowerCase().trim(), n = 0;
      rows.forEach(function (tr) {
        var show = match(tr.dataset.state) && (!term || tr.dataset.name.indexOf(term) > -1);
        tr.hidden = !show;
        if (show) n++;
      });
      count.textContent = n;
      empty.hidden = n !== 0;
    }
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        mode = b.getAttribute('data-gp');
        btns.forEach(function (x) {
          var on = x === b;
          x.classList.toggle('btn-primary', on);
          x.classList.toggle('btn-outline-primary', !on);
        });
        apply();
      });
    });
    search.addEventListener('input', apply);
    apply();
  })();
  </script>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>