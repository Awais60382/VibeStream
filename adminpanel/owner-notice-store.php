<?php
/**
 * adminpanel/owner-notice-store.php
 * Owner -> admin notices with read receipts.
 *
 *   1. The owner sends a notice to an admin          -> the admin gets a bell notification
 *   2. The admin opens it                            -> the owner gets "<admin> saw your notice"
 *   3. The admin presses "I changed it"              -> the owner gets "<admin> changed it"
 *
 * Bell notifications are rows in `user_notifications` (the same table header.php already reads),
 * so no change to the bell itself is needed. Every function swallows its own errors.
 */

function vs_notice_ensure(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS owner_notices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            from_id INT NOT NULL,
            from_name VARCHAR(100) NOT NULL DEFAULT \'\',
            to_id INT NOT NULL,
            to_name VARCHAR(100) NOT NULL DEFAULT \'\',
            body VARCHAR(1000) NOT NULL,
            status VARCHAR(10) NOT NULL DEFAULT \'sent\',
            seen_at DATETIME NULL,
            done_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            KEY idx_to (to_id, status),
            KEY idx_from (from_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    // same table header.php reads for the bell
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS `user_notifications` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `user_id` INT NOT NULL,
            `type` VARCHAR(20) NOT NULL DEFAULT \'upload\',
            `channel_name` VARCHAR(255) NOT NULL DEFAULT \'\',
            `actor` VARCHAR(255) NOT NULL DEFAULT \'\',
            `video_id` VARCHAR(255) NOT NULL DEFAULT \'\',
            `title` VARCHAR(255) NOT NULL DEFAULT \'\',
            `message` VARCHAR(500) NOT NULL DEFAULT \'\',
            `link` VARCHAR(500) NOT NULL DEFAULT \'\',
            `is_read` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_user_notifications_user` (`user_id`, `is_read`, `created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $col = $pdo->query("SHOW COLUMNS FROM `user_notifications` LIKE 'actor'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE `user_notifications` ADD COLUMN `actor` VARCHAR(255) NOT NULL DEFAULT '' AFTER `channel_name`");
    }
    $done = true;
}

/** One bell notification for one user. */
function vs_notice_bell(PDO $pdo, int $toId, string $type, string $actor, string $message, string $link): void
{
    if ($toId <= 0) {
        return;
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO user_notifications (user_id, type, channel_name, actor, title, message, link)
             VALUES (:u, :t, :c, :a, :ti, :m, :l)'
        );
        $st->execute([
            ':u' => $toId, ':t' => mb_substr($type, 0, 20), ':c' => mb_substr($actor, 0, 255),
            ':a' => mb_substr($actor, 0, 255), ':ti' => 'Owner notice',
            ':m' => mb_substr($message, 0, 500), ':l' => mb_substr($link, 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('owner notice bell failed: ' . $e->getMessage());
    }
}

function vs_notice_preview(string $body, int $len = 70): string
{
    return mb_substr(trim((string)preg_replace('/\s+/', ' ', $body)), 0, $len);
}

/** id + username of the admin who is logged in. */
function vs_notice_me(PDO $pdo): array
{
    $name = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? ''));
    $id = (int)($_SESSION['user_id'] ?? $_SESSION['AdminID'] ?? 0);
    if ($id <= 0 && $name !== '') {
        try {
            $st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
            $st->execute([$name]);
            $id = (int)$st->fetchColumn();
        } catch (Throwable $e) {
            $id = 0;
        }
    }
    return ['id' => $id, 'name' => $name];
}

