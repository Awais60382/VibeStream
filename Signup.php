<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/google-oauth.php';
$googleEnabled = GOOGLE_OAUTH_ENABLED && GOOGLE_OAUTH_CLIENT_ID !== '';
if ($googleEnabled) {
    $_SESSION['google_oauth_nonce'] = bin2hex(random_bytes(32));
}
$signupError = trim((string)($_GET['error'] ?? ''));
include 'header.php';
?>
<main class="auth-layout">
    <section class="auth-card" aria-labelledby="signup-title">
        <a class="auth-brand" href="index.php">
            <img class="site-brand-icon" src="<?php echo htmlspecialchars($siteLogoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <span><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <p class="auth-eyebrow">Join the community</p>
        <h1 id="signup-title">Create your <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?> account</h1>
        <p class="auth-description">Make an account to share videos, follow creators, and join the conversation.</p>

        <?php if ($signupError !== ''): ?>
            <p class="auth-error" role="alert"><?php echo htmlspecialchars($signupError); ?></p>
        <?php endif; ?>
        <?php if ($googleEnabled): ?>
            <div id="google-signin"></div>
            <p id="google-error" class="auth-error" role="alert" hidden></p>
            <div class="auth-divider"><span>or sign up with email</span></div>
        <?php endif; ?>

        <form action="signup-handler.php" method="POST">
            <div class="form-group">
                <label for="signup-username">Username</label>
                <input type="text" id="signup-username" name="username" autocomplete="username" placeholder="Choose a username" required>
            </div>

            <div class="form-group">
                <label for="signup-email">Email address</label>
                <input type="email" id="signup-email" name="email" autocomplete="email" placeholder="you@example.com" required>
            </div>

            <div class="form-group">
                <label for="pass">Password</label>
                <input type="password" id="pass" name="password" autocomplete="new-password" placeholder="Create a password" required>
            </div>

            <div class="form-group">
                <label for="confirm_pass">Confirm password</label>
                <input type="password" id="confirm_pass" name="confirm_password" autocomplete="new-password" placeholder="Enter your password again" required>
            </div>

            <label class="show-password">
                <input type="checkbox" onclick="showPasswords()" id="show-pass-checkbox">
                <span>Show passwords</span>
            </label>

            <button type="submit" name="signup_submit" class="submit-btn">Create account</button>
        </form>

        <p class="auth-footer">Already have an account? <a href="login.php">Sign in</a></p>
    </section>
</main>

<?php if ($googleEnabled): ?>
<script src="https://accounts.google.com/gsi/client" async defer></script>
<script>
    window.addEventListener('load', function () {
        var google = window.google;
        var error = document.getElementById('google-error');
        if (!google || !google.accounts || !google.accounts.id) {
            error.textContent = 'Google sign-in could not load. Please sign up with your email instead.';
            error.hidden = false;
            return;
        }
        google.accounts.id.initialize({
            client_id: <?php echo json_encode(GOOGLE_OAUTH_CLIENT_ID); ?>,
            nonce: <?php echo json_encode($_SESSION['google_oauth_nonce']); ?>,
            callback: function (response) {
                fetch('google-login.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({credential: response.credential})
                }).then(function (result) {
                    return result.json().then(function (body) {
                        if (!result.ok || !body.success) {
                            throw new Error(body.message || 'Google sign-up failed. Please try again.');
                        }
                        window.location.assign(body.redirect);
                    });
                }).catch(function (reason) {
                    error.textContent = reason.message || 'Google sign-up failed. Please try again.';
                    error.hidden = false;
                });
            }
        });
        google.accounts.id.renderButton(document.getElementById('google-signin'), {
            type: 'standard', theme: 'outline', size: 'large', shape: 'pill', text: 'signup_with',
            width: Math.max(200, Math.min(360, window.innerWidth - 80))
        });
    });
</script>
<?php endif; ?>
<script>
    function showPasswords() {
        var password = document.getElementById("pass");
        var confirmPassword = document.getElementById("confirm_pass");
        var visible = password.type === "password";
        password.type = visible ? "text" : "password";
        confirmPassword.type = visible ? "text" : "password";
    }
</script>
</body>
</html>