<?php

function vs_live_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS live_streams (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            title VARCHAR(160) NOT NULL,
            description VARCHAR(1000) NOT NULL DEFAULT "",
            stream_url VARCHAR(2048) NOT NULL DEFAULT "",
            thumbnail_url VARCHAR(2048) NOT NULL DEFAULT "",
            status VARCHAR(16) NOT NULL DEFAULT "offline",
            scheduled_at DATETIME NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            viewer_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_live_status (status, started_at),
            KEY idx_live_username (username, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS live_chat_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            stream_id BIGINT UNSIGNED NOT NULL,
            username VARCHAR(100) NOT NULL,
            message VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_live_chat_stream (stream_id, created_at),
            CONSTRAINT fk_live_chat_stream FOREIGN KEY (stream_id) REFERENCES live_streams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS live_viewer_sessions (
            stream_id BIGINT UNSIGNED NOT NULL,
            session_key CHAR(64) NOT NULL,
            username VARCHAR(100) NOT NULL DEFAULT "",
            last_seen DATETIME NOT NULL,
            PRIMARY KEY (stream_id, session_key),
            KEY idx_live_viewers_seen (stream_id, last_seen),
            CONSTRAINT fk_live_viewer_stream FOREIGN KEY (stream_id) REFERENCES live_streams(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
