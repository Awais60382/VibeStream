<?php
require_once __DIR__ . '/admin-bootstrap.php';

require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/admin-activity-store.php';

// Owner only: these settings change the whole website (maintenance mode, uploads, categories).
if (empty($isSiteOwner)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can change the app settings.</p>'
        . '<p><a href="index.php">Back to the admin panel</a></p></body></html>';
    exit;
}

// Download a backup of the current settings (owner only, must be requested before any output).
if (($_GET['export'] ?? '') === 'settings') {
    $exportState = vs_branding_state();
    $exportState['last_exported'] = date('Y-m-d');
    try {
        vs_branding_save_state($exportState);
    } catch (Throwable $saveError) {
        // The backup still downloads even if the "last exported" date could not be saved.
    }
    vs_admin_log($pdo ?? null, 'settings', 'App settings exported');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="vibestream-settings-' . date('Y-m-d') . '.json"');
    header('Cache-Control: no-store');
    echo json_encode($exportState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$successMsg = '';
$errorMsg = '';
$brandingState = vs_branding_state();
if (empty($_SESSION['vs_app_settings_csrf'])) {
    $_SESSION['vs_app_settings_csrf'] = bin2hex(random_bytes(32));
}

// Change the app logo right from this page (background upload, answers with JSON).
// Same rules as the Site icons page: square PNG, 128 to 2048 pixels, under 4 MB.
// It only touches the logo, so the other settings on this page are never changed or lost.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_logo') {
    $sendJson = static function (int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    $savedLogoPath = '';
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        $file = $_FILES['logo'] ?? [];
        $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Choose a logo file first.');
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('The logo must be smaller than 4 MB.');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('The upload failed. Please try again.');
        }
        if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) {
            throw new RuntimeException('The logo must be smaller than 4 MB.');
        }
        $info = @getimagesize($tmp);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
            throw new RuntimeException('The logo must be a PNG image.');
        }
        $logoW = (int)$info[0];
        $logoH = (int)$info[1];
        if ($logoW !== $logoH || $logoW < 128 || $logoW > 2048) {
            throw new RuntimeException('The logo must be square, between 128 and 2048 pixels.');
        }

        $logoDir = __DIR__ . '/../uploads/site-branding';
        if (!is_dir($logoDir) && !@mkdir($logoDir, 0755, true) && !is_dir($logoDir)) {
            throw new RuntimeException('Could not create the uploads/site-branding folder.');
        }
        $logoName = bin2hex(random_bytes(16)) . '.png';
        $logoDest = $logoDir . '/' . $logoName;
        if (!@move_uploaded_file($tmp, $logoDest)) {
            throw new RuntimeException('Could not save the logo. Check folder permissions.');
        }
        @chmod($logoDest, 0644);
        $savedLogoPath = $logoDest;

        $logoAdminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'An admin'));
        $logoState = vs_branding_state();
        $logoState['logo'] = 'uploads/site-branding/' . $logoName;
        $logoState['notification'] = ['message' => 'The site logo was updated by ' . $logoAdminName . '.', 'created_at' => date(DATE_ATOM)];
        vs_branding_save_state($logoState); // throws on failure
        $savedLogoPath = '';

        vs_admin_log($pdo ?? null, 'settings', 'App logo changed');
        $sendJson(200, [
            'ok' => true,
            'url' => vs_site_url($logoState['logo']),
            'message' => 'Logo updated.',
        ]);
    } catch (Throwable $logoError) {
        if ($savedLogoPath !== '' && is_file($savedLogoPath)) {
            @unlink($savedLogoPath);
        }
        $sendJson(400, [
            'ok' => false,
            'message' => $logoError instanceof RuntimeException ? $logoError->getMessage() : 'Something went wrong. Please try again.',
        ]);
    }
}

