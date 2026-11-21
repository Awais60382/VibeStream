<?php

function vs_auth_security_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS `auth_login_failures` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `identifier_hash` CHAR(64) NULL,
        `ip` VARCHAR(45) NOT NULL DEFAULT \'\',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_auth_failures_identifier_created` (`identifier_hash`, `created_at`),
        KEY `idx_auth_failures_ip_created` (`ip`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $done = true;
}

function vs_auth_identifier_hash(string $identifier): ?string
{
    $identifier = mb_strtolower(trim(mb_substr($identifier, 0, 191)));
    return $identifier === '' ? null : hash('sha256', $identifier);
}

function vs_auth_login_limited(PDO $pdo, string $identifier, string $ip): bool
{
    vs_auth_security_ensure($pdo);
    $hash = vs_auth_identifier_hash($identifier);

    if ($hash !== null) {
        $byIdentifier = $pdo->prepare(
            'SELECT COUNT(*) FROM `auth_login_failures`
             WHERE `identifier_hash` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
        );
        $byIdentifier->execute([$hash]);
        if ((int)$byIdentifier->fetchColumn() >= 5) {
            return true;
        }
    }

    if ($ip !== '') {
        $byIp = $pdo->prepare(
            'SELECT COUNT(*) FROM `auth_login_failures`
             WHERE `ip` = ? AND `created_at` > DATE_SUB(NOW(), INTERVAL 10 MINUTE)'
        );
        $byIp->execute([mb_substr($ip, 0, 45)]);
        if ((int)$byIp->fetchColumn() >= 10) {
            return true;
        }
    }

    return false;
}

function vs_auth_record_login_failure(PDO $pdo, string $identifier, string $ip): void
{
    vs_auth_security_ensure($pdo);
    $insert = $pdo->prepare(
        'INSERT INTO `auth_login_failures` (`identifier_hash`, `ip`) VALUES (?, ?)'
    );
    $insert->execute([vs_auth_identifier_hash($identifier), mb_substr($ip, 0, 45)]);

    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM `auth_login_failures` WHERE `created_at` < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    }
}

function vs_auth_clear_login_failures(PDO $pdo, string $identifier): void
{
    $hash = vs_auth_identifier_hash($identifier);
    if ($hash === null) {
        return;
    }
    vs_auth_security_ensure($pdo);
    $delete = $pdo->prepare('DELETE FROM `auth_login_failures` WHERE `identifier_hash` = ?');
    $delete->execute([$hash]);
}
