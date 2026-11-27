<?php
/*
 * VibeStream admin panel - Site icons (OWNER ONLY).
 * The owner changes: the app logo, category icons, menu / explore / button icons,
 * the admin panel sidebar icons and the website sidebar icons.
 * Put this file in the adminpanel folder, together with site-icons-overview.php.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/admin-permissions.php';
vs_admin_enforce_route();
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
require_once __DIR__ . '/../sidebar-icons-store.php';
$csbFile = __DIR__ . '/../sidebar-custom-store.php';
$csbReady = is_file($csbFile);
if ($csbReady) {
    require_once $csbFile;
}
$acsbFile = __DIR__ . '/../admin-sidebar-custom-store.php';
$acsbReady = is_file($acsbFile);
if ($acsbReady) {
    require_once $acsbFile;
}

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

/* ---------- Icon guide download (.txt) ---------- */
if (($_GET['download'] ?? '') === 'guide') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="vibestream-icon-guide.txt"');
    echo "VibeStream icon guide\n=====================\n\n";
    echo "App logo              : PNG, square, 128 to 2048 px, max 4 MB (also used as the favicon)\n";
    echo "Admin / website menus : PNG, square, 24 to 1024 px, max 1 MB\n";
    echo "Category icons        : PNG / JPG / WEBP / GIF / SVG, square, under 512 KB\n";
    echo "Menu / Explore / Button icons : PNG / JPG / WEBP / GIF / SVG, square, under 512 KB\n\n";
    echo "Tips\n----\n- Use PNG for the best quality.\n- Square images (1:1) work best.\n- One-colour icons follow the theme colour; use \"original colours\" to keep multi-colour icons as they are.\n";
    exit;
}

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
    // vs_branding_save_state() returns nothing and throws when it cannot save,
    // so do not test its return value (that always looked like a failure and deleted the new logo).
    try {
        vs_branding_save_state($state);
    } catch (Throwable $saveError) {
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
    $scope = (string)($_POST['scope'] ?? '');
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
                vs_branding_save_state($state); // throws a RuntimeException by itself when it cannot save
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
            case 'si_icon':      // admin panel / website sidebar icons (sidebar-icons-store.php)
                vs_si_flash('success', vs_si_set($scope, $name, $_FILES['icon'] ?? [], !empty($_POST['keep'])));
                break;
            case 'si_icon_remove':
                vs_si_flash('success', vs_si_reset($scope, $name));
                break;
            case 'si_icon_hide':
                vs_si_flash('success', vs_si_hide($scope, $name));
                break;
            case 'si_icon_show':
                vs_si_flash('success', vs_si_show($scope, $name));
                break;
            case 'si_mode':
                vs_si_set_mode($scope, $name, !empty($_POST['keep']));
                vs_si_flash('success', !empty($_POST['keep']) ? 'The icon now keeps its original colours.' : 'The icon now follows the theme colour.');
                break;
            case 'csb_add_section':
            case 'csb_delete_section':
            case 'csb_add_item':
            case 'csb_item_icon':
            case 'csb_delete_item':
                if (!$csbReady) {
                    throw new RuntimeException('Upload sidebar-custom-store.php to the main site folder first.');
                }
                if ($act === 'csb_add_section') {
                    vs_csb_add_section((string)($_POST['title'] ?? ''), (string)($_POST['place'] ?? ''));
                    vs_si_flash('success', 'Section added. It shows on the website as soon as it has a menu item.');
                } elseif ($act === 'csb_delete_section') {
                    vs_csb_delete_section($name);
                    vs_si_flash('success', 'Section and its menu items deleted.');
                } elseif ($act === 'csb_add_item') {
                    vs_csb_add_item((string)($_POST['label'] ?? ''), (string)($_POST['href'] ?? ''), (string)($_POST['section'] ?? ''), $_FILES['icon'] ?? []);
                    vs_si_flash('success', 'Menu item added to the website sidebar.');
                } elseif ($act === 'csb_item_icon') {
                    vs_csb_set_item_icon($name, $_FILES['icon'] ?? []);
                    vs_si_flash('success', 'Menu item icon updated.');
                } else {
                    vs_csb_delete_item($name);
                    vs_si_flash('success', 'Menu item deleted.');
                }
                break;
            case 'acsb_add_section':
            case 'acsb_delete_section':
            case 'acsb_add_item':
            case 'acsb_item_icon':
            case 'acsb_delete_item':
            case 'acsb_update_item':
            case 'acsb_move_item':
            case 'acsb_move_section':
            case 'acsb_rename_section':
            case 'acsb_reorder_items':
            case 'acsb_reorder_sections':
            case 'acsb_reset_all':
                if (!$acsbReady) {
                    throw new RuntimeException('Upload admin-sidebar-custom-store.php to the main site folder first.');
                }
                if ($act === 'acsb_add_section') {
                    vs_acsb_add_section((string)($_POST['title'] ?? ''), (string)($_POST['place'] ?? ''));
                    vs_si_flash('success', 'Section added. It shows in the admin sidebar as soon as it has a menu item.');
                } elseif ($act === 'acsb_delete_section') {
                    vs_acsb_delete_section($name);
                    vs_si_flash('success', 'Section and its menu items deleted.');
                } elseif ($act === 'acsb_add_item') {
                    vs_acsb_add_item((string)($_POST['label'] ?? ''), (string)($_POST['href'] ?? ''), (string)($_POST['section'] ?? ''), $_FILES['icon'] ?? [], ['owner' => !empty($_POST['owner']), 'newtab' => !empty($_POST['newtab']), 'emails' => (string)($_POST['emails'] ?? '')]);
                    vs_si_flash('success', 'Menu item added to the admin sidebar.');
                } elseif ($act === 'acsb_item_icon') {
                    vs_acsb_set_item_icon($name, $_FILES['icon'] ?? []);
                    vs_si_flash('success', 'Menu item icon updated.');
                } elseif ($act === 'acsb_update_item') {
                    vs_acsb_update_item($name, (string)($_POST['label'] ?? ''), (string)($_POST['href'] ?? ''), (string)($_POST['section'] ?? ''), ['owner' => !empty($_POST['owner']), 'newtab' => !empty($_POST['newtab']), 'emails' => (string)($_POST['emails'] ?? '')]);
                    vs_si_flash('success', 'Menu item saved.');
                } elseif ($act === 'acsb_move_item') {
                    vs_acsb_move_item($name, (string)($_POST['dir'] ?? ''));
                    vs_si_flash('success', 'Order updated.');
                } elseif ($act === 'acsb_move_section') {
                    vs_acsb_move_section($name, (string)($_POST['dir'] ?? ''));
                    vs_si_flash('success', 'Order updated.');
                } elseif ($act === 'acsb_rename_section') {
                    vs_acsb_rename_section($name, (string)($_POST['title'] ?? ''));
                    vs_si_flash('success', 'Section renamed.');
                } elseif ($act === 'acsb_reorder_items' || $act === 'acsb_reorder_sections') {
                    $acsbIds = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('strval', $_POST['ids']) : [];
                    vs_acsb_reorder($act === 'acsb_reorder_items' ? 'items' : 'sections', $acsbIds);
                    vs_si_flash('success', 'Order updated.');
                } elseif ($act === 'acsb_reset_all') {
                    vs_acsb_reset_all();
                    vs_si_flash('success', 'All custom admin menu sections and items were removed.');
                } else {
                    vs_acsb_delete_item($name);
                    vs_si_flash('success', 'Menu item deleted.');
                }
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
$siMap = vs_si_load();
$siItems = vs_si_items();
$csb = ($csbReady && function_exists('vs_csb_load')) ? vs_csb_load() : ['sections' => [], 'items' => []];
$csbChoices = ($csbReady && function_exists('vs_csb_section_choices')) ? vs_csb_section_choices($csb) : [];
$acsb = ($acsbReady && function_exists('vs_acsb_load')) ? vs_acsb_load() : ['sections' => [], 'items' => []];
$acsbChoices = ($acsbReady && function_exists('vs_acsb_section_choices')) ? vs_acsb_section_choices($acsb) : [];
$acsbPlaces = ($acsbReady && function_exists('vs_acsb_places')) ? vs_acsb_places() : [];
$bust = time();

/** Preview of a sidebar icon (custom PNG or the built-in Bootstrap icon). */
function vs_si_preview(array $siMap, string $scope, string $key, string $defaultClass): string
{
    $entry = $siMap[$scope][$key] ?? null;
    if (!$entry) {
        if (function_exists('vs_si_is_hidden') && vs_si_is_hidden($scope, $key)) {
            return '<i class="bi bi-eye-slash" style="opacity:.45" aria-hidden="true"></i>';
        }
        return '<i class="' . vs_e($defaultClass) . '" aria-hidden="true"></i>';
    }
    $url = '../' . $entry['file'] . '?v=' . (int)@filemtime(__DIR__ . '/../' . $entry['file']);
    if (($entry['mode'] ?? 'mask') === 'color') {
        return '<img src="' . vs_e($url) . '" alt="">';
    }
    $mask = "url('" . $url . "') center/contain no-repeat";
    return '<span aria-hidden="true" style="display:block;width:70%;height:70%;background-color:currentColor;-webkit-mask:' . vs_e($mask) . ';mask:' . vs_e($mask) . '"></span>';
}

include "header.php";
if (function_exists('vs_print_styles')) {
    vs_print_styles();
}

/** Small upload button: choose a file and it uploads straight away. $extra = more hidden fields. */
function vs_si_upload_form(string $csrf, string $action, string $name, string $label, array $extra = [], string $accept = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml'): void
{
    ?>
    <form method="post" enctype="multipart/form-data" class="d-inline">
      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo vs_e($action); ?>">
      <input type="hidden" name="name" value="<?php echo vs_e($name); ?>">
      <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?php echo vs_e((string)$k); ?>" value="<?php echo vs_e((string)$v); ?>"><?php endforeach; ?>
      <label class="vn-iconbtn mb-0" style="cursor:pointer" title="<?php echo vs_e($label); ?>">
        <i class="bi bi-upload" aria-hidden="true"></i><span class="visually-hidden"><?php echo vs_e($label); ?></span>
        <input type="file" name="icon" accept="<?php echo vs_e($accept); ?>" hidden onchange="this.form.submit()">
      </label>
    </form>
    <?php
}

/** Small button that posts an action (remove / reset / toggle). */
function vs_si_button_form(string $csrf, string $action, string $name, string $title, string $iconClass, array $extra = [], string $confirm = '', bool $danger = false): void
{
    ?>
    <form method="post" class="d-inline"<?php echo $confirm !== '' ? ' onsubmit="return confirm(\'' . vs_e($confirm) . '\');"' : ''; ?>>
      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
      <input type="hidden" name="action" value="<?php echo vs_e($action); ?>">
      <input type="hidden" name="name" value="<?php echo vs_e($name); ?>">
      <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?php echo vs_e((string)$k); ?>" value="<?php echo vs_e((string)$v); ?>"><?php endforeach; ?>
      <button type="submit" class="vn-iconbtn<?php echo $danger ? ' danger' : ''; ?>" title="<?php echo vs_e($title); ?>"><i class="bi <?php echo vs_e($iconClass); ?>" aria-hidden="true"></i><span class="visually-hidden"><?php echo vs_e($title); ?></span></button>
    </form>
    <?php
}

/** One full section listing every icon of a sidebar scope ('admin' or 'site'). */
function vs_si_scope_section(string $csrf, array $siMap, array $siItems, string $scope, string $id, string $title, string $help, string $iconClass): void
{
    $items = $siItems[$scope] ?? [];
    ?>
    <section class="panel" aria-label="<?php echo vs_e($title); ?>" id="<?php echo vs_e($id); ?>">
      <h2 class="vn-card-title"><span class="vn-ico"><i class="bi <?php echo vs_e($iconClass); ?>" aria-hidden="true"></i></span><?php echo vs_e($title); ?> <span class="si-count-pill"><?php echo count($items); ?></span></h2>
      <p class="vn-sub mb-3"><?php echo vs_e($help); ?> Square PNG, 24 to 1024 px, under 1 MB.</p>
      <div class="si-tiles" data-si-limit="6">
        <?php foreach ($items as $key => [$label, $defClass]):
            $entry = $siMap[$scope][$key] ?? null;
            $isColor = $entry && ($entry['mode'] ?? '') === 'color';
            $isHidden = !$entry && function_exists('vs_si_is_hidden') && vs_si_is_hidden($scope, (string)$key);
            $base = ['scope' => $scope, 'keep' => $isColor ? '1' : ''];
        ?>
          <div class="si-tile">
            <div class="si-box"><div class="si-round"><?php echo vs_si_preview($siMap, $scope, (string)$key, $defClass); ?></div></div>
            <div class="si-name" title="<?php echo vs_e($label); ?>"><?php echo vs_e($label); ?></div>
            <div class="si-meta"><?php echo $entry ? ($isColor ? 'Custom &middot; original colours' : 'Custom icon') : ($isHidden ? 'Icon hidden' : 'Built-in icon'); ?></div>
            <div class="si-actions">
              <?php vs_si_upload_form($csrf, 'si_icon', (string)$key, $entry ? 'Change icon' : 'Upload icon', $base, 'image/png'); ?>
              <?php if ($entry):
                  vs_si_button_form($csrf, 'si_mode', (string)$key, $isColor ? 'Follow the theme colour' : 'Keep original colours', $isColor ? 'bi-palette-fill' : 'bi-palette', ['scope' => $scope, 'keep' => $isColor ? '' : '1']);
                  vs_si_button_form($csrf, 'si_icon_remove', (string)$key, 'Reset to built-in icon', 'bi-trash', ['scope' => $scope], 'Go back to the built-in icon?', true);
              elseif ($isHidden):
                  vs_si_button_form($csrf, 'si_icon_show', (string)$key, 'Show the icon again', 'bi-eye', ['scope' => $scope]);
              else:
                  vs_si_button_form($csrf, 'si_icon_hide', (string)$key, 'Hide this icon', 'bi-trash', ['scope' => $scope], 'Hide this icon from the menu? The link and its text stay. You can show it again any time.', true);
              endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php
}

$groupHelp = [
    'Menu'    => 'The icons in the main side menu.',
    'Explore' => 'The icons in the Explore list of the side menu.',
];
?>
      <style>
        /* Red-and-black dashboard styling, with a matching light palette. */
        html[data-theme="dark"] .si-page{--si-glow:rgba(255,25,35,.28);--si-ring:rgba(255,42,50,.52);
          --vn-card:#0c0a0a;--vn-card2:#120d0d;--vn-line:rgba(255,42,50,.48);--vn-line-soft:rgba(255,42,50,.25);
          --vn-text:#f6f1f1;--vn-strong:#fff;--vn-muted:#b8a8aa;--vn-accent:#ff2b3a;--vn-accent2:#ff2b3a;
          --vn-iconbox:rgba(255,42,50,.13);--vn-btn-bg:rgba(255,42,50,.10);--vn-btn-hover:rgba(255,42,50,.2);--vn-btn-text:#fff;--vn-input-bg:#100909;--vn-placeholder:#9d8587}
        html[data-theme="light"] .si-page{--si-glow:rgba(205,24,32,.11);--si-ring:rgba(205,24,32,.32);
          --vn-card:#fff;--vn-card2:#fff5f5;--vn-line:rgba(205,24,32,.3);--vn-line-soft:rgba(205,24,32,.18);
          --vn-text:#291a1a;--vn-strong:#291a1a;--vn-muted:#766565;--vn-accent:#c91922;--vn-accent2:#e3262d;
          --vn-iconbox:rgba(205,24,32,.08);--vn-btn-bg:rgba(205,24,32,.06);--vn-btn-hover:rgba(205,24,32,.12);--vn-btn-text:#4b3030;--vn-input-bg:#fff;--vn-placeholder:#897777}
        .si-page{background:radial-gradient(850px 380px at 100% 0,rgba(255,26,32,.10),transparent 60%),var(--vn-bg,#090909)!important;color:var(--vn-text)}
        .si-page .panel,.si-page .page-heading{border-radius:16px;border:1px solid var(--si-ring)!important;
          box-shadow:0 12px 36px rgba(0,0,0,.24),0 0 22px var(--si-glow)!important}
        html[data-theme="dark"] .admin-shell .si-page .panel{background:linear-gradient(145deg,#120909,#080808)!important;color:#f6f1f1!important}
        html[data-theme="light"] .admin-shell .si-page .panel{background:linear-gradient(145deg,#fff,#fffafa)!important;color:#291a1a!important}
        html[data-theme="dark"] .admin-shell .si-page .page-heading{background:radial-gradient(520px 230px at 84% 0,rgba(255,30,40,.22),transparent 72%),linear-gradient(115deg,#170506,#090909 62%,#180506)!important}
        html[data-theme="light"] .admin-shell .si-page .page-heading{background:radial-gradient(520px 230px at 84% 0,rgba(255,30,40,.12),transparent 72%),linear-gradient(115deg,#fff,#fff8f8 62%,#fff0f0)!important}
        .si-page .page-heading h1{color:var(--vn-strong)!important}
        .si-page .page-heading .text-muted{color:var(--vn-muted)!important}
        .si-page .page-heading .btn-outline-secondary{color:var(--vn-text)!important;border-color:var(--si-ring)!important;background:rgba(255,35,45,.06)!important}
        .si-page .vn-card-title{font-size:1.1rem}
        .si-page .vn-card-title .vn-ico{width:42px;height:42px;border-radius:12px;font-size:1.25rem}
        .si-page .vn-card-title .vn-ico{color:#fff;background:linear-gradient(145deg,#ff343c,#a50e16);border-color:rgba(255,70,76,.55);box-shadow:0 0 16px rgba(255,30,40,.24)}
        .si-count-pill{font-size:.7rem;font-weight:700;padding:2px 9px;border-radius:999px;background:rgba(255,42,50,.14);color:#ff8589;border:1px solid rgba(255,70,76,.42)}

        /* Icon tiles */
        .si-tiles{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;padding:14px;border:1px solid var(--vn-line-soft,rgba(128,128,128,.25));border-radius:14px;background:rgba(255,35,45,.035)}
        @media (max-width:1399px){.si-tiles{grid-template-columns:repeat(3,minmax(0,1fr))}}
        @media (max-width:575px){.si-tiles{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .si-tile{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;min-width:0;color:inherit;text-decoration:none}
        .si-box{width:100%;aspect-ratio:1/0.82;max-width:130px;border-radius:12px;display:flex;align-items:center;justify-content:center;
          background:linear-gradient(145deg,var(--vn-card2),rgba(255,35,45,.025));border:1px solid var(--si-ring);box-shadow:0 0 14px var(--si-glow),inset 0 0 18px rgba(255,35,45,.06);
          overflow:hidden;transition:border-color .15s,box-shadow .15s}
        .si-box .si-round{width:64%;aspect-ratio:1/1;border-radius:50%;display:flex;align-items:center;justify-content:center;
          background:var(--vn-iconbox,rgba(40,70,170,.4));color:var(--vn-accent2,#b50d16);font-size:1.7rem;overflow:hidden}
        .si-box img{max-width:70%;max-height:70%;object-fit:contain;display:block}
        .si-box .si-round img{max-width:64%;max-height:64%}
        .si-box>i{font-size:2.2rem;color:var(--vn-accent2,#b50d16)}
        .si-name{font-weight:600;font-size:.86rem;color:var(--vn-strong,#fff);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .si-meta{font-size:.74rem;color:var(--vn-muted,#8fa3d6);margin-top:-3px}
        .si-pill{font-size:.66rem;font-weight:700;padding:2px 12px;border-radius:5px;background:rgba(255,42,50,.14);color:#ff8589;border:1px solid rgba(255,70,76,.42)}
        .si-tile .si-actions{display:flex;gap:6px;justify-content:center}
        .si-btn-primary{display:inline-flex;align-items:center;gap:8px;padding:10px 26px;border-radius:9px;border:1px solid rgba(255,90,95,.55);font-weight:600;cursor:pointer;color:#fff;
          background:linear-gradient(135deg,#ff343c,#ad0c13);box-shadow:0 4px 16px rgba(255,30,40,.24)}

        /* Detail grids (category / menu / button icons) */
        .vn-icontiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(138px,1fr));gap:10px}
        .vn-icontile{display:flex;flex-direction:column;align-items:center;text-align:center;gap:6px;padding:14px 8px;border-radius:12px;background:var(--vn-card2,rgba(128,128,128,.1));border:1px solid var(--vn-line-soft,rgba(128,128,128,.25));min-width:0}
        .vn-icontile:hover{border-color:#ff343c}
        .vn-prev{width:64px;height:64px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:var(--vn-iconbox,rgba(106,61,246,.15));border:1px solid var(--vn-line,rgba(128,128,128,.3));color:var(--vn-accent,#6a3df6);font-size:1.6rem;overflow:hidden;flex:0 0 auto;box-shadow:0 0 14px rgba(255,40,50,.25)}
        .vn-prev img,.vn-pv img{display:block;width:100%!important;height:100%!important;max-width:100%!important;max-height:100%!important;object-fit:contain}
        .vn-prev svg{max-width:100%;max-height:100%}
        .vn-t-name{font-weight:600;font-size:.86rem;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .vn-t-meta{font-size:.72rem;opacity:.7}
        .vn-icontile .vn-actions{display:flex;gap:6px;justify-content:center}
        .vn-iconbtn{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;cursor:pointer}

        /* Menu builders */
        .vn-pv{width:42px;height:42px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;background:var(--vn-iconbox,rgba(106,61,246,.15));border:1px solid var(--vn-line,rgba(128,128,128,.3));color:var(--vn-accent2,#b50d16);overflow:hidden;box-shadow:0 0 10px rgba(255,40,50,.22)}
        .si-rail-h{font-weight:600;color:var(--vn-accent2,#b50d16);margin:10px 0 6px}
        .si-forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
        .si-formbox{display:flex;flex-direction:column;gap:10px;padding:14px;border:1px solid var(--vn-line-soft,rgba(128,128,128,.25));border-radius:12px;background:rgba(255,35,45,.04)}
        .si-h3{font-size:.95rem;font-weight:600;margin:0}
        .si-lab{display:flex;flex-direction:column;gap:4px;font-size:.78rem;color:var(--vn-muted,#8fa3d6)}
        .si-rows{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:8px}
        .si-row.si-drag{opacity:.45}
        .si-row{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:10px;border:1px solid var(--vn-line-soft,rgba(128,128,128,.25));background:rgba(255,35,45,.035)}
        .si-row-main{flex:1;min-width:0;display:flex;flex-direction:column}
        .si-row-main b{font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .si-row-main small{font-size:.74rem;opacity:.7;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

        /* Tabs */
        .si-tabbar{display:none;gap:6px;padding:8px;margin:16px 0 0;border:1px solid var(--si-ring);border-radius:14px;background:rgba(255,35,45,.04);overflow-x:auto;white-space:nowrap;scrollbar-width:thin}
        .si-js .si-tabbar{display:flex}
        .si-tab{display:inline-flex;align-items:center;gap:8px;flex:0 0 auto;padding:10px 18px;border:0;border-radius:13px;background:transparent;color:inherit;font-weight:700;font-size:.95rem;cursor:pointer;transition:background .15s ease,box-shadow .15s ease}
        .si-tab i{font-size:1rem;opacity:.85}
        .si-tab:hover{background:rgba(255,42,50,.10)}
        .si-tab:focus-visible{outline:2px solid #ff343c;outline-offset:2px}
        .si-tab.si-on{color:#fff;background:linear-gradient(135deg,#ff343c,#ad0c13);box-shadow:0 6px 18px rgba(255,30,40,.3),inset 0 0 0 1px rgba(255,255,255,.18)}
        .si-tab.si-on i{opacity:1}
        .si-js .si-pane:not(.si-on){display:none}
        .si-pane>.panel{margin-bottom:16px;margin-top:16px}

        /* Show more / less */
        .si-more-wrap{display:flex;justify-content:center;margin-top:12px}
        .si-more{display:inline-flex;align-items:center;gap:8px;padding:8px 22px;border-radius:9px;font-weight:600;font-size:.86rem;cursor:pointer;
          color:var(--vn-btn-text,#dbe5ff);background:transparent;border:1px solid var(--si-ring)}
        .si-more:hover{background:var(--vn-btn-hover,rgba(60,90,200,.45))}
        .si-more .si-count{opacity:.7;font-weight:500}
      </style>

      <main class="dashboard-content si-page">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <div class="page-heading d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-palette-fill" aria-hidden="true"></i></span>
              <div>
                <h1 class="h3 mb-1">Site <span class="si-red">Icons</span></h1>
                <p class="text-muted mb-0">Customize your app, admin panel, website sidebar, category, menu, explore and button icons.</p>
              </div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <span class="vn-owner-badge"><i class="bi bi-gem" aria-hidden="true"></i> Owner Only</span>
              <a class="btn btn-outline-secondary btn-sm" href="site-icons.php?download=guide"><i class="bi bi-download" aria-hidden="true"></i> Download Guide</a>
              <a class="btn btn-outline-secondary btn-sm" href="index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Dashboard</a>
            </div>
          </div>

          <?php if (is_array($flash)): ?>
            <div class="alert alert-<?php echo vs_e($flash['type']); ?> mt-3 mb-0" role="status"><?php echo vs_e($flash['text']); ?></div>
          <?php endif; ?>

          <div class="si-tabbar" role="tablist" aria-label="Site icon sections">
            <button type="button" class="si-tab si-on" role="tab" data-pane="overview" aria-selected="true"><i class="bi bi-globe2" aria-hidden="true"></i> Overview</button>
            <button type="button" class="si-tab" role="tab" data-pane="website" aria-selected="false"><i class="bi bi-window-sidebar" aria-hidden="true"></i> Website</button>
            <button type="button" class="si-tab" role="tab" data-pane="admin" aria-selected="false"><i class="bi bi-shield-check" aria-hidden="true"></i> Admin Panel</button>
          </div>

          <div class="vn-layout">
            <div class="vn-main">

              <!-- ================= OVERVIEW (new design) ================= -->
              <?php include __DIR__ . '/site-icons-overview.php'; ?>

              <div class="si-pane" id="si-pane-admin" role="tabpanel" data-pane="admin">
              <!-- ================= ADMIN PANEL ICONS (all) ================= -->
              <?php vs_si_scope_section($csrf, $siMap, $siItems, 'admin', 'sec-admin', 'Admin Panel Icons', 'Every icon in this admin panel: the sidebar, the top bar and the profile dropdown.', 'bi-shield-check'); ?>

              <!-- ================= CUSTOM ADMIN SIDEBAR MENU ================= -->
              <section class="panel" aria-label="Custom admin sidebar menu" id="sec-admin-custom">
                <h2 class="vn-card-title"><span class="vn-ico"><i class="bi bi-ui-checks-grid" aria-hidden="true"></i></span>Admin Sidebar Menu <span class="si-count-pill"><?php echo count($acsb['items']); ?></span></h2>
                <p class="vn-sub mb-3">Add your own sections and pages to the admin panel sidebar. Every admin sees them. Icons: PNG, JPG, WEBP or GIF under 512 KB.</p>
                <?php if (!$acsbReady): ?>
                  <div class="alert alert-warning mb-0">Upload <code>admin-sidebar-custom-store.php</code> to the main site folder (next to <code>sidebar-icons-store.php</code>) to use this section.</div>
                <?php else: ?>
                  <div class="si-forms">
                    <form method="post" class="si-formbox">
                      <h3 class="si-h3"><i class="bi bi-folder-plus" aria-hidden="true"></i> Add a section</h3>
                      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="action" value="acsb_add_section">
                      <label class="si-lab">Section name<input type="text" name="title" maxlength="40" required class="form-control form-control-sm" placeholder="e.g. Tools"></label>
                      <label class="si-lab">Where<select name="place" class="form-select form-select-sm"><?php foreach ($acsbPlaces as $pk => $pl): ?><option value="<?php echo vs_e((string)$pk); ?>"<?php echo $pk === 'before_navigation' ? ' selected' : ''; ?>><?php echo vs_e($pl); ?></option><?php endforeach; ?></select></label>
                      <button type="submit" class="si-btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add section</button>
                    </form>
                    <form method="post" enctype="multipart/form-data" class="si-formbox">
                      <h3 class="si-h3"><i class="bi bi-link-45deg" aria-hidden="true"></i> Add a page / menu item</h3>
                      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="action" value="acsb_add_item">
                      <label class="si-lab">Name<input type="text" name="label" maxlength="40" required class="form-control form-control-sm" placeholder="e.g. Reports"></label>
                      <label class="si-lab">Link<input type="text" name="href" maxlength="300" required class="form-control form-control-sm" placeholder="reports.php or https://..."></label>
                      <label class="si-lab">Section<select name="section" class="form-select form-select-sm"><?php foreach ($acsbChoices as $ck => $cl): ?><option value="<?php echo vs_e((string)$ck); ?>"><?php echo vs_e($cl); ?></option><?php endforeach; ?></select></label>
                      <label class="si-lab">Icon (optional)<input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control form-control-sm"></label>
                      <label class="si-lab">Only these admins (optional)<input type="text" name="emails" maxlength="500" class="form-control form-control-sm" placeholder="a@site.com, b@site.com (empty = all admins)"></label>
                      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="newtab" value="1" id="acsbTabNew"><label class="form-check-label" for="acsbTabNew">Open in a new tab</label></div>
                      <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="owner" value="1" id="acsbOwnerNew"><label class="form-check-label" for="acsbOwnerNew">Only the site owner can see this</label></div>
                      <button type="submit" class="si-btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add item</button>
                    </form>
                  </div>

                  <?php if ($acsb['sections']): ?>
                    <p class="si-rail-h">Your sections</p>
                    <ul class="si-rows" data-acsb-sort="sections">
                      <?php foreach ($acsb['sections'] as $cs): ?>
                        <li class="si-row" data-id="<?php echo vs_e((string)$cs['id']); ?>" style="flex-wrap:wrap">
                          <span class="si-grip" title="Drag to reorder" style="cursor:grab;opacity:.6"><i class="bi bi-grip-vertical" aria-hidden="true"></i></span>
                          <span class="si-row-main"><b><?php echo vs_e($cs['title']); ?></b><small><?php echo vs_e($acsbPlaces[$cs['place']] ?? ''); ?></small></span>
                          <?php vs_si_button_form($csrf, 'acsb_move_section', (string)$cs['id'], 'Move up', 'bi-arrow-up', ['dir' => 'up']); ?>
                          <?php vs_si_button_form($csrf, 'acsb_move_section', (string)$cs['id'], 'Move down', 'bi-arrow-down', ['dir' => 'down']); ?>
                          <?php vs_si_button_form($csrf, 'acsb_delete_section', (string)$cs['id'], 'Delete section and its items', 'bi-trash', [], 'Delete this section and all its menu items?', true); ?>
                          <details class="si-edit" style="flex:0 0 100%">
                            <summary class="small" style="cursor:pointer"><i class="bi bi-pencil" aria-hidden="true"></i> Rename</summary>
                            <form method="post" class="si-formbox mt-2">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="acsb_rename_section">
                              <input type="hidden" name="name" value="<?php echo vs_e((string)$cs['id']); ?>">
                              <label class="si-lab">Section name<input type="text" name="title" maxlength="40" required class="form-control form-control-sm" value="<?php echo vs_e($cs['title']); ?>"></label>
                              <button type="submit" class="si-btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save name</button>
                            </form>
                          </details>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>

                  <?php if ($acsb['sections'] || $acsb['items']): ?>
                    <div class="d-flex align-items-center gap-2 mt-3">
                      <?php vs_si_button_form($csrf, 'acsb_reset_all', 'all', 'Reset all admin menu items', 'bi-arrow-counterclockwise', [], 'Remove ALL your custom admin sections and menu items? This cannot be undone.', true); ?>
                      <span class="small">Reset all admin menu items</span>
                    </div>
                  <?php endif; ?>
                  <p class="si-rail-h">Your admin menu items</p>
                  <?php if (!$acsb['items']): ?>
                    <p class="text-muted mb-0">No custom admin menu items yet.</p>
                  <?php else: ?>
                    <ul class="si-rows" data-si-limit="4" data-acsb-sort="items">
                      <?php foreach ($acsb['items'] as $it): ?>
                        <li class="si-row" data-id="<?php echo vs_e((string)$it['id']); ?>" style="flex-wrap:wrap">
                          <span class="si-grip" title="Drag to reorder" style="cursor:grab;opacity:.6"><i class="bi bi-grip-vertical" aria-hidden="true"></i></span>
                          <span class="vn-pv"><?php if ($it['icon'] !== ''): ?><img src="<?php echo vs_e(vs_acsb_icon_url($it['icon'])); ?>" alt=""><?php else: ?><i class="bi bi-link-45deg" aria-hidden="true"></i><?php endif; ?></span>
                          <span class="si-row-main"><b><?php echo vs_e($it['label']); ?><?php if (!empty($it['owner'])): ?> <span class="si-pill">Owner only</span><?php elseif (!empty($it['emails'])): ?> <span class="si-pill">Limited</span><?php endif; ?></b><small><?php echo vs_e($it['href']); ?> &middot; <?php echo vs_e($acsbChoices[$it['section']] ?? $it['section']); ?></small></span>
                          <?php vs_si_button_form($csrf, 'acsb_move_item', (string)$it['id'], 'Move up', 'bi-arrow-up', ['dir' => 'up']); ?>
                          <?php vs_si_button_form($csrf, 'acsb_move_item', (string)$it['id'], 'Move down', 'bi-arrow-down', ['dir' => 'down']); ?>
                          <?php vs_si_upload_form($csrf, 'acsb_item_icon', (string)$it['id'], $it['icon'] !== '' ? 'Change icon' : 'Add icon', [], 'image/png,image/jpeg,image/webp,image/gif'); ?>
                          <?php vs_si_button_form($csrf, 'acsb_delete_item', (string)$it['id'], 'Delete menu item', 'bi-trash', [], 'Delete this menu item?', true); ?>
                          <details class="si-edit" style="flex:0 0 100%">
                            <summary class="small" style="cursor:pointer"><i class="bi bi-pencil" aria-hidden="true"></i> Edit name, link, section</summary>
                            <form method="post" class="si-formbox mt-2">
                              <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                              <input type="hidden" name="action" value="acsb_update_item">
                              <input type="hidden" name="name" value="<?php echo vs_e((string)$it['id']); ?>">
                              <label class="si-lab">Name<input type="text" name="label" maxlength="40" required class="form-control form-control-sm" value="<?php echo vs_e($it['label']); ?>"></label>
                              <label class="si-lab">Link<input type="text" name="href" maxlength="300" required class="form-control form-control-sm" value="<?php echo vs_e($it['href']); ?>"></label>
                              <label class="si-lab">Section<select name="section" class="form-select form-select-sm"><?php foreach ($acsbChoices as $ck => $cl): ?><option value="<?php echo vs_e((string)$ck); ?>"<?php echo (string)$ck === (string)$it['section'] ? ' selected' : ''; ?>><?php echo vs_e($cl); ?></option><?php endforeach; ?></select></label>
                              <label class="si-lab">Only these admins (optional)<input type="text" name="emails" maxlength="500" class="form-control form-control-sm" value="<?php echo vs_e(implode(', ', (array)($it['emails'] ?? []))); ?>" placeholder="a@site.com, b@site.com (empty = all admins)"></label>
                              <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="newtab" value="1" id="acsbTab<?php echo vs_e((string)$it['id']); ?>"<?php echo !empty($it['newtab']) ? ' checked' : ''; ?>><label class="form-check-label" for="acsbTab<?php echo vs_e((string)$it['id']); ?>">Open in a new tab</label></div>
                              <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="owner" value="1" id="acsbOwn<?php echo vs_e((string)$it['id']); ?>"<?php echo !empty($it['owner']) ? ' checked' : ''; ?>><label class="form-check-label" for="acsbOwn<?php echo vs_e((string)$it['id']); ?>">Only the site owner can see this</label></div>
                              <button type="submit" class="si-btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Save changes</button>
                            </form>
                          </details>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                <?php endif; ?>
              </section>

              </div>

              <div class="si-pane" id="si-pane-website" role="tabpanel" data-pane="website">
              <!-- ================= WEBSITE SIDEBAR ICONS (all) ================= -->
              <?php vs_si_scope_section($csrf, $siMap, $siItems, 'site', 'sec-website-sidebar', 'Website Sidebar Icons', 'Every icon in the website sidebar and the website profile dropdown.', 'bi-layout-sidebar'); ?>

              <!-- ================= CUSTOM SIDEBAR MENU ================= -->
              <section class="panel" aria-label="Custom sidebar menu" id="sec-custom">
                <h2 class="vn-card-title"><span class="vn-ico"><i class="bi bi-menu-button-wide" aria-hidden="true"></i></span>Website Sidebar Menu <span class="si-count-pill"><?php echo count($csb['items']); ?></span></h2>
                <p class="vn-sub mb-3">Add your own sections and menu items to the website sidebar. They show for every visitor. Icons: PNG, JPG, WEBP or GIF under 512 KB.</p>
                <?php if (!$csbReady): ?>
                  <div class="alert alert-warning mb-0">Upload <code>sidebar-custom-store.php</code> to the main site folder (next to <code>sidebar-navigation.php</code>) to use this section.</div>
                <?php else: ?>
                  <div class="si-forms">
                    <form method="post" class="si-formbox">
                      <h3 class="si-h3"><i class="bi bi-folder-plus" aria-hidden="true"></i> Add a section</h3>
                      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="action" value="csb_add_section">
                      <label class="si-lab">Section name<input type="text" name="title" maxlength="40" required class="form-control form-control-sm" placeholder="e.g. Community"></label>
                      <label class="si-lab">Where<select name="place" class="form-select form-select-sm"><option value="before_explore">Above Explore</option><option value="bottom">At the bottom</option></select></label>
                      <button type="submit" class="si-btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add section</button>
                    </form>
                    <form method="post" enctype="multipart/form-data" class="si-formbox">
                      <h3 class="si-h3"><i class="bi bi-link-45deg" aria-hidden="true"></i> Add a menu item</h3>
                      <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
                      <input type="hidden" name="action" value="csb_add_item">
                      <label class="si-lab">Name<input type="text" name="label" maxlength="40" required class="form-control form-control-sm" placeholder="e.g. About us"></label>
                      <label class="si-lab">Link<input type="text" name="href" maxlength="300" required class="form-control form-control-sm" placeholder="about.php or https://..."></label>
                      <label class="si-lab">Section<select name="section" class="form-select form-select-sm"><?php foreach ($csbChoices as $ck => $cl): ?><option value="<?php echo vs_e((string)$ck); ?>"><?php echo vs_e($cl); ?></option><?php endforeach; ?></select></label>
                      <label class="si-lab">Icon (optional)<input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif" class="form-control form-control-sm"></label>
                      <button type="submit" class="si-btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add item</button>
                    </form>
                  </div>

                  <?php if ($csb['sections']): ?>
                    <p class="si-rail-h">Your sections</p>
                    <ul class="si-rows">
                      <?php foreach ($csb['sections'] as $cs): ?>
                        <li class="si-row">
                          <span class="si-row-main"><b><?php echo vs_e($cs['title']); ?></b><small><?php echo $cs['place'] === 'bottom' ? 'At the bottom' : 'Above Explore'; ?></small></span>
                          <?php vs_si_button_form($csrf, 'csb_delete_section', (string)$cs['id'], 'Delete section and its items', 'bi-trash', [], 'Delete this section and all its menu items?', true); ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>

                  <p class="si-rail-h">Your menu items</p>
                  <?php if (!$csb['items']): ?>
                    <p class="text-muted mb-0">No custom menu items yet.</p>
                  <?php else: ?>
                    <ul class="si-rows" data-si-limit="4">
                      <?php foreach ($csb['items'] as $it): ?>
                        <li class="si-row">
                          <span class="vn-pv"><?php if ($it['icon'] !== ''): ?><img src="../<?php echo vs_e(vs_csb_icon_url($it['icon'])); ?>" alt=""><?php else: ?><i class="bi bi-tag" aria-hidden="true"></i><?php endif; ?></span>
                          <span class="si-row-main"><b><?php echo vs_e($it['label']); ?></b><small><?php echo vs_e($it['href']); ?> &middot; <?php echo vs_e($csbChoices[$it['section']] ?? $it['section']); ?></small></span>
                          <?php vs_si_upload_form($csrf, 'csb_item_icon', (string)$it['id'], $it['icon'] !== '' ? 'Change icon' : 'Add icon', [], 'image/png,image/jpeg,image/webp,image/gif'); ?>
                          <?php vs_si_button_form($csrf, 'csb_delete_item', (string)$it['id'], 'Delete menu item', 'bi-trash', [], 'Delete this menu item?', true); ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                <?php endif; ?>
              </section>

              </div>

              <div class="si-pane" id="si-pane-categories" role="tabpanel" data-pane="categories">
              <!-- ================= CATEGORY ICONS ================= -->
              <section class="panel" aria-label="Category icons" id="sec-categories">
                <h2 class="vn-card-title"><span class="vn-ico"><i class="bi bi-grid-fill" aria-hidden="true"></i></span>Category Icons <span class="si-count-pill"><?php echo count($categories); ?></span></h2>
                <p class="vn-sub mb-3">The icon next to each category in the side menu and the category chips.</p>
                <?php if (!$categories): ?>
                  <p class="text-muted mb-0">No categories yet. Add them on the <a href="categories.php">Categories</a> page first.</p>
                <?php else: ?>
                  <div class="vn-icontiles" data-si-limit="6">
                    <?php foreach ($categories as $cat): $cname = (string)$cat; $has = vs_cat_icon_url($cname) !== null; ?>
                      <div class="vn-icontile">
                        <div class="vn-prev"><?php echo vs_category_icon_html($cname, 40, '../'); ?></div>
                        <div class="vn-t-name"><?php echo vs_e($cname); ?></div>
                        <div class="vn-t-meta"><?php echo $has ? 'Custom icon' : 'Default icon'; ?></div>
                        <div class="vn-actions">
                          <?php vs_si_upload_form($csrf, 'cat_icon', $cname, $has ? 'Change icon' : 'Add icon'); ?>
                          <?php if ($has) { vs_si_button_form($csrf, 'cat_icon_remove', $cname, 'Remove icon', 'bi-trash', [], 'Remove this icon?', true); } ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                <?php endif; ?>
              </section>

              </div>

              <div class="si-pane" id="si-pane-menu" role="tabpanel" data-pane="menu">
              <!-- ================= MENU / EXPLORE / BUTTON ICONS ================= -->
              <?php foreach (vs_ui_icon_groups() as $group => $names):
                  $gid = 'sec-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$group)); ?>
                <section class="panel" aria-label="<?php echo vs_e($group); ?> icons" id="<?php echo vs_e($gid); ?>">
                  <h2 class="vn-card-title"><span class="vn-ico"><i class="bi bi-grid" aria-hidden="true"></i></span><?php echo vs_e($group); ?> Icons <span class="si-count-pill"><?php echo count($names); ?></span></h2>
                  <p class="vn-sub mb-3">
                    <?php echo vs_e($groupHelp[$group] ?? 'The small icons inside buttons (profile page and others).'); ?>
                    Square images (PNG, WEBP, JPG, GIF or SVG) under 512 KB look best.
                  </p>
                  <div class="vn-icontiles" data-si-limit="6">
                    <?php foreach ($names as $iname => $ilabel): $custom = $uiMap[$iname] ?? null; ?>
                      <div class="vn-icontile">
                        <div class="vn-prev">
                          <?php if ($custom): ?>
                            <img src="../uploads/ui-icons/<?php echo vs_e($custom); ?>" alt="" style="width:40px;height:40px;object-fit:contain">
                          <?php else: ?>
                            <i class="bi bi-image" aria-hidden="true"></i>
                          <?php endif; ?>
                        </div>
                        <div class="vn-t-name"><?php echo vs_e($ilabel); ?></div>
                        <div class="vn-t-meta"><?php echo $custom ? 'Custom icon' : 'Built-in icon'; ?></div>
                        <div class="vn-actions">
                          <?php vs_si_upload_form($csrf, 'ui_icon', $iname, $custom ? 'Change icon' : 'Upload icon'); ?>
                          <?php if ($custom) { vs_si_button_form($csrf, 'ui_icon_remove', $iname, 'Reset to built-in icon', 'bi-trash', [], 'Go back to the built-in icon?', true); } ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </section>
              <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script>
    /* Show more / Show less for long icon grids (everything is visible if JS is off) */
    (function () {
      document.querySelectorAll('[data-si-limit]').forEach(function (grid) {
        var limit = parseInt(grid.getAttribute('data-si-limit'), 10) || 12;
        var items = Array.prototype.slice.call(grid.children);
        if (items.length <= limit) { return; }
        var open = false;
        var wrap = document.createElement('div');
        wrap.className = 'si-more-wrap';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'si-more';
        wrap.appendChild(btn);
        grid.parentNode.insertBefore(wrap, grid.nextSibling);
        function apply() {
          items.forEach(function (el, i) { el.hidden = !open && i >= limit; });
          btn.textContent = '';
          var ic = document.createElement('i');
          ic.className = 'bi ' + (open ? 'bi-chevron-up' : 'bi-chevron-down');
          var tx = document.createElement('span');
          tx.textContent = open ? 'Show less' : 'Show more';
          var ct = document.createElement('span');
          ct.className = 'si-count';
          ct.textContent = open ? '' : '(' + (items.length - limit) + ' more)';
          btn.appendChild(ic); btn.appendChild(tx); btn.appendChild(ct);
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
        btn.addEventListener('click', function () { open = !open; apply(); });
        apply();
      });
    })();
  </script>
  <script>
    /* Tabs: one group of sections at a time. Without JS every section is shown. */
    (function () {
      var page = document.querySelector('.si-page');
      var tabs = Array.prototype.slice.call(document.querySelectorAll('.si-tab'));
      var panes = Array.prototype.slice.call(document.querySelectorAll('.si-pane'));
      if (!page || !tabs.length || !panes.length) { return; }
      page.classList.add('si-js');
      var KEY = 'vsSiTab';
      function show(name, remember) {
        if (!panes.some(function (p) { return p.getAttribute('data-pane') === name; })) { name = 'overview'; }
        panes.forEach(function (p) { p.classList.toggle('si-on', p.getAttribute('data-pane') === name); });
        tabs.forEach(function (t) {
          var on = t.getAttribute('data-pane') === name;
          t.classList.toggle('si-on', on);
          t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (remember) { try { sessionStorage.setItem(KEY, name); } catch (e) {} }
      }
      function paneOf(id) {
        var el = id ? document.getElementById(id) : null;
        var pane = el ? el.closest('.si-pane') : null;
        return pane ? { el: el, name: pane.getAttribute('data-pane') } : null;
      }
      function goTo(id) {
        var hit = paneOf(id);
        if (!hit) { return false; }
        show(hit.name, true);
        hit.el.scrollIntoView({ block: 'start' });
        return true;
      }
      tabs.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-pane'), true); });
      });
      document.querySelectorAll('a[href^="#sec-"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
          if (goTo(a.getAttribute('href').slice(1))) { e.preventDefault(); }
        });
      });
      window.addEventListener('hashchange', function () { goTo((location.hash || '').slice(1)); });
      var start = 'overview';
      try { start = sessionStorage.getItem(KEY) || 'overview'; } catch (e) {}
      show(start, false);
      if (location.hash) { goTo(location.hash.slice(1)); }
    })();
  </script>
  <script>
    /* Drag and drop ordering for the admin sidebar menu (the arrow buttons still work too). */
    (function () {
      document.querySelectorAll('ul[data-acsb-sort]').forEach(function (ul) {
        var kind = ul.getAttribute('data-acsb-sort');
        var dragEl = null, before = '';
        function order() {
          return Array.prototype.map.call(ul.querySelectorAll(':scope > li[data-id]'), function (li) { return li.getAttribute('data-id'); });
        }
        function submit(ids) {
          var tok = document.querySelector('input[name="csrf"]');
          if (!tok) { return; }
          var f = document.createElement('form');
          f.method = 'post';
          function add(n, v) { var i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); }
          add('csrf', tok.value);
          add('action', kind === 'sections' ? 'acsb_reorder_sections' : 'acsb_reorder_items');
          ids.forEach(function (id) { add('ids[]', id); });
          document.body.appendChild(f);
          f.submit();
        }
        ul.querySelectorAll(':scope > li[data-id]').forEach(function (li) {
          var grip = li.querySelector('.si-grip');
          if (!grip) { return; }
          grip.addEventListener('mousedown', function () { li.draggable = true; });
          grip.addEventListener('mouseup', function () { li.draggable = false; });
          li.addEventListener('dragstart', function (e) {
            dragEl = li; before = order().join(',');
            li.classList.add('si-drag');
            if (e.dataTransfer) { e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', li.getAttribute('data-id')); } catch (x) {} }
          });
          li.addEventListener('dragend', function () {
            li.draggable = false; li.classList.remove('si-drag');
            var now = order();
            dragEl = null;
            if (now.join(',') !== before) { submit(now); }
          });
          li.addEventListener('dragover', function (e) {
            if (!dragEl || dragEl === li) { return; }
            e.preventDefault();
            var r = li.getBoundingClientRect();
            ul.insertBefore(dragEl, (e.clientY - r.top) > r.height / 2 ? li.nextSibling : li);
          });
        });
      });
    })();
  </script>
  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
</body>
</html>