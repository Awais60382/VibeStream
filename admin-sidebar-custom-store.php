<?php
/*
 * VibeStream - custom ADMIN sidebar menu store.
 * Put this file in the SAME folder as sidebar-icons-store.php (the main VibeStream folder).
 *
 * Lets the site owner add their own sections and menu items to the admin panel sidebar
 * (Site icons page > "Admin Sidebar Menu"). Settings are kept in
 * uploads/site-branding/admin-sidebar-custom.json and item icons in
 * uploads/site-branding/asb-<random>.<png|jpg|webp|gif>. Nothing else is touched.
 */

if (!defined('VS_ACSB_FILE')) {
    define('VS_ACSB_DIR', 'uploads/site-branding');
    define('VS_ACSB_FILE', 'uploads/site-branding/admin-sidebar-custom.json');
    define('VS_ACSB_MAX_ICON', 512 * 1024);
    define('VS_ACSB_MAX_SECTIONS', 20);
    define('VS_ACSB_MAX_ITEMS', 60);
}

/** The sections the admin sidebar already has. An item can be added to the end of any of them. */
function vs_acsb_builtin_sections(): array
{
    return [
        'main'       => 'Main',
        'mywork'     => 'My work',
        'users'      => 'Users',
        'system'     => 'System',
        'navigation' => 'Navigation',
    ];
}

/** Where a brand-new custom section can be placed. */
function vs_acsb_places(): array
{
    return [
        'top'               => 'At the top',
        'before_navigation' => 'Above Navigation',
        'bottom'            => 'At the bottom',
    ];
}

function vs_acsb_text(string $value, int $max): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $max);
}

/** Accepts a page like users.php (or folder/page.php?x=1) or a full http(s) link. Throws on anything else. */
function vs_acsb_clean_href(string $href): string
{
    $href = trim($href);
    if ($href === '' || strlen($href) > 300 || preg_match('/[\x00-\x20\x7F\\\\"<>]/', $href)) {
        throw new RuntimeException('Enter a valid link, for example users.php or https://example.com.');
    }
    if (preg_match('~^https?://~i', $href)) {
        if (filter_var($href, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('That link does not look valid.');
        }
        return $href;
    }
    if (strpos($href, '//') === 0 || preg_match('~^[a-z][a-z0-9+.\-]*:~i', $href)) {
        throw new RuntimeException('Use a page name like users.php, or a full http(s):// link.');
    }
    return $href;
}

/** Loads the saved menu: ['sections' => [[id,title,place]], 'items' => [[id,label,href,section,icon]]]. */
function vs_acsb_load(): array
{
    if (isset($GLOBALS['vs_acsb_cache']) && is_array($GLOBALS['vs_acsb_cache'])) {
        return $GLOBALS['vs_acsb_cache'];
    }
    $clean = ['sections' => [], 'items' => []];
    $path = __DIR__ . '/' . VS_ACSB_FILE;
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $data = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($data)) {
        $places = vs_acsb_places();
        $valid = array_keys(vs_acsb_builtin_sections());
        foreach ((array)($data['sections'] ?? []) as $s) {
            if (!is_array($s)) {
                continue;
            }
            $id = (string)($s['id'] ?? '');
            $title = vs_acsb_text((string)($s['title'] ?? ''), 40);
            $place = (string)($s['place'] ?? '');
            if (!preg_match('/^s[a-f0-9]{12}$/', $id) || $title === '') {
                continue;
            }
            if (!isset($places[$place])) {
                $place = 'before_navigation';
            }
            $clean['sections'][] = ['id' => $id, 'title' => $title, 'place' => $place];
            $valid[] = $id;
        }
        foreach ((array)($data['items'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $id = (string)($it['id'] ?? '');
            $label = vs_acsb_text((string)($it['label'] ?? ''), 40);
            $section = (string)($it['section'] ?? '');
            $icon = (string)($it['icon'] ?? '');
            if (!preg_match('/^i[a-f0-9]{12}$/', $id) || $label === '' || !in_array($section, $valid, true)) {
                continue;
            }
            try {
                $href = vs_acsb_clean_href((string)($it['href'] ?? ''));
            } catch (RuntimeException $e) {
                continue;
            }
            if ($icon !== '' && !preg_match('/^asb-[a-f0-9]{32}\.(png|jpg|webp|gif)$/', $icon)) {
                $icon = '';
            }
            $clean['items'][] = ['id' => $id, 'label' => $label, 'href' => $href, 'section' => $section, 'icon' => $icon];
        }
    }
    return $GLOBALS['vs_acsb_cache'] = $clean;
}

function vs_acsb_save(array $data): void
{
    $dir = __DIR__ . '/' . VS_ACSB_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the uploads/site-branding folder.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents(__DIR__ . '/' . VS_ACSB_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('Could not save the admin menu. Check folder permissions.');
    }
    unset($GLOBALS['vs_acsb_cache']);
}

/** "Where" choices for a new item: the built-in sections first, then the owner's own sections. */
function vs_acsb_section_choices(array $data): array
{
    $choices = [];
    foreach (vs_acsb_builtin_sections() as $key => $title) {
        $choices[$key] = $title . ' (existing section)';
    }
    foreach ($data['sections'] as $s) {
        $choices[$s['id']] = $s['title'];
    }
    return $choices;
}

function vs_acsb_delete_icon_file(string $icon): void
{
    if (preg_match('/^asb-[a-f0-9]{32}\.(png|jpg|webp|gif)$/', $icon)) {
        $path = __DIR__ . '/' . VS_ACSB_DIR . '/' . $icon;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** Saves an uploaded icon and returns its file name, or '' when no file was chosen. */
function vs_acsb_store_icon(array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The icon upload failed. Please try again.');
    }
    if ((int)($file['size'] ?? 0) > VS_ACSB_MAX_ICON) {
        throw new RuntimeException('The icon must be smaller than 512 KB.');
    }
    $info = @getimagesize($tmp);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2] ?? 0])) {
        throw new RuntimeException('The icon must be a PNG, JPG, WEBP or GIF image.');
    }
    if ((int)$info[0] < 16 || (int)$info[1] < 16 || (int)$info[0] > 2048 || (int)$info[1] > 2048) {
        throw new RuntimeException('The icon must be between 16 and 2048 pixels wide and tall.');
    }
    $dir = __DIR__ . '/' . VS_ACSB_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the uploads/site-branding folder.');
    }
    $name = 'asb-' . bin2hex(random_bytes(16)) . '.' . $types[$info[2]];
    $dest = $dir . '/' . $name;
    if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save the icon. Check folder permissions.');
    }
    @chmod($dest, 0644);
    return $name;
}

