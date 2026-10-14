<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession'])) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/../avatar.php';


$USERS_TABLE = 'users';

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
$uploaderRows = $uploaderStats['rows'];
if ($uploaderStats['has_date']) {
    usort($uploaderRows, fn($a, $b) => strcmp((string)$b['last'], (string)$a['last']));
}
$uploaderRows = array_slice($uploaderRows, 0, 5);

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

$UPLOAD_TABLES = ['videos', 'shorts']; 
$actTotal = 0;
$actError = '';

$hourData = [];
for ($h = 0; $h < 24; $h++) {
    $hourData[$h] = ['n' => 0, 'who' => []];
}
$dayData = [];
for ($d = 0; $d < 7; $d++) {
    $dayData[$d] = ['n' => 0, 'who' => []];
}
$monthData = [];
for ($i = 5; $i >= 0; $i--) {
    $mk = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
    $monthData[date('Y-m', $mk)] = ['n' => 0, 'who' => [], 'label' => date('M', $mk), 'name' => date('F Y', $mk)];
}
$actSince = date('Y-m-d 00:00:00', mktime(0, 0, 0, (int)date('n') - 5, 1, (int)date('Y')));

if (isset($pdo) && $pdo instanceof PDO) {
    foreach ($UPLOAD_TABLES as $tbl) {
        try {
            $tc = vs_cols($pdo, $tbl);
            if (!$tc) {
                continue;
            }
            $dc = vs_pick($tc, ['created_at', 'uploaded_at', 'upload_date', 'date_uploaded', 'created_on', 'upload_time', 'added_at', 'published_at', 'date', 'timestamp']);
            if (!$dc) {
                continue;
            }
            $pc  = vs_pick($tc, ['username', 'user_name', 'uploader', 'uploaded_by', 'author']);
            $sql = 'SELECT ' . vs_q($dc) . ' AS d' . ($pc ? ', ' . vs_q($pc) . ' AS u' : '')
                 . ' FROM ' . vs_q($tbl)
                 . ' WHERE ' . vs_q($dc) . ' >= :since ORDER BY ' . vs_q($dc) . ' DESC LIMIT 20000';
            $st = $pdo->prepare($sql);
            $st->execute([':since' => $actSince]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $ts = strtotime((string)$row['d']);
                if (!$ts) {
                    continue;
                }
                $who = isset($row['u']) ? mb_strtolower(trim((string)$row['u'])) : '';
                $h   = (int)date('G', $ts);
                $w   = (int)date('w', $ts);
                $m   = date('Y-m', $ts);

                $hourData[$h]['n']++;
                $dayData[$w]['n']++;
                if ($who !== '') {
                    $hourData[$h]['who'][$who] = true;
                    $dayData[$w]['who'][$who]  = true;
                }
                if (isset($monthData[$m])) {
                    $monthData[$m]['n']++;
                    if ($who !== '') {
                        $monthData[$m]['who'][$who] = true;
                    }
                }
                $actTotal++;
            }
        } catch (Throwable $e) {
            $actError = 'Could not read upload dates: ' . $e->getMessage();
        }
    }
}


$hourText = function (int $x): string {
    $x = $x % 24;
    $n = $x % 12;
    return ($n === 0 ? 12 : $n) . ' ' . ($x < 12 ? 'AM' : 'PM');
};
$hourShort = function (int $x): string {
    if ($x === 0) return '12a';
    if ($x < 12) return $x . 'a';
    if ($x === 12) return '12p';
    return ($x - 12) . 'p';
};

$panes = [
    'hour'  => ['btn' => 'By hour',       'word' => 'Busiest hour',  'dense' => true,  'items' => []],
    'day'   => ['btn' => 'By weekday',    'word' => 'Busiest day',   'dense' => false, 'items' => []],
    'month' => ['btn' => 'Last 6 months', 'word' => 'Busiest month', 'dense' => false, 'items' => []],
];
for ($h = 0; $h < 24; $h++) {
    $panes['hour']['items'][] = [
        'label'  => ($h % 3 === 0) ? $hourShort($h) : '',
        'name'   => $hourText($h) . ' - ' . $hourText($h + 1),
        'n'      => $hourData[$h]['n'],
        'people' => count($hourData[$h]['who']),
    ];
}
$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
foreach ([1, 2, 3, 4, 5, 6, 0] as $d) {
    $panes['day']['items'][] = [
        'label'  => substr($dayNames[$d], 0, 3),
        'name'   => $dayNames[$d],
        'n'      => $dayData[$d]['n'],
        'people' => count($dayData[$d]['who']),
    ];
}
foreach ($monthData as $md) {
    $panes['month']['items'][] = [
        'label'  => $md['label'],
        'name'   => $md['name'],
        'n'      => $md['n'],
        'people' => count($md['who']),
    ];
}
foreach ($panes as $pk => $pn) {
    $max = 0;
    $peak = -1;
    foreach ($pn['items'] as $ix => $it) {
        if ($it['n'] > $max) {
            $max  = $it['n'];
            $peak = $ix;
        }
    }
    $panes[$pk]['max']  = $max;
    $panes[$pk]['peak'] = $peak;
}


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

$actRender = function (string $kind) use ($actDays) {
    $max  = 0;
    $peak = null;
    foreach ($actDays as $row) {
        $t = $row[$kind]['user'] + $row[$kind]['admin'];
        if ($t > $max) {
            $max  = $t;
            $peak = $row;
        }
    }

    echo '<div class="vs-act">';
    foreach ($actDays as $row) {
        $u = (int)$row[$kind]['user'];
        $a = (int)$row[$kind]['admin'];
        $t = $u + $a;
        $h = $max > 0 ? round($t / $max * 100, 1) : 0;
        $tip = $kind === 'active'
            ? $row['full'] . ': ' . $t . ' active (' . $u . ' user' . ($u === 1 ? '' : 's') . ', ' . $a . ' admin' . ($a === 1 ? '' : 's') . ')'
            : $row['full'] . ': ' . $t . ' upload' . ($t === 1 ? '' : 's') . ' (' . $u . ' by users, ' . $a . ' by admins)';

        echo '<div class="vs-act-col" title="' . vs_e($tip) . '">';
        echo '<span class="vs-act-val">' . $t . '</span>';
        echo '<div class="vs-act-track"><div class="vs-act-bar" style="height:' . $h . '%">';
        if ($a > 0) { echo '<i class="vs-seg-admin" style="flex:' . $a . ' 1 0%"></i>'; }
        if ($u > 0) { echo '<i class="vs-seg-user" style="flex:' . $u . ' 1 0%"></i>'; }
        echo '</div></div>';
        echo '<small>' . vs_e($row['label']) . '</small>';
        echo '</div>';
    }
    echo '</div>';

    echo '<p class="text-muted small mt-2 mb-0">';
    if ($peak === null) {
        echo $kind === 'active' ? 'Nobody was active in the last 7 days.' : 'Nothing was uploaded in the last 7 days.';
    } elseif ($kind === 'active') {
        echo 'Busiest day: <strong>' . vs_e($peak['full']) . '</strong> with ' . $max . ' ' . ($max === 1 ? 'person' : 'people') . ' active. Each person is counted on the day they were last seen.';
    } else {
        echo 'Busiest day: <strong>' . vs_e($peak['full']) . '</strong> with ' . $max . ' upload' . ($max === 1 ? '' : 's') . '.';
    }
    echo '</p>';
};

$download = (string)($_GET['download'] ?? '');
if ($download === 'activity.csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vibestream-activity-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Could not create the activity export.');
    }
    fputcsv($output, ['Date', 'Active users', 'Active admins', 'User uploads', 'Admin uploads']);
    foreach ($actDays as $date => $row) {
        fputcsv($output, [
            $date,
            $row['active']['user'],
            $row['active']['admin'],
            $row['upload']['user'],
            $row['upload']['admin'],
        ]);
    }
    fclose($output);
    exit;
}
if ($download === 'report.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="vibestream-dashboard-report-' . date('Y-m-d') . '.txt"');
    echo "VibeStream dashboard report\n";
    echo 'Generated: ' . date('Y-m-d H:i:s T') . "\n\n";
    echo 'Registered users: ' . ($cardUsers === null ? 'unavailable' : $cardUsers) . "\n";
    echo 'Currently online: ' . ($cardOnline === null ? 'unavailable' : $cardOnline) . "\n";
    echo 'Uploaders: ' . (int)$uploaderStats['totals']['uploaders'] . "\n";
    echo 'Videos: ' . (int)$uploaderStats['totals']['videos'] . "\n";
    echo 'Shorts: ' . (int)$uploaderStats['totals']['shorts'] . "\n";
    echo 'Views: ' . (int)$uploaderStats['totals']['views'] . "\n\n";
    echo "Activity and uploads by day (last 7 days)\n";
    echo "Date | Active users | Active admins | User uploads | Admin uploads\n";
    foreach ($actDays as $date => $row) {
        echo implode(' | ', [
            $date,
            $row['active']['user'],
            $row['active']['admin'],
            $row['upload']['user'],
            $row['upload']['admin'],
        ]) . "\n";
    }
    exit;
}

/* ---------- More exports (users, uploads, uploaders) ---------- */
if (!function_exists('vs_csv_cell')) {
    // Stops spreadsheet programs from running text that starts with = + - @ as a formula.
    function vs_csv_cell($v): string
    {
        $s = (string)$v;
        if ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) {
            $s = "'" . $s;
        }
        return $s;
    }
}
if (!function_exists('vs_csv_open')) {
    function vs_csv_open(string $name)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'wb');
        if ($out === false) {
            throw new RuntimeException('Could not create the export.');
        }
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads names in any language correctly
        return $out;
    }
}

