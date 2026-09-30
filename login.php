<?php
session_start();
include "header.php";
?>
<!DOCTYPE html>
<html lang="en">
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - VibeStream</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="main-layout" style="justify-content: center; align-items: center; min-height: calc(100vh - var(--nav-height));">
        <div class="login-container">
            <h2>Sign in to VibeStream</h2>
            
            <form action="code.php?action=login_form" method="POST">
                <div class="form-group">
                    <label>Username / Email Address</label>
                    <input type="text" name="useremail" placeholder="Enter username or email" required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="pass" name="password" placeholder="Enter password" required>
                </div>

                <!-- Show Password Toggle Checkbox -->
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" onclick="showPassword()" id="show-pass-checkbox" style="width: auto; cursor: pointer;">
                    <label for="show-pass-checkbox" style="margin-bottom: 0; cursor: pointer; color: var(--text-primary);">Show Password</label>
                </div>

                <button type="submit" name="login_submit" class="submit-btn">Sign In</button>
            </form>

            <div style="text-align: center; margin-top: 20px;">
                <p style="color: var(--text-secondary); font-size: 14px;">
                    New to VibeStream? <a href="signup.php" style="color: #3ea6ff; text-decoration: none;">Create account</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function showPassword() {
            var pass = document.getElementById("pass");
            if (pass.type === "password") {
                pass.type = "text";
            } else {
                pass.type = "password";
            }
        }
    </script>

</body>
</html>