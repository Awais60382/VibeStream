<?php
/**
 * VibeStream - Premium checkout page: premium-checkout.php?plan=monthly
 * Put this file in the VibeStream main folder, next to premium.php and header.php.
 * Bank / wallet transfer works now (the buyer pays, enters the transaction ID, an admin approves).
 * The Credit card tab is a placeholder until a payment provider (Stripe, PayPal...) is connected.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';
require_once __DIR__ . '/stripe-lib.php';

$me = trim((string)($_SESSION['username'] ?? ''));
if ($me === '') {
    header('Location: login.php');
    exit;
}
$planKey = (string)($_GET['plan'] ?? '');
if (!isset(VS_PREMIUM_PLANS[$planKey])) {
    header('Location: premium.php');
    exit;
}
$plan = VS_PREMIUM_PLANS[$planKey];
$tierName = $plan['tier'] === 'plus' ? 'Premium Plus' : 'Premium';
$planName = preg_replace('/^Plus /', '', (string)$plan['label']);
$price = (float)$plan['price'];
$payLines = vs_premium_pay_info();
$isAdmin = vs_premium_is_admin($me);
$cardOn = vs_stripe_ready() && !$isAdmin;
if (empty($_SESSION['premium_csrf'])) {
    $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
}
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn(float $v): string => '$' . number_format($v, 2);

include __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar-navigation.php';
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
@media (max-width:760px){.pm-layout>.app-sidebar{display:none}}
.co-page{flex:1;min-width:0;max-width:980px;margin:0 auto;padding:28px clamp(12px,3vw,28px) 80px}
.co-page h1{font-size:26px;margin:0 0 18px}
.co-card{display:grid;grid-template-columns:1fr 1.1fr;border-radius:22px;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,.35);font-family:system-ui,Arial,sans-serif}
.co-left{background:#eef1fb;color:#1b1650;padding:34px}
.co-right{background:#fff;color:#1b1650;padding:34px}
.co-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:22px;color:#5b4cf5;margin-bottom:26px}
.co-brand img{width:40px;height:40px;border-radius:10px;object-fit:cover}
.co-note{font-size:13px;color:#5b4cf5;margin-bottom:22px}
.co-note a{color:inherit}
.co-line{display:flex;justify-content:space-between;gap:12px;padding:10px 0;font-weight:700}
.co-line small{display:block;font-weight:400;color:#6b6890;font-size:12px;margin-top:3px}
.co-sep{border:0;border-top:1px solid #cfd3ea;margin:14px 0}
.co-total{font-size:24px;font-weight:800}
.co-tabs{display:flex;gap:12px;margin-bottom:22px}
.co-tab{display:flex;align-items:center;justify-content:center;gap:8px;flex:1;border:1px solid #dcdff3;background:#fff;border-radius:12px;padding:14px 10px;font:inherit;font-size:14px;color:#5b4cf5;cursor:pointer}
.co-tab[aria-selected="true"]{background:#eef0ff;border-color:#8c82f7;font-weight:700}
.co-panel[hidden]{display:none}
.co-label{display:block;font-size:13px;color:#55527a;margin:14px 0 6px}
.co-input{width:100%;box-sizing:border-box;background:#eef1fb;border:0;border-bottom:2px solid #c9ccf3;border-radius:10px;padding:14px;font:inherit;color:#4b3fe0}
.co-input:disabled{background:#eef1fb;color:#8f8cc0;opacity:1;-webkit-text-fill-color:#8f8cc0;cursor:not-allowed}
.co-input::placeholder{color:#a6a4cf;opacity:1}
.co-cardnum{position:relative}.co-cardnum .co-input{padding-left:62px}
.co-mc{position:absolute;left:14px;top:50%;transform:translateY(-50%);display:flex}.co-mc i{width:22px;height:22px;border-radius:50%;background:#e8212b}.co-mc i+i{background:#f7a21b;margin-left:-9px;opacity:.95}
.co-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.co-pay{list-style:none;margin:0;padding:0}
.co-pay li{background:#eef1fb;border-radius:10px;padding:12px 14px;margin-bottom:8px;font-size:14px;word-break:break-word}
.co-btn{width:100%;margin-top:22px;border:0;border-radius:10px;background:#5b3df5;color:#fff;padding:15px;font:inherit;font-size:17px;font-weight:700;cursor:pointer}
.co-btn:disabled{opacity:.55;cursor:not-allowed}
.co-msg{margin-top:14px;padding:12px 14px;border-radius:10px;font-size:14px;background:#fff6e0;color:#5a3d00}
.co-msg.ok{background:#e3f6ea;color:#14532d}
.co-msg.err{background:#fde8e8;color:#7a1c1c}
@media(max-width:1180px){.co-card{grid-template-columns:1fr}}
</style>
<div class="pm-layout">
<?php vs_render_sidebar('premium'); ?>
<main class="co-page">
  <h1>Payment</h1>
  <div class="co-card">
    <section class="co-left" aria-label="Order summary">
      <div class="co-brand"><img src="branding-icon.php?size=96" alt="" onerror="this.style.display='none'"><span>VibeStream</span></div>
      <div class="co-note">Your receipt appears under <a href="premium.php">Your payments</a> once the payment is approved.</div>
      <div class="co-line"><span><?php echo $h($tierName . ' ' . $planName); ?><small><?php echo (int)$plan['days']; ?> days of access</small></span><span><?php echo $h($money($price)); ?></span></div>
      <div class="co-line"><span>Discounts &amp; offers</span><span>$0.00</span></div>
      <hr class="co-sep">
      <div class="co-line"><span>Tax</span><span>$0.00</span></div>
      <div class="co-line"><span>Total</span><span class="co-total"><?php echo $h($money($price)); ?></span></div>
      <p class="co-note" style="margin-top:20px"><a href="premium.php">&larr; Change plan</a></p>
    </section>

    <section class="co-right" aria-label="Payment">
      <div class="co-tabs" role="tablist">
        <button class="co-tab" type="button" role="tab" id="tabCard" aria-selected="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2.5"/><path d="M2 10h20M6 15h4"/></svg><span>Credit card</span></button>
        <button class="co-tab" type="button" role="tab" id="tabBank" aria-selected="false"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 9.5 12 4l9 5.5"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/></svg><span>Bank / wallet transfer</span></button>
      </div>

      <div class="co-panel" id="panelCard">
        <?php if ($cardOn): ?>
          <div class="co-msg ok">Pay by card on Stripe's secure page. Your card details go straight to Stripe and are never stored on VibeStream.</div>
          <form method="post" action="premium-stripe.php">
            <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['premium_csrf']); ?>">
            <input type="hidden" name="plan" value="<?php echo $h($planKey); ?>">
            <button class="co-btn" type="submit">Pay now <?php echo $h($money($price)); ?></button>
          </form>
        <?php else: ?>
          <div class="co-msg"><?php echo $isAdmin ? 'You are signed in as an admin, and admins always have Premium. Sign in with a normal account to test a purchase.' : 'Card payments are not turned on yet. The site owner needs to add a Stripe key in stripe-config.php. Use the transfer tab for now.'; ?></div>
          <label class="co-label">Cardholder's name</label><input class="co-input" disabled placeholder="Name on card">
          <label class="co-label">Card number</label><div class="co-cardnum"><span class="co-mc"><i></i><i></i></span><input class="co-input" disabled placeholder="0000 0000 0000 0000"></div>
          <div class="co-row">
            <div><label class="co-label">Expiry</label><input class="co-input" disabled placeholder="MM / YY"></div>
            <div><label class="co-label">CVC</label><input class="co-input" disabled placeholder="CVC"></div>
          </div>
          <button class="co-btn" type="button" disabled>Pay now</button>
        <?php endif; ?>
      </div>

      <div class="co-panel" id="panelBank" hidden>
        <?php if ($isAdmin): ?>
          <div class="co-msg">You are signed in as an admin, and admins always have Premium. Sign in with a normal account to test a purchase.</div>
        <?php elseif (!$payLines): ?>
          <div class="co-msg">Payments are not open yet. Please check back soon.</div>
        <?php endif; ?>
        <?php $coOpen = !$isAdmin && $payLines; ?>
        <label class="co-label">1. Send <?php echo $h($money($price)); ?> using one of these:</label>
        <ul class="co-pay">
          <?php foreach ($payLines as $line): ?><li><?php echo $h($line); ?></li><?php endforeach; ?>
          <?php if (!$payLines): ?><li style="opacity:.6">Payment details will appear here.</li><?php endif; ?>
        </ul>
        <label class="co-label" for="coRef">2. Enter the transaction ID from your payment</label>
        <input class="co-input" id="coRef" maxlength="100" autocomplete="off" placeholder="Transaction ID"<?php echo $coOpen ? '' : ' disabled'; ?>>
        <button class="co-btn" type="button" id="coPay"<?php echo $coOpen ? '' : ' disabled'; ?>>Pay now: I have sent <?php echo $h($money($price)); ?></button>
        <div class="co-msg" id="coMsg" hidden></div>
      </div>
    </section>
  </div>
</main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var $ = function (i) { return document.getElementById(i); };
  var PLAN = <?php echo json_encode($planKey); ?>;
  function show(card) {
    $('tabCard').setAttribute('aria-selected', card);
    $('tabBank').setAttribute('aria-selected', !card);
    $('panelCard').hidden = !card;
    $('panelBank').hidden = card;
  }
  $('tabCard').onclick = function () { show(true); };
  $('tabBank').onclick = function () { show(false); };
  var btn = $('coPay');
  if (!btn) return;
  function say(text, cls) { var m = $('coMsg'); m.hidden = false; m.className = 'co-msg ' + (cls || ''); m.textContent = text; }
  btn.onclick = function () {
    var ref = $('coRef').value.trim();
    if (ref.length < 4) { say('Enter the transaction ID from your payment (at least 4 characters).', 'err'); return; }
    if (!window.VSPremium) { say('The page is still loading. Try again in a moment.', 'err'); return; }
    btn.disabled = true;
    VSPremium.request(PLAN, ref).then(function () {
      say('Payment details sent. Premium turns on once we confirm your payment.', 'ok');
      $('coRef').disabled = true;
      setTimeout(function () { location.href = 'premium.php'; }, 2500);
    }).catch(function (e) {
      say(e.message || 'Something went wrong.', 'err'); btn.disabled = false;
    });
  };
});
</script>
</body>
</html>