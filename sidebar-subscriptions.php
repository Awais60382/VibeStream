<?php
/**
 * Sidebar "Subscriptions" section (YouTube style):
 *   Subscriptions >            -> subscriptions.php
 *   (o) Channel name        .  -> round avatar, blue dot = new upload
 *   v Show more / Show less
 *
 * Used by vs_render_sidebar() in sidebar-navigation.php.
 * Blue dot = the channel uploaded something after you last opened it; opening the channel clears it
 * (table subscription_seen, written by subscription-seen.php).
 *
 * Changes vs the old version:
 *  - latest upload of ALL channels is loaded with 2 queries instead of 1 query per channel
 *  - tables are created at most once per session (run migrations.sql for the proper setup)
 *  - one user-id lookup (vs_sub_user_id) instead of two copies
 *  - avatar fallback no longer builds HTML inside an onerror attribute
 *  - CSS/JS are printed once per page, and the seen-request carries a CSRF token
 */

require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/csrf.php';

/** Creates the tables this sidebar needs, at most once per browser session. */
function vs_sub_tables_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['vs_sub_tables_ok'])) {
        $done = true;
        return;
    }
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `user_subscriptions` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `subscriber_id` INT NOT NULL,
            `channel_name` VARCHAR(255) NOT NULL,
            `channel_username` VARCHAR(50) NOT NULL DEFAULT \'\',
            `channel_key` CHAR(64) NOT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_subscriptions_subscriber_channel` (`subscriber_id`, `channel_key`),
            KEY `idx_user_subscriptions_channel` (`channel_username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $conn->query(
        'CREATE TABLE IF NOT EXISTS `subscription_seen` (
            `user_id` INT NOT NULL,
            `channel_key` VARCHAR(191) NOT NULL,
            `seen_at` DATETIME NOT NULL,
            PRIMARY KEY (`user_id`, `channel_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['vs_sub_tables_ok'] = 1;
    }
    $done = true;
}

/** Kept for backwards compatibility (other files may call it). */
function vs_sub_seen_ensure(mysqli $conn): void
{
    vs_sub_tables_ensure($conn);
}

function vs_sub_user_id(mysqli $conn): int
{
    $id = vs_subscription_user_id();
    if ($id > 0) {
        return $id;
    }
    $username = trim((string)($_SESSION['username'] ?? ''));
    if ($username === '') {
        return 0;
    }
    $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $username);
    if ($stmt->execute()) {
        $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    }
    $stmt->close();
    return max($id, 0);
}

/** The user opened this channel: its "new upload" dot goes away until the channel posts again. */
function vs_sub_mark_seen(mysqli $conn, int $userId, string $key): void
{
    $key = mb_substr(mb_strtolower(trim($key)), 0, 191);
    if ($userId <= 0 || $key === '') {
        return;
    }
    vs_sub_tables_ensure($conn);
    $stmt = $conn->prepare(
        'INSERT INTO subscription_seen (user_id, channel_key, seen_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE seen_at = NOW()'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('is', $userId, $key);
    $stmt->execute();
    $stmt->close();
}

/**
 * Newest video row for each of the given channels, in ONE query.
 * @param  string   $column  'username' or 'channel_name'
 * @param  string[] $values  channel usernames / names
 * @return array<string,array>  lowercase value => video row
 */
function vs_sub_latest_by(mysqli $conn, string $column, array $values): array
{
    if (!in_array($column, ['username', 'channel_name'], true)) {
        return [];
    }
    $values = array_values(array_unique(array_filter(array_map('strval', $values), fn($v) => $v !== '')));
    if (!$values) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($values), '?'));
    $sql = "SELECT v.* FROM videos v
            JOIN (SELECT `$column` AS k, MAX(created_at) AS m FROM videos WHERE `$column` IN ($marks) GROUP BY `$column`) x
              ON x.k = v.`$column` AND x.m = v.created_at";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);
    $out = [];
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $k = mb_strtolower((string)($row[$column] ?? ''));
            if ($k !== '' && !isset($out[$k])) {
                $out[$k] = $row;
            }
        }
    }
    $stmt->close();
    return $out;
}

function vs_sidebar_subscriptions_html(int $visible = 7, int $max = 40, int $newDays = 3): string
{
    global $conn;
    static $assetsPrinted = false;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $username = trim((string)($_SESSION['username'] ?? ''));
    if ($username === '') {
        // Guests get the same prompt YouTube shows.
        return '<div class="sidebar-block vs-sub">'
            . '<p class="vs-sub-guest">Sign in to follow channels and see their new uploads here.</p>'
            . '<a class="vs-sub-signin" href="' . htmlspecialchars(vs_sidebar_url('login.php')) . '">Sign in</a>'
            . '</div>';
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        return '';
    }

    $userId = vs_sub_user_id($conn);
    if ($userId <= 0) {
        return '';
    }

    $rows = [];
    $seen = [];
    $latestByUser = [];
    $latestByName = [];
    try {
        vs_sub_tables_ensure($conn);

        $stmt = $conn->prepare(
            'SELECT channel_name, channel_username, created_at FROM user_subscriptions
             WHERE subscriber_id = ? ORDER BY created_at DESC LIMIT ' . max(1, $max)
        );
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            return '';
        }
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();

        // When did this user last open each channel?
        $sq = $conn->prepare('SELECT channel_key, seen_at FROM subscription_seen WHERE user_id = ?');
        if ($sq) {
            $sq->bind_param('i', $userId);
            if ($sq->execute()) {
                $sr = $sq->get_result();
                while ($x = $sr->fetch_assoc()) {
                    $seen[(string)$x['channel_key']] = (int)strtotime((string)$x['seen_at']);
                }
            }
            $sq->close();
        }

        // Latest upload of every subscribed channel (2 queries total, not 1 per channel).
        $users = [];
        $names = [];
        foreach ($rows as $r) {
            $u = trim((string)$r['channel_username']);
            $n = trim((string)$r['channel_name']);
            if ($u !== '') {
                $users[] = $u;
            } elseif ($n !== '') {
                $names[] = $n;
            }
        }
        $latestByUser = vs_sub_latest_by($conn, 'username', $users);
        $latestByName = vs_sub_latest_by($conn, 'channel_name', $names);
    } catch (Throwable $e) {
        error_log('Sidebar subscriptions failed: ' . $e->getMessage());
        if (!$rows) {
            return '';
        }
    }

    $activeProfile = '';
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'profile.php') {
        $activeProfile = mb_strtolower(trim((string)($_GET['u'] ?? '')));
    }

    $items = [];
    foreach ($rows as $r) {
        $chUser = trim((string)$r['channel_username']);
        $chName = trim((string)$r['channel_name']);
        $label = $chUser !== '' ? $chUser : $chName;
        if ($label === '') {
            continue;
        }

        $latest = $chUser !== ''
            ? ($latestByUser[mb_strtolower($chUser)] ?? null)
            : ($latestByName[mb_strtolower($chName)] ?? null);

        $avatar = '';
        foreach (array_unique(array_filter([$chUser, $chName, $label])) as $n) {
            $avatar = avatar_for_record($n, $latest ?: []);
            if ($avatar !== '') {
                break;
            }
        }

        $latestAt = !empty($latest['created_at']) ? (int)strtotime((string)$latest['created_at']) : 0;

        // Blue dot = the channel posted after you last opened it (or after you subscribed).
        $key = mb_strtolower($label);
        $baseline = $seen[$key] ?? (int)strtotime((string)($r['created_at'] ?? ''));
        $isNew = $latestAt > 0 && $latestAt > $baseline;
        if ($isNew && $activeProfile !== '' && $activeProfile === $key) {
            // You are on this channel's page right now: that counts as opening it.
            vs_sub_mark_seen($conn, $userId, $key);
            $isNew = false;
        }

        $items[] = [
            'label'  => $label,
            'vip'    => vs_vip_badge($chUser !== '' ? $chUser : $label, $GLOBALS['conn'] ?? null, 16),
            'avatar' => $avatar,
            'latest' => $latestAt,
            'isNew'  => $isNew,
        ];
    }

    // channels with the freshest upload first
    usort($items, fn($a, $b) => $b['latest'] <=> $a['latest']);

    $hasMore = count($items) > $visible;
    $printAssets = !$assetsPrinted;
    $assetsPrinted = true;

    ob_start();
    if ($printAssets): ?>
    <style>
        .vs-sub-list { display: flex; flex-direction: column; gap: 0; }
        .vs-sub-item {
            display: flex; align-items: center; gap: 22px; min-height: 40px; padding: 0 12px; border-radius: 10px;
            color: var(--sb-text-strong, #f1f1f1); text-decoration: none; font-size: 14px; font-weight: 400;
            transition: background .15s ease;
        }
        .vs-sub-item:hover, .vs-sub-item:focus-visible { background: var(--sb-hover-bg, rgba(255,255,255,.1)); }
        .vs-sub-item:focus-visible { outline: 2px solid #3ea6ff; outline-offset: -2px; }
        .vs-sub-item.active { background: var(--sb-active-bg, rgba(255,255,255,.14)); font-weight: 600; }
        .vs-sub-av {
            position: relative; overflow: hidden;
            width: 24px; height: 24px; flex: 0 0 24px; border-radius: 50%;
            display: grid; place-items: center; color: #fff; font-size: 11px; font-weight: 700;
            background: var(--surface-2, #272727);
        }
        .vs-sub-av img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
        .vs-sub-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vs-sub-vip { flex: 0 0 auto; line-height: 0; }
        .vs-sub-extra[hidden] { display: none; }
        .vs-sub-dot { flex: 0 0 6px; width: 6px; height: 6px; border-radius: 50%; background: #3ea6ff; margin-left: 4px; }
        .vs-sub-toggle { width: 100%; background: none; border: 0; cursor: pointer; font: inherit; font-size: 14px; text-align: left; }
        .vs-sub-toggle .vs-sub-ic { width: 24px; display: grid; place-items: center; flex: 0 0 24px; }
        .vs-sub-toggle svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .sidebar-block-title { display: flex; align-items: center; gap: 6px; color: inherit; text-decoration: none; font-size: 16px; font-weight: 700; padding: 6px 12px 10px; }
        .sidebar-block-title svg { width: 18px; height: 18px; flex: 0 0 18px; }
        .vs-sub-hint, .vs-sub-guest { margin: 0; padding: 4px 12px 8px; color: var(--sb-text, #aaa); font-size: 13px; line-height: 1.45; }
        .vs-sub-signin {
            display: inline-flex; align-items: center; margin: 2px 12px 0; padding: 7px 16px; border: 1px solid var(--sb-divider, #303030);
            border-radius: 999px; color: var(--blue, #3ea6ff); font-size: 14px; font-weight: 600; text-decoration: none;
        }
        .vs-sub-signin:hover { background: rgba(62,166,255,.12); }
        @media (prefers-reduced-motion: reduce) { .vs-sub-item { transition: none; } }
    </style>
    <?php endif; ?>

    <div class="sidebar-block vs-sub" id="vsSub">
        <a class="sidebar-block-title" href="<?= $esc(vs_sidebar_url('subscriptions.php')) ?>">
            Subscriptions
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <?php if (!$items): ?>
            <p class="vs-sub-hint">Channels you subscribe to will show up here.</p>
        <?php else: ?>
        <div class="vs-sub-list" id="vsSubList">
            <?php foreach ($items as $i => $it):
                $initial = mb_strtoupper(mb_substr($it['label'], 0, 1));
                $hue = abs(crc32(mb_strtolower($it['label']))) % 360;
                $isActive = $activeProfile !== '' && $activeProfile === mb_strtolower($it['label']);
                $extra = $i >= $visible;
            ?>
                <a class="vs-sub-item<?= $isActive ? ' active' : '' ?><?= $extra ? ' vs-sub-extra' : '' ?>"
                   href="<?= $esc(vs_sidebar_url('profile.php?u=' . urlencode($it['label']))) ?>"
                   data-key="<?= $esc(mb_strtolower($it['label'])) ?>" data-latest="<?= (int)$it['latest'] ?>"
                   <?= $isActive ? 'aria-current="page"' : '' ?><?= $extra ? ' hidden' : '' ?>>
                    <span class="vs-sub-av" style="background:linear-gradient(135deg,hsl(<?= $hue ?> 65% 45%),hsl(<?= ($hue + 40) % 360 ?> 70% 35%))">
                        <span aria-hidden="true"><?= $esc($initial) ?></span>
                        <?php if ($it['avatar'] !== ''): ?>
                            <img src="<?= $esc($it['avatar']) ?>" alt="" loading="lazy" onerror="this.remove()">
                        <?php endif; ?>
                    </span>
                    <span class="vs-sub-name"><?= $esc($it['label']) ?></span>
                    <?php if ($it['vip'] !== ''): ?><span class="vs-sub-vip"><?= $it['vip'] ?></span><?php endif; ?>
                    <?php if ($it['isNew']): ?><span class="vs-sub-dot" title="New upload" role="img" aria-label="New upload"></span><?php endif; ?>
                </a>
            <?php endforeach; ?>

            <?php if ($hasMore): ?>
                <button type="button" class="vs-sub-item vs-sub-toggle" id="vsSubToggle" aria-expanded="false" aria-controls="vsSubList">
                    <span class="vs-sub-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path id="vsSubChevron" d="M6 9l6 6 6-6"/></svg></span>
                    <span id="vsSubToggleText">Show more</span>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($items): ?>
    <script>
        // Opening a channel clears its blue dot (and remembers it on the server).
        (function () {
            var url = <?= json_encode(vs_sidebar_url('subscription-seen.php')) ?>;
            var token = <?= json_encode(vs_csrf_token()) ?>;
            function seen(a) {
                var dot = a.querySelector('.vs-sub-dot');
                if (!dot) return;
                dot.remove();
                try {
                    var f = new FormData();
                    f.append('key', a.getAttribute('data-key') || '');
                    f.append('csrf_token', token);
                    if (!(navigator.sendBeacon && navigator.sendBeacon(url, f))) {
                        fetch(url, { method: 'POST', body: f, credentials: 'same-origin', keepalive: true });
                    }
                } catch (e) {}
            }
            document.querySelectorAll('#vsSub .vs-sub-item[data-key]').forEach(function (a) {
                a.addEventListener('click', function () { seen(a); });
                a.addEventListener('auxclick', function () { seen(a); });
            });
        })();
    </script>
    <?php endif; ?>

    <?php if ($hasMore): ?>
    <script>
        (function () {
            var btn = document.getElementById('vsSubToggle');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var open = btn.getAttribute('aria-expanded') !== 'true';
                document.querySelectorAll('#vsSub .vs-sub-extra').forEach(function (el) { el.hidden = !open; });
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                document.getElementById('vsSubToggleText').textContent = open ? 'Show less' : 'Show more';
                document.getElementById('vsSubChevron').setAttribute('d', open ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6');
            });
        })();
    </script>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}