// Put the app logo back to the built-in one (background request, answers with JSON). Same as "Reset to default" on the Site icons page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_logo') {
    $sendResetJson = static function (int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }
        $resetAdminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'An admin'));
        $resetState = vs_branding_state();
        $resetState['logo'] = VS_DEFAULT_LOGO_PATH;
        $resetState['notification'] = ['message' => 'The site logo was reset by ' . $resetAdminName . '.', 'created_at' => date(DATE_ATOM)];
        vs_branding_save_state($resetState); // throws on failure

        vs_admin_log($pdo ?? null, 'settings', 'App logo reset to default');
        $sendResetJson(200, [
            'ok' => true,
            'url' => vs_site_url(VS_DEFAULT_LOGO_PATH),
            'message' => 'Logo reset to the default.',
        ]);
    } catch (Throwable $resetError) {
        $sendResetJson(400, [
            'ok' => false,
            'message' => $resetError instanceof RuntimeException ? $resetError->getMessage() : 'Something went wrong. Please try again.',
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app_settings') {
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The settings request expired. Refresh the page and try again.');
        }

        $appName = trim((string)($_POST['app_name'] ?? ''));
        $appDescription = trim((string)($_POST['app_description'] ?? ''));
        $defaultTheme = (string)($_POST['default_theme'] ?? '');
        if ($appName === '' || mb_strlen($appName) > 50) {
            throw new RuntimeException('Enter an app name between 1 and 50 characters.');
        }
        if ($appDescription === '' || mb_strlen($appDescription) > 180) {
            throw new RuntimeException('Enter an app description between 1 and 180 characters.');
        }
        if (!in_array($defaultTheme, ['dark', 'light'], true)) {
            throw new RuntimeException('Choose either light or dark as the default theme.');
        }

        $newUploadsEnabled = isset($_POST['uploads_enabled']);
        $newUploadsRequireApproval = isset($_POST['uploads_require_approval']);
        $newMaintenanceEnabled = isset($_POST['maintenance_enabled']);
        $newMaintenanceMessage = trim((string)($_POST['maintenance_message'] ?? ''));
        if (mb_strlen($newMaintenanceMessage) > 300) {
            throw new RuntimeException('The maintenance message can be at most 300 characters.');
        }
        $newMaxUploadMb = filter_var($_POST['max_upload_mb'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4096]]);
        if ($newMaxUploadMb === false) {
            throw new RuntimeException('Enter a maximum upload size between 1 and 4096 MB.');
        }

        // Registration
        $newRegistrationMode = (string)($_POST['registration_mode'] ?? 'open');
        if (!in_array($newRegistrationMode, ['open', 'approval', 'closed'], true)) {
            throw new RuntimeException('Choose a valid registration mode.');
        }

        // Announcement banner
        $newAnnouncementEnabled = isset($_POST['announcement_enabled']);
        $newAnnouncementMessage = trim((string)($_POST['announcement_message'] ?? ''));
        $newAnnouncementExpires = trim((string)($_POST['announcement_expires'] ?? ''));
        if (mb_strlen($newAnnouncementMessage) > 200) {
            throw new RuntimeException('The announcement can be at most 200 characters.');
        }
        if ($newAnnouncementEnabled && $newAnnouncementMessage === '') {
            throw new RuntimeException('Write the announcement text, or turn the announcement banner off.');
        }
        if ($newAnnouncementExpires !== '') {
            $expiryDate = DateTime::createFromFormat('Y-m-d', $newAnnouncementExpires);
            if (!$expiryDate || $expiryDate->format('Y-m-d') !== $newAnnouncementExpires) {
                throw new RuntimeException('Enter the announcement expiry as a valid date.');
            }
        }

        // Contact and legal links
        $newSupportEmail = trim((string)($_POST['support_email'] ?? ''));
        if ($newSupportEmail !== '' && (mb_strlen($newSupportEmail) > 120 || !filter_var($newSupportEmail, FILTER_VALIDATE_EMAIL))) {
            throw new RuntimeException('Enter a valid support email address.');
        }
        $cleanUrl = static function (string $label, string $value): string {
            $value = trim($value);
            if ($value === '') {
                return '';
            }
            if (mb_strlen($value) > 255 || !filter_var($value, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $value)) {
                throw new RuntimeException($label . ' must be a full link starting with http:// or https://.');
            }
            return $value;
        };
        $newTermsUrl = $cleanUrl('The Terms link', (string)($_POST['terms_url'] ?? ''));
        $newPrivacyUrl = $cleanUrl('The Privacy link', (string)($_POST['privacy_url'] ?? ''));

        // Comments and moderation
        $newCommentsEnabled = isset($_POST['comments_enabled']);
        $newCommentsRequireApproval = isset($_POST['comments_require_approval']);
        $newCommentsRequireLogin = isset($_POST['comments_require_login']);
        $newBannedWords = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['banned_words'] ?? '')) as $wordLine) {
            $wordLine = trim((string)preg_replace('/\s+/', ' ', $wordLine));
            if ($wordLine === '') {
                continue;
            }
            if (mb_strlen($wordLine) < 2 || mb_strlen($wordLine) > 40 || preg_match('/[\x00-\x1f\x7f]/', $wordLine)) {
                throw new RuntimeException('Each banned word must be 2 to 40 characters: "' . $wordLine . '".');
            }
            if (!in_array(mb_strtolower($wordLine), array_map('mb_strtolower', $newBannedWords), true)) {
                $newBannedWords[] = $wordLine;
            }
        }
        if (count($newBannedWords) > 200) {
            throw new RuntimeException('Use at most 200 banned words.');
        }

        // Feed
        $newFeedItems = filter_var($_POST['feed_items_per_page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 6, 'max_range' => 100]]);
        if ($newFeedItems === false) {
            throw new RuntimeException('Enter a number of videos per page between 6 and 100.');
        }
        $newFeedSort = (string)($_POST['feed_default_sort'] ?? 'newest');
        if (!in_array($newFeedSort, ['newest', 'trending'], true)) {
            throw new RuntimeException('Choose either newest or trending as the default feed order.');
        }

        // Brain meter (the checks and error messages live in site-branding.php)
        $newBrainMeter = vs_brain_meter_validate([
            'enabled' => !empty($_POST['brain_enabled']),
            'dizzy_start' => $_POST['brain_dizzy_start'] ?? '',
            'dry_start' => $_POST['brain_dry_start'] ?? '',
            'ash_start' => $_POST['brain_ash_start'] ?? '',
            'grave_start' => $_POST['brain_grave_start'] ?? '',
            'reset_hour' => $_POST['brain_reset_hour'] ?? '',
            'heal_minutes' => $_POST['brain_heal_minutes'] ?? '',
            'heal_amount' => $_POST['brain_heal_amount'] ?? '',
        ]);

        // Social links and analytics
        $newSocialLinks = [];
        foreach (vs_social_networks() as $networkKey => $networkLabel) {
            $newSocialLinks[$networkKey] = $cleanUrl($networkLabel . ' link', (string)($_POST['social_' . $networkKey] ?? ''));
        }
        $newAnalyticsId = strtoupper(trim((string)($_POST['analytics_id'] ?? '')));
        if ($newAnalyticsId !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $newAnalyticsId)) {
            throw new RuntimeException('Enter a Google Analytics ID like G-XXXXXXXXXX, or leave it empty.');
        }

        // Upload rules
        $allFormats = vs_video_formats_all();
        $postedFormats = [];
        foreach ((array)($_POST['video_formats'] ?? []) as $format) {
            $format = strtolower(trim((string)$format));
            if (isset($allFormats[$format])) {
                $postedFormats[$format] = true;
            }
        }
        $newFormats = array_values(array_filter(array_keys($allFormats), static fn($f) => isset($postedFormats[$f])));
        if (!$newFormats) {
            throw new RuntimeException('Allow at least one video format.');
        }
        $newShortSeconds = filter_var($_POST['max_short_seconds'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 600]]);
        if ($newShortSeconds === false) {
            throw new RuntimeException('Enter a short video length between 10 and 600 seconds.');
        }
        $newDailyLimit = filter_var($_POST['daily_upload_limit'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
        if ($newDailyLimit === false) {
            throw new RuntimeException('Enter a daily upload limit between 0 and 1000 (0 means no limit).');
        }

        // Blocked lists
        $newBlockedDomains = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['blocked_email_domains'] ?? '')) as $domainLine) {
            $domainLine = strtolower(ltrim(trim($domainLine), '@'));
            if ($domainLine === '') {
                continue;
            }
            if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $domainLine)) {
                throw new RuntimeException('"' . $domainLine . '" is not a valid email domain (example: mailinator.com).');
            }
            if (!in_array($domainLine, $newBlockedDomains, true)) {
                $newBlockedDomains[] = $domainLine;
            }
        }
        if (count($newBlockedDomains) > 200) {
            throw new RuntimeException('Block at most 200 email domains.');
        }
        $newBlockedIps = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['blocked_ips'] ?? '')) as $ipLine) {
            $ipLine = trim($ipLine);
            if ($ipLine === '') {
                continue;
            }
            if (!vs_is_valid_ip_rule($ipLine)) {
                throw new RuntimeException('"' . $ipLine . '" is not a valid IP address or IPv4 range (example: 203.0.113.0/24).');
            }
            if (!in_array($ipLine, $newBlockedIps, true)) {
                $newBlockedIps[] = $ipLine;
            }
        }
        if (count($newBlockedIps) > 200) {
            throw new RuntimeException('Block at most 200 IP addresses or ranges.');
        }
        $yourIp = vs_client_ip();
        foreach ($newBlockedIps as $ipRule) {
            if (vs_ip_matches($yourIp, $ipRule)) {
                throw new RuntimeException('That would block your own address (' . $yourIp . '). Remove "' . $ipRule . '" from the blocked IP list.');
            }
        }

        // Admin security
        $newLoginAttempts = filter_var($_POST['login_max_attempts'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 3, 'max_range' => 20]]);
        if ($newLoginAttempts === false) {
            throw new RuntimeException('Enter the failed sign-in limit as a number between 3 and 20.');
        }
        $newLockoutMinutes = filter_var($_POST['login_lockout_minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($newLockoutMinutes === false) {
            throw new RuntimeException('Enter the lockout time as a number of minutes between 1 and 1440.');
        }
        $newSessionMinutes = filter_var($_POST['admin_session_minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 1440]]);
        if ($newSessionMinutes === false) {
            throw new RuntimeException('Enter the admin session time as a number of minutes between 5 and 1440.');
        }

        // Appearance and region
        $newBrandColor = strtolower(trim((string)($_POST['brand_color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $newBrandColor)) {
            throw new RuntimeException('Pick a valid brand colour.');
        }
        $newTimezone = (string)($_POST['site_timezone'] ?? '');
        if (!in_array($newTimezone, DateTimeZone::listIdentifiers(), true)) {
            throw new RuntimeException('Choose a valid time zone.');
        }
        $newDateFormat = (string)($_POST['date_format'] ?? '');
        if (!isset(vs_date_formats()[$newDateFormat])) {
            throw new RuntimeException('Choose a valid date format.');
        }

        // Scheduled maintenance
        $validDateTime = static function (string $value): bool {
            $parsed = DateTime::createFromFormat('Y-m-d\TH:i', $value);
            return $parsed && $parsed->format('Y-m-d\TH:i') === $value;
        };
        $newMaintenanceStart = trim((string)($_POST['maintenance_start'] ?? ''));
        $newMaintenanceEnd = trim((string)($_POST['maintenance_end'] ?? ''));
        if (($newMaintenanceStart === '') !== ($newMaintenanceEnd === '')) {
            throw new RuntimeException('Set both a start and an end time for scheduled maintenance, or leave both empty.');
        }
        if ($newMaintenanceStart !== '') {
            if (!$validDateTime($newMaintenanceStart) || !$validDateTime($newMaintenanceEnd)) {
                throw new RuntimeException('Enter the scheduled maintenance times as valid dates and times.');
            }
            if ($newMaintenanceEnd <= $newMaintenanceStart) {
                throw new RuntimeException('The scheduled maintenance must end after it starts.');
            }
        }

        // Cookie notice, mature content, copyright, SEO
        $newCookieEnabled = isset($_POST['cookie_notice_enabled']);
        $newCookieText = trim((string)($_POST['cookie_notice_text'] ?? ''));
        if (mb_strlen($newCookieText) > 200) {
            throw new RuntimeException('The cookie notice can be at most 200 characters.');
        }
        $newMatureEnabled = isset($_POST['mature_content_enabled']);
        $newMatureText = trim((string)($_POST['mature_warning_text'] ?? ''));
        if (mb_strlen($newMatureText) > 200) {
            throw new RuntimeException('The 18+ warning can be at most 200 characters.');
        }
        $newCopyrightEmail = trim((string)($_POST['copyright_email'] ?? ''));
        if ($newCopyrightEmail !== '' && (mb_strlen($newCopyrightEmail) > 120 || !filter_var($newCopyrightEmail, FILTER_VALIDATE_EMAIL))) {
            throw new RuntimeException('Enter a valid copyright contact email address.');
        }
        $newCopyrightNotice = trim((string)($_POST['copyright_notice'] ?? ''));
        if (mb_strlen($newCopyrightNotice) > 300) {
            throw new RuntimeException('The copyright notice can be at most 300 characters.');
        }
        $newSeoKeywords = trim((string)($_POST['seo_keywords'] ?? ''));
        if (mb_strlen($newSeoKeywords) > 200 || preg_match('/[\x00-\x1f\x7f]/', $newSeoKeywords)) {
            throw new RuntimeException('Keywords can be at most 200 characters on one line.');
        }
        $newAllowSearchEngines = isset($_POST['allow_search_engines']);
        $newSeoImage = $cleanUrl('The share image link', (string)($_POST['seo_share_image_url'] ?? ''));

        // Categories
        $newCategories = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['categories'] ?? '')) as $categoryLine) {
            $categoryLine = trim((string)preg_replace('/\s+/', ' ', $categoryLine));
            if ($categoryLine === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9 _-]{1,30}$/', $categoryLine)) {
                throw new RuntimeException('Categories can use letters, numbers, spaces, - and _ only (up to 30 characters): "' . $categoryLine . '".');
            }
            if (!in_array(mb_strtolower($categoryLine), array_map('mb_strtolower', $newCategories), true)) {
                $newCategories[] = $categoryLine;
            }
        }
        if (count($newCategories) > 40) {
            throw new RuntimeException('Use at most 40 categories.');
        }

        // Category colours: use the posted colour, else keep the saved one, else fall back to the palette.
        $existingColors = vs_category_colors($brandingState);
        $postedColors = (array)($_POST['cat_color'] ?? []);
        $newColors = [];
        foreach ($newCategories as $categoryName) {
            $key = mb_strtolower($categoryName);
            $posted = null;
            foreach ($postedColors as $postedName => $postedColor) {
                if (mb_strtolower(trim((string)$postedName)) === $key) {
                    $posted = strtolower(trim((string)$postedColor));
                    break;
                }
            }
            if ($posted !== null && preg_match('/^#[0-9a-f]{6}$/', $posted)) {
                $newColors[$key] = $posted;
            } elseif (isset($existingColors[$key])) {
                $newColors[$key] = $existingColors[$key];
            }
        }

        $nextState = $brandingState;
        $nextState['app_name'] = $appName;
        $nextState['app_description'] = $appDescription;
        $nextState['default_theme'] = $defaultTheme;
        $nextState['uploads_enabled'] = $newUploadsEnabled;
        $nextState['uploads_require_approval'] = $newUploadsRequireApproval;
        $nextState['max_upload_mb'] = $newMaxUploadMb;
        $nextState['maintenance_enabled'] = $newMaintenanceEnabled;
        $nextState['maintenance_message'] = $newMaintenanceMessage;
        $nextState['categories'] = $newCategories;
        $nextState['category_colors'] = $newColors;
        $nextState['registration_mode'] = $newRegistrationMode;
        $nextState['announcement_enabled'] = $newAnnouncementEnabled;
        $nextState['announcement_message'] = $newAnnouncementMessage;
        $nextState['announcement_expires'] = $newAnnouncementExpires;
        $nextState['support_email'] = $newSupportEmail;
        $nextState['terms_url'] = $newTermsUrl;
        $nextState['privacy_url'] = $newPrivacyUrl;
        $nextState['comments_enabled'] = $newCommentsEnabled;
        $nextState['comments_require_approval'] = $newCommentsRequireApproval;
        $nextState['comments_require_login'] = $newCommentsRequireLogin;
        $nextState['banned_words'] = $newBannedWords;
        $nextState['feed_items_per_page'] = (int)$newFeedItems;
        $nextState['feed_default_sort'] = $newFeedSort;
        $nextState['brain_meter'] = $newBrainMeter;
        $nextState['social_links'] = $newSocialLinks;
        $nextState['analytics_id'] = $newAnalyticsId;
        $nextState['allowed_video_formats'] = $newFormats;
        $nextState['max_short_seconds'] = (int)$newShortSeconds;
        $nextState['daily_upload_limit'] = (int)$newDailyLimit;
        $nextState['blocked_email_domains'] = $newBlockedDomains;
        $nextState['blocked_ips'] = $newBlockedIps;
        $nextState['login_max_attempts'] = (int)$newLoginAttempts;
        $nextState['login_lockout_minutes'] = (int)$newLockoutMinutes;
        $nextState['admin_session_minutes'] = (int)$newSessionMinutes;
        $nextState['brand_color'] = $newBrandColor;
        $nextState['site_timezone'] = $newTimezone;
        $nextState['date_format'] = $newDateFormat;
        $nextState['maintenance_start'] = $newMaintenanceStart;
        $nextState['maintenance_end'] = $newMaintenanceEnd;
        $nextState['cookie_notice_enabled'] = $newCookieEnabled;
        $nextState['cookie_notice_text'] = $newCookieText;
        $nextState['mature_content_enabled'] = $newMatureEnabled;
        $nextState['mature_warning_text'] = $newMatureText;
        $nextState['copyright_email'] = $newCopyrightEmail;
        $nextState['copyright_notice'] = $newCopyrightNotice;
        $nextState['seo_keywords'] = $newSeoKeywords;
        $nextState['allow_search_engines'] = $newAllowSearchEngines;
        $nextState['seo_share_image_url'] = $newSeoImage;

        // What changed, for the activity log (compared before saving)
        $clip = static fn($v) => mb_strlen((string)$v) > 40 ? mb_substr((string)$v, 0, 39) . '…' : (string)$v;
        $onOff = static fn($b) => $b ? 'ON' : 'OFF';
        $changes = [];
        if (vs_branding_app_name($brandingState) !== $appName) {
            $changes[] = 'app name "' . $clip(vs_branding_app_name($brandingState)) . '" → "' . $clip($appName) . '"';
        }
        if (vs_branding_app_description($brandingState) !== $appDescription) {
            $changes[] = 'app description';
        }
        if (vs_branding_default_theme($brandingState) !== $defaultTheme) {
            $changes[] = 'default theme → ' . $defaultTheme;
        }
        if (vs_uploads_enabled($brandingState) !== $newUploadsEnabled) {
            $changes[] = 'uploads ' . $onOff($newUploadsEnabled);
        }
        if (vs_uploads_require_approval($brandingState) !== $newUploadsRequireApproval) {
            $changes[] = 'upload approval ' . $onOff($newUploadsRequireApproval);
        }
        if ((int)vs_max_upload_mb($brandingState) !== (int)$newMaxUploadMb) {
            $changes[] = 'max upload ' . (int)vs_max_upload_mb($brandingState) . ' → ' . (int)$newMaxUploadMb . ' MB';
        }
        if (!empty($brandingState['maintenance_enabled']) !== $newMaintenanceEnabled) {
            $changes[] = 'maintenance mode ' . $onOff($newMaintenanceEnabled);
        }
        if ((string)($brandingState['maintenance_message'] ?? '') !== $newMaintenanceMessage) {
            $changes[] = 'maintenance message "' . $clip((string)($brandingState['maintenance_message'] ?? '')) . '" → "' . $clip($newMaintenanceMessage) . '"';
        }
        if (array_values(vs_default_categories($brandingState)) !== $newCategories) {
            $changes[] = 'categories (' . count($newCategories) . ')';
        }
        if ($existingColors !== $newColors) {
            $changes[] = 'category colours';
        }
        if (vs_registration_mode($brandingState) !== $newRegistrationMode) {
            $changes[] = 'registration ' . vs_registration_mode($brandingState) . ' → ' . $newRegistrationMode;
        }
        if (!empty($brandingState['announcement_enabled']) !== $newAnnouncementEnabled) {
            $changes[] = 'announcement banner ' . $onOff($newAnnouncementEnabled);
        }
        if ((string)($brandingState['announcement_message'] ?? '') !== $newAnnouncementMessage
            || (string)($brandingState['announcement_expires'] ?? '') !== $newAnnouncementExpires) {
            $changes[] = 'announcement text/expiry';
        }
        if (vs_support_email($brandingState) !== $newSupportEmail
            || vs_terms_url($brandingState) !== $newTermsUrl
            || vs_privacy_url($brandingState) !== $newPrivacyUrl) {
            $changes[] = 'contact/legal links';
        }
        if (vs_comments_enabled($brandingState) !== $newCommentsEnabled) {
            $changes[] = 'comments ' . $onOff($newCommentsEnabled);
        }
        if (vs_comments_require_approval($brandingState) !== $newCommentsRequireApproval) {
            $changes[] = 'comment approval ' . $onOff($newCommentsRequireApproval);
        }
        if (vs_comments_require_login($brandingState) !== $newCommentsRequireLogin) {
            $changes[] = 'login to comment ' . $onOff($newCommentsRequireLogin);
        }
        if (vs_banned_words($brandingState) !== $newBannedWords) {
            $changes[] = 'banned words (' . count($newBannedWords) . ')';
        }
        if (vs_feed_items_per_page($brandingState) !== (int)$newFeedItems || vs_feed_default_sort($brandingState) !== $newFeedSort) {
            $changes[] = 'feed settings (' . (int)$newFeedItems . ' per page, ' . $newFeedSort . ')';
        }
        if (vs_brain_meter($brandingState) !== $newBrainMeter) {
            $changes[] = 'brain meter settings';
        }
        if (vs_social_links($brandingState) !== array_filter($newSocialLinks)) {
            $changes[] = 'social links';
        }
        if (vs_analytics_id($brandingState) !== $newAnalyticsId) {
            $changes[] = 'analytics ID ' . ($newAnalyticsId === '' ? 'removed' : 'changed');
        }

        if (vs_allowed_video_formats($brandingState) !== $newFormats) {
            $changes[] = 'allowed video formats (' . implode(', ', $newFormats) . ')';
        }
        if (vs_max_short_seconds($brandingState) !== (int)$newShortSeconds) {
            $changes[] = 'max short length ' . vs_max_short_seconds($brandingState) . ' → ' . (int)$newShortSeconds . ' s';
        }
        if (vs_daily_upload_limit($brandingState) !== (int)$newDailyLimit) {
            $changes[] = 'daily upload limit ' . vs_daily_upload_limit($brandingState) . ' → ' . (int)$newDailyLimit;
        }
        if (vs_blocked_email_domains($brandingState) !== $newBlockedDomains) {
            $changes[] = 'blocked email domains (' . count($newBlockedDomains) . ')';
        }
        if (vs_blocked_ips($brandingState) !== $newBlockedIps) {
            $changes[] = 'blocked IPs (' . count($newBlockedIps) . ')';
        }
        if (vs_login_max_attempts($brandingState) !== (int)$newLoginAttempts
            || vs_login_lockout_minutes($brandingState) !== (int)$newLockoutMinutes
            || vs_admin_session_minutes($brandingState) !== (int)$newSessionMinutes) {
            $changes[] = 'admin security (' . (int)$newLoginAttempts . ' attempts, ' . (int)$newLockoutMinutes . ' min lockout, ' . (int)$newSessionMinutes . ' min session)';
        }

        if (vs_brand_color($brandingState) !== $newBrandColor) {
            $changes[] = 'brand colour ' . vs_brand_color($brandingState) . ' → ' . $newBrandColor;
        }
        if (vs_site_timezone($brandingState) !== $newTimezone || vs_date_format($brandingState) !== $newDateFormat) {
            $changes[] = 'time zone/date format (' . $newTimezone . ', ' . $newDateFormat . ')';
        }
        if ((string)($brandingState['maintenance_start'] ?? '') !== $newMaintenanceStart
            || (string)($brandingState['maintenance_end'] ?? '') !== $newMaintenanceEnd) {
            $changes[] = 'scheduled maintenance ' . ($newMaintenanceStart === '' ? 'cleared' : $newMaintenanceStart . ' → ' . $newMaintenanceEnd);
        }
        if (vs_cookie_notice_enabled($brandingState) !== $newCookieEnabled) {
            $changes[] = 'cookie notice ' . $onOff($newCookieEnabled);
        }
        if ((string)($brandingState['cookie_notice_text'] ?? '') !== $newCookieText) {
            $changes[] = 'cookie notice text';
        }
        if (vs_mature_content_enabled($brandingState) !== $newMatureEnabled) {
            $changes[] = '18+ content ' . $onOff($newMatureEnabled);
        }
        if ((string)($brandingState['mature_warning_text'] ?? '') !== $newMatureText) {
            $changes[] = '18+ warning text';
        }
        if (vs_copyright_email($brandingState) !== $newCopyrightEmail || vs_copyright_notice($brandingState) !== $newCopyrightNotice) {
            $changes[] = 'copyright contact';
        }
        if (vs_search_engines_allowed($brandingState) !== $newAllowSearchEngines) {
            $changes[] = 'search engines ' . ($newAllowSearchEngines ? 'allowed' : 'blocked');
        }
        if (vs_seo_keywords($brandingState) !== $newSeoKeywords || vs_seo_share_image_url($brandingState) !== $newSeoImage) {
            $changes[] = 'SEO keywords/share image';
        }

        vs_branding_save_state($nextState);
        vs_admin_log($pdo ?? null, 'settings', 'App settings saved: ' . ($changes ? implode(', ', $changes) : 'no changes'));

        $brandingState = $nextState;
        $successMsg = 'App settings saved.';
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_settings') {
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The import request expired. Refresh the page and try again.');
        }
        $upload = $_FILES['settings_file'] ?? null;
        if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) {
            throw new RuntimeException('Choose a settings backup (.json) file to import.');
        }
        if ((int)$upload['size'] > 262144) {
            throw new RuntimeException('That file is too large to be a settings backup.');
        }
        $imported = json_decode((string)file_get_contents($upload['tmp_name']), true);
        if (!is_array($imported)) {
            throw new RuntimeException('That file is not a valid settings backup.');
        }

        [$importedState, $appliedKeys, $skippedKeys] = vs_import_settings($imported, $brandingState);
        if (!$appliedKeys) {
            throw new RuntimeException('No valid settings were found in that file.');
        }
        foreach (vs_blocked_ips($importedState) as $ipRule) {
            if (vs_ip_matches(vs_client_ip(), $ipRule)) {
                throw new RuntimeException('Importing this file would block your own address (' . vs_client_ip() . '), so nothing was changed.');
            }
        }

        vs_branding_save_state($importedState);
        vs_admin_log($pdo ?? null, 'settings', 'App settings imported: ' . count($appliedKeys) . ' applied, ' . count($skippedKeys) . ' skipped'
            . ($skippedKeys ? ' (' . implode(', ', $skippedKeys) . ')' : ''));

        $brandingState = $importedState;
        $successMsg = 'Imported ' . count($appliedKeys) . ' settings' . ($skippedKeys ? '. Skipped invalid ones: ' . implode(', ', $skippedKeys) : '') . '.';
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

