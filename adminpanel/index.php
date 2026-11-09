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

$USERS_TABLE = 'users';

// The heavy lifting lives in separate files so this page stays short:
//   dashboard-live.php   JSON for the live "online now" + activity feed (?live=1)
//   dashboard-data.php   every number and list shown below
//   dashboard-export.php CSV / text downloads (?download=...)
if (isset($_GET['live'])) {
    require __DIR__ . '/dashboard-live.php';
}
require __DIR__ . '/dashboard-data.php';
if (isset($_GET['download'])) {
    require __DIR__ . '/dashboard-export.php';
}


include "header.php";
require __DIR__ . '/dashboard-overview-data.php';
?>
      <link rel="stylesheet" href="./assets/css/vs-neon.css?v=4">
      <link rel="stylesheet" href="./assets/css/vs-dashboard.css?v=5">
      <link rel="stylesheet" href="./assets/css/vs-dashboard-theme.css?v=1">
      <style>
/* ===== NEON THEME (dark + light) - same look as creator-analytics.php ===== */
html:not([data-bs-theme="light"]):not([data-theme="light"]){
  --nx-cyan:#00e5ff;--nx-pink:#ff2e93;--nx-violet:#8a5cff;--nx-green:#22e58a;--nx-amber:#ffd23f;--nx-red:#ff4d6d;
  --nx-cyan-rgb:0,229,255;--nx-pink-rgb:255,46,147;--nx-violet-rgb:138,92,255;--nx-green-rgb:34,229,138;--nx-amber-rgb:255,210,63;--nx-red-rgb:255,77,109;
  --nx-cyan-ink:#00e5ff;--nx-pink-ink:#ff7ac0;--nx-muted:#8f9bd0;
  --nx-text:#eaf0ff;--nx-card:rgba(10,13,31,.88);--nx-solid:#0a0d1f;--nx-field:rgba(10,13,31,.9);
  --nx-track:#05060f;--nx-active:#fff;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(138,92,255,.16),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,229,255,.10),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.08),transparent 70%),#05060f;
}
html[data-bs-theme="light"],html[data-theme="light"]{
  --nx-cyan:#00b8e6;--nx-pink:#ff2e93;--nx-violet:#7c4dff;--nx-green:#0fb86a;--nx-amber:#f59e0b;--nx-red:#e11d63;
  --nx-cyan-rgb:0,184,230;--nx-pink-rgb:255,46,147;--nx-violet-rgb:124,77,255;--nx-green-rgb:15,184,106;--nx-amber-rgb:245,158,11;--nx-red-rgb:225,29,99;
  --nx-cyan-ink:#0086b3;--nx-pink-ink:#d4147a;--nx-muted:#55608f;
  --nx-text:#0b1030;--nx-card:#ffffff;--nx-solid:#ffffff;--nx-field:#ffffff;
  --nx-track:#eceffd;--nx-active:#0b1030;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(124,77,255,.14),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,184,230,.14),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.10),transparent 70%),#f4f6ff;
}
html body{background:var(--nx-page);background-attachment:fixed}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:var(--nx-track)}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--nx-violet),var(--nx-pink));border-radius:8px}
html body ::selection{background:rgba(var(--nx-pink-rgb),.4);color:var(--nx-text)}

