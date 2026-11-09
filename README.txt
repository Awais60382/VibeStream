VibeStream - improved files
===========================
Copy every file into the main VibeStream folder (C:\xampppp\htdocs\VibeStream), replacing the old ones.
Keep a backup of the old files first.

1. phpMyAdmin -> your VibeStream database -> SQL tab -> paste migrations.sql -> Go.
2. NEW files: csrf.php, security-bootstrap.php (optional), migrations.sql
3. CHANGED files: Signup.php, signup-handler.php, sidebar-subscriptions.php, subscription-seen.php,
   site-branding.php, ui-icons.php, theme.js, sw.js
4. After replacing theme.js / sw.js, change the ?v= number where pages load them (theme.js?v=3) and
   hard-refresh once (Ctrl+F5) so the service worker updates.
5. Optional: add  <meta name="vs-default-theme" content="auto">  to follow each visitor's device theme.
