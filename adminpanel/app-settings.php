<?php
require_once __DIR__ . '/admin-bootstrap.php';

require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../category-presets.php';
require_once __DIR__ . '/../category-icons.php';
require_once __DIR__ . '/admin-activity-store.php';

// Owner only
if (empty($isSiteOwner)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Owner access required</title>'
        . '<style>:root{color-scheme:light dark;--bg:#0a0a0a;--card:#111;--text:#f4f7ff;--muted:#a8b0c4;--line:#242b3d;--accent:#ff2631;--accent-2:#b80d17}'
        . '*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;font-family:Inter,Segoe UI,Arial,sans-serif;color:var(--text);background:var(--bg)}'
        . '.access-card{width:min(520px,100%);padding:38px 36px 34px;text-align:center;border:1px solid var(--line);border-radius:22px;background:var(--card)}'
        . '.lock-wrap{width:74px;height:74px;margin:0 auto 22px;display:grid;place-items:center;border-radius:50%;background:rgba(255,38,49,.12);border:1px solid rgba(255,38,49,.22)}'
        . '.lock-wrap svg{width:34px;height:34px;stroke:var(--accent)}'
        . 'h1{margin:0 0 10px;font-size:2rem;line-height:1.1}'
        . '.message{color:var(--muted);font-size:16px;line-height:1.65;margin:0 0 24px}'
        . '.back-link{display:inline-flex;align-items:center;gap:9px;padding:12px 22px;border-radius:12px;text-decoration:none;font-weight:700;color:#fff;background:linear-gradient(135deg,#ff2631,#b80d17)}'
        . '</style></head><body>'
        . '<main class="access-card"><div class="lock-wrap"><svg viewBox="0 0 24 24"><rect x="4" y="10" width="16" height="11" rx="3"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg></div>'
        . '<h1>Owner Access Required</h1><p class="message">Only the site owner can change platform settings.</p>'
        . '<a class="back-link" href="index.php"><svg viewBox="0 0 24 24" width="18" height="18"><path d="M19 12H5"/><path d="M11 18l-6-6 6-6"/></svg>Back to dashboard</a>'
        . '</main></body></html>';
    exit;
}

// Export settings
if (($_GET['export'] ?? '') === 'settings') {
    $exportState = vs_branding_state();
    $exportState['last_exported'] = date('Y-m-d');
    try { vs_branding_save_state($exportState); } catch (Throwable $e) {}
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

// Change logo (AJAX)
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
        if ($err === UPLOAD_ERR_NO_FILE) throw new RuntimeException('Choose a logo file first.');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('The logo must be smaller than 4 MB.');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('The upload failed. Please try again.');
        if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) throw new RuntimeException('The logo must be smaller than 4 MB.');
        $info = @getimagesize($tmp);
        if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) throw new RuntimeException('The logo must be a PNG image.');
        $logoW = (int)$info[0]; $logoH = (int)$info[1];
        if ($logoW !== $logoH || $logoW < 128 || $logoW > 2048) throw new RuntimeException('The logo must be square, between 128 and 2048 pixels.');
        $logoDir = __DIR__ . '/../uploads/site-branding';
        if (!is_dir($logoDir) && !@mkdir($logoDir, 0755, true) && !is_dir($logoDir)) throw new RuntimeException('Could not create the uploads/site-branding folder.');
        $logoName = bin2hex(random_bytes(16)) . '.png';
        $logoDest = $logoDir . '/' . $logoName;
        if (!@move_uploaded_file($tmp, $logoDest)) throw new RuntimeException('Could not save the logo. Check folder permissions.');
        @chmod($logoDest, 0644);
        $savedLogoPath = $logoDest;
        $logoAdminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'An admin'));
        $logoState = vs_branding_state();
        $logoState['logo'] = 'uploads/site-branding/' . $logoName;
        $logoState['notification'] = ['message' => 'The site logo was updated by ' . $logoAdminName . '.', 'created_at' => date(DATE_ATOM)];
        vs_branding_save_state($logoState);
        $savedLogoPath = '';
        vs_admin_log($pdo ?? null, 'settings', 'App logo changed');
        $sendJson(200, ['ok' => true, 'url' => vs_site_url($logoState['logo']), 'message' => 'Logo updated.']);
    } catch (Throwable $logoError) {
        if ($savedLogoPath !== '' && is_file($savedLogoPath)) @unlink($savedLogoPath);
        $sendJson(400, ['ok' => false, 'message' => $logoError instanceof RuntimeException ? $logoError->getMessage() : 'Something went wrong.']);
    }
}

// Reset logo (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_logo') {
    $sendResetJson = static function (int $status, array $payload): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('The request expired.');
        $resetAdminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'An admin'));
        $resetState = vs_branding_state();
        $resetState['logo'] = VS_DEFAULT_LOGO_PATH;
        $resetState['notification'] = ['message' => 'The site logo was reset by ' . $resetAdminName . '.', 'created_at' => date(DATE_ATOM)];
        vs_branding_save_state($resetState);
        vs_admin_log($pdo ?? null, 'settings', 'App logo reset to default');
        $sendResetJson(200, ['ok' => true, 'url' => vs_site_url(VS_DEFAULT_LOGO_PATH), 'message' => 'Logo reset to the default.']);
    } catch (Throwable $resetError) {
        $sendResetJson(400, ['ok' => false, 'message' => $resetError instanceof RuntimeException ? $resetError->getMessage() : 'Something went wrong.']);
    }
}

