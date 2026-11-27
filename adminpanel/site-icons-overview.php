<?php
/*
 * site-icons-overview.php  (put it in the adminpanel folder, next to site-icons.php)
 * The new red-and-black "Overview" tab of the Site Icons page. It is included by site-icons.php
 * and uses that page's variables and functions, so nothing else needs to change.
 */
if (!isset($csrf)) {
    return;
}
$ovName = (isset($siteName) && trim((string)$siteName) !== '') ? (string)$siteName : 'VibeStream';

$ovAdminAll  = $siItems['admin'] ?? [];
$ovAdmin     = array_slice($ovAdminAll, 0, 10, true);
$ovAdminMore = max(0, count($ovAdminAll) - 10);
$ovCustomAll = $acsb['items'] ?? [];
$ovCustom    = array_slice($ovCustomAll, 0, 10);
$ovCustomMore = max(0, count($ovCustomAll) - 10);

/* Quick icon tiles: the first custom icon of a group if there is one, otherwise a built-in symbol. */
$ovScopeIcon = function (string $scope, string $fallback) use ($siMap, $siItems): string {
    foreach ($siItems[$scope] ?? [] as $key => $def) {
        if (!empty($siMap[$scope][$key])) {
            return vs_si_preview($siMap, $scope, (string)$key, (string)$def[1]);
        }
    }
    return '<i class="bi ' . $fallback . '" aria-hidden="true"></i>';
};
$ovTiles = [];
$ovTiles[] = ['Website Icon', '#sec-website-sidebar', $ovScopeIcon('site', 'bi-house-fill')];
$ovTiles[] = ['Admin Panel Icon', '#sec-admin', $ovScopeIcon('admin', 'bi-gear-fill')];
$ovFallback = ['Menu' => 'bi-controller', 'Explore' => 'bi-compass-fill', 'Button' => 'bi-star-fill'];
foreach (vs_ui_icon_groups() as $group => $names) {
    $firstName = array_key_first($names);
    $custom = $firstName !== null ? ($uiMap[$firstName] ?? null) : null;
    $gid = 'sec-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)$group));
    $html = $custom
        ? '<img src="../uploads/ui-icons/' . vs_e($custom) . '" alt="">'
        : '<i class="bi ' . ($ovFallback[$group] ?? 'bi-grid-fill') . '" aria-hidden="true"></i>';
    $ovTiles[] = [$group . ' Icon', '#' . $gid, $html];
}
$ovFirstCat = $categories ? (string)reset($categories) : '';
$ovTiles[] = ['Category Icons', '#sec-categories',
    $ovFirstCat !== '' ? vs_category_icon_html($ovFirstCat, 40, '../') : '<i class="bi bi-tags-fill" aria-hidden="true"></i>'];
