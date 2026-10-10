<?php
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/avatar.php';
include __DIR__ . '/connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    $conn = new mysqli('localhost', 'root', '', 'vibestream');
}
$conn->set_charset('utf8mb4');

$viewer = trim((string)($_SESSION['username'] ?? ''));
$profileUser = '';
foreach (['u', 'user', 'username', 'channel'] as $param) {
    if (isset($_GET[$param]) && trim((string)$_GET[$param]) !== '') {
        $profileUser = trim((string)$_GET[$param]);
        break;
    }
}
if ($profileUser === '') {
    $profileUser = $viewer;
}
if ($profileUser === '') {
    header('Location: login.php');
    exit;
}
$isMe = $viewer !== '' && strcasecmp($profileUser, $viewer) === 0;
if ($isMe) {
    $profileUser = $viewer;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

/**
 * Renames a user everywhere the old name is stored.
 * Returns '' on success, or an error message to show the user.
 */
function vs_rename_user(mysqli $conn, string $old, string $new): string
{
    $new = trim($new);
    if ($new === $old) {
        return 'That is already your name.';
    }
    if (!preg_match('/^[\p{L}\p{N}_.-]{3,30}$/u', $new)) {
        return 'Use 3-30 characters: letters, numbers, dot, dash or underscore (no spaces).';
    }
    $isAdmin = strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') === 0;
    if (!$isAdmin && in_array(mb_strtolower($new), ['admin', 'administrator', 'root', 'system', 'support', 'guest'], true)) {
        return 'That name is reserved. Please choose another one.';
    }

    // Every text column that stores a user name (found automatically).
    $nameColumns = ['username', 'user_name', 'uploader', 'channel', 'channel_name', 'subscriber', 'subscriber_name',
                    'author', 'owner', 'commenter', 'posted_by', 'created_by'];
    $in = implode(',', array_map(static fn($c) => "'" . $c . "'", $nameColumns));
    $sql = "SELECT c.TABLE_NAME, c.COLUMN_NAME
            FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME AND t.TABLE_TYPE = 'BASE TABLE'
            WHERE c.TABLE_SCHEMA = DATABASE()
              AND c.DATA_TYPE IN ('varchar','char','tinytext','text','mediumtext','longtext')
              AND c.COLUMN_NAME IN ($in)";
    $targets = [];
    $res = $conn->query($sql);
    if (!$res) {
        error_log('Rename: could not inspect tables: ' . $conn->error);
        return 'Could not change your name right now. Please try again later.';
    }
    while ($row = $res->fetch_row()) {
        $targets[] = [$row[0], $row[1]];
    }
    $res->close();
    if (!in_array(['users', 'username'], $targets, true)) {
        return 'Could not change your name right now. Please try again later.';
    }

    $q = static function (string $id): string { return '`' . str_replace('`', '``', $id) . '`'; };
    $run = static function (string $sql, string $a, string $b) use ($conn): int {
        $st = $conn->prepare($sql);
        if (!$st) {
            throw new RuntimeException($conn->error);
        }
        $st->bind_param('ss', $a, $b);
        if (!$st->execute()) {
            $err = $st->error;
            $st->close();
            throw new RuntimeException($err);
        }
        $n = $st->affected_rows;
        $st->close();
        return $n;
    };

    try {
        // Refuse a name that is already used anywhere (this also protects other people's uploads).
        foreach ($targets as [$table, $column]) {
            $st = $conn->prepare('SELECT 1 FROM ' . $q($table) . ' WHERE ' . $q($column) . ' = ? AND BINARY ' . $q($column) . ' <> BINARY ? LIMIT 1');
            if (!$st) {
                throw new RuntimeException($conn->error);
            }
            $st->bind_param('ss', $new, $old);
            $st->execute();
            $st->store_result();
            $taken = $st->num_rows > 0;
            $st->close();
            if ($taken) {
                return 'That name is already taken. Please choose another one.';
            }
        }

        $conn->begin_transaction();
        if ($run('UPDATE `users` SET `username` = ? WHERE `username` = ?', $new, $old) < 1) {
            $conn->rollback();
            return 'Could not find your account to update.';
        }
        foreach ($targets as [$table, $column]) {
            if ($table === 'users' && $column === 'username') {
                continue;
            }
            $run('UPDATE ' . $q($table) . ' SET ' . $q($column) . ' = ? WHERE ' . $q($column) . ' = ?', $new, $old);
        }
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        error_log('Rename failed: ' . $e->getMessage());
        return 'Could not change your name right now. Please try again later.';
    }
    return '';
}

/** Keeps the profile picture when a user is renamed (avatar files are stored under a key made from the name). */
function vs_move_avatar(string $old, string $new): void
{
    if (avatar_key($old) === avatar_key($new)) {
        return;
    }
    $from = avatar_path($old);
    if ($from === '' || !is_file($from)) {
        return;
    }
    $to = dirname($from) . '/' . avatar_key($new) . '.' . pathinfo($from, PATHINFO_EXTENSION);
    if (!@rename($from, $to)) {
        error_log('Could not move the avatar from ' . $from . ' to ' . $to);
    }
}

if (!function_exists('thumb_for')) {
    function thumb_for(array $row, $src, $realYt) {
        if ($realYt) return "https://i.ytimg.com/vi/{$realYt}/hqdefault.jpg";
        foreach (['thumbnail', 'thumb', 'image', 'img', 'poster'] as $c) {
            if (!empty($row[$c])) return $row[$c];
        }
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#1e1e24"/><text x="50%" y="50%" fill="#6f6f7a" font-family="Arial" font-size="16" text-anchor="middle" dy=".3em">No preview</text></svg>');
    }
}
if (!function_exists('vs_short_views')) {
    function vs_short_views($n) {
        $n = (int)$n;
        if ($n >= 1000000000) { $v = $n / 1000000000; $u = 'B'; }
        elseif ($n >= 1000000) { $v = $n / 1000000; $u = 'M'; }
        elseif ($n >= 1000) { $v = $n / 1000; $u = 'K'; }
        else { return (string)$n; }
        $txt = $v >= 10 ? (string)round($v) : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
        return $txt . $u;
    }
}
if (!function_exists('vs_yt_time_ago')) {
    function vs_yt_time_ago($datetime) {
        if (empty($datetime)) return '';
        $t = strtotime($datetime);
        if (!$t) return '';
        $diff = max(0, time() - $t);
        foreach ([['year', 31536000], ['month', 2592000], ['week', 604800], ['day', 86400], ['hour', 3600], ['minute', 60]] as $u) {
            if ($diff >= $u[1]) {
                $n = (int)floor($diff / $u[1]);
                return $n . ' ' . $u[0] . ($n === 1 ? '' : 's') . ' ago';
            }
        }
        return 'Just now';
    }
}

/* ---------- Actions (only on your own channel) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['type' => 'err', 'text' => 'That request was not allowed.'];

    if ($isMe && hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'avatar') {
            $err = avatar_save($viewer, $_FILES['avatar'] ?? null);
            $flash = $err === ''
                ? ['type' => 'ok', 'text' => 'Avatar updated.']
                : ['type' => 'err', 'text' => $err];

        } elseif ($action === 'avatar_remove') {
            avatar_delete($viewer);
            $flash = ['type' => 'ok', 'text' => 'Avatar removed.'];

        } elseif ($action === 'rename') {
            $newName = trim((string)($_POST['new_name'] ?? ''));
            $err = vs_rename_user($conn, $viewer, $newName);
            if ($err === '') {
                vs_move_avatar($viewer, $newName);
                foreach (['username', 'UserSession', 'AdminSession'] as $sessionKey) {
                    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] === $viewer) {
                        $_SESSION[$sessionKey] = $newName;
                    }
                }
                $flash = ['type' => 'ok', 'text' => 'Your name was changed to ' . $newName . '.'];
            } else {
                $flash = ['type' => 'err', 'text' => $err];
            }
        }
    }

    $_SESSION['flash'] = $flash;
    header('Location: profile.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Load the channel ---------- */
$accountFound = true;
if (!$isMe) {
    $accountFound = false;
    if ($st = $conn->prepare('SELECT `username` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1')) {
        $st->bind_param('s', $profileUser);
        $st->execute();
        $st->bind_result($foundName);
        if ($st->fetch()) {
            $accountFound = true;
            $profileUser = (string)$foundName;
        }
        $st->close();
    }
}

$videos = [];
$shorts = [];
if ($accountFound) {
    $vCols = [];
    if ($r = $conn->query('SHOW COLUMNS FROM `videos`')) {
        while ($c = $r->fetch_assoc()) {
            $vCols[strtolower($c['Field'])] = true;
        }
        $r->close();
    }
    $uploaderCol = '';
    foreach (['username', 'channel_name', 'uploader'] as $cand) {
        if (isset($vCols[$cand])) { $uploaderCol = $cand; break; }
    }
    if ($uploaderCol !== '') {
        $order = isset($vCols['created_at']) ? 'created_at DESC' : 'id DESC';
        $st = $conn->prepare("SELECT * FROM `videos` WHERE LOWER(`$uploaderCol`) = LOWER(?) ORDER BY $order");
        if ($st) {
            $st->bind_param('s', $profileUser);
            $st->execute();
            foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                if (isset($row['type']) && strtolower((string)$row['type']) === 'short') {
                    $shorts[] = $row;
                } else {
                    $videos[] = $row;
                }
            }
            $st->close();
        }
    }
}

