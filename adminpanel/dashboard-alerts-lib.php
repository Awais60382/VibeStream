<?php
/*
 * dashboard-alerts-lib.php  (same folder as index.php)
 *
 * Shared code for the alert system. It only defines functions, it prints nothing.
 * Used by:
 *   dashboard-alerts.php       the JSON endpoint behind the dashboard card and bell
 *   dashboard-alerts-cron.php  the scheduled job that records history and sends Telegram/email
 */

/* ---------- small helpers ---------- */
function vsa_q(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function vsa_cols(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $cols = [];
    try {
        $st = $pdo->query('SELECT * FROM ' . vsa_q($table) . ' LIMIT 0');
        if ($st) {
            for ($i = 0; $i < $st->columnCount(); $i++) {
                $m = $st->getColumnMeta($i);
                if (!empty($m['name'])) {
                    $cols[] = $m['name'];
                }
            }
        }
    } catch (Throwable $e) {
        $cols = [];
    }
    return $cache[$table] = $cols;
}

function vsa_pick(array $cols, array $candidates): ?string
{
    foreach ($candidates as $want) {
        foreach ($cols as $have) {
            if (strcasecmp($want, $have) === 0) {
                return $have;
            }
        }
    }
    return null;
}

function vsa_count(PDO $pdo, string $sql, array $params): int
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function vsa_bytes(float $b): string
{
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u) - 1) {
        $b /= 1024;
        $i++;
    }
    return round($b, $i >= 3 ? 1 : 0) . ' ' . $u[$i];
}

const VSA_SEV_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

