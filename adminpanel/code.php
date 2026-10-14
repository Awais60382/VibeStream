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
require_once __DIR__ . '/admin-activity-store.php';   // vs_admin_log()

// Short text for log lines (keeps titles from flooding the 500-char details column)
function vs_log_clip(string $s, int $max = 80): string
{
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
}

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

        vs_admin_log($pdo, 'video', 'Added ' . $type . ' "' . vs_log_clip($title) . '" (ID ' . vs_log_clip($id, 60) . ') for ' . $username);

        if (isset($conn) && $conn instanceof mysqli) {
            require_once __DIR__ . '/../notifications-store.php';
            vs_notify_subscribers($conn, $username, $type, $id, $title);
        }

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
        // Read the old values first so the log can say what changed
        $old = $pdo->prepare("SELECT title, type FROM videos WHERE id = ?");
        $old->execute([$id]);
        $before = $old->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'type' => ''];

        $stmt = $pdo->prepare("UPDATE videos SET title = ?, type = ? WHERE id = ?");
        $stmt->execute([$title, $type, $id]);

        $changes = [];
        if ((string)$before['title'] !== $title) {
            $changes[] = 'title "' . vs_log_clip((string)$before['title'], 60) . '" → "' . vs_log_clip($title, 60) . '"';
        }
        if ((string)$before['type'] !== $type) {
            $changes[] = 'type ' . $before['type'] . ' → ' . $type;
        }
        vs_admin_log(
            $pdo,
            'video',
            'Edited ' . $type . ' ID ' . vs_log_clip($id, 60) . ($changes ? ': ' . implode(', ', $changes) : ' (no changes)')
        );

        echo "<script>alert('Video updated successfully'); window.location.href='index.php';</script>";
        exit;
    } catch (PDOException $e) {
        echo "<script>alert('Error updating video'); window.location.href='index.php';</script>";
        exit;
    }
}

// ---------------------------------------------------------------
// Delete Video/Short (POST + CSRF token only) -> redirects to index.php
// A plain link like code.php?deleteid=... no longer works, so another
// page cannot trigger a delete just by embedding a link or image.
// ---------------------------------------------------------------
if (isset($_POST['delete_video'])) {
    $id   = trim((string)($_POST['id'] ?? ''));
    $csrf = (string)($_POST['csrf'] ?? '');

    if (empty($_SESSION['vs_csrf']) || !hash_equals((string)$_SESSION['vs_csrf'], $csrf)) {
        echo "<script>alert('Session expired. Please reload the page and try again.'); window.location.href='index.php';</script>";
        exit;
    }

    try {
        // Remember what is being deleted for the log
        $old = $pdo->prepare("SELECT title, type FROM videos WHERE id = ?");
        $old->execute([$id]);
        $gone = $old->fetch(PDO::FETCH_ASSOC) ?: ['title' => '', 'type' => 'video'];

        // Transaction: either everything is deleted or nothing is
        $pdo->beginTransaction();

        // Remove child rows first to avoid foreign key constraint errors
        $pdo->prepare("DELETE FROM video_likes WHERE video_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM video_comments WHERE video_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM videos WHERE id = ?")->execute([$id]);

        $pdo->commit();

        vs_admin_log($pdo, 'video', 'Deleted ' . $gone['type'] . ' "' . vs_log_clip((string)$gone['title']) . '" (ID ' . vs_log_clip($id, 60) . ')');

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