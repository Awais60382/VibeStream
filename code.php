<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Same admin check as videos.php, so nobody can call this file directly
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

// connection.php creates the PDO object: $pdo
include_once "../connection.php";

// ---------------------------------------------------------------
// Create Video/Short -> redirects to index.php on success
// ---------------------------------------------------------------
if (isset($_POST['create_video'])) {
    $id       = trim($_POST['id'] ?? '');
    $title    = trim($_POST['title'] ?? '');
    $type     = trim($_POST['type'] ?? 'video');
    $username = trim($_POST['username'] ?? 'admin');

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO videos (id, title, type, username, views) VALUES (?, ?, ?, ?, 0)"
        );
        $stmt->execute([$id, $title, $type, $username]);

        echo "<script>alert('Video added successfully'); window.location.href='index.php';</script>";
        exit;
    } catch (PDOException $e) {
        // error_log($e->getMessage()); // uncomment to log the real reason
        echo "<script>alert('Error adding video'); window.location.href='addvideos.php';</script>";
        exit;
    }
}

// ---------------------------------------------------------------
// Update Video/Short -> redirects to index.php on success
// ---------------------------------------------------------------
if (isset($_POST['update_video'])) {
    $id    = trim($_POST['id'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $type  = trim($_POST['type'] ?? 'video');

    try {
        $stmt = $pdo->prepare("UPDATE videos SET title = ?, type = ? WHERE id = ?");
        $stmt->execute([$title, $type, $id]);

        echo "<script>alert('Video updated successfully'); window.location.href='index.php';</script>";
        exit;
    } catch (PDOException $e) {
        echo "<script>alert('Error updating video'); window.location.href='index.php';</script>";
        exit;
    }
}

// ---------------------------------------------------------------
// Delete Video/Short -> redirects to index.php on success
// ---------------------------------------------------------------
if (isset($_GET['deleteid'])) {
    $id = $_GET['deleteid'];

    try {
        // Transaction: either everything is deleted or nothing is
        $pdo->beginTransaction();

        // Remove child rows first to avoid foreign key constraint errors
        $pdo->prepare("DELETE FROM video_likes WHERE video_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM video_comments WHERE video_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM videos WHERE id = ?")->execute([$id]);

        $pdo->commit();

        echo "<script>alert('Video deleted successfully'); window.location.href='index.php';</script>";
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo "<script>alert('Error deleting video'); window.location.href='index.php';</script>";
        exit;
    }
}
?>