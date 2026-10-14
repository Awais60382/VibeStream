<?php
/**
 * Shared admin list for videos.php and shorts.php.
 * Set $CONTENT_KIND to 'video' or 'short' before including this file.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";

// CSRF token for the delete buttons (same token checkin.php uses)
if (empty($_SESSION['vs_csrf'])) {
    $_SESSION['vs_csrf'] = bin2hex(random_bytes(16));
}

$CONTENT_KIND = (isset($CONTENT_KIND) && $CONTENT_KIND === 'short') ? 'short' : 'video';
$isShortPage  = ($CONTENT_KIND === 'short');
$pageFile     = $isShortPage ? 'shorts.php' : 'videos.php';
$otherFile    = $isShortPage ? 'videos.php' : 'shorts.php';
$noun         = $isShortPage ? 'short' : 'video';
$nouns        = $isShortPage ? 'shorts' : 'videos';
$Nouns        = ucfirst($nouns);

$uploader = isset($_GET['uploader']) ? trim((string)$_GET['uploader']) : '';

$allContent = [];
$commentCounts = [];
// `id` is the video ID / URL text, so sort by the upload date when the table has one.
$vCols   = vs_cols($pdo, 'videos');
$dateCol = vs_pick($vCols, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date']);
$orderBy = $dateCol ? vs_q($dateCol) . ' DESC, id DESC' : 'id DESC';
try {
    if ($uploader !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM videos
             WHERE LOWER(COALESCE(NULLIF(TRIM(username), ''), 'admin')) = ?
             ORDER BY $orderBy"
        );
        $stmt->execute([mb_strtolower($uploader, 'UTF-8')]);
    } else {
        $stmt = $pdo->query("SELECT * FROM videos ORDER BY $orderBy");
    }
    $allContent = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $commentCounts = vs_comment_counts($pdo);
} catch (PDOException $e) {
    $allContent = [];
    $dbError = $e->getMessage();
}

// Keep only this page's kind: videos.php never lists shorts, shorts.php only lists shorts.
$rows = [];
$otherCount = 0;
foreach ($allContent as $row) {
    if (vs_is_short($row['type'] ?? 'video') === $isShortPage) {
        $rows[] = $row;
    } else {
        $otherCount++;
    }
}

// Totals for the summary cards (based on what is listed below).
$sum = ['views' => 0, 'liked' => 0, 'likes' => 0, 'commented' => 0, 'comments' => 0];
foreach ($rows as $row) {
    $likes = max(0, (int)($row['likes'] ?? 0));
    $cm    = (int)($commentCounts[(string)$row['id']] ?? 0);
    $sum['views'] += (int)($row['views'] ?? 0);
    if ($likes > 0) { $sum['liked']++; $sum['likes'] += $likes; }
    if ($cm > 0)    { $sum['commented']++; $sum['comments'] += $cm; }
}
$total = count($rows);

include "header.php";
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi <?php echo $isShortPage ? 'bi-phone' : 'bi-collection-play'; ?>" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Content Library</p>
                <h1 class="h3 mb-1">Manage <?php echo $Nouns; ?></h1>
                <p class="text-muted mb-0">Only <?php echo $nouns; ?> are listed here. Review, edit, and remove them, and see how many have been liked and commented on.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
              <a class="btn btn-outline-primary btn-sm" href="<?php echo $otherFile . ($uploader !== '' ? '?uploader=' . urlencode($uploader) : ''); ?>"><i class="bi <?php echo $isShortPage ? 'bi-collection-play' : 'bi-phone'; ?>" aria-hidden="true"></i> <?php echo $isShortPage ? 'Videos' : 'Shorts'; ?> (<?php echo (int)$otherCount; ?>)</a>
              <a class="btn btn-primary btn-sm" href="addvideos.php"><i class="bi bi-plus-square" aria-hidden="true"></i> Upload New</a>
            </div>
          </div>

          <?php if (isset($dbError)): ?>
            <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($dbError); ?></div>
          <?php endif; ?>

          <?php if ($uploader !== ''): ?>
            <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 mb-0">
              <span>Showing <?php echo $nouns; ?> by <strong><?php echo htmlspecialchars($uploader); ?></strong>.</span>
              <a class="btn btn-outline-secondary btn-sm" href="<?php echo $pageFile; ?>">Show all</a>
            </div>
          <?php endif; ?>

          <section class="row g-3 mt-1" aria-label="<?php echo $Nouns; ?> engagement">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top">
                  <span class="metric-label"><?php echo $Nouns; ?></span>
                  <span class="metric-icon"><i class="bi <?php echo $isShortPage ? 'bi-lightning-charge' : 'bi-collection-play'; ?>" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($total); ?></div>
                <div class="metric-meta"><span><?php echo number_format($sum['views']); ?> views in total</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top">
                  <span class="metric-label">Liked</span>
                  <span class="metric-icon"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($sum['liked']); ?> <small class="fs-6 text-muted">of <?php echo number_format($total); ?></small></div>
                <div class="metric-meta"><span><?php echo number_format($sum['likes']); ?> like<?php echo $sum['likes'] === 1 ? '' : 's'; ?> in total</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-success">
                <div class="metric-top">
                  <span class="metric-label">Commented</span>
                  <span class="metric-icon"><i class="bi bi-chat-left-text" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($sum['commented']); ?> <small class="fs-6 text-muted">of <?php echo number_format($total); ?></small></div>
                <div class="metric-meta"><span><?php echo number_format($sum['comments']); ?> comment<?php echo $sum['comments'] === 1 ? '' : 's'; ?> in total</span></div>
              </article>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top">
                  <span class="metric-label">Not engaged yet</span>
                  <span class="metric-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                </div>
                <?php
                $untouched = 0;
                foreach ($rows as $row) {
                    if (max(0, (int)($row['likes'] ?? 0)) === 0 && (int)($commentCounts[(string)$row['id']] ?? 0) === 0) { $untouched++; }
                }
                ?>
                <div class="metric-value"><?php echo number_format($untouched); ?></div>
                <div class="metric-meta"><span><?php echo $nouns; ?> with no likes or comments</span></div>
              </article>
            </div>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi <?php echo $isShortPage ? 'bi-phone' : 'bi-play-btn'; ?>" aria-hidden="true"></i><span><?php echo $uploader !== '' ? 'Uploads' : 'All ' . $Nouns; ?></span></h2>
                <p class="text-muted mb-0"><?php echo $total; ?> <?php echo $total === 1 ? $noun : $nouns; ?> <?php echo $uploader !== '' ? 'found' : 'total'; ?></p>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">#</th>
                    <th scope="col">Title</th>
                    <th scope="col"><?php echo $isShortPage ? 'Short' : 'Video'; ?> ID / URL</th>
                    <th scope="col" class="text-end">Views</th>
                    <th scope="col" class="text-end"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i> Likes</th>
                    <th scope="col" class="text-end"><i class="bi bi-chat-left-text" aria-hidden="true"></i> Comments</th>
                    <th scope="col">Uploader</th>
                    <th scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if ($total > 0):
                      $sno = 1;
                      foreach ($rows as $row):
                          $who = trim((string)($row["username"] ?? ''));
                          if ($who === '') { $who = 'Admin'; }
                          $likes = max(0, (int)($row['likes'] ?? 0));
                          $cm    = (int)($commentCounts[(string)$row['id']] ?? 0);
                  ?>
                  <tr>
                    <td class="text-muted"><?php echo $sno++; ?></td>
                    <td class="fw-semibold"><?php echo htmlspecialchars((string)($row["title"] ?? '')); ?></td>
                    <td><code><?php echo htmlspecialchars((string)$row["id"]); ?></code></td>
                    <td class="text-end"><?php echo number_format((int)($row["views"] ?? 0)); ?></td>
                    <td class="text-end"><?php echo $likes > 0 ? '<span class="badge text-bg-danger">' . number_format($likes) . '</span>' : '<span class="text-muted">0</span>'; ?></td>
                    <td class="text-end"><?php echo $cm > 0 ? '<span class="badge text-bg-success">' . number_format($cm) . '</span>' : '<span class="text-muted">0</span>'; ?></td>
                    <td><a class="text-decoration-none" href="<?php echo $pageFile; ?>?uploader=<?php echo urlencode(mb_strtolower($who, 'UTF-8')); ?>"><?php echo htmlspecialchars($who); ?></a></td>
                    <td class="text-end">
                      <a href="editvideos.php?editid=<?php echo urlencode((string)$row["id"]); ?>" class="btn btn-light btn-sm" title="Edit">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </a>
                      <form action="code.php" method="POST" class="d-inline"
                            onsubmit="return confirm('Are you sure you want to delete this <?php echo $noun; ?>?');">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['vs_csrf']); ?>">
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars((string)$row["id"]); ?>">
                        <button type="submit" name="delete_video" value="1" class="btn btn-light btn-sm text-danger" title="Delete">
                          <i class="fa-solid fa-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                  <?php
                      endforeach;
                  else: ?>
                  <tr><td colspan="8" class="text-center text-muted py-4">No <?php echo $nouns; ?> found. <a href="addvideos.php">Upload some!</a></td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </section>
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