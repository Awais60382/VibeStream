<?php
// admin-extras.php  (place in /VibeStream/adminpanel/)
// Small shared helpers: JSON store in adminpanel/data/, audit log, time-off requests, task due dates.

if (!function_exists('vs_x_read')) {
    function vs_x_path(string $name): string { return __DIR__ . '/data/' . $name; }

    function vs_x_read(string $name): array {
        $f = vs_x_path($name);
        if (!is_file($f)) return [];
        $d = json_decode((string)@file_get_contents($f), true);
        return is_array($d) ? $d : [];
    }

    function vs_x_update(string $name, callable $change): bool {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n", LOCK_EX);
        $h = @fopen(vs_x_path($name), 'c+');
        if ($h === false) return false;
        try {
            if (!flock($h, LOCK_EX)) return false;
            rewind($h);
            $raw = stream_get_contents($h);
            $st = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
            if (!is_array($st)) return false;             // never overwrite a file we cannot read
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
}

// ---------------------------------------------------------------- audit log
if (!function_exists('vs_audit_log')) {
    function vs_audit_log(string $actor, string $action, string $target = '', string $detail = ''): void {
        try {
            vs_x_update('audit-log.json', function (array $list) use ($actor, $action, $target, $detail): array {
                $list[] = [
                    'at'     => date('Y-m-d H:i:s'),
                    'actor'  => mb_substr($actor, 0, 100),
                    'action' => mb_substr($action, 0, 100),
                    'target' => mb_substr($target, 0, 100),
                    'detail' => mb_substr($detail, 0, 300),
                    'ip'     => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                ];
                return array_slice(array_values($list), -2000);
            });
        } catch (Throwable $e) {
            error_log('audit log failed: ' . $e->getMessage());
        }
    }
    function vs_audit_read(): array { return array_values(vs_x_read('audit-log.json')); }
}

// ---------------------------------------------------------------- time off
if (!function_exists('vs_timeoff_request')) {
    // Returns [ok, message].
    function vs_timeoff_request(string $admin, string $from, string $to, string $reason): array {
        $admin = trim($admin);
        $from = trim($from);
        $to = trim($to);
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if ($admin === '' || !preg_match($re, $from) || !preg_match($re, $to)) return [false, 'Pick a valid start and end date.'];
        if ($from < date('Y-m-d')) return [false, 'The start date cannot be in the past.'];
        if ($to < $from) return [false, 'The end date must be on or after the start date.'];
        if ((strtotime($to) - strtotime($from)) / 86400 + 1 > 31) return [false, 'A single request can cover at most 31 days.'];
        $key = mb_strtolower($admin);
        $pending = 0;
        foreach (vs_x_read('time-off.json') as $r) {
            if (is_array($r) && ($r['status'] ?? '') === 'pending' && mb_strtolower((string)($r['admin'] ?? '')) === $key) $pending++;
        }
        if ($pending >= 5) return [false, 'You already have 5 requests waiting. Please wait for a decision.'];
        $id = bin2hex(random_bytes(8));
        $reason = trim(mb_substr($reason, 0, 140));
        $ok = vs_x_update('time-off.json', function (array $all) use ($id, $admin, $from, $to, $reason): array {
            $all[$id] = ['id' => $id, 'admin' => mb_substr($admin, 0, 100), 'from' => $from, 'to' => $to, 'reason' => $reason,
                         'status' => 'pending', 'requested_at' => date('Y-m-d H:i:s'), 'decided_by' => '', 'decided_at' => ''];
            return $all;
        });
        return $ok ? [true, ''] : [false, 'Could not save your request.'];
    }

    function vs_timeoff_decide(string $id, bool $approve, string $by): bool {
        $changed = false;
        $saved = vs_x_update('time-off.json', function (array $all) use ($id, $approve, $by, &$changed): array {
            if (!isset($all[$id]) || !is_array($all[$id]) || ($all[$id]['status'] ?? '') !== 'pending') return $all;
            $all[$id]['status'] = $approve ? 'approved' : 'denied';
            $all[$id]['decided_by'] = mb_substr($by, 0, 100);
            $all[$id]['decided_at'] = date('Y-m-d H:i:s');
            $changed = true;
            return $all;
        });
        return $saved && $changed;
    }

    // lower-case admin name => [Y-m-d => true] for every approved day off
    function vs_timeoff_map(): array {
        $map = [];
        foreach (vs_x_read('time-off.json') as $r) {
            if (!is_array($r) || ($r['status'] ?? '') !== 'approved') continue;
            $key = mb_strtolower(trim((string)($r['admin'] ?? '')));
            $t = strtotime((string)($r['from'] ?? ''));
            $end = strtotime((string)($r['to'] ?? ''));
            if ($key === '' || !$t || !$end) continue;
            for ($n = 0; $t <= $end && $n < 32; $n++, $t = strtotime('+1 day', $t)) $map[$key][date('Y-m-d', $t)] = true;
        }
        return $map;
    }
}

// ---------------------------------------------------------------- task due dates
if (!function_exists('vs_task_apply_due')) {
    // Call right after vs_task_assign(): finds that admin's newest open task with this title and sets due_at.
    function vs_task_apply_due(string $admin, string $title, string $dueInput): bool {
        $dueInput = trim($dueInput);
        if ($dueInput === '') return true;
        $ts = strtotime(str_replace('T', ' ', $dueInput));
        if (!$ts) return false;
        $bestId = null;
        $bestAt = '';
        foreach (vs_task_all() as $id => $t) {
            if (!is_array($t) || ($t['status'] ?? '') !== 'open') continue;
            if (mb_strtolower(trim((string)($t['admin'] ?? ''))) !== mb_strtolower(trim($admin))) continue;
            if (trim((string)($t['title'] ?? '')) !== trim($title)) continue;
            $at = (string)($t['assigned_at'] ?? '');
            if ($bestId === null || $at >= $bestAt) { $bestId = (string)$id; $bestAt = $at; }
        }
        if ($bestId === null) return false;
        return vs_task_update(function (array $tasks) use ($bestId, $ts): array {
            if (isset($tasks[$bestId]) && is_array($tasks[$bestId])) $tasks[$bestId]['due_at'] = date('Y-m-d H:i:s', $ts);
            return $tasks;
        });
    }
}
