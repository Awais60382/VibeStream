<?php
/* Read-only data for the admin dashboard (index.php). Needs $pdo, $USERS_TABLE and the helpers from ui_helpers.php. */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
    http_response_code(403);
    exit('Administrator access required.');
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
require_once __DIR__ . '/ui_helpers.php';
require_once __DIR__ . '/admin-titles-store.php';

if (isset($pdo) && $pdo instanceof PDO) {
    $uploaderStats = vs_uploader_stats($pdo);
} else {
    $uploaderStats = [
        'rows'     => [],
        'totals'   => ['uploaders' => 0, 'videos' => 0, 'shorts' => 0, 'views' => 0],
        'has_date' => false,
        'error'    => 'No database connection found. Check that ../connection.php creates $pdo.',
    ];
}

$recentContent = [];
$recentContentError = '';
if (isset($pdo) && $pdo instanceof PDO) {
    foreach (['videos', 'shorts'] as $contentTable) {
        $contentCols = vs_cols($pdo, $contentTable);
        if (!$contentCols) {
            continue;
        }
        $titleCol = vs_pick($contentCols, ['title', 'name']);
        $dateCol = vs_pick($contentCols, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at']);
        $viewsCol = vs_pick($contentCols, ['views', 'view_count']);
        $authorCol = vs_pick($contentCols, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
        $typeCol = vs_pick($contentCols, ['type', 'video_type']);
        if (!$titleCol || !$dateCol) {
            continue;
        }
        try {
            $fields = [
                vs_q($titleCol) . ' AS content_title',
                vs_q($dateCol) . ' AS content_date',
                $viewsCol ? vs_q($viewsCol) . ' AS content_views' : '0 AS content_views',
                $authorCol ? vs_q($authorCol) . ' AS content_author' : "'' AS content_author",
                $typeCol ? vs_q($typeCol) . ' AS content_type' : "'' AS content_type",
            ];
            $query = 'SELECT ' . implode(', ', $fields) . ' FROM ' . vs_q($contentTable)
                   . ' ORDER BY ' . vs_q($dateCol) . ' DESC LIMIT 8';
            foreach ($pdo->query($query)->fetchAll(PDO::FETCH_ASSOC) as $content) {
                $type = $contentTable === 'shorts' || stripos((string)$content['content_type'], 'short') === 0
                    ? 'Short'
                    : 'Video';
                $recentContent[] = [
                    'title' => trim((string)$content['content_title']) ?: 'Untitled upload',
                    'date' => (string)$content['content_date'],
                    'views' => max(0, (int)$content['content_views']),
                    'author' => trim((string)$content['content_author']) ?: 'Unknown creator',
                    'type' => $type,
                ];
            }
        } catch (Throwable $e) {
            $recentContentError = 'Recent uploads could not be loaded.';
        }
    }
    usort($recentContent, static function (array $a, array $b): int {
        return (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0);
    });
    $recentContent = array_slice($recentContent, 0, 6);
}

$cardUsers  = null; 
$cardOnline = null; 
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $uc = vs_cols($pdo, $USERS_TABLE);
        if ($uc) {
            $cardUsers = (int)$pdo->query('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE))->fetchColumn();

            $lastCol = vs_pick($uc, ['last_active', 'last_seen', 'last_login']);
            if ($lastCol) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($lastCol) . ' >= :cut');
                $st->execute([':cut' => date('Y-m-d H:i:s', time() - 120)]);
                $cardOnline = (int)$st->fetchColumn();
            }
        }
    } catch (Throwable $e) {
    }
}
$cardPeopleLabel = $cardUsers !== null ? 'Users' : 'Uploaders';
$cardPeopleValue = $cardUsers !== null ? $cardUsers : (int)$uploaderStats['totals']['uploaders'];
$cardPeopleMeta  = $cardUsers !== null ? 'registered accounts' : 'names found on videos';

$ADMIN_ROLE   = 'Admin';          
$VIDEO_TABLES = ['videos', 'shorts'];  

$actDays = [];
for ($i = 6; $i >= 0; $i--) {
    $ts = strtotime("-$i days");
    $actDays[date('Y-m-d', $ts)] = [
        'label'  => date('D', $ts),
        'full'   => date('D, M j', $ts),
        'active' => ['user' => 0, 'admin' => 0],
        'upload' => ['user' => 0, 'admin' => 0],
    ];
}
$actMsg         = ['active' => '', 'upload' => ''];
$actAdminByName = []; 
if (isset($pdo) && $pdo instanceof PDO) {

    try {
        $actUc = vs_cols($pdo, $USERS_TABLE);
        if (!$actUc) {
            $actMsg['active'] = 'No "' . $USERS_TABLE . '" table was found, so activity cannot be shown.';
        } else {
            $cRole = vs_pick($actUc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
            $cUser = vs_pick($actUc, ['username', 'user_name', 'login', 'uname']);
            $cName = vs_pick($actUc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']);
            $cLast = vs_pick($actUc, ['last_active', 'last_seen', 'last_login']);

            $sel = [];
            if ($cRole) { $sel[] = vs_q($cRole) . ' AS f_role'; }
            if ($cUser) { $sel[] = vs_q($cUser) . ' AS f_username'; }
            if ($cName) { $sel[] = vs_q($cName) . ' AS f_name'; }
            if ($cLast) { $sel[] = vs_q($cLast) . ' AS f_last'; }

            if ($sel) {
                $stU   = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($USERS_TABLE) . ' LIMIT 5000');
                $rowsU = $stU ? $stU->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($rowsU as $ur) {
                    $isAdm = strcasecmp(trim((string)($ur['f_role'] ?? '')), $ADMIN_ROLE) === 0;
                    foreach (['f_username', 'f_name'] as $nk) {
                        $nm = mb_strtolower(trim((string)($ur[$nk] ?? '')));
                        if ($nm !== '') {
                            $actAdminByName[$nm] = $isAdm;
                        }
                    }
                    if (!empty($ur['f_last']) && ($lts = strtotime((string)$ur['f_last']))) {
                        $dk = date('Y-m-d', $lts);
                        if (isset($actDays[$dk])) {
                            $actDays[$dk]['active'][$isAdm ? 'admin' : 'user']++;
                        }
                    }
                }
            }
            if (!$cLast) {
                $actMsg['active'] = 'The "' . $USERS_TABLE . '" table has no last_active (or last_seen / last_login) column, so activity cannot be shown.';
            }
        }
    } catch (Throwable $e) {
        $actMsg['active'] = 'Could not read activity: ' . $e->getMessage();
    }

  
    try {
        $foundUploadTable = false;
        foreach ($VIDEO_TABLES as $vt) {
            $vc = vs_cols($pdo, $vt);
            if (!$vc) {
                continue;
            }
            $foundUploadTable = true;
            $cDate = vs_pick($vc, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'added_at', 'published_at', 'created_on', 'upload_time', 'date', 'created', 'timestamp']);
            $cWho  = vs_pick($vc, ['username', 'uploader', 'uploaded_by', 'user_name', 'author', 'owner', 'uploader_name', 'channel_name']);
            if (!$cDate) {
                $actMsg['upload'] = 'The "' . $vt . '" table has no date column (like created_at), so uploads cannot be placed on the chart.';
                continue;
            }
            $sql = 'SELECT ' . vs_q($cDate) . ' AS f_date' . ($cWho ? ', ' . vs_q($cWho) . ' AS f_who' : '')
                 . ' FROM ' . vs_q($vt) . ' ORDER BY ' . vs_q($cDate) . ' DESC LIMIT 20000';
            $stV   = $pdo->query($sql);
            $rowsV = $stV ? $stV->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rowsV as $vr) {
                $vts = strtotime((string)($vr['f_date'] ?? ''));
                if (!$vts) {
                    continue;
                }
                $dk = date('Y-m-d', $vts);
                if (!isset($actDays[$dk])) {
                    continue;
                }
                $who   = mb_strtolower(trim((string)($vr['f_who'] ?? '')));
                $isAdm = ($who !== '' && !empty($actAdminByName[$who]));
                $actDays[$dk]['upload'][$isAdm ? 'admin' : 'user']++;
            }
        }
        if (!$foundUploadTable) {
            $actMsg['upload'] = 'No videos or shorts table was found. Change $VIDEO_TABLES near the top of index.php.';
        }
    } catch (Throwable $e) {
        $actMsg['upload'] = 'Could not read uploads: ' . $e->getMessage();
    }
} else {
    $actMsg['active'] = $actMsg['upload'] = 'No database connection found.';
}

