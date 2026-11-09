<?php

const VS_DEFAULT_LOGO_PATH = 'assets/branding/vibestream-logo-mark.png';
const VS_DEFAULT_APP_NAME = 'VibeStream';
const VS_DEFAULT_APP_DESCRIPTION = 'Discover, watch, and share videos on VibeStream.';

function vs_branding_defaults(): array
{
    return [
        'logo' => VS_DEFAULT_LOGO_PATH,
        'admin_logo' => null,
        'app_name' => VS_DEFAULT_APP_NAME,
        'app_description' => VS_DEFAULT_APP_DESCRIPTION,
        'default_theme' => 'dark',
        'notification' => null,
        'uploads_enabled' => true,
        'max_upload_mb' => 200,
        'maintenance_enabled' => false,
        'maintenance_message' => 'VibeStream is down for a short maintenance break. Please check back soon.',
        'categories' => ['Music', 'Gaming', 'Movies', 'Live', 'Sports', 'News', 'Fashion', 'Education'],
        'category_colors' => [],
        // Registration: 'open' | 'approval' | 'closed'
        'registration_mode' => 'open',
        // When true, new uploads should be saved as "pending" until an admin approves them.
        'uploads_require_approval' => false,
        // Site-wide announcement banner
        'announcement_enabled' => false,
        'announcement_message' => '',
        'announcement_expires' => '', // Y-m-d, empty = never expires
        // Contact and legal links (footer)
        'support_email' => '',
        'terms_url' => '',
        'privacy_url' => '',
        // Comments and moderation
        'comments_enabled' => true,
        'banned_words' => [],
        // Feed
        'feed_items_per_page' => 24,
        'feed_default_sort' => 'newest', // 'newest' | 'trending'
        // Social links (network key => full URL, empty = hidden)
        'social_links' => [],
        // Google Analytics 4 measurement ID, e.g. G-XXXXXXXXXX (empty = off)
        'analytics_id' => '',
        // More comment rules
        'comments_require_approval' => false,
        'comments_require_login' => true,
        // Date (Y-m-d) the settings backup was last downloaded
        'last_exported' => '',
        // Upload rules
        'allowed_video_formats' => ['mp4', 'webm', 'mov'],
        'max_short_seconds' => 60,
        'daily_upload_limit' => 0, // per user per day, 0 = no limit
        // Blocked lists
        'blocked_email_domains' => [],
        'blocked_ips' => [],
        // Admin security
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 15,
        'admin_session_minutes' => 120,
        // Appearance and region
        'brand_color' => '#7c3aed',
        'site_timezone' => 'UTC',
        'date_format' => 'Y-m-d',
        // Scheduled maintenance window (site time zone, format Y-m-dTH:i; both empty = none)
        'maintenance_start' => '',
        'maintenance_end' => '',
        // Cookie notice
        'cookie_notice_enabled' => false,
        'cookie_notice_text' => '',
        // Mature (18+) content
        'mature_content_enabled' => false,
        'mature_warning_text' => '',
        // Copyright / takedown contact
        'copyright_email' => '',
        'copyright_notice' => '',
        // SEO and sharing
        'seo_keywords' => '',
        'allow_search_engines' => true,
        'seo_share_image_url' => '',
        // Brain meter (the little brain at the top of the site)
        'brain_meter' => [
            'enabled' => true,
        'dizzy_start' => 2,
        'dry_start' => 4,
        'ash_start' => 40,
        'grave_start' => 2000,
            'reset_hour' => 7,
            'heal_minutes' => 30,
            'heal_amount' => 20,
        ],
    ];
}

function vs_branding_state(): array
{
    $statePath = __DIR__ . '/uploads/site-branding/state.php';
    if (!is_file($statePath)) {
        return vs_branding_defaults();
    }

    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($statePath, true);
    }
    $state = require $statePath;
    if (!is_array($state)) {
        throw new RuntimeException('The site branding settings could not be read.');
    }

    return $state + vs_branding_defaults();
}

function vs_branding_app_name(?array $state = null): string
{
    $name = trim((string)(($state ?? vs_branding_state())['app_name'] ?? VS_DEFAULT_APP_NAME));
    if ($name === '' || mb_strlen($name) > 50) {
        throw new RuntimeException('The configured app name is invalid.');
    }
    return $name;
}

function vs_branding_app_description(?array $state = null): string
{
    $description = trim((string)(($state ?? vs_branding_state())['app_description'] ?? VS_DEFAULT_APP_DESCRIPTION));
    if ($description === '' || mb_strlen($description) > 180) {
        throw new RuntimeException('The configured app description is invalid.');
    }
    return $description;
}

function vs_branding_default_theme(?array $state = null): string
{
    $theme = (string)(($state ?? vs_branding_state())['default_theme'] ?? 'dark');
    if (!in_array($theme, ['dark', 'light'], true)) {
        throw new RuntimeException('The configured default theme is invalid.');
    }
    return $theme;
}

function vs_branding_save_state(array $state): void
{
    $directory = __DIR__ . '/uploads/site-branding';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the site branding upload directory.');
    }

    $lock = fopen($directory . '/.state.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the site branding settings.');
    }

    try {
        $temporaryPath = tempnam($directory, 'state-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Could not create a temporary branding settings file.');
        }

        $contents = "<?php\nreturn " . var_export($state, true) . ";\n";
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false || !rename($temporaryPath, $directory . '/state.php')) {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
            throw new RuntimeException('Could not save the site branding settings.');
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function vs_branding_logo_relative(?array $state = null): string
{
    $state = $state ?? vs_branding_state();
    $relativePath = (string)($state['logo'] ?? VS_DEFAULT_LOGO_PATH);
    if ($relativePath !== VS_DEFAULT_LOGO_PATH && !preg_match('~^uploads/site-branding/[a-f0-9]{32}\.png$~', $relativePath)) {
        throw new RuntimeException('The configured site logo path is invalid.');
    }

    if (is_file(__DIR__ . '/' . $relativePath)) {
        return $relativePath;
    }

    if ($relativePath !== VS_DEFAULT_LOGO_PATH && is_file(__DIR__ . '/' . VS_DEFAULT_LOGO_PATH)) {
        return VS_DEFAULT_LOGO_PATH;
    }

    // Never take the whole site down because a logo file is missing: use the default path instead.
    return VS_DEFAULT_LOGO_PATH;
}

function vs_branding_logo_path(?array $state = null): string
{
    return __DIR__ . '/' . vs_branding_logo_relative($state);
}

function vs_site_url(string $relativePath): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (preg_match('~/(?:adminpanel)(?:/adminpanel)?/.*$~', $scriptName, $match, PREG_OFFSET_CAPTURE)) {
        $basePath = substr($scriptName, 0, $match[0][1]);
    } else {
        $basePath = dirname($scriptName);
    }
    $basePath = trim(str_replace('\\', '/', $basePath), '/');

    return ($basePath === '' ? '' : '/' . $basePath) . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
}

