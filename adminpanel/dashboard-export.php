<?php
/* CSV / text exports for the dashboard. Included by index.php only when ?download=... is set. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
    http_response_code(403);
    exit('Administrator access required.');
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
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