$trend = ['users' => null, 'videos' => null, 'shorts' => null];
$reportsTable = '';

if (!function_exists('vs_count_between')) {
    // Counts rows in $table whose date column is in [$from, $to). Returns null if it can't be worked out.
    function vs_count_between(PDO $pdo, string $table, array $dateCandidates, string $from, string $to): ?int
    {
        $c = vs_cols($pdo, $table);
        if (!$c) {
            return null;
        }
        $d = vs_pick($c, $dateCandidates);
        if (!$d) {
            return null;
        }
        $st = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($table) . ' WHERE ' . vs_q($d) . ' >= :a AND ' . vs_q($d) . ' < :b');
        $st->execute([':a' => $from, ':b' => $to]);
        return (int)$st->fetchColumn();
    }
}
if (!function_exists('vs_trend_pair')) {
    // Returns ['now' => n, 'prev' => n, 'pct' => float|null] for the last 7 days vs the 7 before.
    function vs_trend_pair(PDO $pdo, string $table, array $dateCandidates): ?array
    {
        $now  = date('Y-m-d H:i:s');
        $w1   = date('Y-m-d H:i:s', strtotime('-7 days'));
        $w2   = date('Y-m-d H:i:s', strtotime('-14 days'));
        $cur  = vs_count_between($pdo, $table, $dateCandidates, $w1, $now);
        $prev = vs_count_between($pdo, $table, $dateCandidates, $w2, $w1);
        if ($cur === null || $prev === null) {
            return null;
        }
        $pct = $prev > 0 ? round(($cur - $prev) / $prev * 100) : null;
        return ['now' => $cur, 'prev' => $prev, 'pct' => $pct];
    }
}
if (isset($pdo) && $pdo instanceof PDO) {
    $dateCols = ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at'];
    try {
        $trend['users']  = vs_trend_pair($pdo, $USERS_TABLE, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']);
        $trend['videos'] = vs_trend_pair($pdo, 'videos', $dateCols);
        $trend['shorts'] = vs_trend_pair($pdo, 'shorts', $dateCols);
    } catch (Throwable $e) {
        // trends are optional - never break the dashboard
    }
    foreach (['reports', 'reported', 'reported_content', 'content_reports', 'video_reports'] as $rt) {
        if (vs_cols($pdo, $rt)) { $reportsTable = $rt; break; }   // used by the activity feed
    }
}

/* ---------- Platform success chart: new signups + new uploads (7D / 30D / 90D / 1Y) ---------- */
$succDays = [];
for ($i = 364; $i >= 0; $i--) {
    $succDays[date('Y-m-d', strtotime("-$i days"))] = ['s' => 0, 'u' => 0];
}
$succSince = date('Y-m-d 00:00:00', strtotime('-364 days'));
$succOk = ['s' => false, 'u' => false];
if (isset($pdo) && $pdo instanceof PDO) {
    $succCollect = function (string $table, array $dateCandidates, string $key) use ($pdo, &$succDays, $succSince, &$succOk): void {
        try {
            $c = vs_cols($pdo, $table);
            if (!$c) {
                return;
            }
            $d = vs_pick($c, $dateCandidates);
            if (!$d) {
                return;
            }
            $st = $pdo->prepare('SELECT ' . vs_q($d) . ' FROM ' . vs_q($table) . ' WHERE ' . vs_q($d) . ' >= :since LIMIT 100000');
            $st->execute([':since' => $succSince]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $val) {
                $ts = strtotime((string)$val);
                if (!$ts) {
                    continue;
                }
                $dk = date('Y-m-d', $ts);
                if (isset($succDays[$dk])) {
                    $succDays[$dk][$key]++;
                }
            }
            $succOk[$key] = true;
        } catch (Throwable $e) {
            // chart is optional - never break the dashboard
        }
    };
    $succCollect($USERS_TABLE, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date'], 's');
    foreach (['videos', 'shorts'] as $succTbl) {
        $succCollect($succTbl, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'date'], 'u');
    }
}
/* ---------- Recent activity feed (new users, uploads, reports) ---------- */
$feed = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $feedUc = vs_cols($pdo, $USERS_TABLE);
        if ($feedUc) {
            $feedDate = vs_pick($feedUc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']);
            $feedName = vs_pick($feedUc, ['username', 'user_name', 'name', 'full_name', 'display_name', 'login']);
            if ($feedDate) {
                $fq = 'SELECT ' . vs_q($feedDate) . ' AS d' . ($feedName ? ', ' . vs_q($feedName) . ' AS n' : '')
                    . ' FROM ' . vs_q($USERS_TABLE) . ' ORDER BY ' . vs_q($feedDate) . ' DESC LIMIT 4';
                foreach ($pdo->query($fq)->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                    $feed[] = [
                        'icon'  => 'bi-person-plus',
                        'title' => 'New user registered',
                        'sub'   => trim((string)($fr['n'] ?? '')) ?: 'New member',
                        'date'  => (string)$fr['d'],
                    ];
                }
            }
        }
    } catch (Throwable $e) {
        // feed is optional
    }
    try {
        if ($reportsTable !== '') {
            $feedRc = vs_cols($pdo, $reportsTable);
            $feedRd = $feedRc ? vs_pick($feedRc, ['created_at', 'reported_at', 'created_on', 'date', 'timestamp']) : null;
            if ($feedRd) {
                $fq = 'SELECT ' . vs_q($feedRd) . ' AS d FROM ' . vs_q($reportsTable) . ' ORDER BY ' . vs_q($feedRd) . ' DESC LIMIT 3';
                foreach ($pdo->query($fq)->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                    $feed[] = ['icon' => 'bi-flag', 'title' => 'Content reported', 'sub' => 'Waiting in the reports queue', 'date' => (string)$fr['d']];
                }
            }
        }
    } catch (Throwable $e) {
        // feed is optional
    }
}
foreach ($recentContent as $feedItem) {
    $isShortItem = $feedItem['type'] === 'Short';
    $feed[] = [
        'icon'  => $isShortItem ? 'bi-lightning-charge' : 'bi-play-btn',
        'title' => ($isShortItem ? 'Short' : 'Video') . ' uploaded: ' . $feedItem['title'],
        'sub'   => 'By ' . $feedItem['author'],
        'date'  => $feedItem['date'],
    ];
}
usort($feed, static function (array $a, array $b): int {
    return (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0);
});
$feed = array_slice($feed, 0, 7);

