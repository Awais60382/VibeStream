<?php
require_once __DIR__ . '/admin-bootstrap.php';

require_once __DIR__ . '/../site-branding.php';

$successMsg = '';
$errorMsg = '';
$brandingState = vs_branding_state();
if (empty($_SESSION['vs_app_settings_csrf'])) {
    $_SESSION['vs_app_settings_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app_settings') {
    $newLogoPath = '';
    try {
        if (!hash_equals($_SESSION['vs_app_settings_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The settings request expired. Refresh the page and try again.');
        }

        $appName = trim((string)($_POST['app_name'] ?? ''));
        $appDescription = trim((string)($_POST['app_description'] ?? ''));
        $defaultTheme = (string)($_POST['default_theme'] ?? '');
        if ($appName === '' || mb_strlen($appName) > 50) {
            throw new RuntimeException('Enter an app name between 1 and 50 characters.');
        }
        if ($appDescription === '' || mb_strlen($appDescription) > 180) {
            throw new RuntimeException('Enter an app description between 1 and 180 characters.');
        }
        if (!in_array($defaultTheme, ['dark', 'light'], true)) {
            throw new RuntimeException('Choose either light or dark as the default theme.');
        }

        $newUploadsEnabled = isset($_POST['uploads_enabled']);
        $newMaintenanceEnabled = isset($_POST['maintenance_enabled']);
        $newMaintenanceMessage = trim((string)($_POST['maintenance_message'] ?? ''));
        if (mb_strlen($newMaintenanceMessage) > 300) {
            throw new RuntimeException('The maintenance message can be at most 300 characters.');
        }
        $newMaxUploadMb = filter_var($_POST['max_upload_mb'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4096]]);
        if ($newMaxUploadMb === false) {
            throw new RuntimeException('Enter a maximum upload size between 1 and 4096 MB.');
        }
        $newCategories = [];
        foreach (preg_split('/\r\n|\r|\n|,/', (string)($_POST['categories'] ?? '')) as $categoryLine) {
            $categoryLine = trim((string)preg_replace('/\s+/', ' ', $categoryLine));
            if ($categoryLine === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9 _-]{1,30}$/', $categoryLine)) {
                throw new RuntimeException('Categories can use letters, numbers, spaces, - and _ only (up to 30 characters): "' . $categoryLine . '".');
            }
            if (!in_array(mb_strtolower($categoryLine), array_map('mb_strtolower', $newCategories), true)) {
                $newCategories[] = $categoryLine;
            }
        }
        if (count($newCategories) > 20) {
            throw new RuntimeException('Use at most 20 categories.');
        }

        $nextState = $brandingState;
        $nextState['app_name'] = $appName;
        $nextState['app_description'] = $appDescription;
        $nextState['default_theme'] = $defaultTheme;
        $nextState['uploads_enabled'] = $newUploadsEnabled;
        $nextState['max_upload_mb'] = $newMaxUploadMb;
        $nextState['maintenance_enabled'] = $newMaintenanceEnabled;
        $nextState['maintenance_message'] = $newMaintenanceMessage;
        $nextState['categories'] = $newCategories;

        $upload = $_FILES['site_logo'] ?? null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('The logo upload failed. Choose a PNG and try again.');
            }
            if ((int)$upload['size'] > 4 * 1024 * 1024) {
                throw new RuntimeException('The logo file must be 4 MB or smaller.');
            }
            if (!is_uploaded_file($upload['tmp_name'])) {
                throw new RuntimeException('The uploaded logo could not be validated.');
            }

            $imageInfo = getimagesize($upload['tmp_name']);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if ($imageInfo === false || $mime !== 'image/png' || ($imageInfo[2] ?? null) !== IMAGETYPE_PNG) {
                throw new RuntimeException('Only valid PNG image files are accepted.');
            }
            if ($imageInfo[0] < 128 || $imageInfo[1] < 128 || $imageInfo[0] > 2048 || $imageInfo[1] > 2048 || abs($imageInfo[0] - $imageInfo[1]) > 20) {
                throw new RuntimeException('Use a square logo between 128 × 128 and 2048 × 2048 pixels.');
            }

            $directory = __DIR__ . '/../uploads/site-branding';
            if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create the logo upload directory.');
            }
            $filename = bin2hex(random_bytes(16)) . '.png';
            $newLogoPath = 'uploads/site-branding/' . $filename;
            if (!move_uploaded_file($upload['tmp_name'], __DIR__ . '/../' . $newLogoPath)) {
                throw new RuntimeException('The uploaded logo could not be saved.');
            }
            $nextState['logo'] = $newLogoPath;
            $adminName = (string)($_SESSION['AdminSession'] ?? 'Site owner');
            $nextState['notification'] = [
                'message' => 'The site logo was updated by ' . $adminName . '.',
                'created_at' => date(DATE_ATOM),
            ];
        }

        try {
            vs_branding_save_state($nextState);
        } catch (Throwable $error) {
            if ($newLogoPath !== '' && is_file(__DIR__ . '/../' . $newLogoPath)) {
                unlink(__DIR__ . '/../' . $newLogoPath);
            }
            throw $error;
        }

        $brandingState = $nextState;
        $successMsg = $newLogoPath !== ''
            ? 'App settings saved and the new logo published. Admins can see the in-app notification.'
            : 'App settings saved.';
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

$appName = vs_branding_app_name($brandingState);
$appDescription = vs_branding_app_description($brandingState);
$defaultTheme = vs_branding_default_theme($brandingState);
$uploadsEnabled = vs_uploads_enabled($brandingState);
$maxUploadMb = vs_max_upload_mb($brandingState);
$maintenanceEnabled = !empty($brandingState['maintenance_enabled']);
$maintenanceMessage = (string)($brandingState['maintenance_message'] ?? '');
$categoriesText = implode("\n", vs_default_categories($brandingState));