function vs_branding_logo_url(): string
{
    return vs_site_url(vs_branding_logo_relative());
}


/**
 * Admin panel icon. When the owner has not uploaded a separate one,
 * the admin panel simply uses the website icon.
 */
function vs_branding_admin_logo_relative(?array $state = null): string
{
    $state = $state ?? vs_branding_state();
    $relativePath = (string)($state['admin_logo'] ?? '');
    if ($relativePath !== ''
        && preg_match('~^uploads/site-branding/admin-[a-f0-9]{32}\.png$~', $relativePath)
        && is_file(__DIR__ . '/' . $relativePath)) {
        return $relativePath;
    }
    return '';
}

function vs_branding_has_admin_logo(?array $state = null): bool
{
    return vs_branding_admin_logo_relative($state) !== '';
}

function vs_branding_admin_logo_url(?array $state = null): string
{
    $state = $state ?? vs_branding_state();
    $relativePath = vs_branding_admin_logo_relative($state);
    if ($relativePath !== '') {
        return vs_site_url($relativePath);
    }
    return vs_branding_logo_url();
}


/* ---------- App settings: uploads, maintenance, categories ---------- */

function vs_uploads_enabled(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['uploads_enabled'] ?? true);
}

function vs_max_upload_mb(?array $state = null): int
{
    $mb = (int)(($state ?? vs_branding_state())['max_upload_mb'] ?? 200);
    return max(1, min($mb, 4096));
}

/** The admin limit in bytes, never higher than what PHP itself accepts. */
function vs_max_upload_bytes(): int
{
    $limit = vs_max_upload_mb() * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $key) {
        $php = vs_ini_bytes((string)ini_get($key));
        if ($php > 0 && $php < $limit) {
            $limit = $php;
        }
    }
    return $limit;
}

function vs_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }
    $number = (float)$value;
    switch (strtolower(substr($value, -1))) {
        case 'g': $number *= 1024;
        case 'm': $number *= 1024;
        case 'k': $number *= 1024;
    }
    return (int)$number;
}

function vs_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

function vs_default_categories(?array $state = null): array
{
    $list = ($state ?? vs_branding_state())['categories'] ?? [];
    $clean = [];
    foreach ((array)$list as $item) {
        $item = trim((string)$item);
        if ($item !== '' && !in_array(mb_strtolower($item), array_map('mb_strtolower', $clean), true)) {
            $clean[] = $item;
        }
    }
    return $clean;
}

/** Fallback colours, used for categories that have no saved colour. */
function vs_category_palette(): array
{
    return ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#db2777', '#0891b2', '#65a30d'];
}

/** Map of lowercase category name => hex colour (only valid #rrggbb values). */
function vs_category_colors(?array $state = null): array
{
    $raw = ($state ?? vs_branding_state())['category_colors'] ?? [];
    $colors = [];
    foreach ((array)$raw as $name => $color) {
        $color = strtolower(trim((string)$color));
        if (preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $colors[mb_strtolower(trim((string)$name))] = $color;
        }
    }
    return $colors;
}

/** Category list as [['name' => 'Music', 'color' => '#7c3aed'], ...] */
function vs_categories_with_colors(?array $state = null): array
{
    $state = $state ?? vs_branding_state();
    $names = vs_default_categories($state);
    $colors = vs_category_colors($state);
    $palette = vs_category_palette();

    $out = [];
    foreach ($names as $i => $name) {
        $out[] = [
            'name' => $name,
            'color' => $colors[mb_strtolower($name)] ?? $palette[$i % count($palette)],
        ];
    }
    return $out;
}


/* ---------- Registration, upload approval, announcement, contact links ---------- */

/** 'open' (anyone can sign up), 'approval' (sign-ups wait for an admin), or 'closed'. */
function vs_registration_mode(?array $state = null): string
{
    $mode = (string)(($state ?? vs_branding_state())['registration_mode'] ?? 'open');
    return in_array($mode, ['open', 'approval', 'closed'], true) ? $mode : 'open';
}

/** True when new sign-ups are allowed at all (open or approval). */
function vs_registration_allowed(?array $state = null): bool
{
    return vs_registration_mode($state) !== 'closed';
}

/** True when new accounts must be approved by an admin before they can sign in. */
function vs_registration_needs_approval(?array $state = null): bool
{
    return vs_registration_mode($state) === 'approval';
}

/** True when new uploads should be saved as "pending" until an admin approves them. */
function vs_uploads_require_approval(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['uploads_require_approval'] ?? false);
}

/** The announcement text to show right now, or '' when disabled, empty or expired. */
function vs_active_announcement(?array $state = null): string
{
    $state = $state ?? vs_branding_state();
    if (empty($state['announcement_enabled'])) {
        return '';
    }
    $message = trim((string)($state['announcement_message'] ?? ''));
    if ($message === '') {
        return '';
    }
    $expires = trim((string)($state['announcement_expires'] ?? ''));
    if ($expires !== '' && date('Y-m-d') > $expires) {
        return '';
    }
    return $message;
}

/**
 * Prints the dismissible announcement banner (nothing if there is no active announcement).
 * Call it once near the top of the site layout, e.g. right after <body>.
 * A visitor who dismisses it will not see the same message again on that device.
 */
