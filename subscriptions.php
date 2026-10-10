<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/subscription-store.php';
require_once __DIR__ . '/avatar.php';
require_once __DIR__ . '/connection.php';

if (empty($_SESSION['username'])) {
    header('Location: login.php?redirect=subscriptions.php');
    exit;
}

$username = trim((string)$_SESSION['username']);
$userId = vs_subscription_user_id();
if ($userId <= 0) {
    $userLookup = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    if ($userLookup) {
        $userLookup->bind_param('s', $username);
        if ($userLookup->execute()) {
            $userId = (int)($userLookup->get_result()->fetch_assoc()['id'] ?? 0);
        }
        $userLookup->close();
    }
    if ($userId > 0) {
        $_SESSION['user_id'] = $userId;
    } else {
        http_response_code(403);
        exit('Sign in again to manage your subscriptions.');
    }
}

$csrf = $_SESSION['subscription_csrf'] ?? '';
if (!is_string($csrf) || $csrf === '') {
    $csrf = bin2hex(random_bytes(32));
    $_SESSION['subscription_csrf'] = $csrf;
}

$errorMessage = '';
$successMessage = $_SESSION['subscription_notice'] ?? '';
unset($_SESSION['subscription_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errorMessage = 'That request expired. Refresh the page and try again.';
    } else {
        $channelName = trim((string)($_POST['channel_name'] ?? ''));
        $channelHash = hash('sha256', mb_strtolower($channelName));
        $lookup = $conn->prepare('SELECT channel_name, channel_username FROM user_subscriptions WHERE subscriber_id = ? AND channel_key = ? LIMIT 1');
        if (!$lookup) {
            $errorMessage = 'Subscriptions are unavailable. Run the subscriptions database migration.';
        } else {
            $lookup->bind_param('is', $userId, $channelHash);
            if (!$lookup->execute()) {
                $errorMessage = 'Could not check your subscription. Please try again.';
            } else {
                $subscription = $lookup->get_result()->fetch_assoc();
                if (!$subscription) {
                    $errorMessage = 'That subscription was not found.';
                } else {
                    $channelName = (string)$subscription['channel_name'];
                    $channelUsername = (string)$subscription['channel_username'];
                    $videoLookupSql = $channelUsername !== ''
                        ? 'SELECT id FROM videos WHERE username = ? ORDER BY created_at DESC LIMIT 1'
                        : 'SELECT id FROM videos WHERE channel_name = ? ORDER BY created_at DESC LIMIT 1';
                    $videoLookup = $conn->prepare($videoLookupSql);
                    $videoId = '';
                    if ($videoLookup) {
                        $videoLookupValue = $channelUsername !== '' ? $channelUsername : $channelName;
                        $videoLookup->bind_param('s', $videoLookupValue);
                        if ($videoLookup->execute()) {
                            $videoId = (string)($videoLookup->get_result()->fetch_assoc()['id'] ?? '');
                        }
                        $videoLookup->close();
                    }
                    try {
                        vs_subscription_change($conn, $channelName, $channelUsername, $videoId, false);
                        $_SESSION['subscription_notice'] = 'Unsubscribed from ' . ($channelUsername !== '' ? $channelUsername : $channelName) . '.';
                        header('Location: subscriptions.php');
                        exit;
                    } catch (Throwable $error) {
                        $errorMessage = $error->getMessage();
                    }
                }
            }
            $lookup->close();
        }
    }
}

