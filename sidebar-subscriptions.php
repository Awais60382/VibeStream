<?php
/**
 * Sidebar "Subscriptions" section (YouTube style):
 *   Subscriptions >            -> subscriptions.php
 *   (o) Channel name        .  -> round avatar, blue dot = new upload
 *   v Show more / Show less
 *
 * Used by vs_render_sidebar() in sidebar-navigation.php.
 */

require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/avatar.php';

function vs_sidebar_subscriptions_html(int $visible = 7, int $max = 40, int $newDays = 3): string
{
    global $conn;
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

    $userId = vs_subscription_user_id();
    if ($userId <= 0) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('s', $username);
            if ($stmt->execute()) {
                $userId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
            }
            $stmt->close();
        }
    }
    if ($userId <= 0) {
        return '';
    }

    $rows = [];
    try {
        // Create the table on first use so the list works even if the migration was never run.
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

        $stmt = $conn->prepare('SELECT channel_name, channel_username FROM user_subscriptions WHERE subscriber_id = ? ORDER BY created_at DESC LIMIT ' . (int)$max);
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
    } catch (Throwable $e) {
        error_log('Sidebar subscriptions failed: ' . $e->getMessage());
        return '';
    }

    $items = [];
    foreach ($rows as $r) {
        $chUser = trim((string)$r['channel_username']);
        $chName = trim((string)$r['channel_name']);
        $label = $chUser !== '' ? $chUser : $chName;
        if ($label === '') {
            continue;
        }

        $latest = null;
        $sql = $chUser !== ''
            ? 'SELECT * FROM videos WHERE username = ? ORDER BY created_at DESC LIMIT 1'
            : 'SELECT * FROM videos WHERE channel_name = ? ORDER BY created_at DESC LIMIT 1';
        $q = $conn->prepare($sql);
        if ($q) {
            $val = $chUser !== '' ? $chUser : $chName;
            $q->bind_param('s', $val);
            if ($q->execute()) {
                $latest = $q->get_result()->fetch_assoc() ?: null;
            }
            $q->close();
        }

        $avatar = '';
        foreach (array_unique(array_filter([$chUser, $chName, $label])) as $n) {
            $avatar = avatar_for_record($n, $latest ?: []);
            if ($avatar !== '') {
                break;
            }
        }

        $latestAt = !empty($latest['created_at']) ? strtotime((string)$latest['created_at']) : 0;
        $items[] = [
            'label'  => $label,
            'avatar' => $avatar,
            'latest' => $latestAt ?: 0,
            'isNew'  => $latestAt > 0 && $latestAt >= time() - $newDays * 86400,
        ];
    }

    // channels with the freshest upload first
    usort($items, fn($a, $b) => $b['latest'] <=> $a['latest']);

    $activeProfile = '';
    if (basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'profile.php') {
        $activeProfile = mb_strtolower(trim((string)($_GET['u'] ?? '')));
    }

    $hasMore = count($items) > $visible;

    ob_start();
    ?>
    <style>
        .vs-sub-list { display: flex; flex-direction: column; gap: 0; }
        .vs-sub-item {
            display: flex; align-items: center; gap: 22px; min-height: 40px; padding: 0 12px; border-radius: 10px;
            color: var(--sb-text-strong, #f1f1f1); text-decoration: none; font-size: 14px; font-weight: 400;
            transition: background .15s ease;
        }
        .vs-sub-item:hover { background: var(--sb-hover-bg, rgba(255,255,255,.1)); }
        .vs-sub-item.active { background: var(--sb-active-bg, rgba(255,255,255,.14)); font-weight: 600; }
        .vs-sub-av {
            width: 24px; height: 24px; flex: 0 0 24px; border-radius: 50%; object-fit: cover;
            display: grid; place-items: center; color: #fff; font-size: 11px; font-weight: 700;
            background: var(--surface-2, #272727);
        }
        .vs-sub-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .vs-sub-extra[hidden] { display: none; }
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
    </style>

    <div class="sidebar-block vs-sub" id="vsSub">
        <a class="sidebar-block-title" href="<?= $esc(vs_sidebar_url('subscriptions.php')) ?>">
            Subscriptions
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
        </a>
        <?php if (!$items): ?>
            <p class="vs-sub-hint">Channels you subscribe to will show up here.</p>
        <?php else: ?>
        <div class="vs-sub-list">
            <?php foreach ($items as $i => $it):
                $initial = mb_strtoupper(mb_substr($it['label'], 0, 1));
                $hue = abs(crc32(mb_strtolower($it['label']))) % 360;
                $isActive = $activeProfile !== '' && $activeProfile === mb_strtolower($it['label']);
                $extra = $i >= $visible;
            ?>
                <a class="vs-sub-item<?= $isActive ? ' active' : '' ?><?= $extra ? ' vs-sub-extra' : '' ?>"
                   href="<?= $esc(vs_sidebar_url('profile.php?u=' . urlencode($it['label']))) ?>"
                   data-key="<?= $esc(mb_strtolower($it['label'])) ?>" data-latest="<?= (int)$it['latest'] ?>"<?= $extra ? ' hidden' : '' ?>>
                    <?php if ($it['avatar'] !== ''): ?>
                        <img class="vs-sub-av" src="<?= $esc($it['avatar']) ?>" alt="" loading="lazy"
                             onerror="this.outerHTML='<span class=&quot;vs-sub-av&quot; style=&quot;background:#64748b&quot;><?= $esc($initial) ?></span>'">
                    <?php else: ?>
                        <span class="vs-sub-av" style="background:linear-gradient(135deg,hsl(<?= $hue ?> 65% 45%),hsl(<?= ($hue + 40) % 360 ?> 70% 35%))"><?= $esc($initial) ?></span>
                    <?php endif; ?>
                    <span class="vs-sub-name"><?= $esc($it['label']) ?></span>
                </a>
            <?php endforeach; ?>

            <?php if ($hasMore): ?>
                <button type="button" class="vs-sub-item vs-sub-toggle" id="vsSubToggle" aria-expanded="false">
                    <span class="vs-sub-ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path id="vsSubChevron" d="M6 9l6 6 6-6"/></svg></span>
                    <span id="vsSubToggleText">Show more</span>
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

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