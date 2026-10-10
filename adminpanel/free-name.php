<?php
// Owner-only cleanup: frees a user name that was left behind by an account deleted earlier.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}
include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/release-username.php';

// Only the owner (VS_OWNER_EMAIL in owner-guard.php) may use this page.
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can free user names.</p>'
        . '<p><a href="admins.php">Back to the admin panel</a></p></body></html>';
    exit;
}

if (empty($_SESSION['free_name_csrf'])) {
    $_SESSION['free_name_csrf'] = bin2hex(random_bytes(16));
}
$message = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'purge') {
    if (!hash_equals($_SESSION['free_name_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif (!isset($pdo) || !($pdo instanceof PDO)) {
        $message = 'No database connection found.';
    } else {
        try {
            $n = vs_purge_deleted_premium($pdo);
            $ok = true;
            $message = 'Done. ' . $n . ' deleted account(s) removed from Premium members.';
        } catch (Throwable $e) {
            error_log('purge premium failed: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    if (!hash_equals($_SESSION['free_name_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $message = 'The request expired. Refresh the page and try again.';
    } elseif ($name === '') {
        $message = 'Type the name you want to free.';
    } elseif (!isset($pdo) || !($pdo instanceof PDO)) {
        $message = 'No database connection found.';
    } else {
        try {
            $n = vs_release_username($pdo, $name, 'deleted_' . substr(md5($name . microtime()), 0, 8));
            if ($n < 0) {
                $message = 'A live account still uses "' . $name . '", so nothing was changed. Delete that account first.';
            } else {
                $ok = true;
                $message = 'Done. "' . $name . '" is free now (' . $n . ' leftover row(s) cleaned).';
            }
        } catch (Throwable $e) {
            error_log('free-name failed: ' . $e->getMessage());
            $message = 'Something went wrong. Please try again.';
        }
    }
}

// Same layout as the other admin pages: header.php draws the navbar and the sidebar.
include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-person-x" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner tools</p>
                <h1 class="h3 mb-1">Delete names</h1>
                <p class="text-muted mb-0">Frees a user name left behind by an account you already deleted. Only the owner can see this page.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="admins.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Admins</a>
            </div>
          </div>

          <?php if ($message !== ''): ?>
            <div class="alert <?php echo $ok ? 'alert-success' : 'alert-danger'; ?> mt-3 mb-0" role="status"><?php echo vs_e($message); ?></div>
          <?php endif; ?>

          <div class="row g-3 mt-1">
            <div class="col-12 col-lg-6">
              <section class="panel h-100" aria-label="Free a user name">
                <div class="panel-header">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-person-x" aria-hidden="true"></i><span>Free a user name</span></h2>
                  <p class="text-muted mb-0">Old videos and comments under that name are kept but moved to a "deleted_..." name.</p>
                </div>
                <form method="post" action="free-name.php">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                  <label class="form-label" for="name">User name to free</label>
                  <input class="form-control mb-3" type="text" id="name" name="name" placeholder="AdminHunter" autocomplete="off" required>
                  <button class="btn btn-danger btn-sm" type="submit">Free this name</button>
                </form>
              </section>
            </div>

            <div class="col-12 col-lg-6">
              <section class="panel h-100" aria-label="Premium members cleanup">
                <div class="panel-header">
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-stars" aria-hidden="true"></i><span>Premium members cleanup</span></h2>
                  <p class="text-muted mb-0">Remove deleted accounts from the Premium members list (rows named "deleted_...").</p>
                </div>
                <form method="post" action="free-name.php">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($_SESSION['free_name_csrf']); ?>">
                  <input type="hidden" name="do" value="purge">
                  <button class="btn btn-outline-danger btn-sm" type="submit">Remove from Premium members</button>
                </form>
              </section>
            </div>
          </div>
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