$subscriptions = [];
try {
    vs_subscription_session_sync($conn);
    $query = $conn->prepare('SELECT channel_name, channel_username, created_at FROM user_subscriptions WHERE subscriber_id = ? ORDER BY created_at DESC');
    if (!$query) {
        throw new RuntimeException('Could not load subscriptions. Run the subscriptions database migration.');
    }
    $query->bind_param('i', $userId);
    if (!$query->execute()) {
        throw new RuntimeException('Could not load subscriptions. Please refresh and try again.');
    }
    $result = $query->get_result();
    while ($subscription = $result->fetch_assoc()) {
        $channelUsername = trim((string)$subscription['channel_username']);
        $channelName = trim((string)$subscription['channel_name']);
        $channelProfileName = $channelUsername !== '' ? $channelUsername : $channelName;
        $subscriberCount = 0;
        $channelDescription = '';
        if ($channelUsername !== '') {
            $metaQuery = $conn->prepare('SELECT MAX(COALESCE(subscribers, 0)) AS subscriber_count FROM videos WHERE username = ?');
            if ($metaQuery) {
                $metaQuery->bind_param('s', $channelUsername);
                if ($metaQuery->execute()) {
                    $subscriberCount = (int)($metaQuery->get_result()->fetch_assoc()['subscriber_count'] ?? 0);
                }
                $metaQuery->close();
            }
        } else {
            $metaQuery = $conn->prepare('SELECT MAX(COALESCE(subscribers, 0)) AS subscriber_count FROM videos WHERE channel_name = ?');
            if ($metaQuery) {
                $metaQuery->bind_param('s', $channelName);
                if ($metaQuery->execute()) {
                    $subscriberCount = (int)($metaQuery->get_result()->fetch_assoc()['subscriber_count'] ?? 0);
                }
                $metaQuery->close();
            }
        }
        $latest = null;
        $latestQuerySql = $channelUsername !== ''
            ? 'SELECT * FROM videos WHERE username = ? ORDER BY created_at DESC LIMIT 1'
            : 'SELECT * FROM videos WHERE channel_name = ? ORDER BY created_at DESC LIMIT 1';
        $latestQuery = $conn->prepare($latestQuerySql);
        if ($latestQuery) {
            $latestQueryValue = $channelUsername !== '' ? $channelUsername : $channelName;
            $latestQuery->bind_param('s', $latestQueryValue);
            if ($latestQuery->execute()) {
                $latest = $latestQuery->get_result()->fetch_assoc() ?: null;
            }
            $latestQuery->close();
        }
        // Same avatar lookup the home page uses: profile avatar first, then any avatar stored on the video row.
        $channelAvatar = '';
        foreach (array_unique(array_filter([$channelUsername, $channelName, $channelProfileName])) as $avatarName) {
            $channelAvatar = avatar_for_record($avatarName, $latest ?: []);
            if ($channelAvatar !== '') {
                break;
            }
        }
        $channelDescription = !empty($latest['title'])
            ? 'Latest upload: ' . (string)$latest['title']
            : "View this creator's profile and uploaded videos.";
        $subscriptions[] = [
            'channel_name' => $channelName,
            'channel_username' => $channelUsername,
            'profile_name' => $channelProfileName,
            'subscriber_count' => $subscriberCount,
            'description' => $channelDescription,
            'created_at' => (string)$subscription['created_at'],
            'avatar' => $channelAvatar,
        ];
    }
    $query->close();
} catch (Throwable $error) {
    error_log('Could not load VibeStream subscriptions: ' . $error->getMessage());
    $errorMessage = $error->getMessage();
}

