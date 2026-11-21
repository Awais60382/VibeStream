<?php
require_once __DIR__ . '/premium-store.php';

function vs_premium_popup_eligible(string $username): bool
{
    if ($username === '' || vs_premium_is_admin($username)) {
        return false;
    }

    $pdo = vs_premium_pdo();
    if (!$pdo) {
        return false;
    }

    try {
        vs_premium_ensure($pdo);
        return !vs_premium_is_active(vs_premium_get($pdo, $username));
    } catch (Throwable $e) {
        error_log('Premium popup eligibility could not be checked: ' . $e->getMessage());
        return false;
    }
}

function vs_premium_popup_html(string $username, bool $eligible): string
{
    if ($username === '' || !$eligible) {
        return '';
    }

    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<dialog class="vs-premium-prompt" id="vsPremiumPrompt" data-user="{$safeUsername}" aria-labelledby="vsPremiumPromptTitle" aria-describedby="vsPremiumPromptDescription">
  <div class="vs-premium-prompt-content">
    <button class="vs-premium-prompt-close" type="button" aria-label="Close Premium offer">&times;</button>
    <span class="vs-premium-prompt-kicker">VibeStream Premium</span>
    <h2 id="vsPremiumPromptTitle">Enjoy more with Premium</h2>
    <p id="vsPremiumPromptDescription">Unlock Premium benefits and get more from your time on VibeStream.</p>
    <div class="vs-premium-prompt-actions">
      <a class="vs-premium-prompt-upgrade" href="premium.php">Explore Premium</a>
      <button class="vs-premium-prompt-later" type="button">Maybe later</button>
    </div>
  </div>
</dialog>
<style>
.vs-premium-prompt{width:min(480px,calc(100% - 32px));padding:0;border:1px solid rgba(192,132,252,.5);border-radius:22px;background:#15102b;color:#f8f5ff;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.vs-premium-prompt::backdrop{background:rgba(5,4,18,.72);backdrop-filter:blur(4px)}
.vs-premium-prompt-content{position:relative;padding:34px}
.vs-premium-prompt-kicker{display:inline-block;margin-bottom:12px;color:#d8b4fe;font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
.vs-premium-prompt h2{margin:0 36px 10px 0;font-size:clamp(24px,5vw,32px);line-height:1.15}
.vs-premium-prompt p{margin:0;color:#c4bfd4;line-height:1.6}
.vs-premium-prompt-close{position:absolute;top:14px;right:16px;border:0;background:transparent;color:inherit;font-size:28px;line-height:1;cursor:pointer}
.vs-premium-prompt-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
.vs-premium-prompt-upgrade,.vs-premium-prompt-later{display:inline-flex;min-height:44px;align-items:center;justify-content:center;padding:10px 16px;border-radius:999px;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}
.vs-premium-prompt-upgrade{border:0;background:linear-gradient(135deg,#a855f7,#6d28d9);color:#fff}
.vs-premium-prompt-later{border:1px solid rgba(255,255,255,.2);background:transparent;color:inherit}
html[data-theme="light"] .vs-premium-prompt{background:#fff;color:#241642}
html[data-theme="light"] .vs-premium-prompt p{color:#5f5a70}
html[data-theme="light"] .vs-premium-prompt-later{border-color:rgba(36,22,66,.2)}
</style>
<script>
(function () {
  var dialog = document.getElementById('vsPremiumPrompt');
  if (!dialog || typeof dialog.showModal !== 'function') return;
  var key = 'vs-premium-popup-dismissed:' + encodeURIComponent(dialog.dataset.user.toLowerCase());
  var dismissedAt;
  try {
    dismissedAt = Number(window.localStorage.getItem(key)) || 0;
  } catch (error) {
    return;
  }
  if (Date.now() - dismissedAt < 30 * 24 * 60 * 60 * 1000) return;
  function dismiss() {
    try { window.localStorage.setItem(key, String(Date.now())); } catch (error) {}
    if (dialog.open) dialog.close();
  }
  dialog.addEventListener('close', function () {
    try { window.localStorage.setItem(key, String(Date.now())); } catch (error) {}
  });
  dialog.querySelector('.vs-premium-prompt-close').addEventListener('click', dismiss);
  dialog.querySelector('.vs-premium-prompt-later').addEventListener('click', dismiss);
  dialog.querySelector('.vs-premium-prompt-upgrade').addEventListener('click', function () {
    try { window.localStorage.setItem(key, String(Date.now())); } catch (error) {}
  });
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) dismiss();
  });
  setTimeout(function () { if (!dialog.open) dialog.showModal(); }, 1200);
})();
</script>
HTML;
}
