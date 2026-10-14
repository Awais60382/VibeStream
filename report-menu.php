<?php
/**
 * Makes the "Report" item in the three-dot menu of video and short cards work
 * (index.php, search results, channel pages...). Put this file in the SITE ROOT and add
 * this one line just before </body> on each page that has those card menus:
 *
 *     <?php include __DIR__ . '/report-menu.php'; ?>
 *
 * It needs report-widget.php, report-content.php and report-helpers.php (same folder).
 */
require_once __DIR__ . '/report-widget.php';
vs_rw_assets('');
?>
<script>
(function () {
  var LINK = 'a[href*="watch.php?id="], a[href*="shorts.php?id="]';
  var last = null; // the card whose menu was opened most recently

  function fromLink(a) {
    try {
      var u = new URL(a.getAttribute('href'), location.href);
      var id = u.searchParams.get('id');
      if (!id) return null;
      return { id: id, type: /shorts\.php$/i.test(u.pathname) ? 'short' : 'video' };
    } catch (err) { return null; }
  }

  // Finds which video or short a clicked element belongs to.
  function cardOf(node) {
    var el = node, hops = 0;
    while (el && el !== document.body && el !== document.documentElement && hops < 12) {
      if (el.nodeType === 1) {
        var ds = el.dataset || {};
        var did = ds.videoId || ds.vid;
        if (did) return { id: did, type: (ds.type || ds.kind) === 'short' ? 'short' : 'video' };
        var a = el.matches && el.matches(LINK) ? el : el.querySelector(LINK);
        if (a) { var p = fromLink(a); if (p) return p; }
      }
      el = el.parentElement;
      hops++;
    }
    return null;
  }

  // Is the clicked thing the "Report" item of a menu?
  function isReportItem(node) {
    var el = node, n = 0;
    while (el && el !== document.body && n < 5) {
      if (el.nodeType === 1 && (el.textContent || '').trim().toLowerCase() === 'report') return true;
      el = el.parentElement;
      n++;
    }
    return false;
  }

  function openReport(c) {
    var b = document.createElement('button');
    b.type = 'button';
    b.setAttribute('data-vs-report', '');
    b.setAttribute('data-type', c.type);
    b.setAttribute('data-table', 'videos');
    b.setAttribute('data-id', c.id);
    b.style.display = 'none';
    document.body.appendChild(b);
    b.click();
    b.remove();
  }

  // Capture phase: runs before the page's own handler, so its "not available" message never appears.
  document.addEventListener('click', function (e) {
    if (e.target.closest && e.target.closest('#vsReportDlg, [data-vs-report]')) return;

    if (last && isReportItem(e.target)) {
      e.preventDefault();
      e.stopImmediatePropagation();
      openReport(last);
      // Close the three-dot menu the way the page normally does (click elsewhere / Escape).
      setTimeout(function () {
        document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
      }, 0);
      return;
    }

    var c = cardOf(e.target);
    if (c) last = c;
  }, true);
})();
</script>