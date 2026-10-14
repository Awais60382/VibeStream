<?php
/**
 * Custom category icons + extra ready-made categories.
 * Put this file in the SITE ROOT, next to category-presets.php.
 *
 * Uploaded icons live in  /uploads/category-icons/  and the mapping
 * (category name -> file) lives in  /uploads/category-icons/icons.json
 */

if (!defined('VS_CAT_ICON_MAX_BYTES')) {
    define('VS_CAT_ICON_MAX_BYTES', 512 * 1024);
}

function vs_cat_icons_dir(): string
{
    return __DIR__ . '/uploads/category-icons';
}

function vs_cat_icons_ensure_dir(): bool
{
    $dir = vs_cat_icons_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Options -Indexes -ExecCGI\n<FilesMatch \"\\.(php|phtml|phar)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    return is_writable($dir);
}

/** @return array<string,string> lowercase category name => file name */
function vs_cat_icons_map(): array
{
    $file = vs_cat_icons_dir() . '/icons.json';
    if (!is_file($file)) {
        return [];
    }
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $key => $val) {
        if (is_string($key) && is_string($val) && preg_match('/^[a-f0-9]{16,}\.(png|jpg|webp|gif|svg)$/', $val)) {
            $out[$key] = $val;
        }
    }
    return $out;
}

function vs_cat_icons_save(array $map): bool
{
    if (!vs_cat_icons_ensure_dir()) {
        return false;
    }
    $json = json_encode($map, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return @file_put_contents(vs_cat_icons_dir() . '/icons.json', $json, LOCK_EX) !== false;
}

/** Validates an uploaded file and moves it into the icons folder. Returns the new file name. */
function vs_cat_icon_store(array $file): string
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
    if ($size <= 0 || $size > VS_CAT_ICON_MAX_BYTES) {
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

    if (!vs_cat_icons_ensure_dir()) {
        throw new RuntimeException('Could not save the icon. Make sure the uploads/category-icons folder is writable.');
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest = vs_cat_icons_dir() . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save the icon. Make sure the uploads/category-icons folder is writable.');
    }
    @chmod($dest, 0644);
    return $name;
}

function vs_cat_icon_set(string $categoryName, array $file): void
{
    $key = mb_strtolower(trim($categoryName));
    $new = vs_cat_icon_store($file);
    $map = vs_cat_icons_map();
    $old = $map[$key] ?? null;
    $map[$key] = $new;
    if (!vs_cat_icons_save($map)) {
        @unlink(vs_cat_icons_dir() . '/' . $new);
        throw new RuntimeException('Could not save the icon list. Check folder permissions.');
    }
    if ($old) {
        @unlink(vs_cat_icons_dir() . '/' . $old);
    }
}

function vs_cat_icon_remove(string $categoryName): void
{
    $key = mb_strtolower(trim($categoryName));
    $map = vs_cat_icons_map();
    if (!isset($map[$key])) {
        return;
    }
    $old = $map[$key];
    unset($map[$key]);
    vs_cat_icons_save($map);
    @unlink(vs_cat_icons_dir() . '/' . $old);
}

/** URL of the custom icon, or null. $base is the path from the current page to the site root ('' or '../'). */
function vs_cat_icon_url(string $categoryName, string $base = ''): ?string
{
    $key = mb_strtolower(trim($categoryName));
    $map = vs_cat_icons_map();
    if (!isset($map[$key]) || !is_file(vs_cat_icons_dir() . '/' . $map[$key])) {
        return null;
    }
    return $base . 'uploads/category-icons/' . $map[$key];
}

/** Ready-made categories: the built-in ones plus extras added here (Fun). */
function vs_all_category_presets(): array
{
    $presets = function_exists('vs_category_presets') ? vs_category_presets() : [];
    $extra = [
        'fun' => ['Fun', '#f59e0b'],
    ];
    foreach ($extra as $slug => $preset) {
        if (!isset($presets[$slug])) {
            $presets[$slug] = $preset;
        }
    }
    return $presets;
}

/**
 * Icon markup for a category: uploaded icon first, then the built-in one.
 * Use this anywhere you currently call vs_category_icon_svg() (sidebar, home chips...).
 */
function vs_category_icon_html(string $categoryName, int $size = 20, string $base = ''): string
{
    $url = vs_cat_icon_url($categoryName, $base);
    if ($url !== null) {
        return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="" width="' . $size . '" height="' . $size
            . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain;border-radius:4px;display:block">';
    }
    if (mb_strtolower(trim($categoryName)) === 'fun') {
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/>'
            . '<path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>';
    }
    return function_exists('vs_category_icon_svg') ? (string)vs_category_icon_svg($categoryName, $size) : '';
}