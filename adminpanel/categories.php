<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../category-presets.php';
require_once __DIR__ . '/../category-icons.php';

const VS_MAX_CATEGORIES = 30;

if (empty($_SESSION['vs_categories_csrf'])) {
    $_SESSION['vs_categories_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['vs_categories_csrf'];

$successMsg = (string)($_SESSION['vs_categories_flash'] ?? '');
unset($_SESSION['vs_categories_flash']);
$errorMsg = '';
$brandingState = vs_branding_state();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException('That file is too big. Icons must be under 512 KB.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        $names = vs_default_categories($brandingState);
        $colors = vs_category_colors($brandingState);
        $pendingIcon = null;
        $removedIcon = null;

        if ($action === 'set_icon' || $action === 'remove_icon') {
            $target = mb_strtolower(trim((string)($_POST['name'] ?? '')));
            $exists = false;
            foreach ($names as $existing) {
                if (mb_strtolower($existing) === $target) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                throw new RuntimeException('That category no longer exists.');
            }
            if ($action === 'set_icon') {
                vs_cat_icon_set($target, $_FILES['icon'] ?? []);
                $_SESSION['vs_categories_flash'] = 'Icon updated.';
            } else {
                vs_cat_icon_remove($target);
                $_SESSION['vs_categories_flash'] = 'Custom icon removed. The default icon is back.';
            }
            header('Location: categories.php');
            exit;
        }

        if ($action === 'add') {
            $name = trim((string)preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
            $color = strtolower(trim((string)($_POST['color'] ?? '')));
            if ($name === '') {
                throw new RuntimeException('Type a category name first.');
            }
            if (!preg_match('/^[A-Za-z0-9 _-]{1,30}$/', $name)) {
                throw new RuntimeException('Use letters, numbers, spaces, - and _ only (up to 30 characters).');
            }
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
                $color = '#7c3aed';
            }
            foreach ($names as $existing) {
                if (mb_strtolower($existing) === mb_strtolower($name)) {
                    throw new RuntimeException('"' . $existing . '" already exists.');
                }
            }
            if (count($names) >= VS_MAX_CATEGORIES) {
                throw new RuntimeException('You can have at most ' . VS_MAX_CATEGORIES . ' categories. Delete one first.');
            }
            $names[] = $name;
            $colors[mb_strtolower($name)] = $color;
            $flash = '"' . $name . '" added.';
            $pendingIcon = $name;
        } elseif ($action === 'add_preset' || $action === 'add_all') {
            $presets = vs_all_category_presets();
            $have = array_map('mb_strtolower', $names);
            $slugs = $action === 'add_all'
                ? array_slice(array_keys($presets), 0, 9)
                : [strtolower(trim((string)($_POST['slug'] ?? '')))];
            $added = [];
            foreach ($slugs as $slug) {
                if (!isset($presets[$slug]) || in_array($slug, $have, true)) {
                    continue;
                }
                if (count($names) >= VS_MAX_CATEGORIES) {
                    break;
                }
                $names[] = $presets[$slug][0];
                $colors[$slug] = $presets[$slug][1];
                $have[] = $slug;
                $added[] = $presets[$slug][0];
            }
            if (!$added) {
                throw new RuntimeException('Nothing new to add. Those categories are already in your list.');
            }
            $flash = implode(', ', $added) . ' added.';
        } elseif ($action === 'delete') {
            $target = mb_strtolower(trim((string)($_POST['name'] ?? '')));
            $before = count($names);
            $names = array_values(array_filter($names, function ($n) use ($target) {
                return mb_strtolower($n) !== $target;
            }));
            if (count($names) === $before) {
                throw new RuntimeException('That category no longer exists.');
            }
            unset($colors[$target]);
            $removedIcon = $target;
            $flash = 'Category deleted.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $nextState = $brandingState;
        $nextState['categories'] = array_values($names);
        $nextState['category_colors'] = $colors;
        vs_branding_save_state($nextState);

        if ($removedIcon !== null) {
            vs_cat_icon_remove($removedIcon);
        }
        if ($pendingIcon !== null && isset($_FILES['icon']) && (int)($_FILES['icon']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                vs_cat_icon_set($pendingIcon, $_FILES['icon']);
            } catch (Throwable $iconError) {
                $flash .= ' The icon was not saved: ' . $iconError->getMessage();
            }
        }

        $_SESSION['vs_categories_flash'] = $flash;
        header('Location: categories.php');
        exit;
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

$categories = vs_categories_with_colors($brandingState);
$presetList = vs_all_category_presets();
$haveSlugs = [];
foreach ($categories as $c) {
    $haveSlugs[mb_strtolower((string)$c['name'])] = true;
}
$availablePresets = array_filter($presetList, function ($slug) use ($haveSlugs) {
    return !isset($haveSlugs[$slug]);
}, ARRAY_FILTER_USE_KEY);

require_once __DIR__ . '/header.php';

// Main-site sidebar (shared with the public pages). Links are relative to the
// site root, so tell it we are one level down in /adminpanel.
$vs_sidebar_base = '../';
require_once __DIR__ . '/../sidebar-navigation.php';
?>
<link rel="stylesheet" href="../sidebar.css">

<style>
  .app-sidebar { top: var(--navbar-height, 96px); width: var(--sidebar-width, 240px); }
  .app-main { margin-left: var(--sidebar-width, 240px); }
  .sidebar-backdrop { top: var(--navbar-height, 96px); }
  @media (max-width: 1000px) { .app-main { margin-left: 0; } }
  .cat-page { max-width: 780px; margin: 0 auto; }
  .cat-page h1 { font-size: 1.6rem; font-weight: 700; margin: 0 0 4px; }
  .cat-page .cat-sub { color: var(--admin-muted); margin: 0 0 22px; }
  .cat-add, .cat-list {
    background: var(--admin-surface);
    border: 1px solid var(--admin-border);
    border-radius: 16px;
  }
  .cat-add { display: flex; gap: 12px; padding: 16px; margin-bottom: 22px; flex-wrap: wrap; }
  .cat-add .cat-name {
    flex: 1 1 220px; min-width: 0; padding: 12px 16px; border-radius: 12px;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); outline: none;
  }
  .cat-add .cat-name:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .cat-color {
    display: inline-flex; align-items: center; gap: 10px; padding: 0 14px; height: 46px; cursor: pointer;
    border: 1px solid var(--admin-border); border-radius: 12px; background: var(--admin-bg); color: var(--admin-muted);
    font-size: 0.85rem; font-variant-numeric: tabular-nums;
  }
  .cat-color input[type="color"] {
    width: 22px; height: 22px; padding: 0; border: 0; border-radius: 4px; background: none; cursor: pointer;
  }
  .cat-color input[type="color"]::-webkit-color-swatch-wrapper { padding: 0; }
  .cat-color input[type="color"]::-webkit-color-swatch { border: 0; border-radius: 4px; }
  .cat-add button.cat-submit {
    display: inline-flex; align-items: center; gap: 8px; height: 46px; padding: 0 22px; border: 0; border-radius: 12px;
    background: var(--admin-primary); color: #fff; font-weight: 700; cursor: pointer;
  }
  .cat-add button.cat-submit:hover { background: var(--admin-primary-dark); }
  .cat-list { overflow: hidden; }
  .cat-row {
    display: flex; align-items: center; gap: 16px; padding: 16px 22px; border-bottom: 1px solid var(--admin-border);
  }
  .cat-row:last-child { border-bottom: 0; }
  .cat-row .cat-num { width: 20px; color: var(--admin-muted); font-weight: 700; font-size: 0.9rem; }
  .cat-row .cat-dot { width: 14px; height: 14px; border-radius: 50%; flex: 0 0 14px; }
  .cat-row .cat-label { flex: 1; font-weight: 600; overflow-wrap: anywhere; }
  .cat-row form { margin: 0; }
  .cat-del {
    border: 0; background: none; color: var(--admin-muted); padding: 6px 8px; border-radius: 8px; cursor: pointer;
  }
  .cat-del:hover { color: var(--admin-danger); background: rgba(220, 38, 38, 0.1); }
  .cat-empty { padding: 36px 22px; text-align: center; color: var(--admin-muted); }
  .cat-presets { background: var(--admin-surface); border: 1px solid var(--admin-border); border-radius: 16px; padding: 16px; margin-bottom: 22px; }
  .cat-presets h2 { font-size: 1rem; font-weight: 700; margin: 0 0 4px; }
  .cat-presets p { color: var(--admin-muted); font-size: 0.85rem; margin: 0 0 12px; }
  .cat-preset-grid { display: flex; flex-wrap: wrap; gap: 10px; }
  .cat-preset-grid form { margin: 0; }
  .cat-preset-search { position: relative; margin-bottom: 12px; }
  .cat-preset-search i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--admin-muted); pointer-events: none; }
  .cat-preset-search input {
    width: 100%; padding: 11px 16px 11px 40px; border-radius: 12px; outline: none;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text);
  }
  .cat-preset-search input:focus { border-color: var(--admin-primary); box-shadow: var(--admin-ring); }
  .cat-preset-empty { margin: 0 0 4px; }
  .preset-chip {
    display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; cursor: pointer;
    border: 1px solid var(--admin-border); background: var(--admin-bg); color: var(--admin-text); font-weight: 600; font-size: 0.9rem;
  }
  .preset-chip:hover { border-color: var(--admin-primary); }
  .preset-chip svg { width: 20px; height: 20px; flex: 0 0 20px; }
  .preset-all { border: 0; background: var(--admin-primary); color: #fff; border-radius: 999px; padding: 8px 16px; font-weight: 700; cursor: pointer; font-size: 0.85rem; }
  .cat-row .cat-icon { width: 24px; height: 24px; flex: 0 0 24px; display: inline-flex; }
  .cat-row .cat-icon svg { width: 24px; height: 24px; }
  .cat-row .cat-icon img { width: 24px; height: 24px; object-fit: contain; border-radius: 4px; }
  .cat-icon-form { display: inline-flex; align-items: center; }
  .cat-icon-btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 8px; cursor: pointer;
    border: 1px dashed var(--admin-border); color: var(--admin-muted); font-size: 0.8rem; font-weight: 600; white-space: nowrap;
  }
  .cat-icon-btn:hover { border-color: var(--admin-primary); color: var(--admin-primary); }
  .cat-icon-btn input[type="file"] { display: none; }
  .cat-hint { color: var(--admin-muted); font-size: 0.8rem; margin: -12px 4px 22px; }
  .cat-add .cat-file-name { max-width: 140px; overflow: hidden; text-overflow: ellipsis; }
