<?php

function vs_creator_analytics_ensure_events_table(mysqli $conn): void
{
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `creator_analytics_events` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `content_type` VARCHAR(16) NOT NULL,
            `content_id` VARCHAR(191) NOT NULL,
            `creator` VARCHAR(191) NOT NULL,
            `event_type` VARCHAR(16) NOT NULL,
            `watched_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_creator_analytics_creator_event` (`creator`, `event_type`, `created_at`),
            KEY `idx_creator_analytics_content_recent` (`content_type`, `content_id`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
