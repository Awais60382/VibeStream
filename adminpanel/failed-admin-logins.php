<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (empty($_SESSION['AdminSession'])) {
    header('Location: ../login.php');
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/admin-alert-store.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('The failed sign-in history is unavailable.');
}

try {
    $isOwner = vs_is_owner($pdo);
} catch (Throwable $error) {
    error_log('Failed admin login owner check failed: ' . $error->getMessage());
    http_response_code(500);
    exit('Owner access could not be verified.');
}
if (!$isOwner) {
    http_response_code(403);
    exit('This page is available to the site owner only.');
}

$range = (string)($_GET['range'] ?? '7d');
if (!in_array($range, ['1d', '7d', '30d', 'all'], true)) {
    $range = '7d';
}
$rangeSql = [
    '1d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)',
    '7d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)',
    '30d' => ' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
    'all' => '',
][$range] ?? ' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
$search = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$errorMessage = '';
$rows = [];
$total = 0;
$pages = 1;
$todayCount = 0;
$weekCount = 0;
$uniqueAccounts = 0;
$uniqueIps = 0;

$parseAttempt = static function (array $row): array {
    $message = (string)($row['message'] ?? '');
    $title = (string)($row['title'] ?? '');
    $text = trim($message !== '' ? $message : $title);

    $account = 'Unknown account';
    $ip = 'Unknown';

    if (preg_match('/(?:attempt for|sign-in for|login for|account)\s+(.+?)\s+(?:failed|from)\s+(?:from\s+)?(?:IP\s+)?([^\s\.]+)?/i', $text, $matches)) {
        $account = trim($matches[1]) ?: 'Unknown account';
    } elseif (preg_match('/A sign-in attempt for (.+?) failed/i', $text, $matches)) {
        $account = trim($matches[1]) ?: 'Unknown account';
    }

    if (preg_match('/\b(?:IP(?: address)?\s*[:=]?\s*|from\s+)([0-9a-f:.]+)\b/i', $text, $matches)) {
        $ip = trim($matches[1]) ?: 'Unknown';
    } elseif (preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b|\b(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}\b/i', $text, $matches)) {
        $ip = trim($matches[0]) ?: 'Unknown';
    }

    return [$account, $ip];
};

try {
    vs_admin_alerts_ensure($pdo);
    $searchSql = '';
    $args = [];
    if ($search !== '') {
        $searchSql = ' AND (message LIKE :message_search OR title LIKE :title_search)';
        $args[':message_search'] = '%' . $search . '%';
        $args[':title_search'] = '%' . $search . '%';
    }

    $count = $pdo->prepare(
        "SELECT COUNT(*) FROM admin_alerts WHERE kind = 'admin_login_failed'{$rangeSql}{$searchSql}"
    );
    $count->execute($args);
    $total = (int)$count->fetchColumn();

    $todayCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM admin_alerts WHERE kind = 'admin_login_failed' AND created_at >= CURDATE()"
    )->fetchColumn();
    $weekCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM admin_alerts WHERE kind = 'admin_login_failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )->fetchColumn();

    $uniqueList = $pdo->query(
        "SELECT title, message, created_at
         FROM admin_alerts
         WHERE kind = 'admin_login_failed'{$rangeSql}"
    )->fetchAll(PDO::FETCH_ASSOC);
    $uniqueAccountNames = [];
    $uniqueIpNames = [];
    foreach ($uniqueList as $uniqueRow) {
        [$uniqueAccount, $uniqueIp] = $parseAttempt($uniqueRow);
        if ($uniqueIp !== '' && $uniqueIp !== 'Unknown') {
            $uniqueIpNames[mb_strtolower($uniqueIp)] = true;
        }
        if ($uniqueAccount !== '' && $uniqueAccount !== 'Unknown account') {
            $uniqueAccountNames[mb_strtolower($uniqueAccount)] = true;
        }
    }
    $uniqueAccounts = count($uniqueAccountNames);
    $uniqueIps = count($uniqueIpNames);

    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    $list = $pdo->prepare(
        "SELECT id, title, message, created_at
         FROM admin_alerts
         WHERE kind = 'admin_login_failed'{$rangeSql}{$searchSql}
         ORDER BY created_at DESC, id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $list->execute($args);
    $rows = $list->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    error_log('Failed admin login history could not be loaded: ' . $error->getMessage());
    $errorMessage = 'Failed sign-in history could not be loaded. Please try again later.';
}

