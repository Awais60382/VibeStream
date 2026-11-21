<?php
/**
 * Google sign-in settings (Google Identity Services button on login.php).
 * This flow needs only the client ID - no client secret.
 *
 * Turn it on either with the GOOGLE_OAUTH_ENABLED=1 environment variable,
 * or (easier on XAMPP) with google-oauth.local.php next to this file.
 */
function vs_google_local_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = [];
        $local = __DIR__ . '/google-oauth.local.php';
        if (is_file($local)) {
            $values = require $local;
            if (is_array($values)) {
                $cfg = $values;
            }
        }
    }
    return $cfg;
}

if (!defined('GOOGLE_OAUTH_CLIENT_ID')) {
    define(
        'GOOGLE_OAUTH_CLIENT_ID',
        getenv('GOOGLE_OAUTH_CLIENT_ID')
            ?: (string)(vs_google_local_config()['client_id'] ?? '778247495161-37fq631q2nm026vm5ofn1qj79vib9au4.apps.googleusercontent.com')
    );
}

if (!defined('GOOGLE_OAUTH_ENABLED')) {
    define(
        'GOOGLE_OAUTH_ENABLED',
        getenv('GOOGLE_OAUTH_ENABLED') === '1' || !empty(vs_google_local_config()['enabled'])
    );
}