?>
<style>
  /* ===== New overview look (red and black, matches the design) ===== */
  .si-page .vn-rail{display:none!important}
  .si-page .vn-layout{display:block!important}
  .si-red{color:#ff1f2d}
  .si-page .page-heading .page-icon{background:linear-gradient(145deg,#ff343c,#a50e16)!important;color:#fff!important;border:1px solid rgba(255,90,95,.6)!important;box-shadow:0 0 24px rgba(255,30,40,.45)!important}

  .ov-grid2{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,7fr);gap:16px;margin-top:16px}
  @media (max-width:1199px){.ov-grid2{grid-template-columns:minmax(0,1fr)}}
  .ov-card{position:relative;padding:18px 20px;border-radius:16px;border:1px solid var(--si-ring,rgba(255,42,50,.5));margin-top:16px;
    background:linear-gradient(145deg,var(--vn-card,#120909),var(--vn-card2,#080808));box-shadow:0 12px 36px rgba(0,0,0,.28),0 0 22px var(--si-glow,rgba(255,25,35,.25))}
  .ov-grid2 .ov-card{margin-top:0}
  .ov-head{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
  .ov-head h2{margin:0;font-size:1.2rem;font-weight:700;color:var(--vn-strong,#fff)}
  .ov-hico{font-size:1.7rem;line-height:1;color:#ff2b3a;filter:drop-shadow(0 0 8px rgba(255,40,50,.7))}
  .ov-head .ov-right{margin-left:auto;display:flex;gap:8px;align-items:center}
  .ov-badge{padding:3px 14px;border-radius:8px;font-size:.78rem;font-weight:700;color:#fff;background:linear-gradient(135deg,#ff343c,#b50d16);box-shadow:0 0 14px rgba(255,30,40,.4)}
  .ov-sub{margin:6px 0 0;font-size:.84rem;color:var(--vn-muted,#b8a8aa)}

  .ov-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 18px;border-radius:10px;border:1px solid var(--si-ring,rgba(255,42,50,.5));
    background:rgba(255,35,45,.08);color:var(--vn-text,#f6f1f1);font:inherit;font-size:.86rem;font-weight:600;cursor:pointer;text-decoration:none;transition:background .15s,box-shadow .15s}
  .ov-btn:hover{background:rgba(255,35,45,.2);box-shadow:0 0 16px rgba(255,35,45,.3);color:var(--vn-text,#fff)}
  .ov-btn-primary{background:linear-gradient(135deg,#ff343c,#ad0c13);border-color:rgba(255,90,95,.6);color:#fff;box-shadow:0 4px 16px rgba(255,30,40,.3)}
  .ov-btn[disabled]{opacity:.45;cursor:not-allowed;box-shadow:none}
  .ov-btn-sm{padding:7px 14px;font-size:.8rem;width:100%}
  .ov-link{background:none;border:0;padding:0;color:var(--vn-muted,#b8a8aa);font:inherit;font-size:.78rem;text-decoration:underline;cursor:pointer}
  .ov-link:hover{color:#ff6b73}

  /* App logo */
  .ov-logo-grid{display:grid;grid-template-columns:150px minmax(0,1fr) 150px;gap:14px;align-items:stretch;margin-top:12px}
  @media (max-width:575px){.ov-logo-grid{grid-template-columns:minmax(0,1fr)}}
  .ov-logo-col{display:flex;flex-direction:column;gap:8px}
  .ov-prev{aspect-ratio:1/1;border-radius:16px;display:flex;align-items:center;justify-content:center;overflow:hidden;
    background:radial-gradient(circle at 50% 35%,#2a0a0d,#0a0606);border:1px solid var(--si-ring,rgba(255,42,50,.5));box-shadow:0 0 22px rgba(255,30,40,.3);transition:background .2s}
  .ov-prev img{max-width:78%;max-height:78%;object-fit:contain;display:block}
  .ov-prev.ov-light{background:#f5f1f1}
  .ov-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;text-align:center;min-height:150px;padding:12px;border:1.5px dashed var(--si-ring,rgba(255,42,50,.6));border-radius:14px;
    background:rgba(255,35,45,.04);cursor:pointer;color:var(--vn-text,#f6f1f1);transition:background .15s,border-color .15s}
  .ov-drop:hover,.ov-drop.ov-over{background:rgba(255,35,45,.12);border-color:#ff3b43}
  .ov-drop i{font-size:2.4rem;color:#ff2b3a;filter:drop-shadow(0 0 8px rgba(255,40,50,.6))}
  .ov-drop b{font-size:.95rem}
  .ov-drop span{font-size:.78rem;color:var(--vn-muted,#b8a8aa)}
  .ov-drop em{font-style:normal;font-size:.74rem;color:#ff8589;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ov-opt{display:flex;flex-direction:column;justify-content:center;gap:10px;padding-left:14px;border-left:1px solid var(--vn-line-soft,rgba(255,42,50,.3))}
  @media (max-width:575px){.ov-opt{border-left:0;padding-left:0}}
  .ov-opt-h{font-size:.78rem;color:var(--vn-muted,#b8a8aa)}
  .ov-opt small{font-size:.74rem;color:var(--vn-muted,#b8a8aa)}
  .ov-switch{position:relative;display:inline-block;width:54px;height:30px;margin:0}
  .ov-switch input{position:absolute;opacity:0;inset:0;width:100%;height:100%;margin:0;cursor:pointer;z-index:1}
  .ov-slider{position:absolute;inset:0;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid var(--si-ring,rgba(255,42,50,.5));transition:background .2s}
  .ov-slider::after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:transform .2s}
  .ov-switch input:checked + .ov-slider{background:linear-gradient(135deg,#ff343c,#b50d16)}
  .ov-switch input:checked + .ov-slider::after{transform:translateX(24px)}
  .ov-switch input:focus-visible + .ov-slider{outline:2px solid #ff343c;outline-offset:2px}
  .ov-foot{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:12px}

  /* Quick icons */
  .ov-quick{display:grid;grid-template-columns:repeat(auto-fit,minmax(112px,1fr));gap:12px;margin-top:14px}
  .ov-q{display:flex;flex-direction:column;align-items:center;gap:10px;padding:10px 8px 12px;border-radius:14px;text-decoration:none;color:var(--vn-text,#f6f1f1);
    background:linear-gradient(160deg,rgba(255,255,255,.04),rgba(255,35,45,.03));border:1px solid var(--vn-line-soft,rgba(255,42,50,.3));transition:transform .15s,border-color .15s,box-shadow .15s}
  .ov-q:hover{transform:translateY(-2px);border-color:#ff343c;box-shadow:0 0 20px rgba(255,40,50,.35);color:var(--vn-text,#fff)}
  .ov-qbox{width:84px;height:84px;border-radius:20px;display:flex;align-items:center;justify-content:center;font-size:2.7rem;color:#ff2b3a;overflow:hidden;
    background:radial-gradient(circle at 50% 28%,rgba(255,60,70,.38),rgba(120,8,14,.35) 62%,rgba(20,4,6,.6));border:1px solid rgba(255,70,76,.5);box-shadow:inset 0 0 18px rgba(255,35,45,.25),0 0 14px rgba(255,30,40,.2)}
  .ov-qbox i{filter:drop-shadow(0 0 10px rgba(255,40,50,.75))}
  .ov-qbox img{max-width:66%;max-height:66%;object-fit:contain}
  .ov-qbox svg{max-width:66%;max-height:66%}
  .ov-qname{font-size:.84rem;font-weight:600;text-align:center;line-height:1.2}

  /* Icon rows */
  .ov-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(96px,1fr));gap:10px;margin-top:16px}
  .ov-ai{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:92px;padding:10px 6px;border-radius:14px;text-decoration:none;text-align:center;color:var(--vn-text,#f6f1f1);
    background:linear-gradient(160deg,rgba(255,255,255,.05),rgba(255,35,45,.02));border:1px solid var(--vn-line-soft,rgba(255,255,255,.14));transition:border-color .15s,box-shadow .15s}
  .ov-ai:hover{border-color:#ff343c;box-shadow:0 0 16px rgba(255,40,50,.3);color:var(--vn-text,#fff)}
  .ov-ai.on{background:linear-gradient(145deg,#ff3a42,#a50e16);border-color:rgba(255,255,255,.7);color:#fff;box-shadow:0 0 22px rgba(255,35,45,.55)}
  .ov-ai-ic{display:flex;align-items:center;justify-content:center;width:38px;height:38px;font-size:1.7rem;line-height:1}
  .ov-ai-ic img{max-width:100%;max-height:100%;object-fit:contain;display:block}
  .ov-ai-ic span{max-width:100%;max-height:100%}
  .ov-ai-nm{font-size:.78rem;font-weight:600;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .ov-ai.ov-add{border-style:dashed;color:var(--vn-muted,#b8a8aa)}
  .ov-ai.ov-more{color:var(--vn-muted,#b8a8aa)}

  /* Bottom note */
  .ov-note{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-top:16px;padding:12px 18px;border-radius:14px;border:1px solid var(--si-ring,rgba(255,42,50,.5));
    background:linear-gradient(110deg,var(--vn-card,#120909),var(--vn-card2,#0a0606));box-shadow:0 0 18px var(--si-glow,rgba(255,25,35,.2))}
  .ov-note>i{font-size:1.5rem;color:#ff2b3a}
  .ov-note p{margin:0;flex:1;min-width:220px;font-size:.82rem;color:var(--vn-text,#f6f1f1)}
  .ov-note p a{color:#ff6b73;margin-left:8px;white-space:nowrap}
  .ov-brand{display:flex;align-items:center;gap:10px}
  .ov-brand img{width:36px;height:36px;object-fit:contain}
  .ov-brand b{display:block;font-size:1.05rem;line-height:1.1;color:var(--vn-strong,#fff)}
  .ov-brand small{display:block;font-size:.68rem;color:var(--vn-muted,#b8a8aa)}
</style>

<div class="si-pane si-on" id="si-pane-overview" role="tabpanel" data-pane="overview">

  <div class="ov-grid2">
    <!-- ===== App logo ===== -->
    <section class="ov-card" aria-label="App logo">
      <div class="ov-head">
        <span class="ov-hico"><i class="bi bi-gem" aria-hidden="true"></i></span>
        <h2>App Logo</h2>
        <span class="ov-badge">Current</span>
      </div>
      <p class="ov-sub">Upload a new logo for <?php echo vs_e($ovName); ?>. PNG only, square, 128&ndash;2048 px, max 4 MB.</p>

      <form method="post" enctype="multipart/form-data" id="ovLogoForm" class="ov-logo-grid">
        <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
        <input type="hidden" name="action" value="logo">
        <input type="file" name="icon" id="ovFile" accept="image/png" hidden>

        <div class="ov-logo-col">
          <div class="ov-prev" id="ovPrev">
            <?php if ($logoUrl !== ''): ?><img id="ovPrevImg" src="<?php echo vs_e($logoUrl); ?>?v=<?php echo (int)$bust; ?>" alt="Current logo"><?php else: ?><i class="bi bi-image" id="ovPrevIcon" style="font-size:2.5rem;color:#ff2b3a" aria-hidden="true"></i><img id="ovPrevImg" alt="" hidden><?php endif; ?>
          </div>
          <button type="button" class="ov-btn ov-btn-sm" id="ovChange"><i class="bi bi-pencil-fill" aria-hidden="true"></i> Change Logo</button>
        </div>

        <label class="ov-drop" id="ovDrop" for="ovFile">
          <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
          <b>Click to upload</b>
          <span>or drag and drop</span>
          <em id="ovFname"></em>
        </label>

        <div class="ov-opt">
          <span class="ov-opt-h">Logo Preview Options</span>
          <label class="ov-switch" title="Preview the logo on a light background"><input type="checkbox" id="ovLight" aria-label="Preview the logo on a light background"><span class="ov-slider"></span></label>
          <small id="ovLightLbl">Dark background</small>
        </div>
      </form>

      <div class="ov-foot">
        <button type="submit" form="ovLogoForm" class="ov-btn ov-btn-primary" id="ovGo" disabled><i class="bi bi-box-arrow-in-up" aria-hidden="true"></i> Upload logo</button>
        <form method="post" onsubmit="return confirm('Reset the app logo to the default one?');" class="d-inline">
          <input type="hidden" name="csrf" value="<?php echo vs_e($csrf); ?>">
          <input type="hidden" name="action" value="logo_reset">
          <button type="submit" class="ov-link">Reset to default</button>
        </form>
      </div>
    </section>

    <!-- ===== Quick icons ===== -->
    <section class="ov-card" aria-label="Quick icons">
      <div class="ov-head">
        <span class="ov-hico"><i class="bi bi-star-fill" aria-hidden="true"></i></span>
        <h2>Quick Icons</h2>
        <div class="ov-right"><a class="ov-btn" href="../index.php" target="_blank" rel="noopener"><i class="bi bi-eye" aria-hidden="true"></i> Preview All</a></div>
      </div>
      <div class="ov-quick">
        <?php foreach ($ovTiles as [$tLabel, $tHref, $tHtml]): ?>
          <a class="ov-q" href="<?php echo vs_e($tHref); ?>">
            <span class="ov-qbox"><?php echo $tHtml; ?></span>
            <span class="ov-qname"><?php echo vs_e($tLabel); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <!-- ===== Admin panel icons ===== -->
  <section class="ov-card" aria-label="Admin panel icons">
    <div class="ov-head">
      <span class="ov-hico"><i class="bi bi-gear-fill" aria-hidden="true"></i></span>
      <div>
        <h2>Admin Panel Icons</h2>
        <p class="ov-sub mt-0">Customize the icons used in the admin panel.</p>
      </div>
      <div class="ov-right"><a class="ov-btn" href="#sec-admin"><i class="bi bi-pencil-fill" aria-hidden="true"></i> Customize Icons</a></div>
    </div>
    <div class="ov-strip">
      <?php $ovN = 0; foreach ($ovAdmin as $key => [$aLabel, $aDef]): ?>
        <a class="ov-ai<?php echo $ovN === 0 ? ' on' : ''; ?>" href="#sec-admin" title="<?php echo vs_e($aLabel); ?>">
          <span class="ov-ai-ic"><?php echo vs_si_preview($siMap, 'admin', (string)$key, (string)$aDef); ?></span>
          <span class="ov-ai-nm"><?php echo vs_e($aLabel); ?></span>
        </a>
      <?php $ovN++; endforeach; ?>
      <?php if ($ovAdminMore > 0): ?>
        <a class="ov-ai ov-more" href="#sec-admin"><span class="ov-ai-ic"><i class="bi bi-three-dots" aria-hidden="true"></i></span><span class="ov-ai-nm">+<?php echo (int)$ovAdminMore; ?> more</span></a>
      <?php endif; ?>
    </div>
  </section>

  <!-- ===== Custom admin sidebar menu ===== -->
  <section class="ov-card" aria-label="Custom admin sidebar menu">
    <div class="ov-head">
      <span class="ov-hico"><i class="bi bi-list-ul" aria-hidden="true"></i></span>
      <div>
        <h2>Custom Admin Sidebar Menu</h2>
        <p class="ov-sub mt-0">Add or remove sidebar menu icons for your admin panel.</p>
      </div>
      <div class="ov-right"><a class="ov-btn" href="#sec-admin-custom"><i class="bi bi-pencil-fill" aria-hidden="true"></i> Customize Menu</a></div>
    </div>
    <?php if (!$acsbReady): ?>
      <p class="ov-sub">Upload <code>admin-sidebar-custom-store.php</code> to the main site folder to use this menu.</p>
    <?php else: ?>
      <div class="ov-strip">
        <?php foreach ($ovCustom as $ci): ?>
          <a class="ov-ai" href="#sec-admin-custom" title="<?php echo vs_e($ci['label']); ?>">
            <span class="ov-ai-ic"><?php if (($ci['icon'] ?? '') !== ''): ?><img src="<?php echo vs_e(vs_acsb_icon_url($ci['icon'])); ?>" alt=""><?php else: ?><i class="bi bi-link-45deg" aria-hidden="true"></i><?php endif; ?></span>
            <span class="ov-ai-nm"><?php echo vs_e($ci['label']); ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($ovCustomMore > 0): ?>
          <a class="ov-ai ov-more" href="#sec-admin-custom"><span class="ov-ai-ic"><i class="bi bi-three-dots" aria-hidden="true"></i></span><span class="ov-ai-nm">+<?php echo (int)$ovCustomMore; ?> more</span></a>
        <?php endif; ?>
        <a class="ov-ai ov-add" href="#sec-admin-custom"><span class="ov-ai-ic"><i class="bi bi-plus-lg" aria-hidden="true"></i></span><span class="ov-ai-nm">Add menu item</span></a>
      </div>
    <?php endif; ?>
  </section>

  <!-- ===== Bottom note ===== -->
  <div class="ov-note">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <p>Use PNG files for the best quality. All icons should be square and look good on both dark and light themes.
      <a href="icons.php#tab-access">Icon permissions</a></p>
    <div class="ov-brand">
      <?php if ($logoUrl !== ''): ?><img src="<?php echo vs_e($logoUrl); ?>?v=<?php echo (int)$bust; ?>" alt=""><?php endif; ?>
      <div><b><?php echo vs_e($ovName); ?></b><small>Create &bull; Watch &bull; Share</small></div>
    </div>
  </div>

  <script>
    (function () {
      var file = document.getElementById('ovFile'), go = document.getElementById('ovGo'), nm = document.getElementById('ovFname');
      var drop = document.getElementById('ovDrop'), img = document.getElementById('ovPrevImg'), icon = document.getElementById('ovPrevIcon');
      var prev = document.getElementById('ovPrev'), light = document.getElementById('ovLight'), lightLbl = document.getElementById('ovLightLbl');
      var change = document.getElementById('ovChange');
      if (!file) { return; }
      function picked() {
        var f = file.files && file.files[0];
        go.disabled = !f;
        nm.textContent = f ? f.name : '';
        if (f && img) {
          img.src = URL.createObjectURL(f); img.hidden = false;
          if (icon) { icon.hidden = true; }
        }
      }
      file.addEventListener('change', picked);
      if (change) { change.addEventListener('click', function () { file.click(); }); }
      if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) {
          drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('ov-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
          drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('ov-over'); });
        });
        drop.addEventListener('drop', function (e) {
          var files = e.dataTransfer && e.dataTransfer.files;
          if (!files || !files.length) { return; }
          if (files[0].type !== 'image/png') { nm.textContent = 'Please choose a PNG image.'; return; }
          try { file.files = files; } catch (x) { return; }
          picked();
        });
      }
      if (light) {
        light.addEventListener('change', function () {
          prev.classList.toggle('ov-light', light.checked);
          lightLbl.textContent = light.checked ? 'Light background' : 'Dark background';
        });
      }
    })();
  </script>
</div>