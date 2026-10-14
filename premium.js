/* VibeStream Premium helper. Loaded by header.php on every page.
   State comes from the server (window.VS_PREMIUM_STATE, printed by header.php). */
(function () {
  var S = window.VS_PREMIUM_STATE || { loggedIn: false, active: false, pending: false, plans: {}, payInfo: [], csrf: '' };
  var PLANS = S.plans || {};
  var API = 'premium-api.php';

  function read() {
    return S.active && S.expires > Date.now() ? { plan: S.plan, since: S.since, expires: S.expires } : null;
  }

  function send(action, plan, ref) {
    if (!S.loggedIn) { location.href = 'login.php'; return Promise.reject(new Error('Please sign in first.')); }
    return fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: action, plan: plan || '', ref: ref || '', csrf: S.csrf })
    }).then(function (r) {
      return r.text().then(function (t) {
        var j;
        try { j = JSON.parse(t); } catch (e) {
          throw new Error('Server error. Check that premium-api.php, premium-store.php and connection.php are in the VibeStream folder.');
        }
        if (!r.ok) throw new Error(j.error || 'Something went wrong.');
        for (var k in j) S[k] = j[k];
        apply();
        return read();
      });
    });
  }

  function apply() {
    var on = !!read(), root = document.documentElement;
    var plus = on && (S.plan === 'admin' || String(S.plan || '').indexOf('plus') === 0);
    root.classList.toggle('vs-premium', on);
    document.querySelectorAll('[data-vs-ad]').forEach(function (el) { el.hidden = on; });
    document.querySelectorAll('[data-vs-badge]').forEach(function (el) {
      el.innerHTML = on ? (plus ? '<span class="vs-badge" title="VibeStream Premium Plus">Plus</span>' : '<span class="vs-badge" title="VibeStream Premium">Premium</span>') : '';
    });
    document.querySelectorAll('[data-premium-only]').forEach(function (el) {
      var lock = el.querySelector(':scope > .vs-lock');
      if (on) { el.classList.remove('vs-locked'); if (lock) lock.remove(); return; }
      el.classList.add('vs-locked');
      if (!lock) {
        lock = document.createElement('a');
        lock.className = 'vs-lock'; lock.href = 'premium.php';
        lock.textContent = 'Premium only. Upgrade to unlock';
        el.appendChild(lock);
      }
    });
    document.querySelectorAll('[data-vs-upgrade]').forEach(function (el) {
      el.textContent = on ? (plus ? 'Plus active' : 'Premium active') : 'Go Premium';
      el.href = 'premium.php';
    });
    document.dispatchEvent(new CustomEvent('vs-premium-change', { detail: read() }));
  }

  var css = '.vs-badge{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;' +
    'background:linear-gradient(135deg,#f5b942,#ff8a3d);color:#2a1700;font:700 11px/18px system-ui,sans-serif}' +
    '.vs-locked{position:relative;overflow:hidden}.vs-locked>*:not(.vs-lock){filter:blur(6px);pointer-events:none;user-select:none}' +
    '.vs-lock{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:12px;' +
    'background:rgba(10,8,18,.55);color:#fff;font:600 14px system-ui,sans-serif;text-decoration:none;z-index:5}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  window.VSPremium = {
    PLANS: PLANS, PAY_INFO: S.payInfo || [], LOGGED_IN: !!S.loggedIn,
    isPremium: function () { return !!read(); },
    isPlus: function () { var r = read(); return !!r && (r.plan === 'admin' || String(r.plan).indexOf('plus') === 0); },
    isPending: function () { return !!S.pending; },
    status: read,
    request: function (plan, ref) { return send('request', plan, ref); },
    cancel: function () { return send('cancel'); },
    refresh: apply
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', apply); else apply();
})();