function vs_render_announcement(): void
{
    $message = vs_active_announcement();
    if ($message === '') {
        return;
    }
    $id = substr(sha1($message), 0, 12);
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    ?>
<div id="vs-announcement" role="status" data-id="<?= $e($id) ?>" style="display:flex;align-items:center;justify-content:center;gap:12px;padding:10px 16px;background:#7c3aed;color:#fff;font:500 14px/1.4 system-ui,sans-serif;text-align:center;">
    <span><?= $e($message) ?></span>
    <button type="button" aria-label="Dismiss announcement" style="background:none;border:0;color:inherit;font-size:20px;line-height:1;cursor:pointer;padding:0 4px;">&times;</button>
</div>
<script>
(function () {
    var box = document.getElementById('vs-announcement');
    if (!box) { return; }
    var id = box.getAttribute('data-id');
    try { if (localStorage.getItem('vs_announcement_dismissed') === id) { box.remove(); return; } } catch (e) {}
    box.querySelector('button').addEventListener('click', function () {
        try { localStorage.setItem('vs_announcement_dismissed', id); } catch (e) {}
        box.remove();
    });
})();
</script>
<?php
}

function vs_support_email(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['support_email'] ?? ''));
}

function vs_terms_url(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['terms_url'] ?? ''));
}

function vs_privacy_url(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['privacy_url'] ?? ''));
}

/* ---------- Comments, moderation, feed, social links, analytics ---------- */

function vs_comments_enabled(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['comments_enabled'] ?? true);
}

/** True when new comments should be held as pending until an admin approves them. */
function vs_comments_require_approval(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['comments_require_approval'] ?? false);
}

/** True when only signed-in users may comment. */
function vs_comments_require_login(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['comments_require_login'] ?? true);
}

/** Date (Y-m-d) of the last settings backup download, or '' if never. */
function vs_last_exported(?array $state = null): string
{
    $date = trim((string)(($state ?? vs_branding_state())['last_exported'] ?? ''));
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
}

/** Clean list of banned words (no blanks, no duplicates). */
function vs_banned_words(?array $state = null): array
{
    $clean = [];
    foreach ((array)(($state ?? vs_branding_state())['banned_words'] ?? []) as $word) {
        $word = trim((string)$word);
        if ($word !== '' && !in_array(mb_strtolower($word), array_map('mb_strtolower', $clean), true)) {
            $clean[] = $word;
        }
    }
    return $clean;
}

/**
 * Returns the first banned word found in $text (whole word, ignoring case), or null if none.
 * Use it before saving a comment or a video title/description.
 */
function vs_find_banned_word(string $text, ?array $state = null): ?string
{
    foreach (vs_banned_words($state) as $word) {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu';
        if (preg_match($pattern, $text) === 1) {
            return $word;
        }
    }
    return null;
}

function vs_feed_items_per_page(?array $state = null): int
{
    $count = (int)(($state ?? vs_branding_state())['feed_items_per_page'] ?? 24);
    return max(6, min($count, 100));
}

/** 'newest' or 'trending' */
function vs_feed_default_sort(?array $state = null): string
{
    $sort = (string)(($state ?? vs_branding_state())['feed_default_sort'] ?? 'newest');
    return in_array($sort, ['newest', 'trending'], true) ? $sort : 'newest';
}

/** Supported social networks: key => label. */
function vs_social_networks(): array
{
    return [
        'youtube' => 'YouTube',
        'instagram' => 'Instagram',
        'x' => 'X (Twitter)',
        'facebook' => 'Facebook',
        'tiktok' => 'TikTok',
    ];
}

