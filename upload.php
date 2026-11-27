<?php
require_once __DIR__ . '/layout.php';  
include __DIR__ . '/connection.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/category-presets.php';
require_once __DIR__ . '/category-icons.php';
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
$old = ['type' => 'video', 'title' => '', 'description' => '', 'mode' => 'file', 'video_url' => '', 'category' => '', 'live_status' => 'live', 'stream_url' => '', 'thumbnail_url' => '', 'scheduled_at' => ''];

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
        if ($old['type'] === 'live') $old['live_status'] = $old['scheduled_at'] !== '' ? 'scheduled' : 'live';

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
        $videoScheduledAt = null;
        if ($old['scheduled_at'] !== '') {
            $ts = strtotime($old['scheduled_at']);
            if ($ts === false || $ts <= time()) {
                $errors[] = 'Choose a publish date and time in the future.';
            } elseif (!isset($cols['scheduled_at'])) {
                $errors[] = 'Scheduled publishing needs a scheduled_at DATETIME column on the videos table: ALTER TABLE videos ADD COLUMN scheduled_at DATETIME NULL;';
            } else {
                $videoScheduledAt = date('Y-m-d H:i:s', $ts);
            }
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

                if ($videoScheduledAt !== null) $put('scheduled_at', $videoScheduledAt);

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
.page.upload-page{max-width:1340px;padding-top:22px}
.upload-wrap{--pink:#ff2a32;--violet:#b50d16;--cyan:#ff2a32;--card:linear-gradient(165deg,rgba(14,18,46,.96),rgba(8,10,28,.97));--solid:#0a0d1f;--line:rgba(138,92,255,.34);--line2:rgba(255,42,50,.6);--tx:#f2f5ff;--mut:#9aa6d6;--faint:#7480b4;--inp:rgba(8,11,30,.85);--chip:rgba(255,255,255,.07);--grad:linear-gradient(90deg,#ff2a32,#b50d16 55%,#ff2a32);--grad-blue:linear-gradient(90deg,#1f6bff,#00c8ff);--glow:rgba(138,92,255,.5);color:var(--tx)}
html[data-theme="light"] .upload-wrap{--card:linear-gradient(165deg,#fff,#f6f4ff);--solid:#fff;--line:rgba(124,77,255,.28);--line2:rgba(255,42,50,.65);--tx:#0b1030;--mut:#55608f;--faint:#7a84ad;--inp:#fff;--chip:rgba(124,77,255,.1);--glow:rgba(124,77,255,.28)}
.upload-wrap [hidden]{display:none!important}
.upload-wrap *{box-sizing:border-box}

/* heading + premium banner */
.upload-heading{display:flex;align-items:center;gap:18px;margin:0 0 20px}
.upload-heading-icon{flex:0 0 auto;color:#12b8ff;filter:drop-shadow(0 0 14px rgba(0,200,255,.6))}
.upload-heading-icon svg{display:block;width:96px;height:80px}
.upload-heading h1{margin:0;font-size:clamp(34px,3.8vw,50px);font-weight:800;letter-spacing:-.025em;line-height:1.05;color:var(--tx)}
.upload-wrap .sub{margin:6px 0 0;color:var(--mut);font-size:clamp(15px,1.5vw,19px)}
.up-premium{margin-left:auto;display:flex;align-items:center;gap:16px;width:min(100%,540px);padding:14px 18px;border:1px solid rgba(255,46,147,.55);border-radius:18px;background:linear-gradient(100deg,rgba(255,46,147,.6),rgba(138,92,255,.6) 55%,rgba(40,28,110,.75));color:#fff;box-shadow:0 0 34px -8px rgba(255,46,147,.65)}
.up-premium .crown{flex:0 0 auto;width:62px;height:52px;filter:drop-shadow(0 0 10px rgba(255,201,51,.6))}
.up-premium .t{flex:1;min-width:0;display:grid;gap:3px}
.up-premium .t strong{font-size:23px;color:#ffc933;line-height:1.1}
.up-premium .t span{font-size:14px;color:#fff}
.up-premium .go{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;padding:11px 20px;border-radius:999px;background:var(--grad-blue);color:#fff;font-size:15px;font-weight:700;white-space:nowrap;box-shadow:0 0 18px -2px rgba(0,200,255,.6)}
.up-premium .go svg{width:17px;height:17px}

/* layout */
.upload-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,340px);gap:20px;align-items:start}
.up-card,.upload-side-card{position:relative;overflow:hidden;padding:20px 22px 22px;border:1px solid var(--line);border-radius:20px;background:var(--card)}
.up-title{margin:0 0 12px;font-size:17px;font-weight:700}
.up-label{display:block;margin:0 0 9px;font-size:16px;font-weight:700;color:var(--tx)}
.up-label small{font-weight:500;font-size:13px;color:var(--mut)}
.up-label svg{width:15px;height:15px;vertical-align:-2px;margin-right:6px}
.required-mark{color:var(--pink)}
.up-field{margin-bottom:6px}

/* inputs */
.upload-wrap .up-input,.upload-wrap .up-textarea,.upload-wrap .up-select{display:block;width:100%;min-height:46px;padding:0 16px;border:1px solid var(--line);border-radius:13px;background:var(--inp);color:var(--tx);font-size:15px;outline:none;transition:border-color .16s,box-shadow .16s}
.upload-wrap .up-textarea{min-height:92px;padding:12px 16px;resize:vertical;line-height:1.5}
.upload-wrap .up-input::placeholder,.upload-wrap .up-textarea::placeholder{color:var(--faint)}
.upload-wrap .up-input:focus,.upload-wrap .up-textarea:focus,.upload-wrap .up-select:focus{border-color:var(--cyan);box-shadow:0 0 0 3px rgba(255,42,50,.18),0 0 18px -4px rgba(255,42,50,.5)}
.upload-wrap input[type=datetime-local]{color-scheme:dark}
html[data-theme="light"] .upload-wrap input[type=datetime-local]{color-scheme:light}
.up-count{margin:5px 0 12px;text-align:right;font-size:12px;color:var(--faint)}
.up-ic{position:relative}
.up-ic>svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:18px;height:18px;color:var(--mut);pointer-events:none}
.up-category-icon{position:absolute;left:14px;top:50%;display:grid;width:18px;height:18px;place-items:center;transform:translateY(-50%);color:var(--mut);pointer-events:none}
.up-category-icon svg,.up-category-icon img{display:block;width:18px;height:18px;object-fit:contain}
.upload-wrap .up-ic .up-input,.upload-wrap .up-ic .up-select{padding-left:44px}
.upload-wrap .up-select{appearance:none;-webkit-appearance:none;padding-right:40px;cursor:pointer}
.upload-wrap .up-select option{background:#0a0d1f;color:#f2f5ff}
.up-sel::after{content:"";position:absolute;right:17px;top:50%;width:7px;height:7px;margin-top:-6px;border:solid var(--tx);border-width:0 2px 2px 0;transform:rotate(45deg);pointer-events:none}

/* pills (type + mode) */
.up-pills{position:relative;display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}
.up-pills input{position:absolute;opacity:0;pointer-events:none}
.up-pills label{display:inline-flex;align-items:center;justify-content:center;gap:9px;min-height:44px;padding:0 26px;border:1px solid transparent;border-radius:999px;background:var(--chip);color:var(--tx);font-size:16px;font-weight:600;cursor:pointer;transition:background .16s,border-color .16s,box-shadow .16s}
.up-pills label svg{width:20px;height:20px}
.up-pills label:hover{border-color:var(--line2)}
.up-pills input:checked+label{border-color:transparent;background:var(--grad-blue);color:#fff;box-shadow:0 0 22px -2px rgba(0,200,255,.65)}
.up-pills input:focus-visible+label{outline:2px solid #fff;outline-offset:2px}
.up-pills.wide{margin-bottom:0}

/* single-column form body */
.up-grid{display:grid;grid-template-columns:1fr;gap:16px}
.up-span{grid-column:1/-1}
#categoryField{display:flex;align-items:center;gap:16px}
#categoryField .up-label{margin:0;flex:0 0 auto}
#categoryField .up-ic{flex:1;min-width:0}

/* dropzone */
.upload-dropzone{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:136px;padding:18px;border:2px dashed var(--cyan);border-radius:16px;background:radial-gradient(ellipse at 50% 130%,rgba(138,92,255,.28),transparent 62%),var(--inp);box-shadow:0 0 0 1px rgba(138,92,255,.45),0 0 26px -8px rgba(255,42,50,.55);text-align:center;cursor:pointer}
.upload-dropzone:hover,.upload-dropzone.is-dragging{border-color:#fff;box-shadow:0 0 0 1px var(--violet),0 0 32px -4px var(--cyan)}
.upload-dropzone svg{width:46px;height:46px;color:#18b8ff;filter:drop-shadow(0 0 10px rgba(0,200,255,.7))}
.upload-dropzone strong{font-size:18px;word-break:break-word}
.upload-dropzone span{font-size:14px;color:var(--mut)}
.upload-dropzone small{font-size:12.5px;color:var(--mut)}
.upload-dropzone input[type=file]{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}

/* thumbnail row */
.up-thumb{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.up-thumb-box{position:relative;flex:0 0 46px;width:46px;height:42px;overflow:hidden;border:1px solid var(--line);border-radius:10px;background:var(--inp);color:var(--mut);cursor:pointer;display:grid;place-items:center}
.up-thumb-box img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.up-thumb-box>svg{width:24px;height:24px}
.up-ghost{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:0 20px;border:1px solid var(--line);border-radius:10px;background:var(--chip);color:var(--tx);font-size:15px;font-weight:600;cursor:pointer}
.up-ghost:hover{border-color:var(--line2)}
.up-ghost svg{display:none}
.up-filename{font-size:15px;color:var(--mut);min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.up-hide{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden}
.up-sched{max-width:340px}

/* live fields */
.up-livebox{display:grid;gap:10px;padding:16px;border:1px solid var(--line);border-radius:16px;background:rgba(138,92,255,.08)}
.up-hint{font-size:12.5px;color:var(--mut);line-height:1.45}

/* publish */
.upload-actions{display:flex;flex-direction:column;align-items:stretch;margin-top:20px}
.up-submit{display:inline-flex;align-items:center;justify-content:center;gap:14px;width:100%;min-height:56px;padding:0 30px;border:0;border-radius:14px;background:var(--grad);color:#fff;font-size:24px;font-weight:700;cursor:pointer;box-shadow:0 0 30px -4px rgba(138,92,255,.7),inset 0 1px 0 rgba(255,255,255,.25);transition:transform .16s,filter .16s}
.up-submit svg{width:30px;height:30px}
.up-submit:hover:not(:disabled){transform:translateY(-1px);filter:brightness(1.1)}
.up-submit:disabled{opacity:.5;cursor:not-allowed}
.upload-actions .upload-draft-status{margin:12px 0 0;padding:0;border:0;background:transparent;color:var(--mut);font-size:12.5px;text-align:center}
.upload-actions .upload-draft-status.error{color:#ff8aa6}
.up-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:6px 14px;margin-top:6px;font-size:13px;color:var(--mut)}
.up-meta a{color:var(--cyan);text-decoration:underline}
.up-clear{border:0;background:transparent;color:var(--mut);font-size:13px;text-decoration:underline;cursor:pointer}

/* side column */
.upload-side{display:grid;gap:16px;min-width:0}
.upload-side-card{padding:18px}
.upload-side-card h2{display:flex;align-items:center;gap:10px;margin:0 0 12px;font-size:22px;font-weight:700;color:var(--tx)}
.upload-side-card h2 svg{width:26px;height:26px}
.up-live h2 svg{color:#ff3b6b}
.up-scene{display:block;width:100%;height:auto;margin:0 0 12px;border-radius:12px}
.up-live p{margin:0 0 14px;text-align:center;color:var(--tx);font-size:15px;line-height:1.5}
.up-golive{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;min-height:50px;border:0;border-radius:14px;background:var(--grad);color:#fff;font-size:20px;font-weight:700;cursor:pointer;box-shadow:0 0 24px -6px rgba(255,46,147,.7)}
.up-golive svg{width:22px;height:22px}
.up-golive:hover{filter:brightness(1.1)}
.up-tips h2 svg{color:#ffd24a}
.up-tips ul{display:grid;gap:11px;margin:4px 0 0;padding:0;list-style:none}
.up-tips li{display:flex;align-items:center;gap:12px;font-size:15.5px}
.up-tips li::before{content:"\2713";display:grid;place-items:center;flex:0 0 24px;height:24px;border-radius:50%;background:#12c8ff;color:#06162e;font-size:14px;font-weight:900}
.upload-live-head{display:flex;align-items:center;justify-content:space-between;margin:0 0 6px}
.upload-side-card .upload-live-head h2{margin:0;font-size:21px}
.upload-live-head a{color:var(--cyan);font-size:14px;font-weight:600}
.upload-live-indicator{width:14px;height:14px;border-radius:50%;background:#ff3157;box-shadow:0 0 12px rgba(255,49,87,.8)}
.upload-live-card{display:grid;grid-template-columns:104px minmax(0,1fr);align-items:center;gap:12px;padding:8px 0;color:inherit}
.upload-live-thumb{position:relative;aspect-ratio:16/10;border-radius:9px;overflow:hidden;background:linear-gradient(135deg,#23205c,#7c3aed)}
.upload-live-thumb img{width:100%;height:100%;object-fit:cover;display:block}
.upload-live-status{position:absolute;right:4px;bottom:4px;border-radius:5px;padding:2px 6px;background:#e11d48;color:#fff;font-size:10px;font-weight:900}
.upload-live-status.scheduled{background:#7c3aed}
.upload-live-copy{min-width:0}
.upload-live-copy strong,.upload-live-copy span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.upload-live-copy strong{font-size:15px}
.upload-live-copy span{margin-top:4px;color:var(--mut);font-size:13px}

/* notices */
.notice{border-radius:14px;padding:12px 14px;font-size:14px;line-height:1.5;margin-bottom:16px;border:1px solid var(--line);background:rgba(138,92,255,.1)}
.notice.error{border-color:rgba(255,93,122,.6);background:rgba(255,93,122,.12)}
.notice.ok{border-color:rgba(255,42,50,.5)}
.notice.warn{border-color:rgba(230,170,60,.5);background:rgba(230,170,60,.1)}
.notice a{color:var(--cyan);text-decoration:underline}
.notice ul{margin:0;padding-left:18px}
.notice code,.notice pre{background:rgba(0,0,0,.3);border-radius:8px;padding:2px 6px;font-size:13px;overflow-wrap:anywhere}
.notice pre{display:block;padding:10px 12px;margin:8px 0 0;overflow-x:auto}

@media(max-width:1020px){.upload-layout{grid-template-columns:1fr}.upload-side{grid-template-columns:repeat(2,minmax(0,1fr))}.upload-live-section{grid-column:1/-1}}
@media(max-width:820px){.upload-heading{flex-wrap:wrap}.up-premium{margin-left:0;width:100%}}
@media(max-width:720px){.upload-side{grid-template-columns:1fr}#categoryField{flex-direction:column;align-items:stretch;gap:9px}}
@media(max-width:520px){.up-card{padding:16px 14px 18px}.upload-heading-icon svg{width:62px;height:52px}.up-pills label{padding:0 18px;flex:1 1 auto}.up-premium .go{padding:10px 14px}.up-submit{font-size:20px}}
@media(prefers-reduced-motion:reduce){.up-pills label,.up-submit{transition:none!important}}
/* ===== NEON THEME (dark + light) ===== */
html:not([data-theme="light"]) {
            --bg: #05060f; --surface: #0a0d1f; --surface-2: #121634; --surface-3: #171c42;
            --text: #eaf0ff; --muted: #8f9bd0; --border: #1d2557;
            --accent: #ff2a32; --accent-hover: #171c42; --accent-soft: rgba(255,46,147,.18); --blue: #ff2a32;
            --neon-cyan: #ff2a32; --neon-pink: #ff2a32; --neon-violet: #b50d16;
        }
        html:not([data-theme="light"]) body { background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.16), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(255,42,50,.10), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.08), transparent 70%), #05060f; background-attachment: fixed; }
        html:not([data-theme="light"]) body .app-navbar { background: rgba(5,6,15,.82); border-bottom: 1px solid rgba(255,42,50,.35); box-shadow: 0 1px 24px -4px rgba(255,42,50,.35), 0 8px 30px -12px rgba(138,92,255,.4); }
        html:not([data-theme="light"]) body .brand { background: linear-gradient(90deg, #ff2a32, #b50d16 55%, #ff2a32); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 8px rgba(138,92,255,.65)); }
        html:not([data-theme="light"]) body .nav-toggle span { background: #ff2a32; box-shadow: 0 0 6px rgba(255,42,50,.7); }
        html:not([data-theme="light"]) body .nav-toggle:hover, html:not([data-theme="light"]) body .theme-toggle:hover { background: rgba(255,42,50,.14); box-shadow: 0 0 14px rgba(255,42,50,.4); }
        html:not([data-theme="light"]) body .theme-toggle svg { color: #ffe14d; filter: drop-shadow(0 0 5px rgba(138,92,255,.6)); }
        html:not([data-theme="light"]) body .nav-link.active { background: rgba(255,46,147,.16); color: #ff7ac0; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html:not([data-theme="light"]) body .create-btn, html:not([data-theme="light"]) body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body #search-input { background: rgba(10,13,31,.9); border: 1px solid rgba(138,92,255,.55); color: #eaf0ff; box-shadow: 0 0 10px -2px rgba(138,92,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html:not([data-theme="light"]) body #search-input:focus { outline: none; border-color: #ff2a32; box-shadow: 0 0 0 1px #ff2a32, 0 0 20px rgba(255,42,50,.55); }
        html:not([data-theme="light"]) body .user-badge-pill { background: linear-gradient(180deg, rgba(23,28,66,.9), rgba(10,13,31,.95)); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html:not([data-theme="light"]) body .logout-link:hover { color: #ff2a32; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html:not([data-theme="light"]) body .app-sidebar { background: linear-gradient(180deg, rgba(10,13,31,.97), rgba(5,6,15,.98)); border-right: 1px solid rgba(138,92,255,.4); box-shadow: 4px 0 28px -10px rgba(138,92,255,.45); }
        html:not([data-theme="light"]) body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html:not([data-theme="light"]) body .sidebar-link:hover { background: rgba(255,42,50,.10); box-shadow: inset 3px 0 0 #ff2a32, 0 0 16px -4px rgba(255,42,50,.5); }
        html:not([data-theme="light"]) body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.22), rgba(138,92,255,.12)); color: #fff; box-shadow: inset 3px 0 0 #ff2a32, 0 0 18px -4px rgba(255,46,147,.55); }
        html:not([data-theme="light"]) body .sidebar-heading { color: #ff2a32; text-shadow: 0 0 8px rgba(255,42,50,.45); }
        html:not([data-theme="light"]) body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-track { background: #05060f; }
        html:not([data-theme="light"]) body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #b50d16, #ff2a32); border-radius: 8px; }
        html:not([data-theme="light"]) body ::selection { background: rgba(255,46,147,.4); color: #fff; }
html[data-theme="light"] {
            --bg: #f4f6ff; --surface: #ffffff; --surface-2: #eceffd; --surface-3: #e3e8fc;
            --text: #0b1030; --muted: #55608f; --border: #cfd6f7;
            --accent: #ff2a32; --accent-hover: #e3e8fc; --accent-soft: rgba(255,46,147,.12); --blue: #ff2a32;
            --neon-cyan: #ff2a32; --neon-pink: #ff2a32; --neon-violet: #b50d16;
        }
        html[data-theme="light"] body { background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.14), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(255,42,50,.14), transparent 70%),
                radial-gradient(700px 500px at 50% 110%, rgba(255,46,147,.10), transparent 70%), #f4f6ff; background-attachment: fixed; }
        html[data-theme="light"] body .app-navbar { background: rgba(255,255,255,.85); border-bottom: 1px solid rgba(255,42,50,.55); box-shadow: 0 1px 22px -4px rgba(255,42,50,.45), 0 8px 30px -12px rgba(124,77,255,.35); }
        html[data-theme="light"] body .brand { background: linear-gradient(90deg, #ff2a32, #b50d16 55%, #ff2a32); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; color: transparent; filter: drop-shadow(0 0 6px rgba(124,77,255,.45)); }
        html[data-theme="light"] body .nav-toggle span { background: #b50d16; box-shadow: 0 0 6px rgba(124,77,255,.7); }
        html[data-theme="light"] body .nav-toggle:hover, html[data-theme="light"] body .theme-toggle:hover { background: rgba(255,42,50,.14); box-shadow: 0 0 14px rgba(255,42,50,.4); }
        html[data-theme="light"] body .theme-toggle svg { color: #b50d16; filter: drop-shadow(0 0 5px rgba(124,77,255,.6)); }
        html[data-theme="light"] body .nav-link.active { background: rgba(255,46,147,.16); color: #d4147a; border-color: rgba(255,46,147,.55); box-shadow: 0 0 12px -2px rgba(255,46,147,.5); }
        html[data-theme="light"] body .create-btn, html[data-theme="light"] body .sign-in-btn { box-shadow: 0 0 16px -2px rgba(255,46,147,.55); }
        html[data-theme="light"] body #search-input { background: #ffffff; border: 1px solid rgba(124,77,255,.55); color: #0b1030; box-shadow: 0 0 10px -2px rgba(124,77,255,.45); transition: box-shadow .2s ease, border-color .2s ease; }
        html[data-theme="light"] body #search-input:focus { outline: none; border-color: #ff2a32; box-shadow: 0 0 0 1px #ff2a32, 0 0 20px rgba(255,42,50,.55); }
        html[data-theme="light"] body .user-badge-pill { background: linear-gradient(180deg, #ffffff, #f1f3ff); border: 1px solid rgba(255,46,147,.55); box-shadow: 0 0 14px -3px rgba(255,46,147,.45); }
        html[data-theme="light"] body .logout-link:hover { color: #ff2a32; text-shadow: 0 0 10px rgba(255,46,147,.7); }
        html[data-theme="light"] body .app-sidebar { background: linear-gradient(180deg, #ffffff, #f4f6ff); border-right: 1px solid rgba(124,77,255,.35); box-shadow: 4px 0 28px -10px rgba(124,77,255,.45); }
        html[data-theme="light"] body .sidebar-link { transition: background .15s ease, box-shadow .15s ease, color .15s ease; }
        html[data-theme="light"] body .sidebar-link:hover { background: rgba(255,42,50,.12); box-shadow: inset 3px 0 0 #ff2a32, 0 0 16px -4px rgba(255,42,50,.5); }
        html[data-theme="light"] body .sidebar-link.active { background: linear-gradient(90deg, rgba(255,46,147,.16), rgba(124,77,255,.12)); color: #0b1030; box-shadow: inset 3px 0 0 #ff2a32, 0 0 18px -4px rgba(255,46,147,.55); }
        html[data-theme="light"] body .sidebar-heading { color: #b50d16; text-shadow: 0 0 8px rgba(124,77,255,.45); }
        html[data-theme="light"] body ::-webkit-scrollbar { width: 10px; height: 10px; }
        html[data-theme="light"] body ::-webkit-scrollbar-track { background: #eceffd; }
        html[data-theme="light"] body ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #b50d16, #ff2a32); border-radius: 8px; }
        html[data-theme="light"] body ::selection { background: rgba(255,46,147,.4); color: #0b1030; }
html:not([data-theme="light"]) body .up-card,html:not([data-theme="light"]) body .upload-side-card{border-color:rgba(138,92,255,.5);box-shadow:0 0 30px -10px rgba(138,92,255,.55)}

CSS;

vs_page_start('Upload', 'upload', $extraCss);
?>
<div class="page upload-page">
    <div class="upload-wrap">
        <header class="upload-heading">
            <span class="upload-heading-icon" aria-hidden="true"><svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 46h-1a11 11 0 0 1-1.6-21.9A15 15 0 0 1 46.6 22 12 12 0 0 1 46 46h-2"/><path d="M32 52V30m0 0-8 8m8-8 8 8"/></svg></span>
            <div>
                <h1>Upload</h1>
                <p class="sub">Share your creativity with the world!</p>
            </div>
            <a class="up-premium" href="premium.php">
                <svg class="crown" viewBox="0 0 64 54" aria-hidden="true"><defs><linearGradient id="crownGold" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffe27a"/><stop offset="1" stop-color="#f29a0d"/></linearGradient></defs><path d="M6 44 2 14l16 14L32 6l14 22L62 14l-4 30z" fill="url(#crownGold)" stroke="#fff3b0" stroke-width="1.5" stroke-linejoin="round"/><rect x="8" y="46" width="48" height="7" rx="2.5" fill="url(#crownGold)"/><circle cx="2" cy="14" r="3" fill="#ffe27a"/><circle cx="32" cy="6" r="3.5" fill="#ffe27a"/><circle cx="62" cy="14" r="3" fill="#ffe27a"/></svg>
                <span class="t"><strong>Go Premium</strong><span>Get more features, grow faster!</span></span>
                <span class="go">Upgrade Now <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg></span>
            </a>
        </header>
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

        <form class="upload-form" method="post" enctype="multipart/form-data" id="uploadForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <div class="upload-layout">
            <section class="up-card upload-main" aria-label="Upload content">
                <h2 class="up-title">Video Type</h2>
                <div class="up-pills" role="radiogroup" aria-label="Upload type">
                    <input type="radio" name="type" id="typeVideo" value="video"<?= $old['type'] === 'video' ? ' checked' : '' ?>>
                    <label for="typeVideo"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M7 4.5v15l12.5-7.5z"/></svg>Video</label>
                    <input type="radio" name="type" id="typeShort" value="short"<?= $old['type'] === 'short' ? ' checked' : '' ?>>
                    <label for="typeShort"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M13.5 2 4.5 14H10l-1 8 9.5-12.5H13z"/></svg>Short</label>
                    <input type="radio" name="type" id="typeLive" value="live"<?= $old['type'] === 'live' ? ' checked' : '' ?>>
                    <label for="typeLive"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M8 8a5.7 5.7 0 0 0 0 8m8-8a5.7 5.7 0 0 1 0 8M5 5a10 10 0 0 0 0 14m14-14a10 10 0 0 1 0 14"/></svg>Live</label>
                </div>

                <div class="up-field">
                    <label class="up-label" for="title">Title <span class="required-mark">*</span></label>
                    <input class="up-input" type="text" id="title" name="title" maxlength="150" required value="<?= htmlspecialchars($old['title']) ?>" placeholder="Enter an awesome title...">
                    <div class="up-count"><span id="titleCount">0</span> / 150</div>
                </div>
                <div class="up-field" id="descriptionField">
                    <label class="up-label" for="description">Description</label>
                    <textarea class="up-textarea" id="description" name="description" maxlength="2000" rows="3" placeholder="Tell viewers about your video..."><?= htmlspecialchars($old['description']) ?></textarea>
                    <div class="up-count"><span id="descCount">0</span> / 2000</div>
                </div>

                <div class="up-grid">
                    <div id="modeField">
                        <span class="up-label">Upload Mode</span>
                        <div class="up-pills wide" role="radiogroup" aria-label="Video source">
                            <input type="radio" name="mode" id="modeFile" value="file"<?= $old['mode'] === 'file' ? ' checked' : '' ?>>
                            <label for="modeFile"><svg viewBox="0 0 48 48" fill="none"><path d="M24 32V10m0 0L14 20m10-10 10 10M9 31v7a3 3 0 0 0 3 3h24a3 3 0 0 0 3-3v-7" stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Upload File</label>
                            <input type="radio" name="mode" id="modeLink" value="link"<?= $old['mode'] === 'link' ? ' checked' : '' ?>>
                            <label for="modeLink"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>Add Link</label>
                        </div>
                    </div>
                    <?php if ($showCategory): ?>
                    <div id="categoryField">
                        <label class="up-label" for="category">Category</label>
                        <div class="up-ic up-sel">
                            <span class="up-category-icon" id="categoryIcon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z"/></svg></span>
                            <select class="up-select" id="category" name="category" required>
                                <option value="">Select a category</option>
                                <?php foreach ($categoryChoices as $choice):
                                    $cv = strtolower(trim((string)$choice));
                                    $categoryIconUrl = vs_cat_icon_url((string)$choice);
                                    $categoryIconSvg = $categoryIconUrl === null ? vs_category_icon_svg($cv, 18) : '';
                                ?>
                                    <option value="<?= htmlspecialchars($cv, ENT_QUOTES, 'UTF-8') ?>" data-icon-url="<?= htmlspecialchars((string)$categoryIconUrl, ENT_QUOTES, 'UTF-8') ?>" data-icon-svg="<?= htmlspecialchars($categoryIconSvg, ENT_QUOTES, 'UTF-8') ?>"<?= $old['category'] === $cv ? ' selected' : '' ?>><?= htmlspecialchars($choice, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php else: ?><div id="categoryField"></div><?php endif; ?>

                    <div id="fileField">
                        <label class="upload-dropzone" for="video_file">
                            <svg viewBox="0 0 48 48" fill="none" aria-hidden="true"><path d="M13 35h-1a8 8 0 0 1-1.2-15.9A11 11 0 0 1 32.4 17 9 9 0 0 1 35 35h-1" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/><path d="M24 40V24m0 0-6 6m6-6 6 6" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <strong id="uploadFilePrompt">Drag &amp; Drop your video here</strong>
                            <span>or click to browse</span>
                            <small>Supported formats: MP4, WebM, MOV &middot; Max size: <?= htmlspecialchars(vs_format_bytes($maxBytes), ENT_QUOTES, 'UTF-8') ?></small>
                            <input type="file" id="video_file" name="video_file" accept="video/mp4,video/webm,video/ogg,video/quicktime,.mp4,.webm,.ogg,.ogv,.m4v,.mov">
                        </label>
                    </div>
                    <div id="linkField">
                        <label class="up-label" for="video_url">Video URL</label>
                        <div class="up-ic">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10 14a4.5 4.5 0 0 0 6.4 0l3-3a4.5 4.5 0 0 0-6.4-6.4l-1 1M14 10a4.5 4.5 0 0 0-6.4 0l-3 3a4.5 4.5 0 0 0 6.4 6.4l1-1"/></svg>
                            <input class="up-input" type="text" id="video_url" name="video_url" placeholder="Paste a YouTube link or a direct video link..." value="<?= htmlspecialchars($old['video_url']) ?>">
                        </div>
                    </div>

                    <div class="up-span" id="liveFields"<?= $old['type'] === 'live' ? '' : ' hidden' ?>>
                        <div class="up-livebox">
                            <label class="up-label" for="stream_url">Stream or YouTube Live link</label>
                            <input class="up-input" type="url" id="stream_url" name="stream_url" placeholder="https://www.youtube.com/live/..." value="<?= htmlspecialchars($old['stream_url']) ?>">
                            <label class="up-label" for="thumbnail_url">Thumbnail URL <small>(Optional)</small></label>
                            <input class="up-input" type="url" id="thumbnail_url" name="thumbnail_url" placeholder="Optional image URL" value="<?= htmlspecialchars($old['thumbnail_url']) ?>">
                            <div class="up-hint" id="urlTypeHint">Add a YouTube Live, HLS, MP4, WebM, or embed URL.</div>
                        </div>
                    </div>

                </div>

                <div class="upload-actions">
                    <button type="submit" class="up-submit" id="submitBtn"<?= ($srcCol === null || !$uploadsOn) ? ' disabled' : '' ?>><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M21.6 2.4a1 1 0 0 0-1.05-.2L2.7 9.6a1 1 0 0 0 .08 1.9l6.1 2 2 6.1a1 1 0 0 0 1.9.08l7.4-17.8a1 1 0 0 0-.58-1.48zM10.2 12.4l7.1-4.9-5 6.3z"/></svg><span id="submitLabel">Publish</span></button>
                    <div class="notice upload-draft-status" id="draftStatus" role="status" aria-live="polite">Upload details are saved in this browser. Video files over 16 MB are not cached; keep this page open or reselect the file if you return.</div>
                    <div class="up-meta">
                        <button type="button" class="up-clear" id="clearDraftBtn">Clear saved draft</button>
                        <?php if ($username !== ''): ?>
                            <span>Posting as @<?= htmlspecialchars($username) ?></span>
                        <?php else: ?>
                            <span><a href="login.php">Sign in</a> so this upload shows on your profile.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <aside class="upload-side" aria-label="Upload resources">
                <section class="upload-side-card up-live">
                    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M8 8a5.7 5.7 0 0 0 0 8m8-8a5.7 5.7 0 0 1 0 8M5 5a10 10 0 0 0 0 14m14-14a10 10 0 0 1 0 14"/></svg>Go Live</h2>
                    <svg class="up-scene" viewBox="0 0 320 150" aria-hidden="true">
                        <defs><linearGradient id="sceneBg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3a1a8c"/><stop offset="1" stop-color="#0b1a52"/></linearGradient></defs>
                        <rect width="320" height="150" rx="12" fill="url(#sceneBg)"/>
                        <path d="M246 24l62-12M258 42l52-6" stroke="#ff2a32" stroke-width="3" stroke-linecap="round" opacity=".85"/>
                        <rect x="100" y="42" width="130" height="78" rx="8" fill="#101a46" stroke="#ff2a32" stroke-width="2"/>
                        <path d="M150 62l32 18-32 18z" fill="#6d9cff"/>
                        <rect x="150" y="28" width="46" height="20" rx="5" fill="#e5173f"/>
                        <text x="173" y="43" text-anchor="middle" font-size="13" font-weight="800" fill="#fff" font-family="Arial,sans-serif">LIVE</text>
                        <rect x="160" y="120" width="26" height="8" fill="#1a2260"/><rect x="146" y="128" width="54" height="5" rx="2" fill="#1a2260"/>
                        <rect x="22" y="58" width="52" height="30" rx="5" fill="#1b1f3c" stroke="#b50d16" stroke-width="2"/><circle cx="82" cy="73" r="10" fill="#0b0f26" stroke="#ff2a32" stroke-width="2"/>
                        <path d="M48 88l-14 44M48 88l14 44M48 88v44" stroke="#b50d16" stroke-width="3" stroke-linecap="round"/>
                        <circle cx="268" cy="72" r="16" fill="#241a5c"/><path d="M242 132c0-22 12-36 26-36s26 14 26 36z" fill="#241a5c"/><path d="M254 66a14 14 0 0 1 28 0" stroke="#ff2a32" stroke-width="4" fill="none" stroke-linecap="round"/>
                    </svg>
                    <p>Start a live stream and connect with your audience!</p>
                    <button type="button" class="up-golive" id="goLiveBtn"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M8 8a5.7 5.7 0 0 0 0 8m8-8a5.7 5.7 0 0 1 0 8M5 5a10 10 0 0 0 0 14m14-14a10 10 0 0 1 0 14"/></svg>Go Live</button>
                </section>
                <section class="upload-side-card up-tips">
                    <h2><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a7 7 0 0 0-4 12.7V17h8v-2.3A7 7 0 0 0 12 2zm-3 17h6v2H9z"/></svg>Upload Tips</h2>
                    <ul>
                        <li>Use a catchy title</li>
                        <li>Add a detailed description</li>
                        <li>Choose the right category</li>
                        <li>Follow our community guidelines</li>
                    </ul>
                </section>
            <section class="upload-live-section upload-side-card" id="uploadLiveSection" aria-label="Live streams"<?= empty($uploadLiveStreams) ? ' style="display:none"' : '' ?>>
                <div class="upload-live-head">
                    <h2><i class="upload-live-indicator"></i>Live now</h2>
                    <a href="live.php">See all <span aria-hidden="true">→</span></a>
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
            </aside>
            </div>
        </form>
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
    var liveFields = document.getElementById('liveFields');
    var modes = document.querySelectorAll('input[name="mode"]');
    function syncMode() {
        var type = form ? form.querySelector('input[name="type"]:checked')?.value : 'video';
        var isLive = type === 'live';
        var modeValue = form ? (form.querySelector('input[name="mode"]:checked')?.value || 'file') : 'file';
        // live: only the live fields; otherwise show the file box OR the link box, depending on the chosen mode
        var visible = {
            modeField: !isLive,
            categoryField: !isLive,
            fileField: !isLive && modeValue === 'file',
            linkField: !isLive && modeValue === 'link'
        };
        Object.keys(visible).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.hidden = !visible[id];
        });
        liveFields.hidden = !isLive;
        var cat = document.getElementById('category');
        if (cat) cat.required = !isLive;
    }
    modes.forEach(function (m) { m.addEventListener('change', syncMode); });
    document.querySelectorAll('input[name="type"]').forEach(function (radio) { radio.addEventListener('change', syncMode); });
    document.querySelectorAll('input[name="live_status"]').forEach(function (radio) { radio.addEventListener('change', syncMode); });

    var form = document.getElementById('uploadForm');
    var btn = document.getElementById('submitBtn');
    var submitLabel = document.getElementById('submitLabel') || btn;
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
    var categorySelect = document.getElementById('category');
    var categoryIcon = document.getElementById('categoryIcon');
    function syncCategoryIcon() {
        if (!categorySelect || !categoryIcon) return;
        while (categoryIcon.firstChild) categoryIcon.removeChild(categoryIcon.firstChild);
        var option = categorySelect.options[categorySelect.selectedIndex];
        var iconUrl = option && option.getAttribute('data-icon-url');
        var iconSvg = option && option.getAttribute('data-icon-svg');
        if (iconUrl) {
            var image = document.createElement('img');
            image.src = iconUrl;
            image.alt = '';
            categoryIcon.appendChild(image);
        } else if (iconSvg) {
            var iconMarkup = document.createElement('span');
            iconMarkup.innerHTML = iconSvg;
            var svg = iconMarkup.querySelector('svg');
            if (svg) categoryIcon.appendChild(svg);
        } else {
            var fallback = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            fallback.setAttribute('viewBox', '0 0 24 24');
            fallback.setAttribute('fill', 'currentColor');
            var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', 'M3 3h8v8H3zm10 0h8v8h-8zM3 13h8v8H3zm10 0h8v8h-8z');
            fallback.appendChild(path);
            categoryIcon.appendChild(fallback);
        }
    }
    if (categorySelect) {
        categorySelect.addEventListener('change', syncCategoryIcon);
        syncCategoryIcon();
    }
    function syncUploadType() {
        var type = form.querySelector('input[name="type"]:checked')?.value || 'video';
        var label = type === 'short' ? 'Shorts' : (type === 'live' ? 'Live Stream' : 'Video');
        if (typeHeading) typeHeading.textContent = label;
        if (typeChip) typeChip.textContent = label;
        submitLabel.textContent = 'Publish';
        syncMode();
    }
    document.querySelectorAll('input[name="type"]').forEach(function (radio) { radio.addEventListener('change', syncUploadType); });
    syncMode();
    syncUploadType();

    function setUploadMode(mode) {
        var radio = document.getElementById(mode === 'file' ? 'modeFile' : 'modeLink');
        if (radio) radio.checked = true;
        syncMode();
    }
    fileInput.addEventListener('change', function () {
        setUploadMode('file');
        filePrompt.textContent = fileInput.files && fileInput.files[0] ? fileInput.files[0].name : 'Drag & Drop your video here';
        saveDraft(true);
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
        var selectedFile = fileInput.files && fileInput.files[0];
        return {
            type: type ? type.value : 'short',
            mode: mode ? mode.value : 'link',
            title: document.getElementById('title').value,
            video_url: document.getElementById('video_url').value,
            description: document.getElementById('description').value,
            live_status: document.querySelector('input[name="live_status"]:checked')?.value || 'live',
            stream_url: document.getElementById('stream_url').value,
            thumbnail_url: document.getElementById('thumbnail_url').value,
            scheduled_at: document.getElementById('scheduled_at')?.value || '',
            category: category ? category.value : '',
            file: selectedFile ? {
                name: selectedFile.name,
                size: selectedFile.size,
                lastModified: selectedFile.lastModified
            } : null
        };
    }
    function deleteDraftFile() {
        return withDraftStore('readwrite', function (store, resolve, reject) {
            var request = store.delete(draftKey);
            request.onsuccess = resolve;
            request.onerror = function () { reject(request.error || new Error('Draft file could not be deleted')); };
        });
    }
    function saveDraft(saveSelectedFile) {
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
        if (!saveSelectedFile) {
            setStatus('Draft details saved in this browser.');
            return Promise.resolve();
        }
        if (selected.size > 16 * 1024 * 1024) {
            return deleteDraftFile().then(function () {
                setStatus('Draft details saved. Large video files are not copied into browser storage; keep this page open or reselect the file if you return.');
            }).catch(function () {
                setStatus('Draft details saved, but the cached video could not be cleared. Keep this page open and reselect the file if you return.', true);
            });
        }
        withDraftStore('readwrite', function (store, resolve, reject) {
            var request = store.put({
                blob: selected,
                name: selected.name,
                type: selected.type,
                size: selected.size,
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
    function scheduleSave(saveSelectedFile) {
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function () { saveDraft(saveSelectedFile === true); }, 300);
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
        var scheduledAt = document.getElementById('scheduled_at');
        if (scheduledAt) scheduledAt.value = typeof saved.scheduled_at === 'string' ? saved.scheduled_at : '';
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
            var savedFile = saved.file;
            if (!savedFile || typeof savedFile.name !== 'string'
                || !Number.isFinite(Number(savedFile.size))
                || !Number.isFinite(Number(savedFile.lastModified))) {
                setStatus('Draft details restored. Select the video file again to retry this upload.');
                return;
            }
            withDraftStore('readonly', function (store, resolve, reject) {
                var request = store.get(draftKey);
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error || new Error('Saved file could not be read')); };
            }).then(function (record) {
                if (!record || !record.blob || record.name !== savedFile.name
                    || Number(record.size) !== Number(savedFile.size)
                    || Number(record.lastModified) !== Number(savedFile.lastModified)
                    || !window.DataTransfer || !window.File) {
                    setStatus('Draft details restored. Select the video file again to retry this upload.');
                    return;
                }
                var transfer = new DataTransfer();
                transfer.items.add(new File([record.blob], record.name, {
                    type: record.type || record.blob.type,
                    lastModified: record.lastModified || Date.now()
                }));
                fileInput.files = transfer.files;
                filePrompt.textContent = record.name || 'Drag & Drop your video here';
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
        if (event.target !== fileInput) scheduleSave();
    });
    clearDraftBtn.addEventListener('click', clearDraft);
    var submitting = false;
    form.addEventListener('submit', function (event) {
        if (submitting) return;
        event.preventDefault();
        submitting = true;
        btn.disabled = true;
        submitLabel.textContent = fileInput.files && fileInput.files[0] ? 'Uploading...' : 'Publishing...';
        window.clearTimeout(saveTimer);
        saveDraft(false);
        form.submit();
    });

    var titleInput = document.getElementById('title');
    var descInput = document.getElementById('description');
    function updateCounts() {
        document.getElementById('titleCount').textContent = titleInput.value.length;
        document.getElementById('descCount').textContent = descInput.value.length;
    }
    titleInput.addEventListener('input', updateCounts);
    descInput.addEventListener('input', updateCounts);
    updateCounts();

    document.getElementById('modeLink').addEventListener('change', function () { if (this.checked) urlInput.focus(); });
    document.getElementById('goLiveBtn').addEventListener('click', function () {
        var radio = document.getElementById('typeLive');
        radio.checked = true;
        radio.dispatchEvent(new Event('change', {bubbles: true}));
        window.scrollTo({top: 0, behavior: 'smooth'});
        titleInput.focus({preventScroll: true});
    });
});
JS;
$extraJs = str_replace('__DRAFT_KEY__', json_encode($draftKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $extraJs);
vs_page_end($extraJs);
if (isset($conn)) { $conn->close(); }