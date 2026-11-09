/* ---------- Greeting + clock (uses the visitor's own clock) ---------- */
(function () {
  var g = document.getElementById('vsdGreet'), d = document.getElementById('vsdDate'), t = document.getElementById('vsdTime');
  function tick() {
    var n = new Date(), h = n.getHours();
    g.textContent = h < 12 ? 'Good morning' : (h < 18 ? 'Good afternoon' : 'Good evening');
    d.textContent = n.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    t.textContent = n.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  }
  tick();
  setInterval(tick, 20000);
})();

/* ---------- Live online + live activity feed (one request every 5 seconds) ---------- */
(function () {
  var list = document.getElementById('vsdFeed'), onl = document.getElementById('vsdOnline');
  var upd = document.getElementById('vsdUpdated'), dot = document.getElementById('vsdDot');
  var ICON = { user: 'bi-person-plus-fill', video: 'bi-play-fill', short: 'bi-lightning-charge-fill', comment: 'bi-chat-dots-fill', report: 'bi-flag-fill' };
  var TONE = { user: 't-purple', video: 't-indigo', short: 't-green', comment: 't-blue', report: 't-red' };
  var seen = null, skew = 0;
  function key(it) { return it.k + '|' + it.t + '|' + it.title; }
  function ago(ts) {
    var s = Math.max(0, Math.round(Date.now() / 1000 + skew - ts));
    if (s < 5) { return 'just now'; }
    if (s < 60) { return s + 's ago'; }
    if (s < 3600) { return Math.floor(s / 60) + 'm ago'; }
    if (s < 86400) { return Math.floor(s / 3600) + 'h ago'; }
    return Math.floor(s / 86400) + 'd ago';
  }
  function split(it) {
    var t = it.title, s = it.sub, i = t.indexOf(': ');
    if (i > 0) { s = t.slice(i + 2) + (s ? ' \u00b7 ' + s : ''); t = t.slice(0, i); }
    return [t, s];
  }
  function draw(fresh) {
    list.textContent = '';
    if (!items.length) { var e = document.createElement('li'); e.className = 'vsd-empty'; e.style.display = 'block'; e.textContent = 'Nothing has happened yet.'; list.appendChild(e); return; }
    items.slice(0, 5).forEach(function (it) {
      var li = document.createElement('li'); if (fresh[key(it)]) { li.className = 'vsd-new'; }
      var ic = document.createElement('span'); ic.className = 'vsd-fi ' + (TONE[it.k] || 't-purple');
      var i = document.createElement('i'); i.className = 'bi ' + (ICON[it.k] || 'bi-dot'); ic.appendChild(i);
      var sp = split(it), tx = document.createElement('span'); tx.className = 'vsd-tx';
      var st = document.createElement('strong'); st.textContent = sp[0];
      var sm = document.createElement('small'); sm.textContent = sp[1];
      tx.appendChild(st); tx.appendChild(sm);
      var tm = document.createElement('span'); tm.className = 'vsd-time'; tm.setAttribute('data-ts', it.t); tm.textContent = ago(it.t);
      li.appendChild(ic); li.appendChild(tx); li.appendChild(tm); list.appendChild(li);
    });
  }
  var items = [];
  function poll() {
    if (document.hidden) { return; }
    fetch('index.php?live=1&feed=1', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        skew = (j.ts || 0) - Date.now() / 1000;
        if (j.online !== null && j.online !== undefined) { dot.classList.remove('off'); onl.textContent = Number(j.online).toLocaleString(); }
        else { dot.classList.add('off'); }
        upd.textContent = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        var fresh = {}, first = (seen === null); if (first) { seen = {}; }
        (j.feed || []).forEach(function (it) { var k = key(it); if (!seen[k]) { seen[k] = true; if (!first) { fresh[k] = true; } } });
        items = j.feed || [];
        draw(fresh);
      })
      .catch(function () { dot.classList.add('off'); });
  }
  setInterval(function () {
    var els = list.querySelectorAll('[data-ts]');
    for (var x = 0; x < els.length; x++) { var ts = Number(els[x].getAttribute('data-ts')); if (ts) { els[x].textContent = ago(ts); } }
  }, 1000);
  poll();
  setInterval(poll, 5000);
})();