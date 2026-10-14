<?php
/**
 * VibeStream - small UI icons the owner can replace (menu, explore list, buttons).
 * Put this file in the main VibeStream folder. The owner changes them in the admin panel
 * (adminpanel/site-icons.php). Uploaded files live in uploads/ui-icons/.
 */
if (!defined('VS_UI_ICON_MAX_BYTES')) {
    define('VS_UI_ICON_MAX_BYTES', 512 * 1024);
}

/** Icons that can be replaced, grouped for the admin page: group => [name => label]. */
function vs_ui_icon_groups(): array
{
    return [
        'Menu' => [
            'home' => 'Home', 'shorts' => 'Shorts', 'watch' => 'Watch', 'subscriptions' => 'All subscriptions',
            'profile' => 'Your channel', 'history' => 'History', 'upload' => 'Upload video',
            'premium' => 'Premium', 'trend' => 'Trending',
        ],
        'Explore' => [
            'music' => 'Music', 'gaming' => 'Gaming', 'movies' => 'Movies', 'live' => 'Live',
            'sports' => 'Sports', 'news' => 'News', 'fashion' => 'Fashion', 'education' => 'Education',
        ],
        'Buttons' => [
            'plus-square' => 'Upload and add buttons', 'contact' => 'Avatar and name buttons',
        ],
    ];
}

/** @return array<string,string> name => label */
function vs_ui_icon_all_names(): array
{
    $all = [];
    foreach (vs_ui_icon_groups() as $names) {
        $all += $names;
    }
    return $all;
}

function vs_ui_icons_dir(): string
{
    return __DIR__ . '/uploads/ui-icons';
}

function vs_ui_icons_ensure_dir(): bool
{
    $dir = vs_ui_icons_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    return is_writable($dir);
}

/** @return array<string,string> icon name => file name */
function vs_ui_icons_map(): array
{
    $file = vs_ui_icons_dir() . '/icons.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $names = vs_ui_icon_all_names();
    $out = [];
    foreach ($data as $key => $val) {
        if (is_string($key) && isset($names[$key]) && is_string($val) && preg_match('/^[a-f0-9]{16,}\.(png|jpg|webp|gif|svg)$/', $val)) {
            $out[$key] = $val;
        }
    }
    return $out;
}

function vs_ui_icons_save(array $map): bool
{
    if (!vs_ui_icons_ensure_dir()) {
        return false;
    }
    $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents(vs_ui_icons_dir() . '/icons.json', $json, LOCK_EX) !== false;
}

/** Validates an uploaded image and moves it into the icons folder. Returns the new file name. */
function vs_ui_icon_store(array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose an icon file first.');
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That file is too big. Icons must be under 512 KB.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The upload failed. Please try again.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > VS_UI_ICON_MAX_BYTES) {
        throw new RuntimeException('That file is too big. Icons must be under 512 KB.');
    }

    $ext = null;
    $info = @getimagesize($tmp);
    if ($info && isset($info[2])) {
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        $ext = $types[$info[2]] ?? null;
    }
    if ($ext === null) {
        $svg = (string)@file_get_contents($tmp);
        $unsafe = '/<\s*(script|foreignObject|iframe|object|embed)|\son[a-z]+\s*=|javascript:|<!ENTITY|<!DOCTYPE/i';
        if (stripos($svg, '<svg') !== false && !preg_match($unsafe, $svg)) {
            $ext = 'svg';
        }
    }
    if ($ext === null) {
        throw new RuntimeException('Use a PNG, JPG, WEBP, GIF or SVG image.');
    }

    if (!vs_ui_icons_ensure_dir()) {
        throw new RuntimeException('Could not save the icon. Make sure the uploads/ui-icons folder is writable.');
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest = vs_ui_icons_dir() . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save the icon. Make sure the uploads/ui-icons folder is writable.');
    }
    @chmod($dest, 0644);
    return $name;
}

function vs_ui_icon_set(string $iconName, array $file): void
{
    if (!isset(vs_ui_icon_all_names()[$iconName])) {
        throw new RuntimeException('Unknown icon.');
    }
    $new = vs_ui_icon_store($file);
    $map = vs_ui_icons_map();
    $old = $map[$iconName] ?? null;
    $map[$iconName] = $new;
    if (!vs_ui_icons_save($map)) {
        @unlink(vs_ui_icons_dir() . '/' . $new);
        throw new RuntimeException('Could not save the icon list. Check folder permissions.');
    }
    if ($old) {
        @unlink(vs_ui_icons_dir() . '/' . $old);
    }
}

function vs_ui_icon_remove(string $iconName): void
{
    $map = vs_ui_icons_map();
    if (!isset($map[$iconName])) {
        return;
    }
    $old = $map[$iconName];
    unset($map[$iconName]);
    vs_ui_icons_save($map);
    @unlink(vs_ui_icons_dir() . '/' . $old);
}

/** URL of the custom icon, or null. $base is the path from the current page to the site root ('' or '../'). */
function vs_ui_icon_url(string $iconName, string $base = ''): ?string
{
    $map = vs_ui_icons_map();
    if (!isset($map[$iconName]) || !is_file(vs_ui_icons_dir() . '/' . $map[$iconName])) {
        return null;
    }
    return $base . 'uploads/ui-icons/' . $map[$iconName];
}

/** <img> markup for a replaced icon, or '' when the owner has not replaced it (use the built-in icon then). */
function vs_ui_icon_img(string $iconName, int $size = 24, string $base = ''): string
{
    $url = vs_ui_icon_url($iconName, $base);
    if ($url === null) {
        return '';
    }
    $s = max(8, $size);
    return '<img class="vs-ui-icon" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" width="' . $s . '" height="' . $s
        . '" alt="" aria-hidden="true" style="width:' . $s . 'px;height:' . $s . 'px;object-fit:contain;display:inline-block;vertical-align:middle">';
}
