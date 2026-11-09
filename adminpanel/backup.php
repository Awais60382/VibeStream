<?php
/*
 * VibeStream admin panel - Backup page.
 * Put this file in the adminpanel folder, next to backup-core.php (the engine).
 * Create a backup (database + uploads, or database only), download it, delete it.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/backup-core.php';
require_once __DIR__ . '/system-check-core.php';   // e-mail sender used for alerts
require_once __DIR__ . '/owner-guard.php';          // vs_is_owner()

/* ---------- OWNER ONLY ----------
 * Backups hold the whole database (emails, password hashes) and every uploaded file,
 * and Restore can replace the live site. Checked on the SERVER before anything else runs
 * (download, checksum, create, upload, delete, restore, test e-mail), so a normal admin
 * cannot get in even by typing a backup.php URL by hand. */
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo "<script>alert('Access Denied: Only the site owner can open Backups.'); window.location.href='index.php';</script>";
    exit;
}

function bk_e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['backup_csrf'])) {
    $_SESSION['backup_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['backup_csrf'];
$by   = (string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'admin');

function bk_flash(string $type, string $text): void
{
    $_SESSION['backup_flash'] = ['type' => $type, 'text' => $text];
}

function bk_redirect(): void
{
    header('Location: backup.php');
    exit;
}

/* ---------- download (GET) ---------- */
if (isset($_GET['download'])) {
    $name = basename((string)$_GET['download']);
    $path = vs_backup_dir() . '/' . $name;
    if (!hash_equals($csrf, (string)($_GET['t'] ?? '')) || !vs_backup_valid_name($name) || !is_file($path)) {
        bk_flash('danger', 'That backup could not be downloaded. Refresh the page and try again.');
        bk_redirect();
    }
    $mime = vs_backup_ends($name, '.zip') ? 'application/zip' : (vs_backup_ends($name, '.sql.gz') ? 'application/gzip' : 'application/sql');
    vs_backup_stream($path, $name, $mime);
    exit;
}

/* ---------- checksum download (GET) ---------- */
if (isset($_GET['checksum'])) {
    $name = basename((string)$_GET['checksum']);
    $path = vs_backup_dir() . '/' . $name;
    if (!hash_equals($csrf, (string)($_GET['t'] ?? '')) || !vs_backup_valid_name($name) || !is_file($path)) {
        bk_flash('danger', 'That backup checksum could not be downloaded. Refresh the page and try again.');
        bk_redirect();
    }
    $sha256 = hash_file('sha256', $path);
    if ($sha256 === false) {
        bk_flash('danger', 'Could not calculate the backup checksum.');
        bk_redirect();
    }
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $name . '.sha256"');
    echo $sha256 . '  ' . $name . "\n";
    exit;
}

/* ---------- actions (POST) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        error_log('backup csrf: session=' . ($_SESSION['backup_csrf'] ?? 'MISSING') . ' posted=' . ($_POST['csrf'] ?? 'MISSING')); // TEMPORARY debug line, remove once fixed
        bk_flash('danger', 'Your session expired. Please try again.');
        bk_redirect();
    }
    $action = (string)($_POST['action'] ?? '');
    $hasPdo = isset($pdo) && ($pdo instanceof PDO);

    if ($action === 'create') {
        $format = (string)($_POST['format'] ?? 'zip');
        if (!$hasPdo) {
            bk_flash('danger', 'No database connection found. Check that ../connection.php creates $pdo.');
            bk_redirect();
        }
        try {
            $entry = vs_backup_create($pdo, $format, $by);
            vs_backup_history_add($entry);
            vs_backup_prune();
            $msg = 'Backup saved: ' . $entry['file'] . ' (' . vs_backup_human($entry['size']) . ', ' . $entry['tables'] . ' tables, ' . $entry['rows'] . ' rows' . ($entry['files'] > 0 ? ', ' . $entry['files'] . ' uploaded files' : '') . '). It was opened again and checked.';
            if ($entry['copy'] === 'ok') {
                $msg .= ' An extra copy was saved in ' . VS_BACKUP_COPY_DIR . '.';
            } elseif ($entry['copy'] !== '') {
                $msg .= ' Extra copy ' . $entry['copy'] . '.';
            }
            bk_flash($entry['copy'] !== '' && $entry['copy'] !== 'ok' ? 'warning' : 'success', $msg);
        } catch (Throwable $e) {
            bk_flash('danger', 'Backup failed: ' . $e->getMessage());
            vs_backup_notify('backup-fail-manual', 'VibeStream: a backup FAILED', "A backup started from the admin panel failed.\n\nReason: " . $e->getMessage(), 3600);
        }
        bk_redirect();
    }

    if ($action === 'upload') {
        try {
            $entry = vs_backup_accept_upload($_FILES['backup_file'] ?? [], $by);
            vs_backup_history_add($entry);
            vs_backup_prune();
            bk_flash('success', 'Backup uploaded and checked: ' . $entry['file'] . ' (' . $entry['tables'] . ' tables). You can restore it from the list below.');
        } catch (Throwable $e) {
            bk_flash('danger', $e->getMessage());
        }
        bk_redirect();
    }

    $name = basename((string)($_POST['file'] ?? ''));
    $path = vs_backup_dir() . '/' . $name;

    if ($action === 'delete') {
        if (vs_backup_valid_name($name) && is_file($path) && @unlink($path)) {
            vs_backup_pin($name, false);
            bk_flash('success', 'Deleted ' . $name . '.');
        } else {
            bk_flash('danger', 'Could not delete that backup.');
        }
        bk_redirect();
    }

    if ($action === 'pin' || $action === 'unpin') {
        if (vs_backup_valid_name($name) && is_file($path)) {
            vs_backup_pin($name, $action === 'pin');
            bk_flash('success', $action === 'pin' ? $name . ' is marked Keep: it will never be deleted automatically.' : $name . ' is no longer marked Keep.');
        }
        bk_redirect();
    }

    if ($action === 'check') {
        try {
            if (!vs_backup_valid_name($name) || !is_file($path)) {
                throw new RuntimeException('That backup was not found.');
            }
            $info = vs_backup_inspect($path);
            $sha256 = hash_file('sha256', $path);
            if ($sha256 === false) {
                throw new RuntimeException('Could not calculate the backup checksum.');
            }
            $recordedSha = '';
            foreach (vs_backup_history() as $historyEntry) {
                if (($historyEntry['file'] ?? '') === $name) {
                    $recordedSha = (string)($historyEntry['sha256'] ?? '');
                    break;
                }
            }
            if ($recordedSha !== '' && !hash_equals($recordedSha, $sha256)) {
                throw new RuntimeException('The backup checksum does not match the saved history. The file may have changed or been damaged.');
            }
            $checksumStatus = $recordedSha !== '' ? ' Its SHA-256 checksum matches the saved history.' : ' No saved checksum baseline exists; the Checksum download can verify copies against this file.';
            bk_flash('success', $name . ' is complete and readable: ' . $info['tables'] . ' tables, ' . number_format($info['inserts']) . ' insert blocks, ' . $info['statements'] . ' SQL steps' . ($info['uploads'] > 0 ? ', ' . $info['uploads'] . ' uploaded files' : '') . '.' . $checksumStatus);
        } catch (Throwable $e) {
            bk_flash('danger', 'Check failed: ' . $e->getMessage());
        }
        bk_redirect();
    }

    if ($action === 'restore') {
        if (strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'RESTORE') {
            bk_flash('danger', 'Nothing was restored: you must type RESTORE in the box to confirm.');
            bk_redirect();
        }
        if (!$hasPdo) {
            bk_flash('danger', 'No database connection found. Check that ../connection.php creates $pdo.');
            bk_redirect();
        }
        try {
            $r = vs_backup_restore($pdo, $name, !empty($_POST['with_uploads']), $by);
            bk_flash('success', 'Restored ' . $name . ' (' . number_format($r['statements']) . ' steps' . ($r['uploads'] > 0 ? ', ' . $r['uploads'] . ' uploaded files' : '') . '). Your database as it was just before is saved as ' . $r['safety'] . ' (marked Keep). You may be signed out: sign in again.');
            vs_backup_notify('restore-done', 'VibeStream: a backup was restored', 'The backup ' . $name . " was restored by {$by}.\nThe database as it was before is saved as " . $r['safety'] . '.');
        } catch (Throwable $e) {
            bk_flash('danger', 'Restore failed: ' . $e->getMessage());
            vs_backup_notify('restore-fail', 'VibeStream: a restore FAILED', 'Restoring ' . $name . " failed.\n\n" . $e->getMessage());
        }
        bk_redirect();
    }

    if ($action === 'test_mail') {
        $ok = vs_syscheck_mail('VibeStream: test e-mail from Backups', "This is a test from the VibeStream Backups page.\nIf you can read this, backup and problem alerts reach you by e-mail.\n");
        bk_flash($ok ? 'success' : 'danger', $ok ? 'Test e-mail sent. Check your inbox (and the spam folder).' : 'The test e-mail could not be sent. ' . vs_syscheck_mail_last_error());
        bk_redirect();
    }

    bk_redirect();
}

/* ---------- data for the page ---------- */
$flash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);

