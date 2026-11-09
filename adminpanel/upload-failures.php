<?php
/* Failed uploads: where the "Upload Service" row of System Status on the dashboard leads. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession'])) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../upload-failure-store.php';

/* What PHP's own upload error numbers mean, and what to do about them */
$ufPhpCodes = [
    1 => ['The file was bigger than upload_max_filesize in php.ini', 'Raise upload_max_filesize and post_max_size in php.ini, then restart Apache.'],
    2 => ['The file was bigger than the limit set by the upload form', 'Check the maximum size the upload form allows.'],
    3 => ['The file was only partly uploaded', 'Usually a dropped connection. Ask the user to try again.'],
    4 => ['No file was uploaded', 'The user submitted the form without choosing a file.'],
    6 => ['The server has no temporary folder', 'Set upload_tmp_dir in php.ini to a folder that exists, then restart Apache.'],
    7 => ['The server could not write the file to disk', 'Check free disk space and the permissions of the uploads folder.'],
    8 => ['A PHP extension stopped the upload', 'Check the PHP error log for the extension that blocked it.'],
];

$ufRows = [];
$ufByCode = [];
$ufCount7 = 0;
$ufTotal = 0;
$ufError = '';

try {
    if (isset($pdo) && $pdo instanceof PDO && vs_upload_failure_ensure($pdo)) {
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));

        $ufTotal = (int)$pdo->query('SELECT COUNT(*) FROM `upload_failure_events`')->fetchColumn();

        $st = $pdo->prepare('SELECT COUNT(*) FROM `upload_failure_events` WHERE `created_at` >= :since');
        $st->execute([':since' => $since]);
        $ufCount7 = (int)$st->fetchColumn();

        $st = $pdo->prepare(
            'SELECT `failure_code`, `php_error_code`, COUNT(*) AS n FROM `upload_failure_events` '
            . 'WHERE `created_at` >= :since GROUP BY `failure_code`, `php_error_code` ORDER BY n DESC LIMIT 8'
        );
        $st->execute([':since' => $since]);
        $ufByCode = $st->fetchAll(PDO::FETCH_ASSOC);

        $ufRows = $pdo->query(
            'SELECT `username`, `content_type`, `upload_mode`, `failure_code`, `php_error_code`, `created_at` '
            . 'FROM `upload_failure_events` ORDER BY `created_at` DESC LIMIT 50'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $ufError = 'The failed-uploads log is not available yet.';
    }
} catch (Throwable $e) {
    error_log('upload-failures.php could not read the log: ' . $e->getMessage());
    $ufError = 'The failed-uploads log could not be read.';
}

include "header.php";
?>
      <link rel="stylesheet" href="./assets/css/vs-neon.css?v=4">
      <link rel="stylesheet" href="./assets/css/vs-dashboard.css?v=6">
      <link rel="stylesheet" href="./assets/css/vs-dashboard-theme.css?v=1">

<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-3" id="vsdPage">

    <section class="vsd-card" aria-label="Failed uploads summary">
      <div class="vsd-head">
        <span class="vsd-hico plain"><i class="bi bi-cloud-slash" aria-hidden="true"></i></span>
        <h2>Failed uploads</h2>
        <a class="vsd-btn ms-auto" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to dashboard</a>
      </div>

      <?php if ($ufError !== ''): ?>
        <div class="vsd-empty"><?php echo vs_e($ufError); ?></div>
      <?php else: ?>
        <p class="mb-2"><strong><?php echo (int)$ufCount7; ?></strong> failed in the last 7 days &middot; <strong><?php echo (int)$ufTotal; ?></strong> in total</p>

        <?php if (!$ufByCode): ?>
          <div class="vsd-empty">No failed uploads in the last 7 days.</div>
        <?php else: ?>
          <h3 class="h6 mt-3 mb-2">Most common causes this week</h3>
          <ul class="mb-0">
            <?php foreach ($ufByCode as $c):
                $phpCode = (int)($c['php_error_code'] ?? 0);
                $info = $ufPhpCodes[$phpCode] ?? null;
            ?>
              <li class="mb-2">
                <strong><?php echo (int)$c['n']; ?>&times;</strong>
                <?php echo vs_e((string)$c['failure_code']); ?>
                <?php if ($info): ?>
                  <br><span class="opacity-75"><?php echo vs_e($info[0]); ?>.</span>
                  <br><strong>How to fix:</strong> <?php echo vs_e($info[1]); ?>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <?php if ($ufError === '' && $ufRows): ?>
      <section class="vsd-card mt-3" aria-label="Latest failed uploads">
        <div class="vsd-head">
          <span class="vsd-hico"><i class="bi bi-list-ul" aria-hidden="true"></i></span>
          <h2>Latest 50</h2>
        </div>
        <div class="table-responsive">
          <table class="vsd-tbl">
            <thead><tr><th>When</th><th>User</th><th>Type</th><th>Mode</th><th>Reason</th><th>PHP code</th></tr></thead>
            <tbody>
              <?php foreach ($ufRows as $r): ?>
                <tr>
                  <td><?php echo vs_e((string)$r['created_at']); ?></td>
                  <td><?php echo vs_e((string)$r['username']); ?></td>
                  <td><?php echo vs_e((string)$r['content_type']); ?></td>
                  <td><?php echo vs_e((string)$r['upload_mode']); ?></td>
                  <td><?php echo vs_e((string)$r['failure_code']); ?></td>
                  <td><?php echo (int)$r['php_error_code'] > 0 ? (int)$r['php_error_code'] : '-'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

  </div>
</main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>  Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a> </span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>
