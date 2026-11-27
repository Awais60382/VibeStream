<?php
require_once __DIR__ . '/premium-store.php';

function vs_premium_popup_eligible(string $username, bool $includeAdmins = false): bool
{
    if ($username === '' || (!$includeAdmins && vs_premium_is_admin($username))) {
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

function vs_premium_popup_html(string $username, bool $eligible, string $upgradeUrl = 'premium.php'): string
{
    if ($username === '' || !$eligible) {
        return '';
    }

    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeUpgradeUrl = htmlspecialchars($upgradeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<dialog class="vs-premium-prompt" id="vsPremiumPrompt" data-user="{$safeUsername}" aria-labelledby="vsPremiumPromptTitle" aria-describedby="vsPremiumPromptDescription">
  <div class="vs-premium-prompt-content">
    <button class="vs-premium-prompt-close" type="button" aria-label="Close Premium offer">&times;</button>
    <span class="vs-premium-prompt-crown" aria-hidden="true">♛</span>
    <span class="vs-premium-prompt-kicker">VibeStream Premium</span>
    <h2 id="vsPremiumPromptTitle">More VibeStream.<br>Fewer limits.</h2>
    <p id="vsPremiumPromptDescription">Upgrade your experience with ad-free viewing, more playlists, extra Watch Later space, and downloads.</p>
    <ul class="vs-premium-prompt-perks" aria-label="Premium benefits">
      <li><span aria-hidden="true">✓</span> Ad-free viewing</li>
      <li><span aria-hidden="true">✓</span> More playlists</li>
      <li><span aria-hidden="true">✓</span> More Watch Later space</li>
      <li><span aria-hidden="true">✓</span> Downloads</li>
    </ul>
    <div class="vs-premium-prompt-actions">
      <a class="vs-premium-prompt-upgrade" href="{$safeUpgradeUrl}">See Premium plans <span aria-hidden="true">→</span></a>
      <button class="vs-premium-prompt-later" type="button">Maybe later</button>
    </div>
  </div>
</dialog>
<style>
.vs-premium-prompt{width:min(500px,calc(100% - 32px));padding:0;border:1px solid rgba(192,132,252,.5);border-radius:24px;background:radial-gradient(420px 220px at 100% 0,rgba(168,85,247,.22),transparent 72%),#15102b;color:#f8f5ff;box-shadow:0 24px 80px rgba(0,0,0,.7)}
.vs-premium-prompt::backdrop{background:rgba(5,4,18,.72);backdrop-filter:blur(4px)}
.vs-premium-prompt-content{position:relative;padding:32px}
.vs-premium-prompt-crown{display:grid;place-items:center;width:44px;height:44px;margin-bottom:18px;border:1px solid rgba(255,210,90,.5);border-radius:14px;background:linear-gradient(145deg,rgba(255,210,90,.22),rgba(255,152,45,.08));color:#ffd36b;font-size:27px;line-height:1;box-shadow:0 0 24px rgba(255,194,61,.15)}
.vs-premium-prompt-kicker{display:block;margin-bottom:8px;color:#d8b4fe;font-size:11px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}
.vs-premium-prompt h2{margin:0 36px 10px 0;font-size:clamp(27px,5vw,36px);line-height:1.1;letter-spacing:-.03em}
.vs-premium-prompt p{margin:0;color:#c4bfd4;line-height:1.6}
.vs-premium-prompt-close{position:absolute;top:14px;right:16px;border:0;background:transparent;color:inherit;font-size:28px;line-height:1;cursor:pointer}
.vs-premium-prompt-perks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:20px 0 0;padding:0;list-style:none}
.vs-premium-prompt-perks li{display:flex;align-items:center;gap:8px;min-width:0;padding:10px;border:1px solid rgba(192,132,252,.18);border-radius:12px;background:rgba(168,85,247,.08);font-size:13px;font-weight:650}
.vs-premium-prompt-perks li span{color:#d8b4fe;font-weight:900}
.vs-premium-prompt-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:24px}
.vs-premium-prompt-upgrade,.vs-premium-prompt-later{display:inline-flex;min-height:46px;align-items:center;justify-content:center;gap:9px;padding:10px 17px;border-radius:12px;font:inherit;font-weight:750;text-decoration:none;cursor:pointer}
.vs-premium-prompt-upgrade{border:0;background:linear-gradient(135deg,#a855f7,#6d28d9);color:#fff;box-shadow:0 8px 22px rgba(124,58,237,.25)}
.vs-premium-prompt-upgrade:hover{color:#fff;filter:brightness(1.08)}
.vs-premium-prompt-later{border:1px solid rgba(255,255,255,.2);background:transparent;color:inherit}
html[data-theme="light"] .vs-premium-prompt{background:#fff;color:#241642}
html[data-theme="light"] .vs-premium-prompt p{color:#5f5a70}
html[data-theme="light"] .vs-premium-prompt-perks li{background:rgba(124,58,237,.05);border-color:rgba(124,58,237,.14)}
html[data-theme="light"] .vs-premium-prompt-later{border-color:rgba(36,22,66,.2)}
@media(max-width:420px){.vs-premium-prompt-content{padding:26px 22px}.vs-premium-prompt-perks{grid-template-columns:1fr}.vs-premium-prompt-actions>*{flex:1}}
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