vs_backup_ensure_dir();
$files   = vs_backup_files();
$history = vs_backup_history();
$pinned  = vs_backup_pinned();
$meta    = [];
foreach ($history as $h) {
    if (!empty($h['file']) && !isset($meta[$h['file']])) {
        $meta[$h['file']] = $h;
    }
}

$totalSize = 0;
$rows = [];
foreach ($files as $f) {
    $p  = vs_backup_dir() . '/' . $f;
    $sz = (int)@filesize($p);
    $ts = (int)@filemtime($p);
    $totalSize += $sz;
    $rows[] = [
        'name' => $f, 'size' => $sz, 'time' => $ts, 'by' => (string)($meta[$f]['by'] ?? ''),
        'sha' => (string)($meta[$f]['sha256'] ?? ''), 'full' => vs_backup_ends($f, '.zip'), 'kept' => in_array($f, $pinned, true),
    ];
}
$lastTs  = $rows ? $rows[0]['time'] : 0;
$isStale = !$rows || (time() - $lastTs) > VS_BACKUP_STALE_DAYS * 86400;
$hasZip  = class_exists('ZipArchive');
$hasGz   = function_exists('gzopen');

$disk     = vs_backup_disk();
$beat     = vs_backup_heartbeat_read();
$beatTs   = (int)($beat['time'] ?? 0);
$beatLate = $beatTs === 0 || (time() - $beatTs) > 26 * 3600;
$mailReady = function_exists('vs_syscheck_mail_configured') && vs_syscheck_mail_configured();
$mailTo   = defined('VS_OWNER_EMAIL') ? (string)VS_OWNER_EMAIL : '';
$mailLog  = function_exists('vs_syscheck_mail_log_read') ? array_slice(vs_syscheck_mail_log_read(), 0, 6) : [];

