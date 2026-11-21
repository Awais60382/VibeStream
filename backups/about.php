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
  .ab { --ab-text:#eaf0ff; --ab-muted:#9aa6d6; --ab-card:rgba(14,18,44,.88); --ab-line:rgba(138,92,255,.45);
        --ab-a:#8a5cff; --ab-b:#ff2e93; --ab-c:#00e5ff;
        max-width:1040px; margin:0 auto; padding:32px 16px 56px; color:var(--ab-text); }
  html[data-bs-theme="light"] .ab, html[data-theme="light"] .ab { --ab-text:#0b1030; --ab-muted:#55608f; --ab-card:#ffffff; --ab-line:rgba(124,77,255,.35); }
  .ab * { box-sizing:border-box; }
  .ab-hero { text-align:center; padding:40px 20px; border:1px solid var(--ab-line); border-radius:22px; background:var(--ab-card);
             box-shadow:0 0 36px -12px rgba(138,92,255,.6); }
  .ab-hero img { width:64px; height:64px; object-fit:contain; border-radius:16px; margin-bottom:12px; }
  .ab-eyebrow { color:var(--ab-c); font-weight:700; letter-spacing:.12em; text-transform:uppercase; font-size:.78rem; margin:0 0 8px; }
  .ab-hero h1 { font-size:clamp(1.8rem,5vw,2.8rem); margin:0 0 12px; line-height:1.15; }
  .ab-hero h1 span { background:linear-gradient(90deg,var(--ab-a),var(--ab-b)); -webkit-background-clip:text; background-clip:text; color:transparent; }
  .ab-hero p { max-width:640px; margin:0 auto; color:var(--ab-muted); font-size:1.05rem; line-height:1.6; }
  .ab h2 { font-size:1.4rem; margin:36px 0 14px; }
  .ab-grid { display:grid; gap:14px; grid-template-columns:repeat(auto-fit,minmax(230px,1fr)); }
  .ab-card { padding:18px; border:1px solid var(--ab-line); border-radius:16px; background:var(--ab-card); }
  .ab-card i { font-size:1.5rem; color:var(--ab-c); }
  .ab-card h3 { font-size:1.05rem; margin:10px 0 6px; }
  .ab-card p { margin:0; color:var(--ab-muted); font-size:.93rem; line-height:1.5; }
  .ab-steps { counter-reset:s; list-style:none; padding:0; margin:0; display:grid; gap:12px; }
  .ab-steps li { counter-increment:s; display:flex; gap:14px; align-items:flex-start; padding:14px 16px; border:1px solid var(--ab-line); border-radius:14px; background:var(--ab-card); color:var(--ab-muted); line-height:1.5; }
  .ab-steps li::before { content:counter(s); flex:none; width:30px; height:30px; border-radius:50%; display:grid; place-items:center; font-weight:700; color:#fff; background:linear-gradient(135deg,var(--ab-a),var(--ab-b)); }
  .ab-steps b { color:var(--ab-text); }
  .ab-cta { text-align:center; margin-top:36px; }
  .ab-btn { display:inline-block; padding:13px 28px; border-radius:999px; font-weight:700; text-decoration:none; color:#fff;
            background:linear-gradient(90deg,var(--ab-a),var(--ab-b)); box-shadow:0 0 22px rgba(255,46,147,.45); }
  .ab-btn:hover { color:#fff; transform:translateY(-1px); }
  .ab-link { display:inline-block; margin-left:14px; color:var(--ab-c); text-decoration:none; font-weight:600; }
  @media (prefers-reduced-motion:no-preference) { .ab-btn { transition:transform .18s ease, box-shadow .18s ease; } }
</style>

<main class="ab">
  <section class="ab-hero" aria-labelledby="about-title">
    <?php if (!empty($siteLogoUrl)): ?><img src="<?php echo $about($siteLogoUrl); ?>" alt=""><?php endif; ?>
    <p class="ab-eyebrow"><?php echo $memberName !== '' ? 'Welcome, ' . $about($memberName) : 'About us'; ?></p>
    <h1 id="about-title">Welcome to <span><?php echo $about($brand); ?></span></h1>
    <p><?php echo $about($brand); ?> is a place to watch, share and enjoy videos. Watch long videos and quick Shorts, follow the creators you like, upload your own, and join the conversation, all in one community.</p>
  </section>

  <h2>What you can do here</h2>
  <div class="ab-grid">
    <article class="ab-card"><i class="bi bi-play-btn" aria-hidden="true"></i><h3>Watch videos</h3><p>Browse videos from the community on the home page and open any of them to watch, like and comment.</p></article>
    <article class="ab-card"><i class="bi bi-lightning-charge" aria-hidden="true"></i><h3>Shorts</h3><p>Swipe through quick vertical videos when you only have a minute.</p></article>
    <article class="ab-card"><i class="bi bi-upload" aria-hidden="true"></i><h3>Upload your own</h3><p>Share your videos and Shorts with everyone and build up your own channel.</p></article>
    <article class="ab-card"><i class="bi bi-collection-play" aria-hidden="true"></i><h3>Subscribe</h3><p>Follow creators so their new videos are easy to find in your subscriptions.</p></article>
    <article class="ab-card"><i class="bi bi-clock-history" aria-hidden="true"></i><h3>Your history</h3><p>Pick up where you left off with the videos you watched recently.</p></article>
    <article class="ab-card"><i class="bi bi-gem" aria-hidden="true"></i><h3>Premium</h3><p>Want more? Check the Premium page in the menu to see what it adds.</p></article>
  </div>

  <h2>Get started in four steps</h2>
  <ol class="ab-steps">
    <li><span><b>Look around.</b> Open the home page and watch something that catches your eye.</span></li>
    <li><span><b>Make it yours.</b> Go to your profile and add a profile picture.</span></li>
    <li><span><b>Follow creators.</b> Subscribe to the channels you like.</span></li>
    <li><span><b>Share something.</b> Use Upload Video to post your first video or Short.</span></li>
  </ol>

  <div class="ab-cta">
    <a class="ab-btn" href="index.php">Start exploring</a>
    <a class="ab-link" href="upload.php">Upload a video</a>
  </div>
</main>
</body>
</html>
