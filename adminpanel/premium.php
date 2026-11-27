<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';
require_once __DIR__ . '/../premium-store.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-activity-store.php';

if (empty($_SESSION['premium_csrf'])) {
    $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['premium_csrf'];
$hasDb = isset($pdo) && $pdo instanceof PDO;
$premiumActor = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
$ceoCannotRevokeOthers = $hasDb && !vs_is_owner($pdo) && vs_premium_is_ceo_account($premiumActor);
$dbError = '';
$members = [];
$requests = [];

if ($hasDb) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        vs_premium_ensure($pdo);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                header('Location: premium.php?msg=expired');
                exit;
            }
            $act = (string)($_POST['action'] ?? '');

            // Approve or reject a payment request
            if ($act === 'approve' || $act === 'reject') {
                $done = vs_premium_review($pdo, (int)($_POST['id'] ?? 0), $act === 'approve');
                if ($done) {
                    vs_admin_log($pdo, 'premium', ($act === 'approve' ? 'Approved' : 'Rejected') . ' Premium payment request #' . (int)($_POST['id'] ?? 0));
                }
                header('Location: premium.php?msg=' . ($done ? ($act === 'approve' ? 'approved' : 'rejected') : 'handled'));
                exit;
            }

            // Grant or revoke Premium by hand
            $who = trim((string)($_POST['username'] ?? ''));
            if ($act === 'revoke' && $who !== '') {
                $actor = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
                if ($ceoCannotRevokeOthers && strcasecmp($actor, $who) !== 0) {
                    header('Location: premium.php?msg=ceo_restricted');
                    exit;
                }
                vs_premium_cancel($pdo, $who);
                vs_admin_log($pdo, 'premium', 'Cancelled Premium for ' . $who);
                header('Location: premium.php?msg=revoked');
                exit;
            }
            if ($act === 'grant' && $who !== '') {
                $ok = vs_premium_set($pdo, $who, (string)($_POST['plan'] ?? ''));
                if ($ok) {
                    vs_admin_log($pdo, 'premium', 'Gave Premium (' . (string)($_POST['plan'] ?? '') . ') to ' . $who);
                }
                header('Location: premium.php?msg=' . ($ok ? 'granted' : 'failed'));
                exit;
            }
        }

        $members  = vs_premium_all($pdo);
        $requests = vs_premium_requests($pdo, 100);
    } catch (Throwable $e) {
        $dbError = 'Could not read premium members: ' . $e->getMessage();
    }
} else {
    $dbError = 'No database connection found. Check that ../connection.php creates $pdo.';
}

$planLabel = function (string $p): string {
    return VS_PREMIUM_PLANS[$p]['label'] ?? ucfirst($p);
};

$stActive = 0; $stNew = 0; $stEnded = 0; $stRev = 0.0;
foreach ($members as $i => $m) {
    $on = vs_premium_is_active($m);
    $members[$i]['_active'] = $on;
    if ($on) {
        $stActive++;
        $stRev += VS_PREMIUM_PLANS[$m['plan']]['price'] ?? 0;
    } else {
        $stEnded++;
    }
    if (strtotime((string)$m['started_at']) > time() - 7 * 86400) {
        $stNew++;
    }
}
$stPending = 0;
foreach ($requests as $r) {
    if ($r['status'] === 'pending') {
        $stPending++;
    }
}

