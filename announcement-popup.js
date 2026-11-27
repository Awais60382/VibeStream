/*
 * announcement-popup.js
 * Shows a popup in front of the visitor with NEW announcements and a "Coming up" list of
 * upcoming events. Each announcement is shown once per browser, then remembered.
 * Add to header.php:  <script src="announcement-popup.js" defer></script>
 * Add to admin/header.php:  <script src="../announcement-popup.js" defer></script>
 * It shows on every page and to every visitor and account type (members, admin, CEO, owner).
 */
(function () {
  'use strict';
  if (window.__vsAnnPopup) return;
  window.__vsAnnPopup = true;

  var FEED = new URL('announcement-feed.php', (document.currentScript && document.currentScript.src) || location.href).href;
  var ROOT = FEED.replace(/announcement-feed\.php.*$/, '');
  var SEEN_KEY = 'vs-ann-seen';

  var TEST = /[?&]vsann=1\b/.test(location.search); // add ?vsann=1 to any page to force the popup
  function log() { try { console.info.apply(console, ['[announcement-popup]'].concat([].slice.call(arguments))); } catch (e) {} }

  function readSeen() {
    if (TEST) return {};
    try { return JSON.parse(localStorage.getItem(SEEN_KEY) || '{}') || {}; } catch (e) { return {}; }
  }
  function writeSeen(map) {
    if (TEST) return;
    try { localStorage.setItem(SEEN_KEY, JSON.stringify(map)); } catch (e) {}
  }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }
  function fmt(iso) {
    var d = new Date(iso);
    if (isNaN(d)) return '';
    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function icon(kind) {
    var k = String(kind || '').toLowerCase();
    if (k.indexOf('event') > -1) return '📅';
    if (k.indexOf('maint') > -1 || k.indexOf('service') > -1) return '🛠️';
    if (k.indexOf('release') > -1 || k.indexOf('update') > -1 || k.indexOf('feature') > -1) return '🚀';
    if (k.indexOf('premium') > -1) return '💎';
    return '📢';
  }
  function countView(id) {
    try {
      var fd = new FormData();
      fd.append('action', 'hit');
      fd.append('id', id);
      fetch(FEED, { method: 'POST', body: fd, credentials: 'same-origin' }).catch(function () {});
    } catch (e) {}
  }

  var CSS =
    '.vsap-back{position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(0,0,0,.66);backdrop-filter:blur(4px);animation:vsapIn .22s ease}' +
    '@keyframes vsapIn{from{opacity:0}to{opacity:1}}@keyframes vsapUp{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}' +
    '.vsap{width:min(520px,100%);max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden;color:#f6f1f1;font-family:Roboto,Arial,sans-serif;' +
      'border:1px solid rgba(255,60,70,.45);border-radius:22px;background:radial-gradient(420px 180px at 0 0,rgba(255,30,45,.22),transparent 70%),#0d0709;' +
      'box-shadow:0 30px 80px -10px rgba(255,30,45,.35);animation:vsapUp .28s ease}' +
    '.vsap-h{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:16px 18px;border-bottom:1px solid rgba(255,60,70,.28)}' +
    '.vsap-h b{font-size:19px;font-weight:800}.vsap-h b i{font-style:normal;margin-right:8px}' +
    '.vsap-x{width:34px;height:34px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:inherit;font-size:20px;line-height:1;cursor:pointer}' +
    '.vsap-x:hover{background:rgba(255,43,58,.4)}' +
    '.vsap-b{padding:14px 18px;overflow:auto;display:grid;gap:12px}' +
    '.vsap-sec{margin:2px 0 -4px;font-size:12px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#ff5560}' +
    '.vsap-card{display:flex;gap:12px;padding:12px;border:1px solid rgba(255,255,255,.1);border-radius:14px;background:#0a0507}' +
    '.vsap-img{flex:none;display:grid;place-items:center;width:84px;height:64px;border-radius:10px;overflow:hidden;font-size:28px;background:radial-gradient(circle,#4a0b13,#14080a)}' +
    '.vsap-img img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.vsap-t{min-width:0}.vsap-tag{font-size:11px;font-weight:700;color:#ff8a93}' +
    '.vsap-t h3{margin:2px 0 4px;font-size:16px;font-weight:800;overflow-wrap:anywhere}' +
    '.vsap-t p{margin:0;font-size:13px;line-height:1.5;color:#d8cacd;white-space:pre-wrap;overflow-wrap:anywhere}' +
    '.vsap-d{margin-top:6px;font-size:12px;color:#a89aa0}' +
    '.vsap-ev{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid rgba(192,123,255,.4);border-radius:12px;background:rgba(192,123,255,.08)}' +
    '.vsap-ev span{font-size:22px}.vsap-ev b{display:block;font-size:14px}.vsap-ev small{color:#c9b6dd;font-size:12px}' +
    '.vsap-f{display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;border-top:1px solid rgba(255,60,70,.28)}' +
    '.vsap-ok{padding:10px 20px;border:0;border-radius:11px;background:linear-gradient(135deg,#ff2b3a,#c4121f);color:#fff;font:700 14px/1 inherit;cursor:pointer;box-shadow:0 8px 22px -8px rgba(255,43,58,.7)}' +
    '.vsap-ok:hover{filter:brightness(1.1)}';

  function show(fresh, upcoming) {
    var style = el('style');
    style.textContent = CSS;
    document.head.appendChild(style);

    var back = el('div', 'vsap-back');
    var box = el('div', 'vsap');
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'Announcements');

    var h = el('div', 'vsap-h');
    var title = el('b');
    var ic = el('i', '', '📢');
    title.appendChild(ic);
    title.appendChild(document.createTextNode(fresh.length ? "What's new" : 'Coming up'));
    var x = el('button', 'vsap-x', '×');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    h.appendChild(title);
    h.appendChild(x);

    var body = el('div', 'vsap-b');
    fresh.slice(0, 3).forEach(function (it) {
      var card = el('div', 'vsap-card');
      var img = el('div', 'vsap-img');
      if (it.thumb) {
        var im = el('img');
        im.alt = '';
        im.src = ROOT + it.thumb;
        img.appendChild(im);
      } else {
        img.textContent = icon(it.kind);
      }
      var t = el('div', 'vsap-t');
      t.appendChild(el('div', 'vsap-tag', it.kindLabel));
      t.appendChild(el('h3', '', it.title));
      t.appendChild(el('p', '', it.body));
      t.appendChild(el('div', 'vsap-d', fmt(it.publishAt)));
      card.appendChild(img);
      card.appendChild(t);
      body.appendChild(card);
    });
    if (fresh.length > 3) {
      body.appendChild(el('div', 'vsap-d', '+ ' + (fresh.length - 3) + ' more on the announcements page'));
    }

    if (upcoming.length) {
      body.appendChild(el('div', 'vsap-sec', 'Coming up'));
      upcoming.slice(0, 4).forEach(function (it) {
        var ev = el('div', 'vsap-ev');
        ev.appendChild(el('span', '', '📅'));
        var w = el('div');
        w.appendChild(el('b', '', it.title));
        w.appendChild(el('small', '', fmt(it.eventAt)));
        ev.appendChild(w);
        body.appendChild(ev);
      });
    }

    var f = el('div', 'vsap-f');
    var ok = el('button', 'vsap-ok', 'Got it');
    ok.type = 'button';
    f.appendChild(ok);

    box.appendChild(h);
    box.appendChild(body);
    box.appendChild(f);
    back.appendChild(box);
    document.body.appendChild(back);
    ok.focus();

    function close() {
      document.removeEventListener('keydown', onKey);
      back.remove();
      style.remove();
    }
    function onKey(e) { if (e.key === 'Escape') close(); }
    document.addEventListener('keydown', onKey);
    x.addEventListener('click', close);
    ok.addEventListener('click', close);
    back.addEventListener('click', function (e) { if (e.target === back) close(); });
  }

  function start() {
    fetch(FEED + '?_=' + Date.now(), { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (!r.ok) { log('feed returned HTTP ' + r.status, FEED); return null; } return r.json(); })
      .then(function (d) {
        if (!d || !d.items || !d.items.length) { log('no live announcements in the feed', d); return; }
        log('feed items:', d.items.length);
        var seen = readSeen();
        var fresh = d.items.filter(function (it) { return !seen[it.id]; });
        var upcoming = d.items.filter(function (it) { return it.upcoming; })
          .sort(function (a, b) { return new Date(a.eventAt) - new Date(b.eventAt); });
        // Nothing new: only pop up for upcoming events we have not shown today.
        if (!fresh.length) {
          var today = new Date().toDateString();
          if (!upcoming.length || seen.__evDay === today) return;
          seen.__evDay = today;
          writeSeen(seen);
          show([], upcoming);
          return;
        }
        fresh.forEach(function (it) { seen[it.id] = 1; countView(it.id); });
        seen.__evDay = new Date().toDateString();
        writeSeen(seen);
        show(fresh, upcoming);
      })
      .catch(function (e) { log('feed could not be read', e); });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();