if (in_array($download, ['users.csv', 'uploads.csv', 'uploaders.csv'], true)) {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        http_response_code(500);
        echo 'No database connection found.';
        exit;
    }

    if ($download === 'uploaders.csv') {
        $out = vs_csv_open('vibestream-uploaders');
        fputcsv($out, ['Uploader', 'Videos', 'Shorts', 'Total uploads', 'Views', 'Last upload']);
        foreach ($uploaderStats['rows'] as $r) {
            fputcsv($out, [vs_csv_cell($r['name']), $r['videos'], $r['shorts'], $r['total'], $r['views'], $r['last']]);
        }
        fclose($out);
        exit;
    }

    if ($download === 'users.csv') {
        $ucx = vs_cols($pdo, $USERS_TABLE);
        $want = [
            'ID'          => vs_pick($ucx, ['id', 'user_id']),
            'User name'   => vs_pick($ucx, ['username', 'user_name', 'login', 'uname']),
            'Name'        => vs_pick($ucx, ['name', 'full_name', 'fullname', 'display_name', 'first_name']),
            'Email'       => vs_pick($ucx, ['email', 'email_address', 'mail']),
            'Role'        => vs_pick($ucx, ['role', 'user_role', 'usertype', 'user_type', 'account_type']),
            'Status'      => vs_pick($ucx, ['status', 'account_status', 'is_active', 'active']),
            'Joined'      => vs_pick($ucx, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'date']),
            'Last active' => vs_pick($ucx, ['last_active', 'last_seen', 'last_login']),
        ];
        $want = array_filter($want);
        if (!$want) {
            http_response_code(404);
            echo 'The users table was not found.';
            exit;
        }
        $sel = array_map('vs_q', array_values($want));
        $ord = $want['ID'] ?? null;
        $stX = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($USERS_TABLE) . ($ord ? ' ORDER BY ' . vs_q($ord) . ' ASC' : ''));
        $out = vs_csv_open('vibestream-users');
        fputcsv($out, array_keys($want));
        while ($stX && ($row = $stX->fetch(PDO::FETCH_NUM))) {
            fputcsv($out, array_map('vs_csv_cell', $row));
        }
        fclose($out);
        exit;
    }

    // uploads.csv: every video and short
    $out = vs_csv_open('vibestream-uploads');
    fputcsv($out, ['Type', 'Title', 'Creator', 'Views', 'Published']);
    foreach (['videos', 'shorts'] as $tblX) {
        $tcx = vs_cols($pdo, $tblX);
        if (!$tcx) {
            continue;
        }
        $cTitle = vs_pick($tcx, ['title', 'name']);
        $cWhoX  = vs_pick($tcx, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
        $cView  = vs_pick($tcx, ['views', 'view_count']);
        $cDateX = vs_pick($tcx, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at', 'date']);
        $cTypeX = vs_pick($tcx, ['type', 'video_type']);
        $cIdX   = vs_pick($tcx, ['id', 'video_id']);
        $selX = [
            $cTitle ? vs_q($cTitle) . ' AS t' : "'' AS t",
            $cWhoX  ? vs_q($cWhoX) . ' AS w' : "'' AS w",
            $cView  ? vs_q($cView) . ' AS v' : '0 AS v',
            $cDateX ? vs_q($cDateX) . ' AS d' : "'' AS d",
            $cTypeX ? vs_q($cTypeX) . ' AS k' : "'' AS k",
        ];
        $orderX = $cDateX ? vs_q($cDateX) : ($cIdX ? vs_q($cIdX) : '1');
        try {
            $stX = $pdo->query('SELECT ' . implode(', ', $selX) . ' FROM ' . vs_q($tblX) . ' ORDER BY ' . $orderX . ' DESC LIMIT 50000');
            while ($stX && ($row = $stX->fetch(PDO::FETCH_ASSOC))) {
                $isShort = $tblX === 'shorts' || stripos((string)$row['k'], 'short') === 0;
                fputcsv($out, [
                    $isShort ? 'Short' : 'Video',
                    vs_csv_cell($row['t']),
                    vs_csv_cell($row['w']),
                    (int)$row['v'],
                    $row['d'],
                ]);
            }
        } catch (Throwable $e) {
            // skip a table we cannot read
        }
    }
    fclose($out);
    exit;
}

/* ---------- Open reports + week-over-week trends ---------- */
$openReports = null;      // null = no reports table found
$reportsTable = '';
$trend = ['users' => null, 'videos' => null, 'shorts' => null];

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
if (!function_exists('vs_trend_html')) {
    function vs_trend_html(?array $t, string $noun): string
    {
        if ($t === null) {
            return '';
        }
        $diff = $t['now'] - $t['prev'];
        if ($diff === 0) {
            $cls = 'text-muted'; $icon = 'bi-dash';
        } elseif ($diff > 0) {
            $cls = 'text-success'; $icon = 'bi-arrow-up-right';
        } else {
            $cls = 'text-danger'; $icon = 'bi-arrow-down-right';
        }
        $label = $t['pct'] === null
            ? ($diff > 0 ? '+' . $diff . ' new' : 'no change')
            : ($diff > 0 ? '+' : '') . $t['pct'] . '%';
        return '<span class="' . $cls . '" title="' . (int)$t['now'] . ' ' . htmlspecialchars($noun, ENT_QUOTES, 'UTF-8')
             . ' in the last 7 days vs ' . (int)$t['prev'] . ' in the 7 days before"><i class="bi ' . $icon
             . '" aria-hidden="true"></i> ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
             . '<span>vs previous 7 days</span>';
    }
}

if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $trend['users']  = vs_trend_pair($pdo, $USERS_TABLE, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']);
        $trend['videos'] = vs_trend_pair($pdo, 'videos', ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at']);
        $trend['shorts'] = vs_trend_pair($pdo, 'shorts', ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at', 'published_at']);
    } catch (Throwable $e) {
        // trends are optional - never break the dashboard
    }

    try {
        foreach (['reports', 'reported', 'reported_content', 'content_reports', 'video_reports'] as $rt) {
            $rc = vs_cols($pdo, $rt);
            if (!$rc) {
                continue;
            }
            $reportsTable = $rt;
            $statusCol = vs_pick($rc, ['status', 'state', 'report_status']);
            $sql = 'SELECT COUNT(*) FROM ' . vs_q($rt);
            if ($statusCol) {
                // count everything that is not already handled
                $sql .= ' WHERE LOWER(CAST(' . vs_q($statusCol) . " AS CHAR)) NOT IN ('resolved','closed','dismissed','reviewed','done','handled','removed','approved','rejected','1')";
            }
            $openReports = (int)$pdo->query($sql)->fetchColumn();
            break;
        }
    } catch (Throwable $e) {
        $openReports = null;
    }
}

/* ---------- Growth chart data: new signups + new uploads per month (last 6 months) ---------- */
$growth = [];
foreach ($monthData as $gk => $gm) {
    $growth[$gk] = ['label' => $gm['label'], 'name' => $gm['name'], 'signups' => 0, 'uploads' => (int)$gm['n']];
}
$growthSignupsOk = false;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $gc = vs_cols($pdo, $USERS_TABLE);
        $gd = $gc ? vs_pick($gc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']) : null;
        if ($gd) {
            $gs = $pdo->prepare('SELECT DATE_FORMAT(' . vs_q($gd) . ", '%Y-%m') AS m, COUNT(*) AS n FROM " . vs_q($USERS_TABLE)
                . ' WHERE ' . vs_q($gd) . ' >= :since GROUP BY m');
            $gs->execute([':since' => $actSince]);
            foreach ($gs->fetchAll(PDO::FETCH_ASSOC) as $gr) {
                if (isset($growth[$gr['m']])) {
                    $growth[$gr['m']]['signups'] = (int)$gr['n'];
                }
            }
            $growthSignupsOk = true;
        }
    } catch (Throwable $e) {
        $growthSignupsOk = false;
    }
}
$growthMax = 0;
$growthSumSignups = 0;
$growthSumUploads = 0;
foreach ($growth as $gv) {
    $growthMax = max($growthMax, $gv['signups'], $gv['uploads']);
    $growthSumSignups += $gv['signups'];
    $growthSumUploads += $gv['uploads'];
}
$growthKeys = array_keys($growth);
$growthLast = $growth[end($growthKeys)] ?? null;
$growthPrev = count($growthKeys) > 1 ? $growth[$growthKeys[count($growthKeys) - 2]] : null;
$growthPct = static function (int $now, int $prev): string {
    if ($prev <= 0) {
        return $now > 0 ? 'new' : '0%';
    }
    $p = (int)round(($now - $prev) / $prev * 100);
    return ($p > 0 ? '+' : '') . $p . '%';
};

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
$succBuild = static function (array $groups): array {
    $out = ['labels' => [], 'names' => [], 's' => [], 'u' => []];
    foreach ($groups as $g) {
        $out['labels'][] = $g['label'];
        $out['names'][]  = $g['name'];
        $out['s'][]      = $g['s'];
        $out['u'][]      = $g['u'];
    }
    return $out;
};
$succKeys = array_keys($succDays);
$succDaily = static function (int $n) use ($succDays, $succKeys): array {
    $g = [];
    foreach (array_slice($succKeys, -$n) as $k) {
        $ts = strtotime($k);
        $g[] = ['label' => date('M j', $ts), 'name' => date('D, M j', $ts), 's' => $succDays[$k]['s'], 'u' => $succDays[$k]['u']];
    }
    return $g;
};
$succWeekly = [];
foreach (array_chunk(array_slice($succKeys, -91), 7) as $chunk) {
    $s = 0; $u = 0;
    foreach ($chunk as $k) { $s += $succDays[$k]['s']; $u += $succDays[$k]['u']; }
    $a = strtotime($chunk[0]); $b = strtotime($chunk[count($chunk) - 1]);
    $succWeekly[] = ['label' => date('M j', $a), 'name' => date('M j', $a) . ' - ' . date('M j', $b), 's' => $s, 'u' => $u];
}
$succMonthly = [];
foreach ($succKeys as $k) {
    $mk = substr($k, 0, 7);
    if (!isset($succMonthly[$mk])) {
        $succMonthly[$mk] = ['label' => date('M', strtotime($k)), 'name' => date('F Y', strtotime($k)), 's' => 0, 'u' => 0];
    }
    $succMonthly[$mk]['s'] += $succDays[$k]['s'];
    $succMonthly[$mk]['u'] += $succDays[$k]['u'];
}
$succMonthly = array_slice(array_values($succMonthly), -12);
$succPayload = [
    'ok'     => $succOk,
    'ranges' => [
        '7D'  => $succBuild($succDaily(7)),
        '30D' => $succBuild($succDaily(30)),
        '90D' => $succBuild($succWeekly),
        '1Y'  => $succBuild($succMonthly),
    ],
];

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

/* ---------- Content mix donut ---------- */
$mixVideos = (int)$uploaderStats['totals']['videos'];
$mixShorts = (int)$uploaderStats['totals']['shorts'];
$mixTotal  = $mixVideos + $mixShorts;
$mixVPct   = $mixTotal > 0 ? round($mixVideos / $mixTotal * 100, 1) : 0;
$mixSPct   = $mixTotal > 0 ? round(100 - $mixVPct, 1) : 0;

/* ---------- Quick stats strip ---------- */
$qsAvgViews = $mixTotal > 0 ? (int)round((int)$uploaderStats['totals']['views'] / $mixTotal) : 0;
$qsUploads30 = 0;
foreach (array_slice($succDays, -30) as $qsd) {
    $qsUploads30 += $qsd['u'];
}
$qsPerDay = round($qsUploads30 / 30, 1);
$qsSignups7 = 0;
foreach (array_slice($succDays, -7) as $qsd) {
    $qsSignups7 += $qsd['s'];
}
$qsBusiest = ($panes['day']['peak'] >= 0 && $panes['day']['max'] > 0)
    ? $panes['day']['items'][$panes['day']['peak']]['name']
    : 'No data yet';

/* ---------- Top creators + top content by views ---------- */
$topCreators = $uploaderStats['rows'];
usort($topCreators, static function (array $a, array $b): int {
    return ((int)$b['views']) <=> ((int)$a['views']);
});
$topCreators = array_slice($topCreators, 0, 5);
$topCreatorsMax = $topCreators ? max(1, (int)$topCreators[0]['views']) : 1;

$topContent = [];
if (isset($pdo) && $pdo instanceof PDO) {
    foreach (['videos', 'shorts'] as $topTbl) {
        try {
            $tcols = vs_cols($pdo, $topTbl);
            if (!$tcols) {
                continue;
            }
            $tTitle = vs_pick($tcols, ['title', 'name']);
            $tViews = vs_pick($tcols, ['views', 'view_count']);
            $tWho   = vs_pick($tcols, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
            if (!$tTitle || !$tViews) {
                continue;
            }
            $tq = 'SELECT ' . vs_q($tTitle) . ' AS t, ' . vs_q($tViews) . ' AS v'
                . ($tWho ? ', ' . vs_q($tWho) . ' AS w' : '')
                . ' FROM ' . vs_q($topTbl) . ' ORDER BY ' . vs_q($tViews) . ' DESC LIMIT 5';
            foreach ($pdo->query($tq)->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                $topContent[] = [
                    'title'  => trim((string)$tr['t']) ?: 'Untitled upload',
                    'views'  => max(0, (int)$tr['v']),
                    'author' => trim((string)($tr['w'] ?? '')) ?: 'Unknown creator',
                    'type'   => $topTbl === 'shorts' ? 'Short' : 'Video',
                ];
            }
        } catch (Throwable $e) {
            // optional panel
        }
    }
    usort($topContent, static function (array $a, array $b): int {
        return $b['views'] <=> $a['views'];
    });
    $topContent = array_slice($topContent, 0, 5);
}

/* ---------- NEW: Premium panel + latest comments (data) ---------- */
// Optional: set plan prices so the panel can estimate revenue, e.g. ['monthly' => 4.99, 'yearly' => 49.99]
$PREMIUM_PRICES   = [];
$PREMIUM_CURRENCY = '$';
$PREMIUM_TABLES   = ['premium', 'premium_users', 'premium_members', 'premium_subscriptions', 'user_premium', 'subscriptions_premium'];
$COMMENT_TABLES   = ['comments', 'video_comments', 'short_comments'];

if (empty($_SESSION['vs_dash_csrf'])) {
    $_SESSION['vs_dash_csrf'] = bin2hex(random_bytes(32));
}
$dashCsrf = $_SESSION['vs_dash_csrf'];

$premium = null;          // null = no premium table found
$premiumTable = '';
$latestComments = [];
$commentsMsg = '';
$commentTablesFound = []; // table => id column (only tables we can safely delete from)

if (isset($pdo) && $pdo instanceof PDO) {
    /* --- Premium --- */
    try {
        foreach ($PREMIUM_TABLES as $pt) {
            $pc = vs_cols($pdo, $pt);
            if (!$pc) { continue; }
            $pEnd   = vs_pick($pc, ['ends_at', 'end_date', 'expires_at', 'expiry_date', 'expires', 'end_at', 'ended_at', 'valid_until']);
            if (!$pEnd) { continue; }
            $pStart = vs_pick($pc, ['started_at', 'start_date', 'starts_at', 'activated_at', 'created_at']);
            $pPlan  = vs_pick($pc, ['plan', 'plan_name', 'tier', 'type']);
            $pUser  = vs_pick($pc, ['username', 'user_name', 'user_id']);

            $premiumTable = $pt;
            $now  = date('Y-m-d H:i:s');
            $in7  = date('Y-m-d H:i:s', strtotime('+7 days'));
            $ago30 = date('Y-m-d H:i:s', strtotime('-30 days'));
            $monthStart = date('Y-m-01 00:00:00');

            $cnt = function (string $where, array $params) use ($pdo, $pt): int {
                $st = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($pt) . ' WHERE ' . $where);
                $st->execute($params);
                return (int)$st->fetchColumn();
            };
            $premium = [
                'active'   => $cnt(vs_q($pEnd) . ' > :n', [':n' => $now]),
                'expiring' => $cnt(vs_q($pEnd) . ' > :n AND ' . vs_q($pEnd) . ' <= :s', [':n' => $now, ':s' => $in7]),
                'ended30'  => $cnt(vs_q($pEnd) . ' <= :n AND ' . vs_q($pEnd) . ' >= :a', [':n' => $now, ':a' => $ago30]),
                'new_month' => $pStart ? $cnt(vs_q($pStart) . ' >= :m', [':m' => $monthStart]) : null,
                'plans'    => [],
                'revenue'  => null,
                'list'     => [],
            ];
            if ($pPlan) {
                $st = $pdo->prepare('SELECT ' . vs_q($pPlan) . ' AS p, COUNT(*) AS n FROM ' . vs_q($pt) . ' WHERE ' . vs_q($pEnd) . ' > :n GROUP BY ' . vs_q($pPlan));
                $st->execute([':n' => $now]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $premium['plans'][trim((string)$pr['p']) ?: 'Unknown'] = (int)$pr['n'];
                }
                if ($PREMIUM_PRICES && $pStart) {
                    $st = $pdo->prepare('SELECT ' . vs_q($pPlan) . ' AS p, COUNT(*) AS n FROM ' . vs_q($pt) . ' WHERE ' . vs_q($pStart) . ' >= :m GROUP BY ' . vs_q($pPlan));
                    $st->execute([':m' => $monthStart]);
                    $sum = 0.0;
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                        $key = mb_strtolower(trim((string)$pr['p']));
                        foreach ($PREMIUM_PRICES as $plan => $price) {
                            if (mb_strtolower((string)$plan) === $key) {
                                $sum += (float)$price * (int)$pr['n'];
                            }
                        }
                    }
                    $premium['revenue'] = $sum;
                }
            }
            $sel = vs_q($pEnd) . ' AS e' . ($pUser ? ', ' . vs_q($pUser) . ' AS u' : '') . ($pPlan ? ', ' . vs_q($pPlan) . ' AS p' : '');
            $st = $pdo->prepare('SELECT ' . $sel . ' FROM ' . vs_q($pt) . ' WHERE ' . vs_q($pEnd) . ' > :n AND ' . vs_q($pEnd) . ' <= :s ORDER BY ' . vs_q($pEnd) . ' ASC LIMIT 5');
            $st->execute([':n' => $now, ':s' => $in7]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                $premium['list'][] = [
                    'user' => trim((string)($pr['u'] ?? '')) ?: 'Unknown user',
                    'plan' => trim((string)($pr['p'] ?? '')),
                    'end'  => (string)$pr['e'],
                ];
            }
            break;
        }
    } catch (Throwable $e) {
        $premium = null; // optional panel - never break the dashboard
    }

    /* --- Comments: find usable tables --- */
    $commentSources = [];
    foreach ($COMMENT_TABLES as $ct) {
        try {
            $cc = vs_cols($pdo, $ct);
            if (!$cc) { continue; }
            $cId   = vs_pick($cc, ['id', 'comment_id']);
            $cText = vs_pick($cc, ['comment', 'content', 'body', 'text', 'message', 'comment_text']);
            if (!$cId || !$cText) { continue; }
            $commentSources[$ct] = [
                'id'   => $cId,
                'text' => $cText,
                'who'  => vs_pick($cc, ['username', 'user_name', 'author', 'commenter', 'name']),
                'date' => vs_pick($cc, ['created_at', 'commented_at', 'date_added', 'created_on', 'date', 'timestamp']),
                'item' => vs_pick($cc, ['video_id', 'short_id', 'content_id', 'post_id']),
            ];
            $commentTablesFound[$ct] = $cId;
        } catch (Throwable $e) {
            // skip this table
        }
    }

    /* --- Comments: delete (POST, CSRF-protected, table must be one we found) --- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vs_del_comment'])) {
        $okCsrf = hash_equals($dashCsrf, (string)($_POST['csrf'] ?? ''));
        $dt = (string)($_POST['table'] ?? '');
        $di = (int)($_POST['comment_id'] ?? 0);
        if ($okCsrf && $di > 0 && isset($commentSources[$dt])) {
            try {
                $del = $pdo->prepare('DELETE FROM ' . vs_q($dt) . ' WHERE ' . vs_q($commentSources[$dt]['id']) . ' = :id LIMIT 1');
                $del->execute([':id' => $di]);
            } catch (Throwable $e) {
                // ignore - the comment simply stays
            }
        }
        header('Location: index.php#vsComments');
        exit;
    }

    /* --- Comments: latest 6 across tables --- */
    foreach ($commentSources as $ct => $src) {
        try {
            $sel = [
                vs_q($src['id']) . ' AS cid',
                vs_q($src['text']) . ' AS ctext',
                $src['who']  ? vs_q($src['who'])  . ' AS cwho'  : "'' AS cwho",
                $src['date'] ? vs_q($src['date']) . ' AS cdate' : "'' AS cdate",
                $src['item'] ? vs_q($src['item']) . ' AS citem' : "'' AS citem",
            ];
            $order = $src['date'] ? vs_q($src['date']) : vs_q($src['id']);
            $st = $pdo->query('SELECT ' . implode(', ', $sel) . ' FROM ' . vs_q($ct) . ' ORDER BY ' . $order . ' DESC LIMIT 6');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                $latestComments[] = [
                    'table' => $ct,
                    'id'    => (int)$cr['cid'],
                    'text'  => trim((string)$cr['ctext']),
                    'who'   => trim((string)$cr['cwho']) ?: 'Unknown user',
                    'date'  => (string)$cr['cdate'],
                    'item'  => (string)$cr['citem'],
                    'kind'  => stripos($ct, 'short') !== false ? 'Short' : 'Video',
                ];
            }
        } catch (Throwable $e) {
            // skip this table
        }
    }
    usort($latestComments, static function (array $a, array $b): int {
        return (strtotime($b['date']) ?: 0) <=> (strtotime($a['date']) ?: 0);
    });
    $latestComments = array_slice($latestComments, 0, 6);
}
if (!$commentTablesFound) {
    $commentsMsg = 'No comments table was found. Edit $COMMENT_TABLES near the top of this block.';
}

