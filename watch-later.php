<?php
/*
 * VibeStream - Watch later page (like YouTube's playlist page).
 * Reads the signed-in user's server-saved list (or browser storage for guests)
 * and shows it with Play all, Shuffle, sorting, drag to reorder and remove.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/vip-badge.php';

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
/* ===== NEON THEME (dark + light) ===== */
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2e93; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #00e5ff;
            --neon-cyan: #00e5ff; --neon-pink: #ff2e93; --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) body .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(0,229,255,.35); box-shadow: 0 1px 24px -4px rgba(0,229,255,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) body .brand { background: linear-gradient(90deg, #00e5ff, #8a5cff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) body .nav-toggle span { background: #00e5ff; box-shadow: 0 0 6px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) body .nav-toggle:hover, html:not([data-theme="light"]) body .theme-toggle:hover { background: rgba(0,229,255,.14); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) body .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) body .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) body .create-btn, html:not([data-theme="light"]) body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) body #search-input:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 20px rgba(0,229,255,.55); }
        html:not([data-theme="light"]) body .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) body .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) body .sidebar-link:hover { background: rgba(0,229,255,.10); box-shadow: inset 3px 0 0 #00e5ff, 0 0 16px -4px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body .sidebar-heading { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #8a5cff, #ff2e93); border-radius: 8px; }
        html:not([data-theme="light"]) body ::selection { background: rgba(255,46,147,.4); color: #fff; }

/* ---- watch later ---- */
html:not([data-theme="light"]) body .wl-side { border: 1px solid rgba(138,92,255,.55); box-shadow: 0 0 30px -6px rgba(0,229,255,.5), 0 0 70px -20px rgba(255,46,147,.4); }
html:not([data-theme="light"]) body .wl-cover { box-shadow: 0 0 22px -4px rgba(138,92,255,.7); }
html:not([data-theme="light"]) body .wl-side h1 { text-shadow: 0 0 16px rgba(138,92,255,.65); }
html:not([data-theme="light"]) body .wl-btn { transition: box-shadow .18s ease, transform .18s ease; }
html:not([data-theme="light"]) body .wl-btn:hover:not(:disabled) { transform: translateY(-1px); }
html:not([data-theme="light"]) body .wl-btn.play { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; box-shadow: 0 0 18px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .wl-btn.shuffle { background: rgba(5,6,15,.55); color: #fff; border: 1px solid rgba(0,229,255,.6); box-shadow: 0 0 14px rgba(0,229,255,.4); }
html:not([data-theme="light"]) body .wl-note { border: 1px solid rgba(0,229,255,.45); box-shadow: 0 0 18px -6px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .wl-sort { background: rgba(23,28,66,.7); color: #eaf0ff; border: 1px solid rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .wl-sort:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 14px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .wl-row { transition: background .15s ease, box-shadow .15s ease; }
html:not([data-theme="light"]) body .wl-row:hover { background: rgba(0,229,255,.08); box-shadow: inset 3px 0 0 #00e5ff, 0 0 20px -8px rgba(0,229,255,.6); }
html:not([data-theme="light"]) body .wl-row.over { box-shadow: 0 -2px 0 #ff2e93, 0 0 14px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .wl-handle:hover { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.8); }
html:not([data-theme="light"]) body .wl-thumb { border: 1px solid rgba(138,92,255,.3); transition: box-shadow .22s ease, border-color .22s ease; }
html:not([data-theme="light"]) body .wl-row:hover .wl-thumb { border-color: #00e5ff; box-shadow: 0 0 20px rgba(0,229,255,.55), 0 0 40px -8px rgba(138,92,255,.5); }
html:not([data-theme="light"]) body .wl-row:hover .wl-title { color: #00e5ff; text-shadow: 0 0 10px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .wl-tag { background: rgba(5,6,15,.88); color: #00e5ff; border: 1px solid rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .wl-remove:hover { background: rgba(255,46,147,.18); color: #ff2e93; box-shadow: 0 0 12px rgba(255,46,147,.55); }
html:not([data-theme="light"]) body .wl-empty { border: 1px dashed rgba(0,229,255,.5); border-radius: 16px; box-shadow: 0 0 24px -8px rgba(0,229,255,.4); }

html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2e93; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #00b8e6;
            --neon-cyan: #00b8e6; --neon-pink: #ff2e93; --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] body .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(0,184,230,.55); box-shadow: 0 1px 22px -4px rgba(0,184,230,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] body .brand { background: linear-gradient(90deg, #00b8e6, #7c4dff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] body .nav-toggle span { background: #7c4dff; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] body .nav-toggle:hover, html[data-theme="light"] body .theme-toggle:hover { background: rgba(0,184,230,.14); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] body .theme-toggle svg { color: #7c4dff; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] body .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] body .create-btn, html[data-theme="light"] body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] body #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] body #search-input:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 20px rgba(0,184,230,.55); }
        html[data-theme="light"] body .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] body .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] body .sidebar-link:hover { background: rgba(0,184,230,.12); box-shadow: inset 3px 0 0 #00b8e6, 0 0 16px -4px rgba(0,184,230,.5); }
        html[data-theme="light"] body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] body .sidebar-heading { color: #7c4dff; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] body ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #7c4dff, #ff2e93); border-radius: 8px; }
        html[data-theme="light"] body ::selection { background: rgba(255,46,147,.4); color: #0b1030; }

/* ---- watch later ---- */
html[data-theme="light"] body .wl-side { border: 1px solid rgba(124,77,255,.55); box-shadow: 0 0 30px -6px rgba(0,184,230,.5), 0 0 70px -20px rgba(255,46,147,.4); }
html[data-theme="light"] body .wl-cover { box-shadow: 0 0 22px -4px rgba(124,77,255,.7); }
html[data-theme="light"] body .wl-side h1 { text-shadow: 0 0 16px rgba(124,77,255,.3); }
html[data-theme="light"] body .wl-btn { transition: box-shadow .18s ease, transform .18s ease; }
html[data-theme="light"] body .wl-btn:hover:not(:disabled) { transform: translateY(-1px); }
html[data-theme="light"] body .wl-btn.play { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; box-shadow: 0 0 18px rgba(255,46,147,.55); }
html[data-theme="light"] body .wl-btn.shuffle { background: rgba(5,6,15,.55); color: #fff; border: 1px solid rgba(0,184,230,.6); box-shadow: 0 0 14px rgba(0,184,230,.4); }
html[data-theme="light"] body .wl-note { border: 1px solid rgba(0,184,230,.45); box-shadow: 0 0 18px -6px rgba(0,184,230,.5); }
html[data-theme="light"] body .wl-sort { background: #ffffff; color: #0b1030; border: 1px solid rgba(124,77,255,.45); }
html[data-theme="light"] body .wl-sort:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 14px rgba(0,184,230,.5); }
html[data-theme="light"] body .wl-row { transition: background .15s ease, box-shadow .15s ease; }
html[data-theme="light"] body .wl-row:hover { background: rgba(0,184,230,.08); box-shadow: inset 3px 0 0 #00b8e6, 0 0 20px -8px rgba(0,184,230,.6); }
html[data-theme="light"] body .wl-row.over { box-shadow: 0 -2px 0 #ff2e93, 0 0 14px rgba(255,46,147,.6); }
html[data-theme="light"] body .wl-handle:hover { color: #00b8e6; text-shadow: 0 0 8px rgba(0,184,230,.8); }
html[data-theme="light"] body .wl-thumb { border: 1px solid rgba(124,77,255,.3); transition: box-shadow .22s ease, border-color .22s ease; }
html[data-theme="light"] body .wl-row:hover .wl-thumb { border-color: #00b8e6; box-shadow: 0 0 20px rgba(0,184,230,.55), 0 0 40px -8px rgba(124,77,255,.5); }
html[data-theme="light"] body .wl-row:hover .wl-title { color: #0086b3; text-shadow: 0 0 10px rgba(0,184,230,.5); }
html[data-theme="light"] body .wl-tag { background: rgba(5,6,15,.88); color: #7defff; border: 1px solid rgba(0,184,230,.5); }
html[data-theme="light"] body .wl-remove:hover { background: rgba(255,46,147,.18); color: #ff2e93; box-shadow: 0 0 12px rgba(255,46,147,.55); }
html[data-theme="light"] body .wl-empty { border: 1px dashed rgba(0,184,230,.5); border-radius: 16px; box-shadow: 0 0 24px -8px rgba(0,184,230,.4); }

CSS;

vs_page_start('Watch later', 'watch-later', $extraCss);
?>
<div class="wl" id="wl">
    <aside class="wl-side" id="wlSide">
        <img class="wl-cover" id="wlCover" alt="" hidden>
        <h1>Watch later</h1>
        <p class="wl-owner"><?= htmlspecialchars($owner !== '' ? $owner : 'You', ENT_QUOTES, 'UTF-8') ?><?= $owner !== '' ? vs_vip_badge($owner, $conn ?? null, 16) : '' ?></p>
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
  var serverMode = false;
  var storeState = null;
  var rowsEl = document.getElementById('wlRows');

  function load() { try { var v = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
  function store() { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {} }
  function note(text) {
    document.getElementById('wlNoteText').textContent = text;
    document.getElementById('wlNote').hidden = false;
  }
  function storeRequest(action, ids) {
    return fetch('watch-later-store-api.php', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: action, ids: ids, csrf: storeState.csrf })
    }).then(function (r) {
      return r.json().then(function (data) {
        if (!r.ok) throw new Error(data.error || 'Could not update Watch Later.');
        return data;
      });
    });
  }

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
    document.getElementById('wlCount').textContent = list.length + (list.length === 1 ? ' video' : ' videos') +
      (serverMode ? ' \u00b7 Saved to your account' : ' \u00b7 Saved in this browser');
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
        if (serverMode) {
          storeRequest('remove', [x.s.id]).then(function (data) {
            saved = data.ids.map(function (id) { return { id: id }; });
            render();
          }).catch(function (e) { note(e.message); });
          return;
        }
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
    var previous = saved.slice();
    var from = saved.findIndex(function (s) { return s.id === dragId; }), moved = saved.splice(from, 1)[0];
    var to = saved.findIndex(function (s) { return s.id === r.dataset.id; });
    saved.splice(to, 0, moved);
    if (serverMode) {
      storeRequest('order', saved.map(function (s) { return s.id; })).then(function (data) {
        saved = data.ids.map(function (id) { return { id: id }; });
        render();
      }).catch(function (error) { saved = previous; render(); note(error.message); });
    } else { store(); render(); }
  });

  document.getElementById('wlSort').addEventListener('change', function () { mode = this.value; render(); });
  document.getElementById('wlPlay').addEventListener('click', function () { var l = visible(); if (l.length) location.href = href(l[0].s); });
  document.getElementById('wlShuffle').addEventListener('click', function () { var l = visible(); if (l.length) location.href = href(l[Math.floor(Math.random() * l.length)].s); });
  document.getElementById('wlNoteClose').addEventListener('click', function () { document.getElementById('wlNote').hidden = true; });

  function loadDetails() {
    if (!saved.length) { render(); return Promise.resolve(); }
    return fetch('watch-later-api.php?ids=' + encodeURIComponent(saved.map(function (s) { return s.id; }).join(',')), { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error('Could not load video details.');
        return r.json();
      })
      .then(function (list) {
        (list || []).forEach(function (d) { info[d.id] = d; });
        var missing = saved.filter(function (s) { return !info[s.id]; }).length;
        if (missing) note(missing + (missing === 1 ? ' unavailable video is hidden' : ' unavailable videos are hidden'));
        render();
      })
      .catch(function (e) { note(e.message); render(); });
  }

  saved = load().filter(function (s) { return s && typeof s.id === 'string'; });
  fetch('watch-later-store-api.php', { credentials: 'same-origin' })
    .then(function (r) {
      if (!r.ok) throw new Error('Your saved list could not be checked.');
      return r.json();
    })
    .then(function (state) {
      if (!state.loggedIn) return loadDetails();
      storeState = state;
      serverMode = true;
      return storeRequest('sync', saved.map(function (s) { return s.id; })).then(function (data) {
        saved = data.ids.map(function (id) { return { id: id }; });
        localStorage.removeItem(KEY);
        if (data.skipped) {
          note(data.skipped + ' older browser-saved ' + (data.skipped === 1 ? 'video was' : 'videos were') +
            ' not imported because your account Watch Later limit is ' + state.limit + '.');
        }
        return loadDetails();
      });
    })
    .catch(function (e) {
      note(e.message + ' Showing this browser’s saved list instead.');
      serverMode = false;
      return loadDetails();
    });
})();
</script>
<style>.wl-side.has-cover::before { background-image: var(--wl-bg); }</style>
<?php vs_page_end(); ?>