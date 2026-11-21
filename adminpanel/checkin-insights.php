<?php
// Shared helpers: new-device detection for logins, and time-online calculation from check-in history.

if (!function_exists('vs_checkin_ua_family')) {
    // Reduce a user-agent to "Browser / OS" so routine browser version updates are not flagged.
    function vs_checkin_ua_family($ua) {
        $ua = (string)$ua;
        if ($ua === '') return '';
        $browser = 'Other';
        foreach (['Edg' => 'Edge', 'OPR' => 'Opera', 'Firefox' => 'Firefox', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $browser = $v; break; }
        }
        $os = 'Other';
        foreach (['iPhone' => 'iOS', 'iPad' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows',
                  'Macintosh' => 'macOS', 'Mac OS' => 'macOS', 'Linux' => 'Linux'] as $k => $v) {
            if (stripos($ua, $k) !== false) { $os = $v; break; }
        }
        return $browser . ' / ' . $os;
    }
}

if (!function_exists('vs_checkin_annotate_devices')) {
    // Takes history OLDEST FIRST. Adds '_flags' (['New IP', 'New browser']) to each "logged in" event
    // when that admin has not logged in from that IP / browser-OS before. An admin's very first login is never flagged.
    function vs_checkin_annotate_devices(array $events) {
        $seenIp = [];
        $seenUa = [];
        foreach ($events as $i => $e) {
            if (!is_array($e) || ($e['event'] ?? '') !== 'logged in') continue;
            $who = mb_strtolower(trim((string)($e['admin'] ?? '')));
            $ip  = trim((string)($e['ip'] ?? ''));
            $fam = vs_checkin_ua_family($e['ua'] ?? '');
            $flags = [];
            if (isset($seenIp[$who]) || isset($seenUa[$who])) {
                if ($ip !== '' && empty($seenIp[$who][$ip]))  $flags[] = 'New IP';
                if ($fam !== '' && empty($seenUa[$who][$fam])) $flags[] = 'New browser';
            }
            if ($ip !== '')  $seenIp[$who][$ip]  = true;
            if ($fam !== '') $seenUa[$who][$fam] = true;
            $events[$i]['_flags'] = $flags;
        }
        return $events;
    }
}

if (!function_exists('vs_checkin_sessions')) {
    // Builds [admin, startTs, endTs] sessions from history events (any order).
    // A session starts at a check-in / status event and ends at a check-out / offline / idle event,
    // at the admin's next login, or (if nothing ends it) after $cap seconds; an open session runs to "now".
    function vs_checkin_sessions(array $events, $cap = 28800) {
        $now = time();
        $by = [];
        foreach ($events as $e) {
            if (!is_array($e)) continue;
            $ts = strtotime((string)($e['at'] ?? '')) ?: 0;
            if (!$ts) continue;
            $by[trim((string)($e['admin'] ?? ''))][] = ['ts' => $ts, 'event' => mb_strtolower(trim((string)($e['event'] ?? ''))),
                                                        'status' => (string)($e['status'] ?? '')];
        }
        $sessions = [];
        foreach ($by as $who => $list) {
            if ($who === '') continue;
            usort($list, fn($a, $b) => $a['ts'] <=> $b['ts']);
            $start = null;
            foreach ($list as $it) {
                $isEnd = $it['status'] === 'Offline'
                    || preg_match('/check(ed)?[\s_-]*out|offline|idle|expire|timed|log(ged)?[\s_-]*out|forced/', $it['event']);
                $isStart = !$isEnd && preg_match('/check(ed)?[\s_-]*in|status|update/', $it['event']);
                if ($isStart) {
                    if ($start === null) $start = $it['ts'];
                } elseif ($start !== null && ($isEnd || $it['event'] === 'logged in')) {
                    $sessions[] = [$who, $start, min($it['ts'], $start + $cap)];
                    $start = null;
                }
            }
            if ($start !== null) $sessions[] = [$who, $start, min($now, $start + $cap)];
        }
        return $sessions;
    }
}

