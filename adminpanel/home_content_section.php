<?php
/**
 * Dashboard section: "Latest Videos" + "Latest Shorts".
 * This file only DEFINES a function (no output when included), so it is safe to
 * include anywhere. Call it where you want the section to appear:
 *     <?php vs_home_content_section($pdo); ?>          // 6 cards per row
 *     <?php vs_home_content_section($pdo, 8); ?>       // 8 cards per row
 */
require_once __DIR__ . '/ui_helpers.php';

if (!function_exists('vs_home_content_section')) {
function vs_home_content_section($pdo, $vsh_limit = 6) {
$vsh_limit = max(1, (int)$vsh_limit);

$vsh_e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$vsh_short = function ($n) {
    $n = (int)$n;
    if ($n >= 1000000) { return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M'; }
    if ($n >= 1000)    { return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K'; }
    return (string)$n;
};

$vsh_cols  = vs_cols($pdo, 'videos');
$vsh_date  = vs_pick($vsh_cols, ['created_at', 'uploaded_at', 'upload_date', 'date_added', 'created_on', 'added_on', 'created', 'date']);
$vsh_thumbC = vs_pick($vsh_cols, ['thumbnail', 'thumbnail_url', 'thumbnail_path', 'thumbnail_image', 'thumbnail_file', 'thumb', 'thumb_url', 'poster', 'poster_url', 'cover', 'cover_image', 'image', 'image_url', 'image_path', 'preview']);
$vsh_srcC   = vs_pick($vsh_cols, ['video_url', 'video_path', 'video_file', 'video', 'url', 'file_path', 'filepath', 'file', 'filename', 'source', 'src', 'media_url', 'youtube_url', 'youtube_id', 'yt_id']);
$vsh_durC   = vs_pick($vsh_cols, ['duration', 'length', 'video_length']);

$vsh_media = function (string $path): string {
    $path = trim($path);
    if ($path === '' || preg_match('#^(https?:)?//#i', $path) || $path[0] === '/') { return $path; }
    $clean = preg_replace('#^(\.\./|\.\\\\)+#', '', str_replace('\\', '/', $path));
    foreach (['', 'uploads/', 'uploads/thumbnails/', 'uploads/thumbs/', 'thumbnails/', 'thumbs/', 'uploads/images/', 'images/', 'uploads/videos/', 'videos/', 'uploads/shorts/', 'shorts/', 'uploads/covers/', 'covers/'] as $d) {
        if (is_file(__DIR__ . '/../' . $d . $clean)) { return '../' . $d . $clean; }
    }
    return '';
};
$vsh_yt = function (string $v): string {
    $v = trim($v);
    if (preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) { return $v; }
    if (preg_match('~(?:youtu\.be/|youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|live/))([A-Za-z0-9_-]{11})~i', $v, $m)) { return $m[1]; }
    return '';
};

$vsh = ['video' => [], 'short' => []];
$vsh_counts = ['video' => 0, 'short' => 0];
$vsh_comments = [];
try {
    $order = $vsh_date ? vs_q($vsh_date) . ' DESC, id DESC' : 'id DESC';
    $all = $pdo->query("SELECT * FROM videos ORDER BY $order")->fetchAll(PDO::FETCH_ASSOC);
    $vsh_comments = vs_comment_counts($pdo);
    foreach ($all as $r) {
        $k = vs_is_short($r['type'] ?? 'video') ? 'short' : 'video';
        $vsh_counts[$k]++;
        if (count($vsh[$k]) < $vsh_limit) { $vsh[$k][] = $r; }
    }
} catch (PDOException $ex) {
    echo '<div class="alert alert-danger">' . $vsh_e($ex->getMessage()) . '</div>';
}

$vsh_rows = [
    ['kind' => 'video', 'title' => 'Latest Videos', 'icon' => 'bi-play-btn-fill',          'link' => 'videos.php', 'cls' => 'v'],
    ['kind' => 'short', 'title' => 'Latest Shorts', 'icon' => 'bi-lightning-charge-fill',  'link' => 'shorts.php', 'cls' => 's'],
];
?>
<style>
  .vsh{--e:#1e4fd6;--e2:#17338f;--m:#8a97c4;color:#e8edff;margin-top:1.25rem}
  .vsh-box{border:1px solid var(--e);border-radius:12px;background:linear-gradient(180deg,#0a1438,#08102c);box-shadow:0 0 18px #1e4fd62e inset,0 0 14px #1e4fd622;padding:16px;margin-bottom:16px}
  .vsh-top{display:flex;align-items:center;gap:12px;margin-bottom:14px}
  .vsh-top .ic{width:38px;height:38px;border:1px solid var(--e);border-radius:9px;display:grid;place-items:center;background:#0b1a55;font-size:18px}
  .vsh-top h2{font-size:18px;margin:0;color:#fff;flex:1}
  .vsh-top small{color:var(--m);font-weight:400;font-size:12px;margin-left:6px}
  .vsh-all{padding:7px 14px;border-radius:8px;background:linear-gradient(90deg,#5b34ef,#7c4dff);color:#fff;font-size:12px;font-weight:600;text-decoration:none;box-shadow:0 0 14px #6d3df57a}
  .vsh-all:hover{color:#fff}
  .vsh-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}
  .vsh-grid.s{grid-template-columns:repeat(auto-fill,minmax(140px,1fr))}
  .vsh-card{display:block;color:inherit;text-decoration:none;border:1px solid var(--e2);border-radius:10px;overflow:hidden;background:#081240;transition:border-color .15s,transform .15s}
  .vsh-card:hover{border-color:var(--e);transform:translateY(-2px);color:inherit}
  .vsh-th{position:relative;display:block;width:100%;background:#12215e;overflow:hidden}
  .vsh-th.v{aspect-ratio:16/9}.vsh-th.s{aspect-ratio:9/16}
  .vsh-th img,.vsh-th video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
  .vsh-th s{position:absolute;right:6px;bottom:6px;background:#000b;border-radius:4px;font:600 10px sans-serif;padding:1px 5px;text-decoration:none;color:#fff}
  .vsh-info{padding:9px 10px}
  .vsh-info b{display:block;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .vsh-info span{display:flex;gap:10px;color:var(--m);font-size:11px;margin-top:3px;flex-wrap:wrap}
  .vsh-empty{color:var(--m);text-align:center;padding:18px 0}
</style>

<section class="vsh" aria-label="Latest videos and shorts">
  <?php foreach ($vsh_rows as $sec): $items = $vsh[$sec['kind']]; ?>
  <div class="vsh-box">
    <div class="vsh-top">
      <span class="ic"><i class="bi <?php echo $sec['icon']; ?>" aria-hidden="true"></i></span>
      <h2><?php echo $sec['title']; ?><small><?php echo number_format($vsh_counts[$sec['kind']]); ?> total</small></h2>
      <a class="vsh-all" href="<?php echo $sec['link']; ?>">View all</a>
    </div>
    <?php if ($items): ?>
    <div class="vsh-grid <?php echo $sec['cls']; ?>">
      <?php foreach ($items as $r):
          $id  = (string)$r['id'];
          $hue = crc32($id) % 360;
          $src = $vsh_srcC ? trim((string)($r[$vsh_srcC] ?? '')) : '';
          $thumb = $vsh_thumbC ? $vsh_media((string)($r[$vsh_thumbC] ?? '')) : '';
          $yt = $vsh_yt($id) ?: $vsh_yt($src);
          if ($thumb === '' && $yt !== '') { $thumb = 'https://i.ytimg.com/vi/' . rawurlencode($yt) . '/mqdefault.jpg'; }
          $vid = preg_match('/\.(mp4|webm|mov|m4v|ogv)(\?|$)/i', $src) ? $vsh_media($src) : '';
          $dur = $vsh_durC ? trim((string)($r[$vsh_durC] ?? '')) : '';
          $who = trim((string)($r['username'] ?? '')) ?: 'Admin';
      ?>
      <a class="vsh-card" href="editvideos.php?editid=<?php echo urlencode($id); ?>" title="<?php echo $vsh_e($r['title'] ?? ''); ?>">
        <span class="vsh-th <?php echo $sec['cls']; ?>" style="background:linear-gradient(160deg,hsl(<?php echo $hue; ?>,60%,40%),hsl(<?php echo ($hue + 60) % 360; ?>,55%,22%))">
          <?php if ($vid !== ''): ?><video src="<?php echo $vsh_e($vid); ?>#t=0.5" preload="metadata" muted playsinline tabindex="-1"></video><?php endif; ?>
          <?php if ($thumb !== ''): ?><img src="<?php echo $vsh_e($thumb); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?>
          <?php if ($dur !== ''): ?><s><?php echo $vsh_e($dur); ?></s><?php endif; ?>
        </span>
        <span class="vsh-info">
          <b><?php echo $vsh_e($r['title'] ?? ''); ?></b>
          <span>
            <i class="bi bi-eye"></i> <?php echo $vsh_e($vsh_short($r['views'] ?? 0)); ?>
            <i class="bi bi-heart"></i> <?php echo $vsh_e($vsh_short(max(0, (int)($r['likes'] ?? 0)))); ?>
            <i class="bi bi-chat-left-text"></i> <?php echo $vsh_e($vsh_short($vsh_comments[$id] ?? 0)); ?>
          </span>
          <span><?php echo $vsh_e($who); ?></span>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <div class="vsh-empty">No <?php echo $sec['kind']; ?>s yet. <a href="addvideos.php">Upload one</a>.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</section>
<?php
} // function
} // function_exists
