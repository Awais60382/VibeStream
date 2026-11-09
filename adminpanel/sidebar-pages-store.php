<?php
/*
 * VibeStream admin panel - extra sidebar pages (buttons the owner adds himself).
 * Put this file in the adminpanel folder. The owner adds / removes pages on Site Icons (site-icons.php);
 * header.php shows them in the sidebar under "MY PAGES". Saved in adminpanel/data/sidebar-pages.json.
 */
const VS_SP_MAX = 20;
const VS_SP_CONTENT_MAX = 20000;   // characters of text in a page written in the admin panel
const VS_SP_IMG_DIR = 'uploads/sidebar-pages';   // photos the owner uploads (relative to the site root, one level above adminpanel)

function vs_sp_file(): string
{
    return __DIR__ . '/data/sidebar-pages.json';
}

/** A button picture: a full https:// image address, or a file we saved in uploads/sidebar-pages. Anything else is refused. */
function vs_sp_clean_image(string $v): string
{
    $v = trim($v);
    if (preg_match('~^https://[^\s<>"\']{3,300}$~i', $v)) {
        return $v;
    }
    if (preg_match('~^' . preg_quote(VS_SP_IMG_DIR, '~') . '/[a-f0-9]{16,40}\.(?:png|jpg|webp|gif)$~', $v)) {
        return $v;
    }
    return '';
}

/** Saves an uploaded photo for a button and returns its path, or '' when no file was chosen. */
function vs_sp_save_image(array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The photo upload failed. Please try again.');
    }
    if ((int)($file['size'] ?? 0) > 1024 * 1024) {
        throw new RuntimeException('The photo must be smaller than 1 MB.');
    }
    $info = @getimagesize($tmp);
    $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2] ?? 0])) {
        throw new RuntimeException('The photo must be a PNG, JPG, WEBP or GIF image.');
    }
    $dir = dirname(__DIR__) . '/' . VS_SP_IMG_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the ' . VS_SP_IMG_DIR . ' folder.');
    }
    $name = bin2hex(random_bytes(12)) . '.' . $types[$info[2]];
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the photo. Check folder permissions.');
    }
    @chmod($dir . '/' . $name, 0644);
    return VS_SP_IMG_DIR . '/' . $name;
}

/** Address to put in <img src> for a stored picture (we are inside adminpanel, uploads are one level up). */
function vs_sp_image_src(string $img): string
{
    return preg_match('~^https://~i', $img) ? $img : '../' . $img;
}

/** @return array<int,array{id:string,label:string,url:string,icon:string,image:string,owner_only:bool,kind:string,content:string}> */
function vs_sp_load(): array
{
    $f = vs_sp_file();
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $out = [];
    foreach (is_array($d) ? $d : [] as $p) {
        if (!is_array($p) || !isset($p['id'], $p['label'], $p['url'])) {
            continue;
        }
        $url = vs_sp_clean_url((string)$p['url']);
        if ($url === null) {
            continue;
        }
        $out[] = [
            'id' => (string)$p['id'],
            'label' => mb_substr(trim((string)$p['label']), 0, 30),
            'url' => $url,
            'icon' => preg_match('/^[a-z0-9-]{2,40}$/', (string)($p['icon'] ?? '')) ? (string)$p['icon'] : 'link-45deg',
            'image' => vs_sp_clean_image((string)($p['image'] ?? '')),
            'owner_only' => !empty($p['owner_only']),
            'kind' => (($p['kind'] ?? 'link') === 'page') ? 'page' : 'link',   // 'page' = written inside the admin panel
            'content' => mb_substr((string)($p['content'] ?? ''), 0, VS_SP_CONTENT_MAX),
        ];
    }
    return $out;
}

