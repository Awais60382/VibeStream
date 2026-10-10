<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/notifications-store.php';
include __DIR__ . '/connection.php';
require_once __DIR__ . '/avatar.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || empty($_SESSION['username'])) {
    header('Location: login.php?redirect=notifications.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

vs_notifications_ensure_table($conn);

$savedNotice = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'clear') {
            $stmt = $conn->prepare('DELETE FROM user_notifications WHERE user_id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'read_all') {
            $stmt = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ?');
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        } elseif ($action === 'prefs') {
            vs_notification_prefs_save($conn, $userId, [
                'uploads' => !empty($_POST['uploads']),
                'comments' => !empty($_POST['comments']),
                'likes' => !empty($_POST['likes']),
                'subscribers' => !empty($_POST['subscribers']),
            ]);
            header('Location: notifications.php?saved=1#settings');
            exit;
        }
    }
    header('Location: notifications.php');
    exit;
}
$savedNotice = isset($_GET['saved']);

$items = vs_notifications_list($conn, $userId, 60);
$prefs = vs_notification_prefs($conn, $userId);

$unread = 0;
foreach ($items as $item) {
    if (!(int)$item['is_read']) $unread++;
}

// Opening the page counts as reading the list (the dots below still show what was new).
if ($unread > 0) {
    $mark = $conn->prepare('UPDATE user_notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $mark->bind_param('i', $userId);
    $mark->execute();
    $mark->close();
}

$extraCss = <<<'CSS'
.notif-wrap { max-width: 760px; margin: 0 auto; }
.notif-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
.notif-head h1 { margin: 0; font-size: 26px; }
.notif-head .sub { margin: 4px 0 0; color: var(--muted); font-size: 14px; }
.notif-actions { display: flex; gap: 8px; }
.notif-actions form { margin: 0; }
.notif-btn {
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); cursor: pointer;
    border-radius: 999px; padding: 8px 16px; font-size: 13px; font-weight: 600;
}
.notif-btn:hover { border-color: #5b8cff; }
.notif-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.notif-tab {
    border: 0; cursor: pointer; font: inherit; font-size: 14px; font-weight: 600; padding: 7px 14px;
    border-radius: 8px; background: var(--surface-2); color: var(--text);
}
.notif-tab.active { background: var(--text); color: var(--bg, #0f0f0f); }
.notif-card { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
.notif-card .vs-n-list { max-height: none; }
.notif-card .vs-n-item { border-bottom: 1px solid var(--border); }
.notif-card .vs-n-item:last-child { border-bottom: 0; }
.notif-card .vs-n-thumb { width: 120px; height: 68px; flex-basis: 120px; }
.notif-empty {
    text-align: center; padding: 48px 20px; color: var(--muted); font-size: 15px; line-height: 1.6;
    border: 1px dashed var(--border); border-radius: 20px;
}
.notif-empty a { color: #5b8cff; text-decoration: underline; }
.notif-settings { margin-top: 28px; padding: 20px; border: 1px solid var(--border); border-radius: 16px; background: var(--surface); }
.notif-settings h2 { margin: 0 0 4px; font-size: 18px; }
.notif-settings p.hint { margin: 0 0 14px; color: var(--muted); font-size: 13px; }
.notif-pref { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 0; border-top: 1px solid var(--border); font-size: 15px; }
.notif-pref small { display: block; color: var(--muted); font-size: 12px; margin-top: 2px; }
.notif-pref input { width: 20px; height: 20px; accent-color: #5b8cff; cursor: pointer; }
.notif-saved { margin-bottom: 12px; padding: 10px 14px; border-radius: 10px; background: rgba(45,180,120,.14); color: #3fb37f; font-size: 14px; }
CSS;

vs_page_start('Notifications', 'notifications', $extraCss);
?>
<div class="page">
    <div class="notif-wrap">
        <div class="notif-head">
            <div>
                <h1>Notifications</h1>
                <p class="sub"><?= $unread > 0 ? (int)$unread . ' new' : 'You are all caught up.' ?></p>
            </div>
            <?php if ($items): ?>
                <div class="notif-actions">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="read_all">
                        <button type="submit" class="notif-btn">Mark all as read</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Clear all notifications?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="notif-btn">Clear all</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$items): ?>
            <div class="notif-empty">
                No notifications yet.<br>
                You will be told here when a channel you subscribe to uploads, and when someone likes or comments on your videos or subscribes to you.
                <br><a href="subscriptions.php">Manage subscriptions</a>
            </div>
        <?php else: ?>
            <div class="notif-tabs" id="notifTabs">
                <button type="button" class="notif-tab active" data-filter="all">All</button>
                <button type="button" class="notif-tab" data-filter="uploads">Uploads</button>
                <button type="button" class="notif-tab" data-filter="activity">Likes &amp; comments</button>
                <button type="button" class="notif-tab" data-filter="subscribers">Subscribers</button>
            </div>
            <div class="notif-card">
                <div class="vs-n-list" id="notifList">
                    <?php foreach ($items as $n) { echo vs_notification_item_html($n); } ?>
                </div>
            </div>
        <?php endif; ?>

        <section class="notif-settings" id="settings">
            <h2>Notification settings</h2>
            <p class="hint">Choose what you want to be notified about.</p>
            <?php if ($savedNotice): ?><div class="notif-saved">Settings saved.</div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="prefs">
                <label class="notif-pref"><span>New uploads<small>From channels you subscribe to</small></span>
                    <input type="checkbox" name="uploads" value="1"<?= $prefs['uploads'] ? ' checked' : '' ?>></label>
                <label class="notif-pref"><span>Comments<small>When someone comments on your videos or replies to your comment</small></span>
                    <input type="checkbox" name="comments" value="1"<?= $prefs['comments'] ? ' checked' : '' ?>></label>
                <label class="notif-pref"><span>Likes<small>When someone likes your videos, shorts or comments, or the creator hearts your comment</small></span>
                    <input type="checkbox" name="likes" value="1"<?= $prefs['likes'] ? ' checked' : '' ?>></label>
                <label class="notif-pref"><span>New subscribers<small>When someone subscribes to your channel</small></span>
                    <input type="checkbox" name="subscribers" value="1"<?= $prefs['subscribers'] ? ' checked' : '' ?>></label>
                <div style="margin-top:14px"><button type="submit" class="notif-btn">Save settings</button></div>
            </form>
        </section>
    </div>
</div>
<script>
(function () {
    var tabs = document.getElementById('notifTabs'), list = document.getElementById('notifList');
    if (!tabs || !list) return;
    var groups = { uploads: ['video', 'short'], activity: ['like', 'comment', 'comment_like', 'reply', 'heart'], subscribers: ['subscriber'] };
    tabs.addEventListener('click', function (e) {
        var btn = e.target.closest('.notif-tab'); if (!btn) return;
        tabs.querySelectorAll('.notif-tab').forEach(function (b) { b.classList.toggle('active', b === btn); });
        var f = btn.getAttribute('data-filter');
        list.querySelectorAll('.vs-n-item').forEach(function (item) {
            var show = f === 'all' || (groups[f] || []).indexOf(item.getAttribute('data-type')) !== -1;
            item.style.display = show ? '' : 'none';
        });
    });
})();
</script>
<?php
vs_page_end();
if (isset($conn)) { $conn->close(); }