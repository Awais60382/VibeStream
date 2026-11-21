<?php
include __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar-navigation.php';
?>
<style>
/* ---------- theme tokens ---------- */
html:not([data-theme="light"]){
  --pp-page-bg:#07030f;
  --pp-glow-bg:radial-gradient(900px 420px at 88% -6%,rgba(139,60,255,.22),transparent 70%),radial-gradient(700px 420px at 0 100%,rgba(255,46,147,.10),transparent 70%);
  --pp-sidebar:linear-gradient(180deg,#0d0620,#07030f);
  --pp-gold:#ffc23d;--pp-pink:#ff2e93;--pp-green:#2fe08a;--pp-purple:#a855f7;
  --pp-text:#f6f2ff;--pp-muted:#b6abd6;--pp-line:rgba(139,92,246,.38);
  --pp-surface:#120a2a;
  --pp-card:linear-gradient(145deg,rgba(30,17,62,.94),rgba(13,7,32,.97));
  --pp-row:rgba(139,92,246,.10);
  --pp-shadow:0 0 0 1px rgba(139,92,246,.10),0 14px 40px -24px rgba(139,60,255,.8);
}
html[data-theme="light"]{
  --pp-page-bg:#f5f1ff;
  --pp-glow-bg:radial-gradient(800px 380px at 88% -6%,rgba(168,85,247,.18),transparent 70%);
  --pp-sidebar:linear-gradient(180deg,#fff,#f5f1ff);
  --pp-gold:#8a5a00;--pp-pink:#d41d78;--pp-green:#168a50;--pp-purple:#7b2fd6;
  --pp-text:#1a1035;--pp-muted:#5d5580;--pp-line:rgba(123,47,214,.28);
  --pp-surface:#fff;
  --pp-card:linear-gradient(145deg,#fff,#f6f0ff);
  --pp-row:rgba(123,47,214,.06);
  --pp-shadow:0 12px 34px -26px rgba(92,64,160,.5);
}
html body{background-color:var(--pp-page-bg);background-image:var(--pp-glow-bg);background-attachment:fixed;color:var(--pp-text)}
.pp-defs{position:absolute;width:0;height:0;overflow:hidden}

/* ---------- layout + sidebar ---------- */
.pp-layout{display:flex;align-items:flex-start}
.pp-layout>.app-sidebar{flex:0 0 220px;width:220px;position:sticky!important;top:74px!important;height:calc(100vh - 74px);overflow-y:auto;transform:none!important;z-index:1;border-right:1px solid var(--pp-line);padding:8px 0;box-sizing:border-box;background:var(--pp-sidebar);display:flex;flex-direction:column}
.pp-layout .sidebar-top{display:none}
.pp-layout .app-sidebar a{color:inherit;text-decoration:none}
.pp-layout .sidebar-block{display:block;padding:8px 12px;border-bottom:1px solid var(--pp-line)}
.pp-layout .sidebar-block-title{display:flex;align-items:center;gap:6px;margin:0;padding:10px 12px;font-size:16px;font-weight:700}
.pp-layout .sidebar-link{display:flex;align-items:center;gap:20px;padding:10px 12px;border-radius:12px;font-size:14px;line-height:1.3;transition:background .2s,box-shadow .2s}
.pp-layout .sidebar-link:hover{background:rgba(139,92,246,.16)}
.pp-layout .app-sidebar .sidebar-link.active{background:linear-gradient(90deg,#7c3aed,#a855f7 70%,#c084fc)!important;color:#fff!important;font-weight:700;box-shadow:inset 0 0 0 1px rgba(216,180,254,.5),0 0 20px rgba(168,85,247,.55)!important}
.pp-layout .app-sidebar .sidebar-link.active .sidebar-icon,.pp-layout .app-sidebar .sidebar-link.active .sidebar-label{color:#fff!important}
.pp-layout>.app-sidebar>*{flex-shrink:0}
.pp-layout .app-sidebar .sidebar-gopremium{display:none!important}   /* replaced by the account card below */
.pp-layout .sidebar-icon,.pp-layout .sidebar-icon svg,.pp-layout .sidebar-icon img{width:24px;height:24px}
.pp-layout .sidebar-icon{display:inline-flex;flex:0 0 24px}
.pp-layout .sidebar-icon img{object-fit:contain}
.pp-layout .sidebar-account{display:flex;align-items:center;gap:10px;padding:14px 20px}
.pp-layout .sidebar-account-copy{display:flex;flex-direction:column;line-height:1.25;min-width:0}
.pp-layout .sidebar-account-copy small{opacity:.65}
.pp-layout .sidebar-account img,.pp-layout .sidebar-account-avatar{width:34px;height:34px;border-radius:50%;object-fit:cover}
.pp-page{flex:1;min-width:0;max-width:1240px;margin:0 auto;padding:18px clamp(12px,2vw,26px) 56px}

/* sidebar "Premium Account" card (gold outline, like the mockup) */
.pp-account{margin:auto 12px 12px;padding:16px 12px 14px;border-radius:16px;border:1px solid rgba(255,194,61,.7);background:radial-gradient(160px 90px at 50% 0,rgba(255,194,61,.16),transparent 80%),rgba(20,12,4,.35);box-shadow:0 0 22px rgba(255,194,61,.16);text-align:center;color:var(--pp-text)}
.pp-account.is-free{border-color:rgba(255,194,61,.7)}
.pp-account svg{width:38px;height:38px;animation:ppFloat 3.4s ease-in-out infinite}
.pp-account strong{display:block;margin-top:4px;font-size:15px;color:var(--pp-gold)}
.pp-account small{display:block;margin:3px 0 12px;color:var(--pp-muted);font-size:12px}
.pp-account .pp-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;box-sizing:border-box;padding:9px 6px;font-size:13px}

/* ---------- shared bits ---------- */
.pp-card{position:relative;border:1px solid var(--pp-line);background:var(--pp-card);color:var(--pp-text);box-shadow:var(--pp-shadow);border-radius:18px;padding:18px 20px;overflow:hidden}
.pp-card h2{margin:0;font-size:19px;font-weight:800}
.pp-card p{color:var(--pp-muted);line-height:1.45;margin:4px 0}
.pp-note{font-size:12px!important}
.pp-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pp-head h2{flex:1;min-width:0}
.pp-head-ico{width:30px;height:30px;flex:0 0 30px;color:var(--pp-purple)}
.pp-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;padding:11px 22px;background:linear-gradient(90deg,#ffc23d,#ff9a2e);color:#2a1300;font:inherit;font-weight:800;cursor:pointer;text-decoration:none;box-shadow:0 0 20px rgba(255,170,50,.35);transition:transform .15s,box-shadow .15s}
.pp-btn:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 0 30px rgba(255,170,50,.6)}
.pp-btn:disabled{opacity:.55;cursor:default;box-shadow:none}
.pp-btn-purple{background:linear-gradient(90deg,#7c3aed,#a855f7);color:#fff;box-shadow:0 0 18px rgba(139,60,255,.45)}
.pp-btn-purple:hover:not(:disabled){box-shadow:0 0 28px rgba(168,85,247,.75)}
.pp-btn-outline{background:transparent;color:var(--pp-gold);border:1px solid rgba(255,194,61,.8);box-shadow:none}
.pp-btn-outline:hover:not(:disabled){background:rgba(255,194,61,.12);box-shadow:0 0 18px rgba(255,194,61,.3)}
.pp-btn-sm{padding:8px 16px;font-size:13px}
.pp-btn:focus-visible,.pp-dialog button:focus-visible{outline:3px solid #ffb020;outline-offset:3px}

/* ---------- hero ---------- */
.pp-hero{position:relative;display:flex;align-items:center;min-height:200px;padding:26px clamp(18px,3vw,34px);border-radius:22px;border:1px solid rgba(168,85,247,.7);background:radial-gradient(520px 260px at 90% 0,rgba(168,85,247,.45),transparent 70%),linear-gradient(100deg,#12062b 0%,#2a0d63 52%,#5a1bb0 100%);box-shadow:0 0 0 1px rgba(168,85,247,.15),0 0 34px rgba(139,60,255,.35);overflow:hidden;color:#fff}
/* the artwork: assets/rewards-hero.png (crown, coins, gifts); the SVG below is the fallback if the file is missing */
.pp-hero::before{content:"";position:absolute;top:0;right:0;bottom:0;width:62%;background:url('assets/rewards-hero.png') right center/auto 100% no-repeat;opacity:0;transition:opacity .4s;pointer-events:none;-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 32%);mask-image:linear-gradient(90deg,transparent 0,#000 32%)}
.pp-hero.has-art::before{opacity:1}
.pp-hero.has-art .pp-hero-art{visibility:hidden}
.pp-hero-copy{position:relative;z-index:1;max-width:620px}
.pp-title-row{display:flex;align-items:center;gap:16px}
.pp-title-gift{width:54px;height:54px;flex:0 0 54px;filter:drop-shadow(0 0 14px rgba(168,85,247,.7))}
.pp-hero h1{margin:0;font-size:clamp(28px,4.2vw,46px);line-height:1.05;letter-spacing:-.02em;font-weight:800}
.pp-hero h1 span{background:linear-gradient(90deg,#b86bff,#d8a8ff);-webkit-background-clip:text;background-clip:text;color:transparent}
.pp-hero-sub{margin:6px 0 0;color:#e7dcff;font-size:clamp(14px,1.6vw,18px)}
.pp-pill{display:inline-flex;align-items:center;gap:10px;margin-top:18px;padding:11px 20px;border:2px solid #ffc23d;border-radius:14px;background:rgba(40,20,0,.45);color:#ffd36b;font-weight:800;font-size:15px;text-decoration:none;box-shadow:0 0 18px rgba(255,194,61,.25);transition:box-shadow .15s,transform .15s}
.pp-pill:hover{transform:translateY(-1px);box-shadow:0 0 28px rgba(255,194,61,.5)}
.pp-pill svg{width:22px;height:22px}
.pp-hero-art{position:absolute;right:3%;top:50%;width:min(36%,330px);transform:translateY(-50%);filter:drop-shadow(0 0 24px rgba(255,176,32,.4))}

/* ---------- stats ---------- */
.pp-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:14px}
.pp-stat{display:flex;align-items:center;gap:14px;padding:16px 18px}
.pp-stat-ico{display:grid;place-items:center;width:58px;height:58px;flex:0 0 58px;border-radius:50%;border:1px solid var(--pp-line);background:radial-gradient(circle at 50% 30%,rgba(168,85,247,.35),rgba(30,17,62,.8))}
.pp-stat-ico svg{width:30px;height:30px}
.pp-stat-body{min-width:0;flex:1;display:flex;flex-direction:column;gap:2px}
.pp-lab{font-size:13px;color:var(--pp-muted);font-weight:600}
.pp-val{font-size:clamp(24px,2.6vw,32px);font-weight:800;line-height:1.1;display:flex;align-items:baseline;gap:6px;flex-wrap:wrap}
.pp-val small{font-size:14px;color:var(--pp-muted);font-weight:600}
.pp-stat-points .pp-val{color:var(--pp-text)}
.pp-stat-status .pp-val{color:var(--pp-green)}
.pp-stat-status.is-off .pp-val{color:var(--pp-muted)}
.pp-stat-green .pp-stat-ico{background:radial-gradient(circle at 50% 30%,rgba(47,224,138,.3),rgba(8,30,22,.8));border-color:rgba(47,224,138,.5)}
.pp-stat-fire .pp-stat-ico{background:radial-gradient(circle at 50% 30%,rgba(255,120,40,.3),rgba(40,16,6,.8));border-color:rgba(255,140,60,.5)}
.pp-bar{height:7px;margin-top:6px;overflow:hidden;border-radius:999px;background:rgba(128,128,128,.25)}
.pp-bar span{display:block;width:0;height:100%;border-radius:inherit;background:linear-gradient(90deg,#7c3aed,#c084fc);transition:width .25s}
.pp-bar.is-green span{background:linear-gradient(90deg,#16a34a,#2fe08a)}

/* ---------- rows ---------- */
.pp-row{display:grid;gap:14px;margin-top:14px}
.pp-row-2{grid-template-columns:minmax(0,1.15fr) minmax(0,1fr)}

/* daily check-in */
.pp-sub{margin:6px 0 0!important}
.pp-streak-pill{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:999px;border:1px solid rgba(255,140,60,.7);background:rgba(255,120,40,.10);color:#ffd36b;font-weight:800;font-size:13px;white-space:nowrap}
.pp-streak-pill svg{width:18px;height:18px}
.pp-week{position:relative;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:4px;margin:22px 0 16px}
.pp-week::before{content:"";position:absolute;left:7%;right:7%;top:26px;height:2px;background:var(--pp-line)}
.pp-day{position:relative;display:grid;justify-items:center;gap:8px;color:var(--pp-muted);font-size:13px;font-weight:700}
.pp-day .pp-tick{display:grid;place-items:center;width:52px;height:52px;border-radius:50%;border:1px solid var(--pp-line);background:#150c30;font-size:13px;font-weight:800;color:var(--pp-gold)}
html[data-theme="light"] .pp-day .pp-tick{background:#efe8ff}
.pp-day .pp-tick svg{width:24px;height:24px}
.pp-day.is-claimed{color:var(--pp-text)}
.pp-day.is-claimed .pp-tick{border-color:rgba(47,224,138,.7);background:radial-gradient(circle at 50% 30%,rgba(47,224,138,.25),rgba(6,30,20,.95));box-shadow:0 0 12px rgba(47,224,138,.25)}
.pp-day.is-today{color:var(--pp-text)}
.pp-day.is-today .pp-tick{border:2px solid #b86bff;box-shadow:0 0 0 3px rgba(168,85,247,.25),0 0 22px rgba(168,85,247,.7)}
.pp-day.is-today:not(.is-claimed) .pp-tick{animation:ppPulse 1.8s ease-in-out infinite}
.pp-day.is-future .pp-tick{opacity:.75}
.pp-checkin-foot{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pp-timer{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;border:1px solid var(--pp-line);background:var(--pp-row);font-weight:800;font-size:12px;font-variant-numeric:tabular-nums}

/* redeem */
.pp-tiers{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
.pp-tier{display:flex;flex-direction:column;align-items:center;gap:2px;padding:14px 8px 12px;border:1px solid var(--pp-line);border-radius:16px;background:var(--pp-row);text-align:center}
.pp-tier svg{width:42px;height:42px;margin-bottom:6px}
.pp-tier strong{font-size:15px}
.pp-tier span{color:var(--pp-muted);font-size:12px;margin-bottom:10px}
.pp-tier .pp-btn{width:100%;padding:9px 4px;font-size:13px}
.pp-tier.is-live:hover{border-color:rgba(192,132,252,.8);box-shadow:0 0 20px rgba(168,85,247,.3)}

/* chart + leaders */
.pp-chip{padding:5px 12px;border-radius:12px;border:1px solid var(--pp-line);background:var(--pp-row);font-weight:800;font-size:13px}
.pp-chip em{font-style:normal;color:var(--pp-green);margin-left:6px}
.pp-line{margin:10px 0 0}
.pp-line svg{width:100%;height:auto;display:block;overflow:visible}
.pp-line text{fill:var(--pp-muted);font-size:11px}
.pp-leaders{list-style:none;margin:12px 0 0;padding:0;display:grid;gap:8px}
.pp-leaders li{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:12px;background:var(--pp-row);color:var(--pp-muted);font-size:13px}
.pp-leaders li.is-row{color:var(--pp-text)}
.pp-rank{position:relative;display:grid;place-items:center;width:28px;height:28px;flex:0 0 28px;border-radius:50%;font-weight:900;font-size:12px;background:linear-gradient(135deg,#ffe27a,#e79300);color:#3a2500;box-shadow:0 0 10px rgba(255,194,61,.5)}
.pp-leaders li:nth-child(2) .pp-rank{background:linear-gradient(135deg,#f1f1ff,#9aa3d6);box-shadow:0 0 10px rgba(180,190,240,.4)}
.pp-leaders li:nth-child(3) .pp-rank{background:linear-gradient(135deg,#ffb27a,#c4622a);box-shadow:0 0 10px rgba(220,120,60,.4)}
.pp-leader-avatar{position:relative;display:grid;place-items:center;width:36px;height:36px;flex:0 0 36px;overflow:hidden;border:2px solid rgba(168,85,247,.8);border-radius:50%;background:linear-gradient(135deg,#7c3aed,#ff2e93);color:#fff;font-size:13px;font-weight:800}
.pp-leader-avatar img{position:absolute;inset:0;width:100%;height:100%;border-radius:inherit;object-fit:cover}
.pp-leader-name{flex:1;min-width:0;font-weight:700;color:#c084fc;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
html[data-theme="light"] .pp-leader-name{color:#7b2fd6}
.pp-vip-badge{flex:0 0 auto;padding:2px 6px;border:1px solid rgba(255,194,61,.7);border-radius:999px;background:linear-gradient(135deg,#ffe27a,#e79300);color:#3a2500;font-size:9px;font-weight:900;line-height:1.2;letter-spacing:.04em}
.pp-leader-pts{color:var(--pp-text);font-weight:800;font-size:13px;white-space:nowrap}
.pp-leader-pts::after{content:"\2605";color:var(--pp-gold);margin-left:8px}

/* VIP banner */
.pp-vipbar{position:relative;display:flex;align-items:center;gap:clamp(14px,3vw,34px);margin-top:14px;padding:18px clamp(18px,3vw,34px);border:1px solid rgba(255,194,61,.75);border-radius:20px;background:radial-gradient(420px 200px at 0 50%,rgba(255,194,61,.18),transparent 70%),radial-gradient(500px 220px at 100% 50%,rgba(168,85,247,.28),transparent 70%),linear-gradient(100deg,#1a0d02,#241046);box-shadow:0 0 30px rgba(255,194,61,.18);color:#fff;overflow:hidden}
.pp-vipbar>svg{width:96px;height:96px;flex:0 0 96px;filter:drop-shadow(0 0 20px rgba(255,194,61,.55));animation:ppFloat 4s ease-in-out infinite}
.pp-vipbar-copy{flex:1;min-width:0}
.pp-vipbar h2{margin:0;font-size:clamp(22px,2.6vw,30px);font-weight:800}
.pp-vipbar h2 span{color:#ffc23d}
.pp-vipbar p{margin:4px 0 0;color:#eadfff;font-size:clamp(13px,1.4vw,16px)}
.pp-vipbar .pp-btn{padding:13px 26px;font-size:16px;border-radius:14px;white-space:nowrap}

/* toast, dialog, coins */
.pp-toast{position:fixed;left:50%;bottom:24px;transform:translate(-50%,20px);max-width:90vw;padding:12px 20px;border:1px solid var(--pp-line);border-radius:12px;background:var(--pp-surface);color:var(--pp-text);box-shadow:var(--pp-shadow);opacity:0;pointer-events:none;transition:.2s;z-index:80;text-align:center}
.pp-toast.show{opacity:1;transform:translate(-50%,0)}
.pp-dialog{width:min(440px,calc(100vw - 32px));border:1px solid rgba(184,107,255,.6);border-radius:20px;padding:24px;background:var(--pp-surface);color:var(--pp-text);box-shadow:var(--pp-shadow),0 0 30px rgba(139,60,255,.3)}
.pp-dialog::backdrop{background:rgba(4,2,12,.78)}
.pp-dialog h2{margin:0 0 8px}
.pp-dialog p{color:var(--pp-muted);line-height:1.5}
.pp-dialog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
.pp-btn-secondary{border:1px solid var(--pp-line);border-radius:12px;padding:11px 18px;background:transparent;color:var(--pp-text);font:inherit;font-weight:700;cursor:pointer}
.pp-coin{position:fixed;z-index:90;width:22px;height:22px;margin:-11px 0 0 -11px;border-radius:50%;pointer-events:none;background:radial-gradient(circle at 35% 30%,#fff3b0,#ffc23d 55%,#c77a00);box-shadow:0 0 10px rgba(255,194,61,.9);animation:ppCoin .95s cubic-bezier(.2,.7,.3,1) forwards}
.pp-coin::after{content:"+5";position:absolute;inset:0;display:grid;place-items:center;font:800 9px/1 sans-serif;color:#5a3300}

@keyframes ppFloat{0%,100%{transform:translateY(0) rotate(-3deg)}50%{transform:translateY(-8px) rotate(3deg)}}
@keyframes ppPulse{0%,100%{box-shadow:0 0 0 3px rgba(168,85,247,.2),0 0 12px rgba(168,85,247,.5)}50%{box-shadow:0 0 0 5px rgba(168,85,247,.3),0 0 28px rgba(168,85,247,.95)}}
@keyframes ppCoin{0%{transform:translate(0,0) scale(.4);opacity:1}60%{opacity:1}100%{transform:translate(var(--dx),var(--dy)) scale(1) rotate(540deg);opacity:0}}

/* ---------- phones / tablets ---------- */
@media(max-width:1100px){.pp-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:900px){
  .pp-layout>.app-sidebar{display:none}
  .pp-row-2{grid-template-columns:1fr}
  .pp-hero{min-height:0;padding:22px 18px}
  .pp-hero::before{width:100%;opacity:0!important}
  .pp-hero-art{display:none}
  .pp-title-gift{width:44px;height:44px;flex-basis:44px}
  .pp-vipbar{flex-wrap:wrap}
  .pp-vipbar>svg{width:72px;height:72px;flex-basis:72px}
}
@media(max-width:560px){
  .pp-stats{grid-template-columns:1fr}
  .pp-card{padding:14px}
  .pp-day .pp-tick{width:38px;height:38px}
  .pp-day .pp-tick svg{width:18px;height:18px}
  .pp-week::before{top:19px}
  .pp-day{font-size:11px;gap:6px}
  .pp-tiers{gap:6px}
  .pp-tier strong{font-size:13px}
  .pp-vipbar .pp-btn{width:100%}
  .pp-pill{font-size:13px;padding:10px 14px}
}
@media(prefers-reduced-motion:reduce){.pp-btn,.pp-toast,.pp-bar span{transition:none}.pp-btn:hover{transform:none}.pp-account svg,.pp-vipbar>svg,.pp-day.is-today .pp-tick{animation:none!important}.pp-coin{display:none}}
</style>

<svg class="pp-defs" aria-hidden="true" focusable="false">
  <defs>
    <linearGradient id="ppCoin" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#ffe27a"/><stop offset="1" stop-color="#e79300"/></linearGradient>
    <linearGradient id="ppGem" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#f0c6ff"/><stop offset="1" stop-color="#7b2fd6"/></linearGradient>
    <linearGradient id="ppPurple" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#c084fc"/><stop offset="1" stop-color="#6d28d9"/></linearGradient>
    <linearGradient id="ppFire" x1="0" y1="1" x2="0" y2="0"><stop stop-color="#ff5a1f"/><stop offset="1" stop-color="#ffc23d"/></linearGradient>
    <linearGradient id="ppArea" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#a855f7" stop-opacity=".55"/><stop offset="1" stop-color="#a855f7" stop-opacity="0"/></linearGradient>
  </defs>
  <symbol id="ppCrown" viewBox="0 0 64 64"><path d="M8 20l14 14 10-20 10 20 14-14-6 30H14z" fill="url(#ppCoin)"/><rect x="14" y="52" width="36" height="6" rx="3" fill="#e79300"/><circle cx="32" cy="12" r="4" fill="#c084fc"/><circle cx="8" cy="20" r="3.5" fill="#ffe27a"/><circle cx="56" cy="20" r="3.5" fill="#ffe27a"/></symbol>
  <symbol id="ppGemShape" viewBox="0 0 64 64"><path d="M16 10h32l12 16-28 32L4 26z" fill="url(#ppGem)"/><path d="M4 26h56M22 10l10 48M42 10L32 58" stroke="#fff" stroke-opacity=".45" stroke-width="2" fill="none"/></symbol>
  <symbol id="ppGift" viewBox="0 0 64 64"><rect x="8" y="26" width="48" height="32" rx="4" fill="url(#ppPurple)"/><rect x="6" y="18" width="52" height="12" rx="3" fill="#a855f7"/><path d="M32 18v40" stroke="#ffc23d" stroke-width="6"/><path d="M32 18c-10-13-21-3-10 2zM32 18c10-13 21-3 10 2z" fill="#ffc23d"/></symbol>
  <symbol id="ppCoins" viewBox="0 0 64 64"><g><ellipse cx="32" cy="50" rx="26" ry="8" fill="#c77a00"/><rect x="6" y="40" width="52" height="10" fill="url(#ppCoin)"/><ellipse cx="32" cy="40" rx="26" ry="8" fill="#ffe27a"/><ellipse cx="32" cy="38" rx="26" ry="8" fill="#c77a00"/><rect x="6" y="28" width="52" height="10" fill="url(#ppCoin)"/><ellipse cx="32" cy="28" rx="26" ry="8" fill="#ffe27a"/><ellipse cx="32" cy="26" rx="26" ry="8" fill="#c77a00"/><rect x="6" y="16" width="52" height="10" fill="url(#ppCoin)"/><ellipse cx="32" cy="16" rx="26" ry="8" fill="#ffe27a"/></g></symbol>
  <symbol id="ppStar" viewBox="0 0 24 24"><path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.9L12 17.8 5.8 21.1 7 14.2 2 9.3l6.9-1z" fill="url(#ppCoin)"/></symbol>
  <symbol id="ppFlame" viewBox="0 0 24 24"><path d="M12 2c1 4 6 6 6 12a6 6 0 0 1-12 0c0-2.3 1-3.6 2.2-4.8C8.4 11.4 9.6 12 10.6 12 10 8 10.5 5 12 2z" fill="url(#ppFire)"/></symbol>
  <symbol id="ppCheck" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="#2fe08a" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></symbol>
  <symbol id="ppCalendar" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 10h18M8 3v4M16 3v4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M8 14h2M12 14h2M16 14h.01M8 17.5h2M12 17.5h2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></symbol>
  <symbol id="ppTrophy" viewBox="0 0 24 24"><path d="M7 3h10v6a5 5 0 0 1-10 0z" fill="url(#ppCoin)"/><path d="M7 5H3v2a4 4 0 0 0 4 4M17 5h4v2a4 4 0 0 1-4 4" fill="none" stroke="#e79300" stroke-width="1.8"/><path d="M12 14v4M8 21h8M9 18h6" stroke="#e79300" stroke-width="2" stroke-linecap="round"/></symbol>
  <symbol id="ppBars" viewBox="0 0 24 24"><rect x="3" y="12" width="5" height="9" rx="1.5" fill="url(#ppPurple)"/><rect x="9.5" y="5" width="5" height="16" rx="1.5" fill="url(#ppPurple)"/><rect x="16" y="9" width="5" height="12" rx="1.5" fill="url(#ppPurple)"/></symbol>
</svg>

<div class="pp-layout">
<?php vs_render_sidebar('premium'); ?>
<main class="pp-page">

  <section class="pp-hero" id="ppHero" aria-labelledby="ppTitle">
    <div class="pp-hero-copy">
      <div class="pp-title-row">
        <svg class="pp-title-gift" viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGift"/></svg>
        <div>
          <h1 id="ppTitle">Welcome to <span>Rewards</span></h1>
          <p class="pp-hero-sub">Earn points, unlock rewards, and enjoy exclusive benefits!</p>
        </div>
      </div>
      <a class="pp-pill" href="#ppCheckinTitle"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppStar"/></svg>The Ultimate Rewards Experience!</a>
    </div>
    <svg class="pp-hero-art" viewBox="0 0 300 200" role="img" aria-label="Crown above gold coins, a gift and gems">
      <use href="#ppCoins" x="20" y="96" width="90" height="90"/>
      <use href="#ppCoins" x="96" y="116" width="80" height="80"/>
      <use href="#ppCrown" x="86" y="2" width="100" height="100"/>
      <use href="#ppGift" x="172" y="70" width="110" height="110"/>
      <use href="#ppGemShape" x="2" y="132" width="44" height="44"/>
      <use href="#ppGemShape" x="246" y="116" width="40" height="40"/>
    </svg>
  </section>

  <div class="pp-stats">
    <section class="pp-card pp-stat pp-stat-points" aria-labelledby="ppBalanceTitle">
      <span class="pp-stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppStar"/></svg></span>
      <div class="pp-stat-body">
        <span class="pp-lab" id="ppBalanceTitle">Your Points</span>
        <div class="pp-val"><span id="ppPoints">0</span><small>pts</small></div>
        <span id="ppPointsNote" class="pp-lab pp-note" aria-live="polite">Loading your points…</span>
        <div class="pp-bar" id="ppProgress" role="progressbar" aria-label="Progress toward a Premium month" aria-valuemin="0" aria-valuemax="500" aria-valuenow="0"><span id="ppProgressFill"></span></div>
      </div>
    </section>
    <section class="pp-card pp-stat" aria-labelledby="ppLifeTitle">
      <span class="pp-stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppTrophy"/></svg></span>
      <div class="pp-stat-body">
        <span class="pp-lab" id="ppLifeTitle">Lifetime Earned</span>
        <div class="pp-val"><span id="ppLifetime">—</span><small>pts</small></div>
        <span class="pp-lab pp-note" id="ppLifetimeNote">Collect more points to unlock rewards!</span>
      </div>
    </section>
    <section class="pp-card pp-stat pp-stat-fire" aria-labelledby="ppStreakTitle">
      <span class="pp-stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppFlame"/></svg></span>
      <div class="pp-stat-body">
        <span class="pp-lab" id="ppStreakTitle">Streak</span>
        <div class="pp-val"><span id="ppStreakNum">0</span><small>Days</small></div>
        <div class="pp-bar is-green" aria-hidden="true"><span id="ppStreakFill"></span></div>
      </div>
    </section>
    <section class="pp-card pp-stat pp-stat-green pp-stat-status is-off" id="ppStatusCard" aria-labelledby="ppStatusTitle">
      <span class="pp-stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppCheck"/></svg></span>
      <div class="pp-stat-body">
        <span class="pp-lab" id="ppStatusTitle">Premium Status</span>
        <div class="pp-val"><span id="ppStatus">—</span></div>
        <span class="pp-lab pp-note" id="ppStatusNote" aria-live="polite">Loading…</span>
      </div>
    </section>
  </div>

  <div class="pp-row pp-row-2">
    <section class="pp-card" aria-labelledby="ppCheckinTitle">
      <div class="pp-head">
        <svg class="pp-head-ico" viewBox="0 0 24 24" aria-hidden="true"><use href="#ppCalendar"/></svg>
        <h2 id="ppCheckinTitle">Daily Check-In</h2>
        <span class="pp-streak-pill"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppFlame"/></svg><span id="ppStreak">Current Streak: 0 Days</span></span>
      </div>
      <p class="pp-sub">Come back daily and earn bonus points!</p>
      <p class="pp-note">Check in for +5 points every day, or publish a video or Short for +5. Streak bonus: 7 days +50 &middot; 14 days +100 &middot; 21+ days +150 every week. Existing uploads are credited once.</p>
      <div class="pp-week" id="ppCalendar" role="list" aria-label="This week's daily check-ins"></div>
      <div class="pp-checkin-foot">
        <button class="pp-btn pp-btn-purple" type="button" id="ppCheckin">Claim daily check-in (+5 points)</button>
        <span class="pp-timer">&#9201; <span id="ppTimer">--</span></span>
        <span class="pp-note" id="ppCheckinNote" aria-live="polite"></span>
      </div>
    </section>

    <section class="pp-card" aria-labelledby="ppRedeemTitle">
      <div class="pp-head">
        <svg class="pp-head-ico" viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGift"/></svg>
        <h2 id="ppRedeemTitle">Redeem Rewards</h2>
      </div>
      <p class="pp-note">Use your points to claim amazing rewards! If Premium is active, redeem after it ends.</p>
      <div class="pp-tiers">
        <div class="pp-tier is-live">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGemShape"/></svg>
          <strong>500 Points</strong><span>31 days Premium</span>
          <button class="pp-btn pp-btn-purple" type="button" id="ppRedeem">Redeem</button>
        </div>
        <div class="pp-tier is-live">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppCrown"/></svg>
          <strong>1,000 Points</strong><span>31 days Premium Plus</span>
          <button class="pp-btn pp-btn-purple" type="button" id="ppRedeem1000">Redeem</button>
        </div>
        <div class="pp-tier is-live">
          <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppGift"/></svg>
          <strong>2,500 Points</strong><span>93 days Premium Plus</span>
          <button class="pp-btn pp-btn-purple" type="button" id="ppRedeem2500">Redeem</button>
        </div>
      </div>
      <p class="pp-note" id="ppRedeemNote" aria-live="polite" style="margin-top:10px">You need 500 points to redeem one month of Premium.</p>
    </section>
  </div>

  <div class="pp-row pp-row-2">
    <section class="pp-card" aria-labelledby="ppMonthlyTitle">
      <div class="pp-head">
        <svg class="pp-head-ico" viewBox="0 0 24 24" aria-hidden="true"><use href="#ppBars"/></svg>
        <h2 id="ppMonthlyTitle">Your Activity Overview</h2>
        <span class="pp-chip" id="ppChip">Last 6 months</span>
      </div>
      <div class="pp-line" id="ppLineChart"><p class="pp-note">Sign in to view your activity.</p></div>
    </section>
    <section class="pp-card" aria-labelledby="ppLeadersTitle">
      <div class="pp-head">
        <svg class="pp-head-ico" viewBox="0 0 24 24" aria-hidden="true"><use href="#ppTrophy"/></svg>
        <h2 id="ppLeadersTitle">Top Earners</h2>
      </div>
      <ul class="pp-leaders" id="ppLeaders"><li>Loading leaderboard…</li></ul>
    </section>
  </div>

  <section class="pp-vipbar" aria-labelledby="ppVipTitle">
    <svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppCrown"/></svg>
    <div class="pp-vipbar-copy">
      <h2 id="ppVipTitle">Become a <span>VIP Member!</span></h2>
      <p>Get more points, exclusive rewards and special benefits!</p>
    </div>
    <a class="pp-btn" href="premium.php">Upgrade Now &rarr;</a>
  </section>
</main>
</div>
<div class="pp-toast" id="ppToast" role="status"></div>
<dialog class="pp-dialog" id="ppRedeemDialog" aria-labelledby="ppRedeemDialogTitle">
  <h2 id="ppRedeemDialogTitle">Confirm Premium redemption</h2>
  <p id="ppRedeemDialogText">Redeem points for Premium? The points will be deducted from your balance.</p>
  <div class="pp-dialog-actions">
    <button class="pp-btn-secondary" type="button" id="ppRedeemCancel">Cancel</button>
    <button class="pp-btn pp-btn-purple" type="button" id="ppRedeemConfirm">Redeem 500 points</button>
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
  var reduceMotion = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var ICON_CHECK = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppCheck"/></svg>';
  var ICON_STAR = '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="#ppStar"/></svg>';

  // Hero illustration: only swap in the artwork if the image actually loads.
  (function () {
    var img = new Image();
    img.onload = function () { $('ppHero').classList.add('has-art'); };
    img.src = 'assets/rewards-hero.png';
  })();

  // Sidebar "Premium Account" card
  var accountCard = null;
  (function () {
    var sidebar = document.querySelector('.pp-layout > .app-sidebar');
    if (!sidebar) return;
    accountCard = document.createElement('div');
    accountCard.className = 'pp-account is-free';
    accountCard.innerHTML = '<svg viewBox="0 0 64 64" aria-hidden="true"><use href="#ppCrown"/></svg>'
      + '<strong id="ppAcctTitle">Premium Account</strong><small id="ppAcctSub"></small>'
      + '<a class="pp-btn pp-btn-outline" id="ppAcctBtn" href="premium.php">Go Premium</a>';
    sidebar.appendChild(accountCard);
  })();

  function premiumExpiry() {
    // premium.js: status() returns {plan, since, expires}; expires is a millisecond timestamp.
    var st = (typeof VSPremium !== 'undefined' && VSPremium.status) ? VSPremium.status() : null;
    var raw = st && st.expires;
    if (!raw) return null;
    var d = new Date(Number(raw));
    return isNaN(d) ? null : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  function toast(message) {
    var element = $('ppToast');
    element.textContent = message;
    element.classList.add('show');
    setTimeout(function () { element.classList.remove('show'); }, 4500);
  }

  function burst(origin) {
    if (reduceMotion || !origin) return;
    var r = origin.getBoundingClientRect(), cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    for (var i = 0; i < 14; i += 1) {
      var coin = document.createElement('span'), angle = (Math.PI * 2 * i) / 14 + Math.random() * .4;
      var dist = 70 + Math.random() * 80;
      coin.className = 'pp-coin';
      coin.style.left = cx + 'px'; coin.style.top = cy + 'px';
      coin.style.setProperty('--dx', Math.cos(angle) * dist + 'px');
      coin.style.setProperty('--dy', (Math.sin(angle) * dist - 40) + 'px');
      coin.addEventListener('animationend', function () { this.remove(); });
      document.body.appendChild(coin);
    }
  }

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

    var expiry = status ? premiumExpiry() : null;
    $('ppStatus').textContent = !loggedIn ? 'Signed out' : (status ? 'ACTIVE \u2713' : 'Not active');
    $('ppStatusCard').classList.toggle('is-off', !status);
    $('ppStatusNote').textContent = !loggedIn ? 'Sign in to see your status.'
      : (status ? (expiry ? 'Expires: ' + expiry : 'Premium is active on your account.') : 'Redeem 500 points to activate.');

    if (accountCard) {
      accountCard.classList.toggle('is-free', !status);
      $('ppAcctTitle').textContent = status ? 'Premium Account' : (loggedIn ? 'Free Account' : 'Welcome');
      $('ppAcctSub').textContent = !loggedIn ? 'Sign in to earn rewards'
        : (status ? (expiry ? 'Expires: ' + expiry : 'Premium is active') : 'Unlock more rewards!');
      $('ppAcctBtn').textContent = !loggedIn ? 'Sign in \u2192' : (status ? 'Manage Account \u2192' : 'Go Premium \u2192');
      $('ppAcctBtn').setAttribute('href', loggedIn ? 'premium.php' : 'login.php');
    }

    var checkin = $('ppCheckin');
    checkin.disabled = busy || !loggedIn || !available || claimed;
    checkin.textContent = !loggedIn ? 'Sign in to check in'
      : (!available ? 'Check-in unavailable'
        : (claimed ? 'Checked in today' : 'Claim daily check-in (+5 points)'));
    $('ppCheckinNote').textContent = !loggedIn ? 'Sign in to claim your daily 5 points.'
      : (claimed ? 'Come back tomorrow for more.' : '');

    var nextTier = null;
    TIERS.forEach(function (t) {
      var btn = $(t.id);
      btn.disabled = busy || !loggedIn || !available || !!status || points < t.cost;
      btn.textContent = !loggedIn ? 'Sign in'
        : (status ? 'Active' : (!available ? 'Unavailable'
          : (points < t.cost ? (t.cost - points).toLocaleString() + ' to go' : 'Redeem')));
      if (!nextTier && points < t.cost) nextTier = t;
    });
    $('ppRedeemNote').textContent = status
      ? 'Redeem your points after your current Premium period ends.'
      : (!loggedIn ? 'Sign in to earn and redeem points.'
        : (nextTier ? 'You need ' + (nextTier.cost - points).toLocaleString() + ' more points for ' + nextTier.label + '.'
          : 'You can redeem any reward. Pick one above.'));
  }
  function el(ns, name, attrs) {
    var node = document.createElementNS(ns, name);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    return node;
  }
  function drawLine(months) {
    var host = $('ppLineChart'), ns = 'http://www.w3.org/2000/svg';
    host.textContent = '';
    var W = 520, H = 190, L = 38, R = 12, T = 12, B = 26;
    var vals = months.map(function (m) { return Number(m.earned) || 0; });
    var max = Math.max.apply(null, vals.concat([5]));
    var svg = el(ns, 'svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Points earned per month' });
    [0, .5, 1].forEach(function (f) {
      var y = T + (H - T - B) * (1 - f);
      svg.appendChild(el(ns, 'line', { x1: L, x2: W - R, y1: y, y2: y, stroke: 'rgba(168,85,247,.3)', 'stroke-dasharray': '3 4' }));
      var t = el(ns, 'text', { x: L - 6, y: y + 4, 'text-anchor': 'end' });
      t.textContent = Math.round(max * f).toLocaleString();
      svg.appendChild(t);
    });
    var step = (W - L - R) / (vals.length - 1 || 1);
    var pts = vals.map(function (v, i) { return [L + step * i, T + (H - T - B) * (1 - v / max)]; });
    var line = pts.map(function (p, i) { return (i ? 'L' : 'M') + p[0] + ' ' + p[1]; }).join(' ');
    svg.appendChild(el(ns, 'path', { d: line + ' L' + pts[pts.length - 1][0] + ' ' + (H - B) + ' L' + L + ' ' + (H - B) + ' Z', fill: 'url(#ppArea)' }));
    svg.appendChild(el(ns, 'path', { d: line, fill: 'none', stroke: '#a855f7', 'stroke-width': 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
    pts.forEach(function (p, i) {
      svg.appendChild(el(ns, 'circle', { cx: p[0], cy: p[1], r: 4.5, fill: '#fff', stroke: '#a855f7', 'stroke-width': 2 }));
      var t = el(ns, 'text', { x: p[0], y: H - 7, 'text-anchor': 'middle' });
      t.textContent = months[i].label;
      svg.appendChild(t);
    });
    host.appendChild(svg);
  }
  function renderRewards(rewards) {
    // This week, Monday to Sunday (days still to come show a star).
    var calendar = $('ppCalendar');
    calendar.textContent = '';
    var checkedIn = Object.create(null);
    (rewards.checkinDates || []).forEach(function (d) { checkedIn[d] = true; });
    var today = new Date(rewards.today + 'T00:00:00');
    var todayIndex = (today.getDay() + 6) % 7;
    for (var i = 0; i < 7; i += 1) {
      var date = new Date(today.getFullYear(), today.getMonth(), today.getDate() - todayIndex + i);
      var key = date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
      var done = !!checkedIn[key], isToday = i === todayIndex, future = i > todayIndex;
      var label = date.toLocaleDateString(undefined, { weekday: 'short' });
      var day = document.createElement('div');
      day.className = 'pp-day' + (done ? ' is-claimed' : '') + (isToday ? ' is-today' : '') + (future ? ' is-future' : '');
      day.setAttribute('role', 'listitem');
      day.setAttribute('aria-label', label + ', ' + date.toLocaleDateString() + (done ? ': checked in' : (future ? ': upcoming' : ': not checked in')));
      var tick = document.createElement('span'); tick.className = 'pp-tick';
      if (done) tick.innerHTML = ICON_CHECK;
      else if (future) tick.innerHTML = ICON_STAR;
      else if (isToday) tick.textContent = '+5';
      var name = document.createElement('span'); name.textContent = label;
      day.appendChild(tick); day.appendChild(name);
      calendar.appendChild(day);
    }
    var streak = Number(rewards.checkinStreak) || 0;
    $('ppStreak').textContent = 'Current Streak: ' + streak + (streak === 1 ? ' Day' : ' Days');
    $('ppStreakNum').textContent = String(streak);
    $('ppStreakFill').style.width = (Math.min(streak, 7) / 7 * 100) + '%';

    var lifetime = Number(rewards.lifetimeEarned) || 0;
    $('ppLifetime').textContent = lifetime.toLocaleString();

    var totals = Object.create(null);
    (rewards.monthlySummary || []).forEach(function (m) { totals[m.month] = m; });
    var months = [];
    for (var j = 5; j >= 0; j -= 1) {
      var d = new Date(today.getFullYear(), today.getMonth() - j, 1);
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
        var name = document.createElement('span'); name.className = 'pp-leader-name'; name.textContent = row.name || 'Member';
        var avatar = document.createElement('span'); avatar.className = 'pp-leader-avatar';
        avatar.textContent = (row.name || 'Member').trim().charAt(0).toUpperCase();
        if (row.avatar) {
          var image = document.createElement('img');
          image.src = row.avatar;
          image.alt = '';
          image.loading = 'lazy';
          image.onerror = function () { image.remove(); };
          avatar.appendChild(image);
        }
        var vip = null;
        if (row.vip) {
          vip = document.createElement('span');
          vip.className = 'pp-vip-badge';
          vip.textContent = 'VIP';
          vip.title = 'Active Premium member';
        }
        var pts = document.createElement('span'); pts.className = 'pp-leader-pts'; pts.textContent = Number(row.points || 0).toLocaleString();
        li.appendChild(rank); li.appendChild(avatar); li.appendChild(name);
        if (vip) li.appendChild(vip);
        li.appendChild(pts);
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
  function run(job, successMessage, onSuccess) {
    busy = true;
    render();
    job().then(function (result) {
      toast(typeof successMessage === 'function' ? successMessage(result) : successMessage);
      if (onSuccess) onSuccess(result);
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
    var button = $('ppCheckin');
    var before = VSPremium.points();
    run(function () { return VSPremium.checkin(); }, function (awarded) {
      if (!awarded) return 'You already claimed today’s check-in.';
      var gain = VSPremium.points() - before;
      return gain > 5 ? 'Streak bonus! ' + gain + ' points added.' : 'Daily check-in complete! 5 points added.';
    }, function (awarded) { if (awarded) burst(button); });
  };
  TIERS.forEach(function (t) {
    $(t.id).onclick = function () {
      if (!VSPremium.LOGGED_IN) { location.href = 'login.php'; return; }
      if (!VSPremium.pointsAvailable() || VSPremium.points() < t.cost || VSPremium.status()) return;
      pendingTier = t;
      $('ppRedeemDialogText').textContent = 'Redeem ' + t.cost.toLocaleString() + ' points for ' + t.label + '? The points will be deducted from your balance.';
      $('ppRedeemConfirm').textContent = 'Redeem ' + t.cost.toLocaleString() + ' points';
      $('ppRedeemDialog').showModal();
    };
  });
  $('ppRedeemCancel').onclick = function () { $('ppRedeemDialog').close(); };
  $('ppRedeemConfirm').onclick = function () {
    $('ppRedeemDialog').close();
    var t = pendingTier;
    if (!t || !VSPremium.LOGGED_IN || !VSPremium.pointsAvailable()
      || VSPremium.points() < t.cost || VSPremium.status()) return;
    run(function () { return VSPremium.redeem(t.cost); },
      t.cost.toLocaleString() + ' points redeemed. Your ' + t.label + ' is active.',
      function () { burst($('ppStatusCard')); });
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