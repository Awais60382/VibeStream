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
/* ================= VibeStream Insights – Redesigned to match mockup ================= */
html body{background:radial-gradient(900px 480px at 8% -8%,rgba(255,26,34,.14),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(255,42,50,.12),transparent 70%),#050202;background-attachment:fixed}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:#050202}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,#a50a11,#ff2a32);border-radius:8px}
html body ::selection{background:rgba(255,42,50,.45);color:#fff}

.admin-shell>.admin-main{width:calc(100% - var(--sidebar-width))}
.vi{min-width:0;--g:#22e58a;--r:#ff3038;--b:#ff3038;--v:#ff3038;--o:#ffb52e;--red:#ff2a32;--red2:#ff343c;
    --card:#100707;--line:rgba(255,43,51,.48);--muted:#c9b4b4;color:#fff}

/* ---- hero ---- */
.vi .vi-hero{position:relative;overflow:hidden;min-height:118px;padding:16px 26px;border:1.5px solid rgba(255,43,51,.75);border-radius:22px;display:flex;align-items:center;gap:22px;flex-wrap:wrap;
  background:radial-gradient(ellipse at 88% 20%,rgba(255,24,32,.38),transparent 40%),
    repeating-linear-gradient(125deg,transparent 0 70px,rgba(255,24,32,.08) 71px 96px),
    linear-gradient(110deg,#1b0607,#080303 58%,#170405);
  box-shadow:0 0 34px -8px rgba(255,18,28,.7),inset 0 0 70px -34px rgba(255,42,50,.55)}
.vi .vi-hero:before{content:"";position:absolute;inset:0;pointer-events:none;background:radial-gradient(520px 200px at 82% 15%,rgba(255,26,26,.26),transparent 70%)}
.vi .vi-hero>*{position:relative;z-index:1}
.vi .vi-hero-icon{width:78px;height:78px;display:block;flex:none}
.vi .vi-hero-icon svg{width:100%;height:100%;display:block;filter:drop-shadow(0 0 14px rgba(255,26,26,.8))}
.vi .vi-hero h1{color:#fff;font-size:clamp(1.8rem,3.4vw,2.8rem);font-weight:800;letter-spacing:-.02em;margin:0 0 4px}
.vi .vi-hero h1 span{color:var(--red);text-shadow:0 0 22px rgba(255,26,26,.6)}
.vi .vi-hero p{color:#f0e4e4!important;font-size:1.02rem;margin:0}
.vi .vi-hero-art{position:absolute!important;right:200px;top:50%;width:92px;height:68px;transform:translateY(-50%);opacity:.55;z-index:0!important;pointer-events:none}
.vi .vi-hero-art svg{width:100%;height:100%;display:block;filter:drop-shadow(0 0 12px rgba(255,26,26,.7))}
.vi .vi-hero-right{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap;max-width:100%}
.vi .vi-period-control{position:relative}
.vi .vi-period-control>.bi{position:absolute;z-index:1;left:16px;top:50%;transform:translateY(-50%);pointer-events:none}
.vi .vi-period-control:after{content:"";position:absolute;right:17px;top:50%;width:8px;height:8px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-70%) rotate(45deg);pointer-events:none}
.vi .vi-period-btn{display:inline-flex;align-items:center;gap:9px;padding:11px 22px;border-radius:999px;border:1.5px solid rgba(255,42,50,.65);background:rgba(0,0,0,.62);color:#fff;font-weight:700;font-size:.88rem;cursor:pointer;white-space:nowrap;
  min-width:170px;padding-left:42px;padding-right:42px;box-shadow:0 0 18px -5px rgba(255,26,26,.65),inset 0 0 14px -8px rgba(255,42,50,.9);appearance:none;-webkit-appearance:none}
.vi .vi-period-btn:hover{border-color:#ff5a60;box-shadow:0 0 22px -3px rgba(255,26,26,.8)}
.vi .vi-period-btn:focus{outline:none;border-color:var(--red2);box-shadow:0 0 0 1px var(--red2),0 0 16px rgba(255,26,26,.40)}

/* ---- KPI strip (4 cards) ---- */
.vi .vi-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-top:16px}
.vi .vi-kpi-card{position:relative;overflow:hidden;padding:16px 18px;border-radius:20px;border:1.5px solid var(--line);min-width:0;
  background:radial-gradient(ellipse at 100% 0,rgba(255,20,28,.14),transparent 48%),linear-gradient(145deg,#150808,#080505);
  box-shadow:0 0 26px -11px rgba(255,18,28,.7),inset 0 1px 0 rgba(255,255,255,.04),inset 0 -26px 46px -34px rgba(255,26,26,.55);
  transition:border-color .18s,box-shadow .18s}
.vi .vi-kpi-card:hover{border-color:var(--red2);box-shadow:0 0 28px -4px rgba(255,18,28,.65),inset 0 -26px 46px -34px rgba(255,26,26,.6)}
.vi .vi-kico{width:58px;height:58px;border-radius:50%;display:grid;place-items:center;font-size:26px;flex:none;color:var(--red);
  background:radial-gradient(circle at 50% 35%,rgba(255,42,50,.32),rgba(120,8,14,.38));box-shadow:0 0 22px -4px rgba(255,26,26,.85),inset 0 0 14px rgba(255,26,26,.25)}
.vi .vi-kico i{filter:drop-shadow(0 0 6px rgba(255,26,26,.85))}
.vi .vi-kpi-card .kpi-row{display:flex;align-items:center;gap:16px}
.vi .vi-kpi-card .kpi-body{min-width:0;flex:1}
.vi .vi-kpi-card .kpi-label{font-size:.92rem;color:var(--muted);font-weight:500}
.vi .vi-kpi-card .kpi-val{font-size:2.1rem;font-weight:800;line-height:1.15;color:#fff;letter-spacing:-.02em;margin-top:2px}
.vi .vi-kpi-card .kpi-trend{font-size:.82rem;font-weight:700;margin-top:3px}
.vi .vi-kpi-card .kpi-trend.up{color:#22e58a}
.vi .vi-kpi-card .kpi-trend.down{color:#ff5b61}
.vi .vi-kpi-card .kpi-trend.flat{color:var(--muted)}
.vi .vi-spark-bg{position:absolute;right:12px;bottom:8px;width:38%;max-width:150px;height:56px;filter:drop-shadow(0 0 6px rgba(255,26,26,.75));pointer-events:none;opacity:.85}

/* ---- cards (shared) ---- */
.vi .vi-card{position:relative;height:100%;padding:16px 18px;border-radius:20px;color:#fff;
  background:radial-gradient(ellipse at 100% 0,rgba(255,20,28,.14),transparent 48%),linear-gradient(145deg,#150808,#080505);
  border:1.5px solid var(--line);box-shadow:0 0 26px -11px rgba(255,18,28,.7),inset 0 1px 0 rgba(255,255,255,.04),inset 0 -26px 46px -34px rgba(255,26,26,.55);
  transition:border-color .18s,box-shadow .18s}
.vi .vi-card:hover{border-color:var(--red2);box-shadow:0 0 28px -4px rgba(255,18,28,.65),inset 0 -26px 46px -34px rgba(255,26,26,.6)}
.vi .vi-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.vi .vi-head h2{font-size:1.1rem;font-weight:800;margin:0;line-height:1.25;color:#fff;flex:1}
.vi .vi-head small{display:block;color:var(--muted);font-size:.76rem;font-weight:400}
.vi .vi-head .ico{width:36px;height:36px;border-radius:11px;display:grid;place-items:center;font-size:1.05rem;flex:none;
  background:rgba(255,38,45,.14)!important;color:var(--red2)!important;border:1px solid rgba(255,55,60,.55);box-shadow:0 0 14px -3px rgba(255,28,34,.6)}
.vi .vi-head-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:999px;border:1.5px solid rgba(255,42,50,.5);background:rgba(0,0,0,.55);color:#fff;font-weight:600;font-size:.78rem;text-decoration:none;white-space:nowrap;cursor:pointer}
.vi .vi-head-btn:hover{color:#fff;border-color:var(--red2);box-shadow:0 0 14px rgba(255,30,38,.5)}

/* ---- Views Overview chart ---- */
.vi .vi-chart-wrap{position:relative;width:100%;padding-top:12px}
.vi .vi-chart-legend{display:flex;align-items:center;gap:18px;margin-bottom:8px;padding-left:4px}
.vi .vi-chart-legend span{display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)}
.vi .vi-chart-legend span i{width:10px;height:10px;border-radius:50%;display:inline-block}
.vi .vi-chart-legend .dot-views{background:#ff3038;box-shadow:0 0 8px rgba(255,48,56,.7)}
.vi .vi-chart-legend .dot-last{background:#555}
.vi .vi-chart-svg{width:100%;height:176px;display:block}
.vi .vi-chart-svg text{fill:var(--muted);font-size:11px;font-family:inherit}
.vi .vi-chart-svg .grid-line{stroke:rgba(255,43,51,.15);stroke-width:1}
.vi .vi-chart-svg .area-fill{fill:url(#viChartGrad);opacity:.6}
.vi .vi-chart-svg .chart-line{fill:none;stroke:#ff3038;stroke-width:2.5;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 0 6px rgba(255,48,56,.65))}
.vi .vi-chart-svg .chart-line-last{fill:none;stroke:#555;stroke-width:1.8;stroke-dasharray:6 4;stroke-linecap:round;stroke-linejoin:round}
.vi .vi-chart-svg .chart-dot{fill:#ff3038;stroke:#1a0808;stroke-width:2.5;filter:drop-shadow(0 0 6px rgba(255,48,56,.8))}

/* ---- Top Content ---- */
.vi .vi-top-item{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid rgba(255,43,51,.18)}
.vi .vi-top-item:last-child{border-bottom:0}
.vi .vi-rank{width:30px;height:30px;border-radius:50%;display:grid;place-items:center;font-size:.82rem;font-weight:800;flex:none;background:rgba(255,38,45,.18);color:#f3caca}
.vi .vi-top-item:nth-child(1) .vi-rank{background:linear-gradient(135deg,#fbbf24,#e08a00);color:#fff;box-shadow:0 0 12px rgba(251,191,36,.65)}
.vi .vi-top-item:nth-child(2) .vi-rank{background:linear-gradient(135deg,#d5dbe5,#8b97a8);color:#fff;box-shadow:0 0 12px rgba(203,213,225,.5)}
.vi .vi-top-item:nth-child(3) .vi-rank{background:linear-gradient(135deg,#fdba74,#c2410c);color:#fff;box-shadow:0 0 12px rgba(234,88,12,.6)}
.vi .vi-top-thumb{width:76px;height:44px;border-radius:8px;overflow:hidden;flex:none;background:#1a0c0d;border:1px solid rgba(255,43,51,.25)}
.vi .vi-top-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.vi .vi-top-thumb.is-placeholder{display:grid;place-items:center;color:#ff3b42;background:radial-gradient(circle,rgba(255,43,51,.2),#100707 72%)}
.vi .vi-top-thumb.is-placeholder i{font-size:1.55rem}
.vi .vi-top-info{flex:1;min-width:0}
.vi .vi-top-info .title{font-weight:600;font-size:.88rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.vi .vi-top-info .meta{font-size:.76rem;color:var(--muted);margin-top:2px}
.vi .vi-top-views{display:inline-flex;align-items:center;gap:6px;flex:none;padding:7px 12px;border-radius:11px;background:rgba(255,38,45,.14);border:1px solid rgba(255,55,60,.45);box-shadow:0 0 12px -4px rgba(255,28,34,.6);font-weight:700;font-size:.82rem;white-space:nowrap}
.vi .vi-top-views i{font-size:.76rem}

/* ---- bottom stats row ---- */
.vi .vi-big-stat{text-align:left}
.vi .vi-big-stat,.vi .vi-head{position:relative;z-index:1}
.vi .vi-card-art{position:absolute;right:12px;bottom:8px;width:42%;max-width:180px;height:105px;color:#ff3038;opacity:.48;pointer-events:none}
.vi .vi-card-art .art-fill{fill:currentColor;opacity:.24}
.vi .vi-card-art .art-line{fill:none;stroke:currentColor;stroke-width:3;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 0 6px rgba(255,30,38,.85))}
.vi .vi-card-art .art-outline{fill:none;stroke:currentColor;stroke-width:4}
.vi .vi-big-stat .big-val{font-size:2.4rem;font-weight:800;line-height:1.15;color:#fff;letter-spacing:-.02em}
.vi .vi-big-stat .big-label{font-size:.92rem;color:var(--muted);margin-top:2px}
.vi .vi-big-stat .big-trend{font-size:.88rem;font-weight:700;color:#22e58a;margin-top:4px}
.vi .vi-big-stat .big-trend.flat{color:var(--muted)}

/* ---- top countries ---- */
.vi .vi-country{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,43,51,.12)}
.vi .vi-country:last-child{border-bottom:0}
.vi .vi-country-flag{width:28px;height:20px;border-radius:3px;overflow:hidden;flex:none;background:#1a0c0d;display:grid;place-items:center;font-size:1rem}
.vi .vi-country-name{flex:none;min-width:60px;font-size:.88rem;font-weight:600}
.vi .vi-country-bar{flex:1;height:8px;border-radius:99px;background:rgba(255,255,255,.08);overflow:hidden}
.vi .vi-country-bar i{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,#b50d16,#ff3038);box-shadow:0 0 10px rgba(255,30,38,.5);transition:width .8s cubic-bezier(.22,1,.36,1)}
.vi .vi-country-pct{flex:none;font-size:.82rem;font-weight:700;min-width:40px;text-align:right}

/* ---- live badge ---- */
.vi .vi-live{display:inline-flex;align-items:center;gap:9px;padding:10px 20px 10px 16px;border-radius:999px;font-size:.84rem;font-weight:700;letter-spacing:.03em;
  color:#22e58a;background:rgba(0,0,0,.6);border:1.5px solid rgba(34,229,138,.6);box-shadow:0 0 18px -4px rgba(34,229,138,.55)}
.vi .vi-live .dot{width:9px;height:9px;border-radius:50%;background:#22e58a;position:relative;box-shadow:0 0 10px #22e58a}
.vi .vi-live .dot::after{content:"";position:absolute;inset:0;border-radius:50%;background:#22e58a;animation:viPulse 1.6s ease-out infinite}
.vi .vi-live.warn{color:#ffb52e;border-color:rgba(255,181,46,.65);box-shadow:0 0 18px -4px rgba(255,181,46,.55)}
.vi .vi-live.warn .dot,.vi .vi-live.warn .dot::after{background:#ffb52e;box-shadow:0 0 10px #ffb52e}
@keyframes viPulse{0%{transform:scale(1);opacity:.7}100%{transform:scale(3);opacity:0}}

/* ---- section headers ---- */
.vi .vi-sec{font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;color:#ff5b61;font-weight:700;margin:14px 0 6px;text-shadow:0 0 8px rgba(255,35,42,.35)}
.vi .vi-sec:first-of-type{margin-top:0}

/* ---- lists ---- */
.vi .vi-list{list-style:none;margin:0;padding:0}
.vi .vi-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,43,51,.2);font-size:.88rem;min-width:0}
.vi .vi-item:last-child{border-bottom:0}
.vi .vi-item .main{flex:1;min-width:0;overflow-wrap:anywhere}
.vi .vi-item .end{flex:none;font-variant-numeric:tabular-nums;font-weight:700;padding:7px 14px;border-radius:11px;background:rgba(255,38,45,.14);border:1px solid rgba(255,55,60,.45);box-shadow:0 0 12px -4px rgba(255,28,34,.6)}
.vi .vi-empty{color:var(--muted);font-size:.88rem;padding:8px 0}
.vi .vi-chip{font-size:.68rem;font-weight:800;padding:3px 10px;border-radius:99px;text-transform:uppercase;letter-spacing:.03em;flex:none;background:rgba(255,38,45,.16);color:#ff8a8e;border:1px solid rgba(255,55,60,.4)}
.vi .vi-chip.t-g{background:rgba(34,229,138,.14);color:#22e58a;border-color:rgba(34,229,138,.5)}
.vi .vi-chip.t-o{background:rgba(255,181,46,.14);color:#ffb52e;border-color:rgba(255,181,46,.5)}
.vi .vi-sub,.vi .text-muted{color:var(--muted)!important;font-size:.8rem}
.vi .vi-warnbox{display:flex;gap:10px;align-items:flex-start;padding:11px 14px;border-radius:13px;background:rgba(255,30,38,.12);border:1px solid rgba(255,43,51,.45);color:#ffb8b8;font-size:.86rem;margin-bottom:8px;box-shadow:0 0 18px -8px rgba(255,26,34,.6)}

/* bars */
.vi .vi-bar{height:9px;border-radius:99px;background:rgba(255,255,255,.10);overflow:hidden}
.vi .vi-bar>i{display:block;height:100%;border-radius:99px;width:0;transition:width .8s cubic-bezier(.22,1,.36,1);background:linear-gradient(90deg,#b50d16,#ff3038)!important;box-shadow:0 0 10px rgba(255,30,38,.6)}
.vi .vi-ret{margin-bottom:14px}
.vi .vi-ret .top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px;font-size:.9rem}
.vi .vi-ret .top strong{font-variant-numeric:tabular-nums}
.vi .vi-goal-pct{font-size:.76rem;color:var(--muted);margin-top:3px;text-align:right}
.vi .vi-goal+.vi-goal{margin-top:16px;padding-top:14px;border-top:1px solid rgba(255,43,51,.18)}

/* forms */
.vi .form-control,.vi .form-select{border-radius:12px;font-size:.88rem;background:#0a0505!important;color:#fff!important;border:1.5px solid var(--line)!important}
.vi .form-control::placeholder{color:#9e8d8d}
.vi .form-control:focus,.vi .form-select:focus{outline:none;border-color:var(--red2)!important;box-shadow:0 0 0 1px var(--red2),0 0 16px rgba(255,30,38,.35)!important}
.vi .btn{border-radius:999px;font-size:.86rem;font-weight:700;padding-left:18px;padding-right:18px}
.vi .btn-primary{background:linear-gradient(180deg,#ff3a42,#c20d15)!important;border:1px solid #ff6a70!important;color:#fff;box-shadow:0 0 18px rgba(255,25,32,.45),inset 0 1px 0 rgba(255,255,255,.3)}
.vi .btn-primary:hover,.vi .btn-primary:focus{box-shadow:0 0 26px rgba(255,25,32,.75);transform:translateY(-1px)}
.vi .btn-outline-secondary,.vi .btn-outline-danger,.vi .btn-outline-primary{background:rgba(0,0,0,.55)!important;color:#f5eaea!important;border:1.5px solid rgba(255,43,51,.55)!important}
.vi .btn-outline-secondary:hover,.vi .btn-outline-danger:hover,.vi .btn-outline-primary:hover{border-color:var(--red2)!important;box-shadow:0 0 14px rgba(255,30,38,.5)}
.vi .btn-sm{padding-left:12px;padding-right:12px}

/* people */
.vi .vi-user{display:inline-flex;align-items:center;gap:8px;min-width:0;max-width:100%;vertical-align:middle}
.vi .vi-ip-people{display:flex;flex-wrap:wrap;gap:8px 14px}
.vi .vi-av{position:relative;width:32px;height:32px;border-radius:50%;flex:none;overflow:hidden;display:grid;place-items:center;font-size:.8rem;font-weight:700;color:#fff;background:linear-gradient(135deg,#ff343c,#8f090f);box-shadow:0 0 10px -2px rgba(255,38,45,.6)}
.vi .vi-av img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#100707}
.vi .vi-uname{display:inline-flex;align-items:center;gap:6px;min-width:0;font-weight:600}
.vi .vi-uname-t{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.vi .vi-vip{flex:none;display:inline-flex;align-items:center;gap:3px;padding:1px 8px;border-radius:999px;font-size:.64rem;font-weight:800;letter-spacing:.04em;color:#2a1700;background:linear-gradient(135deg,#fcd34d,#f59e0b);box-shadow:0 0 10px rgba(245,158,11,.55)}
.vi .vi-vip.plus{color:#fff;background:linear-gradient(135deg,#e71e28,#8d090f);box-shadow:0 0 10px rgba(255,38,45,.45)}
.vi .vi-vip i{font-size:.7rem}

/* notes */
.vi .vi-note{padding:11px 14px;border-radius:13px;background:rgba(255,30,38,.08);border:1px solid rgba(255,43,51,.3);margin-bottom:8px;font-size:.86rem}
.vi .vi-note .meta{color:var(--muted);font-size:.76rem;margin-top:4px;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center}
.vi .vi-note .meta .vi-av{width:22px;height:22px;font-size:.65rem}
.vi .vi-scroll{max-height:300px;overflow:auto}
.vi .vi-list.vi-scroll{padding-right:4px}

/* status toast */
#vsInsightsStatus{border-radius:14px;font-size:.88rem;color:#f4eeee!important;background:rgba(255,35,42,.08)!important;border:1px solid rgba(255,43,51,.45)!important;box-shadow:0 0 18px -8px rgba(255,20,28,.55)}
#vsInsightsStatus.alert-danger{background:rgba(255,35,42,.16)!important;border-color:rgba(255,43,51,.7)!important}
#vsInsightsStatus.alert-success{background:rgba(34,229,138,.10)!important;border-color:rgba(34,229,138,.5)!important}

/* ---- advanced toggle ---- */
.vi .vi-adv-toggle{display:flex;align-items:center;justify-content:center;gap:10px;padding:14px 0;cursor:pointer;color:var(--muted);font-weight:600;font-size:.92rem;border:0;background:0;width:100%}
.vi .vi-adv-toggle:hover{color:var(--red2)}
.vi .vi-adv-toggle i{transition:transform .3s}
.vi .vi-adv-toggle.open i{transform:rotate(180deg)}

html[data-theme="light"] body{background:radial-gradient(900px 480px at 8% -8%,rgba(255,26,34,.06),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(255,42,50,.05),transparent 70%),#f7f5f5;background-attachment:fixed}
html[data-theme="light"] body ::-webkit-scrollbar-track{background:#f7f5f5}
html[data-theme="light"] .vi{--card:#fff;--line:rgba(190,25,34,.22);--muted:#756565;color:#291a1a}
html[data-theme="light"] .vi .vi-hero{background:radial-gradient(ellipse at 88% 20%,rgba(255,24,32,.08),transparent 40%),repeating-linear-gradient(125deg,transparent 0 70px,rgba(255,24,32,.025) 71px 96px),linear-gradient(110deg,#fff,#fffafa 58%,#fff5f5);box-shadow:0 8px 26px rgba(45,15,15,.07)}
html[data-theme="light"] .vi .vi-hero h1,
html[data-theme="light"] .vi .vi-kpi-card .kpi-val,
html[data-theme="light"] .vi .vi-card,
html[data-theme="light"] .vi .vi-head h2,
html[data-theme="light"] .vi .vi-big-stat .big-val{color:#291a1a}
html[data-theme="light"] .vi .vi-hero p{color:#766565!important}
html[data-theme="light"] .vi .vi-period-btn,
html[data-theme="light"] .vi .vi-head-btn{background:#fff;color:#6b2024;border-color:rgba(190,25,34,.28)}
html[data-theme="light"] .vi .vi-card-art{opacity:.22}
html[data-theme="light"] .vi .vi-kpi-card,
html[data-theme="light"] .vi .vi-card{background:linear-gradient(145deg,#fff,#fffafa);border-color:var(--line);box-shadow:0 8px 26px rgba(45,15,15,.07)}
html[data-theme="light"] .vi .vi-kpi-card:hover,
html[data-theme="light"] .vi .vi-card:hover{box-shadow:0 10px 28px rgba(45,15,15,.1)}
html[data-theme="light"] .vi .vi-head .ico{background:rgba(255,38,45,.08)!important}
html[data-theme="light"] .vi .vi-live{background:rgba(255,255,255,.85)}
html[data-theme="light"] .vi .vi-rank{color:#8f1018}
html[data-theme="light"] .vi .vi-top-thumb,
html[data-theme="light"] .vi .vi-country-flag{background:#f4eeee}
html[data-theme="light"] .vi .vi-top-thumb.is-placeholder{background:radial-gradient(circle,rgba(255,43,51,.12),#f4eeee 72%)}
html[data-theme="light"] .vi .vi-country-bar{background:rgba(90,45,45,.1)}
html[data-theme="light"] .vi .vi-warnbox{color:#8f3034}
html[data-theme="light"] .vi .form-control,
html[data-theme="light"] .vi .form-select{background:#fff!important;color:#291a1a!important}
html[data-theme="light"] .vi .form-control::placeholder{color:#8b7b7b}
html[data-theme="light"] .vi .form-select option{background:#fff;color:#291a1a}
html[data-theme="light"] .vi .btn-outline-secondary,
html[data-theme="light"] .vi .btn-outline-danger,
html[data-theme="light"] .vi .btn-outline-primary{background:#fff!important;color:#6b2024!important}
html[data-theme="light"] #vsInsightsStatus{color:#7a252a!important}
html[data-theme="light"] .admin-shell .dashboard-content .vi :is(h1,h2,h3,h4,h5,h6){color:#291a1a!important}
html[data-theme="light"] .admin-shell .dashboard-content .vi :is(p,small,.text-muted,.muted,.subtitle,.card-text){color:#766565!important}

@media(min-width:992px){body.sidebar-mini .admin-shell>.admin-main{width:calc(100% - var(--sidebar-mini-width))}}
@media(max-width:991.98px){.admin-shell>.admin-main{width:100%}}
@media(max-width:1199.98px){.vi .vi-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:1199.98px){.vi .vi-hero-art{display:none}}
@media(max-width:767.98px){.vi .vi-hero{padding:18px}.vi .vi-hero-icon{width:60px;height:60px}.vi .vi-kpis{grid-template-columns:1fr}.vi .vi-kico{width:50px;height:50px;font-size:22px}.vi .vi-chart-svg{height:150px}.vi .vi-card-art{width:38%;opacity:.28}}
@media(max-width:575.98px){.vi .vi-hero{flex-direction:column;align-items:flex-start}.vi .vi-hero-right{margin-left:0}}
@media (prefers-reduced-motion:reduce){.vi *,.vi *::after{animation:none!important;transition:none!important}.vi .btn-primary:hover{transform:none}}
</style>

<main class="dashboard-content">
  <div class="container-fluid px-3 px-lg-4 py-4 vi">

    <!-- shared SVG defs -->
    <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
      <defs>
        <linearGradient id="viSparkFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff2a32" stop-opacity=".55"/><stop offset="1" stop-color="#ff2a32" stop-opacity="0"/></linearGradient>
        <linearGradient id="viSparkLine" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="#b50d16"/><stop offset="1" stop-color="#ff3b42"/></linearGradient>
        <linearGradient id="viChartGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff2a32" stop-opacity=".45"/><stop offset="1" stop-color="#ff2a32" stop-opacity=".02"/></linearGradient>
        <symbol id="viSpark" viewBox="0 0 150 56" preserveAspectRatio="none">
          <polygon fill="url(#viSparkFill)" points="2,56 2,46 16,40 28,34 40,38 52,30 64,34 78,24 92,28 106,18 120,22 134,8 148,2 148,56"/>
          <polyline fill="none" stroke="url(#viSparkLine)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" points="2,46 16,40 28,34 40,38 52,30 64,34 78,24 92,28 106,18 120,22 134,8 148,2"/>
        </symbol>
      </defs>
    </svg>

    <!-- ═══════ HERO ═══════ -->
    <section class="vi-hero mb-3">
      <span class="vi-hero-icon" aria-hidden="true">
        <svg viewBox="0 0 100 100"><defs><linearGradient id="viHg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff5a5a"/><stop offset="1" stop-color="#c20d15"/></linearGradient></defs>
          <rect x="6" y="64" width="16" height="28" rx="2" fill="url(#viHg)"/><rect x="30" y="50" width="16" height="42" rx="2" fill="url(#viHg)"/><rect x="54" y="36" width="16" height="56" rx="2" fill="url(#viHg)"/>
          <path d="M6 48 30 30 48 40 82 12" fill="none" stroke="url(#viHg)" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M62 10h26v26" fill="none" stroke="url(#viHg)" stroke-width="7" stroke-linecap="round" stroke-linejoin="round"/></svg>
      </span>
      <div>
        <h1>VibeStream <span>Insights</span></h1>
        <p class="mb-0">Track your channel performance and growth.</p>
      </div>
      <span class="vi-hero-art" aria-hidden="true">
        <svg viewBox="0 0 92 68"><rect x="2" y="2" width="88" height="64" rx="18" fill="#a50a11" stroke="#ff343c" stroke-width="2"/><path d="M36 20 62 34 36 48z" fill="#ff343c"/></svg>
      </span>
      <div class="vi-hero-right">
        <span class="vi-live warn" id="vsLiveBadge"><span class="dot"></span><span id="vsLiveText">WAITING FOR DATA</span></span>
        <div class="vi-period-control">
          <i class="bi bi-calendar3" aria-hidden="true"></i>
          <select class="vi-period-btn" id="vsPeriod" aria-label="Time period">
            <option value="30">Last 30 Days</option>
            <option value="7">Last 7 Days</option>
            <option value="90">Last 90 Days</option>
          </select>
        </div>
      </div>
    </section>

    <div id="vsInsightsStatus" class="alert alert-info" role="status" hidden>Connecting to live statistics...</div>

    <!-- ═══════ KPI CARDS ═══════ -->
    <section class="vi-kpis" aria-label="Key metrics">
      <article class="vi-kpi-card">
        <div class="kpi-row">
          <span class="vi-kico"><i class="bi bi-eye-fill" aria-hidden="true"></i></span>
          <div class="kpi-body">
            <div class="kpi-label">Total Views</div>
            <div class="kpi-val" id="vsKpiViews">—</div>
            <div class="kpi-trend up" id="vsKpiViewsTrend"></div>
          </div>
        </div>
        <svg class="vi-spark-bg" aria-hidden="true"><use href="#viSpark"/></svg>
      </article>
      <article class="vi-kpi-card">
        <div class="kpi-row">
          <span class="vi-kico"><i class="bi bi-hand-thumbs-up-fill" aria-hidden="true"></i></span>
          <div class="kpi-body">
            <div class="kpi-label">Total Likes</div>
            <div class="kpi-val" id="vsKpiLikes">—</div>
            <div class="kpi-trend up" id="vsKpiLikesTrend"></div>
          </div>
        </div>
        <svg class="vi-spark-bg" aria-hidden="true"><use href="#viSpark"/></svg>
      </article>
      <article class="vi-kpi-card">
        <div class="kpi-row">
          <span class="vi-kico"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i></span>
          <div class="kpi-body">
            <div class="kpi-label">Total Comments</div>
            <div class="kpi-val" id="vsKpiComments">—</div>
            <div class="kpi-trend up" id="vsKpiCommentsTrend"></div>
          </div>
        </div>
        <svg class="vi-spark-bg" aria-hidden="true"><use href="#viSpark"/></svg>
      </article>
      <article class="vi-kpi-card">
        <div class="kpi-row">
          <span class="vi-kico"><i class="bi bi-people-fill" aria-hidden="true"></i></span>
          <div class="kpi-body">
            <div class="kpi-label">Total Subscribers</div>
            <div class="kpi-val" id="vsKpiSubs">—</div>
            <div class="kpi-trend up" id="vsKpiSubsTrend"></div>
          </div>
        </div>
        <svg class="vi-spark-bg" aria-hidden="true"><use href="#viSpark"/></svg>
      </article>
    </section>

    <!-- ═══════ VIEWS OVERVIEW + TOP CONTENT ═══════ -->
    <section class="row g-3 mt-1 mb-3" aria-label="Views and top content">
      <div class="col-12 col-xl-8">
        <div class="vi-card">
          <div class="vi-head">
            <span class="ico"><i class="bi bi-bar-chart-fill"></i></span>
            <h2>Views Overview</h2>
            <div class="vi-chart-legend ms-auto">
              <span><i class="dot-views"></i> Views</span>
              <span><i class="dot-last"></i> Last Period</span>
            </div>
          </div>
          <div class="vi-chart-wrap">
            <svg class="vi-chart-svg" id="vsChart" viewBox="0 0 700 220" preserveAspectRatio="xMidYMid meet" aria-label="Views chart"></svg>
          </div>
        </div>
      </div>
      <div class="col-12 col-xl-4">
        <div class="vi-card">
          <div class="vi-head">
            <span class="ico"><i class="bi bi-trophy-fill"></i></span>
            <h2>Top Content</h2>
            <a class="vi-head-btn" href="videos.php">View All <i class="bi bi-arrow-right"></i></a>
          </div>
          <div id="vsTopContent">
            <div class="vi-empty">Waiting for live statistics...</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════ AUDIENCE GROWTH + WATCH TIME + TOP COUNTRIES ═══════ -->
    <section class="row g-3 mb-3" aria-label="Audience and geography">
      <div class="col-12 col-xl-4">
        <div class="vi-card">
          <div class="vi-head">
            <span class="ico"><i class="bi bi-people-fill"></i></span>
            <h2>Audience Growth</h2>
          </div>
          <div class="vi-big-stat">
            <div class="big-val" id="vsAudienceVal">—</div>
            <div class="big-label" id="vsAudienceLabel">Total Subscribers</div>
            <div class="big-trend" id="vsAudienceTrend"></div>
          </div>
          <svg class="vi-card-art vi-audience-art" viewBox="0 0 180 120" aria-hidden="true">
            <g class="art-fill">
              <circle cx="104" cy="36" r="11"/><circle cx="132" cy="46" r="9"/><circle cx="78" cy="49" r="9"/>
              <path d="M84 91c0-17 8-29 20-29s20 12 20 29v9H84zM117 93c0-13 6-22 15-22s15 9 15 22v7h-30zM57 93c0-13 6-22 15-22s15 9 15 22v7H57z"/>
            </g>
            <polyline class="art-line" points="10,105 43,99 65,86 84,88 104,70 124,75 145,54 168,45"/>
          </svg>
        </div>
      </div>
      <div class="col-12 col-xl-4">
        <div class="vi-card">
          <div class="vi-head">
            <span class="ico"><i class="bi bi-clock-fill"></i></span>
            <h2>Watch Time</h2>
          </div>
          <div class="vi-big-stat">
            <div class="big-val" id="vsWatchVal">—</div>
            <div class="big-label" id="vsWatchLabel">Hours Watched</div>
            <div class="big-trend" id="vsWatchTrend"></div>
          </div>
          <svg class="vi-card-art vi-watch-art" viewBox="0 0 180 120" aria-hidden="true">
            <circle class="art-outline" cx="132" cy="57" r="35"/>
            <path class="art-line" d="M132 36v23l15 10"/>
            <polyline class="art-line" points="9,105 35,99 58,83 78,87 99,65 119,72 139,61 166,47"/>
          </svg>
        </div>
      </div>
      <div class="col-12 col-xl-4">
        <div class="vi-card">
          <div class="vi-head">
            <span class="ico"><i class="bi bi-globe2"></i></span>
            <h2>Top Countries<small>Share of tracked video views</small></h2>
            <span class="vi-head-btn">View Details <i class="bi bi-arrow-right"></i></span>
          </div>
          <div id="vsCountries">
            <div class="vi-empty">Waiting for live statistics...</div>
          </div>
        </div>
      </div>
    </section>

    <!-- ═══════ TRENDING + SPIKES ═══════ -->
    <section class="row g-3 mb-3" aria-label="Trending and spikes">
      <div class="col-12 col-lg-6"><div class="vi-card">
        <div class="vi-head"><span class="ico"><i class="bi bi-trophy-fill"></i></span><h2>Now Trending<small>Last hour</small></h2></div>
        <ul id="vsTrending" class="vi-list"><li class="vi-empty">Additional live trending data is unavailable.</li></ul>
      </div></div>
      <div class="col-12 col-lg-6"><div class="vi-card">
        <div class="vi-head"><span class="ico"><i class="bi bi-lightning-charge-fill"></i></span><h2>Spike Warnings<small>Compared with normal activity</small></h2></div>
        <ul id="vsSpikes" class="vi-list"><li class="vi-empty">No live spike data is available.</li></ul>
      </div></div>
    </section>
    <div class="small text-muted text-end" id="vsInsightsUpdated"></div>
  </div>
</main>
<script>
(function () {
  'use strict';
  var api = 'dashboard-insights-api.php';
  var statsApi = 'insights-stats.php';
  var REFRESH_MS = 30000;
  var csrf = <?php echo json_encode($insightsCsrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var status = el('vsInsightsStatus');
  var searchTimer = 0, searchAbort = null, statsAbort = null, statusTimer = 0, lastOk = 0, statsFailed = false;

  function el(id) { return document.getElementById(id); }
  function mk(tag, cls, txt) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (txt != null) { n.textContent = txt; } return n; }
  function text(id, value) {
    var n = el(id); if (!n) return; var v = String(value);
    if (n.textContent === v) { return; }
    n.textContent = v; n.setAttribute('data-set', '1');
  }
  function say(kind, msg, auto) {
    window.clearTimeout(statusTimer);
    status.className = 'alert alert-' + kind;
    status.textContent = msg;
    status.hidden = false;
    if (auto) { statusTimer = window.setTimeout(function () { status.hidden = true; }, 4000); }
  }
  function list(id, rows, empty, render) {
    var node = el(id); if (!node) return;
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
  function shortNum(n) {
    n = Number(n) || 0;
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 10000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    if (n >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
    return n.toLocaleString();
  }
  function chip(txt, cls) { return mk('span', 'vi-chip ' + cls, txt); }
  function main(parts) { var m = mk('div', 'main'); parts.forEach(function (p) { m.appendChild(p); }); return m; }
  function line(strong, sub) {
    var d = mk('div');
    if (strong && strong.nodeType) { d.appendChild(strong); } else { d.appendChild(mk('div', null, strong)); }
    if (sub) { d.appendChild(mk('div', 'vi-sub', sub)); }
    return d;
  }

  /* People */
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

  /* ---- Views Overview Chart ---- */
  function drawChart(chart, tracking) {
    var svg = el('vsChart');
    if (!svg) return;
    var W = 700, H = 220, padL = 50, padR = 20, padT = 10, padB = 30;
    var chartW = W - padL - padR, chartH = H - padT - padB;
    var values = chart && Array.isArray(chart.values) ? chart.values : [];
    var lastValues = chart && Array.isArray(chart.previous) ? chart.previous : [];
    var labels = chart && Array.isArray(chart.labels) ? chart.labels : [];
    var days = Math.min(values.length, labels.length);
    var emptyMessage = tracking && tracking.views === false
      ? 'View tracking is not configured.'
      : (tracking ? 'No views recorded in this period yet.' : 'Waiting for live statistics...');
    if (days < 2) {
      svg.innerHTML = '<text class="chart-empty" x="' + (W / 2) + '" y="' + (H / 2) + '" text-anchor="middle">' + emptyMessage + '</text>';
      return;
    }
    values = values.slice(-days).map(function (value) { return Number(value) || 0; });
    labels = labels.slice(-days);
    lastValues = lastValues.slice(-days).map(function (value) { return Number(value) || 0; });
    while (lastValues.length < days) lastValues.unshift(0);

    var maxVal = Math.max.apply(null, values.concat(lastValues));
    if (maxVal <= 0) {
      svg.innerHTML = '<text class="chart-empty" x="' + (W / 2) + '" y="' + (H / 2) + '" text-anchor="middle">' + emptyMessage + '</text>';
      return;
    }
    maxVal = Math.max(maxVal, 1);
    var niceMax = Math.pow(10, Math.floor(Math.log10(maxVal)));
    niceMax = Math.ceil(maxVal / niceMax) * niceMax;

    var html = '';
    html += '<defs><linearGradient id="chartAreaGrad" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ff2a32" stop-opacity=".4"/><stop offset="1" stop-color="#ff2a32" stop-opacity=".02"/></linearGradient></defs>';

    var gridSteps = 5;
    for (var g = 0; g <= gridSteps; g++) {
      var yy = padT + (chartH / gridSteps) * g;
      var val = niceMax - (niceMax / gridSteps) * g;
      html += '<line class="grid-line" x1="' + padL + '" y1="' + yy + '" x2="' + (W - padR) + '" y2="' + yy + '"/>';
      html += '<text x="' + (padL - 8) + '" y="' + (yy + 4) + '" text-anchor="end">' + shortNum(val) + '</text>';
    }

    var xStep = Math.max(1, Math.floor((days - 1) / 4));
    for (var i = 0; i < days; i += xStep) {
      var xx = padL + (i / (days - 1)) * chartW;
      html += '<text x="' + xx + '" y="' + (H - 4) + '" text-anchor="middle">' + labels[i] + '</text>';
    }
    if ((days - 1) % xStep !== 0) {
      html += '<text x="' + (padL + chartW) + '" y="' + (H - 4) + '" text-anchor="middle">' + labels[days - 1] + '</text>';
    }

    function px(i) { return padL + (i / (days - 1)) * chartW; }
    function py(v) { return padT + chartH - (v / niceMax) * chartH; }

    var areaPoints = padL + ',' + (padT + chartH);
    for (var i = 0; i < days; i++) areaPoints += ' ' + px(i).toFixed(1) + ',' + py(values[i]).toFixed(1);
    areaPoints += ' ' + px(days - 1).toFixed(1) + ',' + (padT + chartH);
    html += '<polygon fill="url(#chartAreaGrad)" points="' + areaPoints + '"/>';

    var lastPts = '';
    for (var i = 0; i < days; i++) lastPts += (i ? ' ' : '') + px(i).toFixed(1) + ',' + py(lastValues[i]).toFixed(1);
    html += '<polyline class="chart-line-last" points="' + lastPts + '"/>';

    var mainPts = '';
    for (var i = 0; i < days; i++) mainPts += (i ? ' ' : '') + px(i).toFixed(1) + ',' + py(values[i]).toFixed(1);
    html += '<polyline class="chart-line" points="' + mainPts + '"/>';

    for (var i = 0; i < days; i++) {
      if (i % Math.max(1, Math.floor(days / 6)) === 0 || i === days - 1) {
        html += '<circle class="chart-dot" cx="' + px(i).toFixed(1) + '" cy="' + py(values[i]).toFixed(1) + '" r="4"/>';
      }
    }

    svg.innerHTML = html;
  }

  /* ---- Top Content renderer ---- */
  function renderTopContent(trending, tracking) {
    var container = el('vsTopContent');
    if (!container) return;
    container.innerHTML = '';
    var items = (trending || []).slice(0, 3);
    if (!items.length) {
      var emptyMessage = tracking && tracking.views === false
        ? 'View tracking is not configured.'
        : (tracking ? 'No trending content in this period yet.' : 'Waiting for live statistics...');
      container.appendChild(mk('div', 'vi-empty', emptyMessage));
      return;
    }
    items.forEach(function (item, i) {
      var row = mk('div', 'vi-top-item');

      var rank = mk('span', 'vi-rank', String(i + 1));
      row.appendChild(rank);

      var thumb = mk('div', 'vi-top-thumb');
      function showThumbnailPlaceholder() {
        thumb.classList.add('is-placeholder');
        thumb.appendChild(mk('i', 'bi bi-play-fill'));
      }
      var thumbnailUrl = String(item.thumb || '').trim();
      if (thumbnailUrl) {
        var img = document.createElement('img');
        img.alt = '';
        img.loading = 'lazy';
        img.onerror = function () {
          img.remove();
          showThumbnailPlaceholder();
        };
        img.src = thumbnailUrl;
        thumb.appendChild(img);
      } else {
        showThumbnailPlaceholder();
      }
      row.appendChild(thumb);

      var info = mk('div', 'vi-top-info');
      info.appendChild(mk('div', 'title', item.title || ('Untitled ' + String(item.type || 'video').toLowerCase())));
      info.appendChild(mk('div', 'meta', shortNum(Number(item.views) || 0) + ' views \u2022 ' + (item.creator || item.type || 'Content')));
      row.appendChild(info);

      var views = mk('span', 'vi-top-views');
      views.appendChild(mk('i', 'bi bi-eye-fill', null));
      views.appendChild(document.createTextNode(shortNum(Number(item.views) || 0)));
      row.appendChild(views);
      container.appendChild(row);
    });
  }

  /* ---- Top Countries renderer ---- */
  function renderCountries(data) {
    var container = el('vsCountries');
    if (!container) return;
    container.innerHTML = '';

    var countries = data && Array.isArray(data.countries) ? data.countries.slice(0, 4) : [];
    if (!countries.length) {
      var tracking = data && data.tracking;
      var emptyMessage = !tracking
        ? 'Waiting for live statistics...'
        : (tracking.countries
          ? 'No viewer-country data has been recorded for this period yet.'
          : 'Local GeoIP is not ready. Configure the GeoLite2 Country database to track countries.');
      container.appendChild(mk('div', 'vi-empty', emptyMessage));
      return;
    }

    var flags = {
      'PK': '🇵🇰', 'IN': '🇮🇳', 'US': '🇺🇸', 'GB': '🇬🇧', 'CA': '🇨🇦', 'AU': '🇦🇺',
      'DE': '🇩🇪', 'FR': '🇫🇷', 'SA': '🇸🇦', 'AE': '🇦🇪', 'BD': '🇧🇩', 'TR': '🇹🇷',
      '??': '🌍'
    };

    countries.forEach(function (c) {
      var row = mk('div', 'vi-country');
      var flag = mk('span', 'vi-country-flag', flags[c.code] || '🌍');
      var name = mk('span', 'vi-country-name', c.name);
      var barWrap = mk('div', 'vi-country-bar');
      var fill = mk('i');
      barWrap.appendChild(fill);
      var pct = mk('span', 'vi-country-pct', c.pct + '%');

      row.appendChild(flag);
      row.appendChild(name);
      row.appendChild(barWrap);
      row.appendChild(pct);
      container.appendChild(row);

      requestAnimationFrame(function () { fill.style.width = c.pct + '%'; });
    });
  }

  function setTotal(id, value) {
    text(id, value === null || value === undefined ? '—' : shortNum(value));
  }
  function setTrend(id, value) {
    var node = el(id);
    if (!node) return;
    var isKpi = id.indexOf('vsKpi') === 0;
    if (value === null || value === undefined) {
      node.className = isKpi ? 'kpi-trend flat' : 'big-trend flat';
      node.textContent = 'N/A';
      return;
    }
    var amount = Number(value), positive = amount >= 0;
    node.className = isKpi ? 'kpi-trend ' + (positive ? 'up' : 'down') : 'big-trend' + (positive ? '' : ' down');
    node.textContent = (positive ? '↑ +' : '↓ ') + Math.abs(amount).toFixed(1) + '%';
  }
  function renderStats(data) {
    var totals = data.totals || {};
    setTotal('vsKpiViews', totals.views);
    setTotal('vsKpiLikes', totals.likes);
    setTotal('vsKpiComments', totals.comments);
    setTotal('vsKpiSubs', totals.subscribers);
    setTrend('vsKpiViewsTrend', totals.views_trend);
    setTrend('vsKpiLikesTrend', totals.likes_trend);
    setTrend('vsKpiCommentsTrend', totals.comments_trend);
    setTrend('vsKpiSubsTrend', totals.subs_trend);
    setTotal('vsAudienceVal', totals.subscribers);
    text('vsAudienceLabel', data.tracking && data.tracking.subscribers ? 'Total Subscribers' : 'Subscription tracking is not configured');
    setTrend('vsAudienceTrend', totals.subs_trend);
    setTotal('vsWatchVal', totals.watch_hours);
    text('vsWatchLabel', data.tracking && data.tracking.watch_time
      ? (totals.watch_hours === null || totals.watch_hours === undefined ? 'No watch-time data is stored yet' : 'Hours Watched')
      : 'Watch-time tracking is not configured');
    setTrend('vsWatchTrend', totals.watch_trend);
    drawChart(data.chart, data.tracking);
    renderTopContent(data.top_content, data.tracking);
    renderCountries(data);
    lastOk = Date.now();
    statsFailed = false;
    text('vsInsightsUpdated', 'Database statistics · last refreshed ' + new Date().toLocaleTimeString());
    if (status.classList.contains('alert-info') || status.classList.contains('alert-danger')) status.hidden = true;
    badge();
  }
  function loadStats() {
    if (statsAbort) statsAbort.abort();
    statsAbort = new AbortController();
    fetch(statsApi + '?period=' + encodeURIComponent(el('vsPeriod').value), {
      cache: 'no-store',
      credentials: 'same-origin',
      signal: statsAbort.signal
    })
      .then(function (response) {
        return response.json().then(function (data) {
          if (!response.ok || !data.ok) throw new Error(data.error || 'Insights could not be loaded.');
          return data;
        });
      })
      .then(renderStats)
      .catch(function (error) {
        if (error.name !== 'AbortError') {
          statsFailed = true;
          badge();
          text('vsInsightsUpdated', lastOk
            ? 'Live data unavailable · last successful refresh ' + new Date(lastOk).toLocaleTimeString()
            : 'No live statistics have been received yet.');
          say('danger', error.message);
          console.error('Insights stats refresh failed.', error);
        }
      });
  }

  function render(data) {
    /* ---- KPI cards ---- */
    var totalViews = 0, totalLikes = 0, totalComments = 0, totalSubs = 0;

    // try to get totals from data
    if (data.totals) {
      totalViews = Number(data.totals.views || 0);
      totalLikes = Number(data.totals.likes || 0);
      totalComments = Number(data.totals.comments || 0);
      totalSubs = Number(data.totals.subscribers || data.totals.users || 0);
    }

    // fallback: sum from trending / compute from available data
    if (!totalViews && data.live && data.live.trending) {
      data.live.trending.forEach(function(t) { totalViews += Number(t.views) || 0; });
    }

    text('vsKpiViews', shortNum(totalViews));
    text('vsKpiLikes', shortNum(totalLikes));
    text('vsKpiComments', shortNum(totalComments));
    text('vsKpiSubs', shortNum(totalSubs));

    // trends
    var vt = data.totals && data.totals.views_trend ? data.totals.views_trend : null;
    var lt = data.totals && data.totals.likes_trend ? data.totals.likes_trend : null;
    var ct = data.totals && data.totals.comments_trend ? data.totals.comments_trend : null;
    var st = data.totals && data.totals.subs_trend ? data.totals.subs_trend : null;

    function setTrend(elId, val) {
      var node = el(elId);
      if (!node) return;
      if (val !== null && val !== undefined) {
        var n = Number(val);
        node.className = 'kpi-trend ' + (n >= 0 ? 'up' : 'flat');
        node.textContent = (n >= 0 ? '↑ +' : '↓ ') + Math.abs(n).toFixed(1) + '%';
      } else {
        node.className = 'kpi-trend flat';
        node.textContent = '';
      }
    }
    setTrend('vsKpiViewsTrend', vt);
    setTrend('vsKpiLikesTrend', lt);
    setTrend('vsKpiCommentsTrend', ct);
    setTrend('vsKpiSubsTrend', st);

    /* ---- Chart ---- */
    drawChart(data.chart_views || null);

    /* ---- Top Content ---- */
    renderTopContent(data.live ? data.live.trending : []);

    /* ---- Audience Growth ---- */
    text('vsAudienceVal', shortNum(totalSubs));
    var audNode = el('vsAudienceTrend');
    if (audNode) {
      if (st !== null && st !== undefined) {
        audNode.textContent = '↑ +' + Math.abs(Number(st)).toFixed(1) + '%';
      } else {
        audNode.textContent = '';
      }
    }

    /* ---- Watch Time ---- */
    var watchTime = data.totals && data.totals.watch_hours ? Number(data.totals.watch_hours) : 0;
    text('vsWatchVal', shortNum(watchTime));
    var wtNode = el('vsWatchTrend');
    if (wtNode) {
      var wtt = data.totals && data.totals.watch_trend ? data.totals.watch_trend : null;
      if (wtt !== null) {
        wtNode.textContent = '↑ +' + Math.abs(Number(wtt)).toFixed(1) + '%';
      } else {
        wtNode.textContent = '';
      }
    }

    /* ---- Countries ---- */
    renderCountries(data);

    /* ---- Live KPIs (kept for compatibility) ---- */
    text('vsLiveText', 'LIVE');

    /* ---- Trending ---- */
    list('vsTrending', data.live ? data.live.trending : [], 'No recent views yet.', function (row, item, i) {
      row.appendChild(mk('span', 'vi-rank', String(i + 1)));
      row.appendChild(chip(item.type, typeTone(item.type)));
      row.appendChild(main([line(person(item.creator), item.title || '#' + item.id)]));
      row.appendChild(mk('span', 'end', Number(item.views).toLocaleString() + ' views'));
    });

    /* ---- Spikes ---- */
    var spikesEl = el('vsSpikes');
    if (spikesEl) {
      spikesEl.textContent = '';
      var spikes = data.live ? data.live.spikes : [];
      if (!spikes.length) {
        spikesEl.appendChild(mk('li', 'vi-empty', 'No unusual signup or upload pattern detected.'));
      } else {
        spikes.forEach(function (item) {
          var li = mk('li'), box = mk('div', 'vi-warnbox');
          box.appendChild(mk('i', 'bi bi-exclamation-triangle-fill'));
          box.appendChild(mk('span', null, item.message));
          li.appendChild(box); spikesEl.appendChild(li);
        });
      }
    }

    /* ---- Rising ---- */
    list('vsRising', data.live ? data.live.rising : [], 'Not enough recent view history yet.', function (row, item, i) {
      var up = item.change >= 0;
      row.appendChild(mk('span', 'vi-rank', String(i + 1)));
      row.appendChild(main([line(person(item.creator), Number(item.current).toLocaleString() + ' views this week')]));
      row.appendChild(chip((up ? '▲ +' : '▼ ') + item.change + '%', up ? 't-g' : 't-r'));
    });

    /* ---- Storage ---- */
    var storage = Object.keys(data.storage || {}).map(function (name) { return {name: name, bytes: Number(data.storage[name]) || 0}; })
      .sort(function (a, b) { return b.bytes - a.bytes; });
    var maxB = storage.length ? Math.max(1, storage[0].bytes) : 1;
    list('vsStorage', storage, 'No local uploaded files could be attributed to creators.', function (row, item) {
      row.style.flexDirection = 'column'; row.style.alignItems = 'stretch'; row.style.gap = '5px';
      var t = mk('div', 'd-flex justify-content-between');
      t.appendChild(person(item.name)); t.appendChild(mk('strong', null, formatBytes(item.bytes)));
      var bar = mk('div', 'vi-bar'), fill = mk('i');
      bar.appendChild(fill); row.appendChild(t); row.appendChild(bar);
      requestAnimationFrame(function () { fill.style.width = (item.bytes / maxB * 100) + '%'; });
    });

    /* ---- Broken ---- */
    text('vsBrokenCount', data.broken.count + ' possible issue' + (data.broken.count === 1 ? '' : 's') + ' found.');
    list('vsBroken', data.broken.items, 'No broken uploads found.', function (row, item) {
      row.appendChild(chip(item.type, 't-r'));
      row.appendChild(main([line(item.title, item.reasons.join(', '))]));
    });

    /* ---- Spam ---- */
    list('vsSpamIps', data.spam.same_ip_accounts, data.spam.ip_hashing_enabled ? 'No repeated signup IP matches found yet.' : 'IP matching is unavailable.', function (row, item) {
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
    text('vsIpStatus', data.spam.ip_hashing_enabled ? 'IP matching is enabled.' : 'IP matching is disabled.');

    /* ---- Retention ---- */
    [['1', 'day1'], ['7', 'day7'], ['30', 'day30']].forEach(function (pair) {
      var value = data.retention.cohorts[pair[1]];
      text('vsRetention' + pair[0], value === null ? 'Not enough data yet' : value + '%');
      var barEl = el('vsRetention' + pair[0] + 'Bar');
      if (barEl) barEl.style.width = (value === null ? 0 : Math.max(0, Math.min(100, Number(value)))) + '%';
    });

    /* ---- Goals ---- */
    [['Signup', 'signups'], ['Upload', 'uploads']].forEach(function (pair) {
      var goal = data.goals[pair[1]], current = Number(goal.current || 0), target = Number(goal.target || 0);
      var pct = target > 0 ? Math.min(100, current / target * 100) : 0;
      text('vs' + pair[0] + 'GoalText', current.toLocaleString() + ' / ' + target.toLocaleString());
      text('vs' + pair[0] + 'GoalPct', target > 0 ? Math.round(pct) + '% of goal' : 'No goal set');
      var gBar = el('vs' + pair[0] + 'GoalBar');
      if (gBar) gBar.style.width = pct + '%';
      var targetInput = el('vs' + pair[0] + 'GoalTarget');
      if (targetInput && document.activeElement !== targetInput) { targetInput.value = target > 0 ? target : ''; }
    });

    /* ---- Announcement ---- */
    var announcementInput = document.querySelector('textarea[name="message"]');
    if (announcementInput && document.activeElement !== announcementInput) { announcementInput.value = data.announcement || ''; }
    if (document.activeElement !== el('vsSummaryEmail')) { el('vsSummaryEmail').value = data.summary.email || ''; }
    if (document.activeElement !== el('vsSummaryFrequency')) { el('vsSummaryFrequency').value = data.summary.frequency || 'off'; }

    /* ---- Notes ---- */
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
    if (!lastOk) { b.className = 'vi-live warn'; t.textContent = statsFailed ? 'DATA UNAVAILABLE' : 'WAITING FOR DATA'; return; }
    if (statsFailed) { b.className = 'vi-live warn'; t.textContent = 'DATA UNAVAILABLE'; return; }
    if (Date.now() - lastOk > REFRESH_MS * 2.5) { b.className = 'vi-live warn'; t.textContent = 'DATA STALE'; return; }
    b.className = 'vi-live';
    var ago = lastOk ? Math.round((Date.now() - lastOk) / 1000) : 0;
    t.textContent = lastOk ? 'LIVE \u00b7 ' + (ago < 2 ? 'just now' : ago + 's ago') : 'LIVE';
  }
  window.setInterval(badge, 1000);
  el('vsPeriod').addEventListener('change', function () { refresh(true); });

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
    loadStats();
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

  var clearBtn = el('vsClearAnnouncement');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      var ta = document.querySelector('textarea[name="message"]');
      ta.value = '';
      ta.form.requestSubmit();
    });
  }

  var globalSearch = el('vsGlobalSearch');
  if (globalSearch) {
    globalSearch.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(search, 250);
    });
  }
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
        }
      });
  }

  // Draw the empty state until real statistics arrive.
  drawChart(null, null);
  renderCountries(null);

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
