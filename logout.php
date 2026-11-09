<?php
/**
 * VibeStream - sign out.
 * Works with the existing "logout.php" links, but refuses requests that come from another website,
 * so a stranger's page cannot log your visitors out. A POST with the csrf_token also works.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store');

$site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
$crossSite = $site === 'cross-site';
if ($site === '') {
    // Older browsers send no Sec-Fetch-Site: fall back to comparing the Referer host with ours.
    $ref = parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
    $me = parse_url('//' . (string)($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $crossSite = is_string($ref) && $ref !== '' && is_string($me) && strcasecmp($ref, $me) !== 0;
}
if ($crossSite) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/adminpanel/checkin-lib.php';
if (function_exists('vs_checkin_mark_offline')) {
    vs_checkin_mark_offline();
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}
session_destroy();
header('Location: login.php');
exit;