require_once __DIR__ . '/header.php';
?>

<main class="admin-content p-3 p-lg-4">
  <div class="page-heading mb-4">
    <div class="page-heading-copy">
      <span class="page-icon"><i class="bi bi-sliders" aria-hidden="true"></i></span>
      <div>
        <p class="eyebrow mb-1">Admin controls</p>
        <h1 class="h3 mb-1">App settings</h1>
        <p class="text-muted mb-0">Manage platform branding and the default appearance for <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?>.</p>
      </div>
    </div>
  </div>

  <?php if ($successMsg !== ''): ?>
    <div class="alert alert-success" role="status"><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>
  <?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <section class="panel app-settings-panel">
    <div class="panel-header">
      <h2 class="h5 mb-1 section-title"><i class="bi bi-window-stack" aria-hidden="true"></i><span>Application identity</span></h2>
      <p class="text-muted mb-0">These values appear on the website and installable app. User theme choices are not overridden.</p>
    </div>

    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['vs_app_settings_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="save_app_settings">

      <div class="app-settings-logo">
        <img class="app-settings-logo-image" src="<?php echo htmlspecialchars(vs_branding_logo_url(), ENT_QUOTES, 'UTF-8'); ?>" alt="Current app logo" width="76" height="76">
        <div>
          <strong>Current app logo</strong>
          <p class="text-muted mb-0">Shown in site navigation, browser tabs, and the installable app.</p>
        </div>
      </div>

      <div class="mb-3">
        <label for="site_logo" class="form-label">Replace logo <span class="text-muted">(optional)</span></label>
        <input class="form-control" type="file" id="site_logo" name="site_logo" accept="image/png">
        <div class="form-text">PNG only; square image from 128 × 128 to 2048 × 2048 pixels; up to 4 MB.</div>
      </div>

      <div class="mb-3">
        <label for="app_name" class="form-label">App name</label>
        <input class="form-control" type="text" id="app_name" name="app_name" value="<?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?>" maxlength="50" required>
        <div class="form-text">Used in the site header, page titles, and when users install the app.</div>
      </div>

      <div class="mb-3">
        <label for="app_description" class="form-label">App description</label>
        <textarea class="form-control" id="app_description" name="app_description" rows="3" maxlength="180" required><?php echo htmlspecialchars($appDescription, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="form-text">A short description shown in browser and installable-app metadata.</div>
      </div>

      <div class="mb-4">
        <label for="default_theme" class="form-label">Default appearance</label>
        <select class="form-select" id="default_theme" name="default_theme">
          <option value="dark"<?php echo $defaultTheme === 'dark' ? ' selected' : ''; ?>>Dark</option>
          <option value="light"<?php echo $defaultTheme === 'light' ? ' selected' : ''; ?>>Light</option>
        </select>
        <div class="form-text">This is used only for visitors who have not selected a theme on their device.</div>
      </div>

      <hr class="my-4">
      <h3 class="h6 section-title mb-3"><i class="bi bi-cloud-arrow-up" aria-hidden="true"></i><span>Uploads</span></h3>

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="uploads_enabled" name="uploads_enabled" value="1"<?php echo $uploadsEnabled ? ' checked' : ''; ?>>
        <label class="form-check-label" for="uploads_enabled">Allow users to upload videos and shorts</label>
        <div class="form-text">Turn this off to stop all new uploads on the website. Videos you add in this admin panel still work.</div>
      </div>

      <div class="mb-3">
        <label for="max_upload_mb" class="form-label">Maximum upload size (MB)</label>
        <input class="form-control" type="number" id="max_upload_mb" name="max_upload_mb" min="1" max="4096" step="1" value="<?php echo (int)$maxUploadMb; ?>" required>
        <div class="form-text">
          Applies to video files uploaded from a computer. Links are not affected.
          PHP on this server currently allows files up to <strong><?php echo htmlspecialchars(ini_get('upload_max_filesize'), ENT_QUOTES, 'UTF-8'); ?></strong>
          (post limit <?php echo htmlspecialchars(ini_get('post_max_size'), ENT_QUOTES, 'UTF-8'); ?>),
          so the real limit is whichever is smaller. Raise <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini if you need more.
        </div>
      </div>

      <hr class="my-4">
      <h3 class="h6 section-title mb-3"><i class="bi bi-cone-striped" aria-hidden="true"></i><span>Maintenance mode</span></h3>

      <div class="form-check form-switch mb-3">
        <input class="form-check-input" type="checkbox" role="switch" id="maintenance_enabled" name="maintenance_enabled" value="1"<?php echo $maintenanceEnabled ? ' checked' : ''; ?>>
        <label class="form-check-label" for="maintenance_enabled">Put the website into maintenance mode</label>
        <div class="form-text">Visitors see the message below instead of the site. Signed-in admins can still use everything, and the admin panel and login page stay open.</div>
      </div>

      <div class="mb-4">
        <label for="maintenance_message" class="form-label">Maintenance message</label>
        <textarea class="form-control" id="maintenance_message" name="maintenance_message" rows="3" maxlength="300" placeholder="We are making some improvements. Please check back soon."><?php echo htmlspecialchars($maintenanceMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
        <div class="form-text">Up to 300 characters. Leave empty to use the default message.</div>
      </div>

      <button class="btn btn-primary" type="submit">Save app settings</button>
    </form>
  </section>
</main>

</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
</body>
</html>