function vs_acsb_add_section(string $title, string $place): void
{
    $title = vs_acsb_text($title, 40);
    if ($title === '') {
        throw new RuntimeException('Enter a section name.');
    }
    if (!isset(vs_acsb_places()[$place])) {
        throw new RuntimeException('Choose where the section should go.');
    }
    $data = vs_acsb_load();
    if (count($data['sections']) >= VS_ACSB_MAX_SECTIONS) {
        throw new RuntimeException('You can have at most ' . VS_ACSB_MAX_SECTIONS . ' custom sections.');
    }
    foreach ($data['sections'] as $s) {
        if (mb_strtolower($s['title']) === mb_strtolower($title)) {
            throw new RuntimeException('A section with that name already exists.');
        }
    }
    $data['sections'][] = ['id' => 's' . bin2hex(random_bytes(6)), 'title' => $title, 'place' => $place];
    vs_acsb_save($data);
}

function vs_acsb_delete_section(string $id): void
{
    $data = vs_acsb_load();
    $found = false;
    foreach ($data['sections'] as $i => $s) {
        if ($s['id'] === $id) {
            $found = true;
            unset($data['sections'][$i]);
        }
    }
    if (!$found) {
        throw new RuntimeException('That section no longer exists.');
    }
    $keep = [];
    foreach ($data['items'] as $it) {
        if ($it['section'] === $id) {
            vs_acsb_delete_icon_file($it['icon']);
        } else {
            $keep[] = $it;
        }
    }
    $data['sections'] = array_values($data['sections']);
    $data['items'] = $keep;
    vs_acsb_save($data);
}

