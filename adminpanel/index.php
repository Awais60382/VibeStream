<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
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
                </div>
              </article>
            </div>
          </section>

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
          <span>  Developed by <a href="https://M.Awais.com" target="_blank"></a>Muhammad Awais</a> </span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>