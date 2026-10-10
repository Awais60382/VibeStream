<?php
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

        <form action="code.php?action=signup_form" method="POST">
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
