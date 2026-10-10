<?php
require_once __DIR__ . '/layout.php';
   require_once __DIR__ . '/icons.php';   
require_once __DIR__ . '/avatar.php';   
include __DIR__ . '/connection.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    try {
        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = new mysqli('localhost', 'root', '', 'vibestream');
        if ($conn->connect_errno) {
            die('Database connection failed: ' . htmlspecialchars($conn->connect_error));
        }
        $conn->set_charset('utf8mb4');
    } catch (Throwable $e) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    }
}

$viewer = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
$profileUser = trim((string)($_GET['u'] ?? $viewer));
$isMe = $viewer !== '' && strcasecmp($viewer, $profileUser) === 0;


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
    if ($from === '') {
        return;
    }
    $ext = strtolower(pathinfo($from, PATHINFO_EXTENSION));
    avatar_delete($new); // clear any leftover file from a previous owner of that name
    $to = AVATAR_DIR . '/' . avatar_key($new) . '.' . $ext;
    if (!@rename($from, $to)) {
        error_log('Could not move the avatar from ' . $from . ' to ' . $to);
    }
}

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

$videos = [];
$shorts = [];
$totalViews = 0;
$totalLikes = 0;

if ($profileUser !== '') {
    $rows = [];
    $sql = 'SELECT * FROM videos WHERE username = ? ORDER BY created_at DESC LIMIT 200';
    try {
        $stmt = $conn->prepare($sql);
    } catch (Throwable $e) {
        $stmt = $conn->prepare('SELECT * FROM videos WHERE username = ? LIMIT 200');
    }
    if ($stmt) {
        $stmt->bind_param('s', $profileUser);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        $stmt->close();
    }

    foreach ($rows as $r) {
        $totalViews += (int)($r['views'] ?? 0);
        $totalLikes += (int)($r['likes'] ?? 0);
        if (($r['type'] ?? '') === 'short') $shorts[] = $r; else $videos[] = $r;
    }
}

$letter = $profileUser !== '' ? mb_strtoupper(mb_substr($profileUser, 0, 1)) : 'G';
$hue = avatar_hue($profileUser);
$avatarUrl = avatar_url($profileUser);
$defaultTab = (count($videos) === 0 && count($shorts) > 0) ? 'shorts' : 'videos';


function card_info(array $r) {
    $src = find_source($r, __DIR__);
    $yt = $src ? extract_youtube_id($src) : null;
    $thumb = '';
    foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'poster'] as $k) {
        if (!empty($r[$k])) { $thumb = (string)$r[$k]; break; }
    }
    if ($thumb === '' && $yt) $thumb = 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg';
    $isShort = (($r['type'] ?? '') === 'short');
    $href = ($isShort ? 'shorts.php?id=' : 'watch.php?id=') . urlencode((string)$r['id']);
    return ['thumb' => $thumb, 'file' => ($thumb === '' && $src && !$yt) ? $src : '', 'href' => $href];
}