$totalViews = 0;
$totalLikes = 0;
foreach (array_merge($videos, $shorts) as $row) {
    $totalViews += (int)($row['views'] ?? 0);
    $totalLikes += (int)($row['likes'] ?? 0);
}
$defaultTab = (count($videos) === 0 && count($shorts) > 0) ? 'shorts' : 'videos';
$avatarUrl = $accountFound ? avatar_url($profileUser) : '';
$h = static function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

$extraCss = <<<'CSS'
.profile-page { max-width: 1320px; margin: 0 auto; padding: 24px 24px 56px; }
.flash { margin: 0 0 18px; padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 600; }
.flash.ok  { background: rgba(60, 170, 90, 0.15); border: 1px solid rgba(60, 170, 90, 0.45); color: #2e9e5b; }
.flash.err { background: rgba(220, 60, 60, 0.15); border: 1px solid rgba(220, 60, 60, 0.45); color: #d94a4a; }
.channel-card {
    display: flex; flex-wrap: wrap; align-items: center; gap: 28px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 32px; padding: 32px 36px;
}
.channel-avatar-lg {
    width: 130px; height: 130px; border-radius: 50%; object-fit: cover; flex: 0 0 auto;
    background: var(--surface-2); display: flex; align-items: center; justify-content: center;
    font-size: 52px; font-weight: 700; color: var(--text);
}
.channel-main { min-width: 220px; flex: 1 1 260px; max-width: 360px; }
.channel-name { margin: 0 0 4px; font-size: 32px; line-height: 1.2; font-weight: 800; word-break: break-word; }
.channel-sub { color: var(--muted); font-size: 15px; margin: 0 0 18px; }
.channel-stats { display: grid; grid-template-columns: repeat(2, minmax(90px, 1fr)); gap: 18px 24px; }
.channel-stats b { display: block; font-size: 22px; }
.channel-stats span { color: var(--muted); font-size: 14px; }
.channel-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; flex: 1 1 320px; }
.btn {
    display: inline-flex; align-items: center; gap: 8px; border: 0; cursor: pointer;
    background: var(--surface-2); color: var(--text); padding: 12px 20px; border-radius: 999px;
    font-size: 15px; font-weight: 700; white-space: nowrap;
}
.btn:hover { filter: brightness(0.96); }
.btn.primary { background: var(--accent); color: #ffffff; }
.name-form {
    display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin: 18px 0 0;
    background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 18px 22px;
}
.name-form[hidden] { display: none; }
.name-form input[type=text] {
    flex: 1 1 220px; padding: 11px 14px; border-radius: 12px; border: 1px solid var(--border);
    background: var(--bg); color: var(--text);
}
.name-form small { flex: 1 1 100%; color: var(--muted); }
.tabs { display: flex; gap: 8px; margin: 32px 0 0; border-bottom: 1px solid var(--border); }
.tab {
    background: none; border: 0; border-bottom: 2px solid transparent; padding: 14px 20px; cursor: pointer;
    font-size: 17px; font-weight: 700; color: var(--muted);
}
.tab.active { color: var(--text); border-bottom-color: var(--accent); }
.tab .count { margin-left: 8px; font-weight: 400; color: var(--muted); }
.tab-panel { display: none; padding-top: 24px; }
.tab-panel.active { display: block; }
.video-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 24px 18px; }
.short-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 24px 14px; }
.p-thumb { position: relative; aspect-ratio: 16 / 9; border-radius: 12px; overflow: hidden; background: var(--surface-2); }
.p-thumb img, .p-short-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.p-short-thumb { aspect-ratio: 9 / 16; border-radius: 12px; overflow: hidden; background: var(--surface-2); }
.p-duration { position: absolute; right: 8px; bottom: 8px; background: rgba(0,0,0,.8); color: #fff; font-size: 12px; padding: 2px 6px; border-radius: 6px; }
.p-title { margin: 10px 0 4px; font-size: 15px; font-weight: 700; line-height: 1.35; }
.p-meta { margin: 0; color: var(--muted); font-size: 13px; }
.empty-state {
    text-align: center; padding: 56px 16px; color: var(--muted);
    border: 1px dashed var(--border); border-radius: 20px;
}
.empty-state .btn { margin-top: 16px; }
@media (max-width: 760px) {
    .profile-page { padding: 16px 12px 48px; }
    .channel-card { padding: 22px 18px; gap: 18px; border-radius: 24px; }
    .channel-name { font-size: 26px; }
}
CSS;

vs_page_start($accountFound ? '@' . $profileUser : 'Channel', 'profile', $extraCss);
?>
<div class="profile-page">
<?php if ($flash): ?>
    <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash['text']) ?></div>
