<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/header.php';
require_once __DIR__ . '/dashboard-overview-data.php';

/* ------------------------------------------------------------------
   Config: edit the hrefs below if your page file names are different.
   Items whose route is not allowed for the current admin are hidden.
------------------------------------------------------------------ */
$ccAllowed = static function (string $href): bool {
    $file = strtok($href, '?');
    return function_exists('vs_admin_route_allowed') ? (bool)vs_admin_route_allowed($file) : true;
};

$ccTiles = [
    ['title' => 'Manage Users',       'sub' => 'View and manage users',        'icon' => 'bi-people-fill',          'href' => 'users.php',             'c' => 'cyan'],
    ['title' => 'Manage Videos',      'sub' => 'Edit, delete or feature videos','icon' => 'bi-play-fill',           'href' => 'videos.php',            'c' => 'pink'],
    ['title' => 'View Analytics',     'sub' => 'Check platform statistics',    'icon' => 'bi-bar-chart-fill',       'href' => 'analytics.php',         'c' => 'violet'],
    ['title' => 'View Comments',      'sub' => 'Moderate comments',            'icon' => 'bi-chat-square-text-fill','href' => 'comments.php',          'c' => 'green'],
    ['title' => 'Manage Likes',       'sub' => 'View liked content',           'icon' => 'bi-hand-thumbs-up-fill',  'href' => 'likes.php',             'c' => 'amber'],
    ['title' => 'Platform Settings',  'sub' => 'Control site settings',        'icon' => 'bi-gear-fill',            'href' => 'platform-settings.php', 'c' => 'red'],
    ['title' => 'Support Center',     'sub' => 'Handle user support',          'icon' => 'bi-headset',              'href' => 'support.php',           'c' => 'violet'],
    ['title' => 'Reported Content',   'sub' => 'Review reported items',        'icon' => 'bi-shield-check',         'href' => 'reported.php',          'c' => 'cyan',  'badge' => (int)($dashboardOverview['reports'] ?? 0)],
    ['title' => 'Top Creators',       'sub' => 'See top contributors',         'icon' => 'bi-award-fill',           'href' => 'top-creators.php',      'c' => 'green'],
];

$ccImportant = [
    ['title' => 'Ban User',           'desc' => 'Restrict a user from the platform', 'href' => 'users.php'],
    ['title' => 'Delete Video',       'desc' => 'Remove any video',                  'href' => 'videos.php'],
    ['title' => 'Send Notification',  'desc' => 'Notify all users',                  'href' => 'messages.php'],
    ['title' => 'Export Data',        'desc' => 'Download user data',                'href' => 'users.php'],
    ['title' => 'Clear Cache',        'desc' => 'Refresh the platform',              'href' => 'platform-settings.php'],
];

$ccQuick = [
    ['label' => 'Add New Video', 'icon' => 'bi-plus-lg',         'href' => 'videos.php',            'class' => 'qa-violet'],
    ['label' => 'Add New User',  'icon' => 'bi-person-plus-fill','href' => 'users.php',             'class' => 'qa-cyan'],
    ['label' => 'View Reports',  'icon' => 'bi-bar-chart-fill',  'href' => 'reported.php',          'class' => 'qa-green'],
    ['label' => 'Settings',      'icon' => 'bi-gear-fill',       'href' => 'platform-settings.php', 'class' => 'qa-pink'],
];