</style>

<?php vs_render_sidebar('admincategories'); ?>
<div class="sidebar-backdrop" data-sidebar-close></div>

<main class="admin-content app-main p-3 p-lg-4">
  <div class="cat-page">
    <h1>Categories</h1>
    <p class="cat-sub">Categories power the home page chips, the sidebar menu and the upload form.</p>

    <?php if ($successMsg !== ''): ?>
      <div class="alert alert-success" role="status"><?php echo htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
      <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($availablePresets): ?>
      <section class="cat-presets" aria-label="Ready-made categories">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h2>Ready-made categories</h2>
            <p>Search for a category, then tap it to add it with its icon and colour. Once added, it disappears from here.</p>
          </div>
          <?php if (count(array_intersect(array_slice(array_keys($presetList), 0, 9), array_keys($availablePresets))) > 0): ?>
            <form method="post">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="add_all">
              <button type="submit" class="preset-all">Add the standard set</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="cat-preset-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" id="presetSearch" placeholder="Search ready-made categories (e.g. fun, music)..." autocomplete="off" aria-label="Search ready-made categories">
        </div>
        <p class="cat-preset-empty" id="presetEmpty" hidden>No ready-made category matches that. You can add it yourself with the form below.</p>
        <div class="cat-preset-grid" id="presetGrid">
          <?php foreach ($availablePresets as $slug => $preset): ?>
            <form method="post" class="preset-item" hidden data-name="<?php echo htmlspecialchars(mb_strtolower($preset[0] . ' ' . $slug), ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="add_preset">
              <input type="hidden" name="slug" value="<?php echo htmlspecialchars($slug, ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="preset-chip" style="--chip: <?php echo htmlspecialchars($preset[1], ENT_QUOTES, 'UTF-8'); ?>">
                <span style="color: <?php echo htmlspecialchars($preset[1], ENT_QUOTES, 'UTF-8'); ?>; display:inline-flex"><?php echo vs_category_icon_html($presetList[$slug][0], 20, '../'); ?></span>
                <?php echo htmlspecialchars($preset[0], ENT_QUOTES, 'UTF-8'); ?>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <form method="post" class="cat-add" autocomplete="off" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="add">
      <input class="cat-name" type="text" name="name" maxlength="30" placeholder="New category name..." required>
      <label class="cat-color" title="Pick a colour">
        <input type="color" name="color" id="catColor" value="#7c3aed">
        <span id="catColorHex">#7C3AED</span>
      </label>
      <label class="cat-color" title="Upload your own icon (optional)">
        <i class="bi bi-image" aria-hidden="true"></i>
        <input type="file" name="icon" id="catIconFile" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" hidden>
        <span class="cat-file-name" id="catIconName">Icon (optional)</span>
      </label>
      <button type="submit" class="cat-submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add</button>
    </form>
    <p class="cat-hint">Your own icons: PNG, JPG, WEBP, GIF or SVG, up to 512 KB. Square images look best. You can also add or change an icon on any category below.</p>

    <div class="cat-list">
      <?php if (!$categories): ?>
        <div class="cat-empty">No categories yet. Add your first one above.</div>
      <?php endif; ?>
      <?php foreach ($categories as $index => $category): ?>
        <div class="cat-row">
          <span class="cat-num"><?php echo $index + 1; ?></span>
          <span class="cat-icon" style="color: <?php echo htmlspecialchars($category['color'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo vs_category_icon_html((string)$category['name'], 24, '../'); ?></span>
          <span class="cat-label"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></span>
          <?php $hasCustomIcon = vs_cat_icon_url((string)$category['name']) !== null; ?>
          <form method="post" enctype="multipart/form-data" class="cat-icon-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="set_icon">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>">
            <label class="cat-icon-btn" title="Upload your own icon">
              <i class="bi bi-upload" aria-hidden="true"></i> <?php echo $hasCustomIcon ? 'Change icon' : 'Add icon'; ?>
              <input type="file" name="icon" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml" onchange="this.form.submit()">
            </label>
          </form>
          <?php if ($hasCustomIcon): ?>
            <form method="post" class="cat-icon-form">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="action" value="remove_icon">
              <input type="hidden" name="name" value="<?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>">
              <button type="submit" class="cat-del" title="Remove custom icon" aria-label="Remove custom icon"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
            </form>
          <?php endif; ?>
          <form method="post" onsubmit="return confirm('Delete this category? Videos keep their category text.');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="name" value="<?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>">
            <button type="submit" class="cat-del" aria-label="Delete <?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?>" title="Delete">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</main>

