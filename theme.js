(function () {
  var storageKey = 'vs-theme';
  var defaultThemeMeta = document.querySelector('meta[name="vs-default-theme"]');
  var theme = defaultThemeMeta && defaultThemeMeta.content === 'light' ? 'light' : 'dark';

  try {
    var saved = window.localStorage.getItem(storageKey);
    if (saved === 'light' || saved === 'dark') theme = saved;
  } catch (error) {
    // Keep the configured default when browser storage is unavailable.
  }

  function applyTheme(nextTheme, savePreference) {
    theme = nextTheme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    var themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.setAttribute('content', theme === 'dark' ? '#b02a2a' : '#ffffff');
    if (savePreference) {
      try {
        window.localStorage.setItem(storageKey, theme);
      } catch (error) {
        // The current page still changes theme if storage is unavailable.
      }
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-vs-theme-toggle]'), function (button) {
      var next = theme === 'dark' ? 'light' : 'dark';
      var label = 'Switch to ' + next + ' mode';
      button.setAttribute('aria-label', label);
      button.setAttribute('title', label);
      var icon = button.querySelector('[data-vs-theme-icon]');
      if (icon) icon.textContent = theme === 'dark' ? '☀' : '☾';
    });
  }

  applyTheme(theme, false);
  document.addEventListener('DOMContentLoaded', function () {
    applyTheme(theme, false);
  });
  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-vs-theme-toggle]');
    if (!button) return;
    applyTheme(theme === 'dark' ? 'light' : 'dark', true);
  });
})();


/* Signed-in user dropdown (avatar + name) */
(function () {
  function closeAll(except) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-vs-user-menu]'), function (wrap) {
      if (wrap === except) return;
      var menu = wrap.querySelector('.vs-user-dropdown');
      var toggle = wrap.querySelector('[data-vs-user-toggle]');
      if (menu) menu.hidden = true;
      if (toggle) toggle.setAttribute('aria-expanded', 'false');
    });
  }
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-vs-user-toggle]');
    if (!toggle) {
      if (!event.target.closest('.vs-user-dropdown')) closeAll(null);
      return;
    }
    var wrap = toggle.closest('[data-vs-user-menu]');
    var menu = wrap.querySelector('.vs-user-dropdown');
    var open = menu.hidden;
    closeAll(wrap);
    menu.hidden = !open;
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeAll(null);
  });
})();