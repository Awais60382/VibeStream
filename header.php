<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isLoggedIn = isset($_SESSION['username']);
$username = $isLoggedIn ? $_SESSION['username'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VibeStream</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="navbar">
        <div class="nav-left">
            <h1 onclick="window.location.href='index.php'" style="cursor: pointer;">VibeStream</h1>
        </div>
        <div class="nav-center">
            <input type="text" placeholder="Search">
        </div>
        <div class="nav-right" style="display: flex; align-items: center; gap: 12px;">
            <a href="upload.php" class="upload-btn">+ Create</a>
            
            <?php if ($isLoggedIn): ?>
                <div class="user-profile-badge" style="display: flex; align-items: center; gap: 8px; background: var(--surface-raised, #222); padding: 4px 12px 4px 6px; border-radius: 20px; border: 1px solid var(--border, #333);">
                    <div style="width: 28px; height: 28px; background: #3ea6ff; color: #000; font-weight: bold; border-radius: 50%; display: flex; justify-content: center; align-items: center; font-size: 13px;">
                        <?php echo strtoupper(substr($username, 0, 1)); ?>
                    </div>
                    <span style="color: #fff; font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($username); ?></span>
                    <a href="logout.php" title="Logout" style="color: var(--text-secondary, #aaa); text-decoration: none; font-size: 12px; margin-left: 6px; padding-left: 6px; border-left: 1px solid var(--border, #333);">Logout</a>
                </div>
            <?php else: ?>
                <a href="login.php" class="login-btn">Sign In</a>
            <?php endif; ?>
        </div>
    </header>