$extraCss = <<<'CSS'
.subscriptions-heading { max-width: 1040px; margin: 22px auto 18px; padding: 0 18px; }
.subscriptions-heading h1 { margin: 0 0 12px; font-size: clamp(26px, 4vw, 34px); letter-spacing: -0.03em; }
.subscription-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.subscription-toolbar p { margin: 0; color: var(--muted); }
.subscription-sort { padding: 7px 10px; border: 1px solid var(--border); border-radius: 9px; color: var(--text); background: var(--surface-2); }
.subscription-list { display: grid; gap: 0; max-width: 1040px; margin: 0 auto; padding: 0 18px 48px; }
.subscription-card { display: grid; grid-template-columns: 78px minmax(0, 1fr) auto; align-items: center; gap: 16px; min-height: 112px; padding: 12px 10px; border-bottom: 1px solid var(--border); }
.subscription-profile-link { display: flex; align-items: center; gap: 16px; min-width: 0; color: inherit; text-decoration: none; }
.subscription-profile-link:hover .subscription-info h2 { text-decoration: underline; }
.subscription-avatar { width: 78px; height: 78px; flex: 0 0 78px; border-radius: 50%; object-fit: cover; background: var(--surface-2); }
.subscription-initial { display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 26px; }
.subscription-info { min-width: 0; }
.subscription-info h2 { overflow: hidden; margin: 0 0 4px; font-size: 16px; font-weight: 700; text-overflow: ellipsis; white-space: nowrap; }
.subscription-meta { margin: 0 0 4px; color: var(--muted); font-size: 12px; }
.subscription-description { display: -webkit-box; overflow: hidden; margin: 0; color: var(--muted); font-size: 12px; line-height: 1.45; -webkit-box-orient: vertical; -webkit-line-clamp: 2; }
.subscription-actions { display: flex; align-items: center; justify-content: flex-end; }
.subscription-menu { position: relative; }
.subscription-menu summary { display: flex; align-items: center; gap: 8px; min-width: 116px; justify-content: center; padding: 9px 13px; border: 1px solid var(--border); border-radius: 999px; background: var(--surface-2); color: var(--text); cursor: pointer; font-size: 13px; font-weight: 700; list-style: none; }
.subscription-menu summary::-webkit-details-marker { display: none; }
.subscription-menu summary::after { content: "⌄"; font-size: 14px; }
.subscription-menu[open] summary { border-color: var(--border-strong); }
.subscription-menu form { position: absolute; z-index: 5; top: calc(100% + 6px); right: 0; min-width: 150px; padding: 5px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface); box-shadow: var(--shadow-raised); }
.unsubscribe-button { width: 100%; padding: 9px 10px; border: 0; border-radius: 7px; color: var(--text); background: transparent; text-align: left; cursor: pointer; }
.unsubscribe-button:hover { background: var(--surface-hover); color: #e56a79; }
.subscription-alert, .subscription-empty { max-width: 1040px; margin: 0 auto 18px; padding: 14px 24px; }
.subscription-alert { border-radius: 12px; background: rgba(220, 60, 60, .14); color: #ff9b9b; }
.subscription-success { background: rgba(45, 180, 120, .14); color: #278552; }
html[data-theme="dark"] .subscription-success { color: #8de2b4; }
.subscription-empty { padding-top: 46px; padding-bottom: 46px; border: 1px dashed var(--border); border-radius: 18px; text-align: center; color: var(--muted); }
@media (max-width: 640px) {
  .subscriptions-heading { margin-top: 18px; padding-inline: 14px; }
  .subscription-list { padding-inline: 14px; }
  .subscription-card { grid-template-columns: minmax(0, 1fr) auto; gap: 10px; min-height: 98px; padding: 12px 4px; }
  .subscription-profile-link { gap: 12px; }
  .subscription-avatar { width: 58px; height: 58px; flex-basis: 58px; }
  .subscription-initial { font-size: 21px; }
  .subscription-menu summary { min-width: 0; padding: 8px 10px; font-size: 12px; }
  .subscription-meta { font-size: 11px; }
  .subscription-description, .subscription-latest { display: none; }
  .subscription-alert, .subscription-empty { margin-inline: 14px; padding-inline: 14px; }
}
CSS;

vs_page_start('Subscriptions', 'subscriptions', $extraCss);
?>
<header class="subscriptions-heading">
  <h1>All subscriptions</h1>
  <div class="subscription-toolbar">
    <p>Channels you follow</p>
    <label class="visually-hidden" for="subscriptionSort">Sort subscriptions</label>
    <select class="subscription-sort" id="subscriptionSort">
      <option value="relevant">Most relevant</option>
      <option value="recent">Recently subscribed</option>
      <option value="name">A–Z</option>
    </select>
  </div>
</header>

<?php if ($errorMessage !== ''): ?>
  <div class="subscription-alert" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<?php if ($successMessage !== ''): ?>
  <div class="subscription-alert subscription-success" role="status"><?= htmlspecialchars((string)$successMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="subscription-list" aria-label="Subscribed channels">
  <?php if (!$subscriptions && $errorMessage === ''): ?>
    <div class="subscription-empty">
      <h2>You haven't subscribed to any channels yet</h2>
      <p>Subscribe to a creator while watching a video or a Short and they will appear here.</p>
      <a class="create-btn" href="index.php">Explore videos</a>
    </div>
  <?php endif; ?>
  <?php foreach ($subscriptions as $subscription):
      $label = $subscription['profile_name'];
      $initial = mb_strtoupper(mb_substr($label, 0, 1));
  ?>
    <article class="subscription-card" data-subscription-row data-channel="<?= htmlspecialchars(mb_strtolower($label), ENT_QUOTES, 'UTF-8') ?>" data-created="<?= htmlspecialchars($subscription['created_at'], ENT_QUOTES, 'UTF-8') ?>">
      <a class="subscription-profile-link" href="profile.php?u=<?= urlencode($label) ?>" aria-label="View <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>'s profile">
      <?php if ($subscription['avatar'] !== ''): ?>
        <img class="subscription-avatar" src="<?= htmlspecialchars($subscription['avatar'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" onerror="this.outerHTML='<span class=&quot;subscription-avatar subscription-initial&quot; style=&quot;background:#64748b&quot;><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>'">
      <?php else: ?>
        <?php $hue = abs(crc32(mb_strtolower($label))) % 360; ?>
        <span class="subscription-avatar subscription-initial" style="background:linear-gradient(135deg,hsl(<?= $hue ?> 65% 45%),hsl(<?= ($hue + 40) % 360 ?> 70% 35%))" aria-hidden="true"><?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <div class="subscription-info">
        <h2><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="subscription-meta">@<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> · <?= number_format((int)$subscription['subscriber_count']) ?> subscribers</p>
        <p class="subscription-description"><?= htmlspecialchars($subscription['description'], ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      </a>
      <div class="subscription-actions">
        <details class="subscription-menu">
          <summary aria-label="Subscription options for <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/></svg>
            Subscribed
          </summary>
          <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="channel_name" value="<?= htmlspecialchars($subscription['channel_name'], ENT_QUOTES, 'UTF-8') ?>">
            <button class="unsubscribe-button" type="submit">Unsubscribe</button>
          </form>
        </details>
      </div>
    </article>
  <?php endforeach; ?>
</section>
<script>
  (function () {
    var sort = document.getElementById('subscriptionSort');
    var list = document.querySelector('.subscription-list');
    if (!sort || !list) return;
    sort.addEventListener('change', function () {
      var rows = Array.prototype.slice.call(list.querySelectorAll('[data-subscription-row]'));
      rows.sort(function (a, b) {
        if (sort.value === 'name') return a.dataset.channel.localeCompare(b.dataset.channel);
        if (sort.value === 'recent') return b.dataset.created.localeCompare(a.dataset.created);
        return 0;
      });
      rows.forEach(function (row) { list.appendChild(row); });
    });
  })();
</script>
<?php
vs_page_end();