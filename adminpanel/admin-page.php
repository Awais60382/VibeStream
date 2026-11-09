<?php
/*
 * VibeStream admin panel - shows a page that the owner wrote on Site icons (Admin Sidebar Buttons).
 * Put this file in the adminpanel folder. Opens as admin-page.php?p=ID (the sidebar button links here).
 */
require_once __DIR__ . '/sidebar-pages-store.php';
include 'header.php';   // login check, sidebar and top bar

$spPage = vs_sp_find((string)($_GET['p'] ?? ''));
$spOk = $spPage !== null && (!$spPage['owner_only'] || !empty($isSiteOwner));
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
<?php if (!$spOk): ?>
          <div class="page-heading"><h1 class="h3 mb-1">Page not found</h1><p class="text-muted mb-0">This page does not exist, or only the owner can open it.</p></div>
          <p class="mt-3"><a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a></p>
<?php else: ?>
          <script>document.title = <?php echo json_encode($spPage['label'] . ' | Admin', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;</script>
          <div class="page-heading d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="page-heading-copy">
              <span class="page-icon"><?php if ($spPage['image'] !== ''): ?><img src="<?php echo $h(vs_sp_image_src($spPage['image'])); ?>" alt="" style="width:28px;height:28px;object-fit:contain;border-radius:6px"><?php else: ?><i class="bi bi-<?php echo $h($spPage['icon']); ?>" aria-hidden="true"></i><?php endif; ?></span>
              <div><h1 class="h3 mb-1"><?php echo $h($spPage['label']); ?></h1></div>
            </div>
            <?php if (!empty($isSiteOwner)): ?>
              <a class="btn btn-outline-secondary btn-sm" href="site-icons.php?sp_edit=<?php echo $h($spPage['id']); ?>#sec-admin-pages"><i class="bi bi-pencil" aria-hidden="true"></i> Edit page</a>
            <?php endif; ?>
          </div>
          <section class="panel mt-3" aria-label="<?php echo $h($spPage['label']); ?>">
            <?php echo vs_sp_format($spPage['content']); ?>
          </section>
<?php endif; ?>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>
