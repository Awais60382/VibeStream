<?php
// break-control.php  (place in /VibeStream/adminpanel/)
// Forced breaks: after N minutes of work (default 120) an admin / CEO is signed out and must wait
// M minutes (default 60) before signing in again.
//
// How the work clock runs: it starts at the first admin page load after a break (or after a gap as long
// as a break) and is NOT reset by signing out and back in. When it reaches the limit, the break starts.

require_once __DIR__ . '/admin-extras.php';

if (!function_exists('vs_break_read')) {
    function vs_break_read(): array {
        $d = vs_x_read('break-state.json');
        $set = is_array($d['settings'] ?? null) ? $d['settings'] : [];
        $set = $set + ['enabled' => true, 'work_minutes' => 120, 'break_minutes' => 60, 'include_owner' => true];
        return ['settings' => $set, 'admins' => is_array($d['admins'] ?? null) ? $d['admins'] : []];
    }

    function vs_break_set_settings(bool $enabled, int $work, int $break, bool $includeOwner): bool {
        $work = max(15, min(720, $work));
        $break = max(5, min(240, $break));
        return vs_x_update('break-state.json', function (array $d) use ($enabled, $work, $break, $includeOwner): array {
            $d['settings'] = ['enabled' => $enabled, 'work_minutes' => $work, 'break_minutes' => $break, 'include_owner' => $includeOwner];
            if (!is_array($d['admins'] ?? null)) $d['admins'] = [];
            return $d;
        });
    }

    // Call on every admin page request (after you know who the admin is). Signs the admin out and
    // redirects to break.php when a break is due or still running. Fails open if storage is unavailable.
    function vs_break_enforce(string $admin, bool $isOwner = false): void {
        $cfg = vs_break_read();
        $set = $cfg['settings'];
        if (empty($set['enabled'])) return;
        if ($isOwner && empty($set['include_owner'])) return;
        $key = mb_strtolower(trim($admin));
        if ($key === '') return;

        $now = time();
        $workSec = (int)$set['work_minutes'] * 60;
        $breakSec = (int)$set['break_minutes'] * 60;
        $result = 'ok';
        $until = 0;

        $saved = vs_x_update('break-state.json', function (array $d) use ($key, $admin, $now, $workSec, $breakSec, &$result, &$until): array {
            if (!is_array($d['admins'] ?? null)) $d['admins'] = [];
            $rec = is_array($d['admins'][$key] ?? null) ? $d['admins'][$key] : ['block_start' => 0, 'last_seen' => 0, 'break_until' => 0];
            if ((int)($rec['break_until'] ?? 0) > $now) {            // break still running
                $result = 'break';
                $until = (int)$rec['break_until'];
                return $d;
            }
            if ((int)($rec['break_until'] ?? 0) > 0) {                // break just finished: fresh work block
                $rec['break_until'] = 0;
                $rec['block_start'] = $now;
            }
            if ((int)($rec['last_seen'] ?? 0) > 0 && $now - (int)$rec['last_seen'] >= $breakSec) {
                $rec['block_start'] = $now;                           // away for a full break already
            }
            if (empty($rec['block_start'])) $rec['block_start'] = $now;
            if ($now - (int)$rec['block_start'] >= $workSec) {        // work limit reached: start the break
                $rec['break_until'] = $now + $breakSec;
                $rec['block_start'] = 0;
                $result = 'start';
                $until = (int)$rec['break_until'];
            }
            $rec['last_seen'] = $now;
            $rec['name'] = mb_substr($admin, 0, 100);
            $d['admins'][$key] = $rec;
            return $d;
        });
        if (!$saved || $result === 'ok') return;

        if ($result === 'start') {
            try {
                if (!$isOwner && function_exists('vs_checkin_checkout')) vs_checkin_checkout($admin);
                if (function_exists('vs_checkin_log')) vs_checkin_log($admin, 'break started', 'Offline', 'Work limit of ' . $set['work_minutes'] . ' minutes reached');
                vs_audit_log($admin, 'break started', $admin, 'after ' . $set['work_minutes'] . ' min, for ' . $set['break_minutes'] . ' min');
            } catch (Throwable $e) {
                error_log('break-control: ' . $e->getMessage());
            }
        }

        $card = ['name' => $admin, 'until' => $until, 'work' => (int)$set['work_minutes'], 'break' => (int)$set['break_minutes']];
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION = [];                                               // signed out
        $_SESSION['vs_break_card'] = $card;
        if (!headers_sent()) @session_regenerate_id(true);
        $url = function_exists('vs_checkin_url') ? dirname(vs_checkin_url()) . '/break.php' : 'break.php';
        if (!headers_sent()) header('Location: ' . $url);
        exit;
    }
}
