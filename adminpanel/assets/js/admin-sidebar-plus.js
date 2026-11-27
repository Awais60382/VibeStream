/*
 * VibeStream admin sidebar - extras:
 *  - "Search menu" box (press / to focus, Enter opens the first match, Esc clears)
 *  - sections (MAIN, MY WORK, USERS, ...) fold up; the choice is remembered
 *  - a red dot on a folded section that holds an unread badge
 *  - the menu scrolls so the current page is in view
 * It only reads the sidebar that header.php already prints, so new pages show up by themselves.
 */
(function () {
  'use strict';
  var sidebar = document.getElementById('adminSidebar');
  var nav = sidebar && sidebar.querySelector('.sidebar-nav');
  if (!nav || nav.getAttribute('data-vs-sbp')) { return; }
  nav.setAttribute('data-vs-sbp', '1');

  var KEY = 'vs-admin-sb-collapsed';
  var saved = {};
  var firstVisit = true;
  try {
    var raw = localStorage.getItem(KEY);
    firstVisit = raw === null;
    saved = JSON.parse(raw || '{}') || {};
  } catch (e) { saved = {}; }
  // first time on a short window: fold the sections you are not in, so the menu is not a long scroll
  var foldByDefault = firstVisit && window.innerHeight < 800;
  function persist() { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) {} }

  var filtering = false;
  var narrow = false;

  /* ---------- sections ---------- */
  var groups = [];
  var kids = Array.prototype.slice.call(nav.children);
  var current = null;
  kids.forEach(function (el) {
    if (el.classList.contains('sidebar-section-title')) {
      current = { title: el, els: [], id: (el.textContent || '').trim().toLowerCase() };
      groups.push(current);
    } else if (current) {
      current.els.push(el);
    }
  });
  groups.forEach(function (g) {
    g.hasActive = g.els.some(function (el) {
      return (el.matches && el.matches('.active, [aria-current]')) || !!el.querySelector('.active, [aria-current]');
    });
    g.hasBadge = g.els.some(function (el) { return !!el.querySelector('.badge, .vs-alert-count'); });
    if (foldByDefault && !g.hasActive) { saved[g.id] = 1; }
    g.collapsed = !!saved[g.id] && !g.hasActive;           // the section you are in always stays open
    g.title.classList.add('vs-sbp-title');
    g.title.setAttribute('role', 'button');
    g.title.setAttribute('tabindex', '0');
    function toggle() {
      g.collapsed = !g.collapsed;
      if (g.collapsed) { saved[g.id] = 1; } else { delete saved[g.id]; }
      persist();
      paint();
    }
    g.title.addEventListener('click', toggle);
    g.title.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); }
    });
  });

  if (foldByDefault) { persist(); }

  function paint() {
    groups.forEach(function (g) {
      var folded = g.collapsed && !filtering && !narrow;
      g.els.forEach(function (el) { el.classList.toggle('vs-sbp-hidden', folded); });
      g.title.classList.toggle('is-collapsed', folded);
      g.title.classList.toggle('has-alert', folded && g.hasBadge);
      g.title.setAttribute('aria-expanded', folded ? 'false' : 'true');
    });
  }

  /* ---------- menu search ---------- */
  var box = document.createElement('div');
  box.className = 'vs-sbp-filter';
  box.innerHTML =
    '<div class="vs-sbp-field">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' +
      '<input class="vs-sbp-input" type="text" placeholder="Search menu" aria-label="Search the admin menu" autocomplete="off">' +
      '<span class="vs-sbp-key" aria-hidden="true">/</span>' +
    '</div>' +
    '<button type="button" class="vs-sbp-all" title="Fold / unfold all sections" aria-label="Fold or unfold all sections">' +
      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m7 9 5-5 5 5M7 15l5 5 5-5"/></svg>' +
    '</button>';
  nav.parentNode.insertBefore(box, nav);
  var empty = document.createElement('div');
  empty.className = 'vs-sbp-empty';
  empty.textContent = 'No page matches that.';
  nav.parentNode.insertBefore(empty, nav);

  var input = box.querySelector('.vs-sbp-input');
  var allBtn = box.querySelector('.vs-sbp-all');
  var links = Array.prototype.slice.call(nav.querySelectorAll('.nav-link'));

  function linkText(a) {
    var t = a.querySelector('.nav-text');
    return ((t ? t.textContent : a.textContent) || '').toLowerCase();
  }

  function applyFilter() {
    var q = input.value.trim().toLowerCase();
    filtering = q !== '';
    var shown = 0;
    links.forEach(function (a) {
      var hit = !filtering || linkText(a).indexOf(q) !== -1;
      a.classList.toggle('vs-sbp-nomatch', !hit);
      if (hit) { shown++; }
    });
    groups.forEach(function (g) {
      var any = !filtering || g.els.some(function (el) {
        var ls = el.matches && el.matches('.nav-link') ? [el] : Array.prototype.slice.call(el.querySelectorAll('.nav-link'));
        return ls.some(function (a) { return !a.classList.contains('vs-sbp-nomatch'); });
      });
      g.title.classList.toggle('vs-sbp-nomatch', !any);
    });
    empty.classList.toggle('is-on', filtering && shown === 0);
    paint();
  }

  function firstMatch() {
    for (var i = 0; i < links.length; i++) {
      if (!links[i].classList.contains('vs-sbp-nomatch') && !links[i].classList.contains('vs-sbp-hidden')) { return links[i]; }
    }
    return null;
  }

  input.addEventListener('input', applyFilter);
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { input.value = ''; applyFilter(); input.blur(); }
    else if (e.key === 'Enter') {
      var a = firstMatch();
      if (a && a.href) { window.location.href = a.href; }
    } else if (e.key === 'ArrowDown') {
      var b = firstMatch();
      if (b) { e.preventDefault(); b.focus(); }
    }
  });

  allBtn.addEventListener('click', function () {
    var anyOpen = groups.some(function (g) { return !g.collapsed; });
    groups.forEach(function (g) {
      g.collapsed = anyOpen && !g.hasActive;               // never fold the section you are in
      if (g.collapsed) { saved[g.id] = 1; } else { delete saved[g.id]; }
    });
    persist();
    paint();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
    var t = e.target, tag = t && t.tagName ? t.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) { return; }
    if (narrow || !sidebar.offsetWidth || !box.offsetHeight) { return; }
    e.preventDefault();
    input.focus();
    input.select();
  });

  /* ---------- icon-bar mode: leave everything open and hide the extras ---------- */
  function checkNarrow() {
    var n = sidebar.offsetWidth > 0 && sidebar.offsetWidth < 150;
    if (n !== narrow) {
      narrow = n;
      sidebar.classList.toggle('vs-sbp-narrow', narrow);
      paint();
    }
  }
  if (window.ResizeObserver) { new ResizeObserver(checkNarrow).observe(sidebar); }
  window.addEventListener('resize', checkNarrow);

  /* ---------- keep the current page in view ---------- */
  function revealActive() {
    var a = nav.querySelector('.nav-link.active');
    if (!a) { return; }
    // on short windows the whole sidebar scrolls instead of just the menu
    var box = (nav.scrollHeight > nav.clientHeight + 2) ? nav : sidebar;
    if (!box.clientHeight) { return; }
    var r = a.getBoundingClientRect(), br = box.getBoundingClientRect();
    box.scrollTop += (r.top - br.top) - br.height / 2 + r.height / 2;
  }

  paint();
  checkNarrow();
  revealActive();
})();