/* ---------- the checks ---------- */
/** @return array{alerts: array<int,array>, skipped: array<int,string>} */
function vsa_run_checks(PDO $pdo, string $usersTable = 'users'): array
{
    $alerts = [];
    $skipped = [];
    $now = time();
    $fmt = static function (int $ts): string {
        return date('Y-m-d H:i:s', $ts);
    };
    $add = static function (string $id, string $sev, string $kind, string $title, string $detail) use (&$alerts): void {
        $alerts[] = ['id' => $id, 'sev' => $sev, 'kind' => $kind, 'title' => $title, 'detail' => $detail];
    };
    $dateCandidates = ['created_at', 'joined_at', 'registered_at', 'date_joined', 'created_on', 'reg_date', 'signup_date'];
    $uploadDateCandidates = ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_at'];

    /* 1. Signup spike (last hour vs the previous 7 days) */
    try {
        $uc = vsa_cols($pdo, $usersTable);
        $ud = $uc ? vsa_pick($uc, $dateCandidates) : null;
        if (!$ud) {
            $skipped[] = 'Signup spikes: no signup-date column found in the users table.';
        } else {
            $t = vsa_q($usersTable);
            $d = vsa_q($ud);
            $lastHour = vsa_count($pdo, "SELECT COUNT(*) FROM $t WHERE $d >= :a", [':a' => $fmt($now - 3600)]);
            $before = vsa_count($pdo, "SELECT COUNT(*) FROM $t WHERE $d >= :a AND $d < :b", [':a' => $fmt($now - 8 * 86400), ':b' => $fmt($now - 3600)]);
            $perHour = $before / (7 * 24);
            if ($lastHour >= 5 && $lastHour >= 3 * max($perHour, 0.5)) {
                $crit = $lastHour >= 20 && $lastHour >= 10 * max($perHour, 0.5);
                $add('spike-signups-' . date('YmdH', $now), $crit ? 'critical' : 'warning', 'spike', 'Signup spike',
                    $lastHour . ' new accounts in the last hour. A normal hour is about ' . round($perHour, 1) . '. This can be a promotion, or bots.');
            }
        }
    } catch (Throwable $e) {
        $skipped[] = 'Signup spikes: check failed.';
    }

    /* 2. Uploads dropped or stopped (last 24h vs the daily average of the 7 days before) */
    try {
        $last24 = 0;
        $prev7 = 0;
        $found = 0;
        foreach (['videos', 'shorts'] as $table) {
            $c = vsa_cols($pdo, $table);
            $d = $c ? vsa_pick($c, $uploadDateCandidates) : null;
            if (!$d) {
                continue;
            }
            $found++;
            $t = vsa_q($table);
            $dq = vsa_q($d);
            $last24 += vsa_count($pdo, "SELECT COUNT(*) FROM $t WHERE $dq >= :a", [':a' => $fmt($now - 86400)]);
            $prev7 += vsa_count($pdo, "SELECT COUNT(*) FROM $t WHERE $dq >= :a AND $dq < :b", [':a' => $fmt($now - 8 * 86400), ':b' => $fmt($now - 86400)]);
        }
        if (!$found) {
            $skipped[] = 'Upload drops: no videos or shorts table with an upload-date column found.';
        } else {
            $avg = $prev7 / 7;
            if ($last24 === 0 && $avg >= 3) {
                $add('uploads-low-' . date('Ymd', $now), 'warning', 'uploads', 'Uploads have stopped',
                    'Nothing was uploaded in the last 24 hours. The usual is about ' . round($avg, 1) . ' a day. Check that uploading still works.');
            } elseif ($avg >= 8 && $last24 < $avg * 0.25) {
                $add('uploads-low-' . date('Ymd', $now), 'warning', 'uploads', 'Uploads are far below normal',
                    $last24 . ' uploads in the last 24 hours. The usual is about ' . round($avg) . ' a day.');
            }
        }
    } catch (Throwable $e) {
        $skipped[] = 'Upload drops: check failed.';
    }

    /* 3. Comment spam: the same user posting the same text 3+ times in 24h */
    try {
        $anyComments = false;
        foreach (['comments', 'video_comments', 'short_comments'] as $table) {
            $c = vsa_cols($pdo, $table);
            if (!$c) {
                continue;
            }
            $tx = vsa_pick($c, ['comment', 'content', 'body', 'text', 'message', 'comment_text']);
            $d = vsa_pick($c, ['created_at', 'commented_at', 'date_added', 'created_on', 'date', 'timestamp']);
            $w = vsa_pick($c, ['username', 'user_name', 'author', 'commenter', 'name', 'user_id', 'userid']);
            if (!$tx || !$d) {
                continue;
            }
            $anyComments = true;
            $t = vsa_q($table);
            $txq = 'SUBSTR(' . vsa_q($tx) . ', 1, 120)';
            $sel = ($w ? vsa_q($w) . ' AS w, ' : '') . "$txq AS t, COUNT(*) AS c";
            $grp = ($w ? vsa_q($w) . ', ' : '') . $txq;
            $st = $pdo->prepare("SELECT $sel FROM $t WHERE " . vsa_q($d) . " >= :a AND $txq <> '' GROUP BY $grp HAVING COUNT(*) >= 3 ORDER BY c DESC LIMIT 5");
            $st->execute([':a' => $fmt($now - 86400)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $who = $w ? (string)($r['w'] ?? 'someone') : 'Someone';
                $n = (int)$r['c'];
                $snippet = mb_substr(trim((string)$r['t']), 0, 60);
                $add('spam-c-' . md5($table . $who . $r['t']) . '-' . date('Ymd', $now), $n >= 10 ? 'critical' : 'warning', 'spam',
                    'Possible comment spam',
                    $who . ' posted the same comment ' . $n . ' times in 24 hours: "' . $snippet . '"');
            }
        }
        if (!$anyComments) {
            $skipped[] = 'Comment spam: no comments table with text and date columns found.';
        }
    } catch (Throwable $e) {
        $skipped[] = 'Comment spam: check failed.';
    }

    /* 4. Many accounts created from one IP in 7 days */
    try {
        $uc = vsa_cols($pdo, $usersTable);
        $ud = $uc ? vsa_pick($uc, $dateCandidates) : null;
        $ip = $uc ? vsa_pick($uc, ['signup_ip', 'registration_ip', 'register_ip', 'reg_ip', 'ip_address', 'ip', 'last_ip', 'last_login_ip']) : null;
        if (!$ud || !$ip) {
            $skipped[] = 'Shared-IP signups: the users table needs both a signup-date and an IP column.';
        } else {
            $t = vsa_q($usersTable);
            $ipq = vsa_q($ip);
            $st = $pdo->prepare("SELECT $ipq AS ip, COUNT(*) AS c FROM $t WHERE " . vsa_q($ud) . " >= :a AND $ipq IS NOT NULL AND $ipq <> '' GROUP BY $ipq HAVING COUNT(*) >= 3 ORDER BY c DESC LIMIT 5");
            $st->execute([':a' => $fmt($now - 7 * 86400)]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $n = (int)$r['c'];
                $add('ip-' . md5((string)$r['ip']) . '-' . date('oW', $now), $n >= 10 ? 'critical' : 'warning', 'spam',
                    'Many accounts from one IP',
                    $n . ' accounts were created from ' . $r['ip'] . ' in the last 7 days. Could be bots, or a school or office network.');
            }
        }
    } catch (Throwable $e) {
        $skipped[] = 'Shared-IP signups: check failed.';
    }

    /* 5. Disk space */
    try {
        $free = @disk_free_space(__DIR__);
        $total = @disk_total_space(__DIR__);
        if ($free !== false && $total !== false && $total > 0) {
            $pct = $free / $total * 100;
            if ($pct < 20) {
                $add('disk-low-' . ($pct < 10 ? 'crit' : 'warn'), $pct < 10 ? 'critical' : 'warning', 'disk', 'Disk space is running low',
                    vsa_bytes($free) . ' free of ' . vsa_bytes($total) . ' (' . round($pct, 1) . '%). Uploads will fail when it is full.');
            }
        } else {
            $skipped[] = 'Disk space: the server does not report it.';
        }
    } catch (Throwable $e) {
        $skipped[] = 'Disk space: check failed.';
    }

    /* 6. Reports waiting for review (shown as info; not saved to history, not sent) */
    try {
        $reportsDone = false;
        foreach (['reports', 'reported', 'reported_content', 'content_reports', 'video_reports'] as $table) {
            $c = vsa_cols($pdo, $table);
            if (!$c) {
                continue;
            }
            $reportsDone = true;
            $st = vsa_pick($c, ['status', 'state', 'report_status']);
            $sql = 'SELECT COUNT(*) FROM ' . vsa_q($table);
            if ($st) {
                $sql .= ' WHERE LOWER(CAST(' . vsa_q($st) . " AS CHAR)) NOT IN ('resolved','closed','dismissed','reviewed','done','handled','removed','approved','rejected','1')";
            }
            $open = (int)$pdo->query($sql)->fetchColumn();
            if ($open > 0) {
                $add('reports-' . $open, 'info', 'reports', $open . ' report' . ($open === 1 ? '' : 's') . ' waiting for review',
                    'Open the reports page to approve or dismiss them.');
            }
            break;
        }
        if (!$reportsDone) {
            $skipped[] = 'Reports: no reports table found.';
        }
    } catch (Throwable $e) {
        $skipped[] = 'Reports: check failed.';
    }

    usort($alerts, static function (array $a, array $b): int {
        return VSA_SEV_RANK[$a['sev']] <=> VSA_SEV_RANK[$b['sev']];
    });
    return ['alerts' => $alerts, 'skipped' => $skipped];
}

/* ---------- alert history (table admin_alert_log, created automatically) ---------- */
function vsa_history_ensure(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM admin_alert_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        // not there yet, create it below
    }
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec('CREATE TABLE IF NOT EXISTS admin_alert_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                alert_key VARCHAR(190) NOT NULL,
                sev VARCHAR(12) NOT NULL,
                kind VARCHAR(24) NOT NULL,
                title VARCHAR(190) NOT NULL,
                detail TEXT NOT NULL,
                first_seen DATETIME NOT NULL,
                last_seen DATETIME NOT NULL,
                resolved_at DATETIME NULL,
                notified_at DATETIME NULL,
                KEY idx_alert_open (resolved_at),
                KEY idx_alert_key (alert_key)
            ) DEFAULT CHARSET=utf8mb4');
        } else {
            $pdo->exec('CREATE TABLE IF NOT EXISTS admin_alert_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                alert_key TEXT NOT NULL, sev TEXT NOT NULL, kind TEXT NOT NULL, title TEXT NOT NULL, detail TEXT NOT NULL,
                first_seen TEXT NOT NULL, last_seen TEXT NOT NULL, resolved_at TEXT NULL, notified_at TEXT NULL)');
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_alert_open ON admin_alert_log (resolved_at)');
        }
        $pdo->query('SELECT 1 FROM admin_alert_log LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Save the current alerts: new ones are inserted, ones still firing get last_seen updated,
 * ones that stopped are marked resolved. Info alerts are not saved.
 */
function vsa_record(PDO $pdo, array $alerts): void
{
    $now = date('Y-m-d H:i:s');
    $open = [];
    foreach ($pdo->query('SELECT alert_key, id FROM admin_alert_log WHERE resolved_at IS NULL')->fetchAll(PDO::FETCH_NUM) as $r) {
        $open[(string)$r[0]] = (int)$r[1];
    }
    $upd = $pdo->prepare('UPDATE admin_alert_log SET last_seen = :n, title = :t, detail = :d WHERE id = :id');
    $ins = $pdo->prepare('INSERT INTO admin_alert_log (alert_key, sev, kind, title, detail, first_seen, last_seen) VALUES (:k, :s, :kd, :t, :d, :n, :n2)');
    foreach ($alerts as $a) {
        if ($a['sev'] === 'info') {
            continue;
        }
        if (isset($open[$a['id']])) {
            $upd->execute([':n' => $now, ':t' => mb_substr($a['title'], 0, 190), ':d' => $a['detail'], ':id' => $open[$a['id']]]);
            unset($open[$a['id']]);
        } else {
            $ins->execute([':k' => $a['id'], ':s' => $a['sev'], ':kd' => $a['kind'], ':t' => mb_substr($a['title'], 0, 190), ':d' => $a['detail'], ':n' => $now, ':n2' => $now]);
        }
    }
    $res = $pdo->prepare('UPDATE admin_alert_log SET resolved_at = :n WHERE id = :id');
    foreach ($open as $id) {
        $res->execute([':n' => $now, ':id' => $id]);
    }
    if (mt_rand(1, 50) === 1) { // tidy up: drop resolved entries older than 90 days
        $pdo->prepare('DELETE FROM admin_alert_log WHERE resolved_at IS NOT NULL AND resolved_at < :c')->execute([':c' => date('Y-m-d H:i:s', time() - 90 * 86400)]);
    }
}

function vsa_history(PDO $pdo, int $limit = 40): array
{
    $rows = $pdo->query('SELECT sev, kind, title, detail, first_seen, last_seen, resolved_at, notified_at FROM admin_alert_log ORDER BY first_seen DESC, id DESC LIMIT ' . (int)$limit)->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'sev' => $r['sev'], 'kind' => $r['kind'], 'title' => $r['title'], 'detail' => $r['detail'],
            'first' => strtotime((string)$r['first_seen']) ?: null,
            'last' => strtotime((string)$r['last_seen']) ?: null,
            'resolved' => $r['resolved_at'] ? (strtotime((string)$r['resolved_at']) ?: null) : null,
            'notified' => !empty($r['notified_at']),
        ];
    }
    return $out;
}

