<?php
/*
 * VibeStream admin panel - icon access store.
 * Put this file in the adminpanel folder (next to icons.php and header.php).
 * Shared by icons.php and header.php so the owner can be notified on every admin page.
 */

if (!defined('VS_IA_FILE')) {
    define('VS_IA_FILE', 'uploads/site-branding/icon-access.json');
}

/** Loads ['granted' => [names], 'requests' => [name => ['status' => 'pending'|'denied', 'at' => ISO date]]]. */
if (!function_exists('vs_ia_load')) {
    function vs_ia_load(): array
    {
        $clean = ['granted' => [], 'requests' => []];
        $path = __DIR__ . '/../' . VS_IA_FILE;
        $raw = is_file($path) ? @file_get_contents($path) : false;
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data)) {
            foreach ((array)($data['granted'] ?? []) as $name) {
                if (is_string($name) && $name !== '') {
                    $clean['granted'][] = $name;
                }
            }
            $clean['granted'] = array_values(array_unique($clean['granted']));
            foreach ((array)($data['requests'] ?? []) as $name => $req) {
                $name = (string)$name;
                if ($name === '' || !is_array($req)) {
                    continue;
                }
                $clean['requests'][$name] = [
                    'status' => (($req['status'] ?? '') === 'denied') ? 'denied' : 'pending',
                    'at' => (string)($req['at'] ?? ''),
                ];
            }
        }
        return $clean;
    }
}

if (!function_exists('vs_ia_save')) {
    function vs_ia_save(array $data): void
    {
        $dir = __DIR__ . '/../uploads/site-branding';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the settings directory.');
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || file_put_contents(__DIR__ . '/../' . VS_IA_FILE, $json, LOCK_EX) === false) {
            throw new RuntimeException('The access settings could not be saved.');
        }
    }
}

/** Admins waiting for approval: [name => ISO date]. */
if (!function_exists('vs_ia_pending_list')) {
    function vs_ia_pending_list(): array
    {
        $out = [];
        foreach (vs_ia_load()['requests'] as $name => $req) {
            if ($req['status'] === 'pending') {
                $out[$name] = $req['at'];
            }
        }
        return $out;
    }
}

/** Number of admins waiting for approval. */
if (!function_exists('vs_ia_pending_count')) {
    function vs_ia_pending_count(): int
    {
        return count(vs_ia_pending_list());
    }
}
