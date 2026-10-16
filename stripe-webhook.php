<?php
/**
 * Stripe calls this by itself after a payment, even if the buyer closes the browser early.
 * Needs the signing secret (whsec_...) in stripe-config.php and a public address, so it is optional on localhost.
 * Put this file in the VibeStream main folder.
 */
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/stripe-lib.php';

$payload = (string)file_get_contents('php://input');
$header = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (VS_STRIPE_WEBHOOK_SECRET === '') {
    http_response_code(503);
    exit('Webhook secret not set.');
}
$t = 0;
$sigs = [];
foreach (explode(',', $header) as $part) {
    $kv = explode('=', trim($part), 2);
    if (count($kv) === 2 && $kv[0] === 't') { $t = (int)$kv[1]; }
    if (count($kv) === 2 && $kv[0] === 'v1') { $sigs[] = $kv[1]; }
}
$expected = hash_hmac('sha256', $t . '.' . $payload, VS_STRIPE_WEBHOOK_SECRET);
$valid = $t > 0 && abs(time() - $t) <= 300;
$match = false;
foreach ($sigs as $sig) {
    $match = $match || hash_equals($expected, $sig);
}
if (!$valid || !$match) {
    http_response_code(400);
    exit('Bad signature.');
}
$event = json_decode($payload, true);
$type = (string)($event['type'] ?? '');
if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
    $pdo = vs_premium_pdo();
    if ($pdo) {
        try {
            vs_premium_ensure($pdo);
            vs_stripe_fulfil($pdo, (array)($event['data']['object'] ?? []));
        } catch (Throwable $e) {
            error_log('stripe-webhook: ' . $e->getMessage());
            http_response_code(500);
            exit;
        }
    }
}
http_response_code(200);
echo 'ok';