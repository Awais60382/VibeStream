<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connection.php';
require_once __DIR__ . '/ui_helpers.php';

$settings = $pdo->query(
    "SELECT `admin_key`, `email`, `frequency`, `last_sent_on`
     FROM `admin_summary_settings` WHERE `frequency` IN ('daily', 'weekly')"
)->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('DELETE FROM `video_view_events` WHERE `viewed_at` < DATE_SUB(NOW(), INTERVAL 90 DAY)');
$today = date('Y-m-d');
$isMonday = date('N') === '1';
$users = vs_cols($pdo, 'users');
$signupDate = $users ? vs_pick($users, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']) : null;
$openReports = 0;
foreach (['reports', 'reported', 'reported_content', 'content_reports', 'video_reports'] as $table) {
    $columns = vs_cols($pdo, $table);
    if (!$columns) {
        continue;
    }
    $status = vs_pick($columns, ['status', 'state', 'report_status']);
    $query = 'SELECT COUNT(*) FROM ' . vs_q($table);
    if ($status) {
        $query .= ' WHERE LOWER(CAST(' . vs_q($status) . " AS CHAR)) NOT IN ('resolved','closed','dismissed','done','handled','removed','approved','rejected','1')";
    }
    $openReports = (int)$pdo->query($query)->fetchColumn();
    break;
}
$sent = 0;
$skipped = 0;
foreach ($settings as $setting) {
    if ($setting['frequency'] === 'weekly' && !$isMonday) {
        $skipped++;
        continue;
    }
    if ((string)($setting['last_sent_on'] ?? '') === $today) {
        $skipped++;
        continue;
    }
    $email = trim((string)$setting['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log('Dashboard summary skipped an invalid recipient address.');
        $skipped++;
        continue;
    }
    $periodStart = $setting['frequency'] === 'weekly'
        ? date('Y-m-d 00:00:00', strtotime('-6 days'))
        : date('Y-m-d 00:00:00');
    if ($signupDate) {
        $userStmt = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE ' . vs_q($signupDate) . ' >= :start');
        $userStmt->execute([':start' => $periodStart]);
        $periodUsers = (int)$userStmt->fetchColumn();
    } else {
        $periodUsers = null;
    }
    $periodUploads = 0;
    foreach (['videos', 'shorts'] as $table) {
        $columns = vs_cols($pdo, $table);
        $date = $columns ? vs_pick($columns, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'timestamp']) : null;
        if ($date) {
            $uploadStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($table) . ' WHERE ' . vs_q($date) . ' >= :start');
            $uploadStmt->execute([':start' => $periodStart]);
            $periodUploads += (int)$uploadStmt->fetchColumn();
        }
    }
    $viewStmt = $pdo->prepare('SELECT COUNT(*) FROM `video_view_events` WHERE `viewed_at` >= :start');
    $viewStmt->execute([':start' => $periodStart]);
    $periodViews = (int)$viewStmt->fetchColumn();
    $subject = 'VibeStream ' . $setting['frequency'] . ' admin summary — ' . $today;
    $periodLabel = $setting['frequency'] === 'weekly' ? 'the last 7 days' : 'today';
    $body = "VibeStream admin summary for {$periodLabel} (generated {$today})\n\n"
        . 'New users: ' . ($periodUsers === null ? 'unavailable (signup date column not found)' : $periodUsers) . "\n"
        . 'New uploads: ' . $periodUploads . "\n"
        . 'Video views: ' . $periodViews . "\n"
        . 'Open reports: ' . $openReports . "\n"
        . "\nOpen the admin dashboard to review live activity, alerts, and content health.\n";
    $headers = "Content-Type: text/plain; charset=UTF-8\r\n";
    if (!mail($email, $subject, $body, $headers)) {
        error_log('Dashboard summary email failed to send to ' . $email . '. Check PHP mail configuration.');
        $skipped++;
        continue;
    }
    $update = $pdo->prepare('UPDATE `admin_summary_settings` SET `last_sent_on` = :today WHERE `admin_key` = :key');
    $update->execute([':today' => $today, ':key' => $setting['admin_key']]);
    $sent++;
}

fwrite(STDOUT, 'Dashboard summaries sent: ' . $sent . '; skipped: ' . $skipped . PHP_EOL);
