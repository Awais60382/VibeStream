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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Video/Short - VibeStream</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
</head>
<body>
    <div class="container my-5">
        <h2 class="text-center">Edit Video / Short</h2>
        <hr class="w-50 mx-auto">
        <div class="w-50 mx-auto">
            <form action="code.php" method="POST">
                <!-- The ID is the record key, so it is sent as hidden and shown read-only -->
                <input type="hidden" name="id" value="<?php echo htmlspecialchars($row['id']); ?>">
                <div class="mb-3">
                    <label>Video ID / URL</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['id']); ?>" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label>Video Title</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['title']); ?>" name="title" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label>Type</label>
                    <?php $currentType = $row['type'] ?? 'video'; ?>
                    <select name="type" class="form-select">
                        <option value="video" <?php echo ($currentType === 'video') ? 'selected' : ''; ?>>Video</option>
                        <option value="short" <?php echo ($currentType === 'short') ? 'selected' : ''; ?>>Short</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label>Uploader</label>
                    <input type="text" value="<?php echo htmlspecialchars($row['username'] ?? 'Admin'); ?>" class="form-control" readonly>
                </div>
                <div class="text-center">
                    <input type="submit" value="Update" name="update_video" class="btn btn-dark w-50">
                </div>
            </form>
        </div>
        <div class="text-center mt-4">
            <a href="videos.php" class="btn btn-secondary">Back to Manage Videos</a>
        </div>
    </div>
</body>
</html>