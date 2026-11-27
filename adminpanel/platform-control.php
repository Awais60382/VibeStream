<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/admin-activity-store.php';

// Owner only: everybody else gets a plain 403 (the page, and every action on it)
if (empty($isSiteOwner)) {
    http_response_code(403);
    exit('Only the site owner can control platform availability.');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['vs_platform_control_csrf'])) {
    $_SESSION['vs_platform_control_csrf'] = bin2hex(random_bytes(32));
}

$h = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$successMsg = '';
$errorMsg = '';
// The pop-up (here and in the sidebar) sends the form with fetch and wants JSON back.
$wantsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals((string)$_SESSION['vs_platform_control_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        $state = vs_branding_state();

        if ($action === 'close') {
            $message = trim((string)($_POST['offline_message'] ?? ''));
            if ($message === '') {
                $message = 'This platform is currently offline for maintenance.';
            }
            if (mb_strlen($message) > 300) {
                throw new RuntimeException('The maintenance message can be at most 300 characters.');
            }
            $state['maintenance_enabled'] = true;
            $state['maintenance_message'] = $message;
            vs_branding_save_state($state);
            vs_admin_log($pdo ?? null, 'settings', 'Platform closed for maintenance');
            $successMsg = 'The platform is now in maintenance mode.';
        } elseif ($action === 'open') {
            $state['maintenance_enabled'] = false;
            vs_branding_save_state($state);
            vs_admin_log($pdo ?? null, 'settings', 'Platform reopened');
            $successMsg = 'The platform is now operating.';
        } else {
            throw new RuntimeException('Unknown platform control action.');
        }
    } catch (Throwable $error) {
        $successMsg = '';
        $errorMsg = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Could not update platform availability.';
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $errorMsg === '', 'message' => $errorMsg !== '' ? $errorMsg : $successMsg]);
        exit;
    }
}

$state = vs_branding_state();
$offline = !empty($state['maintenance_enabled']);
$offlineMessage = trim((string)($state['maintenance_message'] ?? ''));
if ($offlineMessage === '') {
    $offlineMessage = 'This platform is currently offline for maintenance.';
}
$csrf = (string)$_SESSION['vs_platform_control_csrf'];

require_once __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="./assets/css/platform-control.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/platform-control.css'); ?>">

<main class="admin-content p-3 p-lg-4 vpc-page">
  <header class="vpc-head">
    <span class="vpc-head-icon" aria-hidden="true"><i class="bi bi-gear-fill"></i></span>
    <span class="vpc-head-sep" aria-hidden="true"></span>
    <div>
      <h1>Platform Control</h1>
      <p>Manage your platform availability.</p>
    </div>
  </header>

  <?php if ($successMsg !== ''): ?>
    <div class="vpc-alert is-ok" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i><?php echo $h($successMsg); ?></div>
  <?php endif; ?>
  <?php if ($errorMsg !== ''): ?>
    <div class="vpc-alert is-bad" role="alert"><i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i><?php echo $h($errorMsg); ?></div>
  <?php endif; ?>

  <section class="vpc-state<?php echo $offline ? ' is-offline' : ''; ?>" aria-live="polite">
    <span class="vpc-ring" aria-hidden="true"><i class="bi bi-power"></i></span>
    <span class="vpc-state-sep" aria-hidden="true"></span>
    <div class="vpc-state-copy">
      <h2>Platform <span class="vpc-word"><?php echo $offline ? 'Offline' : 'Operating'; ?></span></h2>
      <p><?php echo $h($offline ? $offlineMessage : 'The platform is available and operating normally.'); ?></p>
    </div>
    <span class="vpc-pill"><span class="vpc-dot"></span><?php echo $offline ? 'Closed' : 'Online'; ?></span>
  </section>

  <section class="vpc-card">
    <form method="post" id="vpcForm">
      <input type="hidden" name="csrf_token" value="<?php echo $h($csrf); ?>">
      <?php if ($offline): ?>
        <input type="hidden" name="action" value="open">
        <div class="vpc-card-head">
          <i class="bi bi-play-circle" aria-hidden="true"></i>
          <div>
            <h3>Reopen the platform</h3>
            <p>Visitors can use the platform again and maintenance mode is removed.</p>
          </div>
        </div>
        <div class="vpc-actions">
          <button class="vpc-btn vpc-btn-open" type="submit" data-vs-maint data-state="offline" data-url="platform-control.php" data-csrf="<?php echo $h($csrf); ?>" data-message="<?php echo $h($offlineMessage); ?>">
            <i class="bi bi-play-fill" aria-hidden="true"></i>Open Platform
          </button>
          <a class="vpc-btn vpc-btn-ghost" href="app-settings.php">App Settings</a>
        </div>
      <?php else: ?>
        <input type="hidden" name="action" value="close">
        <div class="vpc-card-head">
          <i class="bi bi-envelope" aria-hidden="true"></i>
          <div>
            <h3>Offline Message</h3>
            <p>This message will be shown to visitors when maintenance mode is enabled.</p>
          </div>
        </div>
        <label for="offline_message" class="visually-hidden">Offline message</label>
        <textarea id="offline_message" name="offline_message" maxlength="300" rows="3"><?php echo $h($offlineMessage); ?></textarea>
        <div class="vpc-actions">
          <button class="vpc-btn vpc-btn-main" type="submit" data-vs-maint data-state="online" data-url="platform-control.php" data-csrf="<?php echo $h($csrf); ?>" data-message="<?php echo $h($offlineMessage); ?>">
            <i class="bi bi-power" aria-hidden="true"></i>Enable Maintenance
          </button>
          <a class="vpc-btn vpc-btn-ghost" href="app-settings.php">App Settings</a>
        </div>
      <?php endif; ?>
    </form>
  </section>

  <div class="vpc-warn<?php echo $offline ? ' is-info' : ''; ?>" role="note">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <span><?php echo $offline
        ? 'Maintenance mode is on: visitors currently see the offline message above.'
        : 'Enabling maintenance mode will make the platform unavailable to all visitors.'; ?></span>
  </div>
</main>

<script src="./assets/js/platform-control.js?v=<?php echo (int)@filemtime(__DIR__ . '/assets/js/platform-control.js'); ?>" defer></script>

</div>
</div>
</body>
</html>