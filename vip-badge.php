<?php
/**
 * VIP badge helper. Put this file in the SITE ROOT (next to premium-store.php).
 *
 *   vs_vip_is($username, $db)     true when the member has Premium (admins always do)
 *   vs_vip_badge($username, $db, $size)  white check-mark circle HTML (shown next to the name), or '' when not VIP. $size is optional (default 18).
 *   vs_vip_ring_open/close($username, $db)  gold ring + VIP pill around an avatar, or '' when not VIP
 *   vs_viewer_is_vip($db)         is the person looking at the page Premium?
 *   vs_ads_html()                 the ad box (empty for Premium members)
 *   vs_download_attr($id, $db)    Download option for the three-dots menu (Premium only)
 *
 * $db can be your mysqli $conn or your PDO $pdo.
 * The badge uses inline styles only, so it looks the same on the site and in the admin panel.
 */
if (!function_exists('vs_vip_badge')) {

    /** Reads the account role (Admin / User ...) from the `users` table. */
    function vs_vip_role($db, string $username): string
    {
        static $cache = [];
        $key = mb_strtolower(trim($username));
        if ($key === '') {
            return '';
        }
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $role = '';
        try {
            $sql = 'SELECT `role` FROM `users` WHERE LOWER(`username`) = LOWER(?) LIMIT 1';
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute([$username]);
                $role = (string)($st->fetchColumn() ?: '');
            } elseif ($db instanceof mysqli) {
                if ($st = $db->prepare($sql)) {
                    $st->bind_param('s', $username);
                    $st->execute();
                    $st->bind_result($r);
                    if ($st->fetch()) {
                        $role = (string)$r;
                    }
                    $st->close();
                }
            }
        } catch (Throwable $e) {
            $role = '';
        }
        return $cache[$key] = $role;
    }

    /**
     * Who has active Premium right now. Uses your premium-store.php (premium_members table),
     * loaded once per page, so a long list of users costs one query.
     * @return array<string,bool>|null  lower-case username => true, or null if it could not be read
     */
    function vs_vip_premium_set(): ?array
    {
        static $set = false;
        if ($set !== false) {
            return $set;
        }
        $set = null;
        $file = __DIR__ . '/premium-store.php';
        if (!is_file($file)) {
            return $set;
        }
        try {
            require_once $file;
            $pdo = vs_premium_pdo();
            if (!$pdo) {
                return $set;
            }
            $st = $pdo->query("SELECT * FROM premium_members WHERE status = 'active'");
            $found = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (vs_premium_is_active($row)) {
                    $found[mb_strtolower(trim((string)$row['username']))] = true;
                }
            }
            $set = $found;
        } catch (Throwable $e) {
            $set = null;
        }
        return $set;
    }

    /** true = has Premium, false = does not, null = could not tell. */
    function vs_vip_premium_check(string $username): ?bool
    {
        $set = vs_vip_premium_set();
        return $set === null ? null : isset($set[mb_strtolower(trim($username))]);
    }

    function vs_vip_is(string $username, $db = null): bool
    {
        static $cache = [];
        $key = mb_strtolower(trim($username));
        if ($key === '') {
            return false;
        }
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        // Admins always have Premium.
        if (strcasecmp(vs_vip_role($db, $username), 'admin') === 0) {
            return $cache[$key] = true;
        }
        return $cache[$key] = (vs_vip_premium_check($username) === true);
    }

    function vs_vip_badge(string $username, $db = null, int $size = 18): string
    {
        if (!vs_vip_is($username, $db)) {
            return '';
        }
        // White circle with a black check mark (the "verified" look). The thin grey outline keeps it
        // visible on light pages too. Inline SVG, so no CSS or image file is needed.
        return '<svg class="vs-vip" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" role="img" aria-label="VIP"'
            . ' style="display:inline-block;vertical-align:-3px;margin-left:' . max(3, (int)round($size / 3)) . 'px;flex:none;">'
            . '<title>VIP - Premium member</title>'
            . '<circle cx="12" cy="12" r="11" fill="#ffffff" stroke="rgba(128,128,128,.55)" stroke-width="1"/>'
            . '<path d="M7 12.6l3.2 3.2L17 8.9" fill="none" stroke="#111111" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';
    }

    /** Style for the gold avatar ring. Sent once per page, together with the first ring. */
    function vs_vip_ring_css(): string
    {
        static $sent = false;
        if ($sent) {
            return '';
        }
        $sent = true;
        return '<style>'
            . '.vs-vip-ring{position:relative;display:flex;flex:none;box-sizing:border-box;align-self:flex-start;aspect-ratio:1/1;'
            . 'padding:1px;border:2px solid #f5b301;border-radius:50%;}'
            . '.vs-vip-ring>*:not(.vs-vip-pill){flex:1 1 0!important;min-width:0!important;min-height:0!important;width:100%!important;height:100%!important;'
            . 'box-sizing:border-box;border-radius:50%;}'
            . '.vs-vip-pill{position:absolute;left:50%;bottom:-6px;transform:translateX(-50%);padding:1px 5px;border-radius:999px;'
            . 'background:linear-gradient(180deg,#ffd45a,#f2a900);color:#3a2500;border:1px solid rgba(255,255,255,.55);'
            . 'font:800 7px/1.2 Arial,Helvetica,sans-serif;letter-spacing:.03em;white-space:nowrap;pointer-events:none;}'
            . '</style>';
    }

    /**
     * Gold ring + "VIP" pill around an avatar, only for Premium members and admins.
     * Wrap the avatar like this (both calls must use the same name):
     *   <?= vs_vip_ring_open($name, $conn, 36) ?> ...avatar html... <?= vs_vip_ring_close($name, $conn) ?>
     * The last number is the avatar's normal size in pixels (default 40).
     */
    function vs_vip_ring_open(string $username, $db = null, int $size = 40): string
    {
        if (!vs_vip_is($username, $db)) {
            return '';
        }
        $size = max(24, min(160, $size));   // same size as the plain avatar, so nothing around it moves
        return vs_vip_ring_css() . '<span class="vs-vip-ring" style="width:' . $size . 'px;height:' . $size . 'px;">';
    }

    function vs_vip_ring_close(string $username, $db = null): string
    {
        return vs_vip_is($username, $db) ? '<span class="vs-vip-pill">VIP</span></span>' : '';
    }

    /** Style for the "auto" ring. Sent once per page, together with the first ring. */
    function vs_vip_ring_auto_css(): string
    {
        static $sent = false;
        if ($sent) {
            return '';
        }
        $sent = true;
        return '<style>'
            . '.vs-vip-ring-auto{position:relative;display:inline-flex;flex:none;align-self:center;vertical-align:middle;'
            . 'box-sizing:border-box;padding:1px;border:2px solid #f5b301;border-radius:50%;line-height:0;}'
            . '.vs-vip-ring-auto>*:not(.vs-vip-pill){border-radius:50%;}'
            . '.vs-vip-ring-auto .vs-vip-pill{position:absolute;left:50%;bottom:-6px;transform:translateX(-50%);padding:1px 5px;'
            . 'border-radius:999px;background:linear-gradient(180deg,#ffd45a,#f2a900);color:#3a2500;'
            . 'border:1px solid rgba(255,255,255,.55);font:800 7px/1.2 Arial,Helvetica,sans-serif;letter-spacing:.03em;'
            . 'white-space:nowrap;pointer-events:none;}'
            . '</style>';
    }

    /**
     * Gold ring + "VIP" pill that hugs an avatar of ANY size (it does not need the size).
     * Used in the admin panel lists:
     *   <?= vs_vip_ring_auto_open($name, $pdo) ?> ...avatar html... <?= vs_vip_ring_auto_close($name, $pdo) ?>
     */
    function vs_vip_ring_auto_open(string $username, $db = null): string
    {
        if (!vs_vip_is($username, $db)) {
            return '';
        }
        return vs_vip_ring_auto_css() . '<span class="vs-vip-ring-auto" title="VIP - Premium member">';
    }

    function vs_vip_ring_auto_close(string $username, $db = null): string
    {
        return vs_vip_is($username, $db) ? '<span class="vs-vip-pill">VIP</span></span>' : '';
    }

    /**
     * Should the CURRENT visitor see ads? Guests and free members: yes.
     * Premium members and admins: no.
     */
    function vs_show_ads(): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (isset($_SESSION['AdminSession']) || strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') === 0) {
            return false;
        }
        $user = trim((string)($_SESSION['username'] ?? ''));
        if ($user === '') {
            return true;
        }
        return vs_vip_premium_check($user) !== true;
    }

    /**
     * Wrap every ad with this. Premium members get an empty string, so the ad (and its
     * script) is never even sent to their browser.
     *   <?= vs_ad_slot('<script async src="..."></script><ins class="adsbygoogle"></ins>') ?>
     */
    function vs_ad_slot(string $adHtml, string $label = 'Sponsored'): string
    {
        if (!vs_show_ads()) {
            return '';
        }
        return '<div class="vs-ad" data-vs-ad style="margin:12px 0;padding:8px;border:1px solid rgba(128,128,128,.28);border-radius:12px;">'
            . '<div style="font-size:11px;opacity:.6;margin-bottom:4px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
            . $adHtml . '</div>';
    }
}

