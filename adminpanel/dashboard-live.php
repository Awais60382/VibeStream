<?php
/* Live endpoint for the dashboard: tiny JSON answer (online users + activity feed), polled by the browser. */
/* Needs $pdo and $USERS_TABLE from index.php. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'Admin') !== 0) {
    http_response_code(403);
    exit('Administrator access required.');
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
if (($_GET['live'] ?? '') === '1') {
    session_write_close(); // don't lock the session while polling
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $liveOnline = null;
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $lc = vs_cols($pdo, $USERS_TABLE);
            $ll = $lc ? vs_pick($lc, ['last_active', 'last_seen', 'last_login']) : null;
            if ($ll) {
                $lst = $pdo->prepare('SELECT COUNT(*) FROM ' . vs_q($USERS_TABLE) . ' WHERE ' . vs_q($ll) . ' >= :cut');
                $lst->execute([':cut' => date('Y-m-d H:i:s', time() - 120)]);
                $liveOnline = (int)$lst->fetchColumn();
            }
        } catch (Throwable $e) {
            $liveOnline = null;
        }
    }
    $liveFeed = [];
    $liveReports = null;
    // live=1&feed=1 also returns the activity feed and the open-reports count (polled every few seconds, not every second)
    if ((int)($_GET['live'] ?? 0) === 1 && isset($_GET['feed']) && isset($pdo) && $pdo instanceof PDO) {
        $liveAdd = function (string $kind, string $title, string $sub, $date) use (&$liveFeed): void {
            $ts = strtotime((string)$date);
            if (!$ts) { return; }
            $liveFeed[] = ['k' => $kind, 'title' => mb_substr(trim($title), 0, 90), 'sub' => mb_substr(trim($sub), 0, 60), 't' => $ts];
        };
        try { // new users
            $c = vs_cols($pdo, $USERS_TABLE);
            $d = $c ? vs_pick($c, ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date']) : null;
            $n = $c ? vs_pick($c, ['username', 'user_name', 'name', 'full_name', 'display_name', 'login']) : null;
            if ($d) {
                $q = 'SELECT ' . vs_q($d) . ' AS d' . ($n ? ', ' . vs_q($n) . ' AS n' : '') . ' FROM ' . vs_q($USERS_TABLE) . ' ORDER BY ' . vs_q($d) . ' DESC LIMIT 5';
                foreach ($pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $liveAdd('user', 'New user registered', (string)($r['n'] ?? '') ?: 'New member', $r['d']);
                }
            }
        } catch (Throwable $e) {}
        foreach (['videos', 'shorts'] as $t) { // new uploads
            try {
                $c = vs_cols($pdo, $t);
                if (!$c) { continue; }
                $ti = vs_pick($c, ['title', 'name']);
                $d  = vs_pick($c, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at']);
                $w  = vs_pick($c, ['username', 'user_name', 'uploader', 'uploaded_by', 'author', 'channel_name']);
                if (!$ti || !$d) { continue; }
                $q = 'SELECT ' . vs_q($ti) . ' AS t, ' . vs_q($d) . ' AS d' . ($w ? ', ' . vs_q($w) . ' AS w' : '') . ' FROM ' . vs_q($t) . ' ORDER BY ' . vs_q($d) . ' DESC LIMIT 5';
                foreach ($pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $liveAdd($t === 'shorts' ? 'short' : 'video', ($t === 'shorts' ? 'Short' : 'Video') . ' uploaded: ' . ((string)$r['t'] ?: 'Untitled'), 'By ' . ((string)($r['w'] ?? '') ?: 'Unknown creator'), $r['d']);
                }
            } catch (Throwable $e) {}
        }
        foreach (['comments', 'video_comments', 'short_comments'] as $t) { // new comments
            try {
                $c = vs_cols($pdo, $t);
                if (!$c) { continue; }
                $tx = vs_pick($c, ['comment', 'content', 'body', 'text', 'message', 'comment_text']);
                $d  = vs_pick($c, ['created_at', 'commented_at', 'date_added', 'created_on', 'date', 'timestamp']);
                $w  = vs_pick($c, ['username', 'user_name', 'author', 'commenter', 'name']);
                if (!$tx || !$d) { continue; }
                $q = 'SELECT ' . vs_q($tx) . ' AS t, ' . vs_q($d) . ' AS d' . ($w ? ', ' . vs_q($w) . ' AS w' : '') . ' FROM ' . vs_q($t) . ' ORDER BY ' . vs_q($d) . ' DESC LIMIT 4';
                foreach ($pdo->query($q)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $liveAdd('comment', 'New comment: ' . (string)$r['t'], 'By ' . ((string)($r['w'] ?? '') ?: 'Unknown user'), $r['d']);
                }
            } catch (Throwable $e) {}
        }
        foreach (['reports', 'reported', 'reported_content', 'content_reports', 'video_reports'] as $t) { // reports
            try {
                $c = vs_cols($pdo, $t);
                if (!$c) { continue; }
                $st = vs_pick($c, ['status', 'state', 'report_status']);
                $d  = vs_pick($c, ['created_at', 'reported_at', 'created_on', 'date', 'timestamp']);
                $sql = 'SELECT COUNT(*) FROM ' . vs_q($t);
                if ($st) {
                    $sql .= ' WHERE LOWER(CAST(' . vs_q($st) . " AS CHAR)) NOT IN ('resolved','closed','dismissed','reviewed','done','handled','removed','approved','rejected','1')";
                }
                $liveReports = (int)$pdo->query($sql)->fetchColumn();
                if ($d) {
                    foreach ($pdo->query('SELECT ' . vs_q($d) . ' AS d FROM ' . vs_q($t) . ' ORDER BY ' . vs_q($d) . ' DESC LIMIT 4')->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        $liveAdd('report', 'Content reported', 'Waiting in the reports queue', $r['d']);
                    }
                }
                break;
            } catch (Throwable $e) {}
        }
        usort($liveFeed, static fn(array $a, array $b): int => $b['t'] <=> $a['t']);
        $liveFeed = array_slice($liveFeed, 0, 10);
    }
    echo json_encode(['online' => $liveOnline, 'reports' => $liveReports, 'feed' => $liveFeed, 'ts' => time()], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}
