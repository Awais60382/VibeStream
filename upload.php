<?php
require_once __DIR__ . '/layout.php';  
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
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);  

$cols = [];
$res = $conn->query('SHOW COLUMNS FROM `videos`');
while ($c = $res->fetch_assoc()) {
    $cols[strtolower($c['Field'])] = $c;
}

$SOURCE_COLUMNS = ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'link', 'media_url', 'youtube_url', 'youtube_id', 'yt_id', 'video_link', 'embed_url', 'embed'];
$srcCol = null;
foreach ($SOURCE_COLUMNS as $name) {
    if (isset($cols[$name])) { $srcCol = $name; break; }
}
$idIsAuto = isset($cols['id']) && stripos((string)$cols['id']['Extra'], 'auto_increment') !== false;
$idIsText = isset($cols['id']) && !preg_match('/int|decimal|float|double|bit/i', (string)$cols['id']['Type']);
if ($srcCol === null && $idIsText && !$idIsAuto) {
    $srcCol = 'id';
}

function col_max_len(array $col) {
    if (preg_match('/^(?:var)?char\((\d+)\)/i', (string)$col['Type'], $m)) return (int)$m[1];
    return null;
}
function new_short_id() {
    return substr(strtr(base64_encode(random_bytes(9)), '+/', '-_'), 0, 11);
}
function upload_error_text($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'That file is bigger than your PHP limit (upload_max_filesize = ' . ini_get('upload_max_filesize') . '). Raise it in php.ini or paste a link instead.';
        case UPLOAD_ERR_PARTIAL:
            return 'The file only uploaded partly. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'PHP has no temp folder to store the upload.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'PHP could not write the file to disk.';
        default:
            return 'The file upload failed (error code ' . (int)$code . ').';
    }
}

if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

$username = isset($_SESSION['username']) ? trim((string)$_SESSION['username']) : '';
$errors = [];
$old = ['type' => 'short', 'title' => '', 'description' => '', 'mode' => 'link', 'video_url' => '', 'category' => ''];

