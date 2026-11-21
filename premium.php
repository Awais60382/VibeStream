<?php
include __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar-navigation.php';
$formatPlanLimit = static function (string $tier, string $key): string {
    $limit = (int)VS_PREMIUM_LIMITS[$tier][$key];
    return $limit >= 100000 ? 'Unlimited' : number_format($limit);
};
?>
<style>
.pm-layout{display:flex;align-items:flex-start}
.pm-layout>.app-sidebar{flex:0 0 206px;width:206px;position:sticky!important;top:74px!important;left:auto!important;height:calc(100vh - 74px);overflow-y:auto;transform:none!important;z-index:1}
.pm-layout .sidebar-top{display:none}
.pm-layout .app-sidebar{border-right:1px solid rgba(128,128,128,.25);padding:8px 0;box-sizing:border-box}
.pm-layout .app-sidebar a{color:inherit;text-decoration:none}
.pm-layout .sidebar-block{display:block;padding:8px 12px;border-bottom:1px solid rgba(128,128,128,.25)}
.pm-layout .sidebar-block-title{display:flex;align-items:center;gap:6px;margin:0;padding:10px 12px;font-size:16px;font-weight:700}
.pm-layout .sidebar-link{display:flex;align-items:center;gap:22px;padding:10px 12px;border-radius:12px;font-size:14px;line-height:1.3}
.pm-layout .sidebar-link:hover{background:rgba(128,128,128,.16)}
.pm-layout .sidebar-link.active{background:rgba(128,128,128,.24);font-weight:700}
.pm-layout .sidebar-icon{display:inline-flex;flex:0 0 24px;width:24px;height:24px}
.pm-layout .sidebar-icon svg{width:24px;height:24px}
.pm-layout .sidebar-icon img{width:24px;height:24px;object-fit:contain}
.pm-layout .sidebar-account{display:flex;align-items:center;gap:10px;padding:14px 20px}
.pm-layout .sidebar-account-copy{display:flex;flex-direction:column;line-height:1.25;min-width:0}
.pm-layout .sidebar-account-copy small{opacity:.65}
.pm-layout .sidebar-account img,.pm-layout .sidebar-account-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover}
@media (max-width:991px){.pm-layout>.app-sidebar{display:none}}
.pm-page{flex:1;min-width:0;max-width:1240px;margin:0 auto;padding:14px clamp(12px,1.4vw,20px) 48px;color:inherit}
.pm-hero{position:relative;overflow:hidden;display:grid;grid-template-columns:minmax(0,1.2fr) minmax(200px,.8fr);align-items:center;gap:10px;min-height:194px;padding:20px 30px 16px;border:1px solid rgba(90,115,255,.55);border-radius:20px;background:radial-gradient(ellipse at 2% 5%,rgba(0,150,255,.8),transparent 30%),radial-gradient(ellipse at 75% 100%,rgba(142,0,255,.78),transparent 48%),linear-gradient(108deg,#0647db 0%,#2010a0 44%,#4a0b9a 70%,#db078d 100%);color:#fff;box-shadow:0 12px 32px -18px rgba(0,94,255,.8)}
.pm-badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border:1px solid #f6c900;border-radius:999px;background:rgba(4,8,35,.55);color:#ffd400;font-size:12px;font-weight:800;letter-spacing:.08em}
.pm-badge svg{width:18px;height:18px}
.pm-hero h1{margin:6px 0 6px;max-width:none;font-size:clamp(34px,4.2vw,56px);line-height:1;font-weight:900;letter-spacing:-.03em}
.pm-hero h1 b{color:#ffd400;font-weight:900}
.pm-lead{max-width:58ch;margin:0 0 14px;font-size:clamp(14px,1.35vw,18px);line-height:1.4;color:#f1eeff;opacity:1}
.pm-lead em{font-style:normal;color:#fff}
.pm-perks{display:flex;flex-wrap:wrap;gap:10px 0;margin:0;padding:0;list-style:none}
.pm-perks li{display:flex;align-items:center;gap:8px;padding:0 14px;border-left:1px solid rgba(255,255,255,.4);font-size:13px;font-weight:600;white-space:nowrap}
.pm-perks li:first-child{padding-left:0;border-left:0}
.pm-perks svg{width:24px;height:24px;flex:0 0 24px}
.pm-art{justify-self:center;width:min(100%,330px);height:auto;filter:drop-shadow(0 0 24px rgba(255,50,180,.55))}
.pm-status{display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-radius:14px;margin-bottom:24px;background:linear-gradient(135deg,#f5b942,#ff8a3d);color:#2a1700;font-weight:600}
.vs-premium .pm-status{display:flex}
.pm-status button{background:#2a1700;color:#fff;border:0;border-radius:10px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer}
.pm-toggle{display:flex;width:max-content;max-width:100%;margin:14px auto 16px;padding:4px;border:1px solid rgba(82,110,220,.45);border-radius:999px;background:#101a3b;box-shadow:0 5px 22px -14px #4167ff;color:#eaf0ff}
.pm-toggle button{display:inline-flex;align-items:center;gap:8px;border:0;background:none;color:inherit;opacity:.8;padding:10px 24px;border-radius:999px;font:inherit;font-weight:700;cursor:pointer}
.pm-toggle button[aria-pressed=true]{background:linear-gradient(100deg,#a500ff,#5d24ff);color:#fff;opacity:1}
.pm-chip{padding:3px 10px;border-radius:999px;background:#ffd400;color:#1a1530;font-size:12px;font-weight:800}
.pm-plans{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;align-items:stretch}
.pm-plan{position:relative;display:flex;flex-direction:column;min-height:232px;padding:16px 20px 14px;border:1px solid rgba(81,112,220,.5);border-radius:19px;background:linear-gradient(145deg,rgba(28,43,91,.9),rgba(8,15,39,.96));box-shadow:0 10px 24px -20px rgba(97,129,255,.75);color:#f4f6ff}
.pm-plan h2{margin:0;font-size:24px;font-weight:800}
.pm-sub{display:none}
.pm-price{display:flex;align-items:baseline;gap:8px;margin:0 0 4px;font-size:40px;font-weight:850;line-height:1}
.pm-price small{font-size:14px;font-weight:500;opacity:.78}
.pm-save{display:block;min-height:8px;font-size:12px;font-weight:600;color:#ffe060}
.pm-plan ul{flex:1;list-style:none;margin:6px 0 10px;padding:0}
.pm-plan li{position:relative;padding:3px 0 3px 26px;font-size:14px;line-height:1.25}
.pm-plan li:before{content:"";position:absolute;left:3px;top:7px;width:12px;height:7px;border-left:3px solid #8da6ff;border-bottom:3px solid #8da6ff;transform:rotate(-45deg)}
.pm-plan li.pm-no{opacity:.55}
.pm-plan li.pm-no:before{height:0;border-left:0;transform:none;top:17px;border-color:currentColor}
.pm-plan.pm-pro{border:2px solid #ffd000;background:radial-gradient(circle at 80% 25%,rgba(255,165,25,.25),transparent 52%),linear-gradient(145deg,#35200e,#171426 75%);box-shadow:0 0 20px -4px rgba(255,208,0,.8),inset 0 0 18px -10px #ffcd00}
.pm-plan.pm-plus{border:2px solid #c000ff;background:linear-gradient(145deg,#251052,#100a2e);box-shadow:0 0 22px -7px rgba(196,0,255,.9)}
.pm-popular{position:absolute;top:-14px;left:50%;transform:translateX(-50%);padding:5px 16px;border-radius:999px;background:#ffd400;color:#17111f;font-size:13px;font-weight:800;white-space:nowrap}
.pm-vip-chip{position:absolute;right:14px;top:12px;padding:5px 11px;border-radius:999px;background:linear-gradient(90deg,#b600ff,#6d20ff);font-size:12px;font-weight:800}
.pm-vip-chip svg{width:16px;height:16px;vertical-align:-3px}
.pm-btn{width:100%;border:0;border-radius:999px;padding:11px 14px;font:inherit;font-weight:800;font-size:14px;cursor:pointer;background:linear-gradient(90deg,#ff9b00,#ffdf00);color:#201000}
.pm-btn:not(:disabled):not(.pm-ghost)::after{content:" \2192"}
.pm-btn.pm-btn-plus{background:linear-gradient(90deg,#a500ff,#701bff);color:#fff}
.pm-btn:disabled,.pm-btn.pm-btn-plus:disabled{background:rgba(128,128,128,.25);color:inherit;opacity:.7;cursor:default}
.pm-btn:focus-visible,.pm-toggle button:focus-visible,.pm-status button:focus-visible,.pm-rewards-link:focus-visible{outline:3px solid #f5b942;outline-offset:2px}
.pm-rewards{display:grid;grid-template-columns:minmax(240px,1.7fr) repeat(4,minmax(90px,.55fr)) auto;align-items:center;gap:0;margin-top:16px;padding:12px 14px;border:2px solid #cb00ff;border-radius:19px;background:radial-gradient(ellipse at 4% 100%,rgba(255,46,147,.35),transparent 34%),linear-gradient(105deg,#15043d,#17094a 56%,#28045c);box-shadow:0 0 25px -5px rgba(205,0,255,.82),inset 0 0 24px -15px #fd35ff;color:#fff}
.pm-rewards-intro{display:flex;align-items:center;gap:14px;padding-right:14px}
.pm-gift{width:78px;height:72px;flex:0 0 78px;filter:drop-shadow(0 0 12px rgba(255,193,35,.7))}
.pm-rewards h2{margin:0 0 3px;font-size:20px}
.pm-rewards p{margin:0;color:#e4d7ff;font-size:12px;line-height:1.35}
.pm-reward-perk{display:grid;justify-items:center;gap:3px;align-content:center;min-height:70px;padding:0 10px;border-left:1px solid rgba(183,141,255,.6);text-align:center;font-size:12px;font-weight:700}
.pm-reward-perk svg{width:32px;height:32px;filter:drop-shadow(0 0 8px rgba(255,200,0,.5))}
.pm-rewards-link{display:inline-flex;align-items:center;gap:8px;margin-left:12px;padding:12px 17px;border-radius:999px;background:linear-gradient(90deg,#a500ff,#731cff);color:#fff;font-size:13px;font-weight:800;text-decoration:none;white-space:nowrap}
.pm-rewards-link:hover{filter:brightness(1.12)}
.pm-trust{display:flex;flex-wrap:wrap;justify-content:center;width:100%;max-width:100%;margin:14px auto 0;padding:9px 6px;border:1px solid rgba(74,101,178,.32);border-radius:999px;background:rgba(15,25,57,.8);font-size:13px;color:#eaf0ff}
.pm-trust span{display:flex;align-items:center;gap:8px;padding:0 20px;border-left:1px solid rgba(74,101,178,.5)}
.pm-trust span:first-child{border-left:0}
.pm-trust svg{width:20px;height:20px;opacity:.8}
.pm-note{margin-top:18px;font-size:13px;opacity:.65;max-width:70ch}
@media(max-width:1050px){.pm-rewards{grid-template-columns:repeat(4,minmax(0,1fr));row-gap:8px}.pm-rewards-intro{grid-column:span 2}.pm-rewards-link{grid-column:1/-1;justify-self:center;margin:4px 0 0}}
@media(max-width:900px){.pm-hero{grid-template-columns:1fr;padding:22px 18px}.pm-art{display:none}.pm-plans{grid-template-columns:1fr;gap:30px}}
@media(max-width:620px){.pm-rewards{grid-template-columns:repeat(2,minmax(0,1fr));padding:12px}.pm-rewards-intro{grid-column:1/-1}.pm-reward-perk:nth-of-type(2){border-left:0}.pm-trust{border-radius:20px}.pm-trust span{padding:6px 14px}}
.pm-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);background:#f3f0fa;color:#17141f;padding:12px 20px;border-radius:12px;opacity:0;pointer-events:none;transition:.25s;z-index:80;max-width:90vw;text-align:center}
.pm-toast.show{opacity:1;transform:translate(-50%,0)}
.pm-modal{position:fixed;inset:0;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;padding:16px;z-index:70}
.pm-modal[hidden]{display:none}
.pm-box{background:#1b1b1f;color:#f3f0fa;border:1px solid rgba(128,128,128,.35);border-radius:20px;padding:26px;width:min(460px,100%);max-height:90vh;overflow-y:auto}
.pm-box h2{margin:0 0 6px;font-size:22px}
.pm-box p{margin:8px 0}
.pm-box ul{margin:8px 0 16px;padding-left:18px}
.pm-box li{margin:4px 0}
.pm-box label{display:block;font-weight:600;margin-bottom:6px}
.pm-box input{width:100%;box-sizing:border-box;padding:12px;border-radius:10px;border:1px solid rgba(128,128,128,.45);background:#0d0d10;color:inherit;font:inherit}
.pm-row{display:flex;gap:10px;margin-top:18px}
.pm-row .pm-btn{flex:1}
.pm-btn.pm-ghost{background:rgba(128,128,128,.28);color:inherit}
.pm-small{font-size:13px;opacity:.7}
.pm-history{margin-top:30px}
.pm-history h2{font-size:20px;margin:0 0 10px}
.pm-hrow{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:10px 0;border-top:1px solid rgba(128,128,128,.25);font-size:14px}
.pm-hrow a{color:#f5b942;font-weight:600}
.pm-reward-panel{margin-top:22px;border-color:rgba(245,185,66,.65)}
.pm-reward-panel p{line-height:1.5}
.pm-reward-balance{font-size:18px}
.pm-checkin{margin:14px 0}
@media (prefers-reduced-motion:reduce){.pm-toast{transition:none}}

/* ===== NEON THEME (dark + light) - same look as creator-analytics.php ===== */
html:not([data-theme="light"]){
  --bg:#050918;--surface:#0b1533;--surface-2:#121d43;--surface-3:#182654;
  --text:#eaf0ff;--muted:#a2afd7;--border:#1d2b63;
  --accent:#ff2e93;--accent-hover:#171c42;--accent-soft:rgba(255,46,147,.18);--blue:#00e5ff;
  --neon-cyan:#00e5ff;--neon-pink:#ff2e93;--neon-violet:#8a5cff;
  --nx-cyan:#00e5ff;--nx-pink:#ff2e93;--nx-violet:#8a5cff;
  --nx-cyan-rgb:0,229,255;--nx-pink-rgb:255,46,147;--nx-violet-rgb:138,92,255;
  --nx-cyan-ink:#00e5ff;--nx-pink-ink:#ff7ac0;
  --nx-text:#eaf0ff;--nx-card:rgba(13,22,50,.92);--nx-solid:#080e23;--nx-field:rgba(10,18,42,.95);
  --nx-nav:#050918;--nx-side:linear-gradient(180deg,#0b1533,#050918);
  --nx-track:#050918;--nx-sun:#b9c9ff;--nx-active:#fff;--nx-backdrop:rgba(5,9,24,.82);
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(32,85,255,.15),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,229,255,.08),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.07),transparent 70%),#050918;
}
html[data-theme="light"]{
  --bg:#f4f6ff;--surface:#ffffff;--surface-2:#eceffd;--surface-3:#e3e8fc;
  --text:#0b1030;--muted:#55608f;--border:#cfd6f7;
  --accent:#ff2e93;--accent-hover:#e3e8fc;--accent-soft:rgba(255,46,147,.12);--blue:#00b8e6;
  --neon-cyan:#00b8e6;--neon-pink:#ff2e93;--neon-violet:#7c4dff;
  --nx-cyan:#00b8e6;--nx-pink:#ff2e93;--nx-violet:#7c4dff;
  --nx-cyan-rgb:0,184,230;--nx-pink-rgb:255,46,147;--nx-violet-rgb:124,77,255;
  --nx-cyan-ink:#0086b3;--nx-pink-ink:#d4147a;
  --nx-text:#0b1030;--nx-card:#ffffff;--nx-solid:#ffffff;--nx-field:#ffffff;
  --nx-nav:rgba(255,255,255,.85);--nx-side:linear-gradient(180deg,#ffffff,#f4f6ff);
  --nx-track:#eceffd;--nx-sun:#7c4dff;--nx-active:#0b1030;--nx-backdrop:rgba(11,16,48,.55);
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(124,77,255,.14),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,184,230,.14),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.10),transparent 70%),#f4f6ff;
}

/* page + top bar */
html body{background:var(--nx-page);background-attachment:fixed}
html body .app-navbar{background:var(--nx-nav);border-bottom:1px solid rgba(var(--nx-cyan-rgb),.45);box-shadow:0 1px 24px -4px rgba(var(--nx-cyan-rgb),.4),0 8px 30px -12px rgba(var(--nx-violet-rgb),.4)}
html body .premium-rewards-navbar{background:#050918;color:var(--nx-text);border-bottom:1px solid rgba(66,91,170,.45);box-shadow:0 6px 24px -14px rgba(0,0,0,.8)}
html body .premium-rewards-navbar .vs-install-btn{display:none!important}
html body .premium-rewards-navbar .vs-user-toggle{gap:8px;min-height:40px;padding:4px 8px;background:transparent;border-color:transparent}
html body .premium-rewards-navbar .vs-user-name{display:none}
html body .premium-rewards-navbar .nav-right{gap:12px}
html body .premium-rewards-navbar .nav-bell,html body .premium-rewards-navbar .vs-theme-toggle{background:transparent;border-color:transparent;color:#aab8f0}
html body .premium-rewards-navbar .nav-bell:hover,html body .premium-rewards-navbar .vs-theme-toggle:hover{background:#101a3c;border-color:#273b78;color:#fff}
html body .brand{background:linear-gradient(90deg,var(--nx-cyan),var(--nx-violet) 55%,var(--nx-pink));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent;filter:drop-shadow(0 0 8px rgba(var(--nx-violet-rgb),.6))}
html body .nav-toggle span{background:var(--nx-cyan);box-shadow:0 0 6px rgba(var(--nx-cyan-rgb),.7)}
html body .nav-toggle:hover,html body .theme-toggle:hover{background:rgba(var(--nx-cyan-rgb),.14);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.4)}
html body .theme-toggle svg{color:var(--nx-sun);filter:drop-shadow(0 0 5px rgba(var(--nx-violet-rgb),.6))}
html body .nav-link.active{background:rgba(var(--nx-pink-rgb),.16);color:var(--nx-pink-ink);border-color:rgba(var(--nx-pink-rgb),.55);box-shadow:0 0 12px -2px rgba(var(--nx-pink-rgb),.5)}
html body .create-btn,html body .sign-in-btn{box-shadow:0 0 16px -2px rgba(var(--nx-pink-rgb),.55)}
html body #search-input{background:var(--nx-field);border:1px solid rgba(var(--nx-violet-rgb),.55);color:var(--nx-text);box-shadow:0 0 10px -2px rgba(var(--nx-violet-rgb),.45);transition:box-shadow .2s ease,border-color .2s ease}
html body #search-input:focus{outline:none;border-color:var(--nx-cyan);box-shadow:0 0 0 1px var(--nx-cyan),0 0 20px rgba(var(--nx-cyan-rgb),.55)}
html body .user-badge-pill{background:var(--nx-card);border:1px solid rgba(var(--nx-pink-rgb),.55);box-shadow:0 0 14px -3px rgba(var(--nx-pink-rgb),.45)}
html body .logout-link:hover{color:var(--nx-pink);text-shadow:0 0 10px rgba(var(--nx-pink-rgb),.7)}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:var(--nx-track)}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--nx-violet),var(--nx-pink));border-radius:8px}
html body ::selection{background:rgba(var(--nx-pink-rgb),.4);color:var(--nx-text)}

/* sidebar */
html body .pm-layout .app-sidebar{background:var(--nx-side);border-right:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:4px 0 28px -10px rgba(var(--nx-violet-rgb),.45)}
html body .pm-layout .sidebar-block{border-bottom:1px solid rgba(var(--nx-violet-rgb),.28)}
html body .pm-layout .sidebar-block-title,html body .pm-layout .sidebar-heading{color:var(--nx-cyan-ink);text-shadow:0 0 8px rgba(var(--nx-cyan-rgb),.45)}
html body .pm-layout .sidebar-link{transition:background .15s ease,box-shadow .15s ease,color .15s ease}
html body .pm-layout .sidebar-link:hover{background:rgba(var(--nx-cyan-rgb),.10);box-shadow:inset 3px 0 0 var(--nx-cyan),0 0 16px -4px rgba(var(--nx-cyan-rgb),.5)}
html body .pm-layout .sidebar-link.active{background:linear-gradient(90deg,rgba(var(--nx-pink-rgb),.22),rgba(var(--nx-violet-rgb),.12));color:var(--nx-active);box-shadow:inset 3px 0 0 var(--nx-pink),0 0 18px -4px rgba(var(--nx-pink-rgb),.55)}

/* premium page */
html body .pm-page h1{text-shadow:0 0 18px rgba(var(--nx-violet-rgb),.7)}
html body .pm-plan h2{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body .pm-hero{border-color:rgba(82,115,255,.7);box-shadow:0 12px 32px -18px rgba(0,94,255,.8),0 0 26px -16px rgba(205,0,255,.8)}
html body .pm-status{background:linear-gradient(135deg,rgba(var(--nx-pink-rgb),.92),rgba(var(--nx-violet-rgb),.92));color:#fff;border:1px solid rgba(var(--nx-pink-rgb),.7);box-shadow:0 0 24px -4px rgba(var(--nx-pink-rgb),.6)}
html body .pm-status button{background:var(--nx-solid);color:var(--nx-cyan-ink);border:1px solid var(--nx-cyan);box-shadow:0 0 12px -2px rgba(var(--nx-cyan-rgb),.55);transition:box-shadow .18s ease}
html body .pm-status button:hover{box-shadow:0 0 20px rgba(var(--nx-cyan-rgb),.8)}

html body .pm-modal{background:var(--nx-backdrop);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
html body .pm-box{background:var(--nx-solid);color:var(--nx-text);border:1px solid rgba(var(--nx-cyan-rgb),.6);box-shadow:0 0 36px -6px rgba(var(--nx-cyan-rgb),.55),0 0 70px -24px rgba(var(--nx-violet-rgb),.7)}
html body .pm-box h2{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body .pm-box input{background:var(--nx-field);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:0 0 10px -2px rgba(var(--nx-violet-rgb),.45);transition:box-shadow .2s ease,border-color .2s ease}
html body .pm-box input:focus{outline:none;border-color:var(--nx-cyan);box-shadow:0 0 0 1px var(--nx-cyan),0 0 20px rgba(var(--nx-cyan-rgb),.55)}
html body .pm-toast{background:var(--nx-solid);color:var(--nx-text);border:1px solid rgba(var(--nx-cyan-rgb),.6);box-shadow:0 0 24px -4px rgba(var(--nx-cyan-rgb),.55)}
html body .pm-history h2{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html body .pm-hrow{border-top:1px solid rgba(var(--nx-violet-rgb),.28)}
html body .pm-hrow a{color:var(--nx-cyan-ink);text-shadow:0 0 10px rgba(var(--nx-cyan-rgb),.5)}
@media (prefers-reduced-motion:reduce){html body .pm-plan,html body .pm-btn,html body .pm-toggle button{transition:none}html body .pm-plan:hover,html body .pm-btn:hover{transform:none}}
</style>

<div class="pm-layout">
<?php vs_render_sidebar('premium'); ?>
<main class="pm-page">
  <div class="pm-status">
    <span id="pmStatusText">Premium is active</span>
    <button type="button" id="pmCancel">Cancel Premium</button>
  </div>
  <section class="pm-hero">
    <div>
      <span class="pm-badge"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="m3 8 4.5 4L12 5l4.5 7L21 8l-2 11H5L3 8Zm2.2 12.5h13.6V22H5.2z"/></svg> PREMIUM</span>
      <h1>Get VibeStream <b>Premium</b></h1>
      <p class="pm-lead">Unlock more features. Enjoy an <em>ad-free</em> experience, and take your VibeStream experience to the next level!</p>
      <ul class="pm-perks">
        <li><svg viewBox="0 0 24 24" fill="none" stroke="#ff3b4e" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="m6 18 12-12"/></svg>No Ads</li>
        <li><svg viewBox="0 0 26 26"><rect x="1" y="5" width="24" height="16" rx="4" fill="#e5173f"/><text x="13" y="17" text-anchor="middle" font-size="10" font-weight="800" fill="#fff" font-family="Arial">4K</text></svg>4K Quality</li>
        <li><svg viewBox="0 0 24 24" fill="#ffb020"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>Priority Support</li>
        <li><svg viewBox="0 0 24 24" fill="#a45cff"><path d="M6 3h12l4 6-10 12L2 9z"/></svg>Exclusive Perks</li>
      </ul>
    </div>
    <svg class="pm-art" viewBox="0 0 320 260" aria-hidden="true"><defs><linearGradient id="pmR" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff477b"/><stop offset="1" stop-color="#e0004d"/></linearGradient><linearGradient id="pmG" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff28a"/><stop offset="1" stop-color="#ff9d00"/></linearGradient><filter id="pmGlow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="7" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter></defs><ellipse cx="165" cy="205" rx="136" ry="31" fill="none" stroke="#ffbd28" stroke-width="5" opacity=".9" transform="rotate(-16 165 205)"/><ellipse cx="165" cy="205" rx="136" ry="31" fill="none" stroke="#ffed57" stroke-width="2" opacity=".8" transform="rotate(-16 165 205)"/><rect x="66" y="68" width="194" height="132" rx="36" fill="url(#pmR)" stroke="#ff7895" stroke-width="3" filter="url(#pmGlow)"/><path d="M137 101v64l58-32z" fill="#fff"/><path d="M111 62l11-35 22 22 17-31 17 31 22-22 11 35z" fill="url(#pmG)" stroke="#ffe273" stroke-width="3"/><path d="m40 50 8 14 14 8-14 8-8 14-8-14-14-8 14-8z" fill="#ff5a7a"/></svg>
  </section>

  <div class="pm-toggle" role="group" aria-label="Billing period">
    <button type="button" id="pmW" aria-pressed="false">Weekly</button>
    <button type="button" id="pmM" aria-pressed="true">Monthly</button>
    <button type="button" id="pmY" aria-pressed="false">Yearly <span class="pm-chip" id="pmYSave" hidden></span></button>
  </div>

  <div class="pm-plans" id="pmPlans">
    <section class="pm-plan">
      <h2>Free</h2>
      <p class="pm-sub">Start watching</p>
      <div class="pm-price">$0</div>
      <span class="pm-save"></span>
      <ul>
        <li>Watch videos and Shorts</li>
        <li>Limited playlists</li>
        <li>Watch Later and daily uploads</li>
      </ul>
      <button class="pm-btn" type="button" disabled id="pmFree">Current plan</button>
    </section>
    <section class="pm-plan pm-pro">
      <span class="pm-popular">&#128293; Most Popular</span>
      <h2>Premium</h2>
      <p class="pm-sub">For viewers &amp; creators</p>
      <div class="pm-price"><span id="pmAmt">$4.99</span> <small id="pmPer">per month</small></div>
      <span class="pm-save" id="pmSave"></span>
      <ul>
        <li>Ad-free experience</li>
        <li>Private Creator Analytics</li>
        <li>VIP badge</li>
        <li>Eligible video downloads</li>
      </ul>
      <button class="pm-btn" type="button" id="pmBuy">Get Premium</button>
    </section>
    <section class="pm-plan pm-plus">
      <h2>Premium Plus</h2>
      <p class="pm-sub">For power users</p>
      <span class="pm-vip-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m12 2 3 7h7l-5.5 4.4 2 7.6L12 16.8 5.5 21l2-7.6L2 9h7z"/></svg> VIP+</span>
      <div class="pm-price"><span id="pmAmt2">$9.99</span> <small id="pmPer2">per month</small></div>
      <span class="pm-save" id="pmSave2"></span>
      <ul>
        <li>Everything in Premium</li>
        <li>VIP+ badge</li>
        <li>Higher limits</li>
      </ul>
      <button class="pm-btn pm-btn-plus" type="button" id="pmBuy2">Get Plus</button>
    </section>
  </div>
  <section class="pm-rewards" aria-labelledby="pmRewardsTitle">
    <div class="pm-rewards-intro">
      <svg class="pm-gift" viewBox="0 0 80 72" aria-hidden="true"><defs><linearGradient id="pmGiftBox" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#fff06a"/><stop offset="1" stop-color="#ff4c00"/></linearGradient><linearGradient id="pmGiftRibbon" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#ff48d8"/><stop offset="1" stop-color="#8f00ff"/></linearGradient></defs><path d="M8 28h64v39H8z" fill="url(#pmGiftBox)" stroke="#ffe45e" stroke-width="2"/><path d="M4 20h72v14H4z" fill="url(#pmGiftBox)" stroke="#ffe45e" stroke-width="2"/><path d="M35 20h10v47H35zM4 23h72v8H4z" fill="url(#pmGiftRibbon)"/><path d="M40 20C18 20 17 3 28 3c7 0 11 9 12 17Zm0 0C62 20 63 3 52 3c-7 0-11 9-12 17Z" fill="url(#pmGiftRibbon)" stroke="#ff9df2" stroke-width="2"/></svg>
      <div><h2 id="pmRewardsTitle">Premium Rewards</h2><p>As a Premium member, you get exclusive rewards.</p></div>
    </div>
    <div class="pm-reward-perk"><svg viewBox="0 0 32 32" fill="#ffcc24" aria-hidden="true"><path d="m16 2 4.2 8.7 9.6 1.4-6.9 6.7 1.6 9.5L16 23.8l-8.5 4.5 1.6-9.5-6.9-6.7 9.6-1.4z"/></svg><span>Exclusive<br>Badges</span></div>
    <div class="pm-reward-perk"><svg viewBox="0 0 32 32" fill="none" stroke="#ff4f8d" stroke-width="2.5" stroke-linejoin="round" aria-hidden="true"><path d="M16 28s-12-7-12-16a6 6 0 0 1 12-2 6 6 0 0 1 12 2c0 9-12 16-12 16z"/></svg><span>Monthly<br>Rewards</span></div>
    <div class="pm-reward-perk"><svg viewBox="0 0 32 32" fill="#ffbd27" aria-hidden="true"><path d="m3 12 7 5 6-12 6 12 7-5-3 17H6z"/></svg><span>Early Access</span></div>
    <div class="pm-reward-perk"><svg viewBox="0 0 32 32" fill="#b74dff" aria-hidden="true"><path d="M8 3h16l6 8-14 18L2 11z"/><path d="M2 11h28M11 3l5 26M21 3l-5 26" fill="none" stroke="#fff" stroke-opacity=".65" stroke-width="1.5"/></svg><span>Bonus Points</span></div>
    <a class="pm-rewards-link" href="premium-points.php">Explore Rewards <span aria-hidden="true">&rarr;</span></a>
  </section>
  <div class="pm-trust">
    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/></svg>Secure Payments</span>
    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>Your Data is Safe</span>
    <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>Cancel Premium anytime</span>
  </div>

  <p class="pm-note" id="pmNote"></p>
  <section class="pm-history" id="pmHistoryBox" hidden>
    <h2>Your payments</h2>
    <div id="pmHistory"></div>
  </section>
</main>
</div>

<div class="pm-modal" id="pmModal" hidden>
  <div class="pm-box" role="dialog" aria-modal="true" aria-labelledby="pmModalTitle">
    <h2 id="pmModalTitle">Pay for Premium</h2>
    <p id="pmModalPlan"></p>
    <p>Send the exact amount using one of these:</p>
    <ul id="pmPayList"></ul>
    <label for="pmRef">Transaction ID</label>
    <input id="pmRef" type="text" maxlength="100" autocomplete="off" placeholder="Paste the ID from your payment receipt">
    <p class="pm-small">Premium turns on after we confirm your payment.</p>
    <div class="pm-row">
      <button class="pm-btn pm-ghost" type="button" id="pmClose">Cancel</button>
      <button class="pm-btn" type="button" id="pmSend">I've paid</button>
    </div>
  </div>
</div>
<div class="pm-toast" id="pmToast" role="status"></div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var period = 'monthly', chosen = '', busy = false, $ = function (i) { return document.getElementById(i); };
  var PER = { weekly: 'per week', monthly: 'per month', yearly: 'per year' };
  var IDS = { weekly: 'pmW', monthly: 'pmM', yearly: 'pmY' };
  var KEY = {
    premium: { weekly: 'weekly', monthly: 'monthly', yearly: 'yearly' },
    plus: { weekly: 'plusweek', monthly: 'plusmonth', yearly: 'plusyear' }
  };
  var UI = {
    premium: { amt: 'pmAmt', per: 'pmPer', save: 'pmSave', buy: 'pmBuy' },
    plus: { amt: 'pmAmt2', per: 'pmPer2', save: 'pmSave2', buy: 'pmBuy2' }
  };
  function money(n) { return '$' + Number(n).toFixed(2); }
  function toast(t) { var e = $('pmToast'); e.textContent = t; e.classList.add('show'); setTimeout(function () { e.classList.remove('show'); }, 4500); }
  function tierOf(plan) { return String(plan || '').indexOf('plus') === 0 ? 'plus' : 'premium'; }
  if (typeof VSPremium === 'undefined') {
    $('pmBuy').onclick = $('pmBuy2').onclick = function () { toast('premium.js is missing. Put it in the VibeStream folder and refresh.'); };
    return;
  }
  if (!VSPremium.PLANS.weekly || !VSPremium.PLANS.plusmonth) {
    $('pmBuy').onclick = $('pmBuy2').onclick = function () { toast('Premium plans are not set up. Replace premium-store.php and premium.js with the new versions.'); };
    return;
  }

  function saving(tier, p) {
    var P = VSPremium.PLANS, k = KEY[tier], base = p === 'monthly' ? P[k.weekly].price * 4 : (p === 'yearly' ? P[k.monthly].price * 12 : 0);
    if (!base) return '';
    var pct = Math.round((1 - P[k[p]].price / base) * 100);
    return pct > 0 ? 'Save ' + pct + '% compared to ' + (p === 'monthly' ? 'weekly' : 'monthly') : '';
  }

  function render() {
    var s = VSPremium.status(), pend = VSPremium.isPending(), cur = s ? tierOf(s.plan) : '';
    for (var k in IDS) $(IDS[k]).setAttribute('aria-pressed', k === period);
    ['premium', 'plus'].forEach(function (tier) {
      var p = VSPremium.PLANS[KEY[tier][period]], u = UI[tier];
      $(u.amt).textContent = money(p.price);
      $(u.per).textContent = PER[period];
      $(u.save).textContent = saving(tier, period);
    });
    var ys = saving('premium', 'yearly').replace(/ compared.*/, '');
    $('pmYSave').textContent = ys; $('pmYSave').hidden = !ys;
    var b1 = $('pmBuy'), b2 = $('pmBuy2');
    b1.disabled = !!s || pend || busy;
    b1.textContent = s ? (cur === 'plus' ? 'Included in your plan' : 'You have Premium') : (pend ? 'Payment under review' : (VSPremium.LOGGED_IN ? 'Get Premium' : 'Sign in to get Premium'));
    b2.disabled = cur === 'plus' || pend || busy;
    b2.textContent = cur === 'plus' ? 'You have Plus' : (pend ? 'Payment under review' : (s ? 'Upgrade to Plus' : (VSPremium.LOGGED_IN ? 'Get Plus' : 'Sign in to get Plus')));
    $('pmFree').style.visibility = s ? 'hidden' : 'visible';
    if (s) {
      $('pmStatusText').textContent = (cur === 'plus' ? 'Premium Plus' : 'Premium') + ' is active until ' +
        new Date(s.expires).toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
    }
    $('pmNote').textContent = pend
      ? 'We got your payment details. Premium turns on as soon as the payment is confirmed.'
      : 'Pay first, then enter your transaction ID. Premium turns on after we confirm your payment.';

    var H = VSPremium.history ? VSPremium.history() : [], list = $('pmHistory');
    $('pmHistoryBox').hidden = !H.length;
    list.innerHTML = '';
    H.forEach(function (h) {
      var row = document.createElement('div'), t = document.createElement('span');
      row.className = 'pm-hrow';
      t.textContent = h.label + ' \u00b7 ' + money(h.amount) + ' \u00b7 ' + h.status + ' \u00b7 ' + h.date;
      row.appendChild(t);
      if (h.status === 'approved') {
        var a = document.createElement('a');
        a.href = 'premium-receipt.php?id=' + encodeURIComponent(h.id);
        a.target = '_blank'; a.rel = 'noopener'; a.textContent = 'Receipt';
        row.appendChild(a);
      }
      list.appendChild(row);
    });
  }

  // Get Premium / Get Plus: go straight to the checkout page for the chosen plan and billing period.
  function openModal(tier) {
    chosen = KEY[tier][period];
    location.href = 'premium-checkout.php?plan=' + encodeURIComponent(chosen);
  }
  function closeModal() { $('pmModal').hidden = true; }

  function run(job, okMsg) {
    busy = true; render();
    job().then(function () { toast(okMsg); }).catch(function (e) { toast(e.message); }).then(function () { busy = false; render(); });
  }

  $('pmW').onclick = function () { period = 'weekly'; render(); };
  $('pmM').onclick = function () { period = 'monthly'; render(); };
  $('pmY').onclick = function () { period = 'yearly'; render(); };
  $('pmBuy').onclick = function () {
    if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
    openModal('premium');
  };
  $('pmBuy2').onclick = function () {
    if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
    openModal('plus');
  };
  $('pmClose').onclick = closeModal;
  $('pmModal').onclick = function (e) { if (e.target === $('pmModal')) closeModal(); };
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });
  $('pmSend').onclick = function () {
    var ref = $('pmRef').value.trim();
    if (ref.length < 4) { toast('Enter the transaction ID from your payment.'); return; }
    busy = true; render();
    VSPremium.request(chosen, ref)
      .then(function () { closeModal(); toast('Payment details sent. Premium turns on once we confirm it.'); })
      .catch(function (e) { toast(e.message); })
      .then(function () { busy = false; render(); });
  };
  $('pmCancel').onclick = function () {
    if (!confirm('End Premium now? You lose the time you already paid for, and there is no refund.')) return;
    run(function () { return VSPremium.cancel(); }, 'Premium cancelled');
  };
  document.addEventListener('vs-premium-change', render);
  render();
});
</script>
</body>
</html>