<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../site-branding.php';

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['admin_csrf'];

$fatal = '';
$flash = '';
$rows  = [];
$hasCat = false;
$hasType = false;
$hasShortsTable = false;
$showTypeFilter = false;
$counts = [];
$catFilter = '';
$stats = ['all' => 0, 'tagged' => 0, 'shorts' => 0];
$allowed = [];
$status = 'uncategorized';
$type = 'all';

if (!isset($conn) || !($conn instanceof mysqli)) {
    $fatal = 'No database connection found. Check that ../connection.php creates $conn.';
} else {
    $colExists = static function (mysqli $c, string $table, string $col): bool {
        $t = $c->real_escape_string($table);
        $k = $c->real_escape_string($col);
        $r = $c->query("SHOW COLUMNS FROM `$t` LIKE '$k'");
        return $r && $r->num_rows > 0;
    };
    $tableExists = static function (mysqli $c, string $table): bool {
        $k = $c->real_escape_string($table);
        $r = $c->query("SHOW TABLES LIKE '$k'");
        return $r && $r->num_rows > 0;
    };

    $names = [];
    try {
        $names = (array)vs_default_categories();
    } catch (Throwable $e) {
        $names = [];
    }
    if (!$names) {
        $names = ['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education', 'Anime'];
    }
    foreach ($names as $n) {
        $allowed[strtolower(trim((string)$n))] = trim((string)$n);
    }

    $catFilter = strtolower(trim((string)($_GET['cat'] ?? '')));
    if ($catFilter !== '' && $catFilter !== '__none' && !isset($allowed[$catFilter])) {
        $catFilter = '';
    }
    $status = (string)($_GET['show'] ?? ($catFilter !== '' ? 'all' : 'uncategorized'));
    if (!in_array($status, ['uncategorized', 'tagged', 'all'], true)) {
        $status = 'uncategorized';
    }
    $type = (string)($_GET['type'] ?? 'all');
    if (!in_array($type, ['all', 'video', 'short'], true)) {
        $type = 'all';
    }

    try {
        // Where videos and shorts live: the `videos` table (with an optional `type` column)
        // and, if you have one, a separate `shorts` table.
        $sources = ['v' => ['table' => 'videos', 'kind' => 'mixed']];
        if ($tableExists($conn, 'shorts') && $colExists($conn, 'shorts', 'id') && $colExists($conn, 'shorts', 'title')) {
            $sources['s'] = ['table' => 'shorts', 'kind' => 'short'];
        }
        foreach ($sources as $k => $s) {
            $sources[$k]['cat']  = $colExists($conn, $s['table'], 'category');
            $sources[$k]['type'] = $s['kind'] === 'mixed' && $colExists($conn, $s['table'], 'type');
            $sources[$k]['desc'] = $colExists($conn, $s['table'], 'description');
        }
        $hasCat = true;
        foreach ($sources as $s) {
            if (!$s['cat']) {
                $hasCat = false;
            }
        }
        $hasType = $sources['v']['type'];
        $hasShortsTable = isset($sources['s']);
        $showTypeFilter = $hasType || $hasShortsTable;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $code = 'failed';
            if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
                $code = 'expired';
            } else {
                $action = (string)($_POST['action'] ?? '');
                if ($action === 'addcol') {
                    foreach ($sources as $s) {
                        if (!$s['cat']) {
                            $conn->query('ALTER TABLE `' . $s['table'] . '` ADD COLUMN `category` VARCHAR(40) NULL');
                        }
                    }
                    $code = 'colok';
                } elseif ($action === 'save' && $hasCat) {
                    $ups = [];
                    foreach ((array)($_POST['cat'] ?? []) as $key => $val) {
                        $parts = explode(':', (string)$key, 2);
                        if (count($parts) !== 2 || !isset($sources[$parts[0]])) {
                            continue;
                        }
                        $sk  = $parts[0];
                        $vid = $parts[1];
                        $val = strtolower(trim((string)$val));
                        if ($val === '__keep') {
                            continue;
                        }
                        $v = $val === '' ? null : ($allowed[$val] ?? null);
                        if ($val !== '' && $v === null) {
                            continue;
                        }
                        $v = $v === null ? null : strtolower($v);
                        if (!isset($ups[$sk])) {
                            $ups[$sk] = $conn->prepare('UPDATE `' . $sources[$sk]['table'] . '` SET `category` = ? WHERE `id` = ? LIMIT 1');
                        }
                        $ups[$sk]->bind_param('ss', $v, $vid);
                        $ups[$sk]->execute();
                    }
                    $code = 'saved';
                } elseif (in_array($action, ['auto', 'autoall'], true) && $hasCat) {
                    // first match wins, so put the most specific categories first
                    $keywords = [
                        'gaming'    => ['gaming', 'game', 'roblox', 'blox', 'minecraft', 'fortnite', 'gta', 'pubg', 'valorant', 'gameplay', 'speedrun'],
                        'anime'     => ['anime', 'naruto', 'one piece', 'luffy', 'goku', 'dragon ball', 'dbz', 'manga', 'otaku', 'doffy', 'jujutsu', 'demon slayer'],
                        'music'     => ['music', 'song', 'remix', 'lyrics', 'playlist', 'singing', 'guitar'],
                        'movies'    => ['movie', 'film', 'trailer', 'episode', 'netflix'],
                        'sports'    => ['sport', 'football', 'cricket', 'soccer', 'basketball'],
                        'news'      => ['news', 'breaking'],
                        'fashion'   => ['fashion', 'outfit'],
                        'fun'       => ['funny', 'prank', 'meme', 'comedy', 'hilarious', 'challenge', 'lol', 'for fun'],
                        'education' => ['learn', 'tutorial', 'lesson', 'education', 'how to'],
                        'live'      => ['livestream', 'live stream', 'live now'],
                    ];
                    $onlyEmpty = $action === 'auto';
                    foreach ($sources as $s) {
                        if (!$s['cat']) {
                            continue;
                        }
                        $descSel = $s['desc'] ? ', `description` AS d' : ", '' AS d";
                        $res = $conn->query('SELECT `id`, `title` AS t' . $descSel . ', `category` AS c FROM `' . $s['table'] . '`');
                        $up = $conn->prepare('UPDATE `' . $s['table'] . '` SET `category` = ? WHERE `id` = ? LIMIT 1');
                        while ($res && ($row = $res->fetch_assoc())) {
                            $current = strtolower(trim((string)$row['c']));
                            if ($onlyEmpty && $current !== '') {
                                continue;
                            }
                            $text = strtolower((string)$row['t'] . ' ' . (string)$row['d']);
                            $found = null;
                            foreach ($keywords as $cat => $words) {
                                if (!isset($allowed[$cat])) {
                                    continue;
                                }
                                foreach ($words as $w) {
                                    if (preg_match('/\b' . preg_quote($w, '/') . '/u', $text)) {
                                        $found = $cat;
                                        break 2;
                                    }
                                }
                            }
                            if ($found !== null && $found !== $current) {
                                $vid = (string)$row['id'];
                                $up->bind_param('ss', $found, $vid);
                                $up->execute();
                            }
                        }
                    }
                    $code = 'auto';
                }
            }
            header('Location: video-categories.php?show=' . urlencode($status) . '&type=' . urlencode($type)
                . ($catFilter !== '' ? '&cat=' . urlencode($catFilter) : '') . '&msg=' . urlencode($code));
            exit;
        }

        if ($hasCat) {
            // How many videos and shorts each category has.
            foreach ($sources as $s) {
                $shortExpr = $s['kind'] === 'short' ? '1' : ($s['type'] ? "(type = 'short')" : '0');
                $r = $conn->query("SELECT LOWER(TRIM(COALESCE(category, ''))) AS c, SUM($shortExpr) AS s, COUNT(*) AS n FROM `{$s['table']}` GROUP BY c");
                while ($r && ($x = $r->fetch_assoc())) {
                    $c = (string)$x['c'];
                    $n = (int)$x['n'];
                    $sh = (int)$x['s'];
                    if (!isset($counts[$c])) {
                        $counts[$c] = ['video' => 0, 'short' => 0];
                    }
                    $counts[$c]['video'] += $n - $sh;
                    $counts[$c]['short'] += $sh;
                    $stats['all'] += $n;
                    $stats['shorts'] += $sh;
                    if ($c !== '') {
                        $stats['tagged'] += $n;
                    }
                }
            }

            // The list itself.
            foreach ($sources as $sk => $s) {
                if ($type === 'video' && $s['kind'] === 'short') {
                    continue;
                }
                if ($type === 'short' && $s['kind'] === 'mixed' && !$s['type']) {
                    continue;
                }
                $where = [];
                if ($catFilter === '__none' || ($catFilter === '' && $status === 'uncategorized')) {
                    $where[] = "(category IS NULL OR TRIM(category) = '')";
                } elseif ($catFilter !== '') {
                    $where[] = "LOWER(TRIM(category)) = '" . $conn->real_escape_string($catFilter) . "'";
                } elseif ($status === 'tagged') {
                    $where[] = "(category IS NOT NULL AND TRIM(category) <> '')";
                }
                if ($s['kind'] === 'mixed' && $s['type'] && $type !== 'all') {
                    $where[] = $type === 'short' ? "type = 'short'" : "(type IS NULL OR type <> 'short')";
                }
                $typeSel = $s['kind'] === 'short' ? "'short' AS type" : ($s['type'] ? 'type' : "'' AS type");
                $sql = "SELECT id, title, $typeSel, category FROM `{$s['table']}`"
                     . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT 500';
                $r = $conn->query($sql);
                while ($r && ($x = $r->fetch_assoc())) {
                    $x['src'] = $sk;
                    $rows[] = $x;
                }
            }
            usort($rows, static function (array $a, array $b): int {
                return is_numeric($a['id']) && is_numeric($b['id']) ? ((int)$b['id'] <=> (int)$a['id']) : 0;
            });
            $rows = array_slice($rows, 0, 500);
        }
    } catch (Throwable $e) {
        error_log('video-categories failed: ' . $e->getMessage());
        $fatal = 'Something went wrong: ' . $e->getMessage();
    }
}

