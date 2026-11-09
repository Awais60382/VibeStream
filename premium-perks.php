<?php
/**
 * VibeStream - Premium perks: the VIP badge and the limits each plan gets.
 * Put this file in the VibeStream main folder (next to premium-store.php).
 *
 *   vs_vip_badge_html('name')         -> a small gold "VIP" (Premium) or purple "VIP+" (Premium Plus) tag, or '' for free accounts
 *   vs_vip_prefetch(['a', 'b'])        -> looks up many names with one query (used for comment lists)
 *   vs_perk_limit('name', 'playlists') -> the cap for that account, or null when it has no cap
 *
 * The numbers live in VS_PREMIUM_LIMITS in premium-store.php (one place to change them).
 */
require_once __DIR__ . '/premium-store.php';

if (!defined('VS_VIP_INCLUDE_ADMINS')) {
    define('VS_VIP_INCLUDE_ADMINS', true);   // admins always have Premium, so they get the badge too (set false to hide it for admins)
}
if (!defined('VS_PERK_UNLIMITED')) {
    define('VS_PERK_UNLIMITED', 100000);     // a limit this big (or more) means "no cap"
}

function vs_perk_pdo(): ?PDO
{
    try {
        return vs_premium_pdo();
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<string,string> lower-case user name => 'plus' | 'premium' | '' */
function &vs_vip_cache(): array
{
    static $cache = [];
    return $cache;
}

function vs_vip_prefetch(array $names): void
{
    $cache = &vs_vip_cache();
    $want = [];
    foreach ($names as $n) {
        $k = mb_strtolower(trim((string)$n));
        if ($k !== '' && !array_key_exists($k, $cache)) {
            $want[$k] = true;
        }
    }
    if (!$want) {
        return;
    }
    foreach ($want as $k => $unused) {
        $cache[$k] = '';
    }
    $pdo = vs_perk_pdo();
    if (!$pdo) {
        return;
    }
    $keys = array_keys($want);
    $marks = implode(',', array_fill(0, count($keys), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT username, plan FROM premium_members
             WHERE status = 'active' AND expires_at > NOW() AND LOWER(username) IN ($marks)"
        );
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cache[mb_strtolower((string)$r['username'])] = strpos((string)$r['plan'], 'plus') === 0 ? 'plus' : 'premium';
        }
    } catch (Throwable $e) {
        // no premium table yet: everyone is simply free
    }
    if (VS_VIP_INCLUDE_ADMINS) {
        try {
            $st = $pdo->prepare("SELECT username FROM users WHERE LOWER(role) = 'admin' AND LOWER(username) IN ($marks)");
            $st->execute($keys);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) {
                $cache[mb_strtolower((string)$name)] = 'plus';
            }
        } catch (Throwable $e) {
            // users.role missing: skip
        }
    }
}

/** 'plus', 'premium' or '' (free) */
function vs_vip_tier(string $username): string
{
    $k = mb_strtolower(trim($username));
    if ($k === '') {
        return '';
    }
    $cache = &vs_vip_cache();
    if (!array_key_exists($k, $cache)) {
        vs_vip_prefetch([$k]);
    }
    return (string)($cache[$k] ?? '');
}

function vs_vip_badge_html(string $username): string
{
    $tier = vs_vip_tier($username);
    if ($tier === '') {
        return '';
    }
    static $styled = false;
    $css = '';
    if (!$styled) {
        $styled = true;
        $css = '<style>.vs-vip{display:inline-block;margin-left:6px;padding:0 7px;border-radius:999px;'
            . 'background:linear-gradient(135deg,#f5b942,#ff8a3d);color:#2a1700;font:800 10px/17px system-ui,sans-serif;'
            . 'letter-spacing:.04em;vertical-align:middle;white-space:nowrap}'
            . '.vs-vip.vs-vip-plus{background:linear-gradient(135deg,#c084fc,#6366f1);color:#fff}</style>';
    }
    return $css . ($tier === 'plus'
        ? '<span class="vs-vip vs-vip-plus" title="Premium Plus member">VIP+</span>'
        : '<span class="vs-vip" title="Premium member">VIP</span>');
}