/** Every supported network with its saved link (empty string when not set or invalid). */
function vs_social_links_all(?array $state = null): array
{
    $saved = (array)(($state ?? vs_branding_state())['social_links'] ?? []);
    $links = [];
    foreach (array_keys(vs_social_networks()) as $key) {
        $url = trim((string)($saved[$key] ?? ''));
        $links[$key] = ($url !== '' && preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
    }
    return $links;
}

/** Only the networks that have a link: key => url. Use it to print footer icons. */
function vs_social_links(?array $state = null): array
{
    return array_filter(vs_social_links_all($state), static fn($url) => $url !== '');
}

/** Google Analytics 4 ID, or '' when empty/invalid. */
function vs_analytics_id(?array $state = null): string
{
    $id = strtoupper(trim((string)(($state ?? vs_branding_state())['analytics_id'] ?? '')));
    return preg_match('/^G-[A-Z0-9]{4,15}$/', $id) ? $id : '';
}

/**
 * Prints the Google Analytics snippet (nothing if no ID is set, and never for admins).
 * Call it once inside <head> of the site layout.
 */
function vs_render_analytics(): void
{
    $id = vs_analytics_id();
    if ($id === '' || vs_is_admin_session()) {
        return;
    }
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $e($id) ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', '<?= $e($id) ?>');
</script>
<?php
}

/* ---------- Upload rules, blocked lists, admin security, import ---------- */

/** Video formats the admin can allow: extension => label. */
function vs_video_formats_all(): array
{
    return ['mp4' => 'MP4', 'webm' => 'WebM', 'mov' => 'MOV', 'mkv' => 'MKV', 'avi' => 'AVI', 'm4v' => 'M4V'];
}

/** Allowed extensions, in canonical order. Never empty (falls back to mp4). */
function vs_allowed_video_formats(?array $state = null): array
{
    $saved = (array)(($state ?? vs_branding_state())['allowed_video_formats'] ?? ['mp4', 'webm', 'mov']);
    $saved = array_map(static fn($v) => strtolower(trim((string)$v)), $saved);
    $list = array_values(array_filter(array_keys(vs_video_formats_all()), static fn($f) => in_array($f, $saved, true)));
    return $list ?: ['mp4'];
}

/** True when the file name has an allowed video extension. */
function vs_is_video_filename_allowed(string $filename, ?array $state = null): bool
{
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return $extension !== '' && in_array($extension, vs_allowed_video_formats($state), true);
}

/** Longest allowed "short" in seconds (10 to 600). */
function vs_max_short_seconds(?array $state = null): int
{
    return max(10, min((int)(($state ?? vs_branding_state())['max_short_seconds'] ?? 60), 600));
}

/** Uploads one user may make per day. 0 means no limit. */
function vs_daily_upload_limit(?array $state = null): int
{
    return max(0, min((int)(($state ?? vs_branding_state())['daily_upload_limit'] ?? 0), 1000));
}

function vs_blocked_email_domains(?array $state = null): array
{
    $clean = [];
    foreach ((array)(($state ?? vs_branding_state())['blocked_email_domains'] ?? []) as $domain) {
        $domain = strtolower(ltrim(trim((string)$domain), '@'));
        if ($domain !== '' && !in_array($domain, $clean, true)) {
            $clean[] = $domain;
        }
    }
    return $clean;
}

/** True when the email's domain (or a subdomain of it) is on the blocked list. Use it when signing up. */
function vs_is_email_blocked(string $email, ?array $state = null): bool
{
    $at = strrpos($email, '@');
    if ($at === false) {
        return false;
    }
    $domain = strtolower(trim(substr($email, $at + 1)));
    foreach (vs_blocked_email_domains($state) as $blocked) {
        if ($domain === $blocked || substr($domain, -(strlen($blocked) + 1)) === '.' . $blocked) {
            return true;
        }
    }
    return false;
}

/** A single IP address, or an IPv4 range like 203.0.113.0/24. */
function vs_is_valid_ip_rule(string $rule): bool
{
    $rule = trim($rule);
    if (strpos($rule, '/') === false) {
        return filter_var($rule, FILTER_VALIDATE_IP) !== false;
    }
    [$network, $bits] = explode('/', $rule, 2);
    return filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
        && ctype_digit($bits) && (int)$bits >= 0 && (int)$bits <= 32;
}

function vs_blocked_ips(?array $state = null): array
{
    $clean = [];
    foreach ((array)(($state ?? vs_branding_state())['blocked_ips'] ?? []) as $rule) {
        $rule = trim((string)$rule);
        if ($rule !== '' && vs_is_valid_ip_rule($rule) && !in_array($rule, $clean, true)) {
            $clean[] = $rule;
        }
    }
    return $clean;
}

function vs_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** True when $ip equals the rule, or falls inside the IPv4 range. */
function vs_ip_matches(string $ip, string $rule): bool
{
    if ($ip === '' || $rule === '') {
        return false;
    }
    if (strpos($rule, '/') === false) {
        $a = @inet_pton($ip);
        $b = @inet_pton($rule);
        return $a !== false && $b !== false && $a === $b;
    }
    [$network, $bits] = explode('/', $rule, 2);
    $ipLong = ip2long($ip);
    $networkLong = ip2long($network);
    $bits = (int)$bits;
    if ($ipLong === false || $networkLong === false || $bits < 0 || $bits > 32) {
        return false;
    }
    $mask = $bits === 0 ? 0 : ((~0 << (32 - $bits)) & 0xFFFFFFFF);
    return ($ipLong & $mask) === ($networkLong & $mask);
}

function vs_is_ip_blocked(?string $ip = null, ?array $state = null): bool
{
    $ip = $ip ?? vs_client_ip();
    foreach (vs_blocked_ips($state) as $rule) {
        if (vs_ip_matches($ip, $rule)) {
            return true;
        }
    }
    return false;
}

/** Shows a plain "access denied" page to blocked IPs (signed-in admins are let through). Call it next to vs_maintenance_gate(). */
function vs_ip_block_gate(): void
{
    if (vs_is_admin_session() || !vs_is_ip_blocked()) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(403);
        header('Cache-Control: no-store');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Access denied</title></head>'
        . '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#0f0f0f;color:#f1f1f1">'
        . '<div style="text-align:center;padding:24px"><h1 style="font-size:24px">Access denied</h1><p style="color:#b5b5b5">Your network address is not allowed to use this website.</p></div></body></html>';
    exit;
}

/** Failed sign-in attempts allowed before a lockout (3 to 20). */
function vs_login_max_attempts(?array $state = null): int
{
    return max(3, min((int)(($state ?? vs_branding_state())['login_max_attempts'] ?? 5), 20));
}

/** Minutes a sign-in stays locked after too many failed attempts (1 to 1440). */
function vs_login_lockout_minutes(?array $state = null): int
{
    return max(1, min((int)(($state ?? vs_branding_state())['login_lockout_minutes'] ?? 15), 1440));
}

/** Minutes of inactivity before an admin is signed out (5 to 1440). */
function vs_admin_session_minutes(?array $state = null): int
{
    return max(5, min((int)(($state ?? vs_branding_state())['admin_session_minutes'] ?? 120), 1440));
}

/** Signs an admin out after the configured time of inactivity. Call it near the top of the admin bootstrap. */
function vs_enforce_admin_session_timeout(): void
{
    if (!vs_is_admin_session()) {
        return;
    }
    $now = time();
    $limit = vs_admin_session_minutes() * 60;
    if (isset($_SESSION['vs_last_activity']) && ($now - (int)$_SESSION['vs_last_activity']) > $limit) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        if (!headers_sent()) {
            header('Location: login.php?timeout=1');
        }
        exit;
    }
    $_SESSION['vs_last_activity'] = $now;
}

/**
 * Safely applies a settings backup (the JSON from "Export Settings") on top of the current settings.
 * Only known settings are read, each one is validated, and file paths (logos) are never imported.
 * Returns [new state, list of applied keys, list of skipped keys].
 */