/** Admin accounts the owner can write to (the owner is left out). */
function vs_notice_admins(PDO $pdo, int $ownerId): array
{
    try {
        $rows = $pdo->query("SELECT id, username FROM users WHERE LOWER(role) = 'admin' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    return array_values(array_filter($rows, static fn($r) => (int)$r['id'] !== $ownerId && trim((string)$r['username']) !== ''));
}

/** The owner sends a notice to one admin. Returns the notice id (0 on failure). */
function vs_notice_send(PDO $pdo, array $owner, int $toId, string $toName, string $body): int
{
    $body = trim($body);
    if ($body === '' || $toId <= 0 || $toId === (int)$owner['id']) {
        return 0;
    }
    try {
        vs_notice_ensure($pdo);
        $st = $pdo->prepare(
            'INSERT INTO owner_notices (from_id, from_name, to_id, to_name, body, status, created_at)
             VALUES (:f, :fn, :t, :tn, :b, \'sent\', NOW())'
        );
        $st->execute([
            ':f' => (int)$owner['id'], ':fn' => mb_substr((string)$owner['name'], 0, 100),
            ':t' => $toId, ':tn' => mb_substr($toName, 0, 100), ':b' => mb_substr($body, 0, 1000),
        ]);
        $id = (int)$pdo->lastInsertId();
        vs_notice_bell(
            $pdo, $toId, 'notice', (string)$owner['name'],
            $owner['name'] . ' sent you a notice: ' . vs_notice_preview($body),
            'adminpanel/owner-notices.php?id=' . $id
        );
        return $id;
    } catch (Throwable $e) {
        error_log('owner notice send failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * The admin has opened their notices: every notice still "sent" becomes "seen"
 * and the owner is told once per notice.
 */
function vs_notice_mark_seen(PDO $pdo, int $adminId, string $adminName): int
{
    $n = 0;
    try {
        vs_notice_ensure($pdo);
        $st = $pdo->prepare("SELECT id, from_id, body FROM owner_notices WHERE to_id = ? AND status = 'sent'");
        $st->execute([$adminId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $up = $pdo->prepare("UPDATE owner_notices SET status = 'seen', seen_at = NOW() WHERE id = ? AND status = 'sent'");
            $up->execute([(int)$r['id']]);
            if ($up->rowCount() > 0) {
                vs_notice_bell(
                    $pdo, (int)$r['from_id'], 'notice_seen', $adminName,
                    $adminName . ' saw your notice: ' . vs_notice_preview((string)$r['body']),
                    'adminpanel/owner-notices.php'
                );
                // the admin has now read the bell item that pointed here
                $pdo->prepare("UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND type = 'notice' AND link = ?")
                    ->execute([$adminId, 'adminpanel/owner-notices.php?id=' . (int)$r['id']]);
                $n++;
            }
        }
    } catch (Throwable $e) {
        error_log('owner notice seen failed: ' . $e->getMessage());
    }
    return $n;
}

/** The admin says they made the change: the owner is told. Only the addressed admin can do this. */
function vs_notice_mark_done(PDO $pdo, int $noticeId, int $adminId, string $adminName): bool
{
    try {
        vs_notice_ensure($pdo);
        $st = $pdo->prepare('SELECT id, from_id, body, status FROM owner_notices WHERE id = ? AND to_id = ? LIMIT 1');
        $st->execute([$noticeId, $adminId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['status'] === 'done') {
            return false;
        }
        $pdo->prepare("UPDATE owner_notices SET status = 'done', done_at = NOW(), seen_at = COALESCE(seen_at, NOW()) WHERE id = ?")
            ->execute([$noticeId]);
        vs_notice_bell(
            $pdo, (int)$r['from_id'], 'notice_done', $adminName,
            $adminName . ' changed it: ' . vs_notice_preview((string)$r['body']),
            'adminpanel/owner-notices.php'
        );
        return true;
    } catch (Throwable $e) {
        error_log('owner notice done failed: ' . $e->getMessage());
        return false;
    }
}

function vs_notice_list_for_owner(PDO $pdo, int $ownerId, int $limit = 100): array
{
    try {
        vs_notice_ensure($pdo);
        $st = $pdo->prepare('SELECT * FROM owner_notices WHERE from_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$ownerId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

function vs_notice_list_for_admin(PDO $pdo, int $adminId, int $limit = 100): array
{
    try {
        vs_notice_ensure($pdo);
        $st = $pdo->prepare('SELECT * FROM owner_notices WHERE to_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$adminId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}
