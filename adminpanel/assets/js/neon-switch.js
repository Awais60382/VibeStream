/*
 * VibeStream - ONE neon on/off switch for every page (admin panel + public site).
 * Put this file in the MAIN folder (next to index.php). Admin pages load it as ../neon-switch.js
 *
 * - The choice is saved in this browser (localStorage key "vs-neon": "off" or "on") and shared by the
 *   whole site, so one click turns neon off everywhere.
 * - When neon is OFF:  <html> gets the class "vs-neon-off", and every <link>/<style> marked data-vs-neon
 *   (or one of the known neon ids) is switched off. neon-off.css then removes the leftover glows.
 * - A lightning button is wired automatically: any button with data-vs-neon-toggle. If a page has no such
 *   button, one is added next to the light/dark theme button (or floats bottom-right as a last resort).
 */
(function () {
  'use strict';
  var KEY = 'vs-neon';
  var root = document.documentElement;
  var SEL = [
    '[data-vs-neon]',
    '#vs-neon-css-1',
    '#vs-neon-css-2',
    '#neon-dark',
    '#neon-light',
    'link[href*="/assets/css/neon-theme.css"]',
    'link[href*="/assets/css/vs-dashboard-theme.css"]',
    'link[href*="/assets/css/vs-admin-pages-neon.css"]',
    'link[href*="/assets/css/sidebar-neon.css"]'
  ].join(',');
  var off = false;
  try { off = localStorage.getItem(KEY) === 'off'; } catch (e) {}

  function flip(el, isOff) {
    if (isOff) {
      if (el.getAttribute('data-vs-media') === null) { el.setAttribute('data-vs-media', el.getAttribute('media') || ''); }
      el.setAttribute('media', 'not all');
    } else if (el.getAttribute('data-vs-media') !== null) {
      var m = el.getAttribute('data-vs-media');
      if (m) { el.setAttribute('media', m); } else { el.removeAttribute('media'); }
    }
  }

  function buttons() { return document.querySelectorAll('[data-vs-neon-toggle]'); }

  function paintButtons() {
    var label = off ? 'Turn neon on' : 'Turn neon off';
    Array.prototype.forEach.call(buttons(), function (b) {
      b.setAttribute('aria-pressed', off ? 'false' : 'true');
      b.setAttribute('aria-label', label);
      b.title = label;
      var labelNode = b.querySelector('[data-vs-neon-label]');
      if (labelNode) { labelNode.textContent = label; }
      var i = b.querySelector('i.bi');
      if (i) { i.className = off ? 'bi bi-lightning-charge' : 'bi bi-lightning-charge-fill'; }
    });
  }

  function apply(isOff) {
    off = !!isOff;
    root.classList.toggle('vs-neon-off', off);
    Array.prototype.forEach.call(document.querySelectorAll(SEL), function (el) { flip(el, off); });
    if (document.body && document.body.hasAttribute('data-vs-neon-body')) {
      document.body.classList.toggle('vs-neon', !off);
    }
    paintButtons();
    try { document.dispatchEvent(new CustomEvent('vs-neon-change', { detail: { off: off } })); } catch (e) {}
  }
  window.vsNeonApply = apply;                                   // old name, still works
  window.vsNeon = { isOff: function () { return off; }, set: function (v) { save(!v); apply(!v); } };

  function save(isOff) { try { localStorage.setItem(KEY, isOff ? 'off' : 'on'); } catch (e) {} }

  // Run right now (no flash), and switch off style elements that are added later while the page loads.
  root.classList.toggle('vs-neon-off', off);
  apply(off);
  if (window.MutationObserver) {
    var mo = new MutationObserver(function (list) {
      if (!off) { return; }
      list.forEach(function (m) {
        Array.prototype.forEach.call(m.addedNodes, function (n) {
          if (n.nodeType !== 1) { return; }
          if (n.matches && n.matches(SEL)) { flip(n, true); }
          if (n.querySelectorAll) { Array.prototype.forEach.call(n.querySelectorAll(SEL), function (el) { flip(el, true); }); }
        });
      });
    });
    mo.observe(root, { childList: true, subtree: true });
    window.addEventListener('load', function () { setTimeout(function () { mo.disconnect(); }, 1500); });
  }

  var BOLT = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M14 1 2 14h8l-1 9 13-15h-8z"/></svg>';

  function css() {
    if (document.getElementById('vs-neon-btn-css')) { return; }
    var s = document.createElement('style');
    s.id = 'vs-neon-btn-css';
    s.textContent =
      '.vs-neon-toggle{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;padding:0;border:0;border-radius:50%;background:transparent;color:inherit;cursor:pointer;flex:0 0 auto}' +
      '.vs-neon-toggle:hover{background:rgba(138,92,255,.18)}' +
      '.vs-neon-toggle[aria-pressed="true"]{color:#00e5ff}' +
      '.vs-neon-toggle[aria-pressed="true"] svg,.vs-neon-toggle[aria-pressed="true"] i{filter:drop-shadow(0 0 6px #00e5ff)}' +
      '.vs-neon-toggle[aria-pressed="false"]{opacity:.65}' +
      '.vs-neon-toggle[aria-pressed="false"] svg{opacity:.7}' +
      '.vs-neon-toggle svg{display:block;width:24px!important;height:24px!important;flex:0 0 24px}' +
      '.vs-neon-toggle.vs-neon-float{position:fixed;right:14px;bottom:14px;z-index:2147483000;background:rgba(10,13,31,.9);border:1px solid rgba(138,92,255,.6)}' +
      'html.vs-neon-off .vs-neon-toggle,html.vs-neon-off .vs-neon-toggle:hover{box-shadow:none}';
    document.head.appendChild(s);
  }

  function ready() {
    if (document.body && document.body.classList.contains('vs-neon')) { document.body.setAttribute('data-vs-neon-body', '1'); }
    css();
    if (!buttons().length) {
      var anchor = document.querySelector('[data-vs-theme-toggle],[data-theme-toggle],.theme-toggle,.vs-theme-toggle');
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'vs-neon-toggle' + (anchor ? '' : ' vs-neon-float');
      b.setAttribute('data-vs-neon-toggle', '');
      b.innerHTML = BOLT;
      if (anchor && anchor.parentNode) { anchor.parentNode.insertBefore(b, anchor); } else { document.body.appendChild(b); }
    }
    // Buttons that came with an icon-less body get the bolt icon.
    Array.prototype.forEach.call(buttons(), function (b) {
      if (!b.firstElementChild && !b.textContent.trim()) { b.innerHTML = BOLT; }
      if (!b.__vsNeonBound) {
        b.__vsNeonBound = true;
        b.addEventListener('click', function () { save(!off); apply(!off); });
      }
    });
    apply(off);
  }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', ready); } else { ready(); }

  // Keep other open tabs in step.
  window.addEventListener('storage', function (e) { if (e.key === KEY) { apply(e.newValue === 'off'); } });
})();
