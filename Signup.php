<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/google-oauth.php';
$googleEnabled = GOOGLE_OAUTH_ENABLED && GOOGLE_OAUTH_CLIENT_ID !== '';
if ($googleEnabled) {
    $_SESSION['google_oauth_nonce'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/csrf.php';
// Message and typed values come from signup-handler.php through the session (nothing is read from the URL).
$signupError = (string)($_SESSION['signup_error'] ?? '');
$signupOld = (array)($_SESSION['signup_old'] ?? []);
unset($_SESSION['signup_error'], $_SESSION['signup_old']);
$oldUsername = (string)($signupOld['username'] ?? '');
$oldEmail = (string)($signupOld['email'] ?? '');
include 'header.php';
?>
<link rel="stylesheet" href="neon-auth.css?v=3" data-vs-neon>
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
            <p class="auth-error" role="alert"><?php echo htmlspecialchars($signupError, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if ($googleEnabled): ?>
            <div id="google-signin" style="display:flex;justify-content:center;width:100%;color-scheme:light"></div>
            <p id="google-error" class="auth-error" role="alert" hidden></p>
            <div class="auth-divider"><span>or sign up with email</span></div>
        <?php endif; ?>

        <form action="signup-handler.php" method="POST" autocomplete="on">
            <?php echo vs_csrf_field(); ?>
            <div style="position:absolute;left:-9999px;height:0;overflow:hidden" aria-hidden="true">
                <label for="signup-website">Leave this empty</label>
                <input type="text" id="signup-website" name="website" tabindex="-1" autocomplete="off">
            </div>
            <div class="form-group">
                <label for="signup-username">Username</label>
                <input type="text" id="signup-username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false"
                       minlength="3" maxlength="30" pattern="[\p{L}\p{N}_.\-]{3,30}" title="3-30 letters, numbers, dot, dash or underscore"
                       value="<?php echo htmlspecialchars($oldUsername, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Choose a username" required>
            </div>

            <div class="form-group">
                <label for="signup-email">Email address</label>
                <input type="email" id="signup-email" name="email" autocomplete="email" maxlength="190"
                       value="<?php echo htmlspecialchars($oldEmail, ENT_QUOTES, 'UTF-8'); ?>" placeholder="you@example.com" required>
            </div>

            <div class="form-group">
                <label for="pass">Password</label>
                <input type="password" id="pass" name="password" autocomplete="new-password" minlength="8" maxlength="72"
                       aria-describedby="pass-hint" placeholder="Create a password" required>
                <small id="pass-hint" class="auth-description">At least 8 characters. <span id="pass-strength" aria-live="polite"></span></small>
            </div>

            <div class="form-group">
                <label for="confirm_pass">Confirm password</label>
                <input type="password" id="confirm_pass" name="confirm_password" autocomplete="new-password" maxlength="72" placeholder="Enter your password again" required>
                <small id="match-hint" class="auth-error" role="alert" hidden>Passwords do not match yet.</small>
            </div>

            <label class="show-password">
                <input type="checkbox" onclick="showPasswords()" id="show-pass-checkbox">
                <span>Show passwords</span>
            </label>

            <button type="submit" name="signup_submit" id="signup-submit" class="submit-btn">Create account</button>
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
    (function () {
        var pass = document.getElementById('pass');
        var confirm = document.getElementById('confirm_pass');
        var strength = document.getElementById('pass-strength');
        var mismatch = document.getElementById('match-hint');
        var form = pass.form;
        function score(p) {
            var s = 0;
            if (p.length >= 8) s++;
            if (p.length >= 12) s++;
            if (/[a-z]/.test(p) && /[A-Z]/.test(p)) s++;
            if (/\d/.test(p)) s++;
            if (/[^A-Za-z0-9]/.test(p)) s++;
            return s;
        }
        pass.addEventListener('input', function () {
            if (!pass.value) { strength.textContent = ''; return; }
            var s = score(pass.value);
            strength.textContent = 'Strength: ' + (s <= 2 ? 'weak' : s <= 3 ? 'okay' : 'strong');
        });
        function check() { mismatch.hidden = !confirm.value || confirm.value === pass.value; }
        confirm.addEventListener('input', check);
        pass.addEventListener('input', check);
        form.addEventListener('submit', function (e) {
            if (confirm.value !== pass.value) { e.preventDefault(); mismatch.hidden = false; confirm.focus(); return; }
            var btn = document.getElementById('signup-submit');
            btn.disabled = true; btn.textContent = 'Creating account...';
        });
    })();
</script>
</body>
</html>