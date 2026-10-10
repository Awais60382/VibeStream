<?php
/**
 * Admin page: see "I paid" requests and approve or reject them.
 * Only usernames listed in VS_PREMIUM_ADMINS (premium-store.php) can open it.
 * Put this file in the VibeStream root, next to header.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/premium-store.php';

$me = strtolower(trim((string)($_SESSION['username'] ?? '')));
$admins = array_map('strtolower', VS_PREMIUM_ADMINS);
if ($me === '' || !in_array($me, $admins, true)) {
    http_response_code(403);
    exit('Admins only.');
}

$db = vs_premium_pdo();
if (!$db) {
    exit('No database connection.');
}
vs_premium_ensure($db);

if (empty($_SESSION['premium_csrf'])) {
    $_SESSION['premium_csrf'] = bin2hex(random_bytes(32));
}

// Handle Approve / Reject before any page output.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = hash_equals((string)$_SESSION['premium_csrf'], (string)($_POST['csrf'] ?? ''));
    $id = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $result = 'error';
    if ($ok && $id > 0 && in_array($decision, ['approve', 'reject'], true)) {
        try {
            $result = vs_premium_review($db, $id, $decision === 'approve') ? $decision : 'gone';
        } catch (Throwable $e) {
            $result = 'error';
        }
    }
    header('Location: premium-requests.php?r=' . urlencode($result));
    exit;
}

$rows = vs_premium_requests($db, 100);
$flash = [
    'approve' => 'Approved. Premium is now active for that user.',
    'reject'  => 'Rejected.',
    'gone'    => 'That request was already handled.',
    'error'   => 'Something went wrong. Try again.',
][$_GET['r'] ?? ''] ?? '';

function vs_h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

include __DIR__ . '/header.php';
?>
<style>
.pr-page{max-width:1000px;margin:0 auto;padding:32px clamp(16px,4vw,32px) 80px;color:inherit}
.pr-page h1{font-size:28px;margin:0 0 6px}
.pr-flash{padding:12px 16px;border-radius:12px;margin:16px 0;background:rgba(245,185,66,.18);border:1px solid #f5b942}
.pr-wrap{overflow-x:auto;margin-top:18px}
.pr-page table{width:100%;border-collapse:collapse;min-width:720px}
.pr-page th,.pr-page td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(128,128,128,.28);vertical-align:middle}
.pr-page th{font-size:13px;opacity:.7}
.pr-tag{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;background:rgba(128,128,128,.3)}
.pr-tag.pending{background:#f5b942;color:#2a1700}
.pr-tag.approved{background:#2e9e5b;color:#fff}
.pr-tag.rejected{background:#c0392b;color:#fff}
.pr-page form{display:inline}
.pr-btn{border:0;border-radius:8px;padding:7px 12px;font:inherit;font-weight:600;cursor:pointer;color:#fff;background:#2e9e5b}
.pr-btn.no{background:#c0392b}
</style>
<main class="pr-page">
  <h1>Premium payments</h1>
  <p>Check that the money arrived, then approve. Approving turns Premium on for that user.</p>
  <?php if ($flash !== ''): ?><div class="pr-flash"><?php echo vs_h($flash); ?></div><?php endif; ?>
  <div class="pr-wrap">
    <table>
      <thead>
        <tr><th>User</th><th>Plan</th><th>Amount</th><th>Transaction ID</th><th>Sent</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7">No payment requests yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?php echo vs_h($r['username']); ?></td>
          <td><?php echo vs_h(ucfirst($r['plan'])); ?></td>
          <td>$<?php echo vs_h(number_format((float)$r['amount'], 2)); ?></td>
          <td><?php echo vs_h($r['txn_ref']); ?></td>
          <td><?php echo vs_h($r['created_at']); ?></td>
          <td><span class="pr-tag <?php echo vs_h($r['status']); ?>"><?php echo vs_h($r['status']); ?></span></td>
          <td>
            <?php if ($r['status'] === 'pending'): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?php echo vs_h($_SESSION['premium_csrf']); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button class="pr-btn" name="decision" value="approve" type="submit">Approve</button>
                <button class="pr-btn no" name="decision" value="reject" type="submit">Reject</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