if (!function_exists('vs_checkin_hours')) {
    // Seconds online per admin per day (Y-m-d) inside [$fromTs, $toTs].
    function vs_checkin_hours(array $sessions, $fromTs, $toTs) {
        $out = [];
        foreach ($sessions as [$who, $s, $e]) {
            $s = max($s, $fromTs);
            $e = min($e, $toTs);
            while ($s < $e) {
                $dayEnd = strtotime('tomorrow', $s);
                $seg = min($e, $dayEnd);
                $d = date('Y-m-d', $s);
                $out[$who][$d] = ($out[$who][$d] ?? 0) + ($seg - $s);
                $s = $seg;
            }
        }
        return $out;
    }
}

if (!function_exists('vs_checkin_fmt_duration')) {
    function vs_checkin_fmt_duration($sec) {
        $sec = (int)$sec;
        if ($sec < 60) return '—';
        $h = intdiv($sec, 3600);
        $m = intdiv($sec % 3600, 60);
        return $h > 0 ? $h . 'h ' . $m . 'm' : $m . 'm';
    }
}

// ---------------------------------------------------------------------------------------------
// Owner notes, failed-login log, online summary, history archive
// ---------------------------------------------------------------------------------------------
if (!function_exists('vs_data_dir')) {
    function vs_data_dir() {
        return defined('VS_CHECKIN_HISTORY') ? dirname(VS_CHECKIN_HISTORY) : __DIR__;
    }
}

// ---- private owner notes (one short note per admin, only the owner's page shows them) ----
if (!function_exists('vs_owner_notes_read')) {
    function vs_owner_notes_read() {
        $p = vs_data_dir() . '/owner-notes.json';
        if (!is_file($p)) return [];
        $d = json_decode((string)@file_get_contents($p), true);
        return is_array($d) ? $d : [];
    }
    function vs_owner_note_save($who, $note) {
        $who = trim((string)$who);
        if ($who === '') return false;
        $all = vs_owner_notes_read();
        $note = trim(mb_substr((string)$note, 0, 200));
        if ($note === '') unset($all[$who]); else $all[$who] = $note;
        return @file_put_contents(vs_data_dir() . '/owner-notes.json',
            json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }
}

// ---- [online, total] admins, owner excluded ----
if (!function_exists('vs_checkin_online_summary')) {
    function vs_checkin_online_summary(array $ownerNames) {
        $online = 0; $total = 0;
        foreach (vs_checkin_all() as $who => $rec) {
            $who = trim((string)$who);
            if ($who === '' || !is_array($rec) || isset($ownerNames[mb_strtolower($who)])) continue;
            $total++;
            if (vs_checkin_effective($rec) !== 'Offline') $online++;
        }
        return [$online, $total];
    }
}

// ---- move history entries older than $days into an archive file (with a .bak of the original) ----
if (!function_exists('vs_checkin_archive_history')) {
    function vs_checkin_archive_history($days) {
        $days = max(7, (int)$days);
        $path = VS_CHECKIN_HISTORY;
        $raw = @file_get_contents($path);
        if ($raw === false) return [false, 'Could not read the history file.'];
        $data = json_decode($raw, true);
        if (!is_array($data)) return [false, 'The history file has an unexpected format. Nothing was changed.'];
        if (!$data) return [true, 0];
        $isList = function_exists('array_is_list') ? array_is_list($data) : (array_keys($data) === range(0, count($data) - 1));
        if (!$isList) return [false, 'The history file has an unexpected format. Nothing was changed.'];
        $cut = time() - $days * 86400;
        $keep = []; $old = [];
        foreach ($data as $e) {
            $ts = is_array($e) ? (strtotime((string)($e['at'] ?? '')) ?: 0) : 0;
            if ($ts && $ts < $cut) $old[] = $e; else $keep[] = $e;
        }
        if (!$old) return [true, 0];
        $arc = dirname($path) . '/checkin-history-archive-' . date('Y-m-d-His') . '.json';
        if (@file_put_contents($arc, json_encode($old, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return [false, 'Could not write the archive file. Nothing was changed.'];
        }
        @copy($path, $path . '.bak');
        if (@file_put_contents($path, json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) {
            return [false, 'Could not update the history file.'];
        }
        return [true, count($old)];
    }
}