/* ---------- Latest comments ---------- */
$COMMENT_TABLES = ['comments', 'video_comments', 'short_comments'];
$latestComments = [];
if (isset($pdo) && $pdo instanceof PDO) {
    foreach ($COMMENT_TABLES as $ct) {
        try {
            $cc = vs_cols($pdo, $ct);
            if (!$cc) { continue; }
            $cId   = vs_pick($cc, ['id', 'comment_id']);
            $cText = vs_pick($cc, ['comment', 'content', 'body', 'text', 'message', 'comment_text']);
            if (!$cId || !$cText) { continue; }
            $cWho  = vs_pick($cc, ['username', 'user_name', 'author', 'commenter', 'name']);
            $cDate = vs_pick($cc, ['created_at', 'commented_at', 'date_added', 'created_on', 'date', 'timestamp']);
            $sel = [vs_q($cText) . ' AS ctext', $cWho ? vs_q($cWho) . ' AS cwho' : "'' AS cwho", $cDate ? vs_q($cDate) . ' AS cdate' : "'' AS cdate"];
            $st = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($ct) . ' ORDER BY ' . ($cDate ? vs_q($cDate) : vs_q($cId)) . ' DESC LIMIT 4');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $latestComments[] = ['text' => trim((string)$cr['ctext']), 'who' => trim((string)$cr['cwho']) ?: 'Unknown user', 'date' => (string)$cr['cdate']];
            }
        } catch (Throwable $e) {
            // skip this table
        }
    }
    usort($latestComments, static function (array $a, array $b): int {
        return (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0);
    });
    $latestComments = array_slice($latestComments, 0, 4);
}

