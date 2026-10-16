<?php
/*
 * VibeStream admin panel - System check (OWNER ONLY).
 * One page that tells you if something is missing or set up wrongly: files in the wrong folder,
 * missing database tables, folders that cannot be written, PHP settings, and security warnings.
 * When something fails you are told: a red bar on every admin page, an entry in the alert history
 * below, and an e-mail to the owner (if PHP mail works on your server).
 * Put this file in the adminpanel folder, together with system-check-core.php.
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
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/system-check-core.php';
require_once __DIR__ . '/owner-alert.php';

// Only the owner (VS_OWNER_EMAIL in owner-guard.php) may open this page.
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can open the system check.</p>'
        . '<p><a href="index.php">Back to the admin panel</a></p></body></html>';
    exit;
}

if (empty($_SESSION['syscheck_csrf'])) {
    $_SESSION['syscheck_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['syscheck_csrf'];

/* ---- Buttons on this page ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('That request expired. Go back, refresh the page and try again.');
    }
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'ack') {
        $st = vs_syscheck_state();
        $st['acknowledged'] = true;
        vs_syscheck_save($st);
        $_SESSION['syscheck_flash'] = ['success', 'Got it. The red bar is hidden and no more reminder e-mails are sent until a different problem appears.'];
    } elseif ($act === 'fix') {
        [$worked, $msg] = vs_syscheck_fix((string)($_POST['fix'] ?? ''), $pdo);
        $_SESSION['syscheck_flash'] = [$worked ? 'success' : 'danger', $msg];
    } elseif ($act === 'save_mail') {
        $old = vs_syscheck_mail_settings();
        $newPass = (string)($_POST['smtp_pass'] ?? '');
        $secure = in_array($_POST['smtp_secure'] ?? '', ['tls', 'ssl'], true) ? (string)$_POST['smtp_secure'] : 'tls';
        $cfg = [
            'host'   => trim((string)($_POST['smtp_host'] ?? '')),
            'port'   => max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
            'secure' => $secure,
            'user'   => trim((string)($_POST['smtp_user'] ?? '')),
            // Gmail shows app passwords with spaces; they are not part of the password.
            'pass'   => $newPass !== '' ? str_replace(' ', '', $newPass) : (string)$old['pass'],
            'from'   => trim((string)($_POST['smtp_from'] ?? '')),
            'verify' => empty($_POST['smtp_skip_verify']),
        ];
        $_SESSION['syscheck_flash'] = vs_syscheck_mail_save($cfg)
            ? ['success', 'E-mail settings saved. Press "Send a test e-mail" to check them.']
            : ['danger', 'Could not save the settings. Check that the backups folder is writable.'];
    } elseif ($act === 'test_mail') {
        $ok = vs_syscheck_mail('VibeStream: test alert', "This is a test alert from the VibeStream system check.\nIf you can read this, failure alerts reach you by e-mail.\n");
        $_SESSION['syscheck_flash'] = $ok
            ? ['success', 'Test e-mail sent. Check your inbox (and the spam folder).']
            : ['danger', 'The test e-mail could not be sent. ' . vs_syscheck_mail_last_error()];
    }
    header('Location: system-check.php');
    exit;
}
$flash = $_SESSION['syscheck_flash'] ?? null;
unset($_SESSION['syscheck_flash']);

/* ---- Run the checks and save the result (this is what raises an alert) ---- */
$result = vs_syscheck_run($pdo);
$groups = $result['groups'];
$tally = $result['tally'];
$state = vs_syscheck_record($result);
$history = (array)($state['history'] ?? []);
$ownerAlerts = vs_owner_alert_list(25);
$mailTo = defined('VS_OWNER_EMAIL') ? (string)VS_OWNER_EMAIL : '';
$mail = vs_syscheck_mail_settings();
$mailReady = vs_syscheck_mail_configured();

