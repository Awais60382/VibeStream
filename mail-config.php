<?php
/*
 * VibeStream - e-mail settings for password-reset and verification e-mails.
 * Fill in the 6 values below, save, and keep this file private (never send it to anyone).
 *
 * For Gmail: use the same Gmail address and "app password" you used on the admin System check page
 * (Google account > Security > 2-Step Verification > App passwords). Use port 587.
 */
define('VS_MAIL_HOST', 'smtp.gmail.com');
define('VS_MAIL_PORT', 587);                    // 587 = STARTTLS (Gmail), 465 = SSL
define('VS_MAIL_USER', '');                     // your full Gmail address
define('VS_MAIL_PASS', '');                     // the 16-letter app password (no spaces)
define('VS_MAIL_FROM', '');                     // leave empty to use VS_MAIL_USER
define('VS_MAIL_FROM_NAME', 'VibeStream');

// The address of your site WITHOUT a slash at the end, as people type it in the browser.
// Used inside the e-mail links. Local XAMPP: http://localhost/VibeStream
define('VS_SITE_URL', 'http://localhost/VibeStream');

// Only set this to false for local testing if the e-mail fails with a certificate error.
define('VS_MAIL_VERIFY_TLS', true);
