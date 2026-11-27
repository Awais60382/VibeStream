<?php
/**
 * Channel memberships: tables + joining with Rewards points.
 * Put this file in the site root, next to premium-store.php.
 */
require_once __DIR__ . '/premium-store.php';

// A tier's "price" column stays in dollars; members pay this many points per dollar.
const VS_MEMBERSHIP_POINTS_PER_DOLLAR = 100;
// How long one purchase lasts. After that the member sees "Renew membership".
const VS_MEMBERSHIP_DAYS = 30;

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
            requires_approval TINYINT(1) NOT NULL DEFAULT 0,
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
    // Tiers created before approvals existed: add the column once.
    if (!$pdo->query("SHOW COLUMNS FROM channel_membership_tiers LIKE 'requires_approval'")->fetch()) {
        $pdo->exec('ALTER TABLE channel_membership_tiers ADD COLUMN requires_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER active');
    }
    // One request row per member per creator (asking again after a decline re-opens the same row).
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS channel_membership_requests (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator VARCHAR(100) NOT NULL,
            member VARCHAR(100) NOT NULL,
            tier_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT "pending",
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            UNIQUE KEY uq_membership_request (creator, member),
            KEY idx_membership_request_creator (creator, status),
            CONSTRAINT fk_membership_request_tier FOREIGN KEY (tier_id) REFERENCES channel_membership_tiers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** Points a tier costs for one period (price is in dollars; 0 = free). */
function vs_membership_points_cost($price): int
{
    return max(0, (int)round((float)$price * VS_MEMBERSHIP_POINTS_PER_DOLLAR));
}

/**
 * Join (or renew / switch to) a tier by spending Rewards points.
 * The points are taken and the membership is saved in ONE transaction, so a member can never
 * lose points without getting the membership, or get it without paying.
 * The creator receives nothing; points are simply spent. Nothing is refunded on cancel.
 *
 * Returns ['tier' => name, 'cost' => points spent, 'balance' => points left, 'days' => length].
 * Throws RuntimeException (with a message that is safe to show) when the join is not allowed.
 */
function vs_membership_join_with_points(PDO $pdo, string $creator, string $member, int $tierId): array
{
    $creator = trim($creator);
    $member = trim($member);
    if ($creator === '' || $member === '' || strcasecmp($creator, $member) === 0) {
        throw new RuntimeException('You cannot join this membership.');
    }
    vs_membership_ensure($pdo);
    vs_premium_ensure($pdo);

    $pdo->beginTransaction();
    try {
        // Lock the member's points row first, so two clicks at once cannot spend the same points twice.
        $pdo->prepare('INSERT IGNORE INTO reward_points_accounts (username, points, updated_at) VALUES (:u, 0, NOW())')
            ->execute([':u' => $member]);
        $lock = $pdo->prepare('SELECT points FROM reward_points_accounts WHERE username = :u FOR UPDATE');
        $lock->execute([':u' => $member]);
        $points = $lock->fetchColumn();
        if ($points === false) {
            throw new RuntimeException('Could not read your points balance. Try again.');
        }
        $points = (int)$points;

        $tierStmt = $pdo->prepare('SELECT id, name, price, requires_approval FROM channel_membership_tiers WHERE id = ? AND creator = ? AND active = 1 LIMIT 1');
        $tierStmt->execute([$tierId, $creator]);
        $tier = $tierStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tier) {
            throw new RuntimeException('That membership tier is not available.');
        }
        if (!empty($tier['requires_approval'])) {
            throw new RuntimeException('This tier needs the creator\'s approval. Send a join request instead.');
        }
        $cost = vs_membership_points_cost($tier['price']);

        $curStmt = $pdo->prepare(
            'SELECT tier_id, (status = "active" AND (expires_at IS NULL OR expires_at > NOW())) AS is_active
             FROM channel_memberships WHERE creator = ? AND member = ? LIMIT 1 FOR UPDATE'
        );
        $curStmt->execute([$creator, $member]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        if ($cur && (int)$cur['is_active'] === 1 && (int)$cur['tier_id'] === (int)$tier['id']) {
            throw new RuntimeException('You are already a member of this tier.');
        }

        if ($points < $cost) {
            throw new RuntimeException(
                'You need ' . number_format($cost) . ' points to join ' . $tier['name'] . '. You have ' . number_format($points) . '.'
            );
        }

        if ($cost > 0) {
            $entry = $pdo->prepare(
                'INSERT INTO reward_points_ledger (username, amount, reason, reference_key, created_at)
                 VALUES (:u, :a, \'membership\', :r, NOW())'
            );
            $entry->execute([
                ':u' => $member,
                ':a' => -$cost,
                ':r' => hash('sha256', "membership\0" . strtolower($member) . "\0" . strtolower($creator) . "\0" . bin2hex(random_bytes(16))),
            ]);
            $update = $pdo->prepare('UPDATE reward_points_accounts SET points = points - :c, updated_at = NOW() WHERE username = :u AND points >= :c2');
            $update->execute([':c' => $cost, ':u' => $member, ':c2' => $cost]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Could not take the points. Please try again.');
            }
        }

        $pdo->prepare(
            'INSERT INTO channel_memberships (creator, member, tier_id, status, started_at, expires_at)
             VALUES (?, ?, ?, "active", NOW(), DATE_ADD(NOW(), INTERVAL ' . (int)VS_MEMBERSHIP_DAYS . ' DAY))
             ON DUPLICATE KEY UPDATE tier_id = VALUES(tier_id), status = "active", started_at = NOW(),
                                     expires_at = DATE_ADD(NOW(), INTERVAL ' . (int)VS_MEMBERSHIP_DAYS . ' DAY)'
        )->execute([$creator, $member, (int)$tier['id']]);

        $pdo->commit();
        return ['tier' => (string)$tier['name'], 'cost' => $cost, 'balance' => $points - $cost, 'days' => VS_MEMBERSHIP_DAYS];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}


/* ------------------------------------------------------------------ */
/* Join requests (tiers with "requires approval": free, creator decides) */
/* ------------------------------------------------------------------ */

/** A member asks to join an approval tier. Re-asking after a decline re-opens the request. */
function vs_membership_request_create(PDO $pdo, string $creator, string $member, int $tierId): array
{
    $creator = trim($creator);
    $member = trim($member);
    if ($creator === '' || $member === '' || strcasecmp($creator, $member) === 0) {
        throw new RuntimeException('You cannot join this membership.');
    }
    vs_membership_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $tierStmt = $pdo->prepare('SELECT id, name, requires_approval FROM channel_membership_tiers WHERE id = ? AND creator = ? AND active = 1 LIMIT 1');
        $tierStmt->execute([$tierId, $creator]);
        $tier = $tierStmt->fetch(PDO::FETCH_ASSOC);
        if (!$tier) {
            throw new RuntimeException('That membership tier is not available.');
        }
        if (empty($tier['requires_approval'])) {
            throw new RuntimeException('This tier does not need approval. Join it directly.');
        }
        $curStmt = $pdo->prepare(
            'SELECT tier_id, (status = "active" AND (expires_at IS NULL OR expires_at > NOW())) AS is_active
             FROM channel_memberships WHERE creator = ? AND member = ? LIMIT 1 FOR UPDATE'
        );
        $curStmt->execute([$creator, $member]);
        $cur = $curStmt->fetch(PDO::FETCH_ASSOC);
        if ($cur && (int)$cur['is_active'] === 1 && (int)$cur['tier_id'] === (int)$tier['id']) {
            throw new RuntimeException('You are already a member of this tier.');
        }
        $reqStmt = $pdo->prepare('SELECT tier_id, status FROM channel_membership_requests WHERE creator = ? AND member = ? LIMIT 1 FOR UPDATE');
        $reqStmt->execute([$creator, $member]);
        $req = $reqStmt->fetch(PDO::FETCH_ASSOC);
        if ($req && $req['status'] === 'pending' && (int)$req['tier_id'] === (int)$tier['id']) {
            throw new RuntimeException('You already asked to join this tier. Wait for the creator to answer.');
        }
        $pdo->prepare(
            'INSERT INTO channel_membership_requests (creator, member, tier_id, status, created_at, decided_at)
             VALUES (?, ?, ?, "pending", NOW(), NULL)
             ON DUPLICATE KEY UPDATE tier_id = VALUES(tier_id), status = "pending", created_at = NOW(), decided_at = NULL'
        )->execute([$creator, $member, (int)$tier['id']]);
        $pdo->commit();
        return ['tier' => (string)$tier['name']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** The member withdraws their own pending request. */
function vs_membership_request_cancel(PDO $pdo, string $creator, string $member): void
{
    vs_membership_ensure($pdo);
    $pdo->prepare('UPDATE channel_membership_requests SET status = "cancelled", decided_at = NOW() WHERE creator = ? AND member = ? AND status = "pending"')
        ->execute([trim($creator), trim($member)]);
}

/**
 * The creator approves or declines a pending request. Approving makes the person a member
 * (no expiry: it lasts until they cancel). Only the creator named on the request can decide it.
 * Returns ['member' => name, 'tier' => tier name, 'approved' => bool].
 */
function vs_membership_request_decide(PDO $pdo, string $creator, int $requestId, bool $approve): array
{
    vs_membership_ensure($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'SELECT r.id, r.member, r.tier_id, t.name AS tier_name, t.active AS tier_active
             FROM channel_membership_requests r JOIN channel_membership_tiers t ON t.id = r.tier_id
             WHERE r.id = ? AND r.creator = ? AND r.status = "pending" LIMIT 1 FOR UPDATE'
        );
        $st->execute([$requestId, trim($creator)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) {
            throw new RuntimeException('That request was already answered or no longer exists.');
        }
        if ($approve) {
            if (!(int)$r['tier_active']) {
                throw new RuntimeException('That tier is unpublished. Publish it again before approving.');
            }
            $pdo->prepare(
                'INSERT INTO channel_memberships (creator, member, tier_id, status, started_at, expires_at)
                 VALUES (?, ?, ?, "active", NOW(), NULL)
                 ON DUPLICATE KEY UPDATE tier_id = VALUES(tier_id), status = "active", started_at = NOW(), expires_at = NULL'
            )->execute([trim($creator), (string)$r['member'], (int)$r['tier_id']]);
        }
        $pdo->prepare('UPDATE channel_membership_requests SET status = ?, decided_at = NOW() WHERE id = ?')
            ->execute([$approve ? 'approved' : 'rejected', (int)$r['id']]);
        $pdo->commit();
        return ['member' => (string)$r['member'], 'tier' => (string)$r['tier_name'], 'approved' => $approve];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Pending requests for a creator, oldest first. */
function vs_membership_pending_requests(PDO $pdo, string $creator): array
{
    $st = $pdo->prepare(
        'SELECT r.id, r.member, r.created_at, t.name AS tier_name
         FROM channel_membership_requests r JOIN channel_membership_tiers t ON t.id = r.tier_id
         WHERE r.creator = ? AND r.status = "pending" ORDER BY r.created_at ASC, r.id ASC LIMIT 100'
    );
    $st->execute([trim($creator)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** A member's own request on a creator's page (any status), or null. */
function vs_membership_my_request(PDO $pdo, string $creator, string $member): ?array
{
    $st = $pdo->prepare('SELECT id, tier_id, status, created_at FROM channel_membership_requests WHERE creator = ? AND member = ? LIMIT 1');
    $st->execute([trim($creator), trim($member)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}