$badge = ['ok' => ['text-bg-success', 'OK'], 'warn' => ['text-bg-warning', 'Check'], 'bad' => ['text-bg-danger', 'Problem']];

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-heart-pulse" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner tools</p>
                <h1 class="h3 mb-1">System check</h1>
                <p class="text-muted mb-0">Finds missing files, wrong folders, missing tables and risky settings. Only the owner can see this page.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="system-check.php"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Check again</a>
            </div>
          </div>

          <?php if (is_array($flash)): ?>
            <div class="alert alert-<?php echo vs_e($flash[0]); ?> mt-3 mb-0" role="status"><?php echo vs_e($flash[1]); ?></div>
          <?php endif; ?>

          <div class="alert alert-<?php echo $tally['bad'] > 0 ? 'danger' : ($tally['warn'] > 0 ? 'warning' : 'success'); ?> mt-3 mb-0 d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
            <span>
              <?php if ($tally['bad'] === 0 && $tally['warn'] === 0): ?>
                <strong>Everything looks good.</strong>
              <?php else: ?>
                <strong><?php echo (int)$tally['bad']; ?> problem<?php echo $tally['bad'] === 1 ? '' : 's'; ?></strong> and
                <strong><?php echo (int)$tally['warn']; ?> thing<?php echo $tally['warn'] === 1 ? '' : 's'; ?> to check.</strong>
                Details are marked below.
                <?php if ($state['bad']): ?>
                  <div class="small mt-1">Problems: <?php echo vs_e(implode(', ', array_map(static fn($b) => $b['label'], $state['bad']))); ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </span>
            <?php if ($tally['bad'] > 0 && empty($state['acknowledged'])): ?>
              <form method="post" class="mb-0">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <button type="submit" name="action" value="ack" class="btn btn-outline-danger btn-sm">Got it, stop reminding me</button>
              </form>
            <?php elseif ($tally['bad'] > 0): ?>
              <span class="small">You already acknowledged these problems.</span>
            <?php endif; ?>
          </div>

          <?php if (!empty($result['fixes'])): ?>
            <section class="panel mt-3" aria-label="Quick repairs">
              <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i><span>Quick repairs</span></h2>
                <p class="text-muted mb-0">These problems can be fixed with one click.</p>
              </div>
              <div class="d-flex flex-column gap-2 p-3 pt-0">
                <?php foreach ($result['fixes'] as $fixId => $fixLabel): ?>
                  <?php if ($fixId === 'owner_lock'): ?>
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                      <span><?php echo vs_e($fixLabel); ?></span>
                      <a class="btn btn-primary btn-sm" href="install-owner-lock.php">Open it</a>
                    </div>
                  <?php else: ?>
                  <form method="post" class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-0">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <input type="hidden" name="action" value="fix">
                    <input type="hidden" name="fix" value="<?php echo vs_e($fixId); ?>">
                    <span><?php echo vs_e($fixLabel); ?></span>
                    <button type="submit" class="btn btn-primary btn-sm"<?php echo $fixId === 'make_backup' ? ' onclick="this.textContent=\'Working, please wait...\';"' : ''; ?>>Fix it</button>
                  </form>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <?php foreach ($groups as $title => $rows): ?>
            <section class="panel mt-3" aria-label="<?php echo vs_e($title); ?>">
              <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><span><?php echo vs_e($title); ?></span></h2>
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <tbody>
                    <?php foreach ($rows as [$label, $status, $detail]): ?>
                      <tr>
                        <td style="width:90px"><span class="badge <?php echo vs_e($badge[$status][0]); ?>"><?php echo vs_e($badge[$status][1]); ?></span></td>
                        <td class="fw-semibold" style="width:280px"><?php echo vs_e($label); ?></td>
                        <td class="text-muted small"><?php echo vs_e($detail); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endforeach; ?>

          <section class="panel mt-3" aria-label="Failure alerts">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div>
                <h2 class="h5 mb-1 section-title"><span>Failure alerts</span></h2>
                <p class="text-muted mb-0">
                  When a check fails you get a red bar on every admin page and an e-mail<?php echo $mailTo !== '' ? ' to ' . vs_e($mailTo) : ''; ?>.
                  The e-mail only works if your server can send mail; use the test button to find out.
                </p>
              </div>
              <form method="post" class="mb-0">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <button type="submit" name="action" value="test_mail" class="btn btn-outline-secondary btn-sm">Send a test e-mail</button>
              </form>
            </div>
            <form method="post" class="p-3 pt-0" autocomplete="off">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="action" value="save_mail">
              <p class="small mb-2">
                <strong>E-mail settings</strong> <?php echo $mailReady ? '<span class="badge text-bg-success">Set</span>' : '<span class="badge text-bg-warning">Not set up</span>'; ?>
                <?php if (!$mailReady): ?>
                  <span class="text-muted">Everything is already filled in for you from the owner e-mail. <strong>Only the app password is missing.</strong></span>
                  <?php if (stripos((string)$mail['host'], 'gmail') !== false): ?>
                    <br><span class="text-muted">Create one here (needs 2-Step Verification on): <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer">myaccount.google.com/apppasswords</a>. Name it "VibeStream", copy the 16 letters, paste them below and press Save.</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">Press "Send a test e-mail" to check the settings.</span>
                <?php endif; ?>
              </p>
              <div class="row g-2">
                <div class="col-12 col-md-4"><input class="form-control form-control-sm" name="smtp_host" value="<?php echo vs_e($mail['host']); ?>" placeholder="SMTP host" aria-label="SMTP host"></div>
                <div class="col-6 col-md-2"><input class="form-control form-control-sm" name="smtp_port" value="<?php echo vs_e($mail['port']); ?>" placeholder="Port" aria-label="Port"></div>
                <div class="col-6 col-md-2">
                  <select class="form-select form-select-sm" name="smtp_secure" aria-label="Security">
                    <option value="tls"<?php echo $mail['secure'] === 'tls' ? ' selected' : ''; ?>>TLS (587)</option>
                    <option value="ssl"<?php echo $mail['secure'] === 'ssl' ? ' selected' : ''; ?>>SSL (465)</option>
                  </select>
                </div>
                <div class="col-12 col-md-4"><input class="form-control form-control-sm" type="email" name="smtp_user" value="<?php echo vs_e($mail['user']); ?>" placeholder="Your e-mail (username)" aria-label="Username"></div>
                <div class="col-12 col-md-4"><input class="form-control form-control-sm" type="password" name="smtp_pass" value="" placeholder="<?php echo $mailReady ? 'Password saved (leave empty to keep it)' : 'App password'; ?>" aria-label="Password" autocomplete="new-password"></div>
                <div class="col-12 col-md-4"><input class="form-control form-control-sm" type="email" name="smtp_from" value="<?php echo vs_e($mail['from']); ?>" placeholder="From address (optional)" aria-label="From address"></div>
                <div class="col-12 col-md-4 d-flex align-items-center">
                  <label class="small mb-0 d-flex align-items-center gap-2"><input class="form-check-input mt-0" type="checkbox" name="smtp_skip_verify" value="1"<?php echo empty($mail['verify']) ? ' checked' : ''; ?>> Skip certificate check (only if you get a certificate error)</label>
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-sm mt-2">Save e-mail settings</button>
            </form>
            <?php if (!$history): ?>
              <p class="text-center text-muted py-4 mb-0">No alerts so far.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">When</th><th scope="col">Alert</th><th scope="col">E-mail</th></tr></thead>
                  <tbody>
                    <?php foreach ($history as $h): ?>
                      <tr>
                        <td class="text-muted small text-nowrap"><?php echo vs_e(date('M j, g:i A', (int)($h['time'] ?? 0))); ?></td>
                        <td>
                          <span class="badge <?php echo ($h['type'] ?? '') === 'ok' ? 'text-bg-success' : 'text-bg-danger'; ?> me-1"><?php echo ($h['type'] ?? '') === 'ok' ? 'Fixed' : 'Problem'; ?></span>
                          <?php echo vs_e((string)($h['text'] ?? '')); ?>
                        </td>
                        <td class="text-muted small"><?php echo ($h['type'] ?? '') === 'ok' ? '' : (!empty($h['emailed']) ? 'Sent' : 'Not sent'); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3" aria-label="Owner alerts">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><span>Owner alerts</span></h2>
              <p class="text-muted mb-0">Important things that happen in the app (admin changes, deleted accounts, blocked attempts). Each one is also e-mailed to you, up to 10 an hour. Things you do yourself are not reported.</p>
            </div>
            <?php if (!$ownerAlerts): ?>
              <p class="text-center text-muted py-4 mb-0">Nothing to report so far.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">When</th><th scope="col">What happened</th><th scope="col">Who</th><th scope="col">E-mail</th></tr></thead>
                  <tbody>
                    <?php foreach ($ownerAlerts as $oa): ?>
                      <tr>
                        <td class="text-muted small text-nowrap"><?php echo vs_e(date('M j, g:i A', (int)($oa['time'] ?? 0))); ?></td>
                        <td><?php echo vs_e((string)($oa['subject'] ?? '')); ?><?php if (!empty($oa['details'])): ?><div class="text-muted small"><?php echo vs_e((string)$oa['details']); ?></div><?php endif; ?></td>
                        <td class="text-muted small"><?php echo vs_e((string)($oa['actor'] ?? '')); ?><?php echo !empty($oa['ip']) ? ' &middot; ' . vs_e((string)$oa['ip']) : ''; ?></td>
                        <td class="text-muted small"><?php echo !empty($oa['emailed']) ? 'Sent' : 'Not sent'; ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
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