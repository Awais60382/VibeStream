<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';

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
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('The request expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        $names = vs_default_categories($brandingState);
        $colors = vs_category_colors($brandingState);

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
            $flash = 'Category deleted.';
        } else {
            throw new RuntimeException('Unknown action.');
        }

        $nextState = $brandingState;
        $nextState['categories'] = array_values($names);
        $nextState['category_colors'] = $colors;
        vs_branding_save_state($nextState);

        $_SESSION['vs_categories_flash'] = $flash;
        header('Location: categories.php');
        exit;
    } catch (Throwable $error) {
        $errorMsg = $error->getMessage();
    }
}

$categories = vs_categories_with_colors($brandingState);

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

    <form method="post" class="cat-add" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
      <input type="hidden" name="action" value="add">
      <input class="cat-name" type="text" name="name" maxlength="30" placeholder="New category name..." required>
      <label class="cat-color" title="Pick a colour">
        <input type="color" name="color" id="catColor" value="#7c3aed">
        <span id="catColorHex">#7C3AED</span>
      </label>
      <button type="submit" class="cat-submit"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add</button>
    </form>

    <div class="cat-list">
      <?php if (!$categories): ?>
        <div class="cat-empty">No categories yet. Add your first one above.</div>
      <?php endif; ?>
      <?php foreach ($categories as $index => $category): ?>
        <div class="cat-row">
          <span class="cat-num"><?php echo $index + 1; ?></span>
          <span class="cat-dot" style="background: <?php echo htmlspecialchars($category['color'], ENT_QUOTES, 'UTF-8'); ?>"></span>
          <span class="cat-label"><?php echo htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8'); ?></span>
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
</script>
</body>
</html>