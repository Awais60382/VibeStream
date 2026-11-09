<?php
/**
 * Custom sidebar sections and menu items, made by the owner on Admin > Site icons.
 * Put this file in the SITE ROOT (next to sidebar-navigation.php).
 *
 * Everything is saved in uploads/sidebar-custom/custom.json (icons next to it), so the new items
 * show on the main site for every visitor. No database table is needed.
 *
 *   vs_csb_load()                      ['sections' => [...], 'items' => [...]]
 *   vs_csb_add_section($title, $place) place: 'before_explore' (default) or 'bottom'
 *   vs_csb_delete_section($id)
 *   vs_csb_add_item($label, $href, $section, $file)   $section: main | you | explore | <custom section id>
 *   vs_csb_set_item_icon($id, $file)
 *   vs_csb_delete_item($id)
 *   vs_csb_icon_url($file)             path of an icon, relative to the site root
 *
 * The add / change / delete functions throw RuntimeException with a message that is safe to show.
 */
if (!function_exists('vs_csb_load')) {

    if (!defined('VS_CSB_MAX_SECTIONS')) {
        define('VS_CSB_MAX_SECTIONS', 10);
        define('VS_CSB_MAX_ITEMS', 40);
        define('VS_CSB_ICON_MAX_BYTES', 524288);   // 512 KB
    }

    function vs_csb_dir(): string
    {
        return __DIR__ . '/uploads/sidebar-custom';
    }

    function vs_csb_file(): string
    {
        return vs_csb_dir() . '/custom.json';
    }

    function vs_csb_icon_url(string $file): string
    {
        return 'uploads/sidebar-custom/' . $file;
    }

    /** Only links that are safe in a menu: http(s) addresses, or plain site paths like page.php?x=1 or #top. */
    function vs_csb_valid_href(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || mb_strlen($href) > 300) {
            return null;
        }
        if (preg_match('#^https?://#i', $href)) {
            return filter_var($href, FILTER_VALIDATE_URL) ? $href : null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) || strpos($href, '//') === 0 || strpos($href, '..') !== false) {
            return null;   // javascript:, data:, mailto: ..., protocol-less //host, parent folders
        }
        return preg_match('#^[A-Za-z0-9_\-./?=&%\#+,:@~]+$#', $href) ? $href : null;
    }

    function vs_csb_clean_icon(string $file): string
    {
        return preg_match('/^[a-f0-9]{16}\.(png|jpg|webp|gif)$/', $file) ? $file : '';
    }

    function vs_csb_load(): array
    {
        $data = ['sections' => [], 'items' => []];
        $path = vs_csb_file();
        if (!is_file($path)) {
            return $data;
        }
        $json = json_decode((string)@file_get_contents($path), true);
        if (!is_array($json)) {
            return $data;
        }
        $ids = [];
        foreach ((array)($json['sections'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($s['id'] ?? '')));
            $title = trim((string)($s['title'] ?? ''));
            if ($id === '' || $title === '' || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = true;
            $data['sections'][] = [
                'id' => $id,
                'title' => mb_substr($title, 0, 40),
                'place' => (($s['place'] ?? '') === 'bottom') ? 'bottom' : 'before_explore',
            ];
        }
        $itemIds = [];
        foreach ((array)($json['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($it['id'] ?? '')));
            $label = trim((string)($it['label'] ?? ''));
            $href = vs_csb_valid_href((string)($it['href'] ?? ''));
            $section = (string)($it['section'] ?? '');
            if ($id === '' || $label === '' || $href === null || isset($itemIds[$id])) {
                continue;
            }
            if (!in_array($section, ['main', 'you', 'explore'], true) && !isset($ids[$section])) {
                continue;   // its custom section was deleted
            }
            $itemIds[$id] = true;
            $data['items'][] = [
                'id' => $id,
                'label' => mb_substr($label, 0, 40),
                'href' => $href,
                'section' => $section,
                'icon' => vs_csb_clean_icon((string)($it['icon'] ?? '')),
            ];
        }
        return $data;
    }

    function vs_csb_save(array $data): void
    {
        $dir = vs_csb_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the uploads/sidebar-custom folder. Check folder permissions.');
        }
        $json = json_encode(
            ['sections' => array_values($data['sections']), 'items' => array_values($data['items'])],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($json === false || @file_put_contents(vs_csb_file(), $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the sidebar settings. Check folder permissions.');
        }
    }

    function vs_csb_new_id(): string
    {
        return bin2hex(random_bytes(4));
    }

    /** Where a new menu item can go: the built-in sections plus the owner's own. [key => label] */
    function vs_csb_section_choices(array $data): array
    {
        $choices = ['main' => 'Main (Home, Shorts, Watch)', 'you' => 'You (Your Channel, History ...)', 'explore' => 'Explore (categories)'];
        foreach ($data['sections'] as $s) {
            $choices[$s['id']] = $s['title'] . ' (your section)';
        }
        return $choices;
    }

    /** Saves an uploaded icon image and returns its file name, or '' when no file was chosen. */
    function vs_csb_save_icon(array $file): string
    {
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return '';
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The icon upload failed. Please try again.');
        }
        if ((int)($file['size'] ?? 0) > VS_CSB_ICON_MAX_BYTES) {
            throw new RuntimeException('The icon must be smaller than 512 KB.');
        }
        $info = @getimagesize($tmp);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (!$info || !isset($types[$info[2] ?? 0])) {
            throw new RuntimeException('Use a PNG, JPG, WEBP or GIF image for the icon.');
        }
        $dir = vs_csb_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the uploads/sidebar-custom folder. Check folder permissions.');
        }
        $name = bin2hex(random_bytes(8)) . '.' . $types[$info[2]];
        if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new RuntimeException('Could not save the icon. Check folder permissions.');
        }
        @chmod($dir . '/' . $name, 0644);
        return $name;
    }

    function vs_csb_remove_icon_file(string $file): void
    {
        $file = vs_csb_clean_icon($file);
        if ($file !== '' && is_file(vs_csb_dir() . '/' . $file)) {
            @unlink(vs_csb_dir() . '/' . $file);
        }
    }

    function vs_csb_add_section(string $title, string $place): void
    {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 40) {
            throw new RuntimeException('Give the section a name of up to 40 characters.');
        }
        $data = vs_csb_load();
        if (count($data['sections']) >= VS_CSB_MAX_SECTIONS) {
            throw new RuntimeException('You can have up to ' . VS_CSB_MAX_SECTIONS . ' sections.');
        }
        foreach ($data['sections'] as $s) {
            if (mb_strtolower($s['title']) === mb_strtolower($title)) {
                throw new RuntimeException('A section called "' . $title . '" already exists.');
            }
        }
        $data['sections'][] = ['id' => vs_csb_new_id(), 'title' => $title, 'place' => $place === 'bottom' ? 'bottom' : 'before_explore'];
        vs_csb_save($data);
    }

    function vs_csb_delete_section(string $id): void
    {
        $data = vs_csb_load();
        $found = false;
        foreach ($data['sections'] as $i => $s) {
            if ($s['id'] === $id) {
                unset($data['sections'][$i]);
                $found = true;
            }
        }
        if (!$found) {
            throw new RuntimeException('That section was not found.');
        }
        foreach ($data['items'] as $i => $it) {
            if ($it['section'] === $id) {
                vs_csb_remove_icon_file($it['icon']);
                unset($data['items'][$i]);
            }
        }
        vs_csb_save($data);
    }

    function vs_csb_add_item(string $label, string $href, string $section, array $file): void
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 40) {
            throw new RuntimeException('Give the menu item a name of up to 40 characters.');
        }
        $cleanHref = vs_csb_valid_href($href);
        if ($cleanHref === null) {
            throw new RuntimeException('That link is not allowed. Use a page like about.php, or a full address starting with https://.');
        }
        $data = vs_csb_load();
        if (!isset(vs_csb_section_choices($data)[$section])) {
            throw new RuntimeException('Choose which section the item belongs to.');
        }
        if (count($data['items']) >= VS_CSB_MAX_ITEMS) {
            throw new RuntimeException('You can have up to ' . VS_CSB_MAX_ITEMS . ' custom menu items.');
        }
        $icon = vs_csb_save_icon($file);
        $data['items'][] = ['id' => vs_csb_new_id(), 'label' => $label, 'href' => $cleanHref, 'section' => $section, 'icon' => $icon];
        try {
            vs_csb_save($data);
        } catch (Throwable $e) {
            vs_csb_remove_icon_file($icon);
            throw $e;
        }
    }

    function vs_csb_set_item_icon(string $id, array $file): void
    {
        $data = vs_csb_load();
        foreach ($data['items'] as $i => $it) {
            if ($it['id'] === $id) {
                $icon = vs_csb_save_icon($file);
                if ($icon === '') {
                    throw new RuntimeException('Choose an icon file first.');
                }
                $old = $it['icon'];
                $data['items'][$i]['icon'] = $icon;
                try {
                    vs_csb_save($data);
                } catch (Throwable $e) {
                    vs_csb_remove_icon_file($icon);
                    throw $e;
                }
                vs_csb_remove_icon_file($old);
                return;
            }
        }
        throw new RuntimeException('That menu item was not found.');
    }

    function vs_csb_delete_item(string $id): void
    {
        $data = vs_csb_load();
        foreach ($data['items'] as $i => $it) {
            if ($it['id'] === $id) {
                unset($data['items'][$i]);
                vs_csb_save($data);
                vs_csb_remove_icon_file($it['icon']);
                return;
            }
        }
        throw new RuntimeException('That menu item was not found.');
    }
}