</div>
</div>
<script src="./assets/js/bootstrap.bundle.min.js"></script>
<script src="./assets/js/main.js"></script>
<script>
  // sidebar.js listens on #navToggle; give the admin hamburger that id if it has none.
  (function () {
    if (document.getElementById('navToggle')) return;
    var btn = document.querySelector('.nav-toggle, [data-sidebar-toggle], .navbar-toggler, header button');
    if (btn) btn.id = 'navToggle';
  })();
</script>
<script src="../sidebar.js"></script>
<script>
  (function () {
    var picker = document.getElementById('catColor');
    var hex = document.getElementById('catColorHex');
    if (!picker || !hex) return;
    picker.addEventListener('input', function () { hex.textContent = picker.value.toUpperCase(); });
  })();
  (function () {
    var box = document.getElementById('presetSearch');
    var empty = document.getElementById('presetEmpty');
    var items = document.querySelectorAll('#presetGrid .preset-item');
    if (!box) return;
    box.addEventListener('input', function () {
      var q = box.value.trim().toLowerCase();
      var shown = 0;
      items.forEach(function (el) {
        var match = q !== '' && el.getAttribute('data-name').indexOf(q) !== -1;
        el.hidden = !match;
        if (match) shown++;
      });
      if (empty) empty.hidden = !(q !== '' && shown === 0);
    });
  })();
  (function () {
    var file = document.getElementById('catIconFile');
    var label = document.getElementById('catIconName');
    if (!file || !label) return;
    file.addEventListener('change', function () {
      label.textContent = file.files && file.files[0] ? file.files[0].name : 'Icon (optional)';
    });
  })();
</script>
</body>
</html>