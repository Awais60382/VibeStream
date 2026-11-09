<?php
/**
 * VIP test page (admins only). Put it in the site root and open:
 *   http://localhost/VibeStream/vip-test.php?u=USERNAME
 * It shows whether the site sees that member as VIP, and draws the badge and the ring so you can
 * tell a detection problem from a display problem. Delete it when everything works.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && strcasecmp((string)($_SESSION['role'] ?? ''), 'admin') !== 0) {
    http_response_code(403);
    exit('Admins only.');
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/vip-badge.php';

$u = trim((string)($_GET['u'] ?? ($_SESSION['username'] ?? '')));
$role = vs_vip_role($conn ?? null, $u);
$set = vs_vip_premium_set();
$prem = vs_vip_premium_check($u);
$is = vs_vip_is($u, $conn ?? null);
$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$yn = static fn($v): string => $v === null ? 'could not read' : ($v ? 'YES' : 'no');
?><!DOCTYPE html><meta charset="utf-8"><title>VIP test</title>
<body style="font-family:system-ui;background:#111;color:#eee;padding:30px;max-width:720px">
<h2>VIP test for "<?php echo $h($u); ?>"</h2>
<form><input name="u" value="<?php echo $h($u); ?>"> <button>Test</button></form>
<table cellpadding="6" style="margin:16px 0;border-collapse:collapse">
<tr><td>Role in the users table</td><td><b><?php echo $h($role !== '' ? $role : '(not found)'); ?></b></td></tr>
<tr><td>Premium list could be read</td><td><b><?php echo $set === null ? 'NO (premium-store.php or the database failed)' : 'yes, ' . count($set) . ' active member(s)'; ?></b></td></tr>
<tr><td>Has active Premium</td><td><b><?php echo $yn($prem); ?></b></td></tr>
<tr><td>Counted as VIP (admin or Premium)</td><td><b><?php echo $yn($is); ?></b></td></tr>
</table>
<?php if ($is): ?>
<p>Check mark: <?php echo vs_vip_badge($u, $conn ?? null, 24); ?></p>
<p style="display:flex;align-items:center;gap:14px">Ring (40px): <?php echo vs_vip_ring_open($u, $conn ?? null, 40); ?><span style="display:block;background:#2c74d8;border-radius:50%"></span><?php echo vs_vip_ring_close($u, $conn ?? null); ?>
Ring (80px): <?php echo vs_vip_ring_open($u, $conn ?? null, 80); ?><span style="display:block;background:#2c74d8;border-radius:50%"></span><?php echo vs_vip_ring_close($u, $conn ?? null); ?></p>
<p style="opacity:.7">If you see the ring here but not on a page, that page still has the old file.</p>
<?php else: ?>
<p style="opacity:.8">This member is not counted as VIP, so no ring or check is drawn. Check the table above: role must be Admin, or the member needs active Premium.</p>
<?php endif; ?>
</body>
