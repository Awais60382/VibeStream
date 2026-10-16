<?php
/**
 * Where Stripe sends the buyer after paying. Checks the payment with Stripe itself (the address cannot be faked)
 * and switches Premium on. Put this file in the VibeStream main folder.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/stripe-lib.php';

$me = trim((string)($_SESSION['username'] ?? ''));
$sid = (string)($_GET['session_id'] ?? '');
$error = null;
if ($me === '') {
    header('Location: login.php');
    exit;
}
if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sid) || !vs_stripe_ready()) {
    $error = 'This payment link is not valid.';
} else {
    try {
        $pdo = vs_premium_pdo();
        $s = vs_stripe_call('GET', 'checkout/sessions/' . $sid);
        if (strcasecmp((string)($s['metadata']['username'] ?? ''), $me) !== 0) {
            $error = 'This payment belongs to a different account.';
        } else {
            $error = $pdo ? vs_stripe_fulfil($pdo, $s) : 'No database connection.';
        }
    } catch (Throwable $e) {
        $error = 'Could not check the payment: ' . $e->getMessage();
    }
}
if ($error === null) {
    header('Location: premium.php?paid=1');
    exit;
}
?><!DOCTYPE html><meta charset="utf-8"><body style="font-family:system-ui;background:#111;color:#eee;padding:40px">
<h2>Payment</h2><p><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
<p><a style="color:#f9433f" href="premium.php">Back to Premium</a></p></body>