$ccTiles     = array_values(array_filter($ccTiles, fn($t) => $ccAllowed($t['href'])));
$ccImportant = array_values(array_filter($ccImportant, fn($t) => $ccAllowed($t['href'])));
$ccQuick     = array_values(array_filter($ccQuick, fn($t) => $ccAllowed($t['href'])));
$ccCount     = count($ccTiles);
?>
<style>
  #ccPage {
    --cc-cyan: #00e5ff;   --cc-cyan-rgb: 0, 229, 255;
    --cc-pink: #ff2e93;   --cc-pink-rgb: 255, 46, 147;
    --cc-violet: #8a5cff; --cc-violet-rgb: 138, 92, 255;
    --cc-green: #22e58a;  --cc-green-rgb: 34, 229, 138;
    --cc-amber: #ffb020;  --cc-amber-rgb: 255, 176, 32;
    --cc-red: #ff3d77;    --cc-red-rgb: 255, 61, 119;
    --cc-text: #eaf0ff;
    --cc-muted: #9aa6d8;
    --cc-card: rgba(12, 14, 36, 0.9);
    color: var(--cc-text);
    min-height: calc(100vh - 80px);
    background: radial-gradient(900px 480px at 8% -8%, rgba(138,92,255,.18), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,229,255,.10), transparent 70%),
                #05060f;
  }

  html[data-theme="light"] #ccPage,
  html[data-bs-theme="light"] #ccPage {
    --cc-cyan: #00a9d6;  --cc-cyan-rgb: 0, 169, 214;
    --cc-violet: #7c4dff; --cc-violet-rgb: 124, 77, 255;
    --cc-green: #0fb86a;  --cc-green-rgb: 15, 184, 106;
    --cc-amber: #f59e0b;  --cc-amber-rgb: 245, 158, 11;
    --cc-red: #e11d63;    --cc-red-rgb: 225, 29, 99;
    --cc-text: #0b1030;
    --cc-muted: #55608f;
    --cc-card: #ffffff;
    background: radial-gradient(900px 480px at 8% -8%, rgba(124,77,255,.12), transparent 70%),
                radial-gradient(800px 420px at 100% 0, rgba(0,184,230,.12), transparent 70%),
                #f4f6ff;
  }

  /* ---------- Banner ---------- */
  #ccPage .cc-banner {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 22px;
    border-radius: 18px;
    border: 1px solid rgba(var(--cc-violet-rgb), 0.55);
    background: linear-gradient(115deg, rgba(var(--cc-violet-rgb), 0.22), rgba(12, 14, 36, 0.92) 45%, rgba(var(--cc-cyan-rgb), 0.1));
    box-shadow: 0 0 30px -10px rgba(var(--cc-violet-rgb), 0.7);
  }
  html[data-theme="light"] #ccPage .cc-banner,
  html[data-bs-theme="light"] #ccPage .cc-banner {
    background: linear-gradient(115deg, rgba(var(--cc-violet-rgb), 0.14), #fff 50%, rgba(var(--cc-cyan-rgb), 0.1));
  }
  #ccPage .cc-banner-left { display: flex; align-items: center; gap: 18px; min-width: 0; }
  #ccPage .cc-banner-icon {
    flex: 0 0 64px;
    width: 64px;
    height: 64px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    font-size: 1.9rem;
    color: #fff;
    background: linear-gradient(135deg, #b44dff, #6a3dff);
    box-shadow: 0 0 22px rgba(var(--cc-violet-rgb), 0.7);
  }
  #ccPage .cc-banner h1 { margin: 0; font-size: 2rem; font-weight: 700; color: var(--cc-text); line-height: 1.1; }
  #ccPage .cc-banner p { margin: 4px 0 0; color: var(--cc-muted); font-size: 1.02rem; }
  #ccPage .cc-pill {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    border-radius: 999px;
    border: 1.5px solid var(--cc-cyan);
    background: rgba(var(--cc-cyan-rgb), 0.1);
    color: var(--cc-cyan);
    font-size: 0.85rem;
    font-weight: 700;
    box-shadow: 0 0 16px rgba(var(--cc-cyan-rgb), 0.35);
    white-space: nowrap;
  }
  #ccPage .cc-pill::before {
    content: "";
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: var(--cc-cyan);
    box-shadow: 0 0 8px var(--cc-cyan);
  }

  /* ---------- Tiles ---------- */
  #ccPage .cc-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-top: 16px;
  }
  #ccPage .cc-tile {
    --c: var(--cc-cyan);
    --c-rgb: var(--cc-cyan-rgb);
    position: relative;
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 18px;
    min-height: 92px;
    border-radius: 16px;
    border: 1px solid rgba(var(--c-rgb), 0.55);
    background: linear-gradient(135deg, rgba(var(--c-rgb), 0.12), var(--cc-card) 70%);
    box-shadow: 0 0 22px -12px rgba(var(--c-rgb), 0.9);
    color: var(--cc-text);
    text-decoration: none;
    transition: transform 0.15s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  }
  #ccPage .cc-tile:hover {
    transform: translateY(-2px);
    border-color: var(--c);
    box-shadow: 0 0 26px -4px rgba(var(--c-rgb), 0.7);
    color: var(--cc-text);
    text-decoration: none;
  }
  #ccPage .cc-tile[data-c="cyan"]   { --c: var(--cc-cyan);   --c-rgb: var(--cc-cyan-rgb); }
  #ccPage .cc-tile[data-c="pink"]   { --c: var(--cc-pink);   --c-rgb: var(--cc-pink-rgb); }
  #ccPage .cc-tile[data-c="violet"] { --c: var(--cc-violet); --c-rgb: var(--cc-violet-rgb); }
  #ccPage .cc-tile[data-c="green"]  { --c: var(--cc-green);  --c-rgb: var(--cc-green-rgb); }
  #ccPage .cc-tile[data-c="amber"]  { --c: var(--cc-amber);  --c-rgb: var(--cc-amber-rgb); }
  #ccPage .cc-tile[data-c="red"]    { --c: var(--cc-red);    --c-rgb: var(--cc-red-rgb); }

  #ccPage .cc-tile-icon {
    flex: 0 0 56px;
    width: 56px;
    height: 56px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    border: 1px solid rgba(var(--c-rgb), 0.35);
    background: rgba(var(--c-rgb), 0.14);
    color: var(--c);
    font-size: 1.7rem;
  }
  #ccPage .cc-tile strong { display: block; font-size: 1.08rem; font-weight: 700; color: var(--cc-text); }
  #ccPage .cc-tile small { display: block; margin-top: 3px; font-size: 0.88rem; color: var(--cc-muted); }
  #ccPage .cc-tile-badge {
    position: absolute;
    top: 10px;
    right: 12px;
    min-width: 22px;
    padding: 2px 7px;
    border-radius: 999px;
    background: var(--cc-red);
    color: #fff;
    font-size: 0.72rem;
    font-weight: 700;
    text-align: center;
  }

  /* ---------- Lower panels ---------- */
  #ccPage .cc-lower {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
    gap: 14px;
    margin-top: 16px;
  }
  #ccPage .cc-panel {
    padding: 18px 20px;
    border-radius: 18px;
    border: 1px solid rgba(var(--cc-violet-rgb), 0.5);
    background: var(--cc-card);
    box-shadow: 0 0 26px -12px rgba(var(--cc-violet-rgb), 0.7);
  }
  #ccPage .cc-panel-head { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
  #ccPage .cc-panel-head i { font-size: 1.5rem; color: var(--cc-violet); }
  #ccPage .cc-panel-head h2 { margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--cc-text); }
  #ccPage .cc-panel-head p { margin: 2px 0 0; font-size: 0.88rem; color: var(--cc-muted); }

  #ccPage .cc-cmd-list { margin: 0; padding: 0; list-style: none; }
  #ccPage .cc-cmd-list li { border-top: 1px solid rgba(var(--cc-violet-rgb), 0.22); }
  #ccPage .cc-cmd-list a {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 10px 4px;
    color: var(--cc-muted);
    text-decoration: none;
    font-size: 0.95rem;
  }
  #ccPage .cc-cmd-list a::before {
    content: "";
    flex: 0 0 11px;
    width: 11px;
    height: 11px;
    border-radius: 50%;
    background: var(--cc-violet);
    box-shadow: 0 0 8px rgba(var(--cc-violet-rgb), 0.8);
  }
  #ccPage .cc-cmd-list a strong { color: var(--cc-text); font-weight: 600; }
  #ccPage .cc-cmd-list a:hover { color: var(--cc-text); background: rgba(var(--cc-violet-rgb), 0.08); text-decoration: none; }

  #ccPage .cc-quick-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  #ccPage .cc-qa {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 62px;
    padding: 10px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.18);
    color: #fff;
    font-weight: 700;
    font-size: 1rem;
    text-decoration: none;
    transition: transform 0.15s ease, box-shadow 0.18s ease, filter 0.18s ease;
  }
  #ccPage .cc-qa i { font-size: 1.3rem; }
  #ccPage .cc-qa:hover { transform: translateY(-2px); filter: brightness(1.12); color: #fff; text-decoration: none; }
  #ccPage .qa-violet { background: linear-gradient(135deg, #6a3dff, #4a2bd6); box-shadow: 0 0 18px -4px rgba(138, 92, 255, 0.8); }
  #ccPage .qa-cyan   { background: linear-gradient(135deg, #00c8ff, #0a74e6); box-shadow: 0 0 18px -4px rgba(0, 200, 255, 0.8); }
  #ccPage .qa-green  { background: linear-gradient(135deg, #17c964, #0b8f55); box-shadow: 0 0 18px -4px rgba(34, 229, 138, 0.7); }
  #ccPage .qa-pink   { background: linear-gradient(135deg, #ff2e7e, #c71d6d); box-shadow: 0 0 18px -4px rgba(255, 46, 147, 0.8); }

  #ccPage .cc-empty {
    padding: 14px;
    border: 1px dashed rgba(var(--cc-violet-rgb), 0.4);
    border-radius: 12px;
    color: var(--cc-muted);
    font-size: 0.9rem;
  }

  /* ---------- Responsive ---------- */
  @media (max-width: 1100px) {
    #ccPage .cc-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    #ccPage .cc-lower { grid-template-columns: 1fr; }
  }
  @media (max-width: 640px) {
    #ccPage .cc-grid { grid-template-columns: 1fr; }
    #ccPage .cc-banner { flex-direction: column; align-items: flex-start; }
    #ccPage .cc-banner h1 { font-size: 1.5rem; }
    #ccPage .cc-banner p { font-size: 0.92rem; }
    #ccPage .cc-quick-grid { grid-template-columns: 1fr; }
  }
