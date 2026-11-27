<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession'])) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../report-helpers.php';
require_once __DIR__ . '/../avatar.php';
include_once __DIR__ . '/../vip-badge.php';
require_once __DIR__ . '/../upload-failure-store.php';
require_once __DIR__ . '/../premium-popup.php';

$USERS_TABLE = 'users';

// The heavy lifting lives in separate files so this page stays short:
//   dashboard-live.php   JSON for the live "online now" + activity feed (?live=1)
//   dashboard-data.php   every number and list shown below
//   dashboard-export.php CSV / text downloads (?download=...)
if (isset($_GET['live'])) {
    require __DIR__ . '/dashboard-live.php';
}
require __DIR__ . '/dashboard-data.php';

/* --- Signups today (added for dashboard feature) --- */
$vsdSignupsToday = null;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $vsdStCols = vs_cols($pdo, $USERS_TABLE);
        $vsdStDate = $vsdStCols ? vs_pick($vsdStCols, ['created_at','joined_at','registered_at','date_joined','created_on','reg_date']) : null;
        if ($vsdStDate) {
            $vsdStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE DATE(' . vs_q($vsdStDate) . ') = CURDATE()');
            $vsdStmt->execute();
            $vsdSignupsToday = (int)$vsdStmt->fetchColumn();
        }
    } catch (Throwable $e) {}
}

if (isset($_GET['download'])) {
    require __DIR__ . '/dashboard-export.php';
}


include "header.php";
$vsDashboardPremiumUser = trim((string)($_SESSION['username'] ?? $_SESSION['AdminSession'] ?? ''));
echo vs_premium_popup_html(
    $vsDashboardPremiumUser,
    vs_premium_popup_eligible($vsDashboardPremiumUser, true),
    '../premium.php'
);
require __DIR__ . '/dashboard-overview-data.php';
?>
      <link rel="stylesheet" href="./assets/css/vs-neon.css?v=4">
      <link rel="stylesheet" href="./assets/css/vs-dashboard.css?v=7">
      <link rel="stylesheet" href="./assets/css/vs-dashboard-theme.css?v=1">
      <style>
/* ===== Dashboard theme tokens ===== */
html:not([data-bs-theme="light"]):not([data-theme="light"]){
  --nx-cyan:#ff2a32;--nx-pink:#ff2a32;--nx-violet:#b50d16;--nx-green:#22e58a;--nx-amber:#ffd23f;--nx-red:#ff4d6d;
  --nx-cyan-rgb:255,42,50;--nx-pink-rgb:255,42,50;--nx-violet-rgb:181,13,22;--nx-green-rgb:34,229,138;--nx-amber-rgb:255,210,63;--nx-red-rgb:255,42,50;
  --nx-cyan-ink:#ff2a32;--nx-pink-ink:#ff2a32;--nx-muted:#aaaab4;
  --nx-text:#f5f5f7;--nx-card:#16161a;--nx-solid:#16161a;--nx-field:#202027;
  --nx-track:#0f0f0f;--nx-active:#fff;
  --nx-page:#0f0f0f;
  --vn-page:var(--nx-page);--vn-card:var(--nx-card);--vn-card2:var(--nx-field);
  --vn-line:rgba(var(--nx-violet-rgb),.4);--vn-line-soft:rgba(var(--nx-violet-rgb),.24);
  --vn-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55);
  --vn-text:var(--nx-text);--vn-strong:var(--nx-text);--vn-muted:var(--nx-muted);
  --vn-cyan:var(--nx-cyan);--vn-cyan-rgb:var(--nx-cyan-rgb);--vn-pink:var(--nx-pink);--vn-pink-rgb:var(--nx-pink-rgb);--vn-violet:var(--nx-violet);--vn-violet-rgb:var(--nx-violet-rgb);
  --vn-green:var(--nx-green);--vn-green-rgb:var(--nx-green-rgb);--vn-accent:var(--nx-violet);--vn-accent2:var(--nx-cyan);
  --vn-iconbox:rgba(var(--nx-violet-rgb),.14);--vn-backdrop:none;
  --vn-btn-bg:rgba(var(--nx-violet-rgb),.12);--vn-btn-hover:rgba(var(--nx-cyan-rgb),.12);--vn-btn-text:var(--nx-text);
  --vn-row-hover:rgba(var(--nx-cyan-rgb),.07);--vn-thead-bg:rgba(var(--nx-violet-rgb),.12);
}
html[data-bs-theme="light"],html[data-theme="light"]{
  --nx-cyan:#ff2a32;--nx-pink:#ff2a32;--nx-violet:#b50d16;--nx-green:#0fb86a;--nx-amber:#f59e0b;--nx-red:#e11d63;
  --nx-cyan-rgb:255,42,50;--nx-pink-rgb:255,42,50;--nx-violet-rgb:181,13,22;--nx-green-rgb:15,184,106;--nx-amber-rgb:245,158,11;--nx-red-rgb:223,23,31;
  --nx-cyan-ink:#d80e18;--nx-pink-ink:#d80e18;--nx-muted:#666671;
  --nx-text:#17171c;--nx-card:#ffffff;--nx-solid:#ffffff;--nx-field:#ffffff;
  --nx-track:#f5f6fa;--nx-active:#17171c;
  --nx-page:#f5f6fa;
  --vn-page:var(--nx-page);--vn-card:var(--nx-card);--vn-card2:var(--nx-field);
  --vn-line:rgba(var(--nx-violet-rgb),.28);--vn-line-soft:rgba(var(--nx-violet-rgb),.16);
  --vn-shadow:0 0 20px -12px rgba(var(--nx-violet-rgb),.3);
  --vn-text:var(--nx-text);--vn-strong:var(--nx-text);--vn-muted:var(--nx-muted);
  --vn-cyan:var(--nx-cyan);--vn-cyan-rgb:var(--nx-cyan-rgb);--vn-pink:var(--nx-pink);--vn-pink-rgb:var(--nx-pink-rgb);--vn-violet:var(--nx-violet);--vn-violet-rgb:var(--nx-violet-rgb);
  --vn-green:var(--nx-green);--vn-green-rgb:var(--nx-green-rgb);--vn-accent:var(--nx-violet);--vn-accent2:var(--nx-cyan);
  --vn-iconbox:rgba(var(--nx-violet-rgb),.1);--vn-backdrop:none;
  --vn-btn-bg:rgba(var(--nx-violet-rgb),.08);--vn-btn-hover:rgba(var(--nx-cyan-rgb),.1);--vn-btn-text:var(--nx-text);
  --vn-row-hover:rgba(var(--nx-cyan-rgb),.06);--vn-thead-bg:rgba(var(--nx-violet-rgb),.08);
}
html body{background:var(--nx-page);background-attachment:fixed}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:var(--nx-track)}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--nx-violet),var(--nx-pink));border-radius:8px}
html body ::selection{background:rgba(var(--nx-pink-rgb),.4);color:var(--nx-text)}

