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
if (empty($_SESSION['vs_insights_csrf'])) {
    $_SESSION['vs_insights_csrf'] = bin2hex(random_bytes(32));
}
$insightsCsrf = (string)$_SESSION['vs_insights_csrf'];
include __DIR__ . '/header.php';
?>
<style>
  .vi { --g:#16a34a; --r:#dc2626; --b:#3b82f6; --v:#8b5cf6; --o:#f59e0b;
        --card:var(--bs-body-bg,#fff); --line:var(--bs-border-color,rgba(120,130,150,.25)); --muted:var(--bs-secondary-color,#6b7280); }
  .vi-card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:18px 20px; height:100%;
             box-shadow:0 1px 2px rgba(16,24,40,.04),0 6px 20px rgba(16,24,40,.05); }
  .vi-head { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
  .vi-head .ico { width:32px; height:32px; border-radius:10px; display:grid; place-items:center; font-size:.95rem; flex:none; }
  .vi-head h2 { font-size:.95rem; font-weight:600; margin:0; }
  .vi-head small { color:var(--muted); display:block; font-size:.74rem; font-weight:400; }
  .t-g { background:rgba(34,197,94,.14); color:var(--g); } .t-r { background:rgba(239,68,68,.14); color:var(--r); }
  .t-b { background:rgba(59,130,246,.14); color:var(--b); } .t-v { background:rgba(139,92,246,.14); color:var(--v); }
  .t-o { background:rgba(245,158,11,.16); color:#d97706; } .t-s { background:rgba(148,163,184,.18); color:var(--muted); }
  .vi-sec { font-size:.72rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); font-weight:600; margin:14px 0 6px; }
  .vi-sec:first-of-type { margin-top:0; }

  /* live badge */
  .vi-live { display:inline-flex; align-items:center; gap:8px; padding:6px 13px 6px 11px; border-radius:999px; font-size:.78rem; font-weight:600; letter-spacing:.03em;
             background:rgba(34,197,94,.12); color:var(--g); border:1px solid rgba(34,197,94,.3); }
  .vi-live .dot { width:8px; height:8px; border-radius:50%; background:#22c55e; position:relative; }
  .vi-live .dot::after { content:""; position:absolute; inset:0; border-radius:50%; background:#22c55e; animation:viPulse 1.6s ease-out infinite; }
  .vi-live.warn { background:rgba(245,158,11,.12); color:#d97706; border-color:rgba(245,158,11,.35); }
  .vi-live.warn .dot, .vi-live.warn .dot::after { background:#f59e0b; }
  @keyframes viPulse { 0%{transform:scale(1);opacity:.7} 100%{transform:scale(3);opacity:0} }

  /* KPI strip */
  .vi-kpi { position:relative; overflow:hidden; }
  .vi-kpi .lbl { font-size:.8rem; color:var(--muted); font-weight:500; }
  .vi-kpi .val { font-size:1.9rem; font-weight:700; letter-spacing:-.02em; line-height:1.1; margin-top:8px; font-variant-numeric:tabular-nums; }
  .vi-kpi .sub { font-size:.78rem; color:var(--muted); margin-top:4px; min-height:1.1em; }
  .vi-kpi::after { content:""; position:absolute; right:-28px; top:-28px; width:96px; height:96px; border-radius:50%; opacity:.07; background:currentColor; }
  .vi-kpi.g::after{color:var(--g)} .vi-kpi.b::after{color:var(--b)} .vi-kpi.o::after{color:var(--o)} .vi-kpi.r::after{color:var(--r)}
  .vi-flash { animation:viFlash 1.1s ease-out; }
  @keyframes viFlash { 0%{background:rgba(59,130,246,.28);border-radius:6px} 100%{background:transparent} }

  /* lists */
  .vi-list { list-style:none; margin:0; padding:0; }
  .vi-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px dashed var(--line); font-size:.86rem; min-width:0; }
  .vi-item:last-child { border-bottom:0; }
  .vi-item .main { flex:1; min-width:0; overflow-wrap:anywhere; }
  .vi-item .end { flex:none; font-variant-numeric:tabular-nums; font-weight:600; }
  .vi-empty { color:var(--muted); font-size:.85rem; padding:8px 0; }
  .vi-rank { width:24px; height:24px; border-radius:8px; display:grid; place-items:center; font-size:.75rem; font-weight:700; flex:none; background:rgba(148,163,184,.18); color:var(--muted); }
  .vi-item:nth-child(1) .vi-rank { background:linear-gradient(135deg,#fbbf24,#f59e0b); color:#fff; }
  .vi-item:nth-child(2) .vi-rank { background:linear-gradient(135deg,#cbd5e1,#94a3b8); color:#fff; }
  .vi-item:nth-child(3) .vi-rank { background:linear-gradient(135deg,#fdba74,#ea580c); color:#fff; }
  .vi-chip { font-size:.68rem; font-weight:700; padding:2px 8px; border-radius:99px; text-transform:uppercase; letter-spacing:.03em; flex:none; }
  .vi-sub { color:var(--muted); font-size:.78rem; }
  .vi-warnbox { display:flex; gap:10px; align-items:flex-start; padding:10px 12px; border-radius:12px; background:rgba(245,158,11,.12); color:#b45309; font-size:.85rem; margin-bottom:8px; }
  [data-bs-theme="dark"] .vi-warnbox { color:#fbbf24; }

  /* bars */
  .vi-bar { height:8px; border-radius:99px; background:rgba(148,163,184,.2); overflow:hidden; }
  .vi-bar > i { display:block; height:100%; border-radius:99px; width:0; transition:width .8s cubic-bezier(.22,1,.36,1); }
  .vi-ret { margin-bottom:14px; }
  .vi-ret .top { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:5px; font-size:.86rem; }
  .vi-ret .top strong { font-variant-numeric:tabular-nums; }
  .vi-goal-pct { font-size:.74rem; color:var(--muted); margin-top:3px; text-align:right; }

  /* forms */
  .vi .form-control, .vi .form-select { border-radius:10px; font-size:.88rem; }
  .vi .btn { border-radius:10px; font-size:.86rem; font-weight:500; }
  .vi-note { padding:10px 12px; border-radius:12px; background:rgba(148,163,184,.1); margin-bottom:8px; font-size:.85rem; }
  .vi-note .meta { color:var(--muted); font-size:.74rem; margin-top:3px; display:flex; flex-wrap:wrap; gap:4px 10px; align-items:center; }
  .vi-scroll { max-height:300px; overflow:auto; }

  /* people: profile picture + VIP badge */
  .vi-user { display:inline-flex; align-items:center; gap:8px; min-width:0; max-width:100%; vertical-align:middle; }
  .vi-ip-people { display:flex; flex-wrap:wrap; gap:8px 14px; }
  .vi-av { position:relative; width:30px; height:30px; border-radius:50%; flex:none; overflow:hidden; display:grid; place-items:center;
           font-size:.8rem; font-weight:700; color:#fff; background:linear-gradient(135deg,#6366f1,#3b82f6); }
  .vi-av img { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; background:var(--card); }
  .vi-uname { display:inline-flex; align-items:center; gap:6px; min-width:0; font-weight:600; }
  .vi-uname-t { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .vi-vip { flex:none; display:inline-flex; align-items:center; gap:3px; padding:1px 8px; border-radius:999px; font-size:.64rem; font-weight:800; letter-spacing:.04em;
            color:#2a1700; background:linear-gradient(135deg,#fcd34d,#f59e0b); box-shadow:0 1px 4px rgba(245,158,11,.4); }
  .vi-vip.plus { color:#fff; background:linear-gradient(135deg,#8b5cf6,#6366f1); box-shadow:0 1px 4px rgba(99,102,241,.45); }
  .vi-vip i { font-size:.7rem; }
  .vi-note .meta .vi-user { gap:6px; }
  .vi-note .meta .vi-av { width:22px; height:22px; font-size:.65rem; }

  /* cleaner cards */
  .vi-card { transition:box-shadow .2s, transform .2s; }
  .vi-card:hover { box-shadow:0 2px 4px rgba(16,24,40,.05),0 12px 28px rgba(16,24,40,.09); }
  .vi-kpi { border-top:3px solid transparent; }
  .vi-kpi.g { border-top-color:var(--g); } .vi-kpi.b { border-top-color:var(--b); }
  .vi-kpi.o { border-top-color:var(--o); } .vi-kpi.r { border-top-color:var(--r); }
  .vi-head h2 { line-height:1.25; }
  .vi-item { padding:10px 0; }
  .vi-list.vi-scroll { padding-right:4px; }

  /* status toast */
  #vsInsightsStatus { border-radius:12px; border:0; font-size:.88rem; }
  .vi-hero-icon { width:48px; height:48px; border-radius:14px; display:grid; place-items:center; font-size:1.4rem; color:#fff; background:linear-gradient(135deg,#8b5cf6,#3b82f6); box-shadow:0 8px 20px rgba(99,102,241,.3); }
  @media (prefers-reduced-motion:reduce){ .vi *, .vi *::after { animation:none !important; transition:none !important; } }

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

/* insights page */
html body .vi{--g:var(--nx-green);--r:var(--nx-red);--b:var(--nx-cyan);--v:var(--nx-violet);--o:var(--nx-amber);--card:var(--nx-card);--line:rgba(var(--nx-violet-rgb),.35);--muted:var(--nx-muted)}
html body .vi h1{text-shadow:0 0 18px rgba(var(--nx-violet-rgb),.7)}
html body .vi .vi-card{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55)}
html body .vi .vi-card:hover{border-color:var(--nx-cyan);box-shadow:0 0 26px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body .vi .vi-head h2{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body .vi .vi-sec{color:var(--nx-cyan-ink);text-shadow:0 0 8px rgba(var(--nx-cyan-rgb),.4)}
html body .vi .vi-head .ico{border:1px solid currentColor;box-shadow:0 0 12px -2px currentColor}
html body .vi .t-g{background:rgba(var(--nx-green-rgb),.14);color:var(--nx-green)}
html body .vi .t-r{background:rgba(var(--nx-red-rgb),.14);color:var(--nx-red)}
html body .vi .t-b{background:rgba(var(--nx-cyan-rgb),.14);color:var(--nx-cyan-ink)}
html body .vi .t-v{background:rgba(var(--nx-violet-rgb),.16);color:var(--nx-violet)}
html body .vi .t-o{background:rgba(var(--nx-amber-rgb),.16);color:var(--nx-amber)}
html body .vi .t-s{background:rgba(var(--nx-violet-rgb),.12);color:var(--nx-muted)}

html body .vi .vi-hero-icon{background:linear-gradient(135deg,var(--nx-violet),var(--nx-pink));box-shadow:0 0 22px rgba(var(--nx-pink-rgb),.55)}
html body .vi .vi-live{background:rgba(var(--nx-green-rgb),.12);color:var(--nx-green);border:1px solid rgba(var(--nx-green-rgb),.55);box-shadow:0 0 16px -4px rgba(var(--nx-green-rgb),.6)}
html body .vi .vi-live .dot,html body .vi .vi-live .dot::after{background:var(--nx-green)}
html body .vi .vi-live .dot{box-shadow:0 0 10px var(--nx-green)}
html body .vi .vi-live.warn{background:rgba(var(--nx-amber-rgb),.12);color:var(--nx-amber);border-color:rgba(var(--nx-amber-rgb),.6);box-shadow:0 0 16px -4px rgba(var(--nx-amber-rgb),.6)}
html body .vi .vi-live.warn .dot,html body .vi .vi-live.warn .dot::after{background:var(--nx-amber)}
html body .vi .vi-live.warn .dot{box-shadow:0 0 10px var(--nx-amber)}

html body .vi .vi-kpi.g{--kc:var(--nx-green)}
html body .vi .vi-kpi.b{--kc:var(--nx-cyan)}
html body .vi .vi-kpi.o{--kc:var(--nx-amber)}
html body .vi .vi-kpi.r{--kc:var(--nx-red)}
html body .vi .vi-kpi{border-top-color:var(--kc,var(--nx-violet))}
html body .vi .vi-kpi::before{content:"";position:absolute;left:0;right:0;top:0;height:3px;background:var(--kc,var(--nx-violet));box-shadow:0 0 16px 2px var(--kc,var(--nx-violet))}
html body .vi .vi-kpi .val{text-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.45)}
html body .vi .vi-flash{animation:none}

html body .vi .vi-rank{background:rgba(var(--nx-violet-rgb),.2);color:var(--nx-muted)}
html body .vi .vi-item:nth-child(1) .vi-rank{color:#fff;box-shadow:0 0 12px rgba(var(--nx-amber-rgb),.7)}
html body .vi .vi-item:nth-child(2) .vi-rank{color:#fff;box-shadow:0 0 12px rgba(var(--nx-cyan-rgb),.6)}
html body .vi .vi-item:nth-child(3) .vi-rank{color:#fff;box-shadow:0 0 12px rgba(var(--nx-pink-rgb),.6)}
html body .vi .vi-bar{background:rgba(var(--nx-violet-rgb),.22)}
html body .vi .vi-bar > i{box-shadow:0 0 10px rgba(var(--nx-cyan-rgb),.6)}
html body .vi .vi-note{background:rgba(var(--nx-violet-rgb),.10);border:1px solid rgba(var(--nx-violet-rgb),.3)}
html body .vi .vi-warnbox{background:rgba(var(--nx-pink-rgb),.12);border:1px solid rgba(var(--nx-pink-rgb),.55);color:var(--nx-pink-ink);box-shadow:0 0 18px -6px rgba(var(--nx-pink-rgb),.55)}

html body .vi .vi-av{background:linear-gradient(135deg,var(--nx-violet),var(--nx-pink));box-shadow:0 0 10px -2px rgba(var(--nx-pink-rgb),.6)}
html body .vi .vi-vip{box-shadow:0 0 12px rgba(var(--nx-amber-rgb),.6)}
html body .vi .vi-vip.plus{background:linear-gradient(135deg,var(--nx-violet),var(--nx-cyan));box-shadow:0 0 12px rgba(var(--nx-cyan-rgb),.6)}

html body .vi .form-control,html body .vi .form-select{background-color:var(--nx-field);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:0 0 10px -2px rgba(var(--nx-violet-rgb),.45);transition:box-shadow .2s ease,border-color .2s ease}
html body .vi .form-control::placeholder{color:var(--nx-muted);opacity:.8}
html body .vi .form-control:focus,html body .vi .form-select:focus{background-color:var(--nx-field);color:var(--nx-text);outline:none;border-color:var(--nx-cyan);box-shadow:0 0 0 1px var(--nx-cyan),0 0 20px rgba(var(--nx-cyan-rgb),.55)}
html body .vi .btn-primary{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));border:0;color:#fff;box-shadow:0 0 20px rgba(var(--nx-pink-rgb),.5);transition:box-shadow .18s ease,transform .18s ease}
html body .vi .btn-primary:hover,html body .vi .btn-primary:focus{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;transform:translateY(-1px);box-shadow:0 0 30px rgba(var(--nx-pink-rgb),.85)}
html body .vi .btn-outline-secondary{background:rgba(var(--nx-violet-rgb),.14);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55)}
html body .vi .btn-outline-secondary:hover{background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-text);border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}

html body #vsInsightsStatus{color:var(--nx-text);background:rgba(var(--nx-cyan-rgb),.10);border:1px solid rgba(var(--nx-cyan-rgb),.5);box-shadow:0 0 20px -6px rgba(var(--nx-cyan-rgb),.5)}
html body #vsInsightsStatus.alert-danger{background:rgba(var(--nx-pink-rgb),.12);border-color:rgba(var(--nx-pink-rgb),.6);box-shadow:0 0 20px -6px rgba(var(--nx-pink-rgb),.55)}
@media (prefers-reduced-motion:reduce){html body .vi .btn-primary,html body .vi .form-control,html body .vi .form-select{transition:none}html body .vi .btn-primary:hover{transform:none}}
</style>

<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-4 vi">

    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
      <div class="d-flex align-items-center gap-3">
        <span class="vi-hero-icon"><i class="bi bi-activity" aria-hidden="true"></i></span>
        <div>
          <h1 class="h3 mb-0">Live Insights</h1>
          <p class="text-muted small mb-0">Live site signals and practical community tools. Refreshes every 30 seconds.</p>
        </div>
      </div>
      <span class="vi-live" id="vsLiveBadge"><span class="dot"></span><span id="vsLiveText">LIVE</span></span>
    </div>

    <div id="vsInsightsStatus" class="alert alert-info" role="status">Loading insights...</div>

    <!-- KPI strip -->
    <section class="row g-3 mb-3" aria-label="Live views and activity">
      <div class="col-6 col-lg-3"><div class="vi-card vi-kpi g">
        <div class="lbl"><i class="bi bi-eye me-1"></i>Views per minute</div>
        <div class="val" id="vsViewsMinute">—</div>
        <div class="sub" id="vsTopVideo">Waiting for recent views</div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="vi-card vi-kpi b">
        <div class="lbl"><i class="bi bi-fire me-1"></i>Trending now</div>
        <div class="val" id="vsKpiTrend">—</div>
        <div class="sub">items with views in the last hour</div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="vi-card vi-kpi o">
        <div class="lbl"><i class="bi bi-exclamation-triangle me-1"></i>Spike warnings</div>
        <div class="val" id="vsKpiSpikes">—</div>
        <div class="sub">unusual signup / upload patterns</div>
      </div></div>
      <div class="col-6 col-lg-3"><div class="vi-card vi-kpi r">
        <div class="lbl"><i class="bi bi-bug me-1"></i>Content issues</div>
        <div class="val" id="vsKpiBroken">—</div>
        <div class="sub">possible broken uploads</div>
      </div></div>
    </section>

    <section class="row g-3 mb-3" aria-label="Trending and spikes">
      <div class="col-12 col-lg-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-b"><i class="bi bi-fire"></i></span><h2>Now trending<small>Last hour</small></h2></div>
        <ul id="vsTrending" class="vi-list"><li class="vi-empty">Loading...</li></ul>
      </div></div>
      <div class="col-12 col-lg-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-o"><i class="bi bi-lightning-charge"></i></span><h2>Spike warnings<small>Compared with normal activity</small></h2></div>
        <ul id="vsSpikes" class="vi-list"><li class="vi-empty">No unusual activity detected.</li></ul>
      </div></div>
    </section>

    <section class="row g-3 mb-3" aria-label="Creator, storage, and content health">
      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-g"><i class="bi bi-graph-up-arrow"></i></span><h2>Rising creators<small>Last 7 days</small></h2></div>
        <ul id="vsRising" class="vi-list"><li class="vi-empty">Collecting view history...</li></ul>
      </div></div>
      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-v"><i class="bi bi-hdd"></i></span><h2>Storage by creator<small>Local upload files</small></h2></div>
        <ul id="vsStorage" class="vi-list"><li class="vi-empty">Scanning local upload files...</li></ul>
      </div></div>
      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-r"><i class="bi bi-bug"></i></span><h2>Broken content check<small>Recent uploads</small></h2></div>
        <div class="fw-semibold small mb-2" id="vsBrokenCount">Checking uploads...</div>
        <ul id="vsBroken" class="vi-list vi-scroll"><li class="vi-empty">Loading...</li></ul>
      </div></div>
    </section>

    <section class="row g-3 mb-3" aria-label="Spam signals, retention, and goals">
      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-r"><i class="bi bi-shield-exclamation"></i></span><h2>Spam finder<small>Recent sample</small></h2></div>
        <div class="vi-sec">Accounts created from the same IP</div>
        <ul id="vsSpamIps" class="vi-list"><li class="vi-empty">Loading...</li></ul>
        <div class="vi-sub mt-2" id="vsIpStatus"></div>
      </div></div>

      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-b"><i class="bi bi-arrow-repeat"></i></span><h2>Retention<small>New users who came back</small></h2></div>
        <div class="vi-ret"><div class="top"><span>Day 1</span><strong id="vsRetention1">—</strong></div><div class="vi-bar"><i id="vsRetention1Bar" style="background:var(--b)"></i></div></div>
        <div class="vi-ret"><div class="top"><span>Day 7</span><strong id="vsRetention7">—</strong></div><div class="vi-bar"><i id="vsRetention7Bar" style="background:var(--v)"></i></div></div>
        <div class="vi-ret"><div class="top"><span>Day 30</span><strong id="vsRetention30">—</strong></div><div class="vi-bar"><i id="vsRetention30Bar" style="background:var(--g)"></i></div></div>
        <div class="vi-sub" id="vsRetentionNote">Retention collection starts after setup; older activity cannot be reconstructed.</div>
      </div></div>

      <div class="col-12 col-xl-4"><div class="vi-card">
        <div class="vi-head"><span class="ico t-g"><i class="bi bi-bullseye"></i></span><h2>Monthly goals<small>This month so far</small></h2></div>
        <div class="vi-ret">
          <div class="top"><span>Signups</span><strong id="vsSignupGoalText">0 / 0</strong></div>
          <div class="vi-bar" role="progressbar" aria-label="Signup goal progress"><i id="vsSignupGoalBar" style="background:var(--b)"></i></div>
          <div class="vi-goal-pct" id="vsSignupGoalPct"></div>
        </div>
        <div class="vi-ret">
          <div class="top"><span>Uploads</span><strong id="vsUploadGoalText">0 / 0</strong></div>
          <div class="vi-bar" role="progressbar" aria-label="Upload goal progress"><i id="vsUploadGoalBar" style="background:var(--g)"></i></div>
          <div class="vi-goal-pct" id="vsUploadGoalPct"></div>
        </div>
        <form class="row g-2" data-insights-form>
          <input type="hidden" name="action" value="goal">
          <div class="col-5"><select class="form-select" name="metric" aria-label="Goal metric"><option value="signups">Signups</option><option value="uploads">Uploads</option></select></div>
          <div class="col-4"><input class="form-control" type="number" name="target" min="0" max="1000000" required placeholder="Target" aria-label="Monthly target"></div>
          <div class="col-3"><button class="btn btn-primary w-100" type="submit">Set</button></div>
        </form>
      </div></div>
    </section>

    <section class="row g-3 mb-3" aria-label="Admin actions">
      <div class="col-12 col-xl-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-o"><i class="bi bi-megaphone"></i></span><h2>Site-wide announcement<small>Shown to every visitor until cleared or replaced</small></h2></div>
        <form data-insights-form>
          <input type="hidden" name="action" value="announcement">
          <textarea class="form-control mb-2" name="message" rows="3" maxlength="500" placeholder="Write an announcement (500 characters max)"></textarea>
          <button class="btn btn-primary" type="submit"><i class="bi bi-send me-1"></i>Publish</button>
          <button class="btn btn-outline-secondary ms-2" type="button" id="vsClearAnnouncement">Remove announcement</button>
        </form>
      </div></div>
      <div class="col-12 col-xl-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-b"><i class="bi bi-envelope-paper"></i></span><h2>Email summary<small>Daily or weekly report</small></h2></div>
        <p class="vi-sub">Requires PHP mail to be configured and the scheduled sender set up (instructions in migrations.sql comments).</p>
        <form data-insights-form class="row g-2">
          <input type="hidden" name="action" value="summary">
          <div class="col-12 col-md-5"><input class="form-control" type="email" name="email" id="vsSummaryEmail" placeholder="admin@example.com" aria-label="Summary email"></div>
          <div class="col-12 col-md-4"><select class="form-select" name="frequency" id="vsSummaryFrequency" aria-label="Email frequency"><option value="off">Off</option><option value="daily">Daily</option><option value="weekly">Weekly</option></select></div>
          <div class="col-12 col-md-3"><button class="btn btn-primary w-100" type="submit">Save</button></div>
        </form>
      </div></div>
    </section>

    <section class="row g-3 mb-3" aria-label="Search and notes">
      <div class="col-12 col-xl-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-v"><i class="bi bi-search"></i></span><h2>Search<small>Users, videos and comments</small></h2></div>
        <input class="form-control mb-2" id="vsGlobalSearch" type="search" placeholder="Type at least 2 characters" autocomplete="off">
        <ul id="vsGlobalResults" class="vi-list vi-scroll"><li class="vi-empty">Search across supported content.</li></ul>
      </div></div>
      <div class="col-12 col-xl-6"><div class="vi-card">
        <div class="vi-head"><span class="ico t-s"><i class="bi bi-journal-text"></i></span><h2>Private admin notes<small>Visible to admins only</small></h2></div>
        <form data-insights-form class="mb-3">
          <input type="hidden" name="action" value="note">
          <div class="row g-2 mb-2">
            <div class="col-4"><input class="form-control" type="number" name="user_id" id="vsNoteUserId" min="1" required placeholder="User ID"></div>
            <div class="col-8"><textarea class="form-control" name="note" rows="1" maxlength="2000" required placeholder="Private note (up to 2,000 characters)"></textarea></div>
          </div>
          <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save note</button>
          <span class="vi-sub ms-2">Find a user in Search and press “Use for note”.</span>
        </form>
        <div id="vsNotes" class="vi-scroll"><div class="vi-empty">Loading notes...</div></div>
      </div></div>
    </section>

    <div class="small text-muted text-end" id="vsInsightsUpdated"></div>
  </div>
</main>

<script>
(function () {
  'use strict';
  var api = 'dashboard-insights-api.php';
  var REFRESH_MS = 30000;
  var csrf = <?php echo json_encode($insightsCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var status = el('vsInsightsStatus');
  var searchTimer = 0, searchAbort = null, statusTimer = 0, lastOk = 0;

  function el(id) { return document.getElementById(id); }
  function mk(tag, cls, txt) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (txt != null) { n.textContent = txt; } return n; }
  function text(id, value) {
    var n = el(id), v = String(value);
    if (n.textContent === v) { return; }
    var had = n.getAttribute('data-set');
    n.textContent = v; n.setAttribute('data-set', '1');
    if (had) { n.classList.remove('vi-flash'); void n.offsetWidth; n.classList.add('vi-flash'); }
  }
  function say(kind, msg, auto) {
    window.clearTimeout(statusTimer);
    status.className = 'alert alert-' + kind;
    status.textContent = msg;
    status.hidden = false;
    if (auto) { statusTimer = window.setTimeout(function () { status.hidden = true; }, 4000); }
  }
  function list(id, rows, empty, render) {
    var node = el(id);
    node.textContent = '';
    if (!rows.length) { node.appendChild(mk('li', 'vi-empty', empty)); return; }
    rows.forEach(function (item, i) {
      var row = mk('li', 'vi-item');
      render(row, item, i);
      node.appendChild(row);
    });
  }
  function formatBytes(value) {
    var size = Number(value) || 0, units = ['B', 'KB', 'MB', 'GB', 'TB'], index = 0;
    while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
    return (index > 1 ? size.toFixed(1) : Math.round(size)) + ' ' + units[index];
  }
  function chip(txt, cls) { return mk('span', 'vi-chip ' + cls, txt); }
  function main(parts) { var m = mk('div', 'main'); parts.forEach(function (p) { m.appendChild(p); }); return m; }
  function line(strong, sub) {
    var d = mk('div');
    if (strong && strong.nodeType) { d.appendChild(strong); } else { d.appendChild(mk('div', null, strong)); }
    if (sub) { d.appendChild(mk('div', 'vi-sub', sub)); }
    return d;
  }

  /* People: profile picture + VIP badge. Looked up in one request per refresh and remembered. */
  var peopleCache = {};
  function person(name) {
    name = String(name || 'unknown user');
    var w = mk('span', 'vi-user'), av = mk('span', 'vi-av', (name.trim().charAt(0) || '?').toUpperCase()), nm = mk('span', 'vi-uname');
    nm.appendChild(mk('span', 'vi-uname-t', name));
    w.appendChild(av); w.appendChild(nm);
    w.setAttribute('data-vi-user', name.toLowerCase());
    paint(w);
    return w;
  }
  function paint(w) {
    var p = peopleCache[w.getAttribute('data-vi-user')];
    if (!p || w.getAttribute('data-vi-done')) { return; }
    w.setAttribute('data-vi-done', '1');
    if (p.avatar) {
      var img = document.createElement('img'), av = w.querySelector('.vi-av');
      img.alt = ''; img.loading = 'lazy';
      img.onload = function () { av.style.color = 'transparent'; };
      img.onerror = function () { img.remove(); };
      img.src = p.avatar;
      av.appendChild(img);
    }
    if (p.tier) {
      var b = mk('span', 'vi-vip' + (p.tier === 'plus' ? ' plus' : ''));
      b.title = p.tier === 'plus' ? 'VibeStream Premium Plus' : 'VibeStream Premium';
      b.appendChild(mk('i', 'bi bi-gem')); b.appendChild(document.createTextNode(p.tier === 'plus' ? 'VIP+' : 'VIP'));
      w.querySelector('.vi-uname').appendChild(b);
    }
  }
  function hydrate() {
    var nodes = [].slice.call(document.querySelectorAll('.vi [data-vi-user]'));
    var need = [];
    nodes.forEach(function (n) { var k = n.getAttribute('data-vi-user'); if (!(k in peopleCache) && need.indexOf(k) < 0 && k !== 'unknown user') { need.push(k); } });
    function paintAll() { nodes.forEach(paint); }
    if (!need.length) { paintAll(); return; }
    fetch('insights-people.php?names=' + encodeURIComponent(JSON.stringify(need.slice(0, 100))), {cache: 'no-store', credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { return; }
        need.forEach(function (k) { peopleCache[k] = d.people[k] || {avatar: '', tier: ''}; });
        paintAll();
      })
      .catch(function (e) { console.error('Could not load profile pictures.', e); });
  }
  function typeTone(t) { t = String(t || '').toLowerCase(); return t.indexOf('short') > -1 || t.indexOf('reel') > -1 ? 't-v' : (t.indexOf('user') > -1 ? 't-g' : 't-b'); }

  function render(data) {
    /* KPIs */
    text('vsViewsMinute', Number(data.live.views_per_minute || 0).toLocaleString());
    var top = data.live.top_video;
    text('vsTopVideo', top ? 'Top: ' + top.type + ' ' + (top.title || '#' + top.id) + ' · ' + top.creator + ' (' + top.views + ' in the last hour)' : 'No views recorded in the last hour.');
    text('vsKpiTrend', data.live.trending.length);
    text('vsKpiSpikes', data.live.spikes.length);
    text('vsKpiBroken', data.broken.count);

    list('vsTrending', data.live.trending, 'No recent views yet. View tracking starts after migrations.sql is applied.', function (row, item, i) {
      row.appendChild(mk('span', 'vi-rank', i + 1));
      row.appendChild(chip(item.type, typeTone(item.type)));
      row.appendChild(main([line(person(item.creator), item.title || '#' + item.id)]));
      row.appendChild(mk('span', 'end', Number(item.views).toLocaleString() + ' views'));
    });
    el('vsSpikes').textContent = '';
    if (!data.live.spikes.length) {
      el('vsSpikes').appendChild(mk('li', 'vi-empty', 'No unusual signup or upload pattern detected.'));
    } else {
      data.live.spikes.forEach(function (item) {
        var li = mk('li'), box = mk('div', 'vi-warnbox');
        box.appendChild(mk('i', 'bi bi-exclamation-triangle-fill'));
        box.appendChild(mk('span', null, item.message));
        li.appendChild(box); el('vsSpikes').appendChild(li);
      });
    }
    list('vsRising', data.live.rising, 'Not enough recent view history yet.', function (row, item, i) {
      var up = item.change >= 0;
      row.appendChild(mk('span', 'vi-rank', i + 1));
      row.appendChild(main([line(person(item.creator), Number(item.current).toLocaleString() + ' views this week')]));
      row.appendChild(chip((up ? '▲ +' : '▼ ') + item.change + '%', up ? 't-g' : 't-r'));
    });

    var storage = Object.keys(data.storage || {}).map(function (name) { return {name: name, bytes: Number(data.storage[name]) || 0}; })
      .sort(function (a, b) { return b.bytes - a.bytes; });
    var maxB = storage.length ? Math.max(1, storage[0].bytes) : 1;
    list('vsStorage', storage, 'No local uploaded files could be attributed to creators.', function (row, item) {
      row.style.flexDirection = 'column'; row.style.alignItems = 'stretch'; row.style.gap = '5px';
      var t = mk('div', 'd-flex justify-content-between');
      t.appendChild(person(item.name)); t.appendChild(mk('strong', null, formatBytes(item.bytes)));
      var bar = mk('div', 'vi-bar'), fill = mk('i'); fill.style.background = 'var(--v)';
      bar.appendChild(fill); row.appendChild(t); row.appendChild(bar);
      requestAnimationFrame(function () { fill.style.width = (item.bytes / maxB * 100) + '%'; });
    });

    text('vsBrokenCount', data.broken.count + ' possible issue' + (data.broken.count === 1 ? '' : 's') + ' found (up to 2,500 recent rows checked per content table).');
    list('vsBroken', data.broken.items, 'No broken or zero-view uploads found in the checked records.', function (row, item) {
      row.appendChild(chip(item.type, 't-r'));
      row.appendChild(main([line(item.title, item.reasons.join(', '))]));
    });

    list('vsSpamIps', data.spam.same_ip_accounts, data.spam.ip_hashing_enabled ? 'No repeated signup IP matches found yet. New signups will be checked.' : 'IP matching is unavailable. Check the private key configuration.', function (row, item) {
      row.appendChild(chip(item.accounts, 't-o'));
      var accounts = mk('div', 'vi-ip-people');
      (Array.isArray(item.users) ? item.users : []).forEach(function (name) {
        accounts.appendChild(person(name));
      });
      row.appendChild(main([
        accounts.childNodes.length ? accounts : mk('span', null, 'accounts share a privacy-preserving IP hash'),
        mk('div', 'vi-sub', 'accounts share a privacy-preserving IP hash')
      ]));
    });
    text('vsIpStatus', data.spam.ip_hashing_enabled ? 'IP matching is enabled. Only keyed hashes are stored; earlier signups cannot be matched.' : 'IP matching is disabled because the private key is unavailable.');

    text('vsRetentionNote', data.retention.available ? 'Based on activity collected after setup; historical retention before tracking is unavailable.' : 'Retention tracking data is not available yet.');
    [['1', 'day1'], ['7', 'day7'], ['30', 'day30']].forEach(function (pair) {
      var value = data.retention.cohorts[pair[1]];
      text('vsRetention' + pair[0], value === null ? 'Not enough data yet' : value + '%');
      el('vsRetention' + pair[0] + 'Bar').style.width = (value === null ? 0 : Math.max(0, Math.min(100, Number(value)))) + '%';
    });
    [['Signup', 'signups'], ['Upload', 'uploads']].forEach(function (pair) {
      var goal = data.goals[pair[1]], current = Number(goal.current || 0), target = Number(goal.target || 0);
      var pct = target > 0 ? Math.min(100, current / target * 100) : 0;
      text('vs' + pair[0] + 'GoalText', current.toLocaleString() + ' / ' + target.toLocaleString());
      text('vs' + pair[0] + 'GoalPct', target > 0 ? Math.round(pct) + '% of goal' : 'No goal set');
      el('vs' + pair[0] + 'GoalBar').style.width = pct + '%';
    });

    var announcementInput = document.querySelector('textarea[name="message"]');
    if (announcementInput && document.activeElement !== announcementInput) { announcementInput.value = data.announcement || ''; }
    if (document.activeElement !== el('vsSummaryEmail')) { el('vsSummaryEmail').value = data.summary.email || ''; }
    if (document.activeElement !== el('vsSummaryFrequency')) { el('vsSummaryFrequency').value = data.summary.frequency || 'off'; }

    var notes = el('vsNotes'); notes.textContent = '';
    if (!data.notes.length) { notes.appendChild(mk('div', 'vi-empty', 'No admin notes yet.')); }
    data.notes.forEach(function (item) {
      var card = mk('div', 'vi-note');
      card.appendChild(mk('div', null, item.note));
      var meta = mk('div', 'meta');
      meta.appendChild(person(item.username));
      meta.appendChild(mk('span', null, 'by ' + item.admin_name));
      meta.appendChild(mk('span', null, item.created_at));
      if (item.admin_name === data.current_admin) {
        var remove = mk('button', 'btn btn-sm btn-outline-danger py-0 ms-auto', 'Delete');
        remove.type = 'button';
        remove.addEventListener('click', function () { deleteNote(item.id); });
        meta.appendChild(remove);
      }
      card.appendChild(meta); notes.appendChild(card);
    });

    hydrate();
    lastOk = Date.now();
    text('vsInsightsUpdated', 'Last refreshed ' + new Date(data.ts * 1000).toLocaleTimeString());
    if (status.classList.contains('alert-info') || status.classList.contains('alert-danger')) { status.hidden = true; }
    badge();
  }

  function badge() {
    var b = el('vsLiveBadge'), t = el('vsLiveText');
    if (lastOk && Date.now() - lastOk > REFRESH_MS * 2.5) { b.className = 'vi-live warn'; t.textContent = 'RECONNECTING…'; return; }
    b.className = 'vi-live';
    var ago = lastOk ? Math.round((Date.now() - lastOk) / 1000) : 0;
    t.textContent = lastOk ? 'LIVE · ' + (ago < 2 ? 'just now' : ago + 's ago') : 'LIVE';
  }
  window.setInterval(badge, 1000);

  function post(values) {
    values.append('csrf', csrf);
    return fetch(api, {method: 'POST', body: values, credentials: 'same-origin'})
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) { throw new Error(data.error || 'Could not save changes.'); }
          return data;
        });
      });
  }
  function deleteNote(noteId) {
    var values = new FormData();
    values.append('action', 'delete_note');
    values.append('note_id', noteId);
    post(values).then(function (d) { say('success', d.message, true); refresh(true); })
      .catch(function (e) { say('danger', e.message); });
  }
  function refresh(force) {
    if (document.hidden && !force) { return; }
    fetch(api, {cache: 'no-store', credentials: 'same-origin'})
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) { throw new Error(data.error || 'Insights could not be loaded.'); }
          return data;
        });
      })
      .then(render)
      .catch(function (error) { say('danger', error.message); console.error('Live insights refresh failed.', error); });
  }

  document.querySelectorAll('[data-insights-form]').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var btn = form.querySelector('button[type=submit]');
      if (btn) { btn.disabled = true; }
      post(new FormData(form))
        .then(function (data) {
          say('success', data.message, true);
          if (form.querySelector('[name=action]').value === 'note') { form.reset(); }
          refresh(true);
        })
        .catch(function (error) { say('danger', error.message); })
        .then(function () { if (btn) { btn.disabled = false; } });
    });
  });
  el('vsClearAnnouncement').addEventListener('click', function () {
    var ta = document.querySelector('textarea[name="message"]');
    ta.value = '';
    ta.form.requestSubmit();
  });

  el('vsGlobalSearch').addEventListener('input', function () {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(search, 250);
  });
  function search() {
    var query = el('vsGlobalSearch').value.trim();
    if (searchAbort) { searchAbort.abort(); }
    var node = el('vsGlobalResults');
    node.textContent = '';
    if (query.length < 2) { node.appendChild(mk('li', 'vi-empty', 'Type at least 2 characters.')); return; }
    searchAbort = new AbortController();
    fetch(api + '?q=' + encodeURIComponent(query), {cache: 'no-store', credentials: 'same-origin', signal: searchAbort.signal})
      .then(function (response) { return response.json(); })
      .then(function (data) {
        if (!data.ok) { throw new Error(data.error || 'Search failed.'); }
        list('vsGlobalResults', data.search, 'No matches found.', function (row, item) {
          row.appendChild(chip(item.type, typeTone(item.type)));
          if (item.type === 'User') {
            var uname = String(item.username || item.name || item.label || '').replace(/\s*[(#\u00b7\u2013-].*$/, '');
            row.appendChild(main([person(uname)]));
          } else {
            row.appendChild(main([mk('span', null, item.label)]));
          }
          if (item.type === 'User' && Number(item.id) > 0) {
            var choose = mk('button', 'btn btn-sm btn-outline-primary py-0', 'Use for note');
            choose.type = 'button';
            choose.addEventListener('click', function () { el('vsNoteUserId').value = item.id; el('vsNoteUserId').focus(); });
            row.appendChild(choose);
          }
        });
        hydrate();
      })
      .catch(function (error) {
        if (error.name !== 'AbortError') {
          node.textContent = '';
          node.appendChild(mk('li', 'vi-empty text-danger', 'Search failed: ' + error.message));
          console.error('Admin search failed.', error);
        }
      });
  }

  refresh();
  window.setInterval(refresh, REFRESH_MS);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastOk > 5000) { refresh(); } });
})();
</script>
<footer class="admin-footer">
  <div class="container-fluid px-3 px-lg-4">
    <span>Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a></span>
  </div>
</footer>
</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body>
</html>