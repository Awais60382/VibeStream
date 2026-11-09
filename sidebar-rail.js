/*
 * VibeStream - slim icon bar for a closed menu (desktop only). Works with sidebar-rail.css.
 * Load it in the <head> of each page (it sets the saved state before the page is drawn).
 */
(function () {
  'use strict';

  var root = document.documentElement;
  var KEY = 'vs-rail';
  var WIDE = 1000; // wider than this = desktop menu, narrower = slide-in menu

  // The five icons that stay when the menu is closed: file name -> short label.
  var KEEP = {
    'index.php': 'Home',
    'shorts.php': 'Shorts',
    'subscriptions.php': 'Subscriptions',
    'profile.php': 'You',
    'premium.php': 'Premium'
  };

  function saved() {
    try { return window.localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
  }
  function save(on) {
    try { window.localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
  }

  if (saved()) root.classList.add('vs-rail');

  function syncToggle() {
    var btn = document.getElementById('navToggle');
    if (btn && window.innerWidth > WIDE) {
      btn.setAttribute('aria-expanded', root.classList.contains('vs-rail') ? 'false' : 'true');
    }
  }

  function setRail(on, remember) {
    root.classList.toggle('vs-rail', on);
    if (remember) save(on);
    syncToggle();
  }

  function mark() {
    var sidebar = document.querySelector('.app-sidebar');
    if (!sidebar) return;
    try {
      if (window.getComputedStyle(sidebar).position === 'fixed') root.classList.add('vs-rail-fixed');
    } catch (e) {}

    var links = sidebar.querySelectorAll('a.sidebar-link');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var keep = false;
      var label = '';
      try {
        var u = new URL(a.getAttribute('href') || '', window.location.href);
        if (u.origin === window.location.origin && !u.search) {
          var parts = u.pathname.split('/');
          var file = parts.pop() || 'index.php';
          var dir = (parts.pop() || '').toLowerCase();
          if (Object.prototype.hasOwnProperty.call(KEEP, file) && dir !== 'adminpanel') {
            keep = true;
            label = KEEP[file];
          }
        }
      } catch (e) {}
      a.classList.toggle('vs-rail-keep', keep);
      a.classList.toggle('vs-rail-hide', !keep);
      if (keep) {
        a.setAttribute('data-rail-label', label);
        if (!a.getAttribute('title')) a.setAttribute('title', label);
      }
    }

    // sections that have no kept icon left are hidden completely
    var blocks = sidebar.querySelectorAll('.sidebar-block, .sidebar-section');
    for (var j = 0; j < blocks.length; j++) {
      blocks[j].classList.toggle('vs-rail-empty', !blocks[j].querySelector('a.sidebar-link.vs-rail-keep'));
    }
  }

  // On desktop the menu button also opens and closes the icon bar. This never blocks the page's own
  // menu code: it only adds to it, and only on pages that really have the sidebar.
  document.addEventListener('click', function (event) {
    var btn = event.target && event.target.closest ? event.target.closest('#navToggle') : null;
    if (!btn || window.innerWidth <= WIDE) return;
    var sidebar = document.querySelector('.app-sidebar');
    if (!sidebar || sidebar.offsetWidth === 0) return;
    setRail(!root.classList.contains('vs-rail'), true);
  });

  window.addEventListener('storage', function (event) {
    if (event.key === KEY) setRail(event.newValue === '1', false);
  });
  window.addEventListener('resize', syncToggle);

  function start() {
    mark();
    syncToggle();
    // the sidebar can be rebuilt by other scripts (icons, subscriptions): keep the marks fresh
    var timer = null;
    var sidebar = document.querySelector('.app-sidebar');
    if (sidebar && window.MutationObserver) {
      new MutationObserver(function () {
        clearTimeout(timer);
        timer = setTimeout(mark, 60);
      }).observe(sidebar, { childList: true, subtree: true });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();