/* ---------- NEW: server health, subscribers, user moderation, searches, team (data) ---------- */
$fmtBytes = static function ($b): string {
    $b = (float)$b;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) { $b /= 1024; $i++; }
    return round($b, $i > 1 ? 1 : 0) . ' ' . $u[$i];
};

/* --- Server health --- */
$health = ['disk' => null, 'folder' => null];
$dTotal = @disk_total_space(__DIR__);
$dFree  = @disk_free_space(__DIR__);
if ($dTotal && $dFree !== false) {
    $health['disk'] = ['total' => $dTotal, 'free' => $dFree, 'pct' => round(($dTotal - $dFree) / $dTotal * 100, 1)];
}
$vsFolderCache = $_SESSION['vs_dash_folder'] ?? null;
if (is_array($vsFolderCache) && (time() - (int)$vsFolderCache['t']) < 600) {
    $health['folder'] = $vsFolderCache['v'];   // cached for 10 minutes so the dashboard stays fast
} else {
    foreach (['../uploads', '../videos', '../upload', '../media'] as $dirName) {
        $dirPath = realpath(__DIR__ . '/' . $dirName);
        if (!$dirPath || !is_dir($dirPath)) { continue; }
        $size = 0; $files = 0;
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $size += $f->getSize();
                    if (++$files > 50000) { break; }
                }
            }
        } catch (Throwable $e) {
            // unreadable folder - show what we counted
        }
        $health['folder'] = ['name' => basename($dirPath), 'size' => $size, 'capped' => $files > 50000];
        $_SESSION['vs_dash_folder'] = ['t' => time(), 'v' => $health['folder']];
        break;
    }
}

$subs = null;          // null = no subscriptions table found
$modUsers = null;      // null = no users table
$searchStats = null;   // null = no search log table found
$teamRows = [];

