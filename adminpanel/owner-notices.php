<?php
/*
 * VibeStream admin panel - Owner notices.
 * Owner: write a notice to an admin and watch it go Sent -> Seen -> Changed.
 * Admin: read the notices the owner sent you and press "I changed it" when you have.
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
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/owner-notice-store.php';
require_once __DIR__ . '/admin-activity-store.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('No database connection found.');
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
vs_notice_ensure($pdo);

$isOwner = function_exists('vs_is_owner') && vs_is_owner($pdo);
$me = vs_notice_me($pdo);
if (empty($_SESSION['owner_notice_csrf'])) {
    $_SESSION['owner_notice_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['owner_notice_csrf'];
$message = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif ($do === 'send' && $isOwner) {
        $toId = (int)($_POST['to_id'] ?? 0);
        $body = trim((string)($_POST['body'] ?? ''));
        $target = null;
        foreach (vs_notice_admins($pdo, (int)$me['id']) as $a) {
            if ((int)$a['id'] === $toId) {
                $target = $a;
            }
        }
        if (!$target) {
            $message = 'Choose an admin.';
        } elseif ($body === '') {
            $message = 'Write the notice first.';
        } elseif (vs_notice_send($pdo, $me, $toId, (string)$target['username'], $body) > 0) {
            $ok = true;
            vs_admin_log($pdo, 'other', 'Sent an owner notice to ' . $target['username']);
            $message = 'Sent to ' . $target['username'] . '. You will be told when they see it and when they change it.';
        } else {
            $message = 'Something went wrong. Please try again.';
        }
    } elseif ($do === 'done') {
        if (vs_notice_mark_done($pdo, (int)($_POST['id'] ?? 0), (int)$me['id'], (string)$me['name'])) {
            $ok = true;
            $message = 'Done. The owner has been told.';
        } else {
            $message = 'That notice could not be updated.';
        }
    }
}

// An admin who opens this page has now seen their notices: tell the owner.
if (!$isOwner && (int)$me['id'] > 0) {
    vs_notice_mark_seen($pdo, (int)$me['id'], (string)$me['name']);
}

$admins = $isOwner ? vs_notice_admins($pdo, (int)$me['id']) : [];
$sent = $isOwner ? vs_notice_list_for_owner($pdo, (int)$me['id']) : [];
$mine = vs_notice_list_for_admin($pdo, (int)$me['id']);
$focus = (int)($_GET['id'] ?? 0);

$fmt = static function (?string $t): string {
    $ts = $t ? strtotime($t) : 0;
    return $ts ? date('M j, g:i A', $ts) : '';
};
$statusBadge = static function (array $n) use ($fmt): string {
    if ($n['status'] === 'done') {
        return '<span class="badge text-bg-success">Changed</span><div class="text-muted small">' . vs_e($fmt($n['done_at'])) . '</div>';
    }
    if ($n['status'] === 'seen') {
        return '<span class="badge text-bg-info">Seen</span><div class="text-muted small">' . vs_e($fmt($n['seen_at'])) . '</div>';
    }
    return '<span class="badge text-bg-secondary">Sent</span><div class="text-muted small">not opened yet</div>';
};

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-send-check" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1"><?php echo $isOwner ? 'Owner tools' : 'From the owner'; ?></p>
                <h1 class="h3 mb-1">Owner notices</h1>
                <p class="text-muted mb-0"><?php echo $isOwner
                    ? 'Send a notice to an admin. You are told when they see it and when they have changed it.'
                    : 'Notices the owner sent you. Press "I changed it" once you have made the change.'; ?></p>
              </div>
            </div>
          </div>

          <?php if ($message !== ''): ?>
            <div class="alert <?php echo $ok ? 'alert-success' : 'alert-danger'; ?> mt-3 mb-0" role="status"><?php echo vs_e($message); ?></div>
          <?php endif; ?>

          <?php if ($isOwner): ?>
          <section class="panel mt-3" aria-label="Send a notice">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-pencil-square" aria-hidden="true"></i><span>New notice</span></h2>
            </div>
            <form method="post" action="owner-notices.php">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="do" value="send">
              <label class="form-label" for="to_id">Admin</label>
              <select class="form-select mb-3" id="to_id" name="to_id" required>
                <option value="">Choose an admin</option>
                <?php foreach ($admins as $a): ?>
                  <option value="<?php echo (int)$a['id']; ?>"><?php echo vs_e($a['username']); ?></option>
                <?php endforeach; ?>
              </select>
              <label class="form-label" for="body">Notice</label>
              <textarea class="form-control mb-3" id="body" name="body" rows="3" maxlength="1000" placeholder="What should they change?" required></textarea>
              <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Send notice</button>
            </form>
          </section>

          <section class="panel mt-3" aria-label="Sent notices">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-list-check" aria-hidden="true"></i><span>Sent notices</span></h2>
              <p class="text-muted mb-0">Sent, then Seen when the admin opens it, then Changed when they press the button.</p>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead><tr><th scope="col">To</th><th scope="col">Notice</th><th scope="col">Sent</th><th scope="col">Status</th></tr></thead>
                <tbody>
                  <?php if (!$sent): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">You have not sent any notice yet.</td></tr>
                  <?php else: foreach ($sent as $n): ?>
                    <tr>
                      <td class="fw-semibold"><?php echo vs_e($n['to_name']); ?></td>
                      <td><?php echo nl2br(vs_e($n['body'])); ?></td>
                      <td class="text-muted small text-nowrap"><?php echo vs_e($fmt($n['created_at'])); ?></td>
                      <td class="text-nowrap"><?php echo $statusBadge($n); ?></td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
            </div>
          </section>
          <?php endif; ?>

          <?php if (!$isOwner || $mine): ?>
          <section class="panel mt-3" aria-label="Notices for you">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-inbox" aria-hidden="true"></i><span>Notices for you</span></h2>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead><tr><th scope="col">From</th><th scope="col">Notice</th><th scope="col">Sent</th><th scope="col" class="text-end">Action</th></tr></thead>
                <tbody>
                  <?php if (!$mine): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No notices from the owner.</td></tr>
                  <?php else: foreach ($mine as $n): ?>
                    <tr<?php echo $focus === (int)$n['id'] ? ' class="table-active"' : ''; ?>>
                      <td class="fw-semibold"><?php echo vs_e($n['from_name']); ?></td>
                      <td><?php echo nl2br(vs_e($n['body'])); ?></td>
                      <td class="text-muted small text-nowrap"><?php echo vs_e($fmt($n['created_at'])); ?></td>
                      <td class="text-end text-nowrap">
                        <?php if ($n['status'] === 'done'): ?>
                          <span class="badge text-bg-success">Changed <?php echo vs_e($fmt($n['done_at'])); ?></span>
                        <?php else: ?>
                          <form method="post" action="owner-notices.php" class="d-inline" onsubmit="return confirm('Tell the owner you changed it?');">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="do" value="done">
                            <input type="hidden" name="id" value="<?php echo (int)$n['id']; ?>">
                            <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check2" aria-hidden="true"></i> I changed it</button>
                          </form>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; endif; ?>
                </tbody>
              </table>
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