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
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
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
    } elseif ($act === 'download_report') {
        $report = vs_syscheck_run($pdo);
        vs_syscheck_record($report);
        $lines = [
            'VibeStream System Check',
            'Generated: ' . date(DATE_ATOM),
            'Summary: ' . (int)$report['tally']['bad'] . ' problems, '
                . (int)$report['tally']['ok'] . ' passing, '
                . (int)$report['tally']['warn'] . ' warnings',
            '',
        ];
        foreach ($report['groups'] as $group => $rows) {
            $lines[] = $group;
            foreach ($rows as [$label, $status, $detail]) {
                $lines[] = sprintf('  [%s] %s%s', strtoupper($status), $label, $detail !== '' ? ': ' . $detail : '');
            }
            $lines[] = '';
        }
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="vibestream-system-check-' . date('Ymd-His') . '.txt"');
        echo implode("\r\n", $lines);
        exit;
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
$checkRows = [];
foreach ($groups as $group => $rows) {
    foreach ($rows as [$label, $status, $detail]) {
        $checkRows[] = ['group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
$badChecks = array_values(array_filter($checkRows, static fn($check) => $check['status'] === 'bad'));
$securityWarnings = array_values(array_filter($checkRows, static fn($check) => $check['group'] === 'Security' && $check['status'] === 'warn'));
$checkTotal = array_sum($tally);
$health = $checkTotal > 0 ? (int)round(($tally['ok'] / $checkTotal) * 100) : 100;
$dbVersion = 'Unavailable';
foreach ($groups['Database'] ?? [] as [$label, $status, $detail]) {
    if ($label === 'Connection' && preg_match('/\((?:MySQL\/MariaDB )?([^)]*)\)/', $detail, $versionMatch)) {
        $dbVersion = $versionMatch[1];
        break;
    }
}
$diskRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
$diskFree = @disk_free_space($diskRoot);
$diskTotal = @disk_total_space($diskRoot);
$diskUsed = $diskFree !== false && $diskTotal !== false ? max(0, $diskTotal - $diskFree) : false;
$diskLabel = $diskFree !== false && $diskTotal !== false
    ? number_format($diskUsed / 1073741824, 1) . ' GB / ' . number_format($diskTotal / 1073741824, 1) . ' GB'
    : 'Unavailable';
$checkStatus = static function (string $group, string $label) use ($groups): string {
    foreach ($groups[$group] ?? [] as [$checkLabel, $status]) {
        if ($checkLabel === $label) {
            return $status;
        }
    }
    return 'warn';
};
$phpStatus = $checkStatus('PHP', 'Version');
$databaseStatus = $checkStatus('Database', 'Connection');
$diskStatus = $checkStatus('Folders the site writes to', 'Free disk space');
$memoryStatus = $checkStatus('PHP', 'memory_limit');
$statusIcon = static function (string $status): string {
    return $status === 'ok' ? 'bi-check-circle-fill' : ($status === 'warn' ? 'bi-exclamation-circle-fill' : 'bi-x-circle-fill');
};
$repairForCheck = static function (string $group, string $label, string $detail) use ($result): ?string {
    $repairId = null;
    if ($group === 'Files in the main VibeStream folder' && stripos($detail, 'a copy is in the adminpanel folder') !== false) {
        $repairId = 'move_files';
    } elseif ($group === 'Folders the site writes to' && stripos($detail, 'does not exist') !== false) {
        $repairId = 'make_folders';
    } elseif ($group === 'Security') {
        if (stripos($label, 'backup') !== false) {
            $repairId = 'backups_htaccess';
        } elseif (stripos($label, 'uploads') !== false) {
            $repairId = 'uploads_htaccess';
        } elseif (stripos($label, 'private files') !== false || stripos($label, 'client_secret') !== false || preg_match('/\.(?:env|sql|zip)$/i', $label)) {
            $repairId = 'block_secrets';
        }
    } elseif ($group === 'Database' && stripos($label, 'owner lock') !== false) {
        $repairId = 'owner_lock';
    } elseif ($group === 'Numbers' && $label === 'Last backup') {
        $repairId = 'make_backup';
    }
    return $repairId !== null && isset($result['fixes'][$repairId]) ? $repairId : null;
};

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}
?>

      <style>
        html[data-theme="dark"] .admin-shell .system-check-page{
          --sc-text:#f6f1f1;--sc-muted:#b8a8aa;--sc-line:rgba(255,42,50,.34);
          --sc-panel:#0d0809;--sc-panel2:#150a0d;--sc-field:#100909;
        }
        html[data-theme="light"] .admin-shell .system-check-page{
          --sc-text:#291a1a;--sc-muted:#766565;--sc-line:rgba(205,24,32,.24);
          --sc-panel:#fff;--sc-panel2:#fffafa;--sc-field:#fff;
        }
        .admin-shell .system-check-page{color:var(--sc-text);min-width:0}
        html[data-theme="dark"] .admin-shell .system-check-page .page-heading{
          background:radial-gradient(520px 230px at 84% 0,rgba(255,30,40,.22),transparent 72%),linear-gradient(115deg,#170506,#090909 62%,#180506)!important;
          border:1px solid var(--sc-line)!important;border-radius:18px;box-shadow:0 12px 36px rgba(0,0,0,.28),0 0 24px rgba(255,30,40,.12)!important;
        }
        html[data-theme="light"] .admin-shell .system-check-page .page-heading{
          background:radial-gradient(520px 230px at 84% 0,rgba(255,30,40,.12),transparent 72%),linear-gradient(115deg,#fff,#fff8f8 62%,#fff0f0)!important;
          border:1px solid var(--sc-line)!important;border-radius:18px;box-shadow:0 8px 26px rgba(45,15,15,.07)!important;
        }
        .system-check-page .page-heading .page-icon{background:linear-gradient(145deg,#ff343c,#a50e16);color:#fff;border:1px solid rgba(255,90,95,.55);box-shadow:0 0 22px rgba(255,30,40,.28)}
        .system-check-page .page-heading h1{color:var(--sc-text)!important}
        .system-check-page .page-heading .text-muted,.system-check-page .page-heading .eyebrow{color:var(--sc-muted)!important}
        .system-check-page .page-heading .btn-outline-secondary,.system-check-page .btn-outline-secondary{
          color:var(--sc-text)!important;background:rgba(255,35,45,.05)!important;border-color:var(--sc-line)!important;
        }
        .system-check-page .panel{
          overflow:hidden;border:1px solid var(--sc-line)!important;border-radius:16px;
          background:linear-gradient(145deg,var(--sc-panel2),var(--sc-panel))!important;
          color:var(--sc-text)!important;box-shadow:0 12px 36px rgba(0,0,0,.2),0 0 20px rgba(255,30,40,.07)!important;
        }
        .system-check-page .panel-header{padding:16px 18px;border-bottom:1px solid var(--sc-line);margin-bottom:14px}
        .system-check-page .panel-header .section-title{display:flex;align-items:center;gap:9px;color:var(--sc-text)!important}
        .system-check-page .panel-header .section-title i{color:#ff343c}
        .system-check-page .text-muted{color:var(--sc-muted)!important}
        .system-check-page .table-responsive{border:0;border-radius:0;background:transparent}
        .system-check-page .table{--bs-table-bg:transparent;--bs-table-color:var(--sc-text);--bs-table-border-color:var(--sc-line);color:var(--sc-text)!important;margin:0}
        .system-check-page .table>:not(caption)>*>*{background:transparent!important;color:var(--sc-text)!important;border-color:var(--sc-line)!important;padding:12px 14px}
        .system-check-page .table tbody tr:hover>*{background:rgba(255,42,50,.055)!important}
        .system-check-page .form-control,.system-check-page .form-select{
          background:var(--sc-field)!important;color:var(--sc-text)!important;border-color:var(--sc-line)!important;
        }
        .system-check-page .btn-primary{background:linear-gradient(135deg,#ff343c,#ad0c13)!important;border:1px solid rgba(255,90,95,.55)!important;color:#fff!important;box-shadow:0 6px 18px rgba(255,30,40,.18)}
        .system-check-page .sc-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-top:16px}
        .system-check-page .sc-kpi{display:flex;align-items:center;gap:12px;min-width:0;padding:14px 16px;border:1px solid var(--sc-line);border-radius:14px;background:linear-gradient(145deg,var(--sc-panel2),var(--sc-panel));box-shadow:0 8px 24px rgba(0,0,0,.12)}
        .system-check-page .sc-kpi-icon{width:42px;height:42px;flex:0 0 auto;display:grid;place-items:center;border-radius:12px;background:rgba(255,42,50,.12);color:#ff343c;font-size:19px}
        .system-check-page .sc-kpi.is-warn .sc-kpi-icon{background:rgba(255,177,46,.13);color:#f2a900}
        .system-check-page .sc-kpi.is-ok .sc-kpi-icon{background:rgba(47,190,112,.13);color:#1ca661}
        .system-check-page .sc-kpi-label{display:block;color:var(--sc-muted);font-size:.78rem}
        .system-check-page .sc-kpi-value{display:block;color:var(--sc-text);font-size:1.35rem;font-weight:850;line-height:1.2}
        .system-check-page .page-heading{position:relative;overflow:hidden;padding:22px 26px!important;margin-bottom:16px}
        .system-check-page .page-heading:after{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(480px 150px at 88% 0,rgba(255,35,45,.18),transparent 72%),linear-gradient(155deg,transparent 58%,rgba(255,35,45,.08) 58.2%,transparent 59%)}
        .system-check-page .page-heading>*{position:relative;z-index:1}
        .system-check-page .page-heading h1 b{color:#ff343c;text-shadow:0 0 18px rgba(255,35,45,.3)}
        .system-check-page .sc-overview{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:14px;margin-top:14px}
        .system-check-page .sc-board{min-width:0;padding:16px;border:1px solid var(--sc-line)!important;border-radius:16px;background:linear-gradient(145deg,var(--sc-panel2),var(--sc-panel))!important;box-shadow:0 12px 30px rgba(0,0,0,.2),0 0 20px rgba(255,30,40,.08)!important}
        .system-check-page .sc-board-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}
        .system-check-page .sc-board-title{display:flex;align-items:center;gap:10px;margin:0;color:var(--sc-text)!important;font-size:1rem;font-weight:800}
        .system-check-page .sc-board-title>i{color:#ff343c;font-size:1.2rem}
        .system-check-page .sc-state{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--sc-line);border-radius:13px;background:rgba(255,35,45,.055)}
        .system-check-page .sc-state.is-ok{background:rgba(47,190,112,.07);border-color:rgba(47,190,112,.3)}
        .system-check-page .sc-state.is-warn{background:rgba(255,177,46,.07);border-color:rgba(255,177,46,.3)}
        .system-check-page .sc-state-icon{width:46px;height:46px;flex:0 0 auto;display:grid;place-items:center;border-radius:13px;background:rgba(255,42,50,.14);color:#ff343c;font-size:1.3rem}
        .system-check-page .sc-state.is-ok .sc-state-icon{background:rgba(47,190,112,.14);color:#1ca661}
        .system-check-page .sc-state.is-warn .sc-state-icon{background:rgba(255,177,46,.14);color:#e49b00}
        .system-check-page .sc-state strong{display:block;color:var(--sc-text);font-size:1rem}
        .system-check-page .sc-state p{margin:2px 0 0;color:var(--sc-muted);font-size:.78rem}
        .system-check-page .sc-kpis{margin-top:12px}
        .system-check-page .sc-kpi{padding:10px 12px}
        .system-check-page .sc-kpi-icon{width:36px;height:36px}
        .system-check-page .sc-kpi-value{font-size:1.15rem}
        .system-check-page .sc-problem-list{margin:10px 0 0;color:var(--sc-muted);font-size:.78rem;line-height:1.5;overflow-wrap:anywhere}
        .system-check-page .sc-ack{margin-top:10px}
        .system-check-page .sc-repairs{display:grid;gap:8px}
        .system-check-page .sc-repair{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 11px;border:1px solid var(--sc-line);border-radius:11px;background:rgba(255,35,45,.035);font-size:.82rem}
        .system-check-page .sc-repair>span{min-width:0}
        .system-check-page .sc-repair .btn{flex:0 0 auto}
        .system-check-page .sc-no-repairs{padding:18px 12px;text-align:center;border:1px dashed var(--sc-line);border-radius:11px;color:var(--sc-muted);font-size:.82rem}
        .system-check-page .sc-check-group{padding:0}
        .system-check-page .sc-check-group .panel-header{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:0;padding:14px 16px}
        .system-check-page .sc-check-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:14px}
        .system-check-page .sc-check-item{min-width:0;padding:12px;border:1px solid var(--sc-line);border-radius:12px;background:linear-gradient(145deg,rgba(255,35,45,.045),rgba(0,0,0,.12))}
        .system-check-page .sc-check-item.is-ok{border-color:rgba(47,190,112,.28)}
        .system-check-page .sc-check-item.is-warn{border-color:rgba(255,177,46,.32)}
        .system-check-page .sc-check-item h3{margin:8px 0 4px;color:var(--sc-text);font-size:.86rem;font-weight:800;overflow-wrap:anywhere}
        .system-check-page .sc-check-item p{margin:0;color:var(--sc-muted);font-size:.76rem;line-height:1.45;overflow-wrap:anywhere}
        .system-check-page .sc-check-item .badge{font-size:.65rem}
        .system-check-page .sc-alert-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,.8fr);gap:14px;margin-top:14px;align-items:start}
        .system-check-page .sc-alert-grid>.panel{min-width:0;margin-top:0!important}
        .system-check-page .sc-alert-grid .table-responsive{overflow-x:auto}
        .system-check-page .sc-alert-grid .table{min-width:520px}
        .system-check-page .sc-alert-grid [aria-label="Owner alerts"] .table{min-width:580px}
        .system-check-page .sc-footer-note{display:flex;align-items:center;gap:10px;margin-top:14px;padding:11px 14px;border:1px solid var(--sc-line);border-radius:12px;background:rgba(255,35,45,.045);color:var(--sc-muted);font-size:.75rem}
        .system-check-page .sc-footer-note i{color:#ff343c;font-size:1.1rem}
        html[data-theme="light"] .system-check-page .sc-check-item{background:linear-gradient(145deg,#fff,#fff7f7)}
        html[data-theme="light"] .system-check-page .sc-repair{background:#fffafa}
        @media(max-width:1100px){.system-check-page .sc-overview,.system-check-page .sc-alert-grid{grid-template-columns:1fr}}
        @media(max-width:760px){.system-check-page .sc-check-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:520px){.system-check-page .sc-check-grid{grid-template-columns:1fr}.system-check-page .page-heading{padding:18px!important}}
        @media(max-width:640px){.system-check-page .sc-kpis{grid-template-columns:1fr}.system-check-page .table-responsive{overflow-x:auto}}
        html[data-theme="dark"] .admin-shell .system-check-page{
          --sc-text:#f8f6f7;--sc-muted:#b8b2b7;--sc-line:rgba(255,44,55,.56);
          --sc-panel:#0b0b0d;--sc-panel2:#100d10;--sc-field:#09090b;
        }
        html[data-theme="light"] .admin-shell .system-check-page{
          --sc-text:#23191c;--sc-muted:#74676b;--sc-line:rgba(196,24,36,.28);
          --sc-panel:#fff;--sc-panel2:#fffafa;--sc-field:#fff;
        }
        .system-check-page .page-heading{padding:18px 22px!important;margin-bottom:12px}
        .system-check-page .page-heading .eyebrow{display:none}
        .system-check-page .page-heading h1{font-size:1.75rem!important;font-weight:800}
        .system-check-page .page-heading .page-icon{width:64px;height:64px;font-size:1.8rem;border-radius:14px}
        .system-check-page .page-heading .text-muted{font-size:.92rem}
        .system-check-page .sc-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:12px 0 18px}
        .system-check-page .sc-metric{--metric:#ff2634;display:flex;align-items:center;gap:14px;min-width:0;min-height:84px;padding:14px 18px;border:1px solid color-mix(in srgb,var(--metric) 68%,transparent);border-radius:14px;background:linear-gradient(112deg,color-mix(in srgb,var(--metric) 13%,var(--sc-panel)),var(--sc-panel));box-shadow:inset 0 0 24px color-mix(in srgb,var(--metric) 7%,transparent),0 0 12px color-mix(in srgb,var(--metric) 13%,transparent)}
        .system-check-page .sc-metric.is-ok{--metric:#12e889}
        .system-check-page .sc-metric.is-warn{--metric:#16aaff}
        .system-check-page .sc-metric.is-health{--metric:#d52cff}
        .system-check-page .sc-metric-icon{display:grid;place-items:center;flex:0 0 44px;width:44px;height:44px;border-radius:50%;background:color-mix(in srgb,var(--metric) 20%,transparent);color:var(--metric);font-size:1.65rem;filter:drop-shadow(0 0 8px color-mix(in srgb,var(--metric) 60%,transparent))}
        .system-check-page .sc-metric:after{content:"›";margin-left:auto;color:var(--metric);font-size:1.75rem;font-weight:400}
        .system-check-page .sc-metric-value{display:block;color:var(--sc-text);font-size:1.45rem;font-weight:850;line-height:1.1}
        .system-check-page .sc-metric-label{display:block;margin-top:3px;color:var(--sc-muted);font-size:.78rem}
        .system-check-page .sc-dashboard-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:14px 16px}
        .system-check-page .sc-panel{min-width:0;overflow:hidden;border:1px solid var(--sc-line);border-radius:15px;background:linear-gradient(145deg,var(--sc-panel2),var(--sc-panel));box-shadow:0 0 16px rgba(255,30,40,.08)}
        .system-check-page .sc-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid rgba(255,50,60,.2)}
        .system-check-page .sc-panel-title{display:flex;align-items:center;gap:10px;margin:0;color:var(--sc-text);font-size:1rem;font-weight:800}
        .system-check-page .sc-panel-title>i{color:#ff343c;font-size:1.25rem}
        .system-check-page .sc-panel-body{padding:12px 14px}
        .system-check-page .sc-panel details>summary{color:var(--sc-muted);cursor:pointer}
        .system-check-page .sc-hero .heading-actions .btn{padding:10px 16px;border-radius:10px;font-size:.82rem;font-weight:750}
        .system-check-page .sc-count{padding:5px 11px;border:1px solid rgba(255,43,54,.45);border-radius:999px;background:rgba(255,28,40,.14);color:#ff646b;font-size:.73rem;font-weight:800;white-space:nowrap}
        .system-check-page .sc-issue-list,.system-check-page .sc-security-list{display:grid;gap:8px}
        .system-check-page .sc-issue,.system-check-page .sc-security-item{display:grid;grid-template-columns:38px minmax(0,1fr) auto;align-items:center;gap:10px;padding:10px 11px;border:1px solid rgba(255,255,255,.08);border-radius:11px;background:rgba(255,255,255,.018)}
        .system-check-page .sc-issue-icon{display:grid;place-items:center;color:#ff2737;font-size:1.55rem}
        .system-check-page .sc-security-item .sc-issue-icon{color:#ffc322}
        .system-check-page .sc-issue-copy{min-width:0}
        .system-check-page .sc-issue-copy strong{display:block;color:var(--sc-text);font-size:.82rem}
        .system-check-page .sc-issue-copy span{display:block;margin-top:2px;color:var(--sc-muted);font-size:.73rem;line-height:1.4;overflow-wrap:anywhere}
        .system-check-page .sc-issue .btn,.system-check-page .sc-security-item .btn{min-width:94px}
        .system-check-page .sc-info-list{display:grid}
        .system-check-page .sc-info-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:10px;padding:9px 5px;border-bottom:1px solid rgba(255,255,255,.08);font-size:.8rem}
        .system-check-page .sc-info-row:last-child{border-bottom:0}
        .system-check-page .sc-info-label{display:flex;align-items:center;gap:9px;color:var(--sc-muted)}
        .system-check-page .sc-info-label i{width:18px;color:#a6b0bd;font-size:1rem;text-align:center}
        .system-check-page .sc-info-value{display:flex;align-items:center;gap:9px;color:var(--sc-text);font-weight:700;text-align:right}
        .system-check-page .sc-info-value .bi-check-circle-fill{color:#12e889}
        .system-check-page .sc-info-value .is-warn{color:#ffbd26}
        .system-check-page .sc-info-value .is-bad{color:#ff3847}
        .system-check-page .sc-info-value .is-info{color:#25aaff}
        .system-check-page .sc-disk-meter{width:112px;height:8px;overflow:hidden;border-radius:999px;background:rgba(255,255,255,.12)}
        .system-check-page .sc-disk-meter span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#ff2737,#ff5860);box-shadow:0 0 10px rgba(255,39,55,.5)}
        .system-check-page .sc-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
        .system-check-page .sc-action{display:flex;min-height:60px;flex-direction:column;align-items:center;justify-content:center;gap:4px;padding:9px 7px;border:1px solid rgba(255,46,57,.7);border-radius:11px;background:linear-gradient(145deg,rgba(255,30,42,.22),rgba(40,8,12,.55));color:var(--sc-text);font-size:.73rem;font-weight:750;text-align:center;text-decoration:none}
        .system-check-page .sc-action i{font-size:1.2rem}
        .system-check-page .sc-action:hover{border-color:#ff4550;color:#fff;box-shadow:0 0 16px rgba(255,35,46,.2)}
        .system-check-page .sc-action.is-neutral{border-color:rgba(190,195,205,.48);background:linear-gradient(145deg,rgba(255,255,255,.11),rgba(25,25,28,.55))}
        .system-check-page .sc-history-panel{grid-column:1/-1}
        .system-check-page .sc-history-panel .sc-panel-body{padding:0}
        .system-check-page .sc-history-panel .table>:not(caption)>*>*{padding:9px 12px}
        .system-check-page .sc-history-panel .table thead th{font-size:.72rem;white-space:nowrap}
        .system-check-page .sc-history-panel .table tbody td{font-size:.75rem}
        .system-check-page .sc-history-title{display:flex;align-items:center;gap:10px}
        .system-check-page .sc-history-title small{display:block;margin-top:2px;color:var(--sc-muted);font-size:.7rem;font-weight:400}
        .system-check-page .sc-link{color:#ff343c;font-size:.72rem;font-weight:800;text-decoration:none;white-space:nowrap}
        .system-check-page .sc-details{margin-top:14px;border:1px solid var(--sc-line);border-radius:14px;background:var(--sc-panel)}
        .system-check-page .sc-details>summary{padding:13px 16px;color:var(--sc-text);font-size:.86rem;font-weight:800;cursor:pointer}
        .system-check-page .sc-details[open]>summary{border-bottom:1px solid var(--sc-line)}
        .system-check-page .sc-details-content{padding:14px}
        .system-check-page .sc-details-content .panel{margin:0 0 12px!important}
        .system-check-page .sc-details-content .panel:last-child{margin-bottom:0!important}
        .system-check-page .sc-check-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
        .system-check-page .sc-check-item{background:rgba(255,255,255,.025)}
        html[data-theme="light"] .system-check-page .sc-panel{box-shadow:0 8px 24px rgba(65,20,25,.07)}
        html[data-theme="light"] .system-check-page .sc-issue,html[data-theme="light"] .system-check-page .sc-security-item{border-color:rgba(60,30,35,.1);background:rgba(60,30,35,.025)}
        html[data-theme="light"] .system-check-page .sc-info-row{border-color:rgba(60,30,35,.1)}
        html[data-theme="light"] .system-check-page .sc-action{background:linear-gradient(145deg,#fff1f2,#fff);color:#25191c}
        html[data-theme="light"] .system-check-page .sc-action.is-neutral{background:linear-gradient(145deg,#f6f6f7,#fff)}
        html[data-theme="light"] .system-check-page .sc-disk-meter{background:rgba(30,30,35,.12)}
        @supports not (color: color-mix(in srgb, red, transparent)){.system-check-page .sc-metric{background:var(--sc-panel2)}}
        @media(max-width:900px){.system-check-page .sc-dashboard-grid{grid-template-columns:1fr}.system-check-page .sc-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.system-check-page .sc-history-panel{grid-column:auto}}
        @media(max-width:600px){.system-check-page .sc-metrics{gap:8px}.system-check-page .sc-metric{min-height:72px;padding:10px}.system-check-page .sc-metric-icon{width:36px;height:36px;flex-basis:36px;font-size:1.3rem}.system-check-page .sc-metric-value{font-size:1.2rem}.system-check-page .sc-issue,.system-check-page .sc-security-item{grid-template-columns:30px minmax(0,1fr);gap:8px}.system-check-page .sc-issue .btn,.system-check-page .sc-security-item .btn{grid-column:2;justify-self:start}.system-check-page .sc-actions{gap:6px}.system-check-page .sc-action{font-size:.66rem}.system-check-page .sc-info-row{font-size:.73rem}.system-check-page .sc-info-value{flex-wrap:wrap;justify-content:flex-end;gap:6px}.system-check-page .sc-disk-meter{width:70px}}
      </style>

      <main class="dashboard-content system-check-page">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading sc-hero">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
              <div>
                <h1 class="h3 mb-1">System <b>Check</b></h1>
                <p class="text-muted mb-0">Scan your platform for issues and get detailed reports.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="system-check.php"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Run New Check</a>
            </div>
          </div>

          <?php if (is_array($flash)): ?>
            <div class="alert alert-<?php echo vs_e($flash[0]); ?> mt-3 mb-0" role="status"><?php echo vs_e($flash[1]); ?></div>
          <?php endif; ?>

          <section class="sc-metrics" aria-label="System check summary">
            <div class="sc-metric"><span class="sc-metric-icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span><div><strong class="sc-metric-value"><?php echo (int)$tally['bad']; ?></strong><span class="sc-metric-label">Issues Found</span></div></div>
            <div class="sc-metric is-ok"><span class="sc-metric-icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></span><div><strong class="sc-metric-value"><?php echo (int)$tally['ok']; ?></strong><span class="sc-metric-label">All Good</span></div></div>
            <div class="sc-metric is-warn"><span class="sc-metric-icon"><i class="bi bi-info-circle-fill" aria-hidden="true"></i></span><div><strong class="sc-metric-value"><?php echo (int)$tally['warn']; ?></strong><span class="sc-metric-label">Warnings</span></div></div>
            <div class="sc-metric is-health"><span class="sc-metric-icon"><i class="bi bi-shield-fill-check" aria-hidden="true"></i></span><div><strong class="sc-metric-value"><?php echo $health; ?>%</strong><span class="sc-metric-label">Overall Health</span></div></div>
          </section>

          <div class="sc-dashboard-grid">
            <section class="sc-panel" id="issues-section" aria-label="Issues detected">
              <div class="sc-panel-head">
                <h2 class="sc-panel-title"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Issues Detected</h2>
                <span class="sc-count"><?php echo (int)$tally['bad']; ?> Issue<?php echo $tally['bad'] === 1 ? '' : 's'; ?></span>
              </div>
              <div class="sc-panel-body">
                <?php if (!$badChecks): ?>
                  <div class="sc-issue-copy"><strong>Everything is running smoothly.</strong><span>No failing system checks were found.</span></div>
                <?php else: ?>
                  <div class="sc-issue-list">
                    <?php foreach ($badChecks as $check): ?>
                      <?php $repairId = $repairForCheck($check['group'], $check['label'], $check['detail']); ?>
                      <article class="sc-issue">
                        <span class="sc-issue-icon"><i class="bi <?php echo $check['group'] === 'Database' ? 'bi-database-fill' : ($check['group'] === 'Files in the main VibeStream folder' || $check['group'] === 'Folders the site writes to' ? 'bi-folder-fill' : 'bi-shield-exclamation'); ?>" aria-hidden="true"></i></span>
                        <div class="sc-issue-copy">
                          <strong><?php echo vs_e($check['label']); ?></strong>
                          <span><?php echo vs_e($check['detail'] !== '' ? $check['detail'] : $check['group']); ?></span>
                        </div>
                        <?php if ($repairId === 'owner_lock'): ?>
                          <a class="btn btn-primary btn-sm" href="install-owner-lock.php"><i class="bi bi-wrench-adjustable me-1" aria-hidden="true"></i>Fix Now</a>
                        <?php elseif ($repairId !== null): ?>
                          <form method="post" class="mb-0">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="fix">
                            <input type="hidden" name="fix" value="<?php echo vs_e($repairId); ?>">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-wrench-adjustable me-1" aria-hidden="true"></i>Fix Now</button>
                          </form>
                        <?php else: ?>
                          <a class="btn btn-outline-secondary btn-sm" href="#all-checks-details">Details</a>
                        <?php endif; ?>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
                <?php if ($tally['bad'] > 0 && empty($state['acknowledged'])): ?>
                  <form method="post" class="mt-3 mb-0">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <button type="submit" name="action" value="ack" class="btn btn-outline-danger btn-sm">Got it, stop reminding me</button>
                  </form>
                <?php elseif ($tally['bad'] > 0): ?>
                  <p class="small text-muted mt-3 mb-0">You already acknowledged these problems.</p>
                <?php endif; ?>
              </div>
            </section>

            <section class="sc-panel" aria-label="System information">
              <div class="sc-panel-head"><h2 class="sc-panel-title"><i class="bi bi-gear-fill" aria-hidden="true"></i> System Information</h2></div>
              <div class="sc-panel-body sc-info-list">
                <div class="sc-info-row"><span class="sc-info-label"><i class="bi bi-filetype-php" aria-hidden="true"></i>PHP Version</span><span class="sc-info-value"><?php echo vs_e(PHP_VERSION); ?><i class="bi <?php echo $statusIcon($phpStatus); ?> is-<?php echo vs_e($phpStatus); ?>" aria-hidden="true"></i></span></div>
                <div class="sc-info-row"><span class="sc-info-label"><i class="bi bi-database-fill" aria-hidden="true"></i>MySQL Version</span><span class="sc-info-value"><?php echo vs_e($dbVersion); ?><i class="bi <?php echo $statusIcon($databaseStatus); ?> is-<?php echo vs_e($databaseStatus); ?>" aria-hidden="true"></i></span></div>
                <div class="sc-info-row"><span class="sc-info-label"><i class="bi bi-hdd-network-fill" aria-hidden="true"></i>Server OS</span><span class="sc-info-value"><?php echo vs_e(defined('PHP_OS_FAMILY') ? PHP_OS_FAMILY : PHP_OS); ?><i class="bi bi-info-circle-fill is-info" aria-hidden="true"></i></span></div>
                <div class="sc-info-row"><span class="sc-info-label"><i class="bi bi-hdd-fill" aria-hidden="true"></i>Disk Space</span><span class="sc-info-value"><span class="sc-disk-meter" aria-hidden="true"><span style="width:<?php echo $diskTotal && $diskFree !== false ? (int)max(0, min(100, round(($diskUsed / $diskTotal) * 100))) : 0; ?>%"></span></span><?php echo vs_e($diskLabel); ?><i class="bi <?php echo $statusIcon($diskStatus); ?> is-<?php echo vs_e($diskStatus); ?>" aria-hidden="true"></i></span></div>
                <div class="sc-info-row"><span class="sc-info-label"><i class="bi bi-memory" aria-hidden="true"></i>Memory Limit</span><span class="sc-info-value"><?php echo vs_e((string)ini_get('memory_limit')); ?><i class="bi <?php echo $statusIcon($memoryStatus); ?> is-<?php echo vs_e($memoryStatus); ?>" aria-hidden="true"></i></span></div>
              </div>
            </section>

            <section class="sc-panel" aria-label="Security warnings">
              <div class="sc-panel-head">
                <h2 class="sc-panel-title"><i class="bi bi-shield-fill-exclamation" aria-hidden="true"></i> Security Warnings</h2>
                <span class="sc-count"><?php echo count($securityWarnings); ?> Warning<?php echo count($securityWarnings) === 1 ? '' : 's'; ?></span>
              </div>
              <div class="sc-panel-body">
                <?php if (!$securityWarnings): ?>
                  <div class="sc-issue-copy"><strong>No security warnings.</strong><span>Your security checks are passing.</span></div>
                <?php else: ?>
                  <div class="sc-security-list">
                    <?php foreach ($securityWarnings as $check): ?>
                      <?php $repairId = $repairForCheck($check['group'], $check['label'], $check['detail']); ?>
                      <article class="sc-security-item">
                        <span class="sc-issue-icon"><i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i></span>
                        <div class="sc-issue-copy"><strong><?php echo vs_e($check['label']); ?></strong><span><?php echo vs_e($check['detail'] !== '' ? $check['detail'] : 'Review this security check in the details below.'); ?></span></div>
                        <?php if ($repairId !== null && $repairId !== 'owner_lock'): ?>
                          <form method="post" class="mb-0">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="fix">
                            <input type="hidden" name="fix" value="<?php echo vs_e($repairId); ?>">
                            <button type="submit" class="btn btn-outline-warning btn-sm">Fix Now</button>
                          </form>
                        <?php else: ?>
                          <a class="btn btn-outline-warning btn-sm" href="#all-checks-details">Learn More <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        <?php endif; ?>
                      </article>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </div>
            </section>

            <section class="sc-panel" aria-label="Quick actions">
              <div class="sc-panel-head"><h2 class="sc-panel-title"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i> Quick Actions</h2></div>
              <div class="sc-panel-body">
                <div class="sc-actions">
                  <a class="sc-action" href="#issues-section"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i>Fix Issues</a>
                  <form method="post" class="mb-0">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <button type="submit" name="action" value="test_mail" class="sc-action is-neutral w-100"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Send Test Email</button>
                  </form>
                  <form method="post" class="mb-0">
                    <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                    <button type="submit" name="action" value="download_report" class="sc-action w-100"><i class="bi bi-download" aria-hidden="true"></i>Download Report</button>
                  </form>
                </div>
                <details class="mt-3">
                  <summary class="small fw-bold">Available one-click repairs (<?php echo count($result['fixes']); ?>)</summary>
                  <div class="sc-repairs mt-2">
                    <?php if (!empty($result['fixes'])): ?>
                      <?php foreach ($result['fixes'] as $fixId => $fixLabel): ?>
                        <?php if ($fixId === 'owner_lock'): ?>
                          <div class="sc-repair"><span><?php echo vs_e($fixLabel); ?></span><a class="btn btn-primary btn-sm" href="install-owner-lock.php">Open it</a></div>
                        <?php else: ?>
                          <form method="post" class="sc-repair">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="fix">
                            <input type="hidden" name="fix" value="<?php echo vs_e($fixId); ?>">
                            <span><?php echo vs_e($fixLabel); ?></span>
                            <button type="submit" class="btn btn-primary btn-sm"<?php echo $fixId === 'make_backup' ? ' onclick="this.textContent=\'Working, please wait...\';"' : ''; ?>>Fix it</button>
                          </form>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="sc-no-repairs">No quick repairs are needed.</div>
                    <?php endif; ?>
                  </div>
                </details>
              </div>
            </section>

            <section class="sc-panel sc-history-panel" aria-label="Check history">
              <div class="sc-panel-head">
                <h2 class="sc-panel-title sc-history-title"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Check History<small>Recent system issues and fixes.</small></span></h2>
                <a class="sc-link" href="#all-alert-history">View All <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
              </div>
              <div class="sc-panel-body">
                <?php if (!$history): ?>
                  <p class="text-center text-muted py-3 mb-0">No checks have been recorded yet.</p>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead><tr><th scope="col">Date &amp; Time</th><th scope="col">Status</th><th scope="col">Issues</th><th scope="col">Details</th></tr></thead>
                      <tbody>
                        <?php foreach (array_slice($history, 0, 5) as $h): ?>
                          <?php $historyText = (string)($h['text'] ?? ''); $historyIssues = ($h['type'] ?? '') === 'ok' ? 0 : (preg_match('/^(\d+)/', $historyText, $historyMatch) ? (int)$historyMatch[1] : '—'); ?>
                          <tr>
                            <td class="text-nowrap"><?php echo vs_e(date('M j, Y - g:i A', (int)($h['time'] ?? 0))); ?></td>
                            <td><span class="badge <?php echo ($h['type'] ?? '') === 'ok' ? 'text-bg-success' : 'text-bg-danger'; ?>"><?php echo ($h['type'] ?? '') === 'ok' ? 'Healthy' : 'Issues Found'; ?></span></td>
                            <td><?php echo vs_e((string)$historyIssues); ?></td>
                            <td><?php echo vs_e($historyText); ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </section>
          </div>

          <details class="sc-details" id="all-checks-details">
            <summary>View All System Check Details (<?php echo count($checkRows); ?> checks)</summary>
            <div class="sc-details-content">
          <?php foreach ($groups as $title => $rows): ?>
            <section class="panel sc-check-group mt-3" aria-label="<?php echo vs_e($title); ?>">
              <div class="panel-header">
                <h2 class="h5 mb-0 section-title"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i><span><?php echo vs_e($title); ?></span></h2>
                <span class="badge text-bg-secondary"><?php echo count($rows); ?> checks</span>
              </div>
              <div class="sc-check-grid">
                <?php foreach ($rows as [$label, $status, $detail]): ?>
                  <article class="sc-check-item is-<?php echo vs_e($status); ?>">
                    <span class="badge <?php echo vs_e($badge[$status][0]); ?>"><i class="bi <?php echo $status === 'ok' ? 'bi-check-circle-fill' : ($status === 'warn' ? 'bi-exclamation-triangle-fill' : 'bi-x-octagon-fill'); ?> me-1" aria-hidden="true"></i><?php echo vs_e($badge[$status][1]); ?></span>
                    <h3><?php echo vs_e($label); ?></h3>
                    <p><?php echo vs_e($detail); ?></p>
                  </article>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endforeach; ?>
            </div>
          </details>

          <details class="sc-details" id="all-alert-history">
            <summary>Email Alert Settings, Full Alert History &amp; Owner Alerts</summary>
            <div class="sc-details-content">
          <div class="sc-alert-grid">
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
            </div>
          </details>
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