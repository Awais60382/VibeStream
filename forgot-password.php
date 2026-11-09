<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/account-safety-store.php';

$message = '';
$error = '';
$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!vs_as_csrf_ok()) {
        $error = 'The page expired. Please try again.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid e-mail address.';
    } else {
        try {
            if (vs_reset_request($pdo, $email)) {
                $sent = true;
            } else {
                $error = 'Password reset e-mail is not set up on this site yet. Please contact the site owner.';
            }
        } catch (Throwable $e) {
            error_log('forgot-password.php: ' . $e->getMessage());
            $error = 'Something went wrong. Please try again later.';
        }
    }
}
include "header.php";
?>
<link rel="stylesheet" href="neon-auth.css?v=2">
<main class="auth-layout">
    <section class="auth-card" aria-labelledby="forgot-title">
        <a class="auth-brand" href="index.php"><img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""><span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span></a>
        <p class="auth-eyebrow">Account help</p>
        <h1 id="forgot-title">Forgot your password?</h1>
        <p class="auth-description">Enter the e-mail address of your account. We will send you a link to choose a new password.</p>

        <?php if ($error !== ''): ?>
            <p class="auth-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>

        <?php if ($sent): ?>
            <p class="auth-description" role="status"><strong>Check your e-mail.</strong> If an account uses that address, a reset link is on its way. The link works for 1 hour. Look in your spam folder too.</p>
        <?php else: ?>
            <form method="POST" action="forgot-password.php">
                <?php echo vs_as_csrf_field(); ?>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" autocomplete="email" maxlength="190" placeholder="you@example.com" required>
                </div>
                <button type="submit" class="submit-btn">Send reset link</button>
            </form>
        <?php endif; ?>

        <p class="auth-footer"><a href="login.php">Back to sign in</a></p>
    </section>
</main>
</body>
</html>
