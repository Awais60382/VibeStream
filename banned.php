<?php
// banned.php - the "you have been banned" card (reason + time until unbanned).
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/ban-guard.php';
require_once __DIR__ . '/site-branding.php';

// A signed-in admin/owner/CEO (or any fresh login) must never see a stale ban card from this browser.
if (vs_bg_is_privileged_session() || vs_bg_session_logged_in()) {
    unset($_SESSION['vs_ban_card']);
    header('Location: index.php');   // ban-guard re-checks the account on the next page
    exit;
}

$ban = $_SESSION['vs_ban_card'] ?? null;
if (!is_array($ban)) {
    header('Location: login.php');
    exit;
}
if (empty($ban['permanent']) && (int)($ban['until'] ?? 0) <= time()) {
    unset($_SESSION['vs_ban_card']);
    session_regenerate_id(true);
    header('Location: login.php');
    exit;
}
http_response_code(403);
header('Cache-Control: no-store');
echo vs_bg_card_html($ban);