if (isset($pdo) && $pdo instanceof PDO) {
    /* --- Subscribers --- */
    try {
        foreach (['subscriptions', 'subscribers', 'channel_subscriptions', 'subscribes'] as $sTbl) {
            $sc = vs_cols($pdo, $sTbl);
            if (!$sc) { continue; }
            $sChan = vs_pick($sc, ['channel', 'channel_name', 'channel_username', 'creator', 'creator_username', 'subscribed_to', 'channel_id', 'creator_id']);
            if (!$sChan) { continue; }
            $sDate = vs_pick($sc, ['created_at', 'subscribed_at', 'date_subscribed', 'created_on', 'date', 'timestamp']);
            $subs = ['total' => 0, 'new7' => null, 'top' => []];
            $subs['total'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . vs_q($sTbl))->fetchColumn();
            if ($sDate) {
                $st = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($sTbl) . ' WHERE ' . vs_q($sDate) . ' >= :a');
                $st->execute([':a' => date('Y-m-d H:i:s', strtotime('-7 days'))]);
                $subs['new7'] = (int)$st->fetchColumn();
            }
            $st = $pdo->query('SELECT ' . vs_q($sChan) . ' AS c, COUNT(*) AS n FROM ' . vs_q($sTbl) . ' GROUP BY ' . vs_q($sChan) . ' ORDER BY n DESC LIMIT 5');
            $subs['top'] = $st->fetchAll(PDO::FETCH_ASSOC);
            // If the channel column holds user ids, turn them into usernames
            if ($subs['top'] && preg_match('/_id$/', $sChan)) {
                $uc3 = vs_cols($pdo, $USERS_TABLE);
                $uId3 = $uc3 ? vs_pick($uc3, ['id', 'user_id']) : null;
                $uNm3 = $uc3 ? vs_pick($uc3, ['username', 'user_name', 'name', 'full_name', 'display_name']) : null;
                if ($uId3 && $uNm3) {
                    $ids = array_values(array_unique(array_map(static fn($r) => (int)$r['c'], $subs['top'])));
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $st = $pdo->prepare('SELECT ' . vs_q($uId3) . ' AS i, ' . vs_q($uNm3) . ' AS n FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($uId3) . ' IN (' . $ph . ')');
                    $st->execute($ids);
                    $nameById = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $nr) { $nameById[(int)$nr['i']] = (string)$nr['n']; }
                    foreach ($subs['top'] as &$tr) { $tr['c'] = $nameById[(int)$tr['c']] ?? ('Channel #' . (int)$tr['c']); }
                    unset($tr);
                }
            }
            break;
        }
    } catch (Throwable $e) {
        $subs = null;
    }

    /* --- User moderation overview --- */
    try {
        $mc = vs_cols($pdo, $USERS_TABLE);
        if ($mc) {
            $mStatus = vs_pick($mc, ['status', 'account_status', 'is_active', 'active', 'is_banned', 'banned']);
            $mVerify = vs_pick($mc, ['email_verified', 'is_verified', 'verified', 'email_verified_at']);
            $mDate   = vs_pick($mc, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']);
            $mName   = vs_pick($mc, ['username', 'user_name', 'name', 'full_name', 'display_name', 'login']);
            $modUsers = ['status' => [], 'status_col' => $mStatus, 'unverified' => null, 'newest' => []];
            if ($mStatus) {
                $st = $pdo->query('SELECT ' . vs_q($mStatus) . ' AS s, COUNT(*) AS n FROM ' . vs_q($USERS_TABLE) . ' GROUP BY ' . vs_q($mStatus) . ' ORDER BY n DESC LIMIT 8');
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $sr) {
                    $modUsers['status'][] = ['label' => trim((string)$sr['s']) !== '' ? (string)$sr['s'] : '(empty)', 'n' => (int)$sr['n']];
                }
            }
            if ($mVerify) {
                $modUsers['unverified'] = (int)$pdo->query('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($mVerify) . " IS NULL OR CAST(" . vs_q($mVerify) . " AS CHAR) IN ('0','')")->fetchColumn();
            }
            if ($mDate && $mName) {
                $sel = vs_q($mName) . ' AS n, ' . vs_q($mDate) . ' AS d' . ($mStatus ? ', ' . vs_q($mStatus) . ' AS s' : '');
                $st = $pdo->query('SELECT ' . $sel . ' FROM ' . vs_q($USERS_TABLE) . ' ORDER BY ' . vs_q($mDate) . ' DESC LIMIT 5');
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $nr) {
                    $modUsers['newest'][] = ['name' => trim((string)$nr['n']) ?: 'New member', 'date' => (string)$nr['d'], 'status' => trim((string)($nr['s'] ?? ''))];
                }
            }
        }
    } catch (Throwable $e) {
        $modUsers = null;
    }

    /* --- Top searches --- */
    try {
        foreach (['search_logs', 'searches', 'search_history', 'search_terms', 'search_queries'] as $qTbl) {
            $qc = vs_cols($pdo, $qTbl);
            if (!$qc) { continue; }
            $qTerm = vs_pick($qc, ['query', 'term', 'keyword', 'search_term', 'q', 'search', 'text']);
            if (!$qTerm) { continue; }
            $qDate = vs_pick($qc, ['created_at', 'searched_at', 'date', 'created_on', 'timestamp']);
            $qRes  = vs_pick($qc, ['results', 'result_count', 'results_count', 'num_results', 'hits']);
            $norm  = 'LOWER(TRIM(' . vs_q($qTerm) . '))';
            $where = $norm . " <> ''";
            $params = [];
            if ($qDate) { $where .= ' AND ' . vs_q($qDate) . ' >= :a'; $params[':a'] = date('Y-m-d H:i:s', strtotime('-30 days')); }
            $searchStats = ['top' => [], 'zero' => [], 'has_date' => (bool)$qDate];
            $st = $pdo->prepare('SELECT ' . $norm . ' AS t, COUNT(*) AS n FROM ' . vs_q($qTbl) . ' WHERE ' . $where . ' GROUP BY t ORDER BY n DESC LIMIT 8');
            $st->execute($params);
            $searchStats['top'] = $st->fetchAll(PDO::FETCH_ASSOC);
            if ($qRes) {
                $st = $pdo->prepare('SELECT ' . $norm . ' AS t, COUNT(*) AS n FROM ' . vs_q($qTbl) . ' WHERE ' . $where . ' AND ' . vs_q($qRes) . ' = 0 GROUP BY t ORDER BY n DESC LIMIT 5');
                $st->execute($params);
                $searchStats['zero'] = $st->fetchAll(PDO::FETCH_ASSOC);
                $searchStats['has_results_col'] = true;
            } else {
                $searchStats['has_results_col'] = false;
            }
            break;
        }
    } catch (Throwable $e) {
        $searchStats = null;
    }

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
}

