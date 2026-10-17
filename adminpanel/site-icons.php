<?php
/*
 * VibeStream admin panel - Site icons (OWNER ONLY).
 * The owner changes: the app logo and icon, the category icons, and the small menu / explore / button icons.
 * Put this file in the adminpanel folder.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['AdminSession']) && (!isset($_SESSION['role']) || strcasecmp((string)$_SESSION['role'], 'Admin') !== 0)) {
    echo "<script>alert('Access Denied: Administrator privileges required.'); window.location.href='../login.php';</script>";
    exit;
}

include_once "../connection.php";
include_once "ui_helpers.php";
require_once __DIR__ . '/owner-guard.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../category-presets.php';
require_once __DIR__ . '/../category-icons.php';
require_once __DIR__ . '/../ui-icons.php';

// Only the owner (VS_OWNER_EMAIL in owner-guard.php) may open this page or send any change.
if (!isset($pdo) || !($pdo instanceof PDO) || !vs_is_owner($pdo)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Owner only</title></head>'
        . '<body style="font-family:Arial,sans-serif;padding:40px;background:#f4f5f7">'
        . '<h2>Owner only</h2><p>Only the owner account can change the site icons.</p>'
        . '<p><a href="index.php">Back to the admin panel</a></p></body></html>';
    exit;
}

if (empty($_SESSION['site_icons_csrf'])) {
    $_SESSION['site_icons_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['site_icons_csrf'];
$adminName = trim((string)($_SESSION['AdminSession'] ?? $_SESSION['username'] ?? 'An admin'));

function vs_si_flash(string $type, string $text): void
{
    $_SESSION['site_icons_flash'] = ['type' => $type, 'text' => $text];
}

/** Saves a new square PNG logo (same rules as the Platform settings page). */
function vs_si_save_logo(array $file, string $adminName): void
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a logo file first.');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('The upload failed. Please try again.');
    }
    if ((int)($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new RuntimeException('The logo must be smaller than 4 MB.');
    }
    $info = @getimagesize($tmp);
    if (!$info || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
        throw new RuntimeException('The logo must be a PNG image.');
    }
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w !== $h || $w < 128 || $w > 2048) {
        throw new RuntimeException('The logo must be square, between 128 and 2048 pixels.');
    }
    $dir = __DIR__ . '/../uploads/site-branding';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the uploads/site-branding folder.');
    }
    $filename = bin2hex(random_bytes(16)) . '.png';
    $dest = $dir . '/' . $filename;
    if (!@move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Could not save the logo. Check folder permissions.');
    }
    @chmod($dest, 0644);
    $state = vs_branding_state();
    $state['logo'] = 'uploads/site-branding/' . $filename;
    $state['notification'] = ['message' => 'The site logo was updated by ' . $adminName . '.', 'created_at' => date(DATE_ATOM)];
    try {
        vs_branding_save_state($state);   // throws when it cannot save
    } catch (Throwable $e) {
        @unlink($dest);
        throw new RuntimeException('Could not save the settings. Check folder permissions.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        vs_si_flash('danger', 'The request expired. Refresh the page and try again.');
        header('Location: site-icons.php');
        exit;
    }
    $act = (string)($_POST['action'] ?? '');
    $name = (string)($_POST['name'] ?? '');
    try {
        switch ($act) {
            case 'logo':
                vs_si_save_logo($_FILES['icon'] ?? [], $adminName);
                vs_si_flash('success', 'The app logo and icon were updated.');
                break;
            case 'logo_reset':
                $state = vs_branding_state();
                $state['logo'] = VS_DEFAULT_LOGO_PATH;
                $state['notification'] = ['message' => 'The site logo was reset by ' . $adminName . '.', 'created_at' => date(DATE_ATOM)];
                try {
                    vs_branding_save_state($state);   // throws when it cannot save
                } catch (Throwable $e) {
                    throw new RuntimeException('Could not save the settings. Check folder permissions.');
                }
                vs_si_flash('success', 'The app logo was reset to the default.');
                break;
            case 'cat_icon':
                vs_cat_icon_set($name, $_FILES['icon'] ?? []);
                vs_si_flash('success', 'Category icon updated for "' . $name . '".');
                break;
            case 'cat_icon_remove':
                vs_cat_icon_remove($name);
                vs_si_flash('success', 'Category icon removed for "' . $name . '".');
                break;
            case 'ui_icon':
                vs_ui_icon_set($name, $_FILES['icon'] ?? []);
                vs_si_flash('success', 'Icon updated: ' . (vs_ui_icon_all_names()[$name] ?? $name) . '.');
                break;
            case 'ui_icon_remove':
                vs_ui_icon_remove($name);
                vs_si_flash('success', 'Icon reset to the built-in one: ' . (vs_ui_icon_all_names()[$name] ?? $name) . '.');
                break;
            default:
                vs_si_flash('danger', 'Unknown action.');
        }
    } catch (Throwable $e) {
        vs_si_flash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.');
        if (!($e instanceof RuntimeException)) {
            error_log('site-icons failed: ' . $e->getMessage());
        }
    }
    header('Location: site-icons.php');
    exit;
}

$flash = $_SESSION['site_icons_flash'] ?? null;
unset($_SESSION['site_icons_flash']);

try {
    $logoUrl = vs_branding_logo_url();
} catch (Throwable $e) {
    $logoUrl = '';
}
$categories = function_exists('vs_default_categories') ? vs_default_categories() : [];
$uiMap = vs_ui_icons_map();
// When the sidebar icon editor (sidebar-icons-store.php) is installed it handles the Menu and Explore icons,
// so they are not offered here a second time.
$vsSidebarEditor = is_file(__DIR__ . '/../sidebar-icons-store.php');

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}

