<?php
/**
 * VibeStream - Premium payment setup (LOCALHOST ONLY).
 * Put this file in the VibeStream main folder, next to premium-store.php, then open:
 *   http://localhost/VibeStream/premium-setup.php
 * It shows why "Payments are not open yet" appears and lets you save your payment details.
 * It refuses to run for anyone who is not on the same computer as the server.
 * DELETE this file before the site goes live (the admin page premium-requests.php does the same job).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$addr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($addr, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('This page only works on the computer that runs the server.');
}

require_once __DIR__ . '/premium-store.php';

if (empty($_SESSION['premium_setup_csrf'])) {
    $_SESSION['premium_setup_csrf'] = bin2hex(random_bytes(32));
}
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['premium_setup_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $flash = 'Session expired. Reload the page and try again.';
    } else {
        $lines = preg_split('/\R/', (string)($_POST['pay_info'] ?? '')) ?: [];
        $flash = vs_premium_save_pay_info($lines)
            ? 'Saved. Reload the Premium page (Ctrl+F5): buying is now open.'
            : 'Could not save. Check that the VibeStream folder is writable.';
    }
}

$file = vs_premium_settings_file();
$dir = dirname($file);
$lines = vs_premium_pay_info();
$user = (string)($_SESSION['username'] ?? '');
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Premium payment setup</title>
<style>
body{font-family:system-ui,Arial,sans-serif;background:#111;color:#eee;margin:0;padding:24px}
.box{max-width:680px;margin:0 auto;background:#1c1c1f;border-radius:14px;padding:24px}
h1{font-size:22px;margin:0 0 12px}
table{width:100%;border-collapse:collapse;margin:12px 0 20px}
td{padding:8px 0;border-bottom:1px solid #333;vertical-align:top}
td:last-child{text-align:right;font-weight:600}
.ok{color:#4cd58a}.bad{color:#ff6b6b}
textarea{width:100%;box-sizing:border-box;min-height:110px;padding:10px;border-radius:8px;border:1px solid #444;background:#111;color:#eee;font:inherit}
button{margin-top:10px;border:0;border-radius:10px;background:#f9433f;color:#fff;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}
.flash{background:#2a2410;border:1px solid #f5b942;border-radius:10px;padding:10px 14px;margin-bottom:16px}
small{color:#aaa}
</style></head><body><div class="box">
<h1>Premium payment setup</h1>
<?php if ($flash !== ''): ?><div class="flash"><?php echo $h($flash); ?></div><?php endif; ?>
<table>
  <tr><td>Payment details saved</td><td class="<?php echo $lines ? 'ok' : 'bad'; ?>"><?php echo $lines ? count($lines) . ' line(s): buying is open' : 'None: buying is closed'; ?></td></tr>
  <tr><td>Settings file</td><td><small><?php echo $h($file); ?></small></td></tr>
  <tr><td>Folder can be written</td><td class="<?php echo (is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir))) ? 'ok' : 'bad'; ?>"><?php echo (is_dir($dir) ? is_writable($dir) : is_writable(dirname($dir))) ? 'Yes' : 'No'; ?></td></tr>
  <tr><td>Signed in on the site as</td><td><?php echo $user !== '' ? $h($user) : 'nobody'; ?></td></tr>
  <tr><td>That account can approve payments</td><td class="<?php echo ($user !== '' && vs_premium_is_admin($user)) ? 'ok' : 'bad'; ?>"><?php echo ($user !== '' && vs_premium_is_admin($user)) ? 'Yes (admin)' : 'No (normal user)'; ?></td></tr>
</table>
<form method="post">
  <input type="hidden" name="csrf" value="<?php echo $h($_SESSION['premium_setup_csrf']); ?>">
  <label for="pi"><strong>How should buyers pay you?</strong> One line per method, up to 6. Buyers see these lines.</label>
  <textarea id="pi" name="pay_info" maxlength="1300" placeholder="Bank transfer: Bank name, account number, account title&#10;Wallet: number and account name"><?php echo $h(implode("\n", $lines)); ?></textarea>
  <button type="submit">Save payment details</button>
</form>
<p><small>Premium turns on only after you approve a payment in the admin panel (Admin &rarr; Premium) or on premium-requests.php, signed in as an admin.</small></p>
</div></body></html>