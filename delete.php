<?php

require_once __DIR__ . '/layout.php';   
include __DIR__ . '/connection.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli('localhost', 'root', '', 'vibestream');
        if ($conn->connect_errno) {
            die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
        }
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

function back_to_profile($type, $text) {
    global $conn;
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
    if (isset($conn) && $conn instanceof mysqli) { $conn->close(); }
    header('Location: profile.php');
    exit;
}

function delete_local_media($ref) {
    $ref = trim((string)$ref);
    if ($ref === '' || preg_match('#^(https?:)?//#i', $ref)) return; 
    $ref = strtok($ref, '?#');
    if ($ref === false || $ref === '') return;

    $root = realpath(__DIR__);
    foreach ([$ref, __DIR__ . '/' . ltrim($ref, '/\\')] as $candidate) {
        $real = @realpath($candidate);
        if (!$real || !$root || !is_file($real)) continue;
        if (strncasecmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) continue;
        if (!preg_match('/\.(mp4|webm|mov|m4v|mkv|avi|jpg|jpeg|png|webp|gif)$/i', $real)) continue;
        @unlink($real);
        return;
    }
}

/* ---------- Checks ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    back_to_profile('err', 'Invalid request.');
}

$viewer = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
if ($viewer === '') {
    back_to_profile('err', 'Please sign in first.');
}

$csrf = (string)($_SESSION['csrf'] ?? '');
if ($csrf === '' || !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    back_to_profile('err', 'That request was not allowed. Reload the page and try again.');
}

$id = trim((string)($_POST['id'] ?? ''));
if ($id === '') {
    back_to_profile('err', 'No video selected.');
}

try {
    $stmt = $conn->prepare('SELECT * FROM videos WHERE id = ? AND username = ? LIMIT 1');
    if (!$stmt) back_to_profile('err', 'Could not delete that upload.');
    $stmt->bind_param('ss', $id, $viewer);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        back_to_profile('err', 'That upload was not found (or it is not yours).');
    }

    $files = [];
    $src = find_source($row, __DIR__);
    if ($src) $files[] = $src;
    foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'poster'] as $k) {
        if (!empty($row[$k])) $files[] = (string)$row[$k];
    }

    $del = $conn->prepare('DELETE FROM videos WHERE id = ? AND username = ?');
    if (!$del) back_to_profile('err', 'Could not delete that upload.');
    $del->bind_param('ss', $id, $viewer);
    $del->execute();
    $gone = $del->affected_rows > 0;
    $del->close();

    if (!$gone) {
        back_to_profile('err', 'Could not delete that upload.');
    }

    foreach (array_unique($files) as $f) delete_local_media($f);

    $label = (($row['type'] ?? '') === 'short') ? 'Short' : 'Video';
    back_to_profile('ok', $label . ' deleted.');

} catch (Throwable $e) {
    back_to_profile('err', 'Could not delete that upload.');
}
