-- VibeStream: run once in phpMyAdmin (SQL tab) on your VibeStream database.
-- XAMPP uses MariaDB, which supports "CREATE INDEX IF NOT EXISTS". On plain MySQL, run the
-- CREATE INDEX lines one by one and ignore "duplicate key name" errors.

CREATE TABLE IF NOT EXISTS `user_subscriptions` (
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

CREATE TABLE IF NOT EXISTS `subscription_seen` (
    `user_id` INT NOT NULL,
    `channel_key` VARCHAR(191) NOT NULL,
    `seen_at` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`, `channel_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Records submitted site searches for the admin dashboard's Top searches panel.
CREATE TABLE IF NOT EXISTS `search_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `query` VARCHAR(100) NOT NULL,
    `results` INT UNSIGNED NOT NULL DEFAULT 0,
    `user_id` INT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_search_logs_created_at` (`created_at`),
    KEY `idx_search_logs_query` (`query`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores safe failure categories for video/short upload attempts; no filenames, URLs, or IPs are recorded.
CREATE TABLE IF NOT EXISTS `upload_failure_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL DEFAULT '',
    `content_type` VARCHAR(16) NOT NULL,
    `upload_mode` VARCHAR(16) NOT NULL,
    `failure_code` VARCHAR(40) NOT NULL,
    `php_error_code` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_upload_failure_events_created` (`created_at`),
    KEY `idx_upload_failure_events_code_created` (`failure_code`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Live insights, moderation signals, community controls, and retention tracking.
CREATE TABLE IF NOT EXISTS `video_view_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `content_type` VARCHAR(16) NOT NULL,
    `content_id` VARCHAR(191) NOT NULL,
    `creator` VARCHAR(191) NOT NULL DEFAULT '',
    `viewed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_video_view_events_recent` (`viewed_at`),
    KEY `idx_video_view_events_content_recent` (`content_type`, `content_id`, `viewed_at`),
    KEY `idx_video_view_events_creator_recent` (`creator`, `viewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_activity_days` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `active_date` DATE NOT NULL,
    PRIMARY KEY (`user_id`, `active_date`),
    KEY `idx_user_activity_days_date` (`active_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stores keyed hashes, not raw signup IP addresses. Set VIBESTREAM_IP_HASH_KEY
-- in the server environment to enable same-IP account signals.
CREATE TABLE IF NOT EXISTS `signup_ip_hashes` (
    `user_id` BIGINT UNSIGNED NOT NULL,
    `ip_hash` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    KEY `idx_signup_ip_hashes_hash` (`ip_hash`),
    KEY `idx_signup_ip_hashes_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_dashboard_goals` (
    `goal_month` CHAR(7) NOT NULL,
    `metric` VARCHAR(16) NOT NULL,
    `target` INT UNSIGNED NOT NULL,
    `updated_by` VARCHAR(191) NOT NULL DEFAULT '',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`goal_month`, `metric`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Moderation bans for user accounts. expires_at is NULL for permanent bans.
CREATE TABLE IF NOT EXISTS `user_bans` (
    `user_id` VARCHAR(191) NOT NULL,
    `username` VARCHAR(191) NOT NULL DEFAULT '',
    `reason` VARCHAR(500) NOT NULL DEFAULT '',
    `banned_by` VARCHAR(191) NOT NULL DEFAULT '',
    `is_permanent` TINYINT(1) NOT NULL DEFAULT 0,
    `expires_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`),
    KEY `idx_user_bans_username` (`username`),
    KEY `idx_user_bans_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-account brain meter state synced across signed-in devices.
CREATE TABLE IF NOT EXISTS `brain_state` (
    `username` VARCHAR(191) NOT NULL,
    `bundle` TEXT NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_user_notes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `admin_name` VARCHAR(191) NOT NULL,
    `note` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_admin_user_notes_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `site_announcements` (
    `id` TINYINT UNSIGNED NOT NULL,
    `message` VARCHAR(500) NOT NULL DEFAULT '',
    `updated_by` VARCHAR(191) NOT NULL DEFAULT '',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `admin_summary_settings` (
    `admin_key` VARCHAR(191) NOT NULL,
    `email` VARCHAR(254) NOT NULL,
    `frequency` VARCHAR(8) NOT NULL,
    `last_sent_on` DATE NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`admin_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `top_creator_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `creator_key` VARCHAR(191) NOT NULL DEFAULT '',
    `creator_name` VARCHAR(191) NOT NULL DEFAULT '',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Account-backed Watch Later items; the per-account count is enforced by PHP using Premium limits.
CREATE TABLE IF NOT EXISTS `watch_later_items` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `video_id` VARCHAR(191) NOT NULL,
    `video_key` CHAR(64) NOT NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `added_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_watch_later_user_video` (`username`, `video_key`),
    KEY `idx_watch_later_user_order` (`username`, `sort_order`, `added_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upload rewards: five points per published video/short; 500 points redeem one Premium month.
CREATE TABLE IF NOT EXISTS `reward_points_accounts` (
    `username` VARCHAR(100) NOT NULL,
    `points` INT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL,
    PRIMARY KEY (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reward_points_ledger` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(100) NOT NULL,
    `amount` INT NOT NULL,
    `reason` VARCHAR(32) NOT NULL,
    `reference_key` CHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_reward_points_reference` (`reference_key`),
    KEY `idx_reward_points_username_created` (`username`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Setup notes for opt-in email summaries:
-- 1. Configure PHP mail() (or the host's SMTP relay) before enabling summaries.
-- 2. On Windows Task Scheduler, run daily:
--    Program: C:\xampppp\php\php.exe
--    Arguments: C:\xampppp\htdocs\VibeStream\adminpanel\send-dashboard-summary.php
--    Adjust both paths to match your XAMPP installation.
-- 3. Set VIBESTREAM_IP_HASH_KEY to a long random secret in the web server environment
--    to enable privacy-preserving same-IP signup signals. Do not use a public/static key.

-- Makes the "latest upload per channel" lookup in the sidebar fast.
CREATE INDEX IF NOT EXISTS `idx_videos_username_created` ON `videos` (`username`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_videos_channel_created` ON `videos` (`channel_name`, `created_at`);

-- Stops two people registering the same username/email at the same moment.
-- (If this fails with "duplicate entry", you already have duplicate users - clean them first.)
-- ALTER TABLE `users` ADD UNIQUE KEY `uq_users_username` (`username`);
-- ALTER TABLE `users` ADD UNIQUE KEY `uq_users_email` (`email`);
