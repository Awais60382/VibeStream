<?php
/**
 * VibeStream: Payment Ledger + Who Wants a Raise?
 * Install beside admin-pay.php. PHP 7.4+, PDO MySQL, mbstring.
 * Uses existing authentication helpers; do NOT include admin-pay.php here.
 * Owner: records payments and reviews raise requests. Admin: own requests only.
 * Ledger entries are new transaction records, NOT imported monthly payroll flags.
 * Approving a raise explicitly updates admin_pay_settings.rate immediately.
 * Existing payroll recalculates historical recommendations using that current rate.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    header('Location: ../login.php'); exit;
}
require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/owner-notice-store.php';
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(503); exit('Database unavailable.'); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
function vpl_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function vpl_key(string $v): string { return mb_strtolower(trim($v), 'UTF-8'); }
function vpl_money($v): string { return 'PKR ' . number_format((float)$v, 2); }
function vpl_valid_date(string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
function vpl_text($v, int $max): string {
    if (!is_string($v) || mb_strlen(trim($v), 'UTF-8') > $max) { throw new InvalidArgumentException('Text is missing or too long.'); }
    return trim($v);
}
function vpl_amount($v): string {
    if (!is_string($v) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', $v)) { throw new InvalidArgumentException('Enter a positive amount with at most two decimal places.'); }
    $parts = explode('.', $v);
    $cents = (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
    if ($cents <= 0 || $cents > 100000000) { throw new InvalidArgumentException('Amount must be between PKR 0.01 and PKR 1,000,000.'); }
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}
function vpl_cents($v): int { return (int)round((float)$v * 100); }
function vpl_month($v): string {
    if (!is_string($v) || !preg_match('/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/D', $v)) { throw new InvalidArgumentException('Choose a valid payroll month.'); }
    return $v;
}
function vpl_token($v): string {
    if (!is_string($v) || !preg_match('/^[a-f0-9]{32}$/D', $v)) { throw new InvalidArgumentException('Invalid form token. Reload and try again.'); }
    return $v;
}
function vpl_audit(PDO $pdo, string $actor, string $event, string $subject, string $detail): void {
    $pdo->prepare('INSERT INTO admin_payment_ledger_audit (actor,event,subject,detail,created_at) VALUES (?,?,?,?,NOW())')->execute([$actor, $event, $subject, $detail]);
}
$me = vs_notice_me($pdo);
$isOwner = function_exists('vs_is_owner') && vs_is_owner($pdo);
$verifiedId = is_array($me) ? filter_var($me['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$verifiedUsername = is_array($me) && is_string($me['username'] ?? null) ? trim($me['username']) : '';
// Owners are independently verified by vs_is_owner(); keep the account ID for admin lookup and audit attribution.
// Non-owners still require their verified username so requests cannot be submitted for another account.
if (!$verifiedId || (!$isOwner && $verifiedUsername === '')) {
    http_response_code(403);
    exit('Unable to verify the current admin identity. Sign in again. If this continues, check owner-notice-store.php and owner-guard.php.');
}
$actor = $verifiedUsername !== '' ? vpl_key($verifiedUsername) : 'owner#' . $verifiedId;
$admins = [];
if ($isOwner) {
    foreach (vs_notice_admins($pdo, (int)$me['id']) as $a) {
        $k = vpl_key((string)$a['username']);
        if ($k !== '') { $admins[$k] = (string)$a['username']; }
    }
} else { $admins[$actor] = (string)$me['username']; }
try {
    // Safe additive setup. No existing table or record is deleted or imported.
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_pay_settings (
        username VARCHAR(100) NOT NULL PRIMARY KEY, rate DECIMAL(10,2) NOT NULL DEFAULT 0,
        title VARCHAR(60) NOT NULL DEFAULT ''
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_payment_ledger (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL, payroll_month CHAR(7) NOT NULL,
        amount DECIMAL(12,2) NOT NULL, payment_date DATE NOT NULL,
        method VARCHAR(30) NOT NULL, reference VARCHAR(100) NOT NULL DEFAULT '',
        note VARCHAR(500) NOT NULL DEFAULT '', recorded_by VARCHAR(100) NOT NULL,
        request_token CHAR(32) NOT NULL UNIQUE, created_at DATETIME NOT NULL,
        KEY idx_user_date (username,payment_date,id), KEY idx_user_month (username,payroll_month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_raise_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(100) NOT NULL, current_rate DECIMAL(10,2) NOT NULL,
        requested_rate DECIMAL(10,2) NOT NULL, reason TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pending',
        pending_key VARCHAR(100) NULL UNIQUE, request_token CHAR(32) NOT NULL UNIQUE,
        submitted_by VARCHAR(100) NOT NULL, submitted_at DATETIME NOT NULL,
        reviewed_by VARCHAR(100) NULL, reviewed_at DATETIME NULL,
        review_note VARCHAR(500) NOT NULL DEFAULT '', applied_from_rate DECIMAL(10,2) NULL,
        KEY idx_user_status (username,status,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_payment_ledger_audit (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        actor VARCHAR(100) NOT NULL, event VARCHAR(40) NOT NULL,
        subject VARCHAR(100) NOT NULL, detail TEXT NOT NULL, created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    error_log('payment-ledger setup: ' . $e->getMessage());
    http_response_code(503); exit('Ledger setup failed. Ask the site owner to check the database permissions and server error log.');
}
if (empty($_SESSION['vpl_csrf'])) { $_SESSION['vpl_csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['vpl_csrf'];
$selected = $isOwner ? vpl_key(is_string($_GET['admin'] ?? null) ? $_GET['admin'] : '') : $actor;
if ($selected !== '' && !isset($admins[$selected])) { $selected = ''; }
$filterError = '';
$paymentFilters = [
    'payment_from' => is_string($_GET['payment_from'] ?? null) ? trim($_GET['payment_from']) : '',
    'payment_to' => is_string($_GET['payment_to'] ?? null) ? trim($_GET['payment_to']) : '',
    'payroll_month_filter' => is_string($_GET['payroll_month_filter'] ?? null) ? trim($_GET['payroll_month_filter']) : '',
    'method' => is_string($_GET['method'] ?? null) ? trim($_GET['method']) : '',
    'search' => is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '',
];
$validMethods = ['Bank transfer', 'JazzCash', 'Easypaisa', 'Cash', 'Other'];
if (!is_string($_GET['payment_from'] ?? '') || ($paymentFilters['payment_from'] !== '' && !vpl_valid_date($paymentFilters['payment_from']))) {
    $filterError = 'Choose a valid start date for the payment filter.';
    $paymentFilters['payment_from'] = '';
}
if (!is_string($_GET['payment_to'] ?? '') || ($paymentFilters['payment_to'] !== '' && !vpl_valid_date($paymentFilters['payment_to']))) {
    $filterError = 'Choose a valid end date for the payment filter.';
    $paymentFilters['payment_to'] = '';
}
if ($paymentFilters['payment_from'] !== '' && $paymentFilters['payment_to'] !== '' && $paymentFilters['payment_from'] > $paymentFilters['payment_to']) {
    $filterError = 'The payment start date must be on or before the end date.';
    $paymentFilters['payment_from'] = '';
    $paymentFilters['payment_to'] = '';
}
if ($paymentFilters['payroll_month_filter'] !== '' && !preg_match('/^(?:19|20|21)\d{2}-(?:0[1-9]|1[0-2])$/D', $paymentFilters['payroll_month_filter'])) {
    $filterError = 'Choose a valid payroll month filter.';
    $paymentFilters['payroll_month_filter'] = '';
}
if ($paymentFilters['method'] !== '' && !in_array($paymentFilters['method'], $validMethods, true)) {
    $filterError = 'Choose a valid payment method filter.';
    $paymentFilters['method'] = '';
}
if (!is_string($_GET['search'] ?? '') || mb_strlen($paymentFilters['search'], 'UTF-8') > 100) {
    $filterError = 'Payment search must be 100 characters or fewer.';
    $paymentFilters['search'] = '';
}
$methods = ['Bank transfer', 'JazzCash', 'Easypaisa', 'Cash', 'Other'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = $selected;
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) { throw new InvalidArgumentException('This form expired. Reload the page and try again.'); }
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!in_array($action, ['payment', 'request_raise', 'review_raise'], true)) { throw new InvalidArgumentException('Unknown action.'); }
        if ($action === 'payment' && !$isOwner) { throw new InvalidArgumentException('Only the owner can record payments.'); }
        if ($action === 'review_raise' && !$isOwner) { throw new InvalidArgumentException('Only the owner can review requests.'); }
        if ($action !== 'review_raise') {
            // Self-service identities always come from the verified session, never form input.
            $target = $isOwner ? vpl_key(vpl_text($_POST['admin'] ?? '', 100)) : $actor;
            if (!isset($admins[$target])) { throw new InvalidArgumentException('Select an available admin.'); }
            $token = vpl_token($_POST['request_token'] ?? null);
        }
        if ($action === 'payment') {
            $amount = vpl_amount($_POST['amount'] ?? null);
            $month = vpl_month($_POST['payroll_month'] ?? null);
            if ($month > date('Y-m')) { throw new InvalidArgumentException('Future payroll months are not supported.'); }
            $day = vpl_text($_POST['payment_date'] ?? '', 10);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
            if (!$parsed || $parsed->format('Y-m-d') !== $day || $day > date('Y-m-d') || $day < '1900-01-01') { throw new InvalidArgumentException('Choose a valid payment date that is not in the future.'); }
            $method = vpl_text($_POST['method'] ?? '', 30);
            if (!in_array($method, $methods, true)) { throw new InvalidArgumentException('Choose a payment method.'); }
            $reference = vpl_text($_POST['reference'] ?? '', 100);
            $note = vpl_text($_POST['note'] ?? '', 500);
            if (($_POST['confirm_payment'] ?? '') !== 'yes') { throw new InvalidArgumentException('Confirm that this money has already been paid.'); }
            $pdo->beginTransaction();
            $q = $pdo->prepare('INSERT INTO admin_payment_ledger (username,payroll_month,amount,payment_date,method,reference,note,recorded_by,request_token,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
            $q->execute([$target,$month,$amount,$day,$method,$reference,$note,$actor,$token]);
            $entryId = $pdo->lastInsertId();
            vpl_audit($pdo,$actor,'payment_recorded',$target,json_encode(['id'=>$entryId,'amount'=>$amount,'month'=>$month], JSON_THROW_ON_ERROR));
            $pdo->commit();
            $msg = 'Payment recorded. Monthly payroll status has not been changed.';
        } elseif ($action === 'request_raise') {
            $requested = vpl_amount($_POST['requested_rate'] ?? null);
            $reason = vpl_text($_POST['reason'] ?? '', 2000);
            if (mb_strlen($reason, 'UTF-8') < 10) { throw new InvalidArgumentException('Explain the raise request in at least 10 characters.'); }
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT rate FROM admin_pay_settings WHERE username = ? FOR UPDATE');
            $q->execute([$target]); $rate = $q->fetchColumn();
            $rate = $rate === false ? '0.00' : (string)$rate;
            if (vpl_cents($requested) <= vpl_cents($rate)) { throw new InvalidArgumentException('The requested hourly rate must be higher than the current rate.'); }
            $q = $pdo->prepare('INSERT INTO admin_raise_requests (username,current_rate,requested_rate,reason,pending_key,request_token,submitted_by,submitted_at) VALUES (?,?,?,?,?,?,?,NOW())');
            $q->execute([$target,$rate,$requested,$reason,$target,$token,$actor]);
            vpl_audit($pdo,$actor,'raise_requested',$target,json_encode(['id'=>$pdo->lastInsertId(),'requested_rate'=>$requested], JSON_THROW_ON_ERROR));
            $pdo->commit();
            $msg = 'Raise request submitted for owner review. The pay rate has not changed.';
        } else {
            $id = $_POST['request_id'] ?? '';
            if (!is_string($id) || !preg_match('/^[1-9]\d{0,17}$/D', $id)) { throw new InvalidArgumentException('Invalid request.'); }
            $decision = $_POST['decision'] ?? '';
            if (!in_array($decision, ['approved','rejected'], true)) { throw new InvalidArgumentException('Choose approve or reject.'); }
            $note = vpl_text($_POST['review_note'] ?? '', 500);
            if ($note === '') { throw new InvalidArgumentException('Add a review note for the admin.'); }
            if (($_POST['confirm_review'] ?? '') !== 'yes') { throw new InvalidArgumentException('Confirm this decision before saving it.'); }
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT * FROM admin_raise_requests WHERE id = ? FOR UPDATE'); $q->execute([$id]);
            $request = $q->fetch(PDO::FETCH_ASSOC);
            if (!$request || $request['status'] !== 'pending') { throw new InvalidArgumentException('This request has already been reviewed or does not exist.'); }
            $target = (string)$request['username'];
            if (!isset($admins[$target])) { throw new InvalidArgumentException('This admin is no longer available; no rate was changed.'); }
            $fromRate = null;
            if ($decision === 'approved') {
                // Insert only when missing; never overwrite title, shifts or work days.
                $pdo->prepare("INSERT INTO admin_pay_settings (username,rate,title) VALUES (?,0,'') ON DUPLICATE KEY UPDATE username = VALUES(username)")->execute([$target]);
                $q = $pdo->prepare('SELECT rate FROM admin_pay_settings WHERE username = ? FOR UPDATE'); $q->execute([$target]);
                $fromRate = (string)$q->fetchColumn();
                if (vpl_cents($fromRate) !== vpl_cents($request['current_rate'])) { throw new InvalidArgumentException('The current rate changed after this request was submitted. Reject with a note and ask for a new request based on the current rate.'); }
                if (vpl_cents($request['requested_rate']) <= vpl_cents($fromRate)) { throw new InvalidArgumentException('Requested rate must exceed the current rate.'); }
                $pdo->prepare('UPDATE admin_pay_settings SET rate = ? WHERE username = ?')->execute([$request['requested_rate'],$target]);
            }
            $pdo->prepare('UPDATE admin_raise_requests SET status = ?, pending_key = NULL, reviewed_by = ?, reviewed_at = NOW(), review_note = ?, applied_from_rate = ? WHERE id = ?')->execute([$decision,$actor,$note,$fromRate,$id]);
            vpl_audit($pdo,$actor,'raise_'.$decision,$target,json_encode(['id'=>$id,'previous_rate'=>$fromRate,'requested_rate'=>$request['requested_rate'],'note'=>$note], JSON_THROW_ON_ERROR));
            $pdo->commit();
            $msg = $decision === 'approved' ? 'Raise approved and hourly rate updated immediately. Saved paid amounts are unchanged.' : 'Raise rejected. The hourly rate is unchanged.';
        }
        $_SESSION['vpl_flash'] = ['ok'=>true,'message'=>$msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $message = 'Could not save. Check the server error log and try again.';
        if ($e instanceof InvalidArgumentException) { $message = $e->getMessage(); }
        elseif ($e instanceof PDOException && (string)$e->getCode() === '23000') { $message = 'Duplicate submission, or this admin already has a pending raise request. Reload to view the saved record.'; }
        error_log('payment-ledger action: '.$e->getMessage());
        $_SESSION['vpl_flash'] = ['ok'=>false,'message'=>$message];
    }
    $redirectAdmin = $isOwner && isset($admins[$target]) ? $target : ($isOwner ? '' : $actor);
    header('Location: admin-payment-ledger.php' . ($redirectAdmin !== '' ? '?admin='.rawurlencode($redirectAdmin) : ''), true, 303); exit;
}
$flash = $_SESSION['vpl_flash'] ?? null; unset($_SESSION['vpl_flash']);
$rates = []; $payments = []; $requests = []; $audit = []; $total = '0.00'; $paymentCount = 0; $page = 1; $pages = 1; $pendingCount = 0;
$allTimeLedgerTotal = '0.00'; $allTimeLedgerCount = 0;
$paymentToken = bin2hex(random_bytes(16)); $raiseToken = bin2hex(random_bytes(16));
try {
    if ($isOwner) {
        foreach ($pdo->query('SELECT username,rate FROM admin_pay_settings')->fetchAll(PDO::FETCH_ASSOC) as $r) { $rates[vpl_key((string)$r['username'])] = (string)$r['rate']; }
    } else {
        $q = $pdo->prepare('SELECT rate FROM admin_pay_settings WHERE username = ?'); $q->execute([$actor]); $r = $q->fetchColumn();
        $rates[$actor] = $r === false ? '0.00' : (string)$r;
    }
    if ($isOwner) {
        $summary = $pdo->query('SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS total FROM admin_payment_ledger')->fetch(PDO::FETCH_ASSOC);
        $allTimeLedgerTotal = (string)$summary['total'];
        $allTimeLedgerCount = (int)$summary['n'];
        $conditions = [];
        $paymentArgs = [];
        if ($selected !== '') { $conditions[] = 'LOWER(TRIM(p.username)) = ?'; $paymentArgs[] = $selected; }
        if ($paymentFilters['payment_from'] !== '') { $conditions[] = 'p.payment_date >= ?'; $paymentArgs[] = $paymentFilters['payment_from']; }
        if ($paymentFilters['payment_to'] !== '') { $conditions[] = 'p.payment_date <= ?'; $paymentArgs[] = $paymentFilters['payment_to']; }
        if ($paymentFilters['payroll_month_filter'] !== '') { $conditions[] = 'p.payroll_month = ?'; $paymentArgs[] = $paymentFilters['payroll_month_filter']; }
        if ($paymentFilters['method'] !== '') { $conditions[] = 'p.method = ?'; $paymentArgs[] = $paymentFilters['method']; }
        if ($paymentFilters['search'] !== '') {
            $conditions[] = '(p.username LIKE ? OR p.reference LIKE ? OR p.note LIKE ? OR p.recorded_by LIKE ?)';
            $searchTerm = '%' . $paymentFilters['search'] . '%';
            array_push($paymentArgs, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        }
        $paymentWhere = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $q = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS total FROM admin_payment_ledger p' . $paymentWhere);
        $q->execute($paymentArgs);
        $summary = $q->fetch(PDO::FETCH_ASSOC); $total = (string)$summary['total']; $paymentCount = (int)$summary['n'];
        $pages = max(1, (int)ceil($paymentCount / 25));
        $page = min($pages, max(1, (int)(is_scalar($_GET['page'] ?? null) ? $_GET['page'] : 1)));
        $offset = ($page-1)*25;
        $q = $pdo->prepare('SELECT p.* FROM admin_payment_ledger p' . $paymentWhere . ' ORDER BY p.payment_date DESC,p.id DESC LIMIT 25 OFFSET '.$offset);
        $q->execute($paymentArgs);
        $payments = $q->fetchAll(PDO::FETCH_ASSOC);
    }
    $rqPage = max(1, (int)(is_scalar($_GET['raise_page'] ?? null) ? $_GET['raise_page'] : 1));
    // Owner sees every request, independent of payment selector; others see ONLY their own.
    $where = $isOwner ? '' : ' WHERE username = ?'; $args = $isOwner ? [] : [$actor];
    $q = $pdo->prepare('SELECT COUNT(*) FROM admin_raise_requests'.$where); $q->execute($args);
    $rqPages = max(1, (int)ceil((int)$q->fetchColumn()/20)); $rqPage = min($rqPage,$rqPages);
    $q = $pdo->prepare('SELECT COUNT(*) FROM admin_raise_requests WHERE status = ?'.($isOwner ? '' : ' AND username = ?')); $q->execute($isOwner ? ['pending'] : ['pending',$actor]); $pendingCount = (int)$q->fetchColumn();
    $q = $pdo->prepare("SELECT * FROM admin_raise_requests".$where." ORDER BY (status = 'pending') DESC,id DESC LIMIT 20 OFFSET ".(($rqPage-1)*20)); $q->execute($args); $requests = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($isOwner) { $audit = $pdo->query('SELECT * FROM admin_payment_ledger_audit ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC); }
} catch (Throwable $e) { error_log('payment-ledger read: '.$e->getMessage()); http_response_code(503); exit('Could not load ledger data. Please check the server error log.'); }
function vpl_url(string $admin, int $page, int $raisePage, array $filters = []): string { return 'admin-payment-ledger.php?'.http_build_query(array_merge(['admin'=>$admin,'page'=>$page,'raise_page'=>$raisePage], $filters)); }
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
include __DIR__ . '/header.php';
?>
<style>
.vpl{--lp-bg:#121025;--lp-card:#1c1835;--lp-line:#3c315c;--lp-text:#f5f1ff;--lp-muted:#beb5d4;max-width:1450px;margin:auto;color:var(--lp-text);padding:24px;font-family:system-ui,sans-serif}.vpl *{box-sizing:border-box}.vpl h1,.vpl h2,.vpl h3{color:var(--lp-text);margin:0 0 12px}.vpl h1{font-size:1.8rem}.vpl h2{font-size:1.2rem}.vpl p{line-height:1.6}.vpl .lp-muted{color:var(--lp-muted);font-size:.9rem}.lp-card{background:var(--lp-card);border:1px solid var(--lp-line);border-radius:16px;padding:22px;margin-bottom:18px}.lp-head{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap}.lp-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.lp-fields{display:grid;grid-template-columns:1fr 1fr;gap:14px}.vpl label{display:block;font-size:.9rem;margin-bottom:6px}.vpl input:not([type=checkbox]),.vpl select,.vpl textarea{width:100%;background:var(--lp-bg);border:1px solid var(--lp-line);border-radius:9px;color:var(--lp-text);padding:10px;font:inherit;color-scheme:dark}.vpl textarea{min-height:90px;resize:vertical}.vpl button,.vpl .lp-btn{display:inline-block;background:#7843de;color:#fff;border:1px solid #9870ec;padding:10px 16px;border-radius:9px;cursor:pointer;font:inherit;text-decoration:none}.vpl .lp-secondary{background:transparent;color:var(--lp-text);border-color:var(--lp-line)}.vpl .lp-danger{background:#8b274b;border-color:#bd3c6a}.vpl a{color:#c7afff}.vpl a.lp-btn{color:#fff}.lp-wide{grid-column:1/-1}.lp-scroll{overflow:auto}.vpl table{width:100%;border-collapse:collapse;white-space:nowrap}.vpl th,.vpl td{text-align:left;padding:13px 10px;border-bottom:1px solid var(--lp-line);font-size:.9rem;vertical-align:top}.vpl th{text-transform:uppercase;font-size:.72rem;letter-spacing:.06em;color:var(--lp-muted)}.lp-wrap{white-space:normal;min-width:200px;max-width:380px;overflow-wrap:anywhere}.lp-badge{display:inline-block;padding:5px 10px;border-radius:20px;background:#35285c;color:#dccbff;font-size:.8rem}.lp-approved{background:#153e34;color:#91f5ca}.lp-rejected{background:#4d2130;color:#ffafc8}.lp-alert{border-left:4px solid #b28aff;background:#302149;padding:14px;border-radius:8px;margin-bottom:18px}.lp-alert.lp-error{border-color:#fc739b;background:#492033}.lp-check{display:flex!important;align-items:flex-start;gap:9px;line-height:1.5;margin:16px 0!important}.lp-check input{margin-top:5px}.lp-pager{display:flex;gap:12px;align-items:center;margin-top:16px;flex-wrap:wrap}.lp-stat{font-size:1.7rem;font-weight:750}.vpl details{margin-top:8px}.vpl summary{cursor:pointer;color:#d0b8ff}.vpl details form{min-width:240px;padding-top:12px}.vpl input:focus,.vpl select:focus,.vpl textarea:focus,.vpl button:focus-visible{outline:2px solid #b38aff;outline-offset:2px}.lp-notes{white-space:pre-wrap;overflow-wrap:anywhere}.lp-gap{margin-top:14px}@media(max-width:900px){.lp-grid,.lp-fields{grid-template-columns:1fr}.lp-wide{grid-column:auto}.vpl{padding:14px}.lp-card{padding:16px}}@media print{.lp-noprint,.sidebar,.topbar,nav,.admin-footer{display:none!important}.vpl{--lp-card:#fff;--lp-text:#111;--lp-muted:#444;--lp-line:#bbb}.lp-scroll{overflow:visible}.vpl table{white-space:normal}}

/* Neon violet ledger styling, scoped to this page. */
.vpl{--lp-bg:#080412;--lp-card:#10091f;--lp-text:#f3eaff;--lp-muted:#b6a0d0;--lp-line:#6c349877;--lp-accent:#a719ff;color:var(--lp-text);background:radial-gradient(ellipse at top right,#4a107133,transparent 65%),#080412;padding:24px;border-radius:18px;color-scheme:dark;font-family:Inter,system-ui,-apple-system,'Segoe UI',sans-serif}
.vpl .lp-card{background:linear-gradient(140deg,#1b0b33,#0b0619);border:1px solid #7839af88;border-radius:16px;box-shadow:0 8px 28px #0003;padding:22px;margin-bottom:20px}
.vpl>section.lp-head:first-child{background:repeating-linear-gradient(135deg,transparent 0 80px,#9c1aff0a 81px 115px),radial-gradient(ellipse at right top,#61008a66,transparent 70%),#120720;border-color:#a13be2}
.vpl h1{font-size:clamp(24px,2.5vw,34px);letter-spacing:-.8px;color:white;font-weight:800}.vpl h2{color:white;font-size:19px;font-weight:750}.vpl .lp-muted{color:#b4a0cc;font-size:12px;line-height:1.65}
.vpl :is(button,.lp-btn){background:linear-gradient(110deg,#8500f5,#b528ff);color:white;border:1px solid #c54aff66;border-radius:9px;box-shadow:0 4px 16px #8900ff33;font-weight:650;font-size:12px;padding:10px 14px}.vpl .lp-secondary{background:#1c0c34;border-color:#7d3caa;color:#eadbff;box-shadow:none}.vpl .lp-danger{background:#811b3a;border-color:#bd3c6a}.vpl a{color:#d799ff}.vpl .lp-btn{color:white}
.vpl :is(input:not([type=checkbox]),select,textarea){background:#0e071e;color:#f2e7ff;border:1px solid #64358499;border-radius:9px;padding:10px 12px;color-scheme:dark;font-size:13px}.vpl label{color:#dac9ef;font-size:12px}.vpl input[type=checkbox]{accent-color:#b122ff}.vpl :is(input,select,textarea,button,a):focus-visible{outline:2px solid #dc9aff;outline-offset:3px}
.vpl table{border-collapse:collapse;font-size:12px}.vpl th{background:#291141;color:#e5d2ff;border-color:#6d349655;font-size:11px;padding:12px}.vpl td{border-color:#68329433;padding:12px}.vpl tbody tr:hover{background:#42135c33}.vpl td strong{color:#23e5ab}.vpl .lp-scroll{border:1px solid #64358444;border-radius:10px}.vpl .lp-alert{background:#24103d;border:1px solid #7747a055;color:#d2b9ed;border-radius:10px;font-size:12px;line-height:1.7}.vpl .lp-error{background:#45102a;border-color:#bb305a;color:#ffadc6}.vpl .lp-stat{color:#24edb5;font-size:30px;font-weight:800}.vpl .lp-pager{color:#b9a2d0}
@media(max-width:650px){.vpl{padding:12px;border-radius:0}.vpl .lp-card{padding:15px}.vpl .lp-grid,.vpl .lp-fields{grid-template-columns:1fr}.vpl h1{font-size:25px}}
@media(prefers-reduced-motion:reduce){.vpl *{transition:none!important;animation:none!important}}
@media print{.vpl,.vpl .lp-card{background:white!important;color:#111!important;box-shadow:none}.vpl :is(h1,h2,td,th,strong,.lp-muted){color:#111!important}.vpl .lp-noprint{display:none!important}}
.vpl .lp-chart-wrap{overflow:hidden;border:1px solid #64358444;border-radius:12px;background:linear-gradient(180deg,#140a26,#0b0617);padding:10px}.vpl .lp-chart-wrap svg{display:block;width:100%;height:auto;min-height:220px}.vpl .lp-chart-wrap text{font-family:system-ui,sans-serif;fill:#cbb8e2}.vpl .lp-chart-legend{display:flex;gap:18px;flex-wrap:wrap;color:#d8c5ed;font-size:12px;margin:8px 4px}.vpl .lp-chart-legend span{display:inline-flex;align-items:center;gap:7px}.vpl .lp-chart-legend i{display:inline-block;width:12px;height:8px;border-radius:3px}.vpl .lp-live-status{min-height:1.5em}.vpl .lp-history-table td{min-width:86px}.vpl .lp-table-status{font-weight:700}.vpl .lp-chart-controls{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.vpl .lp-chart-controls label{min-width:210px}.vpl .lp-chart-controls select{min-width:220px}@media(max-width:650px){.vpl .lp-chart-controls label,.vpl .lp-chart-controls select{width:100%;min-width:0}}
/* Theme-aware ledger palette. header.php changes html[data-theme] when the shared toggle is used. */
html[data-theme="dark"] .vpl{--lp-bg:#080412;--lp-card:#10091f;--lp-line:#6c349877;--lp-text:#f3eaff;--lp-muted:#b6a0d0;--lp-accent:#a719ff;--lp-input:#0e071e;--lp-table-head:#291141;--lp-alert:#24103d;--lp-chart:#0b0617;--lp-chart-grid:#70458d;--lp-chart-label:#cbb8e2;--lp-bar:#4285ff;--lp-line-chart:#cb5cff;--lp-badge:#35285c;--lp-badge-text:#dccbff;--lp-hover:#42135c33;--lp-shadow:#0003;color-scheme:dark;background:radial-gradient(ellipse at top right,#4a107133,transparent 65%),var(--lp-bg);color:var(--lp-text)}
html[data-theme="dark"] .vpl{--lp-summary:#1b0b33;--lp-summary-accent:#23e5ab}
html[data-theme="light"] .vpl{--lp-bg:#f7f5fb;--lp-card:#fff;--lp-line:#ddd4ea;--lp-text:#282137;--lp-muted:#6c617c;--lp-accent:#7138bd;--lp-input:#fff;--lp-table-head:#f0eaf8;--lp-alert:#f4effa;--lp-chart:#fbfaff;--lp-chart-grid:#c9bbd9;--lp-chart-label:#625672;--lp-bar:#4285ff;--lp-line-chart:#9a36d7;--lp-badge:#eee5f8;--lp-badge-text:#56327b;--lp-hover:#f5effb;--lp-shadow:#38234d12;--lp-summary:#f2eafb;--lp-summary-accent:#147c5b;color-scheme:light;background:var(--lp-bg);color:var(--lp-text)}
html[data-theme="dark"] .vpl .lp-card,html[data-theme="light"] .vpl .lp-card{background:var(--lp-card);color:var(--lp-text);border-color:var(--lp-line);box-shadow:0 8px 28px var(--lp-shadow)}
html[data-theme="dark"] .vpl>section.lp-head:first-child{background:repeating-linear-gradient(135deg,transparent 0 80px,#9c1aff0a 81px 115px),radial-gradient(ellipse at right top,#61008a66,transparent 70%),#120720;border-color:#a13be2}
html[data-theme="light"] .vpl>section.lp-head:first-child{background:linear-gradient(115deg,#fff 35%,#f0e8fa);border-color:#d8c5eb}
html[data-theme="light"] .vpl :is(h1,h2,h3){color:var(--lp-text)}
html[data-theme="light"] .vpl .lp-muted,html[data-theme="light"] .vpl label{color:var(--lp-muted)}
html[data-theme="light"] .vpl :is(input:not([type=checkbox]),select,textarea){background:var(--lp-input);color:var(--lp-text);border-color:var(--lp-line);color-scheme:light}
html[data-theme="light"] .vpl select option{background:#fff;color:#282137}
html[data-theme="light"] .vpl .lp-secondary{background:#fff;color:#56327b;border-color:#cdb9e0;box-shadow:none}
html[data-theme="light"] .vpl .lp-danger{background:#a83255;color:#fff;border-color:#bd4968}
html[data-theme="light"] .vpl a{color:#6330a0}
html[data-theme="light"] .vpl a.lp-btn,html[data-theme="light"] .vpl :is(button:not(.lp-secondary):not(.lp-danger),.lp-btn:not(.lp-secondary)){color:#fff}
html[data-theme="light"] .vpl table{color:var(--lp-text)}
html[data-theme="light"] .vpl th{background:var(--lp-table-head);color:#5f5270;border-color:var(--lp-line)}
html[data-theme="light"] .vpl td{color:var(--lp-text);border-color:var(--lp-line)}
html[data-theme="light"] .vpl tbody tr:hover{background:var(--lp-hover)}
html[data-theme="light"] .vpl td strong{color:#147c5b}
html[data-theme="light"] .vpl .lp-alert{background:var(--lp-alert);border-color:#c8b5dc;color:#57486b}
html[data-theme="light"] .vpl .lp-alert.lp-error{background:#fff0f3;border-color:#d64b6d;color:#84253e}
html[data-theme="light"] .vpl .lp-badge{background:var(--lp-badge);color:var(--lp-badge-text)}
html[data-theme="light"] .vpl .lp-approved{background:#e0f5ec;color:#176547}
html[data-theme="light"] .vpl .lp-rejected{background:#fde8ed;color:#912d49}
html[data-theme="light"] .vpl .lp-stat{color:#147c5b}
html[data-theme="light"] .vpl details summary{color:#6330a0}
html[data-theme="light"] .vpl .lp-chart-wrap{background:linear-gradient(180deg,#fff,#f8f5fc);border-color:var(--lp-line)}
.vpl .lp-chart-wrap svg text{fill:var(--lp-chart-label)}
.vpl .lp-chart-wrap svg line{stroke:var(--lp-chart-grid)}
.vpl .lp-chart-wrap svg rect{fill:var(--lp-bar)!important}
.vpl .lp-chart-wrap svg polyline,.vpl .lp-chart-wrap svg circle{stroke:var(--lp-line-chart)!important}
.vpl .lp-chart-wrap svg polyline{fill:none!important}
.vpl .lp-chart-wrap svg circle{fill:var(--lp-line-chart)!important}
html[data-theme="light"] .vpl :is(input,select,textarea,button,a):focus-visible{outline-color:#7138bd}
.vpl .lp-summary-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}
.vpl .lp-summary-card{min-width:0;padding:18px;border:1px solid var(--lp-line);border-radius:14px;background:var(--lp-summary,#1b0b33)}
.vpl .lp-summary-card .lp-muted{margin:0}.vpl .lp-summary-value{display:block;color:var(--lp-summary-accent,#23e5ab);font-size:clamp(22px,2.4vw,32px);font-weight:800;overflow-wrap:anywhere;margin:6px 0}
.vpl .lp-payment-filters{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:18px 0}
.vpl .lp-filter-actions{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
@media(max-width:850px){.vpl .lp-summary-grid{grid-template-columns:1fr 1fr}.vpl .lp-payment-filters{grid-template-columns:1fr 1fr}}
@media(max-width:560px){.vpl .lp-summary-grid,.vpl .lp-payment-filters{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.vpl *{transition:none!important;animation:none!important}}
</style>
<main class="dashboard-content"><div class="vpl">
<section class="lp-card lp-head"><div><h1>Payment Ledger &amp; Raise Requests</h1><p class="lp-muted" style="margin:0"><?php echo $isOwner ? 'Owner workspace · actual payment transactions and hourly-rate decisions' : 'Request a raise and track your own review history'; ?></p></div><div class="lp-noprint"><?php if ($isOwner): ?><a class="lp-btn lp-secondary" href="admin-pay.php">Monthly payroll</a><?php endif; ?></div></section>
<?php if ($flash): ?><div class="lp-alert <?php echo $flash['ok'] ? '' : 'lp-error'; ?>" role="status"><?php echo vpl_h($flash['message']); ?></div><?php endif; ?>
<?php if ($isOwner): ?>
<?php if ($filterError !== ''): ?><div class="lp-alert lp-error" role="alert"><?php echo vpl_h($filterError); ?></div><?php endif; ?>
<section class="lp-summary-grid" aria-label="Payment and payroll summary">
  <article class="lp-summary-card"><span class="lp-muted">Recorded payments · all time</span><strong class="lp-summary-value"><?php echo vpl_h(vpl_money($allTimeLedgerTotal)); ?></strong><p class="lp-muted"><?php echo $allTimeLedgerCount; ?> transaction(s) in this ledger; separate from monthly payroll records.</p></article>
  <article class="lp-summary-card"><span class="lp-muted">Outstanding payroll · this month</span><strong class="lp-summary-value" id="vpl-outstanding-payroll">Loading…</strong><p class="lp-muted">Eligible payroll marked pending in the current payroll month.</p></article>
  <article class="lp-summary-card"><span class="lp-muted">Pending raise requests</span><strong class="lp-summary-value"><?php echo $pendingCount; ?></strong><p class="lp-muted">Awaiting an owner decision.</p></article>
</section>
<section class="lp-card" id="payroll-history"><div class="lp-head"><div><h2>Admin Payment History</h2><p class="lp-muted">Live six-month payroll overview and monthly records.</p></div><div class="lp-chart-controls lp-noprint"><div><label for="vpl-payroll-admin">Select admin</label><select id="vpl-payroll-admin"><option value="">All admins — overview chart</option><?php foreach ($admins as $key=>$name): ?><option value="<?php echo vpl_h($key); ?>" <?php echo $selected===$key ? 'selected' : ''; ?>><?php echo vpl_h($name); ?></option><?php endforeach; ?></select></div><button type="button" class="lp-secondary" id="vpl-payroll-refresh">Refresh now</button></div></div>
<p class="lp-muted" id="vpl-payroll-caption">Select an admin to see all recorded payroll months. The chart follows your selection.</p><p class="lp-muted">Paid amounts use saved monthly payroll records where available. Recommendations use current settings, not historical rate snapshots. Each row is one monthly payroll record, not a bank transaction.</p>
<div class="lp-chart-wrap"><div class="lp-chart-legend"><span><i style="background:#4285ff"></i>Hours</span><span><i style="background:#cb5cff;height:3px"></i>Recommended/payable total</span></div><svg id="vpl-payroll-chart" viewBox="0 0 900 300" role="img" aria-label="Payroll for the last six months"></svg></div>
<div class="lp-live-status lp-muted" id="vpl-payroll-status" role="status" aria-live="polite">Loading live payroll data…</div>
<div class="lp-scroll lp-gap"><table class="lp-history-table"><thead><tr><th>Month</th><th>Hours</th><th>Rate / hr</th><th>Task bonus</th><th>Recommended</th><th>Paid / Payable</th><th>Status</th><th>Last updated</th><th>Notes</th></tr></thead><tbody id="vpl-payroll-body"><tr><td colspan="9">Select an admin to view their payments.</td></tr></tbody></table></div>
<div class="lp-pager lp-noprint"><button type="button" class="lp-secondary" id="vpl-payroll-prev" disabled>← Previous</button><span id="vpl-payroll-page">No admin selected</span><button type="button" class="lp-secondary" id="vpl-payroll-next" disabled>Next →</button></div>
</section>
<section class="lp-card" id="raise-overview"><div class="lp-head"><div><h2>Who wants a raise?</h2><p class="lp-muted">Pending raise requests follow the admin selection above; choose “All admins” to see everyone.</p></div><span class="lp-badge" id="vpl-raise-count">Loading…</span><a class="lp-btn" href="admin-payment-ledger.php#raise-requests">Review all raise requests</a></div>
<p class="lp-muted" id="vpl-raise-summary" role="status" aria-live="polite">Loading ledger summary…</p><div class="lp-scroll"><table><thead><tr><th>Admin</th><th>Rate at request</th><th>Requested / hr</th><th>Reason</th><th>Submitted</th><th>Review</th></tr></thead><tbody id="vpl-raise-body"><tr><td colspan="6">Loading pending raise requests…</td></tr></tbody></table></div>
<p class="lp-muted lp-gap">Ledger payments are separate transactions. They are not added to monthly payroll totals and do not automatically mark payroll paid. Approving a raise on this page updates the hourly rate; refresh payroll to recalculate its recommendations.</p></section>
<section class="lp-card" id="transaction-history"><div class="lp-head"><div><h2>Admin payment history</h2><p class="lp-muted">Search and filter actual ledger transactions. Showing up to 25 per page.</p></div></div>
<div class="lp-alert">This is a separate transaction ledger. Existing monthly “Paid” records are not imported or added to these totals. Recording an entry does not send money, mark monthly payroll paid, or calculate a remaining salary balance. Do not record the same transfer twice.</div>
<form method="get" class="lp-noprint">
  <div class="lp-payment-filters">
    <div><label for="lp-admin">Admin</label><select name="admin" id="lp-admin"><option value="">All admins</option><?php foreach ($admins as $key=>$name): ?><option value="<?php echo vpl_h($key); ?>" <?php echo $selected === $key ? 'selected' : ''; ?>><?php echo vpl_h($name); ?></option><?php endforeach; ?></select></div>
    <div><label for="payment-from">Payment date from</label><input id="payment-from" type="date" name="payment_from" value="<?php echo vpl_h($paymentFilters['payment_from']); ?>"></div>
    <div><label for="payment-to">Payment date to</label><input id="payment-to" type="date" name="payment_to" value="<?php echo vpl_h($paymentFilters['payment_to']); ?>"></div>
    <div><label for="payroll-month-filter">Payroll month</label><input id="payroll-month-filter" type="month" name="payroll_month_filter" value="<?php echo vpl_h($paymentFilters['payroll_month_filter']); ?>"></div>
    <div><label for="payment-method-filter">Payment method</label><select id="payment-method-filter" name="method"><option value="">All methods</option><?php foreach ($methods as $method): ?><option value="<?php echo vpl_h($method); ?>" <?php echo $paymentFilters['method'] === $method ? 'selected' : ''; ?>><?php echo vpl_h($method); ?></option><?php endforeach; ?></select></div>
    <div><label for="payment-search">Search admin, reference, notes, or recorder</label><input id="payment-search" type="search" name="search" maxlength="100" value="<?php echo vpl_h($paymentFilters['search']); ?>" placeholder="Enter a name or reference"></div>
  </div>
  <div class="lp-filter-actions"><button type="submit">Apply filters</button><a class="lp-btn lp-secondary" href="admin-payment-ledger.php#transaction-history">Clear filters</a></div>
</form>
<p class="lp-muted lp-gap">Showing <?php echo count($payments); ?> of <?php echo $paymentCount; ?> matching transaction(s) · filtered total <?php echo vpl_h(vpl_money($total)); ?>.</p>
<div class="lp-scroll"><table><thead><tr><th>ID</th><th>Admin</th><th>Payment date</th><th>Payroll month</th><th>Amount</th><th>Method</th><th>Reference / notes</th><th>Recorded by / at</th></tr></thead><tbody>
<?php if (!$payments): ?><tr><td colspan="8">No transactions match these filters.</td></tr><?php endif; ?>
<?php foreach ($payments as $p): ?><tr><td>#<?php echo (int)$p['id']; ?></td><td><?php echo vpl_h($admins[vpl_key((string)$p['username'])] ?? $p['username']); ?></td><td><?php echo vpl_h($p['payment_date']); ?></td><td><?php echo vpl_h($p['payroll_month']); ?></td><td><strong><?php echo vpl_h(vpl_money($p['amount'])); ?></strong></td><td><?php echo vpl_h($p['method']); ?></td><td class="lp-wrap"><strong><?php echo vpl_h($p['reference']); ?></strong><div class="lp-notes"><?php echo vpl_h($p['note']); ?></div></td><td><?php echo vpl_h($p['recorded_by']); ?><div class="lp-muted"><?php echo vpl_h($p['created_at']); ?></div></td></tr><?php endforeach; ?>
</tbody></table></div>
<div class="lp-pager lp-noprint"><?php if ($page>1): ?><a href="<?php echo vpl_h(vpl_url($selected,$page-1,$rqPage,$paymentFilters)); ?>#transaction-history">← Previous</a><?php endif; ?><span>Page <?php echo $page; ?> of <?php echo $pages; ?></span><?php if ($page<$pages): ?><a href="<?php echo vpl_h(vpl_url($selected,$page+1,$rqPage,$paymentFilters)); ?>#transaction-history">Next →</a><?php endif; ?><button type="button" class="lp-secondary" onclick="window.print()">Print</button></div>
</section>
<?php endif; ?>
<div class="lp-grid lp-noprint">
<?php if ($isOwner): ?>
<section class="lp-card"><h2>Record a payment</h2><p class="lp-muted">Record money already paid. Partial payments are separate entries. Entries cannot be edited or deleted on this page, preserving the audit trail.</p>
<form method="post" action="admin-payment-ledger.php"><input type="hidden" name="csrf" value="<?php echo vpl_h($csrf); ?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="request_token" value="<?php echo vpl_h($paymentToken); ?>">
<div class="lp-fields"><div class="lp-wide"><label for="payment-admin">Admin</label><select id="payment-admin" name="admin" required><option value="">Choose admin</option><?php foreach ($admins as $key=>$name): ?><option value="<?php echo vpl_h($key); ?>" <?php echo $selected===$key ? 'selected' : ''; ?>><?php echo vpl_h($name); ?></option><?php endforeach; ?></select></div>
<div><label for="payment-amount">Amount (PKR)</label><input id="payment-amount" type="number" name="amount" min="0.01" max="1000000" step="0.01" required></div><div><label for="payment-date">Payment date</label><input id="payment-date" type="date" name="payment_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required></div>
<div><label for="payment-month">Payroll month</label><input id="payment-month" type="month" name="payroll_month" value="<?php echo date('Y-m'); ?>" max="<?php echo date('Y-m'); ?>" required></div><div><label for="payment-method">Method</label><select id="payment-method" name="method" required><?php foreach ($methods as $method): ?><option><?php echo vpl_h($method); ?></option><?php endforeach; ?></select></div>
<div class="lp-wide"><label for="payment-ref">Transfer reference (optional)</label><input id="payment-ref" name="reference" maxlength="100" placeholder="Bank / wallet transaction reference"></div><div class="lp-wide"><label for="payment-note">Notes (optional)</label><textarea id="payment-note" name="note" maxlength="500"></textarea></div></div>
<label class="lp-check"><input type="checkbox" name="confirm_payment" value="yes" required><span>I confirm this money was already paid and this transfer is not already recorded in this ledger.</span></label><button type="submit">Save payment record</button></form></section>
<?php endif; ?>
<section class="lp-card"><h2><?php echo $isOwner ? 'Record a raise request' : 'Want a raise?'; ?></h2><p class="lp-muted"><?php echo $isOwner ? 'Record a request on an admin’s behalf. The record shows you as the submitter; this is not an automatic recommendation.' : 'Tell the owner your requested hourly rate and why. Only you and the owner can view your request here.'; ?></p>
<form method="post" action="admin-payment-ledger.php"><input type="hidden" name="csrf" value="<?php echo vpl_h($csrf); ?>"><input type="hidden" name="action" value="request_raise"><input type="hidden" name="request_token" value="<?php echo vpl_h($raiseToken); ?>">
<label for="raise-admin">Admin</label><select id="raise-admin" name="admin" required><option value="">Choose admin</option><?php foreach ($admins as $key=>$name): ?><option value="<?php echo vpl_h($key); ?>" data-rate="<?php echo vpl_h($rates[$key] ?? '0.00'); ?>" <?php echo ($isOwner ? $selected===$key : $actor===$key) ? 'selected' : ''; ?>><?php echo vpl_h($name); ?></option><?php endforeach; ?></select>
<p id="raise-rate" class="lp-muted" aria-live="polite">Current hourly rate is checked again when saving.</p><label for="requested-rate">Requested hourly rate (PKR / hr)</label><input id="requested-rate" type="number" name="requested_rate" min="0.01" max="1000000" step="0.01" required><label for="raise-reason" class="lp-gap">Why is a raise requested?</label><textarea id="raise-reason" name="reason" minlength="10" maxlength="2000" placeholder="Responsibilities, results, increased workload…" required></textarea>
<p class="lp-muted">One pending request per admin. Submitting does not change pay. Only an explicit owner approval applies the requested rate.</p><button type="submit">Submit raise request</button></form></section>
</div>
<section class="lp-card" id="raise-requests"><div class="lp-head"><div><h2><?php echo $isOwner ? 'Who wants a raise?' : 'My raise requests'; ?></h2><p class="lp-muted"><?php echo $isOwner ? 'All admin requests, regardless of the payment filter. Pending requests appear first.' : 'Your own requests, pending first.'; ?></p></div><span class="lp-badge"><?php echo $pendingCount; ?> pending</span></div>
<?php if ($isOwner): ?><div class="lp-alert">Approval updates the hourly rate immediately in monthly payroll. Your existing payroll page recalculates past recommendations using current rates; saved paid amounts stay unchanged. This page does not provide effective-dated rates. If a rate changed since submission, reject the stale request and request a new one.</div><?php endif; ?>
<div class="lp-scroll"><table><thead><tr><th>Admin / submitted</th><th>Rate at request</th><th>Requested / increase</th><th>Reason</th><th>Status / review</th><?php if ($isOwner): ?><th class="lp-noprint">Owner decision</th><?php endif; ?></tr></thead><tbody>
<?php if (!$requests): ?><tr><td colspan="<?php echo $isOwner ? 6 : 5; ?>">No raise requests yet.</td></tr><?php endif; ?>
<?php foreach ($requests as $r): $increase = (float)$r['requested_rate']-(float)$r['current_rate']; ?>
<tr><td><strong><?php echo vpl_h($admins[$r['username']] ?? $r['username']); ?></strong><div class="lp-muted"><?php echo vpl_h($r['submitted_at']); ?></div><div class="lp-muted">Submitted by <?php echo vpl_h($r['submitted_by']); ?> · #<?php echo (int)$r['id']; ?></div></td>
<td><?php echo vpl_h(vpl_money($r['current_rate'])); ?>/hr</td><td><strong><?php echo vpl_h(vpl_money($r['requested_rate'])); ?>/hr</strong><div class="lp-muted">+<?php echo vpl_h(vpl_money($increase)); ?><?php if ((float)$r['current_rate']>0): ?> (<?php echo number_format($increase/(float)$r['current_rate']*100,1); ?>%)<?php endif; ?></div></td><td class="lp-wrap"><div class="lp-notes"><?php echo vpl_h($r['reason']); ?></div></td>
<td class="lp-wrap"><span class="lp-badge <?php echo $r['status']==='approved' ? 'lp-approved' : ($r['status']==='rejected' ? 'lp-rejected' : ''); ?>"><?php echo vpl_h(ucfirst($r['status'])); ?></span><?php if ($r['reviewed_at']): ?><div class="lp-muted"><?php echo vpl_h($r['reviewed_by'].' · '.$r['reviewed_at']); ?></div><div class="lp-notes"><?php echo vpl_h($r['review_note']); ?></div><?php endif; ?></td>
<?php if ($isOwner): ?><td class="lp-noprint"><?php if ($r['status']==='pending' && isset($admins[$r['username']])): ?><details><summary>Review request</summary><form method="post" action="admin-payment-ledger.php"><input type="hidden" name="csrf" value="<?php echo vpl_h($csrf); ?>"><input type="hidden" name="action" value="review_raise"><input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>"><label for="decision-<?php echo (int)$r['id']; ?>">Decision</label><select id="decision-<?php echo (int)$r['id']; ?>" name="decision" required><option value="">Choose decision</option><option value="approved">Approve and apply rate</option><option value="rejected">Reject (keep rate)</option></select><label class="lp-gap" for="review-<?php echo (int)$r['id']; ?>">Review note</label><textarea id="review-<?php echo (int)$r['id']; ?>" name="review_note" maxlength="500" required></textarea><label class="lp-check"><input type="checkbox" name="confirm_review" value="yes" required><span>I confirm this decision. Approval immediately changes this admin’s hourly rate to <?php echo vpl_h(vpl_money($r['requested_rate'])); ?>.</span></label><button type="submit">Save decision</button></form></details><?php elseif ($r['status']==='pending'): ?><span class="lp-muted">Admin unavailable</span><?php else: ?><span class="lp-muted">Reviewed</span><?php endif; ?></td><?php endif; ?></tr>
<?php endforeach; ?></tbody></table></div>
<div class="lp-pager lp-noprint"><?php if ($rqPage>1): ?><a href="<?php echo vpl_h(vpl_url($selected,$page,$rqPage-1,$paymentFilters)); ?>#raise-requests">← Previous requests</a><?php endif; ?><span>Requests page <?php echo $rqPage; ?> of <?php echo $rqPages; ?></span><?php if ($rqPage<$rqPages): ?><a href="<?php echo vpl_h(vpl_url($selected,$page,$rqPage+1,$paymentFilters)); ?>#raise-requests">Next requests →</a><?php endif; ?></div></section>
<?php if ($isOwner): ?><section class="lp-card"><h2>Recent ledger activity</h2><p class="lp-muted">Latest 10 events. The database retains the complete audit trail.</p><?php if (!$audit): ?><p class="lp-muted">No activity yet.</p><?php endif; ?><?php foreach ($audit as $a): ?><p><strong><?php echo vpl_h(str_replace('_',' ',$a['event'])); ?></strong> · <?php echo vpl_h($admins[$a['subject']] ?? $a['subject']); ?><br><span class="lp-muted"><?php echo vpl_h($a['actor'].' · '.$a['created_at']); ?></span></p><?php endforeach; ?></section><?php endif; ?>
<p class="lp-muted">Dates use the server timezone. This page records payments; it does not connect to a bank or send money.</p>
</div></main>
<script>
(function () {
  'use strict';
  var adminSelect = document.getElementById('vpl-payroll-admin');
  var refresh = document.getElementById('vpl-payroll-refresh');
  var chart = document.getElementById('vpl-payroll-chart');
  var body = document.getElementById('vpl-payroll-body');
  var caption = document.getElementById('vpl-payroll-caption');
  var status = document.getElementById('vpl-payroll-status');
  var pager = document.getElementById('vpl-payroll-page');
  var prev = document.getElementById('vpl-payroll-prev');
  var next = document.getElementById('vpl-payroll-next');
  var raiseCount = document.getElementById('vpl-raise-count');
  var raiseSummary = document.getElementById('vpl-raise-summary');
  var raiseBody = document.getElementById('vpl-raise-body');
  var outstandingCard = document.getElementById('vpl-outstanding-payroll');
  if (adminSelect && refresh && chart && body && status && pager && prev && next) {
    var page = 1, pages = 1, generation = 0, controller = null, timer = null;
    var ns = 'http://www.w3.org/2000/svg';
    function money(value, currency) { var n = Number(value); return (currency || 'PKR') + ' ' + (Number.isFinite(n) ? n : 0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
    function cell(row, value, className) { var td = document.createElement('td'); if (className) td.className = className; td.textContent = value == null ? '' : String(value); row.appendChild(td); return td; }
    function svgEl(tag, attrs, text) { var el = document.createElementNS(ns,tag); Object.keys(attrs || {}).forEach(function(k){el.setAttribute(k,attrs[k]);}); if(text!==undefined) el.textContent=String(text); return el; }
    function drawChart(series, adminName, currency) {
      chart.replaceChildren();
      var data = Array.isArray(series) ? series : [];
      var W=900,H=300,L=62,R=28,T=28,B=48,innerW=W-L-R,innerH=H-T-B;
      var maxHours=Math.max(1,...data.map(function(x){return Number(x.hours)||0;}));
      var maxPay=Math.max(1,...data.map(function(x){return Number(x.pay)||0;}));
      for(var g=0;g<=4;g++) { var y=T+innerH*g/4; chart.appendChild(svgEl('line',{x1:L,y1:y,x2:W-R,y2:y,stroke:'#70458d', 'stroke-opacity':'.35'})); chart.appendChild(svgEl('text',{x:L-8,y:y+4,'text-anchor':'end','font-size':11},(maxHours*(4-g)/4).toFixed(0)+' h')); }
      if(!data.length) { chart.appendChild(svgEl('text',{x:W/2,y:H/2,'text-anchor':'middle','font-size':14},'No payroll data available.')); return; }
      var slot=innerW/data.length, points=[];
      data.forEach(function(item,i){ var x=L+slot*(i+.5), hours=Math.max(0,Number(item.hours)||0), pay=Math.max(0,Number(item.pay)||0); var bh=innerH*hours/maxHours, py=T+innerH*(1-pay/maxPay); var bar=svgEl('rect',{x:x-slot*.2,y:T+innerH-bh,width:slot*.4,height:bh,rx:4,fill:'#4285ff','fill-opacity':'.48'}); bar.appendChild(svgEl('title',{},item.label+': '+hours.toFixed(2)+' hours; '+money(pay,currency))); chart.appendChild(bar); points.push(x+','+py); chart.appendChild(svgEl('text',{x:x,y:H-18,'text-anchor':'middle','font-size':11},item.label||'')); });
      chart.appendChild(svgEl('polyline',{points:points.join(' '),fill:'none',stroke:'#cb5cff','stroke-width':3,'stroke-linejoin':'round'}));
      points.forEach(function(p,i){var xy=p.split(',');var dot=svgEl('circle',{cx:xy[0],cy:xy[1],r:4,fill:'#cb5cff',stroke:'#fff','stroke-width':1.5});dot.appendChild(svgEl('title',{},data[i].label+': '+money(data[i].pay,currency)));chart.appendChild(dot);});
      chart.setAttribute('aria-label','Six-month payroll trend for '+(adminName||'all admins')+'. Bars show hours; the line shows recommended or payable payroll totals.');
    }
    function renderHistory(data) {
      body.replaceChildren();
      if(!adminSelect.value) { var row=document.createElement('tr'); cell(row,'Select an admin to view their payments.'); row.firstChild.colSpan=9; body.appendChild(row); pager.textContent='No admin selected'; prev.disabled=true; next.disabled=true; return; }
      if(!data.history || !data.history.length) { var empty=document.createElement('tr'); cell(empty,'No monthly payroll records found for this admin.').colSpan=9; body.appendChild(empty); }
      (data.history||[]).forEach(function(h){ var tr=document.createElement('tr'); cell(tr,h.label||h.month); cell(tr,Number(h.hours||0).toFixed(2)); cell(tr,money(h.rate,data.currency)); cell(tr,money(h.bonus,data.currency)); cell(tr,money(h.recommended,data.currency)); cell(tr,(h.savedPaid?'Paid: ':'Payable: ')+money(h.amount,data.currency)); cell(tr,h.status==='nopay'?'Not payable':String(h.status||'').replace(/^./,function(c){return c.toUpperCase();}),'lp-table-status'); cell(tr,h.updated||'—'); cell(tr,h.note||'—','lp-wrap'); body.appendChild(tr); });
      page=Number(data.page)||1; pages=Number(data.pages)||1; pager.textContent='Page '+page+' of '+pages+' · '+(Number(data.months)||0)+' month(s)'; prev.disabled=page<=1; next.disabled=page>=pages;
    }
    function renderRaises(data) {
      var ledger=data.ledger||{}; if(raiseCount) raiseCount.textContent=(Number(ledger.pending)||0)+' pending';
      var month=String(data.asOf||'').slice(0,7)||'current month';
      if(raiseSummary) raiseSummary.textContent=ledger.available ? ((adminSelect.value ? adminSelect.options[adminSelect.selectedIndex].text : 'All admins')+' · Ledger payments for '+month+': '+money(ledger.total,data.currency)+' across '+(Number(ledger.transactions)||0)+' transaction(s). Separate from monthly Paid flags.') : (ledger.message||'Ledger summary is not available.');
      if(!raiseBody) return; raiseBody.replaceChildren(); var requests=Array.isArray(ledger.requests)?ledger.requests:[];
      if(!requests.length){var tr=document.createElement('tr');cell(tr,ledger.available?'No pending raise requests for this selection.':(ledger.message||'Raise requests could not be loaded.')).colSpan=6;raiseBody.appendChild(tr);return;}
      requests.forEach(function(r){var tr=document.createElement('tr');cell(tr,r.username||'');cell(tr,money(r.current_rate,data.currency)+'/hr');cell(tr,money(r.requested_rate,data.currency)+'/hr');cell(tr,r.reason||'','lp-wrap');cell(tr,r.submitted_at||'');var td=cell(tr,'');var a=document.createElement('a');a.textContent='Review #'+r.id;a.href='admin-payment-ledger.php?admin='+encodeURIComponent(String(r.username||''))+'#raise-requests';td.appendChild(a);raiseBody.appendChild(tr);});
    }
    function schedule(){clearTimeout(timer);timer=setTimeout(function(){if(!document.hidden)load();else schedule();},30000);}
    async function load(){clearTimeout(timer);var token=++generation;if(controller)controller.abort();controller=new AbortController();var active=controller;var timeout=setTimeout(function(){active.abort();},20000);refresh.disabled=true;status.textContent='Refreshing chart and payroll history…';try{var url=new URL('admin-pay.php',window.location.href);url.searchParams.set('ajax','payroll');url.searchParams.set('admin',adminSelect.value);url.searchParams.set('page',String(page));var response=await fetch(url.toString(),{credentials:'same-origin',cache:'no-store',signal:active.signal,headers:{Accept:'application/json'}});if(response.redirected)throw new Error('Your session may have expired. Sign in again.');if(!(response.headers.get('content-type')||'').includes('application/json'))throw new Error('Could not read payroll data. Check your session.');var data=await response.json();if(!response.ok||data.error)throw new Error(data.error||'Could not load payroll data.');if(!data.payrollSummary||!Number.isFinite(Number(data.payrollSummary.pendingAmount)))throw new Error('The current-month payroll summary is unavailable. Refresh and try again.');if(token!==generation)return;var name=adminSelect.value?adminSelect.options[adminSelect.selectedIndex].text:'';drawChart(data.chart,name,data.currency);renderHistory(data);renderRaises(data);if(outstandingCard)outstandingCard.textContent=money(data.payrollSummary.pendingAmount,data.currency);if(caption)caption.textContent=adminSelect.value?'Showing monthly payroll records for '+name+'.':'All-admin chart. Select an admin to see every recorded payroll month.';status.textContent='Live data · Updated '+new Date(data.asOf).toLocaleTimeString()+' · Refreshes every 30 seconds.'; }catch(err){if(token===generation){status.textContent=err.name==='AbortError'?'Refresh timed out. Your last values are unchanged.':(err.message||'Could not refresh payroll data.');if(outstandingCard&&outstandingCard.textContent==='Loading…')outstandingCard.textContent='Unavailable';}}finally{clearTimeout(timeout);if(token===generation){refresh.disabled=false;schedule();}}}
    adminSelect.addEventListener('change',function(){var u=new URL(window.location.href);if(adminSelect.value)u.searchParams.set('admin',adminSelect.value);else u.searchParams.delete('admin');u.searchParams.delete('page');window.location.assign(u.toString());});
    refresh.addEventListener('click',load);prev.addEventListener('click',function(){if(page>1){page--;load();}});next.addEventListener('click',function(){if(page<pages){page++;load();}});document.addEventListener('visibilitychange',function(){if(!document.hidden)load();});load();
  }
  var select = document.getElementById('raise-admin');
  var label = document.getElementById('raise-rate');
  var input = document.getElementById('requested-rate');
  if (!select || !label || !input) { return; }
  function update() {
    var option = select.options[select.selectedIndex];
    var raw = option ? option.getAttribute('data-rate') : null;
    if (raw === null) { label.textContent = 'Choose an admin to see their current rate.'; input.min = '0.01'; return; }
    var rate = Number(raw);
    label.textContent = 'Current rate: PKR ' + rate.toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + '/hr';
    input.min = ((Math.round(rate*100)+1)/100).toFixed(2);
  }
  select.addEventListener('change',update); update();
})();
</script>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body></html>
