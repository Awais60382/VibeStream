<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/history-store.php';

if (empty($_SESSION['username'])) {
    header('Location: login.php?redirect=history.php');
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$userId = vs_history_user_id($conn);
if ($userId <= 0) {
    http_response_code(403);
    exit('Sign in again to see your watch history.');
}

$csrf = $_SESSION['history_csrf'] ?? '';
if (!is_string($csrf) || $csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['history_csrf'] = $csrf;
}

// Current filter (All / Videos / Shorts) and search
$type = (string)($_GET['type'] ?? 'all');
if (!in_array($type, ['all', 'videos', 'shorts'], true)) {
    $type = 'all';
}
$search = trim((string)($_GET['q'] ?? ''));

$historyUrl = static function (string $q, string $type): string {
    $params = [];
    if ($q !== '') {
        $params['q'] = $q;
    }
    if ($type !== 'all') {
        $params['type'] = $type;
    }
    return 'history.php' . ($params ? '?' . http_build_query($params) : '');
};

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errorMessage = 'That request expired. Refresh the page and try again.';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'remove') {
                vs_history_remove($conn, $userId, trim((string)($_POST['video_id'] ?? '')));
                $_SESSION['history_notice'] = 'Removed from watch history.';
            } elseif ($action === 'clear') {
                vs_history_clear($conn, $userId);
                $_SESSION['history_notice'] = 'Your watch history was cleared.';
            } elseif ($action === 'pause') {
                vs_history_set_paused($conn, $userId, true);
                $_SESSION['history_notice'] = 'Watch history is paused. Videos you watch will not be saved.';
            } elseif ($action === 'resume') {
                vs_history_set_paused($conn, $userId, false);
                $_SESSION['history_notice'] = 'Watch history is on again.';
            }
            $backType = (string)($_POST['type'] ?? 'all');
            header('Location: ' . $historyUrl(trim((string)($_POST['q'] ?? '')), in_array($backType, ['videos', 'shorts'], true) ? $backType : 'all'));
            exit;
        } catch (Throwable $error) {
            error_log('Watch history update failed: ' . $error->getMessage());
            $errorMessage = 'Could not update your watch history. Please try again.';
        }
    }
}

$successMessage = (string)($_SESSION['history_notice'] ?? '');
unset($_SESSION['history_notice']);

$paused = false;
try {
    $paused = vs_history_paused($conn, $userId);
} catch (Throwable $error) {
    $paused = false;
}

$entries = [];
try {
    $entries = vs_history_fetch($conn, $userId, $search, 200);
} catch (Throwable $error) {
    error_log('Watch history load failed: ' . $error->getMessage());
    $errorMessage = $errorMessage !== '' ? $errorMessage : 'Could not load your watch history.';
}

if ($type !== 'all') {
    $wantShorts = ($type === 'shorts');
    $entries = array_values(array_filter($entries, static function ($e) use ($wantShorts) {
        return ((($e['video']['type'] ?? '') === 'short')) === $wantShorts;
    }));
}

if (!function_exists('vs_history_thumb')) {
    function vs_history_thumb(array $row): string
    {
        $src = function_exists('find_source') ? find_source($row) : '';
        $yt = ($src !== '' && function_exists('extract_youtube_id')) ? extract_youtube_id($src, true) : null;
        if ($yt) {
            return 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
        }
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $col) {
            if (!empty($row[$col])) {
                return (string)$row[$col];
            }
        }
        return '';
    }
}

// Group by day (Today / Yesterday / "Friday, Oct 9"); inside a day, Shorts first, then videos
$groups = [];
$todayKey = date('Y-m-d');
$yesterdayKey = date('Y-m-d', strtotime('-1 day'));
foreach ($entries as $entry) {
    $ts = (int)$entry['watched_ts'];
    $key = date('Y-m-d', $ts);
    if (!isset($groups[$key])) {
        if ($key === $todayKey) {
            $label = 'Today';
        } elseif ($key === $yesterdayKey) {
            $label = 'Yesterday';
        } else {
            $label = date('l, M j', $ts) . (date('Y', $ts) !== date('Y') ? ', ' . date('Y', $ts) : '');
        }
        $groups[$key] = ['label' => $label, 'shorts' => [], 'videos' => []];
    }
    $isShortEntry = (($entry['video']['type'] ?? '') === 'short');
    $groups[$key][$isShortEntry ? 'shorts' : 'videos'][] = $entry;
}

