<?php
/**
 * Receives a "report this video/short" from the main site.
 * Put this file in the main VibeStream folder (next to connection.php).
 * Called by report-widget.php with fetch(); answers in JSON.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

function vs_report_out(array $data, int $code = 200): void
{
    // On your own computer (localhost) also tell the pop-up what really went wrong.
    if (!empty($data['detail']) && !in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true)) {
        unset($data['detail']);
    }
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    vs_report_out(['ok' => false, 'error' => 'Use POST.'], 405);
}

// Only accept reports sent from this same site.
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origin !== '') {
    $oh = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $mh = strtolower((string)parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if ($oh !== $mh) {
        vs_report_out(['ok' => false, 'error' => 'Request not allowed.'], 403);
    }
}

include_once __DIR__ . '/connection.php';
(static function (): void {
    $dirs = [__DIR__];
    foreach ($dirs as $d) {
        $f = $d . '/report-helpers.php';
        if (is_file($f)) { require_once $f; return; }
    }
    foreach ($dirs as $d) {                      // tolerate names like 123_report-helpers.php
        $hits = glob($d . '/*report-helpers.php') ?: [];
        if ($hits) { require_once $hits[0]; return; }
    }
    http_response_code(500);
    exit('report-helpers.php was not found. Put it in ' . htmlspecialchars(realpath(__DIR__ . '/..') ?: __DIR__ . '/..') . ' (the main VibeStream folder).');
})();

$user = trim((string)($_SESSION['username'] ?? $_SESSION['user'] ?? $_SESSION['user_name'] ?? $_SESSION['AdminSession'] ?? ''));
if ($user === '') {
    vs_report_out(['ok' => false, 'error' => 'Please log in to report a video.'], 401);
}

$id      = trim((string)($_POST['id'] ?? ''));
$kind    = (($_POST['kind'] ?? '') === 'short') ? 'short' : 'video';
$reason  = (string)($_POST['reason'] ?? '');
$details = mb_substr(trim((string)($_POST['details'] ?? '')), 0, 500);

if ($id === '' || strlen($id) > 64) {
    vs_report_out(['ok' => false, 'error' => 'That video could not be found.'], 400);
}
if (!isset(vs_report_reasons()[$reason])) {
    vs_report_out(['ok' => false, 'error' => 'Please choose a reason.'], 400);
}
if ($reason === 'other' && mb_strlen($details) < 3) {
    vs_report_out(['ok' => false, 'error' => 'Please tell us a little more.'], 400);
}
$pdo = vs_report_pdo();
if (!$pdo) {
    vs_report_out(['ok' => false, 'error' => 'Reports are not available right now.', 'detail' => 'No PDO database connection. Add one to connection.php.'], 500);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    vs_reports_ensure_table($pdo);

    $st = $pdo->prepare('SELECT COUNT(*) FROM `content_reports` WHERE `reporter` = :r AND `created_at` >= :since');
    $st->execute([':r' => $user, ':since' => date('Y-m-d H:i:s', time() - 3600)]);
    if ((int)$st->fetchColumn() >= 20) {
        vs_report_out(['ok' => false, 'error' => 'You have sent a lot of reports. Please try again later.'], 429);
    }

    $content = vs_reports_find($pdo, $id, $kind);
    if (!$content) {
        vs_report_out(['ok' => false, 'error' => 'That video could not be found.'], 404);
    }
    if ($content['who'] !== '' && strcasecmp($content['who'], $user) === 0) {
        vs_report_out(['ok' => false, 'error' => 'You cannot report your own video.'], 400);
    }

    $ins = $pdo->prepare(
        'INSERT INTO `content_reports` (`content_table`, `content_id`, `content_type`, `reason`, `details`, `reporter`, `status`, `created_at`)
         VALUES (:t, :i, :k, :r, :d, :u, \'open\', :c)'
    );
    $ins->execute([
        ':t' => $content['table'], ':i' => $content['id'], ':k' => $content['type'],
        ':r' => $reason, ':d' => $details, ':u' => $user, ':c' => date('Y-m-d H:i:s'),
    ]);
    vs_report_out(['ok' => true, 'message' => 'Thanks. Your report was sent to the admins.']);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        vs_report_out(['ok' => false, 'error' => 'You already reported this video.'], 409);
    }
    error_log('report-content failed: ' . $e->getMessage());
    vs_report_out(['ok' => false, 'error' => 'Could not send the report. Please try again.', 'detail' => $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('report-content failed: ' . $e->getMessage());
    vs_report_out(['ok' => false, 'error' => 'Could not send the report. Please try again.', 'detail' => $e->getMessage()], 500);
}