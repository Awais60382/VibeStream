<?php
// Receives reports from the Report popup and saves them to `content_reports`,
// the table adminpanel/reported.php reads. Put this file in the SITE ROOT.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/report-widget.php';

function vs_rs_reply(bool $ok, string $message, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vs_rs_reply(false, 'Invalid request.', 405);
}
if (!hash_equals(vs_rw_csrf(), (string)($_POST['csrf'] ?? ''))) {
    vs_rs_reply(false, 'Your session expired. Refresh the page and try again.', 403);
}
// Hidden field that only bots fill in: pretend it worked.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    vs_rs_reply(true, 'Thanks, we will take a look.');
}

$reporter = vs_rw_reporter();
if ($reporter === '') {
    vs_rs_reply(false, 'Please log in to report a video.', 401);
}

$table   = (string)($_POST['content_table'] ?? '');
$type    = ($_POST['content_type'] ?? '') === 'short' ? 'short' : 'video';
$id      = trim((string)($_POST['content_id'] ?? ''));
$reason  = (string)($_POST['reason'] ?? '');
$details = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['details'] ?? '')));
$details = mb_substr($details, 0, 500);

if (!in_array($table, ['videos', 'shorts'], true) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $id)) {
    vs_rs_reply(false, 'We could not tell which video this is.', 422);
}
if (!isset(vs_rw_reasons()[$reason])) {
    vs_rs_reply(false, 'Pick a reason first.', 422);
}
if ($reason === 'other' && mb_strlen($details) < 3) {
    vs_rs_reply(false, 'Please tell us a little more.', 422);
}

if (!vs_rw_load_helpers() || !function_exists('vs_report_pdo')) {
    error_log('report-submit: report-helpers.php not found');
    vs_rs_reply(false, 'Reporting is unavailable right now.', 500);
}

try {
    $pdo = vs_report_pdo();
    if (!$pdo) {
        vs_rs_reply(false, 'Reporting is unavailable right now.', 500);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    vs_reports_ensure_table($pdo);

    // The video or short must still exist.
    if (!vs_reports_lookup($pdo, $table, $id)) {
        vs_rs_reply(false, 'That video no longer exists.', 404);
    }

    // Same member, same video, still open: do not stack duplicates.
    $q = $pdo->prepare("SELECT 1 FROM `content_reports` WHERE `reporter` = :r AND `content_table` = :t AND `content_id` = :i AND `status` = 'open' LIMIT 1");
    $q->execute([':r' => $reporter, ':t' => $table, ':i' => $id]);
    if ($q->fetchColumn()) {
        vs_rs_reply(true, 'You already reported this. We are looking into it.');
    }

    // At most 10 reports per hour per member.
    $q = $pdo->prepare('SELECT COUNT(*) FROM `content_reports` WHERE `reporter` = :r AND `created_at` > :since');
    $q->execute([':r' => $reporter, ':since' => date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$q->fetchColumn() >= 10) {
        vs_rs_reply(false, 'You have sent a lot of reports. Please try again later.', 429);
    }

    $ins = $pdo->prepare(
        "INSERT INTO `content_reports` (`content_table`, `content_id`, `content_type`, `reason`, `details`, `reporter`, `status`, `created_at`)
         VALUES (:t, :i, :ty, :re, :d, :r, 'open', :c)"
    );
    $ins->execute([
        ':t' => $table, ':i' => $id, ':ty' => $type, ':re' => $reason,
        ':d' => $details, ':r' => $reporter, ':c' => date('Y-m-d H:i:s'),
    ]);

    vs_rs_reply(true, 'Thanks, we will take a look.');
} catch (Throwable $e) {
    error_log('report-submit failed: ' . $e->getMessage());
    vs_rs_reply(false, 'Something went wrong. Please try again.', 500);
}