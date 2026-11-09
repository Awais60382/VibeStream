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
            'podcasts'   => ['Podcasts', '#7c3aed', '<rect x="9" y="2" width="6" height="11" rx="3"/><path d="M5 10v1a7 7 0 0 0 14 0v-1M12 18v4M8 22h8"/>'],
            'fitness'    => ['Fitness', '#22c55e', '<path d="M6.5 6.5v11M17.5 6.5v11M3 9v6M21 9v6M6.5 12h11"/>'],
            'beauty'     => ['Beauty', '#fb7185', '<circle cx="12" cy="9" r="6"/><path d="M12 15v7M9 22h6"/>'],
            'science'    => ['Science', '#06b6d4', '<path d="M9 3h6M10 3v6L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9V3"/><path d="M7.5 15h9"/>'],
            'kids'       => ['Kids', '#f472b6', '<circle cx="12" cy="9" r="6"/><path d="M11 15l-1 2h4l-1-2M12 17c0 2-2 3-2 5"/>'],
            'diy'        => ['DIY', '#ea580c', '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>'],
            'business'   => ['Business', '#475569', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>'],
            'health'     => ['Health', '#e11d48', '<path d="M20.8 5.6a5.5 5.5 0 0 0-7.8 0L12 6.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 22l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/>'],
            'nature'     => ['Nature', '#16a34a', '<path d="M11 20A7 7 0 0 1 4 13c0-5 4-9 16-10 0 12-4 17-9 17z"/><path d="M4 21c2-6 6-9 11-11"/>'],
            'history'    => ['History', '#b45309', '<path d="M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10M2 10l10-6 10 6z"/>'],
            'motivation' => ['Motivation', '#dc2626', '<path d="M12 22c4 0 7-2.8 7-7 0-3-2-5-3.5-7C15 10 13 11 12 11c0-3-1-6-3-9-1 4-5 6-5 12 0 4.2 3 8 8 8z"/>'],
            'vlogs'      => ['Vlogs', '#0891b2', '<path d="M3 7h4l2-3h6l2 3h4v13H3z"/><circle cx="12" cy="13" r="4"/>'],
            'cricket' => ['Cricket', '#16a34a', '<path d="m14.5 3.5 6 6-9.5 9.5-6-6z"/><path d="M4 20l3-3"/><circle cx="19" cy="19" r="1.6"/>'],
            'football' => ['Football', '#15803d', '<circle cx="12" cy="12" r="9"/><path d="M12 8l3.5 2.5-1.3 4H9.8l-1.3-4z"/><path d="M12 3v5M20.5 9.5l-5 1M17.5 19l-3.3-4.5M6.5 19l3.3-4.5M3.5 9.5l5 1"/>'],
            'cartoons' => ['Cartoons', '#fb923c', '<circle cx="12" cy="13" r="8"/><circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M9 13h.01M15 13h.01M10 17c1.2 1 2.8 1 4 0"/>'],
            'horror' => ['Horror', '#7f1d1d', '<path d="M12 3a8 8 0 0 0-5 14.2V20h10v-2.8A8 8 0 0 0 12 3z"/><path d="M9 12h.01M15 12h.01M10 20v-2M14 20v-2"/>'],
            'crime' => ['Crime', '#334155', '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>'],
            'drama' => ['Drama', '#a855f7', '<path d="M4 4h10v7a5 5 0 0 1-10 0z"/><path d="M7 8h.01M11 8h.01M7 12c1 1 3 1 4 0"/><path d="M14 8h6v6a4 4 0 0 1-6.5 3"/>'],
            'reviews' => ['Reviews', '#eab308', '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>'],
            'coding' => ['Coding', '#0f766e', '<path d="m8 8-5 4 5 4M16 8l5 4-5 4M14 5l-4 14"/>'],
            'photography' => ['Photography', '#0284c7', '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.5"/><path d="M12 3v5.5M20 8l-5 3M20 16l-5-3M12 21v-5.5M4 16l5-3M4 8l5 3"/>'],
            'dance' => ['Dance', '#e879f9', '<circle cx="12" cy="5" r="2"/><path d="M12 7v6M12 13l-3 8M12 13l3 8M8 10l4 1 4-3"/>'],
            'islamic' => ['Islamic', '#059669', '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/><path d="m17 5 .6 1.4L19 7l-1.4.6L17 9l-.6-1.4L15 7l1.4-.6z"/>'],
            'documentary' => ['Documentary', '#78716c', '<path d="M4 4h16v16H4z"/><path d="M4 9h16M4 15h16M9 4v16M15 4v16"/>'],
            'finance' => ['Finance', '#65a30d', '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M14.5 9.5c-.6-.8-1.5-1.2-2.5-1.2-1.4 0-2.5.8-2.5 1.9 0 2.6 5 1.3 5 3.9 0 1.1-1.1 1.9-2.5 1.9-1 0-1.9-.4-2.5-1.2"/>'],
            'space' => ['Space', '#4f46e5', '<circle cx="12" cy="12" r="5"/><path d="M2.5 14.5c-1-1.6 3.5-6.2 9.5-8M21.5 9.5c1 1.6-3.5 6.2-9.5 8"/>'],
            'memes' => ['Memes', '#facc15', '<circle cx="12" cy="12" r="9"/><path d="M8 14h8M10 14v2.5a2 2 0 0 0 4 0V14M9 9h.01M15 9h.01"/>'],
            'bikes' => ['Bikes', '#0d9488', '<circle cx="6" cy="16" r="3.5"/><circle cx="18" cy="16" r="3.5"/><path d="M6 16l4-8h5l3 8M10 8H8M13 8l-3 8"/>'],
            'asmr' => ['ASMR', '#c084fc', '<path d="M4 15v-3a8 8 0 0 1 16 0v3"/><rect x="3" y="14" width="4" height="7" rx="1.5"/><rect x="17" y="14" width="4" height="7" rx="1.5"/>'],
            'trending' => ['Trending', '#f43f5e', '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>'],
            'shopping' => ['Shopping', '#f97316', '<path d="M6 7h12l1 14H5z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>'],
            'lifehacks' => ['Lifehacks', '#2dd4bf', '<path d="m5 19 9-9M13 4l1.2 2.8L17 8l-2.8 1.2L13 12l-1.2-2.8L9 8l2.8-1.2z"/><path d="M18 14l.8 1.7 1.7.8-1.7.8L18 19l-.8-1.7-1.7-.8 1.7-.8z"/>'],
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