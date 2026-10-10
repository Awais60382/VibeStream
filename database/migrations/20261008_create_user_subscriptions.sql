CREATE TABLE `user_subscriptions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `subscriber_id` INT NOT NULL,
    `channel_name` VARCHAR(255) NOT NULL,
    `channel_username` VARCHAR(50) NOT NULL DEFAULT '',
    `channel_key` CHAR(64) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_subscriptions_subscriber_channel` (`subscriber_id`, `channel_key`),
    KEY `idx_user_subscriptions_channel` (`channel_username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