include "header.php";
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Overview</p>
                <h1 class="h3 mb-1">Welcome back, <?php echo vs_e($_SESSION['AdminSession'] ?? 'Admin'); ?></h1>
                <p class="text-muted mb-0">Here is what is happening across your VibeStream community.</p>
              </div>
            </div>
            <div class="heading-actions">
              <div class="dropdown d-inline-block">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="?download=activity.csv">Activity (last 7 days)</a></li>
                  <li><a class="dropdown-item" href="?download=users.csv">All users</a></li>
                  <li><a class="dropdown-item" href="?download=uploads.csv">All videos and shorts</a></li>
                  <li><a class="dropdown-item" href="?download=uploaders.csv">Uploaders</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><a class="dropdown-item" href="?download=report.txt">Dashboard summary (.txt)</a></li>
                </ul>
              </div>
              <a class="btn btn-primary btn-sm" href="report.php"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i> Create Report</a>
            </div>
          </div>

          <section class="row g-3 mt-1" aria-label="Dashboard metrics">
            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-primary">
                <div class="metric-top">
                  <span class="metric-label"><?php echo vs_e($cardPeopleLabel); ?></span>
                  <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($cardPeopleValue); ?></div>
                <div class="metric-meta">
                  <span><?php echo vs_e($cardPeopleMeta); ?></span>
                  <?php if ($cardUsers !== null) { echo vs_trend_html($trend['users'], 'new users'); } ?>
                </div>
              </article>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-success">
                <?php if ($cardOnline !== null): ?>
                <div class="metric-top">
                  <span class="metric-label">Online Now</span>
                  <span class="metric-icon"><i class="bi bi-broadcast" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($cardOnline); ?></div>
                <div class="metric-meta">
                  <span class="text-success">active</span>
                  <span>in the last 2 minutes</span>
                </div>
                <?php else: ?>
                <div class="metric-top">
                  <span class="metric-label">Total Views</span>
                  <span class="metric-icon"><i class="bi bi-eye" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($uploaderStats['totals']['views']); ?></div>
                <div class="metric-meta">
                  <span>across videos and shorts</span>
                </div>
                <?php endif; ?>
              </article>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-warning">
                <div class="metric-top">
                  <span class="metric-label">Videos</span>
                  <span class="metric-icon"><i class="bi bi-collection-play" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($uploaderStats['totals']['videos']); ?></div>
                <div class="metric-meta">
                  <span>long-form uploads</span>
                  <?php echo vs_trend_html($trend['videos'], 'new videos'); ?>
                </div>
              </article>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
              <article class="metric-card metric-danger">
                <div class="metric-top">
                  <span class="metric-label">Shorts</span>
                  <span class="metric-icon"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span>
                </div>
                <div class="metric-value"><?php echo number_format($uploaderStats['totals']['shorts']); ?></div>
                <div class="metric-meta">
                  <span>short-form uploads</span>
                  <?php echo vs_trend_html($trend['shorts'], 'new shorts'); ?>
                </div>
              </article>
            </div>
          </section>

          <style>
            .vs-qs{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
            .vs-qs-item{padding:12px 16px;border-radius:14px;background:rgba(128,128,128,.1);border:1px solid rgba(128,128,128,.16)}
            .vs-qs-item small{display:block;opacity:.65;font-size:.78rem}
            .vs-qs-item strong{display:block;font-size:1.25rem;line-height:1.3}
            @media (max-width:991px){.vs-qs{grid-template-columns:repeat(2,1fr)}}
            .vs-rank{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:8px;font-weight:700;font-size:.8rem;background:rgba(128,128,128,.16)}
            .vs-rank-1{background:#f5b301;color:#1b1b1b}
            .vs-top-title{display:block;max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
          </style>
          <section class="vs-qs mt-3" aria-label="Quick stats">
            <div class="vs-qs-item"><small>Average views per upload</small><strong><?php echo number_format($qsAvgViews); ?></strong></div>
            <div class="vs-qs-item"><small>Uploads per day (30 days)</small><strong><?php echo $qsPerDay; ?></strong></div>
            <div class="vs-qs-item"><small>New signups (7 days)</small><strong><?php echo $succOk['s'] ? number_format($qsSignups7) : 'n/a'; ?></strong></div>
            <div class="vs-qs-item"><small>Busiest upload day</small><strong><?php echo vs_e($qsBusiest); ?></strong></div>
          </section>

          <section class="row g-3 mt-1" aria-label="Success overview">
          <div class="col-12 col-xl-8">
          <div class="panel h-100" aria-label="Platform success">
            <style>
              .vs-feed{list-style:none;margin:0;padding:0}
              .vs-feed li{display:flex;gap:12px;align-items:flex-start;padding:11px 0;border-bottom:1px solid rgba(128,128,128,.18)}
              .vs-feed li:last-child{border-bottom:0}
              .vs-feed-ico{flex:0 0 auto;width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;background:rgba(128,128,128,.16)}
              .vs-feed-body{min-width:0}
              .vs-feed-body strong{display:block;font-weight:600;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
              .vs-feed-body small{display:block;opacity:.65;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
              .vs-donut{position:relative;width:170px;max-width:60%;margin:4px auto 0}
              .vs-donut svg{display:block;width:100%;height:auto}
              .vs-donut-mid{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;line-height:1.1}
              .vs-donut-mid b{font-size:1.5rem}
              .vs-donut-mid small{opacity:.65}
              .vs-donut-legend{display:flex;flex-direction:column;gap:8px;margin-top:16px}
              .vs-donut-legend div{display:flex;align-items:center;justify-content:space-between;gap:10px}
              .vs-donut-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:8px}
              .vs-succ{position:relative;width:100%}
              .vs-succ svg{display:block;width:100%;height:auto;overflow:visible}
              .vs-succ .vs-grid{stroke:currentColor;stroke-opacity:.14;stroke-dasharray:3 4}
              .vs-succ .vs-axis{fill:currentColor;opacity:.6;font-size:11px}
              .vs-succ-tip{position:absolute;pointer-events:none;display:none;min-width:130px;padding:8px 10px;border-radius:10px;font-size:.8rem;line-height:1.35;background:rgba(15,20,35,.95);color:#fff;border:1px solid rgba(255,255,255,.12);box-shadow:0 6px 20px rgba(0,0,0,.35);z-index:5}
              .vs-succ-tip i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
              .vs-succ-legend{display:flex;flex-wrap:wrap;gap:18px;margin-top:10px}
              .vs-succ-legend span{display:inline-flex;align-items:center;gap:6px}
              .vs-succ-legend i{display:inline-block;width:10px;height:10px;border-radius:3px}
            </style>
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-graph-up" aria-hidden="true"></i><span>Platform success</span></h2>
                <p class="text-muted mb-0">New signups and new uploads over time. Rising lines mean VibeStream is growing.</p>
              </div>
              <div class="btn-group btn-group-sm" role="group" aria-label="Chart range">
                <button type="button" class="btn btn-outline-secondary" data-succ-range="7D">7D</button>
                <button type="button" class="btn btn-primary" data-succ-range="30D">30D</button>
                <button type="button" class="btn btn-outline-secondary" data-succ-range="90D">90D</button>
                <button type="button" class="btn btn-outline-secondary" data-succ-range="1Y">1Y</button>
              </div>
            </div>
            <div class="vs-succ" id="vsSucc" role="img" aria-label="Line chart of new signups and new uploads">
              <div class="vs-succ-tip" id="vsSuccTip"></div>
            </div>
            <div class="vs-succ-legend text-muted small" id="vsSuccLegend"></div>
            <script>
              (function () {
                var DATA = <?php echo json_encode($succPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
                var wrap = document.getElementById('vsSucc');
                var tip = document.getElementById('vsSuccTip');
                var legend = document.getElementById('vsSuccLegend');
                var btns = document.querySelectorAll('[data-succ-range]');
                var cur = '30D';
                var SERIES = [
                  { key: 'u', name: 'New uploads', color: '#3b82f6' },
                  { key: 's', name: 'New signups', color: '#10b981' }
                ].filter(function (s) { return DATA.ok[s.key]; });
                var NS = 'http://www.w3.org/2000/svg';

                function el(name, attrs, parent) {
                  var n = document.createElementNS(NS, name);
                  for (var k in attrs) { n.setAttribute(k, attrs[k]); }
                  if (parent) { parent.appendChild(n); }
                  return n;
                }
                function niceStep(x) {
                  if (x <= 1) { return 1; }
                  var p = Math.pow(10, Math.floor(Math.log(x) / Math.LN10));
                  var f = x / p;
                  return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p;
                }
                function path(pts, top, bottom) {
                  if (!pts.length) { return ''; }
                  var d = 'M' + pts[0][0] + ',' + pts[0][1];
                  for (var i = 0; i < pts.length - 1; i++) {
                    var p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
                    var c1y = Math.min(bottom, Math.max(top, p1[1] + (p2[1] - p0[1]) / 6));
                    var c2y = Math.min(bottom, Math.max(top, p2[1] - (p3[1] - p1[1]) / 6));
                    d += ' C' + (p1[0] + (p2[0] - p0[0]) / 6) + ',' + c1y + ' ' + (p2[0] - (p3[0] - p1[0]) / 6) + ',' + c2y + ' ' + p2[0] + ',' + p2[1];
                  }
                  return d;
                }
                function fmt(n) { return Number(n).toLocaleString(); }

                function render() {
                  var r = DATA.ranges[cur];
                  var old = wrap.querySelector('svg');
                  if (old) { wrap.removeChild(old); }
                  tip.style.display = 'none';

                  if (!SERIES.length) {
                    legend.textContent = 'No date columns were found on the users, videos or shorts tables, so there is nothing to chart.';
                    return;
                  }
                  var W = wrap.clientWidth || 700, H = W < 520 ? 240 : 300;
                  var L = 44, R = 16, T = 14, B = 28;
                  var n = r.labels.length, maxV = 0;
                  SERIES.forEach(function (s) { r[s.key].forEach(function (v) { if (v > maxV) { maxV = v; } }); });
                  var step = niceStep(maxV / 4), top = step * 4;
                  var iw = W - L - R, ih = H - T - B;
                  function X(i) { return L + (n === 1 ? iw / 2 : i * iw / (n - 1)); }
                  function Y(v) { return T + ih - (v / top) * ih; }

                  var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: W, height: H });
                  var defs = el('defs', {}, svg);
                  SERIES.forEach(function (s) {
                    var g = el('linearGradient', { id: 'vsg-' + s.key, x1: 0, y1: 0, x2: 0, y2: 1 }, defs);
                    el('stop', { offset: '0%', 'stop-color': s.color, 'stop-opacity': 0.28 }, g);
                    el('stop', { offset: '100%', 'stop-color': s.color, 'stop-opacity': 0 }, g);
                  });

                  for (var t = 0; t <= 4; t++) {
                    var gy = Y(step * t);
                    el('line', { x1: L, x2: W - R, y1: gy, y2: gy, 'class': 'vs-grid' }, svg);
                    var tx = el('text', { x: L - 8, y: gy + 4, 'text-anchor': 'end', 'class': 'vs-axis' }, svg);
                    tx.textContent = fmt(step * t);
                  }
                  var every = Math.max(1, Math.ceil(n / Math.max(2, Math.floor(iw / 62))));
                  r.labels.forEach(function (lb, i) {
                    if (i % every !== 0 && i !== n - 1) { return; }
                    if (i !== n - 1 && n - 1 - i < every / 2) { return; }
                    var a = el('text', { x: X(i), y: H - 8, 'text-anchor': i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle', 'class': 'vs-axis' }, svg);
                    a.textContent = lb;
                  });

                  var base = T + ih;
                  SERIES.forEach(function (s) {
                    var pts = r[s.key].map(function (v, i) { return [X(i), Y(v)]; });
                    var line = path(pts, T, base);
                    if (n > 1) {
                      el('path', { d: line + ' L' + X(n - 1) + ',' + base + ' L' + X(0) + ',' + base + ' Z', fill: 'url(#vsg-' + s.key + ')' }, svg);
                    }
                    el('path', { d: line, fill: 'none', stroke: s.color, 'stroke-width': 2.2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, svg);
                  });

                  var guide = el('line', { y1: T, y2: base, stroke: 'currentColor', 'stroke-opacity': 0.25, visibility: 'hidden' }, svg);
                  var dots = SERIES.map(function (s) {
                    return el('circle', { r: 4.5, fill: s.color, stroke: '#fff', 'stroke-width': 1.5, visibility: 'hidden' }, svg);
                  });
                  var hit = el('rect', { x: L, y: T, width: iw, height: ih, fill: 'transparent' }, svg);
                  function hide() {
                    guide.setAttribute('visibility', 'hidden');
                    dots.forEach(function (d) { d.setAttribute('visibility', 'hidden'); });
                    tip.style.display = 'none';
                  }
                  hit.addEventListener('mousemove', function (ev) {
                    var box = svg.getBoundingClientRect();
                    var px = (ev.clientX - box.left) * (W / box.width);
                    var i = n === 1 ? 0 : Math.max(0, Math.min(n - 1, Math.round((px - L) / iw * (n - 1))));
                    guide.setAttribute('x1', X(i)); guide.setAttribute('x2', X(i)); guide.setAttribute('visibility', 'visible');
                    var html = '<strong>' + r.names[i] + '</strong>';
                    SERIES.forEach(function (s, k) {
                      dots[k].setAttribute('cx', X(i)); dots[k].setAttribute('cy', Y(r[s.key][i])); dots[k].setAttribute('visibility', 'visible');
                      html += '<br><i style="background:' + s.color + '"></i>' + s.name + ': ' + fmt(r[s.key][i]);
                    });
                    tip.innerHTML = html;
                    tip.style.display = 'block';
                    var tw = tip.offsetWidth, left = X(i) * (box.width / W) + 12;
                    if (left + tw > box.width) { left -= tw + 24; }
                    tip.style.left = Math.max(0, left) + 'px';
                    tip.style.top = '8px';
                  });
                  hit.addEventListener('mouseleave', hide);
                  wrap.appendChild(svg);

                  var span = { '7D': 'last 7 days', '30D': 'last 30 days', '90D': 'last 90 days', '1Y': 'last 12 months' }[cur];
                  var html2 = '';
                  SERIES.forEach(function (s) {
                    var sum = r[s.key].reduce(function (a, b) { return a + b; }, 0);
                    html2 += '<span><i style="background:' + s.color + '"></i>' + s.name + ' (' + fmt(sum) + ' in the ' + span + ')</span>';
                  });
                  if (!DATA.ok.s) { html2 += '<span>Signups are hidden because the users table has no join-date column.</span>'; }
                  if (!DATA.ok.u) { html2 += '<span>Uploads are hidden because no upload-date column was found.</span>'; }
                  legend.innerHTML = html2;
                }

                btns.forEach(function (b) {
                  b.addEventListener('click', function () {
                    cur = b.getAttribute('data-succ-range');
                    btns.forEach(function (x) {
                      var on = x === b;
                      x.classList.toggle('btn-primary', on);
                      x.classList.toggle('btn-outline-secondary', !on);
                    });
                    render();
                  });
                });
                var rz;
                window.addEventListener('resize', function () { clearTimeout(rz); rz = setTimeout(render, 120); });
                render();
              })();
            </script>
          </div>
          </div>

          <div class="col-12 col-xl-4">
            <div class="panel h-100" aria-label="Recent activity">
              <div class="panel-header">
                <div>
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-activity" aria-hidden="true"></i><span>Recent activity</span></h2>
                  <p class="text-muted mb-0">Latest signups, uploads and reports.</p>
                </div>
              </div>
              <?php if (!$feed): ?>
                <div class="vs-empty text-muted">Nothing has happened yet.</div>
              <?php else: ?>
                <ul class="vs-feed">
                  <?php foreach ($feed as $fi): ?>
                    <li>
                      <span class="vs-feed-ico"><i class="bi <?php echo vs_e($fi['icon']); ?>" aria-hidden="true"></i></span>
                      <div class="vs-feed-body">
                        <strong title="<?php echo vs_e($fi['title']); ?>"><?php echo vs_e($fi['title']); ?></strong>
                        <small><?php echo vs_e($fi['sub']); ?> &middot; <?php echo vs_e(vs_ago($fi['date'])); ?></small>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
            </div>
          </div>
          </section>

          <?php if ($openReports !== null): ?>
          <section class="panel mt-3" aria-label="Reported content">
            <div class="panel-header mb-0">
              <div class="d-flex align-items-center gap-3">
                <span class="metric-icon <?php echo $openReports > 0 ? 'text-danger' : 'text-success'; ?>"><i class="bi <?php echo $openReports > 0 ? 'bi-flag-fill' : 'bi-shield-check'; ?>" aria-hidden="true"></i></span>
                <div>
                  <h2 class="h5 mb-1 section-title"><span>Reports queue</span></h2>
                  <p class="text-muted mb-0">
                    <?php if ($openReports > 0): ?>
                      <strong><?php echo number_format($openReports); ?></strong> report<?php echo $openReports === 1 ? '' : 's'; ?> waiting for review.
                    <?php else: ?>
                      All clear. No reports are waiting for review.
                    <?php endif; ?>
                  </p>
                </div>
              </div>
              <a class="btn <?php echo $openReports > 0 ? 'btn-danger' : 'btn-outline-secondary'; ?> btn-sm" href="reported.php"><i class="bi bi-flag" aria-hidden="true"></i> Review reports</a>
            </div>
          </section>
          <?php endif; ?>

          <section class="row g-3 mt-1">
            <div class="col-12 col-xl-8">
              <style>
                .vs-act{display:flex;align-items:stretch;gap:12px;height:260px;padding:16px 12px 10px;border-radius:14px;background:rgba(0,0,0,.22)}
                .vs-act-col{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center}
                .vs-act-val{font-weight:700;font-size:.85rem;margin-bottom:4px}
                .vs-act-track{position:relative;flex:1;width:100%}
                .vs-act-bar{position:absolute;left:0;right:0;bottom:0;display:flex;flex-direction:column;border-radius:10px;overflow:hidden}
                .vs-act-bar i{display:block;min-height:0}
                .vs-seg-user{background:linear-gradient(180deg,#5ea5fb,#2fd3c3)}
                .vs-seg-admin{background:#f5b301}
                .vs-act-col small{margin-top:8px;font-weight:600;opacity:.7}
                .vs-act-legend{display:flex;gap:16px;margin-top:12px}
                .vs-act-legend span{display:inline-flex;align-items:center;gap:6px}
                .vs-act-legend i{display:inline-block;width:10px;height:10px;border-radius:3px}
              </style>
              <div class="panel">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i><span>Activity</span></h2>
                    <p class="text-muted mb-0">When users and admins are most active and uploading (last 7 days).</p>
                  </div>
                  <div class="btn-group btn-group-sm" role="group" aria-label="Chart view">
                    <button type="button" class="btn btn-primary" data-vs-view="active">Active</button>
                    <button type="button" class="btn btn-outline-secondary" data-vs-view="upload">Uploading</button>
                  </div>
                </div>

                <div data-vs-pane="active">
                  <?php if ($actMsg['active'] !== ''): ?>
                    <p class="text-muted text-center py-5 mb-0"><?php echo vs_e($actMsg['active']); ?></p>
                  <?php else: $actRender('active'); endif; ?>
                </div>
                <div data-vs-pane="upload" hidden>
                  <?php if ($actMsg['upload'] !== ''): ?>
                    <p class="text-muted text-center py-5 mb-0"><?php echo vs_e($actMsg['upload']); ?></p>
                  <?php else: $actRender('upload'); endif; ?>
                </div>

                <div class="vs-act-legend text-muted small">
                  <span><i class="vs-seg-user"></i> Users</span>
                  <span><i class="vs-seg-admin"></i> Admins</span>
                </div>
              </div>
              <script>
                (function () {
                  var btns = document.querySelectorAll('[data-vs-view]');
                  var panes = document.querySelectorAll('[data-vs-pane]');
                  btns.forEach(function (b) {
                    b.addEventListener('click', function () {
                      var v = b.getAttribute('data-vs-view');
                      btns.forEach(function (x) {
                        var on = (x === b);
                        x.classList.toggle('btn-primary', on);
                        x.classList.toggle('btn-outline-secondary', !on);
                      });
                      panes.forEach(function (p) { p.hidden = (p.getAttribute('data-vs-pane') !== v); });
                    });
                  });
                })();
              </script>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel h-100">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-lightning-charge" aria-hidden="true"></i><span>Quick actions</span></h2>
                    <p class="text-muted mb-0">Common admin tasks.</p>
                  </div>
                </div>
                <div class="quick-action-list">
                  <a class="quick-action" href="addvideos.php"><span class="quick-action-icon"><i class="bi bi-cloud-upload" aria-hidden="true"></i></span><span><strong>Add a video</strong><small>Publish new content</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                  <a class="quick-action" href="videos.php"><span class="quick-action-icon"><i class="bi bi-collection-play" aria-hidden="true"></i></span><span><strong>Manage videos</strong><small>Review and edit uploads</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                  <a class="quick-action" href="Users.php"><span class="quick-action-icon"><i class="bi bi-people" aria-hidden="true"></i></span><span><strong>Manage users</strong><small>View community accounts</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                  <a class="quick-action" href="categories.php"><span class="quick-action-icon"><i class="bi bi-tags" aria-hidden="true"></i></span><span><strong>Categories</strong><small>Home chips and sidebar menu</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                  <a class="quick-action" href="platform-settings.php"><span class="quick-action-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span><span><strong>App settings</strong><small>Logo, uploads, maintenance</small></span><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
                </div>
              </div>
            </div>
          </section>

          <section class="row g-3 mt-1" aria-label="Growth and content mix">
          <div class="col-12 col-xl-8">
          <section class="panel h-100" aria-label="Growth">
            <style>
              .vs-gr{display:flex;align-items:stretch;gap:14px;height:240px;padding:16px 12px 10px;border-radius:14px;background:rgba(0,0,0,.22)}
              .vs-gr-col{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center}
              .vs-gr-bars{flex:1;width:100%;display:flex;align-items:flex-end;justify-content:center;gap:6px}
              .vs-gr-bar{width:min(34px,38%);display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%}
              .vs-gr-bar b{font-size:.75rem;margin-bottom:3px;font-weight:700}
              .vs-gr-bar i{display:block;width:100%;border-radius:8px 8px 3px 3px;min-height:3px}
              .vs-gr-s{background:linear-gradient(180deg,#5ea5fb,#2fd3c3)}
              .vs-gr-u{background:#f5b301}
              .vs-gr-col small{margin-top:8px;font-weight:600;opacity:.7}
              .vs-gr-legend{display:flex;flex-wrap:wrap;gap:16px;margin-top:12px}
              .vs-gr-legend span{display:inline-flex;align-items:center;gap:6px}
              .vs-gr-legend i{display:inline-block;width:10px;height:10px;border-radius:3px}
            </style>
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-bar-chart-line" aria-hidden="true"></i><span>Growth</span></h2>
                <p class="text-muted mb-0">New signups and new uploads each month over the last 6 months.</p>
              </div>
              <?php if ($growthLast && $growthPrev): ?>
                <div class="text-end small text-muted">
                  <?php echo vs_e($growthLast['name']); ?> vs <?php echo vs_e($growthPrev['name']); ?><br>
                  <?php if ($growthSignupsOk): ?>Signups <strong><?php echo vs_e($growthPct($growthLast['signups'], $growthPrev['signups'])); ?></strong> &middot; <?php endif; ?>
                  Uploads <strong><?php echo vs_e($growthPct($growthLast['uploads'], $growthPrev['uploads'])); ?></strong>
                </div>
              <?php endif; ?>
            </div>
            <?php if ($growthMax === 0): ?>
              <div class="vs-empty text-muted">No signups or uploads in the last 6 months yet, so there is nothing to chart.</div>
            <?php else: ?>
              <div class="vs-gr" role="img" aria-label="Monthly new signups and new uploads for the last 6 months">
                <?php foreach ($growth as $gv):
                    $hs = round($gv['signups'] / $growthMax * 100, 1);
                    $hu = round($gv['uploads'] / $growthMax * 100, 1);
                    $gtip = $gv['name'] . ': ' . ($growthSignupsOk ? $gv['signups'] . ' signups, ' : '') . $gv['uploads'] . ' uploads';
                ?>
                  <div class="vs-gr-col" title="<?php echo vs_e($gtip); ?>">
                    <div class="vs-gr-bars">
                      <?php if ($growthSignupsOk): ?>
                        <div class="vs-gr-bar"><b><?php echo (int)$gv['signups']; ?></b><i class="vs-gr-s" style="height:<?php echo $hs; ?>%"></i></div>
                      <?php endif; ?>
                      <div class="vs-gr-bar"><b><?php echo (int)$gv['uploads']; ?></b><i class="vs-gr-u" style="height:<?php echo $hu; ?>%"></i></div>
                    </div>
                    <small><?php echo vs_e($gv['label']); ?></small>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="vs-gr-legend text-muted small">
                <?php if ($growthSignupsOk): ?><span><i class="vs-gr-s"></i> New signups (<?php echo number_format($growthSumSignups); ?> total)</span><?php endif; ?>
                <span><i class="vs-gr-u"></i> New uploads (<?php echo number_format($growthSumUploads); ?> total)</span>
              </div>
              <?php if (!$growthSignupsOk): ?>
                <p class="text-muted small mt-2 mb-0">Signups are not shown because the users table has no join-date column (like created_at).</p>
              <?php endif; ?>
            <?php endif; ?>
          </section>
          </div>

          <div class="col-12 col-xl-4">
            <div class="panel h-100" aria-label="Content mix">
              <div class="panel-header">
                <div>
                  <h2 class="h5 mb-1 section-title"><i class="bi bi-pie-chart" aria-hidden="true"></i><span>Content mix</span></h2>
                  <p class="text-muted mb-0">Videos compared with Shorts.</p>
                </div>
              </div>
              <?php if ($mixTotal === 0): ?>
                <div class="vs-empty text-muted">No videos or shorts yet, so there is nothing to show.</div>
              <?php else: ?>
                <div class="vs-donut" role="img" aria-label="<?php echo $mixVideos; ?> videos and <?php echo $mixShorts; ?> shorts">
                  <svg viewBox="0 0 42 42">
                    <circle cx="21" cy="21" r="15.9155" fill="none" stroke="currentColor" stroke-opacity=".15" stroke-width="5"></circle>
                    <?php if ($mixVideos > 0): ?><circle cx="21" cy="21" r="15.9155" fill="none" stroke="#3b82f6" stroke-width="5" stroke-dasharray="<?php echo $mixVPct; ?> <?php echo round(100 - $mixVPct, 1); ?>" stroke-dashoffset="25"></circle><?php endif; ?>
                    <?php if ($mixShorts > 0): ?><circle cx="21" cy="21" r="15.9155" fill="none" stroke="#10b981" stroke-width="5" stroke-dasharray="<?php echo $mixSPct; ?> <?php echo round(100 - $mixSPct, 1); ?>" stroke-dashoffset="<?php echo round(25 - $mixVPct, 1); ?>"></circle><?php endif; ?>
                  </svg>
                  <div class="vs-donut-mid"><b><?php echo number_format($mixTotal); ?></b><small>total uploads</small></div>
                </div>
                <div class="vs-donut-legend small">
                  <div><span><i style="background:#3b82f6"></i>Videos</span><span><strong><?php echo number_format($mixVideos); ?></strong> <span class="text-muted">(<?php echo $mixVPct; ?>%)</span></span></div>
                  <div><span><i style="background:#10b981"></i>Shorts</span><span><strong><?php echo number_format($mixShorts); ?></strong> <span class="text-muted">(<?php echo $mixSPct; ?>%)</span></span></div>
                </div>
              <?php endif; ?>
            </div>
          </div>
          </section>

          <section class="row g-3 mt-1" aria-label="Top content and creators">
            <div class="col-12 col-xl-7">
              <div class="panel h-100">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-trophy" aria-hidden="true"></i><span>Top content</span></h2>
                    <p class="text-muted mb-0">The 5 most viewed videos and Shorts.</p>
                  </div>
                  <a class="btn btn-outline-secondary btn-sm" href="videos.php">View all</a>
                </div>
                <?php if (!$topContent): ?>
                  <div class="vs-empty text-muted">No view counts are available yet.</div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table align-middle mb-0">
                      <thead><tr><th scope="col">#</th><th scope="col">Content</th><th scope="col">Creator</th><th scope="col">Type</th><th scope="col" class="text-end">Views</th></tr></thead>
                      <tbody>
                        <?php foreach ($topContent as $ti => $tc): ?>
                          <tr>
                            <td><span class="vs-rank <?php echo $ti === 0 ? 'vs-rank-1' : ''; ?>"><?php echo $ti + 1; ?></span></td>
                            <td><span class="vs-top-title" title="<?php echo vs_e($tc['title']); ?>"><?php echo vs_e($tc['title']); ?></span></td>
                            <td><?php echo vs_e($tc['author']); ?></td>
                            <td><span class="content-type-badge"><?php echo vs_e($tc['type']); ?></span></td>
                            <td class="text-end vs-num"><?php echo number_format($tc['views']); ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-xl-5">
              <div class="panel h-100">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-stars" aria-hidden="true"></i><span>Top creators</span></h2>
                    <p class="text-muted mb-0">Ranked by total views.</p>
                  </div>
                  <a class="btn btn-outline-secondary btn-sm" href="Users.php">Manage</a>
                </div>
                <?php if (!$topCreators): ?>
                  <div class="vs-empty text-muted">No creators yet.</div>
                <?php else: ?>
                  <ul class="vs-feed">
                    <?php foreach ($topCreators as $ci => $cr): $cw = round(((int)$cr['views']) / $topCreatorsMax * 100, 1); ?>
                      <li>
                        <span class="vs-rank <?php echo $ci === 0 ? 'vs-rank-1' : ''; ?>"><?php echo $ci + 1; ?></span>
                        <?php echo vs_avatar($cr['name'], function_exists('avatar_url') ? avatar_url($cr['name']) : ''); ?>
                        <div class="vs-feed-body flex-grow-1">
                          <strong><?php echo vs_e($cr['name']); ?></strong>
                          <small><?php echo (int)$cr['total']; ?> upload<?php echo (int)$cr['total'] === 1 ? '' : 's'; ?> &middot; <?php echo number_format((int)$cr['views']); ?> views</small>
                          <div class="progress mt-1" style="height:5px"><div class="progress-bar bg-primary" style="width:<?php echo $cw; ?>%"></div></div>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <style>
            .vs-mini{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
            .vs-mini div{padding:10px 12px;border-radius:12px;background:rgba(128,128,128,.1);border:1px solid rgba(128,128,128,.16)}
            .vs-mini small{display:block;opacity:.65;font-size:.75rem}
            .vs-mini strong{display:block;font-size:1.2rem;line-height:1.3}
            .vs-cmt-text{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;white-space:normal;margin:2px 0 0;font-size:.9rem}
            .vs-cmt-row{display:flex;gap:10px;align-items:flex-start;justify-content:space-between}
          </style>
          <section class="row g-3 mt-1" aria-label="Premium and comments">
            <div class="col-12 col-xl-6">
              <div class="panel h-100" aria-label="Premium overview">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-gem" aria-hidden="true"></i><span>Premium</span></h2>
                    <p class="text-muted mb-0">Subscribers and renewals coming up.</p>
                  </div>
                  <a class="btn btn-outline-secondary btn-sm" href="premium.php">Manage</a>
                </div>
                <?php if ($premium === null): ?>
                  <div class="vs-empty text-muted">No premium table with an end-date column was found. Edit <code>$PREMIUM_TABLES</code> near the top of the new data block in index.php.</div>
                <?php else: ?>
                  <div class="vs-mini">
                    <div><small>Active members</small><strong><?php echo number_format($premium['active']); ?></strong></div>
                    <div><small>Ending in 7 days</small><strong class="<?php echo $premium['expiring'] > 0 ? 'text-warning' : ''; ?>"><?php echo number_format($premium['expiring']); ?></strong></div>
                    <div><small>Ended (30 days)</small><strong><?php echo number_format($premium['ended30']); ?></strong></div>
                    <div><small>New this month</small><strong><?php echo $premium['new_month'] === null ? 'n/a' : number_format($premium['new_month']); ?></strong></div>
                    <div><small>Est. revenue this month</small><strong><?php echo $premium['revenue'] === null ? 'n/a' : vs_e($PREMIUM_CURRENCY) . number_format($premium['revenue'], 2); ?></strong></div>
                    <div><small>Plans</small><strong style="font-size:.95rem">
                      <?php
                      if ($premium['plans']) {
                          $pp = [];
                          foreach ($premium['plans'] as $pn => $pv) { $pp[] = vs_e(ucfirst((string)$pn)) . ' ' . (int)$pv; }
                          echo implode(' &middot; ', $pp);
                      } else { echo 'n/a'; }
                      ?>
                    </strong></div>
                  </div>
                  <?php if ($premium['revenue'] === null): ?>
                    <p class="text-muted small">Revenue shows once you fill in <code>$PREMIUM_PRICES</code> (plan name =&gt; price).</p>
                  <?php endif; ?>
                  <?php if (!$premium['list']): ?>
                    <p class="text-muted small mb-0">Nobody's premium ends in the next 7 days.</p>
                  <?php else: ?>
                    <p class="fw-semibold small mb-1">Ending soon</p>
                    <ul class="vs-feed">
                      <?php foreach ($premium['list'] as $pl): ?>
                        <li>
                          <span class="vs-feed-ico"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                          <div class="vs-feed-body">
                            <strong><?php echo vs_e($pl['user']); ?></strong>
                            <small><?php echo $pl['plan'] !== '' ? vs_e(ucfirst($pl['plan'])) . ' &middot; ' : ''; ?>ends <?php echo vs_e(date('M j, Y', strtotime($pl['end']))); ?></small>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-xl-6" id="vsComments">
              <div class="panel h-100" aria-label="Latest comments">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-chat-left-text" aria-hidden="true"></i><span>Latest comments</span></h2>
                    <p class="text-muted mb-0">Newest comments, with quick delete.</p>
                  </div>
                </div>
                <?php if ($commentsMsg !== ''): ?>
                  <div class="vs-empty text-muted"><?php echo vs_e($commentsMsg); ?></div>
                <?php elseif (!$latestComments): ?>
                  <div class="vs-empty text-muted">No comments yet.</div>
                <?php else: ?>
                  <ul class="vs-feed">
                    <?php foreach ($latestComments as $lc): ?>
                      <li>
                        <?php echo vs_avatar($lc['who'], function_exists('avatar_url') ? avatar_url($lc['who']) : ''); ?>
                        <div class="vs-feed-body flex-grow-1">
                          <div class="vs-cmt-row">
                            <strong style="max-width:60%"><?php echo vs_e($lc['who']); ?></strong>
                            <small class="flex-shrink-0"><?php echo $lc['date'] !== '' ? vs_e(vs_ago($lc['date'])) : ''; ?> &middot; <?php echo vs_e($lc['kind']); ?></small>
                          </div>
                          <p class="vs-cmt-text"><?php echo vs_e($lc['text']); ?></p>
                        </div>
                        <form method="post" action="index.php#vsComments" class="flex-shrink-0" onsubmit="return confirm('Delete this comment?');">
                          <input type="hidden" name="vs_del_comment" value="1">
                          <input type="hidden" name="csrf" value="<?php echo vs_e($dashCsrf); ?>">
                          <input type="hidden" name="table" value="<?php echo vs_e($lc['table']); ?>">
                          <input type="hidden" name="comment_id" value="<?php echo (int)$lc['id']; ?>">
                          <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete comment" aria-label="Delete comment"><i class="bi bi-trash" aria-hidden="true"></i></button>
                        </form>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <style>
            .vs-meter{height:8px;border-radius:999px;background:rgba(128,128,128,.2);overflow:hidden;margin:6px 0 4px}
            .vs-meter>i{display:block;height:100%;border-radius:999px}
            .vs-kv{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid rgba(128,128,128,.16);font-size:.9rem}
            .vs-kv:last-child{border-bottom:0}
            .vs-on{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:#10b981}
            .vs-off{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:6px;background:rgba(128,128,128,.5)}
          </style>
          <section class="row g-3 mt-1" aria-label="Server, subscribers and team">
            <div class="col-12 col-xl-4">
              <div class="panel h-100" aria-label="Server health">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-hdd" aria-hidden="true"></i><span>Storage &amp; server</span></h2>
                    <p class="text-muted mb-0">Disk space and upload limits.</p>
                  </div>
                </div>
                <?php if ($health['disk']): $dp = $health['disk']['pct']; $dc = $dp >= 90 ? '#ef4444' : ($dp >= 75 ? '#f5b301' : '#10b981'); ?>
                  <div class="d-flex justify-content-between small"><span>Disk used</span><strong><?php echo vs_e($dp); ?>%</strong></div>
                  <div class="vs-meter" role="img" aria-label="Disk <?php echo vs_e($dp); ?> percent used"><i style="width:<?php echo vs_e($dp); ?>%;background:<?php echo $dc; ?>"></i></div>
                  <p class="text-muted small"><?php echo vs_e($fmtBytes($health['disk']['free'])); ?> free of <?php echo vs_e($fmtBytes($health['disk']['total'])); ?></p>
                  <?php if ($dp >= 90): ?><p class="text-danger small fw-semibold"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Disk is almost full. New uploads may fail.</p><?php endif; ?>
                <?php else: ?>
                  <p class="text-muted small">Disk information is not available on this server.</p>
                <?php endif; ?>
                <div class="vs-kv"><span>Uploads folder<?php echo $health['folder'] ? ' (' . vs_e($health['folder']['name']) . ')' : ''; ?></span><strong><?php echo $health['folder'] ? vs_e($fmtBytes($health['folder']['size'])) . (!empty($health['folder']['capped']) ? '+' : '') : 'not found'; ?></strong></div>
                <div class="vs-kv"><span>Max upload size</span><strong><?php echo vs_e((string)ini_get('upload_max_filesize')); ?></strong></div>
                <div class="vs-kv"><span>Max POST size</span><strong><?php echo vs_e((string)ini_get('post_max_size')); ?></strong></div>
                <div class="vs-kv"><span>Memory limit</span><strong><?php echo vs_e((string)ini_get('memory_limit')); ?></strong></div>
                <div class="vs-kv"><span>PHP version</span><strong><?php echo vs_e(PHP_VERSION); ?></strong></div>
              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel h-100" aria-label="Subscribers">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-bell" aria-hidden="true"></i><span>Subscribers</span></h2>
                    <p class="text-muted mb-0">Channels with the most subscribers.</p>
                  </div>
                </div>
                <?php if ($subs === null): ?>
                  <div class="vs-empty text-muted">No subscriptions table was found. Edit the table list in the new data block in index.php.</div>
                <?php else: ?>
                  <div class="vs-mini" style="grid-template-columns:repeat(2,1fr)">
                    <div><small>Total subscriptions</small><strong><?php echo number_format($subs['total']); ?></strong></div>
                    <div><small>New (7 days)</small><strong><?php echo $subs['new7'] === null ? 'n/a' : number_format($subs['new7']); ?></strong></div>
                  </div>
                  <?php if (!$subs['top']): ?>
                    <p class="text-muted small mb-0">No subscriptions yet.</p>
                  <?php else: ?>
                    <ul class="vs-feed">
                      <?php foreach ($subs['top'] as $si => $sr): ?>
                        <li>
                          <span class="vs-rank <?php echo $si === 0 ? 'vs-rank-1' : ''; ?>"><?php echo $si + 1; ?></span>
                          <div class="vs-feed-body flex-grow-1">
                            <strong><?php echo vs_e((string)$sr['c']); ?></strong>
                            <small><?php echo number_format((int)$sr['n']); ?> subscriber<?php echo (int)$sr['n'] === 1 ? '' : 's'; ?></small>
                          </div>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-xl-4">
              <div class="panel h-100" aria-label="Admin team">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Admin team</span></h2>
                    <p class="text-muted mb-0">Who has been active lately.</p>
                  </div>
                  <a class="btn btn-outline-secondary btn-sm" href="admins.php">Admins</a>
                </div>
                <?php if (!$teamRows): ?>
                  <div class="vs-empty text-muted">No admin accounts could be listed.</div>
                <?php else: ?>
                  <ul class="vs-feed">
                    <?php foreach ($teamRows as $tm): ?>
                      <li>
                        <?php echo vs_avatar($tm['name'], function_exists('avatar_url') ? avatar_url($tm['name']) : ''); ?>
                        <div class="vs-feed-body">
                          <strong><?php echo vs_e($tm['name']); ?></strong>
                          <small><span class="<?php echo $tm['online'] ? 'vs-on' : 'vs-off'; ?>"></span><?php echo $tm['online'] ? 'Online now' : ($tm['last'] !== '' ? 'Seen ' . vs_e(vs_ago($tm['last'])) : 'No activity recorded'); ?></small>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <section class="row g-3 mt-1" aria-label="User moderation and searches">
            <div class="col-12 col-xl-6">
              <div class="panel h-100" aria-label="User overview">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-person-exclamation" aria-hidden="true"></i><span>User moderation</span></h2>
                    <p class="text-muted mb-0">Account status and newest signups.</p>
                  </div>
                  <a class="btn btn-outline-secondary btn-sm" href="Users.php">Manage users</a>
                </div>
                <?php if ($modUsers === null): ?>
                  <div class="vs-empty text-muted">The users table could not be read.</div>
                <?php else: ?>
                  <?php if ($modUsers['status']): ?>
                    <p class="fw-semibold small mb-1">By account status</p>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                      <?php foreach ($modUsers['status'] as $ms): ?>
                        <span class="content-type-badge"><?php echo vs_e($ms['label']); ?>: <strong><?php echo number_format($ms['n']); ?></strong></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                  <?php if ($modUsers['unverified'] !== null): ?>
                    <div class="vs-kv"><span>Unverified accounts</span><strong><?php echo number_format($modUsers['unverified']); ?></strong></div>
                  <?php endif; ?>
                  <?php if ($modUsers['newest']): ?>
                    <p class="fw-semibold small mt-3 mb-1">Newest signups</p>
                    <ul class="vs-feed">
                      <?php foreach ($modUsers['newest'] as $nu): ?>
                        <li>
                          <?php echo vs_avatar($nu['name'], function_exists('avatar_url') ? avatar_url($nu['name']) : ''); ?>
                          <div class="vs-feed-body flex-grow-1">
                            <strong><?php echo vs_e($nu['name']); ?></strong>
                            <small>Joined <?php echo vs_e(vs_ago($nu['date'])); ?><?php echo $nu['status'] !== '' ? ' &middot; ' . vs_e($nu['status']) : ''; ?></small>
                          </div>
                          <a class="btn btn-light btn-sm" href="Users.php">View</a>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>

            <div class="col-12 col-xl-6">
              <div class="panel h-100" aria-label="Top searches">
                <div class="panel-header">
                  <div>
                    <h2 class="h5 mb-1 section-title"><i class="bi bi-search" aria-hidden="true"></i><span>Top searches</span></h2>
                    <p class="text-muted mb-0">What people look for<?php echo ($searchStats && $searchStats['has_date']) ? ' (last 30 days)' : ''; ?>.</p>
                  </div>
                </div>
                <?php if ($searchStats === null): ?>
                  <div class="vs-empty text-muted">No search log table was found. Searches need to be saved to a table (for example <code>search_logs</code>) before they can be shown here.</div>
                <?php elseif (!$searchStats['top']): ?>
                  <div class="vs-empty text-muted">No searches recorded yet.</div>
                <?php else: ?>
                  <?php foreach ($searchStats['top'] as $sq): ?>
                    <div class="vs-kv"><span><?php echo vs_e((string)$sq['t']); ?></span><strong><?php echo number_format((int)$sq['n']); ?></strong></div>
                  <?php endforeach; ?>
                  <?php if (!empty($searchStats['zero'])): ?>
                    <p class="fw-semibold small mt-3 mb-1 text-warning"><i class="bi bi-exclamation-circle" aria-hidden="true"></i> Searches with no results (content to add)</p>
                    <?php foreach ($searchStats['zero'] as $sq): ?>
                      <div class="vs-kv"><span><?php echo vs_e((string)$sq['t']); ?></span><strong><?php echo number_format((int)$sq['n']); ?></strong></div>
                    <?php endforeach; ?>
                  <?php elseif (!empty($searchStats['has_results_col'])): ?>
                    <p class="text-muted small mt-3 mb-0">Every recent search found something.</p>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </section>

          <?php vs_print_styles(); ?>
          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Recent uploads</span></h2>
                <p class="text-muted mb-0">Latest videos and Shorts published to VibeStream.</p>
              </div>
              <a class="btn btn-outline-secondary btn-sm" href="videos.php">View all videos</a>
            </div>
            <?php if ($recentContentError !== ''): ?>
              <div class="vs-empty text-muted"><?php echo vs_e($recentContentError); ?></div>
            <?php elseif (!$recentContent): ?>
              <div class="vs-empty text-muted">No uploaded content is available yet.</div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead><tr><th scope="col">Content</th><th scope="col">Creator</th><th scope="col">Type</th><th scope="col" class="text-end">Views</th><th scope="col" class="text-end">Published</th></tr></thead>
                  <tbody>
                    <?php foreach ($recentContent as $content): ?>
                      <tr>
                        <td><div class="table-media"><span class="content-type-icon"><i class="bi <?php echo $content['type'] === 'Short' ? 'bi-lightning-charge' : 'bi-play-btn'; ?>" aria-hidden="true"></i></span><strong class="content-title"><?php echo vs_e($content['title']); ?></strong></div></td>
                        <td><?php echo vs_e($content['author']); ?></td>
                        <td><span class="content-type-badge"><?php echo vs_e($content['type']); ?></span></td>
                        <td class="text-end"><?php echo number_format($content['views']); ?></td>
                        <td class="text-end text-muted"><?php echo vs_e(vs_ago($content['date'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <?php $eng = (isset($pdo) && $pdo instanceof PDO) ? vs_engagement_stats($pdo) : null; ?>
          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i><span>Likes &amp; Comments</span></h2>
                <p class="text-muted mb-0">How many videos and shorts have been liked and commented on.</p>
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="videos.php">Videos</a>
                <a class="btn btn-outline-secondary btn-sm" href="shorts.php">Shorts</a>
              </div>
            </div>
            <?php if ($eng === null || $eng['error'] !== ''): ?>
              <div class="vs-empty text-muted"><?php echo vs_e($eng['error'] ?? 'No database connection found.'); ?></div>
            <?php else:
                $engTotal = [
                    'items' => $eng['video']['items'] + $eng['short']['items'],
                    'liked_items' => $eng['video']['liked_items'] + $eng['short']['liked_items'],
                    'likes' => $eng['video']['likes'] + $eng['short']['likes'],
                    'commented_items' => $eng['video']['commented_items'] + $eng['short']['commented_items'],
                    'comments' => $eng['video']['comments'] + $eng['short']['comments'],
                ];
                $engRows = ['Videos' => $eng['video'], 'Shorts' => $eng['short'], 'Total' => $engTotal];
            ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Type</th>
                      <th scope="col" class="text-end">Uploaded</th>
                      <th scope="col" class="text-end">Liked</th>
                      <th scope="col" class="text-end">Total likes</th>
                      <th scope="col" class="text-end">Commented</th>
                      <th scope="col" class="text-end">Total comments</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($engRows as $engLabel => $e): ?>
                    <tr<?php echo $engLabel === 'Total' ? ' class="fw-semibold"' : ''; ?>>
                      <td><?php echo vs_e($engLabel); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['items']); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['liked_items']); ?> <span class="text-muted small">of <?php echo number_format($e['items']); ?></span></td>
                      <td class="text-end vs-num"><?php echo number_format($e['likes']); ?></td>
                      <td class="text-end vs-num"><?php echo number_format($e['commented_items']); ?> <span class="text-muted small">of <?php echo number_format($e['items']); ?></span></td>
                      <td class="text-end vs-num"><?php echo number_format($e['comments']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <section class="panel mt-3">
            <div class="panel-header">
              <div>
                <h2 class="h5 mb-1 section-title"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span><?php echo $uploaderStats['has_date'] ? 'Recent Uploaders' : 'Top Uploaders'; ?></span></h2>
                <p class="text-muted mb-0">People who upload videos and shorts to VibeStream.</p>
              </div>
              <a class="btn btn-outline-secondary btn-sm" href="Users.php">Manage Users</a>
            </div>

            <?php if ($uploaderStats['error'] !== ''): ?>
              <div class="vs-empty text-muted">
                <p class="fw-semibold mb-1">Uploaders can't be shown yet</p>
                <p class="mb-0"><?php echo vs_e($uploaderStats['error']); ?></p>
              </div>

            <?php elseif (!$uploaderRows): ?>
              <div class="vs-empty text-muted">
                <p class="fw-semibold mb-1">No uploads yet</p>
                <p class="mb-0">Uploaders appear here after the first video or short is added. <a href="addvideos.php">Upload one</a></p>
              </div>

            <?php else: ?>
              <div class="vs-totals text-muted">
                <span><strong><?php echo (int)$uploaderStats['totals']['uploaders']; ?></strong> uploaders</span>
                <span><span class="vs-dot bg-primary"></span><strong><?php echo (int)$uploaderStats['totals']['videos']; ?></strong> videos</span>
                <span><span class="vs-dot bg-info"></span><strong><?php echo (int)$uploaderStats['totals']['shorts']; ?></strong> shorts</span>
                <span><strong><?php echo number_format($uploaderStats['totals']['views']); ?></strong> views</span>
              </div>

              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <thead>
                    <tr>
                      <th scope="col">Uploader</th>
                      <th scope="col" class="text-end">Videos</th>
                      <th scope="col" class="text-end">Shorts</th>
                      <th scope="col" class="vs-hide-sm">Mix</th>
                      <th scope="col" class="text-end">Views</th>
                      <?php if ($uploaderStats['has_date']): ?><th scope="col">Last upload</th><?php endif; ?>
                      <th scope="col" class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($uploaderRows as $r):
                      $sum  = max(1, $r['videos'] + $r['shorts']);
                      $vPct = round($r['videos'] / $sum * 100, 1);
                      $sPct = round(100 - $vPct, 1);
                  ?>
                    <tr>
                      <td>
                        <div class="d-flex align-items-center gap-2">
                          <?php echo vs_avatar($r['name'], function_exists('avatar_url') ? avatar_url($r['name']) : ''); ?>
                          <div>
                            <p class="fw-semibold mb-0"><?php echo vs_e($r['name']); ?></p>
                            <p class="text-muted small mb-0"><?php echo (int)$r['total']; ?> upload<?php echo $r['total'] === 1 ? '' : 's'; ?></p>
                          </div>
                        </div>
                      </td>
                      <td class="text-end vs-num"><?php echo (int)$r['videos']; ?></td>
                      <td class="text-end vs-num"><?php echo (int)$r['shorts']; ?></td>
                      <td class="vs-hide-sm">
                        <div class="progress vs-mix" role="img" aria-label="<?php echo (int)$r['videos']; ?> videos, <?php echo (int)$r['shorts']; ?> shorts" title="<?php echo (int)$r['videos']; ?> videos, <?php echo (int)$r['shorts']; ?> shorts">
                          <div class="progress-bar bg-primary" style="width:<?php echo $vPct; ?>%"></div>
                          <div class="progress-bar bg-info" style="width:<?php echo $sPct; ?>%"></div>
                        </div>
                      </td>
                      <td class="text-end"><?php echo number_format($r['views']); ?></td>
                      <?php if ($uploaderStats['has_date']): ?><td class="text-muted"><?php echo vs_e(vs_ago($r['last'])); ?></td><?php endif; ?>
                      <td class="text-end"><a class="btn btn-light btn-sm" href="videos.php?uploader=<?php echo urlencode($r['key']); ?>">View</a></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>  Developed by <a href="https://M.Awais.com" target="_blank" rel="noopener">Muhammad Awais</a> </span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>