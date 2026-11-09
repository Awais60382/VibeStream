<?php
require_once __DIR__ . '/layout.php';  
include __DIR__ . '/connection.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/upload-failure-store.php';
require_once __DIR__ . '/premium-store.php';
require_once __DIR__ . '/live-store.php';

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

// Make sure videos can be filed under a category (adds the column once, if it is missing).
if (!isset($cols['category'])) {
    try {
        $conn->query('ALTER TABLE `videos` ADD COLUMN `category` VARCHAR(40) NULL');
        $cols = [];
        $res = $conn->query('SHOW COLUMNS FROM `videos`');
        while ($c = $res->fetch_assoc()) {
            $cols[strtolower($c['Field'])] = $c;
        }
    } catch (Throwable $categoryColumnError) {
        // keep going; the category picker is simply hidden
    }
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
$draftKey = 'vibestream-upload-draft:' . ($username !== '' ? $username : 'guest');
$errors = [];
$old = ['type' => 'video', 'title' => '', 'description' => '', 'mode' => 'link', 'video_url' => '', 'category' => '', 'live_status' => 'live', 'stream_url' => '', 'thumbnail_url' => '', 'scheduled_at' => ''];

$flash = $_SESSION['upload_flash'] ?? null;
unset($_SESSION['upload_flash']);

$uploadsOn = vs_uploads_enabled();
$maxBytes = vs_max_upload_bytes();
$categoryChoices = vs_default_categories();
$uploadLiveStreams = [];
try {
    vs_live_ensure($pdo);
    $uploadLiveStreams = $pdo->query(
        "SELECT id, username, title, thumbnail_url, status, scheduled_at, viewer_count
         FROM live_streams
         WHERE status IN ('live', 'scheduled')
         ORDER BY status = 'live' DESC, started_at DESC, scheduled_at ASC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $liveError) {
    error_log('upload.php live streams: ' . $liveError->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedFile = null;

    if (!$uploadsOn) {
        $errors[] = 'Uploads are turned off right now. Please try again later.';
    } elseif (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'That upload is bigger than PHP allows (post_max_size = ' . ini_get('post_max_size') . '). Raise it in php.ini or paste a link instead.';
    } elseif (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Reload the page and try again.';
    } else {
        $old['type']        = in_array(($_POST['type'] ?? ''), ['video', 'short', 'live'], true) ? (string)$_POST['type'] : 'short';
        $old['title']       = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
        $old['description'] = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 2000);
        $old['mode']        = (($_POST['mode'] ?? '') === 'file') ? 'file' : 'link';
        $old['video_url']   = trim((string)($_POST['video_url'] ?? ''));
        $old['category']    = mb_substr(trim((string)($_POST['category'] ?? '')), 0, 60);
        $old['live_status'] = (($_POST['live_status'] ?? '') === 'scheduled') ? 'scheduled' : 'live';
        $old['stream_url'] = trim((string)($_POST['stream_url'] ?? $old['video_url']));
        $old['thumbnail_url'] = trim((string)($_POST['thumbnail_url'] ?? ''));
        $old['scheduled_at'] = trim((string)($_POST['scheduled_at'] ?? ''));

        if ($old['title'] === '') $errors[] = 'Add a title.';
        if ($old['type'] === 'live') {
            if ($username === '') {
                $errors[] = 'Sign in to create a live stream.';
            }
            if ($old['stream_url'] !== '' && !filter_var($old['stream_url'], FILTER_VALIDATE_URL)) {
                $errors[] = 'The live stream URL is not valid.';
            }
            if ($old['thumbnail_url'] !== '' && !filter_var($old['thumbnail_url'], FILTER_VALIDATE_URL)) {
                $errors[] = 'The live thumbnail URL is not valid.';
            }
            if ($old['live_status'] === 'scheduled' && $old['scheduled_at'] === '') {
                $errors[] = 'Choose a date and time for the scheduled live stream.';
            }

            if (!$errors) {
                try {
                    vs_live_ensure($pdo);
                    $scheduleTimestamp = $old['live_status'] === 'scheduled' ? strtotime($old['scheduled_at']) : false;
                    if ($scheduleTimestamp === false && $old['live_status'] === 'scheduled') {
                        throw new RuntimeException('Choose a valid schedule date and time.');
                    }
                    $scheduledAt = $scheduleTimestamp === false ? null : date('Y-m-d H:i:s', $scheduleTimestamp);

                    $youtubeId = '';
                    foreach ([$old['stream_url'], $old['thumbnail_url']] as $liveUrl) {
                        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})#i', $liveUrl, $match)) {
                            $youtubeId = $match[1];
                            break;
                        }
                    }
                    if ($old['thumbnail_url'] === '' && $youtubeId !== '') {
                        $old['thumbnail_url'] = 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
                    }

                    $stmt = $pdo->prepare(
                        'INSERT INTO live_streams
                         (username, title, description, stream_url, thumbnail_url, status, scheduled_at, started_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $status = $old['live_status'] === 'live' ? 'live' : 'scheduled';
                    $startedAt = $status === 'live' ? date('Y-m-d H:i:s') : null;
                    $stmt->execute([
                        $username,
                        $old['title'],
                        $old['description'],
                        $old['stream_url'],
                        $old['thumbnail_url'],
                        $status,
                        $scheduledAt,
                        $startedAt,
                    ]);
                    $liveId = (int)$pdo->lastInsertId();
                    $_SESSION['upload_flash'] = [
                        'type' => 'live',
                        'id' => $liveId,
                        'title' => $old['title'],
                        'points_earned' => 0,
                        'points_error' => false,
                    ];
                    header('Location: upload.php');
                    exit;
                } catch (Throwable $liveSaveError) {
                    $errors[] = 'Could not save the live stream: ' . $liveSaveError->getMessage();
                }
            }
        }
        if ($old['type'] !== 'live') {
        $validCategories = array_map(function ($c) { return strtolower(trim((string)$c)); }, $categoryChoices);
        $old['category'] = strtolower($old['category']);
        if (isset($cols['category']) && $validCategories) {
            if ($old['category'] === '') {
                $errors[] = 'Choose a category.';
            } elseif (!in_array($old['category'], $validCategories, true)) {
                $errors[] = 'Pick one of the listed categories.';
            }
        }
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
                } elseif ((int)($f['size'] ?? 0) > $maxBytes) {
                    $errors[] = 'That file is ' . vs_format_bytes((int)$f['size']) . '. The maximum upload size is ' . vs_format_bytes($maxBytes) . '. Choose a smaller file or paste a link instead.';
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
            if ($chk->get_result()->fetch_row()) $errors[] = 'This video is already on ' . vs_branding_app_name() . '.';
            $chk->close();
        }

        // Premium perk: free accounts can add a limited number of videos and shorts per day
        if (!$errors && $username !== '' && is_file(__DIR__ . '/premium-perks.php')) {
            require_once __DIR__ . '/premium-perks.php';
            $uploadCap = vs_perk_limit($username, 'uploads_per_day');
            if ($uploadCap !== null) {
                $uploadsToday = vs_perk_uploads_today($conn, $username);
                if ($uploadsToday === null) {
                    $errors[] = 'Could not verify your daily upload limit. Please try again later.';
                } elseif ($uploadsToday >= $uploadCap) {
                    $errors[] = 'You have reached your plan\'s daily upload limit of ' . $uploadCap . '. Try again tomorrow or review the Premium plans.';
                }
            }
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

                $pointsEarned = 0;
                $pointsCreditFailed = false;
                if ($username !== '') {
                    try {
                        $rewardsPdo = vs_premium_pdo();
                        if (!$rewardsPdo) {
                            throw new RuntimeException('No PDO database connection is available.');
                        }
                        if (vs_rewards_award_upload($rewardsPdo, $username, (string)$newId)) {
                            $pointsEarned = 5;
                        }
                    } catch (Throwable $rewardsError) {
                        error_log('VibeStream upload points could not be credited for ' . $username . ': ' . $rewardsError->getMessage());
                        $pointsCreditFailed = true;
                    }
                    vs_notify_subscribers($conn, $poster, $old['type'], (string)$newId, $old['title']);
                }

                $_SESSION['upload_flash'] = [
                    'type' => $old['type'],
                    'id' => $newId,
                    'title' => $old['title'],
                    'points_earned' => $pointsEarned,
                    'points_error' => $pointsCreditFailed,
                ];
                header('Location: upload.php');
                exit;
            } catch (Throwable $e) {
                if ($savedFile && is_file($savedFile)) @unlink($savedFile);
                $errors[] = 'Could not save: ' . $e->getMessage();
            }
        }
        }
    }
    if ($errors) {
        vs_upload_record_failure(
            $conn,
            $username,
            $old['type'],
            $old['mode'],
            (string)$errors[0],
            $uploadsOn,
            is_array($_FILES['video_file'] ?? null) ? $_FILES['video_file'] : []
        );
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
    width: 100%; background: var(--surface-raised, #0d0d0f); border: 1px solid var(--border-strong, #3d3d46); border-radius: 12px;
    padding: 12px 14px; color: var(--text-primary, #ffffff); outline: none;
}
.field input::placeholder, .field textarea::placeholder { color: var(--text-tertiary, #8a8a94); }
.field textarea { min-height: 110px; resize: vertical; line-height: 1.5; }
.field input:focus, .field textarea:focus { border-color: #5b8cff; box-shadow: 0 0 0 3px rgba(91, 140, 255, 0.18); }
.field input[type=file] { padding: 10px; }
.field select {
    width: 100%; background: var(--surface-raised, #0d0d0f); border: 1px solid var(--border-strong, #3d3d46); border-radius: 12px;
    padding: 12px 14px; color: var(--text-primary, #ffffff); outline: none;
}
.field select:focus { border-color: #5b8cff; box-shadow: 0 0 0 3px rgba(91, 140, 255, 0.18); }
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
.upload-live-section { margin: 0 0 18px; }
.upload-live-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
.upload-live-head h2 { margin:0; font-size:18px; }
.upload-live-head a { color:var(--blue); text-decoration:none; font-size:13px; }
.upload-live-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; }
.upload-live-card { min-width:0; overflow:hidden; border-radius:14px; border:1px solid var(--border); background:var(--surface); color:inherit; text-decoration:none; }
.upload-live-thumb { position:relative; aspect-ratio:16/9; background:linear-gradient(135deg,#23205c,#7c3aed); }
.upload-live-thumb img { width:100%; height:100%; object-fit:cover; display:block; }
.upload-live-status { position:absolute; left:8px; bottom:8px; border-radius:999px; padding:4px 7px; background:#e11d48; color:#fff; font-size:10px; font-weight:900; }
.upload-live-status.scheduled { background:#7c3aed; }
.upload-live-copy { padding:9px; }
.upload-live-copy strong { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; }
.upload-live-copy span { display:block; margin-top:4px; color:var(--muted); font-size:12px; }
@media(max-width:760px){ .upload-live-grid{grid-template-columns:repeat(2,minmax(0,1fr));} }
@media(max-width:430px){ .upload-live-grid{grid-template-columns:1fr;} }
/* ===== NEON THEME (dark + light) ===== */
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2e93; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #00e5ff;
            --neon-cyan: #00e5ff; --neon-pink: #ff2e93; --neon-violet: #8a5cff;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) body .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(0,229,255,.35); box-shadow: 0 1px 24px -4px rgba(0,229,255,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) body .brand { background: linear-gradient(90deg, #00e5ff, #8a5cff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) body .nav-toggle span { background: #00e5ff; box-shadow: 0 0 6px rgba(0,229,255,.7); }
        html:not([data-theme="light"]) body .nav-toggle:hover, html:not([data-theme="light"]) body .theme-toggle:hover { background: rgba(0,229,255,.14); box-shadow: 0 0 14px rgba(0,229,255,.4); }
        html:not([data-theme="light"]) body .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) body .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) body .create-btn, html:not([data-theme="light"]) body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) body #search-input:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 20px rgba(0,229,255,.55); }
        html:not([data-theme="light"]) body .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) body .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) body .sidebar-link:hover { background: rgba(0,229,255,.10); box-shadow: inset 3px 0 0 #00e5ff, 0 0 16px -4px rgba(0,229,255,.5); }
        html:not([data-theme="light"]) body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body .sidebar-heading { color: #00e5ff; text-shadow: 0 0 8px rgba(0,229,255,.45); }
        html:not([data-theme="light"]) body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #8a5cff, #ff2e93); border-radius: 8px; }
        html:not([data-theme="light"]) body ::selection { background: rgba(255,46,147,.4); color: #fff; }

/* ---- upload page ---- */
html:not([data-theme="light"]) body .upload-wrap h1 { text-shadow: 0 0 16px rgba(138,92,255,.65); }
html:not([data-theme="light"]) body .card { background: rgba(10,13,31,.88); border: 1px solid rgba(138,92,255,.5); box-shadow: 0 0 30px -8px rgba(0,229,255,.5), 0 0 70px -24px rgba(138,92,255,.55); }
html:not([data-theme="light"]) body .notice { box-shadow: 0 0 18px -6px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .notice.error { border-color: rgba(255,46,147,.7); background: rgba(255,46,147,.12); box-shadow: 0 0 18px -6px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .notice.ok { border-color: rgba(0,229,255,.6); background: rgba(0,229,255,.10); }
html:not([data-theme="light"]) body .notice a { color: #00e5ff; }
html:not([data-theme="light"]) body .field label, html:not([data-theme="light"]) body .field .label { color: #eaf0ff; }
html:not([data-theme="light"]) body .field input[type=text], html:not([data-theme="light"]) body .field input[type=url], html:not([data-theme="light"]) body .field textarea, html:not([data-theme="light"]) body .field input[type=file], html:not([data-theme="light"]) body .field select { background: rgba(10,13,31,.9); color: #eaf0ff; border: 1px solid rgba(138,92,255,.5); transition: box-shadow .18s ease, border-color .18s ease; }
html:not([data-theme="light"]) body .field input:focus, html:not([data-theme="light"]) body .field textarea:focus, html:not([data-theme="light"]) body .field select:focus { outline: none; border-color: #00e5ff; box-shadow: 0 0 0 1px #00e5ff, 0 0 18px rgba(0,229,255,.5); }
html:not([data-theme="light"]) body .field input[type=file]::file-selector-button { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; border-radius: 8px; padding: 6px 12px; margin-right: 12px; cursor: pointer; box-shadow: 0 0 12px rgba(255,46,147,.5); }
html:not([data-theme="light"]) body .seg { background: rgba(23,28,66,.7); border: 1px solid rgba(138,92,255,.45); }
html:not([data-theme="light"]) body .seg input:checked + label { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; box-shadow: 0 0 16px rgba(255,46,147,.6); }
html:not([data-theme="light"]) body .submit-btn { background: linear-gradient(90deg, #8a5cff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 20px rgba(255,46,147,.55); transition: box-shadow .18s ease, transform .18s ease; }
html:not([data-theme="light"]) body .submit-btn:hover:not(:disabled) { background: linear-gradient(90deg, #8a5cff, #ff2e93); transform: translateY(-1px); box-shadow: 0 0 30px rgba(255,46,147,.85); }
html:not([data-theme="light"]) body .posting-as a { color: #00e5ff; }

html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2e93; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #00b8e6;
            --neon-cyan: #00b8e6; --neon-pink: #ff2e93; --neon-violet: #7c4dff;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] body .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(0,184,230,.55); box-shadow: 0 1px 22px -4px rgba(0,184,230,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] body .brand { background: linear-gradient(90deg, #00b8e6, #7c4dff 55%, #ff2e93); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] body .nav-toggle span { background: #7c4dff; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] body .nav-toggle:hover, html[data-theme="light"] body .theme-toggle:hover { background: rgba(0,184,230,.14); box-shadow: 0 0 14px rgba(0,184,230,.4); }
        html[data-theme="light"] body .theme-toggle svg { color: #7c4dff; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] body .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] body .create-btn, html[data-theme="light"] body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] body #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] body #search-input:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 20px rgba(0,184,230,.55); }
        html[data-theme="light"] body .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] body .logout-link:hover { color: #ff2e93; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] body .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] body .sidebar-link:hover { background: rgba(0,184,230,.12); box-shadow: inset 3px 0 0 #00b8e6, 0 0 16px -4px rgba(0,184,230,.5); }
        html[data-theme="light"] body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2e93, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] body .sidebar-heading { color: #7c4dff; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] body ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #7c4dff, #ff2e93); border-radius: 8px; }
        html[data-theme="light"] body ::selection { background: rgba(255,46,147,.4); color: #0b1030; }

/* ---- upload page ---- */
html[data-theme="light"] body .upload-wrap h1 { text-shadow: 0 0 16px rgba(124,77,255,.3); }
html[data-theme="light"] body .card { background: #ffffff; border: 1px solid rgba(124,77,255,.5); box-shadow: 0 0 30px -8px rgba(0,184,230,.5), 0 0 70px -24px rgba(124,77,255,.55); }
html[data-theme="light"] body .notice { box-shadow: 0 0 18px -6px rgba(0,184,230,.5); }
html[data-theme="light"] body .notice.error { border-color: rgba(255,46,147,.7); background: rgba(255,46,147,.12); box-shadow: 0 0 18px -6px rgba(255,46,147,.6); }
html[data-theme="light"] body .notice.ok { border-color: rgba(0,184,230,.6); background: rgba(0,184,230,.10); }
html[data-theme="light"] body .notice a { color: #0086b3; }
html[data-theme="light"] body .field label, html[data-theme="light"] body .field .label { color: #0b1030; }
html[data-theme="light"] body .field input[type=text], html[data-theme="light"] body .field input[type=url], html[data-theme="light"] body .field textarea, html[data-theme="light"] body .field input[type=file], html[data-theme="light"] body .field select { background: #ffffff; color: #0b1030; border: 1px solid rgba(124,77,255,.5); transition: box-shadow .18s ease, border-color .18s ease; }
html[data-theme="light"] body .field input:focus, html[data-theme="light"] body .field textarea:focus, html[data-theme="light"] body .field select:focus { outline: none; border-color: #00b8e6; box-shadow: 0 0 0 1px #00b8e6, 0 0 18px rgba(0,184,230,.5); }
html[data-theme="light"] body .field input[type=file]::file-selector-button { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; border-radius: 8px; padding: 6px 12px; margin-right: 12px; cursor: pointer; box-shadow: 0 0 12px rgba(255,46,147,.5); }
html[data-theme="light"] body .seg { background: #ffffff; border: 1px solid rgba(124,77,255,.45); }
html[data-theme="light"] body .seg input:checked + label { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; box-shadow: 0 0 16px rgba(255,46,147,.6); }
html[data-theme="light"] body .submit-btn { background: linear-gradient(90deg, #7c4dff, #ff2e93); color: #fff; border: 0; box-shadow: 0 0 20px rgba(255,46,147,.55); transition: box-shadow .18s ease, transform .18s ease; }
html[data-theme="light"] body .submit-btn:hover:not(:disabled) { background: linear-gradient(90deg, #7c4dff, #ff2e93); transform: translateY(-1px); box-shadow: 0 0 30px rgba(255,46,147,.85); }
html[data-theme="light"] body .posting-as a { color: #0086b3; }

/* Creator upload workspace */
.upload-wrap{max-width:1100px}
.upload-heading{display:flex;align-items:center;gap:18px;margin:0 0 20px}
.upload-heading-icon{display:grid;place-items:center;flex:0 0 78px;width:78px;height:68px;border-radius:24px;background:linear-gradient(145deg,#8b1cff,#4e12d3);box-shadow:0 8px 26px rgba(128,38,255,.32);color:#fff}
.upload-heading-icon svg{width:44px;height:44px}
.upload-heading h1{margin:0;color:#f8f6ff;font-size:clamp(28px,3vw,38px);font-weight:850;letter-spacing:-.04em}
.upload-heading h1 span{color:#a935ff}
.upload-wrap .sub{margin:4px 0 0;color:#b9b3d2;font-size:14px}
.upload-heading-note{display:flex;align-items:center;gap:12px;min-height:60px;margin-left:auto;padding:10px 14px;border:1px solid rgba(139,92,246,.3);border-radius:14px;background:linear-gradient(125deg,rgba(23,18,50,.94),rgba(12,11,28,.94));color:#d8d2eb;font-size:12px;line-height:1.45}
.upload-heading-note>span:first-child{color:#c6a9ff;font-size:24px}
.upload-heading-note strong{color:#f6f1ff}
.upload-layout{display:grid;grid-template-columns:minmax(0,2.1fr) minmax(265px,.95fr);align-items:start;gap:18px}
.upload-main,.upload-side{min-width:0}
.upload-form{padding:18px;border-radius:18px}
.upload-form>.field{margin-bottom:15px}
.upload-type-label{margin-bottom:9px!important}
.upload-type-tabs{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;width:100%;padding:4px;border-radius:14px}
.upload-type-tabs input{position:absolute;opacity:0;pointer-events:none}
.upload-type-tabs label{display:flex;justify-content:center;align-items:center;min-height:39px;padding:7px 10px;border-radius:11px;text-align:center}
.upload-source-layout{display:grid;gap:10px}
.upload-source-toggle{display:none}
.upload-form .visually-hidden{position:absolute!important;width:1px;height:1px;padding:0!important;margin:-1px!important;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
.upload-drop-field{margin:0!important}
.upload-dropzone{position:relative;display:flex!important;min-height:142px;flex-direction:column;justify-content:center;align-items:center;gap:5px;overflow:hidden;padding:15px;border:1px dashed rgba(174,120,255,.8);border-radius:14px;background:radial-gradient(ellipse at 50% 110%,rgba(113,44,221,.15),transparent 60%),rgba(14,14,34,.82);cursor:pointer;text-align:center;transition:border-color .18s ease,background .18s ease}
.upload-dropzone:hover,.upload-dropzone.is-dragging{border-color:#d1a7ff;background-color:rgba(78,36,137,.16)}
.upload-dropzone svg{width:46px;height:46px;margin-bottom:2px;color:#a43bff;filter:drop-shadow(0 0 8px rgba(164,59,255,.5))}
.upload-dropzone strong{color:#f5f2ff;font-size:16px}
.upload-dropzone>span{color:#b9b3d2;font-size:13px}
.upload-dropzone small{color:#9e97b8;font-size:11px}
.upload-dropzone input[type=file]{position:absolute!important;inset:0;width:100%!important;height:100%;padding:0!important;opacity:0;cursor:pointer}
.upload-or{display:flex;align-items:center;gap:12px;color:#aaa3c1;font-size:12px}
.upload-or::before,.upload-or::after{content:"";height:1px;flex:1;background:rgba(139,92,246,.25)}
.upload-link-field{position:relative;margin:0!important}
.upload-link-field .upload-link-icon{position:absolute;z-index:1;left:13px;top:50%;transform:translateY(-50%);color:#bda2ff;font-size:18px}
.upload-form .upload-link-field input{min-height:42px;padding:10px 13px 10px 40px;border-radius:11px}
.upload-form-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,.78fr);gap:14px;margin-top:16px}
.upload-form-grid .field,.upload-description-row .field{min-width:0;margin-bottom:12px}
.upload-form .field label,.upload-form .field .label{margin-bottom:6px;font-size:13px}
.upload-form .field input[type=text],.upload-form .field input[type=url],.upload-form .field textarea,.upload-form .field select{min-height:39px;padding:9px 11px;border-radius:9px;font-size:12px}
.upload-form .field textarea{min-height:62px;resize:vertical}
.upload-form .field .hint{font-size:10px;margin-top:4px}
.required-mark{color:#ff758f}
.upload-description-row{display:grid;grid-template-columns:minmax(0,1fr) 100px;gap:14px;align-items:start}
.upload-type-summary{padding-top:0}
.upload-type-chip{display:inline-flex;align-items:center;min-height:37px;padding:0 12px;border:1px solid rgba(139,92,246,.3);border-radius:9px;background:rgba(139,92,246,.1);color:#d9c8ff;font-size:12px}
.upload-form .submit-btn{display:block;width:100%;min-height:47px;margin-top:5px;border-radius:12px;font-size:15px}
.upload-form .upload-clear-btn{width:auto;min-height:34px;margin:10px auto 0;padding:6px 12px;background:transparent!important;box-shadow:none!important;color:#aaa3c1;font-size:11px;font-weight:600}
.upload-form .upload-clear-btn:hover{color:#fff;text-decoration:underline}
.upload-form .posting-as{display:block;margin:10px 0 0;text-align:center;font-size:11px}
.upload-side{display:grid;gap:13px}
.upload-side-card{margin:0;padding:14px;border:1px solid rgba(105,100,154,.25);border-radius:15px;background:linear-gradient(155deg,rgba(20,19,43,.98),rgba(12,11,27,.98));box-shadow:0 10px 28px -24px rgba(0,0,0,.8)}
.upload-live-head{display:flex;align-items:center;justify-content:space-between;margin:0 0 8px;padding:0 0 9px;border-bottom:1px solid rgba(139,92,246,.18)}
.upload-live-head h2,.upload-tips h2{display:flex;align-items:center;gap:8px;margin:0;color:#f1efff;font-size:15px;font-weight:750}
.upload-live-head>a{color:#b44aff;font-size:11px;text-decoration:none}
.upload-live-indicator{width:9px;height:9px;border-radius:50%;background:#ff3157;box-shadow:0 0 10px rgba(255,49,87,.6)}
.upload-live-grid{display:grid;gap:0}
.upload-live-card{display:grid;grid-template-columns:82px minmax(0,1fr);align-items:center;gap:10px;padding:7px 0;border:0;border-bottom:1px solid rgba(130,123,176,.12);border-radius:0;background:transparent}
.upload-live-card:last-child{border-bottom:0}
.upload-live-thumb{aspect-ratio:16/10;border-radius:8px}
.upload-live-status{left:auto;right:4px;bottom:4px;padding:3px 5px;font-size:8px}
.upload-live-copy{min-width:0;padding:0}
.upload-live-copy strong{color:#eeeafa;font-size:11px}
.upload-live-copy span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:10px}
.upload-tips h2>span{color:#ffd74c;font-size:20px}
.upload-tips ul{display:grid;gap:8px;margin:10px 0 0;padding:0;list-style:none}
.upload-tips li{position:relative;padding-left:22px;color:#c5bfd9;font-size:11px;line-height:1.4}
.upload-tips li::before{content:"✓";position:absolute;left:0;top:0;display:grid;place-items:center;width:15px;height:15px;border-radius:50%;background:#00c98b;color:#fff;font-size:10px;font-weight:900}
.upload-premium-card{position:relative;min-height:104px;overflow:hidden;padding:14px;border:1px solid rgba(151,49,255,.4);border-radius:15px;background:radial-gradient(circle at 90% 90%,rgba(145,34,255,.5),transparent 44%),linear-gradient(125deg,#22104e,#4b0ab4 70%,#6b13db);color:#fff}
.upload-premium-card h2{margin:0 0 4px;font-size:15px}
.upload-premium-card p{max-width:75%;margin:0 0 10px;color:#e3d8fb;font-size:11px;line-height:1.4}
.upload-premium-card>a{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:8px;background:#781cff;color:#fff;font-size:10px;font-weight:750;text-decoration:none}
.upload-premium-rocket{position:absolute;right:12px;bottom:16px;font-size:42px;transform:rotate(-14deg);filter:drop-shadow(0 3px 8px rgba(0,0,0,.25))}
.upload-wrap .notice{font-size:12px}
.upload-wrap .upload-draft-status{margin:0 0 12px;padding:8px 10px;border-radius:9px;font-size:10px;line-height:1.4;box-shadow:none}
.upload-wrap .notice code,.upload-wrap .notice pre{overflow-wrap:anywhere}
html:not([data-theme="light"]) body .upload-form{background:linear-gradient(155deg,rgba(20,19,43,.98),rgba(12,11,27,.98));border-color:rgba(105,100,154,.25);box-shadow:0 10px 28px -24px rgba(0,0,0,.8)}
html:not([data-theme="light"]) body .upload-type-tabs{background:rgba(10,11,27,.6);border:1px solid rgba(105,100,154,.25)}
html:not([data-theme="light"]) body .upload-type-tabs input:checked+label{background:linear-gradient(105deg,#8318ee,#4a39f5);box-shadow:0 5px 16px rgba(119,28,239,.3);color:#fff}
html:not([data-theme="light"]) body .upload-form .field input[type=text],html:not([data-theme="light"]) body .upload-form .field input[type=url],html:not([data-theme="light"]) body .upload-form .field textarea,html:not([data-theme="light"]) body .upload-form .field select{background:rgba(13,14,34,.9);border-color:rgba(139,92,246,.32);color:#eeeafa}
html:not([data-theme="light"]) body .upload-side-card{background:linear-gradient(155deg,rgba(20,19,43,.98),rgba(12,11,27,.98));border-color:rgba(105,100,154,.25)}
html[data-theme="light"] body .upload-heading h1{color:#211737}
html[data-theme="light"] body .upload-wrap .sub{color:#656079}
html[data-theme="light"] body .upload-heading-note,html[data-theme="light"] body .upload-side-card,html[data-theme="light"] body .upload-form{background:linear-gradient(155deg,#fff,#faf9ff);border-color:rgba(110,77,160,.2);box-shadow:0 10px 28px -24px rgba(49,25,91,.38)}
html[data-theme="light"] body .upload-heading-note,html[data-theme="light"] body .upload-heading-note strong,html[data-theme="light"] body .upload-live-head h2,html[data-theme="light"] body .upload-tips h2,html[data-theme="light"] body .upload-live-copy strong{color:#211737}
html[data-theme="light"] body .upload-type-tabs{background:#f5f1ff;border:1px solid rgba(110,77,160,.2)}
html[data-theme="light"] body .upload-type-tabs input:checked+label{background:linear-gradient(105deg,#8318ee,#4a39f5);box-shadow:0 5px 16px rgba(119,28,239,.24);color:#fff}
html[data-theme="light"] body .upload-form .field input[type=text],html[data-theme="light"] body .upload-form .field input[type=url],html[data-theme="light"] body .upload-form .field textarea,html[data-theme="light"] body .upload-form .field select{background:#fff;border-color:rgba(110,77,160,.27);color:#211737}
html[data-theme="light"] body .upload-dropzone{background:radial-gradient(ellipse at 50% 110%,rgba(113,44,221,.09),transparent 60%),#faf8ff}
html[data-theme="light"] body .upload-dropzone strong{color:#211737}
html[data-theme="light"] body .upload-dropzone>span,html[data-theme="light"] body .upload-dropzone small,html[data-theme="light"] body .upload-tips li,html[data-theme="light"] body .upload-live-copy span{color:#656079}
html[data-theme="light"] body .upload-type-chip{color:#55329a}
html[data-theme="light"] body .upload-form .upload-clear-btn{color:#656079}
@media(max-width:900px){.upload-layout{grid-template-columns:minmax(0,1fr) minmax(240px,.72fr);gap:12px}.upload-heading-note{display:none}.upload-form{padding:15px}}
@media(max-width:720px){.upload-layout{grid-template-columns:1fr}.upload-side{grid-template-columns:repeat(2,minmax(0,1fr));align-items:start}.upload-live-section{grid-column:1/-1}.upload-premium-card{min-height:100%}}
@media(max-width:520px){.upload-heading{gap:12px;margin-bottom:14px}.upload-heading-icon{flex-basis:58px;width:58px;height:56px;border-radius:18px}.upload-heading-icon svg{width:34px;height:34px}.upload-heading h1{font-size:28px}.upload-wrap .sub{font-size:12px}.upload-side{grid-template-columns:1fr}.upload-live-section{grid-column:auto}.upload-form-grid{grid-template-columns:1fr;gap:0}.upload-description-row{grid-template-columns:minmax(0,1fr) 82px;gap:8px}.upload-type-tabs label{padding:7px 5px;font-size:12px}.upload-dropzone{min-height:130px}}
@media(prefers-reduced-motion:reduce){.upload-dropzone,.upload-type-tabs label,.upload-form .submit-btn{transition:none!important}}

CSS;

vs_page_start('Upload', 'upload', $extraCss);
?>
<div class="page">
    <div class="upload-wrap">
        <header class="upload-heading">
            <span class="upload-heading-icon" aria-hidden="true">
                <svg viewBox="0 0 48 48" fill="none"><path d="M24 33V10m0 0L14 20m10-10 10 10M10 31v6a4 4 0 0 0 4 4h20a4 4 0 0 0 4-4v-6" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div>
                <h1>Upload <span id="uploadHeadingType"><?= $old['type'] === 'live' ? 'Live Stream' : ($old['type'] === 'short' ? 'Short' : 'Video') ?></span></h1>
                <p class="sub">Share your creativity with the VibeStream community.</p>
            </div>
            <div class="upload-heading-note">
                <span aria-hidden="true">▶</span>
                <span>You can upload:<br><strong>Videos, Shorts &amp; Live Streams</strong></span>
            </div>
        </header>
        <div class="upload-layout">
        <section class="upload-main" aria-label="Upload content">
        <?php if ($flash): ?>
            <div class="notice ok" id="uploadSuccess">
                <strong>Uploaded:</strong> <?= htmlspecialchars($flash['title']) ?>.
                <?php if ($flash['type'] === 'short'): ?>
                    <a href="shorts.php?id=<?= urlencode($flash['id']) ?>">Watch the short</a>
                <?php elseif ($flash['type'] === 'live'): ?>
                    <a href="live.php?stream=<?= urlencode((string)$flash['id']) ?>">Open live stream</a>
                <?php else: ?>
                    <a href="watch.php?id=<?= urlencode($flash['id']) ?>">Watch the video</a>
                <?php endif; ?>
                &middot; <a href="profile.php">Open your profile</a>
                <?php if (!empty($flash['points_earned'])): ?>
                    &middot; You earned <?= (int)$flash['points_earned'] ?> points!
                <?php elseif (!empty($flash['points_error'])): ?>
                    <p>We could not credit your upload points just now. Please contact support so we can help.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!$uploadsOn): ?>
            <div class="notice warn">
                <strong>Uploads are turned off.</strong> New videos and shorts cannot be added right now. Please check back later.
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

        <form class="card upload-form" method="post" enctype="multipart/form-data" id="uploadForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">

            <div class="field">
                <span class="label upload-type-label">What are you uploading?</span>
                <div class="seg upload-type-tabs" role="radiogroup" aria-label="Upload type">
                    <input type="radio" name="type" id="typeVideo" value="video"<?= $old['type'] === 'video' ? ' checked' : '' ?>>
                    <label for="typeVideo">Video</label>
                    <input type="radio" name="type" id="typeShort" value="short"<?= $old['type'] === 'short' ? ' checked' : '' ?>>
                    <label for="typeShort">Shorts</label>
                    <input type="radio" name="type" id="typeLive" value="live"<?= $old['type'] === 'live' ? ' checked' : '' ?>>
                    <label for="typeLive">Live Stream</label>
                </div>
            </div>

            <div class="upload-source-layout">
                <div class="field upload-source-toggle">
                    <span class="label">Video source</span>
                    <div class="seg" role="radiogroup" aria-label="Video source">
                        <input type="radio" name="mode" id="modeLink" value="link"<?= $old['mode'] === 'link' ? ' checked' : '' ?>>
                        <label for="modeLink">Paste a link</label>
                        <input type="radio" name="mode" id="modeFile" value="file"<?= $old['mode'] === 'file' ? ' checked' : '' ?>>
                        <label for="modeFile">Upload a file</label>
                    </div>
                </div>

                <div class="field upload-drop-field" id="fileField">
                    <label class="upload-dropzone" for="video_file">
                        <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M24 32V10m0 0L14 20m10-10 10 10M9 31v7a3 3 0 0 0 3 3h24a3 3 0 0 0 3-3v-7" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <strong id="uploadFilePrompt">Drag &amp; drop your video here</strong>
                        <span>or click to browse</span>
                        <small>MP4, MOV, WebM · Max size: <?= htmlspecialchars(vs_format_bytes($maxBytes), ENT_QUOTES, 'UTF-8') ?></small>
                        <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.webm,.ogg,.ogv,.m4v,.mov">
                    </label>
                </div>

                <div class="upload-or" aria-hidden="true"><span>OR</span></div>
                <div class="field upload-link-field" id="linkField">
                    <label class="visually-hidden" for="video_url">Video link</label>
                    <span class="upload-link-icon" aria-hidden="true">↗</span>
                    <input type="text" id="video_url" name="video_url" placeholder="Paste a YouTube or video link..." value="<?= htmlspecialchars($old['video_url']) ?>">
                </div>
            </div>

            <div class="upload-form-grid">
                <div class="field">
                    <label for="title">Title <span class="required-mark">*</span></label>
                    <input type="text" id="title" name="title" maxlength="150" required value="<?= htmlspecialchars($old['title']) ?>" placeholder="Enter a catchy title...">
                    <div class="hint">Up to 150 characters</div>
                </div>
                <?php if ($showCategory): ?>
                    <div class="field">
                        <label for="category">Category</label>
                        <select id="category" name="category" required>
                            <option value="">Select category</option>
                            <?php foreach ($categoryChoices as $choice): $cv = strtolower(trim((string)$choice)); ?>
                                <option value="<?= htmlspecialchars($cv) ?>"<?= $old['category'] === $cv ? ' selected' : '' ?>><?= htmlspecialchars($choice) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
            </div>
            <div class="upload-description-row">
                <div class="field" id="descriptionField">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="2000" rows="3" placeholder="Tell viewers about your video..."><?= htmlspecialchars($old['description']) ?></textarea>
                    <div class="hint">Up to 2,000 characters</div>
                </div>
                <div class="field upload-type-summary">
                    <span class="label">Type</span>
                    <span class="upload-type-chip" id="uploadTypeChip">Video</span>
                </div>
            </div>

            <div class="field" id="liveFields"<?= $old['type'] === 'live' ? '' : ' hidden' ?>>
                <span class="label">Live status</span>
                <div class="seg" role="radiogroup" aria-label="Live status">
                    <input type="radio" name="live_status" id="liveNow" value="live"<?= $old['live_status'] !== 'scheduled' ? ' checked' : '' ?>>
                    <label for="liveNow">Go live</label>
                    <input type="radio" name="live_status" id="liveScheduled" value="scheduled"<?= $old['live_status'] === 'scheduled' ? ' checked' : '' ?>>
                    <label for="liveScheduled">Schedule</label>
                </div>
                <div class="field" style="margin-top:14px;margin-bottom:14px">
                    <label for="stream_url">Stream or YouTube Live link</label>
                    <input type="url" id="stream_url" name="stream_url" placeholder="https://www.youtube.com/live/..." value="<?= htmlspecialchars($old['stream_url']) ?>">
                </div>
                <div class="field" style="margin-bottom:14px">
                    <label for="thumbnail_url">Thumbnail URL</label>
                    <input type="url" id="thumbnail_url" name="thumbnail_url" placeholder="Optional image URL" value="<?= htmlspecialchars($old['thumbnail_url']) ?>">
                </div>
                <div class="field" id="scheduledField"<?= $old['live_status'] === 'scheduled' ? '' : ' hidden' ?>>
                    <label for="scheduled_at">Scheduled date and time</label>
                    <input type="datetime-local" id="scheduled_at" name="scheduled_at" value="<?= htmlspecialchars($old['scheduled_at']) ?>">
                </div>
                <div class="hint" id="urlTypeHint">Add a YouTube Live, HLS, MP4, WebM, or embed URL.</div>
            </div>

            <div class="notice upload-draft-status" id="draftStatus" role="status" aria-live="polite">Unfinished details save in this browser. You may need to reselect a video file if browser storage is unavailable.</div>
            <div>
                <button type="submit" class="submit-btn" id="submitBtn"<?= ($srcCol === null || !$uploadsOn) ? ' disabled' : '' ?>>Upload</button>
                <button type="button" class="submit-btn upload-clear-btn" id="clearDraftBtn">Clear saved draft</button>
                <?php if ($username !== ''): ?>
                    <span class="posting-as">Posting as @<?= htmlspecialchars($username) ?></span>
                <?php else: ?>
                    <span class="posting-as"><a href="login.php">Sign in</a> so this upload shows on your profile.</span>
                <?php endif; ?>
            </div>
        </form>
        </section>
        <aside class="upload-side" aria-label="Upload resources">
            <section class="upload-live-section upload-side-card" id="uploadLiveSection" aria-label="Live streams"<?= empty($uploadLiveStreams) ? ' style="display:none"' : '' ?>>
                <div class="upload-live-head">
                    <h2><i class="upload-live-indicator"></i>Live Streams</h2>
                    <a href="live.php">View All <span aria-hidden="true">→</span></a>
                </div>
                <div class="upload-live-grid">
                    <?php foreach (array_slice($uploadLiveStreams, 0, 4) as $uploadLive):
                        $uploadLiveThumb = trim((string)$uploadLive['thumbnail_url']);
                        if (preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?.*?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{11})#i', $uploadLiveThumb, $youtubeMatch)) {
                            $uploadLiveThumb = 'https://i.ytimg.com/vi/' . rawurlencode($youtubeMatch[1]) . '/hqdefault.jpg';
                        }
                    ?>
                    <a class="upload-live-card" href="live.php?stream=<?= (int)$uploadLive['id'] ?>">
                        <div class="upload-live-thumb">
                            <?php if ($uploadLiveThumb !== ''): ?>
                            <img src="<?= htmlspecialchars($uploadLiveThumb, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" onerror="this.style.display='none'">
                            <?php endif; ?>
                            <span class="upload-live-status<?= $uploadLive['status'] === 'scheduled' ? ' scheduled' : '' ?>"><?= $uploadLive['status'] === 'live' ? 'LIVE' : 'SCHEDULED' ?></span>
                        </div>
                        <div class="upload-live-copy">
                            <strong><?= htmlspecialchars((string)$uploadLive['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars((string)$uploadLive['username'], ENT_QUOTES, 'UTF-8') ?> · <?= $uploadLive['status'] === 'live' ? number_format((int)$uploadLive['viewer_count']) . ' watching' : ($uploadLive['scheduled_at'] ? 'Scheduled · ' . date('M j, g:i A', strtotime((string)$uploadLive['scheduled_at'])) : 'Scheduled') ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
            <section class="upload-side-card upload-tips">
                <h2><span aria-hidden="true">✦</span>Upload Tips</h2>
                <ul>
                    <li>Use MP4, MOV or WebM for best compatibility</li>
                    <li>Add a catchy title and description</li>
                    <li>Choose the right category</li>
                    <li>Add a clear thumbnail to scheduled streams</li>
                </ul>
            </section>
            <section class="upload-premium-card">
                <span class="upload-premium-rocket" aria-hidden="true">🚀</span>
                <h2>Go Premium</h2>
                <p>Get larger uploads, advanced analytics and more.</p>
                <a href="premium.php">Upgrade Now <span aria-hidden="true">→</span></a>
            </section>
        </aside>
        </div>
    </div>
</div>
<?php
$extraJs = <<<'JS'
(function () {
    var section = document.getElementById('uploadLiveSection');
    var grid = section ? section.querySelector('.upload-live-grid') : null;
    if (!section || !grid) return;
    function safeThumbnail(value) {
        var source = String(value || '');
        var youtube = source.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?.*?v=|shorts\/|embed\/|live\/))([A-Za-z0-9_-]{11})/i)
            || source.match(/[?&]v=([A-Za-z0-9_-]{11})/i);
        if (youtube) return 'https://i.ytimg.com/vi/' + encodeURIComponent(youtube[1]) + '/hqdefault.jpg';
        if (!source) return '';
        try {
            var url = new URL(source, window.location.href);
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
        } catch (error) {
            return '';
        }
    }
    async function refreshUploadLive() {
        try {
            var response = await fetch('live-api.php?action=list', {cache:'no-store', credentials:'same-origin'});
            var data = await response.json();
            if (!response.ok || !data.ok) throw new Error('The live streams response was not successful.');
            var streams = Array.isArray(data.streams) ? data.streams.slice(0, 4) : [];
            grid.replaceChildren();
            streams.forEach(function (stream) {
                var card = document.createElement('a');
                card.className = 'upload-live-card';
                card.href = 'live.php?stream=' + encodeURIComponent(String(Number(stream.id) || 0));
                var thumb = document.createElement('div');
                thumb.className = 'upload-live-thumb';
                var imageUrl = safeThumbnail(stream.thumbnail_url);
                if (imageUrl) {
                    var image = document.createElement('img');
                    image.src = imageUrl;
                    image.alt = '';
                    image.loading = 'lazy';
                    image.addEventListener('error', function () { image.remove(); });
                    thumb.appendChild(image);
                }
                var status = document.createElement('span');
                status.className = 'upload-live-status' + (stream.status === 'scheduled' ? ' scheduled' : '');
                status.textContent = stream.status === 'live' ? 'LIVE' : 'SCHEDULED';
                thumb.appendChild(status);
                var copy = document.createElement('div');
                copy.className = 'upload-live-copy';
                var title = document.createElement('strong');
                title.textContent = String(stream.title || 'Untitled stream');
                var meta = document.createElement('span');
                meta.textContent = String(stream.username || 'Creator') + ' · ' + (stream.status === 'live'
                    ? Number(stream.viewer_count || 0).toLocaleString() + ' watching'
                    : 'Scheduled');
                copy.appendChild(title);
                copy.appendChild(meta);
                card.appendChild(thumb);
                card.appendChild(copy);
                grid.appendChild(card);
            });
            section.style.display = streams.length ? '' : 'none';
        } catch (error) {
            console.error('Upload live streams could not be refreshed.', error);
        }
    }
    refreshUploadLive();
    window.setInterval(refreshUploadLive, 15000);
})();

document.addEventListener('DOMContentLoaded', function () {
    var linkField = document.getElementById('linkField');
    var fileField = document.getElementById('fileField');
    var liveFields = document.getElementById('liveFields');
    var scheduledField = document.getElementById('scheduledField');
    var modes = document.querySelectorAll('input[name="mode"]');
    function syncMode() {
        var type = form ? form.querySelector('input[name="type"]:checked')?.value : 'short';
        var isLive = type === 'live';
        linkField.hidden = isLive;
        fileField.hidden = isLive;
        liveFields.hidden = !isLive;
        var liveScheduled = document.getElementById('liveScheduled');
        scheduledField.hidden = !liveScheduled || !liveScheduled.checked;
    }
    modes.forEach(function (m) { m.addEventListener('change', syncMode); });
    document.querySelectorAll('input[name="type"]').forEach(function (radio) { radio.addEventListener('change', syncMode); });
    document.querySelectorAll('input[name="live_status"]').forEach(function (radio) { radio.addEventListener('change', syncMode); });

    var form = document.getElementById('uploadForm');
    var btn = document.getElementById('submitBtn');
    var fileInput = document.getElementById('video_file');
    var filePrompt = document.getElementById('uploadFilePrompt');
    var typeHeading = document.getElementById('uploadHeadingType');
    var typeChip = document.getElementById('uploadTypeChip');
    var draftStatus = document.getElementById('draftStatus');
    var clearDraftBtn = document.getElementById('clearDraftBtn');
    var draftKey = __DRAFT_KEY__;
    var dbPromise = null;
    var saveTimer = null;

    var urlInput = document.getElementById('video_url');
    var streamUrlInput = document.getElementById('stream_url');
    var urlTypeHint = document.getElementById('urlTypeHint');
    function syncUploadType() {
        var type = form.querySelector('input[name="type"]:checked')?.value || 'video';
        var label = type === 'short' ? 'Shorts' : (type === 'live' ? 'Live Stream' : 'Video');
        if (typeHeading) typeHeading.textContent = label;
        if (typeChip) typeChip.textContent = label;
        btn.textContent = 'Upload ' + (type === 'short' ? 'Short' : (type === 'live' ? 'Live Stream' : 'Video'));
        syncMode();
    }
    document.querySelectorAll('input[name="type"]').forEach(function (radio) { radio.addEventListener('change', syncUploadType); });
    syncMode();
    syncUploadType();

    function setUploadMode(mode) {
        var radio = document.getElementById(mode === 'file' ? 'modeFile' : 'modeLink');
        if (radio) radio.checked = true;
    }
    fileInput.addEventListener('change', function () {
        setUploadMode('file');
        filePrompt.textContent = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : 'Drag & drop your video here';
        scheduleSave();
    });
    urlInput.addEventListener('focus', function () { setUploadMode('link'); });
    var dropzone = document.querySelector('.upload-dropzone');
    if (dropzone) {
        ['dragenter', 'dragover'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragging');
            });
        });
        ['dragleave', 'drop'].forEach(function (eventName) {
            dropzone.addEventListener(eventName, function (event) {
                event.preventDefault();
                dropzone.classList.remove('is-dragging');
            });
        });
        dropzone.addEventListener('drop', function (event) {
            var files = event.dataTransfer && event.dataTransfer.files;
            if (!files || !files.length) return;
            try {
                fileInput.files = files;
            } catch (error) {
                console.error('The selected video file could not be attached to the upload form.', error);
                return;
            }
            fileInput.dispatchEvent(new Event('change', {bubbles:true}));
        });
    }
    function detectUrlType(value) {
        var url = String(value || '').trim();
        if (!url) return '';
        if (/youtube\.com\/shorts\//i.test(url) || /youtu\.be\//i.test(url) && /short/i.test(url)) return 'Short';
        if (/youtube\.com\/live\//i.test(url) || /youtube\.com\/watch/i.test(url) || /\.m3u8(?:\?|$)/i.test(url)) {
            return /youtube\.com\/live\//i.test(url) || /\.m3u8(?:\?|$)/i.test(url) ? 'Live' : 'Video';
        }
        if (/\.mp4(?:\?|$)|\.webm(?:\?|$)|\.mov(?:\?|$)/i.test(url)) return 'Video';
        return '';
    }
    function syncUrlType() {
        var type = detectUrlType(urlInput.value);
        if (!type) return;
        var radio = form.querySelector('input[name="type"][value="' + type.toLowerCase() + '"]');
        if (radio) radio.checked = true;
        syncUploadType();
    }
    function syncLiveUrlType() {
        var type = detectUrlType(streamUrlInput.value);
        urlTypeHint.textContent = type === 'Live'
            ? 'Detected: Live stream URL.'
            : (type === 'Video' ? 'Detected: direct video URL.' : 'Add a YouTube Live, HLS, MP4, WebM, or embed URL.');
    }
    urlInput.addEventListener('input', function () {
        if (urlInput.value.trim()) setUploadMode('link');
        syncUrlType();
    });
    streamUrlInput.addEventListener('input', syncLiveUrlType);
    syncUrlType();
    syncLiveUrlType();

    function openDraftDb() {
        if (!window.indexedDB) return Promise.reject(new Error('IndexedDB is unavailable'));
        if (!dbPromise) {
            dbPromise = new Promise(function (resolve, reject) {
                var request = indexedDB.open('VibeStreamUploadDrafts', 1);
                request.onupgradeneeded = function () {
                    if (!request.result.objectStoreNames.contains('drafts')) {
                        request.result.createObjectStore('drafts');
                    }
                };
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error || new Error('Could not open browser storage')); };
            });
        }
        return dbPromise;
    }
    function withDraftStore(mode, action) {
        try {
            return openDraftDb().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var transaction = db.transaction('drafts', mode);
                    action(transaction.objectStore('drafts'), resolve, reject);
                    transaction.onerror = function () { reject(transaction.error || new Error('Browser storage operation failed')); };
                });
            });
        } catch (error) {
            return Promise.reject(error);
        }
    }
    function setStatus(message, isError) {
        draftStatus.textContent = message;
        draftStatus.classList.toggle('error', !!isError);
    }
    function readMetadata() {
        var type = form.querySelector('input[name="type"]:checked');
        var mode = form.querySelector('input[name="mode"]:checked');
        var category = document.getElementById('category');
        return {
            type: type ? type.value : 'short',
            mode: mode ? mode.value : 'link',
            title: document.getElementById('title').value,
            video_url: document.getElementById('video_url').value,
            description: document.getElementById('description').value,
            live_status: document.querySelector('input[name="live_status"]:checked')?.value || 'live',
            stream_url: document.getElementById('stream_url').value,
            thumbnail_url: document.getElementById('thumbnail_url').value,
            scheduled_at: document.getElementById('scheduled_at').value,
            category: category ? category.value : ''
        };
    }
    function saveDraft() {
        var data = readMetadata();
        try {
            localStorage.setItem(draftKey, JSON.stringify(data));
        } catch (error) {
            setStatus('Your upload details could not be saved in this browser. Keep this page open or copy your details before leaving.', true);
            return Promise.resolve();
        }
        var selected = fileInput.files && fileInput.files[0];
        if (!selected) {
            setStatus('Draft details saved in this browser.');
            return Promise.resolve();
        }
        withDraftStore('readwrite', function (store, resolve, reject) {
            var request = store.put({
                blob: selected,
                name: selected.name,
                type: selected.type,
                lastModified: selected.lastModified
            }, draftKey);
            request.onsuccess = resolve;
            request.onerror = function () { reject(request.error || new Error('File could not be saved')); };
        }).then(function () {
            setStatus('Draft details and selected video file saved in this browser.');
        }).catch(function () {
            setStatus('Draft details are saved, but browser storage could not keep the video file. You will need to select it again before retrying.', true);
        });
    }
    function scheduleSave() {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(saveDraft, 300);
    }
    function restoreDraft() {
        var saved;
        try {
            saved = JSON.parse(localStorage.getItem(draftKey) || 'null');
        } catch (error) {
            setStatus('The saved draft could not be read. You can continue with the fields shown.', true);
            return;
        }
        if (!saved || typeof saved !== 'object') return;
        document.getElementById('title').value = typeof saved.title === 'string' ? saved.title : '';
        document.getElementById('video_url').value = typeof saved.video_url === 'string' ? saved.video_url : '';
        document.getElementById('description').value = typeof saved.description === 'string' ? saved.description : '';
        document.getElementById('stream_url').value = typeof saved.stream_url === 'string' ? saved.stream_url : '';
        document.getElementById('thumbnail_url').value = typeof saved.thumbnail_url === 'string' ? saved.thumbnail_url : '';
        document.getElementById('scheduled_at').value = typeof saved.scheduled_at === 'string' ? saved.scheduled_at : '';
        var savedType = ['video', 'short', 'live'].includes(saved.type) ? saved.type : 'video';
        var typeRadio = form.querySelector('input[name="type"][value="' + savedType + '"]');
        var modeRadio = form.querySelector('input[name="mode"][value="' + (saved.mode === 'file' ? 'file' : 'link') + '"]');
        if (typeRadio) typeRadio.checked = true;
        if (modeRadio) modeRadio.checked = true;
        var liveStatusRadio = form.querySelector('input[name="live_status"][value="' + (saved.live_status === 'scheduled' ? 'scheduled' : 'live') + '"]');
        if (liveStatusRadio) liveStatusRadio.checked = true;
        var category = document.getElementById('category');
        if (category && typeof saved.category === 'string') category.value = saved.category;
        syncUploadType();
        if (saved.mode === 'file') {
            withDraftStore('readonly', function (store, resolve, reject) {
                var request = store.get(draftKey);
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error || new Error('Saved file could not be read')); };
            }).then(function (record) {
                if (!record || !record.blob || !window.DataTransfer || !window.File) {
                    setStatus('Draft details restored. Select the video file again to retry this upload.');
                    return;
                }
                var transfer = new DataTransfer();
                transfer.items.add(new File([record.blob], record.name, {
                    type: record.type || record.blob.type,
                    lastModified: record.lastModified || Date.now()
                }));
                fileInput.files = transfer.files;
                filePrompt.textContent = record.name || 'Drag & drop your video here';
                setStatus('Draft details and selected video file restored.');
            }).catch(function () {
                setStatus('Draft details restored, but the video file is unavailable. Select it again to retry.', true);
            });
        } else {
            setStatus('Unfinished upload draft restored.');
        }
    }
    function clearDraft() {
        window.clearTimeout(saveTimer);
        try {
            localStorage.removeItem(draftKey);
        } catch (error) {
            setStatus('The saved draft could not be cleared from browser storage.', true);
            return;
        }
        withDraftStore('readwrite', function (store, resolve, reject) {
            var request = store.delete(draftKey);
            request.onsuccess = resolve;
            request.onerror = function () { reject(request.error || new Error('Draft file could not be deleted')); };
        }).then(function () {
            setStatus('Saved draft cleared.');
        }).catch(function () {
            setStatus('Draft details cleared, but the saved video file could not be removed from browser storage.', true);
        });
    }

    if (document.getElementById('uploadSuccess')) {
        clearDraft();
    } else {
        restoreDraft();
    }
    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', function (event) {
        if (event.target === fileInput) {
            scheduleSave();
        } else {
            scheduleSave();
        }
    });
    clearDraftBtn.addEventListener('click', clearDraft);
    var submitting = false;
    form.addEventListener('submit', function (event) {
        if (submitting) return;
        event.preventDefault();
        submitting = true;
        btn.disabled = true;
        btn.textContent = 'Uploading...';
        window.clearTimeout(saveTimer);
        saveDraft().finally(function () {
            form.submit();
        });
    });
});
JS;
$extraJs = str_replace('__DRAFT_KEY__', json_encode($draftKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $extraJs);
vs_page_end($extraJs);
if (isset($conn)) { $conn->close(); }