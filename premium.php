<?php
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
@media (prefers-reduced-motion:reduce){.pm-toast{transition:none}}
</style>

<div class="pm-layout">
<?php vs_render_sidebar('premium'); ?>
<main class="pm-page">
  <div class="pm-status">
    <span id="pmStatusText">Premium is active</span>
    <button type="button" id="pmCancel">Cancel Premium</button>
  </div>

  <h1>Watch without limits</h1>
  <p class="pm-lead">No ads, better quality, and downloads for offline watching. Pick a week, a month or a year.</p>

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
        <li>Up to 720p quality</li>
        <li class="pm-no">Ads between videos</li>
        <li class="pm-no">No offline downloads</li>
      </ul>
      <button class="pm-btn" type="button" disabled id="pmFree">Current plan</button>
    </section>
    <section class="pm-plan pm-pro">
      <h2>Premium</h2>
      <div class="pm-price"><span id="pmAmt">$4.99</span> <small id="pmPer">per month</small></div>
      <span class="pm-save" id="pmSave"></span>
      <ul>
        <li>No ads anywhere</li>
        <li>1080p and 4K quality</li>
        <li>Download videos to watch offline</li>
        <li>Play audio with the screen off</li>
        <li>Premium badge on your profile and comments</li>
        <li>Early access to new features</li>
      </ul>
      <button class="pm-btn" type="button" id="pmBuy">Get Premium</button>
    </section>
    <section class="pm-plan pm-plus">
      <h2>Premium Plus</h2>
      <div class="pm-price"><span id="pmAmt2">$9.99</span> <small id="pmPer2">per month</small></div>
      <span class="pm-save" id="pmSave2"></span>
      <ul>
        <li>Everything in Premium</li>
        <li>4K HDR and the highest quality</li>
        <li>Download in the highest quality</li>
        <li>Plus badge on your profile and comments</li>
        <li>Priority support</li>
        <li>First access to every new feature</li>
      </ul>
      <button class="pm-btn pm-btn-plus" type="button" id="pmBuy2">Get Plus</button>
    </section>
  </div>
  <p class="pm-note" id="pmNote"></p>
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
  }

  function openModal(tier) {
    chosen = KEY[tier][period];
    var p = VSPremium.PLANS[chosen], ul = $('pmPayList');
    $('pmModalPlan').textContent = (tier === 'plus' ? 'Premium Plus' : 'Premium') + ' ' + p.label.replace(/^Plus /, '') + ' plan: ' + money(p.price) + ' ' + PER[period];
    ul.innerHTML = '';
    (VSPremium.PAY_INFO || []).forEach(function (t) {
      var li = document.createElement('li'); li.textContent = t; ul.appendChild(li);
    });
    $('pmRef').value = '';
    $('pmModal').hidden = false;
    $('pmRef').focus();
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
  $('pmCancel').onclick = function () { run(function () { return VSPremium.cancel(); }, 'Premium cancelled'); };
  document.addEventListener('vs-premium-change', render);
  render();
});
</script>
</body>
</html>