/** One small "choose a file and it uploads" form. */
function vs_si_upload_form(string $csrf, string $action, string $name, string $label): void
{
    ?>
    <form method="post" enctype="multipart/form-data" class="d-inline">
      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo vs_e($action); ?>">
      <input type="hidden" name="name" value="<?php echo vs_e($name); ?>">
      <label class="btn btn-outline-primary btn-sm mb-0" style="cursor:pointer">
        <?php echo vs_e($label); ?>
        <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" hidden onchange="this.form.submit()">
      </label>
    </form>
    <?php
}
?>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-images" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Owner tools</p>
                <h1 class="h3 mb-1">Site icons</h1>
                <p class="text-muted mb-0">Change the logo and icons used across the website. Only the owner can see this page.</p>
              </div>
            </div>
            <div class="heading-actions">
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Dashboard</a>
            </div>
          </div>

          <?php if (is_array($flash)): ?>
            <div class="alert alert-<?php echo vs_e($flash['type']); ?> mt-3 mb-0" role="status"><?php echo vs_e($flash['text']); ?></div>
          <?php endif; ?>

          <section class="panel mt-3" aria-label="App logo and icon">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-app-indicator" aria-hidden="true"></i><span>App logo and icon</span></h2>
              <p class="text-muted mb-0">Used in the top bar, the browser tab and the phone home screen icon. PNG, square, 128 to 2048 pixels.</p>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-3 p-3 pt-0">
              <?php if ($logoUrl !== ''): ?>
                <img src="<?php echo vs_e($logoUrl); ?>?v=<?php echo time(); ?>" alt="Current logo" width="72" height="72" style="border-radius:16px;object-fit:cover;background:#111">
              <?php endif; ?>
              <?php vs_si_upload_form($csrf, 'logo', '', 'Change logo'); ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Reset the logo to the default one?');">
                <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                <input type="hidden" name="action" value="logo_reset">
                <button type="submit" class="btn btn-outline-secondary btn-sm">Reset to default</button>
              </form>
            </div>
          </section>

          <section class="panel mt-3" aria-label="Category icons">
            <div class="panel-header">
              <h2 class="h5 mb-1 section-title"><i class="bi bi-tags" aria-hidden="true"></i><span>Category icons</span></h2>
              <p class="text-muted mb-0">The icon next to each category in the side menu and the category chips.</p>
            </div>
            <?php if (!$categories): ?>
              <p class="text-muted p-3 pt-0 mb-0">No categories yet. Add them on the <a href="categories.php">Categories</a> page first.</p>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <tbody>
                    <?php foreach ($categories as $cat): $cname = (string)$cat; $has = vs_cat_icon_url($cname) !== null; ?>
                      <tr>
                        <td style="width:56px"><?php echo vs_category_icon_html($cname, 28, '../'); ?></td>
                        <td class="fw-semibold"><?php echo vs_e($cname); ?></td>
                        <td class="text-end">
                          <?php vs_si_upload_form($csrf, 'cat_icon', $cname, $has ? 'Change' : 'Add icon'); ?>
                          <?php if ($has): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Remove this icon?');">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="cat_icon_remove">
                              <input type="hidden" name="name" value="<?php echo vs_e($cname); ?>">
                              <button type="submit" class="btn btn-outline-danger btn-sm">Remove</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <?php if ($vsSidebarEditor): ?>
            <p class="text-muted small mt-3 mb-0">The Menu and Explore icons are managed by the sidebar icon editor.</p>
          <?php endif; ?>
          <?php foreach (vs_ui_icon_groups() as $group => $names): if ($vsSidebarEditor && in_array($group, ['Menu', 'Explore'], true)) { continue; } ?>
            <section class="panel mt-3" aria-label="<?php echo vs_e($group); ?> icons">
              <div class="panel-header">
                <h2 class="h5 mb-1 section-title"><i class="bi bi-grid" aria-hidden="true"></i><span><?php echo vs_e($group); ?> icons</span></h2>
                <p class="text-muted mb-0">
                  <?php echo $group === 'Menu' ? 'The icons in the main side menu.' : ($group === 'Explore' ? 'The icons in the Explore list of the side menu.' : 'The small icons inside buttons (profile page and others).'); ?>
                  Square images (PNG, WEBP, JPG, GIF or SVG) under 512 KB look best.
                </p>
              </div>
              <div class="table-responsive">
                <table class="table align-middle mb-0">
                  <tbody>
                    <?php foreach ($names as $iname => $ilabel): $custom = $uiMap[$iname] ?? null; ?>
                      <tr>
                        <td style="width:56px">
                          <?php if ($custom): ?>
                            <img src="../uploads/ui-icons/<?php echo vs_e($custom); ?>" alt="" width="28" height="28" style="object-fit:contain">
                          <?php else: ?>
                            <span class="badge text-bg-secondary">Default</span>
                          <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?php echo vs_e($ilabel); ?></td>
                        <td class="text-end">
                          <?php vs_si_upload_form($csrf, 'ui_icon', $iname, $custom ? 'Change' : 'Upload icon'); ?>
                          <?php if ($custom): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Go back to the built-in icon?');">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="ui_icon_remove">
                              <input type="hidden" name="name" value="<?php echo vs_e($iname); ?>">
                              <button type="submit" class="btn btn-outline-danger btn-sm">Reset</button>
                            </form>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          <?php endforeach; ?>
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