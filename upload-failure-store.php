<?php

function vs_upload_failure_code(string $message, bool $uploadsEnabled, array $file): array
{
    if (!$uploadsEnabled) {
        return ['uploads_disabled', 0];
    }
    if (stripos($message, 'session expired') !== false) {
        return ['csrf_failed', 0];
    }
    if (stripos($message, 'bigger than php allows') !== false) {
        return ['request_too_large', 0];
    }
    if (stripos($message, 'could not save:') === 0) {
        return ['storage_or_database_error', 0];
    }
    if (stripos($message, 'daily upload limit') !== false) {
        return ['daily_limit_reached', 0];
    }
    if (isset($file['error']) && (int)$file['error'] !== UPLOAD_ERR_NO_FILE && (int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['php_upload_error', (int)$file['error']];
    }
    if (stripos($message, 'maximum upload size') !== false || stripos($message, 'bigger than your php limit') !== false) {
        return ['file_size_limit', 0];
    }
    if (stripos($message, 'only mp4') !== false) {
        return ['unsupported_file_type', 0];
    }
    if (stripos($message, 'link') !== false || stripos($message, 'youtube') !== false) {
        return ['invalid_video_link', 0];
    }
    return ['upload_validation_failed', 0];
}

function vs_upload_failure_ensure(mysqli|PDO $conn): bool
{
    static $ready = [];
    $connectionKey = spl_object_id($conn);
    if (isset($ready[$connectionKey])) {
        return $ready[$connectionKey];
    }

    try {
        $sql = 'CREATE TABLE IF NOT EXISTS `upload_failure_events` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `username` VARCHAR(100) NOT NULL DEFAULT \'\',
                `content_type` VARCHAR(16) NOT NULL,
                `upload_mode` VARCHAR(16) NOT NULL,
                `failure_code` VARCHAR(40) NOT NULL,
                `php_error_code` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_upload_failure_events_created` (`created_at`),
                KEY `idx_upload_failure_events_code_created` (`failure_code`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        if ($conn instanceof PDO) {
            $conn->exec($sql);
        } else {
            $conn->query($sql);
        }
        $ready[$connectionKey] = true;
        return true;
    } catch (Throwable $error) {
        error_log('Could not initialize upload failure tracking: ' . $error->getMessage());
        $ready[$connectionKey] = false;
        return false;
    }
}

function vs_upload_record_failure(mysqli $conn, string $username, string $contentType, string $mode, string $message, bool $uploadsEnabled, array $file = []): void
{
    if (!vs_upload_failure_ensure($conn)) {
        return;
    }
    [$failureCode, $phpErrorCode] = vs_upload_failure_code($message, $uploadsEnabled, $file);
    try {
        $stmt = $conn->prepare(
            'INSERT INTO `upload_failure_events` (`username`, `content_type`, `upload_mode`, `failure_code`, `php_error_code`)
             VALUES (?, ?, ?, ?, ?)'
        );
        $username = mb_substr($username, 0, 100);
        $contentType = $contentType === 'video' ? 'video' : 'short';
        $mode = $mode === 'file' ? 'file' : 'link';
        $stmt->bind_param('ssssi', $username, $contentType, $mode, $failureCode, $phpErrorCode);
        $stmt->execute();
        $stmt->close();
        if (random_int(1, 100) === 1) {
            $conn->query('DELETE FROM `upload_failure_events` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        }
    } catch (Throwable $error) {
        error_log('Could not record upload failure: ' . $error->getMessage());
    }
}
