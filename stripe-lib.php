<?php
/**
 * VibeStream - Stripe helpers (no Stripe library needed, only PHP's curl).
 * Put this file in the VibeStream main folder, next to stripe-config.php.
 */
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/premium-store.php';

function vs_stripe_ready(): bool
{
    return VS_STRIPE_SECRET_KEY !== '' && strpos(VS_STRIPE_SECRET_KEY, 'sk_') === 0 && function_exists('curl_init');
}

/** Calls the Stripe API. $method GET or POST. Returns the decoded JSON or throws. */
function vs_stripe_call(string $method, string $path, array $params = []): array
{
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => VS_STRIPE_SECRET_KEY . ':',
        CURLOPT_TIMEOUT        => 25,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('Could not reach Stripe: ' . $err);
    }
    $j = json_decode((string)$body, true);
    if (!is_array($j)) {
        throw new RuntimeException('Unexpected answer from Stripe.');
    }
    if (isset($j['error'])) {
        throw new RuntimeException((string)($j['error']['message'] ?? 'Stripe error.'));
    }
    return $j;
}

/** The address of the VibeStream folder, e.g. http://localhost/VibeStream */
function vs_stripe_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

/**
 * Turns a PAID Stripe Checkout session into Premium. Safe to call many times (the payment is recorded once).
 * Returns null on success (or already done), otherwise a short error message.
 */
function vs_stripe_fulfil(PDO $pdo, array $s): ?string
{
    if (($s['payment_status'] ?? '') !== 'paid') {
        return 'The payment is not completed yet.';
    }
    $username = trim((string)($s['metadata']['username'] ?? ''));
    $plan = (string)($s['metadata']['plan'] ?? '');
    $def = VS_PREMIUM_PLANS[$plan] ?? null;
    if ($username === '' || $def === null) {
        return 'This payment does not match a Premium plan.';
    }
    // The amount must match our own price list, so nobody can pay less for a bigger plan.
    if ((int)($s['amount_total'] ?? -1) !== (int)round($def['price'] * 100) || strtolower((string)($s['currency'] ?? '')) !== VS_STRIPE_CURRENCY) {
        return 'The amount paid does not match the plan price. Contact support with your receipt.';
    }
    $txn = 'stripe:' . (string)($s['payment_intent'] ?? $s['id'] ?? '');
    vs_premium_ensure($pdo);
    vs_premium_confirm_payment($pdo, $username, $plan, $txn, (float)$def['price']);   // false = already recorded, fine
    return null;
}