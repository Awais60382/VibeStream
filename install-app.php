<?php
/**
 * VibeStream - "Install app" button.
 *
 * Installs the site as an app (its own window, desktop / home-screen icon) using the
 * browser's built-in install feature. Shown in the top bar next to the bell.
 * - Chrome / Edge / Android: one click opens the browser's install dialog.
 * - iPhone / iPad / other browsers: shows a short "how to install" box instead.
 * - Hidden automatically once the app is installed (or when you are already inside the app).
 */
if (!function_exists('vs_install_app_html')) {
    function vs_install_app_html(): string
    {
        static $scriptDone = false;

        $appName = function_exists('vs_branding_app_name') ? vs_branding_app_name() : 'VibeStream';
        $e = function ($v) {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };

        $html = '<button type="button" class="vs-install-btn" data-vs-install data-app-name="' . $e($appName) . '"'
            . ' title="Install ' . $e($appName) . ' app" aria-label="Install ' . $e($appName) . ' app">'
            . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>'
            . '<span class="vs-install-label">Install app</span>'
            . '</button>';

        if ($scriptDone) {
            return $html;
        }
        $scriptDone = true;

        $html .= '<style>' . <<<'CSS'
.nav-right .vs-install-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    height: 40px; min-width: 40px; padding: 0 14px; border-radius: 999px; cursor: pointer;
    background: var(--surface-2, #272727); color: var(--text, #f1f1f1);
    border: 1px solid var(--border, #303030); font-size: 14px; font-weight: 600; white-space: nowrap;
}
.nav-right .vs-install-btn:hover,
.nav-right .vs-install-btn:focus { background: var(--surface-hover, #2a2a31); border-color: #5b8cff; color: var(--text, #f1f1f1); }
.nav-right .vs-install-btn[hidden] { display: none; }
.vs-install-btn svg { width: 20px; height: 20px; flex: 0 0 20px; display: block; }
@media (max-width: 1100px) {
    .nav-right .vs-install-btn { padding: 0; width: 40px; }
    .vs-install-label { display: none; }
}
.vs-install-modal { position: fixed; inset: 0; z-index: 3000; display: grid; place-items: center; padding: 16px; background: rgba(0,0,0,.6); }
.vs-install-box {
    width: 100%; max-width: 420px; padding: 22px; border-radius: 16px; text-align: left;
    background: var(--surface-raised, var(--surface, #1f1f1f)); color: var(--text, #f1f1f1);
    border: 1px solid var(--border, #303030); box-shadow: 0 20px 50px rgba(0,0,0,.45);
}
:root:not([data-theme="light"]) .vs-install-box { background: #282828; }
.vs-install-box h3 { margin: 0 0 8px; font-size: 18px; font-weight: 600; }
.vs-install-box p { margin: 0 0 12px; font-size: 14px; line-height: 1.5; color: var(--muted, #aaaaaa); }
.vs-install-box ol { margin: 0 0 16px; padding-left: 20px; font-size: 14px; line-height: 1.7; }
.vs-install-box .vs-install-close {
    display: block; margin-left: auto; padding: 9px 20px; border: 0; border-radius: 999px; cursor: pointer;
    background: var(--accent, #b02a2a); color: #fff; font-weight: 700; font-size: 14px;
}
CSS
        . '</style>';

        $html .= <<<'JS'
<script>
(function () {
  if (window.__vsInstallInit) return;
  window.__vsInstallInit = true;

  var deferred = null;

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  }
  function setHidden(hide) {
    document.querySelectorAll('[data-vs-install]').forEach(function (b) { b.hidden = hide; });
  }
  if (isStandalone()) setHidden(true);

  // make sure the offline helper (service worker) is registered on every page, the browser needs it to offer the install
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () {});
    });
  }

  window.addEventListener('beforeinstallprompt', function (event) {
    event.preventDefault();
    deferred = event;
  });
  window.addEventListener('appinstalled', function () {
    deferred = null;
    setHidden(true);
    if (typeof toast === 'function') toast('App installed');
  });

  function steps(appName) {
    var ua = navigator.userAgent || '';
    var ios = /iPhone|iPad|iPod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    if (ios) {
      return { text: 'On iPhone and iPad, installing is done from Safari:', list: [
        'Open this site in <b>Safari</b>.',
        'Tap the <b>Share</b> button.',
        'Choose <b>Add to Home Screen</b>, then tap <b>Add</b>.'
      ] };
    }
    if (/Firefox/i.test(ua)) {
      return { text: 'Firefox on desktop cannot install web apps. Open ' + appName + ' in Chrome or Edge to install it.', list: [] };
    }
    return { text: 'Your browser did not open the install box by itself. You can still install ' + appName + ' from the browser menu:', list: [
      'Open the browser menu (the <b>&#8942;</b> or <b>&hellip;</b> button).',
      'Choose <b>Install ' + appName + '</b> (or <b>Install app</b> / <b>Add to Home screen</b>).',
      'If you do not see it, refresh this page once and try again.'
    ] };
  }

  function esc(v) {
    return String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function showHelp(rawName) {
    var appName = esc(rawName);
    var info = steps(appName);
    var wrap = document.createElement('div');
    wrap.className = 'vs-install-modal';
    wrap.setAttribute('role', 'dialog');
    wrap.setAttribute('aria-modal', 'true');
    var items = info.list.map(function (li) { return '<li>' + li + '</li>'; }).join('');
    wrap.innerHTML = '<div class="vs-install-box"><h3>Install ' + appName + '</h3><p>' + info.text + '</p>' +
      (items ? '<ol>' + items + '</ol>' : '') +
      '<button type="button" class="vs-install-close">Got it</button></div>';
    function close() { if (wrap.parentNode) wrap.parentNode.removeChild(wrap); document.removeEventListener('keydown', onKey); }
    function onKey(e) { if (e.key === 'Escape') close(); }
    wrap.addEventListener('click', function (e) {
      if (e.target === wrap || (e.target.closest && e.target.closest('.vs-install-close'))) close();
    });
    document.addEventListener('keydown', onKey);
    document.body.appendChild(wrap);
  }

  document.addEventListener('click', function (event) {
    var btn = event.target.closest ? event.target.closest('[data-vs-install]') : null;
    if (!btn) return;
    event.preventDefault();
    var appName = btn.getAttribute('data-app-name') || 'this app';
    if (deferred) {
      var promptEvent = deferred;
      deferred = null;
      promptEvent.prompt();
      promptEvent.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') setHidden(true);
      }).catch(function () {});
      return;
    }
    showHelp(appName);
  });
})();
</script>
JS;

        return $html;
    }
}
