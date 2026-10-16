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
            'profile' => 'Your channel', 'history' => 'History', 'later' => 'Watch later', 'upload' => 'Upload video',
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

/**
 * The web address of the VibeStream folder as a path, e.g. "/VibeStream/", worked out from the server's
 * document root. Icons use it so they load from any page, including pages inside /adminpanel.
 * Returns null when it cannot be worked out (then the relative $base is used instead).
 */
function vs_ui_icons_web_base(): ?string
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $cache = null;
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $here = realpath(__DIR__);
    if ($docRoot && $here) {
        $d = rtrim(str_replace('\\', '/', $docRoot), '/');
        $h = str_replace('\\', '/', $here);
        if (strcasecmp($h, $d) === 0) {
            $cache = '/';
        } elseif (stripos($h, $d . '/') === 0) {
            $cache = str_replace('%2F', '/', rawurlencode(substr($h, strlen($d)) . '/'));
        }
    }
    return $cache;
}

/** URL of the custom icon, or null. $base is the path from the current page to the site root ('' or '../'); used only as a fallback. */
function vs_ui_icon_url(string $iconName, string $base = ''): ?string
{
    $map = vs_ui_icons_map();
    if (!isset($map[$iconName]) || !is_file(vs_ui_icons_dir() . '/' . $map[$iconName])) {
        return null;
    }
    $root = vs_ui_icons_web_base();
    return ($root !== null ? $root : $base) . 'uploads/ui-icons/' . $map[$iconName];
}

/**
 * Is a replaced icon one-colour dark ('dark'), one-colour white ('light') or multi-coloured ('color')?
 * One-colour icons are flipped to match the theme (a black icon would vanish on the dark theme).
 * The answer is saved in uploads/ui-icons/icons-meta.json (each icon file has its own unique name).
 */
function vs_ui_icon_tone(string $file): string
{
    static $meta = null;
    $metaFile = vs_ui_icons_dir() . '/icons-meta.json';
    if ($meta === null) {
        $d = is_file($metaFile) ? json_decode((string)@file_get_contents($metaFile), true) : [];
        $meta = is_array($d) ? $d : [];
        // The detection rules changed: forget answers saved by older versions so icons get checked again.
        if (($meta['__v'] ?? 0) !== 3) {
            $meta = ['__v' => 3];
        }
    }
    if (isset($meta[$file]) && in_array($meta[$file], ['mono', 'color'], true)) {
        return $meta[$file];
    }
    $tone = 'color';
    try {
        $path = vs_ui_icons_dir() . '/' . $file;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'svg') {
            $svg = (string)@file_get_contents($path);
            $lums = [];
            if (preg_match_all('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3})\b/i', $svg, $m)) {
                foreach ($m[1] as $hex) {
                    if (strlen($hex) === 3) {
                        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                    }
                    $lums[] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
                }
            }
            if (preg_match_all('/rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i', $svg, $m, PREG_SET_ORDER)) {
                foreach ($m as $c) {
                    $lums[] = [(int)$c[1], (int)$c[2], (int)$c[3]];
                }
            }
            $named = ['black' => [0, 0, 0], 'currentcolor' => [0, 0, 0], 'white' => [255, 255, 255]];
            if (preg_match_all('/\b(black|white|currentcolor)\b/i', $svg, $m)) {
                foreach ($m[1] as $nm) {
                    $lums[] = $named[strtolower($nm)];
                }
            }
            if ($lums === []) {
                $tone = 'mono';   // no colours at all = SVG default black
            } else {
                $allFlat = true;
                foreach ($lums as [$r, $g, $b]) {
                    $allFlat = $allFlat && (max($r, $g, $b) - min($r, $g, $b)) < 40;
                }
                $tone = $allFlat ? 'mono' : 'color';
            }
        } elseif (in_array($ext, ['png', 'gif', 'webp'], true) && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring((string)@file_get_contents($path));
            if ($src) {
                $img = imagecreatetruecolor(16, 16);
                imagealphablending($img, false);
                imagesavealpha($img, true);
                imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
                imagecopyresampled($img, $src, 0, 0, 0, 0, 16, 16, imagesx($src), imagesy($src));
                $n = 0;
                $lum = 0;
                $flat = 0;
                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $c = imagecolorat($img, $x, $y);
                        if ((($c >> 24) & 0x7F) > 60) {
                            continue;   // mostly see-through pixel
                        }
                        $r = ($c >> 16) & 255;
                        $g = ($c >> 8) & 255;
                        $b = $c & 255;
                        $n++;
                        $lum += 0.299 * $r + 0.587 * $g + 0.114 * $b;
                        if (max($r, $g, $b) - min($r, $g, $b) < 40) {
                            $flat++;
                        }
                    }
                }
                // Only transparent icons are flipped: a picture with a solid background (all four corners filled) is left alone.
                $corners = 0;
                foreach ([[0, 0], [15, 0], [0, 15], [15, 15]] as [$cx, $cy]) {
                    if ((((imagecolorat($img, $cx, $cy) >> 24) & 0x7F)) <= 60) {
                        $corners++;
                    }
                }
                if ($n >= 6 && $corners < 4 && $flat / $n >= 0.85) {
                    $tone = 'mono';
                }
            }
        }
    } catch (Throwable $e) {
        $tone = 'color';
    }
    $meta[$file] = $tone;
    if (vs_ui_icons_ensure_dir()) {
        @file_put_contents($metaFile, json_encode($meta), LOCK_EX);
    }
    return $tone;
}

/** Markup for a replaced icon, or '' when the owner has not replaced it (use the built-in icon then).
 *  One-colour icons (black, white or grey on a see-through background) are painted in the current text colour,
 *  so they are always readable on both the dark and the light theme. Multi-coloured icons are shown as uploaded. */
function vs_ui_icon_img(string $iconName, int $size = 24, string $base = ''): string
{
    $url = vs_ui_icon_url($iconName, $base);
    if ($url === null) {
        return '';
    }
    $map = vs_ui_icons_map();
    $tone = isset($map[$iconName]) ? vs_ui_icon_tone($map[$iconName]) : 'color';
    $s = max(8, $size);
    if ($tone === 'mono') {
        $mask = "url('" . str_replace("'", '%27', $url) . "') center/contain no-repeat";
        return '<span class="vs-ui-icon" aria-hidden="true" style="display:inline-block;vertical-align:middle;width:' . $s . 'px;height:' . $s
            . 'px;background-color:currentColor;-webkit-mask:' . htmlspecialchars($mask, ENT_QUOTES, 'UTF-8')
            . ';mask:' . htmlspecialchars($mask, ENT_QUOTES, 'UTF-8') . '"></span>';
    }
    return '<img class="vs-ui-icon" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" width="' . $s . '" height="' . $s
        . '" alt="" aria-hidden="true" style="width:' . $s . 'px;height:' . $s . 'px;object-fit:contain;display:inline-block;vertical-align:middle">';
}