<?php

const VS_UPDATE_TYPES = [
    'announcement' => 'Announcement',
    'upcoming' => 'Upcoming update',
    'release' => 'Release notes',
    'maintenance' => 'Maintenance',
];

function vs_updates_ensure(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `site_updates` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(160) NOT NULL,
            `body` TEXT NOT NULL,
            `kind` VARCHAR(24) NOT NULL DEFAULT 'announcement',
            `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
            `publish_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `event_at` DATETIME NULL DEFAULT NULL,
            `created_by` VARCHAR(191) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_site_updates_public` (`status`, `publish_at`),
            KEY `idx_site_updates_event` (`event_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function vs_updates_public(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT `id`, `title`, `body`, `kind`, `publish_at`, `event_at`
         FROM `site_updates`
         WHERE `status` = 'published' AND `publish_at` <= NOW()
         ORDER BY COALESCE(`event_at`, `publish_at`) DESC, `id` DESC
         LIMIT 50"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function vs_updates_latest_public(PDO $pdo): ?array
{
    $stmt = $pdo->query(
        "SELECT `id`, `title`, `body`, `kind`, `publish_at`, `event_at`
         FROM `site_updates`
         WHERE `status` = 'published' AND `publish_at` <= NOW()
         ORDER BY `publish_at` DESC, `id` DESC
         LIMIT 1"
    );
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function vs_updates_admin_all(PDO $pdo): array
{
    $stmt = $pdo->query(
        'SELECT `id`, `title`, `body`, `kind`, `status`, `publish_at`, `event_at`, `created_by`, `updated_at`
         FROM `site_updates`
         ORDER BY `publish_at` DESC, `id` DESC
         LIMIT 100'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function vs_updates_find(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM `site_updates` WHERE `id` = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function vs_updates_datetime(?string $value, bool $required, string $label): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        if ($required) {
            throw new RuntimeException($label . ' is required.');
        }
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d\TH:i') !== $value) {
        throw new RuntimeException('Enter a valid ' . strtolower($label) . '.');
    }
    return $date->format('Y-m-d H:i:s');
}

function vs_updates_save(PDO $pdo, array $input, string $adminName): int
{
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $kind = (string)($input['kind'] ?? '');
    $status = (string)($input['status'] ?? '');
    $publishAt = vs_updates_datetime((string)($input['publish_at'] ?? ''), true, 'Publish date');
    $eventAt = vs_updates_datetime((string)($input['event_at'] ?? ''), false, 'Update date');

    if ($title === '' || mb_strlen($title) > 160) {
        throw new RuntimeException('Title is required and must be 160 characters or fewer.');
    }
    if ($body === '' || mb_strlen($body) > 5000) {
        throw new RuntimeException('Announcement text is required and must be 5,000 characters or fewer.');
    }
    if (!isset(VS_UPDATE_TYPES[$kind])) {
        throw new RuntimeException('Choose a valid announcement type.');
    }
    if (!in_array($status, ['draft', 'published'], true)) {
        throw new RuntimeException('Choose whether to save this as a draft or publish it.');
    }

    $id = (int)($input['id'] ?? 0);
    if ($id > 0) {
        if (!vs_updates_find($pdo, $id)) {
            throw new RuntimeException('This announcement no longer exists.');
        }
        $stmt = $pdo->prepare(
            'UPDATE `site_updates`
             SET `title` = :title, `body` = :body, `kind` = :kind, `status` = :status,
                 `publish_at` = :publish_at, `event_at` = :event_at
             WHERE `id` = :id'
        );
        $stmt->execute([
            ':title' => $title,
            ':body' => $body,
            ':kind' => $kind,
            ':status' => $status,
            ':publish_at' => $publishAt,
            ':event_at' => $eventAt,
            ':id' => $id,
        ]);
        return $id;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO `site_updates` (`title`, `body`, `kind`, `status`, `publish_at`, `event_at`, `created_by`)
         VALUES (:title, :body, :kind, :status, :publish_at, :event_at, :created_by)'
    );
    $stmt->execute([
        ':title' => $title,
        ':body' => $body,
        ':kind' => $kind,
        ':status' => $status,
        ':publish_at' => $publishAt,
        ':event_at' => $eventAt,
        ':created_by' => mb_substr(trim($adminName), 0, 191),
    ]);
    return (int)$pdo->lastInsertId();
}

function vs_updates_delete(PDO $pdo, int $id): void
{
    if ($id < 1) {
        throw new RuntimeException('Choose a valid announcement to delete.');
    }
    $stmt = $pdo->prepare('DELETE FROM `site_updates` WHERE `id` = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('This announcement no longer exists.');
    }
}