// Everyone who can get Premium, including admins and the owner.
$people = [];
$peopleCount = ['all' => 0, 'has' => 0, 'not' => 0, 'admins' => 0, 'ceos' => 0, 'owners' => 0];
if ($hasDb && $dbError === '') {
    try {
        $uc = vs_cols($pdo, 'users');
        $mName  = $uc ? vs_pick($uc, ['username', 'user_name', 'login', 'uname']) : null;
        $mEmail = $uc ? vs_pick($uc, ['email', 'email_address', 'mail']) : null;
        $mRole  = $uc ? vs_pick($uc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']) : null;
        $mId    = $uc ? vs_pick($uc, ['id', 'user_id', 'userid', 'uid']) : null;
        if ($mName) {
            $sel = vs_q($mName) . ' AS f_name'
                 . ($mEmail ? ', ' . vs_q($mEmail) . ' AS f_email' : '')
                 . ($mRole ? ', ' . vs_q($mRole) . ' AS f_role' : '')
                 . ($mId ? ', ' . vs_q($mId) . ' AS f_id' : '');
            $urows = $pdo->query('SELECT ' . $sel . ' FROM `users` ORDER BY ' . vs_q($mName) . ' ASC')->fetchAll(PDO::FETCH_ASSOC);

            $premState = [];   // lower-case user name => true (active now) / false (ended)
            foreach ($members as $m) {
                $k = mb_strtolower(trim((string)$m['username']));
                $premState[$k] = !empty($premState[$k]) || !empty($m['_active']);
            }
            foreach ($urows as $u) {
                $nm = trim((string)($u['f_name'] ?? ''));
                $em = trim((string)($u['f_email'] ?? ''));
                if ($nm === '') {
                    continue;
                }
                $isOwner = $em !== '' && vs_is_site_owner_email($em);
                $isCeo = $isOwner || ($mId !== null && !empty($u['f_id']) && vs_is_ceo_id($u['f_id']));
                $hasAdminRole = isset($u['f_role']) && strcasecmp(trim((string)$u['f_role']), 'Admin') === 0;
                $isAdmin = $hasAdminRole || $isCeo;
                $roleLabel = $isOwner ? 'Owner' : ($isCeo ? 'CEO' : ($hasAdminRole ? 'Admin' : 'User'));
                $roleFilter = strtolower($roleLabel);
                $k = mb_strtolower($nm);
                if (!empty($premState[$k]))            { $state = 'active'; }
                elseif (array_key_exists($k, $premState)) { $state = 'ended'; }
                else                                   { $state = 'none'; }
                $people[] = ['name' => $nm, 'email' => $em, 'state' => $state, 'is_admin' => $isAdmin, 'role' => $roleLabel, 'role_filter' => $roleFilter];
                $peopleCount['all']++;
                if ($isAdmin) { $peopleCount['admins']++; }
                if ($roleFilter === 'ceo') { $peopleCount['ceos']++; }
                if ($roleFilter === 'owner') { $peopleCount['owners']++; }
                if ($state === 'active') { $peopleCount['has']++; } else { $peopleCount['not']++; }
            }
        }
    } catch (Throwable $e) {
        error_log('premium people list failed: ' . $e->getMessage());
    }
}

if (($_GET['download'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="premium-members.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Username', 'Plan', 'Joined', 'Expires', 'Status']);
    foreach ($members as $m) {
        fputcsv($out, [$m['username'], $m['plan'], $m['started_at'], $m['expires_at'], $m['_active'] ? 'Active' : 'Ended']);
    }
    exit;
}

if (($_GET['download'] ?? '') === 'report') {
    $plans = [];
    foreach (VS_PREMIUM_PLANS as $key => $def) {
        $plans[$key] = 0;
    }
    foreach ($members as $m) {
        if ($m['_active'] && isset($plans[$m['plan']])) {
            $plans[$m['plan']]++;
        }
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="premium-report-' . date('Y-m-d') . '.txt"');
    echo "VibeStream Premium report\n";
    echo "Generated: " . date('Y-m-d H:i') . "\n\n";
    echo "Active members: $stActive\n";
    foreach ($plans as $key => $count) {
        echo "  " . $planLabel($key) . " plans: $count\n";
    }
    echo "Joined in the last 7 days: $stNew\n";
    echo "Ended (cancelled or expired): $stEnded\n";
    echo "Payments waiting for approval: $stPending\n";
    echo "Revenue from active plans: $" . number_format($stRev, 2) . "\n\n";
    echo "Members (newest first)\n";
    echo "Username | Plan | Joined | Expires | Status\n";
    foreach ($members as $m) {
        echo implode(' | ', [$m['username'], $m['plan'], $m['started_at'], $m['expires_at'], $m['_active'] ? 'Active' : 'Ended']) . "\n";
    }
    exit;
}

$msgs = [
    'granted'  => 'Premium granted.',
    'revoked'  => 'Premium cancelled for that user.',
    'approved' => 'Payment approved. Premium is now active for that user.',
    'rejected' => 'Payment rejected.',
    'handled'  => 'That payment request was already handled.',
    'failed'   => 'Could not grant Premium. Check the username and plan.',
    'expired'  => 'Session expired. Try again.',
    'ceo_restricted' => "The CEO cannot cancel another user's Premium plan.",
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';

$pmInitial = function (string $n): string {
    $c = mb_strtoupper(mb_substr(trim($n) !== '' ? trim($n) : 'U', 0, 1));
    return 'data:image/svg+xml;charset=UTF-8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" fill="#4f6df5"/>'
        . '<text x="32" y="42" font-family="Arial,sans-serif" font-size="30" font-weight="700" fill="#fff" text-anchor="middle">'
        . htmlspecialchars($c, ENT_QUOTES | ENT_XML1) . '</text></svg>'
    );
};

include "header.php";
?>
<style>
.pa-page{--c:linear-gradient(150deg,rgba(30,12,66,.95),rgba(12,7,30,.96));--ln:rgba(150,92,255,.34);--tx:#f7f3ff;--mu:#b9acd9;--in:rgba(8,5,22,.7);--hd:rgba(120,70,220,.16);--g:linear-gradient(100deg,#a23cf8,#6d2ae0);color:var(--tx)}
:is(html[data-theme=light],html[data-bs-theme=light]) .pa-page{--c:linear-gradient(150deg,#fff,#f6f0ff);--ln:rgba(109,42,224,.22);--tx:#1a1035;--mu:#5d5580;--in:#fff;--hd:rgba(109,42,224,.07)}
.pa-page *{box-sizing:border-box}
.pa-page [hidden]{display:none!important}
.pa-hero{display:flex;align-items:center;gap:22px;flex-wrap:wrap;padding:22px 24px;border:1px solid var(--ln);border-radius:20px;background:radial-gradient(520px 260px at 85% 0,rgba(168,85,247,.45),transparent 70%),linear-gradient(120deg,#1c0a45,#0b0618);color:#f7f3ff}
.pa-gem{width:110px;height:110px;flex:0 0 110px;filter:drop-shadow(0 0 22px rgba(190,100,255,.55))}
.pa-hero-copy{flex:1;min-width:240px}
.pa-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border:1px solid var(--ln);border-radius:999px;background:rgba(168,85,247,.22);font-size:13px;font-weight:700}
.pa-hero h1{margin:8px 0 6px;font-size:clamp(30px,4vw,46px);font-weight:900;font-style:italic;letter-spacing:-.02em;line-height:1.05;color:#fff}
.pa-hero h1 span{background:linear-gradient(90deg,#c77dff,#ff2a32);-webkit-background-clip:text;background-clip:text;color:transparent}
.pa-hero p{margin:0;color:#d9ceff}
.pa-actions{display:flex;gap:10px;flex-wrap:wrap}
.pa-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:40px;padding:0 18px;border:1px solid var(--ln);border-radius:10px;background:transparent;color:var(--tx);font:inherit;font-size:14px;font-weight:700;text-decoration:none;cursor:pointer}
.pa-btn:hover{border-color:#b36bff;color:var(--tx)}
.pa-hero .pa-btn{color:#fff}
.pa-btn.pri{border-color:transparent;background:var(--g);color:#fff}
.pa-btn.sm{min-height:30px;padding:0 12px;border-radius:7px;font-size:12.5px}
.pa-btn.ok{border-color:transparent;background:#16a34a;color:#fff}
.pa-btn.no{border-color:transparent;background:#be123c;color:#fff}
.pa-flash{margin-top:14px;padding:11px 14px;border:1px solid var(--ln);border-radius:12px;background:var(--hd)}
.pa-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:16px}
.pa-stat{position:relative;display:flex;align-items:center;gap:14px;padding:18px 18px 24px;border:1px solid var(--s);border-radius:16px;background:linear-gradient(150deg,color-mix(in srgb,var(--s) 24%,#0c0720),#0c0720);color:#f7f3ff;overflow:hidden}
.pa-ico{display:grid;place-items:center;flex:0 0 54px;height:54px;border-radius:14px;background:color-mix(in srgb,var(--s) 30%,transparent);color:var(--s);font-size:28px}
.pa-stat b{display:block;font-size:30px;line-height:1.1}
.pa-stat span.l{font-size:14px;opacity:.85}
.pa-bar{position:absolute;left:14px;right:14px;bottom:8px;height:5px;border-radius:9px;background:rgba(255,255,255,.12)}
.pa-bar i{display:block;height:100%;border-radius:inherit;background:var(--s)}
.pa-card{margin-top:16px;border:1px solid var(--ln);border-radius:18px;background:var(--c);overflow:hidden}
.pa-head{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:16px 18px 12px}
.pa-head>i{font-size:30px;color:#b36bff}
.pa-head h2{margin:0;font-size:19px;font-weight:800;color:var(--tx)}
.pa-head p{margin:2px 0 0;color:var(--mu);font-size:13px}
.pa-grow{flex:1;min-width:200px}
.pa-tbl{overflow-x:auto;padding:0 14px 14px}
.pa-tbl table{width:100%;border-collapse:separate;border-spacing:0;font-size:14px;background:transparent}
.pa-tbl th{padding:9px 12px;background:var(--hd);color:var(--tx);text-align:left;font-size:13px;font-weight:700;white-space:nowrap;border:0}
.pa-tbl th:first-child{border-radius:8px 0 0 8px}.pa-tbl th:last-child{border-radius:0 8px 8px 0}
.pa-tbl td{padding:9px 12px;border:0;border-bottom:1px solid var(--ln);vertical-align:middle;color:var(--tx);background:transparent}
.pa-tbl tr:last-child td{border-bottom:0}
.pa-tbl .r{text-align:right}
.pa-mu{color:var(--mu)}
.pa-who{display:flex;align-items:center;gap:10px;font-weight:600}
.pa-who img{width:34px;height:34px;border-radius:50%;object-fit:cover}
.pa-crown{color:#f5b013;margin-right:6px}
.pa-pill{display:inline-block;padding:3px 11px;border-radius:7px;border:1px solid;font-size:12.5px;font-weight:700;white-space:nowrap}
.pa-pill.warn{color:#ffb020;border-color:#e69a12;background:rgba(245,176,19,.14)}
.pa-pill.good{color:#2fd37c;border-color:#1f9d5c;background:rgba(34,197,94,.16)}
.pa-pill.bad{color:#ff6f91;border-color:#be123c;background:rgba(236,47,123,.14)}
.pa-pill.off{color:#9aa6ff;border-color:#4a56a8;background:rgba(90,100,200,.2)}
.pa-pill.info{color:#c9a2ff;border-color:#7b3fd6;background:rgba(168,85,247,.18)}
.pa-in{min-height:40px;padding:0 14px;border:1px solid var(--ln);border-radius:10px;background:var(--in);color:var(--tx);font:inherit;font-size:14px;outline:0}
.pa-in:focus{border-color:#b36bff;box-shadow:0 0 0 3px rgba(162,60,248,.22)}
select.pa-in{padding-right:10px}
.pa-seg,.pa-chips{display:inline-flex;gap:0}
.pa-seg{border:1px solid var(--ln);border-radius:10px;overflow:hidden}
.pa-chips{gap:8px;flex-wrap:wrap}
.pa-seg button,.pa-chips button{border:0;background:transparent;color:var(--tx);min-height:38px;padding:0 16px;font:inherit;font-size:14px;font-weight:600;cursor:pointer}
.pa-chips button{border:1px solid var(--ln);border-radius:999px;min-height:34px;padding:0 14px}
.pa-seg button.on,.pa-chips button.on{background:var(--g);color:#fff;border-color:transparent}
.pa-give{display:flex;gap:8px;flex-wrap:wrap}
.pa-bar2{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:0 18px 8px}
.pa-empty{padding:26px;text-align:center;color:var(--mu)}
@media(max-width:1100px){.pa-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.pa-stats{grid-template-columns:1fr}.pa-gem{display:none}}
</style>

<?php
$paTotal = max(1, $stActive + $stEnded);
$paPct = function ($n, $d) { return (int)round(100 * min(1, max(0, $n / max(1, $d)))); };
?>
      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4 pa-page">
          <header class="pa-hero">
            <svg class="pa-gem" viewBox="0 0 120 120" aria-hidden="true"><defs><linearGradient id="paG" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#e9b6ff"/><stop offset="1" stop-color="#7b2fd6"/></linearGradient></defs><path d="M30 38h60l20 24-50 46L10 62z" fill="url(#paG)"/><path d="M10 62h100M42 38l-12 24 30 46 30-46-12-24M30 62l30-24 30 24" fill="none" stroke="#fff" stroke-opacity=".45" stroke-width="2"/><path d="M42 30l8-14 10 10 10-10 8 14z" fill="#ffc83d"/></svg>
            <div class="pa-hero-copy">
              <span class="pa-badge"><i class="bi bi-gem" aria-hidden="true"></i> Premium</span>
              <h1>Premium <span>members</span></h1>
              <p>All users, including admins, need an active Premium plan. The CEO cannot revoke other users’ plans.</p>
            </div>
            <div class="pa-actions">
              <a class="pa-btn" href="?download=csv"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
              <a class="pa-btn pri" href="?download=report"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i> Create Report</a>
            </div>
          </header>

          <?php if ($flash !== ''): ?>
            <div class="pa-flash" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <section class="pa-stats" aria-label="Premium metrics">
            <article class="pa-stat" style="--s:#22c55e"><span class="pa-ico"><i class="bi bi-people-fill" aria-hidden="true"></i></span><div><b><?php echo number_format($stActive); ?></b><span class="l">Active members</span></div><span class="pa-bar"><i style="width:<?php echo $paPct($stActive, $paTotal); ?>%"></i></span></article>
            <article class="pa-stat" style="--s:#3b82f6"><span class="pa-ico"><i class="bi bi-calendar-week-fill" aria-hidden="true"></i></span><div><b><?php echo number_format($stNew); ?></b><span class="l">New this week</span></div><span class="pa-bar"><i style="width:<?php echo $paPct($stNew, max(1, $stActive)); ?>%"></i></span></article>
            <article class="pa-stat" style="--s:#f5b013"><span class="pa-ico"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span><div><b>$<?php echo number_format($stRev, 2); ?></b><span class="l">Revenue (active plans)</span></div><span class="pa-bar"><i style="width:<?php echo $paPct($stActive, $paTotal); ?>%"></i></span></article>
            <article class="pa-stat" style="--s:#ec2f7b"><span class="pa-ico"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><b><?php echo number_format($stEnded); ?></b><span class="l">Ended</span></div><span class="pa-bar"><i style="width:<?php echo $paPct($stEnded, $paTotal); ?>%"></i></span></article>
          </section>

          <section class="pa-card">
            <div class="pa-head">
              <i class="bi bi-credit-card-2-front-fill" aria-hidden="true"></i>
              <div class="pa-grow">
                <h2>Payment requests <?php if ($stPending > 0): ?><span class="pa-pill warn"><?php echo (int)$stPending; ?> waiting</span><?php endif; ?></h2>
                <p>Check that the money arrived, then approve. Approving turns Premium on for that user.</p>
              </div>
            </div>
            <?php if ($dbError === '' && !$requests): ?>
              <div class="pa-empty"><strong>No payment requests yet</strong><br>They appear here when a user sends their transaction ID on the Premium page.</div>
            <?php elseif ($requests): ?>
              <div class="pa-tbl">
                <table>
                  <thead><tr><th>User</th><th>Plan</th><th>Amount</th><th>Transaction ID</th><th>Sent</th><th>Status</th><th class="r">Action</th></tr></thead>
                  <tbody>
                    <?php foreach ($requests as $r):
                        $pill = $r['status'] === 'approved' ? 'good' : ($r['status'] === 'rejected' ? 'bad' : 'warn');
                    ?>
                      <tr>
                        <td><strong><?php echo vs_e($r['username']); ?></strong></td>
                        <td><i class="bi bi-gem pa-crown" aria-hidden="true"></i><?php echo vs_e($planLabel((string)$r['plan'])); ?></td>
                        <td>$<?php echo vs_e(number_format((float)$r['amount'], 2)); ?></td>
                        <td><code><?php echo vs_e($r['txn_ref']); ?></code></td>
                        <td class="pa-mu"><?php echo vs_e(date('M j, Y · h:i A', strtotime((string)$r['created_at']))); ?></td>
                        <td><span class="pa-pill <?php echo $pill; ?>"><?php echo vs_e(ucfirst((string)$r['status'])); ?></span></td>
                        <td class="r">
                          <?php if ($r['status'] === 'pending'): ?>
                            <form method="post" class="d-inline">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                              <button type="submit" name="action" value="approve" class="pa-btn sm ok">Approve</button>
                              <button type="submit" name="action" value="reject" class="pa-btn sm no" onclick="return confirm('Reject this payment?');">Reject</button>
                            </form>
                          <?php else: ?><span class="pa-mu">&mdash;</span><?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="pa-card">
            <div class="pa-head">
              <i class="bi bi-people-fill" aria-hidden="true"></i>
              <div class="pa-grow"><h2>Members</h2><p>Everyone who has bought Premium, newest first.</p></div>
              <input type="search" class="pa-in" id="pmSearch" placeholder="Search members..." aria-label="Search members" autocomplete="off" list="pmSuggestions">
              <datalist id="pmSuggestions"></datalist>
              <div class="pa-seg" role="group" aria-label="Filter members">
                <button type="button" class="on" data-pm="all">All</button>
                <button type="button" data-pm="active">Active</button>
                <button type="button" data-pm="ended">Ended</button>
              </div>
            </div>
            <?php if ($dbError !== ''): ?>
              <div class="pa-empty"><?php echo vs_e($dbError); ?></div>
            <?php elseif (!$members): ?>
              <div class="pa-empty"><strong>No premium members yet</strong><br>Members appear here as soon as you approve a payment.</div>
            <?php else: ?>
              <div class="pa-tbl">
                <table>
                  <thead><tr><th>Member</th><th>Plan</th><th>Joined</th><th>Expires</th><th>Status</th><th class="r">Action</th></tr></thead>
                  <tbody id="pmRows">
                    <?php foreach ($members as $m): ?>
                      <tr data-name="<?php echo vs_e(mb_strtolower($m['username'])); ?>" data-username="<?php echo vs_e($m['username']); ?>" data-status="<?php echo $m['_active'] ? 'active' : 'ended'; ?>">
                        <td><div class="pa-who"><img src="<?php echo vs_e(avatar_url($m['username'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($m['username'])); ?>';"><?php echo vs_e($m['username']); ?></div></td>
                        <td><i class="bi bi-gem pa-crown" aria-hidden="true"></i><?php echo vs_e($planLabel((string)$m['plan'])); ?></td>
                        <td class="pa-mu"><?php echo vs_e(date('M j, Y', strtotime((string)$m['started_at']))); ?></td>
                        <td class="pa-mu"><?php echo vs_e(date('M j, Y', strtotime((string)$m['expires_at']))); ?></td>
                        <td><span class="pa-pill <?php echo $m['_active'] ? 'good' : 'off'; ?>"><?php echo $m['_active'] ? 'Active' : 'Ended'; ?></span></td>
                        <td class="r">
                          <?php if ($m['_active'] && (!$ceoCannotRevokeOthers || strcasecmp($premiumActor, (string)$m['username']) === 0)): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Cancel Premium for this user?');">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="revoke">
                              <input type="hidden" name="username" value="<?php echo vs_e($m['username']); ?>">
                              <button type="submit" class="pa-btn sm no">Cancel</button>
                            </form>
                          <?php elseif ($m['_active']): ?><span class="pa-mu">CEO cannot revoke</span>
                          <?php else: ?><span class="pa-mu">&mdash;</span><?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="pa-card">
            <div class="pa-head">
              <i class="bi bi-gift-fill" aria-hidden="true"></i>
              <div><h2>Give Premium</h2><p>Manually grant Premium to any user.</p></div>
              <form method="post" class="pa-give pa-grow">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <input type="hidden" name="action" value="grant">
                <input type="text" class="pa-in" name="username" placeholder="Enter username..." aria-label="Username" required>
                <select class="pa-in" name="plan" aria-label="Plan" data-vs-no-search><?php foreach (VS_PREMIUM_PLANS as $key => $def): ?><option value="<?php echo vs_e($key); ?>" <?php echo $key === 'monthly' ? 'selected' : ''; ?>><?php echo vs_e($def['label']); ?></option><?php endforeach; ?></select>
                <button type="submit" class="pa-btn pri"><i class="bi bi-gift-fill" aria-hidden="true"></i> Give Premium</button>
              </form>
              <div class="pa-chips" role="group" aria-label="Filter people">
                <button type="button" class="on" data-gp="all">All (<?php echo (int)$peopleCount['all']; ?>)</button>
                <button type="button" data-gp="has">Has Premium (<?php echo (int)$peopleCount['has']; ?>)</button>
                <button type="button" data-gp="not">Not upgraded (<?php echo (int)$peopleCount['not']; ?>)</button>
                <button type="button" data-gp="admins">Admins (<?php echo (int)$peopleCount['admins']; ?>)</button>
                <button type="button" data-gp="ceos">CEOs (<?php echo (int)$peopleCount['ceos']; ?>)</button>
                <button type="button" data-gp="owners">Owners (<?php echo (int)$peopleCount['owners']; ?>)</button>
              </div>
            </div>
            <div class="pa-bar2">
              <input type="search" class="pa-in" id="gpSearch" placeholder="Search users" aria-label="Search users" autocomplete="off" list="gpSuggestions">
              <datalist id="gpSuggestions"></datalist>
              <span class="pa-mu">Showing <span id="gpCount"><?php echo (int)$peopleCount['all']; ?></span> of <?php echo (int)$peopleCount['all']; ?>.</span>
            </div>
            <div class="pa-tbl">
              <table>
                <thead><tr><th>User</th><th>Email</th><th>Role</th><th>Premium Status</th><th class="r">Action</th></tr></thead>
                <tbody id="gpRows">
                  <?php foreach ($people as $p): ?>
                    <tr data-name="<?php echo vs_e(mb_strtolower($p['name'] . ' ' . $p['email'])); ?>" data-username="<?php echo vs_e($p['name']); ?>" data-state="<?php echo vs_e($p['state']); ?>" data-role="<?php echo vs_e($p['role_filter']); ?>">
                      <td><div class="pa-who"><img src="<?php echo vs_e(avatar_url($p['name'])); ?>" alt="" onerror="this.onerror=null;this.src='<?php echo vs_e($pmInitial($p['name'])); ?>';"><?php echo vs_e($p['name']); ?></div></td>
                      <td class="pa-mu"><?php echo vs_e($p['email']); ?></td>
                      <td class="pa-mu"><?php echo vs_e($p['role']); ?></td>
                      <td>
                        <?php if ($p['state'] === 'active'): ?><span class="pa-pill good">Has Premium</span>
                        <?php elseif ($p['state'] === 'ended'): ?><span class="pa-pill off">Ended</span>
                        <?php else: ?><span class="pa-pill warn">Not upgraded</span><?php endif; ?>
                      </td>
                      <td class="r">
                        <?php if ($p['state'] === 'none' || $p['state'] === 'ended'): ?>
                          <form method="post" class="pa-give" style="justify-content:flex-end">
                            <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                            <input type="hidden" name="action" value="grant">
                            <input type="hidden" name="username" value="<?php echo vs_e($p['name']); ?>">
                            <select class="pa-in" name="plan" aria-label="Plan" style="min-height:30px" data-vs-no-search><?php foreach (VS_PREMIUM_PLANS as $key => $def): ?><option value="<?php echo vs_e($key); ?>" <?php echo $key === 'monthly' ? 'selected' : ''; ?>><?php echo vs_e($def['label']); ?></option><?php endforeach; ?></select>
                            <button type="submit" class="pa-btn sm pri">Give Premium</button>
                          </form>
                        <?php else: ?><span class="pa-mu">&mdash;</span><?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  <tr id="gpEmpty"<?php echo $people ? ' hidden' : ''; ?>><td colspan="5" class="pa-empty">No users found.</td></tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>Developed by <a href="https://M.Awais.com" target="_blank">Muhammad Awais</a></span>
        </div>
      </footer>
    </div>
  </div>

  <script>
  (function () {
    function group(buttons, onPick) {
      buttons.forEach(function (b) {
        b.addEventListener('click', function () {
          buttons.forEach(function (x) { x.classList.toggle('on', x === b); });
          onPick(b);
        });
      });
    }
    function addSuggestions(input, list, rows) {
      if (!input || !list) return;
      var seen = Object.create(null);
      rows.forEach(function (row) {
        var username = row.getAttribute('data-username') || '';
        var key = username.toLowerCase();
        if (!username || seen[key]) return;
        seen[key] = true;
        var option = document.createElement('option');
        option.value = username;
        list.appendChild(option);
      });
    }
    var q = document.getElementById('pmSearch'), pm = [].slice.call(document.querySelectorAll('[data-pm]')), want = 'all';
    addSuggestions(q, document.getElementById('pmSuggestions'), [].slice.call(document.querySelectorAll('#pmRows tr[data-username]')));
    function applyPm() {
      var term = (q.value || '').toLowerCase();
      document.querySelectorAll('#pmRows tr').forEach(function (tr) {
        tr.hidden = !(tr.dataset.name.indexOf(term) > -1 && (want === 'all' || tr.dataset.status === want));
      });
    }
    if (q) { q.addEventListener('input', applyPm); group(pm, function (b) { want = b.getAttribute('data-pm'); applyPm(); }); }

    var body = document.getElementById('gpRows');
    if (!body) return;
    var rows = body.querySelectorAll('tr[data-state]'), empty = document.getElementById('gpEmpty'),
        count = document.getElementById('gpCount'), search = document.getElementById('gpSearch'),
        gp = [].slice.call(document.querySelectorAll('[data-gp]')), mode = 'all';
    addSuggestions(search, document.getElementById('gpSuggestions'), [].slice.call(body.querySelectorAll('tr[data-username]')));
    function match(tr) {
      if (mode === 'all') return true;
      if (mode === 'has') return tr.dataset.state === 'active';
      if (mode === 'not') return tr.dataset.state === 'none' || tr.dataset.state === 'ended';
      if (mode === 'admins') return ['admin', 'ceo', 'owner'].indexOf(tr.dataset.role) > -1;
      return tr.dataset.role === mode.replace(/s$/, '');
    }
    function applyGp() {
      var term = (search.value || '').toLowerCase().trim(), n = 0;
      rows.forEach(function (tr) {
        var show = match(tr) &&
          (!term || tr.dataset.name.indexOf(term) > -1);
        tr.hidden = !show;
        if (show) n++;
      });
      count.textContent = n;
      empty.hidden = n !== 0;
    }
    group(gp, function (b) { mode = b.getAttribute('data-gp'); applyGp(); });
    search.addEventListener('input', applyGp);
    applyGp();
  })();
  </script>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>