$flash = $_SESSION['upload_flash'] ?? null;
unset($_SESSION['upload_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedFile = null;

    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'That upload is bigger than PHP allows (post_max_size = ' . ini_get('post_max_size') . '). Raise it in php.ini or paste a link instead.';
    } elseif (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } else {
        $old['type']        = (($_POST['type'] ?? '') === 'video') ? 'video' : 'short';
        $old['title']       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
        $old['description'] = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
        $old['mode']        = (($_POST['mode'] ?? '') === 'file') ? 'file' : 'link';
        $old['video_url']   = trim((string)($_POST['video_url'] ?? ''));
        $old['category']    = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 60);

        if ($old['title'] === '') $errors[] = 'Add a title.';
        if ($srcCol === null) {
            $errors[] = 'Your videos table has no column to store the video link. Run the SQL shown at the top of this page first.';
        }

        $sourceValue = null;
        $yt = null;
        $uploadTmp = null;
        $uploadExt = null;

        if (!$errors) {
            if ($old['mode'] === 'file') {
                $f = $_FILES['video_file'] ?? null;
                if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Choose a video file to upload.';
                } elseif ($f['error'] !== UPLOAD_ERR_OK) {
                    $errors[] = upload_error_text($f['error']);
                } else {
                    $uploadExt = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    if (!in_array($uploadExt, ['mp4', 'webm', 'ogg', 'ogv', 'm4v', 'mov'], true)) {
                        $errors[] = 'Only mp4, webm, ogg, m4v or mov files are supported.';
                    } else {
                        $uploadTmp = $f['tmp_name'];
                    }
                }
            } else {
                $url = $old['video_url'];
                if ($url === '') {
                    $errors[] = 'Paste a YouTube link or a direct link to a video file.';
                } else {
                    $yt = extract_youtube_id($url, true);
                    if ($yt) {
                        $idOnly = ($srcCol === 'id') || (bool)preg_match('/(^|_)id$/', $srcCol);
                        $sourceValue = $idOnly ? $yt : 'https://www.youtube.com/watch?v=' . $yt;
                    } elseif (preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL)) {
                        $sourceValue = $url;
                    } else {
                        $errors[] = 'That does not look like a YouTube link or an http(s) link to a video file.';
                    }
                }
            }
        }

        if (!$errors && $sourceValue !== null) {
            $max = col_max_len($cols[$srcCol]);
            if ($max !== null && mb_strlen($sourceValue) > $max) {
                $real = $cols[$srcCol]['Field'];
                $errors[] = "The `$real` column only holds $max characters. Widen it with: ALTER TABLE videos MODIFY `$real` VARCHAR(500);";
            }
        }
        if (!$errors && $srcCol === 'id' && $sourceValue !== null) {
            $chk = $conn->prepare('SELECT 1 FROM `videos` WHERE `id` = ? LIMIT 1');
            $chk->bind_param('s', $sourceValue);
            $chk->execute();
            if ($chk->get_result()->fetch_row()) $errors[] = 'This video is already on VibeStream.';
            $chk->close();
        }

        if (!$errors) {
            try {
                if ($uploadTmp !== null) {
                    $dir = __DIR__ . '/uploads/videos';
                    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
                        throw new RuntimeException('Could not create the uploads/videos folder.');
                    }
                    $fileName = bin2hex(random_bytes(8)) . '.' . $uploadExt;
                    if (!move_uploaded_file($uploadTmp, $dir . '/' . $fileName)) {
                        throw new RuntimeException('Could not save the uploaded file.');
                    }
                    $savedFile = $dir . '/' . $fileName;
                    $sourceValue = 'uploads/videos/' . $fileName;

                    $max = col_max_len($cols[$srcCol]);
                    if ($max !== null && mb_strlen($sourceValue) > $max) {
                        throw new RuntimeException("The `{$cols[$srcCol]['Field']}` column is too short for a file path. Run: ALTER TABLE videos MODIFY `{$cols[$srcCol]['Field']}` VARCHAR(500);");
                    }
                }

                $poster = $username !== '' ? $username : 'Guest';
                $data = [];
                $put = function ($key, $val) use (&$data, $cols) {
                    if (isset($cols[$key])) $data[$cols[$key]['Field']] = (string)$val;
                };

                $put('title', $old['title']);
                $put('description', $old['description']);
                $put('type', $old['type']);
                $put('username', $poster);
                $put('channel_name', $poster);
                if ($old['category'] !== '') $put('category', $old['category']);
                $put('views', 0);
                $put('likes', 0);
                $put('subscribers', 0);
                if (isset($cols['created_at'])) {
                    $put('created_at', preg_match('/int/i', (string)$cols['created_at']['Type']) ? time() : date('Y-m-d H:i:s'));
                }
                if (isset($cols['user_id']) && isset($_SESSION['user_id'])) {
                    $put('user_id', $_SESSION['user_id']);
                }
                if ($yt) {
                    foreach (['thumbnail', 'thumbnail_url', 'thumb', 'thumb_url', 'poster'] as $t) {
                        if (isset($cols[$t])) { $put($t, 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg'); break; }
                    }
                }

                $put($srcCol, $sourceValue);

                $newId = null;
                if ($srcCol === 'id') {
                    $newId = $sourceValue;
                } elseif ($idIsText && !$idIsAuto) {
                    $newId = new_short_id();
                    $put('id', $newId);
                }

                $names = array_keys($data);
                $sql = 'INSERT INTO `videos` (' .
                    implode(', ', array_map(function ($n) { return '`' . str_replace('`', '``', $n) . '`'; }, $names)) .
                    ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
                $stmt = $conn->prepare($sql);
                $values = array_values($data);
                $stmt->bind_param(str_repeat('s', count($values)), ...$values);
                $stmt->execute();
                if ($newId === null) $newId = (string)$conn->insert_id;
                $stmt->close();

                $_SESSION['upload_flash'] = ['type' => $old['type'], 'id' => $newId, 'title' => $old['title']];
                header('Location: upload.php');
                exit;
            } catch (Throwable $e) {
                if ($savedFile && is_file($savedFile)) @unlink($savedFile);
                $errors[] = 'Could not save: ' . $e->getMessage();
            }
        }
    }
}