/* ---------- notifications (Telegram and email) ---------- */
function vsa_config(): array
{
    $cfg = [
        'telegram_token' => '', 'telegram_chat_id' => '', 'email_to' => '', 'email_from' => '',
        'min_severity' => 'warning', 'site_name' => 'Admin dashboard', 'dashboard_url' => '',
        'telegram_api_base' => 'https://api.telegram.org',
    ];
    $file = __DIR__ . '/dashboard-alerts-config.php';
    if (is_file($file)) {
        $c = include $file;
        if (is_array($c)) {
            $cfg = array_merge($cfg, $c);
        }
    }
    return $cfg;
}

function vsa_channels(array $cfg): array
{
    return [
        'telegram' => $cfg['telegram_token'] !== '' && $cfg['telegram_chat_id'] !== '',
        'email' => $cfg['email_to'] !== '' && filter_var($cfg['email_to'], FILTER_VALIDATE_EMAIL) !== false,
    ];
}

/** @return array{0: bool, 1: string} ok, error */
function vsa_send_telegram(array $cfg, string $text): array
{
    $url = rtrim((string)$cfg['telegram_api_base'], '/') . '/bot' . $cfg['telegram_token'] . '/sendMessage';
    $body = http_build_query(['chat_id' => $cfg['telegram_chat_id'], 'text' => mb_substr($text, 0, 3900), 'disable_web_page_preview' => 'true']);
    $resp = false;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 8]);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/x-www-form-urlencoded\r\n", 'content' => $body, 'timeout' => 8, 'ignore_errors' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        $err = $resp === false ? 'request failed' : '';
    }
    if ($resp === false) {
        return [false, 'Telegram unreachable: ' . $err];
    }
    $j = json_decode((string)$resp, true);
    if (!is_array($j) || empty($j['ok'])) {
        return [false, 'Telegram refused it: ' . (is_array($j) && isset($j['description']) ? $j['description'] : 'unexpected reply')];
    }
    return [true, ''];
}