function vs_import_settings(array $input, array $current): array
{
    $next = $current;
    $applied = [];
    $skipped = [];

    $set = static function (string $key, $value) use (&$next, &$applied, &$skipped): void {
        if ($value === null) {
            $skipped[] = $key;
            return;
        }
        $next[$key] = $value;
        $applied[] = $key;
    };
    $has = static fn(string $key): bool => array_key_exists($key, $input);
    $text = static function ($value, int $min, int $max): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return (mb_strlen($value) >= $min && mb_strlen($value) <= $max) ? $value : null;
    };
    $number = static function ($value, int $min, int $max): ?int {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        return $value === false ? null : (int)$value;
    };
    $flag = static fn($value): ?bool => is_bool($value) ? $value : null;
    $url = static function ($value): ?string {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        return (mb_strlen($value) <= 255 && preg_match('~^https?://~i', $value) && filter_var($value, FILTER_VALIDATE_URL)) ? $value : null;
    };
    /** A list of unique strings that each pass $valid; null if anything is wrong or the list is too long. */
    $list = static function ($value, int $max, callable $valid, callable $normalize): ?array {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_scalar($item)) {
                return null;
            }
            $item = $normalize((string)$item);
            if ($item === '') {
                continue;
            }
            if (!$valid($item)) {
                return null;
            }
            if (!in_array(mb_strtolower($item), array_map('mb_strtolower', $out), true)) {
                $out[] = $item;
            }
        }
        return count($out) <= $max ? $out : null;
    };
    $trim = static fn(string $v): string => trim((string)preg_replace('/\s+/', ' ', $v));

    if ($has('app_name')) { $set('app_name', $text($input['app_name'], 1, 50)); }
    if ($has('app_description')) { $set('app_description', $text($input['app_description'], 1, 180)); }
    if ($has('default_theme')) { $set('default_theme', in_array($input['default_theme'], ['dark', 'light'], true) ? $input['default_theme'] : null); }

    foreach (['uploads_enabled', 'uploads_require_approval', 'maintenance_enabled', 'announcement_enabled',
              'comments_enabled', 'comments_require_approval', 'comments_require_login'] as $boolKey) {
        if ($has($boolKey)) { $set($boolKey, $flag($input[$boolKey])); }
    }

    if ($has('max_upload_mb')) { $set('max_upload_mb', $number($input['max_upload_mb'], 1, 4096)); }
    if ($has('maintenance_message')) { $set('maintenance_message', $text($input['maintenance_message'], 0, 300)); }
    if ($has('registration_mode')) { $set('registration_mode', in_array($input['registration_mode'], ['open', 'approval', 'closed'], true) ? $input['registration_mode'] : null); }
    if ($has('announcement_message')) { $set('announcement_message', $text($input['announcement_message'], 0, 200)); }
    if ($has('announcement_expires')) {
        $date = is_string($input['announcement_expires']) ? trim($input['announcement_expires']) : null;
        if ($date !== null && $date !== '') {
            $parsed = DateTime::createFromFormat('Y-m-d', $date);
            $date = ($parsed && $parsed->format('Y-m-d') === $date) ? $date : null;
        }
        $set('announcement_expires', $date);
    }
    if ($has('support_email')) {
        $email = is_string($input['support_email']) ? trim($input['support_email']) : null;
        $set('support_email', ($email === '' || ($email !== null && mb_strlen($email) <= 120 && filter_var($email, FILTER_VALIDATE_EMAIL))) ? $email : null);
    }
    if ($has('terms_url')) { $set('terms_url', $url($input['terms_url'])); }
    if ($has('privacy_url')) { $set('privacy_url', $url($input['privacy_url'])); }

    if ($has('categories')) {
        $set('categories', $list($input['categories'], 40, static fn($c) => (bool)preg_match('/^[A-Za-z0-9 _-]{1,30}$/', $c), $trim));
    }
    if ($has('category_colors')) {
        $colors = null;
        if (is_array($input['category_colors'])) {
            $colors = [];
            foreach ($input['category_colors'] as $name => $color) {
                $color = is_string($color) ? strtolower(trim($color)) : '';
                if (preg_match('/^#[0-9a-f]{6}$/', $color) && preg_match('/^[A-Za-z0-9 _-]{1,30}$/', (string)$name)) {
                    $colors[mb_strtolower((string)$name)] = $color;
                }
            }
        }
        $set('category_colors', $colors);
    }
    if ($has('banned_words')) {
        $set('banned_words', $list($input['banned_words'], 200, static fn($w) => mb_strlen($w) >= 2 && mb_strlen($w) <= 40 && !preg_match('/[\x00-\x1f\x7f]/', $w), $trim));
    }

    if ($has('feed_items_per_page')) { $set('feed_items_per_page', $number($input['feed_items_per_page'], 6, 100)); }
    if ($has('feed_default_sort')) { $set('feed_default_sort', in_array($input['feed_default_sort'], ['newest', 'trending'], true) ? $input['feed_default_sort'] : null); }
    if ($has('brain_meter')) {
        $brainClean = null;
        if (is_array($input['brain_meter'])) {
            try {
                $brainClean = vs_brain_meter_validate($input['brain_meter'] + vs_brain_meter_defaults());
            } catch (Throwable $e) {
                $brainClean = null;
            }
        }
        $set('brain_meter', $brainClean);
    }

    if ($has('social_links')) {
        $links = null;
        if (is_array($input['social_links'])) {
            $links = [];
            foreach (array_keys(vs_social_networks()) as $network) {
                $checked = $url($input['social_links'][$network] ?? '');
                if ($checked === null) {
                    $links = null;
                    break;
                }
                $links[$network] = $checked;
            }
        }
        $set('social_links', $links);
    }
    if ($has('analytics_id')) {
        $id = is_string($input['analytics_id']) ? strtoupper(trim($input['analytics_id'])) : null;
        $set('analytics_id', ($id === '' || ($id !== null && preg_match('/^G-[A-Z0-9]{4,15}$/', $id))) ? $id : null);
    }

    if ($has('allowed_video_formats')) {
        $formats = null;
        if (is_array($input['allowed_video_formats'])) {
            $given = array_map(static fn($v) => strtolower(trim((string)(is_scalar($v) ? $v : ''))), $input['allowed_video_formats']);
            $formats = array_values(array_filter(array_keys(vs_video_formats_all()), static fn($f) => in_array($f, $given, true)));
            if (!$formats) {
                $formats = null;
            }
        }
        $set('allowed_video_formats', $formats);
    }
    if ($has('max_short_seconds')) { $set('max_short_seconds', $number($input['max_short_seconds'], 10, 600)); }
    if ($has('daily_upload_limit')) { $set('daily_upload_limit', $number($input['daily_upload_limit'], 0, 1000)); }

    if ($has('blocked_email_domains')) {
        $set('blocked_email_domains', $list($input['blocked_email_domains'], 200,
            static fn($d) => (bool)preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $d),
            static fn(string $v): string => strtolower(ltrim(trim($v), '@'))));
    }
    if ($has('blocked_ips')) {
        $set('blocked_ips', $list($input['blocked_ips'], 200, 'vs_is_valid_ip_rule', static fn(string $v): string => trim($v)));
    }

    if ($has('login_max_attempts')) { $set('login_max_attempts', $number($input['login_max_attempts'], 3, 20)); }
    if ($has('login_lockout_minutes')) { $set('login_lockout_minutes', $number($input['login_lockout_minutes'], 1, 1440)); }
    if ($has('admin_session_minutes')) { $set('admin_session_minutes', $number($input['admin_session_minutes'], 5, 1440)); }

    if ($has('brand_color')) {
        $color = is_string($input['brand_color']) ? strtolower(trim($input['brand_color'])) : '';
        $set('brand_color', preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null);
    }
    if ($has('site_timezone')) {
        $set('site_timezone', (is_string($input['site_timezone']) && in_array($input['site_timezone'], DateTimeZone::listIdentifiers(), true)) ? $input['site_timezone'] : null);
    }
    if ($has('date_format')) {
        $set('date_format', (is_string($input['date_format']) && isset(vs_date_formats()[$input['date_format']])) ? $input['date_format'] : null);
    }
    foreach (['maintenance_start', 'maintenance_end'] as $windowKey) {
        if ($has($windowKey)) {
            $value = is_string($input[$windowKey]) ? trim($input[$windowKey]) : null;
            if ($value !== null && $value !== '') {
                $parsed = DateTime::createFromFormat('Y-m-d\TH:i', $value);
                $value = ($parsed && $parsed->format('Y-m-d\TH:i') === $value) ? $value : null;
            }
            $set($windowKey, $value);
        }
    }
    foreach (['cookie_notice_enabled', 'mature_content_enabled', 'allow_search_engines'] as $moreBoolKey) {
        if ($has($moreBoolKey)) { $set($moreBoolKey, $flag($input[$moreBoolKey])); }
    }
    if ($has('cookie_notice_text')) { $set('cookie_notice_text', $text($input['cookie_notice_text'], 0, 200)); }
    if ($has('mature_warning_text')) { $set('mature_warning_text', $text($input['mature_warning_text'], 0, 200)); }
    if ($has('copyright_notice')) { $set('copyright_notice', $text($input['copyright_notice'], 0, 300)); }
    if ($has('seo_keywords')) { $set('seo_keywords', $text($input['seo_keywords'], 0, 200)); }
    if ($has('copyright_email')) {
        $copyrightEmail = is_string($input['copyright_email']) ? trim($input['copyright_email']) : null;
        $set('copyright_email', ($copyrightEmail === '' || ($copyrightEmail !== null && mb_strlen($copyrightEmail) <= 120 && filter_var($copyrightEmail, FILTER_VALIDATE_EMAIL))) ? $copyrightEmail : null);
    }
    if ($has('seo_share_image_url')) { $set('seo_share_image_url', $url($input['seo_share_image_url'])); }

    return [$next, $applied, $skipped];
}

