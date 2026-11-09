<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/account-safety-store.php';

$token = (string)($_GET['token'] ?? '');
$username = trim((string)($_SESSION['username'] ?? ''));
$email = trim((string)($_SESSION['email'] ?? ''));
$state = '';      // 'verified-now', 'bad-link', 'already', 'sent', 'wait', 'nomail', 'failed', 'ask'
$verifiedUser = '';

try {
    if ($token !== '') {
        $verifiedUser = vs_verify_confirm($pdo, $token);
        $state = $verifiedUser !== '' ? 'verified-now' : 'bad-link';
        if ($verifiedUser !== '' && strcasecmp($verifiedUser, $username) === 0) {
            unset($_SESSION['vs_unverified_banner']);
        }
    } elseif ($username === '') {
        header('Location: login.php?error=' . urlencode('Sign in to verify your e-mail address.'));
        exit;
    } elseif ($email === '') {
        $state = 'nomail';
    } elseif (vs_verify_status($pdo, $username, $email)) {
        $state = 'already';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $state = vs_as_csrf_ok() ? vs_verify_send($pdo, $username, $email) : 'failed';
    } else {
        $state = 'ask';
    }
} catch (Throwable $e) {
    error_log('verify-email.php: ' . $e->getMessage());
    $state = 'failed';
}
include "header.php";
?>
<link rel="stylesheet" href="neon-auth.css?v=2">
<main class="auth-layout">
    <section class="auth-card" aria-labelledby="verify-title">
        <a class="auth-brand" href="index.php"><img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""><span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span></a>
        <p class="auth-eyebrow">Account safety</p>
        <h1 id="verify-title">Verify your e-mail</h1>

        <?php if ($state === 'verified-now'): ?>
            <p class="auth-description" role="status"><strong>Thank you!</strong> Your e-mail address is now verified.</p>
            <a class="submit-btn" style="display:block;text-align:center;text-decoration:none" href="index.php">Go to <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></a>
        <?php elseif ($state === 'bad-link'): ?>
            <p class="auth-error" role="alert">This link is not valid any more. It may have expired (links last 24 hours) or it was already used.</p>
            <p class="auth-footer"><a href="verify-email.php">Send me a new link</a></p>
        <?php elseif ($state === 'already'): ?>
            <p class="auth-description" role="status">Your e-mail address <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong> is already verified.</p>
        <?php elseif ($state === 'sent'): ?>
            <p class="auth-description" role="status"><strong>Check your e-mail.</strong> We sent a link to <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>. It works for 24 hours. Look in your spam folder too.</p>
        <?php elseif ($state === 'wait'): ?>
            <p class="auth-description" role="status">We already sent a link a moment ago. Please wait 2 minutes before asking again.</p>
        <?php elseif ($state === 'nomail'): ?>
            <p class="auth-error" role="alert">E-mail is not set up on this site yet, or your account has no e-mail address. Please contact the site owner.</p>
        <?php elseif ($state === 'failed'): ?>
            <p class="auth-error" role="alert">The e-mail could not be sent. Please try again later.</p>
        <?php else: ?>
            <p class="auth-description">We will send a link to <strong><?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></strong>. Open it to confirm that this address is yours.</p>
            <form method="POST" action="verify-email.php">
                <?php echo vs_as_csrf_field(); ?>
                <button type="submit" class="submit-btn">Send verification e-mail</button>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
