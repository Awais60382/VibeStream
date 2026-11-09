<?php
/**
 * VibeStream - Stripe helpers (no Stripe library needed, only PHP's curl).
 * Put this file in the VibeStream main folder, next to stripe-config.php.
 */
require_once __DIR__ . '/stripe-config.php';
require_once __DIR__ . '/premium-store.php';

function vs_stripe_ready(): bool
{
    return preg_match('/^sk_(?:test|live)_[A-Za-z0-9]+$/', VS_STRIPE_SECRET_KEY) === 1
        && preg_match('/^[a-z]{3}$/', VS_STRIPE_CURRENCY) === 1
        && function_exists('curl_init');
}

/** Calls the Stripe API. $method GET or POST. Returns the decoded JSON or throws. */
function vs_stripe_call(string $method, string $path, array $params = []): array
{
    if (!vs_stripe_ready()) {
        throw new RuntimeException('Stripe is not configured. Set STRIPE_SECRET_KEY on the server.');
    }
    if (!in_array($method, ['GET', 'POST'], true)) {
        throw new InvalidArgumentException('Unsupported Stripe request method.');
    }
    if (!preg_match('~^[A-Za-z0-9_/-]+$~', $path)) {
        throw new InvalidArgumentException('Invalid Stripe API path.');
    }
    $ch = curl_init('https://api.stripe.com/v1/' . ltrim($path, '/'));
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the Stripe connection.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => VS_STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    }
    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        error_log('Stripe API connection failed: ' . $err);
        throw new RuntimeException('Could not reach the payment provider. Please try again.');
    }
    $j = json_decode((string)$body, true);
    if (!is_array($j)) {
        error_log('Stripe API returned a non-JSON response (HTTP ' . $status . ').');
        throw new RuntimeException('Unexpected answer from Stripe.');
    }
    if (isset($j['error'])) {
        $message = (string)($j['error']['message'] ?? 'Stripe error.');
        error_log('Stripe API returned HTTP ' . $status . ': ' . $message);
        throw new RuntimeException('The payment provider could not start checkout. Please check the Stripe account configuration and try again.');
    }
    if ($status < 200 || $status >= 300) {
        error_log('Stripe API returned unexpected HTTP status ' . $status . '.');
        throw new RuntimeException('The payment provider could not complete the request. Please try again.');
    }
    return $j;
}

/** The address of the VibeStream folder, e.g. http://localhost/VibeStream */
function vs_stripe_base_url(): string
{
    $configured = trim((string)VS_STRIPE_BASE_URL);
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (!is_array($parts)) {
            throw new RuntimeException('VIBESTREAM_BASE_URL is not a valid absolute URL.');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('/^[A-Za-z0-9.-]+$/', (string)$parts['host']) !== 1
            || ($scheme !== 'https' && !in_array($host, ['localhost', '127.0.0.1'], true))) {
            throw new RuntimeException('VIBESTREAM_BASE_URL must be HTTPS publicly (HTTP is allowed only for localhost) and must not contain credentials, query, or fragment.');
        }
        return rtrim($configured, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/', $host) !== 1) {
        throw new RuntimeException('Set VIBESTREAM_BASE_URL to the public site URL before accepting payments.');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    $scheme = $https ? 'https' : 'http';
    if (!$https && !in_array(strtolower(explode(':', $host)[0]), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Stripe payments require HTTPS. Set VIBESTREAM_BASE_URL to your HTTPS site URL.');
    }
    $path = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scheme . '://' . $host . rtrim($path, '/');
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
    $paymentIntent = $s['payment_intent'] ?? null;
    if (is_array($paymentIntent)) {
        $paymentIntent = $paymentIntent['id'] ?? null;
    }
    $paymentId = is_string($paymentIntent) && $paymentIntent !== ''
        ? $paymentIntent
        : (string)($s['id'] ?? '');
    if ($paymentId === '') {
        return 'Stripe did not return a payment reference.';
    }
    $txn = 'stripe:' . $paymentId;
    vs_premium_ensure($pdo);
    vs_premium_confirm_payment($pdo, $username, $plan, $txn, (float)$def['price']);   // false = already recorded, fine
    return null;
}