<?php endif; ?>

<?php if (!$accountFound): ?>
    <div class="empty-state">
        <p>This channel was not found.</p>
        <a class="btn primary" href="index.php">Go home</a>
    </div>
<?php else: ?>
    <section class="channel-card">
        <?php if ($avatarUrl !== ''): ?>
            <img class="channel-avatar-lg" src="<?= $h($avatarUrl) ?>" alt="<?= $h($profileUser) ?> avatar">
        <?php else: ?>
            <div class="channel-avatar-lg"><?= $h(mb_strtoupper(mb_substr($profileUser, 0, 1))) ?></div>
        <?php endif; ?>

        <div class="channel-main">
            <h1 class="channel-name">@<?= $h($profileUser) ?></h1>
            <p class="channel-sub"><?= $isMe ? 'Your channel' : 'Channel' ?></p>
            <div class="channel-stats">
                <div><b><?= count($videos) ?></b><span>Videos</span></div>
                <div><b><?= count($shorts) ?></b><span>Shorts</span></div>
                <div><b><?= $h(vs_short_views($totalViews)) ?></b><span>Views</span></div>
                <div><b><?= $h(vs_short_views($totalLikes)) ?></b><span>Likes</span></div>
            </div>
        </div>

        <?php if ($isMe): ?>
            <div class="channel-actions">
                <form method="post" action="profile.php" enctype="multipart/form-data" id="avatarForm" style="display:none;">
                    <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                    <input type="hidden" name="action" value="avatar">
                    <input type="file" name="avatar" id="avatarFile" accept=".jpg,.jpeg,.png,.webp,.gif">
                </form>
                <label class="btn" for="avatarFile"><?= vs_icon('contact', 16) ?> Change avatar</label>
                <?php if ($avatarUrl !== ''): ?>
                    <form method="post" action="profile.php" style="margin:0;">
                        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                        <input type="hidden" name="action" value="avatar_remove">
                        <button type="submit" class="btn">Remove avatar</button>
                    </form>
                <?php endif; ?>
                <button type="button" class="btn" id="nameToggle"><?= vs_icon('contact', 16) ?> Change name</button>
                <a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload</a>
                <a class="btn" href="logout.php">Logout</a>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($isMe): ?>
        <form class="name-form" id="nameForm" method="post" action="profile.php" hidden>
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <label for="newName">New name</label>
            <input type="text" id="newName" name="new_name" value="<?= $h($profileUser) ?>" minlength="3" maxlength="30" required autocomplete="off">
            <button type="submit" class="btn primary">Save</button>
            <button type="button" class="btn" id="nameCancel">Cancel</button>
            <small>3-30 characters: letters, numbers, dot, dash or underscore. Your videos, shorts and channel will move to the new name.</small>
        </form>
    <?php endif; ?>

    <div class="tabs" role="tablist">
        <button type="button" class="tab<?= $defaultTab === 'videos' ? ' active' : '' ?>" role="tab" data-tab="videos">Videos<span class="count"><?= count($videos) ?></span></button>
        <button type="button" class="tab<?= $defaultTab === 'shorts' ? ' active' : '' ?>" role="tab" data-tab="shorts">Shorts<span class="count"><?= count($shorts) ?></span></button>
    </div>

    <div class="tab-panel<?= $defaultTab === 'videos' ? ' active' : '' ?>" data-panel="videos">
        <?php if (!$videos): ?>
            <div class="empty-state">
                <p>No videos yet.</p>
                <?php if ($isMe): ?><a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload your first video</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="video-grid">
                <?php foreach ($videos as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $thumb = thumb_for($row, $src, $yt);
                    $title = $row['title'] ?? 'Untitled';
                    $duration = $row['duration'] ?? '';
                ?>
                    <a href="watch.php?id=<?= urlencode((string)$row['id']) ?>" class="p-card">
                        <div class="p-thumb">
                            <img src="<?= $h($thumb) ?>" alt="<?= $h($title) ?>" loading="lazy">
                            <?php if (!empty($duration)): ?><span class="p-duration"><?= $h($duration) ?></span><?php endif; ?>
                        </div>
                        <h3 class="p-title"><?= $h($title) ?></h3>
                        <p class="p-meta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views &bull; <?= $h(vs_yt_time_ago($row['created_at'] ?? null)) ?></p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-panel<?= $defaultTab === 'shorts' ? ' active' : '' ?>" data-panel="shorts">
        <?php if (!$shorts): ?>
            <div class="empty-state">
                <p>No shorts yet.</p>
                <?php if ($isMe): ?><a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload a short</a><?php endif; ?>
            </div>
        <?php else: ?>
            <div class="short-grid">
                <?php foreach ($shorts as $row):
                    $src = find_source($row, __DIR__);
                    $yt = $src !== '' ? extract_youtube_id($src, true) : null;
                    $thumb = thumb_for($row, $src, $yt);
                    $title = $row['title'] ?? 'Untitled';
                ?>
                    <a href="shorts.php?id=<?= urlencode((string)$row['id']) ?>" class="p-card">
                        <div class="p-short-thumb"><img src="<?= $h($thumb) ?>" alt="<?= $h($title) ?>" loading="lazy"></div>
                        <h3 class="p-title"><?= $h($title) ?></h3>
                        <p class="p-meta"><?= $h(vs_short_views($row['views'] ?? 0)) ?> views</p>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
</div>
<?php
vs_page_end(<<<'JS'
(function () {
    var tabs = document.querySelectorAll('.tab');
    var panels = document.querySelectorAll('.tab-panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var key = tab.getAttribute('data-tab');
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            panels.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-panel') === key); });
        });
    });

    var toggle = document.getElementById('nameToggle');
    var form = document.getElementById('nameForm');
    var cancel = document.getElementById('nameCancel');
    if (toggle && form) {
        toggle.addEventListener('click', function () {
            form.hidden = !form.hidden;
            if (!form.hidden) { document.getElementById('newName').focus(); }
        });
    }
    if (cancel && form) {
        cancel.addEventListener('click', function () { form.hidden = true; });
    }

    var file = document.getElementById('avatarFile');
    var avatarForm = document.getElementById('avatarForm');
    if (file && avatarForm) {
        file.addEventListener('change', function () {
            if (file.files && file.files.length) { avatarForm.submit(); }
        });
    }
})();
JS
);