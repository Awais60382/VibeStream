<?php
/**
 * VibeStream - Main Website Notifications & Settings
 * Clean YouTube-style interface for both regular users and administrators.
 * Shows the exact settings layout with real-time saving and an optional notifications feed tab.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Any user or admin must be logged in to view their notifications/settings
if (empty($_SESSION['username'])) {
    header('Location: login.php?redirect=notifications.php');
    exit;
}

require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/notifications-store.php';

$isAdmin = isset($_SESSION['AdminSession']) || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0);

if (empty($_SESSION['notif_settings_csrf'])) {
    $_SESSION['notif_settings_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['notif_settings_csrf'];
$hasDb = isset($pdo) && $pdo instanceof PDO;

$uid = (int)($_SESSION['user_id'] ?? $_SESSION['UserId'] ?? $_SESSION['AdminId'] ?? $_SESSION['AdminID'] ?? 0);
if ($uid <= 0 && !empty($_SESSION['username']) && $hasDb) {
    try {
        $st = $pdo->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $st->execute([$_SESSION['username']]);
        $uid = (int)$st->fetchColumn();
        if ($uid > 0) {
            $_SESSION['user_id'] = $uid;
        }
    } catch (Throwable $e) {}
}

$e = static function ($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

function ns_page_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS user_notification_prefs (
            user_id INT NOT NULL PRIMARY KEY,
            desktop_notifs TINYINT(1) NOT NULL DEFAULT 0,
            subscriptions TINYINT(1) NOT NULL DEFAULT 1,
            recommended TINYINT(1) NOT NULL DEFAULT 1,
            channel_activity TINYINT(1) NOT NULL DEFAULT 1,
            comment_activity TINYINT(1) NOT NULL DEFAULT 1,
            mentions TINYINT(1) NOT NULL DEFAULT 1,
            content_reuse TINYINT(1) NOT NULL DEFAULT 1,
            promotions TINYINT(1) NOT NULL DEFAULT 0,
            uploads TINYINT(1) NOT NULL DEFAULT 1,
            comments TINYINT(1) NOT NULL DEFAULT 1,
            likes TINYINT(1) NOT NULL DEFAULT 1,
            subscribers TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    $colsNeeded = [
        'desktop_notifs'   => 'TINYINT(1) NOT NULL DEFAULT 0',
        'subscriptions'    => 'TINYINT(1) NOT NULL DEFAULT 1',
        'recommended'      => 'TINYINT(1) NOT NULL DEFAULT 1',
        'channel_activity' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'comment_activity' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'mentions'         => 'TINYINT(1) NOT NULL DEFAULT 1',
        'content_reuse'    => 'TINYINT(1) NOT NULL DEFAULT 1',
        'promotions'       => 'TINYINT(1) NOT NULL DEFAULT 0',
    ];
    try {
        $existing = $pdo->query('SHOW COLUMNS FROM user_notification_prefs')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($colsNeeded as $col => $type) {
            if (!in_array($col, $existing, true)) {
                $pdo->exec("ALTER TABLE user_notification_prefs ADD COLUMN `$col` $type");
            }
        }
    } catch (Throwable $e) {}
}

// Handle AJAX settings save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf'])) {
    header('Content-Type: application/json');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Session expired. Reload the page.');
        }
        if (!$hasDb) {
            throw new RuntimeException('Database unavailable.');
        }
        if ($uid <= 0) {
            throw new RuntimeException('User account ID not found. Please log in again.');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ns_page_ensure_table($pdo);

        $isDesk = isset($_POST['n_desktop_notifs']) ? 1 : 0;
        $isSub  = isset($_POST['n_subscriptions']) ? 1 : 0;
        $isRec  = isset($_POST['n_recommended']) ? 1 : 0;
        $isCh   = isset($_POST['n_channel_activity']) ? 1 : 0;
        $isCm   = isset($_POST['n_comment_activity']) ? 1 : 0;
        $isMen  = isset($_POST['n_mentions']) ? 1 : 0;
        $isReu  = isset($_POST['n_content_reuse']) ? 1 : 0;
        $isPro  = isset($_POST['n_promotions']) ? 1 : 0;

        $st = $pdo->prepare(
            'INSERT INTO user_notification_prefs (
                user_id, desktop_notifs, subscriptions, recommended, channel_activity,
                comment_activity, mentions, content_reuse, promotions,
                uploads, comments, likes, subscribers
            ) VALUES (
                :id, :desk, :sub, :rec, :ch, :cm, :men, :reu, :pro,
                :up, :com, :lik, :subs
            ) ON DUPLICATE KEY UPDATE
                desktop_notifs   = VALUES(desktop_notifs),
                subscriptions    = VALUES(subscriptions),
                recommended      = VALUES(recommended),
                channel_activity = VALUES(channel_activity),
                comment_activity = VALUES(comment_activity),
                mentions         = VALUES(mentions),
                content_reuse    = VALUES(content_reuse),
                promotions       = VALUES(promotions),
                uploads          = VALUES(uploads),
                comments         = VALUES(comments),
                likes            = VALUES(likes),
                subscribers      = VALUES(subscribers)'
        );
        $st->execute([
            ':id'   => $uid,
            ':desk' => $isDesk,
            ':sub'  => $isSub,
            ':rec'  => $isRec,
            ':ch'   => $isCh,
            ':cm'   => $isCm,
            ':men'  => $isMen,
            ':reu'  => $isReu,
            ':pro'  => $isPro,
            ':up'   => $isSub,
            ':com'  => $isCm,
            ':lik'  => $isCm,
            ':subs' => $isCh,
        ]);
        echo json_encode(['ok' => true]);
    } catch (Throwable $err) {
        error_log('notifications.php save failed: ' . $err->getMessage());
        echo json_encode(['ok' => false, 'error' => $err instanceof RuntimeException ? $err->getMessage() : 'Could not save settings.']);
    }
    exit;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli('localhost', 'root', '', 'vibestream');
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {}
}

if (isset($conn) && $conn instanceof mysqli) {
    vs_notifications_ensure_table($conn);
}

// Handle Mark All As Read on the feed
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'read_all') {
    if (isset($conn) && $conn instanceof mysqli && $uid > 0) {
        $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        if ($stmt) {
            $stmt->bind_param('i', $uid);
            $stmt->execute();
            $stmt->close();
        }
    }
    header('Location: notifications.php?tab=feed');
    exit;
}

$prefs = [
    'desktop_notifs'   => 0,
    'subscriptions'    => 1,
    'recommended'      => 1,
    'channel_activity' => 1,
    'comment_activity' => 1,
    'mentions'         => 1,
    'content_reuse'    => 1,
    'promotions'       => 0,
];

if ($hasDb && $uid > 0) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ns_page_ensure_table($pdo);
        $ps = $pdo->prepare('SELECT * FROM user_notification_prefs WHERE user_id = :id LIMIT 1');
        $ps->execute([':id' => $uid]);
        $row = $ps->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            foreach ($prefs as $k => $v) {
                if (isset($row[$k])) {
                    $prefs[$k] = (int)$row[$k] === 1 ? 1 : 0;
                }
            }
            if (!isset($row['subscriptions']) && isset($row['uploads'])) {
                $prefs['subscriptions'] = (int)$row['uploads'] === 1 ? 1 : 0;
            }
            if (!isset($row['channel_activity']) && isset($row['subscribers'])) {
                $prefs['channel_activity'] = (int)$row['subscribers'] === 1 ? 1 : 0;
            }
            if (!isset($row['comment_activity']) && isset($row['comments'])) {
                $prefs['comment_activity'] = (int)$row['comments'] === 1 ? 1 : 0;
            }
        }
    } catch (Throwable $e) {}
}

$prefRows = [
    [
        'subscriptions',
        'Subscriptions',
        'Notify me about activity from the <a href="index.php">channels that I\'m subscribed to</a>',
        true
    ],
    [
        'recommended',
        'Recommended videos',
        'Notify me of videos that I might like based on what I watch',
        false
    ],
    [
        'channel_activity',
        'Activity on my channel',
        'Notify me about comments and other activity on my channel or videos',
        false
    ],
    [
        'comment_activity',
        'Activity on my comments',
        'Notify me about replies, likes and other activity on my comments, and activity on my posts on other channels',
        false
    ],
    [
        'mentions',
        'Mentions',
        'Notify me when others mention my channel',
        false
    ],
    [
        'content_reuse',
        'Others reusing my content',
        'Notify me when others share, remix or respond to my content on their channels',
        false
    ],
    [
        'promotions',
        'Promotional content and offerings',
        'Notify me of promotional content and offerings, like members-only perks',
        false
    ],
];

$activeTab = $_GET['tab'] ?? 'settings';
$items = (isset($conn) && $conn instanceof mysqli && $uid > 0) ? vs_notifications_list($conn, $uid, 50) : [];
$unreadCount = (isset($conn) && $conn instanceof mysqli && $uid > 0) ? vs_notifications_unread_count($conn, $uid) : 0;

$css = vs_notification_css() . <<<'CSS'
:root {
    --yt-bg: #0f0f0f;
    --yt-text: #f1f1f1;
    --yt-muted: #aaaaaa;
    --yt-blue: #3ea6ff;
    --yt-switch-off: #383838;
}

body {
    background: var(--yt-bg);
    color: var(--yt-text);
}

.yt-container {
    max-width: 1040px;
    margin: 0 auto;
    padding: 24px 20px 80px;
}

.yt-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 28px;
    padding-bottom: 14px;
    border-bottom: 1px solid #272727;
    flex-wrap: wrap;
    gap: 16px;
}

.yt-topbar h1 {
    margin: 0;
    font-size: 24px;
    font-weight: 600;
    letter-spacing: -0.2px;
    color: #ffffff;
}

.yt-tabs {
    display: flex;
    align-items: center;
    gap: 8px;
}

.yt-tab-btn {
    background: #272727;
    color: #f1f1f1;
    padding: 7px 18px;
    border-radius: 999px;
    font-size: 14px;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.15s ease;
    border: 1px solid transparent;
}
.yt-tab-btn:hover {
    background: #383838;
}
.yt-tab-btn.active {
    background: #ffffff;
    color: #0f0f0f;
    font-weight: 600;
}

/* Two-column YouTube settings grid */
.yt-section {
    display: flex;
    gap: 52px;
    padding: 24px 0 32px;
}

