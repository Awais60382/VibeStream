<?php
/**
 * Report button and popup for the main site. Put this file in the SITE ROOT
 * (the same folder as report-helpers.php and connection.php).
 *
 * watch.php and shorts.php already include this file like this, so nothing else needs to change:
 *   $reportContentId = (string)$id; $reportKind = 'video';   // or 'short'
 *   include __DIR__ . '/report-widget.php';
 *
 * It sends reports to report-content.php (your existing endpoint, which must sit in the SAME folder),
 * and they land in the `content_reports` table that adminpanel/reported.php reads.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('vs_rw_button')) {

/** Loads report-helpers.php (also tolerates names like 123_report-helpers.php). */
function vs_rw_load_helpers(): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    foreach ([__DIR__, __DIR__ . '/adminpanel'] as $dir) {
        $f = $dir . '/report-helpers.php';
        if (is_file($f)) {
            require_once $f;
            return $ok = true;
        }
        $hits = glob($dir . '/*report-helpers.php') ?: [];
        if ($hits) {
            require_once $hits[0];
            return $ok = true;
        }
    }
    return $ok = false;
}

/** The reasons report-content.php accepts (same list the admin page shows). */
function vs_rw_reasons(): array
{
    if (vs_rw_load_helpers() && function_exists('vs_report_reasons')) {
        return vs_report_reasons();
    }
    return [
        'sexual'    => 'Sexual content',
        'violence'  => 'Violent or repulsive content',
        'hate'      => 'Hateful or abusive content',
        'dangerous' => 'Harmful or dangerous acts',
        'spam'      => 'Spam or misleading',
        'other'     => 'Something else',
    ];
}

/** Prints the popup, styles and script once per page. */
function vs_rw_assets(string $base = ''): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $url  = htmlspecialchars($base . 'report-content.php', ENT_QUOTES, 'UTF-8');
    ?>
<style>
  .vs-report-btn { display: inline-flex; align-items: center; gap: 6px; background: none; border: 0; color: inherit; cursor: pointer; font: inherit; padding: 6px 10px; border-radius: 999px; opacity: .85; }
  .vs-report-btn:hover { opacity: 1; background: rgba(127, 127, 127, .18); }
  #vsReportDlg { border: 0; border-radius: 16px; padding: 0; width: min(440px, 92vw); background: #fff; color: #111; box-shadow: 0 20px 60px rgba(0, 0, 0, .35); }
  #vsReportDlg::backdrop { background: rgba(0, 0, 0, .55); }
  #vsReportDlg form { padding: 22px; }
  #vsReportDlg h2 { font-size: 1.15rem; margin: 0 0 4px; }
  #vsReportDlg p.sub { margin: 0 0 14px; color: #666; font-size: .9rem; }
  #vsReportDlg .vs-reasons { display: grid; gap: 6px; margin-bottom: 12px; }
  #vsReportDlg .vs-reasons label { display: flex; align-items: center; gap: 10px; padding: 9px 12px; border: 1px solid #ddd; border-radius: 10px; cursor: pointer; }
  #vsReportDlg .vs-reasons label:has(input:checked) { border-color: #7c3aed; background: rgba(124, 58, 237, .08); }
  #vsReportDlg textarea { width: 100%; min-height: 76px; resize: vertical; padding: 10px 12px; border-radius: 10px; border: 1px solid #ddd; background: transparent; color: inherit; font: inherit; }
  #vsReportDlg .vs-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
  #vsReportDlg button { padding: 10px 18px; border-radius: 10px; border: 1px solid #ddd; background: transparent; color: inherit; font-weight: 600; cursor: pointer; }
  #vsReportDlg button.vs-send { background: #7c3aed; border-color: #7c3aed; color: #fff; }
  #vsReportDlg button:disabled { opacity: .6; cursor: default; }
  #vsReportMsg { margin: 10px 0 0; font-size: .9rem; min-height: 1.2em; }
  #vsReportMsg.err { color: #dc2626; }
  #vsReportMsg.ok { color: #16a34a; }
  #vsReportDlg .vs-hp { position: absolute; left: -9999px; height: 0; overflow: hidden; }
  @media (prefers-color-scheme: dark) {
    #vsReportDlg { background: #1c1c1f; color: #f1f1f1; }
    #vsReportDlg p.sub { color: #a1a1aa; }
    #vsReportDlg .vs-reasons label, #vsReportDlg textarea, #vsReportDlg button { border-color: #3a3a40; }
  }
</style>
<dialog id="vsReportDlg" aria-labelledby="vsReportTitle" data-url="<?php echo $url; ?>">
  <form method="dialog" id="vsReportForm" autocomplete="off">
    <h2 id="vsReportTitle">Report this video</h2>
    <p class="sub">Tell us what is wrong. Our team will review it.</p>
    <input type="hidden" id="vsReportId" value="">
    <input type="hidden" id="vsReportKind" value="video">
    <div class="vs-reasons">
      <?php foreach (vs_rw_reasons() as $key => $label): ?>
        <label><input type="radio" name="reason" value="<?php echo htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8'); ?>" required> <?php echo htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8'); ?></label>
      <?php endforeach; ?>
    </div>
    <textarea name="details" maxlength="500" placeholder="More details (optional)"></textarea>
    <p id="vsReportMsg" role="status"></p>
    <div class="vs-actions">
      <button type="button" id="vsReportCancel">Cancel</button>
      <button type="submit" class="vs-send" id="vsReportSend">Send report</button>
    </div>
  </form>
