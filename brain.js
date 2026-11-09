/*
 * brain.js - brain health tracker (compact pill version)
 * A big pill at the top centre with a cute brain + the number of shorts/videos watched.
 * It gets tired just before 50, dizzy past 50, and starts drying out at 100.
 * The numbers, the reset hour and the healing rule come from Settings > Brain Meter
 * (through brain-config.php, next to this file). The values below are only the fallback.
 * The count starts again from 0 every day (at the reset hour, device time).
 * A real break away from the site heals the brain a little.
 * The first time each day the brain reaches a new stage, a small notice pops up.
 * The popup also shows the last 7 days and a healthy streak, and a "Hide for today" link.
 * Click the pill for details.
 * Replays count too (video ended then played again, or a looping short starting over).
 * Add <script src="brain.js" defer></script> to every page you want it on.
 * The count is kept in localStorage, one separate copy per signed-in account (brain-user.php says who is signed in),
 * so two people using the same browser each get their own number.
 * Signed-in accounts are also saved on the server (brain-sync.php), so the same account shows the same
 * count on every device. The newest change wins.
 */
(function () {
  'use strict';
  if (window.__vsBrainLoaded) return;
  window.__vsBrainLoaded = true;

  // ---------- settings ----------
  var ENABLED = true;
  var ALLOW_SNOOZE = true; // true = the popup has a "Hide for today" link (the brain comes back at the next reset hour)
  var DIZZY_START = 50;
  var DRY_START = 100;
  var DRY_FULL = 150;      // always 1.5 x DRY_START
  var HEAL_MINUTES = 30;   // this much time away from the site = one real break (0 = no healing)
  var HEAL_AMOUNT = 20;    // watched count taken off after each real break
  var ASH_START = 1000;    // at this many the brain crumbles to dust
  var GRAVE_START = 50000; // at this many only a grave (and a ghost brain) is left
  var DRY_JUMP = 0.4;      // how dried-out it already looks the moment it hits 100 (0 to 1)
  var DAILY_RESET = true;  // true = count starts again each day, false = lifetime count
  var RESET_HOUR = 7;      // the new day starts at this hour (7 = 7:00 am, in the visitor's own time)
  var IN_ADMIN = /\/adminpanel\//i.test(location.pathname); // the admin panel has its own shorts.php / watch.php pages: managing is not watching
  var KEY_CFG = 'vsBrain:cfg';       // site-wide settings from the admin panel (same for everyone)
  var KEY, KEY_SEEN, KEY_HIST, KEY_SNOOZE; // per-account keys, filled in by setWho()
  var SELF = (document.currentScript && document.currentScript.src) ? document.currentScript.src : '';
  var CFG_URL = SELF ? SELF.replace(/brain\.js(\?.*)?$/i, 'brain-config.php') : 'brain-config.php';
  var WHO_URL = SELF ? SELF.replace(/brain\.js(\?.*)?$/i, 'brain-user.php') : 'brain-user.php';
  var SYNC_URL = SELF ? SELF.replace(/brain\.js(\?.*)?$/i, 'brain-sync.php') : 'brain-sync.php';

  // ---------- whose brain is this? ----------
  // Every account gets its own count, streak and "hidden today" flag, even in the same browser.
  var WHO = '';
  function setWho(w) {
    WHO = (w && String(w)) || 'guest';
    var sfx = ':' + WHO;
    KEY = 'vsBrain:v1' + sfx;
    KEY_SEEN = 'vsBrain:seen' + sfx;
    KEY_HIST = 'vsBrain:hist' + sfx;     // how each past day ended (last 30 days)
    KEY_SNOOZE = 'vsBrain:snooze' + sfx; // the day the person hid the brain
  }
  function rememberWho() { try { sessionStorage.setItem('vsBrain:who', WHO); } catch (e) {} }
  function fetchWho(cb) {
    if (!window.fetch) { cb(null); return; }
    fetch(WHO_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) { cb(j && typeof j.who === 'string' ? j.who : null); })
      .catch(function () { cb(null); });
  }
  var known = (typeof window.VS_BRAIN_USER === 'string' && window.VS_BRAIN_USER) ? window.VS_BRAIN_USER : '';
  if (!known) { try { known = sessionStorage.getItem('vsBrain:who') || ''; } catch (e) {} }
  setWho(known || 'guest');

  // ---------- settings from the admin panel ----------
  function applyConfig(c) {
    if (!c || typeof c !== 'object') return;
    var n = function (v, d) { v = parseInt(v, 10); return isFinite(v) ? v : d; };
    var dz = n(c.dizzy_start, DIZZY_START), dr = n(c.dry_start, DRY_START), as = n(c.ash_start, ASH_START), gr = n(c.grave_start, GRAVE_START);
    if (!(dz > 0 && dz < dr && Math.ceil(dr * 1.5) < as && as < gr)) return; // nonsense numbers: keep what we have
    DIZZY_START = dz; DRY_START = dr; DRY_FULL = Math.round(dr * 1.5); ASH_START = as; GRAVE_START = gr;
    RESET_HOUR = Math.max(0, Math.min(23, n(c.reset_hour, RESET_HOUR)));
    HEAL_MINUTES = Math.max(0, n(c.heal_minutes, HEAL_MINUTES));
    HEAL_AMOUNT = Math.max(0, n(c.heal_amount, HEAL_AMOUNT));
    ENABLED = c.enabled !== false;
  }
  try { applyConfig(JSON.parse(localStorage.getItem(KEY_CFG) || 'null')); } catch (e) {} // last known settings, so there is no flash
  function levelFor(total) { return total >= GRAVE_START ? 4 : total >= ASH_START ? 3 : total >= DRY_START ? 2 : total >= DIZZY_START ? 1 : 0; }

  // ---------- storage ----------
  function today() { // before 7:00 am it still counts as yesterday, so the count starts again at 7:00 am
    var d = new Date();
    if (d.getHours() < RESET_HOUR) d.setDate(d.getDate() - 1);
    return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate();
  }
  function fresh() { return { date: today(), videos: 0, shorts: 0, nudged: 0, ts: 0 }; }
  function load() {
    try {
      var s = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (s && typeof s === 'object') {
        if (DAILY_RESET && s.date !== today()) { archive(s); return fresh(); }
        var v = Math.max(0, +s.videos || 0), sh = Math.max(0, +s.shorts || 0);
        // nudged = the highest stage already announced today (an older save without it counts as already announced)
        var nd = s.nudged === undefined ? levelFor(v + sh) : Math.max(0, Math.min(4, +s.nudged || 0));
        return { date: s.date || today(), videos: v, shorts: sh, nudged: nd, ts: Math.max(0, +s.ts || 0) };
      }
    } catch (e) {}
    return fresh();
  }
  function save(s) { s.ts = Date.now(); writeLocal(s); schedulePush(); }
  function writeLocal(s) { try { localStorage.setItem(KEY, JSON.stringify(s)); } catch (e) {} }

  // ---------- server copy (signed-in accounts only) ----------
  var pushTimer = null, pulled = false;
  function signedIn() { return !!WHO && WHO !== 'guest'; }
  function snoozeDay() { try { return localStorage.getItem(KEY_SNOOZE) || ''; } catch (e) { return ''; } }
  function bundle() {
    var s = load();
    return { who: WHO, state: { date: s.date, videos: s.videos, shorts: s.shorts, nudged: s.nudged, ts: s.ts || 0 }, hist: loadHist(), snooze: snoozeDay() };
  }
  function schedulePush() {
    if (!signedIn() || !pulled) return;   // never overwrite the server before we have read it
    clearTimeout(pushTimer);
    pushTimer = setTimeout(function () { push(false); }, 3000);
  }
  function push(beacon) {
    if (!signedIn() || !pulled) return;
    var body = JSON.stringify(bundle());
    try {
      if (beacon && navigator.sendBeacon) { navigator.sendBeacon(SYNC_URL, new Blob([body], { type: 'text/plain' })); return; }
      if (window.fetch) fetch(SYNC_URL, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' }, body: body, keepalive: true }).catch(function () {});
    } catch (e) {}
  }
  function dayNum(d) { var p = String(d).split('-'); return new Date(+p[0], (+p[1]) - 1, +p[2]).getTime() || 0; }
  function mergeRemote(r) {
    var changed = false, rs = r.state;
    if (rs && typeof rs === 'object' && rs.date === today()) {
      var loc = load();
      if ((+rs.ts || 0) > (loc.ts || 0)) {   // the other device changed it more recently
        writeLocal({ date: rs.date, videos: Math.max(0, +rs.videos || 0), shorts: Math.max(0, +rs.shorts || 0),
                     nudged: Math.max(0, Math.min(4, +rs.nudged || 0)), ts: +rs.ts });
        changed = true;
      }
    }
    var map = {}, h = loadHist();
    h.forEach(function (e) { map[e.d] = e.t; });
    var before = h.length;
    (Array.isArray(r.hist) ? r.hist : []).forEach(function (e) {
      if (e && typeof e.d === 'string' && isFinite(e.t) && (map[e.d] === undefined || map[e.d] < +e.t)) map[e.d] = +e.t;
    });
    var merged = Object.keys(map).map(function (d) { return { d: d, t: map[d] }; })
      .sort(function (a, b) { return dayNum(a.d) - dayNum(b.d); }).slice(-30);
    if (merged.length !== before || merged.some(function (e, i) { return !h[i] || h[i].d !== e.d || h[i].t !== e.t; })) {
      try { localStorage.setItem(KEY_HIST, JSON.stringify(merged)); } catch (e) {}
      changed = true;
    }
    if (r.snooze === today() && snoozeDay() !== today()) {
      try { localStorage.setItem(KEY_SNOOZE, today()); } catch (e) {}
      changed = true;
    }
    return changed;
  }
  function syncPull() {
    pulled = false;
    if (!signedIn() || !window.fetch) return;
    var who = WHO;
    fetch(SYNC_URL, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || who !== WHO || j.who !== who) return;   // no answer, the account changed meanwhile, or the server is talking about someone else
        var changed = (j.bundle && typeof j.bundle === 'object') ? mergeRemote(j.bundle) : false;
        pulled = true;
        if (changed) { shownDay = today(); lastTotal = null; render(); }
        push(false);                     // write the merged result back so every device agrees
      })
      .catch(function () {});
  }

  // ---------- history: how each day ended ----------
  function dayDate(back) { // the "day" the meter is on, `back` days ago (respects the reset hour)
    var d = new Date();
    if (d.getHours() < RESET_HOUR) d.setDate(d.getDate() - 1);
    d.setDate(d.getDate() - back);
    return d;
  }
  function dayKey(back) { var d = dayDate(back); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
  function loadHist() {
    try {
      var a = JSON.parse(localStorage.getItem(KEY_HIST) || '[]');
      return Array.isArray(a) ? a.filter(function (e) { return e && typeof e.d === 'string' && isFinite(e.t); }) : [];
    } catch (e) { return []; }
  }
  function archive(s) { // a day is over: remember its final count (once)
    var t = (+s.videos || 0) + (+s.shorts || 0);
    if (!s.date || t < 1) return;
    var h = loadHist();
    for (var i = 0; i < h.length; i++) if (h[i].d === s.date) return;
    h.push({ d: s.date, t: t });
    while (h.length > 30) h.shift();
    try { localStorage.setItem(KEY_HIST, JSON.stringify(h)); } catch (e) {}
  }
  function snoozed() {
    if (!ALLOW_SNOOZE) return false;
    try { return localStorage.getItem(KEY_SNOOZE) === today(); } catch (e) { return false; }
  }

  // ---------- breaks: time away from the site heals the brain ----------
  function touchSeen() { try { localStorage.setItem(KEY_SEEN, String(Date.now())); } catch (e) {} }
  function healAfterBreak() { // returns how much was healed (0 = nothing)
    if (!ENABLED || HEAL_MINUTES <= 0 || HEAL_AMOUNT <= 0) return 0;
    var seen = 0;
    try { seen = parseInt(localStorage.getItem(KEY_SEEN) || '0', 10) || 0; } catch (e) {}
    if (!seen) return 0;
    var breaks = Math.floor((Date.now() - seen) / (HEAL_MINUTES * 60000));
    if (breaks < 1) return 0;
    var s = load(), total = s.videos + s.shorts;
    if (total < 1) return 0;
    var h = Math.min(total, HEAL_AMOUNT * breaks), keep = total - h;
    s.shorts = Math.round(s.shorts * keep / total);
    s.videos = keep - s.shorts;
    save(s);
    return h;
  }

  // ---------- colour helpers ----------
  function hex(h) { h = h.replace('#', ''); return [parseInt(h.substr(0, 2), 16), parseInt(h.substr(2, 2), 16), parseInt(h.substr(4, 2), 16)]; }
  function mix(a, b, t) {
    var x = hex(a), y = hex(b), o = [];
    for (var i = 0; i < 3; i++) o.push(Math.round(x[i] + (y[i] - x[i]) * t));
    return 'rgb(' + o.join(',') + ')';
  }
  // ---------- styles ----------
  var css = '' +
  '.vsb{position:relative;display:inline-flex;align-items:center;vertical-align:middle;margin-left:12px;flex:0 0 auto;z-index:1100;font-family:Roboto,Arial,sans-serif;color:var(--text,#eaf0ff)}' +
  '.vsb.float{position:fixed;top:10px;left:50%;margin-left:0;transform:translateX(-50%)}' +
  '.vsb button.vsb-pill{display:flex;align-items:center;gap:8px;height:54px;padding:3px 22px 3px 12px!important;border-radius:999px!important;cursor:pointer;font:inherit;' +
    'border:1px solid rgba(255,170,90,.4)!important;background:linear-gradient(180deg,#85502b,#5b3419)!important;color:#ffb23e!important;' +
    'box-shadow:0 5px 18px rgba(0,0,0,.5),inset 0 1px 0 rgba(255,255,255,.12);transition:transform .15s ease}' +
  '.vsb button.vsb-pill:hover{transform:translateY(-1px)}' +
  '.vsb-num{font-size:26px;font-weight:800;line-height:1;color:var(--vsb-num,#ffb23e);font-variant-numeric:tabular-nums;text-shadow:0 1px 2px rgba(0,0,0,.4)}' +
  '.vsb-num.pop{animation:vsb-pop .35s ease}' +
  '.vsb-face{width:46px;height:42px;display:block;overflow:visible;animation:vsb-bob 2.6s ease-in-out infinite}' +
  '.vsb[data-stage="2"] .vsb-face{animation:vsb-dizzy 1.6s ease-in-out infinite}' +
  '.vsb[data-stage="3"] .vsb-face,.vsb[data-stage="4"] .vsb-face{animation:vsb-wobble 2.4s ease-in-out infinite;scale:var(--vsb-scale,1)}' +
  '.vsb[data-stage="2"] .vsb-pill{background:linear-gradient(180deg,#7e3150,#4c2035)!important;border-color:rgba(255,159,192,.65)!important;color:#ff9fc0!important}' +
  '.vsb[data-stage="3"] .vsb-pill,.vsb[data-stage="4"] .vsb-pill{background:linear-gradient(180deg,#7a4d2a,#4a2d17)!important;border-color:rgba(214,160,110,.6)!important;color:#e6bd94!important}' +
  '.vsb-blob{fill:var(--vsb-fill,#ff9fc0);stroke:var(--vsb-stroke,#d4527e);stroke-width:1.8;stroke-linejoin:round}' +
  '.vsb-crease{fill:none;stroke:var(--vsb-stroke,#d4527e);stroke-width:1.2;stroke-linecap:round;opacity:.55}' +
  '.vsb-wr{fill:none;stroke:var(--vsb-stroke,#d4527e);stroke-width:1.1;stroke-linecap:round;opacity:.5}' +
  '.vsb-eye{fill:#3a1d2b}.vsb-shine{fill:#fff}' +
  '.vsb-cheek{fill:#ff5f8f;opacity:.45}' +
  '.vsb-mouth{fill:none;stroke:#3a1d2b;stroke-width:1.4;stroke-linecap:round;stroke-linejoin:round}' +
  '.vsb-tongue{fill:#e0556f}' +
  '.vsb-ew{fill:#fff;stroke:#3a1d2b;stroke-width:1.1}' +
  '.vsb-sp{fill:none;stroke:#3a1d2b;stroke-width:1.2;stroke-linecap:round;stroke-linejoin:round;transform-box:fill-box;transform-origin:center;animation:vsb-spin 1.1s linear infinite}' +
  '.vsb-ouch{fill:#7a1f33;stroke:#3a1d2b;stroke-width:.9;stroke-linejoin:round}' +
  '.vsb-dazed,.vsb-dry,.vsb-dry-crack,.vsb-ash,.vsb-grave,.vsb-tired{display:none}' +
  '.vsb-lid{fill:none;stroke:#3a1d2b;stroke-width:1.3;stroke-linecap:round}' +
  '.vsb-drop{fill:#9fdcff;stroke:#3d86b5;stroke-width:.8;stroke-linejoin:round;animation:vsb-drip 2.4s ease-in infinite}' +
  '.vsb-yawn{transform-box:fill-box;transform-origin:center;animation:vsb-yawn 3.6s ease-in-out infinite}' +
  '.vsb[data-stage=\"1\"] .vsb-hap{display:none}' +
  '.vsb[data-stage=\"1\"] .vsb-tired{display:inline}' +
  '.vsb[data-stage=\"1\"] .vsb-face{animation:vsb-sleepy 3.6s ease-in-out infinite}' +
  '.vsb[data-stage=\"1\"] .vsb-pill{background:linear-gradient(180deg,#7c5a22,#52390f)!important;border-color:rgba(255,200,110,.5)!important}' +
  '.vsb[data-stage="6"] .vsb-blob,.vsb[data-stage="6"] .vsb-crease,.vsb[data-stage="6"] .vsb-wr,.vsb[data-stage="6"] .vsb-hap,.vsb[data-stage="6"] .vsb-dazed,.vsb[data-stage="6"] .vsb-dry,.vsb[data-stage="6"] .vsb-ash{display:none}' +
  '.vsb[data-stage="6"] .vsb-grave{display:inline}' +
  '.vsb[data-stage="6"] .vsb-pill{background:linear-gradient(180deg,#3f4358,#23263a)!important;border-color:rgba(160,170,215,.5)!important;color:#b9c0e0!important}' +
  '.vsb-stone{fill:#8e929c;stroke:#3d4048;stroke-width:1.3;stroke-linejoin:round}' +
  '.vsb-stone-crack{fill:none;stroke:#3d4048;stroke-width:.7;stroke-linecap:round;stroke-linejoin:round;opacity:.7}' +
  '.vsb-rip{fill:#2f3138;font:800 5.4px Arial,sans-serif;letter-spacing:.3px}' +
  '.vsb-mound{fill:#8c857d;stroke:#4f4944;stroke-width:1;stroke-linejoin:round}' +
  '.vsb-ghost{fill:#f2eefa;stroke:#cfc6e6;stroke-width:.6;opacity:.75;animation:vsb-ghost 2.6s ease-in-out infinite}' +
  '.vsb-ghost-face{fill:#3a2f55}' +
  '.vsb[data-stage="5"] .vsb-blob,.vsb[data-stage="5"] .vsb-crease,.vsb[data-stage="5"] .vsb-wr,.vsb[data-stage="5"] .vsb-hap,.vsb[data-stage="5"] .vsb-dazed,.vsb[data-stage="5"] .vsb-dry{display:none}' +
  '.vsb[data-stage="5"] .vsb-ash{display:inline}' +
  '.vsb[data-stage="5"] .vsb-pill{background:linear-gradient(180deg,#5d5852,#37332f)!important;border-color:rgba(200,194,186,.5)!important;color:#d2cbc3!important}' +
  '.vsb-pile{fill:#8c857d;stroke:#4f4944;stroke-width:1.5;stroke-linejoin:round}' +
  '.vsb-chunk{fill:#a8a198;stroke:#4f4944;stroke-width:.7;stroke-linejoin:round}' +
  '.vsb-ash-ew{fill:#e6dcd2;stroke:#3a1d2b;stroke-width:.8}' +
  '.vsb-ash-sp{fill:none;stroke:#3a1d2b;stroke-width:.9;stroke-linecap:round;stroke-linejoin:round;transform-box:fill-box;transform-origin:center;animation:vsb-spin 1.4s linear infinite}' +
  '.vsb-ash-mouth{fill:none;stroke:#3a1d2b;stroke-width:.9;stroke-linecap:round}' +
  '.vsb-dust{fill:#d6cfc5;opacity:.8;animation:vsb-dust 2.8s ease-out infinite}' +
  '.vsb-of{font-style:normal}' +
  '.vsb[data-stage="2"] .vsb-hap,.vsb[data-stage="3"] .vsb-hap,.vsb[data-stage="4"] .vsb-hap{display:none}' +
  '.vsb[data-stage="2"] .vsb-dazed,.vsb[data-stage="3"] .vsb-dazed,.vsb[data-stage="4"] .vsb-dazed{display:inline}' +
  '.vsb[data-stage="3"] .vsb-dry,.vsb[data-stage="4"] .vsb-dry{display:inline}' +
  '.vsb[data-stage="3"] .vsb-tongue,.vsb[data-stage="4"] .vsb-tongue{display:none}' +
  '.vsb[data-stage="3"] .vsb-ew,.vsb[data-stage="4"] .vsb-ew{fill:#e6dcd2}' +
  '.vsb[data-stage="3"] .vsb-blob,.vsb[data-stage="4"] .vsb-blob{stroke-width:2.1}' +
  '.vsb-steam{fill:none;stroke:#f0cfa8;stroke-width:1.1;stroke-linecap:round;opacity:.65;animation:vsb-steam 2.4s ease-in-out infinite}' +
  '.vsb-crumb{fill:var(--vsb-fill,#ff9fc0);stroke:var(--vsb-stroke,#d4527e);stroke-width:.7;stroke-linejoin:round;animation:vsb-crumb 3s ease-in infinite}' +
  '.vsb-tongue-dry{fill:#d27a82;stroke:#3a1d2b;stroke-width:.8;stroke-linejoin:round}' +
  '.vsb-tongue-line{fill:none;stroke:#3a1d2b;stroke-width:.6;stroke-linecap:round;opacity:.5}' +
  '.vsb[data-stage="3"] .vsb-dry-crack,.vsb[data-stage="4"] .vsb-dry-crack{display:inline;fill:none;stroke:var(--vsb-stroke,#d4527e);stroke-width:1.1;stroke-linecap:round;stroke-linejoin:round;opacity:calc(.5 + var(--vsb-dry,0) * .5)}' +
  '.vsb-pop{display:none;position:absolute;left:50%;top:calc(100% + 10px);transform:translateX(-50%);width:250px;padding:14px 16px;text-align:center;border-radius:16px;' +
    'background:var(--surface-2,#121634);border:1px solid var(--border,#1d2557);box-shadow:0 8px 28px rgba(0,0,0,.5);font-size:14px;line-height:1.3}' +
  '.vsb.open .vsb-pop{display:block}' +
  '.vsb-title{margin:0 0 8px;font-size:15px;font-weight:800}' +
  '.vsb-bar{position:relative;height:8px;border-radius:99px;background:rgba(128,128,128,.28);overflow:hidden}' +
  '.vsb-bar::after{content:"";position:absolute;top:0;bottom:0;left:' + (DRY_START / DRY_FULL * 100).toFixed(2) + '%;width:2px;background:rgba(255,255,255,.55)}' +
  '.vsb-bar i{display:block;height:100%;width:0;border-radius:99px;background:var(--vsb-fill,#ff9fc0);transition:width .4s ease,background .4s ease}' +
  '.vsb-nums{display:flex;justify-content:space-between;margin:8px 2px 0;font-size:12px;color:var(--muted,#8f9bd0);font-variant-numeric:tabular-nums}' +
  '.vsb-nums b{color:var(--text,#eaf0ff)}' +
  '.vsb-msg{margin:8px 0 4px;font-size:12px;line-height:1.4}' +
  '.vsb-hist{display:flex;align-items:flex-end;justify-content:space-between;gap:4px;height:46px;margin:10px 2px 0}' +
  '.vsb-hist i{flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;font-style:normal}' +
  '.vsb-hist em{display:block;width:100%;max-width:22px;border-radius:4px 4px 2px 2px}' +
  '.vsb-hist span{margin-top:3px;font-size:10px;line-height:1;color:var(--muted,#8f9bd0)}' +
  '.vsb-hist span.now{color:var(--text,#eaf0ff);font-weight:800}' +
  '.vsb-streak{margin:8px 0 0;font-size:12px;font-weight:700}' +
  '.vsb button.vsb-snooze{display:block;margin:8px auto 0;padding:0!important;border:0!important;background:none!important;box-shadow:none!important;' +
    'color:var(--muted,#8f9bd0)!important;font:inherit;font-size:11px;text-decoration:underline;cursor:pointer}' +
  '.vsb-hint{margin:6px 0 0;font-size:11px;line-height:1.4;color:var(--muted,#8f9bd0)}' +
  '.vsb-toast{display:none;position:absolute;left:50%;top:calc(100% + 10px);transform:translateX(-50%);width:230px;padding:10px 14px;text-align:center;cursor:pointer;border-radius:14px;' +
    'background:var(--surface-2,#121634);border:1px solid var(--vsb-fill,#ff9fc0);box-shadow:0 8px 28px rgba(0,0,0,.5);line-height:1.3}' +
  '.vsb-toast b{display:block;font-size:14px;font-weight:800}' +
  '.vsb-toast span{display:block;margin-top:3px;font-size:12px;color:var(--muted,#8f9bd0)}' +
  '.vsb-toast.show{display:block;animation:vsb-toast .3s ease}' +
  '.vsb.open .vsb-toast{display:none}' +
  '@keyframes vsb-toast{from{opacity:0;translate:0 -6px}to{opacity:1;translate:0 0}}' +
  '@keyframes vsb-sleepy{0%,100%{transform:rotate(0)}50%{transform:rotate(5deg) translateY(1px)}}' +
  '@keyframes vsb-yawn{0%,100%{transform:scale(.55,.3)}40%,70%{transform:scale(1,1)}}' +
  '@keyframes vsb-drip{0%{opacity:0;transform:translateY(-2px)}25%{opacity:1}100%{opacity:0;transform:translateY(4px)}}' +
  '@keyframes vsb-bob{0%,100%{transform:translateY(0)}50%{transform:translateY(-1.5px)}}' +
  '@keyframes vsb-wobble{0%,100%{transform:rotate(-4deg)}50%{transform:rotate(4deg)}}' +
  '@keyframes vsb-dizzy{0%,100%{transform:rotate(-7deg) translateX(-1px)}50%{transform:rotate(7deg) translateX(1px)}}' +
  '@keyframes vsb-spin{to{transform:rotate(360deg)}}' +
  '@keyframes vsb-ghost{0%,100%{transform:translateY(0);opacity:.55}50%{transform:translateY(-2px);opacity:.9}}' +
  '@keyframes vsb-dust{0%{opacity:0;transform:translate(0,0)}20%{opacity:.9}100%{opacity:0;transform:translate(var(--dx,2px),-9px)}}' +
  '@keyframes vsb-steam{0%{opacity:0;transform:translateY(2px)}40%{opacity:.8}100%{opacity:0;transform:translateY(-3px)}}' +
  '@keyframes vsb-crumb{0%{opacity:1;transform:translateY(0)}75%{opacity:1}100%{opacity:0;transform:translateY(4px)}}' +
  '@keyframes vsb-pop{0%{transform:scale(1)}40%{transform:scale(1.35)}100%{transform:scale(1)}}' +
  /* phones: tuck it into the bottom-right corner so it does not cover the top bar */
  '@media (max-width:760px){' +
    '.vsb.float{top:auto;bottom:14px;left:auto;right:14px;transform:none}' +
    '.vsb.float .vsb-pop,.vsb.float .vsb-toast{top:auto;bottom:calc(100% + 10px);left:auto;right:0;transform:none!important}' +
    '.vsb button.vsb-pill{height:46px;padding-right:16px!important}.vsb-num{font-size:21px}.vsb-face{width:38px;height:35px}' +
  '}' +
  '@media (prefers-reduced-motion:reduce){.vsb *{animation:none!important}}';

  // ---------- markup ----------
  function spiral(cx, cy, maxR, turns) { // a spiral line for the dizzy eyes
    var d = '', steps = 60, tot = turns * 2 * Math.PI;
    for (var k = 0; k <= steps; k++) {
      var t = tot * k / steps, r = maxR * k / steps;
      d += (k ? 'L' : 'M') + (cx + r * Math.cos(t)).toFixed(2) + ' ' + (cy + r * Math.sin(t)).toFixed(2);
    }
    return d;
  }
  var face = '' +
  '<svg class="vsb-face" viewBox="0 0 40 36" aria-hidden="true">' +
    '<path class="vsb-blob" d="M20 4C14 1 6 4 5 11C1 14 1 22 6 25C7 31 14 34 20 31C26 34 33 31 34 25C39 22 39 14 35 11C34 4 26 1 20 4Z"/>' +
    '<path class="vsb-crease" d="M20 5v8"/>' +
    '<path class="vsb-wr" d="M9 12q3-2 5 0"/><path class="vsb-wr" d="M26 12q3-2 5 0"/>' +
    '<g class="vsb-hap"><ellipse class="vsb-eye" cx="14" cy="19" rx="2.4" ry="3"/><ellipse class="vsb-eye" cx="26" cy="19" rx="2.4" ry="3"/>' +
      '<circle class="vsb-shine" cx="13.2" cy="17.8" r="0.9"/><circle class="vsb-shine" cx="25.2" cy="17.8" r="0.9"/>' +
      '<ellipse class="vsb-cheek" cx="9.5" cy="24" rx="2.6" ry="1.6"/><ellipse class="vsb-cheek" cx="30.5" cy="24" rx="2.6" ry="1.6"/>' +
      '<path class="vsb-mouth" d="M17.5 24q2.5 2.6 5 0"/></g>' +
    '<g class="vsb-tired">' +
      '<path class="vsb-eye" d="M11.4 19.2h5.2a2.6 2.6 0 0 1-5.2 0z"/><path class="vsb-eye" d="M23.4 19.2h5.2a2.6 2.6 0 0 1-5.2 0z"/>' +
      '<path class="vsb-lid" d="M10.6 19.2h6.8M22.6 19.2h6.8"/><path class="vsb-lid" d="M10.2 17.6l5.6-1M29.8 17.6l-5.6-1"/>' +
      '<ellipse class="vsb-cheek" cx="9.5" cy="24" rx="2.6" ry="1.6"/><ellipse class="vsb-cheek" cx="30.5" cy="24" rx="2.6" ry="1.6"/>' +
      '<g class="vsb-yawn"><ellipse class="vsb-ouch" cx="20" cy="26.6" rx="2.7" ry="3.2"/><ellipse class="vsb-tongue" cx="20" cy="28.6" rx="1.7" ry=".9"/></g>' +
      '<path class="vsb-drop" d="M33.2 7.2c-1.9 2.7-2.6 3.9-2.6 5.1a2.6 2.6 0 0 0 5.2 0c0-1.2-.7-2.4-2.6-5.1z"/>' +
    '</g>' +
    '<g class="vsb-dazed">' +
      '<ellipse class="vsb-ew" cx="13.5" cy="17" rx="6.2" ry="7"/><ellipse class="vsb-ew" cx="26.5" cy="17" rx="6.2" ry="7"/>' +
      '<path class="vsb-sp" d="' + spiral(13.5, 17, 4.9, 2.6) + '"/><path class="vsb-sp" d="' + spiral(26.5, 17, 4.9, 2.6) + '"/>' +
      '<path class="vsb-ouch" d="M16.4 25.4Q20 23 23.6 25.4L22.7 29.6Q20 31.2 17.3 29.6Z"/><ellipse class="vsb-tongue" cx="20" cy="29.2" rx="2.3" ry="1.1"/>' +
    '</g>' +
    '<g class="vsb-dry">' +
      '<path class="vsb-steam" d="M12 1q-2-1.8 0-3.6t0-3.6"/>' +
      '<path class="vsb-steam" style="animation-delay:-.8s" d="M20 0q2-1.8 0-3.6t0-3.6"/>' +
      '<path class="vsb-steam" style="animation-delay:-1.6s" d="M28 1q-2-1.8 0-3.6t0-3.6"/>' +
      '<path class="vsb-crumb" d="M3.5 30l2.2-1.6 2 .9-.7 2.2z"/>' +
      '<path class="vsb-crumb" style="animation-delay:-1s" d="M1 34l1.8-1.1 1.7 1-1.4 1.5z"/>' +
      '<path class="vsb-crumb" style="animation-delay:-2s" d="M34.5 29.5l2.2-1 1.8 1.2-1.4 1.9-2.1-.4z"/>' +
      '<path class="vsb-crumb" style="animation-delay:-.5s" d="M32 34l1.6-.9 1.3 1-1.2 1.1z"/>' +
      '<path class="vsb-dry-crack" d="M10 6l3 3-2 3 3 2M30 6l-3 3 2 3-3 2M4 16l3 1-1 3 2 2M36 16l-3 1 1 3-2 2M9 27l3-1 1 2 3-1M31 27l-3-1-1 2-3-1M20 6l-1.8 2.2 1.4 1.6M10 14l3 2-1 3 3 1M30 13l-3 2 1 3-3 1M16 4.5l1 2M24 4.5l-1 2"/>' +
      '<path class="vsb-tongue-dry" d="M17.8 29.3C17.4 32 17.8 34.7 20 34.7C22.2 34.7 22.6 32 22.2 29.3Z"/><path class="vsb-tongue-line" d="M20 30.6v2.8"/>' +
    '</g>' +
    '<g class="vsb-ash">' +
      '<path class="vsb-pile" d="M3 32C4 28 8 27 10 24C12 20 16 19 20 18C24 19 28 20 30 24C32 27 36 28 37 32C37 34 35 34.5 33 34.5H7C5 34.5 3 34 3 32Z"/>' +
      '<path class="vsb-chunk" d="M11 25l3-2 2 1.5-1 2.5-3.5.5z"/><path class="vsb-chunk" d="M24 23l3-1.5 2.5 1.5-.5 2.5-3 .5z"/><path class="vsb-chunk" d="M17 20l2.5-1.5 2.5 1-.5 2.5-3.5.5z"/>' +
      '<circle class="vsb-ash-ew" cx="14.8" cy="28.6" r="3.4"/><circle class="vsb-ash-ew" cx="25.2" cy="28.6" r="3.4"/>' +
      '<path class="vsb-ash-sp" d="' + spiral(14.8, 28.6, 2.6, 1.7) + '"/><path class="vsb-ash-sp" d="' + spiral(25.2, 28.6, 2.6, 1.7) + '"/>' +
      '<path class="vsb-ash-mouth" d="M18 32.6q2 1.5 4 0"/>' +
      '<circle class="vsb-dust" cx="10" cy="18" r=".8" style="--dx:-3px"/>' +
      '<circle class="vsb-dust" style="--dx:2px;animation-delay:-.6s" cx="20" cy="15" r="1"/>' +
      '<circle class="vsb-dust" style="--dx:4px;animation-delay:-1.2s" cx="30" cy="18" r=".8"/>' +
      '<circle class="vsb-dust" style="--dx:-2px;animation-delay:-1.8s" cx="15" cy="13" r=".6"/>' +
      '<circle class="vsb-dust" style="--dx:3px;animation-delay:-2.3s" cx="26" cy="12" r=".6"/>' +
    '</g>' +
    '<g class="vsb-grave">' +
      '<path class="vsb-mound" d="M5 34.5C6 31.5 10 30.5 13 31.5H27C30 30.5 34 31.5 35 34.5Z"/>' +
      '<path class="vsb-stone" d="M12.5 33V17C12.5 12.5 15.5 10.5 20 10.5C24.5 10.5 27.5 12.5 27.5 17V33Z"/>' +
      '<text class="vsb-rip" x="20" y="19.6" text-anchor="middle">RIP</text>' +
      '<path class="vsb-stone-crack" d="M23.5 11.5l-1.4 2.4 1.6 1.6-1 2M16 33l1-2.2-1.2-1.4"/>' +
      '<path class="vsb-mound" d="M5 34.5C8 32.6 12 32.4 14 33.2L16 34.5Z"/><path class="vsb-mound" d="M24 34.5L26 33.2C28 32.4 32 32.6 35 34.5Z"/>' +
      '<path class="vsb-ghost" d="M20 -3C17.4 -4 14.4 -2.5 14.4 0C13 1 13 3.5 15 4.5C15.5 6 18 6.5 20 5.5C22 6.5 24.5 6 25 4.5C27 3.5 27 1 25.6 0C25.6 -2.5 22.6 -4 20 -3Z"/>' +
      '<circle class="vsb-ghost-face" cx="18.2" cy="0.8" r=".7"/><circle class="vsb-ghost-face" cx="21.8" cy="0.8" r=".7"/><ellipse class="vsb-ghost-face" cx="20" cy="3" rx=".8" ry=".6"/>' +
    '</g>' +
  '</svg>';

  var root = document.createElement('div');
  root.className = 'vsb';
  root.setAttribute('data-stage', '0');
  root.innerHTML = '' +
    '<div class="vsb-pop" role="status">' +
      '<div class="vsb-title">Healthy brain</div>' +
      '<div class="vsb-bar"><i></i></div>' +
      '<div class="vsb-nums"><span>Videos <b data-k="v">0</b></span><span>Shorts <b data-k="s">0</b></span><span><b data-k="t">0</b><em class="vsb-of"> / ' + DRY_FULL + '</em></span></div>' +
      '<div class="vsb-hist" aria-hidden="true"></div>' +
      '<div class="vsb-streak"></div>' +
      '<div class="vsb-msg"></div>' +
      '<div class="vsb-hint"></div>' +
      '<button type="button" class="vsb-snooze">Hide for today</button>' +
    '</div>' +
    '<div class="vsb-toast" role="status"><b></b><span></span></div>' +
    '<button type="button" class="vsb-pill" aria-expanded="false" aria-label="Brain health">' + face + '<span class="vsb-num">0</span></button>';

  var $ = function (sel) { return root.querySelector(sel); };
  var messages = [
    'Brain is fresh. Scroll responsibly.',
    'Getting sleepy... take a break soon.',
    'Spiral mode. Take a screen break.',
    'Your brain is starting to dry out. Take a break.',
    'Your brain is fully dried out. Time to take a break.',
    'Your brain has crumbled to dust. Time for a long, long break.',
    'Nothing is left of your brain but a ghost. Please go outside.'
  ];
  var titles = ['Healthy brain', 'Getting tired', 'Dizzy...', 'Drying out...', 'Dried out', 'Turned to dust', 'Rest in peace'];
  var lastTotal = null;

  // ---------- render ----------
  function render() {
    var s = load();
    var total = s.videos + s.shorts;
    var dry = total >= DRY_START ? Math.min(1, DRY_JUMP + (1 - DRY_JUMP) * (total - DRY_START) / (DRY_FULL - DRY_START)) : 0; // drying is already visible at 100
    root.style.display = (ENABLED && !snoozed()) ? '' : 'none';
    var stage = total >= GRAVE_START ? 6 : total >= ASH_START ? 5 : total <= DIZZY_START * 0.8 ? 0 : total <= DIZZY_START ? 1 : total < DRY_START ? 2 : total < DRY_FULL ? 3 : 4;

    root.setAttribute('data-stage', stage);
    root.style.setProperty('--vsb-fill', stage >= 3 ? mix('#ff9fc0', '#c48e8a', Math.sqrt(dry)) : '#ff9fc0');
    root.style.setProperty('--vsb-stroke', stage >= 3 ? mix('#d4527e', '#4a2b2b', Math.sqrt(dry)) : '#d4527e');
    root.style.setProperty('--vsb-dry', stage >= 3 ? dry.toFixed(3) : '0');
    root.style.setProperty('--vsb-scale', stage >= 3 ? (1 - 0.12 * dry).toFixed(3) : '1');
    if (stage === 5) { root.style.setProperty('--vsb-fill', '#9a948b'); root.style.setProperty('--vsb-stroke', '#4f4944'); }
    if (stage === 6) { root.style.setProperty('--vsb-fill', '#8d93b5'); root.style.setProperty('--vsb-stroke', '#3d4048'); }
    root.style.setProperty('--vsb-num', stage === 6 ? '#b9c0e0' : stage === 5 ? '#d2cbc3' : stage >= 3 ? '#e6bd94' : stage === 2 ? '#ff9fc0' : '#ffb23e');

    var num = $('.vsb-num');
    num.textContent = total;
    if (lastTotal !== null && total > lastTotal) { num.classList.remove('pop'); void num.offsetWidth; num.classList.add('pop'); }
    lastTotal = total;
    $('.vsb-pill').setAttribute('aria-label', 'Brain health: ' + total + ' watched. Click for details.');

    $('[data-k="v"]').textContent = s.videos;
    $('[data-k="s"]').textContent = s.shorts;
    $('[data-k="t"]').textContent = total;
    $('.vsb-of').style.display = total > DRY_FULL ? 'none' : '';
    $('.vsb-of').textContent = ' / ' + DRY_FULL;
    var hint = $('.vsb-hint');
    hint.textContent = (HEAL_MINUTES > 0 && HEAL_AMOUNT > 0 && stage < 5) ? 'Rest for ' + HEAL_MINUTES + ' min away from the site and your brain heals by ' + HEAL_AMOUNT + '.' : '';
    hint.style.display = hint.textContent ? '' : 'none';
    $('.vsb-title').textContent = titles[stage];
    $('.vsb-msg').textContent = messages[stage];
    $('.vsb-bar i').style.width = Math.min(100, total / DRY_FULL * 100) + '%';
    renderHistory(total);
  }

  // last 7 days (today on the right) + the healthy streak
  function renderHistory(total) {
    var h = loadHist(), map = {}, i, out = '';
    for (i = 0; i < h.length; i++) map[h[i].d] = +h[i].t;
    var colors = ['#ff9fc0', '#ffb23e', '#c48e8a', '#9a948b', '#8d93b5'];
    for (i = 6; i >= 0; i--) {
      var t = i === 0 ? total : map[dayKey(i)], has = t !== undefined;
      var px = has ? Math.max(3, Math.round(Math.min(1, t / DRY_FULL) * 28)) : 3;
      out += '<i title="' + (has ? t + ' watched' : 'no visit') + '"><em style="height:' + px + 'px;' +
        (has ? 'background:' + colors[levelFor(t)] : 'background:currentColor;opacity:.25') + '"></em>' +
        '<span' + (i === 0 ? ' class="now"' : '') + '>' + 'SMTWTFS'.charAt(dayDate(i).getDay()) + '</span></i>';
    }
    $('.vsb-hist').innerHTML = out;
    // streak: today (if still healthy) + the days before it that ended healthy; days with no visit count as healthy
    var n = 0, seen = 0, k;
    if (total < DRY_START) {
      n = 1;
      for (i = 1; i <= 30; i++) {
        k = dayKey(i);
        if (map[k] !== undefined) { seen++; if (map[k] >= DRY_START) break; n++; }
        else if (seen >= h.length) break;
        else n++;
      }
    }
    $('.vsb-streak').textContent = 'Healthy streak: ' + n + (n === 1 ? ' day' : ' days');
  }

  // ---------- little notices ----------
  var toastTimer = null;
  function showToast(title, text) {
    var t = $('.vsb-toast');
    if (!t || !ENABLED || snoozed()) return;
    t.querySelector('b').textContent = title;
    t.querySelector('span').textContent = text;
    t.classList.remove('show'); void t.offsetWidth; t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(hideToast, 7000);
  }
  function hideToast() { var t = $('.vsb-toast'); if (t) t.classList.remove('show'); }
  var nudgeTitles = [
    '',
    'Your brain is getting dizzy',
    'Your brain is starting to dry out',
    'Your brain has crumbled to dust',
    'Only a ghost of your brain is left'
  ];

  function add(kind) {
    if (!ENABLED || IN_ADMIN) return;
    var s = load();
    if (kind === 'short') s.shorts++; else s.videos++;
    var total = s.videos + s.shorts, lvl = levelFor(total), nudge = lvl > s.nudged;
    if (nudge) s.nudged = lvl; // each stage is announced once a day
    save(s);
    touchSeen();
    render();
    if (nudge) showToast(nudgeTitles[lvl], 'You have watched ' + total + ' today. Time for a break?');
  }
  function afterBreak() { // call when the person comes back to the site
    var h = healAfterBreak();
    touchSeen();
    if (h > 0) { lastTotal = null; render(); showToast('Nice break!', 'Your brain healed a little (-' + h + ').'); }
  }

  // ---------- count what gets watched (page opens) ----------
  function currentKey() {
    if (IN_ADMIN) return null;
    var file = location.pathname.split('/').pop().toLowerCase();
    var id = new URLSearchParams(location.search).get('id') || '';
    if (file === 'watch.php') return { kind: 'video', key: 'watch:' + id };
    if (file === 'shorts.php') return { kind: 'short', key: 'shorts:' + id };
    return null;
  }
  function isReload() {
    try { var n = performance.getEntriesByType('navigation')[0]; return !!(n && n.type === 'reload'); } catch (e) { return false; }
  }
  var lastKey = null;
  function check(initial) {
    var cur = currentKey();
    if (!cur || cur.key === lastKey) return;
    lastKey = cur.key;
    if (initial && isReload()) return; // refreshing the page is not a new watch
    add(cur.kind);
  }

  // ---------- count replays ----------
  var lastReplay = 0;
  function addReplay() {
    var cur = currentKey();           // replays only count on the watch / shorts pages
    if (!cur) return;
    var now = Date.now();
    if (now - lastReplay < 700) return; // ignore duplicate events for the same replay
    lastReplay = now;
    add(cur.kind);
  }
  // the big player only (not the small preview cards or the related list)
  function isMainBox(el) {
    if (!el) return false;
    if (el.closest && el.closest('#playerWrap')) return el.clientWidth * el.clientHeight > 40000; // the main player on shorts.php sits inside .short-card
    if (el.closest && el.closest('.video-card, .short-card, .recommendations-section, .vsb')) return false;
    return el.clientWidth * el.clientHeight > 40000;
  }

  // 1) normal <video> players: ended -> played again, or a looping video starting over
  document.addEventListener('ended', function (e) {
    var v = e.target;
    if (v && v.tagName === 'VIDEO' && isMainBox(v)) v.__vsbEnded = true;
  }, true);
  document.addEventListener('play', function (e) {
    var v = e.target;
    if (v && v.tagName === 'VIDEO' && v.__vsbEnded) {
      v.__vsbEnded = false;
      if (isMainBox(v)) addReplay();
    }
  }, true);
  document.addEventListener('timeupdate', function (e) {
    var v = e.target;
    if (!v || v.tagName !== 'VIDEO' || !v.loop) return;
    var t = v.currentTime, d = v.duration, last = v.__vsbT || 0;
    v.__vsbT = t;
    if (d > 1 && isFinite(d) && last > d * 0.7 && t < d * 0.3 && last - t > d * 0.4 && isMainBox(v)) addReplay();
  }, true);

  // 2) YouTube players (iframes): listen to their play / ended messages
  function isYT(f) { return /youtube(-nocookie)?\.com\/embed\//i.test(f.src || ''); }
  function prepFrames() {
    var frames = document.querySelectorAll('iframe');
    for (var i = 0; i < frames.length; i++) {
      var f = frames[i];
      if (!isYT(f) || !isMainBox(f)) continue;
      if (!/[?&]enablejsapi=1/.test(f.src)) { // the player must allow the YouTube API, reload it once with it on
        if (f.__vsbFix) continue;
        f.__vsbFix = true;
        try { var u = new URL(f.src); u.searchParams.set('enablejsapi', '1'); u.searchParams.set('origin', location.origin); f.src = u.toString(); } catch (err) {}
        continue;
      }
      if (!f.__vsbOK && f.contentWindow) {
        try {
          f.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 1, channel: 'widget' }), '*');
          f.contentWindow.postMessage(JSON.stringify({ event: 'command', func: 'addEventListener', args: ['onStateChange'] }), '*');
        } catch (err) {}
      }
    }
  }
  window.addEventListener('message', function (e) {
    if (!/^https?:\/\/(www\.)?youtube(-nocookie)?\.com$/i.test(e.origin || '')) return;
    var d;
    try { d = typeof e.data === 'string' ? JSON.parse(e.data) : e.data; } catch (err) { return; }
    if (!d || typeof d !== 'object') return;
    var frames = document.querySelectorAll('iframe'), f = null;
    for (var i = 0; i < frames.length; i++) { if (frames[i].contentWindow === e.source) { f = frames[i]; break; } }
    if (!f) return;
    f.__vsbOK = true;
    var st = null;
    if (d.event === 'onStateChange') st = d.info;
    else if (d.event === 'infoDelivery' && d.info && typeof d.info.playerState === 'number') st = d.info.playerState;
    if (st === null || st === undefined || st === f.__vsbS) return;
    f.__vsbS = st;
    if (st === 0) f.__vsbEnded = true;                       // video finished
    else if (st === 1 && f.__vsbEnded) { f.__vsbEnded = false; if (isMainBox(f)) addReplay(); } // playing again
  }, false);

  // ---------- where the pill goes: top centre ----------
  function place() {
    // pages whose search bar sits in the top centre (like the home page): go right beside the search bar
    var sf = document.querySelector('.app-navbar .search-form, .navbar .search-form, header .search-form');
    if (sf && sf.parentNode && window.innerWidth > 760) {
      var r = sf.getBoundingClientRect(), mid = window.innerWidth / 2;
      if (r.width && r.top < 140 && r.left < mid && r.right > mid) {
        if (root.previousSibling !== sf) sf.parentNode.insertBefore(root, sf.nextSibling);
        root.classList.remove('float');
        return;
      }
    }
    // everywhere else: float at the top centre of the screen
    if (root.parentNode !== document.body) document.body.appendChild(root);
    root.classList.add('float');
  }

  // ---------- settings from the server ----------
  function fetchConfig() {
    if (!window.fetch) return;
    fetch(CFG_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (c) {
        if (!c || typeof c.dizzy_start === 'undefined') return; // empty answer: keep the fallback numbers
        try { localStorage.setItem(KEY_CFG, JSON.stringify(c)); } catch (e) {}
        applyConfig(c);
        shownDay = today();
        lastTotal = null;
        render();
      })
      .catch(function () {});
  }

  // ---------- start ----------
  function mount() {
    var style = document.createElement('style');
    style.id = 'vsb-style';
    style.textContent = css;
    document.head.appendChild(style);
    place();
    window.addEventListener('load', place);
    var rt = null;
    window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(place, 200); });

    var pill = $('.vsb-pill'), pop = $('.vsb-pop');
    pill.addEventListener('click', function (e) {
      e.stopPropagation();
      hideToast();
      var o = root.classList.toggle('open');
      pill.setAttribute('aria-expanded', o ? 'true' : 'false');
      if (o) { // keep the popup inside the screen
        pop.style.transform = 'translateX(-50%)';
        var r = pop.getBoundingClientRect(), shift = 0;
        if (r.left < 8) shift = 8 - r.left;
        else if (r.right > window.innerWidth - 8) shift = window.innerWidth - 8 - r.right;
        if (shift) pop.style.transform = 'translateX(calc(-50% + ' + Math.round(shift) + 'px))';
      }
    });
    document.addEventListener('click', function (e) {
      if (!root.contains(e.target)) { root.classList.remove('open'); pill.setAttribute('aria-expanded', 'false'); }
    });

    $('.vsb-snooze').addEventListener('click', function () {
      try { localStorage.setItem(KEY_SNOOZE, today()); } catch (err) {}
      schedulePush();
      root.classList.remove('open'); pill.setAttribute('aria-expanded', 'false');
      render();
    });
    if (!ALLOW_SNOOZE) $('.vsb-snooze').style.display = 'none';

    var healed = healAfterBreak();   // before counting this page, so a break always comes first
    touchSeen();
    render();
    if (healed > 0) showToast('Nice break!', 'Your brain healed a little (-' + healed + ').');
    check(true);
    fetchConfig();
    var shownDay = today();
    setInterval(function () { var t = today(); if (t !== shownDay) { shownDay = t; render(); } }, 30000); // page left open past 7:00 am: start fresh
    setInterval(dayCheck, 30000);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { touchSeen(); return; }
      dayCheck();
      afterBreak();
    });
    window.addEventListener('pagehide', function () { touchSeen(); if (pushTimer) { clearTimeout(pushTimer); pushTimer = null; push(true); } });
    setInterval(function () { if (!document.hidden) touchSeen(); }, 30000); // proves the person is still here
    window.addEventListener('focus', dayCheck);
    setInterval(function () { check(false); prepFrames(); }, 800); // new URL without page load + YouTube players
    window.addEventListener('storage', function (e) { if (e.key === KEY || e.key === KEY_SNOOZE) render(); }); // keep other tabs in sync
  }

  // ---------- fresh brain every day ----------
  // load() already starts a new count on a new day when the page opens; this also does it
  // if the page is left open past midnight, or the tab/phone wakes up the next day.
  var shownDay = today();
  function dayCheck() {
    if (!DAILY_RESET || today() === shownDay) return;
    shownDay = today();
    save(load());     // load() gives a fresh count for the new day (or today's count if another tab already started it)
    lastTotal = null; // no "pop" animation for the reset
    render();
  }

  window.vsBrain = {
    add: add,                                   // vsBrain.add('short') or vsBrain.add('video')
    reset: function () { save(fresh()); render(); },
    show: function () { try { localStorage.removeItem(KEY_SNOOZE); } catch (e) {} render(); }, // bring the brain back after "Hide for today"
    get: load
  };

  function start() { if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount); }
  function switchTo(w) {              // another account is signed in (or someone signed out)
    setWho(w); rememberWho();
    clearTimeout(pushTimer); pulled = false;
    shownDay = today(); lastTotal = null;
    if (root) render();
    syncPull();
  }
  if (known) {
    start();                          // we think we know who it is: show it now, then double-check with the server
    syncPull();
    fetchWho(function (w) {
      if (!w) return;                 // server did not answer: keep what we have
      rememberWho();
      if (w !== WHO) switchTo(w);
    });
  } else {
    var started = false;
    var answer = function (w) {
      w = w || 'guest';
      if (!started) { started = true; setWho(w); rememberWho(); start(); syncPull(); return; }
      rememberWho();
      if (w !== WHO) switchTo(w);     // the server answered after we had already started as a guest
    };
    fetchWho(answer);
    setTimeout(function () { if (!started) answer(null); }, 1500); // server too slow: carry on as a guest, switch when it answers
  }
})();