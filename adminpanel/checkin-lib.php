<?php
// VibeStream admin check-in: shared helpers
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/task-store.php';
require_once __DIR__ . '/admin-titles-store.php';

define('VS_CHECKIN_DIR',      __DIR__ . '/data');
define('VS_CHECKIN_FILE',     VS_CHECKIN_DIR . '/checkins.json');
define('VS_CHECKIN_HISTORY',  VS_CHECKIN_DIR . '/checkin-history.json');
define('VS_CHECKIN_SETTINGS', VS_CHECKIN_DIR . '/checkin-settings.json');
define('VS_CHECKIN_DAILY',    VS_CHECKIN_DIR . '/checkin-daily.json');
define('VS_CHECKIN_HISTORY_MAX', 500);                       // keep the newest 500 history rows
define('VS_CHECKIN_STATUSES', ['Available', 'Busy', 'Moderating', 'On break', 'Away']);
define('VS_CHECKIN_NOTE_REQUIRED', ['On break', 'Away']);    // these statuses need a note

/** Name of the logged-in admin (VibeStream stores it in $_SESSION['AdminSession']). */
function vs_checkin_admin_name() {
    foreach (['AdminSession', 'admin_username', 'admin_name', 'username'] as $k) {
        if (!empty($_SESSION[$k]) && is_string($_SESSION[$k])) return $_SESSION[$k];
    }
    return null;
}

/** URL path of checkin.php (works under localhost/VibeStream/adminpanel). */
function vs_checkin_url() {
    $root = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\'));
    $dir  = str_replace('\\', '/', __DIR__);
    $rel  = (strpos($dir, $root) === 0) ? substr($dir, strlen($root)) : '/adminpanel';
    return $rel . '/checkin.php';
}

function vs_checkin_json_read($file) {
    if (!is_file($file)) return [];
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** Locked read-modify-write. $fn receives the array and returns the new array. */
function vs_checkin_json_update($file, callable $fn) {
    if (!is_dir(VS_CHECKIN_DIR)) {
        @mkdir(VS_CHECKIN_DIR, 0775, true);
        @file_put_contents(VS_CHECKIN_DIR . '/.htaccess', "Require all denied\n");
    }
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) $data = [];
    $data = $fn($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function vs_checkin_all() { return vs_checkin_json_read(VS_CHECKIN_FILE); }

/** Seconds without any admin page load before someone counts as Offline (owner can change it). */
function vs_checkin_idle() {
    $s = vs_checkin_json_read(VS_CHECKIN_SETTINGS);
    $m = (int)($s['idle_minutes'] ?? 30);
    return max(5, min(240, $m)) * 60;
}

function vs_checkin_set_idle_minutes($minutes) {
    $minutes = max(5, min(240, (int)$minutes));
    return vs_checkin_json_update(VS_CHECKIN_SETTINGS, function ($s) use ($minutes) {
        $s['idle_minutes'] = $minutes;
        return $s;
    });
}

/** Status to show for a stored record: "Offline" when the admin has been gone too long. */
function vs_checkin_effective(array $r) {
    if (!empty($r['checked_out']) || empty($r['checked_in'])) return 'Offline';
    $seen = strtotime($r['seen'] ?? $r['at'] ?? '') ?: 0;
    if ($seen <= 0 || time() - $seen > vs_checkin_idle()) return 'Offline';
    return (string)($r['status'] ?? 'Offline');
}

function vs_checkin_online_count() {
    $n = 0;
    foreach (vs_checkin_all() as $r) if (vs_checkin_effective($r) !== 'Offline') $n++;
    return $n;
}

function vs_checkin_color($status) {
    $map = ['Available' => '#2ecc71', 'Busy' => '#e74c3c', 'Moderating' => '#3ea6ff',
            'On break' => '#f39c12', 'Away' => '#95a5a6', 'Offline' => '#555555'];
    return $map[$status] ?? '#95a5a6';
}

/** Has this admin checked in during the current visit (not away too long, not forced to re-check)? */
function vs_checkin_active($name) {
    $rec = vs_checkin_all()[$name] ?? null;
    if (!$rec || empty($rec['checked_in']) || !empty($rec['checked_out'])) return false;
    if (vs_checkin_effective($rec) === 'Offline') return false;
    if ($rec && !empty($rec['recheck']) && (int)$rec['recheck'] > (int)($_SESSION['vs_checkin_ts'] ?? 0)) return false;

    // A server-side check-in remains active until explicit checkout or forced re-check.
    if (empty($_SESSION['vs_checkin']) || ($_SESSION['vs_checkin_for'] ?? '') !== $name) {
        $_SESSION['vs_checkin'] = [
            'status' => (string)($rec['status'] ?? 'Available'),
            'note' => (string)($rec['note'] ?? ''),
            'at' => (string)($rec['at'] ?? ''),
        ];
        $_SESSION['vs_checkin_for'] = $name;
        $_SESSION['vs_checkin_ts'] = strtotime((string)($rec['at'] ?? '')) ?: time();
        $_SESSION['vs_seen'] = time();
    }
    return true;
}

function vs_checkin_log($admin, $event, $status, $note): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160);
    $saved = vs_checkin_json_update(VS_CHECKIN_HISTORY, function ($rows) use ($admin, $event, $status, $note, $ip, $ua) {
        $rows[] = ['admin' => $admin, 'event' => $event, 'status' => $status, 'note' => $note,
                   'at' => date('Y-m-d H:i:s'), 'ip' => $ip, 'ua' => $ua];
        return array_slice($rows, -VS_CHECKIN_HISTORY_MAX);
    });
    if (!$saved) {
        error_log('Admin check-in history could not be saved.');
    }
    return $saved;
}