$extraCss = <<<'CSS'
.profile-head {
    display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
    background: var(--surface); border: 1px solid var(--border); border-radius: 26px; padding: 28px;
}
.profile-avatar {
    width: 104px; height: 104px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 44px; font-weight: 700; color: #ffffff;
}
img.profile-avatar { object-fit: cover; }
.profile-info { min-width: 0; flex: 1; }
.profile-info h1 { margin: 0; font-size: 28px; overflow-wrap: anywhere; }
.profile-info .role { margin: 4px 0 16px; color: var(--muted); font-size: 14px; }
.profile-stats { display: flex; gap: 28px; flex-wrap: wrap; }
.profile-stats div { min-width: 64px; }
.profile-stats strong { display: block; font-size: 20px; font-weight: 700; }
.profile-stats span { font-size: 13px; color: var(--muted); }
.profile-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.profile-actions form { margin: 0; }
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    border: none; cursor: pointer; border-radius: 999px;
    padding: 10px 18px; font-size: 14px; font-weight: 700; background: var(--surface-2); color: var(--text);
}
.btn:hover { background: #3a3a3a; }
.btn.primary { background: var(--accent); color: #ffffff; }
.btn.primary:hover { background: #c43232; }
.btn svg { flex-shrink: 0; }

.flash { margin: 0 0 18px; padding: 12px 16px; border-radius: 14px; font-size: 14px; font-weight: 600; }
.flash.ok  { background: rgba(60, 170, 90, 0.15); border: 1px solid rgba(60, 170, 90, 0.45); color: #7be09a; }
.flash.err { background: rgba(220, 60, 60, 0.15); border: 1px solid rgba(220, 60, 60, 0.45); color: #ff8d8d; }

.tabs { display: flex; gap: 8px; margin: 28px 0 18px; border-bottom: 1px solid var(--border); }
.tab {
    background: none; border: none; color: var(--muted); cursor: pointer; font-weight: 600; font-size: 15px;
    padding: 12px 16px; border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: var(--text); }
.tab.active { color: var(--text); border-bottom-color: var(--accent); }
.tab:focus-visible { outline: 2px solid var(--blue); outline-offset: 2px; }
.tab .count { color: var(--muted); font-weight: 400; margin-left: 6px; }

.grid { display: grid; gap: 20px; }
.grid.videos { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); }
.grid.shorts { grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); }
.vwrap { position: relative; min-width: 0; }
.vcard { display: block; min-width: 0; }
.del-form { position: absolute; top: 8px; right: 8px; z-index: 2; margin: 0; }
.del-btn {
    border: none; cursor: pointer; border-radius: 999px; padding: 7px 12px;
    font-size: 12px; font-weight: 700; color: #ffffff; background: rgba(0, 0, 0, 0.72);
    transition: background 0.15s ease;
}
.del-btn:hover { background: #c43232; }
.thumb {
    position: relative; overflow: hidden; border-radius: 16px; background: #1c1c20;
    border: 1px solid rgba(255, 255, 255, 0.06);
}
.grid.videos .thumb { aspect-ratio: 16 / 9; }
.grid.shorts .thumb { aspect-ratio: 9 / 16; }
.thumb img, .thumb video { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.25s ease; }
.vcard:hover .thumb img, .vcard:hover .thumb video { transform: scale(1.04); }
.thumb .empty { height: 100%; display: flex; align-items: center; justify-content: center; color: #6d6d75; font-size: 28px; }
.vtitle {
    margin: 10px 2px 2px; font-size: 14px; font-weight: 600; line-height: 1.35;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
  .sidebar-icon {
            width: 22px;
            height: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-icon svg {
            width: 20px;
            height: 20px;
        }
.vmeta { margin: 0 2px; font-size: 12px; color: var(--muted); }
.empty-state {
    text-align: center; padding: 48px 20px; color: var(--muted); font-size: 15px; line-height: 1.6;
    border: 1px dashed var(--border); border-radius: 20px;
}
.empty-state .btn { margin-top: 14px; }
[hidden] { display: none !important; }
.name-form {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    margin: 14px 0 0; padding: 16px 18px;
    background: var(--surface); border: 1px solid var(--border); border-radius: 18px;
}
.name-form label { font-size: 14px; font-weight: 600; }
.name-form input[type="text"] {
    flex: 1; min-width: 200px; padding: 10px 14px; border-radius: 999px;
    border: 1px solid var(--border); background: var(--surface-2); color: var(--text); font-size: 14px;
}
.name-form small { flex-basis: 100%; color: var(--muted); font-size: 12px; }
@media (max-width: 640px) {
    .profile-head { padding: 20px; gap: 16px; }
    .profile-avatar { width: 80px; height: 80px; font-size: 34px; }
    .profile-info h1 { font-size: 22px; }
    .grid.shorts { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
CSS;

vs_page_start($profileUser !== '' ? '@' . $profileUser : 'Profile', 'profile', $extraCss);
?>
<div class="page">
<?php if ($flash): ?>
    <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= htmlspecialchars($flash['text']) ?></div>
<?php endif; ?>

<?php if ($profileUser === ''): ?>
    <div class="empty-state">
        Sign in to see your profile and everything you have uploaded.
        <div><a class="btn primary" href="login.php"><?= vs_icon('contact', 16) ?> Sign in</a></div>
    </div>
<?php else: ?>
    <section class="profile-head">
        <?php if ($avatarUrl !== ''): ?>
            <img class="profile-avatar" src="<?= htmlspecialchars($avatarUrl) ?>" alt="">
        <?php else: ?>
            <div class="profile-avatar" style="background: hsl(<?= (int)$hue ?> 55% 42%);"><?= htmlspecialchars($letter) ?></div>
        <?php endif; ?>
        <div class="profile-info">
            <h1>@<?= htmlspecialchars($profileUser) ?></h1>
            <p class="role"><?= $isMe ? 'Your channel' : 'Channel' ?></p>
            <div class="profile-stats">
                <div><strong><?= short_num(count($videos)) ?></strong><span>Videos</span></div>
                <div><strong><?= short_num(count($shorts)) ?></strong><span>Shorts</span></div>
                <div><strong><?= short_num($totalViews) ?></strong><span>Views</span></div>
                <div><strong><?= short_num($totalLikes) ?></strong><span>Likes</span></div>
            </div>
        </div>
        <?php if ($isMe): ?>
            <div class="profile-actions">
                <form method="post" action="profile.php" enctype="multipart/form-data" id="avatarForm">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                    <input type="hidden" name="action" value="avatar">
                    <input type="file" name="avatar" id="avatarInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
                    <label for="avatarInput" class="btn"><?= vs_icon('contact', 16) ?> Change avatar</label>
                </form>
                <?php if ($avatarUrl !== ''): ?>
                    <form method="post" action="profile.php" onsubmit="return confirm('Remove your avatar?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
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
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="action" value="rename">
            <label for="newName">New name</label>
            <input type="text" id="newName" name="new_name" value="<?= htmlspecialchars($profileUser) ?>" minlength="3" maxlength="30" required autocomplete="off">
            <button type="submit" class="btn primary">Save</button>
            <button type="button" class="btn" id="nameCancel">Cancel</button>
            <small>3-30 characters: letters, numbers, dot, dash or underscore. Your videos, shorts and channel will move to the new name.</small>
        </form>
    <?php endif; ?>

    <div class="tabs" role="tablist">
        <button type="button" class="tab<?= $defaultTab === 'videos' ? ' active' : '' ?>" role="tab" data-tab="videos">Videos<span class="count"><?= count($videos) ?></span></button>
        <button type="button" class="tab<?= $defaultTab === 'shorts' ? ' active' : '' ?>" role="tab" data-tab="shorts">Shorts<span class="count"><?= count($shorts) ?></span></button>
    </div>

    <?php foreach (['videos' => $videos, 'shorts' => $shorts] as $key => $list): ?>
        <div class="panel" data-panel="<?= $key ?>"<?= $defaultTab === $key ? '' : ' hidden' ?>>
            <?php if (!$list): ?>
                <div class="empty-state">
                    <?= $key === 'videos' ? 'No videos yet.' : 'No shorts yet.' ?>
                    <?php if ($isMe): ?>
                        <div><a class="btn primary" href="upload.php"><?= vs_icon('plus-square', 16) ?> Upload your first <?= $key === 'videos' ? 'video' : 'short' ?></a></div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="grid <?= $key ?>">
                    <?php foreach ($list as $r): $ci = card_info($r); ?>
                        <div class="vwrap">
                            <a class="vcard" href="<?= htmlspecialchars($ci['href']) ?>">
                                <div class="thumb">
                                    <?php if ($ci['thumb'] !== ''): ?>
                                        <img src="<?= htmlspecialchars($ci['thumb']) ?>" alt="" loading="lazy">
                                    <?php elseif ($ci['file'] !== ''): ?>
                                        <video src="<?= htmlspecialchars($ci['file']) ?>#t=0.5" preload="metadata" muted playsinline></video>
                                    <?php else: ?>
                                        <div class="empty">▶</div>
                                    <?php endif; ?>
                                </div>
                                <p class="vtitle"><?= htmlspecialchars((string)($r['title'] ?? 'Untitled')) ?></p>
                                <p class="vmeta"><?= short_num($r['views'] ?? 0) ?> views</p>
                            </a>
                            <?php if ($isMe): ?>
                                <form class="del-form" method="post" action="delete.php"
                                      onsubmit="return confirm('Delete this <?= $key === 'shorts' ? 'short' : 'video' ?> permanently?');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="id" value="<?= htmlspecialchars((string)$r['id']) ?>">
                                    <button type="submit" class="del-btn">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
<?php
$extraJs = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var tabs = document.querySelectorAll('.tab');
    var panels = document.querySelectorAll('.panel');
    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            var name = tab.getAttribute('data-tab');
            tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
            panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
        });
    });

    var nameToggle = document.getElementById('nameToggle');
    var nameForm = document.getElementById('nameForm');
    if (nameToggle && nameForm) {
        nameToggle.addEventListener('click', function () {
            nameForm.hidden = !nameForm.hidden;
            if (!nameForm.hidden) { document.getElementById('newName').focus(); }
        });
        document.getElementById('nameCancel').addEventListener('click', function () { nameForm.hidden = true; });
    }

    var input = document.getElementById('avatarInput');
    if (input) {
        input.addEventListener('change', function () {
            if (!input.files.length) return;
            if (input.files[0].size > 2 * 1024 * 1024) {
                alert('That image is too large (max 2 MB).');
                input.value = '';
                return;
            }
            document.getElementById('avatarForm').submit();
        });
    }
});
JS;
vs_page_end($extraJs);
if (isset($conn)) { $conn->close(); }