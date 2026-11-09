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
  .ab { --ab-text:#eaf0ff; --ab-muted:#a9b4e0; --ab-card:rgba(16,20,52,.9); --ab-line:rgba(138,92,255,.4);
        --ab-a:#8a5cff; --ab-b:#ff2e93; --ab-c:#00e5ff;
        max-width:1240px; margin:0 auto; padding:24px 16px 56px; color:var(--ab-text); }
  html[data-bs-theme="light"] .ab, html[data-theme="light"] .ab { --ab-text:#0b1030; --ab-muted:#55608f; --ab-card:#ffffff; --ab-line:rgba(124,77,255,.35); }
  .ab * { box-sizing:border-box; }

  /* hero */
  .ab-hero { position:relative; overflow:hidden; display:grid; grid-template-columns:minmax(0,1.5fr) minmax(0,1fr); align-items:center; gap:10px;
             min-height:300px; padding:30px 34px; border:1px solid rgba(138,92,255,.6); border-radius:22px; color:#fff;
             background:radial-gradient(60% 130% at 88% 50%, rgba(138,92,255,.55), transparent 70%), linear-gradient(120deg,#190a48,#2a0f78 55%,#140a3c);
             box-shadow:0 0 40px -14px rgba(138,92,255,.7); }
  .ab-top { display:flex; align-items:center; gap:22px; }
  .ab-logo { flex:none; width:104px; height:104px; object-fit:contain; filter:drop-shadow(0 0 18px rgba(138,92,255,.7)); }
  .ab-pill { display:inline-block; margin:0 0 6px; padding:3px 14px; border:1px solid var(--ab-c); border-radius:999px; background:rgba(0,229,255,.1); color:var(--ab-c); font-weight:700; font-size:.82rem; }
  .ab-hero h1 { margin:0; line-height:1; font-weight:800; }
  .ab-hero h1 small { display:block; font-size:clamp(2rem,4.4vw,3.4rem); font-weight:800; color:#fff; }
  .ab-hero h1 span { display:block; font-size:clamp(2.6rem,6vw,4.6rem); background:linear-gradient(90deg,#6a4dff,#c13cff 60%,#ff2e93); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .ab-hero p.ab-lead { max-width:640px; margin:18px 0 0; color:#dfe5ff; font-size:1.05rem; line-height:1.6; }
  .ab-art { width:100%; max-width:430px; justify-self:center; filter:drop-shadow(0 0 30px rgba(138,92,255,.5)); }

  /* section titles */
  .ab h2 { display:flex; align-items:center; gap:14px; margin:34px 0 16px; font-size:1.6rem; font-weight:800; }
  .ab h2::before { content:""; width:7px; height:30px; border-radius:4px; background:linear-gradient(180deg,var(--ab-a),#5b34e8); box-shadow:0 0 12px rgba(138,92,255,.7); }

  /* feature cards */
  .ab-grid { display:grid; gap:16px; grid-template-columns:repeat(3,minmax(0,1fr)); }
  .ab-card { display:flex; gap:18px; align-items:center; padding:20px 22px; border:1px solid var(--ab-line); border-radius:16px; background:var(--ab-card); }
  .ab-card > i { flex:none; width:56px; text-align:center; font-size:2.8rem; line-height:1; color:#8a5cff; background:linear-gradient(135deg,#a77bff,#5b34e8); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; filter:drop-shadow(0 0 10px rgba(138,92,255,.55)); }
  .ab-card.cy > i { background:linear-gradient(135deg,#22d3ee,#2563eb); -webkit-background-clip:text; background-clip:text; }
  .ab-card h3 { margin:0 0 5px; font-size:1.2rem; font-weight:700; }
  .ab-card p { margin:0; color:var(--ab-muted); font-size:.93rem; line-height:1.5; }

  /* steps */
  .ab-steps { list-style:none; margin:0; padding:0; display:grid; gap:34px; grid-template-columns:repeat(4,minmax(0,1fr)); counter-reset:s; }
  .ab-steps li { position:relative; counter-increment:s; display:flex; gap:14px; align-items:center; padding:16px 18px; border:1px solid var(--ab-line); border-radius:14px; background:var(--ab-card); color:var(--ab-muted); font-size:.92rem; line-height:1.45; }
  .ab-steps li::before { content:counter(s); flex:none; width:44px; height:44px; border-radius:50%; display:grid; place-items:center; font-size:1.25rem; font-weight:800; color:#fff; background:linear-gradient(135deg,#7c4dff,#5b34e8); box-shadow:0 0 16px rgba(124,77,255,.7); }
  .ab-steps li:not(:last-child)::after { content:"\203A"; position:absolute; right:-24px; top:50%; transform:translateY(-52%); color:var(--ab-a); font-size:1.8rem; line-height:1; }
  .ab-steps b { display:block; margin-bottom:2px; color:var(--ab-text); font-size:1.08rem; }

  /* call to action band */
  .ab-cta { display:flex; align-items:center; gap:20px; margin-top:30px; padding:22px 28px; border:1px solid rgba(180,90,255,.6); border-radius:18px; color:#fff;
            background:linear-gradient(90deg,#1b0b52,#4a12a8 55%,#8a1fd6); box-shadow:0 0 30px -10px rgba(168,85,247,.7); }
  .ab-rocket { flex:none; display:grid; place-items:center; width:72px; height:72px; border-radius:50%; background:rgba(8,6,32,.45); color:#22d3ee; font-size:2.4rem; box-shadow:0 0 22px rgba(34,211,238,.45); }
  .ab-cta h3 { margin:0; font-size:1.7rem; font-weight:800; }
  .ab-cta p { margin:2px 0 0; color:#e6dcff; }
  .ab-btn { display:inline-flex; align-items:center; gap:8px; margin-left:auto; padding:14px 30px; border-radius:999px; font-weight:800; font-size:1.05rem; text-decoration:none; color:#fff; white-space:nowrap;
            background:linear-gradient(90deg,#8a2cff,#ff2e93); box-shadow:0 0 24px rgba(255,46,147,.5); }
  .ab-btn:hover { color:#fff; transform:translateY(-1px); }
  @media (prefers-reduced-motion:no-preference) { .ab-btn { transition:transform .18s ease, box-shadow .18s ease; } }

  @media (max-width:1100px) { .ab-steps { grid-template-columns:repeat(2,minmax(0,1fr)); } .ab-steps li::after { display:none; } .ab-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
  @media (max-width:860px) { .ab-hero { grid-template-columns:1fr; padding:26px 22px; } .ab-art { display:none; } .ab-top { flex-direction:column; align-items:flex-start; gap:12px; } }
  @media (max-width:640px) { .ab-grid, .ab-steps { grid-template-columns:1fr; } .ab-cta { flex-direction:column; text-align:center; } .ab-btn { margin:6px 0 0; } }
</style>

<main class="ab">
  <section class="ab-hero" aria-labelledby="about-title">
    <div>
      <div class="ab-top">
        <?php if (!empty($siteLogoUrl)): ?><img class="ab-logo" src="<?php echo $about($siteLogoUrl); ?>" alt=""><?php endif; ?>
        <div>
          <p class="ab-pill"><?php echo $memberName !== '' ? 'WELCOME, ' . $about($memberName) : 'ABOUT US'; ?></p>
          <h1 id="about-title"><small>Welcome to</small><span><?php echo $about($brand); ?></span></h1>
        </div>
      </div>
      <p class="ab-lead"><?php echo $about($brand); ?> is a place to watch, share and enjoy videos. Watch long videos and quick Shorts, follow the creators you like, upload your own, and join the conversation, all in one community.</p>
    </div>
    <svg class="ab-art" viewBox="0 0 380 260" aria-hidden="true">
      <defs><linearGradient id="abg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a77bff"/><stop offset="1" stop-color="#4b1fd6"/></linearGradient></defs>
      <polygon points="70,45 70,190 200,117" fill="url(#abg)" stroke="url(#abg)" stroke-width="30" stroke-linejoin="round"/>
      <g transform="translate(200 90) rotate(-10)">
        <rect x="0" y="40" width="150" height="112" rx="12" fill="#14121f" stroke="#3a3470"/>
        <rect x="-4" y="6" width="158" height="34" rx="8" fill="#14121f" stroke="#3a3470"/>
        <path d="M12 6h22l-14 34h-22zM52 6h22l-14 34h-22zM92 6h22l-14 34h-22zM132 6h22l-14 34h-22z" fill="#f4f1ff"/>
        <rect x="36" y="68" width="78" height="58" rx="15" fill="#e11d2e"/>
        <polygon points="66,80 66,114 94,97" fill="#fff"/>
      </g>
    </svg>
  </section>

  <h2>What you can do here</h2>
  <div class="ab-grid">
    <article class="ab-card"><i class="bi bi-play-fill" aria-hidden="true"></i><div><h3>Watch videos</h3><p>Browse videos from the community on the home page and open any of them to watch, like and comment.</p></div></article>
    <article class="ab-card"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i><div><h3>Shorts</h3><p>Swipe through quick vertical videos when you only have a minute.</p></div></article>
    <article class="ab-card"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><div><h3>Upload your own</h3><p>Share your videos and Shorts with everyone and build up your own channel.</p></div></article>
    <article class="ab-card"><i class="bi bi-people-fill" aria-hidden="true"></i><div><h3>Subscribe</h3><p>Follow creators so their new videos are easy to find in your subscriptions.</p></div></article>
    <article class="ab-card"><i class="bi bi-clock-history" aria-hidden="true"></i><div><h3>Your history</h3><p>Pick up where you left off with the videos you watched recently.</p></div></article>
    <article class="ab-card cy"><i class="bi bi-gem" aria-hidden="true"></i><div><h3>Premium</h3><p>Want more? Check the Premium page in the menu to see what it adds.</p></div></article>
  </div>

  <h2>Get started in four steps</h2>
  <ol class="ab-steps">
    <li><span><b>Look around.</b> Open the home page and watch something that catches your eye.</span></li>
    <li><span><b>Make it yours.</b> Go to your profile and add a profile picture.</span></li>
    <li><span><b>Follow creators.</b> Subscribe to the channels you like.</span></li>
    <li><span><b>Join the community.</b> Like, comment and be part of <?php echo $about($brand); ?>!</span></li>
  </ol>

  <section class="ab-cta" aria-label="Start exploring">
    <span class="ab-rocket"><i class="bi bi-rocket-takeoff-fill" aria-hidden="true"></i></span>
    <div><h3>Ready to dive in?</h3><p>Start watching, create and connect today!</p></div>
    <a class="ab-btn" href="index.php"><i class="bi bi-play-fill" aria-hidden="true"></i> Explore Now</a>
  </section>
</main>
</body>
</html>