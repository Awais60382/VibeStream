<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/account-safety-store.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
$error = '';
$done = false;
try {
    $row = vs_reset_lookup($pdo, $token);
} catch (Throwable $e) {
    error_log('reset-password.php: ' . $e->getMessage());
}

if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if (!vs_as_csrf_ok()) {
        $error = 'The page expired. Please try again.';
    } elseif (strlen($pass) < 8 || strlen($pass) > 72) {
        $error = 'The password must be 8 to 72 characters.';
    } elseif ($pass !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (vs_reset_apply($pdo, $row, $pass)) {
        $done = true;
        // tell the owner (optional: skipped when owner-alert.php is missing)
        $alertFile = __DIR__ . '/adminpanel/owner-alert.php';
        if (is_file($alertFile)) {
            try {
                require_once $alertFile;
                vs_owner_alert_after_response(null, 'login', 'Password reset for "' . $row['username'] . '"',
                    'Done with the "Forgot password" e-mail link.', false);
            } catch (Throwable $e) {
                // an alert must never break this page
            }
        }
    } else {
        $error = 'The password could not be changed. Please try again.';
    }
}
include "header.php";
?>
<link rel="stylesheet" href="neon-auth.css?v=2">
<main class="auth-layout">
    <section class="auth-card" aria-labelledby="reset-title">
        <a class="auth-brand" href="index.php"><img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""><span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span></a>
        <p class="auth-eyebrow">Account help</p>
        <h1 id="reset-title">Choose a new password</h1>

        <?php if ($done): ?>
            <p class="auth-description" role="status"><strong>Your password was changed.</strong> You can sign in with it now.</p>
            <a class="submit-btn" style="display:block;text-align:center;text-decoration:none" href="login.php">Sign in</a>
        <?php elseif (!$row): ?>
            <p class="auth-error" role="alert">This link is not valid any more. It may have been used already or it may have expired.</p>
            <p class="auth-footer"><a href="forgot-password.php">Send me a new link</a></p>
        <?php else: ?>
            <p class="auth-description">For the account <strong><?php echo htmlspecialchars((string)$row['username'], ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
            <?php if ($error !== ''): ?>
                <p class="auth-error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <form method="POST" action="reset-password.php">
                <?php echo vs_as_csrf_field(); ?>
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="pass">New password</label>
                    <input type="password" id="pass" name="password" autocomplete="new-password" minlength="8" maxlength="72" placeholder="At least 8 characters" required>
                </div>
                <div class="form-group">
                    <label for="confirm_pass">Confirm new password</label>
                    <input type="password" id="confirm_pass" name="confirm_password" autocomplete="new-password" maxlength="72" placeholder="Enter it again" required>
                </div>
                <label class="show-password">
                    <input type="checkbox" id="show-pass-checkbox">
                    <span>Show passwords</span>
                </label>
                <button type="submit" class="submit-btn">Change password</button>
            </form>
            <script>
                document.getElementById('show-pass-checkbox').addEventListener('change', function () {
                    var t = this.checked ? 'text' : 'password';
                    document.getElementById('pass').type = t;
                    document.getElementById('confirm_pass').type = t;
                });
            </script>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
