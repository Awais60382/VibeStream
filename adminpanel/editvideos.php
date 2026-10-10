<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Same admin check as the other admin pages
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

// connection.php creates the PDO object: $pdo
include_once "../connection.php";

$id = $_GET['editid'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "<script>alert('Video not found.'); window.location.href='videos.php';</script>";
    exit;
}
?>

<?php include "header.php"; ?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
              <div>
                <h1>Edit Video / Short</h1>
                <p class="text-muted mb-0">Update the title or type of this upload.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a href="videos.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> Back to Manage Videos</a>
            </div>
          </div>

          <section class="panel vs-form-card">
            <form action="code.php" method="POST">
              <!-- The ID is the record key, so it is sent as hidden and shown read-only -->
              <input type="hidden" name="id" value="<?php echo htmlspecialchars($row['id']); ?>">
              <div class="mb-3">
                <label class="form-label">Video ID / URL</label>
                <input type="text" value="<?php echo htmlspecialchars($row['id']); ?>" class="form-control" readonly>
              </div>
              <div class="mb-3">
                <label class="form-label">Video Title</label>
                <input type="text" value="<?php echo htmlspecialchars($row['title']); ?>" name="title" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Type</label>
                <?php $currentType = $row['type'] ?? 'video'; ?>
                <select name="type" class="form-select">
                  <option value="video" <?php echo ($currentType === 'video') ? 'selected' : ''; ?>>Video</option>
                  <option value="short" <?php echo ($currentType === 'short') ? 'selected' : ''; ?>>Short</option>
                </select>
              </div>
              <div class="mb-4">
                <label class="form-label">Uploader</label>
                <input type="text" value="<?php echo htmlspecialchars($row['username'] ?? 'Admin'); ?>" class="form-control" readonly>
              </div>
              <div class="text-center">
                <input type="submit" value="Update" name="update_video" class="btn btn-primary w-50">
              </div>
            </form>
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