/** @return array{0: bool, 1: string} */
function vsa_send_email(array $cfg, string $subject, string $text): array
{
    $clean = static function (string $s): string {
        return trim(preg_replace('/[\r\n]+/', ' ', $s));
    };
    $headers = 'Content-Type: text/plain; charset=UTF-8';
    if ($cfg['email_from'] !== '' && filter_var($cfg['email_from'], FILTER_VALIDATE_EMAIL)) {
        $headers = 'From: ' . $cfg['email_from'] . "\r\n" . $headers;
    }
    $ok = @mail($cfg['email_to'], '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=', $text, $headers);
    return [$ok, $ok ? '' : 'mail() failed: this server may not be set up to send email'];
}

function vsa_message(array $cfg, array $a): array
{
    $head = '[' . strtoupper($a['sev']) . '] ' . $a['title'];
    $body = $head . "\n" . $a['detail'] . "\n\n" . $cfg['site_name'] . ($cfg['dashboard_url'] !== '' ? "\n" . $cfg['dashboard_url'] : '');
    return [$cfg['site_name'] . ': ' . $a['title'], $body];
}

/**
 * Send notifications for alerts that are new and still active.
 * Each alert is claimed (notified_at set) before sending so two runs never send it twice;
 * if every channel fails, the claim is released and it is retried on the next run.
 * @return array{sent:int, failed:int, errors:array<int,string>}
 */
