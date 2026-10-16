/*
 * VibeStream - website sidebar custom icons (complete file).
 * Reads window.VS_SIDEBAR_ICONS (printed by header.php) and swaps the icon inside each
 * .sidebar-link, matched by the link's label text (Home, Shorts, Music, ...).
 */
(function () {
  'use strict';

  var ICONS = window.VS_SIDEBAR_ICONS || {};
  if (!Object.keys(ICONS).length) { return; }

  // link label (lower case) -> key used on the Icons page
  var LABELS = {
    'home': 'home',
    'shorts': 'shorts',
    'watch': 'watch',
    'your channel': 'profile',
    'history': 'history',
    'liked videos': 'liked',
    'all subscriptions': 'subscriptions',
    'upload video': 'upload',
    'premium': 'premium',
    'music': 'music',
    'admin panel': 'adminpanel',
    'premium members': 'adminpremium',
    'reported content': 'adminreports',
    'video categories': 'adminvidcats'
  };

  var applying = false;

  function labelOf(link) {
    return (link.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function buildIcon(entry, size) {
    var url = entry.url;
    if (entry.mode === 'color') {
      var img = document.createElement('img');
      img.src = url;
      img.alt = '';
      img.width = size;
      img.height = size;
      img.style.cssText = 'display:block;object-fit:contain;flex:0 0 auto;';
      img.setAttribute('data-vs-custom-icon', '1');
      return img;
    }
    var span = document.createElement('span');
    var mask = "url('" + url + "') center/contain no-repeat";
    span.setAttribute('aria-hidden', 'true');
    span.setAttribute('data-vs-custom-icon', '1');
    span.style.cssText = 'display:block;flex:0 0 auto;width:' + size + 'px;height:' + size + 'px;' +
      'background-color:currentColor;-webkit-mask:' + mask + ';mask:' + mask + ';';
    return span;
  }

  function findIcon(link) {
    return link.querySelector('[data-vs-custom-icon]') ||
           link.querySelector('svg, img, i') ||
           link.querySelector('[class*="icon"]');
  }

  function apply() {
    if (applying) { return; }
    applying = true;
    try {
      document.querySelectorAll('.sidebar-link').forEach(function (link) {
        var key = LABELS[labelOf(link)];
        if (!key || !ICONS[key]) { return; }
        if (link.getAttribute('data-vs-icon-done') === ICONS[key].url) { return; }
        var old = findIcon(link);
        if (!old) { return; }
        var box = old.getBoundingClientRect();
        var size = Math.round(Math.max(box.width, box.height)) || 24;
        old.replaceWith(buildIcon(ICONS[key], size));
        link.setAttribute('data-vs-icon-done', ICONS[key].url);
      });
    } finally {
      applying = false;
    }
  }

  function start() {
    apply();
    var timer = null;
    new MutationObserver(function () {
      if (applying) { return; }
      clearTimeout(timer);
      timer = setTimeout(apply, 50);
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();