// Save settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app_settings') {
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('The settings request expired. Refresh and try again.');
        $appName = trim((string)($_POST['app_name'] ?? ''));
        $appDescription = trim((string)($_POST['app_description'] ?? ''));
        $defaultTheme = (string)($_POST['default_theme'] ?? '');
        if ($appName === '' || mb_strlen($appName) > 50) throw new RuntimeException('Enter an app name between 1 and 50 characters.');
        if ($appDescription === '' || mb_strlen($appDescription) > 180) throw new RuntimeException('Enter an app description between 1 and 180 characters.');
        if (!in_array($defaultTheme, ['dark', 'light'], true)) throw new RuntimeException('Choose either light or dark as the default theme.');
        $newUploadsEnabled = isset($_POST['uploads_enabled']);
        $newUploadsRequireApproval = isset($_POST['uploads_require_approval']);
        $newMaintenanceEnabled = isset($_POST['maintenance_enabled']);
        $newMaintenanceMessage = trim((string)($_POST['maintenance_message'] ?? ''));
        if (mb_strlen($newMaintenanceMessage) > 300) throw new RuntimeException('The maintenance message can be at most 300 characters.');
        $newMaxUploadMb = filter_var($_POST['max_upload_mb'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4096]]);
        if ($newMaxUploadMb === false) throw new RuntimeException('Enter a maximum upload size between 1 and 4096 MB.');
        $newRegistrationMode = (string)($_POST['registration_mode'] ?? 'open');
        if (!in_array($newRegistrationMode, ['open', 'approval', 'closed'], true)) throw new RuntimeException('Choose a valid registration mode.');
        $newAnnouncementEnabled = isset($_POST['announcement_enabled']);
        $newAnnouncementMessage = trim((string)($_POST['announcement_message'] ?? ''));
        $newAnnouncementExpires = trim((string)($_POST['announcement_expires'] ?? ''));
        if (mb_strlen($newAnnouncementMessage) > 200) throw new RuntimeException('The announcement can be at most 200 characters.');
        if ($newAnnouncementEnabled && $newAnnouncementMessage === '') throw new RuntimeException('Write the announcement text, or turn the banner off.');
        $newSupportEmail = trim((string)($_POST['support_email'] ?? ''));
        if ($newSupportEmail !== '' && (mb_strlen($newSupportEmail) > 120 || !filter_var($newSupportEmail, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Enter a valid support email address.');
        $cleanUrl = static function (string $label, string $value): string {
            $value = trim($value);
            if ($value === '') return '';
            if (mb_strlen($value) > 255 || !filter_var($value, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $value)) throw new RuntimeException($label . ' must be a full link starting with http:// or https://.');
            return $value;
        };
        $newTermsUrl = $cleanUrl('The Terms link', (string)($_POST['terms_url'] ?? ''));
        $newPrivacyUrl = $cleanUrl('The Privacy link', (string)($_POST['privacy_url'] ?? ''));
        $newCommentsEnabled = isset($_POST['comments_enabled']);
        $newCommentsRequireApproval = isset($_POST['comments_require_approval']);
        $newCommentsRequireLogin = isset($_POST['comments_require_login']);
        $newBannedWords = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['banned_words'] ?? '')) as $w) {
            $w = trim((string)preg_replace('/\s+/', ' ', $w));
            if ($w === '') continue;
            if (mb_strlen($w) < 2 || mb_strlen($w) > 40 || preg_match('/[\x00-\x1f\x7f]/', $w)) throw new RuntimeException('Each banned word must be 2 to 40 characters: "' . $w . '".');
            if (!in_array(mb_strtolower($w), array_map('mb_strtolower', $newBannedWords), true)) $newBannedWords[] = $w;
        }
        if (count($newBannedWords) > 200) throw new RuntimeException('Use at most 200 banned words.');
        $newFeedItems = filter_var($_POST['feed_items_per_page'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 6, 'max_range' => 100]]);
        if ($newFeedItems === false) throw new RuntimeException('Enter a number of videos per page between 6 and 100.');
        $newFeedSort = (string)($_POST['feed_default_sort'] ?? 'newest');
        if (!in_array($newFeedSort, ['newest', 'trending'], true)) throw new RuntimeException('Choose either newest or trending as the default feed order.');
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
        $newSocialLinks = [];
        foreach (vs_social_networks() as $networkKey => $networkLabel) {
            $newSocialLinks[$networkKey] = $cleanUrl($networkLabel . ' link', (string)($_POST['social_' . $networkKey] ?? ''));
        }
        $newAnalyticsId = strtoupper(trim((string)($_POST['analytics_id'] ?? '')));
        if ($newAnalyticsId !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $newAnalyticsId)) throw new RuntimeException('Enter a Google Analytics ID like G-XXXXXXXXXX, or leave it empty.');
        $allFormats = vs_video_formats_all();
        $postedFormats = [];
        foreach ((array)($_POST['video_formats'] ?? []) as $format) {
            $format = strtolower(trim((string)$format));
            if (isset($allFormats[$format])) $postedFormats[$format] = true;
        }
        $newFormats = array_values(array_filter(array_keys($allFormats), static fn($f) => isset($postedFormats[$f])));
        if (!$newFormats) throw new RuntimeException('Allow at least one video format.');
        $newShortSeconds = filter_var($_POST['max_short_seconds'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 600]]);
        if ($newShortSeconds === false) throw new RuntimeException('Enter a short video length between 10 and 600 seconds.');
        $newDailyLimit = filter_var($_POST['daily_upload_limit'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
        if ($newDailyLimit === false) throw new RuntimeException('Enter a daily upload limit between 0 and 1000.');
        $newBlockedDomains = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['blocked_email_domains'] ?? '')) as $d) {
            $d = strtolower(ltrim(trim($d), '@'));
            if ($d === '') continue;
            if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/', $d)) throw new RuntimeException('"' . $d . '" is not a valid email domain.');
            if (!in_array($d, $newBlockedDomains, true)) $newBlockedDomains[] = $d;
        }
        $newBlockedIps = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['blocked_ips'] ?? '')) as $ip) {
            $ip = trim($ip);
            if ($ip === '') continue;
            if (!vs_is_valid_ip_rule($ip)) throw new RuntimeException('"' . $ip . '" is not a valid IP address or IPv4 range.');
            if (!in_array($ip, $newBlockedIps, true)) $newBlockedIps[] = $ip;
        }
        $yourIp = vs_client_ip();
        foreach ($newBlockedIps as $ipRule) {
            if (vs_ip_matches($yourIp, $ipRule)) throw new RuntimeException('That would block your own address (' . $yourIp . '). Remove "' . $ipRule . '" from the blocked IP list.');
        }
        $newLoginAttempts = filter_var($_POST['login_max_attempts'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 3, 'max_range' => 20]]);
        if ($newLoginAttempts === false) throw new RuntimeException('Enter the failed sign-in limit as a number between 3 and 20.');
        $newLockoutMinutes = filter_var($_POST['login_lockout_minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        if ($newLockoutMinutes === false) throw new RuntimeException('Enter the lockout time between 1 and 1440 minutes.');
        $newSessionMinutes = filter_var($_POST['admin_session_minutes'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 1440]]);
        if ($newSessionMinutes === false) throw new RuntimeException('Enter the admin session time between 5 and 1440 minutes.');
        $newBrandColor = strtolower(trim((string)($_POST['brand_color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $newBrandColor)) throw new RuntimeException('Pick a valid brand colour.');
        $newTimezone = (string)($_POST['site_timezone'] ?? '');
        $tzAliases = ['pak' => 'Asia/Karachi', 'pakistan' => 'Asia/Karachi', 'pk' => 'Asia/Karachi', 'pkt' => 'Asia/Karachi'];
        if (isset($tzAliases[strtolower(trim($newTimezone))])) $newTimezone = $tzAliases[strtolower(trim($newTimezone))];
        if (!in_array($newTimezone, DateTimeZone::listIdentifiers(), true)) throw new RuntimeException('Choose a valid time zone.');
        $newDateFormat = (string)($_POST['date_format'] ?? '');
        if (!isset(vs_date_formats()[$newDateFormat])) throw new RuntimeException('Choose a valid date format.');
        $newFixedDate = trim((string)($_POST['site_fixed_date'] ?? ''));
        $fixedDateValue = DateTime::createFromFormat('!Y-m-d', $newFixedDate, new DateTimeZone($newTimezone));
        if ($newFixedDate === '' || !$fixedDateValue || $fixedDateValue->format('Y-m-d') !== $newFixedDate) throw new RuntimeException('Choose a valid site date.');
        $newCookieEnabled = isset($_POST['cookie_notice_enabled']);
        $newCookieText = trim((string)($_POST['cookie_notice_text'] ?? ''));
        $newMatureEnabled = isset($_POST['mature_content_enabled']);
        $newMatureText = trim((string)($_POST['mature_warning_text'] ?? ''));
        $newCopyrightEmail = trim((string)($_POST['copyright_email'] ?? ''));
        if ($newCopyrightEmail !== '' && (mb_strlen($newCopyrightEmail) > 120 || !filter_var($newCopyrightEmail, FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Enter a valid copyright contact email address.');
        $newCopyrightNotice = trim((string)($_POST['copyright_notice'] ?? ''));
        $newSeoKeywords = trim((string)($_POST['seo_keywords'] ?? ''));
        $newAllowSearchEngines = isset($_POST['allow_search_engines']);
        $newSeoImage = $cleanUrl('The share image link', (string)($_POST['seo_share_image_url'] ?? ''));
        $newCategories = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['categories'] ?? '')) as $catLine) {
            $catLine = trim((string)preg_replace('/\s+/', ' ', $catLine));
            if ($catLine === '') continue;
            if (!preg_match('/^[A-Za-z0-9 _-]{1,30}$/', $catLine)) throw new RuntimeException('Categories can use letters, numbers, spaces, - and _ only (up to 30 chars): "' . $catLine . '".');
            if (!in_array(mb_strtolower($catLine), array_map('mb_strtolower', $newCategories), true)) $newCategories[] = $catLine;
        }
        if (count($newCategories) > 50) throw new RuntimeException('Use at most 50 categories.');
        $existingColors = vs_category_colors($brandingState);
        $postedColors = (array)($_POST['cat_color'] ?? []);
        $newColors = [];
        foreach ($newCategories as $catName) {
            $key = mb_strtolower($catName);
            $posted = null;
            foreach ($postedColors as $pn => $pc) {
                if (mb_strtolower(trim((string)$pn)) === $key) { $posted = strtolower(trim((string)$pc)); break; }
            }
            if ($posted !== null && preg_match('/^#[0-9a-f]{6}$/', $posted)) $newColors[$key] = $posted;
            elseif (isset($existingColors[$key])) $newColors[$key] = $existingColors[$key];
        }
        $nextState = $brandingState;
        $nextState['app_name'] = $appName; $nextState['app_description'] = $appDescription; $nextState['default_theme'] = $defaultTheme;
        $nextState['uploads_enabled'] = $newUploadsEnabled; $nextState['uploads_require_approval'] = $newUploadsRequireApproval; $nextState['max_upload_mb'] = $newMaxUploadMb;
        $nextState['maintenance_enabled'] = $newMaintenanceEnabled; $nextState['maintenance_message'] = $newMaintenanceMessage;
        $nextState['categories'] = $newCategories; $nextState['category_colors'] = $newColors;
        $nextState['registration_mode'] = $newRegistrationMode;
        $nextState['announcement_enabled'] = $newAnnouncementEnabled; $nextState['announcement_message'] = $newAnnouncementMessage; $nextState['announcement_expires'] = $newAnnouncementExpires;
        $nextState['support_email'] = $newSupportEmail; $nextState['terms_url'] = $newTermsUrl; $nextState['privacy_url'] = $newPrivacyUrl;
        $nextState['comments_enabled'] = $newCommentsEnabled; $nextState['comments_require_approval'] = $newCommentsRequireApproval; $nextState['comments_require_login'] = $newCommentsRequireLogin;
        $nextState['banned_words'] = $newBannedWords; $nextState['feed_items_per_page'] = (int)$newFeedItems; $nextState['feed_default_sort'] = $newFeedSort;
        $nextState['brain_meter'] = $newBrainMeter; $nextState['social_links'] = $newSocialLinks; $nextState['analytics_id'] = $newAnalyticsId;
        $nextState['allowed_video_formats'] = $newFormats; $nextState['max_short_seconds'] = (int)$newShortSeconds; $nextState['daily_upload_limit'] = (int)$newDailyLimit;
        $nextState['blocked_email_domains'] = $newBlockedDomains; $nextState['blocked_ips'] = $newBlockedIps;
        $nextState['login_max_attempts'] = (int)$newLoginAttempts; $nextState['login_lockout_minutes'] = (int)$newLockoutMinutes; $nextState['admin_session_minutes'] = (int)$newSessionMinutes;
        $nextState['brand_color'] = $newBrandColor; $nextState['site_timezone'] = $newTimezone; $nextState['date_format'] = $newDateFormat; $nextState['site_fixed_date'] = $newFixedDate;
        $nextState['cookie_notice_enabled'] = $newCookieEnabled; $nextState['cookie_notice_text'] = $newCookieText;
        $nextState['mature_content_enabled'] = $newMatureEnabled; $nextState['mature_warning_text'] = $newMatureText;
        $nextState['copyright_email'] = $newCopyrightEmail; $nextState['copyright_notice'] = $newCopyrightNotice;
        $nextState['seo_keywords'] = $newSeoKeywords; $nextState['allow_search_engines'] = $newAllowSearchEngines; $nextState['seo_share_image_url'] = $newSeoImage;
        vs_branding_save_state($nextState);
        vs_admin_log($pdo ?? null, 'settings', 'App settings saved');
        $brandingState = $nextState;
        $successMsg = 'Settings saved successfully.';
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

// Import settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_settings') {
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('The import request expired.');
        $upload = $_FILES['settings_file'] ?? null;
        if (!$upload || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$upload['tmp_name'])) throw new RuntimeException('Choose a settings backup (.json) file to import.');
        if ((int)$upload['size'] > 262144) throw new RuntimeException('That file is too large to be a settings backup.');
        $imported = json_decode((string)file_get_contents($upload['tmp_name']), true);
        if (!is_array($imported)) throw new RuntimeException('That file is not a valid settings backup.');
        [$importedState, $appliedKeys, $skippedKeys] = vs_import_settings($imported, $brandingState);
        if (!$appliedKeys) throw new RuntimeException('No valid settings were found in that file.');
        foreach (vs_blocked_ips($importedState) as $ipRule) {
            if (vs_ip_matches(vs_client_ip(), $ipRule)) throw new RuntimeException('Importing this file would block your own address (' . vs_client_ip() . ').');
        }
        vs_branding_save_state($importedState);
        vs_admin_log($pdo ?? null, 'settings', 'App settings imported');
        $brandingState = $importedState;
        $successMsg = 'Imported ' . count($appliedKeys) . ' settings' . ($skippedKeys ? '. Skipped: ' . implode(', ', $skippedKeys) : '') . '.';
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

// Collect display variables
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
$siteFixedDate = trim((string)($brandingState['site_fixed_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $siteFixedDate)) {
    $siteFixedDate = (new DateTime('now', new DateTimeZone($siteTimezone)))->format('Y-m-d');
}
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
$phpLimitBytes = vs_max_upload_bytes();
$configuredLimitBytes = (int)$maxUploadMb * 1024 * 1024;
$phpLimitLower = $phpLimitBytes < $configuredLimitBytes;
$healthPath = is_dir(dirname(__DIR__) . '/uploads') ? dirname(__DIR__) . '/uploads' : dirname(__DIR__);
$diskFree = @disk_free_space($healthPath);
$diskTotal = @disk_total_space($healthPath);
$diskKnown = ($diskFree !== false && $diskTotal !== false && $diskTotal > 0);
$diskLow = $diskKnown && ($diskFree < 2 * 1073741824 || ($diskFree / $diskTotal) < 0.10);
$maintenanceStart = (string)($brandingState['maintenance_start'] ?? '');
$maintenanceEnd = (string)($brandingState['maintenance_end'] ?? '');

$timezoneList = array_values(array_unique(array_merge(['Asia/Karachi'], DateTimeZone::listIdentifiers())));
$countryCodes = preg_split('/\s+/', 'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW');
$countryZones = [];
foreach ($countryCodes as $cc) {
    $zones = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, $cc);
    if ($zones) $countryZones[$cc] = $zones[0];
}
$countryZones['US'] = 'America/New_York'; $countryZones['CA'] = 'America/Toronto'; $countryZones['AU'] = 'Australia/Sydney';
$countryZones['BR'] = 'America/Sao_Paulo'; $countryZones['RU'] = 'Europe/Moscow'; $countryZones['MX'] = 'America/Mexico_City'; $countryZones['PK'] = 'Asia/Karachi';
$selectedCountry = (string)(array_search($siteTimezone, $countryZones, true) ?: '');
if ($selectedCountry === '') { $selectedCountry = 'ZZ'; $countryZones['ZZ'] = $siteTimezone; }
$countryZonesJson = json_encode($countryZones, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

require_once __DIR__ . '/header.php';
?>

<style>
/* ============================================================
   VibeStream – Platform Settings  (matches the dark-red mockup)
   ============================================================ */

/* ---- tokens ---- */
:root {
  --vs-red:  #ff2631;
  --vs-red2: #b80d17;
  --vs-bg:   #0f0f0f;
  --vs-page: radial-gradient(900px 480px at 8% -8%,rgba(181,13,22,.12),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(255,42,50,.08),transparent 70%),#0f0f0f;
  --vs-card: #16161a;
  --vs-card-fill: linear-gradient(145deg,#16161a,#121215);
  --vs-card2:#202027;
  --vs-line: rgba(181,13,22,.4);
  --vs-line2:rgba(255,255,255,.09);
  --vs-text: #f5f5f7;
  --vs-muted:#aaaab4;
  --vs-glow: rgba(255,42,50,.28);
  --vs-card-shadow: 0 0 26px -10px rgba(181,13,22,.4);
  --vs-hover: rgba(255,255,255,.05);
  --vs-toggle-off: rgba(255,255,255,.12);
  --vs-savebar-bg: rgba(15,15,15,.96);
  --vs-on:   #22c55e;
  --vs-warn: #f59e0b;
  --radius:  14px;
}
html { color-scheme:dark; }
html[data-bs-theme="light"],html[data-theme="light"] {
  color-scheme:light;
  --vs-bg: #f5f6fa;
  --vs-page: radial-gradient(900px 480px at 8% -8%,rgba(181,13,22,.08),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(255,42,50,.07),transparent 70%),#f5f6fa;
  --vs-card: #fff;
  --vs-card-fill: linear-gradient(145deg,#fff,#fbfbfe);
  --vs-card2: #fff;
  --vs-line: rgba(181,13,22,.28);
  --vs-line2: rgba(38,28,40,.12);
  --vs-text: #17171c;
  --vs-muted: #666671;
  --vs-glow: rgba(181,13,22,.18);
  --vs-card-shadow: 0 0 20px -12px rgba(181,13,22,.3);
  --vs-hover: rgba(181,13,22,.06);
  --vs-toggle-off: #d7d9e0;
  --vs-savebar-bg: rgba(245,246,250,.96);
}

/* ---- layout shell ---- */
.ps-wrap { display:flex; flex-direction:column; gap:0; padding:28px 32px 112px; min-height:100vh; color:var(--vs-text); background:var(--vs-page); }

/* ---- hero ---- */
.ps-hero { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:14px 24px; margin-bottom:24px; padding:2px 0 18px; border-bottom:1px solid var(--vs-line2); }
.ps-hero-left { display:flex; align-items:center; gap:16px; }
.ps-hero-icon { width:54px; height:54px; border-radius:14px; display:grid; place-items:center; font-size:1.6rem; color:#fff; background:linear-gradient(135deg,var(--vs-red),var(--vs-red2)); box-shadow:0 0 28px var(--vs-glow); flex:0 0 auto; }
.ps-hero h1 { margin:0; font-size:1.6rem; font-weight:800; letter-spacing:-.02em; }
.ps-hero p  { margin:3px 0 0; font-size:.88rem; color:var(--vs-muted); }
.ps-status-chips { display:flex; flex-wrap:wrap; gap:8px; }
.ps-chip { display:inline-flex; align-items:center; gap:7px; padding:7px 14px; border-radius:999px; font-size:.8rem; font-weight:700; border:1px solid transparent; }
.ps-chip .dot { width:7px; height:7px; border-radius:50%; }
.ps-chip.on  { background:rgba(34,197,94,.12);  border-color:rgba(34,197,94,.35);  color:#4ade80; }
.ps-chip.on .dot  { background:#22c55e; box-shadow:0 0 6px #22c55e; }
.ps-chip.off { background:rgba(239,68,68,.12);  border-color:rgba(239,68,68,.35);  color:#f87171; }
.ps-chip.off .dot { background:#ef4444; }
.ps-chip.info{ background:rgba(96,165,250,.12); border-color:rgba(96,165,250,.35); color:#93c5fd; }
.ps-chip.info .dot{ background:#60a5fa; }

/* ---- alerts ---- */
.ps-alert { display:flex; align-items:center; gap:10px; padding:12px 16px; border-radius:12px; font-size:.88rem; margin-bottom:16px; }
.ps-alert.ok   { background:rgba(34,197,94,.1);  border:1px solid rgba(34,197,94,.3);  color:#4ade80; }
.ps-alert.err  { background:rgba(239,68,68,.1);  border:1px solid rgba(239,68,68,.3);  color:#f87171; }
.ps-alert.warn { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.3); color:#fcd34d; }

/* ---- tab bar ---- */
.ps-tabs { display:flex; gap:5px; overflow-x:auto; padding:6px; border-radius:16px; background:var(--vs-card); border:1px solid var(--vs-line2); margin-bottom:22px; scrollbar-width:none; box-shadow:var(--vs-card-shadow); }
.ps-tabs::-webkit-scrollbar { display:none; }
.ps-tab { display:inline-flex; align-items:center; gap:8px; white-space:nowrap; padding:10px 16px; border:0; border-radius:11px; background:transparent; color:var(--vs-muted); font-weight:700; font-size:.875rem; cursor:pointer; transition:.18s ease; }
.ps-tab:hover  { color:var(--vs-text); background:var(--vs-hover); }
.ps-tab.active { color:#fff; background:linear-gradient(135deg,var(--vs-red),var(--vs-red2)); box-shadow:0 4px 16px var(--vs-glow); }
.ps-tab i { font-size:1rem; }

/* ---- settings grid ---- */
.ps-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:20px; align-items:stretch; }

/* ---- card ---- */
.ps-card { background:var(--vs-card-fill); border:1px solid var(--vs-line); border-radius:16px; padding:22px; box-shadow:var(--vs-card-shadow); transition:border-color .18s,box-shadow .18s,transform .18s; }
.ps-card:hover { border-color:var(--vs-red); box-shadow:var(--vs-card-shadow),0 0 26px var(--vs-glow); }
.ps-card-head { display:flex; align-items:center; gap:12px; margin-bottom:18px; padding-bottom:14px; border-bottom:1px solid var(--vs-line2); }
.ps-card-icon { width:40px; height:40px; border-radius:12px; display:grid; place-items:center; font-size:1.08rem; color:var(--vs-red); background:rgba(255,38,49,.11); border:1px solid rgba(255,38,49,.12); flex:0 0 auto; }
.ps-card-head h2 { margin:0; font-size:1.05rem; font-weight:800; letter-spacing:-.01em; }
.ps-card-head p  { margin:3px 0 0; font-size:.79rem; color:var(--vs-muted); }

/* ---- form elements ---- */
.ps-label { display:block; font-size:.82rem; font-weight:600; color:var(--vs-muted); margin-bottom:5px; }
.ps-input, .ps-select, .ps-textarea { width:100%; min-height:42px; background:var(--vs-card2); border:1px solid var(--vs-line2); border-radius:10px; color:var(--vs-text); padding:9px 12px; font-size:.88rem; transition:.15s; outline:0; font-family:inherit; box-sizing:border-box; }
.ps-input:focus, .ps-select:focus, .ps-textarea:focus { border-color:var(--vs-red); box-shadow:0 0 0 3px rgba(255,38,49,.18); }
.ps-textarea { resize:vertical; min-height:80px; }
.ps-hint { font-size:.75rem; color:var(--vs-muted); margin-top:5px; line-height:1.4; }
.ps-row { display:grid; grid-template-columns:repeat(2,1fr); gap:12px; }
.ps-row3 { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
.ps-mb { margin-bottom:14px; }

/* ---- toggle switch ---- */
.ps-switch-row { display:flex; align-items:center; justify-content:space-between; gap:12px; min-height:49px; padding:10px 0; border-bottom:1px solid var(--vs-line2); }
.ps-switch-row:last-child { border-bottom:0; }
.ps-switch-label { font-size:.88rem; font-weight:600; }
.ps-switch-desc  { font-size:.75rem; color:var(--vs-muted); margin-top:2px; }
/* native checkbox restyled as pill toggle */
.ps-toggle { position:relative; display:inline-block; width:44px; height:24px; flex:0 0 44px; }
.ps-toggle input { opacity:0; width:0; height:0; }
.ps-toggle-track { position:absolute; inset:0; border-radius:999px; background:var(--vs-toggle-off); cursor:pointer; transition:.2s; }
.ps-toggle input:checked + .ps-toggle-track { background:var(--vs-red); box-shadow:0 0 10px var(--vs-glow); }
.ps-toggle-track::before { content:''; position:absolute; width:18px; height:18px; left:3px; top:3px; border-radius:50%; background:#fff; transition:.2s; box-shadow:0 1px 4px rgba(0,0,0,.35); }
.ps-toggle input:checked + .ps-toggle-track::before { left:23px; }

/* ---- format badges ---- */
.ps-formats { display:flex; flex-wrap:wrap; gap:8px; }
.ps-fmt { position:relative; display:inline-flex; }
.ps-fmt input { position:absolute; opacity:0; width:0; height:0; }
.ps-fmt-label { padding:5px 13px; border-radius:999px; border:1px solid var(--vs-line2); font-size:.8rem; font-weight:700; color:var(--vs-muted); cursor:pointer; transition:.15s; }
.ps-fmt input:checked + .ps-fmt-label { background:var(--vs-red); border-color:var(--vs-red); color:#fff; box-shadow:0 0 10px var(--vs-glow); }

/* ---- logo section ---- */
.ps-logo-row { display:flex; align-items:center; gap:14px; margin-bottom:14px; }
.ps-logo-row img { width:72px; height:72px; border-radius:14px; object-fit:cover; border:1px solid var(--vs-line2); }
.ps-logo-actions { display:flex; flex-direction:column; gap:7px; }

/* ---- colour grid ---- */
.ps-cat-chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.ps-cat-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; border:1px solid var(--vs-line2); background:var(--vs-card2); font-size:.76rem; }
.ps-cat-chip input[type="color"] { width:16px; height:16px; padding:0; border:0; background:transparent; cursor:pointer; border-radius:50%; }

/* ---- health rows ---- */
.ps-health-row { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:10px 0; border-bottom:1px solid var(--vs-line2); font-size:.87rem; }
.ps-health-row:last-child { border-bottom:0; }
.ps-health-val { font-weight:700; color:var(--vs-text); }

/* ---- bottom section (import / server health) ---- */
.ps-bottom { display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-top:16px; }

/* ---- sticky save bar ---- */
.ps-savebar { position:fixed; bottom:0; left:0; right:0; z-index:100; display:flex; align-items:center; justify-content:space-between; gap:16px; padding:14px 32px; background:var(--vs-savebar-bg); border-top:1px solid var(--vs-line2); backdrop-filter:blur(10px); }
.ps-savebar-left { display:flex; align-items:center; gap:8px; font-size:.85rem; color:var(--vs-warn); }
.ps-savebar-left.hidden { visibility:hidden; }
.ps-savebar-left i { font-size:1rem; }
.ps-savebar-actions { display:flex; gap:10px; }

/* ---- buttons ---- */
.ps-btn { display:inline-flex; align-items:center; gap:7px; padding:9px 18px; border-radius:10px; font-size:.875rem; font-weight:700; border:0; cursor:pointer; transition:.15s; text-decoration:none; white-space:nowrap; }
.ps-btn-primary { background:linear-gradient(135deg,var(--vs-red),var(--vs-red2)); color:#fff; box-shadow:0 6px 20px var(--vs-glow); }
.ps-btn-primary:hover { filter:brightness(1.12); color:#fff; }
.ps-btn-outline { background:transparent; border:1px solid var(--vs-line2); color:var(--vs-text); }
.ps-btn-outline:hover { border-color:var(--vs-red); color:var(--vs-red); }
.ps-btn-sm { padding:6px 12px; font-size:.8rem; }

/* ---- tab panel visibility ---- */
.ps-panel { display:none; }
.ps-panel.active { display:block; }

/* ---- radio group ---- */
.ps-radios { display:flex; gap:10px; flex-wrap:wrap; margin-top:4px; }
.ps-radio { display:inline-flex; align-items:center; gap:7px; cursor:pointer; font-size:.88rem; }
.ps-radio input { accent-color:var(--vs-red); width:16px; height:16px; }

/* ---- select ---- */
.ps-select { appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%239a9aaa' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 12px center; padding-right:32px; }

/* ---- session select wrapper ---- */
.ps-sel-row { display:flex; gap:8px; align-items:center; }
.ps-sel-row .ps-select { flex:1; }

/* ---- divider ---- */
.ps-divider { border:0; border-top:1px solid var(--vs-line2); margin:14px 0; }

/* ---- responsive ---- */
@media(max-width:900px) {
  .ps-grid { grid-template-columns:1fr; }
  .ps-row3 { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:600px) {
  .ps-wrap { padding:16px 14px 90px; }
  .ps-row { grid-template-columns:1fr; }
  .ps-row3 { grid-template-columns:1fr; }
  .ps-savebar { padding:12px 16px; }
}
</style>

<main class="ps-wrap">

  <!-- HERO -->
  <header class="ps-hero">
    <div class="ps-hero-left">
      <div class="ps-hero-icon"><i class="bi bi-gear-fill"></i></div>
      <div>
        <h1>Platform Settings</h1>
        <p>Manage your platform, uploads, security and appearance.</p>
      </div>
    </div>
    <div class="ps-status-chips">
      <span class="ps-chip <?php echo $maintenanceEnabled ? 'off' : 'on'; ?>">
        <span class="dot"></span><?php echo $maintenanceEnabled ? 'Maintenance Mode' : 'Platform Online'; ?>
      </span>
      <span class="ps-chip <?php echo $uploadsEnabled ? 'on' : 'off'; ?>">
        <span class="dot"></span><?php echo $uploadsEnabled ? 'Uploads Enabled' : 'Uploads Disabled'; ?>
      </span>
      <span class="ps-chip <?php echo $registrationMode === 'open' ? 'info' : ($registrationMode === 'closed' ? 'off' : 'warn'); ?>">
        <span class="dot"></span>Registration <?php echo ucfirst($h($registrationMode)); ?>
      </span>
    </div>
  </header>

  <!-- ALERTS -->
  <?php if ($successMsg !== ''): ?>
    <div class="ps-alert ok"><i class="bi bi-check-circle-fill"></i><?php echo $h($successMsg); ?></div>
  <?php endif; ?>
  <?php if ($errorMsg !== ''): ?>
    <div class="ps-alert err"><i class="bi bi-exclamation-circle-fill"></i><?php echo $h($errorMsg); ?></div>
  <?php endif; ?>
  <?php if ($phpLimitLower): ?>
    <div class="ps-alert warn"><i class="bi bi-exclamation-triangle-fill"></i>Upload limit mismatch: PHP on this server only accepts <?php echo $h(vs_format_bytes($phpLimitBytes)); ?>. Raise <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini.</div>
  <?php endif; ?>
  <?php if ($diskLow): ?>
    <div class="ps-alert warn"><i class="bi bi-hdd-fill"></i>Low disk space: only <?php echo $h(vs_format_bytes((int)$diskFree)); ?> remaining. Free some space soon.</div>
  <?php endif; ?>

  <!-- TABS -->
  <nav class="ps-tabs" id="ps-tabs" role="tablist" aria-label="Settings sections">
    <button class="ps-tab active" data-tab="general" role="tab"><i class="bi bi-sliders"></i>General</button>
    <button class="ps-tab" data-tab="uploads"  role="tab"><i class="bi bi-cloud-upload"></i>Uploads</button>
    <button class="ps-tab" data-tab="users"    role="tab"><i class="bi bi-people"></i>Users &amp; Access</button>
    <button class="ps-tab" data-tab="mod"      role="tab"><i class="bi bi-chat-square-dots"></i>Moderation</button>
    <button class="ps-tab" data-tab="appear"   role="tab"><i class="bi bi-palette"></i>Appearance</button>
    <button class="ps-tab" data-tab="security" role="tab"><i class="bi bi-shield-lock"></i>Security</button>
    <button class="ps-tab" data-tab="seo"      role="tab"><i class="bi bi-bar-chart-line"></i>SEO</button>
  </nav>

  <form method="post" id="ps-form">
    <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['vs_app_settings_csrf']); ?>">
    <input type="hidden" name="action" value="save_app_settings">
    <input type="hidden" id="ps-date-format-hidden" name="date_format" value="<?php echo $h($dateFormat); ?>">
    <input type="hidden" id="ps-timezone-hidden"    name="site_timezone" value="<?php echo $h($siteTimezone); ?>">

    <!-- ══════════════════ GENERAL ══════════════════ -->
    <div class="ps-panel active" id="tab-general">
      <div class="ps-grid">

        <!-- App Branding -->
        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-palette-fill"></i></div>
            <div><h2>App Branding</h2><p>Customize your app's name, logo and description.</p></div>
          </div>
          <div class="ps-logo-row">
            <img src="<?php echo $h(vs_branding_logo_url()); ?>" alt="App logo" id="ps-logo-img" width="72" height="72">
            <div class="ps-logo-actions">
              <strong style="font-size:.9rem"><?php echo $h($appName); ?></strong>
              <button type="button" class="ps-btn ps-btn-outline ps-btn-sm" id="ps-logo-btn"><i class="bi bi-image"></i>Change Logo</button>
              <input type="file" id="ps-logo-input" accept="image/png" hidden>
              <div class="ps-hint" id="ps-logo-msg" role="status" aria-live="polite">PNG · square · 128–2048 px · &lt;4 MB</div>
            </div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="app_name">App Name</label>
            <input class="ps-input" type="text" id="app_name" name="app_name" value="<?php echo $h($appName); ?>" maxlength="50" required>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="app_description">App Description</label>
            <textarea class="ps-textarea" id="app_description" name="app_description" maxlength="180" rows="2" required><?php echo $h($appDescription); ?></textarea>
            <div class="ps-hint" id="ps-desc-counter" style="text-align:right"></div>
          </div>
          <div>
            <label class="ps-label">Default Theme</label>
            <div class="ps-radios">
              <label class="ps-radio"><input type="radio" name="default_theme" value="dark"<?php echo $defaultTheme === 'dark' ? ' checked' : ''; ?>>Dark</label>
              <label class="ps-radio"><input type="radio" name="default_theme" value="light"<?php echo $defaultTheme === 'light' ? ' checked' : ''; ?>>Light</label>
            </div>
            <div class="ps-hint">For visitors who haven't chosen a theme.</div>
          </div>
        </div>

        <!-- Upload Settings -->
        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-cloud-plus-fill"></i></div>
            <div><h2>Upload Settings</h2><p>Configure video uploads and limits.</p></div>
          </div>

          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Uploads Enabled</div></div>
            <label class="ps-toggle"><input type="checkbox" name="uploads_enabled" value="1"<?php echo $uploadsEnabled ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Approval Required</div></div>
            <label class="ps-toggle"><input type="checkbox" name="uploads_require_approval" value="1"<?php echo $uploadsRequireApproval ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>

          <hr class="ps-divider">
          <div class="ps-mb">
            <label class="ps-label" for="max_upload_mb">Max Upload Size</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input class="ps-input" style="flex:1" type="number" id="max_upload_mb" name="max_upload_mb" min="1" max="4096" value="<?php echo (int)$maxUploadMb; ?>" required>
              <span style="color:var(--vs-muted);font-size:.88rem;white-space:nowrap">MB</span>
            </div>
            <div class="ps-hint">PHP allows <strong><?php echo $h(ini_get('upload_max_filesize')); ?></strong> (post <?php echo $h(ini_get('post_max_size')); ?>).</div>
          </div>

          <div>
            <label class="ps-label">Allowed Video Formats</label>
            <div class="ps-formats">
              <?php foreach ($videoFormatsAll as $fk => $fl): ?>
                <label class="ps-fmt">
                  <input type="checkbox" name="video_formats[]" value="<?php echo $h($fk); ?>"<?php echo in_array($fk, $allowedFormats, true) ? ' checked' : ''; ?>>
                  <span class="ps-fmt-label"><?php echo $h(strtoupper($fk)); ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Registration & Community -->
        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-people-fill"></i></div>
            <div><h2>Registration &amp; Community</h2><p>Manage how users join and interact.</p></div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="registration_mode">Registration Mode</label>
            <select class="ps-select" id="registration_mode" name="registration_mode">
              <option value="open"<?php echo $registrationMode === 'open' ? ' selected' : ''; ?>>Open Registration</option>
              <option value="approval"<?php echo $registrationMode === 'approval' ? ' selected' : ''; ?>>Approval Required</option>
              <option value="closed"<?php echo $registrationMode === 'closed' ? ' selected' : ''; ?>>Closed</option>
            </select>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Comments Enabled</div></div>
            <label class="ps-toggle"><input type="checkbox" name="comments_enabled" value="1"<?php echo $commentsEnabled ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Comment Approval</div></div>
            <label class="ps-toggle"><input type="checkbox" name="comments_require_approval" value="1"<?php echo $commentsRequireApproval ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Require Login to Comment</div></div>
            <label class="ps-toggle"><input type="checkbox" name="comments_require_login" value="1"<?php echo $commentsRequireLogin ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
        </div>

        <!-- Appearance & Region -->
        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-globe2"></i></div>
            <div><h2>Appearance &amp; Region</h2><p>Adjust the look and regional settings.</p></div>
          </div>
          <div class="ps-row3">
            <div>
              <label class="ps-label" for="brand_color">Brand Color</label>
              <input class="ps-input" type="color" id="brand_color" name="brand_color" value="<?php echo $h($brandColor); ?>" style="height:86px;padding:6px;cursor:pointer">
              <div class="ps-hint" id="ps-color-hex" style="font-weight:700"><?php echo strtoupper($h($brandColor)); ?></div>
            </div>
            <div>
              <label class="ps-label" for="ps-country-sel">Timezone</label>
              <select class="ps-select" id="ps-country-sel" data-selected="<?php echo $h($selectedCountry); ?>"></select>
            </div>
            <div>
              <label class="ps-label" for="site_fixed_date">Date Format</label>
              <select class="ps-select" id="ps-date-format-sel">
                <?php foreach ($dateFormats as $dfKey => $dfLabel): ?>
                  <option value="<?php echo $h($dfKey); ?>"<?php echo $dateFormat === $dfKey ? ' selected' : ''; ?>><?php echo $h($dfLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="ps-mb" style="margin-top:12px">
            <label class="ps-label" for="site_fixed_date">Site Date</label>
            <input class="ps-input" type="date" id="site_fixed_date" name="site_fixed_date" value="<?php echo $h($siteFixedDate); ?>" required>
            <div class="ps-hint">Used in place of today's date throughout the site.</div>
          </div>
        </div>

        <!-- Security -->
        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-shield-fill"></i></div>
            <div><h2>Security</h2><p>Keep your platform secure.</p></div>
          </div>
          <div class="ps-row3">
            <div>
              <label class="ps-label" for="login_max_attempts">Login Attempts</label>
              <input class="ps-input" type="number" id="login_max_attempts" name="login_max_attempts" min="3" max="20" value="<?php echo (int)$loginMaxAttempts; ?>" required>
            </div>
            <div>
              <label class="ps-label" for="login_lockout_minutes">Lockout Duration</label>
              <select class="ps-select" id="login_lockout_minutes" name="login_lockout_minutes">
                <?php foreach ([5=>5,10=>10,15=>15,30=>30,60=>60,120=>120,1440=>1440] as $m => $_): ?>
                  <option value="<?php echo $m; ?>"<?php echo (int)$loginLockoutMinutes === $m ? ' selected' : ''; ?>><?php echo $m < 60 ? $m . ' Minutes' : ($m/60 . ' Hour' . ($m > 60 ? 's' : '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="ps-label" for="admin_session_minutes">Admin Session</label>
              <select class="ps-select" id="admin_session_minutes" name="admin_session_minutes">
                <?php foreach ([30=>30,60=>60,120=>120,480=>480,1440=>1440,10080=>10080] as $m => $_): ?>
                  <option value="<?php echo $m; ?>"<?php echo (int)$adminSessionMinutes === $m ? ' selected' : ''; ?>><?php echo $m < 60 ? $m . ' Min' : ($m < 1440 ? $m/60 . ' Hours' : ($m/1440) . ' Day' . ($m > 1440 ? 's' : '')); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="ps-hint">Failed sign-ins before lockout. Admins are signed out after the session time of inactivity.</div>
        </div>

      </div><!-- /ps-grid -->
    </div><!-- /tab-general -->

    <!-- ══════════════════ UPLOADS ══════════════════ -->
    <div class="ps-panel" id="tab-uploads">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-film"></i></div>
            <div><h2>Upload Rules</h2><p>Fine-tune per-user and format constraints.</p></div>
          </div>
          <div class="ps-row">
            <div>
              <label class="ps-label" for="max_short_seconds">Longest Short (seconds)</label>
              <input class="ps-input" type="number" id="max_short_seconds" name="max_short_seconds" min="10" max="600" value="<?php echo (int)$maxShortSeconds; ?>" required>
            </div>
            <div>
              <label class="ps-label" for="daily_upload_limit">Uploads / User / Day</label>
              <input class="ps-input" type="number" id="daily_upload_limit" name="daily_upload_limit" min="0" max="1000" value="<?php echo (int)$dailyUploadLimit; ?>" required>
            </div>
          </div>
          <div class="ps-hint">Shorts: 10–600 s. Daily limit: 0 = no limit.</div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-activity"></i></div>
            <div><h2>Brain Meter</h2><p>Watch-time health thresholds.</p></div>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Show the Brain Meter</div></div>
            <label class="ps-toggle"><input type="checkbox" name="brain_enabled" value="1"<?php echo !empty($brainMeter['enabled']) ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <hr class="ps-divider">
          <div class="ps-row">
            <div><label class="ps-label" for="brain_dizzy_start">Dizzy at</label><input class="ps-input" type="number" id="brain_dizzy_start" name="brain_dizzy_start" min="1" max="100000" value="<?php echo (int)$brainMeter['dizzy_start']; ?>" required></div>
            <div><label class="ps-label" for="brain_dry_start">Drying at</label><input class="ps-input" type="number" id="brain_dry_start" name="brain_dry_start" min="2" max="100000" value="<?php echo (int)$brainMeter['dry_start']; ?>" required></div>
            <div><label class="ps-label" for="brain_ash_start">Dust at</label><input class="ps-input" type="number" id="brain_ash_start" name="brain_ash_start" min="3" max="10000000" value="<?php echo (int)$brainMeter['ash_start']; ?>" required></div>
            <div><label class="ps-label" for="brain_grave_start">Grave at</label><input class="ps-input" type="number" id="brain_grave_start" name="brain_grave_start" min="4" max="100000000" value="<?php echo (int)$brainMeter['grave_start']; ?>" required></div>
            <div><label class="ps-label" for="brain_reset_hour">New day at (hour)</label><input class="ps-input" type="number" id="brain_reset_hour" name="brain_reset_hour" min="0" max="23" value="<?php echo (int)$brainMeter['reset_hour']; ?>" required></div>
            <div><label class="ps-label" for="brain_heal_minutes">Break (minutes)</label><input class="ps-input" type="number" id="brain_heal_minutes" name="brain_heal_minutes" min="0" max="1440" value="<?php echo (int)$brainMeter['heal_minutes']; ?>" required></div>
            <div>
              <label class="ps-label" for="brain_heal_amount">Healing Amount</label>
              <input class="ps-input" type="number" id="brain_heal_amount" name="brain_heal_amount" min="0" max="1000" step="1" value="<?php echo (int)$brainMeter['heal_amount']; ?>" required>
              <div class="ps-hint">Whole number from 0 to 1000.</div>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ USERS & ACCESS ══════════════════ -->
    <div class="ps-panel" id="tab-users">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-person-check-fill"></i></div>
            <div><h2>Contact &amp; Legal Links</h2><p>Footer links for support, terms and privacy.</p></div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="support_email">Support Email</label>
            <input class="ps-input" type="email" id="support_email" name="support_email" value="<?php echo $h($supportEmail); ?>" maxlength="120" placeholder="support@example.com">
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="terms_url">Terms of Service URL</label>
            <input class="ps-input" type="url" id="terms_url" name="terms_url" value="<?php echo $h($termsUrl); ?>" maxlength="255" placeholder="https://example.com/terms">
          </div>
          <div>
            <label class="ps-label" for="privacy_url">Privacy Policy URL</label>
            <input class="ps-input" type="url" id="privacy_url" name="privacy_url" value="<?php echo $h($privacyUrl); ?>" maxlength="255" placeholder="https://example.com/privacy">
            <div class="ps-hint">Leave empty to hide in the footer.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-megaphone-fill"></i></div>
            <div><h2>Announcement Banner</h2><p>Show a banner at the top of the site.</p></div>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Enable Banner</div></div>
            <label class="ps-toggle"><input type="checkbox" name="announcement_enabled" value="1"<?php echo $announcementEnabled ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <hr class="ps-divider">
          <div class="ps-mb">
            <label class="ps-label" for="announcement_message">Message</label>
            <textarea class="ps-textarea" id="announcement_message" name="announcement_message" rows="2" maxlength="200" placeholder="Enter announcement text…"><?php echo $h($announcementMessage); ?></textarea>
          </div>
          <div>
            <label class="ps-label" for="announcement_expires">Expiry Date</label>
            <input class="ps-input" type="date" id="announcement_expires" name="announcement_expires" value="<?php echo $h($announcementExpires); ?>">
            <div class="ps-hint">The banner hides after this date. Leave empty for no expiry.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-grid-fill"></i></div>
            <div><h2>Categories</h2><p>Organise content into categories.</p></div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="categories">Video Categories</label>
            <textarea class="ps-textarea" id="categories" name="categories" rows="3" placeholder="Gaming, Music, Education"><?php echo $h($categoriesText); ?></textarea>
            <div class="ps-hint">One per line or comma-separated, up to 50.</div>
          </div>
          <div class="ps-cat-chips">
            <?php foreach ($categoriesWithColors as $cat): ?>
              <label class="ps-cat-chip">
                <input type="color" name="cat_color[<?php echo $h($cat['name']); ?>]" value="<?php echo $h($cat['color']); ?>">
                <span><?php echo $h($cat['name']); ?></span>
              </label>
            <?php endforeach; ?>
            <a class="ps-btn ps-btn-outline ps-btn-sm" href="categories.php"><i class="bi bi-plus-lg"></i>Manage</a>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-grid-3x3-gap-fill"></i></div>
            <div><h2>Feed</h2><p>Control how videos appear on the home feed.</p></div>
          </div>
          <div class="ps-row">
            <div>
              <label class="ps-label" for="feed_items_per_page">Videos per Page</label>
              <input class="ps-input" type="number" id="feed_items_per_page" name="feed_items_per_page" min="6" max="100" value="<?php echo (int)$feedItemsPerPage; ?>" required>
            </div>
            <div>
              <label class="ps-label" for="feed_default_sort">Home Feed Order</label>
              <select class="ps-select" id="feed_default_sort" name="feed_default_sort">
                <option value="newest"<?php echo $feedDefaultSort === 'newest' ? ' selected' : ''; ?>>Newest First</option>
                <option value="trending"<?php echo $feedDefaultSort === 'trending' ? ' selected' : ''; ?>>Trending</option>
              </select>
            </div>
          </div>
          <div class="ps-hint">6–100 videos per page.</div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ MODERATION ══════════════════ -->
    <div class="ps-panel" id="tab-mod">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-chat-dots-fill"></i></div>
            <div><h2>Comments &amp; Banned Words</h2><p>Keep discussions healthy.</p></div>
          </div>
          <div>
            <label class="ps-label" for="banned_words">Banned Words</label>
            <textarea class="ps-textarea" id="banned_words" name="banned_words" rows="5"><?php echo $h($bannedWordsText); ?></textarea>
            <div class="ps-hint">One per line. Up to 200 words. Case-insensitive whole-word match.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-slash-circle-fill"></i></div>
            <div><h2>Blocked List</h2><p>Block email domains and IP addresses.</p></div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="blocked_email_domains">Blocked Email Domains</label>
            <textarea class="ps-textarea" id="blocked_email_domains" name="blocked_email_domains" rows="3" placeholder="mailinator.com"><?php echo $h($blockedDomainsText); ?></textarea>
            <div class="ps-hint">One per line. Sign-ups from these domains are refused.</div>
          </div>
          <div>
            <label class="ps-label" for="blocked_ips">Blocked IP Addresses</label>
            <textarea class="ps-textarea" id="blocked_ips" name="blocked_ips" rows="3" placeholder="203.0.113.7&#10;198.51.100.0/24"><?php echo $h($blockedIpsText); ?></textarea>
            <div class="ps-hint">One per line. IPv4 ranges accepted (e.g. 198.51.100.0/24). Yours: <strong><?php echo $h($yourIp); ?></strong>.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-exclamation-octagon-fill"></i></div>
            <div><h2>Mature Content (18+)</h2><p>Gate adult content behind a warning.</p></div>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Allow 18+ Videos</div></div>
            <label class="ps-toggle"><input type="checkbox" name="mature_content_enabled" value="1"<?php echo $matureEnabled ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <hr class="ps-divider">
          <div>
            <label class="ps-label" for="mature_warning_text">Warning Text</label>
            <textarea class="ps-textarea" id="mature_warning_text" name="mature_warning_text" rows="3" maxlength="200" placeholder="This video may contain content for adults only (18+). Do you want to continue?"><?php echo $h($matureText); ?></textarea>
            <div class="ps-hint">Shown before an 18+ video plays.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-c-circle-fill"></i></div>
            <div><h2>Copyright Contact</h2><p>DMCA takedown information.</p></div>
          </div>
          <div class="ps-mb">
            <label class="ps-label" for="copyright_email">Takedown Email</label>
            <input class="ps-input" type="email" id="copyright_email" name="copyright_email" value="<?php echo $h($copyrightEmail); ?>" maxlength="120" placeholder="copyright@example.com">
          </div>
          <div>
            <label class="ps-label" for="copyright_notice">Copyright Notice</label>
            <textarea class="ps-textarea" id="copyright_notice" name="copyright_notice" rows="3" maxlength="300"><?php echo $h($copyrightNotice); ?></textarea>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ APPEARANCE ══════════════════ -->
    <div class="ps-panel" id="tab-appear">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-share-fill"></i></div>
            <div><h2>Social Links</h2><p>Shown in the footer of the site.</p></div>
          </div>
          <?php foreach ($socialNetworks as $netKey => $netLabel): ?>
            <div class="ps-mb">
              <label class="ps-label" for="social_<?php echo $h($netKey); ?>"><?php echo $h($netLabel); ?></label>
              <input class="ps-input" type="url" id="social_<?php echo $h($netKey); ?>" name="social_<?php echo $h($netKey); ?>" value="<?php echo $h($socialLinksAll[$netKey] ?? ''); ?>" maxlength="255" placeholder="https://">
            </div>
          <?php endforeach; ?>
          <div class="ps-hint">Leave a field empty to hide that network.</div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-cookie"></i></div>
            <div><h2>Cookie Notice</h2><p>GDPR-friendly cookie consent banner.</p></div>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Show Cookie Notice</div></div>
            <label class="ps-toggle"><input type="checkbox" name="cookie_notice_enabled" value="1"<?php echo $cookieEnabled ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <hr class="ps-divider">
          <div>
            <label class="ps-label" for="cookie_notice_text">Notice Text</label>
            <textarea class="ps-textarea" id="cookie_notice_text" name="cookie_notice_text" rows="3" maxlength="200" placeholder="We use cookies to make this site work and to improve your experience."><?php echo $h($cookieText); ?></textarea>
            <div class="ps-hint">Accepted once per device. Links to your Privacy Policy if set.</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ SECURITY ══════════════════ -->
    <div class="ps-panel" id="tab-security">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-shield-lock-fill"></i></div>
            <div><h2>Admin Security</h2><p>Brute-force and session protection.</p></div>
          </div>
          <div class="ps-row3">
            <div>
              <label class="ps-label" for="ps-login-attempts">Failed Sign-ins</label>
              <input class="ps-input" type="number" id="ps-login-attempts" name="login_max_attempts" min="3" max="20" value="<?php echo (int)$loginMaxAttempts; ?>" required>
              <div class="ps-hint">Before lockout (3–20)</div>
            </div>
            <div>
              <label class="ps-label" for="ps-lockout-min">Lockout (min)</label>
              <input class="ps-input" type="number" id="ps-lockout-min" name="login_lockout_minutes" min="1" max="1440" value="<?php echo (int)$loginLockoutMinutes; ?>" required>
            </div>
            <div>
              <label class="ps-label" for="ps-session-min">Session (min)</label>
              <input class="ps-input" type="number" id="ps-session-min" name="admin_session_minutes" min="5" max="1440" value="<?php echo (int)$adminSessionMinutes; ?>" required>
            </div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-hdd-fill"></i></div>
            <div><h2>Server Health</h2><p>PHP and disk information.</p></div>
          </div>
          <div class="ps-health-row"><span>Free disk space</span><span class="ps-health-val <?php echo $diskLow ? 'text-warning' : ''; ?>"><?php echo $diskKnown ? $h(vs_format_bytes((int)$diskFree) . ' of ' . vs_format_bytes((int)$diskTotal)) : 'Unknown'; ?></span></div>
          <div class="ps-health-row"><span>PHP version</span><span class="ps-health-val"><?php echo $h(PHP_VERSION); ?></span></div>
          <div class="ps-health-row"><span>PHP upload limit</span><span class="ps-health-val"><?php echo $h(ini_get('upload_max_filesize')); ?> (post <?php echo $h(ini_get('post_max_size')); ?>)</span></div>
          <div class="ps-health-row"><span>Memory limit</span><span class="ps-health-val"><?php echo $h(ini_get('memory_limit')); ?></span></div>
          <div class="ps-health-row"><span>Max execution time</span><span class="ps-health-val"><?php echo $h(ini_get('max_execution_time')); ?> s</span></div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ SEO ══════════════════ -->
    <div class="ps-panel" id="tab-seo">
      <div class="ps-grid">

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-search"></i></div>
            <div><h2>SEO &amp; Sharing</h2><p>Search engine and social sharing settings.</p></div>
          </div>
          <div class="ps-switch-row">
            <div><div class="ps-switch-label">Allow Search Engines</div><div class="ps-switch-desc">Turn off to block Google via robots.txt.</div></div>
            <label class="ps-toggle"><input type="checkbox" name="allow_search_engines" value="1"<?php echo $allowSearchEngines ? ' checked' : ''; ?>><span class="ps-toggle-track"></span></label>
          </div>
          <hr class="ps-divider">
          <div class="ps-mb">
            <label class="ps-label" for="seo_keywords">Site Keywords</label>
            <input class="ps-input" type="text" id="seo_keywords" name="seo_keywords" value="<?php echo $h($seoKeywords); ?>" maxlength="200" placeholder="videos, shorts, music, gaming">
          </div>
          <div>
            <label class="ps-label" for="seo_share_image_url">Default Share Image URL</label>
            <input class="ps-input" type="url" id="seo_share_image_url" name="seo_share_image_url" value="<?php echo $h($seoImage); ?>" maxlength="255" placeholder="https://example.com/share.png">
            <div class="ps-hint">Shown when links are shared on social media.</div>
          </div>
        </div>

        <div class="ps-card">
          <div class="ps-card-head">
            <div class="ps-card-icon"><i class="bi bi-bar-chart-line-fill"></i></div>
            <div><h2>Analytics</h2><p>Connect Google Analytics.</p></div>
          </div>
          <div>
            <label class="ps-label" for="analytics_id">Google Analytics ID</label>
            <input class="ps-input" type="text" id="analytics_id" name="analytics_id" value="<?php echo $h($analyticsId); ?>" maxlength="20" placeholder="G-XXXXXXXXXX">
            <div class="ps-hint">Optional. Admin visits are not counted. Leave empty to disable.</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ══════════════════ STICKY SAVE BAR ══════════════════ -->
    <div class="ps-savebar" id="ps-savebar">
      <div class="ps-savebar-left hidden" id="ps-dirty-label">
        <i class="bi bi-exclamation-circle"></i><span>Unsaved changes</span>
      </div>
      <div class="ps-savebar-actions">
        <a class="ps-btn ps-btn-outline" href="?export=settings"><i class="bi bi-download"></i>Export Backup</a>
        <button class="ps-btn ps-btn-primary" type="submit"><i class="bi bi-floppy-fill"></i>Save Changes</button>
      </div>
    </div>

  </form>

  <!-- IMPORT / SYSTEM -->
  <div class="ps-bottom">
    <div class="ps-card">
      <div class="ps-card-head">
        <div class="ps-card-icon"><i class="bi bi-upload"></i></div>
        <div><h2>Import Settings <span style="font-size:.78rem;font-weight:400;opacity:.6">(Owner Only)</span></h2></div>
      </div>
      <p style="font-size:.85rem;color:var(--vs-muted);margin-bottom:12px">Restore a backup made with Export Settings. Logos are never imported.</p>
      <form method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center">
        <input type="hidden" name="csrf_token" value="<?php echo $h($_SESSION['vs_app_settings_csrf']); ?>">
        <input type="hidden" name="action" value="import_settings">
        <input class="ps-input" style="max-width:340px;flex:1" type="file" name="settings_file" accept=".json,application/json" required>
        <button class="ps-btn ps-btn-outline" type="submit" onclick="return confirm('Replace your current settings?')"><i class="bi bi-upload"></i>Import Settings</button>
      </form>
    </div>

    <div class="ps-card">
      <div class="ps-card-head">
        <div class="ps-card-icon"><i class="bi bi-download"></i></div>
        <div><h2>Export Settings <span style="font-size:.78rem;font-weight:400;opacity:.6">(Owner Only)</span></h2></div>
      </div>
      <p style="font-size:.85rem;color:var(--vs-muted);margin-bottom:12px">Download a JSON backup of your current settings. Last exported: <strong><?php echo $lastExported !== '' ? $h($lastExported) : 'never'; ?></strong>.</p>
      <a class="ps-btn ps-btn-outline" style="width:100%;justify-content:center" href="?export=settings"><i class="bi bi-download"></i>Export Settings</a>
    </div>
  </div>

</main>

<script>
(function () {
  /* ---- country → timezone ---- */
  var countryZones = <?php echo $countryZonesJson; ?>;
  var countrySelect = document.getElementById('ps-country-sel');
  var tzHidden = document.getElementById('ps-timezone-hidden');
  if (countrySelect && tzHidden) {
    var names; try { names = new Intl.DisplayNames(['en'], {type:'region'}); } catch(e) {}
    Object.keys(countryZones).sort(function(a,b){
      var la = a==='ZZ'?'zzz':(names&&names.of(a)||a);
      var lb = b==='ZZ'?'zzz':(names&&names.of(b)||b);
      return la.localeCompare(lb);
    }).forEach(function(code) {
      var o = document.createElement('option');
      o.value = code;
      o.textContent = code==='ZZ' ? 'Other / UTC' : ((names&&names.of(code))||code);
      countrySelect.appendChild(o);
    });
    countrySelect.value = countrySelect.getAttribute('data-selected') || '';
    countrySelect.addEventListener('change', function() {
      tzHidden.value = countryZones[countrySelect.value] || 'UTC';
    });
  }

  /* ---- date format select ---- */
  var dfSel = document.getElementById('ps-date-format-sel');
  var dfHid = document.getElementById('ps-date-format-hidden');
  if (dfSel && dfHid) {
    dfSel.addEventListener('change', function(){ dfHid.value = dfSel.value; });
  }

  /* ---- brand colour preview ---- */
  var colorInput = document.getElementById('brand_color');
  var colorHex   = document.getElementById('ps-color-hex');
  if (colorInput && colorHex) {
    colorInput.addEventListener('input', function() { colorHex.textContent = colorInput.value.toUpperCase(); });
  }

  /* ---- description counter ---- */
  var descArea = document.getElementById('app_description');
  var descCnt  = document.getElementById('ps-desc-counter');
  if (descArea && descCnt) {
    var updateDescCount = function() { descCnt.textContent = descArea.value.length + ' / 180'; };
    descArea.addEventListener('input', updateDescCount);
    updateDescCount();
  }

  /* ---- tab switching ---- */
  var tabBtns  = document.querySelectorAll('#ps-tabs .ps-tab');
  var tabPanelMap = { general:'general', uploads:'uploads', users:'users', mod:'mod', appear:'appear', security:'security', seo:'seo' };
  function activateTab(tabKey) {
    tabBtns.forEach(function(b) { b.classList.toggle('active', b.getAttribute('data-tab') === tabKey); });
    Object.keys(tabPanelMap).forEach(function(k) {
      var panel = document.getElementById('tab-' + tabPanelMap[k]);
      if (panel) panel.classList.toggle('active', k === tabKey);
    });
    try { sessionStorage.setItem('vs-ps-tab', tabKey); } catch(e) {}
  }
  tabBtns.forEach(function(b) {
    b.addEventListener('click', function() { activateTab(b.getAttribute('data-tab')); });
  });
  try {
    var saved = sessionStorage.getItem('vs-ps-tab');
    if (saved && document.getElementById('tab-' + tabPanelMap[saved])) activateTab(saved);
  } catch(e) {}

  /* ---- unsaved changes ---- */
  var form = document.getElementById('ps-form');
  var dirtyLabel = document.getElementById('ps-dirty-label');
  var dirty = false;
  if (form) {
    form.addEventListener('input',  function() { dirty=true; if(dirtyLabel) dirtyLabel.classList.remove('hidden'); });
    form.addEventListener('change', function() { dirty=true; if(dirtyLabel) dirtyLabel.classList.remove('hidden'); });
    form.addEventListener('submit', function() { dirty=false; });
  }
  document.querySelectorAll('a[href*="export=settings"]').forEach(function(a){
    a.addEventListener('click',function(){ var was=dirty; dirty=false; setTimeout(function(){ dirty=was; },1500); });
  });
  window.addEventListener('beforeunload', function(e){ if(dirty){ e.preventDefault(); e.returnValue=''; } });

  /* ---- AJAX logo change ---- */
  var logoBtn   = document.getElementById('ps-logo-btn');
  var logoInput = document.getElementById('ps-logo-input');
  var logoImg   = document.getElementById('ps-logo-img');
  var logoMsg   = document.getElementById('ps-logo-msg');
  var csrfField = form && form.querySelector('input[name="csrf_token"]');
  if (logoBtn && logoInput && logoImg && csrfField) {
    function setLogoMsg(text, color) { if (logoMsg) { logoMsg.textContent=text; logoMsg.style.color=color||''; } }
    ['input','change'].forEach(function(t){ logoInput.addEventListener(t, function(e){ e.stopPropagation(); }); });
    logoBtn.addEventListener('click', function(){ logoInput.click(); });
    logoInput.addEventListener('change', function(){
      var file = logoInput.files && logoInput.files[0];
      if (!file) return;
      if (file.type !== 'image/png') { setLogoMsg('The logo must be a PNG image.','#f87171'); logoInput.value=''; return; }
      if (file.size > 4*1024*1024) { setLogoMsg('Must be under 4 MB.','#f87171'); logoInput.value=''; return; }
      var fd = new FormData();
      fd.append('action','change_logo'); fd.append('csrf_token',csrfField.value); fd.append('logo',file);
      logoBtn.disabled=true; setLogoMsg('Uploading…','#9a9aaa');
      fetch(location.pathname+location.search,{method:'POST',body:fd,credentials:'same-origin',headers:{Accept:'application/json'}})
        .then(function(r){ return r.json().catch(function(){ return {ok:false,message:'Unexpected response.'}; }); })
        .then(function(d){
          if (d&&d.ok&&d.url) {
            logoImg.src = d.url+'?v='+Date.now();
            setLogoMsg('Logo updated.','#4ade80');
            setTimeout(function(){ setLogoMsg('PNG · square · 128–2048 px · <4 MB',''); },3500);
          } else { setLogoMsg((d&&d.message)||'Upload failed.','#f87171'); }
        })
        .catch(function(){ setLogoMsg('Could not reach server.','#f87171'); })
        .then(function(){ logoBtn.disabled=false; logoInput.value=''; });
    });
  }
})();
</script>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body>
</html>