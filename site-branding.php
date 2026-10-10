<?php

const VS_DEFAULT_LOGO_PATH = 'assets/branding/vibestream-logo-mark.png';
const VS_DEFAULT_APP_NAME = 'VibeStream';
const VS_DEFAULT_APP_DESCRIPTION = 'Discover, watch, and share videos on VibeStream.';

function vs_branding_defaults(): array
{
    return [
        'logo' => VS_DEFAULT_LOGO_PATH,
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

function vs_branding_logo_path(?array $state = null): string
{
    $state = $state ?? vs_branding_state();
    $relativePath = (string)($state['logo'] ?? VS_DEFAULT_LOGO_PATH);
    if ($relativePath !== VS_DEFAULT_LOGO_PATH && !preg_match('~^uploads/site-branding/[a-f0-9]{32}\.png$~', $relativePath)) {
        throw new RuntimeException('The configured site logo path is invalid.');
    }

    $absolutePath = __DIR__ . '/' . $relativePath;
    if (!is_file($absolutePath)) {
        throw new RuntimeException('The configured site logo file is missing.');
    }

    return $absolutePath;
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
    $state = vs_branding_state();
    $relativePath = (string)($state['logo'] ?? VS_DEFAULT_LOGO_PATH);
    if ($relativePath !== VS_DEFAULT_LOGO_PATH && !preg_match('~^uploads/site-branding/[a-f0-9]{32}\.png$~', $relativePath)) {
        throw new RuntimeException('The configured site logo path is invalid.');
    }
    if (!is_file(__DIR__ . '/' . $relativePath)) {
        throw new RuntimeException('The configured site logo file is missing.');
    }

    return vs_site_url($relativePath);
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

function vs_is_admin_session(): bool
{
    return !empty($_SESSION['AdminSession'])
        || (isset($_SESSION['role']) && strcasecmp((string)$_SESSION['role'], 'Admin') === 0);
}

/** Shows the maintenance page to everyone except signed-in admins. */
function vs_maintenance_gate(): void
{
    $state = vs_branding_state();
    if (empty($state['maintenance_enabled']) || vs_is_admin_session()) {
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