/* ---------- Appearance, region, scheduled maintenance, notices, SEO ---------- */

function vs_brain_meter_defaults(): array
{
    return [
        'enabled' => true,
        'dizzy_start' => 2,
        'dry_start' => 4,
        'ash_start' => 40,
        'grave_start' => 2000,
        'reset_hour' => 7,
        'heal_minutes' => 30,
        'heal_amount' => 20,
    ];
}

/** Checks the brain meter settings. Returns the clean array, or throws a RuntimeException with a message for the admin. */
function vs_brain_meter_validate(array $in): array
{
    $d = vs_brain_meter_defaults();
    $int = static function ($value, int $min, int $max, string $label): int {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($n === false) {
            throw new RuntimeException($label . ' must be a whole number between ' . $min . ' and ' . $max . '.');
        }
        return (int)$n;
    };
    $out = [
        'enabled' => !empty($in['enabled']),
        'dizzy_start' => $int($in['dizzy_start'] ?? $d['dizzy_start'], 1, 100000, 'The dizzy count'),
        'dry_start' => $int($in['dry_start'] ?? $d['dry_start'], 2, 100000, 'The drying count'),
        'ash_start' => $int($in['ash_start'] ?? $d['ash_start'], 3, 10000000, 'The dust count'),
        'grave_start' => $int($in['grave_start'] ?? $d['grave_start'], 4, 100000000, 'The grave count'),
        'reset_hour' => $int($in['reset_hour'] ?? $d['reset_hour'], 0, 23, 'The reset hour'),
        'heal_minutes' => $int($in['heal_minutes'] ?? $d['heal_minutes'], 0, 1440, 'The break time'),
        'heal_amount' => $int($in['heal_amount'] ?? $d['heal_amount'], 0, 1000, 'The healing amount'),
    ];
    if ($out['dizzy_start'] >= $out['dry_start']) {
        throw new RuntimeException('The dizzy count must be lower than the drying count.');
    }
    if ((int)ceil($out['dry_start'] * 1.5) >= $out['ash_start']) {
        throw new RuntimeException('The dust count must be higher than 1.5 times the drying count (the brain finishes drying out at 1.5 times the drying count).');
    }
    if ($out['ash_start'] >= $out['grave_start']) {
        throw new RuntimeException('The dust count must be lower than the grave count.');
    }
    return $out;
}

/** Brain meter settings, always safe to use (falls back to the defaults if something saved is invalid). */
function vs_brain_meter(?array $state = null): array
{
    $saved = ($state ?? vs_branding_state())['brain_meter'] ?? null;
    try {
        return vs_brain_meter_validate(is_array($saved) ? $saved + vs_brain_meter_defaults() : vs_brain_meter_defaults());
    } catch (Throwable $e) {
        return vs_brain_meter_defaults();
    }
}