/* ---------- Disk space, admin team, failed uploads ---------- */
$health = ['disk' => null];
$dTotal = @disk_total_space(__DIR__);
$dFree  = @disk_free_space(__DIR__);
if ($dTotal && $dFree !== false) {
    $health['disk'] = ['total' => $dTotal, 'free' => $dFree, 'pct' => round(($dTotal - $dFree) / $dTotal * 100, 1)];
}
$teamRows = [];
$uploadFailureStats = ['available' => false, 'count7' => 0, 'items' => []];
if (isset($pdo) && $pdo instanceof PDO) {
    /* --- Admin team --- */
    try {
        $tc = vs_cols($pdo, $USERS_TABLE);
        if ($tc) {
            $tRole = vs_pick($tc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
            $tUser = vs_pick($tc, ['username', 'user_name', 'login', 'uname']);
            $tName = vs_pick($tc, ['name', 'full_name', 'fullname', 'display_name']);
            $tLast = vs_pick($tc, ['last_active', 'last_seen', 'last_login']);
            $tShow = $tUser ?: $tName;
            if ($tRole && $tShow) {
                $sel = vs_q($tShow) . ' AS n' . ($tLast ? ', ' . vs_q($tLast) . ' AS l' : '');
                $st = $pdo->prepare('SELECT ' . $sel . ' FROM ' . vs_q($USERS_TABLE) . ' WHERE LOWER(' . vs_q($tRole) . ') = :r'
                    . ($tLast ? ' ORDER BY ' . vs_q($tLast) . ' DESC' : '') . ' LIMIT 6');
                $st->execute([':r' => mb_strtolower($ADMIN_ROLE)]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                    $lts = !empty($tr['l']) ? strtotime((string)$tr['l']) : 0;
                    $teamRows[] = ['name' => trim((string)$tr['n']) ?: 'Admin', 'last' => (string)($tr['l'] ?? ''), 'online' => $lts && $lts >= time() - 120];
                }
            }
        }
    } catch (Throwable $e) {
        $teamRows = [];
    }

    /* --- Failed upload monitoring --- */
    try {
        $uploadFailureStats['available'] = vs_upload_failure_ensure($pdo);
        $failureCols = $uploadFailureStats['available'] ? vs_cols($pdo, 'upload_failure_events') : [];
        if ($failureCols) {
            $failureUsername = vs_pick($failureCols, ['username']);
            $failureType = vs_pick($failureCols, ['content_type']);
            $failureMode = vs_pick($failureCols, ['upload_mode']);
            $failureCode = vs_pick($failureCols, ['failure_code']);
            $failurePhpCode = vs_pick($failureCols, ['php_error_code']);
            $failureDate = vs_pick($failureCols, ['created_at']);
            if ($failureUsername && $failureType && $failureMode && $failureCode && $failurePhpCode && $failureDate) {
                $uploadFailureStats['available'] = true;
                $st = $pdo->prepare('SELECT COUNT(*) FROM `upload_failure_events` WHERE ' . vs_q($failureDate) . ' >= :since');
                $st->execute([':since' => date('Y-m-d H:i:s', strtotime('-7 days'))]);
                $uploadFailureStats['count7'] = (int)$st->fetchColumn();
                $st = $pdo->query(
                    'SELECT ' . vs_q($failureUsername) . ' AS username, '
                    . vs_q($failureType) . ' AS content_type, ' . vs_q($failureMode) . ' AS upload_mode, '
                    . vs_q($failureCode) . ' AS failure_code, ' . vs_q($failurePhpCode) . ' AS php_error_code, '
                    . vs_q($failureDate) . ' AS created_at FROM `upload_failure_events` ORDER BY '
                    . vs_q($failureDate) . ' DESC LIMIT 6'
                );
                $uploadFailureStats['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Throwable $e) {
        error_log('Dashboard upload failure data could not be read: ' . $e->getMessage());
        $uploadFailureStats = ['available' => false, 'count7' => 0, 'items' => []];
    }
}

/* ---------- Neon dashboard: extra data for the redesigned layout ---------- */
$vsdName  = trim((string)($_SESSION['AdminSession'] ?? '')) ?: 'Admin';
$vsdHour  = (int)date('G');
$vsdGreet = $vsdHour < 12 ? 'Good morning' : ($vsdHour < 18 ? 'Good afternoon' : 'Good evening');

// 1.2K / 12K / 1.4M style numbers
$vsdShort = static function (int $n): string {
    if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
    if ($n >= 10000)   { return number_format($n / 1000, 0) . 'K'; }
    if ($n >= 1000)    { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
    return (string)$n;
};

// Turns a ['now','prev','pct'] pair into an arrow + percent for the stat cards
$vsdTrend = static function (?array $t): ?array {
    if ($t === null) { return null; }
    $diff = (int)$t['now'] - (int)$t['prev'];
    $pct  = $t['pct'] ?? null;
    if ($diff === 0) { return ['cls' => 'flat', 'icon' => 'bi-dash-lg', 'text' => '0%']; }
    if ($pct === null) {
        $text = $diff > 0 ? '+' . $diff . ' new' : (string)$diff;
    } else {
        $text = ($diff > 0 ? '+' : '') . $pct . '%';
    }
    return ['cls' => $diff > 0 ? 'up' : 'down', 'icon' => $diff > 0 ? 'bi-arrow-up' : 'bi-arrow-down', 'text' => $text];
};

// Thumbnails are usually stored relative to the site root; the admin panel lives one folder deeper
$vsdThumb = static function (string $p): string {
    $p = trim($p);
    if ($p === '') { return ''; }
    if (preg_match('#^(https?:)?//#i', $p) || strpos($p, 'data:') === 0 || $p[0] === '/' || strpos($p, '../') === 0) {
        return $p;
    }
    return '../' . $p;
};

// Round avatar: initial letter underneath, picture on top (picture removes itself if it fails to load)
$vsdAvatar = static function (string $name, int $size = 36): string {
    $url = function_exists('avatar_url') ? (string)avatar_url($name) : '';
    $nm  = trim($name) !== '' ? trim($name) : '?';
    $ini = mb_strtoupper(mb_substr($nm, 0, 1));
    $h = '<span class="vsd-av" style="width:' . $size . 'px;height:' . $size . 'px"><b>' . vs_e($ini) . '</b>';
    if ($url !== '') {
        $h .= '<img src="' . vs_e($url) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    return $h . '</span>';
};

/* --- Total comments + 7 day trend --- */
$vsdComments      = null;
$vsdCommentsTrend = null;
if (isset($pdo) && $pdo instanceof PDO) {
    foreach ($COMMENT_TABLES as $vsdCt) {
        try {
            if (!vs_cols($pdo, $vsdCt)) { continue; }
            $vsdComments = (int)($vsdComments ?? 0) + (int)$pdo->query('SELECT COUNT(*) FROM ' . vs_q($vsdCt))->fetchColumn();
            $vsdTp = vs_trend_pair($pdo, $vsdCt, ['created_at', 'commented_at', 'date_added', 'created_on', 'date', 'timestamp']);
            if ($vsdTp !== null) {
                $vsdCommentsTrend = [
                    'now'  => (int)($vsdCommentsTrend['now'] ?? 0) + (int)$vsdTp['now'],
                    'prev' => (int)($vsdCommentsTrend['prev'] ?? 0) + (int)$vsdTp['prev'],
                ];
            }
        } catch (Throwable $e) {
            // optional card - never break the dashboard
        }
    }
    if ($vsdCommentsTrend !== null) {
        $vsdCommentsTrend['pct'] = $vsdCommentsTrend['prev'] > 0
            ? round(($vsdCommentsTrend['now'] - $vsdCommentsTrend['prev']) / $vsdCommentsTrend['prev'] * 100)
            : null;
    }
}

/* --- Platform growth: running totals at the end of each of the last 7 days --- */
$vsdTotalUsers   = $cardUsers !== null ? (int)$cardUsers : (int)$uploaderStats['totals']['uploaders'];
$vsdTotalUploads = (int)$uploaderStats['totals']['videos'] + (int)$uploaderStats['totals']['shorts'];
$vsdTotalViews   = (int)$uploaderStats['totals']['views'];

$vsdViewsByDay = []; // views of the content uploaded on each day
if (isset($pdo) && $pdo instanceof PDO) {
    $vsdSince = date('Y-m-d 00:00:00', strtotime('-6 days'));
    foreach (['videos', 'shorts'] as $vsdT) {
        try {
            $vc = vs_cols($pdo, $vsdT);
            if (!$vc) { continue; }
            $vd = vs_pick($vc, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'date']);
            $vv = vs_pick($vc, ['views', 'view_count']);
            if (!$vd || !$vv) { continue; }
            $vst = $pdo->prepare('SELECT DATE(' . vs_q($vd) . ') AS d, SUM(' . vs_q($vv) . ') AS v FROM ' . vs_q($vsdT)
                . ' WHERE ' . vs_q($vd) . ' >= :s GROUP BY DATE(' . vs_q($vd) . ')');
            $vst->execute([':s' => $vsdSince]);
            foreach ($vst->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                $dk = (string)$vr['d'];
                $vsdViewsByDay[$dk] = (int)($vsdViewsByDay[$dk] ?? 0) + (int)$vr['v'];
            }
        } catch (Throwable $e) {
            // chart still draws without this series moving
        }
    }
}
$vsdG       = ['labels' => [], 'users' => [], 'uploads' => [], 'views' => []];
$vsdDayKeys = array_slice(array_keys($succDays), -7);
$gcU = $vsdTotalUsers; $gcP = $vsdTotalUploads; $gcV = $vsdTotalViews;
$vsdRev = [];
for ($gi = count($vsdDayKeys) - 1; $gi >= 0; $gi--) {
    $gk = $vsdDayKeys[$gi];
    $vsdRev[] = ['l' => date('M j', strtotime($gk)), 'u' => max(0, $gcU), 'p' => max(0, $gcP), 'v' => max(0, $gcV)];
    $gcU -= (int)($succDays[$gk]['s'] ?? 0);
    $gcP -= (int)($succDays[$gk]['u'] ?? 0);
    $gcV -= (int)($vsdViewsByDay[$gk] ?? 0);
}
foreach (array_reverse($vsdRev) as $gr) {
    $vsdG['labels'][]  = $gr['l'];
    $vsdG['users'][]   = $gr['u'];
    $vsdG['uploads'][] = $gr['p'];
    $vsdG['views'][]   = $gr['v'];
}

/* --- Growth chart drawn here as SVG, so it shows even if the browser script fails --- */
$vsdChart = static function (array $g): string {
    $n = count($g['labels']);
    if ($n < 2) { return '<div class="vsd-empty">Not enough data yet.</div>'; }
    $W = 640; $H = 260; $L = 46; $R = 14; $T = 12; $B = 28;
    $iw = $W - $L - $R; $ih = $H - $T - $B; $base = $T + $ih;
    $series = [['users', 'Users', '#a855f7'], ['uploads', 'Uploads', '#3b82f6'], ['views', 'Views', '#22e58a']];
    $pct = []; $mx = 1.0;
    foreach ($series as $s) {
        $b = max(1, (int)$g[$s[0]][0]);
        foreach ($g[$s[0]] as $i => $v) {
            $pct[$s[0]][$i] = ((int)$v - (int)$g[$s[0]][0]) / $b * 100;
            $mx = max($mx, $pct[$s[0]][$i]);
        }
    }
    $mag = pow(10, floor(log10($mx))); $q = $mx / $mag;
    $top = ($q <= 1 ? 1 : ($q <= 2 ? 2 : ($q <= 5 ? 5 : 10))) * $mag;
    $f = static function ($x): string { return number_format((float)$x, 1, '.', ''); };
    $X = static function ($i) use ($L, $iw, $n) { return $L + $i / ($n - 1) * $iw; };
    $Y = static function ($v) use ($T, $ih, $top) { return $T + $ih - $v / $top * $ih; };

    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Growth of users, uploads and views over the last 7 days"><defs>';
    foreach ($series as $si => $s) {
        $o .= '<linearGradient id="vsdG' . $si . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="' . $s[2] . '" stop-opacity=".22"/><stop offset="100%" stop-color="' . $s[2] . '" stop-opacity="0"/></linearGradient>';
    }
    $o .= '</defs>';
    for ($t = 0; $t <= 4; $t++) {
        $v = $top * $t / 4; $gy = $Y($v);
        $o .= '<line class="gl" x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $f($gy) . '" y2="' . $f($gy) . '"/>';
        $o .= '<text class="ax" x="' . ($L - 8) . '" y="' . $f($gy + 4) . '" text-anchor="end">' . (round($v * 10) / 10) . '%</text>';
    }
    for ($i = 0; $i < $n; $i++) {
        $o .= '<text class="ax" x="' . $f($X($i)) . '" y="' . ($H - 8) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle')) . '">' . vs_e($g['labels'][$i]) . '</text>';
    }
    $o .= '<rect id="vsdGrBand" x="' . $L . '" y="' . $T . '" width="' . $f($iw / ($n - 1)) . '" height="' . $ih . '" rx="6" fill="#ffffff" fill-opacity="0" pointer-events="none"/>';
    foreach ($series as $si => $s) {
        $p = [];
        for ($i = 0; $i < $n; $i++) { $p[] = [$X($i), $Y($pct[$s[0]][$i])]; }
        $d = 'M' . $f($p[0][0]) . ',' . $f($p[0][1]);
        for ($i = 0; $i < $n - 1; $i++) {
            $p0 = $p[$i - 1] ?? $p[$i]; $p1 = $p[$i]; $p2 = $p[$i + 1]; $p3 = $p[$i + 2] ?? $p2;
            $c1y = min($base, max($T, $p1[1] + ($p2[1] - $p0[1]) / 6));
            $c2y = min($base, max($T, $p2[1] - ($p3[1] - $p1[1]) / 6));
            $d .= ' C' . $f($p1[0] + ($p2[0] - $p0[0]) / 6) . ',' . $f($c1y) . ' ' . $f($p2[0] - ($p3[0] - $p1[0]) / 6) . ',' . $f($c2y) . ' ' . $f($p2[0]) . ',' . $f($p2[1]);
        }
        $o .= '<path d="' . $d . ' L' . $f($p[$n - 1][0]) . ',' . $base . ' L' . $f($p[0][0]) . ',' . $base . ' Z" fill="url(#vsdG' . $si . ')"/>';
        $o .= '<path d="' . $d . '" fill="none" stroke="' . $s[2] . '" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>';
        for ($i = 0; $i < $n; $i++) {
            $o .= '<circle class="gpc" data-i="' . $i . '" cx="' . $f($p[$i][0]) . '" cy="' . $f($p[$i][1]) . '" r="4" fill="' . $s[2] . '" stroke="#050a24" stroke-width="1.2"/>';
        }
    }
    return $o . '</svg>';
};
$vsdChartHtml = $vsdChart($vsdG);

/* --- Top content (by views): id, thumbnail or video file, likes, link to the public page --- */
$vsdNames = static function ($cols): array {
    $out = [];
    foreach ((array)$cols as $k => $v) { $out[] = (string)(is_string($k) ? $k : $v); }
    return $out;
};
$vsdFind = static function (array $names, string $regex): ?string {
    foreach ($names as $n) { if (preg_match($regex, $n)) { return $n; } }
    return null;
};
// Finds a picture/video file on disk in the usual upload folders and returns a URL the admin page can use
$vsdResolve = static function (string $raw): string {
    $raw = trim($raw);
    if ($raw === '') { return ''; }
    if (preg_match('#^(https?:)?//#i', $raw) || strpos($raw, 'data:') === 0) { return $raw; }
    $clean = preg_replace('#^(\.\./)+#', '', ltrim(str_replace('\\', '/', $raw), '/'));
    $dirs = ['', 'uploads/', 'uploads/thumbnails/', 'uploads/thumbs/', 'uploads/thumbnail/', 'thumbnails/', 'thumbs/',
             'uploads/images/', 'images/', 'uploads/videos/', 'videos/', 'uploads/shorts/', 'shorts/',
             'assets/thumbnails/', 'uploads/covers/', 'covers/', 'uploads/posters/', 'uploads/video/', 'uploads/short/'];
    foreach ($dirs as $d) {
        if (is_file(__DIR__ . '/../' . $d . $clean)) { return '../' . $d . $clean; }
    }
    if (!empty($_SERVER['DOCUMENT_ROOT']) && is_file(rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') . '/' . $clean)) {
        return '/' . $clean;
    }
    return '';
};
$vsdYoutubeId = static function (string $raw): string {
    $raw = trim($raw);
    if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $raw)) {
        return $raw;
    }
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/))([a-zA-Z0-9_-]{11})~i', $raw, $match)) {
        return $match[1];
    }
    return '';
};
$vsdTop = [];
$vsdVideos = [];
$vsdShorts = [];
$vsdDebug = [];
if (isset($pdo) && $pdo instanceof PDO) {
    foreach (['videos', 'shorts'] as $vsdT) {
        try {
            $tc2 = vs_cols($pdo, $vsdT);
            if (!$tc2) { continue; }
            $nm2 = $vsdNames($tc2);
            $ti2 = vs_pick($tc2, ['title', 'name']);
            $vw2 = vs_pick($tc2, ['views', 'view_count']);
            $type2 = vs_pick($tc2, ['type', 'video_type']);
            if (!$ti2 || !$vw2) { continue; }
            $id2 = vs_pick($tc2, ['id', 'video_id', 'short_id']);
            $th2 = vs_pick($tc2, ['thumbnail', 'thumb', 'thumbnail_url', 'thumbnail_path', 'thumbnail_image', 'thumbnail_file', 'thumb_url', 'poster', 'poster_url', 'cover', 'cover_image', 'image', 'image_url', 'image_path', 'preview'])
                ?: $vsdFind($nm2, '/thumb|poster|cover|image|preview|banner/i');
            $fl2 = vs_pick($tc2, ['video_path', 'video_url', 'video_file', 'video_link', 'video', 'file_path', 'filename', 'file', 'path', 'url', 'src', 'media_url', 'media', 'source', 'youtube_url', 'youtube_id', 'yt_id'])
                ?: $vsdFind($nm2, '/^(video|file|media|src|path)/i');
            $yt2 = vs_pick($tc2, ['youtube_id', 'yt_id', 'youtube_url']);
            $vsdDebug[$vsdT] = ['columns' => $nm2, 'thumb_column' => $th2, 'file_column' => $fl2];
            $lk2 = vs_pick($tc2, ['likes', 'like_count', 'total_likes']);
            $sel2 = [
                vs_q($ti2) . ' AS t',
                vs_q($vw2) . ' AS v',
                $id2 ? vs_q($id2) . ' AS i' : "'' AS i",
                $th2 ? vs_q($th2) . ' AS th' : "'' AS th",
                $fl2 ? vs_q($fl2) . ' AS fl' : "'' AS fl",
                $yt2 ? vs_q($yt2) . ' AS yt' : "'' AS yt",
                $type2 ? vs_q($type2) . ' AS ct' : "'' AS ct",
                $lk2 ? vs_q($lk2) . ' AS lk' : 'NULL AS lk',
            ];
            $kinds = $vsdT === 'shorts' ? ['Short'] : ($type2 ? ['Video', 'Short'] : ['Video']);
            foreach ($kinds as $kind) {
                $where = '';
                if ($vsdT === 'videos' && $type2) {
                    $typeExpr = 'LOWER(TRIM(COALESCE(' . vs_q($type2) . ", '')))";
                    $where = $kind === 'Short'
                        ? ' WHERE ' . $typeExpr . " LIKE 'short%'"
                        : ' WHERE ' . $typeExpr . " NOT LIKE 'short%'";
                }
                $st2 = $pdo->query('SELECT ' . implode(', ', $sel2) . ' FROM ' . vs_q($vsdT) . $where . ' ORDER BY ' . vs_q($vw2) . ' DESC LIMIT 5');
                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $tr2) {
                    $isShort = $kind === 'Short';
                    $fileUrl = (string)$tr2['fl'];
                    $isVideoFile = (bool)preg_match('/\.(mp4|webm|mov|m4v|ogv)(\?|$)/i', $fileUrl);
                    $thumbUrl = $vsdResolve((string)$tr2['th']);
                    if ($thumbUrl === '') {
                        $youtubeId = $vsdYoutubeId((string)$tr2['yt']) ?: $vsdYoutubeId($fileUrl);
                        if ($youtubeId !== '') {
                            $thumbUrl = 'https://i.ytimg.com/vi/' . rawurlencode($youtubeId) . '/hqdefault.jpg';
                        }
                    }
                    $item = [
                        'title' => trim((string)$tr2['t']) ?: 'Untitled upload',
                        'views' => max(0, (int)$tr2['v']),
                        'likes' => $tr2['lk'] === null ? null : max(0, (int)$tr2['lk']),
                        'thumb' => $thumbUrl,
                        'video' => $isVideoFile ? $vsdResolve($fileUrl) : '',
                        'thumb_raw' => trim((string)$tr2['th']),
                        'video_raw' => $isVideoFile ? $fileUrl : '',
                        'type'  => $kind,
                        'link'  => (string)$tr2['i'] !== '' ? '../' . ($isShort ? 'shorts' : 'watch') . '.php?id=' . rawurlencode((string)$tr2['i']) : '',
                    ];
                    $vsdTop[] = $item;
                    if ($isShort) {
                        $vsdShorts[] = $item;
                    } else {
                        $vsdVideos[] = $item;
                    }
                }
            }
        } catch (Throwable $e) {
            // optional panel
        }
    }
    $sortByViews = static function (array $a, array $b): int {
        return $b['views'] <=> $a['views'];
    };
    usort($vsdVideos, $sortByViews);
    usort($vsdShorts, $sortByViews);
    $vsdVideos = array_slice($vsdVideos, 0, 5);
    $vsdShorts = array_slice($vsdShorts, 0, 5);
}

