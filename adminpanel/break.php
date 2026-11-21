<?php
// break.php  (place in /VibeStream/adminpanel/)  -- standalone page, no admin login needed.
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$card = $_SESSION['vs_break_card'] ?? null;
$until = is_array($card) ? (int)($card['until'] ?? 0) : 0;
$name = is_array($card) ? (string)($card['name'] ?? '') : '';
$work = is_array($card) ? (int)($card['work'] ?? 0) : 0;
$break = is_array($card) ? (int)($card['break'] ?? 0) : 0;
$remaining = max(0, $until - time());
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Break time · VibeStream</title>
<style>
  :root{color-scheme:dark}
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;color:#f4f5ff;font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;background:
    radial-gradient(ellipse 60% 40% at 0% 0%,rgba(120,50,255,.45),transparent 60%),
    radial-gradient(ellipse 50% 40% at 100% 100%,rgba(60,60,255,.3),transparent 60%),#070914}
  .card{width:min(100%,460px);padding:34px 30px;text-align:center;border:1px solid rgba(139,111,255,.25);border-radius:24px;background:linear-gradient(160deg,rgba(18,22,46,.95),rgba(10,13,30,.96));box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .icon{font-size:44px;margin-bottom:6px}
  h1{margin:0 0 8px;font-size:28px;font-weight:800}
  p{margin:0 0 14px;color:#b9bde0;line-height:1.55}
  .time{margin:18px 0;font-size:52px;font-weight:800;letter-spacing:.02em;font-variant-numeric:tabular-nums;color:#c9bbff}
  a.btn{display:inline-block;margin-top:6px;padding:13px 26px;border-radius:12px;background:linear-gradient(90deg,#7b2ff7,#4a4bff);color:#fff;font-weight:700;text-decoration:none}
  [hidden]{display:none!important}
</style>
</head>
<body>
<main class="card">
  <div class="icon">&#9749;</div>
  <?php if ($until > 0): ?>
    <div id="onbreak"<?= $remaining <= 0 ? ' hidden' : '' ?>>
      <h1>Time for a break<?= $name !== '' ? ', ' . $h($name) : '' ?></h1>
      <p>You have been working for <?= (int)$work ?> minutes. You can sign in again after a <?= (int)$break ?>-minute break.</p>
      <div class="time" id="clock">--:--</div>
      <p>Back at <strong><?= $h(date('H:i', $until)) ?></strong>. Step away, stretch, and drink some water.</p>
    </div>
    <div id="done"<?= $remaining > 0 ? ' hidden' : '' ?>>
      <h1>Break is over</h1>
      <p>Welcome back. Please sign in again to continue.</p>
      <a class="btn" href="../login.php">Sign in</a>
    </div>
  <?php else: ?>
    <h1>You have been signed out</h1>
    <p>Sign in again to continue. If a break is due, this page will show how long is left.</p>
    <a class="btn" href="../login.php">Sign in</a>
  <?php endif; ?>
</main>
<?php if ($until > 0): ?>
<script>
(function () {
  var end = Date.now() + <?= (int)$remaining ?> * 1000;
  var clock = document.getElementById('clock'), on = document.getElementById('onbreak'), done = document.getElementById('done');
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function tick() {
    var left = Math.max(0, Math.round((end - Date.now()) / 1000));
    if (clock) clock.textContent = pad(Math.floor(left / 60)) + ':' + pad(left % 60);
    if (left <= 0) { if (on) on.hidden = true; if (done) done.hidden = false; return; }
    setTimeout(tick, 500);
  }
  tick();
})();
</script>
<?php endif; ?>
</body>
</html>
