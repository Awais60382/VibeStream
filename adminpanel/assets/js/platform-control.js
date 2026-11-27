/*
 * Platform Control pop-up (owner only).
 * Any element with data-vs-maint opens a confirmation instead of going straight to the page:
 *   - the "Platform Control" icon in the admin sidebar (every admin page)
 *   - the "Enable Maintenance" / "Open Platform" button on the Platform Control page
 * The pop-up asks first, then sends the request to platform-control.php, which re-checks that
 * the signed-in person is the owner. Ctrl/Cmd/Shift-click still opens the full page.
 */
(function () {
  'use strict';
  if (window.__vsMaintLoaded) { return; }
  window.__vsMaintLoaded = true;

  var MAX = 300;
  var modal = null;
  var lastFocus = null;

  function make(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined) { n.textContent = text; }
    return n;
  }
  function icon(name) {
    var i = make('i', 'bi ' + name);
    i.setAttribute('aria-hidden', 'true');
    return i;
  }

  function close() {
    if (!modal) { return; }
    document.removeEventListener('keydown', onKey, true);
    modal.back.parentNode.removeChild(modal.back);
    document.body.style.overflow = modal.bodyOverflow;
    modal = null;
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }

  function onKey(e) {
    if (!modal) { return; }
    if (e.key === 'Escape') { e.preventDefault(); if (!modal.busy) { close(); } return; }
    if (e.key !== 'Tab') { return; }
    var f = modal.back.querySelectorAll('button:not([disabled]), textarea');
    if (!f.length) { return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function open(trigger) {
    if (modal) { return; }
    lastFocus = document.activeElement;
    var reopen = trigger.getAttribute('data-state') === 'offline';   // platform is closed: this asks to open it again
    var url = trigger.getAttribute('data-url') || 'platform-control.php';
    var csrf = trigger.getAttribute('data-csrf') || '';
    var pageBox = document.getElementById('offline_message');
    var startText = pageBox ? pageBox.value : (trigger.getAttribute('data-message') || 'This platform is currently offline for maintenance.');

    var back = make('div', 'vpc-modal-back');
    var dlg = make('div', 'vpc-modal' + (reopen ? ' is-open-mode' : ''));
    dlg.setAttribute('role', 'dialog');
    dlg.setAttribute('aria-modal', 'true');
    dlg.setAttribute('aria-labelledby', 'vpcModalTitle');

    var x = make('button', 'vpc-modal-x');
    x.type = 'button';
    x.setAttribute('aria-label', 'Close');
    x.appendChild(icon('bi-x-lg'));

    var big = icon(reopen ? 'bi-play-circle-fill' : 'bi-exclamation-triangle-fill');
    big.className += ' vpc-modal-icon';
    var title = make('h2', '', reopen ? 'Open the Platform Again?' : 'Enable Maintenance Mode?');
    title.id = 'vpcModalTitle';
    var text = make('p', 'vpc-modal-text', reopen
      ? 'Visitors will be able to use the platform again and maintenance mode will be turned off.'
      : 'Are you sure you want to put this platform into maintenance mode? Visitors will not be able to use it until you open it again.');

    dlg.appendChild(x);
    dlg.appendChild(big);
    dlg.appendChild(title);
    dlg.appendChild(text);

    var area = null, count = null;
    if (!reopen) {
      var field = make('div', 'vpc-modal-field');
      var label = make('label');
      label.setAttribute('for', 'vpcModalMessage');
      label.appendChild(icon('bi-chat-left-text'));
      label.appendChild(document.createTextNode('Maintenance Message (optional)'));
      area = make('textarea');
      area.id = 'vpcModalMessage';
      area.maxLength = MAX;
      area.rows = 2;
      area.value = startText;
      count = make('div', 'vpc-modal-count');
      var upd = function () { count.textContent = area.value.length + ' / ' + MAX; };
      area.addEventListener('input', upd);
      upd();
      field.appendChild(label);
      field.appendChild(area);
      field.appendChild(count);
      dlg.appendChild(field);
    }

    var note = make('div');            // errors and the success message appear here
    dlg.appendChild(note);

    var actions = make('div', 'vpc-modal-actions');
    var cancel = make('button', 'vpc-btn vpc-btn-ghost');
    cancel.type = 'button';
    cancel.appendChild(icon('bi-x-lg'));
    cancel.appendChild(document.createTextNode('Cancel'));
    var yes = make('button', 'vpc-btn ' + (reopen ? 'vpc-btn-open' : 'vpc-btn-main'));
    yes.type = 'button';
    yes.appendChild(icon(reopen ? 'bi-play-fill' : 'bi-power'));
    yes.appendChild(document.createTextNode(reopen ? 'Yes, Open' : 'Yes, Enable'));
    actions.appendChild(cancel);
    actions.appendChild(yes);
    dlg.appendChild(actions);

    back.appendChild(dlg);
    document.body.appendChild(back);
    modal = { back: back, busy: false, bodyOverflow: document.body.style.overflow };
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', onKey, true);

    function setBusy(b) {
      modal.busy = b;
      yes.disabled = b; cancel.disabled = b; x.disabled = b;
    }
    function show(cls, msg) {
      note.textContent = '';
      note.appendChild(make('p', cls, msg));
    }

    x.addEventListener('click', function () { if (!modal.busy) { close(); } });
    cancel.addEventListener('click', function () { if (!modal.busy) { close(); } });
    back.addEventListener('mousedown', function (e) { if (e.target === back && !modal.busy) { close(); } });

    yes.addEventListener('click', function () {
      setBusy(true);
      note.textContent = '';
      var body = new URLSearchParams();
      body.set('csrf_token', csrf);
      body.set('action', reopen ? 'open' : 'close');
      if (!reopen) { body.set('offline_message', area.value); }
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
        body: body.toString()
      }).then(function (r) {
        return r.json().catch(function () { throw new Error('Could not update the platform. Refresh the page and try again.'); });
      }).then(function (j) {
        if (!j || !j.ok) { throw new Error((j && j.message) || 'Could not update the platform.'); }
        show('vpc-modal-done', j.message || 'Done.');
        actions.style.display = 'none';
        window.setTimeout(function () { window.location.reload(); }, 900);
      }).catch(function (err) {
        setBusy(false);
        show('vpc-modal-error', err && err.message ? err.message : 'Could not update the platform.');
      });
    });

    (area || yes).focus();
  }

  document.addEventListener('click', function (e) {
    var t = e.target && e.target.closest ? e.target.closest('[data-vs-maint]') : null;
    if (!t) { return; }
    if (e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) { return; }   // let "open in new tab" work
    e.preventDefault();
    open(t);
  });
})();
