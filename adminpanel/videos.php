<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";

$uploader = isset($_GET['uploader']) ? trim((string)$_GET['uploader']) : '';

try {
    if ($uploader !== '') {
        $stmt = $pdo->prepare(
            "SELECT * FROM videos
             WHERE LOWER(COALESCE(NULLIF(TRIM(username), ''), 'admin')) = ?
             ORDER BY id DESC"
        );
        $stmt->execute([mb_strtolower($uploader, 'UTF-8')]);
    } else {
        $stmt = $pdo->query("SELECT * FROM videos ORDER BY id DESC");
    }
    $videos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $videos = [];
    $dbError = $e->getMessage(); 
}

include "header.php";
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-collection-play" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Content Library</p>
                <h1 class="h3 mb-1">Manage Videos &amp; Shorts</h1>
                <p class="text-muted mb-0">Review, edit, and remove everything published to VibeStream.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
              <a class="btn btn-primary btn-sm" href="addvideos.php"><i class="bi bi-plus-square" aria-hidden="true"></i> Upload New</a>
            </div>
          </div>

          <?php if (isset($dbError)): ?>
            <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($dbError); ?></div>
          <?php endif; ?>

          <?php if ($uploader !== ''): ?>
            <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 mb-0">
              <span>Showing uploads by <strong><?php echo htmlspecialchars($uploader); ?></strong>.</span>
              <a class="btn btn-outline-secondary btn-sm" href="videos.php">Show all</a>
            </div>
          <?php endif; ?>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-camera-reels" aria-hidden="true"></i><span><?php echo $uploader !== '' ? 'Uploads' : 'All Content'; ?></span></h2>
                <p class="text-muted mb-0"><?php echo count($videos); ?> item<?php echo count($videos) === 1 ? '' : 's'; ?> <?php echo $uploader !== '' ? 'found' : 'total'; ?></p>
              </div>
            </div>
            <div class="table-responsive">
              <table class="table align-middle mb-0">
                <thead>
                  <tr>
                    <th scope="col">#</th>
                    <th scope="col">Title</th>
                    <th scope="col">Video ID / URL</th>
                    <th scope="col">Type</th>
                    <th scope="col">Views</th>
                    <th scope="col">Uploader</th>
                    <th scope="col" class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($videos) > 0):
                      $sno = 1;
                      foreach ($videos as $row):
                          $type = $row["type"] ?? 'video';
                          $badgeClass = $type === 'short' ? 'text-bg-info' : 'text-bg-primary';
                          $who = trim((string)($row["username"] ?? ''));
                          if ($who === '') { $who = 'Admin'; }
                  ?>
                  <tr>
                    <td class="text-muted"><?php echo $sno++; ?></td>
                    <td class="fw-semibold"><?php echo htmlspecialchars($row["title"]); ?></td>
                    <td><code><?php echo htmlspecialchars($row["id"]); ?></code></td>
                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($type)); ?></span></td>
                    <td><?php echo number_format((int)($row["views"] ?? 0)); ?></td>
                    <td><a class="text-decoration-none" href="videos.php?uploader=<?php echo urlencode(mb_strtolower($who, 'UTF-8')); ?>"><?php echo htmlspecialchars($who); ?></a></td>
                    <td class="text-end">
                      <a href="editvideos.php?editid=<?php echo urlencode($row["id"]); ?>" class="btn btn-light btn-sm" title="Edit">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </a>
                      <a href="code.php?deleteid=<?php echo urlencode($row["id"]); ?>" class="btn btn-light btn-sm text-danger" title="Delete"
                         onclick="return confirm('Are you sure you want to delete this video?');">
                        <i class="fa-solid fa-trash"></i>
                      </a>
                    </td>
                  </tr>
                  <?php
                      endforeach;
                  else: ?>
                  <tr><td colspan="7" class="text-center text-muted py-4">No videos found. <a href="addvideos.php">Upload some!</a></td></tr>
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