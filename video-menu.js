/*!
 * VibeStream "three dots" menu for video cards.
 * Put this file in the main VibeStream folder, add <script src="video-menu.js"></script> before </body>,
 * and give every video card these attributes (the card can be any element, usually the <a class="video-card">):
 *
 *   data-vm-id="<video id>"            (required)
 *   data-vm-title="<title>"
 *   data-vm-channel="<channel name>"
 *   data-vm-kind="video"               ('short' for shorts)
 *   data-vm-url="watch.php?id=..."     (link used by Share / queue)
 *   data-vm-download="<file url>"      (optional; only when the video is a real file, not YouTube)
 *
 * The dots button is added automatically inside the card's .video-info (or the card itself).
 * Queue, Watch Later, playlists, "Not interested" and "Don't recommend channel" are saved in this browser (localStorage).
 * "Report" uses the dialog from report-widget.php (include it on the page; see $reportHideButton there).
 */
(function () {
  if (window.__vsVideoMenu) return;
  window.__vsVideoMenu = true;

  /* ---------- storage ---------- */
  function load(k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } }
  function save(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (e) { return false; } }
  var K = { queue: 'vs_queue', later: 'vs_watch_later', lists: 'vs_playlists', hidden: 'vs_not_interested', blocked: 'vs_blocked_channels' };

  /* ---------- styles ---------- */
  var css = [
    '.vm-host{position:relative;padding-right:32px}',
    '.vm-btn{position:absolute;top:0;right:0;width:28px;height:28px;border:0;border-radius:50%;background:transparent;color:inherit;display:flex;align-items:center;justify-content:center;cursor:pointer;opacity:.85;padding:0}',
    '.vm-btn:hover,.vm-btn[aria-expanded="true"]{background:rgba(128,128,128,.3);opacity:1}',
    '.vm-btn svg{width:20px;height:20px;fill:currentColor;pointer-events:none}',
    '.vm-menu{position:fixed;z-index:2147483000;min-width:236px;max-width:calc(100vw - 16px);padding:8px 0;border-radius:12px;background:#282828;color:#f1f1f1;box-shadow:0 8px 32px rgba(0,0,0,.5);font:14px/1.3 Roboto,Arial,sans-serif}',
    '.vm-item{display:flex;align-items:center;gap:14px;width:100%;padding:10px 16px;border:0;background:transparent;color:inherit;font:inherit;text-align:left;cursor:pointer}',
    '.vm-item:hover,.vm-item:focus-visible{background:rgba(255,255,255,.12);outline:0}',
    '.vm-item svg{width:22px;height:22px;flex:0 0 22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}',
    'html[data-theme="light"] .vm-menu,body.light .vm-menu,body.light-mode .vm-menu{background:#fff;color:#0f0f0f;box-shadow:0 8px 32px rgba(0,0,0,.25)}',
    'html[data-theme="light"] .vm-item:hover,body.light .vm-item:hover,body.light-mode .vm-item:hover{background:rgba(0,0,0,.08)}',
    '.vm-toast{position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:2147483100;background:#323232;color:#fff;padding:11px 18px;border-radius:10px;font:14px Roboto,Arial,sans-serif;box-shadow:0 6px 24px rgba(0,0,0,.45);display:flex;gap:14px;align-items:center;max-width:calc(100vw - 24px)}',
    '.vm-toast button{background:none;border:0;color:#3ea6ff;font:inherit;font-weight:600;cursor:pointer;padding:0}',
    '.vm-pl{border:0;border-radius:14px;padding:0;width:calc(100% - 32px);max-width:360px;background:#282828;color:#f1f1f1;box-shadow:0 10px 40px rgba(0,0,0,.5)}',
    '.vm-pl::backdrop{background:rgba(0,0,0,.55)}',
    '.vm-pl-box{padding:18px;font:14px Roboto,Arial,sans-serif}',
    '.vm-pl-box h3{margin:0 0 10px;font-size:17px}',
    '.vm-pl-list{max-height:220px;overflow:auto;margin-bottom:10px}',
    '.vm-pl-list label{display:flex;gap:10px;align-items:center;padding:7px 0;cursor:pointer}',
    '.vm-pl-new{display:flex;gap:8px}',
    '.vm-pl-new input{flex:1;min-width:0;padding:8px 10px;border-radius:8px;border:1px solid #555;background:#1b1b1b;color:inherit;font:inherit}',
    '.vm-pl-box button{border:0;border-radius:8px;padding:8px 14px;font:inherit;cursor:pointer;background:#3ea6ff;color:#000}',
    '.vm-pl-done{display:block;margin:14px 0 0 auto;background:#3f3f3f!important;color:#f1f1f1!important}',
    'html[data-theme="light"] .vm-pl,body.light .vm-pl{background:#fff;color:#0f0f0f}',
    'html[data-theme="light"] .vm-pl-new input,body.light .vm-pl-new input{background:#fff;border-color:#ccc}'
  ].join('\n');
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  /* ---------- icons ---------- */
  function ic(p) { return '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>'; }
  var ICON = {
    queue: ic('<path d="M3 6h12M3 12h12M3 18h7"/><path d="M17 15l5 3-5 3z"/>'),
    later: ic('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'),
    list: ic('<path d="M6 3h12v18l-6-4-6 4z"/>'),
    down: ic('<path d="M12 4v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/>'),
    share: ic('<path d="M14 4l7 6-7 6v-4c-6 0-9 2-11 6 1-6 4-10 11-11z"/>'),
    no: ic('<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>'),
    minus: ic('<circle cx="12" cy="12" r="9"/><path d="M8 12h8"/>'),
    flag: ic('<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>')
  };
  var DOTS = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>';

  /* ---------- helpers ---------- */
  function infoOf(card) {
    return {
      id: card.getAttribute('data-vm-id') || '',
      title: card.getAttribute('data-vm-title') || 'Untitled',
      channel: card.getAttribute('data-vm-channel') || '',
      kind: card.getAttribute('data-vm-kind') === 'short' ? 'short' : 'video',
      url: card.getAttribute('data-vm-url') || '',
      download: card.getAttribute('data-vm-download') || ''
    };
  }
  function absUrl(u) { try { return new URL(u || location.href, location.href).href; } catch (e) { return location.href; } }

  var toastEl = null, toastTimer = null;
  function toast(msg, undoLabel, undoFn) {
    if (toastEl) toastEl.remove();
    clearTimeout(toastTimer);
    toastEl = document.createElement('div'); toastEl.className = 'vm-toast'; toastEl.setAttribute('role', 'status');
    var s = document.createElement('span'); s.textContent = msg; toastEl.appendChild(s);
    if (undoLabel && undoFn) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = undoLabel;
      b.onclick = function () { undoFn(); toastEl.remove(); };
      toastEl.appendChild(b);
    }
    document.body.appendChild(toastEl);
    toastTimer = setTimeout(function () { if (toastEl) toastEl.remove(); }, 5000);
  }

  function addTo(key, info, doneMsg, dupMsg) {
    var list = load(key, []);
    for (var i = 0; i < list.length; i++) if (list[i].id === info.id) { toast(dupMsg); return; }
    list.push({ id: info.id, title: info.title, channel: info.channel, kind: info.kind, url: info.url });
    toast(save(key, list) ? doneMsg : 'Could not save in this browser.');
  }

  /* ---------- hiding ("not interested" / "don't recommend") ---------- */
  function applyHidden() {
    var hid = load(K.hidden, []), blk = load(K.blocked, []);
    var cards = document.querySelectorAll('[data-vm-id]');
    for (var i = 0; i < cards.length; i++) {
      var c = cards[i], ch = (c.getAttribute('data-vm-channel') || '').toLowerCase();
      var hide = hid.indexOf(c.getAttribute('data-vm-id')) !== -1 || (ch && blk.indexOf(ch) !== -1);
      c.style.display = hide ? 'none' : '';
    }
  }

  /* ---------- playlist dialog ---------- */
  var plDlg = null;
  function openPlaylists(info) {
    if (!plDlg) {
      plDlg = document.createElement('dialog'); plDlg.className = 'vm-pl';
      plDlg.innerHTML = '<div class="vm-pl-box"><h3>Save to playlist</h3><div class="vm-pl-list"></div>' +
        '<div class="vm-pl-new"><input type="text" maxlength="60" placeholder="New playlist name"><button type="button">Create</button></div>' +
        '<button type="button" class="vm-pl-done">Done</button></div>';
      document.body.appendChild(plDlg);
      plDlg.querySelector('.vm-pl-done').onclick = function () { plDlg.close(); };
    }
    var listEl = plDlg.querySelector('.vm-pl-list'), inp = plDlg.querySelector('input'), mk = plDlg.querySelector('.vm-pl-new button');
    function render() {
      var lists = load(K.lists, {}), names = Object.keys(lists);
      listEl.innerHTML = '';
      if (!names.length) { listEl.textContent = 'No playlists yet. Create one below.'; }
      names.forEach(function (n) {
        var l = document.createElement('label'), cb = document.createElement('input'), sp = document.createElement('span');
        cb.type = 'checkbox'; cb.checked = lists[n].some(function (x) { return x.id === info.id; });
        sp.textContent = n;
        cb.onchange = function () {
          var cur = load(K.lists, {}); cur[n] = cur[n] || [];
          cur[n] = cur[n].filter(function (x) { return x.id !== info.id; });
          if (cb.checked) cur[n].push({ id: info.id, title: info.title, channel: info.channel, kind: info.kind, url: info.url });
          save(K.lists, cur);
        };
        l.appendChild(cb); l.appendChild(sp); listEl.appendChild(l);
      });
    }
    mk.onclick = function () {
      var n = inp.value.trim(); if (!n) return;
      var cur = load(K.lists, {}); if (!cur[n]) cur[n] = [];
      if (!cur[n].some(function (x) { return x.id === info.id; })) cur[n].push({ id: info.id, title: info.title, channel: info.channel, kind: info.kind, url: info.url });
      save(K.lists, cur); inp.value = ''; render();
    };
    inp.onkeydown = function (e) { if (e.key === 'Enter') { e.preventDefault(); mk.onclick(); } };
    render(); plDlg.showModal();
  }

  /* ---------- report dialog (loads report-widget.php on demand if the page did not include it) ---------- */
  function ensureReport(cb) {
    if (typeof window.vsOpenReport === 'function') { cb(true); return; }
    fetch('report-widget.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (html) {
        var box = document.createElement('div'); box.innerHTML = html; document.body.appendChild(box);
        Array.prototype.slice.call(box.querySelectorAll('script')).forEach(function (old) {
          var s = document.createElement('script'); s.textContent = old.textContent; old.parentNode.replaceChild(s, old);
        });
        cb(typeof window.vsOpenReport === 'function');
      })
      .catch(function () { cb(false); });
  }

  /* ---------- actions ---------- */
  var ACTIONS = [
    { key: 'queue', label: 'Add to queue', run: function (i) { addTo(K.queue, i, 'Added to queue', 'Already in your queue'); } },
    { key: 'later', label: 'Save to Watch Later', run: function (i) { addTo(K.later, i, 'Saved to Watch Later', 'Already in Watch Later'); } },
    { key: 'list', label: 'Save to playlist', run: openPlaylists },
    { key: 'down', label: 'Download', run: function (i) {
        if (!i.download) { toast('Download is not available for this video.'); return; }
        var a = document.createElement('a'); a.href = i.download; a.download = ''; a.rel = 'noopener';
        document.body.appendChild(a); a.click(); a.remove();
      } },
    { key: 'share', label: 'Share', run: function (i) {
        var url = absUrl(i.url);
        if (navigator.share) { navigator.share({ title: i.title, url: url }).catch(function () {}); return; }
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(url).then(function () { toast('Link copied'); }, function () { window.prompt('Copy this link:', url); });
        } else { window.prompt('Copy this link:', url); }
      } },
    { key: 'no', label: 'Not interested', run: function (i) {
        var hid = load(K.hidden, []); if (hid.indexOf(i.id) === -1) hid.push(i.id); save(K.hidden, hid); applyHidden();
        toast('Video removed from your recommendations', 'Undo', function () {
          save(K.hidden, load(K.hidden, []).filter(function (x) { return x !== i.id; })); applyHidden();
        });
      } },
    { key: 'minus', label: "Don't recommend channel", run: function (i) {
        var ch = i.channel.toLowerCase();
        if (!ch) { toast('This video has no channel name.'); return; }
        var blk = load(K.blocked, []); if (blk.indexOf(ch) === -1) blk.push(ch); save(K.blocked, blk); applyHidden();
        toast('Channel hidden from your recommendations', 'Undo', function () {
          save(K.blocked, load(K.blocked, []).filter(function (x) { return x !== ch; })); applyHidden();
        });
      } },
    { key: 'flag', label: 'Report', run: function (i) {
        ensureReport(function (ok) {
          if (ok) window.vsOpenReport(i.id, i.kind); else toast('Reporting is not available right now.');
        });
      } }
  ];

  /* ---------- menu ---------- */
  var menu = null, menuBtn = null;
  function closeMenu() {
    if (menu) { menu.remove(); menu = null; }
    if (menuBtn) { menuBtn.setAttribute('aria-expanded', 'false'); menuBtn = null; }
  }
  function openMenu(btn, card) {
    var same = menuBtn === btn; closeMenu(); if (same) return;
    var info = infoOf(card);
    menu = document.createElement('div'); menu.className = 'vm-menu'; menu.setAttribute('role', 'menu');
    ACTIONS.forEach(function (a) {
      var it = document.createElement('button'); it.type = 'button'; it.className = 'vm-item'; it.setAttribute('role', 'menuitem');
      it.innerHTML = ICON[a.key] + '<span></span>'; it.lastChild.textContent = a.label;
      it.onclick = function (e) { e.preventDefault(); e.stopPropagation(); closeMenu(); a.run(info); };
      menu.appendChild(it);
    });
    document.body.appendChild(menu);
    var r = btn.getBoundingClientRect(), mw = menu.offsetWidth, mh = menu.offsetHeight;
    var left = Math.min(Math.max(8, r.right - mw), window.innerWidth - mw - 8);
    var top = r.bottom + 4; if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
    menu.style.left = left + 'px'; menu.style.top = top + 'px';
    btn.setAttribute('aria-expanded', 'true'); menuBtn = btn;
    var first = menu.querySelector('.vm-item'); if (first) first.focus();
  }
  document.addEventListener('click', function (e) { if (menu && !menu.contains(e.target)) closeMenu(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
  window.addEventListener('scroll', closeMenu, true);
  window.addEventListener('resize', closeMenu);

  /* ---------- auto-detect cards that were not marked with data-vm-* ---------- */
  function autoMark() {
    var links = document.querySelectorAll('a[href*="watch.php?id="], a[href*="shorts.php?id="]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      if (a.hasAttribute('data-vm-id') || a.closest('[data-vm-id]') || a.closest('nav, header, footer, [class*="sidebar"], [class*="comment"]')) continue;
      var m = (a.getAttribute('href') || '').match(/(watch|shorts)\.php\?(?:.*&)?id=([^&#]+)/);
      if (!m) continue;
      var id = decodeURIComponent(m[2]), kind = m[1] === 'shorts' ? 'short' : 'video';
      var card = (a.parentElement && a.parentElement.closest('[class*="card"], article, li')) || a.parentElement || a;
      if (card === document.body || card.tagName === 'MAIN') card = a;
      var seen = {}, n = 0, ls = card.querySelectorAll('a[href*="watch.php?id="], a[href*="shorts.php?id="]');
      for (var j = 0; j < ls.length; j++) { var mm = (ls[j].getAttribute('href') || '').match(/id=([^&#]+)/); if (mm && !seen[mm[1]]) { seen[mm[1]] = 1; n++; } }
      if (n > 1) card = a;                       // the "card" holds several videos, so use the link itself
      if (card.hasAttribute('data-vm-id')) continue;
      if (!card.querySelector('img') && !a.querySelector('img') && !(a.textContent || '').trim()) continue;
      var tEl = card.querySelector('[class*="title"]'), cEl = card.querySelector('[class*="channel"]'), img = card.querySelector('img');
      var title = ((tEl && tEl.textContent) || (img && img.alt) || a.textContent || '').trim().replace(/\s+/g, ' ');
      var channel = ((cEl && cEl.textContent) || '').trim().replace(/^@/, '');
      card.setAttribute('data-vm-id', id);
      card.setAttribute('data-vm-title', title || 'Untitled');
      card.setAttribute('data-vm-channel', channel);
      card.setAttribute('data-vm-kind', kind);
      card.setAttribute('data-vm-url', a.getAttribute('href'));
    }
  }

  /* ---------- attach buttons ---------- */
  function attach() {
    autoMark();
    var cards = document.querySelectorAll('[data-vm-id]');
    for (var i = 0; i < cards.length; i++) {
      var card = cards[i];
      if (card.__vm) continue; card.__vm = true;
      var tEl = card.querySelector('[class*="title"]');
      var host = card.querySelector('.video-info') || (tEl && tEl.parentElement && tEl.parentElement !== card ? tEl.parentElement : null) || card;
      host.classList.add('vm-host');
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'vm-btn'; b.setAttribute('aria-label', 'More actions'); b.setAttribute('aria-haspopup', 'menu'); b.setAttribute('aria-expanded', 'false');
      b.innerHTML = DOTS;
      (function (btn, c) {
        btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); openMenu(btn, c); });
      })(b, card);
      host.appendChild(b);
    }
    applyHidden();
  }
  var t = null;
  function later() { clearTimeout(t); t = setTimeout(attach, 50); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach); else attach();
  if (window.MutationObserver) new MutationObserver(later).observe(document.body || document.documentElement, { childList: true, subtree: true });
})();