/* --- Top admins: everyone with the Admin role (owner, CEO, manager, admins), ranked by how hard they are working --- */
$vsdAdmins = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $aUc = vs_cols($pdo, $USERS_TABLE);
        if ($aUc) {
            $aRole  = vs_pick($aUc, ['role', 'user_role', 'usertype', 'user_type', 'account_type']);
            $aUser  = vs_pick($aUc, ['username', 'user_name', 'login', 'uname']);
            $aName  = vs_pick($aUc, ['name', 'full_name', 'fullname', 'display_name', 'first_name']);
            $aEmail = vs_pick($aUc, ['email', 'email_address', 'mail']);
            $aLast  = vs_pick($aUc, ['last_active', 'last_seen', 'last_login']);
            if ($aRole && ($aUser || $aName)) {
                $aSel = [];
                if ($aUser)  { $aSel[] = vs_q($aUser)  . ' AS f_username'; }
                if ($aName)  { $aSel[] = vs_q($aName)  . ' AS f_name'; }
                if ($aEmail) { $aSel[] = vs_q($aEmail) . ' AS f_email'; }
                if ($aLast)  { $aSel[] = vs_q($aLast)  . ' AS f_last'; }
                $aSt = $pdo->prepare('SELECT ' . implode(', ', $aSel) . ' FROM ' . vs_q($USERS_TABLE) . ' WHERE LOWER(' . vs_q($aRole) . ') = :r LIMIT 50');
                $aSt->execute([':r' => mb_strtolower($ADMIN_ROLE)]);
                $statsByKey = [];
                foreach ($uploaderStats['rows'] as $sr) { $statsByKey[(string)$sr['key']] = $sr; }
                $adminIndex = []; // lower-case username / name -> index in $vsdAdmins
                foreach ($aSt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
                    $uname = trim((string)($ar['f_username'] ?? ''));
                    $full  = trim((string)($ar['f_name'] ?? ''));
                    $email = trim((string)($ar['f_email'] ?? ''));
                    $isOwner = $email !== '' && function_exists('vs_is_site_owner_email') && vs_is_site_owner_email($email);
                    if ($isOwner) { continue; } // the site owner is never shown in Top Admins
                    $title = 'Admin';
                    if ($isOwner) {
                        $title = 'Owner';
                    } elseif ($uname !== '' && function_exists('vs_title_get')) {
                        $t = trim((string)vs_title_get($uname));
                        if ($t !== '') { $title = $t; }
                    }
                    $st0 = null;
                    foreach ([$uname, $full] as $cand) {
                        $k = mb_strtolower(trim($cand));
                        if ($k !== '' && isset($statsByKey[$k])) { $st0 = $statsByKey[$k]; break; }
                    }
                    $lastTs = (!empty($ar['f_last']) && ($lt = strtotime((string)$ar['f_last']))) ? $lt : 0;
                    $idx = count($vsdAdmins);
                    $vsdAdmins[] = [
                        'name'   => $full !== '' ? $full : ($uname !== '' ? $uname : 'Admin'),
                        'handle' => $uname,
                        'title'  => $title,
                        'owner'  => $isOwner,
                        'videos' => $st0 ? (int)$st0['videos'] : 0,
                        'shorts' => $st0 ? (int)$st0['shorts'] : 0,
                        'views'  => $st0 ? (int)$st0['views']  : 0,
                        'd7'     => 0,
                        'd30'    => 0,
                        'last'   => $lastTs,
                        'online' => $lastTs >= time() - 120,
                    ];
                    foreach ([$uname, $full] as $cand) {
                        $k = mb_strtolower(trim($cand));
                        if ($k !== '') { $adminIndex[$k] = $idx; }
                    }
                }

                // uploads in the last 7 / 30 days per admin
                if ($vsdAdmins) {
                    $since7  = strtotime('-7 days');
                    $since30 = strtotime('-30 days');
                    foreach ($VIDEO_TABLES as $vt) {
                        $vc = vs_cols($pdo, $vt);
                        if (!$vc) { continue; }
                        $cDate = vs_pick($vc, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'added_at', 'published_at', 'created_on', 'upload_time', 'date', 'created', 'timestamp']);
                        $cWho  = vs_pick($vc, ['username', 'uploader', 'uploaded_by', 'user_name', 'author', 'owner', 'uploader_name', 'channel_name']);
                        if (!$cDate || !$cWho) { continue; }
                        $vSt = $pdo->prepare('SELECT ' . vs_q($cWho) . ' AS f_who, ' . vs_q($cDate) . ' AS f_date FROM ' . vs_q($vt) . ' WHERE ' . vs_q($cDate) . ' >= :s LIMIT 20000');
                        $vSt->execute([':s' => date('Y-m-d H:i:s', $since30)]);
                        foreach ($vSt->fetchAll(PDO::FETCH_ASSOC) as $vr) {
                            $who = mb_strtolower(trim((string)($vr['f_who'] ?? '')));
                            if ($who === '' || !isset($adminIndex[$who])) { continue; }
                            $ts = strtotime((string)($vr['f_date'] ?? ''));
                            if (!$ts) { continue; }
                            $i = $adminIndex[$who];
                            if ($ts >= $since30) { $vsdAdmins[$i]['d30']++; }
                            if ($ts >= $since7)  { $vsdAdmins[$i]['d7']++; }
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // optional panel
    }
}
// Workload score: recent uploads count most (7 days x3, plus the 30-day total)
foreach ($vsdAdmins as $ai => $ad) {
    $vsdAdmins[$ai]['score'] = $ad['d7'] * 3 + $ad['d30'];
    if ($ad['d7'] >= 5)       { $vsdAdmins[$ai]['level'] = ['Working hard', 'hot']; }
    elseif ($ad['d7'] >= 2)   { $vsdAdmins[$ai]['level'] = ['Busy', 'busy']; }
    elseif ($ad['d7'] >= 1)   { $vsdAdmins[$ai]['level'] = ['Steady', 'steady']; }
    elseif ($ad['d30'] >= 1)  { $vsdAdmins[$ai]['level'] = ['Slowing down', 'slow']; }
    else                      { $vsdAdmins[$ai]['level'] = ['No recent uploads', 'idle']; }
}
usort($vsdAdmins, static function (array $a, array $b): int {
    return [$b['score'], $b['videos'] + $b['shorts'], $b['views']] <=> [$a['score'], $a['videos'] + $a['shorts'], $a['views']];
});
$vsdAdmins = array_slice($vsdAdmins, 0, 8);
$vsdAdminMax = 1;
foreach ($vsdAdmins as $ad) { $vsdAdminMax = max($vsdAdminMax, (int)$ad['score']); }

/* --- Top creators: uploaders ranked by total views --- */
$vsdCreators = $uploaderStats['rows'];
usort($vsdCreators, static function (array $a, array $b): int {
    return ((int)$b['views']) <=> ((int)$a['views']);
});
$vsdCreators = array_slice($vsdCreators, 0, 5);

/* --- Recent activity: admin sign-ins, uploads and comments mixed by time --- */
$vsdRecent = [];
foreach ($teamRows as $tmr) {
    $tts = $tmr['last'] !== '' ? strtotime((string)$tmr['last']) : 0;
    if ($tts) {
        $vsdRecent[] = ['icon' => 'bi-person-fill', 'tone' => 'blue', 'title' => 'Admin logged in', 'sub' => '@' . $tmr['name'], 'ts' => $tts];
    }
}
foreach (array_slice($recentContent, 0, 4) as $rcr) {
    $isS = $rcr['type'] === 'Short';
    $vsdRecent[] = [
        'icon'  => $isS ? 'bi-lightning-charge-fill' : 'bi-play-fill',
        'tone'  => $isS ? 'green' : 'purple',
        'title' => 'New ' . ($isS ? 'short' : 'video') . ' uploaded',
        'sub'   => $rcr['title'] . ' - ' . $rcr['author'],
        'ts'    => (int)strtotime($rcr['date']),
    ];
}
foreach (array_slice($latestComments, 0, 3) as $lcr) {
    $vsdRecent[] = [
        'icon'  => 'bi-chat-dots-fill',
        'tone'  => 'indigo',
        'title' => 'New comment',
        'sub'   => $lcr['who'] . ': ' . mb_substr($lcr['text'], 0, 40),
        'ts'    => (int)strtotime($lcr['date']),
    ];
}
$vsdRecent = array_values(array_filter($vsdRecent, static function (array $x): bool { return $x['ts'] > 0; }));
usort($vsdRecent, static function (array $a, array $b): int { return $b['ts'] <=> $a['ts']; });
$vsdRecent = array_slice($vsdRecent, 0, 4);

/* --- System status --- */
$vsdStatus = [];
$vsdDbOk = false;
try {
    $vsdDbOk = isset($pdo) && $pdo instanceof PDO && $pdo->query('SELECT 1') !== false;
} catch (Throwable $e) {
    $vsdDbOk = false;
}
$vsdStatus[] = ['name' => 'Database', 'text' => $vsdDbOk ? 'Healthy' : 'Not reachable', 'tone' => $vsdDbOk ? 'ok' : 'bad', 'badge' => $vsdDbOk ? 'OK' : 'DOWN'];

if ($health['disk'] !== null) {
    $dp = (float)$health['disk']['pct'];
    $vsdStatus[] = [
        'name'  => 'File Storage',
        'text'  => $dp < 85 ? 'Healthy' : ($dp < 95 ? 'Filling up (' . $dp . '% used)' : 'Almost full (' . $dp . '% used)'),
        'tone'  => $dp < 85 ? 'ok' : ($dp < 95 ? 'warn' : 'bad'),
        'badge' => $dp < 85 ? 'OK' : ($dp < 95 ? 'WARN' : 'FULL'),
    ];
} else {
    $vsdStatus[] = ['name' => 'File Storage', 'text' => 'Not available', 'tone' => 'off', 'badge' => 'N/A'];
}

$vsdMs = (int)round((microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true))) * 1000);
$vsdStatus[] = [
    'name'  => 'Site Performance',
    'text'  => ($vsdMs < 1000 ? 'Good' : ($vsdMs < 2500 ? 'Fair' : 'Slow')) . ' (' . $vsdMs . ' ms)',
    'tone'  => $vsdMs < 1000 ? 'ok' : ($vsdMs < 2500 ? 'warn' : 'bad'),
    'badge' => $vsdMs < 1000 ? 'OK' : ($vsdMs < 2500 ? 'WARN' : 'SLOW'),
];