function vs_acsb_add_item(string $label, string $href, string $section, array $file): void
{
    $label = vs_acsb_text($label, 40);
    if ($label === '') {
        throw new RuntimeException('Enter a name for the menu item.');
    }
    $href = vs_acsb_clean_href($href);
    $data = vs_acsb_load();
    if (!isset(vs_acsb_section_choices($data)[$section])) {
        throw new RuntimeException('Choose a section for the menu item.');
    }
    if (count($data['items']) >= VS_ACSB_MAX_ITEMS) {
        throw new RuntimeException('You can have at most ' . VS_ACSB_MAX_ITEMS . ' custom menu items.');
    }
    $icon = vs_acsb_store_icon($file);
    $data['items'][] = ['id' => 'i' . bin2hex(random_bytes(6)), 'label' => $label, 'href' => $href, 'section' => $section, 'icon' => $icon];
    try {
        vs_acsb_save($data);
    } catch (Throwable $e) {
        vs_acsb_delete_icon_file($icon);
        throw $e;
    }
}

function vs_acsb_set_item_icon(string $id, array $file): void
{
    $data = vs_acsb_load();
    foreach ($data['items'] as $i => $it) {
        if ($it['id'] !== $id) {
            continue;
        }
        $icon = vs_acsb_store_icon($file);
        if ($icon === '') {
            throw new RuntimeException('Choose an icon file first.');
        }
        $old = $it['icon'];
        $data['items'][$i]['icon'] = $icon;
        try {
            vs_acsb_save($data);
        } catch (Throwable $e) {
            vs_acsb_delete_icon_file($icon);
            throw $e;
        }
        vs_acsb_delete_icon_file($old);
        return;
    }
    throw new RuntimeException('That menu item no longer exists.');
}

function vs_acsb_delete_item(string $id): void
{
    $data = vs_acsb_load();
    foreach ($data['items'] as $i => $it) {
        if ($it['id'] === $id) {
            unset($data['items'][$i]);
            $data['items'] = array_values($data['items']);
            vs_acsb_save($data);
            vs_acsb_delete_icon_file($it['icon']);
            return;
        }
    }
    throw new RuntimeException('That menu item no longer exists.');
}

/** URL of an item icon as seen from the adminpanel folder. */
function vs_acsb_icon_url(string $icon): string
{
    $v = (int)@filemtime(__DIR__ . '/' . VS_ACSB_DIR . '/' . $icon);
    return '../' . VS_ACSB_DIR . '/' . $icon . '?v=' . $v;
}

/** One sidebar link, built exactly like the built-in ones. */
function vs_acsb_item_html(array $it, string $current): string
{
    $href = (string)$it['href'];
    $external = (bool)preg_match('~^https?://~i', $href);
    $path = $external ? '' : (string)(parse_url($href, PHP_URL_PATH) ?? '');
    $active = !$external && $path !== '' && $path === $current;
    $icon = $it['icon'] !== ''
        ? '<img src="' . htmlspecialchars(vs_acsb_icon_url($it['icon']), ENT_QUOTES, 'UTF-8') . '" alt="" width="20" height="20" style="display:block;flex:0 0 auto;object-fit:contain">'
        : '<i class="bi bi-link-45deg" aria-hidden="true"></i>';
    return '<a class="nav-link' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
        . ($active ? ' aria-current="page"' : '')
        . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . '>'
        . '<span class="nav-icon">' . $icon . '</span>'
        . '<span class="nav-text">' . htmlspecialchars((string)$it['label'], ENT_QUOTES, 'UTF-8') . '</span></a>' . "\n";
}

/** Prints the custom items that belong at the end of one built-in section (main, mywork, users, system, navigation). */
function vs_acsb_render_builtin(string $key, string $current): void
{
    foreach (vs_acsb_load()['items'] as $it) {
        if ($it['section'] === $key) {
            echo vs_acsb_item_html($it, $current);
        }
    }
}

/** Prints the owner's own sections for one place (top, before_navigation, bottom). Empty sections are skipped. */
function vs_acsb_render_places(string $place, string $current): void
{
    $data = vs_acsb_load();
    foreach ($data['sections'] as $s) {
        if ($s['place'] !== $place) {
            continue;
        }
        $rows = '';
        foreach ($data['items'] as $it) {
            if ($it['section'] === $s['id']) {
                $rows .= vs_acsb_item_html($it, $current);
            }
        }
        if ($rows !== '') {
            echo '<div class="sidebar-section-title">' . htmlspecialchars(mb_strtoupper($s['title']), ENT_QUOTES, 'UTF-8') . "</div>\n" . $rows;
        }
    }
}