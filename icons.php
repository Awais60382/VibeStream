<?php
/*
 * VibeStream - icons hub (MAIN folder, next to index.php).   >>> REBUILT FILE <<<
 *
 * index.php, shorts.php, watch.php, subscriptions.php and profile.php do:  require_once __DIR__ . '/icons.php';
 * This file loads every icon helper that lives in the main folder, so any page that includes it gets:
 *   ui-icons.php            replaceable menu / explore / button icons   (vs_ui_icon_*)
 *   category-presets.php    ready-made categories and their icons       (vs_category_presets, vs_category_icon_svg)
 *   category-icons.php      uploaded category icons                     (vs_category_icon_html, vs_cat_icon_*)
 *   sidebar-icons-store.php owner-replaced sidebar / menu icons         (vs_si_*)
 * and it defines vs_icon(), the small button icon used on the profile page.
 *
 * It only loads files that exist, never prints anything, and is safe to include many times.
 * NOTE: the admin "Site icons" page is a DIFFERENT file and belongs in the adminpanel folder:
 *   adminpanel/icons.php  (the one that starts with "VibeStream admin panel - Site icons (OWNER ONLY)")
 */
foreach (['ui-icons.php', 'category-presets.php', 'category-icons.php', 'sidebar-icons-store.php'] as $vsIconFile) {
    $vsIconPath = __DIR__ . '/' . $vsIconFile;
    if (is_file($vsIconPath)) {
        require_once $vsIconPath;
    }
}
unset($vsIconFile, $vsIconPath);

if (!function_exists('vs_icon')) {
    /**
     * Small button icon by name, e.g. vs_icon('plus-square', 16).
     * If the owner uploaded a replacement (Admin > Site icons > Buttons) that one is used,
     * otherwise the built-in line icon below. Unknown names give an empty string (never an error).
     */
    function vs_icon(string $name, int $size = 20): string
    {
        $size = max(8, $size);
        if (function_exists('vs_ui_icon_img')) {
            try {
                $custom = vs_ui_icon_img($name, $size);
                if ($custom !== '') {
                    return $custom;
                }
            } catch (Throwable $e) {
                // fall back to the built-in icon
            }
        }
        $paths = [
            'plus-square' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/>',
            'contact'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        ];
        if (!isset($paths[$name])) {
            return '';
        }
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" '
            . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
    }
}