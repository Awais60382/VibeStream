<?php
/**
 * Starts a card payment: creates a Stripe Checkout session and sends the buyer to Stripe's secure page.
 * Called by the Credit card tab on premium-checkout.php. Put this file in the VibeStream main folder.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/stripe-lib.php';

function vs_stripe_fail(string $msg, string $plan = ''): void
{
    http_response_code(400);
    $back = $plan !== '' ? 'premium-checkout.php?plan=' . urlencode($plan) : 'premium.php';
    echo '<!DOCTYPE html><meta charset="utf-8"><body style="font-family:system-ui;background:#111;color:#eee;padding:40px"><h2>Card payment</h2><p>'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p><p><a style="color:#f9433f" href="' . $back . '">Go back</a></p></body>';
    exit;
}

$me = trim((string)($_SESSION['username'] ?? ''));
if ($me === '') {
    header('Location: login.php');
    exit;
}
$planKey = (string)($_POST['plan'] ?? '');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals((string)($_SESSION['premium_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
    vs_stripe_fail('Session expired. Go back and try again.', $planKey);
}
if (!isset(VS_PREMIUM_PLANS[$planKey])) {
    vs_stripe_fail('Unknown plan.');
}
if (!vs_stripe_ready()) {
    vs_stripe_fail('Card payments are not set up yet (no Stripe key in stripe-config.php, or PHP curl is off).', $planKey);
}
$pdo = vs_premium_pdo();
if (!$pdo) {
    vs_stripe_fail('No database connection.', $planKey);
}
vs_premium_ensure($pdo);
if (vs_premium_is_admin($me)) {
    vs_stripe_fail('Admins always have Premium. Sign in with a normal account to buy.', $planKey);
}
$cur = vs_premium_get($pdo, $me);
if (vs_premium_is_active($cur)) {
    $isPlusNow = strpos((string)$cur['plan'], 'plus') === 0;
    $wantsPlus = strpos($planKey, 'plus') === 0;
    if ($isPlusNow || !$wantsPlus) {
        vs_stripe_fail($isPlusNow ? 'You already have Premium Plus.' : 'You already have Premium.', $planKey);
    }
}

$plan = VS_PREMIUM_PLANS[$planKey];
$tier = $plan['tier'] === 'plus' ? 'Premium Plus' : 'Premium';
$base = vs_stripe_base_url();
try {
    $s = vs_stripe_call('POST', 'checkout/sessions', [
        'mode' => 'payment',
        'success_url' => $base . '/premium-stripe-return.php?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $base . '/premium-checkout.php?plan=' . urlencode($planKey),
        'client_reference_id' => $me,
        'line_items' => [[
            'quantity' => 1,
            'price_data' => [
                'currency' => VS_STRIPE_CURRENCY,
                'unit_amount' => (int)round($plan['price'] * 100),
                'product_data' => ['name' => 'VibeStream ' . $tier . ' ' . preg_replace('/^Plus /', '', (string)$plan['label'])],
            ],
        ]],
        'metadata' => ['username' => $me, 'plan' => $planKey],
    ]);
} catch (Throwable $e) {
    vs_stripe_fail('Stripe said: ' . $e->getMessage(), $planKey);
}
header('Location: ' . $s['url'], true, 303);
exit;