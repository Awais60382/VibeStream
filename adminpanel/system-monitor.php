<?php
/*
 * VibeStream - background monitor. Run from the command line / Windows Task Scheduler, never from the browser:
 *
 *   C:\xampppp\php\php.exe C:\xampppp\htdocs\VibeStream\adminpanel\system-monitor.php
 *
 * Every time it runs it:
 *   1. makes an automatic backup when the last one is older than AUTO_BACKUP_EVERY_DAYS,
 *   2. e-mails Premium members whose plan ends in 3 days or just ended,
 *   3. runs the same checks as system-check.php,
 *   4. when something fails: saves the alert (red bar in the admin panel) and e-mails the owner.
 * Schedule it once an hour (or once a day). Nobody needs to have the admin panel open.
 */
const AUTO_BACKUP_ENABLED    = true;   // set to false to turn automatic backups off
const AUTO_BACKUP_EVERY_DAYS = 7;      // make a new backup when the last one is older than this
const AUTO_BACKUP_FORMAT     = 'zip';  // 'zip' = database + uploads, 'gz' = database only (small), 'sql' = database only

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run this file from the command line only.');
}

$pdo = null;
try {
    ob_start();
    include __DIR__ . '/../connection.php';   // creates $pdo
    ob_end_clean();
} catch (Throwable $e) {
    $pdo = null;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    $pdo = null;
}

require_once __DIR__ . '/system-check-core.php';
require_once __DIR__ . '/backup-core.php';

/* ---- 1. automatic backup ---- */
if (AUTO_BACKUP_ENABLED && $pdo) {
    try {
        $history = vs_backup_history();
        $lastTs = $history ? (int)strtotime((string)($history[0]['created'] ?? '')) : 0;
        if ($lastTs === 0 || (time() - $lastTs) >= AUTO_BACKUP_EVERY_DAYS * 86400) {
            $format = AUTO_BACKUP_FORMAT;
            if ($format === 'zip' && !class_exists('ZipArchive')) {
                $format = function_exists('gzopen') ? 'gz' : 'sql';
            } elseif ($format === 'gz' && !function_exists('gzopen')) {
                $format = 'sql';
            }
            $entry = vs_backup_create($pdo, $format, 'automatic');
            $entry['kept'] = true;
            vs_backup_history_add($entry);
            vs_backup_prune();
            echo date('Y-m-d H:i:s') . ' - Automatic backup saved: ' . $entry['file'] . ' (' . vs_backup_human($entry['size']) . ')' . PHP_EOL;
        }
        $st = vs_syscheck_state();
        if (($st['auto_backup_error'] ?? '') !== '') {
            unset($st['auto_backup_error']);
            vs_syscheck_save($st);
        }
    } catch (Throwable $e) {
        $st = vs_syscheck_state();
        $st['auto_backup_error'] = mb_substr($e->getMessage(), 0, 300);
        vs_syscheck_save($st);
        echo date('Y-m-d H:i:s') . ' - Automatic backup FAILED: ' . $e->getMessage() . PHP_EOL;
    }
}

/* ---- Premium reminders: "ends in 3 days" and "has ended" e-mails to members (each sent once) ---- */
if ($pdo && is_file(__DIR__ . '/../premium-store.php')) {
    try {
        require_once __DIR__ . '/../premium-store.php';
        $n = vs_premium_send_reminders($pdo);
        if ($n > 0) {
            echo date('Y-m-d H:i:s') . ' - ' . $n . ' Premium reminder e-mail(s) sent.' . PHP_EOL;
        }
    } catch (Throwable $e) {
        echo date('Y-m-d H:i:s') . ' - Premium reminders failed: ' . $e->getMessage() . PHP_EOL;
    }
}

/* ---- 2 + 3. checks and alerts ---- */
$state = vs_syscheck_record(vs_syscheck_run($pdo));
$bad = count($state['bad']);
echo date('Y-m-d H:i:s') . ' - ' . ($bad === 0 ? 'OK, no problems.' : $bad . ' problem(s): ' . implode(', ', array_column($state['bad'], 'label'))) . PHP_EOL;
exit($bad === 0 ? 0 : 1);