function vs_brand_color(?array $state = null): string
{
    $color = strtolower(trim((string)(($state ?? vs_branding_state())['brand_color'] ?? '#7c3aed')));
    return preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : '#7c3aed';
}

/**
 * Prints a small <style> that sets --vs-accent and recolours Bootstrap's primary buttons and switches.
 * Call it once inside <head> (the site layout, and the admin header if you want the admin panel to match).
 */
function vs_render_brand_style(): void
{
    $color = vs_brand_color();
    $darker = sprintf('#%02x%02x%02x',
        (int)(hexdec(substr($color, 1, 2)) * 0.85),
        (int)(hexdec(substr($color, 3, 2)) * 0.85),
        (int)(hexdec(substr($color, 5, 2)) * 0.85));
    ?>
<style>
:root { --vs-accent: <?= $color ?>; }
.btn-primary { --bs-btn-bg: <?= $color ?>; --bs-btn-border-color: <?= $color ?>; --bs-btn-hover-bg: <?= $darker ?>; --bs-btn-hover-border-color: <?= $darker ?>; --bs-btn-active-bg: <?= $darker ?>; --bs-btn-active-border-color: <?= $darker ?>; --bs-btn-disabled-bg: <?= $color ?>; --bs-btn-disabled-border-color: <?= $color ?>; }
.btn-outline-primary { --bs-btn-color: <?= $color ?>; --bs-btn-border-color: <?= $color ?>; --bs-btn-hover-bg: <?= $color ?>; --bs-btn-hover-border-color: <?= $color ?>; --bs-btn-active-bg: <?= $darker ?>; --bs-btn-active-border-color: <?= $darker ?>; }
.form-check-input:checked { background-color: <?= $color ?>; border-color: <?= $color ?>; }
</style>
<?php
}

function vs_site_timezone(?array $state = null): string
{
    $zone = (string)(($state ?? vs_branding_state())['site_timezone'] ?? 'UTC');
    return in_array($zone, DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
}

/** Makes PHP use the site time zone. Call it once early (for example in your bootstrap). */
function vs_apply_timezone(): void
{
    date_default_timezone_set(vs_site_timezone());
}

/** Date formats the admin can choose: PHP format => example. */
function vs_date_formats(): array
{
    return [
        'Y-m-d' => '2026-10-29',
        'd/m/Y' => '29/10/2026',
        'm/d/Y' => '10/29/2026',
        'd M Y' => '29 Oct 2026',
        'M j, Y' => 'Oct 29, 2026',
    ];
}

function vs_date_format(?array $state = null): string
{
    $format = (string)(($state ?? vs_branding_state())['date_format'] ?? 'Y-m-d');
    return isset(vs_date_formats()[$format]) ? $format : 'Y-m-d';
}

/** Formats a timestamp (or a date string) in the site time zone and date format. */
function vs_format_date($when, ?array $state = null): string
{
    $timestamp = is_numeric($when) ? (int)$when : strtotime((string)$when);
    if ($timestamp === false) {
        return '';
    }
    $date = new DateTime('@' . $timestamp);
    $date->setTimezone(new DateTimeZone(vs_site_timezone($state)));
    return $date->format(vs_date_format($state));
}

/** [start, end] timestamps of the scheduled maintenance window, or null when none is set. */
function vs_maintenance_window(?array $state = null): ?array
{
    $state = $state ?? vs_branding_state();
    $zone = new DateTimeZone(vs_site_timezone($state));
    $times = [];
    foreach (['maintenance_start', 'maintenance_end'] as $key) {
        $value = trim((string)($state[$key] ?? ''));
        $date = $value === '' ? false : DateTime::createFromFormat('Y-m-d\TH:i', $value, $zone);
        if (!$date) {
            return null;
        }
        $times[] = $date->getTimestamp();
    }
    return $times[1] > $times[0] ? $times : null;
}

/** True when maintenance is switched on, or the scheduled window is happening right now. */
function vs_maintenance_active(?array $state = null): bool
{
    $state = $state ?? vs_branding_state();
    if (!empty($state['maintenance_enabled'])) {
        return true;
    }
    $window = vs_maintenance_window($state);
    if ($window === null) {
        return false;
    }
    $now = time();
    return $now >= $window[0] && $now < $window[1];
}

function vs_cookie_notice_enabled(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['cookie_notice_enabled'] ?? false);
}

function vs_cookie_notice_text(?array $state = null): string
{
    $text = trim((string)(($state ?? vs_branding_state())['cookie_notice_text'] ?? ''));
    return $text !== '' ? $text : 'We use cookies to make this site work and to improve your experience.';
}

/** Prints the cookie notice bar (nothing if it is turned off). Call it once near the end of <body>. */
function vs_render_cookie_notice(): void
{
    $state = vs_branding_state();
    if (!vs_cookie_notice_enabled($state)) {
        return;
    }
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $privacy = vs_privacy_url($state);
    ?>
<div id="vs-cookie-notice" role="dialog" aria-label="Cookie notice" style="position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:12px 16px;border-radius:12px;background:#181818;color:#f1f1f1;border:1px solid #303030;font:14px/1.5 system-ui,sans-serif;">
    <span><?= $e(vs_cookie_notice_text($state)) ?><?php if ($privacy !== ''): ?> <a href="<?= $e($privacy) ?>" style="color:#8ab4ff">Privacy policy</a><?php endif; ?></span>
    <button type="button" style="background:<?= $e(vs_brand_color($state)) ?>;color:#fff;border:0;border-radius:8px;padding:8px 16px;cursor:pointer;">Accept</button>
</div>
<script>
(function () {
    var bar = document.getElementById('vs-cookie-notice');
    if (!bar) { return; }
    try { if (localStorage.getItem('vs_cookie_ok') === '1') { bar.remove(); return; } } catch (e) {}
    bar.style.display = 'flex';
    bar.querySelector('button').addEventListener('click', function () {
        try { localStorage.setItem('vs_cookie_ok', '1'); } catch (e) {}
        bar.remove();
    });
})();
</script>
<?php
}

