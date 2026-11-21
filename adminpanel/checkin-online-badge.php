<?php
// Include on the dashboard (adminpanel/index.php) where you want the badge, e.g.:
//   <?php include __DIR__ . '/checkin-online-badge.php'; ?>
// Shows "3 of 5 admins online" and links to the check-in page. Owner only.
if (!empty($_SESSION['vs_is_owner'])) {
    require_once __DIR__ . '/checkin-lib.php';
    require_once __DIR__ . '/checkin-owner-names.php';
    require_once __DIR__ . '/checkin-insights.php';
    $vsBadgeNames = vs_checkin_owner_names(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
    [$vsOnline, $vsTotal] = vs_checkin_online_summary($vsBadgeNames);
    ?>
<a href="checkin.php#team" style="display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:999px;background:rgba(34,224,138,.13);color:#22e08a;font:600 14px/1 Inter,system-ui,sans-serif;text-decoration:none">
  <span style="width:9px;height:9px;border-radius:50%;background:currentColor"></span>
  <?= (int)$vsOnline ?> of <?= (int)$vsTotal ?> admin<?= $vsTotal === 1 ? '' : 's' ?> online
</a>
<?php } ?>