$appName = vs_branding_app_name($brandingState);
$appDescription = vs_branding_app_description($brandingState);
$defaultTheme = vs_branding_default_theme($brandingState);
$uploadsEnabled = vs_uploads_enabled($brandingState);
$uploadsRequireApproval = vs_uploads_require_approval($brandingState);
$maxUploadMb = vs_max_upload_mb($brandingState);
$maintenanceEnabled = !empty($brandingState['maintenance_enabled']);
$maintenanceMessage = (string)($brandingState['maintenance_message'] ?? '');
$categoriesText = implode("\n", vs_default_categories($brandingState));
$categoriesWithColors = vs_categories_with_colors($brandingState);
$registrationMode = vs_registration_mode($brandingState);
$announcementEnabled = !empty($brandingState['announcement_enabled']);
$announcementMessage = (string)($brandingState['announcement_message'] ?? '');
$announcementExpires = (string)($brandingState['announcement_expires'] ?? '');
$supportEmail = vs_support_email($brandingState);
$termsUrl = vs_terms_url($brandingState);
$privacyUrl = vs_privacy_url($brandingState);
$commentsEnabled = vs_comments_enabled($brandingState);
$commentsRequireApproval = vs_comments_require_approval($brandingState);
$commentsRequireLogin = vs_comments_require_login($brandingState);
$bannedWordsText = implode("\n", vs_banned_words($brandingState));
$feedItemsPerPage = vs_feed_items_per_page($brandingState);
$feedDefaultSort = vs_feed_default_sort($brandingState);
$brainMeter = vs_brain_meter($brandingState);
$socialNetworks = vs_social_networks();
$socialLinksAll = vs_social_links_all($brandingState);
$analyticsId = vs_analytics_id($brandingState);
$lastExported = vs_last_exported($brandingState);
$videoFormatsAll = vs_video_formats_all();
$allowedFormats = vs_allowed_video_formats($brandingState);
$maxShortSeconds = vs_max_short_seconds($brandingState);
$dailyUploadLimit = vs_daily_upload_limit($brandingState);
$blockedDomainsText = implode("\n", vs_blocked_email_domains($brandingState));
$blockedIpsText = implode("\n", vs_blocked_ips($brandingState));
$loginMaxAttempts = vs_login_max_attempts($brandingState);
$loginLockoutMinutes = vs_login_lockout_minutes($brandingState);
$adminSessionMinutes = vs_admin_session_minutes($brandingState);
$brandColor = vs_brand_color($brandingState);
$siteTimezone = vs_site_timezone($brandingState);
$dateFormat = vs_date_format($brandingState);
$dateFormats = vs_date_formats();
$timezoneList = DateTimeZone::listIdentifiers();
$maintenanceStart = (string)($brandingState['maintenance_start'] ?? '');
$maintenanceEnd = (string)($brandingState['maintenance_end'] ?? '');
$cookieEnabled = vs_cookie_notice_enabled($brandingState);
$cookieText = (string)($brandingState['cookie_notice_text'] ?? '');
$matureEnabled = vs_mature_content_enabled($brandingState);
$matureText = (string)($brandingState['mature_warning_text'] ?? '');
$copyrightEmail = vs_copyright_email($brandingState);
$copyrightNotice = vs_copyright_notice($brandingState);
$seoKeywords = vs_seo_keywords($brandingState);
$allowSearchEngines = vs_search_engines_allowed($brandingState);
$seoImage = vs_seo_share_image_url($brandingState);
$yourIp = vs_client_ip();

