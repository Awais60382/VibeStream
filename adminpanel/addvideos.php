<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}
?>

<?php include "header.php"; ?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-plus-square" aria-hidden="true"></i></span>
              <div>
                <h1>Add Video / Short</h1>
                <p class="text-muted mb-0">Add a new video or short to VibeStream.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a href="videos.php" class="btn btn-light"><i class="bi bi-arrow-left"></i> Back to Manage Videos</a>
            </div>
          </div>

          <section class="panel vs-form-card">
            <form action="code.php" method="POST">
              <div class="mb-3">
                <label class="form-label">Video ID / URL</label>
                <input type="text" name="id" placeholder="Video ID / URL" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Video Title</label>
                <input type="text" name="title" placeholder="Video Title" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                  <option value="video" selected>Video</option>
                  <option value="short">Short</option>
                </select>
              </div>
              <div class="mb-4">
                <label class="form-label">Uploader</label>
                <input type="text" name="username" placeholder="Uploader" value="admin" class="form-control">
              </div>
              <div class="text-center">
                <input type="submit" value="Upload" name="create_video" class="btn btn-primary w-50">
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