.yt-section-title {
    width: 240px;
    flex-shrink: 0;
    font-size: 15px;
    font-weight: 500;
    color: #ffffff;
    line-height: 22px;
    padding-top: 2px;
}

.yt-section-items {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 28px;
    min-width: 0;
}

.yt-row {
    display: flex;
    align-items: flex-start;
    gap: 20px;
}

.yt-toggle {
    position: relative;
    width: 38px;
    height: 20px;
    flex-shrink: 0;
    margin-top: 2px;
    cursor: pointer;
    display: inline-block;
}

.yt-toggle input {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
    pointer-events: none;
}

.yt-track {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: var(--yt-switch-off);
    transition: background 0.16s ease;
}

.yt-track::after {
    content: "";
    position: absolute;
    top: 2px;
    left: 2px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #ffffff;
    transition: transform 0.16s ease;
    box-shadow: 0 1px 3px rgba(0,0,0,0.4);
}

.yt-toggle input:checked + .yt-track {
    background: var(--yt-blue);
}

.yt-toggle input:checked + .yt-track::after {
    transform: translateX(18px);
}

.yt-toggle input:focus-visible + .yt-track {
    outline: 2px solid var(--yt-blue);
    outline-offset: 2px;
}

.yt-info {
    flex: 1;
    min-width: 0;
}