if ($uploadFailureStats['available']) {
    $uf = (int)$uploadFailureStats['count7'];
    $vsdStatus[] = [
        'name'  => 'Upload Service',
        'text'  => $uf === 0 ? 'Operational' : $uf . ' failed upload' . ($uf === 1 ? '' : 's') . ' this week',
        'tone'  => $uf === 0 ? 'ok' : 'warn',
        'badge' => $uf === 0 ? 'OK' : 'WARN',
    ];
} else {
    $vsdStatus[] = ['name' => 'Upload Service', 'text' => 'Not monitored', 'tone' => 'off', 'badge' => 'N/A'];
}

/* --- Warning / error rows: send the admin to the place where the problem can be seen ---
   Rows that are OK (or not monitored) get no link. A page is only used when this admin is allowed to open it. */
$vsdGoMap = [
    'Database'         => [['system-check.php', 'Open System check']],
    'File Storage'     => [['backup.php', 'Open Backups'], ['system-check.php', 'Open System check']],
    'Site Performance' => [['system-check.php', 'Open System check']],
];
foreach ($vsdStatus as $vsdI => $vsdRow) {
    $vsdStatus[$vsdI]['url'] = '';
    $vsdStatus[$vsdI]['go']  = '';
    if (!in_array($vsdRow['tone'], ['warn', 'bad'], true)) {
        continue;
    }
    if ($vsdRow['name'] === 'Upload Service') {
        $vsdStatus[$vsdI]['url'] = 'upload-failures.php';
        $vsdStatus[$vsdI]['go']  = 'See the failed uploads';
        continue;
    }
    foreach (($vsdGoMap[$vsdRow['name']] ?? []) as $vsdTarget) {
        if (!function_exists('vs_admin_route_allowed') || vs_admin_route_allowed($vsdTarget[0])) {
            $vsdStatus[$vsdI]['url'] = $vsdTarget[0];
            $vsdStatus[$vsdI]['go']  = $vsdTarget[1];
            break;
        }
    }
}

/* --- First paint of the live activity feed (the browser refreshes it every 5 seconds) --- */
$vsdFeedKinds = ['bi-person-plus' => 'user', 'bi-flag' => 'report', 'bi-lightning-charge' => 'short', 'bi-play-btn' => 'video', 'bi-chat-left-text' => 'comment'];
$vsdFeedIcons = ['user' => 'bi-person-plus-fill', 'video' => 'bi-play-fill', 'short' => 'bi-lightning-charge-fill', 'comment' => 'bi-chat-dots-fill', 'report' => 'bi-flag-fill'];
$vsdFeedTones = ['user' => 'purple', 'video' => 'indigo', 'short' => 'green', 'comment' => 'blue', 'report' => 'red'];