/** Record one sign-in event for this admin session. */
function vs_checkin_log_login_once(string $admin): void {
    if ($admin === '' || !empty($_SESSION['vs_checkin_login_logged'])) return;
    if (vs_checkin_log($admin, 'logged in', 'Online', '')) {
        $_SESSION['vs_checkin_login_logged'] = true;
    }
}

function vs_checkin_save($name, $status, $note, bool $taskBusy = false) {
    $event = vs_checkin_active($name) ? 'changed' : 'checked in';
    $now = date('Y-m-d H:i:s');
    $ok = vs_checkin_json_update(VS_CHECKIN_FILE, function ($data) use ($name, $status, $note, $now, $taskBusy) {
        $data[$name] = ['status' => $status, 'note' => $note, 'at' => $now, 'seen' => $now, 'checked_in' => 1, 'checked_out' => 0];
        if ($taskBusy) {
            $data[$name]['task_busy'] = 1;
        }
        return $data;
    });
    if (!$ok) return false;
    vs_checkin_log($name, $event, $status, $note);
    $_SESSION['vs_checkin']     = ['status' => $status, 'note' => $note, 'at' => $now];
    $_SESSION['vs_checkin_for'] = $name;
    $_SESSION['vs_checkin_ts']  = time();
    $_SESSION['vs_seen']        = time();
    $_SESSION['vs_touch_at']    = time();
    return true;
}

/** Explicitly end an admin's check-in. Logout and idle activity do not call this function. */
function vs_checkin_checkout($name) {
    if ($name === '') return false;
    $ok = vs_checkin_json_update(VS_CHECKIN_FILE, function ($data) use ($name) {
        if (!isset($data[$name])) return $data;
        $data[$name]['checked_in'] = 0;
        $data[$name]['checked_out'] = 1;
        $data[$name]['status'] = 'Offline';
        $data[$name]['seen'] = date('Y-m-d H:i:s', 0);
        unset($data[$name]['recheck']);
        return $data;
    });
    if (!$ok) return false;
    unset($_SESSION['vs_checkin'], $_SESSION['vs_checkin_for'], $_SESSION['vs_checkin_ts'], $_SESSION['vs_seen'], $_SESSION['vs_touch_at']);
    return vs_checkin_log($name, 'checked out', 'Offline', '');
}

/** Owner action: make an admin check in again on their next page load. */
function vs_checkin_force($target, $by) {
    $found = false;
    vs_checkin_json_update(VS_CHECKIN_FILE, function ($data) use ($target, &$found) {
        if (isset($data[$target])) { $data[$target]['recheck'] = time(); $found = true; }
        return $data;
    });
    if ($found) vs_checkin_log($target, 'forced to re-check-in by ' . $by, 'Offline', '');
    return $found;
}

/** Add active seconds to today's total for an admin (keeps 31 days). */
function vs_checkin_daily_add($name, $secs) {
    $day = date('Y-m-d');
    vs_checkin_json_update(VS_CHECKIN_DAILY, function ($d) use ($name, $day, $secs) {
        $d[$day][$name] = (int)($d[$day][$name] ?? 0) + (int)$secs;
        ksort($d);
        return array_slice($d, -31, null, true);
    });
}

/** Heartbeat: called on every admin page load so the team list knows who is online. */
function vs_checkin_touch($name) {
    $now  = time();
    $prev = (int)($_SESSION['vs_touch_at'] ?? 0);
    $_SESSION['vs_seen'] = $now;
    if ($now - $prev < 60) return;                       // write at most once a minute
    $_SESSION['vs_touch_at'] = $now;
    $delta = $prev > 0 ? min($now - $prev, 300) : 0;     // never count more than 5 min per gap
    vs_checkin_json_update(VS_CHECKIN_FILE, function ($data) use ($name) {
        if (isset($data[$name])) $data[$name]['seen'] = date('Y-m-d H:i:s');
        return $data;
    });
    if ($delta > 0) vs_checkin_daily_add($name, $delta);
}

/** OPTIONAL: call from logout.php (before session_destroy) to show "Offline" instantly. */
function vs_checkin_mark_offline() {
    $name = vs_checkin_admin_name();
    if (!$name) return;
    vs_checkin_json_update(VS_CHECKIN_FILE, function ($data) use ($name) {
        if (isset($data[$name])) $data[$name]['seen'] = date('Y-m-d H:i:s', 0);
        return $data;
    });
    vs_checkin_log($name, 'logged out', 'Offline', '');
    unset($_SESSION['vs_checkin'], $_SESSION['vs_checkin_for'], $_SESSION['vs_checkin_ts'], $_SESSION['vs_seen']);
}