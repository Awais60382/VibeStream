<?php
/*
 * announcement-banner.php  (site root)
 * A banner built on the server and printed straight into the page, so it still appears
 * when the popup script or the feed fails. Visitors can close it; it comes back for the next
 * announcement. View the page source and search "vs-annb" to see why it is hidden if it is.
 *
 * Main site header.php:   vs_announcement_banner($vsPdo, '');
 * admin/header.php:       vs_announcement_banner($pdo, '../');
 */
require_once __DIR__ . '/announcements-store.php';

function vs_announcement_banner(?PDO $pdo, string $base = ''): void
{
    if (defined('VS_ANNB_DONE')) {
        return; // never print it twice on one page
    }
    define('VS_ANNB_DONE', 1);

    try {
        if (!$pdo) {
            echo "\n<!-- vs-annb: no database connection -->\n";
            return;
        }
        vs_updates_ensure($pdo);
        $now = time();
        $latest = null;
        $event = null;
        foreach (vs_updates_admin_all($pdo) as $u) {
            $pub = strtotime((string)$u['publish_at']);
            if (($u['status'] ?? '') !== 'published' || !$pub || $pub > $now) {
                continue;
            }
            if ($latest === null || $pub > strtotime((string)$latest['publish_at'])) {
                $latest = $u;
            }
            $evt = !empty($u['event_at']) ? strtotime((string)$u['event_at']) : 0;
            if ($evt > $now && ($event === null || $evt < strtotime((string)$event['event_at']))) {
                $event = $u;
            }
        }
        // do not keep an old announcement on screen forever (events keep their banner until they happen)
        if ($latest !== null && strtotime((string)$latest['publish_at']) < $now - 30 * 86400) {
            $latest = null;
        }
        $main = $latest ?? $event;
        if ($main === null) {
            echo "\n<!-- vs-annb: no live announcements (draft, or publish date in the future) -->\n";
            return;
        }

        $e = static function ($v): string {
            return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        };
        $kind = (string)($main['kind'] ?? 'announcement');
        $kindLabel = defined('VS_UPDATE_TYPES') && isset(VS_UPDATE_TYPES[$kind]) ? VS_UPDATE_TYPES[$kind] : 'Announcement';
        $body = trim((string)preg_replace('/\s+/', ' ', (string)$main['body']));
        if (mb_strlen($body) > 160) {
            $body = mb_substr($body, 0, 160) . '…';
        }
        $id = (int)$main['id'];
        $href = $e($base . 'announcements.php');

        $line2 = '';
        if ($event !== null) {
            $line2 = '📅 Coming up: ' . $e($event['title']) . ' · ' . $e(date('M j, Y · g:i A', strtotime((string)$event['event_at'])));
        }
        ?>
<!-- vs-annb: showing announcement #<?= $id ?> -->
<style>
#vs-annb{position:fixed;left:0;right:0;bottom:0;z-index:9990;display:flex;align-items:center;gap:14px;padding:11px 16px;
  color:#fff;font-family:Roboto,Arial,sans-serif;font-size:14px;line-height:1.35;
  background:linear-gradient(90deg,#3a0910,#a3121d 60%,#c4121f);border-top:1px solid rgba(255,120,128,.55);
  box-shadow:0 -10px 30px rgba(0,0,0,.45)}
#vs-annb[hidden]{display:none}
#vs-annb .vsab-ic{flex:none;display:grid;place-items:center;width:36px;height:36px;border-radius:10px;background:rgba(0,0,0,.28);font-size:18px}
#vs-annb .vsab-tx{min-width:0;flex:1}
#vs-annb .vsab-tx b{font-weight:800}
#vs-annb .vsab-tag{display:inline-block;margin-right:8px;padding:1px 8px;border-radius:999px;background:rgba(0,0,0,.32);font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.05em}
#vs-annb .vsab-body{display:block;opacity:.9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#vs-annb .vsab-ev{display:block;margin-top:2px;font-size:12px;color:#ffd9dc}
#vs-annb .vsab-go{flex:none;padding:8px 14px;border-radius:10px;background:#fff;color:#a3121d;font-weight:800;text-decoration:none;white-space:nowrap}
#vs-annb .vsab-go:hover{background:#ffe9eb;color:#8f0e18}
#vs-annb .vsab-x{flex:none;width:32px;height:32px;border:0;border-radius:50%;background:rgba(0,0,0,.28);color:#fff;font-size:20px;line-height:1;cursor:pointer}
#vs-annb .vsab-x:hover{background:rgba(0,0,0,.5)}
body.vs-annb-on{padding-bottom:64px}
@media(max-width:640px){#vs-annb{flex-wrap:wrap;gap:8px}#vs-annb .vsab-body{white-space:normal}#vs-annb .vsab-go{order:3}}
</style>
<div id="vs-annb" role="region" aria-label="Announcement" data-id="<?= $id ?>" hidden>
  <span class="vsab-ic" aria-hidden="true">📢</span>
  <div class="vsab-tx">
    <span class="vsab-tag"><?= $e($kindLabel) ?></span><b><?= $e($main['title']) ?></b>
    <?php if ($body !== ''): ?><span class="vsab-body"><?= $e($body) ?></span><?php endif; ?>
    <?php if ($line2 !== ''): ?><span class="vsab-ev"><?= $line2 ?></span><?php endif; ?>
  </div>
  <a class="vsab-go" href="<?= $href ?>">Read more</a>
  <button class="vsab-x" type="button" aria-label="Close announcement">&times;</button>
</div>
<script>
(function () {
  var box = document.getElementById('vs-annb');
  if (!box) { return; }
  var id = box.getAttribute('data-id'), KEY = 'vs-annb-closed';
  try { if (localStorage.getItem(KEY) === id) { return; } } catch (e) {}
  box.hidden = false;
  function on() { if (document.body) { document.body.classList.add('vs-annb-on'); } }
  if (document.body) { on(); } else { document.addEventListener('DOMContentLoaded', on); }
  box.querySelector('.vsab-x').addEventListener('click', function () {
    box.hidden = true;
    document.body.classList.remove('vs-annb-on');
    try { localStorage.setItem(KEY, id); } catch (e) {}
  });
})();
</script>
        <?php
    } catch (Throwable $err) {
        error_log('Announcement banner failed: ' . $err->getMessage());
        echo "\n<!-- vs-annb: error, see the PHP error log -->\n";
    }
}