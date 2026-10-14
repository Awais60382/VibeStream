<?php
/**
 * Ready-made categories (name, colour, icon) used by the Admin > Categories page and the sidebar.
 * Put this file in the main VibeStream folder (next to sidebar-navigation.php).
 * It does not depend on any other file.
 */

if (!function_exists('vs_category_presets')) {
    /** slug => [label, colour, svg path markup (24x24, stroke icons)] */
    function vs_category_presets(): array
    {
        return [
            'music'     => ['Music', '#8b5cf6', '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'],
            'gaming'    => ['Gaming', '#f59e0b', '<path d="M6 11h4M8 9v4"/><path d="M15 12h.01M18 10h.01"/><path d="M17.3 5H6.7a4 4 0 0 0-3.9 3.2l-1.3 6.4A3 3 0 0 0 4.4 18c1 0 1.9-.5 2.4-1.3L8 15h8l1.2 1.7c.5.8 1.4 1.3 2.4 1.3a3 3 0 0 0 2.9-3.4l-1.3-6.4A4 4 0 0 0 17.3 5z"/>'],
            'movies'    => ['Movies', '#ef4444', '<rect x="3" y="9" width="18" height="12" rx="2"/><path d="m3 9 2.5-5 3.5 1.5L6.5 9M9 5.5 12.5 4 15 9M12.5 4l4 1.5L14 9M16.5 5.5 20 7l-1.5 2"/>'],
            'live'      => ['Live', '#ec4899', '<circle cx="12" cy="12" r="2"/><path d="M16.2 7.8a6 6 0 0 1 0 8.4M7.8 16.2a6 6 0 0 1 0-8.4M19.1 4.9a10 10 0 0 1 0 14.2M4.9 19.1a10 10 0 0 1 0-14.2"/>'],
            'sports'    => ['Sports', '#10b981', '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>'],
            'news'      => ['News', '#3b82f6', '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 8h6v5H7zM16 8h1M16 11h1M7 16h10"/>'],
            'fashion'   => ['Fashion', '#d946ef', '<path d="M8 3 3 6l2 4 3-1v12h8V9l3 1 2-4-5-3a4 4 0 0 1-8 0z"/>'],
            'education' => ['Education', '#0ea5e9', '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5M22 9v6"/>'],
            'anime'     => ['Anime', '#f43f5e', '<path d="M12 3l1.8 4.6L18.5 9l-4.7 1.4L12 15l-1.8-4.6L5.5 9l4.7-1.4z"/><path d="M19 15l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>'],
            'facts'     => ['Facts', '#eab308', '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.6.5 1 1.2 1 2.1h5c0-.9.4-1.6 1-2.1A6 6 0 0 0 12 3z"/>'],
            'comedy'    => ['Comedy', '#f97316', '<circle cx="12" cy="12" r="9"/><path d="M8 14a4 4 0 0 0 8 0"/><path d="M9 9h.01M15 9h.01"/>'],
            'food'      => ['Food', '#84cc16', '<path d="M6 3v8a3 3 0 0 0 3 3v7M9 3v6M12 3v8M18 3c-2 2-3 5-3 8h3v10"/>'],
            'travel'    => ['Travel', '#14b8a6', '<path d="M22 2 11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>'],
            'tech'      => ['Tech', '#6366f1', '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'],
            'cars'      => ['Cars', '#64748b', '<path d="M5 16l1.5-5a2 2 0 0 1 1.9-1.4h7.2a2 2 0 0 1 1.9 1.4L19 16"/><rect x="3" y="16" width="18" height="4" rx="1.5"/><path d="M7 18h.01M17 18h.01"/>'],
            'animals'   => ['Animals', '#a16207', '<circle cx="7" cy="9" r="1.6"/><circle cx="12" cy="6.5" r="1.6"/><circle cx="17" cy="9" r="1.6"/><path d="M12 12c-3 0-5 3-5 5a2.5 2.5 0 0 0 3 2.4c1.2-.3 1.5-.4 2-.4s.8.1 2 .4a2.5 2.5 0 0 0 3-2.4c0-2-2-5-5-5z"/>'],
            'art'       => ['Art', '#c026d3', '<path d="M12 3a9 9 0 1 0 0 18c1.1 0 2-.9 2-2 0-.6-.3-1.1-.6-1.5-.3-.4-.4-.9-.4-1.5a2 2 0 0 1 2-2h2.3A3.7 3.7 0 0 0 21 10.3C21 6.2 17 3 12 3z"/><path d="M7.5 11h.01M10 7.5h.01M14.5 7.5h.01"/>'],
        ];
    }
}

if (!function_exists('vs_category_icon_svg')) {
    /** Icon for a category name (or slug). Unknown categories get a tag icon. */
    function vs_category_icon_svg(string $name, int $size = 22): string
    {
        $slug = strtolower(trim($name));
        $presets = vs_category_presets();
        $inner = isset($presets[$slug]) ? $presets[$slug][2] : '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8.5" r="1.2"/>';
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $inner . '</svg>';
    }
}