// The Task Scheduler command for this computer (php.exe, not Apache's httpd.exe).
$phpExe = basename((string)PHP_BINARY) === 'php.exe' || basename((string)PHP_BINARY) === 'php' ? (string)PHP_BINARY : '';
if ($phpExe === '') {
    $guess = dirname((string)PHP_BINARY, 3) . '/php/php.exe';
    $phpExe = is_file($guess) ? $guess : 'C:\\xampp\\php\\php.exe';
}
$phpExe = str_replace('/', '\\', $phpExe);
$monitorPath = str_replace('/', '\\', (string)(realpath(__DIR__ . '/system-monitor.php') ?: __DIR__ . '/system-monitor.php'));
$taskCmd = 'schtasks /Create /TN "VibeStream Monitor" /SC HOURLY /MO 1 /TR "\\"' . $phpExe . '\\" \\"' . $monitorPath . '\\"" /F';

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
$flashClass = ['success' => 'success', 'warning' => 'warning'][$flash['type'] ?? ''] ?? 'danger';
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-hdd-stack" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">System</p>
                <h1 class="h3 mb-1">Backups</h1>
                <p class="text-muted mb-0">Save a copy of your database and uploaded files, check it, and put it back when you need it. Every backup is opened again and checked before it is kept.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
          </div>

          <?php if ($flash): ?>
            <div class="alert alert-<?php echo $flashClass; ?> mt-3" role="alert"><?php echo bk_e($flash['text']); ?></div>
          <?php endif; ?>
          <?php if ($isStale): ?>
            <div class="alert alert-warning mt-3" role="alert">
              <?php echo $rows ? 'Your last backup is ' . bk_e(vs_backup_ago($lastTs)) . '. Make a new one.' : 'No backup has been made yet. Make your first one below.'; ?>
            </div>
          <?php endif; ?>
          <?php if (!$mailReady): ?>
            <div class="alert alert-warning mt-3" role="alert">
              <strong>E-mail alerts are not set up.</strong> Without them nobody tells you when a backup fails. Open <a href="system-check.php">System check</a> and fill in the e-mail settings (only the app password is missing), then use <em>Send a test e-mail</em> below.
            </div>
          <?php endif; ?>
          <?php if ($beatLate): ?>
            <div class="alert alert-warning mt-3" role="alert">
              <strong>Automatic backups are not running.</strong> The background monitor <?php echo $beatTs ? 'last ran ' . bk_e(vs_backup_ago($beatTs)) : 'has never run'; ?>. Set it up once (see "Automatic backups" below).
            </div>
          <?php endif; ?>

          <section class="row g-3 mt-1" aria-label="Backup summary">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top"><span class="metric-label">Saved backups</span><span class="metric-icon"><i class="bi bi-archive" aria-hidden="true"></i></span></div>
                <div class="metric-value"><?php echo count($rows); ?></div>
                <div class="metric-meta"><span>newest <?php echo (int)VS_BACKUP_KEEP; ?> + backups marked Keep</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card <?php echo $isStale ? 'metric-danger' : 'metric-success'; ?>">
                <div class="metric-top"><span class="metric-label">Last backup</span><span class="metric-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span></div>
                <div class="metric-value" style="font-size:1.4rem;"><?php echo $rows ? bk_e(vs_backup_ago($lastTs)) : 'Never'; ?></div>
                <div class="metric-meta"><span>warns after <?php echo (int)VS_BACKUP_STALE_DAYS; ?> days</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top"><span class="metric-label">Space used</span><span class="metric-icon"><i class="bi bi-hdd" aria-hidden="true"></i></span></div>
                <div class="metric-value" style="font-size:1.4rem;"><?php echo bk_e(vs_backup_human($totalSize)); ?></div>
                <div class="metric-meta"><span><?php echo $disk ? bk_e(vs_backup_human($disk['free'])) . ' free on the disk' : 'in the backups folder'; ?></span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card <?php echo $beatLate ? 'metric-danger' : 'metric-success'; ?>">
                <div class="metric-top"><span class="metric-label">Monitor</span><span class="metric-icon"><i class="bi bi-activity" aria-hidden="true"></i></span></div>
                <div class="metric-value" style="font-size:1.4rem;"><?php echo $beatTs ? bk_e(vs_backup_ago($beatTs)) : 'Never ran'; ?></div>
                <div class="metric-meta"><span><?php echo $beatTs && empty($beat['ok']) ? 'last run found problems' : 'checks, backups and e-mail alerts'; ?></span></div>
              </article>
            </div>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-plus-circle" aria-hidden="true"></i><span>Make a backup</span></h2>
                <p class="text-muted mb-0">Large sites can take a minute. Keep this page open until it finishes.<?php echo trim((string)VS_BACKUP_COPY_DIR) !== '' ? ' An extra copy is also saved in ' . bk_e(VS_BACKUP_COPY_DIR) . '.' : ''; ?></p>
              </div>
            </div>
            <form method="post" action="backup.php" class="row g-2 align-items-end" onsubmit="var b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='Working...';">
              <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
              <input type="hidden" name="action" value="create">
              <div class="col-12 col-md-6">
                <label class="form-label" for="bk-format">Backup type</label>
                <select class="form-select" id="bk-format" name="format">
                  <option value="zip"<?php echo $hasZip ? '' : ' disabled'; ?>>Full backup: database + uploaded files (.zip)<?php echo $hasZip ? '' : ' - zip not enabled in PHP'; ?></option>
                  <option value="gz"<?php echo $hasGz ? '' : ' disabled'; ?>>Database only, compressed (.sql.gz)<?php echo $hasGz ? '' : ' - zlib not enabled in PHP'; ?></option>
                  <option value="sql">Database only, plain (.sql)</option>
                </select>
              </div>
              <div class="col-12 col-md-auto">
                <button class="btn btn-primary" type="submit"><i class="bi bi-cloud-arrow-down" aria-hidden="true"></i> Make backup now</button>
              </div>
            </form>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-archive" aria-hidden="true"></i><span>Saved backups</span></h2>
                <p class="text-muted mb-0"><strong>Check</strong> opens a backup and makes sure it is complete. <strong>Keep</strong> protects a backup from being deleted. <strong>Restore</strong> puts it back (a safety backup of your current data is made first).</p>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">File</th>
                    <th scope="col">Made</th>
                    <th scope="col">By</th>
                    <th scope="col" class="text-end">Size</th>
                    <th scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                <?php if ($rows): foreach ($rows as $r): ?>
                  <tr>
                    <td>
                      <span class="fw-semibold"><?php echo bk_e($r['name']); ?></span>
                      <span class="badge text-bg-secondary ms-1"><?php echo $r['full'] ? 'database + files' : 'database only'; ?></span>
                      <?php if ($r['kept']): ?><span class="badge text-bg-info ms-1">Keep</span><?php endif; ?>
                      <?php if ($r['sha'] !== ''): ?><div class="small text-muted" title="Checksum (SHA-256): proves the file was not changed or damaged"><code><?php echo bk_e(substr($r['sha'], 0, 16)); ?>&hellip;</code></div><?php endif; ?>
                    </td>
                    <td class="text-muted"><?php echo bk_e(date('M d, Y H:i', $r['time'])); ?> <span class="small">(<?php echo bk_e(vs_backup_ago($r['time'])); ?>)</span></td>
                    <td class="text-muted"><?php echo $r['by'] !== '' ? bk_e($r['by']) : '&mdash;'; ?></td>
                    <td class="text-end"><?php echo bk_e(vs_backup_human($r['size'])); ?></td>
                    <td class="text-end">
                      <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                        <a class="btn btn-primary btn-sm" href="backup.php?download=<?php echo urlencode($r['name']); ?>&amp;t=<?php echo urlencode($csrf); ?>"><i class="bi bi-download" aria-hidden="true"></i> Download</a>
                        <a class="btn btn-outline-secondary btn-sm" href="backup.php?checksum=<?php echo urlencode($r['name']); ?>&amp;t=<?php echo urlencode($csrf); ?>" title="Download a SHA-256 checksum to verify this backup after copying it"><i class="bi bi-fingerprint" aria-hidden="true"></i> Checksum</a>
                        <form method="post" action="backup.php" class="m-0">
                          <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
                          <input type="hidden" name="file" value="<?php echo bk_e($r['name']); ?>">
                          <button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="check"><i class="bi bi-shield-check" aria-hidden="true"></i> Check</button>
                        </form>
                        <form method="post" action="backup.php" class="m-0">
                          <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
                          <input type="hidden" name="file" value="<?php echo bk_e($r['name']); ?>">
                          <button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="<?php echo $r['kept'] ? 'unpin' : 'pin'; ?>"><i class="bi bi-pin-angle" aria-hidden="true"></i> <?php echo $r['kept'] ? 'Unkeep' : 'Keep'; ?></button>
                        </form>
                        <form method="post" action="backup.php" class="m-0" onsubmit="return confirm('Delete this backup? This cannot be undone.');">
                          <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="file" value="<?php echo bk_e($r['name']); ?>">
                          <button class="btn btn-outline-danger btn-sm" type="submit"><i class="bi bi-trash" aria-hidden="true"></i> Delete</button>
                        </form>
                      </div>
                      <details class="mt-2 text-start">
                        <summary class="small text-warning" style="cursor:pointer;">Restore this backup&hellip;</summary>
                        <form method="post" action="backup.php" class="mt-2 p-2 border rounded" onsubmit="if(!confirm('Replace your current database with this backup?')){return false;}var b=this.querySelector('button[type=submit]');b.disabled=true;b.innerHTML='Restoring... keep this page open';">
                          <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="file" value="<?php echo bk_e($r['name']); ?>">
                          <p class="small text-danger mb-2"><strong>This replaces your current database</strong> with this backup. Anything added since <?php echo bk_e(date('M d, H:i', $r['time'])); ?> is lost, but a safety backup of the current data is saved first.</p>
                          <?php if ($r['full']): ?>
                            <label class="small d-flex align-items-center gap-2 mb-2"><input class="form-check-input mt-0" type="checkbox" name="with_uploads" value="1" checked> Also put back the uploaded files (videos, avatars, icons)</label>
                          <?php endif; ?>
                          <div class="d-flex flex-wrap gap-2">
                            <input class="form-control form-control-sm" style="max-width:220px;" name="confirm_text" placeholder="Type RESTORE to confirm" autocomplete="off" aria-label="Type RESTORE to confirm">
                            <button class="btn btn-warning btn-sm" type="submit"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Restore now</button>
                          </div>
                        </form>
                      </details>
                    </td>
                  </tr>
                <?php endforeach; else: ?>
                  <tr><td colspan="5" class="text-center text-muted py-4">No backups saved yet.</td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span>Upload a backup</span></h2>
                <p class="text-muted mb-0">Moving to a new computer, or got a backup from somewhere else? Upload the .zip, .sql.gz or .sql file here. It is checked first; then restore it from the list above.</p>
              </div>
            </div>
            <form method="post" action="backup.php" enctype="multipart/form-data" class="row g-2 align-items-end">
              <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
              <input type="hidden" name="action" value="upload">
              <div class="col-12 col-md-6">
                <label class="form-label" for="bk-upload">Backup file</label>
                <input class="form-control" id="bk-upload" type="file" name="backup_file" accept=".zip,.gz,.sql" required>
                <div class="form-text">PHP accepts files up to <?php echo bk_e(ini_get('upload_max_filesize')); ?>. For bigger files copy them into the "backups" folder by hand.</div>
              </div>
              <div class="col-12 col-md-auto"><button class="btn btn-outline-primary" type="submit">Upload and check</button></div>
            </form>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-envelope-check" aria-hidden="true"></i><span>E-mail alerts</span> <?php echo $mailReady ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-warning">Not set up</span>'; ?></h2>
                <p class="text-muted mb-0">
                  <?php echo $mailTo !== '' ? 'Alerts go to <strong>' . bk_e($mailTo) . '</strong>' : 'No owner e-mail is set in owner-guard.php.'; ?>.
                  You are e-mailed when: a backup fails, the newest backup is older than <?php echo (int)VS_BACKUP_STALE_DAYS; ?> days, the disk is almost full, the database is unreachable, a system check fails, the monitor crashes, a restore happens, and after each automatic backup.
                  <?php echo VS_BACKUP_EMAIL_COPY ? 'A copy of each automatic backup is attached.' : 'Attaching a copy of the backup is off (VS_BACKUP_EMAIL_COPY in backup-core.php).'; ?>
                </p>
              </div>
              <form method="post" action="backup.php" class="m-0">
                <input type="hidden" name="csrf" value="<?php echo bk_e($csrf); ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit" name="action" value="test_mail"><i class="bi bi-send" aria-hidden="true"></i> Send a test e-mail</button>
              </form>
            </div>
            <?php if ($mailLog): ?>
              <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                  <thead><tr><th scope="col">When</th><th scope="col">E-mail</th><th scope="col">Result</th></tr></thead>
                  <tbody>
                  <?php foreach ($mailLog as $m): ?>
                    <tr>
                      <td class="text-muted small"><?php echo bk_e(date('M d, H:i', (int)($m['time'] ?? 0))); ?></td>
                      <td><?php echo bk_e($m['subject'] ?? ''); ?></td>
                      <td class="small"><?php echo !empty($m['ok']) ? '<span class="text-success">Sent</span>' : '<span class="text-danger">Failed</span> ' . bk_e($m['error'] ?? ''); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php else: ?>
              <p class="text-muted small mb-0">No e-mail has been sent yet. Press the test button to check that it works.</p>
            <?php endif; ?>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-calendar-check" aria-hidden="true"></i><span>Automatic backups</span> <?php echo $beatLate ? '<span class="badge text-bg-warning">Not running</span>' : '<span class="badge text-bg-success">Running</span>'; ?></h2>
                <p class="text-muted mb-0">
                  <?php echo VS_AUTO_BACKUP_ENABLED ? 'A new backup is made every ' . (int)VS_AUTO_BACKUP_EVERY_DAYS . ' days (' . bk_e(strtoupper(VS_AUTO_BACKUP_FORMAT)) . ')' : 'Automatic backups are turned off'; ?>,
                  by the background monitor, which also checks your site and sends the e-mail alerts. Monitor last ran: <strong><?php echo $beatTs ? bk_e(vs_backup_ago($beatTs)) : 'never'; ?></strong>.
                  Change the settings at the top of <code>backup-core.php</code>.
                </p>
              </div>
            </div>
            <p class="small mb-1"><strong>Set it up once:</strong> double-click <code>schedule-monitor.bat</code> (in the adminpanel folder), or open Command Prompt as administrator and paste:</p>
            <pre class="small p-2 border rounded mb-0" style="white-space:pre-wrap;"><?php echo bk_e($taskCmd); ?></pre>
          </section>

          <?php if ($history): ?>
          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-clock-history" aria-hidden="true"></i><span>History</span></h2>
                <p class="text-muted mb-0">The last <?php echo min(10, count($history)); ?> backups that were made.</p>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr><th scope="col">When</th><th scope="col">By</th><th scope="col">Type</th><th scope="col" class="text-end">Tables</th><th scope="col" class="text-end">Rows</th><th scope="col" class="text-end">Files</th><th scope="col" class="text-end">Size</th><th scope="col">Extra copy</th></tr>
                </thead>
                <tbody>
                <?php foreach (array_slice($history, 0, 10) as $h): ?>
                  <tr>
                    <td><?php echo bk_e($h['created'] ?? ''); ?></td>
                    <td class="text-muted"><?php echo bk_e($h['by'] ?? ''); ?></td>
                    <td><?php echo bk_e(strtoupper((string)($h['format'] ?? ''))); ?></td>
                    <td class="text-end"><?php echo (int)($h['tables'] ?? 0); ?></td>
                    <td class="text-end"><?php echo number_format((int)($h['rows'] ?? 0)); ?></td>
                    <td class="text-end"><?php echo (int)($h['files'] ?? 0); ?></td>
                    <td class="text-end"><?php echo bk_e(vs_backup_human((int)($h['size'] ?? 0))); ?></td>
                    <td class="small <?php echo (($h['copy'] ?? '') === 'ok') ? 'text-success' : 'text-muted'; ?>"><?php echo bk_e(($h['copy'] ?? '') === '' ? '—' : (string)$h['copy']); ?></td>
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
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>