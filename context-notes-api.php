<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/context-notes-store.php';

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $data): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    $respond(405, ['success' => false, 'error' => 'Use POST to submit a context note.']);
}
if (empty($_SESSION['username'])) {
    $respond(401, ['success' => false, 'error' => 'Sign in to submit a context note.']);
}
if (!vs_csrf_valid($_POST['csrf_token'] ?? null)) {
    $respond(403, ['success' => false, 'error' => 'That request expired. Refresh the page and try again.']);
}

$videoIdValue = $_POST['video_id'] ?? '';
$typeValue = $_POST['content_type'] ?? '';
$noteValue = $_POST['note'] ?? '';
$sourceValue = $_POST['source_url'] ?? '';
if (!is_string($videoIdValue) || !is_string($typeValue) || !is_string($noteValue) || !is_string($sourceValue)) {
    $respond(400, ['success' => false, 'error' => 'Invalid context note data.']);
}
$videoId = trim($videoIdValue);
$contentType = trim($typeValue);
$note = trim($noteValue);
$sourceUrl = trim($sourceValue);
$author = trim((string)$_SESSION['username']);

if ($videoId === '' || strlen($videoId) > 64 || !in_array($contentType, ['video', 'short'], true)) {
    $respond(400, ['success' => false, 'error' => 'Choose a valid video or Short.']);
}
if (mb_strlen($note, 'UTF-8') < 30 || mb_strlen($note, 'UTF-8') > 1000) {
    $respond(400, ['success' => false, 'error' => 'Context notes must be 30–1,000 characters long.']);
}
if ($sourceUrl !== '' && (strlen($sourceUrl) > 500 || !filter_var($sourceUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $sourceUrl))) {
    $respond(400, ['success' => false, 'error' => 'Enter a valid HTTP or HTTPS source link.']);
}

try {
    $video = $conn->prepare('SELECT `type` FROM `videos` WHERE `id` = ? LIMIT 1');
    $video->bind_param('s', $videoId);
    $video->execute();
    $videoRow = $video->get_result()->fetch_assoc();
    $video->close();
    if (!$videoRow || (($contentType === 'short') !== (strtolower((string)($videoRow['type'] ?? '')) === 'short'))) {
        $respond(404, ['success' => false, 'error' => 'The selected content could not be found.']);
    }

    vs_context_notes_ensure($conn);
    $rateLimit = $conn->prepare(
        'SELECT COUNT(*) AS total FROM `video_context_notes`
         WHERE `author` = ? AND `created_at` >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $rateLimit->bind_param('s', $author);
    $rateLimit->execute();
    $recentCount = (int)($rateLimit->get_result()->fetch_assoc()['total'] ?? 0);
    $rateLimit->close();
    if ($recentCount >= 3) {
        $respond(429, ['success' => false, 'error' => 'You have reached the limit of three context notes per hour.']);
    }

    $duplicate = $conn->prepare(
        'SELECT 1 FROM `video_context_notes`
         WHERE `video_id` = ? AND `content_type` = ? AND `author` = ? AND `status` IN (\'pending\', \'approved\')
         LIMIT 1'
    );
    $duplicate->bind_param('sss', $videoId, $contentType, $author);
    $duplicate->execute();
    $hasDuplicate = $duplicate->get_result()->num_rows > 0;
    $duplicate->close();
    if ($hasDuplicate) {
        $respond(409, ['success' => false, 'error' => 'You already have a note awaiting review or approved for this content.']);
    }

    $insert = $conn->prepare(
        'INSERT INTO `video_context_notes` (`video_id`, `content_type`, `author`, `note`, `source_url`)
         VALUES (?, ?, ?, ?, ?)'
    );
    $insert->bind_param('sssss', $videoId, $contentType, $author, $note, $sourceUrl);
    $insert->execute();
    $insert->close();
    $respond(201, ['success' => true, 'message' => 'Your context note was submitted for moderator review.']);
} catch (Throwable $error) {
    error_log('Community context note submission failed: ' . $error->getMessage());
    $respond(500, ['success' => false, 'error' => 'Context notes are temporarily unavailable. Please try again later.']);
}
