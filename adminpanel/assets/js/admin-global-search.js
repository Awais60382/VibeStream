(function () {
  'use strict';

  var openButton = document.querySelector('[data-admin-search-open]');
  var dialog = document.getElementById('adminSearchDialog');
  var input = document.querySelector('[data-admin-search-input]');
  var results = document.querySelector('[data-admin-search-results]');
  var closeButton = document.querySelector('[data-admin-search-close]');
  if (!openButton || !dialog || !input || !results) { return; }

  var closeOnRestore = null;
  var timer = null;
  var request = null;
  var activeIndex = -1;
  var sequence = 0;
  var pageLinks = [];

  function collectPages() {
    pageLinks = [];
    document.querySelectorAll('.admin-sidebar a.nav-link[href]').forEach(function (link) {
      var label = (link.querySelector('.nav-text') || link).textContent.trim();
      var href = link.getAttribute('href') || '';
      if (label && href && href.charAt(0) !== '#' && !/^javascript:/i.test(href)) {
        pageLinks.push({ title: label, subtitle: 'Admin page', href: href, icon: 'bi-grid' });
      }
    });
  }

  function show() {
    closeOnRestore = document.activeElement;
    dialog.hidden = false;
    openButton.setAttribute('aria-expanded', 'true');
    input.value = '';
    render([], []);
    input.focus();
    document.body.classList.add('admin-search-open');
  }

  function hide() {
    dialog.hidden = true;
    openButton.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('admin-search-open');
    if (request) { request.abort(); request = null; }
    if (closeOnRestore && typeof closeOnRestore.focus === 'function') { closeOnRestore.focus(); }
  }

  function makeResult(item) {
    var link = document.createElement('a');
    link.className = 'admin-search-result';
    link.href = item.href;
    link.setAttribute('role', 'option');
    link.setAttribute('aria-selected', 'false');
    link.dataset.adminSearchResult = '1';

    var icon = document.createElement('span');
    icon.className = 'admin-search-result-icon';
    var glyph = document.createElement('i');
    glyph.className = item.icon ? 'bi ' + item.icon : 'bi bi-search';
    glyph.setAttribute('aria-hidden', 'true');
    icon.appendChild(glyph);

    var copy = document.createElement('span');
    copy.className = 'admin-search-result-copy';
    var title = document.createElement('span');
    title.className = 'admin-search-result-title';
    title.textContent = item.title || 'Result';
    copy.appendChild(title);
    if (item.subtitle) {
      var subtitle = document.createElement('span');
      subtitle.className = 'admin-search-result-subtitle';
      subtitle.textContent = item.subtitle;
      copy.appendChild(subtitle);
    }

    link.appendChild(icon);
    link.appendChild(copy);
    return link;
  }

  function render(groups, quickPages) {
    results.textContent = '';
    activeIndex = -1;
    var count = 0;

    groups.forEach(function (group) {
      if (!group.items.length) { return; }
      var heading = document.createElement('div');
      heading.className = 'admin-search-group-title';
      heading.textContent = group.title;
      results.appendChild(heading);
      group.items.forEach(function (item) {
        results.appendChild(makeResult(item));
        count++;
      });
    });

    if (quickPages && quickPages.length) {
      var heading = document.createElement('div');
      heading.className = 'admin-search-group-title';
      heading.textContent = 'Quick links';
      results.appendChild(heading);
      quickPages.slice(0, 6).forEach(function (item) {
        results.appendChild(makeResult(item));
        count++;
      });
    }

    if (!count) {
      var hint = document.createElement('p');
      hint.className = 'admin-search-hint';
      hint.textContent = input.value.trim() ? 'No matching results found.' : 'Start typing to search this admin panel.';
      results.appendChild(hint);
    }
  }

  function matchingPages(query) {
    var q = query.toLowerCase();
    return pageLinks.filter(function (item) {
      return item.title.toLowerCase().indexOf(q) !== -1;
    }).slice(0, 5);
  }

  function search(query) {
    var trimmed = query.trim();
    var pages = trimmed ? matchingPages(trimmed) : [];
    if (request) { request.abort(); request = null; }
    var thisSearch = ++sequence;
    if (trimmed.length < 2) {
      render([], pages);
      return;
    }

    render([{ title: 'Pages', items: pages }], []);
    var controller = window.AbortController ? new AbortController() : null;
    request = controller;
    fetch('admin-search.php?q=' + encodeURIComponent(trimmed), {
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      if (!response.ok) { throw new Error('Search failed with status ' + response.status); }
      return response.json();
    }).then(function (data) {
      if (thisSearch !== sequence || dialog.hidden) { return; }
      if (!data || data.ok !== true) { throw new Error((data && data.error) || 'Search response was invalid.'); }
      render([
        { title: 'Pages', items: pages },
        { title: 'Videos & shorts', items: data.videos || [] },
        { title: 'Users', items: data.users || [] },
        { title: 'Messages', items: data.messages || [] }
      ], []);
    }).catch(function (error) {
      if (error.name === 'AbortError' || thisSearch !== sequence) { return; }
      console.error('Admin global search failed.', error);
      render([{ title: 'Pages', items: pages }], []);
      if (!pages.length) {
        results.textContent = '';
        var failure = document.createElement('p');
        failure.className = 'admin-search-hint';
        failure.textContent = 'Search is temporarily unavailable. Try again.';
        results.appendChild(failure);
      }
    });
  }

  function activateResult(index) {
    var items = results.querySelectorAll('[data-admin-search-result]');
    if (!items.length) { return; }
    if (index < 0) { index = items.length - 1; }
    if (index >= items.length) { index = 0; }
    Array.prototype.forEach.call(items, function (item) {
      item.classList.remove('is-active');
      item.setAttribute('aria-selected', 'false');
    });
    activeIndex = index;
    items[activeIndex].classList.add('is-active');
    items[activeIndex].setAttribute('aria-selected', 'true');
    items[activeIndex].scrollIntoView({ block: 'nearest' });
  }

  openButton.addEventListener('click', show);
  if (closeButton) { closeButton.addEventListener('click', hide); }
  dialog.addEventListener('mousedown', function (event) {
    if (event.target === dialog) { hide(); }
  });
  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () { search(input.value); }, 140);
  });
  input.addEventListener('keydown', function (event) {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      activateResult(activeIndex + 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      activateResult(activeIndex < 0 ? -1 : activeIndex - 1);
    } else if (event.key === 'Enter') {
      var items = results.querySelectorAll('[data-admin-search-result]');
      if (activeIndex >= 0 && items[activeIndex]) {
        event.preventDefault();
        window.location.href = items[activeIndex].href;
      }
    } else if (event.key === 'Escape') {
      event.preventDefault();
      hide();
    }
  });
  document.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (dialog.hidden) { show(); } else { input.focus(); }
    } else if (event.key === 'Escape' && !dialog.hidden) {
      hide();
    }
  });

  collectPages();
})();