</dialog>
<script>
(function () {
  var dlg = document.getElementById('vsReportDlg');
  var form = document.getElementById('vsReportForm');
  var msg = document.getElementById('vsReportMsg');
  var send = document.getElementById('vsReportSend');
  if (!dlg || !form) return;

  function setMsg(text, cls) { msg.textContent = text || ''; msg.className = cls || ''; }
  // Safe lookups (do not rely on the browser's form.reason / detailsBox shortcuts).
  var detailsBox = form.querySelector('textarea[name="details"]');
  function reasonValue() {
    var c = form.querySelector('input[name="reason"]:checked');
    return c ? c.value : '';
  }

  // "Something else": the user writes their own message, so the box becomes required.
  function syncOther() {
    var other = reasonValue() === 'other';
    detailsBox.placeholder = other ? 'Tell us what is wrong (required)' : 'More details (optional)';
    detailsBox.required = other;
  }
  form.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'reason') {
      syncOther();
      if (e.target.value === 'other') detailsBox.focus();
    }
  });

  function openFor(id, isShort) {
    form.reset();
    syncOther();
    document.getElementById('vsReportId').value = id || '';
    document.getElementById('vsReportKind').value = isShort ? 'short' : 'video';
    document.getElementById('vsReportTitle').textContent = isShort ? 'Report this short' : 'Report this video';
    setMsg('');
    send.disabled = false;
    if (typeof dlg.showModal === 'function') { if (!dlg.open) dlg.showModal(); } else dlg.setAttribute('open', '');
  }

  // Used by the three-dot menu on video cards (video-menu.js): vsOpenReport(id, 'video' | 'short')
  window.vsOpenReport = function (id, kind) { openFor(id, kind === 'short'); };

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-vs-report]');
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    openFor(btn.getAttribute('data-id') || '', btn.getAttribute('data-type') === 'short');
  });

  document.getElementById('vsReportCancel').addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!reasonValue()) { setMsg('Pick a reason first.', 'err'); return; }
    if (reasonValue() === 'other' && detailsBox.value.trim().length < 3) {
      setMsg('Please tell us a little more.', 'err'); detailsBox.focus(); return;
    }
    send.disabled = true;
    setMsg('Sending...', '');
    var fd = new FormData();
    fd.append('id', document.getElementById('vsReportId').value);
    fd.append('kind', document.getElementById('vsReportKind').value);
    fd.append('reason', reasonValue());
    fd.append('details', detailsBox.value);
    fetch(dlg.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Something went wrong.' }; }); })
      .then(function (d) {
        if (d.ok) {
          setMsg(d.message || 'Thanks. Your report was sent to the admins.', 'ok');
          setTimeout(function () { dlg.close(); }, 1400);
        } else {
          setMsg(d.error || d.message || 'Something went wrong.', 'err');
          send.disabled = false;
        }
      })
      .catch(function () { setMsg('Network problem. Please try again.', 'err'); send.disabled = false; });
  });
})();
</script>
    <?php
}

/**
 * Prints a Report button (and the popup, once).
 *   $type  'video' or 'short'
 *   $table 'videos' (default; shorts are rows in `videos` with type = 'short') or 'shorts'
 *   $base  path from the current page to the site root: '' or '../'
 */
function vs_rw_button($id, string $type = 'video', string $table = '', string $base = '', string $label = 'Report', string $style = 'plain'): void
{
    vs_rw_assets($base);
    $type  = $type === 'short' ? 'short' : 'video';
    $table = $table === 'shorts' ? 'shorts' : 'videos';
    $attrs = ' data-vs-report data-type="' . $type . '" data-table="' . $table
        . '" data-id="' . htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') . '" title="Report" aria-label="Report"';
    $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    $flag  = '<path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/>';
    if ($style === 'icon') {
        // Shorts page: round icon with the label underneath, like the like and comment buttons.
        echo '<button class="icon-btn" type="button"' . $attrs . '><span class="circle"><svg viewBox="0 0 24 24" aria-hidden="true">' . $flag . '</svg></span><span>' . $label . '</span></button>';
    } elseif ($style === 'pill') {
        // Watch page: same pill as Share / Download / Save.
        echo '<button class="btn-pill" type="button"' . $attrs . '>' . $label . '</button>';
    } else {
        echo '<button type="button" class="vs-report-btn"' . $attrs . '><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $flag . '</svg> ' . $label . '</button>';
    }
}
}  // end of function definitions

// Include mode: watch.php and shorts.php set these two variables, then include this file.
if (isset($reportContentId, $reportKind) && $reportContentId !== '') {
    $vsRwKind = $reportKind === 'short' ? 'short' : 'video';
    vs_rw_button($reportContentId, $vsRwKind, 'videos', '', 'Report', $vsRwKind === 'short' ? 'icon' : 'pill');
    unset($vsRwKind);
} elseif (!empty($reportHideButton) || basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'report-widget.php') {
    // index.php (card menus) sets $reportHideButton: print only the popup, no button.
    // Also used when video-menu.js loads this file on demand with fetch('report-widget.php').
    vs_rw_assets('');
}