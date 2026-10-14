<?php
/*
 * VibeStream - sidebar icon store.
 * Put this file in the SAME folder as site-branding.php (the main VibeStream folder).
 * It REPLACES the old sidebar-icons-store.php.
 *
 * Lets the site owner replace the icon of any sidebar item or profile-dropdown item
 * (admin panel and the website) from adminpanel/icons.php. A new icon can be uploaded as
 * a PNG file OR imported from a direct PNG URL. Settings are kept in
 * uploads/site-branding/sidebar-icons.json - nothing else is touched.
 */

if (!defined('VS_SI_DIR')) {
    define('VS_SI_DIR', 'uploads/site-branding');
    define('VS_SI_FILE', 'uploads/site-branding/sidebar-icons.json');
    define('VS_SI_MAX_BYTES', 1024 * 1024);
}

/** Every item that can get a custom icon: scope => key => [label, preview icon class]. */
function vs_si_items(): array
{
    return [
        'admin' => [
            'dashboard'   => ['Dashboard', 'bi bi-speedometer2'],
            'videos'      => ['Videos', 'bi bi-collection-play'],
            'shorts'      => ['Shorts', 'bi bi-camera-reels'],
            'addvideo'    => ['Add Video', 'bi bi-plus-square'],
            'categories'  => ['Categories', 'bi bi-tags'],
            'reported'    => ['Reported content', 'bi bi-flag'],
            'users'       => ['Users', 'bi bi-people'],
            'admins'      => ['Admins', 'bi bi-shield-check'],
            'userprofile' => ['Users profile', 'bi bi-person-lines-fill'],
            'messages'    => ['Messages', 'bi bi-chat-dots'],
            'premium'     => ['Premium', 'bi bi-gem'],
            'profile'     => ['Profile', 'bi bi-person-badge'],
            'settings'    => ['App settings', 'bi bi-sliders'],
            'icons'       => ['Icons', 'bi bi-image'],
            'activity'    => ['Admin activity', 'bi bi-activity'],
            'mainsite'    => ['Main Site', 'bi bi-house'],
            // Profile dropdown (top-right menu of the admin panel)
            'menu_profile'  => ['Dropdown: Profile', 'bi bi-person-badge'],
            'menu_settings' => ['Dropdown: App settings', 'bi bi-sliders'],
            'menu_mainsite' => ['Dropdown: Go to Main Site', 'bi bi-house'],
            'menu_logout'   => ['Dropdown: Log out', 'bi bi-box-arrow-right'],
        ],
        'site' => [
            'home'          => ['Home', 'bi bi-house-door'],
            'shorts'        => ['Shorts', 'bi bi-lightning-charge'],
            'watch'         => ['Watch', 'bi bi-play-btn'],
            'profile'       => ['Your Channel', 'bi bi-person-square'],
            'history'       => ['History', 'bi bi-clock-history'],
            'subscriptions' => ['All subscriptions', 'bi bi-collection-play'],
            'upload'        => ['Upload Video', 'bi bi-upload'],
            'premium'       => ['Premium', 'bi bi-gem'],
            'music'         => ['Music', 'bi bi-music-note-beamed'],
            'adminpanel'    => ['Admin Panel', 'bi bi-shield-check'],
            'adminpremium'  => ['Premium members', 'bi bi-stars'],
            'adminreports'  => ['Reported content', 'bi bi-flag'],
            'adminvidcats'  => ['Video categories', 'bi bi-tags'],
            // Profile dropdown (top-right menu of the website)
            'menu_profile'       => ['Dropdown: Profile', 'bi bi-person'],
            'menu_subscriptions' => ['Dropdown: Subscriptions', 'bi bi-collection-play'],
            'menu_notifications' => ['Dropdown: Notifications', 'bi bi-bell'],
            'menu_adminpanel'    => ['Dropdown: Admin panel', 'bi bi-shield-check'],
            'menu_logout'        => ['Dropdown: Log out', 'bi bi-box-arrow-right'],
        ],
    ];
}

/** Loads the saved icons: ['admin' => [key => ['file' => ..., 'mode' => 'mask'|'color']], 'site' => [...]]. */
function vs_si_load(): array
{
    if (isset($GLOBALS['vs_si_cache']) && is_array($GLOBALS['vs_si_cache'])) {
        return $GLOBALS['vs_si_cache'];
    }
    $clean = ['admin' => [], 'site' => []];
    $path = __DIR__ . '/' . VS_SI_FILE;
    $raw = is_file($path) ? @file_get_contents($path) : false;
    $data = $raw !== false ? json_decode($raw, true) : null;
    $items = vs_si_items();
    if (is_array($data)) {
        foreach ($clean as $scope => $unused) {
            foreach ((array)($data[$scope] ?? []) as $key => $entry) {
                $file = is_array($entry) ? (string)($entry['file'] ?? '') : '';
                $mode = is_array($entry) && ($entry['mode'] ?? '') === 'color' ? 'color' : 'mask';
                if (isset($items[$scope][$key])
                    && preg_match('~^uploads/site-branding/si-[a-f0-9]{32}\.png$~', $file)
                    && is_file(__DIR__ . '/' . $file)) {
                    $clean[$scope][$key] = ['file' => $file, 'mode' => $mode];
                }
            }
        }
    }
    return $GLOBALS['vs_si_cache'] = $clean;
}

