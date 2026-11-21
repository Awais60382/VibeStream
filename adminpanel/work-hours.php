<?php
// work-hours.php  (place in /VibeStream/adminpanel/)
// Expected working hours per admin, plus on-time / late / left-early / absent checks.
// Stored in adminpanel/data/work-hours.json. Times use the server's time zone.

if (!function_exists('vs_wh_file')) {
    function vs_wh_file(): string { return __DIR__ . '/data/work-hours.json'; }

    function vs_wh_read(): array {
        $f = vs_wh_file();
        if (!is_file($f)) return ['admins' => []];
        $d = json_decode((string)@file_get_contents($f), true);
        if (!is_array($d) || !is_array($d['admins'] ?? null)) return ['admins' => []];
        return $d;
    }

    function vs_wh_update(callable $change): bool {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n", LOCK_EX);
        $h = @fopen(vs_wh_file(), 'c+');
        if ($h === false) return false;
        try {
            if (!flock($h, LOCK_EX)) return false;
            rewind($h);
            $raw = stream_get_contents($h);
            $st = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
            if (!is_array($st)) return false;
            if (!is_array($st['admins'] ?? null)) $st['admins'] = [];
            $new = $change($st);
            if (!is_array($new)) return false;
            $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || !ftruncate($h, 0) || !rewind($h)) return false;
            return fwrite($h, $json . PHP_EOL) !== false && fflush($h);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    // Empty start AND end removes the schedule. $days uses ISO numbers: 1 = Monday ... 7 = Sunday.
    function vs_wh_save(string $name, string $start, string $end, array $days, int $grace = 10): bool {
        $name = trim($name);
        if ($name === '') return false;
        $key = mb_strtolower($name);
        $start = trim($start);
        $end = trim($end);
        if ($start === '' && $end === '') {
            return vs_wh_update(function (array $st) use ($key): array { unset($st['admins'][$key]); return $st; });
        }
        $okTime = fn($t) => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) === 1;
        if (!$okTime($start) || !$okTime($end)) return false;
        $days = array_values(array_unique(array_filter(array_map('intval', $days), fn($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if (!$days) $days = [1, 2, 3, 4, 5];
        $grace = max(0, min(120, $grace));
        return vs_wh_update(function (array $st) use ($key, $name, $start, $end, $days, $grace): array {
            $st['admins'][$key] = ['name' => mb_substr($name, 0, 100), 'start' => $start, 'end' => $end, 'days' => $days, 'grace' => $grace];
            return $st;
        });
    }

    // $sessions: [[admin, startTs, endTs], ...] for ONE admin (from vs_checkin_sessions).
    // Returns ['state' => off|future|pending|notin|absent|ok|flag, 'late' => min, 'early' => min, ...]
    function vs_wh_day_status(array $sched, array $sessions, string $ymd, int $now): array {
        $dayStart = strtotime($ymd . ' 00:00:00');
        if (!$dayStart) return ['state' => 'off'];
        if (!in_array((int)date('N', $dayStart), array_map('intval', $sched['days'] ?? []), true)) return ['state' => 'off'];
        if ($ymd > date('Y-m-d', $now)) return ['state' => 'future'];
        $dayEnd = $dayStart + 86400;
        $ss = strtotime($ymd . ' ' . $sched['start']);
        $se = strtotime($ymd . ' ' . $sched['end']);
        if ($se <= $ss) $se += 86400;
        $grace = max(0, (int)($sched['grace'] ?? 10)) * 60;
        $first = null;
        $last = 0;
        foreach ($sessions as $sess) {
            $s = max((int)$sess[1], $dayStart);
            $e = min((int)$sess[2], $dayEnd);
            if ($e <= $s) continue;
            $first = $first === null ? $s : min($first, $s);
            $last = max($last, $e);
        }
        if ($first === null) {
            if ($now < $ss + $grace) return ['state' => 'pending'];
            if ($now < $se) return ['state' => 'notin', 'expected' => $sched['start']];
            return ['state' => 'absent'];
        }
        $late  = $first > $ss + $grace ? (int)round(($first - $ss) / 60) : 0;
        $early = ($now >= $se && $last < $se - $grace) ? (int)round(($se - $last) / 60) : 0;
        return ['state' => ($late || $early) ? 'flag' : 'ok', 'late' => $late, 'early' => $early];
    }

    function vs_wh_fmt_min(int $m): string {
        return $m >= 60 ? intdiv($m, 60) . 'h ' . ($m % 60) . 'm' : $m . 'm';
    }

    function vs_wh_label(array $st): string {
        switch ($st['state'] ?? '') {
            case 'notin':  return 'Not in yet · expected ' . ($st['expected'] ?? '');
            case 'absent': return 'Absent today';
            case 'flag':
                $parts = [];
                if (!empty($st['late']))  $parts[] = 'Arrived ' . vs_wh_fmt_min((int)$st['late']) . ' late';
                if (!empty($st['early'])) $parts[] = 'Left ' . vs_wh_fmt_min((int)$st['early']) . ' early';
                return implode(' · ', $parts);
        }
        return '';
    }
}
