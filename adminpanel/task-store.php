<?php

if (!function_exists('vs_task_file')) {
    function vs_task_file(): string
    {
        return __DIR__ . '/data/tasks.json';
    }
}

if (!function_exists('vs_task_all')) {
    function vs_task_all(): array
    {
        $file = vs_task_file();
        if (!is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            error_log('Could not open admin tasks file for reading.');
            return [];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                error_log('Could not lock admin tasks file for reading.');
                return [];
            }
            $contents = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            if ($contents === false || trim($contents) === '') {
                return [];
            }
            $tasks = json_decode($contents, true);
            if (!is_array($tasks)) {
                error_log('Admin tasks file contains invalid JSON.');
                return [];
            }
            return $tasks;
        } finally {
            fclose($handle);
        }
    }
}

if (!function_exists('vs_task_update')) {
    function vs_task_update(callable $change): bool
    {
        $directory = __DIR__ . '/data';
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('Could not create admin data directory for tasks.');
            return false;
        }

        $denyFile = $directory . '/.htaccess';
        if (!is_file($denyFile) && file_put_contents($denyFile, "Require all denied\n", LOCK_EX) === false) {
            error_log('Could not protect admin data directory.');
            return false;
        }

        $handle = @fopen(vs_task_file(), 'c+');
        if ($handle === false) {
            error_log('Could not open admin tasks file for writing.');
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                error_log('Could not lock admin tasks file for writing.');
                return false;
            }
            rewind($handle);
            $contents = stream_get_contents($handle);
            $tasks = $contents === false || trim($contents) === '' ? [] : json_decode($contents, true);
            if (!is_array($tasks)) {
                error_log('Admin tasks file contains invalid JSON; refusing to overwrite it.');
                return false;
            }

            $updated = $change($tasks);
            if (!is_array($updated)) {
                error_log('Admin task update returned invalid data.');
                return false;
            }
            $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false || !ftruncate($handle, 0) || !rewind($handle)) {
                error_log('Could not prepare admin tasks file for writing.');
                return false;
            }
            $written = fwrite($handle, $json . PHP_EOL);
            if ($written === false || $written !== strlen($json . PHP_EOL) || !fflush($handle)) {
                error_log('Could not write admin tasks file completely.');
                return false;
            }
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

if (!function_exists('vs_task_assign')) {
    function vs_task_assign(string $admin, string $title, string $details, string $assignedBy, float $reward = 0): bool
    {
        $admin = trim($admin);
        $title = trim($title);
        $assignedBy = trim($assignedBy);
        if ($admin === '' || $title === '' || $assignedBy === '' || !is_finite($reward) || $reward < 0) {
            return false;
        }

        $id = bin2hex(random_bytes(16));
        $task = [
            'id' => $id,
            'admin' => mb_substr($admin, 0, 100),
            'title' => mb_substr($title, 0, 100),
            'details' => mb_substr(trim($details), 0, 500),
            'assigned_by' => mb_substr($assignedBy, 0, 100),
            'assigned_at' => date('Y-m-d H:i:s'),
            'status' => 'open',
            'reward' => round($reward, 2),
            'closed_by' => '',
            'closed_at' => '',
        ];

        $saved = vs_task_update(static function (array $tasks) use ($id, $task): array {
            $tasks[$id] = $task;
            return $tasks;
        });
        if (!$saved) {
            return false;
        }
        vs_task_set_busy($admin, (string)$task['title']);
        return true;
    }
}

if (!function_exists('vs_task_note')) {
    function vs_task_note(string $title): string
    {
        return mb_substr('Working on: ' . $title, 0, 140);
    }
}

if (!function_exists('vs_task_set_busy')) {
    function vs_task_set_busy(string $admin, string $title): bool
    {
        if (!function_exists('vs_checkin_json_update') || !function_exists('vs_checkin_log')) {
            error_log('Could not update check-in state for assigned task: check-in helpers are unavailable.');
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $note = vs_task_note($title);
        $updated = vs_checkin_json_update(VS_CHECKIN_FILE, static function (array $checkins) use ($admin, $now, $note): array {
            $record = $checkins[$admin] ?? [
                'handoff' => '',
                'return_at' => '',
                'return_timezone' => '',
                'seen' => date('Y-m-d H:i:s', 0),
                'is_owner' => 0,
            ];
            $record['status'] = 'Busy';
            $record['note'] = $note;
            $record['at'] = $now;
            $record['task_busy'] = 1;
            $checkins[$admin] = $record;
            return $checkins;
        });
        if (!$updated) {
            error_log('Could not mark admin busy after task assignment.');
            return false;
        }
        vs_checkin_log($admin, 'task assigned', 'Busy', $note);
        return true;
    }
}

if (!function_exists('vs_task_finish')) {
    function vs_task_finish(string $id, string $closedBy, string $status = 'done'): bool
    {
        $id = trim($id);
        $closedBy = trim($closedBy);
        if ($id === '' || $closedBy === '' || !in_array($status, ['done', 'cancelled'], true)) {
            return false;
        }

        $finishedTask = null;
        $updated = vs_task_update(static function (array $tasks) use ($id, $closedBy, $status, &$finishedTask): array {
            if (!isset($tasks[$id]) || !is_array($tasks[$id]) || ($tasks[$id]['status'] ?? '') !== 'open') {
                return $tasks;
            }
            $tasks[$id]['status'] = $status;
            $tasks[$id]['closed_by'] = mb_substr($closedBy, 0, 100);
            $tasks[$id]['closed_at'] = date('Y-m-d H:i:s');
            $finishedTask = $tasks[$id];
            return $tasks;
        });
        if (!$updated || $finishedTask === null) {
            return false;
        }

        $admin = (string)($finishedTask['admin'] ?? '');
        $openTasks = vs_task_open_for($admin);
        if ($admin !== '' && function_exists('vs_checkin_json_update') && function_exists('vs_checkin_log')) {
            $now = date('Y-m-d H:i:s');
            $becameAvailable = false;
            $checkinUpdated = vs_checkin_json_update(VS_CHECKIN_FILE, static function (array $checkins) use ($admin, $openTasks, $now, &$becameAvailable): array {
                if (!isset($checkins[$admin]) || empty($checkins[$admin]['task_busy'])) {
                    return $checkins;
                }
                if (!$openTasks) {
                    $checkins[$admin]['status'] = 'Available';
                    $checkins[$admin]['note'] = '';
                    $checkins[$admin]['at'] = $now;
                    unset($checkins[$admin]['task_busy']);
                    $becameAvailable = true;
                } else {
                    $nextTask = reset($openTasks);
                    $checkins[$admin]['note'] = vs_task_note((string)($nextTask['title'] ?? ''));
                }
                return $checkins;
            });
            if ($checkinUpdated) {
                vs_checkin_log($admin, 'task ' . $status . ' (' . $closedBy . ')', $becameAvailable ? 'Available' : 'Busy', (string)($finishedTask['title'] ?? ''));
            } else {
                error_log('Could not update check-in state after closing task.');
            }
        } else {
            error_log('Could not update check-in state after closing task: check-in helpers are unavailable.');
        }
        return true;
    }
}

if (!function_exists('vs_task_open_for')) {
    function vs_task_open_for(string $admin): array
    {
        $key = mb_strtolower(trim($admin));
        if ($key === '') {
            return [];
        }

        return array_filter(vs_task_all(), static function ($task) use ($key): bool {
            return is_array($task)
                && ($task['status'] ?? '') === 'open'
                && mb_strtolower(trim((string)($task['admin'] ?? ''))) === $key;
        });
    }
}
