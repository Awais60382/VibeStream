<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

function vs_points_export_error(int $status, string $message): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    vs_points_export_error(405, 'Method not allowed.');
}

$username = trim((string)($_SESSION['username'] ?? ''));
if ($username === '') {
    vs_points_export_error(401, 'Please sign in first.');
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    vs_points_export_error(400, 'Invalid export request.');
}
if (!hash_equals((string)($_SESSION['premium_csrf'] ?? ''), (string)($input['csrf'] ?? ''))) {
    vs_points_export_error(403, 'Session expired. Refresh the page and try again.');
}
$filters = $input['filters'] ?? [];
if (!is_array($filters)) {
    vs_points_export_error(400, 'Invalid activity filters.');
}

require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';

try {
    $pdo = vs_premium_pdo();
    if (!$pdo) {
        vs_points_export_error(500, 'No database connection.');
    }
    vs_premium_ensure($pdo);
    $normalizedFilters = vs_rewards_normalize_activity_filters($filters);
    $statement = vs_rewards_activity_statement($pdo, $username, $normalizedFilters);
} catch (InvalidArgumentException $error) {
    vs_points_export_error(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('VibeStream points activity export failed: ' . $error->getMessage());
    vs_points_export_error(500, 'Could not export points activity. Please try again.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="vibestream-points-activity.csv"');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$output = fopen('php://output', 'wb');
if ($output === false) {
    error_log('VibeStream points activity export could not open the response stream.');
    exit;
}
fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, ['Date', 'Activity', 'Points'], ',', '"', '\\');
$labels = [
    'upload' => 'Published video or Short',
    'daily_checkin' => 'Daily check-in',
    'premium_monthly' => 'Premium redemption',
];
while ($entry = $statement->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [
        (string)$entry['created_at'],
        $labels[(string)$entry['reason']] ?? 'Points update',
        (int)$entry['amount'],
    ], ',', '"', '\\');
}
fclose($output);
