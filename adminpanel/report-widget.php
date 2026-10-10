<?php
/**
 * "Report" button + pop-up for watch.php and shorts.php.
 * Put this file in the main VibeStream folder, then on the page add:
 *
 *   <?php $reportContentId = $theVideoId; $reportKind = 'video'; include __DIR__ . '/report-widget.php'; ?>
 *
 * ($reportKind is 'short' on the shorts page.)
 */
(static function (): void {
    $dirs = [__DIR__];
    foreach ($dirs as $d) {
        $f = $d . '/report-helpers.php';
        if (is_file($f)) { require_once $f; return; }
    }
    foreach ($dirs as $d) {                      // tolerate names like 123_report-helpers.php
        $hits = glob($d . '/*report-helpers.php') ?: [];
        if ($hits) { require_once $hits[0]; return; }
    }
    http_response_code(500);
    exit('report-helpers.php was not found. Put it in ' . htmlspecialchars(realpath(__DIR__ . '/..') ?: __DIR__ . '/..') . ' (the main VibeStream folder).');
})();

// Opened directly (fetched by video-menu.js on pages that did not include it): output just the hidden pop-up.
if (!isset($reportContentId) && realpath(__FILE__) === realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    $reportHideButton = true;
}

$rwId   = trim((string)($reportContentId ?? ''));
$rwKind = (($reportKind ?? 'video') === 'short') ? 'short' : 'video';
if ($rwId === '' && empty($reportHideButton)) {
    return;
}
$rwReasons = vs_report_reasons();
$rwE = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<style>
  :where(.vs-report-btn){display:inline-flex;align-items:center;gap:6px;background:transparent;color:inherit;border:1px solid rgba(128,128,128,.45);border-radius:999px;padding:7px 14px;font:inherit;font-size:.9rem;cursor:pointer}
  :where(.vs-report-btn):hover{background:rgba(128,128,128,.15)}
  .vs-report-dialog{border:0;border-radius:14px;padding:0;max-width:420px;width:calc(100% - 32px);background:#fff;color:#1d1d27;box-shadow:0 10px 40px rgba(0,0,0,.35)}
  .vs-report-dialog::backdrop{background:rgba(0,0,0,.55)}
  .vs-report-box{padding:22px;font-family:Arial,Helvetica,sans-serif}
  .vs-report-box h3{margin:0 0 4px;font-size:18px}
  .vs-report-box p{margin:0 0 12px;color:#555;font-size:14px}
  .vs-report-box label.vs-opt{display:flex;align-items:center;gap:8px;padding:7px 0;font-size:15px;cursor:pointer}
  .vs-report-box textarea{width:100%;box-sizing:border-box;min-height:70px;margin-top:8px;padding:9px;border:1px solid #ccc;border-radius:8px;font:inherit;resize:vertical}
  .vs-report-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:14px}
  .vs-report-actions button{border:0;border-radius:8px;padding:9px 16px;font:inherit;cursor:pointer}
  .vs-report-send{background:#c0392b;color:#fff}
  .vs-report-cancel{background:#e5e7eb;color:#1d1d27}
  .vs-report-msg{margin-top:10px;font-size:14px}
  .vs-report-msg.err{color:#b02a2a}.vs-report-msg.ok{color:#1b6b31}
</style>

<?php if (!empty($reportHideButton)): ?>
<?php elseif ($rwKind === 'short'): ?>
<button type="button" class="icon-btn" id="vsReportOpen" aria-haspopup="dialog" aria-label="Report this short">
  <span class="circle"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 22V4"/><path d="M4 4h13l-2 4 2 4H4"/></svg></span>
  <span>Report</span>
</button>
<?php else: ?>
<button type="button" class="vs-report-btn btn-pill" id="vsReportOpen" aria-haspopup="dialog">&#9873; Report</button>
<?php endif; ?>

<dialog class="vs-report-dialog" id="vsReportDialog" aria-labelledby="vsReportTitle">
  <form class="vs-report-box" id="vsReportForm">
    <h3 id="vsReportTitle">Report this <?php echo $rwKind === 'short' ? 'short' : 'video'; ?></h3>
    <p>What is wrong with it? Your report goes to the admins.</p>
    <input type="hidden" name="id" value="<?php echo $rwE($rwId); ?>">
    <input type="hidden" name="kind" value="<?php echo $rwE($rwKind); ?>">
    <?php foreach ($rwReasons as $key => $label): ?>
      <label class="vs-opt"><input type="radio" name="reason" value="<?php echo $rwE($key); ?>" required> <?php echo $rwE($label); ?></label>
    <?php endforeach; ?>
    <textarea name="details" maxlength="500" placeholder="Anything else we should know? (optional)"></textarea>
    <div class="vs-report-msg" id="vsReportMsg" role="status"></div>
    <div class="vs-report-actions">
      <button type="button" class="vs-report-cancel" id="vsReportCancel">Cancel</button>
      <button type="submit" class="vs-report-send" id="vsReportSend">Send report</button>
    </div>
  </form>
</dialog>

<script>
(function () {
  var dlg = document.getElementById('vsReportDialog');
  var openBtn = document.getElementById('vsReportOpen');
  var form = document.getElementById('vsReportForm');
  var msg = document.getElementById('vsReportMsg');
  var send = document.getElementById('vsReportSend');
  if (!dlg || !form || typeof dlg.showModal !== 'function') return;
  var idIn = form.querySelector('input[name="id"]'), kindIn = form.querySelector('input[name="kind"]'), titleEl = document.getElementById('vsReportTitle');
  function setTarget(id, kind) { idIn.value = id; kindIn.value = kind; titleEl.textContent = 'Report this ' + (kind === 'short' ? 'short' : 'video'); }
  // Lets other scripts (video-menu.js) open this dialog for any video: vsOpenReport(id, 'video' | 'short')
  window.vsOpenReport = function (id, kind) { setTarget(String(id), kind === 'short' ? 'short' : 'video'); say('', true); send.disabled = false; dlg.showModal(); };

  function say(text, ok) { msg.textContent = text; msg.className = 'vs-report-msg ' + (ok ? 'ok' : 'err'); }

  if (openBtn) openBtn.addEventListener('click', function () { setTarget(idIn.defaultValue, kindIn.defaultValue); say('', true); send.disabled = false; dlg.showModal(); });
  document.getElementById('vsReportCancel').addEventListener('click', function () { dlg.close(); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    send.disabled = true;
    fetch('report-content.php', { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.ok) {
          say(d.message || 'Thanks. Your report was sent.', true);
          setTimeout(function () { dlg.close(); form.reset(); }, 1400);
        } else {
          say((d && d.error) || 'Could not send the report.', false);
          send.disabled = false;
        }
      })
      .catch(function () { say('Could not send the report. Check your connection.', false); send.disabled = false; });
  });
})();
</script>