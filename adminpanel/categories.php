<?php
require_once __DIR__ . '/admin-bootstrap.php';
require_once __DIR__ . '/../site-branding.php';
require_once __DIR__ . '/../category-presets.php';
require_once __DIR__ . '/../category-icons.php';

const VS_MAX_CATEGORIES = 50;

/** Turns a hex colour like #8B5CF6 into a plain colour name like "Purple". */
if (!function_exists('vs_color_name')) {
    function vs_color_name(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return 'Custom';
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        $sat = $d == 0 ? 0 : $d / (1 - abs(2 * $l - 1));
        if ($d == 0) {
            $h = 0;
        } elseif ($max === $r) {
            $h = 60 * fmod((($g - $b) / $d), 6);
        } elseif ($max === $g) {
            $h = 60 * ((($b - $r) / $d) + 2);
        } else {
            $h = 60 * ((($r - $g) / $d) + 4);
        }
        if ($h < 0) {
            $h += 360;
        }

        if ($l < 0.12) { return 'Black'; }
        if ($l > 0.93) { return 'White'; }
        if ($sat < 0.18) {
            if ($h >= 20 && $h < 60 && $sat >= 0.1) { return $l > 0.6 ? 'Tan' : 'Brown'; }
            return $l < 0.3 ? 'Dark gray' : ($l > 0.75 ? 'Light gray' : 'Gray');
        }

        if ($h < 15 || $h >= 345)   { $name = ($h >= 335 && $h < 360 && $h >= 345) ? 'Rose' : 'Red'; }
        elseif ($h < 35)            { $name = 'Orange'; }
        elseif ($h < 43)            { $name = 'Amber'; }
        elseif ($h < 65)            { $name = 'Yellow'; }
        elseif ($h < 90)            { $name = 'Lime'; }
        elseif ($h < 165)           { $name = 'Green'; }
        elseif ($h < 190)           { $name = 'Teal'; }
        elseif ($h < 207)           { $name = 'Sky blue'; }
        elseif ($h < 235)           { $name = 'Blue'; }
        elseif ($h < 250)           { $name = 'Indigo'; }
        elseif ($h < 285)           { $name = 'Purple'; }
        elseif ($h < 320)           { $name = 'Magenta'; }
        else                        { $name = 'Pink'; }

        if ($name === 'Orange' && $l < 0.35) { return 'Brown'; }
        if ($l < 0.27) { return 'Dark ' . strtolower($name); }
        if ($l > 0.8)  { return 'Light ' . strtolower($name); }
        return $name;
    }
}

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

        if ($action === 'set_color') {
            $target = mb_strtolower(trim((string)($_POST['name'] ?? '')));
            $color = strtolower(trim((string)($_POST['color'] ?? '')));
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
            if (!preg_match('/^#[0-9a-f]{6}$/', $color)) {
                throw new RuntimeException('Pick a valid colour.');
            }
            $colors[$target] = $color;
            $nextState = $brandingState;
            $nextState['category_colors'] = $colors;
            vs_branding_save_state($nextState);
            $_SESSION['vs_categories_flash'] = 'Colour updated.';
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
                ? array_keys($presets)
                : [strtolower(trim((string)($_POST['slug'] ?? '')))];
            $added = [];
            foreach ($slugs as $slug) {
                if (!isset($presets[$slug])) {
                    continue;
                }
                $presetName = trim((string)$presets[$slug][0]);
                $presetKey = mb_strtolower($presetName);
                if ($presetName === '' || in_array($presetKey, $have, true)) {
                    continue;
                }
                if (count($names) >= VS_MAX_CATEGORIES) {
                    break;
                }
                $names[] = $presetName;
                $colors[$presetKey] = $presets[$slug][1];
                $have[] = $presetKey;
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
$haveNames = [];
foreach ($categories as $c) {
    $haveNames[mb_strtolower(trim((string)$c['name']))] = true;
}
$availablePresets = array_filter($presetList, function ($preset) use ($haveNames) {
    $name = mb_strtolower(trim((string)($preset[0] ?? '')));
    return $name !== '' && !isset($haveNames[$name]);
});

require_once __DIR__ . '/header.php';

$e = static function ($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
};

// Videos per category (only shown when the videos table has a category column)
$videoCounts = [];
$haveCounts = false;
if (isset($pdo) && $pdo instanceof PDO && function_exists('vs_cols')) {
    try {
        $vcols = vs_cols($pdo, 'videos');
        $catCol = $vcols ? vs_pick($vcols, ['category', 'category_name', 'cat']) : null;
        if ($catCol) {
            $rs = $pdo->query("SELECT LOWER(TRIM(`$catCol`)) AS k, COUNT(*) AS c FROM `videos` GROUP BY LOWER(TRIM(`$catCol`))");
            foreach ($rs->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $videoCounts[(string)$r['k']] = (int)$r['c'];
            }
            $haveCounts = true;
        }
    } catch (Throwable $err) {
        $videoCounts = [];
        $haveCounts = false;
    }
}

$presetNames = [];
foreach ($presetList as $preset) {
    $presetNames[] = mb_strtolower(trim((string)($preset[0] ?? '')));
}
$statTotal = count($categories);
$statCustom = 0;
$statPreset = 0;
foreach ($categories as $c) {
    if (vs_cat_icon_url((string)$c['name']) !== null) {
        $statCustom++;
    }
    if (in_array(mb_strtolower(trim((string)$c['name'])), $presetNames, true)) {
        $statPreset++;
    }
}
$statLeft = max(0, VS_MAX_CATEGORIES - $statTotal);
$standardLeft = count($availablePresets);   // ready-made categories not added yet
$iconTypes = 'image/png,image/jpeg,image/webp,image/gif,image/svg+xml';
?>

      <style>
        .ct-tile{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;font-size:20px;color:#fff;background:linear-gradient(135deg,#8b5cf6,#5b3bd6);flex:none}
        .ct-head{display:flex;align-items:center;gap:12px;padding:16px}
        .ct-head h2{font-size:1.1rem;margin:0}
        .ct-head p{margin:0;font-size:.85rem;opacity:.75}
        .ct-tabs{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:0 16px 14px;border-bottom:1px solid rgba(128,128,128,.22)}
        .ct-tab{border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;border-radius:10px;padding:8px 16px;font-size:.88rem;font-weight:600}
        .ct-tab:hover{background:rgba(128,128,128,.12)}
        .ct-tab.on{background:#7c4dff;border-color:#7c4dff;color:#fff}
        .ct-tab:disabled{opacity:.45;cursor:not-allowed}
        .ct-max{margin-left:auto;font-size:.85rem;opacity:.75}
        .ct-grid{padding:16px}
        .ct-card{height:100%;border:1px solid rgba(128,128,128,.3);border-radius:14px;padding:14px;background:rgba(128,128,128,.06);display:flex;flex-direction:column;gap:10px}
        .ct-top{display:flex;align-items:center;gap:12px}
        .ct-ico{width:50px;height:50px;border-radius:12px;display:grid;place-items:center;flex:none;color:#fff;overflow:hidden}
        .ct-ico svg{width:28px;height:28px}
        .ct-ico img{width:34px;height:34px;object-fit:contain;border-radius:6px}
        .ct-name{font-weight:700;line-height:1.2;overflow-wrap:anywhere}
        .ct-meta{font-size:.82rem;opacity:.8}
        .ct-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px}
        .ct-acts{display:flex;justify-content:flex-end;align-items:center;gap:4px;margin-top:auto}
        .ct-acts form{margin:0}
        .ct-btn{border:0;background:none;color:inherit;opacity:.75;padding:5px 7px;border-radius:8px;cursor:pointer;font-size:15px}
        .ct-btn:hover{opacity:1;background:rgba(128,128,128,.18)}
        .ct-btn.del:hover{color:#ff5a5f;background:rgba(255,90,95,.14)}
        .ct-btn input[type=file]{display:none}
        .ct-foot{display:flex;align-items:center;gap:8px;padding:12px 16px;font-size:.82rem;opacity:.75;border-top:1px solid rgba(128,128,128,.22)}
        .ct-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,138px),1fr));gap:10px;padding:0 16px 16px}
        .ct-stat{display:flex;align-items:center;gap:10px;min-width:0;border:1px solid rgba(128,128,128,.28);border-radius:12px;padding:12px 10px;background:rgba(128,128,128,.06)}
        .ct-stat>div{min-width:0}
        .ct-stat i{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;color:#fff;flex:none;font-size:15px}
        .ct-stat b{display:block;font-size:1.25rem;line-height:1.1}
        .ct-stat small{display:block;opacity:.8;line-height:1.25;font-size:.8rem;overflow-wrap:anywhere}
        .ct-quick{display:flex;align-items:center;gap:12px;width:100%;text-align:left;padding:12px;border-radius:12px;border:1px solid rgba(128,128,128,.3);background:transparent;color:inherit;margin-bottom:10px}
        .ct-quick:hover{background:rgba(128,128,128,.12)}
        .ct-quick:disabled{opacity:.45;cursor:not-allowed}
        .ct-quick .ic{width:40px;height:40px;border-radius:10px;background:rgba(124,77,255,.25);display:grid;place-items:center;flex:none}
        .ct-quick small{display:block;opacity:.75}
        .ct-tips{list-style:none;margin:0;padding:0 16px 16px;font-size:.88rem}
        .ct-tips li{display:flex;gap:10px;margin-bottom:8px}
        .ct-tips i{color:#27c77a}
        .ct-presets{padding:16px}
        .ct-empty{padding:36px 16px;text-align:center;opacity:.75}
        .preset-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;cursor:pointer;border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;font-weight:600;font-size:.9rem}
        .preset-chip:hover{border-color:#7c4dff}
        .preset-chip svg{width:20px;height:20px;flex:0 0 20px}
        .cat-preset-grid{display:flex;flex-wrap:wrap;gap:10px}
        .cat-preset-grid form{margin:0}
      

      </style>
      <style data-vs-neon>
/* ===== NEON THEME (dark + light) - same look as creator-analytics.php ===== */
html:not([data-bs-theme="light"]):not([data-theme="light"]){
  --nx-cyan:#00e5ff;--nx-pink:#ff2e93;--nx-violet:#8a5cff;--nx-green:#22e58a;--nx-amber:#ffd23f;--nx-red:#ff4d6d;
  --nx-cyan-rgb:0,229,255;--nx-pink-rgb:255,46,147;--nx-violet-rgb:138,92,255;--nx-green-rgb:34,229,138;--nx-amber-rgb:255,210,63;--nx-red-rgb:255,77,109;
  --nx-cyan-ink:#00e5ff;--nx-pink-ink:#ff7ac0;--nx-muted:#8f9bd0;
  --nx-text:#eaf0ff;--nx-card:rgba(10,13,31,.88);--nx-solid:#0a0d1f;--nx-field:rgba(10,13,31,.9);
  --nx-track:#05060f;--nx-soft:#121634;--nx-active:#fff;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(138,92,255,.16),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,229,255,.10),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.08),transparent 70%),#05060f;
}
html[data-bs-theme="light"],html[data-theme="light"]{
  --nx-cyan:#00b8e6;--nx-pink:#ff2e93;--nx-violet:#7c4dff;--nx-green:#0fb86a;--nx-amber:#f59e0b;--nx-red:#e11d63;
  --nx-cyan-rgb:0,184,230;--nx-pink-rgb:255,46,147;--nx-violet-rgb:124,77,255;--nx-green-rgb:15,184,106;--nx-amber-rgb:245,158,11;--nx-red-rgb:225,29,99;
  --nx-cyan-ink:#0086b3;--nx-pink-ink:#d4147a;--nx-muted:#55608f;
  --nx-text:#0b1030;--nx-card:#ffffff;--nx-solid:#ffffff;--nx-field:#ffffff;
  --nx-track:#eceffd;--nx-soft:#eceffd;--nx-active:#0b1030;
  --nx-page:radial-gradient(900px 480px at 8% -8%,rgba(124,77,255,.14),transparent 70%),radial-gradient(800px 420px at 100% 0,rgba(0,184,230,.14),transparent 70%),radial-gradient(700px 500px at 50% 110%,rgba(255,46,147,.10),transparent 70%),#f4f6ff;
}
html body{background:var(--nx-page);background-attachment:fixed}
html body ::-webkit-scrollbar{width:10px;height:10px}
html body ::-webkit-scrollbar-track{background:var(--nx-track)}
html body ::-webkit-scrollbar-thumb{background:linear-gradient(180deg,var(--nx-violet),var(--nx-pink));border-radius:8px}
html body ::selection{background:rgba(var(--nx-pink-rgb),.4);color:var(--nx-text)}

/* categories page */
html body .panel{background:var(--nx-card);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 26px -10px rgba(var(--nx-violet-rgb),.55)}
html body .ct-tile{background:linear-gradient(135deg,var(--nx-violet),var(--nx-pink));box-shadow:0 0 20px rgba(var(--nx-pink-rgb),.55)}
html body .ct-head h1,html body .ct-head h2{text-shadow:0 0 18px rgba(var(--nx-violet-rgb),.7)}
html body .ct-tabs{border-bottom:1px solid rgba(var(--nx-violet-rgb),.3)}
html body .ct-tab{border:1px solid rgba(var(--nx-violet-rgb),.5);background:rgba(var(--nx-violet-rgb),.10);color:var(--nx-text);transition:box-shadow .18s ease,border-color .18s ease,background .18s ease}
html body .ct-tab:hover:not(:disabled){background:rgba(var(--nx-cyan-rgb),.12);border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}
html body .ct-tab.on{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));border-color:var(--nx-pink);color:#fff;box-shadow:0 0 16px rgba(var(--nx-pink-rgb),.6)}
html body .ct-max{color:var(--nx-muted);opacity:1}
html body .ct-card{background:rgba(var(--nx-violet-rgb),.08);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 22px -10px rgba(var(--nx-violet-rgb),.55);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}
html body .ct-card:hover{transform:translateY(-2px);border-color:var(--nx-cyan);box-shadow:0 0 26px -4px rgba(var(--nx-cyan-rgb),.55),0 0 50px -16px rgba(var(--nx-violet-rgb),.55)}
html body .ct-ico{box-shadow:0 0 14px -2px rgba(var(--nx-violet-rgb),.8)}
html body .ct-dot{box-shadow:0 0 8px rgba(var(--nx-cyan-rgb),.7)}
html body .ct-btn:hover{color:var(--nx-cyan-ink);background:rgba(var(--nx-cyan-rgb),.12);box-shadow:0 0 12px rgba(var(--nx-cyan-rgb),.45)}
html body .ct-btn.del:hover{color:var(--nx-red);background:rgba(var(--nx-red-rgb),.14);box-shadow:0 0 12px rgba(var(--nx-red-rgb),.5)}
html body .ct-foot{border-top:1px solid rgba(var(--nx-violet-rgb),.3)}
html body .ct-stat{background:rgba(var(--nx-violet-rgb),.08);border:1px solid rgba(var(--nx-violet-rgb),.4);box-shadow:0 0 16px -8px rgba(var(--nx-violet-rgb),.6)}
html body .ct-stat i{box-shadow:0 0 12px -2px rgba(var(--nx-violet-rgb),.8)}
html body .ct-stat b{text-shadow:0 0 12px rgba(var(--nx-cyan-rgb),.45)}
html body .ct-quick{border:1px solid rgba(var(--nx-violet-rgb),.5);background:rgba(var(--nx-violet-rgb),.08);color:var(--nx-text);transition:box-shadow .18s ease,border-color .18s ease,background .18s ease}
html body .ct-quick:hover:not(:disabled){background:rgba(var(--nx-cyan-rgb),.10);border-color:var(--nx-cyan);box-shadow:0 0 16px rgba(var(--nx-cyan-rgb),.5)}
html body .ct-quick .ic{background:rgba(var(--nx-violet-rgb),.22);box-shadow:0 0 12px -2px rgba(var(--nx-violet-rgb),.7)}
html body .ct-tips i{color:var(--nx-green);filter:drop-shadow(0 0 4px var(--nx-green))}
html body .preset-chip{border:1px solid rgba(var(--nx-violet-rgb),.5);background:rgba(var(--nx-violet-rgb),.08);color:var(--nx-text);transition:box-shadow .18s ease,border-color .18s ease}
html body .preset-chip:hover{border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}
html body .ct-empty{color:var(--nx-muted);opacity:1}

html body .form-control,html body .form-select{background-color:var(--nx-field);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55);box-shadow:0 0 10px -2px rgba(var(--nx-violet-rgb),.45);transition:box-shadow .2s ease,border-color .2s ease}
html body .form-control::placeholder{color:var(--nx-muted);opacity:.8}
html body .form-control:focus,html body .form-select:focus{background-color:var(--nx-field);color:var(--nx-text);outline:none;border-color:var(--nx-cyan);box-shadow:0 0 0 1px var(--nx-cyan),0 0 20px rgba(var(--nx-cyan-rgb),.55)}
html body .btn-primary{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));border:0;color:#fff;box-shadow:0 0 20px rgba(var(--nx-pink-rgb),.5);transition:box-shadow .18s ease,transform .18s ease}
html body .btn-primary:hover,html body .btn-primary:focus{background:linear-gradient(90deg,var(--nx-violet),var(--nx-pink));color:#fff;transform:translateY(-1px);box-shadow:0 0 30px rgba(var(--nx-pink-rgb),.85)}
html body .btn-outline-secondary{background:rgba(var(--nx-violet-rgb),.14);color:var(--nx-text);border:1px solid rgba(var(--nx-violet-rgb),.55)}
html body .btn-outline-secondary:hover{background:rgba(var(--nx-cyan-rgb),.12);color:var(--nx-text);border-color:var(--nx-cyan);box-shadow:0 0 14px rgba(var(--nx-cyan-rgb),.5)}
html body .modal-content{background:var(--nx-solid);color:var(--nx-text);border:1px solid rgba(var(--nx-cyan-rgb),.6);box-shadow:0 0 36px -6px rgba(var(--nx-cyan-rgb),.55),0 0 70px -24px rgba(var(--nx-violet-rgb),.7)}
html body .modal-header,html body .modal-footer{border-color:rgba(var(--nx-violet-rgb),.3)}
html body .modal-title{text-shadow:0 0 12px rgba(var(--nx-violet-rgb),.5)}
html[data-bs-theme="dark"] body .btn-close,html:not([data-bs-theme="light"]):not([data-theme="light"]) body .btn-close{filter:invert(1) grayscale(1) brightness(1.6)}
html body .alert-success{color:var(--nx-text);background:rgba(var(--nx-green-rgb),.12);border:1px solid rgba(var(--nx-green-rgb),.55);box-shadow:0 0 20px -6px rgba(var(--nx-green-rgb),.55)}
html body .alert-danger{color:var(--nx-text);background:rgba(var(--nx-pink-rgb),.12);border:1px solid rgba(var(--nx-pink-rgb),.6);box-shadow:0 0 20px -6px rgba(var(--nx-pink-rgb),.55)}
@media (prefers-reduced-motion:reduce){html body .ct-card,html body .ct-tab,html body .ct-quick,html body .btn-primary{transition:none}html body .ct-card:hover,html body .btn-primary:hover{transform:none}}
</style>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">

          <?php if ($successMsg !== ''): ?>
            <div class="alert alert-success" role="status"><?php echo $e($successMsg); ?></div>
          <?php endif; ?>
          <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger" role="alert"><?php echo $e($errorMsg); ?></div>
          <?php endif; ?>

          <div class="row g-3">
            <div class="col-12 col-xl-9">
              <section class="panel" aria-label="Categories">
                <div class="ct-head justify-content-between flex-wrap">
                  <div class="d-flex align-items-center gap-3">
                    <span class="ct-tile"><i class="bi bi-grid-fill" aria-hidden="true"></i></span>
                    <div>
                      <h1 class="h4 mb-0">Categories</h1>
                      <p>Manage your site categories, add new ones, or customize icons and colors.</p>
                    </div>
                  </div>
                  <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ctAddModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add Category</button>
                </div>

                <div class="ct-tabs">
                  <button type="button" class="ct-tab on" data-tab="all"><i class="bi bi-grid" aria-hidden="true"></i> All Categories (<?php echo (int)$statTotal; ?> of <?php echo (int)VS_MAX_CATEGORIES; ?>)</button>
                  <button type="button" class="ct-tab" data-tab="presets"<?php echo $availablePresets ? '' : ' disabled title="All ready-made categories are already added"'; ?>>Add Preset Categories</button>
                  <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                    <input type="hidden" name="action" value="add_all">
                    <button type="submit" class="ct-tab"<?php echo $standardLeft > 0 ? '' : ' disabled title="All ready-made categories are already added"'; ?>>Add All Presets</button>
                  </form>
                  <span class="ct-max">You have <strong><?php echo (int)$statTotal; ?></strong> of <?php echo (int)VS_MAX_CATEGORIES; ?> categories &middot; <strong><?php echo (int)$statLeft; ?></strong> left</span>
                </div>

                <!-- All categories -->
                <div id="ctPaneAll">
                  <?php if (!$categories): ?>
                    <div class="ct-empty">No categories yet. Press "Add Category" to create your first one.</div>
                  <?php else: ?>
                    <div class="ct-grid">
                      <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-lg-3 row-cols-xxl-4">
                        <?php foreach ($categories as $category):
                            $cname = (string)$category['name'];
                            $ccolor = (string)$category['color'];
                            $hasCustomIcon = vs_cat_icon_url($cname) !== null;
                            $cnt = $videoCounts[mb_strtolower(trim($cname))] ?? 0;
                        ?>
                          <div class="col">
                            <div class="ct-card">
                              <div class="ct-top">
                                <span class="ct-ico" style="background:<?php echo $e($ccolor); ?>"><?php echo vs_category_icon_html($cname, 28, '../'); ?></span>
                                <div class="min-w-0">
                                  <div class="ct-name"><?php echo $e($cname); ?></div>
                                  <?php if ($haveCounts): ?><div class="ct-meta"><?php echo number_format($cnt); ?> video<?php echo $cnt === 1 ? '' : 's'; ?></div><?php endif; ?>
                                  <div class="ct-meta" title="<?php echo $e(strtoupper($ccolor)); ?>"><span class="ct-dot" style="background:<?php echo $e($ccolor); ?>"></span><?php echo $e(vs_color_name($ccolor)); ?></div>
                                </div>
                              </div>
                              <div class="ct-acts">
                                <button type="button" class="ct-btn" title="Change colour" aria-label="Change colour of <?php echo $e($cname); ?>" data-bs-toggle="modal" data-bs-target="#ctColorModal" data-name="<?php echo $e($cname); ?>" data-color="<?php echo $e($ccolor); ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                <form method="post" enctype="multipart/form-data">
                                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                  <input type="hidden" name="action" value="set_icon">
                                  <input type="hidden" name="name" value="<?php echo $e($cname); ?>">
                                  <label class="ct-btn mb-0" title="<?php echo $hasCustomIcon ? 'Change icon' : 'Add your own icon'; ?>">
                                    <i class="bi bi-image" aria-hidden="true"></i>
                                    <input type="file" name="icon" accept="<?php echo $e($iconTypes); ?>" onchange="this.form.submit()">
                                  </label>
                                </form>
                                <?php if ($hasCustomIcon): ?>
                                  <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                    <input type="hidden" name="action" value="remove_icon">
                                    <input type="hidden" name="name" value="<?php echo $e($cname); ?>">
                                    <button type="submit" class="ct-btn" title="Remove custom icon" aria-label="Remove custom icon"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
                                  </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this category? Videos keep their category text.');">
                                  <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                                  <input type="hidden" name="action" value="delete">
                                  <input type="hidden" name="name" value="<?php echo $e($cname); ?>">
                                  <button type="submit" class="ct-btn del" title="Delete" aria-label="Delete <?php echo $e($cname); ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                                </form>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Preset categories -->
                <div id="ctPanePresets" class="ct-presets" hidden>
                  <?php if ($availablePresets): ?>
                    <p class="text-muted small">Search for a category, then tap it to add it with its icon and colour. Once added, it disappears from here.</p>
                    <div class="position-relative mb-3">
                      <input type="search" class="form-control" id="presetSearch" placeholder="Search ready-made categories (e.g. fun, music)..." autocomplete="off" aria-label="Search ready-made categories">
                    </div>
                    <p class="text-muted small" id="presetHint">Type a word above to see matching categories.</p>
                    <p class="text-muted small" id="presetEmpty" hidden>No ready-made category matches that. You can add it yourself with "Add Category".</p>
                    <div class="cat-preset-grid" id="presetGrid">
                      <?php foreach ($availablePresets as $slug => $preset): ?>
                        <form method="post" class="preset-item" hidden data-name="<?php echo $e(mb_strtolower($preset[0] . ' ' . $slug)); ?>">
                          <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                          <input type="hidden" name="action" value="add_preset">
                          <input type="hidden" name="slug" value="<?php echo $e($slug); ?>">
                          <button type="submit" class="preset-chip">
                            <span style="color: <?php echo $e($preset[1]); ?>; display:inline-flex"><?php echo vs_category_icon_html($presetList[$slug][0], 20, '../'); ?></span>
                            <?php echo $e($preset[0]); ?>
                          </button>
                        </form>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <p class="text-muted mb-0">All ready-made categories are already in your list.</p>
                  <?php endif; ?>
                </div>

                <div class="ct-foot"><i class="bi bi-info-circle" aria-hidden="true"></i> Pencil changes the colour, the picture icon uploads your own icon, the bin deletes the category.</div>
              </section>
            </div>

            <div class="col-12 col-xl-3">
              <section class="panel" aria-label="Category stats">
                <div class="ct-head">
                  <span class="ct-tile"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                  <h2>Category Stats</h2>
                </div>
                <div class="ct-stats">
                  <div class="ct-stat"><i style="background:#7c4dff" class="bi bi-grid"></i><div><b><?php echo (int)$statTotal; ?></b><small>Total Categories</small></div></div>
                  <div class="ct-stat"><i style="background:#2f8cff" class="bi bi-image"></i><div><b><?php echo (int)$statCustom; ?></b><small>With Custom Icons</small></div></div>
                  <div class="ct-stat"><i style="background:#27c77a" class="bi bi-stars"></i><div><b><?php echo (int)$statPreset; ?></b><small>Preset Categories</small></div></div>
                  <div class="ct-stat"><i style="background:#f5a623" class="bi bi-hourglass-split"></i><div><b><?php echo (int)$statLeft; ?></b><small>Remaining Slots</small></div></div>
                </div>
              </section>

              <section class="panel mt-3" aria-label="Quick actions">
                <div class="ct-head">
                  <span class="ct-tile"><i class="bi bi-lightning-charge" aria-hidden="true"></i></span>
                  <h2>Quick Actions</h2>
                </div>
                <div class="px-3 pb-2">
                  <button type="button" class="ct-quick" data-bs-toggle="modal" data-bs-target="#ctAddModal">
                    <span class="ic"><i class="bi bi-plus-lg" aria-hidden="true"></i></span>
                    <span><strong>Add New Category</strong><small>Create a custom category</small></span>
                  </button>
                  <button type="button" class="ct-quick" id="ctGoPresets"<?php echo $availablePresets ? '' : ' disabled'; ?>>
                    <span class="ic"><i class="bi bi-grid" aria-hidden="true"></i></span>
                    <span><strong>Add Preset Categories</strong><small>Choose from predefined list</small></span>
                  </button>
                  <form method="post" class="m-0">
                    <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
                    <input type="hidden" name="action" value="add_all">
                    <button type="submit" class="ct-quick"<?php echo $standardLeft > 0 ? '' : ' disabled'; ?>>
                      <span class="ic"><i class="bi bi-stack" aria-hidden="true"></i></span>
                      <span><strong>Add All Presets</strong><small>Add every ready-made category (up to the limit)</small></span>
                    </button>
                  </form>
                </div>
              </section>

              <section class="panel mt-3" aria-label="Tips">
                <div class="ct-head">
                  <span class="ct-tile"><i class="bi bi-info-circle" aria-hidden="true"></i></span>
                  <h2>Tips</h2>
                </div>
                <ul class="ct-tips">
                  <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Use clear and unique names.</li>
                  <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Choose a color that matches the category.</li>
                  <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Custom icons must be under 512 KB.</li>
                  <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>You can have up to <?php echo (int)VS_MAX_CATEGORIES; ?> categories.</li>
                </ul>
              </section>
            </div>
          </div>
        </div>
      </main>

      <!-- Add category -->
      <div class="modal fade" id="ctAddModal" tabindex="-1" aria-labelledby="ctAddTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
          <form method="post" class="modal-content" autocomplete="off" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h2 class="modal-title h5" id="ctAddTitle">Add Category</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <label class="form-label" for="ctName">Name</label>
              <input class="form-control mb-3" id="ctName" type="text" name="name" maxlength="30" placeholder="New category name..." required>
              <div class="d-flex gap-3 flex-wrap">
                <div>
                  <label class="form-label" for="catColor">Colour</label>
                  <div class="d-flex align-items-center gap-2"><input type="color" class="form-control form-control-color" name="color" id="catColor" value="#7c3aed"><span id="catColorHex" class="small">#7C3AED</span></div>
                </div>
                <div class="flex-grow-1">
                  <label class="form-label" for="catIconFile">Icon (optional)</label>
                  <input class="form-control" type="file" name="icon" id="catIconFile" accept="<?php echo $e($iconTypes); ?>">
                </div>
              </div>
              <p class="text-muted small mt-3 mb-0">Your own icons: PNG, JPG, WEBP, GIF or SVG, up to 512 KB. Square images look best.</p>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add</button></div>
          </form>
        </div>
      </div>

      <!-- Change colour -->
      <div class="modal fade" id="ctColorModal" tabindex="-1" aria-labelledby="ctColorTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
          <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo $e($csrf); ?>">
            <input type="hidden" name="action" value="set_color">
            <input type="hidden" name="name" id="ctColorName" value="">
            <div class="modal-header"><h2 class="modal-title h5" id="ctColorTitle">Change colour</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
              <p class="fw-semibold mb-2" id="ctColorLabel"></p>
              <input type="color" class="form-control form-control-color" name="color" id="ctColorInput" value="#7c3aed">
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
          </form>
        </div>
      </div>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>VibeStream Admin Panel</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="./assets/js/bootstrap.bundle.min.js"></script>
  <script src="./assets/js/main.js"></script>
  <script>
  (function () {
    // Tabs: All categories / Preset categories
    var paneAll = document.getElementById('ctPaneAll'), panePre = document.getElementById('ctPanePresets');
    var tabs = document.querySelectorAll('.ct-tab[data-tab]');
    function go(which) {
      if (which === 'presets' && panePre.querySelector('#presetGrid') === null && !panePre.textContent.trim()) { which = 'all'; }
      paneAll.hidden = which !== 'all';
      panePre.hidden = which !== 'presets';
      tabs.forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-tab') === which); });
      if (which === 'presets') { var s = document.getElementById('presetSearch'); if (s) { s.focus(); } }
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { if (!t.disabled) { go(t.getAttribute('data-tab')); } }); });
    var quick = document.getElementById('ctGoPresets');
    if (quick) { quick.addEventListener('click', function () { go('presets'); }); }

    // Colour pickers
    var picker = document.getElementById('catColor'), hex = document.getElementById('catColorHex');
    if (picker && hex) { picker.addEventListener('input', function () { hex.textContent = picker.value.toUpperCase(); }); }
    var cm = document.getElementById('ctColorModal');
    if (cm) {
      cm.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget; if (!b) { return; }
        document.getElementById('ctColorName').value = b.getAttribute('data-name') || '';
        document.getElementById('ctColorLabel').textContent = b.getAttribute('data-name') || '';
        document.getElementById('ctColorInput').value = b.getAttribute('data-color') || '#7c3aed';
      });
    }

    // Preset search
    var box = document.getElementById('presetSearch'), empty = document.getElementById('presetEmpty'),
        hint = document.getElementById('presetHint'), items = document.querySelectorAll('#presetGrid .preset-item');
    if (box) {
      box.addEventListener('input', function () {
        var q = box.value.trim().toLowerCase(), shown = 0;
        items.forEach(function (el) {
          var match = q !== '' && el.getAttribute('data-name').indexOf(q) !== -1;
          el.hidden = !match; if (match) { shown++; }
        });
        if (empty) { empty.hidden = !(q !== '' && shown === 0); }
        if (hint) { hint.hidden = q !== ''; }
      });
    }
  })();
  </script>
</body>
</html>