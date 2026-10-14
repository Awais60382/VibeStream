<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/google-oauth.php';
$googleEnabled = GOOGLE_OAUTH_ENABLED && GOOGLE_OAUTH_CLIENT_ID !== '';
if ($googleEnabled) {
    $_SESSION['google_oauth_nonce'] = bin2hex(random_bytes(32));
}
$googleClientId = GOOGLE_OAUTH_CLIENT_ID;
$loginError = trim((string)($_GET['error'] ?? ''));
include "header.php";
?>
<main class="auth-layout">
    <section class="auth-card" aria-labelledby="login-title">
        <a class="auth-brand" href="index.php"><img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt=""><span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span></a>
        <p class="auth-eyebrow">Welcome back</p>
        <h1 id="login-title">Sign in to <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="auth-description">Pick up where you left off and find your next favorite video.</p>

        <?php if ($loginError !== ''): ?>
            <p class="auth-error" role="alert"><?php echo htmlspecialchars($loginError); ?></p>
        <?php endif; ?>
        <?php if ($googleEnabled): ?>
            <div id="google-signin"></div>
            <p id="google-error" class="auth-error" role="alert" hidden></p>
        <?php else: ?>
            <div class="google-coming-soon" role="status">
                <span class="google-coming-soon-icon" aria-hidden="true">G</span>
                <span>Continue with Google</span>
                <span class="google-coming-soon-badge">Coming soon</span>
            </div>
            <p class="google-coming-soon-note">Google sign-in is being prepared. Sign in with your email and password for now.</p>
        <?php endif; ?>
        <div class="auth-divider"><span>or continue with email</span></div>

        <form action="code.php?action=login_form" method="POST">
            <div class="form-group">
                <label for="useremail">Username or email address</label>
                <input type="text" id="useremail" name="useremail" autocomplete="username" placeholder="Enter username or email" required>
            </div>
            <div class="form-group">
                <label for="pass">Password</label>
                <input type="password" id="pass" name="password" autocomplete="current-password" placeholder="Enter password" required>
            </div>
            <label class="show-password">
                <input type="checkbox" id="show-pass-checkbox">
                <span>Show password</span>
            </label>
            <button type="submit" name="login_submit" class="submit-btn">Sign in</button>
        </form>

        <p class="auth-footer">New to <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>? <a href="Signup.php">Create an account</a></p>
    </section>
</main>
<?php if ($googleEnabled): ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
<script>

    <?php if ($googleEnabled): ?>
    window.addEventListener('load', function () {
        var google = window.google;
        if (!google || !google.accounts || !google.accounts.id) {
            document.getElementById('google-error').textContent = 'Google sign-in could not load. Please try again or use your email and password.';
            document.getElementById('google-error').hidden = false;
            return;
        }

        google.accounts.id.initialize({
            client_id: <?php echo json_encode($googleClientId); ?>,
            nonce: <?php echo json_encode($_SESSION['google_oauth_nonce']); ?>,
            callback: function (response) {
                var error = document.getElementById('google-error');
                fetch('google-login.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({credential: response.credential})
                }).then(function (result) {
                    return result.json().then(function (body) {
                        if (!result.ok || !body.success) {
                            throw new Error(body.message || 'Google sign-in failed. Please try again.');
                        }
                        window.location.assign(body.redirect);
                    });
                }).catch(function (reason) {
                    error.textContent = reason.message || 'Google sign-in failed. Please try again.';
                    error.hidden = false;
                });
            }
        });
        google.accounts.id.renderButton(document.getElementById('google-signin'), {
            type: 'standard',
            theme: 'outline',
            size: 'large',
            shape: 'pill',
            text: 'continue_with',
            width: Math.max(200, Math.min(360, window.innerWidth - 80))
        });
    });
    <?php endif; ?>

    document.getElementById('show-pass-checkbox').addEventListener('change', function () {
        document.getElementById('pass').type = this.checked ? 'text' : 'password';
    });
</script>
</body>
</html>