function vsa_notify_pending(PDO $pdo, array $cfg): array
{
    $res = ['sent' => 0, 'failed' => 0, 'errors' => []];
    $ch = vsa_channels($cfg);
    if (!$ch['telegram'] && !$ch['email']) {
        return $res;
    }
    $min = VSA_SEV_RANK[$cfg['min_severity']] ?? 1;
    $st = $pdo->prepare('SELECT id, sev, kind, title, detail FROM admin_alert_log WHERE notified_at IS NULL AND resolved_at IS NULL AND first_seen >= :c ORDER BY id');
    $st->execute([':c' => date('Y-m-d H:i:s', time() - 6 * 3600)]);
    $claim = $pdo->prepare('UPDATE admin_alert_log SET notified_at = :n WHERE id = :id AND notified_at IS NULL');
    $release = $pdo->prepare('UPDATE admin_alert_log SET notified_at = NULL WHERE id = :id');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((VSA_SEV_RANK[$row['sev']] ?? 2) > $min) {
            continue;
        }
        $claim->execute([':n' => date('Y-m-d H:i:s'), ':id' => $row['id']]);
        if ($claim->rowCount() !== 1) {
            continue; // another run already took it
        }
        [$subject, $text] = vsa_message($cfg, $row);
        $any = false;
        if ($ch['telegram']) {
            [$ok, $err] = vsa_send_telegram($cfg, $text);
            $any = $any || $ok;
            if (!$ok) { $res['errors'][] = $err; }
        }
        if ($ch['email']) {
            [$ok, $err] = vsa_send_email($cfg, $subject, $text);
            $any = $any || $ok;
            if (!$ok) { $res['errors'][] = $err; }
        }
        if ($any) {
            $res['sent']++;
        } else {
            $res['failed']++;
            $release->execute([':id' => $row['id']]);
        }
    }
    $res['errors'] = array_values(array_unique($res['errors']));
    return $res;
}
