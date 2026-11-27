(function () {
  'use strict';

  var root = document.documentElement;
  var loader = document.getElementById('vsPageLoader');

  function show() {
    root.classList.add('vs-page-loading');
    if (loader) {
      loader.setAttribute('aria-hidden', 'false');
    }
  }

  function hide() {
    root.classList.remove('vs-page-loading');
    if (loader) {
      loader.setAttribute('aria-hidden', 'true');
    }
  }

  window.addEventListener('beforeunload', show);
  window.addEventListener('load', hide, { once: true });
  window.addEventListener('pageshow', hide);

  if (document.readyState === 'complete') {
    hide();
  }
})();