.yt-title {
    display: block;
    font-size: 15px;
    font-weight: 500;
    color: #ffffff;
    cursor: pointer;
    line-height: 20px;
    margin-bottom: 4px;
}

.yt-desc {
    font-size: 13px;
    line-height: 1.5;
    color: var(--yt-muted);
}

.yt-desc a {
    color: var(--yt-blue);
    text-decoration: none;
}
.yt-desc a:hover {
    text-decoration: underline;
}

.yt-browser-row {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin: 6px 0 4px;
    font-size: 14px;
    font-weight: 600;
    color: #ffffff;
}

.yt-status-msg {
    min-height: 20px;
    font-size: 13px;
    color: var(--yt-muted);
    margin-top: 14px;
}

.notif-feed-card {
    background: #181818;
    border: 1px solid #272727;
    border-radius: 16px;
    overflow: hidden;
}
.notif-feed-card .vs-n-list {
    max-height: none;
}

@media (max-width: 768px) {
    .yt-section {
        flex-direction: column;
        gap: 16px;
        padding: 20px 0;
        border-bottom: 1px solid #222222;
    }
    .yt-section-title {
        width: 100%;
        font-size: 15px;
        font-weight: 600;
    }
    .yt-section-items {
        gap: 22px;
    }
}

/* Light mode overrides */
:root[data-theme="light"] {
    --yt-bg: #ffffff;
    --yt-text: #0f0f0f;
    --yt-muted: #606060;
    --yt-switch-off: #cccccc;
}
:root[data-theme="light"] body { background: #ffffff; color: #0f0f0f; }
:root[data-theme="light"] .yt-topbar h1 { color: #0f0f0f; }
:root[data-theme="light"] .yt-topbar { border-bottom-color: #e0e0e0; }
:root[data-theme="light"] .yt-section-title { color: #0f0f0f; }
:root[data-theme="light"] .yt-title { color: #0f0f0f; }
:root[data-theme="light"] .yt-desc { color: #606060; }
:root[data-theme="light"] .yt-desc a { color: #065fd4; }
:root[data-theme="light"] .yt-browser-row { color: #0f0f0f; }
:root[data-theme="light"] .yt-status-msg { color: #606060; }
:root[data-theme="light"] .yt-track { background: #cccccc; }
:root[data-theme="light"] .yt-tab-btn { background: #f2f2f2; color: #0f0f0f; }
:root[data-theme="light"] .yt-tab-btn:hover { background: #e0e0e0; }
:root[data-theme="light"] .yt-tab-btn.active { background: #0f0f0f; color: #ffffff; }
:root[data-theme="light"] .notif-feed-card { background: #ffffff; border-color: #e0e0e0; }
:root[data-theme="light"] .yt-section { border-bottom-color: #e0e0e0; }
CSS;

$js = <<<'JS'
(function () {
    var form = document.getElementById('ytSettingsForm');
    var status = document.getElementById('ytStatus');
    if (!form) return;

    function detectBrowser() {
        var ua = navigator.userAgent;
        if (ua.indexOf('Edg/') !== -1) return 'Microsoft Edge';
        if (ua.indexOf('Brave') !== -1 || (navigator.brave && navigator.brave.isBrave)) return 'Brave';
        if (ua.indexOf('Firefox/') !== -1) return 'Firefox';
        if (ua.indexOf('OPR/') !== -1 || ua.indexOf('Opera/') !== -1) return 'Opera';
        if (ua.indexOf('Chrome/') !== -1) return 'Chrome';
        if (ua.indexOf('Safari/') !== -1) return 'Safari';
        return 'Browser';
    }

    var bLabel = document.getElementById('ytBrowserLabel');
    if (bLabel) bLabel.textContent = detectBrowser();

    var deskToggle = document.getElementById('n_desktop_notifs');
    if (deskToggle && 'Notification' in window) {
        deskToggle.addEventListener('change', function () {
            if (this.checked) {
                if (Notification.permission === 'default') {
                    Notification.requestPermission().then(function (perm) {
                        if (perm === 'granted') {
                            try {
                                new Notification('VibeStream', {
                                    body: 'Notifications in this browser are now enabled.',
                                    icon: 'branding-icon.php'
                                });
                            } catch (e) {}
                        } else if (perm === 'denied') {
                            deskToggle.checked = false;
                            saveSettings();
                            if (status) status.textContent = 'Notifications were blocked in your browser settings.';
                        }
                    });
                } else if (Notification.permission === 'denied') {
                    this.checked = false;
                    saveSettings();
                    alert('Notifications are blocked in your browser settings. Please allow notifications in your browser URL bar.');
                }
            }
        });
    }

    function saveSettings() {
        if (status) status.textContent = 'Saving...';
        fetch('notifications.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: new FormData(form),
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (status) {
                status.textContent = d.ok ? 'Saved' : (d.error || 'Could not save.');
                if (d.ok) {
                    setTimeout(function () { if (status.textContent === 'Saved') status.textContent = ''; }, 2000);
                }
            }
        })
        .catch(function () {
            if (status) status.textContent = 'Could not save. Check your connection.';
        });
    }

    form.addEventListener('submit', function (e) { e.preventDefault(); });
    form.addEventListener('change', saveSettings);
})();
JS;

vs_page_start('Notifications', 'notifications', $css);
?>
<div class="yt-container">
    <div class="yt-topbar">
        <h1>Notifications</h1>
        <div class="yt-tabs">
            <a href="notifications.php?tab=settings" class="yt-tab-btn<?= $activeTab !== 'feed' ? ' active' : '' ?>">Settings</a>
            <a href="notifications.php?tab=feed" class="yt-tab-btn<?= $activeTab === 'feed' ? ' active' : '' ?>">
                Activity Feed <?= $unreadCount > 0 ? '(' . (int)$unreadCount . ')' : '' ?>
            </a>
        </div>
    </div>

    <?php if ($activeTab === 'feed'): ?>
        <!-- Activity Feed Tab -->
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
            <p style="margin:0;color:var(--yt-muted);font-size:14px;">Recent activity from channels you subscribe to, likes, and comments.</p>
            <?php if ($unreadCount > 0): ?>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="action" value="read_all">
                    <button type="submit" style="background:#272727;color:#fff;border:1px solid #383838;padding:6px 16px;border-radius:999px;cursor:pointer;font-size:13px;font-weight:600;">Mark all as read</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="notif-feed-card" data-vs-notif data-signed="1" data-csrf="<?= htmlspecialchars($_SESSION['csrf'] ?? '') ?>" data-api="notifications-api.php">
            <div class="vs-n-list" data-vs-n-list>
                <?= vs_notification_list_html($items) ?>
            </div>
        </div>

    <?php else: ?>
        <!-- Settings Tab: Exact layout from user screenshot -->
        <form id="ytSettingsForm" method="post">
            <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">

            <!-- Desktop notifications section -->
            <section class="yt-section">
                <div class="yt-section-title">Desktop notifications</div>
                <div class="yt-section-items">
                    <div class="yt-row">
                        <label class="yt-toggle" for="n_desktop_notifs" aria-label="Get notifications in this browser">
                            <input type="checkbox" id="n_desktop_notifs" name="n_desktop_notifs" value="1"<?php echo !empty($prefs['desktop_notifs']) ? ' checked' : ''; ?>>
                            <span class="yt-track"></span>
                        </label>
                        <div class="yt-info">
                            <label class="yt-title" for="n_desktop_notifs">Get notifications in this browser</label>
                            <div class="yt-browser-row">
                                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                    <circle cx="12" cy="12" r="10" fill="#EA4335"/>
                                    <path d="M12 2a10 10 0 0 1 8.66 5H12a5 5 0 0 0-4.33 2.5L4.72 4.41A9.96 9.96 0 0 1 12 2z" fill="#EA4335"/>
                                    <path d="M22 12a10 10 0 0 1-5 8.66L14.05 15.6A5 5 0 0 0 17 12h-5V7h8.66A9.96 9.96 0 0 1 22 12z" fill="#FBBC05"/>
                                    <path d="M12 22a10 10 0 0 1-8.66-5l2.95-5.1A5 5 0 0 0 12 17v-5l4.33 7.5A9.96 9.96 0 0 1 12 22z" fill="#34A853"/>
                                    <circle cx="12" cy="12" r="5" fill="#ffffff"/>
                                    <circle cx="12" cy="12" r="3.8" fill="#4285F4"/>
                                </svg>
                                <span id="ytBrowserLabel">Chrome</span>
                            </div>
                            <div class="yt-desc">Receive notifications on your computer, even if you're not watching VibeStream</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Your preferences section -->
            <section class="yt-section">
                <div class="yt-section-title">Your preferences</div>
                <div class="yt-section-items">
                    <?php foreach ($prefRows as [$key, $title, $desc, $isHtml]): ?>
                        <div class="yt-row">
                            <label class="yt-toggle" for="n_<?php echo $e($key); ?>" aria-label="<?php echo $e($title); ?>">
                                <input type="checkbox" id="n_<?php echo $e($key); ?>" name="n_<?php echo $e($key); ?>" value="1"<?php echo !empty($prefs[$key]) ? ' checked' : ''; ?>>
                                <span class="yt-track"></span>
                            </label>
                            <div class="yt-info">
                                <label class="yt-title" for="n_<?php echo $e($key); ?>"><?php echo $e($title); ?></label>
                                <div class="yt-desc"><?php echo $isHtml ? $desc : $e($desc); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </form>

        <div class="yt-status-msg" id="ytStatus" role="status" aria-live="polite"></div>
    <?php endif; ?>
</div>
<?php vs_page_end($js); ?>