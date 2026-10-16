<?php
/**
 * A printable receipt for one confirmed Premium payment: premium-receipt.php?id=123
 * Only the person who paid (or an admin) can open it. Put this file in the VibeStream root, next to premium-api.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';

$me = trim((string)($_SESSION['username'] ?? ''));
if ($me === '') {
    header('Location: login.php');
    exit;
}
$pdo = vs_premium_pdo();
$id = (int)($_GET['id'] ?? 0);
$r = null;
if ($pdo && $id > 0) {
    $st = $pdo->prepare('SELECT * FROM premium_requests WHERE id = :i AND status = \'approved\' LIMIT 1');
    $st->execute([':i' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
// Same answer for "does not exist" and "not yours", so receipts cannot be guessed.
if (!$r || (strcasecmp((string)$r['username'], $me) !== 0 && !vs_premium_is_admin($me))) {
    http_response_code(404);
    exit('Receipt not found.');
}
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$def = vs_premium_plan_def((string)$r['plan']);
$label = ($def['label'] ?? (string)$r['plan']);
$tier = ($def['tier'] ?? 'premium') === 'plus' ? 'Premium Plus' : 'Premium';
$days = (int)($def['days'] ?? 0);
header('Cache-Control: private, no-store');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Receipt VS-<?php echo $h(str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT)); ?></title>
<style>
body{font-family:system-ui,Arial,sans-serif;background:#f4f5f7;color:#17141f;margin:0;padding:24px}
.rc{max-width:560px;margin:0 auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h1{font-size:22px;margin:0 0 4px}
.muted{color:#666;font-size:14px}
table{width:100%;border-collapse:collapse;margin:22px 0}
td{padding:10px 0;border-bottom:1px solid #e6e6ea;vertical-align:top}
td:last-child{text-align:right;font-weight:600}
.total td{border-bottom:0;font-size:18px}
.paid{display:inline-block;background:#2e9e5b;color:#fff;border-radius:999px;padding:3px 12px;font-size:12px;font-weight:700}
button{border:0;border-radius:10px;background:#f9433f;color:#fff;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}
@media print{body{background:#fff;padding:0}.rc{box-shadow:none}button{display:none}}
</style>
</head>
<body>
<div class="rc">
  <h1>VibeStream receipt</h1>
  <p class="muted">Invoice VS-<?php echo $h(str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT)); ?> &middot; <?php echo $h(date('F j, Y', strtotime((string)$r['updated_at']))); ?> &nbsp; <span class="paid">PAID</span></p>
  <table>
    <tr><td>Customer</td><td><?php echo $h($r['username']); ?></td></tr>
    <tr><td><?php echo $h($tier); ?> &ndash; <?php echo $h(preg_replace('/^Plus /', '', $label)); ?><?php echo $days > 0 ? ' (' . $days . ' days)' : ''; ?></td><td>$<?php echo $h(number_format((float)$r['amount'], 2)); ?></td></tr>
    <tr><td>Transaction ID</td><td><?php echo $h($r['txn_ref']); ?></td></tr>
    <tr class="total"><td>Total paid</td><td>$<?php echo $h(number_format((float)$r['amount'], 2)); ?></td></tr>
  </table>
  <p class="muted">Thank you for supporting VibeStream. Keep this receipt for your records.</p>
  <button type="button" onclick="window.print()">Print or save as PDF</button>
</div>
</body>
</html>