function vs_si_save(array $data): void
{
    $dir = __DIR__ . '/' . VS_SI_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the icon upload directory.');
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents(__DIR__ . '/' . VS_SI_FILE, $json, LOCK_EX) === false) {
        throw new RuntimeException('The sidebar icon settings could not be saved.');
    }
    unset($GLOBALS['vs_si_cache']);
}

/**
 * HTML for the custom icon of one item, or $fallback when none is set.
 * 'mask' mode = the PNG shape is painted in the current text colour (best for white/black line icons;
 *               a red "Log out" item keeps its red).
 * 'color' mode = the PNG is shown exactly as uploaded.
 */
function vs_si_html(string $scope, string $key, string $fallback = '', int $size = 0): string
{
    $entry = vs_si_load()[$scope][$key] ?? null;
    if (!$entry) {
        return $fallback;
    }
    if ($size <= 0) {
        $size = $scope === 'admin' ? 20 : 24;
    }
    $prefix = $scope === 'admin' ? '../' : (string)($GLOBALS['vs_sidebar_base'] ?? '');
    $url = $prefix . $entry['file'] . '?v=' . (int)@filemtime(__DIR__ . '/' . $entry['file']);
    $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    if ($entry['mode'] === 'color') {
        return '<img src="' . $safe . '" alt="" width="' . $size . '" height="' . $size . '" style="display:block;object-fit:contain;">';
    }
    $mask = "url('" . $safe . "') center/contain no-repeat";
    return '<span aria-hidden="true" style="display:block;width:' . $size . 'px;height:' . $size . 'px;background-color:currentColor;-webkit-mask:' . $mask . ';mask:' . $mask . ';"></span>';
}

/**
 * Use around an existing icon:  vs_si_open('admin','menu_profile'); ...original icon...  vs_si_close();
 * Optional $size (px) overrides the default icon size (20 admin / 24 website).
 */
function vs_si_open(string $scope, string $key, int $size = 0): void
{
    $GLOBALS['vs_si_stack'][] = vs_si_html($scope, $key, '', $size);
    ob_start();
}

function vs_si_close(): void
{
    $custom = array_pop($GLOBALS['vs_si_stack']);
    $original = (string)ob_get_clean();
    echo ($custom !== null && $custom !== '') ? $custom : $original;
}

/** Deletes an old uploaded sidebar icon, only if it is one of our generated files. */
function vs_si_delete_file(string $relative): void
{
    if (preg_match('~^uploads/site-branding/si-[a-f0-9]{32}\.png$~', $relative)) {
        $path = __DIR__ . '/' . $relative;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Safely downloads a PNG from a public URL into a temp file inside the branding folder and returns its path.
 * - only http/https on ports 80/443, no user:pass in the link
 * - the host must resolve to a public IP (private/internal addresses are refused)
 * - redirects are NOT followed; the download is cut off at $maxBytes
 * The caller must validate the file and move or delete it.
 */
if (!function_exists('vs_fetch_remote_png')) {
    function vs_fetch_remote_png(string $url, int $maxBytes): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new RuntimeException('Paste a valid image URL.');
        }
        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Paste a direct http(s) link to a PNG image.');
        }
        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($port, [80, 443], true)) {
            throw new RuntimeException('Only standard web ports (80 / 443) are allowed in the link.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Importing from a URL needs the PHP cURL extension on this server. Upload the PNG file instead.');
        }

        $bare = trim($host, '[]');
        $isIpLiteral = filter_var($bare, FILTER_VALIDATE_IP) !== false;
        if ($isIpLiteral) {
            $ips = [$bare];
        } else {
            $ips = @gethostbynamel($bare);
            if (!$ips) {
                throw new RuntimeException('That address could not be found. Check the link.');
            }
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('That address is not allowed. Use a link to a public image.');
            }
        }

        $dir = __DIR__ . '/' . VS_SI_DIR;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create the icon upload directory.');
        }
        $tmp = tempnam($dir, 'vsf');
        $fh = $tmp !== false ? fopen($tmp, 'wb') : false;
        if ($tmp === false || $fh === false) {
            if ($tmp !== false) {
                @unlink($tmp);
            }
            throw new RuntimeException('Could not create a temporary file for the download.');
        }

        $bytes = 0;
        $tooBig = false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT      => 'VibeStream-IconFetch/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: image/png,image/*;q=0.8'],
            CURLOPT_WRITEFUNCTION  => function ($c, $chunk) use ($fh, &$bytes, &$tooBig, $maxBytes) {
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    $tooBig = true;
                    return 0; // abort the transfer
                }
                return fwrite($fh, $chunk);
            },
        ]);
        if (!$isIpLiteral) {
            // Connect to the exact IP we just checked (prevents DNS tricks between check and download).
            curl_setopt($ch, CURLOPT_RESOLVE, [$host . ':' . $port . ':' . $ips[0]]);
        }
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        fclose($fh);

        $fail = null;
        if ($tooBig) {
            $fail = 'The image is too large. It must be ' . max(1, (int)round($maxBytes / 1048576)) . ' MB or smaller.';
        } elseif ($ok === false) {
            $fail = 'The image could not be downloaded. Check the link and try again.';
        } elseif ($code >= 300 && $code < 400) {
            $fail = 'That link redirects somewhere else. Open it in your browser and paste the final direct link to the PNG.';
        } elseif ($code !== 200) {
            $fail = 'The other server answered with HTTP ' . $code . ' instead of the image.';
        }
        if ($fail !== null) {
            @unlink($tmp);
            throw new RuntimeException($fail);
        }
        return $tmp;
    }
}

