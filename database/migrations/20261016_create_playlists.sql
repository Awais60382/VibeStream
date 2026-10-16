CREATE TABLE `playlists` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `visibility` ENUM('private', 'public') NOT NULL DEFAULT 'private',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_playlists_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `playlist_videos` (
    `playlist_id` BIGINT UNSIGNED NOT NULL,
    `video_id` VARCHAR(191) NOT NULL,
    `added_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`playlist_id`, `video_id`),
    KEY `idx_playlist_videos_video` (`video_id`),
    CONSTRAINT `fk_playlist_videos_playlist`
        FOREIGN KEY (`playlist_id`) REFERENCES `playlists` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