$showCategory = isset($cols['category']);
$srcRealName = $srcCol !== null ? $cols[$srcCol]['Field'] : '';

$extraCss = <<<'CSS'
.upload-wrap { max-width: 720px; margin: 0 auto; }
.upload-wrap h1 { margin: 0 0 6px; font-size: 26px; }
.upload-wrap .sub { margin: 0 0 22px; color: var(--muted); font-size: 14px; line-height: 1.5; }
.card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 22px; padding: 24px;
}
.notice {
    border-radius: 14px; padding: 12px 14px; font-size: 14px; line-height: 1.5; margin-bottom: 16px;
    border: 1px solid var(--border); background: var(--surface-3);
}
.notice.error { border-color: rgba(176, 42, 42, 0.6); background: rgba(176, 42, 42, 0.14); color: #ffd0d0; }
.notice.ok { border-color: rgba(62, 166, 255, 0.5); background: rgba(62, 166, 255, 0.1); }
.notice.warn { border-color: rgba(230, 170, 60, 0.5); background: rgba(230, 170, 60, 0.1); }
.notice a { color: var(--blue); text-decoration: underline; }
.notice ul { margin: 0; padding-left: 18px; }
.notice code, .notice pre {
    background: #0d0d0f; border-radius: 8px; padding: 2px 6px; font-size: 13px; color: #f1f1f1;
}
.notice pre { display: block; padding: 10px 12px; margin: 8px 0 0; overflow-x: auto; }
.field { margin-bottom: 18px; }
.field label, .field .label { display: block; font-size: 14px; font-weight: 600; margin-bottom: 8px; }
.field .hint { font-size: 12px; color: var(--muted); margin-top: 6px; line-height: 1.4; }
.field input[type=text], .field input[type=url], .field textarea, .field input[type=file] {
    width: 100%; background: #0d0d0f; border: 1px solid #3d3d46; border-radius: 12px;
    padding: 12px 14px; color: #ffffff; outline: none;
}
.field textarea { min-height: 110px; resize: vertical; line-height: 1.5; }
.field input:focus, .field textarea:focus { border-color: #6b6b78; }
.field input[type=file] { padding: 10px; }
.seg { display: inline-flex; background: var(--surface-2); border-radius: 999px; padding: 4px; gap: 4px; }
.seg input { position: absolute; opacity: 0; pointer-events: none; }
.seg label {
    margin: 0; padding: 8px 18px; border-radius: 999px; cursor: pointer; font-size: 14px; font-weight: 600;
    color: var(--muted); transition: background 0.2s ease, color 0.2s ease;
}
.seg input:checked + label { background: var(--accent); color: #ffffff; }
.seg input:focus-visible + label { outline: 2px solid var(--blue); outline-offset: 2px; }
.submit-btn {
    background: var(--accent); color: #ffffff; border: none; border-radius: 999px;
    padding: 13px 26px; font-weight: 700; font-size: 15px; cursor: pointer;
}
.submit-btn:hover { background: #c43232; }
.submit-btn:disabled { opacity: 0.5; cursor: default; }
.posting-as { font-size: 13px; color: var(--muted); margin-left: 14px; }
.posting-as a { color: var(--blue); }
CSS;

vs_page_start('Upload', 'upload', $extraCss);
?>
<div class="page">
    <div class="upload-wrap">
        <h1>Upload</h1>
        <p class="sub">Add a video or a short. Paste a YouTube link, or upload a file from your computer.</p>

        <?php if ($flash): ?>
            <div class="notice ok">
                <strong>Uploaded:</strong> <?= htmlspecialchars($flash['title']) ?>.
                <?php if ($flash['type'] === 'short'): ?>
                    <a href="shorts.php?id=<?= urlencode($flash['id']) ?>">Watch the short</a>
                <?php else: ?>
                    <a href="watch.php?id=<?= urlencode($flash['id']) ?>">Watch the video</a>
                <?php endif; ?>
                &middot; <a href="profile.php">Open your profile</a>
            </div>
        <?php endif; ?>

        <?php if ($srcCol === null): ?>
            <div class="notice warn">
                Your <code>videos</code> table has no column that can store the video link, which is why the link was never saved.
                Paste this into phpMyAdmin (SQL tab) once, then reload this page:
                <pre>ALTER TABLE videos ADD COLUMN video_url VARCHAR(500) NULL;</pre>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="notice error">
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form class="card" method="post" enctype="multipart/form-data" id="uploadForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

            <div class="field">
                <span class="label">What are you uploading?</span>
                <div class="seg" role="radiogroup" aria-label="Upload type">
                    <input type="radio" name="type" id="typeShort" value="short"<?= $old['type'] === 'short' ? ' checked' : '' ?>>
                    <label for="typeShort">Short</label>
                    <input type="radio" name="type" id="typeVideo" value="video"<?= $old['type'] === 'video' ? ' checked' : '' ?>>
                    <label for="typeVideo">Video</label>
                </div>
            </div>

            <div class="field">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="150" required value="<?= htmlspecialchars($old['title']) ?>">
            </div>

            <div class="field">
                <span class="label">Video source</span>
                <div class="seg" role="radiogroup" aria-label="Video source">
                    <input type="radio" name="mode" id="modeLink" value="link"<?= $old['mode'] === 'link' ? ' checked' : '' ?>>
                    <label for="modeLink">Paste a link</label>
                    <input type="radio" name="mode" id="modeFile" value="file"<?= $old['mode'] === 'file' ? ' checked' : '' ?>>
                    <label for="modeFile">Upload a file</label>
                </div>
            </div>

            <div class="field" id="linkField">
                <label for="video_url">Video link</label>
                <input type="text" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?= htmlspecialchars($old['video_url']) ?>">
                <div class="hint">YouTube watch, shorts or youtu.be links all work. You can also paste a direct link that ends in .mp4 or .webm.</div>
            </div>

            <div class="field" id="fileField" hidden>
                <label for="video_file">Video file</label>
                <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.webm,.ogg,.ogv,.m4v,.mov">
                <div class="hint">Max size: <?= htmlspecialchars(ini_get('upload_max_filesize')) ?> (set by PHP). mp4, webm, ogg, m4v or mov.</div>
            </div>

            <?php if ($showCategory): ?>
                <div class="field">
                    <label for="category">Category <span style="color:var(--muted);font-weight:400">(optional)</span></label>
                    <input type="text" id="category" name="category" maxlength="60" value="<?= htmlspecialchars($old['category']) ?>">
                </div>
            <?php endif; ?>

            <div>
                <button type="submit" class="submit-btn" id="submitBtn"<?= $srcCol === null ? ' disabled' : '' ?>>Upload</button>
                <?php if ($username !== ''): ?>
                    <span class="posting-as">Posting as @<?= htmlspecialchars($username) ?></span>
                <?php else: ?>
                    <span class="posting-as"><a href="login.php">Sign in</a> so this upload shows on your profile.</span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>
<?php
$extraJs = <<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    var linkField = document.getElementById('linkField');
    var fileField = document.getElementById('fileField');
    var modes = document.querySelectorAll('input[name="mode"]');
    function syncMode() {
        var isFile = document.getElementById('modeFile').checked;
        linkField.hidden = isFile;
        fileField.hidden = !isFile;
    }
    modes.forEach(function (m) { m.addEventListener('change', syncMode); });
    syncMode();

    var form = document.getElementById('uploadForm');
    var btn = document.getElementById('submitBtn');
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.textContent = 'Uploading...';
    });
});
JS;
vs_page_end($extraJs);
if (isset($conn)) { $conn->close(); }