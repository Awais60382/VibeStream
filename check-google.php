<?php
// Temporary helper: open http://localhost/VibeStream/check-google.php, then DELETE this file.
header('Content-Type: text/plain; charset=utf-8');
$dir = __DIR__;
echo "Folder: $dir\n\n";
foreach (['google-oauth.php', 'google-oauth.local.php', 'code.php', 'login.php'] as $f) {
    $p = $dir . '/' . $f;
    echo str_pad($f, 24) . (is_file($p) ? 'found, modified ' . date('Y-m-d H:i:s', filemtime($p)) : 'MISSING') . "\n";
}
echo "\n";
$local = $dir . '/google-oauth.local.php';
if (is_file($local)) {
    $v = require $local;
    echo "local file returns: " . json_encode($v) . "\n";
    echo "has 'enabled' => true ? " . (!empty($v['enabled']) ? 'YES' : 'NO  <-- this is the problem, replace the file') . "\n";
}
if (function_exists('opcache_reset')) { @opcache_reset(); }
require_once $dir . '/google-oauth.php';
echo "\nGOOGLE_OAUTH_ENABLED  = " . (GOOGLE_OAUTH_ENABLED ? 'true' : 'false') . "\n";
echo "GOOGLE_OAUTH_CLIENT_ID = " . (GOOGLE_OAUTH_CLIENT_ID !== '' ? 'set' : 'EMPTY') . "\n";
echo "curl extension        = " . (function_exists('curl_init') ? 'on' : 'OFF (enable extension=curl in php.ini)') . "\n";
