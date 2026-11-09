<?php
/*
 * dashboard-alerts-panel.php  (same folder as index.php)
 *
 * Add this ONE line to index.php, just above  <div class="vsd-grid mt-3">
 *
 *     <?php require __DIR__ . '/dashboard-alerts-panel.php'; ?>
 *
 * It draws an "Alerts" card plus a floating bell with an unread badge.
 * Data comes from dashboard-alerts.php, checked every 30 seconds.
 */
?>
<style>
#vsdAlerts .vsa-list{list-style:none;margin:0;padding:0}
#vsdAlerts .vsa-list li{display:flex;gap:12px;align-items:flex-start;padding:11px 8px;border-bottom:1px solid rgba(var(--nx-violet-rgb,138,92,255),.25);border-radius:8px}
#vsdAlerts .vsa-list li:last-child{border-bottom:0}
#vsdAlerts .vsa-list li.unread{background:rgba(var(--nx-cyan-rgb,0,229,255),.07);box-shadow:inset 3px 0 0 var(--vsa-c,#00e5ff)}
#vsdAlerts .vsa-ico{flex:0 0 34px;width:34px;height:34px;border-radius:10px;display:grid;place-items:center;color:var(--vsa-c,#00e5ff);background:color-mix(in srgb,var(--vsa-c,#00e5ff) 16%,transparent)}
#vsdAlerts .vsa-body{min-width:0;flex:1}
#vsdAlerts .vsa-body strong{display:block;font-weight:600;font-size:.92rem}
#vsdAlerts .vsa-body small{display:block;opacity:.72;line-height:1.45;margin-top:2px}
#vsdAlerts .vsa-sev{flex:0 0 auto;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:3px 8px;border-radius:999px;color:var(--vsa-c,#00e5ff);border:1px solid color-mix(in srgb,var(--vsa-c,#00e5ff) 55%,transparent)}
#vsdAlerts .sev-critical{--vsa-c:var(--nx-red,#ff4d6d)}
#vsdAlerts .sev-warning{--vsa-c:var(--nx-amber,#ffd23f)}
#vsdAlerts .sev-info{--vsa-c:var(--nx-cyan,#00e5ff)}
#vsdAlerts .vsa-ok{display:flex;gap:10px;align-items:center;padding:14px 8px;opacity:.85}
#vsdAlerts .vsa-ok i{color:var(--nx-green,#22e58a);font-size:1.3rem}
#vsdAlerts .vsa-head-btn{margin-left:auto}
#vsdAlerts details{margin-top:8px;font-size:.8rem;opacity:.75}
#vsdAlerts details ul{margin:6px 0 0 18px;padding:0}
#vsdAlerts details.vsa-hist-d ul,#vsdAlerts #vsaHist{margin:8px 0 0;padding:0}
#vsdAlerts .vsa-hist li{padding:8px}
#vsdAlerts .vsa-hist .vsa-ico{flex-basis:28px;width:28px;height:28px;font-size:.85rem}
#vsdAlerts .vsa-hist small.when{opacity:.6}
#vsdAlerts .vsa-histmsg{opacity:.7}
#vsdAlerts .vsa-state{flex:0 0 auto;font-size:.72rem;font-weight:600;opacity:.85}
#vsdAlerts .vsa-state.active{color:var(--nx-red,#ff4d6d)}
#vsdAlerts .vsa-state.done{color:var(--nx-green,#22e58a)}
#vsdAlerts .vsa-note{margin:10px 0 0;font-size:.78rem;opacity:.65}
.vsa-bell{position:fixed;right:18px;bottom:18px;z-index:1900;width:48px;height:48px;border-radius:50%;border:1px solid rgba(var(--nx-violet-rgb,138,92,255),.6);background:var(--nx-solid,#0a0d1f);color:var(--nx-text,#eaf0ff);display:grid;place-items:center;font-size:1.2rem;cursor:pointer;box-shadow:0 0 20px -4px rgba(var(--nx-violet-rgb,138,92,255),.7)}
.vsa-bell:hover,.vsa-bell:focus-visible{border-color:var(--nx-cyan,#00e5ff);box-shadow:0 0 20px -2px rgba(var(--nx-cyan-rgb,0,229,255),.7)}
.vsa-bell .vsa-badge{position:absolute;top:-4px;right:-4px;min-width:20px;height:20px;padding:0 5px;border-radius:999px;background:var(--nx-red,#ff4d6d);color:#fff;font-size:.72rem;font-weight:700;line-height:20px;text-align:center}
.vsa-bell.ring i{animation:vsaRing .9s ease-in-out 2}
@keyframes vsaRing{0%,100%{transform:rotate(0)}20%{transform:rotate(16deg)}40%{transform:rotate(-14deg)}60%{transform:rotate(9deg)}80%{transform:rotate(-6deg)}}
#vsaToasts{position:fixed;right:18px;bottom:78px;z-index:1900;display:flex;flex-direction:column;gap:8px;max-width:min(360px,calc(100vw - 36px))}
.vsa-toast{padding:10px 14px;border-radius:12px;background:rgba(10,13,31,.97);color:#eaf0ff;border:1px solid var(--vsa-c,#00e5ff);box-shadow:0 8px 24px rgba(0,0,0,.4);font-size:.86rem;animation:vsaIn .25s ease-out}
.vsa-toast b{display:block;margin-bottom:2px}
.vsa-toast.sev-critical{--vsa-c:#ff4d6d}.vsa-toast.sev-warning{--vsa-c:#ffd23f}.vsa-toast.sev-info{--vsa-c:#00e5ff}
@keyframes vsaIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@media (prefers-reduced-motion:reduce){.vsa-bell.ring i,.vsa-toast{animation:none}}
</style>

<section class="vsd-card mt-3" id="vsdAlerts" aria-label="Alerts">
  <div class="vsd-head">
    <span class="vsd-hico plain"><i class="bi bi-bell-fill" aria-hidden="true"></i></span>
    <div>
      <h2>Alerts</h2>
      <p class="vsd-sub" id="vsaSub">Checking...</p>
    </div>
    <button type="button" class="vsd-btn vsa-head-btn" id="vsaRead">Mark all read</button>
  </div>
  <ul class="vsa-list" id="vsaList"></ul>
  <details id="vsaSkipWrap" hidden>
    <summary>Checks that could not run</summary>
    <ul id="vsaSkip"></ul>
  </details>
  <details id="vsaHistWrap">
    <summary>Alert history</summary>
    <ul class="vsa-list vsa-hist" id="vsaHist"><li class="vsa-histmsg">Open to load.</li></ul>
  </details>
  <p class="vsa-note" id="vsaNotify"></p>
</section>

<button type="button" class="vsa-bell" id="vsaBell" aria-label="Alerts" title="Alerts">
  <i class="bi bi-bell-fill" aria-hidden="true"></i>
  <span class="vsa-badge" id="vsaBadge" hidden>0</span>
</button>
<div id="vsaToasts" aria-live="polite"></div>

<script>
(function () {
  var URL_ = 'dashboard-alerts.php', EVERY = 30000, KEY = 'vsaSeenAlerts';
  var list = document.getElementById('vsaList'), sub = document.getElementById('vsaSub');
  var bell = document.getElementById('vsaBell'), badge = document.getElementById('vsaBadge');
  var toasts = document.getElementById('vsaToasts'), readBtn = document.getElementById('vsaRead');
  var skipWrap = document.getElementById('vsaSkipWrap'), skipList = document.getElementById('vsaSkip');
  var ICON = { spike: 'bi-graph-up-arrow', uploads: 'bi-cloud-slash', spam: 'bi-shield-exclamation', disk: 'bi-hdd', reports: 'bi-flag' };
  var seen = {}, toasted = {}, alerts = [], first = true;

  try { seen = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { seen = {}; }
  function save() {
    var keep = {};
    alerts.forEach(function (a) { if (seen[a.id]) { keep[a.id] = 1; } });
    seen = keep;
    try { localStorage.setItem(KEY, JSON.stringify(seen)); } catch (e) {}
  }
  function unread() { return alerts.filter(function (a) { return !seen[a.id]; }); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }
  function toast(a) {
    var t = el('div', 'vsa-toast sev-' + a.sev);
    t.appendChild(el('b', '', a.title));
    t.appendChild(el('span', '', a.detail));
    toasts.appendChild(t);
    setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 9000);
  }
  function paintBadge() {
    var n = unread().length;
    badge.hidden = n === 0;
    badge.textContent = n > 99 ? '99+' : String(n);
    bell.title = n ? n + ' unread alert' + (n === 1 ? '' : 's') : 'No new alerts';
    bell.setAttribute('aria-label', bell.title);
  }
  function paint(ts) {
    list.textContent = '';
    if (!alerts.length) {
      var ok = el('li', 'vsa-ok');
      ok.appendChild(el('i', 'bi bi-check-circle-fill'));
      ok.appendChild(el('span', '', 'All clear. No spikes, spam or disk problems found.'));
      list.appendChild(ok);
    }
    alerts.forEach(function (a) {
      var li = el('li', 'sev-' + a.sev + (seen[a.id] ? '' : ' unread'));
      var ico = el('span', 'vsa-ico');
      ico.appendChild(el('i', 'bi ' + (ICON[a.kind] || 'bi-exclamation-triangle')));
      var body = el('div', 'vsa-body');
      body.appendChild(el('strong', '', a.title));
      body.appendChild(el('small', '', a.detail));
      li.appendChild(ico); li.appendChild(body); li.appendChild(el('span', 'vsa-sev', a.sev));
      list.appendChild(li);
    });
    var n = alerts.length;
    sub.textContent = (n ? n + ' alert' + (n === 1 ? '' : 's') : 'Nothing needs attention') +
      ' · checked ' + new Date(ts * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    paintBadge();
  }
  function markRead() {
    alerts.forEach(function (a) { seen[a.id] = 1; });
    save();
    paint(Math.floor(Date.now() / 1000));
  }
  var histWrap = document.getElementById('vsaHistWrap'), hist = document.getElementById('vsaHist');
  var notifyEl = document.getElementById('vsaNotify');
  function when(ts) {
    return ts ? new Date(ts * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : '';
  }
  function span(sec) {
    sec = Math.max(0, sec);
    if (sec < 90) { return 'under 2 min'; }
    if (sec < 5400) { return Math.round(sec / 60) + ' min'; }
    if (sec < 172800) { return Math.round(sec / 3600) + ' h'; }
    return Math.round(sec / 86400) + ' days';
  }
  function loadHistory() {
    fetch(URL_ + '?history=1', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        hist.textContent = '';
        if (!j || !j.ok) { hist.appendChild(el('li', 'vsa-histmsg', 'History is not available (the log table could not be created).')); return; }
        if (!j.history.length) { hist.appendChild(el('li', 'vsa-histmsg', 'Nothing has been recorded yet. Warnings and critical alerts are saved here.')); return; }
        j.history.forEach(function (h) {
          var li = el('li', 'sev-' + h.sev);
          var ico = el('span', 'vsa-ico'); ico.appendChild(el('i', 'bi ' + (ICON[h.kind] || 'bi-exclamation-triangle')));
          var body = el('div', 'vsa-body');
          body.appendChild(el('strong', '', h.title));
          body.appendChild(el('small', '', h.detail));
          body.appendChild(el('small', 'when', 'Started ' + when(h.first) +
            (h.resolved ? ' · lasted ' + span(h.resolved - h.first) : ' · ongoing') + (h.notified ? ' · sent to you' : '')));
          li.appendChild(ico); li.appendChild(body);
          li.appendChild(el('span', 'vsa-state ' + (h.resolved ? 'done' : 'active'), h.resolved ? 'Resolved' : 'Active'));
          hist.appendChild(li);
        });
      })
      .catch(function () { hist.textContent = ''; hist.appendChild(el('li', 'vsa-histmsg', 'Could not load the history.')); });
  }
  histWrap.addEventListener('toggle', function () { if (histWrap.open) { loadHistory(); } });
  function paintNotify(n) {
    if (!n) { return; }
    var on = [];
    if (n.telegram) { on.push('Telegram'); }
    if (n.email) { on.push('email'); }
    notifyEl.textContent = on.length
      ? 'Messages go to: ' + on.join(' and ') + ' (' + (n.min === 'critical' ? 'critical alerts only' : 'warnings and critical') + '), sent by the scheduled job.'
      : 'Phone alerts are off. Fill in dashboard-alerts-config.php and add the scheduled job to get messages on Telegram or email.';
  }
  function poll() {
    if (document.hidden) { return; }
    fetch(URL_, { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j || !j.ok) { throw new Error('bad response'); }
        var fresh = [];
        j.alerts.forEach(function (a) { if (!seen[a.id] && !toasted[a.id]) { fresh.push(a); } toasted[a.id] = 1; });
        alerts = j.alerts;
        paint(j.ts);
        paintNotify(j.notify);
        if (histWrap.open) { loadHistory(); }
        skipList.textContent = '';
        (j.skipped || []).forEach(function (s) { skipList.appendChild(el('li', '', s)); });
        skipWrap.hidden = !(j.skipped && j.skipped.length);
        // pop-ups only for alerts that appear after the page has loaded
        if (!first && fresh.length) {
          fresh.slice(0, 3).forEach(toast);
          bell.classList.remove('ring'); void bell.offsetWidth; bell.classList.add('ring');
        }
        first = false;
      })
      .catch(function () { sub.textContent = 'Could not reach dashboard-alerts.php'; });
  }
  readBtn.addEventListener('click', markRead);
  bell.addEventListener('click', function () {
    var card = document.getElementById('vsdAlerts');
    if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    markRead();
  });
  poll();
  setInterval(poll, EVERY);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); } });
})();
</script>