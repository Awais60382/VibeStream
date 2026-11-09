<?php

function vs_membership_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS channel_membership_tiers (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator VARCHAR(100) NOT NULL,
            name VARCHAR(60) NOT NULL,
            price DECIMAL(8,2) NOT NULL DEFAULT 0,
            benefits VARCHAR(1000) NOT NULL DEFAULT "",
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_membership_creator (creator, active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS channel_memberships (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator VARCHAR(100) NOT NULL,
            member VARCHAR(100) NOT NULL,
            tier_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT "active",
            started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NULL,
            UNIQUE KEY uq_channel_membership (creator, member),
            KEY idx_membership_member (member, status),
            CONSTRAINT fk_channel_membership_tier FOREIGN KEY (tier_id) REFERENCES channel_membership_tiers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}
