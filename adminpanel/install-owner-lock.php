<?php
/**
 * Run this ONCE (open it in the browser while signed in to the admin panel and press the button).
 * It adds database rules to the `users` table, so they hold no matter which page tries the change:
 *   - an admin account can NOT be deleted, and an admin can NOT be turned into a normal user,
 *     unless it is done from admins.php by the owner (muhammadawaiscom0@gmail.com)
 *   - the owner account can NEVER be deleted, demoted, or have its email changed
 *   - no other account can use the owner's email (stops someone copying it to become owner)
 * Put this file in the adminpanel folder. You can delete it after running it.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp($_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/owner-guard.php';

// Only the owner (VS_OWNER_EMAIL in owner-guard.php) may run this installer.
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can run this page.</p>'
        . '<p><a href="admins.php">Back to the admin panel</a></p></body></html>';
    exit;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function vs_qi(string $c): string
{
    return '`' . str_replace('`', '``', $c) . '`';
}

$done = false;
$error = '';
$ownerEmail = strtolower(VS_OWNER_EMAIL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)$_SESSION['admin_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Session expired. Refresh the page and try again.';
    } elseif (!isset($pdo) || !($pdo instanceof PDO)) {
        $error = 'No database connection found. Check that ../connection.php creates $pdo.';
    } else {
        try {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $cols = vs_cols($pdo, 'users');
            $idC  = $cols ? vs_pick($cols, ['id', 'user_id']) : null;
            $emC  = $cols ? vs_pick($cols, ['email', 'email_address', 'mail']) : null;
            $roC  = $cols ? vs_pick($cols, ['role', 'user_role', 'usertype', 'user_type', 'account_type']) : null;
            if (!$idC || !$emC || !$roC) {
                throw new RuntimeException('The users table needs id, email and role columns.');
            }

            $st = $pdo->prepare(
                'SELECT ' . vs_qi($idC) . ' AS i, ' . vs_qi($roC) . ' AS r FROM `users` WHERE LOWER(TRIM(' . vs_qi($emC) . ')) = :e'
            );
            $st->execute([':e' => $ownerEmail]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) === 0) {
                throw new RuntimeException('No account uses ' . VS_OWNER_EMAIL . '. Create that account first, then run this again.');
            }
            if (count($rows) > 1) {
                throw new RuntimeException('More than one account uses ' . VS_OWNER_EMAIL . '. Remove the duplicates first.');
            }
            if (strtolower(trim((string)$rows[0]['r'])) !== 'admin') {
                throw new RuntimeException('The owner account must have the Admin role first.');
            }

            $id  = vs_qi($idC);
            $em  = vs_qi($emC);
            $ro  = vs_qi($roC);
            $oid = $pdo->quote((string)$rows[0]['i']);
            $eml = $pdo->quote($ownerEmail);

            foreach (['vs_users_lock_delete', 'vs_users_lock_update', 'vs_users_lock_insert'] as $t) {
                $pdo->exec('DROP TRIGGER IF EXISTS ' . $t);
            }

            $pdo->exec(
                "CREATE TRIGGER vs_users_lock_delete BEFORE DELETE ON `users` FOR EACH ROW
                 BEGIN
                   IF OLD.$id = $oid THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The owner account cannot be deleted.';
                   END IF;
                   IF LOWER(TRIM(OLD.$ro)) = 'admin' AND COALESCE(@vs_owner_ok, 0) <> 1 THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the owner can delete admin accounts.';
                   END IF;
                 END"
            );

            $pdo->exec(
                "CREATE TRIGGER vs_users_lock_update BEFORE UPDATE ON `users` FOR EACH ROW
                 BEGIN
                   IF OLD.$id = $oid AND (COALESCE(LOWER(TRIM(NEW.$ro)), '') <> 'admin' OR COALESCE(LOWER(TRIM(NEW.$em)), '') <> $eml) THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The owner account cannot be changed this way.';
                   END IF;
                   IF OLD.$id <> $oid AND LOWER(TRIM(NEW.$em)) = $eml THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That email belongs to the owner.';
                   END IF;
                   IF LOWER(TRIM(OLD.$ro)) = 'admin' AND COALESCE(LOWER(TRIM(NEW.$ro)), '') <> 'admin' AND COALESCE(@vs_owner_ok, 0) <> 1 THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the owner can remove admin access.';
                   END IF;
                 END"
            );

            $pdo->exec(
                "CREATE TRIGGER vs_users_lock_insert BEFORE INSERT ON `users` FOR EACH ROW
                 BEGIN
                   IF LOWER(TRIM(NEW.$em)) = $eml THEN
                     SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That email belongs to the owner.';
                   END IF;
                 END"
            );
            $done = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Lock admin accounts</title>
  <style>
    body{font-family:system-ui,sans-serif;background:#12121a;color:#eee;margin:0;padding:40px 16px}
    .box{max-width:560px;margin:0 auto;background:#1c1c28;border:1px solid #333;border-radius:16px;padding:28px}
    h1{margin:0 0 10px;font-size:22px}
    p,li{line-height:1.5;color:#cfcfe0}
    button{background:#6c5ce7;color:#fff;border:0;border-radius:10px;padding:12px 20px;font:inherit;font-weight:600;cursor:pointer}
    .ok{background:#16351f;border:1px solid #2e9e5b;padding:12px 16px;border-radius:10px}
    .bad{background:#3a1a1a;border:1px solid #c0392b;padding:12px 16px;border-radius:10px}
    a{color:#9d93ff}
  </style>
</head>
<body>
  <div class="box">
    <h1>Lock admin accounts</h1>
    <?php if ($done): ?>
      <div class="ok">Done. Admin accounts are now locked.</div>
      <p>Only <?php echo htmlspecialchars(VS_OWNER_EMAIL, ENT_QUOTES, 'UTF-8'); ?> can delete an admin, and only from the Admins page. You can delete this file now.</p>
      <p><a href="admins.php">Go to Admins</a></p>
    <?php else: ?>
      <?php if ($error !== ''): ?><div class="bad"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
      <p>This adds database rules to the users table:</p>
      <ul>
        <li>Nobody can delete an admin, or turn an admin into a normal user, except the owner from the Admins page.</li>
        <li>The owner account can never be deleted, demoted, or have its email changed.</li>
        <li>No other account can use the owner's email.</li>
      </ul>
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)$_SESSION['admin_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit">Lock admin accounts</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>