$queryString = static function (array $replace = []) use ($range, $search): string {
    $params = array_merge(['range' => $range, 'q' => $search], $replace);
    return '?' . http_build_query(array_filter($params, static fn($value) => $value !== ''));
};
$formatDate = static function (string $date): string {
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y · g:i A', $timestamp) : 'Time unavailable';
};

include __DIR__ . '/header.php';
?>
<style>
  .fal-page{--c-card:rgba(12,14,36,.92);--c-text:#eaf0ff;--c-muted:#9aa6d8;--c-line:rgba(138,92,255,.5);--c-glow:rgba(138,92,255,.6);--c-row:rgba(138,92,255,.18);max-width:1500px;margin:0 auto;color:var(--c-text)}
  html[data-theme="light"] .fal-page,html[data-bs-theme="light"] .fal-page{--c-card:#fff;--c-text:#0b1030;--c-muted:#55608f;--c-line:rgba(124,77,255,.4);--c-glow:rgba(124,77,255,.3);--c-row:rgba(124,77,255,.15)}
  .fal-page *{box-sizing:border-box}

  .fal-hero{position:relative;overflow:hidden;display:flex;align-items:center;gap:20px;padding:18px 22px;border:1px solid var(--c-line);border-radius:18px;background:linear-gradient(115deg,rgba(255,46,147,.22),rgba(12,14,36,.92) 45%,rgba(106,61,255,.3));box-shadow:0 0 30px -10px var(--c-glow)}
  html[data-theme="light"] .fal-hero,html[data-bs-theme="light"] .fal-hero{background:linear-gradient(115deg,rgba(255,46,147,.14),#fff 50%,rgba(124,77,255,.14))}
  .fal-icon{flex:0 0 72px;width:72px;height:72px;display:grid;place-items:center;border-radius:18px;font-size:2.2rem;color:#fff;background:linear-gradient(135deg,#ff2e93,#b0126b);box-shadow:0 0 22px rgba(255,46,147,.6)}
  .fal-hero h1{margin:0;font-size:clamp(24px,2.6vw,36px);font-weight:800;letter-spacing:-.02em;color:var(--c-text)}
  .fal-hero p{margin:4px 0 0;color:var(--c-muted);font-size:15px}
  .fal-hero-btn{margin-left:auto;flex:0 0 auto;display:inline-flex;align-items:center;gap:10px;padding:12px 22px;border-radius:14px;border:1.5px solid #00c8ff;background:linear-gradient(135deg,rgba(0,200,255,.28),rgba(10,116,230,.28));color:#fff;font-weight:700;text-decoration:none;box-shadow:0 0 18px rgba(0,200,255,.45);white-space:nowrap}
  html[data-theme="light"] .fal-hero-btn,html[data-bs-theme="light"] .fal-hero-btn{color:#065f7d}
  .fal-hero-btn:hover{filter:brightness(1.15);color:#fff;text-decoration:none}

  .fal-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:16px 0}
  .fal-stat{--c:#00c8ff;--c-rgb:0,200,255;position:relative;display:flex;align-items:center;gap:16px;padding:18px;border-radius:16px;border:1px solid rgba(var(--c-rgb),.6);background:linear-gradient(135deg,rgba(var(--c-rgb),.16),var(--c-card) 70%);box-shadow:0 0 22px -12px rgba(var(--c-rgb),.9)}
  .fal-stat-red{--c:#ff3d77;--c-rgb:255,61,119}
  .fal-stat-blue{--c:#2e9bff;--c-rgb:46,155,255}
  .fal-stat-violet{--c:#8a5cff;--c-rgb:138,92,255}
  .fal-stat-green{--c:#22e58a;--c-rgb:34,229,138}
  .fal-stat-icon{flex:0 0 58px;width:58px;height:58px;display:grid;place-items:center;border-radius:15px;border:1px solid rgba(var(--c-rgb),.35);background:rgba(var(--c-rgb),.14);color:var(--c);font-size:1.8rem}
  .fal-stat strong{display:block;font-size:2rem;line-height:1.05;font-weight:800;color:var(--c-text)}
  .fal-stat div span{display:block;margin-top:4px;color:var(--c-text);font-size:.95rem}
  .fal-stat div small{display:block;margin-top:2px;color:var(--c-muted);font-size:.8rem}

  .fal-filters{display:flex;align-items:stretch;gap:12px;margin-bottom:16px}
  .fal-select,.fal-search{position:relative;display:flex;align-items:center;gap:12px;min-height:52px;padding:0 16px;border-radius:14px;border:1px solid var(--c-line);background:var(--c-card);color:var(--c-text)}
  .fal-select{flex:0 0 auto;padding-right:42px}
  .fal-select i,.fal-search i{font-size:1.25rem;color:var(--c-text)}
  .fal-select::after{content:'';position:absolute;right:16px;top:50%;width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-70%) rotate(45deg);pointer-events:none}
  .fal-select select{appearance:none;-webkit-appearance:none;background:transparent;border:0;outline:0;color:inherit;font-weight:700;font-size:16px;cursor:pointer;width:130px;min-width:130px;flex:0 0 130px;white-space:nowrap}
  .fal-select select option{color:#111;background:#fff}
  .fal-search{flex:1 1 auto;min-width:0}
  .fal-search input{flex:1;min-width:0;background:transparent;border:0;outline:0;color:var(--c-text);font-size:15px}
  .fal-search input::placeholder{color:var(--c-muted)}
  .fal-select:focus-within,.fal-search:focus-within{box-shadow:0 0 0 2px #00e5ff}
  .fal-submit{flex:0 0 auto;display:inline-flex;align-items:center;gap:10px;padding:0 26px;border-radius:14px;border:1px solid #a070ff;background:linear-gradient(135deg,#8a3dff,#4a2bd6);color:#fff;font-weight:700;font-size:16px;box-shadow:0 0 18px rgba(138,92,255,.6);cursor:pointer}
  .fal-submit:hover{filter:brightness(1.12)}

  .fal-panel{overflow:hidden;border:1px solid var(--c-line);border-radius:18px;background:var(--c-card);box-shadow:0 0 26px -12px var(--c-glow)}
  .fal-table-wrap{overflow-x:auto}
  .fal-table{width:100%;min-width:760px;border-collapse:collapse}
  .fal-table th{padding:18px 16px;text-align:left;color:var(--c-muted);font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;border-bottom:1px solid var(--c-line)}
  .fal-table td{padding:12px 16px;text-align:left;vertical-align:middle;border-bottom:1px solid var(--c-row);font-size:15px}
  .fal-table tbody tr:last-child td{border-bottom:0}
  .fal-table tbody tr:hover{background:rgba(138,92,255,.08)}
  .fal-table .fal-num{width:60px;font-weight:700}
  .fal-table .fal-act{text-align:right;width:150px}
  .fal-acc{display:flex;align-items:center;gap:16px;min-width:0}
  .fal-avatar{flex:0 0 46px;width:46px;height:46px;display:grid;place-items:center;border-radius:50%;border:1px solid rgba(138,92,255,.6);background:radial-gradient(circle at 30% 25%,#6c4bd9,#2a1a66);color:#d9ccff;font-size:1.5rem;box-shadow:0 0 10px rgba(138,92,255,.4)}
  .fal-msg{max-width:330px;line-height:1.35;overflow-wrap:anywhere}
  .fal-ip{font-variant-numeric:tabular-nums}
  .fal-view{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-width:118px;padding:9px 18px;border-radius:999px;border:1px solid #00c8ff;background:rgba(0,200,255,.1);color:#00c8ff;font-weight:700;font-size:15px;cursor:pointer;transition:box-shadow .18s ease,background .18s ease}
  .fal-view:hover{background:rgba(0,200,255,.22);box-shadow:0 0 16px rgba(0,200,255,.5)}
  html[data-theme="light"] .fal-view,html[data-bs-theme="light"] .fal-view{color:#0089ad;border-color:#0089ad}
  .fal-empty{padding:46px 18px;text-align:center}
  .fal-empty i{display:block;margin-bottom:10px;color:#22e58a;font-size:2rem}
  .fal-empty strong{display:block;font-size:1rem}
  .fal-empty span{display:block;margin-top:5px;color:var(--c-muted);font-size:.9rem}
  .fal-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:16px 20px;border-top:1px solid var(--c-line);color:var(--c-muted);font-size:15px}
  .fal-pager{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
  .fal-pager a,.fal-pager span{display:grid;place-items:center;min-width:40px;height:40px;padding:0 8px;border-radius:10px;border:1px solid var(--c-line);background:rgba(138,92,255,.06);color:var(--c-text);font-weight:600;text-decoration:none}
  .fal-pager a:hover{border-color:#00e5ff;color:var(--c-text);text-decoration:none}
  .fal-pager .is-current{background:linear-gradient(135deg,#8a3dff,#5a2fe0);border-color:#a070ff;color:#fff;box-shadow:0 0 14px rgba(138,92,255,.6)}
  .fal-pager .is-disabled{opacity:.4}
  .fal-pager .fal-gap{border:0;background:transparent;min-width:20px}
  .fal-alert{margin:0 0 16px;padding:14px 18px;border-radius:14px;border:1px solid rgba(255,176,32,.5);background:rgba(255,176,32,.1);color:var(--c-text)}

  .fal-dialog{width:min(520px,92vw);padding:22px;border:1px solid var(--c-line);border-radius:18px;background:var(--c-card);color:var(--c-text);box-shadow:0 0 40px -8px var(--c-glow)}
  .fal-dialog::backdrop{background:rgba(3,1,15,.7)}
  .fal-dialog-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
  .fal-dialog-head h2{margin:0;font-size:1.2rem;font-weight:700}
  .fal-dialog button.fal-close{padding:7px 14px;border-radius:10px;border:1px solid var(--c-line);background:transparent;color:var(--c-text);cursor:pointer}
  .fal-dl{display:grid;gap:10px;margin:0}
  .fal-dl div{padding:10px 12px;border-radius:10px;background:rgba(138,92,255,.08)}
  .fal-dl dt{color:var(--c-muted);font-size:.75rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
  .fal-dl dd{margin:3px 0 0;overflow-wrap:anywhere}

  @media(max-width:1100px){.fal-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:700px){
    .fal-hero{flex-wrap:wrap;padding:16px}.fal-hero-btn{margin-left:0}
    .fal-stats{grid-template-columns:1fr}
    .fal-filters{flex-wrap:wrap}.fal-search{flex:1 1 100%;order:3}.fal-submit{flex:1}.fal-select{flex:1}
  }
</style>

<?php
$rangeLabels = ['1d' => '24 Hours', '7d' => '7 Days', '30d' => '30 Days', 'all' => 'All time'];
$showSettings = !function_exists('vs_admin_route_allowed') || vs_admin_route_allowed('platform-settings.php');
$pageWindow = [];
for ($p = 1; $p <= $pages; $p++) {
    if ($p === 1 || $p === $pages || abs($p - $page) <= 1 || ($page <= 3 && $p <= 5) || ($page >= $pages - 2 && $p >= $pages - 4)) {
        $pageWindow[] = $p;
    }
}
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-4 fal-page">
    <section class="fal-hero">
      <span class="fal-icon" aria-hidden="true"><i class="bi bi-shield-fill-exclamation"></i></span>
      <div>
        <h1>Failed Sign-In History</h1>
        <p>View recent failed admin login attempts and suspicious activity.</p>
      </div>
      <?php if ($showSettings): ?>
        <a class="fal-hero-btn" href="platform-settings.php"><i class="bi bi-gear-fill" aria-hidden="true"></i>View Settings</a>
      <?php endif; ?>
    </section>

    <section class="fal-stats" aria-label="Failed sign-in summary">
      <article class="fal-stat fal-stat-red"><span class="fal-stat-icon"><i class="bi bi-shield-fill-x" aria-hidden="true"></i></span><div><strong><?= number_format($weekCount) ?></strong><span>Failed Attempts</span><small>(Last 7 Days)</small></div></article>
      <article class="fal-stat fal-stat-blue"><span class="fal-stat-icon"><i class="bi bi-calendar3" aria-hidden="true"></i></span><div><strong><?= number_format($todayCount) ?></strong><span>Today</span></div></article>
      <article class="fal-stat fal-stat-violet"><span class="fal-stat-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span><div><strong><?= number_format($uniqueAccounts) ?></strong><span>Unique Accounts</span><small>(<?= $e($rangeLabels[$range]) ?>)</small></div></article>
      <article class="fal-stat fal-stat-green"><span class="fal-stat-icon"><i class="bi bi-globe2" aria-hidden="true"></i></span><div><strong><?= number_format($uniqueIps) ?></strong><span>Unique IPs</span><small>(<?= $e($rangeLabels[$range]) ?>)</small></div></article>
    </section>

    <form class="fal-filters" method="get">
      <label class="fal-select"><i class="bi bi-calendar-week" aria-hidden="true"></i>
        <select name="range" aria-label="Time range" onchange="this.form.submit()">
          <?php foreach ($rangeLabels as $value => $label): ?><option value="<?= $e($value) ?>"<?= $range === $value ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="fal-search"><i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" value="<?= $e($search) ?>" placeholder="Search by account or IP..." aria-label="Search failed sign-ins">
      </label>
      <button class="fal-submit" type="submit"><i class="bi bi-search" aria-hidden="true"></i>Search</button>
    </form>

    <?php if ($errorMessage !== ''): ?>
      <div class="fal-alert" role="alert"><?= $e($errorMessage) ?></div>
    <?php endif; ?>

    <section class="fal-panel" aria-label="Failed sign-in history">
      <?php if (!$rows && $errorMessage === ''): ?>
        <div class="fal-empty"><i class="bi bi-shield-check" aria-hidden="true"></i><strong>No failed admin sign-ins found</strong><span>Try a wider time range, or check back if a sign-in fails.</span></div>
      <?php elseif ($rows): ?>
        <div class="fal-table-wrap">
          <table class="fal-table">
            <thead><tr><th class="fal-num" scope="col">#</th><th scope="col">Account / Message</th><th scope="col">IP Address</th><th scope="col">Date &amp; Time</th><th class="fal-act" scope="col">Action</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $row):
                [$account, $ip] = $parseAttempt($row);
                $messageText = trim((string)($row['message'] ?? '')) !== '' ? trim((string)$row['message']) : trim((string)($row['title'] ?? ''));
                $timeText = $formatDate((string)$row['created_at']);
                $timestamp = strtotime((string)$row['created_at']);
              ?>
                <tr>
                  <td class="fal-num"><?= number_format(($page - 1) * $perPage + $i + 1) ?></td>
                  <td><div class="fal-acc"><span class="fal-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></span><div class="fal-msg"><?= $e($messageText !== '' ? $messageText : $account) ?></div></div></td>
                  <td class="fal-ip"><?= $e($ip) ?></td>
                  <td><time datetime="<?= $e(date(DATE_ATOM, $timestamp ?: time())) ?>"><?= $e($timeText) ?></time></td>
                  <td class="fal-act"><button type="button" class="fal-view" data-account="<?= $e($account) ?>" data-ip="<?= $e($ip) ?>" data-time="<?= $e($timeText) ?>" data-message="<?= $e($messageText) ?>"><i class="bi bi-eye-fill" aria-hidden="true"></i>View</button></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="fal-footer">
          <span>Showing <?= number_format(($page - 1) * $perPage + 1) ?> – <?= number_format(min($page * $perPage, $total)) ?> of <?= number_format($total) ?> result<?= $total === 1 ? '' : 's' ?></span>
          <?php if ($pages > 1): ?>
            <nav class="fal-pager" aria-label="Failed sign-in pages">
              <?php $previous = 0; foreach ($pageWindow as $p):
                if ($previous && $p - $previous > 1): ?><span class="fal-gap" aria-hidden="true">…</span><?php endif;
                $previous = $p;
                if ($p === $page): ?><span class="is-current" aria-current="page"><?= $p ?></span>
                <?php else: ?><a href="<?= $e($queryString(['page' => $p])) ?>"><?= $p ?></a><?php endif;
              endforeach; ?>
              <?php if ($page < $pages): ?><a href="<?= $e($queryString(['page' => $page + 1])) ?>" aria-label="Next page"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
              <?php else: ?><span class="is-disabled" aria-disabled="true"><i class="bi bi-chevron-right" aria-hidden="true"></i></span><?php endif; ?>
            </nav>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </section>
  </div>
</main>

<dialog class="fal-dialog" id="falDialog" aria-labelledby="falDialogTitle">
  <div class="fal-dialog-head"><h2 id="falDialogTitle">Failed sign-in attempt</h2><button type="button" class="fal-close" id="falClose">Close</button></div>
  <dl class="fal-dl" id="falDetails"></dl>
</dialog>

<footer class="admin-footer">
  <div class="container-fluid px-3 px-lg-4"><span>VibeStream Admin Panel</span></div>
</footer>
</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<script>
(function () {
  'use strict';
  var dialog = document.getElementById('falDialog'), list = document.getElementById('falDetails'), close = document.getElementById('falClose');
  if (!dialog || !list) return;
  document.addEventListener('click', function (event) {
    var button = event.target.closest('.fal-view');
    if (!button) return;
    var d = button.dataset, fields = [['Account', d.account], ['IP address', d.ip], ['Date & time', d.time], ['Message', d.message]];
    list.replaceChildren();
    fields.forEach(function (f) {
      var box = document.createElement('div'), dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = f[0]; dd.textContent = f[1] || '—'; box.append(dt, dd); list.appendChild(box);
    });
    if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
  });
  close.addEventListener('click', function () { if (dialog.close) dialog.close(); else dialog.removeAttribute('open'); });
  dialog.addEventListener('click', function (event) { if (event.target === dialog && dialog.close) dialog.close(); });
})();
</script>
</body>
</html>