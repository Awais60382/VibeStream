<?php
// device-approval.php  (place in /VibeStream/adminpanel/)
// Optional owner approval for admins signing in from a new browser/OS.
// State lives in adminpanel/data/device-approval.json (that folder is already protected by .htaccess).
//   - An admin's FIRST device is trusted automatically.
//   - Later new browser/OS combinations are recorded as trusted while the setting is OFF (the default),
//     and held for the owner's approval while it is ON.

if (!function_exists('vs_checkin_ua_family')) {
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

if (!function_exists('vs_dev_file')) {
    function vs_dev_file(): string { return __DIR__ . '/data/device-approval.json'; }

    function vs_dev_defaults(array $st): array {
        $st['enabled'] = !empty($st['enabled']);
        $st['known']   = is_array($st['known'] ?? null) ? $st['known'] : [];
        $st['pending'] = is_array($st['pending'] ?? null) ? $st['pending'] : [];
        return $st;
    }

    function vs_dev_read(): array {
        $f = vs_dev_file();
        if (!is_file($f)) return vs_dev_defaults([]);
        $d = json_decode((string)@file_get_contents($f), true);
        return vs_dev_defaults(is_array($d) ? $d : []);
    }

    function vs_dev_update(callable $change): bool {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return false;
        $deny = $dir . '/.htaccess';
        if (!is_file($deny)) @file_put_contents($deny, "Require all denied\n", LOCK_EX);
        $h = @fopen(vs_dev_file(), 'c+');
        if ($h === false) return false;
        try {
            if (!flock($h, LOCK_EX)) return false;
            rewind($h);
            $raw = stream_get_contents($h);
            $st = ($raw === false || trim($raw) === '') ? [] : json_decode($raw, true);
            if (!is_array($st)) return false;            // never overwrite a file we cannot read
            $new = $change(vs_dev_defaults($st));
            if (!is_array($new)) return false;
            $json = json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || !ftruncate($h, 0) || !rewind($h)) return false;
            return fwrite($h, $json . PHP_EOL) !== false && fflush($h);
        } finally {
            flock($h, LOCK_UN);
            fclose($h);
        }
    }

    function vs_dev_set_enabled(bool $on): bool {
        return vs_dev_update(function (array $st) use ($on): array { $st['enabled'] = $on; return $st; });
    }

    // Called by login-handler.php after the password is correct. Returns 'ok', 'pending' or 'denied'.
    function vs_dev_check(string $admin, string $ua, string $ip, $pdo = null): string {
        $key = mb_strtolower(trim($admin));
        if ($key === '') return 'ok';
        $fam = vs_checkin_ua_family($ua) ?: 'Unknown';
        $now = date('Y-m-d H:i:s');
        $result = 'ok';
        $created = false;
        $saved = vs_dev_update(function (array $st) use ($key, $admin, $fam, $ua, $ip, $now, &$result, &$created): array {
            $known = $st['known'][$key] ?? [];
            $remember = function () use (&$st, $key, $admin, $fam, $ip, $now) {
                $old = $st['known'][$key][$fam] ?? [];
                $st['known'][$key][$fam] = ['admin' => $admin, 'first' => $old['first'] ?? $now, 'last' => $now, 'ip' => $ip];
            };
            if (isset($known[$fam]) || !$known || !$st['enabled']) {
                $remember();                      // known device, first device, or approval switched off
                return $st;
            }
            $id = hash('sha256', $key . '|' . $fam);
            $p = $st['pending'][$id] ?? null;
            if (is_array($p) && ($p['status'] ?? '') === 'denied') {
                $st['pending'][$id]['last'] = $now;
                $result = 'denied';
                return $st;
            }
            if (!is_array($p)) {
                $st['pending'][$id] = ['admin' => $admin, 'family' => $fam, 'ip' => $ip, 'ua' => mb_substr($ua, 0, 200),
                                       'first' => $now, 'last' => $now, 'status' => 'pending'];
                $created = true;
            } else {
                $st['pending'][$id]['last'] = $now;
                $st['pending'][$id]['ip'] = $ip;
            }
            $result = 'pending';
            return $st;
        });
        if (!$saved) return 'ok';                 // storage trouble must never lock everyone out
        if ($created && $pdo instanceof PDO && function_exists('vs_admin_alerts_ensure') && function_exists('vs_admin_alert_add')) {
            try {
                vs_admin_alerts_ensure($pdo);
                vs_admin_alert_add($pdo, 'device-approval-' . hash('sha256', $key . '|' . $fam . '|' . $now),
                    'device_approval', 'New device waiting for approval',
                    $admin . ' tried to sign in from ' . $fam . ' (IP ' . ($ip !== '' ? $ip : 'unknown') . ').',
                    'checkin.php#team');
            } catch (Throwable $e) {
                error_log('device-approval alert failed: ' . $e->getMessage());
            }
        }
        return $result;
    }

    function vs_dev_approve(string $id): bool {
        $ok = false;
        $saved = vs_dev_update(function (array $st) use ($id, &$ok): array {
            $p = $st['pending'][$id] ?? null;
            if (!is_array($p)) return $st;
            $key = mb_strtolower(trim((string)$p['admin']));
            $now = date('Y-m-d H:i:s');
            $st['known'][$key][(string)$p['family']] = ['admin' => $p['admin'], 'first' => $now, 'last' => $now, 'ip' => $p['ip'] ?? ''];
            unset($st['pending'][$id]);
            $ok = true;
            return $st;
        });
        return $saved && $ok;
    }

    function vs_dev_deny(string $id): bool {
        $ok = false;
        $saved = vs_dev_update(function (array $st) use ($id, &$ok): array {
            if (!isset($st['pending'][$id]) || !is_array($st['pending'][$id])) return $st;
            $st['pending'][$id]['status'] = 'denied';
            $ok = true;
            return $st;
        });
        return $saved && $ok;
    }
}
