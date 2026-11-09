<?php

function vs_admin_alerts_ensure(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_alerts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            alert_key VARCHAR(190) NOT NULL,
            kind VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            message VARCHAR(500) NOT NULL DEFAULT \'\',
            href VARCHAR(255) NOT NULL DEFAULT \'index.php\',
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY alert_key_unique (alert_key),
            KEY alert_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admin_alert_reads (
            alert_id BIGINT UNSIGNED NOT NULL,
            admin_username VARCHAR(100) NOT NULL,
            read_at DATETIME NOT NULL,
            PRIMARY KEY (alert_id, admin_username),
            KEY admin_alert_read_by (admin_username, read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function vs_admin_alert_add(PDO $pdo, string $key, string $kind, string $title, string $message, string $href): bool
{
    try {
        $st = $pdo->prepare(
            'INSERT IGNORE INTO admin_alerts (alert_key, kind, title, message, href, created_at)
             VALUES (:k, :y, :t, :m, :h, NOW())'
        );
        $st->execute([
            ':k' => mb_substr($key, 0, 190),
            ':y' => mb_substr($kind, 0, 40),
            ':t' => mb_substr($title, 0, 190),
            ':m' => mb_substr($message, 0, 500),
            ':h' => mb_substr($href, 0, 255),
        ]);
        return $st->rowCount() > 0;
    } catch (Throwable $error) {
        error_log('Admin alert could not be saved: ' . $error->getMessage());
        return false;
    }
}

function vs_admin_alert_unread_count(PDO $pdo, string $admin): int
{
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM admin_alerts a
         LEFT JOIN admin_alert_reads r ON r.alert_id = a.id AND r.admin_username = :admin
         WHERE r.alert_id IS NULL'
    );
    $st->execute([':admin' => $admin]);
    return (int)$st->fetchColumn();
}

function vs_admin_alert_list(PDO $pdo, string $admin, int $limit = 15): array
{
    $limit = max(1, min(50, $limit));
    $st = $pdo->prepare(
        'SELECT a.id, a.kind, a.title, a.message, a.href, a.created_at,
                CASE WHEN r.alert_id IS NULL THEN 0 ELSE 1 END AS is_read
         FROM admin_alerts a
         LEFT JOIN admin_alert_reads r ON r.alert_id = a.id AND r.admin_username = :admin
         ORDER BY a.id DESC LIMIT ' . $limit
    );
    $st->execute([':admin' => $admin]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function vs_admin_alert_mark_all_read(PDO $pdo, string $admin): void
{
    $st = $pdo->prepare(
        'INSERT IGNORE INTO admin_alert_reads (alert_id, admin_username, read_at)
         SELECT id, :admin, NOW() FROM admin_alerts'
    );
    $st->execute([':admin' => $admin]);
}
