<?php
include __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar-navigation.php';
?>
<style>
html:not([data-theme="light"]){
  --pp-page-bg:#06030f;--pp-sidebar:linear-gradient(180deg,#0c0720,#06030f);
  --pp-gold:#ffc23d;--pp-pink:#ff2e93;--pp-green:#2fe08a;
  --pp-text:#f7f3ff;--pp-muted:#b9acd9;--pp-line:rgba(184,107,255,.32);
  --pp-surface:#120a2a;
  --pp-card:linear-gradient(145deg,rgba(30,12,62,.94),rgba(10,6,26,.96));
  --pp-hero:radial-gradient(700px 360px at 85% 0,rgba(168,85,247,.4),transparent 70%),radial-gradient(520px 340px at 0 100%,rgba(255,46,147,.2),transparent 70%),linear-gradient(135deg,#150a33,#07040f);
  --pp-shadow:0 0 0 1px rgba(184,107,255,.12),0 16px 44px -24px rgba(168,85,247,.75);
  --pp-glow:0 0 26px rgba(168,85,247,.38);
}
html[data-theme="light"]{
  --pp-page-bg:#f6f2ff;--pp-sidebar:linear-gradient(180deg,#fff,#f4f0ff);
  --pp-gold:#8a5a00;--pp-pink:#d41d78;--pp-green:#168a50;
  --pp-text:#1a1035;--pp-muted:#5d5580;--pp-line:rgba(123,47,214,.28);
  --pp-surface:#fff;
  --pp-card:linear-gradient(145deg,#fff,#f6f0ff);
  --pp-hero:radial-gradient(700px 360px at 85% 0,rgba(168,85,247,.22),transparent 70%),linear-gradient(135deg,#fff,#f1e9ff);
  --pp-shadow:0 12px 36px -26px rgba(92,64,160,.45);
  --pp-glow:none;
}
html body{background-color:var(--pp-page-bg);background-image:var(--pp-hero);background-attachment:fixed;color:var(--pp-text)}
.pp-defs{position:absolute;width:0;height:0;overflow:hidden}
.pp-layout{display:flex;align-items:flex-start}
.pp-layout>.app-sidebar{flex:0 0 206px;width:206px;position:sticky!important;top:74px!important;height:calc(100vh - 74px);overflow-y:auto;transform:none!important;z-index:1;border-right:1px solid var(--pp-line);padding:8px 0;box-sizing:border-box;background:var(--pp-sidebar)}
.pp-layout .sidebar-top{display:none}
.pp-layout .app-sidebar a{color:inherit;text-decoration:none}
.pp-layout .sidebar-block{display:block;padding:8px 12px;border-bottom:1px solid var(--pp-line)}
.pp-layout .sidebar-block-title{display:flex;align-items:center;gap:6px;margin:0;padding:10px 12px;font-size:16px;font-weight:700}
.pp-layout .sidebar-link{display:flex;align-items:center;gap:22px;padding:10px 12px;border-radius:12px;font-size:14px;line-height:1.3}
.pp-layout .sidebar-link:hover{background:rgba(168,85,247,.14)}
.pp-layout .sidebar-link.active{background:linear-gradient(90deg,rgba(255,46,147,.3),rgba(168,85,247,.2));box-shadow:inset 0 0 0 1px rgba(255,46,147,.5);font-weight:700}
.pp-layout .sidebar-icon,.pp-layout .sidebar-icon svg,.pp-layout .sidebar-icon img{width:24px;height:24px}
.pp-layout .sidebar-icon{display:inline-flex;flex:0 0 24px}
.pp-layout .sidebar-icon img{object-fit:contain}
.pp-layout .sidebar-account{display:flex;align-items:center;gap:10px;padding:14px 20px}
.pp-layout .sidebar-account-copy{display:flex;flex-direction:column;line-height:1.25;min-width:0}
.pp-layout .sidebar-account-copy small{opacity:.65}
.pp-layout .sidebar-account img,.pp-layout .sidebar-account-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover}
.pp-page{flex:1;min-width:0;max-width:1180px;margin:0 auto;padding:16px clamp(12px,2vw,24px) 56px}

.pp-hero,.pp-card{position:relative;border:1px solid var(--pp-line);background:var(--pp-card);color:var(--pp-text);box-shadow:var(--pp-shadow)}
.pp-card{border-radius:18px;padding:16px 18px;overflow:hidden}
.pp-card h2{margin:0;font-size:18px}
.pp-card p{color:var(--pp-muted);line-height:1.45;margin:4px 0}
.pp-note{font-size:12px!important}
.pp-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pp-head h2{flex:1;min-width:0}
.pp-ico{width:30px;height:30px;flex:0 0 30px}

/* hero */
.pp-hero{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(200px,.8fr);align-items:center;gap:16px;padding:clamp(18px,3vw,30px) clamp(18px,3vw,36px);border-radius:24px;background:var(--pp-hero);border-color:rgba(184,107,255,.6);box-shadow:var(--pp-shadow),var(--pp-glow);overflow:hidden}
.pp-eyebrow{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:999px;background:rgba(168,85,247,.25);border:1px solid rgba(184,107,255,.55);color:#f0e1ff;font-size:12px;font-weight:800}
html[data-theme="light"] .pp-eyebrow{color:#5b1fa8}
.pp-hero h1{margin:10px 0 4px;font-size:clamp(30px,4.6vw,54px);line-height:1.02;letter-spacing:-.03em}
.pp-hero h1 span{background:linear-gradient(90deg,#c58bff,#ff2e93 60%,#ffb020);-webkit-background-clip:text;background-clip:text;color:transparent}
.pp-hero h3{margin:0 0 6px;font-size:clamp(15px,1.8vw,20px)}
.pp-hero p{max-width:54ch;margin:0 0 14px;color:var(--pp-muted);font-size:14px;line-height:1.5}
.pp-hero-art{width:min(100%,290px);justify-self:center;filter:drop-shadow(0 0 26px rgba(255,176,32,.4))}
.pp-tag{position:absolute;right:22px;top:14px;color:var(--pp-gold);font-style:italic;font-weight:800;font-size:15px;line-height:1.15;transform:rotate(-6deg);text-align:right}
.pp-btn{display:inline-block;border:0;border-radius:999px;padding:11px 22px;background:linear-gradient(90deg,#ffb020,#ff802e);color:#2a1300;font:inherit;font-weight:800;cursor:pointer;text-decoration:none;box-shadow:0 0 20px rgba(255,160,40,.4);transition:transform .15s,box-shadow .15s}
.pp-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 0 30px rgba(255,160,40,.65)}
.pp-btn:disabled{opacity:.55;cursor:default;box-shadow:none}
.pp-btn-pink{background:linear-gradient(90deg,#ff2e93,#a855f7);color:#fff;box-shadow:0 0 20px rgba(255,46,147,.4)}
.pp-btn-sm{padding:8px 16px;font-size:13px}
.pp-btn:focus-visible,.pp-dialog button:focus-visible{outline:3px solid #ffb020;outline-offset:3px}

/* stats */
.pp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-top:12px}
.pp-stat{display:flex;flex-direction:column;gap:4px;min-height:132px;padding-right:76px}
.pp-stat .pp-lab{display:flex;align-items:center;gap:8px;font-weight:700;font-size:14px}
.pp-stat .pp-lab svg{width:24px;height:24px}
.pp-stat-art{position:absolute;right:8px;bottom:6px;width:86px;height:86px;opacity:.95;filter:drop-shadow(0 0 14px rgba(255,176,32,.4))}
.pp-val{font-size:clamp(26px,2.8vw,36px);font-weight:800;line-height:1.1;display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.pp-val small{font-size:15px;color:var(--pp-muted)}
.pp-stat-points .pp-val{color:var(--pp-gold)}
.pp-stat-status .pp-val{color:var(--pp-green)}
.pp-stat-status.is-off .pp-val{color:var(--pp-muted)}
.pp-bar{height:8px;margin-top:auto;overflow:hidden;border-radius:999px;background:rgba(128,128,128,.25)}
.pp-bar span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#a855f7,#ff2e93,#ffb020);transition:width .25s}
.pp-bar.is-green span{background:linear-gradient(90deg,#16a34a,#2fe08a)}

/* rows */
.pp-row{display:grid;gap:12px;margin-top:12px}
.pp-row-2{grid-template-columns:minmax(0,1.2fr) minmax(0,1fr)}
.pp-row-3{grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) minmax(0,1fr)}
.pp-timer{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;border:1px solid rgba(184,107,255,.6);background:rgba(168,85,247,.16);font-weight:800;font-size:13px;font-variant-numeric:tabular-nums}
.pp-calendar{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:6px;margin:12px 0 10px}
.pp-day{display:grid;justify-items:center;gap:3px;padding:8px 2px;border:1px solid var(--pp-line);border-radius:12px;background:rgba(168,85,247,.07);color:var(--pp-muted);font-size:11px}
.pp-day .pp-tick{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:12px;border:1px dashed var(--pp-line)}
.pp-day b{color:var(--pp-gold);font-size:13px}
.pp-day.is-claimed{border-color:rgba(47,224,138,.7);background:rgba(47,224,138,.1);color:var(--pp-green)}
.pp-day.is-claimed .pp-tick{background:var(--pp-green);border:0;color:#04130b;font-weight:900}
.pp-day.is-today{border-color:#ffb020;background:rgba(255,176,32,.14);box-shadow:0 0 16px rgba(255,176,32,.4)}
.pp-day.is-today .pp-tick{border-color:#ffb020}
.pp-checkin-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pp-tiers{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:12px}
.pp-tier{display:flex;flex-direction:column;align-items:center;gap:2px;padding:10px 6px;border:1px solid var(--pp-line);border-radius:14px;background:rgba(168,85,247,.08);text-align:center}
.pp-tier svg{width:38px;height:38px}
.pp-tier strong{font-size:13px}
.pp-tier span{color:var(--pp-muted);font-size:11px;margin-bottom:6px}
.pp-tier .pp-btn{width:100%;padding:7px 4px;font-size:12px}
.pp-tier.is-live{border-color:rgba(255,176,32,.75);background:radial-gradient(120px 80px at 50% 0,rgba(255,176,32,.25),transparent 80%),rgba(255,176,32,.06);box-shadow:0 0 18px rgba(255,176,32,.28)}
.pp-tier.is-soon{opacity:.7}

/* chart + leaders + vip */
.pp-chip{padding:5px 12px;border-radius:12px;border:1px solid var(--pp-line);background:rgba(168,85,247,.14);font-weight:800;font-size:13px}
.pp-chip em{font-style:normal;color:var(--pp-green);margin-left:6px}
.pp-line{margin:6px 0 0}
.pp-line svg{width:100%;height:auto;display:block;overflow:visible}
.pp-line text{fill:var(--pp-muted);font-size:11px}
.pp-leaders{list-style:none;margin:10px 0 0;padding:0;display:grid;gap:8px}
.pp-leaders li{display:flex;align-items:center;gap:10px;padding:8px 10px;border:1px solid var(--pp-line);border-radius:12px;background:rgba(168,85,247,.07);color:var(--pp-muted);font-size:13px}
.pp-leaders li.is-row{color:var(--pp-text)}
.pp-rank{width:26px;height:26px;flex:0 0 26px;border-radius:50%;display:grid;place-items:center;font-weight:800;font-size:12px;background:linear-gradient(135deg,#ffe27a,#e79300);color:#2a1300}
.pp-leaders li:nth-child(2) .pp-rank{background:linear-gradient(135deg,#e9e9ff,#9aa3d6)}
.pp-leaders li:nth-child(3) .pp-rank{background:linear-gradient(135deg,#ffb27a,#c4622a)}
.pp-leader-name{flex:1;min-width:0;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pp-leader-pts{color:var(--pp-gold);font-weight:800;font-size:12px;white-space:nowrap}
.pp-vip{background:radial-gradient(360px 220px at 100% 100%,rgba(255,176,32,.28),transparent 70%),var(--pp-card);border-color:rgba(255,176,32,.6);padding-right:90px}
.pp-vip h2{font-size:21px;line-height:1.1}
.pp-vip h2 span{background:linear-gradient(90deg,#ff2e93,#ffb020);-webkit-background-clip:text;background-clip:text;color:transparent}
.pp-perks{list-style:none;margin:8px 0 12px;padding:0;display:grid;gap:4px;color:var(--pp-muted);font-size:12px}
.pp-perks li::before{content:"\2713";color:var(--pp-green);font-weight:900;margin-right:6px}
.pp-vip .pp-stat-art{width:100px;height:100px;bottom:50px}

.pp-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);max-width:90vw;padding:12px 20px;border:1px solid var(--pp-line);border-radius:12px;background:var(--pp-surface);color:var(--pp-text);box-shadow:var(--pp-shadow);opacity:0;pointer-events:none;transition:.2s;z-index:80;text-align:center}
.pp-toast.show{opacity:1;transform:translate(-50%,0)}
.pp-dialog{width:min(440px,calc(100vw - 32px));border:1px solid rgba(184,107,255,.6);border-radius:20px;padding:24px;background:var(--pp-surface);color:var(--pp-text);box-shadow:var(--pp-shadow),var(--pp-glow)}
.pp-dialog::backdrop{background:rgba(4,2,12,.78)}
.pp-dialog h2{margin:0 0 8px}
.pp-dialog p{color:var(--pp-muted);line-height:1.5}
.pp-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.pp-btn-secondary{border:1px solid var(--pp-line);border-radius:999px;padding:11px 18px;background:transparent;color:var(--pp-text);font:inherit;font-weight:700;cursor:pointer}

/* hero artwork: drop an image at assets/premium-hero.webp and it replaces the SVG art */
.pp-hero::before{content:"";position:absolute;inset:0;z-index:0;background:url('assets/premium-hero.webp') right center/auto 100% no-repeat;-webkit-mask-image:linear-gradient(to left,#000 45%,transparent 90%);mask-image:linear-gradient(to left,#000 45%,transparent 90%);pointer-events:none}
.pp-hero>div,.pp-hero>svg{position:relative;z-index:1}
.pp-hero.has-art .pp-hero-art{display:none}
.pp-hero-art g.pp-float{transform-box:fill-box;transform-origin:center}
@keyframes ppFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-8px) rotate(3deg)}}
@keyframes ppPulse{0%,100%{box-shadow:0 0 10px rgba(255,176,32,.3)}50%{box-shadow:0 0 22px rgba(255,176,32,.8)}}
@keyframes ppTwinkle{0%,100%{opacity:.35;transform:scale(.8)}50%{opacity:1;transform:scale(1.15)}}
@keyframes ppBurst{0%{transform:translate(0,0) scale(.6);opacity:1}100%{transform:translate(var(--dx),var(--dy)) scale(1.1);opacity:0}}
.pp-float{animation:ppFloat 4.2s ease-in-out infinite}
.pp-float.d2{animation-delay:-1.4s}
.pp-float.d3{animation-delay:-2.8s}
.pp-twinkle{animation:ppTwinkle 2.6s ease-in-out infinite;transform-box:fill-box;transform-origin:center}
.pp-day.is-today{animation:ppPulse 2s ease-in-out infinite}
.pp-coin-burst{position:fixed;width:14px;height:14px;border-radius:50%;background:radial-gradient(circle at 35% 30%,#fff1b1,#e79300);box-shadow:0 0 10px rgba(255,176,32,.8);pointer-events:none;z-index:90;animation:ppBurst .9s ease-out forwards}
.pp-sb-card{margin:12px;padding:14px 12px;border-radius:16px;border:1px solid rgba(255,176,32,.65);background:linear-gradient(145deg,rgba(255,176,32,.18),rgba(168,85,247,.16));box-shadow:0 0 18px rgba(255,176,32,.28);text-align:center}
.pp-sb-card strong{display:block;font-size:15px;color:var(--pp-gold)}
.pp-sb-card span{display:block;margin:4px 0 10px;font-size:12px;opacity:.85}
.pp-sb-card a.pp-btn{padding:8px 14px;font-size:12px;color:#2a1300}
@media(prefers-reduced-motion:reduce){.pp-float,.pp-twinkle,.pp-day.is-today{animation:none}.pp-coin-burst{display:none}}
@media(max-width:1080px){.pp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.pp-row-3{grid-template-columns:1fr}}
@media(max-width:900px){.pp-layout>.app-sidebar{display:none}.pp-row-2{grid-template-columns:1fr}.pp-hero{grid-template-columns:1fr}.pp-hero-art{width:150px;grid-row:1}.pp-tag{display:none}}
@media(max-width:560px){.pp-hero,.pp-card{padding:14px}.pp-stats{gap:8px}.pp-stat{padding-right:60px}.pp-stat-art{width:62px;height:62px}.pp-calendar{gap:4px}.pp-day{font-size:10px}.pp-tiers{gap:6px}}
@media(prefers-reduced-motion:reduce){.pp-btn,.pp-toast,.pp-bar span{transition:none}.pp-btn:hover{transform:none}}
</style>

<svg class="pp-defs" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="ppCoin" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#ffe27a"/><stop offset="1" stop-color="#e79300"/></linearGradient>
    <linearGradient id="ppGem" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#f0c6ff"/><stop offset="1" stop-color="#7b2fd6"/></linearGradient>
    <linearGradient id="ppBlue" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#7dd3fc"/><stop offset="1" stop-color="#3b6df0"/></linearGradient>
    <linearGradient id="ppGreen" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#86efac"/><stop offset="1" stop-color="#16a34a"/></linearGradient>
    <linearGradient id="ppArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#b86bff" stop-opacity=".6"/><stop offset="1" stop-color="#b86bff" stop-opacity="0"/></linearGradient>
  </defs>
  <symbol id="ppCrown" viewBox="0 0 64 64"><path d="M8 20l14 14 10-20 10 20 14-14-6 30H14z" fill="url(#ppCoin)"/><rect x="14" y="52" width="36" height="6" rx="3" fill="#e79300"/><circle cx="32" cy="12" r="4" fill="#ff5c93"/><circle cx="8" cy="20" r="3.5" fill="#ffe27a"/><circle cx="56" cy="20" r="3.5" fill="#ffe27a"/></symbol>
  <symbol id="ppGemShape" viewBox="0 0 64 64"><path d="M16 10h32l12 16-28 32L4 26z" fill="url(#ppGem)"/><path d="M4 26h56M22 10l10 48M42 10L32 58" stroke="#fff" stroke-opacity=".45" stroke-width="2" fill="none"/></symbol>
  <symbol id="ppShield" viewBox="0 0 64 64"><path d="M32 4l22 8v18c0 16-10 26-22 30C20 56 10 46 10 30V12z" fill="url(#ppCoin)"/><path d="M32 18l4 9 10 1-7.5 6.5L41 44l-9-5-9 5 2.5-9.5L18 28l10-1z" fill="#fff6c8"/></symbol>
  <symbol id="ppGift" viewBox="0 0 64 64"><rect x="8" y="26" width="48" height="32" rx="4" fill="url(#ppBlue)"/><rect x="6" y="18" width="52" height="12" rx="3" fill="#7dd3fc"/><path d="M32 18v40" stroke="#fff" stroke-width="5"/><path d="M32 18c-10-12-20-2-10 2zM32 18c10-12 20-2 10 2z" fill="#fff"/></symbol>
  <symbol id="ppChest" viewBox="0 0 64 64"><path d="M6 28a26 18 0 0 1 52 0z" fill="#8a4b12"/><rect x="6" y="28" width="52" height="26" rx="4" fill="#a8601a"/><rect x="28" y="30" width="8" height="12" rx="2" fill="url(#ppCoin)"/><circle cx="20" cy="26" r="5" fill="url(#ppCoin)"/><circle cx="34" cy="22" r="6" fill="url(#ppCoin)"/><circle cx="46" cy="26" r="5" fill="url(#ppCoin)"/></symbol>
</svg>

<div class="pp-layout">
<?php vs_render_sidebar('premium'); ?>
<main class="pp-page">

  <section class="pp-hero" aria-labelledby="ppTitle">
    <span class="pp-tag" aria-hidden="true">More Points,<br>More Rewards!</span>
    <div>
      <span class="pp-eyebrow">&#128081; Premium Rewards</span>
      <h1 id="ppTitle">Welcome to <span>Rewards</span></h1>
      <h3>The Ultimate Rewards Experience!</h3>
      <p>Publish a video or Short for 5 points, or check in daily for 5 more. Redeem 500 points for 31 days of Premium. Existing uploads are credited once.</p>
      <a class="pp-btn" href="#ppCheckinTitle">Start Earning Now &rarr;</a>
    </div>
    <svg class="pp-hero-art" viewBox="0 0 260 200" role="img" aria-label="Crown above gold coins and gems">
      <g class="pp-float"><use href="#ppCrown" x="78" y="2" width="104" height="104"/></g>
      <g class="pp-float d2"><use href="#ppGemShape" x="6" y="50" width="52" height="52"/></g>
      <g class="pp-float d3"><use href="#ppGemShape" x="206" y="64" width="44" height="44"/></g>
      <use href="#ppChest" x="60" y="94" width="140" height="100"/>
      <circle class="pp-twinkle" cx="52" cy="22" r="3" fill="#fff"/>
      <circle class="pp-twinkle" cx="196" cy="16" r="2.5" fill="#ffe27a" style="animation-delay:-1s"/>
      <circle class="pp-twinkle" cx="236" cy="120" r="2.5" fill="#fff" style="animation-delay:-1.8s"/>
      <path d="M232 22v22m-11-11h22" stroke="#ff6f91" stroke-width="4" stroke-linecap="round"/>
      <path d="M28 24v16m-8-8h16" stroke="#b86bff" stroke-width="3" stroke-linecap="round"/>
    </svg>
  </section>

  <div class="pp-stats">
    <section class="pp-card pp-stat pp-stat-points" aria-labelledby="ppBalanceTitle">
      <span class="pp-lab" id="ppBalanceTitle"><svg><use href="#ppGemShape"/></svg>Your Balance</span>
      <div class="pp-val"><span id="ppPoints">0</span><small>pts</small></div>
      <p id="ppPointsNote" class="pp-note" aria-live="polite">Loading your points…</p>
      <div class="pp-bar" id="ppProgress" role="progressbar" aria-label="Progress toward a Premium month" aria-valuemin="0" aria-valuemax="500" aria-valuenow="0"><span id="ppProgressFill"></span></div>
      <svg class="pp-stat-art" aria-hidden="true"><use href="#ppChest"/></svg>
    </section>
    <section class="pp-card pp-stat" aria-labelledby="ppLifeTitle">
      <span class="pp-lab" id="ppLifeTitle"><svg><use href="#ppCrown"/></svg>Lifetime Earned</span>
      <div class="pp-val" style="color:var(--pp-gold)"><span id="ppLifetime">—</span><small>pts</small></div>
      <p class="pp-note" id="ppLifetimeNote">Collect more points to unlock rewards!</p>
      <svg class="pp-stat-art" aria-hidden="true"><use href="#ppShield"/></svg>
    </section>
    <section class="pp-card pp-stat" aria-labelledby="ppStreakTitle">
      <span class="pp-lab" id="ppStreakTitle"><svg><use href="#ppCrown"/></svg>Total Streak</span>
      <div class="pp-val"><span id="ppStreakNum">0</span><small>days &#128293;</small></div>
      <p class="pp-note">Keep going!</p>
      <div class="pp-bar is-green" aria-hidden="true"><span id="ppStreakFill"></span></div>
      <svg class="pp-stat-art" aria-hidden="true"><use href="#ppGift"/></svg>
    </section>
    <section class="pp-card pp-stat pp-stat-status is-off" id="ppStatusCard" aria-labelledby="ppStatusTitle">
      <span class="pp-lab" id="ppStatusTitle"><svg><use href="#ppCrown"/></svg>Premium Status</span>
      <div class="pp-val"><span id="ppStatus">—</span></div>
      <p class="pp-note" id="ppStatusNote" aria-live="polite">Loading…</p>
      <svg class="pp-stat-art" aria-hidden="true"><use href="#ppCrown"/></svg>
    </section>
  </div>

  <div class="pp-row pp-row-2">
    <section class="pp-card" aria-labelledby="ppCheckinTitle">
      <div class="pp-head">
        <h2 id="ppCheckinTitle">&#127873; Daily Check-In</h2>
        <span class="pp-timer">&#9201; <span id="ppTimer">--</span></span>
      </div>
      <p class="pp-note" id="ppStreak" aria-live="polite">Log in every day and get +5 points!</p>
      <div class="pp-calendar" id="ppCalendar" role="list" aria-label="Recent daily check-ins"></div>
      <div class="pp-checkin-foot">
        <button class="pp-btn" type="button" id="ppCheckin">Claim daily check-in (+5 points)</button>
        <span class="pp-note" id="ppCheckinNote" aria-live="polite"></span>
      </div>
    </section>

    <section class="pp-card" aria-labelledby="ppRedeemTitle">
      <div class="pp-head"><h2 id="ppRedeemTitle">&#127942; Redeem Rewards</h2></div>
      <p class="pp-note">Use your points to get amazing rewards! If Premium is active, redeem after it ends.</p>
      <div class="pp-tiers">
        <div class="pp-tier is-live">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppCrown"/></svg>
          <strong>500 Points</strong><span>31 days Premium</span>
          <button class="pp-btn pp-btn-pink" type="button" id="ppRedeem">Redeem</button>
        </div>
        <div class="pp-tier">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGemShape"/></svg>
          <strong>1,000 Points</strong><span>31 days Premium Plus</span>
          <button class="pp-btn pp-btn-pink" type="button" id="ppRedeem1000">Redeem</button>
        </div>
        <div class="pp-tier">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGift"/></svg>
          <strong>2,500 Points</strong><span>93 days Premium Plus</span>
          <button class="pp-btn pp-btn-pink" type="button" id="ppRedeem2500">Redeem</button>
        </div>
      </div>
      <p class="pp-note" id="ppRedeemNote" aria-live="polite" style="margin-top:10px">You need 500 points to redeem one month of Premium.</p>
    </section>
  </div>

  <div class="pp-row pp-row-3">
    <section class="pp-card" aria-labelledby="ppMonthlyTitle">
      <div class="pp-head">
        <h2 id="ppMonthlyTitle">&#128200; Your Activity Overview</h2>
        <span class="pp-chip" id="ppChip">Last 6 months</span>
      </div>
      <div class="pp-line" id="ppLineChart"><p class="pp-note">Sign in to view your activity.</p></div>
    </section>
    <section class="pp-card" aria-labelledby="ppLeadersTitle">
      <div class="pp-head"><h2 id="ppLeadersTitle">&#127942; Top Earners</h2></div>
      <ul class="pp-leaders" id="ppLeaders"><li>Loading leaderboard…</li></ul>
    </section>
    <section class="pp-card pp-vip" aria-labelledby="ppVipTitle">
      <h2 id="ppVipTitle">Become a <span>VIP Member!</span></h2>
      <ul class="pp-perks">
        <li>Unlock exclusive rewards</li>
        <li>Enjoy an ad-free experience</li>
        <li>Support your favorite creators</li>
      </ul>
      <a class="pp-btn pp-btn-sm" href="premium.php">Upgrade to VIP &rarr;</a>
      <svg class="pp-stat-art" aria-hidden="true"><use href="#ppCrown"/></svg>
    </section>
  </div>
</main>
</div>
<div class="pp-toast" id="ppToast" role="status"></div>
<dialog class="pp-dialog" id="ppRedeemDialog" aria-labelledby="ppRedeemDialogTitle">
  <h2 id="ppRedeemDialogTitle">Confirm Premium redemption</h2>
  <p id="ppRedeemDialogText">Redeem 500 points for 31 days of Premium? The points will be deducted from your balance.</p>
  <div class="pp-dialog-actions">
    <button class="pp-btn-secondary" type="button" id="ppRedeemCancel">Cancel</button>
    <button class="pp-btn pp-btn-pink" type="button" id="ppRedeemConfirm">Redeem 500 points</button>
  </div>
</dialog>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var busy = false, $ = function (id) { return document.getElementById(id); };
  var TIERS = [
    { id: 'ppRedeem', cost: 500, label: '31 days of Premium' },
    { id: 'ppRedeem1000', cost: 1000, label: '31 days of Premium Plus' },
    { id: 'ppRedeem2500', cost: 2500, label: '93 days of Premium Plus' }
  ];
  var pendingTier = null;
  function toast(message) {
    var element = $('ppToast');
    element.textContent = message;
    element.classList.add('show');
    setTimeout(function () { element.classList.remove('show'); }, 4500);
  }
  function expiryText(status) {
    if (!status || typeof status !== 'object') return '';
    var n = Number(status.expires);   // premium.js gives expires as milliseconds
    if (!n) return '';
    var d = new Date(n > 1e12 ? n : n * 1000);
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  }
  function burst(from) {
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    var r = from.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    for (var i = 0; i < 14; i += 1) {
      var c = document.createElement('i'), ang = (Math.PI * 2 * i) / 14, dist = 50 + Math.random() * 60;
      c.className = 'pp-coin-burst';
      c.style.left = cx + 'px'; c.style.top = cy + 'px';
      c.style.setProperty('--dx', Math.cos(ang) * dist + 'px');
      c.style.setProperty('--dy', Math.sin(ang) * dist - 30 + 'px');
      document.body.appendChild(c);
      setTimeout((function (n) { return function () { n.remove(); }; })(c), 950);
    }
  }
  var probe = new Image();
  probe.onload = function () { document.querySelector('.pp-hero').classList.add('has-art'); };
  probe.src = 'assets/premium-hero.webp';
  function render() {
    if (typeof VSPremium === 'undefined') {
      $('ppPoints').textContent = 'Unavailable';
      $('ppPointsNote').textContent = 'Premium points are temporarily unavailable.';
      $('ppStatus').textContent = 'Unavailable';
      $('ppStatusNote').textContent = 'Premium status could not be loaded.';
      $('ppCheckin').disabled = true;
      TIERS.forEach(function (t) { $(t.id).disabled = true; });
      return;
    }
    var points = VSPremium.points(), available = VSPremium.pointsAvailable();
    var loggedIn = VSPremium.LOGGED_IN, status = VSPremium.status();
    var claimed = VSPremium.checkinClaimed();
    $('ppPoints').textContent = available ? points.toLocaleString() : 'Unavailable';
    var progress = available ? Math.min(points, 500) : 0;
    $('ppProgressFill').style.width = (progress / 5) + '%';
    $('ppProgress').setAttribute('aria-valuenow', String(progress));
    $('ppPointsNote').textContent = !loggedIn ? 'Sign in to earn points.'
      : (!available ? 'Your balance could not be loaded.'
        : (points >= 500 ? 'Enough for a Premium month!' : points.toLocaleString() + ' / 500 to your next Premium month'));

    $('ppStatus').textContent = !loggedIn ? 'Signed out' : (status ? 'ACTIVE \u2713' : 'Not active');
    $('ppStatusCard').classList.toggle('is-off', !status);
    $('ppStatusNote').textContent = !loggedIn ? 'Sign in to see your status.'
      : (status ? (expiryText(status) ? 'Expires: ' + expiryText(status) : 'Premium is active on your account.')
        : 'Redeem 500 points to activate.');

    var checkin = $('ppCheckin');
    checkin.disabled = busy || !loggedIn || !available || claimed;
    checkin.textContent = !loggedIn ? 'Sign in to check in'
      : (!available ? 'Check-in unavailable'
        : (claimed ? 'Checked in today' : 'Claim daily check-in (+5 points)'));
    $('ppCheckinNote').textContent = !loggedIn ? 'Sign in to claim your daily 5 points.'
      : (claimed ? 'Come back tomorrow for more.' : '');

    TIERS.forEach(function (t) {
      var b = $(t.id);
      b.disabled = busy || !loggedIn || !available || !!status || points < t.cost;
      b.textContent = !loggedIn ? 'Sign in' : (status ? 'Active' : (!available ? 'Unavailable'
        : (points < t.cost ? (t.cost - points) + ' to go' : 'Redeem')));
    });
    $('ppRedeemNote').textContent = status
      ? 'Redeem your points after your current Premium period ends.'
      : (!loggedIn ? 'Sign in to earn and redeem points.'
        : (points < 500 ? 'You need ' + (500 - points) + ' more points to redeem Premium.'
          : 'Your balance is ready to redeem for one 31-day Premium period.'));
  }
  function el(ns, name, attrs) {
    var node = document.createElementNS(ns, name);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    return node;
  }
  function drawLine(months) {
    var host = $('ppLineChart'), ns = 'http://www.w3.org/2000/svg';
    host.textContent = '';
    var W = 520, H = 170, L = 34, R = 10, T = 12, B = 24;
    var vals = months.map(function (m) { return Number(m.earned) || 0; });
    var max = Math.max.apply(null, vals.concat([5]));
    var svg = el(ns, 'svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Points earned per month' });
    [0, .5, 1].forEach(function (f) {
      var y = T + (H - T - B) * (1 - f);
      svg.appendChild(el(ns, 'line', { x1: L, x2: W - R, y1: y, y2: y, stroke: 'rgba(184,107,255,.25)', 'stroke-dasharray': '3 4' }));
      var t = el(ns, 'text', { x: L - 6, y: y + 4, 'text-anchor': 'end' });
      t.textContent = Math.round(max * f).toLocaleString();
      svg.appendChild(t);
    });
    var step = (W - L - R) / (vals.length - 1 || 1);
    var pts = vals.map(function (v, i) { return [L + step * i, T + (H - T - B) * (1 - v / max)]; });
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0] + ' ' + p[1]; }).join(' ');
    svg.appendChild(el(ns, 'path', { d: line + ' L' + pts[pts.length - 1][0] + ' ' + (H - B) + ' L' + L + ' ' + (H - B) + ' Z', fill: 'url(#ppArea)' }));
    svg.appendChild(el(ns, 'path', { d: line, fill: 'none', stroke: '#b86bff', 'stroke-width': 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
    pts.forEach(function (p, i) {
      svg.appendChild(el(ns, 'circle', { cx: p[0], cy: p[1], r: 4.5, fill: '#fff', stroke: '#b86bff', 'stroke-width': 2 }));
      var t = el(ns, 'text', { x: p[0], y: H - 6, 'text-anchor': 'middle' });
      t.textContent = months[i].label;
      svg.appendChild(t);
    });
    host.appendChild(svg);
  }
  function renderRewards(rewards) {
    var calendar = $('ppCalendar');
    calendar.textContent = '';
    var checkedIn = Object.create(null);
    (rewards.checkinDates || []).forEach(function (d) { checkedIn[d] = true; });
    var today = new Date(rewards.today + 'T00:00:00');
    for (var offset = 6; offset >= 0; offset -= 1) {
      var date = new Date(today.getFullYear(), today.getMonth(), today.getDate() - offset);
      var key = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
      var done = !!checkedIn[key], label = date.toLocaleDateString(undefined, { weekday: 'short' });
      var day = document.createElement('div');
      day.className = 'pp-day' + (done ? ' is-claimed' : '') + (key === rewards.today ? ' is-today' : '');
      day.setAttribute('role', 'listitem');
      day.setAttribute('aria-label', label + ', ' + date.toLocaleDateString() + (done ? ': checked in' : ': not checked in'));
      var a = document.createElement('span'); a.textContent = label;
      var tick = document.createElement('span'); tick.className = 'pp-tick'; tick.textContent = done ? '\u2713' : '';
      var pts = document.createElement('b'); pts.textContent = '+5';
      day.appendChild(a); day.appendChild(tick); day.appendChild(pts);
      calendar.appendChild(day);
    }
    var streak = Number(rewards.checkinStreak) || 0;
    var toBonus = 7 - (streak % 7), nextWeeks = Math.floor((streak + toBonus) / 7);
    var nextBonus = [50, 100, 150][Math.min(3, nextWeeks) - 1];
    $('ppStreak').textContent = '+5 points a day \u00B7 \uD83D\uDD25 ' + streak + '-day streak \u00B7 +' + nextBonus
      + ' bonus in ' + toBonus + ' day' + (toBonus === 1 ? '' : 's');
    $('ppStreakNum').textContent = String(streak);
    $('ppStreakFill').style.width = (Math.min(streak, 7) / 7 * 100) + '%';

    var lifetime = Number(rewards.lifetimeEarned) || 0;
    $('ppLifetime').textContent = lifetime.toLocaleString();
    var levels = [['Bronze', 0], ['Silver', 250], ['Gold', 1000], ['Platinum', 2500], ['Diamond', 5000]], levelName = 'Bronze';
    levels.forEach(function (l) { if (lifetime >= l[1]) levelName = l[0]; });
    $('ppLifetimeNote').textContent = levelName + ' level' + (rewards.rank ? ' \u00B7 Rank #' + rewards.rank : '');

    var totals = Object.create(null);
    (rewards.monthlySummary || []).forEach(function (m) { totals[m.month] = m; });
    var months = [];
    for (var i = 5; i >= 0; i -= 1) {
      var d = new Date(today.getFullYear(), today.getMonth() - i, 1);
      var k = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
      var s = totals[k] || { earned: 0 };
      months.push({ earned: s.earned, label: d.toLocaleDateString(undefined, { month: 'short' }) });
    }
    drawLine(months);
    var cur = Number(months[5].earned) || 0, prev = Number(months[4].earned) || 0;
    var chip = $('ppChip');
    chip.textContent = cur.toLocaleString() + ' pts this month';
    if (prev > 0) {
      var change = Math.round((cur - prev) / prev * 1000) / 10;
      var em = document.createElement('em');
      em.textContent = (change >= 0 ? '+' : '') + change + '%';
      chip.appendChild(em);
    }
  }
  function loadLeaders() {
    var list = $('ppLeaders');
    function msg(text) { list.textContent = ''; var li = document.createElement('li'); li.textContent = text; list.appendChild(li); }
    if (typeof VSPremium === 'undefined' || typeof VSPremium.leaderboard !== 'function') { msg('Leaderboard coming soon.'); return; }
    VSPremium.leaderboard().then(function (rows) {
      rows = (rows || []).slice(0, 3);
      if (!rows.length) { msg('No earners yet. Be the first!'); return; }
      list.textContent = '';
      rows.forEach(function (row, i) {
        var li = document.createElement('li'); li.className = 'is-row';
        var rank = document.createElement('span'); rank.className = 'pp-rank'; rank.textContent = String(i + 1);
        var name = document.createElement('span'); name.className = 'pp-leader-name'; name.textContent = (row.name || 'Member') + (row.me ? ' (you)' : '');
        var pts = document.createElement('span'); pts.className = 'pp-leader-pts'; pts.textContent = Number(row.points || 0).toLocaleString() + ' pts';
        li.appendChild(rank);
        if (row.avatar) {
          var av = document.createElement('img');
          av.src = row.avatar; av.alt = ''; av.width = 28; av.height = 28;
          av.style.cssText = 'border-radius:50%;object-fit:cover;flex:0 0 28px';
          li.appendChild(av);
        }
        li.appendChild(name); li.appendChild(pts);
        list.appendChild(li);
      });
    }).catch(function () { msg('Leaderboard unavailable.'); });
  }
  function tickTimer() {
    var now = new Date(), next = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1);
    var s = Math.max(0, Math.floor((next - now) / 1000));
    function p(n) { return String(n).padStart(2, '0'); }
    $('ppTimer').textContent = p(Math.floor(s / 3600)) + 'h ' + p(Math.floor(s % 3600 / 60)) + 'm ' + p(s % 60) + 's';
  }
  function loadRewards() {
    var hasPremium = typeof VSPremium !== 'undefined';
    if (!hasPremium || !VSPremium.LOGGED_IN || !VSPremium.pointsAvailable()) {
      $('ppCalendar').textContent = hasPremium && VSPremium.LOGGED_IN
        ? 'Check-in details are temporarily unavailable.' : 'Sign in to view your check-in calendar.';
      return Promise.resolve();
    }
    return VSPremium.rewards().then(function (rewards) {
      if (!rewards) throw new Error('Rewards details were not returned.');
      renderRewards(rewards);
    }).catch(function (error) {
      $('ppCalendar').textContent = 'Could not load your rewards: ' + error.message;
    });
  }
  function run(job, successMessage) {
    busy = true;
    render();
    job().then(function (result) {
      toast(typeof successMessage === 'function' ? successMessage(result) : successMessage);
      busy = false;
      render();
      loadRewards();
    }, function (error) {
      toast(error.message);
      busy = false;
      render();
    });
  }
  $('ppCheckin').onclick = function () {
    if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
    if (!VSPremium.pointsAvailable() || VSPremium.checkinClaimed()) return;
    run(function () { return VSPremium.checkin(); }, function (awarded) {
      if (awarded) burst($('ppCheckin'));
      return awarded ? 'Daily check-in complete! 5 points added.' : 'You already claimed today’s check-in.';
    });
  };
  TIERS.forEach(function (t) {
    $(t.id).onclick = function () {
      if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
      if (!VSPremium.pointsAvailable() || VSPremium.points() < t.cost || VSPremium.status()) return;
      pendingTier = t;
      $('ppRedeemDialogText').textContent = 'Redeem ' + t.cost.toLocaleString() + ' points for ' + t.label
        + '? The points will be deducted from your balance.';
      $('ppRedeemConfirm').textContent = 'Redeem ' + t.cost.toLocaleString() + ' points';
      $('ppRedeemDialog').showModal();
    };
  });
  $('ppRedeemCancel').onclick = function () { pendingTier = null; $('ppRedeemDialog').close(); };
  $('ppRedeemConfirm').onclick = function () {
    var t = pendingTier;
    pendingTier = null;
    $('ppRedeemDialog').close();
    if (!t || !VSPremium.LOGGED_IN || !VSPremium.pointsAvailable()
      || VSPremium.points() < t.cost || VSPremium.status()) return;
    run(function () { return VSPremium.redeem(t.cost); },
      t.cost.toLocaleString() + ' points redeemed. You now have ' + t.label + '.');
  };
  document.addEventListener('vs-premium-change', render);
  setInterval(tickTimer, 1000);
  tickTimer();
  render();
  loadRewards();
  loadLeaders();
});
</script>
</body>
</html>