/* dashboard (index) */
html body #vsdPage .vsd-card{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55);transition:box-shadow .18s ease,border-color .18s ease}
html body #vsdPage .vsd-card:hover{border-color:var(--nx-cyan);box-shadow:0 0 26px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body #vsdPage .vsd-hello h1{text-shadow:0 0 18px rgba(var(--nx-violet-rgb),.7)}
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
#vsdPage .vsd-featured-creator{display:flex;align-items:center;gap:14px;margin-top:14px;padding:14px 18px;border-color:rgba(var(--nx-amber-rgb),.48);background:linear-gradient(110deg,rgba(var(--nx-amber-rgb),.1),var(--nx-card) 48%)}
#vsdPage .vsd-featured-creator .vsd-featured-trophy{display:grid;place-items:center;flex:0 0 42px;width:42px;height:42px;border-radius:12px;background:rgba(var(--nx-amber-rgb),.16);color:var(--nx-amber);font-size:1.35rem}
#vsdPage .vsd-featured-copy{display:flex;flex:1;flex-direction:column;min-width:0;gap:3px}
#vsdPage .vsd-featured-copy small{color:var(--vsd-muted)}
#vsdPage .vsd-featured-links{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
#vsdPage .vsd-featured-links a{color:var(--nx-cyan-ink);font-size:.82rem;font-weight:600}
#vsdPage .vsd-featured-links a:hover{text-decoration:underline}
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
@media(max-width:640px){#vsdPage .vsd-command-lower{grid-template-columns:1fr}#vsdPage .vsd-command-head{align-items:center}}
@media (prefers-reduced-motion:reduce){html body #vsdPage .vsd-card,html body #vsdPage .vsd-btn,html body #vsdPage .vsd-tbl tbody tr{transition:none}}
      </style>

<main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-3" id="vsdPage">

          <!-- Greeting -->
          <section class="vsd-card vsd-hello" aria-label="Welcome">
            <span class="vsd-wave" aria-hidden="true">&#128075;</span>
            <div>
              <h1><span id="vsdGreet"><?php echo vs_e($vsdGreet); ?></span>, <?php echo vs_e($vsdName); ?></h1>
              <p>Here's what's happening with your platform today.</p>
            </div>
            <div class="vsd-hello-right">
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
              <div class="vsd-date">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <div><div id="vsdDate"><?php echo vs_e(date('M j, Y')); ?></div><div id="vsdTime"><?php echo vs_e(date('g:i A')); ?></div></div>
              </div>
            </div>
          </section>

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

          <?php if (!empty($vsdCreators[0])): $featuredCreator = $vsdCreators[0]; ?>
            <section class="vsd-card vsd-featured-creator" aria-label="Top creator by views">
              <span class="vsd-featured-trophy" aria-hidden="true"><i class="bi bi-trophy-fill"></i></span>
              <?php echo $vsdAvatar((string)$featuredCreator['name'], 48); ?>
              <div class="vsd-featured-copy">
                <small>Top Creator by views</small>
                <strong><?php echo vs_e($featuredCreator['name']); ?></strong>
                <small><?php echo vs_e($vsdShort((int)$featuredCreator['views'])); ?> views &bull; <?php echo number_format((int)$featuredCreator['total']); ?> uploads</small>
              </div>
              <div class="vsd-featured-links">
                <a href="../profile.php?u=<?php echo rawurlencode((string)$featuredCreator['name']); ?>">View channel</a>
                <a href="top-creators.php">All creators</a>
              </div>
            </section>
          <?php endif; ?>

          <div class="vsd-grid mt-3">
            <!-- LEFT column -->
            <div class="vsd-main">

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
                      <span><i style="background:#a855f7"></i>Users</span>
                      <span><i style="background:#3b82f6"></i>Uploads</span>
                      <span title="Views are counted on the day the video or short was uploaded"><i style="background:#22e58a"></i>Views</span>
                    </div>
                  </div>
                  <div class="vsd-plwrap" id="vsdGrWrap">
                    <div class="vsd-chart" id="vsdGrowth"><?php echo $vsdChartHtml; ?></div>
                    <div class="vsd-pltip" id="vsdGrTip"></div>
                  </div>
                </section>

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
                          <stop offset="0%" stop-color="#22e58a"/>
                          <stop offset="50%" stop-color="#2f7bff"/>
                          <stop offset="100%" stop-color="#8b5cf6"/>
                        </linearGradient>
                      </defs>
                      <circle cx="84" cy="84" r="74" fill="none" stroke="rgba(90,125,255,.15)" stroke-width="10"/>
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
              </div>


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
                      <?php foreach ($section['items'] as $ti => $tp):
                          $share = $vsdTotalViews > 0 ? round($tp['views'] / $vsdTotalViews * 100, 1) : 0;
                      ?>
                        <li>
                          <span class="vsd-rank"><?php echo $ti + 1; ?></span>
                          <a class="vsd-thumb" <?php echo $tp['link'] !== '' ? 'href="' . vs_e($tp['link']) . '" target="_blank" rel="noopener"' : ''; ?> aria-label="Open <?php echo vs_e($tp['title']); ?>">
                            <?php if ($tp['video'] !== ''): ?>
                              <video src="<?php echo vs_e($tp['video']); ?>#t=0.5" preload="metadata" muted playsinline tabindex="-1"></video>
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
                    <div class="table-responsive">
                      <table class="vsd-tbl">
                        <thead><tr><th>#</th><th>Admin</th><th>Videos</th><th>Shorts</th><th>Views</th></tr></thead>
                        <tbody>
                          <?php foreach ($vsdAdmins as $ai => $ad): ?>
                            <tr>
                              <td><?php echo $ai + 1; ?></td>
                              <td><div class="vsd-who"><?php echo $vsdAvatar((string)$ad['name'], 34); ?><span class="n"><?php echo vs_e($ad['name']); ?></span></div></td>
                              <td><?php echo (int)$ad['videos']; ?></td>
                              <td><?php echo (int)$ad['shorts']; ?></td>
                              <td><?php echo vs_e($vsdShort((int)$ad['views'])); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </section>

                <!-- Top creators -->
                <section class="vsd-card" aria-label="Top creators">
                  <div class="vsd-head">
                    <span class="vsd-hico plain"><i class="bi bi-trophy-fill" aria-hidden="true"></i></span>
                    <h2>Top Creators</h2>
                  </div>
                  <?php if (!$vsdCreators): ?>
                    <div class="vsd-empty">No creator uploads found yet.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="vsd-tbl">
                        <thead><tr><th>#</th><th>Creator</th><th>Uploads</th><th>Views</th></tr></thead>
                        <tbody>
                          <?php foreach ($vsdCreators as $ci => $creator): ?>
                            <tr>
                              <td><?php echo $ci + 1; ?></td>
                              <td><div class="vsd-who"><?php echo $vsdAvatar((string)$creator['name'], 34); ?><span class="n"><?php echo vs_e($creator['name']); ?></span></div></td>
                              <td><?php echo (int)$creator['total']; ?></td>
                              <td><?php echo vs_e($vsdShort((int)$creator['views'])); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </section>
              </div>
            </div>

            <!-- RIGHT column -->
            <aside class="vsd-side">
              <!-- Live activity feed -->
              <section class="vsd-card" aria-label="Live activity feed">
                <div class="vsd-head">
                  <span class="vsd-hico plain"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
                  <h2>Live Activity Feed</h2>
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
                        <span class="vsd-fi t-<?php echo $ra['tone'] === 'blue' ? 'blue' : $ra['tone']; ?>"><i class="bi <?php echo $ra['icon']; ?>" aria-hidden="true"></i></span>
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
  <script src="./assets/js/vs-dashboard.js?v=3"></script>
  <script>
  (function () {
    var G = <?php echo json_encode($vsdG, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;
    var wrap = document.getElementById('vsdGrWrap'), tip = document.getElementById('vsdGrTip');
    var svg = wrap ? wrap.querySelector('svg') : null;
    if (!svg || !G.labels || G.labels.length < 2) { return; }
    var W = 640, L = 46, R = 14, n = G.labels.length;
    var band = document.getElementById('vsdGrBand'), dots = svg.querySelectorAll('.gpc');
    var S = [['users', 'Users', '#a855f7'], ['uploads', 'Uploads', '#3b82f6'], ['views', 'Views', '#22e58a']];
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
</body>
</html>