// Server health (read-only)
$healthPath = is_dir(dirname(__DIR__) . '/uploads') ? dirname(__DIR__) . '/uploads' : dirname(__DIR__);
$diskFree = @disk_free_space($healthPath);
$diskTotal = @disk_total_space($healthPath);
$diskKnown = ($diskFree !== false && $diskTotal !== false && $diskTotal > 0);
$diskLow = $diskKnown && ($diskFree < 2 * 1073741824 || ($diskFree / $diskTotal) < 0.10);

// Warn when the admin limit is higher than what PHP will really accept.
$phpLimitBytes = vs_max_upload_bytes();
$configuredLimitBytes = (int)$maxUploadMb * 1024 * 1024;
$phpLimitLower = $phpLimitBytes < $configuredLimitBytes;

require_once __DIR__ . '/header.php';
?>

<style>
/* ===== VibeStream neon settings: tabs keep the page short, cards stay compact ===== */
.vsn { --p:#8b5cf6; --p2:#6d28d9; --glow:rgba(139,92,246,.30); --cardbg:rgba(28,20,60,.55); --line:rgba(139,92,246,.38); --soft:rgba(139,92,246,.14); }
html[data-theme="light"] .vsn { --cardbg:#fff; --line:rgba(109,40,217,.22); --glow:rgba(109,40,217,.12); --soft:rgba(109,40,217,.08); }

/* hero */
.vsn-hero { position:relative; overflow:hidden; display:flex; flex-wrap:wrap; align-items:center; gap:14px 22px; padding:18px 22px; margin-bottom:14px; border-radius:18px; color:#f4f0ff;
  background: radial-gradient(520px 200px at 92% -20%, rgba(168,120,255,.55), transparent 70%), radial-gradient(380px 160px at 0% 120%, rgba(90,60,220,.55), transparent 70%), linear-gradient(135deg,#1a0f45,#2a1470 55%,#140a35);
  border:1px solid rgba(160,120,255,.45); box-shadow:0 10px 34px rgba(80,40,200,.30), inset 0 0 40px rgba(139,92,246,.12); }
.vsn-hero-main { display:flex; align-items:center; gap:14px; flex:1 1 340px; min-width:0; }
.vsn-hero-icon { flex:0 0 auto; width:52px; height:52px; border-radius:16px; display:grid; place-items:center; font-size:1.7rem; color:#fff; background:linear-gradient(135deg,#a78bfa,#6d28d9); box-shadow:0 0 22px rgba(167,139,250,.65); }
.vsn-hero h1 { margin:0; font-size:1.55rem; font-weight:800; letter-spacing:-.01em; }
.vsn-hero p { margin:2px 0 0; font-size:.9rem; opacity:.8; }
.vsn-chips { display:flex; flex-wrap:wrap; gap:8px; flex:1 1 100%; order:3; }
.vsn-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:999px; font-size:.78rem; font-weight:600; background:rgba(255,255,255,.09); border:1px solid rgba(255,255,255,.16); }
.vsn-chip.is-ok { background:rgba(34,197,94,.16); border-color:rgba(34,197,94,.45); color:#86efac; }
.vsn-chip.is-warn { background:rgba(251,191,36,.16); border-color:rgba(251,191,36,.5); color:#fde68a; }
.vsn-chip.is-info { background:rgba(96,165,250,.16); border-color:rgba(96,165,250,.5); color:#bfdbfe; }
.vsn-quick { display:flex; gap:8px; flex-wrap:wrap; }
.vsn-qbtn { display:inline-flex; align-items:center; gap:7px; padding:8px 14px; border-radius:10px; font-size:.85rem; font-weight:600; color:#fff; text-decoration:none; background:rgba(139,92,246,.28); border:1px solid rgba(190,160,255,.5); transition:.15s; }
.vsn-qbtn:hover { background:rgba(139,92,246,.5); color:#fff; box-shadow:0 0 16px rgba(167,139,250,.55); }
.vsn-tagline { position:absolute; right:22px; top:12px; font-weight:800; font-style:italic; font-size:.95rem; opacity:.85; text-shadow:0 0 14px rgba(190,160,255,.8); }

/* tabs + search */
.vsn-bar { display:flex; flex-wrap:wrap; align-items:center; gap:10px 14px; margin-bottom:14px; }
.vsn-tabs { display:flex; gap:6px; overflow-x:auto; padding:4px; border-radius:14px; background:var(--soft); border:1px solid var(--line); flex:1 1 auto; scrollbar-width:none; }
.vsn-tabs::-webkit-scrollbar { display:none; }
.vsn-tab { display:inline-flex; align-items:center; gap:8px; white-space:nowrap; padding:8px 15px; border:0; border-radius:10px; background:transparent; color:inherit; font-weight:600; font-size:.9rem; opacity:.78; transition:.15s; }
.vsn-tab:hover { opacity:1; background:var(--soft); }
.vsn-tab.is-active { opacity:1; color:#fff; background:linear-gradient(135deg,#8b5cf6,#6d28d9); box-shadow:0 4px 16px var(--glow), 0 0 0 1px rgba(190,160,255,.4) inset; }
.vsn-search { display:flex; align-items:center; gap:8px; flex:0 1 260px; min-width:180px; padding:0 12px; border-radius:12px; border:1px solid var(--line); background:var(--cardbg); }
.vsn-search input { border:0; background:transparent; color:inherit; padding:9px 0; width:100%; outline:0; font-size:.9rem; }
.vsn-search:focus-within { box-shadow:0 0 0 .2rem var(--glow); border-color:var(--p); }

/* cards: balanced columns, no empty gaps */
.vs-settings-grid { display:block; column-width:330px; column-gap:16px; }
.vs-settings-grid + .vs-settings-grid { margin-top:0; }
.vs-col { display:contents; }
.vs-hide { display:none !important; }
.vs-card { break-inside:avoid; margin:0 0 16px; background:var(--cardbg); border:1px solid var(--line); border-radius:16px; padding:14px 16px; min-width:0;
  box-shadow:0 0 0 1px rgba(139,92,246,.06), 0 8px 26px rgba(40,20,120,.18); backdrop-filter:blur(6px); transition:border-color .15s, box-shadow .15s; }
.vs-card:hover { border-color:rgba(167,139,250,.65); box-shadow:0 0 22px var(--glow); }
.vs-card-head { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.vs-card-head h2 { margin:0; font-size:1rem; font-weight:700; }
.vs-card-icon { display:inline-flex; flex:0 0 auto; width:30px; height:30px; align-items:center; justify-content:center; border-radius:10px; background:var(--soft); color:#a78bfa; font-size:1.05rem; box-shadow:0 0 12px var(--soft); }
.vs-card .form-label { font-size:.84rem; margin-bottom:3px; }
.vs-card .mb-3 { margin-bottom:.6rem !important; }
.vs-card .form-control, .vs-card .form-select { padding-top:.3rem; padding-bottom:.3rem; font-size:.9rem; border-radius:10px; }
.vsn .form-control:focus, .vsn .form-select:focus { border-color:var(--p); box-shadow:0 0 0 .2rem var(--glow); }
.vsn .form-check-input:checked { background-color:var(--p); border-color:var(--p); }
.vsn .form-check-input:focus { box-shadow:0 0 0 .2rem var(--glow); border-color:var(--p); }
.vsn .btn-primary { background:linear-gradient(135deg,#8b5cf6,#6d28d9); border:0; box-shadow:0 6px 18px var(--glow); }
.vsn .btn-primary:hover { filter:brightness(1.12); }
.vsn .btn-outline-primary { color:#a78bfa; border-color:rgba(167,139,250,.6); }
.vsn .btn-outline-primary:hover { background:var(--p); border-color:var(--p); color:#fff; }
.vs-hint { font-size:.76rem; line-height:1.35; opacity:.7; margin-top:3px; }
.vs-card .vs-switch-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding-left:0; margin-bottom:8px; }
.vs-card .vs-switch-row .form-check-input { float:none; margin:0; flex:0 0 auto; width:2.4em; height:1.25em; }
.vs-card .vs-switch-row .form-check-label { flex:1; }
.vs-counter { text-align:right; font-size:.78rem; opacity:.65; margin-top:4px; }
.vs-logo-row { display:flex; align-items:center; gap:12px; margin-bottom:10px; }
.vs-logo-row img { width:56px; height:56px; border-radius:14px; object-fit:cover; border:1px solid var(--line); box-shadow:0 0 16px var(--glow); }
.vs-colour-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:10px 16px; }
.vs-health-row { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid var(--line); }
.vs-health-row:last-child { border-bottom:0; }

/* sticky save bar */
.vsn-savebar { position:sticky; bottom:12px; z-index:5; display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px; margin-top:6px; padding:10px 14px; border-radius:14px; border:1px solid rgba(167,139,250,.55);
  background:linear-gradient(135deg, rgba(40,22,100,.94), rgba(26,14,70,.94)); color:#f1ecff; box-shadow:0 10px 30px rgba(60,30,180,.35); backdrop-filter:blur(8px); }
.vsn-save-note { font-size:.85rem; opacity:.85; }
.vsn-save-actions { display:flex; gap:8px; }
.vsn-savebar .btn-outline-secondary { color:#e8e0ff; border-color:rgba(200,180,255,.45); }
@media (max-width:575.98px) { .vsn-tagline { display:none; } .vsn-search { flex-basis:100%; } .vsn-savebar { flex-direction:column; align-items:stretch; } .vsn-save-actions .btn { flex:1; } }
</style>

<main class="admin-content p-3 p-lg-4 vsn">
  <div class="vsn-hero">
    <div class="vsn-hero-main">
      <span class="vsn-hero-icon"><i class="bi bi-gear-fill" aria-hidden="true"></i></span>
      <div>
        <h1>Platform Settings</h1>
        <p>Manage your platform settings and preferences. They apply to the whole website.</p>
      </div>
    </div>
    <div class="vsn-quick">
      <a class="vsn-qbtn" href="?export=settings"><i class="bi bi-download" aria-hidden="true"></i>Export</a>
      <a class="vsn-qbtn" href="../index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>View site</a>
    </div>
    <div class="vsn-chips">
      <span class="vsn-chip <?php echo $maintenanceEnabled ? 'is-warn' : 'is-ok'; ?>"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i>Maintenance <?php echo $maintenanceEnabled ? 'on' : 'off'; ?></span>
      <span class="vsn-chip"><i class="bi bi-person-plus" aria-hidden="true"></i>Sign-ups: <?php echo $h(ucfirst((string)$registrationMode)); ?></span>
      <span class="vsn-chip"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>Uploads up to <?php echo (int)$maxUploadMb; ?> MB</span>
      <?php if ($announcementEnabled): ?><span class="vsn-chip is-info"><i class="bi bi-megaphone" aria-hidden="true"></i>Banner is live</span><?php endif; ?>
    </div>
    <div class="vsn-tagline">Build. Stream. Grow.</div>
  </div>

  <?php if ($successMsg !== ''): ?>
    <div class="alert alert-success" role="status"><?php echo $h($successMsg); ?></div>
  <?php endif; ?>
  <?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger" role="alert"><?php echo $h($errorMsg); ?></div>
  <?php endif; ?>
  <?php if ($phpLimitLower): ?>
    <div class="alert alert-warning" role="alert">
      <strong>Upload limit mismatch.</strong>
      You set <?php echo (int)$maxUploadMb; ?> MB, but PHP on this server only accepts <?php echo $h(vs_format_bytes($phpLimitBytes)); ?>,
      so that is the real limit. Raise <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini to use the full amount.
    </div>
  <?php endif; ?>

  <div class="vsn-bar">
    <div class="vsn-tabs" id="vsn-tabs" role="tablist" aria-label="Settings sections">
      <button type="button" class="vsn-tab is-active" role="tab" data-tab="general"><i class="bi bi-sliders" aria-hidden="true"></i><span>General</span></button>
      <button type="button" class="vsn-tab" role="tab" data-tab="content"><i class="bi bi-collection-play" aria-hidden="true"></i><span>Content</span></button>
      <button type="button" class="vsn-tab" role="tab" data-tab="security"><i class="bi bi-shield-lock" aria-hidden="true"></i><span>Security &amp; Privacy</span></button>
      <button type="button" class="vsn-tab" role="tab" data-tab="look"><i class="bi bi-palette" aria-hidden="true"></i><span>Look &amp; SEO</span></button>
      <button type="button" class="vsn-tab" role="tab" data-tab="system"><i class="bi bi-hdd" aria-hidden="true"></i><span>System</span></button>
    </div>
    <label class="vsn-search"><i class="bi bi-search" aria-hidden="true"></i><input type="search" id="vs-settings-search" placeholder="Search all settings..." aria-label="Search settings" autocomplete="off"></label>
  </div>
  <div id="vs-no-match" class="alert alert-secondary d-none" role="status">No settings match your search.</div>

  <form method="post" id="vs-settings-form">
    <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['vs_app_settings_csrf']); ?>">
    <input type="hidden" name="action" value="save_app_settings">

    <div class="vs-settings-grid">

      <!-- Column 1 -->
      <div class="vs-col">

        <section class="vs-card" data-tab="general">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-gear-fill" aria-hidden="true"></i></span><h2>General Settings</h2></div>

          <div class="vs-logo-row">
            <img src="<?php echo $h(vs_branding_logo_url()); ?>" alt="Current app logo" width="56" height="56">
            <div>
              <strong>App logo</strong>
              <div class="vs-hint mt-0">Site header, browser tab and installed app.</div>
              <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="vs-logo-btn"><i class="bi bi-image me-1" aria-hidden="true"></i><span>Change logo</span></button>
              <button type="button" class="btn btn-sm btn-outline-secondary mt-2" id="vs-logo-reset"<?php echo (string)($brandingState['logo'] ?? VS_DEFAULT_LOGO_PATH) === VS_DEFAULT_LOGO_PATH ? ' disabled' : ''; ?>><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i><span>Reset to default</span></button>
              <input type="file" id="vs-logo-input" accept="image/png" hidden>
              <div class="vs-hint" id="vs-logo-msg" role="status" aria-live="polite">PNG, square, 128 to 2048 pixels, under 4 MB.</div>
            </div>
          </div>

          <div class="mb-3">
            <label for="app_name" class="form-label">App Name</label>
            <input class="form-control" type="text" id="app_name" name="app_name" value="<?php echo $h($appName); ?>" maxlength="50" required>
          </div>

          <div class="mb-3">
            <label for="app_description" class="form-label">App Description</label>
            <textarea class="form-control" id="app_description" name="app_description" rows="2" maxlength="180" required><?php echo $h($appDescription); ?></textarea>
            <div class="vs-counter" data-for="app_description" data-max="180"></div>
          </div>

          <div>
            <label class="form-label d-block">Default Theme</label>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="default_theme" id="theme_dark" value="dark"<?php echo $defaultTheme === 'dark' ? ' checked' : ''; ?>>
              <label class="form-check-label" for="theme_dark">Dark</label>
            </div>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="radio" name="default_theme" id="theme_light" value="light"<?php echo $defaultTheme === 'light' ? ' checked' : ''; ?>>
              <label class="form-check-label" for="theme_light">Light</label>
            </div>
            <div class="vs-hint">For visitors who have not chosen a theme.</div>
          </div>
        </section>

        <section class="vs-card" data-tab="general">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-person-fill" aria-hidden="true"></i></span><h2>Registration Settings</h2></div>
          <label for="registration_mode" class="form-label">Registration Mode</label>
          <select class="form-select" id="registration_mode" name="registration_mode">
            <option value="open"<?php echo $registrationMode === 'open' ? ' selected' : ''; ?>>Open</option>
            <option value="approval"<?php echo $registrationMode === 'approval' ? ' selected' : ''; ?>>Approval required</option>
            <option value="closed"<?php echo $registrationMode === 'closed' ? ' selected' : ''; ?>>Closed</option>
          </select>
          <div class="vs-hint">Open: anyone can sign up. Approval: an admin approves new accounts. Closed: no sign-ups.</div>
        </section>

        <section class="vs-card" data-tab="content">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-chat-dots-fill" aria-hidden="true"></i></span><h2>Comments &amp; Moderation</h2></div>

          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="comments_enabled">Allow Comments</label>
            <input class="form-check-input" type="checkbox" role="switch" id="comments_enabled" name="comments_enabled" value="1"<?php echo $commentsEnabled ? ' checked' : ''; ?>>
          </div>
          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="comments_require_approval">Enable Comment Approval</label>
            <input class="form-check-input" type="checkbox" role="switch" id="comments_require_approval" name="comments_require_approval" value="1"<?php echo $commentsRequireApproval ? ' checked' : ''; ?>>
          </div>
          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="comments_require_login">Require Login to Comment</label>
            <input class="form-check-input" type="checkbox" role="switch" id="comments_require_login" name="comments_require_login" value="1"<?php echo $commentsRequireLogin ? ' checked' : ''; ?>>
          </div>

          <label for="banned_words" class="form-label mt-2">Banned Words</label>
          <textarea class="form-control" id="banned_words" name="banned_words" rows="3"><?php echo $h($bannedWordsText); ?></textarea>
          <div class="vs-hint">One per line, up to 200. Whole words only, upper/lower case ignored.</div>
        </section>

      </div>

      <!-- Column 2 -->
      <div class="vs-col">

        <section class="vs-card" data-tab="content">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-cloud-plus-fill" aria-hidden="true"></i></span><h2>Uploads &amp; Content</h2></div>

          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="uploads_enabled">Enable Video Uploads</label>
            <input class="form-check-input" type="checkbox" role="switch" id="uploads_enabled" name="uploads_enabled" value="1"<?php echo $uploadsEnabled ? ' checked' : ''; ?>>
          </div>
          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="uploads_require_approval">Require Approval for Uploads</label>
            <input class="form-check-input" type="checkbox" role="switch" id="uploads_require_approval" name="uploads_require_approval" value="1"<?php echo $uploadsRequireApproval ? ' checked' : ''; ?>>
          </div>

          <label for="max_upload_mb" class="form-label mt-2">Maximum Upload Size (MB)</label>
          <input class="form-control" type="number" id="max_upload_mb" name="max_upload_mb" min="1" max="4096" step="1" value="<?php echo (int)$maxUploadMb; ?>" required>
          <div class="vs-hint">
            PHP allows <strong><?php echo $h(ini_get('upload_max_filesize')); ?></strong>
            (post <?php echo $h(ini_get('post_max_size')); ?>). The smaller number is the real limit.
          </div>
        </section>

        <section class="vs-card" data-tab="general">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-megaphone-fill" aria-hidden="true"></i></span><h2>Announcement Banner</h2></div>

          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="announcement_enabled">Enable Announcement</label>
            <input class="form-check-input" type="checkbox" role="switch" id="announcement_enabled" name="announcement_enabled" value="1"<?php echo $announcementEnabled ? ' checked' : ''; ?>>
          </div>

          <label for="announcement_message" class="form-label">Message</label>
          <textarea class="form-control" id="announcement_message" name="announcement_message" rows="3" maxlength="200" placeholder="Enter announcement message..."><?php echo $h($announcementMessage); ?></textarea>
          <div class="vs-counter" data-for="announcement_message" data-max="200"></div>

          <label for="announcement_expires" class="form-label mt-2">Expiry Date</label>
          <input class="form-control" type="date" id="announcement_expires" name="announcement_expires" value="<?php echo $h($announcementExpires); ?>">
          <div class="vs-hint">Optional. The banner hides itself after this date.</div>
        </section>

              </div>

      <!-- Column 3 -->
      <div class="vs-col">

        <section class="vs-card" data-tab="general">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><h2>Maintenance Mode</h2></div>

          <div class="form-check form-switch vs-switch-row">
            <label class="form-check-label" for="maintenance_enabled">Enable Maintenance Mode</label>
            <input class="form-check-input" type="checkbox" role="switch" id="maintenance_enabled" name="maintenance_enabled" value="1"<?php echo $maintenanceEnabled ? ' checked' : ''; ?>>
          </div>

          <label for="maintenance_message" class="form-label">Maintenance Message</label>
          <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="3" maxlength="300" placeholder="We are currently performing maintenance. Please check back later."><?php echo $h($maintenanceMessage); ?></textarea>
          <div class="vs-counter" data-for="maintenance_message" data-max="300"></div>
          <div class="vs-hint">Shown instead of the site. Admins can still use everything. Empty = default message.</div>
        </section>

        <section class="vs-card" data-tab="general">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-link-45deg" aria-hidden="true"></i></span><h2>Contact &amp; Legal Links</h2></div>

          <div class="mb-3">
            <label for="support_email" class="form-label">Support Email</label>
            <input class="form-control" type="email" id="support_email" name="support_email" value="<?php echo $h($supportEmail); ?>" maxlength="120" placeholder="support@example.com">
          </div>
          <div class="mb-3">
            <label for="terms_url" class="form-label">Terms of Service URL</label>
            <input class="form-control" type="url" id="terms_url" name="terms_url" value="<?php echo $h($termsUrl); ?>" maxlength="255" placeholder="https://example.com/terms">
          </div>
          <div>
            <label for="privacy_url" class="form-label">Privacy Policy URL</label>
            <input class="form-control" type="url" id="privacy_url" name="privacy_url" value="<?php echo $h($privacyUrl); ?>" maxlength="255" placeholder="https://example.com/privacy">
            <div class="vs-hint">Shown in the website footer. Leave a field empty to hide it.</div>
          </div>
        </section>

        <section class="vs-card" data-tab="system">
          <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-download" aria-hidden="true"></i></span><h2>Export Settings <small class="fw-normal opacity-75">(Owner Only)</small></h2></div>
          <p class="mb-3 opacity-75">Download a backup of your current settings.</p>
          <a class="btn btn-outline-primary w-100" href="?export=settings"><i class="bi bi-download me-1" aria-hidden="true"></i>Export Settings</a>
          <div class="vs-hint text-center mt-2">Last exported: <?php echo $lastExported !== '' ? $h($lastExported) : 'never'; ?></div>
        </section>

      </div>
    </div>

    <!-- More settings -->
    <div class="vs-settings-grid vs-more">

      <section class="vs-card" data-tab="content">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-film" aria-hidden="true"></i></span><h2>Upload Rules</h2></div>

        <label class="form-label d-block">Allowed Video Formats</label>
        <div class="mb-3">
          <?php foreach ($videoFormatsAll as $formatKey => $formatLabel): ?>
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="video_formats[]" id="fmt_<?php echo $h($formatKey); ?>" value="<?php echo $h($formatKey); ?>"<?php echo in_array($formatKey, $allowedFormats, true) ? ' checked' : ''; ?>>
              <label class="form-check-label" for="fmt_<?php echo $h($formatKey); ?>"><?php echo $h($formatLabel); ?></label>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label for="max_short_seconds" class="form-label">Longest Short (sec)</label>
            <input class="form-control" type="number" id="max_short_seconds" name="max_short_seconds" min="10" max="600" step="1" value="<?php echo (int)$maxShortSeconds; ?>" required>
          </div>
          <div class="col-6">
            <label for="daily_upload_limit" class="form-label">Uploads / User / Day</label>
            <input class="form-control" type="number" id="daily_upload_limit" name="daily_upload_limit" min="0" max="1000" step="1" value="<?php echo (int)$dailyUploadLimit; ?>" required>
          </div>
        </div>
        <div class="vs-hint">Short length: 10 to 600. Daily limit: 0 means no limit.</div>
      </section>

      <section class="vs-card" data-tab="content">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-slash-circle-fill" aria-hidden="true"></i></span><h2>Blocked List</h2></div>

        <div class="mb-3">
          <label for="blocked_email_domains" class="form-label">Blocked Email Domains</label>
          <textarea class="form-control" id="blocked_email_domains" name="blocked_email_domains" rows="3" placeholder="mailinator.com"><?php echo $h($blockedDomainsText); ?></textarea>
          <div class="vs-hint">One per line. Sign-ups from these domains can be refused.</div>
        </div>
        <div>
          <label for="blocked_ips" class="form-label">Blocked IP Addresses</label>
          <textarea class="form-control" id="blocked_ips" name="blocked_ips" rows="3" placeholder="203.0.113.7&#10;198.51.100.0/24"><?php echo $h($blockedIpsText); ?></textarea>
          <div class="vs-hint">One per line: addresses or IPv4 ranges (198.51.100.0/24). Yours is <strong><?php echo $h($yourIp); ?></strong>; saving is refused if it would block you.</div>
        </div>
      </section>

      <section class="vs-card" data-tab="security">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-shield-lock-fill" aria-hidden="true"></i></span><h2>Admin Security</h2></div>

        <div class="row g-2">
          <div class="col-6">
            <label for="login_max_attempts" class="form-label">Failed sign-ins</label>
            <input class="form-control" type="number" id="login_max_attempts" name="login_max_attempts" min="3" max="20" step="1" value="<?php echo (int)$loginMaxAttempts; ?>" required>
          </div>
          <div class="col-6">
            <label for="login_lockout_minutes" class="form-label">Lockout (min)</label>
            <input class="form-control" type="number" id="login_lockout_minutes" name="login_lockout_minutes" min="1" max="1440" step="1" value="<?php echo (int)$loginLockoutMinutes; ?>" required>
          </div>
          <div class="col-6">
            <label for="admin_session_minutes" class="form-label">Session (min)</label>
            <input class="form-control" type="number" id="admin_session_minutes" name="admin_session_minutes" min="5" max="1440" step="1" value="<?php echo (int)$adminSessionMinutes; ?>" required>
          </div>
        </div>
        <div class="vs-hint">Failed sign-ins before lockout: 3 to 20. Admins are signed out after the session time of inactivity.</div>
      </section>

      <section class="vs-card" data-tab="look">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-palette-fill" aria-hidden="true"></i></span><h2>Appearance &amp; Region</h2></div>

        <div class="mb-3">
          <label for="brand_color" class="form-label">Brand Colour</label>
          <input class="form-control form-control-color" type="color" id="brand_color" name="brand_color" value="<?php echo $h($brandColor); ?>">
          <div class="vs-hint">Used for buttons and switches (needs <code>vs_render_brand_style()</code>).</div>
        </div>
        <div class="mb-3">
          <label for="site_timezone" class="form-label">Time Zone</label>
          <select class="form-select" id="site_timezone" name="site_timezone">
            <?php foreach ($timezoneList as $zoneName): ?>
              <option value="<?php echo $h($zoneName); ?>"<?php echo $zoneName === $siteTimezone ? ' selected' : ''; ?>><?php echo $h($zoneName); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="date_format" class="form-label">Date Format</label>
          <select class="form-select" id="date_format" name="date_format">
            <?php foreach ($dateFormats as $formatCode => $formatExample): ?>
              <option value="<?php echo $h($formatCode); ?>"<?php echo $formatCode === $dateFormat ? ' selected' : ''; ?>><?php echo $h($formatExample); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </section>

      <section class="vs-card" data-tab="system">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-calendar-event-fill" aria-hidden="true"></i></span><h2>Scheduled Maintenance</h2></div>

        <div class="mb-3">
          <label for="maintenance_start" class="form-label">Starts</label>
          <input class="form-control" type="datetime-local" id="maintenance_start" name="maintenance_start" value="<?php echo $h($maintenanceStart); ?>">
        </div>
        <div>
          <label for="maintenance_end" class="form-label">Ends</label>
          <input class="form-control" type="datetime-local" id="maintenance_end" name="maintenance_end" value="<?php echo $h($maintenanceEnd); ?>">
          <div class="vs-hint">Site time zone (<?php echo $h($siteTimezone); ?>). Turns on and off by itself. Empty = no schedule.</div>
        </div>
      </section>

      <section class="vs-card" data-tab="security">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-cookie" aria-hidden="true"></i></span><h2>Cookie Notice</h2></div>

        <div class="form-check form-switch vs-switch-row">
          <label class="form-check-label" for="cookie_notice_enabled">Show Cookie Notice</label>
          <input class="form-check-input" type="checkbox" role="switch" id="cookie_notice_enabled" name="cookie_notice_enabled" value="1"<?php echo $cookieEnabled ? ' checked' : ''; ?>>
        </div>
        <label for="cookie_notice_text" class="form-label">Notice Text</label>
        <textarea class="form-control" id="cookie_notice_text" name="cookie_notice_text" rows="3" maxlength="200" placeholder="We use cookies to make this site work and to improve your experience."><?php echo $h($cookieText); ?></textarea>
        <div class="vs-counter" data-for="cookie_notice_text" data-max="200"></div>
        <div class="vs-hint">Accepted once per device. Links to your Privacy Policy if set.</div>
      </section>

      <section class="vs-card" data-tab="content">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i></span><h2>Mature Content (18+)</h2></div>

        <div class="form-check form-switch vs-switch-row">
          <label class="form-check-label" for="mature_content_enabled">Allow 18+ Videos</label>
          <input class="form-check-input" type="checkbox" role="switch" id="mature_content_enabled" name="mature_content_enabled" value="1"<?php echo $matureEnabled ? ' checked' : ''; ?>>
        </div>
        <label for="mature_warning_text" class="form-label">Warning Text</label>
        <textarea class="form-control" id="mature_warning_text" name="mature_warning_text" rows="3" maxlength="200" placeholder="This video may contain content for adults only (18+). Do you want to continue?"><?php echo $h($matureText); ?></textarea>
        <div class="vs-counter" data-for="mature_warning_text" data-max="200"></div>
        <div class="vs-hint">Shown on a warning page before an 18+ video plays.</div>
      </section>

      <section class="vs-card" data-tab="security">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-c-circle-fill" aria-hidden="true"></i></span><h2>Copyright Contact</h2></div>

        <div class="mb-3">
          <label for="copyright_email" class="form-label">Takedown Email</label>
          <input class="form-control" type="email" id="copyright_email" name="copyright_email" value="<?php echo $h($copyrightEmail); ?>" maxlength="120" placeholder="copyright@example.com">
        </div>
        <div>
          <label for="copyright_notice" class="form-label">Notice</label>
          <textarea class="form-control" id="copyright_notice" name="copyright_notice" rows="3" maxlength="300" placeholder="To report content that infringes your copyright, email us with the video link and proof of ownership."><?php echo $h($copyrightNotice); ?></textarea>
          <div class="vs-counter" data-for="copyright_notice" data-max="300"></div>
          <div class="vs-hint">Shown in the footer for takedown requests.</div>
        </div>
      </section>

      <section class="vs-card" data-tab="look">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-search" aria-hidden="true"></i></span><h2>SEO &amp; Sharing</h2></div>

        <div class="form-check form-switch vs-switch-row">
          <label class="form-check-label" for="allow_search_engines">Allow Search Engines</label>
          <input class="form-check-input" type="checkbox" role="switch" id="allow_search_engines" name="allow_search_engines" value="1"<?php echo $allowSearchEngines ? ' checked' : ''; ?>>
        </div>
        <div class="mb-3">
          <label for="seo_keywords" class="form-label">Site Keywords</label>
          <input class="form-control" type="text" id="seo_keywords" name="seo_keywords" value="<?php echo $h($seoKeywords); ?>" maxlength="200" placeholder="videos, shorts, music, gaming">
        </div>
        <div>
          <label for="seo_share_image_url" class="form-label">Default Share Image URL</label>
          <input class="form-control" type="url" id="seo_share_image_url" name="seo_share_image_url" value="<?php echo $h($seoImage); ?>" maxlength="255" placeholder="https://example.com/share.png">
          <div class="vs-hint">Shown when links are shared. Turn off "Allow Search Engines" to ask Google not to list the site.</div>
        </div>
      </section>

      <section class="vs-card" data-tab="content">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i></span><h2>Feed</h2></div>

        <div class="row g-2">
          <div class="col-6">
            <label for="feed_items_per_page" class="form-label">Videos per Page</label>
            <input class="form-control" type="number" id="feed_items_per_page" name="feed_items_per_page" min="6" max="100" step="1" value="<?php echo (int)$feedItemsPerPage; ?>" required>
          </div>
          <div class="col-6">
            <label for="feed_default_sort" class="form-label">Home Feed Order</label>
            <select class="form-select" id="feed_default_sort" name="feed_default_sort">
              <option value="newest"<?php echo $feedDefaultSort === 'newest' ? ' selected' : ''; ?>>Newest first</option>
              <option value="trending"<?php echo $feedDefaultSort === 'trending' ? ' selected' : ''; ?>>Trending</option>
            </select>
          </div>
        </div>
        <div class="vs-hint">Videos per page: 6 to 100.</div>
      </section>

      <section class="vs-card" data-tab="content">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-activity" aria-hidden="true"></i></span><h2>Brain Meter</h2></div>

        <div class="form-check form-switch vs-switch-row">
          <label class="form-check-label" for="brain_enabled">Show the Brain Meter</label>
          <input class="form-check-input" type="checkbox" role="switch" id="brain_enabled" name="brain_enabled" value="1"<?php echo !empty($brainMeter['enabled']) ? ' checked' : ''; ?>>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label for="brain_dizzy_start" class="form-label">Dizzy at</label>
            <input class="form-control form-control-sm" type="number" id="brain_dizzy_start" name="brain_dizzy_start" min="1" max="100000" step="1" value="<?php echo (int)$brainMeter['dizzy_start']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_dry_start" class="form-label">Drying at</label>
            <input class="form-control form-control-sm" type="number" id="brain_dry_start" name="brain_dry_start" min="2" max="100000" step="1" value="<?php echo (int)$brainMeter['dry_start']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_ash_start" class="form-label">Dust at</label>
            <input class="form-control form-control-sm" type="number" id="brain_ash_start" name="brain_ash_start" min="3" max="10000000" step="1" value="<?php echo (int)$brainMeter['ash_start']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_grave_start" class="form-label">Grave at</label>
            <input class="form-control form-control-sm" type="number" id="brain_grave_start" name="brain_grave_start" min="4" max="100000000" step="1" value="<?php echo (int)$brainMeter['grave_start']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_reset_hour" class="form-label">New day at (hour)</label>
            <input class="form-control form-control-sm" type="number" id="brain_reset_hour" name="brain_reset_hour" min="0" max="23" step="1" value="<?php echo (int)$brainMeter['reset_hour']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_heal_minutes" class="form-label">Break (minutes)</label>
            <input class="form-control form-control-sm" type="number" id="brain_heal_minutes" name="brain_heal_minutes" min="0" max="1440" step="1" value="<?php echo (int)$brainMeter['heal_minutes']; ?>" required>
          </div>
          <div class="col-6">
            <label for="brain_heal_amount" class="form-label">Healed per break</label>
            <input class="form-control form-control-sm" type="number" id="brain_heal_amount" name="brain_heal_amount" min="0" max="1000" step="1" value="<?php echo (int)$brainMeter['heal_amount']; ?>" required>
          </div>
        </div>
        <div class="vs-hint">Numbers are videos watched in a day. 4% thresholds: 2, 4, 40, 2000. Defaults also use hour 7, 30 min, 20. Set the break or heal amount to 0 to turn healing off.</div>
      </section>

      <section class="vs-card" data-tab="look">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-share-fill" aria-hidden="true"></i></span><h2>Social Links</h2></div>

        <?php foreach ($socialNetworks as $networkKey => $networkLabel): ?>
          <div class="mb-3">
            <label for="social_<?php echo $h($networkKey); ?>" class="form-label"><?php echo $h($networkLabel); ?></label>
            <input class="form-control" type="url" id="social_<?php echo $h($networkKey); ?>" name="social_<?php echo $h($networkKey); ?>" value="<?php echo $h($socialLinksAll[$networkKey] ?? ''); ?>" maxlength="255" placeholder="https://">
          </div>
        <?php endforeach; ?>
        <div class="vs-hint">Shown in the footer. Leave empty to hide a network.</div>
      </section>

      <section class="vs-card" data-tab="look">
        <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-bar-chart-line-fill" aria-hidden="true"></i></span><h2>Analytics</h2></div>

        <label for="analytics_id" class="form-label">Google Analytics ID</label>
        <input class="form-control" type="text" id="analytics_id" name="analytics_id" value="<?php echo $h($analyticsId); ?>" maxlength="20" placeholder="G-XXXXXXXXXX">
        <div class="vs-hint">Optional. Admin visits are not counted. Empty = off.</div>
      </section>

    </div>

    <div class="vsn-savebar">
      <span class="vsn-save-note"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>All changes apply to the entire website.</span>
      <div class="vsn-save-actions">
        <button class="btn btn-outline-secondary" type="reset"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reset</button>
        <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1" aria-hidden="true"></i>Save Settings</button>
      </div>
    </div>
  </form>

  <div class="vs-settings-grid mt-3" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
  <section class="vs-card" data-tab="system">
    <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-upload" aria-hidden="true"></i></span><h2>Import Settings <small class="fw-normal opacity-75">(Owner Only)</small></h2></div>
    <p class="opacity-75 small mb-2">Restore a backup made with "Export Settings". Each setting is checked, invalid ones are skipped, and logos are never imported.</p>
    <form method="post" enctype="multipart/form-data" class="d-flex flex-wrap gap-2 align-items-center">
      <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['vs_app_settings_csrf']); ?>">
      <input type="hidden" name="action" value="import_settings">
      <input class="form-control" style="max-width:360px" type="file" name="settings_file" accept=".json,application/json" required>
      <button class="btn btn-outline-primary" type="submit" onclick="return confirm('Replace your current settings with the ones in this file?');"><i class="bi bi-upload me-1" aria-hidden="true"></i>Import Settings</button>
    </form>
  </section>

  <section class="vs-card" data-tab="system">
    <div class="vs-card-head"><span class="vs-card-icon"><i class="bi bi-hdd-fill" aria-hidden="true"></i></span><h2>Server Health</h2></div>

    <?php if ($diskLow): ?>
      <div class="alert alert-warning" role="alert">
        <strong>Low disk space.</strong> Only <?php echo $h(vs_format_bytes((int)$diskFree)); ?> is free. Video uploads can fill the disk quickly, so free some space soon.
      </div>
    <?php endif; ?>

    <div class="vs-health-row"><span>Free disk space</span><strong><?php echo $diskKnown ? $h(vs_format_bytes((int)$diskFree) . ' of ' . vs_format_bytes((int)$diskTotal)) : 'Unknown'; ?></strong></div>
    <div class="vs-health-row"><span>PHP version</span><strong><?php echo $h(PHP_VERSION); ?></strong></div>
    <div class="vs-health-row"><span>PHP upload limit</span><strong><?php echo $h(ini_get('upload_max_filesize')); ?> (post <?php echo $h(ini_get('post_max_size')); ?>)</strong></div>
    <div class="vs-health-row"><span>Memory limit</span><strong><?php echo $h(ini_get('memory_limit')); ?></strong></div>
    <div class="vs-health-row"><span>Max script run time</span><strong><?php echo $h(ini_get('max_execution_time')); ?> s</strong></div>
  </section>
  </div>
</main>

</div>
</div>
<script>
(function () {
    function updateCounters() {
        document.querySelectorAll('.vs-counter').forEach(function (counter) {
            var field = document.getElementById(counter.getAttribute('data-for'));
            if (field) {
                counter.textContent = field.value.length + '/' + counter.getAttribute('data-max');
            }
        });
    }
    document.addEventListener('input', updateCounters);
    document.addEventListener('reset', function () { setTimeout(updateCounters, 0); });
    updateCounters();

    // Tabs + search: one tab at a time keeps the page short; typing in the search shows matches from every tab.
    var search = document.getElementById('vs-settings-search');
    var noMatch = document.getElementById('vs-no-match');
    var tabBtns = document.querySelectorAll('#vsn-tabs .vsn-tab');
    var saveBar = document.querySelector('.vsn-savebar');
    var activeTab = 'general';
    function isTab(name) {
        return Array.prototype.some.call(tabBtns, function (b) { return b.getAttribute('data-tab') === name; });
    }
    if (window.location.hash && isTab(window.location.hash.slice(1))) {
        activeTab = window.location.hash.slice(1);
    } else {
        try { var savedTab = window.sessionStorage.getItem('vsn-tab'); if (savedTab && isTab(savedTab)) { activeTab = savedTab; } } catch (e) {}
    }
    function applyView() {
        var query = search ? search.value.trim().toLowerCase() : '';
        var shown = 0;
        document.querySelectorAll('.vs-card').forEach(function (card) {
            var ok = query === ''
                ? card.getAttribute('data-tab') === activeTab
                : card.textContent.toLowerCase().indexOf(query) !== -1;
            card.classList.toggle('vs-hide', !ok);
            if (ok) { shown++; }
        });
        document.querySelectorAll('.vs-settings-grid').forEach(function (grid) {
            grid.classList.toggle('vs-hide', !grid.querySelector('.vs-card:not(.vs-hide)'));
        });
        tabBtns.forEach(function (b) {
            var on = query === '' && b.getAttribute('data-tab') === activeTab;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (saveBar) { saveBar.classList.toggle('vs-hide', query === '' && activeTab === 'system'); }
        if (noMatch) { noMatch.classList.toggle('d-none', shown > 0); }
    }
    function goTab(name) {
        activeTab = name;
        if (search) { search.value = ''; }
        try { window.sessionStorage.setItem('vsn-tab', name); } catch (e) {}
        applyView();
    }
    tabBtns.forEach(function (b) { b.addEventListener('click', function () { goTab(b.getAttribute('data-tab')); }); });
    if (search) { search.addEventListener('input', applyView); }
    // If the browser finds a wrong value in a tab you are not looking at, jump to that tab so it can be fixed.
    var settingsForm = document.getElementById('vs-settings-form');
    if (settingsForm) {
        settingsForm.addEventListener('invalid', function (event) {
            var card = event.target.closest ? event.target.closest('.vs-card') : null;
            if (card && card.classList.contains('vs-hide') && card.getAttribute('data-tab')) {
                goTab(card.getAttribute('data-tab'));
            }
        }, true);
    }
    applyView();

    // Change the logo without leaving this page.
    var logoBtn = document.getElementById('vs-logo-btn');
    var logoInput = document.getElementById('vs-logo-input');
    var logoMsg = document.getElementById('vs-logo-msg');
    var logoPreview = document.querySelector('.vs-logo-row img');
    var logoResetBtn = document.getElementById('vs-logo-reset');
    var csrfField = document.querySelector('#vs-settings-form input[name="csrf_token"]');
    if (logoBtn && logoInput && logoPreview && csrfField) {
        var logoHint = logoMsg ? logoMsg.textContent : '';
        var setLogoMsg = function (text, kind) {
            if (!logoMsg) { return; }
            logoMsg.textContent = text;
            logoMsg.style.color = kind === 'error' ? '#ff6b6b' : (kind === 'ok' ? '#4cd137' : '');
        };
        // Picking a file is not a change to the settings form, so it must not trigger the unsaved-changes warning.
        ['input', 'change'].forEach(function (type) {
            logoInput.addEventListener(type, function (event) { event.stopPropagation(); });
        });
        logoBtn.addEventListener('click', function () { logoInput.click(); });
        logoInput.addEventListener('change', function () {
            var file = logoInput.files && logoInput.files[0];
            if (!file) { return; }
            if (file.type !== 'image/png') {
                setLogoMsg('The logo must be a PNG image.', 'error');
                logoInput.value = '';
                return;
            }
            if (file.size > 4 * 1024 * 1024) {
                setLogoMsg('The logo must be smaller than 4 MB.', 'error');
                logoInput.value = '';
                return;
            }
            var body = new FormData();
            body.append('action', 'change_logo');
            body.append('csrf_token', csrfField.value);
            body.append('logo', file);
            logoBtn.disabled = true;
            setLogoMsg('Uploading…', '');
            fetch(window.location.pathname + window.location.search, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            }).then(function (response) {
                return response.json().catch(function () { return { ok: false, message: 'Unexpected response from the server.' }; });
            }).then(function (data) {
                if (data && data.ok && data.url) {
                    var oldSrc = logoPreview.getAttribute('src');
                    var newSrc = data.url + '?v=' + Date.now();
                    document.querySelectorAll('img').forEach(function (img) {
                        if (img.getAttribute('src') === oldSrc) { img.src = newSrc; }
                    });
                    setLogoMsg('Logo updated.', 'ok');
                    if (logoResetBtn) { logoResetBtn.disabled = false; }
                    setTimeout(function () { setLogoMsg(logoHint, ''); }, 4000);
                } else {
                    setLogoMsg((data && data.message) || 'The upload failed. Please try again.', 'error');
                }
            }).catch(function () {
                setLogoMsg('Could not reach the server. Please try again.', 'error');
            }).then(function () {
                logoBtn.disabled = false;
                logoInput.value = '';
            });
        });

        // Reset the logo to the built-in one.
        if (logoResetBtn) {
            logoResetBtn.addEventListener('click', function () {
                if (!window.confirm('Reset the logo to the default one?')) { return; }
                var resetBody = new FormData();
                resetBody.append('action', 'reset_logo');
                resetBody.append('csrf_token', csrfField.value);
                logoBtn.disabled = true;
                logoResetBtn.disabled = true;
                setLogoMsg('Resetting…', '');
                var resetOk = false;
                fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: resetBody,
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                }).then(function (response) {
                    return response.json().catch(function () { return { ok: false, message: 'Unexpected response from the server.' }; });
                }).then(function (data) {
                    if (data && data.ok && data.url) {
                        resetOk = true;
                        var oldSrc = logoPreview.getAttribute('src');
                        var newSrc = data.url + '?v=' + Date.now();
                        document.querySelectorAll('img').forEach(function (img) {
                            if (img.getAttribute('src') === oldSrc) { img.src = newSrc; }
                        });
                        setLogoMsg('Logo reset to the default.', 'ok');
                        setTimeout(function () { setLogoMsg(logoHint, ''); }, 4000);
                    } else {
                        setLogoMsg((data && data.message) || 'The reset failed. Please try again.', 'error');
                    }
                }).catch(function () {
                    setLogoMsg('Could not reach the server. Please try again.', 'error');
                }).then(function () {
                    logoBtn.disabled = false;
                    logoResetBtn.disabled = resetOk;
                });
            });
        }
    }

    // Warn before leaving the page with unsaved changes.
    var mainForm = document.getElementById('vs-settings-form');
    var dirty = false;
    if (mainForm) {
        mainForm.addEventListener('input', function () { dirty = true; });
        mainForm.addEventListener('change', function () { dirty = true; });
        mainForm.addEventListener('reset', function () { dirty = false; });
    }
    document.addEventListener('submit', function () { dirty = false; });
    document.querySelectorAll('a[href*="export=settings"]').forEach(function (link) {
        link.addEventListener('click', function () {
            var wasDirty = dirty;
            dirty = false;
            setTimeout(function () { dirty = wasDirty; }, 1500);
        });
    });
    window.addEventListener('beforeunload', function (event) {
        if (dirty) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
})();
</script>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body>
</html>