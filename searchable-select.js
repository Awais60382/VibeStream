(function () {
  'use strict';

  var sequence = 0;
  var invalidMessage = 'Choose an option from the list.';

  function optionLabel(option) {
    return (option.label || option.textContent || '').trim();
  }

  function getOptions(select) {
    return Array.prototype.slice.call(select.options).filter(function (option) {
      return optionLabel(option) !== '';
    });
  }

  function getAccessibleLabel(select) {
    var label = select.getAttribute('aria-label');
    if (label) return label;

    if (select.labels && select.labels.length) {
      label = Array.prototype.map.call(select.labels, function (item) {
        return item.textContent.trim();
      }).filter(Boolean).join(' ');
      if (label) return label;
    }

    return select.name ? select.name.replace(/[_-]+/g, ' ') : 'select';
  }

  function dispatchChange(select) {
    var event;
    if (typeof Event === 'function') {
      event = new Event('change', { bubbles: true });
    } else {
      event = document.createEvent('HTMLEvents');
      event.initEvent('change', true, false);
    }
    select.dispatchEvent(event);
  }

  function enhance(select) {
    if (select.dataset.vsSearchableSelect === 'ready'
      || select.multiple
      || select.size > 1
      || select.hasAttribute('data-vs-no-search')) {
      return;
    }

    select.dataset.vsSearchableSelect = 'ready';
    sequence += 1;

    var wrapper = document.createElement('div');
    var input = document.createElement('input');
    var list = document.createElement('div');
    var listId = 'vs-select-options-' + sequence;
    var inputId = select.id ? select.id + '-search' : 'vs-select-search-' + sequence;
    var originalRequired = select.required;
    var label = getAccessibleLabel(select);
    var activeIndex = -1;
    var isOpen = false;

    wrapper.className = 'vs-searchable-select';
    wrapper.style.width = select.style.width || window.getComputedStyle(select).width;
    input.type = 'text';
    input.id = inputId;
    input.className = select.className;
    input.classList.add('vs-searchable-select-input');
    input.style.cssText = select.style.cssText;
    input.placeholder = 'Search options...';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-label', 'Search ' + label + ' options');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', listId);
    input.setAttribute('autocomplete', 'off');
    input.setAttribute('autocapitalize', 'off');
    input.spellcheck = false;
    if (select.hasAttribute('form')) input.setAttribute('form', select.getAttribute('form'));
    if (select.hasAttribute('aria-describedby')) {
      input.setAttribute('aria-describedby', select.getAttribute('aria-describedby'));
    }
    if (originalRequired) input.required = true;

    list.id = listId;
    list.className = 'vs-searchable-select-menu';
    list.setAttribute('role', 'listbox');
    list.hidden = true;

    select.parentNode.insertBefore(wrapper, select);
    wrapper.appendChild(input);
    wrapper.appendChild(list);
    wrapper.appendChild(select);
    select.classList.add('vs-searchable-select-native');
    select.hidden = true;
    select.setAttribute('aria-hidden', 'true');
    select.tabIndex = -1;
    select.required = false;

    function setOpen(open) {
      isOpen = open;
      list.hidden = !open;
      input.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (!open) {
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
      }
    }

    function currentOption() {
      return select.options[select.selectedIndex] || null;
    }

    function syncInput() {
      var selected = currentOption();
      input.value = selected ? optionLabel(selected) : '';
      input.disabled = select.disabled;
      input.required = originalRequired;
      input.setCustomValidity('');
      if (isOpen) renderOptions();
    }

    function updateValidity(value) {
      var match = findMatch(value);
      if (value.trim() && !match) {
        input.setCustomValidity(invalidMessage);
      } else if (originalRequired && match && match.value === '') {
        input.setCustomValidity('Choose a non-empty option.');
      } else {
        input.setCustomValidity('');
      }
      return match;
    }

    function findMatch(value) {
      var normalized = value.trim().toLocaleLowerCase();
      if (!normalized) return null;

      var selected = currentOption();
      if (selected && optionLabel(selected).toLocaleLowerCase() === normalized) {
        return selected;
      }

      var options = getOptions(select);
      for (var index = 0; index < options.length; index += 1) {
        if (!options[index].disabled
          && optionLabel(options[index]).toLocaleLowerCase() === normalized) {
          return options[index];
        }
      }
      return null;
    }

    function filteredOptions() {
      var query = input.value.trim().toLocaleLowerCase();
      return getOptions(select).filter(function (option) {
        return optionLabel(option).toLocaleLowerCase().indexOf(query) !== -1;
      });
    }

    function setActive(index, items) {
      var enabled = [];
      items.forEach(function (item, itemIndex) {
        if (!item.disabled) enabled.push(itemIndex);
      });
      Array.prototype.forEach.call(list.children, function (child) {
        child.classList.remove('is-active');
      });
      if (!enabled.length) {
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
        return;
      }

      var position = enabled.indexOf(index);
      if (position === -1) position = 0;
      activeIndex = enabled[position];
      input.setAttribute('aria-activedescendant', listId + '-option-' + activeIndex);

      var activeItem = list.children[activeIndex];
      if (activeItem) {
        activeItem.classList.add('is-active');
        activeItem.scrollIntoView({ block: 'nearest' });
      }
    }

    function choose(option) {
      if (!option || option.disabled) return;

      var changed = select.value !== option.value;
      select.selectedIndex = option.index;
      input.value = optionLabel(option);
      input.setCustomValidity('');
      setOpen(false);
      if (changed) dispatchChange(select);
    }

    function renderOptions() {
      while (list.firstChild) list.removeChild(list.firstChild);
      var items = filteredOptions();

      if (!items.length) {
        var empty = document.createElement('div');
        empty.className = 'vs-searchable-select-empty';
        empty.textContent = 'No matching options';
        empty.setAttribute('role', 'status');
        list.appendChild(empty);
        activeIndex = -1;
        input.removeAttribute('aria-activedescendant');
        return;
      }

      items.forEach(function (option, index) {
        var item = document.createElement('div');
        item.id = listId + '-option-' + index;
        item.className = 'vs-searchable-select-option';
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', option.selected ? 'true' : 'false');
        item.setAttribute('aria-disabled', option.disabled ? 'true' : 'false');
        item.textContent = optionLabel(option);
        if (option.disabled) item.classList.add('is-disabled');
        if (option.selected) item.classList.add('is-selected');
        item.addEventListener('mouseenter', function () {
          if (!option.disabled) setActive(index, items);
        });
        item.addEventListener('mousedown', function (event) {
          event.preventDefault();
        });
        item.addEventListener('click', function () {
          choose(option);
        });
        list.appendChild(item);
      });

      activeIndex = -1;
      input.removeAttribute('aria-activedescendant');
    }

    function commitInput(emitChange) {
      var match = updateValidity(input.value);
      if (input.value.trim() && !match) return false;
      if (originalRequired && (!match || match.value === '')) {
        input.setCustomValidity('Choose a non-empty option.');
        return false;
      }

      var previousValue = select.value;
      select.selectedIndex = match ? match.index : -1;
      if (emitChange && previousValue !== select.value) dispatchChange(select);
      return true;
    }

    input.addEventListener('focus', function () {
      setOpen(true);
      renderOptions();
    });
    input.addEventListener('click', function () {
      if (!isOpen) {
        setOpen(true);
        renderOptions();
      }
    });
    input.addEventListener('input', function () {
      activeIndex = -1;
      input.removeAttribute('aria-activedescendant');
      updateValidity(input.value);
      renderOptions();
      setOpen(true);
    });
    input.addEventListener('keydown', function (event) {
      var items = filteredOptions();
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (!isOpen) {
          setOpen(true);
          renderOptions();
          items = filteredOptions();
        }
        var direction = event.key === 'ArrowDown' ? 1 : -1;
        var start = activeIndex;
        for (var step = 0; step < items.length; step += 1) {
          start = (start + direction + items.length) % items.length;
          if (!items[start].disabled) {
            setActive(start, items);
            break;
          }
        }
      } else if (event.key === 'Enter' && isOpen && activeIndex >= 0) {
        event.preventDefault();
        choose(items[activeIndex]);
      } else if (event.key === 'Escape' && isOpen) {
        event.preventDefault();
        syncInput();
        setOpen(false);
      } else if (event.key === 'Tab') {
        if (!commitInput(true)) {
          event.preventDefault();
          input.reportValidity();
        } else {
          setOpen(false);
        }
      }
    });
    input.addEventListener('change', function () {
      commitInput(true);
      setOpen(false);
    });
    input.addEventListener('blur', function () {
      window.setTimeout(function () {
        if (!wrapper.contains(document.activeElement)) {
          commitInput(true);
          setOpen(false);
        }
      }, 0);
    });
    select.addEventListener('change', syncInput);

    Array.prototype.forEach.call(select.labels || [], function (associatedLabel) {
      associatedLabel.htmlFor = inputId;
      associatedLabel.addEventListener('click', function () {
        window.setTimeout(function () { input.focus(); }, 0);
      });
    });

    document.addEventListener('mousedown', function (event) {
      if (isOpen && !wrapper.contains(event.target)) {
        commitInput(true);
        setOpen(false);
      }
    });

    var form = select.form;
    if (form) {
      form.addEventListener('reset', function () {
        window.setTimeout(function () {
          syncInput();
          setOpen(false);
        }, 0);
      });
      form.addEventListener('submit', function (event) {
        if (!commitInput(false)) {
          event.preventDefault();
          input.reportValidity();
          input.focus();
        }
      }, true);
    }

    if (typeof MutationObserver === 'function') {
      var observer = new MutationObserver(function () {
        if (isOpen) renderOptions();
        if (document.activeElement !== input) syncInput();
      });
      observer.observe(select, {
        attributes: true,
        attributeFilter: ['disabled', 'label', 'selected', 'value'],
        childList: true,
        subtree: true
      });
    }

    syncInput();
  }

  function initialize(root) {
    var selector = 'select:not([multiple])';
    if (root.nodeType === 1 && root.matches(selector)) enhance(root);
    var selects = root.querySelectorAll(selector);
    Array.prototype.forEach.call(selects, enhance);
  }

  function start() {
    initialize(document);
    if (typeof MutationObserver === 'function' && document.documentElement) {
      var observer = new MutationObserver(function (records) {
        records.forEach(function (record) {
          Array.prototype.forEach.call(record.addedNodes, function (node) {
            if (node.nodeType === 1) initialize(node);
          });
        });
      });
      observer.observe(document.documentElement, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