// Premium perks helpers. Guarded on their own, so they always load, even next to an older copy of the code above.
if (!function_exists('vs_viewer_is_vip')) {

    /** Is the person looking at the page Premium (or admin) right now? */
    function vs_viewer_is_vip($db = null): bool
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
        if (isset($_SESSION['AdminSession']) || strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') === 0) {
            return true;
        }
        $user = trim((string)($_SESSION['username'] ?? ''));
        if ($user === '') {
            return false;
        }
        return vs_vip_is($user, $db);
    }

    /** Where the "Get Premium" button goes. Uses premium.php if you have it. */
    function vs_premium_url(): string
    {
        return is_file(__DIR__ . '/premium.php') ? 'premium.php' : 'index.php';
    }

    /** The ad box from ads-config.php, or '' for Premium members / when no ad code is set. */
    function vs_ads_html(): string
    {
        static $code = null;
        if ($code === null) {
            $file = __DIR__ . '/ads-config.php';
            $code = is_file($file) ? trim((string)(include $file)) : '';
        }
        return $code === '' ? '' : vs_ad_slot($code);
    }

    /**
     * Download link for the three-dots menu: Premium members get the real download link,
     * everyone else gets nothing (so the menu has no Download option for them).
     */
    function vs_download_attr(string $videoId, $db = null): string
    {
        if ($videoId === '' || !vs_viewer_is_vip($db)) {
            return '';
        }
        return ' data-vm-download="download.php?id=' . htmlspecialchars(rawurlencode($videoId), ENT_QUOTES, 'UTF-8') . '"';
    }
}