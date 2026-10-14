/*
 * VibeStream - search suggestions.
 * Works on header.php pages (#vs-search-input) and on index.php (#search-input inside .search-form).
 * Extra bars: put data-vs-suggest-input="videos" (or "history" for watch history) on the <input>.
 * Shows your recent searches first, then ONLY your own videos and shorts (from search-suggest.php).
 * Searching opens index.php?q=... (your main results page).
 */
(function () {
  var KEY0 = 'vs-search-history', MAX_HIST = 20, MAX_ITEMS = 10, RESULTS = 'index.php';
  var ICON = {
    search: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>',
    clock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/></svg>',
    x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>'
  };

  // Base folder of this script (so it also works from /adminpanel/ pages).
  var SCRIPT = document.currentScript;
  var BASE = SCRIPT && SCRIPT.src ? SCRIPT.src.replace(/[?#].*$/, '').replace(/[^\/]*$/, '') : '';

  function readHist(key) {
    try { var a = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(a) ? a : []; } catch (e) { return []; }
  }
  function writeHist(key, a) { try { localStorage.setItem(key, JSON.stringify(a.slice(0, MAX_HIST))); } catch (e) {} }
  function saveHist(key, q) {
    q = (q || '').trim(); if (!q) { return; }
    var low = q.toLowerCase();
    var a = readHist(key).filter(function (h) { return h.toLowerCase() !== low; });
    a.unshift(q); writeHist(key, a);
  }
  function delHist(key, q) {
    var low = q.toLowerCase();
    writeHist(key, readHist(key).filter(function (h) { return h.toLowerCase() !== low; }));
  }

  function fetchSuggestions(q, scope, cb) {
    var done = false;
    function finish(list) { if (!done) { done = true; cb(list); } }
    try {
      fetch(BASE + 'search-suggest.php?q=' + encodeURIComponent(q) + (scope ? '&scope=' + encodeURIComponent(scope) : ''), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (d) { finish(Array.isArray(d) ? d : []); })
        .catch(function () { finish([]); });
    } catch (e) { finish([]); }
    setTimeout(function () { finish([]); }, 4000);
  }


  // ---- Voice search (microphone) ----
  var MIC_SVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4M8 22h8"/></svg>';

  function wireMic(btn, input, onDone) {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    var rec = null, listening = false, t = null, ph = input.getAttribute('placeholder') || '';
    function note(m) {
      input.placeholder = m; clearTimeout(t);
      t = setTimeout(function () { if (!listening) { input.placeholder = ph; } }, 3500);
    }
    function stop() { listening = false; btn.classList.remove('is-listening'); input.placeholder = ph; }
    btn.addEventListener('click', function () {
      if (!SR) { note('Voice search needs Chrome, Edge or Safari'); return; }
      if (listening && rec) { rec.stop(); return; }
      rec = new SR();
      rec.lang = document.documentElement.lang || 'en-US';
      rec.interimResults = true; rec.maxAlternatives = 1;
      rec.onstart = function () { listening = true; btn.classList.add('is-listening'); input.placeholder = 'Listening...'; };
      rec.onresult = function (e) {
        var s = '';
        for (var i = 0; i < e.results.length; i++) { s += e.results[i][0].transcript; }
        input.value = s;
        input.dispatchEvent(new Event('input', { bubbles: true }));
      };
      rec.onerror = function (e) {
        stop();
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') { note('Allow microphone access (needs HTTPS)'); }
        else if (e.error === 'no-speech') { note('No speech heard, try again'); }
        else { note('Voice search unavailable'); }
      };
      rec.onend = function () { var had = input.value.trim(); stop(); if (had && onDone) { onDone(had); } };
      try { rec.start(); } catch (err) { stop(); }
    });
  }

  // Adds a small mic inside any search input that has none (admin lists, history, index.php ...).
  function addMic(input) {
    if (input.getAttribute('data-vs-mic') || input.hasAttribute('data-vs-no-mic')) { return; }
    if (input.id === 'vs-search-input' || input.disabled || input.readOnly) { return; }
    if (input.closest('.vs-search-box') || input.closest('.input-group') || input.parentNode.classList.contains('vs-mic-field')) { return; }
    input.setAttribute('data-vs-mic', '1');

    var par = input.parentNode, pw = par.clientWidth, iw = input.offsetWidth;
    var cs = getComputedStyle(input), parentFlex = /flex/.test(getComputedStyle(par).display);
    var wide = !iw || (pw && iw >= pw * 0.9);

    var w = document.createElement('span');
    w.className = 'vs-mic-field';
    w.style.margin = cs.margin;
    if (parentFlex) {
      w.style.display = 'block';
      if (wide) { w.style.flex = '1 1 auto'; w.style.width = '100%'; w.style.minWidth = '0'; }
      else { w.style.flex = cs.flex; w.style.width = iw + 'px'; w.style.maxWidth = '100%'; }
    } else if (wide || cs.display === 'block') {
      w.style.display = 'block'; w.style.width = wide ? '100%' : iw + 'px'; w.style.maxWidth = '100%';
    } else {
      w.style.display = 'inline-block'; w.style.width = iw + 'px'; w.style.maxWidth = '100%'; w.style.verticalAlign = 'middle';
    }
    input.style.margin = '0'; input.style.width = '100%'; input.style.maxWidth = 'none';

    var btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'vs-mic-inline';
    btn.setAttribute('aria-label', 'Search with your voice'); btn.title = 'Search with your voice';
    btn.innerHTML = MIC_SVG;

    par.insertBefore(w, input); w.appendChild(input); w.appendChild(btn);
    wireMic(btn, input, function () {
      var f = input.form;
      if (f && (f.method || 'get').toLowerCase() === 'get') { f.requestSubmit ? f.requestSubmit() : f.submit(); }
    });
  }

  function isLight(color) {
    var m = (color || '').match(/\d+(\.\d+)?/g);
    if (!m || m.length < 3) { return false; }
    return (0.299 * m[0] + 0.587 * m[1] + 0.114 * m[2]) > 140;
  }

  function init() {
    // Voice search mic for every other search bar on the page
    var all = document.querySelectorAll('input[type="search"], input[name="q"], input#search-input, input[data-vs-suggest-input], form[role="search"] input[type="text"], input[type="text"][placeholder*="earch"], input:not([type])[placeholder*="earch"]');
    for (var k = 0; k < all.length; k++) {
      var t = (all[k].type || 'text').toLowerCase();
      if (t === 'text' || t === 'search') { addMic(all[k]); }
    }
    // Mode A: header.php (main site) search box.
    var a = document.getElementById('vs-search-input');
    if (a) { setup(a, true, ''); }
    // Mode B: index.php search form.
    var b = document.getElementById('search-input');
    if (b && b !== a) { setup(b, false, ''); }
    // Mode C: any other search bar - add data-vs-suggest-input="videos" (or "history") to the <input>.
    var c = document.querySelectorAll('input[data-vs-suggest-input]');
    for (var i = 0; i < c.length; i++) {
      if (c[i] === a || c[i] === b) { continue; }
      setup(c[i], false, c[i].getAttribute('data-vs-suggest-input') === 'history' ? 'history' : '');
    }
  }

  function setup(input, modeA, scope) {
    var KEYX = scope ? KEY0 + '-' + scope : KEY0;
    var box, wrap, goBtn = null, form = null;
    if (modeA) {
      box = input.closest('.vs-search-box'); wrap = box && box.closest('.vs-search-wrap');
      goBtn = document.getElementById('vs-search-btn');
    } else {
      form = input.closest('form'); box = form; wrap = form;
    }
    if (!box || !wrap || wrap.getAttribute('data-vs-suggest')) { return; }
    wrap.setAttribute('data-vs-suggest', '1');
    if (!modeA && getComputedStyle(wrap).position === 'static') { wrap.style.position = 'relative'; }

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.setAttribute('spellcheck', 'false');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-expanded', 'false');

    if (modeA && !document.getElementById('vs-mic-btn') && !wrap.querySelector('.vs-mic-btn')) {
      var mb = document.createElement('button');
      mb.type = 'button'; mb.className = 'vs-mic-btn'; mb.innerHTML = MIC_SVG;
      mb.setAttribute('aria-label', 'Search with your voice'); mb.title = 'Search with your voice';
      wrap.appendChild(mb);
      wireMic(mb, input, function (t) { go(t); });
    }

    var clear = null;
    if (modeA) {
      var lead = document.createElement('span');
      lead.className = 'vs-lead-icon'; lead.innerHTML = ICON.search;
      box.insertBefore(lead, input);
      clear = document.createElement('button');
      clear.type = 'button'; clear.className = 'vs-clear-btn'; clear.hidden = true;
      clear.setAttribute('aria-label', 'Clear search'); clear.title = 'Clear'; clear.innerHTML = ICON.x;
      box.insertBefore(clear, goBtn || null);
    }

    var list = document.createElement('ul');
    list.className = 'vs-suggest'; list.hidden = true; list.setAttribute('role', 'listbox');
    wrap.appendChild(list);

    var items = [], active = -1, typedValue = '', lastQuery = null, timer = null;

    function isOpen() { return !list.hidden; }
    function close() { list.hidden = true; active = -1; input.setAttribute('aria-expanded', 'false'); }
    function toggleClear() { if (clear) { clear.hidden = !input.value.length; } }
    function setActive(i) {
      var lis = list.children;
      if (active >= 0 && lis[active]) { lis[active].classList.remove('is-active'); }
      active = i;
      if (active >= 0 && lis[active]) {
        lis[active].classList.add('is-active');
        lis[active].scrollIntoView({ block: 'nearest' });
        input.value = items[active].text;
      } else {
        input.value = typedValue;
      }
      toggleClear();
    }

    // Open the results page for this text.
    function go(text) {
      text = (text || '').trim();
      if (!text) { input.focus(); return; }
      input.value = text; saveHist(KEYX, text); close();
      if (modeA) { location.href = BASE + RESULTS + '?q=' + encodeURIComponent(text); }
      else if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    }

    function draw() {
      list.innerHTML = '';
      if (!items.length) { close(); return; }
      var low = typedValue.trim().toLowerCase();
      list.classList.toggle('is-dark', isLight(getComputedStyle(input).color));
      list.style.width = box.offsetWidth + 'px';
      items.forEach(function (it) {
        var li = document.createElement('li');
        li.setAttribute('role', 'option');
        var ic = document.createElement('span'); ic.className = 'vs-s-icon'; ic.innerHTML = it.hist ? ICON.clock : ICON.search;
        var tx = document.createElement('span'); tx.className = 'vs-s-text';
        if (low && it.text.toLowerCase().indexOf(low) === 0) {
          tx.appendChild(document.createTextNode(it.text.slice(0, low.length)));
          var b = document.createElement('b'); b.textContent = it.text.slice(low.length); tx.appendChild(b);
        } else if (low) {
          var b2 = document.createElement('b'); b2.textContent = it.text; tx.appendChild(b2);
        } else {
          tx.textContent = it.text;
        }
        li.appendChild(ic); li.appendChild(tx);
        if (it.hist) {
          var del = document.createElement('button');
          del.type = 'button'; del.className = 'vs-s-del'; del.textContent = 'Delete';
          del.setAttribute('aria-label', 'Remove from search history');
          del.addEventListener('mousedown', function (e) { e.preventDefault(); e.stopPropagation(); });
          del.addEventListener('click', function (e) { e.stopPropagation(); delHist(KEYX, it.text); build(typedValue); });
          li.appendChild(del);
        } else {
          if (it.type === 'short') {
            var tag = document.createElement('span'); tag.className = 'vs-s-tag'; tag.textContent = 'Short'; li.appendChild(tag);
          }
          if (it.thumb) {
            var im = document.createElement('img'); im.className = 'vs-s-thumb'; im.alt = ''; im.loading = 'lazy'; im.src = it.thumb;
            im.onerror = function () { im.style.display = 'none'; };
            li.appendChild(im);
          }
        }
        li.addEventListener('mousedown', function (e) { e.preventDefault(); });
        li.addEventListener('click', function () { go(it.text); });
        list.appendChild(li);
      });
      list.hidden = false; input.setAttribute('aria-expanded', 'true');
    }

    function merge(hist, sugs) {
      var seen = {}, out = [];
      hist.forEach(function (t) { var k = t.toLowerCase(); if (!seen[k]) { seen[k] = 1; out.push({ text: t, hist: true }); } });
      sugs.forEach(function (s) {
        var k = (s.title || '').toLowerCase();
        if (k && !seen[k]) { seen[k] = 1; out.push({ text: s.title, hist: false, type: s.type, thumb: s.thumb }); }
      });
      return out.slice(0, MAX_ITEMS);
    }

    function build(q) {
      typedValue = q; var typed = q.trim(), low = typed.toLowerCase();
      var hist = readHist(KEYX).filter(function (h) { return !low || h.toLowerCase().indexOf(low) === 0; }).slice(0, low ? 3 : 8);
      lastQuery = typed;
      items = merge(hist, []); active = -1; draw();
      if (!typed) { return; }
      fetchSuggestions(typed, scope, function (sugs) {
        if (lastQuery !== typed || document.activeElement !== input) { return; }
        items = merge(hist, sugs); active = -1; draw();
      });
    }

    input.addEventListener('input', function () {
      toggleClear(); clearTimeout(timer);
      var v = input.value; typedValue = v;
      timer = setTimeout(function () { build(v); }, 120);
    });
    input.addEventListener('focus', function () { toggleClear(); build(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        if (!isOpen()) { build(input.value); return; }
        e.preventDefault();
        var n = items.length; if (!n) { return; }
        var next = e.key === 'ArrowDown' ? active + 1 : active - 1;
        if (next >= n) { next = -1; } if (next < -1) { next = n - 1; }
        setActive(next);
      } else if (e.key === 'Escape') {
        if (isOpen()) { close(); } else { input.blur(); }
      } else if (e.key === 'Enter' && e.isTrusted) {
        if (modeA) { e.preventDefault(); go(input.value); }
        else { saveHist(KEYX, input.value); close(); }
      }
    });
    if (goBtn) { goBtn.addEventListener('click', function () { go(input.value); }); }
    if (clear) {
      clear.addEventListener('click', function () { input.value = ''; toggleClear(); input.focus(); build(''); });
    }
    document.addEventListener('mousedown', function (e) { if (!wrap.contains(e.target)) { close(); } });
    window.addEventListener('resize', function () { if (isOpen()) { list.style.width = box.offsetWidth + 'px'; } });
    toggleClear();
  }

  // Load the stylesheet automatically when the page did not include it.
  (function () {
    var has = false, ls = document.querySelectorAll('link[rel="stylesheet"]');
    for (var i = 0; i < ls.length; i++) { if ((ls[i].getAttribute('href') || '').indexOf('search-suggest.css') !== -1) { has = true; } }
    if (!has && BASE) {
      var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = BASE + 'search-suggest.css';
      document.head.appendChild(l);
    }
  })();

  if (window.__vsSuggestLoaded) { return; }
  window.__vsSuggestLoaded = true;
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();