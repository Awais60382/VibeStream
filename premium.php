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
.pm-layout>.app-sidebar{flex:0 0 240px;width:240px;position:sticky!important;top:74px!important;left:auto!important;height:calc(100vh - 74px);overflow-y:auto;transform:none!important;z-index:1}
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
.pm-page{flex:1;min-width:0;max-width:980px;margin:0 auto;padding:32px clamp(16px,4vw,32px) 80px;color:inherit}
.pm-page h1{font-size:clamp(32px,6vw,54px);line-height:1.08;margin:8px 0 12px;max-width:14ch}
.pm-lead{opacity:.7;max-width:52ch;margin:0 0 26px}
.pm-status{display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px 18px;border-radius:14px;margin-bottom:24px;background:linear-gradient(135deg,#f5b942,#ff8a3d);color:#2a1700;font-weight:600}
.vs-premium .pm-status{display:flex}
.pm-status button{background:#2a1700;color:#fff;border:0;border-radius:10px;padding:8px 14px;font:inherit;font-weight:600;cursor:pointer}
.pm-toggle{display:inline-flex;border:1px solid rgba(128,128,128,.35);border-radius:999px;padding:4px;margin-bottom:20px}
.pm-toggle button{border:0;background:none;color:inherit;opacity:.7;padding:8px 18px;border-radius:999px;font:inherit;font-weight:600;cursor:pointer}
.pm-toggle button[aria-pressed=true]{background:#f9433f;color:#fff;opacity:1}
.pm-plans{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:20px}
.pm-plan{background:rgba(128,128,128,.1);border:1px solid rgba(128,128,128,.28);border-radius:20px;padding:28px}
.pm-plan.pm-pro{border:2px solid #f5b942}
.pm-plan.pm-plus{border:2px solid #a78bfa}
.pm-btn.pm-btn-plus{background:#7c5cff}
.pm-btn.pm-btn-plus:disabled{background:rgba(128,128,128,.3)}
.pm-plan h2{font-size:22px;margin:0 0 4px}
.pm-price{font-size:42px;font-weight:800;margin:8px 0}
.pm-price small{font-size:15px;font-weight:500;opacity:.65}
.pm-save{display:block;font-size:13px;font-weight:600;color:#f9433f;min-height:20px}
.pm-plan ul{list-style:none;padding:0;margin:16px 0 24px}
.pm-plan li{padding:8px 0 8px 26px;position:relative;border-top:1px solid rgba(128,128,128,.25)}
.pm-plan li:first-child{border-top:0}
.pm-plan li:before{content:"";position:absolute;left:4px;top:15px;width:10px;height:6px;border-left:2px solid #f9433f;border-bottom:2px solid #f9433f;transform:rotate(-45deg)}
.pm-plan li.pm-no{opacity:.6}
.pm-plan li.pm-no:before{width:10px;height:0;border-left:0;transform:none;top:19px;border-color:currentColor}
.pm-btn{width:100%;border:0;border-radius:12px;padding:14px;font:inherit;font-weight:700;font-size:16px;cursor:pointer;background:#f9433f;color:#fff}
.pm-btn:disabled{background:rgba(128,128,128,.3);color:inherit;opacity:.7;cursor:default}
.pm-btn:focus-visible,.pm-toggle button:focus-visible,.pm-status button:focus-visible{outline:3px solid #f5b942;outline-offset:2px}
.pm-note{margin-top:18px;font-size:13px;opacity:.65;max-width:70ch}
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
  --bg:#05060f;--surface:#0a0d1f;--surface-2:#121634;--surface-3:#171c42;
  --text:#eaf0ff;--muted:#8f9bd0;--border:#1d2557;
  --accent:#ff2e93;--accent-hover:#171c42;--accent-soft:rgba(255,46,147,.18);--blue:#00e5ff;
  --neon-cyan:#00e5ff;--neon-pink:#ff2e93;--neon-violet:#8a5cff;
  --nx-cyan:#00e5ff;--nx-pink:#ff2e93;--nx-violet:#8a5cff;
  --nx-cyan-rgb:0,229,255;--nx-pink-rgb:255,46,147;--nx-violet-rgb:138,92,255;
  --nx-cyan-ink:#00e5ff;--nx-pink-ink:#ff7ac0;
  --nx-text:#eaf0ff;--nx-card:rgba(10,13,31,.88);--nx-solid:#0a0d1f;--nx-field:rgba(10,13,31,.9);
  --nx-nav:rgba(5,6,15,.82);--nx-side:linear-gradient(180deg,rgba(10,13,31,.97),rgba(5,6,15,.98));
  --nx-track:#05060f;--nx-sun:#ffe14d;--nx-active:#fff;--nx-backdrop:rgba(5,6,15,.78);
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(138,92,255,.16),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,229,255,.10),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.08),transparent 70%),#05060f;
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
html body .pm-status{background:linear-gradient(135deg,rgba(var(--nx-pink-rgb),.92),rgba(var(--nx-violet-rgb),.92));color:#fff;border:1px solid rgba(var(--nx-pink-rgb),.7);box-shadow:0 0 24px -4px rgba(var(--nx-pink-rgb),.6)}
html body .pm-status button{background:var(--nx-solid);color:var(--nx-cyan-ink);border:1px solid var(--nx-cyan);box-shadow:0 0 12px -2px rgba(var(--nx-cyan-rgb),.55);transition:box-shadow .18s ease}
html body .pm-status button:hover{box-shadow:0 0 20px rgba(var(--nx-cyan-rgb),.8)}

html body .pm-toggle{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:0 0 18px -6px rgba(var(--nx-violet-rgb),.6)}
html body .pm-toggle button{transition:background .18s ease,box-shadow .18s ease}
html body .pm-toggle button:hover{background:rgba(var(--nx-cyan-rgb),.12);opacity:1}
html body .pm-toggle button[aria-pressed=true]{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;box-shadow:0 0 16px rgba(var(--nx-pink-rgb),.6)}

html body .pm-plan{--tick:var(--nx-violet);background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.45);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
html body .pm-plan:hover{transform:translateY(-3px);border-color:var(--nx-cyan);box-shadow:0 0 28px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body .pm-plan.pm-pro{--tick:var(--nx-pink);border:2px solid var(--nx-pink);box-shadow:0 0 30px -6px rgba(var(--nx-pink-rgb),.7),inset 0 0 26px -16px rgba(var(--nx-pink-rgb),.7)}
html body .pm-plan.pm-pro:hover{border-color:var(--nx-pink);box-shadow:0 0 40px -4px rgba(var(--nx-pink-rgb),.9),inset 0 0 26px -14px rgba(var(--nx-pink-rgb),.8)}
html body .pm-plan.pm-plus{--tick:var(--nx-cyan);border:2px solid var(--nx-cyan);box-shadow:0 0 30px -6px rgba(var(--nx-cyan-rgb),.7),0 0 56px -22px rgba(var(--nx-violet-rgb),.8),inset 0 0 26px -16px rgba(var(--nx-cyan-rgb),.7)}
html body .pm-plan.pm-plus:hover{border-color:var(--nx-cyan);box-shadow:0 0 40px -4px rgba(var(--nx-cyan-rgb),.9),0 0 60px -18px rgba(var(--nx-violet-rgb),.9),inset 0 0 26px -14px rgba(var(--nx-cyan-rgb),.8)}
html body .pm-price{text-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.45)}
html body .pm-save{color:var(--nx-pink-ink);text-shadow:0 0 10px rgba(var(--nx-pink-rgb),.55)}
html body .pm-plan li{border-top:1px solid rgba(var(--nx-violet-rgb),.28)}
html body .pm-plan li:first-child{border-top:0}
html body .pm-plan li:before{border-left-color:var(--tick);border-bottom-color:var(--tick);filter:drop-shadow(0 0 4px var(--tick))}

html body .pm-btn{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;text-shadow:0 1px 2px rgba(0,0,0,.3);box-shadow:0 0 20px rgba(var(--nx-pink-rgb),.5);transition:box-shadow .18s ease,transform .18s ease}
html body .pm-btn:hover{transform:translateY(-1px);box-shadow:0 0 30px rgba(var(--nx-pink-rgb),.85)}
html body .pm-btn.pm-btn-plus{background:linear-gradient(90deg,var(--nx-violet),var(--nx-cyan));box-shadow:0 0 20px rgba(var(--nx-cyan-rgb),.5)}
html body .pm-btn.pm-btn-plus:hover{box-shadow:0 0 30px rgba(var(--nx-cyan-rgb),.85)}
html body .pm-btn.pm-ghost{background:rgba(var(--nx-violet-rgb),.16);color:var(--nx-text);text-shadow:none;border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:none}
html body .pm-btn.pm-ghost:hover{border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}
html body .pm-btn:disabled,html body .pm-btn.pm-btn-plus:disabled{background:rgba(var(--nx-violet-rgb),.14);color:inherit;text-shadow:none;border:1px solid rgba(var(--nx-violet-rgb),.35);box-shadow:none;transform:none;opacity:.7}
html body .pm-btn:focus-visible,html body .pm-toggle button:focus-visible,html body .pm-status button:focus-visible{outline:3px solid var(--nx-cyan);outline-offset:2px}

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

  <h1>Watch without ads. Get more room to upload, save and download.</h1>
  <p class="pm-lead">Premium removes configured ads from the home feed, video pages and Shorts, and includes private Creator Analytics, a VIP badge, downloads for eligible hosted videos, more playlists and Watch Later space, and higher daily upload limits. Pick a week, a month or a year.</p>

  <div class="pm-toggle" role="group" aria-label="Billing period">
    <button type="button" id="pmW" aria-pressed="false">Weekly</button>
    <button type="button" id="pmM" aria-pressed="true">Monthly</button>
    <button type="button" id="pmY" aria-pressed="false">Yearly</button>
  </div>

  <div class="pm-plans">
    <section class="pm-plan">
      <h2>Free</h2>
      <div class="pm-price">$0</div>
      <span class="pm-save"></span>
      <ul>
        <li>Watch all videos and shorts</li>
        <li>Up to <?php echo htmlspecialchars($formatPlanLimit('free', 'playlists'), ENT_QUOTES, 'UTF-8'); ?> playlists</li>
        <li>Watch Later: up to <?php echo htmlspecialchars($formatPlanLimit('free', 'watch_later'), ENT_QUOTES, 'UTF-8'); ?> videos</li>
        <li>Up to <?php echo htmlspecialchars($formatPlanLimit('free', 'uploads_per_day'), ENT_QUOTES, 'UTF-8'); ?> uploads a day</li>
        <li class="pm-no">No VIP badge</li>
        <li class="pm-no">No video downloads</li>
      </ul>
      <button class="pm-btn" type="button" disabled id="pmFree">Current plan</button>
    </section>
    <section class="pm-plan pm-pro">
      <h2>Premium</h2>
      <div class="pm-price"><span id="pmAmt">$4.99</span> <small id="pmPer">per month</small></div>
      <span class="pm-save" id="pmSave"></span>
      <ul>
        <li>Ad-free home feed, video pages and Shorts</li>
        <li>Private Creator Analytics with daily views and top content</li>
        <li>VIP badge on your comments and channel</li>
        <li>Download eligible videos hosted on VibeStream</li>
        <li>Up to <?php echo htmlspecialchars($formatPlanLimit('premium', 'playlists'), ENT_QUOTES, 'UTF-8'); ?> playlists</li>
        <li>Watch Later: up to <?php echo htmlspecialchars($formatPlanLimit('premium', 'watch_later'), ENT_QUOTES, 'UTF-8'); ?> videos</li>
        <li>Up to <?php echo htmlspecialchars($formatPlanLimit('premium', 'uploads_per_day'), ENT_QUOTES, 'UTF-8'); ?> uploads a day</li>
      </ul>
      <button class="pm-btn" type="button" id="pmBuy">Get Premium</button>
    </section>
    <section class="pm-plan pm-plus">
      <h2>Premium Plus</h2>
      <div class="pm-price"><span id="pmAmt2">$9.99</span> <small id="pmPer2">per month</small></div>
      <span class="pm-save" id="pmSave2"></span>
      <ul>
        <li>Everything in Premium</li>
        <li>Ad-free home feed, video pages and Shorts</li>
        <li>VIP+ badge (purple) on your comments and channel</li>
        <li><?php echo htmlspecialchars($formatPlanLimit('plus', 'playlists'), ENT_QUOTES, 'UTF-8'); ?> playlists</li>
        <li>Watch Later: up to <?php echo htmlspecialchars($formatPlanLimit('plus', 'watch_later'), ENT_QUOTES, 'UTF-8'); ?> videos</li>
        <li>Up to <?php echo htmlspecialchars($formatPlanLimit('plus', 'uploads_per_day'), ENT_QUOTES, 'UTF-8'); ?> uploads a day</li>
      </ul>
      <button class="pm-btn pm-btn-plus" type="button" id="pmBuy2">Get Plus</button>
    </section>
  </div>
  <section class="pm-plan pm-reward-panel" aria-labelledby="pmRewardsTitle">
    <h2 id="pmRewardsTitle">Earn Premium with points</h2>
    <p>Earn 5 points for each video or short you publish and 5 points for a daily check-in. Existing uploads are credited once. Redeem 500 points for one month of Premium.</p>
    <p class="pm-reward-balance">Your balance: <strong><span id="pmPoints">0</span> points</strong></p>
    <div class="pm-checkin">
      <button class="pm-btn" type="button" id="pmCheckin">Claim daily check-in (+5 points)</button>
      <p class="pm-small" id="pmCheckinNote" aria-live="polite">Check in once each day to earn 5 points.</p>
    </div>
    <button class="pm-btn" type="button" id="pmRedeem">Redeem 500 points for monthly Premium</button>
    <p class="pm-small" id="pmRewardsNote">You can redeem points after your current Premium period ends.</p>
  </section>
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
  function tierOf(plan) { return plan === 'admin' || String(plan || '').indexOf('plus') === 0 ? 'plus' : 'premium'; }
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
    var b1 = $('pmBuy'), b2 = $('pmBuy2');
    var points = VSPremium.points();
    var pointsAvailable = VSPremium.pointsAvailable();
    $('pmPoints').textContent = pointsAvailable ? points.toLocaleString() : 'Unavailable';
    var checkin = $('pmCheckin');
    var checkinClaimed = VSPremium.checkinClaimed();
    checkin.disabled = busy || !VSPremium.LOGGED_IN || !pointsAvailable || checkinClaimed;
    checkin.textContent = !VSPremium.LOGGED_IN ? 'Sign in to check in'
      : (!pointsAvailable ? 'Check-in temporarily unavailable'
        : (checkinClaimed ? 'Checked in today' : 'Claim daily check-in (+5 points)'));
    $('pmCheckinNote').textContent = !VSPremium.LOGGED_IN ? 'Sign in to claim your daily 5 points.'
      : (!pointsAvailable ? 'Your check-in could not be loaded. Please try again later.'
        : (checkinClaimed ? 'You have already claimed today’s check-in. Come back tomorrow.'
          : 'Check in once each day to earn 5 points.'));
    var redeem = $('pmRedeem');
    redeem.disabled = busy || !VSPremium.LOGGED_IN || !pointsAvailable || !!s || points < 500;
    redeem.textContent = !VSPremium.LOGGED_IN ? 'Sign in to redeem points'
      : (s ? 'Premium is already active' : (!pointsAvailable ? 'Points temporarily unavailable'
        : (points < 500 ? 'Earn ' + (500 - points) + ' more points' : 'Redeem 500 points for monthly Premium')));
    $('pmRewardsNote').textContent = s
      ? 'Redeem your points after your current Premium period ends.'
      : (!VSPremium.LOGGED_IN ? 'Sign in to earn points by publishing videos or shorts and checking in daily.'
        : (!pointsAvailable ? 'Your points balance could not be loaded. Please try again later.'
          : 'You need 500 points to redeem one 31-day Premium month.'));
    b1.disabled = !!s || pend || busy;
    b1.textContent = s ? (cur === 'plus' ? 'Included in your plan' : 'You have Premium') : (pend ? 'Payment under review' : (VSPremium.LOGGED_IN ? 'Get Premium' : 'Sign in to get Premium'));
    b2.disabled = cur === 'plus' || pend || busy;
    b2.textContent = cur === 'plus' ? 'You have Plus' : (pend ? 'Payment under review' : (s ? 'Upgrade to Plus' : (VSPremium.LOGGED_IN ? 'Get Plus' : 'Sign in to get Plus')));
    $('pmFree').style.visibility = s ? 'hidden' : 'visible';
    var admin = !!s && s.plan === 'admin';
    $('pmCancel').style.display = admin ? 'none' : '';
    document.querySelector('.pm-status').style.display = admin ? 'none' : '';
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
  $('pmRedeem').onclick = function () {
    if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
    if (!VSPremium.pointsAvailable() || VSPremium.points() < 500 || VSPremium.status()) return;
    run(function () { return VSPremium.redeem(); }, '500 points redeemed. Your one-month Premium is active.');
  };
  $('pmCheckin').onclick = function () {
    if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
    if (!VSPremium.pointsAvailable() || VSPremium.checkinClaimed()) return;
    busy = true; render();
    VSPremium.checkin()
      .then(function (awarded) {
        toast(awarded ? 'Daily check-in complete! 5 points added.' : 'You already claimed today’s check-in.');
      })
      .catch(function (e) { toast(e.message); })
      .then(function () { busy = false; render(); });
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