/* dashboard (index) */
html body #vsdPage .vsd-card{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55);transition:box-shadow .18s ease,border-color .18s ease}
html body #vsdPage .vsd-card.vsd-hello{background:transparent!important;border:0!important;box-shadow:none!important}
html body #vsdPage .vsd-card:hover{border-color:var(--nx-cyan);box-shadow:0 0 26px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body #vsdPage .vsd-hello h1{text-shadow:0 0 18px rgba(var(--nx-violet-rgb),.7)}
html body #vsdPage #vsHelloExtra{margin:4px 0 0;color:var(--nx-muted);font-size:.84rem}
html body #vsdPage #vsHelloMotiv{margin:6px 0 0;font-size:.82rem;color:var(--nx-green)}
html body #vsdPage .vsd-today-badge{display:inline-flex;align-items:center;gap:4px;margin-top:5px;padding:2px 9px;border-radius:999px;background:rgba(var(--nx-green-rgb),.15);color:var(--nx-green);font-size:.72rem;font-weight:700}
html body #vsdPage .vsd-sparkline{display:block;margin-top:6px;width:100%;height:22px;overflow:visible}
#vsKbModal{display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.55);align-items:center;justify-content:center}
#vsKbModal.open{display:flex}
#vsKbModal .vsKbBox{background:var(--nx-card);border:1px solid rgba(var(--nx-cyan-rgb),.5);border-radius:16px;padding:24px 28px;min-width:280px;max-width:380px;box-shadow:0 0 40px -6px rgba(var(--nx-cyan-rgb),.5)}
#vsKbModal h3{margin:0 0 14px;font-size:1rem;color:var(--nx-text)}
#vsKbModal table{width:100%;border-collapse:collapse;font-size:.84rem}
#vsKbModal td{padding:5px 8px;color:var(--nx-text)}
#vsKbModal td:first-child{font-family:monospace;background:rgba(var(--nx-violet-rgb),.18);border-radius:6px;text-align:center;width:36px;color:var(--nx-cyan-ink);font-weight:700}
#vsKbModal .vsKbClose{margin-top:16px;width:100%;padding:7px;border:1px solid rgba(var(--nx-cyan-rgb),.4);border-radius:8px;background:rgba(var(--nx-cyan-rgb),.08);color:var(--nx-text);cursor:pointer;font-size:.84rem}
#vsKbHint{position:fixed;bottom:20px;right:20px;z-index:999;width:34px;height:34px;border-radius:50%;background:rgba(var(--nx-violet-rgb),.18);border:1px solid rgba(var(--nx-cyan-rgb),.4);color:var(--nx-cyan-ink);font-size:.85rem;font-weight:700;display:grid;place-items:center;cursor:pointer;box-shadow:0 0 14px -3px rgba(var(--nx-cyan-rgb),.4)}
html body #vsdPage .vsd-head h2,html body #vsdPage .vsd-head h3{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body #vsdPage .vsd-btn{background:rgba(var(--nx-violet-rgb),.16);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55);transition:box-shadow .18s ease,border-color .18s ease,background .18s ease}
html body #vsdPage .vsd-btn:hover,html body #vsdPage .vsd-btn:focus{background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-text);border-color:var(--nx-cyan);box-shadow:0 0 16px rgba(var(--nx-cyan-rgb),.55)}
html body #vsdPage .vsd-stat-val{text-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.45)}
html body #vsdPage .vsd-hico{border:1px solid rgba(var(--nx-violet-rgb),.5);box-shadow:0 0 12px -2px rgba(var(--nx-violet-rgb),.7)}
html body #vsdPage .vsd-fi{box-shadow:0 0 10px -2px currentColor}
html body #vsdPage .vsd-dot{background:var(--nx-green);box-shadow:0 0 10px var(--nx-green),0 0 18px rgba(var(--nx-green-rgb),.7)}
html body #vsdPage .vsd-ring{filter:drop-shadow(0 0 6px rgba(var(--nx-cyan-rgb),.55))}
html body #vsdPage .vsd-chart{filter:drop-shadow(0 0 6px rgba(var(--nx-violet-rgb),.45))}
html body #vsdPage .vsd-legend i{box-shadow:0 0 8px rgba(var(--nx-cyan-rgb),.7)}
html body #vsdPage .vsd-rank{box-shadow:0 0 10px -2px rgba(var(--nx-pink-rgb),.6)}
html body #vsdPage .vsd-thumb{box-shadow:0 0 12px -3px rgba(var(--nx-violet-rgb),.7)}
html body #vsdPage .vsd-share,html body #vsdPage .vsd-viewall{color:var(--nx-cyan-ink);text-shadow:0 0 10px rgba(var(--nx-cyan-rgb),.5)}
html body #vsdPage .vsd-badge{border:1px solid rgba(var(--nx-violet-rgb),.5);box-shadow:0 0 10px -3px rgba(var(--nx-violet-rgb),.6)}
html body #vsdPage .vsd-tbl th{color:var(--nx-cyan-ink);opacity:.95}
html body #vsdPage .vsd-tbl th,html body #vsdPage .vsd-tbl td{border-bottom:1px solid rgba(var(--nx-violet-rgb),.28)}
html body #vsdPage .vsd-tbl tbody tr{transition:background .15s ease,box-shadow .15s ease}
html body #vsdPage .vsd-tbl tbody tr:hover{background:rgba(var(--nx-cyan-rgb),.07);box-shadow:inset 3px 0 0 var(--nx-cyan)}
html body #vsdPage .vsd-empty{border:1px dashed rgba(var(--nx-cyan-rgb),.5)}
html body #vsdPage .dropdown-menu{background:var(--nx-solid);border:1px solid rgba(var(--nx-cyan-rgb),.5);box-shadow:0 0 24px -4px rgba(var(--nx-cyan-rgb),.5)}
html body #vsdPage .dropdown-item{color:var(--nx-text)}
html body #vsdPage .dropdown-item:hover,html body #vsdPage .dropdown-item:focus{background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-text)}
html body #vsdPage .dropdown-divider{border-top-color:rgba(var(--nx-violet-rgb),.35)}
#vsdPage .vsd-plwrap{position:relative}
#vsdPage .vsd-pltip{position:absolute;top:12px;left:0;pointer-events:none;z-index:5;min-width:170px;padding:10px 12px;border-radius:12px;font-size:.8rem;line-height:1.5;background:rgba(10,13,31,.96);color:#eaf0ff;border:1px solid rgba(var(--nx-cyan-rgb),.55);box-shadow:0 0 24px -4px rgba(var(--nx-cyan-rgb),.55);opacity:0;transform:translateY(4px);transition:opacity .12s,transform .12s}
#vsdPage .vsd-pltip.show{opacity:1;transform:none}
#vsdPage .vsd-pltip b{display:block;margin-bottom:4px;font-size:.82rem}
#vsdPage .vsd-pltip .r2{display:flex;justify-content:space-between;gap:14px}
#vsdPage .vsd-pltip .sw{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px}
#vsdPage #vsdGrowth svg{touch-action:pan-y}
#vsdPage .vsd-command{padding:18px}
#vsdPage .vsd-command-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px}
#vsdPage .vsd-command-head h2{margin:0;font-size:1.12rem}
#vsdPage .vsd-command-head p{margin:4px 0 0;color:var(--vsd-muted);font-size:.84rem}
#vsdPage .vsd-command-count{flex:0 0 auto;padding:5px 10px;border-radius:999px;background:rgba(var(--nx-cyan-rgb),.1);color:var(--nx-cyan-ink);font-size:.78rem;font-weight:700}
#vsdPage .vsd-command-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
#vsdPage .vsd-command-tile{display:flex;align-items:center;gap:12px;min-width:0;padding:13px;border:1px solid rgba(var(--nx-violet-rgb),.3);border-radius:12px;background:rgba(var(--nx-violet-rgb),.06);color:var(--nx-text);text-decoration:none}
#vsdPage .vsd-command-tile:hover{border-color:var(--nx-cyan);background:rgba(var(--nx-cyan-rgb),.07);color:var(--nx-text)}
#vsdPage .vsd-command-tile i{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:11px;background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-cyan-ink);font-size:1.05rem}
#vsdPage .vsd-command-tile strong,#vsdPage .vsd-command-tile small{display:block}
#vsdPage .vsd-command-tile strong{font-size:.88rem}
#vsdPage .vsd-command-tile small{margin-top:2px;color:var(--vsd-muted);font-size:.76rem}
#vsdPage .vsd-command-lower{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(220px,1fr);gap:14px;margin-top:16px}
#vsdPage .vsd-command-section h3{margin:0 0 8px;font-size:.88rem}
#vsdPage .vsd-command-list{display:grid;gap:7px;margin:0;padding:0;list-style:none}
#vsdPage .vsd-command-list li{display:flex;justify-content:space-between;gap:12px;padding:8px 10px;border-radius:9px;background:rgba(var(--nx-violet-rgb),.07);font-size:.82rem}
#vsdPage .vsd-command-list li span{min-width:0}
#vsdPage .vsd-command-list small{display:block;margin-top:2px;color:var(--vsd-muted);font-size:.74rem}
#vsdPage .vsd-command-empty{padding:10px;border:1px dashed rgba(var(--nx-violet-rgb),.35);border-radius:9px;color:var(--vsd-muted);font-size:.82rem}
#vsdPage .vsd-admin-alerts{padding:16px}
#vsdPage .vsd-admin-alerts .vsd-head{margin-bottom:12px}
#vsdPage .vsd-admin-alert-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}
#vsdPage .vsd-admin-alert{display:flex;align-items:center;gap:11px;min-width:0;padding:12px;border:1px solid rgba(var(--nx-violet-rgb),.3);border-radius:12px;background:rgba(var(--nx-violet-rgb),.06);color:var(--nx-text);text-decoration:none}
#vsdPage .vsd-admin-alert:hover{border-color:var(--nx-cyan);background:rgba(var(--nx-cyan-rgb),.07);color:var(--nx-text)}
#vsdPage .vsd-admin-alert-icon{display:grid;place-items:center;flex:0 0 36px;width:36px;height:36px;border-radius:11px;background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-cyan-ink);font-size:1.05rem}
#vsdPage .vsd-admin-alert[data-tone="warning"] .vsd-admin-alert-icon{background:rgba(var(--nx-amber-rgb),.14);color:var(--nx-amber)}
#vsdPage .vsd-admin-alert-copy{flex:1;min-width:0}
#vsdPage .vsd-admin-alert-copy strong,#vsdPage .vsd-admin-alert-copy small{display:block}
#vsdPage .vsd-admin-alert-copy strong{font-size:.88rem}
#vsdPage .vsd-admin-alert-copy small{margin-top:2px;color:var(--vsd-muted);font-size:.76rem}
#vsdPage .vsd-admin-alert-value{flex:0 0 auto;font-size:.95rem;font-weight:700;color:var(--nx-cyan-ink)}
#vsdPage .vsd-admin-alert[data-tone="warning"] .vsd-admin-alert-value{color:var(--nx-amber)}
@media(max-width:640px){#vsdPage .vsd-command-lower{grid-template-columns:1fr}#vsdPage .vsd-command-head{align-items:center}}
@media (prefers-reduced-motion:reduce){html body #vsdPage .vsd-card,html body #vsdPage .vsd-btn,html body #vsdPage .vsd-tbl tbody tr{transition:none}}
      </style>

<main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-3" id="vsdPage">

          <!-- Greeting -->
          <section class="vsd-card vsd-hello" aria-label="Welcome">
            <span class="vsd-wave" aria-hidden="true"></span>
            <div>
              <h1><span id="vsHelloText"><?php echo vs_e($vsdGreet); ?></span>, <?php echo vs_e($vsdName); ?><span id="vsHelloMark"><?php echo $vsdGreetingQuestion ? '?' : ''; ?></span> <i class="bi bi-crown-fill" aria-hidden="true"></i></h1>
              <p id="vsHelloMsg"><?php echo vs_e($vsdGreetingMessage); ?></p>
              <p id="vsHelloExtra"></p>
              <span id="vsdGreet" hidden></span>
            </div>
            <div class="vsd-hello-right">
              <div class="vsd-date">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <div><div id="vsdDate"><?php echo vs_e(date('M j, Y')); ?></div><div id="vsdTime"><?php echo vs_e(date('g:i A')); ?></div></div>
              </div>
              <div class="dropdown">
                <button class="vsd-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download" aria-hidden="true"></i> Export</button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="?download=activity.csv">Activity (last 7 days)</a></li>
                  <li><a class="dropdown-item" href="?download=users.csv">All users</a></li>
                  <li><a class="dropdown-item" href="?download=uploads.csv">All videos and shorts</a></li>
                  <li><a class="dropdown-item" href="?download=uploaders.csv">Uploaders</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="?download=report.txt">Dashboard summary (.txt)</a></li>
                </ul>
              </div>
              <a class="vsd-btn" href="report.php"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Create Report</a>
            </div>
          </section>

          <?php
            $vsdAdminAlerts = [];
            $vsdReportCount = $dashboardOverview['reports'] ?? null;
            if (vs_admin_route_allowed('reported.php') && $vsdReportCount !== null) {
                $vsdReportCount = (int)$vsdReportCount;
                $vsdAdminAlerts[] = [
                    'href' => 'reported.php',
                    'icon' => 'bi-shield-exclamation',
                    'title' => 'Open reports',
                    'message' => $vsdReportCount > 0
                        ? number_format($vsdReportCount) . ' report' . ($vsdReportCount === 1 ? '' : 's') . ' need review'
                        : 'No reports need review',
                    'value' => number_format($vsdReportCount),
                    'tone' => $vsdReportCount > 0 ? 'warning' : 'ok',
                ];
            }
            $vsdScheduledCount = $dashboardOverview['scheduledVideos'] ?? null;
            if (vs_admin_route_allowed('videos.php') && $vsdScheduledCount !== null) {
                $vsdScheduledCount = (int)$vsdScheduledCount;
                $vsdAdminAlerts[] = [
                    'href' => 'videos.php',
                    'icon' => 'bi-calendar2-week',
                    'title' => 'Scheduled videos',
                    'message' => $vsdScheduledCount > 0
                        ? number_format($vsdScheduledCount) . ' waiting to publish'
                        : 'No upcoming videos',
                    'value' => number_format($vsdScheduledCount),
                    'tone' => 'ok',
                ];
            }
            $vsdSystemCheck = $dashboardOverview['systemCheck'] ?? null;
            if (is_array($vsdSystemCheck)) {
                $vsdAdminAlerts[] = [
                    'href' => 'system-check.php',
                    'icon' => 'bi-heart-pulse',
                    'title' => 'System check',
                    'message' => (string)($vsdSystemCheck['message'] ?? 'Status unavailable'),
                    'value' => ($vsdSystemCheck['tone'] ?? '') === 'ok' ? 'OK' : '!',
                    'tone' => ($vsdSystemCheck['tone'] ?? '') === 'ok' ? 'ok' : 'warning',
                ];
            }
          ?>
          <!-- Stat cards -->
          <?php
            $vsdFmt = static function ($n) use ($vsdShort): string {
                if ($n === null) { return 'n/a'; }
                return (int)$n >= 10000 ? $vsdShort((int)$n) : number_format((int)$n);
            };
            $vsdCards = [
                ['label' => $cardUsers !== null ? 'Total Users' : 'Total Uploaders', 'val' => $cardPeopleValue, 'tone' => 't-purple', 'icon' => 'bi-people-fill', 'trend' => $cardUsers !== null ? $vsdTrend($trend['users']) : null],
                ['label' => 'Total Videos',   'val' => (int)$uploaderStats['totals']['videos'], 'tone' => 't-red',    'icon' => 'bi-play-fill',             'trend' => $vsdTrend($trend['videos'])],
                ['label' => 'Total Shorts',   'val' => (int)$uploaderStats['totals']['shorts'], 'tone' => 't-green',  'icon' => 'bi-lightning-charge-fill', 'trend' => $vsdTrend($trend['shorts'])],
                ['label' => 'Total Views',    'val' => (int)$uploaderStats['totals']['views'],  'tone' => 't-violet', 'icon' => 'bi-eye-fill',              'trend' => null],
                ['label' => 'Total Comments', 'val' => $vsdComments,                            'tone' => 't-blue',   'icon' => 'bi-chat-dots-fill',        'trend' => $vsdCommentsTrend !== null ? $vsdTrend($vsdCommentsTrend) : null],
            ];
          ?>
          <section class="vsd-stats mt-3" aria-label="Dashboard metrics">
            <?php foreach ($vsdCards as $vc2): ?>
              <article class="vsd-card vsd-stat">
                <span class="vsd-ico <?php echo $vc2['tone']; ?>"><i class="bi <?php echo $vc2['icon']; ?>" aria-hidden="true"></i></span>
                <div class="vsd-stat-label"><?php echo vs_e($vc2['label']); ?></div>
                <div class="vsd-stat-val"><?php echo vs_e($vsdFmt($vc2['val'])); ?></div>
                <?php if ($vc2['trend'] !== null): ?>
                  <div class="vsd-trend <?php echo $vc2['trend']['cls']; ?>"><i class="bi <?php echo $vc2['trend']['icon']; ?>" aria-hidden="true"></i> <?php echo vs_e($vc2['trend']['text']); ?></div>
                  <small>vs. last 7 days</small>
                <?php else: ?>
                  <div class="vsd-trend flat">all time</div>
                  <small>total so far</small>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </section>

          <div class="vsd-grid mt-3">
            <!-- LEFT column -->
            <div class="vsd-main">
              <?php if ($vsdAdminAlerts): ?>
                <section class="vsd-card vsd-admin-alerts" aria-label="Admin alerts">
                  <div class="vsd-head">
                    <span class="vsd-hico plain"><i class="bi bi-bell-fill" aria-hidden="true"></i></span>
                    <div>
                      <h2>Admin Alerts</h2>
                      <p class="vsd-sub">Queues and system checks that may need attention</p>
                    </div>
                  </div>
                  <div class="vsd-admin-alert-grid">
                    <?php foreach ($vsdAdminAlerts as $alert): ?>
                      <a class="vsd-admin-alert" data-tone="<?php echo vs_e($alert['tone']); ?>" href="<?php echo vs_e($alert['href']); ?>">
                        <span class="vsd-admin-alert-icon" aria-hidden="true"><i class="bi <?php echo vs_e($alert['icon']); ?>"></i></span>
                        <span class="vsd-admin-alert-copy">
                          <strong><?php echo vs_e($alert['title']); ?></strong>
                          <small><?php echo vs_e($alert['message']); ?></small>
                        </span>
                        <span class="vsd-admin-alert-value"><?php echo vs_e($alert['value']); ?></span>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endif; ?>

              <div class="vsd-mid">
                <!-- Platform growth -->
                <section class="vsd-card" aria-label="Platform growth">
                  <div class="vsd-head">
                    <span class="vsd-hico"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
                    <div>
                      <h2>Platform Growth</h2>
                      <p class="vsd-sub">Users, uploads and views over the last 7 days</p>
                    </div>
                    <div class="vsd-legend">
                      <span><i style="background:#df171f"></i>Users</span>
                      <span><i style="background:#b50d16"></i>Uploads</span>
                      <span title="Views are counted on the day the video or short was uploaded"><i style="background:#ff6b73"></i>Views</span>
                    </div>
                  </div>
                  <div class="vsd-plwrap" id="vsdGrWrap">
                    <div class="vsd-chart" id="vsdGrowth"><?php echo $vsdChartHtml; ?></div>
                    <div class="vsd-pltip" id="vsdGrTip"></div>
                  </div>
                </section>

              </div>

              <div class="vsd-lower">
                <!-- Live online -->
                <section class="vsd-card vsd-online" aria-label="Live online">
                  <div class="vsd-head">
                    <span class="vsd-dot" id="vsdDot"></span>
                    <h2>Live Online</h2>
                  </div>
                  <div class="vsd-ring">
                    <svg viewBox="0 0 168 168" aria-hidden="true">
                      <defs>
                        <linearGradient id="vsdRingG" x1="0" y1="0" x2="1" y2="1">
                          <stop offset="0%" stop-color="#df171f"/>
                          <stop offset="50%" stop-color="#b50d16"/>
                          <stop offset="100%" stop-color="#ff6b73"/>
                        </linearGradient>
                      </defs>
                      <circle cx="84" cy="84" r="74" fill="none" stroke="rgba(128,128,128,.15)" stroke-width="10"/>
                      <circle cx="84" cy="84" r="74" fill="none" stroke="url(#vsdRingG)" stroke-width="10" stroke-linecap="round" stroke-dasharray="420 46"/>
                    </svg>
                    <div class="vsd-ring-c">
                      <i class="bi bi-people-fill" aria-hidden="true"></i>
                      <strong id="vsdOnline"><?php echo $cardOnline !== null ? number_format($cardOnline) : '-'; ?></strong>
                      <span>online now</span>
                    </div>
                  </div>
                  <div class="vsd-updated">Last updated: <span id="vsdUpdated"><?php echo vs_e(date('g:i A')); ?></span></div>
                </section>
              <div class="vsd-two">
                <?php if (isset($_GET['debug'])): ?>
                  <pre style="white-space:pre-wrap;font-size:.72rem;color:#cfd9ff;background:rgba(0,0,0,.35);padding:8px;border-radius:8px"><?php echo vs_e(print_r(['tables' => $vsdDebug, 'rows' => array_map(static function ($t) { return ['title' => $t['title'], 'type' => $t['type'], 'thumb_in_db' => $t['thumb_raw'], 'thumb_found' => $t['thumb'], 'video_in_db' => $t['video_raw'], 'video_found' => $t['video'], 'link' => $t['link']]; }, $vsdTop)], true)); ?></pre>
                <?php endif; ?>
                <?php foreach ([
                    'Videos' => ['items' => $vsdVideos, 'href' => 'videos.php', 'icon' => 'bi-play-btn-fill'],
                    'Shorts' => ['items' => $vsdShorts, 'href' => 'shorts.php', 'icon' => 'bi-lightning-charge-fill'],
                ] as $sectionTitle => $section): ?>
                  <section class="vsd-card" aria-label="Top <?php echo vs_e(strtolower($sectionTitle)); ?>">
                    <div class="vsd-head">
                      <span class="vsd-hico plain"><i class="bi <?php echo vs_e($section['icon']); ?>" aria-hidden="true"></i></span>
                      <h2>Top <?php echo vs_e($sectionTitle); ?></h2>
                      <a class="vsd-viewall" href="<?php echo vs_e($section['href']); ?>">View all</a>
                    </div>
                    <?php if (!$section['items']): ?>
                      <div class="vsd-empty">No <?php echo vs_e(strtolower($sectionTitle)); ?> with view counts yet.</div>
                    <?php else: ?>
                    <ol class="vsd-top">
                      <?php foreach (array_slice($section['items'], 0, 3) as $ti => $tp):
                          $share = $vsdTotalViews > 0 ? round($tp['views'] / $vsdTotalViews * 100, 1) : 0;
                      ?>
                        <li>
                          <span class="vsd-rank"><?php echo $ti + 1; ?></span>
                          <a class="vsd-thumb" <?php echo $tp['link'] !== '' ? 'href="' . vs_e($tp['link']) . '" target="_blank" rel="noopener"' : ''; ?> aria-label="Open <?php echo vs_e($tp['title']); ?>">
                            <?php if ($tp['video'] !== ''): ?>
                              <video data-video-preview-src="<?php echo vs_e($tp['video']); ?>" poster="<?php echo vs_e($tp['thumb'] !== '' ? $tp['thumb'] : '../assets/branding/vibestream-logo-mark.png'); ?>" preload="none" muted playsinline tabindex="-1" aria-hidden="true"></video>
                            <?php endif; ?>
                            <?php if ($tp['thumb'] !== ''): ?>
                              <img src="<?php echo vs_e($tp['thumb']); ?>" alt="" loading="lazy" onerror="this.remove()">
                            <?php endif; ?>
                            <i class="bi bi-play-fill vsd-play" aria-hidden="true"></i>
                          </a>
                          <span class="vsd-tx">
                            <strong><?php if ($tp['link'] !== ''): ?><a href="<?php echo vs_e($tp['link']); ?>" target="_blank" rel="noopener" title="<?php echo vs_e($tp['title']); ?>"><?php echo vs_e($tp['title']); ?></a><?php else: echo vs_e($tp['title']); endif; ?></strong>
                            <small><?php echo vs_e($tp['type']); ?> &bull; <?php echo vs_e($vsdShort($tp['views'])); ?> views<?php echo $tp['likes'] !== null ? ' &bull; ' . vs_e($vsdShort($tp['likes'])) . ' likes' : ''; ?></small>
                          </span>
                          <span class="vsd-share" title="Share of all views"><?php echo $share; ?>%</span>
                        </li>
                      <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>
                  </section>
                <?php endforeach; ?>
              </div>

              <div class="vsd-two">
                <!-- Top admins -->
                <section class="vsd-card" aria-label="Top admins">
                  <div class="vsd-head">
                    <span class="vsd-hico plain"><i class="bi bi-award-fill" aria-hidden="true"></i></span>
                    <h2>Top Admins</h2>
                    <a class="vsd-viewall" href="Users.php">View all</a>
                  </div>
                  <?php if (!$vsdAdmins): ?>
                    <div class="vsd-empty">No admin uploads found yet.</div>
                  <?php else: ?>
                    <ol class="vsd-top vsd-people">
                      <?php foreach (array_slice($vsdAdmins, 0, 3) as $ai => $ad): ?>
                        <li>
                          <span class="vsd-rank"><?php echo $ai + 1; ?></span>
                          <?php echo $vsdAvatar((string)$ad['name'], 30); ?>
                          <span class="vsd-tx"><strong><?php echo vs_e($ad['name']); ?></strong><small><?php echo (int)$ad['videos']; ?> videos · <?php echo (int)$ad['shorts']; ?> shorts</small></span>
                          <span class="vsd-person-value"><?php echo vs_e($vsdShort((int)$ad['views'])); ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ol>
                  <?php endif; ?>
                </section>

                <!-- Top creators -->
                <section class="vsd-card" aria-label="Top creators">
                  <div class="vsd-head">
                    <span class="vsd-hico plain"><i class="bi bi-trophy-fill" aria-hidden="true"></i></span>
                    <h2>Top Creators</h2>
                    <a class="vsd-viewall" href="top-creators.php">View all</a>
                  </div>
                  <?php if (!$vsdCreators): ?>
                    <div class="vsd-empty">No creator uploads found yet.</div>
                  <?php else: ?>
                    <ol class="vsd-top vsd-people">
                      <?php foreach (array_slice($vsdCreators, 0, 3) as $ci => $creator): ?>
                        <li>
                          <span class="vsd-rank"><?php echo $ci + 1; ?></span>
                          <?php echo $vsdAvatar((string)$creator['name'], 30); ?>
                          <span class="vsd-tx"><strong><a href="../profile.php?u=<?php echo rawurlencode((string)$creator['name']); ?>"><?php echo vs_e($creator['name']); ?></a></strong><small><?php echo number_format((int)$creator['total']); ?> uploads</small></span>
                          <span class="vsd-person-value"><?php echo vs_e($vsdShort((int)$creator['views'])); ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ol>
                  <?php endif; ?>
                </section>
              </div>
              </div>
            </div>

            <!-- RIGHT column -->
            <aside class="vsd-side">
              <!-- Live activity feed -->
              <section class="vsd-card" aria-label="Live activity feed">
                <div class="vsd-head">
                  <span class="vsd-hico plain"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
                  <h2>Live Activity Feed</h2>
                  <span class="vsd-live-badge"><i aria-hidden="true"></i>Live</span>
                </div>
                <ul class="vsd-feed" id="vsdFeed">
                  <?php if (!$feed): ?>
                    <li class="vsd-empty" style="display:block">Nothing has happened yet.</li>
                  <?php else: foreach (array_slice($feed, 0, 5) as $fi):
                      $fk = $vsdFeedKinds[$fi['icon']] ?? 'user';
                      $ft = (string)$fi['title']; $fs = (string)$fi['sub'];
                      $fp = strpos($ft, ': ');
                      if ($fp !== false && $fp > 0) { $fs = substr($ft, $fp + 2) . ($fs !== '' ? ' · ' . $fs : ''); $ft = substr($ft, 0, $fp); }
                  ?>
                    <li>
                      <span class="vsd-fi t-<?php echo $vsdFeedTones[$fk]; ?>"><i class="bi <?php echo $vsdFeedIcons[$fk]; ?>" aria-hidden="true"></i></span>
                      <span class="vsd-tx"><strong><?php echo vs_e($ft); ?></strong><small><?php echo vs_e($fs); ?></small></span>
                      <span class="vsd-time" data-ts="<?php echo (int)strtotime((string)$fi['date']); ?>"><?php echo vs_e(vs_ago((string)$fi['date'])); ?></span>
                    </li>
                  <?php endforeach; endif; ?>
                </ul>
              </section>

              <!-- Recent activity -->
              <section class="vsd-card" aria-label="Recent activity">
                <div class="vsd-head">
                  <span class="vsd-hico plain"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
                  <h2>Recent Activity</h2>
                </div>
                <?php if (!$vsdRecent): ?>
                  <div class="vsd-empty">No recent activity yet.</div>
                <?php else: ?>
                  <ul class="vsd-feed">
                    <?php foreach ($vsdRecent as $ra): ?>
                      <li>
                        <?php $activityAvatar = trim((string)($ra['avatar'] ?? '')); ?>
                        <span class="vsd-fi t-<?php echo $ra['tone'] === 'blue' ? 'blue' : $ra['tone']; ?><?php echo $activityAvatar !== '' ? ' has-avatar' : ''; ?>">
                          <?php if ($activityAvatar !== ''): ?>
                            <?php $activityInitial = mb_strtoupper(mb_substr(trim((string)($ra['sub'] ?? 'A')), 1, 1)); ?>
                            <span class="vsd-avatar-fallback" aria-hidden="true"><?php echo vs_e($activityInitial); ?></span>
                            <img src="<?php echo vs_e($activityAvatar); ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                          <?php else: ?>
                            <i class="bi <?php echo $ra['icon']; ?>" aria-hidden="true"></i>
                          <?php endif; ?>
                        </span>
                        <span class="vsd-tx"><strong><?php echo vs_e($ra['title']); ?></strong><small><?php echo vs_e($ra['sub']); ?></small></span>
                        <span class="vsd-time"><?php echo vs_e(date('Y-m-d', $ra['ts']) === date('Y-m-d') ? date('g:i A', $ra['ts']) : date('M j', $ra['ts'])); ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </section>

              <!-- System status -->
              <section class="vsd-card" aria-label="System status">
                <div class="vsd-head">
                  <span class="vsd-hico plain"><i class="bi bi-shield-fill-check" aria-hidden="true"></i></span>
                  <h2>System Status</h2>
                </div>
                <ul class="vsd-feed vsd-sys">
                  <?php foreach ($vsdStatus as $ss): ?>
                    <li>
                      <span class="vsd-sico s-<?php echo $ss['tone']; ?>"><i class="bi <?php echo $ss['tone'] === 'ok' ? 'bi-check-lg' : ($ss['tone'] === 'off' ? 'bi-dash-lg' : 'bi-exclamation-lg'); ?>" aria-hidden="true"></i></span>
                      <span class="vsd-tx"><strong><?php echo vs_e($ss['name']); ?></strong><small class="<?php echo $ss['tone']; ?>"><?php echo vs_e($ss['text']); ?></small></span>
                      <span class="vsd-badge"><?php echo vs_e($ss['badge']); ?></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </section>
            </aside>
          </div>
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
  <script src="../video-thumbnails.js?v=<?php echo (int)@filemtime(__DIR__ . '/../video-thumbnails.js'); ?>" defer></script>
  <script src="./assets/js/vs-dashboard.js?v=3"></script>
  <script>
  (function () {
    var G = <?php echo json_encode($vsdG, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    var wrap = document.getElementById('vsdGrWrap'), tip = document.getElementById('vsdGrTip');
    var svg = wrap ? wrap.querySelector('svg') : null;
    if (!svg || !G.labels || G.labels.length < 2) { return; }
    var W = 640, L = 46, R = 14, n = G.labels.length;
    var band = document.getElementById('vsdGrBand'), dots = svg.querySelectorAll('.gpc');
    var S = [['users', 'Users', '#df171f'], ['uploads', 'Uploads', '#b50d16'], ['views', 'Views', '#ff6b73']];
    function xAt(i) { return L + (W - L - R) * i / (n - 1); }
    function pct(k, i) { var b = Math.max(1, +G[k][0]); return (+G[k][i] - +G[k][0]) / b * 100; }
    function fmtPct(v) { var r = Math.round(v * 10) / 10; return (r > 0 ? '+' : '') + r + '%'; }
    function show(ev) {
      var r = svg.getBoundingClientRect();
      var x = (ev.clientX - r.left) / r.width * W;
      var i = Math.max(0, Math.min(n - 1, Math.round((x - L) / (W - L - R) * (n - 1))));
      var h = '<b>' + G.labels[i] + '</b>';
      S.forEach(function (s) {
        var v = +G[s[0]][i], gain = v - +G[s[0]][0], p = pct(s[0], i);
        h += '<div class="r2"><span><i class="sw" style="background:' + s[2] + '"></i>' + s[1] + '</span><span>' + v.toLocaleString() +
             ' <small style="opacity:.7;color:' + (p > 0 ? '#22e58a' : '#eaf0ff') + '">' + fmtPct(p) + '</small></span></div>';
      });
      h += '<div class="r2" style="opacity:.7"><span>Growth since</span><span>' + G.labels[0] + '</span></div>';
      tip.innerHTML = h;
      var wr = wrap.getBoundingClientRect(), px = r.left - wr.left + xAt(i) / W * r.width;
      var tw = tip.offsetWidth, left = px + 16;
      if (left + tw > wr.width) { left = px - tw - 16; }
      tip.style.left = Math.max(0, left) + 'px';
      tip.classList.add('show');
      if (band) { var bw = (W - L - R) / (n - 1); band.setAttribute('x', Math.max(L, xAt(i) - bw / 2)); band.setAttribute('width', bw); band.setAttribute('fill-opacity', .07); }
      Array.prototype.forEach.call(dots, function (c) { c.setAttribute('r', +c.getAttribute('data-i') === i ? 6 : 4); });
    }
    function hide() {
      tip.classList.remove('show');
      if (band) { band.setAttribute('fill-opacity', 0); }
      Array.prototype.forEach.call(dots, function (c) { c.setAttribute('r', 4); });
    }
    svg.addEventListener('pointermove', show);
    svg.addEventListener('pointerdown', show);
    svg.addEventListener('pointerleave', hide);
  })();
  </script>
  <script>
  /* Greeting that follows the time on the viewer's device */
  (function () {
    var title = document.getElementById('vsHelloText'),
        mark  = document.getElementById('vsHelloMark'),
        msg   = document.getElementById('vsHelloMsg'),
        extra = document.getElementById('vsHelloExtra');
    if (!title || !mark || !msg || !extra) { return; }

    var periods = {
      morning: {
        title: 'Good morning',
        messages: [
          "A fresh day for your platform. Here's what's happening.",
          "Rise and shine! Let's see what came in overnight.",
          "A new day, new uploads. Here's your overview."
        ],
        tips: [
          "☀️ Tip: start with open reports, then check new uploads.",
          "☕ Tip: see what users did overnight in Recent Activity.",
          "📅 Tip: check which scheduled videos are waiting to publish.",
          "🩺 Tip: run a quick system check before the day gets busy."
        ]
      },
      afternoon: {
        title: 'Good afternoon',
        messages: [
          "Hope your day is going well. Here's the latest.",
          "Halfway through the day. Let's check the numbers.",
          "Time for a quick look at how your platform is doing."
        ],
        tips: [
          "🚀 Tip: see which videos and shorts are leading.",
          "📈 Tip: look at Platform Growth to spot changes this week.",
          "💧 Tip: take a short break and drink some water.",
          "🏆 Tip: visit Top Creators to see who is doing well."
        ]
      },
      evening: {
        title: 'Good evening',
        messages: [
          "Here's what's happening with your platform today.",
          "The day is winding down. Let's see how it went.",
          "Nice work today. Here's your evening overview."
        ],
        tips: [
          "🌆 Tip: clear any pending reports before you call it a day.",
          "📊 Tip: create a report to save today's numbers.",
          "💾 Tip: export your activity list for your records.",
          "👀 Tip: take one last look at new uploads."
        ]
      },
      night: {
        title: 'Still awake',
        messages: [
          "Burning the midnight oil? Here's a quick look.",
          "It's late, but your platform never sleeps.",
          "Night shift mode. Here's what's going on."
        ],
        tips: [
          "🌙 Check the important things, then get some rest.",
          "😴 Tip: the rest can wait until morning.",
          "🔒 Tip: log out if you are on a shared computer.",
          "🛌 Tip: good sleep helps you build better features tomorrow."
        ]
      }
    };

    // One new tip per page load: remember a counter in this browser
    var load = Math.floor(Math.random() * 1000);
    try {
      var saved = parseInt(localStorage.getItem('vsTipCount'), 10);
      if (!isNaN(saved)) { load = saved + 1; }
      localStorage.setItem('vsTipCount', load);
    } catch (e) {}

    function update() {
      var h = new Date().getHours();
      var key = h >= 5 && h < 12 ? 'morning'
              : h >= 12 && h < 17 ? 'afternoon'
              : h >= 17 && h < 21 ? 'evening'
              : 'night';
      var p = periods[key];
      title.textContent = p.title;
      mark.textContent  = key === 'night' ? '?' : '';
      msg.textContent   = p.messages[h % p.messages.length];
      extra.textContent = p.tips[load % p.tips.length];
    }

    update();
    setInterval(update, 60000);
  })();
  </script>
</body>
</html>