function vs_sp_save(array $pages): void
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the data folder.');
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (@file_put_contents(vs_sp_file(), json_encode(array_values($pages), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Could not save the sidebar pages. Check folder permissions.');
    }
}

/** A page in this folder (my-page.php, my-page.php?tab=2) or a full https:// address. Anything else is refused. */
function vs_sp_clean_url(string $u): ?string
{
    $u = trim($u);
    if (preg_match('~^https?://[^\s<>"\']{3,300}$~i', $u)) {
        return $u;
    }
    if (preg_match('~^/[^?\s<>"\']*\.php(?:\?[A-Za-z0-9_=&.-]{0,100})?$~i', $u)) {
        $path = parse_url($u, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';
        $adminMarker = '/adminpanel/';
        $markerPos = stripos($path, $adminMarker);
        if ($markerPos !== false) {
            $relative = substr($path, $markerPos + strlen($adminMarker));
            $query = parse_url($u, PHP_URL_QUERY);
            return $relative !== '' ? ($relative . ($query !== null && $query !== '' ? '?' . $query : '')) : null;
        }
    }
    if (preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*\.php(?:\?[A-Za-z0-9_=&.-]{0,100})?$~', $u)) {
        return $u;
    }
    return null;
}

function vs_sp_add(string $label, string $url, string $icon, bool $ownerOnly, string $imageUrl = '', array $imageFile = []): void
{
    $label = mb_substr(trim($label), 0, 30);
    if ($label === '') {
        throw new RuntimeException('Write a name for the button.');
    }
    $clean = vs_sp_clean_url($url);
    if ($clean === null) {
        throw new RuntimeException('The page must be a file in the adminpanel folder (like my-page.php) or a full https:// address.');
    }
    $icon = strtolower(trim($icon));
    $icon = preg_replace('/^bi[- ]+/', '', $icon);
    if ($icon === '' || !preg_match('/^[a-z0-9-]{2,40}$/', $icon)) {
        $icon = 'link-45deg';
    }
    $image = '';
    if (trim($imageUrl) !== '') {
        $image = vs_sp_clean_image($imageUrl);
        if ($image === '' || !preg_match('~^https://~i', $image)) {
            throw new RuntimeException('The picture address must start with https://');
        }
    }
    $pages = vs_sp_load();
    if (count($pages) >= VS_SP_MAX) {
        throw new RuntimeException('You can add up to ' . VS_SP_MAX . ' pages. Remove one first.');
    }
    foreach ($pages as $p) {
        if (mb_strtolower($p['label']) === mb_strtolower($label)) {
            throw new RuntimeException('There is already a button named "' . $label . '".');
        }
    }
    if ($image === '' && $imageFile) {
        $image = vs_sp_save_image($imageFile);   // an uploaded photo is used when no picture address was given
    }
    $pages[] = ['id' => bin2hex(random_bytes(6)), 'label' => $label, 'url' => $clean, 'icon' => $icon, 'image' => $image, 'owner_only' => $ownerOnly];
    vs_sp_save($pages);
}

/** One written page by its id (null when it does not exist). */
function vs_sp_find(string $id): ?array
{
    foreach (vs_sp_load() as $p) {
        if ($p['kind'] === 'page' && $p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/**
 * Creates a page written inside the admin panel (opens as admin-page.php?p=ID and gets its own sidebar button),
 * or updates it when $id is given. A new photo replaces the old one; no photo keeps it.
 */
function vs_sp_save_page(string $label, string $content, string $icon, bool $ownerOnly, array $imageFile = [], string $id = ''): string
{
    $label = mb_substr(trim($label), 0, 30);
    if ($label === '') {
        throw new RuntimeException('Write a name for the page.');
    }
    $content = str_replace(["\r\n", "\r"], "\n", trim($content));
    if ($content === '') {
        throw new RuntimeException('Write something in the page.');
    }
    if (mb_strlen($content) > VS_SP_CONTENT_MAX) {
        throw new RuntimeException('The page is too long (the limit is ' . VS_SP_CONTENT_MAX . ' characters).');
    }
    $icon = preg_replace('/^bi[- ]+/', '', strtolower(trim($icon)));
    if ($icon === '' || !preg_match('/^[a-z0-9-]{2,40}$/', $icon)) {
        $icon = 'file-earmark-text';
    }
    $pages = vs_sp_load();
    $index = null;
    foreach ($pages as $i => $p) {
        if (mb_strtolower($p['label']) === mb_strtolower($label) && $p['id'] !== $id) {
            throw new RuntimeException('There is already a button named "' . $label . '".');
        }
        if ($id !== '' && $p['id'] === $id && $p['kind'] === 'page') {
            $index = $i;
        }
    }
    if ($id !== '' && $index === null) {
        throw new RuntimeException('That page no longer exists.');
    }
    if ($index === null && count($pages) >= VS_SP_MAX) {
        throw new RuntimeException('You can add up to ' . VS_SP_MAX . ' pages. Remove one first.');
    }
    $image = vs_sp_save_image($imageFile);   // '' when no photo was chosen
    if ($index === null) {
        $id = bin2hex(random_bytes(6));
        $pages[] = ['id' => $id, 'label' => $label, 'url' => 'admin-page.php?p=' . $id, 'icon' => $icon, 'image' => $image,
                    'owner_only' => $ownerOnly, 'kind' => 'page', 'content' => $content];
    } else {
        $old = $pages[$index]['image'];
        if ($image !== '' && $old !== '' && strpos($old, VS_SP_IMG_DIR . '/') === 0) {
            @unlink(dirname(__DIR__) . '/' . $old);
        }
        $pages[$index] = array_merge($pages[$index], ['label' => $label, 'icon' => $icon, 'owner_only' => $ownerOnly,
                         'content' => $content, 'image' => $image !== '' ? $image : $old]);
    }
    vs_sp_save($pages);
    return $id;
}

/** Turns the text of a written page into safe HTML: blank line = new paragraph, "# " heading, "## " small heading, "- " list, **bold**, https:// links. */
function vs_sp_format(string $text): string
{
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $inline = static function (string $line) use ($h): string {
        $s = $h($line);
        $s = preg_replace('~\*\*(.+?)\*\*~u', '<strong>$1</strong>', $s);
        return preg_replace_callback('~https://[^\s<]+~i', static fn($m) => '<a href="' . $m[0] . '" target="_blank" rel="noopener noreferrer">' . $m[0] . '</a>', $s);
    };
    $html = '';
    foreach (preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", trim($text))) as $block) {
        $lines = explode("\n", trim($block));
        $first = $lines[0] ?? '';
        if (count($lines) === 1 && strpos($first, '## ') === 0) {
            $html .= '<h3 class="h5 mt-4">' . $inline(substr($first, 3)) . '</h3>';
        } elseif (count($lines) === 1 && strpos($first, '# ') === 0) {
            $html .= '<h2 class="h4 mt-4">' . $inline(substr($first, 2)) . '</h2>';
        } elseif (count(array_filter($lines, static fn($l) => strpos($l, '- ') !== 0)) === 0) {
            $html .= '<ul>' . implode('', array_map(static fn($l) => '<li>' . $inline(substr($l, 2)) . '</li>', $lines)) . '</ul>';
        } else {
            $html .= '<p>' . implode('<br>', array_map($inline, $lines)) . '</p>';
        }
    }
    return $html;
}

function vs_sp_delete(string $id): void
{
    $all = vs_sp_load();
    $pages = array_values(array_filter($all, static fn($p) => $p['id'] !== $id));
    vs_sp_save($pages);
    foreach ($all as $p) {   // remove the uploaded photo of the deleted button
        if ($p['id'] === $id && $p['image'] !== '' && strpos($p['image'], VS_SP_IMG_DIR . '/') === 0) {
            @unlink(dirname(__DIR__) . '/' . $p['image']);
        }
    }
}

/** Owner action: take the picture off a button (it goes back to the default link icon). Deletes an uploaded photo. */
function vs_sp_clear_image(string $id): void
{
    $pages = vs_sp_load();
    $found = false;
    foreach ($pages as &$p) {
        if ($p['id'] === $id) {
            $found = true;
            if ($p['image'] !== '' && strpos($p['image'], VS_SP_IMG_DIR . '/') === 0) {
                @unlink(dirname(__DIR__) . '/' . $p['image']);
            }
            $p['image'] = '';
            $p['icon'] = 'link-45deg';
        }
    }
    unset($p);
    if (!$found) {
        throw new RuntimeException('That sidebar button no longer exists.');
    }
    vs_sp_save($pages);
}

/** Prints the extra buttons (nothing when there are none). */
function vs_sp_render(string $current, bool $isOwner): void
{
    $show = array_values(array_filter(vs_sp_load(), static fn($p) => $isOwner || !$p['owner_only']));
    if (!$show) {
        return;
    }
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    echo '        <div class="sidebar-section-title">MY PAGES</div>' . "\n";
    foreach ($show as $p) {
        $external = (bool)preg_match('~^https?://~i', $p['url']);
        $file = basename((string)parse_url($p['url'], PHP_URL_PATH));
        $active = !$external && $file === $current;
        if ($active) {   // several written pages share admin-page.php, so the ?p= part must match too
            parse_str((string)parse_url($p['url'], PHP_URL_QUERY), $want);
            foreach ($want as $k => $v) {
                if ((string)($_GET[$k] ?? '') !== (string)$v) {
                    $active = false;
                }
            }
        }
        echo '        <a class="nav-link' . ($active ? ' active' : '') . '" href="' . $h($p['url']) . '"'
            . ($external ? ' target="_blank" rel="noopener noreferrer"' : '') . ($active ? ' aria-current="page"' : '') . '>' . "\n"
            . '          <span class="nav-icon">' . ($p['image'] !== ''
                ? '<img src="' . $h(vs_sp_image_src($p['image'])) . '" alt="" loading="lazy" style="width:22px;height:22px;object-fit:contain;border-radius:5px;display:block">'
                : '<i class="bi bi-' . $h($p['icon']) . '" aria-hidden="true"></i>') . '</span>' . "\n"
            . '          <span class="nav-text">' . $h($p['label']) . '</span>' . "\n"
            . '        </a>' . "\n";
    }
}