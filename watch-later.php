<?php
/*
 * VibeStream - Watch later page (like YouTube's playlist page).
 * Reads the list saved by the three-dot menu ("Save to Watch Later", stored in this browser)
 * and shows it with Play all, Shuffle, sorting, drag to reorder and remove.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';

$owner = trim((string)($_SESSION['username'] ?? ''));

$extraCss = <<<'CSS'
.wl { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 24px; max-width: 1280px; margin: 0 auto; padding: 16px 24px 56px; align-items: start; }
.wl-side {
    position: sticky; top: 76px; border-radius: 16px; padding: 24px; overflow: hidden; color: #fff;
    background: linear-gradient(180deg, #3b3b46, #1c1c22);
}
.wl-side::before { content: ""; position: absolute; inset: 0; background-size: cover; background-position: center; filter: blur(40px) saturate(1.2); opacity: .55; transform: scale(1.4); }
.wl-side > * { position: relative; }
.wl-cover { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; border-radius: 12px; background: rgba(255, 255, 255, .12); box-shadow: 0 6px 24px rgba(0, 0, 0, .35); }
.wl-side h1 { margin: 20px 0 14px; font-size: 28px; line-height: 1.2; font-weight: 800; }
.wl-owner { margin: 0 0 6px; font-size: 14px; font-weight: 600; }
.wl-count { margin: 0 0 18px; font-size: 13px; opacity: .8; }
.wl-actions { display: flex; gap: 8px; }
.wl-btn { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 38px; border: 0; border-radius: 999px; font: inherit; font-size: 14px; font-weight: 700; cursor: pointer; }
.wl-btn.play { background: #fff; color: #0f0f0f; }
.wl-btn.shuffle { background: rgba(255, 255, 255, .18); color: #fff; }
.wl-btn:disabled { opacity: .5; cursor: default; }
.wl-btn svg { width: 18px; height: 18px; fill: currentColor; }
.wl-note { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0 0 14px; padding: 12px 16px; border-radius: 10px; background: var(--surface-2); font-size: 14px; }
.wl-note[hidden] { display: none; }
.wl-note button { border: 0; background: transparent; color: inherit; width: 32px; height: 32px; border-radius: 50%; font-size: 20px; cursor: pointer; }
.wl-note button:hover { background: rgba(128, 128, 128, .25); }
.wl-bar { margin: 0 0 12px; }
.wl-sort { height: 34px; padding: 0 12px; border-radius: 8px; border: 0; background: var(--surface-2); color: var(--text); font: inherit; font-size: 14px; font-weight: 600; cursor: pointer; }
.wl-row { display: flex; align-items: center; gap: 12px; padding: 8px 6px; border-radius: 12px; }
.wl-row:hover { background: rgba(128, 128, 128, .12); }
.wl-row.dragging { opacity: .4; }
.wl-row.over { box-shadow: 0 -2px 0 var(--accent, #f9433f); }
.wl-handle { flex: 0 0 22px; text-align: center; color: var(--muted); cursor: grab; font-size: 18px; line-height: 1; user-select: none; }
.wl-thumb { position: relative; flex: 0 0 168px; aspect-ratio: 16 / 9; border-radius: 10px; overflow: hidden; background: var(--surface-2); display: block; }
.wl-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.wl-tag { position: absolute; right: 6px; bottom: 6px; padding: 2px 6px; border-radius: 4px; background: rgba(0, 0, 0, .78); color: #fff; font-size: 11px; font-weight: 700; }
.wl-info { flex: 1; min-width: 0; }
.wl-title { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; margin: 0 0 4px; font-size: 16px; font-weight: 600; line-height: 1.35; color: var(--text); text-decoration: none; }
.wl-meta { margin: 0; font-size: 13px; color: var(--muted); }
.wl-remove { flex: 0 0 auto; width: 34px; height: 34px; border: 0; border-radius: 50%; background: transparent; color: var(--muted); font-size: 18px; cursor: pointer; }
.wl-remove:hover { background: rgba(128, 128, 128, .25); color: var(--text); }
.wl-empty { padding: 60px 16px; text-align: center; color: var(--muted); }
.wl-empty h2 { margin: 0 0 8px; color: var(--text); font-size: 20px; }
@media (max-width: 960px) {
    .wl { grid-template-columns: 1fr; padding: 12px 12px 48px; }
    .wl-side { position: relative; top: 0; }
}
@media (max-width: 560px) {
    .wl-thumb { flex-basis: 120px; }
    .wl-title { font-size: 14px; }
    .wl-handle { display: none; }
}
CSS;

vs_page_start('Watch later', 'watch-later', $extraCss);
?>
<div class="wl" id="wl">
    <aside class="wl-side" id="wlSide">
        <img class="wl-cover" id="wlCover" alt="" hidden>
        <h1>Watch later</h1>
        <p class="wl-owner"><?= htmlspecialchars($owner !== '' ? $owner : 'You', ENT_QUOTES, 'UTF-8') ?></p>
        <p class="wl-count" id="wlCount">0 videos &middot; Saved in this browser</p>
        <div class="wl-actions">
            <button type="button" class="wl-btn play" id="wlPlay" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg> Play all</button>
            <button type="button" class="wl-btn shuffle" id="wlShuffle" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 3h4v4h-2V6.4l-4.6 4.6-1.4-1.4L17.6 5H17zM3 7h4.5l9 10H21v2h-5.5l-9-10H3zM17.6 19l-3.2-3.2 1.4-1.4L19 17.6V17h2v4h-4v-2z"/></svg> Shuffle</button>
        </div>
    </aside>

    <section class="wl-list" aria-label="Watch later videos">
        <div class="wl-note" id="wlNote" hidden><span id="wlNoteText"></span><button type="button" id="wlNoteClose" aria-label="Dismiss">&times;</button></div>
        <div class="wl-bar">
            <select class="wl-sort" id="wlSort" aria-label="Sort">
                <option value="newest">Sort: Date added (newest)</option>
                <option value="oldest">Sort: Date added (oldest)</option>
                <option value="views">Sort: Most views</option>
                <option value="title">Sort: Title A to Z</option>
                <option value="manual">Sort: My order (drag to change)</option>
            </select>
        </div>
        <div id="wlRows"></div>
        <div class="wl-empty" id="wlEmpty" hidden>
            <h2>Nothing saved yet</h2>
            <p>Use the three dots on any video and choose <strong>Save to Watch Later</strong>.</p>
        </div>
    </section>
</div>

<script>
(function () {
  var KEY = 'vs_watch_later';
  var saved = [];            // [{id,title,channel,kind,url}] in the order they were saved / arranged
  var info = {};             // id -> details from the server
  var mode = 'newest';
  var rowsEl = document.getElementById('wlRows');

  function load() { try { var v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function store() { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {} }

  function short(n) {
    n = +n || 0;
    if (n >= 1e9) return (n / 1e9).toFixed(1).replace(/\.0$/, '') + 'B';
    if (n >= 1e6) return (n / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (n >= 1e3) return (n / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return String(n);
  }
  function ago(ts) {
    if (!ts) return '';
    var d = Math.max(0, Date.now() / 1000 - ts), u = [['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]];
    for (var i = 0; i < u.length; i++) { var n = Math.floor(d / u[i][1]); if (n >= 1) return n + ' ' + u[i][0] + (n > 1 ? 's' : '') + ' ago'; }
    return 'just now';
  }
  function href(it) { return (it.kind === 'short' ? 'shorts.php?id=' : 'watch.php?id=') + encodeURIComponent(it.id); }

  function visible() {
    var list = saved.filter(function (s) { return info[s.id]; }).map(function (s, i) { return { s: s, d: info[s.id], i: i }; });
    if (mode === 'newest') list.reverse();
    else if (mode === 'views') list.sort(function (a, b) { return b.d.views - a.d.views || b.i - a.i; });
    else if (mode === 'title') list.sort(function (a, b) { return a.d.title.localeCompare(b.d.title); });
    return list;
  }

  function render() {
    var list = visible();
    rowsEl.textContent = '';
    document.getElementById('wlEmpty').hidden = list.length > 0;
    document.getElementById('wlCount').textContent = list.length + (list.length === 1 ? ' video' : ' videos') + ' \u00b7 Saved in this browser';
    document.getElementById('wlPlay').disabled = document.getElementById('wlShuffle').disabled = !list.length;

    var cover = document.getElementById('wlCover'), side = document.getElementById('wlSide');
    if (list.length && list[0].d.thumb) {
      cover.src = list[0].d.thumb; cover.hidden = false;
      side.style.setProperty('--wl-bg', 'url(' + list[0].d.thumb + ')');
      side.classList.add('has-cover');
    } else { cover.hidden = true; }

    list.forEach(function (x) {
      var row = document.createElement('div'); row.className = 'wl-row'; row.dataset.id = x.s.id;
      if (mode === 'manual') row.draggable = true;

      var handle = document.createElement('span'); handle.className = 'wl-handle'; handle.textContent = '\u2261'; handle.title = 'Drag to reorder (My order)';
      var th = document.createElement('a'); th.className = 'wl-thumb'; th.href = href(x.s);
      if (x.d.thumb) { var im = document.createElement('img'); im.src = x.d.thumb; im.alt = ''; im.loading = 'lazy'; th.appendChild(im); }
      if (x.d.kind === 'short') { var tg = document.createElement('span'); tg.className = 'wl-tag'; tg.textContent = 'SHORT'; th.appendChild(tg); }
      var inf = document.createElement('div'); inf.className = 'wl-info';
      var t = document.createElement('a'); t.className = 'wl-title'; t.href = href(x.s); t.textContent = x.d.title;
      var m = document.createElement('p'); m.className = 'wl-meta';
      m.textContent = [x.d.channel, short(x.d.views) + ' views', ago(x.d.ts)].filter(Boolean).join(' \u00b7 ');
      inf.appendChild(t); inf.appendChild(m);
      var rm = document.createElement('button'); rm.type = 'button'; rm.className = 'wl-remove'; rm.title = 'Remove from Watch later'; rm.setAttribute('aria-label', 'Remove from Watch later'); rm.textContent = '\u2715';
      rm.addEventListener('click', function () {
        saved = saved.filter(function (s) { return s.id !== x.s.id; }); store(); render();
      });
      row.appendChild(handle); row.appendChild(th); row.appendChild(inf); row.appendChild(rm);
      rowsEl.appendChild(row);
    });
  }

  // Drag to reorder (only in "My order")
  var dragId = null;
  rowsEl.addEventListener('dragstart', function (e) { var r = e.target.closest('.wl-row'); if (!r) return; dragId = r.dataset.id; r.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', dragId); } catch (x) {} });
  rowsEl.addEventListener('dragend', function () { dragId = null; rowsEl.querySelectorAll('.dragging,.over').forEach(function (n) { n.classList.remove('dragging', 'over'); }); });
  rowsEl.addEventListener('dragover', function (e) { if (!dragId) return; e.preventDefault(); rowsEl.querySelectorAll('.over').forEach(function (n) { n.classList.remove('over'); }); var r = e.target.closest('.wl-row'); if (r && r.dataset.id !== dragId) r.classList.add('over'); });
  rowsEl.addEventListener('drop', function (e) {
    if (!dragId) return; e.preventDefault();
    var r = e.target.closest('.wl-row'); if (!r || r.dataset.id === dragId) return;
    var from = saved.findIndex(function (s) { return s.id === dragId; }), moved = saved.splice(from, 1)[0];
    var to = saved.findIndex(function (s) { return s.id === r.dataset.id; });
    saved.splice(to, 0, moved); store(); render();
  });

  document.getElementById('wlSort').addEventListener('change', function () { mode = this.value; render(); });
  document.getElementById('wlPlay').addEventListener('click', function () { var l = visible(); if (l.length) location.href = href(l[0].s); });
  document.getElementById('wlShuffle').addEventListener('click', function () { var l = visible(); if (l.length) location.href = href(l[Math.floor(Math.random() * l.length)].s); });
  document.getElementById('wlNoteClose').addEventListener('click', function () { document.getElementById('wlNote').hidden = true; });

  saved = load().filter(function (s) { return s && typeof s.id === 'string'; });
  if (!saved.length) { render(); return; }
  fetch('watch-later-api.php?ids=' + encodeURIComponent(saved.map(function (s) { return s.id; }).join(',')), { credentials: 'same-origin' })
    .then(function (r) { return r.ok ? r.json() : []; })
    .catch(function () { return []; })
    .then(function (list) {
      (list || []).forEach(function (d) { info[d.id] = d; });
      var missing = saved.filter(function (s) { return !info[s.id]; }).length;
      if (missing && Object.keys(info).length) {
        document.getElementById('wlNoteText').textContent = missing + (missing === 1 ? ' unavailable video is hidden' : ' unavailable videos are hidden');
        document.getElementById('wlNote').hidden = false;
      }
      render();
    });
})();
</script>
<style>.wl-side.has-cover::before { background-image: var(--wl-bg); }</style>
<?php vs_page_end(); ?>
