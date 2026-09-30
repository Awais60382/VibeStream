<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Video/Short - VibeStream</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
</head>
<body>
    <div class="container my-5">
        <h2 class="text-center text-primary">Add Video / Short</h2>
        <hr class="w-50 mx-auto">
        <div class="w-50 mx-auto bg-info p-5 rounded">
            <form action="code.php" method="POST">
                <div class="mb-3 form-floating">
                    <input type="text" name="id" placeholder="Video ID / URL" class="form-control" required>
                    <label>Video ID / URL</label>
                </div>
                <div class="mb-3 form-floating">
                    <input type="text" name="title" placeholder="Video Title" class="form-control" required>
                    <label>Video Title</label>
                </div>
                <div class="mb-3">
                    <label>Type</label>
                    <select name="type" class="form-select">
                        <option value="video" selected>Video</option>
                        <option value="short">Short</option>
                    </select>
                </div>
                <div class="mb-4 form-floating">
                    <input type="text" name="username" placeholder="Uploader" value="admin" class="form-control">
                    <label>Uploader</label>
                </div>
                <div class="text-center">
                    <input type="submit" value="Upload" name="create_video" class="btn btn-success w-50">
                </div>
            </form>
        </div>
        <div class="text-center mt-4">
            <a href="videos.php" class="btn btn-dark">Back to Manage Videos</a>
        </div>
    </div>
</body>
</html>