// Small helper: the three-dot menu on every item
$itemMenu = static function (string $videoId, string $title, bool $openUp) use ($h, $csrf, $search, $type): string {
    ob_start(); ?>
    <details class="hm<?= $openUp ? ' hm-up' : '' ?>">
      <summary aria-label="More options for <?= $h($title) ?>"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="12" cy="5" r="1.8"/><circle cx="12" cy="12" r="1.8"/><circle cx="12" cy="19" r="1.8"/></svg></summary>
      <form class="hm-menu" method="post">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="remove">
        <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
        <input type="hidden" name="q" value="<?= $h($search) ?>">
        <input type="hidden" name="type" value="<?= $h($type) ?>">
        <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>Remove from watch history</button>
      </form>
    </details>
    <?php
    return (string)ob_get_clean();
};

$extraCss = <<<'CSS'
.history-page { max-width: 1320px; margin: 0 auto; padding: 22px 24px 56px; }
.history-layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 56px; align-items: start; }
.history-main { min-width: 0; }
.history-side { position: sticky; top: 76px; padding-top: 52px; }
.history-main h1 { margin: 0 0 14px; font-size: clamp(28px, 4vw, 40px); letter-spacing: -0.03em; }

/* Filter chips */
.history-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 26px; }
.history-chip { padding: 7px 14px; border-radius: 8px; background: var(--surface-2); color: var(--text); font-size: 14px; font-weight: 600; text-decoration: none; }
.history-chip:hover { filter: brightness(1.15); }
.history-chip.active { background: var(--text); color: var(--bg, #0f0f0f); }

.history-day { margin: 8px 0 18px; font-size: 20px; font-weight: 700; }
.history-day:not(:first-of-type) { margin-top: 34px; }

/* Shorts shelf */
.hs-shelf { margin-bottom: 26px; }
.hs-shelf-head { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; }
.hs-shelf-head svg { width: 28px; height: 28px; flex: 0 0 28px; }
.hs-shelf-head .hs-ic { display: block; flex: 0 0 auto; width: 23px; height: 28px; background-color: currentColor; -webkit-mask: url("data:image/png;base64,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") center / contain no-repeat; mask: url("data:image/png;base64,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") center / contain no-repeat; }
.hs-shelf-head h3 { margin: 0; font-size: 22px; font-weight: 700; }
.hs-rowwrap { position: relative; }
.hs-row { display: flex; gap: 6px; overflow-x: auto; scroll-snap-type: x proximity; scrollbar-width: none; padding-bottom: 4px; }
.hs-row::-webkit-scrollbar { display: none; }
.hs-card { flex: 0 0 262px; width: 262px; scroll-snap-align: start; }
.hs-thumb { position: relative; display: block; aspect-ratio: 2 / 3; overflow: hidden; border-radius: 14px; background: var(--surface-2); }
.hs-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.hs-cap { display: flex; align-items: flex-start; justify-content: space-between; gap: 4px; margin-top: 10px; }
.hs-title { display: -webkit-box; overflow: hidden; margin: 0; color: var(--text); font-size: 16px; font-weight: 600; line-height: 1.35; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.hs-cap a:hover .hs-title { text-decoration: underline; }
.hs-views { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
.hs-next { position: absolute; right: -8px; top: 50%; z-index: 5; display: none; place-items: center; width: 46px; height: 46px; border: 0; border-radius: 50%; background: rgba(40, 40, 46, .92); color: #fff; cursor: pointer; box-shadow: 0 2px 10px rgba(0, 0, 0, .45); transform: translateY(-50%); }
.hs-next.show { display: grid; }
.hs-next svg { width: 22px; height: 22px; }

/* Video rows */
.history-item { display: grid; grid-template-columns: 246px minmax(0, 1fr) auto; gap: 16px; align-items: start; padding: 8px 0; }
.history-thumb { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 12px; background: var(--surface-2); }
.history-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.history-thumb-empty, .hs-thumb-empty { display: grid; place-items: center; width: 100%; height: 100%; color: var(--muted); background: linear-gradient(135deg, #25252c, #17171b); }
.history-thumb-empty svg, .hs-thumb-empty svg { width: 38px; height: 38px; }
.history-info { min-width: 0; padding-top: 2px; }
.history-title { display: -webkit-box; overflow: hidden; margin: 0 0 6px; color: var(--text); font-size: 18px; font-weight: 600; line-height: 1.3; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.history-info a:hover .history-title { text-decoration: underline; }
.history-meta { margin: 0 0 8px; color: var(--muted); font-size: 12.5px; }
.history-meta a:hover { color: var(--text); }
.history-desc { display: -webkit-box; overflow: hidden; margin: 0; color: var(--muted); font-size: 12.5px; line-height: 1.45; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }

/* Three-dot menu */
.hm { position: relative; flex: 0 0 auto; }
.hm summary { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 50%; color: var(--text); cursor: pointer; list-style: none; }
.hm summary::-webkit-details-marker { display: none; }
.hm summary:hover, .hm[open] summary { background: var(--surface-2); }
.hm summary svg { width: 20px; height: 20px; }
.hm-menu { position: absolute; right: 0; top: 40px; z-index: 30; min-width: 250px; margin: 0; padding: 8px 0; border: 1px solid var(--border); border-radius: 12px; background: var(--surface); box-shadow: 0 8px 28px rgba(0, 0, 0, .45); }
.hm-up .hm-menu { top: auto; bottom: 40px; }
.hm-menu button { display: flex; align-items: center; gap: 14px; width: 100%; padding: 10px 16px; border: 0; background: transparent; color: var(--text); font-size: 14px; text-align: left; cursor: pointer; }
.hm-menu button:hover { background: var(--surface-2); }
.hm-menu svg { width: 20px; height: 20px; flex: 0 0 20px; }

/* Right-hand panel */
.history-search { display: flex; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); }
.history-search svg { width: 22px; height: 22px; flex: 0 0 22px; color: var(--text); }
.history-search input { width: 100%; border: 0; outline: 0; background: transparent; color: var(--text); font-size: 16px; }
.history-search input::placeholder { color: var(--muted); }
.history-search:focus-within { border-bottom-color: var(--text); }
.history-actions { margin-top: 28px; display: flex; flex-direction: column; gap: 6px; }
.history-actions form { margin: 0; }
.history-action { display: flex; align-items: center; gap: 14px; width: 100%; padding: 12px 4px; border: 0; border-radius: 10px; background: transparent; color: var(--text); font-size: 16px; font-weight: 600; text-align: left; cursor: pointer; }
.history-action:hover { background: var(--surface-2); }
.history-action svg { width: 24px; height: 24px; flex: 0 0 24px; }

.history-alert, .history-empty { margin: 0 0 18px; padding: 14px 20px; border-radius: 12px; }
.history-alert { background: rgba(220, 60, 60, .14); color: #ff9b9b; }
.history-success { background: rgba(45, 180, 120, .14); color: #278552; }
html[data-theme="dark"] .history-success { color: #8de2b4; }
.history-paused { background: rgba(255, 190, 60, .14); color: #d99a1c; }
html[data-theme="dark"] .history-paused { color: #ffcf6b; }
.history-empty { padding: 46px 20px; border: 1px dashed var(--border); border-radius: 18px; text-align: center; color: var(--muted); }
.history-empty h2 { margin: 0 0 8px; color: var(--text); }

@media (max-width: 1060px) {
  .history-layout { grid-template-columns: minmax(0, 1fr); gap: 8px; }
  .history-side { position: static; order: -1; padding-top: 0; }
  .history-actions { flex-direction: row; flex-wrap: wrap; margin-top: 12px; }
  .history-action { width: auto; padding: 10px 14px; border: 1px solid var(--border); border-radius: 999px; font-size: 14px; }
  .history-main h1 { margin-top: 18px; }
}
@media (max-width: 760px) {
  .history-page { padding-inline: 14px; }
  .history-item { grid-template-columns: 150px minmax(0, 1fr) auto; gap: 12px; }
  .history-title { font-size: 15px; }
  .history-desc { display: none; }
  .hs-card { flex-basis: 190px; width: 190px; }
  .hs-title { font-size: 14px; }
}
@media (max-width: 480px) {
  .history-item { grid-template-columns: 124px minmax(0, 1fr) auto; gap: 10px; }
  .history-title { font-size: 14px; }
}
CSS;

vs_page_start('Watch history', 'history', $extraCss);
?>
<link rel="stylesheet" href="search-suggest.css">
<script src="search-suggest.js"></script>
<div class="history-page">
  <div class="history-layout">

    <div class="history-main">
      <h1>Watch history</h1>

      <nav class="history-chips" aria-label="Filter watch history">
        <a class="history-chip<?= $type === 'all' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'all')) ?>">All</a>
        <a class="history-chip<?= $type === 'videos' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'videos')) ?>">Videos</a>
        <a class="history-chip<?= $type === 'shorts' ? ' active' : '' ?>" href="<?= $h($historyUrl($search, 'shorts')) ?>">Shorts</a>
      </nav>

      <?php if ($errorMessage !== ''): ?>
        <div class="history-alert" role="alert"><?= $h($errorMessage) ?></div>
      <?php endif; ?>
      <?php if ($successMessage !== ''): ?>
        <div class="history-alert history-success" role="status"><?= $h($successMessage) ?></div>
      <?php endif; ?>
      <?php if ($paused): ?>
        <div class="history-alert history-paused" role="status">Watch history is paused. Videos and Shorts you watch are not being saved.</div>
      <?php endif; ?>

      <?php if (!$entries && $errorMessage === ''): ?>
        <div class="history-empty">
          <?php if ($search !== ''): ?>
            <h2>No results for &ldquo;<?= $h($search) ?>&rdquo;</h2>
            <p>Try a different title or channel name.</p>
          <?php elseif ($type !== 'all'): ?>
            <h2>No <?= $type === 'shorts' ? 'Shorts' : 'videos' ?> in your history</h2>
            <p>Switch to &ldquo;All&rdquo; to see everything you watched.</p>
          <?php else: ?>
            <h2>Nothing watched yet</h2>
            <p>Videos and Shorts you watch will show up here.</p>
          <?php endif; ?>
          <a class="create-btn" href="index.php">Explore videos</a>
        </div>
      <?php endif; ?>

      <?php foreach ($groups as $group): ?>
        <h2 class="history-day"><?= $h($group['label']) ?></h2>

        <?php if ($group['shorts']): ?>
          <section class="hs-shelf" aria-label="Shorts you watched">
            <div class="hs-shelf-head">
              <span class="hs-ic" aria-hidden="true"></span>
              <h3>Shorts</h3>
            </div>
            <div class="hs-rowwrap">
              <div class="hs-row">
                <?php foreach ($group['shorts'] as $entry):
                    $row = $entry['video'];
                    $videoId = (string)($row['id'] ?? '');
                    $href = 'shorts.php?id=' . urlencode($videoId);
                    $title = trim((string)($row['title'] ?? '')) ?: 'Untitled short';
                    $views = (int)($row['views'] ?? 0);
                    $thumb = vs_history_thumb($row);
                ?>
                  <article class="hs-card">
                    <a class="hs-thumb" href="<?= $h($href) ?>" aria-label="Watch <?= $h($title) ?>">
                      <?php if ($thumb !== ''): ?>
                        <img src="<?= $h($thumb) ?>" alt="" loading="lazy">
                      <?php else: ?>
                        <span class="hs-thumb-empty"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
                      <?php endif; ?>
                    </a>
                    <div class="hs-cap">
                      <a href="<?= $h($href) ?>"><h3 class="hs-title"><?= $h($title) ?></h3></a>
                      <?= $itemMenu($videoId, $title, true) ?>
                    </div>
                    <p class="hs-views"><?= $h(function_exists('short_num') ? short_num($views) : number_format($views)) ?> views</p>
                  </article>
                <?php endforeach; ?>
              </div>
              <button type="button" class="hs-next" aria-label="Next Shorts"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 5 7 7-7 7"/></svg></button>
            </div>
          </section>
        <?php endif; ?>

        <?php foreach ($group['videos'] as $entry):
            $row = $entry['video'];
            $videoId = (string)($row['id'] ?? '');
            $href = 'watch.php?id=' . urlencode($videoId);
            $title = trim((string)($row['title'] ?? '')) ?: 'Untitled video';
            $channel = trim((string)($row['channel_name'] ?? ''));
            if ($channel === '') {
                $channel = trim((string)($row['username'] ?? '')) ?: 'Unknown channel';
            }
            $profileName = trim((string)($row['username'] ?? '')) ?: $channel;
            $views = (int)($row['views'] ?? 0);
            $description = trim((string)($row['description'] ?? ''));
            $thumb = vs_history_thumb($row);
        ?>
          <article class="history-item">
            <a class="history-thumb" href="<?= $h($href) ?>" aria-label="Watch <?= $h($title) ?>">
              <?php if ($thumb !== ''): ?>
                <img src="<?= $h($thumb) ?>" alt="" loading="lazy">
              <?php else: ?>
                <span class="history-thumb-empty"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>
              <?php endif; ?>
            </a>
            <div class="history-info">
              <a href="<?= $h($href) ?>"><h3 class="history-title"><?= $h($title) ?></h3></a>
              <p class="history-meta">
                <a href="profile.php?u=<?= urlencode($profileName) ?>"><?= $h($channel) ?></a>
                &middot; <?= $h(function_exists('short_num') ? short_num($views) : number_format($views)) ?> views
              </p>
              <?php if ($description !== ''): ?>
                <p class="history-desc"><?= $h($description) ?></p>
              <?php endif; ?>
            </div>
            <?= $itemMenu($videoId, $title, false) ?>
          </article>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <aside class="history-side" aria-label="Watch history options">
      <form class="history-search" method="get" action="history.php" role="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="<?= $h($search) ?>" placeholder="Search watch history" aria-label="Search watch history" autocomplete="off" data-vs-suggest-input="history">
        <?php if ($type !== 'all'): ?><input type="hidden" name="type" value="<?= $h($type) ?>"><?php endif; ?>
      </form>

      <div class="history-actions">
        <form method="post" onsubmit="return confirm('Clear your entire watch history?');">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="clear">
          <button type="submit" class="history-action">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>
            Clear all watch history
          </button>
        </form>

        <form method="post">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="<?= $paused ? 'resume' : 'pause' ?>">
          <input type="hidden" name="q" value="<?= $h($search) ?>">
          <input type="hidden" name="type" value="<?= $h($type) ?>">
          <button type="submit" class="history-action">
            <?php if ($paused): ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
              Turn on watch history
            <?php else: ?>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="4" width="4.5" height="16" rx="1.2"/><rect x="14.5" y="4" width="4.5" height="16" rx="1.2"/></svg>
              Pause watch history
            <?php endif; ?>
          </button>
        </form>
      </div>
    </aside>

  </div>
</div>
<script>
(function () {
  // Shorts shelf: the round arrow scrolls the row and hides itself at the end
  document.querySelectorAll('.hs-rowwrap').forEach(function (wrap) {
    var row = wrap.querySelector('.hs-row'), next = wrap.querySelector('.hs-next');
    if (!row || !next) return;
    function update() {
      next.classList.toggle('show', row.scrollWidth - row.clientWidth - row.scrollLeft > 6);
    }
    next.addEventListener('click', function () {
      row.scrollBy({ left: Math.max(row.clientWidth * 0.8, 260), behavior: 'smooth' });
    });
    row.addEventListener('scroll', update, { passive: true });
    window.addEventListener('resize', update);
    update();
  });

  // Three-dot menus: only one open at a time, close when clicking elsewhere
  document.addEventListener('click', function (e) {
    var open = document.querySelectorAll('.hm[open]');
    open.forEach(function (d) { if (!d.contains(e.target)) d.removeAttribute('open'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') document.querySelectorAll('.hm[open]').forEach(function (d) { d.removeAttribute('open'); });
  });
})();
</script>
<?php
vs_page_end();