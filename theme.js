(function () {
  var storageKey = 'vs-theme';
  // Browser-bar colour per theme (dark uses the brand red, light is white).
  var barColors = { dark: '#b02a2a', light: '#ffffff' };
  var defaultThemeMeta = document.querySelector('meta[name="vs-default-theme"]');
  var configured = defaultThemeMeta ? defaultThemeMeta.content : 'dark';
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: light)') : null;
  var hasSaved = false;
  var theme;

  // meta value "light" / "dark" = fixed default; "auto" = follow the device until the visitor picks one.
  function systemTheme() { return media && media.matches ? 'light' : 'dark'; }
  theme = configured === 'light' ? 'light' : configured === 'auto' ? systemTheme() : 'dark';

  try {
    var saved = window.localStorage.getItem(storageKey);
    if (saved === 'light' || saved === 'dark') { theme = saved; hasSaved = true; }
  } catch (error) {
    // Keep the configured default when browser storage is unavailable.
  }

  function applyTheme(nextTheme, savePreference) {
    theme = nextTheme === 'light' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.style.colorScheme = theme;
    var themeColor = document.querySelector('meta[name="theme-color"]');
    if (themeColor) themeColor.setAttribute('content', barColors[theme]);
    if (savePreference) {
      hasSaved = true;
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
      button.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
      var icon = button.querySelector('[data-vs-theme-icon]');
      // Buttons that contain SVG sun/moon icons are switched by theme.css; only plain-text buttons get a glyph.
      if (icon && !icon.querySelector('svg')) icon.textContent = theme === 'dark' ? '\u2600' : '\u263E';
    });

    try {
      document.dispatchEvent(new CustomEvent('vs-theme-change', { detail: { theme: theme } }));
    } catch (error) { /* old browser: no event */ }
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

  // Another tab changed the theme: follow it.
  window.addEventListener('storage', function (event) {
    if (event.key === storageKey && (event.newValue === 'light' || event.newValue === 'dark')) {
      applyTheme(event.newValue, false);
    }
  });
  // "auto" mode: follow the device setting while the visitor has not chosen a theme.
  if (media && configured === 'auto') {
    var onSystem = function () { if (!hasSaved) applyTheme(systemTheme(), false); };
    if (media.addEventListener) media.addEventListener('change', onSystem);
    else if (media.addListener) media.addListener(onSystem);
  }
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
    if (event.key !== 'Escape') return;
    // Put focus back on the button that opened the menu (keyboard users).
    var open = document.querySelector('[data-vs-user-toggle][aria-expanded="true"]');
    closeAll(null);
    if (open) open.focus();
  });
  // Choosing an item closes the menu.
  document.addEventListener('click', function (event) {
    if (event.target.closest('.vs-user-dropdown a')) closeAll(null);
  });
})();