<?php
    include "header.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - VibeStream</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="main-layout" style="justify-content: center; align-items: center; min-height: calc(100vh - var(--nav-height));">
        <div class="login-container">
            <h2>Create VibeStream Account</h2>
            
            <form action="code.php?action=signup_form" method="POST">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" placeholder="Username here.." required>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="Email Address here.." required>
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" id="pass" name="password" placeholder="Password here.." required>
                </div>

                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" id="confirm_pass" name="confirm_password" placeholder="Confirm Password here.." required>
                </div>

                <!-- Show Password Checkbox -->
                <div class="form-group" style="display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" onclick="showPasswords()" id="show-pass-checkbox" style="width: auto; cursor: pointer;">
                    <label for="show-pass-checkbox" style="margin-bottom: 0; cursor: pointer; color: var(--text-primary);">Show Passwords</label>
                </div>

                <button type="submit" name="signup_submit" class="submit-btn">Sign Up</button>
            </form>

            <div style="text-align: center; margin-top: 20px;">
                <p style="color: var(--text-secondary); font-size: 14px;">
                    If already have account! <a href="login.php" style="color: #3ea6ff; text-decoration: none;">Login Here.</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        function showPasswords() {
            var pass = document.getElementById("pass");
            var confirmPass = document.getElementById("confirm_pass");
            if (pass.type === "password") {
                pass.type = "text";
                confirmPass.type = "text";
            } else {
                pass.type = "password";
                confirmPass.type = "password";
            }
        }
    </script>

</body>
</html>