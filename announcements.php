<?php
session_start();
require_once __DIR__ . '/connection.php';
require_once __DIR__ . '/announcements-store.php';
require_once __DIR__ . '/announcements-meta.php';
require_once __DIR__ . '/layout.php';

$updates = [];
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        vs_updates_ensure($pdo);
        vs_um_ensure($pdo);
        $updates = vs_updates_public($pdo);
        $meta = vs_um_all($pdo);
    } catch (Throwable $e) {
        error_log('Public announcements could not be loaded: ' . $e->getMessage());
        $updates = [];
        $meta = [];
    }
}

$e = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};

vs_page_start('Announcements', '');
?>
<style>
.announcements-page { --announcement-surface:#0d0909;--announcement-hero:linear-gradient(120deg,#180708,#090909 75%);--announcement-text:#f1f1f1;--announcement-muted:#cbbabb;--announcement-meta:#9f8f90;--announcement-border:rgba(255,42,50,.22);--announcement-thumb:#16090a;max-width:980px;margin:0 auto;padding:32px 24px 64px;color:var(--announcement-text); }
.announcements-hero { display:flex;align-items:center;gap:18px;margin-bottom:24px;padding:24px;border:1px solid rgba(255,42,50,.32);border-radius:18px;background:var(--announcement-hero); }
.announcements-hero-icon { width:64px;height:64px;display:grid;place-items:center;border-radius:18px;background:linear-gradient(145deg,#ff343c,#a50a11);font-size:30px;box-shadow:0 12px 28px rgba(255,26,32,.25); }
.announcements-hero h1 { margin:0;font-size:clamp(28px,5vw,42px);color:var(--announcement-text); }
.announcements-hero p { margin:6px 0 0;color:var(--announcement-muted); }
.announcement-list { display:grid; gap:16px; }
.announcement-card { display:grid;grid-template-columns:120px 1fr;gap:18px;padding:18px;border:1px solid var(--announcement-border);border-radius:16px;background:var(--announcement-surface);color:var(--announcement-text); }
.announcement-thumb { width:120px;height:90px;border-radius:12px;object-fit:cover;background:var(--announcement-thumb); }
.announcement-card h2 { margin:3px 0 7px;font-size:20px;color:var(--announcement-text); }
.announcement-card p { margin:0;color:var(--announcement-muted);line-height:1.6;white-space:pre-wrap; }
.announcement-meta { display:flex;flex-wrap:wrap;gap:10px;margin-top:12px;color:var(--announcement-meta);font-size:12px; }
.announcement-kind { color:#ff6269;font-weight:800;text-transform:uppercase;letter-spacing:.06em; }
.announcements-empty { padding:44px 20px;text-align:center;border:1px solid var(--announcement-border);border-radius:16px;color:var(--announcement-muted);background:var(--announcement-surface); }
html[data-theme="light"] .announcements-page { --announcement-surface:#fff;--announcement-hero:linear-gradient(120deg,#fff,#fff7f7 75%);--announcement-text:#17181c;--announcement-muted:#626873;--announcement-meta:#7a808b;--announcement-border:rgba(190,25,34,.2);--announcement-thumb:#eceef3; }
html[data-theme="light"] .announcements-hero,
html[data-theme="light"] .announcement-card,
html[data-theme="light"] .announcements-empty { box-shadow:0 2px 10px rgba(24,32,48,.07); }
@media(max-width:600px){.announcement-card{grid-template-columns:1fr}.announcement-thumb{width:100%;height:180px}}
</style>
<div class="announcements-page">
  <header class="announcements-hero">
    <span class="announcements-hero-icon" aria-hidden="true">📢</span>
    <div><h1>Announcements</h1><p>Latest updates and upcoming VibeStream events.</p></div>
  </header>
  <section class="announcement-list" aria-label="Announcements">
  <?php if (!$updates): ?>
    <div class="announcements-empty">No announcements yet.</div>
  <?php else: foreach ($updates as $update): ?>
    <?php
      $id = (int)$update['id'];
      $thumb = $meta[$id]['thumb'] ?? null;
      $kind = (string)$update['kind'];
      $labels = ['announcement' => 'Announcement', 'upcoming' => 'Upcoming update', 'release' => 'Release notes', 'maintenance' => 'Maintenance'];
    ?>
    <article class="announcement-card" id="update-<?php echo $id; ?>">
      <?php if ($thumb): ?>
        <img class="announcement-thumb" src="<?php echo $e($thumb); ?>" alt="">
      <?php else: ?>
        <div class="announcement-thumb" aria-hidden="true"></div>
      <?php endif; ?>
      <div>
        <span class="announcement-kind"><?php echo $e($labels[$kind] ?? 'Announcement'); ?></span>
        <h2><?php echo $e($update['title']); ?></h2>
        <p><?php echo $e($update['body']); ?></p>
        <div class="announcement-meta">
          <span>Published <?php echo $e(date('M j, Y g:i A', strtotime((string)$update['publish_at']))); ?></span>
          <?php if (!empty($update['event_at'])): ?>
            <span>Event <?php echo $e(date('M j, Y g:i A', strtotime((string)$update['event_at']))); ?></span>
          <?php endif; ?>
        </div>
      </div>
    </article>
  <?php endforeach; endif; ?>
  </section>
</div>
<?php vs_page_end(); ?>