$msgs = [
    'saved'   => 'Categories saved.',
    'auto'    => 'Auto-tag finished. Check the list and fix anything that is wrong.',
    'colok'   => 'The category column is ready.',
    'expired' => 'Session expired. Try again.',
    'failed'  => 'Something went wrong. Please try again.',
];
$flash = $msgs[$_GET['msg'] ?? ''] ?? '';
$qs = static function (array $o): string {
    $q = array_merge(['show' => $GLOBALS['status'] ?? 'uncategorized', 'type' => $GLOBALS['type'] ?? 'all', 'cat' => $GLOBALS['catFilter'] ?? ''], $o);
    if (($q['cat'] ?? '') === '') {
        unset($q['cat']);
    }
    return 'video-categories.php?' . http_build_query($q);
};

include "header.php";
vs_print_styles();
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-tags" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Content</p>
                <h1 class="h3 mb-1">Video categories</h1>
                <p class="text-muted mb-0">Put each video and short in a category so it shows up under the right chip and sidebar item.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="categories.php">Manage categories</a>
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
          </div>

          <?php if ($flash !== ''): ?>
            <div class="alert alert-info mt-3 mb-0" role="status"><?php echo vs_e($flash); ?></div>
          <?php endif; ?>

          <?php if ($fatal !== ''): ?>
            <div class="alert alert-warning mt-3"><?php echo vs_e($fatal); ?></div>
          <?php elseif (!$hasCat): ?>
            <section class="panel mt-3">
              <p class="mb-3">Your videos<?php echo $hasShortsTable ? ' and shorts tables have' : ' table has'; ?> no <code>category</code> column yet. Click the button to add it (this is safe and does not change your videos or shorts).</p>
              <form method="post" action="video-categories.php">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <input type="hidden" name="action" value="addcol">
                <button type="submit" class="btn btn-primary">Add the category column</button>
              </form>
            </section>
          <?php else: ?>

          <div class="row g-3 mt-1">
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><p class="text-muted small mb-1">All videos and shorts</p><p class="h3 mb-0 vs-num"><?php echo number_format($stats['all']); ?></p></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><p class="text-muted small mb-1">With a category</p><p class="h3 mb-0 vs-num"><?php echo number_format($stats['tagged']); ?></p></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><p class="text-muted small mb-1">Still without one</p><p class="h3 mb-0 vs-num"><?php echo number_format($stats['all'] - $stats['tagged']); ?></p></div></div>
            <div class="col-6 col-xl-3"><div class="border rounded-3 p-3 h-100"><p class="text-muted small mb-1">Shorts</p><p class="h3 mb-0 vs-num"><?php echo number_format($stats['shorts']); ?></p></div></div>
          </div>

          <section class="panel mt-3" aria-label="Browse by category">
            <div class="panel-header"><h2 class="h6 mb-0">Browse by category</h2></div>
            <div class="d-flex flex-wrap gap-2 p-3">
              <a class="btn btn-sm <?php echo ($catFilter === '' && $status === 'all') ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['show' => 'all', 'cat' => ''])); ?>">
                All <span class="badge text-bg-light ms-1"><?php echo number_format($stats['all'] - $stats['shorts']); ?> videos &middot; <?php echo number_format($stats['shorts']); ?> shorts</span>
              </a>
              <?php foreach ($allowed as $slug => $label):
                  $cv = (int)($counts[$slug]['video'] ?? 0);
                  $cs = (int)($counts[$slug]['short'] ?? 0);
              ?>
                <a class="btn btn-sm <?php echo $catFilter === $slug ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['show' => 'all', 'cat' => $slug])); ?>">
                  <?php echo vs_e($label); ?> <span class="badge text-bg-light ms-1"><?php echo number_format($cv); ?> videos &middot; <?php echo number_format($cs); ?> shorts</span>
                </a>
              <?php endforeach; ?>
              <?php $nv = (int)($counts['']['video'] ?? 0); $ns = (int)($counts['']['short'] ?? 0); ?>
              <a class="btn btn-sm <?php echo $catFilter === '__none' ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['show' => 'all', 'cat' => '__none'])); ?>">
                No category <span class="badge text-bg-light ms-1"><?php echo number_format($nv); ?> videos &middot; <?php echo number_format($ns); ?> shorts</span>
              </a>
            </div>
          </section>

          <section class="panel mt-3" aria-label="Video categories">
            <div class="panel-header d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div class="d-flex flex-wrap gap-2">
                <div class="btn-group btn-group-sm" role="group" aria-label="Show">
                  <?php foreach (['uncategorized' => 'Without category', 'tagged' => 'With category', 'all' => 'All'] as $k => $l): ?>
                    <a class="btn <?php echo $status === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['show' => $k, 'cat' => ''])); ?>"><?php echo vs_e($l); ?></a>
                  <?php endforeach; ?>
                </div>
                <?php if ($showTypeFilter): ?>
                <div class="btn-group btn-group-sm" role="group" aria-label="Type">
                  <?php foreach (['all' => 'Videos and shorts', 'video' => 'Videos', 'short' => 'Shorts'] as $k => $l): ?>
                    <a class="btn <?php echo $type === $k ? 'btn-primary' : 'btn-outline-secondary'; ?>" href="<?php echo vs_e($qs(['type' => $k])); ?>"><?php echo vs_e($l); ?></a>
                  <?php endforeach; ?>
                </div>
                <?php endif; ?>
              </div>
              <div class="d-flex flex-wrap gap-2">
                <form method="post" action="<?php echo vs_e($qs([])); ?>" onsubmit="return confirm('Guess categories from titles for every video that has none?');">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                  <input type="hidden" name="action" value="auto">
                  <button type="submit" class="btn btn-outline-secondary btn-sm">Auto-tag untagged</button>
                </form>
                <form method="post" action="<?php echo vs_e($qs([])); ?>" onsubmit="return confirm('Re-tag ALL videos and shorts from their titles? This replaces categories you set by hand when a title matches.');">
                  <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                  <input type="hidden" name="action" value="autoall">
                  <button type="submit" class="btn btn-primary btn-sm">Auto-tag everything at once</button>
                </form>
              </div>
            </div>

            <?php if (!$rows): ?>
              <p class="text-center text-muted py-4 mb-0"><?php echo $catFilter !== '' ? 'Nothing here yet. No videos or shorts are in this category.' : ($status === 'uncategorized' ? 'Every video and short here already has a category.' : ($status === 'tagged' ? 'No videos or shorts have a category yet.' : 'No videos or shorts found.')); ?></p>
            <?php else: ?>
            <form method="post" action="<?php echo vs_e($qs([])); ?>">
              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
              <input type="hidden" name="action" value="save">
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">Title</th><th scope="col">Type</th><th scope="col" style="min-width:200px">Category</th></tr></thead>
                  <tbody>
                  <?php foreach ($rows as $r):
                      $cur = strtolower(trim((string)$r['category']));
                      $isShort = strtolower((string)$r['type']) === 'short';
                  ?>
                    <tr>
                      <td><?php echo vs_e($r['title'] !== '' ? $r['title'] : 'Untitled'); ?><p class="text-muted small mb-0">ID <?php echo vs_e($r['id']); ?></p></td>
                      <td><span class="badge <?php echo $isShort ? 'text-bg-info' : 'text-bg-primary'; ?>"><?php echo $isShort ? 'Short' : 'Video'; ?></span></td>
                      <td>
                        <select class="form-select form-select-sm" name="cat[<?php echo vs_e($r['src'] . ':' . $r['id']); ?>]">
                          <option value=""<?php echo $cur === '' ? ' selected' : ''; ?>>No category</option>
                          <?php foreach ($allowed as $slug => $label): ?>
                            <option value="<?php echo vs_e($slug); ?>"<?php echo $cur === $slug ? ' selected' : ''; ?>><?php echo vs_e($label); ?></option>
                          <?php endforeach; ?>
                          <?php if ($cur !== '' && !isset($allowed[$cur])): ?>
                            <option value="__keep" selected><?php echo vs_e($cur); ?> (not in your list)</option>
                          <?php endif; ?>
                        </select>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="mt-3 text-end"><button type="submit" class="btn btn-primary">Save categories</button></div>
            </form>
            <?php endif; ?>
          </section>
          <?php endif; ?>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>