/** The cap for this account ('playlists', 'watch_later', 'uploads_per_day'), or null when there is no cap. */
function vs_perk_limit(string $username, string $key): ?int
{
    $limits = vs_premium_user_limits(vs_perk_pdo(), $username);
    if (!isset($limits[$key])) {
        return null;
    }
    $v = (int)$limits[$key];
    return $v >= VS_PERK_UNLIMITED ? null : max(0, $v);
}

function vs_watch_later_ensure(PDO $pdo): void
{
    vs_premium_ensure($pdo);
}

function vs_watch_later_ids(PDO $pdo, string $username): array
{
    $st = $pdo->prepare('SELECT video_id FROM watch_later_items WHERE username = ? ORDER BY sort_order ASC, added_at ASC, id ASC');
    $st->execute([$username]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Apply an account-scoped Watch Later change while holding a per-account database lock.
 * The same limits are checked server-side, so changing browser storage cannot bypass them.
 */
function vs_watch_later_change(PDO $pdo, string $username, string $action, array $ids): array
{
    if ($username === '' || !in_array($action, ['add', 'remove', 'order', 'sync'], true)) {
        throw new InvalidArgumentException('Invalid Watch Later request.');
    }
    vs_watch_later_ensure($pdo);

    $skipped = max(0, count($ids) - 300);
    $clean = [];
    foreach (array_slice($ids, 0, 300) as $id) {
        $id = trim((string)$id);
        if ($id !== '' && strlen($id) <= 191 && !in_array($id, $clean, true)) {
            $clean[] = $id;
        }
    }
    if (in_array($action, ['add', 'remove'], true) && count($clean) !== 1) {
        throw new InvalidArgumentException('Choose a valid video.');
    }

    $lockName = 'vs_wl_' . substr(hash('sha256', mb_strtolower($username)), 0, 48);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Watch Later is busy. Please try again.');
    }

    try {
        $pdo->beginTransaction();
        $current = vs_watch_later_ids($pdo, $username);

        if ($action === 'remove') {
            $delete = $pdo->prepare('DELETE FROM watch_later_items WHERE username = ? AND video_key = ?');
            $delete->execute([$username, hash('sha256', $clean[0])]);
        } elseif ($action === 'add' || $action === 'sync') {
            $limit = vs_perk_limit($username, 'watch_later');
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO watch_later_items (username, video_id, video_key, sort_order, added_at)
                 VALUES (?, ?, ?, ?, NOW())'
            );
            $exists = $pdo->prepare('SELECT 1 FROM videos WHERE CAST(id AS CHAR) = ? LIMIT 1');
            foreach ($clean as $id) {
                if (in_array($id, $current, true)) {
                    continue;
                }
                $exists->execute([$id]);
                if (!$exists->fetchColumn() || ($limit !== null && count($current) >= $limit)) {
                    $skipped++;
                    continue;
                }
                $insert->execute([$username, $id, hash('sha256', $id), count($current),]);
                if ($insert->rowCount() === 1) {
                    $current[] = $id;
                } else {
                    $skipped++;
                }
            }
        } elseif ($action === 'order') {
            $ordered = [];
            foreach ($clean as $id) {
                if (in_array($id, $current, true)) {
                    $ordered[] = $id;
                }
            }
            foreach ($current as $id) {
                if (!in_array($id, $ordered, true)) {
                    $ordered[] = $id;
                }
            }
            $update = $pdo->prepare('UPDATE watch_later_items SET sort_order = ? WHERE username = ? AND video_key = ?');
            foreach ($ordered as $position => $id) {
                $update->execute([$position, $username, hash('sha256', $id)]);
            }
        }
        $pdo->commit();
        return ['ids' => vs_watch_later_ids($pdo, $username), 'skipped' => $skipped];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
}

/** How many videos and shorts this user added today, or null when the count cannot be verified. */
function vs_perk_uploads_today(mysqli $conn, string $username): ?int
{
    try {
        $st = $conn->prepare('SELECT COUNT(*) FROM `videos` WHERE LOWER(`username`) = LOWER(?) AND `created_at` >= CURDATE()');
        $st->bind_param('s', $username);
        $st->execute();
        $row = $st->get_result()->fetch_row();
        $st->close();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) {
        error_log('Premium upload count could not be checked: ' . $e->getMessage());
        return null;
    }
}
