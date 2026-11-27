<?php
/*
 * about.php - "Welcome / About us" page.
 * New members are sent here right after they sign up (see google-login.php and signup-handler.php).
 * It uses the same header.php as signup.php, so $siteName and $siteLogoUrl come from there.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$memberName = trim((string)($_SESSION['username'] ?? ''));
include 'header.php';

$about = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$brand = isset($siteName) && $siteName !== '' ? (string)$siteName : 'VibeStream';
?>
<style>
  .ab { --ab-text:#f8f5f5; --ab-muted:#c2b9ba; --ab-card:linear-gradient(145deg,#171112,#100b0c); --ab-line:rgba(255,80,86,.2);
        max-width:1600px; margin:0 auto; padding:16px 24px 40px; color:var(--ab-text); }
  html[data-bs-theme="light"] .ab, html[data-theme="light"] .ab { --ab-text:#211719; --ab-muted:#66585a; --ab-card:linear-gradient(145deg,#fff,#fff7f7); --ab-line:rgba(190,25,34,.2); }
  .ab * { box-sizing:border-box; }
  .ab-hero { position:relative; isolation:isolate; overflow:hidden; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); align-items:center; gap:24px;
             min-height:280px; padding:24px 40px; border:1px solid rgba(255,50,59,.55); border-radius:22px; color:#fff;
             background:radial-gradient(55% 135% at 78% 55%,rgba(255,20,30,.25),transparent 72%),linear-gradient(115deg,#100708,#1a0608 55%,#090607);
             box-shadow:0 16px 45px rgba(0,0,0,.24),0 0 28px -16px rgba(255,20,30,.8); }
  .ab-hero::before { content:""; position:absolute; z-index:-1; inset:0; opacity:.42; pointer-events:none;
                     background:repeating-linear-gradient(135deg,transparent 0 140px,rgba(255,20,30,.12) 141px 165px,transparent 166px 245px); }
  .ab-copy { position:relative; z-index:1; max-width:580px; }
  .ab-pill { display:inline-flex; align-items:center; gap:6px; margin:0 0 8px; padding:5px 12px; border:1px solid rgba(255,40,50,.5); border-radius:999px; background:rgba(176,13,22,.3); color:#fff; font-weight:700; font-size:.82rem; }
  .ab-pill i { color:#ff2631; }
  .ab-hero h1 { margin:0; font-size:clamp(2.7rem,5.5vw,4.7rem); line-height:.98; letter-spacing:-.055em; font-weight:850; color:#fff; }
  .ab-hero h1 span { display:block; color:#ff151f; font-size:1.12em; }
  .ab-hero p.ab-lead { max-width:510px; margin:10px 0 18px; color:#ded6d7; font-size:1rem; line-height:1.38; }
  .ab-art { width:100%; max-width:580px; justify-self:end; overflow:visible; filter:drop-shadow(0 12px 22px rgba(0,0,0,.45)); }
  .ab-hero-btn { display:inline-flex; align-items:center; gap:10px; padding:11px 22px; border:1px solid #ff3039; border-radius:999px; color:#fff; font-weight:800; text-decoration:none; background:linear-gradient(135deg,#ff1722,#ce0611); box-shadow:0 8px 22px rgba(255,15,28,.25); }
  .ab-hero-btn:hover { color:#fff; transform:translateY(-1px); box-shadow:0 10px 28px rgba(255,15,28,.38); }
  @media (prefers-reduced-motion:no-preference) { .ab-hero-btn { transition:transform .18s ease,box-shadow .18s ease; } }

  .ab h2 { display:flex; align-items:center; gap:10px; margin:14px 0 8px; font-size:1.25rem; font-weight:800; color:var(--ab-text); }
  .ab h2::before { content:""; width:5px; height:23px; border-radius:4px; background:linear-gradient(180deg,#ff2832,#c6000b); box-shadow:0 0 12px rgba(255,30,40,.5); }
  .ab-grid { display:grid; gap:14px; grid-template-columns:repeat(3,minmax(0,1fr)); margin-top:16px; }
  .ab-card { display:flex; min-width:0; min-height:74px; gap:14px; align-items:center; padding:11px 16px; border:1px solid var(--ab-line); border-radius:15px; color:var(--ab-text); background:var(--ab-card); text-decoration:none; box-shadow:0 7px 20px rgba(0,0,0,.12); }
  .ab-card:hover { color:var(--ab-text); border-color:rgba(255,40,50,.55); background:linear-gradient(145deg,rgba(255,30,40,.1),rgba(255,30,40,.025)),var(--ab-card); transform:translateY(-2px); }
  .ab-icon { display:grid; flex:none; width:74px; height:48px; place-items:center; border-radius:12px; color:#ff1420; background:radial-gradient(circle,rgba(255,20,32,.13),transparent 70%); }
  .ab-icon i { font-size:2.25rem; line-height:1; filter:drop-shadow(0 0 9px rgba(255,20,32,.3)); }
  .ab-icon svg { display:block; width:42px; height:38px; filter:drop-shadow(0 0 9px rgba(255,20,32,.3)); }
  .ab-card h3 { margin:0 0 3px; font-size:1rem; font-weight:750; color:var(--ab-text); }
  .ab-card p { margin:0; color:var(--ab-muted); font-size:.8rem; line-height:1.35; }
  .ab-card-arrow { flex:none; margin-left:auto; color:#ff1925; font-size:1.25rem; }

  .ab-steps { list-style:none; margin:0; padding:0; display:grid; gap:30px; grid-template-columns:repeat(4,minmax(0,1fr)); counter-reset:s; }
  .ab-steps li { position:relative; counter-increment:s; display:flex; min-width:0; gap:12px; align-items:center; padding:12px 14px; border:1px solid var(--ab-line); border-radius:14px; background:var(--ab-card); color:var(--ab-muted); font-size:.8rem; line-height:1.35; }
  .ab-steps li::before { content:counter(s); flex:none; width:34px; height:34px; border-radius:50%; display:grid; place-items:center; font-size:1rem; font-weight:800; color:#fff; background:linear-gradient(135deg,#ff303a,#c6000b); box-shadow:0 0 14px rgba(255,20,30,.38); }
  .ab-steps li:not(:last-child)::after { content:"\203A"; position:absolute; right:-21px; top:50%; transform:translateY(-52%); color:#ff1420; font-size:2rem; line-height:1; }
  .ab-steps b { display:block; margin-bottom:2px; color:var(--ab-text); font-size:.9rem; }

  .ab-cta { position:relative; isolation:isolate; overflow:hidden; display:flex; align-items:center; gap:20px; margin-top:20px; min-height:92px; padding:14px 28px; border:1px solid rgba(255,48,58,.65); border-radius:20px; color:#fff;
            background:radial-gradient(ellipse at 12% 50%,rgba(255,20,30,.4),transparent 30%),linear-gradient(105deg,#300609,#170607 48%,#41070b); box-shadow:0 12px 32px rgba(0,0,0,.22),0 0 25px -16px rgba(255,20,30,.75); }
  .ab-cta::before { content:""; position:absolute; z-index:-1; inset:-45%; transform:rotate(-8deg); background:repeating-linear-gradient(155deg,transparent 0 180px,rgba(255,20,30,.25) 181px 215px,transparent 216px 310px); }
  .ab-rocket { flex:none; display:grid; place-items:center; width:68px; height:68px; border:2px solid #ff3340; border-radius:50%; background:#100708; color:#fff; font-size:2rem; box-shadow:0 0 0 5px rgba(255,30,40,.12),0 0 24px rgba(255,20,30,.55); }
  .ab-cta h3 { margin:0; font-size:1.55rem; font-weight:800; }
  .ab-cta p { margin:2px 0 0; color:#e5d7d8; font-size:.95rem; }
  .ab-btn { display:inline-flex; align-items:center; gap:8px; margin-left:auto; padding:13px 26px; border:1px solid #ff2632; border-radius:999px; font-weight:800; font-size:1rem; text-decoration:none; color:#fff; white-space:nowrap;
            background:linear-gradient(90deg,#ff1722,#ce0611); box-shadow:0 7px 20px rgba(255,20,30,.3); }
  .ab-btn:hover { color:#fff; transform:translateY(-1px); box-shadow:0 10px 26px rgba(255,20,30,.42); }
  @media (prefers-reduced-motion:no-preference) { .ab-card,.ab-btn { transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease; } }

  @media (max-width:1100px) { .ab-steps { grid-template-columns:repeat(2,minmax(0,1fr)); } .ab-steps li::after { display:none; } .ab-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:860px) { .ab { padding:14px 18px 32px; } .ab-hero { grid-template-columns:minmax(0,1fr) .8fr; padding:24px; } .ab-art { max-width:380px; } }
  @media (max-width:680px) { .ab-hero { grid-template-columns:1fr; min-height:auto; padding:24px 20px; } .ab-art { width:min(100%,400px); justify-self:center; margin-top:4px; } .ab-hero h1 { font-size:clamp(2.5rem,12vw,4rem); } }
  @media (max-width:540px) { .ab { padding:12px 12px 28px; } .ab-grid,.ab-steps { grid-template-columns:1fr; } .ab-steps { gap:10px; } .ab-cta { flex-wrap:wrap; justify-content:center; text-align:center; padding:18px; } .ab-cta>div { flex:1 1 190px; } .ab-btn { margin:4px 0 0; } }
</style>

<main class="ab">
  <section class="ab-hero" aria-labelledby="about-title">
    <div class="ab-copy">
      <p class="ab-pill"><i class="bi bi-play-fill" aria-hidden="true"></i> <?php echo $memberName !== '' ? 'Welcome, ' . $about($memberName) : 'About ' . $about($brand); ?></p>
      <h1 id="about-title">Welcome to<span><?php echo $about($brand); ?></span></h1>
      <p class="ab-lead">Watch videos, discover amazing creators, enjoy Shorts, follow your favourites, upload your own content and be part of our growing community.</p>
      <a class="ab-hero-btn" href="index.php">Explore Now <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
    </div>
    <svg class="ab-art" viewBox="0 0 560 280" aria-hidden="true">
      <defs>
        <linearGradient id="abScreen" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#302326"/><stop offset="1" stop-color="#100c0d"/></linearGradient>
        <linearGradient id="abRed" x1="0" y1="0" x2="1" y2="1"><stop stop-color="#ff626a"/><stop offset=".55" stop-color="#ff101e"/><stop offset="1" stop-color="#a9000a"/></linearGradient>
        <filter id="abGlow" x="-60%" y="-60%" width="220%" height="220%"><feGaussianBlur stdDeviation="5" result="blur"/><feMerge><feMergeNode in="blur"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
      </defs>
      <ellipse cx="330" cy="215" rx="190" ry="31" fill="none" stroke="#ff1723" stroke-width="7" opacity=".8" filter="url(#abGlow)"/>
      <ellipse cx="330" cy="215" rx="155" ry="22" fill="none" stroke="#ff434d" stroke-width="2" opacity=".8"/>
      <g transform="translate(185 26) rotate(-5 150 100)">
        <rect x="12" y="10" width="300" height="186" rx="17" fill="#080607" stroke="#fb202b" stroke-width="4"/>
        <rect x="22" y="20" width="280" height="163" rx="11" fill="url(#abScreen)" stroke="#5d3337" stroke-width="1.5"/>
        <path d="M119 69 119 134 178 101Z" fill="url(#abRed)" filter="url(#abGlow)"/>
        <rect x="35" y="155" width="248" height="3" rx="2" fill="#6d5f60"/>
        <rect x="35" y="155" width="98" height="3" rx="2" fill="#ff1d29"/>
        <circle cx="134" cy="156.5" r="5" fill="#fff"/>
        <path d="M39 170v9l9-4.5z" fill="#fff"/><path d="M58 171v8m5-7q5 3 0 6" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/>
        <text x="83" y="179" fill="#ddd" font-size="9" font-family="Arial">0:00 / 12:34</text>
        <rect x="230" y="169" width="19" height="11" rx="2" fill="none" stroke="#fff" stroke-width="1.5"/><text x="232" y="177" fill="#fff" font-size="6" font-family="Arial">CC</text>
        <circle cx="268" cy="174" r="5" fill="none" stroke="#fff" stroke-width="1.5"/>
        <path d="M115 198h100l18 13H97z" fill="#201719" stroke="#693238" stroke-width="1.5"/>
      </g>
      <g transform="translate(64 104) rotate(-8)">
        <rect x="0" y="27" width="74" height="52" rx="8" fill="#211719" stroke="#5d3135" stroke-width="2"/>
        <path d="M0 27h74v15H0z" fill="#302326"/>
        <path d="M15 27h11L17 42H6zm28 0h11L45 42H34zm28 0h11L73 42H62z" fill="#eee"/>
        <path d="M24 49v24l23-12z" fill="#ff1b27"/>
        <rect x="0" y="10" width="76" height="17" rx="5" fill="#211719" stroke="#5d3135" stroke-width="2"/>
        <path d="M11 10h13l-9 17H2zm33 0h13l-9 17H35zm33 0h5v17H68z" fill="#f5eeee"/>
      </g>
      <g transform="translate(452 63)" filter="url(#abGlow)">
        <path d="M17 8C8 8 6 16 6 25c0 12-5 17-5 20h34c0-3-5-8-5-20C30 16 26 8 17 8z" fill="url(#abRed)"/>
        <path d="M12 49a5 5 0 0 0 10 0" fill="#ff4650"/>
        <circle cx="29" cy="8" r="8" fill="#ff2631"/><path d="M29 4v5m0 3h.1" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
      </g>
      <g transform="translate(114 37) rotate(-12)"><rect width="63" height="42" rx="11" fill="#ed101b" filter="url(#abGlow)"/><path d="M25 11v20l17-10z" fill="#fff"/></g>
    </svg>
  </section>

  <div class="ab-grid">
    <a class="ab-card" href="index.php"><span class="ab-icon"><svg viewBox="0 0 48 36" aria-hidden="true"><rect x="3" y="2" width="42" height="32" rx="6" fill="currentColor"/><path d="M20 10v16l13-8z" fill="#fff"/></svg></span><div><h3>Watch videos</h3><p>Discover trending and amazing content from creators around the world.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
    <a class="ab-card" href="shorts.php"><span class="ab-icon"><svg viewBox="0 0 48 36" aria-hidden="true"><path d="M3 5.5C3 3.6 5.1 2.4 6.8 3.3l18 10.1a3 3 0 0 1 0 5.2l-18 10.1A3 3 0 0 1 3 26.1z" fill="currentColor"/><path d="M22 5.5c0-1.9 2.1-3.1 3.8-2.2l18 10.1a3 3 0 0 1 0 5.2l-18 10.1a2.5 2.5 0 0 1-3.8-2.2z" fill="currentColor"/></svg></span><div><h3>Shorts</h3><p>Enjoy quick, engaging Shorts in a never-ending feed.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
    <a class="ab-card" href="upload.php"><span class="ab-icon"><svg viewBox="0 0 48 36" aria-hidden="true"><path d="M13 31a10 10 0 0 1-1-20 13 13 0 0 1 24-2 10 10 0 0 1 0 22z" fill="currentColor"/><path d="M24 25V12m-6 6 6-6 6 6" fill="none" stroke="#fff" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span><div><h3>Upload your own</h3><p>Share your creativity with the VibeStream community.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
    <a class="ab-card" href="subscriptions.php"><span class="ab-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span><div><h3>Subscribe</h3><p>Follow your favourite creators and never miss an upload.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
    <a class="ab-card" href="history.php"><span class="ab-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><h3>Your history</h3><p>Easily find the videos you've watched before.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
    <a class="ab-card" href="premium.php"><span class="ab-icon"><svg viewBox="0 0 64 54" aria-hidden="true"><path d="M6 44 2 14l16 14L32 6l14 22 16-14-4 30z" fill="currentColor"/><rect x="8" y="46" width="48" height="7" rx="2.5" fill="currentColor"/></svg></span><div><h3>Premium</h3><p>Get more features with VibeStream Premium.</p></div><i class="bi bi-chevron-right ab-card-arrow" aria-hidden="true"></i></a>
  </div>

  <h2>Getting Started is Easy!</h2>
  <ol class="ab-steps">
    <li><span><b>Look around</b>Explore videos, Shorts and more.</span></li>
    <li><span><b>Make it yours</b>Subscribe, like and customize your feed.</span></li>
    <li><span><b>Follow creators</b>Stay connected to your favourite creators.</span></li>
    <li><span><b>Join the community</b>Be part of something bigger!</span></li>
  </ol>

  <section class="ab-cta" aria-label="Start exploring">
    <span class="ab-rocket"><i class="bi bi-rocket-takeoff-fill" aria-hidden="true"></i></span>
    <div><h3>Ready to dive in?</h3><p>Explore <?php echo $about($brand); ?> today and be part of our amazing community!</p></div>
    <a class="ab-btn" href="index.php">Explore Now <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
  </section>
</main>
</body>
</html>