</style>

<div id="ccPage" class="container-fluid px-3 px-lg-4 py-4">

  <!-- Banner -->
  <section class="cc-banner" aria-label="Admin commands">
    <div class="cc-banner-left">
      <div class="cc-banner-icon" aria-hidden="true"><i class="bi bi-lightning-charge-fill"></i></div>
      <div>
        <h1>Admin Commands</h1>
        <p>Manage and use powerful admin commands</p>
      </div>
    </div>
    <span class="cc-pill"><?php echo (int)$ccCount; ?> Commands</span>
  </section>

  <!-- Command tiles -->
  <section class="cc-grid" aria-label="Command shortcuts">
    <?php foreach ($ccTiles as $tile): ?>
      <a class="cc-tile" data-c="<?php echo vs_e($tile['c']); ?>" href="<?php echo vs_e($tile['href']); ?>">
        <span class="cc-tile-icon" aria-hidden="true"><i class="bi <?php echo vs_e($tile['icon']); ?>"></i></span>
        <span>
          <strong><?php echo vs_e($tile['title']); ?></strong>
          <small><?php echo vs_e($tile['sub']); ?></small>
        </span>
        <?php if (!empty($tile['badge'])): ?>
          <span class="cc-tile-badge"><?php echo vs_e(number_format((int)$tile['badge'])); ?></span>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </section>

  <!-- Important commands + Quick actions -->
  <div class="cc-lower">
    <section class="cc-panel" aria-label="Important commands">
      <div class="cc-panel-head">
        <i class="bi bi-list-ul" aria-hidden="true"></i>
        <div>
          <h2>Important Commands</h2>
          <p>Quick access to essential actions</p>
        </div>
      </div>
      <?php if ($ccImportant): ?>
        <ul class="cc-cmd-list">
          <?php foreach ($ccImportant as $cmd): ?>
            <li>
              <a href="<?php echo vs_e($cmd['href']); ?>">
                <span><strong><?php echo vs_e($cmd['title']); ?></strong> &ndash; <?php echo vs_e($cmd['desc']); ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <div class="cc-empty">No commands available for your role.</div>
      <?php endif; ?>
    </section>

    <section class="cc-panel" aria-label="Quick actions">
      <div class="cc-panel-head">
        <i class="bi bi-rocket-takeoff-fill" aria-hidden="true"></i>
        <div>
          <h2>Quick Actions</h2>
          <p>Get things done faster</p>
        </div>
      </div>
      <?php if ($ccQuick): ?>
        <div class="cc-quick-grid">
          <?php foreach ($ccQuick as $qa): ?>
            <a class="cc-qa <?php echo vs_e($qa['class']); ?>" href="<?php echo vs_e($qa['href']); ?>">
              <i class="bi <?php echo vs_e($qa['icon']); ?>" aria-hidden="true"></i>
              <?php echo vs_e($qa['label']); ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="cc-empty">No quick actions available for your role.</div>
      <?php endif; ?>
    </section>
  </div>

</div>