<?php
/**
 * VibeStream - Stripe keys. Put this file in the VibeStream main folder.
 * Configure these as server environment variables (preferred):
 *   STRIPE_SECRET_KEY=sk_test_...       (use a test key first)
 *   STRIPE_WEBHOOK_SECRET=whsec_...     (optional for localhost, required in production)
 *   VIBESTREAM_BASE_URL=https://your-domain.example/VibeStream
 * For local-only testing, configure these in the active Apache VirtualHost
 * and restart Apache; the checkout page shows the exact SetEnv directives.
 * XAMPP: add SetEnv STRIPE_SECRET_KEY "sk_test_..." and
 * SetEnv VIBESTREAM_BASE_URL "http://localhost/VibeStream" inside the active
 * VirtualHost in Apache's httpd-vhosts.conf, then restart Apache. Never commit
 * real keys. For reliable fulfilment if the buyer closes Checkout, configure
 * Stripe webhook https://YOUR-DOMAIN/stripe-webhook.php for checkout.session.completed
 * and set STRIPE_WEBHOOK_SECRET to that endpoint's signing secret.
 * Never share or publish this file, and never put secret keys in browser JavaScript.
 */
$stripeSecret = trim((string)(getenv('STRIPE_SECRET_KEY') ?: ''));
$stripeWebhook = trim((string)(getenv('STRIPE_WEBHOOK_SECRET') ?: ''));
$stripeBaseUrl = trim((string)(getenv('VIBESTREAM_BASE_URL') ?: ''));

if ($stripeSecret === '') {
    $stripeSecret = ''; // Local fallback: 'sk_test_...'
}
if ($stripeWebhook === '') {
    $stripeWebhook = ''; // Local fallback: 'whsec_...'
}

if (!defined('VS_STRIPE_SECRET_KEY')) {
    define('VS_STRIPE_SECRET_KEY', $stripeSecret);
}
if (!defined('VS_STRIPE_WEBHOOK_SECRET')) {
    define('VS_STRIPE_WEBHOOK_SECRET', $stripeWebhook);
}
if (!defined('VS_STRIPE_CURRENCY')) {
    define('VS_STRIPE_CURRENCY', 'usd');
}
if (!defined('VS_STRIPE_BASE_URL')) {
    define('VS_STRIPE_BASE_URL', $stripeBaseUrl);
}