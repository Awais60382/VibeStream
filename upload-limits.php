<?php
/**
 * Upload size limits by plan. Put this file in the SITE ROOT.
 *
 *   $msg = vs_upload_too_big($_FILES['video']['size'], $conn);   // null = OK, otherwise a message to show
 *   vs_upload_limit_label($conn)                                   // e.g. "100 MB" or "1 GB"
 *
 * Premium members and admins get the Premium limit. Change the two numbers below to suit you.
 * PHP has its own caps (upload_max_filesize and post_max_size in php.ini). Whichever is lower wins,
 * so raise those too if you want the full Premium size to work.
 */
if (!defined('VS_UPLOAD_FREE_MB')) {
    define('VS_UPLOAD_FREE_MB', 100);
}
if (!defined('VS_UPLOAD_PREMIUM_MB')) {
    define('VS_UPLOAD_PREMIUM_MB', 1024);
}

if (!function_exists('vs_upload_too_big')) {
    require_once __DIR__ . '/vip-badge.php';

    function vs_upload_ini_bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $n = (float)$value;
        switch (strtolower(substr($value, -1))) {
            case 'g': $n *= 1024;
            // no break
            case 'm': $n *= 1024;
            // no break
            case 'k': $n *= 1024;
        }
        return (int)$n;
    }

    /** Is the current visitor Premium (or admin)? */
    function vs_upload_is_premium($db = null): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        return vs_vip_is(trim((string)($_SESSION['username'] ?? '')), $db);
    }

    /** The plan's limit in bytes, before PHP's own caps. */
    function vs_upload_plan_bytes($db = null): int
    {
        return (vs_upload_is_premium($db) ? VS_UPLOAD_PREMIUM_MB : VS_UPLOAD_FREE_MB) * 1024 * 1024;
    }

    /** What the visitor can really upload: the plan limit, held down by PHP's caps if those are lower. */
    function vs_upload_limit_bytes($db = null): int
    {
        $limit = vs_upload_plan_bytes($db);
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $cap = vs_upload_ini_bytes((string)ini_get($key));
            if ($cap > 0) {
                $limit = min($limit, $cap);
            }
        }
        return $limit;
    }

    function vs_upload_format_bytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / 1024 / 1024 / 1024, 1), '0'), '.') . ' GB';
        }
        return rtrim(rtrim(number_format($bytes / 1024 / 1024, 1), '0'), '.') . ' MB';
    }

    function vs_upload_limit_label($db = null): string
    {
        return vs_upload_format_bytes(vs_upload_limit_bytes($db));
    }

    /** Returns null when the file is fine, or a short message when it is over the visitor's limit. */
    function vs_upload_too_big(int $bytes, $db = null): ?string
    {
        if ($bytes <= vs_upload_limit_bytes($db)) {
            return null;
        }
        if (vs_upload_is_premium($db)) {
            return 'This file is too large. The limit is ' . vs_upload_limit_label($db) . '.';
        }
        return 'This file is too large. Free accounts can upload up to ' . vs_upload_limit_label($db)
            . '. Premium members can upload up to ' . vs_upload_format_bytes(VS_UPLOAD_PREMIUM_MB * 1024 * 1024) . '.';
    }
}
