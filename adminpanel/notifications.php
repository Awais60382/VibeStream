<?php
/*
 * VibeStream admin panel - Notifications (admins only).
 * Shows every "I want Premium" request sent from the main site's Premium page,
 * with Give (approve) and Decline (reject) buttons. Put this file in the adminpanel folder.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/admin-activity-store.php';
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../premium-store.php';

if (empty($_SESSION['premium_csrf'])) {
    $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['premium_csrf'];
$hasDb = isset($pdo) && $pdo instanceof PDO;
$dbError = '';
$requests = [];
$adminUid = (int)($_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? 0);
$endedAlerts = [];
$prefs = ['uploads' => 1, 'comments' => 1, 'likes' => 1, 'subscribers' => 1];
$adminPrefs = ['premium_requests' => 1, 'new_users' => 1, 'messages' => 1, 'user_uploads' => 1];

if ($hasDb) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        vs_premium_ensure($pdo);

        // Give (approve) or Decline (reject) a request: the same function premium.php uses.
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
            if ($isAjax && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page.']);
                exit;
            }
            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                header('Location: notifications.php?msg=expired');
                exit;
            }
            $act = (string)($_POST['action'] ?? '');
            // Notification settings for THIS admin account (same table the main site uses).
            if ($act === 'save_settings') {
                if ($adminUid <= 0) {
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['ok' => false, 'error' => 'Could not find your account id.']);
                        exit;
                    }
                    header('Location: notifications.php?msg=nouser');
                    exit;
                }
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS user_notification_prefs (
                        user_id INT NOT NULL PRIMARY KEY,
                        uploads TINYINT(1) NOT NULL DEFAULT 1,
                        comments TINYINT(1) NOT NULL DEFAULT 1,
                        likes TINYINT(1) NOT NULL DEFAULT 1,
                        subscribers TINYINT(1) NOT NULL DEFAULT 1
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                $st = $pdo->prepare(
                    'INSERT INTO user_notification_prefs (user_id, uploads, comments, likes, subscribers)
                     VALUES (:id, :u, :c, :l, :s)
                     ON DUPLICATE KEY UPDATE uploads = VALUES(uploads), comments = VALUES(comments),
                                             likes = VALUES(likes), subscribers = VALUES(subscribers)'
                );
                $st->execute([
                    ':id' => $adminUid,
                    ':u'  => isset($_POST['n_uploads']) ? 1 : 0,
                    ':c'  => isset($_POST['n_comments']) ? 1 : 0,
                    ':l'  => isset($_POST['n_likes']) ? 1 : 0,
                    ':s'  => isset($_POST['n_subscribers']) ? 1 : 0,
                ]);
                // Admin-only alerts for THIS admin account.
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS admin_notification_prefs (
                        user_id INT NOT NULL PRIMARY KEY,
                        premium_requests TINYINT(1) NOT NULL DEFAULT 1,
                        new_users TINYINT(1) NOT NULL DEFAULT 1,
                        messages TINYINT(1) NOT NULL DEFAULT 1,
                        user_uploads TINYINT(1) NOT NULL DEFAULT 1
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
                );
                $as = $pdo->prepare(
                    'INSERT INTO admin_notification_prefs (user_id, premium_requests, new_users, messages, user_uploads)
                     VALUES (:id, :p, :n, :m, :u)
                     ON DUPLICATE KEY UPDATE premium_requests = VALUES(premium_requests), new_users = VALUES(new_users),
                                             messages = VALUES(messages), user_uploads = VALUES(user_uploads)'
                );
                $as->execute([
                    ':id' => $adminUid,
                    ':p'  => isset($_POST['a_premium_requests']) ? 1 : 0,
                    ':n'  => isset($_POST['a_new_users']) ? 1 : 0,
                    ':m'  => isset($_POST['a_messages']) ? 1 : 0,
                    ':u'  => isset($_POST['a_user_uploads']) ? 1 : 0,
                ]);
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['ok' => true]);
                    exit;
                }
                header('Location: notifications.php?msg=saved');
                exit;
            }
            if ($act === 'approve' || $act === 'reject') {
                $done = vs_premium_review($pdo, (int)($_POST['id'] ?? 0), $act === 'approve');
                if ($done) {
                    vs_admin_log($pdo, 'premium', ($act === 'approve' ? 'Gave Premium for' : 'Declined') . ' Premium request #' . (int)($_POST['id'] ?? 0));
                }
                header('Location: notifications.php?msg=' . ($done ? ($act === 'approve' ? 'given' : 'declined') : 'handled'));
                exit;
            }
        }

        $requests = vs_premium_requests($pdo, 100);

        // Premium periods that just ended. Opening this page counts as seeing them,
        // so those members leave the Premium Members list on the next visit.
        vs_premium_sync_end_alerts($pdo);
        $endedAlerts = vs_premium_end_alerts($pdo, true, 50);
        vs_premium_end_alerts_mark_seen($pdo, array_column($endedAlerts, 'id'));

        if ($adminUid > 0) {
            try {
                $ps = $pdo->prepare('SELECT uploads, comments, likes, subscribers FROM user_notification_prefs WHERE user_id = :id LIMIT 1');
                $ps->execute([':id' => $adminUid]);
                $row = $ps->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    foreach ($prefs as $k => $v) {
                        $prefs[$k] = (int)$row[$k] === 1 ? 1 : 0;
                    }
                }
            } catch (Throwable $e) {
                // no saved settings yet: everything stays on
            }
            try {
                $as = $pdo->prepare('SELECT premium_requests, new_users, messages, user_uploads FROM admin_notification_prefs WHERE user_id = :id LIMIT 1');
                $as->execute([':id' => $adminUid]);
                $arow = $as->fetch(PDO::FETCH_ASSOC);
                if ($arow) {
                    foreach ($adminPrefs as $k => $v) {
                        $adminPrefs[$k] = (int)$arow[$k] === 1 ? 1 : 0;
                    }
                }
            } catch (Throwable $e) {
                // no saved admin settings yet: everything stays on
            }
        }
    } catch (Throwable $e) {
        error_log('notifications.php failed: ' . $e->getMessage());
        $dbError = 'Could not load the notifications.';
    }
} else {
    $dbError = 'No database connection found. Check that ../connection.php creates $pdo.';
}

$pending = array_values(array_filter($requests, static fn($r) => $r['status'] === 'pending'));
$history = array_values(array_filter($requests, static fn($r) => $r['status'] !== 'pending'));

$planLabel = static function (string $p): string {
    return VS_PREMIUM_PLANS[$p]['label'] ?? ucfirst($p);
};
$when = static function ($t): string {
    $ts = strtotime((string)$t);
    return $ts ? date('M j, Y g:i A', $ts) : '';
};
$pmInitial = static function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'U', 0, 1));
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
        . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
        . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
    );
};

$msgs = [
    'given'    => 'Premium given. It is now active for that user.',
    'declined' => 'Request declined.',
    'handled'  => 'That request was already handled.',
    'expired'  => 'Session expired. Try again.',
    'saved'    => 'Notification settings saved.',
    'nouser'   => 'Could not find your account id, so the settings were not saved.',
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-bell" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Admin</p>
                <h1 class="h3 mb-1">Notifications</h1>
                <p class="text-muted mb-0">Users who ask for Premium show up here. Check that the payment arrived, then press Give or Decline.</p>
              </div>
            </div>
            <div class="heading-actions d-flex align-items-center gap-2">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="nsGear" aria-expanded="false" aria-controls="notifSettings" title="Notification settings" aria-label="Notification settings"><i class="bi bi-gear" aria-hidden="true"></i></button>
              <a class="btn btn-outline-secondary btn-sm" href="premium.php"><i class="bi bi-gem" aria-hidden="true"></i> Premium members</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info mt-3 mb-0" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <?php if ($dbError !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($dbError); ?></div>
          <?php else: ?>

          <?php if ($endedAlerts): ?>
          <section class="panel mt-3" aria-label="Premium ended">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-person-dash" aria-hidden="true"></i><span>Premium ended</span></h2>
              <p class="text-muted mb-0"><?php echo count($endedAlerts); ?> new. Once you have seen this, these members are removed from the Premium members list.</p>
            </div>
            <div class="px-3 pb-3">
              <?php foreach ($endedAlerts as $ea): ?>
                <div class="d-flex align-items-center gap-3 py-3 border-top">
                  <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url((string)$ea['username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial((string)$ea['username'])); ?>';">
                  <div class="min-w-0 flex-grow-1">
                    <p class="fw-semibold mb-0"><?php echo vs_e($ea['username']); ?>'s Premium has ended</p>
                    <p class="text-muted small mb-0"><?php echo vs_e($planLabel((string)$ea['plan'])); ?> plan &middot; ended <?php echo vs_e($when($ea['ended_at'])); ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if ($pending): ?>
          <section class="panel mt-3" aria-label="Premium requests waiting">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-gem" aria-hidden="true"></i><span>Wants Premium</span></h2>
              <p class="text-muted mb-0"><?php echo count($pending); ?> waiting</p>
            </div>
              <div class="row g-3 p-3 pt-0">
                <?php foreach ($pending as $r): ?>
                  <div class="col-12 col-lg-6">
                    <div class="border rounded-3 p-3 h-100">
                      <div class="d-flex align-items-center gap-3">
                        <img class="avatar-img avatar-sm" src="<?php echo vs_e(avatar_url((string)$r['username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial((string)$r['username'])); ?>';">
                        <div class="min-w-0">
                          <p class="fw-semibold mb-0"><?php echo vs_e($r['username']); ?> wants Premium</p>
                          <p class="text-muted small mb-0"><?php echo vs_e($when($r['created_at'])); ?></p>
                        </div>
                      </div>
                      <div class="d-flex flex-wrap gap-4 mt-3 small">
                        <span><strong><?php echo vs_e($planLabel((string)$r['plan'])); ?></strong> plan</span>
                        <span><strong>$<?php echo number_format((float)$r['amount'], 2); ?></strong></span>
                        <span>Transaction ID: <strong><?php echo vs_e($r['txn_ref']); ?></strong></span>
                      </div>
                      <form method="post" class="d-flex flex-wrap gap-2 mt-3">
                        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                        <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                        <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm" onclick="return confirm('Give Premium to this user?');">Give</button>
                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger btn-sm" onclick="return confirm('Decline this request?');">Decline</button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
          </section>
          <?php endif; ?>

          <?php if ($history): ?>
          <section class="panel mt-3" aria-label="Earlier requests">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Earlier requests</span></h2>
            </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr><th scope="col">User</th><th scope="col">Plan</th><th scope="col">Amount</th><th scope="col">Transaction ID</th><th scope="col">Date</th><th scope="col">Result</th></tr>
                  </thead>
                  <tbody>
                    <?php foreach ($history as $r): $given = $r['status'] === 'approved'; ?>
                      <tr>
                        <td class="fw-semibold"><?php echo vs_e($r['username']); ?></td>
                        <td><?php echo vs_e($planLabel((string)$r['plan'])); ?></td>
                        <td>$<?php echo number_format((float)$r['amount'], 2); ?></td>
                        <td class="text-muted small"><?php echo vs_e($r['txn_ref']); ?></td>
                        <td class="text-muted small"><?php echo vs_e($when($r['updated_at'])); ?></td>
                        <td><span class="badge <?php echo $given ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo $given ? 'Given' : 'Declined'; ?></span></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
          </section>
          <?php endif; ?>

          <section class="panel mt-3" id="notifSettings" style="display:none" aria-label="Notification settings">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-gear" aria-hidden="true"></i><span>Notification settings</span></h2>
              <p class="text-muted mb-0">Choose what you want to be notified about. Changes save automatically.</p>
            </div>
            <form method="post" id="notifSettingsForm" class="px-3 pb-3">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="action" value="save_settings">
              <p class="small fw-semibold text-uppercase text-muted mb-2">Your activity</p>
              <?php
              $settingRows = [
                  ['n_uploads',     'uploads',     'New uploads',     'From channels you subscribe to'],
                  ['n_comments',    'comments',    'Comments',        'When someone comments on your videos or replies to your comment'],
                  ['n_likes',       'likes',       'Likes',           'When someone likes your videos, shorts or comments, or the creator hearts your comment'],
                  ['n_subscribers', 'subscribers', 'New subscribers', 'When someone subscribes to your channel'],
              ];
              foreach ($settingRows as [$field, $key, $label, $hint]): ?>
                <div class="d-flex align-items-center justify-content-between gap-3 py-3 border-top">
                  <label class="flex-grow-1 mb-0" for="<?php echo vs_e($field); ?>">
                    <span class="d-block"><?php echo vs_e($label); ?></span>
                    <small class="text-muted"><?php echo vs_e($hint); ?></small>
                  </label>
                  <input class="form-check-input flex-shrink-0 m-0" type="checkbox" id="<?php echo vs_e($field); ?>" name="<?php echo vs_e($field); ?>" value="1" style="width:1.6rem;height:1.6rem"<?php echo !empty($prefs[$key]) ? ' checked' : ''; ?>>
                </div>
              <?php endforeach; ?>
              <p class="small fw-semibold text-uppercase text-muted mt-4 mb-2">Admin alerts</p>
              <?php
              $adminRows = [
                  ['a_premium_requests', 'premium_requests', 'Premium requests',  'When a user sends a transaction ID and wants Premium'],
                  ['a_new_users',        'new_users',        'New users',         'When someone creates a new account'],
                  ['a_messages',         'messages',         'Admin messages',    'Private and group messages from other admins'],
                  ['a_user_uploads',     'user_uploads',     'New uploads',       'When any user uploads a video or short'],
              ];
              foreach ($adminRows as [$field, $key, $label, $hint]): ?>
                <div class="d-flex align-items-center justify-content-between gap-3 py-3 border-top">
                  <label class="flex-grow-1 mb-0" for="<?php echo vs_e($field); ?>">
                    <span class="d-block"><?php echo vs_e($label); ?></span>
                    <small class="text-muted"><?php echo vs_e($hint); ?></small>
                  </label>
                  <input class="form-check-input flex-shrink-0 m-0" type="checkbox" id="<?php echo vs_e($field); ?>" name="<?php echo vs_e($field); ?>" value="1" style="width:1.6rem;height:1.6rem"<?php echo !empty($adminPrefs[$key]) ? ' checked' : ''; ?>>
                </div>
              <?php endforeach; ?>
              <p id="nsStatus" class="small text-muted mt-2 mb-0" role="status" aria-live="polite"></p>
            </form>
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
  <script>
  (function () {
    var gear = document.getElementById('nsGear');
    var box = document.getElementById('notifSettings');
    var form = document.getElementById('notifSettingsForm');
    var status = document.getElementById('nsStatus');
    if (!gear || !box || !form) { return; }

    // Gear icon shows / hides the settings
    gear.addEventListener('click', function () {
      var opening = box.style.display === 'none';
      box.style.display = opening ? '' : 'none';
      gear.setAttribute('aria-expanded', opening ? 'true' : 'false');
      if (opening) { box.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    });

    // Arriving from the bell menu's gear icon (notifications.php#notifSettings) opens the settings
    if (window.location.hash === '#notifSettings') { gear.click(); }

    // No Save button: every switch saves itself
    form.addEventListener('submit', function (e) { e.preventDefault(); });
    form.addEventListener('change', function () {
      status.textContent = 'Saving...';
      fetch('notifications.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form),
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (d) { status.textContent = d.ok ? 'Saved' : (d.error || 'Could not save'); })
        .catch(function () { status.textContent = 'Could not save. Check your connection.'; });
    });
  })();
  </script>
</body>
</html>