/** Throws unless the file at $path is a valid, roughly square PNG for a sidebar icon. */
function vs_si_validate_png(string $path): void
{
    $info = @getimagesize($path);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if ($info === false || $mime !== 'image/png' || ($info[2] ?? null) !== IMAGETYPE_PNG) {
        throw new RuntimeException('Only valid PNG image files are accepted.');
    }
    $w = (int)$info[0];
    $h = (int)$info[1];
    if ($w < 24 || $h < 24 || $w > 1024 || $h > 1024 || abs($w - $h) > max($w, $h) * 0.2) {
        throw new RuntimeException('Use a square PNG between 24 × 24 and 1024 × 1024 pixels.');
    }
}

/**
 * Saves a new icon for one item. The icon comes from an uploaded PNG ($upload) or, when no file was
 * chosen, from a direct PNG link ($url). If both are given, the file wins. Returns a success message.
 */
function vs_si_set(string $scope, string $key, array $upload, bool $keepColors, string $url = ''): string
{
    $items = vs_si_items();
    if (!isset($items[$scope][$key])) {
        throw new RuntimeException('Unknown sidebar item.');
    }
    $url = trim($url);
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    $hasFile = $error !== UPLOAD_ERR_NO_FILE;

    $dir = __DIR__ . '/' . VS_SI_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the icon upload directory.');
    }
    $relative = VS_SI_DIR . '/si-' . bin2hex(random_bytes(16)) . '.png';
    $target = __DIR__ . '/' . $relative;

    if ($hasFile) {
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('The upload failed. Choose a PNG and try again.');
        }
        if ((int)$upload['size'] > VS_SI_MAX_BYTES) {
            throw new RuntimeException('Sidebar icons must be 1 MB or smaller.');
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            throw new RuntimeException('The uploaded file could not be validated.');
        }
        vs_si_validate_png($upload['tmp_name']);
        if (!move_uploaded_file($upload['tmp_name'], $target)) {
            throw new RuntimeException('The uploaded icon could not be saved.');
        }
    } elseif ($url !== '') {
        $tmp = vs_fetch_remote_png($url, VS_SI_MAX_BYTES);
        try {
            vs_si_validate_png($tmp);
            if (!@rename($tmp, $target)) {
                throw new RuntimeException('The icon could not be saved.');
            }
        } catch (Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }
    } else {
        throw new RuntimeException('Choose a PNG file or paste a PNG URL first.');
    }
    @chmod($target, 0644);

    $data = vs_si_load();
    $old = (string)($data[$scope][$key]['file'] ?? '');
    $data[$scope][$key] = ['file' => $relative, 'mode' => $keepColors ? 'color' : 'mask'];
    try {
        vs_si_save($data);
    } catch (Throwable $saveError) {
        vs_si_delete_file($relative);
        throw $saveError;
    }
    if ($old !== '' && $old !== $relative) {
        vs_si_delete_file($old);
    }
    return '“' . $items[$scope][$key][0] . '” icon updated.';
}

/** Switches an already-customised icon between 'mask' (text colour) and 'color' (original colours). */
function vs_si_set_mode(string $scope, string $key, bool $keepColors): void
{
    $data = vs_si_load();
    if (!isset($data[$scope][$key])) {
        return;
    }
    $data[$scope][$key]['mode'] = $keepColors ? 'color' : 'mask';
    vs_si_save($data);
}

/** Puts one item back to its default icon. Returns a success message. */
function vs_si_reset(string $scope, string $key): string
{
    $items = vs_si_items();
    if (!isset($items[$scope][$key])) {
        throw new RuntimeException('Unknown sidebar item.');
    }
    $data = vs_si_load();
    $old = (string)($data[$scope][$key]['file'] ?? '');
    unset($data[$scope][$key]);
    vs_si_save($data);
    if ($old !== '') {
        vs_si_delete_file($old);
    }
    return '“' . $items[$scope][$key][0] . '” icon reset to the default.';
}