/** True when uploaders may mark a video as 18+ and viewers see a warning first. */
function vs_mature_content_enabled(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['mature_content_enabled'] ?? false);
}

function vs_mature_warning_text(?array $state = null): string
{
    $text = trim((string)(($state ?? vs_branding_state())['mature_warning_text'] ?? ''));
    return $text !== '' ? $text : 'This video may contain content for adults only (18+). Do you want to continue?';
}

function vs_copyright_email(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['copyright_email'] ?? ''));
}

function vs_copyright_notice(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['copyright_notice'] ?? ''));
}

function vs_seo_keywords(?array $state = null): string
{
    return trim((string)(($state ?? vs_branding_state())['seo_keywords'] ?? ''));
}

function vs_search_engines_allowed(?array $state = null): bool
{
    return (bool)(($state ?? vs_branding_state())['allow_search_engines'] ?? true);
}

function vs_seo_share_image_url(?array $state = null): string
{
    $url = trim((string)(($state ?? vs_branding_state())['seo_share_image_url'] ?? ''));
    return ($url !== '' && preg_match('~^https?://~i', $url) && filter_var($url, FILTER_VALIDATE_URL)) ? $url : '';
}

/**
 * Prints description, keywords, robots and Open Graph / Twitter share tags inside <head>.
 * Pass a page title, description and image for a single video page; leave them empty to use the site defaults.
 * If your layout already prints a description tag, remove that one to avoid duplicates.
 */
function vs_render_seo_meta(string $title = '', string $description = '', string $image = ''): void
{
    $state = vs_branding_state();
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };

    $name = vs_branding_app_name($state);
    $title = $title !== '' ? $title : $name;
    $description = $description !== '' ? $description : vs_branding_app_description($state);
    $image = $image !== '' ? $image : vs_seo_share_image_url($state);

    if (!vs_search_engines_allowed($state)) {
        if (!headers_sent()) {
            header('X-Robots-Tag: noindex, nofollow');
        }
        echo '<meta name="robots" content="noindex, nofollow">' . "\n";
    }
    echo '<meta name="description" content="' . $e($description) . '">' . "\n";
    if (vs_seo_keywords($state) !== '') {
        echo '<meta name="keywords" content="' . $e(vs_seo_keywords($state)) . '">' . "\n";
    }
    echo '<meta property="og:site_name" content="' . $e($name) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:title" content="' . $e($title) . '">' . "\n";
    echo '<meta property="og:description" content="' . $e($description) . '">' . "\n";
    if ($image !== '') {
        echo '<meta property="og:image" content="' . $e($image) . '">' . "\n";
    }
    echo '<meta name="twitter:card" content="' . ($image !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
}

/* ---------- CEO title (an admin who also carries the CEO title) ---------- */

/** Account ids (as strings) the owner has given the CEO title. The owner is always the CEO and is not listed here. */
function vs_ceo_ids(?array $state = null): array
{
    $ids = [];
    foreach ((array)(($state ?? vs_branding_state())['ceo_ids'] ?? []) as $id) {
        $id = trim((string)$id);
        if ($id !== '' && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id) && !in_array($id, $ids, true)) {
            $ids[] = $id;
        }
    }
    return $ids;
}

function vs_is_ceo_id($id, ?array $state = null): bool
{
    return in_array(trim((string)$id), vs_ceo_ids($state), true);
}

/** Gives or removes the CEO title for one account id and saves it. The account's role is not touched. */
function vs_set_ceo_id($id, bool $isCeo): void
{
    $id = trim((string)$id);
    if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $id)) {
        throw new InvalidArgumentException('Invalid account id.');
    }
    $state = vs_branding_state();
    $ids = array_values(array_filter(vs_ceo_ids($state), static fn($existing) => $existing !== $id));
    if ($isCeo) {
        $ids[] = $id;
    }
    $state['ceo_ids'] = $ids;
    vs_branding_save_state($state);
}

function vs_is_admin_session(): bool
{
    return !empty($_SESSION['AdminSession'])
        || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0);
}

/** Shows the maintenance page to everyone except signed-in admins. */
function vs_maintenance_gate(): void
{
    $state = vs_branding_state();
    if (!vs_maintenance_active($state) || vs_is_admin_session()) {
        return;
    }

    $name = vs_branding_app_name($state);
    $message = trim((string)($state['maintenance_message'] ?? ''));
    if ($message === '') {
        $message = $name . ' is down for a short maintenance break. Please check back soon.';
    }
    $logo = '';
    try {
        $logo = vs_branding_logo_url();
    } catch (Throwable $error) {
        $logo = '';
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Cache-Control: no-store');
    }
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Maintenance - <?= $e($name) ?></title>
<style>
:root { color-scheme: light dark; }
* { box-sizing: border-box; }
body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
    font-family: "Segoe UI", system-ui, sans-serif; background: #0f0f0f; color: #f1f1f1; }
.box { max-width: 460px; text-align: center; background: #181818; border: 1px solid #303030;
    border-radius: 24px; padding: 36px 28px; }
.box img { width: 68px; height: 68px; border-radius: 16px; object-fit: cover; }
h1 { margin: 16px 0 10px; font-size: 24px; }
p { margin: 0 0 18px; line-height: 1.6; color: #b5b5b5; white-space: pre-line; }
a { color: #8ab4ff; font-size: 14px; }
@media (prefers-color-scheme: light) {
    body { background: #f5f6fa; color: #17181c; }
    .box { background: #ffffff; border-color: #d9dde5; }
    p { color: #4b5160; }
    a { color: #1f5fd6; }
}
</style>
</head>
<body>
<div class="box">
    <?php if ($logo !== ''): ?><img src="<?= $e($logo) ?>" alt=""><?php endif; ?>
    <h1>We'll be right back</h1>
    <p><?= $e($message) ?></p>
    <a href="login.php">Admin sign in</a>
</div>
</body>
</html>
<?php
    exit;
}