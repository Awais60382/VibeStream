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
            }
            $back = 'history.php';
            $backQuery = trim((string)($_POST['q'] ?? ''));
            if ($backQuery !== '') {
                $back .= '?q=' . urlencode($backQuery);
            }
            header('Location: ' . $back);
            exit;
        } catch (Throwable $error) {
            error_log('Watch history update failed: ' . $error->getMessage());
            $errorMessage = 'Could not update your watch history. Please try again.';
        }
    }
}

$successMessage = (string)($_SESSION['history_notice'] ?? '');
unset($_SESSION['history_notice']);

$search = trim((string)($_GET['q'] ?? ''));
$entries = [];
try {
    $entries = vs_history_fetch($conn, $userId, $search, 200);
} catch (Throwable $error) {
    error_log('Watch history load failed: ' . $error->getMessage());
    $errorMessage = $errorMessage !== '' ? $errorMessage : 'Could not load your watch history.';
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

// Group by day: Today / Yesterday / "Friday, Oct 9"
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
        $groups[$key] = ['label' => $label, 'items' => []];
    }
    $groups[$key]['items'][] = $entry;
}

$extraCss = <<<'CSS'
.history-page { max-width: 1040px; margin: 0 auto; padding: 22px 18px 56px; }
.history-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 20px; }
.history-head h1 { margin: 0; font-size: clamp(26px, 4vw, 34px); letter-spacing: -0.03em; }
.history-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
.history-search { display: flex; align-items: center; gap: 8px; padding: 0 12px; height: 38px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-2); }
.history-search svg { width: 17px; height: 17px; flex: 0 0 17px; color: var(--muted); }
.history-search input { width: 190px; max-width: 46vw; border: 0; outline: 0; background: transparent; color: var(--text); font-size: 14px; }
.history-clear { height: 38px; padding: 0 16px; border: 1px solid var(--border); border-radius: 999px; background: transparent; color: var(--text); font-size: 14px; font-weight: 600; cursor: pointer; }
.history-clear:hover { background: var(--surface-2); color: #e56a79; }
.history-day { margin: 26px 0 10px; font-size: 18px; font-weight: 700; }
.history-item { position: relative; display: grid; grid-template-columns: 246px minmax(0, 1fr) auto; gap: 16px; align-items: start; padding: 8px 0; }
.history-thumb { position: relative; display: block; aspect-ratio: 16 / 9; overflow: hidden; border-radius: 12px; background: var(--surface-2); }
.history-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.history-thumb-empty { display: grid; place-items: center; width: 100%; height: 100%; color: var(--muted); background: linear-gradient(135deg, #25252c, #17171b); }
.history-thumb-empty svg { width: 38px; height: 38px; }
.history-badge { position: absolute; right: 6px; bottom: 6px; padding: 2px 6px; border-radius: 4px; background: rgba(0, 0, 0, .8); color: #fff; font-size: 11px; font-weight: 700; letter-spacing: .04em; }
.history-info { min-width: 0; padding-top: 2px; }
.history-title { display: -webkit-box; overflow: hidden; margin: 0 0 6px; color: var(--text); font-size: 18px; font-weight: 600; line-height: 1.3; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.history-info a:hover .history-title { text-decoration: underline; }
.history-meta { margin: 0 0 8px; color: var(--muted); font-size: 12.5px; }
.history-meta a:hover { color: var(--text); }
.history-desc { display: -webkit-box; overflow: hidden; margin: 0; color: var(--muted); font-size: 12.5px; line-height: 1.45; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.history-remove { display: grid; place-items: center; width: 36px; height: 36px; border: 0; border-radius: 50%; background: transparent; color: var(--text); cursor: pointer; }
.history-remove:hover { background: var(--surface-2); }
.history-remove svg { width: 20px; height: 20px; }
.history-alert, .history-empty { margin: 0 0 18px; padding: 14px 20px; border-radius: 12px; }
.history-alert { background: rgba(220, 60, 60, .14); color: #ff9b9b; }
.history-success { background: rgba(45, 180, 120, .14); color: #278552; }
html[data-theme="dark"] .history-success { color: #8de2b4; }
.history-empty { padding: 46px 20px; border: 1px dashed var(--border); border-radius: 18px; text-align: center; color: var(--muted); }
.history-empty h2 { margin: 0 0 8px; color: var(--text); }
@media (max-width: 760px) {
  .history-page { padding-inline: 14px; }
  .history-item { grid-template-columns: 150px minmax(0, 1fr) auto; gap: 12px; }
  .history-title { font-size: 15px; }
  .history-desc { display: none; }
}
@media (max-width: 480px) {
  .history-item { grid-template-columns: 124px minmax(0, 1fr) auto; gap: 10px; }
  .history-title { font-size: 14px; }
  .history-search input { width: 120px; }
}
CSS;

vs_page_start('Watch history', 'history', $extraCss);
?>
<div class="history-page">
  <div class="history-head">
    <h1>Watch history</h1>
    <div class="history-tools">
      <form class="history-search" method="get" action="history.php" role="search">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="<?= $h($search) ?>" placeholder="Search watch history" aria-label="Search watch history">
      </form>
      <?php if ($entries || $search !== ''): ?>
        <form method="post" onsubmit="return confirm('Clear your entire watch history?');">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="clear">
          <button type="submit" class="history-clear">Clear all watch history</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($errorMessage !== ''): ?>
    <div class="history-alert" role="alert"><?= $h($errorMessage) ?></div>
  <?php endif; ?>
  <?php if ($successMessage !== ''): ?>
    <div class="history-alert history-success" role="status"><?= $h($successMessage) ?></div>
  <?php endif; ?>

  <?php if (!$entries && $errorMessage === ''): ?>
    <div class="history-empty">
      <?php if ($search !== ''): ?>
        <h2>No results for &ldquo;<?= $h($search) ?>&rdquo;</h2>
        <p>Try a different title or channel name.</p>
      <?php else: ?>
        <h2>Nothing watched yet</h2>
        <p>Videos and Shorts you watch will show up here.</p>
      <?php endif; ?>
      <a class="create-btn" href="index.php">Explore videos</a>
    </div>
  <?php endif; ?>

  <?php foreach ($groups as $group): ?>
    <h2 class="history-day"><?= $h($group['label']) ?></h2>
    <?php foreach ($group['items'] as $entry):
        $row = $entry['video'];
        $videoId = (string)($row['id'] ?? '');
        $isShort = (($row['type'] ?? '') === 'short');
        $href = ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . urlencode($videoId);
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
          <?php if ($isShort): ?><span class="history-badge">SHORTS</span><?php endif; ?>
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
        <form method="post">
          <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="video_id" value="<?= $h($videoId) ?>">
          <input type="hidden" name="q" value="<?= $h($search) ?>">
          <button type="submit" class="history-remove" title="Remove from watch history" aria-label="Remove <?= $h($title) ?> from watch history">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
          </button>
        </form>
      </article>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<?php
vs_page_end();
